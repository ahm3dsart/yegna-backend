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
const PORT = process.env.PORT || 5000;

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
app.use('/api/auth', authRoutes);
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

app.listen(PORT, '0.0.0.0', () => {
  console.log(`\n🚀 Yegna API Server running on http://0.0.0.0:${PORT}`);
  console.log(`📊 Environment: ${process.env.NODE_ENV || 'development'}`);
  console.log('\n📋 Available Endpoints:');
  console.log('   POST  /api/auth/register');
  console.log('   POST  /api/auth/login');
  console.log('   GET   /api/auth/me');
  console.log('   GET   /api/businesses');
  console.log('   GET   /api/businesses/nearby');
  console.log('   GET   /api/businesses/search');
  console.log('   GET   /api/businesses/trending');
  console.log('   GET   /api/businesses/top-rated');
  console.log('   GET   /api/businesses/recently-added');
  console.log('   GET   /api/businesses/:id');
  console.log('   GET   /api/businesses/:id/reviews');
  console.log('   POST  /api/businesses/:id/reviews');
  console.log('   POST  /api/businesses/favorite');
  console.log('   GET   /api/user/profile');
  console.log('   GET   /api/search');
  console.log('   GET   /api/notifications');
  console.log('   GET   /api/health');
});
