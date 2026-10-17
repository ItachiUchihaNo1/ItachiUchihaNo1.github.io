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
