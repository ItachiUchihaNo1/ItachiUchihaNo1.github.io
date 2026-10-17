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
