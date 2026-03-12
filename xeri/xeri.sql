xeri_db-- Xeri schema (MySQL) - compatible with current PHP code
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

DROP TABLE IF EXISTS player_sessions;
DROP TABLE IF EXISTS move_log;
DROP TABLE IF EXISTS collect_events;
DROP TABLE IF EXISTS player_collected;
DROP TABLE IF EXISTS table_stack;
DROP TABLE IF EXISTS player_hand;
DROP TABLE IF EXISTS game_totals;
DROP TABLE IF EXISTS players;
DROP TABLE IF EXISTS game_status;

CREATE TABLE players (
  position TINYINT NOT NULL PRIMARY KEY,
  username VARCHAR(50) DEFAULT NULL,
  token VARCHAR(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE game_status (
  id TINYINT NOT NULL PRIMARY KEY,
  status VARCHAR(20) NOT NULL,
  p_turn TINYINT DEFAULT NULL,
  current_round INT NOT NULL DEFAULT 1,
  max_rounds INT NOT NULL DEFAULT 3,
  result VARCHAR(1) DEFAULT NULL,
  last_collector TINYINT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE player_hand (
  position TINYINT NOT NULL,
  card VARCHAR(3) NOT NULL,
  hand_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (position, card)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE table_stack (
  stack_position INT NOT NULL PRIMARY KEY,
  card VARCHAR(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE player_collected (
  round INT NOT NULL,
  position TINYINT NOT NULL,
  card VARCHAR(3) NOT NULL,
  collected_order INT NOT NULL,
  PRIMARY KEY (round, position, collected_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE collect_events (
  round INT NOT NULL,
  position TINYINT NOT NULL,
  is_xeri TINYINT NOT NULL DEFAULT 0,
  is_xeri_with_jack TINYINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE game_totals (
  position TINYINT NOT NULL PRIMARY KEY,
  total_score INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE move_log (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  round INT NOT NULL DEFAULT 1,
  position TINYINT DEFAULT NULL,
  player_username VARCHAR(50) DEFAULT NULL,
  player_token VARCHAR(64) DEFAULT NULL,
  action VARCHAR(40) NOT NULL,
  card VARCHAR(3) DEFAULT NULL,
  details TEXT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE player_sessions (
  id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  position TINYINT NOT NULL,
  username VARCHAR(50) DEFAULT NULL,
  token VARCHAR(64) DEFAULT NULL,
  event_type VARCHAR(20) NOT NULL DEFAULT 'join',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO players (position, username, token) VALUES
(1, NULL, NULL),
(2, NULL, NULL);

INSERT INTO game_totals (position, total_score) VALUES
(1, 0),
(2, 0);

INSERT INTO game_status (id, status, p_turn, current_round, max_rounds, result, last_collector)
VALUES (1, 'not_active', NULL, 1, 3, NULL, NULL);
