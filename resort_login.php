<?php

session_start();

require_once "database.php";

$message = "";
$message_type = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    /*
    |--------------------------------------------------------------------------
    | VALIDATION
    |--------------------------------------------------------------------------
    */

    if ($email === "" || $password === "") {

        $message = "Please enter your email and password.";
        $message_type = "error";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email address.";
        $message_type = "error";

    } else {

        /*
        |--------------------------------------------------------------------------
        | FIND USER
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare(
            "SELECT
                id,
                resort_name,
                first_name,
                last_name,
                full_name,
                email,
                contact_number,
                password
             FROM users
             WHERE email = ?
             LIMIT 1"
        );

        if (!$stmt) {

            $message = "Database error. Please try again.";
            $message_type = "error";

        } else {

            $stmt->bind_param("s", $email);
            $stmt->execute();

            $result = $stmt->get_result();

            if ($result->num_rows === 1) {

                $user = $result->fetch_assoc();

                /*
                |--------------------------------------------------------------------------
                | CHECK PASSWORD
                |--------------------------------------------------------------------------
                */

                if (password_verify($password, $user["password"])) {

                    /*
                    |--------------------------------------------------------------------------
                    | FIND RESORT
                    |--------------------------------------------------------------------------
                    */

                    $resort_stmt = $conn->prepare(
                        "SELECT
                            id,
                            resort_name,
                            contact_number,
                            status
                         FROM resorts
                         WHERE user_id = ?
                         LIMIT 1"
                    );

                    if (!$resort_stmt) {

                        $message =
                            "Unable to verify resort account.";

                        $message_type = "error";

                    } else {

                        $resort_stmt->bind_param(
                            "i",
                            $user["id"]
                        );

                        $resort_stmt->execute();

                        $resort_result =
                            $resort_stmt->get_result();

                        if ($resort_result->num_rows === 1) {

                            $resort =
                                $resort_result->fetch_assoc();

                            /*
                            |--------------------------------------------------------------------------
                            | SAVE SESSION
                            |--------------------------------------------------------------------------
                            */

                            $_SESSION["user_id"] =
                                $user["id"];

                            $_SESSION["resort_id"] =
                                $resort["id"];

                            $_SESSION["resort_name"] =
                                $resort["resort_name"];

                            $_SESSION["first_name"] =
                                $user["first_name"];

                            $_SESSION["last_name"] =
                                $user["last_name"];

                            $_SESSION["full_name"] =
                                trim(
                                    $user["first_name"] .
                                    " " .
                                    $user["last_name"]
                                );

                            $_SESSION["email"] =
                                $user["email"];

                            $_SESSION["contact_number"] =
                                $user["contact_number"];

                            $_SESSION["resort_status"] =
                                $resort["status"];


                            /*
                            |--------------------------------------------------------------------------
                            | RESORT STATUS
                            |--------------------------------------------------------------------------
                            */

                            if ($resort["status"] === "Pending") {

                                header(
                                    "Location: ./pending_approval.php"
                                );

                                exit;

                            } elseif ($resort["status"] === "Active") {

                                header(
                                    "Location: ./resort_dashboard.php"
                                );

                                exit;

                            } elseif ($resort["status"] === "Inactive") {

                                session_unset();
                                session_destroy();

                                $message =
                                    "Your resort account is inactive. Please contact the Barangay Tourism Desk.";

                                $message_type = "error";

                            } else {

                                session_unset();
                                session_destroy();

                                $message =
                                    "Your resort account has an invalid status. Please contact the Barangay Tourism Desk.";

                                $message_type = "error";
                            }

                        } else {

                            $message =
                                "Resort account not found. Please contact the Barangay Tourism Desk.";

                            $message_type = "error";
                        }

                        $resort_stmt->close();
                    }

                } else {

                    $message = "Incorrect password.";
                    $message_type = "error";
                }

            } else {

                $message = "Email address not found.";
                $message_type = "error";
            }

            $stmt->close();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <link rel="icon" type="image/png" href="logo1.png">

    <title>Resort Login</title>

    <link
        rel="stylesheet"
        href="style.css"
    >

    <style>

        .password-wrapper {
            position: relative;
            width: 100%;
        }

        .password-wrapper input {
            width: 100%;
            padding-right: 45px;
            box-sizing: border-box;
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: none;
            padding: 0;
            margin: 0;
            width: auto;
            color: #666;
            cursor: pointer;
            font-size: 18px;
            line-height: 1;
        }

        .toggle-password:hover {
            color: #1f5d42;
            background: none;
        }

    </style>

</head>

<body>

<div class="page">

    <div class="form-card">

        <!-- BACK TO ACCOUNT TYPE -->

        <a
            href="./index.php"
            class="back"
        >
            ← Back
        </a>


        <div class="form-icon">
            🏡
        </div>


        <h1>
            Resort Login
        </h1>


        <p class="subtitle">
            Rest House / Resort Account
        </p>


        <?php if (!empty($message)): ?>

            <div
                class="message <?php echo htmlspecialchars($message_type); ?>"
            >
                <?php
                echo htmlspecialchars($message);
                ?>
            </div>

        <?php endif; ?>


        <form method="POST">

            <label>
                Email
            </label>

            <input
                type="email"
                name="email"
                placeholder="Enter email"
                value="<?php echo htmlspecialchars($_POST["email"] ?? ""); ?>"
                required
            >


            <label>
                Password
            </label>

            <div class="password-wrapper">

                <input
                    type="password"
                    name="password"
                    id="password"
                    placeholder="Enter password"
                    required
                >

                <button
                    type="button"
                    class="toggle-password"
                    onclick="togglePassword()"
                    aria-label="Show or hide password"
                >
                    👁️
                </button>

            </div>


            <button type="submit">
                Login
            </button>

        </form>


        <p class="forgot">

            <a href="./forgot_password.php">
                Forgot Password?
            </a>

        </p>


        <p class="register-text">

            Don't have an account?

            <a href="./resort_register.php">
                Register here
            </a>

        </p>

    </div>

</div>


<script>

function togglePassword() {

    const password =
        document.getElementById("password");

    const toggle =
        document.querySelector(".toggle-password");

    if (password.type === "password") {

        password.type = "text";

        toggle.textContent = "🙈";

    } else {

        password.type = "password";

        toggle.textContent = "👁️";

    }
}

</script>

</body>

</html>