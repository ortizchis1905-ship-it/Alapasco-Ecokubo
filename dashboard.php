<?php

$account_type = $_POST['account_type'] ?? '';

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="logo1.png">
    <title>Dashboard</title>

</head>

<body>

<h1>Login Successful</h1>

<?php if ($account_type == "admin"): ?>

    <h2>Barangay Tourism Admin Dashboard</h2>

<?php elseif ($account_type == "user"): ?>

    <h2>Rest House / Resort User Dashboard</h2>

<?php else: ?>

    <h2>No account type selected.</h2>

<?php endif; ?>

</body>

</html>