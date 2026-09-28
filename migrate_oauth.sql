-- ============================================================
-- Additive migration: GitHub OAuth account linking.
-- Run once:  mysql ContactManagerDB < migrate_oauth.sql
--
--  * Allow OAuth-created accounts to have no password or email.
--  * Add a verified github_id to github_profiles.
-- ============================================================

USE ContactManagerDB;

ALTER TABLE users
  MODIFY password VARBINARY(255) DEFAULT NULL,
  MODIFY email VARCHAR(255) DEFAULT NULL;

ALTER TABLE github_profiles
  ADD COLUMN github_id BIGINT UNSIGNED NULL AFTER userid,
  ADD UNIQUE KEY uq_github_profiles_github_id (github_id);
