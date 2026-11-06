<?php
if (empty($_SESSION['admin_id'])) {
    return;
}

$headerEscape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$headerPending = null;
$headerActivity = [];
$headerActivityAvailable = false;

try {
    $headerCount = $conn->query("SELECT COUNT(*) FROM resorts WHERE status = 'Pending'");
    if ($headerCount) {
        $headerPending = (int) $headerCount->fetch_row()[0];
        $headerCount->free();
    }
} catch (Throwable $exception) {
    error_log('Admin header approval count: ' . $exception->getMessage());
}

try {
    $headerResult = $conn->query(
        'SELECT n.resort_id, n.title, n.message, n.created_at, r.resort_name
         FROM notifications n
         INNER JOIN resorts r ON r.id = n.resort_id
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT 8'
    );
    if ($headerResult) {
        $headerActivity = $headerResult->fetch_all(MYSQLI_ASSOC);
        $headerResult->free();
        $headerActivityAvailable = true;
    }
} catch (Throwable $exception) {
    error_log('Admin header resort activity: ' . $exception->getMessage());
}
?>

<style>
.admin-shared-header { z-index: 500; gap: 12px; }
.admin-shared-header-with-back { position: relative; }
.admin-header-account { display: flex; align-items: center; justify-content: flex-end; gap: 12px; min-width: 0; }
.admin-header-notifications { position: static; flex: 0 0 auto; }
.admin-header-notifications summary { display: grid; place-items: center; position: relative; width: 40px; height: 40px; border: 1px solid #dce8e1; border-radius: 50%; color: #1f5d42; background: #f5f9f6; cursor: pointer; list-style: none; }
.admin-header-notifications summary::-webkit-details-marker { display: none; }
.admin-header-notifications summary:hover { background: #e5f2eb; }
.admin-header-notifications summary:focus-visible { outline: 2px solid #1f5d42; outline-offset: 3px; }
.admin-header-badge { position: absolute; top: -5px; right: -5px; min-width: 18px; padding: 2px 4px; text-align: center; border-radius: 12px; background: #b42318; color: white; font: bold 11px Arial, sans-serif; }
.admin-header-panel { position: absolute; top: calc(100% + 6px); right: 12px; width: min(360px, calc(100% - 24px)); max-height: min(65vh, 440px); overflow-y: auto; overscroll-behavior: contain; padding: 14px; background: white; color: #333; border: 1px solid #dce8e1; border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.13); overflow-wrap: anywhere; }
.admin-header-panel h2 { color: #1f5d42; margin: 0 0 10px; font-size: 17px; }
.admin-header-panel p { margin: 6px 0; font-size: 13px; }
.admin-header-approval { display: block; background: #eff7f1; color: #1f5d42; border-radius: 6px; padding: 9px; margin-bottom: 10px; font-weight: bold; font-size: 13px; }
.admin-header-event { display: block; padding: 10px 8px; border-top: 1px solid #e7eee9; color: inherit; text-decoration: none; }
.admin-header-event:hover { background: #eff7f1; }
.admin-header-event:focus-visible { outline: 2px solid #1f5d42; outline-offset: -2px; }
.admin-header-event strong { display: block; font-size: 13px; }
.admin-header-event small { color: #596b60; font-size: 12px; }
</style>

<div class="topbar admin-shared-header<?= !empty($adminHeaderBackUrl) ? ' admin-shared-header-with-back' : '' ?>">
    <?php if (!empty($adminHeaderBackUrl)): ?>
        <div class="topbar-left">
            <a class="back-button" href="<?= $headerEscape($adminHeaderBackUrl) ?>">← Back</a>
            <h1><?= $headerEscape($adminHeaderTitle) ?></h1>
        </div>
    <?php else: ?>
        <h3><?= $headerEscape($adminHeaderTitle) ?></h3>
    <?php endif; ?>

    <div class="admin-header-account">
        <details class="admin-header-notifications" id="admin-header-notifications">
            <summary aria-label="<?= $headerEscape($headerPending > 0 ? 'Notifications, ' . $headerPending . ' pending resort approvals' : 'Notifications') ?>" title="Notifications">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
                <?php if ($headerPending > 0): ?>
                    <span class="admin-header-badge" aria-hidden="true"><?= $headerPending > 99 ? '99+' : $headerPending ?></span>
                <?php endif; ?>
            </summary>

            <div class="admin-header-panel">
                <h2>Notifications</h2>
                <a class="admin-header-approval" href="admin_resorts.php">
                    <?php if ($headerPending === null): ?>
                        Open resort approvals
                    <?php elseif ($headerPending === 0): ?>
                        No pending resort approvals
                    <?php else: ?>
                        <?= $headerPending ?> pending resort <?= $headerPending === 1 ? 'approval' : 'approvals' ?>
                    <?php endif; ?>
                </a>

                <p><strong>Recent resort activity</strong></p>
                <?php if (!$headerActivityAvailable): ?>
                    <p>Resort activity is unavailable.</p>
                <?php elseif (!$headerActivity): ?>
                    <p>No resort activity yet.</p>
                <?php else: ?>
                    <?php foreach ($headerActivity as $headerEvent): ?>
                        <a class="admin-header-event" href="admin_resort_details.php?id=<?= (int) $headerEvent['resort_id'] ?>">
                            <strong><?= $headerEscape($headerEvent['title']) ?></strong>
                            <small><?= $headerEscape($headerEvent['resort_name']) ?> · <?= $headerEscape($headerEvent['created_at']) ?></small>
                            <p><?= $headerEscape($headerEvent['message']) ?></p>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </details>

        <?php if (!empty($adminHeaderBackUrl)): ?>
            <div class="admin-name">👤 <?= $headerEscape($adminHeaderName) ?></div>
        <?php else: ?>
            <div class="user-info">
                <strong><?= $headerEscape($adminHeaderName) ?></strong>
                <span><?= $headerEscape($adminHeaderRole) ?></span>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(() => {
    const notifications = document.getElementById('admin-header-notifications');
    document.addEventListener('click', (event) => {
        if (notifications.open && !notifications.contains(event.target)) {
            notifications.open = false;
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && notifications.open) {
            notifications.open = false;
            notifications.querySelector('summary').focus();
        }
    });
})();
</script>
