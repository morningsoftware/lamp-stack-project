-- ============================================================
-- migrate.sql — single consolidated schema migration
--
-- Brings an existing ContactManagerDB up to the current schema.
-- Safe to re-run: every step is idempotent.
--
--   mysql -u root -p ContactManagerDB < migrate.sql
--
-- Applies, as needed:
--   1. Organizations / roles / applications / invitations tables
--      + applications.decision / decided_at / decided_by (accept/reject)
--   2. messages.roleid / messages.organizationid (message sharing)
--   3. github_profiles.github_id (verified GitHub OAuth identity)
--   4. users.password / users.email nullable (OAuth accounts)
-- ============================================================

USE ContactManagerDB;

-- ------------------------------------------------------------
-- 1. Organizations, roles, applications, and invitations
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS organizations (
  organizationid INT AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(100) NOT NULL,
  slug           VARCHAR(50)  NOT NULL,
  description    TEXT,
  location       VARCHAR(100) DEFAULT NULL,
  website        VARCHAR(255) DEFAULT NULL,
  logo           VARCHAR(255) DEFAULT NULL,
  created_by     INT DEFAULT NULL,
  created_at     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_organizations_slug (slug),
  CONSTRAINT fk_organizations_created_by FOREIGN KEY (created_by)
    REFERENCES users (userid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS organization_members (
  organizationid INT NOT NULL,
  userid         INT NOT NULL,
  membership     VARCHAR(20) NOT NULL DEFAULT 'member',
  created_at     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (organizationid, userid),
  KEY idx_organization_members_userid (userid),
  CONSTRAINT fk_organization_members_org FOREIGN KEY (organizationid)
    REFERENCES organizations (organizationid) ON DELETE CASCADE,
  CONSTRAINT fk_organization_members_user FOREIGN KEY (userid)
    REFERENCES users (userid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS roles (
  roleid         INT AUTO_INCREMENT PRIMARY KEY,
  organizationid INT NOT NULL,
  name           VARCHAR(100) NOT NULL,
  description    TEXT,
  status         VARCHAR(20) NOT NULL DEFAULT 'open',
  created_by     INT DEFAULT NULL,
  created_at     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at      TIMESTAMP NULL DEFAULT NULL,
  updated_at     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_roles_org_status (organizationid, status),
  CONSTRAINT fk_roles_organization FOREIGN KEY (organizationid)
    REFERENCES organizations (organizationid) ON DELETE CASCADE,
  CONSTRAINT fk_roles_created_by FOREIGN KEY (created_by)
    REFERENCES users (userid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_skills (
  roleid  INT NOT NULL,
  skillid INT NOT NULL,
  PRIMARY KEY (roleid, skillid),
  KEY idx_role_skills_skillid (skillid),
  CONSTRAINT fk_role_skills_role FOREIGN KEY (roleid)
    REFERENCES roles (roleid) ON DELETE CASCADE,
  CONSTRAINT fk_role_skills_skill FOREIGN KEY (skillid)
    REFERENCES skills (skillid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS applications (
  applicationid INT AUTO_INCREMENT PRIMARY KEY,
  roleid        INT NOT NULL,
  userid        INT NOT NULL,
  decision      VARCHAR(20) NOT NULL DEFAULT 'pending',
  decided_at    TIMESTAMP NULL DEFAULT NULL,
  decided_by    INT DEFAULT NULL,
  created_at    TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_applications_role_user (roleid, userid),
  KEY idx_applications_userid (userid),
  KEY idx_applications_decision (decision),
  CONSTRAINT fk_applications_role FOREIGN KEY (roleid)
    REFERENCES roles (roleid) ON DELETE CASCADE,
  CONSTRAINT fk_applications_user FOREIGN KEY (userid)
    REFERENCES users (userid) ON DELETE CASCADE,
  CONSTRAINT fk_applications_decided_by FOREIGN KEY (decided_by)
    REFERENCES users (userid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS organization_invitations (
  invitationid   INT AUTO_INCREMENT PRIMARY KEY,
  organizationid INT NOT NULL,
  userid         INT NOT NULL,
  roleid         INT DEFAULT NULL,
  invited_by     INT DEFAULT NULL,
  status         VARCHAR(20) NOT NULL DEFAULT 'pending',
  created_at     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at   TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uq_invitation_org_user (organizationid, userid),
  KEY idx_invitations_userid_status (userid, status),
  CONSTRAINT fk_invitations_org FOREIGN KEY (organizationid)
    REFERENCES organizations (organizationid) ON DELETE CASCADE,
  CONSTRAINT fk_invitations_user FOREIGN KEY (userid)
    REFERENCES users (userid) ON DELETE CASCADE,
  CONSTRAINT fk_invitations_role FOREIGN KEY (roleid)
    REFERENCES roles (roleid) ON DELETE SET NULL,
  CONSTRAINT fk_invitations_invited_by FOREIGN KEY (invited_by)
    REFERENCES users (userid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- applications: add accept/reject decision columns (existing rows stay pending)
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'applications' AND COLUMN_NAME = 'decision');
SET @sql := IF(@exists = 0,
  'ALTER TABLE applications
     ADD COLUMN decision VARCHAR(20) NOT NULL DEFAULT ''pending'' AFTER userid,
     ADD COLUMN decided_at TIMESTAMP NULL DEFAULT NULL AFTER decision,
     ADD COLUMN decided_by INT NULL AFTER decided_at,
     ADD KEY idx_applications_decision (decision),
     ADD CONSTRAINT fk_applications_decided_by FOREIGN KEY (decided_by)
       REFERENCES users (userid) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2. messages: add share columns (roleid / organizationid)
-- ------------------------------------------------------------
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'roleid');
SET @sql := IF(@exists = 0,
  'ALTER TABLE messages
     ADD COLUMN roleid INT NULL AFTER body,
     ADD COLUMN organizationid INT NULL AFTER roleid',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND CONSTRAINT_NAME = 'fk_messages_role');
SET @sql := IF(@exists = 0,
  'ALTER TABLE messages
     ADD KEY idx_messages_roleid (roleid),
     ADD KEY idx_messages_organizationid (organizationid),
     ADD CONSTRAINT fk_messages_role FOREIGN KEY (roleid)
       REFERENCES roles (roleid) ON DELETE SET NULL,
     ADD CONSTRAINT fk_messages_organization FOREIGN KEY (organizationid)
       REFERENCES organizations (organizationid) ON DELETE SET NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 3. github_profiles: add verified github_id
-- ------------------------------------------------------------
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'github_profiles' AND COLUMN_NAME = 'github_id');
SET @sql := IF(@exists = 0,
  'ALTER TABLE github_profiles
     ADD COLUMN github_id BIGINT UNSIGNED NULL AFTER userid,
     ADD UNIQUE KEY uq_github_profiles_github_id (github_id)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 4. users: allow password / email to be null (OAuth accounts)
-- ------------------------------------------------------------
SET @exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password' AND IS_NULLABLE = 'YES');
SET @sql := IF(@exists = 0,
  'ALTER TABLE users
     MODIFY password VARBINARY(255) DEFAULT NULL,
     MODIFY email VARCHAR(255) DEFAULT NULL',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
