/* Layout / responsive audit for the Certificate Designer.
   Reports geometry at whatever viewport the page is loaded in — drive it by
   launching Chrome at different --window-size values. */
window.ZXDT_LAYOUT = async function () {
  /* Kill transitions before measuring anything. This is a LAYOUT audit — the
     final resting geometry is what matters, and an in-flight transition
     reports an intermediate transform. It is also load-bearing under headless
     Chrome, whose virtual clock does not advance CSS transitions at all: a
     transitioned property stays pinned at its start value forever, which reads
     exactly like a broken rule. */
  const killMotion = document.createElement("style");
  killMotion.textContent = "*,*::before,*::after{transition:none !important;animation:none !important}";
  document.head.appendChild(killMotion);
  const settle = () => new Promise(r => setTimeout(r, 60));
  const vis = el => {
    if (!el) return false;
    const s = getComputedStyle(el);
    if (s.display === "none" || s.visibility === "hidden" || +s.opacity === 0) return false;
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  };
  const rect = el => { const r = el.getBoundingClientRect(); return { x: Math.round(r.x), y: Math.round(r.y), w: Math.round(r.width), h: Math.round(r.height) }; };
  const W = innerWidth, H = innerHeight;

  // get into the designer with a real template
  try { S.tpl = starterTC(); go("designer"); S.sel = [S.tpl.objects.find(o => o.type === "text").id]; render(); } catch (e) { }

  const rail = document.querySelector(".zxdt .rail") || document.querySelector(".rail");
  const insp = document.querySelector(".zxdt .insp") || document.querySelector(".insp");
  const desk = document.querySelector(".zxdt .desk") || document.querySelector(".desk");
  const topbar = document.querySelector(".zxdt .topbar") || document.querySelector(".topbar");

  const out = {
    viewport: `${W}x${H}`,
    railVisible: vis(rail),
    inspVisible: vis(insp),
    deskVisible: vis(desk),
    deskRect: desk ? rect(desk) : null,
    horizontalOverflow: document.documentElement.scrollWidth > W + 1,
    scrollWidth: document.documentElement.scrollWidth,
    problems: []
  };

  // 1 · every panel must be REACHABLE, whether inline or as a drawer
  const railEl = rail, inspEl = insp;
  if (!out.railVisible) {
    // the tabstrip is the documented way back in — prove it works
    const tab = document.querySelector('#tabstrip button[data-pane="content"]');
    out.railReopenControl = !!(tab && vis(tab));
    const cs0 = getComputedStyle(railEl);
    out.debug = {
      closeDrawersFn: typeof closeDrawers,
      railIsDrawerFn: typeof railIsDrawer !== "undefined" ? railIsDrawer() : "undefined",
      mq1020: matchMedia("(max-width:1020px)").matches,
      railBefore: { display: cs0.display, visibility: cs0.visibility, position: cs0.position, transform: cs0.transform.slice(0, 40) }
    };
    if (tab) tab.click();
    await settle();
    const cs1 = getComputedStyle(railEl);
    out.debug.railAfterClick = { display: cs1.display, visibility: cs1.visibility, position: cs1.position, transform: cs1.transform.slice(0, 40), hasIsOpen: railEl.classList.contains("is-open") };
    out.railReachable = vis(railEl);
    if (out.railReachable) {
      const r = rect(railEl);
      if (r.left < 0 || r.right > W + 1)
        out.problems.push({ sev: "high", what: "rail drawer off-screen", detail: JSON.stringify(r) });
      // and it must close again
      if (typeof closeDrawers === "function") { closeDrawers(); await settle(); if (vis(railEl)) out.problems.push({ sev: "med", what: "rail drawer will not close", detail: "" }); }
    } else {
      out.problems.push({ sev: "high", what: "left rail unreachable", detail: "hidden with no working route back — Insert/Fields/Blocks/Layers/Content all lost" });
    }
  }
  if (!out.inspVisible) {
    const btn = document.querySelector("#inspBtn");
    out.inspReopenControl = !!(btn && vis(btn));
    if (btn && vis(btn)) btn.click();
    await settle();
    out.inspReachable = vis(inspEl);
    if (out.inspReachable) {
      const r = rect(inspEl);
      if (r.left < 0 || r.right > W + 1)
        out.problems.push({ sev: "high", what: "inspector drawer off-screen", detail: JSON.stringify(r) });
      if (typeof closeDrawers === "function") { closeDrawers(); await settle(); if (vis(inspEl)) out.problems.push({ sev: "med", what: "inspector drawer will not close", detail: "" }); }
    } else {
      out.problems.push({ sev: "high", what: "inspector unreachable", detail: "hidden with no working route back — object properties lost" });
    }
  }

  // 2 · content taller than its scroll container but not scrollable
  [["rail", rail], ["inspector", insp]].forEach(([name, node]) => {
    if (!node || !vis(node)) return;
    node.querySelectorAll("*").forEach(c => {
      const cs = getComputedStyle(c);
      if (c.scrollHeight > c.clientHeight + 4 && /hidden|visible/.test(cs.overflowY) && c.clientHeight > 40)
        out.problems.push({ sev: "med", what: name + " content trapped", detail: `${c.className || c.tagName} ${c.scrollHeight}px in ${c.clientHeight}px, overflow-y:${cs.overflowY}` });
    });
  });

  // 3 · anything painted outside the viewport AND not reachable by scrolling.
  //     Content inside an overflow-x:auto ancestor is scrollable, not clipped —
  //     flagging it would be a false positive.
  const inScrollable = n => {
    for (let p = n.parentElement; p; p = p.parentElement) {
      const ov = getComputedStyle(p).overflowX;
      if (ov === "auto" || ov === "scroll") return true;
    }
    return false;
  };
  const offscreen = [];
  document.querySelectorAll(".zxdt .topbar *, .zxdt .rail *, .zxdt .insp *, .zxdt .statusbar *").forEach(n => {
    if (!vis(n) || inScrollable(n)) return;
    const r = n.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) return;
    if (r.right > W + 1 || r.left < -1)
      offscreen.push((n.className || n.tagName).toString().slice(0, 28) + " right=" + Math.round(r.right));
  });
  if (offscreen.length) out.problems.push({ sev: "med", what: "clipped horizontally — unreachable", detail: offscreen.slice(0, 5).join(" | ") + (offscreen.length > 5 ? ` (+${offscreen.length - 5})` : "") });

  // 4 · the desk squeezed to nothing
  if (desk && vis(desk) && rect(desk).w < 320)
    out.problems.push({ sev: "high", what: "canvas squeezed", detail: rect(desk).w + "px wide" });

  // 5 · modals must fit the viewport and scroll internally
  try {
    openPublish();
    const m = document.querySelector(".zxdt .modal") || document.querySelector("#modal");
    if (m && vis(m)) {
      const r = m.getBoundingClientRect();
      out.modalRect = rect(m);
      if (r.height > H + 1) out.problems.push({ sev: "high", what: "modal taller than viewport", detail: `${Math.round(r.height)}px in ${H}px` });
      if (r.width > W + 1) out.problems.push({ sev: "high", what: "modal wider than viewport", detail: `${Math.round(r.width)}px in ${W}px` });
      if (r.top < 0) out.problems.push({ sev: "high", what: "modal top cut off", detail: "top=" + Math.round(r.top) });
      const body = document.querySelector("#mBody");
      if (body && body.scrollHeight > body.clientHeight + 4 && !/auto|scroll/.test(getComputedStyle(body).overflowY))
        out.problems.push({ sev: "high", what: "modal body not scrollable", detail: `${body.scrollHeight}px in ${body.clientHeight}px` });
      const foot = document.querySelector("#mFoot");
      if (foot && vis(foot) && foot.getBoundingClientRect().bottom > H + 1)
        out.problems.push({ sev: "high", what: "modal buttons below the fold", detail: "bottom=" + Math.round(foot.getBoundingClientRect().bottom) });
    }
    closeModal();
  } catch (e) { out.problems.push({ sev: "med", what: "modal audit threw", detail: e.message }); }

  // 6 · the rail tabstrip must not overflow its own width
  const ts = document.querySelector(".zxdt #tabstrip");
  if (ts && vis(ts) && ts.scrollWidth > ts.clientWidth + 2)
    out.problems.push({ sev: "med", what: "rail tabstrip overflows", detail: `${ts.scrollWidth}px in ${ts.clientWidth}px — a pane tab is unreachable` });

  // 7 · status bar overflow — only a problem if it cannot be scrolled to
  const sb = document.querySelector(".zxdt .statusbar");
  if (sb && vis(sb) && sb.scrollWidth > sb.clientWidth + 2) {
    const ov = getComputedStyle(sb).overflowX;
    if (ov !== "auto" && ov !== "scroll")
      out.problems.push({ sev: "med", what: "status bar truncated", detail: `${sb.scrollWidth}px in ${sb.clientWidth}px, overflow-x:${ov}` });
    else out.statusBarScrolls = true;
  }

  return out;
};
"ZXDT_LAYOUT loaded";
