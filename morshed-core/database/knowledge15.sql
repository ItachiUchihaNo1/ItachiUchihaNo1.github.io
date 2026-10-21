CREATE TABLE IF NOT EXISTS m_knowledge_entities (
 entity_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 source_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at BIGINT NOT NULL,PRIMARY KEY(entity_type,source_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_knowledge_relations (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 relation_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 from_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 from_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 to_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 to_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 origin VARCHAR(16) NOT NULL,status VARCHAR(16) NOT NULL,visibility VARCHAR(16) NOT NULL,
 evidence VARCHAR(500) NOT NULL,reason VARCHAR(1000) NOT NULL,confidence DECIMAL(5,4) NULL,
 created_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,created_at BIGINT NOT NULL,
 reviewed_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,reviewed_at BIGINT NULL,
 revision INT NOT NULL DEFAULT 1,
 UNIQUE(relation_type,from_type,from_id,to_type,to_id),INDEX(status,visibility,relation_type)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_knowledge_history (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 subject_id VARCHAR(80) NOT NULL,actor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 action VARCHAR(40) NOT NULL,before_json MEDIUMTEXT NOT NULL,after_json MEDIUMTEXT NOT NULL,
 created_at BIGINT NOT NULL,INDEX(subject_id,created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_term_aliases (
 term_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,alias VARCHAR(160) NOT NULL,
 PRIMARY KEY(term_id,alias),INDEX(alias),FOREIGN KEY(term_id) REFERENCES m_terms(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_term_redirects (
 source_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 target_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 merged_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,merged_at BIGINT NOT NULL,
 FOREIGN KEY(source_id) REFERENCES m_terms(id),FOREIGN KEY(target_id) REFERENCES m_terms(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_interaction_events (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 event_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 object_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 object_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 properties_json VARCHAR(2000) NOT NULL,idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 created_at BIGINT NOT NULL,UNIQUE(idempotency_key),INDEX(created_at),INDEX(event_type,created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_interaction_policy (
 id TINYINT PRIMARY KEY,enabled TINYINT NOT NULL DEFAULT 0,retention_days INT NULL,
 approved_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,approved_at BIGINT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO m_interaction_policy(id,enabled) VALUES(1,0);
