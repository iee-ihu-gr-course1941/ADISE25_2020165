<?php
/**
 * Database connection layer.
 *
 * Default mode (localhost): SQLite file, no external services needed.
 * Optional mode: MySQL (for users.iee.ihu.gr) via lib/db_upass.php.
 */

declare(strict_types=1);

/**
 * @return PDO
 */
function get_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $configFile = __DIR__ . '/db_upass.php';
    $useMysql = false;

    if (file_exists($configFile)) {
        require $configFile;
        $useMysql = isset($DB_DRIVER) && strtolower((string)$DB_DRIVER) === 'mysql';
    }

    try {
        if ($useMysql) {
            $host = $DB_HOST ?? '127.0.0.1';
            $port = (int)($DB_PORT ?? 3306);
            $name = $DB_NAME ?? 'xeri';
            $user = $DB_USER ?? '';
            $pass = $DB_PASS ?? '';
            $charset = $DB_CHARSET ?? 'utf8mb4';
            $socket = defined('DB_SOCKET') ? DB_SOCKET : null;

            $dsn = $socket
                ? "mysql:unix_socket={$socket};dbname={$name};charset={$charset}"
                : "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } else {
            $dataDir = dirname(__DIR__) . '/data';
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0777, true);
            }
            $dbFile = $dataDir . '/xeri.sqlite';
            $dsn = 'sqlite:' . $dbFile;
            $pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    } catch (Throwable $e) {
        json_error(500, 'Database connection failed: ' . $e->getMessage());
    }

    initialize_schema($pdo, $useMysql);
    return $pdo;
}

function initialize_schema(PDO $pdo, bool $isMysql): void
{
    $playersSql = $isMysql
        ? "CREATE TABLE IF NOT EXISTS players (
                position TINYINT NOT NULL PRIMARY KEY,
                username VARCHAR(50) DEFAULT NULL,
                token VARCHAR(64) DEFAULT NULL
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        : "CREATE TABLE IF NOT EXISTS players (
                position INTEGER NOT NULL PRIMARY KEY,
                username TEXT DEFAULT NULL,
                token TEXT DEFAULT NULL
           )";

    $statusSql = $isMysql
        ? "CREATE TABLE IF NOT EXISTS game_status (
                id TINYINT NOT NULL PRIMARY KEY,
                status VARCHAR(20) NOT NULL,
                p_turn TINYINT DEFAULT NULL,
                current_round INT NOT NULL DEFAULT 1,
                max_rounds INT NOT NULL DEFAULT 3,
                result VARCHAR(1) DEFAULT NULL,
                last_collector TINYINT DEFAULT NULL
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        : "CREATE TABLE IF NOT EXISTS game_status (
                id INTEGER NOT NULL PRIMARY KEY,
                status TEXT NOT NULL,
                p_turn INTEGER DEFAULT NULL,
                current_round INTEGER NOT NULL DEFAULT 1,
                max_rounds INTEGER NOT NULL DEFAULT 3,
                result TEXT DEFAULT NULL,
                last_collector INTEGER DEFAULT NULL
           )";

    $handSql = $isMysql
        ? "CREATE TABLE IF NOT EXISTS player_hand (
                position TINYINT NOT NULL,
                card VARCHAR(3) NOT NULL,
                hand_order INT NOT NULL DEFAULT 0,
                PRIMARY KEY (position, card)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        : "CREATE TABLE IF NOT EXISTS player_hand (
                position INTEGER NOT NULL,
                card TEXT NOT NULL,
                hand_order INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (position, card)
           )";

    $tableSql = $isMysql
        ? "CREATE TABLE IF NOT EXISTS table_stack (
                stack_position INT NOT NULL PRIMARY KEY,
                card VARCHAR(3) NOT NULL
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        : "CREATE TABLE IF NOT EXISTS table_stack (
                stack_position INTEGER NOT NULL PRIMARY KEY,
                card TEXT NOT NULL
           )";

    $collectedSql = $isMysql
        ? "CREATE TABLE IF NOT EXISTS player_collected (
                round INT NOT NULL,
                position TINYINT NOT NULL,
                card VARCHAR(3) NOT NULL,
                collected_order INT NOT NULL,
                PRIMARY KEY (round, position, collected_order)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        : "CREATE TABLE IF NOT EXISTS player_collected (
                round INTEGER NOT NULL,
                position INTEGER NOT NULL,
                card TEXT NOT NULL,
                collected_order INTEGER NOT NULL,
                PRIMARY KEY (round, position, collected_order)
           )";

    $eventsSql = $isMysql
        ? "CREATE TABLE IF NOT EXISTS collect_events (
                round INT NOT NULL,
                position TINYINT NOT NULL,
                is_xeri TINYINT NOT NULL DEFAULT 0,
                is_xeri_with_jack TINYINT NOT NULL DEFAULT 0
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        : "CREATE TABLE IF NOT EXISTS collect_events (
                round INTEGER NOT NULL,
                position INTEGER NOT NULL,
                is_xeri INTEGER NOT NULL DEFAULT 0,
                is_xeri_with_jack INTEGER NOT NULL DEFAULT 0
           )";

    $totalsSql = $isMysql
        ? "CREATE TABLE IF NOT EXISTS game_totals (
                position TINYINT NOT NULL PRIMARY KEY,
                total_score INT NOT NULL DEFAULT 0
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        : "CREATE TABLE IF NOT EXISTS game_totals (
                position INTEGER NOT NULL PRIMARY KEY,
                total_score INTEGER NOT NULL DEFAULT 0
           )";

    $moveLogSql = $isMysql
        ? "CREATE TABLE IF NOT EXISTS move_log (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                round INT NOT NULL DEFAULT 1,
                position TINYINT DEFAULT NULL,
                player_username VARCHAR(50) DEFAULT NULL,
                player_token VARCHAR(64) DEFAULT NULL,
                action VARCHAR(40) NOT NULL,
                card VARCHAR(3) DEFAULT NULL,
                details TEXT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        : "CREATE TABLE IF NOT EXISTS move_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                round INTEGER NOT NULL DEFAULT 1,
                position INTEGER DEFAULT NULL,
                player_username TEXT DEFAULT NULL,
                player_token TEXT DEFAULT NULL,
                action TEXT NOT NULL,
                card TEXT DEFAULT NULL,
                details TEXT DEFAULT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
           )";

    $playerSessionsSql = $isMysql
        ? "CREATE TABLE IF NOT EXISTS player_sessions (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                position TINYINT NOT NULL,
                username VARCHAR(50) DEFAULT NULL,
                token VARCHAR(64) DEFAULT NULL,
                event_type VARCHAR(20) NOT NULL DEFAULT 'join',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        : "CREATE TABLE IF NOT EXISTS player_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                position INTEGER NOT NULL,
                username TEXT DEFAULT NULL,
                token TEXT DEFAULT NULL,
                event_type TEXT NOT NULL DEFAULT 'join',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
           )";

    $pdo->exec($playersSql);
    $pdo->exec($statusSql);
    $pdo->exec($handSql);
    $pdo->exec($tableSql);
    $pdo->exec($collectedSql);
    $pdo->exec($eventsSql);
    $pdo->exec($totalsSql);
    $pdo->exec($moveLogSql);
    $pdo->exec($playerSessionsSql);

    // Lightweight migrations for existing databases.
    try_add_column($pdo, 'move_log', 'player_username', $isMysql ? 'VARCHAR(50) NULL' : 'TEXT');
    try_add_column($pdo, 'move_log', 'player_token', $isMysql ? 'VARCHAR(64) NULL' : 'TEXT');

    $exists = (int)$pdo->query('SELECT COUNT(*) FROM game_status WHERE id=1')->fetchColumn();
    if ($exists === 0) {
        $pdo->exec("INSERT INTO game_status (id, status, p_turn, current_round, max_rounds, result, last_collector)
                    VALUES (1, 'not_active', NULL, 1, 3, NULL, NULL)");
    }

    $p1 = (int)$pdo->query('SELECT COUNT(*) FROM players WHERE position=1')->fetchColumn();
    $p2 = (int)$pdo->query('SELECT COUNT(*) FROM players WHERE position=2')->fetchColumn();
    if ($p1 === 0) {
        $pdo->exec('INSERT INTO players (position, username, token) VALUES (1, NULL, NULL)');
    }
    if ($p2 === 0) {
        $pdo->exec('INSERT INTO players (position, username, token) VALUES (2, NULL, NULL)');
    }

    $t1 = (int)$pdo->query('SELECT COUNT(*) FROM game_totals WHERE position=1')->fetchColumn();
    $t2 = (int)$pdo->query('SELECT COUNT(*) FROM game_totals WHERE position=2')->fetchColumn();
    if ($t1 === 0) {
        $pdo->exec('INSERT INTO game_totals (position, total_score) VALUES (1, 0)');
    }
    if ($t2 === 0) {
        $pdo->exec('INSERT INTO game_totals (position, total_score) VALUES (2, 0)');
    }
}

function try_add_column(PDO $pdo, string $table, string $column, string $definition): void
{
    try {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    } catch (Throwable $e) {
        // Ignore if column already exists.
    }
}

function json_error(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['errormesg' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
