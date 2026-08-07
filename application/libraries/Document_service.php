<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Canonical Document Service — Phase 1 (Admission-scoped, forward-compatible).
 *
 * A lightweight, module-agnostic file service:
 *   • one physical Storage object per logical document (ID-addressed)
 *   • modules REFERENCE documents by id via links (never move/copy bytes)
 *   • reference-counted, retention-governed deletion (orphan-safe)
 *   • per-type validation + size caps + image optimization
 *   • audited on every state change
 *
 * Firestore is the sole metadata store; Firebase Storage the sole byte store.
 * The public API (upload/replace/delete/link/unlink/verify/getMetadata) is
 * generic so Student/HR/Hostel/… can adopt it later WITHOUT redesign — but
 * this phase wires it to Admission only.
 *
 * Tenant isolation: every doc/link id is `{schoolId}_…`; every read verifies
 * the stored schoolId matches the service's init scope.
 *
 * Collections:
 *   documents/{schoolId}_{documentId}
 *   documentLinks/{schoolId}_{documentId}_{module}_{entityId}
 * Storage:
 *   documents/{schoolId}/{documentId}_v{n}.{ext}
 */
class Document_service
{
    /** @var CI_Controller */
    private $CI;
    /** @var object Firebase library (Storage + Firestore REST) */
    private $fb;
    private $schoolId = '';
    private $cfg = [];

    private $C_DOCS  = 'documents';
    private $C_LINKS = 'documentLinks';

    /**
     * Optimistic-concurrency retry budget for the CAS loops (replace / link /
     * unlink). Each retry re-reads fresh state and re-attempts a precondition-
     * gated atomic commit; correctness comes from the server-side precondition,
     * the retries only absorb contention. Matches the Phase 8 accounting CAS.
     */
    private const CAS_MAX_RETRIES = 6;

    public function __construct()
    {
        $this->CI = &get_instance();
        $this->CI->config->load('document_service', true);
        $this->cfg = (array) $this->CI->config->item('document_service', 'document_service');
        if (!empty($this->cfg['collection_documents'])) $this->C_DOCS  = $this->cfg['collection_documents'];
        if (!empty($this->cfg['collection_links']))     $this->C_LINKS = $this->cfg['collection_links'];
    }

    /** Bind the Firebase client + tenant scope. Call once per request. */
    public function init($firebase, string $schoolId): self
    {
        $this->fb = $firebase;
        $this->schoolId = trim($schoolId);
        return $this;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  PUBLIC API
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Upload a NEW logical document. Validates, optimizes (images), stores one
     * canonical object, writes metadata (linkCount=0 — caller then link()s).
     *
     * @return array ['ok'=>bool,'error'=>?string,'document'=>?array]
     *   document = {documentId,type,label,category,mime,sizeBytes,storagePath,downloadUrl,verificationStatus,version}
     */
    public function upload(string $tmpPath, string $origName, string $type, array $meta = []): array
    {
        if ($this->schoolId === '') return $this->err('service not initialised');
        $policy = $this->policy($type);
        if (!$policy) return $this->err("unknown document type '{$type}'");

        $v = $this->validate($tmpPath, $origName, $policy);
        if (!$v['ok']) return $this->err($v['error']);

        $ext  = $v['ext'];
        $mime = $v['mime'];
        $useTmp = $tmpPath;
        $cleanup = null;

        // Image optimization / compression (storage efficiency).
        if (!empty($policy['image'])) {
            $opt = $this->optimizeImage($tmpPath, $mime);
            if ($opt) { $useTmp = $opt['path']; $mime = $opt['mime']; $ext = $opt['ext']; $cleanup = $opt['path']; }
        }

        $documentId  = $this->newDocId();
        $version     = 1;
        $storagePath = $this->storagePath($documentId, $version, $ext);

        if (!$this->fb->uploadFile($useTmp, $storagePath)) {
            if ($cleanup) @unlink($cleanup);
            return $this->err('storage upload failed');
        }
        $downloadUrl = $this->fb->getDownloadUrl($storagePath);
        $sizeBytes   = @filesize($useTmp) ?: 0;
        if ($cleanup) @unlink($cleanup);

        $now = date('c');
        $docData = [
            'documentId'         => $documentId,
            'schoolId'           => $this->schoolId,
            'type'               => $type,
            'label'              => (string) ($meta['label'] ?? $policy['label']),
            'category'           => $policy['category'],
            'mime'               => $mime,
            'ext'                => $ext,
            'sizeBytes'          => $sizeBytes,
            'storagePath'        => $storagePath,
            'downloadUrl'        => $downloadUrl,
            'version'            => $version,
            'verificationStatus' => 'pending',
            'verifiedBy'         => null,
            'verifiedAt'         => null,
            'uploadedBy'         => (string) ($meta['uploadedBy'] ?? 'system'),
            'uploadedAt'         => $now,
            'replacedAt'         => null,
            'linkCount'          => 0,
            'retention'          => ['policy' => 'default', 'eligibleAt' => null, 'legalHold' => false],
            'updatedAt'          => $now,
        ];
        if (!$this->fb->firestoreSet($this->C_DOCS, $this->docKey($documentId), $docData, false)) {
            // metadata write failed → don't leave an orphan object
            $this->fb->deleteStorageFile($storagePath);
            return $this->err('metadata write failed');
        }

        $this->audit('upload', $documentId, ['type' => $type, 'sizeBytes' => $sizeBytes], (string) ($meta['uploadedBy'] ?? 'system'));
        return ['ok' => true, 'error' => null, 'document' => $this->pointer($docData)];
    }

    /**
     * Replace the bytes of an existing document (new version). Uploads the new
     * object first, repoints metadata, then deletes the OLD object (safe order)
     * — one physical object per logical document, no orphan. Resets verification.
     */
    public function replace(string $documentId, string $tmpPath, string $origName, string $by = 'system'): array
    {
        $doc0 = $this->read($documentId);
        if (!$doc0) return $this->err('document not found');
        $policy = $this->policy((string) ($doc0['type'] ?? ''));
        if (!$policy) return $this->err('unknown type on stored document');

        // Validate + optimize ONCE (local, no Storage yet). The optimized temp is
        // re-uploaded (to a fresh unique path) on each CAS attempt.
        $v = $this->validate($tmpPath, $origName, $policy);
        if (!$v['ok']) return $this->err($v['error']);
        $ext = $v['ext']; $mime = $v['mime']; $useTmp = $tmpPath; $cleanup = null;
        if (!empty($policy['image'])) {
            $opt = $this->optimizeImage($tmpPath, $mime);
            if ($opt) { $useTmp = $opt['path']; $mime = $opt['mime']; $ext = $opt['ext']; $cleanup = $opt['path']; }
        }
        $newSize = @filesize($useTmp) ?: 0;

        // F3 — optimistic-concurrency replace. Each attempt: read current version
        // + updateTime, upload the new bytes to a UNIQUE path, then commit the
        // metadata repoint ONLY IF the document has not changed since the read
        // (currentDocument.updateTime CAS). Guarantees:
        //   • no lost update — a concurrent replace bumps updateTime → our commit
        //     fails → we retry on top of the winner (serialized, never discarded);
        //   • no orphan — on a CAS miss we delete OUR just-uploaded object; on a
        //     win we delete the previous object; unique paths prevent collisions;
        //   • metadata consistency — version is strictly increasing under CAS and
        //     downloadUrl always matches the committed storagePath.
        $result = $this->err('replace is busy (write contention) — please retry');
        for ($attempt = 0; $attempt < self::CAS_MAX_RETRIES; $attempt++) {
            $doc = $this->read($documentId);
            if (!$doc) { $result = $this->err('document not found'); break; }
            $docUt   = (string) ($doc['__updateTime'] ?? '');
            $oldPath = (string) ($doc['storagePath'] ?? '');
            $newVer  = (int) ($doc['version'] ?? 1) + 1;
            $newPath = $this->replacePath($documentId, $newVer, $ext);

            if (!$this->fb->uploadFile($useTmp, $newPath)) { $result = $this->err('storage upload failed'); break; }
            $newUrl = $this->fb->getDownloadUrl($newPath);
            $now    = date('c');

            $ok = $this->fb->firestoreCommitBatch([[
                'op' => 'update', 'collection' => $this->C_DOCS, 'docId' => $this->docKey($documentId),
                'merge' => true,
                'precondition' => $docUt !== '' ? ['updateTime' => $docUt] : ['exists' => true],
                'data' => [
                    'storagePath' => $newPath, 'downloadUrl' => $newUrl, 'mime' => $mime, 'ext' => $ext,
                    'sizeBytes' => $newSize, 'version' => $newVer,
                    'verificationStatus' => 'pending', 'verifiedBy' => null, 'verifiedAt' => null,
                    'replacedAt' => $now, 'updatedAt' => $now,
                ],
            ]]);

            if ($ok) {
                if ($oldPath !== '' && $oldPath !== $newPath) $this->fb->deleteStorageFile($oldPath); // retire previous
                $this->audit('replace', $documentId, ['oldPath' => $oldPath, 'newPath' => $newPath, 'version' => $newVer], $by);
                if ($cleanup) @unlink($cleanup);
                $merged = array_merge($doc, [
                    'storagePath' => $newPath, 'downloadUrl' => $newUrl, 'mime' => $mime, 'ext' => $ext,
                    'sizeBytes' => $newSize, 'version' => $newVer, 'verificationStatus' => 'pending',
                ]);
                return ['ok' => true, 'error' => null, 'document' => $this->pointer($merged)];
            }
            // CAS miss (or transient) → delete OUR object so no orphan is left, then retry.
            $this->fb->deleteStorageFile($newPath);
            usleep(20000 * ($attempt + 1));
        }
        if ($cleanup) @unlink($cleanup);
        return $result;
    }

    /**
     * Attach a document to a module entity (reference). Idempotent per
     * (doc,module,entity) and concurrency-safe (F4).
     *
     * The link-state write and the linkCount change commit in ONE atomic batch,
     * gated by two preconditions: the link write is create-if-absent (or a
     * CAS reactivate), and the linkCount write is CAS'd on the document's
     * updateTime. So exactly one of any set of concurrent linkers increments the
     * count; the rest fail the precondition, retry, observe the active link, and
     * become no-ops. No double count is possible.
     */
    public function link(string $documentId, string $module, string $entityId, string $role = '', string $by = 'system'): array
    {
        $linkKey = $this->linkKey($documentId, $module, $entityId);
        for ($attempt = 0; $attempt < self::CAS_MAX_RETRIES; $attempt++) {
            $doc = $this->read($documentId);
            if (!$doc) return $this->err('document not found');
            $docUt = (string) ($doc['__updateTime'] ?? '');
            if ($docUt === '') return $this->err('document updateTime unavailable (CAS)');

            $existing   = $this->fb->firestoreGet($this->C_LINKS, $linkKey);
            $linkExists = is_array($existing);
            if ($linkExists && !empty($existing['active'])) {
                return ['ok' => true, 'error' => null, 'linkCount' => (int) ($doc['linkCount'] ?? 0)]; // already linked (idempotent)
            }

            $now = date('c');
            $docOp = [
                'op' => 'update', 'collection' => $this->C_DOCS, 'docId' => $this->docKey($documentId),
                'merge' => true, 'precondition' => ['updateTime' => $docUt],
                'data' => [
                    'linkCount' => (int) ($doc['linkCount'] ?? 0) + 1,
                    'retention' => $this->retentionMap($doc, null),  // a fresh link clears any countdown
                    'updatedAt' => $now,
                ],
            ];
            if (!$linkExists) {
                $linkOp = [
                    'op' => 'update', 'collection' => $this->C_LINKS, 'docId' => $linkKey,
                    'precondition' => ['exists' => false],   // create-if-absent
                    'data' => [
                        'documentId' => $documentId, 'schoolId' => $this->schoolId,
                        'module' => $module, 'entityId' => $entityId, 'role' => $role,
                        'active' => true, 'linkedBy' => $by, 'linkedAt' => $now,
                    ],
                ];
            } else {
                $linkUt = (string) ($existing['__updateTime'] ?? '');
                $linkOp = [
                    'op' => 'update', 'collection' => $this->C_LINKS, 'docId' => $linkKey, 'merge' => true,
                    'precondition' => $linkUt !== '' ? ['updateTime' => $linkUt] : ['exists' => true],
                    'data' => ['active' => true, 'relinkedAt' => $now],
                ];
            }

            if ($this->fb->firestoreCommitBatch([$linkOp, $docOp])) {
                $this->audit('link', $documentId, ['module' => $module, 'entityId' => $entityId, 'role' => $role, 're' => $linkExists], $by);
                return ['ok' => true, 'error' => null, 'linkCount' => (int) ($doc['linkCount'] ?? 0) + 1];
            }
            usleep(20000 * ($attempt + 1)); // contention → re-read fresh state and retry
        }
        return $this->err('link is busy (write contention) — please retry');
    }

    /**
     * Detach a document from a module entity. Concurrency-safe (F4): the link
     * deactivation and the linkCount decrement commit in ONE atomic batch, gated
     * by a CAS on BOTH the link's updateTime and the document's updateTime. Only
     * the writer that actually flips active:true→false decrements — a second
     * concurrent unlink of the same link fails the precondition, retries, sees
     * the link already inactive, and returns a no-op. No double decrement.
     * When the last active link goes, the retention clock starts (no purge).
     */
    public function unlink(string $documentId, string $module, string $entityId, string $by = 'system'): array
    {
        $linkKey = $this->linkKey($documentId, $module, $entityId);
        for ($attempt = 0; $attempt < self::CAS_MAX_RETRIES; $attempt++) {
            $existing = $this->fb->firestoreGet($this->C_LINKS, $linkKey);
            if (!is_array($existing) || empty($existing['active'])) {
                return ['ok' => true, 'error' => null]; // already detached / never linked (idempotent)
            }
            $linkUt = (string) ($existing['__updateTime'] ?? '');

            $doc = $this->read($documentId);
            if (!$doc) {
                // Document already gone — just deactivate the dangling link.
                $this->fb->firestoreUpdate($this->C_LINKS, $linkKey, ['active' => false, 'unlinkedAt' => date('c'), 'unlinkedBy' => $by]);
                return ['ok' => true, 'error' => null, 'linkCount' => 0];
            }
            $docUt    = (string) ($doc['__updateTime'] ?? '');
            $newCount = max(0, (int) ($doc['linkCount'] ?? 0) - 1);
            $eligible = $newCount <= 0
                ? date('c', time() + ((int) ($this->cfg['retention_days'] ?? 180)) * 86400) // last link → start retention
                : ($doc['retention']['eligibleAt'] ?? null);

            $now = date('c');
            $linkOp = [
                'op' => 'update', 'collection' => $this->C_LINKS, 'docId' => $linkKey, 'merge' => true,
                'precondition' => $linkUt !== '' ? ['updateTime' => $linkUt] : ['exists' => true],
                'data' => ['active' => false, 'unlinkedAt' => $now, 'unlinkedBy' => $by],
            ];
            $docOp = [
                'op' => 'update', 'collection' => $this->C_DOCS, 'docId' => $this->docKey($documentId),
                'merge' => true, 'precondition' => $docUt !== '' ? ['updateTime' => $docUt] : ['exists' => true],
                'data' => ['linkCount' => $newCount, 'retention' => $this->retentionMap($doc, $eligible), 'updatedAt' => $now],
            ];

            if ($this->fb->firestoreCommitBatch([$linkOp, $docOp])) {
                $this->audit('unlink', $documentId, ['module' => $module, 'entityId' => $entityId, 'linkCount' => $newCount], $by);
                return ['ok' => true, 'error' => null, 'linkCount' => $newCount];
            }
            usleep(20000 * ($attempt + 1)); // contention → re-read fresh state and retry
        }
        return $this->err('unlink is busy (write contention) — please retry');
    }

    /**
     * Rebuild the full retention map from a document, overriding only eligibleAt.
     * Written as a whole nested map (not a dotted path) because commitBatch does
     * NOT reshape dotted keys; policy + legalHold are preserved from the read.
     */
    private function retentionMap(array $doc, ?string $eligibleAt): array
    {
        $ret = is_array($doc['retention'] ?? null) ? $doc['retention'] : [];
        return [
            'policy'     => (string) ($ret['policy'] ?? 'default'),
            'eligibleAt' => $eligibleAt,
            'legalHold'  => !empty($ret['legalHold']),
        ];
    }

    /**
     * Detach + (optionally) purge if orphaned. `$force` bypasses the retention
     * window for an explicit, permission-gated Permanent Delete.
     */
    public function delete(string $documentId, string $module, string $entityId, string $by = 'system', bool $force = false): array
    {
        $this->unlink($documentId, $module, $entityId, $by);
        $doc = $this->read($documentId);
        if (!is_array($doc)) return ['ok' => true, 'error' => null, 'purged' => false];
        $count = (int) ($doc['linkCount'] ?? 0);
        $legalHold = !empty($doc['retention']['legalHold']);
        if ($count <= 0 && !$legalHold) {
            $eligibleAt = $doc['retention']['eligibleAt'] ?? null;
            $eligible = $force || ($eligibleAt && strtotime($eligibleAt) <= time());
            if ($eligible) { $this->purge($documentId, $by); return ['ok' => true, 'error' => null, 'purged' => true]; }
        }
        return ['ok' => true, 'error' => null, 'purged' => false];
    }

    /** Set verification status: pending | verified | rejected. */
    public function verify(string $documentId, string $status, string $by = 'system'): array
    {
        if (!in_array($status, ['pending', 'verified', 'rejected'], true)) return $this->err('invalid verification status');
        $doc = $this->read($documentId);
        if (!$doc) return $this->err('document not found');
        $this->fb->firestoreUpdate($this->C_DOCS, $this->docKey($documentId), [
            'verificationStatus' => $status,
            'verifiedBy'         => $status === 'pending' ? null : $by,
            'verifiedAt'         => $status === 'pending' ? null : date('c'),
            'updatedAt'          => date('c'),
        ]);
        $this->audit('verify', $documentId, ['status' => $status], $by);
        return ['ok' => true, 'error' => null];
    }

    /** Read the canonical metadata (tenant-checked). Returns the full doc pointer or null. */
    public function getMetadata(string $documentId): ?array
    {
        $doc = $this->read($documentId);
        return $doc ? $this->pointer($doc) : null;
    }

    // ─────────────────────────────────────────────────────────────────────
    //  INTERNALS
    // ─────────────────────────────────────────────────────────────────────

    /** Hard-remove Storage object + metadata. Callers gate this (retention/permission). */
    private function purge(string $documentId, string $by): void
    {
        $doc = $this->read($documentId);
        if (!is_array($doc)) return;
        if (!empty($doc['storagePath'])) $this->fb->deleteStorageFile($doc['storagePath']);
        $this->fb->firestoreDelete($this->C_DOCS, $this->docKey($documentId));
        $this->audit('purge', $documentId, ['storagePath' => $doc['storagePath'] ?? ''], $by);
    }

    private function read(string $documentId, bool $silent = false): ?array
    {
        $doc = $this->fb->firestoreGet($this->C_DOCS, $this->docKey($documentId));
        if (!is_array($doc)) return null;
        if ((string) ($doc['schoolId'] ?? '') !== $this->schoolId) {  // tenant isolation
            if (!$silent) log_message('error', "Document_service tenant mismatch for {$documentId}");
            return null;
        }
        return $doc;
    }

    private function validate(string $tmpPath, string $origName, array $policy): array
    {
        if ($tmpPath === '' || !is_file($tmpPath) || !is_uploaded_file($tmpPath)) {
            // is_uploaded_file is false for internally-staged temp files; allow when caller opts in
            if (!is_file($tmpPath)) return ['ok' => false, 'error' => 'invalid upload'];
        }
        $size = @filesize($tmpPath) ?: 0;
        if ($size <= 0) return ['ok' => false, 'error' => 'empty file'];
        if ($size > (int) $policy['maxBytes']) {
            return ['ok' => false, 'error' => 'file exceeds ' . round($policy['maxBytes'] / 1048576, 1) . ' MB limit'];
        }
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $denyExt = (array) ($this->cfg['deny_ext'] ?? []);
        if ($ext === '' || in_array($ext, $denyExt, true) || !in_array($ext, $policy['ext'], true)) {
            return ['ok' => false, 'error' => 'unsupported file type'];
        }
        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) { $m = finfo_file($fi, $tmpPath); finfo_close($fi); if (is_string($m) && $m !== '') $mime = $m; }
        }
        $denyMime = (array) ($this->cfg['deny_mime'] ?? []);
        if (in_array($mime, $denyMime, true)) return ['ok' => false, 'error' => 'file content not allowed'];
        if ($mime !== 'application/octet-stream' && !in_array($mime, $policy['mime'], true)) {
            return ['ok' => false, 'error' => 'file content does not match its type'];
        }
        return ['ok' => true, 'ext' => $ext, 'mime' => $mime, 'size' => $size];
    }

    /** GD downscale + JPEG re-encode. Returns ['path','mime','ext','size'] or null (fall back to original). */
    private function optimizeImage(string $tmpPath, string $mime): ?array
    {
        $opt = (array) ($this->cfg['image_optimize'] ?? []);
        if (empty($opt['enabled'])) return null;
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return null;
        if (!function_exists('imagecreatetruecolor')) return null;
        $info = @getimagesize($tmpPath);
        if (!$info) return null;
        [$w, $h] = $info;
        $src = false;
        if ($mime === 'image/png' && function_exists('imagecreatefrompng'))       $src = @imagecreatefrompng($tmpPath);
        elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp'))  $src = @imagecreatefromwebp($tmpPath);
        elseif (function_exists('imagecreatefromjpeg'))                            $src = @imagecreatefromjpeg($tmpPath);
        if (!$src) return null;

        $max   = (int) ($opt['maxDim'] ?? 1200);
        $scale = min(1.0, $max / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $white); // flatten alpha → white
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $out = tempnam(sys_get_temp_dir(), 'docopt');
        $ok = imagejpeg($dst, $out, (int) ($opt['jpegQuality'] ?? 82));
        imagedestroy($src); imagedestroy($dst);
        if (!$ok || !is_file($out) || (@filesize($out) ?: 0) <= 0) { @unlink($out); return null; }
        return ['path' => $out, 'mime' => 'image/jpeg', 'ext' => 'jpg', 'size' => filesize($out)];
    }

    private function audit(string $action, string $documentId, array $meta, string $actor): void
    {
        $logId = 'DOCLOG_' . strtoupper(substr(dechex((int) (microtime(true) * 1000)), -8)) . '_' . str_pad((string) mt_rand(0, 99999), 5, '0', STR_PAD_LEFT);
        $this->fb->firestoreSet('auditLogs', $this->schoolId . '_' . $logId, [
            'schoolId'   => $this->schoolId,
            'module'     => 'document_service',
            'action'     => $action,
            'documentId' => $documentId,
            'actor'      => $actor,
            'meta'       => $meta,
            'timestamp'  => date('c'),
        ], false);
    }

    private function policy(string $type): ?array
    {
        $t = $this->cfg['types'][$type] ?? null;
        return is_array($t) ? $t : null;
    }
    private function pointer(array $doc): array
    {
        return [
            'documentId'         => $doc['documentId'] ?? '',
            'type'               => $doc['type'] ?? '',
            'label'              => $doc['label'] ?? '',
            'category'           => $doc['category'] ?? '',
            'mime'               => $doc['mime'] ?? '',
            'ext'                => $doc['ext'] ?? '',
            'sizeBytes'          => (int) ($doc['sizeBytes'] ?? 0),
            'storagePath'        => $doc['storagePath'] ?? '',
            'downloadUrl'        => $doc['downloadUrl'] ?? '',
            'version'            => (int) ($doc['version'] ?? 1),
            'verificationStatus' => $doc['verificationStatus'] ?? 'pending',
        ];
    }
    private function newDocId(): string
    {
        return 'DOC_' . strtoupper(substr(dechex((int) (microtime(true) * 1000)), -8)) . '_' . str_pad((string) mt_rand(0, 99999), 5, '0', STR_PAD_LEFT);
    }
    private function storagePath(string $documentId, int $version, string $ext): string
    {
        $prefix = trim((string) ($this->cfg['storage_prefix'] ?? 'documents'), '/');
        return "{$prefix}/{$this->schoolId}/{$documentId}_v{$version}.{$ext}";
    }
    /**
     * Unique replacement path — appends a short random token so two admins
     * replacing the same document concurrently never write the SAME object
     * (each cleans up its own on a CAS miss → no orphan, no cross-overwrite).
     */
    private function replacePath(string $documentId, int $version, string $ext): string
    {
        $prefix = trim((string) ($this->cfg['storage_prefix'] ?? 'documents'), '/');
        $rand   = bin2hex(random_bytes(3)); // 6 hex chars
        return "{$prefix}/{$this->schoolId}/{$documentId}_v{$version}_{$rand}.{$ext}";
    }
    private function docKey(string $documentId): string { return $this->schoolId . '_' . $documentId; }
    private function linkKey(string $documentId, string $module, string $entityId): string
    {
        $safe = static function ($s) { return preg_replace('/[^A-Za-z0-9_]/', '_', (string) $s); };
        return $this->schoolId . '_' . $documentId . '_' . $safe($module) . '_' . $safe($entityId);
    }
    private function err(string $msg): array { return ['ok' => false, 'error' => $msg, 'document' => null]; }
}
