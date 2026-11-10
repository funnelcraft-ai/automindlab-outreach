-- Automindlab Outreach schema (MySQL 5.7+/MariaDB 10.3+)

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  totp_secret TEXT NULL,
  totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  recovery_codes TEXT NULL,
  session_version INT NOT NULL DEFAULT 1,
  password_changed_at DATETIME NULL,
  is_platform_admin TINYINT(1) NOT NULL DEFAULT 0,
  last_tenant_id INT UNSIGNED NULL,
  name VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(80) NOT NULL PRIMARY KEY,
  v MEDIUMTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaigns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  vertical VARCHAR(60) NOT NULL DEFAULT 'custom',
  keywords TEXT NOT NULL,
  locations TEXT NOT NULL,
  icp TEXT NOT NULL,
  offer TEXT NOT NULL,
  proof TEXT NULL,
  min_reviews INT NOT NULL DEFAULT 5,
  max_reviews INT NOT NULL DEFAULT 400,
  min_rating DECIMAL(2,1) NOT NULL DEFAULT 0.0,
  daily_new_leads INT NOT NULL DEFAULT 40,
  fit_threshold INT NOT NULL DEFAULT 60,
  approval_mode ENUM('manual','auto') NOT NULL DEFAULT 'manual',
  auto_min_score INT NOT NULL DEFAULT 80,
  followups INT NOT NULL DEFAULT 3,
  step_delays VARCHAR(60) NOT NULL DEFAULT '3,4,7',
  status ENUM('active','paused') NOT NULL DEFAULT 'paused',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS search_queries (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  campaign_id INT UNSIGNED NOT NULL,
  keyword VARCHAR(190) NOT NULL,
  location VARCHAR(190) NOT NULL,
  next_page_token TEXT NULL,
  pages_done INT NOT NULL DEFAULT 0,
  results INT NOT NULL DEFAULT 0,
  status ENUM('pending','done','error') NOT NULL DEFAULT 'pending',
  error VARCHAR(255) NULL,
  last_run_at DATETIME NULL,
  UNIQUE KEY uq_q (campaign_id, keyword(90), location(90)),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mailboxes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  label VARCHAR(120) NOT NULL,
  provider VARCHAR(30) NULL,
  from_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  smtp_host VARCHAR(190) NOT NULL DEFAULT 'smtp.gmail.com',
  smtp_port INT NOT NULL DEFAULT 587,
  smtp_secure VARCHAR(10) NOT NULL DEFAULT 'tls',
  imap_host VARCHAR(190) NOT NULL DEFAULT 'imap.gmail.com',
  imap_port INT NOT NULL DEFAULT 993,
  username VARCHAR(190) NOT NULL,
  password_enc TEXT NOT NULL,
  signature TEXT NULL,
  daily_limit INT NOT NULL DEFAULT 30,
  ramp_start INT NOT NULL DEFAULT 5,
  ramp_step INT NOT NULL DEFAULT 2,
  warmup_started DATE NULL,
  sent_today INT NOT NULL DEFAULT 0,
  sent_date DATE NULL,
  last_sent_at DATETIME NULL,
  next_send_after DATETIME NULL,
  last_imap_uid BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_imap_check DATETIME NULL,
  last_error VARCHAR(255) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS leads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  campaign_id INT UNSIGNED NOT NULL,
  place_id VARCHAR(190) NULL,
  business_name VARCHAR(255) NOT NULL,
  category VARCHAR(190) NULL,
  address VARCHAR(255) NULL,
  city VARCHAR(120) NULL,
  region VARCHAR(20) NULL,
  country CHAR(2) NULL,
  phone VARCHAR(40) NULL,
  website VARCHAR(255) NULL,
  domain VARCHAR(190) NULL,
  maps_url VARCHAR(255) NULL,
  rating DECIMAL(2,1) NULL,
  reviews INT NULL,
  timezone VARCHAR(60) NULL,
  search_location VARCHAR(190) NULL,
  email VARCHAR(190) NULL,
  email_source VARCHAR(255) NULL,
  email_found_at DATETIME NULL,
  contact_name VARCHAR(120) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'new',
  fit_score INT NULL,
  fit_reason TEXT NULL,
  research MEDIUMTEXT NULL,
  signals TEXT NULL,
  site_excerpt MEDIUMTEXT NULL,
  mailbox_id INT UNSIGNED NULL,
  current_step INT NOT NULL DEFAULT 0,
  last_sent_at DATETIME NULL,
  replied_at DATETIME NULL,
  reply_category VARCHAR(30) NULL,
  booked_at DATETIME NULL,
  unsubscribed_at DATETIME NULL,
  attempts INT NOT NULL DEFAULT 0,
  error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_place (tenant_id, place_id),
  KEY idx_tenant (tenant_id),
  KEY idx_status (status),
  KEY idx_campaign (campaign_id),
  KEY idx_email (email),
  KEY idx_domain (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS emails (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  lead_id INT UNSIGNED NOT NULL,
  step INT NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body TEXT NOT NULL,
  status ENUM('draft','approved','scheduled','sent','failed','cancelled') NOT NULL DEFAULT 'draft',
  mailbox_id INT UNSIGNED NULL,
  message_id VARCHAR(255) NULL,
  scheduled_for DATETIME NULL,
  sent_at DATETIME NULL,
  error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_lead_step (lead_id, step),
  KEY idx_status_sched (status, scheduled_for),
  KEY idx_msgid (message_id(120))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS replies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  lead_id INT UNSIGNED NULL,
  mailbox_id INT UNSIGNED NOT NULL,
  message_id VARCHAR(255) NOT NULL,
  from_email VARCHAR(190) NOT NULL,
  from_name VARCHAR(190) NULL,
  subject VARCHAR(255) NULL,
  body TEXT NULL,
  received_at DATETIME NOT NULL,
  kind ENUM('reply','bounce','auto') NOT NULL DEFAULT 'reply',
  category VARCHAR(30) NULL,
  summary VARCHAR(500) NULL,
  suggested_reply TEXT NULL,
  handled TINYINT(1) NOT NULL DEFAULT 0,
  sent_reply TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_msg (mailbox_id, message_id(150)),
  KEY idx_lead (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  lead_id INT UNSIGNED NULL,
  uid VARCHAR(190) NULL,
  trigger_event VARCHAR(60) NOT NULL,
  attendee_email VARCHAR(190) NULL,
  attendee_name VARCHAR(190) NULL,
  start_time DATETIME NULL,
  title VARCHAR(255) NULL,
  raw MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lead (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS suppression (
  tenant_id INT UNSIGNED NOT NULL,
  value VARCHAR(190) NOT NULL,
  type ENUM('email','domain') NOT NULL DEFAULT 'email',
  reason VARCHAR(60) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tenant_id, value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
  lead_id INT UNSIGNED NULL,
  type VARCHAR(40) NOT NULL,
  level ENUM('info','warn','error') NOT NULL DEFAULT 'info',
  detail VARCHAR(1000) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lead (lead_id),
  KEY idx_created (created_at),
  KEY idx_tenant (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_usage (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  day DATE NOT NULL,
  service VARCHAR(40) NOT NULL,
  tenant_id INT UNSIGNED NOT NULL DEFAULT 0,
  calls INT NOT NULL DEFAULT 0,
  tokens_in INT NOT NULL DEFAULT 0,
  tokens_out INT NOT NULL DEFAULT 0,
  cost_usd DECIMAL(10,4) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_day_service (day, service, tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  email VARCHAR(190) NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip (ip, created_at),
  KEY idx_email (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(190) NOT NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  plan VARCHAR(60) NOT NULL DEFAULT 'Starter',
  quota_leads INT NOT NULL DEFAULT 600,
  quota_ai_usd DECIMAL(8,2) NOT NULL DEFAULT 8.00,
  quota_mailboxes INT NOT NULL DEFAULT 3,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS memberships (
  user_id INT UNSIGNED NOT NULL,
  tenant_id INT UNSIGNED NOT NULL,
  role ENUM('owner','admin','member','viewer') NOT NULL DEFAULT 'member',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, tenant_id),
  KEY idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invitations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id INT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  role ENUM('owner','admin','member','viewer') NOT NULL DEFAULT 'member',
  token_hash CHAR(64) NOT NULL,
  invited_by INT UNSIGNED NULL,
  expires_at DATETIME NOT NULL,
  accepted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (token_hash),
  KEY idx_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tenant_settings (
  tenant_id INT UNSIGNED NOT NULL,
  k VARCHAR(80) NOT NULL,
  v MEDIUMTEXT NULL,
  PRIMARY KEY (tenant_id, k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- v5: developer error log, cron history, page timings
CREATE TABLE IF NOT EXISTS error_groups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, fingerprint CHAR(40) NOT NULL,
  level ENUM('notice','warning','error','critical') NOT NULL DEFAULT 'error', source VARCHAR(20) NOT NULL DEFAULT 'web',
  title VARCHAR(255) NOT NULL, message TEXT NULL, file VARCHAR(255) NULL, line INT NULL, trace MEDIUMTEXT NULL,
  count INT UNSIGNED NOT NULL DEFAULT 0, first_seen DATETIME NOT NULL, last_seen DATETIME NOT NULL, tenants VARCHAR(500) NULL,
  status ENUM('open','resolved','muted') NOT NULL DEFAULT 'open', muted_until DATETIME NULL, resolved_at DATETIME NULL, regressed TINYINT(1) NOT NULL DEFAULT 0,
  notify_pending TINYINT(1) NOT NULL DEFAULT 0, notify_reason VARCHAR(80) NULL, last_notified_at DATETIME NULL, notified_count INT UNSIGNED NOT NULL DEFAULT 0,
  note VARCHAR(500) NULL,
  UNIQUE KEY uq_fp (fingerprint), KEY idx_status_seen (status, last_seen), KEY idx_seen (last_seen), KEY idx_pending (notify_pending)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS error_samples (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, group_id INT UNSIGNED NOT NULL, at DATETIME NOT NULL,
  tenant_id INT UNSIGNED NULL, user_id INT UNSIGNED NULL, request_id CHAR(8) NOT NULL, url VARCHAR(500) NULL, context TEXT NULL,
  KEY idx_group (group_id, id), KEY idx_at (at), KEY idx_request (request_id), KEY idx_tenant (tenant_id, at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, started_at DATETIME NOT NULL, duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
  tenants SMALLINT UNSIGNED NOT NULL DEFAULT 0, jobs VARCHAR(120) NULL, errors SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  peak_mb DECIMAL(7,1) NULL, skipped TINYINT(1) NOT NULL DEFAULT 0, summary TEXT NULL, KEY idx_started (started_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS perf_daily (
  day DATE NOT NULL, path VARCHAR(80) NOT NULL, hits INT UNSIGNED NOT NULL DEFAULT 0, total_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
  max_ms INT UNSIGNED NOT NULL DEFAULT 0, slow_hits INT UNSIGNED NOT NULL DEFAULT 0, queries INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (day, path)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v5: composite indexes for many workspaces
ALTER TABLE `leads` ADD KEY `idx_t_status` (tenant_id, status, id);
ALTER TABLE `leads` ADD KEY `idx_t_created` (tenant_id, created_at);
ALTER TABLE `leads` ADD KEY `idx_t_updated` (tenant_id, updated_at);
ALTER TABLE `leads` ADD KEY `idx_t_status_upd` (tenant_id, status, updated_at);
ALTER TABLE `leads` ADD KEY `idx_t_domain` (tenant_id, domain);
ALTER TABLE `leads` ADD KEY `idx_t_email` (tenant_id, email);
ALTER TABLE `leads` ADD KEY `idx_c_status` (campaign_id, status);
ALTER TABLE `leads` ADD KEY `idx_t_camp_cover` (tenant_id, campaign_id, status, last_sent_at, replied_at, booked_at);
ALTER TABLE `leads` ADD KEY `idx_mb_status` (mailbox_id, status, updated_at);
ALTER TABLE `emails` ADD KEY `idx_t_status_sent` (tenant_id, status, sent_at);
ALTER TABLE `emails` ADD KEY `idx_t_status_sched` (tenant_id, status, scheduled_for);
ALTER TABLE `emails` ADD KEY `idx_mb_status_sent` (mailbox_id, status, sent_at);
ALTER TABLE `replies` ADD KEY `idx_t_kind_handled` (tenant_id, kind, handled);
ALTER TABLE `replies` ADD KEY `idx_t_kind_received` (tenant_id, kind, received_at);
ALTER TABLE `replies` ADD KEY `idx_mb_kind_received` (mailbox_id, kind, received_at);
ALTER TABLE `events` ADD KEY `idx_t_level` (tenant_id, level, id);
ALTER TABLE `search_queries` ADD KEY `idx_t_c_status` (tenant_id, campaign_id, status);
ALTER TABLE `bookings` ADD KEY `idx_t_start` (tenant_id, start_time);

-- v6: indexes for the Admin console's cross-workspace totals
ALTER TABLE `emails` ADD KEY `idx_status_sent_t` (status, sent_at, tenant_id);
ALTER TABLE `replies` ADD KEY `idx_kind_received_t` (kind, received_at, tenant_id);
ALTER TABLE `events` ADD KEY `idx_type_id` (type, id);

-- v7: one-time links that open a client workspace from the Admin console
CREATE TABLE IF NOT EXISTS login_handoffs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, token_hash CHAR(64) NOT NULL, user_id INT UNSIGNED NOT NULL, tenant_id INT UNSIGNED NOT NULL,
  next VARCHAR(120) NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_token (token_hash), KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `users` ADD COLUMN totp_last_step BIGINT NOT NULL DEFAULT 0;

-- v8: assisted LinkedIn outreach
CREATE TABLE IF NOT EXISTS li_tasks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id INT UNSIGNED NOT NULL, lead_id INT UNSIGNED NOT NULL,
  kind ENUM('connect','message','followup') NOT NULL, body TEXT NULL, due_at DATETIME NOT NULL,
  status ENUM('pending','done','skipped','cancelled') NOT NULL DEFAULT 'pending', outcome VARCHAR(40) NULL,
  done_by INT UNSIGNED NULL, done_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_t_status_due (tenant_id, status, due_at), KEY idx_lead (lead_id), KEY idx_t_done (tenant_id, done_by, done_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE `leads` ADD COLUMN linkedin_url VARCHAR(300) NULL, ADD COLUMN linkedin_kind ENUM('person','company') NULL,
  ADD COLUMN li_state VARCHAR(12) NOT NULL DEFAULT 'none', ADD COLUMN li_note TEXT NULL, ADD COLUMN li_msg1 TEXT NULL, ADD COLUMN li_msg2 TEXT NULL,
  ADD COLUMN li_requested_at DATETIME NULL, ADD COLUMN li_connected_at DATETIME NULL, ADD KEY idx_t_li_state (tenant_id, li_state);
ALTER TABLE `campaigns` ADD COLUMN linkedin_enabled TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN li_connect_after_days TINYINT UNSIGNED NOT NULL DEFAULT 1,
  ADD COLUMN li_followup_days TINYINT UNSIGNED NOT NULL DEFAULT 4;
ALTER TABLE `replies` ADD COLUMN channel VARCHAR(10) NOT NULL DEFAULT 'email';
