# InvenPilot

InvenPilot is a self-hosted inventory management application for businesses that buy, stock, and sell products. Use it to track current stock, receive orders in stages, record sales and returns, and review business reports.

Created by [princewill86](https://github.com/princewill86). [View the project on GitHub](https://github.com/princewill86/invenpilot).

## Features

- Business accounts, roles, shareable user invitations, and profile settings.
- Searchable products, categories, suppliers, and private product images.
- Current inventory, an auditable stock ledger, low-stock filters, and restocking shortcuts.
- Purchase orders with partial receipts, supplier returns, and cancellation of outstanding quantities.
- Sales with stock validation, returns, cancellations, and historical line-item snapshots.
- Dashboard charts, inventory valuation, sales and purchase analytics, CSV exports, and printable report views.

## Screenshots

[Landing page](docs/screenshots/landing.png) · [Dashboard with test transactions](docs/screenshots/dashboard.png) · [Platform settings](docs/screenshots/platform-settings.png) · [Platform activity](docs/screenshots/platform-activity.png)

## Run with Docker

Requirements are Docker and Docker Compose. From the repository root, copy `.env.example` to `.env`. Set a strong `MYSQL_PASSWORD` and a Laravel `APP_KEY`. Generate both with OpenSSL, then copy the generated values into the corresponding variables.

```sh
openssl rand -hex 24
printf 'base64:%s\n' "$(openssl rand -base64 32)"
docker compose --profile app up -d --wait --build
docker compose --profile app exec -T app php artisan migrate --force
```

Open <http://localhost:8080> and register the business owner. The application starts with an empty catalog. MySQL and uploaded images persist in Docker volumes. Use `docker compose --profile app down` to stop the stack while retaining those volumes.

## Platform administration

Platform administrators sign in at `/admin` and open **Platform settings**. This role is separate from business owners and business administrators. Create a platform administrator with a password prompt.

```sh
docker compose --profile app exec app php artisan invenpilot:admin operator@example.com
```

The **Users** and **Businesses** directories support search, access suspension, reactivation, and business membership management. Platform administrators can transfer ownership to an active member and review the reason and actor for each change in **Activity log**.

Settings cover the platform name, logo and favicon, landing copy, SEO metadata and indexing, support email, registration, default and allowed currencies, password policy, recovery, invitation expiry, and legal policies. Branding accepts PNG, JPEG, or WebP up to 1 MB. Registration records when the user accepted the current legal documents. The public site reads saved settings from the installation database.

Email can use server environment settings, PHP mail through the configured sendmail command, or SMTP. PHP mail requires a working local mail transfer agent. SMTP passwords are encrypted using the application key. The Docker environment defaults to an in-memory mailer until an operator configures delivery. Configure a real mailer before relying on password recovery.

For local testing, generate a platform administrator and three business roles with sample transactions. The command works on loopback installations and prints unique passwords when it creates the accounts.

```sh
docker compose --profile app exec app php artisan invenpilot:demo
```

## Local development

Install PHP 8.4 with BCMath and PDO MySQL, Composer 2, and Node.js 22.12 or newer in the Node 22 release line.

1. Copy the root `.env.example` to `.env`, set `MYSQL_PASSWORD`, and run `docker compose up -d --wait mysql`.
2. In `backend/`, run `composer install` and copy `.env.example` to `.env`. Set `DB_PASSWORD` to the root `MYSQL_PASSWORD`. If you changed `MYSQL_PORT`, set `DB_PORT` to match.
3. In `backend/`, run `php artisan key:generate`, `php artisan migrate`, and `php artisan serve`.
4. In `frontend/`, run `npm ci` and `npm run dev`. Open the Vite address. Vite proxies the REST API and Sanctum requests to port 8000.

SQLite is available for development and feature tests. Set `DB_CONNECTION=sqlite`, remove the remaining `DB_*` values, and create `backend/database/database.sqlite` before migrating. Use MySQL to verify row locking and the production decimal implementation.

## Architecture

| Path | Responsibility |
| --- | --- |
| `frontend/src/` | React, TypeScript, Vite, Tailwind CSS, Lucide, and Chart.js. Screens use a shared API client and preserve the selected business and theme in the browser. |
| `backend/app/Http/` | Laravel REST controllers, input validation, business membership checks, and role enforcement. |
| `backend/app/Services/` | Stock ledger, purchase and sales workflows, reports, invitation acceptance, and activity logging. |
| `backend/database/migrations/` | MySQL schema and constraints for memberships, catalog records, movements, order operations, and audit history. |
| `backend/tests/` and `frontend/tests/` | PHPUnit feature tests and Playwright end-to-end tests. |
| `docker/` and `.github/` | Container settings, CI, and contribution templates. |

The browser uses a same-origin session with Sanctum and CSRF protection. API access to business data resolves the caller’s membership on every request. Business-scoped records and cross-record links are constrained in the database.

Inventory quantities use three decimal places. Prices and costs use four decimal places. Stock changes lock the relevant rows and persist the balance and ledger movement together. Retries reuse operation identifiers. Sales capture the weighted-average cost present at sale time, and reversals preserve the original transaction.

See the [API reference](docs/API.md) for routes, permissions, and payloads. See the [development report](docs/DEVELOPMENT.md) for the schema and accounting rules.

## Testing

- Backend feature tests from `backend/` with `php artisan test`. The default test database is in-memory SQLite.
- For MySQL tests, provide `DB_CONNECTION=mysql` and credentials for a dedicated, disposable database. The suite uses `RefreshDatabase` and can rebuild its schema.
- Type-check and build from `frontend/` with `npm run build`.
- Run `npx playwright install chromium` and `npm run test:browser` from `frontend/`. The workflow creates its own sample business and writes test transactions. Point `BASE_URL` to a disposable stack. It defaults to `http://localhost:8080`.

## Deployment

Build the Docker image from the repository root. Set `APP_KEY` once and keep it stable across updates. Set `APP_URL` to the public HTTPS origin and `SANCTUM_STATEFUL_DOMAINS` to its host and port. Set `SESSION_SECURE_COOKIE=true` for HTTPS and list the reverse proxy’s addresses in `TRUSTED_PROXIES` if TLS terminates there.

Place the app behind an HTTPS reverse proxy. The Compose bindings publish services to loopback. Firewall MySQL and back up its database and the application storage volume. Run `php artisan migrate --force` after taking a backup and before routing traffic to a new release. Extend the included privacy and terms pages with the deployment operator’s details and policies.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md).

InvenPilot is available under the [MIT License](LICENSE). The bundled Plus Jakarta Sans font is licensed under the [SIL Open Font License](frontend/public/fonts/plus-jakarta-sans/OFL.txt).
