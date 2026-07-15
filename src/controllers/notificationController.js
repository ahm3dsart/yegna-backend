const pool = require('../config/database');

exports.getNotifications = async (req, res) => {
  try {
    const userId = req.userId;
    const { limit = 20, offset = 0 } = req.query;

    const [rows] = await pool.query(
      `SELECT * FROM notifications 
       WHERE user_id = ? 
       ORDER BY created_at DESC 
       LIMIT ? OFFSET ?`,
      [userId, parseInt(limit), parseInt(offset)]
    );

    const [unread] = await pool.query(
      'SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE',
      [userId]
    );

    res.json({
      success: true,
      data: rows,
      unreadCount: unread[0].count
    });
  } catch (error) {
    console.error('Error fetching notifications:', error);
    res.status(500).json({
      success: false,
      message: 'Error fetching notifications',
      error: error.message
    });
  }
};

exports.markAsRead = async (req, res) => {
  try {
    const { id } = req.params;
    const userId = req.userId;

    await pool.query(
      'UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?',
      [id, userId]
    );

    res.json({
      success: true,
      message: 'Notification marked as read'
    });
  } catch (error) {
    console.error('Error marking notification as read:', error);
    res.status(500).json({
      success: false,
      message: 'Error marking notification as read',
      error: error.message
    });
  }
};

exports.markAllAsRead = async (req, res) => {
  try {
    const userId = req.userId;

    await pool.query(
      'UPDATE notifications SET is_read = TRUE WHERE user_id = ?',
      [userId]
    );

    res.json({
      success: true,
      message: 'All notifications marked as read'
    });
  } catch (error) {
    console.error('Error marking all as read:', error);
    res.status(500).json({
      success: false,
      message: 'Error marking all as read',
      error: error.message
    });
  }
};

exports.createNotification = async (userId, type, title, message, data = null) => {
  try {
    await pool.query(
      `INSERT INTO notifications (user_id, type, title, message, data) 
       VALUES (?, ?, ?, ?, ?)`,
      [userId, type, title, message, JSON.stringify(data)]
    );
  } catch (error) {
    console.error('Error creating notification:', error);
  }
};