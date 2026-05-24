# Deployment

> ⚠️ **This is a simulation / demo product.**
> No real funds are ever moved. Trade outcomes are decided manually by an
> administrator — there is no real market data, no broker integration, and no
> regulated counterparty. It exists for **practice and education only** and
> must not be used to handle real customer money.

This document covers production-style deployment of both apps. For local
development setup, see [README.md](README.md).

---

## Architecture at a glance

```
                        ┌──────────────────┐
   Browser  ──HTTPS──►  │  React SPA (CDN  │
                        │   or static host)│
                        └────────┬─────────┘
                                 │  axios  (Bearer token)
                                 ▼
                        ┌──────────────────┐
                        │  Laravel API     │ ──► database (MySQL / Postgres / SQLite)
                        │  (nginx + FPM)   │ ──► queue worker (database / Redis)
                        └──────────────────┘
```

The SPA is fully static — any CDN or object store (S3+CloudFront, Netlify,
Vercel, Cloudflare Pages, nginx) works. The Laravel API needs PHP-FPM behind
a web server.

---

## Backend deployment (Laravel)

### 1. Server requirements

- PHP **8.2+** with extensions: `pdo`, `mbstring`, `openssl`, `tokenizer`,
  `xml`, `ctype`, `json`, `bcmath`, plus the driver for your DB
  (`pdo_mysql`, `pdo_pgsql`, or `pdo_sqlite`).
- Composer **2.x**.
- A web server: nginx + PHP-FPM (recommended), Apache + mod_php, or Laravel
  Octane (Swoole / RoadRunner).
- A process supervisor (systemd or Supervisor) for the queue worker.

### 2. Environment variables

Copy `.env.example` to `.env` on the server and fill in production values.
Critical keys:

| Variable                    | Purpose                                                                 |
| --------------------------- | ----------------------------------------------------------------------- |
| `APP_NAME`                  | Display name.                                                            |
| `APP_ENV`                   | `production`                                                             |
| `APP_KEY`                   | Generated once via `php artisan key:generate`. Treat as a secret.        |
| `APP_DEBUG`                 | `false` in production — leaking stack traces is a vulnerability.         |
| `APP_URL`                   | Canonical URL of the API, e.g. `https://api.example.com`.                |
| `FRONTEND_URL`              | Canonical URL of the SPA, e.g. `https://app.example.com`. Used by CORS.  |
| `SANCTUM_STATEFUL_DOMAINS`  | Comma-separated hosts (no scheme) of the SPA, e.g. `app.example.com`.    |
| `DB_CONNECTION`             | `mysql`, `pgsql`, or `sqlite`.                                           |
| `DB_HOST` / `DB_PORT`       | DB host/port (not needed for sqlite).                                    |
| `DB_DATABASE`               | DB name (or sqlite file path).                                           |
| `DB_USERNAME` / `DB_PASSWORD` | DB credentials.                                                        |
| `SESSION_DRIVER`            | `database` (default) or `redis`.                                         |
| `CACHE_STORE`               | `database`, `redis`, or `file`.                                          |
| `QUEUE_CONNECTION`          | `database` for simple setups, `redis` for higher throughput.             |
| `MAIL_*`                    | SMTP credentials if your deployment sends mail.                          |

Set `APP_DEBUG=false`, `APP_ENV=production`, and a long random `APP_KEY`
before serving any traffic.

### 3. Install dependencies

On the server, in `backend/`:

```bash
composer install --no-dev --optimize-autoloader
```

### 4. Cache configuration

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache
```

Re-run these after every deploy. If you change any `.env` value, run
`php artisan config:clear` first or your update will be ignored.

### 5. Run migrations

```bash
php artisan migrate --force
```

`--force` skips the "are you sure?" prompt that artisan normally shows in
production.

### 6. Run seeders (carefully — see the admin section below)

The default `DatabaseSeeder` creates a hard-coded admin and test users. **Do
not run it on production.** For production, only seed reference data:

```bash
php artisan db:seed --class=CurrencyPairSeeder --force
```

This populates the active EURUSD / GBPUSD / USDJPY / AUDUSD / USDCAD rows.

### 7. Storage permissions

The web server user (`www-data`, `nginx`, etc.) must be able to write
`storage/` and `bootstrap/cache/`:

```bash
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
```

### 8. Serve the API

#### nginx + PHP-FPM (recommended)

```nginx
server {
    listen 443 ssl http2;
    server_name api.example.com;

    root /var/www/forex/backend/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/api.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.example.com/privkey.pem;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Plus an HTTP→HTTPS redirect for port 80. Reload nginx with
`sudo nginx -t && sudo systemctl reload nginx`.

#### Alternatives

- **Apache + mod_php**: point `DocumentRoot` at `backend/public` and ship
  the bundled `public/.htaccess`.
- **Laravel Octane**: `composer require laravel/octane && php artisan octane:install`,
  then run `php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000`
  behind nginx as a reverse proxy. Octane keeps the framework in memory between
  requests and is dramatically faster, but requires more discipline (no leaky
  singletons).

### 9. Queue worker

Although the current app does not yet dispatch background jobs, the framework
is configured (`QUEUE_CONNECTION=database` by default) so that future work
(emails, webhooks, reports) Just Works. Run a worker as a long-lived service.

#### Supervisor (recommended for non-systemd hosts)

`/etc/supervisor/conf.d/forex-worker.conf`:

```ini
[program:forex-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/forex/backend/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/forex/worker.log
stopwaitsecs=3600
```

Then:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start forex-worker:*
```

#### systemd

`/etc/systemd/system/forex-worker.service`:

```ini
[Unit]
Description=Forex Laravel queue worker
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/forex/backend
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Then `systemctl daemon-reload && systemctl enable --now forex-worker`.

**After every deploy**, restart the worker so it loads the new code:

```bash
php artisan queue:restart
```

### 10. Scheduler (optional)

If you later add scheduled tasks, register a single cron entry:

```cron
* * * * * cd /var/www/forex/backend && php artisan schedule:run >> /dev/null 2>&1
```

### 11. Rate limits

The API ships with throttle middleware on the sensitive endpoints:

- `POST /api/register` — 5 / minute / IP
- `POST /api/login` — 10 / minute / IP
- `POST /api/trades` — 30 / minute / IP

These use Laravel's default in-memory rate limiter, which is per-process.
For a horizontally-scaled deployment, set `CACHE_STORE=redis` so the limiter
shares state across nodes.

### 12. Deploy script outline

A minimal `deploy.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail
cd /var/www/forex/backend
git pull --ff-only
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan queue:restart
sudo systemctl reload php8.2-fpm
```

---

## Seeding the first admin account safely

> **Never expose admin creation through a public route.** The default
> `DatabaseSeeder` creates `admin@example.com / password` — fine for local
> development, catastrophic in production.

The repo ships an artisan command for this — run it on the server:

```bash
# Interactive (recommended — password is prompted hidden):
php artisan admin:create

# Non-interactive (for provisioning scripts):
php artisan admin:create \
  --name="Ops Admin" \
  --email="ops@example.com" \
  --password="$(openssl rand -base64 24)"
```

The command:

- Validates name, email (must be unique), and password (≥ 8 chars).
- Hashes the password with bcrypt.
- Sets `role=admin`, `status=active`, and `email_verified_at=now()`.

After the first admin exists, you can manage all other users — including
promoting more admins — directly from the `/admin/users` UI. Treat the
initial admin password like any other production secret: rotate it after
first login.

If you ever need to disable the production seeder entirely, simply do not run
`php artisan db:seed`; only the `CurrencyPairSeeder` is safe to seed in
production, and you can call it explicitly with `--class=CurrencyPairSeeder`.

---

## Frontend deployment (React + Vite)

### 1. Configure the production API URL

Build-time env vars for Vite must be prefixed with `VITE_`. Create
`frontend/.env.production` (or set the var in your CI/CD environment):

```
VITE_API_URL=https://api.example.com/api
```

This is read by [src/lib/api.ts](frontend/src/lib/api.ts) at build time and
baked into the bundle. Changing it requires a rebuild.

### 2. Build

```bash
cd frontend
npm ci
npm run build
```

The output lands in `frontend/dist/` (HTML + hashed JS + CSS assets). The
bundle is fully static — no Node runtime is required to serve it.

### 3. Serve the bundle

Any static-file host works. Two common options:

#### nginx (alongside the API, separate vhost)

```nginx
server {
    listen 443 ssl http2;
    server_name app.example.com;

    root /var/www/forex/frontend/dist;
    index index.html;

    ssl_certificate     /etc/letsencrypt/live/app.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app.example.com/privkey.pem;

    # SPA fallback — every URL serves index.html so React Router can take over.
    location / {
        try_files $uri /index.html;
    }

    # Hashed assets are immutable — cache them aggressively.
    location /assets/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

#### Object storage + CDN

Upload `dist/` to S3 (or any object store), front it with CloudFront /
Cloudflare / Fastly:

- Set the bucket's index document to `index.html`.
- Configure the CDN to **return `index.html` for any 404** so client-side
  routes resolve.
- Cache `/assets/*` for a year (filenames include a content hash).
- Cache `/index.html` for a short TTL or `no-store` so deploys take effect
  quickly.

### 4. CORS

The Laravel API's [config/cors.php](backend/config/cors.php) reads
`FRONTEND_URL` from the environment and adds it to `allowed_origins`. Set
`FRONTEND_URL=https://app.example.com` on the API server before serving
traffic. Without this, the browser will block every API call from the SPA.

### 5. Token storage

The SPA stores its Sanctum token in `localStorage`. This is convenient but
trades off XSS risk against simplicity. If your threat model requires it,
swap to httpOnly-cookie session auth (Sanctum supports it via the
`statefulApi` middleware already wired in `bootstrap/app.php`).

---

## Post-deploy smoke test

After both apps are up:

```bash
# API is alive
curl -sf https://api.example.com/api/health

# CORS lets the SPA in
curl -sI -X OPTIONS https://api.example.com/api/login \
  -H "Origin: https://app.example.com" \
  -H "Access-Control-Request-Method: POST"
# Expect: HTTP/2 204 with Access-Control-Allow-Origin: https://app.example.com

# Admin login works
curl -s https://api.example.com/api/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"ops@example.com","password":"<the password you set>"}'
# Expect: 200 with { user, token }
```

Then open the SPA in a browser, sign in as the admin, and the `/admin`
overview should populate with `total_users: 1` (just you) and zeroes
everywhere else.

---

## Reminder

This system **does not** trade real money, settle against any exchange, or
interact with a custodian. The "outcome" of every trade is set by an admin
through `POST /api/admin/trades/{id}/resolve`. Use it to teach mechanics, run
exercises, or prototype UI flows — never to take deposits from real users.
