<?php
$resort_header_unread = 0;
try {
    $resort_header_user = (int) ($_SESSION["user_id"] ?? 0);
    $resort_header_stmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM notifications n
         INNER JOIN resorts r ON r.id = n.resort_id
         WHERE r.user_id = ? AND n.is_read = 0"
    );
    if ($resort_header_stmt) {
        $resort_header_stmt->bind_param("i", $resort_header_user);
        $resort_header_stmt->execute();
        $resort_header_row = $resort_header_stmt->get_result()->fetch_assoc();
        $resort_header_unread = (int) ($resort_header_row["total"] ?? 0);
        $resort_header_stmt->close();
    }
} catch (Throwable $e) {
    error_log("Notification bell: " . $e->getMessage());
}
?>
<a class="resort-notification-bell" href="notifications.php" aria-label="Notifications<?php echo $resort_header_unread > 0 ? ", " . $resort_header_unread . " unread" : ""; ?>" title="Notifications">
    <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
    <?php if ($resort_header_unread > 0): ?><span class="resort-notification-badge"><?php echo $resort_header_unread > 99 ? "99+" : $resort_header_unread; ?></span><?php endif; ?>
</a>
