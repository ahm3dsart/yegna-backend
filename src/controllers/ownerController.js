const pool = require('../config/database');
const multer = require('multer');
const path = require('path');
const fs = require('fs');

// ── Auto-run migration to ensure owner columns exist ─────────────────────────
(async () => {
  try {
    await pool.query(`ALTER TABLE businesses ADD COLUMN IF NOT EXISTS owner_id INT NULL`);
    await pool.query(`ALTER TABLE businesses ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'approved'`);
    await pool.query(`ALTER TABLE photos ADD COLUMN IF NOT EXISTS uploaded_by INT NULL`);
  } catch (e) {
    // Columns may already exist — ignore
  }
})();

// ── Multer setup for business photos ─────────────────────────────────────────
const uploadDir = path.join(__dirname, '../../uploads/businesses');
if (!fs.existsSync(uploadDir)) fs.mkdirSync(uploadDir, { recursive: true });

const storage = multer.diskStorage({
  destination: (req, file, cb) => cb(null, uploadDir),
  filename: (req, file, cb) => {
    const ext = path.extname(file.originalname);
    cb(null, `biz_${Date.now()}_${Math.random().toString(36).slice(2)}${ext}`);
  },
});
exports.upload = multer({
  storage,
  limits: { fileSize: 8 * 1024 * 1024 }, // 8MB
  fileFilter: (req, file, cb) => {
    if (file.mimetype.startsWith('image/')) cb(null, true);
    else cb(new Error('Only image files allowed'));
  },
});

// ── GET /api/owner/businesses ─────────────────────────────────────────────────
exports.getMyBusinesses = async (req, res) => {
  try {
    const [rows] = await pool.query(
      `SELECT b.*,
        (SELECT image_url FROM photos WHERE business_id = b.id AND is_primary = 1 LIMIT 1) AS image_url
       FROM businesses b
       WHERE b.owner_id = ?
       ORDER BY b.created_at DESC`,
      [req.userId]
    );
    res.json({ success: true, data: rows });
  } catch (err) {
    console.error('getMyBusinesses:', err);
    res.status(500).json({ success: false, message: 'Failed to fetch businesses' });
  }
};

// ── POST /api/owner/businesses ────────────────────────────────────────────────
exports.createBusiness = async (req, res) => {
  try {
    const {
      name, category, description, address, city,
      phone, website, price_range,
      latitude, longitude, hours,
    } = req.body;

    if (!name || !category || !address || !city) {
      return res.status(400).json({ success: false, message: 'Name, category, address and city are required.' });
    }

    const [result] = await pool.query(
      `INSERT INTO businesses
         (name, category, description, address, city, phone, website,
          price_range, latitude, longitude, owner_id, is_active, status)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'pending')`,
      [
        name, category, description || null, address, city,
        phone || null, website || null,
        price_range || null,
        latitude || null, longitude || null,
        req.userId,
      ]
    );

    const businessId = result.insertId;

    // Insert business hours if provided
    if (hours && Array.isArray(hours)) {
      const days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
      for (const day of days) {
        const h = hours.find(x => x.day === day);
        await pool.query(
          `INSERT INTO business_hours (business_id, day_of_week, open_time, close_time, is_closed)
           VALUES (?, ?, ?, ?, ?)`,
          [businessId, day, h?.open || null, h?.close || null, h?.closed ? 1 : 0]
        );
      }
    }

    res.status(201).json({
      success: true,
      message: 'Business submitted for review. It will be visible once approved.',
      data: { id: businessId },
    });
  } catch (err) {
    console.error('createBusiness:', err);
    res.status(500).json({ success: false, message: 'Failed to create business' });
  }
};

// ── PUT /api/owner/businesses/:id ─────────────────────────────────────────────
exports.updateBusiness = async (req, res) => {
  try {
    const { id } = req.params;

    // Verify ownership
    const [rows] = await pool.query(
      'SELECT id FROM businesses WHERE id = ? AND owner_id = ?',
      [id, req.userId]
    );
    if (!rows.length) {
      return res.status(403).json({ success: false, message: 'Not authorized to edit this business' });
    }

    const {
      name, category, description, address, city,
      phone, website, price_range, latitude, longitude, hours,
    } = req.body;

    await pool.query(
      `UPDATE businesses SET
         name = ?, category = ?, description = ?, address = ?, city = ?,
         phone = ?, website = ?, price_range = ?, latitude = ?, longitude = ?
       WHERE id = ?`,
      [
        name, category, description || null, address, city,
        phone || null, website || null,
        price_range || null,
        latitude || null, longitude || null,
        id,
      ]
    );

    // Update hours if provided
    if (hours && Array.isArray(hours)) {
      await pool.query('DELETE FROM business_hours WHERE business_id = ?', [id]);
      const days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
      for (const day of days) {
        const h = hours.find(x => x.day === day);
        await pool.query(
          `INSERT INTO business_hours (business_id, day_of_week, open_time, close_time, is_closed)
           VALUES (?, ?, ?, ?, ?)`,
          [id, day, h?.open || null, h?.close || null, h?.closed ? 1 : 0]
        );
      }
    }

    res.json({ success: true, message: 'Business updated successfully' });
  } catch (err) {
    console.error('updateBusiness:', err);
    res.status(500).json({ success: false, message: 'Failed to update business' });
  }
};

// ── POST /api/owner/businesses/:id/photos ─────────────────────────────────────
exports.uploadPhotos = async (req, res) => {
  try {
    const { id } = req.params;

    // Verify ownership
    const [rows] = await pool.query(
      'SELECT id FROM businesses WHERE id = ? AND owner_id = ?',
      [id, req.userId]
    );
    if (!rows.length) {
      return res.status(403).json({ success: false, message: 'Not authorized' });
    }

    if (!req.files || req.files.length === 0) {
      return res.status(400).json({ success: false, message: 'No images uploaded' });
    }

    // Check existing photo count
    const [existing] = await pool.query(
      'SELECT COUNT(*) as cnt FROM photos WHERE business_id = ?', [id]
    );
    if (existing[0].cnt + req.files.length > 10) {
      return res.status(400).json({ success: false, message: 'Maximum 10 photos per business' });
    }

    const baseUrl = `${req.protocol}://${req.get('host')}`;
    const isPrimary = existing[0].cnt === 0;

    for (let i = 0; i < req.files.length; i++) {
      const file = req.files[i];
      const imageUrl = `${baseUrl}/uploads/businesses/${file.filename}`;
      await pool.query(
        'INSERT INTO photos (business_id, image_url, is_primary, uploaded_by) VALUES (?, ?, ?, ?)',
        [id, imageUrl, isPrimary && i === 0 ? 1 : 0, req.userId]
      );
    }

    res.json({ success: true, message: `${req.files.length} photo(s) uploaded` });
  } catch (err) {
    console.error('uploadPhotos:', err);
    res.status(500).json({ success: false, message: 'Failed to upload photos' });
  }
};

// ── DELETE /api/owner/businesses/:id/photos/:photoId ──────────────────────────
exports.deletePhoto = async (req, res) => {
  try {
    const { id, photoId } = req.params;

    // Verify ownership via business
    const [rows] = await pool.query(
      'SELECT id FROM businesses WHERE id = ? AND owner_id = ?',
      [id, req.userId]
    );
    if (!rows.length) return res.status(403).json({ success: false, message: 'Not authorized' });

    const [photo] = await pool.query('SELECT * FROM photos WHERE id = ? AND business_id = ?', [photoId, id]);
    if (!photo.length) return res.status(404).json({ success: false, message: 'Photo not found' });

    // Delete file from disk
    const filename = photo[0].image_url.split('/uploads/businesses/').pop();
    const filePath = path.join(uploadDir, filename);
    if (fs.existsSync(filePath)) fs.unlinkSync(filePath);

    await pool.query('DELETE FROM photos WHERE id = ?', [photoId]);
    res.json({ success: true, message: 'Photo deleted' });
  } catch (err) {
    console.error('deletePhoto:', err);
    res.status(500).json({ success: false, message: 'Failed to delete photo' });
  }
};

// ── GET /api/owner/businesses/:id ─────────────────────────────────────────────
exports.getMyBusiness = async (req, res) => {
  try {
    const { id } = req.params;
    const [rows] = await pool.query(
      'SELECT * FROM businesses WHERE id = ? AND owner_id = ?',
      [id, req.userId]
    );
    if (!rows.length) return res.status(404).json({ success: false, message: 'Business not found' });

    const [hours] = await pool.query(
      'SELECT * FROM business_hours WHERE business_id = ? ORDER BY FIELD(day_of_week,"Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday")',
      [id]
    );
    const [photos] = await pool.query(
      'SELECT * FROM photos WHERE business_id = ? ORDER BY is_primary DESC, created_at DESC',
      [id]
    );

    res.json({ success: true, data: { ...rows[0], hours, photos } });
  } catch (err) {
    console.error('getMyBusiness:', err);
    res.status(500).json({ success: false, message: 'Failed to fetch business' });
  }
};
