<?php
session_start();
require_once "database.php";

/* =====================================================
   ALAPASCO ECOKUBO - CHECKOUT
   Complete corrected version
===================================================== */

if (!isset($_SESSION["user_id"])) {
    header("Location: resort_login.php");
    exit;
}

$user_id = (int) $_SESSION["user_id"];
$full_name = $_SESSION["full_name"] ?? "User";
$resort_name = $_SESSION["resort_name"] ?? "Your Resort";

$message = "";
$message_type = "";
$active_checkins = [];

/* =====================================================
   DATABASE CONNECTION
===================================================== */

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection is unavailable.");
}

$conn->set_charset("utf8mb4");

/* =====================================================
   RESOLVE RESORT FROM AUTHENTICATED USER

   Do not trust a stale resort_id stored in session.
===================================================== */

$stmt = $conn->prepare(
    "SELECT id, resort_name
     FROM resorts
     WHERE user_id = ?
     LIMIT 1"
);

if (!$stmt) {
    die("Unable to verify resort account.");
}

$stmt->bind_param("i", $user_id);
$stmt->execute();

$resort_result = $stmt->get_result();
$resort = $resort_result->fetch_assoc();
$stmt->close();

if (!$resort) {
    die("Resort account was not found. Please log in again.");
}

$resort_id = (int) $resort["id"];
$resort_name = $resort["resort_name"] ?: $resort_name;
$_SESSION["resort_id"] = $resort_id;

/* =====================================================
   NOTIFICATION SETTINGS
===================================================== */

$notifications_enabled = 1;

$stmt = $conn->prepare(
    "SELECT notifications_enabled
     FROM user_settings
     WHERE user_id = ?
     LIMIT 1"
);

if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $settings = $result->fetch_assoc();

    if ($settings) {
        $notifications_enabled =
            (int) $settings["notifications_enabled"];
    }

    $stmt->close();
}

/* =====================================================
   HELPER FUNCTIONS
===================================================== */

function checkoutMoney($value)
{
    return round((float) $value, 2);
}

function checkoutBalance($total, $paid)
{
    return max(
        0,
        checkoutMoney(
            checkoutMoney($total) -
            checkoutMoney($paid)
        )
    );
}

function checkoutEscape($value)
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

function checkoutNotification(
    $conn,
    $resort_id,
    $type,
    $title,
    $message,
    $enabled
) {
    if (!$enabled) {
        return true;
    }

    try {
        $stmt = $conn->prepare(
            "INSERT INTO notifications
             (
                 resort_id,
                 notification_type,
                 title,
                 message,
                 is_read
             )
             VALUES (?, ?, ?, ?, 0)"
        );

        if (!$stmt) {
            throw new Exception(
                "Unable to prepare notification."
            );
        }

        $stmt->bind_param(
            "isss",
            $resort_id,
            $type,
            $title,
            $message
        );

        $success = $stmt->execute();
        $stmt->close();

        return $success;

    } catch (Throwable $e) {
        error_log(
            "Checkout notification error: " .
            $e->getMessage()
        );

        return false;
    }
}

/* =====================================================
   CSRF TOKEN
===================================================== */

if (empty($_SESSION["checkout_csrf"])) {
    $_SESSION["checkout_csrf"] =
        bin2hex(random_bytes(32));
}

/* =====================================================
   PROCESS CHECKOUT
===================================================== */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["checkout"])
) {
    $posted_token =
        (string) ($_POST["csrf_token"] ?? "");

    $checkin_id =
        filter_var(
            $_POST["checkin_id"] ?? null,
            FILTER_VALIDATE_INT
        );

    $payment_input =
        trim((string) ($_POST["final_payment"] ?? ""));

    $payment_method =
        trim((string) ($_POST["payment_method"] ?? "Cash"));

    $reference_number =
        trim((string) ($_POST["reference_number"] ?? ""));

    $valid_methods = [
        "Cash",
        "GCash",
        "Bank Transfer",
        "Other"
    ];

    $payment_valid =
        preg_match(
            '/^\d+(?:\.\d{1,2})?$/',
            $payment_input
        ) === 1;

    if (
        !hash_equals(
            $_SESSION["checkout_csrf"],
            $posted_token
        )
    ) {
        $message =
            "Security verification failed. Refresh the page and try again.";

        $message_type = "error";

    } elseif (!$checkin_id || $checkin_id <= 0) {
        $message = "Please select a valid active guest.";
        $message_type = "error";

    } elseif (!$payment_valid) {
        $message =
            "Enter a valid payment amount with up to two decimal places.";

        $message_type = "error";

    } elseif (
        !in_array($payment_method, $valid_methods, true)
    ) {
        $message = "Invalid payment method.";
        $message_type = "error";

    } elseif (strlen($reference_number) > 100) {
        $message =
            "Reference number must not exceed 100 characters.";

        $message_type = "error";

    } else {
        $final_payment = checkoutMoney($payment_input);
        $transaction_started = false;

        try {
            /* =========================================
               START TRANSACTION
            ========================================= */

            if (!$conn->begin_transaction()) {
                throw new Exception(
                    "Unable to start checkout transaction."
                );
            }

            $transaction_started = true;

            /* =========================================
               LOCK ACTIVE CHECK-IN

               Prevent two requests from processing
               the same check-in simultaneously.
            ========================================= */

            $stmt = $conn->prepare(
                "SELECT
                    c.id,
                    c.resort_id,
                    c.guest_id,
                    c.accommodation_id,
                    c.visit_type,
                    c.number_of_guests,
                    c.total_amount,
                    c.amount_paid,
                    c.remaining_balance,
                    c.payment_status,
                    c.status,
                    g.full_name,
                    a.accommodation_code
                 FROM checkins c
                 LEFT JOIN guests g
                    ON g.id = c.guest_id
                 LEFT JOIN accommodations a
                    ON a.id = c.accommodation_id
                 WHERE c.id = ?
                   AND c.resort_id = ?
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$stmt) {
                throw new Exception(
                    "Unable to prepare check-in verification."
                );
            }

            $stmt->bind_param(
                "ii",
                $checkin_id,
                $resort_id
            );

            $stmt->execute();

            $result = $stmt->get_result();
            $checkin = $result->fetch_assoc();
            $stmt->close();

            if (!$checkin) {
                throw new Exception(
                    "The selected check-in was not found."
                );
            }

            if ($checkin["status"] !== "Active") {
                throw new Exception(
                    "This guest is no longer active or has already checked out."
                );
            }

            $guest_id = (int) $checkin["guest_id"];
            $accommodation_id =
                (int) $checkin["accommodation_id"];

            $visit_type = $checkin["visit_type"];
            $guest_name =
                $checkin["full_name"] ?: "Guest";

            $total_amount =
                checkoutMoney($checkin["total_amount"]);

            $already_paid =
                checkoutMoney($checkin["amount_paid"]);

            $balance =
                checkoutBalance(
                    $total_amount,
                    $already_paid
                );

            /* =========================================
               PREVENT DUPLICATE CHECKOUT
            ========================================= */

            $stmt = $conn->prepare(
                "SELECT id
                 FROM checkouts
                 WHERE checkin_id = ?
                 LIMIT 1"
            );

            if (!$stmt) {
                throw new Exception(
                    "Unable to verify previous checkout."
                );
            }

            $stmt->bind_param("i", $checkin_id);
            $stmt->execute();

            $duplicate = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($duplicate) {
                throw new Exception(
                    "This guest has already been checked out."
                );
            }

            /* =========================================
               DAY VISITOR RULES
            ========================================= */

            if ($visit_type === "Day Visitor") {
                if ($final_payment != 0) {
                    throw new Exception(
                        "Day Visitor checkout does not accept an accommodation payment."
                    );
                }

                $new_total_paid = $already_paid;
                $new_balance = $balance;

                $checkout_accommodation_id = null;

            } elseif ($visit_type === "Accommodation") {
                /* =====================================
                   ACCOMMODATION RULES
                ===================================== */

                if ($accommodation_id <= 0) {
                    throw new Exception(
                        "No accommodation is assigned to this check-in."
                    );
                }

                if ($final_payment > $balance + 0.009) {
                    throw new Exception(
                        "Final payment exceeds the remaining balance of ₱" .
                        number_format($balance, 2)
                    );
                }

                $new_total_paid =
                    checkoutMoney(
                        $already_paid + $final_payment
                    );

                $new_balance =
                    checkoutBalance(
                        $total_amount,
                        $new_total_paid
                    );

                if ($new_balance > 0.009) {
                    throw new Exception(
                        "Full payment is required before checkout. Remaining balance: ₱" .
                        number_format($new_balance, 2)
                    );
                }

                $new_balance = 0.00;
                $checkout_accommodation_id =
                    $accommodation_id;

            } else {
                throw new Exception(
                    "Unsupported visitor type."
                );
            }

            /* =========================================
               INSERT CHECKOUT RECORD

               Uses actual database columns:
               resort_id (after migration)
               check_out_time (existing)
            ========================================= */

            $stmt = $conn->prepare(
                "INSERT INTO checkouts
                 (
                     resort_id,
                     checkin_id,
                     guest_id,
                     accommodation_id,
                     check_out_time,
                     total_amount,
                     amount_paid,
                     remaining_balance
                 )
                 VALUES
                 (
                     ?, ?, ?, ?, NOW(), ?, ?, ?
                 )"
            );

            if (!$stmt) {
                throw new Exception(
                    "Unable to prepare checkout record."
                );
            }

            $stmt->bind_param(
                "iiiiddd",
                $resort_id,
                $checkin_id,
                $guest_id,
                $checkout_accommodation_id,
                $total_amount,
                $new_total_paid,
                $new_balance
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    "Unable to save checkout record."
                );
            }

            $stmt->close();

            /* =========================================
               RECORD ONLY NEW PAYMENT

               Existing reservation and check-in
               payments are not inserted again.
            ========================================= */

            if ($final_payment > 0) {
                $payment_status_db = "Paid";
                $reference_db =
                    $reference_number !== ""
                    ? $reference_number
                    : null;

                $proof_of_payment = null;
                $notes = "Check-Out payment";

                $stmt = $conn->prepare(
                    "INSERT INTO payments
                     (
                         resort_id,
                         guest_id,
                         reservation_id,
                         checkin_id,
                         amount,
                         payment_status,
                         payment_method,
                         reference_number,
                         proof_of_payment,
                         notes
                     )
                     VALUES
                     (
                         ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?
                     )"
                );

                if (!$stmt) {
                    throw new Exception(
                        "Unable to prepare checkout payment."
                    );
                }

                $stmt->bind_param(
                    "iiidsssss",
                    $resort_id,
                    $guest_id,
                    $checkin_id,
                    $final_payment,
                    $payment_status_db,
                    $payment_method,
                    $reference_db,
                    $proof_of_payment,
                    $notes
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        "Unable to record final payment."
                    );
                }

                $stmt->close();
            }

            /* =========================================
               COMPLETE CHECK-IN

               Preserve cumulative payments.
            ========================================= */

            $stmt = $conn->prepare(
                "UPDATE checkins
                 SET
                     amount_paid = ?,
                     remaining_balance = ?,
                     payment_status = ?,
                     status = 'Completed'
                 WHERE id = ?
                   AND resort_id = ?
                   AND status = 'Active'"
            );

            if (!$stmt) {
                throw new Exception(
                    "Unable to prepare check-in update."
                );
            }

            $updated_payment_status =
                $new_balance <= 0.009
                ? "Paid"
                : ($new_total_paid > 0
                    ? "Partially Paid"
                    : "Pending");

            $stmt->bind_param(
                "ddsii",
                $new_total_paid,
                $new_balance,
                $updated_payment_status,
                $checkin_id,
                $resort_id
            );

            if (!$stmt->execute()) {
                throw new Exception(
                    "Unable to complete check-in."
                );
            }

            if ($stmt->affected_rows !== 1) {
                throw new Exception(
                    "Check-in status changed during checkout."
                );
            }

            $stmt->close();

            /* =========================================
               RELEASE ACCOMMODATION

               Only accommodation visitors have rooms.
            ========================================= */

            if ($visit_type === "Accommodation") {
                $stmt = $conn->prepare(
                    "UPDATE accommodations
                     SET status = 'Available'
                     WHERE id = ?
                       AND resort_id = ?"
                );

                if (!$stmt) {
                    throw new Exception(
                        "Unable to prepare accommodation update."
                    );
                }

                $stmt->bind_param(
                    "ii",
                    $accommodation_id,
                    $resort_id
                );

                if (!$stmt->execute()) {
                    throw new Exception(
                        "Unable to release accommodation."
                    );
                }

                if ($stmt->affected_rows < 1) {
                    /*
                     * An already Available row can
                     * produce zero affected rows.
                     * Verify that the room belongs
                     * to the current resort.
                     */
                    $stmt->close();

                    $verify = $conn->prepare(
                        "SELECT id
                         FROM accommodations
                         WHERE id = ?
                           AND resort_id = ?
                           AND status = 'Available'
                         LIMIT 1"
                    );

                    if (!$verify) {
                        throw new Exception(
                            "Unable to verify accommodation."
                        );
                    }

                    $verify->bind_param(
                        "ii",
                        $accommodation_id,
                        $resort_id
                    );

                    $verify->execute();

                    $found =
                        $verify->get_result()->fetch_assoc();

                    $verify->close();

                    if (!$found) {
                        throw new Exception(
                            "Accommodation could not be released."
                        );
                    }
                } else {
                    $stmt->close();
                }
            }

            /* =========================================
               COMMIT ALL CHECKOUT CHANGES
            ========================================= */

            if (!$conn->commit()) {
                throw new Exception(
                    "Unable to commit checkout transaction."
                );
            }

            $transaction_started = false;

            /* =========================================
               NOTIFICATION AFTER COMMIT
            ========================================= */

            $notification_message =
                $guest_name . " has checked out";

            if (
                $visit_type === "Accommodation" &&
                !empty($checkin["accommodation_code"])
            ) {
                $notification_message .=
                    " from accommodation " .
                    $checkin["accommodation_code"];
            }

            if ($final_payment > 0) {
                $notification_message .=
                    ". Final payment collected: ₱" .
                    number_format($final_payment, 2);
            } else {
                $notification_message .=
                    ". No additional payment collected.";
            }

            checkoutNotification(
                $conn,
                $resort_id,
                "Check-Out",
                "Guest Checked Out",
                $notification_message,
                $notifications_enabled
            );

            /* =========================================
               POST / REDIRECT / GET

               Avoid duplicate POST on refresh.
            ========================================= */

            $_SESSION["checkout_flash"] = [
                "type" => "success",
                "message" =>
                    "Checkout completed successfully. " .
                    (
                        $final_payment > 0
                        ? "Final payment of ₱" .
                          number_format($final_payment, 2) .
                          " was recorded once."
                        : "No additional payment was recorded."
                    )
            ];

            $_SESSION["checkout_csrf"] =
                bin2hex(random_bytes(32));

            header("Location: checkout.php");
            exit;

        } catch (Throwable $e) {
            if ($transaction_started) {
                try {
                    $conn->rollback();
                } catch (Throwable $rollback_error) {
                    error_log(
                        "Checkout rollback error: " .
                        $rollback_error->getMessage()
                    );
                }
            }

            error_log(
                "Checkout error: " . $e->getMessage()
            );

            $message =
                "Checkout failed: " . $e->getMessage();

            $message_type = "error";
        }
    }
}

/* =====================================================
   FLASH MESSAGE
===================================================== */

if (
    $message === "" &&
    isset($_SESSION["checkout_flash"])
) {
    $flash = $_SESSION["checkout_flash"];

    $message = $flash["message"] ?? "";
    $message_type = $flash["type"] ?? "";

    unset($_SESSION["checkout_flash"]);
}

/* =====================================================
   LOAD ACTIVE CHECK-INS
===================================================== */

$stmt = $conn->prepare(
    "SELECT
        c.id,
        c.guest_id,
        c.accommodation_id,
        c.visit_type,
        c.number_of_guests,
        c.check_in_time,
        c.total_amount,
        c.amount_paid,
        c.remaining_balance,
        c.payment_status,
        g.full_name,
        g.contact_number,
        a.accommodation_name,
        a.accommodation_code
     FROM checkins c
     LEFT JOIN guests g
        ON c.guest_id = g.id
     LEFT JOIN accommodations a
        ON c.accommodation_id = a.id
     WHERE c.resort_id = ?
       AND c.status = 'Active'
     ORDER BY c.check_in_time ASC, c.id ASC"
);

if ($stmt) {
    $stmt->bind_param("i", $resort_id);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $active_checkins[] = $row;
    }

    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<link rel="icon" type="image/png" href="logo1.png">

<title>Check-Out - Alapasco EcoKubo</title>

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

.main-content {
    margin-left: 250px;
    width: calc(100% - 250px);
    min-height: 100vh;
}

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

.user-info span {
    font-size: 11px;
    color: #777;
}

.page-content {
    padding: 25px;
}

.page-header {
    margin-bottom: 20px;
}

.page-header h1 {
    margin: 0 0 5px;
    color: #1f5d42;
    font-size: 25px;
}

.page-header p {
    margin: 0;
    color: #777;
    font-size: 13px;
}

.section {
    background: white;
    border-radius: 10px;
    padding: 22px;
    margin-bottom: 20px;
    border: 1px solid #e3e8e5;
}

.section h2 {
    margin: 0 0 18px;
    color: #1f5d42;
    font-size: 19px;
}

.info-note {
    background: #f1f7f3;
    border-left: 4px solid #1f5d42;
    padding: 13px;
    margin-bottom: 20px;
    font-size: 13px;
    line-height: 1.5;
}

.warning-note {
    background: #fff7e6;
    border-left: 4px solid #d99a00;
    padding: 13px;
    margin-bottom: 20px;
    font-size: 13px;
    line-height: 1.5;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.form-group {
    margin-bottom: 17px;
}

.form-group.full {
    grid-column: 1 / -1;
}

.form-group label {
    display: block;
    margin-bottom: 7px;
    font-size: 13px;
    font-weight: bold;
}

input,
select {
    width: 100%;
    padding: 12px;
    border: 1px solid #d7ddd9;
    border-radius: 6px;
    font-size: 14px;
    background: white;
    min-height: 45px;
}

input:focus,
select:focus {
    outline: none;
    border-color: #1f5d42;
}

input[readonly] {
    background: #f4f6f5;
}

.btn {
    border: none;
    background: #1f5d42;
    color: white;
    padding: 13px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    min-height: 46px;
    width: 100%;
}

.btn:hover {
    background: #174a34;
}

.btn:disabled {
    opacity: 0.65;
    cursor: not-allowed;
}

.message {
    padding: 13px 15px;
    border-radius: 7px;
    margin-bottom: 20px;
    font-size: 14px;
}

.message.success {
    background: #e7f6ec;
    color: #176b36;
    border: 1px solid #c9e8d3;
}

.message.error {
    background: #fdeaea;
    color: #a32121;
    border: 1px solid #f3caca;
}

.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 950px;
}

th,
td {
    padding: 12px;
    border-bottom: 1px solid #eee;
    text-align: left;
    font-size: 13px;
}

th {
    background: #f1f6f3;
    color: #1f5d42;
}

.status {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
}

.status-paid {
    background: #dff4e5;
    color: #176b36;
}

.status-partial {
    background: #fff0cc;
    color: #9a6800;
}

.status-pending {
    background: #fde2e2;
    color: #a32121;
}

.visit-type {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 15px;
    font-size: 11px;
    font-weight: bold;
}

.visit-accommodation {
    background: #e1f1e8;
    color: #1f5d42;
}

.visit-day {
    background: #fff1d6;
    color: #916000;
}

.empty {
    text-align: center;
    padding: 30px;
    color: #777;
    font-size: 14px;
}

@media (max-width: 900px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {

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
}
</style>
</head>

<body>

<!-- =====================================================
     SIDEBAR
===================================================== -->

<?php require __DIR__ . '/resort_sidebar.php'; ?>

<!-- =====================================================
     MAIN CONTENT
===================================================== -->

<main class="main-content">

    <div class="topbar">
        <h3>Check-Out</h3>

        <div class="resort-header-account"><?php require __DIR__ . '/resort_header_notifications.php'; ?>
<div class="user-info">
            <strong>
                <?php echo checkoutEscape($full_name); ?>
            </strong>

            <span>
                <?php echo checkoutEscape($resort_name); ?>
            </span>
        </div></div>
    </div>

    <div class="page-content">

        <div class="page-header">
            <h1>Guest Check-Out</h1>

            <p>
                Complete payment and check out current visitors.
            </p>
        </div>

        <?php if ($message !== ""): ?>
            <div class="message <?php echo checkoutEscape($message_type); ?>">
                <?php echo checkoutEscape($message); ?>
            </div>
        <?php endif; ?>

        <!-- CHECKOUT FORM -->

        <section class="section">

            <h2>🚪 Process Check-Out</h2>

            <div class="info-note">
                <strong>Payment rule:</strong>

                The amount already paid during reservation and
                check-in is included in the guest's total paid amount.

                At checkout, enter only the
                <strong>new final payment</strong>, if any.

                It will be recorded once.
            </div>

            <form method="POST" id="checkoutForm">

                <!-- Keep the action in POST when JavaScript disables the submit button. -->
                <input type="hidden" name="checkout" value="1">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo checkoutEscape($_SESSION["checkout_csrf"]); ?>"
                >

                <div class="form-grid">

                    <div class="form-group full">
                        <label for="checkin_id">
                            Active Guest *
                        </label>

                        <select
                            name="checkin_id"
                            id="checkin_id"
                            required
                        >
                            <option value="">
                                Select Guest
                            </option>

                            <?php foreach ($active_checkins as $active): ?>

                                <?php
                                $option_total =
                                    checkoutMoney($active["total_amount"]);

                                $option_paid =
                                    checkoutMoney($active["amount_paid"]);

                                $option_balance =
                                    checkoutBalance(
                                        $option_total,
                                        $option_paid
                                    );
                                ?>

                                <option
                                    value="<?php echo (int) $active["id"]; ?>"
                                    data-total="<?php echo $option_total; ?>"
                                    data-paid="<?php echo $option_paid; ?>"
                                    data-balance="<?php echo $option_balance; ?>"
                                    data-visit-type="<?php echo checkoutEscape($active["visit_type"]); ?>"
                                >
                                    <?php echo checkoutEscape($active["full_name"] ?? "Guest"); ?>

                                    -

                                    <?php echo checkoutEscape($active["visit_type"]); ?>

                                    <?php if (!empty($active["accommodation_code"])): ?>
                                        -
                                        <?php echo checkoutEscape($active["accommodation_code"]); ?>
                                    <?php endif; ?>

                                    -

                                    Paid ₱<?php echo number_format($option_paid, 2); ?>

                                    -

                                    Balance ₱<?php echo number_format($option_balance, 2); ?>
                                </option>

                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="form-group">
                        <label for="total_display">
                            Total Stay Amount
                        </label>

                        <input
                            type="text"
                            id="total_display"
                            value="₱0.00"
                            readonly
                        >
                    </div>

                    <div class="form-group">
                        <label for="paid_display">
                            Already Paid
                        </label>

                        <input
                            type="text"
                            id="paid_display"
                            value="₱0.00"
                            readonly
                        >
                    </div>

                    <div class="form-group">
                        <label for="balance_display">
                            Remaining Balance
                        </label>

                        <input
                            type="text"
                            id="balance_display"
                            value="₱0.00"
                            readonly
                        >
                    </div>

                    <div class="form-group">
                        <label for="final_payment">
                            Final Payment at Check-Out
                        </label>

                        <input
                            type="number"
                            name="final_payment"
                            id="final_payment"
                            min="0"
                            step="0.01"
                            value="0"
                            required
                        >

                        <small
                            id="paymentHelp"
                            style="
                                display:block;
                                margin-top:6px;
                                color:#777;
                                font-size:12px;
                            "
                        >
                            Select a guest to see the remaining balance.
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="payment_method">
                            Payment Method
                        </label>

                        <select
                            name="payment_method"
                            id="payment_method"
                        >
                            <option value="Cash">Cash</option>
                            <option value="GCash">GCash</option>
                            <option value="Bank Transfer">
                                Bank Transfer
                            </option>
                            <option value="Other">Other</option>
                        </select>
                    </div>

                    <div class="form-group full">
                        <label for="reference_number">
                            Reference Number
                        </label>

                        <input
                            type="text"
                            name="reference_number"
                            id="reference_number"
                            maxlength="100"
                            placeholder="Optional"
                        >
                    </div>

                </div>

                <div class="warning-note">
                    <strong>Before checking out:</strong>

                    Make sure the remaining balance is
                    <strong>₱0.00</strong> after the final payment.

                    If there is still a balance, the guest must
                    pay the remaining amount before accommodation
                    checkout can be completed.
                </div>

                <button
                    type="submit"
                    class="btn"
                    id="checkoutButton"
                >
                    ✓ Complete Check-Out
                </button>

            </form>

        </section>

        <!-- ACTIVE VISITORS -->

        <section class="section">

            <h2>👥 Active Visitors</h2>

            <?php if (count($active_checkins) === 0): ?>

                <div class="empty">
                    No active visitors currently.
                </div>

            <?php else: ?>

                <div class="table-container">
                    <table>

                        <thead>
                            <tr>
                                <th>Guest</th>
                                <th>Contact</th>
                                <th>Visit Type</th>
                                <th>Accommodation</th>
                                <th>Guests</th>
                                <th>Total</th>
                                <th>Already Paid</th>
                                <th>Balance</th>
                                <th>Payment Status</th>
                                <th>Check-In</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($active_checkins as $active): ?>

                            <?php
                            $row_total =
                                checkoutMoney($active["total_amount"]);

                            $row_paid =
                                checkoutMoney($active["amount_paid"]);

                            $row_balance =
                                checkoutBalance(
                                    $row_total,
                                    $row_paid
                                );

                            $is_day =
                                $active["visit_type"] === "Day Visitor";
                            ?>

                            <tr>

                                <td>
                                    <?php echo checkoutEscape($active["full_name"] ?? "Guest"); ?>
                                </td>

                                <td>
                                    <?php echo checkoutEscape($active["contact_number"] ?? "—"); ?>
                                </td>

                                <td>
                                    <?php if ($is_day): ?>
                                        <span class="visit-type visit-day">
                                            👤 Day Visitor
                                        </span>
                                    <?php else: ?>
                                        <span class="visit-type visit-accommodation">
                                            🏡 Accommodation
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if (
                                        !$is_day &&
                                        !empty($active["accommodation_code"])
                                    ): ?>

                                        <?php echo checkoutEscape($active["accommodation_code"]); ?>

                                        -

                                        <?php echo checkoutEscape($active["accommodation_name"]); ?>

                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php echo (int) $active["number_of_guests"]; ?>
                                </td>

                                <td>
                                    <?php if ($is_day): ?>
                                        N/A
                                    <?php else: ?>
                                        ₱<?php echo number_format($row_total, 2); ?>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($is_day): ?>
                                        N/A
                                    <?php else: ?>
                                        ₱<?php echo number_format($row_paid, 2); ?>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($is_day): ?>
                                        N/A
                                    <?php else: ?>
                                        <strong
                                            style="color:<?php echo $row_balance <= 0 ? '#176b36' : '#b83b3b'; ?>"
                                        >
                                            ₱<?php echo number_format($row_balance, 2); ?>
                                        </strong>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($is_day): ?>

                                        <span class="status status-paid">
                                            No Payment
                                        </span>

                                    <?php elseif ($row_balance <= 0): ?>

                                        <span class="status status-paid">
                                            Paid
                                        </span>

                                    <?php elseif ($row_paid > 0): ?>

                                        <span class="status status-partial">
                                            Partially Paid
                                        </span>

                                    <?php else: ?>

                                        <span class="status status-pending">
                                            Pending
                                        </span>

                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php echo checkoutEscape($active["check_in_time"]); ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>
                    </table>
                </div>

            <?php endif; ?>

        </section>

    </div>

</main>

<script>
/* =====================================================
   CHECKOUT FORM JAVASCRIPT
===================================================== */

const checkinSelect =
    document.getElementById("checkin_id");

const totalDisplay =
    document.getElementById("total_display");

const paidDisplay =
    document.getElementById("paid_display");

const balanceDisplay =
    document.getElementById("balance_display");

const finalPayment =
    document.getElementById("final_payment");

const paymentHelp =
    document.getElementById("paymentHelp");

const checkoutForm =
    document.getElementById("checkoutForm");

const checkoutButton =
    document.getElementById("checkoutButton");

let checkoutSubmitting = false;

function formatMoney(amount) {
    return "₱" + Number(amount).toFixed(2);
}

function selectedCheckin() {
    if (!checkinSelect) {
        return null;
    }

    const option =
        checkinSelect.options[checkinSelect.selectedIndex];

    if (!option || !option.value) {
        return null;
    }

    return option;
}

function updateCheckoutInformation() {
    const selected = selectedCheckin();

    if (!selected) {
        totalDisplay.value = "₱0.00";
        paidDisplay.value = "₱0.00";
        balanceDisplay.value = "₱0.00";

        finalPayment.value = "0";
        finalPayment.readOnly = false;
        finalPayment.removeAttribute("max");

        paymentHelp.textContent =
            "Select a guest to see the remaining balance.";

        return;
    }

    const total =
        Number(selected.dataset.total || 0);

    const paid =
        Number(selected.dataset.paid || 0);

    const visitType =
        selected.dataset.visitType || "Accommodation";

    const balance =
        Math.max(0, Number((total - paid).toFixed(2)));

    totalDisplay.value = formatMoney(total);
    paidDisplay.value = formatMoney(paid);
    balanceDisplay.value = formatMoney(balance);

    if (visitType === "Day Visitor") {
        finalPayment.value = "0";
        finalPayment.max = "0";
        finalPayment.readOnly = true;

        balanceDisplay.value = "₱0.00";

        paymentHelp.textContent =
            "Day Visitors do not require accommodation payment.";

        return;
    }

    finalPayment.readOnly = false;
    finalPayment.max = balance.toFixed(2);

    if (balance <= 0) {
        finalPayment.value = "0";

        paymentHelp.textContent =
            "This guest is already fully paid. No additional payment is required.";
    } else {
        finalPayment.value = balance.toFixed(2);

        paymentHelp.textContent =
            "Final payment required: " +
            formatMoney(balance) + ".";
    }
}

if (checkinSelect) {
    checkinSelect.addEventListener(
        "change",
        updateCheckoutInformation
    );
}

if (finalPayment) {
    finalPayment.addEventListener("input", function () {
        const selected = selectedCheckin();

        if (!selected) {
            return;
        }

        if (selected.dataset.visitType === "Day Visitor") {
            this.value = "0";
            return;
        }

        const total =
            Number(selected.dataset.total || 0);

        const paid =
            Number(selected.dataset.paid || 0);

        const balance =
            Math.max(0, Number((total - paid).toFixed(2)));

        const entered = Number(this.value);

        if (Number.isFinite(entered) && entered > balance) {
            this.value = balance.toFixed(2);
        }
    });
}

if (checkoutForm) {
    checkoutForm.addEventListener("submit", function (event) {
        if (checkoutSubmitting) {
            event.preventDefault();
            return;
        }

        const selected = selectedCheckin();

        if (!selected) {
            event.preventDefault();
            alert("Please select an active guest.");
            return;
        }

        const visitType =
            selected.dataset.visitType || "Accommodation";

        const total =
            Number(selected.dataset.total || 0);

        const paid =
            Number(selected.dataset.paid || 0);

        const balance =
            Math.max(0, Number((total - paid).toFixed(2)));

        const entered =
            Number(finalPayment.value);

        if (visitType === "Day Visitor") {
            finalPayment.value = "0";
        } else {
            if (!Number.isFinite(entered) || entered < 0) {
                event.preventDefault();
                alert("Enter a valid final payment.");
                return;
            }

            if (entered < balance - 0.009) {
                event.preventDefault();

                alert(
                    "Full payment is required before checkout.\n\n" +
                    "Remaining balance: " +
                    formatMoney(balance)
                );

                return;
            }

            if (entered > balance + 0.009) {
                event.preventDefault();

                alert(
                    "Checkout payment cannot exceed the remaining balance."
                );

                return;
            }

            finalPayment.value = balance.toFixed(2);
        }

        checkoutSubmitting = true;

        checkoutButton.disabled = true;
        checkoutButton.textContent = "Processing Check-Out...";
    });
}

updateCheckoutInformation();
</script>

</body>
</html>
