-- Skip categories since they already exist
-- Use INSERT IGNORE to avoid duplicates

USE yegna_db;

-- Add missing columns to users
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(20) DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS bio TEXT DEFAULT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS points INT DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS level INT DEFAULT 1;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_verified BOOLEAN DEFAULT FALSE;

-- Create visits table if not exists
CREATE TABLE IF NOT EXISTS visits (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  business_id INT NOT NULL,
  visited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  UNIQUE KEY unique_user_business_visit (user_id, business_id)
);

-- Create amenities table if not exists
CREATE TABLE IF NOT EXISTS amenities (
  id INT PRIMARY KEY AUTO_INCREMENT,
  business_id INT NOT NULL,
  amenity VARCHAR(100) NOT NULL,
  FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  INDEX idx_amenity (amenity)
);

-- Create events table if not exists
CREATE TABLE IF NOT EXISTS events (
  id INT PRIMARY KEY AUTO_INCREMENT,
  business_id INT NOT NULL,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  image_url VARCHAR(500),
  start_date DATETIME NOT NULL,
  end_date DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
);

-- Create notifications table if not exists
CREATE TABLE IF NOT EXISTS notifications (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  type VARCHAR(50) NOT NULL,
  title VARCHAR(200) NOT NULL,
  message TEXT NOT NULL,
  data JSON,
  is_read BOOLEAN DEFAULT FALSE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user_read (user_id, is_read)
);

-- Create reports table if not exists
CREATE TABLE IF NOT EXISTS reports (
  id INT PRIMARY KEY AUTO_INCREMENT,
  reported_by INT NOT NULL,
  target_type ENUM('business', 'review', 'user') NOT NULL,
  target_id INT NOT NULL,
  reason VARCHAR(200) NOT NULL,
  description TEXT,
  status ENUM('pending', 'reviewed', 'resolved') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Create activity_logs table if not exists
CREATE TABLE IF NOT EXISTS activity_logs (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  action VARCHAR(100) NOT NULL,
  details JSON,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user_action (user_id, action)
);