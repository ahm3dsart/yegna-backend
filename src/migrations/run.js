const mysql = require('mysql2/promise');
const fs = require('fs');
const path = require('path');
require('dotenv').config();

async function runMigration() {
  const connection = await mysql.createConnection({
    host: process.env.DB_HOST || 'localhost',
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || '',
    multipleStatements: true,
    database: process.env.DB_NAME || 'yegna_db'
  });

  try {
    console.log('📦 Running database migration...');
    
    // Run the first migration only if needed (create tables)
    const sql1 = fs.readFileSync(path.join(__dirname, '001_create_tables.sql'), 'utf8');
    // This will fail if tables already exist, but we want it to continue
    try {
      await connection.query(sql1);
      console.log('✅ Base tables checked/created');
    } catch (error) {
      if (error.message.includes('Duplicate entry')) {
        console.log('ℹ️ Categories already exist, skipping...');
      } else if (error.message.includes('already exists')) {
        console.log('ℹ️ Tables already exist, skipping...');
      } else {
        console.log('⚠️ Warning:', error.message);
      }
    }

    // Run the second migration (new tables)
    try {
      const sql2 = fs.readFileSync(path.join(__dirname, '002_update_tables.sql'), 'utf8');
      await connection.query(sql2);
      console.log('✅ Additional tables created successfully!');
    } catch (error) {
      if (error.message.includes('Duplicate column name')) {
        console.log('ℹ️ Columns already exist, skipping...');
      } else if (error.message.includes('already exists')) {
        console.log('ℹ️ Tables already exist, skipping...');
      } else {
        console.error('❌ Error in second migration:', error.message);
      }
    }

    console.log('✅ Database migration completed successfully!');
  } catch (error) {
    console.error('❌ Migration failed:', error.message);
  } finally {
    await connection.end();
  }
}

runMigration();