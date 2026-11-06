<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: resort_login.php");
    exit;
}


/* ==================================================
   DATABASE CONNECTION
================================================== */

require_once "database.php";


/* ==================================================
   SESSION INFORMATION
================================================== */

$user_id = (int) $_SESSION["user_id"];

$resort_id = (int) ($_SESSION["resort_id"] ?? 0);

$resort_name = $_SESSION["resort_name"] ?? "My Resort";

$full_name = $_SESSION["full_name"] ?? "User";


/* ==================================================
   GET RESORT ID IF NOT IN SESSION
================================================== */

if (!$resort_id) {

    $stmt = $conn->prepare("
        SELECT id
        FROM resorts
        WHERE user_id = ?
        LIMIT 1
    ");

    if ($stmt) {

        $stmt->bind_param(
            "i",
            $user_id
        );

        $stmt->execute();

        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {

            $resort_id = (int) $row["id"];

        }

        $stmt->close();
    }
}


if (!$resort_id) {
    die("Resort account not found.");
}


/* ==================================================
   DATE FILTER
================================================== */

$start_date = $_GET["start_date"]
    ?? date("Y-m-01");

$end_date = $_GET["end_date"]
    ?? date("Y-m-d");


/* ==================================================
   VISITOR REPORT
================================================== */

$total_visitors = 0;
$accommodation_visitors = 0;
$day_visitors = 0;

$sql = "
    SELECT

        COUNT(*) AS total_visitors,

        SUM(
            CASE
                WHEN visit_type = 'Accommodation'
                THEN 1
                ELSE 0
            END
        ) AS accommodation_visitors,

        SUM(
            CASE
                WHEN visit_type = 'Day Visitor'
                THEN 1
                ELSE 0
            END
        ) AS day_visitors

    FROM checkins

    WHERE resort_id = ?

    AND DATE(check_in_time)
    BETWEEN ? AND ?
";

$stmt = $conn->prepare($sql);

if ($stmt) {

    $stmt->bind_param(
        "iss",
        $resort_id,
        $start_date,
        $end_date
    );

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        $total_visitors =
            $row["total_visitors"] ?? 0;

        $accommodation_visitors =
            $row["accommodation_visitors"] ?? 0;

        $day_visitors =
            $row["day_visitors"] ?? 0;
    }

    $stmt->close();
}


/* ==================================================
   RESERVATION REPORT
================================================== */

$total_reservations = 0;
$pending_reservations = 0;
$confirmed_reservations = 0;
$cancelled_reservations = 0;
$completed_reservations = 0;

$sql = "
    SELECT

        COUNT(*) AS total_reservations,

        SUM(
            CASE
                WHEN reservation_status = 'Pending'
                THEN 1
                ELSE 0
            END
        ) AS pending,

        SUM(
            CASE
                WHEN reservation_status = 'Confirmed'
                THEN 1
                ELSE 0
            END
        ) AS confirmed,

        SUM(
            CASE
                WHEN reservation_status = 'Cancelled'
                THEN 1
                ELSE 0
            END
        ) AS cancelled,

        SUM(
            CASE
                WHEN reservation_status = 'Completed'
                THEN 1
                ELSE 0
            END
        ) AS completed

    FROM reservations

    WHERE resort_id = ?

    AND visit_date
    BETWEEN ? AND ?
";

$stmt = $conn->prepare($sql);

if ($stmt) {

    $stmt->bind_param(
        "iss",
        $resort_id,
        $start_date,
        $end_date
    );

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        $total_reservations =
            $row["total_reservations"] ?? 0;

        $pending_reservations =
            $row["pending"] ?? 0;

        $confirmed_reservations =
            $row["confirmed"] ?? 0;

        $cancelled_reservations =
            $row["cancelled"] ?? 0;

        $completed_reservations =
            $row["completed"] ?? 0;
    }

    $stmt->close();
}


/* ==================================================
   PAYMENT REPORT
================================================== */

$payment_count = 0;
$total_payments = 0;

$sql = "
    SELECT

        COUNT(*) AS payment_count,

        COALESCE(
            SUM(amount),
            0
        ) AS total_payments

    FROM payments

    WHERE resort_id = ?

    AND DATE(payment_date)
    BETWEEN ? AND ?
";

$stmt = $conn->prepare($sql);

if ($stmt) {

    $stmt->bind_param(
        "iss",
        $resort_id,
        $start_date,
        $end_date
    );

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        $payment_count =
            $row["payment_count"] ?? 0;

        $total_payments =
            $row["total_payments"] ?? 0;
    }

    $stmt->close();
}


/* ==================================================
   ACCOMMODATION REPORT
================================================== */

$total_accommodations = 0;
$available_accommodations = 0;
$occupied_accommodations = 0;
$reserved_accommodations = 0;
$maintenance_accommodations = 0;

$sql = "
    SELECT

        COUNT(*) AS total_accommodations,

        SUM(
            CASE
                WHEN status = 'Available'
                THEN 1
                ELSE 0
            END
        ) AS available,

        SUM(
            CASE
                WHEN status = 'Occupied'
                THEN 1
                ELSE 0
            END
        ) AS occupied,

        SUM(
            CASE
                WHEN status = 'Reserved'
                THEN 1
                ELSE 0
            END
        ) AS reserved,

        SUM(
            CASE
                WHEN status = 'Maintenance'
                THEN 1
                ELSE 0
            END
        ) AS maintenance

    FROM accommodations

    WHERE resort_id = ?
";

$stmt = $conn->prepare($sql);

if ($stmt) {

    $stmt->bind_param(
        "i",
        $resort_id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        $total_accommodations =
            $row["total_accommodations"] ?? 0;

        $available_accommodations =
            $row["available"] ?? 0;

        $occupied_accommodations =
            $row["occupied"] ?? 0;

        $reserved_accommodations =
            $row["reserved"] ?? 0;

        $maintenance_accommodations =
            $row["maintenance"] ?? 0;
    }

    $stmt->close();
}


/* ==================================================
   VISITOR DETAILS
================================================== */

$daily_sql = "
    SELECT

        c.id,

        g.full_name,

        g.contact_number,

        c.visit_type,

        a.accommodation_code,

        c.number_of_guests,

        c.check_in_time,

        c.total_amount,

        c.amount_paid,

        c.remaining_balance,

        c.payment_status,

        c.status

    FROM checkins c

    INNER JOIN guests g
        ON c.guest_id = g.id

    LEFT JOIN accommodations a
        ON c.accommodation_id = a.id

    WHERE c.resort_id = ?

    AND DATE(c.check_in_time)
    BETWEEN ? AND ?

    ORDER BY c.check_in_time DESC
";

$stmt = $conn->prepare($daily_sql);

if (!$stmt) {
    die("Unable to load visitor report.");
}

$stmt->bind_param(
    "iss",
    $resort_id,
    $start_date,
    $end_date
);

$stmt->execute();

$daily_result = $stmt->get_result();

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

<title>
    Reports - Alapasco EcoKubo
</title>


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


/* ==================================================
   MAIN CONTENT
================================================== */

.main-content {
    margin-left: 250px;
    width: calc(100% - 250px);
    min-height: 100vh;
}


/* ==================================================
   TOPBAR
================================================== */

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
    color: #777;
    font-size: 12px;
}


/* ==================================================
   PAGE CONTENT
================================================== */

.page-content {
    padding: 25px;
}


/* ==================================================
   PAGE HEADER
================================================== */

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


/* ==================================================
   FILTER
================================================== */

.filter-box {
    background: white;
    padding: 20px;
    border-radius: 10px;
    border: 1px solid #eee;
    margin-bottom: 20px;
}

.filter-box h2 {
    margin: 0 0 15px;
    color: #1f5d42;
    font-size: 17px;
}

.filter-form {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 12px;
    align-items: end;
}

.form-group label {
    display: block;
    margin-bottom: 6px;
    font-size: 12px;
    font-weight: bold;
    color: #555;
}

.form-group input {
    width: 100%;
    padding: 11px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 13px;
}

.filter-button {
    padding: 11px 20px;
    background: #1f5d42;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-weight: bold;
}

.filter-button:hover {
    background: #174832;
}


/* ==================================================
   REPORT CARDS
================================================== */

.report-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin-bottom: 25px;
}

.report-card {
    background: white;
    padding: 20px;
    border-radius: 10px;
    border: 1px solid #eee;
}

.report-card .icon {
    font-size: 25px;
    margin-bottom: 8px;
}

.report-card h3 {
    margin: 0;
    color: #1f5d42;
    font-size: 25px;
}

.report-card p {
    margin: 5px 0 0;
    color: #777;
    font-size: 12px;
}


/* ==================================================
   REPORT SECTIONS
================================================== */

.report-section {
    background: white;
    padding: 20px;
    border-radius: 10px;
    border: 1px solid #eee;
    margin-bottom: 20px;
}

.report-section h2 {
    margin: 0 0 15px;
    color: #1f5d42;
    font-size: 18px;
}


/* ==================================================
   MINI GRID
================================================== */

.mini-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}

.mini-card {
    background: #f4f6f5;
    padding: 15px;
    border-radius: 7px;
    text-align: center;
}

.mini-card strong {
    display: block;
    color: #1f5d42;
    font-size: 20px;
    margin-bottom: 5px;
}

.mini-card span {
    color: #777;
    font-size: 11px;
}


/* ==================================================
   TABLE
================================================== */

.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

table th {
    background: #1f5d42;
    color: white;
    padding: 11px;
    text-align: left;
    font-size: 12px;
}

table td {
    padding: 11px;
    border-bottom: 1px solid #eee;
    font-size: 12px;
}

table tr:hover {
    background: #f7f9f8;
}


/* ==================================================
   STATUS
================================================== */

.status {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: bold;
}

.status-accommodation {
    background: #e5f1ea;
    color: #1f5d42;
}

.status-day {
    background: #eef1f5;
    color: #555;
}

.status-paid {
    background: #e5f1ea;
    color: #1f5d42;
}

.status-partial {
    background: #fff4d6;
    color: #8a6500;
}

.status-pending {
    background: #f5e7e7;
    color: #9b3d3d;
}

/* ==================================================
   EMPTY
================================================== */

.empty {
    text-align: center;
    padding: 30px;
    color: #777;
    font-size: 13px;
}


/* ==================================================
   RESPONSIVE
================================================== */

@media (max-width: 1000px) {

    .report-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .mini-grid {
        grid-template-columns: repeat(2, 1fr);
    }

}


@media (max-width: 700px) {

    .main-content {
        margin-left: 200px;
        width: calc(100% - 200px);
    }

    .filter-form {
        grid-template-columns: 1fr;
    }

    .report-grid {
        grid-template-columns: 1fr;
    }

    .mini-grid {
        grid-template-columns: 1fr;
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

    .user-info strong {
        font-size: 12px;
    }

    .user-info span {
        font-size: 10px;
    }

}


</style>

</head>


<body>


<!-- ==================================================
     STANDARD RESORT SIDEBAR
================================================== -->

<?php require __DIR__ . '/resort_sidebar.php'; ?>


<!-- ==================================================
     MAIN CONTENT
================================================== -->

<div class="main-content">


    <div class="topbar">

        <h3>
            Reports
        </h3>


        <div class="resort-header-account"><?php require __DIR__ . '/resort_header_notifications.php'; ?>
<div class="user-info">

            <strong>

                <?php

                echo htmlspecialchars(
                    $full_name
                );

                ?>

            </strong>


            <span>

                <?php

                echo htmlspecialchars(
                    $resort_name
                );

                ?>

            </span>

        </div></div>

    </div>


    <div class="page-content">


        <!-- HEADER -->

        <div class="page-header">

            <h1>
                Reports
            </h1>

            <p>
                View and monitor your resort records.
            </p>

        </div>


        <!-- DATE FILTER -->

        <div class="filter-box">

            <h2>
                Report Date Range
            </h2>


            <form
                method="GET"
                class="filter-form"
            >

                <div class="form-group">

                    <label>
                        Start Date
                    </label>

                    <input
                        type="date"
                        name="start_date"
                        value="<?php
                        echo htmlspecialchars(
                            $start_date
                        );
                        ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        End Date
                    </label>

                    <input
                        type="date"
                        name="end_date"
                        value="<?php
                        echo htmlspecialchars(
                            $end_date
                        );
                        ?>"
                        required
                    >

                </div>


                <button
                    type="submit"
                    class="filter-button"
                >
                    🔎 Generate Report
                </button>

            </form>

        </div>


        <!-- SUMMARY CARDS -->

        <div class="report-grid">

            <div class="report-card">

                <div class="icon">
                    👥
                </div>

                <h3>
                    <?php
                    echo $total_visitors;
                    ?>
                </h3>

                <p>
                    Total Visitors
                </p>

            </div>


            <div class="report-card">

                <div class="icon">
                    📅
                </div>

                <h3>
                    <?php
                    echo $total_reservations;
                    ?>
                </h3>

                <p>
                    Reservations
                </p>

            </div>


            <div class="report-card">

                <div class="icon">
                    💰
                </div>

                <h3>
                    ₱<?php
                    echo number_format(
                        $total_payments,
                        2
                    );
                    ?>
                </h3>

                <p>
                    Total Payments
                </p>

            </div>


            <div class="report-card">

                <div class="icon">
                    🏡
                </div>

                <h3>
                    <?php
                    echo $total_accommodations;
                    ?>
                </h3>

                <p>
                    Accommodations
                </p>

            </div>

        </div>


        <!-- VISITOR REPORT -->

        <div class="report-section">

            <h2>
                👥 Visitor Report
            </h2>


            <div class="mini-grid">

                <div class="mini-card">

                    <strong>
                        <?php
                        echo $total_visitors;
                        ?>
                    </strong>

                    <span>
                        Total Visitors
                    </span>

                </div>


                <div class="mini-card">

                    <strong>
                        <?php
                        echo $accommodation_visitors;
                        ?>
                    </strong>

                    <span>
                        Accommodation Guests
                    </span>

                </div>


                <div class="mini-card">

                    <strong>
                        <?php
                        echo $day_visitors;
                        ?>
                    </strong>

                    <span>
                        Day Visitors
                    </span>

                </div>


                <div class="mini-card">

                    <strong>
                        <?php
                        echo $payment_count;
                        ?>
                    </strong>

                    <span>
                        Payment Records
                    </span>

                </div>

            </div>

        </div>


        <!-- RESERVATION REPORT -->

        <div class="report-section">

            <h2>
                📅 Reservation Report
            </h2>


            <div class="mini-grid">

                <div class="mini-card">

                    <strong>
                        <?php
                        echo $total_reservations;
                        ?>
                    </strong>

                    <span>
                        Total
                    </span>

                </div>


                <div class="mini-card">

                    <strong>
                        <?php
                        echo $pending_reservations;
                        ?>
                    </strong>

                    <span>
                        Pending
                    </span>

                </div>


                <div class="mini-card">

                    <strong>
                        <?php
                        echo $confirmed_reservations;
                        ?>
                    </strong>

                    <span>
                        Confirmed
                    </span>

                </div>


                <div class="mini-card">

                    <strong>
                        <?php
                        echo $cancelled_reservations;
                        ?>
                    </strong>

                    <span>
                        Cancelled
                    </span>

                </div>

            </div>

        </div>


        <!-- ACCOMMODATION REPORT -->

        <div class="report-section">

            <h2>
                🏡 Accommodation Report
            </h2>


            <div class="mini-grid">

                <div class="mini-card">

                    <strong>
                        <?php
                        echo $total_accommodations;
                        ?>
                    </strong>

                    <span>
                        Total
                    </span>

                </div>


                <div class="mini-card">

                    <strong>
                        <?php
                        echo $available_accommodations;
                        ?>
                    </strong>

                    <span>
                        Available
                    </span>

                </div>


                <div class="mini-card">

                    <strong>
                        <?php
                        echo $occupied_accommodations;
                        ?>
                    </strong>

                    <span>
                        Occupied
                    </span>

                </div>


                <div class="mini-card">

                    <strong>
                        <?php
                        echo $maintenance_accommodations;
                        ?>
                    </strong>

                    <span>
                        Maintenance
                    </span>

                </div>

            </div>

        </div>


        <!-- PAYMENT REPORT -->

        <div class="report-section">

            <h2>
                💰 Payment Report
            </h2>


            <div class="mini-grid">

                <div class="mini-card">

                    <strong>
                        ₱<?php
                        echo number_format(
                            $total_payments,
                            2
                        );
                        ?>
                    </strong>

                    <span>
                        Total Payments
                    </span>

                </div>


                <div class="mini-card">

                    <strong>
                        <?php
                        echo $payment_count;
                        ?>
                    </strong>

                    <span>
                        Payment Records
                    </span>

                </div>


                <div class="mini-card">

                    <strong>
                        <?php
                        echo $completed_reservations;
                        ?>
                    </strong>

                    <span>
                        Completed Reservations
                    </span>

                </div>


                <div class="mini-card">

                    <strong>
                        <?php
                        echo $pending_reservations;
                        ?>
                    </strong>

                    <span>
                        Pending Reservations
                    </span>

                </div>

            </div>

        </div>


        <!-- VISITOR DETAILS -->

        <div class="report-section">

            <h2>
                📋 Visitor Details
            </h2>


            <div class="table-container">


                <?php if ($daily_result->num_rows > 0): ?>


                <table>

                    <thead>

                    <tr>

                        <th>
                            Visitor
                        </th>

                        <th>
                            Contact
                        </th>

                        <th>
                            Visit Type
                        </th>

                        <th>
                            Cottage/Tent
                        </th>

                        <th>
                            Guests
                        </th>

                        <th>
                            Check-In
                        </th>

                        <th>
                            Payment
                        </th>

                        <th>
                            Balance
                        </th>

                        <th>
                            Status
                        </th>

                    </tr>

                    </thead>


                    <tbody>


                    <?php
                    while (
                        $row =
                        $daily_result->fetch_assoc()
                    ):
                    ?>


                    <tr>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                $row["full_name"]
                            );
                            ?>
                        </td>


                        <td>
                            <?php
                            echo htmlspecialchars(
                                $row["contact_number"]
                            );
                            ?>
                        </td>


                        <td>

                            <?php
                            if (
                                $row["visit_type"]
                                === "Day Visitor"
                            ):
                            ?>

                                <span
                                    class="status status-day"
                                >
                                    Day Visitor
                                </span>

                            <?php else: ?>

                                <span
                                    class="status status-accommodation"
                                >
                                    Accommodation
                                </span>

                            <?php endif; ?>

                        </td>


                        <td>

                            <?php

                            if (
                                $row["visit_type"]
                                === "Day Visitor"
                            ) {

                                echo "—";

                            } else {

                                echo htmlspecialchars(
                                    $row[
                                        "accommodation_code"
                                    ]
                                    ?? "—"
                                );

                            }

                            ?>

                        </td>


                        <td>

                            <?php

                            echo (int)
                                $row[
                                    "number_of_guests"
                                ];

                            ?>

                        </td>


                        <td>

                            <?php

                            echo date(
                                "M d, Y h:i A",
                                strtotime(
                                    $row[
                                        "check_in_time"
                                    ]
                                )
                            );

                            ?>

                        </td>


                        <td>

                            <?php

                            if (
                                $row["visit_type"]
                                === "Day Visitor"
                            ) {

                                echo "N/A";

                            } else {

                                echo "₱";

                                echo number_format(
                                    $row[
                                        "amount_paid"
                                    ] ?? 0,
                                    2
                                );

                            }

                            ?>

                        </td>


                        <td>

                            <?php

                            if (
                                $row["visit_type"]
                                === "Day Visitor"
                            ) {

                                echo "N/A";

                            } else {

                                echo "₱";

                                echo number_format(
                                    $row[
                                        "remaining_balance"
                                    ] ?? 0,
                                    2
                                );

                            }

                            ?>

                        </td>


                        <td>

                            <?php

                            if (
                                $row["visit_type"]
                                === "Day Visitor"
                            ) {

                                echo "Checked In";

                            } elseif (
                                $row[
                                    "payment_status"
                                ]
                                === "Paid"
                            ) {

                                echo '
                                <span
                                    class="status status-paid"
                                >
                                    Paid
                                </span>
                                ';

                            } elseif (
                                $row[
                                    "payment_status"
                                ]
                                === "Partially Paid"
                            ) {

                                echo '
                                <span
                                    class="status status-partial"
                                >
                                    Partially Paid
                                </span>
                                ';

                            } else {

                                echo '
                                <span
                                    class="status status-pending"
                                >
                                    Pending
                                </span>
                                ';

                            }

                            ?>

                        </td>

                    </tr>


                    <?php endwhile; ?>


                    </tbody>

                </table>


                <?php else: ?>


                <div class="empty">

                    No visitor records found
                    for the selected date range.

                </div>


                <?php endif; ?>


            </div>

        </div>


    </div>

</div>


</body>

</html>

<?php

$stmt->close();

$conn->close();

?>