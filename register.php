<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="logo1.png">

    <title>Register - Alapasco EcoKubo</title>

    <link rel="stylesheet" href="css/style.css">
</head>

<body>

<div class="container">

    <div class="login-box">

        <h1>Create Account</h1>

        <p class="subtitle">
            Rest House / Resort Registration
        </p>

        <form action="#" method="POST">

            <label>Rest House / Resort Name</label>

            <input
                type="text"
                name="resort_name"
                placeholder="Enter establishment name"
                required
            >

            <label>Owner / Secretary Name</label>

            <input
                type="text"
                name="owner_name"
                placeholder="Enter name"
                required
            >

            <label>Email</label>

            <input
                type="email"
                name="email"
                placeholder="Enter email"
                required
            >

            <label>Contact Number</label>

            <input
                type="text"
                name="contact"
                placeholder="Enter contact number"
                required
            >

            <label>Password</label>

            <input
                type="password"
                name="password"
                placeholder="Create password"
                required
            >

            <label>Confirm Password</label>

            <input
                type="password"
                name="confirm_password"
                placeholder="Confirm password"
                required
            >

            <button type="submit">Register</button>

        </form>

        <p class="register-text">
            Already have an account?
            <a href="index.php">Login here</a>
        </p>

    </div>

</div>

</body>
</html>