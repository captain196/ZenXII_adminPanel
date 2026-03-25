# HTTPS Deployment Guide — GraderIQ School ERP

## Overview

This guide covers the complete HTTPS setup for Apache + PHP + CodeIgniter 3.
Three layers of enforcement are configured (belt-and-suspenders):

1. **Apache VirtualHost** — port 80 → 301 redirect to port 443
2. **`.htaccess` mod_rewrite** — catches any request that bypasses VHost
3. **PHP-level redirect** — `MY_Controller` fallback via `FORCE_HTTPS=true` in `.env`

---

## Prerequisites

```bash
# Enable required Apache modules
sudo a2enmod rewrite ssl headers socache_shmcb expires deflate
sudo systemctl restart apache2
```

---

## Step 1: Obtain SSL Certificate

### Option A: Let's Encrypt (free, recommended)

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d school.example.com -d www.school.example.com
```

Certbot auto-configures renewal. Verify:
```bash
sudo certbot renew --dry-run
```

### Option B: Commercial certificate

Place certificate files:
```
/etc/ssl/certs/school.example.com.crt      # Certificate + chain
/etc/ssl/private/school.example.com.key     # Private key
```

---

## Step 2: Apache VirtualHost

Copy `deployment/apache-vhost.conf` to your Apache config:

```bash
sudo cp deployment/apache-vhost.conf /etc/apache2/sites-available/grader.conf

# Edit: replace school.example.com with your domain
# Edit: update certificate paths if not using Let's Encrypt
sudo nano /etc/apache2/sites-available/grader.conf

# Enable and reload
sudo a2ensite grader
sudo a2dissite 000-default   # disable default site
sudo apache2ctl configtest   # verify syntax
sudo systemctl reload apache2
```

---

## Step 3: Enable .htaccess HTTPS redirect

Edit `.htaccess` in the project root and uncomment these lines:

```apache
RewriteCond %{HTTPS} !=on [OR]
RewriteCond %{HTTP:X-Forwarded-Proto} =http
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

---

## Step 4: Enable PHP-level HTTPS redirect

Add to `.env`:

```
FORCE_HTTPS=true
```

This activates the redirect in `MY_Controller::__construct()` as a fallback
for any requests that bypass Apache's redirect (e.g., proxied requests).

---

## Step 5: Secure cookies (automatic)

`config.php` now auto-detects HTTPS:

```php
$config['cookie_secure'] = $_is_https;  // Auto-enabled when serving over HTTPS
```

No manual change needed. When the server detects HTTPS, secure flag is set on
all cookies, preventing them from being sent over plain HTTP.

---

## Step 6: Enable HSTS

### Phase 1: Testing (24 hours)

In `.htaccess` or the VirtualHost config, uncomment:
```apache
Header always set Strict-Transport-Security "max-age=86400; includeSubDomains" env=HTTPS
```

The PHP-level HSTS header in `MY_Controller` is already active with `max-age=86400`.

### Phase 2: Production (after 24 hours of no issues)

Increase max-age to 1 year:

**In `MY_Controller.php`** (~line 274):
```php
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
```

**In `.htaccess`**:
```apache
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains" env=HTTPS
```

**In `apache-vhost.conf`**: Already set to 31536000.

### Phase 3: HSTS Preload (optional, permanent)

After 1 year max-age is confirmed stable:
```apache
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
```
Submit at https://hstspreload.org — this is **permanent** (very hard to undo).

---

## Configuration Summary

### Files modified:

| File | Change |
|------|--------|
| `.htaccess` | HTTPS redirect rules (commented), HSTS header, security headers, sensitive file blocking |
| `application/config/config.php` | Proxy-aware HTTPS detection, `cookie_secure` auto-enabled |
| `application/core/MY_Controller.php` | PHP HTTPS redirect, HSTS header, `upgrade-insecure-requests` CSP, `Permissions-Policy` |
| `.env.example` | `FORCE_HTTPS` and `APP_DOMAIN` variables |
| `deployment/apache-vhost.conf` | Full production VirtualHost template |

### Security headers sent (complete list):

| Header | Value | Source |
|--------|-------|--------|
| `Strict-Transport-Security` | `max-age=86400; includeSubDomains` | PHP + Apache |
| `Content-Security-Policy` | Full policy with `upgrade-insecure-requests` | PHP |
| `X-Frame-Options` | `DENY` | PHP + Apache |
| `X-Content-Type-Options` | `nosniff` | PHP + Apache |
| `X-XSS-Protection` | `1; mode=block` | PHP |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | PHP |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=()` | PHP + Apache |
| `Cache-Control` | `no-store, no-cache, must-revalidate` | PHP |

### Cookie security:

| Setting | Value | Effect |
|---------|-------|--------|
| `cookie_secure` | `true` (auto) | Cookie only sent over HTTPS |
| `cookie_httponly` | `true` | JS cannot read session cookie |
| `cookie_samesite` | `Strict` | No cross-site cookie leakage |
| `sess_match_ip` | `true` | Session bound to client IP |
| `sess_regenerate_destroy` | `true` | Old session destroyed on regeneration |

---

## Verification Checklist

After deployment, run these checks:

### 1. HTTPS redirect
```bash
curl -I http://school.example.com/Grader/school/
# Expected: HTTP/1.1 301 Moved Permanently
# Location: https://school.example.com/Grader/school/
```

### 2. HSTS header
```bash
curl -sI https://school.example.com/Grader/school/ | grep -i strict
# Expected: Strict-Transport-Security: max-age=86400; includeSubDomains
```

### 3. Cookie flags
Open browser DevTools → Application → Cookies:
- `grader_session` should show: Secure ✓, HttpOnly ✓, SameSite: Strict

### 4. SSL grade
Test at: https://www.ssllabs.com/ssltest/
- Target: **A+** grade (achievable with this config)

### 5. Security headers
Test at: https://securityheaders.com
- Target: **A** grade

### 6. CSP violations
Open browser DevTools → Console. Navigate through major pages.
Any CSP violations will appear as errors. Fix by adding the blocked domain
to the CSP policy in `MY_Controller::_send_security_headers()`.

---

## Troubleshooting

### Infinite redirect loop
- Check if a load balancer terminates SSL. Add to `.env`: `FORCE_HTTPS=false`
  and rely on the LB's redirect instead.
- Or set `$_SERVER['HTTPS'] = 'on';` in `index.php` if behind an SSL-terminating proxy.

### Session lost after HTTPS switch
- Clear all old HTTP cookies in the browser.
- With `cookie_secure=true`, cookies set over HTTP won't be sent over HTTPS.

### Login redirect breaks with SameSite=Strict
- If using OAuth/external login redirects, temporarily set `cookie_samesite=Lax`.
- This ERP uses password login only, so `Strict` is correct.

### Mixed content warnings
- The `upgrade-insecure-requests` CSP directive auto-upgrades `http://` to `https://`.
- For persistent issues, search views for hardcoded `http://` URLs and update them.

---

## XAMPP Local Development

For local dev (no SSL), these features degrade gracefully:

- `FORCE_HTTPS` defaults to `false` in `.env` → no redirect
- `cookie_secure` auto-detects `$_is_https` → stays `false` on localhost
- HSTS header only sent when `$_SERVER['HTTPS'] === 'on'` → skipped
- `.htaccess` HTTPS redirect is commented out by default
- `upgrade-insecure-requests` CSP only added over HTTPS → skipped

No changes needed for local development.
