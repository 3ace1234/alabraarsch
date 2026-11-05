# Deployment

The repository has two separate parts:

- `frontend/` — static HTML, CSS and JavaScript
- `backend/` — PHP 8.4 API and MySQL schema

Vercel and GitHub Pages can host only the static frontend. They cannot execute
the PHP API or provide MySQL.

## GitHub Pages

The workflow in `.github/workflows/deploy-pages.yml` publishes only the
`frontend/` directory, so the site is served at the repository root instead of
under `/frontend/`.

1. Push the workflow to `main`.
2. Open the repository **Settings → Pages**.
3. Set **Build and deployment → Source** to **GitHub Actions**.
4. Run the workflow, or push any change under `frontend/`.

The site will then be available at:

```text
https://<owner>.github.io/<repository>/
```

For this repository:

```text
https://3ace1234.github.io/alabraarsch/
```

Do not publish the repository root. The backend source and database schema must
not be exposed as static files.

## Vercel

The Vercel project must also treat `frontend/` as the deployment root.

1. Open the project in Vercel.
2. Go to **Settings → General → Root Directory**.
3. Set it to `frontend`.
4. Redeploy the latest commit.

After the redeploy, verify that the backend is no longer public:

```text
https://<project>.vercel.app/backend/public/index.php
```

This must return `404`.

## API

The PHP API must be hosted on a PHP 8.4 + MySQL server. Then configure the
frontend to use that public URL before `js/api.js` loads:

```html
<script>
  window.ALFALAAH_API_BASE = "https://api.example.com/api";
</script>
```

The API must allow the deployed frontend origin with credentials and secure
cross-site cookies when the frontend and API are on different domains:

```dotenv
CORS_ALLOWED_ORIGINS=https://3ace1234.github.io,https://<project>.vercel.app
SESSION_SECURE=true
SESSION_SAMESITE=None
```

Keep `backend/.env` out of Git and never serve it from a static host.
