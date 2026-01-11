# SUMEE Dev Workspace

This repo contains:

- `api/` PHP REST API (Slim)
- `ws/` Bun + ws realtime gateway
- `web/` React testing UI
- `db/` MySQL schema
- `scripts/` GC worker for attachments

## Quick start (local)

1) Start MySQL/Redis/MinIO:

```bash
docker compose up -d
```

2) Install dependencies:

```bash
cd api && composer install
cd ../ws && bun install
cd ../web && npm install
```

3) Copy env files:

```bash
cp api/.env.example api/.env
cp ws/.env.example ws/.env
cp web/.env.example web/.env
```

4) Run all services with pm2:

```bash
pm2 start pm2.config.cjs
pm2 logs
```

- API: http://localhost:8080
- WS: ws://localhost:8787
- Web UI: http://localhost:5173

## Notes

- Email verification is skipped by default: `SKIP_EMAIL_VERIFICATION=true`.
- Turnstile is still required on register/login. Use `dev-pass` for local bypass.
- Attachments are stored locally in `api/storage`.
- Run GC for expired attachments:

```bash
php scripts/gc_attachments.php
```
