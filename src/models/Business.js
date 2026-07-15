const pool = require('../config/database');

class Business {
  static async findAll(filters = {}) {
    let query = 'SELECT * FROM businesses WHERE is_active = TRUE';
    const values = [];

    if (filters.category) {
      query += ' AND category = ?';
      values.push(filters.category);
    }

    if (filters.city) {
      query += ' AND city = ?';
      values.push(filters.city);
    }

    if (filters.search) {
      query += ' AND (name LIKE ? OR description LIKE ? OR address LIKE ? OR category LIKE ?)';
      const searchTerm = `%${filters.search}%`;
      values.push(searchTerm, searchTerm, searchTerm, searchTerm);
    }

    if (filters.minRating) {
      query += ' AND rating >= ?';
      values.push(parseFloat(filters.minRating));
    }

    if (filters.priceRange) {
      query += ' AND price_range = ?';
      values.push(filters.priceRange);
    }

    if (filters.openNow) {
      // Complex query for open now - simplified
      query += ' AND is_active = TRUE';
    }

    query += ' ORDER BY rating DESC, review_count DESC';
    query += ' LIMIT ? OFFSET ?';
    values.push(filters.limit || 20, filters.offset || 0);

    const [rows] = await pool.query(query, values);
    return rows;
  }

  static async findById(id, userId = null) {
    const [rows] = await pool.query(
      'SELECT * FROM businesses WHERE id = ? AND is_active = TRUE',
      [id]
    );
    
    if (rows.length === 0) return null;
    
    const business = rows[0];
    
    // Get additional data
    const [hours] = await pool.query(
      'SELECT * FROM business_hours WHERE business_id = ? ORDER BY FIELD(day_of_week, "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday")',
      [id]
    );
    
    const [reviews] = await pool.query(
      `SELECT r.*, u.name as user_name, u.avatar_url 
       FROM reviews r 
       JOIN users u ON r.user_id = u.id 
       WHERE r.business_id = ? 
       ORDER BY r.created_at DESC 
       LIMIT 10`,
      [id]
    );
    
    const [photos] = await pool.query(
      'SELECT * FROM photos WHERE business_id = ? ORDER BY is_primary DESC, created_at DESC',
      [id]
    );
    
    const [amenities] = await pool.query(
      'SELECT amenity FROM amenities WHERE business_id = ?',
      [id]
    );
    
    const [events] = await pool.query(
      'SELECT * FROM events WHERE business_id = ? AND end_date >= NOW() ORDER BY start_date DESC LIMIT 5',
      [id]
    );
    
    // Check if user favorited this business
    let isFavorited = false;
    if (userId) {
      const [fav] = await pool.query(
        'SELECT id FROM favorites WHERE user_id = ? AND business_id = ?',
        [userId, id]
      );
      isFavorited = fav.length > 0;
    }
    
    return {
      ...business,
      hours,
      reviews,
      photos,
      amenities: amenities.map(a => a.amenity),
      events,
      isFavorited
    };
  }

  static async findNearby(lat, lng, radius = 10, filters = {}) {
    let query = `
      SELECT *,
        (6371 * acos(
          cos(radians(?)) * cos(radians(latitude)) *
          cos(radians(longitude) - radians(?)) +
          sin(radians(?)) * sin(radians(latitude))
        )) AS distance
      FROM businesses
      WHERE is_active = TRUE
        AND latitude IS NOT NULL
        AND longitude IS NOT NULL
    `;
    const values = [lat, lng, lat];

    if (filters.search) {
      query += ' AND (name LIKE ? OR description LIKE ? OR address LIKE ? OR category LIKE ?)';
      const t = `%${filters.search}%`;
      values.push(t, t, t, t);
    }

    if (filters.category) {
      query += ' AND category = ?';
      values.push(filters.category);
    }

    if (filters.minRating) {
      query += ' AND rating >= ?';
      values.push(parseFloat(filters.minRating));
    }

    if (filters.priceRange) {
      query += ' AND price_range = ?';
      values.push(filters.priceRange);
    }

    query += ' HAVING distance < ? ORDER BY distance ASC LIMIT 30';
    values.push(radius);

    const [rows] = await pool.query(query, values);
    return rows;
  }

  static async getCategories() {
    const [rows] = await pool.query(
      'SELECT * FROM categories ORDER BY name'
    );
    return rows;
  }

  static async getFeatured(lat = null, lng = null) {
    let query = 'SELECT * FROM businesses WHERE is_active = TRUE ORDER BY rating DESC, review_count DESC LIMIT 10';
    const [rows] = await pool.query(query);
    return rows;
  }

  static async getTrending(lat = null, lng = null) {
    let query = 'SELECT * FROM businesses WHERE is_active = TRUE ORDER BY review_count DESC, rating DESC LIMIT 10';
    const [rows] = await pool.query(query);
    return rows;
  }

  static async getTopRated() {
    const [rows] = await pool.query(
      'SELECT * FROM businesses WHERE is_active = TRUE ORDER BY rating DESC LIMIT 10'
    );
    return rows;
  }

  static async getRecentlyAdded() {
    const [rows] = await pool.query(
      'SELECT * FROM businesses WHERE is_active = TRUE ORDER BY created_at DESC LIMIT 10'
    );
    return rows;
  }

  static async toggleFavorite(userId, businessId) {
    // Check if already favorited
    const [existing] = await pool.query(
      'SELECT id FROM favorites WHERE user_id = ? AND business_id = ?',
      [userId, businessId]
    );
    
    if (existing.length > 0) {
      // Remove favorite
      await pool.query(
        'DELETE FROM favorites WHERE user_id = ? AND business_id = ?',
        [userId, businessId]
      );
      return { action: 'removed' };
    } else {
      // Add favorite
      await pool.query(
        'INSERT INTO favorites (user_id, business_id) VALUES (?, ?)',
        [userId, businessId]
      );
      return { action: 'added' };
    }
  }

  static async addVisit(userId, businessId) {
    const [result] = await pool.query(
      'INSERT IGNORE INTO visits (user_id, business_id) VALUES (?, ?)',
      [userId, businessId]
    );
    return result.affectedRows > 0;
  }

  static async getReviews(businessId, limit = 20, offset = 0) {
    const [rows] = await pool.query(
      `SELECT r.*, u.name as user_name, u.avatar_url 
       FROM reviews r 
       JOIN users u ON r.user_id = u.id 
       WHERE r.business_id = ? 
       ORDER BY r.created_at DESC 
       LIMIT ? OFFSET ?`,
      [businessId, limit, offset]
    );
    return rows;
  }

  static async addReview(businessId, userId, data) {
    const { rating, title, content, images } = data;
    
    const [result] = await pool.query(
      `INSERT INTO reviews (business_id, user_id, rating, title, content, images) 
       VALUES (?, ?, ?, ?, ?, ?)`,
      [businessId, userId, rating, title, content, images || null]
    );
    
    // Update business rating
    await this.updateRating(businessId);
    
    return result.insertId;
  }

  static async updateRating(businessId) {
    const [avg] = await pool.query(
      'SELECT AVG(rating) as avg, COUNT(*) as count FROM reviews WHERE business_id = ?',
      [businessId]
    );
    
    await pool.query(
      'UPDATE businesses SET rating = ?, review_count = ? WHERE id = ?',
      [avg[0].avg || 0, avg[0].count || 0, businessId]
    );
    
    return avg[0];
  }
}

module.exports = Business;