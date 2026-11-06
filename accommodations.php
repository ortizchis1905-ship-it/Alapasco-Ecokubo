<?php

session_start();

require_once "database.php";

/* =========================
   CHECK LOGIN
========================= */

if (!isset($_SESSION["user_id"])) {
    header("Location: resort_login.php");
    exit;
}

$user_id = (int) $_SESSION["user_id"];

$resort_name = $_SESSION["resort_name"] ?? "";
$full_name = $_SESSION["full_name"] ?? "";


/* =========================
   GET OR CREATE RESORT
========================= */

$resort_id = 0;

$stmt = $conn->prepare(
    "SELECT id, resort_name
     FROM resorts
     WHERE user_id = ?
     LIMIT 1"
);

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows > 0) {

    $resort = $result->fetch_assoc();

    $resort_id = (int) $resort["id"];

    if (!empty($resort["resort_name"])) {
        $resort_name = $resort["resort_name"];
    }

} else {

    $contact_number = "";

    $user_stmt = $conn->prepare(
        "SELECT resort_name, contact_number
         FROM users
         WHERE id = ?
         LIMIT 1"
    );

    $user_stmt->bind_param("i", $user_id);
    $user_stmt->execute();

    $user_result = $user_stmt->get_result();

    if ($user_result->num_rows > 0) {

        $user_data = $user_result->fetch_assoc();

        $resort_name = $user_data["resort_name"];
        $contact_number = $user_data["contact_number"];
    }

    $user_stmt->close();


    $insert_resort = $conn->prepare(
        "INSERT INTO resorts
        (
            user_id,
            resort_name,
            contact_number,
            status
        )
        VALUES (?, ?, ?, 'Active')"
    );

    $insert_resort->bind_param(
        "iss",
        $user_id,
        $resort_name,
        $contact_number
    );

    if ($insert_resort->execute()) {

        $resort_id = (int) $insert_resort->insert_id;

    } else {

        die("Unable to create resort record.");
    }

    $insert_resort->close();
}

$stmt->close();


/* =========================
   SAFETY CHECK
========================= */

if ($resort_id <= 0) {
    die("Resort ID was not found. Please log out and log in again.");
}


/* =========================
   MESSAGE
========================= */

$message = "";
$message_type = "";


/* =========================
   ADD ACCOMMODATION
========================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["add_accommodation"])
) {

    $code = trim($_POST["accommodation_code"] ?? "");
    $name = trim($_POST["accommodation_name"] ?? "");
    $type = trim($_POST["accommodation_type"] ?? "");
    $price = isset($_POST["price"])
        ? (float) $_POST["price"]
        : 0;


    /* =========================
       VALIDATION
    ========================= */

    if (
        empty($code) ||
        empty($name) ||
        empty($type)
    ) {

        $message = "Please fill in all required fields.";
        $message_type = "error";

    } elseif ($price < 0) {

        $message = "Price cannot be negative.";
        $message_type = "error";

    } else {

        /* =========================
           CHECK DUPLICATE CODE
           WITHIN SAME RESORT
        ========================= */

        $check = $conn->prepare(
            "SELECT id
             FROM accommodations
             WHERE resort_id = ?
             AND accommodation_code = ?
             LIMIT 1"
        );

        $check->bind_param(
            "is",
            $resort_id,
            $code
        );

        $check->execute();

        $check_result = $check->get_result();


        if ($check_result->num_rows > 0) {

            $message = "Accommodation code already exists in your resort.";
            $message_type = "error";

        } else {

            /* =========================
               INSERT ACCOMMODATION
            ========================= */

            $stmt = $conn->prepare(
                "INSERT INTO accommodations
                (
                    resort_id,
                    accommodation_code,
                    accommodation_name,
                    accommodation_type,
                    price,
                    status
                )
                VALUES (?, ?, ?, ?, ?, 'Available')"
            );

            $stmt->bind_param(
                "isssd",
                $resort_id,
                $code,
                $name,
                $type,
                $price
            );


            if ($stmt->execute()) {

                $message = "Accommodation added successfully.";
                $message_type = "success";

            } else {

                /*
                 * DATABASE-LEVEL DUPLICATE HANDLING
                 *
                 * MySQL duplicate key error = 1062
                 *
                 * This protects the system even if another
                 * request creates the same code between the
                 * SELECT check and INSERT.
                 */

                if ($stmt->errno === 1062) {

                    $message =
                        "Accommodation code already exists in your resort.";

                    $message_type = "error";

                } else {

                    $message =
                        "Unable to add accommodation. Please try again.";

                    $message_type = "error";
                }
            }

            $stmt->close();
        }

        $check->close();
    }
}


/* =========================
   EDIT ACCOMMODATION
========================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["edit_accommodation"])
) {

    $accommodation_id =
        (int) ($_POST["accommodation_id"] ?? 0);

    $code =
        trim($_POST["accommodation_code"] ?? "");

    $name =
        trim($_POST["accommodation_name"] ?? "");

    $type =
        trim($_POST["accommodation_type"] ?? "");

    $price =
        isset($_POST["price"])
            ? (float) $_POST["price"]
            : 0;


    /* =========================
       VALIDATION
    ========================= */

    if (
        $accommodation_id <= 0 ||
        empty($code) ||
        empty($name) ||
        empty($type)
    ) {

        $message = "Please fill in all required fields.";
        $message_type = "error";

    } elseif ($price < 0) {

        $message = "Price cannot be negative.";
        $message_type = "error";

    } else {

        /* =========================
           CHECK DUPLICATE CODE
           WITHIN SAME RESORT
        ========================= */

        $check = $conn->prepare(
            "SELECT id
             FROM accommodations
             WHERE resort_id = ?
             AND accommodation_code = ?
             AND id != ?
             LIMIT 1"
        );

        $check->bind_param(
            "isi",
            $resort_id,
            $code,
            $accommodation_id
        );

        $check->execute();

        $check_result = $check->get_result();


        if ($check_result->num_rows > 0) {

            $message =
                "Another accommodation already uses this code in your resort.";

            $message_type = "error";

        } else {

            /* =========================
               UPDATE ACCOMMODATION
            ========================= */

            $stmt = $conn->prepare(
                "UPDATE accommodations
                 SET
                    accommodation_code = ?,
                    accommodation_name = ?,
                    accommodation_type = ?,
                    price = ?
                 WHERE id = ?
                 AND resort_id = ?"
            );

            $stmt->bind_param(
                "sssdii",
                $code,
                $name,
                $type,
                $price,
                $accommodation_id,
                $resort_id
            );


            if ($stmt->execute()) {

                $message =
                    "Accommodation updated successfully.";

                $message_type = "success";

            } else {

                /*
                 * DATABASE-LEVEL DUPLICATE HANDLING
                 *
                 * Protects against a duplicate-code conflict
                 * occurring during the UPDATE.
                 */

                if ($stmt->errno === 1062) {

                    $message =
                        "Another accommodation already uses this code in your resort.";

                    $message_type = "error";

                } else {

                    $message =
                        "Unable to update accommodation. Please try again.";

                    $message_type = "error";
                }
            }

            $stmt->close();
        }

        $check->close();
    }
}


/* =========================
   DELETE ACCOMMODATION
========================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["delete_accommodation"])
) {

    $accommodation_id =
        (int) ($_POST["accommodation_id"] ?? 0);


    if ($accommodation_id > 0) {

        $stmt = $conn->prepare(
            "DELETE FROM accommodations
             WHERE id = ?
             AND resort_id = ?"
        );

        $stmt->bind_param(
            "ii",
            $accommodation_id,
            $resort_id
        );


        if ($stmt->execute()) {

            if ($stmt->affected_rows > 0) {

                $message =
                    "Accommodation deleted successfully.";

                $message_type = "success";

            } else {

                $message =
                    "Accommodation was not found.";

                $message_type = "error";
            }

        } else {

            $message =
                "Unable to delete accommodation.";

            $message_type = "error";
        }

        $stmt->close();

    } else {

        $message =
            "Invalid accommodation selected.";

        $message_type = "error";
    }
}


/* =========================
   GET ACCOMMODATIONS
========================= */

$accommodations = [];

$stmt = $conn->prepare(
    "SELECT
        id,
        accommodation_code,
        accommodation_name,
        accommodation_type,
        price,
        status
     FROM accommodations
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
    $accommodations[] = $row;
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

<title>Accommodations - Alapasco EcoKubo</title>

<link
    rel="stylesheet"
    href="style.css"
>

<link
    rel="icon"
    type="image/png"
    href="logo1.png"
>

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

.dashboard-container {
    display: flex;
    min-height: 100vh;
}


/* =========================
   MAIN CONTENT
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

.page-content {
    padding: 25px;
}

.page-intro {
    margin-bottom: 20px;
}

.page-intro h1 {
    margin: 0 0 6px;
    color: #1f5d42;
}

.page-intro p {
    margin: 0;
    color: #777;
}


/* =========================
   MESSAGE
========================= */

.message {
    padding: 12px 15px;
    border-radius: 7px;
    margin-bottom: 20px;
    font-size: 14px;
}

.message.success {
    background: #e6f6ed;
    color: #17643f;
    border: 1px solid #b8e5c9;
}

.message.error {
    background: #fdeaea;
    color: #a83232;
    border: 1px solid #efbcbc;
}


/* =========================
   SECTION
========================= */

.section {
    background: white;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 25px;
    position: relative;
    z-index: 1;
}

.section h2 {
    margin-top: 0;
    color: #1f5d42;
    font-size: 20px;
}


/* =========================
   FORM
========================= */

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group.full {
    grid-column: 1 / -1;
}

.form-group label {
    font-size: 13px;
    font-weight: bold;
    margin-bottom: 6px;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 12px;
    border: 1px solid #ccc;
    border-radius: 7px;
    font-size: 14px;
    background: white;
    min-height: 45px;
}

.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: #1f5d42;
}


/* =========================
   ADD BUTTON
========================= */

.add-button {
    margin-top: 15px;
    background: #1f5d42;
    color: white;
    border: none;
    border-radius: 7px;
    padding: 12px 18px;
    min-height: 45px;
    font-size: 14px;
    cursor: pointer;
    touch-action: manipulation;
}

.add-button:hover {
    background: #174a35;
}


/* =========================
   ACTION BUTTONS
========================= */

.action-buttons {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: nowrap;
}

.action-buttons form {
    margin: 0;
    padding: 0;
}

.edit-button,
.delete-button {
    width: 75px;
    height: 40px;
    min-height: 40px;
    padding: 0;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    text-align: center;
    cursor: pointer;
    touch-action: manipulation;
}

.edit-button {
    background: #397a5c;
    color: white;
}

.delete-button {
    background: #b83b3b;
    color: white;
}

.edit-button:active,
.delete-button:active {
    transform: scale(0.97);
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
    min-width: 750px;
}

table th {
    background: #1f5d42;
    color: white;
    padding: 12px;
    text-align: left;
    font-size: 13px;
}

table td {
    padding: 12px;
    border-bottom: 1px solid #eee;
    font-size: 13px;
}

table tr:hover {
    background: #fafafa;
}


/* =========================
   STATUS
========================= */

.status {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
}

.status.available {
    background: #e4f5ea;
    color: #19713f;
}

.status.reserved {
    background: #fff3d8;
    color: #9b6b00;
}

.status.occupied {
    background: #fde4e4;
    color: #a32d2d;
}

.status.maintenance {
    background: #e8e8e8;
    color: #555;
}


/* =========================
   EDIT MODAL
========================= */

.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    padding: 20px;
    overflow-y: auto;
}

.modal-content {
    background: white;
    max-width: 500px;
    margin: 40px auto;
    padding: 25px;
    border-radius: 12px;
}

.modal-content h2 {
    margin-top: 0;
    color: #1f5d42;
}

.close-button {
    background: #777;
    margin-top: 10px;
    width: 100%;
    min-height: 45px;
    border: none;
    color: white;
    border-radius: 7px;
    font-size: 14px;
    cursor: pointer;
}

.update-button {
    width: 100%;
    min-height: 45px;
    background: #1f5d42;
    color: white;
    border: none;
    border-radius: 7px;
    font-size: 14px;
    cursor: pointer;
}


/* =========================
   RESPONSIVE
========================= */

@media (max-width: 950px) {

    .form-grid {
        grid-template-columns: repeat(2, 1fr);
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


<!-- ==================================================
     STANDARD SIDEBAR
================================================== -->

<?php require __DIR__ . '/resort_sidebar.php'; ?>


<!-- ==================================================
     MAIN CONTENT
================================================== -->

<div class="main-content">


    <div class="topbar">

        <h3>
            Accommodations
        </h3>

        <div class="resort-header-account"><?php require __DIR__ . '/resort_header_notifications.php'; ?>
<div class="user-info">

            <strong>
                <?php
                echo htmlspecialchars($full_name);
                ?>
            </strong>

            <span>
                <?php
                echo htmlspecialchars($resort_name);
                ?>
            </span>

        </div></div>

    </div>


    <div class="page-content">


        <div class="page-intro">

            <h1>
                Accommodations
            </h1>

            <p>
                Manage your cottages and tents.
            </p>

        </div>


        <?php if (!empty($message)): ?>

            <div class="message <?php echo htmlspecialchars($message_type); ?>">

                <?php
                echo htmlspecialchars($message);
                ?>

            </div>

        <?php endif; ?>


        <div class="section">

            <h2>
                Add Accommodation
            </h2>


            <form method="POST">

                <div class="form-grid">


                    <div class="form-group">

                        <label>
                            Accommodation Code
                        </label>

                        <input
                            type="text"
                            name="accommodation_code"
                            placeholder="Example: C-01"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label>
                            Accommodation Name
                        </label>

                        <input
                            type="text"
                            name="accommodation_name"
                            placeholder="Example: Cottage 1"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label>
                            Type
                        </label>

                        <select
                            name="accommodation_type"
                            required
                        >

                            <option value="">
                                Select Type
                            </option>

                            <option value="Cottage">
                                Cottage
                            </option>

                            <option value="Tent">
                                Tent
                            </option>

                        </select>

                    </div>


                    <div class="form-group">

                        <label>
                            Price
                        </label>

                        <input
                            type="number"
                            name="price"
                            min="0"
                            step="0.01"
                            placeholder="0.00"
                            required
                        >

                    </div>


                </div>


                <button
                    type="submit"
                    name="add_accommodation"
                    class="add-button"
                >
                    + Add Accommodation
                </button>

            </form>

        </div>


        <div class="section">

            <h2>
                Accommodation List
            </h2>


            <?php if (count($accommodations) === 0): ?>

                <p style="color:#777;">
                    No accommodations added yet.
                </p>

            <?php else: ?>


                <div class="table-wrapper">

                    <table>

                        <thead>

                            <tr>

                                <th>Code</th>

                                <th>Name</th>

                                <th>Type</th>

                                <th>Price</th>

                                <th>Status</th>

                                <th>Action</th>

                            </tr>

                        </thead>


                        <tbody>


                        <?php foreach ($accommodations as $row): ?>

                            <tr>


                                <td>

                                    <?php
                                    echo htmlspecialchars(
                                        $row["accommodation_code"]
                                    );
                                    ?>

                                </td>


                                <td>

                                    <?php
                                    echo htmlspecialchars(
                                        $row["accommodation_name"]
                                    );
                                    ?>

                                </td>


                                <td>

                                    <?php
                                    echo htmlspecialchars(
                                        $row["accommodation_type"]
                                    );
                                    ?>

                                </td>


                                <td>

                                    ₱<?php
                                    echo number_format(
                                        (float) $row["price"],
                                        2
                                    );
                                    ?>

                                </td>


                                <td>

                                    <span
                                        class="status <?php
                                        echo htmlspecialchars(
                                            strtolower(
                                                $row["status"]
                                            )
                                        );
                                        ?>"
                                    >

                                        <?php
                                        echo htmlspecialchars(
                                            $row["status"]
                                        );
                                        ?>

                                    </span>

                                </td>


                                <td>

                                    <div class="action-buttons">


                                        <button
                                            type="button"
                                            class="edit-button"
                                            onclick='openEditModal(
                                                <?php
                                                echo htmlspecialchars(
                                                    json_encode(
                                                        $row,
                                                        JSON_HEX_TAG |
                                                        JSON_HEX_APOS |
                                                        JSON_HEX_AMP |
                                                        JSON_HEX_QUOT
                                                    ),
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                );
                                                ?>
                                            )'
                                        >
                                            Edit
                                        </button>


                                        <form
                                            method="POST"
                                            onsubmit="return confirm(
                                                'Are you sure you want to delete this accommodation?'
                                            );"
                                        >

                                            <input
                                                type="hidden"
                                                name="accommodation_id"
                                                value="<?php
                                                echo (int) $row["id"];
                                                ?>"
                                            >


                                            <button
                                                type="submit"
                                                name="delete_accommodation"
                                                class="delete-button"
                                            >
                                                Delete
                                            </button>

                                        </form>


                                    </div>

                                </td>


                            </tr>

                        <?php endforeach; ?>


                        </tbody>

                    </table>

                </div>

            <?php endif; ?>


        </div>


    </div>

</div>

</div>


<!-- ==================================================
     EDIT MODAL
================================================== -->

<div
    id="editModal"
    class="modal"
>

    <div class="modal-content">


        <h2>
            Edit Accommodation
        </h2>


        <form method="POST">


            <input
                type="hidden"
                name="accommodation_id"
                id="edit_id"
            >


            <div class="form-group">

                <label>
                    Accommodation Code
                </label>

                <input
                    type="text"
                    name="accommodation_code"
                    id="edit_code"
                    required
                >

            </div>


            <br>


            <div class="form-group">

                <label>
                    Accommodation Name
                </label>

                <input
                    type="text"
                    name="accommodation_name"
                    id="edit_name"
                    required
                >

            </div>


            <br>


            <div class="form-group">

                <label>
                    Type
                </label>

                <select
                    name="accommodation_type"
                    id="edit_type"
                    required
                >

                    <option value="Cottage">
                        Cottage
                    </option>

                    <option value="Tent">
                        Tent
                    </option>

                </select>

            </div>


            <br>


            <div class="form-group">

                <label>
                    Price
                </label>

                <input
                    type="number"
                    name="price"
                    id="edit_price"
                    min="0"
                    step="0.01"
                    required
                >

            </div>


            <br>


            <button
                type="submit"
                name="edit_accommodation"
                class="update-button"
            >
                Save Changes
            </button>


            <button
                type="button"
                class="close-button"
                onclick="closeEditModal()"
            >
                Cancel
            </button>


        </form>

    </div>

</div>


<script>

function openEditModal(data) {

    document.getElementById("edit_id").value =
        data.id;

    document.getElementById("edit_code").value =
        data.accommodation_code;

    document.getElementById("edit_name").value =
        data.accommodation_name;

    document.getElementById("edit_type").value =
        data.accommodation_type;

    document.getElementById("edit_price").value =
        data.price;

    document.getElementById("editModal").style.display =
        "block";
}


function closeEditModal() {

    document.getElementById("editModal").style.display =
        "none";
}


window.onclick = function(event) {

    const modal =
        document.getElementById("editModal");

    if (event.target === modal) {

        closeEditModal();

    }

};

</script>


</body>

</html>