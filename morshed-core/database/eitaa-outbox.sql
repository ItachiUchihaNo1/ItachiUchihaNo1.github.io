CREATE TABLE IF NOT EXISTS m_eitaa_deliveries (notification_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,state VARCHAR(20) NOT NULL,created_at BIGINT NOT NULL,updated_at BIGINT NOT NULL,INDEX(state,created_at),FOREIGN KEY(notification_id) REFERENCES m_notifications(id)) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS m_message_permissions (
 user_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 eitaa_allowed TINYINT NOT NULL DEFAULT 0,
 source VARCHAR(24) NOT NULL DEFAULT 'client_sdk',
 updated_at BIGINT NOT NULL,
 FOREIGN KEY(user_id) REFERENCES m_users(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS m_sms_deliveries (
 notification_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
 state VARCHAR(32) NOT NULL,
 created_at BIGINT NOT NULL,
 updated_at BIGINT NOT NULL,
 INDEX(state,created_at),
 FOREIGN KEY(notification_id) REFERENCES m_notifications(id)
) ENGINE=InnoDB;

INSERT IGNORE INTO m_notifications(id,user_id,title,body,created_at,destination,category)
SELECT LEFT(SHA2(CONCAT('eitaa-permission24:',u.id),256),32),u.id,'دریافت پیام‌های مرشد در ایتا','برای دریافت نتیجهٔ درخواست مشاوره و پیام‌های مرتبط، از همین صفحه داخل برنامک ایتا اجازهٔ دریافت پیام را انتخاب کنید.',UNIX_TIMESTAMP(),'/notifications','system'
FROM m_users u JOIN m_auth_identities i ON i.user_id=u.id AND i.provider='eitaa'
LEFT JOIN m_message_permissions p ON p.user_id=u.id
WHERE u.status='active' AND p.user_id IS NULL;
