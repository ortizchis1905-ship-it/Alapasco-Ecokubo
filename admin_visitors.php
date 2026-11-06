<?php

session_start();

require_once "database.php";


/*
|--------------------------------------------------------------------------
| ADMIN ACCESS
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

$admin_full_name =
    $_SESSION["admin_full_name"]
    ?? $_SESSION["admin_username"]
    ?? "Administrator";


/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$search = trim($_GET["search"] ?? "");

$visit_type = $_GET["visit_type"] ?? "All";

$status = $_GET["status"] ?? "All";


/*
|--------------------------------------------------------------------------
| BUILD QUERY
|--------------------------------------------------------------------------
*/

$where = [];

$params = [];

$types = "";


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

if ($search !== "") {

    $where[] = "
        (
            g.full_name LIKE ?
            OR g.contact_number LIKE ?
            OR r.resort_name LIKE ?
            OR a.accommodation_code LIKE ?
        )
    ";

    $search_value = "%" . $search . "%";

    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;

    $types .= "ssss";
}


/*
|--------------------------------------------------------------------------
| VISIT TYPE FILTER
|--------------------------------------------------------------------------
*/

if (
    $visit_type === "Accommodation"
    || $visit_type === "Day Visitor"
) {

    $where[] = "c.visit_type = ?";

    $params[] = $visit_type;

    $types .= "s";
}


/*
|--------------------------------------------------------------------------
| STATUS FILTER
|--------------------------------------------------------------------------
*/

if (
    $status === "Active"
    || $status === "Completed"
) {

    $where[] = "c.status = ?";

    $params[] = $status;

    $types .= "s";
}


/*
|--------------------------------------------------------------------------
| WHERE SQL
|--------------------------------------------------------------------------
*/

$where_sql = "";

if (count($where) > 0) {

    $where_sql =
        "WHERE " . implode(" AND ", $where);
}


/*
|--------------------------------------------------------------------------
| GET VISITORS
|--------------------------------------------------------------------------
*/

$visitors = [];


$sql = "
    SELECT

        c.id,
        c.visit_type,
        c.check_in_time,
        c.status,

        g.full_name,
        g.contact_number,
        g.address,
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

    $where_sql

    ORDER BY c.check_in_time DESC
";


$stmt = $conn->prepare($sql);


if ($stmt) {

    if (count($params) > 0) {

        $stmt->bind_param(
            $types,
            ...$params
        );
    }

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $visitors[] = $row;
    }

    $stmt->close();
}


/*
|--------------------------------------------------------------------------
| SUMMARY COUNTS
|--------------------------------------------------------------------------
*/

$total_visitors = count($visitors);

$active_visitors = 0;

$accommodation_visitors = 0;

$day_visitors = 0;


foreach ($visitors as $visitor) {

    if ($visitor["status"] === "Active") {

        $active_visitors++;
    }

    if (
        $visitor["visit_type"]
        === "Accommodation"
    ) {

        $accommodation_visitors++;

    } elseif (
        $visitor["visit_type"]
        === "Day Visitor"
    ) {

        $day_visitors++;
    }
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

    <title>
        Visitors - Admin
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


        /* =====================================================
           MAIN
        ===================================================== */

        .main-content {
            margin-left: 250px;

            width:
                calc(100% - 250px);

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

            border-bottom:
                1px solid #ddd;

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


        .page-title {
            margin-bottom: 20px;
        }


        .page-title h1 {
            margin: 0 0 5px;

            color: #1f5d42;

            font-size: 25px;
        }


        .page-title p {
            margin: 0;

            color: #777;

            font-size: 14px;
        }


        /* =====================================================
           SUMMARY
        ===================================================== */

        .summary-grid {
            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap: 18px;

            margin-bottom: 20px;
        }


        .summary-card {
            background: white;

            border:
                1px solid #e1e5e3;

            border-radius: 10px;

            padding: 18px;

            box-shadow:
                0 2px 8px
                rgba(0,0,0,0.04);
        }


        .summary-card .icon {
            font-size: 25px;

            margin-bottom: 8px;
        }


        .summary-card h4 {
            margin: 0 0 5px;

            color: #777;

            font-size: 12px;

            font-weight: normal;
        }


        .summary-card strong {
            color: #1f5d42;

            font-size: 27px;
        }


        /* =====================================================
           FILTERS
        ===================================================== */

        .filter-box {
            background: white;

            border:
                1px solid #e1e5e3;

            border-radius: 10px;

            padding: 18px;

            margin-bottom: 20px;
        }


        .filter-form {
            display: grid;

            grid-template-columns:
                2fr 1fr 1fr auto;

            gap: 10px;

            align-items: end;
        }


        .filter-group label {
            display: block;

            margin-bottom: 6px;

            font-size: 11px;

            color: #666;

            font-weight: bold;
        }


        .filter-group input,
        .filter-group select {
            width: 100%;

            padding: 10px 11px;

            border:
                1px solid #ccc;

            border-radius: 6px;

            font-size: 12px;

            background: white;

            outline: none;
        }


        .filter-group input:focus,
        .filter-group select:focus {
            border-color: #1f5d42;
        }


        .filter-button {
            padding: 10px 18px;

            border: none;

            border-radius: 6px;

            background: #1f5d42;

            color: white;

            font-size: 12px;

            font-weight: bold;

            cursor: pointer;
        }


        .filter-button:hover {
            background: #174a34;
        }


        .clear-button {
            display: inline-block;

            margin-top: 10px;

            color: #1f5d42;

            font-size: 11px;

            font-weight: bold;
        }


        /* =====================================================
           TABLE
        ===================================================== */

        .panel {
            background: white;

            border:
                1px solid #e1e5e3;

            border-radius: 10px;

            overflow: hidden;

            box-shadow:
                0 2px 8px
                rgba(0,0,0,0.04);
        }


        .panel-header {
            padding: 17px 20px;

            border-bottom:
                1px solid #e5e5e5;

            display: flex;

            justify-content: space-between;

            align-items: center;
        }


        .panel-header h2 {
            margin: 0;

            color: #1f5d42;

            font-size: 17px;
        }


        .panel-header span {
            color: #777;

            font-size: 12px;
        }


        .table-container {
            overflow-x: auto;
        }


        table {
            width: 100%;

            border-collapse: collapse;

            min-width: 950px;
        }


        th {
            background: #f7f9f8;

            color: #666;

            font-size: 11px;

            text-align: left;

            padding: 13px 15px;

            border-bottom:
                1px solid #ddd;
        }


        td {
            padding: 13px 15px;

            font-size: 12px;

            border-bottom:
                1px solid #eee;

            vertical-align: middle;
        }


        tr:last-child td {
            border-bottom: none;
        }


        .visitor-name {
            font-weight: bold;

            color: #333;

            margin-bottom: 3px;
        }


        .contact {
            color: #888;

            font-size: 10px;
        }


        .resort-name {
            color: #555;

            font-size: 12px;
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
            background: #eeeeee;

            color: #666;
        }


        .time {
            color: #555;

            font-size: 11px;

            line-height: 1.5;
        }


        .empty {
            padding: 45px 20px;

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


            .filter-form {
                grid-template-columns:
                    1fr 1fr;
            }
        }


        @media (max-width: 700px) {


            .main-content {
                margin-left: 200px;

                width:
                    calc(100% - 200px);
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


            .filter-form {
                grid-template-columns: 1fr;
            }

        }

    </style>

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
    $adminHeaderTitle = 'Visitors';
    $adminHeaderName = $admin_full_name ?? 'Barangay Admin';
    $adminHeaderRole = 'Barangay Tourism Desk';
    $adminHeaderBackUrl = null;
    require __DIR__ . '/admin_header.php';
    ?>



        <!-- PAGE CONTENT -->

        <div class="page-content">


            <!-- PAGE TITLE -->

            <div class="page-title">

                <h1>
                    Visitors
                </h1>

                <p>
                    Monitor visitor records from all registered resorts and rest houses.
                </p>

            </div>



            <!-- =================================================
                 SUMMARY
            ================================================== -->

            <div class="summary-grid">


                <div class="summary-card">

                    <div class="icon">
                        👥
                    </div>

                    <h4>
                        Total Records
                    </h4>

                    <strong>
                        <?php
                        echo $total_visitors;
                        ?>
                    </strong>

                </div>


                <div class="summary-card">

                    <div class="icon">
                        📍
                    </div>

                    <h4>
                        Current Visitors
                    </h4>

                    <strong>
                        <?php
                        echo $active_visitors;
                        ?>
                    </strong>

                </div>


                <div class="summary-card">

                    <div class="icon">
                        🏡
                    </div>

                    <h4>
                        Accommodation
                    </h4>

                    <strong>
                        <?php
                        echo $accommodation_visitors;
                        ?>
                    </strong>

                </div>


                <div class="summary-card">

                    <div class="icon">
                        ☀️
                    </div>

                    <h4>
                        Day Visitors
                    </h4>

                    <strong>
                        <?php
                        echo $day_visitors;
                        ?>
                    </strong>

                </div>


            </div>



            <!-- =================================================
                 FILTERS
            ================================================== -->

            <div class="filter-box">


                <form
                    method="GET"
                    action="admin_visitors.php"
                    class="filter-form"
                >


                    <div class="filter-group">

                        <label>
                            Search
                        </label>

                        <input
                            type="text"
                            name="search"
                            placeholder="Name, contact, resort, accommodation..."
                            value="<?php
                            echo htmlspecialchars($search);
                            ?>"
                        >

                    </div>


                    <div class="filter-group">

                        <label>
                            Visit Type
                        </label>

                        <select name="visit_type">

                            <option value="All"
                                <?php
                                echo $visit_type === "All"
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                All Visit Types
                            </option>

                            <option value="Accommodation"
                                <?php
                                echo $visit_type === "Accommodation"
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                Accommodation
                            </option>

                            <option value="Day Visitor"
                                <?php
                                echo $visit_type === "Day Visitor"
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                Day Visitor
                            </option>

                        </select>

                    </div>


                    <div class="filter-group">

                        <label>
                            Status
                        </label>

                        <select name="status">

                            <option value="All"
                                <?php
                                echo $status === "All"
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                All Status
                            </option>

                            <option value="Active"
                                <?php
                                echo $status === "Active"
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                Active
                            </option>

                            <option value="Completed"
                                <?php
                                echo $status === "Completed"
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                Completed
                            </option>

                        </select>

                    </div>


                    <button
                        type="submit"
                        class="filter-button"
                    >
                        🔍 Filter
                    </button>


                </form>


                <?php

                if (
                    $search !== ""
                    || $visit_type !== "All"
                    || $status !== "All"
                ):

                ?>

                    <a
                        href="admin_visitors.php"
                        class="clear-button"
                    >
                        Clear Filters
                    </a>

                <?php endif; ?>


            </div>



            <!-- =================================================
                 VISITOR TABLE
            ================================================== -->

            <div class="panel">


                <div class="panel-header">


                    <h2>
                        Visitor Records
                    </h2>


                    <span>

                        <?php
                        echo count($visitors);
                        ?>

                        record(s)

                    </span>


                </div>


                <?php if (count($visitors) > 0): ?>


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


                            <?php foreach ($visitors as $visitor): ?>


                                <tr>


                                    <!-- VISITOR -->

                                    <td>

                                        <div class="visitor-name">

                                            <?php
                                            echo htmlspecialchars(
                                                $visitor["full_name"]
                                            );
                                            ?>

                                        </div>


                                        <div class="contact">

                                            <?php
                                            echo htmlspecialchars(
                                                $visitor["contact_number"]
                                            );
                                            ?>

                                        </div>

                                    </td>



                                    <!-- RESORT -->

                                    <td>

                                        <div class="resort-name">

                                            <?php
                                            echo htmlspecialchars(
                                                $visitor["resort_name"]
                                            );
                                            ?>

                                        </div>

                                    </td>



                                    <!-- VISIT TYPE -->

                                    <td>


                                        <?php
                                        if (
                                            $visitor["visit_type"]
                                            === "Accommodation"
                                        ):
                                        ?>

                                            <span
                                                class="badge badge-accommodation"
                                            >
                                                Accommodation
                                            </span>

                                        <?php else: ?>

                                            <span
                                                class="badge badge-day"
                                            >
                                                Day Visitor
                                            </span>

                                        <?php endif; ?>


                                    </td>



                                    <!-- NUMBER OF GUESTS -->

                                    <td>

                                        <?php
                                        echo (int)
                                            $visitor[
                                                "number_of_guests"
                                            ];
                                        ?>

                                    </td>



                                    <!-- ACCOMMODATION -->

                                    <td>

                                        <?php
                                        if (
                                            $visitor["visit_type"]
                                            === "Day Visitor"
                                        ):
                                        ?>

                                            <span
                                                style="color:#999;"
                                            >
                                                N/A
                                            </span>

                                        <?php else: ?>

                                            <?php
                                            echo htmlspecialchars(
                                                $visitor[
                                                    "accommodation_code"
                                                ]
                                                ?? "N/A"
                                            );
                                            ?>

                                        <?php endif; ?>


                                    </td>



                                    <!-- CHECK-IN -->

                                    <td>

                                        <div class="time">

                                            <?php

                                            echo date(
                                                "M d, Y",
                                                strtotime(
                                                    $visitor[
                                                        "check_in_time"
                                                    ]
                                                )
                                            );

                                            ?>

                                            <br>

                                            <?php

                                            echo date(
                                                "h:i A",
                                                strtotime(
                                                    $visitor[
                                                        "check_in_time"
                                                    ]
                                                )
                                            );

                                            ?>

                                        </div>

                                    </td>



                                    <!-- STATUS -->

                                    <td>


                                        <?php
                                        if (
                                            $visitor["status"]
                                            === "Active"
                                        ):
                                        ?>

                                            <span
                                                class="badge badge-active"
                                            >
                                                Active
                                            </span>

                                        <?php else: ?>

                                            <span
                                                class="badge badge-completed"
                                            >

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

                        <?php if (
                            $search !== ""
                            || $visit_type !== "All"
                            || $status !== "All"
                        ): ?>

                            No visitors match the selected filters.

                        <?php else: ?>

                            No visitor records yet.

                        <?php endif; ?>

                    </div>


                <?php endif; ?>


            </div>


        </div>


    </div>


</div>


</body>

</html>