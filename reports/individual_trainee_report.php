<?php
// reports.php - Main Reports Dashboard
require_once __DIR__ . '/../config/config.php';

// Get current user and page info
$user = getCurrentUser();
$current_page = basename($_SERVER['PHP_SELF']);

// Define getBaseUrl function if not already defined
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

// Database connection
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
// SOFT-DELETE HELPER - EXCLUDE SOFT-DELETED TRAINEES
// ============================================================
function getActiveCondition($tableAlias = 't') {
    return "($tableAlias.deleted_at IS NULL)
            AND ($tableAlias.status IS NULL OR $tableAlias.status != 'deleted')";
}

// ============================================================
// REAL-TIME METRICS - WITH SOFT-DELETE FILTERING
// ============================================================

// Card 1: Total Trainees (EXCLUDE SOFT-DELETED)
$totalTrainees = $pdo->query("
    SELECT COUNT(*) AS total 
    FROM trainees 
    WHERE deleted_at IS NULL
    AND (status IS NULL OR status != 'deleted')
")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Card 2: Batch Trainees - Has a valid batch code (EXCLUDE SOFT-DELETED)
$batchTrainees = $pdo->query("
    SELECT COUNT(*) AS total 
    FROM trainees 
    WHERE batch_code IS NOT NULL 
    AND batch_code != '' 
    AND batch_code != '0'
    AND deleted_at IS NULL
    AND (status IS NULL OR status != 'deleted')
")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Card 3: Individual Trainees - NO batch code OR batch code is '0' or empty (EXCLUDE SOFT-DELETED)
$individualTrainees = $pdo->query("
    SELECT COUNT(*) AS total 
    FROM trainees 
    WHERE (batch_code IS NULL OR batch_code = '' OR batch_code = '0')
    AND deleted_at IS NULL
    AND (status IS NULL OR status != 'deleted')
")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Card 4: Non-Compliant Trainees (EXCLUDE SOFT-DELETED)
$nonCompliantTrainees = $pdo->query("
    SELECT COUNT(DISTINCT trainee_id) AS total 
    FROM evaluation_tracker et
    JOIN trainees t ON t.id = et.trainee_id
    WHERE t.deleted_at IS NULL
    AND (t.status IS NULL OR t.status != 'deleted')
    AND (LOWER(et.status) = 'not_complied' OR LOWER(et.remarks) NOT IN ('complied', 'completed', 'done'))
")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Also check trainees with no evaluation records (EXCLUDE SOFT-DELETED)
$traineesWithNoRecords = $pdo->query("
    SELECT COUNT(DISTINCT t.id) AS total
    FROM trainees t
    WHERE t.qualification_id IS NOT NULL
      AND t.qualification_id > 0
      AND t.deleted_at IS NULL
      AND (t.status IS NULL OR t.status != 'deleted')
      AND NOT EXISTS (
          SELECT 1 FROM evaluation_tracker et 
          WHERE et.trainee_id = t.id
      )
")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$nonCompliantTrainees += $traineesWithNoRecords;

// ============================================================
// COMPLIANCE RATE CALCULATION
// ============================================================
$fullyCompliantTrainees = $totalTrainees - $nonCompliantTrainees;
$overallCompliance = $totalTrainees > 0 ? round(($fullyCompliantTrainees / $totalTrainees) * 100) : 0;

if ($totalTrainees == 0) {
    $complianceSubtext = "No trainees registered";
} else {
    $complianceSubtext = $fullyCompliantTrainees . " out of " . number_format($totalTrainees) . " trainees fully compliant";
}

// Batch count for badge (EXCLUDE SOFT-DELETED)
$batchCount = $pdo->query("
    SELECT COUNT(DISTINCT batch_code) AS total 
    FROM trainees 
    WHERE batch_code IS NOT NULL 
    AND batch_code != '' 
    AND batch_code != '0'
    AND deleted_at IS NULL
    AND (status IS NULL OR status != 'deleted')
")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Determine compliance color
$complianceColor = $overallCompliance >= 80 ? '#10b981' : ($overallCompliance >= 50 ? '#f59e0b' : '#ef4444');

// Get current filters from URL (for passing to PDF)
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
            border: 1px solid rgba(255,255,255,0.2);
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            animation: slideDown 0.5s ease-out;
            flex-wrap: wrap;
            gap: 15px;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .header-left h1 {
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, #1e293b, #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-left h1 i {
            background: linear-gradient(135deg, #8b5cf6, #6d28d9);
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

        .header-right {
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
        }

        .date-badge {
            background: #f0f2f5;
            padding: 12px 24px;
            border-radius: 12px;
            color: #1a1c2c;
            font-weight: 500;
            font-size: 1rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .date-badge:hover {
            transform: rotate(3deg) scale(1.02);
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }

        /* ============================================================
           EXPORT DROPDOWN BUTTON
           ============================================================ */
        .btn-export-dropdown {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        .btn-export-dropdown:hover {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
            color: white;
        }

        .btn-export-dropdown i {
            font-size: 1rem;
        }

        .btn-export-dropdown .dropdown-icon {
            font-size: 0.7rem;
            transition: transform 0.3s ease;
            margin-left: 4px;
        }

        .btn-export-dropdown.show .dropdown-icon {
            transform: rotate(180deg);
        }

        /* Custom Dropdown Menu */
        .export-dropdown-menu {
            border: none;
            border-radius: 16px;
            padding: 8px 0;
            box-shadow: 0 15px 50px rgba(0,0,0,0.15);
            min-width: 260px;
            margin-top: 8px;
            animation: dropdownFade 0.25s ease-out;
        }

        @keyframes dropdownFade {
            from { opacity: 0; transform: translateY(-10px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .export-dropdown-menu .dropdown-item {
            padding: 12px 20px;
            font-size: 0.9rem;
            font-weight: 500;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.15s ease;
            border-left: 3px solid transparent;
        }

        .export-dropdown-menu .dropdown-item:hover {
            background: #f8fafc;
            color: #0f172a;
            border-left-color: #10b981;
        }

        .export-dropdown-menu .dropdown-item i {
            font-size: 1.1rem;
            color: #94a3b8;
            width: 24px;
            text-align: center;
        }

        .export-dropdown-menu .dropdown-item:hover i {
            color: #10b981;
        }

        .export-dropdown-menu .dropdown-divider {
            margin: 6px 16px;
            border-color: #f1f5f9;
        }

        .export-dropdown-menu .dropdown-header {
            padding: 8px 20px 4px;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #94a3b8;
            font-weight: 600;
        }

        /* ============================================================ */
        .hero-progress-card {
            background: white;
            border-radius: 24px;
            padding: 30px 35px;
            margin-bottom: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            border: 1px solid rgba(255,255,255,0.2);
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            animation: fadeInUp 0.6s ease-out 0.1s both;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .hero-progress-card .row {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
        }

        .hero-progress-card .left-section {
            flex: 0 0 auto;
            width: 25%;
            text-align: center;
            padding-right: 30px;
            border-right: 2px solid #e2e8f0;
        }

        .hero-progress-card .right-section {
            flex: 1;
            padding-left: 30px;
        }

        .circular-progress-val {
            font-size: 3.5rem;
            font-weight: 700;
            color: <?= $complianceColor ?>;
            line-height: 1.2;
        }

        .circular-progress-val small {
            font-size: 0.9rem;
            color: #94a3b8;
            font-weight: 400;
        }

        .hero-progress-card .progress {
            height: 10px;
            background: #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 16px;
        }

        .hero-progress-card .progress-bar {
            height: 100%;
            border-radius: 8px;
            background: <?= $complianceColor ?>;
            transition: width 0.6s ease;
        }

        .hero-progress-card .right-section h5 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 12px;
        }

        .stat-pills {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-top: 16px;
        }

        .stat-pill {
            padding: 14px 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-pill:hover {
            background: #f1f5f9;
            transform: translateY(-2px);
        }

        .stat-pill .pill-value {
            display: block;
            font-size: 1.6rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.2;
        }

        .stat-pill .pill-value.text-success { color: #10b981; }
        .stat-pill .pill-value.text-danger { color: #ef4444; }
        .stat-pill .pill-value.text-info { color: #3b82f6; }
        .stat-pill .pill-value.text-purple { color: #8b5cf6; }
        .stat-pill .pill-label { font-size: 0.72rem; color: #64748b; font-weight: 500; margin-top: 2px; }

        .report-tabs-nav {
            display: flex;
            width: 100%;
            gap: 12px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 16px;
            margin-bottom: 24px;
            overflow-x: auto;
            flex-wrap: nowrap;
        }

        .report-tabs-nav .tab-btn {
            flex: 1 1 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 14px 20px;
            font-size: 0.95rem;
            font-weight: 600;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s ease-in-out;
            white-space: nowrap;
            background: transparent;
            color: #64748b;
            position: relative;
            gap: 8px;
        }

        .report-tabs-nav .tab-btn:hover {
            background: #f1f5f9;
            color: #1e293b;
            transform: translateY(-2px);
        }

        .report-tabs-nav .tab-btn.active {
            background: #8b5cf6;
            color: white;
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
        }

        .report-tabs-nav .tab-btn i { font-size: 1.2rem; }

        .report-tabs-nav .tab-btn .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 4px;
        }

        .report-tabs-nav .tab-btn .badge.bg-light {
            background: rgba(255, 255, 255, 0.3);
            color: #8b5cf6;
        }

        .report-tabs-nav .tab-btn.active .badge.bg-light {
            background: rgba(255, 255, 255, 0.25);
            color: white;
        }

        .report-tabs-nav .tab-btn .badge.bg-danger {
            background: #ef4444;
            color: white;
        }

        .report-tabs-nav .tab-btn.active .badge.bg-danger {
            background: rgba(255, 255, 255, 0.25);
            color: white;
        }

        #reports-content-display {
            background: white;
            border-radius: 20px;
            padding: 28px 32px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            min-height: 400px;
            animation: fadeInUp 0.6s ease-out 0.2s both;
        }

        #reports-content-display .loading-spinner {
            text-align: center;
            padding: 60px 20px;
        }

        #reports-content-display .loading-spinner .spinner-border {
            width: 3rem;
            height: 3rem;
            border: 0.25rem solid #e2e8f0;
            border-top-color: #8b5cf6;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            display: inline-block;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* ============================================================
           Live Data Badge - HIDDEN
           ============================================================ */
        .auto-refresh-badge {
            display: none !important;
        }

        /* ============================================================ */
        @media (max-width: 1200px) {
            .main-content {
                margin-left: 80px;
                width: calc(100% - 80px);
            }
            body { min-width: 992px; }
        }

        @media (max-width: 992px) {
            .hero-progress-card .left-section {
                width: 100%;
                border-right: none;
                border-bottom: 2px solid #e2e8f0;
                padding-right: 0;
                padding-bottom: 20px;
                margin-bottom: 20px;
            }
            .hero-progress-card .right-section { padding-left: 0; }
            .stat-pills {
                grid-template-columns: repeat(2, 1fr);
            }
            .report-tabs-nav { gap: 8px; }
            .report-tabs-nav .tab-btn { padding: 12px 16px; font-size: 0.85rem; }
            .report-tabs-nav .tab-btn i { font-size: 1rem; }
            .header-right {
                flex-wrap: wrap;
            }
            .btn-export-dropdown {
                padding: 10px 18px;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 768px) {
            body { min-width: auto; }
            .main-content { 
                margin-left: 0; 
                width: 100%; 
                min-width: auto; 
                padding: 15px; 
            }
            body.sidebar-collapsed .main-content { margin-left: 0; width: 100%; }
            .header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            .header-right {
                flex-wrap: wrap;
                width: 100%;
            }
            .hero-progress-card { padding: 20px; }
            .stat-pills {
                grid-template-columns: 1fr 1fr;
            }
            .report-tabs-nav {
                flex-wrap: wrap;
                gap: 6px;
            }
            .report-tabs-nav .tab-btn {
                flex: 1 1 calc(50% - 6px);
                padding: 10px 14px;
                font-size: 0.78rem;
                white-space: normal;
            }
            .report-tabs-nav .tab-btn i { font-size: 0.9rem; }
            .report-tabs-nav .tab-btn .badge { font-size: 0.7rem; padding: 3px 8px; }
            #reports-content-display { padding: 16px; }
            .circular-progress-val { font-size: 2.5rem; }
            .btn-export-dropdown {
                padding: 8px 16px;
                font-size: 0.85rem;
            }
            .export-dropdown-menu {
                min-width: 220px;
            }
        }

        @media (max-width: 480px) {
            .report-tabs-nav .tab-btn { flex: 1 1 100%; padding: 10px 12px; font-size: 0.75rem; }
            .header-left h1 { font-size: 1.3rem; }
            .stat-pills {
                grid-template-columns: 1fr;
            }
            .header-right {
                flex-direction: column;
                align-items: stretch;
            }
            .btn-export-dropdown {
                justify-content: center;
            }
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
                <h1><i class="fas fa-chart-pie"></i> Reports Dashboard</h1>
                <p>Compliance overview and reporting</p>
            </div>
            <div class="header-right">
                <!-- Date Badge -->
                <div class="date-badge">
                    <i class="far fa-calendar-alt"></i> <?= date('F j, Y') ?>
                </div>

                <!-- ============================================================
                EXPORT PDF DROPDOWN BUTTON
                ============================================================ -->
                <div class="dropdown">
                    <button class="btn-export-dropdown dropdown-toggle" type="button" id="exportPdfDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-file-pdf"></i>
                        Export PDF Reports
                        <i class="fas fa-chevron-down dropdown-icon"></i>
                    </button>
                    <ul class="dropdown-menu export-dropdown-menu" aria-labelledby="exportPdfDropdown">
                        <li class="dropdown-header">📋 Select Report Type</li>
                        
                        <li>
                            <a class="dropdown-item" href="javascript:void(0)" onclick="exportPDF('batch')">
                                <i class="fas fa-layer-group me-2"></i>
                                Batch Compliance Report
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="javascript:void(0)" onclick="exportPDF('individual')">
                                <i class="fas fa-user me-2"></i>
                                Individual Trainee Report
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="javascript:void(0)" onclick="exportPDF('summary')">
                                <i class="fas fa-file-alt me-2"></i>
                                Summary Compliance Report
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="javascript:void(0)" onclick="exportPDF('non_compliance')">
                                <i class="fas fa-list-ul me-2"></i>
                                Non-Compliance List Report
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="javascript:void(0)" onclick="exportPDF('tesda_inspection')">
                                <i class="fas fa-clipboard-check me-2"></i>
                                TESDA Inspection Audit
                            </a>
                        </li>
                    </ul>
                </div>

                <!-- Live Data Badge - HIDDEN -->
                <span class="auto-refresh-badge">
                    <i class="fas fa-circle"></i> Live Data
                </span>
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
                            <span class="pill-value text-purple" id="batchTrainees"><?= number_format($batchTrainees) ?></span>
                            <span class="pill-label">Batch Trainees</span>
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
            <button class="tab-btn active" data-target="batch" data-report-type="batch">
                <i class="fas fa-layer-group"></i>
                <span>Batch Compliance</span>
                <span class="badge bg-light"><?= $batchCount ?></span>
            </button>
            
            <button class="tab-btn" data-target="individual" data-report-type="individual">
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
            <div class="loading-spinner">
                <div class="spinner-border"></div>
                <p style="margin-top: 16px; color: #64748b;">Loading report data...</p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            loadReportContent('batch');

            $('.tab-btn').on('click', function(e) {
                e.preventDefault();
                $('.tab-btn').removeClass('active');
                $(this).addClass('active');
                const targetReport = $(this).data('target');
                loadReportContent(targetReport);
            });

            setInterval(function() {
                refreshStats();
            }, 30000);
        });

        function loadReportContent(reportType) {
            $('#reports-content-display').html(`
                <div class="loading-spinner">
                    <div class="spinner-border"></div>
                    <p style="margin-top: 16px; color: #64748b;">Loading report...</p>
                </div>
            `);

            $.ajax({
                url: 'fetch_report_details.php',
                type: 'GET',
                data: { report_type: reportType },
                dataType: 'html',
                success: function(response) {
                    $('#reports-content-display').html(response);
                    
                    // Update active tab's data-report-type attribute for PDF export
                    $('.tab-btn').removeAttr('data-report-type');
                    $('.tab-btn.active').attr('data-report-type', reportType);
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', {
                        status: xhr.status,
                        statusText: xhr.statusText,
                        responseText: xhr.responseText,
                        error: error
                    });
                    
                    $('#reports-content-display').html(`
                        <div style="text-align: center; padding: 40px; color: #ef4444; background: white; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.08);">
                            <i class="fas fa-exclamation-circle" style="font-size: 2rem; display: block; margin-bottom: 12px;"></i>
                            <h6 class="fw-bold">Failed to load report data.</h6>
                            <p class="text-muted" style="font-size: 0.9rem; color: #64748b;">Server responded with: ${xhr.status} ${error}</p>
                            <small class="text-muted" style="font-size: 0.8rem; color: #94a3b8;">Check console for details</small>
                            <div style="margin-top: 12px; padding: 10px; background: #fef2f2; border-radius: 8px; text-align: left; font-size: 0.8rem; color: #991b1b; max-height: 200px; overflow: auto;">
                                <strong>Response:</strong> ${xhr.responseText || 'No response text'}
                            </div>
                        </div>
                    `);
                }
            });
        }

        function refreshStats() {
            $.ajax({
                url: 'fetch_stats.php',
                type: 'GET',
                dataType: 'json',
                success: function(data) {
                    $('#totalTrainees').text(data.totalTrainees);
                    $('#batchTrainees').text(data.batchTrainees);
                    $('#individualTrainees').text(data.individualTrainees);
                    $('#nonCompliantTrainees').text(data.nonCompliantTrainees);
                    $('.circular-progress-val').html(data.overallCompliance + '%<small>Overall Compliance</small>');
                    $('.progress-bar').css('width', data.overallCompliance + '%');
                    $('.hero-progress-card .left-section span').text(data.complianceSubtext);
                },
                error: function() {}
            });
        }

        // ============================================================
        // PDF EXPORT FUNCTION - Dynamic context passing
        // ============================================================
        function exportPDF(reportType) {
            // Map tab names to report type strings for PDF export script
            const reportTypeMap = {
                'batch': 'batch',
                'individual': 'individual',
                'summary': 'summary',
                'non_compliance': 'non_compliance',
                'non_compliance_detailed': 'non_compliance',
                'tesda_inspection': 'tesda_inspection',
                'inspection': 'tesda_inspection'
            };

            const pdfReportType = reportTypeMap[reportType] || reportType;

            // Fetch active URL parameters to maintain filtering state in PDF
            const urlParams = new URLSearchParams(window.location.search);
            const department = urlParams.get('department') || '<?= urlencode($currentDepartment) ?>';
            const qualification = urlParams.get('qualification') || '<?= urlencode($currentQualification) ?>';
            const search = urlParams.get('search') || '<?= urlencode($currentSearch) ?>';
            const batchId = urlParams.get('batch_id') || '<?= urlencode($currentBatch) ?>';

            // Construct export URL with active filter values
            let exportUrl = `generate_pdf.php?report_type=${encodeURIComponent(pdfReportType)}`;
            
            if (department) exportUrl += `&department=${encodeURIComponent(department)}`;
            if (qualification) exportUrl += `&qualification_id=${encodeURIComponent(qualification)}`;
            if (search) exportUrl += `&search=${encodeURIComponent(search)}`;
            if (batchId) exportUrl += `&batch_id=${encodeURIComponent(batchId)}`;

            // Open PDF report in new tab
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