const db = require('../config/database');
const { sendPushToUser } = require('../services/expoPushService');

// ── helpers ──────────────────────────────────────────────────────────────────

async function areFriends(userA, userB) {
  const [rows] = await db.execute(
    `SELECT COUNT(*) AS cnt FROM follows f1
     JOIN follows f2 ON f1.follower_id = f2.following_id AND f1.following_id = f2.follower_id
     WHERE f1.follower_id = ? AND f1.following_id = ?`,
    [userA, userB]
  );
  return rows[0].cnt > 0;
}

async function createNotification(userId, type, title, message, data = {}) {
  try {
    await db.execute(
      `INSERT INTO notifications (user_id, type, title, message, data) VALUES (?, ?, ?, ?, ?)`,
      [userId, type, title, message, JSON.stringify(data)]
    );
    // ── Deliver via Expo Push so user sees it even when the app is closed ──
    sendPushToUser(userId, title, message, { ...data, type }).catch((e) =>
      console.error('Push send (social) failed:', e.message)
    );
  } catch (e) { console.error('Notification error:', e.message); }
}

async function notifyFriends(actorId, actorName, type, title, message, data = {}) {
  // Get mutual followers (friends) of the actor
  const [friends] = await db.execute(
    `SELECT f1.follower_id AS friend_id FROM follows f1
     JOIN follows f2 ON f1.follower_id = f2.following_id AND f1.following_id = f2.follower_id
     WHERE f1.following_id = ?`,
    [actorId]
  );
  for (const { friend_id } of friends) {
    await createNotification(friend_id, type, title, message, data);
  }
}

// ── Follow / Unfollow ─────────────────────────────────────────────────────────

exports.follow = async (req, res) => {
  const followerId   = req.userId;
  const followingId  = parseInt(req.params.userId);

  if (followerId === followingId)
    return res.status(400).json({ success: false, message: "You can't follow yourself." });

  try {
    await db.execute(
      `INSERT IGNORE INTO follows (follower_id, following_id) VALUES (?, ?)`,
      [followerId, followingId]
    );

    // Check if they are now mutual (friends)
    const friends = await areFriends(followerId, followingId);

    // Notify the person being followed — include sender_id and is_mutual for the follow-back button
    const [actor] = await db.execute(`SELECT name FROM users WHERE id = ?`, [followerId]);
    const actorName = actor[0]?.name || 'Someone';
    await createNotification(
      followingId, 'follow',
      friends ? `${actorName} and you are now friends! 🎉` : `${actorName} started following you`,
      friends
        ? `You and ${actorName} follow each other — you're now friends.`
        : `${actorName} is now following you. Follow back to become friends!`,
      { sender_id: followerId, actor_id: followerId, is_mutual: friends }
    );

    const [[{ followers }]] = await db.execute(
      `SELECT COUNT(*) AS followers FROM follows WHERE following_id = ?`, [followingId]
    );
    const [[{ following }]] = await db.execute(
      `SELECT COUNT(*) AS following FROM follows WHERE follower_id = ?`, [followingId]
    );

    return res.json({ success: true, data: { is_following: true, is_friend: friends, followers, following } });
  } catch (e) {
    console.error(e);
    return res.status(500).json({ success: false, message: 'Server error' });
  }
};

exports.unfollow = async (req, res) => {
  const followerId  = req.userId;
  const followingId = parseInt(req.params.userId);
  try {
    await db.execute(
      `DELETE FROM follows WHERE follower_id = ? AND following_id = ?`,
      [followerId, followingId]
    );
    return res.json({ success: true, data: { is_following: false, is_friend: false } });
  } catch (e) {
    return res.status(500).json({ success: false, message: 'Server error' });
  }
};

// ── Follow status ─────────────────────────────────────────────────────────────

exports.followStatus = async (req, res) => {
  const myId     = req.userId;
  const targetId = parseInt(req.params.userId);
  try {
    const [[{ is_following }]] = await db.execute(
      `SELECT COUNT(*) AS is_following FROM follows WHERE follower_id = ? AND following_id = ?`,
      [myId, targetId]
    );
    const [[{ is_followed_back }]] = await db.execute(
      `SELECT COUNT(*) AS is_followed_back FROM follows WHERE follower_id = ? AND following_id = ?`,
      [targetId, myId]
    );
    const [[{ followers }]] = await db.execute(
      `SELECT COUNT(*) AS followers FROM follows WHERE following_id = ?`, [targetId]
    );
    const [[{ following }]] = await db.execute(
      `SELECT COUNT(*) AS following FROM follows WHERE follower_id = ?`, [targetId]
    );
    const [[{ friends }]] = await db.execute(
      `SELECT COUNT(*) AS friends FROM follows f1
       JOIN follows f2 ON f1.follower_id = f2.following_id AND f1.following_id = f2.follower_id
       WHERE f1.follower_id = ?`, [targetId]
    );
    return res.json({
      success: true,
      data: {
        is_following: is_following > 0,
        is_friend: is_following > 0 && is_followed_back > 0,
        followers, following, friends,
      }
    });
  } catch (e) {
    return res.status(500).json({ success: false, message: 'Server error' });
  }
};

// ── Followers / Following lists ───────────────────────────────────────────────

exports.getFollowers = async (req, res) => {
  const targetId = parseInt(req.params.userId);
  const myId     = req.userId;
  try {
    const [rows] = await db.execute(
      `SELECT u.id, u.name, u.avatar_url, u.bio,
              (SELECT COUNT(*) FROM follows WHERE follower_id = ? AND following_id = u.id) AS is_following
       FROM follows f
       JOIN users u ON f.follower_id = u.id
       WHERE f.following_id = ?
       ORDER BY f.created_at DESC`,
      [myId || 0, targetId]
    );
    return res.json({ success: true, data: rows });
  } catch (e) {
    return res.status(500).json({ success: false, message: 'Server error' });
  }
};

exports.getFollowing = async (req, res) => {
  const targetId = parseInt(req.params.userId);
  const myId     = req.userId;
  try {
    const [rows] = await db.execute(
      `SELECT u.id, u.name, u.avatar_url, u.bio,
              (SELECT COUNT(*) FROM follows WHERE follower_id = ? AND following_id = u.id) AS is_following
       FROM follows f
       JOIN users u ON f.following_id = u.id
       WHERE f.follower_id = ?
       ORDER BY f.created_at DESC`,
      [myId || 0, targetId]
    );
    return res.json({ success: true, data: rows });
  } catch (e) {
    return res.status(500).json({ success: false, message: 'Server error' });
  }
};

exports.getFriends = async (req, res) => {
  const targetId = parseInt(req.params.userId);
  try {
    const [rows] = await db.execute(
      `SELECT u.id, u.name, u.avatar_url, u.bio
       FROM follows f1
       JOIN follows f2 ON f1.follower_id = f2.following_id AND f1.following_id = f2.follower_id
       JOIN users u ON f1.following_id = u.id
       WHERE f1.follower_id = ?
       ORDER BY f1.created_at DESC`,
      [targetId]
    );
    return res.json({ success: true, data: rows });
  } catch (e) {
    return res.status(500).json({ success: false, message: 'Server error' });
  }
};

// ── Public profile ────────────────────────────────────────────────────────────

exports.getPublicProfile = async (req, res) => {
  const targetId = parseInt(req.params.userId);
  const myId     = req.userId;
  try {
    const [[user]] = await db.execute(
      `SELECT id, name, avatar_url, bio, level, points, is_verified, created_at FROM users WHERE id = ?`,
      [targetId]
    );
    if (!user) return res.status(404).json({ success: false, message: 'User not found' });

    const [[{ followers }]] = await db.execute(
      `SELECT COUNT(*) AS followers FROM follows WHERE following_id = ?`, [targetId]
    );
    const [[{ following }]] = await db.execute(
      `SELECT COUNT(*) AS following FROM follows WHERE follower_id = ?`, [targetId]
    );
    const [[{ friends }]] = await db.execute(
      `SELECT COUNT(*) AS friends FROM follows f1
       JOIN follows f2 ON f1.follower_id = f2.following_id AND f1.following_id = f2.follower_id
       WHERE f1.follower_id = ?`, [targetId]
    );
    const [[{ review_count }]] = await db.execute(
      `SELECT COUNT(*) AS review_count FROM reviews WHERE user_id = ?`, [targetId]
    );

    let is_following = false, is_friend = false;
    if (myId) {
      const [[{ f }]] = await db.execute(
        `SELECT COUNT(*) AS f FROM follows WHERE follower_id = ? AND following_id = ?`, [myId, targetId]
      );
      const [[{ b }]] = await db.execute(
        `SELECT COUNT(*) AS b FROM follows WHERE follower_id = ? AND following_id = ?`, [targetId, myId]
      );
      is_following = f > 0;
      is_friend    = f > 0 && b > 0;
    }

    // Get privacy prefs
    const [[privacy]] = await db.execute(
      `SELECT * FROM user_privacy WHERE user_id = ?`, [targetId]
    );

    // Check visibility: can myId see this profile's activity?
    const canSee = (visibility) => {
      if (!visibility || visibility === 'everyone' || visibility === 'public') return true;
      if (!myId || myId === targetId) return myId === targetId;
      if (visibility === 'friends') return is_friend;
      if (visibility === 'followers') return is_following;
      return false;
    };

    let reviews = [], visited = [];
    if (canSee(privacy?.reviews_visibility)) {
      const [r] = await db.execute(
        `SELECT r.*, b.name AS business_name FROM reviews r
         JOIN businesses b ON r.business_id = b.id
         WHERE r.user_id = ? ORDER BY r.created_at DESC LIMIT 20`, [targetId]
      );
      reviews = r;
    }
    if (canSee(privacy?.visited_visibility)) {
      const [v] = await db.execute(
        `SELECT b.* FROM visits v JOIN businesses b ON v.business_id = b.id
         WHERE v.user_id = ? ORDER BY v.visited_at DESC LIMIT 20`, [targetId]
      );
      visited = v;
    }

    return res.json({
      success: true,
      data: {
        user: { ...user, followers, following, friends, review_count },
        is_following, is_friend,
        reviews, visited,
      }
    });
  } catch (e) {
    console.error(e);
    return res.status(500).json({ success: false, message: 'Server error' });
  }
};

// ── Activity feed (friends discover) ─────────────────────────────────────────

exports.getFriendsFeed = async (req, res) => {
  const myId   = req.userId;
  const limit  = parseInt(req.query.limit)  || 20;
  const offset = parseInt(req.query.offset) || 0;
  try {
    // Pull activity from everyone I follow, respecting visibility.
    // NOTE: db.execute does NOT cast integers correctly when mixed with LIMIT ?, OFFSET ? on MySQL2.
    // Use db.query (which supports positional ? fine without strict type binding) to avoid the
    // "you have an error in your SQL syntax near '?, offset ?'" error that returns HTTP 500.
    const [rows] = await db.query(
      `SELECT
         af.id, af.type, af.caption, af.rating, af.photo_count, af.created_at,
         af.visibility,
         u.id AS user_id, u.name AS user_name, u.avatar_url,
         b.id AS business_id, b.name AS business_name,
         b.category, b.image_url, b.address, b.city, b.rating AS business_rating,
         -- is this person a friend (mutual follow)?
         (SELECT COUNT(*) FROM follows f2
          WHERE f2.follower_id = af.user_id AND f2.following_id = ?) AS is_friend
       FROM activity_feed af
       JOIN users u        ON af.user_id     = u.id
       JOIN businesses b   ON af.business_id = b.id
       JOIN follows f      ON f.follower_id  = ? AND f.following_id = af.user_id
       WHERE (
         af.visibility = 'everyone'
         OR (af.visibility = 'friends' AND (
           SELECT COUNT(*) FROM follows f3
           WHERE f3.follower_id = af.user_id AND f3.following_id = ?
         ) > 0)
       )
       ORDER BY af.created_at DESC
       LIMIT ? OFFSET ?`,
      [myId, myId, myId, limit, offset]
    );
    return res.json({ success: true, data: rows, count: rows.length });
  } catch (e) {
    console.error(e);
    return res.status(500).json({ success: false, message: 'Server error', error: e.message });
  }
};

// ── Create activity (called internally + from routes) ────────────────────────

exports.createActivity = async (userId, type, businessId, referenceId, caption, rating, photoCount, visibility) => {
  try {
    await db.execute(
      `INSERT INTO activity_feed
         (user_id, type, business_id, reference_id, caption, rating, photo_count, visibility)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
      [userId, type, businessId, referenceId || null, caption || null, rating || null, photoCount || 0, visibility || 'everyone']
    );

    // Notify friends
    const [[actor]] = await db.execute(`SELECT name FROM users WHERE id = ?`, [userId]);
    const [[biz]]   = await db.execute(`SELECT name FROM businesses WHERE id = ?`, [businessId]);
    const actorName = actor?.name || 'Someone';
    const bizName   = biz?.name   || 'a place';

    const labels = {
      review: `${actorName} reviewed ${bizName}`,
      visit:  `${actorName} visited ${bizName}`,
      photo:  `${actorName} uploaded photos at ${bizName}`,
      rating: `${actorName} rated ${bizName}`,
    };
    await notifyFriends(userId, actorName, `activity_${type}`, labels[type] || labels.review,
      labels[type] || labels.review,
      { business_id: businessId, actor_id: userId, type }
    );
  } catch (e) { console.error('createActivity error:', e.message); }
};

// ── Privacy settings ──────────────────────────────────────────────────────────

exports.getPrivacy = async (req, res) => {
  const userId = req.userId;
  try {
    const [[priv]] = await db.execute(
      `SELECT * FROM user_privacy WHERE user_id = ?`, [userId]
    );
    if (!priv) {
      // Insert defaults
      await db.execute(`INSERT IGNORE INTO user_privacy (user_id) VALUES (?)`, [userId]);
      return res.json({ success: true, data: { user_id: userId, activity_visibility: 'everyone', reviews_visibility: 'everyone', photos_visibility: 'everyone', visited_visibility: 'everyone', saved_visibility: 'friends', followers_visibility: 'public' } });
    }
    return res.json({ success: true, data: priv });
  } catch (e) {
    return res.status(500).json({ success: false, message: 'Server error' });
  }
};

exports.updatePrivacy = async (req, res) => {
  const userId  = req.userId;
  const allowed = ['activity_visibility','reviews_visibility','photos_visibility','visited_visibility','saved_visibility','followers_visibility'];
  const fields  = Object.keys(req.body).filter(k => allowed.includes(k));
  if (!fields.length) return res.status(400).json({ success: false, message: 'No valid fields' });
  try {
    await db.execute(`INSERT IGNORE INTO user_privacy (user_id) VALUES (?)`, [userId]);
    const sets  = fields.map(f => `${f} = ?`).join(', ');
    const vals  = fields.map(f => req.body[f]);
    await db.execute(`UPDATE user_privacy SET ${sets} WHERE user_id = ?`, [...vals, userId]);
    return res.json({ success: true, message: 'Privacy updated' });
  } catch (e) {
    return res.status(500).json({ success: false, message: 'Server error' });
  }
};

// ── Search users ──────────────────────────────────────────────────────────────

exports.searchUsers = async (req, res) => {
  const myId = req.userId;
  const q    = `%${req.query.q || ''}%`;
  try {
    const [rows] = await db.execute(
      `SELECT u.id, u.name, u.avatar_url, u.bio,
              (SELECT COUNT(*) FROM follows WHERE following_id = u.id) AS followers,
              (SELECT COUNT(*) FROM follows WHERE follower_id = ? AND following_id = u.id) AS is_following
       FROM users u
       WHERE u.name LIKE ? AND u.id != ?
       ORDER BY followers DESC
       LIMIT 30`,
      [myId || 0, q, myId || 0]
    );
    return res.json({ success: true, data: rows });
  } catch (e) {
    return res.status(500).json({ success: false, message: 'Server error' });
  }
};
