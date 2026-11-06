<?php
session_start();
require_once "database.php";

if (!isset($_SESSION["admin_id"])) {
    header("Location: admin_login.php");
    exit;
}

$admin_id = $_SESSION["admin_id"];
$message = "";
$error = "";

if (empty($_SESSION["admin_settings_csrf"])) {
    $_SESSION["admin_settings_csrf"] = bin2hex(random_bytes(32));
}
$csrf_valid = $_SERVER["REQUEST_METHOD"] !== "POST" || hash_equals(
    $_SESSION["admin_settings_csrf"], (string) ($_POST["csrf_token"] ?? "")
);
if (!$csrf_valid) {
    $error = "Security verification failed. Refresh the page and try again.";
}


try {
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["settings_action"] ?? "password") === "password" && $csrf_valid) {

    $current_password = $_POST["current_password"] ?? "";
    $new_password = $_POST["new_password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if ($current_password === "" || $new_password === "" || $confirm_password === "") {
        $error = "Please complete all password fields.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New passwords do not match.";
    } elseif (strlen($new_password) < 6) {
        $error = "New password must be at least 6 characters.";
    } else {

        $stmt = $conn->prepare("
            SELECT password
            FROM admin
            WHERE id = ?
            LIMIT 1
        ");

        if ($stmt) {

            $stmt->bind_param("i", $admin_id);
            $stmt->execute();

            $result = $stmt->get_result();
            $admin = $result->fetch_assoc();

            $stmt->close();

            if (!$admin) {
                $error = "Admin account not found.";
            } else {

                $password_correct = false;

                /*
                 * Supports both:
                 * 1. Hashed passwords
                 * 2. Existing plain-text password
                 */
                if (password_verify($current_password, $admin["password"])) {
                    $password_correct = true;
                } elseif ($current_password === $admin["password"]) {
                    $password_correct = true;
                }

                if (!$password_correct) {

                    $error = "Current password is incorrect.";

                } else {

                    $hashed_password = password_hash(
                        $new_password,
                        PASSWORD_DEFAULT
                    );

                    $update = $conn->prepare("
                        UPDATE admin
                        SET password = ?
                        WHERE id = ?
                    ");

                    if ($update) {

                        $update->bind_param(
                            "si",
                            $hashed_password,
                            $admin_id
                        );

                        if ($update->execute()) {
                            $message = "Password changed successfully.";
                        } else {
                            $error = "Unable to change password.";
                        }

                        $update->close();

                    } else {
                        $error = "Database error.";
                    }
                }
            }

        } else {
            $error = "Database error.";
        }
    }
}

/* Profile settings */

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["settings_action"] ?? "") === "profile" && $csrf_valid) {

    $full_name = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");

    if ($full_name === "" || $email === "") {
        $error = "Full name and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {

        $stmt = $conn->prepare("
            UPDATE admin
            SET full_name = ?, email = ?
            WHERE id = ?
        ");

        if ($stmt) {
            $stmt->bind_param("ssi", $full_name, $email, $admin_id);

            if ($stmt->execute()) {
                $_SESSION["admin_full_name"] = $full_name;
                $_SESSION["admin_email"] = $email;

                $message = "Profile updated successfully.";
            } else {
                $error = "Unable to update profile.";
            }

            $stmt->close();

        } else {
            $error = "Database error.";
        }
    }
}



} catch (mysqli_sql_exception $e) {
    error_log("Admin settings update failed: " . $e->getMessage());
    $error = (int) $e->getCode() === 1062
        ? "That email address is already in use."
        : "Unable to save account settings. Please try again.";
}

/* Load current account */

$stmt = $conn->prepare("
    SELECT
        id,
        full_name,
        username,
        email,
        created_at
    FROM admin
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param("i", $admin_id);
$stmt->execute();

$result = $stmt->get_result();
$admin = $result->fetch_assoc();

$stmt->close();

if (!$admin) {
    session_destroy();
    header("Location: admin_login.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="logo1.png">

<title>Admin Settings</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f4f6f5;
    color: #333;
}

a {
    text-decoration: none;
}

/* MAIN */

.main-content {
    margin-left: 250px;
    width: calc(100% - 250px);
    min-height: 100vh;
}

/* TOPBAR */

.topbar {
    background: white;
    min-height: 65px;
    padding: 15px 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #ddd;
    position: sticky;
    top: 0;
    z-index: 500;
}

.topbar h3 {
    margin: 0;
    color: #1f5d42;
    font-size: 20px;
}

.user-info {
    text-align: right;
}

.user-info strong {
    display: block;
    color: #333;
    font-size: 14px;
}

.user-info span {
    font-size: 11px;
    color: #777;
}

/* CONTENT */

.page-content {
    padding: 25px;
}

.settings-card {
    background: white;
    max-width: none; width: 100%;
    border-radius: 10px;
    padding: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.settings-header {
    margin-bottom: 25px;
    padding-bottom: 18px;
    border-bottom: 1px solid #eee;
}

.settings-header h2 {
    margin: 0 0 6px;
    color: #1f5d42;
}

.settings-header p {
    margin: 0;
    color: #777;
    font-size: 13px;
}

.message {
    background: #d1e7dd;
    color: #0f5132;
    padding: 12px 15px;
    border-radius: 6px;
    margin-bottom: 20px;
    font-size: 13px;
}

.error {
    background: #f8d7da;
    color: #842029;
    padding: 12px 15px;
    border-radius: 6px;
    margin-bottom: 20px;
    font-size: 13px;
}

.form-group {
    margin-bottom: 18px;
}

label {
    display: block;
    font-size: 13px;
    font-weight: bold;
    margin-bottom: 7px;
    color: #444;
}

input {
    width: 100%;
    padding: 11px 12px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 14px;
    outline: none;
}

input:focus {
    border-color: #1f5d42;
}

.form-note {
    margin-top: 5px;
    color: #888;
    font-size: 11px;
}

.save-button {
    margin-top: 8px;
    background: #1f5d42;
    color: white;
    border: none;
    padding: 12px 22px;
    border-radius: 6px;
    font-size: 14px;
    cursor: pointer;
}

.save-button:hover {
    background: #174832;
}

/* RESPONSIVE */

@media (max-width:700px) {

    .main-content {
        margin-left: 200px;
        width: calc(100% - 200px);
    }

    .page-content {
        padding: 15px;
    }

    .topbar {
        padding: 12px 15px;
    }

    .topbar h3 {
        font-size: 16px;
    }

    .user-info {
        font-size: 12px;
    }

    .settings-card {
        padding: 18px;
    }
}

.profile-card {
    background: white;
    border-radius: 10px;
    padding: 25px;
    max-width: none; width: 100%;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.profile-header {
    display: flex;
    align-items: center;
    gap: 18px;
    margin-bottom: 25px;
    padding-bottom: 20px;
    border-bottom: 1px solid #eee;
}

.profile-avatar {
    width: 75px;
    height: 75px;
    border-radius: 50%;
    background: #1f5d42;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
}

.profile-header h2 {
    margin: 0 0 5px;
    color: #1f5d42;
}

.profile-header p {
    margin: 0;
    color: #777;
    font-size: 13px;
}

.message {
    background: #d1e7dd;
    color: #0f5132;
    padding: 12px 15px;
    border-radius: 6px;
    margin-bottom: 20px;
    font-size: 13px;
}

.error {
    background: #f8d7da;
    color: #842029;
    padding: 12px 15px;
    border-radius: 6px;
    margin-bottom: 20px;
    font-size: 13px;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}

.form-group {
    margin-bottom: 5px;
}

.form-group.full {
    grid-column: 1 / -1;
}

label {
    display: block;
    font-size: 13px;
    font-weight: bold;
    margin-bottom: 7px;
    color: #444;
}

input {
    width: 100%;
    padding: 11px 12px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 14px;
    outline: none;
}

input:focus {
    border-color: #1f5d42;
}

input[readonly] {
    background: #f1f3f2;
    color: #777;
}

.role-box {
    background: #f1f6f3;
    border: 1px solid #dce8e1;
    padding: 11px 12px;
    border-radius: 6px;
    font-size: 14px;
    color: #1f5d42;
    font-weight: bold;
}

.form-note {
    font-size: 11px;
    color: #888;
    margin-top: 5px;
}

.save-button {
    margin-top: 25px;
    background: #1f5d42;
    color: white;
    border: none;
    padding: 12px 22px;
    border-radius: 6px;
    font-size: 14px;
    cursor: pointer;
}

.save-button:hover {
    background: #174832;
}


.profile-card { max-width: none; width: 100%; margin-bottom: 24px; scroll-margin-top: 90px; }
@media (max-width:700px) { .form-grid { grid-template-columns: 1fr; } .profile-card { padding: 18px; } }
</style>

</head>

<body>

<!-- SIDEBAR -->

<?php require __DIR__ . '/admin_sidebar.php'; ?>


<!-- MAIN CONTENT -->

<div class="main-content">

    <?php
    $adminHeaderTitle = 'Admin Settings';
    $adminHeaderName = $_SESSION["admin_full_name"] ?? 'Barangay Admin';
    $adminHeaderRole = 'Barangay Tourism Admin';
    $adminHeaderBackUrl = null;
    require __DIR__ . '/admin_header.php';
    ?>


    <div class="page-content">

            <?php if ($message !== ""): ?>

                <div class="message">
                    <?php echo htmlspecialchars($message); ?>
                </div>

            <?php endif; ?>


            <?php if ($error !== ""): ?>

                <div class="error">
                    <?php echo htmlspecialchars($error); ?>
                </div>

            <?php endif; ?>


        <div class="profile-card" id="profile">

            <div class="profile-header">

                <div class="profile-avatar">
                    👤
                </div>

                <div>

                    <h2>Profile: 
                        <?php echo htmlspecialchars($admin["full_name"]); ?>
                    </h2>

                    <p>
                        Barangay Tourism Desk Administrator
                    </p>

                </div>

            </div>


            <form method="POST" action="admin_settings.php#profile">
                <input type="hidden" name="settings_action" value="profile">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["admin_settings_csrf"], ENT_QUOTES, "UTF-8"); ?>">

                <div class="form-grid">

                    <div class="form-group">

                        <label>Full Name</label>

                        <input
                            type="text"
                            name="full_name"
                            value="<?php echo htmlspecialchars($admin["full_name"]); ?>"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label>Username</label>

                        <input
                            type="text"
                            value="<?php echo htmlspecialchars($admin["username"]); ?>"
                            readonly
                        >

                        <div class="form-note">
                            Username cannot be changed here.
                        </div>

                    </div>


                    <div class="form-group">

                        <label>Email Address</label>

                        <input
                            type="email"
                            name="email"
                            value="<?php echo htmlspecialchars($admin["email"]); ?>"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label>Account Role</label>

                        <div class="role-box">
                            Barangay Admin
                        </div>

                    </div>


                    <div class="form-group full">

                        <label>Account Created</label>

                        <input
                            type="text"
                            value="<?php echo date("F d, Y", strtotime($admin["created_at"])); ?>"
                            readonly
                        >

                    </div>

                </div>


                <button type="submit" class="save-button">
                    Save Changes
                </button>

            </form>

        </div>


        <div class="settings-card">

            <div class="settings-header">

                <h2>Security Settings</h2>

                <p>
                    Change your Barangay Admin account password.
                </p>

            </div>


            <form method="POST" action="admin_settings.php">
                <input type="hidden" name="settings_action" value="password">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["admin_settings_csrf"], ENT_QUOTES, "UTF-8"); ?>">

                <div class="form-group">

                    <label>Current Password</label>

                    <input
                        type="password"
                        name="current_password"
                        placeholder="Enter current password"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>New Password</label>

                    <input
                        type="password"
                        name="new_password"
                        placeholder="Enter new password"
                        required
                    >

                    <div class="form-note">
                        Password must be at least 6 characters.
                    </div>

                </div>


                <div class="form-group">

                    <label>Confirm New Password</label>

                    <input
                        type="password"
                        name="confirm_password"
                        placeholder="Re-enter new password"
                        required
                    >

                </div>


                <button
                    type="submit"
                    class="save-button"
                >
                    Change Password
                </button>

            </form>

        </div>

    </div>

</div>

</body>
</html>