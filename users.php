<?php
require_once __DIR__ . '/auth_check.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn()) {
    redirect('index.php');
}

$user = getCurrentUser();

if ($user['role'] != 'coordinator') {
    redirect('dashboard.php');
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    require_once 'config/database.php';
    $pdo = getDBConnection();
}

// ==========================================
// ENSURE COLUMNS & TABLES EXIST
// ==========================================
try {
    $check_pic_col = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_picture'");
    if ($check_pic_col->rowCount() == 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) DEFAULT NULL");
    }
    
    $check_dept_id = $pdo->query("SHOW COLUMNS FROM users LIKE 'department_id'");
    if ($check_dept_id->rowCount() == 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN department_id INT NULL AFTER department");
    }
} catch(PDOException $e) {
    error_log("Column setup: " . $e->getMessage());
}

try {
    $check = $pdo->query("SHOW TABLES LIKE 'user_courses'");
    if ($check->rowCount() > 0) {
        $pdo->exec("RENAME TABLE user_courses TO user_qualifications");
    }
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_qualifications (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        qualification_id INT NOT NULL,
        assigned_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (qualification_id) REFERENCES qualifications(id) ON DELETE CASCADE,
        UNIQUE KEY unique_user_qualification (user_id, qualification_id)
    )");
} catch(PDOException $e) {
    error_log("User qualifications table setup: " . $e->getMessage());
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS trainer_trainee_assignments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        trainer_id INT NOT NULL,
        trainee_id INT NOT NULL,
        assigned_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_active TINYINT DEFAULT 1,
        FOREIGN KEY (trainer_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (trainee_id) REFERENCES trainees(id) ON DELETE CASCADE,
        UNIQUE KEY unique_trainer_trainee (trainer_id, trainee_id)
    )");
} catch(PDOException $e) {
    error_log("Error creating trainer_trainee_assignments: " . $e->getMessage());
}

$message = '';
$error = '';

// ==========================================
// HELPERS
// ==========================================
function validateQualification($pdo, $qualification_id) {
    if (empty($qualification_id)) {
        return ['valid' => false, 'message' => 'No qualification selected.'];
    }
    $qualification_id = (int)$qualification_id;
    $stmt = $pdo->prepare("SELECT id, qualification_name FROM qualifications WHERE id = ?");
    $stmt->execute([$qualification_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$result) {
        return ['valid' => false, 'message' => 'Selected qualification does not exist in the system.'];
    }
    return ['valid' => true, 'data' => $result];
}

function uploadProfilePicture($user_id, $file) {
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'No file uploaded or upload error.'];
    }
    $upload_dir = __DIR__ . '/uploads/profiles/';
    if (!is_dir($upload_dir)) { @mkdir($upload_dir, 0777, true); }
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($file_ext, $allowed_ext)) {
        return ['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, GIF, WEBP allowed.'];
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return ['success' => false, 'message' => 'File too large. Max 2MB.'];
    }
    $new_filename = 'user_' . (int)$user_id . '_' . time() . '.' . $file_ext;
    $upload_path = $upload_dir . $new_filename;
    if (move_uploaded_file($file['tmp_name'], $upload_path)) {
        return ['success' => true, 'path' => 'uploads/profiles/' . $new_filename];
    }
    return ['success' => false, 'message' => 'Failed to move uploaded file.'];
}

// ==========================================
// HANDLE UPLOAD PROFILE PICTURE
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_profile_picture'])) {
    try {
        $target_user_id = (int)$_POST['profile_user_id'];
        if ($target_user_id <= 0) { $error = "Invalid user ID."; }
        elseif (!isset($_FILES['profile_picture_file'])) { $error = "No file selected."; }
        else {
            $upload = uploadProfilePicture($target_user_id, $_FILES['profile_picture_file']);
            if ($upload['success']) {
                $update_stmt = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                $update_stmt->execute([$upload['path'], $target_user_id]);
                $message = "Profile picture updated successfully!";
            } else { $error = "Upload failed: " . $upload['message']; }
        }
    } catch(PDOException $e) {
        error_log("Profile picture upload error: " . $e->getMessage());
        $error = "Error uploading profile picture: " . $e->getMessage();
    }
}

// ==========================================
// ✅ HANDLE UPDATE TRAINER/COORDINATOR ACCOUNT (with department + qualification reassignment)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_trainer_account'])) {
    try {
        $trainer_id = (int)$_POST['trainer_id'];
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $contact = trim($_POST['contact_number'] ?? '');
        $new_department_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
        $selected_qualifications = isset($_POST['qualifications']) && is_array($_POST['qualifications'])
            ? array_map('intval', $_POST['qualifications'])
            : [];

        if ($trainer_id <= 0) throw new Exception("Invalid user ID.");
        if (empty($full_name) || empty($email)) throw new Exception("Full name and email are required.");
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("Invalid email format.");

        $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check->execute([$email, $trainer_id]);
        if ($check->fetch()) throw new Exception("Email already in use by another account.");

        $pdo->beginTransaction();

        if (isset($_FILES['profile_picture_file']) && $_FILES['profile_picture_file']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadProfilePicture($trainer_id, $_FILES['profile_picture_file']);
            if ($upload['success']) {
                $old = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
                $old->execute([$trainer_id]);
                $old_pic = $old->fetchColumn();
                if ($old_pic && file_exists(__DIR__ . '/' . $old_pic)) { @unlink(__DIR__ . '/' . $old_pic); }
                $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?")->execute([$upload['path'], $trainer_id]);
            }
        }

        $dept_code = null;
        if ($new_department_id) {
            $dept_stmt = $pdo->prepare("SELECT course_code FROM courses WHERE id = ?");
            $dept_stmt->execute([$new_department_id]);
            $dept_code = $dept_stmt->fetchColumn();
        }

        $stmt = $pdo->prepare("UPDATE users SET full_name=?, email=?, contact_number=?, department=?, department_id=? WHERE id=?");
        $stmt->execute([$full_name, $email, $contact, $dept_code, $new_department_id, $trainer_id]);

        $pdo->prepare("DELETE FROM user_qualifications WHERE user_id = ?")->execute([$trainer_id]);
        if (!empty($selected_qualifications)) {
            $insert_qual = $pdo->prepare("INSERT INTO user_qualifications (user_id, qualification_id) VALUES (?, ?)");
            foreach ($selected_qualifications as $qual_id) {
                if ($qual_id > 0) {
                    try { $insert_qual->execute([$trainer_id, $qual_id]); } catch (PDOException $e) {}
                }
            }
        }

        $pdo->commit();
        $message = "Account updated successfully!";
    } catch(PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Error updating account: " . $e->getMessage();
    } catch(Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// ==========================================
// HANDLE DEACTIVATE TRAINER
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['deactivate_trainer'])) {
    try {
        if (!$is_coordinator) throw new Exception("Only coordinators can deactivate trainers.");
        $trainer_id = (int)$_POST['trainer_id'];
        if ($trainer_id <= 0) throw new Exception("Invalid trainer ID.");
        if ($trainer_id == $user['id']) throw new Exception("You cannot deactivate your own account.");

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM user_qualifications WHERE user_id = ?")->execute([$trainer_id]);
        $pdo->prepare("DELETE FROM trainer_trainee_assignments WHERE trainer_id = ?")->execute([$trainer_id]);
        $stmt = $pdo->prepare("DELETE FROM users WHERE id=? AND role='trainer'");
        $stmt->execute([$trainer_id]);
        $pdo->commit();
        $message = "Trainer deactivated successfully!";
    } catch(PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Error deactivating trainer: " . $e->getMessage();
    } catch(Exception $e) {
        $error = $e->getMessage();
    }
}

// ==========================================
// HANDLE UNLOCK ACCOUNT
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['unlock_user'])) {
    try {
        $target_user_id = (int)$_POST['unlock_user_id'];
        if ($target_user_id <= 0) { $error = "Invalid user ID."; }
        else {
            $pdo->prepare("
                UPDATE users 
                SET login_attempts = 0, 
                    lockout_until = NULL, 
                    lockout_tier = 0, 
                    is_locked = 0 
                WHERE id = ?
            ")->execute([$target_user_id]);
            $message = "User account unlocked successfully!";
        }
    } catch(PDOException $e) {
        error_log("Unlock error: " . $e->getMessage());
        $error = "Error unlocking account: " . $e->getMessage();
    }
}

// ==========================================
// HANDLE ADD TRAINER
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_trainer'])) {
    try {
        $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $check->execute([$_POST['username']]);
        if ($check->fetchColumn() > 0) {
            $error = "Username already exists!";
        } else {
            $password = $_POST['password'] ?? '';
            if (empty($password)) { $error = "Password is required!"; }
            else {
                $pdo->beginTransaction();
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, contact_number, role, status) VALUES (?, ?, ?, ?, ?, 'trainer', 'active')");
                $stmt->execute([$_POST['username'], $hashed, $_POST['full_name'], $_POST['email'], $_POST['contact_number'] ?? null]);
                $new_id = $pdo->lastInsertId();
                
                $valid_qualifications = [];
                if (isset($_POST['qualifications']) && is_array($_POST['qualifications'])) {
                    foreach ($_POST['qualifications'] as $qualification_id) {
                        $qualification_id = (int)$qualification_id;
                        $validation = validateQualification($pdo, $qualification_id);
                        if ($validation['valid']) { $valid_qualifications[] = $qualification_id; }
                    }
                    if (!empty($valid_qualifications)) {
                        $qual_stmt = $pdo->prepare("INSERT INTO user_qualifications (user_id, qualification_id) VALUES (?, ?)");
                        foreach ($valid_qualifications as $qualification_id) {
                            try { $qual_stmt->execute([$new_id, $qualification_id]); } catch(PDOException $e) {}
                        }
                    }
                }
                
                if (isset($_POST['assign_all_trainees']) && $_POST['assign_all_trainees'] == 'yes' && !empty($valid_qualifications)) {
                    $placeholders = implode(',', array_fill(0, count($valid_qualifications), '?'));
                    $trainees = $pdo->prepare("SELECT t.id FROM trainees t WHERE t.qualification_id IN ($placeholders)");
                    $trainees->execute($valid_qualifications);
                    $trainee_ids = $trainees->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($trainee_ids)) {
                        $assign_stmt = $pdo->prepare("INSERT INTO trainer_trainee_assignments (trainer_id, trainee_id) VALUES (?, ?)");
                        foreach ($trainee_ids as $trainee_id) {
                            try { $assign_stmt->execute([$new_id, $trainee_id]); } catch(PDOException $e) {}
                        }
                    }
                }
                $pdo->commit();
                $message = "Trainer added successfully!";
            }
        }
    } catch(PDOException $e) {
        if (isset($pdo)) $pdo->rollBack();
        $error = "Error adding trainer: " . $e->getMessage();
    } catch(Exception $e) { $error = $e->getMessage(); }
}

// ==========================================
// FETCH DATA
// ==========================================
$users = $pdo->query("SELECT * FROM users ORDER BY role DESC, full_name")->fetchAll();

$all_courses = $pdo->query("SELECT * FROM courses ORDER BY course_code")->fetchAll();
$courses = array_filter($all_courses, function($course) {
    return in_array($course['course_code'], ['CSS', 'ATS', 'HRS']);
});

try {
    $check_status = $pdo->query("SHOW COLUMNS FROM qualifications LIKE 'status'");
    $has_status = $check_status->rowCount() > 0;
} catch(PDOException $e) { $has_status = false; }

try {
    if ($has_status) {
        $all_qualifications = $pdo->query("
            SELECT q.*, c.course_code, c.course_name 
            FROM qualifications q 
            JOIN courses c ON q.course_id = c.id 
            WHERE q.status = 'active'
            ORDER BY c.course_code, q.qualification_name
        ")->fetchAll();
    } else {
        $all_qualifications = $pdo->query("
            SELECT q.*, c.course_code, c.course_name 
            FROM qualifications q 
            JOIN courses c ON q.course_id = c.id 
            ORDER BY c.course_code, q.qualification_name
        ")->fetchAll();
    }
} catch(PDOException $e) {
    $all_qualifications = [];
}

$qualifications_by_course = [];
foreach ($all_qualifications as $qual) {
    if (!isset($qualifications_by_course[$qual['course_id']])) {
        $qualifications_by_course[$qual['course_id']] = [];
    }
    $qualifications_by_course[$qual['course_id']][] = $qual;
}

// Build trainer_qualifications
$trainer_qualifications = [];
$trainer_ids = [];

foreach ($users as $u) {
    if (in_array($u['role'], ['trainer', 'coordinator'])) {
        $trainer_ids[] = $u['id'];
    }
}

if (!empty($trainer_ids)) {
    $placeholders = implode(',', array_fill(0, count($trainer_ids), '?'));
    $assignments = $pdo->prepare("
        SELECT uq.user_id, q.id as qualification_id, q.qualification_name, q.qualification_code, q.course_id, c.course_code
        FROM user_qualifications uq
        JOIN qualifications q ON uq.qualification_id = q.id
        JOIN courses c ON q.course_id = c.id
        WHERE uq.user_id IN ($placeholders)
        ORDER BY c.course_code, q.qualification_name
    ");
    $assignments->execute($trainer_ids);
    $results = $assignments->fetchAll();
    
    foreach ($results as $row) {
        $user_id = $row['user_id'];
        if (!isset($trainer_qualifications[$user_id])) {
            $trainer_qualifications[$user_id] = ['data' => [], 'ids' => []];
        }
        $trainer_qualifications[$user_id]['data'][] = [
            'id' => $row['qualification_id'],
            'name' => $row['qualification_name'],
            'code' => $row['qualification_code'],
            'course_id' => $row['course_id'],
            'course_code' => $row['course_code']
        ];
        $trainer_qualifications[$user_id]['ids'][] = $row['qualification_id'];
    }
}

// Get trainer trainee counts
$trainer_trainee_counts = [];
if (!empty($trainer_ids)) {
    $placeholders = implode(',', array_fill(0, count($trainer_ids), '?'));
    $counts = $pdo->prepare("
        SELECT trainer_id, COUNT(*) as trainee_count 
        FROM trainer_trainee_assignments 
        WHERE trainer_id IN ($placeholders) AND is_active = 1
        GROUP BY trainer_id
    ");
    $counts->execute($trainer_ids);
    $trainer_trainee_counts = $counts->fetchAll(PDO::FETCH_KEY_PAIR);
}

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - TESDA Compliance</title>
    <link rel="stylesheet" href="/tesda_compliance/assets/vendor/fontawesome/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: #f0f2f5; min-width: 1200px; overflow-x: auto; margin: 0; transition: all 0.3s ease; }
        body.sidebar-collapsed { min-width: 992px; }

        .main-content {
            margin-left: 280px; padding: 30px; width: calc(100% - 280px);
            min-width: 920px; transition: margin-left 0.3s ease, width 0.3s ease;
            animation: fadeInPage 0.4s ease-out;
        }
        @keyframes fadeInPage { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        body.sidebar-collapsed .main-content { margin-left: 80px; width: calc(100% - 80px); }

        .header {
            background: white; border-radius: 24px; padding: 25px 35px;
            margin-bottom: 30px; display: flex; justify-content: space-between;
            align-items: center; box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            border: 1px solid rgba(255,255,255,0.2);
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .header:hover { transform: translateY(-2px); box-shadow: 0 15px 45px rgba(0,0,0,0.12); }
        .header-left { display: flex; flex-direction: column; gap: 2px; }
        .header-left h1 {
            font-size: 1.8rem; font-weight: 700; color: #0f172a;
            display: flex; align-items: center; gap: 12px; margin: 0; line-height: 1.2;
        }
        .header-left h1 i { color: #3b82f6; font-size: 1.8rem; }
        .header-left p { color: #64748b; font-size: 1rem; margin: 0; margin-top: 2px; }
        .header-right { display: flex; align-items: center; gap: 18px; }

        .profile-badge {
            display: flex; align-items: center; gap: 12px;
            padding: 6px 20px 6px 6px; background-color: #f8fafc;
            border: 1px solid #e2e8f0; border-radius: 50px;
            transition: all 0.2s ease; cursor: default;
        }
        .profile-badge:hover { background-color: #f1f5f9; border-color: #cbd5e1; }
        .profile-badge .avatar-circle {
            width: 32px; height: 32px; border-radius: 50%;
            background-color: #263868; display: flex;
            align-items: center; justify-content: center;
            flex-shrink: 0; transition: all 0.2s ease;
        }
        .profile-badge:hover .avatar-circle { transform: scale(1.05); }
        .profile-badge .avatar-circle i { color: #ffffff; font-size: 0.9rem; }
        .profile-badge .profile-name {
            font-weight: 600; font-size: 0.95rem; color: #1e293b;
            letter-spacing: 0.3px; white-space: nowrap;
        }

        .date-badge {
            background: #f8fafc; padding: 10px 20px; border-radius: 12px;
            color: #0f172a; font-weight: 500; font-size: 1rem;
            border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 10px;
        }
        .date-badge i { color: #0f172a; font-size: 1rem; }
        .date-badge:hover { background-color: #f1f5f9; }

        .add-trainer-card {
            background: white; border-radius: 16px; padding: 20px 28px;
            margin-bottom: 30px; display: flex; justify-content: space-between;
            align-items: center; box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
        }
        .add-trainer-card h3 {
            font-size: 1.5rem; font-weight: 600; color: #1e293b;
            display: flex; align-items: center; gap: 12px;
        }
        .add-trainer-card h3 i { color: #10b981; font-size: 1.3rem; }

        .btn-add {
            background: linear-gradient(135deg, #10b981, #059669); color: white;
            border: none; padding: 10px 24px; border-radius: 30px;
            font-weight: 600; font-size: 0.95rem; cursor: pointer;
            display: flex; align-items: center; gap: 8px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 12px rgba(16,185,129,0.3);
        }
        .btn-add:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(16,185,129,0.4); }

        .department-section {
            margin-bottom: 30px; background: white; border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0; overflow: hidden;
        }
        .department-header {
            padding: 18px 24px; background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex; align-items: center; gap: 14px;
        }
        .dept-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; color: white;
        }
        .department-header h3 { font-size: 1.15rem; font-weight: 600; color: #0f172a; margin: 0; }
        .dept-code { font-size: 0.85rem; color: #64748b; font-weight: 400; }

        .qualification-list { padding: 0; }
        .qualification-item { border-bottom: 1px solid #f1f5f9; }
        .qualification-item:last-child { border-bottom: none; }
        .qualification-header-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 16px 24px; cursor: pointer; transition: background 0.2s;
            background: white;
        }
        .qualification-header-row:hover { background: #f8fafc; }
        .qual-left { display: flex; align-items: center; gap: 12px; }
        .qual-left .qual-icon { color: #8b5cf6; font-size: 1rem; }
        .qual-left h4 { font-size: 1rem; font-weight: 600; color: #1e293b; margin: 0; }
        .qual-right { display: flex; align-items: center; gap: 16px; }
        .trainer-count-badge {
            background: #dbeafe; color: #1e40af; padding: 4px 12px;
            border-radius: 20px; font-size: 0.75rem; font-weight: 600;
            display: flex; align-items: center; gap: 6px;
        }
        .btn-assign-sm {
            background: #1e293b; color: white; border: none;
            padding: 6px 14px; border-radius: 20px; font-weight: 500;
            font-size: 0.75rem; cursor: pointer;
            display: flex; align-items: center; gap: 6px;
            transition: all 0.2s;
        }
        .btn-assign-sm:hover { background: #0f172a; }
        .accordion-icon { color: #94a3b8; font-size: 0.9rem; transition: transform 0.3s; }
        .accordion-icon.open { transform: rotate(180deg); }
        .trainer-list { padding: 0 24px 20px 24px; background: #fafbfc; display: none; }
        .trainer-list.open { display: block; }

        .trainer-item {
            background: white; border-radius: 10px;
            border-left: 4px solid #2563EB;
            padding: 16px 20px; margin-top: 12px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
            border-left-width: 4px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .trainer-item:first-child { margin-top: 16px; }
        .trainer-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
            border-color: #cbd5e1;
        }

        .trainer-item-top {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 12px; flex-wrap: wrap; gap: 12px;
        }
        .trainer-item-left { display: flex; align-items: center; gap: 14px; flex: 1; }
        .trainer-avatar-sm {
            width: 44px; height: 44px; border-radius: 50%;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            color: white; display: flex; align-items: center;
            justify-content: center; font-weight: 700; font-size: 0.95rem;
            flex-shrink: 0; position: relative; overflow: hidden;
        }
        .trainer-avatar-sm img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .trainer-avatar-sm .status-dot {
            position: absolute; bottom: 1px; right: 1px;
            width: 12px; height: 12px; border-radius: 50%;
            border: 2px solid white;
        }
        .status-dot.online { background: #10b981; }
        .status-dot.offline { background: #ef4444; }
        .trainer-info-sm { display: flex; flex-direction: column; gap: 2px; }
        .trainer-info-sm .name { font-size: 1rem; font-weight: 600; color: #0f172a; }

        .trainer-details-sm {
            display: grid; grid-template-columns: auto auto 1fr;
            gap: 10px; background: #f8fafc; padding: 10px 14px;
            border-radius: 8px; align-items: center;
        }
        .detail-item-sm {
            display: flex; align-items: center; gap: 8px;
            font-size: 0.85rem; color: #475569;
        }
        .detail-item-sm .icon-box-sm {
            width: 24px; height: 24px; border-radius: 6px;
            background: #e2e8f0; display: flex;
            align-items: center; justify-content: center;
            font-size: 0.65rem; color: #475569; flex-shrink: 0;
        }
        .detail-item-sm .icon-box-sm.blue { background: #dbeafe; color: #2563eb; }
        .detail-item-sm .icon-box-sm.green { background: #d1fae5; color: #10b981; }

        .trainer-open-hint {
            display: flex; align-items: center; gap: 6px;
            font-size: 0.75rem; color: #94a3b8;
            justify-self: end;
        }

        .empty-trainers {
            text-align: center; padding: 30px 20px; color: #94a3b8;
            background: white; border-radius: 10px; margin-top: 12px;
            border: 1px dashed #e2e8f0;
        }
        .empty-trainers i { font-size: 1.8rem; color: #cbd5e1; margin-bottom: 10px; }
        .empty-trainers p { font-size: 0.9rem; }

        .modal {
            display: none; position: fixed; top: 0; left: 0;
            width: 100%; height: 100%; background: rgba(0,0,0,0.5);
            align-items: center; justify-content: center;
            z-index: 2000; backdrop-filter: blur(5px);
        }
        .modal.active { display: flex; }
        .modal-content {
            background: white; border-radius: 20px; padding: 30px;
            width: 90%; max-width: 500px; max-height: 85vh;
            overflow-y: auto; box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }
        .modal-content.lg { max-width: 600px; }
        .modal-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px; padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        .modal-header h3 {
            font-size: 1.3rem; font-weight: 700; color: #0f172a;
            display: flex; align-items: center; gap: 10px; margin: 0;
        }
        .modal-header h3 i { color: #8b5cf6; }
        .close-modal {
            font-size: 1.8rem; cursor: pointer; color: #94a3b8;
            transition: all 0.2s; background: none; border: none;
            padding: 0 8px; line-height: 1;
        }
        .close-modal:hover { color: #ef4444; }

        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block; margin-bottom: 6px; color: #475569;
            font-weight: 600; font-size: 0.9rem;
        }
        .form-group label .required { color: #ef4444; margin-left: 2px; }
        .form-group label i { margin-right: 6px; color: #8b5cf6; }
        .form-group input, .form-group select {
            width: 100%; padding: 10px 14px;
            border: 2px solid #e2e8f0; border-radius: 10px;
            font-size: 0.95rem; transition: all 0.2s;
            background: white; color: #1e293b;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none; border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139,92,246,0.1);
        }

        .checkbox-group {
            display: grid; grid-template-columns: 1fr; gap: 6px;
            margin-top: 6px; max-height: 200px; overflow-y: auto;
            padding: 10px; background: #f8fafc;
            border-radius: 10px; border: 1px solid #e2e8f0;
        }
        .checkbox-item {
            display: flex; align-items: center; gap: 8px;
            padding: 4px 8px; border-radius: 6px;
        }
        .checkbox-item:hover { background: white; }
        .checkbox-item input[type="checkbox"] { width: 16px; height: 16px; cursor: pointer; accent-color: #8b5cf6; }
        .checkbox-item label { margin: 0; cursor: pointer; font-size: 0.85rem; color: #1e293b; display: flex; align-items: center; gap: 6px; }
        .checkbox-item label .qual-code { color: #64748b; font-size: 0.75rem; }

        .qual-group-header {
            font-weight: 600; color: #475569; padding: 4px 10px;
            background: #f1f5f9; border-radius: 6px; margin-top: 4px;
            font-size: 0.85rem; display: flex; align-items: center; gap: 6px;
        }

        .modal-actions {
            display: flex; gap: 10px; margin-top: 20px;
            padding-top: 15px; border-top: 1px solid #e2e8f0;
        }
        .modal-actions button {
            flex: 1; padding: 10px; border: none;
            border-radius: 10px; font-weight: 600;
            font-size: 0.9rem; cursor: pointer; transition: all 0.2s;
        }
        .btn-primary { background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(139,92,246,0.3); }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(239,68,68,0.3); }
        .btn-secondary { background: #f1f5f9; color: #475569; border: 2px solid #e2e8f0; }
        .btn-secondary:hover { background: #e2e8f0; }

        .message {
            background: #dcfce7; color: #166534; padding: 14px 20px;
            border-radius: 12px; margin-bottom: 20px;
            border-left: 5px solid #166534;
            display: flex; align-items: flex-start; gap: 12px;
            font-size: 0.95rem; line-height: 1.5;
        }
        .message i.fa-check-circle { margin-top: 3px; flex-shrink: 0; }

        .error {
            background: #fee2e2; color: #991b1b; padding: 14px 20px;
            border-radius: 12px; margin-bottom: 20px;
            border-left: 5px solid #991b1b;
            display: flex; align-items: center; gap: 12px;
            font-size: 0.95rem;
        }

        /* TRAINER ACCOUNT MODAL */
        .trainer-account-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 999999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .trainer-account-modal-overlay.active { display: flex; }
        .trainer-account-modal-card {
            background: #ffffff;
            border-radius: 24px;
            width: 100%;
            max-width: 900px;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.35);
            position: relative;
            display: flex;
            flex-direction: column;
        }
        .trainer-account-modal-close {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #f1f5f9;
            border: none;
            font-size: 1.5rem;
            line-height: 1;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s ease;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .trainer-account-modal-close:hover {
            background: #fee2e2;
            color: #dc2626;
            transform: rotate(90deg);
        }
        .trainer-account-modal-body {
            display: grid;
            grid-template-columns: 260px 1fr;
            overflow: hidden;
            max-height: 90vh;
        }
        .trainer-account-modal-left {
            background: #f8fafc;
            padding: 30px 24px;
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            align-items: center;
            overflow-y: auto;
        }
        .trainer-account-avatar-wrapper {
            position: relative;
            width: 130px;
            height: 130px;
            margin-bottom: 16px;
        }
        .trainer-account-avatar {
            width: 130px;
            height: 130px;
            border-radius: 24px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(59, 130, 246, 0.25);
            border: 4px solid #ffffff;
        }
        .trainer-account-avatar img {
            width: 100%; height: 100%; object-fit: cover; display: block;
        }
        .trainer-account-initials {
            color: #ffffff; font-weight: 700; font-size: 2.6rem;
            letter-spacing: 1px; text-transform: uppercase; line-height: 1;
        }
        .trainer-account-camera-overlay {
            position: absolute; bottom: 8px; right: 8px;
            width: 40px; height: 40px; border-radius: 50%;
            background: #1e293b; color: #ffffff;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.95rem; cursor: pointer;
            border: 3px solid #ffffff;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            transition: all 0.2s ease; z-index: 5;
        }
        .trainer-account-camera-overlay:hover {
            background: #3b82f6; transform: scale(1.1);
        }
        .trainer-account-name {
            font-size: 1rem; font-weight: 700; color: #0f172a;
            margin: 0 0 4px; text-align: center; line-height: 1.3;
        }
        .trainer-account-role {
            font-size: 0.82rem; color: #64748b;
            margin: 0 0 24px;
            display: flex; align-items: center; justify-content: center;
            gap: 6px; font-weight: 500;
        }
        .trainer-account-tabs {
            width: 100%;
            display: flex; flex-direction: column; gap: 4px;
            margin-top: auto;
        }
        .trainer-account-tab {
            display: flex; align-items: center; gap: 10px;
            width: 100%; padding: 12px 16px;
            border-radius: 10px; background: transparent;
            border: none; font-family: 'Poppins', sans-serif;
            font-size: 0.88rem; font-weight: 500;
            color: #475569; cursor: pointer;
            text-align: left; transition: all 0.2s ease;
        }
        .trainer-account-tab i {
            width: 18px; font-size: 0.9rem;
            color: #94a3b8; transition: color 0.2s ease;
        }
        .trainer-account-tab:hover { background: #e2e8f0; color: #1e293b; }
        .trainer-account-tab:hover i { color: #64748b; }
        .trainer-account-tab.active {
            background: #1e293b; color: #ffffff;
            box-shadow: 0 4px 12px rgba(30, 41, 59, 0.2);
        }
        .trainer-account-tab.active i { color: #ffffff; }

        .trainer-account-modal-right {
            padding: 30px 32px;
            overflow-y: auto;
            max-height: 90vh;
        }
        .trainer-account-tab-content { display: none; }
        .trainer-account-tab-content.active { display: block; }
        .trainer-account-panel-title {
            font-size: 1.4rem; font-weight: 700;
            color: #0f172a; margin: 0 0 4px;
        }
        .trainer-account-panel-subtitle {
            font-size: 0.85rem; color: #64748b;
            margin: 0 0 24px;
        }
        .trainer-account-form-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 16px;
        }
        .trainer-account-form-group {
            display: flex; flex-direction: column;
        }
        .trainer-account-form-group.full-width {
            grid-column: 1 / -1;
        }
        .trainer-account-form-group label {
            font-size: 0.75rem; font-weight: 700;
            color: #334155; margin-bottom: 6px;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .trainer-account-form-group input,
        .trainer-account-form-group select {
            width: 100%; padding: 11px 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px; font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
            color: #1e293b; background: #f8fafc;
            transition: all 0.2s ease;
        }
        .trainer-account-form-group input:focus,
        .trainer-account-form-group select:focus {
            outline: none; border-color: #3b82f6;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.12);
        }
        .trainer-account-modal-footer {
            display: flex; justify-content: flex-end;
            margin-top: 24px; padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }
        .trainer-account-btn-primary {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 11px 28px; background: #3b82f6; color: #ffffff;
            border: none; border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem; font-weight: 600;
            cursor: pointer; transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        .trainer-account-btn-primary:hover:not(:disabled) {
            background: #2563eb; transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.4);
        }
        .trainer-account-btn-primary:disabled {
            opacity: 0.6; cursor: not-allowed;
        }
        .trainer-account-btn-danger {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 11px 28px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #ffffff; border: none; border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem; font-weight: 600;
            cursor: pointer; transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }
        .trainer-account-btn-danger:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(220, 38, 38, 0.4);
        }

        /* ✅ NEW: Reassign Section */
        .ta-reassign-section {
            margin-top: 22px;
            padding-top: 18px;
            border-top: 2px dashed #e2e8f0;
        }
        .ta-reassign-title {
            font-size: 0.85rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 14px;
            padding: 10px 14px;
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            border-left: 4px solid #3b82f6;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .ta-reassign-title i {
            color: #3b82f6;
            font-size: 0.95rem;
        }
        .ta-qual-checkbox-container {
            display: flex;
            flex-direction: column;
            gap: 4px;
            max-height: 280px;
            overflow-y: auto;
            padding: 12px;
            background: #f8fafc;
            border-radius: 12px;
            border: 1.5px solid #e2e8f0;
        }
        .ta-qual-checkbox-container::-webkit-scrollbar { width: 6px; }
        .ta-qual-checkbox-container::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 10px; }
        .ta-qual-checkbox-container::-webkit-scrollbar-thumb { background: #8b5cf6; border-radius: 10px; }
        .ta-qual-group-header {
            font-weight: 700;
            color: #475569;
            padding: 8px 14px;
            background: #e2e8f0;
            border-radius: 8px;
            margin-top: 8px;
            margin-bottom: 4px;
            font-size: 0.82rem;
            display: flex;
            align-items: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .ta-qual-group-header:first-child { margin-top: 0; }
        .ta-qual-group-header i { color: #64748b; font-size: 0.75rem; }
        .ta-qual-checkbox { width: 18px; height: 18px; cursor: pointer; accent-color: #8b5cf6; }

        .danger-zone-box {
            background: #fef2f2;
            border: 2px solid #fecaca;
            border-radius: 12px;
            padding: 20px;
            margin-top: 8px;
        }
        .danger-zone-box h4 {
            font-size: 1rem;
            font-weight: 700;
            color: #991b1b;
            margin: 0 0 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .danger-zone-box p {
            font-size: 0.85rem;
            color: #7f1d1d;
            margin: 0 0 16px;
            line-height: 1.5;
        }

        .security-notice {
            background: #eff6ff;
            border: 2px solid #bfdbfe;
            border-radius: 12px;
            padding: 22px 24px;
            margin-top: 8px;
            display: flex;
            gap: 16px;
            align-items: flex-start;
        }
        .security-notice-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: #3b82f6;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .security-notice-content h4 {
            font-size: 1rem;
            font-weight: 700;
            color: #1e3a8a;
            margin: 0 0 6px;
        }
        .security-notice-content p {
            font-size: 0.85rem;
            color: #1e40af;
            margin: 0 0 8px;
            line-height: 1.55;
        }
        .security-notice-content ul {
            margin: 8px 0 0 18px;
            padding: 0;
            font-size: 0.83rem;
            color: #1e40af;
            line-height: 1.6;
        }
        .security-notice-content ul li { margin-bottom: 4px; }

        .trainer-toast {
            position: fixed; top: 24px; right: 24px;
            padding: 14px 24px; border-radius: 12px;
            color: #ffffff; font-weight: 600;
            font-size: 0.9rem; z-index: 9999999;
            display: flex; align-items: center; gap: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        .trainer-toast.success { background: #10b981; }
        .trainer-toast.error { background: #ef4444; }

        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #e2e8f0; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 10px; }

        @media (max-width: 1200px) {
            .main-content { margin-left: 80px; width: calc(100% - 80px); }
        }
        @media (max-width: 768px) {
            .add-trainer-card { flex-direction: column; gap: 15px; }
            .header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .header-right { width: 100%; flex-wrap: wrap; }
            .main-content { margin-left: 0; width: 100%; padding: 15px; }
            .profile-badge { padding: 6px 12px 6px 6px; }
            .profile-badge .profile-name { font-size: 0.85rem; }
            .qualification-header-row { flex-direction: column; align-items: flex-start; gap: 10px; }
            .qual-right { width: 100%; justify-content: space-between; }
            .trainer-details-sm { grid-template-columns: 1fr; }
            .trainer-account-modal-body { grid-template-columns: 1fr; }
            .trainer-account-form-grid { grid-template-columns: 1fr; }
        }
    </style>

    <link rel="stylesheet" href="/tesda_compliance/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/tesda_compliance/assets/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content" id="mainContent">
        <div class="header">
            <div class="header-left">
                <h1><i class="fas fa-users-cog"></i> User Management</h1>
                <p>Manage trainers by department and qualification</p>
            </div>
            <div class="header-right">
                <div class="profile-badge">
                    <div class="avatar-circle">
                        <i class="fas fa-user"></i>
                    </div>
                    <span class="profile-name"><?= htmlspecialchars($user['full_name'] ?? $user['username']) ?></span>
                </div>
                <div class="date-badge">
                    <i class="far fa-calendar-alt"></i> <?= date('F j, Y') ?>
                </div>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="message">
                <i class="fas fa-check-circle"></i> 
                <div><?= $message ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div>
        <?php endif; ?>

        <div class="add-trainer-card">
            <h3><i class="fas fa-user-plus"></i> Add New Trainer</h3>
            <button class="btn-add" onclick="openModal('addUserModal')">
                <i class="fas fa-plus-circle"></i> Add Trainer
            </button>
        </div>

        <?php 
        $department_data = [
            'CSS' => ['name' => 'Computer Systems Servicing', 'icon' => 'fa-laptop', 'color' => '#10B981'],
            'ATS' => ['name' => 'Automotive Mechanics', 'icon' => 'fa-wrench', 'color' => '#F59E0B'],
            'HRS' => ['name' => 'Hotel and Restaurant Services', 'icon' => 'fa-utensils', 'color' => '#8B5CF6']
        ];
        
        foreach ($courses as $course):
            $dept_code = $course['course_code'];
            $dept_info = $department_data[$dept_code] ?? ['name' => $course['course_name'], 'icon' => 'fa-building', 'color' => '#6b7280'];
            $dept_qualifications = $qualifications_by_course[$course['id']] ?? [];
            if (empty($dept_qualifications)) continue;
        ?>
        
        <div class="department-section">
            <div class="department-header">
                <div class="dept-icon" style="background: <?= $dept_info['color'] ?>">
                    <i class="fas <?= $dept_info['icon'] ?>"></i>
                </div>
                <h3><?= htmlspecialchars($dept_info['name']) ?> <span class="dept-code">(<?= htmlspecialchars($dept_code) ?>)</span></h3>
            </div>
            
            <div class="qualification-list">
                <?php foreach ($dept_qualifications as $qualification): 
                    $qual_trainers = array_filter($users, function($u) use ($trainer_qualifications, $qualification) {
                        if (!in_array($u['role'], ['trainer', 'coordinator'])) return false;
                        if (!isset($trainer_qualifications[$u['id']])) return false;
                        $qual_ids = $trainer_qualifications[$u['id']]['ids'] ?? [];
                        return in_array($qualification['id'], $qual_ids);
                    });
                ?>
                
                <div class="qualification-item">
                    <div class="qualification-header-row" onclick="toggleQualification(this)">
                        <div class="qual-left">
                            <i class="fas fa-certificate qual-icon"></i>
                            <h4><?= htmlspecialchars($qualification['qualification_name']) ?></h4>
                        </div>
                        <div class="qual-right">
                            <span class="trainer-count-badge">
                                <i class="fas fa-users"></i> <?= count($qual_trainers) ?> Trainer<?= count($qual_trainers) != 1 ? 's' : '' ?>
                            </span>
                            <button class="btn-assign-sm" onclick="event.stopPropagation(); openAddTrainerModalWithQualification(<?= $qualification['id'] ?>)">
                                <i class="fas fa-user-plus"></i> Assign
                            </button>
                            <i class="fas fa-chevron-down accordion-icon"></i>
                        </div>
                    </div>
                    
                    <div class="trainer-list">
                        <?php if (empty($qual_trainers)): ?>
                            <div class="empty-trainers">
                                <i class="fas fa-user-slash"></i>
                                <p>No trainers assigned to this qualification</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($qual_trainers as $trainer): 
                                $trainer_quals = $trainer_qualifications[$trainer['id']]['data'] ?? [];
                                $initials = '';
                                $name_parts = preg_split('/\s+/', trim($trainer['full_name'] ?? ''));
                                if (count($name_parts) >= 2) {
                                    $initials = strtoupper(mb_substr($name_parts[0], 0, 1) . mb_substr($name_parts[count($name_parts) - 1], 0, 1));
                                } else {
                                    $initials = strtoupper(mb_substr($trainer['full_name'] ?? 'T', 0, 2));
                                }
                                $is_locked = !empty($trainer['is_locked']) && $trainer['is_locked'] == 1;
                                $has_profile_pic = !empty($trainer['profile_picture']);
                                $profile_pic_url = '';
                                if ($has_profile_pic) {
                                    $physical = __DIR__ . '/' . ltrim($trainer['profile_picture'], '/');
                                    $v = file_exists($physical) ? filemtime($physical) : time();
                                    $profile_pic_url = '/tesda_compliance/' . ltrim($trainer['profile_picture'], '/') . '?v=' . $v;
                                }
                                
                                $trainer_json = json_encode([
                                    'id' => $trainer['id'],
                                    'username' => $trainer['username'] ?? '',
                                    'full_name' => $trainer['full_name'] ?? '',
                                    'email' => $trainer['email'] ?? '',
                                    'contact_number' => $trainer['contact_number'] ?? '',
                                    'role' => $trainer['role'] ?? 'trainer',
                                    'department_id' => $trainer['department_id'] ?? null,
                                    'profile_pic_url' => $profile_pic_url,
                                    'initials' => $initials,
                                    'is_locked' => $is_locked,
                                    'qualifications' => $trainer_quals
                                ]);
                            ?>
                            <div class="trainer-item" 
                                 style="border-left-color: <?= $dept_info['color'] ?>"
                                 onclick='openTrainerAccountModal(<?= $trainer_json ?>)'
                                 title="Click to view full profile">
                                <div class="trainer-item-top">
                                    <div class="trainer-item-left">
                                        <div class="trainer-avatar-sm">
                                            <?php if ($has_profile_pic): ?>
                                                <img src="<?= htmlspecialchars($profile_pic_url) ?>" 
                                                     alt="<?= htmlspecialchars($trainer['full_name']) ?>"
                                                     onerror="this.style.display='none'; this.parentElement.insertAdjacentHTML('afterbegin', '<?= htmlspecialchars($initials) ?>');">
                                            <?php else: ?>
                                                <?= htmlspecialchars($initials) ?>
                                            <?php endif; ?>
                                            <span class="status-dot <?= $is_locked ? 'offline' : 'online' ?>"></span>
                                        </div>
                                        <div class="trainer-info-sm">
                                            <span class="name"><?= htmlspecialchars($trainer['full_name']) ?></span>
                                            <?php if ($is_locked): ?>
                                                <span style="font-size:0.7rem; color:#ef4444; font-weight:600;">
                                                    <i class="fas fa-lock"></i> LOCKED
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="trainer-details-sm">
                                    <div class="detail-item-sm">
                                        <span class="icon-box-sm blue"><i class="fas fa-envelope"></i></span>
                                        <?= htmlspecialchars($trainer['email']) ?>
                                    </div>
                                    <div class="detail-item-sm">
                                        <span class="icon-box-sm green"><i class="fas fa-phone"></i></span>
                                        <?= !empty($trainer['contact_number']) ? htmlspecialchars($trainer['contact_number']) : 'N/A' ?>
                                    </div>
                                    <div class="trainer-open-hint">
                                        <i class="fas fa-hand-pointer"></i> Click to open
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Add Trainer Modal -->
    <div id="addUserModal" class="modal">
        <div class="modal-content lg">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus" style="color: #10b981;"></i> Add New Trainer</h3>
                <button class="close-modal" onclick="closeModal('addUserModal')">&times;</button>
            </div>
            <form method="POST" id="addUserForm">
                <input type="hidden" name="add_trainer" value="1">
                
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Username <span class="required">*</span></label>
                    <input type="text" name="username" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-user-tag"></i> Full Name <span class="required">*</span></label>
                    <input type="text" name="full_name" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Email <span class="required">*</span></label>
                    <input type="email" name="email" required>
                </div>
                <div class="form-group">
                    <label><i class="fas fa-phone"></i> Contact Number</label>
                    <input type="text" name="contact_number" placeholder="09123456789">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> Password <span class="required">*</span></label>
                    <input type="password" name="password" required minlength="6" placeholder="Set trainer's password">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-certificate"></i> Assign Qualifications</label>
                    <div class="checkbox-group" id="add_qualifications_container">
                        <?php 
                        $current_course = '';
                        foreach ($all_qualifications as $qual): 
                            if ($qual['course_code'] != $current_course) {
                                $current_course = $qual['course_code'];
                                $course_name = $qual['course_name'];
                                echo '<div class="qual-group-header"><i class="fas fa-building"></i> ' . htmlspecialchars($course_name) . '</div>';
                            }
                        ?>
                        <div class="checkbox-item">
                            <input type="checkbox" name="qualifications[]" value="<?= (int)$qual['id'] ?>" id="add_qual_<?= $qual['id'] ?>" class="add-qual-checkbox">
                            <label for="add_qual_<?= $qual['id'] ?>">
                                <i class="fas fa-certificate" style="color: #8b5cf6;"></i>
                                <?= htmlspecialchars($qual['qualification_name']) ?>
                                <span class="qual-code">(<?= htmlspecialchars($qual['qualification_code']) ?>)</span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group">
                    <div class="checkbox-item" style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 10px;">
                        <input type="checkbox" name="assign_all_trainees" id="assign_all_add" value="yes" checked>
                        <label for="assign_all_add" style="font-weight: 500; color: #065f46;">
                            <i class="fas fa-user-graduate"></i> Auto-assign trainees from selected qualifications
                        </label>
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn-primary">Add Trainer</button>
                    <button type="button" class="btn-secondary" onclick="closeModal('addUserModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Unlock Account Modal -->
    <div id="unlockUserModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-unlock" style="color: #10b981;"></i> Unlock Account</h3>
                <button class="close-modal" onclick="closeModal('unlockUserModal')">&times;</button>
            </div>
            <form method="POST" id="unlockUserForm">
                <input type="hidden" name="unlock_user" value="1">
                <input type="hidden" name="unlock_user_id" id="unlock_id">
                <p style="font-size: 1rem; margin-bottom: 10px;">Are you sure you want to unlock: <strong id="unlock_name"></strong>?</p>
                <p style="color: #065f46; font-size: 0.85rem; background: #f0fdf4; padding: 10px; border-radius: 8px;">
                    <i class="fas fa-info-circle"></i> The user will be able to login again. Their failed login attempts will be reset to 0.
                </p>
                <div class="modal-actions">
                    <button type="submit" class="btn-primary" style="background: linear-gradient(135deg, #10b981, #059669);">Unlock Account</button>
                    <button type="button" class="btn-secondary" onclick="closeModal('unlockUserModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- TRAINER/COORDINATOR ACCOUNT MODAL (WITH REASSIGNMENT) -->
    <!-- ========================================== -->
    <div class="trainer-account-modal-overlay" id="trainerAccountModal">
        <div class="trainer-account-modal-card">
            <button class="trainer-account-modal-close" onclick="closeTrainerAccountModal()" aria-label="Close">&times;</button>

            <div class="trainer-account-modal-body">
                <!-- LEFT COLUMN -->
                <div class="trainer-account-modal-left">
                    <div class="trainer-account-avatar-wrapper">
                        <div class="trainer-account-avatar" id="ta_account_avatar_preview"></div>
                        <label for="ta_account_picture_input" class="trainer-account-camera-overlay" title="Change picture">
                            <i class="fas fa-camera"></i>
                        </label>
                        <input type="file" id="ta_account_picture_input" accept="image/*" style="display: none;" onchange="taPreviewAccountPicture(this)">
                    </div>

                    <h3 class="trainer-account-name" id="ta_account_name">Loading...</h3>
                    <p class="trainer-account-role">
                        <span style="display: inline-block; width: 9px; height: 9px; border-radius: 50%; background: #10b981;"></span>
                        <span id="ta_role_display">Trainer</span>
                    </p>

                    <div class="trainer-account-tabs">
                        <button type="button" class="trainer-account-tab active" onclick="taSwitchTab(event, 'details')" data-tab="details">
                            <i class="fas fa-user"></i> Account Details
                        </button>
                        <button type="button" class="trainer-account-tab" onclick="taSwitchTab(event, 'security')" data-tab="security">
                            <i class="fas fa-shield-alt"></i> Security
                        </button>
                        <button type="button" class="trainer-account-tab" onclick="taSwitchTab(event, 'danger')" data-tab="danger">
                            <i class="fas fa-exclamation-triangle"></i> Danger Zone
                        </button>
                    </div>
                </div>

                <!-- RIGHT COLUMN -->
                <div class="trainer-account-modal-right">
                    <!-- TAB 1: Account Details -->
                    <div class="trainer-account-tab-content active" data-tab-content="details">
                        <h3 class="trainer-account-panel-title">Account Details</h3>
                        <p class="trainer-account-panel-subtitle">Update personal info and reassign department / qualifications</p>

                        <form method="POST" enctype="multipart/form-data" id="ta_accountUpdateForm">
                            <input type="hidden" name="update_trainer_account" value="1">
                            <input type="hidden" name="trainer_id" id="ta_trainer_id">

                            <div class="trainer-account-form-grid">
                                <div class="trainer-account-form-group full-width">
                                    <label>Full Name</label>
                                    <input type="text" name="full_name" id="ta_full_name" required>
                                </div>
                                <div class="trainer-account-form-group">
                                    <label>Username</label>
                                    <input type="text" id="ta_username" disabled style="background:#f1f5f9; cursor: not-allowed;">
                                </div>
                                <div class="trainer-account-form-group">
                                    <label>Role</label>
                                    <input type="text" id="ta_role" value="Trainer" disabled style="background:#f1f5f9; cursor: not-allowed;">
                                </div>
                                <div class="trainer-account-form-group full-width">
                                    <label>Email Address</label>
                                    <input type="email" name="email" id="ta_email" required>
                                </div>
                                <div class="trainer-account-form-group full-width">
                                    <label>Contact Number</label>
                                    <input type="text" name="contact_number" id="ta_contact_number" placeholder="09123456789">
                                </div>
                            </div>

                            <!-- ✅ Assign Department -->
                            <div class="ta-reassign-section">
                                <div class="ta-reassign-title">
                                    <i class="fas fa-building"></i> Assign Department
                                </div>
                                <div class="trainer-account-form-group full-width">
                                    <select name="department_id" id="ta_department_id">
                                        <option value="">— No Department Assigned —</option>
                                        <?php foreach ($courses as $course): ?>
                                            <option value="<?= (int)$course['id'] ?>">
                                                <?= htmlspecialchars($course['course_name']) ?> (<?= htmlspecialchars($course['course_code']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- ✅ Assign Qualifications (grouped by department) -->
                            <div class="ta-reassign-section">
                                <div class="ta-reassign-title">
                                    <i class="fas fa-certificate"></i> Assign Qualifications
                                </div>
                                <div class="ta-qual-checkbox-container">
                                    <?php 
                                    $ta_current_course = '';
                                    foreach ($all_qualifications as $qual): 
                                        if ($qual['course_code'] != $ta_current_course) {
                                            $ta_current_course = $qual['course_code'];
                                            echo '<div class="ta-qual-group-header"><i class="fas fa-building"></i> ' . htmlspecialchars($qual['course_name']) . '</div>';
                                        }
                                    ?>
                                    <div class="checkbox-item">
                                        <input type="checkbox"
                                               name="qualifications[]"
                                               value="<?= (int)$qual['id'] ?>"
                                               id="ta_qual_<?= $qual['id'] ?>"
                                               class="ta-qual-checkbox">
                                        <label for="ta_qual_<?= $qual['id'] ?>">
                                            <i class="fas fa-certificate" style="color:#8b5cf6;"></i>
                                            <?= htmlspecialchars($qual['qualification_name']) ?>
                                            <span style="color:#64748b; font-size:0.75rem; font-weight:600;">(<?= htmlspecialchars($qual['qualification_code']) ?>)</span>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="trainer-account-modal-footer">
                                <button type="submit" class="trainer-account-btn-primary">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- TAB 2: Security -->
                    <div class="trainer-account-tab-content" data-tab-content="security">
                        <h3 class="trainer-account-panel-title">Security</h3>
                        <p class="trainer-account-panel-subtitle">Password is managed by the trainer</p>

                        <div class="security-notice">
                            <div class="security-notice-icon">
                                <i class="fas fa-shield-alt"></i>
                            </div>
                            <div class="security-notice-content">
                                <h4>Trainer's Password</h4>
                                <p>
                                    As a coordinator, you are <strong>not permitted</strong> to view or change a trainer's password.
                                    This is to protect the trainer's account and ensure accountability.
                                </p>
                                <p style="margin-top:10px;">If a trainer needs to reset their password, they can do so themselves:</p>
                                <ul>
                                    <li>On the login page, click <strong>"Forgot password?"</strong></li>
                                    <li>Choose <strong>Email</strong> or <strong>SMS</strong> to receive a 6-digit reset code</li>
                                    <li>Enter the code and set a new password</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 3: Danger Zone -->
                    <div class="trainer-account-tab-content" data-tab-content="danger">
                        <h3 class="trainer-account-panel-title">Danger Zone</h3>
                        <p class="trainer-account-panel-subtitle">Irreversible actions</p>

                        <div class="danger-zone-box">
                            <h4><i class="fas fa-exclamation-triangle"></i> Deactivate Trainer</h4>
                            <p>
                                Once deactivated, this trainer will be removed from the system. All qualification assignments and trainee assignments will also be removed. This action cannot be undone.
                            </p>
                            <form method="POST" id="ta_deactivateForm">
                                <input type="hidden" name="deactivate_trainer" value="1">
                                <input type="hidden" name="trainer_id" id="ta_trainer_id_deact">
                                <button type="submit" class="trainer-account-btn-danger" onclick="return confirm('Are you sure you want to deactivate this trainer? This action cannot be undone.');">
                                    <i class="fas fa-user-slash"></i> Deactivate Trainer
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="/tesda_compliance/assets/js/jquery-3.6.0.min.js"></script>
    <script>
        function toggleQualification(headerRow) {
            const item = headerRow.closest('.qualification-item');
            const trainerList = item.querySelector('.trainer-list');
            const icon = headerRow.querySelector('.accordion-icon');
            trainerList.classList.toggle('open');
            icon.classList.toggle('open');
        }
        
        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        function openAddTrainerModalWithQualification(qualificationId) {
            openModal('addUserModal');
            const qualCheckbox = document.querySelector('#add_qualifications_container input[value="' + qualificationId + '"]');
            if (qualCheckbox) qualCheckbox.checked = true;
        }

        function unlockAccount(id, name) {
            document.getElementById('unlock_id').value = id;
            document.getElementById('unlock_name').textContent = name;
            openModal('unlockUserModal');
        }

        // ============================================================
        // TRAINER/COORDINATOR ACCOUNT MODAL
        // ============================================================
        let currentTrainerData = null;

        function openTrainerAccountModal(trainerData) {
            currentTrainerData = trainerData;

            // Fill left column
            document.getElementById('ta_account_name').textContent = trainerData.full_name || 'User';

            const roleDisplay = document.getElementById('ta_role_display');
            if (roleDisplay) {
                roleDisplay.textContent = trainerData.role
                    ? trainerData.role.charAt(0).toUpperCase() + trainerData.role.slice(1)
                    : 'Trainer';
            }

            const roleField = document.getElementById('ta_role');
            if (roleField) {
                roleField.value = trainerData.role
                    ? trainerData.role.charAt(0).toUpperCase() + trainerData.role.slice(1)
                    : 'Trainer';
            }

            const avatarPreview = document.getElementById('ta_account_avatar_preview');
            if (trainerData.profile_pic_url && trainerData.profile_pic_url.length > 0) {
                let cleanUrl = trainerData.profile_pic_url.split('?')[0];
                cleanUrl = cleanUrl + '?v=' + Date.now();
                avatarPreview.innerHTML = '<img src="' + cleanUrl + '" alt="' + trainerData.full_name + '" onerror="this.style.display=\'none\'; this.parentElement.innerHTML=\'<span class=\\\'trainer-account-initials\\\'>' + (trainerData.initials || 'U') + '</span>\';">';
            } else {
                avatarPreview.innerHTML = '<span class="trainer-account-initials">' + (trainerData.initials || 'U') + '</span>';
            }

            // Fill details form
            document.getElementById('ta_trainer_id').value = trainerData.id;
            document.getElementById('ta_trainer_id_deact').value = trainerData.id;
            document.getElementById('ta_full_name').value = trainerData.full_name || '';
            document.getElementById('ta_username').value = trainerData.username || '';
            document.getElementById('ta_email').value = trainerData.email || '';
            document.getElementById('ta_contact_number').value = trainerData.contact_number || '';

            // ✅ Set department dropdown
            const deptSelect = document.getElementById('ta_department_id');
            if (deptSelect) {
                deptSelect.value = trainerData.department_id || '';
            }

            // ✅ Set qualification checkboxes
            const qualCheckboxes = document.querySelectorAll('.ta-qual-checkbox');
            const trainerQualIds = (trainerData.qualifications || []).map(function(q) {
                return parseInt(q.id);
            });
            qualCheckboxes.forEach(function(cb) {
                cb.checked = trainerQualIds.indexOf(parseInt(cb.value)) !== -1;
            });

            // Reset file input
            const fileInput = document.getElementById('ta_account_picture_input');
            fileInput.value = '';

            // Reset tabs to Details
            taSwitchTab(null, 'details');
            document.querySelectorAll('.trainer-account-tab').forEach(function(t) {
                t.classList.remove('active');
            });
            document.querySelector('.trainer-account-tab[data-tab="details"]').classList.add('active');

            // Open modal
            document.getElementById('trainerAccountModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeTrainerAccountModal() {
            document.getElementById('trainerAccountModal').classList.remove('active');
            document.body.style.overflow = '';
            currentTrainerData = null;
        }

        function taSwitchTab(e, tabName) {
            if (e) { e.preventDefault(); e.stopPropagation(); }

            document.querySelectorAll('.trainer-account-tab').forEach(t => t.classList.remove('active'));
            if (e && e.currentTarget) {
                e.currentTarget.classList.add('active');
            } else {
                const target = document.querySelector('.trainer-account-tab[data-tab="' + tabName + '"]');
                if (target) target.classList.add('active');
            }

            document.querySelectorAll('.trainer-account-tab-content').forEach(c => c.classList.remove('active'));
            const content = document.querySelector('.trainer-account-tab-content[data-tab-content="' + tabName + '"]');
            if (content) content.classList.add('active');
        }

        function taPreviewAccountPicture(input) {
            if (!input.files || !input.files[0]) return;
            const file = input.files[0];
            const allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            if (allowed.indexOf(file.type) === -1) {
                taShowToast('Invalid file type. JPG, PNG, GIF, WEBP only.', 'error');
                input.value = '';
                return;
            }
            if (file.size > 2 * 1024 * 1024) {
                taShowToast('File too large. Max 2MB.', 'error');
                input.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById('ta_account_avatar_preview');
                preview.innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
            };
            reader.readAsDataURL(file);
        }

        function taShowToast(message, type) {
            const existing = document.querySelector('.trainer-toast');
            if (existing) existing.remove();
            const toast = document.createElement('div');
            toast.className = 'trainer-toast ' + (type || 'success');
            toast.innerHTML = '<i class="fas ' + (type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle') + '"></i> ' + message;
            document.body.appendChild(toast);
            setTimeout(() => { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 4000);
        }

        document.addEventListener('DOMContentLoaded', function() {
            const updateForm = document.getElementById('ta_accountUpdateForm');
            if (updateForm) {
                updateForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const btn = updateForm.querySelector('.trainer-account-btn-primary');
                    const originalText = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

                    const formData = new FormData(updateForm);
                    const fileInput = document.getElementById('ta_account_picture_input');
                    if (fileInput && fileInput.files && fileInput.files[0]) {
                        formData.append('profile_picture_file', fileInput.files[0]);
                    }

                    fetch('users.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(r => r.text())
                    .then(text => {
                        taShowToast('Account updated successfully!', 'success');
                        setTimeout(() => window.location.reload(), 1200);
                    })
                    .catch(err => {
                        taShowToast('Server error. Please try again.', 'error');
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    });
                });
            }

            const deactivateForm = document.getElementById('ta_deactivateForm');
            if (deactivateForm) {
                deactivateForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    taShowToast('Deactivating trainer...', 'success');
                    setTimeout(() => deactivateForm.submit(), 300);
                });
            }
        });

        document.addEventListener('click', function(e) {
            const modal = document.getElementById('trainerAccountModal');
            if (modal && e.target === modal) closeTrainerAccountModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b') {
                e.preventDefault();
                const btn = document.getElementById('sidebar-toggle-btn');
                if (btn) btn.click();
            }
            if (e.key === 'Escape') {
                closeTrainerAccountModal();
                document.querySelectorAll('.modal.active').forEach(m => m.classList.remove('active'));
            }
        });

        window.onclick = function(e) {
            if (e.target.classList.contains('modal')) e.target.classList.remove('active');
        };
    </script>
<script src="/tesda_compliance/assets/js/seamless-nav.js"></script>
<script data-no-instant>// InstantClick.init(); // DISABLED</script>
<script src="/tesda_compliance/assets/js/jquery.min.js"></script>
<script src="/tesda_compliance/assets/js/bootstrap.bundle.min.js"></script>
<script src="/tesda_compliance/assets/js/app.js"></script>
</body>
</html>