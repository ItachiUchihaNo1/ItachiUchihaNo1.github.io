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
