<?php

session_start();

/* =========================================================
   CHECK LOGIN
========================================================= */

if (!isset($_SESSION["user_id"])) {
    header("Location: resort_login.php");
    exit;
}


/* =========================================================
   DATABASE CONNECTION
========================================================= */

require_once "database.php";


/* =========================================================
   SESSION INFORMATION
========================================================= */

$user_id = (int) $_SESSION["user_id"];

$resort_id = (int) ($_SESSION["resort_id"] ?? 0);

$resort_name = $_SESSION["resort_name"] ?? "My Resort";

$full_name = $_SESSION["full_name"] ?? "";



/* =========================================================
   GET RESORT INFORMATION
========================================================= */

if ($resort_id <= 0) {

    $stmt = $conn->prepare(
        "SELECT
            id,
            resort_name
         FROM resorts
         WHERE user_id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        die("Unable to load resort information.");
    }

    $stmt->bind_param(
        "i",
        $user_id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows === 1) {

        $resort = $result->fetch_assoc();

        $resort_id =
            (int) $resort["id"];

        if (!empty($resort["resort_name"])) {

            $resort_name =
                $resort["resort_name"];
        }

    } else {

        $stmt->close();

        die(
            "Resort account not found. Please log out and log in again."
        );
    }

    $stmt->close();
}


/* =========================================================
   GET CURRENT VISITORS
========================================================= */

$sql = "
    SELECT

        c.id AS checkin_id,

        c.visit_type,

        c.check_in_time,

        c.number_of_guests,

        c.total_amount,

        c.amount_paid,

        c.remaining_balance,

        c.payment_status,

        c.status,

        g.full_name,

        g.contact_number,

        a.accommodation_code,

        a.accommodation_name

    FROM checkins c

    INNER JOIN guests g
        ON c.guest_id = g.id

    LEFT JOIN accommodations a
        ON c.accommodation_id = a.id

    WHERE c.resort_id = ?

    AND c.status = 'Active'

    ORDER BY
        c.check_in_time DESC
";


$visitor_stmt =
    $conn->prepare($sql);


if (!$visitor_stmt) {

    die(
        "Unable to load current visitors. Please check the check-ins and guests database tables."
    );
}


$visitor_stmt->bind_param(
    "i",
    $resort_id
);


if (!$visitor_stmt->execute()) {

    $visitor_stmt->close();

    die(
        "Unable to load current visitors."
    );
}


$visitors =
    $visitor_stmt->get_result();


$visitor_count =
    $visitors->num_rows;

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
        Current Visitors - Alapasco EcoKubo
    </title>


    <style>

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


        /* =====================================================
           SIDEBAR
        ===================================================== */

        .dashboard-container {

            display: flex;

            min-height: 100vh;
        }


        /* =====================================================
           MAIN CONTENT
        ===================================================== */

        .main-content {

            margin-left: 250px;

            width:
                calc(100% - 250px);

            min-height: 100vh;
        }

        .topbar {

            background: white;

            min-height: 65px;

            padding:
                15px 25px;

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

        .page-content {

            padding: 25px;
        }


        /* =====================================================
           PAGE HEADER
        ===================================================== */

        .page-header {

            margin-bottom: 20px;
        }

        .page-header h2 {

            margin:
                0 0 5px;

            color: #1f5d42;

            font-size: 24px;
        }

        .page-header p {

            margin: 0;

            color: #777;

            font-size: 14px;
        }


        /* =====================================================
           SUMMARY
        ===================================================== */

        .summary-card {

            background: white;

            border:
                1px solid #ddd;

            border-radius: 8px;

            padding:
                18px 20px;

            margin-bottom: 20px;
        }

        .summary-card strong {

            display: block;

            color: #1f5d42;

            font-size: 24px;

            margin-bottom: 5px;
        }

        .summary-card span {

            color: #777;

            font-size: 13px;
        }


        /* =====================================================
           TABLE
        ===================================================== */

        .table-container {

            background: white;

            border:
                1px solid #ddd;

            border-radius: 8px;

            overflow-x: auto;
        }

        table {

            width: 100%;

            border-collapse: collapse;

            min-width: 1100px;
        }

        th {

            background: #1f5d42;

            color: white;

            padding:
                13px 12px;

            text-align: left;

            font-size: 13px;

            white-space: nowrap;
        }

        td {

            padding:
                13px 12px;

            border-bottom:
                1px solid #eee;

            font-size: 13px;

            vertical-align: middle;
        }

        tr:last-child td {

            border-bottom: none;
        }

        tr:hover td {

            background: #f8faf9;
        }


        /* =====================================================
           TYPE BADGE
        ===================================================== */

        .type-badge {

            display: inline-block;

            padding:
                5px 9px;

            border-radius: 15px;

            font-size: 11px;

            font-weight: bold;

            white-space: nowrap;
        }

        .accommodation {

            background: #e5f2eb;

            color: #1f5d42;
        }

        .day-visitor {

            background: #eef1f4;

            color: #555;
        }


        /* =====================================================
           STATUS
        ===================================================== */

        .status-badge {

            display: inline-block;

            padding:
                5px 10px;

            border-radius: 15px;

            background: #e5f2eb;

            color: #1f5d42;

            font-size: 11px;

            font-weight: bold;
        }


        /* =====================================================
           BUTTON
        ===================================================== */

        .checkout-btn {

            display: inline-block;

            background: #1f5d42;

            color: white;

            padding:
                7px 11px;

            border-radius: 5px;

            font-size: 12px;

            white-space: nowrap;
        }

        .checkout-btn:hover {

            opacity: 0.9;
        }


        /* =====================================================
           EMPTY
        ===================================================== */

        .empty-state {

            padding:
                45px 20px;

            text-align: center;

            color: #777;
        }

        .empty-state strong {

            display: block;

            color: #555;

            font-size: 16px;

            margin-bottom: 6px;
        }

        .empty-state p {

            margin: 0;

            font-size: 13px;
        }


        /* =====================================================
           MOBILE
        ===================================================== */

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

        }

    </style>

</head>


<body>


<div class="dashboard-container">


    <!-- =====================================================
         SIDEBAR
    ===================================================== -->

    <?php require __DIR__ . '/resort_sidebar.php'; ?>


    <!-- =====================================================
         MAIN CONTENT
    ===================================================== -->

    <div class="main-content">


        <div class="topbar">

            <h3>
                Current Visitors
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


            <div class="page-header">

                <h2>
                    Current Visitors
                </h2>

                <p>
                    Monitor visitors who are currently inside the resort.
                </p>

            </div>


            <!-- SUMMARY -->

            <div class="summary-card">

                <strong>

                    <?php

                    echo $visitor_count;

                    ?>

                </strong>


                <span>
                    Current Visitor Records
                </span>

            </div>


            <!-- TABLE -->

            <div class="table-container">


                <?php if ($visitor_count > 0): ?>


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
                                    Cottage / Tent
                                </th>

                                <th>
                                    Guests
                                </th>

                                <th>
                                    Check-In
                                </th>

                                <th>
                                    Total
                                </th>

                                <th>
                                    Paid
                                </th>

                                <th>
                                    Balance
                                </th>

                                <th>
                                    Payment
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
                            $visitor =
                            $visitors->fetch_assoc()
                        ): ?>


                            <tr>


                                <!-- VISITOR -->

                                <td>

                                    <strong>

                                        <?php

                                        echo htmlspecialchars(
                                            $visitor["full_name"]
                                        );

                                        ?>

                                    </strong>

                                </td>


                                <!-- CONTACT -->

                                <td>

                                    <?php

                                    echo htmlspecialchars(
                                        $visitor["contact_number"]
                                        ?? ""
                                    );

                                    ?>

                                </td>


                                <!-- VISIT TYPE -->

                                <td>


                                    <?php if (
                                        $visitor["visit_type"]
                                        ===
                                        "Day Visitor"
                                    ): ?>


                                        <span
                                            class="
                                                type-badge
                                                day-visitor
                                            "
                                        >

                                            👤 Day Visitor

                                        </span>


                                    <?php else: ?>


                                        <span
                                            class="
                                                type-badge
                                                accommodation
                                            "
                                        >

                                            🏡 Accommodation

                                        </span>


                                    <?php endif; ?>


                                </td>


                                <!-- ACCOMMODATION -->

                                <td>


                                    <?php if (
                                        $visitor["visit_type"]
                                        ===
                                        "Day Visitor"
                                    ): ?>


                                        —


                                    <?php else: ?>


                                        <?php

                                        echo htmlspecialchars(
                                            $visitor[
                                                "accommodation_code"
                                            ]
                                            ??
                                            "—"
                                        );

                                        ?>


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


                                <!-- CHECK IN -->

                                <td>


                                    <?php

                                    if (
                                        !empty(
                                            $visitor[
                                                "check_in_time"
                                            ]
                                        )
                                    ) {

                                        echo date(
                                            "M d, Y h:i A",
                                            strtotime(
                                                $visitor[
                                                    "check_in_time"
                                                ]
                                            )
                                        );

                                    } else {

                                        echo "—";
                                    }

                                    ?>


                                </td>


                                <!-- TOTAL -->

                                <td>


                                    <?php if (
                                        $visitor["visit_type"]
                                        ===
                                        "Day Visitor"
                                    ): ?>


                                        N/A


                                    <?php else: ?>


                                        ₱<?php

                                        echo number_format(
                                            (float)
                                            $visitor[
                                                "total_amount"
                                            ],
                                            2
                                        );

                                        ?>


                                    <?php endif; ?>


                                </td>


                                <!-- PAID -->

                                <td>


                                    <?php if (
                                        $visitor["visit_type"]
                                        ===
                                        "Day Visitor"
                                    ): ?>


                                        N/A


                                    <?php else: ?>


                                        ₱<?php

                                        echo number_format(
                                            (float)
                                            $visitor[
                                                "amount_paid"
                                            ],
                                            2
                                        );

                                        ?>


                                    <?php endif; ?>


                                </td>


                                <!-- BALANCE -->

                                <td>


                                    <?php if (
                                        $visitor["visit_type"]
                                        ===
                                        "Day Visitor"
                                    ): ?>


                                        N/A


                                    <?php else: ?>


                                        ₱<?php

                                        echo number_format(
                                            (float)
                                            $visitor[
                                                "remaining_balance"
                                            ],
                                            2
                                        );

                                        ?>


                                    <?php endif; ?>


                                </td>


                                <!-- PAYMENT -->

                                <td>


                                    <?php if (
                                        $visitor["visit_type"]
                                        ===
                                        "Day Visitor"
                                    ): ?>


                                        N/A


                                    <?php else: ?>


                                        <?php

                                        echo htmlspecialchars(
                                            $visitor[
                                                "payment_status"
                                            ]
                                            ??
                                            "Pending"
                                        );

                                        ?>


                                    <?php endif; ?>


                                </td>


                                <!-- STATUS -->

                                <td>


                                    <span
                                        class="status-badge"
                                    >

                                        <?php

                                        echo htmlspecialchars(
                                            $visitor["status"]
                                        );

                                        ?>

                                    </span>


                                </td>


                                <!-- ACTION -->

                                <td>


                                    <a
                                        href="
                                            checkout.php?checkin_id=<?php
                                            echo (int)
                                                $visitor[
                                                    "checkin_id"
                                                ];
                                            ?>
                                        "
                                        class="checkout-btn"
                                    >

                                        Check-Out

                                    </a>


                                </td>


                            </tr>


                        <?php endwhile; ?>


                        </tbody>


                    </table>


                <?php else: ?>


                    <div class="empty-state">


                        <strong>
                            No Current Visitors
                        </strong>


                        <p>
                            There are currently no visitors checked in.
                        </p>


                    </div>


                <?php endif; ?>


            </div>


        </div>


    </div>


</div>


</body>

</html>


<?php

$visitor_stmt->close();

$conn->close();

?>