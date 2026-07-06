# ZenXii Admin Panel — Phase 2 Infra Runbook (Class-C)

**Goal:** the biggest score movers (Network 45 → ~90, Caching 60 → ~85) that are **pure ops** — no application code changes, so **zero risk of breaking a module**. Do these on the Lightsail box / Cloudflare, in the order below (highest ROI, lowest risk first).

> Context: Lightsail Mumbai (`65.0.240.198`), app at `/opt/zenxii`, SSH user `admin`, Apache + mod_php (prefork), domain `zenxii.com`. TLS already terminates at the origin (OCSP stapling in `apache-vhost.conf`).

> **Golden rule:** change ONE thing, verify, then move on. Every step has a Verify and a Rollback.

---

## 0. Capture the "before" numbers (5 min)

So the gains are real, not guessed. Run from your laptop:

```bash
# TTFB + protocol + headers (repeat 3×, take the median)
curl -sS -o /dev/null -w "protocol=%{http_version}  ttfb=%{time_starttransfer}s  size=%{size_download}\n" https://www.zenxii.com/

# Is compression brotli/gzip/none? Is HTTP/2?
curl -sSI -H "Accept-Encoding: br,gzip" https://www.zenxii.com/tools/dist/css/AdminLTE.min.css | grep -iE "content-encoding|cache-control|vary|alt-svc"
```

Also run **Lighthouse** (Chrome DevTools → Lighthouse → Desktop) on the Dashboard and one heavy page (e.g. Fees). Save the report. You'll re-run these after each step.

---

## 1. Cloudflare — the single biggest win ⭐ (30–45 min, reversible in 1 click)

Gives your users **HTTP/2 + HTTP/3, brotli, edge caching, and DDoS absorption** **without touching the origin's Apache/MPM** (Cloudflare↔origin can stay HTTP/1.1). This alone moves Network the most.

### 1.1 Add the site
1. Create a free Cloudflare account → **Add site** `zenxii.com`.
2. Cloudflare scans your DNS. Confirm the `A` record for `zenxii.com` (and `www`) points to `65.0.240.198` and the cloud icon is **orange (Proxied)**.
3. Change your domain registrar's **nameservers** to the two Cloudflare gives you. (Propagation: minutes to a few hours. The site stays up the whole time.)

### 1.2 SSL/TLS (do this BEFORE traffic proxies, or you'll get redirect loops)
- **SSL/TLS → Overview → Full (strict)** (your origin already has a valid Let's Encrypt cert, so "strict" is correct — do **not** use "Flexible", it causes infinite redirects with the app's HTTPS redirect).
- **Edge Certificates → Always Use HTTPS: On**, **HTTP/3 (QUIC): On**, **TLS 1.3: On**, **Automatic HTTPS Rewrites: On**.

### 1.3 Speed
- **Speed → Optimization → Brotli: On** (edge brotli — instant, no origin change).
- Leave "Rocket Loader" **OFF** (it reorders/defers JS and can break jQuery-dependent inline scripts — not water-tight).
- Leave "Auto Minify" **OFF** for JS (can break inline handlers); CSS/HTML minify is lower risk but skip for now to stay water-tight.

### 1.4 Caching (static assets only — never HTML)
The app already sends `Cache-Control: no-store` on HTML (`MY_Controller.php`) and long cache on static types (`.htaccess`), so Cloudflare will respect that. Add ONE cache rule to be explicit:
- **Caching → Cache Rules → Create**:
  - If `URI Path` **matches** `*/tools/*` OR `*/assets/*` OR ends with `.css .js .png .jpg .webp .woff2 .woff .svg`
  - Then **Eligible for cache: Yes**, **Edge TTL: 7 days**, **Browser TTL: Respect origin**.
- Do **NOT** cache `*/index.php`, `*/superadmin/*`, or anything returning `Set-Cookie`.

### Verify
```bash
curl -sSI https://www.zenxii.com/ | grep -iE "server|alt-svc|cf-cache-status"          # expect: server: cloudflare, alt-svc h3
curl -sSI https://www.zenxii.com/tools/dist/css/skin-blue.min.css | grep -iE "cf-cache-status|content-encoding"  # HIT (2nd hit) + br
```
Then **log in and click through every module** (dashboard, fees, attendance, chat, stories, gallery, superadmin) — because proxying is the one step that touches all traffic. Watch for: login works, uploads work, no mixed-content warnings, no redirect loops.

### Rollback
Cloudflare dashboard → **DNS** → set the record to **DNS only (grey cloud)**. Traffic goes straight to origin again within seconds. (Nuclear option: point nameservers back at the registrar's defaults.)

---

## 2. Brotli on the origin (only if NOT using Cloudflare edge brotli) — 5 min

Cloudflare (step 1.3) already brotli-compresses to users, so this is optional. Do it only if you skip Cloudflare. The `.htaccess` already has the brotli block; the module just isn't loaded:

```bash
ssh admin@65.0.240.198
sudo a2enmod brotli
sudo systemctl reload apache2
```
### Verify
```bash
curl -sSI -H "Accept-Encoding: br" https://www.zenxii.com/tools/dist/css/AdminLTE.min.css | grep -i content-encoding   # expect: br
```
### Rollback
```bash
sudo a2dismod brotli && sudo systemctl reload apache2   # falls back to gzip (mod_deflate)
```

---

## 3. Redis sessions — unblocks parallel AJAX (20–30 min) ⚠️ test hard

**Why:** the file-session `flock` serializes a user's concurrent AJAX (dashboard fires 3–4 at once → they run one-at-a-time). Redis sessions remove that. **The app code is already prepared** — `sess_driver`/`sess_save_path` read from env (`config.php`), default stays `files`, so this is a pure infra + env flip with an instant rollback.

### 3.1 Install
```bash
ssh admin@65.0.240.198
sudo apt update && sudo apt install -y redis-server
# phpredis extension (match your PHP version):
sudo apt install -y php-redis        # or: sudo pecl install redis && add extension=redis.so
php -m | grep -i redis                # confirm the extension loaded
redis-cli ping                        # expect: PONG
```
Redis is light (a few MB for sessions) — fine on the small box. Optionally cap it: in `/etc/redis/redis.conf` set `maxmemory 128mb` and `maxmemory-policy allkeys-lru`, then `sudo systemctl restart redis-server`.

### 3.2 Flip the env (NOT the code)
Add to the app's environment (the same place `AUTH_INTERNAL_SECRET` etc. live — Apache `SetEnv` in the vhost, or the app's `.env`):
```
SESS_DRIVER=redis
SESS_SAVE_PATH=tcp://127.0.0.1:6379
```
Then reload Apache: `sudo systemctl reload apache2`.

### Verify (critical — sessions = login)
1. Log in fresh → you get in and stay logged in across page loads.
2. Open the **Dashboard** with DevTools → Network: the parallel AJAX calls should now overlap (not run strictly one-after-another).
3. `redis-cli KEYS 'ci_session*' | head` → you should see session keys appearing.
4. Click through a few modules; confirm no random logouts.

### Rollback (instant)
Remove the two env vars (or set `SESS_DRIVER=files`) and `sudo systemctl reload apache2`. Back to file sessions immediately. No code change needed.

> Note: an alternative/complement that needs **code** (not done here, higher touch): call `session_write_close()` at the top of read-only AJAX endpoints so the file lock releases early. Redis is the cleaner, lower-touch fix — do Redis first.

---

## 4. Deploy the missing Firestore composite indexes — 10 min

Some queries (stories, studentFlags, homework session-composite) have indexes defined in `firebase-rules/firestore.indexes.json` but **not deployed** → those queries can 500 (`FAILED_PRECONDITION`) or silently fall back to client-side sorts.

```bash
cd /path/to/Zennxii_adminPanel/firebase-rules
firebase deploy --only firestore:indexes        # ⬅ ONLY indexes — does NOT touch firestore.rules
```
- `--only firestore:indexes` is **safe re: the rules-clobber concern** — it deploys the index config, not `firestore.rules`, so it won't ship anyone's in-progress rules edits.
- Index builds run in the background (minutes to hours for large collections); existing queries keep working meanwhile.

### Verify
Firebase Console → Firestore → **Indexes** tab → the new composite indexes show **Enabled**. Then load Stories / Red Flags / Homework filtered views → no console 500s.

### Rollback
Indexes are additive and harmless; leave them. (Removing an index only reverts a query to needing client-sort.)

---

## 5. (Advanced / optional) HTTP/2 at the origin — only if you skip Cloudflare

With Cloudflare (step 1) your users already get HTTP/2+3, so **skip this** unless you're not using Cloudflare. mod_php needs prefork, which is incompatible with mod_http2, so this means moving to **php-fpm + mpm_event** — a bigger change. Do it in a maintenance window, test thoroughly. High touch; not recommended unless needed.

---

## Re-measure & expected movement

Re-run the **section 0** commands + Lighthouse after each step:

| Step | Moves | Expected |
|---|---|---|
| Cloudflare (1) | Network, Caching, TTFB (far users), protocol | HTTP/2+3 to users, brotli, edge cache → Network ~45→80+ |
| Brotli origin (2) | payload size | text assets ~15–20% smaller (if no CF) |
| Redis sessions (3) | API/concurrency | parallel AJAX unblocked → multi-call pages feel 3–4× snappier |
| Indexes (4) | DB reliability | no FAILED_PRECONDITION, no client-sort fallback |

**Order of impact:** 1 ≫ 3 > 4 > 2. Cloudflare is ~80% of the network win for ~20% of the effort — do it first.

---

## What this does NOT change
No controller, view, model, route, permission, or Firestore-schema change. Every step is reversible from a dashboard toggle or an env var. If any module misbehaves after a step, roll back **that step** and tell me the symptom.
