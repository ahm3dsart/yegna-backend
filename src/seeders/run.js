const mysql = require('mysql2/promise');
const fs = require('fs');
const path = require('path');
require('dotenv').config();

async function runSeeder() {
  const connection = await mysql.createConnection({
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || 'yegna_db',
    multipleStatements: true
  });

  try {
    console.log('🌱 Running database seeder...');
    
    // Check if data already exists
    const [businessCount] = await connection.query('SELECT COUNT(*) as count FROM businesses');
    
    if (businessCount[0].count > 0) {
      console.log('ℹ️ Data already exists. Skipping seed...');
      return;
    }

    const sql = fs.readFileSync(path.join(__dirname, 'businesses_seed.sql'), 'utf8');
    await connection.query(sql);
    console.log('✅ Database seeding completed successfully!');
  } catch (error) {
    if (error.message.includes('Duplicate entry')) {
      console.log('ℹ️ Data already exists. Skipping seed...');
    } else {
      console.error('❌ Seeding failed:', error.message);
    }
  } finally {
    await connection.end();
  }
}

runSeeder();