-- ── Follows (one-way, mutual = friends) ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS follows (
  id            INT PRIMARY KEY AUTO_INCREMENT,
  follower_id   INT NOT NULL,
  following_id  INT NOT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (follower_id)  REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (following_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY unique_follow (follower_id, following_id),
  INDEX idx_follower  (follower_id),
  INDEX idx_following (following_id)
);

-- ── Activity feed (auto-created on review / visit / photo) ───────────────────
CREATE TABLE IF NOT EXISTS activity_feed (
  id              INT PRIMARY KEY AUTO_INCREMENT,
  user_id         INT NOT NULL,
  type            ENUM('review','visit','photo','rating') NOT NULL,
  business_id     INT NOT NULL,
  reference_id    INT,           -- review.id / photo.id / visit.id
  caption         VARCHAR(500),
  rating          INT,
  photo_count     INT DEFAULT 0,
  visibility      ENUM('everyone','friends','only_me') DEFAULT 'everyone',
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)     REFERENCES users(id)      ON DELETE CASCADE,
  FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  INDEX idx_user       (user_id),
  INDEX idx_business   (business_id),
  INDEX idx_created    (created_at),
  INDEX idx_visibility (visibility)
);

-- ── Per-user privacy preferences ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS user_privacy (
  user_id             INT PRIMARY KEY,
  activity_visibility ENUM('everyone','friends','only_me') DEFAULT 'everyone',
  reviews_visibility  ENUM('everyone','followers','friends','only_me') DEFAULT 'everyone',
  photos_visibility   ENUM('everyone','followers','friends','only_me') DEFAULT 'everyone',
  visited_visibility  ENUM('everyone','followers','friends','hidden') DEFAULT 'everyone',
  saved_visibility    ENUM('public','friends','private') DEFAULT 'friends',
  followers_visibility ENUM('public','friends','hidden') DEFAULT 'public',
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ── Ensure users have follower/following counts (computed on the fly via SQL) ─
-- No extra columns needed — counts derived from follows table.

-- ── Update notifications type to include social events ───────────────────────
ALTER TABLE notifications
  MODIFY COLUMN type VARCHAR(50) NOT NULL;
-- (already VARCHAR so just ensuring 'follow','activity_review','activity_visit','activity_photo' fit)
