<?php
session_start();

/* If logout is confirmed */
if (isset($_GET["confirm"]) && $_GET["confirm"] === "yes") {

    $_SESSION = array();

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();

    header("Location: index.php");
    exit;
}

/* Get previous page */
$back_page = $_SERVER["HTTP_REFERER"] ?? "admin_dashboard.php";
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="logo1.png">

<title>Admin Logout</title>

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

.logout-page {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.logout-card {
    width: 100%;
    max-width: 420px;
    background: white;
    padding: 35px 30px;
    border-radius: 12px;
    text-align: center;
    box-shadow: 0 4px 15px rgba(0,0,0,0.08);
}

.logout-icon {
    width: 70px;
    height: 70px;
    margin: 0 auto 20px;
    border-radius: 50%;
    background: #e8f1ed;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
}

.logout-card h2 {
    margin: 0 0 10px;
    color: #1f5d42;
    font-size: 22px;
}

.logout-card p {
    margin: 0 0 25px;
    color: #777;
    font-size: 14px;
    line-height: 1.5;
}

.button-group {
    display: flex;
    gap: 10px;
    justify-content: center;
}

.button {
    display: inline-block;
    padding: 11px 20px;
    border-radius: 6px;
    font-size: 14px;
    cursor: pointer;
    border: none;
    text-decoration: none;
}

.cancel-button {
    background: #e9ecef;
    color: #333;
}

.cancel-button:hover {
    background: #dfe3e5;
}

.logout-button {
    background: #1f5d42;
    color: white;
}

.logout-button:hover {
    background: #174832;
}

@media (max-width: 450px) {

    .logout-card {
        padding: 30px 20px;
    }

    .button-group {
        flex-direction: column;
    }

    .button {
        width: 100%;
    }
}

</style>

</head>

<body>

<div class="logout-page">

    <div class="logout-card">

        <div class="logout-icon">
            🚪
        </div>

        <h2>Logout</h2>

        <p>
            Are you sure you want to logout from the
            Barangay Admin account?
        </p>

        <div class="button-group">

            <a
                href="<?php echo htmlspecialchars($back_page); ?>"
                class="button cancel-button"
            >
                Cancel
            </a>

            <a
                href="admin_logout.php?confirm=yes"
                class="button logout-button"
            >
                Yes, Logout
            </a>

        </div>

    </div>

</div>

</body>

</html>