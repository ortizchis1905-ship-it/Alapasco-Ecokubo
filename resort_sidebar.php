<?php
// Include once after <body> on authenticated resort pages.
$resort_sidebar_active = basename($_SERVER["SCRIPT_NAME"] ?? "");
$resort_sidebar_groups = [
    "MAIN" => [["resort_dashboard.php", "🏠", "Dashboard"]],
    "OPERATIONS" => [
        ["accommodations.php", "🏡", "Accommodations"],
        ["reservations.php", "📅", "Reservations"],
        ["guests.php", "👥", "Guests / Visitors"],
        ["checkin.php", "📝", "Check-In"],
        ["checkout.php", "🚪", "Check-Out"]
    ],
    "FINANCIAL" => [["payments.php", "💰", "Payments"]],
    "MONITORING" => [
        ["current_visitors.php", "📍", "Current Visitors"]
    ],
    "REPORTS" => [["reports.php", "📄", "Reports"]],
    "ACCOUNT" => [
        ["settings.php", "⚙️", "Settings"],
        ["logout.php", "🚪", "Logout"]
    ]
];
?>
<style>
body .resort-sidebar {
    box-sizing: border-box; width: 250px; background: #1f5d42; color: #fff;
    position: fixed; left: 0; top: 0; bottom: 0; height: 100vh;
    min-height: 0; margin: 0; padding: 0; overflow-y: auto; z-index: 1000;
    font-family: Arial, sans-serif;
}
body .resort-sidebar .sidebar-logo {
    padding: 20px 15px; text-align: center;
    border-bottom: 1px solid rgba(255,255,255,.15);
}
body .resort-sidebar .sidebar-logo img {
    width: 78px; height: 78px; object-fit: contain; display: block;
    margin: 0 auto 10px; background: #fff; border-radius: 50%; padding: 8px;
}
body .resort-sidebar .sidebar-logo h2 { margin: 0; font-size: 18px; color: #fff; }
body .resort-sidebar .sidebar-logo p { margin: 5px 0 0; font-size: 11px; opacity: .8; color: #fff; }
body .resort-sidebar .admin-label { display: block; margin-top: 10px; font-size: 10px; font-weight: bold; letter-spacing: 1px; opacity: .8; }
body .resort-sidebar .sidebar-section { padding: 17px 18px 7px; font-size: 11px; font-weight: bold; letter-spacing: .5px; opacity: .65; }
body .resort-sidebar a {
    display: flex; align-items: center; gap: 9px; color: #fff; padding: 12px 20px;
    font-size: 14px; line-height: 20px; text-decoration: none;
    border-left: 4px solid transparent; touch-action: manipulation;
}
body .resort-sidebar a:hover { background: rgba(255,255,255,.12); }
body .resort-sidebar a.active { background: rgba(255,255,255,.18); border-left-color: #fff; padding-left: 20px; }
body .resort-sidebar a:focus-visible { outline: 2px solid #fff; outline-offset: -4px; }
body .main-content { margin-left: 250px; width: calc(100% - 250px); min-width: 0; }

.resort-menu-button, .resort-sidebar-overlay { display: none; }
@media (max-width: 700px) {
    body .resort-sidebar { width: 250px; left: -250px; transition: left .2s ease; }
    body .resort-sidebar.show { left: 0; }
    body .main-content { margin-left: 0; width: 100%; }
    .resort-menu-button { display: block; position: fixed; bottom: 18px; left: 18px; z-index: 1200; background: #1f5d42; color: white; border: 1px solid white; border-radius: 8px; padding: 12px 16px; font: bold 14px Arial,sans-serif; cursor: pointer; }
    .resort-sidebar-overlay.show { display: block; position: fixed; inset: 0; border: 0; background: rgba(0,0,0,.35); z-index: 999; }
}

.resort-header-account { display: flex; align-items: center; justify-content: flex-end; gap: 16px; margin-left: auto; }
body .resort-notification-bell { position: relative; display: inline-flex; align-items: center; justify-content: center; flex: 0 0 42px; width: 42px; height: 42px; color: #1f5d42; border: 1px solid #dce8e1; border-radius: 50%; background: #f5f9f6; text-decoration: none; }
body .resort-notification-bell:hover { background: #e5f2eb; }
.resort-notification-badge { position: absolute; top: -5px; right: -5px; min-width: 18px; height: 18px; padding: 0 4px; border-radius: 10px; background: #c73535; color: #fff; font: bold 10px/18px Arial,sans-serif; text-align: center; }
@media (max-width:700px) { .resort-header-account { gap: 9px; } }
</style>
<button type="button" class="resort-sidebar-overlay" id="resort-sidebar-overlay" aria-label="Close navigation" tabindex="-1"></button>
<nav class="sidebar resort-sidebar" id="sidebar" aria-label="Resort navigation">
    <div class="sidebar-logo">
        <img src="logo1.png" alt="Alapasco EcoKubo Logo">
        <h2>ALAPASCO ECOKUBO</h2>
        <p>Tourism Management System</p>
    </div>
    <?php foreach ($resort_sidebar_groups as $resort_sidebar_heading => $resort_sidebar_links): ?>
        <div class="sidebar-section"><?php echo htmlspecialchars($resort_sidebar_heading, ENT_QUOTES, "UTF-8"); ?></div>
        <?php foreach ($resort_sidebar_links as [$resort_sidebar_url, $resort_sidebar_icon, $resort_sidebar_label]): ?>
            <a href="<?php echo htmlspecialchars($resort_sidebar_url, ENT_QUOTES, "UTF-8"); ?>"<?php if ($resort_sidebar_url === "logout.php"): ?> onclick="return confirm('Are you sure you want to logout?');"<?php endif; ?><?php if ($resort_sidebar_active === $resort_sidebar_url): ?> class="active" aria-current="page"<?php endif; ?>>
                <span aria-hidden="true"><?php echo $resort_sidebar_icon; ?></span>
                <span><?php echo htmlspecialchars($resort_sidebar_label, ENT_QUOTES, "UTF-8"); ?></span>
            </a>
        <?php endforeach; ?>
    <?php endforeach; ?>
</nav>
<button type="button" class="resort-menu-button" id="resort-menu-button" aria-controls="sidebar" aria-expanded="false">☰ Menu</button>
<script>
function resortToggleSidebar(forceOpen) {
    const sidebar = document.getElementById('sidebar');
    const open = typeof forceOpen === 'boolean' ? forceOpen : !sidebar.classList.contains('show');
    sidebar.classList.toggle('show', open);
    document.getElementById('resort-sidebar-overlay').classList.toggle('show', open);
    document.getElementById('resort-menu-button').setAttribute('aria-expanded', String(open));
}
document.getElementById('resort-menu-button').addEventListener('click', function () { resortToggleSidebar(); });
document.getElementById('resort-sidebar-overlay').addEventListener('click', function () { resortToggleSidebar(false); });
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') resortToggleSidebar(false);
});
</script>
