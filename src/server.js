const express = require('express');
const cors = require('cors');
const path = require('path');
require('dotenv').config();

const businessRoutes      = require('./routes/businessRoutes');
const authRoutes          = require('./routes/authRoutes');
const userRoutes          = require('./routes/userRoutes');
const searchRoutes        = require('./routes/searchRoutes');
const notificationRoutes  = require('./routes/notificationRoutes');
const socialRoutes        = require('./routes/socialRoutes');
const ownerRoutes         = require('./routes/ownerRoutes');

const app = express();

// Middleware
app.use(cors({
  origin: '*',
  methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
  allowedHeaders: ['Content-Type', 'Authorization'],
}));
app.use(express.json({ limit: '10mb' }));
app.use(express.urlencoded({ extended: true, limit: '10mb' }));

// Serve static images from uploads folder
app.use('/uploads', express.static(path.join(__dirname, '../uploads')));

// Routes
app.use('/api/businesses', businessRoutes);
try {
  app.use('/api/auth', authRoutes);
  console.log('✅ authRoutes loaded');
} catch (e) {
  console.error('❌ authRoutes FAILED:', e.message);
  app.use('/api/auth', (req, res) => res.status(500).json({ success: false, message: 'Auth service error: ' + e.message }));
}
app.use('/api/user', userRoutes);
app.use('/api/search', searchRoutes);
app.use('/api/notifications', notificationRoutes);
app.use('/api/social', socialRoutes);
app.use('/api/owner', ownerRoutes);

// Health check
app.get('/api/health', (req, res) => {
  res.json({
    status: 'OK',
    timestamp: new Date().toISOString(),
    version: '2.0.0',
    environment: process.env.NODE_ENV || 'development',
  });
});

app.get('/', (req, res) => {
  res.json({
    message: 'Yegna API is running!',
    version: '2.0.0',
    endpoints: {
      businesses: '/api/businesses',
      auth: '/api/auth',
      user: '/api/user',
      search: '/api/search',
      notifications: '/api/notifications',
    },
  });
});

// Error handling middleware
app.use((err, req, res, next) => {
  console.error('Error:', err.stack);
  res.status(500).json({
    success: false,
    message: 'Something went wrong!',
    error: process.env.NODE_ENV === 'development' ? err.message : undefined,
  });
});

// 404 handler
app.use((req, res) => {
  res.status(404).json({ success: false, message: 'Route not found' });
});

// Export for Phusion Passenger (Plesk) — Passenger calls the app directly
// app.listen() is only used when running locally with `node src/server.js`
if (require.main === module) {
  const PORT = process.env.PORT || 5000;
  app.listen(PORT, '0.0.0.0', () => {
    console.log(`\n🚀 Yegna API Server running on http://0.0.0.0:${PORT}`);
    console.log(`📊 Environment: ${process.env.NODE_ENV || 'development'}`);
  });
}

module.exports = app;
