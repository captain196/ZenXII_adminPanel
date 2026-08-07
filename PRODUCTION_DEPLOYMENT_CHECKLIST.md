# ZenX Fees Module — Production Deployment Checklist

> Follow top-to-bottom when onboarding a new school. Items marked **[secret]** must
> never be committed to git. Verify each box before going live.

## 1. Backend configuration (Admin Panel / CI3)
- [ ] Deploy the ZenX admin codebase to the production host over **HTTPS** (valid TLS cert).
- [ ] Set the web-server environment variable **`CI_ENV=production`** (gates destructive
      test endpoints via `_reject_in_production()`; enables production error handling).
- [ ] Confirm `application/config/config.php` `base_url` resolves to the production host.
- [ ] Place the Firebase Admin service-account JSON at
      `application/config/graderadmin-firebase-adminsdk-*.json` **[secret]** (server-only; outside web root if possible).
- [ ] Confirm PHP `max_execution_time`/memory are adequate (fee generation batches, refunds).

## 2. Firestore rules & indexes
- [ ] Deploy the updated rules: `firebase deploy --only firestore:rules`
      (includes the gateway-secret read lock — `feeSettings` `type='gateway'` is client-unreadable).
- [ ] Deploy composite indexes: `firebase deploy --only firestore:indexes`
      (fee collections are queried by `schoolId`+`session`).
- [ ] Smoke-check: a parent token can read **only** its own `feeDemands/feeReceipts/feeDefaulters`;
      cannot read another student's, another tenant's, or the gateway doc.

## 3. Razorpay setup (per school, Admin Panel → Fees → Payment Gateway)
- [ ] Enter **Live Key ID** (`rzp_live_…`).
- [ ] Enter **Live Key Secret** **[secret]**.
- [ ] Set **Mode = live** (the form rejects a `rzp_live_*` key with mode≠live).
- [ ] Set **Active = on**.
- [ ] Confirm the secret is stored masked in the admin UI and is **not** client-readable.

## 4. Webhook configuration
- [ ] In the Razorpay dashboard, add the webhook URL:
      `https://<prod-host>/fee_management/payment_webhook`
- [ ] Subscribe to `payment.captured` (and `payment.failed` if used).
- [ ] Generate a **Webhook Secret** in Razorpay; enter the same value in the gateway config **[secret]**.
      (Live mode **rejects** webhooks when the secret is unset.)
- [ ] Confirm the production host IP is reachable by Razorpay (default allowlist already includes Razorpay IPs).

## 5. Parent App release configuration (`SchoolSyncParent`)
- [ ] Build the release: `./gradlew :app:bundleRelease`
      (release `BASE_URL` defaults to **`https://www.zenxii.com/`**; localhost is debug-only.
      For a staging/other host, override: `-PPROD_BASE_URL=https://<host>/`).
- [ ] Point `google-services.json` at the **production** Firebase project.
- [ ] Network security is already production-hardened (`src/main/res/xml/network_security_config.xml`:
      HTTPS-only, system CAs only; a debug-only override keeps dev cleartext). Confirm no local edits re-enabled cleartext.
- [ ] Verify the release build (see §7 verification) exposes **no** dev-settings dialog, **no**
      base-URL override, **no** debug logging.

## 6. Release signing (Parent App)
- [ ] Create/locate the upload keystore **[secret]** (never commit).
- [ ] Add a `signingConfigs { release { … } }` block and reference it from `buildTypes.release`
      (keystore path/passwords supplied via Gradle properties / env, not hardcoded).
- [ ] Build a **signed** AAB and upload to Google Play (internal testing → production track).

## 7. Production environment variables & release verification
| Variable / property | Where | Purpose |
|---|---|---|
| `CI_ENV=production` | web server env | gate test endpoints, error handling |
| `PROD_BASE_URL` | Gradle property (release build) | Parent App production backend (HTTPS) |
| Keystore path/password | Gradle property / env **[secret]** | release signing |
| Razorpay key/secret/webhook-secret | Firestore `feeSettings` gateway doc **[secret]** | live payments |
| Firebase service account | server file **[secret]** | Admin SDK writes |

**Release build verification (run once per release):**
- [ ] Install the signed release APK; confirm long-pressing the login title does **nothing** (no dev dialog).
- [ ] Confirm no base-URL override is possible; app talks only to `PROD_BASE_URL`.
- [ ] Confirm no HTTP body logging in logcat.
- [ ] End-to-end smoke: login → view dues → pay (live ₹1 test) → receipt → verify accounting journal posted.

---
_Backend + Admin Panel + Firestore rules are code-certified. This checklist covers the per-deployment configuration a deployment engineer supplies._
