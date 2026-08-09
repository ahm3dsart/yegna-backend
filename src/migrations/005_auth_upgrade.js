const mysql = require('mysql2/promise');
require('dotenv').config();

async function run() {
  const conn = await mysql.createConnection({
    host: process.env.DB_HOST || '127.0.0.1',
    port: parseInt(process.env.DB_PORT || '3306', 10),
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || 'yegna',
    multipleStatements: true,
    connectTimeout: 30000,
  });

  const statements = [
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS username VARCHAR(30) UNIQUE",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS google_id VARCHAR(200) UNIQUE",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS birth_date DATE",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) DEFAULT 0",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(30)",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS bio TEXT",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS points INT DEFAULT 0",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS level INT DEFAULT 1",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS is_verified TINYINT(1) DEFAULT 0",
    `CREATE TABLE IF NOT EXISTS otp_codes (
      id INT PRIMARY KEY AUTO_INCREMENT,
      email VARCHAR(100) NOT NULL,
      code VARCHAR(6) NOT NULL,
      type ENUM('verify','reset') NOT NULL DEFAULT 'verify',
      expires_at TIMESTAMP NOT NULL,
      used TINYINT(1) DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_email (email),
      INDEX idx_expires (expires_at)
    )`,
  ];

  for (const sql of statements) {
    try {
      await conn.execute(sql);
      console.log('OK:', sql.substring(0, 60));
    } catch (e) {
      if (e.message.includes('Duplicate column')) {
        console.log('SKIP (exists):', sql.substring(0, 60));
      } else {
        console.log('WARN:', e.message);
      }
    }
  }

  const [cols] = await conn.execute('DESCRIBE users');
  console.log('\nUsers columns:', cols.map(c => c.Field).join(', '));

  await conn.end();
  console.log('\nMigration complete.');
}

run().catch(console.error);
