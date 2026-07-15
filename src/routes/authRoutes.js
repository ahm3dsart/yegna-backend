const express = require('express');
const router = express.Router();
const c = require('../controllers/authController');
const { protect } = require('../middleware/auth');

// ── Registration flow ─────────────────────────────────────────────────────────
router.post('/send-otp',          c.sendOTP);           // Step 1: send verification code
router.post('/verify-otp',        c.verifyEmailOTP);    // Step 2: verify code
router.get ('/check-username',    c.checkUsername);     // Step 3: check username availability
router.post('/register',          c.register);          // Step 4: create account

// ── Google OAuth ──────────────────────────────────────────────────────────────
router.post('/google',            c.googleAuth);        // Google sign-in / sign-up

// ── Sign in ───────────────────────────────────────────────────────────────────
router.post('/login',             c.login);             // username or email + password

// ── Password reset ────────────────────────────────────────────────────────────
router.post('/forgot-password',   c.forgotPassword);   // Send reset OTP
router.post('/verify-reset-otp',  c.verifyResetOTP);   // Verify reset OTP → get resetToken
router.post('/reset-password',    c.resetPassword);    // Set new password

// ── Auth check ────────────────────────────────────────────────────────────────
router.get ('/me',                protect, c.getMe);

module.exports = router;
