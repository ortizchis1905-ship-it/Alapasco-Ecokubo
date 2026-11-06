<?php
session_start();

/*
|--------------------------------------------------------------------------
| Confirm Logout
|--------------------------------------------------------------------------
| If the user has not confirmed yet, show a confirmation page.
*/

if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="logo1.png">

    <title>Confirm Logout - Alapasco EcoKubo</title>
    
    <link rel="icon" type="image/png" href="logo1.png">
    
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f6f5;
            min-height: 100vh;

            display: flex;
            justify-content: center;
            align-items: center;
        }

        .logout-box {
            width: 90%;
            max-width: 420px;
            background: white;
            padding: 35px 30px;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.10);
            text-align: center;
        }

        .logout-icon {
            width: 70px;
            height: 70px;
            margin: 0 auto 20px;

            background: #e8f1ed;
            color: #1f5d42;

            border-radius: 50%;

            display: flex;
            justify-content: center;
            align-items: center;

            font-size: 32px;
        }

        .logout-box h2 {
            margin: 0 0 10px;
            color: #1f5d42;
            font-size: 22px;
        }

        .logout-box p {
            margin: 0 0 25px;
            color: #666;
            font-size: 14px;
            line-height: 1.5;
        }

        .buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .btn {
            flex: 1;
            padding: 12px 15px;
            border-radius: 7px;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            border: none;
        }

        .cancel-btn {
            background: #e9eceb;
            color: #333;
        }

        .cancel-btn:hover {
            background: #dfe3e1;
        }

        .logout-btn {
            background: #1f5d42;
            color: white;
        }

        .logout-btn:hover {
            background: #174a34;
        }

        @media (max-width: 400px) {
            .buttons {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

    <div class="logout-box">

        <div class="logout-icon">
            🚪
        </div>

        <h2>
            Confirm Logout
        </h2>

        <p>
            Are you sure you want to log out of
            <strong>Alapasco EcoKubo</strong>?
        </p>

        <div class="buttons">

            <a
                href="javascript:history.back()"
                class="btn cancel-btn"
            >
                Cancel
            </a>

            <a
                href="logout.php?confirm=yes"
                class="btn logout-btn"
            >
                Yes, Logout
            </a>

        </div>

    </div>

</body>
</html>

<?php
    exit;
}


/*
|--------------------------------------------------------------------------
| Logout
|--------------------------------------------------------------------------
| Destroy the current session and return to index.php
|--------------------------------------------------------------------------
*/

$_SESSION = [];

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

/*
|--------------------------------------------------------------------------
| Return to Login / Index
|--------------------------------------------------------------------------
*/

header("Location: index.php");
exit;

?>