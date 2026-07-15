const express = require('express');
const router = express.Router({ mergeParams: true });
const reviewController = require('../controllers/reviewController');
const { protect } = require('../middleware/auth');

// GET /api/businesses/:businessId/reviews
router.get('/', reviewController.getBusinessReviews);

// Protected routes
router.post('/', protect, reviewController.addReview);
router.put('/:reviewId', protect, reviewController.updateReview);
router.delete('/:reviewId', protect, reviewController.deleteReview);
router.post('/:reviewId/helpful', protect, reviewController.markHelpful);
router.post('/:reviewId/report', protect, reviewController.reportReview);

module.exports = router;
