<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/Doc_rows.php';

/**
 * Doc_resolver — the READ-ONLY seam between the Document Engine and the
 * modules that will one day print.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT DOES, AND THE ONE THING IT DELIBERATELY CANNOT DO
 * ---------------------------------------------------------------------------
 * It answers, for a document type: *is there an active template, which frozen
 * version is it, and is this school ready to issue?*
 *
 * It CANNOT issue. There is no render method, no number allocation, no write
 * of any kind — no method on this class touches Firestore except to read.
 * Issuance is the next engine (`CON-NO_PRINT_IMPL`), and a resolver that could
 * quietly render one document would be that engine, built by accident and
 * without the number allocation, register and audit trail that make an issued
 * document lawful.
 *
 * The absence is enforced, not merely intended: DocResolverTest asserts this
 * class exposes no method whose name suggests issuing or rendering. That guard
 * exists because the tempting next commit is a "small helper" here.
 *
 * ---------------------------------------------------------------------------
 * WHY A SEAM AT ALL, IF NOTHING PRINTS
 * ---------------------------------------------------------------------------
 * So that Accounts, Students, Staff and Payroll can be built against a stable
 * question — "can I offer this?" — before the answer can ever be "here it is".
 * A module can render a disabled button with a truthful reason today, and the
 * day issuance lands, nothing about how it asks changes.
 *
 * @see application/config/document_targets.php  where each type surfaces
 */
class Doc_resolver
{
    const HEAD_COLLECTION    = 'documentTemplates';
    const VERSION_COLLECTION = 'documentTemplateVersions';

    /** @var array<string,callable> */
    private array $store;

    /** @var array|null lazily loaded print-point registry */
    private ?array $targets = null;

    public function __construct(array $params = [])
    {
        if (!empty($params['store'])) {
            $this->store = $params['store'];
            if (isset($params['targets'])) {
                $this->targets = $params['targets'];
            }
            return;
        }

        $ci = &get_instance();
        $fs = $ci->fs;
        $this->store = [
            'get'   => fn(string $c, string $id) => $fs->get($c, $id),
            'query' => fn(string $c, array $w) => Doc_rows::map($fs->schoolWhere($c, $w)),
        ];
    }

    /* ================================================================== *
     *  The registry
     * ================================================================== */

    /** Every declared print point. */
    public function targets(): array
    {
        if ($this->targets === null) {
            $this->targets = include APPPATH . 'config/document_targets.php';
        }
        return is_array($this->targets) ? $this->targets : [];
    }

    /** The print points a given RBAC module owns — e.g. everything Accounts prints. */
    public function targetsForModule(string $module): array
    {
        return array_values(array_filter(
            $this->targets(),
            fn($t) => ($t['module'] ?? null) === $module
        ));
    }

    /**
     * Is any print point wired yet?
     *
     * Always false in this build. Exposed so a caller can branch on the FACT
     * rather than on a hardcoded assumption that will rot silently the day it
     * stops being true.
     */
    public function issuanceAvailable(): bool
    {
        foreach ($this->targets() as $t) {
            if (!empty($t['wired'])) {
                return true;
            }
        }
        return false;
    }

    /* ================================================================== *
     *  Resolution
     * ================================================================== */

    /**
     * The active template head for a (school, docType), or null.
     *
     * Exactly one may be active per document type. If the data ever holds more
     * than one this returns null rather than picking — an arbitrary choice
     * between two "official" certificates is worse than refusing, because the
     * caller cannot tell it was arbitrary.
     */
    public function activeTemplate(string $schoolId, string $docType): ?array
    {
        $rows = ($this->store['query'])(self::HEAD_COLLECTION, [
            ['schoolId', '=', $schoolId],
            ['docType',  '=', $docType],
        ]) ?: [];

        $active = [];
        foreach ($rows as $id => $row) {
            if (($row['activeVersion'] ?? null) !== null) {
                $row['_id'] = is_string($id) ? $id : (string) ($row['_id'] ?? '');
                $active[] = $row;
            }
        }

        return count($active) === 1 ? $active[0] : null;
    }

    /**
     * The frozen, immutable version an issued document would be rendered from.
     *
     * The VERSION, never the head. The head is a live draft that moves; a
     * document must record the exact template that produced it, and "the
     * template as it was three years ago" is only answerable if the frozen
     * snapshot is what was used.
     */
    public function activeVersion(string $schoolId, string $docType): ?array
    {
        $head = $this->activeTemplate($schoolId, $docType);
        if ($head === null) {
            return null;
        }
        $vid = ($head['_id'] ?? '') . '_v' . (int) $head['activeVersion'];
        $v = ($this->store['get'])(self::VERSION_COLLECTION, $vid);
        return is_array($v) ? $v : null;
    }

    /**
     * Can this school issue this document type today?
     *
     * Returns a reason in EVERY case, including the affirmative one. A caller
     * rendering a disabled button needs something truthful to put in a
     * tooltip, and "not available" with no reason is how a school ends up
     * phoning support to be told they never activated a template.
     *
     * @return array{ready:bool, reason:string, code:string,
     *                templateId:?string, version:?int, target:?array}
     */
    public function readiness(string $schoolId, string $docType): array
    {
        $no = fn(string $code, string $reason) => [
            'ready' => false, 'code' => $code, 'reason' => $reason,
            'templateId' => null, 'version' => null,
            'target' => $this->targets()[$docType] ?? null,
        ];

        $target = $this->targets()[$docType] ?? null;
        if ($target === null) {
            return $no('NO_TARGET',
                "'$docType' has no declared print point. Add it to config/document_targets.php "
                . 'before building a button for it.');
        }

        $head = $this->activeTemplate($schoolId, $docType);
        if ($head === null) {
            return $no('NO_ACTIVE_TEMPLATE',
                'No template is active for this document type. A template must be designed, '
                . 'published and then activated before anything can be issued.');
        }

        if ($this->activeVersion($schoolId, $docType) === null) {
            // The pointer survived but the snapshot did not: worse than nothing
            // active, because the UI would look ready.
            return $no('MISSING_SNAPSHOT',
                'The active version points at a frozen snapshot that cannot be read. '
                . 'Nothing may be issued from a template that cannot be reproduced.');
        }

        if (empty($target['wired'])) {
            return array_merge(
                $no('ISSUANCE_NOT_WIRED',
                    'A template is active and ready, but no print point is wired in this build. '
                    . 'Issuance — number allocation, the issued-document register and archival — '
                    . 'is a separate engine.'),
                ['templateId' => $head['_id'] ?? null, 'version' => (int) $head['activeVersion']]
            );
        }

        return [
            'ready' => true,
            'code' => 'READY',
            'reason' => 'An active template is published and a print point is wired.',
            'templateId' => $head['_id'] ?? null,
            'version' => (int) $head['activeVersion'],
            'target' => $target,
        ];
    }
}
