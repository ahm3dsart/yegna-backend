const express = require('express');
const router = express.Router();
const businessController = require('../controllers/businessController');
const reviewRoutes = require('./reviewRoutes');
const { protect } = require('../middleware/auth');

// Public routes
router.get('/', businessController.getBusinesses);
router.get('/nearby', businessController.getNearbyBusinesses);
router.get('/search', businessController.searchBusinesses);
router.get('/featured', businessController.getFeatured);
router.get('/trending', businessController.getTrending);
router.get('/top-rated', businessController.getTopRated);
router.get('/recently-added', businessController.getRecentlyAdded);
router.get('/categories', businessController.getCategories);

// Protected routes
router.post('/favorite', protect, businessController.toggleFavorite);
router.get('/favorites', protect, businessController.getUserFavorites);

// Single business routes
router.get('/:id', businessController.getBusinessById);

// Nested review routes: /api/businesses/:businessId/reviews
router.use('/:businessId/reviews', reviewRoutes);

module.exports = router;
