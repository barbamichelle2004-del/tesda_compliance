<?php
// manage_trainee.php - View and Edit Trainee Only
require_once 'config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn()) {
    redirect('index.php');
}

$user = getCurrentUser();
$db = $pdo;

$user_role = $_SESSION['role'] ?? '';
$user_department = $_SESSION['department'] ?? '';

$error = '';
$edit_mode = false;
$view_mode = false;
$trainee_data = null;

// ==========================================
// PUSHER HELPER - Real-time updates
// ==========================================
require_once __DIR__ . '/helpers/helpers/PusherHelper.php';

// ==========================================
// HANDLE EDIT TRAINEE
// ==========================================
if (isset($_POST['action']) && $_POST['action'] == 'edit') {
    try {
        $id = $_POST['id'];
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $middle_initial = trim($_POST['middle_initial'] ?? '');
        $suffix = trim($_POST['suffix'] ?? '');
        $section = $_POST['section'] ?? '';
        $email = trim($_POST['email'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $course_id = $_POST['course_id'] ?? 0;
        $sex = $_POST['sex'] ?? '';
        $qualification_id = !empty($_POST['qualification_id']) ? $_POST['qualification_id'] : null;
        
        if (empty($first_name) || empty($last_name)) {
            throw new Exception("First name and last name are required.");
        }
        if (empty($section)) {
            throw new Exception("Please select a section.");
        }
        if (empty($sex)) {
            throw new Exception("Please select sex.");
        }
        if (empty($course_id)) {
            throw new Exception("Please select a department.");
        }
        
        $dept_stmt = $pdo->prepare("SELECT course_code FROM courses WHERE id = ?");
        $dept_stmt->execute([$course_id]);
        $department = $dept_stmt->fetchColumn();
        
        if (!$department) {
            throw new Exception("Invalid department selected");
        }
        
        $stmt = $pdo->prepare("
            UPDATE trainees 
            SET first_name=?, last_name=?, middle_initial=?, suffix=?, section=?, email=?, contact=?, department=?, sex=?, qualification_id=? 
            WHERE id=?
        ");
        
        $stmt->execute([
            $first_name, $last_name, $middle_initial, $suffix,
            $section, $email, $contact,
            $department, $sex, $qualification_id,
            $id
        ]);
        
        $payload = [
            'trainee_id' => $id,
            'trainee_name' => $first_name . ' ' . $last_name,
            'department' => $department,
            'qualification_id' => $qualification_id,
            'section' => $section
        ];
        triggerPusherUpdate('trainee_updated', $payload, 'compliance-channel');
        
        $_SESSION['success_message'] = "Trainee updated successfully!";
        header('Location: trainees.php');
        exit;
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    } catch(PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// ==========================================
// HANDLE MARK AS COMPLETED
// ==========================================
if (isset($_POST['action']) && $_POST['action'] == 'mark_completed') {
    try {
        $id = (int)$_POST['id'];

        if ($id <= 0) {
            throw new Exception("Invalid trainee ID.");
        }

        $check = $pdo->prepare("
            SELECT id, first_name, last_name, status, qualification_id, department
            FROM trainees
            WHERE id = ? AND deleted_at IS NULL
        ");
        $check->execute([$id]);
        $trainee = $check->fetch(PDO::FETCH_ASSOC);

        if (!$trainee) {
            throw new Exception("Trainee not found.");
        }

        if ($trainee['status'] === 'Completed') {
            throw new Exception("This trainee is already marked as Completed.");
        }

        $stmt = $pdo->prepare("UPDATE trainees SET status = 'Completed' WHERE id = ?");
        $stmt->execute([$id]);

        try {
            $payload = [
                'trainee_id' => $id,
                'trainee_name' => $trainee['first_name'] . ' ' . $trainee['last_name'],
                'department' => $trainee['department'],
                'qualification_id' => $trainee['qualification_id'],
                'new_status' => 'Completed',
                'timestamp' => date('Y-m-d H:i:s')
            ];
            triggerPusherUpdate('trainee_completed', $payload, 'compliance-channel');
        } catch (Exception $e) {
            error_log("Pusher error (mark_completed): " . $e->getMessage());
        }

        $_SESSION['success_message'] = "Trainee marked as Completed and moved to Archive.";
        header('Location: manage_trainee.php?id=' . $id);
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

// ==========================================
// GET TRAINEE DATA FOR VIEW/EDIT
// ==========================================
if (isset($_GET['id']) && !empty($_GET['id'])) {
    $trainee_id = (int)$_GET['id'];
    
    $stmt = $pdo->prepare("
        SELECT t.*, 
               c.course_name, c.course_code, c.color as department_color,
               q.qualification_name, q.qualification_code
        FROM trainees t
        LEFT JOIN courses c ON t.department = c.course_code
        LEFT JOIN qualifications q ON t.qualification_id = q.id
        WHERE t.id = ?
    ");
    $stmt->execute([$trainee_id]);
    $trainee_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($trainee_data) {
        if (isset($_GET['mode']) && $_GET['mode'] == 'edit') {
            $edit_mode = true;
        } else {
            $view_mode = true;
        }
    } else {
        $error = "Trainee not found.";
    }
}

// ==========================================
// AJAX: Get Qualifications for Department
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_qualifications') {
    header('Content-Type: application/json');
    
    $course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
    
    if ($course_id > 0) {
        $stmt = $pdo->prepare("
            SELECT id, qualification_name, qualification_code 
            FROM qualifications 
            WHERE course_id = ?
            ORDER BY qualification_name
        ");
        $stmt->execute([$course_id]);
        $qualifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $qualifications]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid course ID']);
    }
    exit;
}

// ==========================================
// GET ALL DATA FOR PAGE LOAD
// ==========================================
$courses = $pdo->query("SELECT * FROM courses ORDER BY course_code")->fetchAll();
$qualifications = $pdo->query("SELECT * FROM qualifications ORDER BY qualification_name")->fetchAll();

$success_message = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : '';
$error_message = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

$page_title = 'Manage Trainee';
$page_subtitle = 'View or edit trainee details';

if ($view_mode && $trainee_data) {
    $page_title = 'Trainee Profile';
    $page_subtitle = 'Complete trainee profile and information';
} elseif ($edit_mode && $trainee_data) {
    $page_title = 'Edit Trainee';
    $page_subtitle = 'Update trainee information';
} else {
    header('Location: trainees.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - TESDA Compliance</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
    <link rel="stylesheet" href="/tesda_compliance/assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="/tesda_compliance/assets/css/bootstrap.min.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: #f0f2f5;
            min-width: 1200px;
            overflow-x: auto;
            margin: 0;
            transition: all 0.3s ease;
        }
        
        body.sidebar-collapsed {
            min-width: 992px;
        }

        .main-content {
            margin-left: 280px;
            padding: 30px;
            width: calc(100% - 280px);
            min-width: 920px;
            transition: margin-left 0.3s ease, width 0.3s ease;
        }

        body.sidebar-collapsed .main-content {
            margin-left: 80px;
            width: calc(100% - 80px);
        }

        .header {
            background: white;
            border-radius: 24px;
            padding: 25px 35px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            border: 1px solid rgba(255,255,255,0.2);
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
        }

        .header-left h1 {
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, #1e293b, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
        }

        .header-left h1 i {
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 1.8rem;
        }

        .header-left p {
            color: #64748b;
            font-size: 1rem;
            margin-top: 4px;
        }

        .role-badge {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            padding: 12px 28px;
            border-radius: 50px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border: 1px solid #e2e8f0;
        }

        .role-badge i {
            font-size: 1.4rem;
            color: #3b82f6;
        }

        .role-badge .role-name {
            font-weight: 600;
            font-size: 1.1rem;
            color: #1e293b;
            letter-spacing: 0.3px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
        }

        .btn-back {
            background: #f1f5f9;
            color: #475569;
            border: 2px solid #e2e8f0;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .btn-back:hover {
            background: #e2e8f0;
            transform: translateY(-2px);
        }

        /* ============================================================ */
        /* MARK AS COMPLETED BUTTON                                      */
        /* ============================================================ */
        .btn-complete {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
            font-family: 'Poppins', sans-serif;
        }

        .btn-complete:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4);
            background: linear-gradient(135deg, #059669, #047857);
        }

        .message {
            background: #dcfce7;
            color: #166534;
            padding: 16px 24px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 5px solid #166534;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1rem;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 16px 24px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 5px solid #991b1b;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 1rem;
        }

        .form-card {
            background: white;
            border-radius: 20px;
            padding: 35px 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
            max-width: 800px;
            margin: 0 auto;
        }

        .form-card .card-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-card .card-title i {
            color: #8b5cf6;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #475569;
            font-weight: 600;
            font-size: 0.85rem;
            letter-spacing: 0.3px;
        }

        .form-group label .required {
            color: #ef4444;
            margin-left: 2px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 11px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.2s;
            background: white;
            color: #1e293b;
            height: 44px;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139,92,246,0.1);
        }

        .form-group input:disabled,
        .form-group select:disabled {
            background: #f1f5f9;
            cursor: not-allowed;
        }

        .form-group .help-text {
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 4px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .form-row-3 {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 16px;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }

        .btn-save {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
            border: none;
            padding: 14px 32px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 5px 15px rgba(139,92,246,0.3);
            flex: 1;
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(139,92,246,0.4);
        }

        .btn-save:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none !important;
        }

        .btn-cancel {
            background: #f1f5f9;
            color: #475569;
            border: 2px solid #e2e8f0;
            padding: 14px 32px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .btn-cancel:hover {
            background: #e2e8f0;
            transform: translateY(-2px);
        }

        .view-section {
            margin-bottom: 20px;
        }

        .view-section:last-child {
            margin-bottom: 0;
        }

        .view-section .section-label {
            font-size: 0.75rem;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .view-section .section-value {
            font-size: 1.05rem;
            color: #0f172a;
            font-weight: 500;
            padding: 8px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .view-section .section-value:last-child {
            border-bottom: none;
        }

        .view-avatar {
            width: 120px;
            height: 120px;
            border-radius: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: white;
            font-weight: 700;
            margin-bottom: 20px;
            box-shadow: 0 10px 20px rgba(0,0,0,0.15);
        }

        .view-badge {
            display: inline-block;
            padding: 4px 16px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-right: 8px;
        }

        .view-badge.active {
            background: #d1fae5;
            color: #065f46;
        }

        .view-badge.pending {
            background: #fef3c7;
            color: #92400e;
        }

        .view-badge.inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .dept-hrs { background: #8b5cf6; }
        .dept-css { background: #10b981; }
        .dept-ats { background: #f59e0b; }

        /* ============================================================ */
        /* MARK AS COMPLETED MODAL                                       */
        /* ============================================================ */
        .complete-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 99999;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: completeFadeIn 0.2s ease-out;
        }

        .complete-modal-overlay.active {
            display: flex;
        }

        @keyframes completeFadeIn {
            from { opacity: 0; }
            to   { opacity: 1; }
        }

        @keyframes completePopIn {
            from { opacity: 0; transform: translateY(12px) scale(0.96); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        .complete-modal-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 32px 28px 24px;
            width: 90%;
            max-width: 440px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.25);
            text-align: center;
            animation: completePopIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }

        .complete-modal-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.7rem;
            color: #059669;
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.2);
        }

        .complete-modal-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 8px;
        }

        .complete-modal-text {
            font-size: 0.9rem;
            color: #64748b;
            line-height: 1.5;
            margin: 0 0 12px;
        }

        .complete-modal-text strong {
            color: #1e293b;
            font-weight: 700;
        }

        .complete-modal-warning {
            background: #f0fdf4;
            border-left: 4px solid #10b981;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.8rem;
            color: #065f46;
            text-align: left;
            margin: 0 0 24px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        .complete-modal-warning i {
            color: #10b981;
            font-size: 1rem;
            margin-top: 1px;
            flex-shrink: 0;
        }

        .complete-modal-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .complete-btn {
            flex: 1;
            padding: 11px 18px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-family: 'Poppins', sans-serif;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .complete-btn-cancel {
            background: #f1f5f9;
            color: #475569;
            border: 2px solid #e2e8f0;
        }

        .complete-btn-cancel:hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        .complete-btn-confirm {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #ffffff;
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.3);
        }

        .complete-btn-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(16, 185, 129, 0.4);
        }

        .complete-btn-confirm:active,
        .complete-btn-cancel:active {
            transform: translateY(0);
        }

        @media (max-width: 1200px) {
            body { min-width: 992px; }
            .main-content { margin-left: 80px; width: calc(100% - 80px); }
            .form-row { grid-template-columns: 1fr; }
            .form-row-3 { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; width: 100%; padding: 15px; }
            .header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .form-card { padding: 20px; }
            .form-row { grid-template-columns: 1fr; }
            .form-row-3 { grid-template-columns: 1fr; }
            .form-actions { flex-direction: column; }
            .form-actions button, .form-actions a { width: 100%; justify-content: center; }
        }
    </style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content" id="mainContent">
        <div class="header">
            <div class="header-left">
                <h1><i class="fas <?= $view_mode ? 'fa-eye' : 'fa-user-edit' ?>"></i> <?= $page_title ?></h1>
                <p><?= $page_subtitle ?></p>
            </div>
            <div class="header-right">
                <div class="role-badge">
                    <i class="fas fa-user-circle"></i>
                    <span class="role-name"><?= htmlspecialchars($user['full_name'] ?? $user['username']) ?></span>
                </div>
                <?php if (!$edit_mode && ($trainee_data['status'] ?? '') !== 'Completed'): ?>
                <a href="manage_trainee.php?id=<?= $trainee_data['id'] ?>&mode=edit" class="btn-back">
                    <i class="fas fa-edit"></i> Edit
                </a>
                <button type="button" class="btn-complete" onclick="openCompleteModal()">
                    <i class="fas fa-check-circle"></i> Mark as Completed
                </button>
                <?php endif; ?>
                <a href="trainees.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <?php if ($success_message): ?>
            <div class="message"><i class="fas fa-check-circle"></i> <?= $success_message ?></div>
        <?php endif; ?>

        <?php if ($error_message || $error): ?>
            <div class="error"><i class="fas fa-exclamation-circle"></i> <?= $error_message ?: $error ?></div>
        <?php endif; ?>

        <?php if ($view_mode && $trainee_data): ?>
        <!-- VIEW MODE -->
        <div class="form-card">
            <div class="card-title"><i class="fas fa-user-graduate"></i> Trainee Profile</div>
            <?php 
            $dept_code = $trainee_data['course_code'] ?? 'HRS';
            $dept_icon = 'fa-building';
            $dept_color = '#667eea';
            if ($dept_code == 'HRS') { $dept_icon = 'fa-hotel'; $dept_color = '#8b5cf6'; }
            elseif ($dept_code == 'CSS') { $dept_icon = 'fa-laptop-code'; $dept_color = '#10b981'; }
            elseif ($dept_code == 'ATS') { $dept_icon = 'fa-car'; $dept_color = '#f59e0b'; }
            ?>
            <div style="display:flex;align-items:center;gap:30px;margin-bottom:30px;flex-wrap:wrap;">
                <div class="view-avatar" style="background:linear-gradient(135deg,<?= $dept_color ?>,<?= $dept_color ?>cc);">
                    <?= strtoupper(substr($trainee_data['first_name'] ?? '', 0, 1) . substr($trainee_data['last_name'] ?? '', 0, 1)) ?>
                </div>
                <div>
                    <h2 style="font-size:1.8rem;color:#0f172a;margin-bottom:8px;">
                        <?= htmlspecialchars($trainee_data['first_name'] . ' ' . $trainee_data['last_name']) ?>
                        <?php if ($trainee_data['suffix']): ?><?= htmlspecialchars($trainee_data['suffix']) ?><?php endif; ?>
                    </h2>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
                        <span class="view-badge <?= $trainee_data['status'] ?? 'active' ?>"><?= ucfirst($trainee_data['status'] ?? 'Active') ?></span>
                        <?php if ($trainee_data['course_code']): ?>
                            <span class="view-badge" style="background:<?= $dept_color ?>;color:white;">
                                <i class="fas <?= $dept_icon ?>"></i> <?= htmlspecialchars($trainee_data['course_code']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <p style="color:#64748b;"><i class="fas fa-id-card"></i> ID: <?= $trainee_data['id'] ?></p>
                </div>
            </div>
            <div class="view-section">
                <div class="section-label">Personal Information</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 30px;">
                    <div class="section-value"><strong>First Name:</strong> <?= htmlspecialchars($trainee_data['first_name'] ?? 'N/A') ?></div>
                    <div class="section-value"><strong>Last Name:</strong> <?= htmlspecialchars($trainee_data['last_name'] ?? 'N/A') ?></div>
                    <?php if ($trainee_data['middle_initial']): ?>
                    <div class="section-value"><strong>Middle Initial:</strong> <?= htmlspecialchars($trainee_data['middle_initial']) ?>.</div>
                    <?php endif; ?>
                    <?php if ($trainee_data['suffix']): ?>
                    <div class="section-value"><strong>Suffix:</strong> <?= htmlspecialchars($trainee_data['suffix']) ?></div>
                    <?php endif; ?>
                    <div class="section-value"><strong>Sex:</strong> <?= htmlspecialchars($trainee_data['sex'] ?? 'N/A') ?></div>
                </div>
            </div>
            <div class="view-section">
                <div class="section-label">Academic Information</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 30px;">
                    <div class="section-value"><strong>Section:</strong> <?= htmlspecialchars($trainee_data['section'] ?? 'N/A') ?></div>
                </div>
            </div>
            <div class="view-section">
                <div class="section-label">Department & Qualification</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 30px;">
                    <div class="section-value"><strong>Department:</strong> <?= htmlspecialchars($trainee_data['course_name'] ?? 'N/A') ?></div>
                    <div class="section-value"><strong>Qualification:</strong> <?= htmlspecialchars($trainee_data['qualification_name'] ?? 'Not Assigned') ?></div>
                    <?php if ($trainee_data['qualification_code']): ?>
                    <div class="section-value"><strong>Qualification Code:</strong> <?= htmlspecialchars($trainee_data['qualification_code']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="view-section">
                <div class="section-label">Contact Information</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 30px;">
                    <div class="section-value"><strong>Email:</strong> <?= htmlspecialchars($trainee_data['email'] ?? 'N/A') ?></div>
                    <div class="section-value"><strong>Contact:</strong> <?= htmlspecialchars($trainee_data['contact'] ?? 'N/A') ?></div>
                </div>
            </div>
        </div>

        <?php elseif ($edit_mode && $trainee_data): ?>
        <!-- EDIT MODE -->
        <div class="form-card">
            <div class="card-title"><i class="fas fa-user-edit" style="color:#f59e0b;"></i> Edit Trainee</div>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="<?= $trainee_data['id'] ?>">
                
                <div class="form-row-3">
                    <div class="form-group">
                        <label>First Name <span class="required">*</span></label>
                        <input type="text" name="first_name" value="<?= htmlspecialchars($trainee_data['first_name'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>M.I.</label>
                        <input type="text" name="middle_initial" maxlength="5" placeholder="M" value="<?= htmlspecialchars($trainee_data['middle_initial'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Suffix</label>
                        <select name="suffix">
                            <option value="">None</option>
                            <option value="Jr." <?= ($trainee_data['suffix'] ?? '') == 'Jr.' ? 'selected' : '' ?>>Jr.</option>
                            <option value="Sr." <?= ($trainee_data['suffix'] ?? '') == 'Sr.' ? 'selected' : '' ?>>Sr.</option>
                            <option value="II" <?= ($trainee_data['suffix'] ?? '') == 'II' ? 'selected' : '' ?>>II</option>
                            <option value="III" <?= ($trainee_data['suffix'] ?? '') == 'III' ? 'selected' : '' ?>>III</option>
                            <option value="IV" <?= ($trainee_data['suffix'] ?? '') == 'IV' ? 'selected' : '' ?>>IV</option>
                            <option value="V" <?= ($trainee_data['suffix'] ?? '') == 'V' ? 'selected' : '' ?>>V</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Last Name <span class="required">*</span></label>
                    <input type="text" name="last_name" value="<?= htmlspecialchars($trainee_data['last_name'] ?? '') ?>" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Section <span class="required">*</span></label>
                        <select name="section" required>
                            <option value="">Select Section</option>
                            <option value="A" <?= ($trainee_data['section'] ?? '') == 'A' ? 'selected' : '' ?>>Section A</option>
                            <option value="B" <?= ($trainee_data['section'] ?? '') == 'B' ? 'selected' : '' ?>>Section B</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sex <span class="required">*</span></label>
                        <select name="sex" required>
                            <option value="">Select Sex</option>
                            <option value="Male" <?= ($trainee_data['sex'] ?? '') == 'Male' ? 'selected' : '' ?>>Male</option>
                            <option value="Female" <?= ($trainee_data['sex'] ?? '') == 'Female' ? 'selected' : '' ?>>Female</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Department <span class="required">*</span></label>
                    <select name="course_id" id="edit_course_id" required onchange="loadQualificationsEdit(<?= $trainee_data['qualification_id'] ?? 0 ?>)">
                        <option value="">Select Department</option>
                        <?php foreach ($courses as $course): ?>
                        <option value="<?= $course['id'] ?>" <?= ($trainee_data['course_id'] ?? '') == $course['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($course['course_name']) ?> (<?= $course['course_code'] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Qualification</label>
                    <select name="qualification_id" id="edit_qualification_id">
                        <option value="">Select Department first</option>
                    </select>
                    <div class="help-text">Select the qualification the trainee is enrolled in</div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="text" name="contact" value="<?= htmlspecialchars($trainee_data['contact'] ?? '') ?>" placeholder="Contact Number">
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($trainee_data['email'] ?? '') ?>" placeholder="Email Address">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn-save"><i class="fas fa-save"></i> Update Trainee</button>
                    <a href="manage_trainee.php?id=<?= $trainee_data['id'] ?>" class="btn-cancel">Cancel</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- ============================================================ -->
    <!-- MARK AS COMPLETED CONFIRMATION MODAL                          -->
    <!-- ============================================================ -->
    <div class="complete-modal-overlay" id="completeModal">
        <div class="complete-modal-card">
            <div class="complete-modal-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <h3 class="complete-modal-title">Mark as Completed</h3>
            <p class="complete-modal-text">
                Are you sure you want to mark <strong><?= htmlspecialchars($trainee_data['first_name'] . ' ' . $trainee_data['last_name']) ?></strong> as Completed?
            </p>
            <p class="complete-modal-warning">
                <i class="fas fa-info-circle"></i>
                <span>They will be moved to the Completed Archive and removed from the Active list.</span>
            </p>
            <div class="complete-modal-actions">
                <button type="button" class="complete-btn complete-btn-cancel" onclick="closeCompleteModal()">Cancel</button>
                <form method="POST" id="completeForm" style="display:inline; flex:1;">
                    <input type="hidden" name="action" value="mark_completed">
                    <input type="hidden" name="id" value="<?= $trainee_data['id'] ?>">
                    <button type="submit" class="complete-btn complete-btn-confirm" style="width:100%;">
                        <i class="fas fa-check-circle"></i> Yes, Mark as Completed
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function loadQualificationsEdit(currentQualId) {
            const courseId = document.getElementById('edit_course_id').value;
            const qualSelect = document.getElementById('edit_qualification_id');
            
            if (!courseId) {
                qualSelect.innerHTML = '<option value="">Select Department first</option>';
                return;
            }
            
            fetch('manage_trainee.php?ajax=get_qualifications&course_id=' + courseId)
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    qualSelect.innerHTML = '<option value="">Select Qualification</option>';
                    if (data.success && data.data && data.data.length > 0) {
                        data.data.forEach(function(qual) {
                            const selected = (qual.id == currentQualId) ? 'selected' : '';
                            qualSelect.innerHTML += '<option value="' + qual.id + '" ' + selected + '>' + qual.qualification_name + ' (' + qual.qualification_code + ')</option>';
                        });
                    } else {
                        qualSelect.innerHTML = '<option value="">No qualifications available</option>';
                    }
                })
                .catch(function() {
                    qualSelect.innerHTML = '<option value="">Error loading qualifications</option>';
                });
        }

        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($edit_mode && $trainee_data): ?>
            setTimeout(function() {
                loadQualificationsEdit(<?= $trainee_data['qualification_id'] ?? 0 ?>);
            }, 100);
            <?php endif; ?>
        });

        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b') {
                e.preventDefault();
                const toggleBtn = document.getElementById('sidebar-toggle-btn');
                if (toggleBtn) toggleBtn.click();
            }
        });

        // ============================================================
        // MARK AS COMPLETED MODAL
        // ============================================================
        function openCompleteModal() {
            var modal = document.getElementById('completeModal');
            if (modal) modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeCompleteModal() {
            var modal = document.getElementById('completeModal');
            if (modal) modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        document.addEventListener('DOMContentLoaded', function() {
            var modal = document.getElementById('completeModal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) closeCompleteModal();
                });
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                var modal = document.getElementById('completeModal');
                if (modal && modal.classList.contains('active')) {
                    closeCompleteModal();
                }
            }
        });

        document.addEventListener('DOMContentLoaded', function() {
            var form = document.getElementById('completeForm');
            if (form) {
                form.addEventListener('submit', function() {
                    var btn = form.querySelector('button[type="submit"]');
                    if (btn) {
                        btn.disabled = true;
                        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Marking...';
                    }
                });
            }
        });
    </script>
    <script src="/tesda_compliance/assets/js/seamless-nav.js"></script>
    <script src="/tesda_compliance/assets/js/jquery.min.js"></script>
    <script src="/tesda_compliance/assets/js/bootstrap.bundle.min.js"></script>
    <script src="/tesda_compliance/assets/js/app.js"></script>
</body>
</html>