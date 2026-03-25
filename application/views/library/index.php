<?php
$at = isset($active_tab) ? $active_tab : 'catalog';
$tab_map = [
    'catalog'    => ['panel'=>'panelCatalog',   'icon'=>'fa-book',          'label'=>'Catalog'],
    'categories' => ['panel'=>'panelCats',      'icon'=>'fa-tags',          'label'=>'Categories'],
    'issues'     => ['panel'=>'panelIssues',    'icon'=>'fa-exchange',      'label'=>'Issue / Return'],
    'fines'      => ['panel'=>'panelFines',     'icon'=>'fa-rupee',         'label'=>'Fines'],
    'reports'    => ['panel'=>'panelReports',   'icon'=>'fa-bar-chart',     'label'=>'Reports'],
];
?>

<style>
.lib-wrap{padding:20px;max-width:1400px;margin:0 auto}
.lib-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.lib-header-icon{font-family:var(--font-b);font-size:1.3rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.lib-header-icon i{color:var(--gold);font-size:1.1rem}
.lib-breadcrumb{list-style:none;display:flex;gap:6px;font-size:12px;color:var(--t3);padding:0;margin:6px 0 0;font-family:var(--font-b)}
.lib-breadcrumb a{color:var(--gold);text-decoration:none}
.lib-breadcrumb li+li::before{content:"›";margin-right:6px;color:var(--t4)}
.lib-tabs{display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--border);padding-bottom:0;overflow-x:auto}
.lib-tab{padding:10px 16px;font-size:13px;font-weight:600;color:var(--t3);text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;transition:all var(--ease);font-family:var(--font-b)}
.lib-tab:hover{color:var(--t1)}
.lib-tab.active{color:var(--gold);border-bottom-color:var(--gold)}
.lib-tab i{margin-right:6px;font-size:14px}
.lib-panel{display:none}
.lib-panel.active{display:block}
.lib-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:20px;margin-bottom:18px}
.lib-card-title{font-family:var(--font-b);font-size:14px;font-weight:700;color:var(--t1);margin-bottom:14px;display:flex;align-items:center;justify-content:space-between}
.lib-btn{padding:8px 16px;border-radius:var(--r-sm);font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all var(--ease);font-family:var(--font-b)}
.lib-btn-primary{background:var(--gold);color:#fff}
.lib-btn-primary:hover{background:var(--gold2)}
.lib-btn-danger{background:var(--rose);color:#fff}
.lib-btn-sm{padding:6px 12px;font-size:12px}
.lib-table{width:100%;border-collapse:collapse;font-size:13px;font-family:var(--font-b)}
.lib-table th,.lib-table td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--border)}
.lib-table th{color:var(--t3);font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
.lib-table td{color:var(--t1)}
.lib-table tr:hover td{background:var(--gold-dim)}
.lib-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;font-family:var(--font-b)}
.lib-badge-green{background:rgba(34,197,94,.12);color:#22c55e}
.lib-badge-amber{background:rgba(234,179,8,.12);color:#eab308}
.lib-badge-red{background:rgba(239,68,68,.12);color:#ef4444}
.lib-badge-blue{background:rgba(59,130,246,.12);color:#3b82f6}
.lib-form-group{margin-bottom:14px}
.lib-form-group label{display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:4px;font-family:var(--font-b);text-transform:uppercase;letter-spacing:.3px}
.lib-form-group input,.lib-form-group select,.lib-form-group textarea{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1);font-size:13px;font-family:var(--font-b)}
.lib-form-group input:focus,.lib-form-group select:focus{border-color:var(--gold);outline:none}
.lib-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.lib-modal-bg{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);z-index:9999;display:none;align-items:center;justify-content:center}
.lib-modal-bg.show{display:flex}
.lib-modal{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);width:560px;max-width:95vw;max-height:85vh;overflow-y:auto;padding:24px}
.lib-modal-title{font-family:var(--font-b);font-size:16px;font-weight:700;color:var(--t1);margin-bottom:18px;display:flex;align-items:center;justify-content:space-between}
.lib-modal-close{cursor:pointer;color:var(--t3);font-size:1.2rem;background:none;border:none}
.lib-stats-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:14px;margin-bottom:20px}
.lib-stat-card{background:var(--bg3);border-radius:var(--r-sm);padding:14px;text-align:center}
.lib-stat-val{font-family:var(--font-m);font-size:1.6rem;font-weight:700;color:var(--gold)}
.lib-stat-lbl{font-size:12px;color:var(--t3);margin-top:2px;font-family:var(--font-b)}
.lib-search-box{position:relative}
.lib-search-results{position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:0 0 var(--r-sm) var(--r-sm);max-height:200px;overflow-y:auto;z-index:100;display:none}
.lib-search-results.show{display:block}
.lib-search-item{padding:8px 12px;cursor:pointer;font-size:13px;color:var(--t1);border-bottom:1px solid var(--border);font-family:var(--font-b)}
.lib-search-item:hover{background:var(--gold-dim)}
.lib-search-item small{color:var(--t3)}
</style>

<div class="content-wrapper">
<section class="content">
<div class="lib-wrap">

  <div class="lib-header">
    <div>
      <div class="lib-header-icon"><i class="fa fa-book"></i> Library Management</div>
      <ol class="lib-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li><a href="<?= base_url('operations') ?>">Operations</a></li>
        <li>Library</li>
      </ol>
    </div>
  </div>

  <nav class="lib-tabs">
    <?php foreach ($tab_map as $slug => $t): ?>
    <a class="lib-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url('library/' . $slug) ?>">
      <i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <!-- ════════ CATALOG ════════ -->
  <div class="lib-panel<?= $at === 'catalog' ? ' active' : '' ?>" id="panelCatalog">
    <div class="lib-card">
      <div class="lib-card-title">
        <span>Book Catalog</span>
        <button class="lib-btn lib-btn-primary" onclick="LIB.openBookModal()"><i class="fa fa-plus"></i> Add Book</button>
      </div>
      <table class="lib-table" id="booksTable">
        <thead><tr><th>ID</th><th>Title</th><th>Author</th><th>ISBN</th><th>Category</th><th>Copies</th><th>Available</th><th>Actions</th></tr></thead>
        <tbody id="booksTbody"></tbody>
      </table>
    </div>
  </div>

  <!-- ════════ CATEGORIES ════════ -->
  <div class="lib-panel<?= $at === 'categories' ? ' active' : '' ?>" id="panelCats">
    <div class="lib-card">
      <div class="lib-card-title">
        <span>Book Categories</span>
        <button class="lib-btn lib-btn-primary" onclick="LIB.openCatModal()"><i class="fa fa-plus"></i> Add Category</button>
      </div>
      <table class="lib-table">
        <thead><tr><th>ID</th><th>Name</th><th>Description</th><th>Actions</th></tr></thead>
        <tbody id="catsTbody"></tbody>
      </table>
    </div>
  </div>

  <!-- ════════ ISSUE / RETURN ════════ -->
  <div class="lib-panel<?= $at === 'issues' ? ' active' : '' ?>" id="panelIssues">
    <div class="lib-card">
      <div class="lib-card-title">
        <span>Issue a Book</span>
      </div>
      <div class="lib-form-row">
        <div class="lib-form-group lib-search-box">
          <label>Student</label>
          <input type="text" id="issStudentSearch" placeholder="Search by name or ID..." autocomplete="off">
          <input type="hidden" id="issStudentId">
          <div class="lib-search-results" id="issStudentResults"></div>
        </div>
        <div class="lib-form-group">
          <label>Book</label>
          <select id="issBookSelect"><option value="">-- Select Book --</option></select>
        </div>
      </div>
      <div class="lib-form-row">
        <div class="lib-form-group">
          <label>Due Date</label>
          <input type="date" id="issDueDate">
        </div>
        <div class="lib-form-group" style="display:flex;align-items:flex-end">
          <button class="lib-btn lib-btn-primary" onclick="LIB.issueBook()"><i class="fa fa-arrow-right"></i> Issue Book</button>
        </div>
      </div>
    </div>

    <div class="lib-card">
      <div class="lib-card-title"><span>Current Issues</span></div>
      <table class="lib-table">
        <thead><tr><th>ID</th><th>Book</th><th>Student</th><th>Issued</th><th>Due</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody id="issuesTbody"></tbody>
      </table>
    </div>
  </div>

  <!-- ════════ FINES ════════ -->
  <div class="lib-panel<?= $at === 'fines' ? ' active' : '' ?>" id="panelFines">
    <div class="lib-card">
      <div class="lib-card-title"><span>Library Fines</span></div>
      <table class="lib-table">
        <thead><tr><th>ID</th><th>Student</th><th>Book</th><th>Late Days</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody id="finesTbody"></tbody>
      </table>
    </div>
  </div>

  <!-- ════════ REPORTS ════════ -->
  <div class="lib-panel<?= $at === 'reports' ? ' active' : '' ?>" id="panelReports">
    <div class="lib-stats-grid" id="reportStats"></div>
    <div class="lib-card">
      <div class="lib-card-title"><span>Library Summary</span></div>
      <div id="reportContent"></div>
    </div>
  </div>

</div>
</section>
</div>

<!-- ════════ BOOK MODAL ════════ -->
<div class="lib-modal-bg" id="bookModal">
  <div class="lib-modal">
    <div class="lib-modal-title"><span id="bookModalTitle">Add Book</span><button class="lib-modal-close" onclick="LIB.closeBookModal()">&times;</button></div>
    <input type="hidden" id="bkId">
    <div class="lib-form-row">
      <div class="lib-form-group"><label>Title *</label><input type="text" id="bkTitle"></div>
      <div class="lib-form-group"><label>Author</label><input type="text" id="bkAuthor"></div>
    </div>
    <div class="lib-form-row">
      <div class="lib-form-group"><label>ISBN</label><input type="text" id="bkIsbn"></div>
      <div class="lib-form-group"><label>Category</label><select id="bkCatId"><option value="">-- None --</option></select></div>
    </div>
    <div class="lib-form-row">
      <div class="lib-form-group"><label>Publisher</label><input type="text" id="bkPublisher"></div>
      <div class="lib-form-group"><label>Edition</label><input type="text" id="bkEdition"></div>
    </div>
    <div class="lib-form-row">
      <div class="lib-form-group"><label>Total Copies</label><input type="number" id="bkCopies" min="1" value="1"></div>
      <div class="lib-form-group"><label>Shelf Location</label><input type="text" id="bkShelf"></div>
    </div>
    <div class="lib-form-group"><label>Description</label><textarea id="bkDesc" rows="2"></textarea></div>
    <button class="lib-btn lib-btn-primary" onclick="LIB.saveBook()" style="width:100%;margin-top:8px">Save Book</button>
  </div>
</div>

<!-- ════════ CATEGORY MODAL ════════ -->
<div class="lib-modal-bg" id="catModal">
  <div class="lib-modal" style="width:420px">
    <div class="lib-modal-title"><span id="catModalTitle">Add Category</span><button class="lib-modal-close" onclick="LIB.closeCatModal()">&times;</button></div>
    <input type="hidden" id="catId">
    <div class="lib-form-group"><label>Name *</label><input type="text" id="catName"></div>
    <div class="lib-form-group"><label>Description</label><textarea id="catDesc" rows="2"></textarea></div>
    <button class="lib-btn lib-btn-primary" onclick="LIB.saveCat()" style="width:100%;margin-top:8px">Save Category</button>
  </div>
</div>

<script>
var _LIB_CFG = {
  BASE:      '<?= base_url() ?>',
  CSRF_NAME: '<?= $this->security->get_csrf_token_name() ?>',
  CSRF_HASH: '<?= $this->security->get_csrf_hash() ?>',
  activeTab: '<?= $at ?>'
};

document.addEventListener('DOMContentLoaded', function(){
(function(){
  'use strict';
  var BASE      = _LIB_CFG.BASE;
  var CSRF_NAME = _LIB_CFG.CSRF_NAME;
  var CSRF_HASH = _LIB_CFG.CSRF_HASH;
  var activeTab = _LIB_CFG.activeTab;
  var categories = [], books = [];

  function getJSON(url){ return $.getJSON(BASE + url); }
  function post(url, data){
    data[CSRF_NAME] = CSRF_HASH;
    return $.post(BASE + url, data);
  }
  function toast(msg, ok){
    var t = $('<div style="position:fixed;top:20px;right:20px;z-index:99999;padding:12px 20px;border-radius:8px;font-size:.85rem;color:#fff;background:'+(ok?'var(--green)':'var(--rose)')+'">'+msg+'</div>');
    $('body').append(t);
    setTimeout(function(){ t.fadeOut(300, function(){ t.remove(); }); }, 3000);
  }
  function escH(s){ return $('<span>').text(s||'').html(); }
  function catName(id){
    for(var i=0;i<categories.length;i++) if(categories[i].id===id) return categories[i].name;
    return id||'—';
  }

  // ── Categories ───────────────────────────────────────────────────
  function loadCategories(){
    getJSON('library/get_categories').done(function(r){
      if(r.status!=='success') return;
      categories = r.categories||[];
      var html = '';
      categories.forEach(function(c){
        html += '<tr><td>'+escH(c.id)+'</td><td>'+escH(c.name)+'</td><td>'+escH(c.description)+'</td>';
        html += '<td><button class="lib-btn lib-btn-sm lib-btn-primary" onclick="LIB.editCat(\''+c.id+'\')"><i class="fa fa-pencil"></i></button> ';
        html += '<button class="lib-btn lib-btn-sm lib-btn-danger" onclick="LIB.deleteCat(\''+c.id+'\')"><i class="fa fa-trash"></i></button></td></tr>';
      });
      $('#catsTbody').html(html||'<tr><td colspan="4" style="text-align:center;color:var(--t3)">No categories</td></tr>');
      // Update selects
      var opts = '<option value="">-- None --</option>';
      categories.forEach(function(c){ opts += '<option value="'+c.id+'">'+escH(c.name)+'</option>'; });
      $('#bkCatId').html(opts);
    });
  }

  window.LIB = {};
  LIB.openCatModal = function(){ $('#catId').val(''); $('#catName').val(''); $('#catDesc').val(''); $('#catModalTitle').text('Add Category'); $('#catModal').addClass('show'); };
  LIB.closeCatModal = function(){ $('#catModal').removeClass('show'); };
  LIB.editCat = function(id){
    var c = categories.find(function(x){ return x.id===id; });
    if(!c) return;
    $('#catId').val(c.id); $('#catName').val(c.name); $('#catDesc').val(c.description);
    $('#catModalTitle').text('Edit Category'); $('#catModal').addClass('show');
  };
  LIB.saveCat = function(){
    post('library/save_category', { id:$('#catId').val(), name:$('#catName').val(), description:$('#catDesc').val() }).done(function(r){
      r = typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success'){ toast(r.message,true); LIB.closeCatModal(); loadCategories(); }
      else toast(r.message,false);
    });
  };
  LIB.deleteCat = function(id){
    if(!confirm('Delete this category?')) return;
    post('library/delete_category', {id:id}).done(function(r){
      r = typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success'){ toast(r.message,true); loadCategories(); } else toast(r.message,false);
    });
  };

  // ── Books (Catalog) ──────────────────────────────────────────────
  function loadBooks(){
    getJSON('library/get_books').done(function(r){
      if(r.status!=='success') return;
      books = r.books||[];
      var html = '';
      books.forEach(function(b){
        var avail = (b.available||0)>0 ? '<span class="lib-badge lib-badge-green">'+b.available+'</span>' : '<span class="lib-badge lib-badge-red">0</span>';
        html += '<tr><td>'+escH(b.id)+'</td><td>'+escH(b.title)+'</td><td>'+escH(b.author)+'</td><td>'+escH(b.isbn)+'</td>';
        html += '<td>'+escH(catName(b.category_id))+'</td><td>'+b.copies+'</td><td>'+avail+'</td>';
        html += '<td><button class="lib-btn lib-btn-sm lib-btn-primary" onclick="LIB.editBook(\''+b.id+'\')"><i class="fa fa-pencil"></i></button> ';
        html += '<button class="lib-btn lib-btn-sm lib-btn-danger" onclick="LIB.deleteBook(\''+b.id+'\')"><i class="fa fa-trash"></i></button></td></tr>';
      });
      $('#booksTbody').html(html||'<tr><td colspan="8" style="text-align:center;color:var(--t3)">No books</td></tr>');
      // Populate issue book select
      var opts = '<option value="">-- Select Book --</option>';
      books.forEach(function(b){
        if((b.available||0)>0) opts += '<option value="'+b.id+'">'+escH(b.title)+' ('+b.available+' avail)</option>';
      });
      $('#issBookSelect').html(opts);
    });
  }

  LIB.openBookModal = function(){ $('#bkId,#bkTitle,#bkAuthor,#bkIsbn,#bkPublisher,#bkEdition,#bkShelf,#bkDesc').val(''); $('#bkCopies').val(1); $('#bkCatId').val(''); $('#bookModalTitle').text('Add Book'); $('#bookModal').addClass('show'); };
  LIB.closeBookModal = function(){ $('#bookModal').removeClass('show'); };
  LIB.editBook = function(id){
    var b = books.find(function(x){ return x.id===id; });
    if(!b) return;
    $('#bkId').val(b.id); $('#bkTitle').val(b.title); $('#bkAuthor').val(b.author); $('#bkIsbn').val(b.isbn);
    $('#bkCatId').val(b.category_id); $('#bkPublisher').val(b.publisher); $('#bkEdition').val(b.edition);
    $('#bkCopies').val(b.copies); $('#bkShelf').val(b.shelf_location); $('#bkDesc').val(b.description);
    $('#bookModalTitle').text('Edit Book'); $('#bookModal').addClass('show');
  };
  LIB.saveBook = function(){
    post('library/save_book', {
      id:$('#bkId').val(), title:$('#bkTitle').val(), author:$('#bkAuthor').val(), isbn:$('#bkIsbn').val(),
      category_id:$('#bkCatId').val(), publisher:$('#bkPublisher').val(), edition:$('#bkEdition').val(),
      copies:$('#bkCopies').val(), shelf_location:$('#bkShelf').val(), description:$('#bkDesc').val()
    }).done(function(r){
      r = typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success'){ toast(r.message,true); LIB.closeBookModal(); loadBooks(); } else toast(r.message,false);
    });
  };
  LIB.deleteBook = function(id){
    if(!confirm('Delete this book?')) return;
    post('library/delete_book', {id:id}).done(function(r){
      r = typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success'){ toast(r.message,true); loadBooks(); } else toast(r.message,false);
    });
  };

  // ── Student Search (for Issue) ────────────────────────────────────
  var searchTimer;
  $('#issStudentSearch').on('input', function(){
    clearTimeout(searchTimer);
    var q = $(this).val();
    if(q.length < 2){ $('#issStudentResults').removeClass('show'); return; }
    searchTimer = setTimeout(function(){
      getJSON('library/search_students?q='+encodeURIComponent(q)).done(function(r){
        if(r.status!=='success'||!r.students.length){ $('#issStudentResults').html('<div class="lib-search-item" style="color:var(--t3);cursor:default">No students found</div>').addClass('show'); return; }
        var html = '';
        r.students.forEach(function(s){
          html += '<div class="lib-search-item" data-id="'+s.id+'" data-name="'+escH(s.name)+'">'+escH(s.name)+' <small style="opacity:.6">'+escH(s.id)+'</small> <small>'+escH(s.class)+' '+escH(s.section||'')+'</small></div>';
        });
        $('#issStudentResults').html(html).addClass('show');
      }).fail(function(xhr){ $('#issStudentResults').html('<div class="lib-search-item" style="color:var(--rose);cursor:default">Search failed: '+(xhr.responseJSON?xhr.responseJSON.message:xhr.statusText)+'</div>').addClass('show'); });
    }, 300);
  });
  $(document).on('click', '#issStudentResults .lib-search-item', function(){
    $('#issStudentId').val($(this).data('id'));
    $('#issStudentSearch').val($(this).data('name'));
    $('#issStudentResults').removeClass('show');
  });
  $(document).on('click', function(e){ if(!$(e.target).closest('.lib-search-box').length) $('#issStudentResults').removeClass('show'); });

  // ── Issue / Return ────────────────────────────────────────────────
  function loadIssues(){
    getJSON('library/get_issues').done(function(r){
      if(r.status!=='success') return;
      var html = '';
      var today = new Date().toISOString().slice(0,10);
      (r.issues||[]).forEach(function(iss){
        var statusBadge = iss.status==='Issued'
          ? ((iss.due_date||'') < today ? '<span class="lib-badge lib-badge-red">Overdue</span>' : '<span class="lib-badge lib-badge-blue">Issued</span>')
          : '<span class="lib-badge lib-badge-green">Returned</span>';
        var actions = iss.status==='Issued'
          ? '<button class="lib-btn lib-btn-sm lib-btn-primary" onclick="LIB.returnBook(\''+iss.id+'\')"><i class="fa fa-undo"></i> Return</button>'
          : '—';
        html += '<tr><td>'+escH(iss.id)+'</td><td>'+escH(iss.book_title)+'</td><td>'+escH(iss.student_name)+'</td>';
        html += '<td>'+escH(iss.issue_date)+'</td><td>'+escH(iss.due_date)+'</td><td>'+statusBadge+'</td><td>'+actions+'</td></tr>';
      });
      $('#issuesTbody').html(html||'<tr><td colspan="7" style="text-align:center;color:var(--t3)">No issues</td></tr>');
    });
  }

  LIB.issueBook = function(){
    var studentId = $('#issStudentId').val(), bookId = $('#issBookSelect').val(), dueDate = $('#issDueDate').val();
    if(!studentId){ toast('Select a student',false); return; }
    if(!bookId){ toast('Select a book',false); return; }
    if(!dueDate){ toast('Set a due date',false); return; }
    post('library/issue_book', { student_id:studentId, book_id:bookId, due_date:dueDate }).done(function(r){
      r = typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success'){
        toast(r.message,true); loadIssues(); loadBooks();
        $('#issStudentSearch,#issStudentId').val(''); $('#issBookSelect').val('');
      } else toast(r.message,false);
    });
  };

  LIB.returnBook = function(issueId){
    var finePerDay = prompt('Fine per day for late return (Rs):', '2');
    if(finePerDay === null) return;
    post('library/return_book', { issue_id:issueId, fine_per_day:finePerDay }).done(function(r){
      r = typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success'){ toast(r.message,true); loadIssues(); loadBooks(); loadFines(); } else toast(r.message,false);
    });
  };

  // ── Fines ─────────────────────────────────────────────────────────
  function loadFines(){
    getJSON('library/get_fines').done(function(r){
      if(r.status!=='success') return;
      var html = '';
      (r.fines||[]).forEach(function(f){
        var statusBadge = f.status==='Paid' ? '<span class="lib-badge lib-badge-green">Paid</span>' : '<span class="lib-badge lib-badge-amber">Pending</span>';
        var actions = f.status!=='Paid'
          ? '<button class="lib-btn lib-btn-sm lib-btn-primary" onclick="LIB.payFine(\''+f.id+'\')"><i class="fa fa-check"></i> Pay</button>'
          : '—';
        html += '<tr><td>'+escH(f.id)+'</td><td>'+escH(f.student_name)+'</td><td>'+escH(f.book_title)+'</td>';
        html += '<td>'+f.late_days+'</td><td>Rs '+f.amount+'</td><td>'+statusBadge+'</td><td>'+actions+'</td></tr>';
      });
      $('#finesTbody').html(html||'<tr><td colspan="7" style="text-align:center;color:var(--t3)">No fines</td></tr>');
    });
  }

  LIB.payFine = function(fineId){
    if(!confirm('Mark this fine as paid?')) return;
    post('library/pay_fine', { fine_id:fineId, payment_mode:'Cash' }).done(function(r){
      r = typeof r==='string'?JSON.parse(r):r;
      if(r.status==='success'){ toast(r.message,true); loadFines(); } else toast(r.message,false);
    });
  };

  // ── Reports ───────────────────────────────────────────────────────
  function loadReports(){
    getJSON('library/get_reports').done(function(r){
      if(r.status!=='success') return;
      var rpt = r.report;
      var stats = [
        {label:'Total Titles', val:rpt.total_titles, color:'var(--gold)'},
        {label:'Total Copies', val:rpt.total_copies, color:'var(--blue)'},
        {label:'Available', val:rpt.available_copies, color:'#22c55e'},
        {label:'Issued', val:rpt.currently_issued, color:'#3b82f6'},
        {label:'Overdue', val:rpt.overdue, color:'#ef4444'},
        {label:'Total Fines', val:'Rs '+rpt.total_fines, color:'var(--amber)'},
        {label:'Pending Fines', val:'Rs '+rpt.pending_fines, color:'#ef4444'},
        {label:'Collected', val:'Rs '+rpt.paid_fines, color:'#22c55e'},
      ];
      var html = '';
      stats.forEach(function(s){
        html += '<div class="lib-stat-card"><div class="lib-stat-val" style="color:'+s.color+'">'+s.val+'</div><div class="lib-stat-lbl">'+s.label+'</div></div>';
      });
      $('#reportStats').html(html);
    });
  }

  // ── Init ──────────────────────────────────────────────────────────
  function init(){
    // Set default due date to 14 days from today
    var d = new Date(); d.setDate(d.getDate()+14);
    $('#issDueDate').val(d.toISOString().slice(0,10));

    loadCategories();
    loadBooks();
    if(activeTab==='issues') loadIssues();
    if(activeTab==='fines') loadFines();
    if(activeTab==='reports') loadReports();
    // Lazy-load other tabs on click
    $('.lib-tab').on('click', function(){
      var href = $(this).attr('href');
      if(href.indexOf('/issues')>-1) loadIssues();
      if(href.indexOf('/fines')>-1) loadFines();
      if(href.indexOf('/reports')>-1) loadReports();
    });
  }
  init();
})();
});
</script>
