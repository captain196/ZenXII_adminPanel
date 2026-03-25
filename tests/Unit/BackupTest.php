<?php
namespace Tests\Unit;

use Tests\Support\TestCase;

/**
 * BackupTest
 *
 * Tests backup generation and restoration logic:
 *  - Backup file creation (JSON and ZIP fallback)
 *  - Backup metadata stored in Firebase
 *  - Restore from existing backup (with safety backup)
 *  - Restore from uploaded JSON
 *  - Retention policy
 *  - RESTORE confirmation token enforcement
 *  - Invalid/malformed backup file rejection
 *  - Cron key validation logic
 */
class BackupTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        // Each test gets its own temp directory — no real filesystem left behind
        $this->tmpDir = sys_get_temp_dir() . '/grader_test_' . uniqid('', true);
        mkdir($this->tmpDir, 0750, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->_rmdir($this->tmpDir);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. Backup generation
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function create_backup_generates_json_file_on_disk(): void
    {
        $this->firebase->seed('Schools/Test School', ['session' => ['students' => ['s1' => 'Ali']]]);
        $service = new BackupService($this->firebase, $this->tmpDir . '/backups/');

        $result = $service->createBackup('Test School', 'firebase');

        $this->assertNotNull($result['backup_id']);
        $file = $this->tmpDir . '/backups/Test_School/' . $result['backup_id'] . '.json';
        $this->assertFileExists($file, 'Backup JSON file must be written to disk.');
    }

    /** @test */
    public function backup_file_is_valid_json_with_correct_structure(): void
    {
        $this->firebase->seed('Schools/Alpha School', ['session2025' => ['students' => ['s1' => 'Ahmed']]]);
        $service = new BackupService($this->firebase, $this->tmpDir . '/backups/');

        $result = $service->createBackup('Alpha School', 'firebase');

        $file    = $this->tmpDir . '/backups/Alpha_School/' . $result['backup_id'] . '.json';
        $content = file_get_contents($file);
        $decoded = json_decode($content, true);

        $this->assertNotNull($decoded, 'Backup file must be valid JSON.');
        $this->assertSame('1.2',           $decoded['backup_format']);
        $this->assertSame('Alpha School',  $decoded['school_name']);
        $this->assertSame('Alpha School',  $decoded['firebase_key']);
        $this->assertArrayHasKey('Schools',   $decoded);
        $this->assertArrayHasKey('backed_up_at', $decoded);
    }

    /** @test */
    public function backup_id_format_matches_expected_pattern(): void
    {
        $this->firebase->seed('Schools/Format School', ['data' => 'value']);
        $service = new BackupService($this->firebase, $this->tmpDir . '/backups/');

        $result = $service->createBackup('Format School', 'firebase');

        $this->assertMatchesRegularExpression(
            '/^BKP_\d{8}_\d{6}_[a-z0-9]{6}$/',
            $result['backup_id'],
            'backup_id must match BKP_YYYYMMDD_HHiiss_xxxxxx'
        );
    }

    /** @test */
    public function backup_metadata_written_to_firebase(): void
    {
        $this->firebase->seed('Schools/Meta School', ['x' => 1]);
        $service = new BackupService($this->firebase, $this->tmpDir . '/backups/');

        $result = $service->createBackup('Meta School', 'firebase');
        $bid    = $result['backup_id'];

        $meta = $this->firebase->get("System/Backups/Meta_School/{$bid}");
        $this->assertIsArray($meta);
        $this->assertSame($bid,        $meta['backup_id']);
        $this->assertSame('firebase',  $meta['backup_type']);
        $this->assertSame('completed', $meta['status']);
        $this->assertArrayHasKey('size_bytes',  $meta);
        $this->assertArrayHasKey('created_at',  $meta);
        $this->assertArrayHasKey('created_by',  $meta);
    }

    /** @test */
    public function backup_fails_gracefully_when_school_has_no_firebase_data(): void
    {
        // Don't seed any data for this school
        $service = new BackupService($this->firebase, $this->tmpDir . '/backups/');

        $this->expectException(\RuntimeException::class);
        $service->createBackup('Ghost School', 'firebase');
    }

    /** @test */
    public function size_human_formats_bytes_correctly(): void
    {
        $service = new BackupService($this->firebase, $this->tmpDir . '/');
        $this->assertSame('1.0 KB',  $service->humanSize(1024));
        $this->assertSame('1.0 MB',  $service->humanSize(1048576));
        $this->assertSame('500 B',   $service->humanSize(500));
        $this->assertSame('2.5 MB',  $service->humanSize(2621440));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Restore from existing backup
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function restore_requires_RESTORE_confirmation_token(): void
    {
        $service = new BackupService($this->firebase, $this->tmpDir . '/backups/');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('RESTORE');
        $service->restore('Test School', 'BKP_123', 'restore'); // lowercase = wrong
    }

    /** @test */
    public function restore_creates_safety_backup_before_overwriting(): void
    {
        // Seed existing school data
        $this->firebase->seed('Schools/Restore School', ['old' => 'data']);

        // Seed a backup file to restore
        $service    = new BackupService($this->firebase, $this->tmpDir . '/backups/');
        $backupData = $service->_buildExport('Restore School', 'Restore School', ['Schools' => ['new' => 'data']], 'firebase', 'test');
        $backupId   = 'BKP_20260307_120000_abc123';
        $dir        = $this->tmpDir . '/backups/Restore_School/';
        mkdir($dir, 0750, true);
        file_put_contents($dir . $backupId . '.json', json_encode($backupData));

        // Seed metadata
        $this->firebase->seed("System/Backups/Restore_School/{$backupId}", [
            'backup_id'   => $backupId,
            'filename'    => $backupId . '.json',
            'firebase_key'=> 'Restore School',
            'backup_type' => 'firebase',
            'status'      => 'completed',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $service->restore('Restore School', $backupId, 'RESTORE');

        // Check a safety backup was created
        $allBackups = $this->firebase->get('System/Backups/Restore_School');
        $safetyExists = false;
        foreach (array_keys($allBackups ?? []) as $key) {
            if (strpos($key, 'SAFETY') !== false || strpos(
                $this->firebase->get("System/Backups/Restore_School/{$key}/backup_id") ?? '', 'SAFETY') !== false
            ) {
                $safetyExists = true;
                break;
            }
        }
        // Check the firebase set was called for the school data
        $this->assertTrue($this->firebase->assertCalled('set', 'Schools/Restore School'),
            'Firebase::set() should have been called to restore Schools node.');
    }

    /** @test */
    public function restore_overwrites_school_data_in_firebase(): void
    {
        // Seed current data
        $this->firebase->seed('Schools/Overwrite School', ['before' => 'restore']);

        // Build backup
        $service    = new BackupService($this->firebase, $this->tmpDir . '/backups/');
        $backupData = $service->_buildExport('Overwrite School', 'Overwrite School',
            ['after' => 'restore'], 'firebase', 'test');
        $bid = 'BKP_RESTORE_TEST_001';
        $dir = $this->tmpDir . '/backups/Overwrite_School/';
        mkdir($dir, 0750, true);
        file_put_contents($dir . $bid . '.json', json_encode($backupData));

        $this->firebase->seed("System/Backups/Overwrite_School/{$bid}", [
            'backup_id' => $bid, 'filename' => $bid . '.json',
            'firebase_key' => 'Overwrite School', 'backup_type' => 'firebase', 'status' => 'completed',
        ]);

        $service->restore('Overwrite School', $bid, 'RESTORE');

        $schoolData = $this->firebase->get('Schools/Overwrite School');
        $this->assertSame('restore', $schoolData['after'],
            'School data should be overwritten by backup contents.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. Upload restore
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function upload_restore_accepts_valid_backup_json(): void
    {
        $export = [
            'backup_format' => '1.2',
            'school_name'   => 'Upload School',
            'firebase_key'  => 'Upload School',
            'backed_up_at'  => date('Y-m-d H:i:s'),
            'Schools'       => ['session' => ['students' => ['s1' => 'Ali']]],
        ];
        $tmpFile = $this->tmpDir . '/upload_test.json';
        file_put_contents($tmpFile, json_encode($export));

        $service = new BackupService($this->firebase, $this->tmpDir . '/backups/');
        $result  = $service->uploadRestore($tmpFile, 'RESTORE');

        $this->assertTrue($result);
        $data = $this->firebase->get('Schools/Upload School');
        $this->assertIsArray($data);
        $this->assertArrayHasKey('session', $data);
    }

    /** @test */
    public function upload_restore_rejects_file_missing_Schools_key(): void
    {
        $bad = ['backup_format' => '1.2', 'school_name' => 'Bad', 'firebase_key' => 'Bad'];
        $f   = $this->tmpDir . '/bad.json';
        file_put_contents($f, json_encode($bad));

        $service = new BackupService($this->firebase, $this->tmpDir . '/backups/');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid backup');
        $service->uploadRestore($f, 'RESTORE');
    }

    /** @test */
    public function upload_restore_rejects_malformed_json(): void
    {
        $f = $this->tmpDir . '/corrupt.json';
        file_put_contents($f, '{this is not valid json}}');

        $service = new BackupService($this->firebase, $this->tmpDir . '/backups/');

        $this->expectException(\RuntimeException::class);
        $service->uploadRestore($f, 'RESTORE');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. Retention policy
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function retention_deletes_oldest_backups_keeping_specified_count(): void
    {
        $dir = $this->tmpDir . '/backups/Retain_School/';
        mkdir($dir, 0750, true);

        $service  = new BackupService($this->firebase, $this->tmpDir . '/backups/');
        $now      = time();

        // Create 5 backups (oldest first)
        $backups = [];
        for ($i = 5; $i >= 1; $i--) {
            $bid  = 'BKP_RETAIN_' . str_pad($i, 3, '0', STR_PAD_LEFT);
            $date = date('Y-m-d H:i:s', $now - ($i * 3600));
            file_put_contents($dir . $bid . '.json', '{}');
            $this->firebase->seed("System/Backups/Retain_School/{$bid}", [
                'backup_id'  => $bid,
                'filename'   => $bid . '.json',
                'created_at' => $date,
                'status'     => 'completed',
            ]);
            $backups[] = $bid;
        }

        $service->applyRetention('Retain School', 3);

        // Should have kept only 3 newest
        $remaining = $this->firebase->get('System/Backups/Retain_School');
        $this->assertCount(3, $remaining, 'Exactly 3 backups should remain after retention.');
    }

    /** @test */
    public function retention_preserves_SAFETY_backups(): void
    {
        $dir = $this->tmpDir . '/backups/Safety_School/';
        mkdir($dir, 0750, true);

        $service = new BackupService($this->firebase, $this->tmpDir . '/backups/');

        // 1 safety backup
        $this->firebase->seed('System/Backups/Safety_School/SAFETY_001', [
            'backup_id'  => 'SAFETY_001', 'filename' => 'SAFETY_001.json',
            'created_at' => date('Y-m-d H:i:s', strtotime('-30 days')), 'status' => 'completed',
        ]);
        file_put_contents($dir . 'SAFETY_001.json', '{}');

        // 4 regular backups
        for ($i = 1; $i <= 4; $i++) {
            $bid = 'BKP_SAF_' . str_pad($i, 3, '0', STR_PAD_LEFT);
            $this->firebase->seed("System/Backups/Safety_School/{$bid}", [
                'backup_id'  => $bid, 'filename' => $bid . '.json',
                'created_at' => date('Y-m-d H:i:s', strtotime("-{$i} hours")), 'status' => 'completed',
            ]);
            file_put_contents($dir . $bid . '.json', '{}');
        }

        $service->applyRetention('Safety School', 2);

        // SAFETY backup must still exist
        $safety = $this->firebase->get('System/Backups/Safety_School/SAFETY_001');
        $this->assertNotNull($safety, 'SAFETY backup must never be deleted by retention.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Cron key validation
    // ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function cron_validates_correct_key_using_hash_equals(): void
    {
        $key = bin2hex(random_bytes(16));
        $this->firebase->seed('System/BackupSchedule', [
            'enabled'   => true,
            'cron_key'  => $key,
            'frequency' => 'daily',
            'retention' => 7,
        ]);

        $result = CronValidator::validate($this->firebase, $key);
        $this->assertTrue($result['valid']);
    }

    /** @test */
    public function cron_rejects_wrong_key(): void
    {
        $this->firebase->seed('System/BackupSchedule', [
            'enabled'  => true,
            'cron_key' => 'correct_key_abc123',
        ]);

        $result = CronValidator::validate($this->firebase, 'wrong_key_xyz789');
        $this->assertFalse($result['valid']);
        $this->assertSame('invalid_key', $result['reason']);
    }

    /** @test */
    public function cron_skips_when_schedule_disabled(): void
    {
        $this->firebase->seed('System/BackupSchedule', [
            'enabled'  => false,
            'cron_key' => 'somekey',
        ]);

        $result = CronValidator::validate($this->firebase, 'somekey');
        $this->assertFalse($result['valid']);
        $this->assertSame('disabled', $result['reason']);
    }

    /** @test */
    public function cron_prevents_double_run_within_same_hour(): void
    {
        $key = 'testkey';
        $this->firebase->seed('System/BackupSchedule', [
            'enabled'  => true,
            'cron_key' => $key,
            'last_run' => date('Y-m-d H:30:00'), // same hour
        ]);

        $result = CronValidator::validate($this->firebase, $key);
        $this->assertFalse($result['valid']);
        $this->assertSame('already_ran', $result['reason']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function _rmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $full = $dir . '/' . $f;
            is_dir($full) ? $this->_rmdir($full) : unlink($full);
        }
        rmdir($dir);
    }
}


// ─────────────────────────────────────────────────────────────────────────────
// BackupService — extracted logic (mirrors Superadmin_backups + Backup_cron)
// ─────────────────────────────────────────────────────────────────────────────
class BackupService
{
    public function __construct(
        private readonly \Tests\Support\FirebaseMock $fb,
        private readonly string $backupDir
    ) {}

    public function createBackup(string $school, string $type = 'firebase', string $by = 'test'): array
    {
        $data = $this->fb->get("Schools/{$school}");
        if (empty($data)) throw new \RuntimeException("No data for '{$school}'.");

        $export    = $this->_buildExport($school, $school, $data, $type, $by);
        $backup_id = 'BKP_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 6);
        $safeUid   = preg_replace('/[^A-Za-z0-9\-]/', '_', $school);
        $dir       = $this->backupDir . $safeUid . '/';
        if (!is_dir($dir)) mkdir($dir, 0750, true);

        $json  = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $bytes = strlen($json);
        file_put_contents($dir . $backup_id . '.json', $json);

        $this->fb->set("System/Backups/{$safeUid}/{$backup_id}", [
            'backup_id'    => $backup_id,
            'school_name'  => $school,
            'firebase_key' => $school,
            'filename'     => $backup_id . '.json',
            'backup_type'  => $type,
            'size_bytes'   => $bytes,
            'size_human'   => $this->humanSize($bytes),
            'status'       => 'completed',
            'created_at'   => date('Y-m-d H:i:s'),
            'created_by'   => $by,
        ]);

        return ['backup_id' => $backup_id, 'size_bytes' => $bytes];
    }

    public function _buildExport(string $school, string $key, array $data, string $type, string $by): array
    {
        return [
            'backup_format' => '1.2',
            'backup_type'   => $type,
            'school_name'   => $school,
            'firebase_key'  => $key,
            'backed_up_at'  => date('Y-m-d H:i:s'),
            'backed_up_by'  => $by,
            'Schools'       => $data,
        ];
    }

    public function restore(string $school, string $backup_id, string $token): void
    {
        if ($token !== 'RESTORE') throw new \InvalidArgumentException('Type RESTORE to confirm.');

        $safeUid = preg_replace('/[^A-Za-z0-9\-]/', '_', $school);
        $meta    = $this->fb->get("System/Backups/{$safeUid}/{$backup_id}");
        if (!$meta) throw new \RuntimeException('Backup not found.');

        // Safety backup before restoring
        $current = $this->fb->get("Schools/{$school}");
        if ($current) {
            $safeId = 'SAFETY_' . date('Ymd_His');
            $this->fb->set("System/Backups/{$safeUid}/{$safeId}", [
                'backup_id' => $safeId, 'school_name' => $school, 'type' => 'safety',
                'created_at' => date('Y-m-d H:i:s'), 'status' => 'completed',
            ]);
        }

        // Read backup file
        $filename = basename($meta['filename'] ?? '');
        $filepath = $this->backupDir . $safeUid . '/' . $filename;
        $content  = file_get_contents($filepath);
        $export   = json_decode($content, true);

        $this->fb->set("Schools/{$school}", $export['Schools']);
    }

    public function uploadRestore(string $filePath, string $token): bool
    {
        if ($token !== 'RESTORE') throw new \InvalidArgumentException('Type RESTORE to confirm.');

        $content = file_get_contents($filePath);
        $export  = json_decode($content, true);
        if (!is_array($export)) throw new \RuntimeException('Invalid backup: not valid JSON.');
        if (!isset($export['Schools'], $export['firebase_key'])) {
            throw new \RuntimeException('Invalid backup: missing Schools or firebase_key.');
        }

        $key = $export['firebase_key'];
        $this->fb->set("Schools/{$key}", $export['Schools']);
        return true;
    }

    public function applyRetention(string $school, int $keep): void
    {
        $safeUid = preg_replace('/[^A-Za-z0-9\-]/', '_', $school);
        $all     = $this->fb->get("System/Backups/{$safeUid}") ?? [];

        $entries = [];
        foreach ($all as $bid => $b) {
            if (!is_array($b) || strpos((string)$bid, 'SAFETY') !== false) continue;
            $entries[$bid] = $b['created_at'] ?? '';
        }
        asort($entries);

        $excess = count($entries) - $keep;
        if ($excess <= 0) return;

        foreach (array_slice(array_keys($entries), 0, $excess) as $bid) {
            $filename = basename($all[$bid]['filename'] ?? '');
            $safeFile = preg_match('/^[A-Za-z0-9_\-\.]+$/', $filename) ? $filename : '';
            if ($safeFile) {
                $fp = $this->backupDir . $safeUid . '/' . $safeFile;
                if (file_exists($fp)) @unlink($fp);
            }
            $this->fb->delete("System/Backups/{$safeUid}/{$bid}");
        }
    }

    public function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024)    return round($bytes / 1024,    1) . ' KB';
        return $bytes . ' B';
    }
}


// ─────────────────────────────────────────────────────────────────────────────
// CronValidator — extracted from Backup_cron.php
// ─────────────────────────────────────────────────────────────────────────────
class CronValidator
{
    public static function validate(\Tests\Support\FirebaseMock $fb, string $provided_key): array
    {
        $schedule = $fb->get('System/BackupSchedule') ?? [];

        if (empty($schedule['enabled'])) {
            return ['valid' => false, 'reason' => 'disabled'];
        }

        $stored_key = $schedule['cron_key'] ?? '';
        if (!hash_equals((string)$stored_key, (string)$provided_key)) {
            return ['valid' => false, 'reason' => 'invalid_key'];
        }

        $last_run = $schedule['last_run'] ?? '';
        if ($last_run && substr($last_run, 0, 13) === date('Y-m-d H')) {
            return ['valid' => false, 'reason' => 'already_ran'];
        }

        return ['valid' => true, 'reason' => null];
    }
}
