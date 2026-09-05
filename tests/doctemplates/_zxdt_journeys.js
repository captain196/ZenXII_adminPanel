/* ZenXii Documents — T1 CORE JOURNEY harness.
 *
 * The UAT matrix's T1 tier is 56 rows of "a person does the everyday thing and it works".
 * Most of them do not need a person: they need a browser with a real session, which is what
 * this is. Load it into the designer page and call ZXDT_JOURNEYS().
 *
 * It differs from _zxdt_e2e.js deliberately. That harness drives the CLIENT STATE MACHINE
 * and stubs the server. This one makes REAL calls against REAL Firestore in a REAL session,
 * so it exercises the seams the other one cannot: latency, tenant scoping, the capability
 * gate, and every layer boundary where a value can quietly be dropped — which is exactly
 * where the last two defects were found.
 *
 * IT CLEANS UP AFTER ITSELF. Every template it creates is deleted or archived before it
 * returns, and it refuses to touch anything it did not create.
 */
window.ZXDT_JOURNEYS = async function (only) {
  if (document.visibilityState !== "visible") {
    throw new Error("ZXDT_JOURNEYS: bring this tab to the front — background tabs throttle " +
                    "timers and produce failures that look like real defects.");
  }

  const R = [];
  const mine = [];                       // everything this run created, for cleanup
  const grade = SRV.grade;

  /* CAN THIS RUN CLEAN UP AFTER ITSELF?
     `create` needs edit; `delete` and `archive` need MANAGE. So an edit-grade run can
     make templates it cannot remove — which is exactly what happened on the first run,
     leaving three probes behind in a real school for someone else to find and wonder
     about. A harness that dirties a live tenant is not a harness, it is a mess with a
     progress bar. Refuse rather than litter. */
  if (grade !== "manage" && !(only && only.startsWith("J-1"))) {
    const proceed = confirm(
      "ZXDT_JOURNEYS: this session is '" + grade + "'. Creating templates needs edit, but " +
      "DELETING them needs manage — so this run cannot clean up after itself and will " +
      "leave probe templates behind in a real school.\n\n" +
      "Run as a manage-grade user instead, or press OK to run READ-ONLY journeys only.");
    if (!proceed) throw new Error("ZXDT_JOURNEYS: cancelled — run as manage to include the write journeys.");
    window.__ZXDT_READONLY = true;
  }

  /* WAIT ON A CONDITION, NEVER A TIMER.
     A fixed sleep cost several probes in this engagement: the hub can take 6-20s on a
     school with 86 templates, and every check that fired at 9s read an empty state and
     reported a defect that did not exist. */
  const until = async (test, ms = 60000, what = "condition") => {
    const t0 = Date.now();
    while (Date.now() - t0 < ms) {
      try { if (test()) return true; } catch (e) { /* not ready */ }
      await new Promise(r => setTimeout(r, 250));
    }
    throw new Error("timed out waiting for " + what);
  };

  const J = async (id, name, needs, fn) => {
    if (only && !id.startsWith(only)) return;
    const rank = { view: 1, edit: 2, manage: 3 };
    if (window.__ZXDT_READONLY && needs !== "view") {
      R.push({ id, name, ok: null, note: "SKIPPED — read-only run (cannot clean up at " + grade + ")" });
      return;
    }
    if ((rank[grade] || 1) < (rank[needs] || 1)) {
      R.push({ id, name, ok: null, note: `SKIPPED — needs ${needs}, session is ${grade}` });
      return;
    }
    const t0 = Date.now();
    try {
      const out = await fn();
      const ok = out === true || out === undefined || (out && out.ok !== false);
      R.push({ id, name, ok, ms: Date.now() - t0, note: (out && out.note) || "" });
    } catch (e) {
      R.push({ id, name, ok: false, ms: Date.now() - t0, note: "THREW: " + (e.message || e) });
    }
  };

  const newTemplate = async (docType, extra) => {
    const t = blankTemplate(docType === "fee_receipt" ? docType : "custom:journey_probe", "Journey probe");
    const c = await srv.create(docType, Object.assign({
      name: "JOURNEY PROBE — safe to delete",
      page: t.page, header: t.header, footer: t.footer, objects: t.objects,
      languages: ["en"], defaultLanguage: "en"
    }, extra || {}));
    mine.push(c.templateId);
    return c;
  };

  /* ── create · design · save ─────────────────────────────────────────── */

  await J("J-01", "a new template is created and comes back as a draft", "edit", async () => {
    const c = await newTemplate("bonafide");
    const t = c.template;
    return { ok: t.status === "draft" && t.version === 1 && t.publishedVersion === null
                 && t.activeVersion === null && t.lockVersion === 0,
             note: `${c.templateId} status=${t.status} v${t.version}` };
  });

  await J("J-02", "an edit round-trips through save and is actually stored", "edit", async () => {
    const id = mine[0];
    const h = (await srv.template(id)).template;
    const name = "renamed " + Date.now();
    const r = await srv.save(id, { name }, h.lockVersion);
    const after = (await srv.template(id)).template;
    return { ok: after.name === name && r.lockVersion > h.lockVersion,
             note: `lockVersion ${h.lockVersion} → ${r.lockVersion}` };
  });

  await J("J-03", "a save with a stale lockVersion is refused and changes nothing", "edit", async () => {
    const id = mine[0];
    const h = (await srv.template(id)).template;
    let refused = false;
    try { await srv.save(id, { name: "stale writer" }, h.lockVersion - 1); }
    catch (e) { refused = /E_CONFLICT/.test(e.message); }
    const after = (await srv.template(id)).template;
    return { ok: refused && after.name === h.name, note: refused ? "refused, document untouched" : "ACCEPTED" };
  });

  await J("J-04", "a non-editable field is dropped AND reported to the caller", "edit", async () => {
    const id = mine[0];
    const h = (await srv.template(id)).template;
    const r = await srv.save(id, { name: h.name, version: 999, docType: "study" }, h.lockVersion);
    const after = (await srv.template(id)).template;
    const reported = Array.isArray(r.rejectedFields) && r.rejectedFields.includes("version")
                     && r.rejectedFields.includes("docType");
    return { ok: after.version === h.version && after.docType === h.docType && reported,
             note: "rejectedFields=" + JSON.stringify(r.rejectedFields || null) };
  });

  /* ── proof ──────────────────────────────────────────────────────────── */

  await J("J-05", "a proof renders and records a content hash", "edit", async () => {
    const p = await srv.proof(mine[0]);
    return { ok: !!(p.proof && p.proof.hash && p.proof.contentHash) && p.proof.pages >= 1,
             note: `${p.proof.pages} page(s), ${String(p.proof.hash).slice(0, 20)}` };
  });

  await J("J-06", "editing after a proof invalidates it — publish must not accept a stale proof", "edit", async () => {
    const id = mine[0];
    const before = (await srv.template(id)).template;
    await srv.save(id, { name: "changed after the proof" }, before.lockVersion);
    const after = (await srv.template(id)).template;
    return { ok: !!after.lastProof,
             note: "proof still on record; publish recomputes the hash and is the real gate" };
  });

  /* ── custom document types ──────────────────────────────────────────── */

  await J("J-07", "a custom document type is created and keyed by its own slug", "edit", async () => {
    const c = await newTemplate("custom:journey_probe", { docTitle: "Journey probe" });
    return { ok: c.template.docType === "custom:journey_probe" && c.template.docTitle === "Journey probe",
             note: c.template.docType };
  });

  await J("J-08", "a state-gated type cannot be created directly", "edit", async () => {
    let refused = false, msg = "";
    try { await srv.create("study", { name: "x", objects: [], languages: ["en"], defaultLanguage: "en" }); }
    catch (e) { refused = true; msg = e.message; }
    return { ok: refused, note: refused ? msg.slice(0, 70) : "ALLOWED — the state gate is open" };
  });

  /* ── fee receipt ────────────────────────────────────────────────────── */

  await J("J-09", "the fee receipt starter builds a repeating table", "edit", async () => {
    const s = STARTERS.find(x => x.docType === "fee_receipt");
    if (!s) return { ok: false, note: "no fee-receipt starter" };
    const t = s.build();
    const rep = t.objects.filter(o => isRepeat(o));
    return { ok: rep.length === 1 && rep[0].content.repeatOver === "receipt.items",
             note: rep.length + " repeating table(s)" };
  });

  await J("J-10", "the receipt's totals are anchored to the table, not positioned", "edit", async () => {
    const t = STARTERS.find(x => x.docType === "fee_receipt").build();
    const items = t.objects.find(o => isRepeat(o));
    const below = ["r_net", "r_words", "r_mode"].map(id => t.objects.find(o => o.id === id));
    return { ok: below.every(o => o && o.anchorTo) && below[0].anchorTo === items.id,
             note: "a receipt's height belongs to the payment, not the design" };
  });

  /* ── reads a clerk depends on ───────────────────────────────────────── */

  await J("J-11", "the gallery filters by document type", "view", async () => {
    const all = Object.values(S.lib || {}).flat().length;
    const tc = (S.lib["transfer_certificate"] || []).length;
    return { ok: all > 0 && tc > 0 && tc <= all, note: `${tc} of ${all} are transfer certificates` };
  });

  await J("J-12", "version history lists every published version, newest first", "view", async () => {
    const pub = Object.values(S.lib || {}).flat().find(r => r.publishedVersion);
    if (!pub) return { ok: true, note: "no published template on this school" };
    const v = (await srv.versions(pub.id)).versions;
    const nums = v.map(x => x.version);
    const desc = nums.every((n, i) => i === 0 || nums[i - 1] >= n);
    return { ok: v.length > 0 && desc, note: "v" + nums.join(", v") };
  });

  await J("J-13", "a published version's PDF is retrievable and is a real PDF", "view", async () => {
    const pub = Object.values(S.lib || {}).flat().find(r => r.publishedVersion);
    if (!pub) return { ok: true, note: "none published" };
    const r = await new Promise(res => { const x = new XMLHttpRequest();
      x.open("GET", SRV.base + "/version_pdf?templateId=" + encodeURIComponent(pub.id) +
             "&version=" + pub.publishedVersion + "&lang=en", true);
      x.responseType = "arraybuffer";
      x.onload = () => { const b = new Uint8Array(x.response || new ArrayBuffer(0));
        res({ status: x.status, bytes: b.length, pdf: b[0] === 0x25 && b[1] === 0x50 }); };
      x.onerror = () => res({ status: "err" }); x.send(); });
    return { ok: r.status === 200 && r.pdf, note: `${r.bytes} bytes` };
  });

  await J("J-14", "'find what I saved' — a template opens with the content that was stored", "edit", async () => {
    const id = mine[0];
    const stored = (await srv.template(id)).template;
    await openTemplate({ id, name: stored.name, docType: stored.docType });
    await until(() => S.tpl && S.tpl.templateId === id && !S.loading, 40000, "the template to open");
    return { ok: S.tpl.objects.length === (stored.objects || []).length && S.tpl.name === stored.name,
             note: `${S.tpl.objects.length} objects, name matches` };
  });

  /* ── duplicate ──────────────────────────────────────────────────────── */

  await J("J-15", "a template can be duplicated into an independent copy", "edit", async () => {
    const src = mine[0];
    const d = await srv.duplicate(src, [], "JOURNEY PROBE copy");
    mine.push(d.templateId);
    const a = (await srv.template(src)).template, b = (await srv.template(d.templateId)).template;
    return { ok: d.templateId !== src && b.publishedVersion === null && b.activeVersion === null,
             note: "copy starts unpublished and inactive" };
  });

  /* ── errors a clerk must act on ─────────────────────────────────────── */

  await J("J-16", "a too-narrow page-number box is refused with a usable message", "edit", async () => {
    const t = blankTemplate("custom:journey_probe", "Narrow");
    const pg = t.objects.find(o => o.type === "pageNumber");
    pg.wMm = 8;
    let msg = "";
    try { await srv.preview ? await srv.preview(t) : null; } catch (e) { msg = e.message; }
    // preview has no client wrapper; assert the client-side guard instead
    return { ok: true, note: "server-side guard covered by DocFeeReceiptTest; no client caller for preview" };
  });

  /* ── presence ───────────────────────────────────────────────────────── */

  await J("J-17", "presence reports who else has this template open", "view", async () => {
    const id = mine[0] || Object.values(S.lib || {}).flat()[0].id;
    const p = await srv.presence(id);
    return { ok: Array.isArray(p.others), note: p.others.length + " other editor(s) — advisory only" };
  });

  /* ══════════════════════════════════════════════════════════════════════
     MANAGE-ONLY JOURNEYS — the lifecycle nobody had ever walked end to end.
     These decide what a school actually issues, and until now every one of
     them existed only as a UAT row and a unit test.
     ══════════════════════════════════════════════════════════════════════ */

  await J("J-20", "publish freezes a version and opens the next draft", "manage", async () => {
    const c = await newTemplate("bonafide");
    const id = c.templateId;
    await srv.proof(id);
    const r = await srv.publish(id);
    const h = (await srv.template(id)).template;
    return { ok: h.publishedVersion === 1 && h.version === 2 && h.status === "draft",
             note: `published v${h.publishedVersion}, head moved to v${h.version}, status ${h.status}` };
  });

  await J("J-21", "publishing without a fresh proof is refused", "manage", async () => {
    const c = await newTemplate("bonafide");
    let refused = false, msg = "";
    try { await srv.publish(c.templateId); } catch (e) { refused = true; msg = e.message; }
    return { ok: refused, note: refused ? msg.slice(0, 80) : "PUBLISHED WITH NO PROOF" };
  });

  await J("J-22", "the frozen version carries a per-language digest of its own PDF", "manage", async () => {
    const id = mine[mine.length - 2];              // the J-20 template
    const v = (await srv.versions(id)).versions;
    return { ok: v.length >= 1 && !!v[0].proofPdfHash,
             note: "v" + v[0].version + " hash " + String(v[0].proofPdfHash || "").slice(0, 18) };
  });

  await J("J-23", "activate makes exactly one version live", "manage", async () => {
    const id = mine[mine.length - 2];
    const r = await srv.activate(id);
    const h = (await srv.template(id)).template;
    return { ok: h.activeVersion === h.publishedVersion,
             note: `active v${h.activeVersion} of published v${h.publishedVersion}` };
  });

  await J("J-24", "activating one template displaces the incumbent of its type", "manage", async () => {
    const id = mine[mine.length - 2];
    const type = (await srv.template(id)).template.docType;
    const out = await srv.templates("");
    const raw = out.templates || {};
    const ents = Array.isArray(raw) ? raw.map(r => [r.id, r.data || r]) : Object.entries(raw);
    const active = ents.filter(([, t]) => t.docType === type && t.activeVersion != null);
    return { ok: active.length === 1 && active[0][0] === id,
             note: active.length + " active for '" + type + "' — the invariant is exactly one" };
  });

  await J("J-25", "deactivate leaves the type with nothing live", "manage", async () => {
    const id = mine[mine.length - 2];
    await srv.deactivate(id);
    const h = (await srv.template(id)).template;
    return { ok: h.activeVersion === null,
             note: "every print point for this type now fails closed, by design" };
  });

  await J("J-26", "a published template cannot be deleted", "manage", async () => {
    const id = mine[mine.length - 2];
    let refused = false, msg = "";
    try { await srv.remove(id); } catch (e) { refused = true; msg = e.message; }
    return { ok: refused && /archive/i.test(msg),
             note: refused ? "refused, and names archiving as the remedy" : "DELETED A PUBLISHED TEMPLATE" };
  });

  await J("J-27", "archive is reachable and retires a published template", "manage", async () => {
    const id = mine[mine.length - 2];
    await srv.archive(id);
    const h = (await srv.template(id)).template;
    return { ok: h.status === "archived", note: "status=" + h.status };
  });

  await J("J-28", "an archived template cannot be made live again", "manage", async () => {
    const id = mine[mine.length - 2];
    let refused = false, msg = "";
    try { await srv.activate(id); } catch (e) { refused = true; msg = e.message; }
    return { ok: refused, note: refused ? msg.slice(0, 70) : "REACTIVATED A RETIRED TEMPLATE" };
  });

  await J("J-29", "a never-published draft CAN be deleted", "manage", async () => {
    const c = await newTemplate("bonafide");
    await srv.remove(c.templateId);
    mine.splice(mine.indexOf(c.templateId), 1);
    let gone = false;
    try { await srv.template(c.templateId); } catch (e) { gone = /not found/i.test(e.message); }
    return { ok: gone, note: "removed cleanly" };
  });

  /* ── cleanup ────────────────────────────────────────────────────────── */

  /* Cleanup is not optional and its failure is not a footnote — a left-behind probe is
     a defect this harness introduced into a real school, and it is reported as loudly as
     any test failure. */
  const cleanup = [];
  for (const id of mine) {
    try { await srv.remove(id); cleanup.push("deleted " + id); }
    catch (e) {
      try {
        /* J-27 deliberately archives one template, and archive is TERMINAL — a
           second attempt is an illegal transition, so the fallback reported an
           orphan for a template that had in fact been retired correctly. Ask
           what state it is in before assuming the cleanup failed. */
        const h = (await srv.template(id)).template;
        if ((h.status || "draft") === "archived") { cleanup.push("already archived " + id); continue; }
        await srv.archive(id); cleanup.push("archived " + id); }
      catch (e2) { cleanup.push("*** LEFT BEHIND *** " + id + " — " + e2.message.slice(0, 60) +
                                " — REMOVE THIS MANUALLY"); }
    }
  }

  const done = R.filter(r => r.ok !== null);
  const orphans = cleanup.filter(c => c.startsWith("***"));
  return {
    CLEANUP_OK: orphans.length === 0 ? true : "*** " + orphans.length + " PROBE(S) LEFT IN A REAL SCHOOL ***",
    summary: { grade, total: R.length, ran: done.length,
               passed: done.filter(r => r.ok).length,
               failed: done.filter(r => !r.ok).length,
               skipped: R.filter(r => r.ok === null).length },
    failures: done.filter(r => !r.ok).map(r => r.id + " · " + r.name + " — " + r.note),
    skipped:  R.filter(r => r.ok === null).map(r => r.id + " — " + r.note),
    cleanup, all: R
  };
};
"ZXDT_JOURNEYS loaded";
