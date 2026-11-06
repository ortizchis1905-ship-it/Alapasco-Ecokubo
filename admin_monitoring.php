<?php

session_start();

require_once "database.php";


/* =========================================================
   ADMIN LOGIN CHECK
========================================================= */

if (!isset($_SESSION["admin_id"])) {

    header("Location: admin_login.php");
    exit;

}


/* =========================================================
   ADMIN NAME
========================================================= */

$admin_name =
    $_SESSION["admin_full_name"]
    ?? $_SESSION["admin_name"]
    ?? $_SESSION["admin_username"]
    ?? "Barangay Admin";


/* =========================================================
   SEARCH AND FILTER
========================================================= */

$search = trim(
    $_GET["search"] ?? ""
);

$visit_type =
    $_GET["visit_type"] ?? "All";


$where = [];

$params = [];

$types = "";


/* Only active visitors */

$where[] = "c.status = 'Active'";


/* Visit type filter */

if (
    $visit_type === "Accommodation"
    ||
    $visit_type === "Day Visitor"
) {

    $where[] =
        "c.visit_type = ?";

    $params[] =
        $visit_type;

    $types .= "s";

}


/* Search filter */

if ($search !== "") {

    $where[] = "
        (
            g.full_name LIKE ?
            OR g.contact_number LIKE ?
            OR r.resort_name LIKE ?
            OR a.accommodation_code LIKE ?
        )
    ";

    $search_value =
        "%" . $search . "%";


    $params[] =
        $search_value;

    $params[] =
        $search_value;

    $params[] =
        $search_value;

    $params[] =
        $search_value;


    $types .= "ssss";

}


/* =========================================================
   WHERE SQL
========================================================= */

$where_sql =
    implode(" AND ", $where);


/* =========================================================
   MONITORING QUERY
========================================================= */

$sql = "

    SELECT

        c.id,
        c.visit_type,
        c.number_of_guests,
        c.check_in_time,

        g.full_name,
        g.contact_number,
        g.address,

        r.resort_name,

        a.accommodation_code

    FROM checkins c

    INNER JOIN guests g
        ON c.guest_id = g.id

    INNER JOIN resorts r
        ON c.resort_id = r.id

    LEFT JOIN accommodations a
        ON c.accommodation_id = a.id

    WHERE $where_sql

    ORDER BY c.check_in_time DESC

";


$stmt =
    $conn->prepare($sql);


$monitoring = [];


if ($stmt) {

    if (!empty($params)) {

        $stmt->bind_param(
            $types,
            ...$params
        );

    }


    $stmt->execute();


    $result =
        $stmt->get_result();


    while (
        $row =
        $result->fetch_assoc()
    ) {

        $monitoring[] =
            $row;

    }


    $stmt->close();

}


/* =========================================================
   SUMMARY
========================================================= */

$total_current = 0;

$total_accommodation = 0;

$total_day_visitors = 0;


$count_sql = "

    SELECT

        COUNT(*) AS total_current,

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

    WHERE status = 'Active'

";


$count_result =
    $conn->query($count_sql);


if ($count_result) {

    $count_data =
        $count_result->fetch_assoc();


    $total_current =
        (int)(
            $count_data["total_current"]
            ?? 0
        );


    $total_accommodation =
        (int)(
            $count_data["accommodation_count"]
            ?? 0
        );


    $total_day_visitors =
        (int)(
            $count_data["day_visitor_count"]
            ?? 0
        );

}


/* =========================================================
   OCCUPIED ACCOMMODATIONS
========================================================= */

$occupied_accommodations = 0;


$occupied_sql = "

    SELECT
        COUNT(*) AS total

    FROM accommodations

    WHERE status = 'Occupied'

";


$occupied_result =
    $conn->query(
        $occupied_sql
    );


if ($occupied_result) {

    $occupied_data =
        $occupied_result->fetch_assoc();


    $occupied_accommodations =
        (int)(
            $occupied_data["total"]
            ?? 0
        );

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
    Admin Monitoring | Alapasco EcoKubo
</title>


<style>

/* =========================================================
   BASIC
========================================================= */

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

.main-content {

    margin-left: 250px;

    width:
        calc(100% - 250px);

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


/* =========================================================
   PAGE CONTENT
========================================================= */

.page-content {

    padding: 25px;

}


/* =========================================================
   SUMMARY
========================================================= */

.summary-grid {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 15px;

    margin-bottom: 20px;

}


.summary-card {

    background: white;

    border-radius: 10px;

    padding: 18px;

    border:
        1px solid #e1e5e3;

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


/* =========================================================
   FILTER
========================================================= */

.filter-card {

    background: white;

    padding: 18px;

    border-radius: 10px;

    border:
        1px solid #e1e5e3;

    margin-bottom: 20px;

}


.filter-form {

    display: grid;

    grid-template-columns:
        1fr 200px 110px;

    gap: 10px;

}


.filter-form input,
.filter-form select {

    width: 100%;

    padding: 11px 12px;

    border:
        1px solid #ccc;

    border-radius: 6px;

    font-size: 13px;

}


.filter-form button {

    border: none;

    background: #1f5d42;

    color: white;

    border-radius: 6px;

    cursor: pointer;

    font-size: 13px;

}


.filter-form button:hover {

    background: #174932;

}


/* =========================================================
   TABLE
========================================================= */

.table-card {

    background: white;

    border-radius: 10px;

    border:
        1px solid #e1e5e3;

    overflow: hidden;

}


.table-header {

    padding: 18px;

    border-bottom:
        1px solid #e5e5e5;

}


.table-header h3 {

    margin: 0;

    color: #1f5d42;

    font-size: 16px;

}


.table-wrapper {

    overflow-x: auto;

}


table {

    width: 100%;

    border-collapse: collapse;

    min-width: 850px;

}


th {

    background: #f5f7f6;

    color: #555;

    font-size: 12px;

    text-align: left;

    padding: 13px;

    border-bottom:
        1px solid #ddd;

}


td {

    padding: 13px;

    font-size: 13px;

    border-bottom:
        1px solid #eee;

}


tr:hover {

    background: #fafafa;

}


.visitor-name {

    font-weight: bold;

    color: #333;

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


.empty {

    text-align: center;

    padding: 40px 20px;

    color: #777;

    font-size: 13px;

}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1000px) {

    .summary-grid {

        grid-template-columns:
            repeat(2, 1fr);

    }

}


@media (max-width: 900px) {

    .filter-form {

        grid-template-columns:
            1fr;

    }

    .filter-form button {

        padding: 11px;

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

        padding:
            12px 15px;

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

}

</style>

</head>


<body>


<!-- =========================================================
     STANDARD ADMIN SIDEBAR
========================================================= -->

<?php require __DIR__ . '/admin_sidebar.php'; ?>


<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<div class="main-content">


    <!-- TOPBAR -->

    <?php
    $adminHeaderTitle = 'Monitoring';
    $adminHeaderName = $admin_name ?? 'Barangay Admin';
    $adminHeaderRole = 'Barangay Tourism Desk';
    $adminHeaderBackUrl = null;
    require __DIR__ . '/admin_header.php';
    ?>


    <!-- PAGE CONTENT -->

    <div class="page-content">


        <!-- =================================================
             SUMMARY
        ================================================== -->

        <div class="summary-grid">


            <div class="summary-card">


                <div class="icon">
                    📍
                </div>


                <div class="label">
                    Current Visitors
                </div>


                <div class="value">

                    <?php

                    echo $total_current;

                    ?>

                </div>


            </div>


            <div class="summary-card">


                <div class="icon">
                    🏡
                </div>


                <div class="label">
                    Accommodation Visitors
                </div>


                <div class="value">

                    <?php

                    echo $total_accommodation;

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

                    echo $total_day_visitors;

                    ?>

                </div>


            </div>


            <div class="summary-card">


                <div class="icon">
                    🏠
                </div>


                <div class="label">
                    Occupied Accommodations
                </div>


                <div class="value">

                    <?php

                    echo $occupied_accommodations;

                    ?>

                </div>


            </div>


        </div>


        <!-- =================================================
             FILTER
        ================================================== -->

        <div class="filter-card">


            <form
                method="GET"
                class="filter-form"
            >


                <input
                    type="text"
                    name="search"
                    placeholder="Search visitor, contact, resort or accommodation..."
                    value="<?php echo htmlspecialchars($search); ?>"
                >


                <select name="visit_type">


                    <option
                        value="All"
                        <?php

                        echo $visit_type === "All"
                            ? "selected"
                            : "";

                        ?>
                    >
                        All Visit Types
                    </option>


                    <option
                        value="Accommodation"
                        <?php

                        echo $visit_type === "Accommodation"
                            ? "selected"
                            : "";

                        ?>
                    >
                        Accommodation
                    </option>


                    <option
                        value="Day Visitor"
                        <?php

                        echo $visit_type === "Day Visitor"
                            ? "selected"
                            : "";

                        ?>
                    >
                        Day Visitor
                    </option>


                </select>


                <button type="submit">
                    Search
                </button>


            </form>


        </div>


        <!-- =================================================
             CURRENT VISITORS TABLE
        ================================================== -->

        <div class="table-card">


            <div class="table-header">


                <h3>
                    Currently Active Visitors
                </h3>


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


                    <?php if (empty($monitoring)): ?>


                        <tr>


                            <td
                                colspan="7"
                                class="empty"
                            >

                                📍 No active visitors found.

                            </td>


                        </tr>


                    <?php else: ?>


                        <?php foreach (
                            $monitoring
                            as $row
                        ): ?>


                            <tr>


                                <!-- VISITOR -->

                                <td>


                                    <span
                                        class="visitor-name"
                                    >

                                        <?php

                                        echo htmlspecialchars(
                                            $row["full_name"]
                                        );

                                        ?>

                                    </span>


                                    <span
                                        class="small-text"
                                    >

                                        <?php

                                        echo htmlspecialchars(
                                            $row["contact_number"]
                                        );

                                        ?>

                                    </span>


                                </td>


                                <!-- RESORT -->

                                <td>

                                    <?php

                                    echo htmlspecialchars(
                                        $row["resort_name"]
                                    );

                                    ?>

                                </td>


                                <!-- VISIT TYPE -->

                                <td>


                                    <?php

                                    if (
                                        $row["visit_type"]
                                        === "Day Visitor"
                                    ):

                                    ?>


                                        <span
                                            class="badge badge-day"
                                        >
                                            Day Visitor
                                        </span>


                                    <?php else: ?>


                                        <span
                                            class="badge badge-accommodation"
                                        >
                                            Accommodation
                                        </span>


                                    <?php endif; ?>


                                </td>


                                <!-- GUESTS -->

                                <td>

                                    <?php

                                    echo (int)
                                        $row["number_of_guests"];

                                    ?>

                                </td>


                                <!-- ACCOMMODATION -->

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


                                <!-- CHECK IN -->

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


                                <!-- STATUS -->

                                <td>


                                    <span
                                        class="badge badge-active"
                                    >
                                        Active
                                    </span>


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