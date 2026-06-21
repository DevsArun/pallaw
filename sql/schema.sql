-- ============================================================
--  Pallaw — AI Question Platform
--  MySQL schema
-- ============================================================
--  Tables are also auto-created by the app (api/db.php) the first
--  time a working DB connection is made, so running this file is
--  optional. It is provided for manual / production setup.
-- ============================================================

CREATE DATABASE IF NOT EXISTS pallaw
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE pallaw;

-- A "job" = one batch produced by the platform.
--   type = 'generate'  -> new questions built from a sample CSV
--   type = 'solve'      -> explanations/answers added to uploaded questions
-- columns_json stores the exact CSV column order so exports match the input.
CREATE TABLE IF NOT EXISTS jobs (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type          ENUM('generate','solve') NOT NULL,
  topic         VARCHAR(255)      DEFAULT NULL,
  source_file   VARCHAR(255)      DEFAULT NULL,
  columns_json  JSON              NOT NULL,
  model         VARCHAR(120)      DEFAULT NULL,
  row_count     INT UNSIGNED      NOT NULL DEFAULT 0,
  created_at    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_type (type),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each generated/solved question row, stored as JSON keyed by the columns.
CREATE TABLE IF NOT EXISTS job_rows (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id      BIGINT UNSIGNED   NOT NULL,
  data_json   JSON              NOT NULL,
  created_at  TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_job_rows_job
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
  INDEX idx_job (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
