-- SUMEE schema
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARBINARY(255) NOT NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at DATETIME(3) NOT NULL,
  email_verified_at DATETIME(3) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS email_verifications (
  id BIGINT UNSIGNED PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  code_hash VARBINARY(255) NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  attempts INT NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL,
  INDEX idx_email_verifications_user_expires (user_id, expires_at),
  INDEX idx_email_verifications_expires (expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sessions (
  id BIGINT UNSIGNED PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  refresh_token_hash VARBINARY(255) NOT NULL,
  created_at DATETIME(3) NOT NULL,
  last_seen_at DATETIME(3) NOT NULL,
  revoked_at DATETIME(3) NULL,
  device_id VARCHAR(128) NULL,
  client_facts_json JSON NULL,
  INDEX idx_sessions_user (user_id),
  INDEX idx_sessions_revoked (revoked_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_profiles (
  user_id BIGINT UNSIGNED PRIMARY KEY,
  username VARCHAR(32) NOT NULL UNIQUE,
  display_name VARCHAR(64) NULL,
  bio VARCHAR(256) NULL,
  avatar_url VARCHAR(512) NULL,
  banner_url VARCHAR(512) NULL,
  privacy_json JSON NULL,
  updated_at DATETIME(3) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_links (
  id BIGINT UNSIGNED PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  type VARCHAR(32) NOT NULL,
  url VARCHAR(512) NOT NULL,
  visibility ENUM('public','friends','private') NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL,
  INDEX idx_user_links_user_sort (user_id, sort_order)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS friend_requests (
  id BIGINT UNSIGNED PRIMARY KEY,
  from_user_id BIGINT UNSIGNED NOT NULL,
  to_user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','accepted','rejected','canceled') NOT NULL,
  created_at DATETIME(3) NOT NULL,
  responded_at DATETIME(3) NULL,
  UNIQUE KEY uniq_friend_req_pair (from_user_id, to_user_id),
  INDEX idx_friend_requests_to (to_user_id, status),
  INDEX idx_friend_requests_from (from_user_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS friendships (
  user_id BIGINT UNSIGNED NOT NULL,
  friend_user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(3) NOT NULL,
  PRIMARY KEY (user_id, friend_user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS blocks (
  blocker_id BIGINT UNSIGNED NOT NULL,
  blocked_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(3) NOT NULL,
  PRIMARY KEY (blocker_id, blocked_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS conversations (
  id BIGINT UNSIGNED PRIMARY KEY,
  type ENUM('dm','group') NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(3) NOT NULL,
  title VARCHAR(128) NULL,
  icon_url VARCHAR(512) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS conversation_members (
  conversation_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
  joined_at DATETIME(3) NOT NULL,
  last_read_message_id BIGINT UNSIGNED NULL,
  muted_until DATETIME(3) NULL,
  PRIMARY KEY (conversation_id, user_id),
  INDEX idx_conversation_members_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS messages (
  id BIGINT UNSIGNED PRIMARY KEY,
  conversation_id BIGINT UNSIGNED NOT NULL,
  sender_id BIGINT UNSIGNED NOT NULL,
  content TEXT NULL,
  content_type ENUM('text','json','markdown') NOT NULL DEFAULT 'text',
  created_at DATETIME(3) NOT NULL,
  edited_at DATETIME(3) NULL,
  deleted_at DATETIME(3) NULL,
  client_msg_id VARCHAR(64) NOT NULL,
  UNIQUE KEY uniq_message_client (sender_id, client_msg_id),
  INDEX idx_messages_conversation (conversation_id, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS message_attachments (
  id BIGINT UNSIGNED PRIMARY KEY,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  message_id BIGINT UNSIGNED NULL,
  storage_key VARCHAR(512) NOT NULL,
  cdn_url VARCHAR(512) NOT NULL,
  mime VARCHAR(128) NOT NULL,
  size BIGINT UNSIGNED NOT NULL,
  sha256 BINARY(32) NULL,
  status ENUM('pending','active') NOT NULL DEFAULT 'pending',
  created_at DATETIME(3) NOT NULL,
  deleted_at DATETIME(3) NULL,
  expires_at DATETIME(3) NULL,
  INDEX idx_message_attachments_message (message_id),
  INDEX idx_message_attachments_gc (expires_at, deleted_at),
  INDEX idx_message_attachments_owner (owner_user_id, status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rich_presence_sessions (
  id BIGINT UNSIGNED PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  activity_name VARCHAR(128) NOT NULL,
  started_at DATETIME(3) NOT NULL,
  ended_at DATETIME(3) NULL,
  metadata_json JSON NULL,
  visibility ENUM('public','friends','private') NOT NULL DEFAULT 'friends',
  INDEX idx_rich_presence_user_time (user_id, started_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reports (
  id BIGINT UNSIGNED PRIMARY KEY,
  reporter_id BIGINT UNSIGNED NOT NULL,
  target_type ENUM('user','message','conversation') NOT NULL,
  target_id BIGINT UNSIGNED NOT NULL,
  category VARCHAR(64) NOT NULL,
  description VARCHAR(2000) NULL,
  status ENUM('open','triaged','action_taken','dismissed') NOT NULL DEFAULT 'open',
  created_at DATETIME(3) NOT NULL,
  INDEX idx_reports_status_created (status, created_at),
  INDEX idx_reports_reporter_created (reporter_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS report_evidence (
  id BIGINT UNSIGNED PRIMARY KEY,
  report_id BIGINT UNSIGNED NOT NULL,
  type ENUM('message_id','url','text') NOT NULL,
  value VARCHAR(1024) NOT NULL,
  created_at DATETIME(3) NOT NULL,
  INDEX idx_report_evidence_report (report_id)
) ENGINE=InnoDB;
