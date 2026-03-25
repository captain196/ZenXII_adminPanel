<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
  <section class="content">

    <!-- ── Header ── matching manage_classes.php style ── -->
    <div class="mc-header">
      <div class="mc-header-inner">
        <div class="mc-header-left">
          <a href="<?= base_url('classes') ?>" class="mc-back-btn" title="Back to Class Management">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path d="M19 12H5M5 12l7 7M5 12l7-7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </a>
          <div class="mc-header-icon">
            <svg width="22" height="22" fill="none" viewBox="0 0 24 24"><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0H5a2 2 0 01-2-2v-4m6 6h10a2 2 0 002-2v-4" stroke="#fff" stroke-width="2"/></svg>
          </div>
          <div>
            <h1 class="mc-title"><?= htmlspecialchars($class_name) ?></h1>
            <p class="mc-subtitle">Section management &amp; student enrollment</p>
          </div>
        </div>

        <button type="button" class="mc-btn mc-btn-add" id="addSectionBtn">
          <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>
          Add Section
        </button>
      </div>
    </div>

    <!-- ── Stats ── -->
    <div class="mc-stats">
      <div class="mc-stat-card">
        <div class="mc-stat-icon mc-stat-icon--sections">
          <svg width="20" height="20" fill="none" viewBox="0 0 24 24"><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0H5a2 2 0 01-2-2v-4m6 6h10a2 2 0 002-2v-4" stroke="currentColor" stroke-width="2"/></svg>
        </div>
        <div>
          <span class="mc-stat-value" id="statSections">&mdash;</span>
          <span class="mc-stat-label">Total Sections</span>
        </div>
      </div>
      <div class="mc-stat-card">
        <div class="mc-stat-icon mc-stat-icon--students">
          <svg width="20" height="20" fill="none" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <div>
          <span class="mc-stat-value" id="statStudents">&mdash;</span>
          <span class="mc-stat-label">Total Students</span>
        </div>
      </div>
    </div>

    <!-- ── Loader ── -->
    <div class="mc-loader" id="pageLoader">
      <div class="mc-spinner"></div>
      <span>Loading sections…</span>
    </div>

    <!-- ── Section Grid ── -->
    <div class="mc-grid" id="sectionContainer" style="display:none;"></div>

    <!-- ── Empty State ── -->
    <div class="mc-empty" id="emptyState" style="display:none;">
      <svg width="56" height="56" fill="none" viewBox="0 0 24 24"><path d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18m0 0H5a2 2 0 01-2-2v-4m6 6h10a2 2 0 002-2v-4" stroke="var(--gold)" stroke-width="1.5"/></svg>
      <h3>No sections yet</h3>
      <p>Click <strong>"Add Section"</strong> above to create the first section.</p>
    </div>

    <div class="mc-toast" id="toast"><span id="toastMsg"></span></div>

    <!-- ── Add Section Modal ── -->
    <div class="modal fade" id="addSectionModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content cp-modal">
          <div class="modal-header">
            <h4 class="modal-title">
              <i class="fa fa-th-large" style="color:var(--gold);margin-right:8px;"></i>Add Section
            </h4>
            <button type="button" class="close" data-dismiss="modal">&times;</button>
          </div>
          <div class="modal-body">
            <div class="form-group">
              <label class="cp-label">Section Name</label>
              <input type="text" id="sectionNameInput" class="form-control cp-input" readonly>
            </div>
            <div class="form-group">
              <label class="cp-label">Maximum Strength</label>
              <input type="number" id="sectionStrengthInput" class="form-control cp-input"
                     placeholder="e.g. 40" min="1">
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-default" data-dismiss="modal">Cancel</button>
            <button class="btn cp-btn-save" id="saveSectionBtn">
              <i class="fa fa-save"></i> Save Section
            </button>
          </div>
        </div>
      </div>
    </div>

  </section>
</div>


<!-- jQuery must load before inline script (footer.php loads it too late) -->
<script src="<?= base_url() ?>tools/bower_components/jquery/dist/jquery.min.js"></script>

<script>
    /* ── CSRF ─────────────────────────────────────────────────────── */
    var CSRF_NAME = '<?php echo $this->security->get_csrf_token_name(); ?>';
    var CSRF_HASH = '<?php echo $this->security->get_csrf_hash(); ?>';

    $.ajaxSetup({
        beforeSend: function (xhr) {
            xhr.setRequestHeader('X-CSRF-Token', CSRF_HASH);
        }
    });

    var CLASS_NAME = <?= json_encode($class_name) ?>;
    var BASE = '<?= base_url() ?>';

    function escHtml(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str || ''));
        return d.innerHTML;
    }

    function toast(msg) {
        $('#toastMsg').text(msg);
        $('#toast').addClass('show');
        setTimeout(function () { $('#toast').removeClass('show'); }, 3000);
    }

    /* ── Load sections ─────────────────────────────────────────────── */
    $(document).ready(function () {
        if (!CLASS_NAME) return;
        loadSections();
    });

    function loadSections() {
        $('#pageLoader').show();
        $('#sectionContainer').hide();
        $('#emptyState').hide();

        var data = { class_name: CLASS_NAME };
        data[CSRF_NAME] = CSRF_HASH;

        $.ajax({
            url: BASE + 'classes/fetch_class_sections',
            type: 'POST',
            dataType: 'json',
            data: data,
            success: function (sections) {
                $('#pageLoader').hide();
                renderSections(sections);
            },
            error: function () {
                $('#pageLoader').hide();
                $('#emptyState').show();
                toast('Failed to load sections.');
            }
        });
    }

    function renderSections(sections) {
        var $grid = $('#sectionContainer');
        $grid.empty();

        if (!Array.isArray(sections) || sections.length === 0) {
            $('#statSections').text('0');
            $('#statStudents').text('0');
            $('#emptyState').show();
            return;
        }

        var totalStudents = 0;
        sections.forEach(function (sec) {
            totalStudents += (sec.strength || 0);
            $grid.append(buildCard(sec));
        });

        $('#statSections').text(sections.length);
        $('#statStudents').text(totalStudents);
        $grid.show();
    }

    function buildCard(sec) {
        var count    = sec.strength || 0;
        var max      = sec.max_strength || 0;
        var pct      = max > 0 ? Math.min(Math.round(count / max * 100), 100) : 0;
        var students = Array.isArray(sec.students) ? sec.students : [];

        var listHtml = '';
        if (students.length > 0) {
            students.slice(0, 20).forEach(function (s) {
                listHtml += '<div class="cp-stu-row"><i class="fa fa-user-o cp-stu-icon"></i>' + escHtml(s.name) + '</div>';
            });
            if (students.length > 20) {
                listHtml += '<div class="cp-stu-more">+' + (students.length - 20) + ' more (scroll to see all)</div>';
            }
        } else {
            listHtml = '<div class="cp-stu-empty"><i class="fa fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;"></i>No students enrolled yet</div>';
        }

        return '<div class="mc-card cp-sec-card" data-section="' + escHtml(sec.name) + '">' +
                 '<div class="mc-card-top">' +
                   '<h3 class="mc-card-class">' + escHtml(sec.name) + '</h3>' +
                   '<div class="mc-card-sections">' +
                     '<span class="mc-section-badge">' + count + ' / ' + max + ' students</span>' +
                   '</div>' +
                 '</div>' +
                 '<div class="mc-card-body">' +
                   '<div class="cp-stu-list">' + listHtml + '</div>' +
                   '<div class="cp-cap-bar">' +
                     '<div class="cp-cap-fill" style="width:' + pct + '%;"></div>' +
                   '</div>' +
                   '<span class="mc-card-meta">' +
                     '<strong>' + pct + '%</strong> capacity used' +
                   '</span>' +
                 '</div>' +
               '</div>';
    }

    /* ── Add Section ───────────────────────────────────────────────── */
    $('#addSectionBtn').on('click', function () {
        var next = getNextSectionLetter();
        $('#sectionNameInput').val('Section ' + next);
        $('#sectionStrengthInput').val('');
        $('#addSectionModal').modal('show');
    });

    function getNextSectionLetter() {
        var letters = [];
        $('.mc-card-class').each(function () {
            var m = $(this).text().trim().match(/Section\s+([A-Z])/i);
            if (m) letters.push(m[1].toUpperCase());
        });
        if (!letters.length) return 'A';
        letters.sort();
        return String.fromCharCode(letters[letters.length - 1].charCodeAt(0) + 1);
    }

    $('#saveSectionBtn').on('click', function () {
        var sectionName = $('#sectionNameInput').val().trim();
        var maxStrength = parseInt($('#sectionStrengthInput').val().trim(), 10);
        if (!maxStrength || maxStrength <= 0) {
            alert('Please enter a valid maximum strength.');
            return;
        }

        var $btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving…');

        var data = { class_name: CLASS_NAME, section_name: sectionName, max_strength: maxStrength };
        data[CSRF_NAME] = CSRF_HASH;

        $.ajax({
            url: BASE + 'classes/add_section',
            type: 'POST',
            dataType: 'json',
            data: data,
            success: function (res) {
                if (res.status === 'success') {
                    $('#addSectionModal').modal('hide');
                    /* Append new card to LEFT-aligned grid naturally */
                    var newSec = { name: res.section, strength: 0, max_strength: res.max_strength, students: [] };
                    var $card = $(buildCard(newSec)).hide();
                    $('#sectionContainer').append($card).show();
                    $('#emptyState').hide();
                    $card.fadeIn(280);
                    /* Update stats */
                    var cur = parseInt($('#statSections').text(), 10) || 0;
                    $('#statSections').text(cur + 1);
                    toast('Section ' + res.section + ' created.');
                } else {
                    alert(res.message || 'Failed to add section.');
                }
            },
            error: function () { alert('Server error. Please try again.'); },
            complete: function () {
                $btn.prop('disabled', false).html('<i class="fa fa-save"></i> Save Section');
            }
        });
    });

    /* ── Navigate to section students ─────────────────────────────── */
    $('#sectionContainer').on('click', '.cp-sec-card', function () {
        var sectionName = $(this).data('section');
        if (!sectionName) return;
        var classSlug   = CLASS_NAME.replace(/^Class\s+/i, '').trim();
        var sectionSlug = sectionName.replace(/^Section\s+/i, '').trim();
        window.location.href = BASE + 'classes/section_students/' + encodeURIComponent(classSlug) + '/' + encodeURIComponent(sectionSlug);
    });
</script>


<style>
/* ─── Reuse manage_classes design tokens ─── */
.mc-header {
  background: linear-gradient(135deg, var(--gold) 0%, #0d6b63 100%);
  border-radius: 14px; padding: 22px 28px; margin-bottom: 24px;
}
.mc-header-inner { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; }
.mc-header-left  { display: flex; align-items: center; gap: 14px; }
.mc-header-icon  { width: 44px; height: 44px; background: rgba(255,255,255,.18); border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.mc-title   { font: 700 22px/1.2 var(--font-b); color: #fff; margin: 0; }
.mc-subtitle{ font: 400 13px/1.4 var(--font-b); color: rgba(255,255,255,.78); margin: 4px 0 0; }

.mc-back-btn {
  display: inline-flex; align-items: center; justify-content: center;
  width: 36px; height: 36px; border-radius: 10px;
  background: rgba(255,255,255,.20); color: #fff;
  transition: background var(--ease); flex-shrink: 0;
}
.mc-back-btn:hover { background: rgba(255,255,255,.35); color: #fff; }

.mc-btn { display: inline-flex; align-items: center; gap: 7px; font: 600 13px/1 var(--font-b); border: none; border-radius: 10px; padding: 10px 16px; cursor: pointer; transition: all var(--ease); white-space: nowrap; }
.mc-btn-add { background: #fff; color: #0d6b63; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
.mc-btn-add:hover { background: linear-gradient(135deg, var(--gold) 0%, #0d6b63 100%); color: #fff; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(15,118,110,.40); }

/* Stats */
.mc-stats { display: flex; gap: 16px; margin-bottom: 28px; flex-wrap: wrap; }
.mc-stat-card { flex: 1 1 180px; background: var(--bg2); border: 1px solid var(--border); border-radius: 10px; padding: 16px 20px; display: flex; align-items: center; gap: 14px; box-shadow: var(--sh); }
.mc-stat-icon { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.mc-stat-icon--sections { background: var(--gold-dim); color: var(--gold); }
.mc-stat-icon--students  { background: #E0F2FE; color: #0284C7; }
.mc-stat-value { display: block; font: 700 22px/1.2 var(--font-b); color: var(--t1); }
.mc-stat-label { font: 400 12.5px/1.4 var(--font-b); color: var(--t3); }

/* Loader */
.mc-loader { display: flex; flex-direction: column; align-items: center; padding: 60px 20px; color: var(--t3); font: 400 14px/1.4 var(--font-b); gap: 14px; }
.mc-spinner { width: 32px; height: 32px; border: 3px solid var(--border); border-top-color: var(--gold); border-radius: 50%; animation: mcSpin .7s linear infinite; }
@keyframes mcSpin { to { transform: rotate(360deg); } }

/* Grid — auto-fill keeps tracks fixed → items always left-aligned */
.mc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 22px; }

/* Cards */
.mc-card { background: var(--bg2); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; transition: all var(--ease); cursor: pointer; }
.mc-card:hover { border-color: var(--gold); box-shadow: var(--sh); transform: translateY(-3px); }
.mc-card-top { background: linear-gradient(135deg, var(--gold) 0%, #14b8a6 100%); padding: 20px 18px 16px; position: relative; overflow: hidden; }
.mc-card-top::after { content: ''; position: absolute; right: -18px; top: -18px; width: 70px; height: 70px; background: rgba(255,255,255,.10); border-radius: 50%; }
.mc-card-class { font: 700 18px/1.2 var(--font-b); color: #fff; margin: 0; }
.mc-card-sections { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 5px; }
.mc-section-badge { display: inline-block; background: rgba(255,255,255,.22); color: #fff; font: 600 11px/1 var(--font-b); padding: 3px 9px; border-radius: 6px; }
.mc-card-body { padding: 14px 16px 16px; }
.mc-card-meta { font: 400 12px/1.6 var(--font-b); color: var(--t3); display: block; margin-top: 8px; }
.mc-card-meta strong { color: var(--t1); font-weight: 600; }

/* Student preview in card */
.cp-stu-list { margin-bottom: 10px; max-height: 250px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: var(--border) transparent; }
.cp-stu-list::-webkit-scrollbar { width: 4px; }
.cp-stu-list::-webkit-scrollbar-track { background: transparent; }
.cp-stu-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
.cp-stu-row { font: 400 12.5px/1 var(--font-b); color: var(--t2); padding: 6px 0; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 7px; }
.cp-stu-icon { font-size: 11px; color: var(--t3); flex-shrink: 0; }
.cp-stu-more { font: 600 12px/1 var(--font-b); color: var(--gold); padding: 6px 0; }
.cp-stu-empty { font: 400 12.5px/1.6 var(--font-b); color: var(--t3); text-align: center; padding: 12px 0; }

/* Capacity bar */
.cp-cap-bar { height: 4px; background: var(--border); border-radius: 4px; overflow: hidden; }
.cp-cap-fill { height: 100%; background: linear-gradient(90deg, var(--gold), #0d6b63); border-radius: 4px; transition: width .4s ease; }

/* Empty state */
.mc-empty { text-align: center; padding: 60px 20px; color: var(--t3); }
.mc-empty h3 { font: 600 18px/1.3 var(--font-b); color: var(--t1); margin: 16px 0 6px; }
.mc-empty p  { font: 400 14px/1.5 var(--font-b); }

/* Toast */
.mc-toast { position: fixed; bottom: 32px; left: 50%; transform: translateX(-50%) translateY(80px); background: var(--bg3); color: var(--t1); font: 500 14px/1.4 var(--font-b); padding: 12px 28px; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,.2); z-index: 99999; opacity: 0; transition: all .3s ease; pointer-events: none; }
.mc-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

/* Modal */
.cp-modal { background: var(--bg2); border: 1px solid var(--border); border-radius: 14px; box-shadow: var(--sh); }
.cp-modal .modal-header { border-bottom: 1px solid var(--border); padding: 16px 20px; }
.cp-modal .modal-title { color: var(--t1); font: 700 16px/1 var(--font-b); }
.cp-modal .close { color: var(--t3); opacity: .7; }
.cp-modal .close:hover { opacity: 1; }
.cp-modal .modal-footer { border-top: 1px solid var(--border); background: var(--bg2); border-radius: 0 0 14px 14px; }
.cp-label { font: 600 13px/1 var(--font-b); color: var(--t2); display: block; margin-bottom: 6px; }
.cp-input { background: var(--bg) !important; border: 1px solid var(--border) !important; color: var(--t1) !important; border-radius: 8px !important; height: 42px; font-family: var(--font-b) !important; }
.cp-input:focus { border-color: var(--gold) !important; box-shadow: 0 0 0 3px rgba(15,118,110,.18) !important; }
.cp-btn-save { background: linear-gradient(135deg, var(--gold) 0%, #0d6b63 100%); color: #fff; border: none; border-radius: 8px; padding: 8px 20px; font: 600 13px/1 var(--font-b); transition: opacity var(--ease); }
.cp-btn-save:hover { opacity: .9; color: #fff; }

@media (max-width: 600px) {
  .mc-header { padding: 16px; }
  .mc-title  { font-size: 18px; }
  .mc-grid   { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 14px; }
}
</style>
