<?php
ob_start();
require_once __DIR__ . '/auth_check.php';
if (!isLoggedIn()) {
    redirect('index.php');
}

$user = getCurrentUser();
$user_role = $_SESSION['role'] ?? '';
$user_department = $_SESSION['department'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

// ==========================================
// RBAC: Get trainer qualification IDs
// ==========================================
$is_trainer = isTrainer();
$is_coordinator = isCoordinator();
$trainer_qualification_ids = [];

if ($is_trainer) {
    $trainer_qualification_ids = getTrainerQualificationIds($user_id);
    $_SESSION['qualification_ids'] = $trainer_qualification_ids;
}

// Get all courses
$courses = $pdo->query("SELECT * FROM courses ORDER BY course_code")->fetchAll();

// ==========================================
// RBAC: Get qualifications filtered for trainers
// ==========================================
if ($is_coordinator) {
    $qualifications = $pdo->query("
        SELECT q.*, c.course_name, c.course_code, c.color
        FROM qualifications q
        JOIN courses c ON q.course_id = c.id
        ORDER BY c.course_code, q.qualification_name
    ")->fetchAll();
} else if ($is_trainer && !empty($trainer_qualification_ids)) {
    $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
    $qual_stmt = $pdo->prepare("
        SELECT q.*, c.course_name, c.course_code, c.color
        FROM qualifications q
        JOIN courses c ON q.course_id = c.id
        WHERE q.id IN ($placeholders)
        ORDER BY c.course_code, q.qualification_name
    ");
    $qual_stmt->execute($trainer_qualification_ids);
    $qualifications = $qual_stmt->fetchAll();
} else {
    $qualifications = [];
}

// ==========================================
// Handle Delete Competency - Coordinator only
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_competency'])) {
    header('Content-Type: application/json');
    try {
        $competency_id = (int)$_POST['id'];
        
        if (!$is_coordinator) {
            throw new Exception("You don't have permission to delete competencies.");
        }
        
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("DELETE FROM performance_tasks WHERE competency_id = ?");
        $stmt->execute([$competency_id]);
        
        $stmt = $pdo->prepare("DELETE FROM competency_standards WHERE id = ? AND LOWER(unit_of_competency) = 'core'");
        $stmt->execute([$competency_id]);
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Core competency and all associated tasks deleted successfully.']);
        exit;
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ==========================================
// RBAC: Get competency standards filtered by trainer's qualifications - CORE ONLY
// ==========================================
if ($is_coordinator) {
    $stmt = $pdo->prepare("
        SELECT 
            cs.id,
            cs.qualification_id,
            cs.unit_of_competency,
            cs.competency_name,
            cs.code_no,
            cs.created_at,
            cs.updated_at,
            q.qualification_name,
            q.qualification_code,
            c.course_name,
            c.course_code,
            c.color,
            c.id as course_id,
            COUNT(DISTINCT pt.id) as task_count
        FROM competency_standards cs
        JOIN qualifications q ON cs.qualification_id = q.id
        JOIN courses c ON q.course_id = c.id
        LEFT JOIN performance_tasks pt ON cs.id = pt.competency_id
        WHERE LOWER(cs.unit_of_competency) = 'core'
        GROUP BY cs.id
        ORDER BY c.course_code, q.qualification_name, cs.competency_name
    ");
    $stmt->execute();
} else if ($is_trainer && !empty($trainer_qualification_ids)) {
    $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT 
            cs.id,
            cs.qualification_id,
            cs.unit_of_competency,
            cs.competency_name,
            cs.code_no,
            cs.created_at,
            cs.updated_at,
            q.qualification_name,
            q.qualification_code,
            c.course_name,
            c.course_code,
            c.color,
            c.id as course_id,
            COUNT(DISTINCT pt.id) as task_count
        FROM competency_standards cs
        JOIN qualifications q ON cs.qualification_id = q.id
        JOIN courses c ON q.course_id = c.id
        LEFT JOIN performance_tasks pt ON cs.id = pt.competency_id
        WHERE cs.qualification_id IN ($placeholders)
          AND LOWER(cs.unit_of_competency) = 'core'
        GROUP BY cs.id
        ORDER BY c.course_code, q.qualification_name, cs.competency_name
    ");
    $stmt->execute($trainer_qualification_ids);
} else {
    $competencies = [];
    $stmt = null;
}

if ($stmt) {
    $competencies = $stmt->fetchAll();
} else {
    $competencies = [];
}

// ==========================================
// Group by Qualification -> Unit of Competency -> Competency (CORE ONLY)
// ==========================================
$grouped_by_qualification = [];
foreach ($competencies as $comp) {
    $qual_id = $comp['qualification_id'];
    $unit = $comp['unit_of_competency'] ?: 'core';
    
    if (!isset($grouped_by_qualification[$qual_id])) {
        $grouped_by_qualification[$qual_id] = [
            'qualification_id' => $qual_id,
            'qualification_name' => $comp['qualification_name'],
            'qualification_code' => $comp['qualification_code'],
            'course_id' => $comp['course_id'] ?? 0,
            'course_name' => $comp['course_name'],
            'course_code' => $comp['course_code'],
            'color' => $comp['color'] ?? '#8b5cf6',
            'units' => []
        ];
    }
    
    if (!isset($grouped_by_qualification[$qual_id]['units'][$unit])) {
        $grouped_by_qualification[$qual_id]['units'][$unit] = [];
    }
    
    $grouped_by_qualification[$qual_id]['units'][$unit][] = $comp;
}

// ==========================================
// RBAC: Get tasks grouped by competency filtered by trainer's qualifications - CORE ONLY
// ==========================================
$tasks_by_competency = [];
if ($is_coordinator) {
    $task_stmt = $pdo->query("
        SELECT 
            pt.id,
            pt.competency_id,
            pt.task_name,
            pt.code_no as task_code,
            cs.competency_name,
            cs.code_no,
            cs.unit_of_competency
        FROM performance_tasks pt
        JOIN competency_standards cs ON pt.competency_id = cs.id
        WHERE LOWER(cs.unit_of_competency) = 'core'
        ORDER BY pt.id
    ");
    while ($task = $task_stmt->fetch()) {
        $comp_id = $task['competency_id'];
        if (!isset($tasks_by_competency[$comp_id])) {
            $tasks_by_competency[$comp_id] = [];
        }
        $tasks_by_competency[$comp_id][] = $task;
    }
} else if ($is_trainer && !empty($trainer_qualification_ids)) {
    $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
    $task_stmt = $pdo->prepare("
        SELECT 
            pt.id,
            pt.competency_id,
            pt.task_name,
            pt.code_no as task_code,
            cs.competency_name,
            cs.code_no,
            cs.unit_of_competency
        FROM performance_tasks pt
        JOIN competency_standards cs ON pt.competency_id = cs.id
        WHERE cs.qualification_id IN ($placeholders)
          AND LOWER(cs.unit_of_competency) = 'core'
        ORDER BY pt.id
    ");
    $task_stmt->execute($trainer_qualification_ids);
    while ($task = $task_stmt->fetch()) {
        $comp_id = $task['competency_id'];
        if (!isset($tasks_by_competency[$comp_id])) {
            $tasks_by_competency[$comp_id] = [];
        }
        $tasks_by_competency[$comp_id][] = $task;
    }
}

// ==========================================
// Get total tasks count with RBAC - CORE ONLY
// ==========================================
if ($is_coordinator) {
    $total_tasks = $pdo->query("
        SELECT COUNT(DISTINCT pt.id) 
        FROM performance_tasks pt
        JOIN competency_standards cs ON pt.competency_id = cs.id
        WHERE LOWER(cs.unit_of_competency) = 'core'
    ")->fetchColumn();
} else if ($is_trainer && !empty($trainer_qualification_ids)) {
    $placeholders = implode(',', array_fill(0, count($trainer_qualification_ids), '?'));
    $task_stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT pt.id) 
        FROM performance_tasks pt
        JOIN competency_standards cs ON pt.competency_id = cs.id
        WHERE cs.qualification_id IN ($placeholders)
          AND LOWER(cs.unit_of_competency) = 'core'
    ");
    $task_stmt->execute($trainer_qualification_ids);
    $total_tasks = (int)$task_stmt->fetchColumn();
} else {
    $total_tasks = 0;
}

// ==========================================
// Unit order and labels - CORE ONLY
// ==========================================
$unit_order = ['core'];
$unit_labels = [
    'core' => 'CORE'
];
$unit_icons = [
    'core' => 'fa-star'
];
$unit_colors = [
    'core' => '#8b5cf6'
];
$unit_badge_classes = [
    'core' => 'core'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Core Competency Standards - TESDA Compliance</title>
    <link rel="stylesheet" href="/tesda_compliance/assets/vendor/fontawesome/css/all.min.css">
    <style>
        /* ========================================== */
        /* STYLES - SYNCED with tasks.php              */
        /* ========================================== */
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
            animation: fadeInPage 0.4s ease-out;
        }

        @keyframes fadeInPage {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
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
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .header:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 45px rgba(0,0,0,0.12);
        }

        .header-left {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .header-left h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
            line-height: 1.2;
        }

        .header-left h1 i {
            color: #8b5cf6;
            font-size: 1.8rem;
        }

        .header-left p {
            color: #64748b;
            font-size: 1rem;
            margin: 0;
            margin-top: 2px;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .profile-badge {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 6px 20px 6px 6px;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 50px;
            transition: all 0.2s ease;
            cursor: default;
        }

        .profile-badge:hover {
            background-color: #f1f5f9;
            border-color: #cbd5e1;
        }

        .profile-badge .avatar-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background-color: #263868;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: all 0.2s ease;
        }

        .profile-badge:hover .avatar-circle {
            transform: scale(1.05);
        }

        .profile-badge .avatar-circle i {
            color: #ffffff;
            font-size: 0.9rem;
        }

        .profile-badge .profile-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: #1e293b;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .date-badge {
            background: #f8fafc;
            padding: 10px 20px;
            border-radius: 12px;
            color: #0f172a;
            font-weight: 500;
            font-size: 1rem;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .date-badge i {
            color: #0f172a;
            font-size: 1rem;
        }

        .date-badge:hover {
            background-color: #f1f5f9;
        }

        .btn-primary {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            box-shadow: 0 5px 15px rgba(139,92,246,0.2);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(139,92,246,0.3);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            gap: 16px;
            border: 1px solid #e8edf5;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.12);
        }

        .stat-icon-wrapper {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .stat-card:hover .stat-icon-wrapper {
            transform: scale(1.05);
        }

        .stat-icon-wrapper i {
            font-size: 1.5rem;
            color: white;
        }

        .stat-content h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1a1c2c;
            line-height: 1.2;
        }

        .stat-content p {
            color: #64748b;
            font-size: 0.9rem;
            font-weight: 500;
            margin: 0;
        }

        .filter-section {
            background: white;
            border-radius: 20px;
            padding: 28px 32px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }

        .filter-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .filter-select, .filter-input {
            padding: 14px 18px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            min-width: 170px;
            background: white;
            flex: 1;
        }

        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: #8b5cf6;
            box-shadow: 0 0 0 3px rgba(139,92,246,0.1);
        }

        .btn-reset-filters {
            padding: 14px 28px;
            background: #e2e8f0;
            color: #1a1c2c;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            white-space: nowrap;
        }

        .btn-reset-filters:hover {
            background: #cbd5e1;
        }

        .accordion-section {
            margin-bottom: 24px;
        }

        /* ========================================== */
        /* QUALIFICATION HEADER CARD — SMALLER FONTS  */
        /* ========================================== */
        .qualification-header-card {
            background: #ffffff;
            border-radius: 18px;
            padding: 14px 22px;
            box-shadow: 0 6px 18px rgba(0,0,0,0.06);
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            border: 1px solid #eef2f6;
        }

        .qualification-header-card:hover {
            background: #fafbff;
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
        }

        .qual-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }

        .qual-header-left .qual-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.1rem;
            flex-shrink: 0;
            background: #8b5cf6;
        }

        .qual-header-left .qual-title {
            font-weight: 700;
            font-size: 1.05rem;
            color: #1e293b;
        }

        .qual-header-left .qual-sub {
            font-size: 0.72rem;
            font-weight: 600;
            color: #64748b;
            background: #f1f5f9;
            padding: 3px 12px;
            border-radius: 30px;
        }

        .qual-header-right {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .qual-toggle {
            font-size: 1.1rem;
            color: #94a3b8;
        }

        .qual-toggle.open {
            transform: rotate(180deg);
        }

        .accordion-body {
            background: white;
            border-radius: 0 0 18px 18px;
            box-shadow: 0 6px 18px rgba(0,0,0,0.04);
            margin-top: 4px;
            border: 1px solid #eef2f6;
            border-top: none;
            overflow: hidden;
            max-height: 0;
            padding: 0 24px;
        }

        .accordion-body.open {
            max-height: 5000px;
            padding: 24px 20px 24px;
        }

        .unit-card {
            border: 1px solid #eef2f6;
            border-radius: 14px;
            margin-bottom: 16px;
            overflow: hidden;
            background: #ffffff;
        }

        .unit-card:last-child {
            margin-bottom: 0;
        }

        .unit-card:hover {
            border-color: #d5dce6;
            box-shadow: 0 2px 12px rgba(0,0,0,0.04);
        }

        .unit-header {
            padding: 14px 20px;
            background: #fafbfc;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            border-bottom: 2px solid transparent;
        }

        .unit-header:hover {
            background: #f5f6fa;
        }

        .unit-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .unit-icon {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .unit-icon.core { background: #8b5cf6; }

        .unit-label {
            font-size: 0.95rem;
            font-weight: 700;
            color: #1e293b;
            letter-spacing: 0.5px;
        }

        .unit-toggle {
            color: #94a3b8;
            font-size: 0.9rem;
        }

        .unit-toggle.open {
            transform: rotate(180deg);
        }

        .unit-table-container {
            padding: 0 16px 16px;
            display: none;
            background: #ffffff;
        }

        .unit-table-container.open {
            display: block;
        }

        .competency-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
            table-layout: fixed;
        }

        .competency-table thead th {
            padding: 12px 12px;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            border-bottom: 2px solid #eef2f6;
            background: #fafbfc;
        }

        .competency-table thead th:first-child,
        .competency-table tbody td:first-child {
            width: 120px;
            min-width: 120px;
            max-width: 120px;
            text-align: left;
            padding-left: 8px;
        }

        .competency-table thead th:nth-child(2),
        .competency-table tbody td:nth-child(2) {
            text-align: left;
            width: auto;
            padding-left: 12px;
            padding-right: 12px;
        }

        .competency-table thead th:nth-child(3),
        .competency-table tbody td:nth-child(3) {
            width: 110px;
            min-width: 110px;
            max-width: 110px;
            text-align: center;
        }

        .competency-table thead th:nth-child(4),
        .competency-table tbody td:nth-child(4) {
            width: 90px;
            min-width: 90px;
            max-width: 90px;
            text-align: center;
        }

        .competency-table thead th:nth-child(5),
        .competency-table tbody td:nth-child(5) {
            width: 180px;
            min-width: 180px;
            max-width: 180px;
            text-align: right;
            padding-right: 8px;
        }

        .competency-table tbody td {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            font-size: 0.9rem;
            color: #1e293b;
        }

        .competency-table tbody td:nth-child(3),
        .competency-table tbody td:nth-child(4) {
            padding-left: 4px;
            padding-right: 4px;
        }

        .competency-table tbody tr:hover {
            background: #f8fafc;
        }

        .competency-table tbody tr:last-child td {
            border-bottom: none;
        }

        .code-badge {
            background: #f1f5f9;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
            color: #475569;
            display: inline-block;
            font-family: 'Poppins', monospace;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .comp-name {
            font-weight: 700;
            color: #1e293b;
            display: inline-block;
            word-break: break-word;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .unit-type-badge {
            font-size: 0.85rem;
            text-transform: uppercase;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 30px;
            display: inline-block;
            white-space: nowrap;
            letter-spacing: 0.5px;
            text-align: center;
            min-width: 65px;
        }

        .unit-type-badge.core { 
            background: #fce7f3; 
            color: #9d174d; 
        }

        .task-badge {
            background: #8b5cf6;
            color: white;
            font-weight: 700;
            font-size: 0.9rem;
            padding: 2px 14px;
            border-radius: 30px;
            display: inline-block;
            min-width: 36px;
            text-align: center;
        }

        .task-badge.zero {
            background: #e2e8f0;
            color: #94a3b8;
        }

        .action-group {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
            align-items: center;
            flex-wrap: nowrap;
        }

        .action-btn {
            padding: 5px 14px;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            text-decoration: none;
            background: #f1f5f9;
            color: #475569;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .action-btn:hover {
            background: #e2e8f0;
        }

        .action-btn.edit {
            background: #fef3c7;
            color: #92400e;
        }
        .action-btn.edit:hover {
            background: #fde68a;
        }

        .action-btn.delete {
            background: #fee2e2;
            color: #991b1b;
        }
        .action-btn.delete:hover {
            background: #fecaca;
        }

        .action-btn i {
            font-size: 0.85rem;
        }

        .empty-row td {
            text-align: center;
            color: #94a3b8;
            padding: 30px 0;
            font-style: italic;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }

        .empty-state i {
            font-size: 4rem;
            color: #cbd5e1;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-size: 1.3rem;
            color: #1e293b;
            margin-bottom: 8px;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-box {
            background: white;
            border-radius: 25px;
            padding: 40px 44px;
            width: 90%;
            max-width: 700px;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 2px solid #eef2f6;
        }

        .modal-header h3 {
            font-size: 1.3rem;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-header h3 i {
            color: #8b5cf6;
        }

        .close-modal {
            font-size: 1.8rem;
            cursor: pointer;
            color: #94a3b8;
            background: none;
            border: none;
            padding: 0 8px;
            line-height: 1;
        }

        .close-modal:hover {
            color: #ef4444;
        }

        .modal-task-list {
            list-style: none;
            padding: 0;
        }

        .modal-task-list li {
            padding: 12px 16px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            gap: 14px;
            font-size: 0.9rem;
        }

        .modal-task-list li:hover {
            background: #f8fafc;
        }

        .modal-task-list li:last-child {
            border-bottom: none;
        }

        .modal-task-list .task-bullet {
            color: #8b5cf6;
            font-size: 0.9rem;
        }

        .modal-task-list .task-code {
            font-size: 0.9rem;
            font-weight: 600;
            color: #475569;
            background: #f1f5f9;
            padding: 2px 14px;
            border-radius: 30px;
            font-family: 'Poppins', monospace;
        }

        .modal-actions {
            margin-top: 24px;
            padding-top: 16px;
            border-top: 1px solid #eef2f6;
            display: flex;
            gap: 12px;
        }

        .modal-actions .btn-close-modal {
            padding: 12px 32px;
            background: #f1f5f9;
            color: #475569;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            width: 100%;
        }

        .modal-actions .btn-close-modal:hover {
            background: #e2e8f0;
        }

        .confirm-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 99999;
            align-items: center;
            justify-content: center;
            animation: logoutFadeIn 0.2s ease-out;
        }

        .confirm-modal-overlay.active {
            display: flex;
        }

        @keyframes logoutFadeIn {
            from { opacity: 0; }
            to   { opacity: 1; }
        }

        @keyframes logoutPopIn {
            from { opacity: 0; transform: translateY(12px) scale(0.96); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        .confirm-modal-box {
            background: #ffffff;
            border-radius: 20px;
            padding: 32px 28px 24px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.25);
            text-align: center;
            animation: logoutPopIn 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            border: 1px solid rgba(226, 232, 240, 0.8);
        }

        .confirm-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 16px;
            border-radius: 50%;
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.7rem;
            color: #dc2626;
            box-shadow: 0 6px 20px rgba(220, 38, 38, 0.15);
        }

        .confirm-modal-box h3 {
            font-size: 1.25rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 8px;
        }

        .confirm-modal-box .confirm-message {
            font-size: 0.9rem;
            color: #64748b;
            line-height: 1.5;
            margin: 0 0 16px;
        }

        .confirm-modal-box .confirm-competency-name {
            background: #f8fafc;
            padding: 10px 16px;
            border-radius: 10px;
            margin: 0 0 12px;
            font-size: 0.9rem;
            color: #1e293b;
            font-weight: 700;
            word-break: break-word;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border: 1px solid #e2e8f0;
        }

        .confirm-modal-box .confirm-competency-name i {
            color: #ef4444;
            margin-right: 8px;
        }

        .confirm-modal-box .confirm-warning {
            font-size: 0.85rem;
            color: #ef4444;
            margin: 0 0 24px;
            padding: 8px 12px;
            background: #fef2f2;
            border-radius: 10px;
            border: 1px solid #fecaca;
            line-height: 1.4;
        }

        .confirm-modal-box .confirm-warning i {
            margin-right: 6px;
        }

        .confirm-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .btn-confirm-cancel {
            flex: 1;
            padding: 11px 18px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
            background: #f1f5f9;
            color: #475569;
            border: 2px solid #e2e8f0;
            font-family: 'Poppins', sans-serif;
        }

        .btn-confirm-cancel:hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        .btn-confirm-delete {
            flex: 1;
            padding: 11px 18px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #ffffff;
            border: none;
            box-shadow: 0 6px 16px rgba(220, 38, 38, 0.3);
            font-family: 'Poppins', sans-serif;
        }

        .btn-confirm-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(220, 38, 38, 0.4);
        }

        .btn-confirm-delete:active,
        .btn-confirm-cancel:active {
            transform: translateY(0);
        }

        .btn-confirm-delete:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none;
        }

        .floating-message {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #10b981;
            color: white;
            padding: 12px 20px;
            border-radius: 10px;
            z-index: 9999;
            animation: slideIn 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
            font-family: 'Poppins', sans-serif;
        }

        .floating-message.error {
            background: #ef4444;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #8b5cf6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #e2e8f0;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #5a67d8, #6b46a0);
        }

        @media (max-width: 1200px) {
            body { min-width: 992px; }
            .main-content { margin-left: 80px; width: calc(100% - 80px); }
        }

        @media (max-width: 992px) {
            .competency-table thead th:nth-child(5),
            .competency-table tbody td:nth-child(5) {
                width: 160px;
                min-width: 160px;
                max-width: 160px;
            }
            
            .action-btn {
                padding: 4px 10px;
                font-size: 0.85rem;
            }
        }

        @media (max-width: 768px) {
            .main-content { margin-left: 0; width: 100%; padding: 15px; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .qualification-header-card { flex-wrap: wrap; }
            .qual-header-right { margin-top: 10px; }
            .unit-header { flex-wrap: wrap; }
            .unit-header-left { flex-wrap: wrap; }
            
            .competency-table { font-size: 0.9rem; }
            .competency-table thead th, 
            .competency-table tbody td { padding: 8px 8px; }
            
            .competency-table thead th:first-child,
            .competency-table tbody td:first-child {
                width: 90px;
                min-width: 90px;
                max-width: 90px;
            }
            
            .competency-table thead th:nth-child(3),
            .competency-table tbody td:nth-child(3) {
                width: 80px;
                min-width: 80px;
                max-width: 80px;
            }
            
            .competency-table thead th:nth-child(4),
            .competency-table tbody td:nth-child(4) {
                width: 70px;
                min-width: 70px;
                max-width: 70px;
            }
            
            .competency-table thead th:nth-child(5),
            .competency-table tbody td:nth-child(5) {
                width: auto;
                min-width: unset;
                max-width: unset;
            }
            
            .action-group { flex-wrap: wrap; justify-content: flex-end; gap: 4px; }
            .action-btn { padding: 4px 8px; font-size: 0.85rem; }
            .modal-box { padding: 24px; }
            .profile-badge { padding: 6px 12px 6px 6px; }
            .profile-badge .profile-name { font-size: 0.85rem; }
            .confirm-modal-box { padding: 25px 20px; }
        }
    </style>


<link rel="stylesheet" href="/tesda_compliance/assets/css/bootstrap.min.css">
<link rel="stylesheet" href="/tesda_compliance/assets/css/all.min.css">    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
    </div>

    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <h1><i class="fas fa-list-check"></i> Core Competency Standards</h1>
                <p>View Core competency standards and associated performance tasks</p>
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
                <a href="tasks.php" class="btn-primary">
                    <i class="fas fa-tasks"></i> Manage Tasks
                </a>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon-wrapper" style="background: #8b5cf6;">
                    <i class="fas fa-list-check"></i>
                </div>
                <div class="stat-content">
                    <h3><?= count($competencies) ?></h3>
                    <p>Total Core Competencies</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-wrapper" style="background: #10b981;">
                    <i class="fas fa-certificate"></i>
                </div>
                <div class="stat-content">
                    <h3><?= count($qualifications) ?></h3>
                    <p>Qualifications</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-wrapper" style="background: #f59e0b;">
                    <i class="fas fa-building"></i>
                </div>
                <div class="stat-content">
                    <h3><?= count($courses) ?></h3>
                    <p>Departments</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon-wrapper" style="background: #3b82f6;">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="stat-content">
                    <h3><?= $total_tasks ?></h3>
                    <p>Total Core Performance Tasks</p>
                </div>
            </div>
        </div>

        <!-- Filter -->
        <div class="filter-section">
            <div class="filter-row">
                <select class="filter-select" id="courseFilter">
                    <option value="">All Departments</option>
                    <?php foreach ($courses as $course): ?>
                    <option value="<?= $course['id'] ?>"><?= htmlspecialchars($course['course_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="filter-select" id="unitFilter">
                    <option value="">All Units</option>
                    <option value="core">Core</option>
                </select>
                <input type="text" class="filter-input" id="searchInput" placeholder="Search competencies or tasks...">
                <button class="btn-reset-filters" id="resetFilters">
                    <i class="fas fa-undo-alt"></i> Reset
                </button>
            </div>
        </div>

        <!-- ACCORDION LIST -->
        <?php if (empty($grouped_by_qualification)): ?>
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <h3>No Core Competency Standards Found</h3>
                <?php if ($is_trainer && !$is_coordinator && empty($trainer_qualification_ids)): ?>
                    <p>You don't have any qualifications assigned. Please contact the coordinator.</p>
                <?php else: ?>
                    <p>Add Core competency standards by creating performance tasks in the <a href="tasks.php" style="color:#8b5cf6;font-weight:600;">Task Management</a> module.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php 
            $qual_index = 0;
            foreach ($grouped_by_qualification as $qual_id => $qual_data): 
                $qual_index++;
                $color = $qual_data['color'] ?? '#8b5cf6';
                $units = $qual_data['units'];
                $course_id = $qual_data['course_id'] ?? 0;
            ?>
            <div class="accordion-section" data-course-id="<?= $course_id ?>">
                <!-- Qualification Header Card -->
                <div class="qualification-header-card" onclick="toggleQualification(this)">
                    <div class="qual-header-left">
                        <div class="qual-icon" style="background:<?= $color ?>;">
                            <i class="fas fa-certificate"></i>
                        </div>
                        <span class="qual-title"><?= htmlspecialchars($qual_data['qualification_name'] ?? 'Qualification') ?></span>
                        <span class="qual-sub"><?= htmlspecialchars($qual_data['qualification_code'] ?? '') ?></span>
                    </div>
                    <div class="qual-header-right">
                        <span class="qual-toggle"><i class="fas fa-chevron-down"></i></span>
                    </div>
                </div>

                <!-- Accordion Body -->
                <div class="accordion-body" id="qualBody<?= $qual_index ?>">
                    <?php 
                    foreach ($unit_order as $unit_key):
                        if (!isset($units[$unit_key])) continue;
                        $unit_items = $units[$unit_key];
                    ?>
                    <div class="unit-card" data-unit="<?= $unit_key ?>">
                        <div class="unit-header" onclick="toggleUnit(this)">
                            <div class="unit-header-left">
                                <div class="unit-icon <?= $unit_key ?>">
                                    <i class="fas <?= $unit_icons[$unit_key] ?>"></i>
                                </div>
                                <span class="unit-label"><?= $unit_labels[$unit_key] ?></span>
                            </div>
                            <span class="unit-toggle"><i class="fas fa-chevron-down"></i></span>
                        </div>
                        
                        <div class="unit-table-container">
                            <table class="competency-table">
                                <thead>
                                    <tr>
                                        <th style="text-align:left;width:120px;min-width:120px;max-width:120px;">CODE</th>
                                        <th style="text-align:left;">COMPETENCY NAME</th>
                                        <th style="text-align:center;width:110px;min-width:110px;max-width:110px;">UNIT</th>
                                        <th style="text-align:center;width:90px;min-width:90px;max-width:90px;">TASKS</th>
                                        <th style="text-align:right;width:180px;min-width:180px;max-width:180px;">ACTIONS</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $row_idx = 0;
                                    foreach ($unit_items as $comp):
                                        $row_idx++;
                                        $comp_code = $comp['code_no'] ?? 'N/A';
                                        $task_count = $comp['task_count'] ?? 0;
                                        $unit_label = ucfirst($unit_key);
                                        $unit_class = $unit_key;
                                    ?>
                                    <tr data-name="<?= strtolower($comp['competency_name'] ?? '') ?>" data-code="<?= strtolower($comp_code) ?>" data-competency-id="<?= $comp['id'] ?>">
                                        <td style="text-align:left;padding-left:8px;">
                                            <span class="code-badge"><?= htmlspecialchars($comp_code) ?></span>
                                        </td>
                                        <td style="text-align:left;padding-left:12px;padding-right:12px;">
                                            <span class="comp-name"><?= htmlspecialchars(strtoupper($comp['competency_name'] ?? 'UNNAMED')) ?></span>
                                        </td>
                                        <td style="text-align:center;padding-left:4px;padding-right:4px;">
                                            <span class="unit-type-badge <?= $unit_class ?>"><?= $unit_label ?></span>
                                        </td>
                                        <td style="text-align:center;padding-left:4px;padding-right:4px;">
                                            <span class="task-badge <?= $task_count == 0 ? 'zero' : '' ?>">
                                                <?= $task_count ?>
                                            </span>
                                        </td>
                                        <td style="text-align:right;padding-right:8px;">
                                            <div class="action-group">
                                                <?php if ($is_coordinator): ?>
                                                <a href="tasks.php?edit_competency=<?= $comp['id'] ?>" class="action-btn edit">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <button class="action-btn delete" onclick="openDeleteModal(<?= $comp['id'] ?>, '<?= htmlspecialchars(addslashes(strtoupper($comp['competency_name'] ?? 'UNNAMED'))) ?>')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                                <?php else: ?>
                                                <span style="font-size:0.7rem;color:#94a3b8;">View only</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if ($row_idx === 0): ?>
                                    <tr class="empty-row"><td colspan="5">No Core competencies in this unit.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- View Tasks Modal -->
    <div id="viewTasksModal" class="modal-overlay">
        <div class="modal-box">
            <div class="modal-header">
                <h3><i class="fas fa-tasks"></i> <span id="modalTitle">Performance Tasks</span></h3>
                <button class="close-modal" onclick="closeModal('viewTasksModal')">&times;</button>
            </div>
            <div id="modalContent">
                <div style="text-align:center;padding:20px;color:#94a3b8;">
                    <i class="fas fa-spinner fa-spin" style="font-size:1.8rem;"></i>
                    <p style="margin-top:12px;">Loading tasks...</p>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn-close-modal" onclick="closeModal('viewTasksModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- DELETE CONFIRMATION MODAL (matches logout)  -->
    <!-- ========================================== -->
    <div class="confirm-modal-overlay" id="deleteModal">
        <div class="confirm-modal-box">
            <div class="confirm-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3>Delete Core Competency</h3>
            <p class="confirm-message">Are you sure you want to delete this Core competency?</p>
            <div class="confirm-competency-name">
                <i class="fas fa-trash-alt"></i> <span id="deleteCompetencyName"></span>
            </div>
            <div class="confirm-warning">
                <i class="fas fa-exclamation-circle"></i> This will also delete all associated Core performance tasks.
            </div>
            <div class="confirm-actions">
                <button class="btn-confirm-cancel" onclick="closeDeleteModal()">Cancel</button>
                <button class="btn-confirm-delete" id="confirmDeleteBtn" onclick="confirmDelete()">Delete</button>
            </div>
        </div>
    </div>

    <script>
        // ==========================================
        // DATA STORE - tasks_by_competency from PHP
        // ==========================================
        const tasksByCompetency = <?= json_encode($tasks_by_competency) ?>;

        // ==========================================
        // TOGGLE FUNCTIONS
        // ==========================================
        function toggleQualification(headerEl) {
            const body = headerEl.nextElementSibling;
            const toggleIcon = headerEl.querySelector('.qual-toggle i');
            if (body) {
                body.classList.toggle('open');
                if (toggleIcon) {
                    toggleIcon.classList.toggle('fa-chevron-down');
                    toggleIcon.classList.toggle('fa-chevron-up');
                }
            }
        }

        function toggleUnit(headerEl) {
            const container = headerEl.nextElementSibling;
            const toggleIcon = headerEl.querySelector('.unit-toggle i');
            if (container) {
                container.classList.toggle('open');
                if (toggleIcon) {
                    toggleIcon.classList.toggle('fa-chevron-down');
                    toggleIcon.classList.toggle('fa-chevron-up');
                }
            }
        }

        // ==========================================
        // DELETE CONFIRMATION MODAL
        // ==========================================
        let deleteId = null;
        let deleteName = '';

        function openDeleteModal(id, name) {
            deleteId = id;
            deleteName = name;
            document.getElementById('deleteCompetencyName').textContent = (name || '').toUpperCase();
            document.getElementById('deleteModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            document.getElementById('confirmDeleteBtn').disabled = false;
            document.getElementById('confirmDeleteBtn').textContent = 'Delete';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
            document.body.style.overflow = '';
            deleteId = null;
            deleteName = '';
        }

        function confirmDelete() {
            if (!deleteId) return;
            
            var btn = document.getElementById('confirmDeleteBtn');
            btn.disabled = true;
            btn.textContent = 'Deleting...';
            
            var loadingOverlay = document.getElementById('loadingOverlay');
            loadingOverlay.style.display = 'flex';
            
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'delete_competency=1&id=' + deleteId
            })
            .then(function(response) {
                return response.json();
            })
            .then(function(data) {
                loadingOverlay.style.display = 'none';
                closeDeleteModal();
                
                if (data.success) {
                    showFloatingMessage(data.message, 'success');
                    setTimeout(function() {
                        window.location.reload();
                    }, 500);
                } else {
                    showFloatingMessage(data.message, 'error');
                    btn.disabled = false;
                    btn.textContent = 'Delete';
                }
            })
            .catch(function(error) {
                loadingOverlay.style.display = 'none';
                closeDeleteModal();
                console.error('Error:', error);
                showFloatingMessage('An error occurred while deleting.', 'error');
                btn.disabled = false;
                btn.textContent = 'Delete';
            });
        }

        // ==========================================
        // FLOATING MESSAGE
        // ==========================================
        function showFloatingMessage(message, type) {
            type = type || 'success';
            var existing = document.querySelector('.floating-message');
            if (existing) existing.remove();
            
            var msgDiv = document.createElement('div');
            msgDiv.className = 'floating-message ' + type;
            
            var iconMap = {
                'success': 'fa-check-circle',
                'error': 'fa-exclamation-circle'
            };
            
            var icon = iconMap[type] || 'fa-info-circle';
            msgDiv.innerHTML = '<i class="fas ' + icon + '"></i> ' + message;
            document.body.appendChild(msgDiv);
            
            setTimeout(function() {
                if (msgDiv.parentNode) {
                    msgDiv.parentNode.removeChild(msgDiv);
                }
            }, 3000);
        }

        // ==========================================
        // VIEW TASKS MODAL
        // ==========================================
        function viewTasks(competencyId, competencyName) {
            const modalTitle = document.getElementById('modalTitle');
            const modalContent = document.getElementById('modalContent');
            
            modalTitle.textContent = 'Performance Tasks: ' + (competencyName || '').toUpperCase();
            
            const tasks = tasksByCompetency[competencyId] || [];
            
            if (tasks.length > 0) {
                let html = `<ul class="modal-task-list">`;
                tasks.forEach(task => {
                    const taskCode = task.task_code || 'N/A';
                    const taskName = task.task_name || 'Unnamed Task';
                    html += `
                        <li>
                            <span class="task-bullet">•</span>
                            ${taskCode !== 'N/A' ? `<span class="task-code">${escapeHtml(taskCode)}</span>` : ''}
                            <span>${escapeHtml(taskName)}</span>
                        </li>
                    `;
                });
                html += `</ul>`;
                html += `<div style="margin-top:12px;font-size:0.85rem;color:#64748b;text-align:center;">Total: ${tasks.length} task(s)</div>`;
                modalContent.innerHTML = html;
            } else {
                modalContent.innerHTML = `
                    <div style="text-align:center;padding:30px;color:#94a3b8;">
                        <i class="fas fa-inbox" style="font-size:2.5rem;color:#cbd5e1;display:block;margin-bottom:12px;"></i>
                        <p>No performance tasks found for this Core competency.</p>
                        <p style="font-size:0.9rem;margin-top:8px;">
                            <a href="tasks.php" style="color:#8b5cf6;font-weight:600;text-decoration:none;">
                                <i class="fas fa-plus-circle"></i> Add tasks in Task Management
                            </a>
                        </p>
                    </div>
                `;
            }
            
            openModal('viewTasksModal');
        }

        // ==========================================
        // MODAL FUNCTIONS
        // ==========================================
        function openModal(id) {
            document.getElementById(id).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
            document.body.style.overflow = '';
        }

        window.onclick = function(e) {
            if (e.target.classList.contains('modal-overlay')) {
                e.target.classList.remove('active');
                document.body.style.overflow = '';
            }
            var deleteModal = document.getElementById('deleteModal');
            if (e.target == deleteModal) {
                closeDeleteModal();
            }
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.active').forEach(modal => {
                    modal.classList.remove('active');
                    document.body.style.overflow = '';
                });
                closeDeleteModal();
            }
        });

        // ==========================================
        // UTILITY FUNCTIONS
        // ==========================================
        function escapeHtml(text) {
            if (!text) return '';
            return String(text).replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }

        // ==========================================
        // FILTER
        // ==========================================
        function filterCompetencies() {
            const courseId = document.getElementById('courseFilter').value;
            const unitFilter = document.getElementById('unitFilter').value;
            const search = document.getElementById('searchInput').value.toLowerCase().trim();

            const sections = document.querySelectorAll('.accordion-section');
            sections.forEach(section => {
                const sectionCourse = section.dataset.courseId || '';
                let showSection = true;
                if (courseId && sectionCourse != courseId) showSection = false;

                const unitCards = section.querySelectorAll('.unit-card');
                let visibleUnitCount = 0;
                
                unitCards.forEach(card => {
                    const unitType = card.dataset.unit || '';
                    let showCard = true;
                    
                    if (unitFilter && unitType !== unitFilter) showCard = false;
                    
                    const rows = card.querySelectorAll('.competency-table tbody tr:not(.empty-row)');
                    let visibleRowCount = 0;
                    rows.forEach(row => {
                        const rowName = row.dataset.name || '';
                        const rowCode = row.dataset.code || '';
                        let showRow = true;
                        
                        if (search) {
                            const searchMatch = rowName.includes(search) || rowCode.includes(search);
                            const compId = row.dataset.competencyId || '';
                            if (compId && tasksByCompetency[compId]) {
                                const taskMatch = tasksByCompetency[compId].some(t => 
                                    (t.task_name || '').toLowerCase().includes(search)
                                );
                                if (!searchMatch && !taskMatch) showRow = false;
                            } else if (!searchMatch) {
                                showRow = false;
                            }
                        }
                        
                        row.style.display = showRow ? '' : 'none';
                        if (showRow) visibleRowCount++;
                    });

                    const emptyRow = card.querySelector('.empty-row');
                    if (emptyRow) {
                        emptyRow.style.display = (visibleRowCount === 0) ? '' : 'none';
                    }

                    if (search && visibleRowCount === 0) {
                        showCard = false;
                    }

                    card.style.display = showCard ? '' : 'none';
                    if (showCard) visibleUnitCount++;
                });

                if (visibleUnitCount === 0) {
                    showSection = false;
                }

                section.style.display = showSection ? '' : 'none';
            });
        }

        // ==========================================
        // DOM READY
        // ==========================================
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('courseFilter').addEventListener('change', filterCompetencies);
            document.getElementById('unitFilter').addEventListener('change', filterCompetencies);
            document.getElementById('searchInput').addEventListener('input', filterCompetencies);
            
            document.getElementById('resetFilters').addEventListener('click', function() {
                document.getElementById('courseFilter').value = '';
                document.getElementById('unitFilter').value = '';
                document.getElementById('searchInput').value = '';
                filterCompetencies();
            });

            // Open first qualification and its first unit by default
            const firstQualBody = document.querySelector('.accordion-body');
            if (firstQualBody) {
                firstQualBody.classList.add('open');
                const qualToggle = firstQualBody.previousElementSibling?.querySelector('.qual-toggle i');
                if (qualToggle) {
                    qualToggle.classList.remove('fa-chevron-down');
                    qualToggle.classList.add('fa-chevron-up');
                }
                
                const firstUnitContainer = firstQualBody.querySelector('.unit-table-container');
                if (firstUnitContainer) {
                    firstUnitContainer.classList.add('open');
                    const unitToggle = firstUnitContainer.previousElementSibling?.querySelector('.unit-toggle i');
                    if (unitToggle) {
                        unitToggle.classList.remove('fa-chevron-down');
                        unitToggle.classList.add('fa-chevron-up');
                    }
                }
            }
        });
    </script>
<script src="/tesda_compliance/assets/js/seamless-nav.js"></script>

<script data-no-instant>
  // InstantClick.init(); // DISABLED
</script>
<script src="/tesda_compliance/assets/js/jquery.min.js"></script>
<script src="/tesda_compliance/assets/js/bootstrap.bundle.min.js"></script>
<script src="/tesda_compliance/assets/js/app.js"></script>
</body>
</html>