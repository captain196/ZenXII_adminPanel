# Path A — Move the Admin Server next to the Database (US), keep everything else

**Why:** Firestore is permanently in **`nam5` (US)**. Each read from the Mumbai server is a **~1.7 s** intercontinental round-trip; pages do several → 5–14 s. Moving the **PHP server** into a US region next to `nam5` makes server↔DB **~10–30 ms**, so those same queries drop to near-zero. The only long hop left is user(India)→server(US), which happens **once per page** and is absorbed by Cloudflare.

**Expected result:** admin-panel pages **5–14 s → well under 1 s**. No data migration, no app-code change, no mobile-app change, no rules/index change — same `graderadmin` backend.

> **Nothing about the interconnected architecture changes.** The DB, RTDB, Storage, security rules, indexes, Cloud Functions, and the Teacher/Parent apps all keep pointing at the same `graderadmin` project. We are only relocating *where the PHP runs*.

---

## Decisions before you start

- **Region:** pick a US region close to `nam5` (Iowa + South Carolina):
  - **AWS Lightsail — Virginia (`us-east-1`) or Ohio (`us-east-2`)** ← recommended (matches your current AWS/Lightsail workflow; ~10–30 ms to `nam5`).
  - *Optimal but a platform switch:* GCP Compute Engine in `us-central1` (~1–3 ms to `nam5`). Only if you want to move clouds.
- **Same instance blueprint** as today (Ubuntu + Apache + PHP 8 + mod_php) so nothing else changes.
- **Keep the Mumbai box running** the whole time — it's your instant rollback.

---

## Step 1 — Provision the US instance
Create a Lightsail instance in **us-east-1/us-east-2**, same OS blueprint as Mumbai. Note its **static IP** (`US_IP`). Add your SSH key.

## Step 2 — Install the exact stack
```bash
ssh admin@US_IP        # (or the user your blueprint uses)
sudo apt update
sudo apt install -y apache2 php php-cli libapache2-mod-php \
     php-curl php-mbstring php-gd php-zip php-xml php-bcmath composer git
# extensions the app needs (match Mumbai): curl mbstring gd json openssl fileinfo zip
sudo a2enmod rewrite headers expires deflate ssl
php -v && php -m | grep -iE "curl|mbstring|gd|openssl|fileinfo|zip"   # sanity
```

## Step 3 — Deploy the app code
```bash
sudo mkdir -p /opt/zenxii && sudo chown -R $USER /opt/zenxii
git clone <your-repo-url> /opt/zenxii
cd /opt/zenxii
git checkout yug_b1_t          # SAME branch as live
composer install --no-dev --optimize-autoloader
```

## Step 4 — Copy the 4 things that are NOT in git (critical — do NOT skip)
From your Mumbai box (or local), copy these to the US box:

1. **Firebase service-account JSON** (skip-worktree / not committed):
   ```bash
   scp admin@65.0.240.198:/opt/zenxii/application/config/graderadmin-firebase-adminsdk-a1sml-2b5f1862a7.json \
       admin@US_IP:/opt/zenxii/application/config/
   ```
2. **`.env`** (skip-worktree — holds `AUTH_INTERNAL_SECRET` etc.):
   ```bash
   scp admin@65.0.240.198:/opt/zenxii/.env admin@US_IP:/opt/zenxii/.env
   ```
3. **Prod `.htaccess`** (RewriteBase + CSP/HSTS hardening — the repo version differs):
   ```bash
   scp admin@65.0.240.198:/opt/zenxii/.htaccess admin@US_IP:/opt/zenxii/.htaccess
   ```
4. **`uploads/` (2 MB — real user files: logos, circulars, brand):**
   ```bash
   rsync -avz admin@65.0.240.198:/opt/zenxii/uploads/ admin@US_IP:/opt/zenxii/uploads/
   ```

## Step 5 — Writable dirs + Apache vhost
```bash
cd /opt/zenxii
sudo chown -R www-data:www-data application/cache application/logs application/sessions uploads application/temp 2>/dev/null
sudo chmod -R 775 application/cache application/logs application/sessions uploads
# point Apache DocumentRoot at /opt/zenxii, AllowOverride All (copy the Mumbai vhost)
sudo systemctl restart apache2
```

## Step 6 — TLS on the origin
Cloudflare will front the site with `Full (strict)`, so the origin needs a valid cert:
```bash
sudo apt install -y certbot python3-certbot-apache
# temporarily point a test host (e.g. us.zenxii.com) at US_IP, then:
sudo certbot --apache -d us.zenxii.com
```
(Or use a **Cloudflare Origin Certificate** on the origin — even simpler; no Let's Encrypt renewal.)

## Step 7 — SMOKE-TEST the US box BEFORE any DNS flip ✅ (this is the money step)
Point a **temporary** host `us.zenxii.com` at `US_IP` (grey-cloud/DNS-only for now), then:

1. **Prove the latency fix — from the US box itself** (the perf probe I added logs on `localhost`):
   ```bash
   ssh admin@US_IP
   curl -s -o /dev/null http://localhost/admin_login   # warm it
   # log in via browser to us.zenxii.com, click a few modules, then:
   tail -n 20 /opt/zenxii/application/logs/perf_probe.log
   ```
   **Success = `fb_ms` per read drops from ~1700 ms → ~10–30 ms.** That is the whole point — confirm it here before cutting over.
2. **Functional smoke:** log in, open Dashboard / Students / Fees / Attendance, **upload a logo**, **save a school setting** → verify it works and files persist.

Only proceed to DNS if step 7 shows fast `fb_ms` **and** everything works.

## Step 8 — Cut over via Cloudflare (instant, reversible)
You'll already have Cloudflare from the infra runbook. Then it's just an origin IP swap:
1. Cloudflare → **DNS** → change the `zenxii.com` (and `www`) **A record** from `65.0.240.198` → **`US_IP`**, keep it **Proxied (orange)**.
2. SSL/TLS mode **Full (strict)**, HTTP/2+3 on, Brotli on (per the infra runbook).
3. Propagation is seconds (Cloudflare edge). Users hit the Cloudflare edge near India; Cloudflare fetches dynamic HTML from the US origin over its backbone; static assets cache at the edge.

## Step 9 — Post-cutover verification
- Log in on `https://www.zenxii.com` → click every module: Dashboard, Fees, Attendance, SIS, Homework, Stories, Gallery, Settings, Superadmin.
- Uploads + a settings save → change shows immediately.
- `curl -sI https://www.zenxii.com/ | grep -i alt-svc` → HTTP/3 via Cloudflare.
- **Users will need to log in again** (file sessions don't transfer — expected on a server move).

## Rollback (instant)
Cloudflare → DNS → point the A record back to **`65.0.240.198`** (Mumbai box, still running). Done in seconds. Keep Mumbai alive for a few days until the US box is proven.

---

## Gotchas (your setup specifically)
- **Firebase SA JSON + `.env` + `.htaccess` are NOT in git** — Step 4 copies them by hand. Miss the JSON → Firebase fails to init on the new box.
- **`uploads/` are real user files** — rsync them (Step 4.4), or logos/circulars 404.
- **Sessions are file-based** → everyone re-logs-in after cut-over (fine). If you also do the Redis step from the infra runbook, set it up on the US box.
- **Mobile apps are unaffected** — they read Firestore *directly* from phones (India→nam5), so this move does **not** speed them up. That's a separate decision (Path B / accept mobile SDK offline-cache). This runbook is about the **admin panel**, which is your acute pain.
- **The perf probe** I installed is `localhost`-guarded, so it will **not** run on `zenxii.com` (safe on prod). Use it via `localhost` on the US box for Step 7, then I'll remove it entirely once we're happy.
- **No gRPC concern** — the app uses the Firestore **REST** client, which just needs outbound HTTPS from the US box (default).

## After cut-over — what changes for our optimization work
Once server↔DB is ~10 ms, the config/bell caches still help (fewer reads = fewer cheap round-trips), but the **~1.7 s-per-read pressure is gone**, so most of the remaining "code tuning" becomes optional. We'd then focus on the few genuinely heavy pages and the front-end (bundling), not the read latency.
