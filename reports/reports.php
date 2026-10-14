<?php
// reports.php - Main Reports Dashboard

// ============================================================
// SESSION — must match login.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_SESSION_ID');
    session_start();
}

require_once __DIR__ . '/../config/config.php';

$user = getCurrentUser();
$current_page = basename($_SERVER['PHP_SELF']);

if (!function_exists('getBaseUrl')) {
    function getBaseUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $script_name = $_SERVER['SCRIPT_NAME'];
        $base_path = rtrim(dirname(dirname($script_name)), '/') . '/';
        if (strpos($base_path, '/reports/') !== false) {
            $base_path = str_replace('/reports/', '/', $base_path);
        }
        return $protocol . $host . $base_path;
    }
}

$base_url = getBaseUrl();

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
// ROLE-BASED ACCESS CONTROL
// ============================================================
$currentUserId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);

$roleString = strtolower(trim(
    $_SESSION['role'] 
    ?? $_SESSION['user_role'] 
    ?? $_SESSION['user_type'] 
    ?? $_SESSION['position'] 
    ?? ''
));

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

// Trainer: fetch all qualification_ids assigned to this user
$trainerQualificationIds = [];
$isTrainerRestricted = false;

if (!$isCoordinator) {
    if ($currentUserId > 0) {
        $assignStmt = $pdo->prepare("
            SELECT DISTINCT qualification_id 
            FROM user_qualifications 
            WHERE user_id = :user_id
        ");
        $assignStmt->execute([':user_id' => $currentUserId]);
        $trainerQualificationIds = $assignStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
    $isTrainerRestricted = true;
}

// Scope fragment for trainee-level queries
$traineeScopeSql = "";
if (!$isCoordinator) {
    if (empty($trainerQualificationIds)) {
        $traineeScopeSql = " AND 1=0 ";
    } else {
        $idList = implode(',', array_map('intval', $trainerQualificationIds));
        $traineeScopeSql = " AND t.qualification_id IN ($idList) ";
    }
}

// Scope fragment for qualification-level queries
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
// REAL-TIME METRICS — WITH SOFT-DELETE + COMPLETED FILTER
// ============================================================

// Card 1: Total Trainees (ACTIVE ONLY)
$totalTrainees = $pdo->query("
    SELECT COUNT(*) AS total 
    FROM trainees t
    WHERE t.deleted_at IS NULL
      AND (t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))
      $traineeScopeSql
")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Card 2: Batch Trainees
$batchTrainees = $pdo->query("
    SELECT COUNT(*) AS total 
    FROM trainees t
    WHERE t.status IS NOT NULL AND t.status != '' 
      AND t.status != 'deleted' AND t.status != 'Completed'
      AND t.deleted_at IS NULL
      $traineeScopeSql
")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Card 3: Individual Trainees
$individualTrainees = $pdo->query("
    SELECT COUNT(*) AS total 
    FROM trainees t
    WHERE t.deleted_at IS NULL
      AND (t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))
      $traineeScopeSql
")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Card 4: Non-Compliant Trainees
$nonCompliantTrainees = $pdo->query("
    SELECT COUNT(DISTINCT et.trainee_id) AS total 
    FROM evaluation_tracker et
    JOIN trainees t ON t.id = et.trainee_id
    WHERE t.deleted_at IS NULL
      AND (t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))
      AND (LOWER(et.status) = 'not_complied' OR LOWER(et.remarks) != 'done')
      $traineeScopeSql
")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$traineesWithNoRecords = $pdo->query("
    SELECT COUNT(DISTINCT t.id) AS total
    FROM trainees t
    WHERE t.qualification_id IS NOT NULL
      AND t.qualification_id > 0
      AND t.deleted_at IS NULL
      AND (t.status IS NULL OR (t.status != 'deleted' AND t.status != 'Completed'))
      AND NOT EXISTS (
          SELECT 1 FROM evaluation_tracker et 
          WHERE et.trainee_id = t.id
      )
      $traineeScopeSql
")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$nonCompliantTrainees += $traineesWithNoRecords;

$fullyCompliantTrainees = max(0, $totalTrainees - $nonCompliantTrainees);
$overallCompliance = $totalTrainees > 0 ? round(($fullyCompliantTrainees / $totalTrainees) * 100) : 0;

if ($totalTrainees == 0) {
    $complianceSubtext = "No trainees registered";
} else {
    $complianceSubtext = $fullyCompliantTrainees . " out of " . number_format($totalTrainees) . " trainees fully compliant";
}

$batchCount = $pdo->query("
    SELECT COUNT(DISTINCT t.status) AS total 
    FROM trainees t
    WHERE t.status IS NOT NULL AND t.status != '' 
      AND t.status != 'deleted' AND t.status != 'Completed'
      AND t.deleted_at IS NULL
      $traineeScopeSql
")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$complianceColor = $overallCompliance >= 80 ? '#10b981' : ($overallCompliance >= 50 ? '#f59e0b' : '#ef4444');

$currentDepartment = isset($_GET['department']) ? $_GET['department'] : '';
$currentQualification = isset($_GET['qualification']) ? $_GET['qualification'] : '';
$currentSearch = isset($_GET['search']) ? $_GET['search'] : '';
$currentBatch = isset($_GET['batch_id']) ? $_GET['batch_id'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TESDA Reports Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/tesda_compliance/assets/vendor/fontawesome/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="/tesda_compliance/assets/js/jquery-3.6.0.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: #f0f2f5; min-width: 1200px; overflow-x: auto; margin: 0; transition: all 0.3s ease; }
        body.sidebar-collapsed { min-width: 992px; }
        .main-content { margin-left: 280px; padding: 30px; width: calc(100% - 280px); min-width: 920px; transition: margin-left 0.3s ease, width 0.3s ease; }
        body.sidebar-collapsed .main-content { margin-left: 80px; width: calc(100% - 80px); }
        .header { background: white; border-radius: 24px; padding: 25px 35px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 10px 40px rgba(0,0,0,0.08); border: 1px solid rgba(255,255,255,0.2); background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); flex-wrap: wrap; gap: 15px; }
        .header-left h1 { font-size: 1.8rem; font-weight: 700; color: #000000; display: flex; align-items: center; gap: 12px; }
        .header-left h1 i { background: linear-gradient(135deg, #8b5cf6, #6d28d9); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; font-size: 1.8rem; }
        .header-left p { color: #64748b; font-size: 1rem; margin-top: 4px; }
        .header-right { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }
        .date-badge { background: #f0f2f5; padding: 12px 24px; border-radius: 12px; color: #1a1c2c; font-weight: 500; font-size: 1rem; transition: all 0.3s ease; display: flex; align-items: center; gap: 10px; }
        .date-badge:hover { transform: rotate(3deg) scale(1.02); background: linear-gradient(135deg, #667eea, #764ba2); color: white; }

        .btn-export-dropdown { background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; padding: 12px 24px; border-radius: 12px; font-weight: 600; font-size: 1rem; display: flex; align-items: center; gap: 10px; transition: all 0.3s ease; cursor: pointer; text-decoration: none; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
        .btn-export-dropdown:hover { background: linear-gradient(135deg, #059669, #047857); transform: translateY(-2px); box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4); color: white; }
        .btn-export-dropdown i { font-size: 1rem; }
        .btn-export-dropdown .dropdown-icon { font-size: 0.7rem; transition: transform 0.3s ease; margin-left: 4px; }
        .btn-export-dropdown.show .dropdown-icon { transform: rotate(180deg); }
        .export-dropdown-menu { border: none; border-radius: 16px; padding: 8px 0; box-shadow: 0 15px 50px rgba(0,0,0,0.15); min-width: 260px; margin-top: 8px; }
        .export-dropdown-menu .dropdown-item { padding: 12px 20px; font-size: 0.9rem; font-weight: 500; color: #1e293b; display: flex; align-items: center; gap: 12px; transition: all 0.15s ease; border-left: 3px solid transparent; }
        .export-dropdown-menu .dropdown-item:hover { background: #f8fafc; color: #0f172a; border-left-color: #10b981; }
        .export-dropdown-menu .dropdown-item i { font-size: 1.1rem; color: #94a3b8; width: 24px; text-align: center; }
        .export-dropdown-menu .dropdown-item:hover i { color: #10b981; }
        .export-dropdown-menu .dropdown-divider { margin: 6px 16px; border-color: #f1f5f9; }
        .export-dropdown-menu .dropdown-header { padding: 8px 20px 4px; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 600; }
        .lock-notice { display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px; background: #f1f5f9; border: 1px dashed #cbd5e1; border-radius: 12px; color: #64748b; font-size: 0.85rem; font-weight: 500; }
        .lock-notice i { color: #94a3b8; }
        .hero-progress-card { background: white; border-radius: 24px; padding: 30px 35px; margin-bottom: 30px; box-shadow: 0 10px 40px rgba(0,0,0,0.08); border: 1px solid rgba(255,255,255,0.2); background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); }
        .hero-progress-card .row { display: flex; align-items: center; flex-wrap: wrap; }
        .hero-progress-card .left-section { flex: 0 0 auto; width: 25%; text-align: center; padding-right: 30px; border-right: 2px solid #e2e8f0; }
        .hero-progress-card .right-section { flex: 1; padding-left: 30px; }
        .circular-progress-val { font-size: 3.5rem; font-weight: 700; color: <?= $complianceColor ?>; line-height: 1.2; }
        .circular-progress-val small { font-size: 0.9rem; color: #94a3b8; font-weight: 400; }
        .hero-progress-card .progress { height: 10px; background: #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 16px; }
        .hero-progress-card .progress-bar { height: 100%; border-radius: 8px; background: <?= $complianceColor ?>; transition: width 0.6s ease; }
        .hero-progress-card .right-section h5 { font-size: 1.1rem; font-weight: 700; color: #1e293b; margin-bottom: 12px; }
        .stat-pills { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 16px; }
        .stat-pill { padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; text-align: center; transition: all 0.3s ease; }
        .stat-pill:hover { background: #f1f5f9; transform: translateY(-2px); }
        .stat-pill .pill-value { display: block; font-size: 1.6rem; font-weight: 700; color: #1e293b; line-height: 1.2; }
        .stat-pill .pill-value.text-success { color: #10b981; }
        .stat-pill .pill-value.text-danger { color: #ef4444; }
        .stat-pill .pill-value.text-info { color: #3b82f6; }
        .stat-pill .pill-value.text-purple { color: #8b5cf6; }
        .stat-pill .pill-label { font-size: 0.72rem; color: #64748b; font-weight: 500; margin-top: 2px; }
        .report-tabs-nav { display: flex; width: 100%; gap: 12px; border-bottom: 2px solid #e2e8f0; padding-bottom: 16px; margin-bottom: 24px; overflow-x: auto; flex-wrap: nowrap; }
        .report-tabs-nav .tab-btn { flex: 1 1 0; display: inline-flex; align-items: center; justify-content: center; padding: 14px 20px; font-size: 0.95rem; font-weight: 600; border: none; border-radius: 50px; cursor: pointer; transition: all 0.3s ease-in-out; white-space: nowrap; background: transparent; color: #64748b; position: relative; gap: 8px; }
        .report-tabs-nav .tab-btn:hover { background: #f1f5f9; color: #1e293b; transform: translateY(-2px); }
        .report-tabs-nav .tab-btn.active { background: #8b5cf6; color: white; box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3); }
        .report-tabs-nav .tab-btn i { font-size: 1.2rem; }
        .report-tabs-nav .tab-btn .badge { display: inline-block; padding: 5px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; margin-left: 4px; }
        .report-tabs-nav .tab-btn .badge.bg-light { background: rgba(255, 255, 255, 0.3); color: #8b5cf6; }
        .report-tabs-nav .tab-btn.active .badge.bg-light { background: rgba(255, 255, 255, 0.25); color: white; }
        .report-tabs-nav .tab-btn .badge.bg-danger { background: #ef4444; color: white; }
        .report-tabs-nav .tab-btn.active .badge.bg-danger { background: rgba(255, 255, 255, 0.25); color: white; }
        #reports-content-display { background: white; border-radius: 20px; padding: 28px 32px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); min-height: 400px; position: relative; }
        .skeleton-wrapper { animation: skeletonFadeIn 0.2s ease-out; }
        @keyframes skeletonFadeIn { from { opacity: 0; } to { opacity: 1; } }
        .skeleton-header { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 2px solid #f1f5f9; }
        .skeleton-icon { width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%); background-size: 200% 100%; animation: shimmer 1.4s infinite; }
        .skeleton-title-block { flex: 1; }
        .skeleton-title { height: 16px; width: 40%; border-radius: 6px; background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%); background-size: 200% 100%; animation: shimmer 1.4s infinite; margin-bottom: 8px; }
        .skeleton-subtitle { height: 12px; width: 25%; border-radius: 6px; background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%); background-size: 200% 100%; animation: shimmer 1.4s infinite; }
        .skeleton-dept-header { height: 46px; border-radius: 8px; background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%); background-size: 200% 100%; animation: shimmer 1.4s infinite; margin-bottom: 18px; }
        .skeleton-row { display: flex; gap: 12px; margin-bottom: 10px; }
        .skeleton-cell { height: 40px; border-radius: 6px; background: linear-gradient(90deg, #e2e8f0 25%, #f1f5f9 50%, #e2e8f0 75%); background-size: 200% 100%; animation: shimmer 1.4s infinite; }
        .skeleton-cell.w-25 { flex: 0 0 25%; }
        .skeleton-cell.w-30 { flex: 0 0 30%; }
        .skeleton-cell.flex-1 { flex: 1; }
        @keyframes shimmer { 0% { background-position: -200% 0; } 100% { background-position: 200% 0; } }
        .loading-bar { position: absolute; top: 0; left: 0; right: 0; height: 3px; background: #e2e8f0; border-radius: 20px 20px 0 0; overflow: hidden; z-index: 10; }
        .loading-bar::after { content: ''; position: absolute; top: 0; left: 0; height: 100%; width: 30%; background: linear-gradient(90deg, #8b5cf6, #a78bfa); border-radius: 20px; animation: loadingSlide 1.2s ease-in-out infinite; }
        @keyframes loadingSlide { 0% { left: -30%; } 100% { left: 100%; } }
        .auto-refresh-badge { display: none !important; }
        @media (max-width: 1200px) { .main-content { margin-left: 80px; width: calc(100% - 80px); } body { min-width: 992px; } }
        @media (max-width: 992px) { .hero-progress-card .left-section { width: 100%; border-right: none; border-bottom: 2px solid #e2e8f0; padding-right: 0; padding-bottom: 20px; margin-bottom: 20px; } .hero-progress-card .right-section { padding-left: 0; } .stat-pills { grid-template-columns: repeat(3, 1fr); } .report-tabs-nav { gap: 8px; } .report-tabs-nav .tab-btn { padding: 12px 16px; font-size: 0.85rem; } }
        @media (max-width: 768px) { body { min-width: auto; } .main-content { margin-left: 0; width: 100%; min-width: auto; padding: 15px; } .header { flex-direction: column; align-items: flex-start; gap: 15px; } .stat-pills { grid-template-columns: 1fr 1fr; } .report-tabs-nav { flex-wrap: wrap; gap: 6px; } }
    </style>
<link rel="stylesheet" href="/tesda_compliance/assets/css/bootstrap.min.css">
<link rel="stylesheet" href="/tesda_compliance/assets/css/all.min.css">
</head>
<body>
    <?php include __DIR__ . '/../sidebar.php'; ?>

    <div class="main-content">
        <div class="header">
            <div class="header-left">
                <h1><i class="fas fa-chart-pie"></i> Reports Dashboard</h1>
                <p>Compliance overview and reporting</p>
            </div>
            <div class="header-right">

                <div class="date-badge"><i class="far fa-calendar-alt"></i> <?= date('F j, Y') ?></div>

                <?php if ($isCoordinator): ?>
                    <div class="dropdown">
                        <button class="btn-export-dropdown dropdown-toggle" type="button" id="exportPdfDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-file-pdf"></i>
                            Export PDF Reports
                            <i class="fas fa-chevron-down dropdown-icon"></i>
                        </button>
                        <ul class="dropdown-menu export-dropdown-menu" aria-labelledby="exportPdfDropdown">
                            <li class="dropdown-header">📋 Select Report Type</li>
                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="exportPDF('individual')"><i class="fas fa-user me-2"></i> Individual Trainee Report</a></li>
                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="exportPDF('summary')"><i class="fas fa-file-alt me-2"></i> Summary Compliance Report</a></li>
                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="exportPDF('non_compliance')"><i class="fas fa-list-ul me-2"></i> Non-Compliance List Report</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="javascript:void(0)" onclick="exportPDF('tesda_inspection')"><i class="fas fa-clipboard-check me-2"></i> TESDA Inspection Audit</a></li>
                        </ul>
                    </div>
                <?php else: ?>
                    <div class="lock-notice" title="Only the TESDA Coordinator is authorized to generate PDF reports.">
                        <i class="fas fa-lock"></i>
                        PDF export is available to Coordinators only
                    </div>
                <?php endif; ?>

                <span class="auto-refresh-badge"><i class="fas fa-circle"></i> Live Data</span>
            </div>
        </div>

        <div class="hero-progress-card">
            <div class="row">
                <div class="left-section">
                    <div class="circular-progress-val">
                        <?= $overallCompliance ?>%
                        <small>Overall Compliance</small>
                    </div>
                    <span style="font-size:0.75rem;color:#94a3b8;display:block;margin-top:4px;">
                        <?= htmlspecialchars($complianceSubtext) ?>
                    </span>
                </div>
                <div class="right-section">
                    <h5><i class="fas fa-chart-line" style="color:#8b5cf6;"></i> Compliance Overview</h5>
                    <div class="progress">
                        <div class="progress-bar" role="progressbar" style="width: <?= $overallCompliance ?>%;"></div>
                    </div>

                    <div class="stat-pills">
                        <div class="stat-pill">
                            <span class="pill-value" id="totalTrainees"><?= number_format($totalTrainees) ?></span>
                            <span class="pill-label">Total Trainees</span>
                        </div>
                        <div class="stat-pill">
                            <span class="pill-value text-info" id="individualTrainees"><?= number_format($individualTrainees) ?></span>
                            <span class="pill-label">Individual Trainees</span>
                        </div>
                        <div class="stat-pill">
                            <span class="pill-value text-danger" id="nonCompliantTrainees"><?= number_format($nonCompliantTrainees) ?></span>
                            <span class="pill-label">Non-Compliant Trainees</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="report-tabs-nav">
            <button class="tab-btn active" data-target="individual" data-report-type="individual">
                <i class="fas fa-user"></i>
                <span>Individual Trainee</span>
            </button>
            <button class="tab-btn" data-target="summary" data-report-type="summary">
                <i class="fas fa-file-alt"></i>
                <span>Summary Compliance</span>
            </button>
            <button class="tab-btn" data-target="non_compliance_detailed" data-report-type="non_compliance">
                <i class="fas fa-list-ul"></i>
                <span>Non-Compliance List</span>
                <span class="badge bg-danger"><?= number_format($nonCompliantTrainees) ?></span>
            </button>
            <button class="tab-btn" data-target="inspection" data-report-type="tesda_inspection">
                <i class="fas fa-clipboard-check"></i>
                <span>TESDA Inspection</span>
            </button>
        </div>

        <div id="reports-content-display">
            <div class="skeleton-wrapper">
                <div class="skeleton-header">
                    <div class="skeleton-icon"></div>
                    <div class="skeleton-title-block">
                        <div class="skeleton-title"></div>
                        <div class="skeleton-subtitle"></div>
                    </div>
                </div>
                <div class="skeleton-dept-header"></div>
                <div class="skeleton-row"><div class="skeleton-cell w-30"></div><div class="skeleton-cell flex-1"></div><div class="skeleton-cell flex-1"></div><div class="skeleton-cell w-25"></div></div>
                <div class="skeleton-row"><div class="skeleton-cell w-30"></div><div class="skeleton-cell flex-1"></div><div class="skeleton-cell flex-1"></div><div class="skeleton-cell w-25"></div></div>
                <div class="skeleton-row"><div class="skeleton-cell w-30"></div><div class="skeleton-cell flex-1"></div><div class="skeleton-cell flex-1"></div><div class="skeleton-cell w-25"></div></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const IS_COORDINATOR = <?= $isCoordinator ? 'true' : 'false' ?>;
        let currentReportType = 'individual';
        let isLoadingReport = false;
        let currentRequest = null;

        $(document).ready(function() {
            loadReportContent('individual');

            $('.tab-btn').on('click', function(e) {
                e.preventDefault();
                if (isLoadingReport) return;
                const targetReport = $(this).data('target');
                if (targetReport === currentReportType && $('#reports-content-display').children().length > 0 && !$('#reports-content-display .skeleton-wrapper').length) return;
                $('.tab-btn').removeClass('active');
                $(this).addClass('active');
                currentReportType = targetReport;
                loadReportContent(targetReport);
            });

            setInterval(function() { if (!document.hidden) refreshStats(); }, 30000);
        });

        function loadReportContent(reportType) {
            if (currentRequest) currentRequest.abort();
            isLoadingReport = true;
            $('#reports-content-display').html(`
                <div class="loading-bar"></div>
                <div class="skeleton-wrapper">
                    <div class="skeleton-header">
                        <div class="skeleton-icon"></div>
                        <div class="skeleton-title-block">
                            <div class="skeleton-title"></div>
                            <div class="skeleton-subtitle"></div>
                        </div>
                    </div>
                    <div class="skeleton-dept-header"></div>
                    <div class="skeleton-row"><div class="skeleton-cell w-30"></div><div class="skeleton-cell flex-1"></div><div class="skeleton-cell flex-1"></div><div class="skeleton-cell w-25"></div></div>
                    <div class="skeleton-row"><div class="skeleton-cell w-30"></div><div class="skeleton-cell flex-1"></div><div class="skeleton-cell flex-1"></div><div class="skeleton-cell w-25"></div></div>
                </div>
            `);
            currentRequest = $.ajax({
                url: 'fetch_report_details.php',
                type: 'GET',
                data: { 
                    report_type: reportType,
                    _t: Date.now()
                },
                dataType: 'html',
                timeout: 30000,
                cache: false,
                success: function(response) {
                    if (!response || !response.trim()) {
                        $('#reports-content-display').html('<div style="text-align:center;padding:40px;color:#64748b;"><i class="fas fa-inbox" style="font-size:2rem;display:block;margin-bottom:12px;opacity:0.5;"></i><h6 style="color:#1e293b;margin-bottom:6px;">No report data</h6><p style="font-size:0.9rem;">The server returned an empty response.</p></div>');
                    } else {
                        $('#reports-content-display').html(response);
                    }
                },
                error: function(xhr, status, error) {
                    if (status === 'abort') return;
                    let errorMessage = error;
                    if (status === 'timeout') errorMessage = 'Request timed out. Please try again.';
                    $('#reports-content-display').html('<div style="text-align:center;padding:40px;color:#ef4444;"><i class="fas fa-exclamation-circle" style="font-size:2rem;display:block;margin-bottom:12px;"></i><h6 class="fw-bold">Failed to load report data.</h6><p style="font-size:0.9rem;color:#64748b;">Server responded with: ' + xhr.status + ' ' + errorMessage + '</p></div>');
                },
                complete: function() {
                    isLoadingReport = false;
                    currentRequest = null;
                }
            });
        }

        function refreshStats() {
            $.ajax({
                url: 'fetch_stats.php',
                type: 'GET',
                dataType: 'json',
                cache: false,
                success: function(data) {
                    $('#totalTrainees').text(data.totalTrainees);
                    $('#individualTrainees').text(data.individualTrainees);
                    $('#nonCompliantTrainees').text(data.nonCompliantTrainees);
                    $('.circular-progress-val').html(data.overallCompliance + '%<small>Overall Compliance</small>');
                    $('.progress-bar').css('width', data.overallCompliance + '%');
                },
                error: function() {}
            });
        }

        function exportPDF(reportType) {
            if (!IS_COORDINATOR) {
                alert('Only the TESDA Coordinator is authorized to generate PDF reports.');
                return;
            }
            const reportTypeMap = {
                'individual': 'individual',
                'summary': 'summary',
                'non_compliance': 'non_compliance',
                'non_compliance_detailed': 'non_compliance',
                'tesda_inspection': 'tesda_inspection',
                'inspection': 'tesda_inspection'
            };
            const pdfReportType = reportTypeMap[reportType] || reportType;
            let exportUrl = `generate_pdf.php?report_type=${encodeURIComponent(pdfReportType)}`;

            const skipFilters = ['non_compliance', 'tesda_inspection'];
            if (!skipFilters.includes(pdfReportType)) {
                const urlParams = new URLSearchParams(window.location.search);
                const department = urlParams.get('department') || '<?= urlencode($currentDepartment) ?>';
                const qualification = urlParams.get('qualification') || '<?= urlencode($currentQualification) ?>';
                const search = urlParams.get('search') || '<?= urlencode($currentSearch) ?>';
                const batchId = urlParams.get('batch_id') || '<?= urlencode($currentBatch) ?>';
                if (department) exportUrl += `&department=${encodeURIComponent(department)}`;
                if (qualification) exportUrl += `&qualification_id=${encodeURIComponent(qualification)}`;
                if (search) exportUrl += `&search=${encodeURIComponent(search)}`;
                if (batchId) exportUrl += `&batch_id=${encodeURIComponent(batchId)}`;
            } else {
                exportUrl += `&_full=1`;
            }
            window.open(exportUrl, '_blank');
        }
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