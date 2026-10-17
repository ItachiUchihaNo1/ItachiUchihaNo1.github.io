SET NAMES utf8mb4;
ALTER TABLE m_notifications ADD category VARCHAR(16) NOT NULL DEFAULT 'account', ADD destination VARCHAR(240) NULL;
ALTER TABLE m_schedule_settings ADD acceptance_paused TINYINT NOT NULL DEFAULT 0, ADD access_scope VARCHAR(16) NOT NULL DEFAULT 'public',
 ADD CHECK(acceptance_paused IN (0,1)), ADD CHECK(access_scope IN ('public','organization'));
INSERT INTO m_schema_migrations(version,description) VALUES(5,'Notification destinations and mentor acceptance scope');
