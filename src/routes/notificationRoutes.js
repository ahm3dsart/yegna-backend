const express = require('express');
const router = express.Router();
const notificationController = require('../controllers/notificationController');
const { protect } = require('../middleware/auth');

router.use(protect);

router.get('/', notificationController.getNotifications);
// Support both PATCH and PUT for mark-as-read
router.patch('/:id/read', notificationController.markAsRead);
router.put('/:id/read', notificationController.markAsRead);
router.patch('/read-all', notificationController.markAllAsRead);
router.put('/read-all', notificationController.markAllAsRead);

module.exports = router;
