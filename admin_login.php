<?php

$error = "";

if (isset($_GET["error"])) {
    if ($_GET["error"] === "empty") {
        $error = "Please enter your username or email and password.";
    } elseif ($_GET["error"] === "invalid") {
        $error = "Invalid username/email or password.";
    } elseif ($_GET["error"] === "database") {
        $error = "Unable to connect to the database.";
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Login</title>

    <link rel="stylesheet" href="style.css">
    <link rel="icon" type="image/png" href="logo1.png">

    <style>
        .error-message {
            background: #f8d7da;
            color: #842029;
            padding: 10px 12px;
            border-radius: 6px;
            margin-bottom: 15px;
            font-size: 13px;
            text-align: center;
        }

        .password-field {
            position: relative;
            width: 100%;
        }

        .password-field input {
            display: block;
            width: 100%;
            box-sizing: border-box;
            padding-right: 48px;
        }

        .password-field .password-toggle {
            position: absolute;
            top: 50%;
            right: 12px;
            transform: translateY(-50%);
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            margin: 0;
            padding: 0;
            border: 0;
            background: transparent;
            color: #666;
            cursor: pointer;
        }

        .password-field .password-toggle:hover {
            color: #222;
        }

        .password-field .password-toggle:focus-visible {
            outline: 2px solid #F97316;
            outline-offset: 2px;
            border-radius: 50%;
        }

        .password-toggle svg {
            display: block;
            width: 19px;
            height: 19px;
            fill: none;
            stroke: currentColor;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .password-toggle .eye-off-icon {
            display: none;
        }

        .password-toggle.is-visible .eye-icon {
            display: none;
        }

        .password-toggle.is-visible .eye-off-icon {
            display: block;
        }
    </style>
</head>

<body>

    <div class="page">

        <div class="form-card">

            <a href="index.php" class="back">
                ← Back
            </a>

            <div class="form-icon">
                👤
            </div>

            <h1>Admin Login</h1>

            <p class="subtitle">
                Barangay Tourism Desk
            </p>

            <?php if ($error !== ""): ?>
                <div class="error-message">
                    <?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?>
                </div>
            <?php endif; ?>

            <form action="admin_auth.php" method="POST">

                <label for="username">
                    Username or Email
                </label>

                <input
                    type="text"
                    id="username"
                    name="username"
                    placeholder="Enter username or email"
                    autocomplete="username"
                    required
                >

                <label for="password">
                    Password
                </label>

                <div class="password-field">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter password"
                        required
                    >

                    <button
                        type="button"
                        class="password-toggle"
                        id="passwordToggle"
                        aria-label="Show password"
                        aria-pressed="false"
                    >
                        <svg
                            class="eye-icon"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>

                        <svg
                            class="eye-off-icon"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path d="m3 3 18 18"/>
                            <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8"/>
                            <path d="M9.9 5.2A10.8 10.8 0 0 1 12 5c6.4 0 10 7 10 7a15.7 15.7 0 0 1-3.1 3.9"/>
                            <path d="M6.2 6.2C3.5 8 2 12 2 12s3.6 7 10 7a10.6 10.6 0 0 0 4-.8"/>
                        </svg>
                    </button>
                </div>

                <button type="submit">
                    Login
                </button>

            </form>

            <?php if (($_GET["reset"] ?? "") === "success"): ?>
                <p role="status" style="color:#1f5d42;">Password reset successfully. Log in with your new password.</p>
            <?php endif; ?>

            <p class="forgot">
                <a href="admin_forgot_password.php">
                    Forgot Password?
                </a>
            </p>

        </div>

    </div>

    <script>
        const passwordInput = document.getElementById("password");
        const passwordToggle = document.getElementById("passwordToggle");

        passwordToggle.addEventListener("click", function () {
            const showPassword = passwordInput.type === "password";

            passwordInput.type = showPassword ? "text" : "password";
            passwordToggle.classList.toggle("is-visible", showPassword);

            passwordToggle.setAttribute(
                "aria-label",
                showPassword ? "Hide password" : "Show password"
            );

            passwordToggle.setAttribute(
                "aria-pressed",
                String(showPassword)
            );
        });
    </script>

</body>

</html>