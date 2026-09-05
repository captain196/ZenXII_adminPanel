<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/Doc_contract.php';

/**
 * Doc_seeder — give a school the standard documents it should already have.
 *
 * ---------------------------------------------------------------------------
 * THE PROBLEM THIS SOLVES
 * ---------------------------------------------------------------------------
 * A new school's Document Engine library was EMPTY. Verified on a live tenant:
 * Harshit Public School had zero templates. Nothing in the codebase provisioned
 * any — `documentTemplates` was written only when a human opened the designer
 * (which has no navigation link), picked a starter card, and then proofed,
 * published and activated it. Five to seven correct decisions before a school
 * could print a transfer certificate at all.
 *
 * ---------------------------------------------------------------------------
 * WHAT A SEEDED TEMPLATE IS — the decision, and why
 * ---------------------------------------------------------------------------
 * A SNAPSHOT the school owns outright. Not a live link to a central template.
 *
 * The alternative — seeding a linked copy that receives central updates — reads
 * as tidier and is worse: a school that carefully adjusted the wording of its
 * own statutory document would find it silently rewritten by someone else.
 *
 * This module has faced that exact question twice and refused both horns both
 * times. `Doc_compliance` will not auto-invalidate a template when an authority
 * is revised — it produces a REPORT, because auto-acting "would take a school's
 * active certificate away without anyone deciding to." `Doc_block_service`
 * offers a shared-block update the school accepts or declines. Seeding follows
 * the same rule: the copy is the school's, and a later revision to a standard
 * template becomes an OFFER, never a silent overwrite.
 *
 * The cost is honest and stated: a school seeded today keeps today's wording
 * until somebody accepts an update. That is the price of not editing a school's
 * statutory documents behind its back, and it is the right side to err on.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS AND IS NOT SEEDED
 * ---------------------------------------------------------------------------
 * Only starters whose board/state gates the school satisfies — the SAME gates
 * `startersFor()` applies in the designer. A Kerala Form 5A is prescribed by
 * Kerala; seeding it into a school in Uttar Pradesh would hand that school a
 * statutory instrument its state does not prescribe.
 *
 * Seeded templates arrive as DRAFTS. They are deliberately NOT auto-published
 * and NOT auto-activated: publishing is the act that freezes a legal record and
 * activating is what every print point resolves. Neither should happen because
 * a page was loaded. A human still decides — but they now start from a complete
 * document instead of an empty gallery.
 */
class Doc_seeder
{
    const HEAD_COLLECTION = 'documentTemplates';

    /** @var array<int,array<string,mixed>> */
    private array $starters;

    /**
     * How a template gets created.
     *
     * A callable rather than a `Doc_template_service` handle, matching the
     * injection idiom the rest of this module already uses (`Doc_contract`,
     * `Doc_compliance` and `Doc_template_service` itself all take `store`
     * callables). It also states the dependency honestly: the seeder needs
     * exactly ONE capability — create a template — and should not be handed a
     * service that can also publish, activate and delete.
     *
     * @var callable(string,string,array,string):array
     */
    private $create;

    /**
     * @param array $params 'starters' and 'create' may be injected for tests;
     *        otherwise the generated catalogue and the live service are used.
     */
    public function __construct(array $params = [])
    {
        if (isset($params['starters'])) {
            $this->starters = $params['starters'];
        } else {
            $config = [];
            $path   = APPPATH . 'config/doc_starters.php';
            if (!is_file($path)) {
                throw new RuntimeException(
                    'Doc_seeder: application/config/doc_starters.php is missing. It is generated '
                    . 'from the designer — run: node tools/gen_doc_starters.js'
                );
            }
            require $path;
            $this->starters = $config['doc_starters'] ?? [];
        }
        if (!$this->starters) {
            throw new RuntimeException(
                'Doc_seeder: the starter catalogue is empty. Refusing to report "seeded 0 '
                . 'templates" as success — that is indistinguishable from a school that is '
                . 'already provisioned.'
            );
        }

        if (isset($params['create']) && is_callable($params['create'])) {
            $this->create = $params['create'];
        } else {
            $ci = &get_instance();
            $ci->load->library('doc_template_service', null, 'doctpl');
            $svc = $ci->doctpl;
            $this->create = static fn(string $schoolId, string $docType, array $seed, string $by)
                => $svc->create($schoolId, $docType, $seed, $by);
        }
    }

    /**
     * Which starters this school is entitled to, by the designer's own gates.
     *
     * @return array<int,array<string,mixed>>
     */
    public function eligible(?string $board, ?string $state): array
    {
        $norm = static fn($v) => strtolower(trim((string) $v));
        $out  = [];

        foreach ($this->starters as $s) {
            $boards = $s['boards'] ?? null;
            $states = $s['states'] ?? null;

            // Case- and whitespace-insensitive: the live data holds
            // "madhya pradesh" and "UTTAR PRADESH" for what the catalogue
            // spells "Kerala". An exact match would silently gate on casing.
            if (is_array($boards) && $boards
                && !in_array($norm($board), array_map($norm, $boards), true)) {
                continue;
            }
            if (is_array($states) && $states
                && !in_array($norm($state), array_map($norm, $states), true)) {
                continue;
            }
            $out[] = $s;
        }
        return $out;
    }

    /**
     * Seed the standard set into one school.
     *
     * IDEMPOTENT, and by document TYPE rather than by starter id: if the school
     * already has any template of a type, that type is skipped. Running twice
     * must not double the library, and a school that has already built its own
     * transfer certificate must not be handed a second one beside it.
     *
     * @param array<int|string,array<string,mixed>> $existing current head docs for the school
     * @return array{seeded:list<string>, skipped:list<string>, ineligible:list<string>}
     */
    public function seed(string $schoolId, ?string $board, ?string $state,
                         array $existing, string $by = ''): array
    {
        if ($schoolId === '') {
            throw new InvalidArgumentException('Doc_seeder: schoolId is required');
        }

        $haveTypes = [];
        foreach ($existing as $row) {
            $t = (string) ($row['docType'] ?? '');
            if ($t !== '') {
                $haveTypes[$t] = true;
            }
        }

        $eligible   = $this->eligible($board, $state);
        $eligibleId = array_column($eligible, 'id');
        $ineligible = array_values(array_diff(array_column($this->starters, 'id'), $eligibleId));

        $seeded = $skipped = [];

        foreach ($eligible as $s) {
            $docType = (string) $s['docType'];
            if (isset($haveTypes[$docType])) {
                $skipped[] = $s['id'];
                continue;
            }

            $tpl = $s['template'];
            ($this->create)($schoolId, $docType, [
                'name'             => (string) $s['name'],
                'page'             => $tpl['page']            ?? [],
                'header'           => $tpl['header']          ?? [],
                'footer'           => $tpl['footer']          ?? [],
                'objects'          => $tpl['objects']         ?? [],
                'languages'        => $tpl['languages']       ?? ['en'],
                'defaultLanguage'  => $tpl['defaultLanguage'] ?? 'en',
                'starterId'        => (string) $s['id'],
                'complianceLayers' => $tpl['complianceLayers'] ?? [],
            ], $by);

            // Claim the type immediately: two starters can share a docType
            // (tc_cbse and tc_plain are both transfer certificates) and a school
            // should be seeded ONE of each type, not one of each starter.
            $haveTypes[$docType] = true;
            $seeded[] = $s['id'];
        }

        return ['seeded' => $seeded, 'skipped' => $skipped, 'ineligible' => $ineligible];
    }
}
