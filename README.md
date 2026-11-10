# Automindlab AI Outreach

Multi-workspace AI cold-outreach platform for local businesses in the US and Canada. It finds leads with Google Places, researches each business's own website, writes personalised email sequences with AI, sends them from warmed inboxes, handles replies, and books meetings on cal.id. It's built in plain PHP + MySQL, runs on Hostinger shared hosting, and needs no build step.

**Two areas:** the client **workspace dashboard** (`/`) and the **Admin console** (`/admin/`), each with its own sign-in.

| Document | What's in it |
|---|---|
| [README.md](README.md) | This guide: setup, upgrade, operations, costs, compliance |
| [CHANGELOG.md](CHANGELOG.md) | Every version, newest first |
| [CODEBASE.md](CODEBASE.md) | Map of every file, table and setting |
| [docs/HANDBOOK.md](docs/HANDBOOK.md) | Developer handbook: architecture, local setup with the `dev/` kit, workspace rules, migrations, testing, releases |
| [docs/GITHUB.md](docs/GITHUB.md) | Putting the code on GitHub and deploying it to Hostinger from there |
| [SECURITY.md](SECURITY.md) | Secrets handling and reporting a vulnerability |

**Never commit secrets.** API keys and inbox passwords live encrypted in the database; database credentials and `app_key` live in `outreach-config.php`, outside the web folder and outside this repository (see `.gitignore`).

---

# Deployment guide

A self-hosted cold-outreach system for **automindlab.io** that runs entirely on Hostinger shared hosting (PHP + MySQL + cron).

```
Google Maps search ─▶ website research ─▶ AI fit score + 4-email sequence ─▶ you approve
      (Places API)      (email + signals)     (Claude / OpenAI / Gemini)        (or auto-approve)
                                                                                   │
   cal.id booking ◀── AI-drafted reply ◀── reply detected + classified ◀── throttled sending
     (webhook)          (you send it)            (IMAP every 10 min)        (Zoho / any SMTP)    
```

Everything is tracked in the dashboard: funnel, review queue, replies inbox, campaigns, mailbox health, API spend, and a full activity log. The dashboard works on phones, tablets and desktops: on phones every table turns into a stack of cards, and the menu slides in from the left.

---

## Upgrading to v2.4 — LinkedIn steps, separate admin and client sign-in, Admin console, multi-workspace, error log and speed (from any v1.x or v2.x)

1. **Back up the database first:** hPanel → Databases → phpMyAdmin → select the database → *Export* → *Quick* → *Go*.
2. In hPanel File Manager, open the app folder, upload `automindlab-outreach.zip` and **Extract** over the existing files. Your config file is not in the zip, so it is kept.
3. Open the dashboard once. The database upgrades itself: everything you already have becomes workspace **#1 (your agency)**, you become its owner and a **platform admin**, and your existing cal.id webhook URL keeps working.
4. **Delete `install.php`** if it's there, and (recommended) move the config file out of `public_html` as `outreach-config.php`.
5. Account menu → **Account and security**: change the temporary password and turn on two-step verification.
6. **Platform admins now sign in at `https://outreach.automindlab.io/admin/`** (the client sign-in page refuses admin accounts). The console asks you to turn on two-step verification before anything else — have your authenticator app ready. Its **Platform connections** page holds the AI, Google Places and Hunter keys (moved out of the workspace Connections page).
7. Admin console → **System health** → *Alerts*: add the developers' emails and/or a Slack/Discord/Google Chat/Telegram webhook, choose the inbox that sends alert emails, then **Send test alert**.
8. System health → *Server*: copy the uptime-monitor URL into UptimeRobot (or similar), and check OPcache shows **On**.
9. Optional: add `'alert_webhook' => 'https://…'` to the config file, so you're told even when the database is down.

Nothing else changes for workspace #1. The new pieces are below in **System health**, **Performance and capacity** and **Workspaces and teams**.

## Assisted LinkedIn outreach (v2.4)

LinkedIn doesn't allow software to send connection requests or messages for its members (its User Agreement and "Prohibited software" policy), and its API has no way to do it. Tools that do it anyway risk getting the account restricted. So the app does the research, writing, scheduling and tracking, and a person does the sending in their own LinkedIn: about 10–15 minutes a day.

1. **Switch it on per campaign:** Campaigns → edit → *LinkedIn (assisted)*. Choose how many days after the first email the connection request is due (default 1), and the gap before the LinkedIn follow-up (default 4).
2. **Links:** website research picks up the LinkedIn link a business publishes, preferring an owner's profile over the company page. You can also paste a profile on the lead page.
3. **Copy:** the AI writes a connection note (≤200 characters), a message for after they accept, and a follow-up. All three are editable on the lead page.
4. **Daily work:** menu → **LinkedIn tasks**. Open LinkedIn, copy the text, send it, and press the matching button. Accepted requests appear in *Waiting for acceptance*; mark them **Accepted** and the message task appears.
5. **Limits:** 20 requests per person per day by default (Settings → LinkedIn, max 40). Free LinkedIn accounts can add a note to only a few requests a month; use **Sent without note** and let the message after acceptance do the work.
6. **Stop rules:** a reply on either channel, a booking, an opt-out or a bounce stops both channels. **Asked to stop** adds the lead to the do-not-contact list.

## Separate sign-in for admins and clients (v2.3)

| | Client teams | Platform admins |
|---|---|---|
| Sign-in page | `https://outreach.automindlab.io/` (`login.php`) | `https://outreach.automindlab.io/admin/` (`admin/login.php`) |
| Who can use it | Workspace owners, admins, members, viewers | Only accounts marked platform admin |
| Lands on | Their workspace dashboard | Admin console overview |
| Session cookie | `amlo_sess` | `amlo_admin`, sent only to `/admin/`, SameSite=Strict |
| Sign-out after | 2 h idle / 12 h total | 30 min idle / 8 h total |
| Two-step verification | Optional (recommended) | **Required** before any console page opens |

- The client page refuses admin accounts (and tells the admin where to go); the admin page refuses client accounts with the same message as a wrong password, so it doesn't reveal which emails exist.
- **Opening a client workspace from the console** (Open buttons, the "Open a workspace" menu) creates a one-time link that works once, for 60 seconds, and starts a separate client-app session for that workspace. A dark banner shows you're inside a client's workspace as a platform admin; **Admin console** in the top bar, or signing out there, takes you back. Your console session is untouched.
- Removing someone's platform access, or changing their password, ends both of their sessions.
- A two-step code works once per account, not just per browser.
- Old console addresses (`/admin.php`, `/workspaces.php`, `/platform.php`, `/health.php`, `/admins.php`) redirect to `/admin/…`.

## Two dashboards: Admin console and workspace dashboard (v2.2, now under /admin/)

**Admin console** (`/admin/`) — only platform admins (you, and your developer during setup), with its own sign-in. Black "Admin console" badge in the top bar.
- **Overview (master dashboard):** totals across every client — meetings booked, active/live workspaces, new leads, emails sent, bounce rate, platform AI spend. A *Needs attention* list ranks problems (failing inboxes, bounce rate over 3%, missing postal address, allowances nearly used, no activity for a week, unanswered replies, platform issues such as an untested AI key or admins without two-step verification); the same issue in 3+ workspaces is shown once. A table compares every workspace — sending mode, leads and AI against allowances, sent, reply rate, booked, bounce rate, work waiting, last activity — sortable by attention, bookings, sent, leads, recency or name, with **Open** to jump into that workspace. Plus platform usage meters, a 30-day sends/replies chart across all clients, and recent workspace and team changes. Figures refresh every minute.
- **Workspaces** — create, set allowances, suspend, invite owners.
- **Platform connections** — the shared AI / Google Places / Hunter keys, AI provider and budget, Places caps, and the cron job.
- **System health** — error log, alerts, cron runs, page speed, server.
- **Platform admins** — who has platform access (add an existing account, remove access, see everyone's workspaces and 2-step status).

**Workspace dashboard** — what each client team sees (and what you see after pressing **Open** on a workspace): Dashboard, Review queue, Leads, Replies, Campaigns, Mailboxes, Connections (sending mode, cal.id, inboxes), Settings, Team, Activity — for that one workspace only. Platform admins get an **Admin console** button in the top bar to go back. Client users never see the console or anything platform-wide.

## System health: developer error log and alerts (v2.1)

Platform admins get a **System health** page (heart icon in the menu). Everything that goes wrong is recorded there automatically:

- **What's captured:** PHP warnings and fatal errors, uncaught exceptions, failed cron jobs (with stack trace), failing integrations (AI, Google Places, SMTP/IMAP, cal.id), pages slower than 3 s, SQL queries slower than 1 s, cron not running for 20+ minutes, and a cron run stuck for 15+ minutes.
- **Grouped, not repeated:** the same problem is one row with a count, the workspaces it affected, first/last seen, the last 25 occurrences (time, workspace, user, URL, reference) and the stack trace. A **Report for the developer** box gives a copy-ready summary with no secrets in it.
- **Reference codes:** visitors who hit an error see a friendly page with a code such as `3FA9C21B`; paste it in *Find* on System health to jump to the exact occurrence.
- **Alerts** (System health → Alerts): email (sent through an inbox you choose — use an `alerts@` inbox with prospect sending switched off) and/or chat webhooks — Slack, Discord, Google Chat, Telegram (`https://api.telegram.org/bot<token>/sendMessage?chat_id=<id>`), or n8n/Make/Zapier. You're alerted when a problem is new, comes back after being marked fixed, reaches 10/100/1,000 occurrences, or (critical) is still happening 2 hours later. Max 30 alerts an hour. A daily summary goes out with the first cron run after midnight UTC (05:30 IST). "Cron is running again" is sent automatically on recovery.
- **Mark as fixed / Mute:** fixed problems reopen (and alert) if they recur; muted ones are still counted but not alerted.
- **If the database is down** nothing can be stored in it, so errors go to `storage/errors-fallback.log` (shown on System health once the database is back) and, if you set `alert_webhook` in the config file, a critical alert is posted there directly (at most every 15 minutes).
- **Uptime monitor:** System health → Server shows a `healthz.php?key=…` URL. Add it to a free monitor (e.g. UptimeRobot, every 5 minutes): it returns 200 when the database works and cron ran in the last 20 minutes, 503 otherwise — so you're told even if the whole server is down.
- **Housekeeping (daily, automatic):** error occurrences kept 30 days, fixed problems 90 days, cron history 14 days, page timings 60 days, sign-in attempts 30 days, activity log 365 days.

## Performance and capacity (v2.1)

- **Indexes for many workspaces:** 18 composite indexes lead with `tenant_id`, so each workspace's pages only touch its own rows.
- **Cheaper dashboard:** headline numbers and the 14-day chart are cached per workspace for 60 s / 5 min (cleared when you press *Run a step now*); campaign counts are one grouped query instead of 4–5 sub-queries per campaign.
- **Streaming CSV export:** exports stream row by row, so a 40,000-lead workspace exports without running out of memory.
- **Cron:** one lock (overlapping runs are skipped and recorded), fair time-slicing across workspaces (least recently served first), a configurable time budget (`cron_budget`, default 50 s), and a stuck-run alarm. *Run a step now* no longer freezes your other tabs.
- **Static files:** compressed and cached by the browser for a year (they're versioned, so updates still load immediately).

Measured on the test server with 23 workspaces, 90,000 leads, 270,000 emails and 190,000 activity entries (fastest of 3 loads, server time):

| Page | 40,000-lead workspace before | after | 2,500-lead workspace after |
|---|---|---|---|
| Dashboard | 246 ms | 13 ms | 7 ms |
| Leads | 140 ms | 28 ms | 8 ms |
| Campaigns | 114 ms | 23 ms | 10 ms |
| Lead search (text) | 170 ms | 99 ms | 11 ms |
| Workspaces (all clients) | 108 ms | 27 ms | 27 ms |

A full cron run across all 23 workspaces took 3–5 s of its 50 s budget.

**Capacity guide:**
- **Shared hosting (Hostinger Business), 5-minute cron:** comfortable for ~10–15 active client workspaces. The limit is AI drafting and website research time per cron run, not the database.
- **Beyond that, or if System health shows runs near 55 s or "skipped" runs:** move to a small VPS (2 vCPU / 4 GB). Run cron every minute; overlapping runs are skipped automatically.
- **Keep OPcache on.** It's shown on System health → Server; turn it on under hPanel → Advanced → PHP Configuration.

## Workspaces and teams (v2.0)

The app is now **agency-managed multi-tenant**: you run one installation and create a workspace per client. Each workspace has its own leads, campaigns, inboxes, replies, bookings, settings, opt-out list, activity log and team. Nobody in one workspace can see or change another's data.

**Who pays for what**
- **Platform (you):** the Claude / OpenAI / Gemini, Google Places and Hunter keys, set once in *Connections* and shared by every workspace. Each workspace gets a monthly **allowance** so one client can't use up your budget: new leads, AI spend in dollars, and number of sending inboxes (0 = unlimited). Allowances reset on the 1st (UTC). When a workspace hits its lead allowance, lead search pauses for it until next month and the activity log says so.
- **Each workspace:** its own sending inboxes and domain (Zoho, Google or any SMTP/IMAP), its own cal.id link and webhook, business name, legal name and **postal address** (used in that client's email footers), and sending window.
- **Billing** is manual: track it in each workspace's *Notes* on the Workspaces page and suspend a workspace if needed.

**Creating a client workspace** (platform admins): menu → **Workspaces** → *New workspace* → name, plan (Starter / Growth / Scale fill in allowances you can edit), and optionally the owner's email. You get a one-time **invite link** to send them yourself — the app doesn't send it. New workspaces start with sending **paused**; the client connects an inbox and switches to Test, then Live, in their *Connections* page.

**Switching:** the workspace name in the top bar opens a switcher. Platform admins can open every workspace; others see the ones they belong to.

**Roles** (menu → **Team**, admins and owners):

| Role | Can |
|---|---|
| Viewer | see everything in the workspace, change nothing |
| Member | review and approve leads, edit drafts, handle replies |
| Admin | also campaigns, inboxes, workspace settings, Connections (bookings and sending mode), and inviting members and viewers |
| Owner | everything, including managing other owners and admins |
| Platform admin | every workspace, plus the Workspaces page, shared API keys, cron and AI provider |

Invitations are links valid for 7 days, usable once, tied to the invited email address. A person who already has an account just signs in and accepts; a new person sets a password (12+ characters). Every workspace keeps at least one owner. A **suspended** workspace can be viewed but not changed, and nothing is searched or sent for it.

**cal.id per workspace:** each workspace's Connections page shows its own webhook URL (`webhook-cal.php?w=<workspace>&key=<its key>`), so bookings land in the right workspace.

**Cron:** still one cron job for the whole platform. Each run takes turns across active workspaces, least recently served first, within the 50-second budget. To run one workspace by hand: `php cron.php send,inbox,find,enrich,draft 2`.

**Shared protection:** bounced addresses are blocked for every workspace (protects all your sending domains); unsubscribes and do-not-contact entries apply to the workspace whose emails the person opted out of.

**Before you take on paying clients:** move this app to its own hosting plan or a small VPS (not shared with your WordPress sites), turn on two-step verification for every admin, and have your own lawyer review the client agreement — each client is the sender of record for its emails under CAN-SPAM and CASL.

---

## 1. Monthly cost (target ≤ $40)

| Item | Cost / month |
|---|---|
| **Zoho Mail Lite × 3 inboxes** on a secondary domain (recommended to start) | ≈ $3 (≈ ₹59 per inbox per month, billed annually) |
| *or* Google Workspace Business Starter × 3 | $21 (annual, $7 each) – $25.20 (monthly, $8.40 each) |
| *or* Mailforge (10 cold-email mailboxes, can be pre-warmed) | ≈ $30 |
| Secondary domain (e.g. `getautomindlab.com`) | ≈ $1 (≈ $10–12/yr) |
| Google Places API (Text Search + Place Details, Enterprise) | $0 while each stays under its 1,000 free calls/month (app caps both at 950) |
| AI (Claude Haiku / GPT-4o-mini / Gemini Flash) | ≈ $2–6 for ~500–600 leads; hard budget stop in Settings |
| Hunter.io (optional email fallback) | $0 on the free plan |
| Hostinger shared hosting | already paid |
| **Total** | **≈ $8–12 with Zoho**, ≈ $25–33 with Google Workspace |

Capacity at that cost: 3 inboxes × 30 emails/day ≈ 90 sends/day. With a 4-email sequence that's roughly 25–30 new prospects per day, 500–600 per month.

---

## 2. Before you deploy (one-time, ~1 hour + 2 weeks warm-up)

### 2a. Sending domain + inboxes
1. Use a look-alike domain, e.g. `getautomindlab.com`. **Never send cold email from automindlab.io itself.** Point its website to automindlab.io (301 redirect).
2. Pick an inbox provider. The Mailboxes screen has presets for each, which fill in the server settings and show the steps:
   - **Zoho Mail (cheapest, recommended to start):** sign up for **Mail Lite** (paid — the free plan has no IMAP/SMTP) on the sending domain and create 2–3 users. Choose the India region if you sign in at mail.zoho.in. DNS: MX `mx.zoho.in` (10), `mx2.zoho.in` (20), `mx3.zoho.in` (50); SPF `v=spf1 include:zohomail.in ~all` (use `.com` versions for a global account); DKIM from Zoho Admin → Domains → Email Configuration. For each user: Settings → Mail Accounts → IMAP → tick IMAP Access, turn on two-factor sign-in, and create an **app password**.
   - **Google Workspace (best inbox placement):** MX `smtp.google.com` (1); SPF `v=spf1 include:_spf.google.com ~all`; DKIM from Admin → Apps → Gmail → Authenticate email. Each user turns on 2-Step Verification and creates an app password.
   - **Mailforge / Maildoso / other cold-email providers:** they set up DNS for you and give SMTP + IMAP details per mailbox — choose "Mailforge, Maildoso or another SMTP provider" and paste them in.
   - Avoid Microsoft 365 / Outlook mailboxes: Microsoft is switching off password sign-in for SMTP, which this app uses.
3. Add DMARC for any provider (TXT on `_dmarc`): `v=DMARC1; p=none; rua=mailto:hello@automindlab.io`
4. **Warm up for 1–2 weeks** before cold sending: use the inboxes normally. The app also ramps each inbox automatically — 5/day on day one, +2/day, up to 30.

### 2b. Google Places API key
1. Google Cloud Console → new project → enable **Places API (New)** → create an API key.
2. Restrict the key: API restriction = Places API (New); application restriction = your Hostinger server IP (hPanel → Hosting → Details shows it).
3. Billing must be enabled, but usage stays free under the per-SKU free tier. Add a **budget alert** at $1 and, under Quotas, cap "Text Search" and "Place Details" requests per day to ~35 as a second safety net.

### 2c. AI key (any one; switchable later)
- Claude: console.anthropic.com → API keys
- OpenAI: platform.openai.com → API keys
- Gemini: aistudio.google.com → Get API key

---

## 3. Deploy on Hostinger

1. **Subdomain**: hPanel → Domains → Subdomains → create `outreach.automindlab.io`. Enable SSL for it (hPanel → Security → SSL).
2. **PHP version**: hPanel → Advanced → PHP Configuration → PHP **8.2 or 8.3**. Make sure `sodium`, `curl`, `mbstring`, `pdo_mysql` are on (default).
3. **Database**: hPanel → Databases → MySQL → create database + user; note name, user, password.
4. **Upload**: File Manager → open the subdomain's folder (e.g. `domains/outreach.automindlab.io/public_html`) → upload `automindlab-outreach.zip` → Extract so `index.php` sits directly in that folder.
5. **Config**: copy `config.sample.php` to `outreach-config.php` in the folder **above** `public_html` (recommended — it can never be served to the web), or to `config.php` inside the app folder, and fill in DB details and `base_url` (`https://outreach.automindlab.io`). For `app_key`, open `/install.php` once — if the key is missing it shows a freshly generated one to paste in. **Never change `app_key` after install** (it encrypts your API keys and app passwords).
6. Open `https://outreach.automindlab.io/install.php` → create your admin login (12+ characters) → **delete `install.php`** from File Manager.
7. Sign in → account menu → **Account and security** → turn on two-step verification.
8. **Cron**: hPanel → Advanced → Cron Jobs → Custom → every 5 minutes (`*/5 * * * *`), command shown on the Settings page, e.g.
   `/usr/bin/php /home/u123456789/domains/outreach.automindlab.io/public_html/cron.php`
   (If CLI cron isn't available on your plan, use the "Cron URL" from Settings with any free URL-cron service.)

---

## 4. Configure in the dashboard

1. **Settings** — business name, legal entity, **postal address** (required by US CAN-SPAM and Canada CASL; a red banner shows until it's set), sending window (applied in each **lead's** local time), and the cron command to copy.
2. **Admin console → Platform connections** — one card per shared API (AI, Google Places, Hunter); the workspace's own **Connections** page holds sending mode, cal.id and inboxes:
   - **Claude / OpenAI / Gemini**: paste the key, check the model name, press **Save and test**. Pick which one drafts emails with the switch at the top of the section, and set the monthly AI budget.
   - **Google Places**: paste the key → **Save and test** (uses one search call). The usage bar shows calls used this month.
   - **Hunter.io** (optional): backup email finder for US leads.
   - **cal.id bookings**: copy the webhook URL into cal.id → Settings → Developer → Webhooks (cal.id is Cal.com-based; the menu name may differ slightly) and select *Booking Created / Rescheduled / Cancelled*. The card turns green when the first webhook arrives. If your cal.id plan has no webhooks, use **Mark booked** on the lead page.
   - **Sending mode** at the top: *Test* (everything goes to your own inbox), *Dry run*, *Paused* or *Live*, with a go-live checklist that ticks itself off as you connect things.
   Each card shows its status (Connected / Failed / Not connected) and when it was last tested. Keys are stored encrypted and only the last 4 characters are ever shown.
3. **Mailboxes** → choose your provider (e.g. *Zoho Mail — India account*), enter the email address, your name and the app password → **Save and test**. You should see *SMTP login OK · IMAP login OK* and get a test email at your notify address — check it doesn't land in spam. If the login fails, the screen tells you the likely fix.
4. **Campaigns** → New → pick a preset (HVAC, plumbing, dental, med spa, auto repair, restaurants, gyms…) → edit locations (`City, ST` or `City, ON`), ICP and offer → keep **status Paused** until you've tested.

---

## 5. First-run checklist (do this before going live)

1. Connections → Sending mode → **Test**, with your own email. Every message now goes to you with `[TEST → prospect@…]` in the subject.
2. Activate one campaign with 1–2 locations → Dashboard → **Find leads**, **Research sites**, **AI draft**.
3. Open **Review queue** → read the drafts. Edit the campaign ICP/offer/proof if the tone or angle is off, then *Re-draft with AI*.
4. Approve 3–5 leads → **Send due** → check your inbox for formatting, links, signature, and footer. Run the email through mail-tester.com (aim for 9+/10).
5. Switch Sending mode to **Live** once the checklist is green → you're live. Keep **approval = manual** for the first 2–3 weeks; switch high-performing campaigns to *auto-approve ≥ 80* later.

**Daily routine (10 min):** Review queue → approve/edit · Replies → send the AI-drafted answer (edit first) · glance at bounce rate and mailbox status.

---

## 6. How each stage works

| Stage | What happens | Where to see it |
|---|---|---|
| Find | Google Maps text search per keyword × location (3 pages × 20 results). Skips closed businesses, no-website listings, chains (review-count ceiling) and duplicates. **Only Google's place ID is stored**, plus your own search keyword and city. | Campaign → searches table |
| Research | Looks up the website address by place ID (used once, not stored), then fetches homepage + contact/about pages, takes the business name, phone and address from the site itself, finds a published email (incl. Cloudflare-obfuscated and "name [at] domain"), detects booking/chat/forms/pixel/24-7 signals, builds a text excerpt. | Lead → Business, AI research |
| AI draft | Scores fit 0–100 against the ICP, notes observations, writes email 1 (no links, ≤90 words) + follow-ups that bring in the booking link. Never invents results — only uses the proof you supply. | Review queue / Lead page |
| Send | Plain text, one email per inbox per run, random 6–14 min gaps, lead's local business hours, warm-up ramp, threaded follow-ups ("Re:"), List-Unsubscribe headers, opt-out link + address footer. | Lead timeline, Mailboxes |
| Inbox | Reads new mail every 10 min. Replies stop the sequence and are classified (interested / question / not interested / unsubscribe / out-of-office / referral) with an AI-drafted response; you get an email for hot replies. Bounces suppress the address. Out-of-office replies don't stop the sequence. | Replies |
| Book | cal.id webhook → lead *Booked*, sequence stopped, meeting on the dashboard. | Dashboard → Upcoming meetings |

Safety rails: monthly AI budget stop, monthly Places caps, AI drafts with unexpected links/emails/phone numbers are held for manual review (never auto-approved), inbox auto-pause if its 7-day bounce rate exceeds 5%, global "Pause all sending", dry-run mode, do-not-contact list (emails and whole domains).

---

## 7. Security

- **Sign-in:** 12+ character passwords (common words rejected), bcrypt hashing, optional two-step verification (authenticator app) with 10 single-use recovery codes, lockout after 5 failed attempts per account or 10 per IP address in 15 minutes, sessions end after 2 hours idle or 12 hours total, changing your password signs out every other browser. Account and security also shows recent sign-in attempts.
- **Requests:** every form has a forgery token; sign-out is a protected action; all database queries are parameterised; all output is escaped; strict Content-Security-Policy and other security headers on every page.
- **Workspaces:** every query on client data is filtered by the current workspace; every record opened by ID is checked to belong to it; roles are checked on every page and every form submission, not just hidden in the menu. Invite links store only a hash of the token.
- **Secrets:** API keys and inbox app passwords are encrypted with libsodium using the `app_key` in your config file. Keep the config file outside `public_html`.
- **Prospect websites:** fetched with every redirect re-checked, the address resolved once and pinned, private/internal addresses blocked, and pages capped at 1.5 MB.
- **AI prompt injection:** website text and replies are passed to the AI as untrusted data, and drafts containing unexpected links, email addresses or phone numbers are held for manual review.
- **Exports:** CSV cells that start with `=`, `+`, `-` or `@` are escaped so spreadsheets can't run them as formulas.
- **Hosting:** on shared hosting, every site on the same plan can usually read the others' files. Once you keep client data here, run it on its own hosting plan or a VPS so a compromised WordPress site elsewhere can't reach it.

## 8. Compliance notes (not legal advice)

- **Google Maps Platform:** Google's terms don't allow storing Places content such as business names, addresses or reviews; the place ID may be stored. This version stores only the place ID and your own search inputs. Everything else on a lead comes from the business's own website. Review Google's current terms yourself before using this commercially.
- **Google Workspace:** Google's acceptable use policy prohibits using Workspace to "generate or facilitate unsolicited bulk commercial emails". Keep volumes low and personal, as the app's limits do, and accept that an inbox can be suspended.

- **US (CAN-SPAM)** allows B2B cold email if you use honest headers/subjects, include a physical postal address, and offer an opt-out honored within 10 business days. The app adds the address + opt-out to every email and suppresses opt-outs instantly (link, reply, or "unsubscribe" in the reply).
- **Canada (CASL)** is opt-in. Cold email can rely on *implied consent by conspicuous publication* only when the address is published by the business, has no "no unsolicited messages" notice, and your message is relevant to their business role. **Canada mode** (on by default) enforces this: only emails found on the business's own site, never Hunter, skip sites with no-solicitation notices, and the source URL + date found are stored on each lead as evidence. Every email identifies you and includes an unsubscribe link.
- Treat the address in Settings as the legal sender address (Revenue Pixels, Kolkata). Consider a Canadian or US mailing address if you scale in those markets.

---

## 9. Deliverability rules baked in (don't override them)

- ≤ 30–40 emails/day per inbox; add inboxes to scale, not volume per inbox.
- Plain text only, no tracking pixels, no link in email 1.
- Keep bounce rate < 3% (the dashboard KPI turns red above 3%; inboxes auto-pause above 5%).
- Rotate copy angles per campaign; don't send the same template to thousands.

---

## 10. Troubleshooting

| Symptom | Fix |
|---|---|
| Red banner "Cron has not run" | Make sure the cron command matches the path on the Settings page exactly and runs every 5 minutes; run it once via the Cron URL in a browser to see any error. |
| Mailbox login rejected | Use an **app password** (not the normal password) with two-step sign-in on. Zoho: tick IMAP Access in Zoho Mail settings and pick the right region (zoho.in vs zoho.com). |
| `IMAP: [ALERT] Application-specific password required` | Same as above. |
| Places "API key not valid" / 403 (shown on the Google Places card) | Enable *Places API (New)* (not the legacy Places API) and check key restrictions / server IP. |
| Locked out after wrong passwords | Wait 15 minutes. Lost your phone? Use a recovery code at the code step. |
| Many leads "No email" | Normal for some niches (30–50%). Add a Hunter key for US leads, or widen locations. |
| AI errors | Connections → the provider's card → Save and test. Model names are editable — update them if a provider renames models. |
| "Monthly AI budget reached" | Raise the budget in Settings; drafting resumes on the next cron run. |

---

## 11. Files

```
index.php        dashboard            leads.php / lead.php   leads + review queue + sequence editor
replies.php      replies inbox        campaigns.php          campaigns, presets, searches
mailboxes.php    inboxes + ramp       settings.php           keys, sending, compliance, cron/webhook URLs
activity.php     log + do-not-contact cron.php               pipeline runner (every 5 min)
account.php      password, two-step verification, sign-in activity, server checks
team.php         members, roles, invites   workspaces.php      platform admin: client workspaces, allowances, suspend
linkedin.php     LinkedIn tasks (assisted; the app never sends to LinkedIn)
admin/           the Admin console (own sign-in and session) — see CODEBASE.md for every file
enter.php        client-app end of the console's one-time "Open workspace" link
healthz.php      uptime-monitor endpoint
lib/errors.php   error capture, grouping, alerts, daily housekeeping
invite.php       accept an invitation      switch.php           change the current workspace
webhook-cal.php  cal.id bookings      unsubscribe.php        public opt-out page (one-click supported)
install.php      one-time installer (delete after use)
lib/             pipeline, AI providers, Places, website research, SMTP (PHPMailer), IMAP, prompts, presets
schema.sql       database schema      storage/               lock + error log (web access blocked)
assets/          logo, styles, Google Sans Flex font (self-hosted, SIL OFL), icons load from Google Fonts
```

Prompts live in `lib/presets.php` (`prompt_research_and_draft`, `prompt_classify_reply`) — edit there to change the AI's writing rules.
