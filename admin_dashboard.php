<?php

session_start();

require_once "database.php";

/*
|--------------------------------------------------------------------------
| ADMIN LOGIN AUTHENTICATION
|--------------------------------------------------------------------------
*/

$error = "";

/*
|--------------------------------------------------------------------------
| If admin is submitting the login form
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    require __DIR__ . '/admin_auth.php';
    exit;
}

/*
|--------------------------------------------------------------------------
| PROTECT ADMIN DASHBOARD
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION["admin_id"])) {

    header("Location: admin_login.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| ADMIN INFORMATION
|--------------------------------------------------------------------------
*/

$admin_username = $_SESSION["admin_username"] ?? "Administrator";


/*
|--------------------------------------------------------------------------
| HELPER FUNCTION
|--------------------------------------------------------------------------
*/

function getCount($conn, $sql) {

    $result = $conn->query($sql);

    if ($result) {

        $row = $result->fetch_assoc();

        return (int)($row["total"] ?? 0);
    }

    return 0;
}


/*
|--------------------------------------------------------------------------
| DASHBOARD COUNTS
|--------------------------------------------------------------------------
*/


/*
| Registered Resorts
*/

$total_resorts = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM resorts"
);


/*
| Total Accommodations
*/

$total_accommodations = getCount(
    $conn,
    "SELECT COUNT(*) AS total FROM accommodations"
);


/*
| Today's Visitors
*/

$today_visitors = getCount(
    $conn,
    "
    SELECT COUNT(*) AS total
    FROM checkins
    WHERE DATE(check_in_time) = CURDATE()
    "
);


/*
| Current Visitors
*/

$current_visitors = getCount(
    $conn,
    "
    SELECT COUNT(*) AS total
    FROM checkins
    WHERE status = 'Active'
    "
);


/*
|--------------------------------------------------------------------------
| ACCOMMODATION STATUS
|--------------------------------------------------------------------------
*/

$available_accommodations = getCount(
    $conn,
    "
    SELECT COUNT(*) AS total
    FROM accommodations
    WHERE status = 'Available'
    "
);

$occupied_accommodations = getCount(
    $conn,
    "
    SELECT COUNT(*) AS total
    FROM accommodations
    WHERE status = 'Occupied'
    "
);

$reserved_accommodations = getCount(
    $conn,
    "
    SELECT COUNT(*) AS total
    FROM accommodations
    WHERE status = 'Reserved'
    "
);


/*
|--------------------------------------------------------------------------
| TODAY'S ACCOMMODATION VISITORS
|--------------------------------------------------------------------------
*/

$today_accommodation_visitors = getCount(
    $conn,
    "
    SELECT COUNT(*) AS total
    FROM checkins
    WHERE DATE(check_in_time) = CURDATE()
    AND visit_type = 'Accommodation'
    "
);


/*
|--------------------------------------------------------------------------
| TODAY'S DAY VISITORS
|--------------------------------------------------------------------------
*/

$today_day_visitors = getCount(
    $conn,
    "
    SELECT COUNT(*) AS total
    FROM checkins
    WHERE DATE(check_in_time) = CURDATE()
    AND visit_type = 'Day Visitor'
    "
);


/*
|--------------------------------------------------------------------------
| RECENT VISITOR ACTIVITY
|--------------------------------------------------------------------------
*/

$recent_visitors = [];

$result = $conn->query("
    SELECT
        c.id,
        c.visit_type,
        c.check_in_time,
        c.status,
        g.full_name,
        g.number_of_guests,
        r.resort_name,
        a.accommodation_code

    FROM checkins c

    INNER JOIN guests g
        ON c.guest_id = g.id

    INNER JOIN resorts r
        ON c.resort_id = r.id

    LEFT JOIN accommodations a
        ON c.accommodation_id = a.id

    ORDER BY c.check_in_time DESC

    LIMIT 8
");

if ($result) {

    while ($row = $result->fetch_assoc()) {

        $recent_visitors[] = $row;
    }
}


/*
|--------------------------------------------------------------------------
| REGISTERED RESORTS
|--------------------------------------------------------------------------
*/

$resorts = [];

$result = $conn->query("
    SELECT
        r.id,
        r.resort_name,
        r.user_id,
        COUNT(a.id) AS accommodation_count

    FROM resorts r

    LEFT JOIN accommodations a
        ON r.id = a.resort_id

    GROUP BY
        r.id,
        r.resort_name,
        r.user_id

    ORDER BY r.resort_name ASC

    LIMIT 8
");

if ($result) {

    while ($row = $result->fetch_assoc()) {

        $resorts[] = $row;
    }
}


/* Analytics embedded in Dashboard */
/* =========================
   TOTAL VISITS
========================= */

$analytics_total_visits = 0;
$analytics_accommodation_visits = 0;
$analytics_day_visits = 0;

$analytics_sql = "
    SELECT
        COUNT(*) AS total_visits,

        SUM(
            CASE
                WHEN visit_type = 'Accommodation'
                THEN 1
                ELSE 0
            END
        ) AS accommodation_visits,

        SUM(
            CASE
                WHEN visit_type = 'Day Visitor'
                THEN 1
                ELSE 0
            END
        ) AS day_visits

    FROM checkins
";

$analytics_result = $conn->query($analytics_sql);

if ($analytics_result) {
    $analytics_row = $analytics_result->fetch_assoc();

    $analytics_total_visits = (int)($analytics_row["total_visits"] ?? 0);
    $analytics_accommodation_visits = (int)($analytics_row["accommodation_visits"] ?? 0);
    $analytics_day_visits = (int)($analytics_row["day_visits"] ?? 0);
}


/* =========================
   TOTAL RESORTS
========================= */

$analytics_total_resorts = 0;

$analytics_result = $conn->query("
    SELECT COUNT(*) AS total
    FROM resorts
");

if ($analytics_result) {
    $analytics_row = $analytics_result->fetch_assoc();
    $analytics_total_resorts = (int)($analytics_row["total"] ?? 0);
}


/* =========================
   TOTAL ACCOMMODATIONS
========================= */

$analytics_total_accommodations = 0;

$analytics_result = $conn->query("
    SELECT COUNT(*) AS total
    FROM accommodations
");

if ($analytics_result) {
    $analytics_row = $analytics_result->fetch_assoc();
    $analytics_total_accommodations = (int)($analytics_row["total"] ?? 0);
}


/* =========================
   CURRENT VISITORS
========================= */

$analytics_current_visitors = 0;

$analytics_result = $conn->query("
    SELECT COUNT(*) AS total
    FROM checkins
    WHERE status = 'Active'
");

if ($analytics_result) {
    $analytics_row = $analytics_result->fetch_assoc();
    $analytics_current_visitors = (int)($analytics_row["total"] ?? 0);
}


/* =========================
   LAST 7 DAYS
========================= */

$analytics_dates = [];
$analytics_accommodation_data = [];
$analytics_day_visitor_data = [];

for ($analytics_i = 6; $analytics_i >= 0; $analytics_i--) {

    $analytics_date = date(
        "Y-m-d",
        strtotime("-" . $analytics_i . " days")
    );

    $analytics_dates[] = date(
        "M d",
        strtotime($analytics_date)
    );

    $analytics_accommodation_data[$analytics_date] = 0;
    $analytics_day_visitor_data[$analytics_date] = 0;
}

$analytics_start_date = date(
    "Y-m-d",
    strtotime("-6 days")
);

$analytics_end_date = date("Y-m-d");

$analytics_stmt = $conn->prepare("
    SELECT
        DATE(check_in_time) AS visit_date,

        SUM(
            CASE
                WHEN visit_type = 'Accommodation'
                THEN 1
                ELSE 0
            END
        ) AS accommodation_count,

        SUM(
            CASE
                WHEN visit_type = 'Day Visitor'
                THEN 1
                ELSE 0
            END
        ) AS day_visitor_count

    FROM checkins

    WHERE DATE(check_in_time)
    BETWEEN ? AND ?

    GROUP BY DATE(check_in_time)

    ORDER BY DATE(check_in_time)
");

$analytics_stmt->bind_param(
    "ss",
    $analytics_start_date,
    $analytics_end_date
);

$analytics_stmt->execute();

$analytics_result = $analytics_stmt->get_result();

while ($analytics_row = $analytics_result->fetch_assoc()) {

    $analytics_date = $analytics_row["visit_date"];

    if (isset($analytics_accommodation_data[$analytics_date])) {

        $analytics_accommodation_data[$analytics_date] =
            (int)$analytics_row["accommodation_count"];

        $analytics_day_visitor_data[$analytics_date] =
            (int)$analytics_row["day_visitor_count"];
    }
}

$analytics_stmt->close();


/* =========================
   RESORT VISITOR DATA
========================= */

$analytics_resort_names = [];
$analytics_resort_visitors = [];

$analytics_sql = "
    SELECT
        r.resort_name,
        COUNT(c.id) AS total_visitors

    FROM resorts r

    LEFT JOIN checkins c
        ON r.id = c.resort_id

    GROUP BY r.id, r.resort_name

    ORDER BY total_visitors DESC
    LIMIT 10
";

$analytics_result = $conn->query($analytics_sql);

if ($analytics_result) {

    while ($analytics_row = $analytics_result->fetch_assoc()) {

        $analytics_resort_names[] =
            $analytics_row["resort_name"];

        $analytics_resort_visitors[] =
            (int)$analytics_row["total_visitors"];
    }
}


/* =========================
   PAYMENT ANALYTICS
========================= */

$analytics_total_payments = 0;

$analytics_result = $conn->query("
    SELECT
        COALESCE(SUM(amount), 0) AS total
    FROM payments
");

if ($analytics_result) {

    $analytics_row = $analytics_result->fetch_assoc();

    $analytics_total_payments =
        (float)($analytics_row["total"] ?? 0);
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

    <title>Admin Dashboard - Alapasco EcoKubo</title>
    <link rel="icon" type="image/png" href="logo1.png">

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


        /* =====================================================
           MAIN CONTENT
        ===================================================== */

        .main-content {
            margin-left: 250px;

            width: calc(100% - 250px);

            min-height: 100vh;
        }


        /* =====================================================
           TOPBAR
        ===================================================== */

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


        /* =====================================================
           PAGE
        ===================================================== */

        .page-content {
            padding: 25px;
        }


        .welcome {
            margin-bottom: 25px;
        }


        .welcome h1 {
            margin: 0 0 5px;

            color: #1f5d42;

            font-size: 25px;
        }


        .welcome p {
            margin: 0;

            color: #777;

            font-size: 14px;
        }


        /* =====================================================
           SUMMARY CARDS
        ===================================================== */

        .summary-grid {
            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap: 18px;

            margin-bottom: 25px;
        }


        .summary-card {
            background: white;

            border-radius: 10px;

            padding: 20px;

            border: 1px solid #e1e5e3;

            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }


        .summary-card .icon {
            font-size: 27px;

            margin-bottom: 10px;
        }


        .summary-card h4 {
            margin: 0 0 7px;

            color: #777;

            font-size: 13px;

            font-weight: normal;
        }


        .summary-card .number {
            font-size: 28px;

            font-weight: bold;

            color: #1f5d42;
        }


        /* =====================================================
           TWO COLUMN AREA
        ===================================================== */

        .two-column {
            display: grid;

            grid-template-columns: 1.5fr 1fr;

            gap: 20px;

            margin-bottom: 25px;
        }


        .panel {
            background: white;

            border-radius: 10px;

            border: 1px solid #e1e5e3;

            box-shadow: 0 2px 8px rgba(0,0,0,0.04);

            overflow: hidden;
        }


        .panel-header {
            padding: 17px 20px;

            border-bottom: 1px solid #e5e5e5;

            display: flex;

            justify-content: space-between;

            align-items: center;
        }


        .panel-header h2 {
            margin: 0;

            font-size: 17px;

            color: #1f5d42;
        }


        .panel-header a {
            color: #1f5d42;

            font-size: 12px;

            font-weight: bold;
        }


        /* =====================================================
           VISITOR TABLE
        ===================================================== */

        .table-container {
            overflow-x: auto;
        }


        table {
            width: 100%;

            border-collapse: collapse;

            min-width: 650px;
        }


        th {
            background: #f7f9f8;

            color: #666;

            font-size: 11px;

            text-align: left;

            padding: 12px 15px;

            border-bottom: 1px solid #ddd;
        }


        td {
            padding: 12px 15px;

            font-size: 12px;

            border-bottom: 1px solid #eee;
        }


        tr:last-child td {
            border-bottom: none;
        }


        .visitor-name {
            font-weight: bold;

            color: #333;
        }


        .resort-name {
            color: #666;
        }


        .badge {
            display: inline-block;

            padding: 5px 8px;

            border-radius: 20px;

            font-size: 10px;

            font-weight: bold;
        }


        .badge-accommodation {
            background: #e7f2ec;

            color: #1f5d42;
        }


        .badge-day {
            background: #edf0f2;

            color: #555;
        }


        .badge-active {
            background: #e7f2ec;

            color: #1f5d42;
        }


        .badge-completed {
            background: #eee;

            color: #666;
        }


        /* =====================================================
           RESORT LIST
        ===================================================== */

        .resort-list {
            padding: 5px 0;
        }


        .resort-item {
            padding: 15px 20px;

            border-bottom: 1px solid #eee;
        }


        .resort-item:last-child {
            border-bottom: none;
        }


        .resort-item h3 {
            margin: 0 0 5px;

            font-size: 14px;

            color: #333;
        }


        .resort-item p {
            margin: 0;

            font-size: 11px;

            color: #777;
        }


        .resort-count {
            margin-top: 7px;

            font-size: 11px;

            color: #1f5d42;

            font-weight: bold;
        }


        /* =====================================================
           ACCOMMODATION STATUS
        ===================================================== */

        .status-grid {
            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            gap: 15px;

            padding: 20px;
        }


        .status-card {
            padding: 18px;

            background: #f7f9f8;

            border-radius: 8px;

            text-align: center;
        }


        .status-card strong {
            display: block;

            font-size: 25px;

            color: #1f5d42;

            margin-bottom: 5px;
        }


        .status-card span {
            font-size: 11px;

            color: #777;
        }


        /* =====================================================
           VISITOR SUMMARY
        ===================================================== */

        .visitor-summary {
            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap: 15px;

            padding: 20px;
        }


        .visitor-box {
            background: #f7f9f8;

            border-radius: 8px;

            padding: 18px;
        }


        .visitor-box strong {
            display: block;

            color: #1f5d42;

            font-size: 24px;

            margin-bottom: 5px;
        }


        .visitor-box span {
            font-size: 11px;

            color: #777;
        }


        /* =====================================================
           EMPTY STATE
        ===================================================== */

        .empty {
            padding: 30px 20px;

            text-align: center;

            color: #888;

            font-size: 13px;
        }


        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 1000px) {

            .summary-grid {
                grid-template-columns:
                    repeat(2, 1fr);
            }

            .two-column {
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


            .user-info {
                font-size: 12px;
            }


            .summary-grid {
                grid-template-columns: 1fr;
            }


            .status-grid {
                grid-template-columns: 1fr;
            }
        }

    /* =========================
   SUMMARY
========================= */

.dashboard-analytics .summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin-bottom: 20px;
}

.dashboard-analytics .summary-card {
    background: white;
    border-radius: 10px;
    padding: 18px;
    border: 1px solid #e1e5e3;
}

.dashboard-analytics .summary-card .icon {
    font-size: 23px;
    margin-bottom: 8px;
}

.dashboard-analytics .summary-card .label {
    color: #777;
    font-size: 12px;
    margin-bottom: 5px;
}

.dashboard-analytics .summary-card .value {
    font-size: 24px;
    font-weight: bold;
    color: #1f5d42;
}


/* =========================
   CHARTS
========================= */

.dashboard-analytics .chart-card {
    background: white;
    border-radius: 10px;
    border: 1px solid #e1e5e3;
    padding: 20px;
    margin-bottom: 20px;
}

.dashboard-analytics .chart-header {
    margin-bottom: 15px;
}

.dashboard-analytics .chart-header h3 {
    margin: 0;
    color: #1f5d42;
    font-size: 16px;
}

.dashboard-analytics .chart-header p {
    margin: 5px 0 0;
    color: #888;
    font-size: 11px;
}

.dashboard-analytics .chart-container {
    position: relative;
    height: 340px;
}

.dashboard-analytics .chart-small {
    height: 300px;
}


/* =========================
   TWO COLUMNS
========================= */

.dashboard-analytics .two-column {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}


/* =========================
   PAYMENT
========================= */

.dashboard-analytics .payment-box {
    background: #f5f7f6;
    border-radius: 8px;
    padding: 20px;
    text-align: center;
}

.dashboard-analytics .payment-label {
    color: #777;
    font-size: 12px;
    margin-bottom: 8px;
}

.dashboard-analytics .payment-value {
    font-size: 28px;
    font-weight: bold;
    color: #1f5d42;
}


/* =========================
   MOBILE
========================= */

@media (max-width: 1000px) {

    .summary-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .two-column {
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

    .summary-grid {
        grid-template-columns: 1fr;
    }

    .chart-container {
        height: 280px;
    }
}


.dashboard-analytics { margin-top: 25px; scroll-margin-top: 90px; }
.dashboard-analytics h2 { color: #1f5d42; font-size: 20px; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>


<body>


<div class="dashboard-container">


    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <?php require __DIR__ . '/admin_sidebar.php'; ?>



    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <div class="main-content">


        <!-- TOPBAR -->

        <?php
    $adminHeaderTitle = 'Admin Dashboard';
    $adminHeaderName = $admin_username ?? 'Barangay Admin';
    $adminHeaderRole = 'Barangay Tourism Desk';
    $adminHeaderBackUrl = null;
    require __DIR__ . '/admin_header.php';
    ?>



        <!-- PAGE CONTENT -->

        <div class="page-content">


            <!-- WELCOME -->

            <div class="welcome">

                <h1>
                    Welcome, Admin!
                </h1>

                <p>
                    Monitor tourism accommodations and visitors across Barangay Alapasco.
                </p>

            </div>



            <!-- =================================================
                 SUMMARY CARDS
            ================================================== -->

            <div class="summary-grid">


                <div class="summary-card">

                    <div class="icon">
                        🏡
                    </div>

                    <h4>
                        Registered Resorts
                    </h4>

                    <div class="number">
                        <?php echo $total_resorts; ?>
                    </div>

                </div>


                <div class="summary-card">

                    <div class="icon">
                        🏠
                    </div>

                    <h4>
                        Total Accommodations
                    </h4>

                    <div class="number">
                        <?php echo $total_accommodations; ?>
                    </div>

                </div>


                <div class="summary-card">

                    <div class="icon">
                        👥
                    </div>

                    <h4>
                        Today's Visitors
                    </h4>

                    <div class="number">
                        <?php echo $today_visitors; ?>
                    </div>

                </div>


                <div class="summary-card">

                    <div class="icon">
                        📍
                    </div>

                    <h4>
                        Current Visitors
                    </h4>

                    <div class="number">
                        <?php echo $current_visitors; ?>
                    </div>

                </div>


            </div>



            <!-- =================================================
                 RECENT VISITORS + RESORTS
            ================================================== -->

            <div class="two-column">


                <!-- RECENT VISITORS -->

                <div class="panel">


                    <div class="panel-header">

                        <h2>
                            Recent Visitor Activity
                        </h2>

                        <a href="admin_visitors.php">
                            View All
                        </a>

                    </div>


                    <?php if (count($recent_visitors) > 0): ?>


                        <div class="table-container">

                            <table>

                                <thead>

                                    <tr>

                                        <th>
                                            Visitor
                                        </th>

                                        <th>
                                            Resort
                                        </th>

                                        <th>
                                            Visit Type
                                        </th>

                                        <th>
                                            Accommodation
                                        </th>

                                        <th>
                                            Status
                                        </th>

                                    </tr>

                                </thead>


                                <tbody>


                                <?php foreach ($recent_visitors as $visitor): ?>


                                    <tr>


                                        <td>

                                            <div class="visitor-name">

                                                <?php
                                                echo htmlspecialchars(
                                                    $visitor["full_name"]
                                                );
                                                ?>

                                            </div>

                                            <div style="font-size:10px;color:#888;">

                                                <?php
                                                echo (int)$visitor["number_of_guests"];
                                                ?>
                                                guest(s)

                                            </div>

                                        </td>


                                        <td>

                                            <span class="resort-name">

                                                <?php
                                                echo htmlspecialchars(
                                                    $visitor["resort_name"]
                                                );
                                                ?>

                                            </span>

                                        </td>


                                        <td>


                                            <?php if ($visitor["visit_type"] === "Day Visitor"): ?>

                                                <span class="badge badge-day">
                                                    Day Visitor
                                                </span>

                                            <?php else: ?>

                                                <span class="badge badge-accommodation">
                                                    Accommodation
                                                </span>

                                            <?php endif; ?>


                                        </td>


                                        <td>

                                            <?php if ($visitor["visit_type"] === "Day Visitor"): ?>

                                                <span style="color:#999;">
                                                    N/A
                                                </span>

                                            <?php else: ?>

                                                <?php
                                                echo htmlspecialchars(
                                                    $visitor["accommodation_code"] ?? "N/A"
                                                );
                                                ?>

                                            <?php endif; ?>


                                        </td>


                                        <td>


                                            <?php if ($visitor["status"] === "Active"): ?>

                                                <span class="badge badge-active">
                                                    Active
                                                </span>

                                            <?php else: ?>

                                                <span class="badge badge-completed">
                                                    <?php
                                                    echo htmlspecialchars(
                                                        $visitor["status"]
                                                    );
                                                    ?>
                                                </span>

                                            <?php endif; ?>


                                        </td>


                                    </tr>


                                <?php endforeach; ?>


                                </tbody>

                            </table>

                        </div>


                    <?php else: ?>


                        <div class="empty">

                            No visitor activity yet.

                        </div>


                    <?php endif; ?>


                </div>



                <!-- REGISTERED RESORTS -->

                <div class="panel">


                    <div class="panel-header">

                        <h2>
                            Registered Resorts
                        </h2>

                        <a href="admin_resorts.php">
                            View All
                        </a>

                    </div>


                    <?php if (count($resorts) > 0): ?>


                        <div class="resort-list">


                            <?php foreach ($resorts as $resort): ?>


                                <div class="resort-item">


                                    <h3>

                                        <?php
                                        echo htmlspecialchars(
                                            $resort["resort_name"]
                                        );
                                        ?>

                                    </h3>


                                    <p>
                                        Registered Resort / Rest House
                                    </p>


                                    <div class="resort-count">

                                        🏠
                                        <?php
                                        echo (int)$resort["accommodation_count"];
                                        ?>
                                        accommodation(s)

                                    </div>


                                </div>


                            <?php endforeach; ?>


                        </div>


                    <?php else: ?>


                        <div class="empty">

                            No registered resorts yet.

                        </div>


                    <?php endif; ?>


                </div>


            </div>



            <!-- =================================================
                 ACCOMMODATION STATUS
            ================================================== -->

            <div class="panel" style="margin-bottom:25px;">


                <div class="panel-header">

                    <h2>
                        Accommodation Status
                    </h2>

                    <a href="admin_monitoring.php">
                        Monitoring
                    </a>

                </div>


                <div class="status-grid">


                    <div class="status-card">

                        <strong>
                            <?php
                            echo $available_accommodations;
                            ?>
                        </strong>

                        <span>
                            Available
                        </span>

                    </div>


                    <div class="status-card">

                        <strong>
                            <?php
                            echo $occupied_accommodations;
                            ?>
                        </strong>

                        <span>
                            Occupied
                        </span>

                    </div>


                    <div class="status-card">

                        <strong>
                            <?php
                            echo $reserved_accommodations;
                            ?>
                        </strong>

                        <span>
                            Reserved
                        </span>

                    </div>


                </div>


            </div>



            <!-- =================================================
                 TODAY'S VISITOR SUMMARY
            ================================================== -->

            <div class="panel">


                <div class="panel-header">

                    <h2>
                        Today's Visitor Summary
                    </h2>

                    <a href="#analytics">
                        Analytics
                    </a>

                </div>


                <div class="visitor-summary">


                    <div class="visitor-box">

                        <strong>
                            <?php
                            echo $today_accommodation_visitors;
                            ?>
                        </strong>

                        <span>
                            Accommodation Visitors
                        </span>

                    </div>


                    <div class="visitor-box">

                        <strong>
                            <?php
                            echo $today_day_visitors;
                            ?>
                        </strong>

                        <span>
                            Day Visitors
                        </span>

                    </div>


                </div>


            </div>

<section class="dashboard-analytics" id="analytics" aria-labelledby="analytics-heading">
<h2 id="analytics-heading">Analytics</h2>
<!-- SUMMARY -->

        <div class="summary-grid">


            <div class="summary-card">

                <div class="icon">
                    👥
                </div>

                <div class="label">
                    Total Visits
                </div>

                <div class="value">
                    <?php
                    echo $analytics_total_visits;
                    ?>
                </div>

            </div>


            <div class="summary-card">

                <div class="icon">
                    🏡
                </div>

                <div class="label">
                    Accommodation Visits
                </div>

                <div class="value">
                    <?php
                    echo $analytics_accommodation_visits;
                    ?>
                </div>

            </div>


            <div class="summary-card">

                <div class="icon">
                    👤
                </div>

                <div class="label">
                    Day Visitors
                </div>

                <div class="value">
                    <?php
                    echo $analytics_day_visits;
                    ?>
                </div>

            </div>


            <div class="summary-card">

                <div class="icon">
                    📍
                </div>

                <div class="label">
                    Current Visitors
                </div>

                <div class="value">
                    <?php
                    echo $analytics_current_visitors;
                    ?>
                </div>

            </div>


        </div>


        <!-- VISITOR TREND -->

        <div class="chart-card">

            <div class="chart-header">

                <h3>
                    Visitor Activity — Last 7 Days
                </h3>

                <p>
                    Accommodation visitors compared with day visitors.
                </p>

            </div>


            <div class="chart-container">

                <canvas id="visitorChart"></canvas>

            </div>

        </div>


        <!-- TWO CHARTS -->

        <div class="two-column">


            <div class="chart-card">

                <div class="chart-header">

                    <h3>
                        Visitors by Resort
                    </h3>

                    <p>
                        Top 10 registered resorts by visitor records.
                    </p>

                </div>


                <div class="chart-container chart-small">

                    <canvas id="resortChart"></canvas>

                </div>

            </div>


            <div class="chart-card">

                <div class="chart-header">

                    <h3>
                        Payment Overview
                    </h3>

                    <p>
                        Total recorded payments.
                    </p>

                </div>


                <div class="payment-box">

                    <div class="payment-label">
                        Total Payments Recorded
                    </div>

                    <div class="payment-value">

                        ₱<?php
                        echo number_format(
                            $analytics_total_payments,
                            2
                        );
                        ?>

                    </div>

                </div>


            </div>


        </div>

</section>

        </div>


    </div>


</div>


<script>
if (typeof Chart !== "undefined") {


const visitorLabels = <?php
echo json_encode($analytics_dates, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;

const accommodationData = <?php
echo json_encode(
    array_values($analytics_accommodation_data)
);
?>;

const dayVisitorData = <?php
echo json_encode(
    array_values($analytics_day_visitor_data)
);
?>;


/* =========================
   VISITOR CHART
========================= */

new Chart(
    document.getElementById("visitorChart"),
    {
        type: "line",

        data: {

            labels: visitorLabels,

            datasets: [

                {
                    label: "Accommodation",
                    data: accommodationData,
                    tension: 0.3,
                    borderWidth: 2
                },

                {
                    label: "Day Visitor",
                    data: dayVisitorData,
                    tension: 0.3,
                    borderWidth: 2
                }

            ]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            plugins: {

                legend: {
                    position: "top"
                }

            },

            scales: {

                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }

            }

        }

    }
);


/* =========================
   RESORT CHART
========================= */

const resortLabels = <?php
echo json_encode($analytics_resort_names, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;

const resortData = <?php
echo json_encode($analytics_resort_visitors, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;

new Chart(
    document.getElementById("resortChart"),
    {
        type: "bar",

        data: {

            labels: resortLabels,

            datasets: [

                {
                    label: "Visitors",
                    data: resortData,
                    borderWidth: 1
                }

            ]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            plugins: {

                legend: {
                    display: false
                }

            },

            scales: {

                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                }

            }

        }

    }
);


}
</script>
</body>
</html>