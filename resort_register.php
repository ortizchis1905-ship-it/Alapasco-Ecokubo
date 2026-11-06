<?php

require_once "database.php";

$message = "";
$message_type = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $resort_name = trim($_POST["resort_name"] ?? "");
    $first_name = trim($_POST["first_name"] ?? "");
    $last_name = trim($_POST["last_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $contact_number = trim($_POST["contact_number"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    $full_name = trim($first_name . " " . $last_name);

    if (
        $resort_name === "" ||
        $first_name === "" ||
        $last_name === "" ||
        $email === "" ||
        $contact_number === "" ||
        $password === "" ||
        $confirm_password === ""
    ) {

        $message = "Please fill in all fields.";
        $message_type = "error";

    } elseif (!preg_match('/^[a-zA-ZÀ-ÿ\s\'-]+$/', $first_name)) {

        $message = "First name can only contain letters.";
        $message_type = "error";

    } elseif (!preg_match('/^[a-zA-ZÀ-ÿ\s\'-]+$/', $last_name)) {

        $message = "Last name can only contain letters.";
        $message_type = "error";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email address.";
        $message_type = "error";

    } elseif (!preg_match('/^[0-9]{11}$/', $contact_number)) {

        $message = "Contact number must contain exactly 11 digits.";
        $message_type = "error";

    } elseif ($password !== $confirm_password) {

        $message = "Passwords do not match.";
        $message_type = "error";

    } else {

        $check = $conn->prepare(
            "SELECT id FROM users WHERE email = ? LIMIT 1"
        );

        if (!$check) {

            $message = "Database error. Please try again.";
            $message_type = "error";

        } else {

            $check->bind_param("s", $email);
            $check->execute();

            $result = $check->get_result();

            if ($result->num_rows > 0) {

                $message =
                    "This email is already registered. Please use another email or log in instead.";

                $message_type = "error";

                $check->close();

            } else {

                $check->close();

                $conn->begin_transaction();

                try {

                    $hashed_password = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | CREATE USER
                    |--------------------------------------------------------------------------
                    */

                    $user_stmt = $conn->prepare(
                        "INSERT INTO users
                        (
                            resort_name,
                            first_name,
                            last_name,
                            full_name,
                            email,
                            contact_number,
                            password
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );

                    if (!$user_stmt) {
                        throw new Exception(
                            "Could not prepare user account."
                        );
                    }

                    $user_stmt->bind_param(
                        "sssssss",
                        $resort_name,
                        $first_name,
                        $last_name,
                        $full_name,
                        $email,
                        $contact_number,
                        $hashed_password
                    );

                    if (!$user_stmt->execute()) {
                        throw new Exception(
                            "Could not create user account: " .
                            $user_stmt->error
                        );
                    }

                    $user_id = $conn->insert_id;

                    $user_stmt->close();


                    /*
                    |--------------------------------------------------------------------------
                    | CREATE RESORT
                    |--------------------------------------------------------------------------
                    */

                    $resort_stmt = $conn->prepare(
                        "INSERT INTO resorts
                        (
                            user_id,
                            resort_name,
                            contact_number,
                            status
                        )
                        VALUES (?, ?, ?, 'Pending')"
                    );

                    if (!$resort_stmt) {
                        throw new Exception(
                            "Could not prepare resort account."
                        );
                    }

                    $resort_stmt->bind_param(
                        "iss",
                        $user_id,
                        $resort_name,
                        $contact_number
                    );

                    if (!$resort_stmt->execute()) {
                        throw new Exception(
                            "Could not create resort account: " .
                            $resort_stmt->error
                        );
                    }

                    $resort_id = $conn->insert_id;

                    $resort_stmt->close();


                    /*
                    |--------------------------------------------------------------------------
                    | CREATE NOTIFICATION
                    |--------------------------------------------------------------------------
                    */

                    $notification_stmt = $conn->prepare(
                        "INSERT INTO notifications
                        (
                            resort_id,
                            notification_type,
                            title,
                            message,
                            is_read
                        )
                        VALUES
                        (
                            ?,
                            'Account',
                            'Registration Submitted',
                            ?,
                            0
                        )"
                    );

                    if (!$notification_stmt) {
                        throw new Exception(
                            "Could not prepare notification."
                        );
                    }

                    $notification_message =
                        "Your resort registration has been submitted and is waiting for Barangay Tourism Admin approval.";

                    $notification_stmt->bind_param(
                        "is",
                        $resort_id,
                        $notification_message
                    );

                    if (!$notification_stmt->execute()) {
                        throw new Exception(
                            "Could not create notification: " .
                            $notification_stmt->error
                        );
                    }

                    $notification_stmt->close();


                    /*
                    |--------------------------------------------------------------------------
                    | SAVE
                    |--------------------------------------------------------------------------
                    */

                    $conn->commit();

                    $message =
                        "Registration successful! Your account is now waiting for approval from the Barangay Tourism Admin.";

                    $message_type = "success";

                } catch (Exception $e) {

                    $conn->rollback();

                    $message =
                        "Registration failed: " .
                        $e->getMessage();

                    $message_type = "error";
                }
            }
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

    <title>Resort Registration</title>

    <link
        rel="stylesheet"
        href="style.css"
    >

</head>

<body>

<div class="page">

    <div class="form-card register-card">

        <!-- BACK TO LOGIN -->

        <a
            href="./resort_login.php"
            class="back"
        >
            ← Back to Login
        </a>


        <div class="form-icon">
            🏡
        </div>


        <h1>
            Register Resort
        </h1>


        <p class="subtitle">
            Create your Rest House / Resort Account
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
                Rest House / Resort Name
            </label>

            <input
                type="text"
                name="resort_name"
                placeholder="Enter establishment name"
                value="<?php echo htmlspecialchars($_POST["resort_name"] ?? ""); ?>"
                required
            >


            <label>
                First Name
            </label>

            <input
                type="text"
                name="first_name"
                placeholder="Enter first name"
                value="<?php echo htmlspecialchars($_POST["first_name"] ?? ""); ?>"
                required
            >


            <label>
                Last Name
            </label>

            <input
                type="text"
                name="last_name"
                placeholder="Enter last name"
                value="<?php echo htmlspecialchars($_POST["last_name"] ?? ""); ?>"
                required
            >


            <label>
                Contact Number
            </label>

            <input
                type="tel"
                name="contact_number"
                placeholder="09XXXXXXXXX"
                maxlength="11"
                inputmode="numeric"
                pattern="[0-9]{11}"
                value="<?php echo htmlspecialchars($_POST["contact_number"] ?? ""); ?>"
                required
                oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)"
            >


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


            <!-- PASSWORD -->

            <label>
                Password
            </label>

            <div style="position:relative;width:100%;">

                <input
                    type="password"
                    name="password"
                    id="password"
                    placeholder="Create password"
                    required
                    oninput="checkPasswordStrength()"
                    style="
                        width:100%;
                        padding-right:45px;
                        box-sizing:border-box;
                    "
                >

                <button
                    type="button"
                    onclick="togglePassword('password', this)"
                    style="
                        position:absolute;
                        right:10px;
                        top:50%;
                        transform:translateY(-50%);
                        border:none;
                        background:transparent;
                        padding:5px;
                        margin:0;
                        width:auto;
                        cursor:pointer;
                        font-size:18px;
                    "
                >
                    👁️
                </button>

            </div>


            <div
                id="passwordStrength"
                style="
                    margin-top:6px;
                    font-size:13px;
                    font-weight:bold;
                "
            ></div>


            <!-- CONFIRM PASSWORD -->

            <label>
                Confirm Password
            </label>

            <div style="position:relative;width:100%;">

                <input
                    type="password"
                    name="confirm_password"
                    id="confirm_password"
                    placeholder="Confirm password"
                    required
                    style="
                        width:100%;
                        padding-right:45px;
                        box-sizing:border-box;
                    "
                >

                <button
                    type="button"
                    onclick="togglePassword('confirm_password', this)"
                    style="
                        position:absolute;
                        right:10px;
                        top:50%;
                        transform:translateY(-50%);
                        border:none;
                        background:transparent;
                        padding:5px;
                        margin:0;
                        width:auto;
                        cursor:pointer;
                        font-size:18px;
                    "
                >
                    👁️
                </button>

            </div>


            <button type="submit">
                Register
            </button>

        </form>


        <!-- LOGIN LINK -->

        <p class="register-text">

            Already have an account?

            <a href="./resort_login.php">
                Login here
            </a>

        </p>

    </div>

</div>


<script>

function togglePassword(fieldId, button) {

    const field = document.getElementById(fieldId);

    if (field.type === "password") {

        field.type = "text";
        button.textContent = "🙈";

    } else {

        field.type = "password";
        button.textContent = "👁️";

    }
}


function checkPasswordStrength() {

    const password =
        document.getElementById("password").value;

    const strength =
        document.getElementById("passwordStrength");


    if (password.length === 0) {

        strength.textContent = "";

        return;
    }


    let score = 0;


    if (password.length >= 8) {
        score++;
    }

    if (/[a-z]/.test(password)) {
        score++;
    }

    if (/[A-Z]/.test(password)) {
        score++;
    }

    if (/[0-9]/.test(password)) {
        score++;
    }

    if (/[^A-Za-z0-9]/.test(password)) {
        score++;
    }


    if (score <= 2) {

        strength.textContent =
            "Password Strength: Weak";

        strength.style.color =
            "#dc3545";

    } else if (score <= 4) {

        strength.textContent =
            "Password Strength: Good";

        strength.style.color =
            "#d39e00";

    } else {

        strength.textContent =
            "Password Strength: Strong";

        strength.style.color =
            "#198754";
    }
}

</script>

</body>

</html>