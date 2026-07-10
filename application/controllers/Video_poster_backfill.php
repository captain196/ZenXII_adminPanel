<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Video_poster_backfill — CLI. Generates a poster `thumbnail` (+ `duration`)
 * for galleryMedia VIDEO docs that have none, so every viewer (admin gallery,
 * Teacher, Parent) shows a real frame instead of a blank/play-only tile.
 *
 * Root cause it repairs: admin-panel video uploads on a server WITHOUT ffmpeg
 * store the video but no poster (duration "0:00", thumbnail ""). This runs
 * ffmpeg/ffprobe LOCALLY (present on the dev box) against the real Storage
 * files and writes the poster back.
 *
 * DRY-RUN by default. Pass `commit` to actually upload + write.
 *   php index.php video_poster_backfill run <schoolId|all> [commit]
 */
class Video_poster_backfill extends CI_Controller
{
    private $ffmpeg;
    private $ffprobe;
    private $stats = ['videos' => 0, 'missing' => 0, 'fixed' => 0, 'failed' => 0];

    public function __construct()
    {
        parent::__construct();
        if (!$this->input->is_cli_request()) { show_error('CLI-only.', 403); exit(1); }
        $this->load->library('firebase');
        $this->ffmpeg  = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'))  ?: '/opt/homebrew/bin/ffmpeg';
        $this->ffprobe = trim((string) shell_exec('command -v ffprobe 2>/dev/null')) ?: '/opt/homebrew/bin/ffprobe';
    }

    public function run($scope = '', $commit = '')
    {
        if ($scope === '') { echo "Usage: php index.php video_poster_backfill run <schoolId|all> [commit]\n"; return; }
        $doCommit = ($commit === 'commit');
        if (!is_file($this->ffmpeg) && !is_executable($this->ffmpeg)) { echo "ffmpeg not found at {$this->ffmpeg}\n"; return; }

        $filters = [['type', '==', 'video']];
        if ($scope !== 'all') $filters[] = ['schoolId', '==', $scope];
        $rows = (array) $this->firebase->firestoreQuery('galleryMedia', $filters);

        foreach ($rows as $r) {
            $m   = (is_array($r) && isset($r['data']) && is_array($r['data'])) ? $r['data'] : (is_array($r) ? $r : []);
            $id  = (string) ($r['id'] ?? '');
            $sid = (string) ($m['schoolId'] ?? '');
            $this->stats['videos']++;
            if (!empty($m['thumbnail'])) continue;               // already has a poster
            $this->stats['missing']++;

            $url = (string) ($m['url'] ?? '');
            if ($url === '' || $sid === '') { $this->stats['failed']++; echo "  SKIP {$id} (no url/sid)\n"; continue; }
            $albumId = (string) ($m['albumId'] ?? '');
            echo "VIDEO {$id} (album={$albumId}) — poster MISSING\n";
            if (!$doCommit) { echo "  would generate poster (dry-run)\n"; continue; }

            try {
                $poster = $this->_generate_and_upload($sid, $albumId, $id, $url);
                if ($poster === null) { $this->stats['failed']++; echo "  FAILED to build poster\n"; continue; }
                $this->firebase->firestoreSet('galleryMedia', $id, [
                    'thumbnail' => $poster['url'],
                    'duration'  => $poster['duration'],
                ], true);
                $this->stats['fixed']++;
                echo "  OK thumb={$poster['url']} dur={$poster['duration']}\n";
            } catch (\Throwable $e) {
                $this->stats['failed']++;
                echo "  ERROR {$e->getMessage()}\n";
                log_message('error', "Video_poster_backfill {$id}: " . $e->getMessage());
            }
        }

        echo str_repeat('=', 50) . "\n";
        echo "videos={$this->stats['videos']} missing={$this->stats['missing']} fixed={$this->stats['fixed']} failed={$this->stats['failed']}"
           . ($doCommit ? "" : "  (DRY-RUN — pass 'commit' to apply)") . "\n";
    }

    /** Download video, extract a 1s frame + duration, upload poster, return [url,duration]. */
    private function _generate_and_upload(string $sid, string $albumId, string $mediaId, string $url): ?array
    {
        $tmpVid  = tempnam(sys_get_temp_dir(), 'vpb_') . '.mp4';
        $tmpJpg  = tempnam(sys_get_temp_dir(), 'vpb_') . '.jpg';
        try {
            $bytes = @file_get_contents($url);
            if ($bytes === false || strlen($bytes) === 0) return null;
            file_put_contents($tmpVid, $bytes);

            // duration
            $durOut = shell_exec("\"{$this->ffprobe}\" -v error -select_streams v:0 -show_entries stream=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($tmpVid));
            $secs   = is_numeric(trim((string) $durOut)) ? round((float) trim((string) $durOut), 2) : 0;
            $min    = (int) floor($secs / 60); $s = (int) round($secs - ($min * 60));
            if ($s === 60) { $min++; $s = 0; }
            $duration = sprintf('%d:%02d', $min, $s);

            // frame at 1s (fallback to 0 if the clip is shorter)
            $ss = $secs >= 1 ? '00:00:01.000' : '00:00:00.000';
            shell_exec("\"{$this->ffmpeg}\" -y -ss {$ss} -i " . escapeshellarg($tmpVid) . " -vframes 1 -q:v 3 " . escapeshellarg($tmpJpg) . " 2>/dev/null");
            if (!is_file($tmpJpg) || filesize($tmpJpg) === 0) return null;

            $safeAlbum  = preg_replace('/[^A-Za-z0-9_\-]/', '_', $albumId);
            $storPath   = "schools/{$sid}/events/{$safeAlbum}/thumbnails/thumb_{$mediaId}.jpg";
            $up = $this->firebase->uploadFile($tmpJpg, $storPath);
            if ($up !== true) return null;
            $dl = $this->firebase->getDownloadUrl($storPath);
            return $dl ? ['url' => $dl, 'duration' => $duration] : null;
        } finally {
            @unlink($tmpVid); @unlink($tmpJpg);
        }
    }
}
