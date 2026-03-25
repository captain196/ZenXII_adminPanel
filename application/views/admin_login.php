<div class="content-wrapper">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Clash+Display:wght@400;500;600;700&family=Satoshi:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<!-- Fallback if Clash Display CDN not available -->
<link href="https://api.fontshare.com/v2/css?f[]=clash-display@400,500,600,700&f[]=satoshi@300,400,500,600,700&display=swap" rel="stylesheet">

<style>
/* ═══════════════════════════════════════════════════════════
   SCHOOL ADMIN LOGIN  ·  Diagonal Split + Floating Glass
   Same teal/forest/gold colours — radically different layout
   ═══════════════════════════════════════════════════════════ */
:root {
    --brand:       #0d9488;
    --brand2:      #0f766e;
    --brand3:      #2dd4bf;
    --brand-dim:   rgba(13,148,136,.12);
    --brand-ring:  rgba(13,148,136,.24);
    --gold:        #d4a843;
    --gold2:       #f0c060;
    --forest:      #1a2e1a;
    --forest2:     #0f1f10;
    --leaf:        #4a7c59;
    --sans:        'Satoshi', 'Plus Jakarta Sans', system-ui, sans-serif;
    --display:     'Clash Display', 'Satoshi', system-ui, sans-serif;
    --mono:        'JetBrains Mono', ui-monospace, monospace;
    --ease:        .22s cubic-bezier(.4,0,.2,1);
    --ease-spring: .5s cubic-bezier(.34,1.56,.64,1);
}

[data-theme="light"] {
    --bg:          #e8f0e9;
    --surface:     rgba(255,255,255,.82);
    --surface2:    rgba(255,255,255,.55);
    --border:      rgba(13,148,136,.15);
    --border2:     rgba(13,148,136,.28);
    --text:        #0a1a0a;
    --text2:       #2a4a2a;
    --muted:       #5a7a5a;
    --muted2:      #a0baa0;
    --input-bg:    rgba(240,248,240,.8);
    --input-foc:   rgba(255,255,255,.95);
    --glass-bg:    rgba(255,255,255,.75);
    --glass-brd:   rgba(255,255,255,.9);
    --sh:          0 8px 32px rgba(13,148,136,.12), 0 2px 8px rgba(0,0,0,.06);
    --sh-card:     0 24px 80px rgba(13,80,60,.18), 0 4px 16px rgba(0,0,0,.08);
    --red:         #dc2626;
    --red-bg:      rgba(220,38,38,.06);
    --red-brd:     rgba(220,38,38,.2);
    --green-ok:    #16a34a;
}

[data-theme="dark"] {
    --bg:          #080e08;
    --surface:     rgba(20,35,20,.92);
    --surface2:    rgba(30,50,30,.7);
    --border:      rgba(45,212,191,.12);
    --border2:     rgba(45,212,191,.22);
    --text:        #e4f0e4;
    --text2:       #8ab88a;
    --muted:       #4a6a4a;
    --muted2:      #2a3e2a;
    --input-bg:    rgba(20,40,20,.8);
    --input-foc:   rgba(25,50,25,.95);
    --glass-bg:    rgba(15,30,15,.85);
    --glass-brd:   rgba(45,212,191,.15);
    --sh:          0 8px 32px rgba(0,0,0,.5), 0 2px 8px rgba(0,0,0,.3);
    --sh-card:     0 32px 80px rgba(0,0,0,.6), 0 0 0 1px rgba(45,212,191,.08);
    --red:         #f87171;
    --red-bg:      rgba(248,113,113,.07);
    --red-brd:     rgba(248,113,113,.2);
    --green-ok:    #4ade80;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

/* ── Full-screen stage ─────────────────────────────────── */
.lx-stage {
    font-family: var(--sans);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    width: 100%;
    display: grid;
    place-items: center;
    padding: 20px;
    position: relative;
    overflow: hidden;
}

/* ── Animated mesh background ─────────────────────────── */
.lx-mesh {
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 0;
}

.lx-orb {
    position: absolute;
    border-radius: 50%;
    filter: blur(80px);
    animation: lxFloat linear infinite;
    will-change: transform;
}

.lx-orb-1 {
    width: 600px; height: 600px;
    background: radial-gradient(circle, rgba(13,148,136,.18) 0%, transparent 65%);
    top: -150px; left: -150px;
    animation-duration: 18s;
}
.lx-orb-2 {
    width: 500px; height: 500px;
    background: radial-gradient(circle, rgba(26,46,26,.35) 0%, transparent 65%);
    bottom: -100px; right: -100px;
    animation-duration: 22s;
    animation-delay: -8s;
}
.lx-orb-3 {
    width: 350px; height: 350px;
    background: radial-gradient(circle, rgba(212,168,67,.08) 0%, transparent 65%);
    top: 40%; left: 55%;
    animation-duration: 26s;
    animation-delay: -14s;
}
[data-theme="dark"] .lx-orb-1 { background: radial-gradient(circle, rgba(13,148,136,.22) 0%, transparent 65%); }
[data-theme="dark"] .lx-orb-2 { background: radial-gradient(circle, rgba(15,30,10,.8) 0%, transparent 65%); }
[data-theme="dark"] .lx-orb-3 { background: radial-gradient(circle, rgba(212,168,67,.06) 0%, transparent 65%); }

@keyframes lxFloat {
    0%,100% { transform: translate(0,0) scale(1); }
    33%      { transform: translate(40px, -30px) scale(1.05); }
    66%      { transform: translate(-20px, 25px) scale(.97); }
}

/* Fine grain overlay */
.lx-grain {
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 1;
    opacity: .025;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)'/%3E%3C/svg%3E");
    background-size: 200px 200px;
}

/* ── Theme button ──────────────────────────────────────── */
.lx-theme-btn {
    position: fixed;
    top: 16px; right: 18px;
    z-index: 300;
    display: flex;
    align-items: center;
    gap: 6px;
    background: var(--glass-bg);
    border: 1px solid var(--glass-brd);
    border-radius: 40px;
    padding: 7px 13px 7px 10px;
    cursor: pointer;
    font-family: var(--mono);
    font-size: 10px;
    color: var(--text2);
    letter-spacing: .5px;
    backdrop-filter: blur(12px);
    box-shadow: var(--sh);
    animation: lxFadeDown .5s .4s ease both;
    transition: all var(--ease);
}
.lx-theme-btn:hover { border-color: var(--brand3); color: var(--text); transform: scale(1.03); }

[data-theme="light"] .ico-moon { display: none; }
[data-theme="light"] .ico-sun  { color: var(--brand); font-size: 12px; }
[data-theme="dark"]  .ico-sun  { display: none; }
[data-theme="dark"]  .ico-moon { color: var(--gold2); font-size: 12px; }

.lx-auto-tag {
    font-size: 9px;
    padding: 1px 6px;
    border-radius: 20px;
    background: var(--brand-dim);
    color: var(--brand3);
    border: 1px solid var(--brand-ring);
}

/* ── Main wrapper — diagonal split ───────────────────── */
.lx-wrap {
    position: relative;
    z-index: 10;
    width: 100%;
    max-width: 1020px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    min-height: 580px;
    gap: 0;
    animation: lxRise .7s cubic-bezier(.16,1,.3,1) both;
}
@keyframes lxRise {
    from { opacity: 0; transform: translateY(32px) scale(.98); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

/* ── Left: big visual side ────────────────────────────── */
.lx-visual {
    background: linear-gradient(145deg, var(--forest2) 0%, var(--forest) 40%, #0d2a0d 70%, #071407 100%);
    border-radius: 24px 0 0 24px;
    padding: 52px 48px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    position: relative;
    overflow: hidden;
}

/* Diagonal slash accent */
.lx-visual::before {
    content: '';
    position: absolute;
    top: 0; right: -1px; bottom: 0;
    width: 80px;
    background: linear-gradient(to right, transparent, var(--bg));
    clip-path: polygon(40% 0%, 100% 0%, 100% 100%, 0% 100%);
    z-index: 2;
    pointer-events: none;
}
[data-theme="dark"] .lx-visual::before {
    background: linear-gradient(to right, transparent, var(--bg));
}

/* Gold top bar */
.lx-visual::after {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--gold), var(--gold2), transparent);
    border-radius: 24px 0 0 0;
}

/* Grid lines on visual side */
.lx-vgrid {
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(rgba(45,212,191,.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(45,212,191,.04) 1px, transparent 1px);
    background-size: 44px 44px;
    pointer-events: none;
}

/* Big number accent */
.lx-big-num {
    position: absolute;
    bottom: -20px; right: 40px;
    font-family: var(--display);
    font-size: clamp(140px, 18vw, 200px);
    font-weight: 700;
    color: transparent;
    -webkit-text-stroke: 1px rgba(45,212,191,.08);
    line-height: 1;
    user-select: none;
    pointer-events: none;
    letter-spacing: -8px;
}

.lx-vtop {
    position: relative;
    z-index: 3;
    animation: lxSlideR .5s .1s ease both;
}

.lx-brand {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 0;
}

.lx-brand-mark {
    position: relative;
}

.lx-brand-icon-wrap {
    width: 52px; height: 52px;
    border-radius: 16px;
    background: linear-gradient(135deg, var(--leaf), var(--brand2));
    border: 1px solid rgba(212,168,67,.3);
    display: flex; align-items: center; justify-content: center;
    font-size: 24px;
    box-shadow: 0 6px 24px rgba(0,0,0,.3), 0 0 0 1px rgba(45,212,191,.1);
    position: relative;
    overflow: hidden;
}
.lx-brand-icon-wrap::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(255,255,255,.15) 0%, transparent 60%);
}

.lx-brand-text { }
.lx-brand-name {
    font-family: var(--display);
    font-size: 19px;
    font-weight: 600;
    color: #fff;
    letter-spacing: -.3px;
    line-height: 1;
}
.lx-brand-sub {
    font-family: var(--mono);
    font-size: 9.5px;
    color: rgba(255,255,255,.35);
    margin-top: 4px;
    letter-spacing: 1.5px;
    text-transform: uppercase;
}

/* Mid — headline */
.lx-vmid {
    position: relative;
    z-index: 3;
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    padding: 40px 0;
    animation: lxSlideR .5s .18s ease both;
}

.lx-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-family: var(--mono);
    font-size: 10px;
    color: var(--brand3);
    letter-spacing: 2.5px;
    text-transform: uppercase;
    margin-bottom: 18px;
}
.lx-eyebrow-dot {
    width: 5px; height: 5px;
    border-radius: 50%;
    background: var(--brand3);
    box-shadow: 0 0 8px var(--brand3);
    animation: lxPulse 2s ease-in-out infinite;
}
@keyframes lxPulse { 0%,100%{opacity:1} 50%{opacity:.3} }

.lx-headline {
    font-family: var(--display);
    font-size: clamp(32px, 4vw, 46px);
    font-weight: 700;
    color: #fff;
    line-height: 1.05;
    letter-spacing: -1.5px;
    margin-bottom: 18px;
}
.lx-headline em {
    font-style: normal;
    display: block;
    background: linear-gradient(90deg, var(--brand3), var(--gold2));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.lx-sub-text {
    font-size: 14px;
    color: rgba(255,255,255,.42);
    line-height: 1.75;
    max-width: 280px;
}

/* Feature pills */
.lx-features {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 22px;
}
.lx-feat-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 11px;
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(45,212,191,.15);
    border-radius: 20px;
    font-size: 11px;
    color: rgba(255,255,255,.55);
    font-family: var(--mono);
    transition: all var(--ease);
}
.lx-feat-pill:hover {
    background: rgba(45,212,191,.08);
    border-color: rgba(45,212,191,.3);
    color: var(--brand3);
}
.lx-feat-pill i { font-size: 9px; color: var(--brand3); }

/* Stats */
.lx-vbottom {
    position: relative;
    z-index: 3;
    animation: lxSlideR .5s .26s ease both;
}

.lx-stats {
    display: flex;
    gap: 0;
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(45,212,191,.1);
    border-radius: 14px;
    overflow: hidden;
}

.lx-stat {
    flex: 1;
    padding: 14px 16px;
    border-right: 1px solid rgba(45,212,191,.08);
    transition: background var(--ease);
}
.lx-stat:last-child { border-right: none; }
.lx-stat:hover { background: rgba(45,212,191,.05); }

.lx-stat-num {
    font-family: var(--display);
    font-size: 22px;
    font-weight: 700;
    color: var(--gold2);
    line-height: 1;
    letter-spacing: -.5px;
}
.lx-stat-lbl {
    font-family: var(--mono);
    font-size: 9px;
    color: rgba(255,255,255,.3);
    margin-top: 4px;
    letter-spacing: 1px;
    text-transform: uppercase;
}

/* ── Right: glass form card ───────────────────────────── */
.lx-form-side {
    background: var(--glass-bg);
    border-radius: 0 24px 24px 0;
    border: 1px solid var(--glass-brd);
    border-left: none;
    backdrop-filter: blur(20px);
    padding: 52px 50px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    position: relative;
    overflow: hidden;
    box-shadow: var(--sh-card);
}

/* Subtle inner glow top */
.lx-form-side::before {
    content: '';
    position: absolute;
    top: 0; left: 20%; right: 20%;
    height: 1px;
    background: linear-gradient(90deg, transparent, var(--brand-ring), transparent);
}

/* ── Form head ─────────────────────────────────────────── */
.lx-form-head {
    margin-bottom: 30px;
    animation: lxSlideL .5s .15s ease both;
}

.lx-form-kicker {
    display: flex;
    align-items: center;
    gap: 8px;
    font-family: var(--mono);
    font-size: 10px;
    color: var(--muted);
    letter-spacing: 2px;
    text-transform: uppercase;
    margin-bottom: 10px;
}
.lx-form-kicker::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
}

.lx-form-h2 {
    font-family: var(--display);
    font-size: 28px;
    font-weight: 700;
    color: var(--text);
    letter-spacing: -.6px;
    line-height: 1.1;
    margin-bottom: 6px;
}

.lx-form-sub {
    font-size: 13.5px;
    color: var(--muted);
    line-height: 1.6;
}

/* Mode pill */
.lx-mode-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 12px;
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 4px 10px;
    font-family: var(--mono);
    font-size: 10.5px;
    color: var(--muted);
}
.lx-mode-dot {
    width: 5px; height: 5px;
    border-radius: 50%;
    animation: lxPulse 2s ease-in-out infinite;
}
[data-theme="light"] .lx-mode-dot { background: var(--brand); box-shadow: 0 0 6px rgba(13,148,136,.5); }
[data-theme="dark"]  .lx-mode-dot { background: var(--gold); box-shadow: 0 0 6px rgba(212,168,67,.5); }

/* Alerts */
.lx-alert {
    display: flex;
    align-items: center;
    gap: 10px;
    border-radius: 10px;
    padding: 12px 14px;
    margin-bottom: 20px;
    font-size: 13px;
    border-left: 3px solid;
    backdrop-filter: blur(8px);
    animation: lxShake .4s ease both;
}
.lx-alert.error   { background: var(--red-bg);  border-color: var(--red);      color: var(--red); }
.lx-alert.success { background: rgba(13,148,136,.07); border-color: var(--green-ok); color: var(--green-ok); }

@keyframes lxShake {
    0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-5px)} 40%,80%{transform:translateX(5px)}
}

/* ── Fields ────────────────────────────────────────────── */
.lx-form { animation: lxSlideL .5s .22s ease both; }

/* Two fields side by side */
.lx-field-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 14px;
}

.lx-fgroup { margin-bottom: 14px; }
.lx-fgroup.full { grid-column: 1 / -1; }

.lx-flabel {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 10.5px;
    font-weight: 700;
    color: var(--text2);
    text-transform: uppercase;
    letter-spacing: .8px;
    margin-bottom: 7px;
    font-family: var(--sans);
}
.lx-flabel i { font-size: 9.5px; color: var(--brand); }

.lx-finput-wrap { position: relative; }

.lx-finput-wrap input {
    width: 100%;
    background: var(--input-bg);
    border: 1.5px solid var(--border);
    border-radius: 10px;
    padding: 11px 42px 11px 14px;
    font-size: 13.5px;
    font-family: var(--sans);
    color: var(--text);
    outline: none;
    caret-color: var(--brand);
    transition: all var(--ease);
    backdrop-filter: blur(6px);
}
.lx-finput-wrap input::placeholder { color: var(--muted2); }
.lx-finput-wrap input:focus {
    border-color: var(--brand);
    background: var(--input-foc);
    box-shadow: 0 0 0 3px var(--brand-dim);
    transform: translateY(-1px);
}

.lx-ficon {
    position: absolute;
    right: 13px; top: 50%;
    transform: translateY(-50%);
    color: var(--muted);
    font-size: 12.5px;
    pointer-events: none;
    transition: color var(--ease);
}
.lx-finput-wrap:focus-within .lx-ficon { color: var(--brand); }
.lx-pw-toggle { pointer-events: all; cursor: pointer; }
.lx-pw-toggle:hover { color: var(--text); }

/* Separator */
.lx-sep {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 6px 0 14px;
    color: var(--muted2);
    font-family: var(--mono);
    font-size: 9px;
    letter-spacing: 2px;
    text-transform: uppercase;
}
.lx-sep::before, .lx-sep::after { content:''; flex:1; height:1px; background:var(--border); }

/* Submit */
.lx-btn {
    width: 100%;
    padding: 14px;
    background: linear-gradient(135deg, var(--brand2), var(--brand), var(--leaf));
    background-size: 200% 200%;
    background-position: 0% 50%;
    border: none;
    border-radius: 12px;
    color: #fff;
    font-family: var(--display);
    font-size: 14.5px;
    font-weight: 600;
    letter-spacing: .2px;
    cursor: pointer;
    margin-top: 6px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 6px 24px rgba(13,148,136,.35);
    transition: all .4s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
}
.lx-btn:hover {
    background-position: 100% 50%;
    transform: translateY(-2px);
    box-shadow: 0 10px 32px rgba(13,148,136,.45);
}
.lx-btn:active { transform: translateY(0); }
.lx-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }

/* Shimmer on hover */
.lx-btn::after {
    content: '';
    position: absolute;
    top: -50%; left: -60%;
    width: 40%; height: 200%;
    background: rgba(255,255,255,.15);
    transform: skewX(-15deg);
    transition: left .5s ease;
}
.lx-btn:hover::after { left: 120%; }

.lx-btn-inner { display: flex; align-items: center; gap: 9px; position: relative; z-index: 1; }

.lx-btn-spinner {
    display: none;
    width: 18px; height: 18px;
    border: 2px solid rgba(255,255,255,.25);
    border-top-color: #fff;
    border-radius: 50%;
    animation: lxSpin .65s linear infinite;
}
.lx-btn.loading .lx-btn-inner  { display: none; }
.lx-btn.loading .lx-btn-spinner { display: block; }
@keyframes lxSpin { to { transform: rotate(360deg); } }

/* Footer */
.lx-form-foot {
    margin-top: 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    animation: lxSlideL .5s .30s ease both;
}

.lx-secure {
    display: flex;
    align-items: center;
    gap: 6px;
    font-family: var(--mono);
    font-size: 10.5px;
    color: var(--muted);
}
.lx-secure i { color: var(--green-ok); font-size: 10px; }

.lx-forgot {
    font-size: 12px;
    font-weight: 600;
    color: var(--brand);
    text-decoration: none;
    transition: all var(--ease);
    display: flex;
    align-items: center;
    gap: 4px;
}
.lx-forgot:hover { color: var(--brand3); gap: 7px; }

/* ── Smooth theme transitions ─────────────────────────── */
.lx-t-ready *, .lx-t-ready *::before, .lx-t-ready *::after {
    transition: background-color .3s ease, border-color .3s ease,
                color .3s ease, box-shadow .3s ease !important;
}

/* ── Animations ─────────────────────────────────────────── */
@keyframes lxSlideR {
    from { opacity:0; transform:translateX(-20px); }
    to   { opacity:1; transform:translateX(0); }
}
@keyframes lxSlideL {
    from { opacity:0; transform:translateX(20px); }
    to   { opacity:1; transform:translateX(0); }
}
@keyframes lxFadeDown {
    from { opacity:0; transform:translateY(-10px); }
    to   { opacity:1; transform:translateY(0); }
}

/* ── Responsive ─────────────────────────────────────────── */
@media (max-width: 860px) {
    .lx-wrap { grid-template-columns: 1fr; max-width: 480px; }
    .lx-visual { border-radius: 24px 24px 0 0; padding: 36px 32px; }
    .lx-visual::before { display: none; }
    .lx-form-side { border-radius: 0 0 24px 24px; border-left: 1px solid var(--glass-brd); border-top: none; padding: 36px 32px; }
    .lx-stats { display: none; }
    .lx-headline { font-size: 28px; }
    .lx-vmid { padding: 24px 0 20px; }
    .lx-field-row { grid-template-columns: 1fr; }
    .lx-stage { overflow: auto; align-items: flex-start; padding: 20px; }
}
@media (max-width: 460px) {
    .lx-visual, .lx-form-side { padding: 28px 22px; }
    .lx-headline { font-size: 24px; }
}
</style>

<div class="lx-stage" id="lxStage">

    <!-- Animated mesh background -->
    <div class="lx-mesh">
        <div class="lx-orb lx-orb-1"></div>
        <div class="lx-orb lx-orb-2"></div>
        <div class="lx-orb lx-orb-3"></div>
    </div>
    <div class="lx-grain"></div>

    <!-- Theme toggle -->
    <button class="lx-theme-btn" id="lxThemeBtn" title="Toggle theme (double-click = auto)">
        <i class="fas fa-sun  ico-sun"></i>
        <i class="fas fa-moon ico-moon"></i>
        <span id="lxThemeLabel">DAY</span>
        <span class="lx-auto-tag" id="lxAutoTag">AUTO</span>
    </button>

    <!-- Main layout -->
    <div class="lx-wrap">

        <!-- ══ Visual left ══ -->
        <div class="lx-visual">
            <div class="lx-vgrid"></div>
            <div class="lx-big-num">S</div>

            <div class="lx-vtop">
                <div class="lx-brand">
                    <div class="lx-brand-mark">
                        <div class="lx-brand-icon-wrap">🏫</div>
                    </div>
                    <div class="lx-brand-text">
                        <div class="lx-brand-name">SchoolXAdmin</div>
                        <div class="lx-brand-sub">Management System</div>
                    </div>
                </div>
            </div>

            <div class="lx-vmid">
                <div class="lx-eyebrow">
                    <div class="lx-eyebrow-dot"></div>
                    <h2>Admin Portal</h2>
                </div>
                <h1 class="lx-headline">
                    Manage your<br>
                    <em>school smarter.</em>
                </h1>
                <p class="lx-sub-text">Students, staff, attendance, fees — everything rooted in one place.</p>

                <div class="lx-features">
                    <div class="lx-feat-pill"><i class="fas fa-users"></i> Students</div>
                    <div class="lx-feat-pill"><i class="fas fa-chalkboard-teacher"></i> Staff</div>
                    <div class="lx-feat-pill"><i class="fas fa-calendar-check"></i> Attendance</div>
                    <div class="lx-feat-pill"><i class="fas fa-money-bill-wave"></i> Fees</div>
                    <div class="lx-feat-pill"><i class="fas fa-chart-bar"></i> Reports</div>
                </div>
            </div>

            <div class="lx-vbottom">
                <div class="lx-stats">
                    <div class="lx-stat">
                        <!-- <div class="lx-stat-num">3,654</div> -->
                        <div class="lx-stat-lbl">Students</div>
                    </div>
                    <div class="lx-stat">
                        <!-- <div class="lx-stat-num">284</div> -->
                        <div class="lx-stat-lbl">Teachers</div>
                    </div>
                    <div class="lx-stat">
                        <!-- <div class="lx-stat-num">162</div> -->
                        <div class="lx-stat-lbl">Classes</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══ Glass form right ══ -->
        <div class="lx-form-side">

            <div class="lx-form-head">
                <div class="lx-form-kicker">Secure Access</div>
                <h2 class="lx-form-h2">Welcome back 👋</h2>
                <p class="lx-form-sub">Sign in to your admin account to continue.</p>
                <div class="lx-mode-pill">
                    <div class="lx-mode-dot"></div>
                    <span id="lxModePillText">Day mode — auto</span>
                </div>
            </div>

            <?php if ($this->session->flashdata('error')): ?>
            <div class="lx-alert error">
                <i class="fas fa-triangle-exclamation"></i>
                <span><?= htmlspecialchars($this->session->flashdata('error')) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($this->session->flashdata('success')): ?>
            <div class="lx-alert success">
                <i class="fas fa-circle-check"></i>
                <span><?= htmlspecialchars($this->session->flashdata('success')) ?></span>
            </div>
            <?php endif; ?>

            <form method="post"
                  action="<?= base_url('admin_login/check_credentials') ?>"
                  class="lx-form"
                  id="lxLoginForm">

                <input type="hidden"
                       name="<?= $this->security->get_csrf_token_name() ?>"
                       value="<?= $this->security->get_csrf_hash() ?>">

                <!-- Admin ID -->
                <div class="lx-fgroup">
                    <div class="lx-flabel"><i class="fas fa-id-badge"></i> Admin ID</div>
                    <div class="lx-finput-wrap">
                        <input type="text" name="admin_id" id="lxAdminId"
                               placeholder="SSA0001 / ADM0001"
                               required autocomplete="username">
                        <i class="lx-ficon fas fa-user"></i>
                    </div>
                </div>

                <div class="lx-sep">credentials</div>

                <div class="lx-fgroup">
                    <div class="lx-flabel"><i class="fas fa-lock"></i> Password</div>
                    <div class="lx-finput-wrap">
                        <input type="password" name="password" id="lxPassword"
                               placeholder="Enter your password"
                               required autocomplete="current-password">
                        <i class="lx-ficon lx-pw-toggle fas fa-eye" id="lxPwToggle"></i>
                    </div>
                </div>

                <button type="submit" class="lx-btn" id="lxSubmitBtn">
                    <span class="lx-btn-inner">
                        <i class="fas fa-arrow-right-to-bracket"></i>
                        Sign In to Dashboard
                    </span>
                    <span class="lx-btn-spinner"></span>
                </button>

            </form>

            <div class="lx-form-foot">
                <div class="lx-secure">
                    <i class="fas fa-shield-halved"></i>
                    256-bit encrypted
                </div>
                <a href="<?= base_url('admin_login/forgot_password') ?>" class="lx-forgot">
                    Admin Forgot Password? <i class="fas fa-arrow-right" style="font-size:10px;"></i>
                </a>
                <a href="<?= base_url('admin_login/student_forgot_password') ?>" class="lx-forgot" style="margin-top:6px;">
                    Student Forgot Password? <i class="fas fa-arrow-right" style="font-size:10px;"></i>
                </a>
            </div>

        </div>

    </div><!-- /lx-wrap -->

</div><!-- /lx-stage -->

<script>
(function () {
    'use strict';

    var html       = document.documentElement;
    var themeBtn   = document.getElementById('lxThemeBtn');
    var themeLabel = document.getElementById('lxThemeLabel');
    var autoTag    = document.getElementById('lxAutoTag');
    var pillText   = document.getElementById('lxModePillText');
    var manualMode = false;

    function timeTheme() {
        var h = new Date().getHours();
        return (h >= 6 && h < 18) ? 'light' : 'dark';
    }

    function applyTheme(theme, isManual) {
        html.setAttribute('data-theme', theme);
        var dark           = theme === 'dark';
        themeLabel.textContent = dark ? 'NIGHT' : 'DAY';
        autoTag.textContent    = isManual ? 'MANUAL' : 'AUTO';
        autoTag.style.opacity  = isManual ? '0.55' : '1';
        var time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        pillText.textContent   = dark ? 'Night mode · ' + time : 'Day mode · ' + time;
        if (isManual) {
            localStorage.setItem('graderadmin_theme', theme);
            localStorage.setItem('graderadmin_manual', '1');
        }
    }

    /* Init */
    var savedTheme  = localStorage.getItem('graderadmin_theme');
    var savedManual = localStorage.getItem('graderadmin_manual') === '1';
    if (savedManual && savedTheme) {
        manualMode = true;
        applyTheme(savedTheme, true);
    } else {
        applyTheme(timeTheme(), false);
    }

    requestAnimationFrame(function () {
        setTimeout(function () { document.body.classList.add('lx-t-ready'); }, 50);
    });

    themeBtn.addEventListener('click', function () {
        var curr = html.getAttribute('data-theme');
        manualMode = true;
        applyTheme(curr === 'dark' ? 'light' : 'dark', true);
    });

    themeBtn.addEventListener('dblclick', function () {
        manualMode = false;
        localStorage.removeItem('graderadmin_theme');
        localStorage.removeItem('graderadmin_manual');
        autoTag.textContent   = 'AUTO';
        autoTag.style.opacity = '1';
        applyTheme(timeTheme(), false);
    });

    setInterval(function () {
        if (!manualMode) applyTheme(timeTheme(), false);
        var dark = html.getAttribute('data-theme') === 'dark';
        var time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        pillText.textContent = dark ? 'Night mode · ' + time : 'Day mode · ' + time;
    }, 60000);

    /* Password toggle */
    var pwToggle = document.getElementById('lxPwToggle');
    var pwInput  = document.getElementById('lxPassword');
    pwToggle.addEventListener('click', function () {
        var show = pwInput.type === 'password';
        pwInput.type = show ? 'text' : 'password';
        pwToggle.classList.toggle('fa-eye',       !show);
        pwToggle.classList.toggle('fa-eye-slash',  show);
    });

    /* Submit loading */
    document.getElementById('lxLoginForm').addEventListener('submit', function () {
        var btn = document.getElementById('lxSubmitBtn');
        btn.classList.add('loading');
        btn.disabled = true;
        setTimeout(function () {
            btn.classList.remove('loading');
            btn.disabled = false;
        }, 6000);
    });

}());
</script>