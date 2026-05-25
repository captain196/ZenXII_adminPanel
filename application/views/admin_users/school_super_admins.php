<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<style>
:root{--green:#16a34a;}
.content-wrapper { margin-left:var(--sw,248px) !important; margin-top:var(--hh,58px) !important; min-height:calc(100vh - var(--hh,58px)) !important; }

.au-fg{display:flex;flex-direction:column;gap:4px;margin-bottom:12px;}
.au-fg label{font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.4px;font-family:var(--font-m);}
.au-fg input{padding:8px 12px;border:1px solid var(--border);background:var(--bg3);border-radius:6px;font-size:13px;color:var(--t1);font-family:var(--font-b);transition:border-color .2s,box-shadow .2s;}
.au-fg input:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-ring);}

.au-btn{padding:8px 18px;border-radius:7px;font-size:12.5px;font-weight:600;border:none;cursor:pointer;font-family:var(--font-b);transition:all .15s var(--ease);display:inline-flex;align-items:center;gap:6px;}
.au-btn:disabled{opacity:.5;cursor:not-allowed;}
.au-btn-p{background:var(--gold);color:#fff;}.au-btn-p:hover{background:var(--gold2);}
.au-btn-s{background:var(--bg3);color:var(--t2);border:1px solid var(--border);}.au-btn-s:hover{border-color:var(--gold-ring);}
.au-btn-sm{padding:5px 12px;font-size:11.5px;}

.au-table{width:100%;border-collapse:collapse;}
.au-table th{padding:10px 12px;background:var(--bg3);color:var(--t3);font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;font-family:var(--font-m);border-bottom:2px solid var(--border);text-align:left;}
.au-table td{padding:9px 12px;border-bottom:1px solid var(--border);color:var(--t1);font-size:12.5px;}
.au-table tr:hover td{background:var(--gold-dim);}
.au-wrap{border:1px solid var(--border);border-radius:8px;overflow:hidden;}

.au-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:10.5px;font-weight:600;letter-spacing:.3px;}
.au-b-green{background:rgba(22,163,74,.12);color:#16a34a;}
.au-b-red{background:rgba(220,38,38,.10);color:#dc2626;}

.au-box{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:18px;}
.au-box-head{font-size:14px;font-weight:700;color:var(--t1);font-family:var(--font-b);margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;}
.au-note{padding:10px 14px;background:var(--gold-dim);border:1px solid var(--gold-ring);border-radius:8px;font-size:12px;color:var(--t2);margin-bottom:16px;line-height:1.5;}
</style>

<div class="content-wrapper">
<section class="content-header">
    <h1 style="font-family:var(--font-b); font-weight:700; color:var(--t1); font-size:1.3rem;">
        <i class="fa fa-user-shield" style="color:var(--gold); margin-right:8px;"></i>
        School Super Admins
    </h1>
    <ol class="breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li class="active">School Super Admins</li>
    </ol>
</section>

<section class="content" style="padding:18px 24px;">

    <div class="au-note">
        <i class="fa fa-info-circle" style="color:var(--gold);margin-right:5px;"></i>
        Reset another School Super Admin's password. After reset, the account is forced to change its password on next login and is signed out of every device. To change your own password use
        <a href="<?= base_url('admin_users/change_my_password') ?>" style="color:var(--gold);font-weight:600;">Change My Password</a>.
    </div>

    <div class="au-box">
        <div class="au-box-head">
            <i class="fa fa-users" style="color:var(--gold);"></i>
            Peer School Super Admins (<?= count($peers) ?>)
        </div>

        <div class="au-wrap">
            <table class="au-table">
                <thead>
                    <tr>
                        <th style="width:120px;">SSA ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th style="width:100px;">Status</th>
                        <th style="width:160px;text-align:right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($peers)): ?>
                    <tr><td colspan="5" style="text-align:center;padding:28px;color:var(--t3);">
                        No other School Super Admins in this school.
                    </td></tr>
                <?php else: foreach ($peers as $p):
                    $stClass = strcasecmp($p['status'], 'Active') === 0 ? 'au-b-green' : 'au-b-red';
                ?>
                    <tr>
                        <td style="font-family:var(--font-m);color:var(--gold);font-weight:600;"><?= htmlspecialchars($p['id']) ?></td>
                        <td><?= htmlspecialchars($p['name']) ?></td>
                        <td style="color:var(--t3);font-size:12px;"><?= htmlspecialchars($p['email']) ?></td>
                        <td><span class="au-badge <?= $stClass ?>"><?= htmlspecialchars($p['status'] ?: 'Active') ?></span></td>
                        <td style="text-align:right;">
                            <button class="au-btn au-btn-sm au-btn-s ssa-reset"
                                data-id="<?= htmlspecialchars($p['id']) ?>"
                                data-name="<?= htmlspecialchars($p['name']) ?>">
                                <i class="fa fa-key" style="color:var(--gold);"></i> Reset Password
                            </button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</section>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="ssaResetModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content" style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;">
            <div class="modal-header" style="border-color:var(--border);">
                <button type="button" class="close" data-dismiss="modal" style="color:var(--t3);">&times;</button>
                <h4 class="modal-title" style="font-family:var(--font-b);color:var(--t1);">
                    <i class="fa fa-key" style="color:var(--gold);margin-right:8px;"></i>Reset SSA Password
                </h4>
            </div>
            <form id="ssaResetForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" id="ssaResetId" value="">
                    <p style="font-size:12.5px;color:var(--t2);margin-bottom:12px;">
                        Reset password for <strong id="ssaResetName"></strong>.
                        They will be forced to choose a new password on next login.
                    </p>
                    <div class="au-fg">
                        <label>New Password *</label>
                        <input type="password" id="ssaResetPw" minlength="8" required>
                        <span style="font-size:11px;color:var(--t3);margin-top:3px;">Min 8 chars, one upper, one lower, one digit.</span>
                    </div>
                </div>
                <div class="modal-footer" style="border-color:var(--border);">
                    <button type="button" class="au-btn au-btn-s" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="au-btn au-btn-p"><i class="fa fa-key"></i> Reset</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
'use strict';
var B = typeof BASE_URL !== 'undefined' ? BASE_URL : <?= json_encode(base_url()) ?>;

function esc(s){ return $('<div>').text(s||'').html(); }
function csrf(extra){ var o={}; if(typeof csrfName!=='undefined') o[csrfName]=csrfToken; return $.extend(o,extra||{}); }
function toast(msg,ok){var t=$('<div style="position:fixed;top:20px;right:20px;z-index:99999;padding:12px 20px;border-radius:8px;font-size:.85rem;color:#fff;box-shadow:0 4px 12px rgba(0,0,0,.15);background:'+(ok?'var(--gold)':'#dc2626')+'">'+esc(msg)+'</div>');$('body').append(t);setTimeout(function(){t.fadeOut(300,function(){t.remove()})},3500);}

$('.ssa-reset').on('click', function(){
    $('#ssaResetId').val($(this).data('id'));
    $('#ssaResetName').text($(this).data('id') + ' — ' + $(this).data('name'));
    $('#ssaResetPw').val('');
    $('#ssaResetModal').modal('show');
    setTimeout(function(){ $('#ssaResetPw').focus(); }, 200);
});

$('#ssaResetForm').on('submit', function(e){
    e.preventDefault();
    var id = $('#ssaResetId').val();
    var pw = $('#ssaResetPw').val();
    if (!pw || pw.length < 8) { toast('Password must be at least 8 characters.', false); return; }

    var $btn = $(this).find('button[type=submit]').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Resetting...');
    $.post(B + 'admin_users/reset_ssa_password', csrf({ ssa_id: id, new_password: pw }), function(r){
        if (r.status === 'success') {
            toast(r.message || 'Password reset.', true);
            $('#ssaResetModal').modal('hide');
        } else {
            toast(r.message || 'Reset failed.', false);
        }
    }, 'json')
    .fail(function(xhr){
        var m = 'Network error.';
        try { var j = JSON.parse(xhr.responseText); if (j && j.message) m = j.message; } catch(_) {}
        toast(m, false);
    })
    .always(function(){
        $btn.prop('disabled', false).html('<i class="fa fa-key"></i> Reset');
    });
});

});
</script>
