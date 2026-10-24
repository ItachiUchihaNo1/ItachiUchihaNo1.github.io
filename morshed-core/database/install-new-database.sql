-- ONLY for a new empty database. Installed systems use the authenticated upgrade action.

-- Morshed identity foundation. Import ONLY into a new, empty staging database.
-- MySQL 8.0 target; all tables use InnoDB / utf8mb4 explicitly.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE m_schema_migrations (
 version INT PRIMARY KEY, description VARCHAR(180) NOT NULL,
 installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE m_installation (
 id TINYINT PRIMARY KEY, bootstrapped_at BIGINT UNSIGNED NULL,
 CONSTRAINT chk_install_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO m_installation(id) VALUES (1);

CREATE TABLE m_users (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 phone VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
 phone_verified_at BIGINT UNSIGNED NULL,
 display_name VARCHAR(120) NOT NULL DEFAULT '',
 password_hash VARCHAR(255) CHARACTER SET ascii NULL,
 status VARCHAR(16) CHARACTER SET ascii NOT NULL DEFAULT 'active',
 created_at BIGINT UNSIGNED NOT NULL,
 updated_at BIGINT UNSIGNED NOT NULL,
 CONSTRAINT chk_user_status CHECK (status IN ('active','blocked')),
 CONSTRAINT chk_phone_proof CHECK ((phone IS NULL AND phone_verified_at IS NULL) OR (phone IS NOT NULL AND phone_verified_at IS NOT NULL)),
 INDEX idx_users_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE m_admin_accounts (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 username VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 password_hash VARCHAR(255) CHARACTER SET ascii NOT NULL,
 role VARCHAR(24) CHARACTER SET ascii NOT NULL,
 created_at BIGINT UNSIGNED NOT NULL,
 CONSTRAINT fk_admin_user FOREIGN KEY(user_id) REFERENCES m_users(id),
 CONSTRAINT chk_admin_role CHECK (role IN ('system_admin','content_admin'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE m_auth_identities (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 provider VARCHAR(24) CHARACTER SET ascii NOT NULL,
 subject VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at BIGINT UNSIGNED NOT NULL,
 UNIQUE KEY uq_external_identity(provider,subject),
 UNIQUE KEY uq_user_provider(user_id,provider),
 FOREIGN KEY(user_id) REFERENCES m_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE m_auth_sessions (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 admin_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 realm VARCHAR(8) CHARACTER SET ascii NOT NULL,
 token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 auth_method VARCHAR(24) CHARACTER SET ascii NOT NULL,
 auth_at BIGINT UNSIGNED NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
 expires_at BIGINT UNSIGNED NOT NULL, revoked_at BIGINT UNSIGNED NULL,
 agent_label VARCHAR(180) NOT NULL DEFAULT '',
 FOREIGN KEY(user_id) REFERENCES m_users(id),
 FOREIGN KEY(admin_id) REFERENCES m_admin_accounts(id),
 CONSTRAINT chk_session_realm CHECK ((realm='site' AND admin_id IS NULL) OR (realm='admin' AND admin_id IS NOT NULL)),
 INDEX idx_sessions_owner(user_id,realm,expires_at), INDEX idx_sessions_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE m_otp_challenges (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 phone VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 purpose VARCHAR(16) CHARACTER SET ascii NOT NULL,
 actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 actor_realm VARCHAR(8) CHARACTER SET ascii NULL,
 target_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 secret_hmac CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 created_at BIGINT UNSIGNED NOT NULL, expires_at BIGINT UNSIGNED NOT NULL,
 consumed_at BIGINT UNSIGNED NULL,
 submission_status VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT 'preparing',
 FOREIGN KEY(actor_user_id) REFERENCES m_users(id),
 FOREIGN KEY(target_user_id) REFERENCES m_users(id),
 CONSTRAINT chk_otp_purpose CHECK (purpose IN ('login','reset','change_phone')),
 CONSTRAINT chk_otp_actor CHECK ((purpose='change_phone' AND actor_user_id IS NOT NULL AND actor_realm IN ('site','admin')) OR (purpose IN ('login','reset') AND actor_user_id IS NULL AND actor_realm IS NULL)),
 INDEX idx_otp_phone(phone,purpose,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE m_rate_limits (
 key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 bucket BIGINT UNSIGNED NOT NULL, attempts INT UNSIGNED NOT NULL,
 expires_at BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(key_hash,bucket), INDEX idx_rate_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE m_legal_versions (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 document_key VARCHAR(16) CHARACTER SET ascii NOT NULL,
 version_no INT UNSIGNED NOT NULL,
 title VARCHAR(180) NOT NULL, body MEDIUMTEXT NOT NULL,
 content_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(created_by) REFERENCES m_users(id),
 CONSTRAINT chk_legal_key CHECK(document_key IN ('base','recording')),
 UNIQUE KEY uq_legal_version(document_key,version_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_legal_publications (
 document_key VARCHAR(16) CHARACTER SET ascii PRIMARY KEY,
 version_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 published_at BIGINT UNSIGNED NULL,
 FOREIGN KEY(version_id) REFERENCES m_legal_versions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO m_legal_publications(document_key) VALUES ('base'),('recording');
CREATE TABLE m_user_acceptances (
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 accepted_at BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(user_id,version_id),
 FOREIGN KEY(user_id) REFERENCES m_users(id), FOREIGN KEY(version_id) REFERENCES m_legal_versions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE m_notifications (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 title VARCHAR(180) NOT NULL, body TEXT NOT NULL,
 created_at BIGINT UNSIGNED NOT NULL, read_at BIGINT UNSIGNED NULL,
 FOREIGN KEY(user_id) REFERENCES m_users(id), INDEX idx_notifications_user(user_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 realm VARCHAR(8) CHARACTER SET ascii NOT NULL,
 event VARCHAR(80) CHARACTER SET ascii NOT NULL,
 subject_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(actor_user_id) REFERENCES m_users(id),
 INDEX idx_audit_created(created_at), INDEX idx_audit_subject(subject_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO m_schema_migrations(version,description) VALUES (1,'Identity, separate realms, legal versions and audit');


-- Apply after 001. Never re-import 001 into an installed database.
SET NAMES utf8mb4;
CREATE TABLE m_terms (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 kind VARCHAR(12) NOT NULL, title VARCHAR(160) NOT NULL, parent_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 active TINYINT NOT NULL DEFAULT 1, FOREIGN KEY(parent_id) REFERENCES m_terms(id),
 CHECK(kind IN ('topic','issue')), UNIQUE KEY uq_term(kind,title), INDEX(active,kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_mentors (
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 headline VARCHAR(180) NOT NULL, bio TEXT NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'invited',
 invited_at BIGINT UNSIGNED NOT NULL, accepted_at BIGINT UNSIGNED NULL, updated_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(user_id) REFERENCES m_users(id), CHECK(status IN ('invited','review','active','restricted')), INDEX(status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_mentor_terms (
 mentor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, term_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(mentor_id,term_id), FOREIGN KEY(mentor_id) REFERENCES m_mentors(user_id), FOREIGN KEY(term_id) REFERENCES m_terms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_experiences (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, mentor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 public_version_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(mentor_id) REFERENCES m_mentors(user_id), INDEX(mentor_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_experience_versions (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, experience_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version_no INT NOT NULL, title VARCHAR(180) NOT NULL, body MEDIUMTEXT NOT NULL,
 term_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, status VARCHAR(16) NOT NULL DEFAULT 'draft',
 review_note TEXT NOT NULL, created_at BIGINT UNSIGNED NOT NULL, reviewed_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 reviewed_at BIGINT UNSIGNED NULL, FOREIGN KEY(experience_id) REFERENCES m_experiences(id), FOREIGN KEY(term_id) REFERENCES m_terms(id),
 FOREIGN KEY(reviewed_by) REFERENCES m_users(id), UNIQUE KEY uq_exp_version(experience_id,version_no),
 CHECK(status IN ('draft','submitted','changes','approved','rejected')), INDEX(status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE m_experiences ADD FOREIGN KEY(public_version_id) REFERENCES m_experience_versions(id);
CREATE TABLE m_store_versions (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, version_no INT NOT NULL UNIQUE,
 blocks_json MEDIUMTEXT NOT NULL, created_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(created_by) REFERENCES m_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_store_publication (
 id TINYINT PRIMARY KEY, version_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, published_at BIGINT UNSIGNED NULL,
 FOREIGN KEY(version_id) REFERENCES m_store_versions(id), CHECK(id=1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO m_store_publication(id) VALUES(1);
CREATE TABLE m_schedule_settings (
 mentor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 duration_minutes SMALLINT NOT NULL DEFAULT 30, buffer_minutes SMALLINT NOT NULL DEFAULT 10,
 lead_hours SMALLINT NOT NULL DEFAULT 24, daily_capacity SMALLINT NOT NULL DEFAULT 4,
 auto_accept TINYINT NOT NULL DEFAULT 0, timezone VARCHAR(50) NOT NULL DEFAULT 'Asia/Tehran',
 FOREIGN KEY(mentor_id) REFERENCES m_mentors(user_id), CHECK(duration_minutes BETWEEN 15 AND 120),
 CHECK(buffer_minutes BETWEEN 0 AND 120), CHECK(lead_hours BETWEEN 1 AND 720), CHECK(daily_capacity BETWEEN 1 AND 30)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_schedule_rules (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, mentor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 weekday TINYINT NOT NULL, start_minute SMALLINT NOT NULL, end_minute SMALLINT NOT NULL,
 FOREIGN KEY(mentor_id) REFERENCES m_mentors(user_id), CHECK(weekday BETWEEN 0 AND 6),
 CHECK(start_minute>=0 AND end_minute<=1440 AND end_minute>start_minute), INDEX(mentor_id,weekday)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_schedule_exceptions (
 mentor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, local_date DATE NOT NULL,
 PRIMARY KEY(mentor_id,local_date), FOREIGN KEY(mentor_id) REFERENCES m_mentors(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_bookings (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, mentor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, starts_at BIGINT UNSIGNED NOT NULL, ends_at BIGINT UNSIGNED NOT NULL,
 buffer_minutes SMALLINT NOT NULL, local_date DATE NOT NULL, state VARCHAR(16) NOT NULL, mode VARCHAR(12) NOT NULL DEFAULT 'text',
 problem TEXT NOT NULL, idempotency_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 expires_at BIGINT UNSIGNED NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(mentor_id) REFERENCES m_mentors(user_id), FOREIGN KEY(user_id) REFERENCES m_users(id),
 UNIQUE KEY uq_booking_request(user_id,idempotency_key), INDEX(mentor_id,starts_at,state), INDEX(user_id,starts_at), INDEX idx_booking_expiry(state,expires_at),
 CHECK(state IN ('pending','confirmed','rejected','expired','cancelled','completed')), CHECK(ends_at>starts_at), CHECK(mode='text')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_booking_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, booking_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, event VARCHAR(40) NOT NULL, note TEXT NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(booking_id) REFERENCES m_bookings(id), FOREIGN KEY(actor_id) REFERENCES m_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_time_proposals (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, booking_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 proposer_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, starts_at BIGINT UNSIGNED NOT NULL,
 state VARCHAR(12) NOT NULL DEFAULT 'pending', expires_at BIGINT UNSIGNED NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(booking_id) REFERENCES m_bookings(id), FOREIGN KEY(proposer_id) REFERENCES m_users(id),
 CHECK(state IN ('pending','accepted','rejected','expired')), INDEX(booking_id,state), INDEX idx_proposal_expiry(state,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, booking_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 sender_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, client_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 body TEXT NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(booking_id) REFERENCES m_bookings(id), FOREIGN KEY(sender_id) REFERENCES m_users(id),
 UNIQUE KEY uq_message_retry(booking_id,sender_id,client_key), INDEX(booking_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_tickets (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 booking_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, kind VARCHAR(12) NOT NULL, title VARCHAR(180) NOT NULL,
 state VARCHAR(12) NOT NULL DEFAULT 'open', created_at BIGINT UNSIGNED NOT NULL, updated_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(user_id) REFERENCES m_users(id), FOREIGN KEY(booking_id) REFERENCES m_bookings(id),
 CHECK(kind IN ('support','complaint')), CHECK(state IN ('open','closed')), INDEX(state,updated_at), INDEX(user_id,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_ticket_messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ticket_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 author_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, body TEXT NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(ticket_id) REFERENCES m_tickets(id), FOREIGN KEY(author_id) REFERENCES m_users(id), INDEX(ticket_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_feedback (
 booking_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 score TINYINT NOT NULL, body TEXT NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(booking_id) REFERENCES m_bookings(id), FOREIGN KEY(user_id) REFERENCES m_users(id), CHECK(score BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_archive_grants (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, ticket_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 booking_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, reviewer_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 approved_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, purpose VARCHAR(500) NOT NULL,
 expires_at BIGINT UNSIGNED NOT NULL, revoked_at BIGINT UNSIGNED NULL, created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(ticket_id) REFERENCES m_tickets(id), FOREIGN KEY(booking_id) REFERENCES m_bookings(id),
 FOREIGN KEY(reviewer_id) REFERENCES m_users(id), FOREIGN KEY(approved_by) REFERENCES m_users(id), INDEX(booking_id,reviewer_id,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_organizations (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, name VARCHAR(180) NOT NULL, active TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_memberships (
 org_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(org_id,user_id), FOREIGN KEY(org_id) REFERENCES m_organizations(id), FOREIGN KEY(user_id) REFERENCES m_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO m_schema_migrations(version,description) VALUES(2,'Content, booking, durable text sessions and operations');


SET NAMES utf8mb4;
CREATE TABLE m_external_flows (
 state_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 provider VARCHAR(16) NOT NULL, purpose VARCHAR(12) NOT NULL,
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 session_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 browser_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 nonce VARCHAR(128) CHARACTER SET ascii NOT NULL, verifier VARCHAR(128) CHARACTER SET ascii NOT NULL,
 created_at BIGINT UNSIGNED NOT NULL, expires_at BIGINT UNSIGNED NOT NULL, consumed_at BIGINT UNSIGNED NULL,
 FOREIGN KEY(user_id) REFERENCES m_users(id), FOREIGN KEY(session_id) REFERENCES m_auth_sessions(id),
 CHECK(provider IN ('google','eitaa')), CHECK(purpose IN ('login','link','reauth')),
 CHECK((purpose='login' AND user_id IS NULL AND session_id IS NULL) OR (purpose IN ('link','reauth') AND user_id IS NOT NULL AND session_id IS NOT NULL)), INDEX(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_identity_assertions (
 assertion_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 provider VARCHAR(16) NOT NULL, expires_at BIGINT UNSIGNED NOT NULL, consumed_at BIGINT UNSIGNED NOT NULL,
 INDEX(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO m_schema_migrations(version,description) VALUES(3,'External identity state and assertion replay protection');


SET NAMES utf8mb4;
CREATE TABLE m_private_files (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, booking_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 owner_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, storage_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 display_name VARCHAR(180) NOT NULL, media_type VARCHAR(80) CHARACTER SET ascii NOT NULL, byte_size INT UNSIGNED NOT NULL,
 sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(booking_id) REFERENCES m_bookings(id), FOREIGN KEY(owner_id) REFERENCES m_users(id), INDEX(booking_id,created_at),
 CHECK(byte_size BETWEEN 1 AND 5242880)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_notification_templates (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, name VARCHAR(120) NOT NULL,
 title VARCHAR(180) NOT NULL, body TEXT NOT NULL, created_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at BIGINT UNSIGNED NOT NULL, FOREIGN KEY(created_by) REFERENCES m_users(id), INDEX(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_notification_deliveries (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, template_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, notification_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 request_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, channel VARCHAR(12) NOT NULL DEFAULT 'in_app',
 state VARCHAR(16) NOT NULL DEFAULT 'stored', created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(template_id) REFERENCES m_notification_templates(id), FOREIGN KEY(user_id) REFERENCES m_users(id),
 FOREIGN KEY(notification_id) REFERENCES m_notifications(id), UNIQUE(user_id,request_key), CHECK(channel='in_app'), CHECK(state='stored')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_public_settings (
 setting_key VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, setting_value TEXT NOT NULL,
 updated_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, updated_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(updated_by) REFERENCES m_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO m_schema_migrations(version,description) VALUES(4,'Private attachments, notification templates and public settings');


SET NAMES utf8mb4;
ALTER TABLE m_notifications ADD category VARCHAR(16) NOT NULL DEFAULT 'account', ADD destination VARCHAR(240) NULL;
ALTER TABLE m_schedule_settings ADD acceptance_paused TINYINT NOT NULL DEFAULT 0, ADD access_scope VARCHAR(16) NOT NULL DEFAULT 'public',
 ADD CHECK(acceptance_paused IN (0,1)), ADD CHECK(access_scope IN ('public','organization'));
INSERT INTO m_schema_migrations(version,description) VALUES(5,'Notification destinations and mentor acceptance scope');


SET NAMES utf8mb4;
CREATE TABLE m_content_assets (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, owner_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 storage_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE, display_name VARCHAR(180) NOT NULL,
 media_type VARCHAR(80) NOT NULL, byte_size INT UNSIGNED NOT NULL, sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at BIGINT UNSIGNED NOT NULL, FOREIGN KEY(owner_id) REFERENCES m_users(id), INDEX(owner_id,created_at), CHECK(byte_size BETWEEN 1 AND 5242880)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE m_mentors ADD avatar_asset_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, ADD FOREIGN KEY(avatar_asset_id) REFERENCES m_content_assets(id);
CREATE TABLE m_experience_assets (
 version_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, asset_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(version_id,asset_id), FOREIGN KEY(version_id) REFERENCES m_experience_versions(id), FOREIGN KEY(asset_id) REFERENCES m_content_assets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO m_schema_migrations(version,description) VALUES(6,'Reviewed mentor portraits and version-bound public evidence');


-- Additive update from schema 6. Do not reimport earlier migrations.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS m_user_profiles (
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 first_name VARCHAR(60) NOT NULL DEFAULT '',last_name VARCHAR(60) NOT NULL DEFAULT '',
 province VARCHAR(60) NOT NULL DEFAULT '',city VARCHAR(80) NOT NULL DEFAULT '',gender VARCHAR(16) NOT NULL DEFAULT '',
 completed_at BIGINT UNSIGNED NULL,deleted_at BIGINT UNSIGNED NULL,
 FOREIGN KEY(user_id) REFERENCES m_users(id), INDEX(deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS m_experience_details (
 version_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 summary TEXT NOT NULL,
 role TEXT NOT NULL,
 activity_area TEXT NOT NULL,
 period TEXT NOT NULL,
 setting TEXT NOT NULL,
 problem TEXT NOT NULL,
 context TEXT NOT NULL,
 decisions TEXT NOT NULL,
 actions TEXT NOT NULL,
 challenges TEXT NOT NULL,
 results TEXT NOT NULL,
 lessons TEXT NOT NULL,
 problem_statement TEXT NOT NULL,
 synonyms TEXT NOT NULL,
 target_audience TEXT NOT NULL,
 scale TEXT NOT NULL,
 constraints TEXT NOT NULL,
 applicability TEXT NOT NULL,
 consultation_topics TEXT NOT NULL,
 source_text TEXT NOT NULL,
 claim_sources TEXT NOT NULL,
 unanswered TEXT NOT NULL,
 confidential_notes TEXT NOT NULL,
 outcome VARCHAR(16) NOT NULL DEFAULT 'unknown',visibility VARCHAR(16) NOT NULL DEFAULT 'private',
 method VARCHAR(16) NOT NULL DEFAULT 'manual',quality_level VARCHAR(16) NOT NULL DEFAULT 'incomplete',
 owner_approved_at BIGINT UNSIGNED NULL, updated_at BIGINT UNSIGNED NOT NULL, updated_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 FOREIGN KEY(version_id) REFERENCES m_experience_versions(id),FOREIGN KEY(updated_by) REFERENCES m_users(id),
 CHECK(outcome IN ('success','failure','mixed','unknown')),CHECK(visibility IN ('private','public'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS m_store_drafts (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 created_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
 blocks_json MEDIUMTEXT NOT NULL,updated_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(created_by) REFERENCES m_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO m_schema_migrations(version,description) VALUES(7,'Account profiles, structured experience cases and reusable storefront drafts');

CREATE TABLE IF NOT EXISTS m_experience_leads (id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,title VARCHAR(240) NOT NULL,summary TEXT NOT NULL,state VARCHAR(16) NOT NULL DEFAULT 'open',created_at BIGINT NOT NULL,updated_at BIGINT NOT NULL,INDEX(user_id,created_at),INDEX(state,created_at),FOREIGN KEY(user_id) REFERENCES m_users(id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_organization_details (org_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,logo_asset_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,website VARCHAR(1000) NOT NULL DEFAULT '',social_urls TEXT NOT NULL,representative_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,representative_role VARCHAR(180) NOT NULL DEFAULT '',updated_at BIGINT NOT NULL,FOREIGN KEY(org_id) REFERENCES m_organizations(id),FOREIGN KEY(representative_id) REFERENCES m_users(id)) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS m_eitaa_deliveries (notification_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,state VARCHAR(20) NOT NULL,created_at BIGINT NOT NULL,updated_at BIGINT NOT NULL,INDEX(state,created_at),FOREIGN KEY(notification_id) REFERENCES m_notifications(id)) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS m_atlas_visibility (
 entity_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 entity_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 approved TINYINT NOT NULL DEFAULT 1,
 reviewed_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reviewed_at BIGINT NOT NULL,
 PRIMARY KEY(entity_kind,entity_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS m_message_permissions (
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 eitaa_allowed TINYINT NOT NULL DEFAULT 0,
 source VARCHAR(24) NOT NULL DEFAULT 'client_sdk',
 updated_at BIGINT NOT NULL,
 FOREIGN KEY(user_id) REFERENCES m_users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_sms_deliveries (
 notification_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 state VARCHAR(32) NOT NULL,
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL,
 INDEX(state,created_at),
 FOREIGN KEY(notification_id) REFERENCES m_notifications(id)
) ENGINE=InnoDB;

INSERT IGNORE INTO m_notifications(id,user_id,title,body,created_at,destination,category)
SELECT LEFT(SHA2(CONCAT('eitaa-permission24:',u.id),256),32),u.id,'دریافت پیام‌های مرشد در ایتا','برای دریافت نتیجهٔ درخواست مشاوره و پیام‌های مرتبط، از همین صفحه داخل برنامک ایتا اجازهٔ دریافت پیام را انتخاب کنید.',UNIX_TIMESTAMP(),'/notifications','system'
FROM m_users u JOIN m_auth_identities i ON i.user_id=u.id AND i.provider='eitaa'
LEFT JOIN m_message_permissions p ON p.user_id=u.id
WHERE u.status='active' AND p.user_id IS NULL;
