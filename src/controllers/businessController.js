const Business = require('../models/Business');
const User = require('../models/User');
const pool = require('../config/database');

// Get all businesses with filters
exports.getBusinesses = async (req, res) => {
  try {
    const { category, city, search, minRating, priceRange, openNow, limit, offset } = req.query;

    const businesses = await Business.findAll({
      category,
      city,
      search,
      minRating,
      priceRange,
      openNow,
      limit: parseInt(limit) || 20,
      offset: parseInt(offset) || 0,
    });

    res.json({ success: true, data: businesses, count: businesses.length });
  } catch (error) {
    console.error('Error fetching businesses:', error);
    res.status(500).json({ success: false, message: 'Error fetching businesses', error: error.message });
  }
};

// Get business by ID with full details
exports.getBusinessById = async (req, res) => {
  try {
    const { id } = req.params;
    const userId = req.userId || null;

    const business = await Business.findById(id, userId);

    if (!business) {
      return res.status(404).json({ success: false, message: 'Business not found' });
    }

    res.json({ success: true, data: business });
  } catch (error) {
    console.error('Error fetching business:', error);
    res.status(500).json({ success: false, message: 'Error fetching business', error: error.message });
  }
};

// Get nearby businesses
exports.getNearbyBusinesses = async (req, res) => {
  try {
    const { lat, lng, radius, category, minRating } = req.query;

    if (!lat || !lng) {
      return res.status(400).json({ success: false, message: 'Latitude and longitude are required' });
    }

    const businesses = await Business.findNearby(
      parseFloat(lat),
      parseFloat(lng),
      parseFloat(radius) || 10,
      { category, minRating }
    );

    res.json({ success: true, data: businesses, count: businesses.length });
  } catch (error) {
    console.error('Error fetching nearby businesses:', error);
    res.status(500).json({ success: false, message: 'Error fetching nearby businesses', error: error.message });
  }
};

// Search businesses — full filter support
exports.searchBusinesses = async (req, res) => {
  try {
    const { q, category, city, lat, lng, minRating, priceRange, sortBy, openNow, limit = 30, offset = 0 } = req.query;

    if (!q) {
      return res.status(400).json({ success: false, message: 'Search query is required' });
    }

    const filters = {
      search: q,
      category,
      city,
      minRating,
      priceRange,
      openNow,
      limit: parseInt(limit),
      offset: parseInt(offset),
    };

    let businesses;

    if (lat && lng) {
      businesses = await Business.findNearby(
        parseFloat(lat), parseFloat(lng), 20, filters
      );
    } else {
      businesses = await Business.findAll(filters);
    }

    // Apply sort after fetch
    if (sortBy === 'rating') {
      businesses.sort((a, b) => (b.rating || 0) - (a.rating || 0));
    } else if (sortBy === 'newest') {
      businesses.sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime());
    } else if (sortBy === 'popular') {
      businesses.sort((a, b) => (b.review_count || 0) - (a.review_count || 0));
    }

    res.json({ success: true, data: businesses, count: businesses.length, query: q });
  } catch (error) {
    console.error('Error searching businesses:', error);
    res.status(500).json({ success: false, message: 'Error searching businesses', error: error.message });
  }
};

// Get featured businesses
exports.getFeatured = async (req, res) => {
  try {
    const { lat, lng } = req.query;
    const businesses = await Business.getFeatured(lat, lng);
    res.json({ success: true, data: businesses });
  } catch (error) {
    console.error('Error fetching featured:', error);
    res.status(500).json({ success: false, message: 'Error fetching featured businesses', error: error.message });
  }
};

// Get trending businesses
exports.getTrending = async (req, res) => {
  try {
    const { lat, lng } = req.query;
    const businesses = await Business.getTrending(lat, lng);
    res.json({ success: true, data: businesses });
  } catch (error) {
    console.error('Error fetching trending:', error);
    res.status(500).json({ success: false, message: 'Error fetching trending businesses', error: error.message });
  }
};

// Get top rated businesses
exports.getTopRated = async (req, res) => {
  try {
    const businesses = await Business.getTopRated();
    res.json({ success: true, data: businesses });
  } catch (error) {
    console.error('Error fetching top rated:', error);
    res.status(500).json({ success: false, message: 'Error fetching top rated businesses', error: error.message });
  }
};

// Get recently added businesses
exports.getRecentlyAdded = async (req, res) => {
  try {
    const businesses = await Business.getRecentlyAdded();
    res.json({ success: true, data: businesses });
  } catch (error) {
    console.error('Error fetching recently added:', error);
    res.status(500).json({ success: false, message: 'Error fetching recently added businesses', error: error.message });
  }
};

// Get categories
exports.getCategories = async (req, res) => {
  try {
    const categories = await Business.getCategories();
    res.json({ success: true, data: categories });
  } catch (error) {
    console.error('Error fetching categories:', error);
    res.status(500).json({ success: false, message: 'Error fetching categories', error: error.message });
  }
};

// Toggle favorite
exports.toggleFavorite = async (req, res) => {
  try {
    const { businessId } = req.body;
    const userId = req.userId;

    if (!businessId) {
      return res.status(400).json({ success: false, message: 'Business ID is required' });
    }

    const result = await Business.toggleFavorite(userId, businessId);
    res.json({ success: true, data: result });
  } catch (error) {
    console.error('Error toggling favorite:', error);
    res.status(500).json({ success: false, message: 'Error toggling favorite', error: error.message });
  }
};

// Get user favorites
exports.getUserFavorites = async (req, res) => {
  try {
    const userId = req.userId;
    const favorites = await User.getFavorites(userId);
    res.json({ success: true, data: favorites, count: favorites.length });
  } catch (error) {
    console.error('Error fetching favorites:', error);
    res.status(500).json({ success: false, message: 'Error fetching favorites', error: error.message });
  }
};

// Get reviews for a business (delegated but kept for backward compat)
exports.getReviews = async (req, res) => {
  try {
    const { businessId } = req.params;
    const { limit = 20, offset = 0 } = req.query;
    const reviews = await Business.getReviews(businessId, parseInt(limit), parseInt(offset));
    res.json({ success: true, data: reviews });
  } catch (error) {
    console.error('Error fetching reviews:', error);
    res.status(500).json({ success: false, message: 'Error fetching reviews', error: error.message });
  }
};

// Add review (delegated but kept for backward compat)
exports.addReview = async (req, res) => {
  try {
    const { businessId } = req.params;
    const userId = req.userId;
    const { rating, title, content, images } = req.body;

    if (!rating || !content) {
      return res.status(400).json({ success: false, message: 'Rating and content are required' });
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

    res.status(201).json({ success: true, message: 'Review added successfully', data: { id: reviewId } });
  } catch (error) {
    console.error('Error adding review:', error);
    res.status(500).json({ success: false, message: 'Error adding review', error: error.message });
  }
};
