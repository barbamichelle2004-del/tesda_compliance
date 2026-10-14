<?php
// non_compliance_report.php - Non-Compliant Trainees as Core Competency Matrix
// TRANSPOSED LAYOUT: Trainees across the top, Competency/Task down the left
require_once __DIR__ . '/../config/config.php';

$host = 'localhost';
$dbname = 'tesda_compliance';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ============================================================
// RBAC — ROLE-BASED ACCESS CONTROL
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

$roleString = strtolower(trim(
    $_SESSION['role'] 
    ?? $_SESSION['user_role'] 
    ?? $_SESSION['user_type'] 
    ?? $_SESSION['position'] 
    ?? ''
));

if ($currentUserId === 0 && !empty(session_id())) {
    try {
        $lookupStmt = $pdo->prepare("
            SELECT id, username, full_name, role 
            FROM users 
            WHERE current_session_id = :sid 
              AND status = 'active' 
            LIMIT 1
        ");
        $lookupStmt->execute([':sid' => session_id()]);
        $dbUser = $lookupStmt->fetch(PDO::FETCH_ASSOC);

        if ($dbUser) {
            $currentUserId = (int)$dbUser['id'];
            $roleString = strtolower(trim($dbUser['role'] ?? ''));
            $_SESSION['user_id'] = $currentUserId;
            $_SESSION['role'] = $dbUser['role'];
            $_SESSION['full_name'] = $dbUser['full_name'];
            $_SESSION['username'] = $dbUser['username'];
        }
    } catch (Exception $e) {
        // silent
    }
}

$coordinatorKeywords = ['coordinator', 'admin', 'head', 'supervisor', 'tesda'];

$isCoordinator = false;
if ($roleString !== '') {
    foreach ($coordinatorKeywords as $kw) {
        if (strpos($roleString, $kw) !== false) {
            $isCoordinator = true;
            break;
        }
    }
}

$trainerQualificationIds = [];
$isTrainerRestricted = false;

if (!$isCoordinator && $currentUserId > 0) {
    try {
        $assignStmt = $pdo->prepare("
            SELECT DISTINCT qualification_id 
            FROM user_qualifications 
            WHERE user_id = :user_id
        ");
        $assignStmt->execute([':user_id' => $currentUserId]);
        $trainerQualificationIds = $assignStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        if (!empty($trainerQualificationIds)) {
            $isTrainerRestricted = true;
        }
    } catch (Exception $e) {
        // silent
    }
}

$traineeScopeSql = "";
if (!$isCoordinator) {
    if (empty($trainerQualificationIds)) {
        $traineeScopeSql = " AND 1=0 ";
    } else {
        $idList = implode(',', array_map('intval', $trainerQualificationIds));
        $traineeScopeSql = " AND t.qualification_id IN ($idList) ";
    }
}

$qualificationScopeSql = "";
if (!$isCoordinator) {
    if (empty($trainerQualificationIds)) {
        $qualificationScopeSql = " AND 1=0 ";
    } else {
        $idList = implode(',', array_map('intval', $trainerQualificationIds));
        $qualificationScopeSql = " AND q.id IN ($idList) ";
    }
}
// ============================================================
// END RBAC
// ============================================================

$pageTitle = 'Non-Compliance List';

// ============================================================
// STEP 1: Fetch all CORE competency standards
// ============================================================
$coreCompetencyQuery = "
    SELECT 
        cs.id AS competency_id,
        cs.competency_name,
        cs.unit_of_competency,
        cs.qualification_id,
        q.qualification_name,
        q.qualification_code
    FROM competency_standards cs
    INNER JOIN qualifications q ON cs.qualification_id = q.id
    WHERE LOWER(cs.unit_of_competency) = 'core'
      $qualificationScopeSql
    ORDER BY q.qualification_name, cs.id
";
$coreCompetencyStmt = $pdo->query($coreCompetencyQuery);
$allCoreCompetencies = $coreCompetencyStmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

$coreCompetenciesByQual = [];
$qualificationMeta = [];
foreach ($allCoreCompetencies as $comp) {
    $qualId = (int)$comp['qualification_id'];
    if (!isset($coreCompetenciesByQual[$qualId])) {
        $coreCompetenciesByQual[$qualId] = [];
        $qualificationMeta[$qualId] = [
            'qualification_name' => $comp['qualification_name'],
            'qualification_code' => $comp['qualification_code'],
        ];
    }
    $coreCompetenciesByQual[$qualId][] = [
        'competency_id' => (int)$comp['competency_id'],
        'competency_title' => $comp['competency_name'],
    ];
}

// ============================================================
// STEP 2: Preload ALL tasks per competency
// ============================================================
$tasksByCompetency = [];
$tasksStmt = $pdo->query("
    SELECT id AS task_id, competency_id, task_name 
    FROM performance_tasks 
    ORDER BY competency_id, id
");
foreach ($tasksStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
    $compId = (int)$t['competency_id'];
    if (!isset($tasksByCompetency[$compId])) $tasksByCompetency[$compId] = [];
    $tasksByCompetency[$compId][(int)$t['task_id']] = $t['task_name'];
}

// ============================================================
// STEP 3: Fetch all non-deleted trainees with a qualification
// ============================================================
$traineesQuery = "
    SELECT 
        t.id AS trainee_id,
        t.first_name,
        t.last_name,
        t.department,
        t.status,
        t.qualification_id,
        t.deleted_at
    FROM trainees t
    WHERE t.qualification_id IS NOT NULL 
      AND t.qualification_id > 0
      AND t.deleted_at IS NULL
      AND (t.status IS NULL OR t.status != 'deleted')
      $traineeScopeSql
    ORDER BY t.department, t.last_name, t.first_name
";
$traineesStmt = $pdo->query($traineesQuery);
$allTrainees = $traineesStmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

// ============================================================
// STEP 4: Evaluation tracker — build [trainee_id][task_id] = isComplied
// ============================================================
$evalByTraineeTask = [];
$evalStmt = $pdo->query("
    SELECT et.trainee_id, et.task_id, et.status, et.remarks
    FROM evaluation_tracker et
");
foreach ($evalStmt->fetchAll(PDO::FETCH_ASSOC) as $ev) {
    $tid    = (int)$ev['trainee_id'];
    $taskId = (int)$ev['task_id'];
    $sL     = strtolower($ev['status'] ?? '');
    $rL     = strtolower($ev['remarks'] ?? '');
    $isComplied = ($sL === 'complied' || $sL === 'done' || $sL === 'competent' || strpos($rL, 'done') !== false);
    $evalByTraineeTask[$tid][$taskId] = $isComplied;
}

// ============================================================
// STEP 5: Build transposed matrix
//   Group: Department → Qualification → Trainees (only those WITH deficiency)
// ============================================================
$deptDisplay = [
    'HRS' => ['name' => 'HOTEL AND RESTAURANT SERVICES', 'color' => '#8B5CF6', 'icon' => 'fa-utensils'],
    'CSS' => ['name' => 'COMPUTER SYSTEMS SERVICING', 'color' => '#10B981', 'icon' => 'fa-laptop-code'],
    'ATS' => ['name' => 'AUTOMOTIVE MECHANICS', 'color' => '#F59E0B', 'icon' => 'fa-car'],
    'OTHER' => ['name' => 'OTHER DEPARTMENTS', 'color' => '#6B7280', 'icon' => 'fa-building']
];

function normalizeDeptCode($dept) {
    $d = strtoupper(trim($dept ?? ''));
    if ($d === '') return 'OTHER';
    if (strpos($d, 'HRS') !== false || strpos($d, 'HOTEL') !== false) return 'HRS';
    if (strpos($d, 'CSS') !== false || strpos($d, 'COMPUTER') !== false) return 'CSS';
    if (strpos($d, 'ATS') !== false || strpos($d, 'AUTOMOTIVE') !== false) return 'ATS';
    return 'OTHER';
}

$matrixData = []; // [dept][qualId] = ['meta'=>..., 'trainees'=>[tid=>name]]
$nonCompliantTraineeIds = [];

foreach ($allTrainees as $trainee) {
    $traineeId = (int)$trainee['trainee_id'];
    $qualId    = (int)$trainee['qualification_id'];

    if (!isset($coreCompetenciesByQual[$qualId]) || empty($coreCompetenciesByQual[$qualId])) continue;

    $competencies = $coreCompetenciesByQual[$qualId];
    $hasDeficiency = false;

    foreach ($competencies as $comp) {
        $compId = (int)$comp['competency_id'];
        $tasks  = $tasksByCompetency[$compId] ?? [];
        if (empty($tasks)) { $hasDeficiency = true; break; }
        foreach ($tasks as $taskId => $taskName) {
            $isComplied = $evalByTraineeTask[$traineeId][$taskId] ?? false;
            if (!$isComplied) { $hasDeficiency = true; break 2; }
        }
    }
    if (!$hasDeficiency) continue;

    $nonCompliantTraineeIds[] = $traineeId;

    $deptCode = normalizeDeptCode($trainee['department'] ?? '');
    $traineeName = trim(($trainee['first_name'] ?? '') . ' ' . ($trainee['last_name'] ?? ''));
    if (empty($traineeName)) $traineeName = 'Unknown Trainee';

    if (!isset($matrixData[$deptCode])) $matrixData[$deptCode] = [];
    if (!isset($matrixData[$deptCode][$qualId])) {
        $matrixData[$deptCode][$qualId] = [
            'meta'     => $qualificationMeta[$qualId],
            'trainees' => []
        ];
    }
    $matrixData[$deptCode][$qualId]['trainees'][$traineeId] = $traineeName;
}

$nonCompliantTraineeIds = array_unique($nonCompliantTraineeIds);
$totalNonCompliantTrainees = count($nonCompliantTraineeIds);

// Count non-compliant task cells
$totalNonCompliantCells = 0;
foreach ($matrixData as $dept => $quals) {
    foreach ($quals as $qualId => $group) {
        $competencies = $coreCompetenciesByQual[$qualId] ?? [];
        foreach ($group['trainees'] as $tId => $tName) {
            foreach ($competencies as $comp) {
                $compId = (int)$comp['competency_id'];
                $tasks  = $tasksByCompetency[$compId] ?? [];
                foreach ($tasks as $taskId => $taskName) {
                    if (!($evalByTraineeTask[$tId][$taskId] ?? false)) {
                        $totalNonCompliantCells++;
                    }
                }
            }
        }
    }
}

// Sort departments
$deptOrder = ['CSS' => 1, 'HRS' => 2, 'ATS' => 3, 'OTHER' => 4];
uksort($matrixData, function($a, $b) use ($deptOrder) {
    return ($deptOrder[$a] ?? 99) - ($deptOrder[$b] ?? 99);
});

if (!function_exists('getCurrentUser')) {
    function getCurrentUser() { return ['full_name' => 'Trainer / Coordinator', 'username' => 'trainer']; }
}
if (!function_exists('getBaseUrl')) {
    function getBaseUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $script_name = $_SERVER['SCRIPT_NAME'];
        $base_path = rtrim(dirname(dirname($script_name)), '/') . '/';
        if (strpos($base_path, '/reports/') !== false) $base_path = str_replace('/reports/', '/', $base_path);
        return $protocol . $host . $base_path;
    }
}

$user = getCurrentUser();
$base_url = getBaseUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TESDA – <?= htmlspecialchars($pageTitle) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/tesda_compliance/assets/vendor/fontawesome/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: #f0f2f5; min-width: 1200px; overflow-x: auto; margin: 0; transition: all 0.3s ease; }
        body.sidebar-collapsed { min-width: 992px; }
        .main-content { margin-left: 280px; padding: 30px; width: calc(100% - 280px); min-width: 920px; transition: margin-left 0.3s ease, width 0.3s ease; animation: fadeInPage 0.4s ease-out; }
        @keyframes fadeInPage { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        body.sidebar-collapsed .main-content { margin-left: 80px; width: calc(100% - 80px); }

        .header { background: white; border-radius: 24px; padding: 25px 35px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 10px 40px rgba(0,0,0,0.08); border: 1px solid rgba(255,255,255,0.2); background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); animation: slideDown 0.5s ease-out; flex-wrap: wrap; gap: 15px; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        .header-left h1 { font-size: 1.8rem; font-weight: 700; background: linear-gradient(135deg, #1e293b, #ef4444); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; display: flex; align-items: center; gap: 12px; }
        .header-left h1 i { background: linear-gradient(135deg, #ef4444, #dc2626); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; font-size: 1.8rem; }
        .header-left p { color: #64748b; font-size: 1rem; margin-top: 4px; }
        .header-right { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }
        .role-badge { background: linear-gradient(135deg, #f8fafc, #f1f5f9); padding: 12px 28px; border-radius: 50px; display: flex; align-items: center; gap: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; transition: all 0.3s ease; }
        .role-badge:hover { transform: scale(1.02); }
        .role-badge i { font-size: 1.4rem; color: #ef4444; }
        .role-badge .role-name { font-weight: 600; font-size: 1.1rem; color: #1e293b; letter-spacing: 0.3px; }
        .date-badge { background: #f0f2f5; padding: 12px 24px; border-radius: 12px; color: #1a1c2c; font-weight: 500; font-size: 1rem; transition: all 0.3s ease; }
        .date-badge:hover { transform: rotate(3deg) scale(1.02); background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
        .btn-back { background: #f1f5f9; color: #475569; border: 2px solid #e2e8f0; padding: 12px 24px; border-radius: 12px; cursor: pointer; font-weight: 600; font-size: 0.95rem; display: inline-flex; align-items: center; gap: 10px; transition: all 0.3s ease; text-decoration: none; }
        .btn-back:hover { background: #e2e8f0; transform: translateY(-2px); }
        .btn-back i { color: #ef4444; }

        .non-compliance-card { background: #ffffff; border-radius: 16px; padding: 24px 28px; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.04); border: 1px solid #f1f5f9; animation: fadeInUp 0.6s ease-out 0.2s both; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

        .card-header-section { display: flex; align-items: center; gap: 14px; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #f1f5f9; }
        .icon-box { width: 44px; height: 44px; border-radius: 12px; background: #FEE2E2; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .icon-box i { font-size: 1.2rem; color: #DC2626; }
        .card-title { font-size: 1.1rem; font-weight: 700; color: #1E293B; letter-spacing: 0.3px; margin: 0; line-height: 1.3; }
        .card-subtitle { font-size: 0.85rem; color: #64748B; margin: 0; font-weight: 400; }
        .summary-bar { display: flex; gap: 30px; padding: 12px 20px; background: #F8FAFC; border-radius: 10px; margin-bottom: 20px; flex-wrap: wrap; border: 1px solid #e8edf5; }
        .summary-item { display: flex; align-items: center; gap: 8px; font-size: 0.85rem; color: #1E293B; }
        .summary-item i { font-size: 0.9rem; }
        .summary-item .count { font-weight: 700; font-size: 1.1rem; color: #DC2626; }
        .summary-item .label { color: #64748B; }

        .department-section { margin-bottom: 28px; border: 1px solid #e8edf5; border-radius: 12px; overflow: hidden; }
        .department-section:last-child { margin-bottom: 0; }
        .department-header { padding: 14px 20px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .department-header .dept-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1rem; flex-shrink: 0; }
        .department-header .dept-name { font-weight: 700; font-size: 1rem; color: white; text-transform: uppercase; letter-spacing: 0.3px; }
        .department-header .dept-count { margin-left: auto; font-size: 0.8rem; color: white; background: rgba(255,255,255,0.25); padding: 2px 14px; border-radius: 20px; font-weight: 500; }

        .qualification-section { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; }
        .qualification-section:last-child { border-bottom: none; }
        .qualification-header { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
        .qualification-header .qual-icon { color: #8B5CF6; font-size: 0.9rem; }
        .qualification-header .qual-name { font-weight: 600; font-size: 0.95rem; color: #1E293B; }
        .qualification-header .qual-code { font-size: 0.75rem; color: #94A3B8; font-weight: 400; background: #f1f5f9; padding: 1px 10px; border-radius: 12px; }
        .qualification-header .qual-count { margin-left: auto; font-size: 0.75rem; color: #64748B; background: #f1f5f9; padding: 2px 12px; border-radius: 20px; font-weight: 500; }

        /* ============================================================
           TRANSPOSED MATRIX TABLE
           First column = Competency / Performance Task
           Trainee columns = one per trainee
           ============================================================ */
        .matrix-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; }
        .matrix-table thead { background: #f8fafc; }
        .matrix-table th { text-align: center; padding: 10px 8px; font-weight: 700; color: #1e293b; border-bottom: 2px solid #e2e8f0; border-right: 1px solid #e9eef5; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; vertical-align: middle; line-height: 1.35; word-wrap: break-word; overflow-wrap: break-word; }
        .matrix-table th:last-child { border-right: none; }
        .matrix-table th.col-task {
            text-align: left;
            min-width: 320px;
            max-width: 420px;
            background: #f1f5f9;
            position: sticky;
            left: 0;
            z-index: 2;
        }
        .matrix-table th.col-trainee {
            min-width: 90px;
            max-width: 130px;
            background: #f8fafc;
        }

        .matrix-table td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; border-right: 1px solid #f1f5f9; color: #1E293B; vertical-align: middle; }
        .matrix-table td:last-child { border-right: none; }
        .matrix-table tbody tr:last-child td { border-bottom: none; }

        .matrix-table td.col-task {
            text-align: left;
            font-size: 0.78rem;
            color: #334155;
            background: #ffffff;
            position: sticky;
            left: 0;
            z-index: 1;
            padding: 8px 10px;
            line-height: 1.35;
        }
        .matrix-table td.col-trainee {
            text-align: center;
            font-size: 1rem;
            font-weight: 700;
            width: 90px;
        }
        .matrix-table td.cell-compliant {
            background: #d1fae5;
            color: #059669;
        }
        .matrix-table td.cell-not-compliant {
            background: #fee2e2;
            color: #dc2626;
        }

        /* Competency separator row */
        .competency-row td {
            background: #cbd5e1 !important;
            color: #0f172a;
            font-weight: 700;
            font-size: 0.78rem;
            letter-spacing: 0.02em;
            padding: 8px 10px;
            text-align: left;
        }

        .empty-state { text-align: center; padding: 50px 20px; color: #94A3B8; }
        .empty-state i { font-size: 3.5rem; color: #10B981; margin-bottom: 16px; display: block; }
        .empty-state h3 { font-size: 1.3rem; color: #1E293B; margin-bottom: 8px; }
        .empty-state p { font-size: 1rem; }

        @media (max-width: 1200px) { .main-content { margin-left: 80px; width: calc(100% - 80px); } body { min-width: 992px; } }
        @media (max-width: 768px) {
            body { min-width: auto; }
            .main-content { margin-left: 0; width: 100%; min-width: auto; padding: 15px; }
            body.sidebar-collapsed .main-content { margin-left: 0; width: 100%; }
            .header { flex-direction: column; align-items: flex-start; gap: 15px; }
            .header-right { flex-wrap: wrap; width: 100%; }
            .non-compliance-card { padding: 16px 18px; }
            .matrix-table { font-size: 0.7rem; }
            .matrix-table th, .matrix-table td { padding: 5px 6px; }
            .matrix-table th.col-task, .matrix-table td.col-task { min-width: 180px; max-width: 220px; }
            .matrix-table th.col-trainee, .matrix-table td.col-trainee { min-width: 70px; max-width: 90px; }
            .department-header { padding: 10px 16px; }
            .qualification-section { padding: 12px 16px; }
        }
        @media (max-width: 576px) {
            .header { padding: 15px 20px; }
            .header-left h1 { font-size: 1.3rem; }
            .non-compliance-card { padding: 12px 14px; }
        }
    </style>
<link rel="stylesheet" href="/tesda_compliance/assets/vendor/fonts/poppins/poppins.css">
<link rel="stylesheet" href="/tesda_compliance/assets/vendor/fonts/poppins/poppins.css">
<link rel="stylesheet" href="/tesda_compliance/assets/vendor/fonts/poppins/poppins.css">
<link rel="stylesheet" href="/tesda_compliance/assets/css/bootstrap.min.css">
<!-- <link rel="stylesheet" href="/tesda_compliance/assets/css/style.css"> -->
<link rel="stylesheet" href="/tesda_compliance/assets/css/all.min.css">
</head>
<body>
    <?php include __DIR__ . '/../sidebar.php'; ?>

    <div class="main-content">
        <div class="header">
            <div class="header-left">
                <h1><i class="fas fa-list-ul"></i> <?= htmlspecialchars($pageTitle) ?></h1>
                <p>Core competency matrix for non-compliant trainees (transposed layout)</p>
            </div>
            <div class="header-right">
                <div class="role-badge">
                    <i class="fas fa-user-circle"></i>
                    <span class="role-name"><?= htmlspecialchars($user['full_name'] ?? $user['username'] ?? 'Trainer / Coordinator') ?></span>
                </div>
                <div class="date-badge">
                    <i class="far fa-calendar-alt"></i> <?= date('F j, Y') ?>
                </div>
                <a href="<?= $base_url ?>reports/reports.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Reports
                </a>
            </div>
        </div>

        <div class="non-compliance-card">
            <div class="card-header-section">
                <div class="icon-box">
                    <i class="fas fa-list-ul"></i>
                </div>
                <div>
                    <h3 class="card-title">NON-COMPLIANCE LIST</h3>
                    <p class="card-subtitle">✓ = Complied &nbsp;·&nbsp; ✗ = Not Complied / Pending</p>
                </div>
            </div>

            <div class="summary-bar">
                <div class="summary-item">
                    <i class="fas fa-user-graduate" style="color: #8B5CF6;"></i>
                    <span class="count"><?= $totalNonCompliantTrainees ?></span>
                    <span class="label">non-compliant trainee(s)</span>
                </div>
                <div class="summary-item">
                    <i class="fas fa-times-circle" style="color: #F59E0B;"></i>
                    <span class="count"><?= $totalNonCompliantCells ?></span>
                    <span class="label">non-compliant task cell(s)</span>
                </div>
            </div>

            <?php if (empty($matrixData)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <h3>All Tasks Complied!</h3>
                    <p>No non-compliant trainees found. All tasks are in compliance.</p>
                </div>
            <?php else: ?>
                <?php foreach ($matrixData as $deptCode => $qualGroups): 
                    $deptInfo = $deptDisplay[$deptCode] ?? $deptDisplay['OTHER'];
                    $totalDeptTrainees = 0;
                    foreach ($qualGroups as $qualId => $group) $totalDeptTrainees += count($group['trainees']);
                ?>
                    <div class="department-section">
                        <div class="department-header" style="background: <?= $deptInfo['color'] ?>;">
                            <div class="dept-icon" style="background: rgba(255,255,255,0.25);">
                                <i class="fas <?= $deptInfo['icon'] ?>"></i>
                            </div>
                            <span class="dept-name"><?= htmlspecialchars($deptInfo['name']) ?></span>
                            <span class="dept-count"><?= $totalDeptTrainees ?> trainee(s)</span>
                        </div>

                        <?php foreach ($qualGroups as $qualId => $group): 
                            $meta         = $group['meta'];
                            $trainees     = $group['trainees']; // [traineeId => name]
                            $competencies = $coreCompetenciesByQual[$qualId] ?? [];
                            $qualName     = $meta['qualification_name'];
                            $qualCode     = $meta['qualification_code'];
                            $traineeCount = count($trainees);
                        ?>
                            <div class="qualification-section">
                                <div class="qualification-header">
                                    <i class="fas fa-certificate qual-icon"></i>
                                    <span class="qual-name"><?= htmlspecialchars($qualName) ?></span>
                                    <?php if (!empty($qualCode)): ?>
                                        <span class="qual-code"><?= htmlspecialchars($qualCode) ?></span>
                                    <?php endif; ?>
                                    <span class="qual-count">
                                        <?= $traineeCount ?> trainee(s),
                                        <?= count($competencies) ?> core competency(ies)
                                    </span>
                                </div>

                                <div class="table-responsive" style="overflow-x:auto;">
                                    <table class="matrix-table">
                                        <thead>
                                            <tr>
                                                <th class="col-task">COMPETENCY / PERFORMANCE TASK</th>
                                                <?php foreach ($trainees as $tid => $tname): ?>
                                                    <th class="col-trainee" title="<?= htmlspecialchars($tname) ?>">
                                                        <?= htmlspecialchars($tname) ?>
                                                    </th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($competencies as $comp): 
                                                $compId = (int)$comp['competency_id'];
                                                $cname  = $comp['competency_title'];
                                                $tasks  = $tasksByCompetency[$compId] ?? [];
                                            ?>
                                                <!-- Competency header row -->
                                                <tr class="competency-row">
                                                    <td colspan="<?= $traineeCount + 1 ?>">
                                                        <?= htmlspecialchars($cname) ?>
                                                    </td>
                                                </tr>

                                                <?php if (empty($tasks)): ?>
                                                    <tr>
                                                        <td class="col-task" style="font-style:italic;color:#94a3b8;">
                                                            (No tasks defined for this competency)
                                                        </td>
                                                        <?php foreach ($trainees as $tid => $tname): ?>
                                                            <td class="col-trainee cell-not-compliant">✗</td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php else: ?>
                                                    <?php foreach ($tasks as $taskId => $taskName): ?>
                                                        <tr>
                                                            <td class="col-task">
                                                                <?= htmlspecialchars($taskName) ?>
                                                            </td>
                                                            <?php foreach ($trainees as $tid => $tname): 
                                                                $isComplied = $evalByTraineeTask[$tid][$taskId] ?? false;
                                                                $cls = $isComplied ? 'cell-compliant' : 'cell-not-compliant';
                                                                $mark = $isComplied ? '✓' : '✗';
                                                            ?>
                                                                <td class="col-trainee <?= $cls ?>"
                                                                    title="<?= $isComplied ? 'Complied' : 'Not Complied' ?>">
                                                                    <?= $mark ?>
                                                                </td>
                                                            <?php endforeach; ?>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Non-Compliance Matrix (Transposed) loaded successfully.');
            console.log('Total Non-Compliant Trainees: <?= $totalNonCompliantTrainees ?>');
            console.log('Total Non-Compliant Cells: <?= $totalNonCompliantCells ?>');
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