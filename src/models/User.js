const pool = require('../config/database');
const bcrypt = require('bcryptjs');

class User {
  static async findByEmail(email) {
    const [rows] = await pool.query('SELECT * FROM users WHERE email = ?', [email]);
    return rows[0] || null;
  }

  static async findByUsername(username) {
    const [rows] = await pool.query('SELECT * FROM users WHERE username = ?', [username]);
    return rows[0] || null;
  }

  static async findByGoogleId(googleId) {
    const [rows] = await pool.query('SELECT * FROM users WHERE google_id = ?', [googleId]);
    return rows[0] || null;
  }

  static async findById(id) {
    const [rows] = await pool.query(
      'SELECT id, name, username, email, phone, bio, avatar_url, role, points, level, is_verified, email_verified, birth_date, google_id, created_at FROM users WHERE id = ?',
      [id]
    );
    return rows[0] || null;
  }

  static async create(userData) {
    const { name, username, email, password, phone, birth_date, google_id, avatar_url, email_verified = 0, role = 'user' } = userData;
    let password_hash = null;
    if (password) {
      const salt = await bcrypt.genSalt(10);
      password_hash = await bcrypt.hash(password, salt);
    }
    const [result] = await pool.query(
      `INSERT INTO users (name, username, email, password_hash, phone, birth_date, google_id, avatar_url, email_verified, role)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [name, username || null, email, password_hash, phone || null, birth_date || null,
       google_id || null, avatar_url || null, email_verified, role]
    );
    return result.insertId;
  }

  static async usernameExists(username) {
    const [rows] = await pool.query('SELECT id FROM users WHERE username = ?', [username]);
    return rows.length > 0;
  }

  static async comparePassword(plainPassword, hashedPassword) {
    if (!hashedPassword) return false;
    return bcrypt.compare(plainPassword, hashedPassword);
  }

  static async updateProfile(id, data) {
    const allowed = ['name', 'email', 'phone', 'bio', 'avatar_url', 'username', 'birth_date'];
    const fields = [], values = [];
    allowed.forEach(field => {
      if (data[field] !== undefined) { fields.push(`${field} = ?`); values.push(data[field]); }
    });
    if (!fields.length) return 0;
    values.push(id);
    const [result] = await pool.query(`UPDATE users SET ${fields.join(', ')} WHERE id = ?`, values);
    return result.affectedRows;
  }

  static async updatePassword(id, newPassword) {
    const salt = await bcrypt.genSalt(10);
    const hash = await bcrypt.hash(newPassword, salt);
    await pool.query('UPDATE users SET password_hash = ? WHERE id = ?', [hash, id]);
  }

  static async setEmailVerified(email) {
    await pool.query('UPDATE users SET email_verified = 1 WHERE email = ?', [email]);
  }

  static async addPoints(userId, points) {
    const [result] = await pool.query('UPDATE users SET points = points + ? WHERE id = ?', [points, userId]);
    return result.affectedRows;
  }

  static async getFavorites(userId) {
    const [rows] = await pool.query(
      'SELECT b.* FROM businesses b JOIN favorites f ON f.business_id = b.id WHERE f.user_id = ? ORDER BY f.created_at DESC',
      [userId]
    );
    return rows;
  }

  static async getVisited(userId) {
    const [rows] = await pool.query(
      'SELECT b.* FROM businesses b JOIN visits v ON v.business_id = b.id WHERE v.user_id = ? ORDER BY v.visited_at DESC',
      [userId]
    );
    return rows;
  }

  static async getReviews(userId) {
    const [rows] = await pool.query(
      'SELECT r.*, b.name as business_name FROM reviews r JOIN businesses b ON r.business_id = b.id WHERE r.user_id = ? ORDER BY r.created_at DESC',
      [userId]
    );
    return rows;
  }

  static async getStats(userId) {
    const [[reviews]] = await pool.query('SELECT COUNT(*) as count FROM reviews WHERE user_id = ?', [userId]);
    const [[favorites]] = await pool.query('SELECT COUNT(*) as count FROM favorites WHERE user_id = ?', [userId]);
    const [[visits]] = await pool.query('SELECT COUNT(*) as count FROM visits WHERE user_id = ?', [userId]);
    return { reviews: reviews.count, favorites: favorites.count, visits: visits.count };
  }
}

module.exports = User;
