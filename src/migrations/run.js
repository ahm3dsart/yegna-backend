const mysql = require('mysql2/promise');
const fs = require('fs');
const path = require('path');
require('dotenv').config();

async function runMigrations() {
  const dbName = process.env.DB_NAME || 'yegna';
  const dbPort = parseInt(process.env.DB_PORT || '3306', 10);

  // Connect directly to the existing database (no CREATE DATABASE needed for remote DBs)
  const connection = await mysql.createConnection({
    host:     process.env.DB_HOST     || 'localhost',
    port:     dbPort,
    user:     process.env.DB_USER     || 'root',
    password: process.env.DB_PASSWORD || '',
    database: dbName,
    multipleStatements: true,
    connectTimeout: 30000,
  });

  console.log('Running database migrations...');

  // Get all .sql migration files sorted by name
  const migrationsDir = path.join(__dirname);
  const files = fs.readdirSync(migrationsDir)
    .filter(f => f.endsWith('.sql'))
    .sort();

  for (const file of files) {
    try {
      const sql = fs.readFileSync(path.join(migrationsDir, file), 'utf8');
      await connection.query(sql);
      console.log(`OK: ${file}`);
    } catch (err) {
      // Ignore "already exists" and duplicate column errors — idempotent
      const msg = err.message || '';
      if (
        msg.includes('already exists') ||
        msg.includes('Duplicate column') ||
        msg.includes('Duplicate entry') ||
        msg.includes('Multiple primary key')
      ) {
        console.log(`SKIP (already applied): ${file}`);
      } else {
        console.error(`ERROR in ${file}:`, msg);
      }
    }
  }

  console.log('Migrations complete.');
  await connection.end();
}

runMigrations().catch(err => {
  console.error('Migration failed:', err.message);
  process.exit(1);
});
