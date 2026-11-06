<?php

session_start();

$message = "";
$message_type = "";

if (!isset($_SESSION["reset_email"])) {
    header("Location: forgot_password.php");
    exit;
}

if (!isset($_SESSION["reset_otp"])) {
    header("Location: forgot_password.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $entered_otp = trim($_POST["otp"]);

    // Check if OTP has expired
    if (time() > $_SESSION["reset_otp_expiry"]) {

        $message = "OTP has expired. Please request a new OTP.";
        $message_type = "error";

        unset($_SESSION["reset_otp"]);
        unset($_SESSION["reset_otp_expiry"]);

    }

    // Check OTP
    elseif ($entered_otp == $_SESSION["reset_otp"]) {

        // OTP is correct
        $_SESSION["otp_verified"] = true;

        // Remove OTP so it cannot be reused
        unset($_SESSION["reset_otp"]);
        unset($_SESSION["reset_otp_expiry"]);

        // Continue to password reset
        header("Location: reset_password.php");
        exit;

    } else {

        $message = "Incorrect OTP. Please try again.";
        $message_type = "error";
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

    <title>Verify OTP</title>

    <link rel="stylesheet" href="style.css">

</head>

<body>

<div class="page">

    <div class="form-card">

        <a href="resort_login.php" class="back">
            ← Back to Login
        </a>

        <div class="form-icon">🔢</div>

        <h1>Verify OTP</h1>

        <p class="subtitle">
            Enter the 6-digit verification code
        </p>

        <?php if (!empty($message)): ?>

            <script>
                alert("<?php echo htmlspecialchars($message, ENT_QUOTES); ?>");
            </script>

        <?php endif; ?>

        <form method="POST">

            <label>Verification Code</label>

            <input
                type="text"
                name="otp"
                placeholder="Enter 6-digit OTP"
                maxlength="6"
                inputmode="numeric"
                required
            >

            <button type="submit">
                Verify OTP
            </button>

        </form>

    </div>

</div>

</body>

</html>