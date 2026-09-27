<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Student_panel_reader — the LAZY half of the Student 360 read model.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  WHY THIS IS SEPARATE FROM Student_context
 * ─────────────────────────────────────────────────────────────────────────
 *  `Student_context` is the EAGER core: it is built on every page load because
 *  the summary cards and every Action Registry precondition depend on it. Its
 *  cost is therefore paid by every visit, so it is deliberately capped at four
 *  queries and must stay there.
 *
 *  These panels are different. An operator opening a student to take a payment
 *  does not need their hostel allocation, their document list, or eleven months
 *  of attendance. Loading all of that eagerly would have made the counter — the
 *  common case — pay for the rare one, and would have multiplied the read cost
 *  recorded as D-04 by roughly six.
 *
 *  So each panel here is fetched **on demand**, one request per panel, only
 *  when the operator opens that tab. Adding a panel costs a tab, not a page
 *  load. That is the whole reason this class exists.
 *
 * ─────────────────────────────────────────────────────────────────────────
 *  IT READS. IT DOES NOT COMPUTE.
 * ─────────────────────────────────────────────────────────────────────────
 *  Every panel below surfaces values the owning module already stored, or
 *  calls that module's own accessor. Specifically:
 *
 *  • **Attendance percentages are NOT recalculated here.** The
 *    `attendanceSummary` writer already stores `present/absent/leave/holiday/
 *    tardy/percentage/workingDays` per month, with the school's leave policy
 *    already applied. This class displays those stored numbers verbatim.
 *    Re-deriving a percentage would mean re-implementing attendance policy —
 *    and the policy flag (`include_leave_in_percent`) is only readable from
 *    RTDB, which this platform forbids. Reading the stored value is both the
 *    correct architecture and the only compliant one. See ATT-001.
 *
 *  • **Hostel and documents go through point reads / the owning service.**
 *
 *  • **Certificates cannot be served at all** — see `certificates()`.
 *
 *  Every method fails soft: an unavailable module yields `available: false`,
 *  never an exception and never a blank workspace.
 */
class Student_panel_reader
{
    /** @var object|null */ private $firebase;
    /** @var object|null */ private $fs;
    /** @var object|null Document_service, already init()'d by the caller. */
    private $docsvc;
    private string $schoolId = '';
    private string $session  = '';

    /** @var object|null Fee_firestore_txn, already init()'d by the caller. */
    private $feeTxn;

    /** Panels this reader can serve. The controller validates against this. */
    public const PANELS = ['attendance', 'hostel', 'documents', 'certificates', 'results', 'receipts'];

    public function init($firebase, $fs, string $schoolId, string $session, $docsvc = null, $feeTxn = null): self
    {
        $this->firebase = $firebase;
        $this->fs       = $fs;
        $this->schoolId = $schoolId;
        $this->session  = $session;
        $this->docsvc   = $docsvc;
        $this->feeTxn   = $feeTxn;
        return $this;
    }

    /**
     * A-1 — Examination results, from the canonical `results` collection
     * (`Firestore_helper::RESULTS`), written by `Exam_result_store::buildResultDoc()`.
     *
     * ── NOTHING IS COMPUTED HERE ─────────────────────────────────────────
     * Percentage, grade, pass/fail, rank and the per-subject breakdown are all
     * **stored fields** on the published result document. The Result module
     * computed them under its own grading scale and passing percentage, and
     * both of those are stored on the row too. Recomputing any of it in
     * Student 360 would fork the grading rules — so this reads and displays.
     *
     * `percentage` is deliberately allowed to stay NULL (CC-8 in the writer):
     * a null percentage means "not computed", which is not the same as 0%, and
     * flattening it would misreport a child's result.
     *
     * ── ONLY PUBLISHED RESULTS ───────────────────────────────────────────
     * `results` is the published set; `resultsStaging` is the admin-only
     * pre-publish workspace. This reads `results` only, so Student 360 can
     * never show a parent-invisible draft as if it were final.
     *
     * One equality-only query. No composite index required.
     */
    public function results(string $studentId): array
    {
        $out = ['available' => false, 'exams' => [], 'latest' => null];
        if ($this->firebase === null || $studentId === '') return $out;

        try {
            $rows = $this->firebase->firestoreQuery('results', [
                ['schoolId',  '==', $this->schoolId],
                ['session',   '==', $this->session],
                ['studentId', '==', $studentId],
            ], null, 'ASC', 40);
            $out['available'] = true;

            foreach ((array) $rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : $r;
                if (!is_array($d)) continue;

                $subjects = [];
                foreach ((array) ($d['subjects'] ?? []) as $name => $s) {
                    if (!is_array($s)) continue;
                    $subjects[] = [
                        'subject'    => (string) $name,
                        'total'      => $s['total']      ?? null,
                        'maxMarks'   => $s['maxMarks']   ?? null,
                        'percentage' => $s['percentage'] ?? null,
                        'grade'      => (string) ($s['grade'] ?? ''),
                        'passFail'   => (string) ($s['passFail'] ?? ''),
                        'absent'     => !empty($s['absent']),
                    ];
                }

                $out['exams'][] = [
                    'examId'     => (string) ($d['examId'] ?? ''),
                    'examName'   => (string) ($d['examName'] ?? $d['examId'] ?? ''),
                    'className'  => (string) ($d['className'] ?? ''),
                    'section'    => (string) ($d['section'] ?? ''),
                    'totalMarks' => $d['totalMarks'] ?? null,
                    'maxMarks'   => $d['maxMarks'] ?? null,
                    // NULL preserved — "not computed" is not 0%.
                    'percentage' => array_key_exists('percentage', $d) ? $d['percentage'] : null,
                    'grade'      => (string) ($d['grade'] ?? ''),
                    'passFail'   => (string) ($d['passFail'] ?? ''),
                    'rank'       => isset($d['rank']) ? (int) $d['rank'] : null,
                    'absent'     => !empty($d['absent']),
                    'subjects'   => $subjects,
                    'computedAt' => (string) ($d['computedAt'] ?? ''),
                    'gradingScale'   => (string) ($d['gradingScale'] ?? ''),
                    'passingPercent' => $d['passingPercent'] ?? null,
                ];
            }

            // Newest first by computedAt; the first row is the "latest exam".
            usort($out['exams'], fn(array $a, array $b) => strcmp((string) $b['computedAt'], (string) $a['computedAt']));
            $out['latest'] = $out['exams'][0] ?? null;
        } catch (\Throwable $e) {
            log_message('error', 'Student_panel_reader::results failed for ' . $studentId . ': ' . $e->getMessage());
            $out['available'] = false;
        }
        return $out;
    }

    /**
     * A-3 — Receipt history, via `Fee_firestore_txn::listReceiptsForStudent()`.
     *
     * That is the Fees module's OWN per-student receipt accessor (newest
     * first). Student 360 does not query `feeReceipts` itself: receipts are
     * money records, and the collection carries dual-emitted field aliases
     * (`amount`/`input_amount`/`inputAmount`, `allocated_amount`/`allocatedAmount`)
     * that the owning module understands. Going through its accessor means the
     * shape stays that module's problem.
     *
     * Amounts are read with their documented aliases rather than one guessed
     * key — picking a single name would silently render ₹0 for receipts
     * written by whichever path used the other spelling.
     *
     * NOTE: the accessor orders by `createdAt`, so it depends on a composite
     * index owned by Fees. It is an existing production reader, so that index
     * is presumed live; if this panel errors where others do not, the index is
     * the first thing to check.
     */
    public function receipts(string $studentId, int $cap = 50): array
    {
        $out = ['available' => false, 'receipts' => [], 'truncated' => false, 'totalPaid' => 0.0];
        if ($this->feeTxn === null || $studentId === '') return $out;
        $cap = max(1, min($cap, 200));

        try {
            $rows = (array) $this->feeTxn->listReceiptsForStudent($studentId);
            $out['available'] = true;
            if (count($rows) > $cap) { $out['truncated'] = true; $rows = array_slice($rows, 0, $cap); }

            $pick = static function (array $d, array $keys) {
                foreach ($keys as $k) if (isset($d[$k]) && $d[$k] !== '') return $d[$k];
                return null;
            };

            foreach ($rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : $r;
                if (!is_array($d)) continue;

                $net = $pick($d, ['netAmount', 'net_amount']);
                $amt = $pick($d, ['amount', 'inputAmount', 'input_amount']);
                $shown = $net !== null ? (float) $net : (float) ($amt ?? 0);

                $out['receipts'][] = [
                    'receiptNo'   => (string) ($pick($d, ['receiptNo', 'receipt_no']) ?? ''),
                    'date'        => (string) ($pick($d, ['date', 'createdAt', 'created_at']) ?? ''),
                    'amount'      => $shown,
                    'discount'    => (float) ($pick($d, ['discount']) ?? 0),
                    'fine'        => (float) ($pick($d, ['fine']) ?? 0),
                    'paymentMode' => (string) ($pick($d, ['paymentMode', 'payment_mode']) ?? ''),
                    'collectedBy' => (string) ($pick($d, ['collectedBy', 'collected_by']) ?? ''),
                    'txnId'       => (string) ($pick($d, ['txnId', 'txn_id']) ?? ''),
                    'remarks'     => (string) ($pick($d, ['remarks']) ?? ''),
                    'status'      => (string) ($pick($d, ['status']) ?? 'paid'),
                ];
                $out['totalPaid'] += $shown;
            }
            $out['totalPaid'] = round($out['totalPaid'], 2);
        } catch (\Throwable $e) {
            log_message('error', 'Student_panel_reader::receipts failed for ' . $studentId . ': ' . $e->getMessage());
            $out['available'] = false;
        }
        return $out;
    }

    /**
     * Attendance — per-month rows straight from `attendanceSummary`.
     *
     * The stored `percentage` is shown as-is, per month. The session headline
     * deliberately reports **counts** (present / absent / leave / late) summed
     * across months rather than an overall percentage: summing counts is
     * arithmetic, but choosing a percentage denominator is *policy*, and that
     * policy lives in the Attendance module, not here.
     *
     * One equality-only query. No composite index required.
     */
    public function attendance(string $studentId): array
    {
        $out = ['available' => false, 'months' => [], 'totals' => [
            'present' => 0, 'absent' => 0, 'leave' => 0, 'late' => 0,
            'holiday' => 0, 'workingDays' => 0,
        ], 'latestPercent' => null];
        if ($this->firebase === null || $studentId === '') return $out;

        try {
            $rows = $this->firebase->firestoreQuery('attendanceSummary', [
                ['schoolId',  '==', $this->schoolId],
                ['studentId', '==', $studentId],
                ['session',   '==', $this->session],
            ], null, 'ASC', 24);
            $out['available'] = true;

            foreach ((array) $rows as $r) {
                $d = is_array($r['data'] ?? null) ? $r['data'] : $r;
                if (!is_array($d)) continue;
                $row = [
                    'month'       => (string) ($d['month'] ?? ''),
                    'label'       => (string) ($d['monthLabel'] ?? $d['month'] ?? ''),
                    'present'     => (int) ($d['present'] ?? 0),
                    'absent'      => (int) ($d['absent'] ?? 0),
                    'leave'       => (int) ($d['leave'] ?? 0),
                    'late'        => (int) ($d['tardy'] ?? 0),
                    'holiday'     => (int) ($d['holiday'] ?? 0),
                    'workingDays' => (int) ($d['workingDays'] ?? 0),
                    // Stored by the writer with policy already applied. NOT
                    // recomputed here — see the class header.
                    'percent'     => isset($d['percentage']) ? (float) $d['percentage'] : null,
                ];
                $out['months'][] = $row;
                foreach (['present', 'absent', 'leave', 'late', 'holiday', 'workingDays'] as $k) {
                    $out['totals'][$k] += $row[$k];
                }
            }

            // Newest month first; the most recent month's stored percentage is
            // the headline, because it is a real stored value rather than an
            // average this class would have had to invent.
            usort($out['months'], fn($a, $b) => strcmp($b['month'], $a['month']));
            foreach ($out['months'] as $m) {
                if ($m['percent'] !== null) { $out['latestPercent'] = $m['percent']; break; }
            }
        } catch (\Throwable $e) {
            log_message('error', 'Student_panel_reader::attendance failed for ' . $studentId . ': ' . $e->getMessage());
            $out['available'] = false;
        }
        return $out;
    }

    /**
     * Hostel — the allocation document, whose docId IS the studentId
     * (`Hostel::allocate()` writes `setEntity(COL_ALLOCS, $studentId, …)`).
     * One point read.
     */
    public function hostel(string $studentId): array
    {
        $out = ['available' => false, 'allocated' => false];
        if ($this->fs === null || $studentId === '') return $out;
        try {
            $out['available'] = true;
            $a = $this->fs->getEntity('hostelAllocations', $studentId);
            if (isset($a['data']) && is_array($a['data']) && !isset($a['studentId'])) $a = $a['data'];
            if (is_array($a) && $a !== []) {
                // A checked-out allocation is history, not a current placement.
                $status = (string) ($a['status'] ?? '');
                $out['allocated']    = ($status === '' || strcasecmp($status, 'Active') === 0);
                $out['status']       = $status;
                $out['building']     = (string) ($a['building_name'] ?? '');
                $out['roomNo']       = (string) ($a['room_no'] ?? '');
                $out['bedNo']        = (string) ($a['bed_no'] ?? '');
                $out['monthlyFee']   = (float)  ($a['monthly_fee'] ?? 0);
                $out['checkIn']      = (string) ($a['check_in'] ?? '');
                $out['checkOut']     = (string) ($a['check_out'] ?? '');
            }
        } catch (\Throwable $e) {
            log_message('error', 'Student_panel_reader::hostel failed for ' . $studentId . ': ' . $e->getMessage());
            $out['available'] = false;
        }
        return $out;
    }

    /**
     * Documents — the canonical Document Service, consumed read-only.
     *
     * `documentLinks` rows carry only (documentId, module, entityId, role), so
     * the display name / type / verification status must come from
     * `Document_service::getMetadata()` — one point read per linked document.
     *
     * That is an N+1 by shape, and it is why this panel is lazy and capped:
     * a student holds a handful of documents, the cost is paid only when the
     * tab is opened, and the cap is reported rather than hidden. If document
     * counts per student ever grow large, the fix is a denormalised list on
     * the student record — not a bigger cap here.
     *
     * The Document Service is a FROZEN PLATFORM BASELINE: this reads through
     * its public API and modifies nothing.
     */
    public function documents(string $studentId, int $cap = 40): array
    {
        $out = ['available' => false, 'documents' => [], 'truncated' => false];
        if ($this->firebase === null || $this->docsvc === null || $studentId === '') return $out;
        $cap = max(1, min($cap, 100));

        try {
            $links = $this->firebase->firestoreQuery('documentLinks', [
                ['schoolId', '==', $this->schoolId],
                ['module',   '==', 'student'],   // the tag Sis.php links with
                ['entityId', '==', $studentId],
            ], null, 'ASC', $cap + 1);
            $out['available'] = true;

            $links = (array) $links;
            if (count($links) > $cap) { $out['truncated'] = true; $links = array_slice($links, 0, $cap); }

            foreach ($links as $r) {
                $l = is_array($r['data'] ?? null) ? $r['data'] : $r;
                if (!is_array($l)) continue;
                $docId = (string) ($l['documentId'] ?? '');
                if ($docId === '') continue;

                $meta = null;
                try {
                    $meta = $this->docsvc->getMetadata($docId);
                } catch (\Throwable $e) {
                    // One unreadable document must not empty the panel.
                    log_message('error', 'Student_panel_reader::documents metadata failed for ' . $docId . ': ' . $e->getMessage());
                }
                $meta = is_array($meta) ? $meta : [];

                $out['documents'][] = [
                    'documentId' => $docId,
                    'role'       => (string) ($l['role'] ?? ''),
                    'name'       => (string) ($meta['originalName'] ?? $meta['name'] ?? $docId),
                    'type'       => (string) ($meta['type'] ?? ''),
                    'status'     => (string) ($meta['verificationStatus'] ?? $meta['status'] ?? ''),
                    'uploadedAt' => (string) ($meta['createdAt'] ?? ''),
                    'sizeBytes'  => (int)    ($meta['sizeBytes'] ?? $meta['size'] ?? 0),
                    'resolved'   => $meta !== [],
                ];
            }
        } catch (\Throwable $e) {
            log_message('error', 'Student_panel_reader::documents failed for ' . $studentId . ': ' . $e->getMessage());
            $out['available'] = false;
        }
        return $out;
    }

    /**
     * Certificates — DELIBERATELY NOT SERVED. See CERT-001.
     *
     * `Certificates::generate_certificate()` persists issued certificates with
     * `$this->firebase->set("Schools/{school}/{session}/Certificates/Issued/…")`,
     * and `Firebase::set()` is `$this->database->getReference($path)->set()` —
     * the Realtime Database. The issued-certificate store is therefore 100%
     * RTDB, and there is no Firestore collection holding this data.
     *
     * This platform's rule is absolute: RTDB does not exist. No fallback, no
     * mirror, no bridge, no "just this one read". Serving this panel would
     * require breaking that rule, so it is not served.
     *
     * The panel is reported as `available: false` with an explicit reason
     * rather than silently omitted, because an empty Certificates tab would
     * read as "this student has no certificates" — which is not what is being
     * said. What is being said is "this data lives somewhere this surface is
     * not permitted to read."
     *
     * UNBLOCKED BY: migrating the Certificates module to Firestore. At that
     * point this method becomes a normal equality query and nothing else in
     * Student 360 changes.
     */
    public function certificates(string $studentId): array
    {
        return [
            'available' => false,
            'reason'    => 'The issued-certificate store is still Realtime Database only '
                         . '(Certificates module, CERT-001). This platform does not read RTDB, '
                         . 'so certificate history cannot be shown here until that module is '
                         . 'migrated to Firestore.',
            'certificates' => [],
        ];
    }
}
