const nodemailer = require('nodemailer');
const pool = require('../config/database');
require('dotenv').config();

// ── Transporter ──────────────────────────────────────────────────────────────
const transporter = nodemailer.createTransport({
  service: 'gmail',
  auth: {
    user: process.env.EMAIL_FROM || 'yegnaapp@gmail.com',
    pass: process.env.EMAIL_PASSWORD, // App password from Gmail settings
  },
});

// ── Generate 6-digit OTP ─────────────────────────────────────────────────────
function generateOTP() {
  return Math.floor(100000 + Math.random() * 900000).toString();
}

// ── Save OTP to DB ────────────────────────────────────────────────────────────
async function saveOTP(email, code, type = 'verify') {
  // Delete old OTPs for this email+type
  await pool.execute(
    'DELETE FROM otp_codes WHERE email = ? AND type = ?',
    [email, type]
  );
  // OTP expires in 15 minutes
  const expiresAt = new Date(Date.now() + 15 * 60 * 1000);
  await pool.execute(
    'INSERT INTO otp_codes (email, code, type, expires_at) VALUES (?, ?, ?, ?)',
    [email, code, type, expiresAt]
  );
}

// ── Verify OTP ────────────────────────────────────────────────────────────────
async function verifyOTP(email, code, type = 'verify') {
  const [[row]] = await pool.execute(
    'SELECT * FROM otp_codes WHERE email = ? AND code = ? AND type = ? AND used = 0 AND expires_at > NOW()',
    [email, code, type]
  );
  if (!row) return false;
  await pool.execute('UPDATE otp_codes SET used = 1 WHERE id = ?', [row.id]);
  return true;
}

// ── Send verification email ───────────────────────────────────────────────────
async function sendVerificationEmail(email, name) {
  const code = generateOTP();
  await saveOTP(email, code, 'verify');

  const html = `
    <div style="font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto;">
      <div style="background: #FE4A49; padding: 32px; text-align: center; border-radius: 12px 12px 0 0;">
        <h1 style="color: white; margin: 0; font-size: 28px;">Yegna</h1>
        <p style="color: rgba(255,255,255,0.85); margin: 8px 0 0;">Discover the best of Ethiopia</p>
      </div>
      <div style="background: #ffffff; padding: 32px; border-radius: 0 0 12px 12px; border: 1px solid #e5e7eb;">
        <h2 style="color: #111827; margin: 0 0 8px;">Hi ${name || 'there'},</h2>
        <p style="color: #6b7280;">Use the code below to verify your email address. It expires in 15 minutes.</p>
        <div style="background: #f9fafb; border: 2px dashed #FE4A49; border-radius: 12px; padding: 24px; text-align: center; margin: 24px 0;">
          <span style="font-size: 42px; font-weight: 800; letter-spacing: 12px; color: #FE4A49;">${code}</span>
        </div>
        <p style="color: #9ca3af; font-size: 13px;">If you didn't request this, you can safely ignore this email.</p>
        <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0;">
        <p style="color: #9ca3af; font-size: 12px; text-align: center;">© ${new Date().getFullYear()} Yegna · Addis Ababa, Ethiopia</p>
      </div>
    </div>
  `;

  await transporter.sendMail({
    from: `"Yegna App" <${process.env.EMAIL_FROM || 'yegnaapp@gmail.com'}>`,
    to: email,
    subject: `${code} — Your Yegna verification code`,
    html,
  });

  return code;
}

// ── Send password reset email ─────────────────────────────────────────────────
async function sendPasswordResetEmail(email, name) {
  const code = generateOTP();
  await saveOTP(email, code, 'reset');

  const html = `
    <div style="font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto;">
      <div style="background: #FE4A49; padding: 32px; text-align: center; border-radius: 12px 12px 0 0;">
        <h1 style="color: white; margin: 0; font-size: 28px;">Yegna</h1>
      </div>
      <div style="background: #ffffff; padding: 32px; border-radius: 0 0 12px 12px; border: 1px solid #e5e7eb;">
        <h2 style="color: #111827; margin: 0 0 8px;">Password Reset</h2>
        <p style="color: #6b7280;">Hi ${name || 'there'}, use the code below to reset your password. It expires in 15 minutes.</p>
        <div style="background: #fff5f5; border: 2px dashed #FE4A49; border-radius: 12px; padding: 24px; text-align: center; margin: 24px 0;">
          <span style="font-size: 42px; font-weight: 800; letter-spacing: 12px; color: #FE4A49;">${code}</span>
        </div>
        <p style="color: #9ca3af; font-size: 13px;">If you didn't request a password reset, please ignore this email. Your password will not change.</p>
        <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0;">
        <p style="color: #9ca3af; font-size: 12px; text-align: center;">© ${new Date().getFullYear()} Yegna · Addis Ababa, Ethiopia</p>
      </div>
    </div>
  `;

  await transporter.sendMail({
    from: `"Yegna App" <${process.env.EMAIL_FROM || 'yegnaapp@gmail.com'}>`,
    to: email,
    subject: `${code} — Reset your Yegna password`,
    html,
  });

  return code;
}

module.exports = { sendVerificationEmail, sendPasswordResetEmail, verifyOTP, generateOTP, saveOTP };
