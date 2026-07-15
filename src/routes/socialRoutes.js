const express = require('express');
const router  = express.Router();
const { protect } = require('../middleware/auth');
const c = require('../controllers/socialController');

// ── User search (must be BEFORE /:userId routes to avoid param conflict) ──────
router.get('/users/search', protect, c.searchUsers);

// ── Follow / Unfollow ─────────────────────────────────────────────────────────
router.post  ('/follow/:userId',        protect, c.follow);
router.delete('/follow/:userId',        protect, c.unfollow);
router.get   ('/follow/:userId/status', protect, c.followStatus);

// ── Follower / Following / Friends lists ──────────────────────────────────────
router.get('/users/:userId/followers', protect, c.getFollowers);
router.get('/users/:userId/following', protect, c.getFollowing);
router.get('/users/:userId/friends',   protect, c.getFriends);

// ── Public profile ────────────────────────────────────────────────────────────
router.get('/users/:userId/profile', protect, c.getPublicProfile);

// ── Friends discover feed ─────────────────────────────────────────────────────
router.get('/feed', protect, c.getFriendsFeed);

// ── Privacy ───────────────────────────────────────────────────────────────────
router.get('/privacy', protect, c.getPrivacy);
router.put ('/privacy', protect, c.updatePrivacy);

module.exports = router;
