<?php
// summary_compliance_report.php - Summary Compliance Report
// FIXED: Per-trainee compliance logic + Year Level filter + Exact Department names
require_once __DIR__ . '/../config/config.php';

$user = getCurrentUser();
$current_page = basename($_SERVER['PHP_SELF']);
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
// ✅ HELPER: Exact department display name
// ============================================================
function getExactDeptName($deptCode) {
    $map = [
        'HRS' => 'Hotel and Restaurant Services',
        'CSS' => 'Computer Systems Servicing',
        'ATS' => 'Automotive Servicing',
        'OTHER' => 'Other Departments'
    ];
    $code = strtoupper(trim($deptCode ?? ''));
    return $map[$code] ?? ($code ?: 'Other Departments');
}

function normalizeDeptCode($dept) {
    $d = strtoupper(trim($dept ?? ''));
    if (strpos($d, 'HRS') !== false || strpos($d, 'HOTEL') !== false) return 'HRS';
    if (strpos($d, 'CSS') !== false || strpos($d, 'COMPUTER') !== false) return 'CSS';
    if (strpos($d, 'ATS') !== false || strpos($d, 'AUTOMOTIVE') !== false) return 'ATS';
    return 'OTHER';
}

// ============================================================
// GET FILTERS FROM URL
// ============================================================
$selectedQualification = $_GET['qualification'] ?? '';
$selectedStatus        = $_GET['status'] ?? '';
$selectedDepartment    = $_GET['department'] ?? '';
$selectedYear          = $_GET['year'] ?? 'all'; // ✅ NEW
$searchTerm            = trim($_GET['search'] ?? '');

if (!in_array($selectedYear, ['1st Year', '2nd Year', 'all', ''])) {
    $selectedYear = 'all';
}

// ============================================================
// BUILD DYNAMIC FILTERS
// ============================================================
$whereClauses = ["t.deleted_at IS NULL", "(t.status IS NULL OR t.status != 'deleted')"];
$params = [];

if ($selectedQualification !== '') {
    $whereClauses[] = "q.qualification_name = :qualification";
    $params[':qualification'] = $selectedQualification;
}
if ($selectedDepartment !== '') {
    $whereClauses[] = "t.department = :department";
    $params[':department'] = $selectedDepartment;
}
// ✅ NEW: Year Level filter
if ($selectedYear === '1st Year' || $selectedYear === '2nd Year') {
    $whereClauses[] = "t.year_level = :year_level";
    $params[':year_level'] = $selectedYear;
}
if ($searchTerm !== '') {
    $whereClauses[] = "(
        CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) LIKE :search 
        OR t.department LIKE :search 
        OR q.qualification_name LIKE :search
    )";
    $params[':search'] = '%' . $searchTerm . '%';
}
$whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

// ============================================================
// FETCH TRAINEE-LEVEL DATA
// ============================================================
$query = "
    SELECT 
        t.id,
        t.first_name,
        t.last_name,
        CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) as full_name,
        t.department,
        t.year_level,
        t.created_at AS date_added,
        t.qualification_id,
        q.qualification_name,
        q.qualification_code,
        u.full_name AS trainer_name,
        COALESCE(stats.total_tasks, 0) AS total_tasks,
        COALESCE(stats.complied_tasks, 0) AS completed_tasks,
        COALESCE(stats.not_complied_tasks, 0) AS not_complied_tasks
    FROM trainees t
    LEFT JOIN qualifications q ON t.qualification_id = q.id
    LEFT JOIN trainer_trainee_assignments tta ON tta.trainee_id = t.id
    LEFT JOIN users u ON tta.trainer_id = u.id
    LEFT JOIN (
        SELECT 
            et.trainee_id,
            COUNT(et.id) AS total_tasks,
            SUM(CASE WHEN et.status = 'complied' THEN 1 ELSE 0 END) AS complied_tasks,
            SUM(CASE WHEN et.status = 'not_complied' THEN 1 ELSE 0 END) AS not_complied_tasks
        FROM evaluation_tracker et
        GROUP BY et.trainee_id
    ) stats ON stats.trainee_id = t.id
    $whereSql
    GROUP BY t.id, t.first_name, t.last_name, t.department, t.year_level, t.created_at, t.qualification_id, q.qualification_name, q.qualification_code, u.full_name, stats.total_tasks, stats.complied_tasks, stats.not_complied_tasks
    ORDER BY t.department, t.year_level, t.last_name
";

$stmt = $pdo->prepare($query);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$traineesData = $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

// ============================================================
// PROCESS — compute per-trainee compliance status
// ============================================================
$totalTrainees        = count($traineesData);
$totalComplied        = 0;
$totalNotComplied     = 0;
$allQualifications    = [];
$allDepartments       = [];

foreach ($traineesData as &$trainee) {
    $total       = (int)$trainee['total_tasks'];
    $completed   = (int)$trainee['completed_tasks'];

    if ($total > 0 && $completed === $total) {
        $trainee['compliance_status'] = 'COMPLIED';
        $trainee['compliance_class']  = 'complied';
        $trainee['is_complied']       = true;
    } else {
        $trainee['compliance_status'] = 'NOT COMPLIED';
        $trainee['compliance_class']  = 'not-complied';
        $trainee['is_complied']       = false;
    }

    // ✅ Exact department display
    $trainee['dept_code']         = normalizeDeptCode($trainee['department'] ?? '');
    $trainee['dept_display_name'] = getExactDeptName($trainee['dept_code']);

    if ($trainee['is_complied']) $totalComplied++;
    else                         $totalNotComplied++;

    $qual = !empty($trainee['qualification_name']) ? $trainee['qualification_name'] : 'Unknown';
    if (!in_array($qual, $allQualifications)) $allQualifications[] = $qual;

    // ✅ Track unique departments for filter dropdown
    if (!isset($allDepartments[$trainee['dept_code']])) {
        $allDepartments[$trainee['dept_code']] = $trainee['dept_display_name'];
    }
}
unset($trainee);

sort($allQualifications);
asort($allDepartments);

// ============================================================
// APPLY STATUS FILTER
// ============================================================
$filteredData = $traineesData;
if ($selectedStatus !== '') {
    $filteredData = array_filter($filteredData, function($item) use ($selectedStatus) {
        return $item['compliance_status'] === $selectedStatus;
    });
}
$filteredData = array_values($filteredData);

// Overall compliance rate
$overallCompliance = $totalTrainees > 0 ? round(($totalComplied / $totalTrainees) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TESDA – Summary Compliance Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/tesda_compliance/assets/vendor/fontawesome/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f5f6fa; color: #1e293b; line-height: 1.5;
            min-width: 1200px; overflow-x: auto;
        }
        body.sidebar-collapsed { min-width: 992px; }

        .main-content {
            margin-left: 280px; padding: 24px 32px 40px;
            width: calc(100% - 280px); min-width: 920px;
        }
        body.sidebar-collapsed .main-content {
            margin-left: 80px; width: calc(100% - 80px);
        }

        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 0 0 20px 0; border-bottom: 2px solid #e2e8f0;
            margin-bottom: 24px;
        }
        .page-header-left h1 {
            font-size: 1.5rem; font-weight: 700; color: #0f172a;
            letter-spacing: -0.02em;
        }
        .page-header-left h1 i { margin-right: 10px; opacity: 0.7; }
        .page-header-left .subtitle {
            font-size: 0.875rem; color: #64748b; margin-top: 2px;
        }
        .page-header-right {
            display: flex; align-items: center; gap: 16px;
        }
        .page-header-right .date-badge {
            font-size: 0.875rem; color: #475569; padding: 6px 16px;
            background: #f1f5f9; border-radius: 4px; border: 1px solid #e2e8f0;
        }
        .page-header-right .btn-back {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 16px; background: #f1f5f9; border: 1px solid #e2e8f0;
            border-radius: 4px; color: #475569; text-decoration: none;
            font-size: 0.875rem; font-weight: 500;
        }
        .page-header-right .btn-back:hover { background: #e2e8f0; }

        .stats-grid {
            display: grid; grid-template-columns: repeat(4, 1fr);
            gap: 16px; margin-bottom: 24px;
        }
        .stat-card {
            background: #ffffff; border: 1px solid #e2e8f0;
            border-radius: 6px; padding: 16px 20px; text-align: center;
        }
        .stat-card .stat-number {
            font-size: 1.75rem; font-weight: 700; color: #0f172a; line-height: 1.2;
        }
        .stat-card .stat-label {
            font-size: 0.75rem; color: #64748b; font-weight: 500;
            margin-top: 2px; text-transform: uppercase; letter-spacing: 0.03em;
        }

        .filters-bar {
            display: flex; flex-wrap: wrap; align-items: center;
            gap: 12px; padding: 12px 16px; background: #ffffff;
            border: 1px solid #e2e8f0; border-radius: 6px;
            margin-bottom: 24px;
        }
        .filters-bar .filter-group {
            display: flex; align-items: center; gap: 6px;
        }
        .filters-bar .filter-group label {
            font-size: 0.75rem; font-weight: 600; color: #475569;
            text-transform: uppercase; letter-spacing: 0.03em; white-space: nowrap;
        }
        .filters-bar .filter-group select,
        .filters-bar .filter-group input {
            padding: 6px 12px; border: 1px solid #e2e8f0;
            border-radius: 4px; font-size: 0.8125rem; font-family: inherit;
            color: #1e293b; background: #ffffff; outline: none;
            min-width: 160px;
        }
        .filters-bar .filter-group select:focus,
        .filters-bar .filter-group input:focus { border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139,92,246,0.1); }
        .filters-bar .filter-group .search-wrapper { position: relative; }
        .filters-bar .filter-group .search-wrapper i {
            position: absolute; left: 10px; top: 50%;
            transform: translateY(-50%); color: #94a3b8; font-size: 0.75rem;
        }
        .filters-bar .filter-group .search-wrapper input { padding-left: 30px; }
        .filters-bar .filter-divider {
            width: 1px; height: 28px; background: #e2e8f0;
        }
        .filters-bar .filter-actions {
            display: flex; gap: 8px; margin-left: auto;
        }
        .filters-bar .filter-actions .btn-filter {
            padding: 6px 20px; background: #8b5cf6; color: #ffffff;
            border: none; border-radius: 6px; font-size: 0.75rem;
            font-weight: 600; cursor: pointer; font-family: inherit;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .filters-bar .filter-actions .btn-filter:hover { background: #7c3aed; }
        .filters-bar .filter-actions .btn-reset {
            padding: 6px 20px; background: #f1f5f9; color: #475569;
            border: 1px solid #e2e8f0; border-radius: 6px;
            font-size: 0.75rem; font-weight: 600; cursor: pointer;
            font-family: inherit;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .filters-bar .filter-actions .btn-reset:hover { background: #e2e8f0; }
        .filters-bar .result-count {
            font-size: 0.8125rem; color: #64748b; margin-left: 4px;
            margin-right: 8px;
        }

        .table-container {
            background: #ffffff; border: 1px solid #e2e8f0;
            border-radius: 6px; overflow: hidden;
        }
        .table-wrapper { overflow-x: auto; }
        .table-wrapper table {
            width: 100%; border-collapse: collapse; font-size: 0.8125rem;
        }
        .table-wrapper table thead { background: #f8f9fa; }
        .table-wrapper table thead th {
            text-align: left; padding: 12px 16px;
            font-weight: 700; font-size: 0.75rem;
            text-transform: uppercase; letter-spacing: 0.04em;
            color: #1e293b; border-bottom: 2px solid #e2e8f0;
        }
        .table-wrapper table thead th.text-center { text-align: center; }
        .table-wrapper table tbody td {
            padding: 10px 16px; border-bottom: 1px solid #e9ecef;
            color: #1e293b; vertical-align: middle;
        }
        .table-wrapper table tbody tr:last-child td { border-bottom: none; }
        .table-wrapper table tbody tr:hover { background: #f8f9fa; }
        .table-wrapper table tbody td.text-center { text-align: center; }

        .trainee-name { font-weight: 700; color: #0f172a; }
        .qualification-text { font-weight: 400; color: #1e293b; }
        .date-text { text-align: center; color: #475569; font-size: 0.8125rem; }

        /* ✅ NEW: Year badge */
        .year-badge {
            display: inline-block;
            font-size: 0.7rem; font-weight: 600;
            padding: 3px 12px; border-radius: 20px;
            letter-spacing: 0.02em;
            white-space: nowrap;
        }
        .year-badge.yr-1st {
            background: #ede9fe; color: #6d28d9;
            border: 1px solid #ddd6fe;
        }
        .year-badge.yr-2nd {
            background: #fef3c7; color: #b45309;
            border: 1px solid #fde68a;
        }

        .status-text {
            font-weight: 700; text-align: center; font-size: 0.8125rem;
        }
        .status-text.complied { color: #198754; }
        .status-text.not-complied { color: #dc3545; }

        .trainer-text { color: #1e293b; }
        .trainer-text.not-assigned { color: #94a3b8; }

        .empty-state { text-align: center; padding: 40px 20px; color: #94a3b8; }
        .empty-state i {
            font-size: 2.5rem; display: block; margin-bottom: 12px;
            opacity: 0.5;
        }
        .empty-state h3 {
            font-size: 1.1rem; color: #475569; margin-bottom: 4px;
        }
        .empty-state p { font-size: 0.875rem; }

        @media (max-width: 1200px) {
            body { min-width: auto; }
            .main-content {
                margin-left: 80px; width: calc(100% - 80px);
            }
        }
        @media (max-width: 992px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filters-bar { flex-direction: column; align-items: stretch; }
            .filters-bar .filter-group { flex-wrap: wrap; }
            .filters-bar .filter-group select,
            .filters-bar .filter-group input {
                flex: 1; min-width: 120px;
            }
            .filters-bar .filter-divider { display: none; }
            .filters-bar .filter-actions { margin-left: 0; }
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0; width: 100%;
                padding: 16px 16px 32px;
            }
            body.sidebar-collapsed .main-content {
                margin-left: 0; width: 100%;
            }
            .page-header {
                flex-direction: column; align-items: flex-start; gap: 12px;
            }
            .page-header-right { width: 100%; flex-wrap: wrap; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .table-wrapper table { font-size: 0.75rem; }
            .table-wrapper table thead th,
            .table-wrapper table tbody td { padding: 8px 10px; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
<link rel="stylesheet" href="/tesda_compliance/assets/vendor/fonts/poppins/poppins.css">
<link rel="stylesheet" href="/tesda_compliance/assets/css/bootstrap.min.css">
<link rel="stylesheet" href="/tesda_compliance/assets/css/all.min.css">
</head>
<body>
    <?php include __DIR__ . '/../sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <div class="page-header-left">
                <h1><i class="fas fa-file-alt"></i> Summary Compliance Report</h1>
                <div class="subtitle">Consolidated compliance data across all departments and qualifications</div>
            </div>
            <div class="page-header-right">
                <span class="date-badge"><i class="far fa-calendar-alt"></i> <?= date('F j, Y') ?></span>
                <a href="<?= $base_url ?>reports/reports.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= number_format($totalTrainees) ?></div>
                <div class="stat-label">Total Trainees</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color:<?= $overallCompliance >= 80 ? '#10b981' : ($overallCompliance >= 50 ? '#f59e0b' : '#ef4444') ?>;"><?= $overallCompliance ?>%</div>
                <div class="stat-label">Overall Rate</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color:#10b981;"><?= number_format($totalComplied) ?></div>
                <div class="stat-label">Complied</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color:#ef4444;"><?= number_format($totalNotComplied) ?></div>
                <div class="stat-label">Not Complied</div>
            </div>
        </div>

        <div class="filters-bar" id="filtersBar">
            <div class="filter-group">
                <label for="searchInput"><i class="fas fa-search"></i></label>
                <div class="search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" id="searchInput" placeholder="Search trainee, department..." value="<?= htmlspecialchars($searchTerm) ?>">
                </div>
            </div>
            <div class="filter-divider"></div>
            <div class="filter-group">
                <label for="deptFilter"><i class="fas fa-building"></i> Department</label>
                <select id="deptFilter" onchange="applyFilters()">
                    <option value="">All Departments</option>
                    <?php foreach ($allDepartments as $code => $name): ?>
                        <option value="<?= htmlspecialchars($code) ?>" <?= $selectedDepartment == $code ? 'selected' : '' ?>>
                            <?= htmlspecialchars($name) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-divider"></div>
            <!-- ✅ NEW: Year Level filter -->
            <div class="filter-group">
                <label for="yearFilter"><i class="fas fa-layer-group"></i> Year Level</label>
                <select id="yearFilter" onchange="applyFilters()">
                    <option value="all" <?= $selectedYear === 'all' ? 'selected' : '' ?>>All Year Levels</option>
                    <option value="1st Year" <?= $selectedYear === '1st Year' ? 'selected' : '' ?>>1st Year</option>
                    <option value="2nd Year" <?= $selectedYear === '2nd Year' ? 'selected' : '' ?>>2nd Year</option>
                </select>
            </div>
            <div class="filter-divider"></div>
            <div class="filter-group">
                <label for="qualFilter">Qualification</label>
                <select id="qualFilter" onchange="applyFilters()">
                    <option value="">All Qualifications</option>
                    <?php foreach ($allQualifications as $qual): ?>
                        <option value="<?= htmlspecialchars($qual) ?>" <?= $selectedQualification == $qual ? 'selected' : '' ?>>
                            <?= htmlspecialchars($qual) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-divider"></div>
            <div class="filter-group">
                <label for="statusFilter">Status</label>
                <select id="statusFilter" onchange="applyFilters()">
                    <option value="">All Status</option>
                    <option value="COMPLIED" <?= $selectedStatus == 'COMPLIED' ? 'selected' : '' ?>>Complied</option>
                    <option value="NOT COMPLIED" <?= $selectedStatus == 'NOT COMPLIED' ? 'selected' : '' ?>>Not Complied</option>
                </select>
            </div>
            <div class="filter-actions">
                <span class="result-count" id="resultCount"><?= count($filteredData) ?> record(s)</span>
                <button class="btn-reset" onclick="resetFilters()"><i class="fas fa-undo-alt"></i> Reset</button>
                <button class="btn-filter" onclick="applyFilters()"><i class="fas fa-filter"></i> Apply</button>
            </div>
        </div>

        <div class="table-container">
            <div class="table-wrapper">
                <table id="summaryTable">
                    <thead>
                        <tr>
                            <th>Trainee Name</th>
                            <th>Department</th>
                            <th>Qualification</th>
                            <th class="text-center">Year Level</th>
                            <th class="text-center">Date Added</th>
                            <th class="text-center">Status</th>
                            <th>Assigned Trainer</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php if (empty($filteredData)): ?>
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <i class="fas fa-inbox"></i>
                                        <h3>No trainees found</h3>
                                        <p>Try adjusting your filters or add some trainees to see results.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($filteredData as $trainee): 
                                $fullName = $trainee['full_name'] ?? 'Unknown Trainee';
                                $deptDisplay = $trainee['dept_display_name'] ?? 'Unknown';
                                $yearLevel = $trainee['year_level'] ?? 'N/A';
                                $yearClass = ($yearLevel === '2nd Year') ? 'yr-2nd' : 'yr-1st';

                                $qualification = !empty($trainee['qualification_name']) ? $trainee['qualification_name'] : 'N/A';
                                $qualCode = !empty($trainee['qualification_code']) ? $trainee['qualification_code'] : '';
                                $displayQual = $qualCode ? $qualification . ' (' . $qualCode . ')' : $qualification;
                                
                                $dateAdded = 'N/A';
                                if (!empty($trainee['date_added']) && $trainee['date_added'] != '0000-00-00 00:00:00') {
                                    $timestamp = strtotime($trainee['date_added']);
                                    if ($timestamp !== false) {
                                        $dateAdded = date('m/d/Y', $timestamp);
                                    }
                                }
                                
                                $status = $trainee['compliance_status'] ?? 'NOT COMPLIED';
                                $statusClass = strtolower(str_replace(' ', '-', $status));
                                
                                $trainer = !empty($trainee['trainer_name']) ? $trainee['trainer_name'] : 'Not Assigned';
                                $trainerClass = $trainer === 'Not Assigned' ? 'not-assigned' : '';
                            ?>
                                <tr>
                                    <td><span class="trainee-name"><?= htmlspecialchars($fullName) ?></span></td>
                                    <!-- ✅ EXACT DEPARTMENT NAME -->
                                    <td><span class="qualification-text"><strong><?= htmlspecialchars($deptDisplay) ?></strong></span></td>
                                    <td><span class="qualification-text"><?= htmlspecialchars($displayQual) ?></span></td>
                                    <!-- ✅ YEAR LEVEL BADGE -->
                                    <td class="text-center">
                                        <span class="year-badge <?= $yearClass ?>">
                                            <?= htmlspecialchars($yearLevel) ?>
                                        </span>
                                    </td>
                                    <td class="text-center"><span class="date-text"><?= htmlspecialchars($dateAdded) ?></span></td>
                                    <td class="text-center">
                                        <span class="status-text <?= $statusClass ?>">
                                            <?= htmlspecialchars($status) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="trainer-text <?= $trainerClass ?>">
                                            <?= htmlspecialchars($trainer) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        function applyFilters() {
            const searchTerm     = document.getElementById('searchInput').value.trim();
            const deptFilter     = document.getElementById('deptFilter').value;
            const yearFilter     = document.getElementById('yearFilter').value;
            const qualFilter     = document.getElementById('qualFilter').value;
            const statusFilter   = document.getElementById('statusFilter').value;

            const params = new URLSearchParams();
            if (searchTerm)   params.set('search', searchTerm);
            if (deptFilter)   params.set('department', deptFilter);
            if (yearFilter && yearFilter !== 'all') params.set('year', yearFilter);
            if (qualFilter)   params.set('qualification', qualFilter);
            if (statusFilter) params.set('status', statusFilter);

            const url = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
            window.location.href = url;
        }

        function resetFilters() {
            window.location.href = window.location.pathname;
        }

        document.getElementById('searchInput').addEventListener('keyup', function(e) {
            if (e.key === 'Enter') applyFilters();
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