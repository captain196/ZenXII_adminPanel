<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/Doc_rows.php';

/**
 * Doc_compliance — authority versions and the re-validation REPORT (P5.6).
 *
 * ---------------------------------------------------------------------------
 * A LIST, NEVER AN AUTO-ACTION. That is the whole requirement.
 * ---------------------------------------------------------------------------
 * When an authority is revised — CBSE amends Annexure-I, a state gazettes a new
 * form — every template validated against the old version becomes, in some
 * sense, stale. The tempting behaviour is to invalidate them automatically.
 *
 * That would be wrong, and dangerous in a specific way: **it would take a
 * school's active certificate away without anyone deciding to.** A clerk who
 * printed a Transfer Certificate yesterday would find the print button dead
 * this morning, with no human in the loop and no explanation. On a statutory
 * document that is worse than being slightly out of date.
 *
 * So this class REPORTS. It has no method that changes a template's status, its
 * active version, or its compliance layers. Acting on the report is a person's
 * job, one template at a time, through the normal draft → publish → activate
 * path that already carries its own gates and its own audit trail.
 *
 * This mirrors the block OFFER model in `Doc_block_service` deliberately: the
 * same problem — a shared thing changed underneath things that reference it —
 * and therefore the same answer.
 *
 * EXECUTION_PLAN_v1.1 P5.6 · COMPLIANCE_ARCHITECTURE.md §5.2
 */
class Doc_compliance
{
    const AUTHORITY_COLLECTION = 'complianceAuthorities';
    const HEAD_COLLECTION      = 'documentTemplates';
    const VERSION_COLLECTION   = 'documentTemplateVersions';

    /** Evidence levels, best first. A is read from the primary text. */
    const EVIDENCE_RANK = ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1];

    /** @var array<string,callable> */
    private array $store;

    public function __construct(array $params = [])
    {
        if (!empty($params['store'])) {
            $this->store = $params['store'];
            return;
        }
        $ci = &get_instance();
        $fs = $ci->fs;
        $this->store = [
            'get'   => fn(string $c, string $id) => $fs->get($c, $id),
            'query' => fn(string $c, array $w) => Doc_rows::map($fs->where($c, $w)),
        ];
    }

    /* ================================================================== *
     *  P5.6 — the report
     * ================================================================== */

    /**
     * Which templates were validated against an older version of this
     * authority, across every school.
     *
     * @return array{
     *   authorityId:string, label:string, currentVersion:int,
     *   evidence:?string, verifiedOn:?string,
     *   affected: list<array{templateId:string,schoolId:string,docType:string,
     *                        status:string,appliedVersion:int,active:bool}>,
     *   schools: list<string>, activeCount:int
     * }
     * @throws RuntimeException on an unknown authority — reporting "0 affected"
     *         for an id that does not exist would read as reassurance.
     */
    public function affectedByAuthority(string $authorityId): array
    {
        $a = ($this->store['get'])(self::AUTHORITY_COLLECTION, $authorityId);
        if (!is_array($a) || !$a) {
            throw new RuntimeException(
                "Doc_compliance: no authority '$authorityId'. Refusing to report "
                . '"0 affected" for an id that does not exist — that reads as reassurance.'
            );
        }

        $current   = (int) ($a['version'] ?? 1);
        $templates = ($this->store['query'])(self::HEAD_COLLECTION, []) ?: [];

        $affected = [];
        $schools  = [];
        $active   = 0;

        foreach ($templates as $id => $t) {
            $id = is_string($id) ? $id : (string) ($t['_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $appliedVersion = null;
            foreach ((array) ($t['complianceLayers'] ?? []) as $layer) {
                if (($layer['authorityId'] ?? null) === $authorityId) {
                    // An EXCLUDED layer is not affected: the school recorded a
                    // written reason for not applying it, and a revision to a
                    // rule you are documented as not following changes nothing.
                    if (array_key_exists('applied', $layer) && !$layer['applied']) {
                        break;
                    }
                    $appliedVersion = (int) ($layer['version'] ?? 0);
                    break;
                }
            }
            if ($appliedVersion === null || $appliedVersion >= $current) {
                continue;
            }

            $isActive = ($t['activeVersion'] ?? null) !== null;
            $affected[] = [
                'templateId'     => $id,
                'schoolId'       => (string) ($t['schoolId'] ?? ''),
                'docType'        => (string) ($t['docType'] ?? ''),
                'status'         => (string) ($t['status'] ?? 'draft'),
                'appliedVersion' => $appliedVersion,
                'active'         => $isActive,
            ];
            $schools[(string) ($t['schoolId'] ?? '')] = true;
            if ($isActive) {
                $active++;
            }
        }

        /* ACTIVE templates first. They are what print points resolve today, so
           they are what a compliance officer must look at first — a stale draft
           harms nobody until someone publishes it. */
        usort($affected, function ($x, $y) {
            if ($x['active'] !== $y['active']) {
                return $y['active'] <=> $x['active'];
            }
            return [$x['schoolId'], $x['templateId']] <=> [$y['schoolId'], $y['templateId']];
        });

        return [
            'authorityId'    => $authorityId,
            'label'          => (string) ($a['label'] ?? $authorityId),
            'currentVersion' => $current,
            'evidence'       => $a['evidence']   ?? null,
            'verifiedOn'     => $a['verifiedOn'] ?? null,
            'affected'       => $affected,
            'schools'        => array_values(array_filter(array_keys($schools))),
            'activeCount'    => $active,
        ];
    }

    /* ================================================================== *
     *  Staleness
     * ================================================================== */

    /**
     * Is this authority's own verification older than its review interval?
     *
     * Distinct from "a template is behind": here the AUTHORITY itself has not
     * been re-checked against its source. `reviewMonths` defaults to 12 —
     * our own convention, not a statutory period, which is why it is
     * overridable per authority.
     */
    public function isStale(array $authority, ?int $nowTs = null): bool
    {
        $verified = $authority['verifiedOn'] ?? null;
        if (!$verified) {
            return true;   // never verified is the stalest state there is
        }
        $ts = strtotime((string) $verified);
        if ($ts === false) {
            return true;
        }
        $months = (int) ($authority['reviewMonths'] ?? 12);
        return (($nowTs ?? time()) - $ts) > ($months * 30 * 86400);
    }

    /**
     * The best evidence level across applied layers — never an average.
     *
     * Averaging would let two Level-C citations present as a Level-B fact. The
     * strongest source that actually applies is the honest answer, and the
     * weaker ones are still listed beside it.
     */
    public function bestEvidence(array $layers): ?string
    {
        $best = null;
        foreach ($layers as $l) {
            if (array_key_exists('applied', $l) && !$l['applied']) {
                continue;
            }
            $e = $l['evidence'] ?? null;
            if ($e === null || !isset(self::EVIDENCE_RANK[$e])) {
                continue;
            }
            if ($best === null || self::EVIDENCE_RANK[$e] > self::EVIDENCE_RANK[$best]) {
                $best = $e;
            }
        }
        return $best;
    }
}
