<?php
session_start();
require_once __DIR__ . '/database.php';

function adminResetEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function adminResetRedirect() {
    header('Location: admin_forgot_password.php');
    exit;
}
if (empty($_SESSION['admin_reset_csrf'])) {
    $_SESSION['admin_reset_csrf'] = bin2hex(random_bytes(32));
}
$message = '';
$error = '';
if (isset($_SESSION['admin_reset_notice'])) {
    $message = $_SESSION['admin_reset_notice'];
    unset($_SESSION['admin_reset_notice']);
}
if (isset($_SESSION['admin_reset_challenge']) && time() > $_SESSION['admin_reset_challenge']['expires']) {
    unset($_SESSION['admin_reset_challenge']);
    $error = 'Your reset session expired. Request a new code.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (!hash_equals($_SESSION['admin_reset_csrf'], $token)) {
        $error = 'Security verification failed. Refresh the page and try again.';
    } else {
        try {
            if ($action === 'request') {
                $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Enter a valid admin email address.';
                } elseif (time() < (int) ($_SESSION['admin_reset_next_request'] ?? 0)) {
                    $error = 'Please wait 60 seconds before requesting another code.';
                } else {
                    unset($_SESSION['admin_reset_challenge']);
                    $_SESSION['admin_reset_next_request'] = time() + 60;
                    $stmt = $conn->prepare('SELECT id, full_name, email, password FROM admin WHERE TRIM(email) = ? LIMIT 1');
                    $stmt->bind_param('s', $email);
                    $stmt->execute();
                    $account = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $code = (string) random_int(10000000, 99999999);
                    if ($account) {
                        // Reuse the existing SMTP credentials without copying secrets into this page.
                        ob_start();
                        try {
                            require_once __DIR__ . '/email_config.php';
                        } finally {
                            ob_end_clean();
                        }
                        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                        $mail->isSMTP();
                        $mail->Host = 'smtp.gmail.com';
                        $mail->SMTPAuth = true;
                        $mail->Username = trim((string) ($gmail_username ?? ''));
                        $mail->Password = trim((string) ($gmail_password ?? ''));
                        if ($mail->Username === '' || $mail->Password === '') {
                            throw new RuntimeException('SMTP configuration is missing.');
                        }
                        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port = 587;
                        $mail->Timeout = 20;
                        $mail->CharSet = 'UTF-8';
                        $mail->setFrom($mail->Username, 'Alapasco EcoKubo');
                        $mail->addAddress(trim($account['email']), $account['full_name']);
                        $mail->Subject = 'Alapasco EcoKubo - Admin Password Reset';
                        $mail->Body = "Your admin password reset code is: " . $code . "\n\nIt expires in 10 minutes. Enter it in the same browser where you requested it. If you did not request this, ignore this email.";
                        $mail->send();
                    }
                    $_SESSION['admin_reset_challenge'] = [
                        'id' => $account ? (int) $account['id'] : 0,
                        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
                        'password_snapshot' => $account ? hash('sha256', $account['password']) : '',
                        'expires' => time() + 600,
                        'attempts' => 0,
                        'verified' => false
                    ];
                    $_SESSION['admin_reset_notice'] = 'If that email belongs to an admin account, a code has been sent. Check your inbox and Spam folder.';
                    adminResetRedirect();
                }
            } elseif ($action === 'verify') {
                $challenge = $_SESSION['admin_reset_challenge'] ?? null;
                $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
                if (!$challenge || time() > $challenge['expires']) {
                    unset($_SESSION['admin_reset_challenge']);
                    $error = 'The code expired. Request a new code.';
                } elseif ($challenge['verified']) {
                    $error = 'Code already verified. Enter your new password below.';
                } else {
                    $_SESSION['admin_reset_challenge']['attempts']++;
                    if ($challenge['id'] > 0 && preg_match('/^\d{8}$/D', $code) && password_verify($code, $challenge['code_hash'])) {
                        session_regenerate_id(true);
                        $_SESSION['admin_reset_challenge']['verified'] = true;
                        $_SESSION['admin_reset_challenge']['expires'] = time() + 300;
                        unset($_SESSION['admin_reset_challenge']['code_hash']);
                        $_SESSION['admin_reset_csrf'] = bin2hex(random_bytes(32));
                        $_SESSION['admin_reset_notice'] = 'Code verified. Choose your new admin password.';
                        adminResetRedirect();
                    } elseif ($_SESSION['admin_reset_challenge']['attempts'] >= 5) {
                        unset($_SESSION['admin_reset_challenge']);
                        $error = 'Too many incorrect attempts. Request a new code.';
                    } else {
                        $error = 'Incorrect code. Try again.';
                    }
                }
            } elseif ($action === 'reset') {
                $challenge = $_SESSION['admin_reset_challenge'] ?? null;
                $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
                if (!$challenge || !$challenge['verified'] || time() > $challenge['expires']) {
                    unset($_SESSION['admin_reset_challenge']);
                    $error = 'Verify a new code before resetting your password.';
                } elseif (strlen($password) < 8 || strlen($password) > 72) {
                    $error = 'Use a password between 8 and 72 bytes.';
                } elseif ($password !== $confirm) {
                    $error = 'Passwords do not match.';
                } else {
                    $id = (int) $challenge['id'];
                    $stmt = $conn->prepare('SELECT password FROM admin WHERE id = ? LIMIT 1');
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $account = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if (!$account || !hash_equals($challenge['password_snapshot'], hash('sha256', $account['password']))) {
                        unset($_SESSION['admin_reset_challenge']);
                        $error = 'The account changed. Request a new reset code.';
                    } else {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        $old_password = $account['password'];
                        // Only one outstanding reset can replace the current password.
                        $stmt = $conn->prepare('UPDATE admin SET password = ? WHERE id = ? AND BINARY password = BINARY ?');
                        $stmt->bind_param('sis', $hash, $id, $old_password);
                        $stmt->execute();
                        $updated = $stmt->affected_rows === 1;
                        $stmt->close();
                        unset($_SESSION['admin_reset_challenge']);
                        if (!$updated) {
                            $error = 'The account changed. Request a new reset code.';
                        } else {
                            unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_full_name'], $_SESSION['admin_email']);
                            session_regenerate_id(true);
                            $_SESSION['admin_reset_csrf'] = bin2hex(random_bytes(32));
                            header('Location: admin_login.php?reset=success');
                            exit;
                        }
                    }
                }
            } elseif ($action === 'restart') {
                unset($_SESSION['admin_reset_challenge']);
                adminResetRedirect();
            }
        } catch (Throwable $e) {
            error_log('Admin password reset failed: ' . $e->getMessage());
            $error = 'Unable to complete this request. For email delivery problems, check the SMTP settings in email_config.php and the PHP error log.';
        }
    }
}
$challenge = $_SESSION['admin_reset_challenge'] ?? null;
$stage = !$challenge ? 'request' : ($challenge['verified'] ? 'reset' : 'verify');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Password Recovery - Alapasco EcoKubo</title>
<link rel="icon" type="image/png" href="logo1.png">
<link rel="stylesheet" href="style.css">
<style>
.form-card { width: 100%; max-width: 460px; }
.reset-notice { padding: 12px; margin: 14px 0; border-radius: 6px; background: #e5f2eb; color: #1f5d42; font-size: 14px; }
.reset-error { background: #fbeaea; color: #842029; }
.reset-secondary { background: transparent; color: #1f5d42; border: 1px solid #1f5d42; margin-top: 12px; }
</style>
</head>
<body>
<div class="page"><div class="form-card">
<a href="admin_login.php" class="back">← Back to Admin Login</a>
<div class="form-icon">🔐</div>
<h1>Admin Password Recovery</h1>
<p class="subtitle"><?php echo $stage === 'request' ? 'Enter the email registered to your admin account.' : ($stage === 'verify' ? 'Enter the 8-digit code sent to your email.' : 'Choose a new password for your admin account.'); ?></p>
<?php if ($message !== ''): ?><div class="reset-notice" role="status"><?php echo adminResetEscape($message); ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="reset-notice reset-error" role="alert"><?php echo adminResetEscape($error); ?></div><?php endif; ?>
<form method="POST" action="admin_forgot_password.php">
<input type="hidden" name="csrf_token" value="<?php echo adminResetEscape($_SESSION['admin_reset_csrf']); ?>">
<input type="hidden" name="action" value="<?php echo adminResetEscape($stage); ?>">
<?php if ($stage === 'request'): ?>
<label for="email">Admin Email Address</label>
<input id="email" name="email" type="email" autocomplete="email" required>
<button type="submit">Send Reset Code</button>
<?php elseif ($stage === 'verify'): ?>
<label for="code">Verification Code</label>
<input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{8}" maxlength="8" required>
<button type="submit">Verify Code</button>
<?php else: ?>
<label for="password">New Password</label>
<input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>
<label for="confirm_password">Confirm New Password</label>
<input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" required>
<button type="submit">Reset Admin Password</button>
<?php endif; ?>
</form>
<?php if ($stage !== 'request'): ?>
<form method="POST" action="admin_forgot_password.php">
<input type="hidden" name="csrf_token" value="<?php echo adminResetEscape($_SESSION['admin_reset_csrf']); ?>">
<input type="hidden" name="action" value="restart">
<button class="reset-secondary" type="submit">Request Another Code</button>
</form>
<?php endif; ?>
</div></div>
</body>
</html>
