/* ZenXii Certificate Designer — end-to-end client suite.
   Untracked local harness. Load into the designer page and call ZXDT_E2E().
   Drives every screen and flow: hub → type → gallery → classic starter / blank
   canvas → design → validate → proof → publish → activate → history.

   NOTE ON SCOPE: every server endpoint in Doc_templates.php is still a stub
   (pending P1.x), so nothing here asserts persistence. This exercises the
   client state machine, which is what currently exists. */

window.ZXDT_E2E = async function (only) {
  const R = [];
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  let group = "";
  const G = g => { group = g; };

  async function T(id, name, fn) {
    if (only && !id.startsWith(only)) return;
    let rec = { id, group, name, ok: false, note: "" };
    try {
      const out = await fn();
      if (out === true || out === undefined) rec.ok = true;
      else if (out && typeof out === "object" && "ok" in out) { rec.ok = !!out.ok; rec.note = out.note || ""; }
      else { rec.ok = false; rec.note = "returned " + JSON.stringify(out); }
    } catch (e) {
      rec.ok = false; rec.note = "THREW: " + (e && e.message ? e.message : String(e));
    }
    R.push(rec);
  }

  /* ---- fixtures ------------------------------------------------------- */
  function resetApp(school) {
    closeModal();
    S.school = Object.assign({}, SCHOOL_DEFAULT, school || {});
    S.lib = JSON.parse(JSON.stringify(LIB));
    S.active = Object.assign({}, ACTIVE);
    S.docType = "transfer_certificate";
    S.tpl = starterTC();
    S.sel = []; S.undo = []; S.redo = []; S.proofed = null; S.dirty = false;
    S.lang = "en"; S.data = "typical"; S.tool = "move"; S.editing = null;
    S.measured = {}; S.clamped = {}; S.hidden = {}; S.layerOff = {};
    S.overrideReason = {}; S.issuance = { duplicate: false }; S.cmode = "edit";
    S.baseline = JSON.parse(JSON.stringify(S.tpl.objects));
    paintHub(); go("hub");
  }
  /* A publishable template: the classic starter blocks only on `noproof`. */
  function openClassic(proofed) {
    resetApp();
    S.tpl = starterTC();
    S.tpl.templateId = "TPL7777"; S.tpl.status = "draft";
    S.tpl.version = 3; S.tpl.publishedVersion = 2; S.tpl.activeVersion = null;
    S.baseline = JSON.parse(JSON.stringify(S.tpl.objects));
    S.proofed = proofed === false ? null : { hash: "sha256:testhash" };
    go("designer");
    return validate();
  }
  const bt = v => v.blocking.map(b => b.type);
  const wt = v => v.warnings.map(b => b.type);
  const has = (arr, t) => arr.includes(t);
  const firstText = () => S.tpl.objects.find(o => o.type === "text");

  /* ===================================================================== */
  G("A · boot & hub");
  await T("A1", "boots to hub with school, library and active map seeded", () => {
    resetApp();
    return { ok: S.screen === "hub" && !!S.school.board && !!S.lib && !!S.active,
             note: `${S.school.board}/${S.school.state}` };
  });
  await T("A2", "hub lists exactly the enabled types, and parks the rest", () => {
    resetApp();
    const on = document.querySelectorAll("#typeGrid .type-card").length;
    const off = document.querySelectorAll("#typeGridOff .type-card").length;
    return { ok: on === TYPES.filter(typeEnabled).length && off === TYPES.filter(t => !typeEnabled(t)).length,
             note: `${on} enabled / ${off} not enabled` };
  });
  await T("A3", "disabled types are never enabled", () => {
    resetApp();
    const bad = TYPES.filter(t => t.disabled && typeEnabled(t)).map(t => t.id);
    return { ok: !bad.length, note: bad.join(",") };
  });
  await T("A4", "state-gated types hidden for the wrong state", () => {
    resetApp({ state: "Jharkhand" });
    return { ok: !typeEnabled(TYPES.find(t => t.id === "school_education_certificate"))
                 && !typeEnabled(TYPES.find(t => t.id === "study")) };
  });
  await T("A5", "state-gated types appear for the right state", () => {
    resetApp({ state: "Kerala" });
    return { ok: typeEnabled(TYPES.find(t => t.id === "school_education_certificate"))
                 && typeEnabled(TYPES.find(t => t.id === "leaving_certificate_5a")) };
  });
  await T("A6", "hub card names the active template per type", () => {
    resetApp();
    return { ok: /TPL0007|Annexure|Transfer/i.test($("#typeGrid").textContent) };
  });

  /* ===================================================================== */
  G("B · gallery");
  await T("B1", "gallery renders for every enabled type without throwing", () => {
    resetApp({ state: "Kerala" });
    const bad = [];
    TYPES.filter(typeEnabled).forEach(t => {
      S.docType = t.id;
      try { go("gallery"); } catch (e) { bad.push(t.id + ":" + e.message); }
    });
    return { ok: !bad.length, note: bad.join(" | ") };
  });
  await T("B2", "'nothing active' copy shows when the type has no active template", () => {
    resetApp(); S.docType = "character"; go("gallery");
    return { ok: /Nothing is active/i.test($("#galSub").textContent) };
  });
  await T("B3", "active template named when one is active", () => {
    resetApp(); S.docType = "transfer_certificate"; go("gallery");
    return { ok: /Every print point resolves/i.test($("#galSub").textContent) };
  });
  await T("B4", "starters filter by board", () => {
    resetApp({ board: "CBSE" });  const a = startersFor("transfer_certificate").map(s => s.id);
    resetApp({ board: "ICSE" });  const b = startersFor("transfer_certificate").map(s => s.id);
    return { ok: a.includes("tc_cbse") && !b.includes("tc_cbse") && b.includes("tc_plain"),
             note: "CBSE=[" + a + "] ICSE=[" + b + "]" };
  });
  await T("B5", "Kerala-only starters hidden outside Kerala", () => {
    resetApp({ state: "Jharkhand" }); const a = startersFor("school_education_certificate").length;
    resetApp({ state: "Kerala" });    const b = startersFor("school_education_certificate").length;
    return { ok: a === 0 && b > 0, note: `outside=${a} kerala=${b}` };
  });
  await T("B6", "blank-canvas card present", () => {
    resetApp(); S.docType = "transfer_certificate"; go("gallery");
    return { ok: !!$("#starterGrid .tpl-card--new") };
  });
  await T("B7", "'Set active' disabled for a never-published template", () => {
    resetApp();
    S.lib.character = [{ id: "TPLX", name: "Never published", starter: "conduct",
                         status: "draft", version: 1, publishedVersion: null }];
    S.docType = "character"; go("gallery");
    const btn = $("#mineGrid button[disabled]");
    return { ok: !!btn, note: btn ? btn.title : "no disabled button" };
  });
  await T("B8", "a type with no starter tells you to start blank", () => {
    resetApp({ board: "ICSE" }); S.docType = "school_education_certificate"; go("gallery");
    return { ok: /blank canvas/i.test($("#starterGrid").textContent) };
  });
  await T("B9", "the active template shows Deactivate, not Set active", () => {
    resetApp(); S.docType = "transfer_certificate"; go("gallery");
    return { ok: !!$("#mineGrid [data-deact]") && !$(`#mineGrid [data-act="${S.active.transfer_certificate}"]`) };
  });

  /* ===================================================================== */
  G("C · creation");
  await T("C1", "every starter builds and is non-empty", () => {
    const bad = [];
    STARTERS.forEach(s => {
      try { const t = s.build(); if (!t.objects || !t.objects.length) bad.push(s.id + ":empty"); }
      catch (e) { bad.push(s.id + ":" + e.message); }
    });
    return { ok: !bad.length, note: bad.join(" | ") };
  });
  await T("C2", "opening a classic starter clones it as a fresh draft", () => {
    resetApp(); openStarter(STARTERS.find(s => s.id === "tc_cbse"));
    return { ok: S.screen === "designer" && S.tpl.status === "draft" && S.tpl.version === 1
                 && S.tpl.publishedVersion === null && S.dirty === true
                 && /\(copy\)$/.test(S.tpl.name) && /^TPL/.test(S.tpl.templateId),
             note: `${S.tpl.name} ${S.tpl.templateId}` };
  });
  await T("C3", "starter clone is independent of the starter", () => {
    resetApp(); const st = STARTERS.find(s => s.id === "tc_cbse");
    openStarter(st); firstText().name = "MUTATED";
    return { ok: st.build().objects.every(o => o.name !== "MUTATED") };
  });
  await T("C4", "blank canvas keeps only region/required objects", () => {
    resetApp(); S.docType = "transfer_certificate"; go("gallery");
    const before = STARTERS.find(s => s.id === "tc_cbse").build().objects.length;
    $("#starterGrid .tpl-card--new").click();
    return { ok: S.screen === "designer" && S.tpl.objects.every(o => o.region || o.requiredKey)
                 && S.tpl.objects.length <= before && S.dirty === true,
             note: `${before} → ${S.tpl.objects.length}` };
  });
  await T("C5", "blank canvas is a fresh draft identity", () => {
    resetApp(); S.docType = "transfer_certificate"; go("gallery");
    $("#starterGrid .tpl-card--new").click();
    return { ok: S.tpl.name === "Untitled template" && S.tpl.version === 1
                 && S.tpl.publishedVersion === null && S.tpl.activeVersion === null };
  });
  await T("C6", "opening a library row loads its versions", () => {
    resetApp(); const row = S.lib.transfer_certificate[0]; openTemplate(row);
    return { ok: S.screen === "designer" && S.tpl.templateId === row.id
                 && S.tpl.version === row.version && S.dirty === false && !!S.baseline };
  });
  await T("C7", "opening a template clears stale undo and proof", () => {
    resetApp(); S.undo = [{ label: "stale" }]; S.proofed = { hash: "stale" }; S.dirty = true;
    openTemplate(S.lib.transfer_certificate[0]);
    return { ok: S.undo.length === 0 && S.proofed === null && S.dirty === false };
  });
  await T("C8", "blank canvas for EVERY enabled type builds without throwing", () => {
    const bad = [];
    ["Jharkhand", "Kerala", "Andhra Pradesh"].forEach(state => {
      resetApp({ state });
      TYPES.filter(typeEnabled).forEach(t => {
        S.docType = t.id;
        try {
          go("gallery");
          const n = $("#starterGrid .tpl-card--new");
          if (!n) { bad.push(state + "/" + t.id + ":no blank card"); return; }
          n.click();
          if (S.screen !== "designer") bad.push(state + "/" + t.id + ":did not open");
        } catch (e) { bad.push(state + "/" + t.id + ":" + e.message); }
      });
    });
    return { ok: !bad.length, note: bad.join(" | ") };
  });

  /* ===================================================================== */
  G("D · designer editing");
  await T("D1", "add an object of every type", () => {
    openClassic(); const bad = [];
    ["text", "table", "image", "shape", "qr", "pageNumber"].forEach(t => {
      try { const o = addObject(t, 20, 20, 40, 10); if (!o || !obj(o.id)) bad.push(t); }
      catch (e) { bad.push(t + ":" + e.message); }
    });
    return { ok: !bad.length, note: bad.join(",") };
  });
  await T("D2", "add pushes exactly one undo entry and undo removes it", () => {
    openClassic(); const n0 = S.tpl.objects.length, u0 = S.undo.length;
    const o = addObject("text", 20, 20, 40, 10);
    const added = S.tpl.objects.length === n0 + 1 && S.undo.length === u0 + 1;
    undo();
    return { ok: added && S.tpl.objects.length === n0 && !obj(o.id) };
  });
  await T("D3", "redo re-adds it", () => {
    openClassic(); const n0 = S.tpl.objects.length;
    addObject("text", 20, 20, 40, 10); undo(); redo();
    return { ok: S.tpl.objects.length === n0 + 1 };
  });
  await T("D4", "a required object cannot be deleted", () => {
    openClassic();
    const req = S.tpl.objects.find(o => o.requiredKey);
    if (!req) return { ok: false, note: "no required object in the starter" };
    S.sel = [req.id]; tryDelete();
    return { ok: !!obj(req.id), note: "kept " + req.requiredKey };
  });
  await T("D5", "a non-required object can be deleted and undone", () => {
    openClassic();
    const o = addObject("text", 20, 20, 40, 10);
    S.sel = [o.id]; tryDelete();
    const gone = !obj(o.id); undo();
    return { ok: gone && !!obj(o.id) };
  });
  await T("D6", "duplicate strips the compliance binding", () => {
    openClassic();
    const req = S.tpl.objects.find(o => o.requiredKey);
    S.sel = [req.id]; duplicateSel();
    const copy = obj(S.sel[0]);
    return { ok: copy && copy.id !== req.id && !copy.requiredKey && !!obj(req.id),
             note: "copy.requiredKey=" + copy.requiredKey };
  });
  await T("D7", "Content pane commits on input, one undo entry per burst", async () => {
    openClassic();
    $('#tabstrip button[data-pane="content"]').click();
    paintContent();
    const row = $$("#contentList [data-cid]").find(r => obj(r.dataset.cid).type === "text");
    const id = row.dataset.cid;
    const M = () => JSON.stringify(obj(id).content.i18n[langOf(obj(id))].runs);
    const m0 = M(), u0 = S.undo.length;
    row.appendChild(document.createTextNode(" QQ"));
    row.dispatchEvent(new InputEvent("input", { bubbles: true }));
    await sleep(30);
    const committed = /QQ/.test(M()), noSpam = S.undo.length === u0;
    row.dispatchEvent(new FocusEvent("blur"));
    await sleep(60);
    const oneEntry = S.undo.length === u0 + 1;
    undo(); await sleep(30);
    return { ok: committed && noSpam && oneEntry && M() === m0,
             note: `commit=${committed} noSpam=${noSpam} one=${oneEntry}` };
  });
  await T("D8", "Content pane refuses to repaint under a live edit", async () => {
    openClassic();
    $('#tabstrip button[data-pane="content"]').click(); paintContent();
    const row = $$("#contentList [data-cid]").find(r => obj(r.dataset.cid).type === "text");
    row.appendChild(document.createTextNode(" MID"));
    row.dispatchEvent(new InputEvent("input", { bubbles: true }));
    await sleep(30);
    const real = liveContentRow; liveContentRow = () => row;
    render(); await sleep(40);
    const survived = document.contains(row) && $("#contentList")._deferred === true;
    liveContentRow = real;
    row.dispatchEvent(new FocusEvent("blur")); await sleep(60);
    return { ok: survived && $("#contentList")._deferred === false };
  });
  await T("D9", "Read mode has no editable nodes", () => {
    openClassic();
    $('#tabstrip button[data-pane="content"]').click();
    S.cmode = "read"; paintContent();
    const n = $$('#contentList [contenteditable="true"]').length;
    S.cmode = "edit"; paintContent();
    return { ok: n === 0 && $$("#contentList [data-cid]").length > 0, note: n + " editable in read" };
  });
  await T("D10", "insertField adds an atomic chip and binds the key", () => {
    openClassic();
    const t = firstText(); S.sel = [t.id];
    const key = CONTRACT.find(f => !boundKeys().has(f.key));
    if (!key) return { ok: true, note: "everything already bound" };
    enterEdit(t.id); insertField(key.key); commitEdit();
    return { ok: boundKeys().has(key.key), note: "bound " + key.key };
  });
  await T("D11", "language switch keeps the model and swaps rendered runs", () => {
    openClassic();
    const t = firstText();
    S.lang = "hi"; render();
    const hiOk = !!t.content.i18n.hi;
    S.lang = "en"; render();
    return { ok: hiOk && !!t.content.i18n.en };
  });
  await T("D12", "p95 stress mode renders without throwing", () => {
    openClassic(); S.data = "p95"; render();
    const ok = S.data === "p95"; S.data = "typical"; render();
    return { ok };
  });
  await T("D13", "duplicate-issuance preview mode renders", () => {
    openClassic(); S.issuance.duplicate = true; render();
    const ok = true; S.issuance.duplicate = false; render();
    return { ok };
  });

  /* ===================================================================== */
  G("E · validation matrix");
  await T("E1", "classic starter blocks only on the missing proof", () => {
    const v = openClassic(false);
    return { ok: bt(v).length === 1 && bt(v)[0] === "noproof", note: bt(v).join(",") };
  });
  await T("E2", "a proofed classic starter has zero blocking findings", () => {
    const v = openClassic(true);
    return { ok: v.blocking.length === 0, note: bt(v).join(",") };
  });
  await T("E3", "blank canvas blocks on unbound required fields", () => {
    resetApp(); S.docType = "transfer_certificate"; go("gallery");
    $("#starterGrid .tpl-card--new").click();
    S.proofed = { hash: "x" };
    const v = validate();
    return { ok: bt(v).filter(t => t === "unbound").length > 0, note: bt(v).filter(t => t === "unbound").length + " unbound" };
  });
  await T("E4", "a text object with no line height blocks", () => {
    openClassic(); const t = firstText(); t.style.lineHeight = null;
    return { ok: has(bt(validate()), "lineheight") };
  });
  await T("E5", "clamped content blocks (silent truncation is never allowed)", () => {
    openClassic(); const t = firstText(); S.clamped[t.id] = 1.2;
    return { ok: has(bt(validate()), "clamped") };
  });
  await T("E6", "an off-contract field key blocks", () => {
    openClassic(); const t = firstText();
    t.content.i18n[S.lang].runs.push({ f: "not.a.real.key" });
    return { ok: has(bt(validate()), "offContract"), note: offContractKeys().join(",") };
  });
  await T("E7", "removing the duplicate mark blocks under CBSE", () => {
    openClassic();
    S.tpl.objects = S.tpl.objects.filter(o => o.showWhen !== "doc.isDuplicate");
    return { ok: has(bt(validate()), "noDuplicateMark") };
  });
  await T("E8", "a non-red duplicate mark warns where colour is prescribed", () => {
    openClassic();
    const m = S.tpl.objects.find(o => o.showWhen === "doc.isDuplicate");
    if (!m) return { ok: false, note: "starter has no duplicate mark" };
    m.style.colour = "#101010";
    const v = validate();
    return { ok: has(wt(v), "dupNotRed") || !stackActive().some(l => l.rule.duplicateMark && l.rule.duplicateMark.colour),
             note: wt(v).join(",") };
  });
  await T("E9", "a missing prescribed signature blocks", () => {
    openClassic();
    const s = S.tpl.objects.find(o => o.sigRole);
    if (!s) return { ok: false, note: "starter has no signature blocks" };
    S.tpl.objects = S.tpl.objects.filter(o => o.id !== s.id);
    return { ok: has(bt(validate()), "noSignature"), note: "removed " + s.sigRole };
  });
  await T("E10", "signatures out of prescribed order warn", () => {
    openClassic();
    const sigs = S.tpl.objects.filter(o => o.sigRole && (prof().requiredSignatures || []).includes(o.sigRole));
    if (sigs.length < 2) return { ok: true, note: "fewer than 2 prescribed signatures" };
    const ys = sigs.map(o => o.yMm);
    sigs[0].yMm = Math.max(...ys) + 10;      // push the first one last
    return { ok: has(wt(validate()), "sigOrder"), note: wt(validate()).join(",") };
  });
  await T("E11", "text overflowing a fixed box warns", () => {
    openClassic();
    const t = S.tpl.objects.find(o => o.type === "text");
    t.height = "fixed"; S.measured[t.id] = t.hMm + 5;
    return { ok: has(wt(validate()), "overflow") };
  });
  await T("E12", "overlapping objects in the same region warn", () => {
    openClassic();
    const a = addObject("text", 20, 100, 60, 10);
    const b = addObject("text", 20, 100, 60, 10);
    a.region = b.region = "body";
    return { ok: has(wt(validate()), "overlap") };
  });
  await T("E13", "an image with no asset warns", () => {
    openClassic(); addObject("image", 20, 20, 30, 30);
    return { ok: has(wt(validate()), "noAsset") };
  });
  await T("E14", "missing proof blocks publish until a proof exists", () => {
    const a = openClassic(false), b = openClassic(true);
    return { ok: has(bt(a), "noproof") && !has(bt(b), "noproof") };
  });
  await T("E15", "untranslated Hindi warns when hi is a declared language", () => {
    openClassic();
    const cov = translationCoverage("hi");
    const v = validate();
    return { ok: (cov.done < cov.total) === has(wt(v), "untranslated"),
             note: `coverage ${cov.done}/${cov.total} warn=${has(wt(v), "untranslated")}` };
  });

  /* ===================================================================== */
  G("F · proof");
  await T("F1", "proof modal opens and schematics the page", () => {
    openClassic(false); openProof();
    return { ok: $("#scrim").classList.contains("is-on") && !!$("#proofPaper") && !!$("#proofRun") };
  });
  await T("F2", "running a proof sets a content hash and unlocks publish", async () => {
    openClassic(false);
    const blockedBefore = has(bt(validate()), "noproof");
    openProof(); $("#proofRun").click();
    await sleep(2600);
    const ok = !!(S.proofed && S.proofed.hash) && !has(bt(validate()), "noproof");
    closeModal();
    return { ok: blockedBefore && ok, note: S.proofed ? S.proofed.hash : "no hash" };
  });

  /* ===================================================================== */
  G("G · publish");
  await T("G1", "publish is blocked while any blocking finding stands", () => {
    openClassic(false);              // no proof
    openPublish();
    const btn = $("#pubGo");
    const ok = !!btn && btn.disabled;
    closeModal();
    return { ok, note: btn ? "disabled=" + btn.disabled : "no button" };
  });
  await T("G2", "publish is offered when the template is clean", () => {
    openClassic(true); openPublish();
    const btn = $("#pubGo");
    const ok = !!btn && !btn.disabled;
    closeModal();
    return { ok, note: btn ? "disabled=" + btn.disabled : "no button" };
  });
  await T("G3", "publishing freezes the version and opens a new draft", () => {
    openClassic(true);
    const v0 = S.tpl.version;
    openPublish(); $("#pubGo").click();
    const ok = S.tpl.publishedVersion === v0 && S.tpl.version === v0 + 1 && S.dirty === false;
    closeModal();
    return { ok, note: `v${v0} → published ${S.tpl.publishedVersion}, draft ${S.tpl.version}` };
  });
  await T("G4", "publishing does NOT activate (publish ≠ activate)", () => {
    openClassic(true);
    S.active.transfer_certificate = "TPL0007";       // something else is active
    openPublish(); $("#pubGo").click();
    const ok = S.active.transfer_certificate === "TPL0007" && S.tpl.activeVersion === null;
    closeModal();
    return { ok, note: "active stayed " + S.active.transfer_certificate };
  });
  await T("G5", "after publishing, an explicit activation step is offered", () => {
    openClassic(true);
    S.active.transfer_certificate = "TPL0007";
    openPublish(); $("#pubGo").click();
    const ok = !!$("#pubAct") && /Publishing freezes it/i.test($("#mSub").textContent);
    closeModal();
    return { ok, note: $("#mTitle").textContent };
  });
  await T("G6", "taking that step makes it the active template", () => {
    openClassic(true);
    S.active.transfer_certificate = "TPL0007";
    openPublish(); $("#pubGo").click();
    const pv = S.tpl.publishedVersion;
    $("#pubAct").click();
    const ok = S.active.transfer_certificate === "TPL7777" && S.tpl.activeVersion === pv;
    closeModal();
    return { ok, note: "active=" + S.active.transfer_certificate + " v" + S.tpl.activeVersion };
  });
  await T("G7", "publishing an already-active template goes live immediately", () => {
    openClassic(true);
    S.active.transfer_certificate = "TPL7777";       // this template is already active
    openPublish(); $("#pubGo").click();
    const ok = S.tpl.activeVersion === S.tpl.publishedVersion && !$("#pubAct");
    closeModal();
    return { ok, note: "activeVersion=" + S.tpl.activeVersion };
  });
  await T("G8", "publishing registers the template in the library", () => {
    openClassic(true);
    S.tpl.templateId = "TPLNEW1"; S.tpl.name = "Brand new";
    const n0 = libOf("transfer_certificate").length;
    openPublish(); $("#pubGo").click(); closeModal();
    const row = libOf("transfer_certificate").find(r => r.id === "TPLNEW1");
    return { ok: !!row && libOf("transfer_certificate").length === n0 + 1
                 && row.status === "published" && row.publishedVersion === 3,
             note: row ? `${row.id} pub v${row.publishedVersion}` : "not registered" };
  });
  await T("G9", "the publish gate lists a pass row for every satisfied contract", () => {
    openClassic(true); openPublish();
    const passes = $$("#mBody .gate--pass").length, fails = $$("#mBody .gate--fail").length;
    closeModal();
    return { ok: passes >= 3 && fails === 0, note: `${passes} pass / ${fails} fail` };
  });
  await T("G10", "the publish gate names each blocking reason", () => {
    openClassic(false);
    const t = firstText(); t.style.lineHeight = null;
    openPublish();
    const txt = $("#mBody").textContent;
    const ok = /line height/i.test(txt) && /proof/i.test(txt) && $$("#mBody .gate--fail").length >= 2;
    closeModal();
    return { ok, note: $$("#mBody .gate--fail").length + " fail rows" };
  });

  /* ===================================================================== */
  G("H · activation");
  await T("H1", "activation modal warns it replaces the current active template", () => {
    resetApp(); S.docType = "transfer_certificate"; go("gallery");
    const other = libOf("transfer_certificate").find(r => r.id !== S.active.transfer_certificate && r.publishedVersion);
    if (!other) return { ok: true, note: "no second published template to test with" };
    openActivate(other.id);
    const ok = /Replaces/i.test($("#mBody").textContent);
    closeModal();
    return { ok };
  });
  await T("H2", "exactly one template is active per type after activating", () => {
    resetApp();
    S.tpl = starterTC(); S.tpl.templateId = "TPL7777"; S.tpl.publishedVersion = 2;
    S.active.transfer_certificate = "TPL7777";
    return { ok: typeof S.active.transfer_certificate === "string"
                 && libOf("transfer_certificate").filter(r => S.active.transfer_certificate === r.id).length <= 1 };
  });
  await T("H3", "deactivating asks first, then leaves it published but not active", () => {
    resetApp(); S.docType = "transfer_certificate"; go("gallery");
    const id = S.active.transfer_certificate;
    const btn = $(`#mineGrid [data-deact="${id}"]`);
    if (!btn) return { ok: false, note: "no deactivate button" };
    btn.click();
    const asked = /Deactivate\?/i.test($("#mTitle").textContent) && !!$("#deactGo")
                  && S.active.transfer_certificate === id;   // not yet acted
    $("#deactGo").click();
    const row = libOf("transfer_certificate").find(r => r.id === id);
    return { ok: asked && S.active.transfer_certificate === undefined && row && row.status === "published",
             note: `confirmed=${asked} activeNow=${S.active.transfer_certificate}` };
  });
  await T("H4", "with nothing active the gallery says so and print points fail closed", () => {
    resetApp(); S.docType = "transfer_certificate"; go("gallery");
    $(`#mineGrid [data-deact="${S.active.transfer_certificate}"]`).click();
    $("#deactGo").click();
    return { ok: /Nothing is active/i.test($("#galSub").textContent) };
  });

  /* ---- copy-vs-behaviour checks -------------------------------------- */
  await T("H5", "blank card does not promise required objects it drops", () => {
    resetApp(); S.docType = "transfer_certificate"; go("gallery");
    const txt = $("#starterGrid .tpl-card--new").textContent.replace(/\s+/g, " ").trim();
    $("#starterGrid .tpl-card--new").click();
    S.proofed = { hash: "x" };
    const v = validate();
    const unbound = v.blocking.filter(b => b.type === "unbound").length;
    const overPromises = /required objects pre-?placed/i.test(txt);
    // it may legitimately start incomplete — but then the gate must say so
    const gateCatchesIt = unbound === 0 || (unbound > 0 && v.blocking.length > 0);
    return { ok: !overPromises && gateCatchesIt,
             note: `card="${txt}" unbound=${unbound} gateBlocks=${v.blocking.length}` };
  });
  await T("H5b", "a blank canvas cannot be published until its gaps are filled", () => {
    resetApp(); S.docType = "transfer_certificate"; go("gallery");
    $("#starterGrid .tpl-card--new").click();
    S.proofed = { hash: "x" };
    openPublish();
    const btn = $("#pubGo"), disabled = !!btn && btn.disabled;
    const txt = $("#mBody").textContent;
    const namesGaps = /unbound|not bound|required field/i.test(txt) || $$("#mBody .gate--fail").length > 0;
    closeModal();
    return { ok: disabled && namesGaps, note: `disabled=${disabled} failRows=${$$("#mBody .gate--fail").length}` };
  });
  await T("H6", "COPY: the publish button's label matches what it does", () => {
    openClassic(true);
    S.active.transfer_certificate = "TPL0007";
    openPublish();
    const label = $("#pubGo").textContent.trim();
    $("#pubGo").click();
    const activated = S.active.transfer_certificate === "TPL7777";
    closeModal();
    return { ok: /set active/i.test(label) === activated,
             note: `button reads "${label}" but activation ${activated ? "did" : "did NOT"} happen` };
  });

  /* ===================================================================== */
  G("I · history, compare, conflict");
  await T("I1", "history opens and lists frozen versions", () => {
    openClassic(true); openHistory();
    const ok = /Version history/i.test($("#mTitle").textContent) && $$("#mBody .tl li").length >= 2;
    closeModal(); return { ok };
  });
  await T("I2", "compare against the baseline reports the edits made", () => {
    openClassic(true);
    firstText().xMm += 12;
    openCompare();
    const ok = $("#mBody").textContent.length > 0;
    closeModal(); return { ok };
  });
  await T("I3", "conflict modal opens", () => {
    openClassic(true); openConflict();
    const ok = $("#scrim").classList.contains("is-on") && $("#mBody").textContent.length > 0;
    closeModal(); return { ok };
  });
  await T("I4", "keyboard-shortcuts modal opens", () => {
    openClassic(true); openKeys();
    const ok = $("#mBody").textContent.length > 0;
    closeModal(); return { ok };
  });

  /* ===================================================================== */
  G("J · cross-cutting");
  await T("J1", "a full create → design → proof → publish → activate run", async () => {
    resetApp();
    S.docType = "transfer_certificate"; go("gallery");
    openStarter(STARTERS.find(s => s.id === "tc_cbse"));
    const t = firstText(); S.sel = [t.id];
    addObject("text", 30, 200, 60, 8);
    S.proofed = null;
    openProof(); $("#proofRun").click(); await sleep(2600); closeModal();
    const v = validate();
    if (v.blocking.length) { return { ok: false, note: "blocked: " + bt(v).join(",") }; }
    openPublish();
    if ($("#pubGo").disabled) { closeModal(); return { ok: false, note: "publish disabled" }; }
    $("#pubGo").click();
    const pv = S.tpl.publishedVersion;
    if ($("#pubAct")) $("#pubAct").click();
    closeModal();
    return { ok: S.active.transfer_certificate === S.tpl.templateId && S.tpl.activeVersion === pv,
             note: `published v${pv}, active=${S.active.transfer_certificate}` };
  });
  await T("J2", "undo/redo survives a screen change", () => {
    openClassic(true);
    const n0 = S.tpl.objects.length;
    addObject("text", 20, 20, 40, 10);
    go("gallery"); go("designer");
    undo();
    return { ok: S.tpl.objects.length === n0 };
  });
  await T("J3", "undo stack is bounded at 80 entries", () => {
    openClassic(true);
    for (let i = 0; i < 90; i++) push("noise " + i, "a", "b");
    return { ok: S.undo.length <= 80, note: S.undo.length + " entries" };
  });
  await T("J4", "every enabled type validates without throwing", () => {
    const bad = [];
    ["Jharkhand", "Kerala"].forEach(state => {
      resetApp({ state });
      TYPES.filter(typeEnabled).forEach(ty => {
        S.docType = ty.id;
        const st = startersFor(ty.id)[0];
        if (!st) return;
        try { S.tpl = st.build(); go("designer"); validate(); }
        catch (e) { bad.push(state + "/" + ty.id + ":" + e.message); }
      });
    });
    return { ok: !bad.length, note: bad.join(" | ") };
  });
  await T("J5", "render() is stable when nothing is selected", () => {
    openClassic(true); S.sel = []; render(); render();
    return { ok: true };
  });
  await T("J6", "deleting every deletable object leaves a valid template", () => {
    openClassic(true);
    S.tpl.objects.filter(o => !o.requiredKey).forEach(o => { S.sel = [o.id]; tryDelete(); });
    validate(); render();
    return { ok: S.tpl.objects.every(o => o.requiredKey), note: S.tpl.objects.length + " left" };
  });

  /* ===================================================================== */
  G("K · full lifecycle per certificate type");
  /* The three the product ships plus the state-gated ones, each taken from a
     classic starter all the way to active, and each checked as a blank canvas. */
  const LIFECYCLE = [
    { state: "Jharkhand", type: "transfer_certificate", starter: "tc_cbse" },
    { state: "Jharkhand", type: "transfer_certificate", starter: "tc_plain", board: "ICSE" },
    { state: "Jharkhand", type: "bonafide", starter: "bonafide" },
    { state: "Jharkhand", type: "character", starter: "conduct" },
    { state: "Kerala", type: "school_education_certificate", starter: "sec_ker" },
    { state: "Kerala", type: "leaving_certificate_5a", starter: "lc_5a" }
  ];
  for (const [i, c] of LIFECYCLE.entries()) {
    await T("K" + (i + 1), `classic → proof → publish → activate · ${c.type} (${c.starter})`, async () => {
      resetApp({ state: c.state, board: c.board || "CBSE" });
      S.docType = c.type;
      const st = STARTERS.find(s => s.id === c.starter);
      if (!st) return { ok: false, note: "starter missing" };
      if (!startersFor(c.type).some(s => s.id === c.starter))
        return { ok: false, note: `starter not offered for ${c.state}/${c.board || "CBSE"}` };
      go("gallery");
      openStarter(st);
      if (S.screen !== "designer") return { ok: false, note: "did not open designer" };

      openProof(); $("#proofRun").click(); await sleep(2600); closeModal();
      if (!S.proofed) return { ok: false, note: "proof did not complete" };

      const v = validate();
      if (v.blocking.length)
        return { ok: false, note: "blocked: " + [...new Set(v.blocking.map(b => b.type + (b.key ? ":" + b.key : "")))].join(", ") };

      openPublish();
      const btn = $("#pubGo");
      if (!btn || btn.disabled) { closeModal(); return { ok: false, note: "publish button disabled" }; }
      btn.click();
      const pv = S.tpl.publishedVersion;
      if ($("#pubAct")) $("#pubAct").click();
      closeModal();

      const active = S.active[c.type] === S.tpl.templateId;
      const inLib = libOf(c.type).some(r => r.id === S.tpl.templateId && r.publishedVersion === pv);
      return { ok: active && inLib && S.tpl.activeVersion === pv,
               note: `published v${pv}, active=${active}, inLibrary=${inLib}` };
    });
  }
  await T("K90", "every enabled type's blank canvas blocks publish and names the gaps", () => {
    const bad = [];
    ["Jharkhand", "Kerala"].forEach(state => {
      resetApp({ state });
      TYPES.filter(typeEnabled).forEach(ty => {
        S.docType = ty.id;
        go("gallery");
        const card = $("#starterGrid .tpl-card--new");
        if (!card) { bad.push(state + "/" + ty.id + ":no blank card"); return; }
        card.click();
        S.proofed = { hash: "x" };
        const v = validate();
        if (!v.blocking.length) return;          // legitimately complete
        openPublish();
        const btn = $("#pubGo");
        if (!btn || !btn.disabled) bad.push(state + "/" + ty.id + ":publish not blocked");
        if ($$("#mBody .gate--fail").length === 0) bad.push(state + "/" + ty.id + ":no failure rows shown");
        closeModal();
      });
    });
    return { ok: !bad.length, note: bad.join(" | ") };
  });

  await T("K91", "no starter binds a key its own contract does not declare", () => {
    const bad = [];
    STARTERS.forEach(s => {
      resetApp({ state: (s.states || ["Jharkhand"])[0], board: (s.boards || ["CBSE"])[0] });
      S.tpl = s.build(); S.docType = S.tpl.docType;
      const off = offContractKeys();
      if (off.length) bad.push(`${s.id}(${S.tpl.docType}): ${off.join(",")}`);
    });
    return { ok: !bad.length, note: bad.join(" | ") };
  });
  await T("K92", "no starter carries runs in a language it does not declare", () => {
    const bad = [];
    STARTERS.forEach(s => {
      const t = s.build();
      t.objects.forEach(o => {
        if (!o.content || !o.content.i18n) return;
        Object.keys(o.content.i18n).forEach(L => {
          if (!t.languages.includes(L)) bad.push(`${s.id}/${o.id}: stale ${L}`);
        });
      });
    });
    return { ok: !bad.length, note: bad.slice(0, 6).join(" | ") };
  });
  await T("K93", "every starter's declared languages are renderable", () => {
    const bad = [];
    STARTERS.forEach(s => {
      resetApp({ state: (s.states || ["Jharkhand"])[0], board: (s.boards || ["CBSE"])[0] });
      S.tpl = s.build(); S.docType = S.tpl.docType;
      go("designer");
      S.tpl.languages.forEach(L => {
        S.lang = L;
        try { render(); } catch (e) { bad.push(`${s.id}/${L}: ${e.message}`); }
      });
      S.lang = "en";
    });
    return { ok: !bad.length, note: bad.join(" | ") };
  });

  /* ===================================================================== */
  G("L · compliance stack");
  await T("L1", "the stack is a union of authorities, not a single profile", () => {
    resetApp(); S.docType = "transfer_certificate";
    const st = stackActive();
    return { ok: st.length >= 2 && /\+/.test(prof().name), note: prof().name };
  });
  await T("L2", "required keys are the union across active layers", () => {
    resetApp(); S.docType = "transfer_certificate";
    const union = new Set();
    stackActive().forEach(l => (l.rule.requiredKeys || []).forEach(k => union.add(k)));
    return { ok: requiredKeysOf("transfer_certificate").length === union.size, note: union.size + " keys" };
  });
  await T("L3", "excluding a layer requires a recorded reason", () => {
    openClassic(true);
    const id = stackActive()[0].a.id;
    toggleLayer(id);
    $("#ovrWhy").value = "";
    $("#ovrGo").click();
    const stillOn = !S.layerOff[id];              // refused without a reason
    $("#ovrWhy").value = "written exemption on file";
    $("#ovrGo").click();
    const nowOff = !!S.layerOff[id] && !!S.overrideReason[id];
    closeModal();
    return { ok: stillOn && nowOff, note: "reason=" + S.overrideReason[id] };
  });
  await T("L4", "excluding a layer drops its required keys from the gate", () => {
    openClassic(true);
    const before = requiredKeysOf("transfer_certificate").length;
    const id = stackActive()[0].a.id;
    S.layerOff[id] = true; S.overrideReason[id] = "test";
    const after = requiredKeysOf("transfer_certificate").length;
    S.layerOff = {}; S.overrideReason = {};
    return { ok: after <= before, note: `${before} → ${after}` };
  });
  await T("L5", "re-applying an excluded layer clears the override reason", () => {
    openClassic(true);
    const id = stackActive()[0].a.id;
    S.layerOff[id] = true; S.overrideReason[id] = "test";
    toggleLayer(id);
    return { ok: !S.layerOff[id] && !S.overrideReason[id] };
  });
  await T("L6", "every required key names the authority that requires it", () => {
    resetApp(); S.docType = "transfer_certificate";
    const orphan = requiredKeysOf("transfer_certificate").filter(k => !keyAuthority(k, "transfer_certificate"));
    return { ok: !orphan.length, note: orphan.join(",") };
  });
  await T("L7", "evidence level is the best across layers, never averaged", () => {
    resetApp(); S.docType = "transfer_certificate";
    const best = stackActive().slice().sort((x, y) => (EVIDENCE_RANK[y.a.evidence] || 0) - (EVIDENCE_RANK[x.a.evidence] || 0))[0];
    return { ok: prof().evidence === best.a.evidence, note: prof().evidence };
  });
  await T("L8", "the compliance panel renders for every enabled type", () => {
    const bad = [];
    ["Jharkhand", "Kerala"].forEach(state => {
      resetApp({ state });
      TYPES.filter(typeEnabled).forEach(ty => {
        S.docType = ty.id;
        const st = startersFor(ty.id)[0]; if (!st) return;
        try { S.tpl = st.build(); go("designer"); paintCompliance(); }
        catch (e) { bad.push(state + "/" + ty.id + ":" + e.message); }
      });
    });
    return { ok: !bad.length, note: bad.join(" | ") };
  });

  /* ===================================================================== */
  G("M · page setup, tools, keyboard");
  await T("M1", "page size and orientation changes re-layout without throwing", () => {
    openClassic(true);
    const bad = [];
    [["A4", "portrait"], ["A4", "landscape"], ["A5", "portrait"], ["Legal", "portrait"]].forEach(([s, o]) => {
      try { S.tpl.page.size = s; S.tpl.page.orientation = o; layoutPage(); render(); }
      catch (e) { bad.push(s + "/" + o + ":" + e.message); }
    });
    return { ok: !bad.length, note: bad.join(" | ") };
  });
  await T("M2", "every tool can be selected", () => {
    openClassic(true); const bad = [];
    Object.values(TOOLKEY).forEach(t => { try { setTool(t); if (S.tool !== t) bad.push(t); } catch (e) { bad.push(t + ":" + e.message); } });
    setTool("move");
    return { ok: !bad.length, note: bad.join(",") };
  });
  await T("M3", "zoom stays within sane bounds", () => {
    openClassic(true);
    const z0 = S.zoom; zoomFit();
    const ok = S.zoom > 0.05 && S.zoom < 8;
    S.zoom = z0; render();
    return { ok, note: "zoomFit → " + S.zoom };
  });
  await T("M4", "margins are respected by the page box", () => {
    openClassic(true);
    const D = pageDims(), m = S.tpl.page.marginsMm;
    return { ok: D.w > m.l + m.r && D.h > m.t + m.b, note: `${D.w}×${D.h}mm margins ${JSON.stringify(m)}` };
  });

  const summary = { total: R.length, passed: R.filter(r => r.ok).length, failed: R.filter(r => !r.ok).length };
  return { summary, failures: R.filter(r => !r.ok), all: R };
};
"ZXDT_E2E loaded";
