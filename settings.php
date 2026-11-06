<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: resort_login.php");
    exit;
}

require_once "database.php";

$user_id = $_SESSION['user_id'];

$message = "";
$message_type = "";

$user_id = (int) $_SESSION["user_id"];






if (empty($_SESSION["resort_settings_csrf"])) {
    $_SESSION["resort_settings_csrf"] = bin2hex(random_bytes(32));
}
$resort_settings_csrf_valid = $_SERVER["REQUEST_METHOD"] !== "POST" || hash_equals(
    $_SESSION["resort_settings_csrf"], (string) ($_POST["csrf_token"] ?? "")
);
if (!$resort_settings_csrf_valid) {
    $message = "Security verification failed. Refresh the page and try again.";
    $message_type = "error";
}
/* =========================================================
   GET USER
========================================================= */

$resort_profile_stmt = $conn->prepare(
    "SELECT
        id,
        full_name,
        email,
        contact_number,
        resort_name
     FROM users
     WHERE id = ?
     LIMIT 1"
);

if (!$resort_profile_stmt) {
    die("Unable to load account information.");
}

$resort_profile_stmt->bind_param("i", $user_id);
$resort_profile_stmt->execute();

$resort_profile_result = $resort_profile_stmt->get_result();

if ($resort_profile_result->num_rows === 0) {

    $resort_profile_stmt->close();
    $conn->close();

    die("User account was not found.");
}

$resort_profile_user = $resort_profile_result->fetch_assoc();

$resort_profile_stmt->close();


/* =========================================================
   GET RESORT
========================================================= */

$resort_profile_resort_id = 0;

$resort_profile_resort_name =
    $resort_profile_user["resort_name"] ?? "My Resort";

$resort_profile_profile_picture = "";

$resort_profile_resort_stmt = $conn->prepare(
    "SELECT
        id,
        resort_name,
        profile_picture
     FROM resorts
     WHERE user_id = ?
     LIMIT 1"
);

if ($resort_profile_resort_stmt) {

    $resort_profile_resort_stmt->bind_param(
        "i",
        $user_id
    );

    $resort_profile_resort_stmt->execute();

    $resort_profile_resort_result =
        $resort_profile_resort_stmt->get_result();

    if ($resort_profile_resort_result->num_rows > 0) {

        $resort_profile_resort =
            $resort_profile_resort_result->fetch_assoc();

        $resort_profile_resort_id =
            (int) $resort_profile_resort["id"];

        if (!empty($resort_profile_resort["resort_name"])) {

            $resort_profile_resort_name =
                $resort_profile_resort["resort_name"];
        }

        if (!empty($resort_profile_resort["profile_picture"])) {

            $resort_profile_profile_picture =
                $resort_profile_resort["profile_picture"];
        }
    }

    $resort_profile_resort_stmt->close();
}


/* =========================================================
   CREATE PROFILE PICTURE FOLDER
========================================================= */

$resort_profile_upload_folder =
    __DIR__ . "/profile_uploads";

if (!is_dir($resort_profile_upload_folder)) {

    @mkdir(
        $resort_profile_upload_folder,
        0755,
        true
    );
}


/* =========================================================
   UPLOAD PROFILE PICTURE
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" && $resort_settings_csrf_valid
    &&
    isset($_POST["action"])
    &&
    $_POST["action"] === "upload_picture"
) {

    if ($resort_profile_resort_id <= 0) {

        $message =
            "Resort account was not found.";

        $message_type =
            "error";

    } elseif (
        !isset($_FILES["profile_picture"])
        ||
        $_FILES["profile_picture"]["error"] !== UPLOAD_ERR_OK
    ) {

        $message =
            "Please select a profile picture.";

        $message_type =
            "error";

    } else {

        $resort_profile_file =
            $_FILES["profile_picture"];


        /* =====================================================
           CHECK FILE SIZE
        ===================================================== */

        if ($resort_profile_file["size"] > 5 * 1024 * 1024) {

            $message =
                "Profile picture must not be larger than 5 MB.";

            $message_type =
                "error";

        } else {


            /* =================================================
               CHECK FILE TYPE
            ================================================= */

            $resort_profile_allowed_types = [
                "image/jpeg",
                "image/png",
                "image/webp"
            ];

            $resort_profile_file_type =
                mime_content_type(
                    $resort_profile_file["tmp_name"]
                );


            if (
                !in_array(
                    $resort_profile_file_type,
                    $resort_profile_allowed_types,
                    true
                )
            ) {

                $message =
                    "Please upload a JPG, PNG, or WEBP image.";

                $message_type =
                    "error";

            } else {


                /* =============================================
                   CREATE UNIQUE FILE NAME
                ============================================= */

                $resort_profile_extension = "jpg";

                if ($resort_profile_file_type === "image/png") {
                    $resort_profile_extension = "png";
                } elseif ($resort_profile_file_type === "image/webp") {
                    $resort_profile_extension = "webp";
                }


                $resort_profile_new_filename =
                    "resort_"
                    . $resort_profile_resort_id
                    . "_"
                    . time()
                    . "."
                    . $resort_profile_extension;


                $resort_profile_destination =
                    $resort_profile_upload_folder
                    . "/"
                    . $resort_profile_new_filename;


                /* =============================================
                   MOVE UPLOADED FILE
                ============================================= */

                if (
                    move_uploaded_file(
                        $resort_profile_file["tmp_name"],
                        $resort_profile_destination
                    )
                ) {


                    /* =========================================
                       DELETE OLD PROFILE PICTURE
                    ========================================= */

                    if (
                        !empty($resort_profile_profile_picture)
                    ) {

                        $resort_profile_old_picture =
                            __DIR__
                            . "/"
                            . $resort_profile_profile_picture;

                        if (
                            is_file(
                                $resort_profile_old_picture
                            )
                        ) {

                            @unlink(
                                $resort_profile_old_picture
                            );
                        }
                    }


                    /* =========================================
                       DATABASE PATH
                    ========================================= */

                    $resort_profile_database_picture =
                        "profile_uploads/"
                        . $resort_profile_new_filename;


                    /* =========================================
                       UPDATE DATABASE
                    ========================================= */

                    $resort_profile_picture_stmt =
                        $conn->prepare(
                            "UPDATE resorts
                             SET profile_picture = ?
                             WHERE id = ?"
                        );

                    if ($resort_profile_picture_stmt) {

                        $resort_profile_picture_stmt->bind_param(
                            "si",
                            $resort_profile_database_picture,
                            $resort_profile_resort_id
                        );


                        if (
                            $resort_profile_picture_stmt->execute()
                        ) {

                            $resort_profile_profile_picture =
                                $resort_profile_database_picture;

                            $message =
                                "Profile picture updated successfully.";

                            $message_type =
                                "success";

                        } else {

                            @unlink(
                                $resort_profile_destination
                            );

                            $message =
                                "Unable to save profile picture.";

                            $message_type =
                                "error";
                        }


                        $resort_profile_picture_stmt->close();

                    } else {

                        @unlink(
                            $resort_profile_destination
                        );

                        $message =
                            "Unable to update profile picture.";

                        $message_type =
                            "error";
                    }

                } else {

                    $message =
                        "Unable to upload the profile picture.";

                    $message_type =
                        "error";
                }
            }
        }
    }
}


/* =========================================================
   UPDATE PROFILE
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" && $resort_settings_csrf_valid
    &&
    isset($_POST["action"])
    &&
    $_POST["action"] === "update_profile"
) {

    $resort_profile_full_name =
        trim($_POST["full_name"] ?? "");

    $resort_profile_new_resort_name =
        trim($_POST["resort_name"] ?? "");

    $resort_profile_contact_number =
        trim($_POST["contact_number"] ?? "");

    $resort_profile_email =
        trim($_POST["email"] ?? "");


    /* =====================================================
       VALIDATION
    ===================================================== */

    if (
        $resort_profile_full_name === ""
        ||
        $resort_profile_new_resort_name === ""
        ||
        $resort_profile_email === ""
    ) {

        $message =
            "Please fill in all required fields.";

        $message_type =
            "error";

    } elseif (
        !filter_var(
            $resort_profile_email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $message =
            "Please enter a valid email address.";

        $message_type =
            "error";

    } else {


        /* =================================================
           CHECK EMAIL
        ================================================= */

        $resort_profile_email_check =
            $conn->prepare(
                "SELECT id
                 FROM users
                 WHERE email = ?
                 AND id != ?
                 LIMIT 1"
            );

        if ($resort_profile_email_check) {

            $resort_profile_email_check->bind_param(
                "si",
                $resort_profile_email,
                $user_id
            );

            $resort_profile_email_check->execute();

            $resort_profile_email_result =
                $resort_profile_email_check->get_result();

            if (
                $resort_profile_email_result->num_rows > 0
            ) {

                $message =
                    "That email address is already being used.";

                $message_type =
                    "error";
            }

            $resort_profile_email_check->close();
        }


        /* =================================================
           UPDATE PROFILE
        ================================================= */

        if ($message === "") {

            $resort_profile_update_user =
                $conn->prepare(
                    "UPDATE users
                     SET
                        full_name = ?,
                        email = ?,
                        contact_number = ?,
                        resort_name = ?
                     WHERE id = ?"
                );

            if ($resort_profile_update_user) {

                $resort_profile_update_user->bind_param(
                    "ssssi",
                    $resort_profile_full_name,
                    $resort_profile_email,
                    $resort_profile_contact_number,
                    $resort_profile_new_resort_name,
                    $user_id
                );


                if (
                    $resort_profile_update_user->execute()
                ) {

                    $resort_profile_update_user->close();


                    /* =========================================
                       UPDATE RESORT
                    ========================================= */

                    if ($resort_profile_resort_id > 0) {

                        $resort_profile_update_resort =
                            $conn->prepare(
                                "UPDATE resorts
                                 SET
                                    resort_name = ?,
                                    contact_number = ?
                                 WHERE id = ?"
                            );

                        if ($resort_profile_update_resort) {

                            $resort_profile_update_resort->bind_param(
                                "ssi",
                                $resort_profile_new_resort_name,
                                $resort_profile_contact_number,
                                $resort_profile_resort_id
                            );

                            $resort_profile_update_resort->execute();

                            $resort_profile_update_resort->close();
                        }
                    }


                    /* =========================================
                       UPDATE SESSION
                    ========================================= */

                    $_SESSION["full_name"] =
                        $resort_profile_full_name;

                    $_SESSION["resort_name"] =
                        $resort_profile_new_resort_name;

                    $_SESSION["email"] =
                        $resort_profile_email;

                    $_SESSION["contact_number"] =
                        $resort_profile_contact_number;


                    /* =========================================
                       UPDATE LOCAL VALUES
                    ========================================= */

                    $resort_profile_user["full_name"] =
                        $resort_profile_full_name;

                    $resort_profile_user["email"] =
                        $resort_profile_email;

                    $resort_profile_user["contact_number"] =
                        $resort_profile_contact_number;

                    $resort_profile_user["resort_name"] =
                        $resort_profile_new_resort_name;

                    $resort_profile_resort_name =
                        $resort_profile_new_resort_name;


                    $message =
                        "Profile updated successfully.";

                    $message_type =
                        "success";

                } else {

                    $resort_profile_update_user->close();

                    $message =
                        "Unable to update your profile.";

                    $message_type =
                        "error";
                }

            } else {

                $message =
                    "Unable to update your profile.";

                $message_type =
                    "error";
            }
        }
    }
}


/* =========================================================
   CHANGE PASSWORD
========================================================= */

if (
    $_SERVER["REQUEST_METHOD"] === "POST" && $resort_settings_csrf_valid
    &&
    isset($_POST["action"])
    &&
    $_POST["action"] === "change_password"
) {

    $resort_profile_current_password =
        $_POST["current_password"] ?? "";

    $resort_profile_new_password =
        $_POST["new_password"] ?? "";

    $resort_profile_confirm_password =
        $_POST["confirm_password"] ?? "";


    if (
        $resort_profile_current_password === ""
        ||
        $resort_profile_new_password === ""
        ||
        $resort_profile_confirm_password === ""
    ) {

        $message =
            "Please complete all password fields.";

        $message_type =
            "error";

    } elseif (
        $resort_profile_new_password !==
        $resort_profile_confirm_password
    ) {

        $message =
            "New password and confirmation do not match.";

        $message_type =
            "error";

    } elseif (
        strlen($resort_profile_new_password) < 6
    ) {

        $message =
            "New password must be at least 6 characters.";

        $message_type =
            "error";

    } else {


        /* =================================================
           GET CURRENT PASSWORD
        ================================================= */

        $resort_profile_password_stmt =
            $conn->prepare(
                "SELECT password
                 FROM users
                 WHERE id = ?
                 LIMIT 1"
            );

        if ($resort_profile_password_stmt) {

            $resort_profile_password_stmt->bind_param(
                "i",
                $user_id
            );

            $resort_profile_password_stmt->execute();

            $resort_profile_password_result =
                $resort_profile_password_stmt->get_result();


            if (
                $resort_profile_password_result->num_rows === 0
            ) {

                $message =
                    "Account not found.";

                $message_type =
                    "error";

            } else {

                $resort_profile_password_data =
                    $resort_profile_password_result->fetch_assoc();

                $resort_profile_stored_password =
                    $resort_profile_password_data["password"];


                $resort_profile_password_valid =
                    false;


                if (
                    password_verify(
                        $resort_profile_current_password,
                        $resort_profile_stored_password
                    )
                ) {

                    $resort_profile_password_valid =
                        true;

                } elseif (
                    hash_equals(
                        (string) $resort_profile_stored_password,
                        (string) $resort_profile_current_password
                    )
                ) {

                    $resort_profile_password_valid =
                        true;
                }


                if (!$resort_profile_password_valid) {

                    $message =
                        "Current password is incorrect.";

                    $message_type =
                        "error";

                } else {

                    $resort_profile_hashed_password =
                        password_hash(
                            $resort_profile_new_password,
                            PASSWORD_DEFAULT
                        );


                    $resort_profile_update_password =
                        $conn->prepare(
                            "UPDATE users
                             SET password = ?
                             WHERE id = ?"
                        );

                    if ($resort_profile_update_password) {

                        $resort_profile_update_password->bind_param(
                            "si",
                            $resort_profile_hashed_password,
                            $user_id
                        );


                        if (
                            $resort_profile_update_password->execute()
                        ) {

                            $message =
                                "Password changed successfully.";

                            $message_type =
                                "success";

                        } else {

                            $message =
                                "Unable to change password.";

                            $message_type =
                                "error";
                        }


                        $resort_profile_update_password->close();

                    } else {

                        $message =
                            "Unable to change password.";

                        $message_type =
                            "error";
                    }
                }
            }


            $resort_profile_password_stmt->close();

        } else {

            $message =
                "Unable to access account information.";

            $message_type =
                "error";
        }
    }
}


/* =========================================================
   DISPLAY VALUES
========================================================= */

$resort_profile_display_full_name =
    $resort_profile_user["full_name"] ?? "";

$resort_profile_display_email =
    $resort_profile_user["email"] ?? "";

$resort_profile_display_contact =
    $resort_profile_user["contact_number"] ?? "";

$resort_profile_display_resort_name =
    $resort_profile_user["resort_name"] ?? $resort_profile_resort_name;


/* =========================================================
   GET USER INFORMATION
========================================================= */

$user = null;

$stmt = $conn->prepare("
    SELECT
        id,
        full_name,
        email,
        resort_name
    FROM users
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

$stmt->close();

if (!$user) {
    session_destroy();
    header("Location: resort_login.php");
    exit;
}


/* =========================================================
   GET EXISTING SETTINGS
========================================================= */

$notifications_enabled = 1;
$dashboard_activity_enabled = 1;

$stmt = $conn->prepare("
    SELECT
        notifications_enabled,
        dashboard_activity_enabled
    FROM user_settings
    WHERE user_id = ?
    LIMIT 1
");

$stmt->bind_param("i", $user_id);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows > 0) {

    $settings = $result->fetch_assoc();

    $notifications_enabled =
        (int)$settings['notifications_enabled'];

    $dashboard_activity_enabled =
        (int)$settings['dashboard_activity_enabled'];
}

$stmt->close();


/* =========================================================
   SAVE SETTINGS
========================================================= */

if ($_SERVER["REQUEST_METHOD"] === "POST" && $resort_settings_csrf_valid) {

    if (isset($_POST['save_settings'])) {

        $notifications_enabled =
            isset($_POST['notifications_enabled']) ? 1 : 0;

        $dashboard_activity_enabled =
            isset($_POST['dashboard_activity_enabled']) ? 1 : 0;


        /* Check if settings already exist */

        $stmt = $conn->prepare("
            SELECT id
            FROM user_settings
            WHERE user_id = ?
            LIMIT 1
        ");

        $stmt->bind_param("i", $user_id);
        $stmt->execute();

        $result = $stmt->get_result();
        $existing = $result->fetch_assoc();

        $stmt->close();


        /* Update existing settings */

        if ($existing) {

            $settings_id = $existing['id'];

            $stmt = $conn->prepare("
                UPDATE user_settings
                SET
                    notifications_enabled = ?,
                    dashboard_activity_enabled = ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                "iii",
                $notifications_enabled,
                $dashboard_activity_enabled,
                $settings_id
            );

            if ($stmt->execute()) {

                $message = "Settings saved successfully.";
                $message_type = "success";

            } else {

                $message = "Failed to save settings.";
                $message_type = "error";
            }

            $stmt->close();

        }


        /* Create settings if none exist */

        else {

            $stmt = $conn->prepare("
                INSERT INTO user_settings
                (
                    user_id,
                    notifications_enabled,
                    dashboard_activity_enabled
                )
                VALUES (?, ?, ?)
            ");

            $stmt->bind_param(
                "iii",
                $user_id,
                $notifications_enabled,
                $dashboard_activity_enabled
            );

            if ($stmt->execute()) {

                $message = "Settings saved successfully.";
                $message_type = "success";

            } else {

                $message = "Failed to save settings.";
                $message_type = "error";
            }

            $stmt->close();
        }
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

    <title>Settings - Alapasco EcoKubo</title>

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
           MESSAGE
        ===================================================== */

        .message {
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .message.success {
            background: #e8f5e9;
            color: #1f5d42;
            border: 1px solid #b7d9c4;
        }

        .message.error {
            background: #fdeaea;
            color: #b42318;
            border: 1px solid #f2b8b5;
        }


        /* =====================================================
           SETTINGS CARD
        ===================================================== */

        .settings-card {
            background: white;
            border-radius: 10px;
            border: 1px solid #ddd;
            margin-bottom: 20px;
            overflow: hidden;
        }

        .settings-header {
            padding: 18px 20px;
            border-bottom: 1px solid #eee;
        }

        .settings-header h2 {
            margin: 0;
            color: #1f5d42;
            font-size: 18px;
        }

        .settings-header p {
            margin: 6px 0 0;
            color: #777;
            font-size: 13px;
        }


        /* =====================================================
           SETTING ROW
        ===================================================== */

        .setting-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            padding: 20px;
            border-bottom: 1px solid #eee;
        }

        .setting-row:last-child {
            border-bottom: none;
        }

        .setting-info {
            flex: 1;
        }

        .setting-info h3 {
            margin: 0 0 5px;
            font-size: 15px;
            color: #333;
        }

        .setting-info p {
            margin: 0;
            color: #777;
            font-size: 13px;
            line-height: 1.5;
        }


        /* =====================================================
           TOGGLE
        ===================================================== */

        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 28px;
            flex-shrink: 0;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: #ccc;
            transition: 0.3s;
            border-radius: 30px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 22px;
            width: 22px;
            left: 3px;
            top: 3px;
            background: white;
            transition: 0.3s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background: #1f5d42;
        }

        input:checked + .slider:before {
            transform: translateX(22px);
        }


        /* =====================================================
           INFORMATION
        ===================================================== */

        .info-list {
            padding: 0;
            margin: 0;
            list-style: none;
        }

        .info-list li {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }

        .info-list li:last-child {
            border-bottom: none;
        }

        .info-label {
            color: #777;
        }

        .info-value {
            font-weight: bold;
            color: #333;
            text-align: right;
        }


        /* =====================================================
           BUTTONS
        ===================================================== */

        .button-area {
            padding: 20px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn {
            border: none;
            padding: 11px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #1f5d42;
            color: white;
        }

        .btn-primary:hover {
            background: #174a34;
        }

        .btn-secondary {
            background: #eee;
            color: #333;
        }

        .btn-secondary:hover {
            background: #ddd;
        }


        /* =====================================================
           MOBILE
        ===================================================== */

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

            .user-info {
                font-size: 12px;
            }

            .setting-row {
                align-items: flex-start;
            }

            .info-list li {
                flex-direction: column;
                gap: 5px;
            }

            .info-value {
                text-align: left;
            }

        }

    .resort-profile-section * {
    box-sizing: border-box;
}.resort-profile-section a {
    text-decoration: none;
}.resort-profile-section .page-header {
    margin-bottom: 20px;
}.resort-profile-section .page-header h2 {
    margin: 0 0 5px;
    color: #1f5d42;
    font-size: 24px;
}.resort-profile-section .page-header p {
    margin: 0;
    color: #777;
    font-size: 14px;
}.resort-profile-section .message {
    padding: 13px 15px;
    border-radius: 6px;
    margin-bottom: 20px;
    font-size: 13px;
}.resort-profile-section .message.success {
    background: #e5f2eb;
    color: #1f5d42;
    border: 1px solid #c9e3d5;
}.resort-profile-section .message.error {
    background: #fbeaea;
    color: #a33a3a;
    border: 1px solid #edcccc;
}.resort-profile-section .profile-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}.resort-profile-section .card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 22px;
}.resort-profile-section .card h3 {
    margin: 0 0 5px;
    color: #1f5d42;
    font-size: 18px;
}.resort-profile-section .card-description {
    margin: 0 0 20px;
    color: #777;
    font-size: 13px;
}.resort-profile-section .picture-card {
    grid-column: 1 / -1;
}.resort-profile-section .picture-content {
    display: flex;
    align-items: center;
    gap: 25px;
}.resort-profile-section .profile-picture-container {
    width: 130px;
    height: 130px;
    flex-shrink: 0;
    border-radius: 50%;
    overflow: hidden;
    background: #e5f2eb;
    border: 4px solid #1f5d42;
    display: flex;
    align-items: center;
    justify-content: center;
}.resort-profile-section .profile-picture-container img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}.resort-profile-section .profile-picture-placeholder {
    font-size: 55px;
    color: #1f5d42;
}.resort-profile-section .picture-form {
    flex: 1;
}.resort-profile-section .picture-form input[type="file"] {
    width: 100%;
    padding: 10px;
    border: 1px solid #ccc;
    border-radius: 5px;
    background: white;
    font-size: 13px;
    margin-bottom: 10px;
}.resort-profile-section .picture-note {
    color: #777;
    font-size: 11px;
    margin: 0 0 12px;
}.resort-profile-section .profile-info {
    margin-bottom: 18px;
}.resort-profile-section .profile-info:last-child {
    margin-bottom: 0;
}.resort-profile-section .profile-info label {
    display: block;
    color: #777;
    font-size: 12px;
    margin-bottom: 5px;
}.resort-profile-section .profile-info strong {
    display: block;
    color: #333;
    font-size: 14px;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}.resort-profile-section .form-group {
    margin-bottom: 15px;
}.resort-profile-section .form-group label {
    display: block;
    margin-bottom: 6px;
    font-size: 13px;
    font-weight: bold;
    color: #444;
}.resort-profile-section .form-group label span {
    color: #b33;
}.resort-profile-section .form-group input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #ccc;
    border-radius: 5px;
    font-size: 13px;
    outline: none;
    background: white;
}.resort-profile-section .form-group input:focus {
    border-color: #1f5d42;
}.resort-profile-section .save-btn {
    border: none;
    background: #1f5d42;
    color: white;
    padding: 10px 16px;
    border-radius: 5px;
    font-size: 13px;
    cursor: pointer;
}.resort-profile-section .save-btn:hover {
    opacity: 0.9;
}.resort-profile-section .account-type {
    display: inline-block;
    padding: 6px 10px;
    background: #e5f2eb;
    color: #1f5d42;
    border-radius: 15px;
    font-size: 11px;
    font-weight: bold;
}




@media (max-width: 800px) {.resort-profile-section .profile-grid {
        grid-template-columns: 1fr;
    }.resort-profile-section .picture-card {
        grid-column: auto;
    }.resort-profile-section .picture-content {
        flex-direction: column;
        align-items: flex-start;
    }

}

@media (max-width: 700px) {

}


.resort-profile-section { margin-bottom: 25px; scroll-margin-top: 90px; }
</style>

</head>

<body>

<div class="dashboard-container">


    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <?php require __DIR__ . '/resort_sidebar.php'; ?>


    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <div class="main-content">


        <!-- TOPBAR -->

        <div class="topbar">

            <h3>
                Settings
            </h3>

            <div class="resort-header-account"><?php require __DIR__ . '/resort_header_notifications.php'; ?>
<div class="user-info">

                <strong>
                    <?php echo htmlspecialchars($user['full_name']); ?>
                </strong>

                <span>
                    <?php echo htmlspecialchars($user['resort_name']); ?>
                </span>

            </div></div>

        </div>


        <!-- PAGE CONTENT -->

        <div class="page-content">


            <div class="page-title">

                <h1>
                    Settings
                </h1>

                <p>
                    Manage your system preferences.
                </p>

            </div>


            <!-- MESSAGE -->

            <?php if ($message !== ""): ?>

                <div class="message <?php echo $message_type; ?>">

                    <?php echo htmlspecialchars($message); ?>

                </div>

            <?php endif; ?>


<section class="resort-profile-section" id="profile"><div class="page-header"><h2>My Profile</h2><p>Manage your resort account, picture, and password.</p></div><div class="profile-grid">


<!-- =================================================
     PROFILE PICTURE
================================================= -->

<div class="card picture-card">

    <h3>
        Resort Profile Picture
    </h3>

    <p class="card-description">
        Upload a picture for your resort profile.
    </p>


    <div class="picture-content">


        <div class="profile-picture-container">

            <?php if (!empty($resort_profile_profile_picture)): ?>

                <img
                    src="<?php echo htmlspecialchars($resort_profile_profile_picture); ?>"
                    alt="Resort Profile Picture"
                >

            <?php else: ?>

                <div class="profile-picture-placeholder">
                    🏡
                </div>

            <?php endif; ?>

        </div>


        <div class="picture-form">

            <form
                method="POST"
                action="settings.php#profile"
                enctype="multipart/form-data"
            >
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["resort_settings_csrf"], ENT_QUOTES, "UTF-8"); ?>">

                <input
                    type="hidden"
                    name="action"
                    value="upload_picture"
                >


                <input
                    type="file"
                    name="profile_picture"
                    accept="image/jpeg,image/png,image/webp"
                    required
                >


                <p class="picture-note">
                    JPG, PNG, or WEBP only. Maximum file size: 5 MB.
                </p>


                <button
                    type="submit"
                    class="save-btn"
                >
                    Upload Picture
                </button>

            </form>

        </div>


    </div>

</div>


<!-- =================================================
     ACCOUNT INFORMATION
================================================= -->

<div class="card">

    <h3>
        Account Information
    </h3>

    <p class="card-description">
        Your registered account details.
    </p>


    <div class="profile-info">

        <label>
            Full Name
        </label>

        <strong>
            <?php
            echo htmlspecialchars(
                $resort_profile_display_full_name
            );
            ?>
        </strong>

    </div>


    <div class="profile-info">

        <label>
            Email
        </label>

        <strong>
            <?php
            echo htmlspecialchars(
                $resort_profile_display_email
            );
            ?>
        </strong>

    </div>


    <div class="profile-info">

        <label>
            Contact Number
        </label>

        <strong>
            <?php
            echo htmlspecialchars(
                $resort_profile_display_contact
            );
            ?>
        </strong>

    </div>


    <div class="profile-info">

        <label>
            Resort Name
        </label>

        <strong>
            <?php
            echo htmlspecialchars(
                $resort_profile_display_resort_name
            );
            ?>
        </strong>

    </div>


    <div class="profile-info">

        <label>
            Account Type
        </label>

        <strong>

            <span class="account-type">
                Resort / User
            </span>

        </strong>

    </div>

</div>


<!-- =================================================
     EDIT PROFILE
================================================= -->

<div class="card">

    <h3>
        Edit Profile
    </h3>

    <p class="card-description">
        Update your account and resort information.
    </p>


    <form
        method="POST"
        action="settings.php#profile"
    >
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["resort_settings_csrf"], ENT_QUOTES, "UTF-8"); ?>">

        <input
            type="hidden"
            name="action"
            value="update_profile"
        >


        <div class="form-group">

            <label>
                Full Name <span>*</span>
            </label>

            <input
                type="text"
                name="full_name"
                value="<?php
                echo htmlspecialchars(
                    $resort_profile_display_full_name
                );
                ?>"
                required
            >

        </div>


        <div class="form-group">

            <label>
                Email <span>*</span>
            </label>

            <input
                type="email"
                name="email"
                value="<?php
                echo htmlspecialchars(
                    $resort_profile_display_email
                );
                ?>"
                required
            >

        </div>


        <div class="form-group">

            <label>
                Contact Number
            </label>

            <input
                type="text"
                name="contact_number"
                value="<?php
                echo htmlspecialchars(
                    $resort_profile_display_contact
                );
                ?>"
                placeholder="Enter contact number"
            >

        </div>


        <div class="form-group">

            <label>
                Resort Name <span>*</span>
            </label>

            <input
                type="text"
                name="resort_name"
                value="<?php
                echo htmlspecialchars(
                    $resort_profile_display_resort_name
                );
                ?>"
                required
            >

        </div>


        <button
            type="submit"
            class="save-btn"
        >
            Save Changes
        </button>

    </form>

</div>


<!-- =================================================
     CHANGE PASSWORD
================================================= -->

<div class="card">

    <h3>
        Change Password
    </h3>

    <p class="card-description">
        Change your account password.
    </p>


    <form
        method="POST"
        action="settings.php#profile"
    >
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["resort_settings_csrf"], ENT_QUOTES, "UTF-8"); ?>">

        <input
            type="hidden"
            name="action"
            value="change_password"
        >


        <div class="form-group">

            <label>
                Current Password <span>*</span>
            </label>

            <input
                type="password"
                name="current_password"
                required
            >

        </div>


        <div class="form-group">

            <label>
                New Password <span>*</span>
            </label>

            <input
                type="password"
                name="new_password"
                minlength="6"
                required
            >

        </div>


        <div class="form-group">

            <label>
                Confirm New Password <span>*</span>
            </label>

            <input
                type="password"
                name="confirm_password"
                minlength="6"
                required
            >

        </div>


        <button
            type="submit"
            class="save-btn"
        >
            Change Password
        </button>

    </form>

</div>


</div></section>
            <!-- =================================================
                 SETTINGS FORM
            ================================================== -->

            <form method="POST">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["resort_settings_csrf"], ENT_QUOTES, "UTF-8"); ?>">


                <!-- NOTIFICATION SETTINGS -->

                <div class="settings-card">

                    <div class="settings-header">

                        <h2>
                            🔔 Notification Settings
                        </h2>

                        <p>
                            Control system notifications for your account.
                        </p>

                    </div>


                    <div class="setting-row">

                        <div class="setting-info">

                            <h3>
                                System Notifications
                            </h3>

                            <p>
                                Receive notifications when important
                                check-in and check-out activities happen.
                            </p>

                        </div>


                        <label class="switch">

                            <input
                                type="checkbox"
                                name="notifications_enabled"
                                value="1"
                                <?php
                                echo $notifications_enabled == 1
                                    ? 'checked'
                                    : '';
                                ?>
                            >

                            <span class="slider"></span>

                        </label>

                    </div>

                </div>


                <!-- =================================================
                     DASHBOARD SETTINGS
                ================================================== -->

                <div class="settings-card">

                    <div class="settings-header">

                        <h2>
                            📊 Dashboard Settings
                        </h2>

                        <p>
                            Choose which activity information appears
                            on your dashboard.
                        </p>

                    </div>


                    <div class="setting-row">

                        <div class="setting-info">

                            <h3>
                                Visitor Activity
                            </h3>

                            <p>
                                Show the Visitor Activity chart on the
                                resort dashboard.
                            </p>

                        </div>


                        <label class="switch">

                            <input
                                type="checkbox"
                                name="dashboard_activity_enabled"
                                value="1"
                                <?php
                                echo $dashboard_activity_enabled == 1
                                    ? 'checked'
                                    : '';
                                ?>
                            >

                            <span class="slider"></span>

                        </label>

                    </div>

                </div>


                <!-- =================================================
                     ACCOUNT SECURITY
                ================================================== -->

                <div class="settings-card">

                    <div class="settings-header">

                        <h2>
                            🔐 Account Security
                        </h2>

                        <p>
                            Manage your account information and password.
                        </p>

                    </div>


                    <div class="setting-row">

                        <div class="setting-info">

                            <h3>
                                Password
                            </h3>

                            <p>
                                Change your account password from your
                                profile section above.
                            </p>

                        </div>


                        <a
                            href="#profile"
                            class="btn btn-secondary"
                        >
                            Manage Profile
                        </a>

                    </div>

                </div>


                <!-- =================================================
                     SYSTEM INFORMATION
                ================================================== -->

                <div class="settings-card">

                    <div class="settings-header">

                        <h2>
                            ⚙️ System Information
                        </h2>

                        <p>
                            Basic information about the system.
                        </p>

                    </div>


                    <ul class="info-list">

                        <li>

                            <span class="info-label">
                                System Name
                            </span>

                            <span class="info-value">
                                Alapasco EcoKubo
                            </span>

                        </li>


                        <li>

                            <span class="info-label">
                                System Type
                            </span>

                            <span class="info-value">
                                Tourism Management System
                            </span>

                        </li>


                        <li>

                            <span class="info-label">
                                Account
                            </span>

                            <span class="info-value">
                                Resort / Rest House User
                            </span>

                        </li>


                        <li>

                            <span class="info-label">
                                Version
                            </span>

                            <span class="info-value">
                                1.0
                            </span>

                        </li>

                    </ul>

                </div>


                <!-- =================================================
                     SAVE BUTTON
                ================================================== -->

                <div class="settings-card">

                    <div class="button-area">

                        <a
                            href="resort_dashboard.php"
                            class="btn btn-secondary"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            name="save_settings"
                            class="btn btn-primary"
                        >
                            💾 Save Settings
                        </button>

                    </div>

                </div>


            </form>


        </div>

    </div>

</div>

</body>

</html>