<?php
session_start();

require_once "database.php";

/* =========================================================
   AUTH CHECK
========================================================= */
if (!isset($_SESSION["user_id"])) {
    header("Location: resort_login.php");
    exit;
}

$user_id = (int) $_SESSION["user_id"];

/* =========================================================
   RESORT INFORMATION
========================================================= */
$resort_id = 0;
$resort_name = $_SESSION["resort_name"] ?? "ALAPASCO ECOKUBO";
$full_name = $_SESSION["full_name"] ?? "Resort Administrator";

$stmt = $conn->prepare("
    SELECT id, resort_name, full_name
    FROM users
    WHERE id = ?
    LIMIT 1
");

if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $resort_id = (int) $row["id"];

        if (!empty($row["resort_name"])) {
            $resort_name = $row["resort_name"];
        }

        if (!empty($row["full_name"])) {
            $full_name = $row["full_name"];
        }
    }

    $stmt->close();
}

/*
 * If users.id is not the same as resorts.id in your database,
 * use the existing session resort_id when available.
 */
if (!empty($_SESSION["resort_id"])) {
    $resort_id = (int) $_SESSION["resort_id"];
}

/* =========================================================
   HELPER
========================================================= */
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function money($value)
{
    return "₱" . number_format((float) $value, 2);
}

/* =========================================================
   SUMMARY
========================================================= */

$total_collected = 0;
$pending_balance = 0;
$today_payments = 0;
$paid_transactions = 0;

/* ---------------------------------------------------------
   TOTAL COLLECTED
   Every payment row represents one actual transaction.
   Therefore, simply sum payments.amount.
--------------------------------------------------------- */
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS total_collected
    FROM payments
    WHERE resort_id = ?
");

if ($stmt) {
    $stmt->bind_param("i", $resort_id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $total_collected = (float) $row["total_collected"];
    }

    $stmt->close();
}

/* ---------------------------------------------------------
   PENDING BALANCE FROM RESERVATIONS
--------------------------------------------------------- */

$pending_reservations = 0;

$stmt = $conn->prepare("
    SELECT COALESCE(
        SUM(
            CASE
                WHEN total_amount > amount_paid
                THEN total_amount - amount_paid
                ELSE 0
            END
        ),
        0
    ) AS pending_reservations
    FROM reservations
    WHERE resort_id = ?
      AND reservation_status NOT IN ('Cancelled', 'Completed')
");

if ($stmt) {
    $stmt->bind_param("i", $resort_id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $pending_reservations = (float) $row["pending_reservations"];
    }

    $stmt->close();
}

/* ---------------------------------------------------------
   PENDING BALANCE FROM ACTIVE CHECK-INS
--------------------------------------------------------- */

$pending_checkins = 0;

$stmt = $conn->prepare("
    SELECT COALESCE(
        SUM(
            CASE
                WHEN total_amount > amount_paid
                THEN total_amount - amount_paid
                ELSE 0
            END
        ),
        0
    ) AS pending_checkins
    FROM checkins
    WHERE resort_id = ?
      AND status = 'Active'
      AND visit_type = 'Accommodation'
");

if ($stmt) {
    $stmt->bind_param("i", $resort_id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $pending_checkins = (float) $row["pending_checkins"];
    }

    $stmt->close();
}

/*
 * Pending balance shown on the payment page.
 */
$pending_balance = $pending_reservations + $pending_checkins;

/* ---------------------------------------------------------
   TODAY'S PAYMENTS
--------------------------------------------------------- */

$stmt = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) AS today_payments
    FROM payments
    WHERE resort_id = ?
      AND DATE(payment_date) = CURDATE()
");

if ($stmt) {
    $stmt->bind_param("i", $resort_id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $today_payments = (float) $row["today_payments"];
    }

    $stmt->close();
}

/* ---------------------------------------------------------
   PAID TRANSACTIONS
--------------------------------------------------------- */

$stmt = $conn->prepare("
    SELECT COUNT(*) AS paid_transactions
    FROM payments
    WHERE resort_id = ?
      AND payment_status = 'Paid'
");

if ($stmt) {
    $stmt->bind_param("i", $resort_id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $paid_transactions = (int) $row["paid_transactions"];
    }

    $stmt->close();
}

/* =========================================================
   PAYMENT SOURCE COUNTS
========================================================= */

$reservation_count = 0;
$checkin_count = 0;
$checkout_count = 0;

/*
 * Reservation payment:
 * payment has reservation_id and is not a check-in/check-out.
 */
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM payments
    WHERE resort_id = ?
      AND reservation_id IS NOT NULL
      AND checkin_id IS NULL
      AND (notes IS NULL OR notes NOT LIKE 'Check-Out%')
");

if ($stmt) {
    $stmt->bind_param("i", $resort_id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $reservation_count = (int) $row["total"];
    }

    $stmt->close();
}

/*
 * Check-in payment:
 * checkin_id exists and it is not marked as Check-Out.
 */
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM payments
    WHERE resort_id = ?
      AND checkin_id IS NOT NULL
      AND (notes IS NULL OR notes NOT LIKE 'Check-Out%')
");

if ($stmt) {
    $stmt->bind_param("i", $resort_id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $checkin_count = (int) $row["total"];
    }

    $stmt->close();
}

/*
 * Checkout payment.
 */
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM payments
    WHERE resort_id = ?
      AND notes LIKE 'Check-Out%'
");

if ($stmt) {
    $stmt->bind_param("i", $resort_id);
    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $checkout_count = (int) $row["total"];
    }

    $stmt->close();
}

/* =========================================================
   PAYMENT HISTORY
========================================================= */

$payments = [];

$stmt = $conn->prepare("
    SELECT
        p.id,
        p.amount,
        p.payment_status,
        p.payment_method,
        p.reference_number,
        p.payment_date,
        p.notes,
        p.reservation_id,
        p.checkin_id,
        g.full_name,

        CASE
            WHEN p.notes LIKE 'Check-Out%' THEN 'Check-Out'
            WHEN p.checkin_id IS NOT NULL THEN 'Check-In'
            WHEN p.reservation_id IS NOT NULL THEN 'Reservation'
            ELSE 'Other'
        END AS payment_source

    FROM payments p

    LEFT JOIN guests g
        ON p.guest_id = g.id

    WHERE p.resort_id = ?

    ORDER BY
        p.payment_date DESC,
        p.id DESC
");

if ($stmt) {
    $stmt->bind_param("i", $resort_id);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }

    $stmt->close();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Payments | <?= e($resort_name) ?></title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f7f5;
            color: #26352e;
        }

        /* =================================================
           MAIN
        ================================================= */

        .main {
            margin-left: 250px;
            min-height: 100vh;
        }

        .topbar {
            height: 70px;
            background: white;
            border-bottom: 1px solid #e5e9e6;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
        }

        .topbar h1 {
            font-size: 22px;
            color: #1f5d42;
        }

        .profile {
            text-align: right;
        }

        .profile strong {
            display: block;
            font-size: 14px;
        }

        .profile span {
            font-size: 12px;
            color: #7b867f;
        }

        .content {
            padding: 30px;
        }

        /* =================================================
           SUMMARY CARDS
        ================================================= */

        .cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 25px;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #e4e9e5;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
        }

        .card-title {
            font-size: 13px;
            color: #718078;
            margin-bottom: 10px;
        }

        .card-value {
            font-size: 25px;
            font-weight: bold;
            color: #1f5d42;
        }

        .card-value.pending {
            color: #b26a00;
        }

        .card-value.today {
            color: #26734d;
        }

        .card-value.transactions {
            color: #3f6655;
        }

        /* =================================================
           CONTENT CARD
        ================================================= */

        .panel {
            background: white;
            border: 1px solid #e4e9e5;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
            overflow: hidden;
        }

        .panel-header {
            padding: 20px 22px;
            border-bottom: 1px solid #e8ece9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .panel-header h2 {
            color: #1f5d42;
            font-size: 18px;
        }

        .panel-header p {
            color: #7b867f;
            font-size: 12px;
            margin-top: 4px;
        }

        /* =================================================
           TRANSACTION SUMMARY
        ================================================= */

        .transaction-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            padding: 20px 22px;
            background: #f8faf9;
            border-bottom: 1px solid #e8ece9;
        }

        .transaction-box {
            background: white;
            border: 1px solid #e2e8e4;
            border-radius: 8px;
            padding: 14px;
        }

        .transaction-box span {
            display: block;
            font-size: 12px;
            color: #7b867f;
            margin-bottom: 5px;
        }

        .transaction-box strong {
            font-size: 20px;
            color: #1f5d42;
        }

        /* =================================================
           TABLE
        ================================================= */

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 950px;
        }

        th {
            background: #f7f9f8;
            color: #65736b;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .3px;
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #e1e6e2;
        }

        td {
            padding: 15px 16px;
            font-size: 13px;
            border-bottom: 1px solid #edf0ee;
            vertical-align: middle;
        }

        tbody tr:hover {
            background: #fafcfb;
        }

        .amount {
            font-weight: bold;
            color: #1f5d42;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }

        .badge.paid {
            background: #e5f5eb;
            color: #287345;
        }

        .badge.partial {
            background: #fff3d9;
            color: #a36700;
        }

        .badge.pending {
            background: #f1f3f2;
            color: #69746e;
        }

        .source {
            font-weight: 600;
            color: #3f6655;
        }

        .method {
            color: #58655e;
        }

        .reference {
            color: #718078;
            font-size: 12px;
        }

        .notes {
            color: #718078;
            font-size: 12px;
            max-width: 220px;
        }

        .empty {
            text-align: center;
            padding: 50px 20px;
            color: #7b867f;
        }

        /* =================================================
           MOBILE
        ================================================= */

        .menu-toggle {
            display: none;
            border: none;
            background: #1f5d42;
            color: white;
            padding: 8px 11px;
            border-radius: 6px;
            cursor: pointer;
        }

        @media (max-width: 1100px) {
            .cards {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {

            .main {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }

            .topbar {
                padding: 0 18px;
            }

            .topbar h1 {
                font-size: 18px;
            }

            .content {
                padding: 18px;
            }

            .cards {
                grid-template-columns: 1fr;
            }

            .transaction-summary {
                grid-template-columns: 1fr;
            }

            .profile {
                display: none;
            }
        }
    </style>
</head>

<body>

<!-- =====================================================
     SIDEBAR
===================================================== -->

<?php require __DIR__ . '/resort_sidebar.php'; ?>

<!-- =====================================================
     MAIN
===================================================== -->

<main class="main main-content">

    <header class="topbar">

        <div style="display:flex;align-items:center;gap:12px;">
            <button class="menu-toggle" onclick="toggleSidebar()">☰</button>

            <h1>Payments</h1>
        </div>

        <div class="resort-header-account"><?php require __DIR__ . '/resort_header_notifications.php'; ?>
<div class="profile">
            <strong><?= e($full_name) ?></strong>
            <span><?= e($resort_name) ?></span>
        </div></div>

    </header>

    <section class="content">

        <!-- =================================================
             SUMMARY CARDS
        ================================================= -->

        <div class="cards">

            <div class="card">
                <div class="card-title">
                    Total Collected
                </div>

                <div class="card-value">
                    <?= money($total_collected) ?>
                </div>
            </div>

            <div class="card">
                <div class="card-title">
                    Pending Balance
                </div>

                <div class="card-value pending">
                    <?= money($pending_balance) ?>
                </div>
            </div>

            <div class="card">
                <div class="card-title">
                    Today's Payments
                </div>

                <div class="card-value today">
                    <?= money($today_payments) ?>
                </div>
            </div>

            <div class="card">
                <div class="card-title">
                    Paid Transactions
                </div>

                <div class="card-value transactions">
                    <?= number_format($paid_transactions) ?>
                </div>
            </div>

        </div>

        <!-- =================================================
             PAYMENT HISTORY
        ================================================= -->

        <div class="panel">

            <div class="panel-header">

                <div>
                    <h2>Payment History</h2>

                    <p>
                        Payments are automatically recorded from reservations,
                        check-ins, and check-outs.
                    </p>
                </div>

            </div>

            <!-- TRANSACTION SUMMARY -->

            <div class="transaction-summary">

                <div class="transaction-box">
                    <span>Reservation Payments</span>
                    <strong><?= number_format($reservation_count) ?></strong>
                </div>

                <div class="transaction-box">
                    <span>Check-In Payments</span>
                    <strong><?= number_format($checkin_count) ?></strong>
                </div>

                <div class="transaction-box">
                    <span>Check-Out Payments</span>
                    <strong><?= number_format($checkout_count) ?></strong>
                </div>

            </div>

            <!-- TABLE -->

            <?php if (empty($payments)): ?>

                <div class="empty">
                    No payment transactions have been recorded yet.
                </div>

            <?php else: ?>

                <div class="table-wrap">

                    <table>

                        <thead>
                            <tr>
                                <th>Guest</th>
                                <th>Amount</th>
                                <th>Source</th>
                                <th>Status</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th>Date</th>
                                <th>Notes</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($payments as $payment): ?>

                            <?php
                                $status = $payment["payment_status"] ?? "Pending";

                                $statusClass = "pending";

                                if (strcasecmp($status, "Paid") === 0) {
                                    $statusClass = "paid";
                                } elseif (
                                    strcasecmp($status, "Partially Paid") === 0 ||
                                    strcasecmp($status, "Partial") === 0
                                ) {
                                    $statusClass = "partial";
                                }

                                $guestName = $payment["full_name"]
                                    ?: "Walk-in Guest";

                                $source = $payment["payment_source"]
                                    ?: "Other";

                                $method = $payment["payment_method"]
                                    ?: "—";

                                $reference = $payment["reference_number"]
                                    ?: "—";

                                $notes = $payment["notes"]
                                    ?: "—";

                                $paymentDate = "—";

                                if (!empty($payment["payment_date"])) {
                                    $timestamp = strtotime($payment["payment_date"]);

                                    if ($timestamp !== false) {
                                        $paymentDate = date(
                                            "M d, Y h:i A",
                                            $timestamp
                                        );
                                    }
                                }
                            ?>

                            <tr>

                                <td>
                                    <?= e($guestName) ?>
                                </td>

                                <td class="amount">
                                    <?= money($payment["amount"]) ?>
                                </td>

                                <td class="source">
                                    <?= e($source) ?>
                                </td>

                                <td>
                                    <span class="badge <?= e($statusClass) ?>">
                                        <?= e($status) ?>
                                    </span>
                                </td>

                                <td class="method">
                                    <?= e($method) ?>
                                </td>

                                <td class="reference">
                                    <?= e($reference) ?>
                                </td>

                                <td>
                                    <?= e($paymentDate) ?>
                                </td>

                                <td class="notes">
                                    <?= e($notes) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>

    </section>

</main>

<script>
function toggleSidebar() {
    resortToggleSidebar();
}
</script>

</body>
</html>