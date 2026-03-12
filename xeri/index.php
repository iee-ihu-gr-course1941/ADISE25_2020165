<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/dbconnect.php';
require_once __DIR__ . '/lib/game_logic.php';

$db = get_db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') {
    send_cors();
    http_response_code(200);
    exit;
}

$request = parse_request_path();
if ($request === '') {
    render_home();
    exit;
}

send_cors();
header('Content-Type: application/json; charset=utf-8');

$parts = explode('/', $request);
$resource = $parts[0] ?? '';
$sub = $parts[1] ?? null;
$input = read_json_input();
$token = extract_token($input);

try {
    switch ($resource) {
        case 'status':
            method_only('GET', $method);
            echo json_encode(api_status($db), JSON_UNESCAPED_UNICODE);
            break;
        case 'players':
            echo json_encode(api_players($db, $method, $sub, $input), JSON_UNESCAPED_UNICODE);
            break;
        case 'board':
            echo json_encode(api_board($db, $method, $token), JSON_UNESCAPED_UNICODE);
            break;
        case 'move':
            method_only('POST', $method);
            echo json_encode(api_move($db, $token, $input), JSON_UNESCAPED_UNICODE);
            break;
        case 'history':
            method_only('GET', $method);
            echo json_encode(api_history($db), JSON_UNESCAPED_UNICODE);
            break;
        case 'reset':
            method_only('POST', $method);
            reset_game($db);
            echo json_encode(['status' => 'ok', 'message' => 'Το παιχνίδι έγινε επαναφορά.'], JSON_UNESCAPED_UNICODE);
            break;
        default:
            json_error(404, 'Δεν βρέθηκε πόρος. Χρησιμοποίησε: status, players, board, move, history, reset');
    }
} catch (Throwable $e) {
    json_error(500, 'Server error: ' . $e->getMessage());
}

function send_cors(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, App-Token, X-App-Token, HTTP_APP_TOKEN');
}

function parse_request_path(): string
{
    if (isset($_GET['request'])) {
        return trim((string)$_GET['request'], '/');
    }
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $normalized = str_replace('\\', '/', $uriPath);
    if ($scriptDir !== '' && strpos($normalized, $scriptDir) === 0) {
        $normalized = substr($normalized, strlen($scriptDir));
    }
    $normalized = ltrim($normalized, '/');
    if ($normalized === '' || $normalized === basename(__FILE__)) {
        return '';
    }
    return trim($normalized, '/');
}

/** @return array<string,mixed> */
function read_json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/** @param array<string,mixed> $input */
function extract_token(array $input): string
{
    if (!empty($_SERVER['HTTP_APP_TOKEN'])) {
        return (string)$_SERVER['HTTP_APP_TOKEN'];
    }
    // Some servers prefix custom header names with another HTTP_.
    if (!empty($_SERVER['HTTP_HTTP_APP_TOKEN'])) {
        return (string)$_SERVER['HTTP_HTTP_APP_TOKEN'];
    }
    if (!empty($_SERVER['HTTP_X_APP_TOKEN'])) {
        return (string)$_SERVER['HTTP_X_APP_TOKEN'];
    }
    if (!empty($_GET['token'])) {
        return (string)$_GET['token'];
    }
    return isset($input['token']) ? (string)$input['token'] : '';
}

function method_only(string $expected, string $actual): void
{
    if ($expected !== $actual) {
        json_error(405, 'Η μέθοδος δεν επιτρέπεται.');
    }
}

/** @return array<string,mixed> */
function api_status(PDO $db): array
{
    $status = get_status($db);
    $round = (int)$status['current_round'];

    $playersStmt = $db->query(
        'SELECT p.position, p.username, g.total_score
         FROM players p
         LEFT JOIN game_totals g ON g.position = p.position
         ORDER BY p.position'
    );
    $players = [];
    foreach ($playersStmt as $row) {
        $pos = (int)$row['position'];
        $col = collected_stats($db, $round, $pos);
        $players[(string)$pos] = [
            'username' => $row['username'],
            'total_score' => (int)$row['total_score'],
            'round_collected' => $col,
        ];
    }

    $deadlock = in_array($status['status'], ['round_ended', 'game_ended'], true);
    $deadlockMessage = null;
    if ($status['status'] === 'round_ended') {
        $deadlockMessage = 'Ο γύρος τελείωσε. Κάλεσε POST /board για συνέχεια.';
    } elseif ($status['status'] === 'game_ended') {
        $deadlockMessage = 'Το παιχνίδι τελείωσε.';
    }

    return [
        'game_status' => $status,
        'players' => $players,
        'deadlock' => $deadlock,
        'deadlock_message' => $deadlockMessage,
    ];
}

/** @return array<string,mixed> */
function api_history(PDO $db): array
{
    $stmt = $db->query('SELECT id, round, position, player_username, action, card, details, created_at FROM move_log ORDER BY id DESC LIMIT 30');
    $rows = $stmt->fetchAll();
    // Keep newest first so the latest move appears at the top.
    return ['items' => $rows];
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function api_players(PDO $db, string $method, ?string $sub, array $input): array
{
    if ($sub === null || $sub === '') {
        if ($method !== 'GET') {
            json_error(400, 'Δώσε θέση παίκτη: 1 ή 2.');
        }
        $out = [];
        foreach ($db->query('SELECT position, username FROM players ORDER BY position') as $row) {
            $out[(string)$row['position']] = ['username' => $row['username']];
        }
        return ['players' => $out];
    }

    $position = (int)$sub;
    if (!in_array($position, [1, 2], true)) {
        json_error(404, 'Η θέση παίκτη πρέπει να είναι 1 ή 2.');
    }

    if ($method === 'GET') {
        $stmt = $db->prepare('SELECT position, username FROM players WHERE position = ?');
        $stmt->execute([$position]);
        $row = $stmt->fetch();
        if (!$row) {
            json_error(404, 'Ο παίκτης δεν βρέθηκε.');
        }
        return $row;
    }

    if ($method !== 'PUT') {
        json_error(405, 'Η μέθοδος δεν επιτρέπεται.');
    }

    $username = trim((string)($input['username'] ?? ''));
    if ($username === '') {
        json_error(400, 'Απαιτείται όνομα χρήστη.');
    }

    $status = get_status($db);
    if (!in_array($status['status'], ['waiting', 'not_active'], true)) {
        json_error(400, 'Οι παίκτες μπορούν να συνδεθούν μόνο πριν ξεκινήσει γύρος.');
    }

    $token = bin2hex(random_bytes(16));
    $stmt = $db->prepare('UPDATE players SET username = ?, token = ? WHERE position = ?');
    $stmt->execute([$username, $token, $position]);
    add_player_session($db, $position, $username, $token, 'join');
    add_move_log($db, (int)$status['current_round'], $position, 'player_join', null, $username);

    return [
        'position' => (string)$position,
        'username' => $username,
        'token' => $token,
    ];
}

/** @return array<string,mixed> */
function api_board(PDO $db, string $method, string $token): array
{
    if ($method === 'GET') {
        return board_view($db, $token);
    }

    if ($method !== 'POST') {
        json_error(405, 'Η μέθοδος δεν επιτρέπεται.');
    }

    $status = get_status($db);

    if ($status['status'] === 'not_active') {
        reset_game($db);
        $db->exec("UPDATE game_status SET status='waiting', p_turn=NULL, current_round=1, result=NULL, last_collector=NULL WHERE id=1");
        add_move_log($db, 1, null, 'init_board', null, 'Η παρτίδα αρχικοποιήθηκε και περιμένει παίκτες.');
        return [
            'status' => 'waiting',
            'message' => 'Η παρτίδα αρχικοποιήθηκε. Κάνε σύνδεση παικτών με PUT /players/1 και PUT /players/2.',
        ];
    }

    if ($status['status'] === 'waiting') {
        $ready = (int)$db->query("SELECT COUNT(*) FROM players WHERE username IS NOT NULL AND TRIM(username) <> ''")->fetchColumn();
        if ($ready < 2) {
            return [
                'status' => 'waiting',
                'message' => 'Αναμονή για σύνδεση και των 2 παικτών.',
            ];
        }
        start_round($db, (int)$status['current_round']);
        $db->exec("UPDATE game_status SET status='started', p_turn=1, result=NULL, last_collector=NULL WHERE id=1");
        add_move_log($db, (int)$status['current_round'], null, 'round_start', null, 'Ο γύρος ξεκίνησε.');
        return [
            'status' => 'started',
            'message' => 'Ο γύρος ξεκίνησε. Μοιράστηκαν 6 χαρτιά ανά παίκτη και 4 στο stack.',
            'round' => (int)$status['current_round'],
            'turn' => 1,
        ];
    }

    if ($status['status'] === 'round_ended') {
        $round = (int)$status['current_round'];
        $roundScores = finalize_round_scoring($db, $round);

        $newStatus = get_status($db);
        $maxRounds = (int)$newStatus['max_rounds'];
        if ($round >= $maxRounds) {
            $s1 = (int)$db->query('SELECT total_score FROM game_totals WHERE position=1')->fetchColumn();
            $s2 = (int)$db->query('SELECT total_score FROM game_totals WHERE position=2')->fetchColumn();
            $result = $s1 === $s2 ? 'D' : ($s1 > $s2 ? '1' : '2');
            $stmt = $db->prepare("UPDATE game_status SET status='game_ended', p_turn=NULL, result=?, last_collector=NULL WHERE id=1");
            $stmt->execute([$result]);
            add_move_log($db, $round, null, 'game_ended', null, "Νικητής: {$result}, σκορ 1={$s1}, 2={$s2}");
            return [
                'status' => 'game_ended',
                'result' => $result,
                'round_scores' => $roundScores,
                'total_scores' => ['1' => $s1, '2' => $s2],
                'message' => 'Το παιχνίδι ολοκληρώθηκε.',
            ];
        }

        $nextRound = $round + 1;
        $stmt = $db->prepare("UPDATE game_status SET status='started', p_turn=1, current_round=?, result=NULL, last_collector=NULL WHERE id=1");
        $stmt->execute([$nextRound]);
        start_round($db, $nextRound);
        add_move_log($db, $nextRound, null, 'round_start', null, "Ξεκίνησε ο γύρος {$nextRound}.");

        return [
            'status' => 'started',
            'round' => $nextRound,
            'round_scores' => $roundScores,
            'message' => "Ξεκίνησε ο γύρος {$nextRound}.",
        ];
    }

    if ($status['status'] === 'game_ended') {
        return [
            'status' => 'game_ended',
            'message' => 'Το παιχνίδι έχει ήδη τελειώσει. Χρησιμοποίησε POST /reset για νέα παρτίδα.',
        ];
    }

    json_error(400, 'Δεν μπορεί να εκτελεστεί POST /board στην τρέχουσα κατάσταση: ' . $status['status']);
}

/** @return array<string,mixed> */
function board_view(PDO $db, string $token): array
{
    $status = get_status($db);
    $round = (int)$status['current_round'];
    $player = player_from_token($db, $token);
    $myPos = $player['position'] ?? null;

    // Top card is the most recently placed card on the stack.
    $topCard = $db->query('SELECT card FROM table_stack ORDER BY stack_position DESC LIMIT 1')->fetchColumn();
    $stackCount = (int)$db->query('SELECT COUNT(*) FROM table_stack')->fetchColumn();

    $myHand = [];
    if ($myPos !== null) {
        $stmt = $db->prepare('SELECT card FROM player_hand WHERE position = ? ORDER BY hand_order, card');
        $stmt->execute([(int)$myPos]);
        $myHand = array_map(static fn($r) => $r['card'], $stmt->fetchAll());
    }

    $players = [];
    foreach ([1, 2] as $pos) {
        $stmt = $db->prepare('SELECT username FROM players WHERE position = ?');
        $stmt->execute([$pos]);
        $username = $stmt->fetchColumn();
        $totalScore = (int)$db->query("SELECT total_score FROM game_totals WHERE position={$pos}")->fetchColumn();
        $col = collected_stats($db, $round, $pos);
        $handCount = (int)$db->query("SELECT COUNT(*) FROM player_hand WHERE position={$pos}")->fetchColumn();

        $players[(string)$pos] = [
            'username' => $username,
            'hand_count' => $handCount,
            'total_score' => $totalScore,
            'collected' => $col,
        ];
    }

    return [
        'game_status' => $status,
        'my_position' => $myPos ? (string)$myPos : null,
        'my_hand' => $myHand,
        'table' => [
            'top_card' => $topCard ?: null,
            'stack_count' => $stackCount,
        ],
        'players' => $players,
    ];
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function api_move(PDO $db, string $token, array $input): array
{
    if ($token === '') {
        json_error(401, 'Απαιτείται κωδικός παίκτη.');
    }
    $player = player_from_token($db, $token);
    if (!$player) {
        json_error(401, 'Μη έγκυρος κωδικός παίκτη.');
    }
    $playerPos = (int)$player['position'];

    $status = get_status($db);
    if ($status['status'] !== 'started') {
        json_error(400, 'Το παιχνίδι δεν είναι σε εξέλιξη.');
    }
    if ((int)$status['p_turn'] !== $playerPos) {
        json_error(400, 'Δεν είναι η σειρά σου.');
    }

    $action = (string)($input['action'] ?? '');
    $card = strtoupper(trim((string)($input['card'] ?? '')));
    if (!XeriGame::isValidCard($card)) {
        json_error(400, 'Απαιτείται έγκυρο χαρτί (π.χ. 2S, 10D, JC).');
    }

    $stmt = $db->prepare('SELECT COUNT(*) FROM player_hand WHERE position = ? AND card = ?');
    $stmt->execute([$playerPos, $card]);
    if ((int)$stmt->fetchColumn() === 0) {
        json_error(400, 'Το χαρτί δεν υπάρχει στο χέρι σου.');
    }

    $db->beginTransaction();
    try {
        if ($action === 'throw') {
            remove_hand_card($db, $playerPos, $card);
            push_table_card($db, $card);
            $next = other_player($playerPos);
            $stmt = $db->prepare('UPDATE game_status SET p_turn = ? WHERE id = 1');
            $stmt->execute([$next]);
            add_move_log($db, (int)$status['current_round'], $playerPos, 'throw', $card, "Επόμενη σειρά: P{$next}");

            check_round_end($db, $playerPos);
            $db->commit();
            return [
                'status' => 'ok',
                'action' => 'throw',
                'card' => $card,
                'next_turn' => (string)$next,
            ];
        }

        if ($action !== 'collect') {
            $db->rollBack();
            json_error(400, 'Το action πρέπει να είναι throw ή collect.');
        }

        $topCard = $db->query('SELECT card FROM table_stack ORDER BY stack_position DESC LIMIT 1')->fetchColumn();
        if (!$topCard) {
            $db->rollBack();
            json_error(400, 'Το τραπέζι είναι άδειο.');
        }
        $tableCount = (int)$db->query('SELECT COUNT(*) FROM table_stack')->fetchColumn();

        $canCollect = XeriGame::canCollectWithJack($card) || XeriGame::canCollectWithMatch($card, (string)$topCard);
        if (!$canCollect) {
            $db->rollBack();
            json_error(400, 'Δεν μπορείς να μαζέψεις. Χρειάζεται ίδιο top χαρτί ή Βαλέ.');
        }

        $tableCards = array_map(
            static fn($r) => $r['card'],
            $db->query('SELECT card FROM table_stack ORDER BY stack_position ASC')->fetchAll()
        );

        remove_hand_card($db, $playerPos, $card);
        $db->exec('DELETE FROM table_stack');

        $captured = array_merge($tableCards, [$card]);
        insert_collected_cards($db, (int)$status['current_round'], $playerPos, $captured);

        $isXeri = XeriGame::isXeri($tableCount);
        $isXeriJack = XeriGame::isXeriWithJack($card, $tableCount);
        $stmt = $db->prepare('INSERT INTO collect_events (round, position, is_xeri, is_xeri_with_jack) VALUES (?, ?, ?, ?)');
        $stmt->execute([(int)$status['current_round'], $playerPos, $isXeri ? 1 : 0, $isXeriJack ? 1 : 0]);

        $next = other_player($playerPos);
        $stmt = $db->prepare('UPDATE game_status SET p_turn = ?, last_collector = ? WHERE id = 1');
        $stmt->execute([$next, $playerPos]);
        $collectDetails = 'Μαζεύτηκαν ' . count($captured) . ' χαρτιά';
        if ($isXeriJack) {
            $collectDetails .= ' (Ξερή με Βαλέ +20)';
        } elseif ($isXeri) {
            $collectDetails .= ' (Ξερή +10)';
        }
        $collectDetails .= ", επόμενη σειρά P{$next}";
        add_move_log($db, (int)$status['current_round'], $playerPos, 'collect', $card, $collectDetails);

        check_round_end($db, $playerPos);
        $db->commit();

        return [
            'status' => 'ok',
            'action' => 'collect',
            'card' => $card,
            'captured_count' => count($captured),
            'xeri' => $isXeri,
            'xeri_with_jack' => $isXeriJack,
            'next_turn' => (string)$next,
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/** @return array<string,mixed> */
function get_status(PDO $db): array
{
    $status = $db->query('SELECT * FROM game_status WHERE id=1')->fetch();
    if (!$status) {
        json_error(500, 'Εσωτερικό σφάλμα: δεν βρέθηκε game_status.');
    }
    return $status;
}

/** @return array<string,mixed>|null */
function player_from_token(PDO $db, string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $stmt = $db->prepare('SELECT position, username FROM players WHERE token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function reset_game(PDO $db): void
{
    $db->exec('DELETE FROM player_hand');
    $db->exec('DELETE FROM table_stack');
    $db->exec('DELETE FROM player_collected');
    $db->exec('DELETE FROM collect_events');
    $db->exec('DELETE FROM move_log');
    $db->exec('UPDATE players SET username=NULL, token=NULL');
    add_player_session($db, 1, null, null, 'reset');
    add_player_session($db, 2, null, null, 'reset');
    $db->exec("UPDATE game_status SET status='not_active', p_turn=NULL, current_round=1, result=NULL, last_collector=NULL WHERE id=1");
    $db->exec('UPDATE game_totals SET total_score=0');
    add_move_log($db, 1, null, 'reset', null, 'Η παρτίδα έγινε reset από τον χρήστη.');
}

function start_round(PDO $db, int $round): void
{
    $deck = XeriGame::createDeck(1);
    XeriGame::shuffleDeck($deck);

    $db->exec('DELETE FROM player_hand');
    $db->exec('DELETE FROM table_stack');

    $hand1 = array_splice($deck, 0, 6);
    $hand2 = array_splice($deck, 0, 6);
    $table = array_splice($deck, 0, 4);

    $stmt = $db->prepare('INSERT INTO player_hand (position, card, hand_order) VALUES (?, ?, ?)');
    foreach ($hand1 as $i => $card) {
        $stmt->execute([1, $card, $i]);
    }
    foreach ($hand2 as $i => $card) {
        $stmt->execute([2, $card, $i]);
    }

    $stmt = $db->prepare('INSERT INTO table_stack (stack_position, card) VALUES (?, ?)');
    foreach ($table as $i => $card) {
        $stmt->execute([$i + 1, $card]);
    }

    $stmt = $db->prepare('DELETE FROM player_collected WHERE round = ?');
    $stmt->execute([$round]);
    $stmt = $db->prepare('DELETE FROM collect_events WHERE round = ?');
    $stmt->execute([$round]);
}

function remove_hand_card(PDO $db, int $position, string $card): void
{
    $stmt = $db->prepare('DELETE FROM player_hand WHERE position = ? AND card = ?');
    $stmt->execute([$position, $card]);
}

function push_table_card(PDO $db, string $card): void
{
    $max = (int)$db->query('SELECT COALESCE(MAX(stack_position),0) FROM table_stack')->fetchColumn();
    $stmt = $db->prepare('INSERT INTO table_stack (stack_position, card) VALUES (?, ?)');
    $stmt->execute([$max + 1, $card]);
}

/**
 * @param string[] $cards
 */
function insert_collected_cards(PDO $db, int $round, int $position, array $cards): void
{
    $stmt = $db->prepare('SELECT COALESCE(MAX(collected_order), 0) FROM player_collected WHERE round = ? AND position = ?');
    $stmt->execute([$round, $position]);
    $start = (int)$stmt->fetchColumn();

    $ins = $db->prepare('INSERT INTO player_collected (round, position, card, collected_order) VALUES (?, ?, ?, ?)');
    $ord = $start;
    foreach ($cards as $card) {
        $ord++;
        $ins->execute([$round, $position, $card, $ord]);
    }
}

function check_round_end(PDO $db, int $currentPlayer): void
{
    $c1 = (int)$db->query('SELECT COUNT(*) FROM player_hand WHERE position = 1')->fetchColumn();
    $c2 = (int)$db->query('SELECT COUNT(*) FROM player_hand WHERE position = 2')->fetchColumn();
    if ($c1 !== 0 || $c2 !== 0) {
        return;
    }

    $tableCount = (int)$db->query('SELECT COUNT(*) FROM table_stack')->fetchColumn();
    if ($tableCount > 0) {
        $lastCollector = (int)$db->query('SELECT COALESCE(last_collector, 0) FROM game_status WHERE id=1')->fetchColumn();
        $receiver = $lastCollector > 0 ? $lastCollector : $currentPlayer;
        $round = (int)$db->query('SELECT current_round FROM game_status WHERE id=1')->fetchColumn();
        $tableCards = array_map(
            static fn($r) => $r['card'],
            $db->query('SELECT card FROM table_stack ORDER BY stack_position ASC')->fetchAll()
        );
        insert_collected_cards($db, $round, $receiver, $tableCards);
        $db->exec('DELETE FROM table_stack');
    }

    $db->exec("UPDATE game_status SET status='round_ended', p_turn=NULL WHERE id=1");
}

/** @return array<string,int> */
function finalize_round_scoring(PDO $db, int $round): array
{
    $cardsByPlayer = [];
    foreach ([1, 2] as $pos) {
        $stmt = $db->prepare('SELECT card FROM player_collected WHERE round = ? AND position = ? ORDER BY collected_order');
        $stmt->execute([$round, $pos]);
        $cardsByPlayer[$pos] = array_map(static fn($r) => $r['card'], $stmt->fetchAll());
    }

    $counts = [
        1 => count($cardsByPlayer[1]),
        2 => count($cardsByPlayer[2]),
    ];
    $maxCount = max($counts);
    $hasTie = $counts[1] === $counts[2];

    $eventStmt = $db->prepare('SELECT COALESCE(SUM(is_xeri),0) AS x, COALESCE(SUM(is_xeri_with_jack),0) AS j FROM collect_events WHERE round = ? AND position = ?');
    $roundScores = [1 => 0, 2 => 0];

    foreach ([1, 2] as $pos) {
        $eventStmt->execute([$round, $pos]);
        $events = $eventStmt->fetch() ?: ['x' => 0, 'j' => 0];
        $score = XeriGame::calculateRoundScore(
            $cardsByPlayer[$pos],
            (int)$events['x'],
            (int)$events['j'],
            $counts[$pos] === $maxCount,
            $hasTie
        );
        $roundScores[$pos] = $score;
        $upd = $db->prepare('UPDATE game_totals SET total_score = total_score + ? WHERE position = ?');
        $upd->execute([$score, $pos]);
    }

    return ['1' => $roundScores[1], '2' => $roundScores[2]];
}

/** @return array<string,mixed> */
function collected_stats(PDO $db, int $round, int $position): array
{
    $stmt = $db->prepare('SELECT card FROM player_collected WHERE round = ? AND position = ? ORDER BY collected_order');
    $stmt->execute([$round, $position]);
    $cards = array_map(static fn($r) => $r['card'], $stmt->fetchAll());

    $evt = $db->prepare('SELECT COALESCE(SUM(is_xeri),0) AS x, COALESCE(SUM(is_xeri_with_jack),0) AS j FROM collect_events WHERE round = ? AND position = ?');
    $evt->execute([$round, $position]);
    $ev = $evt->fetch() ?: ['x' => 0, 'j' => 0];

    $faceCount = 0;
    foreach ($cards as $card) {
        if (XeriGame::isFaceOrTen($card)) {
            $faceCount++;
        }
    }

    return [
        'total_cards' => count($cards),
        'xeri_count' => (int)$ev['x'],
        'xeri_with_jack_count' => (int)$ev['j'],
        'has_2_spades' => in_array('2S', $cards, true),
        'has_10_diamonds' => in_array('10D', $cards, true),
        'face_and_10_count' => $faceCount,
    ];
}

function other_player(int $position): int
{
    return $position === 1 ? 2 : 1;
}

function add_move_log(PDO $db, int $round, ?int $position, string $action, ?string $card, ?string $details = null): void
{
    $username = null;
    $token = null;
    if ($position !== null) {
        $stmtPlayer = $db->prepare('SELECT username, token FROM players WHERE position = ?');
        $stmtPlayer->execute([$position]);
        $row = $stmtPlayer->fetch();
        if ($row) {
            $username = $row['username'] ?? null;
            $token = $row['token'] ?? null;
        }
    }

    $stmt = $db->prepare('INSERT INTO move_log (round, position, player_username, player_token, action, card, details) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$round, $position, $username, $token, $action, $card, $details]);
}

function add_player_session(PDO $db, int $position, ?string $username, ?string $token, string $eventType): void
{
    $stmt = $db->prepare('INSERT INTO player_sessions (position, username, token, event_type) VALUES (?, ?, ?, ?)');
    $stmt->execute([$position, $username, $token, $eventType]);
}

function render_home(): void
{
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ξερή - Τοπικός Υπολογιστής</title>
    <style>
        :root {
            --bg: #0c1224;
            --panel: #131d39;
            --line: #2a3b6f;
            --text: #eaf0ff;
            --muted: #9aa9d7;
            --primary: #7c3aed;
            --primary-dark: #5b21b6;
            --ok: #0f9d58;
            --warn: #d97706;
            --danger: #dc2626;
        }
        * { box-sizing: border-box; }
        body {
            font-family: "Segoe UI", Arial, sans-serif;
            margin: 0;
            background:
                radial-gradient(circle at 10% 10%, #1b2d64 0%, transparent 45%),
                radial-gradient(circle at 90% 0%, #4c1d95 0%, transparent 40%),
                linear-gradient(160deg, #0a1020 0%, #0f1730 45%, #0a1020 100%);
            color: var(--text);
        }
        .wrap { max-width: 1160px; margin: 0 auto; padding: 20px; }
        h1 {
            margin: 0;
            font-size: 34px;
            letter-spacing: 0.5px;
            text-shadow: 0 0 16px rgba(124, 58, 237, 0.45);
        }
        h3 { margin: 0 0 10px; font-size: 18px; }
        .muted { color: var(--muted); font-size: 13px; }
        .row { display: grid; gap: 16px; margin-top: 16px; }
        .row-2 { grid-template-columns: 1.15fr 1fr; }
        .row-3 { grid-template-columns: repeat(3, 1fr); }
        .panel {
            background: linear-gradient(180deg, rgba(25, 36, 71, 0.92) 0%, rgba(17, 27, 55, 0.92) 100%);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 14px;
            box-shadow: 0 8px 22px rgba(5, 10, 25, 0.45);
            backdrop-filter: blur(4px);
        }
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 10px; }
        .badge { display: inline-block; border-radius: 999px; padding: 6px 10px; font-size: 12px; font-weight: 600; background: #273a72; color: #d7e3ff; margin-right: 6px; border: 1px solid #38539d; }
        .badge.ok { background: #144933; color: #caffdf; border-color: #1d774f; }
        .badge.warn { background: #66410f; color: #ffe6bf; border-color: #8b5a15; }
        .badge.danger { background: #6b1b1b; color: #ffd0d0; border-color: #8e2929; }
        .controls { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        button {
            border: 1px solid #4960a8;
            border-radius: 10px;
            background: #1a274b;
            color: #e7eeff;
            font-weight: 600;
            padding: 8px 12px;
            cursor: pointer;
            transition: 0.15s;
        }
        button:hover { transform: translateY(-1px); border-color: #7f98ec; background: #223565; }
        button:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
        .btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
        .btn-primary:hover { background: var(--primary-dark); border-color: var(--primary-dark); }
        .btn-soft { background: #1a274b; border-color: #4960a8; }
        input, select {
            border: 1px solid #4960a8;
            border-radius: 10px;
            padding: 8px 10px;
            min-width: 150px;
            color: #f0f5ff;
            background: #111a34;
        }
        .statusline {
            margin-top: 10px;
            border-radius: 10px;
            padding: 10px 12px;
            font-size: 14px;
            border: 1px solid #d4dbf5;
            background: #f7f9ff;
        }
        .statusline.ok { border-color: #b8ebcb; background: #effcf4; color: #0f6a39; }
        .statusline.warn { border-color: #ffe1b7; background: #fff8ed; color: #8a5200; }
        .statusline.danger { border-color: #ffc9c9; background: #fff0f0; color: #9f2222; }
        .players { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
        .player-box { border: 1px solid var(--line); border-radius: 10px; padding: 10px; background: #111a34; }
        .player-box.turn { border-color: #a78bfa; box-shadow: inset 0 0 0 2px rgba(167, 139, 250, 0.28); }
        .player-title { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; font-weight: 700; }
        .metrics { font-size: 13px; color: #40507f; line-height: 1.45; }
        .score-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-top: 12px; }
        .score-card { border: 1px solid var(--line); border-radius: 12px; padding: 12px; background: #111a34; }
        .score-card.turn { border-color: #a78bfa; box-shadow: inset 0 0 0 2px rgba(167, 139, 250, 0.28); }
        .score-head { display: flex; justify-content: space-between; align-items: center; font-weight: 700; margin-bottom: 8px; }
        .score-total { font-size: 28px; font-weight: 800; color: #d7c6ff; line-height: 1; }
        .score-row { display: flex; justify-content: space-between; font-size: 13px; color: #394b80; margin-top: 4px; }
        .score-projected { margin-top: 8px; font-size: 13px; border-top: 1px dashed #ccd7f5; padding-top: 8px; color: #22366f; }
        .table-card-wrap { display: flex; gap: 12px; align-items: center; margin-top: 8px; }
        .table-stack { width: 90px; height: 125px; border-radius: 12px; border: 1px dashed #5875c9; display: flex; align-items: center; justify-content: center; background: #111a34; color: #b5c8ff; }
        .playing-card {
            width: 84px; height: 118px; border-radius: 12px; border: 1px solid #8ea0db;
            background: #fff; display: flex; flex-direction: column; justify-content: space-between; padding: 8px;
            font-weight: 700; color: #1e2b5e; box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        }
        .playing-card.red { color: #b51d2a; border-color: #d79aa0; }
        .playing-card .center { text-align: center; font-size: 30px; margin-top: 8px; }
        .hand { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 8px; }
        .card-btn {
            border: 1px solid #8ea0db; border-radius: 10px; background: #fff; padding: 6px;
            width: 76px; height: 104px; display: flex; align-items: center; justify-content: center;
        }
        .card-btn.selected { outline: 3px solid #c8d8ff; border-color: #2a62e4; transform: translateY(-3px); }
        .card-btn .playing-card { width: 62px; height: 90px; border-radius: 9px; box-shadow: none; border-color: #a3b3e7; }
        .actions { margin-top: 10px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .guide-list { margin: 0; padding-left: 18px; line-height: 1.7; color: #374472; font-size: 14px; }
        .guide-tip { margin-top: 10px; padding: 10px; border: 1px solid #dbe4ff; background: #f6f9ff; border-radius: 10px; font-size: 13px; color: #2f427e; }
        .history-list { max-height: 280px; overflow: auto; border: 1px solid #38539d; border-radius: 10px; background: #111a34; }
        .history-item { padding: 8px 10px; border-bottom: 1px solid #2a3f79; font-size: 13px; color: #d8e3ff; }
        .history-item:last-child { border-bottom: none; }
        .history-head { display: flex; justify-content: space-between; gap: 8px; margin-bottom: 2px; font-weight: 600; }
        .history-meta { color: #6a769e; font-size: 12px; }
        .history-empty { padding: 12px; color: #5f6b94; font-size: 13px; }
        pre { white-space: pre-wrap; word-break: break-word; background: #090f1e; color: #dde4ff; padding: 12px; border-radius: 10px; max-height: 360px; overflow: auto; border: 1px solid #253a74; }
        .top-change { animation: pulseTop .45s ease-out; }
        @keyframes pulseTop {
            0% { transform: scale(1); }
            45% { transform: scale(1.08); box-shadow: 0 0 0 6px rgba(31, 89, 224, 0.15); }
            100% { transform: scale(1); box-shadow: none; }
        }
        .toast {
            position: fixed; right: 16px; bottom: 16px; min-width: 280px; max-width: 400px;
            padding: 10px 12px; border-radius: 10px; color: #fff; font-weight: 600;
            background: #334155; box-shadow: 0 8px 18px rgba(0,0,0,0.2); opacity: 0; transform: translateY(8px);
            transition: .2s; z-index: 99; pointer-events: none;
        }
        .toast.show { opacity: 1; transform: translateY(0); }
        .toast.ok { background: #116b3f; }
        .toast.warn { background: #a16207; }
        .toast.danger { background: #b91c1c; }
        details summary { cursor: pointer; font-weight: 600; color: #2b3f8d; }
        @media (max-width: 900px) {
            .row-2, .row-3, .players, .score-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="topbar">
            <div>
                <h1>Ξερή</h1>
            </div>
            <div id="roundBadges"></div>
        </div>

        <section class="panel" style="margin-top:12px;">
            <h3>Πίνακας Σκορ</h3>
            <div class="muted">Ζωντανή εικόνα πόντων κατά τη διάρκεια του γύρου και συνολικά στο παιχνίδι.</div>
            <div id="scoreboardView" class="score-grid"></div>
        </section>

        <div class="row row-2">
            <section class="panel">
                <h3>Ροή Παιχνιδιού</h3>
                <div class="controls">
                    <button id="boardActionBtn" class="btn-primary" onclick="postBoard()">Αρχικοποίηση / Έναρξη / Επόμενος γύρος</button>
                    <button class="btn-soft" onclick="postReset()">Επαναφορά</button>
                </div>
                <div class="controls" style="margin-top:10px;">
                    <input id="usernameInput" placeholder="Το όνομα σου">
                    <button onclick="join(1)">Σύνδεση ως P1</button>
                    <button onclick="join(2)">Σύνδεση ως P2</button>
                </div>
                <div id="sessionInfo" class="muted" style="margin-top:8px;"></div>
                <div class="controls" style="margin-top:8px;">
                    <button class="btn-soft" onclick="clearSession()">Αποσύνδεση από αυτόν τον browser</button>
                </div>
                <div id="hintBox" class="statusline"></div>
            </section>

            <section class="panel">
                <h3>Παίκτες</h3>
                <div id="playersView" class="players"></div>
                <h3 style="margin-top:12px;">Οδηγός Χρήσης (εύκολος)</h3>
                <ol class="guide-list">
                    <li>Πάτησε <b>Αρχικοποίηση παρτίδας</b>.</li>
                    <li>Από <b>διαφορετικό browser/PC</b>, κάθε παίκτης κάνει σύνδεση ως <b>P1</b> ή <b>P2</b>.</li>
                    <li>Το παιχνίδι ξεκινάει αυτόματα όταν συνδεθούν και οι 2. Κοίτα το πλαίσιο "Παίζει τώρα".</li>
                    <li>Πάτησε σε κάρτα από το χέρι σου.</li>
                    <li><b>Ρίξε</b> για απλό πέταγμα ή <b>Μάζεψε</b> αν ταιριάζει το πάνω χαρτί ή έχεις Βαλέ.</li>
                    <li>Όταν τελειώσει ο γύρος, πάτησε <b>Έναρξη επόμενου γύρου</b>.</li>
                </ol>
                <div class="guide-tip">
                    Συμβουλή: Κάθε browser είναι για έναν παίκτη. Αν μπήκες με λάθος ρόλο, πάτα "Αποσύνδεση από αυτόν τον browser" και ξανασυνδέσου σωστά.
                </div>
            </section>
        </div>

        <div class="row row-2">
            <section class="panel">
                <h3>Τραπέζι</h3>
                <div id="tableView" class="table-card-wrap"></div>

                <h3 style="margin-top:12px;">Χέρι μου</h3>
                <div id="myHand" class="hand"></div>

                <div class="actions">
                    <button id="throwBtn" class="btn-primary" onclick="play('throw')">Ρίξε στο τραπέζι</button>
                    <button id="collectBtn" onclick="play('collect')">Μάζεψε στοίβα</button>
                    <span id="selectedLabel" class="muted">Δεν έχει επιλεγεί χαρτί.</span>
                </div>
            </section>

            <section class="panel">
                <h3>Ιστορικό Κινήσεων</h3>
                <div id="historyView" class="history-list"></div>
                <details style="margin-top:10px;">
                    <summary>Τεχνικά δεδομένα (JSON)</summary>
                    <pre id="statusJson"></pre>
                </details>
            </section>
        </div>
    </div>
    <div id="toast" class="toast"></div>

    <script>
        let selectedCard = null;
        let cachedBoard = null;
        let cachedStatus = null;
        let cachedHistory = [];
        let previousTopCard = null;
        let lastHandSignature = '';
        const sessionTokenKey = 'xeri_session_token';
        const sessionPosKey = 'xeri_session_position';
        const sessionNameKey = 'xeri_session_username';

        function apiUrl(path, token = '') {
            let url = `${location.pathname}?request=${encodeURIComponent(path)}`;
            if (token) url += `&token=${encodeURIComponent(token)}`;
            return url;
        }

        async function callApi(path, method = 'GET', body = null, token = '') {
            const headers = { 'Content-Type': 'application/json' };
            if (token) {
                headers['App-Token'] = token;
                headers['X-App-Token'] = token;
                headers['HTTP_APP_TOKEN'] = token; // backward compatibility
            }
            const res = await fetch(apiUrl(path, token), {
                method,
                headers,
                body: body ? JSON.stringify(body) : null
            });
            return res.json();
        }

        function showToast(text, type = '') {
            const box = document.getElementById('toast');
            box.textContent = text;
            box.className = `toast show ${type}`.trim();
            clearTimeout(showToast._timer);
            showToast._timer = setTimeout(() => {
                box.className = 'toast';
            }, 2600);
        }

        function setSession(token, position, username) {
            localStorage.setItem(sessionTokenKey, token || '');
            localStorage.setItem(sessionPosKey, String(position || ''));
            localStorage.setItem(sessionNameKey, username || '');
        }

        function clearSession() {
            localStorage.removeItem(sessionTokenKey);
            localStorage.removeItem(sessionPosKey);
            localStorage.removeItem(sessionNameKey);
            selectedCard = null;
            lastHandSignature = '';
            showToast('Έγινε αποσύνδεση από αυτόν τον browser', 'ok');
            refresh();
        }

        function activeToken() {
            return localStorage.getItem(sessionTokenKey) || '';
        }

        function activePosition() {
            const pos = Number(localStorage.getItem(sessionPosKey) || 0);
            return pos || null;
        }

        function renderSessionInfo() {
            const el = document.getElementById('sessionInfo');
            const token = activeToken();
            const pos = activePosition();
            const name = localStorage.getItem(sessionNameKey) || '';
            if (!token || !pos) {
                el.textContent = 'Δεν έχεις συνδεθεί ακόμα σε αυτόν τον browser.';
                return;
            }
            el.textContent = `Συνδεδεμένος ως P${pos}${name ? ` (${name})` : ''}.`;
        }

        async function join(position) {
            const username = document.getElementById('usernameInput').value.trim();
            if (!username) return showToast('Βάλε όνομα χρήστη', 'warn');

            const currentPos = activePosition();
            if (currentPos && currentPos !== position) {
                showToast(`Είσαι ήδη συνδεδεμένος ως P${currentPos}. Κάνε πρώτα αποσύνδεση.`, 'warn');
                return;
            }

            const data = await callApi(`players/${position}`, 'PUT', { username });
            if (data.errormesg) {
                showToast(data.errormesg, 'danger');
                return;
            }
            if (data.token) {
                setSession(data.token, position, username);
                showToast(`Ο P${position} μπήκε στο παιχνίδι`, 'ok');
            }
            await refresh();
            const p1 = cachedStatus?.players?.['1']?.username;
            const p2 = cachedStatus?.players?.['2']?.username;
            const gameStatus = cachedStatus?.game_status?.status;
            if (gameStatus === 'waiting' && p1 && p2) {
                await postBoard(true);
            }
        }

        async function postBoard(silent = false) {
            const data = await callApi('board', 'POST', { token: activeToken() }, activeToken());
            if (!silent) showToast(data.message || JSON.stringify(data), data.errormesg ? 'danger' : 'ok');
            await refresh();
        }

        async function postReset() {
            await callApi('reset', 'POST');
            selectedCard = null;
            showToast('Το παιχνίδι έγινε reset', 'ok');
            await refresh();
        }

        function pick(card) {
            selectedCard = card;
            renderHand();
            renderActionState();
        }

        function parseCard(card) {
            if (!card) return null;
            const suit = card.slice(-1);
            const fig = card.slice(0, -1);
            const suitMap = { S: '♠', H: '♥', D: '♦', C: '♣' };
            const red = suit === 'H' || suit === 'D';
            return { raw: card, fig, suit, symbol: suitMap[suit] || '?', red };
        }

        function cardHtml(card) {
            const c = parseCard(card);
            if (!c) return '<div class="table-stack">Κενό</div>';
            return `
                <div class="playing-card ${c.red ? 'red' : ''}">
                    <div>${c.fig}${c.symbol}</div>
                    <div class="center">${c.symbol}</div>
                    <div style="text-align:right">${c.fig}${c.symbol}</div>
                </div>
            `;
        }

        function renderHand(force = false) {
            const handDiv = document.getElementById('myHand');
            const hand = cachedBoard?.my_hand || [];
            const handSignature = hand.join('|');
            const selectedLabel = document.getElementById('selectedLabel');

            if (!force && handSignature === lastHandSignature) {
                const buttons = handDiv.querySelectorAll('.card-btn');
                buttons.forEach(btn => {
                    btn.classList.toggle('selected', btn.dataset.card === selectedCard);
                });
                selectedLabel.textContent = selectedCard ? `Επιλεγμένο: ${selectedCard}` : 'Δεν έχει επιλεγεί χαρτί.';
                return;
            }

            lastHandSignature = handSignature;
            handDiv.innerHTML = '';
            if (hand.length === 0) {
                const span = document.createElement('span');
                span.className = 'muted';
                span.textContent = 'Δεν φαίνονται χαρτιά. Σύνδεση ως P1/P2 και έπειτα έναρξη γύρου.';
                handDiv.appendChild(span);
                selectedLabel.textContent = 'Δεν έχει επιλεγεί χαρτί.';
                return;
            }
            hand.forEach(card => {
                const b = document.createElement('button');
                b.className = 'card-btn';
                b.dataset.card = card;
                if (selectedCard === card) b.classList.add('selected');
                b.onclick = () => pick(card);
                b.innerHTML = cardHtml(card);
                handDiv.appendChild(b);
            });
            selectedLabel.textContent = selectedCard ? `Επιλεγμένο: ${selectedCard}` : 'Δεν έχει επιλεγεί χαρτί.';
        }

        async function play(action) {
            if (!selectedCard) return showToast('Επίλεξε πρώτα χαρτί', 'warn');
            const st = cachedStatus?.game_status?.status;
            if (st !== 'started') {
                return showToast('Ο γύρος δεν έχει ξεκινήσει. Πάτησε Αρχικοποίηση/Έναρξη.', 'warn');
            }
            const data = await callApi('move', 'POST', {
                token: activeToken(),
                action,
                card: selectedCard
            }, activeToken());
            if (data.errormesg) {
                showToast(data.errormesg, 'danger');
            } else {
                const msg = action === 'throw' ? `Έριξες ${selectedCard}` : `Μάζεψες με ${selectedCard}`;
                showToast(msg, 'ok');
            }
            selectedCard = null;
            await refresh();
        }

        function renderPlayers() {
            const wrap = document.getElementById('playersView');
            wrap.innerHTML = '';
            const players = cachedBoard?.players || {};
            const turn = Number(cachedStatus?.game_status?.p_turn || 0);
            [1,2].forEach(p => {
                const data = players[String(p)] || {};
                const col = data.collected || {};
                const username = data.username || `(P${p} χωρίς όνομα)`;
                const div = document.createElement('div');
                div.className = `player-box ${turn === p ? 'turn' : ''}`;
                div.innerHTML = `
                    <div class="player-title">
                        <span>P${p} - ${username}</span>
                        ${turn === p ? '<span class="badge ok">Παίζει τώρα</span>' : ''}
                    </div>
                    <div class="metrics">
                        Χέρι: ${data.hand_count ?? 0} | Συνολικό σκορ: ${data.total_score ?? 0}<br>
                        Μαζεμένα: ${col.total_cards ?? 0} | Ξερές: ${col.xeri_count ?? 0} | Ξερές με Βαλέ: ${col.xeri_with_jack_count ?? 0}<br>
                        2Σ: ${(col.has_2_spades ? 'Ναι' : 'Όχι')} | 10Κ: ${(col.has_10_diamonds ? 'Ναι' : 'Όχι')} | K/Q/J/10: ${col.face_and_10_count ?? 0}
                    </div>
                `;
                wrap.appendChild(div);
            });
        }

        function liveRoundScoreFromCollected(col) {
            const has2S = col?.has_2_spades ? 1 : 0;
            const has10D = col?.has_10_diamonds ? 1 : 0;
            const faceAnd10 = Number(col?.face_and_10_count || 0);
            const xeriCount = Number(col?.xeri_count || 0);
            const xeriJackCount = Number(col?.xeri_with_jack_count || 0);
            const normalXeri = Math.max(0, xeriCount - xeriJackCount);
            return has2S + has10D + faceAnd10 + (normalXeri * 10) + (xeriJackCount * 20);
        }

        function currentMostCardsBonus(p, playersData) {
            const c1 = Number(playersData?.['1']?.collected?.total_cards || 0);
            const c2 = Number(playersData?.['2']?.collected?.total_cards || 0);
            if (c1 === c2) return 0;
            if (p === 1 && c1 > c2) return 3;
            if (p === 2 && c2 > c1) return 3;
            return 0;
        }

        function renderScoreboard() {
            const wrap = document.getElementById('scoreboardView');
            const players = cachedBoard?.players || {};
            const turn = Number(cachedStatus?.game_status?.p_turn || 0);
            wrap.innerHTML = '';

            [1, 2].forEach(p => {
                const data = players[String(p)] || {};
                const col = data.collected || {};
                const username = data.username || `(P${p})`;
                const totalCommitted = Number(data.total_score || 0);
                const liveBase = liveRoundScoreFromCollected(col);
                const liveMostBonus = currentMostCardsBonus(p, players);
                const projected = totalCommitted + liveBase + liveMostBonus;
                const turnBadge = turn === p ? '<span class="badge ok">Παίζει τώρα</span>' : '';

                const div = document.createElement('div');
                div.className = `score-card ${turn === p ? 'turn' : ''}`;
                div.innerHTML = `
                    <div class="score-head">
                        <span>P${p} - ${username}</span>
                        ${turnBadge}
                    </div>
                    <div class="score-total">${totalCommitted}</div>
                    <div class="muted">Συνολικοί πόντοι (οριστικοί)</div>
                    <div class="score-row"><span>Τρέχοντα πόντοι γύρου (χωρίς +3):</span><b>${liveBase}</b></div>
                    <div class="score-row"><span>Μπόνους περισσότερων καρτών τώρα:</span><b>${liveMostBonus}</b></div>
                    <div class="score-projected"><b>Προβολή αν τελείωνε τώρα:</b> ${projected}</div>
                `;
                wrap.appendChild(div);
            });
        }

        function renderTable() {
            const top = cachedBoard?.table?.top_card || null;
            const count = cachedBoard?.table?.stack_count ?? 0;
            const table = document.getElementById('tableView');
            const topChanged = previousTopCard !== null && previousTopCard !== top;
            const cardClass = topChanged ? 'top-change' : '';
            table.innerHTML = `
                <div class="${cardClass}">${cardHtml(top)}</div>
                <div>
                    <div class="table-stack">Στοίβα: ${count}</div>
                    <div class="muted" style="margin-top:8px;">Μόνο το πάνω χαρτί είναι ορατό/παίξιμο.</div>
                </div>
            `;
            previousTopCard = top;
        }

        function formatAction(action) {
            const map = {
                reset: 'Επαναφορά παιχνιδιού',
                init_board: 'Αρχικοποίηση παρτίδας',
                player_join: 'Σύνδεση παίκτη',
                round_start: 'Έναρξη γύρου',
                throw: 'Ρίξιμο κάρτας',
                collect: 'Μάζεμα καρτών',
                game_ended: 'Τέλος παιχνιδιού'
            };
            return map[action] || action;
        }

        function renderHistory() {
            const wrap = document.getElementById('historyView');
            wrap.innerHTML = '';
            if (!cachedHistory || cachedHistory.length === 0) {
                wrap.innerHTML = '<div class="history-empty">Δεν υπάρχουν ακόμα κινήσεις.</div>';
                return;
            }
            cachedHistory.forEach(item => {
                const div = document.createElement('div');
                div.className = 'history-item';
                const who = item.position ? `P${item.position}` : 'Σύστημα';
                const whoName = item.player_username ? ` (${item.player_username})` : '';
                const card = item.card ? ` | ${item.card}` : '';
                const details = item.details ? `<div class="history-meta">${item.details}</div>` : '';
                div.innerHTML = `
                    <div class="history-head">
                        <span>${formatAction(item.action)} - ${who}${whoName}${card}</span>
                        <span class="history-meta">Γ${item.round}</span>
                    </div>
                    ${details}
                `;
                wrap.appendChild(div);
            });
            wrap.scrollTop = 0;
        }

        function renderRoundBadges() {
            const gs = cachedStatus?.game_status || {};
            const currentRound = gs.current_round ?? '-';
            const status = gs.status || '-';
            const turn = gs.p_turn || '-';
            const me = activePosition();
            const html = `
                <span class="badge">Γύρος: ${currentRound}</span>
                <span class="badge ${status === 'started' ? 'ok' : status === 'waiting' ? 'warn' : ''}">Κατάσταση: ${statusLabel(status)}</span>
                <span class="badge">Σειρά: ${turn || '-'}</span>
                <span class="badge">${me ? `Εσύ: P${me}` : 'Εσύ: μη συνδεδεμένος'}</span>
            `;
            document.getElementById('roundBadges').innerHTML = html;
        }

        function statusLabel(status) {
            const map = {
                not_active: 'Ανενεργό',
                waiting: 'Αναμονή',
                started: 'Σε εξέλιξη',
                round_ended: 'Τέλος γύρου',
                game_ended: 'Τέλος παιχνιδιού'
            };
            return map[status] || status;
        }

        function renderHints() {
            const hint = document.getElementById('hintBox');
            const btn = document.getElementById('boardActionBtn');
            const gs = cachedStatus?.game_status?.status || '-';
            const turn = cachedStatus?.game_status?.p_turn || '-';
            const activePos = Number(cachedBoard?.my_position || activePosition() || 0);

            if (gs === 'not_active') {
                btn.textContent = 'Αρχικοποίηση παρτίδας';
                hint.className = 'statusline warn';
                hint.textContent = 'Βήμα 1: Πάτα Αρχικοποίηση παρτίδας.';
                return;
            }
            if (gs === 'waiting') {
                btn.textContent = 'Έναρξη παιχνιδιού';
                hint.className = 'statusline warn';
                hint.textContent = 'Βήμα 2: Κάνε Σύνδεση P1 και Σύνδεση P2. Το παιχνίδι ξεκινά αυτόματα όταν μπουν και οι 2.';
                return;
            }
            if (gs === 'started') {
                btn.textContent = 'Σε εξέλιξη';
                if (activePos && Number(turn) !== Number(activePos)) {
                    hint.className = 'statusline danger';
                    hint.textContent = `Σειρά παίζει: P${turn}. Εσύ είσαι P${activePos}. Περιμένεις τον άλλο παίκτη.`;
                } else {
                    hint.className = 'statusline ok';
                    hint.textContent = `Σειρά παίζει: P${turn}. Εσύ είσαι ${activePos ? `P${activePos}` : 'μη συνδεδεμένος'}.`;
                }
                return;
            }
            if (gs === 'round_ended') {
                btn.textContent = 'Έναρξη επόμενου γύρου';
                hint.className = 'statusline warn';
                hint.textContent = 'Ο γύρος τελείωσε. Πάτα Έναρξη επόμενου γύρου.';
                return;
            }
            if (gs === 'game_ended') {
                btn.textContent = 'Το παιχνίδι τελείωσε';
                hint.className = 'statusline danger';
                hint.textContent = 'Το παιχνίδι τελείωσε. Πάτα Επαναφορά για νέο παιχνίδι.';
                return;
            }
            btn.textContent = 'Αρχικοποίηση / Έναρξη / Επόμενος γύρος';
            hint.className = 'statusline';
            hint.textContent = '';
        }

        function renderActionState() {
            const throwBtn = document.getElementById('throwBtn');
            const collectBtn = document.getElementById('collectBtn');
            const gs = cachedStatus?.game_status?.status;
            const turn = Number(cachedStatus?.game_status?.p_turn || 0);
            const me = Number(cachedBoard?.my_position || activePosition() || 0);
            const active = gs === 'started' && me && turn === me;
            const hasCard = !!selectedCard;
            throwBtn.disabled = !(active && hasCard);
            collectBtn.disabled = !(active && hasCard);
        }

        async function refresh() {
            const token = activeToken();
            const [status, board, history] = await Promise.all([
                callApi('status'),
                callApi('board', 'GET', null, token),
                callApi('history')
            ]);
            cachedStatus = status;
            cachedBoard = board;
            cachedHistory = history?.items || [];
            document.getElementById('statusJson').textContent = JSON.stringify({ status, board }, null, 2);
            renderTable();
            renderHand();
            renderScoreboard();
            renderPlayers();
            renderHistory();
            renderSessionInfo();
            renderHints();
            renderRoundBadges();
            renderActionState();
        }

        renderSessionInfo();
        refresh();
        setInterval(refresh, 2500);
    </script>
</body>
</html>
<?php
}
