#!/usr/bin/env bash
# =============================================================================
# Path A — US admin-server bootstrap (Steps 2–5 of PATH_A_US_SERVER_RUNBOOK.md)
# RUN THIS *ON THE NEW US LIGHTSAIL BOX* after you SSH in.
#
# It installs the exact stack, clones the app, installs deps, and sets perms.
# It does NOT copy secrets (that's a separate scp step — see the runbook §4) and
# it does NOT touch DNS. Safe to re-run (idempotent).
#
# Usage:
#   export REPO_URL="git@github.com:<you>/<zenxii-repo>.git"   # <-- set this first
#   bash us_server_bootstrap.sh
# =============================================================================
set -euo pipefail

APP_DIR="/opt/zenxii"
BRANCH="yug_b1_t"                 # SAME branch as live
REPO_URL="${REPO_URL:-}"

if [ -z "$REPO_URL" ]; then
  echo "ERROR: set REPO_URL first, e.g.:  export REPO_URL=git@github.com:you/zenxii.git"
  exit 1
fi

echo "== 1/5  Install the stack (Apache + PHP 8 + the app's extensions) =="
sudo apt-get update -y
sudo apt-get install -y apache2 php php-cli libapache2-mod-php \
     php-curl php-mbstring php-gd php-zip php-xml php-bcmath \
     composer git rsync certbot python3-certbot-apache
sudo a2enmod rewrite headers expires deflate ssl
echo "   PHP: $(php -v | head -1)"
php -m | grep -qiE '^curl$'     && echo "   ext curl ok"     || { echo "   MISSING curl";     exit 1; }
php -m | grep -qiE '^mbstring$' && echo "   ext mbstring ok" || { echo "   MISSING mbstring"; exit 1; }
php -m | grep -qiE '^gd$'       && echo "   ext gd ok"       || { echo "   MISSING gd";       exit 1; }

echo "== 2/5  Clone the app ($BRANCH) =="
sudo mkdir -p "$APP_DIR"
sudo chown -R "$USER" "$APP_DIR"
if [ ! -d "$APP_DIR/.git" ]; then
  git clone "$REPO_URL" "$APP_DIR"
fi
cd "$APP_DIR"
git fetch origin
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH" || true

echo "== 3/5  Composer deps (prod) =="
composer install --no-dev --optimize-autoloader

echo "== 4/5  Writable dirs =="
mkdir -p application/temp
sudo chown -R www-data:www-data application/cache application/logs application/sessions uploads application/temp 2>/dev/null || true
sudo chmod -R 775 application/cache application/logs application/sessions uploads application/temp

echo "== 5/5  Apache vhost =="
if [ -f deployment/zenxii_us_vhost.conf ]; then
  sudo cp deployment/zenxii_us_vhost.conf /etc/apache2/sites-available/zenxii.conf
  sudo a2ensite zenxii.conf
  sudo a2dissite 000-default.conf 2>/dev/null || true
  sudo systemctl reload apache2
  echo "   vhost installed."
else
  echo "   NOTE: deployment/zenxii_us_vhost.conf not found — copy your Mumbai vhost"
  echo "         into /etc/apache2/sites-available/ and 'a2ensite' it (safest: reuse"
  echo "         the exact working config from the Mumbai box)."
fi

cat <<'NEXT'

======================================================================
STACK READY. Now do these THREE things manually (they need your keys):

  A) Copy the 4 non-git items FROM Mumbai (run from your laptop):
       scp admin@65.0.240.198:/opt/zenxii/application/config/graderadmin-firebase-adminsdk-*.json  admin@US_IP:/opt/zenxii/application/config/
       scp admin@65.0.240.198:/opt/zenxii/.env       admin@US_IP:/opt/zenxii/.env
       scp admin@65.0.240.198:/opt/zenxii/.htaccess  admin@US_IP:/opt/zenxii/.htaccess
       rsync -avz admin@65.0.240.198:/opt/zenxii/uploads/  admin@US_IP:/opt/zenxii/uploads/

  B) TLS:  sudo certbot --apache -d us.zenxii.com      (or a Cloudflare Origin cert)

  C) SMOKE TEST on this box BEFORE any DNS change:
       curl -s -o /dev/null http://localhost/admin_login
       # log in via browser to us.zenxii.com, click a few modules, then:
       tail -n 20 /opt/zenxii/application/logs/perf_probe.log
       # SUCCESS = fb_ms per read ~10-30ms (was ~1700ms in Mumbai).
======================================================================
NEXT
