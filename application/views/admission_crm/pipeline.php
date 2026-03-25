<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
html { font-size:16px !important; }

.ac-wrap { padding:24px 22px 52px; min-height:100vh; }

/* ── Header ── */
.ac-head {
    display:flex; align-items:center; gap:14px;
    padding:18px 22px; margin-bottom:22px;
    background:var(--bg2); border:1px solid var(--border);
    border-radius:var(--r); box-shadow:var(--sh);
}
.ac-head-icon {
    width:44px; height:44px; border-radius:10px;
    background:var(--gold); display:flex; align-items:center; justify-content:center;
    flex-shrink:0; box-shadow:0 0 18px var(--gold-glow);
}
.ac-head-icon i { color:#fff; font-size:18px; }
.ac-head-info { flex:1; }
.ac-head-title { font-size:18px; font-weight:700; color:var(--t1); font-family:var(--font-d); }
.ac-head-sub { font-size:12px; color:var(--t3); margin-top:2px; }

/* ── Filter ── */
.ac-filters {
    background:var(--bg2); border:1px solid var(--border);
    border-radius:10px; padding:14px 16px; margin-bottom:20px;
    display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;
    box-shadow:var(--sh);
}
.ac-fg { display:flex; flex-direction:column; gap:4px; }
.ac-fg label { font-size:11px; font-weight:600; color:var(--t3); text-transform:uppercase; letter-spacing:.4px; }
.ac-fg select {
    padding:8px 12px; border:1px solid var(--border); border-radius:8px;
    background:var(--bg3); color:var(--t1); font-size:13px;
    font-family:var(--font-b); min-width:160px; outline:none;
    transition:border-color var(--ease), box-shadow var(--ease);
}
.ac-fg select:focus { border-color:var(--gold); box-shadow:0 0 0 3px var(--gold-ring); }

/* ── Pipeline Board ── */
.ac-board { display:flex; gap:14px; overflow-x:auto; padding-bottom:20px; min-height:500px; }
.ac-board::-webkit-scrollbar { height:6px; }
.ac-board::-webkit-scrollbar-track { background:var(--bg3); border-radius:3px; }
.ac-board::-webkit-scrollbar-thumb { background:var(--gold); border-radius:3px; }

.ac-col {
    min-width:240px; flex:1;
    background:var(--bg2); border:1px solid var(--border);
    border-radius:10px; display:flex; flex-direction:column;
    box-shadow:var(--sh);
}
.ac-col-hdr {
    padding:14px 16px; border-bottom:1px solid var(--border);
    display:flex; align-items:center; justify-content:space-between;
}
.ac-col-title { font-size:13px; font-weight:700; color:var(--t1); font-family:var(--font-b); }
.ac-col-count {
    background:var(--gold-dim); color:var(--gold);
    padding:2px 10px; border-radius:10px; font-size:11px; font-weight:700;
}
.ac-col-body {
    padding:10px; flex:1; display:flex; flex-direction:column; gap:10px;
    overflow-y:auto; max-height:calc(100vh - 260px);
}
.ac-col-body::-webkit-scrollbar { width:4px; }
.ac-col-body::-webkit-scrollbar-thumb { background:var(--border); border-radius:2px; }

/* ── Pipeline Card ── */
.ac-pipe-card {
    background:var(--bg3); border:1px solid var(--border);
    border-radius:8px; padding:14px; cursor:default;
    transition:all var(--ease);
}
.ac-pipe-card:hover { border-color:var(--gold); box-shadow:0 2px 12px var(--gold-dim); }
.ac-pipe-name { font-size:13px; font-weight:700; color:var(--t1); margin-bottom:6px; }
.ac-pipe-meta { font-size:11px; color:var(--t3); display:flex; gap:12px; flex-wrap:wrap; }
.ac-pipe-meta i { margin-right:3px; }
.ac-pipe-actions { display:flex; gap:6px; margin-top:10px; flex-wrap:wrap; }
.ac-pipe-btn {
    padding:4px 10px; border-radius:5px; border:1px solid var(--border);
    background:var(--bg2); color:var(--t2); font-size:11px; cursor:pointer;
    transition:all var(--ease); font-family:var(--font-b);
}
.ac-pipe-btn:hover { background:var(--gold-dim); color:var(--gold); border-color:var(--gold-ring); }

.ac-col-empty { text-align:center; padding:30px 10px; color:var(--t3); font-size:12px; }
.ac-col-empty i { display:block; font-size:1.5rem; margin-bottom:6px; opacity:.4; }

.ac-alert { padding:12px 16px; border-radius:8px; font-size:13px; display:none; margin-bottom:16px; }
.ac-alert-success { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
.ac-alert-error { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
.ac-back { color:var(--t3); font-size:13px; text-decoration:none; display:inline-flex; align-items:center; gap:6px; margin-bottom:14px; transition:color var(--ease); }
.ac-back:hover { color:var(--gold); }

@media(max-width:767px) {
    .ac-head { flex-wrap:wrap; }
    .ac-board { gap:10px; }
    .ac-col { min-width:220px; }
}
</style>

<div class="content-wrapper">
<div class="ac-wrap">

    <a href="<?= base_url('admission_crm') ?>" class="ac-back"><i class="fa fa-arrow-left"></i> Back to Dashboard</a>

    <div class="ac-head">
        <div class="ac-head-icon"><i class="fa fa-columns"></i></div>
        <div class="ac-head-info">
            <div class="ac-head-title">Admission Pipeline</div>
            <div class="ac-head-sub">Session <?= htmlspecialchars($session_year) ?> — Drag applications through stages</div>
        </div>
    </div>

    <div id="pageAlert" class="ac-alert"></div>

    <div class="ac-filters">
        <div class="ac-fg">
            <label>Filter by Class</label>
            <select id="filterClass" onchange="loadPipeline()">
                <option value="">All Classes</option>
                <?php foreach ($classes as $c): ?>
                <option value="<?= htmlspecialchars(str_replace('Class ', '', $c['class_name'])) ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="ac-board" id="pipelineBoard">
        <div style="text-align:center;padding:80px 0;color:var(--t3);width:100%;font-size:13px;">
            <i class="fa fa-spinner fa-spin" style="font-size:2rem;display:block;margin-bottom:12px;"></i>Loading pipeline...
        </div>
    </div>

</div>
</div>

<script>
var BASE = '<?= base_url() ?>';
var pipelineData = {}, stagesData = {};

document.addEventListener('DOMContentLoaded', function() { loadPipeline(); });

function loadPipeline() {
    fetch(BASE + 'admission_crm/fetch_pipeline', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: ''
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'success') {
            pipelineData = data.data.pipeline || {};
            stagesData = data.data.stages || {};
            renderPipeline();
        }
    })
    .catch(function() {
        document.getElementById('pipelineBoard').innerHTML = '<div style="text-align:center;padding:80px 0;color:var(--t3);width:100%;font-size:13px;"><i class="fa fa-exclamation-triangle" style="font-size:2rem;display:block;margin-bottom:8px;"></i>Failed to load pipeline</div>';
    });
}

function renderPipeline() {
    var cf = document.getElementById('filterClass').value;
    var board = document.getElementById('pipelineBoard');

    var colors = {
        document_collection:'#3b82f6', under_review:'#f59e0b', interview:'#8b5cf6',
        approved:'#10b981', rejected:'#ef4444', waitlisted:'#f97316',
    };

    var stageKeys = Object.keys(pipelineData);
    var html = '';

    stageKeys.forEach(function(sk) {
        var stage = pipelineData[sk];
        var items = (stage.items || []).filter(function(i) { return !cf || i.class === cf; });
        var color = colors[sk] || 'var(--gold)';

        html += '<div class="ac-col">';
        html += '<div class="ac-col-hdr" style="border-top:3px solid ' + color + ';border-top-left-radius:10px;border-top-right-radius:10px;">';
        html += '<span class="ac-col-title">' + esc(stage.label) + '</span>';
        html += '<span class="ac-col-count">' + items.length + '</span>';
        html += '</div><div class="ac-col-body">';

        if (items.length === 0) {
            html += '<div class="ac-col-empty"><i class="fa fa-inbox"></i>No applications</div>';
        } else {
            items.forEach(function(item) {
                html += '<div class="ac-pipe-card">';
                html += '<div class="ac-pipe-name">' + esc(item.student_name) + '</div>';
                html += '<div class="ac-pipe-meta">';
                html += '<span><i class="fa fa-book"></i>' + esc(item.class || '-') + '</span>';
                html += '<span><i class="fa fa-phone"></i>' + esc(item.phone || '') + '</span>';
                html += '<span>' + esc((item.created_at || '').substring(0, 10)) + '</span>';
                html += '</div>';
                html += '<div class="ac-pipe-actions">';
                stageKeys.forEach(function(target) {
                    if (target === sk || target === 'rejected') return;
                    html += '<button class="ac-pipe-btn" onclick="moveStage(\'' + item.id + '\',\'' + target + '\')">' + esc(pipelineData[target].label) + '</button>';
                });
                html += '</div></div>';
            });
        }

        html += '</div></div>';
    });

    board.innerHTML = html;
}

function moveStage(id, newStage) {
    fetch(BASE + 'admission_crm/update_stage', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ id: id, stage: newStage }).toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        showAlert(data.message, data.status === 'success' ? 'success' : 'error');
        if (data.status === 'success') loadPipeline();
    });
}

function showAlert(msg, type) {
    var el = document.getElementById('pageAlert');
    el.className = 'ac-alert ac-alert-' + type;
    el.textContent = msg; el.style.display = 'block';
    setTimeout(function() { el.style.display = 'none'; }, 3000);
}
function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
</script>
