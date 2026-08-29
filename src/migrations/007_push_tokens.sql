-- ── Expo Push Tokens table ───────────────────────────────────────────────────
-- One user can have multiple tokens (phone + tablet, uninstall/reinstall, etc.)
CREATE TABLE IF NOT EXISTS push_tokens (
  id            INT PRIMARY KEY AUTO_INCREMENT,
  user_id       INT NOT NULL,
  token         VARCHAR(255) NOT NULL,
  platform      ENUM('ios','android','web') DEFAULT 'android',
  device_info   VARCHAR(255),
  is_active     BOOLEAN DEFAULT TRUE,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY unique_token (token),
  INDEX idx_user_active (user_id, is_active)
);
