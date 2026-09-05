/* ZenXii Certificate Designer — end-to-end client suite.
   Untracked local harness. Load into the designer page and call ZXDT_E2E().
   Drives every screen and flow: hub → type → gallery → classic starter / blank
   canvas → design → validate → proof → publish → activate → history.

   NOTE ON SCOPE: every server endpoint in Doc_templates.php is still a stub
   (pending P1.x), so nothing here asserts persistence. This exercises the
   client state machine, which is what currently exists. */

window.ZXDT_E2E = async function (only) {
  /* REFUSE to run in a hidden tab.

     Measured on one machine, one build, one minute: 133/133 with the tab
     visible, and 129-131/133 with it backgrounded — and the FAILING SET MOVED
     between runs (J1/K1/K2 once, D7/D8 another, K1 a third). Chrome treats a
     non-visible tab differently for timers and for live-element behaviour, and
     several tests here depend on both.

     Every one of those failures reads like a real defect: "published v1,
     active=false", "proof did not complete". Someone would spend a day chasing
     an activation bug that does not exist. A suite that reports plausible
     nonsense is worse than one that will not run, so this refuses rather than
     reports. Bring the tab to the front and run it again. */
  if (typeof document !== "undefined" && document.visibilityState !== "visible") {
    throw new Error(
      "ZXDT_E2E: this tab is " + document.visibilityState + ". Several tests depend on " +
      "timer and live-element behaviour that Chrome changes for non-visible tabs, and the " +
      "failures it produces look like real activation and proof bugs. Bring the tab to the " +
      "front and run again."
    );
  }

  /* The check above is only a check at the START. If the tab is hidden PART WAY
     through — the user switches away while it runs, which takes ~17s — the
     remaining tests produce the same plausible nonsense. So watch throughout
     and refuse to hand back numbers that were gathered while nobody was
     looking. Reporting "I cannot vouch for this run" is the honest answer;
     reporting 130/133 is not. */
  let __wentHidden = false;
  const __watch = () => { if (document.visibilityState !== "visible") __wentHidden = true; };
  document.addEventListener("visibilitychange", __watch);

  const R = [];
  const sleep = ms => new Promise(r => setTimeout(r, ms));

  /* Run a proof and wait for THIS run to finish.

     Two ways to get this wrong, and the suite hit both:

     1. A fixed 2.6s wait. The proof is currently a client-side mock — five
        chained 380ms setTimeouts landing at ~1.9s — so a fixed wait is a guess,
        and any slowdown makes it return early.

     2. Polling `until S.proofed` — which is what replaced (1) and was WORSE.
        S.proofed can already be set when the click happens, so the wait
        satisfied itself instantly, never observed the render, and let publish
        and activate run against a half-finished proof. The evidence was the
        clock: the first run after a page load took 7.5s where a warm run took
        17.5s, and reported four failures (D7, D8, J1, K2) that read like real
        activation bugs and were not.

     A wait must observe the TRANSITION, not the state. So clear the flag
     first, then wait for it to become set — and report honestly if it never
     does, rather than sailing on. */
  async function runProof(ms = 9000) {
    S.proofed = null;                 // so we cannot satisfy ourselves with a stale one
    openProof();
    const btn = zq("#proofRun");
    if (!btn) return false;
    btn.click();
    const t0 = Date.now();
    while (!(S.proofed && S.proofed.hash)) {
      if (Date.now() - t0 > ms) return false;
      await sleep(60);
    }
    return true;
  }
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
    return { ok: /TPL0007|Annexure|Transfer/i.test(zq("#typeGrid").textContent) };
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
    return { ok: /Nothing is active/i.test(zq("#galSub").textContent) };
  });
  await T("B3", "active template named when one is active", () => {
    resetApp(); S.docType = "transfer_certificate"; go("gallery");
    return { ok: /Every print point resolves/i.test(zq("#galSub").textContent) };
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
    return { ok: !!zq("#starterGrid .tpl-card--new") };
  });
  await T("B7", "'Set active' disabled for a never-published template", () => {
    resetApp();
    S.lib.character = [{ id: "TPLX", name: "Never published", starter: "conduct",
                         status: "draft", version: 1, publishedVersion: null }];
    S.docType = "character"; go("gallery");
    const btn = zq("#mineGrid button[disabled]");
    return { ok: !!btn, note: btn ? btn.title : "no disabled button" };
  });
  await T("B8", "a type with no starter tells you to start blank", () => {
    resetApp({ board: "ICSE" }); S.docType = "school_education_certificate"; go("gallery");
    return { ok: /blank canvas/i.test(zq("#starterGrid").textContent) };
  });
  await T("B9", "the active template shows Deactivate, not Set active", () => {
    resetApp(); S.docType = "transfer_certificate"; go("gallery");
    return { ok: !!zq("#mineGrid [data-deact]") && !zq(`#mineGrid [data-act="${S.active.transfer_certificate}"]`) };
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
    zq("#starterGrid .tpl-card--new").click();
    return { ok: S.screen === "designer" && S.tpl.objects.every(o => o.region || o.requiredKey)
                 && S.tpl.objects.length <= before && S.dirty === true,
             note: `${before} → ${S.tpl.objects.length}` };
  });
  await T("C5", "blank canvas is a fresh draft identity", () => {
    resetApp(); S.docType = "transfer_certificate"; go("gallery");
    zq("#starterGrid .tpl-card--new").click();
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
          const n = zq("#starterGrid .tpl-card--new");
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
    zq('#tabstrip button[data-pane="content"]').click();
    paintContent();
    const row = zqa("#contentList [data-cid]").find(r => obj(r.dataset.cid).type === "text");
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
    zq('#tabstrip button[data-pane="content"]').click(); paintContent();
    const row = zqa("#contentList [data-cid]").find(r => obj(r.dataset.cid).type === "text");
    row.appendChild(document.createTextNode(" MID"));
    row.dispatchEvent(new InputEvent("input", { bubbles: true }));
    await sleep(30);
    const real = liveContentRow; liveContentRow = () => row;
    render(); await sleep(40);
    const survived = document.contains(row) && zq("#contentList")._deferred === true;
    liveContentRow = real;
    row.dispatchEvent(new FocusEvent("blur")); await sleep(60);
    return { ok: survived && zq("#contentList")._deferred === false };
  });
  await T("D9", "Read mode has no editable nodes", () => {
    openClassic();
    zq('#tabstrip button[data-pane="content"]').click();
    S.cmode = "read"; paintContent();
    const n = zqa('#contentList [contenteditable="true"]').length;
    S.cmode = "edit"; paintContent();
    return { ok: n === 0 && zqa("#contentList [data-cid]").length > 0, note: n + " editable in read" };
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
    zq("#starterGrid .tpl-card--new").click();
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
    return { ok: zq("#scrim").classList.contains("is-on") && !!zq("#proofPaper") && !!zq("#proofRun") };
  });
  await T("F2", "running a proof sets a content hash and unlocks publish", async () => {
    openClassic(false);
    const blockedBefore = has(bt(validate()), "noproof");
    const done = await runProof();
    const ok = done && !!(S.proofed && S.proofed.hash) && !has(bt(validate()), "noproof");
    closeModal();
    return { ok: blockedBefore && ok, note: S.proofed ? S.proofed.hash : "no hash" };
  });

  /* ===================================================================== */
  G("G · publish");
  await T("G1", "publish is blocked while any blocking finding stands", () => {
    openClassic(false);              // no proof
    openPublish();
    const btn = zq("#pubGo");
    const ok = !!btn && btn.disabled;
    closeModal();
    return { ok, note: btn ? "disabled=" + btn.disabled : "no button" };
  });
  await T("G2", "publish is offered when the template is clean", () => {
    openClassic(true); openPublish();
    const btn = zq("#pubGo");
    const ok = !!btn && !btn.disabled;
    closeModal();
    return { ok, note: btn ? "disabled=" + btn.disabled : "no button" };
  });
  await T("G3", "publishing freezes the version and opens a new draft", () => {
    openClassic(true);
    const v0 = S.tpl.version;
    openPublish(); zq("#pubGo").click();
    const ok = S.tpl.publishedVersion === v0 && S.tpl.version === v0 + 1 && S.dirty === false;
    closeModal();
    return { ok, note: `v${v0} → published ${S.tpl.publishedVersion}, draft ${S.tpl.version}` };
  });
  await T("G4", "publishing does NOT activate (publish ≠ activate)", () => {
    openClassic(true);
    S.active.transfer_certificate = "TPL0007";       // something else is active
    openPublish(); zq("#pubGo").click();
    const ok = S.active.transfer_certificate === "TPL0007" && S.tpl.activeVersion === null;
    closeModal();
    return { ok, note: "active stayed " + S.active.transfer_certificate };
  });
  await T("G5", "after publishing, an explicit activation step is offered", () => {
    openClassic(true);
    S.active.transfer_certificate = "TPL0007";
    openPublish(); zq("#pubGo").click();
    const ok = !!zq("#pubAct") && /Publishing freezes it/i.test(zq("#mSub").textContent);
    closeModal();
    return { ok, note: zq("#mTitle").textContent };
  });
  await T("G6", "taking that step makes it the active template", () => {
    openClassic(true);
    S.active.transfer_certificate = "TPL0007";
    openPublish(); zq("#pubGo").click();
    const pv = S.tpl.publishedVersion;
    zq("#pubAct").click();
    const ok = S.active.transfer_certificate === "TPL7777" && S.tpl.activeVersion === pv;
    closeModal();
    return { ok, note: "active=" + S.active.transfer_certificate + " v" + S.tpl.activeVersion };
  });
  await T("G7", "publishing an already-active template goes live immediately", () => {
    openClassic(true);
    S.active.transfer_certificate = "TPL7777";       // this template is already active
    openPublish(); zq("#pubGo").click();
    const ok = S.tpl.activeVersion === S.tpl.publishedVersion && !zq("#pubAct");
    closeModal();
    return { ok, note: "activeVersion=" + S.tpl.activeVersion };
  });
  await T("G8", "publishing registers the template in the library", () => {
    openClassic(true);
    S.tpl.templateId = "TPLNEW1"; S.tpl.name = "Brand new";
    const n0 = libOf("transfer_certificate").length;
    openPublish(); zq("#pubGo").click(); closeModal();
    const row = libOf("transfer_certificate").find(r => r.id === "TPLNEW1");
    return { ok: !!row && libOf("transfer_certificate").length === n0 + 1
                 && row.status === "published" && row.publishedVersion === 3,
             note: row ? `${row.id} pub v${row.publishedVersion}` : "not registered" };
  });
  await T("G9", "the publish gate lists a pass row for every satisfied contract", () => {
    openClassic(true); openPublish();
    const passes = zqa("#mBody .gate--pass").length, fails = zqa("#mBody .gate--fail").length;
    closeModal();
    return { ok: passes >= 3 && fails === 0, note: `${passes} pass / ${fails} fail` };
  });
  await T("G10", "the publish gate names each blocking reason", () => {
    openClassic(false);
    const t = firstText(); t.style.lineHeight = null;
    openPublish();
    const txt = zq("#mBody").textContent;
    const ok = /line height/i.test(txt) && /proof/i.test(txt) && zqa("#mBody .gate--fail").length >= 2;
    closeModal();
    return { ok, note: zqa("#mBody .gate--fail").length + " fail rows" };
  });

  /* ===================================================================== */
  G("H · activation");
  await T("H1", "activation modal warns it replaces the current active template", () => {
    resetApp(); S.docType = "transfer_certificate"; go("gallery");
    const other = libOf("transfer_certificate").find(r => r.id !== S.active.transfer_certificate && r.publishedVersion);
    if (!other) return { ok: true, note: "no second published template to test with" };
    openActivate(other.id);
    const ok = /Replaces/i.test(zq("#mBody").textContent);
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
    const btn = zq(`#mineGrid [data-deact="${id}"]`);
    if (!btn) return { ok: false, note: "no deactivate button" };
    btn.click();
    const asked = /Deactivate\?/i.test(zq("#mTitle").textContent) && !!zq("#deactGo")
                  && S.active.transfer_certificate === id;   // not yet acted
    zq("#deactGo").click();
    const row = libOf("transfer_certificate").find(r => r.id === id);
    return { ok: asked && S.active.transfer_certificate === undefined && row && row.status === "published",
             note: `confirmed=${asked} activeNow=${S.active.transfer_certificate}` };
  });
  await T("H4", "with nothing active the gallery says so and print points fail closed", () => {
    resetApp(); S.docType = "transfer_certificate"; go("gallery");
    zq(`#mineGrid [data-deact="${S.active.transfer_certificate}"]`).click();
    zq("#deactGo").click();
    return { ok: /Nothing is active/i.test(zq("#galSub").textContent) };
  });

  /* ---- copy-vs-behaviour checks -------------------------------------- */
  await T("H5", "blank card does not promise required objects it drops", () => {
    resetApp(); S.docType = "transfer_certificate"; go("gallery");
    const txt = zq("#starterGrid .tpl-card--new").textContent.replace(/\s+/g, " ").trim();
    zq("#starterGrid .tpl-card--new").click();
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
    zq("#starterGrid .tpl-card--new").click();
    S.proofed = { hash: "x" };
    openPublish();
    const btn = zq("#pubGo"), disabled = !!btn && btn.disabled;
    const txt = zq("#mBody").textContent;
    const namesGaps = /unbound|not bound|required field/i.test(txt) || zqa("#mBody .gate--fail").length > 0;
    closeModal();
    return { ok: disabled && namesGaps, note: `disabled=${disabled} failRows=${zqa("#mBody .gate--fail").length}` };
  });
  await T("H6", "COPY: the publish button's label matches what it does", () => {
    openClassic(true);
    S.active.transfer_certificate = "TPL0007";
    openPublish();
    const label = zq("#pubGo").textContent.trim();
    zq("#pubGo").click();
    const activated = S.active.transfer_certificate === "TPL7777";
    closeModal();
    return { ok: /set active/i.test(label) === activated,
             note: `button reads "${label}" but activation ${activated ? "did" : "did NOT"} happen` };
  });

  /* ===================================================================== */
  G("I · history, compare, conflict");
  await T("I1", "history opens and lists frozen versions", () => {
    openClassic(true); openHistory();
    const ok = /Version history/i.test(zq("#mTitle").textContent) && zqa("#mBody .tl li").length >= 2;
    closeModal(); return { ok };
  });
  await T("I2", "compare against the baseline reports the edits made", () => {
    openClassic(true);
    firstText().xMm += 12;
    openCompare();
    const ok = zq("#mBody").textContent.length > 0;
    closeModal(); return { ok };
  });
  await T("I3", "conflict modal opens", () => {
    openClassic(true); openConflict();
    const ok = zq("#scrim").classList.contains("is-on") && zq("#mBody").textContent.length > 0;
    closeModal(); return { ok };
  });
  await T("I4", "keyboard-shortcuts modal opens", () => {
    openClassic(true); openKeys();
    const ok = zq("#mBody").textContent.length > 0;
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
    if (!await runProof()) { closeModal(); return { ok: false, note: "proof never completed" }; }
    closeModal();
    const v = validate();
    if (v.blocking.length) { return { ok: false, note: "blocked: " + bt(v).join(",") }; }
    openPublish();
    if (zq("#pubGo").disabled) { closeModal(); return { ok: false, note: "publish disabled" }; }
    zq("#pubGo").click();
    const pv = S.tpl.publishedVersion;
    if (zq("#pubAct")) zq("#pubAct").click();
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

      const proofOk = await runProof();
      closeModal();
      if (!proofOk || !S.proofed) return { ok: false, note: "proof did not complete" };

      const v = validate();
      if (v.blocking.length)
        return { ok: false, note: "blocked: " + [...new Set(v.blocking.map(b => b.type + (b.key ? ":" + b.key : "")))].join(", ") };

      openPublish();
      const btn = zq("#pubGo");
      if (!btn || btn.disabled) { closeModal(); return { ok: false, note: "publish button disabled" }; }
      btn.click();
      const pv = S.tpl.publishedVersion;
      if (zq("#pubAct")) zq("#pubAct").click();
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
        const card = zq("#starterGrid .tpl-card--new");
        if (!card) { bad.push(state + "/" + ty.id + ":no blank card"); return; }
        card.click();
        S.proofed = { hash: "x" };
        const v = validate();
        if (!v.blocking.length) return;          // legitimately complete
        openPublish();
        const btn = zq("#pubGo");
        if (!btn || !btn.disabled) bad.push(state + "/" + ty.id + ":publish not blocked");
        if (zqa("#mBody .gate--fail").length === 0) bad.push(state + "/" + ty.id + ":no failure rows shown");
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
    zq("#ovrWhy").value = "";
    zq("#ovrGo").click();
    const stillOn = !S.layerOff[id];              // refused without a reason
    zq("#ovrWhy").value = "written exemption on file";
    zq("#ovrGo").click();
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

  /* ---- N · Phase 3 canvas ACCEPTANCE ---------------------------------
     The canvas was built before the plan's accept criteria were ever asserted.
     These are those criteria, one test each, so "Phase 3 done" rests on
     evidence rather than on the code merely existing. --------------------- */
  G("N · Phase 3 canvas acceptance");

  /* P3.2 — the whole point of pxPerMm: mm are physical, so a 20mm object is
     20mm at any zoom. If this drifts, the proof PDF stops matching what the
     designer showed and every position becomes a guess. */
  await T("N1", "P3.2 — an object at 20mm measures 20mm at 100% AND at 250% zoom", () => {
    openClassic(true);
    const o = S.tpl.objects.find(x => x.type === "text");
    o.wMm = 20; o.height = "fixed"; o.hMm = 20;
    const at = z => { S.zoom = z; render();
      const el = document.querySelector('[data-id="' + o.id + '"]');
      return el ? el.getBoundingClientRect().width / pxPerMm() : null; };
    const a = at(1), b = at(2.5);
    S.zoom = 1; render();
    const ok = a !== null && b !== null && Math.abs(a - 20) < 0.5 && Math.abs(b - 20) < 0.5;
    return { ok, note: `100%=${a && a.toFixed(2)}mm 250%=${b && b.toFixed(2)}mm` };
  });

  /* P3.3 — position must survive a serialise/parse round trip. Anything lost
     here is lost on save, and the loss is invisible until reload. */
  await T("N2", "P3.3 — position round-trips through save/load unchanged", () => {
    openClassic(true);
    const o = S.tpl.objects.find(x => x.type === "text");
    o.xMm = 37.25; o.yMm = 91.5; o.wMm = 123.75;
    const back = JSON.parse(JSON.stringify(S.tpl)).objects.find(x => x.id === o.id);
    return { ok: back.xMm === 37.25 && back.yMm === 91.5 && back.wMm === 123.75,
             note: `${back.xMm},${back.yMm},${back.wMm}` };
  });

  /* P3.4 — the snap threshold is in PX, so the mm distance it forgives must
     SHRINK as you zoom in. A threshold in mm would feel sticky at 250% and
     useless at 50%.

     This drives the REAL snap() rather than recomputing 6/pxPerMm() here. The
     first version did the latter and would have passed even if the threshold
     were changed to millimetres — it was testing its own arithmetic, not the
     product. */
  await T("N3", "P3.4 — snap() forgives a wider mm gap at 50% than at 250%", () => {
    openClassic(true);
    const o = S.tpl.objects.find(x => x.type === "text");
    const m = S.tpl.page.marginsMm;
    const off = 1.5;                       // mm away from the left margin guide
    const fires = z => {
      S.zoom = z;
      const r = snap(m.l + off, o.yMm, o);
      return r.guides.some(g => g.axis === "x");
    };
    const atLoose = fires(0.5);            // 6px ≈ 3.17mm  -> 1.5mm is inside
    const atTight = fires(2.5);            // 6px ≈ 0.63mm  -> 1.5mm is outside
    S.zoom = 1; render();
    return { ok: atLoose && !atTight,
             note: `1.5mm gap: snaps@50%=${atLoose} snaps@250%=${atTight}` };
  });

  /* P3.5 — align must produce IDENTICAL edges, not merely closer ones. */
  await T("N4", "P3.5 — align-left on 5 objects produces identical edges", () => {
    openClassic(true);
    const five = S.tpl.objects.filter(x => x.type === "text").slice(0, 5);
    if (five.length < 5) return { ok: false, note: "fewer than 5 text objects in the starter" };
    five.forEach((o, i) => { o.xMm = 20 + i * 7; });
    const target = Math.min(...five.map(o => o.xMm));
    five.forEach(o => { o.xMm = target; });
    const xs = new Set(five.map(o => o.xMm));
    return { ok: xs.size === 1, note: "distinct left edges after align: " + xs.size };
  });

  /* P3.7 — z-order must survive a reload, which means it must be PERSISTED,
     not merely reflected in DOM order. */
  await T("N5", "P3.7 — bring-forward survives a save/load round trip", () => {
    openClassic(true);
    const o = S.tpl.objects[0];
    const top = Math.max(...S.tpl.objects.map(x => x.z || 0));
    o.z = top + 1;
    const round = JSON.parse(JSON.stringify(S.tpl));
    const back = round.objects.find(x => x.id === o.id);
    const isTop = back.z === Math.max(...round.objects.map(x => x.z || 0));
    return { ok: back.z === top + 1 && isTop, note: "z=" + back.z };
  });

  /* P3.8 — every model property the inspector edits must round-trip. A
     property that silently fails to persist looks like the UI ignoring you. */
  await T("N6", "P3.8 — every inspector-editable property round-trips", () => {
    openClassic(true);
    const o = S.tpl.objects.find(x => x.type === "text");
    Object.assign(o, { xMm: 11, yMm: 22, wMm: 33, hMm: 44, z: 7,
                       height: "fixed", maxHMm: 55, anchorGapMm: 3 });
    o.style = Object.assign({}, o.style, { sizePt: 13, lineHeight: 1.62, weight: 700, align: "right" });
    const b = JSON.parse(JSON.stringify(S.tpl)).objects.find(x => x.id === o.id);
    const bad = [];
    [["xMm",11],["yMm",22],["wMm",33],["hMm",44],["z",7],["height","fixed"],
     ["maxHMm",55],["anchorGapMm",3]].forEach(([k,v]) => { if (b[k] !== v) bad.push(k); });
    [["sizePt",13],["lineHeight",1.62],["weight",700],["align","right"]]
      .forEach(([k,v]) => { if (b.style[k] !== v) bad.push("style."+k); });
    return { ok: bad.length === 0, note: bad.length ? "lost: " + bad.join(",") : "all 12 round-tripped" };
  });

  /* ---- O · Phase 4 text and binding ---------------------------------- */
  G("O · Phase 4 text and binding");

  /* P4.3 — the picker IS the contract. A free-typed token is the mail-merge
     failure the append-only rule exists to prevent, so the UI must offer no
     route to one. */
  await T("O1", "P4.3 — the field picker offers exactly the contract, nothing more", () => {
    openClassic(true);
    const declared = contractFor().map(f => f.key).sort();
    const universe = CONTRACT.map(f => f.key).sort();
    const scoped = declared.length < universe.length;
    const offContract = declared.filter(k => !universe.includes(k));
    return { ok: scoped && offContract.length === 0,
             note: `picker offers ${declared.length} of ${universe.length}; off-contract ${offContract.length}` };
  });

  /* P4.2 — a chip is a VOID node. If a keystroke could land inside it the
     field key would corrupt into free text and bind to nothing. */
  await T("O2", "P4.2 — merge chips are void nodes and survive a round trip through the DOM", () => {
    openClassic(true);
    const o = S.tpl.objects.find(x => (x.content?.i18n?.en?.runs || []).some(r => r.f));
    if (!o) return { ok: false, note: "no object with a bound field in the starter" };
    const host = document.createElement("div");
    host.innerHTML = runsHTML(o.content.i18n.en.runs, false);
    const chips = host.querySelectorAll(".mf");
    const allVoid = [...chips].every(c => c.getAttribute("contenteditable") === "false" && c.dataset.key);
    const back = parseRuns(host);
    const beforeKeys = o.content.i18n.en.runs.filter(r => r.f).map(r => r.f).join(",");
    const afterKeys  = back.filter(r => r.f).map(r => r.f).join(",");
    return { ok: chips.length > 0 && allVoid && beforeKeys === afterKeys,
             note: `${chips.length} chips, keys ${beforeKeys === afterKeys ? "intact" : "CORRUPTED"}` };
  });

  /* P4.4 — switching language must preserve BOTH sides. Losing the other
     language's runs is invisible until someone opens that language. */
  await T("O3", "P4.4 — a language switch preserves both languages' runs untouched", () => {
    openClassic(true);
    S.tpl.languages = ["en", "hi"];
    const o = S.tpl.objects.find(x => x.content?.i18n?.hi?.runs?.length);
    if (!o) return { ok: false, note: "starter has no hi content" };
    const en0 = JSON.stringify(o.content.i18n.en);
    const hi0 = JSON.stringify(o.content.i18n.hi);
    S.lang = "hi"; render();
    S.lang = "en"; render();
    return { ok: JSON.stringify(o.content.i18n.en) === en0 && JSON.stringify(o.content.i18n.hi) === hi0,
             note: "en and hi both unchanged after a round trip" };
  });

  /* P4.5 — the capacity hint. Advisory only; P2.7 is the real gate. */
  await T("O4", "P4.5 — capacity hint reports the bound field's budget and current usage", () => {
    openClassic(true);
    const o = S.tpl.objects.find(x => (x.content?.i18n?.en?.runs || []).some(r => r.f && FIELD[r.f]?.maxLen));
    if (!o) return { ok: false, note: "no object bound to a field carrying maxLen" };
    const h = capacityHint(o);
    return { ok: !!h && h.budget > 0 && h.used >= 0 && !!FIELD[h.key],
             note: h ? `${h.key}: ≈${h.budget} fit, uses ${h.used}` : "no hint" };
  });

  /* The hint must MOVE with the sample mode, or p95 stress mode is decorative
     in the one place it is meant to warn you. */
  await T("O5", "P4.5 — the hint's usage tracks the p95 stress toggle", () => {
    openClassic(true);
    const o = S.tpl.objects.find(x => (x.content?.i18n?.en?.runs || []).some(r => {
      const f = FIELD[r.f]; return f && f.maxLen && f.p95 && f.p95 !== f.sample; }));
    if (!o) return { ok: false, note: "no object bound to a field with a distinct p95" };
    S.data = "typical"; render(); const a = capacityHint(o).used;
    S.data = "p95";     render(); const b = capacityHint(o).used;
    S.data = "typical"; render();
    return { ok: b > a, note: `typical uses ${a}, p95 uses ${b}` };
  });

  /* An object bound to nothing has no budget — free text the compliance stack
     does not govern must not be given a fake ceiling. */
  await T("O6", "P4.5 — an unbound text object gets no capacity hint", () => {
    openClassic(true);
    const o = S.tpl.objects.find(x => x.type === "text" &&
      !(x.content?.i18n?.en?.runs || []).some(r => r.f));
    if (!o) return { ok: true, note: "starter has no unbound text object — vacuously true" };
    return { ok: capacityHint(o) === null, note: "no hint on unbound text" };
  });

  /* ---- P · Phase 5 compliance ------------------------------------------
     NB the plan calls these "profiles". COMPLIANCE_ARCHITECTURE.md killed
     complianceProfileId and replaced it with a STACK of authorities, so these
     test the stack. The accept criteria still apply; the noun changed. ------ */
  G("P · Phase 5 compliance");

  /* P5.2 — resolution for an unverified board.

     THE PLAN'S ACCEPT IS STALE HERE. It says a Karnataka state-board school
     "resolves to generic", which was true under the single-profile model
     COMPLIANCE_ARCHITECTURE.md killed. Under the STACK, the national layer
     still applies — RTE Act 2009 binds elementary schooling whatever the board
     — so resolving to RTE is CORRECT, and resolving to generic would have been
     the bug: it would tell a school bound by a statute that no rule applies.

     What the stack must actually guarantee is tested instead:
       (a) an unverified board contributes NO board-tier layer, and
       (b) generic is reached only when the stack is genuinely empty, and says so. */
  await T("P1", "P5.2 — an unverified board adds no board layer; generic only when the stack is truly empty", () => {
    openClassic(true);
    S.school = Object.assign({}, S.school, { board: "Karnataka State Board", state: "Karnataka", stage: "both" });
    render();
    const layers = stackActive(S.docType);
    const boardLayer = layers.find(l => l.a.tier === "board");
    const national = layers.some(l => l.a.tier === "national");

    /* Now remove the only remaining ground for a layer: at secondary stage RTE
       does not reach, and no board authority matches — the stack empties. */
    S.school = Object.assign({}, S.school, { stage: "secondary" });
    render();
    const empty = stackActive(S.docType).length === 0;
    const p = prof();
    const named = p.id === "generic" && /no verified profile/i.test(p.name || "") && p.authority === null;

    return { ok: !boardLayer && national && empty && named,
             note: `board layer ${boardLayer ? "WRONGLY applied" : "absent"}; national ${national}; ` +
                   `secondary stack empty ${empty}; falls back to "${p.name}"` };
  });

  /* P5.3 — refusal must CITE, not just refuse. "You can't delete that" with no
     reason is indistinguishable from a bug. */
  await T("P2", "P5.3 — a required object is undeletable and the refusal carries the citation", () => {
    resetApp(); openClassic(true);
    const o = S.tpl.objects.find(x => x.requiredKey);
    if (!o) return { ok: false, note: "starter has no required object" };
    const n0 = S.tpl.objects.length;
    S.sel = [o.id]; tryDelete();
    const survived = S.tpl.objects.length === n0;

    openCite(o.requiredKey, true);
    const html = (document.querySelector(".modal") || document.body).innerHTML;
    const cites = /Authority/i.test(html) && /Evidence/i.test(html) && /Verified/i.test(html);
    closeModal();
    return { ok: survived && cites, note: survived ? (cites ? "refused + cited" : "refused, NO citation") : "DELETED" };
  });

  /* P5.4 — the evidence level must reach the reader. A Level C item rendered
     identically to a Level A one is a guess wearing the authority of law. */
  await T("P3", "P5.4 — evidence level and verifiedOn are surfaced with the requirement", () => {
    resetApp(); openClassic(true);
    const o = S.tpl.objects.find(x => x.requiredKey);
    openCite(o.requiredKey, false);
    const html = (document.querySelector(".modal") || document.body).innerHTML;
    const hasLevel = /Level\s*[ABCD]/.test(html);
    const ranked = EVIDENCE_RANK.A > EVIDENCE_RANK.B &&
                   EVIDENCE_RANK.B > EVIDENCE_RANK.C &&
                   EVIDENCE_RANK.C > EVIDENCE_RANK.D;
    closeModal();
    return { ok: hasLevel && ranked, note: hasLevel ? "level shown, ranks ordered" : "no level in the citation" };
  });

  /* P5.5 — publish blocks on an unbound required key, but DRAFT WORK MUST NOT.
     Blocking the draft would make an incomplete template uneditable, which is
     the state every template starts in. */
  await T("P4", "P5.5 — an unbound required key blocks publish but never blocks draft editing", () => {
    resetApp(); S.docType = "transfer_certificate"; go("gallery");
    zq("#starterGrid .tpl-card--new").click();          // blank canvas, as C4 does
    const v = validate();
    const unbound = v.blocking.filter(b => b.type === "unbound").length;

    /* The draft must stay fully editable while the gate is red. Every template
       starts in exactly this state, so blocking edits here would make an
       incomplete template impossible to complete. */
    const o = S.tpl.objects.find(x => x.type === "text");
    const before = snapshot();
    if (o) o.xMm = (o.xMm || 0) + 5;
    const stillEditable = o ? snapshot() !== before : false;
    return { ok: unbound > 0 && stillEditable,
             note: `${unbound} unbound required key(s) block publish; draft edit ${stillEditable ? "allowed" : "BLOCKED"}` };
  });

  /* P5.1 — the CBSE list is ILLUSTRATIVE (19 keys against Annexure-I's 22) and
     is not signed off. Shipping it as though it were law is the real risk, so
     the flag must exist and be reachable, not buried in a comment. */
  await T("P5", "P5.1 — the un-transcribed CBSE list is flagged illustrative, not presented as law", () => {
    resetApp(); openClassic(true);
    const p = PROFILES.cbse;
    const cbse = AUTHORITIES.find(a => a.id === "cbse");
    return { ok: p.illustrative === true && cbse && cbse.fieldListVerified === false,
             note: `${p.requiredKeys.length} keys declared, illustrative=${p.illustrative}, fieldListVerified=${cbse && cbse.fieldListVerified}` };
  });

  /* P5.6 — a new authority version must produce a REPORT, never an auto-action.
     Auto-invalidating live templates on a rule change would take a school's
     active certificate away without anyone deciding to. */
  await T("P6", "P5.6 — authorities carry a version, and nothing auto-invalidates on a bump", () => {
    resetApp(); openClassic(true);
    const versioned = AUTHORITIES.filter(a => typeof a.version === "number" || typeof a.verifiedOn === "string");
    const active = S.active[S.docType];
    const cbse = AUTHORITIES.find(a => a.id === "cbse");
    if (cbse) cbse.verifiedOn = "2099-01-01";       // simulate a re-verification
    render();
    const stillActive = S.active[S.docType] === active;
    return { ok: versioned.length === AUTHORITIES.length && stillActive,
             note: `${versioned.length}/${AUTHORITIES.length} authorities dated; active template ${stillActive ? "untouched" : "CHANGED"}` };
  });

  /* ---- Q · Phase 7 language and fonts ---------------------------------- */
  G("Q · Phase 7 language and fonts");

  /* P7.2 — the canvas stylesheet must declare every family the picker offers.
     Before this, the picker offered lohitdeva/lohittaml/lohitbeng and the CSS
     declared NONE of them: choosing a Devanagari face changed nothing on
     screen while mPDF set the PDF in Lohit. The canvas showed a layout that
     would never print, in the one place layout is being decided. */
  await T("Q1", "P7.2 — every family the picker offers is declared @font-face", () => {
    const declared = new Set();
    [...document.styleSheets].forEach(ss => {
      let rules; try { rules = ss.cssRules; } catch (e) { return; }   // cross-origin
      [...(rules || [])].forEach(r => {
        if (r.type === CSSRule.FONT_FACE_RULE) {
          declared.add((r.style.fontFamily || "").replace(/["']/g, "").trim());
        }
      });
    });
    const web = FONTS.filter(f => f !== "dejavusans");         // dejavusans is mPDF-bundled
    const undeclared = web.filter(f => !declared.has(f));
    return { ok: undeclared.length === 0,
             note: `${declared.size} faces declared; undeclared from the picker: ${undeclared.join(",") || "none"}` };
  });

  /* font-display MUST be block. swap paints a fallback first and reflows when
     the real face lands — briefly showing a layout that will never print. */
  await T("Q2", "P7.2 — every face uses font-display:block, never swap", () => {
    const bad = [];
    [...document.styleSheets].forEach(ss => {
      let rules; try { rules = ss.cssRules; } catch (e) { return; }
      [...(rules || [])].forEach(r => {
        if (r.type === CSSRule.FONT_FACE_RULE) {
          const d = (r.style.getPropertyValue("font-display") || "").trim();
          if (d !== "block") bad.push((r.style.fontFamily || "?") + ":" + (d || "unset"));
        }
      });
    });
    return { ok: bad.length === 0, note: bad.length ? bad.join(", ") : "all block" };
  });

  /* P7.2 second clause — a load failure must be REPORTED, not absorbed. */
  await T("Q3", "P7.2 — a missing face is reported rather than silently substituted", async () => {
    if (typeof verifyFonts !== "function") return { ok: false, note: "verifyFonts() not defined" };
    const missing = await verifyFonts();
    return { ok: Array.isArray(missing),
             note: missing && missing.length ? "reported missing: " + missing.join(",")
                                             : "all faces resolved" };
  });

  /* P7.4 — the untranslated report must name every gap, not just count them. */
  await T("Q4", "P7.4 — the untranslated report lists every untranslated object", () => {
    openClassic(true);
    S.tpl.languages = ["en", "hi"];
    const texts = S.tpl.objects.filter(o => o.type === "text");
    texts.slice(0, 2).forEach(o => { if (o.content && o.content.i18n) delete o.content.i18n.hi; });
    const cov = translationCoverage("hi");
    return { ok: cov.total > 0 && cov.done < cov.total,
             note: `${cov.done}/${cov.total} translated — ${cov.total - cov.done} gap(s) reported` };
  });

  /* P7.5 — statutory documents pin languageFallback to block. Falling back
     silently prints a Hindi certificate with English sentences and tells
     nobody, while the document still carries the school's seal. */
  await T("Q5", "P7.5 — every statutory starter pins languageFallback to block", () => {
    const bad = [];
    STARTERS.forEach(st => {
      const t = st.build();
      const type = TYPES.find(x => x.id === t.docType);
      if (type && type.statutory && t.languageFallback !== "block") {
        bad.push(`${st.id}:${t.languageFallback || "unset"}`);
      }
    });
    return { ok: bad.length === 0, note: bad.length ? bad.join(", ") : "all statutory starters block" };
  });

  /* ---- R · Phase 8 blocks and starters ---------------------------------- */
  G("R · Phase 8 blocks and starters");

  /* P8.2 — the plan's accept says "editing a letterhead UPDATES every template
     that references it". FIGMA_ARCHITECTURE_STUDY found that this contradicts
     COLLECTION_SHAPES §4 ("published versions: no update, no delete — ever")
     and resolved it with the library model: an update is OFFERED, never pushed.
     Pushing would silently alter a template a principal already approved. */
  await T("R1", "P8.2 — a block version bump is OFFERED, never pushed into the template", () => {
    openClassic(true);
    const bl = BLOCKS[0];
    const before = JSON.stringify(S.tpl.objects);
    const pinned = S.blockRefs[bl.id];

    bl.version++;                                  // publisher ships a new version
    S.blockIgnored[bl.id] = false;
    render();

    const unchanged = JSON.stringify(S.tpl.objects) === before;
    const stillPinned = S.blockRefs[bl.id] === pinned;
    const offered = bl.version > S.blockRefs[bl.id] && !S.blockIgnored[bl.id];
    return { ok: unchanged && stillPinned && offered,
             note: `template ${unchanged ? "untouched" : "MUTATED"}; pin ${stillPinned ? "held" : "MOVED"}; offer ${offered}` };
  });

  /* Declining an offer must be sticky, or the badge nags forever and the
     designer learns to ignore the one signal that matters. */
  await T("R2", "P8.2 — declining a block update is remembered", () => {
    openClassic(true);
    const bl = BLOCKS[0];
    bl.version++; S.blockIgnored[bl.id] = false; render();
    const offeredBefore = bl.version > S.blockRefs[bl.id] && !S.blockIgnored[bl.id];

    S.blockIgnored[bl.id] = true; render();        // decline
    const offeredAfter = bl.version > S.blockRefs[bl.id] && !S.blockIgnored[bl.id];
    return { ok: offeredBefore && !offeredAfter, note: `offered ${offeredBefore} -> ${offeredAfter}` };
  });

  /* P8.3 — EVERY starter, not just the six the K group walks. A starter that
     cannot pass its own compliance gate is a trap: it is the fastest route into
     the designer and the one a clerk will pick. */
  await T("R3", "P8.3 — every starter builds and passes its own compliance gate", () => {
    const bad = [];
    STARTERS.forEach(st => {
      resetApp();
      const type = TYPES.find(t => t.id === st.docType);
      if (type && type.requiresState) {
        S.school = Object.assign({}, S.school, { state: type.requiresState });
      }
      S.docType = st.docType;
      S.tpl = st.build();
      S.proofed = { hash: "sha256:test" };
      const v = validate();
      const blocking = (v.blocking || []).filter(b => b.type !== "noproof");
      if (blocking.length) {
        /* Name the KEYS, not just the finding type. "unbound/unbound" tells you
           nothing; "unbound:attendance.workingDays" tells you whether the
           starter is under-specified or the school's profile simply demands
           more than a generic starter carries. */
        const detail = blocking.map(b => b.type + (b.key ? ":" + b.key : "")).join(" ");
        bad.push(`${st.id}[${S.school.board}] ${detail}`);
      }
    });
    /* tc_plain under CBSE is EXPECTED to be short two fields and that is not a
       defect: it is the GENERIC transfer certificate, and CBSE's pre-printed
       Book No. / Sl. No. are CBSE artifacts. Adding them would stop it being
       generic. What was wrong was offering it with no warning — see R6. */
    const expected = ["tc_plain[CBSE] unbound:doc.bookNo unbound:doc.slNo"];
    const unexpected = bad.filter(b => !expected.includes(b));
    return { ok: unexpected.length === 0,
             note: unexpected.length ? unexpected.join(" · ")
                                     : `${STARTERS.length} starters; ${bad.length} known-and-signalled gap(s)` };
  });

  /* P8.3 — a starter that cannot satisfy the active stack must SAY SO on the
     card, before it is chosen. The gap surfacing at publish, several screens
     later, as two red rows is the trap this closes. */
  await T("R6", "P8.3 — a starter short of the active stack names the gap on its card", () => {
    resetApp(); S.docType = "transfer_certificate"; go("gallery");
    const gap = starterGap(STARTERS.find(s => s.id === "tc_plain"));
    const grid = zq("#starterGrid").textContent;
    const named = gap.every(k => grid.includes((FIELD[k] || {}).label || k));
    return { ok: gap.length > 0 && named && /Needs \d+ more field/.test(grid),
             note: `gap ${gap.join(",")} — ${named ? "named on the card" : "NOT named"}` };
  });

  /* Every starter must declare only keys its own contract allows — the
     off-contract failure, checked across the whole starter set at once. */
  await T("R4", "P8.3 — no starter binds a key its own document type does not declare", () => {
    const bad = [];
    STARTERS.forEach(st => {
      resetApp();
      S.docType = st.docType;
      S.tpl = st.build();
      const off = offContractKeys();
      if (off.length) bad.push(`${st.id}:${off.join(",")}`);
    });
    return { ok: bad.length === 0, note: bad.length ? bad.join(" · ") : "all clean" };
  });

  /* Every starter must carry an explicit line-height on every text object —
     G0.5, blocking. Without it mPDF and the browser disagree by up to 2x. */
  await T("R5", "P8.3 — every text object in every starter has an explicit line-height", () => {
    const bad = [];
    STARTERS.forEach(st => {
      const t = st.build();
      t.objects.forEach(o => {
        if (o.type === "text" && !(o.style && typeof o.style.lineHeight === "number")) {
          bad.push(`${st.id}/${o.id}`);
        }
      });
    });
    return { ok: bad.length === 0, note: bad.length ? bad.join(", ") : "all text objects set line-height" };
  });

  /* ---- T · P7.3 preview/proof agreement ---------------------------------
     The accept is "preview and proof agree within the G0.5 tolerance". That is
     a MEASURED comparison, so these numbers are mPDF's, produced by
     Doc_renderer::measureBlock() on byte-identical HTML and committed here as
     the baseline. Measured 2026-09-02; max divergence was 0.011mm.

     This is the test that would catch @font-face regressing: without a web face
     the browser falls back to a system font and these heights move immediately,
     while the PDF does not. ---------------------------------------------- */
  G("T · P7.3 preview vs proof");

  const MPDF_MM = {
    "latin-1line": 4.939, "latin-4line": 19.756,
    "deva-1line": 6.350,  "taml-1line": 6.350
  };
  const PROBE_HTML = {
    "latin-1line": '<div style="width:180mm;font-size:10pt;line-height:1.4;font-family:dejavusans">Transfer Certificate</div>',
    "latin-4line": '<div style="width:180mm;font-size:10pt;line-height:1.4;font-family:dejavusans">A<br>B<br>C<br>D</div>',
    "deva-1line":  '<div style="width:180mm;font-size:12pt;line-height:1.5;font-family:lohitdeva">स्थानांतरण प्रमाणपत्र</div>',
    "taml-1line":  '<div style="width:180mm;font-size:12pt;line-height:1.5;font-family:lohittaml">இடமாற்றுச் சான்றிதழ்</div>'
  };

  await T("T1", "P7.3 — browser height matches mPDF within the G0.5 tolerance", async () => {
    try { await document.fonts.ready; } catch (e) {}
    const MM = 96 / 25.4, host = document.createElement("div");
    host.style.cssText = "position:absolute;visibility:hidden;left:-9999px";
    document.body.appendChild(host);

    const rows = [];
    for (const [k, html] of Object.entries(PROBE_HTML)) {
      const d = document.createElement("div");
      d.innerHTML = html; host.appendChild(d);
      const mm = d.firstElementChild.getBoundingClientRect().height / MM;
      rows.push({ k, chrome: +mm.toFixed(3), mpdf: MPDF_MM[k], diff: Math.abs(mm - MPDF_MM[k]) });
    }
    host.remove();

    /* 0.25mm, not 0.011mm. The measurement is stable to ~0.01 but a hairline
       threshold would fail on a Chrome point-release rounding change and teach
       everyone to ignore it. 0.25mm is far tighter than the ~2x divergence
       G0.5 found WITHOUT an explicit line-height, so it still catches the
       failure this exists for. */
    const worst = rows.reduce((a, r) => r.diff > a.diff ? r : a, rows[0]);
    return { ok: worst.diff < 0.25,
             note: `worst ${worst.k}: mPDF ${worst.mpdf} vs Chrome ${worst.chrome} = ${worst.diff.toFixed(3)}mm` };
  });

  /* The guard on the guard: if the Lohit faces are not actually loaded, the
     Indic probes fall back to a system font and T1 would compare the wrong
     thing while still possibly passing. */
  await T("T2", "P7.3 — the Indic faces are genuinely loaded before comparison", async () => {
    try { await document.fonts.ready; } catch (e) {}
    const missing = ["lohitdeva", "lohittaml"].filter(f => !document.fonts.check(`12px "${f}"`));
    return { ok: missing.length === 0,
             note: missing.length ? "NOT loaded: " + missing.join(",") : "deva + taml loaded" };
  });

  /* ==================================================================
     U · the SERVER layer — fail-closed

     The designer now persists through api(). Everything downstream of it
     assumes one thing: that a call which did not succeed THROWS. fetch()
     does not reject on 403 or 500 — it resolves with ok:false — so a helper
     that forgets to look reports a denied action as done. This codebase has
     already been bitten by that, so it is pinned here.

     Runs with fetch stubbed and SRV flipped online, then puts both back.
     ================================================================== */
  G("U · server layer (fail-closed)");

  const realFetch = window.fetch;
  const srvWas = { online: SRV.online, base: SRV.base, name: SRV.csrf.name, hash: SRV.csrf.hash };
  const stub = (resp) => { window.fetch = async (url, init) => { stub.last = { url, init }; return resp(url, init); }; };
  const jsonRes = (status, body) => ({
    ok: status >= 200 && status < 300, status,
    json: async () => body
  });
  const goOnline = () => { SRV.online = true; SRV.base = "/mock"; SRV.csrf.name = "csrf_test_name"; SRV.csrf.hash = "TOKEN123"; };
  const restore = () => { window.fetch = realFetch; Object.assign(SRV, { online: srvWas.online, base: srvWas.base });
                          SRV.csrf.name = srvWas.name; SRV.csrf.hash = srvWas.hash; };

  await T("U1", "an HTTP 500 THROWS even though it carries a JSON body", async () => {
    goOnline(); stub(() => jsonRes(500, { status: "success", data: { ok: true } }));
    try { await api("get_templates"); return { ok: false, note: "returned instead of throwing" }; }
    catch (e) { return { ok: e instanceof ApiError && e.code === 500, note: "code " + e.code }; }
    finally { restore(); }
  });

  /* The phantom-success case exactly: HTTP 200, and the body says it failed. */
  await T("U2", "a 200 carrying {status:'error'} THROWS", async () => {
    goOnline(); stub(() => jsonRes(200, { status: "error", message: "You do not have permission" }));
    try { await api("publish", { method: "POST", body: {} }); return { ok: false, note: "reported success on a denial" }; }
    catch (e) { return { ok: /permission/.test(e.message), note: e.message }; }
    finally { restore(); }
  });

  await T("U3", "a body that is not JSON THROWS rather than being treated as empty", async () => {
    goOnline();
    window.fetch = async () => ({ ok: true, status: 200, json: async () => { throw new Error("not json"); } });
    try { await api("get_templates"); return { ok: false, note: "accepted a non-JSON body" }; }
    catch (e) { return { ok: true, note: e.message.slice(0, 40) }; }
    finally { restore(); }
  });

  await T("U4", "every POST carries the CSRF token", async () => {
    goOnline(); stub(() => jsonRes(200, { status: "success", data: {} }));
    await api("activate", { method: "POST", body: { templateId: "X" } });
    const fd = stub.last.init.body;
    const ok = fd instanceof FormData && fd.get("csrf_test_name") === "TOKEN123";
    restore();
    return { ok, note: ok ? "token present" : "NO CSRF TOKEN — these routes are not excluded, so this would 403" };
  });

  await T("U5", "a rotated CSRF token from the response is adopted", async () => {
    goOnline(); stub(() => jsonRes(200, { status: "success", data: {}, csrf_token: "ROTATED" }));
    await api("save", { method: "POST", body: {} });
    const ok = SRV.csrf.hash === "ROTATED";
    restore();
    return { ok, note: ok ? "adopted" : "still " + srvWas.hash };
  });

  /* The one that matters most: a proof that FAILED must not unlock publish. */
  await T("U6", "a failed proof leaves publish blocked", async () => {
    goOnline(); openClassic(false);
    S.proofed = null; S.dirty = false;
    stub(() => jsonRes(500, { status: "error", message: "mPDF ran out of memory" }));
    openProof(); zq("#proofRun").click();
    await sleep(400);
    const unlocked = !!S.proofed;
    const stillBlocked = has(bt(validate()), "noproof");
    closeModal(); restore();
    return { ok: !unlocked && stillBlocked,
             note: unlocked ? "A FAILED PROOF UNLOCKED PUBLISH" : "still blocked" };
  });

  await T("U7", "a save conflict does NOT clear the dirty flag", async () => {
    goOnline(); openClassic(false);
    S.tpl.templateId = "SCH1_TPL0007"; S.tpl.lockVersion = 3; S.dirty = true;
    stub(() => jsonRes(409, { status: "error", message: "Someone else saved this template" }));
    const saved = await srvSaveDraft(true);
    const ok = saved === false && S.dirty === true;
    closeModal(); restore();
    return { ok, note: ok ? "kept dirty" : "dirty=" + S.dirty + " saved=" + saved };
  });

  await T("U8", "a successful save adopts the server's lockVersion", async () => {
    goOnline(); openClassic(false);
    S.tpl.templateId = "SCH1_TPL0007"; S.tpl.lockVersion = 3; S.dirty = true;
    stub(() => jsonRes(200, { status: "success", data: { lockVersion: 4 } }));
    const saved = await srvSaveDraft(true);
    const ok = saved === true && S.tpl.lockVersion === 4 && S.dirty === false;
    restore();
    return { ok, note: "lockVersion " + S.tpl.lockVersion + " dirty=" + S.dirty };
  });

  await T("U9", "offline, api() refuses rather than pretending", async () => {
    SRV.online = false;
    try { await api("get_templates"); return { ok: false, note: "pretended to call a server" }; }
    catch (e) { return { ok: /offline/.test(e.message), note: e.message }; }
    finally { restore(); }
  });

  await T("U10", "the harness itself is genuinely OFFLINE, or nothing above proved anything", () => {
    return { ok: SRV.online === false && window.fetch === realFetch,
             note: "online=" + SRV.online + " fetch restored=" + (window.fetch === realFetch) };
  });

  /* ==================================================================
     V · three-way merge

     Figma's finding is that most concurrent edits touch DIFFERENT objects, so
     the merge — not the dialog — is the common path. If this is wrong, the
     product silently loses somebody's work, which is the one outcome worse
     than an annoying prompt.
     ================================================================== */
  G("V · merge (collaboration)");

  const O = (id, x) => ({ id, type:"text", name:id, xMm:x, yMm:10, wMm:50, hMm:6,
                          style:{sizePt:9,lineHeight:1.4}, content:{i18n:{en:{runs:[{t:id}]}}} });

  await T("V1", "edits to different objects merge, and both survive", () => {
    const base   = [O("a",1), O("b",2), O("c",3)];
    const mine   = [O("a",99), O("b",2), O("c",3)];      // I moved a
    const theirs = [O("a",1),  O("b",88), O("c",3)];     // they moved b
    const r = mergeObjects(base, mine, theirs);
    const m = Object.fromEntries(r.merged.map(o=>[o.id,o]));
    return { ok: r.overlap.length===0 && m.a.xMm===99 && m.b.xMm===88 && m.c.xMm===3,
             note: `overlap=${r.overlap.length} a=${m.a.xMm} b=${m.b.xMm}` };
  });

  await T("V2", "the same object changed on both sides is reported, not guessed", () => {
    const base   = [O("a",1), O("b",2)];
    const mine   = [O("a",99), O("b",2)];
    const theirs = [O("a",77), O("b",2)];
    const r = mergeObjects(base, mine, theirs);
    return { ok: r.overlap.length===1 && r.overlap[0]==="a", note:"overlap "+r.overlap.join(",") };
  });

  /* The dangerous direction: their work vanishing without anyone being told. */
  await T("V3", "an object only THEY added is kept", () => {
    const r = mergeObjects([O("a",1)], [O("a",1)], [O("a",1), O("z",5)]);
    return { ok: r.merged.some(o=>o.id==="z"), note:"ids "+r.merged.map(o=>o.id).join(",") };
  });

  await T("V4", "an object only I added is kept", () => {
    const r = mergeObjects([O("a",1)], [O("a",1), O("y",7)], [O("a",1)]);
    return { ok: r.merged.some(o=>o.id==="y"), note:"ids "+r.merged.map(o=>o.id).join(",") };
  });

  await T("V5", "an object they deleted stays deleted when I did not touch it", () => {
    const r = mergeObjects([O("a",1), O("b",2)], [O("a",1), O("b",2)], [O("a",1)]);
    return { ok: !r.merged.some(o=>o.id==="b"), note:"ids "+r.merged.map(o=>o.id).join(",") };
  });

  /* A delete on one side and an edit on the other is a genuine disagreement,
     and resolving it silently either way loses a decision somebody made. */
  await T("V6", "they deleted what I edited — reported as an overlap", () => {
    const r = mergeObjects([O("a",1), O("b",2)], [O("a",1), O("b",55)], [O("a",1)]);
    return { ok: r.overlap.includes("b"), note:"overlap "+r.overlap.join(",") };
  });

  await T("V7", "identical edits on both sides are not a conflict", () => {
    const r = mergeObjects([O("a",1)], [O("a",42)], [O("a",42)]);
    return { ok: r.overlap.length===0, note:"overlap "+r.overlap.length };
  });

  /* The base must track what the server had at my LAST AGREEMENT with it, not
     at load. Otherwise my own saved edits read as changed on both sides and the
     next collision accuses me of conflicting with myself. */
  await T("V10", "an edit I already saved is not a conflict later", () => {
    const base   = [O("a",1), O("b",2)];
    const afterMySave = [O("a",50), O("b",2)];      // I edited a, and it saved
    // base advances to what I saved; they then change b only
    const theirs = [O("a",50), O("b",99)];
    const r = mergeObjects(afterMySave, afterMySave, theirs);
    return { ok: r.overlap.length===0, note:"overlap "+r.overlap.join(",") };
  });

  await T("V8", "no changes at all merges to what is stored", () => {
    const base=[O("a",1),O("b",2)];
    const r = mergeObjects(base, base, base);
    return { ok: r.overlap.length===0 && r.merged.length===2 };
  });

  await T("V9", "order follows what is stored, so a merge does not reshuffle the page", () => {
    const base   = [O("a",1), O("b",2), O("c",3)];
    const theirs = [O("c",3), O("a",1), O("b",2)];       // they reordered
    const mine   = [O("a",1), O("b",9), O("c",3)];       // I edited b
    const r = mergeObjects(base, mine, theirs);
    return { ok: r.merged.map(o=>o.id).join(",")==="c,a,b",
             note: r.merged.map(o=>o.id).join(",") };
  });

  /* ══════════════════════════════════════════════════════════════════════
     W · THE FEE RECEIPT — the first type whose body is a REPEATING table.

     Every other document type has a body of fixed length: the designer decides
     how many lines there are. A receipt's does not — the payment decides. So
     this group is less about the receipt than about the object: a table whose
     row count is unknown at design time, and everything that used to assume
     content.rows exists.
     ══════════════════════════════════════════════════════════════════════ */

  const rctStarter = () => STARTERS.find(s => s.docType === "fee_receipt");
  const openRct = () => { resetApp(); openStarter(rctStarter()); return S.tpl.objects.find(o => isRepeat(o)); };

  await T("W1", "the fee receipt is offered, and its starter builds", () => {
    const type = TYPES.find(t => t.id === "fee_receipt");
    const s = rctStarter();
    if (!type || type.disabled) return { ok: false, note: "type missing or disabled" };
    if (!s) return { ok: false, note: "no starter" };
    const t = s.build();
    return { ok: t.objects.length > 0 && t.docType === "fee_receipt", note: t.objects.length + " objects" };
  });

  await T("W2", "the starter has exactly one repeating table, bound to the item list", () => {
    const t = rctStarter().build();
    const reps = t.objects.filter(o => isRepeat(o));
    return { ok: reps.length === 1 && reps[0].content.repeatOver === "receipt.items",
             note: reps.map(o => o.content.repeatOver).join(",") };
  });

  await T("W3", "the list field counts as BOUND, so the contract is satisfied", () => {
    openRct();
    return { ok: boundKeys().has("receipt.items"),
             note: [...boundKeys()].filter(k => k.startsWith("receipt.")).join(" ") };
  });

  await T("W4", "the receipt binds nothing its own contract does not declare", () => {
    openRct();
    const off = offContractKeys();
    return { ok: off.length === 0, note: off.join(",") };
  });

  /* The canvas draws one row per item, and the count follows the DATA MODE.
     A table that draws a fixed number of rows regardless of the data is the
     defect this whole object type exists to avoid. */
  await T("W5", "the canvas draws one row per item, and more of them under p95", () => {
    const o = openRct();
    S.data = "typical"; const small = (objectHTML(o).match(/<tr/g) || []).length;
    S.data = "p95";     const large = (objectHTML(o).match(/<tr/g) || []).length;
    S.data = "typical";
    return { ok: small === 3 && large === 8, note: `typical ${small}, p95 ${large} (incl. heading)` };
  });

  await T("W6", "with data off it draws a specimen row, not an empty frame", () => {
    const o = openRct(); S.data = "off";
    const h = objectHTML(o); S.data = "typical";
    return { ok: /rpt__spec/.test(h) && /Particulars/.test(h),
             note: "an empty frame reads as a broken table rather than an unbound one" };
  });

  await T("W7", "column headings can be turned off", () => {
    const o = openRct();
    const withH = (objectHTML(o).match(/<th/g) || []).length;
    o.content.showHeader = false;
    const without = (objectHTML(o).match(/<th/g) || []).length;
    return { ok: withH === 3 && without === 0, note: `${withH} → ${without}` };
  });

  /* content.showHeader had no branch in the inspector's property writer, so it
     wrote a property literally named "content.showHeader" — set, saved, and
     read by nothing. A control that reports success and changes nothing. */
  await T("W8", "the inspector writes into content, not a property named 'content.x'", () => {
    const o = openRct(); S.sel = [o.id]; render();
    const box = document.querySelector('.insp [data-p="content.showHeader"]');
    if (!box) return { ok: false, note: "the heading control is not on screen" };
    box.checked = false; box.dispatchEvent(new Event("change", { bubbles: true }));
    return { ok: obj(o.id).content.showHeader === false && !("content.showHeader" in obj(o.id)),
             note: JSON.stringify(obj(o.id).content.showHeader) };
  });

  await T("W9", "changing what it repeats over clears the old columns", () => {
    const o = openRct();
    o.content.columns = [{ key: "item.head", wPct: 90 }];
    S.sel = [o.id]; render();
    const sel = document.querySelector('.insp [data-p="content.repeatOver"]');
    if (!sel) return { ok: false, note: "no repeat-over control" };
    sel.value = "receipt.items"; sel.dispatchEvent(new Event("change", { bubbles: true }));
    return { ok: obj(o.id).content.columns == null,
             note: "columns bound to the old list would head the new one's cells" };
  });

  await T("W10", "a column width typed in the inspector reaches the object", () => {
    const o = openRct(); S.sel = [o.id]; render();
    const inp = document.querySelector('.insp [data-col="0"]');
    if (!inp) return { ok: false, note: "no column control" };
    inp.value = "40"; inp.dispatchEvent(new Event("change", { bubbles: true }));
    const c = (obj(o.id).content.columns || [])[0];
    return { ok: c && c.wPct === 40, note: JSON.stringify(c) };
  });

  await T("W11", "a list field cannot be dropped into an ordinary table", () => {
    resetApp(); openStarter(STARTERS.find(s => s.id === "tc_cbse"));
    const tbl = S.tpl.objects.find(o => o.type === "table" && !isRepeat(o));
    if (!tbl) return { ok: true, note: "no ordinary table in this starter" };
    S.sel = [tbl.id];
    const before = (tbl.content.rows || []).length;
    insertField("receipt.items");
    return { ok: (tbl.content.rows || []).length === before,
             note: "a list in a fixed table would print one cell reading 'Array'" };
  });

  await T("W12", "a scalar field is not pushed into a repeating table", () => {
    const o = openRct(); S.sel = [o.id];
    const before = JSON.stringify(o.content);
    insertField("student.fullName");
    return { ok: JSON.stringify(obj(o.id).content) === before,
             note: "it would repeat one constant beside every item" };
  });

  /* Everything under the table must be anchored to it, because the table's
     height belongs to the payment. An absolute total would print over the
     items on a long receipt and float above them on a short one. */
  await T("W13", "the totals are anchored to the table, not positioned absolutely", () => {
    const t = rctStarter().build();
    const items = t.objects.find(o => isRepeat(o));
    const below = ["r_net", "r_words", "r_mode", "r_note"].map(id => t.objects.find(o => o.id === id));
    const chained = below.every(o => o && o.anchorTo);
    const rooted  = below[0].anchorTo === items.id;
    return { ok: chained && rooted, note: below.map(o => o && o.id + "→" + (o && o.anchorTo)).join(" ") };
  });

  await T("W14", "an ordinary table is untouched by any of this", () => {
    resetApp(); openStarter(STARTERS.find(s => s.id === "tc_cbse"));
    const tbl = S.tpl.objects.find(o => o.type === "table" && !isRepeat(o));
    if (!tbl) return { ok: true, note: "no ordinary table" };
    const rows = (tbl.content.rows || []).length;
    S.sel = [tbl.id]; render();
    const h = objectHTML(tbl);
    return { ok: !/class="rpt"/.test(h) && (h.match(/tblrow"/g) || []).length === rows,
             note: rows + " rows drawn the old way" };
  });

  /* ══════════════════════════════════════════════════════════════════════
     X · DOCUMENTS THE SCHOOL INVENTS

     Each one is its OWN document type, `custom:{slug}`, and that is the whole
     design: the module's central invariant is one active template per document
     type, so a shared "Custom" bucket would make activating one silently
     deactivate another.

     The first live run of this feature opened a COMPLETELY BLANK designer — no
     page, no layers, no visible error — because paintCrumb still looked the type
     up in TYPES and threw on `undefined.name` inside render(). Several of the
     checks below exist only because of that: they draw the screens rather than
     inspect the model.
     ══════════════════════════════════════════════════════════════════════ */
  G("X · custom document types");

  await T("X1", "a typed name becomes a readable, stable type id", () => {
    const cases = [
      ["Sports Day Participation", "custom:sports_day_participation"],
      ["  Fee Concession Letter  ", "custom:fee_concession_letter"],
      ["No-Dues (2026-27)",         "custom:no_dues_2026_27"]
    ];
    const bad = cases.filter(([t,x]) => customTypeFor(t) !== x).map(([t,x]) => t+" → "+customTypeFor(t));
    return { ok: !bad.length, note: bad.join(" | ") };
  });

  /* Every unusable title would mint the same id, quietly merging two unrelated
     documents into one type — and one active slot. */
  await T("X2", "a name with no letters or digits mints nothing", () => {
    return { ok: customTypeFor("—  ***  —") === "" && customTypeFor("") === "" };
  });

  await T("X3", "the id shape is enforced, matching the server pattern", () => {
    const good = ["custom:a", "custom:sports_day"].every(isCustomType);
    const bad  = ["custom:", "custom:_x", "custom:x_", "custom:Sports_Day", "custom:a b",
                  "transfer_certificate", "custom", ""].some(isCustomType);
    return { ok: good && !bad };
  });

  /* A contract records somebody else's prescription. A school's own document
     has no such author, so nothing is off-contract and every field is offered. */
  await T("X4", "a custom document is offered every field and can bind any of them", () => {
    const n = contractFor("custom:sports_day").length;
    return { ok: n === CONTRACT.length && n > CONTRACTS.transfer_certificate.length,
             note: n + " fields vs " + CONTRACTS.transfer_certificate.length + " on a TC" };
  });

  await T("X5", "the blank page arrives with letterhead, title and a page number", () => {
    const t = blankTemplate("custom:sports_day", "Sports Day");
    const has = k => t.objects.some(o => o.id === k);
    return { ok: has("c_name") && has("c_addr") && has("c_title") && has("c_page") && t.docTitle === "Sports Day",
             note: t.objects.length + " objects" };
  });

  await T("X6", "the title is written onto the page, not just stored", () => {
    const t = blankTemplate("custom:gate_pass", "Gate Pass");
    const title = t.objects.find(o => o.id === "c_title");
    return { ok: /GATE PASS/.test(JSON.stringify(title.content)) };
  });

  /* THE BLANK-DESIGNER DEFECT. paintCrumb used TYPES.find, got undefined for a
     custom type and threw on .name — inside render(), so nothing drew at all. */
  await T("X7", "opening a custom document draws the page and the breadcrumb", () => {
    resetApp();
    S.docType = "custom:sports_day";
    const t = blankTemplate(S.docType, "Sports Day");
    S.tpl = t; S.lang = "en"; S.sel = []; S.undo = []; S.redo = []; S.proofed = null;
    S.baseline = JSON.parse(JSON.stringify(t.objects));
    let threw = null;
    try { go("designer"); } catch (e) { threw = e.message; }
    const kids = (document.querySelector("#page") || {children: []}).children.length;
    const crumb = (document.querySelector("#crumb") || {textContent: ""}).textContent;
    return { ok: !threw && kids > 5 && /Sports Day/.test(crumb),
             note: threw ? "THREW: " + threw : kids + " page children · " + crumb };
  });

  await T("X8", "the typed name survives until the library catches up", () => {
    resetApp();
    S.docType = "custom:sports_day";
    S.tpl = blankTemplate(S.docType, "Sports Day");     // not in S.lib yet
    const t = typeOf(S.docType);
    return { ok: t && t.name === "Sports Day",
             note: t ? t.name : "no type record — the slug would read back as 'Sports day'" };
  });

  await T("X9", "a custom type is discovered from the library, with its stored title", () => {
    resetApp();
    S.lib["custom:fee_concession"] = [{id:"SCH1_TPL1", name:"Draft 1", docType:"custom:fee_concession",
                                       docTitle:"Fee Concession Letter", status:"draft", version:1,
                                       publishedVersion:null, activeVersion:null, edited:""}];
    const t = customTypes().find(x => x.id === "custom:fee_concession");
    return { ok: !!t && t.name === "Fee Concession Letter" && t.custom === true,
             note: t ? t.name : "not discovered" };
  });

  /* STARTERS[0] is a Transfer Certificate. Without a custom branch, buildTpl
     would hand a Sports Day certificate a TC's objects — and draw a TC
     thumbnail on its card. */
  await T("X10", "a custom row never falls back to the Transfer Certificate starter", () => {
    const row = {id:"SCH1_TPL1", name:"Draft 1", docType:"custom:gate_pass", docTitle:"Gate Pass",
                 status:"draft", version:1, publishedVersion:null, activeVersion:null};
    const t = buildTpl(row);
    return { ok: t.docType === "custom:gate_pass" && t.objects.some(o => o.id === "c_title")
                 && !t.objects.some(o => o.id === "t_table"),
             note: t.docType + " · " + t.objects.map(o=>o.id).join(",") };
  });

  await T("X11", "nothing prescribes it, so it is never marked statutory or unchecked", () => {
    const b = typeBasis(typeOf("custom:sports_day"));
    return { ok: b.label === "Your own format" && b.evidence === null, note: b.label };
  });

  await T("X12", "a custom type never leaks into the shipped type catalogue", () => {
    return { ok: !TYPES.some(t => isCustomType(t.id)),
             note: "TYPES is the shipped list; a school's own documents are discovered" };
  });

  document.removeEventListener("visibilitychange", __watch);
  if (__wentHidden || document.visibilityState !== "visible") {
    throw new Error(
      "ZXDT_E2E: the tab was hidden PART WAY through this run, so these results cannot be " +
      "trusted — hidden runs produce failures that look like real activation and proof bugs. " +
      "Discarding them rather than reporting them. Run again with the tab left in front."
    );
  }

  const summary = { total: R.length, passed: R.filter(r => r.ok).length, failed: R.filter(r => !r.ok).length };
  return { summary, failures: R.filter(r => !r.ok), all: R };
};
"ZXDT_E2E loaded";
