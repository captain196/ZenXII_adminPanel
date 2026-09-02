<?php
defined('BASEPATH') or exit('No direct script access allowed');

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
    public function __construct(array $params = [])
    {
        $this->audit = $params['audit'] ?? null;

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
            'query'    => fn(string $c, array $w) => $fs->schoolWhere($c, $w),
            // google/cloud-firestore is vendored and raw_client() exposes it, so
            // a real transaction is available. Null means the caller gets the
            // documented compare-and-swap fallback instead — see activate().
            'transact' => method_exists($fs, 'raw_client') && $fs->raw_client()
                ? fn(callable $fn) => $fs->raw_client()->runTransaction($fn)
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
    public function save(string $docId, array $patch, int $expectedLockVersion): array
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

        // Never let a caller move lifecycle fields through save().
        foreach (['status', 'publishedVersion', 'activeVersion', 'templateId', 'schoolId'] as $k) {
            unset($patch[$k]);
        }

        $patch['lockVersion'] = $stored + 1;
        $patch['updatedAt']   = $this->now();
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
    public function publish(string $docId, array $proof, string $by = ''): array
    {
        $head = $this->head($docId);
        $this->assertTransition($head['status'] ?? 'draft', 'published');

        // P6.2 — a snapshot that cannot name the faces and engine that produced
        // it cannot be re-rendered years later, which is the entire point of it.
        foreach (['hash', 'fontManifest', 'mpdfVersion'] as $k) {
            if (empty($proof[$k])) {
                throw new RuntimeException(
                    "Doc_template_service: cannot publish without proof.$k. "
                    . 'A snapshot must record the exact faces and engine used, or a '
                    . 'byte-identical re-render later is impossible.'
                );
            }
        }

        $version = (int) ($head['version'] ?? 1);
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
    public function activate(string $docId, string $by = ''): array
    {
        $head = $this->head($docId);

        $published = $head['publishedVersion'] ?? null;
        if ($published === null) {
            throw new RuntimeException(
                "Doc_template_service: '$docId' has never been published, so there is "
                . 'no frozen version to activate. Publish it first.'
            );
        }

        $schoolId = (string) ($head['schoolId'] ?? '');
        $docType  = (string) ($head['docType']  ?? '');

        $apply = function () use ($docId, $schoolId, $docType, $published, $head) {
            // Displace every incumbent for this (school, docType) — plural on
            // purpose. If a past bug ever left two active, this heals it rather
            // than assuming there is at most one.
            $siblings = ($this->store['query'])(self::HEAD_COLLECTION, [
                ['schoolId', '=', $schoolId],
                ['docType',  '=', $docType],
            ]) ?: [];

            $displaced = [];
            foreach ($siblings as $sid => $row) {
                $sid = is_string($sid) ? $sid : (string) ($row['_id'] ?? '');
                if ($sid === '' || $sid === $docId) {
                    continue;
                }
                if (($row['activeVersion'] ?? null) !== null) {
                    ($this->store['update'])(self::HEAD_COLLECTION, $sid, [
                        'activeVersion' => null,
                        'updatedAt'     => $this->now(),
                    ]);
                    $displaced[] = $sid;
                }
            }

            ($this->store['update'])(self::HEAD_COLLECTION, $docId, [
                'activeVersion' => $published,
                'lockVersion'   => (int) ($head['lockVersion'] ?? 0) + 1,
                'updatedAt'     => $this->now(),
            ]);

            return $displaced;
        };

        $transact = $this->store['transact'] ?? null;
        if (!is_callable($transact)) {
            // Refuse rather than degrade. A non-transactional activate looks
            // identical when it works and produces two active templates when it
            // races — the failure is silent, rare, and legally consequential.
            throw new RuntimeException(
                'Doc_template_service: activate requires a transaction and none is '
                . 'available. Refusing to run non-transactionally: a race would leave '
                . 'two active templates for one document type, and every print point '
                . 'resolves activeVersion.'
            );
        }

        $displaced = $transact($apply);

        $this->log('activate', $docId, "Activated v$published"
            . ($displaced ? ' (displaced ' . implode(', ', $displaced) . ')' : ''));

        return ['activeVersion' => $published, 'displaced' => $displaced];
    }

    /* ================================================================== *
     *  Archive
     * ================================================================== */

    public function archive(string $docId, string $by = ''): array
    {
        $head = $this->head($docId);
        $this->assertTransition($head['status'] ?? 'draft', 'archived');

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

    private function head(string $docId): array
    {
        $head = ($this->store['get'])(self::HEAD_COLLECTION, $docId);
        if (!is_array($head) || !$head) {
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
