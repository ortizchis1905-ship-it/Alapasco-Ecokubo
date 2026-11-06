<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function adminLoginFailure($reason) {
    header('Location: admin_login.php?error=' . rawurlencode($reason));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_login.php');
    exit;
}

$identifier = is_string($_POST['username'] ?? null)
    ? trim($_POST['username'])
    : '';

$submittedPassword = is_string($_POST['password'] ?? null)
    ? $_POST['password']
    : '';

if ($identifier === '' || $submittedPassword === '') {
    adminLoginFailure('empty');
}

try {
    require_once __DIR__ . '/database.php';

    $databaseResult = $conn->query('SELECT DATABASE() AS selected_database');
    $databaseRow = $databaseResult->fetch_assoc();
    $selectedDatabase = (string) ($databaseRow['selected_database'] ?? '(none)');
    $databaseResult->free();

    $stmt = $conn->prepare(
        'SELECT id, full_name, username, email, password
         FROM admin
         WHERE username = ? OR TRIM(email) = ?
         LIMIT 2'
    );
    $stmt->bind_param('ss', $identifier, $identifier);
    $stmt->execute();
    $accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (count($accounts) !== 1) {
        error_log('[ALAPASCO ADMIN LOGIN] ' . json_encode([
            'event' => 'account_lookup_failed',
            'database' => $selectedDatabase,
            'matching_accounts' => count($accounts)
        ]));
        adminLoginFailure('invalid');
    }

    $admin = $accounts[0];
    $storedPassword = (string) $admin['password'];
    $passwordInfo = password_get_info($storedPassword);
    $isHash = ($passwordInfo['algoName'] ?? 'unknown') !== 'unknown';

    $passwordCorrect = $isHash
        ? password_verify($submittedPassword, $storedPassword)
        : hash_equals($storedPassword, $submittedPassword);

    if (!$passwordCorrect) {
        error_log('[ALAPASCO ADMIN LOGIN] ' . json_encode([
            'event' => 'password_mismatch',
            'database' => $selectedDatabase,
            'admin_id' => (int) $admin['id'],
            'password_format' => $isHash ? 'hashed' : 'legacy/plain text'
        ]));
        adminLoginFailure('invalid');
    }

    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_full_name'] = $admin['full_name'];
    $_SESSION['admin_username'] = $admin['username'];
    $_SESSION['admin_email'] = trim((string) $admin['email']);

    header('Location: admin_dashboard.php');
    exit;

} catch (Throwable $e) {
    error_log('[ALAPASCO ADMIN LOGIN] database_or_runtime_error: ' . $e->getMessage());
    adminLoginFailure('database');
}