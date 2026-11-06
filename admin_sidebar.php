<?php
// Shared admin navigation. Include after <body> on authenticated admin pages.
$admin_sidebar_page = basename($_SERVER["SCRIPT_NAME"] ?? "");
$admin_sidebar_active = [
    "admin_resort_details.php" => "admin_resorts.php",
    "admin_profile.php" => "admin_settings.php",
    "admin_analytics.php" => "admin_dashboard.php"
][$admin_sidebar_page] ?? $admin_sidebar_page;
$admin_sidebar_groups = [
    "MAIN" => [["admin_dashboard.php", "🏠", "Dashboard"]],
    "MONITORING" => [
        ["admin_resorts.php", "🏡", "Registered Resorts"],
        ["admin_visitors.php", "👥", "Visitors"],
        ["admin_monitoring.php", "📍", "Monitoring"]
    ],
    "REPORTS" => [["admin_reports.php", "📄", "Reports"]],
    "ACCOUNT" => [
        ["admin_settings.php", "⚙️", "Settings"],
        ["admin_logout.php", "🚪", "Logout"]
    ]
];
?>
<style>
body .sidebar {
    box-sizing: border-box; width: 250px; background: #1f5d42; color: #fff;
    position: fixed; left: 0; top: 0; bottom: 0; height: 100vh;
    min-height: 0; margin: 0; padding: 0; overflow-y: auto; z-index: 1000;
    font-family: Arial, sans-serif;
}
body .sidebar .sidebar-logo {
    padding: 20px 15px; text-align: center;
    border-bottom: 1px solid rgba(255,255,255,.15);
}
body .sidebar .sidebar-logo img {
    width: 78px; height: 78px; object-fit: contain; display: block;
    margin: 0 auto 10px; background: #fff; border-radius: 50%; padding: 8px;
}
body .sidebar .sidebar-logo h2 { margin: 0; font-size: 18px; color: #fff; }
body .sidebar .sidebar-logo p { margin: 5px 0 0; font-size: 11px; opacity: .8; color: #fff; }
body .sidebar .admin-label { display: block; margin-top: 10px; font-size: 10px; font-weight: bold; letter-spacing: 1px; opacity: .8; }
body .sidebar .sidebar-section { padding: 17px 18px 7px; font-size: 11px; font-weight: bold; letter-spacing: .5px; opacity: .65; }
body .sidebar a {
    display: flex; align-items: center; gap: 9px; color: #fff; padding: 12px 20px;
    font-size: 14px; line-height: 20px; text-decoration: none;
    border-left: 4px solid transparent; touch-action: manipulation;
}
body .sidebar a:hover { background: rgba(255,255,255,.12); }
body .sidebar a.active { background: rgba(255,255,255,.18); border-left-color: #fff; padding-left: 20px; }
body .sidebar a:focus-visible { outline: 2px solid #fff; outline-offset: -4px; }
body .main-content { margin-left: 250px; width: calc(100% - 250px); min-width: 0; }
@media (max-width: 700px) {
    body .sidebar { width: 200px; }
    body .main-content { margin-left: 200px; width: calc(100% - 200px); }
}
</style>
<nav class="sidebar" aria-label="Admin navigation">
    <div class="sidebar-logo">
        <img src="logo1.png" alt="Alapasco EcoKubo Logo">
        <h2>ALAPASCO ECOKUBO</h2>
        <p>Tourism Management System</p>
        <div class="admin-label">BARANGAY ADMIN</div>
    </div>
    <?php foreach ($admin_sidebar_groups as $admin_sidebar_heading => $admin_sidebar_links): ?>
        <div class="sidebar-section"><?php echo htmlspecialchars($admin_sidebar_heading, ENT_QUOTES, "UTF-8"); ?></div>
        <?php foreach ($admin_sidebar_links as [$admin_sidebar_url, $admin_sidebar_icon, $admin_sidebar_label]): ?>
            <?php $admin_sidebar_href = $admin_sidebar_url === "admin_reports.php"
                ? $admin_sidebar_url . "#reports"
                : $admin_sidebar_url; ?>
            <a href="<?php echo htmlspecialchars($admin_sidebar_href, ENT_QUOTES, "UTF-8"); ?>"<?php if ($admin_sidebar_active === $admin_sidebar_url): ?> class="active" aria-current="page"<?php endif; ?>>
                <span aria-hidden="true"><?php echo $admin_sidebar_icon; ?></span>
                <span><?php echo htmlspecialchars($admin_sidebar_label, ENT_QUOTES, "UTF-8"); ?></span>
            </a>
        <?php endforeach; ?>
    <?php endforeach; ?>
</nav>
