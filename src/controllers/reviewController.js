const pool = require('../config/database');
const Business = require('../models/Business');
const { createActivity } = require('./socialController');

// Get reviews for a business
exports.getBusinessReviews = async (req, res) => {
  try {
    const { businessId } = req.params;
    const { limit = 20, offset = 0, sortBy = 'newest' } = req.query;

    let orderClause = 'r.created_at DESC';
    if (sortBy === 'highest') orderClause = 'r.rating DESC, r.created_at DESC';
    else if (sortBy === 'lowest') orderClause = 'r.rating ASC, r.created_at DESC';
    else if (sortBy === 'helpful') orderClause = 'r.helpful_count DESC, r.created_at DESC';

    const [rows] = await pool.query(
      `SELECT r.*, u.name as user_name, u.avatar_url
       FROM reviews r
       JOIN users u ON r.user_id = u.id
       WHERE r.business_id = ?
       ORDER BY ${orderClause}
       LIMIT ? OFFSET ?`,
      [businessId, parseInt(limit), parseInt(offset)]
    );

    const [total] = await pool.query(
      'SELECT COUNT(*) as count FROM reviews WHERE business_id = ?',
      [businessId]
    );

    // Rating distribution
    const [dist] = await pool.query(
      `SELECT rating, COUNT(*) as count
       FROM reviews WHERE business_id = ?
       GROUP BY rating ORDER BY rating DESC`,
      [businessId]
    );

    res.json({
      success: true,
      data: rows,
      total: total[0].count,
      distribution: dist,
    });
  } catch (error) {
    console.error('Error fetching reviews:', error);
    res.status(500).json({ success: false, message: 'Error fetching reviews', error: error.message });
  }
};

// Add a review
exports.addReview = async (req, res) => {
  try {
    const { businessId } = req.params;
    const userId = req.userId;
    const { rating, title, content, images } = req.body;

    if (!rating || !content) {
      return res.status(400).json({ success: false, message: 'Rating and content are required' });
    }

    if (rating < 1 || rating > 5) {
      return res.status(400).json({ success: false, message: 'Rating must be between 1 and 5' });
    }

    // Check if already reviewed
    const [existing] = await pool.query(
      'SELECT id FROM reviews WHERE business_id = ? AND user_id = ?',
      [businessId, userId]
    );

    if (existing.length > 0) {
      return res.status(400).json({ success: false, message: 'You have already reviewed this business' });
    }

    const reviewId = await Business.addReview(businessId, userId, {
      rating: parseInt(rating),
      title,
      content,
      images: images ? JSON.stringify(images) : null,
    });

    const [review] = await pool.query(
      `SELECT r.*, u.name as user_name, u.avatar_url
       FROM reviews r JOIN users u ON r.user_id = u.id
       WHERE r.id = ?`,
      [reviewId]
    );

    // Award points to user
    await pool.query('UPDATE users SET points = points + 10 WHERE id = ?', [userId]);

    // Create activity feed entry
    await createActivity(
      userId, 'review', businessId, reviewId,
      content?.substring(0, 200),
      parseInt(rating), 0, 'everyone'
    );

    res.status(201).json({
      success: true,
      message: 'Review added successfully',
      data: review[0],
    });
  } catch (error) {
    console.error('Error adding review:', error);
    res.status(500).json({ success: false, message: 'Error adding review', error: error.message });
  }
};

// Update a review
exports.updateReview = async (req, res) => {
  try {
    const { reviewId } = req.params;
    const userId = req.userId;
    const { rating, title, content } = req.body;

    const [existing] = await pool.query(
      'SELECT * FROM reviews WHERE id = ? AND user_id = ?',
      [reviewId, userId]
    );

    if (existing.length === 0) {
      return res.status(404).json({ success: false, message: 'Review not found or unauthorized' });
    }

    await pool.query(
      'UPDATE reviews SET rating = ?, title = ?, content = ? WHERE id = ?',
      [rating || existing[0].rating, title || existing[0].title, content || existing[0].content, reviewId]
    );

    // Recalculate business rating
    await Business.updateRating(existing[0].business_id);

    res.json({ success: true, message: 'Review updated successfully' });
  } catch (error) {
    console.error('Error updating review:', error);
    res.status(500).json({ success: false, message: 'Error updating review', error: error.message });
  }
};

// Delete a review
exports.deleteReview = async (req, res) => {
  try {
    const { reviewId } = req.params;
    const userId = req.userId;

    const [existing] = await pool.query(
      'SELECT * FROM reviews WHERE id = ? AND user_id = ?',
      [reviewId, userId]
    );

    if (existing.length === 0) {
      return res.status(404).json({ success: false, message: 'Review not found or unauthorized' });
    }

    await pool.query('DELETE FROM reviews WHERE id = ?', [reviewId]);
    await Business.updateRating(existing[0].business_id);

    res.json({ success: true, message: 'Review deleted successfully' });
  } catch (error) {
    console.error('Error deleting review:', error);
    res.status(500).json({ success: false, message: 'Error deleting review', error: error.message });
  }
};

// Mark review as helpful
exports.markHelpful = async (req, res) => {
  try {
    const { reviewId } = req.params;
    const userId = req.userId;

    // Check if already marked helpful
    const [existing] = await pool.query(
      'SELECT id FROM review_helpful WHERE review_id = ? AND user_id = ?',
      [reviewId, userId]
    );

    if (existing.length > 0) {
      // Remove helpful mark
      await pool.query('DELETE FROM review_helpful WHERE review_id = ? AND user_id = ?', [reviewId, userId]);
      await pool.query('UPDATE reviews SET helpful_count = helpful_count - 1 WHERE id = ? AND helpful_count > 0', [reviewId]);
      return res.json({ success: true, action: 'removed' });
    }

    // Add helpful mark - try insert, fallback gracefully if table doesn't exist
    try {
      await pool.query('INSERT INTO review_helpful (review_id, user_id) VALUES (?, ?)', [reviewId, userId]);
    } catch (e) {
      // Table might not exist yet, just increment count
    }
    await pool.query('UPDATE reviews SET helpful_count = helpful_count + 1 WHERE id = ?', [reviewId]);

    res.json({ success: true, action: 'added' });
  } catch (error) {
    console.error('Error marking helpful:', error);
    res.status(500).json({ success: false, message: 'Error marking review helpful', error: error.message });
  }
};

// Report a review
exports.reportReview = async (req, res) => {
  try {
    const { reviewId } = req.params;
    const userId = req.userId;
    const { reason, description } = req.body;

    if (!reason) {
      return res.status(400).json({ success: false, message: 'Reason is required' });
    }

    await pool.query(
      `INSERT INTO reports (reported_by, target_type, target_id, reason, description)
       VALUES (?, 'review', ?, ?, ?)`,
      [userId, reviewId, reason, description || null]
    );

    res.json({ success: true, message: 'Review reported successfully' });
  } catch (error) {
    console.error('Error reporting review:', error);
    res.status(500).json({ success: false, message: 'Error reporting review', error: error.message });
  }
};
