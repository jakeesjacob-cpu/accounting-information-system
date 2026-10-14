<?php
session_start();
date_default_timezone_set('Asia/Manila');

$dbHost = 'localhost';
$dbName = 'accounting_information_system';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    exit('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function require_login() {
    if (empty($_SESSION['user'])) {
        header('Location: index.php');
        exit;
    }
}

function require_role($roles) {
    require_login();
    $roles = (array)$roles;
    if (!in_array($_SESSION['user']['role'], $roles, true)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function audit($pdo, $action) {
    if (!empty($_SESSION['user']['user_id'])) {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action) VALUES (?, ?)");
        $stmt->execute([$_SESSION['user']['user_id'], $action]);
    }
}

function totp_code($secret, $time = null) {
    $time = $time ?? time();
    $key = base64_decode($secret);
    $counter = intdiv($time, 30);
    $binCounter = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $binCounter, $key, true);
    $offset = ord($hash[19]) & 15;
    $binary = ((ord($hash[$offset]) & 127) << 24) |
              ((ord($hash[$offset + 1]) & 255) << 16) |
              ((ord($hash[$offset + 2]) & 255) << 8) |
              (ord($hash[$offset + 3]) & 255);
    return str_pad((string)($binary % 1000000), 6, '0', STR_PAD_LEFT);
}

function csrf() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function check_csrf() {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        exit('Invalid request.');
    }
}

/* ------------------------------------------------------------------
 * Journal entry helpers (shared by accounting.php and admin.php)
 * ------------------------------------------------------------------ */

// Debits must equal credits and the entry cannot be empty.
function journal_is_balanced(array $debits, array $credits) {
    $d = array_sum(array_map('floatval', $debits));
    $c = array_sum(array_map('floatval', $credits));
    return abs($d - $c) < 0.005 && $d > 0;
}

// Accepts an account code, an account name, or "101 - Cash" (datalist format).
function find_account(PDO $pdo, $input) {
    $input = trim((string)$input);
    if ($input === '') return null;
    $code = trim(explode(' - ', $input, 2)[0]);
    $stmt = $pdo->prepare("SELECT account_id FROM chart_of_accounts WHERE account_code=? OR LOWER(account_name)=LOWER(?) LIMIT 1");
    $stmt->execute([$code, $input]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

// Validates and saves a journal entry. Returns ['ok'=>bool,'valid'=>bool,'message'=>string].
function save_journal_entry(PDO $pdo, $studentId, $date, $desc, array $accounts, array $debits, array $credits) {
    $lines = [];
    foreach ($accounts as $i => $raw) {
        $d = (float)($debits[$i] ?? 0);
        $c = (float)($credits[$i] ?? 0);
        if (trim((string)$raw) === '' && $d == 0 && $c == 0) continue; // skip blank rows
        $id = find_account($pdo, $raw);
        if ($id === null) {
            return ['ok'=>false, 'valid'=>false, 'message'=>'Account not found: ' . trim((string)$raw) . '. Use a code or title from the Chart of Accounts.'];
        }
        $lines[] = [$id, $d, $c];
    }
    if (count($lines) < 2) {
        return ['ok'=>false, 'valid'=>false, 'message'=>'A journal entry needs at least two lines.'];
    }
    $valid = journal_is_balanced(array_column($lines, 1), array_column($lines, 2));
    $status = $valid ? 'Valid' : 'Invalid';

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO journal_entries(student_id,entry_date,description,ai_validation_status) VALUES(?,?,?,?)");
        $stmt->execute([$studentId, $date, $desc, $status]);
        $entryId = $pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO journal_lines(entry_id,account_id,debit_amount,credit_amount) VALUES(?,?,?,?)");
        foreach ($lines as $l) $ins->execute([$entryId, $l[0], $l[1], $l[2]]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [
        'ok'=>true, 'valid'=>$valid,
        'message'=>$valid ? 'Journal entry validated: debit equals credit.' : 'Journal entry rejected: debit and credit totals must be equal.'
    ];
}

function recent_journal_entries(PDO $pdo, $limit = 20, $studentId = null) {
    $sql = "SELECT j.*, u.name,
        (SELECT COALESCE(SUM(l.debit_amount),0) FROM journal_lines l WHERE l.entry_id=j.entry_id) AS total_debit
        FROM journal_entries j JOIN users u ON u.user_id=j.student_id "
        . ($studentId ? "WHERE j.student_id=? " : "") . "ORDER BY j.entry_id DESC LIMIT " . (int)$limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($studentId ? [(int)$studentId] : []);
    return $stmt->fetchAll();
}

// Lines for a set of entries, grouped by entry_id.
function journal_lines_for(PDO $pdo, array $entryIds) {
    if (!$entryIds) return [];
    $in = implode(',', array_map('intval', $entryIds));
    $rows = $pdo->query("SELECT l.*, a.account_code, a.account_name FROM journal_lines l
        JOIN chart_of_accounts a ON a.account_id=l.account_id WHERE l.entry_id IN ($in) ORDER BY l.line_id")->fetchAll();
    $out = [];
    foreach ($rows as $r) $out[$r['entry_id']][] = $r;
    return $out;
}

// Balance of every account from VALID (balanced) entries, on its normal side.
function account_balances(PDO $pdo) {
    $sql = "SELECT a.account_id, a.account_code, a.account_name, a.account_type,
                   COALESCE(SUM(l.debit_amount),0) dr, COALESCE(SUM(l.credit_amount),0) cr
            FROM chart_of_accounts a
            LEFT JOIN (SELECT jl.account_id, jl.debit_amount, jl.credit_amount
                       FROM journal_lines jl JOIN journal_entries je ON je.entry_id=jl.entry_id
                       WHERE je.ai_validation_status='Valid') l ON l.account_id=a.account_id
            GROUP BY a.account_id, a.account_code, a.account_name, a.account_type
            ORDER BY a.account_code";
    $rows = $pdo->query($sql)->fetchAll();
    foreach ($rows as &$r) {
        $debitNormal = in_array($r['account_type'], ['Asset','Expense'], true);
        $r['balance'] = $debitNormal ? (float)$r['dr'] - (float)$r['cr'] : (float)$r['cr'] - (float)$r['dr'];
        $r['debit_normal'] = $debitNormal;
    }
    return $rows;
}
function sum_by_type(array $balances, $type) {
    $t = 0;
    foreach ($balances as $b) if ($b['account_type'] === $type) $t += $b['balance'];
    return $t;
}

function peso($n) { return ($n < 0 ? '-' : '') . '₱' . number_format(abs((float)$n), 2); }
function flash($type, $text) { $_SESSION['flash'] = [$type, $text]; }
function role_label($r) { return ['Admin'=>'Administrator','Faculty'=>'Faculty','Student'=>'Student'][$r] ?? $r; }
