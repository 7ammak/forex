# Forex

> ⚠️ **Simulation / demo product** — no real funds are moved, no broker is
> connected, and trade outcomes are decided manually by an administrator. Use
> for practice or education only. See [DEPLOY.md](DEPLOY.md) for production
> deployment notes (including how to seed the first admin safely).

Monorepo with two apps:

| Folder      | Stack                                 | Dev port |
| ----------- | ------------------------------------- | -------- |
| `backend/`  | Laravel 11 + Sanctum (API)            | 8000     |
| `frontend/` | React + Vite + TypeScript (SPA)       | 5173     |

The frontend talks to the backend over HTTP using **axios** (`src/lib/api.ts`).
Authentication uses **Laravel Sanctum** in token mode: the SPA hits
`POST /api/login`, receives a bearer token, stores it in `localStorage`, and
sends it as `Authorization: Bearer <token>` on every subsequent request.

---

## Prerequisites

- PHP **8.2+**, Composer
- Node.js **20+**, npm
- SQLite extension for PHP (default driver — no separate server needed)

---

## Backend — Laravel 11 (`backend/`)

First-time setup:

```bash
cd backend
composer install
cp .env.example .env          # if .env is missing
php artisan key:generate
touch database/database.sqlite
php artisan migrate
```

Run the dev server on port **8000**:

```bash
php artisan serve --port=8000
```

The API is served from `http://localhost:8000/api`.

> **Port already in use?** If something else (e.g. XAMPP Apache) is bound to
> 8000, either stop it or run Laravel on a different port (`--port=8001`) and
> update `VITE_API_URL` in `frontend/.env.local` to match.

### Useful endpoints

| Method | Path             | Auth     | Purpose                              |
| ------ | ---------------- | -------- | ------------------------------------ |
| GET    | `/api/health`    | public   | Liveness probe used by the frontend  |
| POST   | `/api/register`  | public   | Create user, returns a Sanctum token |
| POST   | `/api/login`     | public   | Issues a Sanctum token               |
| POST   | `/api/logout`    | bearer   | Revokes the current token            |
| GET    | `/api/user`      | bearer   | Returns the authenticated user       |

### CORS

`config/cors.php` allows `http://localhost:5173` (the Vite dev server) for all
`/api/*` and `/sanctum/*` routes. Add other origins to `allowed_origins` if you
serve the SPA from a different host.

---

## Frontend — React + Vite (`frontend/`)

First-time setup:

```bash
cd frontend
npm install
```

Run the dev server on port **5173**:

```bash
npm run dev
```

Open <http://localhost:5173>. The home page pings `/api/health` via TanStack
Query to confirm the backend is reachable, and renders a sample Recharts chart.

### Configuration

`frontend/.env.local`:

```
VITE_API_URL=http://localhost:8000/api
```

Change this if the backend runs on a different host/port — `src/lib/api.ts`
picks it up at build time.

### Libraries in use

- **axios** — HTTP client, configured in `src/lib/api.ts`
- **react-router-dom** — client-side routing (`src/App.tsx`)
- **@tanstack/react-query** — server state / caching (`src/main.tsx`)
- **tailwindcss** — utility CSS (`tailwind.config.js`, `src/index.css`)
- **recharts** — charts (`src/routes/Home.tsx`)

---

## Running both apps together

In two terminals:

```bash
# Terminal 1
cd backend && php artisan serve --port=8000

# Terminal 2
cd frontend && npm run dev
```

Then visit <http://localhost:5173>.

### Auth flow end-to-end

1. SPA submits credentials to `POST http://localhost:8000/api/login`.
2. Laravel validates, calls `$user->createToken('spa')`, returns `{ token }`.
3. SPA stores the token (`setAuthToken` in `src/lib/api.ts`) — subsequent
   axios calls send `Authorization: Bearer <token>`.
4. Protected routes use the `auth:sanctum` middleware on the backend.
5. `POST /api/logout` calls `$request->user()->currentAccessToken()->delete()`
   and the SPA clears the stored token.
# forex
