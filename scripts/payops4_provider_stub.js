/**
 * ══════════════════════════════════════════════════════════════════════════
 *  LOOPBACK PAYMENT-PROVIDER STUB  (for payops4_webhook_e2e.js)
 * ══════════════════════════════════════════════════════════════════════════
 *
 * The webhook path settles a payment by FETCHING it from the provider rather
 * than trusting the envelope — that is the whole point of PAY-OPS-4. So the
 * E2E cannot run without a provider to fetch from, and the real one must never
 * be involved: it would mean real API calls, real credentials, and a test that
 * only works when someone's sandbox account happens to behave.
 *
 * This stands in for that provider on loopback. It is deliberately dumb: it
 * returns exactly what the test told it to return, including the states a real
 * provider will not produce on demand — an authorized-but-uncaptured payment,
 * an amount that disagrees with the order, and a provider that is simply DOWN.
 *
 * ── CONTROL PROTOCOL ─────────────────────────────────────────────────────
 *   POST /__set   {payment_id, status, amount_paise, order_id}   → arm a payment
 *   POST /__set   {payment_id, mode:'unreachable'}               → arm a failure
 *   GET  /v1/payments/{id}                                        → provider fetch
 *
 * `mode:'unreachable'` answers 503, which is what E7 — the headline case —
 * needs: a payment whose first fetch fails must be RETRYABLE, not recorded as
 * processed. That distinction is the defect PAY-OPS-4 exists to fix, and it
 * cannot be exercised against a provider that always answers.
 *
 * Loopback only. Holds no credentials, reaches no network, stores nothing on
 * disk, and forgets everything when it exits.
 *
 *   node scripts/payops4_provider_stub.js [port]      (default 8123)
 */
const http = require('http');

const PORT = parseInt(process.argv[2] || process.env.ZX_STUB_PORT || '8123', 10);

/** payment_id → armed state. In memory only; the test arms what it needs. */
const armed = new Map();

const json = (res, code, body) => {
  const raw = JSON.stringify(body);
  res.writeHead(code, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(raw) });
  res.end(raw);
};

const server = http.createServer((req, res) => {
  // ── control plane ──────────────────────────────────────────────────────
  if (req.method === 'POST' && req.url === '/__set') {
    let body = '';
    req.on('data', (c) => { body += c; });
    req.on('end', () => {
      let cfg;
      try { cfg = JSON.parse(body || '{}'); }
      catch (e) { return json(res, 400, { error: 'bad json' }); }
      if (!cfg.payment_id) return json(res, 400, { error: 'payment_id required' });
      armed.set(String(cfg.payment_id), cfg);
      return json(res, 200, { ok: true, armed: armed.size });
    });
    return;
  }

  // ── provider plane ─────────────────────────────────────────────────────
  const m = /^\/v1\/payments\/([^/?]+)/.exec(req.url || '');
  if (req.method === 'GET' && m) {
    const id = decodeURIComponent(m[1]);
    const cfg = armed.get(id);

    // Never invent a payment. An unarmed id is a payment the provider has
    // never heard of, and answering 404 is what a real provider does — the
    // caller must treat that as a refusal, not as a transient.
    if (!cfg) {
      return json(res, 404, { error: { code: 'BAD_REQUEST_ERROR', description: 'payment not found' } });
    }

    // A provider that is DOWN. 503 rather than a connection reset because the
    // adapter must classify it as RETRYABLE — an unreachable provider says
    // nothing about whether the money moved, so recording it as terminal is
    // precisely the defect E7 guards.
    if (cfg.mode === 'unreachable') {
      return json(res, 503, { error: { code: 'SERVER_ERROR', description: 'provider unavailable' } });
    }

    return json(res, 200, {
      id,
      entity: 'payment',
      amount: Number(cfg.amount_paise || 0),   // paise, as the real API reports
      currency: cfg.currency || 'INR',
      status: String(cfg.status || 'captured'),
      order_id: cfg.order_id || null,
      captured: String(cfg.status || 'captured') === 'captured',
      method: cfg.method || 'netbanking',
    });
  }

  json(res, 404, { error: 'not found' });
});

// Bind loopback explicitly. A stub that answers on a routable interface is a
// fake payment provider anyone on the network can talk to.
server.listen(PORT, '127.0.0.1', () => {
  console.log(`provider stub listening on 127.0.0.1:${PORT}`);
});
