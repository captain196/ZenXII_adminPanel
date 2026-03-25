-- ============================================================
-- Super Admin SaaS Control Panel — MySQL Migration
-- Run once against the school ERP database (school_db)
-- ============================================================

-- Rate-limiting table for SA login attempts (per IP)
CREATE TABLE IF NOT EXISTS `sa_rate_limits` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip`           VARCHAR(45)  NOT NULL,
    `fail_count`   TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `locked_until` DATETIME     DEFAULT NULL,
    `last_attempt` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ip` (`ip`),
    KEY `idx_locked_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Login rate-limiting for Super Admin panel (10 fails → 30 min lockout)';

-- ============================================================
-- That is the only MySQL table required.
-- All other SA data lives in Firebase under System/ nodes:
--
--   System/SuperAdmin/Auth/{sa_id}/
--       email       : "sa@example.com"
--       password    : bcrypt hash  (password_hash(pw, PASSWORD_BCRYPT))
--       name        : "Super Admin"
--       role        : "superadmin"   (or "viewer")
--       created_at  : "2026-01-01 00:00:00"
--
--   System/Schools/{school_uid}/
--       profile/name, city, email, phone, subdomain, firebase_key, status, created_at, created_by
--       subscription/plan_id, expiry_date, status, revenue
--       stats_cache/total_students, total_staff, last_updated
--
--   System/Plans/{plan_id}/
--       name, price, billing_cycle, max_students, max_staff, grace_days
--       modules/{module_key}: true|false
--
--   System/Stats/Summary/
--       total_schools, active_schools, total_students, total_revenue
--       last_refreshed
--
--   System/Logs/Activity/{date}/{push_id}/
--       sa_id, sa_name, action, school_uid, ip, timestamp
--
--   System/Logs/Errors/{date}/{push_id}/
--       message, file, line, ip, timestamp
--
--   System/Backups/{school_uid}/{backup_id}/
--       created_at, created_by, type (manual|safety), size_bytes
--
-- ============================================================
-- Seed: First Super Admin account
-- Replace 'your_secure_password' with actual password before running
-- Generate bcrypt hash in PHP:  echo password_hash('your_secure_password', PASSWORD_BCRYPT);
-- Then insert into Firebase manually at System/SuperAdmin/Auth/sa_001/
-- ============================================================
