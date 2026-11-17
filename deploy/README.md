# Deploying SUN-ATES (spec section 10)

**Status: these files are a prepared kit, not a record of a deployment.** None of them has been run on a real
server yet: hosting, the domain and TLS are still to be allocated by the Directorate of ICT. Test them on a
staging host first.

Target (spec sections 8 and 10): one Linux host (Ubuntu 24.04 LTS or similar), separate from SorotiUniERP's
Windows/IIS environment, running Nginx, PHP 8.3-FPM, MySQL 8.x, Supervisor and cron. Three environments:
local, staging, production; staging and local use **sandbox** SMS/WhatsApp credentials only.

| File | Goes to | Purpose |
|---|---|---|
| `nginx/sunates.conf` | `/etc/nginx/sites-available/` | HTTPS, routing between Laravel and the PWA |
| `supervisor/sunates-worker.conf` | `/etc/supervisor/conf.d/` | keeps the queue worker running |
| `cron/sunates.cron` | `/etc/cron.d/sunates` | the Laravel scheduler, and the nightly backup |
| `backup/sunates-backup.sh` | `/usr/local/bin/` | encrypted MySQL backups with retention |
| `env.production.example` | `backend/.env` on the server | production settings (fill secrets on the server) |

## First installation

```bash
# packages (Ubuntu)
sudo apt install nginx mysql-server supervisor certbot python3-certbot-nginx gnupg \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-intl php8.3-xml php8.3-zip php8.3-gd php8.3-curl php8.3-bcmath
# Composer 2 and Node 22 from their official installers.

# database (use a strong password; the app user needs no global privileges)
sudo mysql -e "CREATE DATABASE sunates CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
  CREATE USER 'sunates'@'localhost' IDENTIFIED BY '<password>';
  GRANT ALL ON sunates.* TO 'sunates'@'localhost';"

# code
sudo mkdir -p /var/www/sunates && sudo chown $USER /var/www/sunates
git clone <repository> /var/www/sunates
cd /var/www/sunates/backend
composer install --no-dev --optimize-autoloader
cp ../deploy/env.production.example .env      # then fill in the secrets
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force                     # the fixed roles and the three survey questionnaires only
php artisan sunates:create-staff ict.admin@soroti.ac.ug --role=ict_admin --name="ICT Admin"
php artisan config:cache && php artisan route:cache && php artisan view:cache
sudo chown -R www-data:www-data storage bootstrap/cache

cd ../pwa && npm ci && npm run build            # PWA served from pwa/dist by Nginx

# web server and TLS
sudo cp ../deploy/nginx/sunates.conf /etc/nginx/sites-available/sunates.conf   # set the real domain first
sudo ln -s /etc/nginx/sites-available/sunates.conf /etc/nginx/sites-enabled/
sudo certbot --nginx -d alumni.example.ac.ug     # Let's Encrypt (spec section 9); renews automatically
sudo nginx -t && sudo systemctl reload nginx

# background processes
sudo cp ../deploy/supervisor/sunates-worker.conf /etc/supervisor/conf.d/ && sudo supervisorctl reread && sudo supervisorctl update
sudo cp ../deploy/cron/sunates.cron /etc/cron.d/sunates
```

Do **not** run `DemoDataSeeder` on staging or production (it refuses outside `local`/`testing` anyway).

## Every deployment

```bash
cd /var/www/sunates && git pull
cd backend && composer install --no-dev --optimize-autoloader
php artisan down --retry=60
php artisan migrate --force
php artisan sunates:sync-surveys
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
cd ../pwa && npm ci && npm run build
```

## Backups (spec section 9)

1. Create a GPG key pair for backups on an ICT workstation; import **only the public key** on the server
   (`gpg --import ict-backups.pub`). Keep the private key off the server, in two places.
2. Put the MySQL dump credentials in `/root/.my.cnf` (`[mysqldump]` section, mode 600) and the settings in
   `/etc/sunates-backup.env` (see the script header).
3. Install the script and the cron entry (above). Check `/var/log/sunates-backup.log` the next morning.

Retention: 14 daily and 8 weekly encrypted copies on the server, plus an off-site copy if `OFFSITE` is set.
The verification-request logs can live in a separate database (`VERIFICATION_DB_CONNECTION`), which the script
backs up separately when `VERIFICATION_DB` is set.

### Restoring a backup

On the machine holding the private key (staging first, never straight onto production):

```bash
gpg --decrypt sunates-20261017-013000.sql.gz.gpg | gunzip > restore.sql
mysql -e "CREATE DATABASE sunates_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
mysql sunates_restore < restore.sql
# check: row counts of alumni_profiles, survey_responses, users look right; then point a staging .env at it.
shred -u restore.sql
```

To recover production: put the site in maintenance (`php artisan down`), restore into a fresh database, switch
`DB_DATABASE`, `php artisan config:cache`, `php artisan up`. The same `APP_KEY` must be used, or encrypted
columns (registration codes, sessions) cannot be read.

**Test a restore on staging at least once a quarter** and record the date in the handover log.

## Security checklist before go-live

- [ ] `APP_DEBUG=false`, `APP_ENV=production`, `SESSION_SECURE_COOKIE=true`, real `APP_URL` / `SUNATES_PWA_URL` (https).
- [ ] TLS certificate issued and auto-renewing (`sudo certbot renew --dry-run`).
- [ ] `https://<domain>/.env` and `/.git/config` return 403/404.
- [ ] Response headers include `Strict-Transport-Security`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`.
- [ ] MySQL listens on localhost only; the app user has rights on `sunates` only.
- [ ] Firewall allows 22 (restricted), 80, 443 only.
- [ ] First backup produced, and a restore tested on staging.
- [ ] Data-protection review complete (spec section 4) before alumni data is collected at scale.
- [ ] A Content-Security-Policy has been tuned and tested on staging (not set by the app yet; Livewire needs inline scripts).
