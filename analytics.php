<?php
session_start();
if (!isset($_SESSION["user_id"])) {
    header("Location: resort_login.php");
    exit;
}

$redirect_params = [];
foreach (["start_date", "end_date"] as $key) {
    if (isset($_GET[$key]) && is_string($_GET[$key])) $redirect_params[$key] = $_GET[$key];
}
$redirect_target = "resort_dashboard.php" . ($redirect_params ? "?" . http_build_query($redirect_params) : "") . "#analytics";
header("Location: " . $redirect_target, true, $_SERVER["REQUEST_METHOD"] === "POST" ? 307 : 302);
exit;
