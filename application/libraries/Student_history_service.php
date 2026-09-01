<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Student_history_service — Phase 16.0 (R1).
 *
 * THE ONE writer for the canonical `studentHistory` collection.
 *
 * ──────────────────────────────────────────────────────────────────────
 *  WHY THIS EXISTS
 * ──────────────────────────────────────────────────────────────────────
 * The history writer lived as `Sis::_log_history()` — a PRIVATE method on the
 * SIS controller. Every lifecycle event raised from SIS (admission, promotion,
 * withdrawal, deletion) was therefore recorded, but any lifecycle event raised
 * from a DIFFERENT controller could not be, because the writer was unreachable.
 *
 * That is exactly how CS-2 happened: `Classes::transfer_students()` moves a
 * student between sections and wrote NO history at all — a child's section
 * changed with no record of who moved them or when, while the structurally
 * identical promotion was fully audited.
 *
 * Copying the writer into Classes.php would have created a second definition
 * of the same audit record, free to drift (different doc-id shape, different
 * field names, different actor resolution) — the "duplicate business rule"
 * this programme forbids. Extracting it keeps ONE definition.
 *
 * Shape is preserved byte-for-byte from the original so existing history rows
 * and `Student_timeline_reader` continue to read identically:
 *   docId  = {schoolId}_{studentId}_{histKey}
 *   histKey= YmdHis + '_' + 6 hex chars
 *
 * `firestoreCreate` is create-if-not-exists (fails if present), so a repeated
 * write cannot overwrite an existing audit row. histKey collision remains
 * astronomically unlikely — the same risk profile the original carried.
 */
class Student_history_service
{
    private const COL = 'studentHistory';

    /** @var object|null */ private $firebase = null;
    private string $actor = 'System';

    /**
     * @param object $firebase Firebase library (provides firestoreCreate).
     * @param string $actor    Human actor label; falls back to 'System'.
     */
    public function init($firebase, string $actor = ''): self
    {
        $this->firebase = $firebase;
        $actor = trim($actor);
        $this->actor = ($actor !== '') ? $actor : 'System';
        return $this;
    }

    /**
     * Append one history event.
     *
     * Never throws and never fails the caller: an audit write must not be able
     * to roll back a completed lifecycle operation. Failures are logged so a
     * missing row is discoverable rather than silent.
     */
    public function log(
        string $schoolId,
        string $studentId,
        string $action,
        string $description,
        array  $metadata = []
    ): bool {
        if (!is_object($this->firebase) || $schoolId === '' || $studentId === '' || $action === '') {
            return false;
        }

        $histKey = date('YmdHis') . '_' . bin2hex(random_bytes(3));
        $docId   = $schoolId . '_' . $studentId . '_' . $histKey;
        $data = [
            'schoolId'    => $schoolId,
            'studentId'   => $studentId,
            'histKey'     => $histKey,
            'action'      => $action,
            'description' => $description,
            'changed_by'  => $this->actor,
            'changed_at'  => date('Y-m-d H:i:s'),
            'metadata'    => $metadata,
        ];

        try {
            $ok = (bool) $this->firebase->firestoreCreate(self::COL, $docId, $data);
            if (!$ok) {
                log_message('error', "Student_history_service: create returned false {$docId} action={$action}");
            }
            return $ok;
        } catch (\Throwable $e) {
            log_message('error', "Student_history_service: write failed {$docId} action={$action} err=" . $e->getMessage());
            return false;
        }
    }
}
