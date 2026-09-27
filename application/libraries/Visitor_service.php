<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Visitor_service — business logic for the Visitor Management module (MVP).
 *
 * New module, 2026-08-09. Mirrors the Transport/Hostel service-layer
 * convention: controller stays thin (permission gates + AJAX plumbing),
 * this library owns validation, tenant-scoped Firestore access, and the
 * check-in/approval/checkout state machine.
 *
 * COLLECTIONS (all school-scoped via Firestore_service::*Entity() — doc id
 * is always `{schoolId}_{businessId}`; every doc also carries its own bare
 * `id` field so callers never need to strip the prefix):
 *   visitors         — master directory (repeat visitors, vendors, contractors)
 *   visitPasses      — one document per visit (the operational log)
 *   visitorWatchlist — staff-maintained flag list, matched by name/phone
 *
 * NOTIFICATION DESIGN (deliberately narrower than the original spec draft):
 *   - A watchlist hit (any severity) fires an Ops/Staff bell notice
 *     (`notices`/`noticeRecipients`, canonical Transport-style shape) to
 *     Front Office + Security + Admin — the people who can actually act on
 *     it from the console right now.
 *   - A completed check-in (immediate or after approval/override) fires a
 *     push to the host staff member via `pushRequests` (MARK_REGISTRY
 *     `VISITOR_CHECKED_IN`) — read-only notify, Phase 1.
 *   - No "approval needed" push to the host: Phase 1 has no host-side
 *     approve/deny action (that's Phase 2, Teacher-app work), so a push the
 *     recipient can't act on would be misleading. Approval/denial/override
 *     happens on the admin console by Front Office/Admin in this phase.
 *
 * Firestore rules / indexes are NOT this library's concern — see
 * firebase-rules/firestore.rules (`visitors`/`visitPasses`/`visitorWatchlist`
 * match blocks) and firebase-rules/firestore.indexes.json.
 */
class Visitor_service
{
    // ── Collections ─────────────────────────────────────────────────────
    const COL_VISITORS   = 'visitors';
    const COL_PASSES     = 'visitPasses';
    const COL_WATCHLIST  = 'visitorWatchlist';
    const COL_NOTICES    = 'notices';
    const COL_RECIPIENTS = 'noticeRecipients';

    // ── Status machine (visitPasses.status) ─────────────────────────────
    const STATUS_PENDING_APPROVAL = 'pending_approval';
    const STATUS_CHECKED_IN       = 'checked_in';
    const STATUS_CHECKED_OUT      = 'checked_out';
    const STATUS_DENIED           = 'denied';
    const STATUS_OVERSTAY         = 'overstay';

    const SEVERITY_BLOCK  = 'block';
    const SEVERITY_FLAG   = 'flag_for_review';

    const AUTO_APPROVE_MINUTES   = 10;   // decided 2026-08-09 — no-response default
    const DEFAULT_DURATION_MINS  = 60;
    const LIST_SCAN_LIMIT        = 500;  // bounded-query guard, mirrors Transport's LIST_SCAN_LIMIT

    /**
     * F-02/F-03 (2026-08-09 correction pass): 'manual' = front-desk enters/
     * taps the passId directly. 'qr_manual' = the badge QR was decoded +
     * cryptographically verified by scan_qr_lookup() first, THEN the
     * operator took a separate explicit confirm action — the scan alone
     * never flips status (see checkoutPass()). No RFID/biometric reader
     * exists, so those values are refused, not just unused.
     */
    const ALLOWED_VERIFICATION_METHODS = ['manual', 'qr_manual'];

    const ALLOWED_PURPOSES = [
        'Meet Staff / Teacher', 'Admission Enquiry', 'Delivery',
        'Vendor / Maintenance', 'Event / PTM', 'Government / Inspection', 'Other',
        // NOTE: "Pickup — Early Leave" intentionally excluded from MVP per
        // 2026-08-09 decision — needs Attendance-module verification first
        // (does a guardian-pickup flow already exist there?) before this
        // module builds a second, possibly-conflicting parent-facing path.
    ];
    const ALLOWED_VISITOR_TYPES = [
        'Guardian / Parent', 'Vendor', 'Contractor', 'Delivery',
        'Government / Inspection', 'Alumni', 'Admission Enquiry', 'Other',
    ];
    const ALLOWED_ID_PROOF_TYPES = ['Aadhaar', 'Driving Licence', 'PAN', 'Voter ID', 'Passport', 'Other'];
    const ALLOWED_SEVERITIES = [self::SEVERITY_BLOCK, self::SEVERITY_FLAG];

    /** Roles alerted on a watchlist hit / overstay — console bell, not push. */
    const OPS_ROLES = ['Admin', 'Front Office', 'Security'];

    /** @var Firestore_service */
    private $fs;
    /** @var Numbering_service */
    private $numbering;
    private $schoolId  = '';
    private $sessionYear = '';
    private $adminId   = 'system';
    private $adminName = 'System';
    private $ready     = false;

    public function init($fs, $numbering, string $schoolId, string $sessionYear = '', string $adminId = 'system', string $adminName = ''): self
    {
        $this->fs         = $fs;
        $this->numbering  = $numbering;
        $this->schoolId   = $schoolId;
        $this->sessionYear = $sessionYear;
        $this->adminId    = $adminId !== '' ? $adminId : 'system';
        $this->adminName  = $adminName !== '' ? $adminName : $this->adminId;
        $this->ready      = true;
        return $this;
    }

    private function _assertReady(): void
    {
        if (!$this->ready) {
            throw new \LogicException('Visitor_service::init() must be called before use.');
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  VISITOR DIRECTORY
    // ══════════════════════════════════════════════════════════════════

    /** Normalize a phone number to digits-only for matching (not storage). */
    private function _normPhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function _normName(string $name): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
    }

    public function searchVisitorByPhone(string $phone): ?array
    {
        $this->_assertReady();
        $phone = trim($phone);
        if ($phone === '') return null;
        $rows = $this->fs->schoolWhere(self::COL_VISITORS, [['phone', '==', $phone]], null, 'ASC', 1);
        if (empty($rows)) return null;
        $data = (array) ($rows[0]['data'] ?? []);
        $data['id'] = $data['id'] ?? $this->_stripPrefix((string) ($rows[0]['id'] ?? ''));
        return $data;
    }

    /** Lightweight typeahead over the visitor directory — bounded, in-memory filter (NF-1 pattern). */
    /** F-10 (2026-08-09 correction pass): same 5-minute per-tenant file-cache idiom as Transport_service::searchStudents — see Gate_pass_service::_cachedStudentIndex for the shared rationale. */
    public function searchVisitors(string $q, int $limit = 20): array
    {
        $this->_assertReady();
        $q = strtolower(trim($q));
        $index = $this->_cachedIndex('cam_visitors_', self::COL_VISITORS, function (array $d, string $strippedId) {
            $name = (string) ($d['name'] ?? '');
            if ($name === '') return null;
            return [
                'id' => $d['id'] ?? $strippedId,
                'name' => $name, 'phone' => (string) ($d['phone'] ?? ''),
                'visitorType' => (string) ($d['visitorType'] ?? ''),
                'organization' => (string) ($d['organization'] ?? ''),
                'visitCount' => (int) ($d['visitCount'] ?? 0),
            ];
        });
        $out = [];
        foreach ($index as $d) {
            if ($q !== '' && stripos($d['name'], $q) === false && stripos($d['phone'], $q) === false) continue;
            $out[] = $d;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    /** Bounded scan + 5-minute file cache, keyed per-tenant so a cache hit can never cross a school boundary. */
    private function _cachedIndex(string $cacheKeyPrefix, string $collection, callable $mapRow): array
    {
        $cacheKey = $cacheKeyPrefix . md5($this->schoolId);
        $CI = &get_instance();
        $index = false;
        try {
            $CI->load->driver('cache', ['adapter' => 'file']);
            $index = $CI->cache->get($cacheKey);
        } catch (\Throwable $_) { $index = false; }
        if (is_array($index)) return $index;

        $index = [];
        $rows = $this->fs->schoolWhere($collection, [], 'name', 'ASC', self::LIST_SCAN_LIMIT);
        foreach ($rows as $row) {
            $d = (array) ($row['data'] ?? []);
            $mapped = $mapRow($d, $this->_stripPrefix((string) ($row['id'] ?? '')));
            if ($mapped !== null) $index[] = $mapped;
        }
        try { $CI->cache->save($cacheKey, $index, 300); } catch (\Throwable $_) {}
        return $index;
    }

    /**
     * Find-or-create the visitor master record. Returns
     * ['id'=>string,'isNew'=>bool,'data'=>array].
     */
    public function upsertVisitor(array $input): array
    {
        $this->_assertReady();
        $name  = trim((string) ($input['name']  ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        if ($name === '' || $phone === '') {
            throw new \InvalidArgumentException('Visitor name and phone are required.');
        }
        $visitorType = (string) ($input['visitorType'] ?? 'Other');
        if (!in_array($visitorType, self::ALLOWED_VISITOR_TYPES, true)) $visitorType = 'Other';
        $idProofType = (string) ($input['idProofType'] ?? '');
        if ($idProofType !== '' && !in_array($idProofType, self::ALLOWED_ID_PROOF_TYPES, true)) $idProofType = 'Other';
        // Structural format check (2026-08-09 hardening) — catches an obviously
        // fabricated/mistyped number before it's ever stored, not just masked
        // after the fact. Aadhaar additionally gets the real Verhoeff checksum
        // UIDAI itself uses, which a made-up 12-digit string fails with very
        // high probability. Never accepts one field without the other.
        $idProofNumberNormalized = $this->_validateIdProof($idProofType, (string) ($input['idProofNumber'] ?? ''));

        $existing = $this->searchVisitorByPhone($phone);
        $isNew = $existing === null;
        $id = $isNew
            ? $this->numbering->next('vms_visitor', ['claimedBy' => $this->adminId, 'claimedFor' => 'Visitor:upsertVisitor'])
            : (string) $existing['id'];

        $data = [
            'id'            => $id,
            'name'          => $name,
            'phone'         => $phone,
            'visitorType'   => $visitorType,
            'organization'  => trim((string) ($input['organization'] ?? '')),
            'idProofType'   => $idProofType,
            'idProofNumberMasked' => $this->_maskIdProof($idProofNumberNormalized),
            'vehicleNumber' => strtoupper(trim((string) ($input['vehicleNumber'] ?? ''))),
        ];
        if ($isNew) {
            $data['visitCount'] = 0;
            $data['createdBy']  = $this->adminId;
        }
        $this->fs->setEntity(self::COL_VISITORS, $id, $data, true); // merge — never clobbers idProofPhotoDocumentId etc.
        $stored = $this->fs->getEntity(self::COL_VISITORS, $id) ?? $data;
        return ['id' => $id, 'isNew' => $isNew, 'data' => $stored];
    }

    /** Last 4 digits only — the module never stores a full ID-proof number as text. */
    private function _maskIdProof(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if ($digits === '') return '';
        return '••••' . substr($digits, -4);
    }

    /**
     * Structural format check per ID type (2026-08-09 hardening) — catches an
     * obviously fabricated/mistyped number BEFORE it's ever stored, rather
     * than only masking a possibly-bogus one after the fact. Aadhaar gets
     * the real Verhoeff checksum UIDAI itself uses (self-verified round-trip
     * against the standard tables — see _verhoeffIsValid()), which a
     * made-up 12-digit string fails with very high probability. Neither
     * field is required overall, but if one is filled the other must be too
     * — a type with no number, or a number with no declared type, is exactly
     * the kind of incomplete/unverifiable record this is meant to prevent.
     *
     * @return string the normalized (trimmed/uppercased/de-hyphenated) number
     */
    private function _validateIdProof(string $type, string $rawNumber): string
    {
        $number = strtoupper(trim($rawNumber));
        if ($type === '' && $number === '') return '';
        if ($type === '' && $number !== '') {
            throw new \InvalidArgumentException('Select an ID proof type before entering the number.');
        }
        if ($type !== '' && $number === '') {
            throw new \InvalidArgumentException('Enter the ID proof number, or clear the ID proof type.');
        }

        switch ($type) {
            case 'Aadhaar':
                $digits = preg_replace('/\s+/', '', $number) ?? '';
                if (!preg_match('/^\d{12}$/', $digits)) {
                    throw new \InvalidArgumentException('Aadhaar number must be exactly 12 digits.');
                }
                if (!$this->_verhoeffIsValid($digits)) {
                    throw new \InvalidArgumentException('That Aadhaar number fails the standard checksum — please re-check it.');
                }
                return $digits;

            case 'PAN':
                $pan = preg_replace('/\s+/', '', $number) ?? '';
                if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) {
                    throw new \InvalidArgumentException('PAN must match the standard format, e.g. ABCDE1234F.');
                }
                return $pan;

            case 'Voter ID':
                $epic = preg_replace('/\s+/', '', $number) ?? '';
                if (!preg_match('/^[A-Z]{3}[0-9]{7}$/', $epic)) {
                    throw new \InvalidArgumentException('Voter ID (EPIC) must match the standard format, e.g. ABC1234567.');
                }
                return $epic;

            case 'Passport':
                $passport = preg_replace('/\s+/', '', $number) ?? '';
                if (!preg_match('/^[A-Z][0-9]{7}$/', $passport)) {
                    throw new \InvalidArgumentException('Indian passport numbers are 1 letter followed by 7 digits, e.g. A1234567.');
                }
                return $passport;

            case 'Driving Licence':
                // State-issued formats vary too widely (length, hyphenation,
                // era) for one strict regex — a loose sanity bound catches
                // obvious junk without rejecting a genuine, unusually-shaped DL.
                $dl = preg_replace('/[\s-]+/', '', $number) ?? '';
                if (!preg_match('/^[A-Z0-9]{10,16}$/', $dl)) {
                    throw new \InvalidArgumentException('Driving Licence number looks too short/long or has unexpected characters.');
                }
                return $dl;

            default: // 'Other' — no known structure to validate against.
                if (strlen($number) < 3) {
                    throw new \InvalidArgumentException('ID proof number looks too short.');
                }
                return $number;
        }
    }

    /**
     * Verhoeff checksum — the algorithm UIDAI itself uses for the Aadhaar
     * check digit. Standard multiplication/permutation/inverse tables;
     * self-verified via a round-trip generate-then-validate test against 20
     * random prefixes (plus single-digit-mutation must always break it)
     * before being wired in. A randomly-typed 12-digit string passes this
     * by chance only ~1 in 10 times, so it meaningfully raises the bar
     * against a fabricated Aadhaar number without needing any external API.
     */
    private function _verhoeffIsValid(string $digits): bool
    {
        static $d = [
            [0,1,2,3,4,5,6,7,8,9],[1,2,3,4,0,6,7,8,9,5],[2,3,4,0,1,7,8,9,5,6],
            [3,4,0,1,2,8,9,5,6,7],[4,0,1,2,3,9,5,6,7,8],[5,9,8,7,6,0,4,3,2,1],
            [6,5,9,8,7,1,0,4,3,2],[7,6,5,9,8,2,1,0,4,3],[8,7,6,5,9,3,2,1,0,4],
            [9,8,7,6,5,4,3,2,1,0],
        ];
        static $p = [
            [0,1,2,3,4,5,6,7,8,9],[1,5,7,6,2,8,3,0,9,4],[5,8,0,3,7,9,6,1,4,2],
            [8,9,1,6,0,4,3,5,2,7],[9,4,5,3,1,2,6,8,7,0],[4,2,8,6,5,7,3,9,0,1],
            [2,7,9,3,8,0,6,4,1,5],[7,0,4,6,9,1,3,2,5,8],
        ];
        $c = 0;
        $chars = array_reverse(str_split($digits));
        foreach ($chars as $i => $ch) {
            $c = $d[$c][$p[$i % 8][(int) $ch]];
        }
        return $c === 0;
    }

    /**
     * Attach an uploaded photo / ID-proof / verification document via the
     * Canonical Document Service. $role: 'photo' | 'id_proof' | 'background_verification'.
     * For 'photo', also caches the downloadUrl on the visitor record
     * (photoUrl) so the front desk / badge can render it without a second
     * document-metadata round trip.
     */
    public function attachVisitorDocument(string $visitorId, string $documentId, string $role): bool
    {
        $this->_assertReady();
        $CI = &get_instance();
        $CI->load->library('document_service', null, 'docsvc');
        $docsvc = $CI->docsvc;
        $docsvc->init($CI->firebase, $this->schoolId);
        $res = $docsvc->link($documentId, 'visitor', $visitorId, $role, $this->adminId);
        if (!(bool) ($res['ok'] ?? false)) return false;

        $update = [];
        if ($role === 'background_verification') {
            $update['backgroundVerificationDocumentId'] = $documentId;
        } elseif ($role === 'photo') {
            $update['photoDocumentId'] = $documentId;
            $meta = $docsvc->getMetadata($documentId);
            if (!empty($meta['downloadUrl'])) {
                $update['photoUrl'] = (string) $meta['downloadUrl'];
            }
        } else {
            $update['idProofPhotoDocumentId'] = $documentId;
        }
        return $this->fs->setEntity(self::COL_VISITORS, $visitorId, $update, true);
    }

    // ══════════════════════════════════════════════════════════════════
    //  WATCHLIST
    // ══════════════════════════════════════════════════════════════════

    public function listWatchlist(): array
    {
        $this->_assertReady();
        $rows = $this->fs->schoolWhere(self::COL_WATCHLIST, [], 'addedAt', 'DESC', self::LIST_SCAN_LIMIT);
        return array_map(function ($row) {
            $d = (array) ($row['data'] ?? []);
            $d['id'] = $d['id'] ?? $this->_stripPrefix((string) ($row['id'] ?? ''));
            return $d;
        }, $rows);
    }

    public function addWatchlistEntry(array $input): array
    {
        $this->_assertReady();
        $name  = trim((string) ($input['name']  ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        if ($name === '' && $phone === '') {
            throw new \InvalidArgumentException('A watchlist entry needs a name or a phone number.');
        }
        $severity = (string) ($input['severity'] ?? self::SEVERITY_FLAG);
        if (!in_array($severity, self::ALLOWED_SEVERITIES, true)) {
            throw new \InvalidArgumentException('Invalid severity.');
        }
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw new \InvalidArgumentException('A reason is required for every watchlist entry (audit trail).');
        }
        $id = $this->numbering->next('vms_watchlist', ['claimedBy' => $this->adminId, 'claimedFor' => 'Visitor:addWatchlistEntry']);
        $now = date('c');
        $expiresAt = trim((string) ($input['expiresAt'] ?? ''));
        $data = [
            'id' => $id, 'name' => $name, 'phone' => $phone,
            'severity' => $severity, 'reason' => $reason,
            'addedBy' => $this->adminId, 'addedByName' => $this->adminName, 'addedAt' => $now,
            'expiresAt' => $expiresAt !== '' ? $expiresAt : null,
        ];
        $this->fs->setEntity(self::COL_WATCHLIST, $id, $data, false);
        return $data;
    }

    /** Soft-removal — keeps the audit trail instead of deleting. */
    public function deactivateWatchlistEntry(string $id, string $reason): bool
    {
        $this->_assertReady();
        return $this->fs->updateEntity(self::COL_WATCHLIST, $id, [
            'expiresAt' => date('c'),
            'deactivatedBy' => $this->adminId,
            'deactivationReason' => $reason,
        ]);
    }

    /**
     * Match a name/phone against the active watchlist. Prefers a `block`
     * match over a `flag_for_review` match when both are present. Expired
     * entries (expiresAt in the past) never match.
     */
    public function matchWatchlist(string $name, string $phone): ?array
    {
        $this->_assertReady();
        $normName  = $this->_normName($name);
        $normPhone = $this->_normPhone($phone);
        if ($normName === '' && $normPhone === '') return null;

        $today = date('c');
        $best = null;
        foreach ($this->listWatchlist() as $entry) {
            $expiresAt = (string) ($entry['expiresAt'] ?? '');
            if ($expiresAt !== '' && $expiresAt <= $today) continue; // expired or soft-deactivated
            $entryName  = $this->_normName((string) ($entry['name'] ?? ''));
            $entryPhone = $this->_normPhone((string) ($entry['phone'] ?? ''));
            $hit = ($entryName !== '' && $entryName === $normName)
                || ($entryPhone !== '' && $entryPhone === $normPhone);
            if (!$hit) continue;
            if ($best === null || ($entry['severity'] ?? '') === self::SEVERITY_BLOCK) {
                $best = $entry;
                if (($entry['severity'] ?? '') === self::SEVERITY_BLOCK) break; // block always wins
            }
        }
        return $best;
    }

    // ══════════════════════════════════════════════════════════════════
    //  STAFF LOOKUP (host-staff typeahead — no caching yet; school-sized lists)
    // ══════════════════════════════════════════════════════════════════

    /** F-10 (2026-08-09 correction pass): cached — see searchVisitors/_cachedIndex above. */
    public function searchStaff(string $q, int $limit = 15): array
    {
        $this->_assertReady();
        $q = strtolower(trim($q));
        $index = $this->_cachedIndex('cam_staff_', 'staff', function (array $d, string $strippedId) {
            $name = (string) ($d['name'] ?? '');
            if ($name === '') return null;
            return [
                'id' => $d['id'] ?? $strippedId,
                'name' => $name,
                'designation' => (string) ($d['designation'] ?? $d['role'] ?? ''),
            ];
        });
        $out = [];
        foreach ($index as $d) {
            if ($q !== '' && stripos($d['name'], $q) === false) continue;
            $out[] = $d;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    private function _stripPrefix(string $docId): string
    {
        $prefix = $this->schoolId . '_';
        return strpos($docId, $prefix) === 0 ? substr($docId, strlen($prefix)) : $docId;
    }

    // ══════════════════════════════════════════════════════════════════
    //  PASS LIFECYCLE
    // ══════════════════════════════════════════════════════════════════

    public function createPass(array $input): array
    {
        $this->_assertReady();
        $purpose = (string) ($input['purpose'] ?? '');
        if (!in_array($purpose, self::ALLOWED_PURPOSES, true)) {
            throw new \InvalidArgumentException('Invalid or unsupported purpose.');
        }
        $visitor = $this->upsertVisitor($input);
        $visitorId = $visitor['id'];
        $visitorData = $visitor['data'];

        $match = $this->matchWatchlist((string) $visitorData['name'], (string) $visitorData['phone']);

        $passId = $this->numbering->next('vms_pass', ['claimedBy' => $this->adminId, 'claimedFor' => 'Visitor:createPass']);
        $now = date('c');
        $expectedDuration = (int) ($input['expectedDurationMinutes'] ?? self::DEFAULT_DURATION_MINS);
        if ($expectedDuration <= 0) $expectedDuration = self::DEFAULT_DURATION_MINS;

        // 2026-08-09 hardening: hostStaffId used to be taken raw from the
        // client and stored as-is — the same class of gap as the gate-pass
        // F-08 fix (an unresolvable or mismatched id/name pair could be
        // submitted and silently accepted, then even used as the target of
        // the host-notification push). Resolve against the real staff
        // record and always use ITS name, never the client-claimed one.
        $hostStaffId = trim((string) ($input['hostStaffId'] ?? ''));
        $hostStaffName = trim((string) ($input['hostStaffName'] ?? ''));
        if ($hostStaffId !== '') {
            $hostRecord = $this->fs->getEntity('staff', $hostStaffId);
            if ($hostRecord === null) {
                throw new \InvalidArgumentException('Selected host staff member was not found — please pick one from the search results.');
            }
            $resolvedHostName = trim((string) ($hostRecord['name'] ?? ''));
            if ($resolvedHostName !== '') $hostStaffName = $resolvedHostName;
        } elseif ($hostStaffName !== '') {
            // A name with no id is unverifiable free text — reject rather
            // than silently store an identity that was never actually
            // looked up (this can only happen via a direct API call; the
            // search-and-click UI never submits a name without an id).
            throw new \InvalidArgumentException('Host staff must be selected from the search results, not entered as free text.');
        }

        $pass = [
            'id' => $passId,
            'visitorId' => $visitorId,
            'visitorNameSnapshot'  => $visitorData['name'],
            'visitorPhoneSnapshot' => $visitorData['phone'],
            'purpose' => $purpose,
            'purposeNote' => trim((string) ($input['purposeNote'] ?? '')),
            'hostStaffId' => $hostStaffId,
            'hostStaffNameSnapshot' => $hostStaffName,
            'source' => 'walk_in',
            'requestedAt' => $now,
            'checkInAt' => null, 'checkInBy' => null,
            'checkOutAt' => null, 'checkOutBy' => null,
            'approvedBy' => null, 'approvedAt' => null, 'autoApproved' => false,
            'deniedBy' => null, 'deniedAt' => null, 'deniedReason' => null,
            'overriddenBy' => null, 'overriddenAt' => null, 'overrideReason' => null,
            'badgeCode' => '',
            'watchlistHit' => $match !== null,
            'watchlistSeverity' => $match['severity'] ?? null,
            'watchlistReason' => $match['reason'] ?? null,
            'expectedDurationMinutes' => $expectedDuration,
            'accompanying' => max(0, (int) ($input['accompanying'] ?? 0)),
            'vehicleNumber' => $visitorData['vehicleNumber'] ?? '',
            'notes' => trim((string) ($input['notes'] ?? '')),
            'overstayNotifiedAt' => null,
            'createdBy' => $this->adminId,
            // Idempotent write-through markers for the unified campusAccessEvents
            // ledger (2026-08-09 decision) — explicitly initialized false (not
            // omitted) so the reconciliation sweep's `== false` query can find
            // them; see _syncCampusAccessEvent()/reconcileCampusEvents().
            'campusEventSyncedIn' => false,
            'campusEventSyncedOut' => false,
        ];

        if ($match !== null) {
            if ($match['severity'] === self::SEVERITY_BLOCK) {
                $pass['status'] = self::STATUS_DENIED;
                $pass['deniedBy'] = 'system';
                $pass['deniedAt'] = $now;
                $pass['deniedReason'] = 'Watchlist block — ' . (string) $match['reason'];
            } else {
                $pass['status'] = self::STATUS_PENDING_APPROVAL;
            }
            $this->_fanoutOpsNotice(
                'watchlist_hit',
                'high',
                'Visitor Watchlist Match',
                sprintf('%s (%s) matched the watchlist — %s.', $visitorData['name'], $match['severity'], $match['reason']),
                ['passId' => $passId, 'visitorId' => $visitorId, 'severity' => $match['severity']]
            );
        } else {
            $pass['status'] = self::STATUS_CHECKED_IN;
            $pass['checkInAt'] = $now;
            $pass['checkInBy'] = $this->adminId;
        }

        $this->fs->setEntity(self::COL_PASSES, $passId, $pass, false);

        $badgeCode = '';
        if ($pass['status'] !== self::STATUS_DENIED) {
            $badgeCode = $this->_mintBadgeCode($passId);
            $this->fs->updateEntity(self::COL_PASSES, $passId, ['badgeCode' => $badgeCode]);
        }

        $this->fs->updateEntity(self::COL_VISITORS, $visitorId, [
            'visitCount' => (int) ($visitorData['visitCount'] ?? 0) + 1,
            'lastVisitAt' => $now,
        ]);

        if ($pass['status'] === self::STATUS_CHECKED_IN && $hostStaffId !== '') {
            $this->_notifyHostCheckedIn($passId, $hostStaffId, $visitorData['name'], $purpose);
        }
        if ($pass['status'] === self::STATUS_CHECKED_IN) {
            $this->_syncCampusAccessEvent($passId, 'in', $visitorId, $visitorData['name']);
        }

        $stored = $this->fs->getEntity(self::COL_PASSES, $passId) ?? $pass;
        $stored['badgeCode'] = $stored['badgeCode'] ?? $badgeCode;
        return $stored;
    }

    public function approvePass(string $passId, bool $autoApproved = false): array
    {
        $this->_assertReady();
        $pass = $this->_requirePass($passId);
        if ($pass['status'] !== self::STATUS_PENDING_APPROVAL) {
            throw new \InvalidArgumentException('Only a pending-approval pass can be approved.');
        }
        $now = date('c');
        $by = $autoApproved ? 'system' : $this->adminId;
        $this->fs->updateEntity(self::COL_PASSES, $passId, [
            'status' => self::STATUS_CHECKED_IN,
            'checkInAt' => $now, 'checkInBy' => $by,
            'approvedBy' => $by, 'approvedAt' => $now, 'autoApproved' => $autoApproved,
        ]);
        $updated = $this->fs->getEntity(self::COL_PASSES, $passId);
        $hostStaffId = (string) ($updated['hostStaffId'] ?? '');
        if ($hostStaffId !== '') {
            $this->_notifyHostCheckedIn($passId, $hostStaffId, (string) ($updated['visitorNameSnapshot'] ?? ''), (string) ($updated['purpose'] ?? ''));
        }
        $this->_syncCampusAccessEvent($passId, 'in', (string) ($updated['visitorId'] ?? ''), (string) ($updated['visitorNameSnapshot'] ?? ''));
        return $updated ?? $pass;
    }

    public function denyPass(string $passId, string $reason): array
    {
        $this->_assertReady();
        $pass = $this->_requirePass($passId);
        if (!in_array($pass['status'], [self::STATUS_PENDING_APPROVAL], true)) {
            throw new \InvalidArgumentException('Only a pending-approval pass can be denied.');
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A denial reason is required.');
        }
        $this->fs->updateEntity(self::COL_PASSES, $passId, [
            'status' => self::STATUS_DENIED,
            'deniedBy' => $this->adminId, 'deniedAt' => date('c'), 'deniedReason' => trim($reason),
        ]);
        return $this->fs->getEntity(self::COL_PASSES, $passId) ?? $pass;
    }

    /** Front Office override of a `block`-severity denial — logged, no Admin gate (2026-08-09 decision). */
    public function overrideBlock(string $passId, string $reason): array
    {
        $this->_assertReady();
        $pass = $this->_requirePass($passId);
        if ($pass['status'] !== self::STATUS_DENIED || ($pass['watchlistSeverity'] ?? '') !== self::SEVERITY_BLOCK) {
            throw new \InvalidArgumentException('Override only applies to a watchlist-block denial.');
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('An override reason is required (audit trail).');
        }
        $now = date('c');
        $this->fs->updateEntity(self::COL_PASSES, $passId, [
            'status' => self::STATUS_CHECKED_IN,
            'checkInAt' => $now, 'checkInBy' => $this->adminId,
            'overriddenBy' => $this->adminId, 'overriddenAt' => $now, 'overrideReason' => trim($reason),
            'deniedBy' => null, 'deniedAt' => null, 'deniedReason' => null,
        ]);
        if (($pass['badgeCode'] ?? '') === '') {
            $this->fs->updateEntity(self::COL_PASSES, $passId, ['badgeCode' => $this->_mintBadgeCode($passId)]);
        }
        $updated = $this->fs->getEntity(self::COL_PASSES, $passId);
        // BUG-058 fix (2026-08-10): the original watchlist-hit that produced
        // this block proactively notified Admin/Front Office/Security
        // (createPass()'s _fanoutOpsNotice('watchlist_hit', ...) above) — but
        // overriding that block (a human decision to let a flagged/blocked
        // person onto campus, arguably the more security-sensitive event of
        // the two) produced no equivalent notice and was only discoverable
        // by opening that specific pass record later. Same pattern, same
        // priority tier, so Admin/Security get the same passive visibility.
        $this->_fanoutOpsNotice(
            'watchlist_override',
            'high',
            'Watchlist Block Overridden',
            sprintf('%s — a watchlist block was overridden and the visitor was checked in. Reason: %s.', (string) ($updated['visitorNameSnapshot'] ?? 'A visitor'), trim($reason)),
            ['passId' => $passId, 'overriddenBy' => $this->adminId]
        );
        $hostStaffId = (string) ($updated['hostStaffId'] ?? '');
        if ($hostStaffId !== '') {
            $this->_notifyHostCheckedIn($passId, $hostStaffId, (string) ($updated['visitorNameSnapshot'] ?? ''), (string) ($updated['purpose'] ?? ''));
        }
        $this->_syncCampusAccessEvent($passId, 'in', (string) ($updated['visitorId'] ?? ''), (string) ($updated['visitorNameSnapshot'] ?? ''));
        return $updated ?? $pass;
    }

    /**
     * F-02/F-03 (2026-08-09 correction pass): $verificationMethod is now a
     * server-checked allowlist (was free-text before — a client could claim
     * 'rfid' with no reader to back it up). When 'qr_manual' is claimed, the
     * token is RE-DECODED AND RE-VERIFIED here, server-side, rather than
     * trusting that scan_qr_lookup() (a separate, read-only prior call) was
     * ever actually invoked — checkoutPass() is the only place a state
     * change happens, so it's the only place that can't be skipped.
     */
    public function checkoutPass(string $passId, string $verificationMethod = 'manual', string $qrToken = ''): array
    {
        $this->_assertReady();
        $pass = $this->_requirePass($passId);
        if (!in_array($pass['status'], [self::STATUS_CHECKED_IN, self::STATUS_OVERSTAY], true)) {
            throw new \InvalidArgumentException('Only an on-campus visitor can be checked out.');
        }
        if (!in_array($verificationMethod, self::ALLOWED_VERIFICATION_METHODS, true)) {
            throw new \InvalidArgumentException("Unsupported verification method '{$verificationMethod}'.");
        }
        if ($verificationMethod === 'qr_manual') {
            $decoded = $this->_decodeQrToken($qrToken);
            if ($decoded === null || $decoded['schoolId'] !== $this->schoolId || $decoded['studentId'] !== $passId) {
                throw new \InvalidArgumentException('QR code is invalid, expired, or does not match this pass — checkout was NOT performed.');
            }
        }
        $this->fs->updateEntity(self::COL_PASSES, $passId, [
            'status' => self::STATUS_CHECKED_OUT,
            'checkOutAt' => date('c'), 'checkOutBy' => $this->adminId,
            'checkOutVerificationMethod' => $verificationMethod,
        ]);
        $updated = $this->fs->getEntity(self::COL_PASSES, $passId) ?? $pass;
        $this->_syncCampusAccessEvent($passId, 'out', (string) ($updated['visitorId'] ?? ''), (string) ($updated['visitorNameSnapshot'] ?? ''));
        return $updated;
    }

    /**
     * F-03 (2026-08-09 correction pass): READ-ONLY QR lookup — decodes +
     * cryptographically verifies the badge token (reusing qr_token_helper.php,
     * not a new token system) and returns pass details for the operator to
     * VISUALLY confirm the person's identity. Never changes any state — the
     * only thing that can check someone out is a separate, explicit
     * checkoutPass() call. This is the "cryptographic validation" half of
     * QR verification; the human physical check is the other half, and this
     * method deliberately does not substitute for it.
     */
    public function lookupByQrToken(string $token): array
    {
        $this->_assertReady();
        $decoded = $this->_decodeQrToken($token);
        if ($decoded === null) {
            throw new \InvalidArgumentException('QR code is invalid or unreadable.');
        }

        // ── SEC-ID-1 / ID-VIS-1 (Phase 19L) ──────────────────────────────
        // A visitor badge is NOT a student ID card. It carries a passId
        // (VPS######) in the subject slot, is minted V1 by _mintBadgeCode(),
        // and has no `idCards` record at all — the two credential families
        // merely share the token encoder.
        //
        // So this deliberately does NOT pass Id_card_service::configuredFlags().
        // Those flags express STUDENT-CARD retirement policy; inheriting them
        // here would mean that switching on IDCARD_REJECT_V1 — a decision about
        // reprinting student cards — silently invalidated every visitor badge
        // in the school. That coupling is registered as ID-VIS-1.
        //
        // What IS shared is the part that belongs to every credential: the
        // malformed check and tenant isolation. Passing all-false flags takes
        // exactly those from the canonical authority and nothing else, so this
        // consumer stops duplicating the tenant comparison while remaining
        // immune to student-card lifecycle policy.
        $CI = &get_instance();
        $CI->load->library('Id_card_service', null, 'idCardSvcVis');
        if (!$CI->idCardSvcVis->isReady()) {
            $CI->idCardSvcVis->init($CI->firebase ?? null, $this->fs, $this->schoolId, $this->adminId ?? 'gate');
        }
        if ($CI->idCardSvcVis->isReady()) {
            $cred = $CI->idCardSvcVis->validateCredential(
                $decoded,
                ['enforceV2' => false, 'rejectLegacy' => false, 'rejectV1' => false]
            );
            if (empty($cred['valid'])) {
                log_message('error',
                    "SEC-ID-1 Visitor badge rejected ({$cred['code']}) school={$this->schoolId}");
                throw new \InvalidArgumentException(
                    \Id_card_service::rejectionMessage((string) $cred['code']));
            }
        }

        // Retained as defence in depth — the canonical check above may be
        // skipped if the card service could not be built.
        if ($decoded['schoolId'] !== $this->schoolId) {
            throw new \InvalidArgumentException('QR code does not belong to this school.');
        }
        $passId = $decoded['studentId'];
        $pass = $this->_requirePass($passId);
        return [
            'passId' => $passId,
            'pass' => $pass,
            'canCheckout' => in_array($pass['status'], [self::STATUS_CHECKED_IN, self::STATUS_OVERSTAY], true),
            'legacy' => (bool) ($decoded['legacy'] ?? false),
        ];
    }

    private function _decodeQrToken(string $token): ?array
    {
        $CI = &get_instance();
        $CI->load->helper('qr_token');
        return qr_token_decode($token);
    }

    private function _requirePass(string $passId): array
    {
        $pass = $this->fs->getEntity(self::COL_PASSES, $passId);
        if ($pass === null) {
            throw new \InvalidArgumentException("Visit pass {$passId} not found.");
        }
        return $pass;
    }

    private function _mintBadgeCode(string $passId): string
    {
        $CI = &get_instance();
        $CI->load->helper('qr_token');
        return qr_token_encode($this->schoolId, $passId);
    }

    // ══════════════════════════════════════════════════════════════════
    //  QUERIES (dashboard / log)
    // ══════════════════════════════════════════════════════════════════

    /**
     * "Today at the gate" board — everyone currently on campus PLUS anyone
     * needing front-desk attention right now. STATUS_DENIED was missing
     * here until 2026-08-09: a block-severity watchlist hit denies the
     * visitor immediately, but with 'denied' absent from this filter the
     * row (and its Override block button, which already existed in the
     * view) had nowhere to render — the override workflow was reachable in
     * code but not in the UI. Same board as pending_approval for the same
     * reason: neither is "on campus" in the literal sense, but both need a
     * front-desk decision, which this board exists to surface.
     */
    public function listOnCampus(int $limit = 200): array
    {
        $this->_assertReady();
        return $this->fs->schoolWhere(
            self::COL_PASSES,
            [['status', 'in', [self::STATUS_CHECKED_IN, self::STATUS_OVERSTAY, self::STATUS_PENDING_APPROVAL, self::STATUS_DENIED]]],
            'checkInAt', 'DESC', min($limit, self::LIST_SCAN_LIMIT)
        );
    }

    public function listPending(int $limit = 100): array
    {
        $this->_assertReady();
        return $this->fs->schoolWhere(
            self::COL_PASSES,
            [['status', '==', self::STATUS_PENDING_APPROVAL]],
            'requestedAt', 'ASC', min($limit, self::LIST_SCAN_LIMIT)
        );
    }

    /** @param array $filters ['status'=>?, 'purpose'=>?] */
    public function listLog(array $filters = [], int $limit = 200): array
    {
        $this->_assertReady();
        $conditions = [];
        if (!empty($filters['status']))  $conditions[] = ['status', '==', (string) $filters['status']];
        if (!empty($filters['purpose'])) $conditions[] = ['purpose', '==', (string) $filters['purpose']];
        return $this->fs->schoolWhere(self::COL_PASSES, $conditions, 'requestedAt', 'DESC', min($limit, self::LIST_SCAN_LIMIT));
    }

    // ══════════════════════════════════════════════════════════════════
    //  CRON SWEEPS (Visitor_cron controller)
    // ══════════════════════════════════════════════════════════════════

    /** Auto-approve pending, non-blocked passes idle past AUTO_APPROVE_MINUTES. */
    public function sweepAutoApprove(): array
    {
        $this->_assertReady();
        $cutoff = date('c', time() - self::AUTO_APPROVE_MINUTES * 60);
        $approved = [];
        foreach ($this->listPending(self::LIST_SCAN_LIMIT) as $row) {
            $d = (array) ($row['data'] ?? []);
            $id = (string) ($d['id'] ?? '');
            if ($id === '') continue;
            // Block-severity hits never auto-approve — override is a human decision.
            if (($d['watchlistSeverity'] ?? '') === self::SEVERITY_BLOCK) continue;
            $requestedAt = (string) ($d['requestedAt'] ?? '');
            if ($requestedAt === '' || $requestedAt > $cutoff) continue;
            try {
                $this->approvePass($id, true);
                $approved[] = $id;
            } catch (\Throwable $e) {
                log_message('error', "Visitor_service::sweepAutoApprove failed for {$id}: " . $e->getMessage());
            }
        }
        return $approved;
    }

    /** Flag on-campus visitors past their expected duration; notify Ops once each. */
    public function sweepOverstay(): array
    {
        $this->_assertReady();
        $flagged = [];
        foreach ($this->listOnCampus(self::LIST_SCAN_LIMIT) as $row) {
            $d = (array) ($row['data'] ?? []);
            if (($d['status'] ?? '') !== self::STATUS_CHECKED_IN) continue; // only fresh checked_in flips to overstay
            $id = (string) ($d['id'] ?? '');
            $checkInAt = (string) ($d['checkInAt'] ?? '');
            if ($id === '' || $checkInAt === '') continue;
            $expected = (int) ($d['expectedDurationMinutes'] ?? self::DEFAULT_DURATION_MINS);
            $checkInTs = strtotime($checkInAt);
            if ($checkInTs === false || (time() - $checkInTs) < $expected * 60) continue;

            $this->fs->updateEntity(self::COL_PASSES, $id, ['status' => self::STATUS_OVERSTAY]);
            if (empty($d['overstayNotifiedAt'])) {
                $this->fs->updateEntity(self::COL_PASSES, $id, ['overstayNotifiedAt' => date('c')]);
                $this->_fanoutOpsNotice(
                    'overstay', 'high', 'Visitor Overstay',
                    sprintf('%s has been on campus past the expected %d minutes.', $d['visitorNameSnapshot'] ?? 'A visitor', $expected),
                    ['passId' => $id]
                );
            }
            $flagged[] = $id;
        }
        return $flagged;
    }

    /**
     * Retry any visitPasses whose unified-ledger write-through failed
     * (campusEventSyncedIn/Out left false after createPass/approvePass/
     * overrideBlock/checkoutPass). Bounded recent-window scan, filtered in
     * PHP — same pattern as searchStaff/searchVisitors — rather than a new
     * composite index for what should be a rare failure path. Idempotent:
     * safe to call every cron tick even when there's nothing to fix.
     */
    public function reconcileCampusEvents(): array
    {
        $this->_assertReady();
        $fixed = [];
        $rows = $this->fs->schoolWhere(self::COL_PASSES, [], 'requestedAt', 'DESC', self::LIST_SCAN_LIMIT);
        foreach ($rows as $row) {
            $d = (array) ($row['data'] ?? []);
            $id = (string) ($d['id'] ?? '');
            if ($id === '') continue;
            $status = (string) ($d['status'] ?? '');
            $visitorId = (string) ($d['visitorId'] ?? '');
            $name = (string) ($d['visitorNameSnapshot'] ?? '');

            $needsIn = in_array($status, [self::STATUS_CHECKED_IN, self::STATUS_OVERSTAY, self::STATUS_CHECKED_OUT], true)
                && empty($d['campusEventSyncedIn']);
            if ($needsIn) {
                $this->_syncCampusAccessEvent($id, 'in', $visitorId, $name);
                $fixed[] = "{$id}:in";
            }
            $needsOut = $status === self::STATUS_CHECKED_OUT && empty($d['campusEventSyncedOut']);
            if ($needsOut) {
                $this->_syncCampusAccessEvent($id, 'out', $visitorId, $name);
                $fixed[] = "{$id}:out";
            }
        }
        return $fixed;
    }

    // ══════════════════════════════════════════════════════════════════
    //  UNIFIED CAMPUS ACCESS LEDGER (idempotent write-through, 2026-08-09)
    // ══════════════════════════════════════════════════════════════════

    /**
     * Best-effort, idempotent write-through into the shared campusAccessEvents
     * ledger (also written by Gate_pass_service for student/staff events).
     * Deterministic entity id -> retrying never duplicates. NEVER throws:
     * a failure here must not corrupt or roll back the already-successful
     * visitPasses write that triggered it — that write already happened and
     * stands on its own. On failure the corresponding campusEventSynced*
     * flag simply stays false, so reconcileCampusEvents() finds and retries
     * it later. No silent, permanent data loss; no coupling of the two
     * collections' success/failure.
     */
    private function _syncCampusAccessEvent(string $passId, string $direction, string $visitorId, string $visitorName): void
    {
        try {
            $entityId = 'V_' . $passId . '_' . $direction;
            $this->fs->setEntity('campusAccessEvents', $entityId, [
                'id' => $entityId, 'personType' => 'visitor', 'visitorId' => $visitorId,
                'personNameSnapshot' => $visitorName, 'direction' => $direction, 'gateId' => '',
                'timestamp' => date('c'), 'verificationMethod' => 'manual', 'cardTapId' => null,
                'authorizedBy' => $this->adminId, 'sourceType' => 'visitor_pass', 'linkedVisitPassId' => $passId,
            ], false);
            $flagField = $direction === 'out' ? 'campusEventSyncedOut' : 'campusEventSyncedIn';
            $this->fs->updateEntity(self::COL_PASSES, $passId, [$flagField => true]);
        } catch (\Throwable $e) {
            // Deliberately swallowed — see method doc. Left false for reconciliation.
            log_message('error', "Visitor_service::_syncCampusAccessEvent failed for {$passId}/{$direction}: " . $e->getMessage());
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  NOTIFICATIONS
    // ══════════════════════════════════════════════════════════════════

    private function _notifyHostCheckedIn(string $passId, string $hostStaffId, string $visitorName, string $purpose): void
    {
        $this->_emitPush('VISITOR_CHECKED_IN', 'vischeckin_' . $passId, [
            'staffId'  => $hostStaffId,
            'pushType' => 'visitor_checkin',
            'title'    => 'Visitor Arrived',
            'body'     => sprintf('%s has checked in — %s.', $visitorName, $purpose),
            'passId'   => $passId,
        ]);
    }

    /** Same wrapper shape as MY_Controller::emit_push() — this is a library, so it writes pushRequests directly. */
    private function _emitPush(string $mark, string $dedupeKey, array $fields): bool
    {
        try {
            $reqId = $this->fs->docId($dedupeKey);
            $payload = array_merge([
                'schoolId'  => $this->schoolId,
                'mark'      => $mark,
                'status'    => 'pending',
                'markedBy'  => $this->adminName,
                'createdAt' => date('c'),
            ], $fields);
            return (bool) $this->fs->set('pushRequests', $reqId, $payload, false);
        } catch (\Throwable $e) {
            log_message('error', 'Visitor_service::_emitPush failed: ' . $e->getMessage());
            return false;
        }
    }

    /** Ops/Staff console-bell notice — canonical Transport-style shape (B3 timestamp fields included). */
    private function _fanoutOpsNotice(string $category, string $priority, string $title, string $body, array $ctx): void
    {
        try {
            $now = date('c');
            $rawId = 'VIS_' . strtoupper($category) . '_' . date('YmdHis') . '_' . substr(md5(json_encode($ctx)), 0, 6);
            $notice = [
                'id' => $rawId, 'title' => $title, 'body' => $body, 'description' => $body,
                'author' => 'Visitor Management (System)', 'authorId' => $this->adminId, 'authorRole' => 'system',
                'category' => 'Visitor', 'priority' => $priority,
                'targetType' => 'role', 'targetClasses' => [], 'targetRoles' => self::OPS_ROLES,
                'status' => 'sent', 'sentAt' => $now, 'createdAt' => $now,
                'timestamp' => $now, 'timestampMs' => (int) round(microtime(true) * 1000),
                'module' => 'Visitor', 'vmsCategory' => $category, 'vmsContext' => $ctx,
            ];
            $this->fs->setEntity(self::COL_NOTICES, $rawId, $notice, false);
            foreach (self::OPS_ROLES as $role) {
                $recipientId = $rawId . '_' . preg_replace('/\s+/', '', $role);
                $this->fs->setEntity(self::COL_RECIPIENTS, $recipientId, [
                    'noticeId' => $rawId, 'recipientType' => 'role', 'recipientKey' => $role,
                    'read' => false, 'createdAt' => $now,
                ], false);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Visitor_service::_fanoutOpsNotice failed: ' . $e->getMessage());
        }
    }
}
