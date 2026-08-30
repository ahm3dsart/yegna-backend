-- =============================================================================
-- YEGNA — Featured Ads System Migration
-- Run this in phpMyAdmin against the `yegna` database
-- Safe to run multiple times (IF NOT EXISTS)
--
-- TIMEZONE CONTRACT:
--   start_at and end_at are stored as UTC.
--   The PHP backend converts from Africa/Addis_Ababa (EAT, UTC+3) to UTC
--   when saving, and from UTC back to EAT when returning to the admin dashboard.
--   The mobile app never performs schedule filtering — only the backend does.
-- =============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS featured_ads (
    id               INT          PRIMARY KEY AUTO_INCREMENT,
    title            VARCHAR(255) NOT NULL                    COMMENT 'Internal campaign/ad name',
    business_id      INT          DEFAULT NULL                COMMENT 'Optional linked business (nullable)',
    media_type       ENUM('image','video') NOT NULL DEFAULT 'image',
    media_url        VARCHAR(600) NOT NULL                    COMMENT 'Full URL to uploaded image or video',
    destination_url  VARCHAR(600) DEFAULT NULL                COMMENT 'CTA destination URL (optional)',
    cta_text         VARCHAR(100) DEFAULT NULL                COMMENT 'Custom button label e.g. "Download App"',
    start_at         DATETIME     NOT NULL                    COMMENT 'UTC — admin enters EAT, backend converts',
    end_at           DATETIME     NOT NULL                    COMMENT 'UTC — admin enters EAT, backend converts',
    display_duration SMALLINT     NOT NULL DEFAULT 8          COMMENT 'Seconds to show image ad before rotating',
    priority         SMALLINT     NOT NULL DEFAULT 10         COMMENT 'Lower number = shown first in rotation',
    weight           SMALLINT     NOT NULL DEFAULT 1          COMMENT 'Rotation weight (higher = more frequent)',
    is_active        TINYINT(1)   NOT NULL DEFAULT 1          COMMENT 'Manual on/off switch',
    impressions      INT          NOT NULL DEFAULT 0          COMMENT 'View count (analytics foundation)',
    clicks           INT          NOT NULL DEFAULT 0          COMMENT 'CTA click count',
    created_by       INT          NOT NULL                    COMMENT 'Admin user ID who created this',
    created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Foreign keys (nullable so ads can exist without a registered business)
    CONSTRAINT fk_featured_ads_business
        FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE SET NULL,
    CONSTRAINT fk_featured_ads_created_by
        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,

    -- Indexes for fast active-ad queries
    INDEX idx_active_schedule (is_active, start_at, end_at),
    INDEX idx_priority_weight (priority, weight)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
