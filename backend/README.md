# Al-Falaah School Portal API

Dependency-free PHP 8.4 + MySQL/MariaDB JSON API for the Al-Falaah school portal.
It powers the existing student, parent, teacher and administrator experiences in
`frontend/`.

Everything in this directory is private except `public/`, which is the only
web-accessible entry point.

## Requirements

- PHP 8.4 with `pdo_mysql`, `mbstring`, `json`, `curl` (tests only)
- MySQL 8 or MariaDB 10.4+
- Apache with `mod_rewrite` (XAMPP defaults are fine)
- A MySQL account that can create databases/users for installation, plus the
  least-privilege runtime account created by the installer

## Quick start (XAMPP)

```bash
cd C:/xampp/htdocs/alabraarsch

# 1. Create the local environment file and review it
cp backend/.env.example backend/.env
# Edit backend/.env: set DB credentials, APP_KEY, APP_URL and DEMO_PASSWORD.

# 2. Create the database, least-privilege app account, schema and demo data
php backend/scripts/install.php --fresh

# 3. Check the API
curl http://localhost/alabraarsch/backend/public/api/health

# 4. Run the end-to-end smoke test
php backend/tests/api_smoke.php
```

The API base URL is:

```text
http://localhost/alabraarsch/backend/public/api
```

`http://localhost/alabraarsch/backend/api/...` also works through the private
`.htaccess` front-controller rewrite. Keep `public/` as the document root in a
production virtual host.

`--fresh` drops and recreates every portal table. Never run it against data you
want to keep. Use `--no-seed` to apply the schema without demo data.

Demo accounts are seeded only when `DEMO_PASSWORD` is present in `.env`:

| Role | Email |
| --- | --- |
| Student | `aisha.abdullahi@student.alfalaah.edu.ng` |
| Parent | `ibrahim.abdullahi@example.com` |
| Teacher | `s.bello@alfalaah.edu.ng` |
| Administrator | `h.sanni@alfalaah.edu.ng` |

Use the value of `DEMO_PASSWORD` from your local `.env` as the password. Change
these passwords before exposing the portal outside a development machine.

## Project layout

```text
backend/
  public/index.php        Front controller, CORS, security headers, route table
  public/.htaccess        Rewrite everything through index.php
  src/Core/               Env, database, request/response, router, auth, sessions, CSRF
  src/Services/           Academic, student, fee, result, attendance and dashboard logic
  src/Controllers/        HTTP endpoints grouped by workflow
  database/schema.sql     Full relational schema
  database/seed.php       Demo school data
  scripts/install.php     Schema installer and database account provisioning
  tests/api_smoke.php     Read-only end-to-end API smoke test
  storage/                Logs and PHP sessions (not public)
```

## Authentication and security

- Authentication uses server-side PHP sessions with an `HttpOnly`, `SameSite=Lax`
  cookie. Set `SESSION_SECURE=true` and serve over HTTPS in production.
- Every request that changes data requires the CSRF token returned by
  `GET /api/session`, `POST /api/auth/login` or `GET /api/auth/me`. Send it in the
  `X-CSRF-Token` header.
- Login requires five failures per email or twenty per IP in a rolling
  fifteen-minute window. Requests over the limit receive `429` and a
  `Retry-After` header.
- Passwords are stored with PHP's `password_hash()` (bcrypt/Argon as configured
  by PHP). Password changes invalidate other sessions for that account.
- Sessions expire after `SESSION_LIFETIME` minutes (default 120).
- Role-based access control is enforced server-side, not by the frontend:
  - Students can read only their own records.
  - Parents can read only linked children.
  - Teachers can read only students in classes they teach and only published
    results. Teachers cannot read fee records.
  - Administrators can manage students, announcements, results, admissions,
    finance and timetables.
- All write actions are recorded in `audit_logs`.
- JSON responses carry `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, `Content-Security-Policy`, `Cache-Control: no-store` and an
  `X-Request-Id` for log correlation.
- Keep `.env` out of version control. `.gitignore` already excludes it.

### CSRF flow for the frontend

```js
// 1. Bootstrap the session and read the token
const session = await fetch(`${API}/session`, { credentials: 'include' }).then(r => r.json());
let csrf = session.data.csrf_token;

// 2. Sign in
const login = await fetch(`${API}/auth/login`, {
  method: 'POST',
  credentials: 'include',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrf,
  },
  body: JSON.stringify({ email, password }),
}).then(r => r.json());

csrf = login.data.csrf_token; // the token rotates on authentication

// 3. Every later write uses the current token
await fetch(`${API}/notifications/read-all`, {
  method: 'POST',
  credentials: 'include',
  headers: { 'X-CSRF-Token': csrf },
});
```

`GET /api/session` also returns the signed-in user, or `null` when signed out.
The frontend must treat `401 unauthenticated` as "return to
`frontend/portal.html`". The API never issues an HTML redirect.

### Frontend connection

`frontend/js/api.js` wraps these calls and defaults the API base URL to
`../backend/public/api` relative to the page. To host the API elsewhere, set
`window.ALFALAAH_API_BASE` before loading the script. The client bootstraps
`/session`, keeps the CSRF token in memory, retries one write after
`403 csrf_failed`, and redirects to `frontend/portal.html` when a session
expires. The login forms use `data-login-form` so the public-site script does
not intercept them.

### CORS

`CORS_ALLOWED_ORIGINS` is a comma-separated list of exact origins. The local
example includes the XAMPP host and common development ports:

```dotenv
CORS_ALLOWED_ORIGINS=http://localhost,http://127.0.0.1,http://localhost:5500,http://127.0.0.1:5500
```

Add only the origins you actually use. Credentials are allowed for configured
origins. The API does not use wildcard CORS.

## Response format

Success:

```json
{
  "data": {},
  "meta": {}
}
```

`meta` is present for paginated responses:

```json
{
  "meta": {
    "pagination": {
      "page": 1,
      "per_page": 20,
      "total": 46,
      "pages": 3
    }
  }
}
```

Error:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Please check the highlighted fields.",
    "fields": {
      "email": "Enter a valid email address."
    }
  }
}
```

Common status codes: `200`, `201`, `204`, `401`, `403`, `404`, `409`, `422`,
`429` and `500`. CSRF failures use `403` with code `csrf_failed` so Apache maps
the response correctly on XAMPP.

## Endpoints

All paths are relative to `/api`. `auth` means a signed-in session is required.
`CSRF` means the write requires `X-CSRF-Token`.

### Public and authentication

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| GET | `/health` | public | Database and application health |
| GET | `/session` | public | Current user, CSRF token, server time |
| POST | `/auth/login` | public + CSRF | Sign in and rotate the session |
| POST | `/auth/logout` | auth + CSRF | End the session |
| GET | `/auth/me` | auth | Current user and CSRF token |
| POST | `/auth/password` | auth + CSRF | Change the current password |

### Shared portal data

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| GET | `/dashboard` | auth | Role-specific dashboard payload |
| GET | `/terms` | auth | Academic terms available to the account |
| GET | `/classes` | auth | Classes visible to the account |
| GET | `/subjects` | auth | Subject catalogue |
| GET | `/announcements` | auth | Published announcements for the role |
| GET | `/notifications` | auth | Notifications and unread count |
| PATCH | `/notifications/{id}/read` | auth + CSRF | Mark one notification read |
| POST | `/notifications/read-all` | auth + CSRF | Mark all notifications read |
| GET | `/parent/children` | parent | Linked children and child selector data |

### Students and parents

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| GET | `/students` | teacher, admin | Paginated students with `class_id`, `search`, `status`, `page`, `per_page` |
| GET | `/students/{id}` | permitted roles | Student profile |
| GET | `/students/{id}/results` | permitted roles | Published results for students/parents; admin may request a term |
| GET | `/students/{id}/attendance` | permitted roles | Attendance summary and recent records |
| GET | `/students/{id}/fees` | student, parent, admin | Invoices, payments and balance (teachers blocked) |
| GET | `/students/{id}/timetable` | permitted roles | Class timetable for a term |
| GET | `/students/{id}/assignments` | permitted roles | Assignments for the student's class |
| GET | `/students/{id}/report` | permitted roles | Combined result, attendance, fee and assignment report |

Students and parents must supply the student UUID they are allowed to see.
Accessing another student's record returns `404` rather than revealing that it
exists.

### Teacher workflows

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| GET | `/teacher/classes` | teacher | Assigned classes with averages |
| GET | `/teacher/schedule` | teacher | Lessons and pending registers for a date |
| GET | `/teacher/attendance?class_id=...` | teacher | Daily register |
| POST | `/teacher/attendance` | teacher + CSRF | Save a complete daily register |
| GET | `/teacher/result-sheets` | teacher | Sheets for a term |
| POST | `/teacher/result-sheets` | teacher + CSRF | Create or reopen a sheet |
| GET | `/teacher/result-sheets/{id}` | teacher, admin | Sheet detail with students and scores |
| PATCH | `/teacher/result-sheets/{id}` | teacher + CSRF | Save draft scores |
| POST | `/teacher/result-sheets/{id}/submit` | teacher + CSRF | Submit a complete sheet for approval |
| GET | `/teacher/assignments?class_id=...` | teacher | Assignments for a class |
| POST | `/teacher/assignments` | teacher + CSRF | Create an assignment |
| GET | `/teacher/students?class_id=...` | teacher | Students in a class with attendance and averages |

### Administrator workflows

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| GET | `/admin/overview` | admin | School-wide dashboard |
| GET | `/admin/students` | admin | Paginated student management list |
| POST | `/admin/students` | admin + CSRF | Create a student record |
| PATCH | `/admin/students/{id}` | admin + CSRF | Update a student record |
| GET | `/admin/announcements` | admin | Draft, published and archived announcements |
| POST | `/admin/announcements` | admin + CSRF | Create an announcement |
| PATCH | `/admin/announcements/{id}` | admin + CSRF | Update an announcement |
| DELETE | `/admin/announcements/{id}` | admin + CSRF | Archive an announcement |
| GET | `/admin/result-sheets` | admin | Sheets for approval |
| POST | `/admin/result-sheets/{id}/approve` | admin + CSRF | Approve a submitted sheet |
| POST | `/admin/result-sheets/{id}/return` | admin + CSRF | Return a sheet with a review note |
| POST | `/admin/terms/{id}/publish` | admin + CSRF | Publish all approved sheets for a term |
| GET | `/admin/admissions` | admin | Paginated admission enquiries |
| PATCH | `/admin/admissions/{id}` | admin + CSRF | Update admission status or notes |
| GET | `/admin/finance` | admin | Term fee summary, invoices and recent payments |
| POST | `/admin/payments` | admin + CSRF | Record a payment against an invoice |
| POST | `/admin/invoices` | admin + CSRF | Create a fee invoice |
| GET | `/admin/timetable?class_id=...` | admin | Timetable for a class and term |
| POST | `/admin/timetable` | admin + CSRF | Add a timetable slot |
| DELETE | `/admin/timetable/{id}` | admin + CSRF | Remove a timetable slot |
| GET | `/admin/teachers` | admin | Active teacher reference list |

Use query parameters `term_id` and `class_id` as UUIDs. Omit `term_id` to use the
current term. `page` is 1-based and `per_page` is bounded by the endpoint.

## Results workflow

1. A teacher creates or reopens a sheet for a class, subject and term.
2. The teacher saves all students' CA and exam scores as a draft.
3. Submitting is blocked until every active student has a score.
4. An administrator approves the sheet or returns it with a note.
5. Publishing a term requires every sheet in that term to be approved or
   returned; published results become visible to students and parents.

Teachers can only read published results for students through the shared student
endpoints. Unpublished sheets are visible only through the teacher/admin result
workflow.

## Testing and verification

```bash
# Lint every PHP file
find backend -name '*.php' -not -path '*/vendor/*' -print0 | xargs -0 -n1 php -l

# Read-only end-to-end checks (requires demo data and a running API)
php backend/tests/api_smoke.php
```

The smoke test signs in as each role, checks dashboards and role restrictions,
verifies student, parent, teacher and admin endpoints, and confirms that the
session ends after logout. Override the target with:

```bash
API_SMOKE_BASE_URL=http://localhost/alabraarsch/backend/api php backend/tests/api_smoke.php
```

## Logs and troubleshooting

- Application logs: `backend/storage/logs/app.log`
- PHP session files: `backend/storage/sessions/`
- Health endpoint: `GET /api/health`
- `401`: no session or expired session. Send the browser back to
  `frontend/portal.html`.
- `403 csrf_failed`: fetch `/api/session` again, then repeat the write with the
  new token.
- `403 forbidden`: the signed-in role is not allowed to perform the action.
- `404`: the record does not exist or is outside the account's permission scope.
- `429`: login throttling is active; wait for `Retry-After` seconds.
- `500`: check `app.log`; set `APP_DEBUG=true` only on a local machine.

If Apache cannot write sessions or logs, verify that `backend/storage/` exists
and is writable by the web-server account.
