// Phase 3.5c Task 5 — receipt canonical model.
//
// Ensures every feeReceipts doc carries the canonical SaaS fields:
//
//   {
//     receiptId,        // alias of receiptKey (e.g. "F12")
//     studentId,
//     totalAmount,      // alias of inputAmount (what the parent paid)
//     paidAmount,       // alias of allocatedAmount (what landed on demands)
//     breakdown: [      // derived from feeBreakdown + allocations
//       { feeHeadId, feeHead, amount }
//     ],
//     status,           // 'full' | 'partial' (coverage indicator)
//     createdAt,
//   }
//
// BACKWARD COMPAT: original fields (receiptKey, amount, allocatedAmount,
// feeBreakdown, feeMonths, etc.) are PRESERVED. Only new canonical
// fields are added. No reads are relocated — existing admin/parent
// code keeps using whatever it already uses.
//
// Idempotent: re-run safely. Skips receipts already carrying receiptId
// unless --force is passed.
//
//   node scripts/migrate_receipt_canonical.js dry-run
//   node scripts/migrate_receipt_canonical.js live [--force]

const admin = require('firebase-admin');
const path  = require('path');
const sa = require(path.join(__dirname, '..', 'application', 'config', 'graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json'));
admin.initializeApp({ credential: admin.credential.cert(sa) });
const db = admin.firestore();

(async () => {
  const mode  = (process.argv[2] || '').toLowerCase();
  const force = process.argv.includes('--force');
  if (!['dry-run','live'].includes(mode)) {
    console.log('Usage: node scripts/migrate_receipt_canonical.js dry-run|live [--force]');
    process.exit(2);
  }

  // Pre-load head-id map per structure so the breakdown can populate
  // feeHeadId from the head's name.
  const structures = await db.collection('feeStructures').get();
  const headIdBy = new Map(); // key = schoolId|session|className|section → { name → feeHeadId }
  structures.docs.forEach(d => {
    const x = d.data() || {};
    const k = [x.schoolId, x.session, x.className, x.section].join('|');
    const m = {};
    (x.feeHeads || []).forEach(h => { if (h && h.name && h.feeHeadId) m[h.name] = h.feeHeadId; });
    headIdBy.set(k, m);
  });

  const snap = await db.collection('feeReceipts').get();
  console.log(`Scanning ${snap.size} feeReceipts...\n`);

  let touched = 0, skipped = 0;
  let batch = db.batch(); let batchOps = 0;

  for (const d of snap.docs) {
    const x = d.data() || {};
    if (!force && typeof x.receiptId === 'string' && x.receiptId !== '' && Array.isArray(x.breakdown)) {
      skipped++; continue;
    }

    const receiptKey = String(x.receiptKey || (x.receiptNo ? `F${x.receiptNo}` : d.id));
    const structKey  = [x.schoolId, x.session, x.className, x.section].join('|');
    const nameToId   = headIdBy.get(structKey) || {};

    // Build canonical breakdown. Prefer allocations when available
    // (exact per-head amounts that actually landed on demands); fall
    // back to feeBreakdown (structure-level totals).
    let breakdown = [];
    try {
      const allocId = `${x.schoolId}_${x.session}_${receiptKey}`;
      const alloc = await db.collection('feeReceiptAllocations').doc(allocId).get();
      if (alloc.exists) {
        const rows = (alloc.data()?.allocations || []).map(r => ({
          feeHead:   String(r.fee_head || r.feeHead || ''),
          feeHeadId: nameToId[String(r.fee_head || r.feeHead || '')] || '',
          amount:    Number(r.allocated || 0),
        })).filter(r => r.feeHead && r.amount > 0);
        if (rows.length) breakdown = rows;
      }
    } catch (_) {}
    if (breakdown.length === 0 && Array.isArray(x.feeBreakdown)) {
      breakdown = x.feeBreakdown.map(b => ({
        feeHead:   String(b.head || b.feeHead || ''),
        feeHeadId: nameToId[String(b.head || b.feeHead || '')] || '',
        amount:    Number(String(b.amount || '0').replace(/,/g,'')) || 0,
      })).filter(r => r.feeHead);
    }

    const totalAmount = Number(x.inputAmount || x.input_amount || x.amount || 0);
    const paidAmount  = Number(x.allocatedAmount || x.allocated_amount || totalAmount);
    // Coverage status: any dangling unpaid balance across the covered
    // months would make this 'partial'. Simplification: if allocated
    // < total, call it partial (the pre-P9 wallet-overflow case).
    const status = (paidAmount + 0.005 >= totalAmount) ? 'full' : 'partial';

    const canonical = {
      receiptId:   receiptKey,
      studentId:   String(x.studentId || ''),
      totalAmount,
      paidAmount,
      breakdown,
      status,
      createdAt:   String(x.createdAt || x.date || new Date().toISOString()),
      _canonicalVersion: 1,
      updatedAt:   new Date().toISOString(),
    };

    console.log(`  patch ${d.id}  receiptId=${receiptKey} total=${totalAmount} paid=${paidAmount} heads=${breakdown.length} status=${status}`);
    if (mode === 'live') {
      batch.set(d.ref, canonical, { merge: true });
      batchOps++; touched++;
      if (batchOps >= 400) { await batch.commit(); batch = db.batch(); batchOps = 0; }
    } else { touched++; }
  }
  if (mode === 'live' && batchOps > 0) await batch.commit();

  console.log(`\n──────────────────────────────────────────────`);
  console.log(`Mode:                ${mode.toUpperCase()}`);
  console.log(`Receipts scanned:    ${snap.size}`);
  console.log(`Touched:             ${touched}`);
  console.log(`Skipped (up-to-date):${skipped}`);
  process.exit(0);
})().catch(e => { console.error(e); process.exit(1); });
