<?php
session_start();
require_once "database.php";

if (!isset($_SESSION["admin_id"])) {
    header("Location: admin_login.php");
    exit;
}

$admin_name = $_SESSION["admin_full_name"] ?? "Barangay Admin";

$today = date("Y-m-d");

$date_from = $_GET["date_from"] ?? date("Y-m-01");
$date_to   = $_GET["date_to"] ?? $today;


/* =========================
   TOTAL RESORTS
========================= */

$total_resorts = 0;

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM resorts
");

if ($result) {
    $row = $result->fetch_assoc();
    $total_resorts = (int)($row["total"] ?? 0);
}


/* =========================
   TOTAL ACCOMMODATIONS
========================= */

$total_accommodations = 0;

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM accommodations
");

if ($result) {
    $row = $result->fetch_assoc();
    $total_accommodations = (int)($row["total"] ?? 0);
}


/* =========================
   CURRENT VISITORS
========================= */

$current_visitors = 0;

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM checkins
    WHERE status = 'Active'
");

if ($result) {
    $row = $result->fetch_assoc();
    $current_visitors = (int)($row["total"] ?? 0);
}


/* =========================
   DATE SUMMARY
========================= */

$total_visits = 0;
$accommodation_visits = 0;
$day_visits = 0;

$stmt = $conn->prepare("
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

    WHERE DATE(check_in_time)
    BETWEEN ? AND ?
");

$stmt->bind_param(
    "ss",
    $date_from,
    $date_to
);

$stmt->execute();

$result = $stmt->get_result();

if ($result) {

    $row = $result->fetch_assoc();

    $total_visits =
        (int)($row["total_visits"] ?? 0);

    $accommodation_visits =
        (int)($row["accommodation_visits"] ?? 0);

    $day_visits =
        (int)($row["day_visits"] ?? 0);
}

$stmt->close();


/* =========================
   VISITOR REPORT
========================= */

$visitor_report = [];

$stmt = $conn->prepare("
    SELECT
        c.check_in_time,
        c.visit_type,
        c.number_of_guests,
        c.status,

        g.full_name,
        g.contact_number,

        r.resort_name,

        a.accommodation_code

    FROM checkins c

    INNER JOIN guests g
        ON c.guest_id = g.id

    INNER JOIN resorts r
        ON c.resort_id = r.id

    LEFT JOIN accommodations a
        ON c.accommodation_id = a.id

    WHERE DATE(c.check_in_time)
    BETWEEN ? AND ?

    ORDER BY c.check_in_time DESC
");

$stmt->bind_param(
    "ss",
    $date_from,
    $date_to
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $visitor_report[] = $row;
}

$stmt->close();


/* =========================
   RESORT SUMMARY
========================= */

$resort_report = [];

$stmt = $conn->prepare("
    SELECT
        r.id,
        r.resort_name,

        COUNT(c.id) AS total_visitors,

        SUM(
            CASE
                WHEN c.visit_type = 'Accommodation'
                THEN 1
                ELSE 0
            END
        ) AS accommodation_visitors,

        SUM(
            CASE
                WHEN c.visit_type = 'Day Visitor'
                THEN 1
                ELSE 0
            END
        ) AS day_visitors

    FROM resorts r

    LEFT JOIN checkins c
        ON r.id = c.resort_id

        AND DATE(c.check_in_time)
        BETWEEN ? AND ?

    GROUP BY r.id, r.resort_name

    ORDER BY total_visitors DESC,
             r.resort_name ASC
");

$stmt->bind_param(
    "ss",
    $date_from,
    $date_to
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $resort_report[] = $row;
}

$stmt->close();

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="logo1.png">

<title>Admin Reports</title>

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


/* =========================
   MAIN
========================= */

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


/* =========================
   DATE FILTER
========================= */

.filter-card {
    background: white;
    padding: 18px;
    border-radius: 10px;
    border: 1px solid #e1e5e3;
    margin-bottom: 20px;
}

.filter-title {
    margin: 0 0 12px;
    color: #1f5d42;
    font-size: 15px;
}

.filter-form {
    display: grid;
    grid-template-columns: 1fr 1fr 110px;
    gap: 10px;
}

.filter-form label {
    display: block;
    font-size: 11px;
    color: #777;
    margin-bottom: 5px;
}

.filter-form input {
    width: 100%;
    padding: 10px;
    border: 1px solid #ccc;
    border-radius: 6px;
}

.filter-form button {
    border: none;
    background: #1f5d42;
    color: white;
    border-radius: 6px;
    cursor: pointer;
    margin-top: 17px;
}

.filter-form button:hover {
    background: #174932;
}


/* =========================
   SUMMARY
========================= */

.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 15px;
    margin-bottom: 20px;
}

.summary-card {
    background: white;
    border-radius: 10px;
    padding: 18px;
    border: 1px solid #e1e5e3;
}

.summary-card .icon {
    font-size: 24px;
    margin-bottom: 8px;
}

.summary-card .label {
    color: #777;
    font-size: 12px;
    margin-bottom: 5px;
}

.summary-card .value {
    font-size: 25px;
    font-weight: bold;
    color: #1f5d42;
}


/* =========================
   REPORT CARDS
========================= */

.report-card {
    background: white;
    border-radius: 10px;
    border: 1px solid #e1e5e3;
    margin-bottom: 20px;
    overflow: hidden;
}

.report-header {
    padding: 18px;
    border-bottom: 1px solid #e5e5e5;
}

.report-header h3 {
    margin: 0;
    color: #1f5d42;
    font-size: 16px;
}

.report-header p {
    margin: 5px 0 0;
    font-size: 11px;
    color: #888;
}


/* =========================
   TABLE
========================= */

.table-wrapper {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
}

th {
    background: #f5f7f6;
    color: #555;
    font-size: 12px;
    text-align: left;
    padding: 13px;
    border-bottom: 1px solid #ddd;
}

td {
    padding: 13px;
    font-size: 13px;
    border-bottom: 1px solid #eee;
}

tr:hover {
    background: #fafafa;
}

.visitor-name {
    font-weight: bold;
}

.small-text {
    display: block;
    color: #888;
    font-size: 11px;
    margin-top: 3px;
}

.badge {
    display: inline-block;
    padding: 5px 8px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: bold;
}

.badge-accommodation {
    background: #e2f0e8;
    color: #1f5d42;
}

.badge-day {
    background: #e8eef8;
    color: #315b91;
}

.badge-active {
    background: #e3f4e8;
    color: #22723d;
}

.badge-completed {
    background: #eee;
    color: #666;
}

.empty {
    text-align: center;
    padding: 40px;
    color: #777;
}


/* =========================
   MOBILE
========================= */

@media (max-width: 900px) {

    .summary-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .filter-form {
        grid-template-columns: 1fr;
    }

    .filter-form button {
        padding: 11px;
        margin-top: 0;
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
}

</style>

</head>


<body>


<!-- SIDEBAR -->

<?php require __DIR__ . '/admin_sidebar.php'; ?>


<!-- MAIN CONTENT -->

<div class="main-content" id="reports">


    <?php
    $adminHeaderTitle = 'Reports';
    $adminHeaderName = $admin_name ?? 'Barangay Admin';
    $adminHeaderRole = 'Barangay Tourism Desk';
    $adminHeaderBackUrl = null;
    require __DIR__ . '/admin_header.php';
    ?>


    <div class="page-content">


        <!-- DATE FILTER -->

        <div class="filter-card">

            <h3 class="filter-title">
                Report Period
            </h3>

            <form method="GET"
                  class="filter-form">

                <div>

                    <label>
                        From
                    </label>

                    <input
                        type="date"
                        name="date_from"
                        value="<?php
                        echo htmlspecialchars($date_from);
                        ?>"
                    >

                </div>


                <div>

                    <label>
                        To
                    </label>

                    <input
                        type="date"
                        name="date_to"
                        value="<?php
                        echo htmlspecialchars($date_to);
                        ?>"
                    >

                </div>


                <button type="submit">
                    Generate
                </button>

            </form>

        </div>


        <!-- SUMMARY -->

        <div class="summary-grid">


            <div class="summary-card">

                <div class="icon">
                    🏡
                </div>

                <div class="label">
                    Registered Resorts
                </div>

                <div class="value">
                    <?php
                    echo $total_resorts;
                    ?>
                </div>

            </div>


            <div class="summary-card">

                <div class="icon">
                    🏠
                </div>

                <div class="label">
                    Total Accommodations
                </div>

                <div class="value">
                    <?php
                    echo $total_accommodations;
                    ?>
                </div>

            </div>


            <div class="summary-card">

                <div class="icon">
                    👥
                </div>

                <div class="label">
                    Total Visits
                </div>

                <div class="value">
                    <?php
                    echo $total_visits;
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
                    echo $current_visitors;
                    ?>
                </div>

            </div>


        </div>


        <!-- VISIT TYPE SUMMARY -->

        <div class="report-card">

            <div class="report-header">

                <h3>
                    Visitor Summary
                </h3>

                <p>
                    Visitor records for the selected period
                </p>

            </div>


            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>

                            <th>
                                Total Visits
                            </th>

                            <th>
                                Accommodation Visitors
                            </th>

                            <th>
                                Day Visitors
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <tr>

                            <td>
                                <?php
                                echo $total_visits;
                                ?>
                            </td>

                            <td>
                                <?php
                                echo $accommodation_visits;
                                ?>
                            </td>

                            <td>
                                <?php
                                echo $day_visits;
                                ?>
                            </td>

                        </tr>

                    </tbody>

                </table>

            </div>

        </div>


        <!-- RESORT REPORT -->

        <div class="report-card">

            <div class="report-header">

                <h3>
                    Resort Visitor Summary
                </h3>

                <p>
                    Visitor activity by registered resort
                </p>

            </div>


            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>

                            <th>
                                Resort
                            </th>

                            <th>
                                Total Visitors
                            </th>

                            <th>
                                Accommodation
                            </th>

                            <th>
                                Day Visitors
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                    <?php if (empty($resort_report)): ?>

                        <tr>

                            <td colspan="4"
                                class="empty">

                                No resort records found.

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($resort_report as $row): ?>

                            <tr>

                                <td>

                                    <strong>
                                        <?php
                                        echo htmlspecialchars(
                                            $row["resort_name"]
                                        );
                                        ?>
                                    </strong>

                                </td>

                                <td>
                                    <?php
                                    echo (int)$row["total_visitors"];
                                    ?>
                                </td>

                                <td>
                                    <?php
                                    echo (int)$row["accommodation_visitors"];
                                    ?>
                                </td>

                                <td>
                                    <?php
                                    echo (int)$row["day_visitors"];
                                    ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>


        <!-- VISITOR REPORT -->

        <div class="report-card">

            <div class="report-header">

                <h3>
                    Visitor Records
                </h3>

                <p>
                    Detailed visitor activity for the selected period
                </p>

            </div>


            <div class="table-wrapper">

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
                                Guests
                            </th>

                            <th>
                                Accommodation
                            </th>

                            <th>
                                Check-In
                            </th>

                            <th>
                                Status
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php if (empty($visitor_report)): ?>

                        <tr>

                            <td colspan="7"
                                class="empty">

                                No visitor records found
                                for the selected period.

                            </td>

                        </tr>

                    <?php else: ?>


                        <?php foreach ($visitor_report as $row): ?>

                            <tr>


                                <td>

                                    <span class="visitor-name">

                                        <?php
                                        echo htmlspecialchars(
                                            $row["full_name"]
                                        );
                                        ?>

                                    </span>

                                    <span class="small-text">

                                        <?php
                                        echo htmlspecialchars(
                                            $row["contact_number"]
                                        );
                                        ?>

                                    </span>

                                </td>


                                <td>

                                    <?php
                                    echo htmlspecialchars(
                                        $row["resort_name"]
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

                                    <?php
                                    echo (int)
                                        $row["number_of_guests"];
                                    ?>

                                </td>


                                <td>

                                    <?php
                                    if (
                                        $row["visit_type"]
                                        === "Day Visitor"
                                    ):
                                    ?>

                                        N/A

                                    <?php else: ?>

                                        <?php
                                        echo htmlspecialchars(
                                            $row["accommodation_code"]
                                            ?? "N/A"
                                        );
                                        ?>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?php
                                    echo date(
                                        "M d, Y h:i A",
                                        strtotime(
                                            $row["check_in_time"]
                                        )
                                    );
                                    ?>

                                </td>


                                <td>

                                    <?php
                                    if (
                                        $row["status"]
                                        === "Active"
                                    ):
                                    ?>

                                        <span class="badge badge-active">
                                            Active
                                        </span>

                                    <?php else: ?>

                                        <span class="badge badge-completed">
                                            <?php
                                            echo htmlspecialchars(
                                                $row["status"]
                                            );
                                            ?>
                                        </span>

                                    <?php endif; ?>

                                </td>


                            </tr>

                        <?php endforeach; ?>


                    <?php endif; ?>


                    </tbody>

                </table>

            </div>

        </div>


    </div>

</div>


</body>

</html>