<?php
defined('BASEPATH') or exit('No direct script access allowed');
// PUBLIC ADMISSION — standalone parent-facing portal. Full UX redesign
// (presentation only): guided multi-step application with a trust-first hero,
// progressive disclosure, friendly validation, a real loading state and a
// celebratory success moment. Backend contract is preserved exactly — every
// field name, every JS-referenced id, the submit/payment/panel flow and all
// server-side validation are unchanged.

$displayName = $school_profile['display_name'] ?? $school_profile['school_name'] ?? $school_profile['name'] ?? $school_id;
$address     = $school_profile['address'] ?? '';
$logoUrl     = $school_profile['logo'] ?? $school_profile['logo_url'] ?? '';
$schoolPhone = $school_profile['phone'] ?? $school_profile['contact'] ?? '';
$schoolEmail = $school_profile['email'] ?? '';
$esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$monogram = strtoupper(mb_substr(trim((string)$displayName), 0, 1) ?: 'S');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#BC5A3C">
    <title>Admissions — <?= $esc($displayName) ?></title>
    <link rel="icon" type="image/png" href="<?= base_url('Designs/favicon.png?v=2') ?>">
    <link rel="apple-touch-icon" href="<?= base_url('Designs/favicon.png?v=2') ?>">
    <meta name="csrf-token" content="<?= $this->security->get_csrf_hash() ?>">
    <meta name="csrf-name"  content="<?= $this->security->get_csrf_token_name() ?>">
    <!-- External fonts/icons load NON-BLOCKING (media=print → all on load).
         Critical for institutional networks (schools/colleges) that block or
         throttle CDNs: a hanging stylesheet would otherwise defer the inline
         wizard <script> below and leave the "Continue" button dead. With this,
         the form's JS runs instantly and the page just falls back to system
         fonts / hidden icon glyphs if the CDNs are unreachable. -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Syne:wght@600;700;800&display=swap" media="print" onload="this.media='all'">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Syne:wght@600;700;800&display=swap">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    </noscript>
    <style>
        :root{
            --teal:#BC5A3C; --teal-d:#9E4830; --teal-x:#7A3826; --mint:#D4725C;
            --tint:rgba(188,90,60,.07); --tint2:rgba(188,90,60,.12); --ring:rgba(188,90,60,.22);
            --bg1:#eef5f3; --bg2:#e4efec; --card:#ffffff; --panel:#f6faf9;
            --line:#dde9e6; --line2:#eaf2f0;
            --ink:#0c211d; --ink2:#3f5a55; --ink3:#77918c; --ink4:#a6bdb8;
            --ok:#16a34a; --err:#dc2626;
            --r:18px; --rs:12px;
            --fb:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
            --fd:'Syne',var(--fb); --mono:ui-monospace,'SF Mono',Menlo,monospace;
            --ease:.24s cubic-bezier(.4,0,.2,1);
            --sh-sm:0 1px 2px rgba(10,40,36,.06);
            --sh:0 14px 40px -16px rgba(10,40,36,.22),0 2px 8px rgba(10,40,36,.05);
        }
        *{box-sizing:border-box;margin:0;padding:0}
        html{font-size:16px;-webkit-text-size-adjust:100%;scroll-behavior:smooth}
        body{font-family:var(--fb);color:var(--ink);line-height:1.5;min-height:100vh;-webkit-font-smoothing:antialiased;
            background:
              radial-gradient(1100px 460px at 50% -8%, rgba(188,90,60,.10), transparent 60%),
              linear-gradient(180deg,var(--bg1),var(--bg2));
            background-attachment:fixed}

        .wrap{max-width:660px;margin:0 auto;padding:26px 16px 120px}
        @media(min-width:768px){.wrap{padding:44px 24px 72px}}
        .card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--sh)}

        /* ── HERO ── */
        .hero{padding:34px 26px 28px;text-align:center;margin-bottom:16px;position:relative;overflow:hidden}
        .hero-glow{position:absolute;inset:-40% 0 auto 0;height:220px;pointer-events:none;
            background:radial-gradient(closest-side,rgba(45,212,191,.20),transparent);opacity:.7}
        .brand{position:relative;width:78px;height:78px;border-radius:20px;margin:0 auto 16px;object-fit:cover;
            box-shadow:0 10px 26px -8px rgba(10,40,36,.4),0 0 0 1px var(--line)}
        .mono{position:relative;width:78px;height:78px;border-radius:20px;margin:0 auto 16px;
            background:linear-gradient(150deg,var(--teal),var(--teal-x));color:#fff;font-family:var(--fd);
            font-weight:800;font-size:34px;display:flex;align-items:center;justify-content:center;
            box-shadow:0 10px 26px -8px rgba(10,40,36,.45)}
        .pills{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-bottom:14px;position:relative}
        .pill{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:700;padding:5px 11px;border-radius:999px;letter-spacing:.2px}
        .pill.live{background:#dcfce7;color:#15803d}
        .pill.live .dot{width:7px;height:7px;border-radius:50%;background:#16a34a;box-shadow:0 0 0 0 rgba(22,163,74,.5);animation:ping 1.8s infinite}
        @keyframes ping{0%{box-shadow:0 0 0 0 rgba(22,163,74,.45)}70%{box-shadow:0 0 0 7px rgba(22,163,74,0)}100%{box-shadow:0 0 0 0 rgba(22,163,74,0)}}
        .pill.sess{background:var(--tint);color:var(--teal-d)}
        .hero h1{position:relative;font-family:var(--fd);font-size:26px;font-weight:800;color:var(--teal-d);letter-spacing:-.4px;margin-bottom:6px;text-wrap:balance}
        @media(min-width:768px){.hero h1{font-size:31px}}
        .hero .addr{position:relative;font-size:13.5px;color:var(--ink3);display:inline-flex;gap:5px;align-items:center;margin-bottom:16px}
        .hero .addr i{color:var(--mint)}
        .hero .lede{position:relative;font-size:14.5px;color:var(--ink2);max-width:440px;margin:0 auto 18px;text-wrap:pretty}
        .hero .lede b{color:var(--ink)}
        .trust{position:relative;display:flex;gap:8px;justify-content:center;flex-wrap:wrap;padding-top:16px;border-top:1px solid var(--line2)}
        .trust span{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:var(--ink2);background:var(--panel);border:1px solid var(--line);border-radius:999px;padding:6px 12px}
        .trust span i{color:var(--teal)}

        /* ── PROGRESS ── */
        .prog{padding:16px 20px;margin-bottom:16px;position:relative}
        .prog-top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:12px}
        .prog-top .now{font-family:var(--fd);font-size:14px;font-weight:700;color:var(--ink)}
        .prog-top .cnt{font-size:12px;font-weight:600;color:var(--ink3);font-variant-numeric:tabular-nums}
        .bar{height:7px;border-radius:99px;background:var(--line);overflow:hidden}
        .bar-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,var(--teal),var(--mint));width:20%;transition:width .45s var(--ease)}
        .dots{display:flex;justify-content:space-between;margin-top:12px}
        .dots .d{display:flex;flex-direction:column;align-items:center;gap:5px;flex:1;font-size:10.5px;font-weight:600;color:var(--ink4)}
        .dots .d .n{width:24px;height:24px;border-radius:50%;background:#fff;border:2px solid var(--line);color:var(--ink4);
            font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;transition:var(--ease)}
        .dots .d.cur .n{background:var(--teal);border-color:var(--teal);color:#fff;box-shadow:0 0 0 4px var(--ring)}
        .dots .d.cur{color:var(--teal-d)}
        .dots .d.done .n{background:var(--mint);border-color:var(--mint);color:#06403a}
        @media(max-width:560px){.dots .d span{display:none}}

        /* ── FORM ── */
        .form-card{padding:26px 20px;position:relative;overflow:hidden}
        @media(min-width:768px){.form-card{padding:32px 34px}}
        .pf-alert{display:none;align-items:center;gap:10px;padding:13px 15px;border-radius:var(--rs);font-size:13.5px;font-weight:500;margin-bottom:20px}
        .pf-alert i{font-size:15px;flex-shrink:0}
        .pf-alert-err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
        .pf-alert-ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}

        .pf-step{display:none}
        .pf-step.active{display:block;animation:slidein .32s var(--ease)}
        @keyframes slidein{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
        .shead{margin-bottom:22px}
        .shead .kick{display:inline-flex;align-items:center;gap:8px;font-size:11.5px;font-weight:700;letter-spacing:.5px;
            text-transform:uppercase;color:var(--teal);background:var(--tint);border-radius:999px;padding:5px 11px;margin-bottom:12px}
        .shead h2{font-family:var(--fd);font-size:21px;font-weight:700;color:var(--ink);letter-spacing:-.2px;margin-bottom:4px;text-wrap:balance}
        .shead p{font-size:13.5px;color:var(--ink3);text-wrap:pretty}

        .grid{display:grid;grid-template-columns:1fr;gap:18px}
        @media(min-width:600px){.grid{grid-template-columns:1fr 1fr}}
        .fg{display:flex;flex-direction:column;gap:7px}
        .fg.full{grid-column:1/-1}
        .fg label{font-size:13px;font-weight:600;color:var(--ink)}
        .fg label .req{color:var(--err);font-weight:700}
        .fg .hint{font-size:12px;color:var(--ink3);display:flex;align-items:center;gap:5px}
        .fg .hint i{color:var(--mint);font-size:11px}
        .fg input,.fg select,.fg textarea{
            width:100%;min-height:52px;padding:14px 15px;border:1.5px solid var(--line);border-radius:var(--rs);
            background:var(--panel);color:var(--ink);font-size:16px;font-family:var(--fb);outline:none;
            transition:border-color var(--ease),box-shadow var(--ease),background var(--ease)}
        .fg select{appearance:none;-webkit-appearance:none;cursor:pointer;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='none' stroke='%2377918c' stroke-width='2.2'%3E%3Cpath d='M2 4l4 4 4-4'/%3E%3C/svg%3E");
            background-repeat:no-repeat;background-position:right 15px center;padding-right:40px}
        .fg textarea{min-height:96px;resize:vertical}
        .fg input:focus,.fg select:focus,.fg textarea:focus{border-color:var(--teal);background:#fff;box-shadow:0 0 0 4px var(--ring)}
        .fg input::placeholder,.fg textarea::placeholder{color:var(--ink4)}
        .fg .pf-invalid{border-color:var(--err)!important;background:#fff6f6;box-shadow:0 0 0 4px rgba(220,38,38,.13)}
        .pf-err{display:none;font-size:12px;color:var(--err);font-weight:600;align-items:center;gap:5px}
        .pf-err::before{content:"\f06a";font-family:"Font Awesome 6 Free";font-weight:900;font-size:11px}
        @keyframes shake{0%,100%{transform:translateX(0)}18%,54%{transform:translateX(-6px)}36%,72%{transform:translateX(6px)}88%{transform:translateX(-2px)}}
        .pf-shake{animation:shake .42s cubic-bezier(.36,.07,.19,.97)}

        /* file uploads */
        .pf-uphint{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--ink2);background:var(--tint);border:1px solid var(--line);border-radius:var(--rs);padding:10px 13px;margin-bottom:18px}
        .pf-uphint i{color:var(--teal)}
        .pf-file-input{position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;clip:rect(0 0 0 0)}
        .pf-file{display:flex;align-items:center;gap:12px;width:100%;min-height:56px;padding:10px 12px;border:1.5px dashed var(--line);border-radius:var(--rs);
            background:var(--panel);cursor:pointer;transition:var(--ease)}
        .pf-file:hover{border-color:var(--teal);background:#fff}
        .pf-file-ico{width:38px;height:38px;flex:0 0 auto;border-radius:10px;background:var(--tint);color:var(--teal);display:flex;align-items:center;justify-content:center;font-size:16px}
        .pf-file-txt{flex:1;font-size:14px;color:var(--ink3);word-break:break-word;line-height:1.35}
        .pf-file.has-file{border-style:solid;border-color:var(--mint);background:#fff}
        .pf-file.has-file .pf-file-txt{color:var(--ink);font-weight:600}
        .pf-file.has-file .pf-file-ico{background:#dcfce7;color:var(--ok)}
        .pf-file-btn{flex:0 0 auto;font-size:12.5px;font-weight:700;color:var(--teal-d);background:#fff;border:1px solid var(--line);border-radius:999px;padding:7px 14px}
        .pf-file:focus-within{outline:3px solid var(--ring);outline-offset:2px}
        .pf-file.pf-file-invalid{border-color:var(--err);background:#fff6f6}
        .pf-file.pf-file-invalid .pf-file-ico{background:#fee2e2;color:var(--err)}
        .pf-file-title{font-size:13px;font-weight:600;color:var(--ink)}
        /* previews */
        .pf-preview{display:none;flex-wrap:wrap;gap:12px;margin-top:12px}
        .pf-pv{width:82px;position:relative}
        .pf-pv img,.pf-pv .pf-pv-ico{width:82px;height:82px;border-radius:12px;border:1px solid var(--line);display:flex;align-items:center;justify-content:center;object-fit:cover;background:var(--panel)}
        .pf-pv .pf-pv-ico{font-size:30px;color:var(--teal)}
        .pf-pv .pf-pv-cap{display:block;font-size:11px;color:var(--ink3);margin-top:5px;max-width:82px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:center}
        .pf-pv .pf-pv-x{position:absolute;top:-7px;right:-7px;width:22px;height:22px;border-radius:50%;background:#fff;border:1px solid var(--line);color:var(--err);font-size:11px;line-height:1;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:var(--sh-sm)}
        .pf-pv .pf-pv-x:hover{background:#fee2e2;border-color:#fecaca}
        #pfReviewAtt{margin-bottom:20px}
        .pf-review-att-h{font-size:13px;font-weight:600;color:var(--ink);margin-bottom:10px}

        /* review */
        .review{background:var(--panel);border:1px solid var(--line);border-radius:var(--rs);padding:6px 18px;margin-bottom:20px}
        .rrow{display:flex;justify-content:space-between;gap:14px;padding:12px 0;border-bottom:1px solid var(--line2);font-size:14px}
        .rrow:last-child{border-bottom:none}
        .rrow .k{color:var(--ink3)}
        .rrow .v{color:var(--ink);font-weight:600;text-align:right;word-break:break-word}
        .consent{display:flex;align-items:flex-start;gap:12px;background:var(--panel);border:1px solid var(--line);border-radius:var(--rs);padding:16px;cursor:pointer;margin-bottom:12px;transition:var(--ease)}
        .consent:hover{border-color:var(--teal-l)}
        .consent input{width:22px;height:22px;min-height:0;margin-top:1px;flex-shrink:0;accent-color:var(--teal);cursor:pointer}
        .consent span{font-size:13.5px;line-height:1.55;color:var(--ink2)}
        .protect{display:flex;align-items:center;justify-content:center;gap:8px;font-size:12.5px;color:var(--ink3);margin-top:16px;text-align:center;text-wrap:balance}
        .protect i{color:var(--ok)}

        /* buttons */
        .btn{min-height:54px;padding:15px 24px;border-radius:14px;font-size:15.5px;font-weight:700;cursor:pointer;font-family:var(--fb);
            display:inline-flex;align-items:center;justify-content:center;gap:10px;border:1.5px solid transparent;transition:var(--ease)}
        .btn:focus-visible{outline:3px solid var(--ring);outline-offset:2px}
        .btn:active{transform:translateY(1px)}
        .btn-p{background:linear-gradient(180deg,var(--teal),var(--teal-d));color:#fff;box-shadow:0 10px 22px -10px rgba(188,90,60,.7)}
        .btn-p:hover{filter:brightness(1.06)}
        .btn-p:disabled{opacity:.65;cursor:not-allowed;box-shadow:none;filter:none}
        .btn-g{background:#fff;color:var(--ink2);border-color:var(--line)}
        .btn-g:hover{background:var(--panel);border-color:var(--teal);color:var(--teal-d)}
        .btn-full{width:100%}

        /* desktop nav (in-card) */
        .nav{display:none;gap:12px;margin-top:28px;padding-top:22px;border-top:1px solid var(--line2)}
        .nav .btn-back{flex:0 0 auto;min-width:120px}
        .nav .btn-fwd{flex:1}
        @media(min-width:768px){.nav{display:flex}}
        /* mobile sticky action bar */
        .abar{position:fixed;left:0;right:0;bottom:0;z-index:40;background:rgba(255,255,255,.92);backdrop-filter:blur(10px);
            border-top:1px solid var(--line);padding:12px 16px calc(12px + env(safe-area-inset-bottom));display:flex;gap:10px;box-shadow:0 -6px 20px -12px rgba(10,40,36,.25)}
        .abar .btn-back{flex:0 0 auto;min-width:96px}
        .abar .btn-fwd{flex:1}
        @media(min-width:768px){.abar{display:none}}

        /* loading */
        .pf-loading{display:none;position:absolute;inset:0;z-index:10;background:rgba(255,255,255,.94);
            flex-direction:column;align-items:center;justify-content:center;gap:16px;text-align:center;padding:40px}
        .pf-loading.on{display:flex}
        .spinner{width:46px;height:46px;border-radius:50%;border:4px solid var(--tint2);border-top-color:var(--teal);animation:spin .8s linear infinite}
        @keyframes spin{to{transform:rotate(360deg)}}
        .pf-loading h3{font-family:var(--fd);font-size:17px;font-weight:700;color:var(--ink)}
        .pf-loading p{font-size:13px;color:var(--ink3)}

        /* panels */
        .panel{display:none;padding:44px 24px;text-align:center;margin-bottom:16px}
        @media(min-width:768px){.panel{padding:54px 40px}}
        .badge-ico{width:90px;height:90px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:42px;margin:0 auto 22px}
        .badge-ok{background:#dcfce7;color:var(--ok);animation:pop .5s cubic-bezier(.34,1.56,.64,1)}
        .badge-pay{background:var(--tint);color:var(--teal)}
        @keyframes pop{0%{transform:scale(.5);opacity:0}100%{transform:scale(1);opacity:1}}
        .panel h2{font-family:var(--fd);font-size:23px;font-weight:700;color:var(--ink);letter-spacing:-.2px;margin-bottom:8px;text-wrap:balance}
        .panel .sub{font-size:14.5px;color:var(--ink2);margin-bottom:4px;text-wrap:pretty}
        .idbox{background:var(--panel);border:1.5px dashed var(--mint);border-radius:var(--rs);padding:15px 18px;margin:20px auto;max-width:340px}
        .idbox .k{font-size:11.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--ink3);margin-bottom:4px}
        .idbox .v{font-size:23px;font-weight:800;color:var(--teal-d);font-family:var(--mono);letter-spacing:.5px}
        .amount{font-size:2.1rem;font-weight:800;color:var(--teal-d)}
        .warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;padding:12px 15px;border-radius:var(--rs);font-size:12.5px;margin:0 auto 16px;max-width:400px;display:flex;gap:9px;align-items:flex-start;text-align:left}
        .timeline{text-align:left;max-width:430px;margin:8px auto 0}
        .timeline .ti{display:flex;gap:14px;position:relative;padding-bottom:16px}
        .timeline .ti:not(:last-child)::before{content:"";position:absolute;left:13px;top:28px;bottom:0;width:2px;background:var(--line)}
        .timeline .ti .num{width:28px;height:28px;border-radius:50%;background:var(--tint);color:var(--teal);flex-shrink:0;
            display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;position:relative;z-index:1}
        .timeline .ti .tt{font-size:13.5px;color:var(--ink);font-weight:600}
        .timeline .ti .td{font-size:12.5px;color:var(--ink3)}
        .actions{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-top:22px}
        .actions .btn{flex:0 1 auto;min-width:150px}
        .note{font-size:12.5px;color:var(--ink3);margin-top:14px}
        .contact{font-size:12.5px;color:var(--ink3);margin-top:20px;padding-top:16px;border-top:1px solid var(--line2)}
        .contact a{color:var(--teal-d);text-decoration:none;font-weight:600}

        .foot{text-align:center;margin-top:22px;font-size:11.5px;color:var(--ink3)}
        .foot .s{display:inline-flex;align-items:center;gap:6px;margin-bottom:5px}
        .foot .s i{color:var(--ok)}

        @media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important;scroll-behavior:auto}}
        /* ── Printable fee receipt (hidden on screen; shown only when printing) ── */
        #pfReceipt{display:none;color:#12201d;font-family:var(--fb)}
        .rc-frame{border:1.5px solid var(--teal);border-radius:6px;position:relative;overflow:hidden;padding:22px 26px 20px}
        .rc-frame::after{content:"";position:absolute;inset:5px;border:1px solid #c9d8d4;border-radius:3px;pointer-events:none}
        .rc-wm{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;pointer-events:none;z-index:0}
        .rc-wm span{font-family:var(--fd);font-size:110px;font-weight:800;color:rgba(188,90,60,.05);letter-spacing:4px;transform:rotate(-18deg);white-space:nowrap}
        .rc-head{display:flex;align-items:center;gap:15px;position:relative;z-index:1}
        .rc-logo{width:60px;height:60px;object-fit:cover;border:1px solid #c9d8d4;border-radius:8px;flex:0 0 auto}
        .rc-mono{width:60px;height:60px;flex:0 0 auto;border-radius:8px;background:linear-gradient(150deg,var(--teal),var(--teal-x));color:#fff;font-family:var(--fd);font-weight:800;font-size:27px;display:flex;align-items:center;justify-content:center}
        .rc-school{font-family:var(--fd);font-size:21px;font-weight:800;color:var(--teal-d);letter-spacing:-.3px;line-height:1.1}
        .rc-addr{font-size:11.5px;color:#33473f;margin-top:3px}
        .rc-contact{font-size:11.5px;color:#5a716c;margin-top:1px}
        .rc-titlebar{background:var(--teal);color:#fff;text-align:center;font-family:var(--fd);font-weight:700;font-size:13px;letter-spacing:2px;text-transform:uppercase;padding:7px;border-radius:5px;margin:14px 0 12px;position:relative;z-index:1}
        .rc-meta{display:flex;flex-wrap:wrap;gap:6px 22px;font-size:12px;color:#33473f;margin-bottom:12px;position:relative;z-index:1}
        .rc-meta b{color:#12201d;font-family:var(--mono);font-weight:700}
        .rc-party{width:100%;border:1px solid #c9d8d4;border-radius:6px;border-collapse:separate;border-spacing:0;overflow:hidden;margin-bottom:14px;position:relative;z-index:1}
        .rc-party td{padding:8px 12px;font-size:12.5px;border-bottom:1px solid #e3edea;vertical-align:top}
        .rc-party tr:last-child td{border-bottom:none}
        .rc-party .pk{color:#5a716c;font-weight:600;width:16%;white-space:nowrap;background:#eaf5f2}
        .rc-party .pv{color:#12201d;font-weight:600;width:34%}
        .rc-tbl{width:100%;border-collapse:collapse;border:1px solid #c9d8d4;position:relative;z-index:1;font-variant-numeric:tabular-nums}
        .rc-tbl th{background:var(--teal);color:#fff;font-size:11px;letter-spacing:.6px;text-transform:uppercase;padding:9px 12px;text-align:left}
        .rc-tbl th.amt,.rc-tbl td.amt{text-align:right;width:150px;font-family:var(--mono)}
        .rc-tbl td{padding:10px 12px;border-bottom:1px solid #e3edea;font-size:12.5px;color:#12201d}
        .rc-tbl tbody tr:last-child td{border-bottom:none}
        /* totals summary — sits on the RIGHT side of the receipt */
        .rc-totals{width:58%;border:1px solid #c9d8d4;border-radius:6px;overflow:hidden;margin:14px 0 12px auto;position:relative;z-index:1;font-variant-numeric:tabular-nums}
        .rc-totals .rc-tr{display:flex;justify-content:space-between;gap:12px;padding:7px 14px;font-size:12.5px;border-top:1px solid #e3edea}
        .rc-totals .rc-tr:first-child{border-top:none}
        .rc-totals .rc-tr .lbl{color:#5a716c;font-weight:600}
        .rc-totals .rc-tr .val{font-weight:700;color:#12201d}
        .rc-totals .rc-tr.net{background:#f0fdfa;border-top:none;padding:11px 14px}
        .rc-totals .rc-tr.net .lbl{color:var(--teal-d);font-weight:800;text-transform:uppercase;letter-spacing:.4px;font-size:12px}
        .rc-totals .rc-tr.net .val{color:var(--teal-d);font-family:var(--fd);font-size:16px;font-weight:800}
        .rc-words{font-size:12px;color:#33473f;border:1px dashed #c9d8d4;border-left:3px solid var(--mint);border-radius:0 6px 6px 0;padding:9px 13px;margin:14px 0 12px;position:relative;z-index:1}
        .rc-words b{color:#12201d}
        .rc-payrow{display:flex;flex-wrap:wrap;gap:8px 26px;font-size:12px;color:#33473f;margin-bottom:22px;position:relative;z-index:1}
        .rc-payrow b{color:#12201d}
        .rc-payrow .mono{font-family:var(--mono);font-weight:700}
        .rc-stamp{position:absolute;top:118px;right:34px;transform:rotate(-14deg);border:3px solid;border-radius:8px;padding:4px 14px;font-family:var(--fd);font-weight:800;font-size:22px;letter-spacing:2px;text-transform:uppercase;opacity:.85;z-index:2}
        .rc-stamp.paid{color:#15803d;border-color:#15803d}
        .rc-stamp.due{color:#b45309;border-color:#b45309}
        .rc-sign{display:flex;justify-content:space-between;gap:40px;margin:24px 0 14px;position:relative;z-index:1}
        .rc-sign.rc-sign-one{justify-content:flex-end}
        .rc-sign.rc-sign-one .rc-sign-box{flex:0 0 260px}
        .rc-sign-box{flex:1;font-size:11px;color:#5a716c;text-align:center}
        .rc-sign-line{border-top:1px solid #9fb2ad;margin-bottom:6px;height:40px}
        .rc-foot{text-align:center;font-size:10px;color:#8aa09b;border-top:1px dashed #c9d8d4;padding-top:9px;position:relative;z-index:1}
        @media print{
          @page{size:A4 portrait;margin:12mm}
          body{background:#fff !important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
          .wrap,#pfAbar{display:none !important}
          #pfReceipt{display:block !important}
        }
    </style>
    <!-- Razorpay checkout is loaded LAZILY (see ensureRazorpay) only when the
         parent actually chooses "Pay Online" — never as a blocking head script.
         This keeps the form fully functional on networks that block razorpay.com. -->
</head>
<body>
<div class="wrap">

    <!-- ── HERO ── -->
    <header class="card hero">
        <div class="hero-glow"></div>
        <?php if (!empty($logoUrl)): ?>
            <img class="brand" src="<?= $esc($logoUrl) ?>" alt="<?= $esc($displayName) ?> logo">
        <?php else: ?>
            <div class="mono" aria-hidden="true"><?= $esc($monogram) ?></div>
        <?php endif; ?>
        <div class="pills">
            <span class="pill live"><span class="dot"></span> Admissions Open</span>
            <?php if (!empty($session_year)): ?><span class="pill sess"><i class="fa-solid fa-calendar-day"></i> Session <?= $esc($session_year) ?></span><?php endif; ?>
        </div>
        <h1><?= $esc($displayName) ?></h1>
        <?php if (!empty($address)): ?><div class="addr"><i class="fa-solid fa-location-dot"></i> <?= $esc($address) ?></div><?php endif; ?>
        <p class="lede">Welcome — we're glad you're considering <b><?= $esc($displayName) ?></b>. Complete the short application below to begin your child's admission.</p>
        <div class="trust">
            <span><i class="fa-solid fa-clock"></i> Takes ~3 minutes</span>
            <span><i class="fa-solid fa-user-slash"></i> No account needed</span>
            <span><i class="fa-solid fa-lock"></i> Secure &amp; private</span>
        </div>
    </header>

    <!-- ── PROGRESS ── -->
    <div class="card prog" id="progWrap" role="group" aria-label="Application progress">
        <div class="prog-top">
            <span class="now" id="pfStepName">Student Information</span>
            <span class="cnt">Step <span id="pfStepNow">1</span> of 6</span>
        </div>
        <div class="bar"><div class="bar-fill" id="pfTrackFill"></div></div>
        <div class="dots">
            <div class="d" data-step="1"><span class="n">1</span><span>Student</span></div>
            <div class="d" data-step="2"><span class="n">2</span><span>Parent</span></div>
            <div class="d" data-step="3"><span class="n">3</span><span>Address</span></div>
            <div class="d" data-step="4"><span class="n">4</span><span>School</span></div>
            <div class="d" data-step="5"><span class="n">5</span><span>Docs</span></div>
            <div class="d" data-step="6"><span class="n">6</span><span>Review</span></div>
        </div>
    </div>

    <!-- ── FORM CARD ── -->
    <div class="card form-card" id="formCard">
        <div class="pf-loading" id="pfLoading" aria-live="polite">
            <div class="spinner"></div>
            <div><h3>Securing your application…</h3><p>Please don't close this window.</p></div>
        </div>

        <div id="formAlert" class="pf-alert"></div>

        <form id="admissionForm" novalidate>

            <!-- STEP 1 -->
            <section class="pf-step active" data-step="1" aria-label="Student Information">
                <div class="shead"><div class="kick"><i class="fa-solid fa-child"></i> The applicant</div>
                    <h2>Tell us about your child</h2><p>A few basics to start the application.</p></div>
                <div class="grid">
                    <div class="fg full"><label for="i_student_name">Student's full name <span class="req">*</span></label>
                        <input id="i_student_name" type="text" name="student_name" required aria-required="true" maxlength="100" autocomplete="name" placeholder="e.g. Aarav Sharma">
                        <span class="pf-err">Please enter the student's name.</span></div>
                    <div class="fg"><label for="i_class">Class applying for <span class="req">*</span></label>
                        <select id="i_class" name="class" required aria-required="true"><option value="">Select a class</option>
                        <?php foreach ($classes as $cls): ?><option value="<?= $esc(str_replace('Class ', '', $cls)) ?>"><?= $esc($cls) ?></option><?php endforeach; ?>
                        <?php if (empty($classes)): ?><option value="" disabled>No classes available</option><?php endif; ?></select>
                        <span class="pf-err">Please choose a class.</span></div>
                    <div class="fg"><label for="i_dob">Date of birth <span class="req">*</span></label>
                        <input id="i_dob" type="date" name="dob" required aria-required="true">
                        <span class="pf-err">Please enter the date of birth.</span></div>
                    <div class="fg"><label for="i_gender">Gender <span class="req">*</span></label>
                        <select id="i_gender" name="gender" required aria-required="true"><option value="">Select</option><option value="Male">Male</option><option value="Female">Female</option><option value="Other">Other</option></select>
                        <span class="pf-err">Please select a gender.</span></div>
                    <div class="fg"><label for="i_blood">Blood group</label>
                        <select id="i_blood" name="blood_group"><option value="">Select</option><option>A+</option><option>A-</option><option>B+</option><option>B-</option><option>AB+</option><option>AB-</option><option>O+</option><option>O-</option></select></div>
                    <div class="fg"><label for="i_category">Category</label>
                        <select id="i_category" name="category"><option value="">Select</option><option>General</option><option>OBC</option><option>SC</option><option>ST</option><option>EWS</option></select></div>
                    <div class="fg"><label for="i_religion">Religion</label><input id="i_religion" type="text" name="religion" maxlength="50" placeholder="Optional"></div>
                    <div class="fg"><label for="i_nationality">Nationality</label><input id="i_nationality" type="text" name="nationality" maxlength="50" placeholder="e.g. Indian"></div>
                </div>
            </section>

            <!-- STEP 2 -->
            <section class="pf-step" data-step="2" aria-label="Parent / Guardian">
                <div class="shead"><div class="kick"><i class="fa-solid fa-people-roof"></i> The family</div>
                    <h2>How should we reach you?</h2><p>We'll use these details only to update you about this application.</p></div>
                <div class="grid">
                    <div class="fg"><label for="i_father">Father's name</label><input id="i_father" type="text" name="parent_name" maxlength="100"></div>
                    <div class="fg"><label for="i_focc">Father's occupation</label><input id="i_focc" type="text" name="father_occupation" maxlength="100"></div>
                    <div class="fg"><label for="i_mother">Mother's name</label><input id="i_mother" type="text" name="mother_name" maxlength="100"></div>
                    <div class="fg"><label for="i_mocc">Mother's occupation</label><input id="i_mocc" type="text" name="mother_occupation" maxlength="100"></div>
                    <div class="fg"><label for="i_phone">Mobile number <span class="req">*</span></label>
                        <input id="i_phone" type="tel" name="phone" required aria-required="true" maxlength="20" inputmode="numeric" autocomplete="tel" placeholder="e.g. 9876543210">
                        <span class="hint"><i class="fa-solid fa-comment-sms"></i> We'll text application updates here.</span>
                        <span class="pf-err">Please enter a valid mobile number.</span></div>
                    <div class="fg"><label for="i_email">Email</label>
                        <input id="i_email" type="email" name="email" maxlength="150" autocomplete="email" placeholder="Optional">
                        <span class="pf-err">Please enter a valid email address.</span></div>
                    <div class="fg"><label for="i_gphone">Alternate phone</label><input id="i_gphone" type="tel" name="guardian_phone" maxlength="20" inputmode="numeric" placeholder="Optional"></div>
                    <div class="fg"><label for="i_grel">Guardian relation</label><input id="i_grel" type="text" name="guardian_relation" maxlength="50" placeholder="e.g. Uncle"></div>
                </div>
            </section>

            <!-- STEP 3 -->
            <section class="pf-step" data-step="3" aria-label="Address">
                <div class="shead"><div class="kick"><i class="fa-solid fa-house"></i> Home</div>
                    <h2>Where does the family live?</h2><p>Helps the school with zoning and communication. All optional.</p></div>
                <div class="grid">
                    <div class="fg full"><label for="i_addr">Street / locality</label><input id="i_addr" type="text" name="address" maxlength="200" autocomplete="street-address"></div>
                    <div class="fg"><label for="i_state">State</label><select id="i_state" name="state" data-india-state data-india-fill data-india-placeholder="Select State"><option value="">Select State</option></select></div>
                    <div class="fg"><label for="i_city">City / District</label><select id="i_city" name="city" data-india-district="i_state" data-india-placeholder="Select District"><option value="">Select District</option></select></div>
                    <div class="fg"><label for="i_pin">Pincode</label><input id="i_pin" type="text" name="pincode" maxlength="10" inputmode="numeric" pattern="[0-9]*"></div>
                </div>
            </section>

            <!-- STEP 4 -->
            <section class="pf-step" data-step="4" aria-label="Previous School">
                <div class="shead"><div class="kick"><i class="fa-solid fa-graduation-cap"></i> Background</div>
                    <h2>Previous school</h2><p>If your child attended one before — this is optional.</p></div>
                <div class="grid">
                    <div class="fg"><label for="i_pschool">Previous school name</label><input id="i_pschool" type="text" name="previous_school" maxlength="150"></div>
                    <div class="fg"><label for="i_pclass">Previous class</label><input id="i_pclass" type="text" name="previous_class" maxlength="50"></div>
                    <div class="fg"><label for="i_pmarks">Previous marks / grade</label><input id="i_pmarks" type="text" name="previous_marks" maxlength="50" placeholder="e.g. 85% / A1"></div>
                    <div class="fg full"><label for="i_msg">Anything else we should know?</label>
                        <textarea id="i_msg" name="message" rows="3" maxlength="500" placeholder="Optional — share anything that would help us understand your child better."></textarea></div>
                </div>
            </section>

            <!-- STEP 5 — Photo & Documents (all optional) -->
            <section class="pf-step" data-step="5" aria-label="Photo and Documents">
                <div class="shead"><div class="kick"><i class="fa-solid fa-paperclip"></i> Attachments</div>
                    <h2>Photo &amp; documents</h2><p>All optional — you can also submit these later at the school.</p></div>
                <div class="pf-uphint"><i class="fa-solid fa-circle-info"></i> Accepted: JPG, PNG, PDF, DOC, XLS · photos up to 2&nbsp;MB, documents up to 5&nbsp;MB</div>
                <div class="grid">
                    <div class="fg full">
                        <div class="pf-file-title">Student photo</div>
                        <label class="pf-file">
                            <input id="i_photo" class="pf-file-input" type="file" name="photo" accept="image/jpeg,image/png,image/gif" data-label="lbl_photo" data-default="Choose a photo">
                            <span class="pf-file-ico"><i class="fa-solid fa-camera"></i></span>
                            <span class="pf-file-txt" id="lbl_photo">Choose a photo</span>
                            <span class="pf-file-btn">Browse</span>
                        </label>
                        <div class="pf-preview" id="prev_photo"></div>
                        <span class="pf-err">This file type isn't supported or is too large.</span>
                    </div>
                    <div class="fg full">
                        <div class="pf-file-title">Student Aadhaar card</div>
                        <label class="pf-file">
                            <input id="i_aadhaar" class="pf-file-input" type="file" name="aadhaar_card" accept="image/jpeg,image/png,image/gif,application/pdf" data-label="lbl_aadhaar" data-default="Choose a file (image or PDF)">
                            <span class="pf-file-ico"><i class="fa-solid fa-id-card"></i></span>
                            <span class="pf-file-txt" id="lbl_aadhaar">Choose a file (image or PDF)</span>
                            <span class="pf-file-btn">Browse</span>
                        </label>
                        <div class="pf-preview" id="prev_aadhaar"></div>
                        <span class="pf-err">This file type isn't supported or is too large.</span>
                    </div>
                    <div class="fg full">
                        <div class="pf-file-title">Birth certificate <span style="color:var(--ink3);font-weight:400">(optional)</span></div>
                        <label class="pf-file">
                            <input id="i_birth" class="pf-file-input" type="file" name="birth_certificate" accept="image/jpeg,image/png,application/pdf" data-label="lbl_birth" data-default="Choose a file (image or PDF)">
                            <span class="pf-file-ico"><i class="fa-solid fa-baby"></i></span>
                            <span class="pf-file-txt" id="lbl_birth">Choose a file (image or PDF)</span>
                            <span class="pf-file-btn">Browse</span>
                        </label>
                        <div class="pf-preview" id="prev_birth"></div>
                        <span class="pf-err">This file type isn't supported or is too large.</span>
                    </div>
                    <div class="fg full">
                        <div class="pf-file-title">Transfer certificate <span style="color:var(--ink3);font-weight:400">(optional)</span></div>
                        <label class="pf-file">
                            <input id="i_transfer" class="pf-file-input" type="file" name="transfer_certificate" accept="image/jpeg,image/png,application/pdf" data-label="lbl_transfer" data-default="Choose a file (image or PDF)">
                            <span class="pf-file-ico"><i class="fa-solid fa-right-left"></i></span>
                            <span class="pf-file-txt" id="lbl_transfer">Choose a file (image or PDF)</span>
                            <span class="pf-file-btn">Browse</span>
                        </label>
                        <div class="pf-preview" id="prev_transfer"></div>
                        <span class="pf-err">This file type isn't supported or is too large.</span>
                    </div>
                    <div class="fg full">
                        <div class="pf-file-title">Marksheet <span style="color:var(--ink3);font-weight:400">(optional)</span></div>
                        <label class="pf-file">
                            <input id="i_marksheet" class="pf-file-input" type="file" name="marksheet" accept="image/jpeg,image/png,application/pdf" data-label="lbl_marksheet" data-default="Choose a file (image or PDF)">
                            <span class="pf-file-ico"><i class="fa-solid fa-file-lines"></i></span>
                            <span class="pf-file-txt" id="lbl_marksheet">Choose a file (image or PDF)</span>
                            <span class="pf-file-btn">Browse</span>
                        </label>
                        <div class="pf-preview" id="prev_marksheet"></div>
                        <span class="pf-err">This file type isn't supported or is too large.</span>
                    </div>
                    <div class="fg full">
                        <div class="pf-file-title">Medical certificate <span style="color:var(--ink3);font-weight:400">(optional)</span></div>
                        <label class="pf-file">
                            <input id="i_medical" class="pf-file-input" type="file" name="medical_certificate" accept="image/jpeg,image/png,application/pdf" data-label="lbl_medical" data-default="Choose a file (image or PDF)">
                            <span class="pf-file-ico"><i class="fa-solid fa-notes-medical"></i></span>
                            <span class="pf-file-txt" id="lbl_medical">Choose a file (image or PDF)</span>
                            <span class="pf-file-btn">Browse</span>
                        </label>
                        <div class="pf-preview" id="prev_medical"></div>
                        <span class="pf-err">This file type isn't supported or is too large.</span>
                    </div>
                    <div class="fg full">
                        <div class="pf-file-title">Other documents <span style="color:var(--ink3);font-weight:400">(any other supporting files — you can pick several)</span></div>
                        <label class="pf-file">
                            <input id="i_docs" class="pf-file-input" type="file" name="documents[]" multiple accept="image/jpeg,image/png,image/gif,application/pdf,.doc,.docx,.xls,.xlsx" data-label="lbl_docs" data-default="Choose files">
                            <span class="pf-file-ico"><i class="fa-solid fa-folder-open"></i></span>
                            <span class="pf-file-txt" id="lbl_docs">Choose files</span>
                            <span class="pf-file-btn">Browse</span>
                        </label>
                        <div class="pf-preview" id="prev_docs"></div>
                        <span class="pf-err">One or more files are unsupported or too large.</span>
                    </div>
                </div>
            </section>

            <!-- STEP 6 -->
            <section class="pf-step" data-step="6" aria-label="Review and Submit">
                <div class="shead"><div class="kick"><i class="fa-solid fa-clipboard-check"></i> Almost done</div>
                    <h2>Review &amp; submit</h2><p>Please confirm your details before submitting.</p></div>
                <div class="review" id="pfReview" aria-live="polite"></div>
                <div id="pfReviewAtt"></div>
                <label class="consent" for="consentCheck">
                    <input type="checkbox" name="consent" id="consentCheck" required aria-required="true">
                    <span>I confirm the information above is correct and consent to <strong><?= $esc($displayName) ?></strong> storing and processing this application data for admission purposes. I understand my contact details may be used to communicate about this application.</span>
                </label>
                <span class="pf-err" id="consentErr" style="margin-bottom:6px">Please accept the consent to continue.</span>
                <!-- Honeypot (bot guard, preserved from live): a real applicant never sees or
                     fills this. Any value → submit_application drops the submission. Off-screen
                     (not display:none) so naive bots still "see" and fill it. -->
                <div class="pf-hp" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;height:0;width:0;overflow:hidden;">
                    <label>Company Website (leave blank)
                        <input type="text" name="company_website" tabindex="-1" autocomplete="off">
                    </label>
                </div>
                <button type="submit" class="btn btn-p btn-full" id="submitBtn"><i class="fa-solid fa-paper-plane"></i> Submit Application</button>
                <div class="protect"><i class="fa-solid fa-shield-halved"></i> Your information is encrypted and never shared. We'll contact you after review.</div>
            </section>

            <!-- desktop nav -->
            <div class="nav">
                <button type="button" class="btn btn-g btn-back" id="pfBackBtn" style="visibility:hidden" onclick="pfBack()"><i class="fa-solid fa-arrow-left"></i> Back</button>
                <button type="button" class="btn btn-p btn-fwd" id="pfNextBtn" onclick="pfNext()">Continue <i class="fa-solid fa-arrow-right"></i></button>
            </div>
        </form>
    </div>

    <!-- ── PAYMENT PANEL ── -->
    <div class="card panel" id="paymentPanel">
        <div class="badge-ico badge-pay"><i class="fa-solid fa-indian-rupee-sign"></i></div>
        <h2>One last step</h2><p class="sub">Complete the admission fee to submit your application.</p>
        <div class="idbox"><div class="k"><span id="payLabel">Admission fee</span> due</div><div class="amount">&#8377;<span id="payAmount">0</span></div></div>
        <div class="warn"><i class="fa-solid fa-circle-info" style="margin-top:2px"></i><span>The <strong>Admission Fee is Non-Refundable</strong>. Please review your details before paying.</span></div>
        <p style="font-size:13.5px;font-weight:600;margin-bottom:14px">Choose how you'd like to pay</p>
        <div style="display:flex;flex-direction:column;gap:11px;max-width:380px;margin:0 auto">
            <button id="payOnlineBtn" class="btn btn-p" onclick="initiatePayment()"><i class="fa-solid fa-credit-card"></i> Pay Online — Card / UPI / Net-banking</button>
            <button id="payOfflineBtn" class="btn btn-g" onclick="chooseOffline()"><i class="fa-solid fa-building-columns"></i> Pay at School — Cash / Cheque / UPI</button>
        </div>
    </div>

    <!-- ── SUCCESS PANEL ── -->
    <div class="card panel" id="successPanel">
        <div class="badge-ico badge-ok"><i class="fa-solid fa-circle-check"></i></div>
        <h2 id="successName">Application Submitted!</h2>
        <p class="sub" id="successSubtext">The school will review your application and contact you shortly.</p>
        <div class="idbox"><div class="k">Your Application ID</div><div class="v" id="appIdDisplay">—</div></div>

        <div id="receiptSection" style="display:none">
            <a id="receiptLink" class="btn btn-p" style="display:inline-flex;min-width:230px;text-decoration:none" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf"></i> <span id="receiptLabel">Download Receipt (PDF)</span></a>
            <p class="note">A copy of this link has been sent to your phone via SMS.</p>
        </div>

        <div class="timeline">
            <div class="ti"><div class="num">1</div><div><div class="tt">Application received</div><div class="td">We've got your details — save your Application ID above.</div></div></div>
            <div class="ti"><div class="num">2</div><div><div class="tt">Under review</div><div class="td">Our admissions team will review your application.</div></div></div>
            <div class="ti"><div class="num">3</div><div><div class="tt">We'll be in touch</div><div class="td">You'll be contacted on the phone number you provided.</div></div></div>
        </div>

        <div class="actions">
            <button type="button" class="btn btn-g" onclick="pfCopyId()"><i class="fa-solid fa-copy"></i> Copy ID</button>
            <button type="button" class="btn btn-g" onclick="pfPrintReceipt()"><i class="fa-solid fa-print"></i> Print</button>
            <button type="button" class="btn btn-g" onclick="location.reload()"><i class="fa-solid fa-plus"></i> New Application</button>
        </div>
        <?php if (!empty($schoolPhone) || !empty($schoolEmail)): ?>
        <div class="contact">Questions? Contact <strong><?= $esc($displayName) ?></strong>
            <?php if (!empty($schoolPhone)): ?> · <a href="tel:<?= $esc($schoolPhone) ?>"><i class="fa-solid fa-phone"></i> <?= $esc($schoolPhone) ?></a><?php endif; ?>
            <?php if (!empty($schoolEmail)): ?> · <a href="mailto:<?= $esc($schoolEmail) ?>"><i class="fa-solid fa-envelope"></i> <?= $esc($schoolEmail) ?></a><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── OFFLINE PANEL ── -->
    <div class="card panel" id="offlinePanel">
        <div class="badge-ico badge-ok"><i class="fa-solid fa-circle-check"></i></div>
        <h2>Application Submitted!</h2>
        <p class="sub">Your application ID: <strong id="offAppId">—</strong></p>
        <p class="sub">Please pay the admission fee of <strong>&#8377;<span id="offAmount">0</span></strong> at the school office.</p>
        <div class="idbox"><div class="k">Your reference number</div><div class="v" id="offRef">-</div></div>
        <p class="note">Show this reference at the reception desk. The office will collect the fee and issue an official receipt.</p>
        <div class="actions">
            <button type="button" class="btn btn-g" onclick="pfPrintReceipt()"><i class="fa-solid fa-print"></i> Print</button>
            <button type="button" class="btn btn-g" onclick="location.reload()"><i class="fa-solid fa-plus"></i> New Application</button>
        </div>
    </div>

    <div class="foot"><span class="s"><i class="fa-solid fa-lock"></i> 256-bit encrypted · Your data is protected</span><br>Powered by ZenXii ERP</div>
</div>

<!-- ── Printable fee receipt (rendered only when the parent prints) ── -->
<div id="pfReceipt"><div class="rc-frame">
    <div class="rc-wm"><span><?= $esc(strtoupper($displayName)) ?></span></div>
    <div class="rc-stamp paid" id="rcStamp">PAID</div>
    <div class="rc-head">
        <?php if (!empty($logoUrl)): ?>
            <img class="rc-logo" src="<?= $esc($logoUrl) ?>" alt="">
        <?php else: ?>
            <div class="rc-mono"><?= $esc($monogram) ?></div>
        <?php endif; ?>
        <div>
            <div class="rc-school"><?= $esc($displayName) ?></div>
            <?php if (!empty($address)): ?><div class="rc-addr"><?= $esc($address) ?></div><?php endif; ?>
            <?php if (!empty($schoolPhone) || !empty($schoolEmail)): ?>
                <div class="rc-contact"><?php if (!empty($schoolPhone)): ?>Phone: <?= $esc($schoolPhone) ?><?php endif; ?><?= (!empty($schoolPhone) && !empty($schoolEmail)) ? ' &nbsp;·&nbsp; ' : '' ?><?php if (!empty($schoolEmail)): ?>Email: <?= $esc($schoolEmail) ?><?php endif; ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="rc-titlebar">Admission Fee Receipt</div>

    <div class="rc-meta">
        <div>Receipt No: <b id="rcApp">—</b></div>
        <div>Date: <b id="rcDate">—</b></div>
        <?php if (!empty($session_year)): ?><div>Session: <b><?= $esc($session_year) ?></b></div><?php endif; ?>
    </div>

    <table class="rc-party"><tbody>
        <tr><td class="pk">Student</td><td class="pv" id="rcStudent"></td><td class="pk">Class</td><td class="pv" id="rcClass"></td></tr>
        <tr><td class="pk">Father / Guardian</td><td class="pv" id="rcFather"></td><td class="pk">Date of Birth</td><td class="pv" id="rcDob"></td></tr>
        <tr><td class="pk">Mobile</td><td class="pv" id="rcPhone"></td><td class="pk">Gender</td><td class="pv" id="rcGender"></td></tr>
    </tbody></table>

    <table class="rc-tbl">
        <thead><tr><th>Particulars</th><th class="amt">Amount (&#8377;)</th></tr></thead>
        <tbody>
            <tr><td id="rcFeeLabel">Admission / Registration Fee</td><td class="amt"><span id="rcLineAmt">0.00</span></td></tr>
        </tbody>
    </table>
    <div class="rc-totals">
        <div class="rc-tr"><span class="lbl">Total Fee</span><span class="val"><span id="rcTotalAmt">0.00</span></span></div>
        <div class="rc-tr"><span class="lbl">Concession (&minus;)</span><span class="val">0.00</span></div>
        <div class="rc-tr net"><span class="lbl" id="rcNetLbl">Amount Payable</span><span class="val">&#8377; <span id="rcNetAmt">0.00</span></span></div>
        <div class="rc-tr"><span class="lbl">Balance</span><span class="val" id="rcBalance">0.00</span></div>
    </div>

    <div class="rc-words">In Words: <b id="rcWords">—</b></div>

    <div class="rc-payrow">
        <div>Payment Mode: <b id="rcMode">—</b></div>
        <div>Reference No: <span class="mono" id="rcRef">—</span></div>
        <div>Received By: <b id="rcBy">—</b></div>
    </div>

    <div class="rc-sign rc-sign-one">
        <div class="rc-sign-box"><div class="rc-sign-line"></div>Authorized Signatory · <?= $esc($displayName) ?></div>
    </div>
    <div class="rc-foot" id="rcFoot">This is a computer-generated receipt and is valid without a physical signature. <?= $esc($displayName) ?> · Powered by ZenXii ERP</div>
</div></div>

<!-- mobile sticky action bar -->
<div class="abar" id="pfAbar">
    <button type="button" class="btn btn-g btn-back" id="pfBackM" style="visibility:hidden" onclick="pfBack()"><i class="fa-solid fa-arrow-left"></i></button>
    <button type="button" class="btn btn-p btn-fwd" id="pfNextM" onclick="pfNext()">Continue <i class="fa-solid fa-arrow-right"></i></button>
</div>

<script src="<?= base_url('assets/js/india_geo.js') ?>"></script>
<script>
// PUBLIC ADMISSION — CSRF from meta tags + AJAX submission
// Build stamp — if you DON'T see this line in the browser console (F12),
// the page is being served from cache and is running old JS.
console.info('%c[admission-form] wizard build 2026-07-20 — Continue is live', 'color:#BC5A3C;font-weight:700');
var _csrfMetaName  = document.querySelector('meta[name="csrf-name"]');
var _csrfMetaToken = document.querySelector('meta[name="csrf-token"]');
var csrfName  = _csrfMetaName  ? _csrfMetaName.content  : 'csrf_test_name';
var csrfToken = _csrfMetaToken ? _csrfMetaToken.content : '';

function showAlert(msg, isError) {
    var el = document.getElementById('formAlert');
    el.setAttribute('role', 'alert');            // announce to screen readers (a11y, preserved from live)
    el.setAttribute('aria-live', 'assertive');
    el.innerHTML = '<i class="fa-solid ' + (isError ? 'fa-circle-exclamation' : 'fa-circle-check') + '"></i><span></span>';
    el.querySelector('span').textContent = msg;
    el.className = 'pf-alert ' + (isError ? 'pf-alert-err' : 'pf-alert-ok');
    el.style.display = 'flex';
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
function pfLoading(on){ document.getElementById('pfLoading').classList.toggle('on', !!on); }

// ── Multi-step wizard (presentation only — all fields stay in the DOM) ──
var PF_TOTAL = 6, pfCur = 1;
var PF_NAMES = {1:'Student Information',2:'Parent / Guardian',3:'Address',4:'Previous School',5:'Photo & Documents',6:'Review & Submit'};
function pfSync(){
    var b = document.getElementById('pfBackBtn'), bm = document.getElementById('pfBackM');
    var vis = pfCur === 1 ? 'hidden' : 'visible';
    if (b) b.style.visibility = vis; if (bm) bm.style.visibility = vis;
    var showNext = pfCur < PF_TOTAL;
    document.getElementById('pfNextBtn').style.display = showNext ? 'inline-flex' : 'none';
    document.getElementById('pfNextM').style.display = showNext ? 'inline-flex' : 'none';
    var sub = document.getElementById('submitBtn');
    // On the last step the primary action is Submit; hide the wizard "Continue".
    document.getElementById('pfNextM').style.display = showNext ? 'inline-flex' : 'none';
    if (!showNext) { document.getElementById('pfNextM').style.display = 'none'; }
}
function pfGoStep(n){
    if (n < 1 || n > PF_TOTAL) return;
    if (n > pfCur && !pfValidateStep(pfCur)) return;
    pfCur = n;
    var steps = document.querySelectorAll('.pf-step');
    for (var i=0;i<steps.length;i++){ steps[i].classList.toggle('active', +steps[i].getAttribute('data-step')===n); }
    var dots = document.querySelectorAll('.dots .d');
    for (var j=0;j<dots.length;j++){ var si=+dots[j].getAttribute('data-step');
        dots[j].classList.toggle('cur', si===n); dots[j].classList.toggle('done', si<n); }
    document.getElementById('pfTrackFill').style.width = (n/PF_TOTAL)*100 + '%';
    document.getElementById('pfStepNow').textContent = n;
    document.getElementById('pfStepName').textContent = PF_NAMES[n];
    pfSync();
    if (n === PF_TOTAL) pfBuildReview();
    document.getElementById('progWrap').scrollIntoView({ behavior:'smooth', block:'start' });
}
function pfNext(){ pfGoStep(pfCur + 1); }
function pfBack(){ if (pfCur > 1){ pfCur = pfCur - 1; document.getElementById('formAlert').style.display='none'; pfGoStep(pfCur); } }

function pfFieldValid(f){
    if (f.type === 'checkbox') return f.checked;
    var v = (f.value || '').trim();
    if (v === '') return false;
    if (f.type === 'email') return /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v);
    if (f.name === 'phone')  return /\d{7,}/.test(v.replace(/\D/g,''));
    return true;
}
function pfValidateStep(n){
    var step = document.querySelector('.pf-step[data-step="'+n+'"]');
    if (!step) return true;
    var reqs = step.querySelectorAll('[required]'), first = null, bad = [];
    for (var i=0;i<reqs.length;i++){
        var f = reqs[i], ok = pfFieldValid(f);
        f.classList.toggle('pf-invalid', !ok);
        var err = (f.id === 'consentCheck') ? document.getElementById('consentErr') : f.parentNode.querySelector('.pf-err');
        if (err) err.style.display = ok ? 'none' : 'flex';
        if (!ok){ if (!first) first = f; bad.push(f); }
    }
    if (first){
        showAlert('Please complete the ' + bad.length + ' required field' + (bad.length > 1 ? 's' : '') + ' below to continue.', true);
        for (var b=0;b<bad.length;b++){ var el=bad[b]; el.classList.remove('pf-shake'); void el.offsetWidth; el.classList.add('pf-shake'); }
        try { first.focus({ preventScroll:true }); } catch(e){ try{ first.focus(); }catch(_){} }
        return false;
    }
    // Step 5 has no required fields, but any chosen file must be a supported
    // type and under 1 MB before we let the parent move on.
    if (n === 5){
        var fileOk = true;
        PF_FILE_INPUTS.forEach(function(id){ var el=document.getElementById(id); if (el && !pfFileCheck(el)) fileOk=false; });
        if (!fileOk){ showAlert('Please fix the highlighted file(s): use JPG, PNG, PDF, DOC or XLS — photos up to 2 MB, documents up to 5 MB.', true); return false; }
    }
    document.getElementById('formAlert').style.display = 'none';
    return true;
}
document.getElementById('admissionForm').addEventListener('input', function(e){
    var f = e.target;
    if (f.classList && f.classList.contains('pf-invalid') && pfFieldValid(f)){
        f.classList.remove('pf-invalid');
        var err = (f.id === 'consentCheck') ? document.getElementById('consentErr') : (f.parentNode && f.parentNode.querySelector('.pf-err'));
        if (err) err.style.display = 'none';
    }
});
function pfEsc(s){ var d=document.createElement('div'); d.textContent=s==null?'':s; return d.innerHTML; }

// ── Printable receipt: fill the letterhead template from the submitted data
// and open the browser print dialog (produces a proper school receipt, not a
// screenshot of the confirmation panel). Self-contained — no external deps. ──
function pfSetTxt(id, v){ var el = document.getElementById(id); if (el) el.textContent = (v == null || v === '') ? '—' : v; }
// Indian-format rupees-in-words (crore / lakh / thousand).
function pfWords(num){
    num = Math.round(num || 0);
    if (num === 0) return 'Zero Rupees Only';
    var a = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    var b = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    function two(n){ return n < 20 ? a[n] : (b[Math.floor(n/10)] + (n%10 ? ' ' + a[n%10] : '')); }
    function three(n){ return (n >= 100 ? a[Math.floor(n/100)] + ' Hundred' + (n%100 ? ' ' : '') : '') + (n%100 ? two(n%100) : ''); }
    var out = '';
    var cr = Math.floor(num/10000000); num %= 10000000;
    var la = Math.floor(num/100000);  num %= 100000;
    var th = Math.floor(num/1000);    num %= 1000;
    if (cr) out += two(cr) + ' Crore ';
    if (la) out += two(la) + ' Lakh ';
    if (th) out += two(th) + ' Thousand ';
    if (num) out += three(num);
    return out.trim() + ' Rupees Only';
}
function pfFillReceipt(){
    var f = document.getElementById('admissionForm');
    function val(name){ try { return (f[name] && (f[name].value || '').trim()) || ''; } catch(e){ return ''; } }
    var appId = window._appId || (document.getElementById('appIdDisplay').textContent || '').trim();
    pfSetTxt('rcApp', appId);
    pfSetTxt('rcStudent', window._studentName || val('student_name'));
    pfSetTxt('rcClass', val('class'));
    pfSetTxt('rcDob', val('dob'));
    pfSetTxt('rcGender', val('gender'));
    pfSetTxt('rcFather', val('parent_name'));
    pfSetTxt('rcPhone', val('phone'));
    var amt = parseFloat(window._payAmount || 0) || 0;
    var money = function(x){ return x.toLocaleString('en-IN', { minimumFractionDigits:2, maximumFractionDigits:2 }); };
    pfSetTxt('rcLineAmt', money(amt));
    pfSetTxt('rcTotalAmt', money(amt));
    pfSetTxt('rcNetAmt', money(amt));
    pfSetTxt('rcWords', pfWords(amt));
    var d = new Date();
    pfSetTxt('rcDate', d.toLocaleDateString('en-GB').replace(/\//g,'-')); // dd-mm-yyyy

    var offlineVisible = document.getElementById('offlinePanel').style.display === 'block';
    var paidVisible = document.getElementById('receiptSection') &&
                      document.getElementById('receiptSection').style.display === 'block' &&
                      document.getElementById('successPanel').style.display === 'block';
    var stamp = document.getElementById('rcStamp');
    if (offlineVisible){
        pfSetTxt('rcNetLbl', 'Amount Payable');
        pfSetTxt('rcBalance', money(amt));
        pfSetTxt('rcMode', 'Payable at School Office');
        pfSetTxt('rcRef', (document.getElementById('offRef').textContent || '').trim() || appId);
        pfSetTxt('rcBy', 'School Office');
        if (stamp){ stamp.textContent = 'DUE'; stamp.className = 'rc-stamp due'; }
    } else if (paidVisible){
        pfSetTxt('rcNetLbl', 'Amount Paid');
        pfSetTxt('rcBalance', money(0));
        pfSetTxt('rcMode', 'Online (UPI / Card / Net-banking)');
        pfSetTxt('rcRef', appId);
        pfSetTxt('rcBy', 'Online Gateway');
        if (stamp){ stamp.textContent = 'PAID'; stamp.className = 'rc-stamp paid'; }
    } else {
        pfSetTxt('rcNetLbl', amt > 0 ? 'Amount Payable' : 'Amount');
        pfSetTxt('rcBalance', money(amt));
        pfSetTxt('rcMode', 'Not collected yet');
        pfSetTxt('rcRef', appId);
        pfSetTxt('rcBy', '—');
        if (stamp) stamp.style.display = 'none';
    }
}
function pfPrintReceipt(){ pfFillReceipt(); window.print(); }

var pfReviewUrls = [];
function pfReviewTile(file){
    var ext = pfExt(file.name), isImg = ['jpg','jpeg','png','gif'].indexOf(ext) !== -1, vis;
    if (isImg){ var u = URL.createObjectURL(file); pfReviewUrls.push(u); vis = '<img src="'+u+'" alt="">'; }
    else {
        var ic = ext==='pdf' ? 'fa-file-pdf' : ((ext==='xls'||ext==='xlsx') ? 'fa-file-excel' : ((ext==='doc'||ext==='docx') ? 'fa-file-word' : 'fa-file'));
        vis = '<div class="pf-pv-ico"><i class="fa-solid '+ic+'"></i></div>';
    }
    return '<div class="pf-pv">' + vis + '<span class="pf-pv-cap">' + pfEsc(file.name) + '</span></div>';
}
function pfBuildReview(){
    var f = document.getElementById('admissionForm');
    var rows = [['Student', f.student_name.value],['Class', f['class'].value],['Date of birth', f.dob.value],
                ['Gender', f.gender.value],['Mobile', f.phone.value],['Email', f.email.value],['City', f.city.value]];
    var html = '';
    for (var i=0;i<rows.length;i++){ var val=(rows[i][1]||'').trim();
        if (val==='' && ['Email','City'].indexOf(rows[i][0])!==-1) continue;
        html += '<div class="rrow"><span class="k">'+rows[i][0]+'</span><span class="v">'+pfEsc(val||'—')+'</span></div>'; }
    document.getElementById('pfReview').innerHTML = html;

    // Attachments preview — the photo/Aadhaar/documents chosen in Step 5, so
    // the parent can confirm them before submitting.
    if (pfReviewUrls.length){ pfReviewUrls.forEach(function(u){ try{ URL.revokeObjectURL(u); }catch(e){} }); pfReviewUrls = []; }
    var tiles = '';
    PF_FILE_INPUTS.forEach(function(id){
        var el = document.getElementById(id);
        if (!el || !el.files) return;
        for (var j=0;j<el.files.length;j++) tiles += pfReviewTile(el.files[j]);
    });
    var att = '<div class="pf-review-att-h">Attachments</div>'
            + (tiles ? '<div class="pf-preview" style="display:flex;margin-top:0;">' + tiles + '</div>'
                     : '<p style="font-size:13px;color:var(--ink3);margin:0;">No files attached (optional).</p>');
    document.getElementById('pfReviewAtt').innerHTML = att;
}
function pfCopyId(){
    var t = (document.getElementById('appIdDisplay').textContent || '').trim(); if (!t) return;
    if (navigator.clipboard) navigator.clipboard.writeText(t);
    var b = event.currentTarget, o = b.innerHTML;
    b.innerHTML = '<i class="fa-solid fa-check"></i> Copied'; setTimeout(function(){ b.innerHTML = o; }, 1500);
}
// ── File uploads (Step 5): friendly filename labels + client-side guard ──
// Per-type caps + allow-lists — MIRROR the server-side Document Service policy
// (application/config/document_service.php). Keeping these aligned prevents a
// file that passes here from being rejected server-side (and vice-versa).
var PF_FILE_INPUTS = ['i_photo','i_aadhaar','i_birth','i_transfer','i_marksheet','i_medical','i_docs'];
var PF_MAX = {
    i_photo: 2097152,                                                   // 2 MB
    i_aadhaar: 5242880, i_birth: 5242880, i_transfer: 5242880,          // 5 MB
    i_marksheet: 5242880, i_medical: 5242880, i_docs: 5242880
};
var PF_OK_EXT = {
    i_photo:     ['jpg','jpeg','png','webp'],
    i_aadhaar:   ['jpg','jpeg','png','pdf'],
    i_birth:     ['jpg','jpeg','png','pdf'],
    i_transfer:  ['jpg','jpeg','png','pdf'],
    i_marksheet: ['jpg','jpeg','png','pdf'],
    i_medical:   ['jpg','jpeg','png','pdf'],
    i_docs:      ['jpg','jpeg','png','pdf','doc','docx','xls','xlsx']
};
function pfExt(name){ var i=(name||'').lastIndexOf('.'); return i<0 ? '' : name.slice(i+1).toLowerCase(); }
function pfFileCheck(input){
    var wrap = input.closest('.pf-file');
    var lbl  = document.getElementById(input.getAttribute('data-label'));
    var errEl = input.closest('.fg').querySelector('.pf-err');
    var allow = PF_OK_EXT[input.id] || [];
    var maxSz = PF_MAX[input.id] || 5242880;
    var files = input.files || [], bad = false, names = [];
    for (var i=0;i<files.length;i++){
        names.push(files[i].name);
        if (files[i].size > maxSz) bad = true;
        if (allow.indexOf(pfExt(files[i].name)) === -1) bad = true;
    }
    if (files.length === 0){
        if (lbl) lbl.textContent = input.getAttribute('data-default');
        if (wrap) wrap.classList.remove('has-file','pf-file-invalid');
        if (errEl) errEl.style.display = 'none';
        pfRenderPreview(input);
        return true;
    }
    if (lbl) lbl.textContent = files.length > 1 ? (files.length + ' files selected') : names[0];
    if (wrap){ wrap.classList.toggle('pf-file-invalid', bad); wrap.classList.toggle('has-file', !bad); }
    if (errEl) errEl.style.display = bad ? 'flex' : 'none';
    pfRenderPreview(input);
    return !bad;
}
// Thumbnail (images) / typed-icon (pdf/doc/xls) previews with a remove (×) control.
function pfRenderPreview(input){
    var box = document.getElementById('prev_' + input.id.slice(2)); // i_photo → prev_photo
    if (!box) return;
    if (input._urls){ input._urls.forEach(function(u){ try{ URL.revokeObjectURL(u); }catch(e){} }); }
    input._urls = [];
    var files = input.files || [];
    if (!files.length){ box.innerHTML=''; box.style.display='none'; return; }
    var html = '';
    for (var i=0;i<files.length;i++){
        var f = files[i], ext = pfExt(f.name), isImg = ['jpg','jpeg','png','gif'].indexOf(ext) !== -1, vis;
        if (isImg){ var u = URL.createObjectURL(f); input._urls.push(u); vis = '<img src="'+u+'" alt="">'; }
        else {
            var ic = ext==='pdf' ? 'fa-file-pdf' : ((ext==='xls'||ext==='xlsx') ? 'fa-file-excel' : ((ext==='doc'||ext==='docx') ? 'fa-file-word' : 'fa-file'));
            vis = '<div class="pf-pv-ico"><i class="fa-solid '+ic+'"></i></div>';
        }
        html += '<div class="pf-pv">' + vis
             +  '<span class="pf-pv-x" title="Remove" onclick="pfRemoveFile(\''+input.id+'\','+i+')">&times;</span>'
             +  '<span class="pf-pv-cap">' + pfEsc(f.name) + '</span></div>';
    }
    box.innerHTML = html; box.style.display = 'flex';
}
// Remove one file from a (possibly multi-file) input via a rebuilt FileList.
function pfRemoveFile(inputId, idx){
    var input = document.getElementById(inputId);
    if (!input || !input.files) return;
    try {
        var dt = new DataTransfer();
        for (var i=0;i<input.files.length;i++){ if (i !== idx) dt.items.add(input.files[i]); }
        input.files = dt.files;
    } catch(e){ input.value = ''; } // very old browsers: clear all
    pfFileCheck(input);
}
PF_FILE_INPUTS.forEach(function(id){
    var el = document.getElementById(id);
    if (el) el.addEventListener('change', function(){ pfFileCheck(this); });
});

pfGoStep(1);

// Reveal the final "Application Submitted!" panel with the app id + a receipt link.
function showSubmitted(appId, receiptUrl, label) {
    pfLoading(false);
    document.getElementById('paymentPanel').style.display = 'none';
    document.getElementById('appIdDisplay').textContent = appId || 'Submitted';
    if (window._studentName) {
        document.getElementById('successName').textContent = "You're all set, " + window._studentName.split(' ')[0] + "!";
    }
    if (receiptUrl) {
        document.getElementById('receiptLink').setAttribute('href', receiptUrl);
        if (label) document.getElementById('receiptLabel').textContent = label;
        document.getElementById('receiptSection').style.display = 'block';
    }
    var panel = document.getElementById('successPanel');
    panel.style.display = 'block';
    panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

document.getElementById('admissionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    if (!pfValidateStep(6)) return;
    window._studentName = (this.student_name && this.student_name.value.trim()) || '';
    var btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';
    document.getElementById('formAlert').style.display = 'none';
    pfLoading(true);

    // Multipart submit — the form now includes optional file inputs (photo,
    // Aadhaar, documents[]), so we post the raw FormData and let the browser
    // set the multipart boundary. (The payment/offline endpoints below stay
    // url-encoded; they carry no files.)
    var fd = new FormData(this);
    fd.append(csrfName, csrfToken);

    fetch('<?= base_url("admission/submit/" . urlencode($school_id)) ?>', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.csrf_token) csrfToken = data.csrf_token;
        if (data.status === 'success') {
            window._appId = data.app_id;
            window._appReceiptUrl = data.receipt_url || '';
            pfLoading(false);
            document.getElementById('formCard').style.display = 'none';
            document.getElementById('progWrap').style.display = 'none';
            document.getElementById('pfAbar').style.display = 'none';

            if (data.payment_required && data.payment_amount > 0) {
                window._payAmount = data.payment_amount;
                document.getElementById('payAmount').textContent = parseFloat(data.payment_amount).toLocaleString('en-IN');
                if (data.payment_label) { document.getElementById('payLabel').textContent = data.payment_label; }
                var pp = document.getElementById('paymentPanel');
                pp.style.display = 'block';
                pp.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } else {
                showSubmitted(data.app_id, data.receipt_url, 'Download Acknowledgement (PDF)');
            }
        } else {
            pfLoading(false);
            showAlert(data.message || 'Submission failed. Please try again.', true);
        }
    })
    .catch(function() { pfLoading(false); showAlert('Network error. Please check your connection and try again.', true); })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Application';
    });
});

// ── Pay at School (offline) ───────────────────────────────────────────
function chooseOffline() {
    var btn = document.getElementById('payOfflineBtn');
    btn.disabled = true;
    var oldHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Please wait...';

    var fd = new URLSearchParams({ app_id: window._appId });
    fd.append(csrfName, csrfToken);

    fetch('<?= base_url("admission/choose_offline/" . urlencode($school_id)) ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: fd.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
        if (res.csrf_token) csrfToken = res.csrf_token;
        if (res.status === 'success') {
            document.getElementById('paymentPanel').style.display = 'none';
            document.getElementById('offAppId').textContent = window._appId || 'Submitted';
            document.getElementById('offAmount').textContent = parseFloat(res.amount || window._payAmount || 0).toLocaleString('en-IN');
            document.getElementById('offRef').textContent = res.reference || window._appId;
            var op = document.getElementById('offlinePanel');
            op.style.display = 'block';
            op.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
            alert(res.message || 'Could not record your choice. Please try again.');
            btn.disabled = false; btn.innerHTML = oldHtml;
        }
    })
    .catch(function() {
        alert('Network error. Please try again.');
        btn.disabled = false; btn.innerHTML = oldHtml;
    });
}

// ── Payment Flow (online) ─────────────────────────────────────────────
function initiatePayment() {
    var btn = document.getElementById('payOnlineBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';

    var fd = new URLSearchParams({ app_id: window._appId, token: window._payToken || '' });
    fd.append(csrfName, csrfToken);

    fetch('<?= base_url("admission/pay/" . urlencode($school_id)) ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: fd.toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(order) {
        if (order.csrf_token) csrfToken = order.csrf_token;
        if (order.status !== 'success') {
            alert(order.message || 'Payment initiation failed.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-credit-card"></i> Pay Now';
            return;
        }
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

// Lazily inject the Razorpay checkout SDK on demand (only when paying online).
// Keeping it OUT of the <head> means a blocked/slow razorpay.com can never
// stall the page load or the wizard JS. cb(true) once ready, cb(false) on fail.
var _rzpLoading = false, _rzpCbs = [];
function ensureRazorpay(cb) {
    if (typeof Razorpay !== 'undefined') { cb(true); return; }
    _rzpCbs.push(cb);
    if (_rzpLoading) return;
    _rzpLoading = true;
    var s = document.createElement('script');
    s.src = 'https://checkout.razorpay.com/v1/checkout.js';
    s.async = true;
    s.onload  = function(){ _rzpLoading = false; var f = _rzpCbs.splice(0); for (var i=0;i<f.length;i++) f[i](true); };
    s.onerror = function(){ _rzpLoading = false; var f = _rzpCbs.splice(0); for (var i=0;i<f.length;i++) f[i](false); };
    document.head.appendChild(s);
}
function _resetPayBtn(){
    var btn = document.getElementById('payOnlineBtn');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-credit-card"></i> Pay Now';
}

function launchRazorpay(order) {
    if (typeof Razorpay === 'undefined') {
        // SDK not loaded yet — fetch it now, then retry once (or fail cleanly).
        ensureRazorpay(function(ok){
            if (!ok) { alert('Could not reach the payment gateway. Please check your internet connection and try again.'); _resetPayBtn(); return; }
            launchRazorpay(order);
        });
        return;
    }
    var rzp = new Razorpay({
        key:      order.key,
        amount:   order.amount_paise,
        currency: order.currency || 'INR',
        name:     order.school_name || 'Admission Fee',
        description: 'Admission Fee · ' + (order.student_name || ''),
        order_id: order.order_id,
        prefill: { name: order.student_name || '', email: order.email || '', contact: order.phone || '' },
        notes: { app_id: window._appId, payment_id: order.payment_id },
        theme: { color: '#BC5A3C' },
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
                    showSubmitted(window._appId, result.fee_receipt_url || window._appReceiptUrl, 'Download Fee Receipt (PDF)');
                } else {
                    alert('Payment verification failed: ' + (result.message || 'Unknown error'));
                    var btn = document.getElementById('payOnlineBtn');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-credit-card"></i> Retry Payment';
                }
            })
            .catch(function() { alert('Payment verification failed. Please contact the school.'); });
        },
        modal: { ondismiss: function() {
            var btn = document.getElementById('payOnlineBtn');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-credit-card"></i> Pay Now';
        }}
    });
    rzp.on('payment.failed', function(resp) {
        alert('Payment failed: ' + (resp.error && resp.error.description ? resp.error.description : 'Unknown error'));
        var btn = document.getElementById('payOnlineBtn');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-credit-card"></i> Retry Payment';
    });
    rzp.open();
}

function simulateMockPayment(order) {
    setTimeout(function() {
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
            if (result.csrf_token) csrfToken = result.csrf_token;
            if (result.status === 'success') {
                showSubmitted(window._appId, result.fee_receipt_url || window._appReceiptUrl, 'Download Fee Receipt (PDF)');
            } else {
                alert('Payment verification failed: ' + (result.message || 'Unknown error'));
                var btn = document.getElementById('payOnlineBtn');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-credit-card"></i> Retry Payment';
            }
        })
        .catch(function() { alert('Payment verification failed. Please contact the school.'); });
    }, 1500);
}
</script>
</body>
</html>
