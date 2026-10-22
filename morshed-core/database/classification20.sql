CREATE TABLE IF NOT EXISTS m_org_types (
 id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 title VARCHAR(160) NOT NULL,description TEXT NOT NULL,active TINYINT NOT NULL DEFAULT 1,display_order INT NOT NULL DEFAULT 0,
 UNIQUE(title)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_organization_type_links (
 org_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 type_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 FOREIGN KEY(org_id) REFERENCES m_organizations(id),FOREIGN KEY(type_id) REFERENCES m_org_types(id),INDEX(type_id,org_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_audiences (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 title VARCHAR(160) NOT NULL,family VARCHAR(16) NOT NULL,description TEXT NOT NULL,active TINYINT NOT NULL DEFAULT 1,display_order INT NOT NULL DEFAULT 0,
 UNIQUE(family,title)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_experience_audiences (
 version_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 audience_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(version_id,audience_id),INDEX(audience_id,version_id),
 FOREIGN KEY(version_id) REFERENCES m_experience_versions(id),FOREIGN KEY(audience_id) REFERENCES m_audiences(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_experience_problems (
 version_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 title VARCHAR(300) NOT NULL,description TEXT NOT NULL,display_order INT NOT NULL DEFAULT 0,
 PRIMARY KEY(version_id,id),FOREIGN KEY(version_id) REFERENCES m_experience_versions(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_problem_tags (
 version_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 problem_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 term_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,is_primary TINYINT NOT NULL DEFAULT 0,
 PRIMARY KEY(version_id,problem_id,term_id),INDEX(term_id,version_id),
 FOREIGN KEY(version_id,problem_id) REFERENCES m_experience_problems(version_id,id),FOREIGN KEY(term_id) REFERENCES m_terms(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_experience_context (
 version_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 org_type_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,independent TINYINT NOT NULL DEFAULT 0,
 FOREIGN KEY(version_id) REFERENCES m_experience_versions(id),FOREIGN KEY(org_type_id) REFERENCES m_org_types(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_org_proposals (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 version_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 type_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,name VARCHAR(180) NOT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'proposed',resolved_org_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 created_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,created_at BIGINT NOT NULL,reviewed_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,reviewed_at BIGINT NULL,
 FOREIGN KEY(version_id) REFERENCES m_experience_versions(id),FOREIGN KEY(type_id) REFERENCES m_org_types(id),FOREIGN KEY(resolved_org_id) REFERENCES m_organizations(id),
 INDEX(status,created_at),UNIQUE(version_id,type_id,name)
) ENGINE=InnoDB;
