<?php
// tesda_inspection_report.php - Complete TESDA Inspection Report

if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

function formatQualificationName($fullName) {
    if (empty($fullName)) return 'N/A';
    $map = [
        'Food and Beverage Services'    => 'FBS',
        'Computer Systems Servicing'    => 'CSS',
        'Shielded Metal Arc Welding'    => 'SMAW',
        'Housekeeping'                  => 'HK',
        'Cookery'                       => 'CK',
        'Electrical Installation'       => 'EIM',
        'Bread and Pastry Production'   => 'BPP',
        'Electronic Products Assembly'  => 'EPAS',
        'Automotive Servicing'          => 'AS',
    ];
    $shortName = $fullName;
    foreach ($map as $full => $abbr) {
        if (stripos($shortName, $full) !== false) {
            $shortName = str_ireplace($full, $abbr, $shortName);
        }
    }
    return trim(preg_replace('/\s+/', ' ', $shortName));
}

// ============================================================
// 1. TCPDF - DIRECT INCLUDE
// ============================================================
$tcpdfFile = __DIR__ . '/../tcpdf/tcpdf.php';
if (file_exists($tcpdfFile)) {
    require_once $tcpdfFile;
} else {
    $altPaths = [
        __DIR__ . '/../../tcpdf/tcpdf.php',
        'C:/laragon/www/tesda_compliance/tcpdf/tcpdf.php',
        __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php'
    ];
    $found = false;
    foreach ($altPaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $found = true;
            break;
        }
    }
    if (!$found) {
        ob_end_clean();
        die('❌ TCPDF Library Not Found');
    }
}

if (!class_exists('TCPDF')) {
    ob_end_clean();
    die('❌ TCPDF Class Not Found');
}

// ============================================================
// 2. Session & DB
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host = 'localhost';
$dbname = 'tesda_compliance';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    ob_end_clean();
    die("Database connection failed: " . $e->getMessage());
}

// ============================================================
// 3. Parameters
// ============================================================
$reportType = $_GET['report_type'] ?? 'tesda_inspection';
$department = $_GET['department'] ?? '';
$qualification_id = isset($_GET['qualification_id']) ? (int)$_GET['qualification_id'] : 0;
$search = $_GET['search'] ?? '';
$batch_id = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;

$currentTrainer = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'TESDA Trainer / Instructor';
$currentTrainerUpper = strtoupper($currentTrainer);

// ============================================================
// 4. PDF Class
// ============================================================
class TESDA_Inspection_PDF extends TCPDF {
    public function Header() {
        $leftLogo = __DIR__ . '/assets/images/Tesda-Logo.png';
        if (!file_exists($leftLogo)) $leftLogo = __DIR__ . '/../assets/images/Tesda-Logo.png';
        if (file_exists($leftLogo)) {
            @$this->Image($leftLogo, 18, 9, 17, '', 'PNG', '', 'T', false, 300, '', false, false, 0);
        }
        $rightLogo = __DIR__ . '/assets/images/love.png';
        if (!file_exists($rightLogo)) $rightLogo = __DIR__ . '/../assets/images/love.png';
        if (file_exists($rightLogo)) {
            @$this->Image($rightLogo, 175, 9, 17, '', 'PNG', '', 'T', false, 300, '', false, false, 0);
        }
        $this->SetY(8);
        $this->SetFont('dejavusans', 'B', 13);
        $this->Cell(0, 5, 'USAT COLLEGE SAGAY CITY, INC.', 0, 1, 'C');
        $this->SetFont('dejavusans', 'B', 10);
        $this->Cell(0, 5, 'TESDA PERFORMANCE COMPLIANCE REPORT', 0, 1, 'C');
        $this->SetFont('dejavusans', '', 8.5);
        $this->SetTextColor(80, 80, 80);
        $this->Cell(0, 4, 'Date Generated: ' . date('F j, Y'), 0, 1, 'C');
        $this->SetLineWidth(0.4);
        $this->SetDrawColor(30, 30, 30);
        $this->Line(15, 29, 195, 29);
    }
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('dejavusans', 'I', 8.5);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, false, 'C');
    }
}

$pdf = new TESDA_Inspection_PDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('TESDA Compliance System');
$pdf->SetAuthor($currentTrainer);
$pdf->SetTitle('TESDA Inspection Report');
$pdf->SetSubject('TESDA Compliance Inspection');
$pdf->setFontSubsetting(true);
$pdf->SetMargins(15, 34, 15);
$pdf->SetAutoPageBreak(TRUE, 20);
$pdf->SetFont('dejavusans', '', 10, '', true);
$pdf->AddPage();

// ============================================================
// 5. Data Queries
// ============================================================
$filterSql = "";
$params = [];

if ($department) {
    $filterSql .= " AND t.department = :department";
    $params[':department'] = $department;
}
if ($qualification_id > 0) {
    $filterSql .= " AND t.qualification_id = :qualification_id";
    $params[':qualification_id'] = $qualification_id;
}
if ($batch_id > 0) {
    $filterSql .= " AND t.batch_id = :batch_id";
    $params[':batch_id'] = $batch_id;
}
if ($search) {
    $filterSql .= " AND (CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) LIKE :search OR q.qualification_name LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

// ============================================================
// SECTION 1 (FIXED) — per-trainee compliance
// ============================================================
$summarySql = "
    SELECT 
        q.id AS qual_id,
        q.qualification_name,
        q.qualification_code,
        COUNT(DISTINCT CASE 
            WHEN COALESCE(pt_counts.total_tasks, 0) > 0 
            THEN t.id 
        END) AS total_trainees,
        COALESCE(MAX(pt_counts.total_tasks), 0) AS total_tasks,
        SUM(CASE 
            WHEN COALESCE(pt_counts.total_tasks, 0) > 0
             AND COALESCE(et_counts.complied_tasks, 0) >= COALESCE(pt_counts.total_tasks, 0)
            THEN 1 ELSE 0 
        END) AS complied_count,
        SUM(CASE 
            WHEN COALESCE(pt_counts.total_tasks, 0) > 0
             AND COALESCE(et_counts.complied_tasks, 0) < COALESCE(pt_counts.total_tasks, 0)
            THEN 1 ELSE 0 
        END) AS not_complied_count
    FROM qualifications q
    LEFT JOIN trainees t 
        ON t.qualification_id = q.id
        AND t.deleted_at IS NULL
        AND (t.status IS NULL OR t.status != 'deleted')
    LEFT JOIN (
        SELECT 
            cs.qualification_id,
            COUNT(DISTINCT pt.id) AS total_tasks
        FROM competency_standards cs
        LEFT JOIN performance_tasks pt ON pt.competency_id = cs.id
        GROUP BY cs.qualification_id
    ) pt_counts ON pt_counts.qualification_id = q.id
    LEFT JOIN (
        SELECT 
            et.trainee_id,
            SUM(CASE WHEN et.status = 'complied' THEN 1 ELSE 0 END) AS complied_tasks
        FROM evaluation_tracker et
        GROUP BY et.trainee_id
    ) et_counts ON et_counts.trainee_id = t.id
    WHERE 1=1 $filterSql
    GROUP BY q.id, q.qualification_name, q.qualification_code
    ORDER BY q.qualification_name
";
$stmt = $pdo->prepare($summarySql);
foreach ($params as $key => $val) $stmt->bindValue($key, $val);
$stmt->execute();
$qualificationSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);

// SECTION 2
$taskDetailsSql = "
    SELECT 
        t.id AS trainee_id,
        CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) AS trainee_name,
        t.department,
        q.qualification_name,
        q.qualification_code,
        COUNT(DISTINCT pt.id) AS total_tasks,
        SUM(CASE WHEN et.status = 'complied' THEN 1 ELSE 0 END) AS complied_tasks,
        SUM(CASE WHEN et.status = 'not_complied' THEN 1 ELSE 0 END) AS not_complied_tasks
    FROM trainees t
    JOIN qualifications q ON t.qualification_id = q.id
    LEFT JOIN competency_standards cs ON cs.qualification_id = q.id
    LEFT JOIN performance_tasks pt ON pt.competency_id = cs.id
    LEFT JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = t.id
    WHERE t.deleted_at IS NULL
      AND (t.status IS NULL OR t.status != 'deleted')
      $filterSql
    GROUP BY t.id, t.first_name, t.last_name, t.department, q.qualification_name, q.qualification_code
    ORDER BY t.last_name, t.first_name
";
$stmt = $pdo->prepare($taskDetailsSql);
foreach ($params as $key => $val) $stmt->bindValue($key, $val);
$stmt->execute();
$taskDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);

// SECTION 3 — RAW data (per task per trainee)
$deficiencySql = "
    SELECT 
        t.id AS trainee_id,
        CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) AS trainee_name,
        q.qualification_name, 
        q.qualification_code,
        pt.task_name AS performance_task,
        CASE 
            WHEN nl.id IS NOT NULL THEN 'Notified'
            ELSE 'Not Notified'
        END AS notification_status
    FROM trainees t
    JOIN qualifications q ON t.qualification_id = q.id
    JOIN competency_standards cs ON cs.qualification_id = q.id
    JOIN performance_tasks pt ON pt.competency_id = cs.id
    LEFT JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = t.id
    LEFT JOIN notified_logs nl ON nl.trainee_id = t.id AND nl.task_id = pt.id
    WHERE t.deleted_at IS NULL
      AND (t.status IS NULL OR t.status != 'deleted')
      $filterSql
      AND (et.status IS NULL OR et.status != 'complied')
    ORDER BY t.last_name, t.first_name, q.qualification_name, pt.id
";
$stmt = $pdo->prepare($deficiencySql);
foreach ($params as $key => $val) $stmt->bindValue($key, $val);
$stmt->execute();
$deficiencyRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ GROUP by trainee + qualification + notification_status
// Each row = 1 trainee. Tasks are combined into a bullet list.
$groupedDeficiency = [];
foreach ($deficiencyRaw as $row) {
    $key = $row['trainee_id'] . '|' . $row['qualification_name'] . '|' . $row['notification_status'];
    if (!isset($groupedDeficiency[$key])) {
        $groupedDeficiency[$key] = [
            'trainee_name' => $row['trainee_name'],
            'qualification_name' => $row['qualification_name'],
            'notification_status' => $row['notification_status'],
            'tasks' => []
        ];
    }
    $groupedDeficiency[$key]['tasks'][] = $row['performance_task'];
}

// Split into two lists
$notNotifiedList = [];
$notifiedList = [];
foreach ($groupedDeficiency as $group) {
    if ($group['notification_status'] === 'Notified') {
        $notifiedList[] = $group;
    } else {
        $notNotifiedList[] = $group;
    }
}

// ============================================================
// 6. Build HTML
// ============================================================
$html = '
<style>
    h1.report-title {
        color: #0f172a;
        font-family: dejavusans;
        text-align: center;
        font-size: 16pt;
        font-weight: bold;
        margin-bottom: 2px;
    }
    h2.section-title {
        color: #1e293b;
        font-family: dejavusans;
        font-size: 12pt;
        font-weight: bold;
        margin-top: 16px;
        margin-bottom: 8px;
        border-bottom: 2px solid #e2e8f0;
        padding-bottom: 4px;
    }
    .section-desc {
        font-size: 8.5pt;
        color: #64748b;
        margin-top: 0px;
        margin-bottom: 10px;
    }
    table.data-table {
        width: 100%;
        border-collapse: collapse;
    }
    table.data-table th {
        background-color: #f1f5f9;
        color: #1e293b;
        font-weight: bold;
        font-size: 7.5pt;
        padding: 6px 6px;
        border-bottom: 2px solid #cbd5e1;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    table.data-table td {
        font-size: 8pt;
        padding: 6px 6px;
        border-bottom: 1px solid #e2e8f0;
        color: #334155;
        vertical-align: top;
    }
    table.data-table tr:last-child td {
        border-bottom: none;
    }
    .text-center { text-align: center; }
    .text-right { text-align: right; }
    .text-green { color: #10b981; font-weight: bold; }
    .text-red { color: #ef4444; font-weight: bold; }
    .text-yellow { color: #f59e0b; font-weight: bold; }
    .text-small { font-size: 7.5pt; }

    .status-pill {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 7.5pt;
        font-weight: bold;
    }
    .status-pill.complied {
        background-color: #d1fae5;
        color: #065f46;
    }
    .status-pill.not-complied {
        background-color: #fee2e2;
        color: #991b1b;
    }

    .empty-row td {
        text-align: center;
        padding: 20px;
        color: #94a3b8;
        font-style: italic;
    }

    .task-list {
        margin: 0;
        padding-left: 12px;
        font-size: 7.5pt;
        line-height: 1.4;
    }
    .task-list li {
        margin-bottom: 2px;
    }

    .signature-block {
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid #e2e8f0;
    }
</style>

<h1 class="report-title">TESDA INSPECTION REPORT</h1>
<p style="text-align:center;font-size:9pt;color:#64748b;margin-bottom:8px;">
    Official Compliance Report for TESDA Accreditation and Inspection Purposes
</p>
<p style="text-align:right;font-size:8pt;color:#64748b;margin-bottom:15px;">
    Generated: ' . date('F d, Y h:i A') . '
</p>

<!-- SECTION 1 -->
<h2 class="section-title">Compliance Summary by Qualification</h2>

<table class="data-table" cellpadding="5" cellspacing="0">
    <thead>
        <tr>
            <th width="34%" align="left">Qualification</th>
            <th width="11%" class="text-center">Trainees</th>
            <th width="13%" class="text-center">Total Tasks</th>
            <th width="13%" class="text-center">Complied</th>
            <th width="15%" class="text-center">Not Complied</th>
            <th width="14%" class="text-center">Rate</th>
        </tr>
    </thead>
    <tbody>';

if (empty($qualificationSummary)) {
    $html .= '<tr><td colspan="6" class="empty-row">No qualification data available</td></tr>';
} else {
    foreach ($qualificationSummary as $qual) {
        $qualName = !empty($qual['qualification_name']) ? $qual['qualification_name'] : 'Unknown';
        $totalT = (int)($qual['total_trainees'] ?? 0);
        $totalTasks = (int)($qual['total_tasks'] ?? 0);
        $complied = (int)($qual['complied_count'] ?? 0);
        $notComplied = (int)($qual['not_complied_count'] ?? 0);

        if ($complied > 0 && $notComplied == 0) {
            $rate = 100;
        } elseif ($complied > 0) {
            $totalEvaluated = $complied + $notComplied;
            $rate = $totalEvaluated > 0 ? round(($complied / $totalEvaluated) * 100) : 0;
        } else {
            $rate = 0;
        }

        $rateClass = $rate >= 80 ? 'text-green' : ($rate >= 50 ? 'text-yellow' : 'text-red');

        $html .= '
        <tr>
            <td><b>' . htmlspecialchars($qualName) . '</b></td>
            <td class="text-center">' . $totalT . '</td>
            <td class="text-center">' . $totalTasks . '</td>
            <td class="text-center text-green">' . $complied . '</td>
            <td class="text-center text-red">' . $notComplied . '</td>
            <td class="text-center ' . $rateClass . '"><b>' . $rate . '%</b></td>
        </tr>';
    }
}

$html .= '
    </tbody>
</table>

<!-- SECTION 2 -->
<h2 class="section-title">Performance Task Details</h2>

<table class="data-table" cellpadding="5" cellspacing="0">
    <thead>
        <tr>
            <th width="22%" align="left">Trainee</th>
            <th width="30%" align="left">Qualification</th>
            <th width="12%" class="text-center">Total Tasks</th>
            <th width="10%" class="text-center">Complied</th>
            <th width="13%" class="text-center">Not Complied</th>
            <th width="13%" class="text-center">Status</th>
        </tr>
    </thead>
    <tbody>';

if (empty($taskDetails)) {
    $html .= '<tr><td colspan="6" class="empty-row">No task records available</td></tr>';
} else {
    foreach ($taskDetails as $task) {
        $total = (int)$task['total_tasks'];
        $complied = (int)$task['complied_tasks'];
        $notComplied = (int)$task['not_complied_tasks'];

        if ($complied > 0 && $notComplied == 0) {
            $status = 'Complied';
            $statusClass = 'complied';
        } else {
            $status = 'Not Complied';
            $statusClass = 'not-complied';
        }

        $html .= '
        <tr>
            <td>' . htmlspecialchars($task['trainee_name'] ?? 'N/A') . '</td>
            <td>' . htmlspecialchars($task['qualification_name'] ?? 'N/A') . '</td>
            <td class="text-center">' . $total . '</td>
            <td class="text-center text-green">' . $complied . '</td>
            <td class="text-center text-red">' . $notComplied . '</td>
            <td class="text-center">
                <span class="status-pill ' . $statusClass . '">' . htmlspecialchars($status) . '</span>
            </td>
        </tr>';
    }
}

$html .= '
    </tbody>
</table>

<!-- SECTION 3A: NOT NOTIFIED -->
<h2 class="section-title">Non-Complied / Deficiency Summary — NOT NOTIFIED</h2>
<p class="section-desc">Mga trainee na hindi pa napadhan ng notification para sa kanilang deficient tasks. Isang row bawat trainee, nakalista lahat ng tasks.</p>

<table class="data-table" cellpadding="5" cellspacing="0">
    <thead>
        <tr>
            <th width="20%" align="left">Trainee</th>
            <th width="25%" align="left">Qualification</th>
            <th width="40%" align="left">Performance Tasks</th>
            <th width="15%" class="text-center">Notification Status</th>
        </tr>
    </thead>
    <tbody>';

if (empty($notNotifiedList)) {
    $html .= '<tr><td colspan="4" class="empty-row">✅ Lahat ng deficient tasks ay na-notify na.</td></tr>';
} else {
    foreach ($notNotifiedList as $def) {
        $qualName = !empty($def['qualification_name']) ? $def['qualification_name'] : 'Unknown';
        $taskListHtml = '<ul class="task-list">';
        foreach ($def['tasks'] as $task) {
            $taskListHtml .= '<li>' . htmlspecialchars($task) . '</li>';
        }
        $taskListHtml .= '</ul>';

        $html .= '
        <tr>
            <td>' . htmlspecialchars($def['trainee_name'] ?? 'N/A') . '</td>
            <td>' . htmlspecialchars($qualName) . '</td>
            <td>' . $taskListHtml . '</td>
            <td class="text-center text-red">Not Notified</td>
        </tr>';
    }
}

$html .= '
    </tbody>
</table>

<!-- SECTION 3B: NOTIFIED -->
<h2 class="section-title">Non-Complied / Deficiency Summary — NOTIFIED</h2>
<p class="section-desc">Mga trainee na napadhan na ng notification para sa kanilang deficient tasks. Isang row bawat trainee, nakalista lahat ng tasks.</p>

<table class="data-table" cellpadding="5" cellspacing="0">
    <thead>
        <tr>
            <th width="20%" align="left">Trainee</th>
            <th width="25%" align="left">Qualification</th>
            <th width="40%" align="left">Performance Tasks</th>
            <th width="15%" class="text-center">Notification Status</th>
        </tr>
    </thead>
    <tbody>';

if (empty($notifiedList)) {
    $html .= '<tr><td colspan="4" class="empty-row">Wala pang na-notify na trainee.</td></tr>';
} else {
    foreach ($notifiedList as $def) {
        $qualName = !empty($def['qualification_name']) ? $def['qualification_name'] : 'Unknown';
        $taskListHtml = '<ul class="task-list">';
        foreach ($def['tasks'] as $task) {
            $taskListHtml .= '<li>' . htmlspecialchars($task) . '</li>';
        }
        $taskListHtml .= '</ul>';

        $html .= '
        <tr>
            <td>' . htmlspecialchars($def['trainee_name'] ?? 'N/A') . '</td>
            <td>' . htmlspecialchars($qualName) . '</td>
            <td>' . $taskListHtml . '</td>
            <td class="text-center text-green">Notified</td>
        </tr>';
    }
}

$html .= '
    </tbody>
</table>

<!-- SIGNATURES -->
<div class="signature-block">
    <table cellpadding="0" cellspacing="0" border="0" width="100%">
        <tr>
            <td width="50%" style="border:none;">
                <font size="8.5" color="#475569">Prepared By:</font>
                <br><br><br>
                <b>' . htmlspecialchars($currentTrainerUpper) . '</b><br>
                <font size="8" color="#334155">TESDA Trainer / Instructor</font>
            </td>
            <td width="50%" style="border:none;">
                <font size="8.5" color="#475569">Verified By:</font>
                <br><br><br>
                <b>MR. ROY FERNANDEZ</b><br>
                <font size="8" color="#334155">Head TESDA Coordinator</font>
            </td>
        </tr>
    </table>
</div>';

// ============================================================
// 7. Render
// ============================================================
$pdf->writeHTML($html, true, false, true, false, '');

$filename = 'TESDA_Inspection_Report_' . date('Ymd_His') . '.pdf';

if (ob_get_level()) {
    ob_end_clean();
}

$pdf->Output($filename, 'I');
exit;
?>