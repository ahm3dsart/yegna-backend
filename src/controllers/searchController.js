const Business = require('../models/Business');

exports.advancedSearch = async (req, res) => {
  try {
    const {
      q,
      category,
      city,
      lat,
      lng,
      radius,
      minRating,
      priceRange,
      amenities,
      sortBy,
      limit = 20,
      offset = 0
    } = req.query;

    // Build filters
    const filters = {
      search: q,
      category,
      city,
      minRating,
      priceRange,
      limit: parseInt(limit),
      offset: parseInt(offset)
    };

    let businesses;

    if (lat && lng) {
      // Search nearby
      const nearby = await Business.findNearby(
        parseFloat(lat),
        parseFloat(lng),
        parseFloat(radius) || 20,
        filters
      );
      businesses = nearby;
    } else {
      businesses = await Business.findAll(filters);
    }

    // Filter by amenities if provided
    if (amenities) {
      const amenityList = amenities.split(',');
      // This would require a more complex query - simplified for now
      businesses = businesses.filter(b => b.amenities && 
        amenityList.some(a => b.amenities.includes(a)));
    }

    // Sort results
    if (sortBy === 'distance') {
      // Already sorted by distance
    } else if (sortBy === 'rating') {
      businesses.sort((a, b) => b.rating - a.rating);
    } else if (sortBy === 'newest') {
      businesses.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
    }

    res.json({
      success: true,
      data: businesses,
      count: businesses.length,
      filters: { q, category, city, minRating, priceRange }
    });
  } catch (error) {
    console.error('Search error:', error);
    res.status(500).json({
      success: false,
      message: 'Error performing search',
      error: error.message
    });
  }
};