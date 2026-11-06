<?php

session_start();

/* =========================
   ADMIN LOGIN CHECK
========================= */

if (!isset($_SESSION["admin_id"])) {
    header("Location: admin_login.php");
    exit;
}

/* =========================
   GET RESORT ID
========================= */

$resort_id = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

if (!$resort_id) {
    header("Location: admin_resorts.php?error=" . urlencode("Invalid resort ID."));
    exit;
}

/* =========================
   ADMIN NAME
========================= */

$admin_name = $_SESSION["admin_full_name"]
    ?? $_SESSION["admin_name"]
    ?? $_SESSION["admin_username"]
    ?? "Administrator";

require_once __DIR__ . "/database.php";

/* =========================
   RESORT INFORMATION
========================= */

$resort = null;

$stmt = $conn->prepare("
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
        u.email

    FROM resorts r

    LEFT JOIN users u
        ON r.user_id = u.id

    WHERE r.id = ?

    LIMIT 1
");

if (!$stmt) {
    die("Unable to prepare resort details query.");
}

$stmt->bind_param("i", $resort_id);

if (!$stmt->execute()) {
    die("Unable to load resort details.");
}

$result = $stmt->get_result();

$resort = $result->fetch_assoc();

$stmt->close();

/* =========================
   RESORT NOT FOUND
========================= */

if (!$resort) {
    header(
        "Location: admin_resorts.php?error=" .
        urlencode("Resort not found.")
    );

    exit;
}

/* =========================
   OWNER NAME
========================= */

$owner_name = trim(
    ($resort["first_name"] ?? "") .
    " " .
    ($resort["last_name"] ?? "")
);

if ($owner_name === "") {
    $owner_name =
        $resort["full_name"] ??
        "N/A";
}

/* =========================
   ACCOMMODATIONS
========================= */

$accommodations = [];

$accommodation_stmt = $conn->prepare("
    SELECT *
    FROM accommodations
    WHERE resort_id = ?
    ORDER BY id DESC
");

if ($accommodation_stmt) {

    $accommodation_stmt->bind_param(
        "i",
        $resort_id
    );

    if ($accommodation_stmt->execute()) {

        $accommodation_result =
            $accommodation_stmt->get_result();

        while (
            $row =
            $accommodation_result->fetch_assoc()
        ) {
            $accommodations[] = $row;
        }
    }

    $accommodation_stmt->close();
}

/* =========================
   CHECK-INS / VISITORS
========================= */

$visitors = [];

$visitor_stmt = $conn->prepare("
    SELECT g.full_name AS guest_name, c.*
    FROM checkins c
    LEFT JOIN guests g
        ON g.id = c.guest_id
        AND g.resort_id = c.resort_id
    WHERE c.resort_id = ?
    ORDER BY c.id DESC
");

if ($visitor_stmt) {

    $visitor_stmt->bind_param(
        "i",
        $resort_id
    );

    if ($visitor_stmt->execute()) {

        $visitor_result =
            $visitor_stmt->get_result();

        while (
            $row =
            $visitor_result->fetch_assoc()
        ) {
            $visitors[] = $row;
        }
    }

    $visitor_stmt->close();
}

/* =========================
   COUNTS
========================= */

$total_accommodations =
    count($accommodations);

$total_visitors =
    count($visitors);

/* =========================
   STATUS
========================= */

$status =
    $resort["status"] ?? "Unknown";

$status_class =
    strtolower($status);

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
    Resort Details | Alapasco EcoKubo
</title>

<link
    rel="stylesheet"
    href="style.css"
>

<style>

/* =========================
   GENERAL
========================= */

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f5f7f6;
    color: #333;
}

.page {
    display: flex;
    min-height: 100vh;
}


/* =========================
   MAIN CONTENT
========================= */

.page-content {
    padding: 25px;
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

.back-button {
    display: inline-block;
    margin-bottom: 15px;
    padding: 9px 13px;
    background: #e8f0ec;
    color: #1f5d42;
    text-decoration: none;
    border-radius: 6px;
    font-size: 13px;
}

.back-button:hover {
    opacity: 0.85;
}



/* =========================
   RESORT HEADER
========================= */

.resort-header {
    background: white;
    border-radius: 12px;
    padding: 25px;
    box-shadow:
        0 2px 8px rgba(0,0,0,0.06);
    display: flex;
    align-items: center;
    gap: 25px;
    margin-bottom: 20px;
}

.resort-header-picture {
    width: 110px;
    height: 110px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid #1f5d42;
    background: #eef3f0;
    cursor: pointer;
}

.resort-header-placeholder {
    width: 110px;
    height: 110px;
    border-radius: 50%;
    border: 4px solid #1f5d42;
    background: #eef3f0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 45px;
}

.resort-header-info {
    flex: 1;
}

.resort-header-info h2 {
    margin: 0 0 8px;
    color: #1f5d42;
    font-size: 25px;
}

.resort-header-info p {
    margin: 5px 0;
    color: #666;
    font-size: 14px;
}


/* =========================
   STATUS
========================= */

.status {
    display: inline-block;
    padding: 7px 13px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
    margin-top: 8px;
}

.status.pending {
    background: #fff3cd;
    color: #856404;
}

.status.active {
    background: #d4edda;
    color: #155724;
}

.status.inactive {
    background: #f8d7da;
    color: #721c24;
}

.status.unknown {
    background: #eee;
    color: #555;
}


/* =========================
   SUMMARY CARDS
========================= */

.summary-grid {
    display: grid;
    grid-template-columns:
        repeat(2, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 20px;
}

.summary-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow:
        0 2px 8px rgba(0,0,0,0.06);
}

.summary-card h3 {
    margin: 0 0 8px;
    color: #777;
    font-size: 13px;
}

.summary-card .number {
    font-size: 28px;
    font-weight: bold;
    color: #1f5d42;
}


/* =========================
   INFORMATION GRID
========================= */

.info-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow:
        0 2px 8px rgba(0,0,0,0.06);
    margin-bottom: 20px;
}

.info-card h2 {
    margin-top: 0;
    color: #1f5d42;
    font-size: 19px;
    border-bottom: 1px solid #eee;
    padding-bottom: 12px;
}

.info-grid {
    display: grid;
    grid-template-columns:
        repeat(2, minmax(0, 1fr));
    gap: 15px 25px;
}

.info-item {
    padding: 10px 0;
}

.info-label {
    font-size: 11px;
    color: #888;
    margin-bottom: 5px;
}

.info-value {
    font-size: 14px;
    font-weight: 600;
    color: #333;
    word-break: break-word;
}


/* =========================
   TABLE
========================= */

.data-table-wrapper {
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
}

.data-table th {
    background: #f1f4f2;
    color: #555;
    font-size: 12px;
    padding: 12px;
    text-align: left;
}

.data-table td {
    padding: 12px;
    border-bottom: 1px solid #eee;
    font-size: 13px;
}

.data-table tr:last-child td {
    border-bottom: none;
}


/* =========================
   EMPTY
========================= */

.empty-state {
    text-align: center;
    padding: 30px 15px;
    color: #777;
}

.empty-state .icon {
    font-size: 40px;
    margin-bottom: 8px;
}


/* =========================
   PICTURE MODAL
========================= */

.picture-modal {
    display: none;
    position: fixed;
    z-index: 3000;
    inset: 0;
    background: rgba(0,0,0,0.78);
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.picture-modal.show {
    display: flex;
}

.picture-modal-content {
    position: relative;
    background: white;
    border-radius: 12px;
    padding: 15px;
    max-width: 90vw;
    max-height: 90vh;
    text-align: center;
}

.picture-modal-content img {
    max-width: 80vw;
    max-height: 75vh;
    border-radius: 8px;
}

.picture-close {
    position: absolute;
    top: -12px;
    right: -12px;
    width: 36px;
    height: 36px;
    border: none;
    border-radius: 50%;
    background: #1f5d42;
    color: white;
    font-size: 25px;
    cursor: pointer;
}


/* =========================
   RESPONSIVE
========================= */

@media (max-width: 900px) {


    .info-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {

    .page {
        display: block;
    }


    .page-content { padding: 15px; }
    .topbar { padding: 12px 15px; }
    .topbar h3 { font-size: 16px; }

    .resort-header {
        flex-direction: column;
        text-align: center;
    }

    .summary-grid {
        grid-template-columns: 1fr;
    }
}

</style>

</head>

<body>

<div class="page">


<!-- =========================
     SIDEBAR
========================= -->

<?php require __DIR__ . '/admin_sidebar.php'; ?>


<!-- =========================
     MAIN CONTENT
========================= -->

<div class="main-content">


    <!-- TOPBAR -->

    <?php
    $adminHeaderTitle = 'Resort Details';
    $adminHeaderName = $admin_name ?? 'Barangay Admin';
    $adminHeaderRole = 'Barangay Tourism Desk';
    $adminHeaderBackUrl = null;
    require __DIR__ . '/admin_header.php';
    ?>


    <div class="page-content">
    <a class="back-button" href="admin_resorts.php">← Back</a>

    <!-- =========================
         RESORT HEADER
    ========================= -->

    <div class="resort-header">


        <?php if (
            !empty(
                $resort["profile_picture"]
            )
        ): ?>

            <img
                src="<?php
                echo htmlspecialchars(
                    $resort["profile_picture"],
                    ENT_QUOTES,
                    "UTF-8"
                );
                ?>"
                alt="Resort Profile Picture"
                class="resort-header-picture"
                onclick="openPictureModal(this.src)"
            >

        <?php else: ?>

            <div class="resort-header-placeholder">
                🏡
            </div>

        <?php endif; ?>


        <div class="resort-header-info">

            <h2>

                <?php
                echo htmlspecialchars(
                    $resort["resort_name"]
                );
                ?>

            </h2>


            <p>
                👤 Registered User:
                <strong>
                    <?php
                    echo htmlspecialchars(
                        $owner_name
                    );
                    ?>
                </strong>
            </p>


            <p>
                📧
                <?php
                echo htmlspecialchars(
                    $resort["email"]
                    ?? "No email"
                );
                ?>
            </p>


            <p>
                📞
                <?php
                echo htmlspecialchars(
                    $resort["contact_number"]
                    ?? "No contact number"
                );
                ?>
            </p>


            <span
                class="status <?php
                echo htmlspecialchars(
                    $status_class
                );
                ?>"
            >

                <?php

                if ($status === "Pending") {
                    echo "🟡 ";
                } elseif ($status === "Active") {
                    echo "🟢 ";
                } elseif ($status === "Inactive") {
                    echo "🔴 ";
                }

                echo htmlspecialchars(
                    $status
                );

                ?>

            </span>

        </div>

    </div>


    <!-- =========================
         SUMMARY
    ========================= -->

    <div class="summary-grid">


        <div class="summary-card">

            <h3>
                🏡 Accommodations
            </h3>

            <div class="number">
                <?php
                echo $total_accommodations;
                ?>
            </div>

        </div>


        <div class="summary-card">

            <h3>
                👥 Recorded Check-Ins
            </h3>

            <div class="number">
                <?php
                echo $total_visitors;
                ?>
            </div>

        </div>

    </div>


    <!-- =========================
         REGISTERED INFORMATION
    ========================= -->

    <div class="info-card">

        <h2>
            Resort Information
        </h2>


        <div class="info-grid">


            <div class="info-item">

                <div class="info-label">
                    Resort / Rest House Name
                </div>

                <div class="info-value">

                    <?php
                    echo htmlspecialchars(
                        $resort["resort_name"]
                    );
                    ?>

                </div>

            </div>


            <div class="info-item">

                <div class="info-label">
                    Resort Contact Number
                </div>

                <div class="info-value">

                    <?php
                    echo htmlspecialchars(
                        $resort["contact_number"]
                        ?? "N/A"
                    );
                    ?>

                </div>

            </div>


            <div class="info-item">

                <div class="info-label">
                    Registered User
                </div>

                <div class="info-value">

                    <?php
                    echo htmlspecialchars(
                        $owner_name
                    );
                    ?>

                </div>

            </div>


            <div class="info-item">

                <div class="info-label">
                    Email Address
                </div>

                <div class="info-value">

                    <?php
                    echo htmlspecialchars(
                        $resort["email"]
                        ?? "N/A"
                    );
                    ?>

                </div>

            </div>


            <div class="info-item">

                <div class="info-label">
                    Account Status
                </div>

                <div class="info-value">

                    <?php
                    echo htmlspecialchars(
                        $status
                    );
                    ?>

                </div>

            </div>


            <div class="info-item">

                <div class="info-label">
                    Registration Date
                </div>

                <div class="info-value">

                    <?php
                    echo htmlspecialchars(
                        $resort["created_at"]
                        ?? "N/A"
                    );
                    ?>

                </div>

            </div>


        </div>

    </div>


    <!-- =========================
         ACCOMMODATIONS
    ========================= -->

    <div class="info-card">

        <h2>
            🏡 Registered Accommodations
        </h2>


        <?php if (
            count($accommodations) > 0
        ): ?>

            <div class="data-table-wrapper">

                <table class="data-table">

                    <thead>

                        <tr>

                            <?php

                            $accommodation_columns =
                                array_keys(
                                    $accommodations[0]
                                );

                            foreach (
                                $accommodation_columns
                                as $column
                            ):

                                if (
                                    in_array(
                                        $column,
                                        [
                                            "id",
                                            "resort_id"
                                        ]
                                    )
                                ) {
                                    continue;
                                }

                            ?>

                                <th>

                                    <?php
                                    echo htmlspecialchars(
                                        ucwords(
                                            str_replace(
                                                "_",
                                                " ",
                                                $column
                                            )
                                        )
                                    );
                                    ?>

                                </th>

                            <?php endforeach; ?>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach (
                            $accommodations
                            as $accommodation
                        ): ?>

                            <tr>

                                <?php foreach (
                                    $accommodation
                                    as $column =>
                                    $value
                                ): ?>

                                    <?php

                                    if (
                                        in_array(
                                            $column,
                                            [
                                                "id",
                                                "resort_id"
                                            ]
                                        )
                                    ) {
                                        continue;
                                    }

                                    ?>

                                    <td>

                                        <?php

                                        if (
                                            $value === null ||
                                            $value === ""
                                        ) {
                                            echo "—";
                                        } else {
                                            echo htmlspecialchars(
                                                (string)$value
                                            );
                                        }

                                        ?>

                                    </td>

                                <?php endforeach; ?>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <div class="empty-state">

                <div class="icon">
                    🏡
                </div>

                <p>
                    No accommodations have been registered
                    for this resort yet.
                </p>

            </div>

        <?php endif; ?>

    </div>


    <!-- =========================
         VISITORS
    ========================= -->

    <div class="info-card">

        <h2>
            👥 Recorded Visitors / Check-Ins
        </h2>


        <?php if (
            count($visitors) > 0
        ): ?>

            <div class="data-table-wrapper">

                <table class="data-table">

                    <thead>

                        <tr>

                            <?php

                            $visitor_columns =
                                array_keys(
                                    $visitors[0]
                                );

                            foreach (
                                $visitor_columns
                                as $column
                            ):

                                if (
                                    in_array(
                                        $column,
                                        [
                                            "id",
                                            "resort_id"
                                        ]
                                    )
                                ) {
                                    continue;
                                }

                            ?>

                                <th>

                                    <?php
                                    echo htmlspecialchars(
                                        ucwords(
                                            str_replace(
                                                "_",
                                                " ",
                                                $column
                                            )
                                        )
                                    );
                                    ?>

                                </th>

                            <?php endforeach; ?>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach (
                            $visitors
                            as $visitor
                        ): ?>

                            <tr>

                                <?php foreach (
                                    $visitor
                                    as $column =>
                                    $value
                                ): ?>

                                    <?php

                                    if (
                                        in_array(
                                            $column,
                                            [
                                                "id",
                                                "resort_id"
                                            ]
                                        )
                                    ) {
                                        continue;
                                    }

                                    ?>

                                    <td>

                                        <?php

                                        if (
                                            $value === null ||
                                            $value === ""
                                        ) {
                                            echo "—";
                                        } else {
                                            echo htmlspecialchars(
                                                (string)$value
                                            );
                                        }

                                        ?>

                                    </td>

                                <?php endforeach; ?>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <div class="empty-state">

                <div class="icon">
                    👥
                </div>

                <p>
                    No visitor check-in records
                    have been recorded for this resort.
                </p>

            </div>

        <?php endif; ?>

    </div>


    </div>

</div>

</div>


<!-- =========================
     PICTURE MODAL
========================= -->

<div
    id="pictureModal"
    class="picture-modal"
    onclick="closePictureModal(event)"
>

    <div
        class="picture-modal-content"
        onclick="event.stopPropagation();"
    >

        <button
            type="button"
            class="picture-close"
            onclick="closePictureModal();"
        >
            ×
        </button>

        <img
            id="modalPicture"
            src=""
            alt="Resort Profile Picture"
        >

    </div>

</div>


<script>

function openPictureModal(image) {

    var modal =
        document.getElementById(
            "pictureModal"
        );

    var modalPicture =
        document.getElementById(
            "modalPicture"
        );

    if (!image) {
        return;
    }

    modalPicture.src = image;

    modal.classList.add("show");
}


function closePictureModal(event) {

    if (
        event &&
        event.target &&
        event.target.id !== "pictureModal"
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

    modal.classList.remove("show");

    modalPicture.src = "";
}


document.addEventListener(
    "keydown",
    function(event) {

        if (event.key === "Escape") {

            closePictureModal();

        }

    }
);

</script>

</body>

</html>