# Security

## Secrets stay out of this repository
- `outreach-config.php` holds the database login and `app_key`. It sits **outside** `public_html` on the server and is listed in `.gitignore`. Never commit it, and never change `app_key` after installing: it encrypts every stored key and inbox password.
- API keys (Claude, OpenAI, Gemini, Google Places, Hunter), inbox app passwords and chat webhook URLs are entered in the app and stored encrypted in the database. They are never in code.
- The CI check (`.github/workflows/php-lint.yml`) fails if a config file or an API-key-shaped string is committed.
- **If a secret is ever committed:** rotate it at the provider straight away. Deleting the commit isn't enough, because Git history and forks keep it.

## Keep the repository private
This is commercial, proprietary code (see `LICENSE`). Give access only to people who work on it, and remove access when they leave (GitHub → Settings → Collaborators).

## Reporting a vulnerability
Email the platform owner privately. Don't open a public issue. Include the steps to reproduce, and the error reference code if the app showed one.

## What the app already does
Two separate sign-ins (clients at `/`, platform admins at `/admin/` with required two-step verification); CSRF tokens on every form; bcrypt passwords; sign-in throttling; session timeouts; encrypted secrets; workspace isolation on every query; SSRF-safe website fetching; prompt-injection guards; strict security headers; web access to `lib/`, `vendor/`, `storage/`, `docs/`, `.git/` and the docs files blocked by `.htaccess`.
