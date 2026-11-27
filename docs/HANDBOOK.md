# Developer handbook: Automindlab AI Outreach v2.4.2

This handbook is for developers working on the code. It covers how the system fits together, how to run it on your own machine, the rules every change must follow, and how a change gets to production.

Related documents: [README.md](../README.md) (operating the live system), [CODEBASE.md](../CODEBASE.md) (what every file does), [CHANGELOG.md](../CHANGELOG.md), [GITHUB.md](GITHUB.md) (repository and deploys), [SECURITY.md](../SECURITY.md).

---

## 1. The system in one page

A multi-workspace cold-outreach platform for local businesses in the US and Canada. An agency (the platform) runs one installation; each client gets a **workspace**.

```
                         cron.php every 5 min  ──►  run_jobs(): one time-sliced pass per active workspace
                                                         │
Google Places ─► find ─► enrich (business's own site) ─► draft (AI) ─► review/approve ─► send (SMTP) ─► inbox (IMAP)
 (place id only)          email, signals, LinkedIn link    fit score,       person         warm-up,       replies, bounces,
                                                          emails + LI copy                 time windows   AI classification
                                                                                │
                                                       linkedin ─► LinkedIn tasks (a person sends by hand)
                                                                                │
                         cal.id webhook ─► booking ─► every stop rule ends BOTH channels (email + LinkedIn)
```

| Fact | Value |
|---|---|
| Language and runtime | PHP 8.1+ (production: 8.3 on Hostinger), MySQL 8 / MariaDB 10.6+ |
| Framework | None. Plain PHP pages and functions, no Composer, no build step |
| Bundled libraries | PHPMailer 6.9.3 (`vendor/`), qrcode.js (`assets/`) |
| Hosting | Hostinger shared hosting (Apache/LiteSpeed + `.htaccess`), one cron job |
| External APIs | Anthropic, OpenAI or Gemini (switchable), Google Places API (New), Hunter.io (optional), cal.id webhook, any SMTP/IMAP |
| Data isolation | Every workspace row carries `tenant_id`; one shared database |
| Areas | Client app at `/` and Admin console at `/admin/`, with separate sign-ins and session cookies |

## 2. Run it on your machine (about 15 minutes)

You need PHP 8.1+ (with `pdo_mysql`, `curl`, `mbstring`, `openssl`, `sodium`), MySQL or MariaDB, Python 3 (for the mail catcher) and Git. Everything runs offline: AI and Google Places are mocked, and emails land in a local folder.

```bash
git clone git@github.com:<account>/automindlab-outreach.git && cd automindlab-outreach

# 1. Database
mysql -uroot -e "CREATE DATABASE outreach_dev CHARACTER SET utf8mb4;
  CREATE USER 'outreach'@'localhost' IDENTIFIED BY 'outreach';
  GRANT ALL ON outreach_dev.* TO 'outreach'@'localhost';"

# 2. Config (git-ignored). Paste a key from: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
cp dev/config.local.php.example config.php

# 3. Fixture websites: map the test hosts to your machine
echo "127.0.0.1 coolair.test brightsmile.test quickfix.test nosolicit.test bounceplumb.test inject.test" | sudo tee -a /etc/hosts

# 4. Start the helpers (each in its own terminal)
sudo php -S 0.0.0.0:80 dev/fixtures/sites.php       # 6 fake business websites
php -S 127.0.0.1:8090 dev/fixtures/places.php        # mock Google Places
pip install aiosmtpd && python3 dev/smtp_sink.py     # catches outgoing email in dev/outbox/
php -S 127.0.0.1:8080 -t .                           # the app

# 5. Install, then load demo data
#    open http://127.0.0.1:8080/install.php in a browser: creates workspace #1 and your platform-admin account
#    (password: 12+ characters, not containing your email name)
php dev/seed_demo.php                                # mock AI + Places, test inbox, demo campaign, a client login

# 6. Run the pipeline
php cron.php find,enrich,draft 1
php cron.php draft 1                                 # drafting works in small batches; run until "drafted": 0
#    → client app: Review queue → select all → Approve & queue
php cron.php send,linkedin 1                         # one email per inbox per run (built-in pacing): repeat to send the rest
```

**Expected result** (checked on a clean install with v2.4):

| Step | Result |
|---|---|
| find | The Places mock returns 9 businesses and 6 become leads. Big Franchise (only a Facebook page), Too Big Corp (over the review limit) and Closed Co (closed) are filtered out. |
| enrich | 5 emails found. No Solicit is skipped because its site says no solicitation. |
| draft | 5 leads drafted at fit 78 (mock AI). Apex Roofing comes from `inject.test`, a site with hidden prompt-injection text; its draft must not contain the injected link. |
| send | After about 5 runs, 5 `.eml` files are in `dev/outbox/`. Each has a `[TEST → real address]` subject, the postal address, an opt-out link and a `List-Unsubscribe` header. |
| linkedin | Connect tasks for CoolAir and BrightSmile, the two fixtures with a LinkedIn link on their site. They appear under LinkedIn tasks in the client app. |

Bounce Plumbing's address bounces only when the local IMAP server below is running and its bounce message is in the maildir.

**Sign-ins:**
- `http://127.0.0.1:8080/admin/` takes your install.php account. Locally `admin_require_2fa` is off, so no authenticator is needed.
- `http://127.0.0.1:8080/` takes the client account that `seed_demo.php` printed.

**Optional, reading replies:** run the local IMAP server from `dev/dovecot.conf.example` (the seeded inbox uses `dev@outreach.test` / `devpass` on port 143). Write a reply into `/tmp/mail/dev@outreach.test/new/`: a plain `.eml` with `From:` set to a lead's address, `Subject: Re: …`, and `In-Reply-To:` set to the `Message-ID` of that lead's email in `dev/outbox/`. Then run `php cron.php inbox 1`.

- The mock AI classifies the reply, for example as Interested.
- The lead's remaining emails and LinkedIn tasks are cancelled.
- Each inbox is checked at most every 10 minutes. To re-check sooner, run `UPDATE mailboxes SET last_imap_check = NULL`.
- If the run reports `"checked": 1` but no replies, check `/tmp/dovecot.log`. The mail folder must be writable by the user dovecot runs as, so let dovecot create it, or `chown` it.

> **`local_testing`** lets the fetcher reach private addresses, skips MX checks and allows `http://` webhooks. It only takes effect when `base_url` is `localhost`, `127.0.0.1` or a `*.test` / `*.localhost` name. Anywhere else it is ignored and raised as a critical problem on System health. Never set it on a server.

## 3. How a request works

1. Every page starts with `require __DIR__ . '/lib/bootstrap.php';`. Console pages first `define('APP_AREA', 'admin')`. The bootstrap:
   - loads config;
   - installs the error handlers (`lib/errors.php`);
   - opens the database (UTC);
   - sends security headers;
   - runs pending migrations;
   - loads every `lib/*.php`.
2. `require_login($minRole)` (`lib/auth.php`):
   - checks the session for this area (cookie `amlo_sess` or `amlo_admin`, idle and absolute timeouts, `session_version`);
   - resolves the current workspace;
   - calls `set_tenant()`;
   - checks the minimum role.

   Console pages add `require_platform_admin()`.
3. **POST handlers** always start with `verify_csrf();` and a role check (`require_role('member'|'admin')`), do the work in `try/catch`, set `flash()`, and end with `redirect()` (Post/Redirect/Get).
4. **The view** is `page_header($title, $navKey, $subtitle)`, then HTML, then `page_footer()`. Escape every value with `e()`, and use `icon('name')` for Material Symbols.

### The two areas

| | Client app (`/`) | Admin console (`/admin/`) |
|---|---|---|
| Cookie | `amlo_sess`, path `/`, SameSite=Lax | `amlo_admin`, path `/admin/`, SameSite=Strict |
| Timeouts | 2 h idle / 12 h | 30 min idle / 8 h |
| Accounts | Members of workspaces | `users.is_platform_admin = 1`, 2FA required |
| Admin into a client workspace | `admin/open.php` creates a one-time `login_handoffs` row (60 s) → `enter.php` starts a separate app session with `via_admin` | — |

`app_area()`, `admin_href()` and `rel()` build links that work from both areas. `admin/login.php`, `logout.php` and `account.php` are thin wrappers that set the area and include the root file.

## 4. Workspaces (multi-tenancy): rules every change must follow

Getting this wrong leaks one client's data to another. Review every pull request against these rules.

1. **Every workspace table has `tenant_id`.** It is listed in `TENANT_TABLES` (`lib/bootstrap.php`). `insert()` fills `tenant_id = tid()` automatically and refuses if no workspace is set.
2. **Every query on a workspace table filters by `tenant_id = tid()`.** That includes counts, joins (filter the outer table) and `UPDATE`/`DELETE`. No exceptions in page code.
3. **Records opened by ID** go through `owned_row('leads', $id)`, which returns `null` for another workspace's row. Answer with `deny()` or a 404. Never trust IDs from forms: for bulk actions, re-select the IDs with `WHERE tenant_id = ? AND id IN (…)`.
4. **Roles**, from lowest to highest: `viewer` < `member` < `admin` < `owner`. Platform admins pass every check.
   - Hide buttons with `can('member')`.
   - Enforce on the server with `require_role()`.
   - Hiding a button is never enough.
5. **Settings:**
   - `setting()` / `set_setting()` route the keys in `TENANT_SETTING_KEYS` to the current workspace (`tenant_settings`). Everything else is platform-wide (`settings`).
   - Secrets listed in `SECRET_SETTINGS` are encrypted with `app_key`.
6. **Public endpoints with no signed-in user** (`unsubscribe.php`, `webhook-cal.php`, `enter.php`) resolve the workspace from a signed token or key, then call `set_tenant()`.
7. **Cross-workspace reads** (admin overview, System health) live only in `admin/` behind `require_platform_admin()`.
8. **Suppression:** bounces are global (`tenant_id = 0`); opt-outs belong to the workspace. `is_suppressed()` checks both.
9. **Allowances:** check `quota_status()` before spending platform money (Places calls, AI). `ai_json()` already enforces the AI budget and allowance.

## 5. Data model

Schema version **8**. `schema.sql` creates fresh installs; `maybe_migrate()` upgrades older ones.

| Table | Purpose and key columns |
|---|---|
| `tenants`, `memberships`, `invitations`, `tenant_settings` | Workspaces, users' roles in them, invite links (hashed tokens), per-workspace settings |
| `users`, `login_attempts`, `login_handoffs` | Accounts (bcrypt, TOTP secret encrypted, `session_version`, `totp_last_step`), throttling, console→app hand-offs |
| `settings` | Platform settings and encrypted API keys |
| `campaigns` | Search keywords and locations, ICP, offer, proof, filters, approval mode, follow-up delays, LinkedIn switch |
| `search_queries` | One row per keyword × location, with paging state |
| `leads` | The prospect: `place_id` (the only thing kept from Google), website research, email, `status`, fit score, research JSON, LinkedIn fields (`linkedin_url`, `li_state`, `li_note`, `li_msg1`, `li_msg2`) |
| `emails` | Sequence steps: `draft` → `approved` → `scheduled` → `sent` / `failed` / `cancelled` |
| `replies` | Inbound replies, bounces and auto-replies, with AI category; `channel` is `email` or `linkedin` |
| `li_tasks` | LinkedIn to-dos: `connect`, `message` or `followup`; `pending` → `done` / `skipped` / `cancelled` |
| `bookings`, `mailboxes`, `suppression`, `events`, `api_usage` | cal.id bookings, sending inboxes (encrypted passwords, warm-up), do-not-contact list, activity log, API usage and cost per day |
| `error_groups`, `error_samples`, `cron_runs`, `perf_daily` | Developer error log, cron history, page timings |

**Lead statuses**, in order:
1. `new` → `enriched` (or `no_website` / `no_email` / `skipped`)
2. → `drafted` (or `disqualified`)
3. → `queued` → `contacted` → `completed`

A lead can end at any point as `replied`, `booked`, `bounced`, `unsubscribed` or `error`.

**LinkedIn states:** `none` → `queued` → `requested` → `connected` → `messaged` → `replied`, or `closed`.

## 6. The pipeline (`lib/pipeline.php`, `lib/linkedin.php`)

`cron.php` calls `run_jobs($jobs, $budget, $onlyWorkspace)`:
- It takes a file lock (an overlapping run is skipped and recorded).
- It visits active workspaces least-recently-served first, giving each a fair slice of the 50-second budget.
- It catches and reports every job error.

Run any subset by hand: `php cron.php find,enrich,draft,send,inbox,linkedin [workspaceId]`.

| Job | What it does | External calls |
|---|---|---|
| `find` | Google Places Text Search per pending query; filters by rating, reviews and business status; stores `place_id` only | Places (counted against the monthly cap) |
| `enrich` | Fetches the business website with SSRF protection; extracts name, phone, emails, signals and LinkedIn link; skips "no soliciting" sites | Business websites, optional Place Details and Hunter |
| `draft` | AI fit score plus 1 + N emails, and LinkedIn copy when on; runs `draft_safety_issues()` checks; auto-approves only clean, high-scoring drafts | AI |
| `send` | Per inbox: warm-up limit, gap, the lead's local business hours, test/dry-run modes; PHPMailer; footer and List-Unsubscribe headers | SMTP |
| `inbox` | IMAP (built-in client): replies, bounces and auto-replies; AI classification; stops the sequence | IMAP, AI |
| `linkedin` | Creates connect tasks N days after email 1, writes missing copy, closes stale requests | AI (only for missing copy) |

**Stop rule:** anything that should end outreach calls `cancel_sequence($leadId, $why)`, which also calls `li_cancel()`. Never cancel emails or tasks directly elsewhere.

**Compliance built into sending** (don't remove it):
- the workspace's legal name and postal address in every email;
- an opt-out link plus one-click `List-Unsubscribe`;
- in Canada strict mode, only emails the business itself publishes (no Hunter);
- no sending without a postal address.

## 7. AI (`lib/ai.php`, `lib/presets.php`)

- `ai_json($system, $user, $maxTokens, $mockKind)` routes to the active provider (`anthropic`, `openai`, `gemini` or `mock`), parses JSON, records tokens and cost in `api_usage`, and enforces the platform budget and the workspace allowance.
- **Prompts live in `presets.php`.** Website text is wrapped in `<untrusted_website_text>` and the model is told never to follow instructions inside it.
  - After generation, `draft_safety_issues()` / `li_safety_issues()` flag links outside an allow-list, email addresses, phone numbers, injected phrases and over-length copy.
  - Flagged drafts are never auto-approved.
  - If you change a prompt, run the `inject.test` fixture and check the result.
- **Facts only:** prompts forbid invented results; "proof" comes only from the campaign field. Keep that rule.
- **The mock provider** returns deterministic JSON so the whole pipeline runs offline. Update `ai_mock()` when you change an output schema.

## 8. Errors, monitoring and speed

- **Never swallow errors silently.**
  - For an operational failure the user should see, use `log_event($leadId, $type, $detail, 'error')`. Error-level events in `REPORTABLE_EVENTS` also reach the developer log.
  - For anything a developer must fix, use `report_error($exceptionOrMessage, ['level' => 'error', 'source' => '…', 'context' => [...]])`.
- **Uncaught exceptions and fatals** are captured automatically. They show the user a friendly page with a reference code, are grouped on Admin console → System health, and alert the team (email and chat webhooks; new, regressed, 10/100/1,000 occurrences; max 30 an hour).
- **Thresholds:** a slow page is over 3 s (`SLOW_PAGE_MS`) and a slow query is over 1 s (`SLOW_QUERY_MS`); both are recorded automatically.
- **Speed rules:**
  - Add a composite index starting with `tenant_id` for any new list or filter query. Run `EXPLAIN` and avoid full scans.
  - Use `cache_remember($key, $ttl, $fn)` for expensive dashboard numbers (per workspace by default), and clear them with `cache_forget()` after an action changes them.
  - Paginate lists (`pager()`) and stream exports.
  - Keep each job within its `$deadline`, which every job receives.
- **Measured baseline:** 23 workspaces and 90,000 leads give 8–30 ms pages and a 3–5 s cron pass. Re-measure with the `dev/` kit before and after any heavy change.

## 9. Front end

- **CSS:** one stylesheet, `assets/app.css`, using the existing tokens (`--primary`, `--s1…s3`, `--bad`, `--warn`, `--good`, radii). Use the existing components: `.card`, `.panel`, `.kpis`, `.t` tables, `.btn` (filled/tonal/text/small/danger), `.chips`, `.badge`, `.lvl`, `.banner`, `.field`, `.switch`.
- **JS:** one script, `assets/app.js`, plain JavaScript with no framework.
- **Tables:** tables with `class="t"` turn into labelled cards on phones automatically; add `wide` to switch on tablets too.
- **Every page must fit 320–1920 px with no sideways scrolling.** Check at 320, 390, 768, 1024 and 1440 px.
- **Bump `APP_VERSION`** in every release that touches CSS or JS, so browsers fetch the new files.
- **No inline `<script>`.** The Content Security Policy blocks it; put behaviour in `app.js` behind a `data-*` attribute.

## 10. Database changes (migrations)

1. Raise `SCHEMA_VERSION` in `lib/bootstrap.php`.
2. Add `migrate_vN()`, and call it from `maybe_migrate()` (`if ($v < N) migrate_vN();`).
   - Make it safe to run twice: `CREATE TABLE IF NOT EXISTS`, `add_column_if_missing()`, `index_exists()`.
   - Use `const` arrays for column and index lists.
3. Add the same change to the end of `schema.sql`, so fresh installs match.
4. A new workspace table must have `tenant_id INT UNSIGNED NOT NULL` and an index starting with it, and must be added to `TENANT_TABLES`.
5. **Test both paths:**
   - an upgrade of a copy of the previous version's database;
   - a fresh install through `install.php` into an empty database.

## 11. Security checklist (every pull request)

- [ ] Every POST has `verify_csrf()` and a server-side role check.
- [ ] Every workspace query is scoped by `tenant_id`, and IDs from the browser go through `owned_row()` or a scoped `IN (…)`.
- [ ] Every value shown in HTML goes through `e()`, every SQL value is a bound parameter, and no table or column name comes from user input.
- [ ] No secret is in code, logs or error context. Keys go through `set_setting()` (encrypted); passwords go through `encrypt_str()`.
- [ ] Outbound HTTP to user-supplied or scraped URLs uses `safe_get()` (SSRF protection), never raw cURL.
- [ ] New AI output that is sent to prospects passes through a safety check before it can be auto-approved.
- [ ] Redirect targets are fixed lists, never `$_GET` URLs.
- [ ] New public folders or files are covered by `.htaccess` if they must not be served.
- [ ] Nothing automates LinkedIn (no sign-in, scraping or sending). That is a hard product rule.

## 12. Testing before a merge

There is no automated test suite yet. Every pull request must pass the CI check (PHP syntax on 8.1 and 8.3, plus a scan for committed secrets), then this manual test with the `dev/` kit:

1. **Pipeline:** the section 2 flow gives the expected find, research and draft results, and emails arrive in `dev/outbox/` with the footer and an opt-out link.
2. **Stop rules:** reply (IMAP), unsubscribe link, "They replied" on LinkedIn and "Mark booked" each cancel the remaining emails and LinkedIn tasks.
3. **Isolation:** create a second workspace. Opening its lead IDs, or posting them from the first workspace's session, must fail.
4. **Roles:** a viewer can't change anything, and a member can't open Mailboxes, Settings, Connections or Team.
5. **Both sign-ins:** a client account is refused at `/admin/`, and an admin account is refused at `/`.
6. **Any page you touched,** at 320 px and 1440 px.
7. **System health** shows no new open problems after the run.

**Good first improvement:** turn the steps above into PHPUnit and Playwright tests running in GitHub Actions against MariaDB and the `dev/` fixtures.

## 13. Branches, releases and deploys

- `main` is production: Hostinger auto-deploys every push to `main` (hPanel → Git).
- **Work on a branch,** `feature/…` or `fix/…`. Open a pull request with what changed, how you tested it and any migration. It needs a green CI check and one review before merging.
- **Release steps:**
  1. Bump `APP_VERSION` (semver).
  2. Add a `CHANGELOG.md` entry.
  3. Merge.
  4. Tag: `git tag v2.4.1 && git push --tags`.
- **Database backup:** Hostinger keeps automatic backups, but export the database manually before any release that contains a migration.
- **Staging (recommended):**
  1. A second subdomain with its own database and `outreach-config.php`.
  2. Deploy a `staging` branch there.
  3. Run section 12 on it.
  4. Then merge to `main`.
- **Rollback:**
  - Revert the merge commit on `main`; Hostinger redeploys the previous code.
  - Migrations only add things, so older code keeps working on a newer schema.
  - If a migration itself caused the problem, restore the backup.

## 14. Production facts

| Item | Where |
|---|---|
| App | `https://outreach.automindlab.io` (client) and `/admin/` (console) |
| Code folder | `domains/outreach.automindlab.io/public_html` (deployed from GitHub `main`) |
| Config | `domains/outreach.automindlab.io/outreach-config.php` (outside the web folder; never in Git) |
| Cron | hPanel → Advanced → Cron Jobs: `/usr/bin/php …/public_html/cron.php` every 5 minutes |
| Errors and alerts | Admin console → System health; uptime monitor on `healthz.php?key=…` |
| Database | hPanel → Databases (phpMyAdmin for exports) |
| Who to ask | The platform owner (product, keys, client decisions) |

Developers get their own platform-admin account during setup, and are removed at handover (Admin console → Platform admins). Never share the owner's login.

## 15. Troubleshooting for developers

| Symptom | Look at |
|---|---|
| Blank page or "Something went wrong" with a code | System health → Find → paste the code; the stack trace is there |
| A job does nothing | `php cron.php <job> <workspace>` prints the JSON result; check the workspace's sending mode, window, allowances and the campaign status |
| Nothing found locally | Is the Places mock on :8090? Are `places_endpoint` and `places_details_endpoint` set? Is `places_key` set (`seed_demo.php`)? |
| Research fails locally | Is the sites fixture on :80? Are the `/etc/hosts` entries there? Is `local_testing` on with a local `base_url`? |
| No emails in `dev/outbox` | Is the SMTP catcher running? Was the lead approved? Is the sending mode Test (not Paused or Dry run)? Has the inbox hit its daily limit? |
| "No workspace selected for insert" | You inserted into a workspace table outside a workspace context: call `set_tenant()` first (CLI and webhooks) |
| Console redirects to Account | Two-step verification is required; turn `admin_require_2fa` off only in your local config |
| CSS or JS changes not showing | Bump `APP_VERSION` |
