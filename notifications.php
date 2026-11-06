<?php
session_start();
require_once "database.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: resort_login.php");
    exit;
}

$user_id = $_SESSION["user_id"];

/* =========================================================
   GET RESORT ID
========================================================= */

$stmt = $conn->prepare("
    SELECT id, resort_name
    FROM resorts
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $user_id);
$stmt->execute();
$resort = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$resort) {

    $stmt = $conn->prepare("
        INSERT INTO resorts (user_id, resort_name)
        VALUES (?, 'My Resort')
    ");

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $resort_id = $stmt->insert_id;
    $stmt->close();

    $resort_name = "My Resort";

} else {

    $resort_id = $resort["id"];
    $resort_name = $resort["resort_name"];
}


/* =========================================================
   USER SETTINGS
========================================================= */

$stmt = $conn->prepare("
    SELECT notifications_enabled
    FROM user_settings
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $user_id);
$stmt->execute();
$settings = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$settings) {

    $stmt = $conn->prepare("
        INSERT INTO user_settings
        (user_id, notifications_enabled, dashboard_activity_enabled)
        VALUES (?, 1, 1)
    ");

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();

    $notifications_enabled = 1;

} else {

    $notifications_enabled = (int)$settings["notifications_enabled"];
}


/* =========================================================
   DELETE ONE NOTIFICATION
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_notification"])) {

    $notification_id = (int)$_POST["delete_notification"];

    if ($notification_id > 0) {

        $stmt = $conn->prepare("
            DELETE FROM notifications
            WHERE id = ?
            AND resort_id = ?
        ");

        $stmt->bind_param("ii", $notification_id, $resort_id);
        $stmt->execute();
        $stmt->close();
    }

    header("Location: notifications.php");
    exit;
}


/* =========================================================
   DELETE ALL NOTIFICATIONS
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["delete_all_notifications"])) {

    $stmt = $conn->prepare("
        DELETE FROM notifications
        WHERE resort_id = ?
    ");

    $stmt->bind_param("i", $resort_id);
    $stmt->execute();
    $stmt->close();

    header("Location: notifications.php");
    exit;
}


/* =========================================================
   MARK ALL AS READ
========================================================= */

if (isset($_GET["read"]) && $_GET["read"] === "all") {

    $stmt = $conn->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE resort_id = ?
    ");

    $stmt->bind_param("i", $resort_id);
    $stmt->execute();
    $stmt->close();

    header("Location: notifications.php");
    exit;
}


/* =========================================================
   VIEW ONE NOTIFICATION
   VIEWING IT AUTOMATICALLY MARKS IT AS READ
========================================================= */

$view_notification = null;

if (isset($_GET["view_id"])) {

    $view_id = (int)$_GET["view_id"];

    if ($view_id > 0) {

        /* Mark as read */

        $stmt = $conn->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE id = ?
            AND resort_id = ?
        ");

        $stmt->bind_param("ii", $view_id, $resort_id);
        $stmt->execute();
        $stmt->close();


        /* Get notification */

        $stmt = $conn->prepare("
            SELECT
                id,
                notification_type,
                title,
                message,
                is_read,
                created_at
            FROM notifications
            WHERE id = ?
            AND resort_id = ?
            LIMIT 1
        ");

        $stmt->bind_param("ii", $view_id, $resort_id);
        $stmt->execute();

        $view_notification = $stmt->get_result()->fetch_assoc();

        $stmt->close();
    }
}


/* =========================================================
   GET NOTIFICATIONS
========================================================= */

$notifications = [];

$stmt = $conn->prepare("
    SELECT
        id,
        notification_type,
        title,
        message,
        is_read,
        created_at
    FROM notifications
    WHERE resort_id = ?
    ORDER BY created_at DESC
");

$stmt->bind_param("i", $resort_id);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}

$stmt->close();


/* =========================================================
   UNREAD COUNT
========================================================= */

$unread_count = 0;

foreach ($notifications as $notification) {

    if ((int)$notification["is_read"] === 0) {
        $unread_count++;
    }
}


/* =========================================================
   CURRENT USER
========================================================= */

$user_name = $_SESSION["user_name"] ?? "Resort User";

?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="logo1.png">

<title>Notifications - Alapasco EcoKubo</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f4f6f5;
    color: #333;
}

a {
    text-decoration: none;
}


/* =========================================================
   MAIN CONTENT
========================================================= */

.dashboard-container {
    display: flex;
    min-height: 100vh;
}

.main-content {
    margin-left: 250px;
    width: calc(100% - 250px);
    min-height: 100vh;
}


/* =========================================================
   TOPBAR
========================================================= */

.topbar {
    background: white;
    min-height: 65px;
    padding: 15px 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #ddd;
    position: sticky;
    top: 0;
    z-index: 500;
}

.topbar h3 {
    margin: 0;
    color: #1f5d42;
    font-size: 20px;
}

.user-info {
    text-align: right;
}

.user-info strong {
    display: block;
    color: #333;
    font-size: 14px;
}


/* =========================================================
   PAGE
========================================================= */

.page-content {
    padding: 25px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
}

.page-header h2 {
    margin: 0;
    color: #1f5d42;
    font-size: 24px;
}

.page-header p {
    margin: 5px 0 0;
    color: #777;
    font-size: 13px;
}


/* =========================================================
   SETTINGS STATUS
========================================================= */

.notification-status {
    background: white;
    border: 1px solid #ddd;
    border-radius: 10px;
    padding: 15px 18px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.notification-status strong {
    color: #333;
}

.status-enabled {
    color: #1f5d42;
    font-weight: bold;
}

.status-disabled {
    color: #b02a37;
    font-weight: bold;
}


/* =========================================================
   SUMMARY
========================================================= */

.summary-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
    margin-bottom: 20px;
}

.summary-card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 10px;
    padding: 18px;
}

.summary-card small {
    color: #777;
    font-size: 12px;
}

.summary-card h2 {
    margin: 8px 0 0;
    color: #1f5d42;
    font-size: 25px;
}


/* =========================================================
   ACTIONS
========================================================= */

.notification-actions {
    background: white;
    border: 1px solid #ddd;
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.tabs {
    display: flex;
    gap: 8px;
}

.tab-btn {
    border: none;
    background: #f0f2f1;
    color: #555;
    padding: 9px 15px;
    border-radius: 7px;
    cursor: pointer;
    font-size: 13px;
}

.tab-btn.active {
    background: #1f5d42;
    color: white;
}

.action-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.btn {
    border: none;
    padding: 9px 14px;
    border-radius: 7px;
    cursor: pointer;
    font-size: 13px;
}

.btn-read {
    background: #1f5d42;
    color: white;
}

.btn-delete-all {
    background: #b02a37;
    color: white;
}

.btn-delete {
    background: #b02a37;
    color: white;
}

.btn-view {
    background: #e8f1ec;
    color: #1f5d42;
}


/* =========================================================
   NOTIFICATION LIST
========================================================= */

.notification-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.notification-card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 10px;
    padding: 17px;
    display: flex;
    justify-content: space-between;
    gap: 15px;
}

.notification-card.unread {
    border-left: 5px solid #1f5d42;
    background: #fbfdfc;
}

.notification-content {
    flex: 1;
}

.notification-title {
    font-size: 16px;
    font-weight: bold;
    color: #333;
    margin-bottom: 6px;
}

.notification-card.unread .notification-title {
    color: #1f5d42;
}

.notification-message {
    color: #666;
    font-size: 13px;
    line-height: 1.5;
}

.notification-date {
    color: #999;
    font-size: 11px;
    margin-top: 8px;
}

.notification-actions-small {
    display: flex;
    align-items: center;
    gap: 7px;
    flex-wrap: wrap;
}

.empty-state {
    background: white;
    border: 1px solid #ddd;
    border-radius: 10px;
    padding: 45px 20px;
    text-align: center;
    color: #777;
}

.empty-state .icon {
    font-size: 40px;
    margin-bottom: 10px;
}

.empty-state h3 {
    margin: 0 0 5px;
    color: #555;
}

.empty-state p {
    margin: 0;
    font-size: 13px;
}


/* =========================================================
   MODAL
========================================================= */

.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.55);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 2000;
    padding: 20px;
}

.modal-overlay.show {
    display: flex;
}

.modal {
    background: white;
    width: 100%;
    max-width: 520px;
    border-radius: 12px;
    overflow: hidden;
}

.modal-header {
    padding: 18px 20px;
    border-bottom: 1px solid #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    color: #1f5d42;
}

.close-btn {
    border: none;
    background: transparent;
    font-size: 24px;
    cursor: pointer;
    color: #777;
}

.modal-body {
    padding: 20px;
}

.modal-body h2 {
    margin: 0 0 10px;
    color: #333;
    font-size: 20px;
}

.modal-body p {
    line-height: 1.6;
    color: #555;
}

.modal-date {
    color: #999;
    font-size: 12px;
    margin-top: 15px;
}

.modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #ddd;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width:700px) {

    .main-content {
        margin-left: 200px;
        width: calc(100% - 200px);
    }

    .page-content {
        padding: 15px;
    }

    .topbar {
        padding: 12px 15px;
    }

    .topbar h3 {
        font-size: 16px;
    }

    .user-info {
        font-size: 12px;
    }

    .summary-grid {
        grid-template-columns: 1fr;
    }

    .page-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .notification-card {
        flex-direction: column;
    }

    .notification-actions-small {
        width: 100%;
    }

    .notification-actions-small .btn {
        flex: 1;
    }
}

</style>

</head>

<body>

<div class="dashboard-container">


<!-- =====================================================
     SIDEBAR
===================================================== -->

<?php require __DIR__ . '/resort_sidebar.php'; ?>


<!-- =====================================================
     MAIN CONTENT
===================================================== -->

<div class="main-content">


    <!-- TOPBAR -->

    <div class="topbar">

        <h3>Notifications</h3>

        <div class="resort-header-account"><?php require __DIR__ . '/resort_header_notifications.php'; ?>
<div class="user-info">

            <strong>
                <?php echo htmlspecialchars($user_name); ?>
            </strong>

            <span>
                <?php echo htmlspecialchars($resort_name); ?>
            </span>

        </div></div>

    </div>


    <!-- PAGE -->

    <div class="page-content">


        <!-- PAGE HEADER -->

        <div class="page-header">

            <div>

                <h2>Notifications</h2>

                <p>
                    View system updates and important activity.
                </p>

            </div>

        </div>


        <!-- NOTIFICATION SETTING -->

        <div class="notification-status">

            <div>

                <strong>Notification Status</strong>

                <div style="font-size:12px;color:#777;margin-top:4px;">
                    System notifications are
                    <?php if ($notifications_enabled): ?>
                        enabled.
                    <?php else: ?>
                        disabled.
                    <?php endif; ?>
                </div>

            </div>

            <?php if ($notifications_enabled): ?>

                <span class="status-enabled">
                    ● Enabled
                </span>

            <?php else: ?>

                <span class="status-disabled">
                    ● Disabled
                </span>

            <?php endif; ?>

        </div>


        <!-- SUMMARY -->

        <div class="summary-grid">

            <div class="summary-card">

                <small>Total Notifications</small>

                <h2>
                    <?php echo count($notifications); ?>
                </h2>

            </div>


            <div class="summary-card">

                <small>Unread Notifications</small>

                <h2>
                    <?php echo $unread_count; ?>
                </h2>

            </div>

        </div>


        <!-- ACTIONS -->

        <div class="notification-actions">

            <div class="tabs">

                <button
                    type="button"
                    class="tab-btn active"
                    id="allTab"
                    onclick="showAll()"
                >
                    All
                </button>

                <button
                    type="button"
                    class="tab-btn"
                    id="unreadTab"
                    onclick="showUnread()"
                >
                    Unread
                </button>

            </div>


            <div class="action-buttons">

                <?php if ($unread_count > 0): ?>

                    <a
                        href="notifications.php?read=all"
                        class="btn btn-read"
                        onclick="return confirm('Mark all notifications as read?');"
                    >
                        ✓ Mark All as Read
                    </a>

                <?php endif; ?>


                <?php if (count($notifications) > 0): ?>

                    <form
                        method="POST"
                        style="display:inline;"
                        onsubmit="return confirm('Delete ALL notifications? This cannot be undone.');"
                    >

                        <button
                            type="submit"
                            name="delete_all_notifications"
                            value="1"
                            class="btn btn-delete-all"
                        >
                            🗑 Delete All
                        </button>

                    </form>

                <?php endif; ?>

            </div>

        </div>


        <!-- =================================================
             NOTIFICATION LIST
        ================================================= -->

        <div class="notification-list" id="notificationList">


            <?php if (empty($notifications)): ?>


                <div class="empty-state">

                    <div class="icon">
                        🔔
                    </div>

                    <h3>No Notifications</h3>

                    <p>
                        You currently have no notifications.
                    </p>

                </div>


            <?php else: ?>


                <?php foreach ($notifications as $notification): ?>

                    <?php

                    $is_unread = ((int)$notification["is_read"] === 0);

                    ?>

                    <div
                        class="notification-card <?php echo $is_unread ? 'unread' : ''; ?>"
                        data-unread="<?php echo $is_unread ? '1' : '0'; ?>"
                    >


                        <div class="notification-content">

                            <div class="notification-title">

                                <?php echo htmlspecialchars($notification["title"]); ?>

                            </div>


                            <div class="notification-message">

                                <?php

                                $message = $notification["message"];

                                if (strlen($message) > 130) {
                                    echo htmlspecialchars(substr($message, 0, 130)) . "...";
                                } else {
                                    echo htmlspecialchars($message);
                                }

                                ?>

                            </div>


                            <div class="notification-date">

                                <?php

                                echo date(
                                    "M d, Y h:i A",
                                    strtotime($notification["created_at"])
                                );

                                ?>

                            </div>

                        </div>


                        <div class="notification-actions-small">


                            <!-- VIEW
                                 Viewing automatically marks it as read -->

                            <a
                                href="notifications.php?view_id=<?php echo $notification["id"]; ?>"
                                class="btn btn-view"
                            >
                                👁 View
                            </a>


                            <!-- DELETE -->

                            <form
                                method="POST"
                                style="display:inline;"
                                onsubmit="return confirm('Delete this notification?');"
                            >

                                <button
                                    type="submit"
                                    name="delete_notification"
                                    value="<?php echo $notification["id"]; ?>"
                                    class="btn btn-delete"
                                >
                                    🗑 Delete
                                </button>

                            </form>


                        </div>

                    </div>

                <?php endforeach; ?>


            <?php endif; ?>


        </div>


    </div>

</div>

</div>


<!-- =====================================================
     VIEW NOTIFICATION MODAL
===================================================== -->

<?php if ($view_notification): ?>

<div
    class="modal-overlay show"
    id="notificationModal"
>


    <div class="modal">


        <div class="modal-header">

            <h3>
                Notification
            </h3>

            <button
                type="button"
                class="close-btn"
                onclick="closeNotification()"
            >
                ×
            </button>

        </div>


        <div class="modal-body">

            <h2>
                <?php echo htmlspecialchars($view_notification["title"]); ?>
            </h2>


            <p>

                <?php echo nl2br(
                    htmlspecialchars($view_notification["message"])
                ); ?>

            </p>


            <div class="modal-date">

                <?php

                echo date(
                    "F d, Y h:i A",
                    strtotime($view_notification["created_at"])
                );

                ?>

            </div>

        </div>


        <div class="modal-footer">


            <form
                method="POST"
                style="display:inline;"
                onsubmit="return confirm('Delete this notification?');"
            >

                <button
                    type="submit"
                    name="delete_notification"
                    value="<?php echo $view_notification["id"]; ?>"
                    class="btn btn-delete"
                >
                    🗑 Delete
                </button>

            </form>


            <button
                type="button"
                class="btn btn-read"
                onclick="closeNotification()"
            >
                Close
            </button>


        </div>


    </div>

</div>

<?php endif; ?>


<script>

/* =========================================================
   SHOW ALL
========================================================= */

function showAll() {

    const cards = document.querySelectorAll(".notification-card");

    cards.forEach(function(card) {
        card.style.display = "flex";
    });

    document.getElementById("allTab").classList.add("active");

    document.getElementById("unreadTab").classList.remove("active");
}


/* =========================================================
   SHOW UNREAD
========================================================= */

function showUnread() {

    const cards = document.querySelectorAll(".notification-card");

    cards.forEach(function(card) {

        if (card.dataset.unread === "1") {

            card.style.display = "flex";

        } else {

            card.style.display = "none";

        }

    });

    document.getElementById("unreadTab").classList.add("active");

    document.getElementById("allTab").classList.remove("active");
}


/* =========================================================
   CLOSE NOTIFICATION
========================================================= */

function closeNotification() {

    const modal = document.getElementById("notificationModal");

    if (modal) {
        modal.style.display = "none";
    }

}


/* =========================================================
   CLOSE MODAL WHEN CLICKING OUTSIDE
========================================================= */

const modal = document.getElementById("notificationModal");

if (modal) {

    modal.addEventListener("click", function(event) {

        if (event.target === modal) {
            closeNotification();
        }

    });

}


/* =========================================================
   ESC KEY CLOSE
========================================================= */

document.addEventListener("keydown", function(event) {

    if (event.key === "Escape") {
        closeNotification();
    }

});

</script>

</body>
</html>