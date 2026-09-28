-- ============================================================
-- Additive migration for the live ContactManagerDB.
-- Run once. It does not drop tables or rewrite existing rows.
--   mysql ContactManagerDB < migrate_organizations.sql
-- ============================================================

USE ContactManagerDB;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS role_skills (
  roleid  INT NOT NULL,
  skillid INT NOT NULL,
  PRIMARY KEY (roleid, skillid),
  KEY idx_role_skills_skillid (skillid),
  CONSTRAINT fk_role_skills_role FOREIGN KEY (roleid)
    REFERENCES roles (roleid) ON DELETE CASCADE,
  CONSTRAINT fk_role_skills_skill FOREIGN KEY (skillid)
    REFERENCES skills (skillid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE messages
  ADD COLUMN roleid INT NULL AFTER body,
  ADD COLUMN organizationid INT NULL AFTER roleid,
  ADD KEY idx_messages_roleid (roleid),
  ADD KEY idx_messages_organizationid (organizationid),
  ADD CONSTRAINT fk_messages_role FOREIGN KEY (roleid)
    REFERENCES roles (roleid) ON DELETE SET NULL,
  ADD CONSTRAINT fk_messages_organization FOREIGN KEY (organizationid)
    REFERENCES organizations (organizationid) ON DELETE SET NULL;
