-- ============================================================
-- SQL Full Reset Script: resetdb.sql
-- Project: Developer Bio Site (LAMP / MySQL)
-- Description: Drops existing tables if present, recreates schema,
--              seeds sample data, and sets up an application user
--              with limited permissions.
-- ============================================================

CREATE DATABASE IF NOT EXISTS ContactManagerDB
  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE ContactManagerDB;

-- Drop tables (children before parents)
DROP TABLE IF EXISTS messages;
DROP TABLE IF EXISTS conversation_participants;
DROP TABLE IF EXISTS conversations;
DROP TABLE IF EXISTS github_repositories;
DROP TABLE IF EXISTS github_profiles;
DROP TABLE IF EXISTS user_skills;
DROP TABLE IF EXISTS skills;
DROP TABLE IF EXISTS social_links;
DROP TABLE IF EXISTS contacts;
DROP TABLE IF EXISTS sessions;
DROP TABLE IF EXISTS users;

-- Create users table
CREATE TABLE users (
  userid      INT AUTO_INCREMENT PRIMARY KEY,
  loginuid    VARCHAR(50)  NOT NULL,
  email       VARCHAR(255) NOT NULL,
  password    VARBINARY(255) NOT NULL,
  firstname   VARCHAR(50)  NOT NULL,
  lastname    VARCHAR(50)  NOT NULL,
  displayname VARCHAR(100) NOT NULL,
  bio         TEXT,
  location    VARCHAR(100),
  jobtitle    VARCHAR(100),
  avatar      VARCHAR(255),
  resume      VARCHAR(255),
  isactive    INT NOT NULL DEFAULT 1,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY users_loginid (loginuid),
  UNIQUE KEY users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create contacts table
CREATE TABLE contacts (
  contactid   INT AUTO_INCREMENT PRIMARY KEY,
  userid      INT NOT NULL,
  firstname   VARCHAR(50)  DEFAULT '',
  lastname    VARCHAR(50)  DEFAULT '',
  description VARCHAR(100) DEFAULT NULL,
  email       VARCHAR(100) DEFAULT '',
  phone       VARCHAR(10)  DEFAULT '',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_contacts_userid (userid),
  CONSTRAINT fk_contacts_userid FOREIGN KEY (userid)
    REFERENCES users (userid) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create social links table
CREATE TABLE social_links (
  linkid        INT AUTO_INCREMENT PRIMARY KEY,
  userid        INT NOT NULL,
  platform      VARCHAR(50),
  url           VARCHAR(255),
  display_order INT DEFAULT 0,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_social_links_userid (userid),
  CONSTRAINT fk_social_links_userid FOREIGN KEY (userid)
    REFERENCES users (userid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create skills catalog
CREATE TABLE skills (
  skillid    INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  category   VARCHAR(50),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_skills_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Link developers to skills
CREATE TABLE user_skills (
  userid        INT NOT NULL,
  skillid       INT NOT NULL,
  proficiency   VARCHAR(20),
  display_order INT DEFAULT 0,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (userid, skillid),
  KEY idx_user_skills_skillid (skillid),
  CONSTRAINT fk_user_skills_userid FOREIGN KEY (userid)
    REFERENCES users (userid) ON DELETE CASCADE,
  CONSTRAINT fk_user_skills_skillid FOREIGN KEY (skillid)
    REFERENCES skills (skillid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create Github profiles table
CREATE TABLE github_profiles (
  githubid      INT AUTO_INCREMENT PRIMARY KEY,
  userid        INT NOT NULL,
  username      VARCHAR(50) NOT NULL,
  avatar_url    VARCHAR(255),
  profile_url   VARCHAR(255),
  bio           TEXT,
  followers     INT DEFAULT 0,
  following     INT DEFAULT 0,
  public_repos  INT DEFAULT 0,
  public_gists  INT DEFAULT 0,
  last_synced   TIMESTAMP NULL DEFAULT NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_github_profiles_userid (userid),
  UNIQUE KEY uq_github_profiles_username (username),
  CONSTRAINT fk_github_profiles_userid FOREIGN KEY (userid)
    REFERENCES users (userid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create Github repos table
CREATE TABLE github_repositories (
  repo_id         INT AUTO_INCREMENT PRIMARY KEY,
  githubid        INT NOT NULL,
  github_repo_id  BIGINT UNSIGNED NOT NULL,
  name            VARCHAR(100) NOT NULL,
  description     TEXT,
  url             VARCHAR(255),
  language        VARCHAR(50),
  stars           INT DEFAULT 0,
  forks           INT DEFAULT 0,
  is_fork         TINYINT(1) DEFAULT 0,
  is_featured     TINYINT(1) DEFAULT 0,
  commits_30d     INT NOT NULL DEFAULT 0,
  weekly_commits  JSON NULL,
  daily_commits   JSON NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_github_repositories_repo (githubid, github_repo_id),
  CONSTRAINT fk_github_repositories_githubid FOREIGN KEY (githubid)
    REFERENCES github_profiles (githubid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create sessions table (stores hashed API tokens)
CREATE TABLE sessions (
  token_hash CHAR(64) NOT NULL,
  userid     INT NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (token_hash),
  KEY idx_sessions_userid (userid),
  KEY idx_sessions_expires_at (expires_at),
  CONSTRAINT fk_sessions_userid FOREIGN KEY (userid)
    REFERENCES users (userid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create conversations for direct messaging
CREATE TABLE conversations (
  conversationid  INT AUTO_INCREMENT PRIMARY KEY,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  last_message_at TIMESTAMP NULL DEFAULT NULL,
  KEY idx_conversations_last_message_at (last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Participants of each conversation (tracks per-user read state)
CREATE TABLE conversation_participants (
  conversationid INT NOT NULL,
  userid         INT NOT NULL,
  last_read_at   TIMESTAMP NULL DEFAULT NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (conversationid, userid),
  KEY idx_conversation_participants_userid (userid),
  CONSTRAINT fk_conversation_participants_conversation FOREIGN KEY (conversationid)
    REFERENCES conversations (conversationid) ON DELETE CASCADE,
  CONSTRAINT fk_conversation_participants_userid FOREIGN KEY (userid)
    REFERENCES users (userid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create direct messages
CREATE TABLE messages (
  messageid      INT AUTO_INCREMENT PRIMARY KEY,
  conversationid INT NOT NULL,
  sender_userid  INT NOT NULL,
  body           TEXT NOT NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_messages_conversation (conversationid, created_at),
  KEY idx_messages_sender (sender_userid),
  CONSTRAINT fk_messages_conversation FOREIGN KEY (conversationid)
    REFERENCES conversations (conversationid) ON DELETE CASCADE,
  CONSTRAINT fk_messages_sender FOREIGN KEY (sender_userid)
    REFERENCES users (userid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed data

INSERT INTO users
  (loginuid, email, password, firstname, lastname, displayname, bio, location, jobtitle, avatar, resume)
VALUES
  ('jdoe', 'jane@example.com', '$2y$12$l6cMqSWkTMMcc1ZzNngq4uGxSdRFuh6k/4NR.E1l4u195zGgT1szK',
   'Jane', 'Doe', 'Jane Doe',
   'Full-stack developer who enjoys building small, useful tools.',
   'Melbourne, FL', 'Software Engineer',
   '/assets/img/avatar-jdoe.png', '/assets/resume/jdoe.pdf'),
  ('asmith', 'alex@example.com', '$2y$12$Ip/c6G.5eywb3TXYsZ9ai.duz0bpOgUiSj0nDHMfe.w1QcPDftZp6',
   'Alex', 'Smith', 'Alex Smith',
   'Backend engineer focused on APIs, databases and developer tooling.',
   'Orlando, FL', 'Platform Engineer',
   '/assets/img/avatar-asmith.png', '/assets/resume/asmith.pdf');

SET @jane = (SELECT userid FROM users WHERE loginuid = 'jdoe');
SET @alex = (SELECT userid FROM users WHERE loginuid = 'asmith');

-- Address-book entries (a contact need not be an app user)
INSERT INTO contacts (userid, firstname, lastname, description, email, phone) VALUES
  (@jane, 'Sam',   'Lee',      'College friend',    'sam.lee@example.com',   '3215550101'),
  (@jane, 'Priya', 'Patel',    'Former teammate',   'priya@example.com',     '3215550102'),
  (@alex, 'Diego', 'Martinez', 'Met at a hackathon','diego@example.com',     '4075550103');

INSERT INTO social_links (userid, platform, url, display_order) VALUES
  (@jane, 'GitHub',   'https://github.com/jdoe',           1),
  (@jane, 'LinkedIn', 'https://linkedin.com/in/jdoe',      2),
  (@jane, 'Twitter',  'https://twitter.com/jdoe',          3),
  (@alex, 'GitHub',   'https://github.com/asmith',         1),
  (@alex, 'LinkedIn', 'https://linkedin.com/in/asmith',    2);

INSERT INTO skills (name, category) VALUES
  ('PHP',        'Language'),
  ('JavaScript', 'Language'),
  ('SQL',        'Database'),
  ('MySQL',      'Database'),
  ('API Design', 'Practice'),
  ('Docker',     'Tooling');

SET @php   = (SELECT skillid FROM skills WHERE name = 'PHP');
SET @js    = (SELECT skillid FROM skills WHERE name = 'JavaScript');
SET @sql   = (SELECT skillid FROM skills WHERE name = 'SQL');
SET @mysql = (SELECT skillid FROM skills WHERE name = 'MySQL');
SET @api   = (SELECT skillid FROM skills WHERE name = 'API Design');
SET @dock  = (SELECT skillid FROM skills WHERE name = 'Docker');

INSERT INTO user_skills (userid, skillid, proficiency, display_order) VALUES
  (@jane, @php,   'expert',       1),
  (@jane, @js,    'advanced',     2),
  (@jane, @mysql, 'advanced',     3),
  (@alex, @php,   'advanced',     1),
  (@alex, @sql,   'expert',       2),
  (@alex, @api,   'advanced',     3),
  (@alex, @dock,  'intermediate', 4);

INSERT INTO github_profiles
  (userid, username, avatar_url, profile_url, bio, followers, following, public_repos, public_gists, last_synced)
VALUES
  (@jane, 'jdoe', 'https://avatars.githubusercontent.com/u/000001',
   'https://github.com/jdoe', 'Building things with PHP and JS.', 42, 30, 18, 3, CURRENT_TIMESTAMP),
  (@alex, 'asmith', 'https://avatars.githubusercontent.com/u/000002',
   'https://github.com/asmith', 'APIs and databases.', 17, 25, 9, 1, CURRENT_TIMESTAMP);

SET @janeGh = (SELECT githubid FROM github_profiles WHERE userid = @jane);
SET @alexGh = (SELECT githubid FROM github_profiles WHERE userid = @alex);

INSERT INTO github_repositories
  (githubid, github_repo_id, name, description, url, language, stars, forks, is_fork, is_featured, commits_30d, weekly_commits, daily_commits)
VALUES
  (@janeGh, 123456, 'developer-bio-site', 'Source for this portfolio site.',
   'https://github.com/jdoe/developer-bio-site', 'PHP', 5, 1, 0, 1, 34,
   JSON_ARRAY(2, 3, 1, 0, 4, 5, 3, 2, 6, 4, 3, 5),
   JSON_ARRAY(0,0,1,1,0,0,0,0,2,1,0,0,0,0,0,1,0,0,0,0,0,0,0,0,0,0,0,0,0,2,2,0,0,0,0,0,1,2,1,1,0,0,0,2,1,0,0,0,0,0,2,0,0,0,0,0,0,1,1,3,1,0,0,0,1,2,1,0,0,0,0,1,0,2,0,0,0,0,0,1,2,1,1,0)),
  (@janeGh, 123457, 'contacts-manager', 'CRUD contacts app for COP4331.',
   'https://github.com/jdoe/contacts-manager', 'JavaScript', 2, 0, 0, 0, 8,
   JSON_ARRAY(0, 0, 1, 2, 1, 0, 1, 0, 2, 1, 0, 0),
   JSON_ARRAY(0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1,0,0,0,0,0,0,2,0,0,0,0,0,0,1,0,0,0,0,0,0,0,0,0,0,0,0,0,1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,2,0,0,0,0,0,0,1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0)),
  (@janeGh, 123458, 'open-source-dashboard', 'Collaborative dashboard for OSS maintainers.',
   'https://github.com/jdoe/open-source-dashboard', 'PHP', 7, 2, 0, 1, 21,
   JSON_ARRAY(3, 4, 2, 5, 3, 4, 2, 3, 5, 6, 4, 5),
   JSON_ARRAY(0,1,2,0,0,0,0,0,2,1,1,0,0,0,0,2,0,0,0,0,0,0,3,0,0,0,2,0,0,1,0,1,1,0,0,0,2,2,0,0,0,0,0,2,0,0,0,0,0,0,0,1,2,0,0,0,0,2,2,1,0,0,0,0,2,2,2,0,0,0,0,2,1,1,0,0,0,0,2,2,0,1,0,0)),
  (@alexGh, 223456, 'api-starter', 'A small PHP API starter kit.',
   'https://github.com/asmith/api-starter', 'PHP', 11, 4, 0, 1, 27,
   JSON_ARRAY(1, 2, 4, 3, 2, 5, 4, 3, 2, 4, 5, 3),
   JSON_ARRAY(0,1,0,0,0,0,0,0,1,1,0,0,0,0,0,0,0,2,2,0,0,0,1,1,1,0,0,0,0,0,2,0,0,0,0,0,1,1,1,0,2,0,0,3,0,0,1,0,0,0,0,2,1,0,0,0,0,1,1,0,0,0,0,0,0,2,1,1,0,0,0,1,1,1,2,0,0,0,0,1,1,0,1,0)),
  (@alexGh, 223457, 'sql-notes', 'Notes on query tuning.',
   'https://github.com/asmith/sql-notes', 'SQL', 3, 0, 0, 0, 5,
   JSON_ARRAY(0, 0, 1, 1, 0, 0, 1, 0, 1, 1, 0, 0),
   JSON_ARRAY(0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1,0,0,0,0,0,1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1,0,0,0,0,0,0,0,0,1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0)),
  (@alexGh, 223458, 'open-source-dashboard', 'Collaborative dashboard for OSS maintainers.',
   'https://github.com/asmith/open-source-dashboard', 'PHP', 12, 2, 0, 1, 30,
   JSON_ARRAY(4, 3, 5, 6, 4, 5, 3, 4, 6, 5, 4, 6),
   JSON_ARRAY(0,1,1,0,1,1,0,0,1,2,0,0,0,0,0,1,2,2,0,0,0,0,2,0,1,2,1,0,0,1,2,1,0,0,0,0,2,2,1,0,0,0,0,0,1,1,1,0,0,0,2,0,2,0,0,0,0,2,0,2,2,0,0,0,1,3,1,0,0,0,0,0,0,1,2,1,0,0,3,0,1,2,0,0));

-- Seed a direct conversation between Jane and Alex
INSERT INTO conversations (last_message_at) VALUES (CURRENT_TIMESTAMP);
SET @convo = LAST_INSERT_ID();

INSERT INTO conversation_participants (conversationid, userid) VALUES
  (@convo, @jane),
  (@convo, @alex);

INSERT INTO messages (conversationid, sender_userid, body, created_at) VALUES
  (@convo, @alex, 'Hey Jane, loved your portfolio site. Are you open to collaborating on an API project?', NOW() - INTERVAL 1 HOUR),
  (@convo, @jane, 'Thanks Alex! Definitely - send over the details.', NOW() - INTERVAL 30 MINUTE);

UPDATE conversation_participants
  SET last_read_at = NOW() - INTERVAL 1 HOUR
  WHERE conversationid = @convo AND userid = @jane;

-- Create user and set permissions
DROP USER IF EXISTS 'ContactManagerUser'@'localhost';
CREATE USER 'ContactManagerUser'@'localhost' IDENTIFIED BY 'VeryStrongPass1!';

-- Grant required privileges
GRANT SELECT, INSERT, UPDATE, DELETE
  ON ContactManagerDB.*
  TO 'ContactManagerUser'@'localhost';

FLUSH PRIVILEGES;
