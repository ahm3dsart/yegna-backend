const express = require('express');
const router = express.Router();
const { protect } = require('../middleware/auth');
const {
  getMyBusinesses,
  getMyBusiness,
  createBusiness,
  updateBusiness,
  uploadPhotos,
  deletePhoto,
  upload,
} = require('../controllers/ownerController');

// All owner routes require authentication
router.use(protect);

// Business CRUD
router.get('/',         getMyBusinesses);
router.get('/:id',      getMyBusiness);
router.post('/',        createBusiness);
router.put('/:id',      updateBusiness);

// Photo management
router.post('/:id/photos',              upload.array('photos', 10), uploadPhotos);
router.delete('/:id/photos/:photoId',   deletePhoto);

module.exports = router;
