<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * Certificate Designer — SPA shell.
 *
 * Ported from blueprints/certificates/design/prototype.html. Screens D0 (types),
 * D1 (gallery) and D2 (designer) all live here and are switched client-side;
 * the breadcrumb is the only back-navigation (UX_SPEC §2). The controller's
 * index/gallery/design actions all render this same view.
 *
 * EVERYTHING is wrapped in .zxdt. assets/css/doctemplates.css is scoped under
 * that class, because this ships a page of absolutely positioned divs into a
 * panel full of layout utilities and the .att-grid collision is the precedent
 * (UX_SPEC §14).
 *
 * No bundler — plain script includes, per the Chart.js precedent.
 *
 * Expects from the controller: $active_tab, $doc_type, $template_id,
 * $school_name, $session_year, $can_edit, $can_manage.
 */
$zxdtBoot = [
    'screen'      => $active_tab   ?? 'gallery',
    'docType'     => $doc_type     ?? '',
    'templateId'  => $template_id  ?? '',
    'schoolName'  => $school_name  ?? '',
    'sessionYear' => $session_year ?? '',
    'canEdit'     => (bool) ($can_edit   ?? false),
    'canManage'   => (bool) ($can_manage ?? false),
    'grade'       => (string) ($grade ?? 'view'),
    'csrfName'    => $this->security->get_csrf_token_name(),
    'csrfHash'    => $this->security->get_csrf_hash(),
    'base'        => rtrim(base_url(), '/') . '/doc_templates',
];
?>
<?php
/* CACHE-BUST BY FILE MTIME.
 *
 * These were plain URLs, so a browser that had loaded the designer once kept
 * serving it from cache no matter how many times the file changed underneath.
 * Someone testing a fix would be running the code from before it, hitting bugs
 * that no longer existed and reporting them in good faith — which is exactly
 * what happened: a save was refused by a tab running a build from before the
 * lockVersion fix, while the same action succeeded in a freshly loaded one.
 *
 * The E2E harness got this treatment hours earlier, for the same reason, and
 * this page was left out — so the ONE place it mattered to a person was the one
 * place still stale.
 *
 * mtime, not a hand-maintained version: a number nobody has to remember to
 * bump cannot be forgotten. */
$zxAsset = function (string $rel) {
    $abs = FCPATH . $rel;
    $v   = @filemtime($abs) ?: time();
    return base_url($rel) . '?v=' . $v;
};
?>
<link rel="stylesheet" href="<?= $zxAsset('assets/css/doctemplates.css') ?>">

<!-- Boot payload. The designer reads this instead of hardcoding anything.
     csrfHash travels here so api.js can attach the token to every POST — the
     routes are NOT in csrf_exclude_uris, and must not be (gate G0.7). -->
<script type="application/json" id="zxdt-boot"><?= json_encode($zxdtBoot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>

<!-- INSIDE .content-wrapper, like every other view in this panel.

     Without it, #zxdt-root was a direct child of .wrapper — a SIBLING of the
     fixed header and the fixed sidebar. Both are out of flow, so nothing pushed
     the designer down or across: it started at 0,0 and the panel chrome sat on
     top of it. The designer's own topbar (Proof PDF, Publish, History) rendered
     at y=0..52 underneath a 58px header and was completely unreachable, and the
     layers rail sat under the 248px sidebar.

     It looked like a z-index problem and was not; the element was simply in the
     wrong place in the document. -->
<div class="content-wrapper zxdt-wrap">
<div class="zxdt" id="zxdt-root">
  <div class="app">
    <header class="topbar">
      <div class="brand"><span class="brand__mark">Z</span><span>ZenXii</span></div>
      <nav class="crumb" id="crumb"></nav>
      <div class="spacer"></div>
      <div id="topActions" style="display:flex;align-items:center;gap:7px"></div>
      <!-- Only rendered where the inspector becomes a drawer (≤760px). Without
           it the inspector was unreachable at that width — see doctemplates.css -->
      <button class="btn btn--ghost btn--ico" id="inspBtn" title="Object properties">⚙</button>
      <button class="btn btn--ghost btn--ico" id="themeBtn" title="Day / night">◐</button>
    </header>

    <!-- screen 1 — document types -->
    <section class="screen is-on" id="screen-hub">
      <div class="doc-scroll"><div class="wrap">
        <div class="page-head">
          <div class="eyebrow">Documents</div>
          <h1>Document types</h1>
          <p>Design the certificate once. Every place that prints it — the office, the Teacher app, a parent's download — resolves the template you activate here.</p>
        </div>
        <div class="type-grid" id="typeGrid"></div>

        <div class="sec-label">Your own documents</div>
        <p class="note" style="margin-top:-4px">Anything the prescribed forms do not cover — a participation certificate, a concession letter, a gate pass. You name it, design it, and it behaves like every other document type: its own templates, its own active version.</p>
        <div class="type-grid" id="typeGridCustom"></div>

        <div class="sec-label">Not enabled for this school</div>
        <div class="type-grid" id="typeGridOff"></div>
        <p class="note">The research corpus holds 281 document types; enabling a prescribed one is data, not code. A school with no hostel never sees a hostel certificate.</p>
      </div></div>
    </section>

    <!-- screen 2 — gallery -->
    <section class="screen" id="screen-gallery">
      <div class="doc-scroll"><div class="wrap">
        <div class="page-head">
          <div class="eyebrow" id="galEyebrow">Transfer Certificate</div>
          <h1>Templates</h1>
          <p id="galSub"></p>
        </div>
        <div class="sec-label">Your templates</div>
        <div class="gal-grid" id="mineGrid"></div>
        <div class="sec-label" id="starterLabel">Starters — cloned into your school, never linked</div>
        <div class="gal-grid" id="starterGrid"></div>
        <p class="note"><b>Why these previews are schematic.</b> A real thumbnail means PDF&nbsp;→&nbsp;PNG, which needs imagick/ghostscript — unverified on the Ohio box. The card draws the object rectangles straight from the saved template instead: honest, instant, and required objects (red) are visible at a glance.</p>
      </div></div>
    </section>

    <!-- screen 3 — designer -->
    <section class="screen" id="screen-designer">
      <div class="dz">
        <nav class="tabstrip" id="tabstrip">
          <button data-pane="layers"  class="is-on" title="Layers"><i>▤</i><span>Layers</span></button>
          <button data-pane="insert"  title="Insert"><i>＋</i><span>Insert</span></button>
          <button data-pane="fields"  title="Merge fields"><i>❰❱</i><span>Fields</span></button>
          <button data-pane="blocks"  title="Reusable blocks"><i>◫</i><span>Blocks</span></button>
          <button data-pane="content" title="Content — read or edit the whole document as text"><i>¶</i><span>Content</span></button>
        </nav>

        <aside class="rail">
          <div class="rail__head" id="railHead">Layers</div>
          <div class="rail__body">
            <div class="rail__pane is-on" data-pane="layers"><div id="layerList"></div></div>
            <div class="rail__pane" data-pane="insert">
              <h4>Objects</h4>
              <div class="tool-grid">
                <button class="tool" data-add="text"><b>Text <em>T</em></b><span>rich · auto-grow</span></button>
                <button class="tool" data-add="table"><b>Table <em>B</em></b><span>label / value</span></button>
                <button class="tool" data-add="image"><b>Image <em>I</em></b><span>storage ref</span></button>
                <button class="tool" data-add="shape"><b>Rule <em>L</em></b><span>line · box</span></button>
                <button class="tool" data-add="qr"><b>QR <em>Q</em></b><span>verify slot</span></button>
                <button class="tool" data-add="pageNumber"><b>Page no.</b><span>footer only</span></button>
              </div>
              <h4>View</h4>
              <div class="tool-grid">
                <button class="tool" id="gridBtn"><b>Grid</b><span>5 mm</span></button>
                <button class="tool" id="anchorBtn"><b>Anchors</b><span>show chains</span></button>
              </div>
              <p class="note" style="margin-top:15px">Pick a tool, then drag on the page to place it. <span class="kbd">V</span> returns to move. Hold <span class="kbd">space</span> to pan, <span class="kbd">⌘</span>+scroll to zoom.</p>
            </div>
            <div class="rail__pane" data-pane="fields">
              <div class="hintline">Only the fields <b id="ctName">this document type</b> declares — <b id="ctCount">—</b> in its contract. There is no free-typed token: a placeholder resolving to nothing is a forgery vector, so <b>the picker is the contract</b>.</div>
              <div id="fieldList"></div>
            </div>
            <!-- Content pane: the document view of the template.
                 Edits content only — it cannot move, resize, reorder or delete
                 anything, so a clerk fixing a typo cannot break a statutory
                 layout by mis-dragging. See design/TEXT_EDITING_PROPOSAL.md. -->
            <div class="rail__pane" data-pane="content">
              <div class="seg seg--fill" id="contentMode" style="margin-bottom:10px">
                <button data-cmode="edit" class="is-on">Edit all</button>
                <button data-cmode="read">Read</button>
              </div>
              <div class="hintline" id="contentHint"></div>
              <div id="contentList"></div>
            </div>
            <div class="rail__pane" data-pane="blocks">
              <div class="blocklist" id="blockList"></div>
              <p class="note" style="margin-top:15px">A block edit reaches a <b>draft</b> immediately. A template that is <b>published and active</b> is never changed underneath you — it is <b>offered</b> the update, and accepting it creates a new draft version. The published snapshot is the legal record of what produced a certificate.</p>
              <button class="btn btn--sm" style="width:100%;margin-top:4px" id="simEdit">Simulate a letterhead edit</button>
            </div>
          </div>
        </aside>

        <div class="deskwrap">
          <div class="toolbar" id="toolbar">
            <button data-tool="move" class="is-on" title="Move — V">✥</button>
            <button data-tool="hand" title="Hand — H">✋</button>
            <span class="div"></span>
            <button data-tool="text" title="Text — T">T</button>
            <button data-tool="table" title="Table — B">▦</button>
            <button data-tool="image" title="Image — I">▣</button>
            <button data-tool="shape" title="Rule — L">━</button>
            <button data-tool="qr" title="QR — Q">▩</button>
          </div>
          <div class="desk" id="desk">
            <div class="stage" id="stage">
              <div class="ruler ruler--h" id="rulerH"></div>
              <div class="ruler ruler--v" id="rulerV"></div>
              <div class="page" id="page"></div>
            </div>
          </div>
        </div>

        <aside class="insp">
          <div class="insp__scroll">
            <div class="sect is-open" id="sectObj">
              <button class="sect__head" data-sect="sectObj"><span class="sect__caret">▶</span><span>Object</span><span class="spacer"></span><span id="objBadge"></span></button>
              <div class="sect__body" id="objBody"></div>
            </div>
            <div class="sect" id="sectPage">
              <button class="sect__head" data-sect="sectPage"><span class="sect__caret">▶</span><span>Page</span></button>
              <div class="sect__body" id="pageBody"></div>
            </div>
            <!-- CLOSED by default. This opened onto the full statutory text —
                 several paragraphs of the RTE Act — before you had selected
                 anything, so the first thing the panel said was the thing you
                 were least likely to need. The summary line and its status dot
                 stay visible; the reasoning is one click away. -->
            <div class="sect" id="sectComp">
              <button class="sect__head" data-sect="sectComp"><span class="sect__caret">▶</span><span>Compliance</span><span class="spacer"></span><span id="compBadge"></span></button>
              <div class="sect__body" style="padding:0" id="compBody"></div>
            </div>
          </div>
        </aside>
      </div>

      <div class="statusbar">
        <span class="sb">Zoom
          <button class="zoombtn" id="zoomOut">−</button><b id="zoomVal">70%</b>
          <button class="zoombtn" id="zoomIn">+</button><button class="zoombtn" id="zoomFit">fit</button>
        </span>
        <span class="sb" id="sbSel">No selection</span>
        <span class="spacer"></span>
        <!-- VIEW CONTROLS LIVE HERE, not in the header.
             Language, sample data and translation coverage change what you are
             LOOKING AT; History, Proof and Publish change the document. Those
             are different kinds of thing and they were sitting side by side at
             the same weight in the header, which is most of why that bar felt
             crowded. This is the bar that already owns view state — zoom,
             selection, findings — so they belong here. -->
        <span class="viewstrip" id="viewStrip"></span>
        <span class="sb">Issue as <button class="zoombtn" id="dupToggle"></button></span>
        <span class="sb" id="sbData"></span>
        <span class="sb" id="sbWarn"></span>
        <button class="zoombtn" id="keysBtn">⌨ shortcuts</button>
        <span class="sb" id="sbSave"></span>
      </div>
    </section>
  </div>

  <div class="ctxbar" id="ctxbar"></div>
  <div class="ctxmenu" id="ctxmenu"></div>
  <div class="scrim" id="scrim">
    <div class="modal" id="modal">
      <div class="modal__head"><div><h3 id="mTitle"></h3><p id="mSub"></p></div><button class="x" data-close>✕</button></div>
      <div class="modal__body" id="mBody"></div>
      <div class="modal__foot" id="mFoot"></div>
    </div>
  </div>
  <div class="toast" id="toast"></div>
</div><!-- /.zxdt -->
</div><!-- /.content-wrapper -->

<script src="<?= $zxAsset('assets/js/doctemplates/designer.js') ?>"></script>
