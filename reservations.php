<?php

session_start();

require_once "database.php";


/* =========================================================
   LOGIN CHECK
========================================================= */

if (!isset($_SESSION["user_id"])) {
    header("Location: ./resort_login.php");
    exit;
}

$user_id = (int) $_SESSION["user_id"];

$resort_name = $_SESSION["resort_name"] ?? "";
$full_name   = $_SESSION["full_name"] ?? "";


/* =========================================================
   GET RESORT ID
========================================================= */

$resort_id = 0;

$stmt = $conn->prepare("
    SELECT id
    FROM resorts
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 1) {

    $resort_id = (int) $result->fetch_assoc()["id"];

    $stmt->close();

} else {

    $stmt->close();

    $userStmt = $conn->prepare("
        SELECT resort_name, contact_number
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $userStmt->bind_param("i", $user_id);
    $userStmt->execute();

    $userResult = $userStmt->get_result();

    if ($userResult->num_rows !== 1) {
        die("User account was not found. Please log in again.");
    }

    $user = $userResult->fetch_assoc();

    $userStmt->close();

    $create = $conn->prepare("
        INSERT INTO resorts
        (
            user_id,
            resort_name,
            contact_number,
            status
        )
        VALUES
        (?, ?, ?, 'Active')
    ");

    $create->bind_param(
        "iss",
        $user_id,
        $user["resort_name"],
        $user["contact_number"]
    );

    $create->execute();

    $resort_id = (int) $conn->insert_id;

    $create->close();
}

if ($resort_id <= 0) {
    die("Unable to identify your resort account. Please log in again.");
}


/* =========================================================
   NOTIFICATION FUNCTION
========================================================= */

function createNotification(
    $conn,
    $resort_id,
    $notification_type,
    $title,
    $message
) {

    $userStmt = $conn->prepare("
        SELECT user_id
        FROM resorts
        WHERE id = ?
        LIMIT 1
    ");

    $userStmt->bind_param("i", $resort_id);
    $userStmt->execute();

    $userResult = $userStmt->get_result();

    if ($userResult->num_rows !== 1) {
        $userStmt->close();
        return;
    }

    $user_id = (int) $userResult->fetch_assoc()["user_id"];

    $userStmt->close();


    $settingStmt = $conn->prepare("
        SELECT notifications_enabled
        FROM user_settings
        WHERE user_id = ?
        LIMIT 1
    ");

    $settingStmt->bind_param("i", $user_id);
    $settingStmt->execute();

    $settingResult = $settingStmt->get_result();

    $notifications_enabled = 1;

    if ($settingResult->num_rows === 1) {

        $setting = $settingResult->fetch_assoc();

        $notifications_enabled =
            (int) $setting["notifications_enabled"];
    }

    $settingStmt->close();


    if ($notifications_enabled !== 1) {
        return;
    }


    $stmt = $conn->prepare("
        INSERT INTO notifications
        (
            resort_id,
            notification_type,
            title,
            message,
            is_read
        )
        VALUES
        (?, ?, ?, ?, 0)
    ");

    $stmt->bind_param(
        "isss",
        $resort_id,
        $notification_type,
        $title,
        $message
    );

    $stmt->execute();

    $stmt->close();
}


/* =========================================================
   MESSAGE
========================================================= */

$message = "";
$message_type = "";


/* =========================================================
   DELETE RESERVATION
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    &&
    isset($_POST["delete_reservation"])
) {

    $reservation_id =
        (int) ($_POST["reservation_id"] ?? 0);


    if ($reservation_id <= 0) {

        $message = "Invalid reservation.";
        $message_type = "error";

    } else {

        $checkStmt = $conn->prepare("
            SELECT
                id,
                full_name,
                reservation_status
            FROM reservations
            WHERE id = ?
            AND resort_id = ?
            LIMIT 1
        ");

        $checkStmt->bind_param(
            "ii",
            $reservation_id,
            $resort_id
        );

        $checkStmt->execute();

        $checkResult = $checkStmt->get_result();


        if ($checkResult->num_rows !== 1) {

            $message = "Reservation not found.";
            $message_type = "error";

            $checkStmt->close();

        } else {

            $reservation = $checkResult->fetch_assoc();

            $checkStmt->close();


            if (
                $reservation["reservation_status"] === "Completed"
            ) {

                $message =
                    "Completed reservations cannot be deleted.";

                $message_type = "error";

            } else {

                $conn->begin_transaction();

                try {

                    /*
                     * Delete only reservation-level payment
                     * records. Check-in/check-out payments are
                     * not deleted here.
                     */
                    $paymentStmt = $conn->prepare("
                        DELETE FROM payments
                        WHERE reservation_id = ?
                        AND resort_id = ?
                        AND checkin_id IS NULL
                    ");

                    $paymentStmt->bind_param(
                        "ii",
                        $reservation_id,
                        $resort_id
                    );

                    $paymentStmt->execute();

                    $paymentStmt->close();


                    /* DELETE RESERVATION */

                    $deleteStmt = $conn->prepare("
                        DELETE FROM reservations
                        WHERE id = ?
                        AND resort_id = ?
                    ");

                    $deleteStmt->bind_param(
                        "ii",
                        $reservation_id,
                        $resort_id
                    );

                    $deleteStmt->execute();

                    $deleteStmt->close();


                    createNotification(
                        $conn,
                        $resort_id,
                        "Reservation",
                        "Reservation Deleted",
                        "Reservation for "
                        . $reservation["full_name"]
                        . " was deleted."
                    );


                    $conn->commit();


                    $message =
                        "Reservation deleted successfully.";

                    $message_type = "success";

                } catch (Exception $e) {

                    $conn->rollback();

                    $message =
                        "Unable to delete reservation.";

                    $message_type = "error";
                }
            }
        }
    }
}


/* =========================================================
   ADD RESERVATION
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    &&
    isset($_POST["add_reservation"])
) {

    $guest_name =
        trim($_POST["full_name"] ?? "");


    $contact_number =
        preg_replace(
            "/[^0-9]/",
            "",
            $_POST["contact_number"] ?? ""
        );

    $contact_number =
        substr($contact_number, 0, 11);


    $accommodation_id =
        (int) ($_POST["accommodation_id"] ?? 0);


    $reservation_date =
        $_POST["reservation_date"] ?? "";


    $visit_date =
        $_POST["visit_date"] ?? "";


    $number_of_guests =
        (int) ($_POST["number_of_guests"] ?? 0);


    $total_amount =
        (float) ($_POST["total_amount"] ?? 0);


    $amount_paid =
        (float) ($_POST["amount_paid"] ?? 0);


    $payment_method =
        trim($_POST["payment_method"] ?? "");


    $reference_number =
        trim($_POST["reference_number"] ?? "");


    $reservation_status =
        trim($_POST["reservation_status"] ?? "Pending");


    /* =====================================================
       VALIDATION
    ===================================================== */

    if (
        $guest_name === ""
        ||
        $contact_number === ""
        ||
        $accommodation_id <= 0
        ||
        $reservation_date === ""
        ||
        $visit_date === ""
        ||
        $number_of_guests <= 0
        ||
        $total_amount < 0
        ||
        $amount_paid < 0
    ) {

        $message =
            "Please complete all required fields.";

        $message_type = "error";

    } elseif (strlen($contact_number) !== 11) {

        $message =
            "Contact number must contain exactly 11 digits.";

        $message_type = "error";

    } elseif ($visit_date < $reservation_date) {

        $message =
            "Visit date cannot be earlier than the reservation date.";

        $message_type = "error";

    } elseif ($amount_paid > $total_amount) {

        $message =
            "Amount paid cannot be greater than the total amount.";

        $message_type = "error";

    } elseif ($amount_paid <= 0) {

        /*
         * RESERVATION PAYMENT IS REQUIRED.
         * The reservation must have at least a partial payment.
         */
        $message =
            "At least a partial payment is required for a reservation.";

        $message_type = "error";

    } elseif (
        !in_array(
            $reservation_status,
            [
                "Pending",
                "Confirmed",
                "Cancelled"
            ],
            true
        )
    ) {

        $message =
            "Invalid reservation status.";

        $message_type = "error";

    } elseif (
        !in_array(
            $payment_method,
            [
                "Cash",
                "GCash",
                "Bank Transfer",
                "Other"
            ],
            true
        )
    ) {

        $message =
            "Invalid payment method.";

        $message_type = "error";

    } else {

        /* =================================================
           GET ACCOMMODATION
        ================================================= */

        $accStmt = $conn->prepare("
            SELECT
                id,
                accommodation_name,
                status,
                price
            FROM accommodations
            WHERE id = ?
            AND resort_id = ?
            LIMIT 1
        ");

        $accStmt->bind_param(
            "ii",
            $accommodation_id,
            $resort_id
        );

        $accStmt->execute();

        $accResult = $accStmt->get_result();


        if ($accResult->num_rows !== 1) {

            $message =
                "Selected accommodation was not found.";

            $message_type = "error";

            $accStmt->close();

        } else {

            $accommodation =
                $accResult->fetch_assoc();

            $accStmt->close();


            if (
                $accommodation["status"] === "Maintenance"
            ) {

                $message =
                    "This accommodation is currently under maintenance.";

                $message_type = "error";

            } else {

                /* =================================================
                   CHECK DATE CONFLICT
                ================================================= */

                $conflictStmt = $conn->prepare("
                    SELECT id
                    FROM reservations
                    WHERE resort_id = ?
                    AND accommodation_id = ?
                    AND visit_date = ?
                    AND reservation_status
                    IN ('Pending', 'Confirmed')
                    LIMIT 1
                ");

                $conflictStmt->bind_param(
                    "iis",
                    $resort_id,
                    $accommodation_id,
                    $visit_date
                );

                $conflictStmt->execute();

                $conflictResult =
                    $conflictStmt->get_result();


                if ($conflictResult->num_rows > 0) {

                    $message =
                        "This accommodation is already reserved for the selected visit date.";

                    $message_type = "error";

                    $conflictStmt->close();

                } else {

                    $conflictStmt->close();


                    /*
                     * Payment status is based on the amount
                     * actually paid at reservation.
                     */
                    $payment_status =
                        (
                            $amount_paid >= $total_amount
                            &&
                            $total_amount > 0
                        )
                        ? "Paid"
                        : "Partially Paid";


                    $conn->begin_transaction();

                    try {

                        /* INSERT RESERVATION */

                        $reservationStmt =
                            $conn->prepare("
                                INSERT INTO reservations
                                (
                                    resort_id,
                                    full_name,
                                    contact_number,
                                    accommodation_id,
                                    reservation_date,
                                    visit_date,
                                    number_of_guests,
                                    total_amount,
                                    amount_paid,
                                    payment_status,
                                    payment_method,
                                    reference_number,
                                    reservation_status
                                )
                                VALUES
                                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ");


                        $reservationStmt->bind_param(
                            "ississiddssss",
                            $resort_id,
                            $guest_name,
                            $contact_number,
                            $accommodation_id,
                            $reservation_date,
                            $visit_date,
                            $number_of_guests,
                            $total_amount,
                            $amount_paid,
                            $payment_status,
                            $payment_method,
                            $reference_number,
                            $reservation_status
                        );


                        $reservationStmt->execute();

                        $new_reservation_id =
                            (int) $conn->insert_id;

                        $reservationStmt->close();


                        /* =================================================
                           FIND OR CREATE GUEST
                        ================================================= */

                        $guest_id = 0;


                        $guestStmt = $conn->prepare("
                            SELECT id
                            FROM guests
                            WHERE resort_id = ?
                            AND full_name = ?
                            AND contact_number = ?
                            LIMIT 1
                        ");

                        $guestStmt->bind_param(
                            "iss",
                            $resort_id,
                            $guest_name,
                            $contact_number
                        );

                        $guestStmt->execute();

                        $guestResult =
                            $guestStmt->get_result();


                        if ($guestResult->num_rows === 1) {

                            $guest_id =
                                (int)
                                $guestResult
                                ->fetch_assoc()["id"];

                            $guestStmt->close();

                        } else {

                            $guestStmt->close();


                            $createGuestStmt =
                                $conn->prepare("
                                    INSERT INTO guests
                                    (
                                        resort_id,
                                        full_name,
                                        contact_number
                                    )
                                    VALUES
                                    (?, ?, ?)
                                ");

                            $createGuestStmt->bind_param(
                                "iss",
                                $resort_id,
                                $guest_name,
                                $contact_number
                            );

                            $createGuestStmt->execute();

                            $guest_id =
                                (int) $conn->insert_id;

                            $createGuestStmt->close();
                        }


                        /* =================================================
                           INSERT RESERVATION PAYMENT
                        ================================================= */

                        /*
                         * This is the ONLY payment record created
                         * for the reservation payment.
                         *
                         * Check-in.php must NOT insert this same
                         * reservation amount again.
                         */
                        $paymentNotes =
                            "Reservation payment";


                        $paymentStmt =
                            $conn->prepare("
                                INSERT INTO payments
                                (
                                    resort_id,
                                    reservation_id,
                                    checkin_id,
                                    guest_id,
                                    amount,
                                    payment_status,
                                    payment_method,
                                    reference_number,
                                    notes
                                )
                                VALUES
                                (?, ?, NULL, ?, ?, ?, ?, ?, ?)
                            ");


                        $paymentStmt->bind_param(
                            "iiidssss",
                            $resort_id,
                            $new_reservation_id,
                            $guest_id,
                            $amount_paid,
                            $payment_status,
                            $payment_method,
                            $reference_number,
                            $paymentNotes
                        );


                        $paymentStmt->execute();

                        $paymentStmt->close();


                        /* =================================================
                           NOTIFICATION
                        ================================================= */

                        createNotification(
                            $conn,
                            $resort_id,
                            "Reservation",
                            "New Reservation",
                            "New reservation from "
                            . $guest_name
                            . " for "
                            . $accommodation["accommodation_name"]
                            . " on "
                            . date(
                                "M d, Y",
                                strtotime($visit_date)
                            )
                            . "."
                        );


                        $conn->commit();


                        $message =
                            "Reservation added successfully.";

                        $message_type =
                            "success";

                    } catch (Exception $e) {

                        $conn->rollback();

                        $message =
                            "Unable to save reservation.";

                        $message_type =
                            "error";
                    }
                }
            }
        }
    }
}


/* =========================================================
   UPDATE RESERVATION
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    &&
    isset($_POST["update_reservation"])
) {

    $reservation_id =
        (int) ($_POST["reservation_id"] ?? 0);


    $guest_name =
        trim($_POST["full_name"] ?? "");


    $contact_number =
        preg_replace(
            "/[^0-9]/",
            "",
            $_POST["contact_number"] ?? ""
        );

    $contact_number =
        substr($contact_number, 0, 11);


    $accommodation_id =
        (int) ($_POST["accommodation_id"] ?? 0);


    $reservation_date =
        $_POST["reservation_date"] ?? "";


    $visit_date =
        $_POST["visit_date"] ?? "";


    $number_of_guests =
        (int) ($_POST["number_of_guests"] ?? 0);


    $total_amount =
        (float) ($_POST["total_amount"] ?? 0);


    $amount_paid =
        (float) ($_POST["amount_paid"] ?? 0);


    $payment_method =
        trim($_POST["payment_method"] ?? "");


    $reference_number =
        trim($_POST["reference_number"] ?? "");


    $reservation_status =
        trim($_POST["reservation_status"] ?? "Pending");


    /* =====================================================
       VALIDATION
    ===================================================== */

    if (
        $reservation_id <= 0
        ||
        $guest_name === ""
        ||
        $contact_number === ""
        ||
        $accommodation_id <= 0
        ||
        $reservation_date === ""
        ||
        $visit_date === ""
        ||
        $number_of_guests <= 0
        ||
        $total_amount < 0
        ||
        $amount_paid < 0
    ) {

        $message =
            "Please complete all required fields.";

        $message_type = "error";

    } elseif (strlen($contact_number) !== 11) {

        $message =
            "Contact number must contain exactly 11 digits.";

        $message_type = "error";

    } elseif ($visit_date < $reservation_date) {

        $message =
            "Visit date cannot be earlier than the reservation date.";

        $message_type = "error";

    } elseif ($amount_paid > $total_amount) {

        $message =
            "Amount paid cannot be greater than the total amount.";

        $message_type = "error";

    } elseif ($amount_paid <= 0) {

        /*
         * Reservation must remain at least partially paid.
         */
        $message =
            "At least a partial payment is required for a reservation.";

        $message_type = "error";

    } elseif (
        !in_array(
            $reservation_status,
            [
                "Pending",
                "Confirmed",
                "Cancelled"
            ],
            true
        )
    ) {

        $message =
            "Invalid reservation status.";

        $message_type = "error";

    } elseif (
        !in_array(
            $payment_method,
            [
                "Cash",
                "GCash",
                "Bank Transfer",
                "Other"
            ],
            true
        )
    ) {

        $message =
            "Invalid payment method.";

        $message_type = "error";

    } else {

        /* =================================================
           GET CURRENT RESERVATION
        ================================================= */

        $currentStmt = $conn->prepare("
            SELECT
                id,
                reservation_status,
                amount_paid,
                total_amount
            FROM reservations
            WHERE id = ?
            AND resort_id = ?
            LIMIT 1
        ");

        $currentStmt->bind_param(
            "ii",
            $reservation_id,
            $resort_id
        );

        $currentStmt->execute();

        $currentResult =
            $currentStmt->get_result();


        if ($currentResult->num_rows !== 1) {

            $message =
                "Reservation not found.";

            $message_type =
                "error";

            $currentStmt->close();

        } else {

            $currentReservation =
                $currentResult->fetch_assoc();

            $currentStmt->close();


            if (
                $currentReservation["reservation_status"]
                === "Completed"
            ) {

                $message =
                    "Completed reservations cannot be edited.";

                $message_type =
                    "error";

            } else {

                /*
                 * Get the amount already recorded in actual
                 * reservation payment transactions.
                 */
                $existingPaymentStmt = $conn->prepare("
                    SELECT COALESCE(SUM(amount), 0) AS paid
                    FROM payments
                    WHERE reservation_id = ?
                    AND resort_id = ?
                    AND checkin_id IS NULL
                    AND payment_status IN ('Paid', 'Partially Paid')
                ");

                $existingPaymentStmt->bind_param(
                    "ii",
                    $reservation_id,
                    $resort_id
                );

                $existingPaymentStmt->execute();

                $existingPaymentResult =
                    $existingPaymentStmt->get_result();

                $existingPaymentRow =
                    $existingPaymentResult->fetch_assoc();

                $existingPaymentStmt->close();

                $existingReservationPayment =
                    (float) ($existingPaymentRow["paid"] ?? 0);


                /*
                 * Do not allow the reservation form to reduce
                 * already recorded money. Payment history should
                 * remain an actual transaction history.
                 */
                if (
                    $amount_paid + 0.009
                    <
                    $existingReservationPayment
                ) {

                    $message =
                        "The amount paid cannot be reduced because payment transactions have already been recorded. Payment history must remain unchanged.";

                    $message_type =
                        "error";

                } else {

                    /* =================================================
                       GET ACCOMMODATION
                    ================================================= */

                    $accStmt = $conn->prepare("
                        SELECT
                            id,
                            accommodation_name,
                            status,
                            price
                        FROM accommodations
                        WHERE id = ?
                        AND resort_id = ?
                        LIMIT 1
                    ");

                    $accStmt->bind_param(
                        "ii",
                        $accommodation_id,
                        $resort_id
                    );

                    $accStmt->execute();

                    $accResult =
                        $accStmt->get_result();


                    if ($accResult->num_rows !== 1) {

                        $message =
                            "Selected accommodation was not found.";

                        $message_type =
                            "error";

                        $accStmt->close();

                    } else {

                        $accommodation =
                            $accResult->fetch_assoc();

                        $accStmt->close();


                        if (
                            $accommodation["status"]
                            === "Maintenance"
                        ) {

                            $message =
                                "This accommodation is currently under maintenance.";

                            $message_type =
                                "error";

                        } else {

                            /* =================================================
                               CONFLICT CHECK
                            ================================================= */

                            $conflictStmt = $conn->prepare("
                                SELECT id
                                FROM reservations
                                WHERE resort_id = ?
                                AND accommodation_id = ?
                                AND visit_date = ?
                                AND reservation_status
                                IN ('Pending', 'Confirmed')
                                AND id != ?
                                LIMIT 1
                            ");

                            $conflictStmt->bind_param(
                                "iisi",
                                $resort_id,
                                $accommodation_id,
                                $visit_date,
                                $reservation_id
                            );

                            $conflictStmt->execute();

                            $conflictResult =
                                $conflictStmt->get_result();


                            if (
                                $conflictResult->num_rows > 0
                            ) {

                                $message =
                                    "This accommodation is already reserved for the selected visit date.";

                                $message_type =
                                    "error";

                                $conflictStmt->close();

                            } else {

                                $conflictStmt->close();


                                $payment_status =
                                    (
                                        $amount_paid >= $total_amount
                                        &&
                                        $total_amount > 0
                                    )
                                    ? "Paid"
                                    : "Partially Paid";


                                $conn->begin_transaction();


                                try {

                                    /* =================================================
                                       UPDATE RESERVATION
                                    ================================================= */

                                    $updateStmt =
                                        $conn->prepare("
                                            UPDATE reservations
                                            SET
                                                full_name = ?,
                                                contact_number = ?,
                                                accommodation_id = ?,
                                                reservation_date = ?,
                                                visit_date = ?,
                                                number_of_guests = ?,
                                                total_amount = ?,
                                                amount_paid = ?,
                                                payment_status = ?,
                                                payment_method = ?,
                                                reference_number = ?,
                                                reservation_status = ?
                                            WHERE id = ?
                                            AND resort_id = ?
                                        ");


                                    $updateStmt->bind_param(
                                        "ssissiddssssii",
                                        $guest_name,
                                        $contact_number,
                                        $accommodation_id,
                                        $reservation_date,
                                        $visit_date,
                                        $number_of_guests,
                                        $total_amount,
                                        $amount_paid,
                                        $payment_status,
                                        $payment_method,
                                        $reference_number,
                                        $reservation_status,
                                        $reservation_id,
                                        $resort_id
                                    );


                                    $updateStmt->execute();

                                    $updateStmt->close();


                                    /* =================================================
                                       FIND OR CREATE GUEST
                                    ================================================= */

                                    $guest_id = 0;


                                    $guestStmt =
                                        $conn->prepare("
                                            SELECT id
                                            FROM guests
                                            WHERE resort_id = ?
                                            AND full_name = ?
                                            AND contact_number = ?
                                            LIMIT 1
                                        ");


                                    $guestStmt->bind_param(
                                        "iss",
                                        $resort_id,
                                        $guest_name,
                                        $contact_number
                                    );


                                    $guestStmt->execute();

                                    $guestResult =
                                        $guestStmt->get_result();


                                    if (
                                        $guestResult->num_rows === 1
                                    ) {

                                        $guest_id =
                                            (int)
                                            $guestResult
                                            ->fetch_assoc()["id"];

                                        $guestStmt->close();

                                    } else {

                                        $guestStmt->close();


                                        $createGuestStmt =
                                            $conn->prepare("
                                                INSERT INTO guests
                                                (
                                                    resort_id,
                                                    full_name,
                                                    contact_number
                                                )
                                                VALUES
                                                (?, ?, ?)
                                            ");


                                        $createGuestStmt->bind_param(
                                            "iss",
                                            $resort_id,
                                            $guest_name,
                                            $contact_number
                                        );


                                        $createGuestStmt->execute();

                                        $guest_id =
                                            (int) $conn->insert_id;

                                        $createGuestStmt->close();
                                    }


                                    /* =================================================
                                       PAYMENT TRANSACTION HANDLING
                                    ================================================= */

                                    $additionalPayment =
                                        $amount_paid
                                        -
                                        $existingReservationPayment;


                                    /*
                                     * IMPORTANT:
                                     *
                                     * Never update the old payment amount.
                                     *
                                     * Example:
                                     *
                                     * Existing reservation payment = ₱500
                                     * New reservation amount paid = ₱800
                                     *
                                     * We keep the original ₱500 payment.
                                     * We create a NEW ₱300 payment.
                                     *
                                     * This prevents payment history from
                                     * becoming inaccurate.
                                     */
                                    if (
                                        $additionalPayment > 0.009
                                    ) {

                                        $paymentNotes =
                                            "Additional reservation payment";


                                        $paymentStmt =
                                            $conn->prepare("
                                                INSERT INTO payments
                                                (
                                                    resort_id,
                                                    reservation_id,
                                                    checkin_id,
                                                    guest_id,
                                                    amount,
                                                    payment_status,
                                                    payment_method,
                                                    reference_number,
                                                    notes
                                                )
                                                VALUES
                                                (?, ?, NULL, ?, ?, 'Paid', ?, ?, ?)
                                            ");


                                        $paymentStmt->bind_param(
                                            "iiidsss",
                                            $resort_id,
                                            $reservation_id,
                                            $guest_id,
                                            $additionalPayment,
                                            $payment_method,
                                            $reference_number,
                                            $paymentNotes
                                        );


                                        $paymentStmt->execute();

                                        $paymentStmt->close();
                                    }


                                    /* =================================================
                                       NOTIFICATION
                                    ================================================= */

                                    if (
                                        $reservation_status === "Cancelled"
                                    ) {

                                        createNotification(
                                            $conn,
                                            $resort_id,
                                            "Reservation",
                                            "Reservation Cancelled",
                                            "Reservation for "
                                            . $guest_name
                                            . " was cancelled."
                                        );

                                    } else {

                                        createNotification(
                                            $conn,
                                            $resort_id,
                                            "Reservation",
                                            "Reservation Updated",
                                            "Reservation for "
                                            . $guest_name
                                            . " was updated."
                                        );
                                    }


                                    $conn->commit();


                                    $message =
                                        "Reservation updated successfully.";

                                    $message_type =
                                        "success";

                                } catch (Exception $e) {

                                    $conn->rollback();

                                    $message =
                                        "Unable to update reservation.";

                                    $message_type =
                                        "error";
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}


/* =========================================================
   GET ACCOMMODATIONS
========================================================= */

$accommodations = [];

$stmt = $conn->prepare("
    SELECT
        id,
        accommodation_code,
        accommodation_name,
        accommodation_type,
        price,
        status
    FROM accommodations
    WHERE resort_id = ?
    ORDER BY accommodation_name ASC
");

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


/* =========================================================
   GET RESERVATIONS
========================================================= */

$reservations = [];

$stmt = $conn->prepare("
    SELECT
        r.id AS reservation_id,
        r.full_name,
        r.contact_number,
        r.accommodation_id,
        r.reservation_date,
        r.visit_date,
        r.number_of_guests,
        r.total_amount,
        r.amount_paid,
        r.payment_status,
        r.payment_method,
        r.reference_number,
        r.reservation_status,

        a.accommodation_code,
        a.accommodation_name,
        a.accommodation_type

    FROM reservations r

    LEFT JOIN accommodations a
        ON r.accommodation_id = a.id
        AND a.resort_id = r.resort_id

    WHERE r.resort_id = ?

    ORDER BY
        r.visit_date DESC,
        r.id DESC
");

$stmt->bind_param(
    "i",
    $resort_id
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $reservations[] = $row;
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
    href="./logo1.png"
>

<title>Reservations - Alapasco EcoKubo</title>


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


/* =========================================================
   MOBILE MENU
========================================================= */

.mobile-menu-button {
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
    z-index: 3000;
}


/* =========================================================
   MAIN
========================================================= */

.main-content {
    margin-left: 250px;
    width: calc(100% - 250px);
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


/* =========================================================
   CONTENT
========================================================= */

.page-content {
    padding: 25px;
}

.page-header {
    margin-bottom: 20px;
}

.page-header h1 {
    margin: 0 0 5px;
    color: #1f5d42;
    font-size: 25px;
}

.page-header p {
    margin: 0;
    color: #777;
    font-size: 13px;
}


/* =========================================================
   MESSAGE
========================================================= */

.message {
    padding: 12px 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 13px;
}

.message.success {
    background: #e7f6ec;
    color: #176b35;
    border: 1px solid #c8e8d1;
}

.message.error {
    background: #fdeaea;
    color: #a33a3a;
    border: 1px solid #f1c4c4;
}


/* =========================================================
   CARD
========================================================= */

.card {
    background: white;
    border-radius: 10px;
    padding: 22px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.card h2 {
    margin-top: 0;
    font-size: 20px;
    color: #1f5d42;
}


/* =========================================================
   NOTE
========================================================= */

.note {
    background: #f1f7f3;
    border-left: 4px solid #1f5d42;
    padding: 12px;
    margin-bottom: 20px;
    font-size: 13px;
    line-height: 1.5;
}


/* =========================================================
   FORM
========================================================= */

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group label {
    font-size: 13px;
    font-weight: bold;
    margin-bottom: 6px;
}

.form-group input,
.form-group select {
    width: 100%;
    padding: 11px 12px;
    border: 1px solid #d5ddd8;
    border-radius: 7px;
    font-family: Arial, sans-serif;
    font-size: 13px;
    background: white;
}

.form-group input:focus,
.form-group select:focus {
    outline: none;
    border-color: #1f5d42;
}

.form-group small {
    margin-top: 5px;
    color: #777;
    font-size: 11px;
}


/* =========================================================
   BUTTONS
========================================================= */

.btn {
    display: inline-block;
    border: none;
    border-radius: 7px;
    padding: 11px 16px;
    font-size: 13px;
    font-weight: bold;
    cursor: pointer;
    text-decoration: none;
}

.btn-primary {
    background: #1f5d42;
    color: white;
}

.btn-primary:hover {
    background: #174832;
}

.btn-secondary {
    background: #eeeeee;
    color: #444;
}

.btn-secondary:hover {
    background: #dddddd;
}

.btn-danger {
    background: #b43b3b;
    color: white;
}

.btn-danger:hover {
    background: #922f2f;
}

.btn-small {
    padding: 7px 10px;
    font-size: 11px;
}


/* =========================================================
   ACTIONS
========================================================= */

.form-actions {
    margin-top: 20px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.action-buttons {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}


/* =========================================================
   TABLE
========================================================= */

.table-wrapper {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1250px;
}

th,
td {
    padding: 12px 10px;
    border-bottom: 1px solid #e5e9e6;
    text-align: left;
    font-size: 13px;
    vertical-align: middle;
}

th {
    background: #f3f6f4;
    font-size: 12px;
}


/* =========================================================
   STATUS
========================================================= */

.status {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
}

.status.paid,
.status.confirmed,
.status.completed {
    background: #e7f6ec;
    color: #176b35;
}

.status.partial,
.status.pending {
    background: #fff3cd;
    color: #856404;
}

.status.cancelled {
    background: #fdeaea;
    color: #a33a3a;
}


/* =========================================================
   EMPTY
========================================================= */

.empty {
    text-align: center;
    padding: 35px;
    color: #777;
    font-size: 14px;
}


/* =========================================================
   MODAL
========================================================= */

.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    z-index: 4000;
    padding: 20px;
    overflow-y: auto;
}

.modal.show {
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    width: 100%;
    max-width: 850px;
    background: white;
    border-radius: 10px;
    padding: 25px;
    position: relative;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.modal-header h2 {
    margin: 0;
    color: #1f5d42;
    font-size: 20px;
}

.close-button {
    border: none;
    background: transparent;
    font-size: 25px;
    color: #777;
    cursor: pointer;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1100px) {

    .form-grid {
        grid-template-columns: 1fr;
    }
}


@media (max-width: 768px) {

    .mobile-menu-button {
        display: block;
    }

    .main-content {
        margin-left: 0;
        width: 100%;
    }

    .topbar {
        padding: 15px 15px 15px 75px;
    }

    .topbar h3 {
        font-size: 16px;
    }

    .user-info strong {
        font-size: 12px;
    }

    .user-info span {
        font-size: 10px;
    }

    .page-content {
        padding: 20px 15px 15px;
    }

    .card {
        padding: 18px;
    }

    .modal {
        padding: 10px;
    }

    .modal-content {
        padding: 18px;
        max-height: 95vh;
    }
}


/* =========================================================
   PRINT
========================================================= */

@media print {

    .main-content {
        margin-left: 0;
        width: 100%;
    }

    .page-content {
        padding: 0;
    }

    .card {
        box-shadow: none;
        border: 1px solid #ddd;
    }
}

</style>

</head>


<body>


<button
    class="mobile-menu-button"
    id="mobileMenuButton"
    type="button"
>
    ☰
</button>


<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>


<?php require __DIR__ . '/resort_sidebar.php'; ?>


<div class="main-content">

    <div class="topbar">

        <h3>Reservations</h3>

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


        <div class="page-header">

            <h1>
                Reservations
            </h1>

            <p>
                Manage accommodation reservations
                and reservation payments.
            </p>

        </div>


        <?php if ($message !== ""): ?>

            <div
                class="message <?php
                    echo htmlspecialchars($message_type);
                ?>"
            >
                <?php
                echo htmlspecialchars($message);
                ?>
            </div>

        <?php endif; ?>


        <div class="card">

            <h2>
                ➕ New Reservation
            </h2>


            <div class="note">

                <strong>Partial payment is required.</strong>

                The guest must make at least one payment
                when making a reservation.

                The reservation payment will automatically
                appear on the <strong>Payments</strong> page.

                If the guest still has a balance, the remaining
                amount may be paid during Check-In or Check-Out.

                The reservation payment will not be recorded
                again when the guest checks in.

            </div>


            <form
                method="POST"
                action="./reservations.php"
            >

                <div class="form-grid">


                    <div class="form-group">

                        <label>
                            Full Name *
                        </label>

                        <input
                            type="text"
                            name="full_name"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label>
                            Contact Number *
                        </label>

                        <input
                            type="text"
                            name="contact_number"
                            id="contactNumber"
                            maxlength="11"
                            inputmode="numeric"
                            placeholder="09XXXXXXXXX"
                            required
                        >

                        <small>
                            Enter exactly 11 digits.
                        </small>

                    </div>


                    <div class="form-group">

                        <label>
                            Cottage / Tent *
                        </label>

                        <select
                            name="accommodation_id"
                            id="accommodationId"
                            required
                        >

                            <option value="">
                                Select accommodation
                            </option>


                            <?php foreach (
                                $accommodations
                                as $accommodation
                            ): ?>

                                <?php
                                $isMaintenance =
                                    $accommodation["status"]
                                    === "Maintenance";
                                ?>

                                <option
                                    value="<?php
                                        echo (int)
                                            $accommodation["id"];
                                    ?>"
                                    data-price="<?php
                                        echo htmlspecialchars(
                                            $accommodation["price"]
                                        );
                                    ?>"
                                    <?php
                                    echo $isMaintenance
                                        ? "disabled"
                                        : "";
                                    ?>
                                >

                                    <?php
                                    echo htmlspecialchars(
                                        $accommodation[
                                            "accommodation_code"
                                        ]
                                    );
                                    ?>

                                    -
                                    <?php
                                    echo htmlspecialchars(
                                        $accommodation[
                                            "accommodation_name"
                                        ]
                                    );
                                    ?>

                                    (
                                    <?php
                                    echo htmlspecialchars(
                                        $accommodation["status"]
                                    );
                                    ?>
                                    )

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


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


                    <div class="form-group">

                        <label>
                            Reservation Date *
                        </label>

                        <input
                            type="date"
                            name="reservation_date"
                            id="reservationDate"
                            value="<?php
                                echo date("Y-m-d");
                            ?>"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label>
                            Visit Date *
                        </label>

                        <input
                            type="date"
                            name="visit_date"
                            id="visitDate"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label>
                            Total Amount *
                        </label>

                        <input
                            type="number"
                            name="total_amount"
                            id="totalAmount"
                            min="0"
                            step="0.01"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label>
                            Amount Paid *
                        </label>

                        <input
                            type="number"
                            name="amount_paid"
                            id="amountPaid"
                            min="0.01"
                            step="0.01"
                            required
                        >

                        <small id="paymentStatusText">
                            At least a partial payment is required.
                        </small>

                    </div>


                    <div class="form-group">

                        <label>
                            Payment Method *
                        </label>

                        <select
                            name="payment_method"
                            required
                        >

                            <option value="">
                                Select payment method
                            </option>

                            <option value="Cash">
                                Cash
                            </option>

                            <option value="GCash">
                                GCash
                            </option>

                            <option value="Bank Transfer">
                                Bank Transfer
                            </option>

                            <option value="Other">
                                Other
                            </option>

                        </select>

                    </div>


                    <div class="form-group">

                        <label>
                            Reference Number
                        </label>

                        <input
                            type="text"
                            name="reference_number"
                            placeholder="Optional"
                        >

                    </div>


                    <div class="form-group">

                        <label>
                            Reservation Status *
                        </label>

                        <select
                            name="reservation_status"
                            required
                        >

                            <option value="Pending">
                                Pending
                            </option>

                            <option value="Confirmed">
                                Confirmed
                            </option>

                            <option value="Cancelled">
                                Cancelled
                            </option>

                        </select>

                        <small>
                            Completed is set automatically after Check-In.
                        </small>

                    </div>


                </div>


                <div class="form-actions">

                    <button
                        type="submit"
                        name="add_reservation"
                        class="btn btn-primary"
                    >
                        Save Reservation
                    </button>

                </div>

            </form>

        </div>


        <div class="card">

            <h2>
                📅 Reservation List
            </h2>


            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>

                            <th>Full Name</th>
                            <th>Contact</th>
                            <th>Accommodation</th>
                            <th>Reservation Date</th>
                            <th>Visit Date</th>
                            <th>Guests</th>
                            <th>Total</th>
                            <th>Paid</th>
                            <th>Payment Status</th>
                            <th>Reservation Status</th>
                            <th>Action</th>

                        </tr>

                    </thead>


                    <tbody>

                    <?php if (count($reservations) > 0): ?>

                        <?php foreach (
                            $reservations
                            as $reservation
                        ): ?>

                            <tr>

                                <td>
                                    <strong>
                                        <?php
                                        echo htmlspecialchars(
                                            $reservation["full_name"]
                                        );
                                        ?>
                                    </strong>
                                </td>


                                <td>
                                    <?php
                                    echo htmlspecialchars(
                                        $reservation["contact_number"]
                                    );
                                    ?>
                                </td>


                                <td>

                                    <?php
                                    echo htmlspecialchars(
                                        $reservation[
                                            "accommodation_code"
                                        ] ?? "—"
                                    );
                                    ?>

                                    -

                                    <?php
                                    echo htmlspecialchars(
                                        $reservation[
                                            "accommodation_name"
                                        ] ?? "Unknown"
                                    );
                                    ?>

                                </td>


                                <td>

                                    <?php
                                    echo date(
                                        "M d, Y",
                                        strtotime(
                                            $reservation[
                                                "reservation_date"
                                            ]
                                        )
                                    );
                                    ?>

                                </td>


                                <td>

                                    <?php
                                    echo date(
                                        "M d, Y",
                                        strtotime(
                                            $reservation[
                                                "visit_date"
                                            ]
                                        )
                                    );
                                    ?>

                                </td>


                                <td>

                                    <?php
                                    echo (int)
                                        $reservation[
                                            "number_of_guests"
                                        ];
                                    ?>

                                </td>


                                <td>

                                    ₱<?php
                                    echo number_format(
                                        (float)
                                        $reservation[
                                            "total_amount"
                                        ],
                                        2
                                    );
                                    ?>

                                </td>


                                <td>

                                    ₱<?php
                                    echo number_format(
                                        (float)
                                        $reservation[
                                            "amount_paid"
                                        ],
                                        2
                                    );
                                    ?>

                                </td>


                                <td>

                                    <?php if (
                                        $reservation[
                                            "payment_status"
                                        ] === "Paid"
                                    ): ?>

                                        <span class="status paid">
                                            Paid
                                        </span>

                                    <?php elseif (
                                        $reservation[
                                            "payment_status"
                                        ] === "Partially Paid"
                                    ): ?>

                                        <span class="status partial">
                                            Partially Paid
                                        </span>

                                    <?php else: ?>

                                        <span class="status pending">
                                            Pending
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?php
                                    $statusClass =
                                        strtolower(
                                            $reservation[
                                                "reservation_status"
                                            ]
                                        );
                                    ?>

                                    <span
                                        class="status <?php
                                            echo htmlspecialchars(
                                                $statusClass
                                            );
                                        ?>"
                                    >
                                        <?php
                                        echo htmlspecialchars(
                                            $reservation[
                                                "reservation_status"
                                            ]
                                        );
                                        ?>
                                    </span>

                                </td>


                                <td>

                                    <div class="action-buttons">

                                        <?php if (
                                            $reservation[
                                                "reservation_status"
                                            ] !== "Completed"
                                        ): ?>

                                            <button
                                                type="button"
                                                class="btn btn-primary btn-small"
                                                onclick='openEditModal(<?php
                                                    echo json_encode(
                                                        $reservation,
                                                        JSON_HEX_TAG |
                                                        JSON_HEX_APOS |
                                                        JSON_HEX_QUOT |
                                                        JSON_HEX_AMP
                                                    );
                                                ?>)'
                                            >
                                                Edit
                                            </button>


                                            <form
                                                method="POST"
                                                action="./reservations.php"
                                                style="display:inline;"
                                                onsubmit="return confirm('Are you sure you want to delete this reservation?');"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="reservation_id"
                                                    value="<?php
                                                        echo (int)
                                                            $reservation[
                                                                "reservation_id"
                                                            ];
                                                    ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    name="delete_reservation"
                                                    class="btn btn-danger btn-small"
                                                >
                                                    Delete
                                                </button>

                                            </form>

                                        <?php else: ?>

                                            <span class="status completed">
                                                Completed
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td
                                colspan="11"
                                class="empty"
                            >
                                No reservations found.
                            </td>

                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>


<div
    class="modal"
    id="editModal"
>

    <div class="modal-content">

        <div class="modal-header">

            <h2>
                ✏️ Edit Reservation
            </h2>

            <button
                type="button"
                class="close-button"
                onclick="closeEditModal()"
            >
                ×
            </button>

        </div>


        <form
            method="POST"
            action="./reservations.php"
        >

            <input
                type="hidden"
                name="reservation_id"
                id="editReservationId"
            >


            <div class="form-grid">


                <div class="form-group">

                    <label>
                        Full Name *
                    </label>

                    <input
                        type="text"
                        name="full_name"
                        id="editFullName"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Contact Number *
                    </label>

                    <input
                        type="text"
                        name="contact_number"
                        id="editContactNumber"
                        maxlength="11"
                        inputmode="numeric"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Cottage / Tent *
                    </label>

                    <select
                        name="accommodation_id"
                        id="editAccommodationId"
                        required
                    >

                        <option value="">
                            Select accommodation
                        </option>


                        <?php foreach (
                            $accommodations
                            as $accommodation
                        ): ?>

                            <?php
                            $isMaintenance =
                                $accommodation["status"]
                                === "Maintenance";
                            ?>

                            <option
                                value="<?php
                                    echo (int)
                                        $accommodation["id"];
                                ?>"
                                data-price="<?php
                                    echo htmlspecialchars(
                                        $accommodation["price"]
                                    );
                                ?>"
                                <?php
                                echo $isMaintenance
                                    ? "disabled"
                                    : "";
                                ?>
                            >

                                <?php
                                echo htmlspecialchars(
                                    $accommodation[
                                        "accommodation_code"
                                    ]
                                );
                                ?>

                                -

                                <?php
                                echo htmlspecialchars(
                                    $accommodation[
                                        "accommodation_name"
                                    ]
                                );
                                ?>

                                (
                                <?php
                                echo htmlspecialchars(
                                    $accommodation["status"]
                                );
                                ?>
                                )

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="form-group">

                    <label>
                        Number of Guests *
                    </label>

                    <input
                        type="number"
                        name="number_of_guests"
                        id="editNumberOfGuests"
                        min="1"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Reservation Date *
                    </label>

                    <input
                        type="date"
                        name="reservation_date"
                        id="editReservationDate"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Visit Date *
                    </label>

                    <input
                        type="date"
                        name="visit_date"
                        id="editVisitDate"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Total Amount *
                    </label>

                    <input
                        type="number"
                        name="total_amount"
                        id="editTotalAmount"
                        min="0"
                        step="0.01"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Amount Paid *
                    </label>

                    <input
                        type="number"
                        name="amount_paid"
                        id="editAmountPaid"
                        min="0.01"
                        step="0.01"
                        required
                    >

                    <small id="editPaymentStatusText">
                        At least a partial payment is required.
                    </small>

                </div>


                <div class="form-group">

                    <label>
                        Payment Method *
                    </label>

                    <select
                        name="payment_method"
                        id="editPaymentMethod"
                        required
                    >

                        <option value="">
                            Select payment method
                        </option>

                        <option value="Cash">
                            Cash
                        </option>

                        <option value="GCash">
                            GCash
                        </option>

                        <option value="Bank Transfer">
                            Bank Transfer
                        </option>

                        <option value="Other">
                            Other
                        </option>

                    </select>

                </div>


                <div class="form-group">

                    <label>
                        Reference Number
                    </label>

                    <input
                        type="text"
                        name="reference_number"
                        id="editReferenceNumber"
                        placeholder="Optional"
                    >

                </div>


                <div class="form-group">

                    <label>
                        Reservation Status *
                    </label>

                    <select
                        name="reservation_status"
                        id="editReservationStatus"
                        required
                    >

                        <option value="Pending">
                            Pending
                        </option>

                        <option value="Confirmed">
                            Confirmed
                        </option>

                        <option value="Cancelled">
                            Cancelled
                        </option>

                    </select>

                    <small>
                        Completed is set automatically after Check-In.
                    </small>

                </div>


            </div>


            <div class="form-actions">

                <button
                    type="submit"
                    name="update_reservation"
                    class="btn btn-primary"
                >
                    Update Reservation
                </button>

                <button
                    type="button"
                    class="btn btn-secondary"
                    onclick="closeEditModal()"
                >
                    Cancel
                </button>

            </div>

        </form>

    </div>

</div>


<script>

/* =========================================================
   MOBILE SIDEBAR
========================================================= */

const mobileMenuButton =
    document.getElementById("mobileMenuButton");

const sidebar =
    document.querySelector(".sidebar");

const sidebarOverlay =
    document.getElementById("sidebarOverlay");


mobileMenuButton.addEventListener(
    "click",
    function () {

        sidebar.classList.toggle("show");
        sidebarOverlay.classList.toggle("show");

    }
);


sidebarOverlay.addEventListener(
    "click",
    function () {

        sidebar.classList.remove("show");
        sidebarOverlay.classList.remove("show");

    }
);


document.querySelectorAll(".sidebar a").forEach(
    function (link) {

        link.addEventListener(
            "click",
            function () {

                sidebar.classList.remove("show");
                sidebarOverlay.classList.remove("show");

            }
        );

    }
);


/* =========================================================
   CONTACT NUMBER
========================================================= */

const contactNumber =
    document.getElementById("contactNumber");

if (contactNumber) {

    contactNumber.addEventListener(
        "input",
        function () {

            this.value =
                this.value
                .replace(/[^0-9]/g, "")
                .slice(0, 11);

        }
    );
}


const editContactNumber =
    document.getElementById("editContactNumber");

if (editContactNumber) {

    editContactNumber.addEventListener(
        "input",
        function () {

            this.value =
                this.value
                .replace(/[^0-9]/g, "")
                .slice(0, 11);

        }
    );
}


/* =========================================================
   PAYMENT STATUS
========================================================= */

function updatePaymentStatus() {

    const total =
        parseFloat(
            document.getElementById("totalAmount").value
        ) || 0;

    const paid =
        parseFloat(
            document.getElementById("amountPaid").value
        ) || 0;

    const text =
        document.getElementById("paymentStatusText");


    if (total <= 0) {

        text.textContent =
            "Enter the total amount.";

    } else if (paid >= total) {

        text.textContent =
            "Payment Status: Paid";

    } else if (paid > 0) {

        text.textContent =
            "Payment Status: Partially Paid";

    } else {

        text.textContent =
            "At least a partial payment is required.";
    }
}


const totalAmount =
    document.getElementById("totalAmount");

const amountPaid =
    document.getElementById("amountPaid");


if (totalAmount) {

    totalAmount.addEventListener(
        "input",
        updatePaymentStatus
    );
}


if (amountPaid) {

    amountPaid.addEventListener(
        "input",
        updatePaymentStatus
    );
}


/* =========================================================
   AUTO PRICE
========================================================= */

const accommodationId =
    document.getElementById("accommodationId");


if (accommodationId) {

    accommodationId.addEventListener(
        "change",
        function () {

            const option =
                this.options[this.selectedIndex];

            const price =
                option.getAttribute("data-price");


            if (
                price !== null
                &&
                price !== ""
            ) {

                document.getElementById(
                    "totalAmount"
                ).value = price;

                updatePaymentStatus();

            }

        }
    );
}


/* =========================================================
   RESERVATION DATE
========================================================= */

const reservationDate =
    document.getElementById("reservationDate");

const visitDate =
    document.getElementById("visitDate");


if (
    reservationDate
    &&
    visitDate
) {

    function updateVisitMinimum() {

        visitDate.min =
            reservationDate.value;

    }

    updateVisitMinimum();

    reservationDate.addEventListener(
        "change",
        updateVisitMinimum
    );
}


/* =========================================================
   EDIT PAYMENT STATUS
========================================================= */

function updateEditPaymentStatus() {

    const total =
        parseFloat(
            document.getElementById(
                "editTotalAmount"
            ).value
        ) || 0;


    const paid =
        parseFloat(
            document.getElementById(
                "editAmountPaid"
            ).value
        ) || 0;


    const text =
        document.getElementById(
            "editPaymentStatusText"
        );


    if (total <= 0) {

        text.textContent =
            "Enter the total amount.";

    } else if (paid >= total) {

        text.textContent =
            "Payment Status: Paid";

    } else if (paid > 0) {

        text.textContent =
            "Payment Status: Partially Paid";

    } else {

        text.textContent =
            "At least a partial payment is required.";

    }
}


const editTotalAmount =
    document.getElementById("editTotalAmount");

const editAmountPaid =
    document.getElementById("editAmountPaid");


if (editTotalAmount) {

    editTotalAmount.addEventListener(
        "input",
        updateEditPaymentStatus
    );
}


if (editAmountPaid) {

    editAmountPaid.addEventListener(
        "input",
        updateEditPaymentStatus
    );
}


/* =========================================================
   EDIT MODAL
========================================================= */

function openEditModal(reservation) {

    document.getElementById(
        "editReservationId"
    ).value =
        reservation.reservation_id || "";


    document.getElementById(
        "editFullName"
    ).value =
        reservation.full_name || "";


    document.getElementById(
        "editContactNumber"
    ).value =
        reservation.contact_number || "";


    document.getElementById(
        "editAccommodationId"
    ).value =
        reservation.accommodation_id || "";


    document.getElementById(
        "editNumberOfGuests"
    ).value =
        reservation.number_of_guests || 1;


    document.getElementById(
        "editReservationDate"
    ).value =
        reservation.reservation_date || "";


    document.getElementById(
        "editVisitDate"
    ).value =
        reservation.visit_date || "";


    document.getElementById(
        "editTotalAmount"
    ).value =
        reservation.total_amount || 0;


    document.getElementById(
        "editAmountPaid"
    ).value =
        reservation.amount_paid || 0;


    document.getElementById(
        "editPaymentMethod"
    ).value =
        reservation.payment_method || "";


    document.getElementById(
        "editReferenceNumber"
    ).value =
        reservation.reference_number || "";


    if (
        reservation.reservation_status === "Completed"
    ) {

        document.getElementById(
            "editReservationStatus"
        ).value = "Pending";

    } else {

        document.getElementById(
            "editReservationStatus"
        ).value =
            reservation.reservation_status || "Pending";
    }


    const editVisitDate =
        document.getElementById(
            "editVisitDate"
        );

    const editReservationDate =
        document.getElementById(
            "editReservationDate"
        );


    editVisitDate.min =
        editReservationDate.value;


    updateEditPaymentStatus();


    document.getElementById(
        "editModal"
    ).classList.add("show");
}


/* =========================================================
   EDIT DATE
========================================================= */

const editReservationDate =
    document.getElementById(
        "editReservationDate"
    );

const editVisitDate =
    document.getElementById(
        "editVisitDate"
    );


if (
    editReservationDate
    &&
    editVisitDate
) {

    editReservationDate.addEventListener(
        "change",
        function () {

            editVisitDate.min =
                this.value;

        }
    );
}


/* =========================================================
   EDIT ACCOMMODATION PRICE
========================================================= */

const editAccommodationId =
    document.getElementById(
        "editAccommodationId"
    );


if (editAccommodationId) {

    editAccommodationId.addEventListener(
        "change",
        function () {

            const option =
                this.options[
                    this.selectedIndex
                ];

            const price =
                option.getAttribute("data-price");


            if (
                price !== null
                &&
                price !== ""
            ) {

                document.getElementById(
                    "editTotalAmount"
                ).value = price;

                updateEditPaymentStatus();

            }

        }
    );
}


/* =========================================================
   CLOSE MODAL
========================================================= */

function closeEditModal() {

    document.getElementById(
        "editModal"
    ).classList.remove("show");
}


/* =========================================================
   CLICK OUTSIDE MODAL
========================================================= */

document.getElementById(
    "editModal"
).addEventListener(
    "click",
    function (event) {

        if (event.target === this) {
            closeEditModal();
        }

    }
);


/* =========================================================
   INITIAL PAYMENT STATUS
========================================================= */

updatePaymentStatus();

</script>


</body>
</html>