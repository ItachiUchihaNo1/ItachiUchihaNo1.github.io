CREATE TABLE IF NOT EXISTS m_service_policy (
 id TINYINT PRIMARY KEY,enabled TINYINT NOT NULL DEFAULT 1,independent_quota INT NOT NULL DEFAULT 1,member_quota INT NOT NULL DEFAULT 3,
 max_pending INT NOT NULL DEFAULT 2,response_hours INT NOT NULL DEFAULT 48,cancel_hours INT NOT NULL DEFAULT 2,
 timezone VARCHAR(50) NOT NULL DEFAULT 'Asia/Tehran',cycle_day TINYINT NOT NULL DEFAULT 1,
 behavior_days INT NOT NULL DEFAULT 90,second_days INT NOT NULL DEFAULT 7,third_days INT NOT NULL DEFAULT 30,
 updated_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,updated_at BIGINT NOT NULL,
 FOREIGN KEY(updated_by) REFERENCES m_users(id)
) ENGINE=InnoDB;
INSERT IGNORE INTO m_service_policy(id,updated_at) VALUES(1,UNIX_TIMESTAMP());
CREATE TABLE IF NOT EXISTS m_service_profiles (
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,novice TINYINT NOT NULL DEFAULT 1,updated_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,updated_at BIGINT NOT NULL,
 FOREIGN KEY(user_id) REFERENCES m_users(id),FOREIGN KEY(updated_by) REFERENCES m_users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_mentor_admission (
 mentor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,accept_novices TINYINT NOT NULL DEFAULT 1,max_pending INT NOT NULL DEFAULT 10,
 FOREIGN KEY(mentor_id) REFERENCES m_mentors(user_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_organization_verification (
 org_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,status VARCHAR(16) NOT NULL DEFAULT 'pending',submitted_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 contact_note TEXT NOT NULL,evidence TEXT NOT NULL,review_note TEXT NOT NULL,reviewed_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,reviewed_at BIGINT NULL,updated_at BIGINT NOT NULL,
 FOREIGN KEY(org_id) REFERENCES m_organizations(id),FOREIGN KEY(submitted_by) REFERENCES m_users(id),FOREIGN KEY(reviewed_by) REFERENCES m_users(id),INDEX(status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO m_organization_verification(org_id,status,contact_note,evidence,review_note,reviewed_by,reviewed_at,updated_at)
 SELECT org_id,'approved','','','تأیید قبلی مدیر سامانه',verified_by,verified_at,updated_at FROM m_organization_profiles WHERE verified_at IS NOT NULL AND merged_into IS NULL;
CREATE TABLE IF NOT EXISTS m_membership_requests (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,org_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 relation_kind VARCHAR(16) NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'pending',note TEXT NOT NULL,review_note TEXT NOT NULL,starts_at BIGINT NOT NULL,ends_at BIGINT NULL,
 reviewed_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,reviewed_at BIGINT NULL,updated_at BIGINT NOT NULL,
 UNIQUE(org_id,user_id),INDEX(user_id,status),INDEX(org_id,status),FOREIGN KEY(org_id) REFERENCES m_organizations(id),FOREIGN KEY(user_id) REFERENCES m_users(id),FOREIGN KEY(reviewed_by) REFERENCES m_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS m_networks (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,name VARCHAR(180) NOT NULL,description TEXT NOT NULL,activity TEXT NOT NULL,
 province_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,county_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,active TINYINT NOT NULL DEFAULT 1,
 created_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,updated_at BIGINT NOT NULL,
 FOREIGN KEY(created_by) REFERENCES m_users(id),FOREIGN KEY(province_id) REFERENCES m_places(id),FOREIGN KEY(county_id) REFERENCES m_places(id),INDEX(active,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS m_network_organizations (
 network_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,org_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(network_id,org_id),FOREIGN KEY(network_id) REFERENCES m_networks(id),FOREIGN KEY(org_id) REFERENCES m_organizations(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_delegations (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 scope_kind VARCHAR(16) NOT NULL,scope_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,verify_organizations TINYINT NOT NULL DEFAULT 0,review_members TINYINT NOT NULL DEFAULT 0,
 active TINYINT NOT NULL DEFAULT 1,granted_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,created_at BIGINT NOT NULL,
 UNIQUE(user_id,scope_kind,scope_id),INDEX(scope_kind,scope_id,active),FOREIGN KEY(user_id) REFERENCES m_users(id),FOREIGN KEY(granted_by) REFERENCES m_users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_personal_problems (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 title VARCHAR(180) NOT NULL,body TEXT NOT NULL,activity TEXT NOT NULL,stage TEXT NOT NULL,attempts TEXT NOT NULL,obstacles TEXT NOT NULL,expectation TEXT NOT NULL,
 org_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,content_hash CHAR(64) CHARACTER SET ascii NOT NULL,revision INT NOT NULL DEFAULT 1,created_at BIGINT NOT NULL,updated_at BIGINT NOT NULL,
 FOREIGN KEY(user_id) REFERENCES m_users(id),FOREIGN KEY(org_id) REFERENCES m_organizations(id),INDEX(user_id,updated_at),UNIQUE(user_id,content_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS m_personal_problem_terms (
 problem_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,term_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(problem_id,term_id),FOREIGN KEY(problem_id) REFERENCES m_personal_problems(id),FOREIGN KEY(term_id) REFERENCES m_terms(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_personal_problem_experiences (
 problem_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,experience_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 PRIMARY KEY(problem_id,experience_id),FOREIGN KEY(problem_id) REFERENCES m_personal_problems(id),FOREIGN KEY(experience_id) REFERENCES m_experiences(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_consultation_intakes (
 booking_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,problem_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 snapshot_json MEDIUMTEXT NOT NULL,needs_details TINYINT NOT NULL DEFAULT 0,decision_code VARCHAR(24) NOT NULL DEFAULT '',clarification TEXT NOT NULL,referred_mentor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 FOREIGN KEY(booking_id) REFERENCES m_bookings(id),FOREIGN KEY(problem_id) REFERENCES m_personal_problems(id),FOREIGN KEY(referred_mentor_id) REFERENCES m_mentors(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS m_credit_reservations (
 booking_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 cycle_key VARCHAR(24) NOT NULL,state VARCHAR(12) NOT NULL,created_at BIGINT NOT NULL,updated_at BIGINT NOT NULL,
 FOREIGN KEY(booking_id) REFERENCES m_bookings(id),FOREIGN KEY(user_id) REFERENCES m_users(id),INDEX(user_id,state,cycle_key)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_credit_grants (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,amount INT NOT NULL,
 expires_at BIGINT NOT NULL,reason VARCHAR(1000) NOT NULL,granted_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,created_at BIGINT NOT NULL,
 FOREIGN KEY(user_id) REFERENCES m_users(id),FOREIGN KEY(granted_by) REFERENCES m_users(id),INDEX(user_id,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS m_credit_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,booking_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 event VARCHAR(24) NOT NULL,reason VARCHAR(1000) NOT NULL,actor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,created_at BIGINT NOT NULL,
 FOREIGN KEY(user_id) REFERENCES m_users(id),FOREIGN KEY(booking_id) REFERENCES m_bookings(id),FOREIGN KEY(actor_id) REFERENCES m_users(id),INDEX(user_id,id),UNIQUE(booking_id,event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS m_consultation_replies (
 code VARCHAR(24) CHARACTER SET ascii PRIMARY KEY,title VARCHAR(180) NOT NULL,body TEXT NOT NULL,active TINYINT NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO m_consultation_replies VALUES
 ('accept','پذیرش','درخواست شما بررسی و پذیرفته شد. زمان انتخاب‌شدهٔ جلسه تأیید شد.',1),
 ('clarify','توضیح تکمیلی','برای بررسی درخواست، لازم است توضیحات بیشتری درباره مسئله و اقدامات قبلی ارائه کنید.',1),
 ('unrelated','موضوع نامرتبط','موضوع مطرح‌شده با حوزه تجربه و مشاوره من ارتباط کافی ندارد.',1),
 ('reading','پیشنهاد مطالعه','پیشنهاد می‌کنم پیش از برگزاری جلسه، تجربه‌های مرتبط با این مسئله را مطالعه کنید.',1),
 ('capacity','محدودیت ظرفیت','در حال حاضر امکان پذیرش درخواست جدید را ندارم.',1),
 ('refer','پیشنهاد مرشد دیگر','پیشنهاد می‌کنم این مسئله را با مرشد پیشنهادی بررسی کنید.',1);
CREATE TABLE IF NOT EXISTS m_behavior_events (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,booking_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 event VARCHAR(24) NOT NULL,status VARCHAR(16) NOT NULL,actor_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,note TEXT NOT NULL,evidence TEXT NOT NULL,
 reviewed_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,review_note TEXT NOT NULL,appeal_of CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,dedup_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,created_at BIGINT NOT NULL,reviewed_at BIGINT NULL,
 FOREIGN KEY(user_id) REFERENCES m_users(id),FOREIGN KEY(booking_id) REFERENCES m_bookings(id),FOREIGN KEY(actor_id) REFERENCES m_users(id),FOREIGN KEY(reviewed_by) REFERENCES m_users(id),FOREIGN KEY(appeal_of) REFERENCES m_behavior_events(id),INDEX(user_id,created_at),INDEX(status,event),UNIQUE(dedup_key),UNIQUE(appeal_of)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS m_service_restrictions (
 id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
 reason TEXT NOT NULL,starts_at BIGINT NOT NULL,ends_at BIGINT NOT NULL,created_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,revoked_by CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,revoked_at BIGINT NULL,
 FOREIGN KEY(user_id) REFERENCES m_users(id),FOREIGN KEY(event_id) REFERENCES m_behavior_events(id),FOREIGN KEY(created_by) REFERENCES m_users(id),FOREIGN KEY(revoked_by) REFERENCES m_users(id),INDEX(user_id,ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS m_credit_allocations (
 booking_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,grant_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 FOREIGN KEY(booking_id) REFERENCES m_credit_reservations(booking_id),FOREIGN KEY(grant_id) REFERENCES m_credit_grants(id),INDEX(grant_id)
) ENGINE=InnoDB;
