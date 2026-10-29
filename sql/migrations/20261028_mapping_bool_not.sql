-- Existing installations: run once before saving inverted boolean mappings.
-- New installations already include this type in sql/schema.sql.
ALTER TABLE mappings
  MODIFY value_type ENUM('u16','s16','u32','s32','bool','bool_not')
  NOT NULL DEFAULT 'u16';
