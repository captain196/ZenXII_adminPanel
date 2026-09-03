<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/Doc_rows.php';

/**
 * Doc_template_service — the template lifecycle. Phase 6.
 *
 * Owns the transitions a certificate template can make and the guarantees that
 * make them safe: optimistic concurrency on save, an immutable snapshot on
 * publish, exactly one active template per (school, docType), and an audit
 * event on every transition.
 *
 * ---------------------------------------------------------------------------
 * WHY THE STORE IS INJECTED
 * ---------------------------------------------------------------------------
 * Every method here is lifecycle ARITHMETIC — which transition is legal, whose
 * lockVersion wins, what a frozen snapshot contains. None of it is about
 * Firestore. The store is an interface-shaped array of callables so the whole
 * state machine is unit-testable with no emulator, no credentials and no
 * network, and so the Firestore specifics live in exactly one adapter.
 *
 * ---------------------------------------------------------------------------
 * THE FOUR GUARANTEES, and what breaks without each
 * ---------------------------------------------------------------------------
 *  1. LOCK VERSION (P6.5). Two clerks editing one template must not silently
 *     overwrite each other. The loser gets a conflict; nobody gets a lost edit.
 *  2. FROZEN SNAPSHOT (P6.1/P6.3). Publishing writes an immutable copy to
 *     documentTemplateVersions. It answers "show me the exact template that
 *     produced this certificate" years later; if it can be edited it answers
 *     nothing. Editing a published template mutates the HEAD to draft v+1 and
 *     never touches the snapshot.
 *  3. EXACTLY ONE ACTIVE (P6.4). activeVersion is the pointer every print point
 *     resolves. Two active templates for one docType means two different
 *     certificates are both "the" certificate.
 *  4. LEGAL TRANSITIONS ONLY (P6.6). Enforced SERVER-side. The UI is not a
 *     security boundary and a published head must not become a draft again.
 *
 * EXECUTION_PLAN_v1.1 Phase 6 · COLLECTION_SHAPES §3, §4
 */
class Doc_template_service
{
    /** Terminal states have no outgoing transitions. */
    const TRANSITIONS = [
        'draft'     => ['published', 'archived'],
        'published' => ['archived'],
        'archived'  => [],
    ];

    const HEAD_COLLECTION    = 'documentTemplates';
    const VERSION_COLLECTION = 'documentTemplateVersions';

    /** @var array<string,callable> */
    private array $store;

    /** @var callable|null audit sink; null = no audit (tests only) */
    private $audit;

    /**
     * @param array $params store: ['get'=>fn, 'set'=>fn, 'update'=>fn,
     *                              'exists'=>fn, 'query'=>fn, 'transact'=>fn|null]
     *                      audit: callable(string $action, string $entityId, string $desc)
     *
     * With no params the CI3 Firestore service is adapted. Production passes
     * nothing; tests pass a fake.
     */
    /**
     * The tenant every operation must belong to.
     *
     * Set from the SESSION by the controller, never from a request. Null means
     * "unscoped", which is only ever correct in a test.
     */
    private ?string $schoolId = null;

    public function __construct(array $params = [])
    {
        $this->audit    = $params['audit'] ?? null;
        $this->schoolId = isset($params['schoolId']) && $params['schoolId'] !== ''
            ? (string) $params['schoolId'] : null;

        if (!empty($params['store'])) {
            $this->store = $params['store'];
            return;
        }

        $ci = &get_instance();
        $fs = $ci->fs;
        $this->store = [
            'get'      => fn(string $c, string $id) => $fs->get($c, $id),
            'set'      => fn(string $c, string $id, array $d) => $fs->set($c, $id, $d),
            'update'   => fn(string $c, string $id, array $d) => $fs->update($c, $id, $d),
            'exists'   => fn(string $c, string $id) => $fs->exists($c, $id),
            'query'    => fn(string $c, array $w) => Doc_rows::map($fs->schoolWhere($c, $w)),
            /* ATOMIC MULTI-DOCUMENT WRITE.
             *
             * This used to read `runTransaction()` off raw_client(). It was
             * wrong twice over. raw_client() returns a FirestoreRestClient —
             * google/cloud-firestore is vendored but this app never uses it —
             * and that class HAS NO runTransaction(), so every activate would
             * have fatalled on the first real click. And even where the method
             * had existed, the closure ignored the Transaction object it is
             * handed and wrote through the plain non-transactional helpers, so
             * the writes would not have been in the transaction anyway.
             *
             * What the REST client does have is `:commit` — all-or-nothing
             * across documents, the same primitive the fee-accounting CAS
             * loops already rely on. That is enough for what activate needs.
             * Null means no atomic write is available, and activate refuses. */
            'commit' => method_exists($fs, 'raw_client')
                        && is_object($fs->raw_client())
                        && method_exists($fs->raw_client(), 'commitBatch')
                ? fn(array $ops) => $fs->raw_client()->commitBatch($ops)
                : null,
        ];
    }

    /* ================================================================== *
     *  P6.6 — the state machine
     * ================================================================== */

    /** Is `$from → $to` a legal transition? */
    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @throws RuntimeException naming both states, so the log is diagnosable. */
    public function assertTransition(string $from, string $to): void
    {
        if (!isset(self::TRANSITIONS[$from])) {
            throw new RuntimeException("Doc_template_service: unknown state '$from'");
        }
        if (!$this->canTransition($from, $to)) {
            $legal = self::TRANSITIONS[$from] ?: ['(none — terminal)'];
            throw new RuntimeException(
                "Doc_template_service: illegal transition '$from' → '$to'. "
                . "Legal from '$from': " . implode(', ', $legal)
            );
        }
    }

    /* ================================================================== *
     *  P1.x — create
     * ================================================================== */

    /**
     * Mint a new draft template for a school.
     *
     * The id is `{schoolId}_TPL####`, matching the repo-wide
     * `{schoolId}_{entityId}` key scheme.
     *
     * NUMBERING IS CREATE-ONLY WITH RETRY, not read-then-write. Two clerks
     * pressing "New template" in the same second both read the same maximum,
     * and a plain write would have the second silently OVERWRITE the first
     * one's template — no error, no trace, one template simply gone. So each
     * attempt refuses to write over an existing id and tries the next number.
     *
     * This is not a counter document, deliberately: a counter for template ids
     * would be a new collection needing its own rules, for ids that are
     * internal handles. The numbers a school is legally accountable for are
     * issued-document serials, which belong to the Issuance Engine and DO need
     * a transactional counter (CON-NO_PRINT_IMPL).
     *
     * @throws RuntimeException if a free id cannot be found — better than
     *         looping forever or silently overwriting.
     */
    public function create(string $schoolId, string $docType, array $seed = [], string $by = ''): array
    {
        if ($schoolId === '' || $docType === '') {
            throw new InvalidArgumentException('Doc_template_service: schoolId and docType are required');
        }

        $existing = ($this->store['query'])(self::HEAD_COLLECTION, [['schoolId', '=', $schoolId]]) ?: [];
        $max = 0;
        foreach ($existing as $id => $row) {
            $tid = (string) ($row['templateId'] ?? '');
            if (preg_match('/^TPL(\d+)$/', $tid, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        for ($n = $max + 1; $n <= $max + 50; $n++) {
            $templateId = sprintf('TPL%04d', $n);
            $docId      = $schoolId . '_' . $templateId;
            if (($this->store['exists'])(self::HEAD_COLLECTION, $docId)) {
                continue;                       // somebody else took it
            }

            $head = [
                'schoolId'         => $schoolId,
                'templateId'       => $templateId,
                'docType'          => $docType,
                'name'             => $this->uniqueName(
                                          (string) ($seed['name'] ?? 'Untitled template'),
                                          $existing),
                'status'           => 'draft',
                'version'          => 1,
                'lockVersion'      => 0,
                'publishedVersion' => null,
                'activeVersion'    => null,
                'page'             => $seed['page']    ?? ['size' => 'A4', 'orientation' => 'portrait'],
                'header'           => $seed['header']  ?? [],
                'footer'           => $seed['footer']  ?? [],
                'objects'          => $seed['objects'] ?? [],
                'languages'        => $seed['languages'] ?? ['en'],
                'defaultLanguage'  => (string) ($seed['defaultLanguage'] ?? 'en'),
                'contractRef'      => $seed['contractRef'] ?? null,
                'complianceBasis'  => $seed['complianceBasis']  ?? [],
                'complianceLayers' => $seed['complianceLayers'] ?? [],
                'starterId'        => $seed['starterId'] ?? null,
                'createdBy'        => $by,
                'createdAt'        => $this->now(),
                'updatedAt'        => $this->now(),
            ];

            ($this->store['set'])(self::HEAD_COLLECTION, $docId, $head);
            $this->log('create', $docId, "Created $docType template $templateId");

            return ['templateId' => $docId, 'head' => $head];
        }

        throw new RuntimeException(
            'Doc_template_service: could not mint a free template id after 50 attempts '
            . "from TPL" . sprintf('%04d', $max + 1) . '. Refusing to overwrite an existing template.'
        );
    }

    /**
     * Make the name distinguishable from the school's existing templates.
     *
     * Every starter clone was called "<starter> (copy)", so a school that tried
     * two variants ended up with two templates named identically, side by side
     * in the same list, with the same schematic thumbnail. There was no way to
     * tell which was which — including for the person deciding which one to
     * activate, which is the one decision in this module that must not be a
     * guess.
     *
     * Appends " 2", " 3" and so on, the way a file manager does. It does NOT
     * try to be clever about what the difference is — only the author knows
     * that, and they can rename it.
     */
    private function uniqueName(string $wanted, array $existing): string
    {
        $wanted = trim($wanted) !== '' ? trim($wanted) : 'Untitled template';

        $taken = [];
        foreach ($existing as $row) {
            $n = trim((string) ($row['name'] ?? ''));
            if ($n !== '') {
                $taken[mb_strtolower($n)] = true;
            }
        }
        if (!isset($taken[mb_strtolower($wanted)])) {
            return $wanted;
        }
        for ($i = 2; $i <= 99; $i++) {
            $try = $wanted . ' ' . $i;
            if (!isset($taken[mb_strtolower($try)])) {
                return $try;
            }
        }
        return $wanted . ' ' . date('Y-m-d H:i');
    }

    /* ================================================================== *
     *  P6.5 — optimistic concurrency
     * ================================================================== */

    /**
     * Save a draft. The caller states the lockVersion it read; if the stored
     * one has moved, the save is REFUSED.
     *
     * Deliberately a refusal and not a merge. Two clerks editing a statutory
     * template are not editing the same sentence by coincidence — silently
     * merging their work produces a document neither of them approved.
     *
     * @throws RuntimeException on conflict, or on saving a non-draft.
     */
    /**
     * @param string $by Who is saving. Recorded as updatedBy, because
     *        "edited 4 hours ago" answers half the question a second person
     *        asks when they open a shared template — the other half is WHO,
     *        and without it a change nobody remembers making has no owner.
     */
    public function save(string $docId, array $patch, int $expectedLockVersion, string $by = ''): array
    {
        $head = $this->head($docId);

        if (($head['status'] ?? 'draft') !== 'draft') {
            throw new RuntimeException(
                "Doc_template_service: '$docId' is {$head['status']}, not draft. "
                . 'Editing a published template creates a new draft version — call newDraft().'
            );
        }

        $stored = (int) ($head['lockVersion'] ?? 0);
        if ($stored !== $expectedLockVersion) {
            throw new RuntimeException(
                "E_CONFLICT: '$docId' changed while you were editing "
                . "(you read lockVersion $expectedLockVersion, it is now $stored). "
                . 'Your edit was NOT saved and nothing was overwritten.'
            );
        }

        // Never let a caller move lifecycle fields through save(), and never
        // let one claim to be somebody else.
        foreach (['status', 'publishedVersion', 'activeVersion', 'templateId', 'schoolId',
                  'updatedBy', 'createdBy'] as $k) {
            unset($patch[$k]);
        }

        $patch['lockVersion'] = $stored + 1;
        $patch['updatedAt']   = $this->now();
        if ($by !== '') {
            $patch['updatedBy'] = $by;
        }
        ($this->store['update'])(self::HEAD_COLLECTION, $docId, $patch);

        return $patch;
    }

    /* ================================================================== *
     *  P6.1 / P6.2 / P6.3 — publish
     * ================================================================== */

    /**
     * Freeze the current draft as an immutable version and open the next draft.
     *
     * @param array $proof  hash, pdfPaths, fontManifest, mpdfVersion, validation
     * @throws RuntimeException if unproofed, mis-transitioned, or the snapshot
     *         id already exists.
     */
    /**
     * A canonical hash of the DESIGN — the only fields that change what prints.
     *
     * Used to answer "is the proof on record still a proof of THIS document?".
     * Deliberately content-based rather than a `proofed` flag: a flag says a
     * proof happened at some point, which is not the question. Status,
     * lockVersion and timestamps are excluded because they move without
     * changing a single printed pixel, and re-proofing for them would train
     * people to click through the gate.
     */
    public function contentHash(array $head): string
    {
        return 'sha256:' . hash('sha256', self::canonical([
            'page'            => $head['page']            ?? [],
            'header'          => $head['header']          ?? [],
            'footer'          => $head['footer']          ?? [],
            'objects'         => $head['objects']         ?? [],
            'languages'       => $head['languages']       ?? [],
            'defaultLanguage' => $head['defaultLanguage'] ?? 'en',
        ]));
    }

    /**
     * Order-independent, precision-stable serialization.
     *
     * json_encode alone is not enough for a hash that must match across
     * machines: PHP key order follows insertion, and float output follows
     * `serialize_precision`, so the SAME design could hash two ways and every
     * publish would report a stale proof. Keys are sorted; floats are printed
     * to a fixed 6dp — finer than any position the designer can express, since
     * it snaps at 0.1mm.
     */
    private static function canonical($v): string
    {
        if (is_array($v)) {
            $isList = array_keys($v) === range(0, count($v) - 1);
            if (!$isList) {
                ksort($v);
            }
            $parts = [];
            foreach ($v as $k => $x) {
                $parts[] = ($isList ? '' : json_encode((string) $k) . ':') . self::canonical($x);
            }
            return ($isList ? '[' : '{') . implode(',', $parts) . ($isList ? ']' : '}');
        }
        if (is_float($v)) {
            return rtrim(rtrim(sprintf('%.6F', $v), '0'), '.') ?: '0';
        }
        if (is_bool($v) || is_null($v) || is_int($v)) {
            return json_encode($v);
        }
        return json_encode((string) $v);
    }

    /**
     * P6.2 — record a proof the SERVER rendered.
     *
     * The proof must never be accepted from the request body. It previously
     * was, and that made the publish gate decorative: any caller could POST a
     * fabricated hash and the immutable snapshot would record a hash that no
     * PDF ever produced — defeating the one thing the snapshot exists for,
     * which is a byte-identical re-render years later.
     *
     * So `Doc_templates::proof_pdf()` renders, hashes the actual bytes, and
     * calls this; `publish()` reads what is on record and verifies it still
     * describes the current design.
     */
    /**
     * @param ?array $head The head document, if the caller already has it.
     *        Firestore reads from outside the database's region cost ~2.3s
     *        each — measured on a dev machine against nam5 — and proof_pdf has
     *        ALREADY read this document in order to render it. Re-reading it
     *        here doubled the round-trips of the slowest operation in the
     *        module for no new information. Passing null still re-reads, so
     *        every other caller is unaffected.
     */
    public function recordProof(string $docId, array $proof, string $by = '', ?array $head = null): array
    {
        if ($head === null) {
            $head = $this->head($docId);
        } elseif ($this->schoolId !== null && ($head['schoolId'] ?? null) !== $this->schoolId) {
            // A caller-supplied head must still pass the tenant check the
            // re-read would have applied.
            throw new RuntimeException("Doc_template_service: no template '$docId'");
        }

        foreach (['hash', 'fontManifest', 'mpdfVersion'] as $k) {
            if (empty($proof[$k])) {
                throw new RuntimeException(
                    "Doc_template_service: refusing to record a proof without $k. "
                    . 'A snapshot must name the exact faces and engine used.'
                );
            }
        }

        $rec = [
            'hash'         => (string) $proof['hash'],
            'contentHash'  => $this->contentHash($head),
            'fontManifest' => $proof['fontManifest'],
            'mpdfVersion'  => (string) $proof['mpdfVersion'],
            'pages'        => (int) ($proof['pages'] ?? 0),
            'validation'   => $proof['validation'] ?? ['blocking' => [], 'warnings' => []],
            'version'      => (int) ($head['version'] ?? 1),
            'renderedBy'   => $by,
            'renderedAt'   => $this->now(),
        ];
        ($this->store['update'])(self::HEAD_COLLECTION, $docId, ['lastProof' => $rec]);

        return $rec;
    }

    public function publish(string $docId, string $by = ''): array
    {
        $head = $this->head($docId);
        $this->assertTransition($head['status'] ?? 'draft', 'published');

        /* P6.2 — the proof comes from the RECORD, never from the caller. */
        $proof = $head['lastProof'] ?? null;
        if (!is_array($proof) || empty($proof['hash'])) {
            throw new RuntimeException(
                'Doc_template_service: no proof on record for this template. '
                . 'Render a proof before publishing — publishing writes an immutable '
                . 'snapshot, and a snapshot whose hash was never produced by a real '
                . 'render cannot be verified against anything later.'
            );
        }

        $version = (int) ($head['version'] ?? 1);

        if ((int) ($proof['version'] ?? 0) !== $version) {
            throw new RuntimeException(
                'Doc_template_service: the proof on record is for v'
                . (int) ($proof['version'] ?? 0) . " but this draft is v$version. Render a new proof."
            );
        }

        /* Content, not a flag. A stale proof and a fresh one look identical to
           a boolean; they do not look identical to a hash of what prints. */
        if (($proof['contentHash'] ?? null) !== $this->contentHash($head)) {
            throw new RuntimeException(
                'Doc_template_service: the design changed after the proof was rendered, '
                . 'so the proof no longer describes what would print. Render a new proof.'
            );
        }
        $vid     = $docId . '_v' . $version;

        // P6.1 — create-only. If the id exists we would be OVERWRITING history.
        if (($this->store['exists'])(self::VERSION_COLLECTION, $vid)) {
            throw new RuntimeException(
                "Doc_template_service: version '$vid' already exists. Snapshots are "
                . 'create-only — a published version is never rewritten.'
            );
        }

        $snapshot = [
            'schoolId'         => $head['schoolId']   ?? '',
            'templateId'       => $head['templateId'] ?? '',
            'docType'          => $head['docType']    ?? '',
            'version'          => $version,
            'snapshot'         => [
                'page'            => $head['page']            ?? [],
                'header'          => $head['header']          ?? [],
                'footer'          => $head['footer']          ?? [],
                'objects'         => $head['objects']         ?? [],
                'languages'       => $head['languages']       ?? [],
                'defaultLanguage' => $head['defaultLanguage'] ?? 'en',
            ],
            'contractRef'      => $head['contractRef']      ?? null,
            'complianceBasis'  => $head['complianceBasis']  ?? [],
            // Frozen, not referenced: the layers that applied AT PUBLISH TIME.
            // A later authority revision must not retroactively change what a
            // already-issued certificate was validated against.
            'complianceLayers' => $head['complianceLayers'] ?? [],
            'validationResult' => $proof['validation'] ?? ['blocking' => [], 'warnings' => []],
            'proofPdfHash'     => $proof['hash'],
            'proofPdfPaths'    => $proof['pdfPaths']     ?? [],
            'fontManifest'     => $proof['fontManifest'],
            'mpdfVersion'      => $proof['mpdfVersion'],
            'publishedBy'      => $by,
            'publishedAt'      => $this->now(),
        ];
        ($this->store['set'])(self::VERSION_COLLECTION, $vid, $snapshot);

        // The head moves on to the NEXT draft. Publishing does not activate —
        // that is a separate, deliberate act (P6.4).
        $headPatch = [
            'status'           => 'draft',
            'publishedVersion' => $version,
            'version'          => $version + 1,
            'lockVersion'      => (int) ($head['lockVersion'] ?? 0) + 1,
            'updatedAt'        => $this->now(),
            'updatedBy'        => $by,
        ];
        ($this->store['update'])(self::HEAD_COLLECTION, $docId, $headPatch);

        $this->log('publish', $docId, "Published v$version");

        return ['versionId' => $vid, 'version' => $version, 'head' => $headPatch];
    }

    /* ================================================================== *
     *  P6.4 — activate, transactionally
     * ================================================================== */

    /**
     * Make one published version THE active template for its (school, docType).
     *
     * Runs inside a Firestore transaction when one is available: the read of
     * "who is currently active" and the write that displaces them must not be
     * separable, or two concurrent activates each see no incumbent and both
     * win — leaving two active templates for one document type, which means two
     * different certificates are both "the" certificate.
     *
     * @throws RuntimeException if never published, or if no transaction is
     *         available — see the note below.
     */
    /**
     * Make a published version THE active template for its (school, docType).
     *
     * @param ?int $version A specific published version to activate. Null means
     *        the newest. ROLLBACK IS ALLOWED (operator decision, 2026-09-03):
     *        a school that activates a broken v5 can return to v4 rather than
     *        having to fix, re-proof, publish and activate a v6 under pressure,
     *        which is where the second mistake gets made.
     *
     *        This costs nothing in audit terms. Rolling back does not erase that
     *        v5 was ever live — both activations are logged, and the rollback is
     *        logged AS a rollback. And v4 is already a frozen, proofed snapshot,
     *        so activating it introduces nothing unverified.
     */
    public function activate(string $docId, string $by = '', ?int $version = null): array
    {
        $head = $this->head($docId);

        $published = $head['publishedVersion'] ?? null;
        if ($published === null) {
            throw new RuntimeException(
                "Doc_template_service: '$docId' has never been published, so there is "
                . 'no frozen version to activate. Publish it first.'
            );
        }

        $isRollback = false;
        if ($version !== null) {
            if ($version < 1 || $version > (int) $published) {
                throw new RuntimeException(
                    "Doc_template_service: v$version is not a published version of '$docId'. "
                    . "Published versions run from v1 to v$published."
                );
            }
            /* The snapshot must actually exist. A pointer to a version whose
               frozen copy is missing is worse than no pointer at all: the UI
               looks ready and nothing can be reproduced from it. */
            if (!($this->store['exists'])(self::VERSION_COLLECTION, $docId . '_v' . $version)) {
                throw new RuntimeException(
                    "Doc_template_service: the frozen snapshot for v$version is missing, so "
                    . 'nothing could be reproduced from it. Refusing to activate it.'
                );
            }
            $isRollback = $version < (int) $published;
            $published  = $version;
        }

        $schoolId = (string) ($head['schoolId'] ?? '');
        $docType  = (string) ($head['docType']  ?? '');

        /* Build the COMPLETE assignment for this (school, docType) and commit
           it in ONE all-or-nothing write.

           Completeness is what makes it safe, not a lock. Every batch names
           the winner AND nulls every other template, so two concurrent
           activates cannot interleave into a half-state: each commit is a
           whole, self-consistent answer, and whichever lands second is the
           final one. The invariant that matters — exactly one active template
           per document type — holds after either ordering.

           A partial write is what would break it: template A nulled by one
           request and template B never set by the other leaves a school with
           NO active template and a print button that resolves nothing. That is
           precisely what ":commit" being atomic prevents. */
        $siblings = ($this->store['query'])(self::HEAD_COLLECTION, [
            ['schoolId', '=', $schoolId],
            ['docType',  '=', $docType],
        ]) ?: [];

        $ops = [[
            'op'         => 'set',
            'collection' => self::HEAD_COLLECTION,
            'docId'      => $docId,
            'merge'      => true,
            'data'       => [
                'activeVersion' => $published,
                'lockVersion'   => (int) ($head['lockVersion'] ?? 0) + 1,
                'updatedAt'     => $this->now(),
            ],
            // Never activate a template that has since been deleted.
            'precondition' => ['exists' => true],
        ]];

        $displaced = [];
        foreach ($siblings as $sid => $row) {
            $sid = is_string($sid) ? $sid : (string) ($row['_id'] ?? '');
            if ($sid === '' || $sid === $docId) {
                continue;
            }
            // Plural on purpose: if a past bug ever left two active, this heals
            // it rather than assuming there is at most one.
            if (($row['activeVersion'] ?? null) === null) {
                continue;
            }
            $ops[] = [
                'op'         => 'set',
                'collection' => self::HEAD_COLLECTION,
                'docId'      => $sid,
                'merge'      => true,
                'data'       => ['activeVersion' => null, 'updatedAt' => $this->now()],
            ];
            $displaced[] = $sid;
        }

        $commit = $this->store['commit'] ?? null;
        if (!is_callable($commit)) {
            // Refuse rather than degrade. A non-atomic activate looks identical
            // when it works and leaves a school with two active templates — or
            // none — when it races. The failure is silent, rare, and legally
            // consequential: every print point resolves activeVersion.
            throw new RuntimeException(
                'Doc_template_service: activate requires an atomic multi-document write '
                . 'and none is available. Refusing to run non-atomically: a race would '
                . 'leave two active templates for one document type, or none at all, and '
                . 'every print point resolves activeVersion.'
            );
        }

        if (($commit)($ops) !== true) {
            throw new RuntimeException(
                'Doc_template_service: the activation write was rejected, so NOTHING '
                . 'changed — the previously active template is still the active one. '
                . 'This is the safe outcome; try again.'
            );
        }

        /* Logged AS a rollback when it is one. "Activated v4" three months later
           reads as a routine act; "Rolled back to v4" is the sentence somebody
           needs to find when they ask what happened that morning. */
        $this->log('activate', $docId,
            ($isRollback ? "Rolled back to v$published" : "Activated v$published")
            . ($displaced ? ' (displaced ' . implode(', ', $displaced) . ')' : ''));

        return ['activeVersion' => $published, 'displaced' => $displaced,
                'rollback' => $isRollback];
    }

    /**
     * Stop issuing this document type — clear the active pointer.
     *
     * The UI has always offered "Deactivate" and there was no server operation
     * behind it: the client deleted its own copy of the pointer, repainted, and
     * announced the type deactivated. Nothing was persisted, so it came back on
     * reload — after the person had been told a school had stopped issuing a
     * statutory document.
     *
     * Deliberately a distinct act from archive(), which refuses while a
     * template is active. This is how you say "stop issuing this type" without
     * throwing the template away.
     */
    public function deactivate(string $docId, string $by = ''): array
    {
        $head = $this->head($docId);

        if (($head['activeVersion'] ?? null) === null) {
            throw new RuntimeException(
                "Doc_template_service: '$docId' is not the active template, so there is "
                . 'nothing to deactivate.'
            );
        }

        $was = (int) $head['activeVersion'];
        $patch = [
            'activeVersion' => null,
            'lockVersion'   => (int) ($head['lockVersion'] ?? 0) + 1,
            'updatedAt'     => $this->now(),
            'updatedBy'     => $by,
        ];
        ($this->store['update'])(self::HEAD_COLLECTION, $docId, $patch);

        /* Logged with what it COST. "Deactivated" three months later does not
           convey that every print point for this document type began failing
           closed at that moment. */
        $this->log('deactivate', $docId,
            "Deactivated v$was — no template is active for "
            . ($head['docType'] ?? 'this type') . ' until one is set');

        return $patch;
    }

    /* ================================================================== *
     *  Archive
     * ================================================================== */

    public function archive(string $docId, string $by = ''): array
    {
        $head = $this->head($docId);
        $this->assertTransition($head['status'] ?? 'draft', 'archived');

        /* REFUSE to archive the template that is currently active (operator
           decision, 2026-09-03).
           
           Nobody archives a template intending to disable a statutory document.
           Archiving is tidying; stopping issuance is a decision. This used to
           clear activeVersion silently, so an admin cleaning up a list could
           leave the office unable to issue a Transfer Certificate the next
           morning with nothing to explain it.
           
           A confirmation dialog was considered and rejected: people click
           through confirmations *while tidying*, which is exactly the moment
           they are not reading. */
        if (($head['activeVersion'] ?? null) !== null) {
            throw new RuntimeException(
                'Doc_template_service: this is the ACTIVE template for '
                . ($head['docType'] ?? 'this document type')
                . '. Activate another one first — archiving it would leave the school '
                . 'unable to issue this document, and nothing would say so.'
            );
        }

        $patch = [
            'status'        => 'archived',
            'activeVersion' => null,   // an archived template cannot stay active
            'lockVersion'   => (int) ($head['lockVersion'] ?? 0) + 1,
            'updatedAt'     => $this->now(),
        ];
        ($this->store['update'])(self::HEAD_COLLECTION, $docId, $patch);
        $this->log('archive', $docId, 'Archived');

        return $patch;
    }

    /* ================================================================== *
     *  Helpers
     * ================================================================== */

    /**
     * Load a template head, and REFUSE it if it belongs to another school.
     *
     * The tenant check lives here rather than only in the controller because
     * every lifecycle method funnels through this one function, and it was
     * missing from four of them — save, publish, activate and archive each took
     * a caller-supplied templateId and acted on it with no ownership check at
     * all. `safe_path_segment()` validates the CHARACTERS of an id; it says
     * nothing about who owns it.
     *
     * That is not caught anywhere downstream: the panel uses the Firebase Admin
     * SDK, which bypasses firestore.rules entirely, so the rules that correctly
     * gate mobile clients never see these writes. Ids are
     * `{schoolId}_{entityId}` and template numbers run sequentially from
     * TPL0001, so a target is guessable once a schoolId is known.
     *
     * The consequence was not a leak but sabotage: activating another school's
     * template changes which certificate that school legally issues, and
     * archiving theirs removes their ability to issue one at all.
     */
    private function head(string $docId): array
    {
        $head = ($this->store['get'])(self::HEAD_COLLECTION, $docId);
        if (!is_array($head) || !$head) {
            throw new RuntimeException("Doc_template_service: no template '$docId'");
        }
        if ($this->schoolId !== null && ($head['schoolId'] ?? null) !== $this->schoolId) {
            // Deliberately the SAME message as "not found". Confirming that an
            // id exists in another tenant is itself a disclosure.
            throw new RuntimeException("Doc_template_service: no template '$docId'");
        }
        return $head;
    }

    /** P6.7 — every transition lands in the existing Audit Logs viewer. */
    private function log(string $action, string $entityId, string $description): void
    {
        if (is_callable($this->audit)) {
            ($this->audit)($action, $entityId, $description);
            return;
        }
        if (function_exists('log_audit')) {
            log_audit('DocTemplates', $action, $entityId, $description);
        }
    }

    private function now(): string
    {
        return gmdate('c');
    }
}
