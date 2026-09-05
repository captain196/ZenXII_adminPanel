<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Doc_contract — the merge-field contract service for the Document Engine.
 *
 * Reads `application/config/doc_types.php`. That file and
 * `assets/js/doctemplates/designer.js` are two copies of one contract, held in
 * step by `tests/Unit/DocContractParityTest`. This library is the only
 * sanctioned server-side reader of it — resolve through here rather than
 * touching `$this->config->item('doc_contracts')` directly, so the fail-closed
 * rules below cannot be bypassed by a caller in a hurry.
 *
 * ---------------------------------------------------------------------------
 * FAIL CLOSED. THAT IS THE WHOLE DESIGN.
 * ---------------------------------------------------------------------------
 * Every path that cannot resolve a value reports it. It never substitutes an
 * empty string, a dash, a placeholder, or the key itself. A certificate is a
 * legal record: a blank where a statutory field belongs is a defective
 * document, and a literal `{student_name}` reaching print is worse. There is no
 * "best effort" mode here and one must not be added.
 *
 * This mirrors what the client already enforces at design time and what the
 * E2E suite already tests (case E5): a clamped or truncated value is a BLOCKING
 * finding, never a silent shortening.
 *
 * EXECUTION_PLAN_v1.1 P1.9
 */
class Doc_contract
{
    /** @var array<string,array<string,mixed>> */
    private array $fields;
    /** @var array<string,array<int,string>> */
    private array $contracts;
    /** @var array<string,array<string,mixed>> */
    private array $types;

    /**
     * @param array $params CI3 library params. Normally empty — the config is
     *        read through CodeIgniter. Passing
     *        `['fields'=>…, 'contracts'=>…, 'types'=>…]` bypasses the CI
     *        loader, which is what lets the unit suite exercise this class
     *        without a framework bootstrap. It is NOT a production seam:
     *        nothing in `application/` may pass it.
     */
    public function __construct(array $params = [])
    {
        if ($params !== []) {
            $this->fields    = (array) ($params['fields'] ?? []);
            $this->contracts = (array) ($params['contracts'] ?? []);
            $this->types     = (array) ($params['types'] ?? []);
            $this->assertLoaded();
            return;
        }

        $ci = &get_instance();
        $ci->config->load('doc_types', false, true);

        $this->fields    = (array) $ci->config->item('doc_merge_fields');
        $this->contracts = (array) $ci->config->item('doc_contracts');
        $this->types     = (array) $ci->config->item('doc_types');

        $this->assertLoaded();
    }

    /**
     * Serving a half-loaded contract is how a document silently loses a field.
     * Refuse to construct rather than hand out a partial universe.
     */
    private function assertLoaded(): void
    {
        if (!$this->fields || !$this->contracts || !$this->types) {
            throw new RuntimeException('Doc_contract: doc_types config is missing or incomplete');
        }
    }

    /* ================================================================== *
     *  Reads
     * ================================================================== */

    /**
     * The full field definitions a document type may bind, in declared order.
     *
     * Order is preserved deliberately: it is the order the field picker
     * presents, and for a statutory form the declared order follows the
     * printed form's own order.
     *
     * @return array<string,array<string,mixed>> key => definition
     */
    public function get(string $docType): array
    {
        $out = [];
        foreach ($this->keysFor($docType) as $k) {
            if (!isset($this->fields[$k])) {
                // DocContractParityTest catches this, so reaching here means
                // the config was edited without running the suite.
                throw new RuntimeException("Doc_contract: '$docType' declares undefined field '$k'");
            }
            $out[$k] = $this->fields[$k] + ['key' => $k];
        }
        return $out;
    }

    /** The bare key list a document type declares. */
    public function keysFor(string $docType): array
    {
        /* A CUSTOM document has no contract to declare, because a contract
           records SOMEBODY ELSE'S PRESCRIPTION — CBSE's Annexure-I, Kerala's
           Form 5A. A document a school invents has no such author, so there is
           nothing to hold it to and every field we hold is offered.

           The practical consequence is that `offContract` can never fire on a
           custom document. That is correct rather than lax: that check exists
           to stop a template binding a field the prescribing authority does not
           recognise, and here the school IS the authority. Everything else —
           unresolved fields, over-length, the overflow gate — still applies,
           because those are about whether the document prints truthfully. */
        if (self::isCustom($docType)) {
            return array_keys($this->fields);
        }
        if (!isset($this->contracts[$docType])) {
            throw new InvalidArgumentException("Doc_contract: unknown document type '$docType'");
        }
        return $this->contracts[$docType];
    }

    /* ================================================================== *
     *  Custom document types
     *
     *  A custom type is `custom:{slug}` and each one is its OWN document type,
     *  not a shared "Custom" bucket. That is deliberate: the module's central
     *  invariant is exactly one ACTIVE template per (school, docType), and a
     *  shared bucket would make activating a school's Sports Day certificate
     *  silently deactivate its Fee Concession letter. Minting a type per
     *  document keeps every existing rule — one active, one contract, one
     *  gallery, one hub card — working unchanged.
     * ================================================================== */

    /** `custom:` followed by a lowercase slug, 1–40 chars, no leading/trailing _ */
    public const CUSTOM_PATTERN = '/^custom:[a-z0-9](?:[a-z0-9_]{0,38}[a-z0-9])?$/';

    public static function isCustom(string $docType): bool
    {
        return (bool) preg_match(self::CUSTOM_PATTERN, $docType);
    }

    /**
     * Turn what a person typed into a custom document-type id.
     *
     * @throws InvalidArgumentException when nothing usable survives — a title of
     *         only punctuation would otherwise mint `custom:` and collide with
     *         every other unusable title, quietly merging two documents into one
     *         type and one active slot.
     */
    public static function customTypeFor(string $title): string
    {
        /* NORMALISE BEFORE LOWERCASING.
           PHP's strtolower() is byte-only; JavaScript's toLowerCase() is
           Unicode-aware. On a Turkish dotted capital İ (U+0130) they disagreed:
           PHP left it alone and the collapse below swallowed it, JS expanded it
           to "i" + a combining mark and kept the i. "İstanbul Public School"
           therefore minted `custom:stanbul_public_school` on the server and
           `custom:i_stanbul_public_school` in the client — two document-type
           identities from one typed name, and the id is what every template,
           active slot and print point is keyed on.
           Executed in both runtimes on 2026-09-04; every ASCII case agreed,
           which is exactly why it survived review. mb_strtolower with an
           explicit UTF-8 encoding matches the client's behaviour. */
        $slug = function_exists('mb_strtolower')
            ? mb_strtolower(trim($title), 'UTF-8')
            : strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim((string) $slug, '_');
        $slug = substr($slug, 0, 40);
        $slug = trim($slug, '_');

        if ($slug === '') {
            throw new InvalidArgumentException(
                'Doc_contract: "' . $title . '" contains no letters or digits, so it cannot name '
                . 'a document type. Give the document a name a person could read.'
            );
        }
        return self::CUSTOM_PREFIX . $slug;
    }

    public const CUSTOM_PREFIX = 'custom:';

    /** The title a custom type was minted from, as a readable fallback. */
    public static function customTitle(string $docType): string
    {
        if (!self::isCustom($docType)) {
            return $docType;
        }
        return ucfirst(str_replace('_', ' ', substr($docType, strlen(self::CUSTOM_PREFIX))));
    }

    /** Document types this school may use, honouring `requiresState`. */
    public function typesForState(?string $state): array
    {
        $out = [];
        foreach ($this->types as $id => $t) {
            if (!empty($t['disabled'])) {
                continue;
            }
            if (!empty($t['requiresState']) && $t['requiresState'] !== $state) {
                continue;
            }
            $out[$id] = $t + ['id' => $id];
        }
        return $out;
    }

    /** True when the type exists, is enabled, and is available in this state. */
    public function typeAvailable(string $docType, ?string $state): bool
    {
        // A custom type is available wherever it was invented: no state
        // prescribes it, so no state can withhold it.
        if (self::isCustom($docType)) {
            return true;
        }
        return isset($this->typesForState($state)[$docType]);
    }

    /**
     * EVERY document type, each with whether this school may use it and WHY NOT.
     *
     * Deliberately returns the unavailable ones too. The hub parks them with a
     * reason rather than hiding them: a type that silently disappears reads to
     * an administrator as a product that does not support their state, which is
     * the wrong conclusion and one they cannot act on. "Applies in Kerala — this
     * school is in Jharkhand" is a fact they can check.
     *
     * @return array<int,array{id:string,name:string,alias:string,statutory:bool,
     *                         requiresState:?string,disabled:bool,
     *                         available:bool,reason:?string}>
     */
    public function catalogue(?string $state): array
    {
        $out = [];
        foreach ($this->types as $id => $t) {
            $disabled = !empty($t['disabled']);
            $needs    = $t['requiresState'] ?? null;

            if ($disabled) {
                $available = false;
                $reason    = 'Not buildable yet — ' . ($t['alias'] ?? 'declared, not implemented');
            } elseif ($needs !== null && $needs !== $state) {
                $available = false;
                $reason    = "Applies in $needs — this school is in "
                             . ($state !== null && $state !== '' ? $state : 'a state that is not set');
            } else {
                $available = true;
                $reason    = null;
            }

            $out[] = [
                'id'            => $id,
                'name'          => $t['name'],
                'alias'         => $t['alias'] ?? '',
                'statutory'     => !empty($t['statutory']),
                'requiresState' => $needs,
                'disabled'      => $disabled,
                'available'     => $available,
                'reason'        => $reason,
                'fieldCount'    => isset($this->contracts[$id]) ? count($this->contracts[$id]) : 0,
            ];
        }
        return $out;
    }

    /* ================================================================== *
     *  validateBundle
     * ================================================================== */

    /**
     * Validate a resolved data bundle against a document type's contract.
     *
     * @param array<string,mixed>    $bundle    key => resolved value
     * @param array<int,string>|null $boundKeys keys the TEMPLATE actually binds.
     *        Pass the template's bound keys to check only what will be printed;
     *        pass null to require the whole contract.
     *
     * @return array{ok:bool,errors:array<int,array>,warnings:array<int,array>}
     *
     * Errors BLOCK issuance. Warnings do not, but are recorded.
     */
    public function validateBundle(string $docType, array $bundle, ?array $boundKeys = null): array
    {
        $contract = $this->get($docType);
        $required = $boundKeys === null ? array_keys($contract) : $boundKeys;

        $errors   = [];
        $warnings = [];

        foreach ($required as $k) {
            /* A template binding a key its own contract does not declare is the
               mail-merge failure the append-only rule exists to prevent. */
            if (!isset($contract[$k])) {
                $errors[] = ['type' => 'offContract', 'key' => $k,
                             'message' => "Field '$k' is not declared by the $docType contract"];
                continue;
            }
            $def = $contract[$k];

            /* Unresolved. Never render a blank into a statutory field. */
            if (!array_key_exists($k, $bundle) || $bundle[$k] === null || $bundle[$k] === ''
                || (is_array($bundle[$k]) && $bundle[$k] === [])) {
                $errors[] = ['type' => 'unresolved', 'key' => $k,
                             'message' => "No value resolved for '{$def['label']}'"];
                continue;
            }

            $type = $def['type'] ?? 'text';

            /* A LIST is rows, not a string.
               Casting one to string yields "Array" — five characters, inside
               every maxLen there is, so a fee receipt with a malformed item
               list would have validated clean and printed a table of nothing.
               Lists are checked per column instead, on their own declared
               limits. */
            if ($type === 'list') {
                foreach ($this->validateList($k, $def, $bundle[$k]) as $issue) {
                    if ($issue['severity'] === 'error') {
                        unset($issue['severity']);
                        $errors[] = $issue;
                    } else {
                        unset($issue['severity']);
                        $warnings[] = $issue;
                    }
                }
                continue;
            }

            if (is_array($bundle[$k])) {
                $errors[] = ['type' => 'badType', 'key' => $k,
                             'message' => "'{$def['label']}' is not a list field but a list was supplied"];
                continue;
            }

            $val = (string) $bundle[$k];

            if ($type === 'int' && !preg_match('/^\d+$/', $val)) {
                $errors[] = ['type' => 'badType', 'key' => $k,
                             'message' => "'{$def['label']}' must be a whole number, got '$val'"];
                continue;
            }

            /* Over-length is a WARNING, not an error, and the distinction is
               deliberate. maxLen is our own Level-D rendering estimate, not a
               statute — so a long but lawful value must not block a document.
               It must instead reach the P2.7 overflow gate, which measures the
               ACTUAL rendered block and throws on a real overflow. Blocking
               here would reject valid data on an estimate; staying silent would
               let the estimate rot unnoticed. */
            if (isset($def['maxLen']) && mb_strlen($val) > $def['maxLen']) {
                $warnings[] = ['type' => 'overLength', 'key' => $k,
                               'len' => mb_strlen($val), 'maxLen' => $def['maxLen'],
                               'message' => "'{$def['label']}' is " . mb_strlen($val)
                                            . " characters against an expected {$def['maxLen']}"
                                            . ' — the overflow gate decides whether it actually fits'];
            }
        }

        /* A value supplied for a key nothing will print is not an error, but it
           usually means the caller resolved against the wrong document type. */
        foreach (array_keys($bundle) as $k) {
            if (!isset($contract[$k])) {
                $warnings[] = ['type' => 'extraneous', 'key' => $k,
                               'message' => "Value supplied for '$k', which the $docType contract does not declare"];
            }
        }

        return ['ok' => $errors === [], 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Check a list field's rows against the columns its own definition declares.
     *
     * Errors are structural — the wrong shape, or a row missing a column the
     * template will print a cell for. Over-length stays a WARNING for the same
     * reason it does on a scalar: maxLen is our rendering estimate, and the
     * overflow gate measures what actually happened.
     *
     * @return list<array{severity:string,type:string,key:string,message:string}>
     */
    private function validateList(string $k, array $def, $value): array
    {
        $label = $def['label'] ?? $k;
        if (!is_array($value) || array_is_list($value) === false) {
            return [['severity' => 'error', 'type' => 'badType', 'key' => $k,
                     'message' => "'$label' must be a list of rows"]];
        }

        $cols   = (array) ($def['itemFields'] ?? []);
        $issues = [];

        foreach ($value as $n => $row) {
            if (!is_array($row)) {
                $issues[] = ['severity' => 'error', 'type' => 'badType', 'key' => $k,
                             'message' => "'$label' row " . ($n + 1) . ' is not a row of columns'];
                continue;
            }
            foreach ($cols as $col => $spec) {
                $cell = $row[$col] ?? null;
                if ($cell === null || $cell === '') {
                    $issues[] = ['severity' => 'error', 'type' => 'unresolved', 'key' => "$k/$col",
                                 'message' => "'$label' row " . ($n + 1) . " has no "
                                              . ($spec['label'] ?? $col)];
                    continue;
                }
                if (isset($spec['maxLen']) && mb_strlen((string) $cell) > $spec['maxLen']) {
                    $issues[] = ['severity' => 'warning', 'type' => 'overLength', 'key' => "$k/$col",
                                 'len' => mb_strlen((string) $cell), 'maxLen' => $spec['maxLen'],
                                 'message' => "'$label' row " . ($n + 1) . ' · '
                                              . ($spec['label'] ?? $col) . ' is '
                                              . mb_strlen((string) $cell)
                                              . " characters against an expected {$spec['maxLen']}"];
                }
            }
        }
        return $issues;
    }

    /* ================================================================== *
     *  diff
     * ================================================================== */

    /**
     * Impact report between two bound-key sets — typically template v3 vs v4.
     *
     * Answers the question a principal actually asks before approving a new
     * version: *what changes on the printed document?*
     *
     * @param array<int,string> $from
     * @param array<int,string> $to
     * @return array{added:array<int,string>,removed:array<int,string>,unchanged:array<int,string>,breaking:bool,impact:array<int,array>}
     *
     * @throws InvalidArgumentException on a key the contract does not declare —
     *         an unknown key is a hard error, never a silently ignored row.
     */
    public function diff(string $docType, array $from, array $to): array
    {
        $contract = $this->get($docType);

        foreach (array_unique(array_merge($from, $to)) as $k) {
            if (!isset($contract[$k])) {
                throw new InvalidArgumentException(
                    "Doc_contract::diff — '$k' is not declared by the $docType contract"
                );
            }
        }

        $added     = array_values(array_diff($to, $from));
        $removed   = array_values(array_diff($from, $to));
        $unchanged = array_values(array_intersect($from, $to));

        $impact = [];
        foreach ($removed as $k) {
            $impact[] = ['change' => 'removed', 'key' => $k,
                         'label'   => $contract[$k]['label'],
                         'message' => "'{$contract[$k]['label']}' will no longer appear on the document"];
        }
        foreach ($added as $k) {
            $impact[] = ['change' => 'added', 'key' => $k,
                         'label'   => $contract[$k]['label'],
                         'message' => "'{$contract[$k]['label']}' will now appear on the document"];
        }

        /* Removal is the breaking direction: a document that used to carry a
           statutory field and now does not is the defect. Addition is additive
           and cannot invalidate an already-issued document. */
        return [
            'added'     => $added,
            'removed'   => $removed,
            'unchanged' => $unchanged,
            'breaking'  => $removed !== [],
            'impact'    => $impact,
        ];
    }

    /* ================================================================== *
     *  Sample bundles — preview, and the p95 stress mode
     * ================================================================== */

    /**
     * A complete sample bundle for a document type.
     *
     * @param bool $p95 true = worst-case realistic values, which is what fires
     *        auto-grow, push-down, overflow and overlap warnings at DESIGN
     *        time rather than at issue time. A short sample is how overflow
     *        bugs reach production.
     */
    public function sampleBundle(string $docType, bool $p95 = false): array
    {
        $out = [];
        foreach ($this->get($docType) as $k => $def) {
            $out[$k] = $p95 ? ($def['p95'] ?? $def['sample']) : $def['sample'];
        }
        return $out;
    }
}
