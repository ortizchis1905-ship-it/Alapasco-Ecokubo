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

/* UPDATE PROFILE */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

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

/* GET ADMIN INFORMATION */
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
<title>Admin Profile</title>

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

.profile-card {
    background: white;
    border-radius: 10px;
    padding: 25px;
    max-width: 850px;
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

/* RESPONSIVE */

@media (max-width:700px) {

    .main-content {
        margin-left: 200px;
        width: calc(100% - 200px);
    }

    .form-grid {
        grid-template-columns: 1fr;
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

    .profile-card {
        padding: 18px;
    }
}

</style>

</head>

<body>

<?php require __DIR__ . '/admin_sidebar.php'; ?>


<div class="main-content">

    <?php
    $adminHeaderTitle = 'Admin Profile';
    $adminHeaderName = $admin["full_name"] ?? 'Barangay Admin';
    $adminHeaderRole = 'Barangay Tourism Admin';
    $adminHeaderBackUrl = null;
    require __DIR__ . '/admin_header.php';
    ?>


    <div class="page-content">

        <div class="profile-card">

            <div class="profile-header">

                <div class="profile-avatar">
                    👤
                </div>

                <div>

                    <h2>
                        <?php echo htmlspecialchars($admin["full_name"]); ?>
                    </h2>

                    <p>
                        Barangay Tourism Desk Administrator
                    </p>

                </div>

            </div>


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


            <form method="POST">

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

    </div>

</div>

</body>
</html>