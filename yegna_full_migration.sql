-- =============================================================================
-- YEGNA APP — FULL DATABASE MIGRATION
-- Target database : yegna
-- Compatible with : MySQL 5.6 / 5.7 / 8.0
-- Import via      : phpMyAdmin → select `yegna` DB → Import → choose this file
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- CORE TABLES (001 + 002 + 005 + 006 columns all included from the start)
-- =============================================================================

-- Users table — all columns included upfront, no ALTER TABLE needed
CREATE TABLE IF NOT EXISTS users (
  id             INT          PRIMARY KEY AUTO_INCREMENT,
  name           VARCHAR(100) NOT NULL,
  email          VARCHAR(100) UNIQUE NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  avatar_url     VARCHAR(500),
  role           ENUM('user','business_owner','admin') DEFAULT 'user',
  -- 002 additions
  phone          VARCHAR(30)  DEFAULT NULL,
  bio            TEXT         DEFAULT NULL,
  points         INT          DEFAULT 0,
  level          INT          DEFAULT 1,
  is_verified    TINYINT(1)   DEFAULT 0,
  -- 005 additions
  username       VARCHAR(30)  UNIQUE,
  google_id      VARCHAR(200) UNIQUE,
  birth_date     DATE,
  email_verified TINYINT(1)   DEFAULT 0,
  created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Businesses table — owner_id and status included upfront
CREATE TABLE IF NOT EXISTS businesses (
  id              INT          PRIMARY KEY AUTO_INCREMENT,
  owner_id        INT,
  name            VARCHAR(200) NOT NULL,
  category        VARCHAR(100) NOT NULL,
  description     TEXT,
  address         VARCHAR(300) NOT NULL,
  city            VARCHAR(100) NOT NULL,
  state           VARCHAR(50),
  country         VARCHAR(50)  DEFAULT 'Ethiopia',
  postal_code     VARCHAR(20),
  phone           VARCHAR(50),
  email           VARCHAR(100),
  website         VARCHAR(200),
  latitude        DECIMAL(10,8),
  longitude       DECIMAL(11,8),
  image_url       VARCHAR(500),
  cover_image_url VARCHAR(500),
  rating          DECIMAL(3,2) DEFAULT 0,
  review_count    INT          DEFAULT 0,
  price_range     ENUM('$','$$','$$$','$$$$') DEFAULT '$$',
  is_active       BOOLEAN      DEFAULT TRUE,
  -- 006 addition
  status          ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_category (category),
  INDEX idx_city     (city),
  INDEX idx_rating   (rating),
  INDEX idx_location (latitude, longitude),
  INDEX idx_owner    (owner_id)
);

-- Business hours table
CREATE TABLE IF NOT EXISTS business_hours (
  id          INT PRIMARY KEY AUTO_INCREMENT,
  business_id INT NOT NULL,
  day_of_week ENUM('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  open_time   TIME,
  close_time  TIME,
  is_closed   BOOLEAN   DEFAULT FALSE,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  UNIQUE KEY unique_business_day (business_id, day_of_week)
);

-- Reviews table
CREATE TABLE IF NOT EXISTS reviews (
  id            INT PRIMARY KEY AUTO_INCREMENT,
  business_id   INT NOT NULL,
  user_id       INT NOT NULL,
  rating        INT NOT NULL,
  title         VARCHAR(200),
  content       TEXT NOT NULL,
  images        JSON,
  is_verified   BOOLEAN   DEFAULT FALSE,
  helpful_count INT       DEFAULT 0,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)     REFERENCES users(id)      ON DELETE CASCADE,
  UNIQUE KEY unique_user_business_review (business_id, user_id),
  INDEX idx_rating  (rating),
  INDEX idx_created (created_at)
);

-- Photos table — uploaded_by included upfront
CREATE TABLE IF NOT EXISTS photos (
  id          INT PRIMARY KEY AUTO_INCREMENT,
  business_id INT NOT NULL,
  user_id     INT NOT NULL,
  uploaded_by INT,
  image_url   VARCHAR(500) NOT NULL,
  caption     VARCHAR(200),
  is_primary  BOOLEAN   DEFAULT FALSE,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)     REFERENCES users(id)      ON DELETE CASCADE,
  FOREIGN KEY (uploaded_by) REFERENCES users(id)      ON DELETE SET NULL,
  INDEX idx_business (business_id)
);

-- Favorites table
CREATE TABLE IF NOT EXISTS favorites (
  id          INT PRIMARY KEY AUTO_INCREMENT,
  user_id     INT NOT NULL,
  business_id INT NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)     REFERENCES users(id)      ON DELETE CASCADE,
  FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  UNIQUE KEY unique_user_business_favorite (user_id, business_id)
);

-- Categories table
CREATE TABLE IF NOT EXISTS categories (
  id          INT PRIMARY KEY AUTO_INCREMENT,
  name        VARCHAR(100) UNIQUE NOT NULL,
  icon        VARCHAR(50),
  description VARCHAR(200),
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Default categories
INSERT IGNORE INTO categories (name, icon, description) VALUES
  ('Restaurant',   'restaurant',      'Places to eat and dine'),
  ('Coffee Shop',  'coffee',          'Coffee and tea houses'),
  ('Hotel',        'hotel',           'Accommodation and lodging'),
  ('Grocery',      'storefront',      'Supermarkets and grocery stores'),
  ('Pharmacy',     'medication',      'Pharmacies and drug stores'),
  ('Beauty Salon', 'spa',             'Hair and beauty services'),
  ('Fitness',      'fitness-center',  'Gyms and fitness centers'),
  ('Shopping',     'shopping-bag',    'Shopping centers and boutiques'),
  ('Bank',         'account-balance', 'Banks and financial services'),
  ('Hospital',     'local-hospital',  'Hospitals and clinics');

-- =============================================================================
-- 002 — ADDITIONAL TABLES
-- =============================================================================

-- Visits table
CREATE TABLE IF NOT EXISTS visits (
  id          INT PRIMARY KEY AUTO_INCREMENT,
  user_id     INT NOT NULL,
  business_id INT NOT NULL,
  visited_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)     REFERENCES users(id)      ON DELETE CASCADE,
  FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  UNIQUE KEY unique_user_business_visit (user_id, business_id)
);

-- Amenities table
CREATE TABLE IF NOT EXISTS amenities (
  id          INT PRIMARY KEY AUTO_INCREMENT,
  business_id INT NOT NULL,
  amenity     VARCHAR(100) NOT NULL,
  FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  INDEX idx_amenity (amenity)
);

-- Events table
CREATE TABLE IF NOT EXISTS events (
  id          INT PRIMARY KEY AUTO_INCREMENT,
  business_id INT NOT NULL,
  title       VARCHAR(200) NOT NULL,
  description TEXT,
  image_url   VARCHAR(500),
  start_date  DATETIME NOT NULL,
  end_date    DATETIME NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
);

-- Notifications table
CREATE TABLE IF NOT EXISTS notifications (
  id         INT PRIMARY KEY AUTO_INCREMENT,
  user_id    INT          NOT NULL,
  type       VARCHAR(50)  NOT NULL,
  title      VARCHAR(200) NOT NULL,
  message    TEXT         NOT NULL,
  data       JSON,
  is_read    BOOLEAN      DEFAULT FALSE,
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user_read (user_id, is_read)
);

-- Reports table
CREATE TABLE IF NOT EXISTS reports (
  id          INT PRIMARY KEY AUTO_INCREMENT,
  reported_by INT NOT NULL,
  target_type ENUM('business','review','user') NOT NULL,
  target_id   INT NOT NULL,
  reason      VARCHAR(200) NOT NULL,
  description TEXT,
  status      ENUM('pending','reviewed','resolved') DEFAULT 'pending',
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Activity logs table
CREATE TABLE IF NOT EXISTS activity_logs (
  id         INT PRIMARY KEY AUTO_INCREMENT,
  user_id    INT          NOT NULL,
  action     VARCHAR(100) NOT NULL,
  details    JSON,
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user_action (user_id, action)
);

-- =============================================================================
-- 003 — SOCIAL TABLES
-- =============================================================================

-- Follows table
CREATE TABLE IF NOT EXISTS follows (
  id           INT PRIMARY KEY AUTO_INCREMENT,
  follower_id  INT NOT NULL,
  following_id INT NOT NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (follower_id)  REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (following_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY unique_follow (follower_id, following_id),
  INDEX idx_follower  (follower_id),
  INDEX idx_following (following_id)
);

-- Activity feed table
CREATE TABLE IF NOT EXISTS activity_feed (
  id           INT PRIMARY KEY AUTO_INCREMENT,
  user_id      INT  NOT NULL,
  type         ENUM('review','visit','photo','rating') NOT NULL,
  business_id  INT  NOT NULL,
  reference_id INT,
  caption      VARCHAR(500),
  rating       INT,
  photo_count  INT  DEFAULT 0,
  visibility   ENUM('everyone','friends','only_me') DEFAULT 'everyone',
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)     REFERENCES users(id)      ON DELETE CASCADE,
  FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  INDEX idx_user       (user_id),
  INDEX idx_business   (business_id),
  INDEX idx_created    (created_at),
  INDEX idx_visibility (visibility)
);

-- User privacy table
CREATE TABLE IF NOT EXISTS user_privacy (
  user_id              INT PRIMARY KEY,
  activity_visibility  ENUM('everyone','friends','only_me')             DEFAULT 'everyone',
  reviews_visibility   ENUM('everyone','followers','friends','only_me') DEFAULT 'everyone',
  photos_visibility    ENUM('everyone','followers','friends','only_me') DEFAULT 'everyone',
  visited_visibility   ENUM('everyone','followers','friends','hidden')  DEFAULT 'everyone',
  saved_visibility     ENUM('public','friends','private')               DEFAULT 'friends',
  followers_visibility ENUM('public','friends','hidden')                DEFAULT 'public',
  updated_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- =============================================================================
-- 005 — OTP CODES TABLE
-- =============================================================================

CREATE TABLE IF NOT EXISTS otp_codes (
  id         INT PRIMARY KEY AUTO_INCREMENT,
  email      VARCHAR(100) NOT NULL,
  code       VARCHAR(6)   NOT NULL,
  type       ENUM('verify','reset') NOT NULL DEFAULT 'verify',
  expires_at TIMESTAMP NOT NULL,
  used       TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP  DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email   (email),
  INDEX idx_expires (expires_at)
);

-- =============================================================================
-- SEED DATA — 60 Addis Ababa businesses
-- =============================================================================

-- Restaurants
INSERT IGNORE INTO businesses (name, description, category, address, city, phone, website, latitude, longitude, price_range, image_url, is_active) VALUES
('Yod Abyssinia', 'Traditional Ethiopian cuisine with live cultural shows. Famous for injera, tibs, and kitfo in a vibrant cultural setting.', 'Restaurant', 'Bole Road, near Atlas Hotel', 'Addis Ababa', '+251-11-661-2985', 'www.yodethiopia.com', 9.0105, 38.7896, '$$$', 'https://images.unsplash.com/photo-1555396273-367ea4eb4db5?w=800', 1),
('Habesha 2000', 'Authentic Habesha food experience with traditional music and dance every evening. Best kitfo in Addis.', 'Restaurant', 'Bole, behind Friendship supermarket', 'Addis Ababa', '+251-11-663-2000', NULL, 9.0074, 38.7962, '$$', 'https://images.unsplash.com/photo-1544025162-d76694265947?w=800', 1),
('Four Seasons Restaurant', 'Upscale dining with panoramic city views. International and Ethiopian fusion cuisine with premium wine selection.', 'Restaurant', 'Hilton Hotel, Menelik II Ave', 'Addis Ababa', '+251-11-517-0000', NULL, 9.0241, 38.7604, '$$$$', 'https://images.unsplash.com/photo-1414235077428-338989a2e8c0?w=800', 1),
('Kategna', 'Popular casual restaurant known for firfir, gored gored, and fresh tej. Always busy — locals love it.', 'Restaurant', 'Bole Michael, near Zemen Bank', 'Addis Ababa', '+251-91-123-4567', NULL, 9.0132, 38.8012, '$', 'https://images.unsplash.com/photo-1567620905732-2d1ec7ab7445?w=800', 1),
('Dashen Restaurant', 'Modern Ethiopian dining with a contemporary twist. Cozy atmosphere, great shiro and doro wat.', 'Restaurant', 'Kazanchis, King George St', 'Addis Ababa', '+251-11-551-2233', NULL, 9.0289, 38.7512, '$$', 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?w=800', 1),
('Lucy Restaurant', 'Named after the famous fossil, this upscale spot offers refined Ethiopian and continental dishes.', 'Restaurant', 'Ghion Hotel, off Ras Desta St', 'Addis Ababa', '+251-11-513-4200', NULL, 9.0198, 38.7445, '$$$', 'https://images.unsplash.com/photo-1466978913421-dad2ebd01d17?w=800', 1);

-- Coffee Shops
INSERT IGNORE INTO businesses (name, description, category, address, city, phone, website, latitude, longitude, price_range, image_url, is_active) VALUES
('Tomoca Coffee', 'Addis Ababa''s most iconic coffee house since 1953. World-famous espresso and traditional bunna ceremony.', 'Coffee Shop', 'Wavel Street, Piassa', 'Addis Ababa', '+251-11-111-5550', NULL, 9.0395, 38.7537, '$', 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=800', 1),
('Kaldi''s Coffee', 'Ethiopia''s premier specialty coffee chain. Multiple locations, great espresso drinks and light bites.', 'Coffee Shop', 'Bole Medhanialem, Bole Road', 'Addis Ababa', '+251-11-667-4455', 'www.kaldiscoffee.com', 9.0051, 38.7891, '$$', 'https://images.unsplash.com/photo-1442512595331-e89e73853f31?w=800', 1),
('Kategna Coffee', 'Specialty single-origin Ethiopian coffees. Expert baristas, beautiful industrial interior, third-wave experience.', 'Coffee Shop', 'CMC Road, Bole Sub-city', 'Addis Ababa', '+251-91-234-5678', NULL, 9.0189, 38.8145, '$$', 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?w=800', 1),
('Crown Coffee', 'Cozy neighborhood café with excellent macchiato and homemade pastries. Quiet workspace atmosphere.', 'Coffee Shop', 'Sarbet, near Dembel City Center', 'Addis Ababa', '+251-91-345-6789', NULL, 9.0012, 38.7701, '$', 'https://images.unsplash.com/photo-1501339847302-ac426a4a7cbb?w=800', 1),
('Chercher Coffee', 'Named after the Chercher mountains, known for specialty harrar and yirgacheffe beans roasted in-house.', 'Coffee Shop', 'Kazanchis, near ECA', 'Addis Ababa', '+251-91-456-7890', NULL, 9.0267, 38.7498, '$$', 'https://images.unsplash.com/photo-1447933601403-0c6688de566e?w=800', 1),
('Coffee Garden', 'Open-air garden café with shade trees and flowing water features. Perfect for afternoon meetings.', 'Coffee Shop', 'Old Airport Road, Lideta', 'Addis Ababa', '+251-91-567-8901', NULL, 9.0178, 38.7389, '$', 'https://images.unsplash.com/photo-1559925393-8be0ec4767c8?w=800', 1);

-- Hotels
INSERT IGNORE INTO businesses (name, description, category, address, city, phone, website, latitude, longitude, price_range, image_url, is_active) VALUES
('Sheraton Addis', 'Addis Ababa''s most iconic 5-star hotel. World-class facilities, stunning gardens, multiple restaurants and pools.', 'Hotel', 'Taitu Street, Kirkos Sub-city', 'Addis Ababa', '+251-11-517-1717', 'www.sheratonaddis.com', 9.0241, 38.7604, '$$$$', 'https://images.unsplash.com/photo-1566073771259-6a8506099945?w=800', 1),
('Hilton Addis Ababa', 'Centrally located 5-star hotel with excellent conference facilities. Beloved institution in the city.', 'Hotel', 'Menelik II Square', 'Addis Ababa', '+251-11-517-0000', 'www.hilton.com', 9.0241, 38.7510, '$$$$', 'https://images.unsplash.com/photo-1551882547-ff40c63fe5fa?w=800', 1),
('Kuriftu Resort Bishoftu', 'Luxury lakeside resort 45 minutes from Addis. Stunning views of Lake Babogaya, spa, and water sports.', 'Hotel', 'Lake Babogaya, Bishoftu', 'Bishoftu', '+251-11-663-2600', 'www.kurifturesort.com', 8.7603, 38.9887, '$$$$', 'https://images.unsplash.com/photo-1520250497591-112f2f40a3f4?w=800', 1),
('Jupiter International Hotel', 'Modern business hotel in the heart of Bole. Excellent service, rooftop bar with city views.', 'Hotel', 'Bole Road, near airport', 'Addis Ababa', '+251-11-661-7171', 'www.jupiterhotel.net', 9.0023, 38.7978, '$$$', 'https://images.unsplash.com/photo-1578683010236-d716f9a3f461?w=800', 1),
('Radisson Blu', 'Contemporary 5-star hotel in Kazanchis. Sky bar on the 22nd floor with panoramic views.', 'Hotel', 'Kazanchis Business District', 'Addis Ababa', '+251-11-557-8888', 'www.radissonblu.com', 9.0312, 38.7534, '$$$$', 'https://images.unsplash.com/photo-1445019980597-93fa8acb246c?w=800', 1),
('Harmony Hotel', 'Boutique hotel in a peaceful garden setting. Excellent Ethiopian breakfast, friendly staff, great value.', 'Hotel', 'Bole Michael area', 'Addis Ababa', '+251-11-663-9191', NULL, 9.0156, 38.8034, '$$$', 'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?w=800', 1);

-- Grocery
INSERT IGNORE INTO businesses (name, description, category, address, city, phone, website, latitude, longitude, price_range, image_url, is_active) VALUES
('Friendship Supermarket', 'Addis Ababa''s largest supermarket chain. Wide variety of local and imported products, fresh produce section.', 'Grocery', 'Bole Road, Bole Sub-city', 'Addis Ababa', '+251-11-661-5555', NULL, 9.0068, 38.7934, '$$', 'https://images.unsplash.com/photo-1542838132-92c53300491e?w=800', 1),
('Shoa Supermarket', 'Well-stocked supermarket with excellent meat and dairy sections. Multiple branches across the city.', 'Grocery', 'Piassa, Churchill Avenue', 'Addis Ababa', '+251-11-111-2222', NULL, 9.0389, 38.7512, '$', 'https://images.unsplash.com/photo-1534723452862-4c874018d66d?w=800', 1),
('Novis Supermarket', 'Premium imported goods, organic produce, and specialty international foods. Best cheese and wine selection.', 'Grocery', 'Bole Medhanialem', 'Addis Ababa', '+251-11-663-7788', NULL, 9.0045, 38.7867, '$$$', 'https://images.unsplash.com/photo-1519996529931-28324d5a630e?w=800', 1),
('Bambis Supermarket', 'Affordable everyday grocery needs. Great local produce, spices, and Ethiopian pantry staples.', 'Grocery', 'Mexico Square, Kirkos', 'Addis Ababa', '+251-11-551-3344', NULL, 9.0231, 38.7423, '$', 'https://images.unsplash.com/photo-1583258292688-d0213dc5a3a8?w=800', 1),
('Summit Supermarket', 'Family-owned grocery with excellent deli counter. Fresh bread daily, great coffee beans selection.', 'Grocery', 'Summit area, Bole', 'Addis Ababa', '+251-91-678-9012', NULL, 9.0312, 38.8167, '$', 'https://images.unsplash.com/photo-1488459716781-31db52582fe9?w=800', 1),
('Alem Market', 'Traditional market atmosphere with modern supermarket convenience. Best injera flour and spice blends.', 'Grocery', 'Mercato area, Addis Ketema', 'Addis Ababa', '+251-11-222-3344', NULL, 9.0412, 38.7334, '$', 'https://images.unsplash.com/photo-1601598851547-4302969d0614?w=800', 1);

-- Pharmacy
INSERT IGNORE INTO businesses (name, description, category, address, city, phone, website, latitude, longitude, price_range, image_url, is_active) VALUES
('Kenema Pharmacy', 'Well-stocked pharmacy with qualified pharmacists. Fast prescription service and home delivery available.', 'Pharmacy', 'Bole Road, near Edna Mall', 'Addis Ababa', '+251-11-661-0011', NULL, 9.0089, 38.7945, '$$', 'https://images.unsplash.com/photo-1585435557343-3b092031a831?w=800', 1),
('Gishen Pharmacy', 'Trusted neighborhood pharmacy with 24-hour service. Wide range of medications and healthcare products.', 'Pharmacy', 'Kazanchis, near Commercial Bank', 'Addis Ababa', '+251-11-551-9922', NULL, 9.0278, 38.7489, '$', 'https://images.unsplash.com/photo-1471864190281-a93a3070b6de?w=800', 1),
('Zemen Pharmacy', 'Modern pharmacy with dermatology and cosmetic health products. Professional staff and quick service.', 'Pharmacy', 'Sarbet, Bole Sub-city', 'Addis Ababa', '+251-91-789-0123', NULL, 9.0001, 38.7712, '$', 'https://images.unsplash.com/photo-1576602976047-174e57a47881?w=800', 1),
('Hayat Pharmacy', 'Hospital-affiliated pharmacy. Specialist medications, vaccinations, and wellness consultations.', 'Pharmacy', 'CMC Road, Yeka Sub-city', 'Addis Ababa', '+251-11-646-4646', NULL, 9.0223, 38.8101, '$$', 'https://images.unsplash.com/photo-1563213126-a4273aed2016?w=800', 1),
('Minaret Pharmacy', 'Community pharmacy trusted for generations. Reliable stock of essential medicines at fair prices.', 'Pharmacy', 'Piassa, Lideta Sub-city', 'Addis Ababa', '+251-11-155-6677', NULL, 9.0401, 38.7523, '$', 'https://images.unsplash.com/photo-1559841644-08984562005b?w=800', 1),
('Alpha Pharmacy', 'Full-service pharmacy with lab testing on premises. Specialized in chronic disease medication management.', 'Pharmacy', 'Bole Michael, Addis Ababa', 'Addis Ababa', '+251-91-890-1234', NULL, 9.0145, 38.8023, '$$', 'https://images.unsplash.com/photo-1587854692152-cbe660dbde88?w=800', 1);

-- Beauty Salons
INSERT IGNORE INTO businesses (name, description, category, address, city, phone, website, latitude, longitude, price_range, image_url, is_active) VALUES
('Elegance Beauty Salon', 'Premium salon offering hair, nail, and skin treatments. Trained stylists specializing in natural African hair.', 'Beauty Salon', 'Bole, Atlas Hotel area', 'Addis Ababa', '+251-91-901-2345', NULL, 9.0112, 38.7889, '$$$', 'https://images.unsplash.com/photo-1560066984-138daaa3b3c7?w=800', 1),
('Radiant Spa & Salon', 'Full-service spa with massages, facials, hair and nail treatments. Serene atmosphere for total relaxation.', 'Beauty Salon', 'Bole Medhanialem Road', 'Addis Ababa', '+251-91-012-3456', NULL, 9.0056, 38.7845, '$$$', 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?w=800', 1),
('Glow Beauty Center', 'Affordable quality beauty services. Popular for braiding, weaving, and traditional Ethiopian hair treatments.', 'Beauty Salon', 'Piassa, near St. George Cathedral', 'Addis Ababa', '+251-91-123-0987', NULL, 9.0389, 38.7467, '$', 'https://images.unsplash.com/photo-1487412947147-5cebf100ffc2?w=800', 1),
('Luxe Nail & Beauty', 'Specialized nail art studio with gel, acrylic, and traditional manicure services. Hygienic and professional.', 'Beauty Salon', 'Sarbet area, Bole', 'Addis Ababa', '+251-91-234-0876', NULL, 9.0023, 38.7723, '$$', 'https://images.unsplash.com/photo-1604654894610-df63bc536371?w=800', 1),
('Amara Beauty Lounge', 'Upscale beauty lounge with private treatment rooms. Specializes in bridal packages and special occasion styling.', 'Beauty Salon', 'CMC area, Yeka', 'Addis Ababa', '+251-91-345-0765', NULL, 9.0198, 38.8089, '$$$', 'https://images.unsplash.com/photo-1470259078422-826894b933aa?w=800', 1),
('Natural Beauty Studio', 'Organic and chemical-free treatments. Experts in loc maintenance, natural styling, and scalp care.', 'Beauty Salon', 'Old Airport area, Lideta', 'Addis Ababa', '+251-91-456-0654', NULL, 9.0167, 38.7378, '$$', 'https://images.unsplash.com/photo-1526045612212-70caf35c14df?w=800', 1);

-- Fitness
INSERT IGNORE INTO businesses (name, description, category, address, city, phone, website, latitude, longitude, price_range, image_url, is_active) VALUES
('Gold''s Gym Addis', 'International franchise gym with full weights, cardio equipment, and group classes. Personal trainers available.', 'Fitness', 'Bole Road, Edna Mall vicinity', 'Addis Ababa', '+251-11-661-9090', NULL, 9.0078, 38.7956, '$$$', 'https://images.unsplash.com/photo-1534438327276-14e5300c3a48?w=800', 1),
('Millennium Fitness Center', 'Modern gym with Olympic weights, cardio zone, and swimming pool. Popular with serious athletes.', 'Fitness', 'Kazanchis, near Radisson Blu', 'Addis Ababa', '+251-11-557-7070', NULL, 9.0301, 38.7523, '$$', 'https://images.unsplash.com/photo-1571902943202-507ec2618e8f?w=800', 1),
('Anytime Fitness Addis', '24-hour gym access with state-of-the-art equipment. Perfect for early morning and late night workouts.', 'Fitness', 'Bole Michael, Yeka Sub-city', 'Addis Ababa', '+251-91-567-0543', NULL, 9.0134, 38.8012, '$$$', 'https://images.unsplash.com/photo-1517836357463-d25dfeac3438?w=800', 1),
('Nexus Health Club', 'Premium fitness club with yoga, pilates, spinning, and martial arts. Sauna and steam room included.', 'Fitness', 'Summit area, Bole', 'Addis Ababa', '+251-91-678-0432', NULL, 9.0289, 38.8145, '$$$', 'https://images.unsplash.com/photo-1540497077202-7c8a3999166f?w=800', 1),
('Planet Fitness Ethiopia', 'Affordable gym membership with no judgment policy. Great for beginners and casual fitness enthusiasts.', 'Fitness', 'Piassa, Arada Sub-city', 'Addis Ababa', '+251-91-789-0321', NULL, 9.0378, 38.7489, '$', 'https://images.unsplash.com/photo-1593079831268-3381b0db4a77?w=800', 1),
('Warrior CrossFit', 'High-intensity CrossFit box with certified coaches. Community-focused training for all fitness levels.', 'Fitness', 'CMC Road, Addis Ababa', 'Addis Ababa', '+251-91-890-0210', NULL, 9.0212, 38.8134, '$$', 'https://images.unsplash.com/photo-1526506118085-60ce8714f8c5?w=800', 1);

-- Shopping
INSERT IGNORE INTO businesses (name, description, category, address, city, phone, website, latitude, longitude, price_range, image_url, is_active) VALUES
('Edna Mall', 'Addis Ababa''s premier shopping and entertainment mall. 100+ stores, cinema, food court, and events space.', 'Shopping', 'Bole Road, Bole Sub-city', 'Addis Ababa', '+251-11-661-2345', 'www.ednamall.com', 9.0089, 38.7945, '$$$', 'https://images.unsplash.com/photo-1555529669-e69e7aa0ba9a?w=800', 1),
('Dembel City Center', 'Multi-level shopping center with fashion, electronics, and dining. Rooftop parking available.', 'Shopping', 'Sarbet, Bole Sub-city', 'Addis Ababa', '+251-11-661-8888', NULL, 9.0012, 38.7701, '$$', 'https://images.unsplash.com/photo-1483985988355-763728e1935b?w=800', 1),
('Merkato Market', 'One of Africa''s largest open-air markets. Everything from spices and fabrics to electronics and crafts.', 'Shopping', 'Merkato, Addis Ketema', 'Addis Ababa', NULL, NULL, 9.0423, 38.7312, '$', 'https://images.unsplash.com/photo-1519125323398-675f0ddb6308?w=800', 1),
('Shola Market', 'Popular local shopping area with fresh produce, clothing, and household goods at great prices.', 'Shopping', 'Shola area, Yeka Sub-city', 'Addis Ababa', NULL, NULL, 9.0198, 38.8012, '$', 'https://images.unsplash.com/photo-1441986300917-64674bd600d8?w=800', 1),
('Africa Avenue Mall', 'Modern shopping mall on Bole road with international and local brands. Popular food court and coffee shops.', 'Shopping', 'Africa Avenue, Bole', 'Addis Ababa', '+251-11-661-0055', NULL, 9.0034, 38.7867, '$$$', 'https://images.unsplash.com/photo-1607082348824-0a96f2a4b9da?w=800', 1),
('Artisans Village', 'Curated marketplace for Ethiopian handcrafts, textiles, and contemporary art. Perfect for gifts and souvenirs.', 'Shopping', 'Bole Road, near Atlas Hotel', 'Addis Ababa', '+251-91-901-0099', NULL, 9.0101, 38.7878, '$$', 'https://images.unsplash.com/photo-1472851294608-062f824d29cc?w=800', 1);

-- Banks
INSERT IGNORE INTO businesses (name, description, category, address, city, phone, website, latitude, longitude, price_range, image_url, is_active) VALUES
('Commercial Bank of Ethiopia', 'Ethiopia''s largest bank. Full range of personal and business banking services, 24/7 ATM access.', 'Bank', 'Churchill Avenue, Arada', 'Addis Ababa', '+251-11-551-5004', 'www.combanketh.et', 9.0312, 38.7523, '$', 'https://images.unsplash.com/photo-1541354329998-f4d9a9f9297f?w=800', 1),
('Awash Bank', 'Leading private bank with excellent mobile banking platform. Fast transfer and loan services.', 'Bank', 'Bole Road, Bole Sub-city', 'Addis Ababa', '+251-11-661-6162', 'www.awashbank.com', 9.0067, 38.7923, '$', 'https://images.unsplash.com/photo-1556742049-0cfed4f6a45d?w=800', 1),
('Dashen Bank', 'Modern private bank with excellent digital services and customer support. Popular for business banking.', 'Bank', 'Kazanchis Business District', 'Addis Ababa', '+251-11-557-2200', 'www.dashenbank.com', 9.0289, 38.7512, '$', 'https://images.unsplash.com/photo-1601597111158-2fceff292cdc?w=800', 1),
('Bank of Abyssinia', 'Historic Ethiopian bank with modern digital services. Trusted for savings and investment products.', 'Bank', 'Piassa, Arada Sub-city', 'Addis Ababa', '+251-11-155-5651', 'www.bankofabyssinia.com', 9.0378, 38.7498, '$', 'https://images.unsplash.com/photo-1567427017947-545c5f8d16ad?w=800', 1),
('Zemen Bank', 'Innovative bank known for technology-first approach. Best mobile app in Ethiopia for personal banking.', 'Bank', 'Bole Michael, Addis Ababa', 'Addis Ababa', '+251-11-663-9000', 'www.zemenbank.com', 9.0145, 38.8001, '$', 'https://images.unsplash.com/photo-1556742044-3c52d6e88c62?w=800', 1),
('Cooperative Bank of Oromia', 'Community-focused bank serving farmers and small businesses. Microfinance and agricultural loans.', 'Bank', 'Mexico Square, Kirkos', 'Addis Ababa', '+251-11-552-1800', 'www.coopbankoromia.com', 9.0245, 38.7445, '$', 'https://images.unsplash.com/photo-1560472354-b33ff0c44a43?w=800', 1);

-- Hospitals
INSERT IGNORE INTO businesses (name, description, category, address, city, phone, website, latitude, longitude, price_range, image_url, is_active) VALUES
('St. Paul''s Hospital', 'Government teaching hospital with specialized departments. Emergency care 24/7, referral center.', 'Hospital', 'Swaziland Street, Gulele', 'Addis Ababa', '+251-11-278-0011', NULL, 9.0512, 38.7345, '$', 'https://images.unsplash.com/photo-1519494026892-80bbd2d6fd0d?w=800', 1),
('Tikur Anbessa Hospital', 'Black Lion Hospital — Ethiopia''s premier specialized teaching hospital. Connected to AAU Medical School.', 'Hospital', 'Lideta, near Ghion Hotel', 'Addis Ababa', '+251-11-517-0000', NULL, 9.0198, 38.7423, '$', 'https://images.unsplash.com/photo-1586773860418-d37222d8fce3?w=800', 1),
('Hayat Hospital', 'Well-equipped private hospital with specialist doctors. Clean facilities and English-speaking staff.', 'Hospital', 'CMC Road, Yeka Sub-city', 'Addis Ababa', '+251-11-646-4646', 'www.hayathospital.com', 9.0234, 38.8089, '$$$', 'https://images.unsplash.com/photo-1538108149393-fbbd81895907?w=800', 1),
('MCM General Hospital', 'Modern private hospital with advanced diagnostic equipment. Known for orthopedics and cardiology.', 'Hospital', 'Bole Road, Bole Sub-city', 'Addis Ababa', '+251-11-661-4646', NULL, 9.0078, 38.7934, '$$$', 'https://images.unsplash.com/photo-1587351021759-3e566b6af7cc?w=800', 1),
('Bethzatha General Hospital', 'Trusted private hospital with maternity ward and pediatrics. 24-hour emergency and pharmacy on-site.', 'Hospital', 'Kazanchis, Kirkos Sub-city', 'Addis Ababa', '+251-11-551-4545', NULL, 9.0267, 38.7501, '$$', 'https://images.unsplash.com/photo-1516549655169-df83a0774514?w=800', 1),
('Addis Cardiac Hospital', 'Specialized heart center with interventional cardiology. State-of-the-art cath lab and cardiac ICU.', 'Hospital', 'Bole Michael, Bole Sub-city', 'Addis Ababa', '+251-11-663-1234', NULL, 9.0156, 38.8034, '$$$$', 'https://images.unsplash.com/photo-1551190822-a9333d879b1f?w=800', 1);

-- =============================================================================
-- RATINGS UPDATE
-- =============================================================================

UPDATE businesses SET rating = 4.7, review_count = 312  WHERE name = 'Yod Abyssinia';
UPDATE businesses SET rating = 4.5, review_count = 198  WHERE name = 'Habesha 2000';
UPDATE businesses SET rating = 4.8, review_count = 445  WHERE name = 'Four Seasons Restaurant';
UPDATE businesses SET rating = 4.3, review_count = 89   WHERE name = 'Kategna';
UPDATE businesses SET rating = 4.4, review_count = 124  WHERE name = 'Dashen Restaurant';
UPDATE businesses SET rating = 4.6, review_count = 201  WHERE name = 'Lucy Restaurant';
UPDATE businesses SET rating = 4.9, review_count = 876  WHERE name = 'Tomoca Coffee';
UPDATE businesses SET rating = 4.6, review_count = 534  WHERE name = 'Kaldi''s Coffee';
UPDATE businesses SET rating = 4.4, review_count = 167  WHERE name = 'Kategna Coffee';
UPDATE businesses SET rating = 4.3, review_count = 92   WHERE name = 'Crown Coffee';
UPDATE businesses SET rating = 4.5, review_count = 213  WHERE name = 'Chercher Coffee';
UPDATE businesses SET rating = 4.2, review_count = 78   WHERE name = 'Coffee Garden';
UPDATE businesses SET rating = 4.8, review_count = 1203 WHERE name = 'Sheraton Addis';
UPDATE businesses SET rating = 4.7, review_count = 987  WHERE name = 'Hilton Addis Ababa';
UPDATE businesses SET rating = 4.9, review_count = 654  WHERE name = 'Kuriftu Resort Bishoftu';
UPDATE businesses SET rating = 4.5, review_count = 423  WHERE name = 'Jupiter International Hotel';
UPDATE businesses SET rating = 4.7, review_count = 567  WHERE name = 'Radisson Blu';
UPDATE businesses SET rating = 4.3, review_count = 178  WHERE name = 'Harmony Hotel';
UPDATE businesses SET rating = 4.2, review_count = 234  WHERE name = 'Friendship Supermarket';
UPDATE businesses SET rating = 4.0, review_count = 156  WHERE name = 'Shoa Supermarket';
UPDATE businesses SET rating = 4.4, review_count = 89   WHERE name = 'Novis Supermarket';
UPDATE businesses SET rating = 4.1, review_count = 67   WHERE name = 'Bambis Supermarket';
UPDATE businesses SET rating = 4.3, review_count = 112  WHERE name = 'Summit Supermarket';
UPDATE businesses SET rating = 3.9, review_count = 45   WHERE name = 'Alem Market';
UPDATE businesses SET rating = 4.4, review_count = 123  WHERE name = 'Kenema Pharmacy';
UPDATE businesses SET rating = 4.5, review_count = 89   WHERE name = 'Gishen Pharmacy';
UPDATE businesses SET rating = 4.2, review_count = 67   WHERE name = 'Zemen Pharmacy';
UPDATE businesses SET rating = 4.6, review_count = 145  WHERE name = 'Hayat Pharmacy';
UPDATE businesses SET rating = 4.1, review_count = 56   WHERE name = 'Minaret Pharmacy';
UPDATE businesses SET rating = 4.3, review_count = 78   WHERE name = 'Alpha Pharmacy';
UPDATE businesses SET rating = 4.6, review_count = 234  WHERE name = 'Elegance Beauty Salon';
UPDATE businesses SET rating = 4.7, review_count = 189  WHERE name = 'Radiant Spa & Salon';
UPDATE businesses SET rating = 4.2, review_count = 134  WHERE name = 'Glow Beauty Center';
UPDATE businesses SET rating = 4.4, review_count = 98   WHERE name = 'Luxe Nail & Beauty';
UPDATE businesses SET rating = 4.8, review_count = 212  WHERE name = 'Amara Beauty Lounge';
UPDATE businesses SET rating = 4.3, review_count = 87   WHERE name = 'Natural Beauty Studio';
UPDATE businesses SET rating = 4.5, review_count = 345  WHERE name = 'Gold''s Gym Addis';
UPDATE businesses SET rating = 4.4, review_count = 267  WHERE name = 'Millennium Fitness Center';
UPDATE businesses SET rating = 4.3, review_count = 189  WHERE name = 'Anytime Fitness Addis';
UPDATE businesses SET rating = 4.6, review_count = 234  WHERE name = 'Nexus Health Club';
UPDATE businesses SET rating = 4.0, review_count = 123  WHERE name = 'Planet Fitness Ethiopia';
UPDATE businesses SET rating = 4.7, review_count = 178  WHERE name = 'Warrior CrossFit';
UPDATE businesses SET rating = 4.5, review_count = 567  WHERE name = 'Edna Mall';
UPDATE businesses SET rating = 4.3, review_count = 345  WHERE name = 'Dembel City Center';
UPDATE businesses SET rating = 4.0, review_count = 234  WHERE name = 'Merkato Market';
UPDATE businesses SET rating = 4.1, review_count = 189  WHERE name = 'Shola Market';
UPDATE businesses SET rating = 4.4, review_count = 312  WHERE name = 'Africa Avenue Mall';
UPDATE businesses SET rating = 4.6, review_count = 156  WHERE name = 'Artisans Village';
UPDATE businesses SET rating = 3.9, review_count = 234  WHERE name = 'Commercial Bank of Ethiopia';
UPDATE businesses SET rating = 4.2, review_count = 178  WHERE name = 'Awash Bank';
UPDATE businesses SET rating = 4.1, review_count = 156  WHERE name = 'Dashen Bank';
UPDATE businesses SET rating = 4.0, review_count = 145  WHERE name = 'Bank of Abyssinia';
UPDATE businesses SET rating = 4.4, review_count = 189  WHERE name = 'Zemen Bank';
UPDATE businesses SET rating = 3.8, review_count = 98   WHERE name = 'Cooperative Bank of Oromia';
UPDATE businesses SET rating = 4.0, review_count = 312  WHERE name = 'St. Paul''s Hospital';
UPDATE businesses SET rating = 3.8, review_count = 445  WHERE name = 'Tikur Anbessa Hospital';
UPDATE businesses SET rating = 4.5, review_count = 234  WHERE name = 'Hayat Hospital';
UPDATE businesses SET rating = 4.4, review_count = 198  WHERE name = 'MCM General Hospital';
UPDATE businesses SET rating = 4.3, review_count = 167  WHERE name = 'Bethzatha General Hospital';
UPDATE businesses SET rating = 4.6, review_count = 145  WHERE name = 'Addis Cardiac Hospital';

-- =============================================================================
-- DONE
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 1;

SHOW TABLES;
