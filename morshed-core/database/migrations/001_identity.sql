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
