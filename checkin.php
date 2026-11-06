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

$user_id = (int) $_SESSION["user_id"];

$resort_id = (int) ($_SESSION["resort_id"] ?? 0);

$resort_name = $_SESSION["resort_name"] ?? "My Resort";

$full_name = $_SESSION["full_name"] ?? "User";


/* ==================================================
   GET RESORT ID IF NOT IN SESSION
================================================== */

if (!$resort_id) {

    $stmt = $conn->prepare("
        SELECT id
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

            $resort_id = (int) $row["id"];
        }

        $stmt->close();
    }
}


if (!$resort_id) {
    die("Resort account not found.");
}


/* ==================================================
   NOTIFICATION FUNCTION
================================================== */

function createNotification(
    $conn,
    $resort_id,
    $title,
    $message
) {

    $stmt = $conn->prepare("
        INSERT INTO notifications
        (
            resort_id,
            title,
            message
        )
        VALUES (?, ?, ?)
    ");

    if ($stmt) {

        $stmt->bind_param(
            "iss",
            $resort_id,
            $title,
            $message
        );

        $stmt->execute();

        $stmt->close();
    }
}


/* ==================================================
   FORM VARIABLES
================================================== */

$guest_id = 0;

$guest_name = "";
$contact_number = "";
$address = "";
$accommodation_id = "";
$number_of_guests = 1;
$stay_type = "Day Visitor";
$check_in_time = date("Y-m-d\TH:i");
$total_amount = 0;
$amount_paid = 0;
$payment_method = "Cash";
$reference_number = "";
$reservation_id = 0;

$message = "";
$message_type = "";

$guest_loaded_from_url = false;


/* ==================================================
   LOAD GUEST FROM GUESTS / VISITORS PAGE
==================================================

   Example:

   checkin.php?guest_id=15

   This allows the Check In button in
   guests.php to automatically load
   the selected guest.
================================================== */

$guest_id_from_url = (int) (
    $_GET["guest_id"] ?? 0
);


if (
    $guest_id_from_url > 0
    && $_SERVER["REQUEST_METHOD"] !== "POST"
) {

    /*
       Get the guest using the current resort ID
       so one resort cannot load another resort's guest.
    */

    $guestLoadStmt = $conn->prepare("
        SELECT
            id,
            full_name,
            contact_number,
            address,
            barangay,
            municipality,
            province,
            number_of_guests,
            visit_type

        FROM guests

        WHERE id = ?
        AND resort_id = ?

        LIMIT 1
    ");


    if ($guestLoadStmt) {

        $guestLoadStmt->bind_param(
            "ii",
            $guest_id_from_url,
            $resort_id
        );

        $guestLoadStmt->execute();

        $guestLoadResult =
            $guestLoadStmt->get_result();


        if (
            $guestRow =
            $guestLoadResult->fetch_assoc()
        ) {

            $guest_id =
                (int) $guestRow["id"];


            $guest_name =
                $guestRow["full_name"] ?? "";


            $contact_number =
                $guestRow["contact_number"] ?? "";


            /*
               Use the existing address if available.

               If address is empty, build the address
               using the Barangay, Municipality,
               and Province fields used in guests.php.
            */

            $existing_address =
                trim(
                    (string)
                    ($guestRow["address"] ?? "")
                );


            if ($existing_address !== "") {

                $address =
                    $existing_address;

            } else {

                $address_parts = [];


                $barangay =
                    trim(
                        (string)
                        ($guestRow["barangay"] ?? "")
                    );


                $municipality =
                    trim(
                        (string)
                        ($guestRow["municipality"] ?? "")
                    );


                $province =
                    trim(
                        (string)
                        ($guestRow["province"] ?? "")
                    );


                if ($barangay !== "") {

                    $address_parts[] =
                        $barangay;
                }


                if ($municipality !== "") {

                    $address_parts[] =
                        $municipality;
                }


                if ($province !== "") {

                    $address_parts[] =
                        $province;
                }


                $address =
                    implode(
                        ", ",
                        $address_parts
                    );
            }


            $number_of_guests =
                max(
                    1,
                    (int)
                    ($guestRow["number_of_guests"] ?? 1)
                );


            $loaded_visit_type =
                trim(
                    (string)
                    ($guestRow["visit_type"] ?? "")
                );


            if (
                $loaded_visit_type ===
                "Accommodation"
            ) {

                $stay_type =
                    "Accommodation";

            } else {

                $stay_type =
                    "Day Visitor";

                $total_amount =
                    0;

                $amount_paid =
                    0;
            }


            $guest_loaded_from_url =
                true;


        } else {

            $message =
                "Guest not found or the guest does not belong to this resort.";

            $message_type =
                "error";
        }


        $guestLoadStmt->close();
    }
}


/* ==================================================
   CHECK IN GUEST
================================================== */

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["check_in_guest"])
) {

    $guest_id = (int) (
        $_POST["guest_id"] ?? 0
    );

    $reservation_id = (int) (
        $_POST["reservation_id"] ?? 0
    );

    $guest_name = trim(
        $_POST["full_name"] ?? ""
    );

    $contact_number = trim(
        $_POST["contact_number"] ?? ""
    );

    $address = trim(
        $_POST["address"] ?? ""
    );

    $accommodation_id = (int) (
        $_POST["accommodation_id"] ?? 0
    );

    $number_of_guests = (int) (
        $_POST["number_of_guests"] ?? 0
    );

    $stay_type = trim(
        $_POST["stay_type"] ?? "Day Visitor"
    );

    $check_in_time = trim(
        $_POST["check_in_time"] ?? ""
    );

    $total_amount = (float) (
        $_POST["total_amount"] ?? 0
    );

    $amount_paid = (float) (
        $_POST["amount_paid"] ?? 0
    );

    $payment_method = trim(
        $_POST["payment_method"] ?? "Cash"
    );

    $reference_number = trim(
        $_POST["reference_number"] ?? ""
    );


    try {


        /* ==================================================
           BASIC VALIDATION
        ================================================== */

        if ($guest_name === "") {

            throw new Exception(
                "Guest full name is required."
            );
        }


        /*
           For a guest selected from the Guests page,
           an existing guest record can be used directly.

           Contact number is still validated when one
           is entered.
        */

        if ($guest_id > 0) {

            if (
                $contact_number !== ""
                && !preg_match(
                    "/^[0-9]{11}$/",
                    $contact_number
                )
            ) {

                throw new Exception(
                    "Contact number must be exactly 11 digits."
                );
            }

        } else {

            if (!preg_match(
                "/^[0-9]{11}$/",
                $contact_number
            )) {

                throw new Exception(
                    "Contact number must be exactly 11 digits."
                );
            }
        }


        if (
            $stay_type !== "Day Visitor"
            && $stay_type !== "Accommodation"
        ) {

            throw new Exception(
                "Invalid visit type."
            );
        }


        if ($number_of_guests < 1) {

            throw new Exception(
                "Number of guests must be at least 1."
            );
        }


        if ($check_in_time === "") {

            throw new Exception(
                "Check-in date and time is required."
            );
        }


        if ($amount_paid < 0) {

            throw new Exception(
                "Amount paid cannot be negative."
            );
        }


        if ($total_amount < 0) {

            throw new Exception(
                "Total amount cannot be negative."
            );
        }


        if ($amount_paid > $total_amount) {

            throw new Exception(
                "Amount paid cannot be greater than the total amount."
            );
        }


        $allowed_payment_methods = [
            "Cash",
            "GCash",
            "Bank Transfer",
            "Other"
        ];


        if (
            !in_array(
                $payment_method,
                $allowed_payment_methods,
                true
            )
        ) {

            throw new Exception(
                "Invalid payment method."
            );
        }


        /* ==================================================
           RESERVATION CHECK-IN

           IMPORTANT:
           Clicking "Use Reservation" ONLY fills
           the form.

           The reservation becomes Completed
           ONLY after "Check In Guest" succeeds.
        ================================================== */

        if ($reservation_id > 0) {


            /* ==================================================
               GET RESERVATION
            ================================================== */

            $reservationStmt = $conn->prepare("
                SELECT
                    r.id,
                    r.resort_id,
                    r.full_name,
                    r.contact_number,
                    r.accommodation_id,
                    r.visit_date,
                    r.number_of_guests,
                    r.total_amount,
                    r.amount_paid,
                    r.payment_status,
                    r.payment_method,
                    r.reference_number,
                    r.reservation_status,

                    a.accommodation_name,
                    a.accommodation_code,
                    a.accommodation_type,
                    a.status AS accommodation_status,

                    g.address AS guest_address

                FROM reservations r

                LEFT JOIN accommodations a
                    ON r.accommodation_id = a.id
                    AND a.resort_id = r.resort_id

                LEFT JOIN guests g
                    ON g.resort_id = r.resort_id
                    AND g.full_name = r.full_name
                    AND g.contact_number = r.contact_number

                WHERE r.id = ?
                AND r.resort_id = ?

                LIMIT 1
            ");


            if (!$reservationStmt) {

                throw new Exception(
                    "Unable to check reservation."
                );
            }


            $reservationStmt->bind_param(
                "ii",
                $reservation_id,
                $resort_id
            );


            $reservationStmt->execute();

            $reservationResult =
                $reservationStmt->get_result();


            $reservation =
                $reservationResult->fetch_assoc();


            $reservationStmt->close();


            if (!$reservation) {

                throw new Exception(
                    "Reservation not found."
                );
            }


            /* ==================================================
               ALLOWED RESERVATION STATUS
            ================================================== */

            if (
                !in_array(
                    $reservation["reservation_status"],
                    [
                        "Pending",
                        "Confirmed",
                        "Reserved"
                    ],
                    true
                )
            ) {

                throw new Exception(
                    "This reservation is no longer available for check-in."
                );
            }


            /* ==================================================
               USE RESERVATION DATA

               Reservation data is authoritative.
            ================================================== */

            $guest_name =
                $reservation["full_name"];

            $contact_number =
                $reservation["contact_number"];

            $address =
                $reservation["guest_address"] ?? "";

            $accommodation_id =
                (int) $reservation["accommodation_id"];

            $number_of_guests =
                (int) $reservation["number_of_guests"];

            $total_amount =
                (float) $reservation["total_amount"];

            $amount_paid =
                (float) $reservation["amount_paid"];

            $payment_method =
                $reservation["payment_method"] ?? "Cash";

            $reference_number =
                $reservation["reference_number"] ?? "";


            if ($accommodation_id > 0) {

                $stay_type =
                    "Accommodation";

            } else {

                $stay_type =
                    "Day Visitor";
            }


            /* ==================================================
               ACCOMMODATION CHECK
            ================================================== */

            if (
                $stay_type === "Accommodation"
                && $accommodation_id <= 0
            ) {

                throw new Exception(
                    "The reservation has no accommodation assigned."
                );
            }


            if ($accommodation_id > 0) {

                $accommodationStmt =
                    $conn->prepare("
                        SELECT
                            id,
                            status,
                            accommodation_name,
                            accommodation_code

                        FROM accommodations

                        WHERE id = ?
                        AND resort_id = ?

                        LIMIT 1
                    ");


                if (!$accommodationStmt) {

                    throw new Exception(
                        "Unable to check accommodation."
                    );
                }


                $accommodationStmt->bind_param(
                    "ii",
                    $accommodation_id,
                    $resort_id
                );


                $accommodationStmt->execute();

                $accommodationResult =
                    $accommodationStmt->get_result();


                $accommodation =
                    $accommodationResult->fetch_assoc();


                $accommodationStmt->close();


                if (!$accommodation) {

                    throw new Exception(
                        "Accommodation not found."
                    );
                }


                if (
                    $accommodation["status"]
                    === "Maintenance"
                ) {

                    throw new Exception(
                        "The selected accommodation is under maintenance."
                    );
                }


                if (
                    $accommodation["status"]
                    === "Occupied"
                ) {

                    throw new Exception(
                        "The selected accommodation is already occupied."
                    );
                }


                /* ==================================================
                   CHECK ACTIVE CHECK-IN
                ================================================== */

                $activeStmt = $conn->prepare("
                    SELECT id
                    FROM checkins
                    WHERE resort_id = ?
                    AND accommodation_id = ?
                    AND status = 'Active'
                    LIMIT 1
                ");


                if ($activeStmt) {

                    $activeStmt->bind_param(
                        "ii",
                        $resort_id,
                        $accommodation_id
                    );

                    $activeStmt->execute();

                    $activeResult =
                        $activeStmt->get_result();


                    if (
                        $activeResult->num_rows > 0
                    ) {

                        $activeStmt->close();

                        throw new Exception(
                            "This accommodation already has an active check-in."
                        );
                    }

                    $activeStmt->close();
                }
            }


            /* ==================================================
               PAYMENT STATUS
            ================================================== */

            if ($stay_type === "Day Visitor") {

                $total_amount = 0;
                $amount_paid = 0;
                $remaining_balance = 0;

                $payment_status = "Paid";

            } else {

                $remaining_balance =
                    $total_amount - $amount_paid;


                if ($remaining_balance < 0) {

                    $remaining_balance = 0;
                }


                if (
                    $amount_paid >= $total_amount
                    && $total_amount > 0
                ) {

                    $payment_status = "Paid";

                } elseif ($amount_paid > 0) {

                    $payment_status =
                        "Partially Paid";

                } else {

                    $payment_status =
                        "Pending";
                }
            }


            /* ==================================================
               START TRANSACTION
            ================================================== */

            $conn->begin_transaction();


            /* ==================================================
               FIND GUEST
            ==================================================

               Reservation check-in continues using
               the guest's contact number.
            ================================================== */

            $guest_id = 0;


            $guestStmt = $conn->prepare("
                SELECT id
                FROM guests
                WHERE resort_id = ?
                AND contact_number = ?
                LIMIT 1
            ");


            if (!$guestStmt) {

                throw new Exception(
                    "Unable to find guest."
                );
            }


            $guestStmt->bind_param(
                "is",
                $resort_id,
                $contact_number
            );


            $guestStmt->execute();

            $guestResult =
                $guestStmt->get_result();


            if (
                $guestRow =
                $guestResult->fetch_assoc()
            ) {

                $guest_id =
                    (int) $guestRow["id"];
            }


            $guestStmt->close();


            /* ==================================================
               CREATE OR UPDATE GUEST
            ================================================== */

            if ($guest_id > 0) {

                $updateGuestStmt =
                    $conn->prepare("
                        UPDATE guests
                        SET
                            full_name = ?,
                            address = ?
                        WHERE id = ?
                        AND resort_id = ?
                    ");


                if (!$updateGuestStmt) {

                    throw new Exception(
                        "Unable to update guest."
                    );
                }


                $updateGuestStmt->bind_param(
                    "ssii",
                    $guest_name,
                    $address,
                    $guest_id,
                    $resort_id
                );


                $updateGuestStmt->execute();

                $updateGuestStmt->close();

            } else {

                $insertGuestStmt =
                    $conn->prepare("
                        INSERT INTO guests
                        (
                            resort_id,
                            full_name,
                            contact_number,
                            address
                        )
                        VALUES (?, ?, ?, ?)
                    ");


                if (!$insertGuestStmt) {

                    throw new Exception(
                        "Unable to register guest."
                    );
                }


                $insertGuestStmt->bind_param(
                    "isss",
                    $resort_id,
                    $guest_name,
                    $contact_number,
                    $address
                );


                $insertGuestStmt->execute();

                $guest_id =
                    (int) $conn->insert_id;


                $insertGuestStmt->close();
            }


            /* ==================================================
               INSERT CHECK-IN
            ================================================== */

            $insertCheckinStmt =
                $conn->prepare("
                    INSERT INTO checkins
                    (
                        resort_id,
                        guest_id,
                        visit_type,
                        accommodation_id,
                        check_in_time,
                        number_of_guests,
                        total_amount,
                        amount_paid,
                        remaining_balance,
                        payment_status,
                        status
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        'Active'
                    )
                ");


            if (!$insertCheckinStmt) {

                throw new Exception(
                    "Unable to create check-in."
                );
            }


            $insertCheckinStmt->bind_param(
                "iisiisddds",
                $resort_id,
                $guest_id,
                $stay_type,
                $accommodation_id,
                $check_in_time,
                $number_of_guests,
                $total_amount,
                $amount_paid,
                $remaining_balance,
                $payment_status
            );


            if (
                !$insertCheckinStmt->execute()
            ) {

                throw new Exception(
                    "Unable to save check-in."
                );
            }


            $checkin_id =
                (int) $conn->insert_id;


            $insertCheckinStmt->close();


            /* ==================================================
               MARK ACCOMMODATION AS OCCUPIED
            ================================================== */

            if ($accommodation_id > 0) {

                $updateAccommodationStmt =
                    $conn->prepare("
                        UPDATE accommodations
                        SET status = 'Occupied'
                        WHERE id = ?
                        AND resort_id = ?
                    ");


                if (!$updateAccommodationStmt) {

                    throw new Exception(
                        "Unable to update accommodation."
                    );
                }


                $updateAccommodationStmt->bind_param(
                    "ii",
                    $accommodation_id,
                    $resort_id
                );


                $updateAccommodationStmt->execute();

                $updateAccommodationStmt->close();
            }


            /* ==================================================
               INSERT PAYMENT
            ================================================== */

            if ($amount_paid > 0) {

                $notes =
                    "Check-In payment";

                $insertPaymentStmt =
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
                        (
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?
                        )
                    ");


                if (!$insertPaymentStmt) {

                    throw new Exception(
                        "Unable to record payment."
                    );
                }


                $insertPaymentStmt->bind_param(
                    "iiiidssss",
                    $resort_id,
                    $reservation_id,
                    $checkin_id,
                    $guest_id,
                    $amount_paid,
                    $payment_status,
                    $payment_method,
                    $reference_number,
                    $notes
                );


                if (
                    !$insertPaymentStmt->execute()
                ) {

                    throw new Exception(
                        "Unable to save payment."
                    );
                }


                $insertPaymentStmt->close();
            }


            /* ==================================================
               CHANGE RESERVATION TO COMPLETED
            ================================================== */

            $updateReservationStmt =
                $conn->prepare("
                    UPDATE reservations

                    SET reservation_status = 'Completed'

                    WHERE id = ?

                    AND resort_id = ?

                    AND reservation_status IN (
                        'Pending',
                        'Confirmed',
                        'Reserved'
                    )
                ");


            if (!$updateReservationStmt) {

                throw new Exception(
                    "Unable to complete reservation."
                );
            }


            $updateReservationStmt->bind_param(
                "ii",
                $reservation_id,
                $resort_id
            );


            if (
                !$updateReservationStmt->execute()
            ) {

                throw new Exception(
                    "Unable to update reservation status."
                );
            }


            if (
                $updateReservationStmt->affected_rows <= 0
            ) {

                throw new Exception(
                    "Reservation status could not be updated."
                );
            }


            $updateReservationStmt->close();


            /* ==================================================
               NOTIFICATION
            ================================================== */

            createNotification(
                $conn,
                $resort_id,
                "Guest Checked In",
                $guest_name .
                " has successfully checked in."
            );


            /* ==================================================
               COMMIT
            ================================================== */

            $conn->commit();


            $message =
                "Guest checked in successfully. Reservation is now Completed.";

            $message_type =
                "success";


            /* ==================================================
               CLEAR FORM
            ================================================== */

            $guest_id = 0;
            $guest_name = "";
            $contact_number = "";
            $address = "";
            $accommodation_id = "";
            $number_of_guests = 1;
            $stay_type = "Day Visitor";
            $check_in_time =
                date("Y-m-d\TH:i");
            $total_amount = 0;
            $amount_paid = 0;
            $payment_method = "Cash";
            $reference_number = "";
            $reservation_id = 0;


        } else {


            /* ==================================================
               MANUAL / GUEST PAGE CHECK-IN
            ================================================== */

            if (
                $stay_type === "Accommodation"
                && $accommodation_id <= 0
            ) {

                throw new Exception(
                    "Please select an accommodation."
                );
            }


            /* ==================================================
               ACCOMMODATION CHECK
            ================================================== */

            if ($accommodation_id > 0) {

                $accommodationStmt =
                    $conn->prepare("
                        SELECT
                            id,
                            status
                        FROM accommodations
                        WHERE id = ?
                        AND resort_id = ?
                        LIMIT 1
                    ");


                if (!$accommodationStmt) {

                    throw new Exception(
                        "Unable to check accommodation."
                    );
                }


                $accommodationStmt->bind_param(
                    "ii",
                    $accommodation_id,
                    $resort_id
                );


                $accommodationStmt->execute();

                $accommodationResult =
                    $accommodationStmt->get_result();


                $accommodation =
                    $accommodationResult->fetch_assoc();


                $accommodationStmt->close();


                if (!$accommodation) {

                    throw new Exception(
                        "Accommodation not found."
                    );
                }


                if (
                    $accommodation["status"]
                    === "Maintenance"
                ) {

                    throw new Exception(
                        "The selected accommodation is under maintenance."
                    );
                }


                if (
                    $accommodation["status"]
                    === "Occupied"
                ) {

                    throw new Exception(
                        "The selected accommodation is already occupied."
                    );
                }


                /* ==================================================
                   CHECK ACTIVE CHECK-IN
                ================================================== */

                $activeStmt = $conn->prepare("
                    SELECT id
                    FROM checkins
                    WHERE resort_id = ?
                    AND accommodation_id = ?
                    AND status = 'Active'
                    LIMIT 1
                ");


                if ($activeStmt) {

                    $activeStmt->bind_param(
                        "ii",
                        $resort_id,
                        $accommodation_id
                    );

                    $activeStmt->execute();

                    $activeResult =
                        $activeStmt->get_result();


                    if (
                        $activeResult->num_rows > 0
                    ) {

                        $activeStmt->close();

                        throw new Exception(
                            "This accommodation already has an active check-in."
                        );
                    }

                    $activeStmt->close();
                }
            }


            /* ==================================================
               PAYMENT STATUS
            ================================================== */

            if ($stay_type === "Day Visitor") {

                $total_amount = 0;
                $amount_paid = 0;
                $remaining_balance = 0;

                $payment_status = "Paid";

            } else {

                $remaining_balance =
                    $total_amount - $amount_paid;


                if ($remaining_balance < 0) {

                    $remaining_balance = 0;
                }


                if (
                    $amount_paid >= $total_amount
                    && $total_amount > 0
                ) {

                    $payment_status = "Paid";

                } elseif ($amount_paid > 0) {

                    $payment_status =
                        "Partially Paid";

                } else {

                    $payment_status =
                        "Pending";
                }
            }


            /* ==================================================
               START TRANSACTION
            ================================================== */

            $conn->begin_transaction();


            /* ==================================================
               USE EXISTING GUEST FROM GUESTS PAGE
            ================================================== */

            if ($guest_id > 0) {


                /*
                   Verify that the guest belongs to
                   the current resort.
                */

                $existingGuestStmt =
                    $conn->prepare("
                        SELECT id
                        FROM guests
                        WHERE id = ?
                        AND resort_id = ?
                        LIMIT 1
                    ");


                if (!$existingGuestStmt) {

                    throw new Exception(
                        "Unable to verify guest."
                    );
                }


                $existingGuestStmt->bind_param(
                    "ii",
                    $guest_id,
                    $resort_id
                );


                $existingGuestStmt->execute();

                $existingGuestResult =
                    $existingGuestStmt->get_result();


                if (
                    $existingGuestResult->num_rows <= 0
                ) {

                    $existingGuestStmt->close();

                    throw new Exception(
                        "The selected guest could not be found."
                    );
                }


                $existingGuestStmt->close();


                /*
                   Update the existing guest's basic
                   information.

                   We do not INSERT another guest.
                */

                $updateExistingGuestStmt =
                    $conn->prepare("
                        UPDATE guests
                        SET
                            full_name = ?,
                            contact_number = ?,
                            number_of_guests = ?,
                            visit_type = ?

                        WHERE id = ?
                        AND resort_id = ?
                    ");


                if (!$updateExistingGuestStmt) {

                    throw new Exception(
                        "Unable to update existing guest."
                    );
                }


                $updateExistingGuestStmt->bind_param(
                    "ssissi",
                    $guest_name,
                    $contact_number,
                    $number_of_guests,
                    $stay_type,
                    $guest_id,
                    $resort_id
                );


                if (
                    !$updateExistingGuestStmt->execute()
                ) {

                    throw new Exception(
                        "Unable to update guest information."
                    );
                }


                $updateExistingGuestStmt->close();


            } else {


                /* ==================================================
                   FIND GUEST BY CONTACT NUMBER

                   This is the original manual check-in
                   behavior.
                ================================================== */

                $guestStmt = $conn->prepare("
                    SELECT id
                    FROM guests
                    WHERE resort_id = ?
                    AND contact_number = ?
                    LIMIT 1
                ");


                if (!$guestStmt) {

                    throw new Exception(
                        "Unable to find guest."
                    );
                }


                $guestStmt->bind_param(
                    "is",
                    $resort_id,
                    $contact_number
                );


                $guestStmt->execute();

                $guestResult =
                    $guestStmt->get_result();


                if (
                    $guestRow =
                    $guestResult->fetch_assoc()
                ) {

                    $guest_id =
                        (int) $guestRow["id"];
                }


                $guestStmt->close();


                /* ==================================================
                   CREATE OR UPDATE GUEST
                ================================================== */

                if ($guest_id > 0) {

                    $updateGuestStmt =
                        $conn->prepare("
                            UPDATE guests
                            SET
                                full_name = ?,
                                address = ?

                            WHERE id = ?
                            AND resort_id = ?
                        ");


                    if (!$updateGuestStmt) {

                        throw new Exception(
                            "Unable to update guest."
                        );
                    }


                    $updateGuestStmt->bind_param(
                        "ssii",
                        $guest_name,
                        $address,
                        $guest_id,
                        $resort_id
                    );


                    $updateGuestStmt->execute();

                    $updateGuestStmt->close();

                } else {

                    $insertGuestStmt =
                        $conn->prepare("
                            INSERT INTO guests
                            (
                                resort_id,
                                full_name,
                                contact_number,
                                address
                            )
                            VALUES (?, ?, ?, ?)
                        ");


                    if (!$insertGuestStmt) {

                        throw new Exception(
                            "Unable to register guest."
                        );
                    }


                    $insertGuestStmt->bind_param(
                        "isss",
                        $resort_id,
                        $guest_name,
                        $contact_number,
                        $address
                    );


                    $insertGuestStmt->execute();

                    $guest_id =
                        (int) $conn->insert_id;


                    $insertGuestStmt->close();
                }
            }


            /* ==================================================
               INSERT MANUAL CHECK-IN
            ================================================== */

            $insertCheckinStmt =
                $conn->prepare("
                    INSERT INTO checkins
                    (
                        resort_id,
                        guest_id,
                        visit_type,
                        accommodation_id,
                        check_in_time,
                        number_of_guests,
                        total_amount,
                        amount_paid,
                        remaining_balance,
                        payment_status,
                        status
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        'Active'
                    )
                ");


            if (!$insertCheckinStmt) {

                throw new Exception(
                    "Unable to create check-in."
                );
            }


            $insertCheckinStmt->bind_param(
                "iisiisddds",
                $resort_id,
                $guest_id,
                $stay_type,
                $accommodation_id,
                $check_in_time,
                $number_of_guests,
                $total_amount,
                $amount_paid,
                $remaining_balance,
                $payment_status
            );


            if (
                !$insertCheckinStmt->execute()
            ) {

                throw new Exception(
                    "Unable to save check-in."
                );
            }


            $checkin_id =
                (int) $conn->insert_id;


            $insertCheckinStmt->close();


            /* ==================================================
               MARK ACCOMMODATION OCCUPIED
            ================================================== */

            if ($accommodation_id > 0) {

                $updateAccommodationStmt =
                    $conn->prepare("
                        UPDATE accommodations
                        SET status = 'Occupied'
                        WHERE id = ?
                        AND resort_id = ?
                    ");


                if (!$updateAccommodationStmt) {

                    throw new Exception(
                        "Unable to update accommodation."
                    );
                }


                $updateAccommodationStmt->bind_param(
                    "ii",
                    $accommodation_id,
                    $resort_id
                );


                $updateAccommodationStmt->execute();

                $updateAccommodationStmt->close();
            }


            /* ==================================================
               INSERT PAYMENT
            ================================================== */

            if ($amount_paid > 0) {

                $notes =
                    "Check-In payment";


                $insertPaymentStmt =
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
                        (
                            ?,
                            NULL,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?
                        )
                    ");


                if (!$insertPaymentStmt) {

                    throw new Exception(
                        "Unable to record payment."
                    );
                }


                $insertPaymentStmt->bind_param(
                    "iiidssss",
                    $resort_id,
                    $checkin_id,
                    $guest_id,
                    $amount_paid,
                    $payment_status,
                    $payment_method,
                    $reference_number,
                    $notes
                );


                if (
                    !$insertPaymentStmt->execute()
                ) {

                    throw new Exception(
                        "Unable to save payment."
                    );
                }


                $insertPaymentStmt->close();
            }


            /* ==================================================
               NOTIFICATION
            ================================================== */

            createNotification(
                $conn,
                $resort_id,
                "Guest Checked In",
                $guest_name .
                " has successfully checked in."
            );


            /* ==================================================
               COMMIT
            ================================================== */

            $conn->commit();


            $message =
                "Guest checked in successfully.";

            $message_type =
                "success";


            /* ==================================================
               CLEAR FORM
            ================================================== */

            $guest_id = 0;
            $guest_name = "";
            $contact_number = "";
            $address = "";
            $accommodation_id = "";
            $number_of_guests = 1;
            $stay_type = "Day Visitor";
            $check_in_time =
                date("Y-m-d\TH:i");
            $total_amount = 0;
            $amount_paid = 0;
            $payment_method = "Cash";
            $reference_number = "";
            $reservation_id = 0;
        }


    } catch (Exception $e) {

        try {

            $conn->rollback();

        } catch (Exception $rollbackError) {

            // Ignore rollback error
        }


        $message =
            $e->getMessage();

        $message_type =
            "error";
    }
}


/* ==================================================
   RESERVED GUESTS READY FOR CHECK-IN

   IMPORTANT FIX:
   NO CURDATE() restriction.

   Pending / Confirmed / Reserved reservations
   will appear regardless of visit date.
================================================== */

$reservationList = [];


$reservationListStmt = $conn->prepare("
    SELECT
        r.id AS reservation_id,
        r.full_name,
        r.contact_number,
        r.accommodation_id,
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
        a.accommodation_type,

        g.address AS guest_address

    FROM reservations r

    LEFT JOIN accommodations a
        ON r.accommodation_id = a.id
        AND a.resort_id = r.resort_id

    LEFT JOIN guests g
        ON g.resort_id = r.resort_id
        AND g.full_name = r.full_name
        AND g.contact_number = r.contact_number

    WHERE r.resort_id = ?

    AND r.reservation_status IN (
        'Pending',
        'Confirmed',
        'Reserved'
    )

    ORDER BY
        r.visit_date ASC,
        r.id ASC
");


if ($reservationListStmt) {

    $reservationListStmt->bind_param(
        "i",
        $resort_id
    );

    $reservationListStmt->execute();

    $reservationListResult =
        $reservationListStmt->get_result();


    while (
        $row =
        $reservationListResult->fetch_assoc()
    ) {

        $reservationList[] = $row;
    }


    $reservationListStmt->close();
}


/* ==================================================
   AVAILABLE ACCOMMODATIONS
================================================== */

$accommodations = [];


$accommodationStmt = $conn->prepare("
    SELECT
        id,
        accommodation_code,
        accommodation_name,
        accommodation_type,
        price,
        status

    FROM accommodations

    WHERE resort_id = ?

    AND status = 'Available'

    ORDER BY
        accommodation_code ASC
");


if ($accommodationStmt) {

    $accommodationStmt->bind_param(
        "i",
        $resort_id
    );

    $accommodationStmt->execute();

    $accommodationResult =
        $accommodationStmt->get_result();


    while (
        $row =
        $accommodationResult->fetch_assoc()
    ) {

        $accommodations[] = $row;
    }


    $accommodationStmt->close();
}


/* ==================================================
   ACTIVE CHECK-INS
================================================== */

$activeCheckins = [];


$activeCheckinsStmt = $conn->prepare("
    SELECT

        c.id,

        g.full_name,

        g.contact_number,

        c.visit_type,

        a.accommodation_code,

        a.accommodation_name,

        c.number_of_guests,

        c.check_in_time,

        c.total_amount,

        c.amount_paid,

        c.remaining_balance,

        c.payment_status,

        c.status

    FROM checkins c

    INNER JOIN guests g
        ON c.guest_id = g.id

    LEFT JOIN accommodations a
        ON c.accommodation_id = a.id

    WHERE c.resort_id = ?

    AND c.status = 'Active'

    ORDER BY
        c.check_in_time DESC
");


if ($activeCheckinsStmt) {

    $activeCheckinsStmt->bind_param(
        "i",
        $resort_id
    );

    $activeCheckinsStmt->execute();

    $activeCheckinsResult =
        $activeCheckinsStmt->get_result();


    while (
        $row =
        $activeCheckinsResult->fetch_assoc()
    ) {

        $activeCheckins[] = $row;
    }


    $activeCheckinsStmt->close();
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

<link
    rel="icon"
    type="image/png"
    href="logo1.png"
>

<title>
    Check-In - Alapasco EcoKubo
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


/* ==================================================
   MAIN
================================================== */

.main-content {
    margin-left: 250px;
    width: calc(100% - 250px);
    min-height: 100vh;
}


/* ==================================================
   TOPBAR
================================================== */

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


/* ==================================================
   PAGE
================================================== */

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


/* ==================================================
   MESSAGE
================================================== */

.message {
    padding: 12px 15px;
    border-radius: 7px;
    margin-bottom: 20px;
    font-size: 13px;
    font-weight: bold;
}

.message-success {
    background: #e5f1ea;
    color: #1f5d42;
    border: 1px solid #c8dfd2;
}

.message-error {
    background: #f8e7e7;
    color: #9b3d3d;
    border: 1px solid #edcaca;
}


/* ==================================================
   SECTION
================================================== */

.section {
    background: white;
    padding: 20px;
    border-radius: 10px;
    border: 1px solid #eee;
    margin-bottom: 20px;
}

.section h2 {
    margin: 0 0 5px;
    color: #1f5d42;
    font-size: 18px;
}

.section-description {
    margin: 0 0 18px;
    color: #777;
    font-size: 12px;
}


/* ==================================================
   FORM
================================================== */

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.form-group {
    margin-bottom: 5px;
}

.form-group.full {
    grid-column: 1 / -1;
}

.form-group label {
    display: block;
    margin-bottom: 6px;
    font-size: 12px;
    font-weight: bold;
    color: #555;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 11px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 13px;
    font-family: Arial, sans-serif;
}

.form-group textarea {
    resize: vertical;
    min-height: 80px;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #1f5d42;
}


/* ==================================================
   BUTTONS
================================================== */

.button-row {
    display: flex;
    gap: 10px;
    margin-top: 18px;
    flex-wrap: wrap;
}

.button {
    padding: 11px 18px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-weight: bold;
}

.button-primary {
    background: #1f5d42;
    color: white;
}

.button-primary:hover {
    background: #174832;
}

.button-secondary {
    background: #777;
    color: white;
}

.button-secondary:hover {
    background: #555;
}

.button-use {
    background: #1f5d42;
    color: white;
    padding: 8px 12px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-size: 11px;
    font-weight: bold;
}

.button-use:hover {
    background: #174832;
}


/* ==================================================
   GUEST LOADED NOTICE
================================================== */

.guest-loaded-notice {
    background: #e5f1ea;
    border: 1px solid #c8dfd2;
    color: #1f5d42;
    padding: 13px 15px;
    border-radius: 7px;
    font-size: 12px;
    margin-bottom: 18px;
}

.guest-loaded-notice strong {
    font-weight: bold;
}


/* ==================================================
   TABLE
================================================== */

.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 900px;
}

table th {
    background: #1f5d42;
    color: white;
    padding: 11px;
    text-align: left;
    font-size: 12px;
}

table td {
    padding: 11px;
    border-bottom: 1px solid #eee;
    font-size: 12px;
}

table tr:hover {
    background: #f7f9f8;
}


/* ==================================================
   STATUS
================================================== */

.status {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: bold;
}

.status-pending {
    background: #fff4d6;
    color: #8a6500;
}

.status-confirmed {
    background: #e5f1ea;
    color: #1f5d42;
}

.status-accommodation {
    background: #e5f1ea;
    color: #1f5d42;
}

.status-day {
    background: #eef1f5;
    color: #555;
}

.status-paid {
    background: #e5f1ea;
    color: #1f5d42;
}

.status-partial {
    background: #fff4d6;
    color: #8a6500;
}

.status-payment-pending {
    background: #f5e7e7;
    color: #9b3d3d;
}

.status-active {
    background: #e5f1ea;
    color: #1f5d42;
}


/* ==================================================
   NOTICE
================================================== */

.notice {
    background: #fff8e6;
    border: 1px solid #f0dfaa;
    color: #775d00;
    padding: 12px 15px;
    border-radius: 7px;
    font-size: 12px;
    margin-bottom: 15px;
}


/* ==================================================
   EMPTY
================================================== */

.empty {
    text-align: center;
    padding: 30px;
    color: #777;
    font-size: 13px;
}


/* ==================================================
   RESPONSIVE
================================================== */

@media (max-width: 1000px) {

    .form-grid {
        grid-template-columns: 1fr;
    }

    .form-group.full {
        grid-column: auto;
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

    .user-info strong {
        font-size: 12px;
    }

    .user-info span {
        font-size: 10px;
    }

    .section {
        padding: 15px;
    }
}


@media (max-width: 500px) {

    .main-content {
        margin-left: 185px;
        width: calc(100% - 185px);
    }

    .page-header h1 {
        font-size: 21px;
    }
}

</style>

</head>


<body>


<!-- ==================================================
     SIDEBAR
================================================== -->

<?php require __DIR__ . '/resort_sidebar.php'; ?>


<!-- ==================================================
     MAIN CONTENT
================================================== -->

<div class="main-content">


    <!-- TOPBAR -->

    <div class="topbar">

        <h3>
            Check-In
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


    <!-- PAGE CONTENT -->

    <div class="page-content">


        <!-- PAGE HEADER -->

        <div class="page-header">

            <h1>
                Guest Check-In
            </h1>

            <p>
                Register guests and check in reserved visitors.
            </p>

        </div>


        <!-- MESSAGE -->

        <?php if ($message !== ""): ?>

            <div
                class="message
                <?php
                echo $message_type === "success"
                    ? "message-success"
                    : "message-error";
                ?>"
            >

                <?php
                echo htmlspecialchars(
                    $message
                );
                ?>

            </div>

        <?php endif; ?>


        <!-- ==================================================
             RESERVED GUESTS
        ================================================== -->

        <div class="section">

            <h2>
                📅 Reserved Guests Ready for Check-In
            </h2>

            <p class="section-description">

                Select a reservation below and click
                <strong>Use Reservation</strong>.
                The guest details will automatically appear
                in the check-in form.

            </p>


            <div class="notice">

                <strong>Important:</strong>

                Clicking
                <strong>Use Reservation</strong>
                does not complete the reservation.

                The reservation will change to
                <strong>Completed</strong>
                only after the actual
                <strong>Check In Guest</strong>
                button is successfully processed.

            </div>


            <div class="table-container">


                <?php if (
                    count($reservationList) > 0
                ): ?>


                <table>

                    <thead>

                    <tr>

                        <th>
                            Guest
                        </th>

                        <th>
                            Contact
                        </th>

                        <th>
                            Visit Date
                        </th>

                        <th>
                            Accommodation
                        </th>

                        <th>
                            Guests
                        </th>

                        <th>
                            Total
                        </th>

                        <th>
                            Paid
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


                    <?php
                    foreach (
                        $reservationList
                        as $reservation
                    ):
                    ?>


                    <tr>

                        <td>

                            <?php

                            echo htmlspecialchars(
                                $reservation[
                                    "full_name"
                                ]
                            );

                            ?>

                        </td>


                        <td>

                            <?php

                            echo htmlspecialchars(
                                $reservation[
                                    "contact_number"
                                ]
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

                            if (
                                $reservation[
                                    "accommodation_code"
                                ]
                            ) {

                                echo htmlspecialchars(
                                    $reservation[
                                        "accommodation_code"
                                    ]
                                );

                            } else {

                                echo "Day Visitor";
                            }

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
                                $reservation[
                                    "amount_paid"
                                ],
                                2
                            );

                            ?>

                        </td>


                        <td>

                            <span
                                class="status status-pending"
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

                            <button
                                type="button"
                                class="button-use"
                                onclick='useReservation(
                                    <?php
                                    echo json_encode(
                                        $reservation,
                                        JSON_HEX_TAG |
                                        JSON_HEX_APOS |
                                        JSON_HEX_QUOT |
                                        JSON_HEX_AMP
                                    );
                                    ?>
                                )'
                            >

                                Use Reservation

                            </button>

                        </td>

                    </tr>


                    <?php endforeach; ?>


                    </tbody>

                </table>


                <?php else: ?>


                <div class="empty">

                    No pending, confirmed, or reserved
                    reservations found.

                </div>


                <?php endif; ?>


            </div>

        </div>


        <!-- ==================================================
             CHECK-IN FORM
        ================================================== -->

        <div
            class="section"
            id="checkinSection"
        >

            <h2>
                📝 Check-In Form
            </h2>

            <p class="section-description">
                Enter guest information or use an existing reservation.
            </p>


            <?php if ($guest_loaded_from_url): ?>

                <div class="guest-loaded-notice">

                    <strong>
                        Guest loaded successfully.
                    </strong>

                    Review the guest information below,
                    then select an accommodation if needed
                    and tap
                    <strong>Check In Guest</strong>.

                </div>

            <?php endif; ?>


            <form
                method="POST"
                id="checkinForm"
            >


                <!-- EXISTING RESERVATION ID -->

                <input
                    type="hidden"
                    name="reservation_id"
                    id="reservation_id"
                    value="<?php
                    echo (int)
                        $reservation_id;
                    ?>"
                >


                <!-- EXISTING GUEST ID -->

                <input
                    type="hidden"
                    name="guest_id"
                    id="guest_id"
                    value="<?php
                    echo (int)
                        $guest_id;
                    ?>"
                >


                <div class="form-grid">


                    <!-- FULL NAME -->

                    <div class="form-group">

                        <label>
                            Guest Full Name
                        </label>

                        <input
                            type="text"
                            name="full_name"
                            id="full_name"
                            value="<?php
                            echo htmlspecialchars(
                                $guest_name
                            );
                            ?>"
                            required
                        >

                    </div>


                    <!-- CONTACT -->

                    <div class="form-group">

                        <label>
                            Contact Number
                        </label>

                        <input
                            type="text"
                            name="contact_number"
                            id="contact_number"
                            value="<?php
                            echo htmlspecialchars(
                                $contact_number
                            );
                            ?>"
                            maxlength="11"
                            inputmode="numeric"
                        >

                        <small
                            style="
                                display:block;
                                margin-top:5px;
                                color:#888;
                                font-size:11px;
                            "
                        >
                            11 digits if available
                        </small>

                    </div>


                    <!-- ADDRESS -->

                    <div class="form-group full">

                        <label>
                            Address
                        </label>

                        <textarea
                            name="address"
                            id="address"
                        ><?php
                        echo htmlspecialchars(
                            $address
                        );
                        ?></textarea>

                    </div>


                    <!-- ACCOMMODATION -->

                    <div class="form-group">

                        <label>
                            Accommodation
                        </label>

                        <select
                            name="accommodation_id"
                            id="accommodation_id"
                        >

                            <option value="">
                                No Accommodation / Day Visitor
                            </option>


                            <?php
                            foreach (
                                $accommodations
                                as $accommodation
                            ):
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
                                echo (
                                    (int)
                                    $accommodation_id
                                    ===
                                    (int)
                                    $accommodation["id"]
                                )
                                    ? "selected"
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

                                -
                                ₱<?php

                                echo number_format(
                                    $accommodation[
                                        "price"
                                    ],
                                    2
                                );

                                ?>

                            </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- NUMBER OF GUESTS -->

                    <div class="form-group">

                        <label>
                            Number of Guests
                        </label>

                        <input
                            type="number"
                            name="number_of_guests"
                            id="number_of_guests"
                            min="1"
                            value="<?php
                            echo (int)
                                $number_of_guests;
                            ?>"
                            required
                        >

                    </div>


                    <!-- VISIT TYPE -->

                    <div class="form-group">

                        <label>
                            Visit Type
                        </label>

                        <select
                            name="stay_type"
                            id="stay_type"
                            required
                        >

                            <option
                                value="Day Visitor"
                                <?php
                                echo $stay_type
                                    === "Day Visitor"
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                Day Visitor
                            </option>

                            <option
                                value="Accommodation"
                                <?php
                                echo $stay_type
                                    === "Accommodation"
                                    ? "selected"
                                    : "";
                                ?>
                            >
                                Accommodation
                            </option>

                        </select>

                    </div>


                    <!-- CHECK-IN DATE -->

                    <div class="form-group">

                        <label>
                            Check-In Date & Time
                        </label>

                        <input
                            type="datetime-local"
                            name="check_in_time"
                            id="check_in_time"
                            value="<?php
                            echo htmlspecialchars(
                                $check_in_time
                            );
                            ?>"
                            required
                        >

                    </div>


                    <!-- TOTAL -->

                    <div class="form-group">

                        <label>
                            Total Amount
                        </label>

                        <input
                            type="number"
                            name="total_amount"
                            id="total_amount"
                            min="0"
                            step="0.01"
                            value="<?php
                            echo htmlspecialchars(
                                $total_amount
                            );
                            ?>"
                            required
                        >

                    </div>


                    <!-- AMOUNT PAID -->

                    <div class="form-group">

                        <label>
                            Amount Paid
                        </label>

                        <input
                            type="number"
                            name="amount_paid"
                            id="amount_paid"
                            min="0"
                            step="0.01"
                            value="<?php
                            echo htmlspecialchars(
                                $amount_paid
                            );
                            ?>"
                            required
                        >

                    </div>


                    <!-- PAYMENT METHOD -->

                    <div class="form-group">

                        <label>
                            Payment Method
                        </label>

                        <select
                            name="payment_method"
                            id="payment_method"
                        >

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


                    <!-- REFERENCE NUMBER -->

                    <div class="form-group">

                        <label>
                            Reference Number
                        </label>

                        <input
                            type="text"
                            name="reference_number"
                            id="reference_number"
                            value="<?php
                            echo htmlspecialchars(
                                $reference_number
                            );
                            ?>"
                            placeholder="Optional"
                        >

                    </div>


                </div>


                <!-- BUTTONS -->

                <div class="button-row">

                    <button
                        type="submit"
                        name="check_in_guest"
                        class="button button-primary"
                    >

                        📝 Check In Guest

                    </button>


                    <button
                        type="button"
                        class="button button-secondary"
                        onclick="clearForm()"
                    >

                        Clear

                    </button>

                </div>


            </form>

        </div>


        <!-- ==================================================
             ACTIVE CHECK-INS
        ================================================== -->

        <div class="section">

            <h2>
                👥 Current Active Check-Ins
            </h2>

            <p class="section-description">
                Guests who are currently checked in.
            </p>


            <div class="table-container">


                <?php if (
                    count($activeCheckins) > 0
                ): ?>


                <table>

                    <thead>

                    <tr>

                        <th>
                            Guest
                        </th>

                        <th>
                            Contact
                        </th>

                        <th>
                            Visit Type
                        </th>

                        <th>
                            Accommodation
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

                    </tr>

                    </thead>


                    <tbody>


                    <?php
                    foreach (
                        $activeCheckins
                        as $row
                    ):
                    ?>


                    <tr>

                        <td>

                            <?php

                            echo htmlspecialchars(
                                $row["full_name"]
                            );

                            ?>

                        </td>


                        <td>

                            <?php

                            echo htmlspecialchars(
                                $row[
                                    "contact_number"
                                ]
                            );

                            ?>

                        </td>


                        <td>

                            <?php

                            if (
                                $row["visit_type"]
                                === "Day Visitor"
                            ) {

                                echo '
                                <span class="status status-day">
                                    Day Visitor
                                </span>
                                ';

                            } else {

                                echo '
                                <span class="status status-accommodation">
                                    Accommodation
                                </span>
                                ';
                            }

                            ?>

                        </td>


                        <td>

                            <?php

                            echo htmlspecialchars(
                                $row[
                                    "accommodation_code"
                                ] ?? "—"
                            );

                            ?>

                        </td>


                        <td>

                            <?php

                            echo (int)
                                $row[
                                    "number_of_guests"
                                ];

                            ?>

                        </td>


                        <td>

                            <?php

                            echo date(
                                "M d, Y h:i A",
                                strtotime(
                                    $row[
                                        "check_in_time"
                                    ]
                                )
                            );

                            ?>

                        </td>


                        <td>

                            ₱<?php

                            echo number_format(
                                $row[
                                    "total_amount"
                                ] ?? 0,
                                2
                            );

                            ?>

                        </td>


                        <td>

                            ₱<?php

                            echo number_format(
                                $row[
                                    "amount_paid"
                                ] ?? 0,
                                2
                            );

                            ?>

                        </td>


                        <td>

                            ₱<?php

                            echo number_format(
                                $row[
                                    "remaining_balance"
                                ] ?? 0,
                                2
                            );

                            ?>

                        </td>


                        <td>

                            <?php

                            if (
                                $row[
                                    "visit_type"
                                ]
                                === "Day Visitor"
                            ) {

                                echo "N/A";

                            } elseif (
                                $row[
                                    "payment_status"
                                ]
                                === "Paid"
                            ) {

                                echo '
                                <span
                                    class="status status-paid"
                                >
                                    Paid
                                </span>
                                ';

                            } elseif (
                                $row[
                                    "payment_status"
                                ]
                                === "Partially Paid"
                            ) {

                                echo '
                                <span
                                    class="status status-partial"
                                >
                                    Partially Paid
                                </span>
                                ';

                            } else {

                                echo '
                                <span
                                    class="status status-payment-pending"
                                >
                                    Pending
                                </span>
                                ';
                            }

                            ?>

                        </td>


                        <td>

                            <span
                                class="status status-active"
                            >
                                Active
                            </span>

                        </td>

                    </tr>


                    <?php endforeach; ?>


                    </tbody>

                </table>


                <?php else: ?>


                <div class="empty">

                    No active check-ins found.

                </div>


                <?php endif; ?>


            </div>

        </div>


    </div>

</div>


<script>

/* ==================================================
   USE RESERVATION
================================================== */

function useReservation(reservation) {

    /*
       Clear the direct guest ID because
       reservation check-in uses the reservation's
       guest information.
    */

    document.getElementById(
        "guest_id"
    ).value = "";


    document.getElementById(
        "reservation_id"
    ).value =
        reservation.reservation_id || "";


    document.getElementById(
        "full_name"
    ).value =
        reservation.full_name || "";


    document.getElementById(
        "contact_number"
    ).value =
        reservation.contact_number || "";


    document.getElementById(
        "address"
    ).value =
        reservation.guest_address || "";


    document.getElementById(
        "number_of_guests"
    ).value =
        reservation.number_of_guests || 1;


    document.getElementById(
        "total_amount"
    ).value =
        reservation.total_amount || 0;


    document.getElementById(
        "amount_paid"
    ).value =
        reservation.amount_paid || 0;


    document.getElementById(
        "payment_method"
    ).value =
        reservation.payment_method || "Cash";


    document.getElementById(
        "reference_number"
    ).value =
        reservation.reference_number || "";


    if (
        reservation.accommodation_id
        &&
        parseInt(
            reservation.accommodation_id
        ) > 0
    ) {

        document.getElementById(
            "accommodation_id"
        ).value =
            reservation.accommodation_id;


        document.getElementById(
            "stay_type"
        ).value =
            "Accommodation";

    } else {

        document.getElementById(
            "accommodation_id"
        ).value =
            "";


        document.getElementById(
            "stay_type"
        ).value =
            "Day Visitor";
    }


    document.getElementById(
        "checkinSection"
    ).scrollIntoView({
        behavior: "smooth",
        block: "start"
    });


    alert(
        "Reservation loaded. The reservation is still " +
        reservation.reservation_status +
        ". It will become Completed only after you click Check In Guest."
    );
}


/* ==================================================
   CLEAR FORM
================================================== */

function clearForm() {

    document.getElementById(
        "reservation_id"
    ).value = "";


    document.getElementById(
        "guest_id"
    ).value = "";


    document.getElementById(
        "full_name"
    ).value = "";


    document.getElementById(
        "contact_number"
    ).value = "";


    document.getElementById(
        "address"
    ).value = "";


    document.getElementById(
        "accommodation_id"
    ).value = "";


    document.getElementById(
        "number_of_guests"
    ).value = "1";


    document.getElementById(
        "stay_type"
    ).value = "Day Visitor";


    document.getElementById(
        "check_in_time"
    ).value =
        getCurrentDateTimeLocal();


    document.getElementById(
        "total_amount"
    ).value = "0";


    document.getElementById(
        "amount_paid"
    ).value = "0";


    document.getElementById(
        "payment_method"
    ).value = "Cash";


    document.getElementById(
        "reference_number"
    ).value = "";
}


/* ==================================================
   CURRENT LOCAL DATE/TIME
================================================== */

function getCurrentDateTimeLocal() {

    const now = new Date();

    const year =
        now.getFullYear();

    const month =
        String(
            now.getMonth() + 1
        ).padStart(2, "0");

    const day =
        String(
            now.getDate()
        ).padStart(2, "0");

    const hours =
        String(
            now.getHours()
        ).padStart(2, "0");

    const minutes =
        String(
            now.getMinutes()
        ).padStart(2, "0");


    return (
        year +
        "-" +
        month +
        "-" +
        day +
        "T" +
        hours +
        ":" +
        minutes
    );
}


/* ==================================================
   CONTACT NUMBER
================================================== */

document.getElementById(
    "contact_number"
).addEventListener(
    "input",
    function () {

        this.value =
            this.value
                .replace(
                    /[^0-9]/g,
                    ""
                )
                .slice(
                    0,
                    11
                );
    }
);


/* ==================================================
   ACCOMMODATION CHANGE
================================================== */

document.getElementById(
    "accommodation_id"
).addEventListener(
    "change",
    function () {

        const accommodationId =
            this.value;


        const stayType =
            document.getElementById(
                "stay_type"
            );


        if (accommodationId) {

            stayType.value =
                "Accommodation";

        } else {

            stayType.value =
                "Day Visitor";

            document.getElementById(
                "total_amount"
            ).value =
                "0";

            document.getElementById(
                "amount_paid"
            ).value =
                "0";
        }


        /*
           Auto-price is used only
           for manual / guest check-in.
        */

        const reservationId =
            document.getElementById(
                "reservation_id"
            ).value;


        if (
            reservationId === ""
            &&
            accommodationId
        ) {

            const selected =
                this.options[
                    this.selectedIndex
                ];


            const price =
                selected.getAttribute(
                    "data-price"
                );


            if (price) {

                document.getElementById(
                    "total_amount"
                ).value =
                    parseFloat(
                        price
                    ).toFixed(2);
            }
        }
    }
);


/* ==================================================
   VISIT TYPE CHANGE
================================================== */

document.getElementById(
    "stay_type"
).addEventListener(
    "change",
    function () {

        if (
            this.value ===
            "Day Visitor"
        ) {

            document.getElementById(
                "accommodation_id"
            ).value = "";


            document.getElementById(
                "total_amount"
            ).value = "0";


            document.getElementById(
                "amount_paid"
            ).value = "0";
        }
    }
);


/* ==================================================
   FORM SUBMIT
================================================== */

document.getElementById(
    "checkinForm"
).addEventListener(
    "submit",
    function (event) {

        const reservationId =
            document.getElementById(
                "reservation_id"
            ).value;


        if (reservationId) {

            const confirmed =
                confirm(
                    "Are you sure you want to check in this reserved guest?\n\nThe reservation will change to Completed only after the check-in is successfully saved."
                );


            if (!confirmed) {

                event.preventDefault();

                return;
            }
        }
    }
);


/* ==================================================
   AUTOMATICALLY SCROLL TO CHECK-IN FORM
   WHEN COMING FROM GUESTS / VISITORS
================================================== */

<?php if ($guest_loaded_from_url): ?>

window.addEventListener(
    "DOMContentLoaded",
    function () {

        const checkinSection =
            document.getElementById(
                "checkinSection"
            );


        if (checkinSection) {

            setTimeout(
                function () {

                    checkinSection.scrollIntoView({
                        behavior: "smooth",
                        block: "start"
                    });

                },
                200
            );
        }

    }
);

<?php endif; ?>

</script>


</body>

</html>

<?php

$conn->close();

?>