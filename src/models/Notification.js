const pool = require('../config/database');

class Notification {
  static async create(userId, type, title, message, data = null) {
    const [result] = await pool.query(
      `INSERT INTO notifications (user_id, type, title, message, data) 
       VALUES (?, ?, ?, ?, ?)`,
      [userId, type, title, message, data ? JSON.stringify(data) : null]
    );
    return result.insertId;
  }

  static async getByUser(userId, limit = 20, offset = 0) {
    const [rows] = await pool.query(
      `SELECT * FROM notifications 
       WHERE user_id = ? 
       ORDER BY created_at DESC 
       LIMIT ? OFFSET ?`,
      [userId, limit, offset]
    );
    return rows;
  }

  static async getUnreadCount(userId) {
    const [rows] = await pool.query(
      'SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE',
      [userId]
    );
    return rows[0].count;
  }

  static async markAsRead(id, userId) {
    const [result] = await pool.query(
      'UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?',
      [id, userId]
    );
    return result.affectedRows > 0;
  }

  static async markAllAsRead(userId) {
    const [result] = await pool.query(
      'UPDATE notifications SET is_read = TRUE WHERE user_id = ?',
      [userId]
    );
    return result.affectedRows;
  }
}

module.exports = Notification;
