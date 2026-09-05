<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/Doc_rows.php';
require_once __DIR__ . '/Doc_contract.php';   // isCustom() — the custom-type shape

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
            /* Firestore_service exposes no delete; the REST client underneath
               does. Null when neither is available, and delete() then refuses
               rather than reporting a removal that did not happen. */
            'delete'   => method_exists($fs, 'delete')
                ? fn(string $c, string $id) => $fs->delete($c, $id)
                : (method_exists($fs, 'raw_client') && is_object($fs->raw_client())
                   && method_exists($fs->raw_client(), 'deleteDocument')
                    ? fn(string $c, string $id) => $fs->raw_client()->deleteDocument($c, $id)
                    : null),
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
        /* A custom type MUST arrive with the title it was minted from.
           Without it the only name left is the slug, and the hub would list the
           document as "custom:sports_day" — which is not what anybody typed and
           cannot be corrected afterwards, because the title is the only record
           of the capitalisation and punctuation the school chose. */
        if (Doc_contract::isCustom($docType) && trim((string) ($seed['docTitle'] ?? '')) === '') {
            throw new InvalidArgumentException(
                "Doc_template_service: '$docType' is a custom document type and must be created "
                . 'with a docTitle — the slug alone cannot reproduce the name that was typed.'
            );
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
                /* The human name of a CUSTOM document type.
                   `name` is the template's name ("Draft 2", "2026 wording");
                   docTitle is what KIND of document it is, and for a custom type
                   nothing else records that — the slug in docType is derived and
                   lossy ("custom:sports_day" cannot tell you it was typed
                   "Sports Day"). Empty for a built-in type, whose name is in the
                   catalogue. */
                'docTitle'         => (string) ($seed['docTitle'] ?? ''),
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

            /* CREATE-ONLY AT THE DATABASE, not at the exists() check above.
             *
             * The check and the write were two separate calls with nothing
             * between them, so two concurrent creates that both read the same
             * $max, and both found TPL0086 free, would BOTH write it — the
             * second silently overwriting the first school's brand-new template,
             * with no error to either caller. The doc-comment claimed this loop
             * "refuses to write over an existing id"; the exists() call cannot
             * deliver that, because the answer is stale the instant it returns.
             * On this deployment a Firestore round trip is ~1.7-2.3s, so the
             * window is wide, not theoretical.
             *
             * With an `exists:false` precondition the DATABASE refuses the
             * write, the loop simply advances to the next number, and the
             * comment becomes true. The exists() check is kept because it saves
             * a doomed round trip in the common case. */
            $commit = $this->store['commit'] ?? null;
            if (is_callable($commit)) {
                $ok = ($commit)([[
                    'op'           => 'set',
                    'collection'   => self::HEAD_COLLECTION,
                    'docId'        => $docId,
                    'data'         => $head,
                    'precondition' => ['exists' => false],
                ]]);
                if ($ok !== true) {
                    continue;   // somebody won the race — take the next number
                }
            } else {
                ($this->store['set'])(self::HEAD_COLLECTION, $docId, $head);
            }

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

        /* AN ALLOWLIST, NOT A DENYLIST.
         *
         * This was a denylist of 7 fields against a document carrying 24, and a
         * denylist is only ever as good as the last person's memory. Two fields
         * had already fallen through it, each independently exploitable by an
         * `edit`-grade caller who never needed `manage`:
         *
         *   docType  — create() gates the type against the school's state via
         *              _safe_type(); save() did not, so you could mint a custom
         *              type and patch it into a state-gated statutory one. The
         *              proof gate does not defend this dimension either
         *              (contentHash() omits docType), so it survived publish and
         *              froze permanently into version history.
         *
         *   version  — proof_pdf() builds its output filename from the LIVE head's
         *              version. Setting version back to an already-published
         *              number made the next proof render overwrite the frozen PDF
         *              that published version points at. The Firestore snapshot
         *              stayed honest while the artefact a school actually
         *              downloads was replaced. No race, two ordinary POSTs.
         *
         * Adding those two names to the denylist would fix those two reports and
         * leave the shape intact for the next field somebody adds. So the list is
         * inverted: a draft edit may change the DESIGN, and nothing else. Every
         * field not named here — present or future, known or forgotten — is
         * dropped, and the caller is told rather than silently ignored.
         *
         * The permitted set is exactly what the editor sends (designer.js:1401-1405),
         * plus the two fields other legitimate flows carry. */
        $editable = ['name', 'docTitle', 'page', 'header', 'footer', 'objects',
                     'languages', 'defaultLanguage', 'complianceLayers'];

        $rejected = array_diff(array_keys($patch), $editable);
        $patch    = array_intersect_key($patch, array_flip($editable));

        if ($rejected) {
            /* Not silent, and not fatal either.
             *
             * The save still succeeds, because the DESIGN in the patch is
             * legitimate and refusing it would break any future client that
             * innocently round-trips a whole template object back through
             * save() — a very plausible refactor that would turn a harmless
             * no-op into a hard failure on every keystroke.
             *
             * But it does not pass silently. Dropping a field while answering
             * "saved" is the phantom-success shape this codebase has been
             * bitten by before, so the rejection is recorded in the audit trail
             * AND returned to the caller, which can surface it. A caller that
             * ignores the field is choosing to; a caller that never hears about
             * it had no choice. */
            $this->log('save.rejected', $docId,
                'Ignored non-editable field(s): ' . implode(', ', $rejected));
        }

        /* GEOMETRY BOUNDS — the server's own, not the client's.
           Page margins and object x/y/w/h had no range check on either side:
           evalMm() rejects non-numbers and clamps nothing, and save() wrote the
           patch through untouched. A negative margin or a 90000mm object could
           be saved and then published, and publish only checks that the proof
           hash still matches the design — not that the design is on the page. */
        if (isset($patch['page']) && is_array($patch['page'])) {
            $patch['page'] = $this->boundPage($patch['page']);
        }
        if (isset($patch['objects']) && is_array($patch['objects'])) {
            $patch['objects'] = array_map([$this, 'boundObject'], $patch['objects']);
        }

        $patch['lockVersion'] = $stored + 1;
        $patch['updatedAt']   = $this->now();
        if ($by !== '') {
            $patch['updatedBy'] = $by;
        }
        /* A REAL COMPARE-AND-SWAP, not a read-then-hope.
         *
         * The doc-comment above has always promised "the loser gets a conflict;
         * nobody gets a lost edit". The implementation read the head, compared
         * lockVersion in PHP, then issued an UNCONDITIONAL write — so two saves
         * that both read lockVersion 7 both passed the check and the second
         * silently overwrote the first. Neither user saw an error. On this
         * deployment a Firestore round trip is ~1.7-2.3s, so the window is wide
         * enough to matter rather than being theoretical.
         *
         * `__updateTime` comes back on every read (Firestore_rest_client:630)
         * and commitBatch turns it into a `currentDocument` precondition
         * (:1041-1047) — the same primitive activate() and the fee-accounting
         * loops already use. The database now arbitrates: if the document moved
         * between our read and our write, the commit fails and nothing lands.
         *
         * Falls back to the old unconditional write only where no atomic
         * primitive exists — the injected-store unit tests — and says so. */
        $commit    = $this->store['commit'] ?? null;
        $seenAt    = $head['__updateTime'] ?? null;

        if (is_callable($commit) && is_string($seenAt) && $seenAt !== '') {
            $ok = ($commit)([[
                'op'           => 'set',
                'collection'   => self::HEAD_COLLECTION,
                'docId'        => $docId,
                'merge'        => true,
                'data'         => $patch,
                'precondition' => ['updateTime' => $seenAt],
            ]]);
            if ($ok !== true) {
                throw new RuntimeException(
                    "E_CONFLICT: '$docId' changed while this save was in flight. Your edit "
                    . 'was NOT saved and nothing was overwritten. Reload to see the current '
                    . 'version before editing again.'
                );
            }
        } else {
            ($this->store['update'])(self::HEAD_COLLECTION, $docId, $patch);
        }

        $out = $patch;
        if ($rejected) {
            $out['rejectedFields'] = array_values($rejected);
        }
        return $out;
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
            /* WHERE THE RENDERED FILES ARE.
               proof_pdf() writes one PDF per language and passes their paths,
               and this record dropped them — so publish() froze a snapshot with
               'proofPdfPaths' => [], and a published version could never be
               shown to anybody. The snapshot names the hash, the fonts and the
               engine, and then could not produce the document itself. */
            'pdfPaths'     => (array) ($proof['pdfPaths'] ?? []),
            'perLanguage'  => (array) ($proof['perLanguage'] ?? []),
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
            /* PER-LANGUAGE hashes, frozen alongside the paths.
               `proofPdfHash` is a single digest over ALL languages concatenated, so it
               cannot verify one downloaded file. Freezing the per-language digests lets
               version_pdf check the exact bytes it is about to serve against what was
               recorded at publication — see the integrity gate there. */
            'proofPdfPerLanguage' => (array) ($proof['perLanguage'] ?? []),
            'fontManifest'     => $proof['fontManifest'],
            'mpdfVersion'      => $proof['mpdfVersion'],
            'publishedBy'      => $by,
            'publishedAt'      => $this->now(),
        ];
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

        /* ONE ATOMIC WRITE, for the same reason activate() insists on one.
         *
         * This was two sequential calls: set() the frozen snapshot, then
         * update() the head. If the process died between them — a timeout, a
         * network blip, a worker recycle — the snapshot existed and the head
         * never advanced. The retry then hit the create-only guard above
         * ("version already exists") BEFORE reaching the head update, and
         * because the head's version had never incremented, the next attempt
         * computed the same version id and hit the same guard. The template
         * could publish that version never, and the following one never either:
         * a permanent dead end reachable by an ordinary network failure, with
         * no self-service repair.
         *
         * There WAS an accidental escape — save() would accept a `version`
         * field, so a technical user could hand-crank the counter past the
         * blockage. That was also the primitive behind the P0 proof-PDF
         * overwrite, and closing the P0 closed the escape with it. Rather than
         * build a repair tool for a state that should not exist, the state is
         * made unreachable: both writes land together or neither does.
         *
         * The precondition on the snapshot is what makes the retry safe. It is
         * create-only at the database, not merely guarded by the read above, so
         * two concurrent publishes cannot both believe they won. */
        $commit = $this->store['commit'] ?? null;
        if (!is_callable($commit)) {
            throw new RuntimeException(
                'Doc_template_service: publish requires an atomic multi-document write and '
                . 'none is available. Refusing to run non-atomically: a failure between the '
                . 'two writes strands the template permanently — it could publish neither '
                . 'this version nor any later one.'
            );
        }

        $ok = ($commit)([
            [
                'op'           => 'set',
                'collection'   => self::VERSION_COLLECTION,
                'docId'        => $vid,
                'data'         => $snapshot,
                // Create-only AT THE DATABASE. The exists() check above is a
                // courtesy that gives a readable error; this is the guarantee.
                'precondition' => ['exists' => false],
            ],
            [
                'op'           => 'set',
                'collection'   => self::HEAD_COLLECTION,
                'docId'        => $docId,
                'merge'        => true,
                'data'         => $headPatch,
                'precondition' => ['exists' => true],
            ],
        ]);

        if ($ok !== true) {
            throw new RuntimeException(
                "Doc_template_service: publishing '$docId' v$version did not commit. "
                . 'Nothing was written — the draft is untouched and can be published again.'
            );
        }

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

        /* An ARCHIVED template must not be reactivated.
           `archive()` refuses to archive an active template, so the pair looked
           symmetrical — but `activate()` never read `status`, so the same
           template could be archived and then activated straight back, arriving
           live in a state the gallery treats as retired. The two guards now
           close the loop from both ends. */
        if (($head['status'] ?? 'draft') === 'archived') {
            throw new RuntimeException(
                "Doc_template_service: '$docId' is archived. An archived template is "
                . 'retired, and making it live again would resolve every print point to a '
                . 'document the school has taken out of use. Duplicate it into a new draft '
                . 'instead.'
            );
        }

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
                'rollback' => $isRollback,
                /* The caller must be told the new lockVersion. Every lifecycle
                   write bumps it, and a client still holding the old one
                   conflicts on its very next autosave. */
                'lockVersion' => (int) ($head['lockVersion'] ?? 0) + 1];
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

    /**
     * Delete a draft outright — only one that was NEVER published.
     *
     * The rule is not squeamishness about deletion, it is about what a
     * published version IS. Each frozen snapshot is the record of what a
     * certificate issued from it actually said; an issued Transfer Certificate
     * points at one, and courts and boards ask years later. Deleting a template
     * that has any published version would delete that answer.
     *
     * So: never published and not active -> gone, genuinely. Otherwise
     * archive(), which keeps the history and takes it out of the way.
     *
     * @throws RuntimeException naming which of the two rules stopped it, and
     *         what to do instead — a refusal that does not say that is just a
     *         locked door.
     */
    public function delete(string $docId, string $by = ''): array
    {
        $head = $this->head($docId);

        if (($head['activeVersion'] ?? null) !== null) {
            throw new RuntimeException(
                'Doc_template_service: this is the ACTIVE template for '
                . ($head['docType'] ?? 'this document type')
                . '. Deactivate it first — deleting what every print point resolves would '
                . 'stop the school issuing this document.'
            );
        }

        if (($head['publishedVersion'] ?? null) !== null) {
            throw new RuntimeException(
                'Doc_template_service: this template has published version(s), and each one '
                . 'is the record of what a certificate issued from it actually said. Deleting '
                . 'it would delete that record. Archive it instead — it disappears from the '
                . 'list and the history survives.'
            );
        }

        if (!is_callable($this->store['delete'] ?? null)) {
            throw new RuntimeException(
                'Doc_template_service: no delete is available in this store. Refusing to '
                . 'pretend the template was removed.'
            );
        }

        ($this->store['delete'])(self::HEAD_COLLECTION, $docId);
        $this->log('delete', $docId,
            'Deleted draft "' . ($head['name'] ?? $docId) . '" — never published');

        return ['deleted' => $docId, 'name' => $head['name'] ?? ''];
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
    /** The largest sheet the engine supports, with room to spare. */
    private const MAX_MM = 2000.0;

    private function clampMm($v, float $min, float $max, float $default): float
    {
        if (!is_numeric($v)) {
            return $default;
        }
        return max($min, min($max, (float) $v));
    }

    /** Margins cannot be negative, and cannot exceed the sheet. */
    private function boundPage(array $page): array
    {
        if (isset($page['marginsMm']) && is_array($page['marginsMm'])) {
            foreach (['t', 'r', 'b', 'l'] as $k) {
                if (array_key_exists($k, $page['marginsMm'])) {
                    $page['marginsMm'][$k] = $this->clampMm($page['marginsMm'][$k], 0.0, self::MAX_MM, 15.0);
                }
            }
        }
        return $page;
    }

    /**
     * An object must sit within a plausible sheet.
     *
     * Position may be negative — bleed off the edge is a legitimate design — but
     * is bounded so it cannot be parked a kilometre away, and width/height can
     * never be negative or absurd.
     */
    private function boundObject($o)
    {
        if (!is_array($o)) {
            return $o;
        }
        foreach (['xMm' => -self::MAX_MM, 'yMm' => -self::MAX_MM] as $k => $min) {
            if (array_key_exists($k, $o)) {
                $o[$k] = $this->clampMm($o[$k], $min, self::MAX_MM, 0.0);
            }
        }
        foreach (['wMm', 'hMm', 'maxHMm', 'anchorGapMm'] as $k) {
            if (array_key_exists($k, $o)) {
                $o[$k] = $this->clampMm($o[$k], 0.0, self::MAX_MM, 0.0);
            }
        }
        return $o;
    }

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
