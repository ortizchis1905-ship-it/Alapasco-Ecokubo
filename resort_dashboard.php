<?php

session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: resort_login.php");
    exit;
}

require_once "database.php";


/* ==================================================
   SESSION INFORMATION
================================================== */

$user_id = (int)$_SESSION["user_id"];

$resort_id = (int)($_SESSION["resort_id"] ?? 0);

$resort_name = $_SESSION["resort_name"] ?? "My Resort";

$full_name = $_SESSION["full_name"] ?? "User";

$email = $_SESSION["email"] ?? "";

$profile_picture = "";


/* ==================================================
   GET RESORT INFORMATION
================================================== */

if (!$resort_id) {

    $stmt = $conn->prepare("
        SELECT
            id,
            resort_name,
            profile_picture
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

            $resort_id = (int)$row["id"];

            if (!empty($row["resort_name"])) {
                $resort_name = $row["resort_name"];
            }

            if (!empty($row["profile_picture"])) {
                $profile_picture = $row["profile_picture"];
            }
        }

        $stmt->close();
    }

} else {

    $stmt = $conn->prepare("
        SELECT
            resort_name,
            profile_picture
        FROM resorts
        WHERE id = ?
        LIMIT 1
    ");

    if ($stmt) {

        $stmt->bind_param(
            "i",
            $resort_id
        );

        $stmt->execute();

        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {

            if (!empty($row["resort_name"])) {
                $resort_name = $row["resort_name"];
            }

            if (!empty($row["profile_picture"])) {
                $profile_picture = $row["profile_picture"];
            }
        }

        $stmt->close();
    }
}


if (!$resort_id) {
    die("Resort account not found.");
}


/* ==================================================
   TODAY
================================================== */

$today = date("Y-m-d");


/* ==================================================
   TODAY'S ACCOMMODATION SUMMARY
================================================== */

$total_accommodations = 0;
$available_accommodations = 0;
$occupied_accommodations = 0;
$reserved_accommodations = 0;
$maintenance_accommodations = 0;


$sql = "
    SELECT

        COUNT(*) AS total,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'Available'
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS available,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'Occupied'
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS occupied,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'Reserved'
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS reserved,

        COALESCE(
            SUM(
                CASE
                    WHEN status = 'Maintenance'
                    THEN 1
                    ELSE 0
                END
            ),
            0
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
            (int)($row["total"] ?? 0);

        $available_accommodations =
            (int)($row["available"] ?? 0);

        $occupied_accommodations =
            (int)($row["occupied"] ?? 0);

        $reserved_accommodations =
            (int)($row["reserved"] ?? 0);

        $maintenance_accommodations =
            (int)($row["maintenance"] ?? 0);
    }

    $stmt->close();
}


/* ==================================================
   TODAY'S GUESTS
================================================== */

$today_guests = 0;


$sql = "
    SELECT
        COUNT(*) AS total
    FROM checkins
    WHERE resort_id = ?
    AND DATE(check_in_time) = ?
";


$stmt = $conn->prepare($sql);

if ($stmt) {

    $stmt->bind_param(
        "is",
        $resort_id,
        $today
    );

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        $today_guests =
            (int)($row["total"] ?? 0);
    }

    $stmt->close();
}


/* ==================================================
   TODAY'S PAYMENTS
================================================== */

$today_payments = 0;


$sql = "
    SELECT
        COALESCE(SUM(amount), 0) AS total
    FROM payments
    WHERE resort_id = ?
    AND DATE(payment_date) = ?
";


$stmt = $conn->prepare($sql);

if ($stmt) {

    $stmt->bind_param(
        "is",
        $resort_id,
        $today
    );

    $stmt->execute();

    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {

        $today_payments =
            (float)($row["total"] ?? 0);
    }

    $stmt->close();
}


/* ==================================================
   RESERVATION CALENDAR
================================================== */

$calendar_year =
    isset($_GET["calendar_year"])
        ? (int)$_GET["calendar_year"]
        : (int)date("Y");


$calendar_month =
    isset($_GET["calendar_month"])
        ? (int)$_GET["calendar_month"]
        : (int)date("m");


if ($calendar_month < 1) {

    $calendar_month = 12;

    $calendar_year--;
}


if ($calendar_month > 12) {

    $calendar_month = 1;

    $calendar_year++;
}


/* ==================================================
   FIRST AND LAST DAY OF MONTH
================================================== */

$first_day_timestamp = mktime(
    0,
    0,
    0,
    $calendar_month,
    1,
    $calendar_year
);


$last_day_timestamp = mktime(
    0,
    0,
    0,
    $calendar_month + 1,
    0,
    $calendar_year
);


$days_in_month =
    (int)date(
        "t",
        $first_day_timestamp
    );


$first_weekday =
    (int)date(
        "w",
        $first_day_timestamp
    );


$calendar_month_name =
    date(
        "F",
        $first_day_timestamp
    );


/* ==================================================
   PREVIOUS MONTH
================================================== */

$previous_month =
    $calendar_month - 1;

$previous_year =
    $calendar_year;


if ($previous_month < 1) {

    $previous_month = 12;

    $previous_year--;
}


/* ==================================================
   NEXT MONTH
================================================== */

$next_month =
    $calendar_month + 1;

$next_year =
    $calendar_year;


if ($next_month > 12) {

    $next_month = 1;

    $next_year++;
}


/* ==================================================
   GET RESERVATIONS FOR CALENDAR
================================================== */

$calendar_reservations = [];


$calendar_start =
    date(
        "Y-m-d",
        $first_day_timestamp
    );


$calendar_end =
    date(
        "Y-m-d",
        $last_day_timestamp
    );


$sql = "
    SELECT

        r.id,

        r.reservation_date,

        r.visit_date,

        r.reservation_status,

        r.full_name,

        r.contact_number,

        r.number_of_guests,

        r.total_amount,

        r.amount_paid,

        r.payment_status,

        a.accommodation_code,

        a.accommodation_name

    FROM reservations r

    LEFT JOIN accommodations a
        ON r.accommodation_id = a.id

    WHERE r.resort_id = ?

    AND DATE(r.visit_date)
        BETWEEN ? AND ?

    AND r.reservation_status
        IN (
            'Pending',
            'Confirmed',
            'Completed'
        )

    ORDER BY
        r.visit_date ASC,
        r.id ASC
";


$stmt = $conn->prepare($sql);

if ($stmt) {

    $stmt->bind_param(
        "iss",
        $resort_id,
        $calendar_start,
        $calendar_end
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $visit_date =
            date(
                "Y-m-d",
                strtotime(
                    $row["visit_date"]
                )
            );


        if (
            !isset(
                $calendar_reservations[
                    $visit_date
                ]
            )
        ) {

            $calendar_reservations[
                $visit_date
            ] = [];
        }


        $calendar_reservations[
            $visit_date
        ][] = $row;
    }

    $stmt->close();
}


/* ==================================================
   LAST 7 DAYS VISITOR ANALYTICS
================================================== */

$visitor_dates = [];

$accommodation_counts = [];

$day_visitor_counts = [];


for (
    $i = 6;
    $i >= 0;
    $i--
) {

    $date =
        date(
            "Y-m-d",
            strtotime(
                "-" . $i . " days"
            )
        );


    $visitor_dates[$date] =
        date(
            "M d",
            strtotime($date)
        );


    $accommodation_counts[$date] =
        0;


    $day_visitor_counts[$date] =
        0;
}


/* ==================================================
   GET VISITOR DATA
================================================== */

$start_chart_date =
    date(
        "Y-m-d",
        strtotime("-6 days")
    );


$end_chart_date =
    $today;


$sql = "
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

    WHERE resort_id = ?

    AND DATE(check_in_time)
        BETWEEN ? AND ?

    GROUP BY
        DATE(check_in_time)

    ORDER BY
        visit_date ASC
";


$stmt = $conn->prepare($sql);

if ($stmt) {

    $stmt->bind_param(
        "iss",
        $resort_id,
        $start_chart_date,
        $end_chart_date
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $date =
            $row["visit_date"];


        if (
            isset(
                $accommodation_counts[$date]
            )
        ) {

            $accommodation_counts[$date] =
                (int)$row[
                    "accommodation_count"
                ];


            $day_visitor_counts[$date] =
                (int)$row[
                    "day_visitor_count"
                ];
        }
    }

    $stmt->close();
}


/* ==================================================
   PREPARE GRAPH VALUES
================================================== */

$chart_dates = [];

$chart_accommodation = [];

$chart_day_visitors = [];


foreach (
    $visitor_dates
    as $date => $label
) {

    $chart_dates[] =
        $label;


    $chart_accommodation[] =
        $accommodation_counts[$date];


    $chart_day_visitors[] =
        $day_visitor_counts[$date];
}


/* ==================================================
   FIND MAXIMUM GRAPH VALUE
================================================== */

$max_chart_value = 0;


foreach (
    $chart_accommodation
    as $value
) {

    if (
        $value >
        $max_chart_value
    ) {

        $max_chart_value =
            $value;
    }
}


foreach (
    $chart_day_visitors
    as $value
) {

    if (
        $value >
        $max_chart_value
    ) {

        $max_chart_value =
            $value;
    }
}


if (
    $max_chart_value <= 0
) {

    $max_chart_value = 1;
}


$chart_max_height = 150;


/* Keep the connection open for Analytics and the notification header.
   PHP releases it automatically when this request finishes. */


/* Embedded resort analytics */
$resort_analytics_today = date("Y-m-d");

$resort_analytics_default_start = date(
    "Y-m-d",
    strtotime("-29 days")
);

$resort_analytics_start_date = $_GET["start_date"]
    ?? $resort_analytics_default_start;

$resort_analytics_end_date = $_GET["end_date"]
    ?? $resort_analytics_today;


/* ==================================================
   DATE VALIDATION
================================================== */

function resortAnalyticsValidDate($resort_analytics_date)
{
    if (!is_string($resort_analytics_date)) return false;
    $resort_analytics_date_object = DateTime::createFromFormat(
        "Y-m-d",
        $resort_analytics_date
    );

    return $resort_analytics_date_object &&
           $resort_analytics_date_object->format("Y-m-d") === $resort_analytics_date;
}


if (!resortAnalyticsValidDate($resort_analytics_start_date)) {
    $resort_analytics_start_date = $resort_analytics_default_start;
}


if (!resortAnalyticsValidDate($resort_analytics_end_date)) {
    $resort_analytics_end_date = $resort_analytics_today;
}


if ($resort_analytics_end_date > $resort_analytics_today) {
    $resort_analytics_end_date = $resort_analytics_today;
}


if ($resort_analytics_start_date > $resort_analytics_end_date) {

    $resort_analytics_temporary = $resort_analytics_start_date;

    $resort_analytics_start_date = $resort_analytics_end_date;

    $resort_analytics_end_date = $resort_analytics_temporary;
}


if ($resort_analytics_end_date > $resort_analytics_today) {
    $resort_analytics_end_date = $resort_analytics_today;
}


/* ==================================================
   DISPLAY DATE
================================================== */

$resort_analytics_display_start_date =
    date(
        "M d, Y",
        strtotime($resort_analytics_start_date)
    );

$resort_analytics_display_end_date =
    date(
        "M d, Y",
        strtotime($resort_analytics_end_date)
    );


/* ==================================================
   SUMMARY DATA
================================================== */


/* --------------------------------------------------
   TOTAL VISITORS
-------------------------------------------------- */

$resort_analytics_total_visitors = 0;

$resort_analytics_sql = "
    SELECT COUNT(*) AS total

    FROM checkins

    WHERE resort_id = ?

    AND DATE(check_in_time)
    BETWEEN ? AND ?
";

$resort_analytics_stmt = $conn->prepare($resort_analytics_sql);

if ($resort_analytics_stmt) {

    $resort_analytics_stmt->bind_param(
        "iss",
        $resort_id,
        $resort_analytics_start_date,
        $resort_analytics_end_date
    );

    $resort_analytics_stmt->execute();

    $resort_analytics_result = $resort_analytics_stmt->get_result();

    if ($resort_analytics_row = $resort_analytics_result->fetch_assoc()) {

        $resort_analytics_total_visitors =
            (int) $resort_analytics_row["total"];
    }

    $resort_analytics_stmt->close();
}


/* --------------------------------------------------
   TOTAL RESERVATIONS
-------------------------------------------------- */

$resort_analytics_total_reservations = 0;

$resort_analytics_sql = "
    SELECT COUNT(*) AS total

    FROM reservations

    WHERE resort_id = ?

    AND DATE(visit_date)
    BETWEEN ? AND ?
";

$resort_analytics_stmt = $conn->prepare($resort_analytics_sql);

if ($resort_analytics_stmt) {

    $resort_analytics_stmt->bind_param(
        "iss",
        $resort_id,
        $resort_analytics_start_date,
        $resort_analytics_end_date
    );

    $resort_analytics_stmt->execute();

    $resort_analytics_result = $resort_analytics_stmt->get_result();

    if ($resort_analytics_row = $resort_analytics_result->fetch_assoc()) {

        $resort_analytics_total_reservations =
            (int) $resort_analytics_row["total"];
    }

    $resort_analytics_stmt->close();
}


/* --------------------------------------------------
   TOTAL PAYMENTS
-------------------------------------------------- */

$resort_analytics_total_payments = 0;

$resort_analytics_sql = "
    SELECT
        COALESCE(SUM(amount), 0) AS total

    FROM payments

    WHERE resort_id = ?

    AND DATE(payment_date)
    BETWEEN ? AND ?
";

$resort_analytics_stmt = $conn->prepare($resort_analytics_sql);

if ($resort_analytics_stmt) {

    $resort_analytics_stmt->bind_param(
        "iss",
        $resort_id,
        $resort_analytics_start_date,
        $resort_analytics_end_date
    );

    $resort_analytics_stmt->execute();

    $resort_analytics_result = $resort_analytics_stmt->get_result();

    if ($resort_analytics_row = $resort_analytics_result->fetch_assoc()) {

        $resort_analytics_total_payments =
            (float) $resort_analytics_row["total"];
    }

    $resort_analytics_stmt->close();
}


/* ==================================================
   CURRENT ACCOMMODATION STATUS
================================================== */

$resort_analytics_total_accommodations = 0;

$resort_analytics_available_count = 0;

$resort_analytics_occupied_count = 0;

$resort_analytics_reserved_count = 0;

$resort_analytics_maintenance_count = 0;


/*
   IMPORTANT:
   This assumes your accommodations table
   has a column named "status".
*/

$resort_analytics_sql = "
    SELECT

        COUNT(*) AS total,

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

$resort_analytics_stmt = $conn->prepare($resort_analytics_sql);

if ($resort_analytics_stmt) {

    $resort_analytics_stmt->bind_param(
        "i",
        $resort_id
    );

    $resort_analytics_stmt->execute();

    $resort_analytics_result = $resort_analytics_stmt->get_result();

    if ($resort_analytics_row = $resort_analytics_result->fetch_assoc()) {

        $resort_analytics_total_accommodations =
            (int) ($resort_analytics_row["total"] ?? 0);

        $resort_analytics_available_count =
            (int) ($resort_analytics_row["available"] ?? 0);

        $resort_analytics_occupied_count =
            (int) ($resort_analytics_row["occupied"] ?? 0);

        $resort_analytics_reserved_count =
            (int) ($resort_analytics_row["reserved"] ?? 0);

        $resort_analytics_maintenance_count =
            (int) ($resort_analytics_row["maintenance"] ?? 0);
    }

    $resort_analytics_stmt->close();
}


/* ==================================================
   CURRENT OCCUPANCY RATE
================================================== */

$resort_analytics_occupancy_rate = 0;

if ($resort_analytics_total_accommodations > 0) {

    $resort_analytics_occupancy_rate =
        (
            $resort_analytics_occupied_count /
            $resort_analytics_total_accommodations
        ) * 100;
}


/* ==================================================
   VISITOR DAILY TREND
================================================== */

$resort_analytics_visitor_by_date = [];


$resort_analytics_sql = "
    SELECT

        DATE(check_in_time) AS activity_date,

        COUNT(*) AS total

    FROM checkins

    WHERE resort_id = ?

    AND DATE(check_in_time)
    BETWEEN ? AND ?

    GROUP BY DATE(check_in_time)

    ORDER BY DATE(check_in_time) ASC
";

$resort_analytics_stmt = $conn->prepare($resort_analytics_sql);

if ($resort_analytics_stmt) {

    $resort_analytics_stmt->bind_param(
        "iss",
        $resort_id,
        $resort_analytics_start_date,
        $resort_analytics_end_date
    );

    $resort_analytics_stmt->execute();

    $resort_analytics_result = $resort_analytics_stmt->get_result();

    while ($resort_analytics_row = $resort_analytics_result->fetch_assoc()) {

        $resort_analytics_visitor_by_date[
            $resort_analytics_row["activity_date"]
        ] = (int) $resort_analytics_row["total"];
    }

    $resort_analytics_stmt->close();
}


/* ==================================================
   CREATE EVERY DATE FOR VISITOR GRAPH
================================================== */

$resort_analytics_visitor_dates = [];

$resort_analytics_visitor_counts = [];

$resort_analytics_current_date =
    new DateTime($resort_analytics_start_date);

$resort_analytics_last_date =
    new DateTime($resort_analytics_end_date);


while ($resort_analytics_current_date <= $resort_analytics_last_date) {

    $resort_analytics_date_key =
        $resort_analytics_current_date->format("Y-m-d");

    $resort_analytics_visitor_dates[] =
        $resort_analytics_current_date->format("M d");

    $resort_analytics_visitor_counts[] =
        $resort_analytics_visitor_by_date[$resort_analytics_date_key] ?? 0;

    $resort_analytics_current_date->modify("+1 day");
}


/* ==================================================
   PAYMENT DAILY TREND
================================================== */

$resort_analytics_payment_by_date = [];


$resort_analytics_sql = "
    SELECT

        DATE(payment_date) AS activity_date,

        COALESCE(SUM(amount), 0) AS total

    FROM payments

    WHERE resort_id = ?

    AND DATE(payment_date)
    BETWEEN ? AND ?

    GROUP BY DATE(payment_date)

    ORDER BY DATE(payment_date) ASC
";

$resort_analytics_stmt = $conn->prepare($resort_analytics_sql);

if ($resort_analytics_stmt) {

    $resort_analytics_stmt->bind_param(
        "iss",
        $resort_id,
        $resort_analytics_start_date,
        $resort_analytics_end_date
    );

    $resort_analytics_stmt->execute();

    $resort_analytics_result = $resort_analytics_stmt->get_result();

    while ($resort_analytics_row = $resort_analytics_result->fetch_assoc()) {

        $resort_analytics_payment_by_date[
            $resort_analytics_row["activity_date"]
        ] = (float) $resort_analytics_row["total"];
    }

    $resort_analytics_stmt->close();
}


/* ==================================================
   CREATE EVERY DATE FOR PAYMENT GRAPH
================================================== */

$resort_analytics_payment_dates = [];

$resort_analytics_payment_totals = [];

$resort_analytics_current_date =
    new DateTime($resort_analytics_start_date);

$resort_analytics_last_date =
    new DateTime($resort_analytics_end_date);


while ($resort_analytics_current_date <= $resort_analytics_last_date) {

    $resort_analytics_date_key =
        $resort_analytics_current_date->format("Y-m-d");

    $resort_analytics_payment_dates[] =
        $resort_analytics_current_date->format("M d");

    $resort_analytics_payment_totals[] =
        $resort_analytics_payment_by_date[$resort_analytics_date_key] ?? 0;

    $resort_analytics_current_date->modify("+1 day");
}


/* ==================================================
   RESERVATION STATUS
   ACTUAL STATUSES:
   Confirmed
   Completed
================================================== */

$resort_analytics_reservation_status = [

    "Confirmed" => 0,

    "Completed" => 0

];


$resort_analytics_sql = "
    SELECT

        reservation_status,

        COUNT(*) AS total

    FROM reservations

    WHERE resort_id = ?

    AND DATE(visit_date)
    BETWEEN ? AND ?

    GROUP BY reservation_status
";

$resort_analytics_stmt = $conn->prepare($resort_analytics_sql);

if ($resort_analytics_stmt) {

    $resort_analytics_stmt->bind_param(
        "iss",
        $resort_id,
        $resort_analytics_start_date,
        $resort_analytics_end_date
    );

    $resort_analytics_stmt->execute();

    $resort_analytics_result = $resort_analytics_stmt->get_result();

    while ($resort_analytics_row = $resort_analytics_result->fetch_assoc()) {

        $resort_analytics_status =
            $resort_analytics_row["reservation_status"];

        $resort_analytics_total =
            (int) $resort_analytics_row["total"];

        if (
            isset(
                $resort_analytics_reservation_status[$resort_analytics_status]
            )
        ) {

            $resort_analytics_reservation_status[$resort_analytics_status] =
                $resort_analytics_total;
        }
    }

    $resort_analytics_stmt->close();
}


/* ==================================================
   JSON DATA
================================================== */

$resort_analytics_visitor_dates_json =
    json_encode($resort_analytics_visitor_dates);

$resort_analytics_visitor_counts_json =
    json_encode($resort_analytics_visitor_counts);

$resort_analytics_payment_dates_json =
    json_encode($resort_analytics_payment_dates);

$resort_analytics_payment_totals_json =
    json_encode($resort_analytics_payment_totals);

$resort_analytics_reservation_labels_json =
    json_encode(
        array_keys(
            $resort_analytics_reservation_status
        )
    );

$resort_analytics_reservation_values_json =
    json_encode(
        array_values(
            $resort_analytics_reservation_status
        )
    );

$resort_analytics_accommodation_labels_json =
    json_encode([
        "Available",
        "Occupied",
        "Reserved",
        "Maintenance"
    ]);

$resort_analytics_accommodation_values_json =
    json_encode([
        $resort_analytics_available_count,
        $resort_analytics_occupied_count,
        $resort_analytics_reserved_count,
        $resort_analytics_maintenance_count
    ]);


?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<link
    rel="icon"
    type="image/png"
    href="logo1.png"
>

<title>
    Alapasco EcoKubo - Dashboard
</title>


<style>

/* ==================================================
   GENERAL
================================================== */

* {
    box-sizing: border-box;
}

body {

    margin: 0;

    font-family:
        Arial,
        sans-serif;

    background: #f4f6f5;

    color: #333;
}

a {
    text-decoration: none;
}


/* ==================================================
   MAIN
================================================== */

.dashboard-container {

    display: flex;

    min-height: 100vh;
}

.main-content {

    margin-left: 250px;

    width:
        calc(100% - 250px);

    min-height: 100vh;
}


/* ==================================================
   TOPBAR
================================================== */

.topbar {

    background: white;

    min-height: 65px;

    padding:
        10px 25px;

    display: flex;

    justify-content:
        space-between;

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

.user-profile-link {

    display: flex;

    align-items: center;

    gap: 10px;

    color: #333;

    border-radius: 30px;

    padding: 4px 7px;

    transition:
        background 0.15s;
}

.user-profile-link:hover {

    background: #f1f4f2;
}

.topbar-profile-picture {

    width: 44px;

    height: 44px;

    border-radius: 50%;

    object-fit: cover;

    border:
        2px solid #1f5d42;

    background: #eef3f0;
}

.topbar-profile-placeholder {

    width: 44px;

    height: 44px;

    border-radius: 50%;

    border:
        2px solid #1f5d42;

    background: #eef3f0;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 21px;
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
   PAGE
================================================== */

.page-content {

    padding: 25px;
}


/* ==================================================
   WELCOME
================================================== */

.welcome-box {

    background:
        linear-gradient(
            135deg,
            #1f5d42,
            #2f7a59
        );

    color: white;

    padding: 25px;

    border-radius: 12px;

    margin-bottom: 25px;
}

.welcome-box h1 {

    margin:
        0 0 8px;

    font-size: 25px;
}

.welcome-box p {

    margin: 0;

    font-size: 14px;

    opacity: 0.9;
}


/* ==================================================
   SECTION TITLE
================================================== */

.section-title {

    color: #1f5d42;

    font-size: 20px;

    margin:
        28px 0 15px;
}


/* ==================================================
   SUMMARY
================================================== */

.summary-grid {

    display: grid;

    grid-template-columns:
        repeat(6, 1fr);

    gap: 15px;

    margin-bottom: 25px;
}

.summary-card {

    background: white;

    border:
        1px solid #ddd;

    border-radius: 10px;

    padding: 18px;

    text-align: center;
}

.summary-card .icon {

    font-size: 27px;

    margin-bottom: 8px;
}

.summary-card h3 {

    margin: 0;

    color: #1f5d42;

    font-size: 25px;
}

.summary-card p {

    margin:
        7px 0 0;

    color: #777;

    font-size: 12px;
}


/* ==================================================
   RESERVATION CALENDAR
================================================== */

.reservation-calendar {

    background: white;

    border:
        1px solid #ddd;

    border-radius: 12px;

    padding: 20px;

    margin-bottom: 25px;
}

.calendar-header {

    display: flex;

    justify-content:
        space-between;

    align-items: center;

    margin-bottom: 18px;
}

.calendar-title h2 {

    margin: 0;

    color: #1f5d42;

    font-size: 20px;
}

.calendar-title p {

    margin:
        5px 0 0;

    color: #777;

    font-size: 12px;
}

.calendar-navigation {

    display: flex;

    align-items: center;

    gap: 8px;
}

.calendar-navigation a {

    display: flex;

    align-items: center;

    justify-content: center;

    width: 35px;

    height: 35px;

    background: #eef3f0;

    color: #1f5d42;

    border-radius: 7px;

    font-size: 18px;

    font-weight: bold;
}

.calendar-navigation a:hover {

    background: #dfeae4;
}

.calendar-month-name {

    min-width: 135px;

    text-align: center;

    font-weight: bold;

    color: #333;

    font-size: 16px;
}


/* ==================================================
   CALENDAR WEEKDAYS
================================================== */

.calendar-weekdays {

    display: grid;

    grid-template-columns:
        repeat(7, 1fr);

    gap: 5px;

    margin-bottom: 5px;
}

.calendar-weekday {

    text-align: center;

    font-size: 11px;

    font-weight: bold;

    color: #777;

    padding: 8px 2px;
}


/* ==================================================
   CALENDAR GRID
================================================== */

.calendar-grid {

    display: grid;

    grid-template-columns:
        repeat(7, 1fr);

    grid-auto-rows: 75px;

    gap: 5px;

    align-items: stretch;
}


/* ==================================================
   FIXED CALENDAR CELL SIZE
================================================== */

.calendar-empty {

    height: 75px;

    min-height: 75px;

    max-height: 75px;

    background: #fafafa;

    border-radius: 7px;

    overflow: hidden;
}


.calendar-day {

    height: 75px;

    min-height: 75px;

    max-height: 75px;

    border:
        1px solid #e4e7e5;

    border-radius: 7px;

    padding: 7px;

    position: relative;

    background: white;

    cursor: pointer;

    overflow: hidden;

    transition:
        background 0.15s,
        border-color 0.15s,
        transform 0.15s;
}

.calendar-day:hover {

    background: #f1f6f3;

    border-color: #1f5d42;

    transform:
        translateY(-1px);
}

.calendar-day.today {

    border:
        2px solid #1f5d42;
}

.calendar-day.has-reservation {

    background: #ffe5e5;

    border:
        1px solid #dc3545;

    cursor: pointer;
}

.calendar-day.has-reservation:hover {

    background: #ffd5d5;

    border-color: #b02a37;
}

.calendar-number {

    font-size: 13px;

    font-weight: bold;

    color: #444;
}

.calendar-day.today
.calendar-number {

    color: #1f5d42;
}

.calendar-day.has-reservation
.calendar-number {

    color: #b02a37;
}


/* ==================================================
   RESERVED LABEL
================================================== */

.reservation-dot {

    display: block;

    margin-top: 8px;

    background: #dc3545;

    color: white;

    border-radius: 4px;

    padding: 3px 5px;

    font-size: 9px;

    font-weight: bold;

    text-align: center;

    overflow: hidden;

    white-space: nowrap;

    text-overflow: ellipsis;

    line-height: 12px;

    height: 18px;
}


.reservation-count {

    display: block;

    margin-top: 3px;

    color: #b02a37;

    font-size: 9px;

    font-weight: bold;

    text-align: center;

    line-height: 11px;

    height: 11px;

    overflow: hidden;

    white-space: nowrap;
}


/* ==================================================
   CALENDAR LEGEND
================================================== */

.calendar-legend {

    display: flex;

    gap: 18px;

    margin-top: 15px;

    font-size: 11px;

    color: #666;
}

.legend-calendar-item {

    display: flex;

    align-items: center;

    gap: 6px;
}

.legend-calendar-box {

    width: 13px;

    height: 13px;

    border-radius: 3px;
}

.legend-reserved {

    background: #dc3545;
}

.legend-today {

    border:
        2px solid #1f5d42;

    background: white;
}


/* ==================================================
   RESERVATION DETAILS
================================================== */

.reservation-details {

    margin-top: 15px;

    border-top:
        1px solid #ddd;

    padding-top: 15px;
}

.reservation-details h3 {

    margin:
        0 0 10px;

    color: #1f5d42;

    font-size: 16px;
}

.reservation-item {

    background: #fff5f5;

    border-left:
        4px solid #dc3545;

    padding: 10px;

    margin-bottom: 8px;

    border-radius: 5px;
}

.reservation-item strong {

    display: block;

    color: #333;

    font-size: 13px;
}

.reservation-item span {

    display: block;

    color: #666;

    font-size: 11px;

    margin-top: 3px;
}

.reservation-empty {

    background: #f7f8f7;

    border:
        1px solid #e1e5e2;

    border-radius: 6px;

    padding: 15px;

    color: #777;

    font-size: 13px;

    text-align: center;
}


/* ==================================================
   ANALYTICS
================================================== */

.dashboard-analytics {

    background: white;

    border:
        1px solid #ddd;

    border-radius: 12px;

    padding: 20px;

    margin-bottom: 25px;
}

.analytics-header {

    display: flex;

    justify-content:
        space-between;

    align-items: center;

    margin-bottom: 20px;
}

.analytics-title {

    margin: 0;

    color: #1f5d42;

    font-size: 20px;
}

.analytics-subtitle {

    margin:
        5px 0 0;

    color: #777;

    font-size: 12px;
}

.analytics-link {

    color: #1f5d42;

    font-size: 12px;

    font-weight: bold;
}

.chart-container {

    height: 190px;

    display: flex;

    align-items: flex-end;

    justify-content: center;

    border-bottom:
        1px solid #ddd;

    padding:
        10px 10px 0;
}

.chart-bars {

    width: 100%;

    height: 160px;

    display: flex;

    justify-content:
        space-around;

    align-items: flex-end;
}

.chart-group {

    height: 160px;

    display: flex;

    align-items: flex-end;

    gap: 4px;

    flex: 1;

    justify-content: center;
}

.chart-bar {

    width: 18px;

    min-height: 2px;

    border-radius:
        4px 4px 0 0;

    position: relative;
}

.chart-bar.accommodation {

    background: #1f5d42;
}

.chart-bar.day {

    background: #d8a83e;
}

.chart-value {

    position: absolute;

    top: -18px;

    left: 50%;

    transform:
        translateX(-50%);

    font-size: 9px;

    font-weight: bold;

    color: #555;
}

.analytics-empty {

    width: 100%;

    text-align: center;

    color: #999;

    font-size: 13px;

    padding-top: 70px;
}

.chart-labels {

    display: flex;

    width: 100%;

    justify-content:
        space-around;

    margin-top: 8px;
}

.chart-date {

    flex: 1;

    text-align: center;

    font-size: 10px;

    color: #777;
}

.chart-legend {

    display: flex;

    justify-content: center;

    gap: 20px;

    margin-top: 15px;
}

.legend-item {

    display: flex;

    align-items: center;

    gap: 6px;

    font-size: 11px;

    color: #666;
}

.legend-box {

    width: 12px;

    height: 12px;

    border-radius: 3px;
}

.legend-accommodation {

    background: #1f5d42;
}

.legend-day {

    background: #d8a83e;
}


/* ==================================================
   OPERATION CARDS
================================================== */

.operation-grid {

    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 15px;

    margin-bottom: 25px;
}

.operation-card {

    background: white;

    border:
        1px solid #ddd;

    border-radius: 10px;

    padding: 20px;

    color: #333;

    transition:
        transform 0.15s,
        box-shadow 0.15s;
}

.operation-card:hover {

    transform:
        translateY(-2px);

    box-shadow:
        0 5px 15px
        rgba(0,0,0,0.08);
}

.operation-card .icon {

    font-size: 30px;

    margin-bottom: 10px;
}

.operation-card h3 {

    margin:
        0 0 7px;

    color: #1f5d42;

    font-size: 16px;
}

.operation-card p {

    margin: 0;

    color: #777;

    font-size: 12px;

    line-height: 1.5;
}


/* ==================================================
   RESPONSIVE
================================================== */

@media (max-width:1000px) {

    .summary-grid {

        grid-template-columns:
            repeat(3, 1fr);
    }

    .operation-grid {

        grid-template-columns:
            repeat(2, 1fr);
    }
}


@media (max-width:700px) {

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
            10px 15px;
    }

    .topbar h3 {

        font-size: 16px;
    }

    .user-info {

        font-size: 12px;
    }

    .topbar-profile-picture,
    .topbar-profile-placeholder {

        width: 38px;

        height: 38px;
    }

    .summary-grid {

        grid-template-columns:
            repeat(2, 1fr);
    }

    .operation-grid {

        grid-template-columns: 1fr;
    }

    .calendar-header {

        align-items:
            flex-start;

        gap: 10px;
    }

    .calendar-title h2 {

        font-size: 17px;
    }

    .calendar-month-name {

        min-width: 100px;

        font-size: 13px;
    }


    /* FIXED MOBILE CALENDAR SIZE */

    .calendar-grid {

        grid-auto-rows: 55px;
    }


    .calendar-day {

        height: 55px;

        min-height: 55px;

        max-height: 55px;

        padding: 5px;

        overflow: hidden;
    }


    .calendar-empty {

        height: 55px;

        min-height: 55px;

        max-height: 55px;

        overflow: hidden;
    }


    .reservation-dot {

        font-size: 8px;

        padding: 2px;

        height: 16px;

        line-height: 11px;

        margin-top: 5px;
    }


    .reservation-count {

        font-size: 8px;

        height: 10px;

        line-height: 10px;

        margin-top: 2px;
    }


    .chart-bar {

        width: 12px;
    }
}

.resort-analytics-section * {
    box-sizing: border-box;
}.resort-analytics-section a {
    text-decoration: none;
}.resort-analytics-section .mobile-menu-button {

    display: none;

    position: fixed;

    top: 15px;

    left: 15px;

    width: 45px;

    height: 45px;

    border: none;

    border-radius: 8px;

    background: #1f5d42;

    color: white;

    font-size: 22px;

    cursor: pointer;

    z-index: 1100;
}.resort-analytics-section .page-header {

    margin-bottom: 20px;
}.resort-analytics-section .page-header h1 {

    margin: 0 0 5px;

    color: #1f5d42;

    font-size: 25px;
}.resort-analytics-section .page-header p {

    margin: 0;

    color: #777;

    font-size: 13px;
}.resort-analytics-section .filter-card {

    background: white;

    padding: 20px;

    border-radius: 10px;

    margin-bottom: 20px;

    border: 1px solid #eee;
}.resort-analytics-section .filter-title {

    font-size: 17px;

    font-weight: bold;

    color: #1f5d42;

    margin-bottom: 15px;
}.resort-analytics-section .filter-form {

    display: grid;

    grid-template-columns:
        1fr 1fr auto auto;

    gap: 12px;

    align-items: end;
}.resort-analytics-section .date-group {

    display: flex;

    flex-direction: column;

    gap: 6px;
}.resort-analytics-section .date-group label {

    font-size: 12px;

    font-weight: bold;

    color: #555;
}.resort-analytics-section .date-group input {

    padding: 11px;

    border: 1px solid #ccc;

    border-radius: 6px;

    font-size: 13px;
}.resort-analytics-section .btn {

    border: none;

    padding: 11px 18px;

    border-radius: 6px;

    cursor: pointer;

    text-decoration: none;

    display: inline-block;

    font-size: 13px;

    font-weight: bold;

    text-align: center;
}.resort-analytics-section .btn-primary {

    background: #1f5d42;

    color: white;
}.resort-analytics-section .btn-primary:hover {

    background: #174832;
}.resort-analytics-section .btn-reset {

    background: #e9e9e9;

    color: #333;
}.resort-analytics-section .btn-reset:hover {

    background: #ddd;
}.resort-analytics-section .selected-period {

    margin-top: 15px;

    padding: 10px 12px;

    background: #f1f8f2;

    border-radius: 7px;

    color: #1f5d42;

    font-size: 13px;
}.resort-analytics-section .summary-grid {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 15px;

    margin-bottom: 25px;
}.resort-analytics-section .summary-card {

    background: white;

    padding: 20px;

    border-radius: 10px;

    border: 1px solid #eee;
}.resort-analytics-section .summary-card h3 {

    margin: 0 0 10px;

    font-size: 13px;

    color: #777;

    font-weight: normal;
}.resort-analytics-section .summary-number {

    font-size: 28px;

    font-weight: bold;

    color: #1f5d42;

    margin: 0;
}.resort-analytics-section .summary-small {

    margin-top: 8px;

    font-size: 12px;

    color: #888;
}.resort-analytics-section .chart-grid {

    display: grid;

    grid-template-columns:
        repeat(2, 1fr);

    gap: 20px;

    margin-bottom: 25px;
}.resort-analytics-section .chart-card {

    background: white;

    padding: 20px;

    border-radius: 10px;

    border: 1px solid #eee;

    min-width: 0;
}.resort-analytics-section .chart-card h2 {

    margin: 0 0 5px;

    color: #1f5d42;

    font-size: 18px;
}.resort-analytics-section .chart-card p {

    margin: 0 0 15px;

    color: #888;

    font-size: 13px;
}.resort-analytics-section .chart-container {

    position: relative;

    width: 100%;

    height: 320px;
}.resort-analytics-section .status-grid {

    display: grid;

    grid-template-columns:
        repeat(4, 1fr);

    gap: 12px;

    margin-bottom: 20px;
}.resort-analytics-section .status-item {

    padding: 15px;

    border-radius: 8px;

    background: #f4f6f5;
}.resort-analytics-section .status-item strong {

    display: block;

    color: #1f5d42;

    font-size: 24px;

    margin-bottom: 5px;
}.resort-analytics-section .status-item span {

    font-size: 13px;

    color: #777;
}.resort-analytics-section .occupancy-box {

    margin-top: 15px;

    padding: 15px;

    background: #f4f6f5;

    border-radius: 8px;
}.resort-analytics-section .occupancy-title {

    font-size: 13px;

    color: #777;

    margin-bottom: 8px;
}.resort-analytics-section .occupancy-value {

    color: #1f5d42;

    font-size: 25px;

    font-weight: bold;
}




@media (max-width: 1100px) {.resort-analytics-section .summary-grid {

        grid-template-columns:
            repeat(2, 1fr);
    }.resort-analytics-section .chart-grid {

        grid-template-columns: 1fr;
    }.resort-analytics-section .status-grid {

        grid-template-columns:
            repeat(2, 1fr);
    }

}


@media (max-width: 768px) {.resort-analytics-section .mobile-menu-button {

        display: block;
    }.resort-analytics-section .summary-grid {

        grid-template-columns: 1fr;
    }.resort-analytics-section .status-grid {

        grid-template-columns:
            1fr 1fr;
    }.resort-analytics-section .filter-form {

        grid-template-columns: 1fr;
    }.resort-analytics-section .date-group input {

        width: 100%;
    }.resort-analytics-section .btn {

        width: 100%;
    }.resort-analytics-section .chart-container {

        height: 280px;
    }

}


@media (max-width: 450px) {.resort-analytics-section .status-grid {

        grid-template-columns: 1fr;
    }

}




@media print {

}


.resort-analytics-section { margin: 24px 0; scroll-margin-top: 90px; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>


<body>


<div class="dashboard-container">


<!-- ==================================================
     SIDEBAR
================================================== -->

<?php require __DIR__ . '/resort_sidebar.php'; ?>


<!-- ==================================================
     MAIN CONTENT
================================================== -->

<div class="main-content">


<div class="topbar">

    <h3>
        Dashboard
    </h3>


    <div class="resort-header-account"><?php require __DIR__ . '/resort_header_notifications.php'; ?>
<a
        href="settings.php#profile"
        class="user-profile-link"
    >

        <?php if (!empty($profile_picture)): ?>

            <img
                src="<?php
                    echo htmlspecialchars(
                        $profile_picture
                    );
                ?>"
                alt="Resort Profile Picture"
                class="topbar-profile-picture"
            >

        <?php else: ?>

            <div class="topbar-profile-placeholder">
                👤
            </div>

        <?php endif; ?>


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

        </div>

    </a></div>

</div>


<div class="page-content">


<!-- ==================================================
     WELCOME
================================================== -->

<div class="welcome-box">

    <h1>

        Welcome,

        <?php

        echo htmlspecialchars(
            $full_name
        );

        ?>!

    </h1>


    <p>

        Manage your resort, guests,
        reservations, and accommodations
        from one place.

    </p>

</div>


<!-- ==================================================
     TODAY'S OVERVIEW
================================================== -->

<h2 class="section-title">
    Today's Overview
</h2>


<div class="summary-grid">


    <div class="summary-card">

        <div class="icon">
            🏡
        </div>

        <h3>
            <?php
            echo $total_accommodations;
            ?>
        </h3>

        <p>
            Total Accommodations
        </p>

    </div>


    <div class="summary-card">

        <div class="icon">
            ✅
        </div>

        <h3>
            <?php
            echo $available_accommodations;
            ?>
        </h3>

        <p>
            Available
        </p>

    </div>


    <div class="summary-card">

        <div class="icon">
            🔴
        </div>

        <h3>
            <?php
            echo $occupied_accommodations;
            ?>
        </h3>

        <p>
            Occupied
        </p>

    </div>


    <div class="summary-card">

        <div class="icon">
            📅
        </div>

        <h3>
            <?php
            echo $reserved_accommodations;
            ?>
        </h3>

        <p>
            Reserved
        </p>

    </div>


    <div class="summary-card">

        <div class="icon">
            👥
        </div>

        <h3>
            <?php
            echo $today_guests;
            ?>
        </h3>

        <p>
            Today's Guests
        </p>

    </div>


    <div class="summary-card">

        <div class="icon">
            💰
        </div>

        <h3>

            ₱<?php

            echo number_format(
                $today_payments,
                2
            );

            ?>

        </h3>

        <p>
            Today's Payments
        </p>

    </div>


</div>


<!-- ==================================================
     RESERVATION CALENDAR
================================================== -->

<div class="reservation-calendar">


    <div class="calendar-header">


        <div class="calendar-title">

            <h2>
                📅 Reservation Calendar
            </h2>

            <p>
                View reservations by visit date
            </p>

        </div>


        <div class="calendar-navigation">


            <a
                href="resort_dashboard.php?calendar_year=<?php
                    echo $previous_year;
                ?>&calendar_month=<?php
                    echo $previous_month;
                ?>"
                title="Previous Month"
            >
                ‹
            </a>


            <div class="calendar-month-name">

                <?php

                echo
                    $calendar_month_name
                    . " "
                    . $calendar_year;

                ?>

            </div>


            <a
                href="resort_dashboard.php?calendar_year=<?php
                    echo $next_year;
                ?>&calendar_month=<?php
                    echo $next_month;
                ?>"
                title="Next Month"
            >
                ›
            </a>


        </div>

    </div>


    <div class="calendar-weekdays">

        <div class="calendar-weekday">
            Sun
        </div>

        <div class="calendar-weekday">
            Mon
        </div>

        <div class="calendar-weekday">
            Tue
        </div>

        <div class="calendar-weekday">
            Wed
        </div>

        <div class="calendar-weekday">
            Thu
        </div>

        <div class="calendar-weekday">
            Fri
        </div>

        <div class="calendar-weekday">
            Sat
        </div>

    </div>


    <div class="calendar-grid">


        <?php

        /* EMPTY CELLS BEFORE MONTH */

        for (
            $blank = 0;
            $blank < $first_weekday;
            $blank++
        ):

        ?>

            <div class="calendar-empty"></div>

        <?php

        endfor;


        /* ==================================================
           CALENDAR DAYS
        ================================================== */

        for (
            $day = 1;
            $day <= $days_in_month;
            $day++
        ):


            $date_key =
                sprintf(
                    "%04d-%02d-%02d",
                    $calendar_year,
                    $calendar_month,
                    $day
                );


            $has_reservation =
                isset(
                    $calendar_reservations[
                        $date_key
                    ]
                );


            $is_today =
                ($date_key === $today);


            $classes =
                "calendar-day";


            if ($has_reservation) {

                $classes .=
                    " has-reservation";
            }


            if ($is_today) {

                $classes .=
                    " today";
            }


            $reservations_for_date =
                $has_reservation
                    ? $calendar_reservations[
                        $date_key
                    ]
                    : [];

        ?>


        <div
            class="<?php
                echo $classes;
            ?>"
            onclick="showReservations(
                '<?php
                    echo $date_key;
                ?>'
            )"
            title="View reservations for <?php
                echo $date_key;
            ?>"
        >


            <div class="calendar-number">

                <?php

                echo $day;

                ?>

            </div>


            <?php if ($has_reservation): ?>


                <span class="reservation-dot">

                    🔴 Reserved

                </span>


                <span class="reservation-count">

                    <?php

                    echo count(
                        $reservations_for_date
                    );

                    ?>

                    reservation<?php

                    echo
                        count(
                            $reservations_for_date
                        ) > 1
                            ? "s"
                            : "";

                    ?>

                </span>


            <?php endif; ?>


        </div>


        <?php

        endfor;


        /* EMPTY CELLS AFTER MONTH */

        $total_cells =
            $first_weekday
            + $days_in_month;


        $remaining_cells =
            (
                7 -
                (
                    $total_cells % 7
                )
            ) % 7;


        for (
            $blank = 0;
            $blank < $remaining_cells;
            $blank++
        ):

        ?>

            <div class="calendar-empty"></div>

        <?php

        endfor;

        ?>


    </div>


    <!-- ==================================================
         CALENDAR LEGEND
    ================================================== -->

    <div class="calendar-legend">


        <div class="legend-calendar-item">

            <span
                class="
                    legend-calendar-box
                    legend-reserved
                "
            ></span>

            Reserved

        </div>


        <div class="legend-calendar-item">

            <span
                class="
                    legend-calendar-box
                    legend-today
                "
            ></span>

            Today

        </div>


    </div>


    <!-- ==================================================
         RESERVATION DETAILS
    ================================================== -->

    <div
        id="reservationDetails"
        class="reservation-details"
        style="display:none;"
    >

        <h3 id="reservationDetailsTitle">
            Reservations
        </h3>


        <div
            id="reservationDetailsList"
        ></div>

    </div>


</div>
<section class="resort-analytics-section" id="analytics">


        <!-- ==================================================
             PAGE HEADER
        ================================================== -->

        <div class="page-header">

            <h1>
                Analytics
            </h1>

            <p>
                View tourism activity, visitors, reservations,
                payments, and accommodation statistics.
            </p>

        </div>


        <!-- ==================================================
             DATE FILTER
        ================================================== -->

        <div class="filter-card">


            <div class="filter-title">

                Analytics Date Range

            </div>


            <form
                method="GET"
                action="resort_dashboard.php#analytics"
                class="filter-form"
            >
<input type="hidden" name="calendar_year" value="<?php echo (int)$calendar_year; ?>">
<input type="hidden" name="calendar_month" value="<?php echo (int)$calendar_month; ?>">


                <div class="date-group">

                    <label for="start_date">
                        Start Date
                    </label>

                    <input
                        type="date"
                        id="start_date"
                        name="start_date"
                        value="<?php
                            echo htmlspecialchars(
                                $resort_analytics_start_date
                            );
                        ?>"
                        max="<?php
                            echo htmlspecialchars(
                                $resort_analytics_today
                            );
                        ?>"
                        required
                    >

                </div>


                <div class="date-group">

                    <label for="end_date">
                        End Date
                    </label>

                    <input
                        type="date"
                        id="end_date"
                        name="end_date"
                        value="<?php
                            echo htmlspecialchars(
                                $resort_analytics_end_date
                            );
                        ?>"
                        max="<?php
                            echo htmlspecialchars(
                                $resort_analytics_today
                            );
                        ?>"
                        required
                    >

                </div>


                <button
                    type="submit"
                    class="btn btn-primary"
                >

                    Apply Filter

                </button>


                <a
                    href="resort_dashboard.php#analytics"
                    class="btn btn-reset"
                >

                    Reset

                </a>


            </form>


            <div class="selected-period">

                Showing analytics from

                <strong>
                    <?php
                    echo htmlspecialchars(
                        $resort_analytics_display_start_date
                    );
                    ?>
                </strong>

                to

                <strong>
                    <?php
                    echo htmlspecialchars(
                        $resort_analytics_display_end_date
                    );
                    ?>
                </strong>

            </div>


        </div>


        <!-- ==================================================
             SUMMARY
        ================================================== -->

        <div class="summary-grid">


            <div class="summary-card">

                <h3>
                    Total Visitors
                </h3>

                <p class="summary-number">

                    <?php
                    echo number_format(
                        $resort_analytics_total_visitors
                    );
                    ?>

                </p>

                <div class="summary-small">

                    Based on check-in records

                </div>

            </div>


            <div class="summary-card">

                <h3>
                    Total Reservations
                </h3>

                <p class="summary-number">

                    <?php
                    echo number_format(
                        $resort_analytics_total_reservations
                    );
                    ?>

                </p>

                <div class="summary-small">

                    Based on visit dates

                </div>

            </div>


            <div class="summary-card">

                <h3>
                    Total Payments
                </h3>

                <p class="summary-number">

                    ₱<?php
                    echo number_format(
                        $resort_analytics_total_payments,
                        2
                    );
                    ?>

                </p>

                <div class="summary-small">

                    Payments within selected period

                </div>

            </div>


            <div class="summary-card">

                <h3>
                    Current Occupancy
                </h3>

                <p class="summary-number">

                    <?php
                    echo number_format(
                        $resort_analytics_occupancy_rate,
                        1
                    );
                    ?>%

                </p>

                <div class="summary-small">

                    Current accommodation status

                </div>

            </div>


        </div>


        <!-- ==================================================
             GRAPHS
        ================================================== -->

        <div class="chart-grid">


            <!-- VISITOR TREND -->

            <div class="chart-card">

                <h2>
                    Visitor Trend
                </h2>

                <p>
                    Daily visitor/check-in activity
                    for the selected date range.
                </p>

                <div class="chart-container">

                    <canvas
                        id="visitorChart"
                    ></canvas>

                </div>

            </div>


            <!-- PAYMENT TREND -->

            <div class="chart-card">

                <h2>
                    Payment Trend
                </h2>

                <p>
                    Daily payment collection
                    for the selected date range.
                </p>

                <div class="chart-container">

                    <canvas
                        id="paymentChart"
                    ></canvas>

                </div>

            </div>


            <!-- RESERVATION STATUS -->

            <div class="chart-card">

                <h2>
                    Reservation Status
                </h2>

                <p>
                    Reservation status based on visit
                    dates within the selected period.
                </p>

                <div class="chart-container">

                    <canvas
                        id="reservationChart"
                    ></canvas>

                </div>

            </div>


            <!-- ACCOMMODATION STATUS -->

            <div class="chart-card">

                <h2>
                    Current Accommodation Status
                </h2>

                <p>
                    Current status of cottages and tents.
                </p>

                <div class="chart-container">

                    <canvas
                        id="accommodationChart"
                    ></canvas>

                </div>

            </div>


        </div>


        <!-- ==================================================
             CURRENT ACCOMMODATION OVERVIEW
        ================================================== -->

        <div class="chart-card">

            <h2>
                Current Accommodation Overview
            </h2>

            <p>
                This section shows the current status
                of all accommodations. It is not affected
                by the selected analytics date range.
            </p>


            <div class="status-grid">


                <div class="status-item">

                    <strong>

                        <?php
                        echo number_format(
                            $resort_analytics_available_count
                        );
                        ?>

                    </strong>

                    <span>
                        Available
                    </span>

                </div>


                <div class="status-item">

                    <strong>

                        <?php
                        echo number_format(
                            $resort_analytics_occupied_count
                        );
                        ?>

                    </strong>

                    <span>
                        Occupied
                    </span>

                </div>


                <div class="status-item">

                    <strong>

                        <?php
                        echo number_format(
                            $resort_analytics_reserved_count
                        );
                        ?>

                    </strong>

                    <span>
                        Reserved
                    </span>

                </div>


                <div class="status-item">

                    <strong>

                        <?php
                        echo number_format(
                            $resort_analytics_maintenance_count
                        );
                        ?>

                    </strong>

                    <span>
                        Maintenance
                    </span>

                </div>


            </div>


            <div class="occupancy-box">

                <div class="occupancy-title">

                    Current Occupancy Rate

                </div>


                <div class="occupancy-value">

                    <?php
                    echo number_format(
                        $resort_analytics_occupancy_rate,
                        1
                    );
                    ?>%

                </div>

            </div>


        </div>


    
</section>



<!-- ==================================================
     VISITOR ACTIVITY
================================================== -->

<div class="dashboard-analytics">


    <div class="analytics-header">


        <div>

            <h2 class="analytics-title">
                📊 Visitor Activity
            </h2>

            <p class="analytics-subtitle">
                Visitor activity for the last 7 days
            </p>

        </div>


        <a
            href="#analytics"
            class="analytics-link"
        >
            View Analytics →
        </a>

    </div>


    <div class="chart-container">


        <?php

        $has_chart_data = false;


        foreach (
            $chart_accommodation
            as $value
        ) {

            if ($value > 0) {

                $has_chart_data = true;

                break;
            }
        }


        if (!$has_chart_data) {

            foreach (
                $chart_day_visitors
                as $value
            ) {

                if ($value > 0) {

                    $has_chart_data = true;

                    break;
                }
            }
        }

        ?>


        <?php if ($has_chart_data): ?>


        <div class="chart-bars">


            <?php

            foreach (
                $chart_dates
                as $index => $date
            ):


                $accommodation =
                    $chart_accommodation[
                        $index
                    ];


                $day_visitors =
                    $chart_day_visitors[
                        $index
                    ];


                $accommodation_height =
                    (
                        $accommodation
                        /
                        $max_chart_value
                    )
                    *
                    $chart_max_height;


                $day_height =
                    (
                        $day_visitors
                        /
                        $max_chart_value
                    )
                    *
                    $chart_max_height;

            ?>


            <div class="chart-group">


                <div
                    class="
                        chart-bar
                        accommodation
                    "
                    style="
                        height:
                        <?php
                        echo
                            $accommodation_height;
                        ?>px;
                    "
                >

                    <?php if (
                        $accommodation > 0
                    ): ?>

                        <span
                            class="chart-value"
                        >

                            <?php
                            echo
                                $accommodation;
                            ?>

                        </span>

                    <?php endif; ?>

                </div>


                <div
                    class="
                        chart-bar
                        day
                    "
                    style="
                        height:
                        <?php
                        echo
                            $day_height;
                        ?>px;
                    "
                >

                    <?php if (
                        $day_visitors > 0
                    ): ?>

                        <span
                            class="chart-value"
                        >

                            <?php
                            echo
                                $day_visitors;
                            ?>

                        </span>

                    <?php endif; ?>

                </div>


            </div>


            <?php endforeach; ?>


        </div>


        <?php else: ?>


        <div class="analytics-empty">

            No visitor activity recorded
            in the last 7 days.

        </div>


        <?php endif; ?>


    </div>


    <?php if ($has_chart_data): ?>


    <div class="chart-labels">


        <?php foreach (
            $chart_dates
            as $date
        ): ?>

            <div class="chart-date">

                <?php

                echo htmlspecialchars(
                    $date
                );

                ?>

            </div>

        <?php endforeach; ?>


    </div>


    <div class="chart-legend">


        <div class="legend-item">

            <span
                class="
                    legend-box
                    legend-accommodation
                "
            ></span>

            Accommodation Guests

        </div>


        <div class="legend-item">

            <span
                class="
                    legend-box
                    legend-day
                "
            ></span>

            Day Visitors

        </div>


    </div>


    <?php endif; ?>


</div>





</div>

</div>

</div>


<script>

/* ==================================================
   RESERVATION DATA
================================================== */

const reservationData =
<?php

echo json_encode(
    $calendar_reservations,
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_HEX_AMP
);

?>;


/* ==================================================
   SHOW RESERVATIONS
================================================== */

function showReservations(date) {

    const details =
        document.getElementById(
            "reservationDetails"
        );


    const title =
        document.getElementById(
            "reservationDetailsTitle"
        );


    const list =
        document.getElementById(
            "reservationDetailsList"
        );


    const reservations =
        reservationData[date] || [];


    /* ==================================================
       FORMAT DATE
    ================================================== */

    const dateObject =
        new Date(
            date + "T00:00:00"
        );


    const formattedDate =
        dateObject.toLocaleDateString(
            "en-US",
            {
                month: "long",
                day: "numeric",
                year: "numeric"
            }
        );


    title.textContent =
        "Reservations for "
        + formattedDate;


    /* ==================================================
       NO RESERVATIONS
    ================================================== */

    if (reservations.length === 0) {

        list.innerHTML = `

            <div class="reservation-empty">

                No reservations for this
                visit date.

            </div>

        `;


        details.style.display =
            "block";


        details.scrollIntoView({
            behavior: "smooth",
            block: "nearest"
        });


        return;
    }


    /* ==================================================
       CLEAR OLD RESULTS
    ================================================== */

    list.innerHTML = "";


    /* ==================================================
       DISPLAY RESERVATIONS
    ================================================== */

    reservations.forEach(
        function(reservation) {

            const item =
                document.createElement(
                    "div"
                );


            item.className =
                "reservation-item";


            const guestName =
                reservation.full_name
                ||
                "Unknown Guest";


            const accommodation =
                reservation.accommodation_code
                ||
                "No Accommodation";


            /*
             * Dashboard calendar display
             * uses RESERVED instead of the
             * database reservation status.
             */

            const status =
                "Reserved";


            const guests =
                reservation.number_of_guests
                ||
                "0";


            item.innerHTML = `

                <strong>
                    ${escapeHtml(
                        guestName
                    )}
                </strong>

                <span>
                    🏡 Accommodation:
                    ${escapeHtml(
                        accommodation
                    )}
                </span>

                <span>
                    👥 Guests:
                    ${escapeHtml(
                        guests
                    )}
                </span>

                <span>
                    📌 Status:
                    ${escapeHtml(
                        status
                    )}
                </span>

            `;


            list.appendChild(item);

        }
    );


    /* ==================================================
       SHOW DETAILS
    ================================================== */

    details.style.display =
        "block";


    details.scrollIntoView({
        behavior: "smooth",
        block: "nearest"
    });
}


/* ==================================================
   ESCAPE HTML
================================================== */

function escapeHtml(value) {

    return String(value)

        .replace(
            /&/g,
            "&amp;"
        )

        .replace(
            /</g,
            "&lt;"
        )

        .replace(
            />/g,
            "&gt;"
        )

        .replace(
            /"/g,
            "&quot;"
        )

        .replace(
            /'/g,
            "&#039;"
        );
}

</script>


<script>
(function () { if (typeof Chart === "undefined") return;


const visitorDates =
    <?php echo $resort_analytics_visitor_dates_json; ?>;

const visitorCounts =
    <?php echo $resort_analytics_visitor_counts_json; ?>;

const paymentDates =
    <?php echo $resort_analytics_payment_dates_json; ?>;

const paymentTotals =
    <?php echo $resort_analytics_payment_totals_json; ?>;

const reservationLabels =
    <?php echo $resort_analytics_reservation_labels_json; ?>;

const reservationValues =
    <?php echo $resort_analytics_reservation_values_json; ?>;

const accommodationLabels =
    <?php echo $resort_analytics_accommodation_labels_json; ?>;

const accommodationValues =
    <?php echo $resort_analytics_accommodation_values_json; ?>;


/* ==================================================
   VISITOR CHART
================================================== */

new Chart(
    document.getElementById("visitorChart"),
    {

        type: "line",

        data: {

            labels: visitorDates,

            datasets: [{

                label: "Visitors",

                data: visitorCounts,

                borderWidth: 2,

                fill: false,

                tension: 0.3

            }]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

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


/* ==================================================
   PAYMENT CHART
================================================== */

new Chart(
    document.getElementById("paymentChart"),
    {

        type: "line",

        data: {

            labels: paymentDates,

            datasets: [{

                label: "Payments",

                data: paymentTotals,

                borderWidth: 2,

                fill: false,

                tension: 0.3

            }]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            scales: {

                y: {

                    beginAtZero: true,

                    ticks: {

                        callback: function(value) {

                            return "₱" +
                                Number(value)
                                .toLocaleString();

                        }

                    }

                }

            }

        }

    }
);


/* ==================================================
   RESERVATION STATUS CHART
================================================== */

new Chart(
    document.getElementById("reservationChart"),
    {

        type: "bar",

        data: {

            labels: reservationLabels,

            datasets: [{

                label: "Reservations",

                data: reservationValues,

                borderWidth: 1

            }]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

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


/* ==================================================
   ACCOMMODATION STATUS CHART
================================================== */

new Chart(
    document.getElementById("accommodationChart"),
    {

        type: "doughnut",

        data: {

            labels: accommodationLabels,

            datasets: [{

                data: accommodationValues,

                borderWidth: 1

            }]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            plugins: {

                legend: {

                    position: "bottom"

                }

            }

        }

    }
);


})();
</script>
</body>

</html>