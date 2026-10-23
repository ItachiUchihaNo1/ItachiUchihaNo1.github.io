CREATE TABLE IF NOT EXISTS m_experience_leads (id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,title VARCHAR(240) NOT NULL,summary TEXT NOT NULL,state VARCHAR(16) NOT NULL DEFAULT 'open',created_at BIGINT NOT NULL,updated_at BIGINT NOT NULL,INDEX(user_id,created_at),INDEX(state,created_at),FOREIGN KEY(user_id) REFERENCES m_users(id)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_organization_details (org_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,logo_asset_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,website VARCHAR(1000) NOT NULL DEFAULT '',social_urls TEXT NOT NULL,representative_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,representative_role VARCHAR(180) NOT NULL DEFAULT '',updated_at BIGINT NOT NULL,FOREIGN KEY(org_id) REFERENCES m_organizations(id),FOREIGN KEY(representative_id) REFERENCES m_users(id)) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS m_atlas_visibility (
 entity_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 entity_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 approved TINYINT NOT NULL DEFAULT 1,
 reviewed_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 reviewed_at BIGINT NOT NULL,
 PRIMARY KEY(entity_kind,entity_id)
) ENGINE=InnoDB;
