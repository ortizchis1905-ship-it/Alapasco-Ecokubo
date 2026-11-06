<?php
session_start();
if (!isset($_SESSION["admin_id"])) {
    header("Location: admin_login.php");
    exit;
}
// Preserve old links and bookmarks after moving analytics into the dashboard.
header("Location: admin_dashboard.php#analytics");
exit;
