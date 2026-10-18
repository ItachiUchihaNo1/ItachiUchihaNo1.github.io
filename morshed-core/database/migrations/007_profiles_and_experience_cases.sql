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
