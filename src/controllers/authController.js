const User = require('../models/User');
const jwt = require('jsonwebtoken');
const { OAuth2Client } = require('google-auth-library');
const { sendVerificationEmail, sendPasswordResetEmail, verifyOTP } = require('../services/emailService');
require('dotenv').config();

const CLIENT_ID = process.env.GOOGLE_CLIENT_ID || '';
const googleClient = new OAuth2Client(CLIENT_ID);

const generateToken = (userId) =>
  jwt.sign({ id: userId }, process.env.JWT_SECRET || 'yegna_secret_key', { expiresIn: '30d' });

// ── Helpers ───────────────────────────────────────────────────────────────────
function validateUsername(username) {
  if (!username || username.length < 3 || username.length > 30) return 'Username must be 3–30 characters.';
  if (!/^[a-zA-Z0-9_.]+$/.test(username)) return 'Username can only contain letters, numbers, _ and .';
  return null;
}

// ── Step 1: Send email verification OTP ──────────────────────────────────────
// POST /api/auth/send-otp
exports.sendOTP = async (req, res) => {
  const { email, name } = req.body;
  if (!email) return res.status(400).json({ success: false, message: 'Email is required.' });

  try {
    const existing = await User.findByEmail(email);
    if (existing && existing.email_verified) {
      return res.status(400).json({ success: false, message: 'An account with this email already exists. Please sign in.' });
    }
    await sendVerificationEmail(email, name || 'there');
    return res.json({ success: true, message: 'Verification code sent to your email.' });
  } catch (e) {
    console.error('sendOTP error:', e);
    return res.status(500).json({ success: false, message: e.message || 'Failed to send email. Please try again.' });
  }
};

// ── Step 2: Verify OTP ────────────────────────────────────────────────────────
// POST /api/auth/verify-otp
exports.verifyEmailOTP = async (req, res) => {
  const { email, code } = req.body;
  if (!email || !code) return res.status(400).json({ success: false, message: 'Email and code are required.' });

  try {
    const valid = await verifyOTP(email, code, 'verify');
    if (!valid) return res.status(400).json({ success: false, message: 'Invalid or expired code. Please request a new one.' });
    return res.json({ success: true, message: 'Email verified.' });
  } catch (e) {
    return res.status(500).json({ success: false, message: 'Verification failed.' });
  }
};

// ── Step 3: Check username availability ───────────────────────────────────────
// GET /api/auth/check-username?username=xxx
exports.checkUsername = async (req, res) => {
  const { username } = req.query;
  const err = validateUsername(username);
  if (err) return res.status(400).json({ success: false, available: false, message: err });

  try {
    const taken = await User.usernameExists(username);
    if (taken) return res.status(200).json({ success: true, available: false, message: 'Username is already taken.' });
    return res.json({ success: true, available: true, message: 'Username is available.' });
  } catch (e) {
    return res.status(500).json({ success: false, message: 'Server error.' });
  }
};

// ── Step 4: Complete registration (email path) ────────────────────────────────
// POST /api/auth/register
exports.register = async (req, res) => {
  const { name, email, username, password, birth_date } = req.body;

  if (!name || !email || !username || !password) {
    return res.status(400).json({ success: false, message: 'Name, email, username and password are required.' });
  }
  const usernameErr = validateUsername(username);
  if (usernameErr) return res.status(400).json({ success: false, message: usernameErr });
  if (password.length < 6) return res.status(400).json({ success: false, message: 'Password must be at least 6 characters.' });

  try {
    const existingEmail = await User.findByEmail(email);
    if (existingEmail) return res.status(400).json({ success: false, message: 'An account with this email already exists.' });

    const existingUsername = await User.usernameExists(username);
    if (existingUsername) return res.status(400).json({ success: false, message: 'Username is already taken. Please choose another.' });

    const userId = await User.create({ name, email, username, password, birth_date, email_verified: 1 });
    const token = generateToken(userId);
    const user = await User.findById(userId);

    return res.status(201).json({ success: true, message: 'Account created!', token, user });
  } catch (e) {
    console.error('register error:', e);
    return res.status(500).json({ success: false, message: 'Registration failed. Please try again.' });
  }
};

// ── Google OAuth sign-in / sign-up ────────────────────────────────────────────
// POST /api/auth/google
exports.googleAuth = async (req, res) => {
  const { idToken, username, birth_date } = req.body;
  if (!idToken) return res.status(400).json({ success: false, message: 'Google token is required.' });

  try {
    // Verify the Google ID token
    const ticket = await googleClient.verifyIdToken({ idToken, audience: CLIENT_ID });
    const payload = ticket.getPayload();
    const { sub: googleId, email, name, picture, birthdate } = payload;

    // Check if user already exists with this Google account
    let user = await User.findByGoogleId(googleId);
    if (user) {
      const token = generateToken(user.id);
      const userData = await User.findById(user.id);
      return res.json({ success: true, message: 'Signed in with Google.', token, user: userData, isNew: false });
    }

    // Check if email is already used by a non-Google account
    const existingEmail = await User.findByEmail(email);
    if (existingEmail && !existingEmail.google_id) {
      return res.status(400).json({
        success: false,
        message: 'An account with this email already exists. Please sign in with your password.',
      });
    }

    // New Google user — need username to complete registration
    if (!username) {
      return res.status(200).json({
        success: true,
        needsUsername: true,
        googleData: { googleId, email, name, picture, birth_date: birthdate || null },
        message: 'Please choose a username to complete your account.',
      });
    }

    const usernameErr = validateUsername(username);
    if (usernameErr) return res.status(400).json({ success: false, message: usernameErr });

    const taken = await User.usernameExists(username);
    if (taken) return res.status(400).json({ success: false, message: 'Username is already taken. Please choose another.' });

    const userId = await User.create({
      name, email, username,
      google_id: googleId,
      avatar_url: picture || null,
      birth_date: birth_date || birthdate || null,
      email_verified: 1,
    });
    const token = generateToken(userId);
    const userData = await User.findById(userId);
    return res.status(201).json({ success: true, message: 'Account created with Google!', token, user: userData, isNew: true });

  } catch (e) {
    console.error('googleAuth error:', e);
    return res.status(401).json({ success: false, message: 'Google sign-in failed. Please try again.' });
  }
};

// ── Sign in (username or email + password) ────────────────────────────────────
// POST /api/auth/login
exports.login = async (req, res) => {
  const { identifier, password } = req.body; // identifier = username or email
  if (!identifier || !password) {
    return res.status(400).json({ success: false, message: 'Username/email and password are required.' });
  }

  try {
    // Find by email or username
    let user = identifier.includes('@')
      ? await User.findByEmail(identifier)
      : await User.findByUsername(identifier);

    if (!user) {
      return res.status(401).json({ success: false, message: 'No account found with that username or email.' });
    }
    if (!user.password_hash) {
      return res.status(401).json({ success: false, message: 'This account uses Google sign-in. Please use "Continue with Google".' });
    }
    const valid = await User.comparePassword(password, user.password_hash);
    if (!valid) {
      return res.status(401).json({ success: false, message: 'Incorrect password. Please try again.' });
    }

    const token = generateToken(user.id);
    const userData = await User.findById(user.id);
    return res.json({ success: true, message: 'Signed in.', token, user: userData });

  } catch (e) {
    console.error('login error:', e);
    return res.status(500).json({ success: false, message: 'Sign in failed. Please try again.' });
  }
};

// ── Forgot password: send reset OTP ──────────────────────────────────────────
// POST /api/auth/forgot-password
exports.forgotPassword = async (req, res) => {
  const { email } = req.body;
  if (!email) return res.status(400).json({ success: false, message: 'Email is required.' });

  try {
    const user = await User.findByEmail(email);
    // Always return success to prevent email enumeration
    if (!user) {
      return res.json({ success: true, message: 'If an account exists, a reset code has been sent.' });
    }
    if (!user.password_hash) {
      return res.status(400).json({ success: false, message: 'This account uses Google sign-in and has no password to reset.' });
    }
    await sendPasswordResetEmail(email, user.name);
    return res.json({ success: true, message: 'Password reset code sent to your email.' });
  } catch (e) {
    console.error('forgotPassword error:', e);
    return res.status(500).json({ success: false, message: 'Failed to send reset email.' });
  }
};

// ── Verify reset OTP ──────────────────────────────────────────────────────────
// POST /api/auth/verify-reset-otp
exports.verifyResetOTP = async (req, res) => {
  const { email, code } = req.body;
  if (!email || !code) return res.status(400).json({ success: false, message: 'Email and code required.' });

  try {
    const valid = await verifyOTP(email, code, 'reset');
    if (!valid) return res.status(400).json({ success: false, message: 'Invalid or expired code.' });
    // Issue a short-lived reset token
    const resetToken = jwt.sign({ email, type: 'reset' }, process.env.JWT_SECRET || 'yegna_secret_key', { expiresIn: '10m' });
    return res.json({ success: true, resetToken });
  } catch (e) {
    return res.status(500).json({ success: false, message: 'Verification failed.' });
  }
};

// ── Reset password ────────────────────────────────────────────────────────────
// POST /api/auth/reset-password
exports.resetPassword = async (req, res) => {
  const { resetToken, newPassword } = req.body;
  if (!resetToken || !newPassword) return res.status(400).json({ success: false, message: 'Reset token and new password required.' });
  if (newPassword.length < 6) return res.status(400).json({ success: false, message: 'Password must be at least 6 characters.' });

  try {
    const decoded = jwt.verify(resetToken, process.env.JWT_SECRET || 'yegna_secret_key');
    if (decoded.type !== 'reset') return res.status(400).json({ success: false, message: 'Invalid reset token.' });

    const user = await User.findByEmail(decoded.email);
    if (!user) return res.status(404).json({ success: false, message: 'User not found.' });

    await User.updatePassword(user.id, newPassword);
    return res.json({ success: true, message: 'Password reset successfully. You can now sign in.' });
  } catch (e) {
    return res.status(400).json({ success: false, message: 'Reset token expired or invalid. Please request a new code.' });
  }
};

// ── Get current user ──────────────────────────────────────────────────────────
// GET /api/auth/me
exports.getMe = async (req, res) => {
  try {
    const user = await User.findById(req.userId);
    if (!user) return res.status(404).json({ success: false, message: 'User not found.' });
    return res.json({ success: true, user });
  } catch (e) {
    return res.status(500).json({ success: false, message: 'Error fetching user.' });
  }
};
