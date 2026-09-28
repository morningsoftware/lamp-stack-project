-- ============================================================
-- Additive migration for application decisions and org invitations.
-- Run once on the live database. It does not drop tables or rewrite rows.
-- Existing applications become pending.
--   mysql ContactManagerDB < migrate_applications.sql
-- ============================================================

USE ContactManagerDB;

ALTER TABLE applications
  ADD COLUMN decision VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER userid,
  ADD COLUMN decided_at TIMESTAMP NULL DEFAULT NULL AFTER decision,
  ADD COLUMN decided_by INT NULL AFTER decided_at,
  ADD KEY idx_applications_decision (decision),
  ADD CONSTRAINT fk_applications_decided_by FOREIGN KEY (decided_by)
    REFERENCES users (userid) ON DELETE SET NULL;

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
