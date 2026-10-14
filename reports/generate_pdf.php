<?php
// ============================================================
// SESSION AUTHENTICATION GUARD
// ============================================================

if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_SESSION_ID');
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

$__configPaths = [
    __DIR__ . '/../config/config.php',
    __DIR__ . '/config/config.php',
];
foreach ($__configPaths as $__cfg) {
    if (file_exists($__cfg)) {
        require_once $__cfg;
        break;
    }
}

if (empty($_SESSION['user_id'])) {
    while (ob_get_level()) ob_end_clean();
    header("Location: ../login.php");
    exit();
}

try {
    if (class_exists('Database')) {
        $__row = Database::fetchOne(
            "SELECT active_session_id FROM users WHERE id = ? LIMIT 1",
            [$_SESSION['user_id']]
        );
        $__dbToken    = $__row['active_session_id'] ?? '';
        $__localToken = $_SESSION['active_session_id'] ?? '';

        if ($__dbToken === '' || $__localToken === '' ||
            !hash_equals($__dbToken, $__localToken)) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            while (ob_get_level()) ob_end_clean();
            header("Location: ../login.php");
            exit();
        }
    }
} catch (\Throwable $e) {
    error_log("generate_pdf token verify error: " . $e->getMessage());
    while (ob_get_level()) ob_end_clean();
    header("Location: ../login.php");
    exit();
}

$__roleStr = strtolower(trim($_SESSION['role'] ?? ''));
$__isCoord = false;
foreach (['coordinator', 'admin', 'head', 'supervisor', 'tesda'] as $kw) {
    if (strpos($__roleStr, $kw) !== false) { $__isCoord = true; break; }
}
if (!$__isCoord) {
    while (ob_get_level()) ob_end_clean();
    http_response_code(403);
    die('Access denied. Only Coordinators are authorized to generate PDF reports.');
}

// ============================================================
// generate_pdf.php - Dynamic TESDA Compliance PDF Report Generator
// ============================================================

if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

// ------------------------------------------------------------
// 1. HELPER FUNCTIONS
// ------------------------------------------------------------
if (!function_exists('safeHtml')) {
    function safeHtml($str) {
        if ($str === null) return '';
        return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('cleanTaskName')) {
    function cleanTaskName($task) {
        if ($task === null) return '';
        $task = trim($task);
        $task = str_replace('?', '', $task);
        $task = preg_replace('/\s+/', ' ', $task);
        return trim($task);
    }
}

if (!function_exists('pct')) {
    function pct($num, $den) {
        if ((int)$den === 0) return 0;
        return round(((int)$num / (int)$den) * 100, 1);
    }
}

if (!function_exists('rateClass')) {
    function rateClass($rate) {
        if ($rate >= 80) return 'text-green';
        if ($rate >= 50) return 'text-yellow';
        return 'text-red';
    }
}

if (!function_exists('formatBirthdate')) {
    function formatBirthdate($bd) {
        if (empty($bd) || $bd === '0000-00-00') return 'N/A';
        try {
            $d = new DateTime($bd);
            return $d->format('M j, Y');
        } catch (Exception $e) {
            return 'N/A';
        }
    }
}

if (!function_exists('shortQualName')) {
    function shortQualName($fullName) {
        if ($fullName === null || $fullName === '') return 'N/A';

        $name  = trim($fullName);
        $upper = strtoupper($name);

        $ncLevel = '';
        if (preg_match('/\bNC\s*(I{1,3}V?|IV)\b/i', $name, $m)) {
            $ncLevel = ' NC ' . strtoupper(preg_replace('/\s+/', ' ', $m[1]));
        }

        $map = [
            'AUTOMOTIVE SERVICING'                        => 'AS',
            'COMPUTER SYSTEMS SERVICING'                  => 'CSS',
            'ELECTRONIC PRODUCTS ASSEMBLY AND SERVICING'  => 'EPAS',
            'ELECTRONIC PRODUCTS ASSEMBLY'                => 'EPAS',
            'FOOD AND BEVERAGE SERVICES'                  => 'FBS',
            'HOUSEKEEPING'                                => 'HKS',
        ];

        foreach ($map as $keyword => $abbr) {
            if (strpos($upper, $keyword) !== false) {
                return trim($abbr . $ncLevel);
            }
        }

        $stopwords = ['AND', 'OF', 'THE', 'FOR', 'IN', 'ON', 'AT', 'TO', 'NC'];
        $words     = preg_split('/\s+/', $upper);
        $acronym   = '';
        foreach ($words as $w) {
            $w = preg_replace('/[^A-Z0-9]/', '', $w);
            if ($w === '' || in_array($w, $stopwords, true)) continue;
            $acronym .= $w[0];
        }
        if ($acronym !== '') {
            return trim($acronym . $ncLevel);
        }

        return $name;
    }
}

// ------------------------------------------------------------
// 2. TCPDF INCLUDE
// ------------------------------------------------------------
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
        die('TCPDF Library Not Found');
    }
}
if (!class_exists('TCPDF')) {
    ob_end_clean();
    die('TCPDF Class Not Found');
}

// ------------------------------------------------------------
// 3. SESSION & DB CONNECTION
// ------------------------------------------------------------
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

// ------------------------------------------------------------
// 4. SCHEMA DETECTION
// ------------------------------------------------------------
$traineesColumns = [];
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM trainees");
    foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $traineesColumns[] = $col['Field'];
    }
} catch (Exception $e) {
    $traineesColumns = [];
}

$HAS_BATCH_CODE = in_array('batch_code', $traineesColumns, true);
$HAS_BATCH_ID   = in_array('batch_id', $traineesColumns, true);

// ------------------------------------------------------------
// 5. REQUEST PARAMETERS
// ------------------------------------------------------------
$reportType       = $_GET['report_type'] ?? 'tesda_inspection';
$department       = $_GET['department'] ?? '';
$qualification_id = isset($_GET['qualification_id']) ? (int)$_GET['qualification_id'] : 0;
$search           = $_GET['search'] ?? '';
$batch_id         = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
$trainee_id       = isset($_GET['trainee_id']) ? (int)$_GET['trainee_id'] : 0;

$currentTrainer = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'TESDA Trainer / Instructor';

// ------------------------------------------------------------
// 6. TCPDF EXTENDED CLASS
// ------------------------------------------------------------
class TESDA_Report_PDF extends TCPDF {
    public function Header() {
        $leftLogo = __DIR__ . '/assets/images/Tesda-Logo.png';
        if (!file_exists($leftLogo)) $leftLogo = __DIR__ . '/../assets/images/Tesda-Logo.png';
        if (file_exists($leftLogo)) {
            @$this->Image($leftLogo, 15, 8, 16, '', 'PNG', '', 'T', false, 300, '', false, false, 0);
        }

        $rightLogo = __DIR__ . '/assets/images/love.png';
        if (!file_exists($rightLogo)) $rightLogo = __DIR__ . '/../assets/images/love.png';
        if (file_exists($rightLogo)) {
            $pageW = $this->getPageWidth();
            if ($pageW > 250) {
                $x = $pageW - 31;
            } else {
                $x = 178;
            }
            @$this->Image($rightLogo, $x, 8, 16, '', 'PNG', '', 'T', false, 300, '', false, false, 0);
        }

        $this->SetY(7);
        $this->SetFont('dejavusans', 'B', 12);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(0, 5, 'USAT COLLEGE SAGAY CITY, INC.', 0, 1, 'C');

        $this->SetFont('dejavusans', 'B', 9.5);
        $this->SetTextColor(30, 41, 59);
        $title = 'TESDA PERFORMANCE COMPLIANCE REPORT';
        $this->Cell(0, 4.5, $title, 0, 1, 'C');

        $this->SetFont('dejavusans', '', 8);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 4, 'Date Generated: ' . date('F j, Y'), 0, 1, 'C');

        $this->SetLineWidth(0.3);
        $this->SetDrawColor(203, 213, 225);

        $pageW   = $this->getPageWidth();
        $marginL = $this->getMargins()['left'];
        $marginR = $this->getMargins()['right'];
        $this->Line($marginL, 27, $pageW - $marginR, 27);
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('dejavusans', '', 8);
        $this->SetTextColor(148, 163, 184);
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages(), 0, false, 'C');
    }
}

// ------------------------------------------------------------
// 7. PDF INITIALIZATION & COMMON STYLES
// ------------------------------------------------------------
$reportTitles = [
    'batch'            => 'Batch Compliance Report',
    'individual'       => 'Individual Trainee Performance Report',
    'summary'          => 'Summary Compliance Report',
    'non_compliance'   => 'Non-Compliance Core Competency Matrix Report',
    'tesda_inspection' => 'TESDA Inspection Report',
    'task_hierarchy'   => 'Department Task Structure Report'
];
$reportTitle = $reportTitles[$reportType] ?? 'TESDA Compliance Report';

$pdf = new TESDA_Report_PDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('TESDA Compliance System');
$pdf->SetAuthor($currentTrainer);
$pdf->SetTitle('TESDA ' . $reportTitle);
$pdf->SetMargins(15, 32, 15);
$pdf->SetAutoPageBreak(TRUE, 18);
$pdf->SetFont('dejavusans', '', 9, '', true);

$commonStyles = '
<style>
    .report-heading { color: #0f172a; font-family: dejavusans; text-align: center; font-size: 13pt; font-weight: bold; margin-bottom: 2px; }
    .report-sub { text-align: center; color: #64748b; font-size: 8pt; margin-top: 0px; margin-bottom: 12px; }

    table.exec-box { width: 100%; background-color: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; margin-bottom: 12px; padding: 6px 10px; }
    table.exec-box td { border: none; font-size: 8.5pt; vertical-align: middle; padding: 2px 4px; }
    .exec-label { color: #475569; }
    .exec-value { font-weight: bold; color: #0f172a; }

    table.data-table { width: 100%; border-collapse: collapse; margin-top: 6px; }
    table.data-table th { background-color: #f1f5f9; color: #0f172a; font-weight: bold; font-size: 8pt; border-bottom: 1.5px solid #94a3b8; border-top: 1px solid #cbd5e1; padding: 6px 4px; }
    table.data-table td { font-size: 8pt; border-bottom: 1px solid #e2e8f0; color: #334155; padding: 5.5px 4px; vertical-align: middle; }

    .text-center { text-align: center; }
    .text-green { color: #059669; font-weight: bold; }
    .text-red { color: #dc2626; font-weight: bold; }
    .text-yellow { color: #d97706; font-weight: bold; }

    .status-badge { display: inline-block; padding: 2px 6px; font-size: 7pt; font-weight: bold; }
    .status-badge.complied { background-color: #d1fae5; color: #065f46; }
    .status-badge.not-complied { background-color: #fee2e2; color: #991b1b; }

    .empty-row td { text-align: center; padding: 12px; color: #94a3b8; font-style: italic; }
    .dept-header { background-color: #0f172a; color: #ffffff; font-weight: bold; padding: 5px; font-size: 8.5pt; }
    .qual-header { background-color: #e2e8f0; color: #1e293b; font-weight: bold; padding: 4px 6px; font-size: 8pt; }

    .section-title { font-size: 11pt; font-weight: bold; color: #0f172a; margin-top: 28px; margin-bottom: 10px; padding-top: 12px; border-top: 1px solid #e2e8f0; }
    .section-title.first { margin-top: 8px; padding-top: 0; border-top: none; }
    .section-icon-red { color: #ef4444; }
    .section-icon-green { color: #10b981; }
    .section-icon-purple { color: #8b5cf6; }

    table.data-table,
    table.block-table {
        page-break-inside: avoid !important;
    }

    table.block-table tbody tr {
        page-break-inside: avoid !important;
    }

    table.block-table thead {
        display: table-header-group;
    }

    .ind-tbl th { font-size: 7pt !important; padding: 5px 3px !important; }
    .ind-tbl td { font-size: 7pt !important; padding: 5px 3px !important; }
    .ind-tbl .col-ref { font-family: dejavusans; font-size: 6.5pt; color: #000000; }
    .ind-tbl .col-name { font-weight: bold; font-size: 7pt; }
    .ind-tbl .col-addr { font-size: 6.5pt; color: #475569; }
    .ind-tbl .col-date { font-size: 6.5pt; white-space: nowrap; }
    .ind-tbl .col-email { font-size: 6.5pt; color: #475569; }
    .ind-tbl .col-contact { font-size: 6.5pt; white-space: nowrap; }
    .ind-tbl .col-progress { font-size: 7pt; }
    .ind-tbl .col-status { font-size: 6.5pt; }

    .insp-tbl th { font-size: 8pt !important; padding: 6px 5px !important; }
    .insp-tbl td { font-size: 8pt !important; padding: 5.5px 5px !important; }
    .insp-tbl .col-trainee { font-weight: bold; }
    .insp-tbl .col-qual { color: #334155; }
</style>';

$signatureBlockHtml = '
<br><br>
<table cellpadding="0" cellspacing="0" border="0" width="100%" style="page-break-inside: avoid;">
    <tr>
        <td width="55%" style="border:none;">
            <font size="8" color="#64748b">Verified &amp; Approved By:</font>
            <br><br><br>
            <b>MR. ROY FERNANDEZ</b><br>
            <font size="8" color="#475569">Head TESDA Coordinator</font>
        </td>
        <td width="45%" style="border:none;"></td>
    </tr>
</table>';

// ------------------------------------------------------------
// 8. REPORT SWITCH
// ------------------------------------------------------------
switch ($reportType) {

    // ============================================================
    // INDIVIDUAL TRAINEE REPORT
    // ============================================================
    case 'individual':
        $sql = "
            SELECT
                t.id AS trainee_id,
                t.reference_id,
                t.first_name,
                t.last_name,
                t.suffix,
                t.address,
                t.birthdate,
                t.email,
                t.contact,
                t.department,
                CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) AS full_name,
                q.qualification_name,
                COUNT(DISTINCT pt.id) AS total_tasks,
                COUNT(DISTINCT CASE WHEN et.status = 'complied' THEN et.task_id END) AS completed_tasks
            FROM trainees t
            LEFT JOIN qualifications q ON t.qualification_id = q.id
            LEFT JOIN competency_standards cs ON cs.qualification_id = q.id
            LEFT JOIN performance_tasks pt ON pt.competency_id = cs.id
            LEFT JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = t.id
            WHERE t.deleted_at IS NULL
              AND (t.status IS NULL OR t.status != 'deleted')
        ";
        $p = [];
        if ($trainee_id > 0) {
            $sql .= " AND t.id = :trainee_id";
            $p[':trainee_id'] = $trainee_id;
        }
        if ($department) {
            $sql .= " AND t.department = :department";
            $p[':department'] = $department;
        }
        if ($qualification_id > 0) {
            $sql .= " AND t.qualification_id = :qualification_id";
            $p[':qualification_id'] = $qualification_id;
        }
        if ($batch_id > 0 && $HAS_BATCH_ID) {
            $sql .= " AND t.batch_id = :batch_id";
            $p[':batch_id'] = $batch_id;
        }
        if ($search) {
            $sql .= " AND (CONCAT(t.first_name,' ',COALESCE(t.last_name,'')) LIKE :search OR t.email LIKE :search OR q.qualification_name LIKE :search)";
            $p[':search'] = '%' . $search . '%';
        }

        $groupByCols = "t.id, t.reference_id, t.first_name, t.last_name, t.suffix, t.address, t.birthdate, t.email, t.contact, t.department, q.qualification_name";

        $sql .= " GROUP BY $groupByCols
                  ORDER BY 
                    t.department ASC,
                    t.last_name ASC,
                    t.first_name ASC";

        $stmt = $pdo->prepare($sql);
        foreach ($p as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Build single page
        $pageContent = '
        <div class="report-heading">Individual Trainee Performance Report</div>
        <div class="report-sub">Per-trainee completion breakdown</div>

        <table class="data-table ind-tbl block-table" cellpadding="3" cellspacing="0">
            <thead>
                <tr>
                    <th width="11%">Reference ID</th>
                    <th width="15%">Trainee</th>
                    <th width="16%">Address</th>
                    <th width="9%"  align="center">Birthdate</th>
                    <th width="16%">Email</th>
                    <th width="10%">Contact</th>
                    <th width="11%" align="center">Progress</th>
                    <th width="12%" align="center">Status</th>
                </tr>
            </thead>
            <tbody>';

        if (empty($rows)) {
            $pageContent .= '<tr><td colspan="8" class="empty-row">No trainee performance records found.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $total = (int)$row['total_tasks'];
                $done  = (int)$row['completed_tasks'];
                $pct   = pct($done, $total);

                $statusHtml = ($total > 0 && $pct == 100)
                    ? '<span class="status-badge complied">COMPLIED</span>'
                    : '<span class="status-badge not-complied">NOT COMPLIED</span>';

                $displayName = $row['full_name'] ?? '';
                if (trim($displayName) === '') {
                    $displayName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                }
                if (!empty($row['suffix'])) {
                    $displayName .= ' ' . $row['suffix'];
                }

                $referenceId = !empty($row['reference_id']) ? $row['reference_id'] : 'N/A';
                $address     = !empty($row['address']) ? $row['address'] : 'N/A';
                $birthdate   = formatBirthdate($row['birthdate'] ?? '');
                $email       = !empty($row['email']) ? $row['email'] : 'N/A';
                $contact     = !empty($row['contact']) ? $row['contact'] : 'N/A';

                $pageContent .= '<tr>
                    <td width="11%" class="col-ref">' . safeHtml($referenceId) . '</td>
                    <td width="15%" class="col-name">' . safeHtml($displayName) . '</td>
                    <td width="16%" class="col-addr">' . safeHtml($address) . '</td>
                    <td width="9%"  class="col-date" align="center">' . safeHtml($birthdate) . '</td>
                    <td width="16%" class="col-email">' . safeHtml($email) . '</td>
                    <td width="10%" class="col-contact">' . safeHtml($contact) . '</td>
                    <td width="11%" class="col-progress" align="center">' . $done . ' / ' . $total . '</td>
                    <td width="12%" class="col-status" align="center">' . $statusHtml . '</td>
                </tr>';
            }
        }

        $pageContent .= '</tbody></table>';

        $pdf->AddPage('L', 'A4');
        $html = $commonStyles . $pageContent . $signatureBlockHtml;
        $pdf->writeHTML($html, true, false, true, false, '');
        break;

    // ============================================================
    // SUMMARY COMPLIANCE REPORT
    // ============================================================
    case 'summary':
        $sql = "
            SELECT
                q.qualification_code,
                q.qualification_name,
                COALESCE(t.department, c.course_code) AS department,
                COALESCE(c.course_code, t.department) AS department_code,
                COUNT(DISTINCT t.id) AS total_trainees,
                SUM(
                    CASE 
                        WHEN stats.total_tasks > 0
                         AND stats.complied_tasks = stats.total_tasks
                        THEN 1 ELSE 0 
                    END
                ) AS complied_trainees,
                SUM(
                    CASE 
                        WHEN stats.total_tasks > 0
                         AND stats.complied_tasks < stats.total_tasks
                        THEN 1 ELSE 0 
                    END
                ) AS not_complied_trainees
            FROM qualifications q
            LEFT JOIN courses c ON q.course_id = c.id
            INNER JOIN trainees t ON t.qualification_id = q.id
                AND t.deleted_at IS NULL
                AND (t.status IS NULL OR t.status != 'deleted')
            LEFT JOIN (
                SELECT
                    et.trainee_id,
                    COUNT(et.id) AS total_tasks,
                    SUM(CASE WHEN et.status = 'complied' THEN 1 ELSE 0 END) AS complied_tasks
                FROM evaluation_tracker et
                GROUP BY et.trainee_id
            ) stats ON stats.trainee_id = t.id
            WHERE 1=1
        ";
        $p = [];
        if ($department) {
            $sql .= " AND q.qualification_code = :department";
            $p[':department'] = $department;
        }
        $sql .= " GROUP BY q.id, q.qualification_code, q.qualification_name, department, department_code
                  ORDER BY department_code ASC, q.qualification_name ASC";

        $stmt = $pdo->prepare($sql);
        foreach ($p as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $summaryRaw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $normalizeDept = function($dept) {
            $d = strtoupper(trim($dept ?? ''));
            if ($d === '') return 'OTHER';
            if (strpos($d, 'HRS') !== false || strpos($d, 'HOTEL') !== false) return 'HRS';
            if (strpos($d, 'CSS') !== false || strpos($d, 'COMPUTER') !== false) return 'CSS';
            if (strpos($d, 'ATS') !== false || strpos($d, 'AUTOMOTIVE') !== false) return 'ATS';
            return 'OTHER';
        };

        $deptDisplayName = [
            'HRS'   => 'Hotel and Restaurant Services',
            'CSS'   => 'Computer Systems Servicing',
            'ATS'   => 'Automotive Servicing',
            'OTHER' => 'Other Departments'
        ];

        $grouped = [];
        foreach ($summaryRaw as $row) {
            $deptCode = $normalizeDept($row['department_code'] ?? $row['department'] ?? '');
            $deptName = $deptDisplayName[$deptCode];
            $qualName = $row['qualification_name'] ?? 'Unknown';

            $key = $deptCode . '|' . $qualName;

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'dept_code'      => $deptCode,
                    'dept_name'      => $deptName,
                    'qualification'  => $qualName,
                    'total_trainees' => 0,
                    'complied'       => 0,
                    'not_complied'   => 0,
                ];
            }
            $grouped[$key]['total_trainees'] += (int)$row['total_trainees'];
            $grouped[$key]['complied']       += (int)$row['complied_trainees'];
            $grouped[$key]['not_complied']   += (int)$row['not_complied_trainees'];
        }

        $deptOrder = ['CSS' => 1, 'HRS' => 2, 'ATS' => 3, 'OTHER' => 4];
        uasort($grouped, function($a, $b) use ($deptOrder) {
            $cmp = ($deptOrder[$a['dept_code']] ?? 99) - ($deptOrder[$b['dept_code']] ?? 99);
            if ($cmp !== 0) return $cmp;
            return strcmp($a['qualification'], $b['qualification']);
        });

        $pageContent = '
        <div class="report-heading">Summary Compliance Report</div>
        <div class="report-sub">Consolidated compliance data</div>

        <table class="data-table block-table" cellpadding="4" cellspacing="0">
            <thead>
                <tr>
                    <th width="22%">Department</th>
                    <th width="30%">Qualification</th>
                    <th width="10%" align="center">Total Trainees</th>
                    <th width="10%" align="center">Complied</th>
                    <th width="12%" align="center">Not Complied</th>
                    <th width="16%" align="center">Rate</th>
                </tr>
            </thead>
            <tbody>';

        if (empty($grouped)) {
            $pageContent .= '<tr><td colspan="6" class="empty-row">No summary records found.</td></tr>';
        } else {
            foreach ($grouped as $g) {
                $total    = (int)$g['total_trainees'];
                $complied = (int)$g['complied'];
                $notComp  = (int)$g['not_complied'];
                $rate     = $total > 0 ? round(($complied / $total) * 100) : 0;
                $rateCls  = rateClass($rate);

                $pageContent .= '<tr>
                    <td width="22%" style="font-weight:bold;">' . safeHtml($g['dept_name']) . '</td>
                    <td width="30%">' . safeHtml(shortQualName($g['qualification'])) . '</td>
                    <td width="10%" align="center"><b>' . $total . '</b></td>
                    <td width="10%" align="center" class="text-green">' . $complied . '</td>
                    <td width="12%" align="center" class="text-red">' . $notComp . '</td>
                    <td width="16%" align="center" class="' . $rateCls . '"><b>' . $rate . '%</b></td>
                </tr>';
            }
        }

        $pageContent .= '</tbody></table>';

        $pdf->AddPage();
        $html = $commonStyles . $pageContent . $signatureBlockHtml;
        $pdf->writeHTML($html, true, false, true, false, '');
        break;

    // ============================================================
    // TESDA INSPECTION REPORT
    // ============================================================
    case 'tesda_inspection':
        // -------- Section 1: Summary by Qualification --------
        $sql1 = "
            SELECT
                q.id AS qual_id,
                q.qualification_name,
                COALESCE(t.department, c.course_code) AS department,
                COUNT(DISTINCT CASE WHEN stats.total_tasks > 0 THEN t.id END) AS total_trainees,
                SUM(
                    CASE 
                        WHEN stats.total_tasks > 0
                         AND stats.complied_tasks = stats.total_tasks
                        THEN 1 ELSE 0 
                    END
                ) AS complied_count,
                SUM(
                    CASE 
                        WHEN stats.total_tasks > 0
                         AND stats.complied_tasks < stats.total_tasks
                        THEN 1 ELSE 0 
                    END
                ) AS not_complied_count
            FROM qualifications q
            LEFT JOIN courses c ON q.course_id = c.id
            LEFT JOIN trainees t ON t.qualification_id = q.id
                AND t.deleted_at IS NULL
                AND (t.status IS NULL OR t.status != 'deleted')
            LEFT JOIN (
                SELECT
                    et.trainee_id,
                    COUNT(et.id) AS total_tasks,
                    SUM(CASE WHEN et.status = 'complied' THEN 1 ELSE 0 END) AS complied_tasks
                FROM evaluation_tracker et
                GROUP BY et.trainee_id
            ) stats ON stats.trainee_id = t.id
            WHERE 1=1
        ";
        $p1 = [];
        if ($department) {
            $sql1 .= " AND q.qualification_code = :department";
            $p1[':department'] = $department;
        }
        if ($qualification_id > 0) {
            $sql1 .= " AND q.id = :qualification_id";
            $p1[':qualification_id'] = $qualification_id;
        }
        $sql1 .= " GROUP BY q.id, q.qualification_name, department
                   ORDER BY q.qualification_name ASC";

        $stmt = $pdo->prepare($sql1);
        foreach ($p1 as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $qualSummary = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $agg = [];
        foreach ($qualSummary as $row) {
            $qn = $row['qualification_name'];
            if (!isset($agg[$qn])) {
                $agg[$qn] = ['total' => 0, 'complied' => 0, 'not_complied' => 0];
            }
            $agg[$qn]['total']        += (int)$row['total_trainees'];
            $agg[$qn]['complied']     += (int)$row['complied_count'];
            $agg[$qn]['not_complied'] += (int)$row['not_complied_count'];
        }

        $pageContent = '
        <div class="report-heading">TESDA INSPECTION REPORT</div>
        <div class="report-sub">Compliance Summary by Qualification</div>

        <table class="data-table insp-tbl block-table" cellpadding="5" cellspacing="0">
            <thead>
                <tr>
                    <th width="40%" align="left">Qualification</th>
                    <th width="15%" align="center">Trainees</th>
                    <th width="15%" align="center">Complied</th>
                    <th width="15%" align="center">Not Complied</th>
                    <th width="15%" align="center">Rate</th>
                </tr>
            </thead>
            <tbody>';

        if (empty($agg)) {
            $pageContent .= '<tr><td colspan="5" class="empty-row">No qualification data available.</td></tr>';
        } else {
            foreach ($agg as $qn => $data) {
                $total = $data['total'];
                $comp  = $data['complied'];
                $nc    = $data['not_complied'];
                $rate  = $total > 0 ? round(($comp / $total) * 100) : 0;
                $rateCls = rateClass($rate);

                $pageContent .= '<tr>
                    <td width="40%"><b>' . safeHtml(shortQualName($qn)) . '</b></td>
                    <td width="15%" align="center">' . $total . '</td>
                    <td width="15%" align="center" class="text-green">' . $comp . '</td>
                    <td width="15%" align="center" class="text-red">' . $nc . '</td>
                    <td width="15%" align="center" class="' . $rateCls . '"><b>' . $rate . '%</b></td>
                </tr>';
            }
        }
        $pageContent .= '</tbody></table>';

        $pdf->AddPage('L', 'A4');
        $html = $commonStyles . '
        <div class="section-title section-icon-purple first">Compliance Summary by Qualification</div>' . $pageContent;
        $pdf->writeHTML($html, true, false, true, false, '');

        // -------- Section 2: Performance Task Details --------
        $sql2 = "
            SELECT
                t.id AS trainee_id,
                CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) AS trainee_name,
                q.qualification_name,
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
        ";
        $p2 = [];
        if ($department) {
            $sql2 .= " AND q.qualification_code = :department";
            $p2[':department'] = $department;
        }
        if ($qualification_id > 0) {
            $sql2 .= " AND q.id = :qualification_id";
            $p2[':qualification_id'] = $qualification_id;
        }

        $groupByCols2 = "t.id, t.first_name, t.last_name, q.qualification_name";
        $sql2 .= " GROUP BY $groupByCols2 ORDER BY t.last_name ASC, t.first_name ASC";

        $stmt = $pdo->prepare($sql2);
        foreach ($p2 as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $taskDetails = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $pageContent = '
        <div class="report-heading">TESDA INSPECTION REPORT</div>
        <div class="report-sub">Performance Task Details</div>

        <table class="data-table insp-tbl block-table" cellpadding="5" cellspacing="0">
            <thead>
                <tr>
                    <th width="26%" align="left">Trainee</th>
                    <th width="26%" align="left">Qualification</th>
                    <th width="10%" align="center">Total Tasks</th>
                    <th width="10%" align="center">Complied</th>
                    <th width="12%" align="center">Not Complied</th>
                    <th width="16%" align="center">Status</th>
                </tr>
            </thead>
            <tbody>';

        if (empty($taskDetails)) {
            $pageContent .= '<tr><td colspan="6" class="empty-row">No task records available.</td></tr>';
        } else {
            foreach ($taskDetails as $row) {
                $total       = (int)$row['total_tasks'];
                $complied    = (int)$row['complied_tasks'];
                $notComplied = (int)$row['not_complied_tasks'];

                if ($complied > 0 && $notComplied == 0 && $complied >= $total) {
                    $status = 'Complied';
                    $statusCls = 'complied';
                } else {
                    $status = 'Not Complied';
                    $statusCls = 'not-complied';
                }

                $pageContent .= '<tr>
                    <td width="26%" class="col-trainee"><b>' . safeHtml($row['trainee_name']) . '</b></td>
                    <td width="26%" class="col-qual">' . safeHtml(shortQualName($row['qualification_name'])) . '</td>
                    <td width="10%" align="center"><b>' . $total . '</b></td>
                    <td width="10%" align="center" class="text-green">' . $complied . '</td>
                    <td width="12%" align="center" class="text-red">' . $notComplied . '</td>
                    <td width="16%" align="center">
                        <span class="status-badge ' . $statusCls . '">' . $status . '</span>
                    </td>
                </tr>';
            }
        }
        $pageContent .= '</tbody></table>';

        $pdf->AddPage('L', 'A4');
        $html = $commonStyles . '
        <div class="section-title section-icon-purple first">Performance Task Details</div>' . $pageContent;
        $pdf->writeHTML($html, true, false, true, false, '');

        // -------- Section 3: Deficiency --------
        $sql3 = "
            SELECT
                t.id AS trainee_id,
                CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) AS trainee_name,
                q.qualification_name,
                pt.task_name,
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
              AND (et.status IS NULL OR et.status != 'complied')
        ";
        $p3 = [];
        if ($department) {
            $sql3 .= " AND q.qualification_code = :department";
            $p3[':department'] = $department;
        }
        if ($qualification_id > 0) {
            $sql3 .= " AND q.id = :qualification_id";
            $p3[':qualification_id'] = $qualification_id;
        }

        $sql3 .= " ORDER BY t.last_name ASC, t.first_name ASC, q.qualification_name ASC, pt.id ASC";

        $stmt = $pdo->prepare($sql3);
        foreach ($p3 as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $deficiencyRaw = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $defGrouped = [];
        foreach ($deficiencyRaw as $row) {
            $key = $row['trainee_id'] . '|' . $row['qualification_name'] . '|' . $row['notification_status'];
            if (!isset($defGrouped[$key])) {
                $defGrouped[$key] = [
                    'trainee_name'        => $row['trainee_name'],
                    'qualification_name'  => $row['qualification_name'],
                    'notification_status' => $row['notification_status'],
                    'tasks'               => []
                ];
            }
            $defGrouped[$key]['tasks'][] = $row['task_name'];
        }

        // NOT NOTIFIED
        $notNotified = array_filter($defGrouped, function($r) { return $r['notification_status'] === 'Not Notified'; });
        if (!empty($notNotified)) {
            $pageContent = '
            <div class="report-heading">TESDA INSPECTION REPORT</div>
            <div class="report-sub">Non-Complied / Deficiency Summary — NOT NOTIFIED</div>

            <table class="data-table insp-tbl block-table" cellpadding="5" cellspacing="0">
                <thead>
                    <tr>
                        <th width="30%" align="left">Trainee</th>
                        <th width="40%" align="left">Qualification</th>
                        <th width="15%" align="center">No. of Tasks</th>
                        <th width="15%" align="center">Notification Status</th>
                    </tr>
                </thead>
                <tbody>';

            foreach ($notNotified as $def) {
                $taskCount = count($def['tasks']);
                $pageContent .= '<tr>
                    <td width="30%"><b>' . safeHtml($def['trainee_name']) . '</b></td>
                    <td width="40%">' . safeHtml(shortQualName($def['qualification_name'])) . '</td>
                    <td width="15%" align="center"><b>' . $taskCount . '</b></td>
                    <td width="15%" align="center" class="text-red">Not Notified</td>
                </tr>';
            }
            $pageContent .= '</tbody></table>';

            $pdf->AddPage('L', 'A4');
            $html = $commonStyles . '
            <div class="section-title section-icon-red first">Non-Complied / Deficiency Summary — NOT NOTIFIED</div>' . $pageContent;
            $pdf->writeHTML($html, true, false, true, false, '');
        }

        // NOTIFIED
        $notified = array_filter($defGrouped, function($r) { return $r['notification_status'] === 'Notified'; });
        if (!empty($notified)) {
            $pageContent = '
            <div class="report-heading">TESDA INSPECTION REPORT</div>
            <div class="report-sub">Non-Complied / Deficiency Summary — NOTIFIED</div>

            <table class="data-table insp-tbl block-table" cellpadding="5" cellspacing="0">
                <thead>
                    <tr>
                        <th width="30%" align="left">Trainee</th>
                        <th width="40%" align="left">Qualification</th>
                        <th width="15%" align="center">No. of Tasks</th>
                        <th width="15%" align="center">Notification Status</th>
                    </tr>
                </thead>
                <tbody>';

            foreach ($notified as $def) {
                $taskCount = count($def['tasks']);
                $pageContent .= '<tr>
                    <td width="30%"><b>' . safeHtml($def['trainee_name']) . '</b></td>
                    <td width="40%">' . safeHtml(shortQualName($def['qualification_name'])) . '</td>
                    <td width="15%" align="center"><b>' . $taskCount . '</b></td>
                    <td width="15%" align="center" class="text-green">Notified</td>
                </tr>';
            }
            $pageContent .= '</tbody></table>';

            $pdf->AddPage('L', 'A4');
            $html = $commonStyles . '
            <div class="section-title section-icon-green first">Non-Complied / Deficiency Summary — NOTIFIED</div>' . $pageContent;
            $pdf->writeHTML($html, true, false, true, false, '');
        }

        // Signature page
        $pdf->AddPage('L', 'A4');
        $pdf->writeHTML($commonStyles . $signatureBlockHtml, true, false, true, false, '');

        break;

    // ============================================================
    // NON-COMPLIANCE CORE COMPETENCY MATRIX
    // ============================================================
    case 'non_compliance':
        $coreCompetencyStmt = $pdo->query("
            SELECT cs.id AS competency_id, cs.competency_name, cs.unit_of_competency,
                   cs.qualification_id, q.qualification_name, q.qualification_code
            FROM competency_standards cs
            INNER JOIN qualifications q ON cs.qualification_id = q.id
            WHERE LOWER(cs.unit_of_competency) = 'core'
            ORDER BY q.qualification_name, cs.id
        ");
        $allCoreCompetencies = $coreCompetencyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $coreCompetenciesByQual = [];
        $qualificationMeta      = [];
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
                'competency_id'    => (int)$comp['competency_id'],
                'competency_title' => $comp['competency_name'],
            ];
        }

        $tasksByCompetency = [];
        foreach ($pdo->query("
            SELECT id AS task_id, competency_id, task_name
            FROM performance_tasks
            ORDER BY competency_id, id
        ")->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $cid = (int)$t['competency_id'];
            if (!isset($tasksByCompetency[$cid])) $tasksByCompetency[$cid] = [];
            $tasksByCompetency[$cid][(int)$t['task_id']] = $t['task_name'];
        }

        $traineesSql = "
            SELECT t.id AS trainee_id, t.first_name, t.last_name, t.department, t.qualification_id
            FROM trainees t
            WHERE t.qualification_id IS NOT NULL AND t.qualification_id > 0
              AND t.deleted_at IS NULL
              AND (t.status IS NULL OR t.status != 'deleted')
        ";
        $tp = [];
        if ($department) {
            $traineesSql .= " AND t.department = :department";
            $tp[':department'] = $department;
        }
        if ($qualification_id > 0) {
            $traineesSql .= " AND t.qualification_id = :qualification_id";
            $tp[':qualification_id'] = $qualification_id;
        }
        if ($batch_id > 0 && $HAS_BATCH_ID) {
            $traineesSql .= " AND t.batch_id = :batch_id";
            $tp[':batch_id'] = $batch_id;
        }
        if ($search) {
            $traineesSql .= " AND CONCAT(t.first_name,' ',COALESCE(t.last_name,'')) LIKE :search";
            $tp[':search'] = '%' . $search . '%';
        }

        $traineesSql .= " ORDER BY t.department ASC, t.last_name ASC, t.first_name ASC";

        $stmt = $pdo->prepare($traineesSql);
        foreach ($tp as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $allTrainees = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $evalByTraineeTask = [];
        foreach ($pdo->query("
            SELECT et.trainee_id, et.task_id, et.status, et.remarks
            FROM evaluation_tracker et
        ")->fetchAll(PDO::FETCH_ASSOC) as $ev) {
            $tid    = (int)$ev['trainee_id'];
            $taskId = (int)$ev['task_id'];
            $sL     = strtolower($ev['status'] ?? '');
            $rL     = strtolower($ev['remarks'] ?? '');
            $isComplied = ($sL === 'complied' || $sL === 'done' || $sL === 'competent' || strpos($rL, 'done') !== false);
            $evalByTraineeTask[$tid][$taskId] = $isComplied;
        }

        $normalizeDept = function($d) {
            $d = strtoupper(trim($d ?? ''));
            if ($d === '') return 'UNASSIGNED';
            if (strpos($d, 'HRS') !== false || strpos($d, 'HOTEL') !== false) return 'HRS';
            if (strpos($d, 'CSS') !== false || strpos($d, 'COMPUTER') !== false) return 'CSS';
            if (strpos($d, 'ATS') !== false || strpos($d, 'AUTOMOTIVE') !== false) return 'ATS';
            return $d;
        };

        $groupedByDept = [];

        foreach ($allTrainees as $tr) {
            $tid = (int)$tr['trainee_id'];
            $qid = (int)$tr['qualification_id'];
            if (!isset($coreCompetenciesByQual[$qid]) || empty($coreCompetenciesByQual[$qid])) continue;

            $competencies  = $coreCompetenciesByQual[$qid];
            $hasDeficiency = false;

            foreach ($competencies as $c) {
                $cid   = (int)$c['competency_id'];
                $tasks = $tasksByCompetency[$cid] ?? [];
                if (empty($tasks)) { $hasDeficiency = true; break; }
                foreach ($tasks as $taskId => $taskName) {
                    $isComplied = $evalByTraineeTask[$tid][$taskId] ?? false;
                    if (!$isComplied) { $hasDeficiency = true; break 2; }
                }
            }
            if (!$hasDeficiency) continue;

            $dk = $normalizeDept($tr['department'] ?? '');
            if (!isset($groupedByDept[$dk])) $groupedByDept[$dk] = [];
            if (!isset($groupedByDept[$dk][$qid])) {
                $groupedByDept[$dk][$qid] = [
                    'meta'     => $qualificationMeta[$qid],
                    'trainees' => []
                ];
            }
            $name = trim(($tr['first_name'] ?? '') . ' ' . ($tr['last_name'] ?? ''));
            if ($name === '') $name = 'Unknown Trainee';
            $groupedByDept[$dk][$qid]['trainees'][$tid] = $name;
        }

        $deptOrder = ['CSS' => 1, 'HRS' => 2, 'ATS' => 3, 'OTHER' => 4];

        if (empty($groupedByDept)) {
            $pdf->AddPage('L', 'A4');
            $pdf->writeHTML($commonStyles . '
                <div class="report-heading">Non-Compliance Core Competency Matrix</div>
                <div class="report-sub">Transposed layout — Competency / Performance Task vs Trainees</div>
                <table class="data-table" cellpadding="10" cellspacing="0">
                    <tr><td class="empty-row" style="color:#059669;font-weight:bold;text-align:center;">
                        All trainees are fully compliant with core competencies.
                    </td></tr>
                </table>' . $signatureBlockHtml, true, false, true, false, '');
        } else {
            uksort($groupedByDept, function($a, $b) use ($deptOrder) {
                return ($deptOrder[$a] ?? 99) - ($deptOrder[$b] ?? 99);
            });

            foreach ($groupedByDept as $deptCode => $qualGroups) {
                $pdf->AddPage('L', 'A4');

                $deptTraineeTotal = 0;
                foreach ($qualGroups as $qg) $deptTraineeTotal += count($qg['trainees']);

                $pageHtml = $commonStyles . '
                <div class="report-heading">Non-Compliance Core Competency Matrix</div>
                <div class="report-sub">Transposed layout (&#10003; = Complied, &#10007; = Not Complied / Pending)</div>
                <table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:8px;background-color:#0f172a;">
                    <tr>
                        <td style="color:#fff;font-size:10pt;font-weight:bold;padding:6px 8px;">' . safeHtml($deptCode) . ' DEPARTMENT</td>
                        <td style="color:#cbd5e1;font-size:8.5pt;padding:6px 8px;text-align:right;">' . $deptTraineeTotal . ' deficient trainee(s)</td>
                    </tr>
                </table>';

                foreach ($qualGroups as $qid => $qg) {
                    $meta         = $qg['meta'];
                    $trainees     = $qg['trainees'];
                    $competencies = $coreCompetenciesByQual[$qid] ?? [];

                    $pageHtml .= '
                    <table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:6px;background-color:#e2e8f0;">
                        <tr>
                            <td style="color:#1e293b;font-size:9pt;font-weight:bold;padding:4px 8px;">' . safeHtml(shortQualName($meta['qualification_name'])) . '</td>
                        </tr>
                    </table>';

                    $traineeCount = count($trainees);
                    $firstColW    = 40;
                    $traineeColW  = $traineeCount > 0 ? round((100 - $firstColW) / $traineeCount, 2) : 60;

                    $pageHtml .= '<table class="data-table" cellpadding="4" cellspacing="0" border="0"><thead><tr>';
                    $pageHtml .= '<th width="' . $firstColW . '%" align="left" style="background-color:#f1f5f9;font-size:8pt;">COMPETENCY / PERFORMANCE TASK</th>';
                    foreach ($trainees as $tid => $tname) {
                        $pageHtml .= '<th width="' . $traineeColW . '%" align="center" style="background-color:#f1f5f9;font-size:7.5pt;">'
                                  . safeHtml($tname) . '</th>';
                    }
                    $pageHtml .= '</tr></thead><tbody>';

                    foreach ($competencies as $comp) {
                        $cid   = (int)$comp['competency_id'];
                        $cname = $comp['competency_title'];
                        $tasks = $tasksByCompetency[$cid] ?? [];

                        $pageHtml .= '<tr>
                            <td colspan="' . ($traineeCount + 1) . '"
                                style="background-color:#cbd5e1;color:#0f172a;font-weight:bold;font-size:8pt;padding:5px 6px;border-top:1px solid #94a3b8;">
                                ' . safeHtml($cname) . '
                            </td>
                        </tr>';

                        if (empty($tasks)) {
                            $pageHtml .= '<tr><td width="' . $firstColW . '%" style="font-size:7.5pt;font-style:italic;color:#94a3b8;padding:4px 6px;">(No tasks defined for this competency)</td>';
                            foreach ($trainees as $tid => $tname) {
                                $pageHtml .= '<td width="' . $traineeColW . '%" align="center" style="color:#dc2626;font-weight:bold;font-size:12pt;">&#10007;</td>';
                            }
                            $pageHtml .= '</tr>';
                            continue;
                        }

                        foreach ($tasks as $taskId => $taskName) {
                            $cleanTask = cleanTaskName($taskName);
                            $pageHtml .= '<tr>';
                            $pageHtml .= '<td width="' . $firstColW . '%" style="font-size:7.5pt;padding:4px 6px;color:#334155;">'
                                      . safeHtml($cleanTask) . '</td>';

                            foreach ($trainees as $tid => $tname) {
                                $isComplied = $evalByTraineeTask[$tid][$taskId] ?? false;
                                if ($isComplied) {
                                    $pageHtml .= '<td width="' . $traineeColW . '%" align="center"
                                        style="background-color:#d1fae5;color:#059669;font-weight:bold;font-size:12pt;">&#10003;</td>';
                                } else {
                                    $pageHtml .= '<td width="' . $traineeColW . '%" align="center"
                                        style="background-color:#fee2e2;color:#dc2626;font-weight:bold;font-size:12pt;">&#10007;</td>';
                                }
                            }
                            $pageHtml .= '</tr>';
                        }
                    }

                    $pageHtml .= '</tbody></table><br>';
                }

                $pdf->writeHTML($pageHtml, true, false, true, false, '');
            }

            // Signature page
            $pdf->AddPage('L', 'A4');
            $pdf->writeHTML($commonStyles . $signatureBlockHtml, true, false, true, false, '');
        }
        break;

    // ============================================================
    // BATCH COMPLIANCE REPORT
    // ============================================================
    case 'batch':
        $pdf->AddPage();

        $batchCol = $HAS_BATCH_CODE
            ? "t.batch_code"
            : ($HAS_BATCH_ID ? "t.batch_id" : "'General'");

        $sql = "
            SELECT
                $batchCol AS batch_label,
                q.qualification_name,
                COUNT(DISTINCT t.id) AS total_trainees,
                COUNT(DISTINCT CASE WHEN et.status = 'complied' THEN et.id END) AS total_complied,
                COUNT(DISTINCT et.id) AS total_evaluations
            FROM trainees t
            LEFT JOIN qualifications q ON t.qualification_id = q.id
            LEFT JOIN evaluation_tracker et ON et.trainee_id = t.id
            WHERE t.deleted_at IS NULL
              AND (t.status IS NULL OR t.status != 'deleted')
        ";
        $p = [];
        if ($department) {
            $sql .= " AND t.department = :department";
            $p[':department'] = $department;
        }
        if ($qualification_id > 0) {
            $sql .= " AND t.qualification_id = :qualification_id";
            $p[':qualification_id'] = $qualification_id;
        }
        $sql .= " GROUP BY batch_label, q.qualification_name
                  ORDER BY batch_label ASC, q.qualification_name ASC";

        $stmt = $pdo->prepare($sql);
        foreach ($p as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $batchRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $totalBatches     = count($batchRows);
        $sumBatchTrainees = 0;
        $sumBatchEvals    = 0;
        $sumBatchComplied = 0;

        $bodyRows = '';
        if (!empty($batchRows)) {
            foreach ($batchRows as $row) {
                $comp  = (int)$row['total_complied'];
                $total = (int)$row['total_evaluations'];
                $rate  = pct($comp, $total);

                $sumBatchTrainees += (int)$row['total_trainees'];
                $sumBatchEvals    += $total;
                $sumBatchComplied += $comp;

                $bodyRows .= '<tr>
                    <td width="22%"><b>' . safeHtml($row['batch_label'] ?: 'N/A') . '</b></td>
                    <td width="38%">' . safeHtml(shortQualName($row['qualification_name'] ?? '')) . '</td>
                    <td width="12%" align="center">' . number_format($row['total_trainees']) . '</td>
                    <td width="14%" align="center">' . number_format($comp) . ' / ' . number_format($total) . '</td>
                    <td width="14%" align="center" class="' . rateClass($rate) . '">' . $rate . '%</td>
                </tr>';
            }
        } else {
            $bodyRows = '<tr><td colspan="5" class="empty-row">No batch records found.</td></tr>';
        }

        $overallBatchRate = pct($sumBatchComplied, $sumBatchEvals);

        $execSummaryHtml = '
        <table class="exec-box" cellpadding="3" cellspacing="0">
            <tr>
                <td width="25%"><span class="exec-label">Batches Count:</span> <span class="exec-value">' . number_format($totalBatches) . '</span></td>
                <td width="25%"><span class="exec-label">Total Trainees:</span> <span class="exec-value">' . number_format($sumBatchTrainees) . '</span></td>
                <td width="25%"><span class="exec-label">Total Evaluations:</span> <span class="exec-value">' . number_format($sumBatchEvals) . '</span></td>
                <td width="25%"><span class="exec-label">Batch Pass Rate:</span> <span class="exec-value ' . rateClass($overallBatchRate) . '">' . $overallBatchRate . '%</span></td>
            </tr>
        </table>';

        $html = $commonStyles . '
        <div class="report-heading">Batch Compliance Report</div>
        <div class="report-sub">Aggregated performance and evaluation rates grouped by batch</div>
        ' . $execSummaryHtml . '
        <table class="data-table block-table" cellpadding="4" cellspacing="0">
            <thead>
                <tr>
                    <th width="22%">Batch Code</th>
                    <th width="38%">Qualification</th>
                    <th width="12%" align="center">Trainees</th>
                    <th width="14%" align="center">Evaluations</th>
                    <th width="14%" align="center">Rate</th>
                </tr>
            </thead>
            <tbody>' . $bodyRows . '</tbody>
        </table>' . $signatureBlockHtml;

        $pdf->writeHTML($html, true, false, true, false, '');
        break;

    // ============================================================
    // TASK HIERARCHY REPORT (default)
    // ============================================================
    case 'task_hierarchy':
    default:
        $pdf->AddPage();

        $sql = "
            SELECT
                COALESCE(q.qualification_code, 'General') AS department,
                q.qualification_name,
                cs.unit_of_competency,
                cs.code_no,
                cs.competency_name,
                pt.task_name
            FROM performance_tasks pt
            JOIN competency_standards cs ON pt.competency_id = cs.id
            JOIN qualifications q ON cs.qualification_id = q.id
            WHERE 1=1
        ";
        $params = [];
        if ($department) {
            $sql .= " AND q.qualification_code = :department";
            $params[':department'] = $department;
        }
        if ($qualification_id > 0) {
            $sql .= " AND q.id = :qualification_id";
            $params[':qualification_id'] = $qualification_id;
        }
        if ($search) {
            $sql .= " AND (pt.task_name LIKE :search OR cs.competency_name LIKE :search OR q.qualification_name LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        $sql .= " ORDER BY q.qualification_code ASC, q.qualification_name ASC, cs.unit_of_competency ASC, cs.id ASC";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        $tasksData = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $totalTasksCount = count($tasksData);
        $uniqueQuals     = [];
        $uniqueComps     = [];

        $rows = '';
        $currentDept = '';
        $currentQual = '';

        if (!empty($tasksData)) {
            foreach ($tasksData as $row) {
                $uniqueQuals[$row['qualification_name']] = true;
                $uniqueComps[$row['competency_name']]    = true;

                if ($currentDept !== $row['department']) {
                    $currentDept = $row['department'];
                    $rows .= '<tr><td colspan="3" class="dept-header">' . safeHtml(strtoupper($currentDept)) . ' DEPARTMENT</td></tr>';
                    $currentQual = '';
                }
                if ($currentQual !== $row['qualification_name']) {
                    $currentQual = $row['qualification_name'];
                    $rows .= '<tr><td colspan="3" class="qual-header">Qualification: ' . safeHtml(shortQualName($currentQual)) . '</td></tr>';
                }
                $unitCode = !empty($row['code_no'])
                    ? '[' . safeHtml($row['code_no']) . '] '
                    : '';
                $compType = !empty($row['unit_of_competency'])
                    ? strtoupper($row['unit_of_competency'])
                    : 'GENERAL';

                $rows .= '<tr>
                    <td width="18%" align="center">' . safeHtml($compType) . '</td>
                    <td width="37%"><b>' . $unitCode . safeHtml($row['competency_name']) . '</b></td>
                    <td width="45%">' . safeHtml(cleanTaskName($row['task_name'])) . '</td>
                </tr>';
            }
        } else {
            $rows = '<tr><td colspan="3" class="empty-row">No performance tasks found.</td></tr>';
        }

        $execSummaryHtml = '
        <table class="exec-box" cellpadding="3" cellspacing="0">
            <tr>
                <td width="33%"><span class="exec-label">Qualifications Registered:</span> <span class="exec-value">' . number_format(count($uniqueQuals)) . '</span></td>
                <td width="33%"><span class="exec-label">Competency Units:</span> <span class="exec-value">' . number_format(count($uniqueComps)) . '</span></td>
                <td width="34%"><span class="exec-label">Total Performance Tasks:</span> <span class="exec-value">' . number_format($totalTasksCount) . '</span></td>
            </tr>
        </table>';

        $html = $commonStyles . '
        <div class="report-heading">Department Task Structure Report</div>
        <div class="report-sub">Breakdown of qualifications, competency units, and required tasks</div>
        ' . $execSummaryHtml . '
        <table class="data-table block-table" cellpadding="4" cellspacing="0">
            <thead>
                <tr>
                    <th width="18%" align="center">Unit Type</th>
                    <th width="37%">Competency Unit Title</th>
                    <th width="45%">Performance Task Requirement</th>
                </tr>
            </thead>
            <tbody>' . $rows . '</tbody>
        </table>' . $signatureBlockHtml;

        $pdf->writeHTML($html, true, false, true, false, '');
        break;
}

// ------------------------------------------------------------
// 9. RENDER PDF OUTPUT
// ------------------------------------------------------------
ob_end_clean();
$pdf->Output('TESDA_' . $reportType . '_report_' . date('Y-m-d') . '.pdf', 'I');
exit;