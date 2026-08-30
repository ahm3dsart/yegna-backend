-- =============================================================================
-- YEGNA — Privacy & Notification Preferences Migration
-- Run in phpMyAdmin against the `yegna` database
--
-- MySQL 5.7 / 8.0 compatible — does NOT use "ADD COLUMN IF NOT EXISTS"
-- which requires MySQL 8.0.3+.
--
-- SAFE TO RUN ON EXISTING DATA:
--   - No tables dropped or truncated
--   - New columns default to 1 (visible/on) — preserves current user experience
--   - Column errors (duplicate column) are expected if already migrated; ignore them
--   - Run each ALTER TABLE statement separately if one errors, then continue
-- =============================================================================

SET NAMES utf8mb4;

-- ─── 1. Add public_profile to user_privacy ───────────────────────────────────
-- If this errors with "Duplicate column name", the column already exists. Skip.
ALTER TABLE user_privacy
  ADD COLUMN public_profile TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1=profile visible to others, 0=restricted (name+avatar only)';

-- ─── 2. Add show_location to user_privacy ────────────────────────────────────
-- If this errors with "Duplicate column name", the column already exists. Skip.
ALTER TABLE user_privacy
  ADD COLUMN show_location TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1=location visible to others, 0=location hidden';

-- ─── 3. Create notification_preferences table ────────────────────────────────
-- Safe to run multiple times — CREATE TABLE IF NOT EXISTS.
-- One row per user; all push preferences default to on except promotions/updates.
CREATE TABLE IF NOT EXISTS notification_preferences (
  user_id      INT        NOT NULL,
  follows      TINYINT(1) NOT NULL DEFAULT 1  COMMENT 'New follower push',
  checkins     TINYINT(1) NOT NULL DEFAULT 1  COMMENT 'Friend check-in push',
  new_reviews  TINYINT(1) NOT NULL DEFAULT 1  COMMENT 'New review on a saved business',
  replies      TINYINT(1) NOT NULL DEFAULT 1  COMMENT 'Reply to my review',
  trending     TINYINT(1) NOT NULL DEFAULT 1  COMMENT 'Trending/recommendation/discovery push',
  promotions   TINYINT(1) NOT NULL DEFAULT 0  COMMENT 'Business promotional push',
  events       TINYINT(1) NOT NULL DEFAULT 1  COMMENT 'Upcoming events push',
  updates      TINYINT(1) NOT NULL DEFAULT 0  COMMENT 'App update news push',
  updated_at   TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_notif_pref_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
