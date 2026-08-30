-- =====================================================================
-- 008_RECOMMENDATIONS.SQL  —  Yegna Smart Food & Drink Recommendation
-- Safe, idempotent: use CREATE IF NOT EXISTS / INSERT IGNORE.
-- Run against the `yegna` database in phpMyAdmin.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. USER_LOCATIONS  — last GPS snapshot of each active user
--    One row per user. Updated every time the app is foregrounded
--    (after login / resume) via POST /user/location.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_locations (
  user_id      INT PRIMARY KEY,
  latitude     DECIMAL(10,7)  NOT NULL,
  longitude    DECIMAL(10,7)  NOT NULL,
  accuracy_m   FLOAT          NULL COMMENT 'metres; from expo-location',
  updated_at   TIMESTAMP      DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------
-- 2. RECOMMENDATION_HISTORY  — every recommendation ever sent.
--    Prevents duplicate same-business pushes (UNIQUE per user+biz+day).
--    Enforces the 21-day cooldown via recent_rec penalty in scoring.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS recommendation_history (
  id                INT PRIMARY KEY AUTO_INCREMENT,
  user_id           INT NOT NULL,
  business_id       INT NOT NULL,
  rec_type          ENUM('sunday','holiday','fasting','adhoc')
                    NOT NULL DEFAULT 'sunday',
  context           JSON NULL COMMENT 'e.g. {"holiday":"Enkutatash","fasting":"wednesday","score_breakdown":{...}}',
  holiday_name      VARCHAR(80) NULL,
  fasting_context   VARCHAR(40) NULL,
  notification_sent TINYINT(1) DEFAULT 1,
  created_date      DATE NOT NULL COMMENT 'Pre-computed for UNIQUE (no DATE() functional idx needed)',
  created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE,
  FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_biz_day (user_id, business_id, created_date),
  INDEX idx_user_created (user_id, created_at),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------
-- 3. RECOMMENDATION_SCHEDULE  — per-user "next due" bookkeeping.
--    Idempotent batching: a lock column prevents double-processing by
--    concurrent scheduler hits; LIMIT 100 per run keeps PHP fast.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS recommendation_schedule (
  user_id            INT PRIMARY KEY,
  last_sent_at       DATETIME NULL,
  next_due_at        DATETIME NULL COMMENT 'NULL = never scheduled; compute next Sun at account-creation HH:MM',
  processing_lock    TINYINT(1) DEFAULT 0,
  lock_expires_at    DATETIME NULL,
  updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_next_due (next_due_at, processing_lock)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------
-- 4. CALENDAR_EVENTS  — holidays & fasting periods.
--    date_type controls how the PHP resolver matches a given date:
--      FIXED_GREGORIAN   → exact mm-dd every year  (mm-dd stored in date_col)
--      YEAR_SPECIFIC     → one exact YYYY-MM-DD    (used for Eid/Eid/Mawlid/Fasika)
--      ETHIOPIAN_FIXED   → Ethiopian mm-dd (e.g. 01-01 = Enkutatash).
--                          PHP resolves to Gregorian (uses leap-shift rule).
--      FASTING_PERIOD_YEARLY  → date range, YYYY-specific or mm-dd every year
--    For YEAR_SPECIFIC we seed 2024–2028 (sources: ertale.com + autolidays).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS calendar_events (
  id            INT PRIMARY KEY AUTO_INCREMENT,
  name          VARCHAR(80) NOT NULL,
  name_amharic  VARCHAR(80) NULL,
  category      ENUM('holiday','fasting_period','fasting_day_rule')
                NOT NULL,
  date_type     ENUM('FIXED_GREGORIAN','YEAR_SPECIFIC','ETHIOPIAN_FIXED',
                     'YEAR_SPECIFIC_RANGE')
                NOT NULL,
  date_col      DATE NULL,             -- used by FIXED_GREGORIAN (year 2000 placeholder) / YEAR_SPECIFIC
  ec_month_day  CHAR(5) NULL,          -- used by ETHIOPIAN_FIXED, e.g. "01-01" Meskerem 1
  range_start   DATE NULL,             -- used by YEAR_SPECIFIC_RANGE
  range_end     DATE NULL,             -- used by YEAR_SPECIFIC_RANGE
  fasting_type  VARCHAR(40) NULL,      -- 'wed_fri' | 'great_lent' | 'filseta' | 'gahad' | 'apostles'
  importance    TINYINT DEFAULT 1,     -- 1 = normal, 2 = national (override Sunday)
  notes         VARCHAR(200) NULL,
  INDEX idx_lookup (category, date_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- =====================================================================
-- SEED: FIXED_GREGORIAN  (national public holidays, permanent dates)
-- =====================================================================
INSERT IGNORE INTO calendar_events
(name, name_amharic, category, date_type, date_col, importance, notes) VALUES
('Genna / Ethiopian Christmas',   'ገና',          'holiday','FIXED_GREGORIAN','2000-01-07', 2, 'Permanent Gregorian date per ertale.com'),
('Timket / Epiphany',             'ጥምቀት',        'holiday','FIXED_GREGORIAN','2000-01-19', 2, NULL),
('Adwa Victory Day',              'አድዋ ድል ቀን',   'holiday','FIXED_GREGORIAN','2000-03-02', 2, NULL),
('Patriots Victory Day',          'የአርበኞች ቀን',   'holiday','FIXED_GREGORIAN','2000-05-05', 2, NULL),
('International Workers Day',     'የላብ አደሮች ቀን', 'holiday','FIXED_GREGORIAN','2000-05-01', 2, NULL),
('Downfall of the Derg Regime',   'ደርግ የወደቀበት',  'holiday','FIXED_GREGORIAN','2000-05-28', 2, NULL);


-- =====================================================================
-- SEED: ETHIOPIAN_FIXED  (Enkutatash + Meskel — shift Sept 11↔12 / 27↔28)
-- =====================================================================
INSERT IGNORE INTO calendar_events
(name, name_amharic, category, date_type, ec_month_day, importance, notes) VALUES
('Enkutatash / Ethiopian New Year','እንቁጣጣሽ',      'holiday','ETHIOPIAN_FIXED','01-01', 2, 'Meskerem 1 → resolved by PHP to Sept 11 or 12'),
('Meskel / Finding of True Cross','መስቀል',         'holiday','ETHIOPIAN_FIXED','01-17', 2, 'Meskerem 17 → 16 days after Enkutatash');


-- =====================================================================
-- SEED: YEAR_SPECIFIC  (movable holidays — Eid/Fitr/Adha/Mawlid,
--                       Fasika, Siklet).
-- Sources: ertale.com (Ethiopia official locale), autolidays.com,
--          printablecalendarhub.info.
-- Multi-day observations: we seed the 2 consecutive most-likely dates
-- for each Islamic holiday so ±1 day lunar-sighting variance is covered.
-- =====================================================================

-- 2024
INSERT IGNORE INTO calendar_events (name, category, date_type, date_col, importance, notes) VALUES
('Eid al-Fitr 2024',        'holiday','YEAR_SPECIFIC','2024-04-10',2,'Lunar sighting (±1d)'),
('Eid al-Fitr 2024 (obs)','holiday','YEAR_SPECIFIC','2024-04-11',2,'Observation window'),
('Siklet / Good Friday 2024','holiday','YEAR_SPECIFIC','2024-05-03',2,'Ethiopian Orthodox Alexandrian Easter-2'),
('Fasika / Easter 2024',    'holiday','YEAR_SPECIFIC','2024-05-05',2,'Ethiopian Orthodox Tewahedo Easter'),
('Eid al-Adha 2024',        'holiday','YEAR_SPECIFIC','2024-06-16',2,'Lunar sighting (±1d)'),
('Eid al-Adha 2024 (obs)','holiday','YEAR_SPECIFIC','2024-06-17',2,'Observation window'),
('Mawlid 2024',             'holiday','YEAR_SPECIFIC','2024-09-15',2,'Lunar sighting (±1d)'),
('Mawlid 2024 (obs)',       'holiday','YEAR_SPECIFIC','2024-09-16',2,'Observation window');

-- 2025
INSERT IGNORE INTO calendar_events (name, category, date_type, date_col, importance, notes) VALUES
('Eid al-Fitr 2025',        'holiday','YEAR_SPECIFIC','2025-03-30',2,'ertale.com: Sun Mar 30 2025'),
('Eid al-Fitr 2025 (obs)','holiday','YEAR_SPECIFIC','2025-03-31',2,'Observation window'),
('Siklet / Good Friday 2025','holiday','YEAR_SPECIFIC','2025-04-18',2,'ertale.com: Fri Apr 18 2025'),
('Fasika / Easter 2025',    'holiday','YEAR_SPECIFIC','2025-04-20',2,'ertale.com: Sun Apr 20 2025'),
('Eid al-Adha 2025',        'holiday','YEAR_SPECIFIC','2025-06-06',2,'ertale.com: Fri Jun 6 2025'),
('Eid al-Adha 2025 (obs)','holiday','YEAR_SPECIFIC','2025-06-07',2,'Observation window'),
('Mawlid 2025',             'holiday','YEAR_SPECIFIC','2025-09-04',2,'ertale.com/xn--das: Thu Sep 4 2025'),
('Mawlid 2025 (obs)',       'holiday','YEAR_SPECIFIC','2025-09-05',2,'Observation window');

-- 2026
INSERT IGNORE INTO calendar_events (name, category, date_type, date_col, importance, notes) VALUES
('Eid al-Fitr 2026',        'holiday','YEAR_SPECIFIC','2026-03-20',2,'ertale.com: Fri Mar 20 2026'),
('Eid al-Fitr 2026 (obs)','holiday','YEAR_SPECIFIC','2026-03-21',2,'Observation window'),
('Siklet / Good Friday 2026','holiday','YEAR_SPECIFIC','2026-04-10',2,'ertale.com: Fri Apr 10 2026'),
('Fasika / Easter 2026',    'holiday','YEAR_SPECIFIC','2026-04-12',2,'ertale.com: Sun Apr 12 2026'),
('Eid al-Adha 2026',        'holiday','YEAR_SPECIFIC','2026-05-27',2,'ertale.com: Wed May 27 2026'),
('Eid al-Adha 2026 (obs)','holiday','YEAR_SPECIFIC','2026-05-28',2,'Observation window + Derg day overlap tolerated'),
('Mawlid 2026',             'holiday','YEAR_SPECIFIC','2026-08-25',2,'autolidays/uneca: Tue Aug 25 2026'),
('Mawlid 2026 (alt)',       'holiday','YEAR_SPECIFIC','2026-08-26',2,'ertale tentative: Wed Aug 26 2026');

-- 2027
INSERT IGNORE INTO calendar_events (name, category, date_type, date_col, importance, notes) VALUES
('Eid al-Fitr 2027',        'holiday','YEAR_SPECIFIC','2027-03-09',2,'ertale.com: Tue Mar 9 2027'),
('Eid al-Fitr 2027 (obs)','holiday','YEAR_SPECIFIC','2027-03-10',2,'Observation window'),
('Siklet / Good Friday 2027','holiday','YEAR_SPECIFIC','2027-04-30',2,'ertale.com: Fri Apr 30 2027'),
('Fasika / Easter 2027',    'holiday','YEAR_SPECIFIC','2027-05-02',2,'ertale.com: Sun May 2 2027'),
('Eid al-Adha 2027',        'holiday','YEAR_SPECIFIC','2027-05-17',2,'ertale.com: Mon May 17 2027'),
('Eid al-Adha 2027 (obs)','holiday','YEAR_SPECIFIC','2027-05-18',2,'Observation window'),
('Mawlid 2027',             'holiday','YEAR_SPECIFIC','2027-08-14',2,'ertale.com: Sat Aug 14 2027'),
('Mawlid 2027 (obs)',       'holiday','YEAR_SPECIFIC','2027-08-15',2,'Observation window');

-- 2028  (estimates based on known Hijri drift: 10-12 days earlier/year;
--        Fasika/Siklet: Ethiopian Orthodox Alexandrian computus published values)
INSERT IGNORE INTO calendar_events (name, category, date_type, date_col, importance, notes) VALUES
('Eid al-Fitr 2028',        'holiday','YEAR_SPECIFIC','2028-02-26',2,'Hijri estimate (±1d)'),
('Eid al-Fitr 2028 (obs)','holiday','YEAR_SPECIFIC','2028-02-27',2,'Observation window'),
('Siklet / Good Friday 2028','holiday','YEAR_SPECIFIC','2028-04-14',2,'Alexandrian computus published value'),
('Fasika / Easter 2028',    'holiday','YEAR_SPECIFIC','2028-04-16',2,'Alexandrian computus published value'),
('Eid al-Adha 2028',        'holiday','YEAR_SPECIFIC','2028-05-06',2,'Hijri estimate (±1d)'),
('Eid al-Adha 2028 (obs)','holiday','YEAR_SPECIFIC','2028-05-07',2,'Observation window'),
('Mawlid 2028',             'holiday','YEAR_SPECIFIC','2028-08-03',2,'Hijri estimate (±1d)'),
('Mawlid 2028 (obs)',       'holiday','YEAR_SPECIFIC','2028-08-04',2,'Observation window');


-- =====================================================================
-- SEED: YEAR_SPECIFIC_RANGE  (major Ethiopian Orthodox FASTING PERIODS)
-- Computed from ertale Fasika dates + standard Orthodox durations.
-- =====================================================================

-- Great Lent (Abiy Tsome): 55 days. Start = Fasika − 54 days; End = Fasika − 1 day.
INSERT IGNORE INTO calendar_events
(name, name_amharic, category, date_type, range_start, range_end, fasting_type, importance, notes) VALUES
('Great Lent 2024', 'ኣቢይ ጾም','fasting_period','YEAR_SPECIFIC_RANGE',
  DATE_SUB('2024-05-05',INTERVAL 54 DAY), DATE_SUB('2024-05-05',INTERVAL 1 DAY),
  'great_lent', 2, 'Ethiopian Orthodox 55-day Lenten fast'),
('Great Lent 2025', 'ኣቢይ ጾም','fasting_period','YEAR_SPECIFIC_RANGE',
  DATE_SUB('2025-04-20',INTERVAL 54 DAY), DATE_SUB('2025-04-20',INTERVAL 1 DAY),
  'great_lent', 2, NULL),
('Great Lent 2026', 'ኣቢይ ጾም','fasting_period','YEAR_SPECIFIC_RANGE',
  DATE_SUB('2026-04-12',INTERVAL 54 DAY), DATE_SUB('2026-04-12',INTERVAL 1 DAY),
  'great_lent', 2, NULL),
('Great Lent 2027', 'ኣቢይ ጾም','fasting_period','YEAR_SPECIFIC_RANGE',
  DATE_SUB('2027-05-02',INTERVAL 54 DAY), DATE_SUB('2027-05-02',INTERVAL 1 DAY),
  'great_lent', 2, NULL),
('Great Lent 2028', 'ኣቢይ ጾም','fasting_period','YEAR_SPECIFIC_RANGE',
  DATE_SUB('2028-04-16',INTERVAL 54 DAY), DATE_SUB('2028-04-16',INTERVAL 1 DAY),
  'great_lent', 2, NULL),

-- Fast of Gahad (Advent / Prophets): 40 days before Genna (Jan 7).  Start = Nov 28, End = Jan 6.
('Gahad / Advent Fast 2024-25','ጋሃድ','fasting_period','YEAR_SPECIFIC_RANGE',
  '2024-11-28','2025-01-06','gahad',1,NULL),
('Gahad / Advent Fast 2025-26','ጋሃድ','fasting_period','YEAR_SPECIFIC_RANGE',
  '2025-11-28','2026-01-06','gahad',1,NULL),
('Gahad / Advent Fast 2026-27','ጋሃድ','fasting_period','YEAR_SPECIFIC_RANGE',
  '2026-11-28','2027-01-06','gahad',1,NULL),
('Gahad / Advent Fast 2027-28','ጋሃድ','fasting_period','YEAR_SPECIFIC_RANGE',
  '2027-11-28','2028-01-06','gahad',1,NULL),
('Gahad / Advent Fast 2028-29','ጋሃድ','fasting_period','YEAR_SPECIFIC_RANGE',
  '2028-11-28','2029-01-06','gahad',1,NULL),

-- Fast of Filseta (Dormition): ~Aug 7 – Aug 22 (Gregorian).
('Filseta Fast 2024','ፍልሰታ','fasting_period','YEAR_SPECIFIC_RANGE',
  '2024-08-07','2024-08-22','filseta',1,NULL),
('Filseta Fast 2025','ፍልሰታ','fasting_period','YEAR_SPECIFIC_RANGE',
  '2025-08-07','2025-08-22','filseta',1,NULL),
('Filseta Fast 2026','ፍልሰታ','fasting_period','YEAR_SPECIFIC_RANGE',
  '2026-08-07','2026-08-22','filseta',1,NULL),
('Filseta Fast 2027','ፍልሰታ','fasting_period','YEAR_SPECIFIC_RANGE',
  '2027-08-07','2027-08-22','filseta',1,NULL),
('Filseta Fast 2028','ፍልሰታ','fasting_period','YEAR_SPECIFIC_RANGE',
  '2028-08-07','2028-08-22','filseta',1,NULL),

-- Fast of the Apostles (Hawariat): 10–40 days after Pentecost (Fasika+50 days + 1),
-- ending on the feast of Peter & Paul (approx Jul 12 in Ethiopian tradition).
('Apostles Fast 2024','ሐዋርያት','fasting_period','YEAR_SPECIFIC_RANGE',
  DATE_ADD('2024-05-05',INTERVAL 51 DAY), DATE_ADD('2024-05-05',INTERVAL 51 + 40 - 1 DAY),
  'apostles',1,NULL),
('Apostles Fast 2025','ሐዋርያት','fasting_period','YEAR_SPECIFIC_RANGE',
  DATE_ADD('2025-04-20',INTERVAL 51 DAY), DATE_ADD('2025-04-20',INTERVAL 51 + 40 - 1 DAY),
  'apostles',1,NULL),
('Apostles Fast 2026','ሐዋርያት','fasting_period','YEAR_SPECIFIC_RANGE',
  DATE_ADD('2026-04-12',INTERVAL 51 DAY), DATE_ADD('2026-04-12',INTERVAL 51 + 40 - 1 DAY),
  'apostles',1,NULL),
('Apostles Fast 2027','ሐዋርያት','fasting_period','YEAR_SPECIFIC_RANGE',
  DATE_ADD('2027-05-02',INTERVAL 51 DAY), DATE_ADD('2027-05-02',INTERVAL 51 + 40 - 1 DAY),
  'apostles',1,NULL),
('Apostles Fast 2028','ሐዋርያት','fasting_period','YEAR_SPECIFIC_RANGE',
  DATE_ADD('2028-04-16',INTERVAL 51 DAY), DATE_ADD('2028-04-16',INTERVAL 51 + 40 - 1 DAY),
  'apostles',1,NULL);
