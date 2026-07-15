const jwt = require('jsonwebtoken');
require('dotenv').config();

const protect = async (req, res, next) => {
  let token;

  if (req.headers.authorization && req.headers.authorization.startsWith('Bearer')) {
    token = req.headers.authorization.split(' ')[1];
  }

  if (!token) {
    return res.status(401).json({
      success: false,
      message: 'Not authorized, no token provided'
    });
  }

  try {
    const decoded = jwt.verify(token, process.env.JWT_SECRET || 'your_super_secret_jwt_key_here');
    req.userId = decoded.id;
    next();
  } catch (error) {
    return res.status(401).json({
      success: false,
      message: 'Not authorized, invalid token'
    });
  }
};

module.exports = { protect };