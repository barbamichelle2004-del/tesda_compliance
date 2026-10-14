<?php
// fetch_report_details.php - AJAX handler for dynamic report loading

// ============================================================
// SESSION — must match login.php
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_name('APP_SESSION_ID');
    session_start();
}

header('Content-Type: text/html; charset=UTF-8');

function formatQualificationName($fullName) {
    if (empty($fullName)) return 'N/A';
    return $fullName;
}

$host = 'localhost';
$dbname = 'tesda_compliance';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo '<div class="alert alert-danger">Database connection failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
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

// ============================================================
// TRAINER SCOPE — DENY BY DEFAULT
// ============================================================
$trainerQualificationIds = [];

if (!$isCoordinator) {
    if ($currentUserId > 0) {
        try {
            $assignStmt = $pdo->prepare("
                SELECT DISTINCT qualification_id 
                FROM user_qualifications 
                WHERE user_id = :user_id
            ");
            $assignStmt->execute([':user_id' => $currentUserId]);
            $trainerQualificationIds = $assignStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Exception $e) { }
    }

    if (empty($trainerQualificationIds) && !empty(session_id())) {
        try {
            $sidStmt = $pdo->prepare("
                SELECT uq.qualification_id
                FROM user_qualifications uq
                JOIN users u ON u.id = uq.user_id
                WHERE u.current_session_id = :sid
                  AND u.status = 'active'
            ");
            $sidStmt->execute([':sid' => session_id()]);
            $trainerQualificationIds = $sidStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Exception $e) { }
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

$report_type = $_GET['report_type'] ?? $_GET['target'] ?? 'individual';
$department = $_GET['department'] ?? '';
$qualification_id = isset($_GET['qualification_id']) ? (int)$_GET['qualification_id'] : 0;
$search = $_GET['search'] ?? '';

// ============================================================
// HELPER FUNCTIONS
// ============================================================
function getDepartmentColor($department) {
    $deptColors = ['ATS'=>'#F59E0B','CSS'=>'#10B981','HRS'=>'#8B5CF6','AUT'=>'#F59E0B','AS'=>'#F59E0B'];
    $deptUpper = strtoupper($department);
    foreach ($deptColors as $code => $color) {
        if (strpos($deptUpper, $code) !== false) return $color;
    }
    return '#8B5CF6';
}

function getDepartmentClass($department) {
    $deptUpper = strtoupper($department);
    if (strpos($deptUpper, 'ATS') !== false || strpos($deptUpper, 'AUTOMOTIVE') !== false) return 'automotive';
    if (strpos($deptUpper, 'CSS') !== false || strpos($deptUpper, 'COMPUTER') !== false) return 'css';
    if (strpos($deptUpper, 'HRS') !== false || strpos($deptUpper, 'HOTEL') !== false) return 'hrs';
    return 'hrs';
}

function getDepartmentDisplayName($deptCode) {
    $map = [
        'HRS' => 'HOTEL AND RESTAURANT SERVICES',
        'CSS' => 'COMPUTER SYSTEMS SERVICING',
        'ATS' => 'AUTOMOTIVE MECHANICS',
        'OTHER' => 'OTHER DEPARTMENTS'
    ];
    return $map[strtoupper($deptCode)] ?? strtoupper($deptCode);
}

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

function formatBirthdate($bd) {
    if (empty($bd) || $bd === '0000-00-00') return 'N/A';
    try {
        $d = new DateTime($bd);
        return $d->format('M j, Y');
    } catch (Exception $e) {
        return 'N/A';
    }
}

function sortTraineesByName(&$trainees) {
    usort($trainees, function($a, $b) {
        $lastCmp = strcasecmp($a['last_name'] ?? '', $b['last_name'] ?? '');
        if ($lastCmp !== 0) return $lastCmp;
        return strcasecmp($a['first_name'] ?? '', $b['first_name'] ?? '');
    });
}

try {
    switch ($report_type) {

        // ============================================================
        // INDIVIDUAL TRAINEE REPORT
        // ============================================================
        case 'individual':
            $individualSql = "
                SELECT 
                    t.id,
                    t.reference_id,
                    CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) as full_name,
                    t.first_name,
                    t.last_name,
                    t.middle_initial,
                    t.suffix,
                    t.address,
                    t.birthdate,
                    t.email,
                    t.contact,
                    t.department,
                    t.status,
                    t.qualification_id,
                    q.qualification_name,
                    q.qualification_code,
                    COUNT(DISTINCT et.id) as total_tasks,
                    SUM(CASE WHEN et.status = 'complied' THEN 1 ELSE 0 END) as completed_tasks
                FROM trainees t
                LEFT JOIN qualifications q ON t.qualification_id = q.id
                LEFT JOIN evaluation_tracker et ON et.trainee_id = t.id
                WHERE t.deleted_at IS NULL
                  AND (t.status IS NULL OR t.status != 'deleted')
                  $traineeScopeSql
                GROUP BY t.id, t.reference_id, t.first_name, t.last_name, t.middle_initial, t.suffix, t.address, t.birthdate, t.email, t.contact, t.department, t.status, t.qualification_id, q.qualification_name, q.qualification_code
                ORDER BY 
                    t.department,
                    t.last_name,
                    t.first_name
            ";

            $stmt = $pdo->prepare($individualSql);
            $stmt->execute();
            $traineesData = $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

            foreach ($traineesData as &$trainee) {
                $total = $trainee['total_tasks'] ?? 0;
                $completed = $trainee['completed_tasks'] ?? 0;
                $trainee['progress'] = $total > 0 ? round(($completed / $total) * 100) : 0;
                $trainee['status_label'] = $trainee['progress'] == 100 ? 'Complied' : 'Not Complied';
            }
            unset($trainee);

            $groupedTrainees = [];
            foreach ($traineesData as $trainee) {
                $deptCode = normalizeDeptCode($trainee['department'] ?? '');
                $deptDisplay = getExactDeptName($deptCode);
                $qualification = !empty($trainee['qualification_name'])
                    ? $trainee['qualification_name']
                    : 'General Qualification';

                if (!isset($groupedTrainees[$deptCode])) {
                    $groupedTrainees[$deptCode] = [
                        'display_name' => $deptDisplay,
                        'qualifications' => []
                    ];
                }
                if (!isset($groupedTrainees[$deptCode]['qualifications'][$qualification])) {
                    $groupedTrainees[$deptCode]['qualifications'][$qualification] = [];
                }
                $groupedTrainees[$deptCode]['qualifications'][$qualification][] = $trainee;
            }

            foreach ($groupedTrainees as &$dept) {
                foreach ($dept['qualifications'] as &$trainees) {
                    sortTraineesByName($trainees);
                }
                unset($trainees);
            }
            unset($dept);

            $deptOrder = ['CSS' => 1, 'HRS' => 2, 'ATS' => 3, 'OTHER' => 4];
            uksort($groupedTrainees, function($a, $b) use ($deptOrder) {
                return ($deptOrder[$a] ?? 99) - ($deptOrder[$b] ?? 99);
            });
            ?>
            <style>
                .automotive { background-color: #F59E0B; }
                .css { background-color: #10B981; }
                .hrs { background-color: #8B5CF6; }
                .report-section-title {
                    display: flex; align-items: center; gap: 12px;
                    font-size: 1.35rem; font-weight: 600; text-transform: uppercase;
                    letter-spacing: 0.8px; color: #1e1b4b; padding: 6px 0;
                    margin-bottom: 1.5rem !important; border-left: 4px solid #8b5cf6;
                    padding-left: 12px;
                }
                .report-section-title .title-icon-badge {
                    display: inline-flex; align-items: center; justify-content: center;
                    width: 38px; height: 38px; background-color: #f3e8ff;
                    color: #8b5cf6; border-radius: 10px; font-size: 1.1rem; flex-shrink: 0;
                }
                .individual-table-wrap {
                    width: 100%;
                    overflow-x: auto;
                    -webkit-overflow-scrolling: touch;
                    border-radius: 8px;
                }
                .individual-table {
                    width: 100%;
                    min-width: 1600px;
                    border-collapse: collapse;
                    font-size: 0.85rem;
                }
                .individual-table thead th {
                    padding: 10px 12px;
                    text-align: left;
                    font-weight: 700;
                    color: #1e293b;
                    background: #f8fafc;
                    border-bottom: 2px solid #e2e8f0;
                    font-size: 0.72rem;
                    text-transform: uppercase;
                    letter-spacing: 0.04em;
                    white-space: nowrap;
                }
                .individual-table tbody td {
                    padding: 10px 12px;
                    border-bottom: 1px solid #f1f5f9;
                    vertical-align: middle;
                    color: #334155;
                }
                .individual-table tbody tr:hover {
                    background: #f8fafc;
                }
                .individual-table .col-ref {
                    font-family: 'Poppins', sans-serif;
                    font-size: 0.78rem;
                    font-weight: 500;
                    color: #000;
                    white-space: nowrap;
                }
                .individual-table .col-address {
                    max-width: 220px;
                    white-space: normal;
                    word-wrap: break-word;
                    line-height: 1.35;
                }
                .individual-table .col-email {
                    max-width: 220px;
                    white-space: normal;
                    word-wrap: break-word;
                    line-height: 1.35;
                }
                .individual-table .col-date {
                    white-space: nowrap;
                }
                .individual-table .col-contact {
                    white-space: nowrap;
                }
            </style>
            <div class="content-card" style="background:white;border-radius:20px;padding:24px 28px;box-shadow:0 10px 30px rgba(0,0,0,0.08);">
                <div class="report-section-title">
                    <span class="title-icon-badge"><i class="fas fa-user"></i></span>
                    <span>INDIVIDUAL TRAINEE REPORT</span>
                </div>

                <?php if (empty($groupedTrainees)): ?>
                    <div style="text-align:center;padding:40px;color:#94a3b8;">
                        <i class="fas fa-inbox" style="display:block;font-size:2rem;margin-bottom:8px;"></i>
                        <p>No trainees found</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($groupedTrainees as $deptCode => $deptGroup): 
                        $deptDisplayName = $deptGroup['display_name'];
                        $qualifications = $deptGroup['qualifications'];
                        $deptClass = getDepartmentClass($deptCode);
                        $deptColor = getDepartmentColor($deptCode);

                        $deptTotal = 0;
                        foreach ($qualifications as $qual => $trainees) $deptTotal += count($trainees);
                    ?>
                        <div style="margin-bottom:25px;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
                            <div class="<?= $deptClass ?>" style="color:white;padding:12px 20px;display:flex;justify-content:space-between;align-items:center;">
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <i class="fas fa-building" style="font-size:1.1rem;"></i>
                                    <strong style="font-size:1rem;text-transform:uppercase;letter-spacing:0.5px;"><?= htmlspecialchars($deptDisplayName) ?></strong>
                                </div>
                                <span style="background:rgba(255,255,255,0.2);padding:2px 12px;border-radius:20px;font-size:0.8rem;">
                                    <?= $deptTotal ?> trainee(s)
                                </span>
                            </div>
                            
                            <div style="padding:16px 20px;">
                                <?php ksort($qualifications); foreach ($qualifications as $qualification => $trainees): ?>
                                    <div style="margin-bottom:16px;border-left:4px solid <?= $deptColor ?>;padding-left:16px;">
                                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                                            <div style="display:flex;align-items:center;gap:8px;">
                                                <i class="fas fa-certificate" style="color:<?= $deptColor ?>;font-size:1rem;"></i>
                                                <strong style="font-size:0.95rem;color:#1e293b;"><?= htmlspecialchars($qualification) ?></strong>
                                            </div>
                                            <span style="font-size:0.8rem;color:#64748b;"><?= count($trainees) ?> trainee(s)</span>
                                        </div>
                                        
                                        <div class="individual-table-wrap">
                                            <table class="individual-table">
                                                <thead>
                                                    <tr>
                                                        <th>Reference ID</th>
                                                        <th>Trainee</th>
                                                        <th>Department</th>
                                                        <th>Address</th>
                                                        <th>Birthdate</th>
                                                        <th>Email</th>
                                                        <th>Contact</th>
                                                        <th>Progress</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($trainees as $trainee): 
                                                        $progress = $trainee['progress'];
                                                        $status = $trainee['status_label'];
                                                        $donutColor = $progress >= 80 ? '#10b981' : ($progress >= 50 ? '#f59e0b' : '#ef4444');

                                                        $displayName = $trainee['full_name'] ?? '';
                                                        if (trim($displayName) === '') {
                                                            $displayName = trim(($trainee['first_name'] ?? '') . ' ' . ($trainee['last_name'] ?? ''));
                                                        }
                                                        if (!empty($trainee['suffix'])) {
                                                            $displayName .= ' ' . $trainee['suffix'];
                                                        }

                                                        $exactDeptName = getExactDeptName(normalizeDeptCode($trainee['department'] ?? ''));
                                                    ?>
                                                        <tr>
                                                            <td class="col-ref">
                                                                <?= htmlspecialchars($trainee['reference_id'] ?? 'N/A') ?>
                                                            </td>
                                                            <td>
                                                                <strong><i class="fas fa-user-circle" style="color:<?= $deptColor ?>;margin-right:8px;"></i><?= htmlspecialchars($displayName ?: 'Unknown') ?></strong>
                                                            </td>
                                                            <td><?= htmlspecialchars($exactDeptName) ?></td>
                                                            <td class="col-address">
                                                                <?= htmlspecialchars($trainee['address'] ?? 'N/A') ?>
                                                            </td>
                                                            <td class="col-date">
                                                                <?= htmlspecialchars(formatBirthdate($trainee['birthdate'] ?? '')) ?>
                                                            </td>
                                                            <td class="col-email">
                                                                <?= htmlspecialchars($trainee['email'] ?? 'N/A') ?>
                                                            </td>
                                                            <td class="col-contact">
                                                                <?= htmlspecialchars($trainee['contact'] ?? 'N/A') ?>
                                                            </td>
                                                            <td>
                                                                <div style="display:flex;align-items:center;gap:12px;">
                                                                    <div style="width:52px;height:52px;border-radius:50%;background:conic-gradient(<?= $donutColor ?> <?= $progress ?>%, #e2e8f0 <?= $progress ?>% 100%);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                                                        <div style="width:38px;height:38px;background:white;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.8rem;font-weight:700;color:<?= $status === 'Complied' ? '#10b981' : '#ef4444' ?>;">
                                                                            <?= $progress ?>%
                                                                        </div>
                                                                    </div>
                                                                    <span style="font-size:0.85rem;color:#64748b;white-space:nowrap;"><?= $trainee['completed_tasks'] ?? 0 ?>/<?= $trainee['total_tasks'] ?? 0 ?> tasks</span>
                                                                </div>
                                                            </td>
                                                            <td>
                                                                <?php if ($status === 'Complied'): ?>
                                                                    <span style="display:inline-flex;align-items:center;gap:6px;padding:4px 16px;border-radius:20px;font-size:0.8rem;font-weight:600;background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;white-space:nowrap;">
                                                                        <i class="fas fa-check-circle" style="color:#10b981;"></i> Complied
                                                                    </span>
                                                                <?php else: ?>
                                                                    <span style="display:inline-flex;align-items:center;gap:6px;padding:4px 16px;border-radius:20px;font-size:0.8rem;font-weight:600;background:#fee2e2;color:#991b1b;border:1px solid #fecaca;white-space:nowrap;">
                                                                        <i class="fas fa-exclamation-circle" style="color:#ef4444;"></i> Not Complied
                                                                    </span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
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
            
            <script>
                (function() {
                    const loadingEl = document.getElementById('reportLoadingIndicator');
                    if (loadingEl) loadingEl.style.display = 'none';
                })();
            </script>
            <?php
            break;

        // ============================================================
        // SUMMARY COMPLIANCE REPORT
        // ============================================================
        case 'summary':
            $summarySql = "
                SELECT 
                    q.qualification_code,
                    q.qualification_name,
                    COALESCE(t.department, c.course_code) AS department,
                    COALESCE(c.course_code, t.department) AS department_code,
                    COUNT(DISTINCT CASE 
                        WHEN COALESCE(pt_counts.total_tasks, 0) > 0 
                        THEN t.id 
                    END) AS total_trainees,
                    SUM(CASE 
                        WHEN COALESCE(pt_counts.total_tasks, 0) > 0
                         AND COALESCE(et_counts.complied_tasks, 0) >= COALESCE(pt_counts.total_tasks, 0)
                        THEN 1 ELSE 0 
                    END) AS complied_trainees,
                    SUM(CASE 
                        WHEN COALESCE(pt_counts.total_tasks, 0) > 0
                         AND COALESCE(et_counts.complied_tasks, 0) < COALESCE(pt_counts.total_tasks, 0)
                        THEN 1 ELSE 0 
                    END) AS not_complied_trainees
                FROM qualifications q
                LEFT JOIN courses c ON q.course_id = c.id
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
                WHERE 1=1 $qualificationScopeSql
                GROUP BY q.id, q.qualification_code, q.qualification_name, department, department_code
                ORDER BY department_code, q.qualification_code
            ";
            $stmt = $pdo->prepare($summarySql);
            $stmt->execute();
            $summaryData = $stmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

            $allQuals = []; $allDepartments = [];

            foreach ($summaryData as &$row) {
                $deptSource = !empty($row['department_code']) 
                    ? $row['department_code'] 
                    : ($row['department'] ?? '');
                
                $row['dept_code'] = normalizeDeptCode($deptSource);
                $row['dept_name'] = getExactDeptName($row['dept_code']);
                
                if (!in_array($row['qualification_name'], $allQuals)) $allQuals[] = $row['qualification_name'];
                if (!in_array($row['dept_name'], $allDepartments)) $allDepartments[] = $row['dept_name'];
            }
            unset($row);

            sort($allQuals); sort($allDepartments);
            ?>
            
            <style>
                .report-section-title {
                    display: flex; align-items: center; gap: 12px;
                    font-size: 1.35rem; font-weight: 600; text-transform: uppercase;
                    letter-spacing: 0.8px; color: #1e1b4b; padding: 6px 0;
                    margin-bottom: 1.5rem !important; border-left: 4px solid #8b5cf6;
                    padding-left: 12px;
                }
                .report-section-title .title-icon-badge {
                    display: inline-flex; align-items: center; justify-content: center;
                    width: 38px; height: 38px; background-color: #f3e8ff;
                    color: #8b5cf6; border-radius: 10px; font-size: 1.1rem; flex-shrink: 0;
                }

                .filter-bar {
                    display: flex;
                    flex-wrap: nowrap;
                    align-items: center;
                    gap: 14px;
                    padding: 14px 20px;
                    background: #f8fafc;
                    border-radius: 10px;
                    margin-bottom: 20px;
                    border: 1px solid #e8edf5;
                    overflow-x: auto;
                }

                .filter-bar .filter-item {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    font-size: 0.85rem;
                    color: #1E293B;
                    flex-shrink: 0;
                }

                .filter-bar .filter-item i {
                    color: #8B5CF6;
                    font-size: 0.9rem;
                    flex-shrink: 0;
                }

                .filter-bar .filter-item select,
                .filter-bar .filter-item input {
                    padding: 8px 14px;
                    border: 1px solid #e2e8f0;
                    border-radius: 8px;
                    font-size: 0.85rem;
                    background: white;
                    color: #1E293B;
                    min-width: 170px;
                }

                .filter-bar .filter-item input {
                    min-width: 220px;
                }

                .filter-bar .filter-item select:focus,
                .filter-bar .filter-item input:focus {
                    outline: none;
                    border-color: #8B5CF6;
                    box-shadow: 0 0 0 3px rgba(139,92,246,0.1);
                }

                .filter-bar .filter-separator {
                    width: 1px;
                    height: 30px;
                    background: #e2e8f0;
                    flex-shrink: 0;
                }

                .filter-bar .filter-actions {
                    display: flex;
                    gap: 8px;
                    align-items: center;
                    margin-left: auto;
                    flex-shrink: 0;
                }

                .filter-bar .filter-actions .btn-filter {
                    padding: 8px 20px;
                    border-radius: 8px;
                    font-weight: 600;
                    font-size: 0.82rem;
                    border: none;
                    cursor: pointer;
                    background: #8B5CF6;
                    color: white;
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    white-space: nowrap;
                }
                .filter-bar .filter-actions .btn-filter:hover { background: #7C3AED; }

                .filter-bar .filter-actions .btn-reset {
                    padding: 8px 20px;
                    border-radius: 8px;
                    font-weight: 600;
                    font-size: 0.82rem;
                    cursor: pointer;
                    background: #ffffff;
                    color: #475569;
                    border: 1px solid #e2e8f0;
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    white-space: nowrap;
                }
                .filter-bar .filter-actions .btn-reset:hover { background: #f1f5f9; }

                .sr-table-container { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; }
                .sr-table-wrapper { overflow-x: auto; }
                .sr-table-wrapper table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
                .sr-table-wrapper table thead { background: #f8f9fa; }
                .sr-table-wrapper table thead th { text-align: left; padding: 10px 14px; font-weight: 700; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: #1e293b; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
                .sr-table-wrapper table thead th.text-center { text-align: center; }
                .sr-table-wrapper table tbody td { padding: 8px 14px; border-bottom: 1px solid #e9ecef; color: #1e293b; vertical-align: middle; }
                .sr-table-wrapper table tbody tr:hover { background: #f8f9fa; }
                .sr-table-wrapper table tbody td.text-center { text-align: center; }
                .sr-rate-bar { display: flex; align-items: center; gap: 8px; }
                .sr-rate-bar .bar-track { flex: 1; height: 6px; background: #e2e8f0; border-radius: 4px; overflow: hidden; min-width: 60px; }
                .sr-rate-bar .bar-fill { height: 100%; border-radius: 4px; }
                .sr-rate-bar .rate-text { font-weight: 600; font-size: 0.8rem; min-width: 40px; text-align: right; }
                .sr-empty-state { text-align: center; padding: 40px 20px; color: #94a3b8; }
                .sr-empty-state i { font-size: 2.5rem; display: block; margin-bottom: 12px; opacity: 0.5; }
                .sr-empty-state h3 { font-size: 1.1rem; color: #475569; margin-bottom: 4px; }
                .sr-empty-state p { font-size: 0.875rem; }
                .sr-no-results .sr-empty-state { padding: 30px 20px; }
                .sr-no-results .sr-empty-state i { font-size: 2rem; }
                @media (max-width: 768px) { .sr-table-wrapper table { font-size: 0.75rem; } .sr-table-wrapper table thead th, .sr-table-wrapper table tbody td { padding: 6px 10px; } }
            </style>

            <div class="content-card" style="background:white;border-radius:20px;padding:24px 28px;box-shadow:0 10px 30px rgba(0,0,0,0.08);">
                <div class="report-section-title">
                    <span class="title-icon-badge"><i class="fas fa-file-alt"></i></span>
                    <span>SUMMARY COMPLIANCE REPORT</span>
                </div>

                <div class="filter-bar" id="srFiltersBar">
                    <div class="filter-item">
                        <i class="fas fa-building"></i>
                        <select id="srDepartmentFilter">
                            <option value="">All Departments</option>
                            <?php foreach ($allDepartments as $dept): ?>
                                <option value="<?= htmlspecialchars($dept) ?>"><?= htmlspecialchars($dept) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-item">
                        <i class="fas fa-certificate"></i>
                        <select id="srQualFilter">
                            <option value="">All Qualifications</option>
                            <?php foreach ($allQuals as $qual): ?>
                                <option value="<?= htmlspecialchars($qual) ?>"><?= htmlspecialchars($qual) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-separator"></div>

                    <div class="filter-item">
                        <i class="fas fa-search"></i>
                        <input type="text" id="srSearchInput" placeholder="Search..." />
                    </div>

                    <div class="filter-actions">
                        <button class="btn-filter" onclick="applySrFilters()">
                            <i class="fas fa-filter"></i> Apply
                        </button>
                        <button class="btn-reset" onclick="resetSrFilters()">
                            <i class="fas fa-undo-alt"></i> Reset
                        </button>
                    </div>
                </div>

                <div class="sr-table-container">
                    <div class="sr-table-wrapper">
                        <table id="srSummaryTable">
                            <thead>
                                <tr>
                                    <th>Department</th>
                                    <th>Qualification</th>
                                    <th class="text-center">Total Trainees</th>
                                    <th class="text-center">Complied</th>
                                    <th class="text-center">Not Complied</th>
                                    <th class="text-center">Rate</th>
                                </tr>
                            </thead>
                            <tbody id="srTableBody">
                                <?php 
                                $groupedByDept = [];
                                foreach ($summaryData as $row) {
                                    $groupKey = $row['dept_code'] . '|' . $row['qualification_name'];

                                    if (!isset($groupedByDept[$groupKey])) {
                                        $groupedByDept[$groupKey] = [
                                            'dept_code' => $row['dept_code'],
                                            'dept_name' => $row['dept_name'],
                                            'qualification' => $row['qualification_name'],
                                            'total_trainees' => 0,
                                            'complied' => 0,
                                            'not_complied' => 0
                                        ];
                                    }
                                    $groupedByDept[$groupKey]['total_trainees'] += (int)$row['total_trainees'];
                                    $groupedByDept[$groupKey]['complied'] += (int)$row['complied_trainees'];
                                    $groupedByDept[$groupKey]['not_complied'] += (int)$row['not_complied_trainees'];
                                }

                                if (empty($groupedByDept)): ?>
                                    <tr>
                                        <td colspan="6">
                                            <div class="sr-empty-state">
                                                <i class="fas fa-inbox"></i>
                                                <h3>No data found</h3>
                                                <p>No department records available.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: 
                                    $deptOrder = ['CSS' => 1, 'HRS' => 2, 'ATS' => 3, 'OTHER' => 4];
                                    uasort($groupedByDept, function($a, $b) use ($deptOrder) {
                                        $cmp = ($deptOrder[$a['dept_code']] ?? 99) - ($deptOrder[$b['dept_code']] ?? 99);
                                        if ($cmp !== 0) return $cmp;
                                        return strcmp($a['qualification'], $b['qualification']);
                                    });

                                    foreach ($groupedByDept as $group): 
                                        $totalTrainees = (int)$group['total_trainees'];
                                        $complied = (int)$group['complied'];
                                        $notComplied = (int)$group['not_complied'];
                                        $rate = $totalTrainees > 0 ? round(($complied / $totalTrainees) * 100) : 0;
                                        $rateColor = ($rate == 100 && $totalTrainees > 0) ? '#10b981' : '#dc3545';
                                    ?>
                                        <tr data-department="<?= htmlspecialchars($group['dept_name']) ?>" data-qualification="<?= htmlspecialchars($group['qualification']) ?>">
                                            <td><strong><?= htmlspecialchars($group['dept_name']) ?></strong></td>
                                            <td><?= htmlspecialchars($group['qualification']) ?></td>
                                            <td class="text-center"><strong><?= $totalTrainees ?></strong></td>
                                            <td class="text-center" style="color:#10b981;font-weight:600;"><?= $complied ?></td>
                                            <td class="text-center" style="color:#dc3545;font-weight:600;"><?= $notComplied ?></td>
                                            <td>
                                                <div class="sr-rate-bar">
                                                    <div class="bar-track">
                                                        <div class="bar-fill" style="width:<?= $rate ?>%;background:<?= $rateColor ?>;"></div>
                                                    </div>
                                                    <span class="rate-text" style="color:<?= $rateColor ?>;font-weight:700;"><?= $rate ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; 
                                endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <script>
                (function() {
                    const srTableBody   = document.getElementById('srTableBody');
                    const srSearchInput = document.getElementById('srSearchInput');
                    const srDeptFilter  = document.getElementById('srDepartmentFilter');
                    const srQualFilter  = document.getElementById('srQualFilter');
                    const srApplyBtn    = document.querySelector('#srFiltersBar .btn-filter');
                    const srResetBtn    = document.querySelector('#srFiltersBar .btn-reset');

                    if (!srTableBody) return;

                    function applySrFilters() {
                        if (!srTableBody) return;

                        const searchTerm = (srSearchInput?.value || '').toLowerCase().trim();
                        const deptFilter = srDeptFilter?.value || '';
                        const qualFilter = srQualFilter?.value || '';

                        let visibleCount = 0;

                        srTableBody.querySelectorAll('tr').forEach(row => {
                            if (row.querySelector('.sr-empty-state')) {
                                row.style.display = '';
                                return;
                            }
                            const dept = row.getAttribute('data-department') || '';
                            const qual = row.getAttribute('data-qualification') || '';
                            let show = true;

                            if (deptFilter && dept !== deptFilter) show = false;
                            if (qualFilter && qual !== qualFilter) show = false;
                            if (searchTerm) {
                                const matches = dept.toLowerCase().includes(searchTerm)
                                             || qual.toLowerCase().includes(searchTerm);
                                if (!matches) show = false;
                            }
                            row.style.display = show ? '' : 'none';
                            if (show) visibleCount++;
                        });

                        let noResRow = srTableBody.querySelector('tr.sr-no-results');
                        if (visibleCount === 0 && !srTableBody.querySelector('.sr-empty-state')) {
                            if (!noResRow) {
                                noResRow = document.createElement('tr');
                                noResRow.className = 'sr-no-results';
                                noResRow.innerHTML = `
                                    <td colspan="6">
                                        <div class="sr-empty-state" style="padding:30px 20px;">
                                            <i class="fas fa-search-minus"></i>
                                            <h3>No matching records</h3>
                                            <p>Try adjusting your filters.</p>
                                        </div>
                                    </td>`;
                                srTableBody.appendChild(noResRow);
                            }
                            noResRow.style.display = '';
                        } else if (noResRow) {
                            noResRow.style.display = 'none';
                        }
                    }

                    function resetSrFilters() {
                        if (srSearchInput) srSearchInput.value = '';
                        if (srDeptFilter)  srDeptFilter.value = '';
                        if (srQualFilter)  srQualFilter.value = '';
                        applySrFilters();
                    }

                    window.applySrFilters = applySrFilters;
                    window.resetSrFilters = resetSrFilters;

                    if (srApplyBtn) srApplyBtn.addEventListener('click', applySrFilters);
                    if (srResetBtn) srResetBtn.addEventListener('click', resetSrFilters);

                    if (srSearchInput) {
                        srSearchInput.addEventListener('keyup', function(e) {
                            if (e.key === 'Enter') applySrFilters();
                        });
                        srSearchInput.addEventListener('input', applySrFilters);
                    }

                    if (srDeptFilter) srDeptFilter.addEventListener('change', applySrFilters);
                    if (srQualFilter) srQualFilter.addEventListener('change', applySrFilters);

                    applySrFilters();

                    const loadingEl = document.getElementById('reportLoadingIndicator');
                    if (loadingEl) loadingEl.style.display = 'none';
                })();
            </script>
            <?php
            break;

        // ============================================================
        // NON-COMPLIANCE LIST — TRANSPOSED MATRIX
        // ============================================================
        case 'non_compliance_detailed':
        case 'non-compliant':
        case 'non_compliant':

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
            $coreCompetencyStmt = $pdo->prepare($coreCompetencyQuery);
            $coreCompetencyStmt->execute();
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
                ORDER BY 
                    t.department,
                    t.last_name, 
                    t.first_name
            ";
            $traineesStmt = $pdo->prepare($traineesQuery);
            $traineesStmt->execute();
            $allTrainees = $traineesStmt->fetchAll(PDO::FETCH_ASSOC) ?? [];

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

            $matrixData = [];
            $nonCompliantTraineeIds = [];

            $deptDisplay = [
                'HRS' => ['name' => 'Hotel and Restaurant Services', 'color' => '#8B5CF6', 'icon' => 'fa-utensils'],
                'CSS' => ['name' => 'Computer Systems Servicing', 'color' => '#10B981', 'icon' => 'fa-laptop-code'],
                'ATS' => ['name' => 'Automotive Servicing', 'color' => '#F59E0B', 'icon' => 'fa-car'],
                'OTHER' => ['name' => 'Other Departments', 'color' => '#6B7280', 'icon' => 'fa-building']
            ];

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

            $deptOrder = ['CSS' => 1, 'HRS' => 2, 'ATS' => 3, 'OTHER' => 4];
            uksort($matrixData, function($a, $b) use ($deptOrder) {
                return ($deptOrder[$a] ?? 99) - ($deptOrder[$b] ?? 99);
            });

            ?>
            <style>
                .report-section-title {
                    display: flex; align-items: center; gap: 12px;
                    font-size: 1.35rem; font-weight: 600; text-transform: uppercase;
                    letter-spacing: 0.8px; color: #1e1b4b; padding: 6px 0;
                    margin-bottom: 1.5rem !important; border-left: 4px solid #ef4444;
                    padding-left: 12px;
                }
                .report-section-title .title-icon-badge {
                    display: inline-flex; align-items: center; justify-content: center;
                    width: 38px; height: 38px; background-color: #fee2e2;
                    color: #ef4444; border-radius: 10px; font-size: 1.1rem; flex-shrink: 0;
                }
                .empty-state { text-align: center; padding: 50px 20px; color: #94a3b8; }
                .empty-state i { font-size: 3.5rem; color: #10b981; margin-bottom: 16px; display: block; }
                .empty-state h3 { font-size: 1.3rem; color: #1e293b; margin-bottom: 8px; }
                .empty-state p { font-size: 1rem; }
                .department-section { margin-bottom: 28px; border: 1px solid #e8edf5; border-radius: 12px; overflow: hidden; }
                .department-section:last-child { margin-bottom: 0; }
                .department-header { padding: 14px 20px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
                .department-header .dept-icon { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1rem; flex-shrink: 0; }
                .department-header .dept-name { font-weight: 700; font-size: 1rem; color: #fff; text-transform: uppercase; letter-spacing: 0.3px; }
                .department-header .dept-count { margin-left: auto; font-size: 0.8rem; color: #fff; background: rgba(255,255,255,0.25); padding: 2px 14px; border-radius: 20px; font-weight: 500; }
                .qualification-section { padding: 16px 20px; border-bottom: 1px solid #f1f5f9; }
                .qualification-section:last-child { border-bottom: none; }
                .qualification-header { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
                .qualification-header .qual-icon { color: #8B5CF6; font-size: 0.9rem; }
                .qualification-header .qual-name { font-weight: 600; font-size: 0.95rem; color: #1E293B; }
                .qualification-header .qual-code { font-size: 0.75rem; color: #94A3B8; font-weight: 400; background: #f1f5f9; padding: 1px 10px; border-radius: 12px; }
                .qualification-header .qual-count { margin-left: auto; font-size: 0.75rem; color: #64748b; background: #f1f5f9; padding: 2px 12px; border-radius: 20px; font-weight: 500; }

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
                    font-size: 1.05rem;
                    font-weight: 700;
                    width: 90px;
                }
                .matrix-table td.cell-compliant { background: #d1fae5; color: #059669; }
                .matrix-table td.cell-not-compliant { background: #fee2e2; color: #dc2626; }
                .competency-row td { background: #cbd5e1 !important; color: #0f172a; font-weight: 700; font-size: 0.78rem; letter-spacing: 0.02em; padding: 8px 10px; text-align: left; }
                @media (max-width: 768px) {
                    .matrix-table { font-size: 0.7rem; }
                    .matrix-table th, .matrix-table td { padding: 5px 6px; }
                    .matrix-table th.col-task, .matrix-table td.col-task { min-width: 180px; max-width: 220px; }
                    .matrix-table th.col-trainee, .matrix-table td.col-trainee { min-width: 70px; max-width: 90px; }
                }
            </style>

            <div class="content-card" style="background:white;border-radius:20px;padding:24px 28px;box-shadow:0 10px 30px rgba(0,0,0,0.08);">
                <div class="report-section-title">
                    <span class="title-icon-badge"><i class="fas fa-list-ul"></i></span>
                    <span>NON-COMPLIANCE LIST</span>
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
                                $trainees     = $group['trainees'];
                                $competencies = $coreCompetenciesByQual[$qualId] ?? [];
                                $qualName     = $meta['qualification_name'];
                                $qualCode     = $meta['qualification_code'];
                                $traineeCount = count($trainees);

                                uksort($trainees, function($a, $b) use ($trainees) {
                                    return strcasecmp($trainees[$a] ?? '', $trainees[$b] ?? '');
                                });
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

                                    <div style="overflow-x:auto;">
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
                                                                    $cls  = $isComplied ? 'cell-compliant' : 'cell-not-compliant';
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

            <script>
                (function() {
                    const loadingEl = document.getElementById('reportLoadingIndicator');
                    if (loadingEl) loadingEl.style.display = 'none';
                })();
            </script>
            <?php
            break;

        // ============================================================
        // TESDA INSPECTION REPORT
        // ============================================================
        case 'inspection':
        case 'tesda_inspection':

            $filterSql = "";
            $params = [];

            if ($department) {
                $filterSql .= " AND t.department = :department";
                $params[':department'] = $department;
            }
            if ($qualification_id > 0) {
                $filterSql .= " AND q.id = :qualification_id";
                $params[':qualification_id'] = $qualification_id;
            }
            if ($search) {
                $filterSql .= " AND (CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) LIKE :search OR q.qualification_name LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }

            // ============================================================
            // Summary by Qualification (FIXED — per-trainee compliance)
            // ============================================================
            $summarySql = "
                SELECT 
                    q.qualification_code,
                    q.qualification_name,
                    COALESCE(t.department, c.course_code) AS department,
                    COALESCE(c.course_code, t.department) AS department_code,
                    COUNT(DISTINCT CASE 
                        WHEN COALESCE(pt_counts.total_tasks, 0) > 0 
                        THEN t.id 
                    END) AS total_trainees,
                    SUM(CASE 
                        WHEN COALESCE(pt_counts.total_tasks, 0) > 0
                         AND COALESCE(et_counts.complied_tasks, 0) >= COALESCE(pt_counts.total_tasks, 0)
                        THEN 1 ELSE 0 
                    END) AS complied_trainees,
                    SUM(CASE 
                        WHEN COALESCE(pt_counts.total_tasks, 0) > 0
                         AND COALESCE(et_counts.complied_tasks, 0) < COALESCE(pt_counts.total_tasks, 0)
                        THEN 1 ELSE 0 
                    END) AS not_complied_trainees
                FROM qualifications q
                LEFT JOIN courses c ON q.course_id = c.id
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
                WHERE 1=1 $qualificationScopeSql
                GROUP BY q.id, q.qualification_code, q.qualification_name, department, department_code
                ORDER BY department_code, q.qualification_code
            ";
            $stmt = $pdo->prepare($summarySql);
            foreach ($params as $key => $val) $stmt->bindValue($key, $val);
            $stmt->execute();
            $qualificationSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ============================================================
            // Performance Task Details
            // ============================================================
            $taskDetailsSql = "
                SELECT 
                    t.id AS trainee_id,
                    CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) AS trainee_name,
                    q.qualification_name, q.qualification_code,
                    COUNT(DISTINCT pt.id) AS total_tasks,
                    SUM(CASE WHEN et.status = 'complied' THEN 1 ELSE 0 END) AS complied_tasks,
                    SUM(CASE WHEN et.status = 'not_complied' THEN 1 ELSE 0 END) AS not_complied_tasks
                FROM trainees t
                JOIN qualifications q ON t.qualification_id = q.id
                LEFT JOIN competency_standards cs ON cs.qualification_id = q.id
                LEFT JOIN performance_tasks pt ON pt.competency_id = cs.id
                LEFT JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = t.id
                WHERE t.deleted_at IS NULL $filterSql $traineeScopeSql
                GROUP BY t.id, t.first_name, t.last_name, q.qualification_name, q.qualification_code
                ORDER BY 
                    t.last_name,
                    t.first_name,
                    q.qualification_name
            ";
            $stmt = $pdo->prepare($taskDetailsSql);
            foreach ($params as $key => $val) $stmt->bindValue($key, $val);
            $stmt->execute();
            $taskDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // ============================================================
            // Deficiency Summary
            // ============================================================
            $deficiencySql = "
                SELECT 
                    t.id AS trainee_id,
                    CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) AS trainee_name,
                    q.qualification_name, q.qualification_code,
                    COUNT(DISTINCT pt.id) AS task_count,
                    CASE 
                        WHEN COUNT(DISTINCT nl.id) > 0 THEN 'Notified'
                        ELSE 'Not Notified'
                    END AS notification_status
                FROM trainees t
                JOIN qualifications q ON t.qualification_id = q.id
                JOIN competency_standards cs ON cs.qualification_id = q.id
                JOIN performance_tasks pt ON pt.competency_id = cs.id
                LEFT JOIN evaluation_tracker et ON et.task_id = pt.id AND et.trainee_id = t.id
                LEFT JOIN notified_logs nl ON nl.trainee_id = t.id AND nl.task_id = pt.id
                WHERE t.deleted_at IS NULL $filterSql $traineeScopeSql
                  AND (et.status IS NULL OR et.status != 'complied')
                GROUP BY t.id, t.first_name, t.last_name, q.qualification_name, q.qualification_code
                ORDER BY 
                    t.last_name,
                    t.first_name,
                    q.qualification_name
            ";
            $stmt = $pdo->prepare($deficiencySql);
            foreach ($params as $key => $val) $stmt->bindValue($key, $val);
            $stmt->execute();
            $deficiencyList = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $notNotifiedList = [];
            $notifiedList = [];
            foreach ($deficiencyList as $row) {
                if (($row['notification_status'] ?? '') === 'Notified') {
                    $notifiedList[] = $row;
                } else {
                    $notNotifiedList[] = $row;
                }
            }
            ?>
            
            <style>
                .report-section-title {
                    display: flex; align-items: center; gap: 12px;
                    font-size: 1.35rem; font-weight: 600; text-transform: uppercase;
                    letter-spacing: 0.8px; color: #1e1b4b; padding: 6px 0;
                    margin-bottom: 1.2rem !important; border-left: 4px solid #8b5cf6;
                    padding-left: 12px;
                }
                .report-section-title .title-icon-badge {
                    display: inline-flex; align-items: center; justify-content: center;
                    width: 38px; height: 38px; background-color: #f3e8ff;
                    color: #8b5cf6; border-radius: 10px; font-size: 1.1rem; flex-shrink: 0;
                }
                .inspection-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-bottom: 16px; }
                .inspection-table thead { background: #f1f5f9; }
                .inspection-table th { padding: 10px 14px; text-align: left; font-weight: 700; font-size: 0.7rem; text-transform: uppercase; color: #1e293b; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
                .inspection-table td { padding: 8px 14px; border-bottom: 1px solid #f1f5f9; color: #1e293b; vertical-align: middle; }
                .inspection-table tbody tr:hover { background: #f8fafc; }
                .inspection-table .text-center { text-align: center; }
                .inspection-table .text-green { color: #10b981; font-weight: 600; }
                .inspection-table .text-red { color: #ef4444; font-weight: 600; }
                .inspection-table .text-yellow { color: #f59e0b; font-weight: 600; }
                .deficiency-empty { text-align: center; padding: 30px 20px; color: #10b981; font-weight: 600; }
                .deficiency-empty i { font-size: 2rem; display: block; margin-bottom: 8px; }
                .status-badge { display: inline-flex; align-items: center; justify-content: center; padding: 3px 14px; border-radius: 20px; font-weight: 600; font-size: 0.75rem; }
                .status-badge.complied { background: #d1fae5; color: #065f46; }
                .status-badge.not-complied { background: #fee2e2; color: #991b1b; }
                @media (max-width: 768px) { .inspection-table { font-size: 0.75rem; } .inspection-table th, .inspection-table td { padding: 6px 10px; } }
            </style>

            <div class="content-card" style="background:white;border-radius:20px;padding:24px 28px;box-shadow:0 10px 30px rgba(0,0,0,0.08);">
                <div class="report-section-title">
                    <span class="title-icon-badge"><i class="fas fa-clipboard-check"></i></span>
                    <span>TESDA INSPECTION REPORT</span>
                </div>

                <div style="display:flex;justify-content:flex-end;margin-bottom:16px;">
                    <span style="font-size:0.85rem;color:#64748b;"><i class="far fa-calendar-alt"></i> Generated: <?= date('F d, Y h:i A') ?></span>
                </div>

                <h6 style="font-weight:700;margin-bottom:12px;color:#1e293b;font-size:1rem;">
                    <i class="fas fa-layer-group" style="color:#8b5cf6;margin-right:8px;"></i> Compliance Summary by Qualification
                </h6>
                <div style="overflow-x:auto;margin-bottom:30px;">
                    <table class="inspection-table">
                        <thead>
                            <tr>
                                <th>Qualification</th>
                                <th class="text-center">Trainees</th>
                                <th class="text-center">Complied</th>
                                <th class="text-center">Not Complied</th>
                                <th class="text-center">Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($qualificationSummary)): ?>
                                <tr><td colspan="5" style="text-align:center;padding:20px;color:#94a3b8;">No qualification data available</td></tr>
                            <?php else: ?>
                                <?php foreach ($qualificationSummary as $qual): 
                                    $qualName = !empty($qual['qualification_name']) ? $qual['qualification_name'] : 'Unknown';
                                    $totalTrainees = (int)($qual['total_trainees'] ?? 0);
                                    $compliedTrainees = (int)($qual['complied_trainees'] ?? 0);
                                    $notCompliedTrainees = (int)($qual['not_complied_trainees'] ?? 0);
                                    $rate = $totalTrainees > 0 ? round(($compliedTrainees / $totalTrainees) * 100) : 0;
                                    $rateClass = $rate >= 80 ? 'text-green' : ($rate >= 50 ? 'text-yellow' : 'text-red');
                                ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($qualName) ?></strong></td>
                                        <td class="text-center"><?= $totalTrainees ?></td>
                                        <td class="text-center text-green"><?= $compliedTrainees ?></td>
                                        <td class="text-center text-red"><?= $notCompliedTrainees ?></td>
                                        <td class="text-center <?= $rateClass ?>"><strong><?= $rate ?>%</strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <h6 style="font-weight:700;margin-bottom:12px;color:#1e293b;font-size:1rem;">
                    <i class="fas fa-tasks" style="color:#8b5cf6;margin-right:8px;"></i> Performance Task Details
                </h6>
                <div style="overflow-x:auto;margin-bottom:30px;">
                    <table class="inspection-table">
                        <thead>
                            <tr>
                                <th>Trainee</th>
                                <th>Qualification</th>
                                <th class="text-center">Total Tasks</th>
                                <th class="text-center">Complied</th>
                                <th class="text-center">Not Complied</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($taskDetails)): ?>
                                <tr><td colspan="6" style="text-align:center;padding:20px;color:#94a3b8;">No task records available</td></tr>
                            <?php else: ?>
                                <?php foreach ($taskDetails as $task): 
                                    $qualName = !empty($task['qualification_name']) ? $task['qualification_name'] : 'Unknown';
                                    $total = (int)($task['total_tasks'] ?? 0);
                                    $complied = (int)($task['complied_tasks'] ?? 0);
                                    $notComplied = (int)($task['not_complied_tasks'] ?? 0);
                                    $status = ($total > 0 && $complied === $total) ? 'Complied' : 'Not Complied';
                                    $statusClass = $status === 'Complied' ? 'complied' : 'not-complied';
                                ?>
                                    <tr>
                                        <td><?= htmlspecialchars($task['trainee_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($qualName) ?></td>
                                        <td class="text-center"><?= $total ?></td>
                                        <td class="text-center text-green"><?= $complied ?></td>
                                        <td class="text-center text-red"><?= $notComplied ?></td>
                                        <td class="text-center">
                                            <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($status) ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <h6 style="font-weight:700;margin-bottom:12px;color:#1e293b;font-size:1rem;">
                    <i class="fas fa-exclamation-triangle" style="color:#ef4444;margin-right:8px;"></i> 
                    Non-Complied / Deficiency Summary — NOT NOTIFIED
                </h6>
                <div style="overflow-x:auto;margin-bottom:30px;">
                    <table class="inspection-table">
                        <thead>
                            <tr>
                                <th style="width:30%;">Trainee</th>
                                <th style="width:35%;">Qualification</th>
                                <th class="text-center" style="width:15%;">No. of Tasks</th>
                                <th class="text-center" style="width:20%;">Notification Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($notNotifiedList)): ?>
                                <tr>
                                    <td colspan="4">
                                        <div class="deficiency-empty">
                                            <i class="fas fa-check-circle"></i>
                                            ✅ All deficient tasks have been notified.
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($notNotifiedList as $def): 
                                    $qualName = !empty($def['qualification_name']) ? $def['qualification_name'] : 'Unknown';
                                    $taskCount = (int)($def['task_count'] ?? 0);
                                ?>
                                    <tr>
                                        <td><?= htmlspecialchars($def['trainee_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($qualName) ?></td>
                                        <td class="text-center"><strong><?= $taskCount ?></strong></td>
                                        <td class="text-center text-red">Not Notified</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <h6 style="font-weight:700;margin-bottom:12px;color:#1e293b;font-size:1rem;">
                    <i class="fas fa-check-circle" style="color:#10b981;margin-right:8px;"></i> 
                    Non-Complied / Deficiency Summary — NOTIFIED
                </h6>
                <div style="overflow-x:auto;">
                    <table class="inspection-table">
                        <thead>
                            <tr>
                                <th style="width:30%;">Trainee</th>
                                <th style="width:35%;">Qualification</th>
                                <th class="text-center" style="width:15%;">No. of Tasks</th>
                                <th class="text-center" style="width:20%;">Notification Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($notifiedList)): ?>
                                <tr>
                                    <td colspan="4">
                                        <div style="text-align:center;padding:30px 20px;color:#94a3b8;font-weight:600;">
                                            <i class="fas fa-inbox" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
                                            No trainees have been notified yet.
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($notifiedList as $def): 
                                    $qualName = !empty($def['qualification_name']) ? $def['qualification_name'] : 'Unknown';
                                    $taskCount = (int)($def['task_count'] ?? 0);
                                ?>
                                    <tr>
                                        <td><?= htmlspecialchars($def['trainee_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($qualName) ?></td>
                                        <td class="text-center"><strong><?= $taskCount ?></strong></td>
                                        <td class="text-center text-green">Notified</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:20px;padding:12px 16px;background:#f8fafc;border-radius:8px;border-left:4px solid #8b5cf6;">
                    <p style="color:#64748b;font-size:0.85rem;margin:0;">
                        <i class="fas fa-info-circle" style="color:#8b5cf6;"></i> 
                        This report is generated automatically and is ready for TESDA inspection purposes.
                        Overall compliance rating is based on completed vs total task evaluations.
                    </p>
                </div>
            </div>
            
            <script>
                (function() {
                    const loadingEl = document.getElementById('reportLoadingIndicator');
                    if (loadingEl) loadingEl.style.display = 'none';
                })();
            </script>
            <?php
            break;

        default:
            echo '<div class="alert alert-warning m-3">Invalid report type requested: ' . htmlspecialchars($report_type) . '</div>';
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo '<div class="alert alert-danger m-3">Database error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>