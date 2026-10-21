CREATE TABLE IF NOT EXISTS m_term_metadata (
 term_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 short_label VARCHAR(100) NOT NULL DEFAULT '',
 description TEXT NOT NULL,
 icon_asset_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 display_order INT NOT NULL DEFAULT 0,
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL,
 FOREIGN KEY(term_id) REFERENCES m_terms(id),
 FOREIGN KEY(icon_asset_id) REFERENCES m_content_assets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS m_topic_paths (
 ancestor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 descendant_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 depth INT UNSIGNED NOT NULL,
 PRIMARY KEY(ancestor_id,descendant_id),
 INDEX(descendant_id,depth),
 FOREIGN KEY(ancestor_id) REFERENCES m_terms(id),
 FOREIGN KEY(descendant_id) REFERENCES m_terms(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_issue_topics (
 issue_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 topic_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(issue_id,topic_id),
 INDEX(topic_id,issue_id),
 FOREIGN KEY(issue_id) REFERENCES m_terms(id),
 FOREIGN KEY(topic_id) REFERENCES m_terms(id)
) ENGINE=InnoDB;
