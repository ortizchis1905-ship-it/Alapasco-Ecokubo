<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: resort_login.php");
    exit;
}
$redirect_target = "settings.php#profile";
header("Location: " . $redirect_target, true, $_SERVER["REQUEST_METHOD"] === "POST" ? 307 : 302);
exit;
