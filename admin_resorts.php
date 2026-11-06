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

$admin_name =
    $_SESSION["admin_name"]
    ?? $_SESSION["admin_username"]
    ?? "Administrator";


/* =========================================================
   APPROVE RESORT
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["approve_resort"])
) {

    $resort_id = intval($_POST["approve_resort"]);

    if ($resort_id <= 0) {
        header("Location: admin_resorts.php?approval=invalid");
        exit;
    }


    /* =====================================================
       GET RESORT INFORMATION
    ===================================================== */

    $stmt = $conn->prepare("
        SELECT
            r.id,
            r.user_id,
            r.resort_name,
            r.status,
            u.first_name,
            u.last_name,
            u.full_name,
            u.email
        FROM resorts r
        LEFT JOIN users u
            ON r.user_id = u.id
        WHERE r.id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        header("Location: admin_resorts.php?approval=error");
        exit;
    }

    $stmt->bind_param("i", $resort_id);
    $stmt->execute();

    $resort_result = $stmt->get_result();
    $resort = $resort_result->fetch_assoc();

    $stmt->close();


    /* =====================================================
       CHECK RESORT
    ===================================================== */

    if (!$resort) {
        header("Location: admin_resorts.php?approval=notfound");
        exit;
    }


    /* =====================================================
       CHECK STATUS
    ===================================================== */

    if ($resort["status"] !== "Pending") {
        header("Location: admin_resorts.php?approval=already");
        exit;
    }


    /* =====================================================
       GET OWNER NAME
    ===================================================== */

    $first_name = trim(
        $resort["first_name"] ?? ""
    );

    $last_name = trim(
        $resort["last_name"] ?? ""
    );

    $full_name = trim(
        $resort["full_name"] ?? ""
    );


    if (
        $first_name !== ""
        || $last_name !== ""
    ) {

        $owner_name = trim(
            $first_name . " " . $last_name
        );

    } elseif ($full_name !== "") {

        $owner_name = $full_name;

    } else {

        $owner_name = "Resort Owner";
    }


    /* =====================================================
       UPDATE RESORT STATUS
    ===================================================== */

    $stmt = $conn->prepare("
        UPDATE resorts
        SET status = 'Active'
        WHERE id = ?
        AND status = 'Pending'
    ");

    if (!$stmt) {
        header("Location: admin_resorts.php?approval=error");
        exit;
    }

    $stmt->bind_param(
        "i",
        $resort_id
    );

    $updated = $stmt->execute();

    $affected_rows = $stmt->affected_rows;

    $stmt->close();


    if (
        !$updated
        || $affected_rows <= 0
    ) {

        header(
            "Location: admin_resorts.php?approval=error"
        );

        exit;
    }


    /* =====================================================
       SEND APPROVAL EMAIL
       
       IMPORTANT:
       We do NOT insert into notifications here.
       The notifications table does not have user_id.
    ===================================================== */

    $email_sent = false;


    if (
        !empty($resort["email"])
        &&
        filter_var(
            $resort["email"],
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $email_config =
            __DIR__ . "/email_config.php";


        if (
            file_exists(
                $email_config
            )
        ) {

            require_once $email_config;


            if (
                function_exists(
                    "sendApprovalEmail"
                )
            ) {

                $email_sent =
                    sendApprovalEmail(
                        $resort["email"],
                        $owner_name,
                        $resort["resort_name"]
                    );
            }
        }
    }


    /* =====================================================
       SAVE APPROVAL RESULT
    ===================================================== */

    $_SESSION["approval_message"] =
        "Resort "
        . $resort["resort_name"]
        . " was approved successfully.";


    if ($email_sent === true) {

        $_SESSION["approval_email"] =
            "sent";

    } else {

        $_SESSION["approval_email"] =
            "failed";
    }


    /* =====================================================
       REDIRECT
    ===================================================== */

    header(
        "Location: admin_resorts.php"
    );

    exit;
}


/* =========================================================
   SEARCH
========================================================= */

$search = "";

if (isset($_GET["search"])) {

    $search = trim(
        $_GET["search"]
    );
}


/* =========================================================
   GET RESORTS
========================================================= */

$sql = "
    SELECT
        r.id,
        r.user_id,
        r.resort_name,
        r.contact_number,
        r.profile_picture,
        r.status,
        r.created_at,

        u.first_name,
        u.last_name,
        u.full_name,
        u.email,

        COUNT(DISTINCT a.id)
            AS accommodation_count,

        COUNT(DISTINCT c.id)
            AS current_visitor_count

    FROM resorts r

    LEFT JOIN users u
        ON r.user_id = u.id

    LEFT JOIN accommodations a
        ON a.resort_id = r.id

    LEFT JOIN checkins c
        ON c.resort_id = r.id
";


$params = [];
$types = "";


if ($search !== "") {

    $sql .= "
        WHERE
            r.resort_name LIKE ?
            OR u.first_name LIKE ?
            OR u.last_name LIKE ?
            OR u.full_name LIKE ?
            OR u.email LIKE ?
    ";


    $search_value =
        "%" . $search . "%";


    $params = [
        $search_value,
        $search_value,
        $search_value,
        $search_value,
        $search_value
    ];


    $types = "sssss";
}


$sql .= "
    GROUP BY
        r.id,
        r.user_id,
        r.resort_name,
        r.contact_number,
        r.profile_picture,
        r.status,
        r.created_at,
        u.first_name,
        u.last_name,
        u.full_name,
        u.email

    ORDER BY r.created_at DESC
";


$stmt = $conn->prepare($sql);


if (!$stmt) {

    die(
        "Database query error: "
        . $conn->error
    );
}


if (!empty($params)) {

    $stmt->bind_param(
        $types,
        ...$params
    );
}


$stmt->execute();

$result = $stmt->get_result();


/* =========================================================
   SUMMARY COUNTS
========================================================= */

$total_resorts = 0;
$active_resorts = 0;
$pending_resorts = 0;
$inactive_resorts = 0;


$count_result = $conn->query("
    SELECT
        COUNT(*) AS total_resorts,

        SUM(
            status = 'Active'
        ) AS active_resorts,

        SUM(
            status = 'Pending'
        ) AS pending_resorts,

        SUM(
            status = 'Inactive'
        ) AS inactive_resorts

    FROM resorts
");


if ($count_result) {

    $counts =
        $count_result->fetch_assoc();


    $total_resorts =
        intval(
            $counts["total_resorts"]
            ?? 0
        );


    $active_resorts =
        intval(
            $counts["active_resorts"]
            ?? 0
        );


    $pending_resorts =
        intval(
            $counts["pending_resorts"]
            ?? 0
        );


    $inactive_resorts =
        intval(
            $counts["inactive_resorts"]
            ?? 0
        );
}


/* =========================================================
   APPROVAL MESSAGE
========================================================= */

$approval_message =
    $_SESSION["approval_message"]
    ?? "";

$approval_email =
    $_SESSION["approval_email"]
    ?? "";


unset(
    $_SESSION["approval_message"]
);

unset(
    $_SESSION["approval_email"]
);

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
        Registered Resorts | Alapasco EcoKubo
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
           PAGE CONTENT
        ===================================================== */

        .page-content {
            padding: 25px;
        }


        /* =====================================================
           APPROVAL MESSAGE
        ===================================================== */

        .approval-message {
            padding: 15px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 5px solid;
        }


        .approval-success {
            background: #d9f2e3;
            color: #207044;
            border-left-color: #2f855a;
        }


        .approval-warning {
            background: #fff1c9;
            color: #8a6500;
            border-left-color: #d69e2e;
        }


        .approval-error {
            background: #f9d8d8;
            color: #a52b2b;
            border-left-color: #c53030;
        }


        /* =====================================================
           SUMMARY
        ===================================================== */

        .summary-grid {
            display: grid;
            grid-template-columns:
                repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }


        .summary-card {
            background: white;
            border-radius: 8px;
            padding: 18px;
            box-shadow:
                0 2px 8px
                rgba(0,0,0,0.06);
            border-left:
                5px solid #1f5d42;
        }


        .summary-card.pending {
            border-left-color: #d69e2e;
        }


        .summary-card.active {
            border-left-color: #2f855a;
        }


        .summary-card.inactive {
            border-left-color: #c53030;
        }


        .summary-card h4 {
            margin: 0 0 8px;
            font-size: 12px;
            color: #777;
        }


        .summary-card .number {
            font-size: 28px;
            font-weight: bold;
            color: #1f5d42;
        }


        /* =====================================================
           SEARCH
        ===================================================== */

        .search-box {
            background: white;
            padding: 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow:
                0 2px 8px
                rgba(0,0,0,0.06);
        }


        .search-form {
            display: flex;
            gap: 10px;
        }


        .search-form input {
            flex: 1;
            padding: 11px 13px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 14px;
        }


        .search-form button {
            background: #1f5d42;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 0 20px;
            cursor: pointer;
            font-size: 14px;
        }


        .search-form button:hover {
            background: #174b35;
        }


        .clear-button {
            display: inline-flex;
            align-items: center;
            padding: 0 15px;
            background: #eee;
            color: #555;
            border-radius: 6px;
            font-size: 14px;
        }


        /* =====================================================
           TABLE
        ===================================================== */

        .table-container {
            background: white;
            border-radius: 8px;
            overflow-x: auto;
            box-shadow:
                0 2px 8px
                rgba(0,0,0,0.06);
        }


        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 950px;
        }


        th {
            background: #1f5d42;
            color: white;
            padding: 13px 12px;
            text-align: left;
            font-size: 13px;
            white-space: nowrap;
        }


        td {
            padding: 13px 12px;
            border-bottom: 1px solid #eee;
            font-size: 13px;
            vertical-align: middle;
        }


        tr:hover td {
            background: #f8faf9;
        }


        /* =====================================================
           PROFILE IMAGE
        ===================================================== */

        .resort-image {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e0e0e0;
            cursor: pointer;
            background: #f5f5f5;
        }


        .no-image {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #e8eee9;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: #1f5d42;
        }


        /* =====================================================
           STATUS
        ===================================================== */

        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }


        .status-active {
            background: #d9f2e3;
            color: #207044;
        }


        .status-pending {
            background: #fff1c9;
            color: #8a6500;
        }


        .status-inactive {
            background: #f9d8d8;
            color: #a52b2b;
        }


        /* =====================================================
           BUTTONS
        ===================================================== */

        .action-buttons {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
        }


        .view-btn,
        .approve-btn {
            display: inline-block;
            border: none;
            border-radius: 5px;
            padding: 8px 11px;
            font-size: 12px;
            cursor: pointer;
            color: white;
        }


        .view-btn {
            background: #1f5d42;
        }


        .view-btn:hover {
            background: #174b35;
        }


        .approve-btn {
            background: #2f855a;
        }


        .approve-btn:hover {
            background: #276749;
        }


        /* =====================================================
           MODAL
        ===================================================== */

        .picture-modal {
            position: fixed;
            inset: 0;
            background:
                rgba(0,0,0,0.75);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 3000;
            padding: 20px;
        }


        .picture-modal.show {
            display: flex;
        }


        .picture-modal-content {
            background: white;
            border-radius: 10px;
            padding: 20px;
            max-width: 500px;
            width: 100%;
            text-align: center;
            position: relative;
        }


        .picture-modal-content img {
            width: 100%;
            max-height: 450px;
            object-fit: contain;
            border-radius: 8px;
        }


        .picture-modal-content h3 {
            margin: 15px 0 0;
            color: #1f5d42;
            font-size: 18px;
        }


        .close-picture {
            position: absolute;
            right: 12px;
            top: 10px;
            width: 34px;
            height: 34px;
            border: none;
            border-radius: 50%;
            background: #1f5d42;
            color: white;
            font-size: 20px;
            cursor: pointer;
            z-index: 10;
        }


        /* =====================================================
           EMPTY
        ===================================================== */

        .empty-message {
            text-align: center;
            padding: 40px;
            color: #777;
        }


        /* =====================================================
           RESPONSIVE
        ===================================================== */

        @media (max-width: 1000px) {

            .summary-grid {
                grid-template-columns:
                    repeat(2, 1fr);
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


            .search-form {
                flex-wrap: wrap;
            }


            .search-form button,
            .clear-button {
                min-height: 40px;
            }
        }

    </style>

</head>


<body>


<!-- =========================================================
     SIDEBAR
========================================================= -->

<?php require __DIR__ . '/admin_sidebar.php'; ?>


<!-- =========================================================
     MAIN CONTENT
========================================================= -->

<div class="main-content">


    <?php
    $adminHeaderTitle = 'Registered Resorts';
    $adminHeaderName = $admin_name ?? 'Barangay Admin';
    $adminHeaderRole = 'Barangay Admin';
    $adminHeaderBackUrl = null;
    require __DIR__ . '/admin_header.php';
    ?>


    <div class="page-content">


        <!-- =================================================
             APPROVAL RESULT
        ================================================== -->

        <?php if (
            $approval_message !== ""
        ) { ?>


            <?php if (
                $approval_email === "sent"
            ) { ?>

                <div
                    class="
                        approval-message
                        approval-success
                    "
                >

                    ✅
                    <?php
                    echo htmlspecialchars(
                        $approval_message
                    );
                    ?>

                    <br>

                    📧 Approval email was
                    successfully sent to the
                    resort owner.

                </div>


            <?php } elseif (
                $approval_email === "failed"
            ) { ?>

                <div
                    class="
                        approval-message
                        approval-warning
                    "
                >

                    ⚠️
                    <?php
                    echo htmlspecialchars(
                        $approval_message
                    );
                    ?>

                    <br>

                    ⚠️ Resort approval succeeded,
                    but the approval email could
                    not be sent.

                </div>

            <?php } ?>


        <?php } ?>


        <!-- =================================================
             OTHER APPROVAL MESSAGES
        ================================================== -->

        <?php if (
            isset($_GET["approval"])
        ) { ?>


            <?php if (
                $_GET["approval"] === "notfound"
            ) { ?>

                <div
                    class="
                        approval-message
                        approval-error
                    "
                >
                    ❌ Resort not found.
                </div>


            <?php } elseif (
                $_GET["approval"] === "already"
            ) { ?>

                <div
                    class="
                        approval-message
                        approval-warning
                    "
                >
                    ⚠️ This resort is already
                    Active or is no longer
                    Pending.
                </div>


            <?php } elseif (
                $_GET["approval"] === "invalid"
            ) { ?>

                <div
                    class="
                        approval-message
                        approval-error
                    "
                >
                    ❌ Invalid resort ID.
                </div>


            <?php } elseif (
                $_GET["approval"] === "error"
            ) { ?>

                <div
                    class="
                        approval-message
                        approval-error
                    "
                >
                    ❌ An error occurred while
                    approving the resort.
                </div>

            <?php } ?>


        <?php } ?>


        <!-- =================================================
             SUMMARY
        ================================================== -->

        <div class="summary-grid">


            <div class="summary-card">

                <h4>
                    Total Resorts
                </h4>

                <div class="number">
                    <?php
                    echo $total_resorts;
                    ?>
                </div>

            </div>


            <div class="summary-card active">

                <h4>
                    Active Resorts
                </h4>

                <div class="number">
                    <?php
                    echo $active_resorts;
                    ?>
                </div>

            </div>


            <div class="summary-card pending">

                <h4>
                    Pending Approval
                </h4>

                <div class="number">
                    <?php
                    echo $pending_resorts;
                    ?>
                </div>

            </div>


            <div class="summary-card inactive">

                <h4>
                    Inactive Resorts
                </h4>

                <div class="number">
                    <?php
                    echo $inactive_resorts;
                    ?>
                </div>

            </div>

        </div>


        <!-- =================================================
             SEARCH
        ================================================== -->

        <div class="search-box">

            <form
                method="GET"
                action="admin_resorts.php"
                class="search-form"
            >

                <input
                    type="text"
                    name="search"
                    placeholder="Search resort, owner, or email..."
                    value="<?php
                    echo htmlspecialchars(
                        $search
                    );
                    ?>"
                >


                <button type="submit">
                    🔍 Search
                </button>


                <?php if (
                    $search !== ""
                ) { ?>

                    <a
                        href="admin_resorts.php"
                        class="clear-button"
                    >
                        Clear
                    </a>

                <?php } ?>


            </form>

        </div>


        <!-- =================================================
             RESORT TABLE
        ================================================== -->

        <div class="table-container">


            <?php if (
                $result->num_rows > 0
            ) { ?>


                <table>


                    <thead>

                        <tr>

                            <th>
                                Photo
                            </th>

                            <th>
                                Resort
                            </th>

                            <th>
                                Owner
                            </th>

                            <th>
                                Email
                            </th>

                            <th>
                                Contact
                            </th>

                            <th>
                                Accommodations
                            </th>

                            <th>
                                Current Visitors
                            </th>

                            <th>
                                Status
                            </th>

                            <th>
                                Action
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php while (
                        $resort =
                        $result->fetch_assoc()
                    ) { ?>


                        <?php

                        $first_name =
                            trim(
                                $resort[
                                    "first_name"
                                ] ?? ""
                            );


                        $last_name =
                            trim(
                                $resort[
                                    "last_name"
                                ] ?? ""
                            );


                        $full_name =
                            trim(
                                $resort[
                                    "full_name"
                                ] ?? ""
                            );


                        if (
                            $first_name !== ""
                            ||
                            $last_name !== ""
                        ) {

                            $owner_name =
                                trim(
                                    $first_name
                                    . " "
                                    . $last_name
                                );

                        } elseif (
                            $full_name !== ""
                        ) {

                            $owner_name =
                                $full_name;

                        } else {

                            $owner_name =
                                "N/A";
                        }


                        $status =
                            $resort[
                                "status"
                            ]
                            ?? "Pending";


                        $status_class =
                            strtolower(
                                $status
                            );


                        $profile_picture =
                            trim(
                                $resort[
                                    "profile_picture"
                                ] ?? ""
                            );

                        ?>


                        <tr>


                            <!-- PHOTO -->

                            <td>

                                <?php if (
                                    $profile_picture
                                    !== ""
                                ) { ?>


                                    <img
                                        src="<?php
                                        echo htmlspecialchars(
                                            $profile_picture
                                        );
                                        ?>"
                                        alt="Resort Profile"
                                        class="resort-image"
                                        data-image="<?php
                                        echo htmlspecialchars(
                                            $profile_picture
                                        );
                                        ?>"
                                        data-name="<?php
                                        echo htmlspecialchars(
                                            $resort[
                                                "resort_name"
                                            ]
                                        );
                                        ?>"
                                        onclick="
                                            viewProfilePicture(this)
                                        "
                                    >


                                <?php } else { ?>


                                    <div class="no-image">
                                        🏡
                                    </div>


                                <?php } ?>

                            </td>


                            <!-- RESORT -->

                            <td>

                                <strong>

                                    <?php
                                    echo htmlspecialchars(
                                        $resort[
                                            "resort_name"
                                        ]
                                    );
                                    ?>

                                </strong>

                            </td>


                            <!-- OWNER -->

                            <td>

                                <?php
                                echo htmlspecialchars(
                                    $owner_name
                                );
                                ?>

                            </td>


                            <!-- EMAIL -->

                            <td>

                                <?php
                                echo htmlspecialchars(
                                    $resort[
                                        "email"
                                    ]
                                    ?? "N/A"
                                );
                                ?>

                            </td>


                            <!-- CONTACT -->

                            <td>

                                <?php
                                echo htmlspecialchars(
                                    $resort[
                                        "contact_number"
                                    ]
                                    ?? "N/A"
                                );
                                ?>

                            </td>


                            <!-- ACCOMMODATIONS -->

                            <td>

                                <?php
                                echo intval(
                                    $resort[
                                        "accommodation_count"
                                    ]
                                    ?? 0
                                );
                                ?>

                            </td>


                            <!-- CURRENT VISITORS -->

                            <td>

                                <?php
                                echo intval(
                                    $resort[
                                        "current_visitor_count"
                                    ]
                                    ?? 0
                                );
                                ?>

                            </td>


                            <!-- STATUS -->

                            <td>

                                <span
                                    class="
                                        status
                                        status-<?php
                                        echo htmlspecialchars(
                                            $status_class
                                        );
                                        ?>
                                    "
                                >

                                    <?php
                                    echo htmlspecialchars(
                                        $status
                                    );
                                    ?>

                                </span>

                            </td>


                            <!-- ACTION -->

                            <td>

                                <div
                                    class="
                                        action-buttons
                                    "
                                >


                                    <!-- VIEW DETAILS -->

                                    <a
                                        href="admin_resort_details.php?id=<?php
                                        echo intval(
                                            $resort["id"]
                                        );
                                        ?>"
                                        class="view-btn"
                                    >
                                        👁 View Details
                                    </a>


                                    <!-- APPROVE -->

                                    <?php if (
                                        $status === "Pending"
                                    ) { ?>


                                        <form
                                            method="POST"
                                            action="admin_resorts.php"
                                            style="
                                                display:inline;
                                                margin:0;
                                            "
                                            onsubmit="
                                                return confirm(
                                                    'Are you sure you want to approve this resort?'
                                                );
                                            "
                                        >

                                            <input
                                                type="hidden"
                                                name="approve_resort"
                                                value="<?php
                                                echo intval(
                                                    $resort["id"]
                                                );
                                                ?>"
                                            >


                                            <button
                                                type="submit"
                                                class="approve-btn"
                                            >
                                                ✓ Approve
                                            </button>

                                        </form>


                                    <?php } ?>


                                </div>

                            </td>

                        </tr>


                    <?php } ?>


                    </tbody>

                </table>


            <?php } else { ?>


                <div class="empty-message">

                    <h3>
                        No Resorts Found
                    </h3>


                    <p>
                        There are no registered
                        resorts matching your
                        search.
                    </p>

                </div>


            <?php } ?>


        </div>

    </div>

</div>


<!-- =========================================================
     PROFILE PICTURE MODAL
========================================================= -->

<div
    class="picture-modal"
    id="pictureModal"
    onclick="closeProfilePicture(event)"
>


    <div
        class="picture-modal-content"
    >


        <button
            type="button"
            class="close-picture"
            onclick="closeProfilePicture(event)"
        >
            ×
        </button>


        <img
            src=""
            alt="Resort Profile"
            id="modalPicture"
        >


        <h3
            id="modalPictureName"
        ></h3>


    </div>

</div>


<script>

/* =========================================================
   PROFILE PICTURE VIEWER
========================================================= */

function viewProfilePicture(element) {

    var image =
        element.getAttribute(
            "data-image"
        );


    var name =
        element.getAttribute(
            "data-name"
        );


    var modal =
        document.getElementById(
            "pictureModal"
        );


    var modalPicture =
        document.getElementById(
            "modalPicture"
        );


    var modalName =
        document.getElementById(
            "modalPictureName"
        );


    if (
        !modal
        ||
        !modalPicture
    ) {

        return;
    }


    modalPicture.src =
        image || "";


    if (modalName) {

        modalName.textContent =
            name || "";
    }


    modal.classList.add(
        "show"
    );
}


/* =========================================================
   CLOSE PROFILE PICTURE
========================================================= */

function closeProfilePicture(event) {

    if (
        event
        &&
        event.target
        &&
        event.target.id !==
            "pictureModal"
    ) {

        return;
    }


    var modal =
        document.getElementById(
            "pictureModal"
        );


    var modalPicture =
        document.getElementById(
            "modalPicture"
        );


    var modalName =
        document.getElementById(
            "modalPictureName"
        );


    if (modal) {

        modal.classList.remove(
            "show"
        );
    }


    if (modalPicture) {

        modalPicture.src = "";
    }


    if (modalName) {

        modalName.textContent = "";
    }
}


/* =========================================================
   ESCAPE KEY
========================================================= */

document.addEventListener(
    "keydown",
    function(event) {

        if (
            event.key === "Escape"
        ) {

            closeProfilePicture();
        }

    }
);

</script>


</body>
</html>