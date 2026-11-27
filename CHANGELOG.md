# Changelog: Automindlab AI Outreach

This package (v2.4.3) is the complete system: every feature below is included. Uploading it over any earlier version upgrades the database automatically on the first page load. Back up the database first.

---

## v2.4.3: Gemini default model

- New installs now default to `gemini-3.8-flash`. Google no longer serves `gemini-2.5-flash` to new accounts, so a new Gemini key failed its test with a 404. Existing installs keep whatever model is saved under Platform connections → Gemini; change it there if the test fails. The "$ per 1M tokens" price default is unchanged: check Google's current pricing so the spend tracker is accurate.

## v2.4.2: Security review fixes

- **Mail servers are checked.** SMTP and IMAP host names typed into Mailboxes must resolve to public addresses, so "Test connection" and the sending job can't be used to probe the hosting account's private network. Custom inboxes are limited to the standard encrypted ports (SMTP 465/587/2525, IMAP 993).
- **No more clear-text passwords.** IMAP always uses TLS (it used plain TCP on any port other than 993), and SMTP "None" security is refused. Only the offline dev kit (`local_testing`) may use unencrypted mail. An inbox saved with "None" now shows a clear error until it is edited.
- **Secrets stay out of the error log.** PHP no longer records function arguments in stack traces, so database, inbox and user passwords can't end up in System health or alert messages.
- **Database errors no longer reach the screen.** A failed query shows a short reference code; the full error (SQL, tables) goes to System health only.
- **Sign-in protections extended.** Changing the password and turning off two-step verification or renewing recovery codes now count failed attempts toward the sign-in lockout, and an authenticator code can't be reused there.
- **Safer redirects.** A refused form post only returns to a page on this site, never to an outside address taken from the Referer header.
- **Installer.** It reopens only when the `users` table doesn't exist; a database timeout or permission error can no longer be mistaken for "not installed yet".
- **Deleting leads** keeps the send history of leads that were already contacted (previously their emails were removed even though the lead stayed); LinkedIn tasks of deleted leads are removed.
- **`dev/` and `docs/` have their own `.htaccess`,** so they stay private even if URL rewriting is off. Outgoing HTTP requests are limited to http/https.

## Today: 10 November 2026 (v2.1 → v2.4.1)

### v2.4.1: Developer handbook and local test kit
- **`docs/HANDBOOK.md`:**
  - architecture and request lifecycle; the two sign-in areas;
  - the workspace-isolation rules every change must follow;
  - data model, pipeline jobs, AI safety, error log and performance rules;
  - how to write a migration; a security checklist for every pull request;
  - manual tests, releases, deploys and rollback.
- **`dev/` kit,** so the whole system runs offline on a developer's machine: fixture websites, a mock Google Places, an SMTP catcher, an optional IMAP server, a local config and a demo seeder. The handbook's local setup was followed from a clean copy: install, seed, then find → enrich → draft → approve → send → LinkedIn tasks → reply, which stopped the sequence on both channels.
- **Safer test mode:** `local_testing` now only takes effect when `base_url` is a local address (`localhost`, `127.0.0.1`, `*.test`, `*.localhost`). On a real domain it is ignored and raised as a critical problem on System health.
- `.htaccess` also blocks `dev/`; `dev/outbox/` is git-ignored.

### v2.4: Assisted LinkedIn outreach
- **Per-campaign switch** (off by default): "Add LinkedIn steps for leads whose website links to LinkedIn".
- **Finds the LinkedIn link the business publishes on its own website** (an owner's profile is preferred over the company page). LinkedIn itself is never scraped, and a profile can also be pasted on the lead page.
- **The AI writes a connection note (≤200 characters), a message for after they accept, and a follow-up,** from the same research as the emails, with the same safety checks. Canadian leads get a plain opt-out line. Copy is written only for leads with a LinkedIn link; leads drafted earlier get it when their first task is due.
- **LinkedIn tasks page:**
  - A connection request is due N days after the first email (set per campaign).
  - Each task has Open LinkedIn, copy-ready text, and buttons to record what happened: Sent request, Sent without note, Already connected, Can't find them, Accepted, Sent, They replied, Skip.
  - Requests are limited to 20 per person per day by default, adjustable in Settings up to 40. Unaccepted requests close after 21 days.
- **One set of stop rules for both channels.** A reply, booking, opt-out or bounce on email cancels the LinkedIn steps, and a LinkedIn reply cancels the remaining emails. "Asked to stop" adds the lead to the do-not-contact list.
- **LinkedIn in the numbers:** replies count in the workspace and admin dashboards; the workspace dashboard has a LinkedIn card; the admin overview flags overdue tasks; the CSV export has LinkedIn columns.
- **What it doesn't do:** the app never signs in to LinkedIn or sends anything there. LinkedIn's terms don't allow software to send for its members, so a person does the sending.
- Database **schema 8**: the `li_tasks` table, plus LinkedIn columns on leads, campaigns and replies.


### Repository (GitHub-ready)
- Adds `.gitignore` (config and runtime files never committed), `.gitattributes`, `.editorconfig`, a proprietary `LICENSE` with third-party notices, `SECURITY.md`, `docs/GITHUB.md`, and a GitHub Actions check (PHP syntax on 8.1 and 8.3, plus no committed secrets).
- `.htaccess` now also blocks `.git/`, `.github/`, `docs/`, dotfiles and `.md`/`.sql`/`.yml` files, which matters when Hostinger deploys straight from GitHub.
- `install.php` answers "not found" once an admin exists, so it is safe to leave in a Git deploy. The "delete install.php" banner was removed.

### v2.3: Separate sign-in for admins and clients
- **Admin console moved to `/admin/`,** with its own sign-in page (`admin/login.php`), its own session cookie (`amlo_admin`, sent only to `/admin/`), and stricter timeouts (30 min idle, 8 h total).
- **Two-step verification is required** for every platform admin before any console page opens.
- **Each sign-in page only accepts its own accounts.** The client sign-in page (`login.php`) refuses admin accounts. The admin page refuses client accounts with the same message as a wrong password.
- **Opening a client workspace from the console** uses a one-time link (60 seconds, single use) that starts a separate client-app session. A banner shows when an admin is inside a client's workspace, and "Admin console" or "Sign out" returns to the console.
- **Two-step codes can't be replayed** in another browser (the last used code is recorded per account).
- **Old addresses redirect:** `/admin.php`, `/workspaces.php`, `/platform.php`, `/health.php` and `/admins.php` now point to `/admin/…`.
- **Installer** now points the first admin to the console sign-in.
- Database **schema 7**: `login_handoffs` table and `users.totp_last_step`.

### v2.2: Two dashboards (master admin and client)
- **Master dashboard** (console Overview), covering every client:
  - KPIs: meetings booked, workspaces live or testing, new leads, emails sent, bounce rate, AI spend.
  - A **Needs attention** list (failing inboxes, high bounce rate, missing postal address, allowances nearly used, inactivity, unanswered replies, platform issues). The same issue in 3+ workspaces is shown once.
  - A sortable comparison table of all workspaces with **Open** buttons.
  - Platform usage meters, a 30-day chart across all clients, and recent changes.
- **Platform connections** page in the console: shared AI, Google Places and Hunter keys, AI provider and budget, Places caps, and the cron job. These moved out of the client Connections page.
- **Platform admins** page: add or remove admin access, and see everyone with an account.
- **Client dashboard and menu** now show only that workspace's own pages; nothing platform-wide is visible to clients.
- Database **schema 6**: indexes for the cross-workspace totals. The overview loads in about 0.1 s with 23 workspaces and 90,000 leads.

### v2.1: Developer error log, alerts and performance
- **System health** page (console only), recording:
  - PHP warnings, crashes and fatal errors;
  - failed cron jobs with stack traces;
  - failing integrations (AI, Places, SMTP/IMAP, cal.id);
  - pages slower than 3 s and SQL queries slower than 1 s;
  - cron not running, or a run stuck.
- **How problems are tracked:**
  - Repeats are grouped, with counts, affected workspaces, the last 25 occurrences and a copy-ready developer report.
  - Problems can be marked fixed (they reopen if they recur) or muted.
- **Reference codes on error pages** let you find the exact occurrence.
- **Alerts:**
  - Channels: email plus Slack, Discord, Google Chat, Telegram, n8n, Make or Zapier webhooks.
  - Triggers: a new problem, a regression, 10/100/1,000 occurrences, or a critical problem still happening.
  - Limits: max 30 an hour. A daily summary is sent at 05:30 IST, and "cron is running again" is sent on recovery.
- **If the database is down,** errors are written to `storage/errors-fallback.log`, with an optional webhook in the config file.
- **Uptime-monitor endpoint** `healthz.php` (200 healthy / 503 not).
- **Performance:**
  - 18 composite indexes per workspace.
  - Cached dashboard numbers.
  - Campaign counts in one grouped query instead of one per campaign.
  - Streaming CSV export.
  - A cron overlap lock and stuck-run alarm.
  - A configurable cron time budget.
  - Browser caching and compression for assets.
  - "Run a step now" no longer blocks other tabs.
- **Measured results:** in a 40,000-lead workspace, the dashboard went from 246 ms to 13 ms, Leads from 140 ms to 28 ms, and Campaigns from 114 ms to 23 ms. One cron pass over 23 workspaces takes 3–5 s.
- **Daily housekeeping** of old logs, samples and expired invites.
- Database **schema 5**.

---

## Earlier

### v2.0.1, 9 November: Works on every screen
- Tables turn into cards on phones (and wide tables on tablets); all pages fit from 320 px to 1920 px.
- Phone top bar, menu drawer and sign-in pages were reworked. iPhone zoom-on-tap and notch spacing are handled.

### v2.0, 9 November: Multi-workspace (multi-tenant)
- **Agency-managed workspaces,** one per client, each with its own leads, campaigns, inboxes, replies, bookings, settings, opt-out list, activity and team.
- **Roles:** viewer, member, admin, owner, plus platform admin. Invite links can be used once and expire after 7 days.
- **Per-workspace monthly allowances** for leads, AI spend and inboxes. Suspend or resume a workspace; billing is manual.
- **Each workspace has its own cal.id webhook.** Cron serves all workspaces fairly. Bounces are blocked across all workspaces.
- Database **schema 4** (existing data became workspace #1).

### v1.x: The core AI outreach system
- **Lead finding** with Google Places API (New), storing only the place ID as Google's terms require. Leads are filtered by rating, reviews and business status.
- **Research** of each business's own website: business name, published emails and signals. It is SSRF-safe and respects "no solicitation" notices. Hunter.io is an optional backup for US leads.
- **AI fit scoring and personalised 4-email sequences** with Claude, OpenAI or Gemini (switchable), with prompt-injection guards and cost tracking.
- **Review queue** with manual or automatic approval; editable drafts.
- **Sending:**
  - from warmed inboxes (Zoho, Google, Hostinger or any SMTP/IMAP);
  - inside each lead's local business hours, with daily limits and warm-up ramp;
  - with one-click unsubscribe, and Live, Test, Dry run and Paused modes.
- **Reply handling over IMAP:** replies are classified by AI with suggested answers; bounces and auto-replies are detected; a sequence stops on reply, booking or opt-out.
- **cal.id booking link** pre-filled with the prospect's email; bookings are recorded through a webhook.
- **Compliance:** CAN-SPAM and CASL (Canada strict mode), postal address in every email, do-not-contact list.
- **Security:**
  - encrypted secrets, CSRF protection, bcrypt passwords;
  - two-step verification with recovery codes;
  - sign-in throttling, session timeouts, strict security headers;
  - CSV formula escaping.
- **Design:** Material-style ("liquid") dashboard with your logo, a self-hosted Google Sans Flex font, and an installer.
