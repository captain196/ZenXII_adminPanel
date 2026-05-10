#!/usr/bin/env node
// Phase 1 collection-count snapshot helper.
// Read-only: prints a TSV of collection -> doc count for every Phase-1
// relevant collection. Used pre-deploy and post-deploy to verify
// audit parity (snake count == camel mirror count) and detect drops.
const path = require('path');
let admin;
try { admin = require(path.resolve(__dirname, '..', 'functions', 'node_modules', 'firebase-admin')); }
catch (e) { admin = require('firebase-admin'); }
const sa = require(path.resolve(__dirname, '..', 'application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const fs = admin.firestore();
const COLS = [
  'fee_audit_logs','feeAuditLogs',
  'feeReceipts','feeDemands','feeRefunds','feeRefundVouchers','feeRefundAudit',
  'feeCarryForward','feeReceiptIndex','feeReceiptAllocations','feeIdempotency',
  'feeOnlineOrders','feeOnlinePayments','studentFeeSummary','classFeeSummary'
];
(async () => {
  for (const c of COLS) {
    try {
      const snap = await fs.collection(c).count().get();
      console.log(`${c}\t${snap.data().count}`);
    } catch (e) {
      console.log(`${c}\tERROR: ${e.message}`);
    }
  }
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
