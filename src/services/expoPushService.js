/**
 * expoPushService.js
 *
 * Sends push notifications via Expo Push API (https://docs.expo.dev/push-notifications/sending-notifications/).
 * Works alongside database notifications — after a row is inserted into `notifications` table,
 * this service delivers it to every active Expo push token for that user.
 *
 * Expo Push API endpoint:
 *   POST https://exp.host/--/api/v2/push/send
 *   Accepts up to 100 messages per request (chunked internally if needed).
 *
 * Called from:
 *   - socialController.createNotification (follow, activity_*, review etc.)
 *   - notificationsController.createNotification (legacy)
 *   - Anywhere else a push should be triggered.
 */

const db = require('../config/database');

const EXPO_PUSH_ENDPOINT = 'https://exp.host/--/api/v2/push/send';
const CHUNK_SIZE = 100; // max per Expo docs

/**
 * Fetch all active push tokens for a given user_id.
 * @param {number} userId
 * @returns {Promise<string[]>}
 */
async function getActiveTokens(userId) {
  if (!userId) return [];
  try {
    const [rows] = await db.execute(
      `SELECT token FROM push_tokens WHERE user_id = ? AND is_active = TRUE`,
      [userId]
    );
    return rows.map(r => r.token).filter(Boolean);
  } catch (e) {
    console.error('[expoPush] Failed to load tokens for user', userId, e.message);
    return [];
  }
}

/**
 * Build the message payload sent to Expo for a single token.
 */
function buildMessage(token, title, body, data = {}) {
  const sound = data?._channel === 'promotions'
    ? null
    : 'default';

  const channelId =
    data?._channel
      ? String(data._channel)
      : 'default';

  const msg = {
    to: token,
    title: title || 'Yegna',
    body:  body  || '',
    sound,
    channelId,
    priority: 'high',
    ttl: 60 * 60 * 24, // 24h
    _contentAvailable: true,
    badge: 1,
  };

  // Strip Expo expects `data` only when present — pass through everything the caller wants
  // but drop internal keys like _channel
  const cleanData = { ...data };
  delete cleanData._channel;
  if (Object.keys(cleanData).length > 0) {
    msg.data = cleanData;
  }

  return msg;
}

/**
 * Actually send a batch of messages to Expo.
 * Returns the number of tokens successfully accepted by Expo.
 */
async function sendBatch(batch) {
  if (!batch.length) return 0;
  try {
    const res = await fetch(EXPO_PUSH_ENDPOINT, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Accept-Encoding': 'gzip, deflate',
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(batch),
    });

    const payload = await res.json().catch(() => ({}));

    // Handle individual ticket response — mark failed tokens (invalid / inactive)
    if (Array.isArray(payload?.data)) {
      for (let i = 0; i < payload.data.length; i++) {
        const ticket = payload.data[i];
        const original = batch[i];
        const status = ticket?.status;
        const details = ticket?.details?.error;
        if (
          status === 'error' &&
          (details === 'DeviceNotRegistered' || details === 'InvalidCredentials' ||
           details === 'MessageTooBig' || details === 'MessageRateExceeded')
        ) {
          // Mark token inactive so we don't keep trying it.
          if (original?.to) {
            db.execute(
              `UPDATE push_tokens SET is_active = FALSE WHERE token = ?`,
              [String(original.to)]
            ).catch(() => {});
          }
        }
      }
    }

    const accepted = Array.isArray(payload?.data)
      ? payload.data.filter(t => t?.status === 'ok').length
      : 0;

    if (!res.ok) {
      console.warn('[expoPush] Expo HTTP', res.status, JSON.stringify(payload).slice(0, 300));
    }
    return accepted;
  } catch (e) {
    console.error('[expoPush] sendBatch failed:', e.message);
    return 0;
  }
}

/**
 * Public — send a push notification to all active tokens of a user.
 * Safe to call even if the user has 0 tokens (it just returns 0).
 *
 * @param {number}   userId      target user id
 * @param {string}   title       notification title
 * @param {string}   body        notification body
 * @param {object}   data        custom data payload (merged with sender_id, business_id, type etc.)
 * @returns {Promise<number>}      number of pushes accepted by Expo
 */
async function sendPushToUser(userId, title, body, data = {}) {
  const tokens = await getActiveTokens(userId);
  if (!tokens.length) return 0;

  const messages = tokens
    .map(tok => buildMessage(tok, title, body, data));

  let accepted = 0;
  for (let i = 0; i < messages.length; i += CHUNK_SIZE) {
    const chunk = messages.slice(i, i + CHUNK_SIZE);
    accepted += await sendBatch(chunk);
  }
  return accepted;
}

/**
 * Store / upsert a push token. Called from POST /user/push-token.
 */
async function upsertToken(userId, token, platform, deviceInfo) {
  if (!userId || !token) return false;
  try {
    await db.execute(
      `INSERT INTO push_tokens (user_id, token, platform, device_info, is_active)
       VALUES (?, ?, ?, ?, TRUE)
       ON DUPLICATE KEY UPDATE
         user_id    = VALUES(user_id),
         platform   = COALESCE(VALUES(platform), platform),
         device_info = COALESCE(VALUES(device_info), device_info),
         is_active  = TRUE,
         updated_at = CURRENT_TIMESTAMP`,
      [userId, token, platform || null, deviceInfo || null]
    );
    return true;
  } catch (e) {
    console.error('[expoPush] upsertToken failed:', e.message);
    return false;
  }
}

/**
 * Deactivate (soft-delete a token. Called on logout or when the backend detects DeviceNotRegistered.
 */
async function deactivateToken(userId, token) {
  try {
    await db.execute(
      `UPDATE push_tokens SET is_active = FALSE WHERE user_id = ? AND token = ?`,
      [userId, token]
    );
    return true;
  } catch (e) {
    console.error('[expoPush] deactivateToken failed:', e.message);
    return false;
  }
}

module.exports = {
  sendPushToUser,
  upsertToken,
  deactivateToken,
};
