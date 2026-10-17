-- Apply after 001. Never re-import 001 into an installed database.
SET NAMES utf8mb4;
CREATE TABLE m_terms (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 kind VARCHAR(12) NOT NULL, title VARCHAR(160) NOT NULL, parent_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 active TINYINT NOT NULL DEFAULT 1, FOREIGN KEY(parent_id) REFERENCES m_terms(id),
 CHECK(kind IN ('topic','issue')), UNIQUE KEY uq_term(kind,title), INDEX(active,kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_mentors (
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 headline VARCHAR(180) NOT NULL, bio TEXT NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'invited',
 invited_at BIGINT UNSIGNED NOT NULL, accepted_at BIGINT UNSIGNED NULL, updated_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(user_id) REFERENCES m_users(id), CHECK(status IN ('invited','review','active','restricted')), INDEX(status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_mentor_terms (
 mentor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, term_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(mentor_id,term_id), FOREIGN KEY(mentor_id) REFERENCES m_mentors(user_id), FOREIGN KEY(term_id) REFERENCES m_terms(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_experiences (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, mentor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 public_version_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(mentor_id) REFERENCES m_mentors(user_id), INDEX(mentor_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_experience_versions (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, experience_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 version_no INT NOT NULL, title VARCHAR(180) NOT NULL, body MEDIUMTEXT NOT NULL,
 term_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, status VARCHAR(16) NOT NULL DEFAULT 'draft',
 review_note TEXT NOT NULL, created_at BIGINT UNSIGNED NOT NULL, reviewed_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 reviewed_at BIGINT UNSIGNED NULL, FOREIGN KEY(experience_id) REFERENCES m_experiences(id), FOREIGN KEY(term_id) REFERENCES m_terms(id),
 FOREIGN KEY(reviewed_by) REFERENCES m_users(id), UNIQUE KEY uq_exp_version(experience_id,version_no),
 CHECK(status IN ('draft','submitted','changes','approved','rejected')), INDEX(status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE m_experiences ADD FOREIGN KEY(public_version_id) REFERENCES m_experience_versions(id);
CREATE TABLE m_store_versions (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, version_no INT NOT NULL UNIQUE,
 blocks_json MEDIUMTEXT NOT NULL, created_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(created_by) REFERENCES m_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_store_publication (
 id TINYINT PRIMARY KEY, version_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, published_at BIGINT UNSIGNED NULL,
 FOREIGN KEY(version_id) REFERENCES m_store_versions(id), CHECK(id=1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO m_store_publication(id) VALUES(1);
CREATE TABLE m_schedule_settings (
 mentor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 duration_minutes SMALLINT NOT NULL DEFAULT 30, buffer_minutes SMALLINT NOT NULL DEFAULT 10,
 lead_hours SMALLINT NOT NULL DEFAULT 24, daily_capacity SMALLINT NOT NULL DEFAULT 4,
 auto_accept TINYINT NOT NULL DEFAULT 0, timezone VARCHAR(50) NOT NULL DEFAULT 'Asia/Tehran',
 FOREIGN KEY(mentor_id) REFERENCES m_mentors(user_id), CHECK(duration_minutes BETWEEN 15 AND 120),
 CHECK(buffer_minutes BETWEEN 0 AND 120), CHECK(lead_hours BETWEEN 1 AND 720), CHECK(daily_capacity BETWEEN 1 AND 30)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_schedule_rules (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, mentor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 weekday TINYINT NOT NULL, start_minute SMALLINT NOT NULL, end_minute SMALLINT NOT NULL,
 FOREIGN KEY(mentor_id) REFERENCES m_mentors(user_id), CHECK(weekday BETWEEN 0 AND 6),
 CHECK(start_minute>=0 AND end_minute<=1440 AND end_minute>start_minute), INDEX(mentor_id,weekday)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_schedule_exceptions (
 mentor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, local_date DATE NOT NULL,
 PRIMARY KEY(mentor_id,local_date), FOREIGN KEY(mentor_id) REFERENCES m_mentors(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_bookings (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, mentor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, starts_at BIGINT UNSIGNED NOT NULL, ends_at BIGINT UNSIGNED NOT NULL,
 buffer_minutes SMALLINT NOT NULL, local_date DATE NOT NULL, state VARCHAR(16) NOT NULL, mode VARCHAR(12) NOT NULL DEFAULT 'text',
 problem TEXT NOT NULL, idempotency_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 expires_at BIGINT UNSIGNED NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(mentor_id) REFERENCES m_mentors(user_id), FOREIGN KEY(user_id) REFERENCES m_users(id),
 UNIQUE KEY uq_booking_request(user_id,idempotency_key), INDEX(mentor_id,starts_at,state), INDEX(user_id,starts_at), INDEX idx_booking_expiry(state,expires_at),
 CHECK(state IN ('pending','confirmed','rejected','expired','cancelled','completed')), CHECK(ends_at>starts_at), CHECK(mode='text')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_booking_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, booking_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 actor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, event VARCHAR(40) NOT NULL, note TEXT NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(booking_id) REFERENCES m_bookings(id), FOREIGN KEY(actor_id) REFERENCES m_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_time_proposals (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, booking_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 proposer_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, starts_at BIGINT UNSIGNED NOT NULL,
 state VARCHAR(12) NOT NULL DEFAULT 'pending', expires_at BIGINT UNSIGNED NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(booking_id) REFERENCES m_bookings(id), FOREIGN KEY(proposer_id) REFERENCES m_users(id),
 CHECK(state IN ('pending','accepted','rejected','expired')), INDEX(booking_id,state), INDEX idx_proposal_expiry(state,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, booking_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 sender_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, client_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 body TEXT NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(booking_id) REFERENCES m_bookings(id), FOREIGN KEY(sender_id) REFERENCES m_users(id),
 UNIQUE KEY uq_message_retry(booking_id,sender_id,client_key), INDEX(booking_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_tickets (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 booking_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, kind VARCHAR(12) NOT NULL, title VARCHAR(180) NOT NULL,
 state VARCHAR(12) NOT NULL DEFAULT 'open', created_at BIGINT UNSIGNED NOT NULL, updated_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(user_id) REFERENCES m_users(id), FOREIGN KEY(booking_id) REFERENCES m_bookings(id),
 CHECK(kind IN ('support','complaint')), CHECK(state IN ('open','closed')), INDEX(state,updated_at), INDEX(user_id,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_ticket_messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ticket_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 author_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, body TEXT NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(ticket_id) REFERENCES m_tickets(id), FOREIGN KEY(author_id) REFERENCES m_users(id), INDEX(ticket_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_feedback (
 booking_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 score TINYINT NOT NULL, body TEXT NOT NULL, created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(booking_id) REFERENCES m_bookings(id), FOREIGN KEY(user_id) REFERENCES m_users(id), CHECK(score BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_archive_grants (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, ticket_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 booking_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, reviewer_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 approved_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, purpose VARCHAR(500) NOT NULL,
 expires_at BIGINT UNSIGNED NOT NULL, revoked_at BIGINT UNSIGNED NULL, created_at BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(ticket_id) REFERENCES m_tickets(id), FOREIGN KEY(booking_id) REFERENCES m_bookings(id),
 FOREIGN KEY(reviewer_id) REFERENCES m_users(id), FOREIGN KEY(approved_by) REFERENCES m_users(id), INDEX(booking_id,reviewer_id,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_organizations (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY, name VARCHAR(180) NOT NULL, active TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE m_memberships (
 org_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(org_id,user_id), FOREIGN KEY(org_id) REFERENCES m_organizations(id), FOREIGN KEY(user_id) REFERENCES m_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO m_schema_migrations(version,description) VALUES(2,'Content, booking, durable text sessions and operations');
