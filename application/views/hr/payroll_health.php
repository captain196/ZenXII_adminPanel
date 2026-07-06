<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!--
  Payroll Health — read-only operational view.
  Consumes ONLY the existing endpoint GET hr/payroll_health. No business
  logic, no Firestore writes, no payroll/accounting impact. All aggregation
  is done server-side; this page just renders the JSON.
-->
<div class="content-wrapper">
  <section class="content-header">
    <h1>
      <i class="fa fa-heartbeat"></i> Payroll Health
      <small>Posting outbox &amp; reconciler status</small>
    </h1>
    <ol class="breadcrumb">
      <li><a href="<?= base_url('admin') ?>"><i class="fa fa-dashboard"></i> Dashboard</a></li>
      <li><a href="<?= base_url('hr/payroll') ?>">Payroll</a></li>
      <li class="active">Health</li>
    </ol>
  </section>

  <section class="content">

    <!-- Overall health banner -->
    <div id="phHealthBanner" class="callout callout-info" style="margin-bottom:15px">
      <h4 id="phHealthTitle"><i class="fa fa-spinner fa-spin"></i> Loading payroll health…</h4>
      <p id="phHealthSub">Fetching the latest reconciler status.</p>
    </div>

    <div class="pull-right" style="margin-bottom:10px">
      <button id="phRefresh" class="btn btn-default btn-sm"><i class="fa fa-refresh"></i> Refresh</button>
      <span id="phGeneratedAt" class="text-muted" style="margin-left:8px;font-size:12px"></span>
    </div>
    <div class="clearfix"></div>

    <!-- Metric cards -->
    <div class="row">
      <div class="col-lg-2 col-sm-4 col-xs-6">
        <div class="small-box bg-aqua">
          <div class="inner"><h3 id="phLastRun" style="font-size:18px">--</h3><p>Last Reconciler Run</p></div>
          <div class="icon"><i class="fa fa-refresh"></i></div>
        </div>
      </div>
      <div class="col-lg-2 col-sm-4 col-xs-6">
        <div class="small-box bg-green">
          <div class="inner"><h3 id="phLastSuccess" style="font-size:18px">--</h3><p>Last Successful Reconciliation</p></div>
          <div class="icon"><i class="fa fa-check-circle"></i></div>
        </div>
      </div>
      <div class="col-lg-2 col-sm-4 col-xs-6">
        <div class="small-box bg-yellow">
          <div class="inner"><h3 id="phPending">--</h3><p>Pending Postings</p></div>
          <div class="icon"><i class="fa fa-hourglass-half"></i></div>
        </div>
      </div>
      <div class="col-lg-2 col-sm-4 col-xs-6">
        <div class="small-box bg-teal">
          <div class="inner"><h3 id="phRecovered">--</h3><p>Recovered Postings</p></div>
          <div class="icon"><i class="fa fa-magic"></i></div>
        </div>
      </div>
      <div class="col-lg-2 col-sm-4 col-xs-6">
        <div class="small-box bg-red">
          <div class="inner"><h3 id="phFailed">--</h3><p>Failed Postings</p></div>
          <div class="icon"><i class="fa fa-exclamation-triangle"></i></div>
        </div>
      </div>
      <div class="col-lg-2 col-sm-4 col-xs-6">
        <div class="small-box bg-purple">
          <div class="inner"><h3 id="phRetries">--</h3><p>Retry Totals</p></div>
          <div class="icon"><i class="fa fa-repeat"></i></div>
        </div>
      </div>
    </div>

    <!-- Reconciler status -->
    <div class="box box-solid">
      <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-cogs"></i> Reconciler Status</h3>
        <span id="phReconBadge" class="label label-default pull-right">--</span>
      </div>
      <div class="box-body">
        <div class="row">
          <div class="col-sm-4"><strong>Last run result:</strong> <span id="phReconResult">--</span></div>
          <div class="col-sm-4"><strong>Records found last run:</strong> <span id="phReconFound">--</span></div>
          <div class="col-sm-4"><strong>Reposted / Failed last run:</strong> <span id="phReconRepost">--</span> / <span id="phReconFail">--</span></div>
        </div>
      </div>
    </div>

    <!-- Failed postings -->
    <div class="box box-danger">
      <div class="box-header with-border"><h3 class="box-title"><i class="fa fa-list"></i> Failed Postings</h3></div>
      <div class="box-body">
        <div id="phHealthyState" class="text-center" style="display:none;padding:24px">
          <i class="fa fa-check-circle text-green" style="font-size:44px"></i>
          <h4 class="text-green" style="margin:10px 0 2px">System Healthy</h4>
          <p class="text-muted">No pending or failed payroll postings.</p>
        </div>
        <div class="table-responsive" id="phFailedWrap" style="display:none">
          <table class="table table-bordered table-hover table-striped" style="font-size:13px">
            <thead>
              <tr>
                <th>Run ID</th><th>Seq</th><th>Journal ID</th><th class="text-right">Amount</th>
                <th>Failure Reason</th><th>Required Action</th><th class="text-center">Retries</th>
                <th>First Failure</th><th>Last Retry</th><th>Last Result</th>
              </tr>
            </thead>
            <tbody id="phFailedBody"></tbody>
          </table>
        </div>
      </div>
    </div>

  </section>
</div>

<script>
(function () {
  var HEALTH_URL = '<?= base_url('hr/payroll_health') ?>';

  function esc(s){ return $('<div>').text(s == null ? '' : String(s)).html(); }
  function fmtDate(iso){
    if (!iso) return '--';
    var d = new Date(iso);
    return isNaN(d.getTime()) ? esc(iso) : d.toLocaleString();
  }
  function money(n){
    n = Number(n) || 0;
    return n.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }

  function setBanner(kind, title, sub){
    var map = {success:'callout-success', warning:'callout-warning', danger:'callout-danger', info:'callout-info'};
    var icon = {success:'fa-check-circle', warning:'fa-hourglass-half', danger:'fa-exclamation-triangle', info:'fa-info-circle'};
    $('#phHealthBanner').removeClass('callout-success callout-warning callout-danger callout-info').addClass(map[kind]);
    $('#phHealthTitle').html('<i class="fa ' + icon[kind] + '"></i> ' + esc(title));
    $('#phHealthSub').text(sub);
  }

  function render(r){
    var rec = r.reconciler || {}, sum = r.summary || {}, failed = r.failed_postings || [];
    var pending = Number(sum.pending_postings) || 0;
    var failedN = Number(sum.failed_postings) || 0;

    // Metric cards
    $('#phLastRun').text(fmtDate(rec.last_run_at));
    $('#phLastSuccess').text(fmtDate(rec.last_success_at));
    $('#phPending').text(pending);
    $('#phRecovered').text(Number(sum.recovered_postings) || 0);
    $('#phFailed').text(failedN);
    $('#phRetries').text(Number(sum.retry_total) || 0);
    $('#phGeneratedAt').text('As of ' + fmtDate(r.generated_at));

    // Reconciler status
    var st = rec.status || 'never_run';
    var res = rec.last_run_result || '--';
    var badge = st === 'never_run' ? ['label-warning','Never Run']
             : res === 'ok'        ? ['label-success','Healthy']
             : res === 'partial'   ? ['label-danger','Partial (failures)']
             :                        ['label-default', esc(res)];
    $('#phReconBadge').removeClass().addClass('label pull-right ' + badge[0]).text(badge[1]);
    $('#phReconResult').text(res);
    $('#phReconFound').text(Number(rec.found) || 0);
    $('#phReconRepost').text(Number(rec.reposted) || 0);
    $('#phReconFail').text(Number(rec.failed) || 0);

    // Overall banner
    if (failedN > 0) {
      setBanner('danger', 'Attention Required', failedN + ' payroll posting(s) failed and need an operator action. See the table below.');
    } else if (pending > 0) {
      setBanner('warning', 'Postings Pending Recovery', pending + ' payroll posting(s) are pending; the reconciler will retry automatically.');
    } else {
      setBanner('success', 'System Healthy', 'No pending or failed payroll postings.');
    }

    // Failed table
    var $body = $('#phFailedBody').empty();
    if (!failed.length) {
      $('#phFailedWrap').hide();
      $('#phHealthyState').show();
    } else {
      $('#phHealthyState').hide();
      $('#phFailedWrap').show();
      failed.forEach(function (f) {
        $body.append(
          '<tr>' +
            '<td>' + esc(f.run_id) + '</td>' +
            '<td class="text-center">' + esc(f.seq) + '</td>' +
            '<td><code style="font-size:11px">' + esc(f.journal_id) + '</code></td>' +
            '<td class="text-right">' + money(f.amount) + '</td>' +
            '<td>' + esc(f.failure_reason) + '</td>' +
            '<td><span class="text-navy">' + esc(f.required_action) + '</span></td>' +
            '<td class="text-center"><span class="badge bg-red">' + (Number(f.retry_count) || 0) + '</span></td>' +
            '<td>' + fmtDate(f.first_failure_at) + '</td>' +
            '<td>' + fmtDate(f.last_retry_at) + '</td>' +
            '<td><span class="label label-danger">' + esc(f.last_retry_result || 'failed') + '</span></td>' +
          '</tr>'
        );
      });
    }
  }

  function load(){
    setBanner('info', 'Loading payroll health…', 'Fetching the latest reconciler status.');
    $.ajax({ url: HEALTH_URL, type: 'GET', dataType: 'json' })
      .done(function (r) {
        if (r && r.status === 'success') { render(r); }
        else { setBanner('danger', 'Could not load health data', (r && r.message) || 'Unexpected response.'); }
      })
      .fail(function () {
        setBanner('danger', 'Could not load health data', 'The health endpoint did not respond. Try Refresh.');
      });
  }

  $(function () {
    load();
    $('#phRefresh').on('click', load);
  });
})();
</script>
