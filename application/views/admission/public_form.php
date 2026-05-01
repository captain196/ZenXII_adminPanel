<?php
defined('BASEPATH') or exit('No direct script access allowed');
// PUBLIC ADMISSION FIX — standalone public form (no header/footer chrome)

$displayName = $school_profile['display_name'] ?? $school_profile['school_name'] ?? $school_profile['name'] ?? $school_id;
$address     = $school_profile['address'] ?? '';
$logoUrl     = $school_profile['logo'] ?? $school_profile['logo_url'] ?? '';
$esc = function($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admission Form — <?= $esc($displayName) ?></title>
    <meta name="csrf-token" content="<?= $this->security->get_csrf_hash() ?>">
    <meta name="csrf-name"  content="<?= $this->security->get_csrf_token_name() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --gold:#0f766e; --gold2:#0d6b63; --gold3:#14b8a6;
            --gold-dim:rgba(15,118,110,.10); --gold-ring:rgba(15,118,110,.22);
            --bg:#f0f7f5; --bg2:#ffffff; --bg3:#e6f4f1;
            --border:rgba(15,118,110,.15);
            --t1:#0c1e38; --t2:#1a5c56; --t3:#5a9e98;
            --r:12px;
            --font-d:'Syne',sans-serif; --font-b:'Plus Jakarta Sans',sans-serif;
            --ease:.22s cubic-bezier(.4,0,.2,1);
        }
        * { box-sizing:border-box; margin:0; padding:0; }
        html { font-size:16px; }
        body { font-family:var(--font-b); background:var(--bg); color:var(--t1); min-height:100vh; }

        .pf-wrap { max-width:720px; margin:0 auto; padding:32px 20px 48px; }

        /* School header */
        .pf-school {
            text-align:center; margin-bottom:28px; padding:24px;
            background:var(--bg2); border:1px solid var(--border);
            border-radius:var(--r); box-shadow:0 2px 16px rgba(0,0,0,.06);
        }
        .pf-school img { height:60px; margin-bottom:12px; border-radius:8px; }
        .pf-school h1 { font-size:20px; font-weight:800; color:var(--gold); font-family:var(--font-d); margin-bottom:4px; }
        .pf-school p { font-size:13px; color:var(--t3); }

        /* Form card */
        .pf-card {
            background:var(--bg2); border:1px solid var(--border);
            border-radius:var(--r); padding:28px;
            box-shadow:0 2px 16px rgba(0,0,0,.06);
        }
        .pf-card h2 {
            font-size:16px; font-weight:700; color:var(--t1); font-family:var(--font-d);
            margin-bottom:20px; padding-bottom:12px; border-bottom:2px solid var(--gold);
            display:flex; align-items:center; gap:10px;
        }
        .pf-card h2 i { color:var(--gold); }

        .pf-section {
            font-size:13px; font-weight:700; color:var(--gold); text-transform:uppercase;
            letter-spacing:.4px; margin:22px 0 12px; padding-top:14px;
            border-top:1px solid var(--border);
            display:flex; align-items:center; gap:8px;
        }
        .pf-section i { font-size:14px; }

        .pf-grid { display:grid; grid-template-columns:1fr 1fr; gap:0 16px; }
        .pf-fg { display:flex; flex-direction:column; gap:4px; margin-bottom:14px; }
        .pf-fg label { font-size:11px; font-weight:600; color:var(--t3); text-transform:uppercase; letter-spacing:.4px; }
        .pf-fg .req { color:#ef4444; }
        .pf-fg input, .pf-fg select, .pf-fg textarea {
            padding:10px 12px; border:1px solid var(--border); border-radius:8px;
            background:#fff; color:var(--t1); font-size:14px; font-family:var(--font-b);
            outline:none; transition:border-color var(--ease), box-shadow var(--ease);
        }
        .pf-fg input:focus, .pf-fg select:focus, .pf-fg textarea:focus {
            border-color:var(--gold); box-shadow:0 0 0 3px var(--gold-ring);
        }
        .pf-fg textarea { resize:vertical; min-height:60px; }
        .pf-fg-full { grid-column:1/-1; }

        .pf-submit {
            width:100%; padding:14px; background:var(--gold); color:#fff; border:none;
            border-radius:8px; font-size:14px; font-weight:700; cursor:pointer;
            font-family:var(--font-b); transition:background var(--ease);
            display:flex; align-items:center; justify-content:center; gap:8px;
            margin-top:8px;
        }
        .pf-submit:hover { background:var(--gold2); }
        .pf-submit:disabled { opacity:.6; cursor:not-allowed; }

        .pf-alert { padding:12px 16px; border-radius:8px; font-size:13px; display:none; margin-bottom:16px; }
        .pf-alert-ok { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
        .pf-alert-err { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }

        .pf-success { display:none; text-align:center; padding:48px 24px; }
        .pf-success i { font-size:3.5rem; color:var(--gold); margin-bottom:16px; display:block; }
        .pf-success h2 { font-size:18px; font-weight:700; color:var(--t1); margin-bottom:8px; font-family:var(--font-d); }
        .pf-success p { color:var(--t3); font-size:14px; margin-bottom:4px; }
        .pf-success strong { color:var(--gold); font-family:'JetBrains Mono',monospace; }
        .pf-success button {
            margin-top:24px; padding:12px 28px; background:var(--gold); color:#fff;
            border:none; border-radius:8px; cursor:pointer; font-size:13px;
            font-weight:600; font-family:var(--font-b);
        }
        .pf-success button:hover { background:var(--gold2); }

        .pf-footer { text-align:center; margin-top:24px; font-size:11px; color:var(--t3); }

        @media(max-width:600px) { .pf-grid { grid-template-columns:1fr; } }
    </style>
    <!-- Razorpay Checkout SDK — loaded unconditionally so the page is
         ready to pop the checkout modal whenever the server returns a
         razorpay-gateway order. SDK silently no-ops if mock gateway. -->
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
</head>
<body>
<div class="pf-wrap">

    <!-- School Header -->
    <div class="pf-school">
        <?php if (!empty($logoUrl)): ?>
        <img src="<?= $esc($logoUrl) ?>" alt="School Logo">
        <?php endif; ?>
        <h1><?= $esc($displayName) ?></h1>
        <?php if (!empty($address)): ?>
        <p><?= $esc($address) ?></p>
        <?php endif; ?>
        <?php if (!empty($session_year)): ?>
        <p style="margin-top:4px;">Admission Form — Session <?= $esc($session_year) ?></p>
        <?php endif; ?>
    </div>

    <!-- Form Card -->
    <div class="pf-card" id="formCard">
        <h2><i class="fa-solid fa-user-plus"></i> Admission Application</h2>

        <div id="formAlert" class="pf-alert"></div>

        <form id="admissionForm" novalidate>

            <div class="pf-section"><i class="fa-solid fa-user"></i> Student Information</div>
            <div class="pf-grid">
                <div class="pf-fg">
                    <label>Student Name <span class="req">*</span></label>
                    <input type="text" name="student_name" required maxlength="100">
                </div>
                <div class="pf-fg">
                    <label>Class Applying For <span class="req">*</span></label>
                    <select name="class" required>
                        <option value="">-- Select Class --</option>
                        <?php foreach ($classes as $cls): ?>
                        <option value="<?= $esc(str_replace('Class ', '', $cls)) ?>"><?= $esc($cls) ?></option>
                        <?php endforeach; ?>
                        <?php if (empty($classes)): ?>
                        <option value="" disabled>No classes available</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="pf-fg">
                    <label>Date of Birth <span class="req">*</span></label>
                    <input type="date" name="dob" required>
                </div>
                <div class="pf-fg">
                    <label>Gender <span class="req">*</span></label>
                    <select name="gender" required>
                        <option value="">-- Select --</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="pf-fg">
                    <label>Blood Group</label>
                    <select name="blood_group">
                        <option value="">-- Select --</option>
                        <option value="A+">A+</option>
                        <option value="A-">A-</option>
                        <option value="B+">B+</option>
                        <option value="B-">B-</option>
                        <option value="AB+">AB+</option>
                        <option value="AB-">AB-</option>
                        <option value="O+">O+</option>
                        <option value="O-">O-</option>
                    </select>
                </div>
                <div class="pf-fg">
                    <label>Category</label>
                    <select name="category">
                        <option value="">-- Select --</option>
                        <option value="General">General</option>
                        <option value="OBC">OBC</option>
                        <option value="SC">SC</option>
                        <option value="ST">ST</option>
                        <option value="EWS">EWS</option>
                    </select>
                </div>
                <div class="pf-fg">
                    <label>Religion</label>
                    <input type="text" name="religion" maxlength="50">
                </div>
                <div class="pf-fg">
                    <label>Nationality</label>
                    <input type="text" name="nationality" maxlength="50" placeholder="e.g. Indian">
                </div>
            </div>

            <div class="pf-section"><i class="fa-solid fa-people-roof"></i> Parent / Guardian</div>
            <div class="pf-grid">
                <div class="pf-fg">
                    <label>Father's Name</label>
                    <input type="text" name="parent_name" maxlength="100">
                </div>
                <div class="pf-fg">
                    <label>Father's Occupation</label>
                    <input type="text" name="father_occupation" maxlength="100">
                </div>
                <div class="pf-fg">
                    <label>Mother's Name</label>
                    <input type="text" name="mother_name" maxlength="100">
                </div>
                <div class="pf-fg">
                    <label>Mother's Occupation</label>
                    <input type="text" name="mother_occupation" maxlength="100">
                </div>
                <div class="pf-fg">
                    <label>Phone <span class="req">*</span></label>
                    <input type="tel" name="phone" required maxlength="20" placeholder="e.g. 9876543210">
                </div>
                <div class="pf-fg">
                    <label>Email</label>
                    <input type="email" name="email" maxlength="150">
                </div>
                <div class="pf-fg">
                    <label>Guardian Phone (alternate)</label>
                    <input type="tel" name="guardian_phone" maxlength="20">
                </div>
                <div class="pf-fg">
                    <label>Guardian Relation</label>
                    <input type="text" name="guardian_relation" maxlength="50" placeholder="e.g. Uncle">
                </div>
            </div>

            <div class="pf-section"><i class="fa-solid fa-location-dot"></i> Address</div>
            <div class="pf-grid">
                <div class="pf-fg pf-fg-full">
                    <label>Street / Locality</label>
                    <input type="text" name="address" maxlength="200">
                </div>
                <div class="pf-fg">
                    <label>City</label>
                    <input type="text" name="city" maxlength="80">
                </div>
                <div class="pf-fg">
                    <label>State</label>
                    <input type="text" name="state" maxlength="80">
                </div>
                <div class="pf-fg">
                    <label>Pincode</label>
                    <input type="text" name="pincode" maxlength="10" pattern="[0-9]*">
                </div>
            </div>

            <div class="pf-section"><i class="fa-solid fa-graduation-cap"></i> Previous School (if any)</div>
            <div class="pf-grid">
                <div class="pf-fg">
                    <label>Previous School Name</label>
                    <input type="text" name="previous_school" maxlength="150">
                </div>
                <div class="pf-fg">
                    <label>Previous Class</label>
                    <input type="text" name="previous_class" maxlength="50">
                </div>
                <div class="pf-fg">
                    <label>Previous Marks / Grade</label>
                    <input type="text" name="previous_marks" maxlength="50" placeholder="e.g. 85% / A1">
                </div>
            </div>

            <div class="pf-fg">
                <label>Message / Additional Information</label>
                <textarea name="message" rows="3" maxlength="500" placeholder="Any additional details..."></textarea>
            </div>

            <!-- Consent — required by India's DPDP Act and GDPR. Without
                 explicit acknowledgement we shouldn't store the parent's
                 contact + child's PII. Server rejects submission if the
                 box isn't checked. -->
            <div class="pf-fg" style="background:#f8fafc;border:1px solid var(--border,#e5e7eb);border-radius:8px;padding:12px 14px;margin-top:8px;">
                <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-weight:400;">
                    <input type="checkbox" name="consent" id="consentCheck" required style="margin-top:3px;flex-shrink:0;">
                    <span style="font-size:13px;line-height:1.5;color:var(--t2,#374151);">
                        I confirm the information above is correct and consent to <strong><?= $esc($school_profile['display_name'] ?? $school_id) ?></strong> storing and processing this application data for admission purposes. I understand my contact details may be used to communicate about this application.
                    </span>
                </label>
            </div>

            <button type="submit" class="pf-submit" id="submitBtn">
                <i class="fa-solid fa-paper-plane"></i> Submit Application
            </button>
        </form>
    </div>

    <!-- Success Panel (shown after submission) -->
    <div class="pf-success" id="successPanel">
        <i class="fa-solid fa-circle-check"></i>
        <h2>Application Submitted!</h2>
        <p>Your application ID: <strong id="appIdDisplay"></strong></p>
        <p>The school will review your application and contact you shortly.</p>

        <!-- Receipt download (Tier-A QW #1). The link is populated by the
             submit-handler JS from data.receipt_url; same URL is also sent
             to the parent via the SMS notification. -->
        <div id="receiptSection" style="display:none;margin-top:20px;">
            <a id="receiptLink" target="_blank" rel="noopener"
               style="display:inline-block;padding:12px 22px;background:#0f766e;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;">
                <i class="fa-solid fa-file-pdf" style="margin-right:6px;"></i> Download Receipt (PDF)
            </a>
            <p style="font-size:12px;color:var(--t3);margin-top:8px;">
                A copy of this link has been sent to your phone via SMS.
            </p>
        </div>

        <!-- Payment button (shown only if school requires admission fee) -->
        <div id="paymentSection" style="display:none;margin-top:20px;">
            <div style="background:var(--bg3);padding:16px;border-radius:8px;border:1px solid var(--border);margin-bottom:16px;">
                <p style="font-size:13px;color:var(--t2);margin-bottom:8px;"><span id="payLabel">Admission fee</span> required:</p>
                <p style="font-size:1.5rem;font-weight:800;color:var(--gold);">&#8377;<span id="payAmount">0</span></p>
            </div>
            <button id="payBtn" class="pf-submit" style="background:#0f766e;max-width:320px;margin:0 auto;" onclick="initiatePayment()">
                <i class="fa-solid fa-credit-card"></i> Pay Now
            </button>
        </div>
        <button onclick="location.reload()" style="margin-top:16px;padding:10px 24px;background:var(--bg3);color:var(--t2);border:1px solid var(--border);border-radius:8px;cursor:pointer;font-size:13px;font-weight:600;">
            <i class="fa-solid fa-plus" style="margin-right:6px;"></i> Submit Another
        </button>
    </div>

    <!-- Payment Success Panel -->
    <div class="pf-success" id="paymentSuccessPanel" style="display:none;">
        <i class="fa-solid fa-check-circle" style="color:#22c55e;"></i>
        <h2>Payment Successful!</h2>
        <p>Your admission fee has been received.</p>
        <p style="margin-top:4px;">Application <strong id="paidAppId"></strong> is now <strong style="color:#22c55e;">Awaiting school review</strong>.</p>
        <p style="font-size:12px;color:var(--t3);margin-top:8px;">The school will verify your details and contact you with next steps. Please keep your application ID for reference.</p>
    </div>

    <div class="pf-footer">Powered by GraderIQ ERP</div>
</div>

<script>
// PUBLIC ADMISSION FIX — CSRF from meta tags + AJAX submission
var csrfName  = document.querySelector('meta[name="csrf-name"]').content;
var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

function showAlert(msg, isError) {
    var el = document.getElementById('formAlert');
    el.textContent = msg;
    el.className = 'pf-alert ' + (isError ? 'pf-alert-err' : 'pf-alert-ok');
    el.style.display = 'block';
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

document.getElementById('admissionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
    document.getElementById('formAlert').style.display = 'none';

    var fd = new FormData(this);
    fd.append(csrfName, csrfToken);

    fetch('<?= base_url("admission/submit/" . urlencode($school_id)) ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: new URLSearchParams(fd).toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        // Refresh CSRF token from server response
        if (data.csrf_token) csrfToken = data.csrf_token;
        if (data.status === 'success') {
            document.getElementById('formCard').style.display = 'none';
            document.getElementById('successPanel').style.display = 'block';
            document.getElementById('appIdDisplay').textContent = data.app_id || 'Submitted';
            // Store for payment flow
            window._appId = data.app_id;
            // Receipt link — Tier-A QW #1. Hidden by default; revealed when
            // the server returns a receipt_url (always returned for new
            // submissions; absent on legacy responses to keep this safe).
            if (data.receipt_url) {
                document.getElementById('receiptLink').setAttribute('href', data.receipt_url);
                document.getElementById('receiptSection').style.display = 'block';
            }
            // Show payment button if required
            if (data.payment_required && data.payment_amount > 0) {
                document.getElementById('paymentSection').style.display = 'block';
                document.getElementById('payAmount').textContent = parseFloat(data.payment_amount).toLocaleString('en-IN');
                if (data.payment_label) {
                    document.getElementById('payLabel').textContent = data.payment_label;
                }
            }
        } else {
            showAlert(data.message || 'Submission failed. Please try again.', true);
        }
    })
    .catch(function() {
        showAlert('Network error. Please check your connection and try again.', true);
    })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Application';
    });
});

// ── Payment Flow ──────────────────────────────────────────────────────
function initiatePayment() {
    var btn = document.getElementById('payBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';

    var fd = new URLSearchParams({ app_id: window._appId });
    fd.append(csrfName, csrfToken);

    fetch('<?= base_url("admission/pay/" . urlencode($school_id)) ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: fd.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(order) {
        // Refresh CSRF token from server response
        if (order.csrf_token) csrfToken = order.csrf_token;
        if (order.status !== 'success') {
            alert(order.message || 'Payment initiation failed.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-credit-card"></i> Pay Now';
            return;
        }

        // ── Gateway checkout ──
        if (order.gateway === 'razorpay') {
            launchRazorpay(order);
        } else if (order.gateway === 'mock') {
            simulateMockPayment(order);
        } else {
            alert('Gateway ' + order.gateway + ' not yet integrated.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-credit-card"></i> Pay Now';
        }
    })
    .catch(function() {
        alert('Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-credit-card"></i> Pay Now';
    });
}

function launchRazorpay(order) {
    if (typeof Razorpay === 'undefined') {
        alert('Razorpay SDK failed to load. Check your internet connection and try again.');
        var btn = document.getElementById('payBtn');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-credit-card"></i> Pay Now';
        return;
    }

    var rzp = new Razorpay({
        key:      order.key,                 // rzp_test_... from server
        amount:   order.amount_paise,        // integer paise
        currency: order.currency || 'INR',
        name:     order.school_name || 'Admission Fee',
        description: 'Admission Fee · ' + (order.student_name || ''),
        order_id: order.order_id,
        prefill: {
            name:    order.student_name || '',
            email:   order.email        || '',
            contact: order.phone        || ''
        },
        notes: {
            app_id:    window._appId,
            payment_id: order.payment_id
        },
        theme: { color: '#0f766e' },
        // Razorpay calls this on success. We then verify the signature
        // server-side via payment_callback — never trust the client.
        handler: function(response) {
            var fd = new URLSearchParams({
                payment_id:         order.payment_id,
                order_id:           order.order_id,
                gateway_payment_id: response.razorpay_payment_id || '',
                signature:          response.razorpay_signature  || ''
            });
            fd.append(csrfName, csrfToken);

            fetch('<?= base_url("admission/payment_callback/" . urlencode($school_id)) ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body: fd.toString()
            })
            .then(function(r) { return r.json(); })
            .then(function(result) {
                if (result.csrf_token) csrfToken = result.csrf_token;
                if (result.status === 'success') {
                    document.getElementById('successPanel').style.display = 'none';
                    document.getElementById('paymentSuccessPanel').style.display = 'block';
                    document.getElementById('paidAppId').textContent = window._appId;
                } else {
                    alert('Payment verification failed: ' + (result.message || 'Unknown error'));
                    var btn = document.getElementById('payBtn');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-credit-card"></i> Retry Payment';
                }
            })
            .catch(function() {
                alert('Payment verification failed. Please contact the school.');
            });
        },
        modal: {
            // Parent dismissed the Razorpay modal without paying. Restore
            // the Pay Now button so they can try again. The server-side
            // admissionPayments doc stays in `created` state, harmless.
            ondismiss: function() {
                var btn = document.getElementById('payBtn');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-credit-card"></i> Pay Now';
            }
        }
    });

    rzp.on('payment.failed', function(resp) {
        alert('Payment failed: ' + (resp.error && resp.error.description ? resp.error.description : 'Unknown error'));
        var btn = document.getElementById('payBtn');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-credit-card"></i> Retry Payment';
    });

    rzp.open();
}

function simulateMockPayment(order) {
    // Simulate gateway processing delay (UX: shows "Processing..." briefly)
    setTimeout(function() {
        // MOCK GATEWAY NOTE: The frontend does NOT generate real signatures.
        // The backend (payment_callback) detects gateway='mock' and calls
        // simulate_payment() server-side to generate its own HMAC signature.
        // These placeholder values are sent only to satisfy the POST schema;
        // the server overwrites them entirely in mock mode.
        var fd = new URLSearchParams({
            payment_id: order.payment_id,
            order_id: order.order_id,
            gateway_payment_id: 'mock_placeholder',
            signature: 'mock_placeholder'
        });
        fd.append(csrfName, csrfToken);

        fetch('<?= base_url("admission/payment_callback/" . urlencode($school_id)) ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: fd.toString()
        })
        .then(function(r) { return r.json(); })
        .then(function(result) {
            // Refresh CSRF token from server response
            if (result.csrf_token) csrfToken = result.csrf_token;
            if (result.status === 'success') {
                document.getElementById('successPanel').style.display = 'none';
                document.getElementById('paymentSuccessPanel').style.display = 'block';
                document.getElementById('paidAppId').textContent = window._appId;
            } else {
                alert('Payment verification failed: ' + (result.message || 'Unknown error'));
                var btn = document.getElementById('payBtn');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-credit-card"></i> Retry Payment';
            }
        })
        .catch(function() {
            alert('Payment verification failed. Please contact the school.');
        });
    }, 1500);
}
</script>
</body>
</html>
