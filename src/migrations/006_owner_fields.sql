-- Add owner_id and status to businesses table
ALTER TABLE businesses
  ADD COLUMN IF NOT EXISTS owner_id INT NULL REFERENCES users(id) ON DELETE SET NULL,
  ADD COLUMN IF NOT EXISTS status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved';

-- Add index for owner lookups
CREATE INDEX IF NOT EXISTS idx_businesses_owner_id ON businesses(owner_id);

-- Add uploaded_by to photos table if missing
ALTER TABLE photos
  ADD COLUMN IF NOT EXISTS uploaded_by INT NULL REFERENCES users(id) ON DELETE SET NULL;
