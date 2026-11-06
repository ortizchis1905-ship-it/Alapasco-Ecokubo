<?php

session_start();

require_once "database.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: resort_login.php");
    exit;
}

$user_id = intval($_SESSION["user_id"]);

$resort_name = $_SESSION["resort_name"] ?? "Your Resort";
$full_name = $_SESSION["full_name"] ?? "User";

$message = "";
$message_type = "";


/* =====================================================
   GET OR CREATE RESORT
===================================================== */

$stmt = $conn->prepare(
    "SELECT id
     FROM resorts
     WHERE user_id = ?
     LIMIT 1"
);

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows > 0) {

    $row = $result->fetch_assoc();

    $resort_id = intval($row["id"]);

} else {

    $user_stmt = $conn->prepare(
        "SELECT resort_name, contact_number
         FROM users
         WHERE id = ?
         LIMIT 1"
    );

    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();

    $user_result = $user_stmt->get_result();
    $user = $user_result->fetch_assoc();

    $real_resort_name = $user["resort_name"] ?? $resort_name;
    $contact_number = $user["contact_number"] ?? "";

    $create = $conn->prepare(
        "INSERT INTO resorts
        (
            user_id,
            resort_name,
            contact_number,
            status
        )
        VALUES
        (?, ?, ?, 'Active')"
    );

    $create->bind_param(
        "iss",
        $user_id,
        $real_resort_name,
        $contact_number
    );

    $create->execute();

    $resort_id = intval($conn->insert_id);

    $create->close();
    $user_stmt->close();
}

$stmt->close();


/* =====================================================
   ADD GUEST / VISITOR
===================================================== */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["add_guest"])
) {

    $guest_name = trim(
        $_POST["full_name"] ?? ""
    );

    $contact_number = trim(
        $_POST["contact_number"] ?? ""
    );

    $barangay = trim(
        $_POST["barangay"] ?? ""
    );

    $municipality = trim(
        $_POST["municipality"] ?? ""
    );

    $province = trim(
        $_POST["province"] ?? ""
    );

    $number_of_guests = intval(
        $_POST["number_of_guests"] ?? 0
    );

    $visit_type = trim(
        $_POST["visit_type"] ?? ""
    );


    /* =================================================
       VALIDATION
    ================================================= */

    if ($guest_name === "") {

        $message =
            "Please enter the guest's full name.";

        $message_type = "error";

    } elseif (
        $contact_number !== ""
        && (
            !ctype_digit($contact_number)
            || strlen($contact_number) > 11
        )
    ) {

        $message =
            "Contact number must contain numbers only and must not exceed 11 digits.";

        $message_type = "error";

    } elseif ($number_of_guests <= 0) {

        $message =
            "Number of guests must be at least 1.";

        $message_type = "error";

    } elseif (
        !in_array(
            $visit_type,
            ["Accommodation", "Day Visitor"]
        )
    ) {

        $message =
            "Please select a valid visit type.";

        $message_type = "error";

    } else {

        $stmt = $conn->prepare(
            "INSERT INTO guests
            (
                resort_id,
                full_name,
                contact_number,
                barangay,
                municipality,
                province,
                number_of_guests,
                visit_type
            )
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            "isssssis",
            $resort_id,
            $guest_name,
            $contact_number,
            $barangay,
            $municipality,
            $province,
            $number_of_guests,
            $visit_type
        );

        if ($stmt->execute()) {

            $message =
                "Guest / visitor added successfully.";

            $message_type = "success";

        } else {

            $message =
                "Failed to add guest: " . $stmt->error;

            $message_type = "error";
        }

        $stmt->close();
    }
}


/* =====================================================
   EDIT GUEST / VISITOR
===================================================== */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["edit_guest"])
) {

    $guest_id = intval(
        $_POST["guest_id"] ?? 0
    );

    $guest_name = trim(
        $_POST["edit_full_name"] ?? ""
    );

    $contact_number = trim(
        $_POST["edit_contact_number"] ?? ""
    );

    $barangay = trim(
        $_POST["edit_barangay"] ?? ""
    );

    $municipality = trim(
        $_POST["edit_municipality"] ?? ""
    );

    $province = trim(
        $_POST["edit_province"] ?? ""
    );

    $number_of_guests = intval(
        $_POST["edit_number_of_guests"] ?? 0
    );

    $visit_type = trim(
        $_POST["edit_visit_type"] ?? ""
    );


    /* =================================================
       VALIDATION
    ================================================= */

    if (
        $guest_id <= 0
        || $guest_name === ""
    ) {

        $message =
            "Please complete all required information.";

        $message_type = "error";

    } elseif (
        $contact_number !== ""
        && (
            !ctype_digit($contact_number)
            || strlen($contact_number) > 11
        )
    ) {

        $message =
            "Contact number must contain numbers only and must not exceed 11 digits.";

        $message_type = "error";

    } elseif ($number_of_guests <= 0) {

        $message =
            "Number of guests must be at least 1.";

        $message_type = "error";

    } elseif (
        !in_array(
            $visit_type,
            ["Accommodation", "Day Visitor"]
        )
    ) {

        $message =
            "Please select a valid visit type.";

        $message_type = "error";

    } else {

        $stmt = $conn->prepare(
            "UPDATE guests
             SET
                full_name = ?,
                contact_number = ?,
                barangay = ?,
                municipality = ?,
                province = ?,
                number_of_guests = ?,
                visit_type = ?
             WHERE id = ?
             AND resort_id = ?"
        );

        $stmt->bind_param(
            "sssssissi",
            $guest_name,
            $contact_number,
            $barangay,
            $municipality,
            $province,
            $number_of_guests,
            $visit_type,
            $guest_id,
            $resort_id
        );

        if ($stmt->execute()) {

            $message =
                "Guest / visitor updated successfully.";

            $message_type = "success";

        } else {

            $message =
                "Failed to update guest: " . $stmt->error;

            $message_type = "error";
        }

        $stmt->close();
    }
}


/* =====================================================
   GET GUESTS / VISITORS
===================================================== */

$guests = [];

$stmt = $conn->prepare(
    "SELECT
        id,
        full_name,
        contact_number,
        barangay,
        municipality,
        province,
        number_of_guests,
        visit_type,
        created_at
     FROM guests
     WHERE resort_id = ?
     ORDER BY id DESC"
);

$stmt->bind_param(
    "i",
    $resort_id
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $guests[] = $row;
}

$stmt->close();

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

<title>Guests / Visitors - Alapasco EcoKubo</title>


<style>

/* =====================================================
   GENERAL
===================================================== */

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

.dashboard-container {
    display: flex;
    min-height: 100vh;
}

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
    color: #777;
    font-size: 12px;
}


/* =====================================================
   PAGE CONTENT
===================================================== */

.page-content {
    padding: 25px;
}


/* =====================================================
   INTRO
===================================================== */

.page-intro {
    background: white;
    padding: 22px;
    border-radius: 10px;
    margin-bottom: 20px;
    border: 1px solid #e5e5e5;
}

.page-intro h1 {
    margin: 0 0 7px;
    color: #1f5d42;
    font-size: 23px;
}

.page-intro p {
    margin: 0;
    color: #777;
    font-size: 13px;
}


/* =====================================================
   SECTION
===================================================== */

.section {
    background: white;
    padding: 22px;
    border-radius: 10px;
    margin-bottom: 20px;
    border: 1px solid #e5e5e5;
}

.section-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 18px;
}

.section-title h2 {
    margin: 0;
    color: #1f5d42;
    font-size: 18px;
}


/* =====================================================
   FORM
===================================================== */

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 17px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group.full {
    grid-column: 1 / -1;
}

.form-group label {
    margin-bottom: 7px;
    font-size: 13px;
    font-weight: bold;
    color: #444;
}

.form-group input,
.form-group textarea,
.form-group select {

    width: 100%;

    padding: 12px;

    border: 1px solid #d8d8d8;

    border-radius: 7px;

    font-size: 14px;

    background: white;

    min-height: 46px;
}

.form-group textarea {
    min-height: 90px;
    resize: vertical;
}

.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {

    outline: none;

    border-color: #1f5d42;
}


/* =====================================================
   ADDRESS GRID
===================================================== */

.address-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
}


/* =====================================================
   CONTACT NOTE
===================================================== */

.input-note {
    margin-top: 5px;
    font-size: 11px;
    color: #888;
}


/* =====================================================
   BUTTON
===================================================== */

.btn {
    border: none;

    background: #1f5d42;

    color: white;

    padding: 11px 18px;

    border-radius: 6px;

    cursor: pointer;

    font-size: 13px;

    min-height: 42px;

    touch-action: manipulation;
}

.btn:hover {
    opacity: 0.92;
}

.btn-edit {
    background: #1f5d42;
    min-width: 70px;
}


/* =====================================================
   CHECK-IN BUTTON
===================================================== */

.btn-checkin {
    display: inline-block;

    background: #2e7d32;

    color: white;

    min-width: 95px;

    text-align: center;

    margin-right: 5px;

    padding: 11px 14px;

    border-radius: 6px;

    font-size: 13px;

    font-weight: bold;

    min-height: 42px;

    touch-action: manipulation;
}

.btn-checkin:hover {
    background: #256b29;
}


/* =====================================================
   ACTION CELL
===================================================== */

.action-buttons {
    display: flex;

    gap: 6px;

    align-items: center;

    flex-wrap: wrap;
}


/* =====================================================
   MESSAGE
===================================================== */

.message {
    padding: 13px 15px;

    border-radius: 7px;

    margin-bottom: 20px;

    font-size: 14px;
}

.message.success {
    background: #e8f5e9;
    color: #2e7d32;
    border: 1px solid #c8e6c9;
}

.message.error {
    background: #fdecea;
    color: #c62828;
    border: 1px solid #f5c6c6;
}


/* =====================================================
   TABLE
===================================================== */

.table-wrapper {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1120px;
}

th,
td {
    padding: 13px 12px;
    border-bottom: 1px solid #eee;
    text-align: left;
    font-size: 13px;
    vertical-align: middle;
}

th {
    background: #f5f8f6;
    color: #1f5d42;
    font-weight: bold;
}

tbody tr:hover {
    background: #fafcfb;
}


/* =====================================================
   VISIT TYPE BADGE
===================================================== */

.visit-badge {
    display: inline-block;

    padding: 6px 10px;

    border-radius: 20px;

    font-size: 12px;

    font-weight: bold;

    white-space: nowrap;
}

.visit-accommodation {
    background: #e8f5e9;
    color: #2e7d32;
}

.visit-day {
    background: #fff4df;
    color: #9a6700;
}


/* =====================================================
   EMPTY
===================================================== */

.empty {
    text-align: center;
    padding: 40px 20px;
    color: #888;
}


/* =====================================================
   MODAL
===================================================== */

.modal {

    display: none;

    position: fixed;

    z-index: 2000;

    left: 0;
    top: 0;

    width: 100%;
    height: 100%;

    background: rgba(0,0,0,0.5);

    padding: 20px;

    overflow-y: auto;
}

.modal.show {
    display: flex;

    justify-content: center;

    align-items: flex-start;
}

.modal-content {

    background: white;

    width: 100%;

    max-width: 650px;

    margin-top: 40px;

    border-radius: 10px;

    overflow: hidden;

    box-shadow: 0 10px 30px rgba(0,0,0,.2);
}

.modal-header {

    background: #1f5d42;

    color: white;

    padding: 18px 20px;

    display: flex;

    justify-content: space-between;

    align-items: center;
}

.modal-header h2 {
    margin: 0;
    font-size: 18px;
}

.close-modal {

    background: transparent;

    border: none;

    color: white;

    font-size: 25px;

    cursor: pointer;

    line-height: 1;
}

.modal-body {
    padding: 22px;
}


/* =====================================================
   RESPONSIVE
===================================================== */

@media (max-width: 900px) {

    .address-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {

    .main-content {
        margin-left: 200px;
        width: calc(100% - 200px);
    }

    .form-grid {
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

<main class="main-content">


    <!-- TOPBAR -->

    <div class="topbar">

        <div>

            <h3>
                Guests / Visitors
            </h3>

        </div>

        <div class="resort-header-account"><?php require __DIR__ . '/resort_header_notifications.php'; ?>
<div class="user-info">

            <strong>
                <?php
                echo htmlspecialchars($full_name);
                ?>
            </strong>

            <span>
                Resort Staff
            </span>

        </div></div>

    </div>


    <div class="page-content">


        <!-- INTRO -->

        <div class="page-intro">

            <h1>
                Guest / Visitor Management
            </h1>

            <p>
                Record and manage guests and visitors staying at or visiting your resort.
            </p>

        </div>


        <!-- MESSAGE -->

        <?php if ($message !== ""): ?>

            <div class="message <?php echo htmlspecialchars($message_type); ?>">

                <?php
                echo htmlspecialchars($message);
                ?>

            </div>

        <?php endif; ?>


        <!-- =================================================
             ADD GUEST
        ================================================= -->

        <section class="section">

            <div class="section-title">

                <h2>
                    ➕ Add Guest / Visitor
                </h2>

            </div>


            <form method="POST">

                <div class="form-grid">


                    <!-- FULL NAME -->

                    <div class="form-group">

                        <label>
                            Full Name *
                        </label>

                        <input
                            type="text"
                            name="full_name"
                            placeholder="Enter guest full name"
                            required
                        >

                    </div>


                    <!-- CONTACT -->

                    <div class="form-group">

                        <label>
                            Contact Number
                        </label>

                        <input
                            type="tel"
                            name="contact_number"
                            placeholder="09XXXXXXXXX"
                            maxlength="11"
                            inputmode="numeric"
                            pattern="[0-9]{0,11}"
                            oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)"
                        >

                        <div class="input-note">
                            Maximum 11 digits
                        </div>

                    </div>


                    <!-- ADDRESS -->

                    <div class="form-group full">

                        <label>
                            Address
                        </label>

                        <div class="address-grid">

                            <div class="form-group">

                                <label>
                                    Barangay
                                </label>

                                <input
                                    type="text"
                                    name="barangay"
                                    placeholder="Enter barangay"
                                >

                            </div>


                            <div class="form-group">

                                <label>
                                    Town / Municipality
                                </label>

                                <input
                                    type="text"
                                    name="municipality"
                                    placeholder="Enter town / municipality"
                                >

                            </div>


                            <div class="form-group">

                                <label>
                                    Province
                                </label>

                                <input
                                    type="text"
                                    name="province"
                                    placeholder="Enter province"
                                >

                            </div>

                        </div>

                    </div>


                    <!-- NUMBER OF GUESTS -->

                    <div class="form-group">

                        <label>
                            Number of Guests *
                        </label>

                        <input
                            type="number"
                            name="number_of_guests"
                            min="1"
                            value="1"
                            required
                        >

                    </div>


                    <!-- VISIT TYPE -->

                    <div class="form-group">

                        <label>
                            Visit Type *
                        </label>

                        <select
                            name="visit_type"
                            required
                        >

                            <option value="">
                                Select Visit Type
                            </option>

                            <option value="Accommodation">
                                🏡 Accommodation
                            </option>

                            <option value="Day Visitor">
                                👤 Day Visitor
                            </option>

                        </select>

                    </div>


                    <!-- BUTTON -->

                    <div class="form-group full">

                        <button
                            type="submit"
                            name="add_guest"
                            class="btn"
                        >
                            ➕ Add Guest / Visitor
                        </button>

                    </div>


                </div>

            </form>

        </section>


        <!-- =================================================
             GUEST LIST
        ================================================= -->

        <section class="section">

            <div class="section-title">

                <h2>
                    📋 Guest / Visitor List
                </h2>

            </div>


            <?php if (count($guests) === 0): ?>

                <div class="empty">

                    No guests or visitors have been recorded yet.

                </div>

            <?php else: ?>


                <div class="table-wrapper">

                    <table>

                        <thead>

                            <tr>

                                <th>
                                    Full Name
                                </th>

                                <th>
                                    Contact Number
                                </th>

                                <th>
                                    Barangay
                                </th>

                                <th>
                                    Municipality
                                </th>

                                <th>
                                    Province
                                </th>

                                <th>
                                    Guests
                                </th>

                                <th>
                                    Visit Type
                                </th>

                                <th>
                                    Date Added
                                </th>

                                <th>
                                    Action
                                </th>

                            </tr>

                        </thead>


                        <tbody>


                        <?php foreach ($guests as $guest): ?>

                            <tr>


                                <!-- NAME -->

                                <td>

                                    <strong>

                                        <?php
                                        echo htmlspecialchars(
                                            $guest["full_name"]
                                        );
                                        ?>

                                    </strong>

                                </td>


                                <!-- CONTACT -->

                                <td>

                                    <?php
                                    echo htmlspecialchars(
                                        $guest["contact_number"]
                                        ?: "—"
                                    );
                                    ?>

                                </td>


                                <!-- BARANGAY -->

                                <td>

                                    <?php
                                    echo htmlspecialchars(
                                        $guest["barangay"]
                                        ?: "—"
                                    );
                                    ?>

                                </td>


                                <!-- MUNICIPALITY -->

                                <td>

                                    <?php
                                    echo htmlspecialchars(
                                        $guest["municipality"]
                                        ?: "—"
                                    );
                                    ?>

                                </td>


                                <!-- PROVINCE -->

                                <td>

                                    <?php
                                    echo htmlspecialchars(
                                        $guest["province"]
                                        ?: "—"
                                    );
                                    ?>

                                </td>


                                <!-- NUMBER -->

                                <td>

                                    <?php
                                    echo intval(
                                        $guest["number_of_guests"]
                                    );
                                    ?>

                                </td>


                                <!-- VISIT TYPE -->

                                <td>

                                    <?php if (
                                        $guest["visit_type"]
                                        === "Accommodation"
                                    ): ?>

                                        <span class="visit-badge visit-accommodation">
                                            🏡 Accommodation
                                        </span>

                                    <?php else: ?>

                                        <span class="visit-badge visit-day">
                                            👤 Day Visitor
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- DATE -->

                                <td>

                                    <?php

                                    echo htmlspecialchars(
                                        date(
                                            "M d, Y",
                                            strtotime(
                                                $guest["created_at"]
                                            )
                                        )
                                    );

                                    ?>

                                </td>


                                <!-- ACTION -->

                                <td>

                                    <div class="action-buttons">


                                        <!-- CHECK IN -->

                                        <a
                                            href="checkin.php?guest_id=<?php echo intval($guest["id"]); ?>"
                                            class="btn-checkin"
                                        >
                                            📝 Check In
                                        </a>


                                        <!-- EDIT -->

                                        <button
                                            type="button"
                                            class="btn btn-edit"
                                            onclick="openEditModal(
                                                <?php echo intval($guest["id"]); ?>,
                                                <?php echo htmlspecialchars(json_encode($guest["full_name"]), ENT_QUOTES, 'UTF-8'); ?>,
                                                <?php echo htmlspecialchars(json_encode($guest["contact_number"]), ENT_QUOTES, 'UTF-8'); ?>,
                                                <?php echo htmlspecialchars(json_encode($guest["barangay"]), ENT_QUOTES, 'UTF-8'); ?>,
                                                <?php echo htmlspecialchars(json_encode($guest["municipality"]), ENT_QUOTES, 'UTF-8'); ?>,
                                                <?php echo htmlspecialchars(json_encode($guest["province"]), ENT_QUOTES, 'UTF-8'); ?>,
                                                <?php echo intval($guest["number_of_guests"]); ?>,
                                                <?php echo htmlspecialchars(json_encode($guest["visit_type"]), ENT_QUOTES, 'UTF-8'); ?>
                                            )"
                                        >
                                            ✏️ Edit
                                        </button>


                                    </div>

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

</div>


<!-- =====================================================
     EDIT MODAL
===================================================== -->

<div
    id="editModal"
    class="modal"
>

    <div class="modal-content">


        <div class="modal-header">

            <h2>
                ✏️ Edit Guest / Visitor
            </h2>

            <button
                type="button"
                class="close-modal"
                onclick="closeEditModal()"
            >
                ×
            </button>

        </div>


        <div class="modal-body">


            <form method="POST">


                <input
                    type="hidden"
                    name="guest_id"
                    id="edit_guest_id"
                >


                <div class="form-grid">


                    <!-- NAME -->

                    <div class="form-group">

                        <label>
                            Full Name *
                        </label>

                        <input
                            type="text"
                            name="edit_full_name"
                            id="edit_full_name"
                            required
                        >

                    </div>


                    <!-- CONTACT -->

                    <div class="form-group">

                        <label>
                            Contact Number
                        </label>

                        <input
                            type="tel"
                            name="edit_contact_number"
                            id="edit_contact_number"
                            placeholder="09XXXXXXXXX"
                            maxlength="11"
                            inputmode="numeric"
                            pattern="[0-9]{0,11}"
                            oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,11)"
                        >

                        <div class="input-note">
                            Maximum 11 digits
                        </div>

                    </div>


                    <!-- ADDRESS -->

                    <div class="form-group full">

                        <label>
                            Address
                        </label>

                        <div class="address-grid">

                            <div class="form-group">

                                <label>
                                    Barangay
                                </label>

                                <input
                                    type="text"
                                    name="edit_barangay"
                                    id="edit_barangay"
                                    placeholder="Enter barangay"
                                >

                            </div>


                            <div class="form-group">

                                <label>
                                    Town / Municipality
                                </label>

                                <input
                                    type="text"
                                    name="edit_municipality"
                                    id="edit_municipality"
                                    placeholder="Enter town / municipality"
                                >

                            </div>


                            <div class="form-group">

                                <label>
                                    Province
                                </label>

                                <input
                                    type="text"
                                    name="edit_province"
                                    id="edit_province"
                                    placeholder="Enter province"
                                >

                            </div>

                        </div>

                    </div>


                    <!-- NUMBER -->

                    <div class="form-group">

                        <label>
                            Number of Guests *
                        </label>

                        <input
                            type="number"
                            name="edit_number_of_guests"
                            id="edit_number_of_guests"
                            min="1"
                            required
                        >

                    </div>


                    <!-- VISIT TYPE -->

                    <div class="form-group">

                        <label>
                            Visit Type *
                        </label>

                        <select
                            name="edit_visit_type"
                            id="edit_visit_type"
                            required
                        >

                            <option value="Accommodation">
                                🏡 Accommodation
                            </option>

                            <option value="Day Visitor">
                                👤 Day Visitor
                            </option>

                        </select>

                    </div>


                    <!-- SAVE -->

                    <div class="form-group full">

                        <button
                            type="submit"
                            name="edit_guest"
                            class="btn"
                        >
                            💾 Save Changes
                        </button>

                    </div>


                </div>

            </form>

        </div>

    </div>

</div>


<script>

/* =====================================================
   EDIT MODAL
===================================================== */

function openEditModal(
    id,
    name,
    contact,
    barangay,
    municipality,
    province,
    numberOfGuests,
    visitType
) {

    document.getElementById(
        "edit_guest_id"
    ).value = id;


    document.getElementById(
        "edit_full_name"
    ).value = name;


    document.getElementById(
        "edit_contact_number"
    ).value = contact;


    document.getElementById(
        "edit_barangay"
    ).value = barangay;


    document.getElementById(
        "edit_municipality"
    ).value = municipality;


    document.getElementById(
        "edit_province"
    ).value = province;


    document.getElementById(
        "edit_number_of_guests"
    ).value = numberOfGuests;


    document.getElementById(
        "edit_visit_type"
    ).value = visitType;


    document.getElementById(
        "editModal"
    ).classList.add("show");
}


/* =====================================================
   CLOSE MODAL
===================================================== */

function closeEditModal() {

    document.getElementById(
        "editModal"
    ).classList.remove("show");
}


/* =====================================================
   CLOSE WHEN CLICKING OUTSIDE
===================================================== */

document.getElementById(
    "editModal"
).addEventListener(
    "click",
    function(event) {

        if (event.target === this) {

            closeEditModal();

        }

    }
);

</script>


</body>

</html>