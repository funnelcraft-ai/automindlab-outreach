# Automindlab Outreach v2.4.2: codebase map

The app is plain PHP 8.1+ and MySQL/MariaDB with no build step and no Composer. It runs on Hostinger shared hosting with one cron job. The repository has 89 files, with about 7,500 lines of the app's own code (PHP, CSS, JS, SQL and the `dev/` kit). That line count leaves out the bundled PHPMailer library (`vendor/`) and the QR-code script (`assets/qrcode.js`).

```
public_html/  (the app folder, e.g. domains/outreach.automindlab.io/public_html)
├── Client app (workspace dashboard, client sign-in, cookie amlo_sess)
├── admin/          Admin console (own sign-in, cookie amlo_admin, platform admins only, 2FA required)
├── lib/            shared code: never served (blocked by .htaccess)
├── assets/         CSS, JS, fonts, logo
├── vendor/         PHPMailer 6.9.3: never served
└── storage/        locks, cache, fallback logs: never served
outreach-config.php  (one level ABOVE public_html; secrets; not in the zip)
```

## Two areas, two sign-ins

| Area | Address | Sign-in | Who |
|---|---|---|---|
| Client app | `/` | `login.php` | Workspace owners, admins, members, viewers |
| Admin console | `/admin/` | `admin/login.php` | Platform admins only, with two-step verification |

The area is chosen by `APP_AREA`. Files in `admin/` set it to `'admin'` before loading `lib/bootstrap.php`. Session name, cookie path, timeouts, allowed accounts and the layout all follow from that (`lib/auth.php`, `lib/layout.php`). The console opens a client workspace through a one-time link (`admin/open.php` → `enter.php`), so the two sessions never share a cookie.

## Client app: pages (site root)

| File | What it does |
|---|---|
| `login.php` | Client sign-in, with a two-step code if the account has 2FA. Refuses platform-admin accounts. Also served as `admin/login.php` in the console area. |
| `logout.php` | Sign-out. An admin leaving a client workspace goes back to the console. |
| `index.php` | Workspace dashboard: KPIs, funnel, 14-day chart, campaigns, mailboxes, allowance, meetings, activity, and the "Run a step now" buttons. |
| `leads.php` | Lead list with filters, search, bulk approve/re-draft/retry/disqualify/delete, and streaming CSV export. |
| `lead.php` | One lead: research, fit score, editable 4-email sequence, replies, history. |
| `replies.php` | Inbox of classified replies, with AI-suggested answers and sending from the app. |
| `campaigns.php` | Campaigns: vertical presets, locations, keywords, ideal customer profile, offer, proof, filters, approval mode. |
| `mailboxes.php` | Sending inboxes: provider presets (Zoho, Google, Hostinger, custom), connection test, warm-up ramp, limits. |
| `integrations.php` | Workspace Connections: sending mode (Live/Test/Dry run/Paused), go-live checklist, allowance, cal.id webhook, inboxes. |
| `settings.php` | Business and compliance details (legal name, postal address, opt-out, Canada mode) and the sending schedule. |
| `team.php` | Members and roles, invite links, role changes, removal, last-owner protection. |
| `invite.php` | Accepting an invitation: create an account, or sign in and join. |
| `linkedin.php` | LinkedIn tasks: today's connection requests and messages with copy-ready text, the daily limit, waiting-for-acceptance list, and buttons to record each outcome. |
| `activity.php` | Activity log and the do-not-contact list. |
| `account.php` | Password, two-step verification and recovery codes, sign-in history. Also served as `admin/account.php`. |
| `switch.php` | Switch between workspaces you belong to. |
| `enter.php` | Client end of the console's one-time "Open workspace" link. |
| `unsubscribe.php` | Public opt-out page, including one-click unsubscribe (RFC 8058). |
| `webhook-cal.php` | cal.id booking webhook, one URL per workspace (`?w=<id>&key=<key>`), with an optional HMAC signature. |
| `cron.php` | Pipeline runner for every workspace (run it every 5 minutes); also sends alerts and does daily housekeeping. |
| `healthz.php` | Uptime-monitor endpoint: 200 when healthy, 503 otherwise. Details only with the key. |
| `install.php` | One-time installer. Delete it after setup. |
| `admin.php`, `workspaces.php`, `platform.php`, `health.php`, `admins.php` | Four-line redirect stubs for old bookmarks. They point to `/admin/…`. |

## Admin console: pages (`admin/`)

| File | What it does |
|---|---|
| `admin/index.php` | Master dashboard covering every workspace: KPIs, needs-attention list, sortable workspace comparison, platform usage, 30-day chart, recent changes. |
| `admin/workspaces.php` | Create workspaces, plans and allowances, suspend or resume, owner invites, notes for manual billing. |
| `admin/platform.php` | Shared AI, Google Places and Hunter keys; AI provider and budget; Places caps; cron command and key. |
| `admin/health.php` | Developer error log (grouped problems, occurrences, stack traces, reference lookup), alert settings, cron runs, page speed, server facts, uptime URL. |
| `admin/admins.php` | Platform-admin accounts: add or remove access, plus everyone with an account. |
| `admin/open.php` | Creates the one-time "Open workspace" link. |
| `admin/login.php`, `admin/logout.php`, `admin/account.php` | Console versions of the shared pages (each sets `APP_AREA` and includes the root file). |

## Shared code (`lib/`)

| File | What it does |
|---|---|
| `bootstrap.php` | Config loading, DB helpers (`q`/`row`/`all`/`val`/`insert`/`update`) with slow-query detection, settings (platform and per workspace, secrets encrypted), file cache, campaign stats, HTTP client, security headers, migrations v2–v7. |
| `auth.php` | The two areas, sessions and timeouts, sign-in with brute-force throttling, two-step verification, hand-off links, CSRF, flash messages, password rules. |
| `tenancy.php` | Workspaces: current-workspace context, roles (viewer → owner), permission checks, allowances, invitations. |
| `errors.php` | Developer error log: PHP error and exception handlers, fatal-error capture, grouping, request IDs, friendly error page, alerts (email, Slack, Discord, Google Chat, Telegram, webhooks), cron heartbeat, page timing, daily housekeeping and summary. |
| `layout.php` | Page shell for both areas: menus, top bar, banners, sending-mode chip, small view helpers. |
| `connections.php` | Connection definitions, tests and form widgets shared by workspace Connections and Platform connections. |
| `linkedin.php` | Assisted LinkedIn: finds a published LinkedIn link, stores AI copy, creates tasks after the first email (cron job `linkedin`), records outcomes, applies the daily limit, closes stale requests, and shares stop rules with email. Never contacts LinkedIn. |
| `pipeline.php` | Jobs: find (Places) → enrich (website research) → draft (AI) → send (warm-up, schedule, compliance) → inbox (replies, bounces). Also the fair multi-workspace runner with locking. |
| `leadfinder.php` | Google Places API (New): Text Search and Place Details. Stores only the place ID. |
| `enrich.php` | Fetches business websites safely (SSRF protection, size limits), extracts business details and published emails. |
| `ai.php` | Claude, OpenAI and Gemini clients returning JSON, plus cost tracking and budgets. |
| `presets.php` | Campaign vertical presets and AI prompts (with prompt-injection guards). |
| `mailer.php` | PHPMailer setup, email composition, footer, unsubscribe headers, booking link. |
| `imap.php` | Small IMAP client (no php-imap needed), MIME parsing, bounce and auto-reply detection. |
| `providers.php` | Mail provider presets and plain-language connection error hints. |
| `totp.php` | Two-step verification codes (RFC 6238) and recovery codes. |

## Database (`schema.sql` for fresh installs; `lib/bootstrap.php` migrates older ones)

| Group | Tables |
|---|---|
| Platform | `settings`, `users`, `login_attempts`, `login_handoffs`, `tenants`, `memberships`, `invitations`, `tenant_settings` |
| Per workspace (every row has `tenant_id`) | `campaigns`, `search_queries`, `leads`, `emails`, `replies`, `bookings`, `mailboxes`, `suppression`, `events`, `api_usage`, `li_tasks` |
| Monitoring | `error_groups`, `error_samples`, `cron_runs`, `perf_daily` |

Schema version 8. Upgrades run automatically on the first page load after new files are uploaded.

## Local development kit (`dev/`, blocked from the web by `.htaccess`)

- `dev/config.local.php.example`: local `config.php` with `local_testing` on and `admin_require_2fa` off.
- `dev/seed_demo.php`: CLI only, and refuses to run unless local test mode is on. Sets mock AI and Places, a Test-mode inbox, a demo HVAC campaign with LinkedIn steps, and a client owner account.
- `dev/fixtures/sites.php`: six fake business websites (emails, a LinkedIn link, "no soliciting", a bounce, hidden prompt injection).
- `dev/fixtures/places.php`: a mock Google Places search and details endpoint.
- `dev/smtp_sink.py`: an SMTP catcher that saves outgoing mail to `dev/outbox/`.
- `dev/dovecot.conf.example`: an optional local IMAP server for testing replies.

`local_testing` (see `local_testing()` in `lib/bootstrap.php`) only takes effect when `base_url` is a local address.

## Assets

`assets/app.css` (design system, responsive layouts, console styles), `assets/app.js` (menu, copy buttons, responsive table cards, forms), `assets/qrcode.js` (2FA QR codes), and `assets/google-sans-flex.woff2` (self-hosted font, OFL licence included), plus the logo, mark and favicon.

## Documentation

`README.md` is the setup and operations guide, `CHANGELOG.md` lists every version (today's changes are at the top), and this file is the code map. `docs/HANDBOOK.md` is the developer handbook (start here if you are changing the code). `docs/GITHUB.md` explains the repository and deploying from GitHub; `SECURITY.md` covers secrets and how to report a vulnerability.

Repository files: `.gitignore` (keeps config and runtime files out), `.gitattributes` and `.editorconfig` (LF line endings, 4-space indent), `LICENSE` (proprietary, with the third-party licences), and `.github/workflows/php-lint.yml` (syntax and secret check on every push).

## Configuration (`outreach-config.php`, from `config.sample.php`)

| Key | Purpose |
|---|---|
| `db_host`, `db_name`, `db_user`, `db_pass` | Database connection |
| `base_url` | Public URL; used for links, and for the console cookie path |
| `app_key` | 32-byte key that encrypts stored API keys and inbox passwords. **Never change it after install.** |
| `app_timezone` | Display timezone |
| `alert_webhook` | Optional chat webhook, used only when the database is down |
| `admin_require_2fa` | Console requires 2FA (default `true`) |
| `cron_budget` | Seconds of work per cron run (default 50) |
| `debug` | `false` in production |
