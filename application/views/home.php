<!-- Content Wrapper -->
<div class="content-wrapper db-root" id="dbRoot">

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap');

        /* ══════════════════════════════════════════════
           DESIGN TOKENS
        ══════════════════════════════════════════════ */
        .db-root {
            --brand:       #0f766e;
            --brand2:      #0d6b63;
            --brand3:      #14b8a6;
            --brand-light: #99f6e4;
            --brand-dim:   rgba(15, 118, 110, 0.08);
            --brand-glow:  rgba(15, 118, 110, 0.22);
            --brand-ring:  rgba(15, 118, 110, 0.16);
            --blue:    #3b82f6;
            --green:   #22c55e;
            --rose:    #f43f5e;
            --amber:   #f59e0b;
            --purple:  #8b5cf6;
            --cyan:    #06b6d4;
            --r:       14px;
            --r-sm:    10px;
            --r-xs:    6px;
            --ease:    cubic-bezier(.4, 0, .2, 1);
            --font:    'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --mono:    'JetBrains Mono', 'Fira Code', monospace;
        }

        /* ── DARK THEME ── */
        .db-root,
        [data-theme="night"] .db-root {
            --bg:       #0b1121;
            --bg2:      #111827;
            --bg3:      #1f2937;
            --bg4:      #374151;
            --card:     #111827;
            --card-alt: #1a2332;
            --border:   rgba(255, 255, 255, 0.06);
            --border2:  rgba(255, 255, 255, 0.10);
            --text:     #f9fafb;
            --text2:    #d1d5db;
            --muted:    #9ca3af;
            --muted2:   #6b7280;
            --heading:  #ffffff;
            --shadow:   0 1px 3px rgba(0,0,0,.3), 0 1px 2px rgba(0,0,0,.2);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,.3), 0 2px 4px -2px rgba(0,0,0,.2);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,.3), 0 4px 6px -4px rgba(0,0,0,.2);
            --chart-grid:  rgba(255, 255, 255, 0.04);
            --chart-tick:  #9ca3af;
        }

        /* ── LIGHT THEME ── */
        [data-theme="day"] .db-root {
            --bg:       #f1f5f9;
            --bg2:      #ffffff;
            --bg3:      #f8fafc;
            --bg4:      #e2e8f0;
            --card:     #ffffff;
            --card-alt: #f8fafc;
            --border:   rgba(0, 0, 0, 0.06);
            --border2:  rgba(0, 0, 0, 0.10);
            --text:     #1e293b;
            --text2:    #475569;
            --muted:    #94a3b8;
            --muted2:   #cbd5e1;
            --heading:  #0f172a;
            --shadow:   0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,.07), 0 2px 4px -2px rgba(0,0,0,.05);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,.08), 0 4px 6px -4px rgba(0,0,0,.05);
            --chart-grid:  rgba(0, 0, 0, 0.04);
            --chart-tick:  #94a3b8;
        }

        /* ── TRANSITIONS ── */
        .db-root.t-ready,
        .db-root.t-ready * {
            transition:
                background-color .25s var(--ease),
                border-color .25s var(--ease),
                color .25s var(--ease),
                box-shadow .25s var(--ease);
        }
        .db-root.t-ready canvas { transition: none; }

        /* ── RESET ── */
        .db-root *, .db-root *::before, .db-root *::after {
            box-sizing: border-box; margin: 0; padding: 0;
        }
        .db-root {
            font-family: var(--font);
            background: var(--bg);
            min-height: 100vh;
            color: var(--text);
        }

        /* ══════════════════════════════════════════════
           HERO / TOP BAR
        ══════════════════════════════════════════════ */
        .db-hero {
            position: relative;
            padding: 20px 28px;
            background: var(--card);
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center;
            justify-content: space-between;
            gap: 16px; flex-wrap: wrap;
        }
        .db-hero-left {
            display: flex; align-items: center; gap: 14px;
        }
        .db-logo-wrap {
            width: 44px; height: 44px; border-radius: 12px;
            overflow: hidden; flex-shrink: 0;
            border: 2px solid var(--border2);
            display: flex; align-items: center; justify-content: center;
            background: var(--bg3);
        }
        .db-logo-wrap img {
            width: 100%; height: 100%; object-fit: cover;
        }
        .db-logo-wrap i {
            font-size: 20px; color: var(--brand);
        }
        .db-hero-info h1 {
            font-size: 17px; font-weight: 700;
            color: var(--heading); line-height: 1.3;
            letter-spacing: -.3px;
        }
        .db-hero-info h1 span { color: var(--brand); }
        .db-hero-meta {
            display: flex; align-items: center; gap: 6px;
            margin-top: 3px; font-size: 12px; color: var(--muted);
        }
        .db-hero-meta .sep { opacity: .4; }
        .db-role-badge {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 10px; font-weight: 600; letter-spacing: .3px;
            padding: 2px 8px; border-radius: 4px;
            background: var(--brand-dim); color: var(--brand);
            border: 1px solid var(--brand-ring);
            text-transform: uppercase;
        }
        .db-hero-right {
            display: flex; align-items: center; gap: 10px;
        }
        .db-date-chip {
            display: flex; align-items: center; gap: 8px;
            padding: 8px 14px; border-radius: 8px;
            background: var(--bg3); border: 1px solid var(--border);
            font-size: 12px; font-weight: 500; color: var(--text2);
        }
        .db-date-chip i { color: var(--brand); font-size: 13px; }
        .db-live-dot {
            width: 6px; height: 6px; border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 6px var(--green);
            animation: dbPulse 2s ease infinite;
        }
        @keyframes dbPulse { 0%,100% { opacity:1; } 50% { opacity:.3; } }

        /* ══════════════════════════════════════════════
           MAIN LAYOUT
        ══════════════════════════════════════════════ */
        .db-body {
            padding: 20px 28px 48px;
            display: flex; flex-direction: column;
            gap: 20px;
        }

        /* ══════════════════════════════════════════════
           STAT CARDS — Horizontal Row
        ══════════════════════════════════════════════ */
        .db-stats {
            display: grid;
            /* Cap tile width so 6 stat cards don't stretch into sparse
               300px-wide blocks on a 1500px+ viewport. With max cap,
               cards wrap to a tidy 4-or-3-per-row grid. */
            grid-template-columns: repeat(auto-fit, minmax(200px, 240px));
            gap: 14px;
            justify-content: start;
        }
        @media (min-width: 1400px) {
            .db-stats { grid-template-columns: repeat(6, minmax(0, 1fr)); }
        }
        @media (min-width: 900px) and (max-width: 1399px) {
            .db-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (max-width: 899px) {
            .db-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        /* ── Fee Breakdown tiles (Today / Month / Year) ── */
        .db-fee-breakdown {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-top: 16px;
        }
        @media (max-width: 700px) {
            .db-fee-breakdown { grid-template-columns: 1fr; }
        }
        .fb-tile {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--r);
            padding: 18px 20px;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
        }
        .fb-tile::before {
            content: ''; position: absolute;
            inset: 0 auto 0 0; width: 3px;
            background: var(--brand);
        }
        .fb-tile:nth-child(2)::before { background: var(--amber); }
        .fb-tile:nth-child(3)::before { background: var(--blue); }
        .fb-label {
            font-size: 11px; font-weight: 600;
            color: var(--muted);
            text-transform: uppercase; letter-spacing: .4px;
            margin-bottom: 6px;
        }
        .fb-label i { margin-right: 6px; color: var(--brand); }
        .fb-tile:nth-child(2) .fb-label i { color: var(--amber); }
        .fb-tile:nth-child(3) .fb-label i { color: var(--blue); }
        .fb-value {
            font-size: 22px; font-weight: 800;
            color: var(--heading);
            letter-spacing: -.5px;
            line-height: 1.1;
        }
        .fb-sub {
            font-size: 11px; color: var(--muted2);
            margin-top: 4px;
        }
        /* ── Activity feed ── */
        .activity-item {
            display: flex; align-items: center;
            gap: 12px;
            padding: 10px 4px;
            border-bottom: 1px solid var(--border);
            transition: background .15s var(--ease);
        }
        .activity-item:last-child { border-bottom: none; }
        .activity-item:hover { background: var(--bg3); }
        .activity-icon {
            width: 34px; height: 34px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            color: white;
            font-size: 13px;
        }
        .activity-body { flex: 1; min-width: 0; }
        .activity-title {
            font-size: 12.5px; font-weight: 600;
            color: var(--heading);
            margin: 0 0 2px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .activity-detail {
            font-size: 11px; color: var(--muted);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .activity-time {
            font-size: 10px; color: var(--muted2);
            flex-shrink: 0;
            font-family: monospace;
        }
        /* ── Birthday row ── */
        .bday-item {
            display: flex; align-items: center;
            gap: 12px;
            padding: 10px 4px;
            border-bottom: 1px solid var(--border);
        }
        .bday-item:last-child { border-bottom: none; }
        .bday-avatar {
            width: 36px; height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--rose), var(--amber));
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 14px; font-weight: 700;
            flex-shrink: 0;
        }
        .bday-name { font-size: 13px; font-weight: 600; color: var(--heading); }
        .bday-class { font-size: 11px; color: var(--muted); }
        /* ── Top Defaulters rows ── */
        .def-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 4px;
            border-bottom: 1px solid var(--border);
            transition: background .15s var(--ease);
        }
        .def-item:last-child { border-bottom: none; }
        .def-item:hover { background: var(--bg3); }
        .def-rank {
            width: 28px; height: 28px;
            border-radius: 50%;
            background: linear-gradient(135deg, #dc2626, #f97316);
            color: white;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 700;
            flex-shrink: 0;
        }
        .def-item:nth-child(n+4) .def-rank { background: linear-gradient(135deg, #d97706, #f59e0b); }
        .def-body { flex: 1; min-width: 0; }
        .def-name {
            font-size: 12.5px; font-weight: 600; color: var(--heading);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .def-class { font-size: 11px; color: var(--muted); }
        .def-dues {
            font-size: 13px; font-weight: 700; color: #dc2626;
            font-family: var(--mono); white-space: nowrap;
        }
        .def-flag {
            display: inline-block;
            padding: 1px 6px; border-radius: 8px;
            font-size: 9px; font-weight: 700;
            margin-left: 6px; vertical-align: middle;
        }
        .def-flag-red   { background: rgba(220,38,38,.12); color: #dc2626; }
        .def-flag-amber { background: rgba(217,119,6,.12); color: #d97706; }
        /* ── Absent today rows ── */
        .absent-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 4px;
            border-bottom: 1px solid var(--border);
        }
        .absent-item:last-child { border-bottom: none; }
        .absent-avatar {
            width: 34px; height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ef4444, #fb7185);
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 13px; font-weight: 700;
            flex-shrink: 0;
        }
        .absent-name { font-size: 13px; font-weight: 600; color: var(--heading); }
        .absent-class { font-size: 11px; color: var(--muted); }
        /* ── Shared: card-header link ── */
        .db-card-link {
            font-size: 11px; color: var(--brand); text-decoration: none;
            font-weight: 600;
        }
        .db-card-link:hover { text-decoration: underline; }
        .bday-wish-btn {
            background: linear-gradient(135deg, var(--rose), var(--amber));
            color: white; border: none;
            padding: 6px 12px;
            border-radius: 18px;
            font-size: 11px; font-weight: 600;
            cursor: pointer;
            display: inline-flex; align-items: center; gap: 4px;
            transition: transform .15s var(--ease), opacity .15s var(--ease), box-shadow .15s var(--ease);
            box-shadow: 0 2px 6px rgba(233, 62, 110, 0.25);
        }
        .bday-wish-btn:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(233, 62, 110, 0.35);
        }
        .bday-wish-btn:disabled {
            cursor: default; opacity: .75;
        }
        .bday-wish-btn.bday-sent {
            background: var(--green);
            box-shadow: 0 2px 6px rgba(22, 163, 74, 0.25);
        }
        /* ── Row arrangement fixes ── */
        /* Stat cards should stay evenly sized and not squash on narrow screens */
        .db-stats { margin-bottom: 16px; }
        /* Activity row — Activity wider than Birthdays */
        .db-grid-wide > .db-card:only-child { grid-column: 1 / -1; }
        @media (min-width: 1100px) {
            .db-grid-row4 {
                display: grid;
                grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
                gap: 14px;
            }
        }
        @media (max-width: 1099px) {
            .db-grid-row4 {
                display: grid;
                grid-template-columns: 1fr;
                gap: 14px;
            }
        }
        /* Keep all db-cards within a row the same height */
        .db-grid-3, .db-grid-wide, .db-grid-row4 {
            align-items: stretch;
        }
        .db-grid-3 > .db-card,
        .db-grid-wide > .db-card,
        .db-grid-row4 > .db-card {
            display: flex; flex-direction: column;
        }
        .db-grid-3 > .db-card .db-card-body,
        .db-grid-wide > .db-card .db-card-body,
        .db-grid-row4 > .db-card .db-card-body {
            flex: 1; min-height: 0;
        }
        .stat-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--r);
            padding: 18px 18px 14px;
            position: relative; overflow: hidden;
            box-shadow: var(--shadow);
            cursor: default;
            transition: transform .2s var(--ease), box-shadow .2s var(--ease);
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .stat-card-head {
            display: flex; align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        .stat-icon {
            width: 38px; height: 38px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; flex-shrink: 0;
        }
        .stat-icon.c-brand  { background: rgba(15,118,110,.15); color: var(--brand); border: 1px solid rgba(15,118,110,.18); }
        .stat-icon.c-green  { background: rgba(34,197,94,.15);  color: var(--green); border: 1px solid rgba(34,197,94,.18); }
        .stat-icon.c-blue   { background: rgba(59,130,246,.15); color: var(--blue);  border: 1px solid rgba(59,130,246,.18); }
        .stat-icon.c-rose   { background: rgba(244,63,94,.15);  color: var(--rose);  border: 1px solid rgba(244,63,94,.18); }
        .stat-icon.c-amber  { background: rgba(245,158,11,.15); color: var(--amber); border: 1px solid rgba(245,158,11,.18); }
        .stat-icon.c-red    { background: rgba(239,68,68,.15);  color: #ef4444;      border: 1px solid rgba(239,68,68,.18); }

        .stat-trend {
            display: inline-flex; align-items: center; gap: 3px;
            font-size: 11px; font-weight: 600; padding: 2px 6px;
            border-radius: 4px;
        }
        .stat-trend.up   { color: var(--green); background: rgba(34,197,94,.1); }
        .stat-trend.down { color: var(--rose);  background: rgba(244,63,94,.1); }
        .stat-trend.flat { color: var(--muted); background: var(--bg3); }

        .stat-value {
            font-size: 28px; font-weight: 800;
            color: var(--heading); letter-spacing: -1px;
            line-height: 1.1;
        }
        .stat-label {
            font-size: 11.5px; font-weight: 500;
            color: var(--muted); margin-top: 4px;
            letter-spacing: .2px;
        }
        .stat-footer {
            margin-top: 12px; padding-top: 10px;
            border-top: 1px solid var(--border);
        }
        .stat-footer a {
            font-size: 11px; font-weight: 500;
            color: var(--brand); text-decoration: none;
            display: flex; align-items: center; gap: 4px;
            transition: gap .2s;
        }
        .stat-footer a:hover { gap: 7px; }

        /* accent bar at bottom */
        .stat-card::after {
            content: ''; position: absolute;
            bottom: 0; left: 0; right: 0; height: 2px;
        }
        .stat-card.c-brand::after  { background: var(--brand); }
        .stat-card.c-green::after  { background: var(--green); }
        .stat-card.c-blue::after   { background: var(--blue); }
        .stat-card.c-rose::after   { background: var(--rose); }
        .stat-card.c-amber::after  { background: var(--amber); }
        .stat-card.c-red::after    { background: #ef4444; }

        /* ══════════════════════════════════════════════
           PANEL GRID (Charts + Sidebar)
        ══════════════════════════════════════════════ */
        .db-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            align-items: start;
        }
        .db-grid-wide {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            align-items: start;
        }
        .db-grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            align-items: start;
        }
        @media(max-width:1100px) {
            .db-grid-wide { grid-template-columns: 1fr; }
            .db-grid-3 { grid-template-columns: 1fr 1fr; }
        }
        @media(max-width:900px) {
            .db-grid, .db-grid-3 { grid-template-columns: 1fr; }
        }
        @media(max-width:700px) {
            .db-body { padding: 14px 14px 32px; gap: 14px; }
            .db-hero { padding: 16px 14px; }
            .db-stats { grid-template-columns: 1fr 1fr; }
        }
        @media(max-width:480px) {
            .db-stats { grid-template-columns: 1fr; }
        }

        /* ══════════════════════════════════════════════
           CARD BASE
        ══════════════════════════════════════════════ */
        .db-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--r);
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        .db-card-header {
            display: flex; align-items: center;
            justify-content: space-between;
            padding: 16px 20px 0;
        }
        .db-card-title {
            font-size: 14px; font-weight: 700;
            color: var(--heading);
        }
        .db-card-sub {
            font-size: 11px; color: var(--muted);
            margin-top: 1px;
        }
        .db-card-badge {
            font-size: 10px; font-weight: 600;
            font-family: var(--mono);
            padding: 3px 8px; border-radius: 5px;
            background: var(--brand-dim); color: var(--brand);
            border: 1px solid var(--brand-ring);
        }
        .db-card-badge.rose  { background: rgba(244,63,94,.08); color: var(--rose); border-color: rgba(244,63,94,.15); }
        .db-card-badge.green { background: rgba(34,197,94,.08); color: var(--green); border-color: rgba(34,197,94,.15); }
        .db-card-body { padding: 16px 20px 20px; }

        /* ══════════════════════════════════════════════
           FEE COLLECTION — Summary Chips + Chart
        ══════════════════════════════════════════════ */
        .fee-summary {
            display: flex; gap: 10px;
            margin-bottom: 16px;
        }
        .fee-chip {
            flex: 1; padding: 10px 14px;
            background: var(--bg3); border-radius: var(--r-sm);
            border: 1px solid var(--border);
        }
        .fee-chip-val {
            font-size: 18px; font-weight: 700;
            color: var(--heading); letter-spacing: -.5px;
        }
        .fee-chip-lbl {
            font-size: 10px; font-weight: 500;
            color: var(--muted); text-transform: uppercase;
            letter-spacing: .4px; margin-top: 2px;
        }
        @media(max-width:480px) { .fee-summary { flex-wrap: wrap; } }

        /* ══════════════════════════════════════════════
           EVENTS
        ══════════════════════════════════════════════ */
        .evt-list { display: flex; flex-direction: column; gap: 8px; max-height: 320px; overflow-y: auto; }
        .evt-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 12px;
            background: var(--bg3);
            border: 1px solid var(--border);
            border-radius: var(--r-sm);
            transition: border-color .15s, background .15s;
        }
        .evt-item:hover { border-color: var(--brand-ring); }
        .evt-icon {
            width: 34px; height: 34px; border-radius: 8px;
            flex-shrink: 0; display: flex;
            align-items: center; justify-content: center;
            font-size: 13px;
        }
        .evt-icon.upcoming { background: rgba(15,118,110,.1); color: var(--brand); }
        .evt-icon.ongoing  { background: rgba(34,197,94,.1);  color: var(--green); }
        .evt-info { flex: 1; min-width: 0; }
        .evt-name {
            font-size: 12.5px; font-weight: 600;
            color: var(--text); white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
        }
        .evt-date {
            font-size: 10.5px; color: var(--muted);
            font-family: var(--mono); margin-top: 1px;
        }
        .evt-badge {
            font-size: 9.5px; font-weight: 600;
            padding: 3px 8px; border-radius: 4px;
            white-space: nowrap; flex-shrink: 0;
        }
        .evt-badge.upcoming { background: var(--brand-dim); color: var(--brand); }
        .evt-badge.ongoing  { background: rgba(34,197,94,.1); color: var(--green); }
        .evt-empty {
            text-align: center; padding: 24px;
            color: var(--muted); font-size: 12px;
        }
        .evt-footer {
            padding: 12px 20px; border-top: 1px solid var(--border);
            display: flex; gap: 14px;
        }
        .evt-footer a {
            font-size: 11px; font-weight: 500; color: var(--brand);
            text-decoration: none; display: flex; align-items: center; gap: 4px;
        }
        .evt-footer a:hover { text-decoration: underline; }

        /* ══════════════════════════════════════════════
           QUICK ACTIONS
        ══════════════════════════════════════════════ */
        .quick-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 10px;
        }
        .quick-btn {
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            gap: 8px; padding: 16px 8px;
            border-radius: var(--r-sm); text-decoration: none;
            font-size: 10px; font-weight: 600;
            letter-spacing: .3px; text-transform: uppercase;
            color: var(--text2); border: 1px solid var(--border);
            background: var(--bg3);
            transition: all .2s var(--ease);
        }
        .quick-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            text-decoration: none;
        }
        .quick-btn i { font-size: 20px; }

        /* Quick action color variants */
        .quick-btn.qb-brand  { border-color: var(--brand-ring); }
        .quick-btn.qb-brand  i { color: var(--brand); }
        .quick-btn.qb-brand:hover  { border-color: var(--brand); color: var(--brand); box-shadow: 0 4px 16px rgba(15,118,110,.12); }

        .quick-btn.qb-amber  { border-color: rgba(245,158,11,.15); }
        .quick-btn.qb-amber  i { color: var(--amber); }
        .quick-btn.qb-amber:hover  { border-color: var(--amber); color: var(--amber); box-shadow: 0 4px 16px rgba(245,158,11,.12); }

        .quick-btn.qb-rose   { border-color: rgba(244,63,94,.15); }
        .quick-btn.qb-rose   i { color: var(--rose); }
        .quick-btn.qb-rose:hover   { border-color: var(--rose); color: var(--rose); box-shadow: 0 4px 16px rgba(244,63,94,.12); }

        .quick-btn.qb-blue   { border-color: rgba(59,130,246,.15); }
        .quick-btn.qb-blue   i { color: var(--blue); }
        .quick-btn.qb-blue:hover   { border-color: var(--blue); color: var(--blue); box-shadow: 0 4px 16px rgba(59,130,246,.12); }

        .quick-btn.qb-green  { border-color: rgba(34,197,94,.15); }
        .quick-btn.qb-green  i { color: var(--green); }
        .quick-btn.qb-green:hover  { border-color: var(--green); color: var(--green); box-shadow: 0 4px 16px rgba(34,197,94,.12); }

        .quick-btn.qb-purple { border-color: rgba(139,92,246,.15); }
        .quick-btn.qb-purple i { color: var(--purple); }
        .quick-btn.qb-purple:hover { border-color: var(--purple); color: var(--purple); box-shadow: 0 4px 16px rgba(139,92,246,.12); }

        .quick-btn.qb-cyan   { border-color: rgba(6,182,212,.15); }
        .quick-btn.qb-cyan   i { color: var(--cyan); }
        .quick-btn.qb-cyan:hover   { border-color: var(--cyan); color: var(--cyan); box-shadow: 0 4px 16px rgba(6,182,212,.12); }

        /* ══════════════════════════════════════════════
           CALENDAR
        ══════════════════════════════════════════════ */
        .mini-cal-header {
            display: flex; align-items: center;
            justify-content: space-between; margin-bottom: 12px;
        }
        .mini-cal-month {
            font-size: 13px; font-weight: 700; color: var(--heading);
        }
        .mini-cal-nav { display: flex; gap: 4px; }
        .mini-cal-nav button {
            width: 26px; height: 26px; border-radius: 6px;
            border: 1px solid var(--border); background: transparent;
            color: var(--muted); cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: all .15s; font-size: 10px;
        }
        .mini-cal-nav button:hover {
            background: var(--bg3); color: var(--brand);
            border-color: var(--brand-ring);
        }
        .cal-grid {
            display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px;
        }
        .cal-day-name {
            text-align: center; font-size: 9px;
            color: var(--muted); text-transform: uppercase;
            letter-spacing: .4px; padding: 3px 0 6px;
            font-family: var(--mono); font-weight: 500;
        }
        .cal-day {
            /* Capped height so calendar cells stay compact; a tall calendar
               was dominating Row 3 and dwarfing Tasks/Attendance. */
            min-height: 32px; max-height: 40px;
            display: flex;
            align-items: center; justify-content: center;
            font-size: 11px; font-weight: 500;
            border-radius: 7px; cursor: pointer;
            transition: all .12s; color: var(--text2);
        }
        .cal-day:hover { background: var(--brand-dim); color: var(--brand); }
        .cal-day.other { color: var(--muted2); }
        .cal-day.today {
            background: var(--brand); color: #fff;
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(15,118,110,.35);
        }
        .cal-day.has-event { position: relative; font-weight: 600; }
        .cal-day.has-event::after {
            content: ''; position: absolute;
            bottom: 2px; left: 50%; transform: translateX(-50%);
            width: 4px; height: 4px; border-radius: 50%;
            background: var(--rose);
        }

        /* ══════════════════════════════════════════════
           TASKS
        ══════════════════════════════════════════════ */
        .db-task-list { display: flex; flex-direction: column; gap: 6px; }
        .db-task-item {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 12px; border-radius: 8px;
            background: var(--bg3); border: 1px solid var(--border);
            font-size: 12px; color: var(--text2);
            cursor: pointer; text-decoration: none;
            transition: border-color .15s, background .15s;
        }
        .db-task-item:hover {
            border-color: var(--brand-ring);
            background: var(--brand-dim); color: var(--text);
        }
        .db-task-icon {
            width: 28px; height: 28px; border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; flex-shrink: 0; color: #fff;
        }
        .db-task-body { flex: 1; min-width: 0; }
        .db-task-title { font-size: 12px; font-weight: 600; color: var(--text); }
        .db-task-detail { font-size: 10.5px; color: var(--muted); margin-top: 1px; }
        .db-task-badge {
            font-size: 9px; font-weight: 700;
            padding: 2px 6px; border-radius: 4px; flex-shrink: 0;
            letter-spacing: .3px;
        }
        .db-task-badge.high   { background: rgba(239,68,68,.12); color: #ef4444; }
        .db-task-badge.medium { background: rgba(245,158,11,.12); color: #f59e0b; }
        .db-task-badge.low    { background: rgba(15,118,110,.1);  color: #0f766e; }

        /* ══════════════════════════════════════════════
           ALERTS
        ══════════════════════════════════════════════ */
        .db-alerts { display: flex; flex-direction: column; gap: 8px; }
        .db-alert-banner {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 16px; border-radius: var(--r-sm);
            font-size: 12px;
        }
        .db-alert-banner.warning {
            background: rgba(245,158,11,.06); border: 1px solid rgba(245,158,11,.15);
            color: var(--amber);
        }
        .db-alert-banner.error {
            background: rgba(239,68,68,.06); border: 1px solid rgba(239,68,68,.15);
            color: #ef4444;
        }
        [data-theme="day"] .db-alert-banner.warning { color: #92400e; }
        [data-theme="day"] .db-alert-banner.error   { color: #991b1b; }
        .db-alert-banner i.alert-icon { font-size: 15px; flex-shrink: 0; }
        .db-alert-body { flex: 1; min-width: 0; }
        .db-alert-title { font-size: 12.5px; font-weight: 600; }
        .db-alert-detail { font-size: 11px; opacity: .7; margin-top: 1px; }
        .db-alert-action {
            font-size: 11px; font-weight: 600;
            padding: 5px 12px; border-radius: 6px;
            border: 1px solid currentColor; background: transparent;
            color: inherit; cursor: pointer; flex-shrink: 0;
            text-decoration: none; opacity: .8; transition: opacity .15s;
        }
        .db-alert-action:hover { opacity: 1; }
        .db-alert-dismiss {
            background: none; border: none; color: inherit;
            cursor: pointer; opacity: .4; font-size: 14px;
            padding: 4px; flex-shrink: 0;
        }
        .db-alert-dismiss:hover { opacity: .8; }

        /* ══════════════════════════════════════════════
           ATTENDANCE RING (SVG)
        ══════════════════════════════════════════════ */
        .att-ring-wrap {
            position: relative; width: 100%; display: flex;
            flex-direction: column; align-items: center;
            padding: 10px 0 6px;
        }
        .att-ring-center {
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -55%);
            text-align: center;
        }
        .att-ring-val {
            font-size: 22px; font-weight: 800;
            color: var(--heading); letter-spacing: -1px;
        }
        .att-ring-lbl {
            font-size: 9px; font-weight: 500;
            color: var(--muted); text-transform: uppercase;
            letter-spacing: .3px;
        }
        .att-legend {
            display: flex; gap: 12px; margin-top: 10px;
            justify-content: center;
        }
        .att-legend-item {
            display: flex; align-items: center; gap: 5px;
            font-size: 10.5px; color: var(--muted);
        }
        .att-legend-dot {
            width: 7px; height: 7px; border-radius: 50%;
        }

        /* ══════════════════════════════════════════════
           SUBSCRIPTION PANEL
        ══════════════════════════════════════════════ */
        .sub-summary { display: flex; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
        .sub-chip {
            flex: 1; min-width: 90px; padding: 12px;
            background: var(--bg3); border-radius: var(--r-sm);
            border: 1px solid var(--border); text-align: center;
        }
        .sub-chip-lbl {
            font-size: 9px; font-weight: 500; color: var(--muted);
            text-transform: uppercase; letter-spacing: .5px;
            font-family: var(--mono);
        }
        .sub-chip-val {
            font-size: 16px; font-weight: 700;
            color: var(--heading); margin-top: 3px;
        }
        .sub-chip-hint {
            font-size: 10px; color: var(--muted); margin-top: 1px;
        }
        .sub-pay-item {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 10px; background: var(--bg3);
            border-radius: var(--r-sm); margin-bottom: 6px;
        }
        .sub-pay-bar {
            height: 3px; background: var(--border);
            border-radius: 2px; flex: 1; margin-top: 3px;
        }
        .sub-pay-fill {
            height: 100%; border-radius: 2px;
        }
        .sub-alert-bar {
            display: none; align-items: center; gap: 8px;
            padding: 10px 14px; border-radius: var(--r-sm);
            margin-bottom: 14px; font-size: 12px;
        }
        .sub-alert-bar.visible { display: flex; }
        .sub-alert-bar.overdue {
            background: rgba(239,68,68,.06);
            border: 1px solid rgba(239,68,68,.15);
        }
        .sub-alert-bar.expiring {
            background: rgba(249,115,22,.06);
            border: 1px solid rgba(249,115,22,.15);
        }

        /* ══════════════════════════════════════════════
           LOADING SKELETON
        ══════════════════════════════════════════════ */
        .db-skel {
            background: linear-gradient(90deg, var(--bg3) 25%, var(--bg4) 50%, var(--bg3) 75%);
            background-size: 200% 100%;
            animation: dbSkelShimmer 1.5s infinite;
            border-radius: 6px;
        }
        @keyframes dbSkelShimmer { 0% { background-position:200% 0; } 100% { background-position:-200% 0; } }

        /* ══════════════════════════════════════════════
           ANIMATIONS
        ══════════════════════════════════════════════ */
        @keyframes dbFadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .stat-card { animation: dbFadeUp .4s ease both; }
        .stat-card:nth-child(1) { animation-delay: .03s; }
        .stat-card:nth-child(2) { animation-delay: .07s; }
        .stat-card:nth-child(3) { animation-delay: .11s; }
        .stat-card:nth-child(4) { animation-delay: .15s; }
        .stat-card:nth-child(5) { animation-delay: .19s; }
        .stat-card:nth-child(6) { animation-delay: .23s; }
        .db-card { animation: dbFadeUp .4s ease both; }

        .db-finance-only { /* toggled via JS */ }
    </style>

    <!-- ─── HERO ─── -->
    <div class="db-hero">
        <div class="db-hero-left">
            <div class="db-logo-wrap">
                <?php if (!empty($school_logo_url) && strpos($school_logo_url, 'default-school') === false): ?>
                    <img src="<?= htmlspecialchars($school_logo_url, ENT_QUOTES, 'UTF-8') ?>" alt="Logo">
                <?php else: ?>
                    <i class="fa fa-graduation-cap"></i>
                <?php endif; ?>
            </div>
            <div class="db-hero-info">
                <h1>Welcome back, <span><?= htmlspecialchars($admin_name, ENT_QUOTES, 'UTF-8') ?></span></h1>
                <div class="db-hero-meta">
                    <span><?= htmlspecialchars($school_name, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="sep">/</span>
                    <span>Session <?= htmlspecialchars($session_year, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="sep">/</span>
                    <span class="db-role-badge"><?= htmlspecialchars($admin_role, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
        </div>
        <div class="db-hero-right">
            <div class="db-date-chip">
                <i class="fa fa-calendar-o"></i>
                <span id="dbLiveDate"></span>
                <span class="db-live-dot"></span>
            </div>
        </div>
    </div>

    <!-- ─── BODY ─── -->
    <div class="db-body">

        <!-- SMART ALERTS -->
        <div class="db-alerts" id="dbAlerts" style="display:none;"></div>

        <!-- ═══ STAT CARDS ═══ -->
        <?php $can = function($m) { return has_permission($m); }; ?>
        <div class="db-stats">

            <?php if ($can('SIS') || $can('Attendance')): ?>
            <div class="stat-card c-brand">
                <div class="stat-card-head">
                    <div class="stat-icon c-brand"><i class="fa fa-graduation-cap"></i></div>
                    <span class="stat-trend flat" id="sectionCount"><i class="fa fa-minus"></i> --</span>
                </div>
                <div class="stat-value" id="valStudents">--</div>
                <div class="stat-label">Total Students</div>
                <?php if ($can('SIS')): ?>
                <div class="stat-footer">
                    <a href="<?= base_url('student/all_student') ?>">View All <i class="fa fa-arrow-right"></i></a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($can('Attendance') || $can('SIS')): ?>
            <div class="stat-card c-green">
                <div class="stat-card-head">
                    <div class="stat-icon c-green"><i class="fa fa-calendar-check-o"></i></div>
                    <span class="stat-trend flat" id="attAbsentCount"><i class="fa fa-minus"></i> Today</span>
                </div>
                <div class="stat-value" id="valAttendance">--</div>
                <div class="stat-label">Today's Attendance</div>
                <?php if ($can('Attendance')): ?>
                <div class="stat-footer">
                    <a href="<?= base_url('attendance/student') ?>">Mark / View <i class="fa fa-arrow-right"></i></a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($can('HR') || $can('SIS')): ?>
            <div class="stat-card c-blue">
                <div class="stat-card-head">
                    <div class="stat-icon c-blue"><i class="fa fa-users"></i></div>
                    <span class="stat-trend flat"><i class="fa fa-minus"></i> Session</span>
                </div>
                <div class="stat-value" id="valTeachers">--</div>
                <div class="stat-label">Total Staff</div>
                <?php if ($can('HR')): ?>
                <div class="stat-footer">
                    <a href="<?= base_url('staff/all_staff') ?>">View All <i class="fa fa-arrow-right"></i></a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($can('Academic') || $can('SIS')): ?>
            <div class="stat-card c-rose">
                <div class="stat-card-head">
                    <div class="stat-icon c-rose"><i class="fa fa-university"></i></div>
                    <span class="stat-trend flat" id="classCount"><i class="fa fa-minus"></i> --</span>
                </div>
                <div class="stat-value" id="valClasses">--</div>
                <div class="stat-label">Classes & Sections</div>
                <?php if ($can('Academic')): ?>
                <div class="stat-footer">
                    <a href="<?= base_url('classes/manage_classes') ?>">View All <i class="fa fa-arrow-right"></i></a>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($can('Fees')): ?>
            <div class="stat-card c-amber db-finance-only">
                <div class="stat-card-head">
                    <div class="stat-icon c-amber"><i class="fa fa-inr"></i></div>
                    <span class="stat-trend flat" id="receiptCountBadge"><i class="fa fa-minus"></i> Session</span>
                </div>
                <div class="stat-value" id="valFees">--</div>
                <div class="stat-label">Fees Collected</div>
                <div class="stat-footer">
                    <a href="<?= base_url('fees/fees_records') ?>">View Records <i class="fa fa-arrow-right"></i></a>
                </div>
            </div>

            <div class="stat-card c-red db-finance-only">
                <div class="stat-card-head">
                    <div class="stat-icon c-red"><i class="fa fa-exclamation-triangle"></i></div>
                    <span class="stat-trend flat">Unpaid</span>
                </div>
                <div class="stat-value" id="valDefaulters">--</div>
                <div class="stat-label">Fee Defaulters</div>
                <div class="stat-footer">
                    <a href="<?= base_url('fees/fees_records') ?>">View Details <i class="fa fa-arrow-right"></i></a>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ═══ FEE BREAKDOWN (Today / Month / Year) ═══ -->
        <?php if ($can('Fees')): ?>
        <div class="db-fee-breakdown db-finance-only">
            <div class="fb-tile">
                <div class="fb-label"><i class="fa fa-calendar-o"></i> Today</div>
                <div class="fb-value" id="fbToday">₹--</div>
                <div class="fb-sub" id="fbTodayDate"><?= date('d M Y') ?></div>
            </div>
            <div class="fb-tile">
                <div class="fb-label"><i class="fa fa-calendar"></i> This Month</div>
                <div class="fb-value" id="fbMonth">₹--</div>
                <div class="fb-sub"><?= date('F Y') ?></div>
            </div>
            <div class="fb-tile">
                <div class="fb-label"><i class="fa fa-line-chart"></i> This Year</div>
                <div class="fb-value" id="fbYear">₹--</div>
                <div class="fb-sub"><?= date('Y') ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ═══ ROW 1: Fee Chart + Events ═══ -->
        <div class="db-grid-wide">

            <!-- FEE COLLECTION -->
            <?php if ($can('Fees')): ?>
            <div class="db-card db-finance-only" style="animation-delay:.28s;">
                <div class="db-card-header">
                    <div>
                        <div class="db-card-title">Fee Collection</div>
                        <div class="db-card-sub">Monthly overview &middot; <?= htmlspecialchars($session_year, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <span class="db-card-badge">Live</span>
                </div>
                <div class="db-card-body">
                    <div class="fee-summary">
                        <div class="fee-chip">
                            <div class="fee-chip-val" style="color:var(--brand)" id="feeTotalCollected">&#8377;0</div>
                            <div class="fee-chip-lbl">Collected</div>
                        </div>
                        <div class="fee-chip">
                            <div class="fee-chip-val" style="color:var(--muted)" id="feeTotalReceipts">0</div>
                            <div class="fee-chip-lbl">Receipts</div>
                        </div>
                        <div class="fee-chip">
                            <div class="fee-chip-val" style="color:var(--green)" id="feeTotalMonths">0</div>
                            <div class="fee-chip-lbl">Active Months</div>
                        </div>
                    </div>
                    <div style="position:relative;height:200px;">
                        <canvas id="feeChart"></canvas>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- EVENTS -->
            <?php if ($can('Events') || $can('Communication')): ?>
            <div class="db-card" style="animation-delay:.32s;">
                <div class="db-card-header">
                    <div>
                        <div class="db-card-title">Events</div>
                        <div class="db-card-sub">Upcoming & ongoing</div>
                    </div>
                    <span class="db-card-badge" id="evtBadge">--</span>
                </div>
                <div class="db-card-body">
                    <div class="evt-list" id="evtList">
                        <div class="evt-empty"><i class="fa fa-spinner fa-spin"></i> Loading events...</div>
                    </div>
                </div>
                <div class="evt-footer">
                    <a href="<?= base_url('events') ?>"><i class="fa fa-calendar"></i> View Calendar</a>
                    <a href="<?= base_url('events/list') ?>"><i class="fa fa-list"></i> All Events</a>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!$can('Fees') && ($can('Events') || $can('Communication'))): ?>
            <!-- Placeholder so events isn't alone -->
            <?php endif; ?>
        </div>

        <!-- ═══ ROW 2: Top Defaulters + Today's Absent + Quick Actions ═══ -->
        <div class="db-grid-3">

            <!-- TOP 5 FEE DEFAULTERS -->
            <?php if ($can('Fees')): ?>
            <div class="db-card db-finance-only" style="animation-delay:.36s;">
                <div class="db-card-header">
                    <div>
                        <div class="db-card-title">Top Fee Defaulters</div>
                        <div class="db-card-sub">Highest outstanding dues</div>
                    </div>
                    <a href="<?= base_url('fees/defaulter_report') ?>" class="db-card-link">View All <i class="fa fa-arrow-right"></i></a>
                </div>
                <div class="db-card-body">
                    <div id="topDefaultersList">
                        <div style="text-align:center;padding:18px 0;color:var(--muted);font-size:12px;">
                            <i class="fa fa-spinner fa-spin"></i> Loading...
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- TODAY'S ABSENT STUDENTS -->
            <?php if ($can('Attendance') || $can('SIS')): ?>
            <div class="db-card" style="animation-delay:.40s;">
                <div class="db-card-header">
                    <div>
                        <div class="db-card-title">Absent Today</div>
                        <div class="db-card-sub" id="absentSubLabel"><?= date('d M Y') ?></div>
                    </div>
                    <span class="db-card-badge" id="absentCountBadge" style="display:none;">0</span>
                </div>
                <div class="db-card-body">
                    <div id="absentStudentsList">
                        <div style="text-align:center;padding:18px 0;color:var(--muted);font-size:12px;">
                            <i class="fa fa-spinner fa-spin"></i> Loading...
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- QUICK ACTIONS -->
            <?php
            $quickActions = [];
            if ($can('SIS'))           $quickActions[] = ['url' => 'sis/studentAdmission', 'icon' => 'fa-user-plus',        'label' => 'Add Student',   'class' => 'qb-brand'];
            if ($can('Fees'))          $quickActions[] = ['url' => 'fees/fees_counter',     'icon' => 'fa-money',            'label' => 'Collect Fees',  'class' => 'qb-amber'];
            if ($can('Events'))        $quickActions[] = ['url' => 'events/list',           'icon' => 'fa-calendar-plus-o',  'label' => 'Create Event',  'class' => 'qb-rose'];
            if ($can('Attendance'))    $quickActions[] = ['url' => 'attendance/student',     'icon' => 'fa-calendar-check-o', 'label' => 'Attendance',    'class' => 'qb-blue'];
            if ($can('Results'))       $quickActions[] = ['url' => 'result/marks_entry',     'icon' => 'fa-list-alt',         'label' => 'Marks Entry',   'class' => 'qb-green'];
            if ($can('Communication')) $quickActions[] = ['url' => 'communication/notices',  'icon' => 'fa-bullhorn',         'label' => 'Send Notice',   'class' => 'qb-purple'];
            if ($can('HR'))            $quickActions[] = ['url' => 'staff/new_staff',        'icon' => 'fa-id-card-o',        'label' => 'Add Staff',     'class' => 'qb-cyan'];
            if ($can('Accounting'))    $quickActions[] = ['url' => 'accounting/ledger',      'icon' => 'fa-calculator',       'label' => 'Journal Entry', 'class' => 'qb-amber'];
            if ($can('Operations'))    $quickActions[] = ['url' => 'operations',             'icon' => 'fa-cog',              'label' => 'Operations',    'class' => 'qb-rose'];
            ?>
            <?php if (!empty($quickActions)): ?>
            <div class="db-card" style="animation-delay:.44s;">
                <div class="db-card-header">
                    <div>
                        <div class="db-card-title">Quick Actions</div>
                        <div class="db-card-sub">Frequently used</div>
                    </div>
                </div>
                <div class="db-card-body">
                    <div class="quick-grid">
                        <?php foreach ($quickActions as $qa): ?>
                        <a href="<?= base_url($qa['url']) ?>" class="quick-btn <?= $qa['class'] ?>">
                            <i class="fa <?= $qa['icon'] ?>"></i><?= $qa['label'] ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ═══ ROW 3: Today's Tasks + Calendar + Attendance Ring ═══ -->
        <div class="db-grid-3">

            <!-- TODAY'S TASKS -->
            <div class="db-card" style="animation-delay:.48s;">
                <div class="db-card-header">
                    <div>
                        <div class="db-card-title">Today's Tasks</div>
                        <div class="db-card-sub" id="dbTaskCount">Loading...</div>
                    </div>
                </div>
                <div class="db-card-body">
                    <div class="db-task-list" id="dbTaskList">
                        <div style="text-align:center;padding:18px 0;color:var(--muted);font-size:12px;">
                            <i class="fa fa-spinner fa-spin"></i> Checking modules...
                        </div>
                    </div>
                </div>
            </div>

            <!-- CALENDAR -->
            <div class="db-card" style="animation-delay:.52s;">
                <div class="db-card-header">
                    <div>
                        <div class="db-card-title">Calendar</div>
                        <div class="db-card-sub" id="todayDate">&mdash;</div>
                    </div>
                </div>
                <div class="db-card-body">
                    <div class="mini-cal" id="miniCal"></div>
                    <div id="calEventList" style="margin-top:12px;display:flex;flex-direction:column;gap:6px;"></div>
                </div>
            </div>

            <!-- ATTENDANCE RING (visual) -->
            <?php if ($can('Attendance') || $can('SIS')): ?>
            <div class="db-card" style="animation-delay:.56s;">
                <div class="db-card-header">
                    <div>
                        <div class="db-card-title">Attendance Overview</div>
                        <div class="db-card-sub">Today's snapshot</div>
                    </div>
                </div>
                <div class="db-card-body">
                    <div class="att-ring-wrap">
                        <svg id="attRingSvg" width="140" height="140" viewBox="0 0 140 140">
                            <circle cx="70" cy="70" r="58" fill="none" stroke="var(--border2)" stroke-width="10" />
                            <circle id="attRingPresent" cx="70" cy="70" r="58" fill="none"
                                    stroke="var(--green)" stroke-width="10"
                                    stroke-dasharray="0 364.42" stroke-dashoffset="0"
                                    stroke-linecap="round" transform="rotate(-90 70 70)"
                                    style="transition:stroke-dasharray 1s var(--ease);" />
                            <circle id="attRingAbsent" cx="70" cy="70" r="58" fill="none"
                                    stroke="var(--rose)" stroke-width="10"
                                    stroke-dasharray="0 364.42" stroke-dashoffset="0"
                                    stroke-linecap="round" transform="rotate(-90 70 70)"
                                    style="transition:stroke-dasharray 1s var(--ease);" />
                        </svg>
                        <div class="att-ring-center">
                            <div class="att-ring-val" id="attRingVal">--</div>
                            <div class="att-ring-lbl">Present</div>
                        </div>
                        <div class="att-legend">
                            <div class="att-legend-item">
                                <div class="att-legend-dot" style="background:var(--green)"></div>
                                <span id="attLegPresent">Present: --</span>
                            </div>
                            <div class="att-legend-item">
                                <div class="att-legend-dot" style="background:var(--rose)"></div>
                                <span id="attLegAbsent">Absent: --</span>
                            </div>
                            <div class="att-legend-item">
                                <div class="att-legend-dot" style="background:var(--amber)"></div>
                                <span id="attLegLate">Late: --</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ═══ ROW 4: Recent Activity + Birthdays ═══ -->
        <div class="db-grid-row4">

            <!-- RECENT ACTIVITY FEED -->
            <div class="db-card" style="animation-delay:.52s;">
                <div class="db-card-header">
                    <div>
                        <div class="db-card-title">Recent Activity</div>
                        <div class="db-card-sub">Live across modules</div>
                    </div>
                    <span class="db-card-badge" id="activityCountBadge" style="display:none;">0</span>
                </div>
                <div class="db-card-body">
                    <div id="activityList">
                        <div style="text-align:center;padding:24px 0;color:var(--muted);font-size:12px;">
                            <i class="fa fa-spinner fa-spin"></i> Loading activity...
                        </div>
                    </div>
                </div>
            </div>

            <!-- BIRTHDAYS TODAY -->
            <?php if ($can('SIS') || $can('Attendance')): ?>
            <div class="db-card" style="animation-delay:.56s;">
                <div class="db-card-header">
                    <div>
                        <div class="db-card-title">🎂 Birthdays Today</div>
                        <div class="db-card-sub" id="birthdayDate"><?= date('d M Y') ?></div>
                    </div>
                    <span class="db-card-badge" id="birthdayCountBadge" style="display:none;">0</span>
                </div>
                <div class="db-card-body">
                    <div id="birthdayList">
                        <div style="text-align:center;padding:24px 0;color:var(--muted);font-size:12px;">
                            <i class="fa fa-spinner fa-spin"></i> Loading...
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ═══ SUBSCRIPTION (admin-only) ═══ -->
        <?php if ($can('Configuration')): ?>
        <div class="db-card" id="subPanel" style="animation-delay:.60s;">
            <div class="db-card-header">
                <div>
                    <div class="db-card-title">Subscription & Billing</div>
                    <div class="db-card-sub" id="subPlanLabel">Loading plan info...</div>
                </div>
                <span class="db-card-badge" id="subStatusBadge" style="display:none;">--</span>
            </div>
            <div class="db-card-body">
                <!-- Empty state (shown when no subscription) -->
                <div id="subEmpty" style="display:none;text-align:center;padding:24px 16px;">
                    <i class="fa fa-credit-card" style="font-size:28px;color:var(--muted2);display:block;margin-bottom:10px;"></i>
                    <div style="font-size:13px;font-weight:600;color:var(--muted);margin-bottom:4px;">No Subscription Assigned</div>
                    <div style="font-size:11px;color:var(--muted2);">Contact your administrator to set up a plan.</div>
                </div>

                <!-- Data state (shown when subscription exists) -->
                <div id="subData" style="display:none;">
                    <div class="sub-summary">
                        <div class="sub-chip">
                            <div class="sub-chip-lbl">Expires</div>
                            <div class="sub-chip-val" id="subExpiry">&mdash;</div>
                            <div class="sub-chip-hint" id="subDaysLeft"></div>
                        </div>
                        <div class="sub-chip">
                            <div class="sub-chip-lbl">Total Paid</div>
                            <div class="sub-chip-val" id="subTotalPaid" style="color:var(--green);">&mdash;</div>
                        </div>
                        <div class="sub-chip">
                            <div class="sub-chip-lbl">Balance Due</div>
                            <div class="sub-chip-val" id="subBalanceDue" style="color:#ef4444;">&mdash;</div>
                            <div class="sub-chip-hint" id="subNextDueDate"></div>
                        </div>
                    </div>

                    <div id="subAlert" class="sub-alert-bar"></div>

                    <div style="font-size:10px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;font-family:var(--mono);">Recent Payments</div>
                    <div id="subPayments">
                        <div style="text-align:center;padding:12px;color:var(--muted);font-size:12px;">Loading...</div>
                    </div>
                </div>

                <!-- Error state -->
                <div id="subError" style="display:none;text-align:center;padding:20px 16px;">
                    <i class="fa fa-exclamation-circle" style="font-size:24px;color:var(--rose);display:block;margin-bottom:8px;"></i>
                    <div style="font-size:12px;color:var(--muted);">Unable to load subscription info.</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /db-body -->
</div><!-- /db-root -->

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function() {

    var root  = document.getElementById('dbRoot');
    var dlEl  = document.getElementById('dbLiveDate');
    var tdEl  = document.getElementById('todayDate');
    var BASE  = '<?= rtrim(base_url(), '/') ?>';
    var ROLE  = '<?= htmlspecialchars($admin_role, ENT_QUOTES, 'UTF-8') ?>';
    var CAN_FEES = <?= json_encode(has_permission('Fees')) ?>;

    var feeChartInst = null;
    var classChartInst = null;
    var genderChartInst = null;
    var calEventDates = {};

    /* ── Helpers ── */
    function fmtDate(d) {
        return d.toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    }
    function fmtINR(n) {
        return '\u20B9' + Number(n || 0).toLocaleString('en-IN');
    }
    function tick() {
        var n = new Date();
        if (dlEl) dlEl.textContent = fmtDate(n);
        if (tdEl) tdEl.textContent = fmtDate(n);
    }
    tick();
    setInterval(tick, 60000);

    requestAnimationFrame(function() { setTimeout(function() { root.classList.add('t-ready'); }, 60); });

    if (!CAN_FEES) {
        document.querySelectorAll('.db-finance-only').forEach(function(el) { el.style.display = 'none'; });
    }

    /* ── Counter animation ── */
    function animateValue(el, target) {
        if (!el) return;
        var start = null, dur = 1000;
        function step(ts) {
            if (!start) start = ts;
            var p = Math.min((ts - start) / dur, 1);
            var ease = 1 - Math.pow(1 - p, 3);
            el.textContent = Math.round(ease * target).toLocaleString('en-IN');
            if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }

    function animateINR(el, target) {
        if (!el) return;
        var start = null, dur = 1000;
        function step(ts) {
            if (!start) start = ts;
            var p = Math.min((ts - start) / dur, 1);
            var ease = 1 - Math.pow(1 - p, 3);
            el.textContent = fmtINR(Math.round(ease * target));
            if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }

    function _esc(s) {
        var d = document.createElement('div'); d.textContent = String(s || ''); return d.innerHTML;
    }
    function escHtml(s) { return _esc(s); }

    function getC() {
        return { grid: 'var(--chart-grid)', tick: 'var(--chart-tick)', legend: 'var(--muted)' };
    }

    /* ══════════════════════════════════════════
       LOAD DASHBOARD DATA — two-stage fetch.
       Stage 1: get_dashboard_data (fast) — stats + today's attendance
                + upcoming events. Renders tiles immediately.
       Stage 2: get_dashboard_charts (lazy) — demographics + monthly
                fees + calendar + ongoing/recent events. Backfills the
                charts once they arrive so the user sees the page land
                in <2s even when the heavier scans take longer.
    ══════════════════════════════════════════ */
    fetch(BASE + '/admin/get_dashboard_data')
        .then(function(r) { return r.json(); })
        .then(function(D) {
            populateStats(D.stats);
            populateAttendance(D.attendance || {});
            populateEvents(D.events);

            // Fire the heavy charts endpoint after the fast one lands.
            fetch(BASE + '/admin/get_dashboard_charts')
                .then(function(r) { return r.json(); })
                .then(function(C) {
                    if (!C) return;
                    // Backfill lazy stat tiles
                    if (D.stats) {
                        D.stats.classes        = C.classes        || 0;
                        D.stats.sections       = C.sections       || 0;
                        D.stats.fee_defaulters = C.fee_defaulters || 0;
                        populateStats(D.stats);
                    }
                    // Charts
                    buildFeeChart(C.monthly_fees || {}, D.stats.fees_collected, D.stats.receipt_count);
                    // New widgets (replaced Students-by-Class + Gender charts)
                    populateTopDefaulters(C.top_defaulters || []);
                    populateAbsentToday(C.absent_today || { count: 0, students: [] });
                    storeCalendarEvents(C.calendar_events || []);
                    renderCalendar(window._dbCalY, window._dbCalM);
                    // Merge ongoing/recent into the already-rendered events section
                    populateEvents({
                        upcoming: (D.events && D.events.upcoming) || [],
                        ongoing:  (C.events && C.events.ongoing)  || [],
                        recent:   (C.events && C.events.recent)   || [],
                    });
                    // New widgets: fee breakdown + birthdays
                    populateFeeBreakdown(C.fee_breakdown || {});
                    populateBirthdays(C.birthdays_today || []);
                })
                .catch(function(e) { console.warn('Dashboard charts load failed:', e); });
        })
        .catch(function(e) { console.error('Dashboard load failed:', e); });

    /* ══════════════════════════════════════════
       ACTIVITY FEED — lazy, fires after main dashboard lands.
    ══════════════════════════════════════════ */
    fetch(BASE + '/admin/get_dashboard_activity')
        .then(function(r) { return r.json(); })
        .then(function(A) { populateActivity((A && A.activity) || []); })
        .catch(function(e) {
            console.warn('Activity load failed:', e);
            var el = document.getElementById('activityList');
            if (el) el.innerHTML = '<div style="text-align:center;padding:18px 0;color:var(--muted);font-size:12px;">'
                + '<i class="fa fa-check-circle" style="color:var(--brand);margin-right:6px;"></i>No recent activity</div>';
        });

    /* ── Render helpers for the new widgets ── */
    function populateFeeBreakdown(fb) {
        var today = Number(fb.today || 0);
        var month = Number(fb.month || 0);
        var year  = Number(fb.year  || 0);
        var a = document.getElementById('fbToday');
        var b = document.getElementById('fbMonth');
        var c = document.getElementById('fbYear');
        if (a) animateINR(a, today);
        if (b) animateINR(b, month);
        if (c) animateINR(c, year);
    }

    function populateActivity(items) {
        var el = document.getElementById('activityList');
        var badge = document.getElementById('activityCountBadge');
        if (!el) return;
        if (!items.length) {
            el.innerHTML = '<div style="text-align:center;padding:18px 0;color:var(--muted);font-size:12px;">'
                + '<i class="fa fa-check-circle" style="color:var(--brand);margin-right:6px;"></i>No recent activity yet</div>';
            if (badge) badge.style.display = 'none';
            return;
        }
        if (badge) { badge.style.display = ''; badge.textContent = String(items.length); }
        var base = '<?= base_url() ?>';
        var html = '';
        items.forEach(function(it) {
            var href = it.action ? (base + it.action) : '#';
            var rel  = relativeTime(it.time);
            html += '<a class="activity-item" href="' + escHtml(href) + '" style="text-decoration:none;">'
                +   '<div class="activity-icon" style="background:' + (it.color || '#0f766e') + '">'
                +     '<i class="fa ' + (it.icon || 'fa-circle') + '"></i>'
                +   '</div>'
                +   '<div class="activity-body">'
                +     '<div class="activity-title">' + escHtml(it.title || '') + '</div>'
                +     '<div class="activity-detail">' + escHtml(it.detail || '') + '</div>'
                +   '</div>'
                +   '<div class="activity-time">' + escHtml(rel) + '</div>'
                + '</a>';
        });
        el.innerHTML = html;
    }

    function populateBirthdays(items) {
        var el = document.getElementById('birthdayList');
        var badge = document.getElementById('birthdayCountBadge');
        if (!el) return;
        if (!items.length) {
            el.innerHTML = '<div style="text-align:center;padding:18px 0;color:var(--muted);font-size:12px;">'
                + '<i class="fa fa-birthday-cake" style="color:var(--muted2);margin-right:6px;"></i>No birthdays today</div>';
            if (badge) badge.style.display = 'none';
            return;
        }
        if (badge) { badge.style.display = ''; badge.textContent = String(items.length); }
        var html = '';
        items.forEach(function(b, idx) {
            var initial = (b.name || '?').charAt(0).toUpperCase();
            var btnId   = 'bdayBtn_' + idx;
            var sid     = escHtml(b.studentId || '');
            html += '<div class="bday-item" data-sid="' + sid + '">'
                +   '<div class="bday-avatar">' + escHtml(initial) + '</div>'
                +   '<div style="flex:1;min-width:0;">'
                +     '<div class="bday-name">' + escHtml(b.name || '') + '</div>'
                +     '<div class="bday-class">' + escHtml(b.class || '') + '</div>'
                +   '</div>'
                +   '<button id="' + btnId + '" class="bday-wish-btn"'
                +       ' data-sid="' + sid + '"'
                +       ' data-name="'      + escHtml(b.name      || '') + '"'
                +       ' data-classname="' + escHtml(b.className || '') + '"'
                +       ' data-section="'   + escHtml(b.section   || '') + '"'
                +       ' title="Send birthday wish">'
                +     '<i class="fa fa-paper-plane"></i> Wish'
                +   '</button>'
                + '</div>';
        });
        el.innerHTML = html;

        // Bind click handlers.
        el.querySelectorAll('.bday-wish-btn').forEach(function(btn) {
            btn.addEventListener('click', function() { sendBirthdayWish(btn); });
        });
    }

    function sendBirthdayWish(btn) {
        if (btn.disabled) return;
        var sid = btn.getAttribute('data-sid');
        if (!sid) { alert('Student ID missing — cannot send wish.'); return; }

        var originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';

        var fd = new FormData();
        fd.append('studentId',   sid);
        fd.append('studentName', btn.getAttribute('data-name') || '');
        fd.append('className',   btn.getAttribute('data-classname') || '');
        fd.append('section',     btn.getAttribute('data-section') || '');
        try { fd.append('<?= $this->security->get_csrf_token_name() ?>', '<?= $this->security->get_csrf_hash() ?>'); } catch(e) {}

        fetch(BASE + '/admin/send_birthday_wish', { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                if (res && res.status === 'success') {
                    btn.classList.add('bday-sent');
                    btn.innerHTML = '<i class="fa fa-check"></i> Sent';
                    // Leave disabled — idempotency is server-enforced for the rest of the day.
                } else {
                    btn.disabled = false;
                    btn.innerHTML = originalHtml;
                    alert((res && res.message) || 'Failed to send wish.');
                }
            })
            .catch(function() {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                alert('Network error. Please try again.');
            });
    }

    function populateTopDefaulters(items) {
        var el = document.getElementById('topDefaultersList');
        if (!el) return;
        if (!items.length) {
            el.innerHTML = '<div style="text-align:center;padding:18px 0;color:var(--muted);font-size:12px;">'
                + '<i class="fa fa-check-circle" style="color:var(--brand);margin-right:6px;"></i>No defaulters — all paid up!</div>';
            return;
        }
        var base = '<?= base_url() ?>';
        var html = '';
        items.forEach(function(d, i) {
            var rank = i + 1;
            var name  = escHtml(d.name || 'Student');
            var cls   = escHtml(d.class || '');
            var dues  = fmtINR(Number(d.totalDues || 0));
            var flags = (d.examBlocked ? ' <span class="def-flag def-flag-red" title="Exam blocked">EB</span>' : '') +
                        (d.unpaidCount ? ' <span class="def-flag def-flag-amber" title="Unpaid month count">' + d.unpaidCount + 'm</span>' : '');
            var href = d.studentId
                ? base + 'fees/fees_counter?user_id=' + encodeURIComponent(d.studentId)
                : base + 'fees/defaulter_report';
            html += '<a class="def-item" href="' + href + '" style="text-decoration:none;">'
                +   '<div class="def-rank">#' + rank + '</div>'
                +   '<div class="def-body">'
                +     '<div class="def-name">' + name + flags + '</div>'
                +     '<div class="def-class">' + cls + '</div>'
                +   '</div>'
                +   '<div class="def-dues">' + dues + '</div>'
                + '</a>';
        });
        el.innerHTML = html;
    }

    function populateAbsentToday(payload) {
        var el = document.getElementById('absentStudentsList');
        var badge = document.getElementById('absentCountBadge');
        var sub = document.getElementById('absentSubLabel');
        if (!el) return;
        var total = Number(payload.count || 0);
        var list  = payload.students || [];
        if (sub) sub.textContent = '<?= date('d M Y') ?>' + (total > 0 ? ' · ' + total + ' absent' : '');
        if (badge) {
            if (total > 0) { badge.style.display = ''; badge.textContent = String(total); }
            else badge.style.display = 'none';
        }
        if (!list.length) {
            el.innerHTML = '<div style="text-align:center;padding:18px 0;color:var(--muted);font-size:12px;">'
                + '<i class="fa fa-check-circle" style="color:var(--brand);margin-right:6px;"></i>Everyone\'s present today</div>';
            return;
        }
        var html = '';
        list.forEach(function(s) {
            var initial = (s.name || '?').charAt(0).toUpperCase();
            html += '<div class="absent-item">'
                +   '<div class="absent-avatar">' + escHtml(initial) + '</div>'
                +   '<div style="flex:1;min-width:0;">'
                +     '<div class="absent-name">' + escHtml(s.name || '') + '</div>'
                +     '<div class="absent-class">' + escHtml(s.class || '') + '</div>'
                +   '</div>'
                +   '<i class="fa fa-times-circle" style="color:var(--rose);"></i>'
                + '</div>';
        });
        if (total > list.length) {
            html += '<div style="text-align:center;padding:8px 0 0;font-size:11px;color:var(--muted);">'
                +   '+ ' + (total - list.length) + ' more — <a href="<?= base_url('attendance/student') ?>" style="color:var(--brand);">view all</a>'
                + '</div>';
        }
        el.innerHTML = html;
    }

    function relativeTime(iso) {
        if (!iso) return '';
        var then = Date.parse(iso);
        if (isNaN(then)) return '';
        var sec = Math.max(0, (Date.now() - then) / 1000);
        if (sec < 60)     return Math.floor(sec) + 's ago';
        if (sec < 3600)   return Math.floor(sec / 60) + 'm ago';
        if (sec < 86400)  return Math.floor(sec / 3600) + 'h ago';
        if (sec < 604800) return Math.floor(sec / 86400) + 'd ago';
        var d = new Date(then);
        return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
    }

    /* ══════════════════════════════════════════
       TASKS & ALERTS — reuses the header bell's fetch instead of
       firing a second request. See header.php for the shared promise.
    ══════════════════════════════════════════ */
    (window.__graderTasksPromise || fetch(BASE + '/notifications/get_tasks').then(function(r){return r.json();}))
        .then(function(D) {
            if (!D || D.status !== 'success') return;
            renderTasks(D.tasks || []);
            renderAlerts(D.alerts || []);
        })
        .catch(function() {
            var el = document.getElementById('dbTaskList');
            if (el) el.innerHTML = '<div style="text-align:center;padding:18px 0;color:var(--muted);font-size:12px;">'
                + '<i class="fa fa-check-circle" style="color:var(--brand);margin-right:6px;"></i>No pending tasks</div>';
        });

    function renderTasks(tasks) {
        var el = document.getElementById('dbTaskList');
        var countEl = document.getElementById('dbTaskCount');
        if (!el) return;

        if (!tasks.length) {
            el.innerHTML = '<div style="text-align:center;padding:20px 0;color:var(--muted);font-size:12px;">'
                + '<i class="fa fa-check-circle" style="color:var(--brand);font-size:18px;display:block;margin-bottom:6px;"></i>All caught up!</div>';
            if (countEl) countEl.textContent = 'Nothing pending';
            return;
        }

        var highCount = tasks.filter(function(t) { return t.priority === 'high'; }).length;
        if (countEl) countEl.textContent = tasks.length + ' item' + (tasks.length > 1 ? 's' : '')
            + (highCount ? ' \u00B7 ' + highCount + ' urgent' : '');

        el.innerHTML = tasks.map(function(t) {
            var href = t.action ? (BASE + '/' + t.action) : '#';
            return '<a class="db-task-item" href="' + _esc(href) + '">'
              + '<div class="db-task-icon" style="background:' + _esc(t.color || '#0f766e') + '"><i class="fa ' + _esc(t.icon || 'fa-tasks') + '"></i></div>'
              + '<div class="db-task-body">'
              + '<div class="db-task-title">' + _esc(t.title) + '</div>'
              + '<div class="db-task-detail">' + _esc(t.detail || '') + '</div>'
              + '</div>'
              + '<span class="db-task-badge ' + _esc(t.priority || 'low') + '">' + _esc((t.priority || 'low').toUpperCase()) + '</span>'
              + '</a>';
        }).join('');
    }

    function renderAlerts(alerts) {
        var el = document.getElementById('dbAlerts');
        if (!el || !alerts.length) { if (el) el.style.display = 'none'; return; }

        el.innerHTML = alerts.map(function(a, i) {
            var type = a.type || 'warning';
            return '<div class="db-alert-banner ' + _esc(type) + '" data-key="' + _esc(a.key || '') + '">'
              + '<i class="fa ' + _esc(a.icon || 'fa-exclamation-triangle') + ' alert-icon"></i>'
              + '<div class="db-alert-body">'
              + '<div class="db-alert-title">' + _esc(a.title) + '</div>'
              + '<div class="db-alert-detail">' + _esc(a.detail || '') + '</div></div>'
              + (a.action ? '<a class="db-alert-action" href="' + BASE + '/' + _esc(a.action) + '">View</a>' : '')
              + '<button class="db-alert-dismiss" title="Dismiss" onclick="dismissAlert(this)"><i class="fa fa-times"></i></button>'
              + '</div>';
        }).join('');
        el.style.display = '';
    }

    window.dismissAlert = function(btn) {
        var banner = btn.closest('.db-alert-banner');
        if (!banner) return;
        var key = banner.getAttribute('data-key');
        banner.style.transition = 'opacity .25s, max-height .25s';
        banner.style.opacity = '0'; banner.style.maxHeight = '0'; banner.style.overflow = 'hidden';
        setTimeout(function() { banner.remove(); }, 300);

        if (key) {
            var csrf = document.querySelector('meta[name="csrf-token"]');
            var csrfName = document.querySelector('meta[name="csrf-name"]');
            var fd = new FormData();
            fd.append('key', key);
            if (csrf && csrfName) fd.append(csrfName.content, csrf.content);
            fetch(BASE + '/notifications/dismiss_alert', { method: 'POST', body: fd });
        }
    };

    /* ── Stats ── */
    function populateStats(s) {
        animateValue(document.getElementById('valStudents'), s.students);
        animateValue(document.getElementById('valTeachers'), s.teachers);

        var classEl = document.getElementById('valClasses');
        if (classEl) classEl.textContent = s.classes;
        var ccEl = document.getElementById('classCount');
        if (ccEl) ccEl.innerHTML = '<i class="fa fa-minus"></i> ' + s.classes + ' classes';
        var scEl = document.getElementById('sectionCount');
        if (scEl) scEl.innerHTML = '<i class="fa fa-minus"></i> ' + s.sections + ' sections';

        if (CAN_FEES) {
            animateINR(document.getElementById('valFees'), s.fees_collected);
            var rcBadge = document.getElementById('receiptCountBadge');
            if (rcBadge) rcBadge.innerHTML = '<i class="fa fa-minus"></i> ' + (s.receipt_count || 0) + ' receipts';
            animateValue(document.getElementById('valDefaulters'), s.fee_defaulters || 0);
        }
    }

    /* ── Attendance ── */
    function populateAttendance(att) {
        var valEl = document.getElementById('valAttendance');
        var badgeEl = document.getElementById('attAbsentCount');
        var ringVal = document.getElementById('attRingVal');
        var ringPresent = document.getElementById('attRingPresent');
        var ringAbsent  = document.getElementById('attRingAbsent');
        var legP = document.getElementById('attLegPresent');
        var legA = document.getElementById('attLegAbsent');
        var legL = document.getElementById('attLegLate');
        var C = 364.42; // 2*PI*58

        if (!att.total || att.rate === null) {
            if (valEl) { valEl.textContent = '--'; valEl.style.fontSize = '22px'; }
            if (badgeEl) badgeEl.innerHTML = '<i class="fa fa-minus"></i> Not marked';
            if (ringVal) ringVal.textContent = '--';
            return;
        }

        // Animate stat card percentage
        if (valEl) {
            var start = null, dur = 1000, target = att.rate;
            (function animate(ts) {
                if (!start) start = ts;
                var p = Math.min((ts - start) / dur, 1);
                var ease = 1 - Math.pow(1 - p, 3);
                valEl.textContent = (ease * target).toFixed(1) + '%';
                if (p < 1) requestAnimationFrame(animate);
            })(performance.now());
            requestAnimationFrame(function re(ts) {
                if (!start) start = ts;
                var p = Math.min((ts - start) / dur, 1);
                var ease = 1 - Math.pow(1 - p, 3);
                valEl.textContent = (ease * target).toFixed(1) + '%';
                if (p < 1) requestAnimationFrame(re);
            });
        }

        if (badgeEl) {
            var parts = [];
            if (att.absent > 0) parts.push(att.absent + ' absent');
            if (att.late > 0) parts.push(att.late + ' late');
            badgeEl.innerHTML = '<i class="fa fa-minus"></i> ' + (parts.length ? parts.join(', ') : att.present + '/' + att.total);
        }

        // Attendance ring
        if (ringVal) ringVal.textContent = att.rate.toFixed(1) + '%';
        if (legP) legP.textContent = 'Present: ' + att.present;
        if (legA) legA.textContent = 'Absent: ' + att.absent;
        if (legL) legL.textContent = 'Late: ' + att.late;

        var presentPct = att.total > 0 ? att.present / att.total : 0;
        var absentPct  = att.total > 0 ? att.absent / att.total : 0;

        setTimeout(function() {
            if (ringPresent) ringPresent.setAttribute('stroke-dasharray', (presentPct * C) + ' ' + C);
            if (ringAbsent) {
                var offset = presentPct * C;
                ringAbsent.setAttribute('stroke-dasharray', (absentPct * C) + ' ' + C);
                ringAbsent.setAttribute('stroke-dashoffset', -offset);
            }
        }, 200);
    }

    /* ── Events ── */
    function populateEvents(evts) {
        var list = document.getElementById('evtList');
        var badge = document.getElementById('evtBadge');
        var items = [];
        var catIcons = { event:'fa fa-star', cultural:'fa fa-music', sports:'fa fa-trophy' };

        (evts.ongoing || []).forEach(function(e) {
            items.push({ data:e, cls:'ongoing', badgeCls:'ongoing', badgeTxt:'Ongoing' });
        });
        (evts.upcoming || []).forEach(function(e) {
            items.push({ data:e, cls:'upcoming', badgeCls:'upcoming', badgeTxt:fmtShortDate(e.start) });
        });

        var total = (evts.ongoing||[]).length + (evts.upcoming||[]).length;
        if (badge) {
            badge.textContent = total + ' Active';
            if (!total) badge.classList.add('rose');
        }

        if (!items.length) {
            if (list) list.innerHTML = '<div class="evt-empty"><i class="fa fa-calendar-times-o" style="font-size:20px;margin-bottom:8px;display:block;opacity:.4;"></i>No upcoming events</div>';
            return;
        }

        if (list) list.innerHTML = items.slice(0, 6).map(function(item) {
            var e = item.data;
            var icon = catIcons[e.category] || 'fa fa-star';
            return '<div class="evt-item">'
                + '<div class="evt-icon ' + item.cls + '"><i class="' + icon + '"></i></div>'
                + '<div class="evt-info"><div class="evt-name">' + escHtml(e.title) + '</div>'
                + '<div class="evt-date">' + (e.location ? escHtml(e.location) + ' \u00B7 ' : '') + e.start + '</div></div>'
                + '<span class="evt-badge ' + item.badgeCls + '">' + item.badgeTxt + '</span>'
                + '</div>';
        }).join('');
    }

    function fmtShortDate(d) {
        if (!d) return '';
        var p = d.split('-');
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return (p[2] ? parseInt(p[2]) : '') + ' ' + (months[parseInt(p[1]) - 1] || '');
    }

    /* ── Fallback when Chart.js (CDN) is unreachable ── */
    function showChartFallback(canvasEl) {
        try {
            var parent = canvasEl.parentNode;
            if (!parent || parent.querySelector('.chart-fallback')) return;
            canvasEl.style.display = 'none';
            var msg = document.createElement('div');
            msg.className = 'chart-fallback';
            msg.style.cssText = 'display:flex;align-items:center;justify-content:center;height:100%;min-height:160px;color:var(--muted);font-size:12px;text-align:center;padding:16px;';
            msg.innerHTML = '<i class="fa fa-bar-chart" style="margin-right:8px;opacity:.6;"></i>Chart library unavailable (offline)';
            parent.appendChild(msg);
        } catch (e) {}
    }

    /* ── Fee Chart ── */
    function buildFeeChart(monthly, totalCollected, receiptCount) {
        var keys = Object.keys(monthly);
        var labels = [], values = [];
        var months = ['Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec','Jan','Feb','Mar'];

        if (keys.length) {
            keys.forEach(function(k) {
                var parts = k.split('-');
                var mIdx = parseInt(parts[1]) - 1;
                var mName = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][mIdx];
                labels.push(mName);
                values.push(monthly[k]);
            });
        } else {
            labels = months;
            values = months.map(function() { return 0; });
        }

        var tcEl = document.getElementById('feeTotalCollected');
        var trEl = document.getElementById('feeTotalReceipts');
        var tmEl = document.getElementById('feeTotalMonths');
        if (tcEl) tcEl.textContent = fmtINR(totalCollected);
        if (trEl) trEl.textContent = (receiptCount || 0).toLocaleString('en-IN');
        if (tmEl) tmEl.textContent = keys.length;

        var ctx = document.getElementById('feeChart');
        if (!ctx) return;
        if (typeof Chart === 'undefined') { showChartFallback(ctx); return; }

        feeChartInst = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Collected',
                    data: values,
                    backgroundColor: function(context) {
                        var chart = context.chart;
                        var ctx2 = chart.ctx;
                        var area = chart.chartArea;
                        if (!area) return 'rgba(15,118,110,0.7)';
                        var gradient = ctx2.createLinearGradient(0, area.bottom, 0, area.top);
                        gradient.addColorStop(0, 'rgba(15,118,110,0.4)');
                        gradient.addColorStop(1, 'rgba(15,118,110,0.85)');
                        return gradient;
                    },
                    borderRadius: 6,
                    borderSkipped: false,
                    maxBarThickness: 40
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(17,24,39,.95)',
                        titleColor: '#f9fafb', bodyColor: '#d1d5db',
                        borderColor: 'rgba(255,255,255,.08)', borderWidth: 1,
                        cornerRadius: 8, padding: 10,
                        callbacks: { label: function(c) { return ' ' + c.dataset.label + ': ' + fmtINR(c.raw); } }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: 'var(--chart-tick)', font: { size: 11, family: 'Inter' } } },
                    y: {
                        grid: { color: 'rgba(15,118,110,0.06)', drawBorder: false },
                        ticks: { color: 'var(--chart-tick)', font: { size: 10, family: 'Inter' }, callback: function(v) { return fmtINR(v); } },
                        border: { display: false }
                    }
                }
            }
        });
    }

    /* ── Class Distribution ── */
    function buildClassChart(classDist) {
        var labels = Object.keys(classDist);
        var values = Object.values(classDist);
        var ctx = document.getElementById('classChart');
        if (!ctx || !labels.length) return;
        if (typeof Chart === 'undefined') { showChartFallback(ctx); return; }

        var colors = labels.map(function(_, i) {
            var hue = 170 + (i * 30) % 360;
            return 'hsla(' + hue + ', 55%, 50%, 0.75)';
        });

        classChartInst = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Students',
                    data: values,
                    backgroundColor: colors,
                    borderRadius: 5,
                    borderSkipped: false,
                    maxBarThickness: 22
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(17,24,39,.95)',
                        cornerRadius: 8, padding: 10,
                        callbacks: { label: function(c) { return ' ' + c.raw + ' students'; } }
                    }
                },
                scales: {
                    x: { grid: { color: 'rgba(15,118,110,0.06)', drawBorder: false }, ticks: { color: 'var(--chart-tick)', font: { size: 10 } }, border: { display: false } },
                    y: { grid: { display: false }, ticks: { color: 'var(--chart-tick)', font: { size: 11, weight: 500 } } }
                }
            }
        });
    }

    /* ── Gender Distribution ── */
    function buildGenderChart(gender, total) {
        var totalEl = document.getElementById('genderTotal');
        if (totalEl) totalEl.textContent = total.toLocaleString('en-IN');

        var labels = ['Male', 'Female', 'Other'];
        var values = [gender.Male || 0, gender.Female || 0, gender.Other || 0];
        var colors = ['#0f766e', '#f43f5e', '#f59e0b'];

        var legendEl = document.getElementById('genderLegend');
        if (legendEl) {
            legendEl.innerHTML = labels.map(function(l, i) {
                if (!values[i]) return '';
                var pct = total ? Math.round(values[i] / total * 100) : 0;
                return '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;' + (i < labels.length - 1 ? 'border-bottom:1px solid var(--border);' : '') + '">'
                    + '<div style="width:8px;height:8px;border-radius:3px;flex-shrink:0;background:' + colors[i] + '"></div>'
                    + '<span style="font-size:12px;color:var(--muted);flex:1;">' + l + '</span>'
                    + '<span style="font-family:var(--mono);font-size:11px;color:var(--text);font-weight:500;">' + values[i].toLocaleString('en-IN') + ' (' + pct + '%)</span>'
                    + '</div>';
            }).join('');
        }

        var ctx = document.getElementById('genderChart');
        if (!ctx) return;
        if (typeof Chart === 'undefined') { showChartFallback(ctx); return; }

        genderChartInst = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{ data: values, backgroundColor: colors, borderWidth: 0, hoverOffset: 4 }]
            },
            options: {
                cutout: '74%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(17,24,39,.95)',
                        cornerRadius: 8, padding: 10,
                        callbacks: { label: function(c) { return ' ' + c.label + ': ' + c.raw; } }
                    }
                },
                animation: { animateRotate: true, duration: 900 }
            }
        });
    }

    /* ── Calendar ── */
    function storeCalendarEvents(events) {
        calEventDates = {};
        (events || []).forEach(function(e) {
            if (!e.date) return;
            if (!calEventDates[e.date]) calEventDates[e.date] = [];
            calEventDates[e.date].push(e.title);
        });
    }

    (function() {
        var today = new Date();
        window._dbCalY = today.getFullYear();
        window._dbCalM = today.getMonth();
        window._dbCalPrev = function() {
            window._dbCalM--;
            if (window._dbCalM < 0) { window._dbCalM = 11; window._dbCalY--; }
            renderCalendar(window._dbCalY, window._dbCalM);
        };
        window._dbCalNext = function() {
            window._dbCalM++;
            if (window._dbCalM > 11) { window._dbCalM = 0; window._dbCalY++; }
            renderCalendar(window._dbCalY, window._dbCalM);
        };
        renderCalendar(window._dbCalY, window._dbCalM);
    })();

    function renderCalendar(y, m) {
        var el = document.getElementById('miniCal');
        if (!el) return;
        var today = new Date();
        var days = ['Su','Mo','Tu','We','Th','Fr','Sa'];
        var mN = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        var first = new Date(y, m, 1).getDay();
        var total = new Date(y, m + 1, 0).getDate();
        var prevT = new Date(y, m, 0).getDate();
        var tDay = (y === today.getFullYear() && m === today.getMonth()) ? today.getDate() : -1;

        var h = '<div class="mini-cal-header"><span class="mini-cal-month">' + mN[m] + ' ' + y + '</span>';
        h += '<div class="mini-cal-nav">';
        h += '<button onclick="window._dbCalPrev()"><i class="fa fa-chevron-left" style="font-size:9px"></i></button>';
        h += '<button onclick="window._dbCalNext()"><i class="fa fa-chevron-right" style="font-size:9px"></i></button>';
        h += '</div></div><div class="cal-grid">';
        days.forEach(function(d) { h += '<div class="cal-day-name">' + d + '</div>'; });
        for (var i = first - 1; i >= 0; i--) h += '<div class="cal-day other">' + (prevT - i) + '</div>';
        for (var d = 1; d <= total; d++) {
            var dateStr = y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
            var cls = 'cal-day';
            if (d === tDay) cls += ' today';
            else if (calEventDates[dateStr]) cls += ' has-event';
            h += '<div class="' + cls + '">' + d + '</div>';
        }
        var rem = (first + total) % 7;
        if (rem) rem = 7 - rem;
        for (var n = 1; n <= rem; n++) h += '<div class="cal-day other">' + n + '</div>';
        h += '</div>';
        el.innerHTML = h;

        var evtListEl = document.getElementById('calEventList');
        if (!evtListEl) return;
        var prefix = y + '-' + String(m + 1).padStart(2, '0');
        var monthEvents = [];
        Object.keys(calEventDates).forEach(function(date) {
            if (date.indexOf(prefix) === 0) {
                calEventDates[date].forEach(function(title) {
                    monthEvents.push({ date: date, title: title });
                });
            }
        });
        monthEvents.sort(function(a, b) { return a.date.localeCompare(b.date); });

        if (!monthEvents.length) {
            evtListEl.innerHTML = '<div style="text-align:center;font-size:11px;color:var(--muted);padding:8px;">No events this month</div>';
            return;
        }

        evtListEl.innerHTML = monthEvents.slice(0, 4).map(function(e) {
            var parts = e.date.split('-');
            var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            var label = parseInt(parts[2]) + ' ' + months[parseInt(parts[1]) - 1];
            return '<div style="display:flex;align-items:center;gap:10px;padding:7px 10px;border-radius:7px;background:var(--bg3);border:1px solid var(--border);border-left:3px solid var(--brand);">'
                + '<span style="font-family:var(--mono);font-size:10px;color:var(--muted);min-width:42px;">' + label + '</span>'
                + '<span style="font-size:11px;color:var(--text);">' + escHtml(e.title) + '</span>'
                + '</div>';
        }).join('');
    }

    /* ── Theme observer ── */
    if (window.MutationObserver) {
        new MutationObserver(function(mutations) {
            for (var i = 0; i < mutations.length; i++) {
                if (mutations[i].attributeName === 'data-theme') {
                    [feeChartInst, classChartInst].forEach(function(chart) {
                        if (chart) chart.update();
                    });
                }
            }
        }).observe(document.documentElement, { attributes: true });
    }

    /* ══════════════════════════════════════════
       SUBSCRIPTION
    ══════════════════════════════════════════ */
    fetch(BASE + '/admin/get_subscription_info')
        .then(function(r) { return r.json(); })
        .then(function(D) {
            var subData  = document.getElementById('subData');
            var subEmpty = document.getElementById('subEmpty');
            var subError = document.getElementById('subError');
            var badge    = document.getElementById('subStatusBadge');
            var planLbl  = document.getElementById('subPlanLabel');

            // Error from server
            if (D.error) {
                planLbl.textContent = 'Plan & payment status';
                subError.style.display = '';
                return;
            }

            // No plan assigned — show empty state
            var planName = D.plan_name || '';
            if (!planName || planName === '\u2014' || planName === '-') {
                planLbl.textContent = 'No plan assigned';
                subEmpty.style.display = '';
                return;
            }

            // ── Has subscription — show data ──
            subData.style.display = '';
            planLbl.textContent = planName + (D.billing_cycle ? ' \u00B7 ' + capitalize(D.billing_cycle) : '');

            // Status badge
            var st = (D.sub_status || 'Inactive').toLowerCase();
            badge.textContent = capitalize(D.sub_status || 'Inactive');
            badge.style.display = '';
            if (st === 'active')            { badge.className = 'db-card-badge green'; }
            else if (st === 'suspended')    { badge.className = 'db-card-badge rose'; }
            else if (st === 'grace_period') { badge.style.display = ''; badge.className = 'db-card-badge'; badge.style.background = 'rgba(249,115,22,.1)'; badge.style.color = '#f97316'; badge.style.borderColor = 'rgba(249,115,22,.18)'; }
            else if (st === 'expired')      { badge.className = 'db-card-badge rose'; }
            else                            { badge.className = 'db-card-badge'; }

            // Expiry
            var expiryEl = document.getElementById('subExpiry');
            if (expiryEl) expiryEl.textContent = D.expiry_date ? fmtShortDate(D.expiry_date) : '\u2014';

            if (D.days_left !== null && D.days_left !== undefined) {
                var dlEl2 = document.getElementById('subDaysLeft');
                if (dlEl2) {
                    if (D.days_left < 0) {
                        dlEl2.textContent = Math.abs(D.days_left) + ' days overdue';
                        dlEl2.style.color = '#ef4444';
                    } else if (D.days_left <= 30) {
                        dlEl2.textContent = D.days_left + ' days left';
                        dlEl2.style.color = '#f97316';
                    } else {
                        dlEl2.textContent = D.days_left + ' days left';
                        dlEl2.style.color = '#22c55e';
                    }
                }
            }

            // Total paid
            var tpEl = document.getElementById('subTotalPaid');
            if (tpEl) tpEl.textContent = fmtINR(D.total_paid || 0);

            // Balance due
            var balEl = document.getElementById('subBalanceDue');
            var ddEl  = document.getElementById('subNextDueDate');
            var totalBal = D.total_balance || 0;
            if (balEl) {
                balEl.textContent = fmtINR(totalBal);
                balEl.style.color = totalBal > 0 ? '#ef4444' : '#22c55e';
            }
            if (ddEl) {
                if (D.next_due_date) {
                    ddEl.textContent = 'Due: ' + fmtShortDate(D.next_due_date);
                    if (D.next_due_date < new Date().toISOString().slice(0, 10)) ddEl.style.color = '#ef4444';
                } else if (totalBal <= 0) {
                    ddEl.textContent = 'All clear';
                    ddEl.style.color = '#22c55e';
                }
            }

            // Alert bar
            var alertEl = document.getElementById('subAlert');
            if (alertEl) {
                var todayStr = new Date().toISOString().slice(0, 10);
                if (totalBal > 0 && D.next_due_date && D.next_due_date < todayStr) {
                    alertEl.className = 'sub-alert-bar visible overdue';
                    alertEl.innerHTML = '<i class="fa fa-exclamation-triangle" style="color:#ef4444;"></i>'
                        + '<span style="color:#ef4444;font-weight:600;">Payment overdue!</span>'
                        + '<span style="color:var(--muted);margin-left:auto;">' + fmtINR(totalBal) + ' since ' + fmtShortDate(D.next_due_date) + '</span>';
                } else if (D.days_left !== null && D.days_left <= 30 && D.days_left >= 0) {
                    alertEl.className = 'sub-alert-bar visible expiring';
                    alertEl.innerHTML = '<i class="fa fa-clock-o" style="color:#f97316;"></i>'
                        + '<span style="color:#f97316;font-weight:600;">Expiring soon</span>'
                        + '<span style="color:var(--muted);margin-left:auto;">' + D.days_left + ' days remaining</span>';
                }
            }

            // Payment history
            var payEl = document.getElementById('subPayments');
            if (!payEl) return;
            var pays = D.payments || [];
            if (!pays.length) {
                payEl.innerHTML = '<div style="text-align:center;padding:12px;color:var(--muted);font-size:12px;">No payment records yet.</div>';
                return;
            }
            var stCfg = {
                paid:    { bg:'rgba(34,197,94,.1)',  color:'#22c55e', icon:'fa fa-check-circle' },
                partial: { bg:'rgba(249,115,22,.1)', color:'#ea580c', icon:'fa fa-adjust' },
                pending: { bg:'rgba(59,130,246,.1)', color:'#3b82f6', icon:'fa fa-clock-o' },
                overdue: { bg:'rgba(239,68,68,.1)',  color:'#ef4444', icon:'fa fa-exclamation-circle' },
                failed:  { bg:'rgba(107,114,128,.1)',color:'#6b7280', icon:'fa fa-times-circle' }
            };
            payEl.innerHTML = pays.slice(0, 6).map(function(p) {
                var c = stCfg[p.status] || stCfg.pending;
                var amt = parseFloat(p.amount || 0), pd = parseFloat(p.amount_paid || 0), bl = parseFloat(p.balance || 0);
                var pct = amt > 0 ? Math.round(pd / amt * 100) : 0;
                return '<div class="sub-pay-item">'
                    + '<i class="' + c.icon + '" style="color:' + c.color + ';font-size:13px;flex-shrink:0;"></i>'
                    + '<div style="flex:1;min-width:0;">'
                    + '<div style="display:flex;justify-content:space-between;align-items:center;">'
                    + '<span style="font-size:12px;font-weight:600;color:var(--heading);">' + fmtINR(amt) + '</span>'
                    + '<span style="font-size:9px;padding:2px 6px;border-radius:4px;background:' + c.bg + ';color:' + c.color + ';font-weight:600;">' + capitalize(p.status) + '</span>'
                    + '</div>'
                    + '<div class="sub-pay-bar"><div class="sub-pay-fill" style="width:' + pct + '%;background:' + (pct >= 100 ? '#22c55e' : '#f97316') + ';"></div></div>'
                    + '<div style="font-size:10px;color:var(--muted);margin-top:2px;">Paid: ' + fmtINR(pd)
                    + (bl > 0 ? ' \u00B7 <span style="color:#ef4444;">Bal: ' + fmtINR(bl) + '</span>' : '')
                    + ' \u00B7 Due: ' + (p.due_date ? fmtShortDate(p.due_date) : '\u2014') + '</div>'
                    + '</div></div>';
            }).join('');
        })
        .catch(function(e) {
            console.error('Subscription info load failed:', e);
            var planLbl = document.getElementById('subPlanLabel');
            var subError = document.getElementById('subError');
            if (planLbl) planLbl.textContent = 'Plan & payment status';
            if (subError) subError.style.display = '';
        });

    function capitalize(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ') : ''; }

})();
</script>
