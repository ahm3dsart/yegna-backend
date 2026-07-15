const express = require('express');
const router = express.Router();
const userController = require('../controllers/userController');
const { protect } = require('../middleware/auth');

// All routes require authentication
router.use(protect);

router.get('/profile', userController.getProfile);
router.put('/profile', userController.updateProfile);
router.get('/stats', userController.getStats);
router.get('/favorites', userController.getFavorites);
router.get('/reviews', userController.getUserReviews);
router.get('/visited', userController.getVisited);
router.post('/visit', userController.addVisit);

module.exports = router;