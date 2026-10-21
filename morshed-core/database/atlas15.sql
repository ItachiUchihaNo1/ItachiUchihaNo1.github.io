CREATE TABLE IF NOT EXISTS m_places (
 id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 parent_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
 kind VARCHAR(12) NOT NULL,name VARCHAR(180) NOT NULL,source_ref VARCHAR(180) NOT NULL,
 INDEX(parent_id,kind),FOREIGN KEY(parent_id) REFERENCES m_places(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_organization_profiles (
 org_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 description TEXT NOT NULL,org_type VARCHAR(24) NOT NULL,
 province_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
 county_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
 verified_at BIGINT NULL,verified_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 merged_into CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,updated_at BIGINT NOT NULL,
 FOREIGN KEY(org_id) REFERENCES m_organizations(id),FOREIGN KEY(province_id) REFERENCES m_places(id),
 FOREIGN KEY(county_id) REFERENCES m_places(id),FOREIGN KEY(merged_into) REFERENCES m_organizations(id),INDEX(province_id,county_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_organization_aliases (
 org_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,alias VARCHAR(180) NOT NULL,
 PRIMARY KEY(org_id,alias),INDEX(alias),FOREIGN KEY(org_id) REFERENCES m_organizations(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_experience_organizations (
 version_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 org_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 relation_type VARCHAR(24) NOT NULL,is_primary TINYINT NOT NULL DEFAULT 0,status VARCHAR(16) NOT NULL,
 raw_name VARCHAR(180) NOT NULL,internal_note TEXT NOT NULL,
 reviewed_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,reviewed_at BIGINT NULL,evidence VARCHAR(500) NOT NULL,
 PRIMARY KEY(version_id,org_id),INDEX(org_id,status,version_id),
 FOREIGN KEY(version_id) REFERENCES m_experience_versions(id),FOREIGN KEY(org_id) REFERENCES m_organizations(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_experience_places (
 version_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 province_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 county_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
 location_type VARCHAR(16) NOT NULL,is_primary TINYINT NOT NULL DEFAULT 0,status VARCHAR(16) NOT NULL,
 reviewed_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,reviewed_at BIGINT NULL,
 place_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(version_id,place_key,location_type),INDEX(province_id,county_id,status,version_id),
 FOREIGN KEY(version_id) REFERENCES m_experience_versions(id),FOREIGN KEY(province_id) REFERENCES m_places(id),FOREIGN KEY(county_id) REFERENCES m_places(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_experience_topics (
 version_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 term_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(version_id,term_id),INDEX(term_id,version_id),
 FOREIGN KEY(version_id) REFERENCES m_experience_versions(id),FOREIGN KEY(term_id) REFERENCES m_terms(id)
) ENGINE=InnoDB;
