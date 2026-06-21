-- ============================================================
--  Pallaw — AI Question Generator
--  MySQL schema
-- ============================================================
--  Flexible design: CSV columns are dynamic, so we store the
--  detected column list per "set" and each generated question
--  row as JSON. This supports ANY CSV structure the user uploads.
-- ============================================================

CREATE DATABASE IF NOT EXISTS pallaw
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE pallaw;

-- A "question set" = one generation batch for a given topic,
-- tied to the column structure of the uploaded sample CSV.
CREATE TABLE IF NOT EXISTS question_sets (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  topic         VARCHAR(255)      NOT NULL,
  columns_json  JSON              NOT NULL,         -- ["id","question","answer", ...]
  model         VARCHAR(120)      DEFAULT NULL,
  source_file   VARCHAR(255)      DEFAULT NULL,     -- original CSV file name
  question_count INT UNSIGNED     NOT NULL DEFAULT 0,
  created_at    TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_topic (topic),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Individual generated question rows. data_json holds the full
-- row keyed by the CSV columns, so any structure is preserved.
CREATE TABLE IF NOT EXISTS questions (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  set_id      BIGINT UNSIGNED   NOT NULL,
  data_json   JSON              NOT NULL,           -- {"id":"1","question":"...","answer":"..."}
  created_at  TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_questions_set
    FOREIGN KEY (set_id) REFERENCES question_sets(id)
    ON DELETE CASCADE,
  INDEX idx_set (set_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
