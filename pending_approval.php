<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: resort_login.php");
    exit;
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

    <title>Waiting for Approval</title>

    <link
        rel="stylesheet"
        href="style.css"
    >

    <style>

        .pending-card {
            text-align: center;
            max-width: 500px;
        }

        .pending-icon {
            font-size: 60px;
            margin-bottom: 15px;
        }

        .pending-card h1 {
            color: #1f5d42;
            margin-bottom: 10px;
        }

        .pending-card p {
            color: #666;
            line-height: 1.6;
        }

        .status-badge {
            display: inline-block;
            margin: 20px 0;
            padding: 10px 20px;
            border-radius: 20px;
            background: #fff3cd;
            color: #856404;
            font-weight: bold;
        }

        .logout-btn {
            display: inline-block;
            margin-top: 10px;
            padding: 12px 25px;
            background: #1f5d42;
            color: white;
            border-radius: 6px;
        }

        .logout-btn:hover {
            opacity: 0.9;
        }

    </style>

</head>

<body>

<div class="page">

    <div class="form-card pending-card">

        <div class="pending-icon">
            ⏳
        </div>

        <h1>
            Account Pending Approval
        </h1>

        <p>
            Your resort account has been successfully registered.
        </p>

        <p>
            Your account is currently waiting for approval from the
            Barangay Tourism Admin.
        </p>

        <div class="status-badge">
            🟡 Pending Approval
        </div>

        <p>
            You will receive an email notification once your
            account has been approved.
        </p>

        <a
            href="logout.php?confirm=yes"
            class="logout-btn"
        >
            Logout
        </a>

    </div>

</div>

</body>

</html>