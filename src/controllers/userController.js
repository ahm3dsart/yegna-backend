const User = require('../models/User');
const Business = require('../models/Business');
const { createActivity } = require('./socialController');

// Get user profile
exports.getProfile = async (req, res) => {
  try {
    const userId = req.userId;
    const user = await User.findById(userId);
    
    if (!user) {
      return res.status(404).json({
        success: false,
        message: 'User not found'
      });
    }

    const stats = await User.getStats(userId);
    const favorites = await User.getFavorites(userId);
    const reviews = await User.getReviews(userId);
    const visited = await User.getVisited(userId);

    res.json({
      success: true,
      data: {
        user,
        stats,
        favorites,
        reviews,
        visited
      }
    });
  } catch (error) {
    console.error('Error fetching profile:', error);
    res.status(500).json({
      success: false,
      message: 'Error fetching profile',
      error: error.message
    });
  }
};

// Update profile
exports.updateProfile = async (req, res) => {
  try {
    const userId = req.userId;
    const { name, email, phone, bio, avatar_url } = req.body;

    const result = await User.updateProfile(userId, {
      name,
      email,
      phone,
      bio,
      avatar_url
    });

    if (result === 0) {
      return res.status(404).json({
        success: false,
        message: 'User not found'
      });
    }

    const user = await User.findById(userId);
    res.json({
      success: true,
      message: 'Profile updated successfully',
      data: user
    });
  } catch (error) {
    console.error('Error updating profile:', error);
    res.status(500).json({
      success: false,
      message: 'Error updating profile',
      error: error.message
    });
  }
};

// Get user stats
exports.getStats = async (req, res) => {
  try {
    const userId = req.userId;
    const stats = await User.getStats(userId);
    res.json({
      success: true,
      data: stats
    });
  } catch (error) {
    console.error('Error fetching stats:', error);
    res.status(500).json({
      success: false,
      message: 'Error fetching stats',
      error: error.message
    });
  }
};

// Get user favorites
exports.getFavorites = async (req, res) => {
  try {
    const userId = req.userId;
    const favorites = await User.getFavorites(userId);
    res.json({
      success: true,
      data: favorites,
      count: favorites.length
    });
  } catch (error) {
    console.error('Error fetching favorites:', error);
    res.status(500).json({
      success: false,
      message: 'Error fetching favorites',
      error: error.message
    });
  }
};

// Get user reviews
exports.getUserReviews = async (req, res) => {
  try {
    const userId = req.userId;
    const reviews = await User.getReviews(userId);
    res.json({
      success: true,
      data: reviews
    });
  } catch (error) {
    console.error('Error fetching user reviews:', error);
    res.status(500).json({
      success: false,
      message: 'Error fetching reviews',
      error: error.message
    });
  }
};

// Get visited places
exports.getVisited = async (req, res) => {
  try {
    const userId = req.userId;
    const visited = await User.getVisited(userId);
    res.json({
      success: true,
      data: visited
    });
  } catch (error) {
    console.error('Error fetching visited places:', error);
    res.status(500).json({
      success: false,
      message: 'Error fetching visited places',
      error: error.message
    });
  }
};

// Add visit — requires location proof (user must be within 200m of business)
exports.addVisit = async (req, res) => {
  try {
    const { businessId, latitude, longitude } = req.body;
    const userId = req.userId;

    if (!businessId) {
      return res.status(400).json({ success: false, message: 'Business ID is required' });
    }

    // Location is required — no silent auto-visits
    if (latitude == null || longitude == null) {
      return res.status(400).json({
        success: false,
        message: 'Location required. Please enable location services to check in.'
      });
    }

    // Fetch business coordinates
    const pool = require('../config/database');
    const [[biz]] = await pool.execute(
      'SELECT latitude, longitude, name FROM businesses WHERE id = ?',
      [businessId]
    );

    if (!biz) {
      return res.status(404).json({ success: false, message: 'Business not found' });
    }

    if (!biz.latitude || !biz.longitude) {
      // Business has no coordinates — allow check-in but flag it
      console.warn(`Business ${businessId} has no coordinates, skipping proximity check`);
    } else {
      // Haversine distance in metres
      const R = 6371000;
      const dLat = (biz.latitude  - latitude)  * Math.PI / 180;
      const dLon = (biz.longitude - longitude) * Math.PI / 180;
      const a = Math.sin(dLat / 2) ** 2
              + Math.cos(latitude * Math.PI / 180)
              * Math.cos(biz.latitude * Math.PI / 180)
              * Math.sin(dLon / 2) ** 2;
      const distance = R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

      const MAX_METRES = 200;
      if (distance > MAX_METRES) {
        return res.status(400).json({
          success: false,
          message: `You need to be at ${biz.name} to check in. You appear to be ${Math.round(distance)}m away.`,
          distance: Math.round(distance),
          required: MAX_METRES,
        });
      }
    }

    const result = await Business.addVisit(userId, businessId);

    if (result) {
      await createActivity(userId, 'visit', businessId, null, null, null, 0, 'everyone');
    }

    res.json({
      success: true,
      message: result ? 'Check-in recorded! Welcome to ' + biz.name : 'You already checked in here.',
      already_visited: !result,
    });
  } catch (error) {
    console.error('Error adding visit:', error);
    res.status(500).json({ success: false, message: 'Error adding visit', error: error.message });
  }
};