<?php

session_start();

// Only allow users who successfully verified the OTP
if (
    !isset($_SESSION["reset_email"]) ||
    !isset($_SESSION["otp_verified"]) ||
    $_SESSION["otp_verified"] !== true
) {
    header("Location: forgot_password.php");
    exit;
}

require_once "database.php";

$message = "";
$message_type = "";

$email = $_SESSION["reset_email"];


if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $password = $_POST["password"];
    $confirm_password = $_POST["confirm_password"];


    // Check if fields are empty
    if (empty($password) || empty($confirm_password)) {

        $message = "Please fill in all fields.";
        $message_type = "error";

    }

    // Check password length
    elseif (strlen($password) < 6) {

        $message = "Password must be at least 6 characters.";
        $message_type = "error";

    }

    // Check if passwords match
    elseif ($password !== $confirm_password) {

        $message = "Passwords do not match.";
        $message_type = "error";

    }

    else {

        // Securely hash the new password
        $hashed_password = password_hash(
            $password,
            PASSWORD_DEFAULT
        );


        // Update password in database
        $stmt = $conn->prepare(
            "UPDATE users
             SET password = ?
             WHERE email = ?"
        );

        $stmt->bind_param(
            "ss",
            $hashed_password,
            $email
        );


        if ($stmt->execute()) {

            // Clear password reset session
            unset($_SESSION["reset_email"]);
            unset($_SESSION["otp_verified"]);

            // Redirect to login
            header("Location: resort_login.php?reset=success");
            exit;

        } else {

            $message = "Unable to reset password. Please try again.";
            $message_type = "error";
        }


        $stmt->close();
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

    <title>Reset Password</title>

    <link rel="stylesheet" href="style.css">

</head>

<body>

<div class="page">

    <div class="form-card">

        <a href="resort_login.php" class="back">
            ← Back to Login
        </a>


        <div class="form-icon">🔑</div>


        <h1>Reset Password</h1>


        <p class="subtitle">
            Create a new password for your account
        </p>


        <?php if (!empty($message)): ?>

            <script>
                alert("<?php echo htmlspecialchars($message, ENT_QUOTES); ?>");
            </script>

        <?php endif; ?>


        <form method="POST">


            <label>New Password</label>

            <input
                type="password"
                name="password"
                placeholder="Enter new password"
                minlength="6"
                required
            >


            <label>Confirm New Password</label>

            <input
                type="password"
                name="confirm_password"
                placeholder="Confirm new password"
                minlength="6"
                required
            >


            <button type="submit">
                Reset Password
            </button>


        </form>


        <p class="register-text">

            Remember your password?

            <a href="resort_login.php">
                Login here
            </a>

        </p>

    </div>

</div>

</body>

</html>