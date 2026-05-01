
<!-- Phone prefix input group (global style) -->
<style>
.phone-ig{display:flex;align-items:stretch;width:100%}
.phone-ig .phone-pfx{display:flex;align-items:center;padding:0 12px;background:#f0f0f0;border:1px solid #ccc;border-right:0;border-radius:4px 0 0 4px;font-size:14px;font-weight:600;color:#555;white-space:nowrap;user-select:none;line-height:1}
.phone-ig input{border-radius:0 4px 4px 0!important;flex:1;min-width:0}
/* Night mode */
[data-theme="night"] .phone-pfx,[data-bs-theme="dark"] .phone-pfx{background:var(--bg3,#1a2540);border-color:var(--border,#2a3350);color:var(--t2,#8892aa)}
</style>

<footer class="main-footer giq-footer">
    <div class="giq-foot-inner">

        <!-- Left: copyright -->
        <div class="giq-foot-left">
            <div class="giq-foot-mark">G</div>
            <div class="giq-foot-copy">
                <!-- <strong>© <?= date('Y') ?>–<?= date('Y') + 1 ?></strong> -->
                 <?php
$start = date('Y');
$end   = substr($start + 1, -2);
?>

<strong>© <?= $start . '-' . $end ?></strong>
                <span class="giq-foot-sep">·</span>
                <a href="https://graderiq.com/" target="_blank" rel="noopener">SchoolX</a>
                <span class="giq-foot-sep">·</span>
                All rights reserved.
            </div>
        </div>

        <!-- Right: developer + version -->
        <div class="giq-foot-right">
            <div class="giq-foot-dev">
                Built by <span>Ankit Prajapati</span>
            </div>
            <span class="giq-foot-tag">v2.0</span>
        </div>

    </div>
</footer>

<!-- Control Sidebar (hidden, keep for AdminLTE compatibility) -->
<aside class="control-sidebar control-sidebar-dark" style="display:none;"></aside>
<div class="control-sidebar-bg"></div>
</div><!-- ./wrapper -->

<!-- ═══════════════ SCRIPTS ═══════════════ -->
<script src="<?= base_url() ?>tools/bower_components/jquery/dist/jquery.min.js"></script>
<script src="<?= base_url() ?>tools/bower_components/jquery-ui/jquery-ui.min.js"></script>
<script>
    $.widget.bridge('uibutton', $.ui.button);
</script>
<script src="<?= base_url() ?>tools/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>
<script src="<?= base_url() ?>tools/bower_components/raphael/raphael.min.js"></script>
<script src="<?= base_url() ?>tools/bower_components/morris.js/morris.min.js"></script>
<script src="<?= base_url() ?>tools/bower_components/jquery-sparkline/dist/jquery.sparkline.min.js"></script>
<script src="<?= base_url() ?>tools/bower_components/jquery-knob/dist/jquery.knob.min.js"></script>
<script src="<?= base_url() ?>tools/bower_components/moment/min/moment.min.js"></script>
<script src="<?= base_url() ?>tools/bower_components/bootstrap-daterangepicker/daterangepicker.js"></script>
<script src="<?= base_url() ?>tools/bower_components/bootstrap-datepicker/dist/js/bootstrap-datepicker.min.js"></script>
<script src="<?= base_url() ?>tools/bower_components/jquery-slimscroll/jquery.slimscroll.min.js"></script>
<script src="<?= base_url() ?>tools/bower_components/fastclick/lib/fastclick.js"></script>
<script src="<?= base_url() ?>tools/dist/js/adminlte.min.js"></script>
<script src="<?= base_url() ?>tools/dist/js/demo.js"></script>
<script src="<?= base_url() ?>tools/js/custom.js"></script>

<!-- Crypto + DataTables -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.1.1/crypto-js.min.js"></script>
<script src="https://cdn.datatables.net/2.0.8/js/dataTables.js"></script>
<script src="https://cdn.datatables.net/buttons/3.0.2/js/dataTables.buttons.js"></script>
<script src="https://cdn.datatables.net/buttons/3.0.2/js/buttons.dataTables.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/3.0.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/3.0.2/js/buttons.print.min.js"></script>

<script>
    /* ── Global 401/403 handler: redirect to login on session expiry ── */
    $(document).ajaxComplete(function(event, xhr){
        if(xhr.status === 401 || xhr.status === 403){
            try {
                var r = JSON.parse(xhr.responseText);
                if(r.status === 'error' && r.message && /session|expired|log\s*in|deactivat|subscription/i.test(r.message)){
                    // Show toast if available, else alert
                    if(typeof saToast === 'function'){
                        saToast(r.message, 'error');
                    } else {
                        var $t = $('<div>').css({
                            position:'fixed',top:'20px',right:'20px',zIndex:99999,
                            background:'#fef2f2',border:'1px solid #ef4444',borderRadius:'8px',
                            padding:'12px 18px',fontSize:'13px',color:'#991b1b',
                            boxShadow:'0 4px 20px rgba(0,0,0,.15)',maxWidth:'360px',
                            fontFamily:"'Plus Jakarta Sans',sans-serif"
                        }).html('<i class="fa fa-exclamation-circle" style="margin-right:8px;color:#ef4444;"></i>' + r.message);
                        $('body').append($t);
                    }
                    setTimeout(function(){ window.location.href = '<?= base_url("admin_login") ?>'; }, 1800);
                }
            } catch(e){}
        }
    });

    /* ── Global helpers ── */
    function numberFormat(num) {
        return num.toLocaleString('en-IN');
    }

    function formatDateToYMD(d) {
        var p = d.split('-');
        return p.length === 3 ? p[2] + '-' + p[1] + '-' + p[0] : d;
    }

    function formatDateToDMY(d) {
        var p = d.split('-');
        return p[2] + '-' + p[1] + '-' + p[0];
    }

    /* ── DataTables ── */
    $(document).ready(function() {

        function initDT(sel, opts) {
            $(sel).each(function() {
                if (!$(this).length || $.fn.DataTable.isDataTable(this)) return;
                new DataTable(this, opts);
            });
        }

        /* Full-feature table (.example) */
        initDT('.example', {
            autoWidth: false,
            responsive: false,
            columnDefs: [{
                targets: 0,
                orderable: false
            }, {
                targets: -1,
                orderable: false
            }],
            layout: {
                topStart: {
                    buttons: [{
                        extend: 'pdfHtml5',
                        orientation: 'landscape',
                        pageSize: 'LEGAL'
                    }, 'copy', 'csv', 'excel', 'print']
                }
            },
            language: {
                emptyTable: 'No data available'
            }
        });

        /* dataTable class */
        initDT('.dataTable', {
            paging: true,
            searching: true,
            ordering: true,
            info: true,
            responsive: true,
            lengthMenu: [5, 10, 15, 20],
            autoWidth: false,
            dom: '<"top"Bfl>rt<"bottom"ip>',
            buttons: [{
                extend: 'pdfHtml5',
                orientation: 'landscape',
                pageSize: 'LEGAL'
            }, 'copy', 'csv', 'excel', 'print'],
            language: {
                emptyTable: 'No data available'
            }
        });

        /* Minimal table */
        initDT('.minimalFeatureTable', {
            paging: true,
            searching: true,
            ordering: false,
            info: true,
            responsive: true,
            lengthMenu: [5, 10, 15, 20],
            language: {
                emptyTable: 'No data available'
            }
        });

    });
</script>

</body>

</html>

<style>
    /* ─── Footer — uses vars from header.php ─── */
    .main-footer.giq-footer {
        background: var(--bg2) !important;
        border-top: 1px solid var(--border) !important;
        margin-left: var(--sw, 248px) !important;
        padding: 0 !important;
        transition: background var(--ease, .22s), margin-left var(--ease, .22s) !important;
        position: relative;
    }

    /* Gold top line */
    .main-footer.giq-footer::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 1px;
        background: linear-gradient(90deg, var(--gold, #0f766e) 0%, rgba(15, 118, 110, .2) 60%, transparent 100%);
    }

    .giq-foot-inner {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 8px;
        padding: 12px 24px;
    }

    /* Left: brand + copyright */
    .giq-foot-left {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .giq-foot-mark {
        width: 22px;
        height: 22px;
        border-radius: 5px;
        background: var(--gold, #0f766e);
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: var(--font-d, 'Syne', sans-serif);
        font-size: 11px;
        font-weight: 800;
        color: #ffffff;
        box-shadow: 0 0 8px rgba(15, 118, 110, .3);
        flex-shrink: 0;
    }

    .giq-foot-copy {
        font-size: 12px;
        color: var(--t3, #7A6E54);
        font-family: var(--font-b, 'Plus Jakarta Sans', sans-serif);
    }

    .giq-foot-copy strong {
        color: var(--t2, #94c9c3);
        font-weight: 600;
    }

    .giq-foot-copy a {
        color: var(--gold, #0f766e) !important;
        font-weight: 700;
        text-decoration: none !important;
        transition: color .15s;
    }

    .giq-foot-copy a:hover {
        color: var(--gold2, #0d6b63) !important;
    }

    /* Separator */
    .giq-foot-sep {
        color: var(--border, rgba(15, 118, 110, .09));
        font-size: 14px;
        margin: 0 2px;
    }

    /* Right: dev credit + version tag */
    .giq-foot-right {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .giq-foot-dev {
        font-size: 11.5px;
        color: var(--t3, #7A6E54);
        font-family: var(--font-m, 'JetBrains Mono', monospace);
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .giq-foot-dev span {
        color: var(--gold, #0f766e);
    }

    .giq-foot-tag {
        font-size: 9.5px;
        font-weight: 700;
        font-family: var(--font-m, 'JetBrains Mono', monospace);
        padding: 2px 8px;
        border-radius: 4px;
        background: rgba(15, 118, 110, .09);
        color: var(--gold, #0f766e);
        border: 1px solid rgba(15, 118, 110, .22);
        letter-spacing: .3px;
    }

    /* Collapsed sidebar */
    .sidebar-collapse .main-footer.giq-footer {
        margin-left: 56px !important;
    }
</style>
