<?php
$tabs = [
    'dashboard' => ['icon' => 'fa-dashboard',   'label' => 'Dashboard',      'url' => 'communication'],
    'messages'  => ['icon' => 'fa-comments',     'label' => 'Messages',       'url' => 'communication/messages'],
    'notices'   => ['icon' => 'fa-bullhorn',     'label' => 'Notice Board',   'url' => 'communication/notices'],
    'circulars' => ['icon' => 'fa-file-text-o',  'label' => 'Circulars',      'url' => 'communication/circulars'],
    'templates' => ['icon' => 'fa-copy',         'label' => 'Templates',      'url' => 'communication/templates'],
    'triggers'  => ['icon' => 'fa-bolt',         'label' => 'Alert Triggers', 'url' => 'communication/triggers'],
    'queue'     => ['icon' => 'fa-clock-o',      'label' => 'Queue',          'url' => 'communication/queue'],
    'logs'      => ['icon' => 'fa-list-alt',     'label' => 'Delivery Logs',  'url' => 'communication/logs'],
];
$at = $active_tab ?? 'messages';
?>
<style>
.cm-wrap{padding:20px;max-width:1400px;margin:0 auto}
.cm-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.cm-header-icon{font-family:var(--font-b);font-size:1.3rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.cm-header-icon i{color:var(--gold);font-size:1.1rem}
.cm-breadcrumb{list-style:none;display:flex;gap:6px;font-size:12px;color:var(--t3);padding:0;margin:6px 0 0;font-family:var(--font-b)}
.cm-breadcrumb a{color:var(--gold);text-decoration:none}
.cm-breadcrumb li+li::before{content:">";margin-right:6px;color:var(--t4)}
.cm-tabs{display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--border);overflow-x:auto}
.cm-tab{padding:10px 16px;font-size:13px;font-weight:600;color:var(--t3);text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;transition:all var(--ease);font-family:var(--font-b)}
.cm-tab:hover{color:var(--t1)} .cm-tab.active{color:var(--gold);border-bottom-color:var(--gold)} .cm-tab i{margin-right:6px;font-size:14px}

/* Messaging Layout */
.msg-layout{display:grid;grid-template-columns:340px 1fr;gap:18px;min-height:500px}
.msg-sidebar{background:var(--card,var(--bg2));border:1px solid var(--border);border-radius:var(--r,10px);overflow:hidden;display:flex;flex-direction:column}
.msg-sidebar-header{padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.msg-sidebar-title{font-family:var(--font-b);font-size:14px;font-weight:700;color:var(--t1)}
.msg-conv-list{flex:1;overflow-y:auto}
.msg-conv-item{padding:12px 16px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .15s;display:flex;gap:10px;align-items:flex-start}
.msg-conv-item:hover,.msg-conv-item.active{background:var(--gold-dim)}
.msg-conv-av{width:36px;height:36px;border-radius:50%;background:var(--gold);color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;font-family:var(--font-b)}
.msg-conv-info{flex:1;min-width:0}
.msg-conv-name{font-family:var(--font-b);font-size:13px;font-weight:600;color:var(--t1);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.msg-conv-preview{font-size:12px;color:var(--t3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.msg-conv-meta{font-size:10px;color:var(--t4,var(--t3));margin-top:2px;font-family:var(--font-m)}
.msg-conv-unread{background:var(--gold);color:#fff;font-size:10px;font-weight:700;border-radius:10px;padding:2px 7px;font-family:var(--font-b)}

.msg-chat{background:var(--card,var(--bg2));border:1px solid var(--border);border-radius:var(--r,10px);display:flex;flex-direction:column}
.msg-chat-header{padding:14px 16px;border-bottom:1px solid var(--border);font-family:var(--font-b);font-weight:700;color:var(--t1);font-size:14px;display:flex;align-items:center;justify-content:space-between}
.msg-chat-body{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:8px}
.msg-bubble{max-width:75%;padding:10px 14px;border-radius:12px;font-size:13px;font-family:var(--font-b);line-height:1.5;position:relative}
.msg-bubble.sent{background:var(--gold);color:#fff;align-self:flex-end;border-bottom-right-radius:4px}
.msg-bubble.received{background:var(--bg3,#e6f4f1);color:var(--t1);align-self:flex-start;border-bottom-left-radius:4px}
.msg-bubble-meta{font-size:10px;opacity:.7;margin-top:4px}
.msg-chat-input{padding:12px 16px;border-top:1px solid var(--border);display:flex;gap:10px}
.msg-chat-input input{flex:1;padding:10px 14px;border:1px solid var(--border);border-radius:20px;background:var(--bg);color:var(--t1);font-size:13px;font-family:var(--font-b)}
.msg-chat-input input:focus{border-color:var(--gold);outline:none}
.msg-send-btn{width:40px;height:40px;border-radius:50%;background:var(--gold);color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:16px;transition:background .15s}
.msg-send-btn:hover{background:var(--gold2)}
.msg-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;flex:1;color:var(--t3);text-align:center;gap:12px}
.msg-empty i{font-size:48px;opacity:.3}
.msg-loading{display:flex;align-items:center;justify-content:center;flex:1;color:var(--t3);font-size:13px;font-family:var(--font-b)}

/* New conversation modal reuses cm-modal from index */
/* Modal/toast/form styles inherited from header.php global definitions */
.cm-modal{width:500px}
.cm-form-group textarea{resize:vertical;min-height:60px}
.cm-btn{padding:8px 16px;border-radius:var(--r-sm,6px);font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all var(--ease);font-family:var(--font-b)}
.cm-btn-primary{background:var(--gold);color:#fff} .cm-btn-primary:hover{background:var(--gold2)}
.cm-btn-sm{padding:6px 12px;font-size:12px}

/* Recipient search dropdown */
.rcpt-search-wrap{position:relative}
.rcpt-results{position:absolute;top:100%;left:0;right:0;background:var(--bg2);border:1px solid var(--border);border-radius:0 0 6px 6px;max-height:180px;overflow-y:auto;z-index:100;display:none}
.rcpt-results.show{display:block}
.rcpt-item{padding:8px 12px;cursor:pointer;font-size:13px;color:var(--t1);border-bottom:1px solid var(--border);font-family:var(--font-b)}
.rcpt-item:hover{background:var(--gold-dim)}
.cm-toast{position:fixed;top:20px;right:20px;z-index:10000;padding:12px 20px;border-radius:8px;font-size:13px;font-weight:600;font-family:var(--font-b);color:#fff;display:none;max-width:400px;box-shadow:0 8px 24px rgba(0,0,0,.15)}
.cm-toast.success{background:#22c55e;display:block}.cm-toast.error{background:#ef4444;display:block}

@media(max-width:768px){.msg-layout{grid-template-columns:1fr}.msg-sidebar{max-height:250px}}
</style>

<div class="content-wrapper"><section class="content"><div class="cm-wrap">
<div class="cm-header"><div>
    <div class="cm-header-icon"><i class="fa fa-comments"></i> Communication</div>
    <ol class="cm-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li><a href="<?= base_url('communication') ?>">Communication</a></li><li>Messages</li></ol>
</div></div>

<nav class="cm-tabs">
    <?php foreach ($tabs as $slug => $t): ?>
    <a class="cm-tab<?= $at === $slug ? ' active' : '' ?>" href="<?= base_url($t['url']) ?>"><i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?></a>
    <?php endforeach; ?>
</nav>

<div class="msg-layout">
    <!-- Conversation List -->
    <div class="msg-sidebar">
        <div class="msg-sidebar-header">
            <span class="msg-sidebar-title">Conversations</span>
            <button class="cm-btn cm-btn-sm cm-btn-primary" onclick="MSG.openNewConv()"><i class="fa fa-plus"></i> New</button>
        </div>
        <div class="msg-conv-list" id="convList">
            <div class="msg-loading">Loading...</div>
        </div>
    </div>

    <!-- Chat Panel -->
    <div class="msg-chat">
        <div class="msg-chat-header" id="chatHeader">
            <span>Select a conversation</span>
        </div>
        <div class="msg-chat-body" id="chatBody">
            <div class="msg-empty"><i class="fa fa-comments-o"></i><span>Select a conversation to start messaging</span></div>
        </div>
        <div class="msg-chat-input" id="chatInput" style="display:none">
            <input type="text" id="msgText" placeholder="Type your message..." onkeypress="if(event.key==='Enter')MSG.sendMsg()">
            <button class="msg-send-btn" onclick="MSG.sendMsg()"><i class="fa fa-paper-plane"></i></button>
        </div>
    </div>
</div>

</div></section></div>

<!-- New Conversation Modal -->
<div class="cm-modal-bg" id="newConvModal"><div class="cm-modal">
    <div class="cm-modal-title"><span>New Conversation</span><button class="cm-modal-close" onclick="MSG.closeNewConv()">&times;</button></div>
    <div class="cm-form-group rcpt-search-wrap">
        <label>Recipient *</label>
        <input type="text" id="rcptSearch" placeholder="Search admin or teacher..." autocomplete="off">
        <div class="rcpt-results" id="rcptResults"></div>
    </div>
    <input type="hidden" id="rcptId"><input type="hidden" id="rcptRole"><input type="hidden" id="rcptName">
    <div id="rcptSelected" style="display:none;padding:8px 12px;background:var(--gold-dim);border-radius:6px;margin-bottom:14px;font-size:13px;font-family:var(--font-b)"></div>
    <div class="cm-form-group"><label>Context (optional)</label><input type="text" id="rcptStudentId" placeholder="Student ID (optional)"></div>
    <div class="cm-form-group"><label>Message *</label><textarea id="newMsgText" rows="3" placeholder="Type your message..."></textarea></div>
    <button class="cm-btn cm-btn-primary" onclick="MSG.createConv()" style="width:100%">Send Message</button>
</div></div>

<div class="cm-toast" id="cmToast"></div>

<script>
var CM = CM || {};
CM.BASE = '<?= base_url() ?>';
CM.adminId = '<?= $admin_id ?>';
CM.toast = function(msg,type){var t=document.getElementById('cmToast');t.textContent=msg;t.className='cm-toast '+(type||'success');setTimeout(function(){t.className='cm-toast';},3000);};
CM.esc = function(s){var d=document.createElement('span');d.textContent=s||'';return d.innerHTML;};
CM.ajax = function(url,data,cb,method,failCb){$.ajax({url:CM.BASE+url,type:method||'GET',data:data,dataType:'json',success:function(r){if(r.status==='success'){if(cb)cb(r);}else{CM.toast(r.message||'Error','error');if(failCb)failCb();}},error:function(xhr){var m='Request failed';try{m=JSON.parse(xhr.responseText).message||m;}catch(e){}CM.toast(m,'error');if(failCb)failCb();}});};

var MSG = {};
MSG.activeConv = null;
MSG.conversations = [];

MSG.loadConversations = function() {
    CM.ajax('communication/get_conversations', {}, function(r) {
        MSG.conversations = r.conversations || [];
        var html = '';
        if (!MSG.conversations.length) {
            html = '<div class="msg-empty"><i class="fa fa-inbox"></i><span>No conversations yet</span></div>';
        } else {
            MSG.conversations.forEach(function(c) {
                var names = c.participant_names || {};
                var otherName = '';
                Object.keys(names).forEach(function(k) { if (k !== CM.adminId) otherName = names[k]; });
                var initials = otherName ? otherName.split(' ').map(function(w){return w[0];}).join('').substring(0,2).toUpperCase() : '??';
                var unread = c.unread_count || 0;
                html += '<div class="msg-conv-item' + (MSG.activeConv === c.id ? ' active' : '') + '" onclick="MSG.openConv(\'' + CM.esc(c.id) + '\')">' +
                    '<div class="msg-conv-av">' + initials + '</div>' +
                    '<div class="msg-conv-info">' +
                    '<div class="msg-conv-name">' + CM.esc(otherName || 'Unknown') + '</div>' +
                    '<div class="msg-conv-preview">' + CM.esc(c.last_message || '') + '</div>' +
                    '<div class="msg-conv-meta">' + CM.esc((c.last_message_at || '').substring(0,16).replace('T',' ')) + '</div>' +
                    '</div>' +
                    (unread > 0 ? '<span class="msg-conv-unread">' + unread + '</span>' : '') +
                    '</div>';
            });
        }
        $('#convList').html(html);
    }, null, function() {
        $('#convList').html('<div class="msg-empty"><i class="fa fa-exclamation-circle"></i><span>Failed to load conversations</span></div>');
    });
};

MSG.openConv = function(convId) {
    MSG.activeConv = convId;
    $('#chatInput').show();
    CM.ajax('communication/get_messages', {conversation_id: convId}, function(r) {
        var conv = r.conversation || {};
        var names = conv.participant_names || {};
        var otherName = '';
        Object.keys(names).forEach(function(k) { if (k !== CM.adminId) otherName = names[k]; });
        var ctx = conv.context || {};
        var headerExtra = ctx.student_id ? ' <span style="font-weight:400;font-size:12px;color:var(--t3)">(' + CM.esc(ctx.student_id) + ')</span>' : '';
        $('#chatHeader').html('<span>' + CM.esc(otherName) + headerExtra + '</span>');

        var msgs = r.messages || [];
        var html = '';
        if (!msgs.length) {
            html = '<div class="msg-empty"><i class="fa fa-comments-o"></i><span>No messages yet</span></div>';
        } else {
            msgs.forEach(function(m) {
                var isSent = m.sender_id === CM.adminId;
                html += '<div class="msg-bubble ' + (isSent ? 'sent' : 'received') + '">' +
                    (!isSent ? '<div style="font-size:11px;font-weight:700;margin-bottom:2px">' + CM.esc(m.sender_name) + '</div>' : '') +
                    CM.esc(m.message_text) +
                    '<div class="msg-bubble-meta">' + CM.esc((m.sent_at||'').substring(11,16)) + '</div>' +
                    '</div>';
            });
        }
        $('#chatBody').html(html);
        var body = document.getElementById('chatBody');
        body.scrollTop = body.scrollHeight;

        // Refresh sidebar to update unread
        MSG.loadConversations();
    });
};

MSG.sendMsg = function() {
    var text = $('#msgText').val().trim();
    if (!text || !MSG.activeConv) return;
    $('#msgText').val('');
    CM.ajax('communication/send_message', {conversation_id: MSG.activeConv, message: text}, function() {
        MSG.openConv(MSG.activeConv);
    }, 'POST');
};

MSG.openNewConv = function() { $('#newConvModal').addClass('show'); };
MSG.closeNewConv = function() { $('#newConvModal').removeClass('show'); $('#rcptSearch').val(''); $('#rcptResults').removeClass('show').html(''); $('#rcptSelected').hide(); $('#rcptId,#rcptRole,#rcptName').val(''); $('#newMsgText').val(''); };

MSG.searchTimer = null;
MSG._bindSearch = function() {
    $('#rcptSearch').on('input', function() {
        clearTimeout(MSG.searchTimer);
        var q = $(this).val().trim();
        if (q.length < 2) { $('#rcptResults').removeClass('show'); return; }
        MSG.searchTimer = setTimeout(function() {
            CM.ajax('communication/search_recipients', {q: q}, function(r) {
                var html = '';
                (r.recipients || []).forEach(function(rr) {
                    html += '<div class="rcpt-item" onclick="MSG.selectRecipient(\'' + CM.esc(rr.id) + '\',\'' + CM.esc(rr.role) + '\',\'' + CM.esc(rr.name) + '\')">' + CM.esc(rr.label) + '</div>';
                });
                if (!html) html = '<div style="padding:10px;color:var(--t3);font-size:12px">No results</div>';
                $('#rcptResults').html(html).addClass('show');
            });
        }, 300);
    });
};

MSG.selectRecipient = function(id, role, name) {
    $('#rcptId').val(id); $('#rcptRole').val(role); $('#rcptName').val(name);
    $('#rcptSelected').text(name + ' (' + id + ') - ' + role).show();
    $('#rcptResults').removeClass('show'); $('#rcptSearch').val('');
};

MSG.createConv = function() {
    var data = {
        recipient_id: $('#rcptId').val(),
        recipient_role: $('#rcptRole').val(),
        recipient_name: $('#rcptName').val(),
        student_id: $('#rcptStudentId').val().trim(),
        message: $('#newMsgText').val().trim()
    };
    if (!data.recipient_id) { CM.toast('Select a recipient', 'error'); return; }
    if (!data.message) { CM.toast('Enter a message', 'error'); return; }
    CM.ajax('communication/create_conversation', data, function(r) {
        CM.toast('Message sent');
        MSG.closeNewConv();
        MSG.activeConv = r.conversation_id;
        MSG.loadConversations();
        setTimeout(function(){ MSG.openConv(r.conversation_id); }, 500);
    }, 'POST');
};

document.addEventListener('DOMContentLoaded', function(){ MSG.loadConversations(); MSG._bindSearch(); });
</script>
