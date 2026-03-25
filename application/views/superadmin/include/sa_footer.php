
</div><!-- ./content-wrapper -->

<footer class="main-footer" style="text-align:center;font-family:var(--font-m);font-size:11.5px;color:var(--t3);padding:12px 24px !important;">
    <strong style="color:var(--t2);">GraderIQ Super Admin</strong>
    <span style="margin:0 8px;color:var(--border);">·</span>
    <span style="color:var(--sa3);">Restricted Access</span>
    <span style="margin:0 8px;color:var(--border);">·</span>
    v2.0 SA
    <span style="float:right;color:var(--t4);">Server: <?= date('Y-m-d H:i:s') ?></span>
</footer>

<aside class="control-sidebar control-sidebar-dark" style="display:none;"></aside>
<div class="control-sidebar-bg"></div>
</div><!-- ./wrapper -->

<!-- jQuery already loaded in sa_header.php — only load jquery-ui here -->
<script src="<?= base_url() ?>tools/bower_components/jquery-ui/jquery-ui.min.js"></script>
<script>$.widget.bridge('uibutton',$.ui.button);</script>
<script src="<?= base_url() ?>tools/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script src="<?= base_url() ?>tools/bower_components/raphael/raphael.min.js"></script>
<script src="<?= base_url() ?>tools/bower_components/morris.js/morris.min.js"></script>
<script src="<?= base_url() ?>tools/bower_components/moment/min/moment.min.js"></script>
<script src="<?= base_url() ?>tools/bower_components/jquery-slimscroll/jquery.slimscroll.min.js"></script>
<script src="<?= base_url() ?>tools/bower_components/fastclick/lib/fastclick.js"></script>
<script src="<?= base_url() ?>tools/dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.js"></script>
<script src="https://cdn.datatables.net/buttons/3.0.2/js/dataTables.buttons.js"></script>
<script src="https://cdn.datatables.net/buttons/3.0.2/js/buttons.dataTables.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/3.0.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.0.2/js/buttons.print.min.js"></script>

<script>
/* ── Debug mode flag (set in PHP constants.php) ── */
window.GRADER_DEBUG_MODE = <?= defined('GRADER_DEBUG') && GRADER_DEBUG ? 'true' : 'false' ?>;

/* ── Theme toggle (needs jQuery — loaded just above) ── */
(function(){
    function applyTheme(t){
        document.documentElement.setAttribute('data-theme', t);
        document.body.setAttribute('data-theme', t);
        var icon  = document.getElementById('saThemeIcon');
        var label = document.getElementById('saThemeLabel');
        if(icon)  icon.className  = t === 'day' ? 'fa fa-sun-o' : 'fa fa-moon-o';
        if(label) label.textContent = t === 'day' ? 'Day' : 'Night';
        localStorage.setItem('sa_theme', t);
    }
    var saved = localStorage.getItem('sa_theme') || 'night';
    applyTheme(saved);

    var btn = document.getElementById('saThemeToggle');
    if(btn) btn.addEventListener('click', function(){
        var cur = document.documentElement.getAttribute('data-theme');
        applyTheme(cur === 'night' ? 'day' : 'night');
    });

    /* Also set up jQuery AJAX CSRF injection now that jQuery is loaded.
       Token is read FRESH on every request (not cached at page load) so it
       stays valid even after CI3 rotates the token on each POST response. */
    if(typeof $ !== 'undefined'){
        $.ajaxSetup({
            beforeSend: function(xhr, settings){
                if(settings.type === 'POST' || settings.type === 'post'){
                    /* Read fresh from meta tag on every request so the token
                       stays valid across multiple AJAX calls in the same page. */
                    var csrfTokenEl = document.querySelector('meta[name="csrf-token"]');
                    var csrfNameEl  = document.querySelector('meta[name="csrf-name"]');
                    if(!csrfTokenEl || !csrfNameEl) return;
                    var token = csrfTokenEl.getAttribute('content');
                    var name  = csrfNameEl.getAttribute('content');

                    if(settings.data instanceof FormData){
                        /* Multipart uploads — append as a form field */
                        settings.data.append(name, token);
                    } else if(typeof settings.data === 'string'){
                        /* Already-serialized query string — append &name=token */
                        settings.data += (settings.data ? '&' : '') +
                            encodeURIComponent(name) + '=' + encodeURIComponent(token);
                    } else if(settings.data && typeof settings.data === 'object'){
                        /* Plain object — add property directly */
                        settings.data[name] = token;
                    } else {
                        /* No data provided at all (undefined / null) — this is the
                           case for all "fetch" endpoints that send no body.
                           CI3's csrf_protection=TRUE reads $_POST, not headers,
                           so we MUST initialise the body with the token here. */
                        settings.data = encodeURIComponent(name) + '=' + encodeURIComponent(token);
                    }

                    /* Also send as a header for defence-in-depth
                       (used by _verify_csrf() fallback when csrf_protection=FALSE) */
                    xhr.setRequestHeader('X-CSRF-Token', token);
                }
            }
        });
    }
})();

/* ── Global AJAX error logger (active only in debug mode) ── */
if(window.GRADER_DEBUG_MODE && typeof $ !== 'undefined'){
    $(document).ajaxError(function(event, xhr, settings, thrownError){
        // Skip the debug endpoint itself to avoid loops
        if((settings.url||'').indexOf('superadmin/debug/log_ajax_error') !== -1) return;
        try {
            var csrfNameEl = document.querySelector('meta[name="csrf-name"]');
            var csrfTokEl  = document.querySelector('meta[name="csrf-token"]');
            var payload  = {
                url:              settings.url || '',
                status:           xhr.status || 0,
                error:            thrownError || xhr.statusText || '',
                response_preview: (xhr.responseText || '').substring(0, 300)
            };
            if(csrfNameEl && csrfTokEl){ payload[csrfNameEl.getAttribute('content')] = csrfTokEl.getAttribute('content'); }
            $.post(BASE_URL + 'superadmin/debug/log_ajax_error', payload);
        } catch(e){}
    });
}

/* ── Global SA helpers ── */
function saToast(msg, type){
    type = type || 'success';
    var colors = {success:'rgba(61,214,140,.12)',error:'rgba(224,92,111,.12)',warning:'rgba(124,58,237,.12)',info:'rgba(74,181,227,.12)'};
    var borders = {success:'#15803d',error:'#E05C6F',warning:'#7c3aed',info:'#4AB5E3'};
    var icons   = {success:'fa-check-circle',error:'fa-times-circle',warning:'fa-exclamation-triangle',info:'fa-info-circle'};
    var $t = $('<div>').css({
        position:'fixed',top:'72px',right:'20px',zIndex:9999,
        background:colors[type],border:'1px solid '+borders[type],
        borderLeft:'3px solid '+borders[type],borderRadius:'10px',
        padding:'11px 16px',display:'flex',alignItems:'center',gap:'9px',
        fontFamily:"'Plus Jakarta Sans',sans-serif",fontSize:'13px',
        color:'var(--t1)',boxShadow:'0 4px 20px rgba(0,0,0,.4)',
        maxWidth:'340px',backdropFilter:'blur(8px)',animation:'fadeIn .2s ease'
    }).append($('<i>').addClass('fa '+icons[type]).css({color:borders[type],flexShrink:0}))
      .append($('<span>').text(msg));
    $('body').append($t);
    setTimeout(function(){ $t.fadeOut(300, function(){ $t.remove(); }); }, 3500);
}

function saConfirm(msg, cb){
    if(window.confirm(msg)) cb();
}

/* ── Global Search Bar (header) ── */
(function(){
    var $wrap   = $('.g-search');
    var $input  = $wrap.find('input');
    if(!$input.length) return;

    // Create dropdown
    var $dd = $('<div id="saSearchDropdown">').css({
        position:'absolute',top:'100%',left:0,right:0,zIndex:9999,
        background:'var(--bg2)',border:'1px solid var(--border)',borderTop:'none',
        borderRadius:'0 0 10px 10px',maxHeight:'380px',overflowY:'auto',
        boxShadow:'0 8px 24px rgba(0,0,0,.35)',display:'none',
        fontFamily:'var(--font-b)',fontSize:'13px'
    }).appendTo($wrap.css('position','relative'));

    var _timer = null;
    $input.on('input', function(){
        clearTimeout(_timer);
        var q = $.trim(this.value);
        if(q.length < 2){ $dd.hide().empty(); return; }
        _timer = setTimeout(function(){
            $.post(BASE_URL+'superadmin/dashboard/search', {q:q}, function(r){
                if(r.status!=='success') return;
                // Guard against stale out-of-order responses
                if($.trim($input.val()).toLowerCase() !== (r.query||'')) return;
                var items = r.results || [];
                if(!items.length){
                    $dd.html('<div style="padding:14px 16px;color:var(--t3);text-align:center;">No results for "'+$('<span>').text(q).html()+'"</div>').show();
                    return;
                }
                var statusCls = {active:'#22c55e',grace:'#eab308',expired:'#ef4444',suspended:'#6b7280',inactive:'#9ca3af'};
                var html = items.map(function(it){
                    var dot = it.status ? '<span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:'+(statusCls[it.status]||'#9ca3af')+';margin-left:6px;"></span>' : '';
                    return '<a href="'+BASE_URL+it.url+'" style="display:flex;align-items:center;gap:10px;padding:10px 16px;color:var(--t1);text-decoration:none;border-bottom:1px solid var(--border);transition:background .15s;" onmouseenter="this.style.background=\'var(--bg3)\'" onmouseleave="this.style.background=\'transparent\'">'
                        +'<i class="fa '+it.icon+'" style="color:var(--sa3);width:18px;text-align:center;flex-shrink:0;"></i>'
                        +'<div style="flex:1;min-width:0;">'
                        +'<div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'+$('<span>').text(it.title).html()+dot+'</div>'
                        +'<div style="font-size:11px;color:var(--t3);">'+$('<span>').text(it.detail).html()+'</div>'
                        +'</div></a>';
                }).join('');
                $dd.html(html).show();
            },'json');
        }, 300);
    });

    // Close on click outside
    $(document).on('click', function(e){
        if(!$(e.target).closest('.g-search').length) $dd.hide();
    });
    // Close on Escape
    $input.on('keydown', function(e){
        if(e.key==='Escape'){ $dd.hide(); this.value=''; }
    });
})();

/* DataTables init */
$(document).ready(function(){
    function initDT(sel, opts){
        $(sel).each(function(){
            if(!$(this).length || $.fn.DataTable.isDataTable(this)) return;
            new DataTable(this, opts);
        });
    }
    initDT('.sa-table', {
        paging:true, searching:true, ordering:true, info:true,
        responsive:true, lengthMenu:[10,25,50,100], autoWidth:false,
        dom:'<"top"Bfl>rt<"bottom"ip>',
        buttons:[{extend:'pdfHtml5',orientation:'landscape',pageSize:'LEGAL'},'copy','csv','excel','print'],
        language:{emptyTable:'No data found'}
    });
    initDT('.sa-table-simple',{
        paging:true, searching:true, ordering:true, info:true,
        responsive:true, lengthMenu:[10,25,50], autoWidth:false,
        language:{emptyTable:'No data found'}
    });
});
</script>
</body>
</html>
