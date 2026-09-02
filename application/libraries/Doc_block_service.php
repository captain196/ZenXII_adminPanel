<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Doc_block_service — reusable blocks (letterhead / signature row / seal).
 *
 * Phase 8. Same injected-store shape as Doc_template_service, for the same
 * reason: the interesting behaviour here is version and reference ARITHMETIC,
 * not Firestore.
 *
 * ---------------------------------------------------------------------------
 * A BLOCK UPDATE IS OFFERED, NEVER PUSHED
 * ---------------------------------------------------------------------------
 * The plan's P8.2 accept reads "editing a letterhead updates every template
 * that references it". `FIGMA_ARCHITECTURE_STUDY.md` found that this
 * CONTRADICTS `COLLECTION_SHAPES.md` §4 — "published versions: no update, no
 * delete — ever" — and resolved it with Figma's library model:
 *
 *   • a template PINS the block version it was designed against;
 *   • publishing a new block version does NOT touch any template;
 *   • referencing templates are OFFERED the update, with a badge;
 *   • accepting creates a new DRAFT; the published snapshot never changes.
 *
 * Pushing would silently alter a template a principal has already approved, and
 * on a statutory document that is a change of legal content nobody authorised.
 * So this service can raise a block's version and REPORT who is affected. It
 * has no method that mutates a referencing template, deliberately.
 *
 * ---------------------------------------------------------------------------
 * BLOCKS AND CONTRACTS ARE COUPLED, ONE WAY
 * ---------------------------------------------------------------------------
 * A block imposes its bound keys on every contract that uses it — the shared
 * letterhead binds `school.affiliationNo`, so every doc type whose starter uses
 * it must declare that key. `boundKeys()` exposes this so the coupling is
 * checkable rather than discovered when a template fails to publish.
 */
class Doc_block_service
{
    const COLLECTION      = 'reusableBlocks';
    const HEAD_COLLECTION = 'documentTemplates';

    /** @var array<string,callable> */
    private array $store;

    /** @var callable|null */
    private $audit;

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
            'get'    => fn(string $c, string $id) => $fs->get($c, $id),
            'set'    => fn(string $c, string $id, array $d) => $fs->set($c, $id, $d),
            'update' => fn(string $c, string $id, array $d) => $fs->update($c, $id, $d),
            'query'  => fn(string $c, array $w) => $fs->schoolWhere($c, $w),
        ];
    }

    /* ================================================================== *
     *  P8.1 — save and list
     * ================================================================== */

    /** Blocks a school may use, optionally narrowed to one blockType. */
    public function listFor(string $schoolId, ?string $blockType = null): array
    {
        $where = [['schoolId', '=', $schoolId]];
        if ($blockType !== null) {
            $where[] = ['blockType', '=', $blockType];
        }
        return ($this->store['query'])(self::COLLECTION, $where) ?: [];
    }

    public function get(string $docId): ?array
    {
        $b = ($this->store['get'])(self::COLLECTION, $docId);
        return is_array($b) && $b ? $b : null;
    }

    /**
     * Create or edit a block. An EDIT bumps `version`, which is what makes the
     * offer visible to referencing templates.
     *
     * @throws InvalidArgumentException on a missing blockType or schoolId —
     *         an unscoped block would be offered to every tenant.
     */
    public function save(string $docId, array $data, string $by = ''): array
    {
        /* A block id is caller-supplied. Overwriting another school's block
           would both destroy it and hijack it into this school's library, since
           schoolId is reassigned below. Same reasoning as
           Doc_template_service::head(): the Admin SDK bypasses firestore.rules. */
        $existing = ($this->store['get'])(self::COLLECTION, $docId);
        if (is_array($existing) && $existing
            && isset($data['schoolId'])
            && ($existing['schoolId'] ?? null) !== $data['schoolId']) {
            throw new RuntimeException("Doc_block_service: no block '$docId'");
        }

        foreach (['schoolId', 'blockType'] as $k) {
            if (empty($data[$k])) {
                throw new InvalidArgumentException("Doc_block_service: '$k' is required");
            }
        }

        $existing = $this->get($docId);
        $version  = $existing ? ((int) ($existing['version'] ?? 1)) + 1 : 1;

        $doc = $data + [];
        $doc['version']   = $version;
        $doc['updatedAt'] = gmdate('c');
        $doc['updatedBy'] = $by;
        if (!$existing) {
            $doc['createdAt'] = $doc['updatedAt'];
        }

        ($this->store['set'])(self::COLLECTION, $docId, $doc);
        $this->log($existing ? 'block_edit' : 'block_create', $docId,
            ($existing ? 'Edited' : 'Created') . " block v$version");

        return $doc;
    }

    /* ================================================================== *
     *  P8.2 — the OFFER
     * ================================================================== */

    /**
     * Which templates are behind this block, and by how much.
     *
     * A REPORT, not an action. Nothing here writes to a template.
     *
     * @return list<array{templateId:string,pinned:int,available:int,status:string,ignored:bool}>
     */
    public function offersFor(string $blockDocId): array
    {
        $block = $this->get($blockDocId);
        if ($block === null) {
            throw new RuntimeException("Doc_block_service: no block '$blockDocId'");
        }
        $blockId   = (string) ($block['blockId'] ?? $blockDocId);
        $available = (int) ($block['version'] ?? 1);

        $templates = ($this->store['query'])(self::HEAD_COLLECTION, [
            ['schoolId', '=', (string) ($block['schoolId'] ?? '')],
        ]) ?: [];

        $out = [];
        foreach ($templates as $id => $t) {
            $id     = is_string($id) ? $id : (string) ($t['_id'] ?? '');
            $pinned = $t['blockRefs'][$blockId] ?? null;
            if ($id === '' || $pinned === null) {
                continue;                       // does not reference this block
            }
            if ((int) $pinned >= $available) {
                continue;                       // already current
            }
            $out[] = [
                'templateId' => $id,
                'pinned'     => (int) $pinned,
                'available'  => $available,
                'status'     => (string) ($t['status'] ?? 'draft'),
                'ignored'    => !empty($t['blockIgnored'][$blockId]),
            ];
        }
        return $out;
    }

    /**
     * Accept an offer for ONE template — the only way a template's pin moves.
     *
     * Refuses on a published head. Accepting is an edit, and an edit to a
     * published template must go through a new draft (P6.3); silently mutating
     * an approved document is the failure the offer model exists to prevent.
     *
     * @throws RuntimeException if the template is not a draft, or has no offer.
     */
    public function acceptOffer(string $templateDocId, string $blockId, string $by = ''): array
    {
        $t = ($this->store['get'])(self::HEAD_COLLECTION, $templateDocId);
        if (!is_array($t) || !$t) {
            throw new RuntimeException("Doc_block_service: no template '$templateDocId'");
        }
        if (($t['status'] ?? 'draft') !== 'draft') {
            throw new RuntimeException(
                "Doc_block_service: '$templateDocId' is {$t['status']}. A block update is "
                . 'accepted into a DRAFT — a published template is never edited in place.'
            );
        }

        $pinned = $t['blockRefs'][$blockId] ?? null;
        if ($pinned === null) {
            throw new RuntimeException("Doc_block_service: '$templateDocId' does not reference '$blockId'");
        }

        $blocks = $this->listFor((string) ($t['schoolId'] ?? ''));
        $available = null;
        foreach ($blocks as $b) {
            if ((string) ($b['blockId'] ?? '') === $blockId) {
                $available = (int) ($b['version'] ?? 1);
                break;
            }
        }
        if ($available === null || $available <= (int) $pinned) {
            throw new RuntimeException("Doc_block_service: no pending update for '$blockId'");
        }

        $refs = $t['blockRefs'];
        $refs[$blockId] = $available;
        $ignored = $t['blockIgnored'] ?? [];
        unset($ignored[$blockId]);              // accepting clears any prior decline

        $patch = [
            'blockRefs'    => $refs,
            'blockIgnored' => $ignored,
            'lockVersion'  => (int) ($t['lockVersion'] ?? 0) + 1,
            'updatedAt'    => gmdate('c'),
        ];
        ($this->store['update'])(self::HEAD_COLLECTION, $templateDocId, $patch);
        $this->log('block_accept', $templateDocId, "Accepted $blockId v$available");

        return $patch;
    }

    /** Decline, stickily — otherwise the badge nags and the signal is learned away. */
    public function declineOffer(string $templateDocId, string $blockId, string $by = ''): array
    {
        $t = ($this->store['get'])(self::HEAD_COLLECTION, $templateDocId);
        if (!is_array($t) || !$t) {
            throw new RuntimeException("Doc_block_service: no template '$templateDocId'");
        }
        $ignored = $t['blockIgnored'] ?? [];
        $ignored[$blockId] = true;

        $patch = ['blockIgnored' => $ignored, 'updatedAt' => gmdate('c')];
        ($this->store['update'])(self::HEAD_COLLECTION, $templateDocId, $patch);
        $this->log('block_decline', $templateDocId, "Declined $blockId");

        return $patch;
    }

    /* ================================================================== *
     *  Coupling
     * ================================================================== */

    /**
     * The merge-field keys a block binds — which it IMPOSES on every contract
     * that uses it. The shared letterhead binds `school.affiliationNo`, so any
     * doc type whose starter includes it must declare that key or the template
     * cannot publish.
     *
     * @return list<string>
     */
    public function boundKeys(string $docId): array
    {
        $b = $this->get($docId);
        if ($b === null) {
            return [];
        }
        $keys = [];
        foreach ((array) ($b['objects'] ?? []) as $o) {
            foreach ((array) ($o['content']['i18n'] ?? []) as $lang) {
                foreach ((array) ($lang['runs'] ?? []) as $r) {
                    if (!empty($r['f'])) {
                        $keys[$r['f']] = true;
                    }
                }
            }
        }
        return array_keys($keys);
    }

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
}
