USE yegna_db;

-- Insert test user if not exists
INSERT IGNORE INTO users (name, email, password_hash, role) 
VALUES ('Test User', 'test@yegna.com', '$2b$10$YourHashedPasswordHere', 'user');

-- Insert sample businesses (skip if already exist)
INSERT IGNORE INTO businesses (name, category, description, address, city, phone, latitude, longitude, price_range, rating, review_count, image_url) VALUES
('Tomoca Coffee', 'Coffee Shop', 'Famous Ethiopian coffee since 1953. Experience the authentic taste of Ethiopian coffee.', 'Bole Road', 'Addis Ababa', '+251-11-123-4567', 9.0150, 38.7510, '$$', 4.8, 234, 'https://images.unsplash.com/photo-1501339847302-ac426a4a7cbb'),
('Yabo Restaurant', 'Restaurant', 'Traditional Ethiopian cuisine served in a warm, cultural atmosphere.', 'Meskel Square', 'Addis Ababa', '+251-11-234-5678', 9.0100, 38.7600, '$$$', 4.5, 189, 'https://images.unsplash.com/photo-1517248135467-4c7edcad34c4'),
('Sheila Hotel', 'Hotel', 'Comfortable stay in the heart of the city with modern amenities.', 'Churchill Road', 'Addis Ababa', '+251-11-345-6789', 9.0200, 38.7450, '$$$', 4.2, 156, 'https://images.unsplash.com/photo-1566073771259-6a8506099945'),
('Khalidi Supermarket', 'Grocery', 'Your one-stop shop for quality groceries and household items.', 'Bole Medhanealem', 'Addis Ababa', '+251-11-456-7890', 9.0050, 38.7700, '$', 4.0, 98, 'https://images.unsplash.com/photo-1544716278-ca5e3f4abd8c'),
('Michael Salon', 'Beauty Salon', 'Professional hair and beauty services by experienced stylists.', 'Kazanchis', 'Addis Ababa', '+251-11-567-8901', 9.0250, 38.7550, '$$', 4.6, 67, 'https://images.unsplash.com/photo-1560066984-6f3d6a4e9b9e'),
('Abraham Pharmacy', 'Pharmacy', 'Quality healthcare products and professional pharmaceutical services.', 'Lideta', 'Addis Ababa', '+251-11-678-9012', 9.0120, 38.7400, '$', 4.3, 45, 'https://images.unsplash.com/photo-1587854692152-cbe660dbde88');

-- Insert sample business hours if not exists
INSERT IGNORE INTO business_hours (business_id, day_of_week, open_time, close_time) VALUES
(1, 'Monday', '08:00', '20:00'),
(1, 'Tuesday', '08:00', '20:00'),
(1, 'Wednesday', '08:00', '20:00'),
(1, 'Thursday', '08:00', '20:00'),
(1, 'Friday', '08:00', '21:00'),
(1, 'Saturday', '09:00', '21:00'),
(1, 'Sunday', '09:00', '18:00');

-- Insert sample reviews if not exists
INSERT IGNORE INTO reviews (business_id, user_id, rating, title, content) VALUES
(1, 1, 5, 'Best coffee in Addis!', 'Amazing coffee experience. The atmosphere is authentic and the coffee is incredible. A must-visit in Addis Ababa.'),
(2, 1, 5, 'Delicious Ethiopian food', 'The injera and doro wat were amazing. Great service and beautiful atmosphere.'),
(3, 1, 4, 'Comfortable stay', 'Clean rooms and friendly staff. Good location in the city center.');

-- Insert sample photos if not exists
INSERT IGNORE INTO photos (business_id, user_id, image_url, caption, is_primary) VALUES
(1, 1, 'https://images.unsplash.com/photo-1501339847302-ac426a4a7cbb', 'Beautiful coffee shop interior', TRUE),
(2, 1, 'https://images.unsplash.com/photo-1517248135467-4c7edcad34c4', 'Delicious traditional food', TRUE);