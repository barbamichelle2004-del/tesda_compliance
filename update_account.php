<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_SESSION_ID');
    session_start();
}

require_once __DIR__ . '/config/config.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$pdo = $GLOBALS['pdo'] ?? null;

if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'DB connection error.']);
    exit;
}

try {
    // ACTION 1: Update Account Details
    if (isset($_POST['update_own_account'])) {
        $full_name = trim($_POST['full_name'] ?? '');
        $email     = trim($_POST['email'] ?? '');
        $contact   = trim($_POST['contact_number'] ?? '');

        if (empty($full_name) || empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Full name and email are required.']);
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
            exit;
        }

        $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check->execute([$email, $user_id]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already in use.']);
            exit;
        }

        $picture_path = null;
        if (isset($_FILES['profile_picture_file']) && $_FILES['profile_picture_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/uploads/profiles/';
            if (!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0777, true);
            }

            $file = $_FILES['profile_picture_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (!in_array($ext, $allowed)) {
                echo json_encode(['success' => false, 'message' => 'Invalid file type.']);
                exit;
            }

            if ($file['size'] > 2 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'File too large. Max 2MB.']);
                exit;
            }

            $new_filename = 'user_' . $user_id . '_' . time() . '.' . $ext;
            $upload_path = $upload_dir . $new_filename;

            if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                $old = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
                $old->execute([$user_id]);
                $old_pic = $old->fetchColumn();
                if ($old_pic && file_exists(__DIR__ . '/' . $old_pic)) {
                    @unlink(__DIR__ . '/' . $old_pic);
                }

                $picture_path = 'uploads/profiles/' . $new_filename;
            }
        }

        if ($picture_path) {
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, contact_number = ?, profile_picture = ? WHERE id = ?");
            $stmt->execute([$full_name, $email, $contact, $picture_path, $user_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, contact_number = ? WHERE id = ?");
            $stmt->execute([$full_name, $email, $contact, $user_id]);
        }

        $_SESSION['full_name'] = $full_name;

        echo json_encode(['success' => true, 'message' => 'Account updated successfully!']);
        exit;
    }

    // ACTION 2: Change Password
    if (isset($_POST['update_own_password'])) {
        $current  = $_POST['current_password'] ?? '';
        $new      = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (empty($current) || empty($new) || empty($confirm)) {
            echo json_encode(['success' => false, 'message' => 'All fields are required.']);
            exit;
        }

        if ($new !== $confirm) {
            echo json_encode(['success' => false, 'message' => 'New passwords do not match.']);
            exit;
        }

        if (strlen($new) < 6) {
            echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $stored_hash = $stmt->fetchColumn();

        if (!password_verify($current, $stored_hash)) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
            exit;
        }

        $new_hash = password_hash($new, PASSWORD_DEFAULT);
        $update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update->execute([$new_hash, $user_id]);

        echo json_encode(['success' => true, 'message' => 'Password changed successfully!']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action.']);

} catch (PDOException $e) {
    error_log("update_account error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}