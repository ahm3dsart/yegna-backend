-- =============================================================================
-- YEGNA — Review Helpful Votes Migration
-- MySQL 5.7 compatible — run in phpMyAdmin against the `yegna` database
-- Safe to run multiple times (CREATE TABLE IF NOT EXISTS)
-- =============================================================================

SET NAMES utf8mb4;

-- Track which user voted "helpful" on which review (one vote per user per review)
CREATE TABLE IF NOT EXISTS review_helpful_votes (
  id         INT       PRIMARY KEY AUTO_INCREMENT,
  review_id  INT       NOT NULL,
  user_id    INT       NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_review_user (review_id, user_id),
  FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
  INDEX idx_review (review_id),
  INDEX idx_user   (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ensure reviews table has helpful_count column (may already exist)
ALTER TABLE reviews ADD COLUMN IF NOT EXISTS helpful_count INT NOT NULL DEFAULT 0;
