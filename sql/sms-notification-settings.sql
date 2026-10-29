-- Apply deliberately during deployment, after a private backup. Never run on each web request.
-- Existing library tables and private provider settings are not changed.
CREATE TABLE sms_notification_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    allow_notifications TINYINT UNSIGNED NOT NULL DEFAULT 0,
    version INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT sms_setting_singleton CHECK (id = 1),
    CONSTRAINT sms_setting_boolean CHECK (allow_notifications IN (0,1))
) ENGINE=InnoDB;
INSERT INTO sms_notification_settings(id,allow_notifications,version) VALUES(1,0,0);
CREATE TABLE sms_notification_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NOT NULL,
    previous_allowed TINYINT UNSIGNED NOT NULL,
    new_allowed TINYINT UNSIGNED NOT NULL,
    setting_version INT UNSIGNED NOT NULL UNIQUE,
    changed_at DATETIME NOT NULL
) ENGINE=InnoDB;
