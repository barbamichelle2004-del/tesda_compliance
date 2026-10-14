<?php
// ============================================================
// OUTPUT BUFFERING — CRITICAL FIX FOR JSON RESPONSES
// ============================================================
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/auth_check.php';

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
$user_id = $_SESSION['user_id'] ?? 0;

try {
    require_once __DIR__ . '/helpers/helpers/PusherHelper.php';
} catch (Throwable $e) {
    error_log("PusherHelper load error: " . $e->getMessage());
}

function send_json($data) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
    }
    echo json_encode($data);
    exit;
}

if ($user_role === 'trainer' || $user_role === 'Trainer') {
    if (!isset($_SESSION['assigned_qualifications']) || empty($_SESSION['assigned_qualifications'])) {
        try {
            if (isset($_SESSION['qualification_ids']) && !empty($_SESSION['qualification_ids'])) {
                $_SESSION['assigned_qualifications'] = $_SESSION['qualification_ids'];
                $_SESSION['assigned_qualifications_string'] = implode(',', $_SESSION['qualification_ids']);
            } else {
                $qual_stmt = $pdo->prepare("SELECT qualification_id FROM user_qualifications WHERE user_id = ?");
                $qual_stmt->execute([$user_id]);
                $assigned_quals = $qual_stmt->fetchAll(PDO::FETCH_COLUMN);
                $_SESSION['assigned_qualifications'] = !empty($assigned_quals) ? $assigned_quals : [];
                $_SESSION['assigned_qualifications_string'] = !empty($assigned_quals) ? implode(',', $assigned_quals) : '';
            }
        } catch (PDOException $e) {
            error_log("Error loading trainer qualifications: " . $e->getMessage());
            $_SESSION['assigned_qualifications'] = [];
            $_SESSION['assigned_qualifications_string'] = '';
        }
    }
}

// ==========================================
// HANDLE MARK AS COMPLETED (AJAX)
// ==========================================
if (isset($_POST['action']) && $_POST['action'] === 'mark_completed') {
    try {
        $trainee_id = (int)($_POST['id'] ?? 0);
        if ($trainee_id <= 0) throw new Exception("Invalid trainee ID.");

        $check = $pdo->prepare("SELECT id, first_name, last_name, status, qualification_id, department FROM trainees WHERE id = ? AND deleted_at IS NULL");
        $check->execute([$trainee_id]);
        $trainee = $check->fetch(PDO::FETCH_ASSOC);

        if (!$trainee) throw new Exception("Trainee not found.");
        if ($trainee['status'] === 'Completed') throw new Exception("This trainee is already marked as Completed.");

        if ($user_role === 'trainer' || $user_role === 'Trainer') {
            $assigned_quals = $_SESSION['assigned_qualifications'] ?? [];
            if (empty($assigned_quals) || !in_array($trainee['qualification_id'], $assigned_quals)) {
                throw new Exception("You don't have permission to modify this trainee.");
            }
        }

        $archive_stmt = $pdo->prepare("
            INSERT INTO trainees_archive (
                original_id, first_name, middle_initial, last_name, suffix,
                section, sex, email, contact, department, qualification_id, status,
                pre_assessment_notified, completion_alert_sent, completion_alert_sent_at,
                created_at, deleted_at, archive_reason, archived_by
            )
            SELECT id, first_name, middle_initial, last_name, suffix,
                section, sex, email, contact, department, qualification_id, status,
                pre_assessment_notified, completion_alert_sent, completion_alert_sent_at,
                created_at, deleted_at, 'completed', ?
            FROM trainees WHERE id = ?
        ");
        $archive_stmt->execute([$user_id, $trainee_id]);

        $del_stmt = $pdo->prepare("DELETE FROM trainees WHERE id = ?");
        $del_stmt->execute([$trainee_id]);

        try {
            triggerPusherUpdate('trainee_completed', [
                'trainee_id' => $trainee_id,
                'trainee_name' => $trainee['first_name'] . ' ' . $trainee['last_name'],
                'department' => $trainee['department'],
                'new_status' => 'Completed',
                'timestamp' => date('Y-m-d H:i:s')
            ], 'compliance-channel');
        } catch (Exception $e) { error_log("Pusher error: " . $e->getMessage()); }

        send_json(['success' => true, 'message' => 'Trainee marked as Completed. Record saved to archive.']);
    } catch (Exception $e) {
        send_json(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ==========================================
// AJAX: Check Reference ID
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 'check_reference_id') {
    $reference_id = trim($_GET['reference_id'] ?? '');
    if ($reference_id === '') send_json(['success' => false, 'exists' => false, 'message' => 'Reference ID is required.']);
    try {
        $edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
        if ($edit_id > 0) {
            $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM trainees WHERE reference_id = ? AND id != ? AND deleted_at IS NULL LIMIT 1");
            $stmt->execute([$reference_id, $edit_id]);
        } else {
            $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM trainees WHERE reference_id = ? AND deleted_at IS NULL LIMIT 1");
            $stmt->execute([$reference_id]);
        }
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            send_json(['success' => true, 'exists' => true, 'message' => 'This Reference ID is already used by ' . $existing['first_name'] . ' ' . $existing['last_name'] . '.']);
        } else {
            send_json(['success' => true, 'exists' => false, 'message' => 'Reference ID is available.']);
        }
    } catch (PDOException $e) {
        send_json(['success' => false, 'exists' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

// ==========================================
// AJAX: Get qualifications for department
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_qualifications') {
    $course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
    if ($course_id > 0) {
        if ($user_role === 'trainer' || $user_role === 'Trainer') {
            $assigned_quals = $_SESSION['assigned_qualifications'] ?? [];
            if (!empty($assigned_quals)) {
                $placeholders = implode(',', array_fill(0, count($assigned_quals), '?'));
                $stmt = $pdo->prepare("SELECT id, qualification_name, qualification_code FROM qualifications WHERE course_id = ? AND id IN ($placeholders) ORDER BY qualification_name");
                $stmt->execute(array_merge([$course_id], $assigned_quals));
            } else {
                send_json(['success' => true, 'data' => []]);
            }
        } else {
            $stmt = $pdo->prepare("SELECT id, qualification_name, qualification_code FROM qualifications WHERE course_id = ? ORDER BY qualification_name");
            $stmt->execute([$course_id]);
        }
        send_json(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } else {
        send_json(['success' => false, 'message' => 'Invalid course ID']);
    }
}

// ==========================================
// AJAX: Filter trainees
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 'filter_trainees') {
    try {
        $search = $_GET['search'] ?? '';
        $department_filter = $_GET['department'] ?? '';
        $section_filter = $_GET['section'] ?? '';
        $active_section = $_GET['set_section'] ?? 'A';
        $qualification_filter = $_GET['qualification'] ?? '';

        $courses = $pdo->query("SELECT * FROM courses ORDER BY course_code")->fetchAll();

        $query = "
            SELECT 
                t.id, t.reference_id,
                CONCAT(t.last_name, ', ', t.first_name) as full_name,
                t.first_name, t.last_name, t.middle_initial, t.suffix,
                t.section, t.email, t.contact, t.department, t.sex,
                t.birthdate, t.address, t.qualification_id, t.status,
                c.id as course_id, c.course_code as department_code,
                c.course_name as department_name, c.color as department_color,
                q.qualification_name, q.qualification_code
            FROM trainees t
            JOIN courses c ON t.department = c.course_code
            LEFT JOIN qualifications q ON t.qualification_id = q.id
            WHERE t.deleted_at IS NULL
            AND (t.status IS NULL OR t.status != 'Completed')
        ";
        $params = [];

        if ($user_role === 'trainer' || $user_role === 'Trainer') {
            $assigned_quals = $_SESSION['assigned_qualifications'] ?? [];
            if (!empty($assigned_quals)) {
                $placeholders = implode(',', array_fill(0, count($assigned_quals), '?'));
                $query .= " AND t.qualification_id IN ($placeholders)";
                $params = array_merge($params, $assigned_quals);
            } else {
                $query .= " AND 1=0";
            }
        }

        if (!empty($qualification_filter) && $qualification_filter !== 'all') {
            $query .= " AND q.qualification_code = ?";
            $params[] = $qualification_filter;
        }

        if (!empty($search)) {
            $query .= " AND (t.first_name LIKE ? OR t.last_name LIKE ? OR t.email LIKE ? OR t.contact LIKE ? OR t.reference_id LIKE ?)";
            $sp = "%$search%";
            for ($i = 0; $i < 5; $i++) $params[] = $sp;
        }

        if (!empty($department_filter)) {
            $query .= " AND c.id = ?";
            $params[] = $department_filter;
        }

        if (!empty($section_filter)) {
            $query .= " AND t.section = ?";
            $params[] = $section_filter;
        }

        $query .= " ORDER BY c.course_code, t.section, q.qualification_name, t.sex, t.last_name";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $trainees = $stmt->fetchAll();

        $trainees_by_dept = [];
        foreach ($courses as $course) {
            $trainees_by_dept[$course['course_code']] = [
                'name' => $course['course_name'],
                'color' => $course['color'] ?? '#8b5cf6',
                'course_id' => $course['id'],
                'sections' => ['A' => [], 'B' => []]
            ];
        }

        foreach ($trainees as $t) {
            $dept_code = $t['department_code'];
            $section = $t['section'] ?: 'A';
            $qual_name = $t['qualification_name'] ?? 'No Qualification';
            $qual_id = $t['qualification_id'] ?? 'none';
            $sex = $t['sex'] ?? 'Male';

            if (isset($trainees_by_dept[$dept_code])) {
                if (!isset($trainees_by_dept[$dept_code]['sections'][$section][$qual_id])) {
                    $trainees_by_dept[$dept_code]['sections'][$section][$qual_id] = [
                        'qualification_name' => $qual_name,
                        'qualification_code' => $t['qualification_code'] ?? '',
                        'Male' => [],
                        'Female' => []
                    ];
                }
                $trainees_by_dept[$dept_code]['sections'][$section][$qual_id][$sex][] = $t;
            }
        }

        ob_start();

        $has_trainees = false;
        foreach ($trainees_by_dept as $dept_data) {
            foreach (['A', 'B'] as $section) {
                if (isset($dept_data['sections'][$section]) && !empty($dept_data['sections'][$section])) {
                    $has_trainees = true;
                    break 2;
                }
            }
        }

        if (!$has_trainees): ?>
            <div class="empty-state">
                <i class="fas fa-users"></i>
                <h3>No Trainees Found</h3>
                <p><?php if ($user_role === 'trainer' || $user_role === 'Trainer'): ?>
                    You don't have any trainees assigned to your qualifications.
                <?php else: ?>
                    Try adjusting your search filters or add a new trainee.
                <?php endif; ?></p>
            </div>
        <?php else: 
            foreach ($trainees_by_dept as $dept_code => $dept_data): 
                $icon = $dept_code == 'HRS' ? 'fa-hotel' : ($dept_code == 'CSS' ? 'fa-laptop-code' : 'fa-cogs');
                $total_dept = 0;
                foreach (['A', 'B'] as $section) {
                    if (isset($dept_data['sections'][$section])) {
                        foreach ($dept_data['sections'][$section] as $qual_data) {
                            $total_dept += count($qual_data['Male'] ?? []) + count($qual_data['Female'] ?? []);
                        }
                    }
                }
                if ($total_dept == 0) continue;
            ?>
            <div class="department-section" data-department="<?= $dept_code ?>" data-course-id="<?= $dept_data['course_id'] ?>">
                <div class="department-header" style="border-bottom-color: <?= $dept_data['color'] ?>">
                    <div class="department-icon" style="background: <?= $dept_data['color'] ?>">
                        <i class="fas <?= $icon ?>"></i>
                    </div>
                    <div class="department-info">
                        <h3><?= htmlspecialchars($dept_data['name']) ?> <span class="dept-badge"><?= $dept_code ?></span></h3>
                        <p><?= $total_dept ?> trainees enrolled</p>
                    </div>
                    <div class="department-actions">
                        <?php if ($user_role === 'coordinator' || ($user_role === 'trainer' && $dept_code === $user_department)): ?>
                        <button class="btn-add-trainee-dept" onclick="openDepartmentTraineeModal(<?= $dept_data['course_id'] ?>, '<?= htmlspecialchars($dept_data['name']) ?>')">
                            <i class="fas fa-user-plus"></i> Add Trainee
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="trainee-section">
                    <div class="section-tabs">
                        <button class="section-tab <?= $active_section == 'A' ? 'active' : '' ?>" onclick="showSection(this, '<?= $dept_code ?>', 'A')">Section A</button>
                        <button class="section-tab <?= $active_section == 'B' ? 'active' : '' ?>" onclick="showSection(this, '<?= $dept_code ?>', 'B')">Section B</button>
                    </div>
                    
                    <?php foreach (['A', 'B'] as $sec_key): 
                        $section_data = isset($dept_data['sections'][$sec_key]) ? $dept_data['sections'][$sec_key] : [];
                    ?>
                    <div id="section-<?= $dept_code ?>-<?= $sec_key ?>" class="section-content <?= $active_section != $sec_key ? 'hidden' : '' ?>">
                        <?php if (empty($section_data)): ?>
                            <div class="empty-state" style="padding: 30px;">
                                <i class="fas fa-user-slash"></i>
                                <p>No trainees in Section <?= $sec_key ?></p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($section_data as $qual_id => $qual_data): 
                                $male_trainees = $qual_data['Male'] ?? [];
                                $female_trainees = $qual_data['Female'] ?? [];
                                $total_qual = count($male_trainees) + count($female_trainees);
                                if ($total_qual == 0) continue;
                            ?>
                            <div class="qualification-group expanded">
                                <div class="qualification-header" onclick="toggleQualification(this)">
                                    <div class="qual-header-left">
                                        <i class="fas fa-chevron-right toggle-icon"></i>
                                        <i class="fas fa-certificate qual-icon"></i>
                                        <span class="qual-name"><?= htmlspecialchars($qual_data['qualification_name']) ?></span>
                                        <?php if (!empty($qual_data['qualification_code'])): ?>
                                        <span class="qual-code">(<?= htmlspecialchars($qual_data['qualification_code']) ?>)</span>
                                        <?php endif; ?>
                                        <span class="qual-count"><?= $total_qual ?> trainees</span>
                                    </div>
                                    <div class="qual-header-right">
                                        <span class="gender-badge male-badge"><i class="fas fa-mars"></i> <?= count($male_trainees) ?></span>
                                        <span class="gender-badge female-badge"><i class="fas fa-venus"></i> <?= count($female_trainees) ?></span>
                                    </div>
                                </div>
                                <div class="qualification-body">
                                    <?php foreach (['Male' => $male_trainees, 'Female' => $female_trainees] as $gender_key => $gender_trainees): 
                                        if (empty($gender_trainees)) continue;
                                    ?>
                                    <div class="gender-section">
                                        <div class="gender-header <?= strtolower($gender_key) ?>">
                                            <i class="fas fa-<?= $gender_key == 'Male' ? 'mars' : 'venus' ?>"></i> <?= $gender_key ?> Trainees
                                            <span class="gender-count"><?= count($gender_trainees) ?></span>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="trainees-table">
                                                <thead>
                                                    <tr>
                                                        <th>Reference ID</th>
                                                        <th>Name</th>
                                                        <th>Address</th>
                                                        <th>Email</th>
                                                        <th>Contact</th>
                                                        <th style="text-align:center;">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($gender_trainees as $t): 
                                                        $display_name = $t['last_name'] . ', ' . $t['first_name'];
                                                        if (!empty($t['suffix'])) { $display_name .= ' ' . $t['suffix']; }
                                                        $address_display = !empty($t['address']) ? $t['address'] : 'N/A';
                                                        $email_display = !empty($t['email']) ? $t['email'] : 'N/A';
                                                        $first_name_js = htmlspecialchars(addslashes($t['first_name'] . ' ' . $t['last_name']));
                                                    ?>
                                                    <tr class="trainee-row"
                                                        data-name="<?= htmlspecialchars(strtolower($t['last_name'] . ' ' . $t['first_name'])) ?>"
                                                        data-alert-sent="<?= !empty($t['completion_alert_sent']) ? '1' : '0' ?>"
                                                        data-section="<?= htmlspecialchars($t['section'] ?? '') ?>">
                                                        <td><span class="ref-id-badge"><?= htmlspecialchars($t['reference_id'] ?? 'N/A') ?></span></td>
                                                        <td><strong class="trainee-name-text"><?= htmlspecialchars($display_name) ?></strong></td>
                                                        <td><span class="truncate-text" title="<?= htmlspecialchars($address_display) ?>"><?= htmlspecialchars($address_display) ?></span></td>
                                                        <td><span class="truncate-text" title="<?= htmlspecialchars($email_display) ?>"><?= htmlspecialchars($email_display) ?></span></td>
                                                        <td><?= !empty($t['contact']) ? htmlspecialchars($t['contact']) : 'N/A' ?></td>
                                                        <td>
                                                            <div class="action-buttons">
                                                                <button class="btn-view" onclick="openViewModal(<?= $t['id'] ?>)" title="View"><i class="fas fa-eye"></i></button>
                                                                <button class="btn-edit" onclick="openEditModal(<?= $t['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                                                                <button class="btn-complete" onclick="openCompleteModal(<?= $t['id'] ?>, '<?= $first_name_js ?>')" title="Mark as Completed"><i class="fas fa-check-circle"></i></button>
                                                                <button class="btn-delete" onclick="openDeleteModal(<?= $t['id'] ?>, '<?= $first_name_js ?>')" title="Delete"><i class="fas fa-trash"></i></button>
                                                            </div>
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
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; 
        endif;

        $html = ob_get_clean();

        // ✅ FIX: Compute dept_counts from separate SQL query (accurate)
        $dept_counts = [];
        foreach ($courses as $course) {
            $dept_counts[$course['course_code']] = 0;
        }

        $count_sql = "
            SELECT c.course_code AS dept_code, COUNT(DISTINCT t.id) AS cnt
            FROM trainees t
            INNER JOIN courses c ON t.department = c.course_code
            WHERE t.deleted_at IS NULL
              AND (t.status IS NULL OR t.status != 'Completed')
        ";
        $count_params = [];

        if ($user_role === 'trainer' || $user_role === 'Trainer') {
            $assigned_quals = $_SESSION['assigned_qualifications'] ?? [];
            if (!empty($assigned_quals)) {
                $placeholders = implode(',', array_fill(0, count($assigned_quals), '?'));
                $count_sql .= " AND t.qualification_id IN ($placeholders)";
                $count_params = array_merge($count_params, $assigned_quals);
            } else {
                $count_sql .= " AND 1=0";
            }
        }

        $count_sql .= " GROUP BY c.course_code";

        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute($count_params);
        foreach ($count_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dept_counts[$row['dept_code']] = (int)$row['cnt'];
        }

        $total_all = array_sum($dept_counts);

        send_json([
            'success' => true,
            'html' => $html,
            'total_visible' => count($trainees),
            'total_all' => $total_all,
            'dept_counts' => $dept_counts
        ]);
    } catch (Exception $e) {
        error_log("Error in filter_trainees: " . $e->getMessage());
        send_json(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ==========================================
// AJAX: Get stats
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_stats') {
    try {
        if ($user_role === 'trainer' || $user_role === 'Trainer') {
            $assigned_quals = $_SESSION['assigned_qualifications'] ?? [];
            if (!empty($assigned_quals)) {
                $placeholders = implode(',', array_fill(0, count($assigned_quals), '?'));
                $sql = "SELECT COUNT(DISTINCT t.id) as total_trainees,
                        SUM(CASE WHEN t.department = 'HRS' THEN 1 ELSE 0 END) as hrs_count,
                        SUM(CASE WHEN t.department = 'CSS' THEN 1 ELSE 0 END) as css_count,
                        SUM(CASE WHEN t.department = 'ATS' THEN 1 ELSE 0 END) as ats_count
                    FROM trainees t
                    WHERE t.qualification_id IN ($placeholders)
                    AND t.deleted_at IS NULL
                    AND (t.status IS NULL OR t.status != 'Completed')";
                $stats_query = $pdo->prepare($sql);
                $stats_query->execute($assigned_quals);
                $stats_query = $stats_query->fetch();
            } else {
                $stats_query = ['total_trainees' => 0, 'hrs_count' => 0, 'css_count' => 0, 'ats_count' => 0];
            }
        } else {
            $stats_query = $pdo->query("SELECT COUNT(DISTINCT t.id) as total_trainees,
                SUM(CASE WHEN t.department = 'HRS' THEN 1 ELSE 0 END) as hrs_count,
                SUM(CASE WHEN t.department = 'CSS' THEN 1 ELSE 0 END) as css_count,
                SUM(CASE WHEN t.department = 'ATS' THEN 1 ELSE 0 END) as ats_count
                FROM trainees t
                WHERE t.deleted_at IS NULL
                AND (t.status IS NULL OR t.status != 'Completed')")->fetch();
        }
        send_json([
            'success' => true,
            'total_trainees' => (int)($stats_query['total_trainees'] ?? 0),
            'dept_counts' => [
                'HRS' => (int)($stats_query['hrs_count'] ?? 0),
                'CSS' => (int)($stats_query['css_count'] ?? 0),
                'ATS' => (int)($stats_query['ats_count'] ?? 0)
            ]
        ]);
    } catch (Exception $e) {
        send_json(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ==========================================
// AJAX: Get trainee data
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_trainee_data') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id > 0) {
        if ($user_role === 'trainer' || $user_role === 'Trainer') {
            $assigned_quals = $_SESSION['assigned_qualifications'] ?? [];
            if (!empty($assigned_quals)) {
                $placeholders = implode(',', array_fill(0, count($assigned_quals), '?'));
                $check_stmt = $pdo->prepare("SELECT t.qualification_id FROM trainees t WHERE t.id = ? AND t.qualification_id IN ($placeholders) AND t.deleted_at IS NULL");
                $check_stmt->execute(array_merge([$id], $assigned_quals));
                if (!$check_stmt->fetchColumn()) send_json(['success' => false, 'message' => 'You don\'t have access to this trainee']);
            } else {
                send_json(['success' => false, 'message' => 'You don\'t have any assigned qualifications']);
            }
        }
        $stmt = $pdo->prepare("SELECT t.*, c.course_name, c.course_code, c.color as department_color, q.qualification_name, q.qualification_code FROM trainees t LEFT JOIN courses c ON t.department = c.course_code LEFT JOIN qualifications q ON t.qualification_id = q.id WHERE t.id = ? AND t.deleted_at IS NULL");
        $stmt->execute([$id]);
        $trainee = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($trainee) {
            $dept_icon = 'fa-building'; $dept_color = '#667eea';
            $dept_code = $trainee['course_code'] ?? 'HRS';
            if ($dept_code == 'HRS') { $dept_icon = 'fa-hotel'; $dept_color = '#8b5cf6'; }
            elseif ($dept_code == 'CSS') { $dept_icon = 'fa-laptop-code'; $dept_color = '#10b981'; }
            elseif ($dept_code == 'ATS') { $dept_icon = 'fa-car'; $dept_color = '#f59e0b'; }
            $trainee['dept_icon'] = $dept_icon;
            $trainee['dept_color'] = $dept_color;
            $trainee['age'] = null;
            if (!empty($trainee['birthdate'])) {
                try { $bd = new DateTime($trainee['birthdate']); $trainee['age'] = $bd->diff(new DateTime('now'))->y; } catch (Exception $e) {}
            }
            send_json(['success' => true, 'data' => $trainee]);
        } else {
            send_json(['success' => false, 'message' => 'Trainee not found']);
        }
    } else {
        send_json(['success' => false, 'message' => 'Invalid ID']);
    }
}

// ==========================================
// HANDLE DELETE TRAINEE
// ==========================================
if (isset($_POST['delete_trainee'])) {
    try {
        $trainee_id = (int)($_POST['id'] ?? 0);
        if ($trainee_id <= 0) throw new Exception("Invalid trainee ID.");

        if ($user_role === 'trainer' || $user_role === 'Trainer') {
            $assigned_quals = $_SESSION['assigned_qualifications'] ?? [];
            if (!empty($assigned_quals)) {
                $placeholders = implode(',', array_fill(0, count($assigned_quals), '?'));
                $check_stmt = $pdo->prepare("SELECT t.qualification_id FROM trainees t WHERE t.id = ? AND t.qualification_id IN ($placeholders) AND t.deleted_at IS NULL");
                $check_stmt->execute(array_merge([$trainee_id], $assigned_quals));
                if (!$check_stmt->fetchColumn()) throw new Exception("You don't have permission to delete this trainee.");
            } else {
                throw new Exception("You don't have any assigned qualifications.");
            }
        }

        $info_stmt = $pdo->prepare("SELECT first_name, last_name, department FROM trainees WHERE id = ?");
        $info_stmt->execute([$trainee_id]);
        $info = $info_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$info) throw new Exception("Trainee not found.");

        $archive_stmt = $pdo->prepare("
            INSERT INTO trainees_archive (
                original_id, first_name, middle_initial, last_name, suffix,
                section, sex, email, contact, department, qualification_id, status,
                pre_assessment_notified, completion_alert_sent, completion_alert_sent_at,
                created_at, deleted_at, archive_reason, archived_by
            )
            SELECT id, first_name, middle_initial, last_name, suffix,
                section, sex, email, contact, department, qualification_id, status,
                pre_assessment_notified, completion_alert_sent, completion_alert_sent_at,
                created_at, deleted_at, 'deleted', ?
            FROM trainees WHERE id = ?
        ");
        $archive_stmt->execute([$user_id, $trainee_id]);

        $del_stmt = $pdo->prepare("DELETE FROM trainees WHERE id = ?");
        $del_stmt->execute([$trainee_id]);

        try {
            triggerPusherUpdate('trainee_deleted', [
                'trainee_id' => $trainee_id,
                'trainee_name' => ($info['first_name'] ?? '') . ' ' . ($info['last_name'] ?? ''),
                'department' => $info['department'] ?? '',
                'deleted_at' => date('Y-m-d H:i:s')
            ], 'compliance-channel');
        } catch (Exception $e) {}

        send_json(['success' => true, 'message' => 'Trainee deleted. Record saved to archive.']);
    } catch (Exception $e) {
        send_json(['success' => false, 'message' => 'Error deleting trainee: ' . $e->getMessage()]);
    }
}

// ==========================================
// HANDLE ADD/EDIT TRAINEE
// ==========================================
if (isset($_POST['ajax_action'])) {
    if ($_POST['ajax_action'] == 'add_trainee') {
        try {
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $middle_initial = trim($_POST['middle_initial'] ?? '');
            $suffix = trim($_POST['suffix'] ?? '');
            $section = $_POST['section'] ?? '';
            $email = trim($_POST['email'] ?? '');
            $contact = trim($_POST['contact'] ?? '');
            $course_id = (int)($_POST['course_id'] ?? 0);
            $sex = $_POST['sex'] ?? '';
            $birthdate = trim($_POST['birthdate'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $qualification_id = !empty($_POST['qualification_id']) ? (int)$_POST['qualification_id'] : null;
            $reference_id = trim($_POST['reference_id'] ?? '');

            if (empty($reference_id)) throw new Exception("Reference ID is required.");
            if (empty($first_name) || empty($last_name)) throw new Exception("First name and last name are required.");
            if (empty($section)) throw new Exception("Please select a section.");
            if (empty($sex)) throw new Exception("Please select sex.");

            if (!empty($birthdate)) {
                $bd_ts = strtotime($birthdate);
                if ($bd_ts === false || $bd_ts > time()) throw new Exception("Invalid birthdate.");
                $birthdate = date('Y-m-d', $bd_ts);
            } else { $birthdate = null; }

            $ref_check = $pdo->prepare("SELECT id, first_name, last_name FROM trainees WHERE reference_id = ? AND deleted_at IS NULL LIMIT 1");
            $ref_check->execute([$reference_id]);
            $dup = $ref_check->fetch(PDO::FETCH_ASSOC);
            if ($dup) throw new Exception("Reference ID '{$reference_id}' is already assigned to {$dup['first_name']} {$dup['last_name']}.");

            if ($user_role === 'trainer' || $user_role === 'Trainer') {
                $assigned_quals = $_SESSION['assigned_qualifications'] ?? [];
                if (empty($assigned_quals) || empty($qualification_id) || !in_array($qualification_id, $assigned_quals)) {
                    throw new Exception("You are not authorized to add a trainee to this qualification.");
                }
            }

            $dept_stmt = $pdo->prepare("SELECT course_code FROM courses WHERE id = ?");
            $dept_stmt->execute([$course_id]);
            $department = $dept_stmt->fetchColumn();
            if (!$department) throw new Exception("Invalid department");

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO trainees (reference_id, first_name, last_name, middle_initial, suffix, section, email, contact, department, sex, birthdate, address, qualification_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$reference_id, $first_name, $last_name, $middle_initial, $suffix, $section, $email, $contact, $department, $sex, $birthdate, $address, $qualification_id]);
            $trainee_id = $pdo->lastInsertId();

            if ($qualification_id) {
                $trainers = $pdo->prepare("SELECT user_id FROM user_qualifications WHERE qualification_id = ?");
                $trainers->execute([$qualification_id]);
                $trainer_ids = $trainers->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($trainer_ids)) {
                    $assign_stmt = $pdo->prepare("INSERT INTO trainer_trainee_assignments (trainer_id, trainee_id) VALUES (?, ?)");
                    foreach ($trainer_ids as $trainer_id) {
                        try { $assign_stmt->execute([$trainer_id, $trainee_id]); } catch(PDOException $e) {}
                    }
                }
            }
            $pdo->commit();

            try {
                triggerPusherUpdate('trainee_added', [
                    'trainee_id' => $trainee_id,
                    'reference_id' => $reference_id,
                    'trainee_name' => $first_name . ' ' . $last_name,
                    'department' => $department,
                    'qualification_id' => $qualification_id,
                    'section' => $section
                ], 'compliance-channel');
            } catch (Exception $e) {}

            send_json(['success' => true, 'message' => "Trainee added successfully! Reference ID: " . $reference_id, 'reference_id' => $reference_id, 'trainee_id' => $trainee_id]);
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            send_json(['success' => false, 'message' => $e->getMessage()]);
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            send_json(['success' => false, 'message' => $e->getCode() == 23000 ? 'This Reference ID is already in use.' : 'Database error: ' . $e->getMessage()]);
        }
    }

    if ($_POST['ajax_action'] == 'edit_trainee') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $first_name = trim($_POST['first_name'] ?? '');
            $last_name = trim($_POST['last_name'] ?? '');
            $middle_initial = trim($_POST['middle_initial'] ?? '');
            $suffix = trim($_POST['suffix'] ?? '');
            $section = $_POST['section'] ?? '';
            $email = trim($_POST['email'] ?? '');
            $contact = trim($_POST['contact'] ?? '');
            $course_id = (int)($_POST['course_id'] ?? 0);
            $sex = $_POST['sex'] ?? '';
            $birthdate = trim($_POST['birthdate'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $qualification_id = !empty($_POST['qualification_id']) ? (int)$_POST['qualification_id'] : null;

            if ($id <= 0) throw new Exception("Invalid trainee ID.");
            if (empty($first_name) || empty($last_name)) throw new Exception("First name and last name are required.");
            if (empty($section)) throw new Exception("Please select a section.");
            if (empty($sex)) throw new Exception("Please select sex.");

            if (!empty($birthdate)) {
                $bd_ts = strtotime($birthdate);
                if ($bd_ts === false || $bd_ts > time()) throw new Exception("Invalid birthdate.");
                $birthdate = date('Y-m-d', $bd_ts);
            } else { $birthdate = null; }

            if ($user_role === 'trainer' || $user_role === 'Trainer') {
                $assigned_quals = $_SESSION['assigned_qualifications'] ?? [];
                if (!empty($assigned_quals)) {
                    $placeholders = implode(',', array_fill(0, count($assigned_quals), '?'));
                    $check_stmt = $pdo->prepare("SELECT t.qualification_id FROM trainees t WHERE t.id = ? AND t.qualification_id IN ($placeholders) AND t.deleted_at IS NULL");
                    $check_stmt->execute(array_merge([$id], $assigned_quals));
                    if (!$check_stmt->fetchColumn()) throw new Exception("You don't have permission to edit this trainee.");
                } else {
                    throw new Exception("You don't have any assigned qualifications.");
                }
            }

            $dept_stmt = $pdo->prepare("SELECT course_code FROM courses WHERE id = ?");
            $dept_stmt->execute([$course_id]);
            $department = $dept_stmt->fetchColumn();
            if (!$department) throw new Exception("Invalid department");

            $stmt = $pdo->prepare("UPDATE trainees SET first_name=?, last_name=?, middle_initial=?, suffix=?, section=?, email=?, contact=?, department=?, sex=?, birthdate=?, address=?, qualification_id=? WHERE id=? AND deleted_at IS NULL");
            $stmt->execute([$first_name, $last_name, $middle_initial, $suffix, $section, $email, $contact, $department, $sex, $birthdate, $address, $qualification_id, $id]);

            try {
                triggerPusherUpdate('trainee_updated', [
                    'trainee_id' => $id,
                    'trainee_name' => $first_name . ' ' . $last_name,
                    'department' => $department,
                    'qualification_id' => $qualification_id,
                    'section' => $section
                ], 'compliance-channel');
            } catch (Exception $e) {}

            send_json(['success' => true, 'message' => 'Trainee updated successfully!']);
        } catch (Exception $e) {
            send_json(['success' => false, 'message' => $e->getMessage()]);
        } catch (PDOException $e) {
            send_json(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }
}

// ==========================================
// NORMAL PAGE LOAD
// ==========================================
$courses = $pdo->query("SELECT * FROM courses ORDER BY course_code")->fetchAll();

if ($user_role === 'trainer' || $user_role === 'Trainer') {
    $assigned_quals = $_SESSION['assigned_qualifications'] ?? [];
    if (!empty($assigned_quals)) {
        $placeholders = implode(',', array_fill(0, count($assigned_quals), '?'));
        $stmt = $pdo->prepare("SELECT q.id, q.qualification_name, q.qualification_code, q.course_id FROM qualifications q WHERE q.id IN ($placeholders) ORDER BY q.qualification_name");
        $stmt->execute($assigned_quals);
        $all_qualifications = $stmt->fetchAll();
    } else {
        $all_qualifications = [];
    }
} else {
    $all_qualifications = $pdo->query("SELECT q.id, q.qualification_name, q.qualification_code, q.course_id FROM qualifications q ORDER BY q.qualification_name")->fetchAll();
}

$qualification_pills = [];
if ($user_role === 'coordinator' || $user_role === 'Coordinator' || $user_role === 'admin' || $user_role === 'Admin') {
    try {
        $pills_stmt = $pdo->query("SELECT DISTINCT q.qualification_code, q.qualification_name, q.course_id, c.course_code AS dept_code, c.color AS dept_color FROM qualifications q LEFT JOIN courses c ON q.course_id = c.id WHERE q.qualification_code IS NOT NULL AND q.qualification_code != '' ORDER BY c.course_code ASC, q.qualification_code ASC");
        $qualification_pills = $pills_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $qualification_pills = [];
    }
}

if ($user_role === 'trainer' || $user_role === 'Trainer') {
    $assigned_quals = $_SESSION['assigned_qualifications'] ?? [];
    if (!empty($assigned_quals)) {
        $placeholders = implode(',', array_fill(0, count($assigned_quals), '?'));
        $stats_query = $pdo->prepare("SELECT COUNT(DISTINCT t.id) as total_trainees, SUM(CASE WHEN t.department = 'HRS' THEN 1 ELSE 0 END) as hrs_count, SUM(CASE WHEN t.department = 'CSS' THEN 1 ELSE 0 END) as css_count, SUM(CASE WHEN t.department = 'ATS' THEN 1 ELSE 0 END) as ats_count FROM trainees t WHERE t.qualification_id IN ($placeholders) AND t.deleted_at IS NULL AND (t.status IS NULL OR t.status != 'Completed')");
        $stats_query->execute($assigned_quals);
        $stats_query = $stats_query->fetch();
    } else {
        $stats_query = ['total_trainees' => 0, 'hrs_count' => 0, 'css_count' => 0, 'ats_count' => 0];
    }
} else {
    $stats_query = $pdo->query("SELECT COUNT(DISTINCT t.id) as total_trainees, SUM(CASE WHEN t.department = 'HRS' THEN 1 ELSE 0 END) as hrs_count, SUM(CASE WHEN t.department = 'CSS' THEN 1 ELSE 0 END) as css_count, SUM(CASE WHEN t.department = 'ATS' THEN 1 ELSE 0 END) as ats_count FROM trainees t WHERE t.deleted_at IS NULL AND (t.status IS NULL OR t.status != 'Completed')")->fetch();
}

$total_trainees = (int)($stats_query['total_trainees'] ?? 0);
$dept_counts_data = [
    'HRS' => (int)($stats_query['hrs_count'] ?? 0),
    'CSS' => (int)($stats_query['css_count'] ?? 0),
    'ATS' => (int)($stats_query['ats_count'] ?? 0)
];

$department_counts = [];
$dept_colors = ['HRS' => '#8b5cf6', 'CSS' => '#10b981', 'ATS' => '#f59e0b'];
foreach (['HRS', 'CSS', 'ATS'] as $code) {
    $department_counts[] = [
        'course_code' => $code,
        'color' => $dept_colors[$code],
        'trainee_count' => $dept_counts_data[$code] ?? 0
    ];
}

$success_message = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : '';
$error_message = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);

$is_trainer_role = ($user_role === 'trainer' || $user_role === 'Trainer');
$is_coordinator_role = ($user_role === 'coordinator' || $user_role === 'Coordinator' || $user_role === 'admin' || $user_role === 'Admin');

$trainer_primary_qual = 0;
$trainer_primary_dept = '';
$trainer_course_id    = 0;
$trainer_dept_display = '';

if ($is_trainer_role) {
    try {
        $stmt = $pdo->prepare("SELECT q.id AS qualification_id, q.qualification_name, q.qualification_code, q.course_id, c.course_code, c.course_name FROM user_qualifications uq JOIN qualifications q ON q.id = uq.qualification_id JOIN courses c ON c.id = q.course_id WHERE uq.user_id = ? ORDER BY q.id ASC LIMIT 1");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $trainer_primary_qual = (int)$row['qualification_id'];
            $trainer_course_id    = (int)$row['course_id'];
            $trainer_primary_dept = (string)$row['course_code'];
            $trainer_dept_display = (string)$row['course_code'];
        }
    } catch (Throwable $e) { error_log($e->getMessage()); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trainees - TESDA Compliance</title>
    <link rel="stylesheet" href="/tesda_compliance/assets/vendor/fontawesome/css/all.min.css">
    <style>
        .ref-id-badge { font-family: 'Poppins', sans-serif; font-size: 0.72rem; font-weight: 500; color: #000; background: #F1F5F9; padding: 4px 10px; border-radius: 30px; white-space: nowrap; display: inline-block; border: 1px solid #E2E8F0; letter-spacing: 0.2px; }
        .trainee-name-text { font-weight: 600; color: #0f172a; font-size: 0.9rem; white-space: nowrap; display: block; }
        .truncate-text { display: block; font-size: 0.85rem; color: #475569; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: #f0f2f5; min-width: 1200px; overflow-x: auto; margin: 0; transition: all 0.3s ease; }
        body.sidebar-collapsed { min-width: 992px; }
        .main-content { margin-left: 280px; padding: 30px; width: calc(100% - 280px); min-width: 920px; transition: margin-left 0.3s ease, width 0.3s ease; animation: fadeInPage 0.4s ease-out; }
        @keyframes fadeInPage { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        body.sidebar-collapsed .main-content { margin-left: 80px; width: calc(100% - 80px); }
        .header { background: white; border-radius: 24px; padding: 25px 35px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 10px 40px rgba(0,0,0,0.08); border: 1px solid rgba(255,255,255,0.2); background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .header:hover { transform: translateY(-2px); box-shadow: 0 15px 45px rgba(0,0,0,0.12); }
        .header-left { display: flex; flex-direction: column; gap: 2px; }
        .header-left h1 { font-size: 1.8rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 12px; margin: 0; line-height: 1.2; }
        .header-left h1 i { color: #8b5cf6; font-size: 1.8rem; }
        .header-left p { color: #64748b; font-size: 0.875rem; margin: 0; margin-top: 2px; }
        .header-right { display: flex; align-items: center; gap: 18px; }
        .profile-badge { display: flex; align-items: center; gap: 12px; padding: 6px 20px 6px 6px; background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 50px; transition: all 0.2s ease; cursor: default; }
        .profile-badge:hover { background-color: #f1f5f9; border-color: #cbd5e1; }
        .profile-badge .avatar-circle { width: 32px; height: 32px; border-radius: 50%; background-color: #263868; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.2s ease; }
        .profile-badge:hover .avatar-circle { transform: scale(1.05); }
        .profile-badge .avatar-circle i { color: #ffffff; font-size: 0.9rem; }
        .profile-badge .profile-name { font-weight: 600; font-size: 0.95rem; color: #1e293b; letter-spacing: 0.3px; white-space: nowrap; }
        .date-badge { background-color: #f8fafc; padding: 10px 20px; border-radius: 12px; color: #0f172a; font-weight: 500; font-size: 1rem; border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 10px; }
        .date-badge i { color: #0f172a; font-size: 1rem; }
        .date-badge:hover { background-color: #f1f5f9; }

        /* =========================================================
           STAT CARDS — FIXED WIDTH NUMBERS (NO LAYOUT SHIFT)
           ========================================================= */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 24px 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 16px;
            cursor: pointer;
            border: 1px solid #e8edf5;
            overflow: hidden;
            min-height: 100px;
        }
        .stat-card:hover { transform: translateY(-5px); box-shadow: 0 20px 40px rgba(0,0,0,0.12); }
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
        .stat-card:hover .stat-icon-wrapper { transform: scale(1.05); }
        .stat-icon-wrapper i { font-size: 1.5rem; color: white; }
        .stat-content { flex: 1; min-width: 0; }
        .stat-content h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1a1c2c;
            line-height: 1.2;
            margin-bottom: 2px;
            display: inline-block;
            min-width: 3.5ch;
            font-variant-numeric: tabular-nums;
            font-feature-settings: "tnum";
            text-align: left;
        }
        .stat-content p { color: #64748b; font-size: 0.9rem; font-weight: 500; margin: 0; }
        /* =========================================================
           END STAT CARDS FIX
           ========================================================= */

        .qual-pills-wrapper { background: white; border-radius: 16px; padding: 14px 20px; margin-bottom: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); border: 1px solid #e8edf5; display: flex; align-items: center; gap: 10px; overflow-x: auto; scrollbar-width: thin; scrollbar-color: #cbd5e1 #f1f5f9; }
        .qual-pills-wrapper::-webkit-scrollbar { height: 6px; }
        .qual-pills-wrapper::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 10px; }
        .qual-pills-wrapper::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .qual-pills-wrapper::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .qual-pill { flex: 1 1 0; padding: 9px 20px; border-radius: 30px; background: #ffffff; border: 2px solid #e2e8f0; color: #1a1c2c; font-family: 'Poppins', sans-serif; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); display: inline-flex; align-items: center; justify-content: center; gap: 8px; white-space: nowrap; user-select: none; min-width: 72px; }
        .qual-pill i { font-size: 0.8rem; transition: color 0.3s ease; }
        .qual-pill:hover { transform: translateY(-1px); }
        .qual-pill[data-dept="ATS"] { border-color: #fde68a; color: #b45309; }
        .qual-pill[data-dept="ATS"] i { color: #f59e0b; }
        .qual-pill[data-dept="ATS"]:hover { border-color: #f59e0b; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.2); }
        .qual-pill[data-dept="ATS"].active { background: linear-gradient(135deg, #f59e0b, #d97706); color: #ffffff; border-color: #f59e0b; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.35); }
        .qual-pill[data-dept="ATS"].active i { color: #ffffff; }
        .qual-pill[data-dept="CSS"] { border-color: #a7f3d0; color: #047857; }
        .qual-pill[data-dept="CSS"] i { color: #10b981; }
        .qual-pill[data-dept="CSS"]:hover { border-color: #10b981; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2); }
        .qual-pill[data-dept="CSS"].active { background: linear-gradient(135deg, #10b981, #059669); color: #ffffff; border-color: #10b981; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.35); }
        .qual-pill[data-dept="CSS"].active i { color: #ffffff; }
        .qual-pill[data-dept="HRS"] { border-color: #ddd6fe; color: #6d28d9; }
        .qual-pill[data-dept="HRS"] i { color: #8b5cf6; }
        .qual-pill[data-dept="HRS"]:hover { border-color: #8b5cf6; box-shadow: 0 4px 12px rgba(139, 92, 246, 0.2); }
        .qual-pill[data-dept="HRS"].active { background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: #ffffff; border-color: #8b5cf6; box-shadow: 0 4px 12px rgba(139, 92, 246, 0.35); }
        .qual-pill[data-dept="HRS"].active i { color: #ffffff; }
        .global-filter-section { background: white; border-radius: 20px; padding: 28px 32px; margin-bottom: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
        .global-filter-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .global-filter-header h2 { color: #1a1c2c; font-size: 1.3rem; display: flex; align-items: center; gap: 12px; }
        .global-filter-header h2 i { color: #8b5cf6; font-size: 1.3rem; }
        .header-action-group { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .btn-add-global { background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: white; border: none; padding: 14px 28px; border-radius: 30px; font-weight: 600; font-size: 1rem; cursor: pointer; display: inline-flex; align-items: center; gap: 10px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 5px 15px rgba(139,92,246,0.3); font-family: 'Poppins', sans-serif; }
        .btn-add-global:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(139,92,246,0.4); }
        .filter-row { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .filter-select, .filter-input { padding: 14px 18px; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 1rem; min-width: 170px; background: white; cursor: pointer; transition: all 0.3s ease; flex: 1; font-family: 'Poppins', sans-serif; }
        .filter-select:focus, .filter-input:focus { outline: none; border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139,92,246,0.1); }
        .filter-input { flex: 2; min-width: 220px; }
        .btn-reset-filters { padding: 14px 28px; background: #e2e8f0; color: #1a1c2c; border: none; border-radius: 12px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 10px; white-space: nowrap; font-size: 1rem; font-family: 'Poppins', sans-serif; }
        .btn-reset-filters:hover { background: #cbd5e1; transform: translateY(-2px); }
        .search-stats { margin-top: 15px; color: #64748b; font-size: 0.9rem; display: flex; justify-content: space-between; align-items: center; padding-top: 15px; border-top: 1px solid #e2e8f0; }
        #traineesContainer { min-height: 200px; transition: opacity 0.3s ease; }
        #traineesContainer .department-section { background: white; border-radius: 20px; margin-bottom: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); overflow: hidden; }
        #traineesContainer .department-section:hover { border-color: #cbd5e1; box-shadow: 0 15px 40px rgba(0,0,0,0.12); }
        .department-header { padding: 24px 32px; border-bottom: 3px solid; display: flex; align-items: center; gap: 18px; background: #f8fafc; }
        .department-icon { width: 56px; height: 56px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; color: white; }
        .department-info { flex: 1; }
        .department-info h3 { font-size: 1.5rem; font-weight: 600; color: #1a1c2c; }
        .department-info p { color: #64748b; font-size: 0.95rem; }
        .dept-badge { background: rgba(0,0,0,0.05); padding: 4px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 500; color: #64748b; margin-left: 12px; }
        .department-actions { display: flex; align-items: center; gap: 15px; }
        .btn-add-trainee-dept { background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; padding: 10px 22px; border-radius: 30px; font-weight: 600; font-size: 0.9rem; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 5px 15px rgba(16,185,129,0.2); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); font-family: 'Poppins', sans-serif; }
        .btn-add-trainee-dept:hover { background: linear-gradient(135deg, #059669, #047857); transform: translateY(-2px); box-shadow: 0 8px 20px rgba(16,185,129,0.3); }
        .trainee-section { padding: 20px 32px; background: white; display: block; }
        .section-tabs { display: flex; gap: 10px; margin-bottom: 20px; }
        .section-tab { padding: 8px 20px; border-radius: 30px; font-weight: 600; font-size: 0.9rem; cursor: pointer; background: white; border: 2px solid #e2e8f0; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); font-family: 'Poppins', sans-serif; }
        .section-tab.active { background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: white; border-color: #8b5cf6; }
        .section-tab:hover { border-color: #8b5cf6; transform: translateY(-1px); }
        .section-content { display: block; }
        .section-content.hidden { display: none; }
        .qualification-group { margin-bottom: 20px; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; }
        .qualification-group:last-child { margin-bottom: 0; }
        .qualification-header { padding: 16px 20px; background: #f8fafc; cursor: pointer; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; }
        .qual-header-left { display: flex; align-items: center; gap: 12px; }
        .toggle-icon { transition: transform 0.3s ease; font-size: 0.9rem; color: #64748b; }
        .qualification-group.expanded .toggle-icon { transform: rotate(90deg); }
        .qual-icon { color: #8b5cf6; font-size: 1rem; }
        .qual-name { font-weight: 600; font-size: 1rem; color: #1a1c2c; }
        .qual-code { font-size: 0.85rem; color: #64748b; font-weight: 400; }
        .qual-count { font-size: 0.75rem; color: #64748b; background: #e2e8f0; padding: 2px 12px; border-radius: 20px; font-weight: 500; }
        .qual-header-right { display: flex; gap: 12px; }
        .gender-badge { font-size: 0.75rem; padding: 2px 12px; border-radius: 20px; font-weight: 500; }
        .male-badge { background: #dbeafe; color: #1e40af; }
        .female-badge { background: #fce7f3; color: #be185d; }
        .qualification-body { padding: 0; max-height: 0; overflow: hidden; transition: max-height 0.4s ease, padding 0.3s ease; }
        .qualification-group.expanded .qualification-body { max-height: 5000px; padding: 20px; }
        .gender-section { margin-bottom: 25px; }
        .gender-section:last-child { margin-bottom: 0; }
        .gender-header { padding: 12px 18px; border-radius: 10px; margin-bottom: 15px; font-weight: 600; font-size: 0.95rem; display: inline-flex; align-items: center; gap: 10px; background: #f8fafc; border-left: 4px solid; }
        .gender-header.male { border-left-color: #3b82f6; color: #1e40af; }
        .gender-header.female { border-left-color: #ec489a; color: #be185d; }
        .gender-header i { font-size: 1rem; }
        .gender-count { background: #e2e8f0; padding: 2px 10px; border-radius: 20px; font-size: 0.75rem; margin-left: 5px; }
        .table-responsive { overflow-x: auto; border-radius: 12px; }
        .trainees-table { width: 100%; border-collapse: collapse; min-width: 1050px; table-layout: auto; }
        .trainees-table th { text-align: left; padding: 14px 16px; background: #f8fafc; color: #64748b; font-weight: 600; font-size: 0.85rem; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
        .trainees-table td { padding: 14px 16px; border-bottom: 1px solid #e2e8f0; font-size: 0.95rem; vertical-align: middle; }
        .trainees-table tbody tr { transition: all 0.2s ease; }
        .trainees-table tbody tr:hover { background: #f8fafc; }
        .trainees-table th:nth-child(1), .trainees-table td:nth-child(1) { width: 180px; }
        .trainees-table th:nth-child(2), .trainees-table td:nth-child(2) { width: 200px; }
        .trainees-table th:nth-child(3), .trainees-table td:nth-child(3) { min-width: 130px; }
        .trainees-table th:nth-child(4), .trainees-table td:nth-child(4) { min-width: 200px; }
        .trainees-table th:nth-child(5), .trainees-table td:nth-child(5) { width: 140px; white-space: nowrap; }
        .trainees-table th:nth-child(6), .trainees-table td:nth-child(6) { width: 190px; white-space: nowrap; text-align: center; }
        .action-buttons { display: flex; gap: 6px; white-space: nowrap; justify-content: center; }
        .btn-view, .btn-edit, .btn-delete, .btn-complete { border: none; width: 34px; height: 34px; border-radius: 8px; cursor: pointer; font-size: 0.85rem; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); color: white; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .btn-view { background: #3b82f6; }
        .btn-view:hover { background: #2563eb; transform: translateY(-2px); }
        .btn-edit { background: #f59e0b; }
        .btn-edit:hover { background: #d97706; transform: translateY(-2px); }
        .btn-complete { background: linear-gradient(135deg, #10b981, #059669); box-shadow: 0 3px 10px rgba(16, 185, 129, 0.3); }
        .btn-complete:hover { background: linear-gradient(135deg, #059669, #047857); transform: translateY(-2px); box-shadow: 0 6px 15px rgba(16, 185, 129, 0.4); }
        .btn-delete { background: #ef4444; }
        .btn-delete:hover { background: #dc2626; transform: translateY(-2px); }
        .empty-state { text-align: center; padding: 60px 20px; color: #64748b; }
        .empty-state i { font-size: 4rem; color: #cbd5e1; margin-bottom: 20px; }
        .empty-state h3 { font-size: 1.3rem; color: #1e293b; margin-bottom: 8px; }
        .empty-state p { font-size: 1rem; }
        .floating-message { position: fixed; top: 20px; right: 20px; background: #10b981; color: white; padding: 16px 28px; border-radius: 12px; box-shadow: 0 5px 20px rgba(16,185,129,0.3); z-index: 9999; display: flex; align-items: center; gap: 12px; font-size: 1rem; font-weight: 500; }
        .floating-message.error { background: #ef4444; box-shadow: 0 5px 20px rgba(239,68,68,0.3); }
        body .modal-custom-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; width: 100vw; height: 100vh; background: rgba(0, 0, 0, 0.75) !important; backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); z-index: 99999; align-items: center !important; justify-content: center !important; overflow-y: auto; padding: 20px; }
        body .modal-custom-overlay.active { display: flex !important; }
        body .modal-custom-overlay .modal-card { background: #ffffff !important; margin: auto !important; flex-shrink: 0; }
        .modal-card { background: #ffffff; border-radius: 16px; padding: 32px 36px; width: 90%; max-width: 800px; max-height: 85vh; overflow-y: auto; box-shadow: 0 25px 60px rgba(0, 0, 0, 0.3); animation: slideUp 0.35s ease-out; position: relative; margin: auto; }
        .modal-card::-webkit-scrollbar { width: 6px; }
        .modal-card::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 10px; }
        .modal-card::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .modal-card::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        @keyframes slideUp { from { transform: translateY(-30px) scale(0.95); opacity: 0; } to { transform: translateY(0) scale(1); opacity: 1; } }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 14px; border-bottom: 2px solid #e2e8f0; }
        .modal-header h3 { font-size: 1.25rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 10px; margin: 0; }
        .modal-header h3 i { color: #8b5cf6; font-size: 1.1rem; }
        .modal-close { background: none; border: none; font-size: 1.8rem; cursor: pointer; color: #94a3b8; transition: all 0.3s ease; padding: 0 6px; line-height: 1; }
        .modal-close:hover { color: #ef4444; transform: rotate(90deg); }
        .view-avatar-large { width: 100px; height: 100px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; color: white; font-weight: 700; flex-shrink: 0; box-shadow: 0 8px 20px rgba(0,0,0,0.15); }
        .view-section { margin-bottom: 18px; }
        .view-section:last-child { margin-bottom: 0; }
        .view-section .section-label { font-size: 0.7rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; margin-bottom: 4px; }
        .view-section .section-value { font-size: 0.95rem; color: #0f172a; font-weight: 500; padding: 6px 0; border-bottom: 1px solid #f1f5f9; }
        .view-section .section-value:last-child { border-bottom: none; }
        .view-badge { display: inline-block; padding: 4px 14px; border-radius: 30px; font-size: 0.85rem; font-weight: 600; margin-right: 6px; }
        .view-badge.active { background: #d1fae5; color: #065f46; }
        .view-badge.pending { background: #fef3c7; color: #92400e; }
        .view-badge.inactive { background: #fee2e2; color: #991b1b; }
        .modal-footer { display: flex; gap: 12px; margin-top: 20px; padding-top: 16px; border-top: 1px solid #e2e8f0; }
        .modal-footer button { padding: 12px 28px; border: none; border-radius: 10px; font-weight: 600; font-size: 0.9rem; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 8px; font-family: 'Poppins', sans-serif; }
        .modal-footer .btn-primary-modal { background: linear-gradient(135deg, #8b5cf6, #7c3aed); color: white; flex: 1; }
        .modal-footer .btn-primary-modal:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(139,92,246,0.3); }
        .modal-footer .btn-secondary-modal { background: #f1f5f9; color: #475569; border: 2px solid #e2e8f0; }
        .modal-footer .btn-secondary-modal:hover { background: #e2e8f0; transform: translateY(-2px); }
        .modal-footer .btn-success-modal { background: linear-gradient(135deg, #10b981, #059669); color: white; flex: 1; }
        .modal-footer .btn-success-modal:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(16,185,129,0.3); }
        .delete-modal .modal-card { max-width: 400px; text-align: center; padding: 28px 32px; }
        .delete-modal .modal-title { font-size: 1.1rem; font-weight: 600; color: #0f172a; margin-bottom: 8px; }
        .delete-modal .modal-question { color: #475569; font-size: 0.95rem; margin-bottom: 4px; }
        .delete-modal .modal-trainee-name { font-weight: 600; color: #0f172a; font-size: 1rem; background: #f1f5f9; padding: 4px 16px; border-radius: 6px; display: inline-block; margin: 6px 0 4px 0; }
        .delete-modal .modal-footer { justify-content: center; border-top: none; padding-top: 0; margin-top: 14px; gap: 10px; }
        .delete-modal .modal-footer .btn-cancel-delete { background: #f1f5f9; color: #475569; border: 2px solid #e2e8f0; padding: 8px 28px; border-radius: 8px; font-weight: 600; font-size: 0.9rem; cursor: pointer; transition: all 0.3s ease; font-family: 'Poppins', sans-serif; min-width: 100px; }
        .delete-modal .modal-footer .btn-cancel-delete:hover { background: #e2e8f0; }
        .delete-modal .modal-footer .btn-confirm-delete { background: #ef4444; color: white; border: none; padding: 8px 28px; border-radius: 8px; font-weight: 600; font-size: 0.9rem; cursor: pointer; transition: all 0.3s ease; font-family: 'Poppins', sans-serif; min-width: 100px; }
        .delete-modal .modal-footer .btn-confirm-delete:hover { background: #dc2626; box-shadow: 0 5px 15px rgba(239,68,68,0.3); }
        .delete-modal .modal-footer .btn-confirm-delete:disabled { opacity: 0.7; cursor: not-allowed; transform: none !important; }
        .complete-modal .modal-card { max-width: 440px; text-align: center; padding: 28px 32px; }
        .complete-modal-icon { width: 64px; height: 64px; margin: 0 auto 16px; border-radius: 50%; background: linear-gradient(135deg, #d1fae5, #a7f3d0); display: flex; align-items: center; justify-content: center; font-size: 1.7rem; color: #059669; box-shadow: 0 6px 20px rgba(16, 185, 129, 0.2); }
        .complete-modal-title { font-size: 1.1rem; font-weight: 600; color: #0f172a; margin-bottom: 8px; }
        .complete-modal-question { color: #475569; font-size: 0.95rem; margin-bottom: 4px; }
        .complete-modal-trainee-name { font-weight: 600; color: #0f172a; font-size: 1rem; background: #d1fae5; padding: 4px 16px; border-radius: 6px; display: inline-block; margin: 6px 0; }
        .complete-modal-warning { background: #f0fdf4; border-left: 4px solid #10b981; padding: 10px 14px; border-radius: 8px; font-size: 0.8rem; color: #065f46; text-align: left; margin: 14px 0; display: flex; align-items: flex-start; gap: 8px; }
        .complete-modal-warning i { color: #10b981; font-size: 1rem; margin-top: 1px; flex-shrink: 0; }
        .complete-modal .modal-footer { justify-content: center; border-top: none; padding-top: 0; margin-top: 14px; gap: 10px; }
        .btn-confirm-complete { background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; padding: 8px 28px; border-radius: 8px; font-weight: 600; font-size: 0.9rem; cursor: pointer; transition: all 0.3s ease; font-family: 'Poppins', sans-serif; min-width: 100px; }
        .btn-confirm-complete:hover { background: linear-gradient(135deg, #059669, #047857); transform: translateY(-2px); box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3); }
        .btn-confirm-complete:disabled { opacity: 0.7; cursor: not-allowed; transform: none !important; }
        .modal-form .form-group { margin-bottom: 14px; }
        .modal-form .form-group label { display: block; margin-bottom: 4px; color: #475569; font-weight: 600; font-size: 0.85rem; letter-spacing: 0.3px; }
        .modal-form .form-group label .required { color: #ef4444; margin-left: 2px; }
        .modal-form .form-group input, .modal-form .form-group select, .modal-form .form-group textarea { width: 100%; padding: 10px 14px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 0.9rem; transition: all 0.2s; background: white; color: #1e293b; height: 42px; font-family: 'Poppins', sans-serif; }
        .modal-form .form-group textarea { height: auto; min-height: 70px; resize: vertical; line-height: 1.4; }
        .modal-form .form-group input:focus, .modal-form .form-group select:focus, .modal-form .form-group textarea:focus { outline: none; border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139,92,246,0.1); }
        .modal-form .form-group input:read-only { background: #f0f9ff; cursor: not-allowed; }
        .modal-form .form-group input.ref-valid { border-color: #10b981 !important; background: #f0fdf4; }
        .modal-form .form-group input.ref-invalid { border-color: #ef4444 !important; background: #fef2f2; }
        .ref-feedback { font-size: 0.72rem; margin-top: 4px; font-weight: 500; display: none; }
        .ref-feedback.show { display: block; }
        .ref-feedback.valid { color: #059669; }
        .ref-feedback.invalid { color: #dc2626; }
        .modal-form .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .modal-form .form-row-3 { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 14px; }
        .modal-form .help-text { font-size: 0.7rem; color: #64748b; margin-top: 3px; }
        .view-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 24px; }
        .info-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; padding: 12px 16px; margin-bottom: 18px; display: flex; align-items: flex-start; gap: 10px; }
        .info-box i { color: #10b981; margin-top: 2px; }
        .info-box p { color: #065f46; font-size: 0.85rem; margin: 0; }
        .ref-id-display { display: inline-flex; align-items: center; gap: 8px; font-family: 'Poppins', sans-serif; font-size: 0.95rem; font-weight: 600; color: #000000; background: #F1F5F9; padding: 6px 16px; border-radius: 20px; border: 1px solid #E2E8F0; letter-spacing: 0.3px; }
        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } .main-content { margin-left: 80px; width: calc(100% - 80px); } body { min-width: 992px; } .filter-row { flex-wrap: wrap; } }
        @media (max-width: 768px) { .stats-grid { grid-template-columns: 1fr; } .header { flex-direction: column; align-items: flex-start; gap: 15px; } .header-right { width: 100%; flex-wrap: wrap; } .main-content { margin-left: 0; width: 100%; padding: 15px; } .modal-card { padding: 20px; } .modal-form .form-row, .modal-form .form-row-3 { grid-template-columns: 1fr; } .view-grid { grid-template-columns: 1fr; } .modal-footer { flex-direction: column; } .modal-footer button { width: 100%; justify-content: center; } .department-header { flex-wrap: wrap; } .department-actions { width: 100%; } .section-tabs { flex-wrap: wrap; } .profile-badge { padding: 6px 12px 6px 6px; } .profile-badge .profile-name { font-size: 0.85rem; } .header-action-group { width: 100%; flex-direction: column; align-items: stretch; } .header-action-group button { width: 100%; justify-content: center; } }
    </style>
    <link rel="stylesheet" href="/tesda_compliance/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="/tesda_compliance/assets/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div id="floatingMessage" style="display: none;"></div>
    
    <div class="main-content">
        <div class="header">
            <div class="header-left">
                <h1><i class="fas fa-users"></i> Trainee Management</h1>
                <p>Manage and track all trainee information</p>
            </div>
            <div class="header-right">
                <div class="profile-badge">
                    <div class="avatar-circle"><i class="fas fa-user"></i></div>
                    <span class="profile-name"><?= htmlspecialchars($user['full_name'] ?? $user['username']) ?></span>
                </div>
                <div class="date-badge">
                    <i class="far fa-calendar-alt"></i> <?= date('F j, Y') ?>
                </div>
            </div>
        </div>
        
        <div class="global-filter-section">
            <div class="global-filter-header">
                <h2><i class="fas fa-search"></i> Search & Filter Trainees</h2>
                <div class="header-action-group">
                    <button class="btn-add-global" onclick="openAddModal()">
                        <i class="fas fa-plus-circle"></i> Add New Trainee
                    </button>
                </div>
            </div>
            
            <div class="filter-row">
                <?php if ($is_coordinator_role): ?>
                <select class="filter-select" id="departmentSelect">
                    <option value="">All Departments</option>
                    <?php foreach ($courses as $course): ?>
                    <option value="<?= $course['id'] ?>"><?= htmlspecialchars($course['course_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                
                <select class="filter-select" id="sectionSelect">
                    <option value="">All Sections</option>
                    <option value="A">Section A</option>
                    <option value="B">Section B</option>
                </select>
                
                <input type="text" class="filter-input" id="searchInput" placeholder="Search by name, email, contact, or Reference ID...">
                
                <button class="btn-reset-filters" id="resetFiltersBtn">
                    <i class="fas fa-undo-alt"></i> Reset
                </button>
            </div>
            
            <div class="search-stats">
                <span>
                    <i class="fas fa-users"></i> 
                    Showing <span id="visibleCount"><?= $total_trainees ?></span> of <span id="totalCount"><?= $total_trainees ?></span> trainees
                </span>
            </div>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon-wrapper" style="background: #1a1c2c;">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-content">
                    <h3 id="totalTraineesCount" data-prev-num="<?= (int)$total_trainees ?>"><?= $total_trainees ?></h3>
                    <p>Total Trainees</p>
                </div>
            </div>
            
            <?php foreach ($department_counts as $dept): 
                $icon = 'fa-building'; $bg_color = '#667eea'; $dept_label = '';
                if ($dept['course_code'] == 'HRS') { $icon = 'fa-hotel'; $bg_color = '#8b5cf6'; $dept_label = 'HRS'; }
                elseif ($dept['course_code'] == 'CSS') { $icon = 'fa-laptop-code'; $bg_color = '#10b981'; $dept_label = 'CSS'; }
                elseif ($dept['course_code'] == 'ATS') { $icon = 'fa-cogs'; $bg_color = '#f59e0b'; $dept_label = 'ATS'; }
            ?>
            <div class="stat-card">
                <div class="stat-icon-wrapper" style="background: <?= $bg_color ?>;">
                    <i class="fas <?= $icon ?>"></i>
                </div>
                <div class="stat-content">
                    <h3 class="dept-count" id="deptCount-<?= $dept['course_code'] ?>" data-dept="<?= $dept['course_code'] ?>" data-prev-num="<?= (int)$dept['trainee_count'] ?>"><?= $dept['trainee_count'] ?></h3>
                    <p><?= $dept_label ?> Trainees</p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($is_coordinator_role && !empty($qualification_pills)): ?>
        <div class="qual-pills-wrapper" id="qualPillsWrapper">
            <?php foreach ($qualification_pills as $pill): 
                $dept_tag = !empty($pill['dept_code']) ? $pill['dept_code'] : 'HRS';
            ?>
            <button type="button" 
                    class="qual-pill" 
                    data-qualification="<?= htmlspecialchars($pill['qualification_code']) ?>"
                    data-dept="<?= htmlspecialchars($dept_tag) ?>"
                    title="<?= htmlspecialchars($pill['qualification_name']) ?>"
                    onclick="setQualificationFilter('<?= htmlspecialchars($pill['qualification_code']) ?>', this)">
                <i class="fas fa-folder"></i> <?= htmlspecialchars($pill['qualification_code']) ?>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <div id="traineesContainer"></div>
    </div>

    <!-- VIEW MODAL -->
    <div id="viewTraineeModal" class="modal-custom-overlay">
        <div class="modal-card" id="viewModalCard">
            <div class="modal-header">
                <h3><i class="fas fa-user-graduate"></i> Trainee Profile</h3>
                <button class="modal-close" onclick="closeAllModals()">&times;</button>
            </div>
            <div id="viewModalBody">
                <div style="text-align:center;padding:40px;">
                    <i class="fas fa-spinner fa-spin" style="font-size:1.8rem;color:#8b5cf6;"></i>
                    <p style="margin-top:12px;color:#64748b;">Loading trainee data...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn-primary-modal" id="viewEditBtn" onclick="openEditFromView()"><i class="fas fa-edit"></i> Edit Trainee</button>
                <button class="btn-secondary-modal" onclick="closeAllModals()">Close</button>
            </div>
        </div>
    </div>

    <!-- ADD MODAL -->
    <div id="addTraineeModal" class="modal-custom-overlay">
        <div class="modal-card">
            <div class="modal-header">
                <h3><i class="fas fa-user-plus" style="color: #10b981;"></i> Add New Trainee</h3>
                <button class="modal-close" onclick="closeAllModals()">&times;</button>
            </div>
            <div class="modal-form">
                <div class="info-box">
                    <i class="fas fa-info-circle"></i>
                    <p>Enter a unique <strong>Reference ID</strong> for the trainee. Duplicates are not allowed.</p>
                </div>
                
                <form id="addTraineeForm" class="modal-form">
                    <input type="hidden" name="ajax_action" value="add_trainee">
                    
                    <div class="form-group">
                        <label>Reference ID <span class="required">*</span></label>
                        <input type="text" name="reference_id" id="add_reference_id" placeholder="e.g., TESDA-000010-122B" maxlength="50" required autocomplete="off">
                        <div class="help-text">Type the Reference ID manually.</div>
                        <div class="ref-feedback" id="addRefFeedback"></div>
                    </div>

                    <div class="form-row-3">
                        <div class="form-group">
                            <label>First Name <span class="required">*</span></label>
                            <input type="text" name="first_name" id="add_first_name" required>
                        </div>
                        <div class="form-group">
                            <label>M.I.</label>
                            <input type="text" name="middle_initial" id="add_middle_initial" maxlength="5">
                        </div>
                        <div class="form-group">
                            <label>Suffix</label>
                            <select name="suffix" id="add_suffix">
                                <option value="">None</option>
                                <option value="Jr.">Jr.</option>
                                <option value="Sr.">Sr.</option>
                                <option value="II">II</option>
                                <option value="III">III</option>
                                <option value="IV">IV</option>
                                <option value="V">V</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Last Name <span class="required">*</span></label>
                        <input type="text" name="last_name" id="add_last_name" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Birthdate</label>
                            <input type="date" name="birthdate" id="add_birthdate" max="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="form-group">
                            <label>Sex <span class="required">*</span></label>
                            <select name="sex" id="add_sex" required>
                                <option value="">Select Sex</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Address</label>
                        <textarea name="address" id="add_address" rows="2"></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Section <span class="required">*</span></label>
                            <select name="section" id="add_section" required>
                                <option value="">Select Section</option>
                                <option value="A">Section A</option>
                                <option value="B">Section B</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Contact Number</label>
                            <input type="text" name="contact" id="add_contact">
                        </div>
                        <div class="form-group">
                            <label>Email Address</label>
                            <input type="email" name="email" id="add_email">
                        </div>
                    </div>
                    
                    <?php if ($is_coordinator_role): ?>
                    <div class="form-group">
                        <label>Department <span class="required">*</span></label>
                        <select name="course_id" id="add_course_id" required onchange="loadQualificationsForAdd()">
                            <option value="">-- Select Department --</option>
                            <?php foreach ($courses as $course): ?>
                            <option value="<?= $course['id'] ?>"><?= htmlspecialchars($course['course_name']) ?> (<?= $course['course_code'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Qualification</label>
                        <select name="qualification_id" id="add_qualification_id">
                            <option value="">Select Department first</option>
                        </select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="course_id" id="add_course_id" value="<?= (int)$trainer_course_id ?>">
                    <div class="form-group">
                        <label>Department</label>
                        <input type="text" value="<?= htmlspecialchars($trainer_dept_display) ?>" readonly style="background:#f0f9ff;">
                    </div>
                    <div class="form-group">
                        <label>Qualification</label>
                        <select name="qualification_id" id="add_qualification_id">
                            <option value="">Select Qualification</option>
                            <?php foreach ($all_qualifications as $qual): ?>
                            <option value="<?= $qual['id'] ?>"><?= htmlspecialchars($qual['qualification_name']) ?> (<?= htmlspecialchars($qual['qualification_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn-success-modal" onclick="submitAddForm()"><i class="fas fa-save"></i> Add Trainee</button>
                        <button type="button" class="btn-secondary-modal" onclick="closeAllModals()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- EDIT MODAL -->
    <div id="editTraineeModal" class="modal-custom-overlay">
        <div class="modal-card">
            <div class="modal-header">
                <h3><i class="fas fa-user-edit" style="color: #f59e0b;"></i> Edit Trainee</h3>
                <button class="modal-close" onclick="closeAllModals()">&times;</button>
            </div>
            <div class="modal-form">
                <div class="info-box" id="editRefIdBox" style="display:none;">
                    <i class="fas fa-id-card"></i>
                    <p>Reference ID: <strong id="editRefIdValue">—</strong> <span style="color:#059669;">(cannot be changed)</span></p>
                </div>
                
                <form id="editTraineeForm" class="modal-form">
                    <input type="hidden" name="ajax_action" value="edit_trainee">
                    <input type="hidden" name="id" id="edit_id">
                    
                    <div class="form-row-3">
                        <div class="form-group"><label>First Name <span class="required">*</span></label><input type="text" name="first_name" id="edit_first_name" required></div>
                        <div class="form-group"><label>M.I.</label><input type="text" name="middle_initial" id="edit_middle_initial" maxlength="5"></div>
                        <div class="form-group"><label>Suffix</label>
                            <select name="suffix" id="edit_suffix">
                                <option value="">None</option>
                                <option value="Jr.">Jr.</option>
                                <option value="Sr.">Sr.</option>
                                <option value="II">II</option>
                                <option value="III">III</option>
                                <option value="IV">IV</option>
                                <option value="V">V</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group"><label>Last Name <span class="required">*</span></label><input type="text" name="last_name" id="edit_last_name" required></div>

                    <div class="form-row">
                        <div class="form-group"><label>Birthdate</label><input type="date" name="birthdate" id="edit_birthdate" max="<?= date('Y-m-d') ?>"></div>
                        <div class="form-group"><label>Sex <span class="required">*</span></label>
                            <select name="sex" id="edit_sex" required>
                                <option value="">Select Sex</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group"><label>Address</label><textarea name="address" id="edit_address" rows="2"></textarea></div>
                    
                    <div class="form-row">
                        <div class="form-group"><label>Section <span class="required">*</span></label>
                            <select name="section" id="edit_section" required>
                                <option value="">Select Section</option>
                                <option value="A">Section A</option>
                                <option value="B">Section B</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group"><label>Contact Number</label><input type="text" name="contact" id="edit_contact"></div>
                        <div class="form-group"><label>Email Address</label><input type="email" name="email" id="edit_email"></div>
                    </div>
                    
                    <?php if ($is_coordinator_role): ?>
                    <div class="form-group">
                        <label>Department <span class="required">*</span></label>
                        <select name="course_id" id="edit_course_id" required onchange="loadQualificationsForEdit()">
                            <option value="">Select Department</option>
                            <?php foreach ($courses as $course): ?>
                            <option value="<?= $course['id'] ?>"><?= htmlspecialchars($course['course_name']) ?> (<?= $course['course_code'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Qualification</label>
                        <select name="qualification_id" id="edit_qualification_id"><option value="">Select Department first</option></select>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="course_id" id="edit_course_id" value="<?= (int)$trainer_course_id ?>">
                    <div class="form-group">
                        <label>Department</label>
                        <input type="text" id="edit_department_display" readonly value="<?= htmlspecialchars($trainer_dept_display) ?>" style="background:#f0f9ff;">
                    </div>
                    <div class="form-group">
                        <label>Qualification</label>
                        <select name="qualification_id" id="edit_qualification_id">
                            <option value="">Select Qualification</option>
                            <?php foreach ($all_qualifications as $qual): ?>
                            <option value="<?= $qual['id'] ?>"><?= htmlspecialchars($qual['qualification_name']) ?> (<?= htmlspecialchars($qual['qualification_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn-primary-modal" onclick="submitEditForm()"><i class="fas fa-save"></i> Update Trainee</button>
                        <button type="button" class="btn-secondary-modal" onclick="closeAllModals()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- DELETE MODAL -->
    <div id="deleteTraineeModal" class="modal-custom-overlay delete-modal">
        <div class="modal-card">
            <div class="modal-title">Delete Trainee</div>
            <div class="modal-question">Are you sure you want to delete this trainee?</div>
            <div class="modal-trainee-name" id="deleteTraineeName">Loading...</div>
            <div class="modal-footer">
                <button class="btn-cancel-delete" onclick="closeAllModals()">Cancel</button>
                <button class="btn-confirm-delete" id="confirmDeleteBtn" onclick="confirmDelete()">Yes</button>
            </div>
        </div>
    </div>

    <!-- COMPLETE MODAL -->
    <div id="completeTraineeModal" class="modal-custom-overlay complete-modal">
        <div class="modal-card">
            <div class="complete-modal-icon"><i class="fas fa-check-circle"></i></div>
            <div class="complete-modal-title">Mark as Completed</div>
            <div class="complete-modal-question">Are you sure you want to mark</div>
            <div class="complete-modal-trainee-name" id="completeTraineeName">Loading...</div>
            <div class="complete-modal-question">as Completed?</div>
            <div class="complete-modal-warning">
                <i class="fas fa-info-circle"></i>
                <span>They will be moved to the Completed Archive and removed from the Active list.</span>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel-delete" onclick="closeAllModals()">Cancel</button>
                <button class="btn-confirm-complete" id="confirmCompleteBtn" onclick="confirmComplete()">Yes, Mark as Completed</button>
            </div>
        </div>
    </div>

    <form id="deleteForm" method="POST" style="display:none;">
        <input type="hidden" name="delete_trainee" value="1">
        <input type="hidden" name="id" id="delete_id">
    </form>

    <script src="/tesda_compliance/assets/js/jquery-3.6.0.min.js"></script>
    <script>
        let currentActiveSection = 'A';
        let filterTimeout;
        let statsRefreshInterval;
        let isUpdatingStats = false;
        let isFiltering = false;
        let currentViewTraineeId = null;
        let deleteTraineeId = null;
        let deleteTraineeName = '';
        let completeTraineeId = null;
        let activeQualification = '';

        function setQualificationFilter(qualCode, btnEl) {
            activeQualification = qualCode || '';
            document.querySelectorAll('.qual-pill').forEach(p => p.classList.remove('active'));
            if (btnEl) btnEl.classList.add('active');
            else {
                const match = document.querySelector('.qual-pill[data-qualification="' + activeQualification + '"]');
                if (match) match.classList.add('active');
            }
            loadTrainees();
        }

        (function() {
            const refInput = document.getElementById('add_reference_id');
            const refFeedback = document.getElementById('addRefFeedback');
            let debounceTimer = null;
            let lastChecked = '';
            if (!refInput) return;

            function setFeedback(message, isValid) {
                refFeedback.textContent = message;
                refFeedback.className = 'ref-feedback show ' + (isValid ? 'valid' : 'invalid');
                refInput.classList.remove('ref-valid', 'ref-invalid');
                refInput.classList.add(isValid ? 'ref-valid' : 'ref-invalid');
            }
            function clearFeedback() {
                refFeedback.className = 'ref-feedback';
                refInput.classList.remove('ref-valid', 'ref-invalid');
            }

            refInput.addEventListener('input', function() {
                const value = refInput.value.trim();
                if (value === '') { clearFeedback(); return; }
                if (value === lastChecked) return;
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function() {
                    lastChecked = value;
                    fetch('trainees.php?ajax=check_reference_id&reference_id=' + encodeURIComponent(value))
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (!data.success) { setFeedback('⚠ ' + (data.message || 'Unable to check.'), false); return; }
                            if (data.exists) setFeedback('✕ ' + data.message, false);
                            else setFeedback('✓ ' + data.message, true);
                        })
                        .catch(function() { setFeedback('⚠ Could not verify.', false); });
                }, 450);
            });
        })();

        function openCompleteModal(id, name) {
            completeTraineeId = id;
            document.getElementById('completeTraineeName').textContent = name;
            document.getElementById('completeTraineeModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function confirmComplete() {
            if (!completeTraineeId) return;
            const btn = document.getElementById('confirmCompleteBtn');
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Marking...';
            const formData = new FormData();
            formData.append('action', 'mark_completed');
            formData.append('id', completeTraineeId);
            fetch('trainees.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) { showFloatingMessage(data.message, 'success'); closeAllModals(); loadTrainees(); }
                else { showFloatingMessage(data.message || 'Failed', 'error'); btn.disabled = false; btn.innerHTML = originalText; }
            })
            .catch(error => { showFloatingMessage('Error', 'error'); btn.disabled = false; btn.innerHTML = originalText; });
        }

        function openDeleteModal(id, name) {
            deleteTraineeId = id;
            deleteTraineeName = name;
            document.getElementById('deleteTraineeName').textContent = name;
            document.getElementById('delete_id').value = id;
            document.getElementById('deleteTraineeModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function confirmDelete() {
            const btn = document.getElementById('confirmDeleteBtn');
            btn.disabled = true;
            btn.textContent = 'Deleting...';
            const formData = new FormData();
            formData.append('delete_trainee', '1');
            formData.append('id', deleteTraineeId);
            fetch('trainees.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) { showFloatingMessage(data.message, 'success'); closeAllModals(); loadTrainees(); }
                else { showFloatingMessage(data.message, 'error'); btn.disabled = false; btn.textContent = 'Yes'; }
            })
            .catch(error => { showFloatingMessage('Error', 'error'); btn.disabled = false; btn.textContent = 'Yes'; });
        }

        function toggleQualification(headerElement) {
            const group = headerElement.closest('.qualification-group');
            if (!group) return;
            group.classList.toggle('expanded');
        }

        function loadQualificationsForAdd() {
            const courseId = document.getElementById('add_course_id').value;
            const qualSelect = document.getElementById('add_qualification_id');
            if (!courseId) { qualSelect.innerHTML = '<option value="">Select Department first</option>'; return; }
            fetch(`trainees.php?ajax=get_qualifications&course_id=${courseId}`)
                .then(response => response.json())
                .then(data => {
                    qualSelect.innerHTML = '<option value="">Select Qualification</option>';
                    if (data.success && data.data && data.data.length > 0) {
                        data.data.forEach(qual => { qualSelect.innerHTML += `<option value="${qual.id}">${qual.qualification_name} (${qual.qualification_code})</option>`; });
                    } else { qualSelect.innerHTML = '<option value="">No qualifications available</option>'; }
                })
                .catch(() => { qualSelect.innerHTML = '<option value="">Error loading</option>'; });
        }

        function loadQualificationsForEdit() {
            const courseId = document.getElementById('edit_course_id').value;
            const qualSelect = document.getElementById('edit_qualification_id');
            const currentQualId = qualSelect.getAttribute('data-current') || '';
            if (!courseId) { qualSelect.innerHTML = '<option value="">Select Department first</option>'; return; }
            fetch(`trainees.php?ajax=get_qualifications&course_id=${courseId}`)
                .then(response => response.json())
                .then(data => {
                    qualSelect.innerHTML = '<option value="">Select Qualification</option>';
                    if (data.success && data.data && data.data.length > 0) {
                        data.data.forEach(qual => {
                            const selected = qual.id == currentQualId ? 'selected' : '';
                            qualSelect.innerHTML += `<option value="${qual.id}" ${selected}>${qual.qualification_name} (${qual.qualification_code})</option>`;
                        });
                    } else { qualSelect.innerHTML = '<option value="">No qualifications available</option>'; }
                })
                .catch(() => { qualSelect.innerHTML = '<option value="">Error loading</option>'; });
        }

        function showFloatingMessage(message, type = 'success') {
            const msgDiv = document.getElementById('floatingMessage');
            msgDiv.className = `floating-message ${type}`;
            msgDiv.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${message}`;
            msgDiv.style.display = 'flex';
            setTimeout(() => { msgDiv.style.display = 'none'; }, 5000);
        }

        // ==========================================
        // Silently update stat cards (no color change, no layout shift)
        // ==========================================
        function updateStatCards(data) {
            const totalEl = document.getElementById('totalTraineesCount');
            if (totalEl && data.total_all !== undefined) {
                const newNum = parseInt(data.total_all, 10) || 0;
                const oldNum = parseInt(totalEl.getAttribute('data-prev-num') || 'NaN', 10);
                if (isNaN(oldNum) || oldNum !== newNum) {
                    totalEl.textContent = newNum;
                    totalEl.setAttribute('data-prev-num', String(newNum));
                }
            }

            if (data.dept_counts) {
                ['HRS', 'CSS', 'ATS'].forEach(code => {
                    const el = document.getElementById('deptCount-' + code);
                    if (el && data.dept_counts[code] !== undefined) {
                        const newNum = parseInt(data.dept_counts[code], 10) || 0;
                        const oldNum = parseInt(el.getAttribute('data-prev-num') || 'NaN', 10);
                        if (isNaN(oldNum) || oldNum !== newNum) {
                            el.textContent = newNum;
                            el.setAttribute('data-prev-num', String(newNum));
                        }
                    }
                });
            }
        }

        function loadTrainees() {
            if (isFiltering) return;
            isFiltering = true;
            const container = document.getElementById('traineesContainer');
            container.style.opacity = '0.6';
            const search = document.getElementById('searchInput')?.value || '';
            const department = document.getElementById('departmentSelect')?.value || '';
            const section = document.getElementById('sectionSelect')?.value || '';
            const qualParam = (activeQualification && activeQualification !== '') ? encodeURIComponent(activeQualification) : '';
            fetch(`trainees.php?ajax=filter_trainees&search=${encodeURIComponent(search)}&department=${encodeURIComponent(department)}&section=${encodeURIComponent(section)}&qualification=${qualParam}&set_section=${currentActiveSection}&t=${Date.now()}`)
                .then(response => {
                    container.style.opacity = '1';
                    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        container.innerHTML = data.html;
                        const visibleCountEl = document.getElementById('visibleCount');
                        const totalCountEl = document.getElementById('totalCount');
                        if (visibleCountEl) visibleCountEl.innerText = data.total_visible;
                        if (totalCountEl) totalCountEl.innerText = data.total_all;
                        document.querySelectorAll('.section-tab.active').forEach(tab => tab.classList.remove('active'));
                        document.querySelectorAll('.section-tab').forEach(tab => {
                            if (tab.textContent.trim() === (currentActiveSection === 'A' ? 'Section A' : 'Section B')) {
                                tab.classList.add('active');
                            }
                        });
                        updateStatCards(data);
                    } else {
                        container.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-circle" style="color:#ef4444;"></i><h3>Error Loading Trainees</h3><p>${data.message || 'Please try refreshing'}</p><button onclick="loadTrainees()" style="margin-top:15px;padding:10px 25px;background:#8b5cf6;color:white;border:none;border-radius:8px;cursor:pointer;font-weight:600;"><i class="fas fa-sync-alt"></i> Retry</button></div>`;
                    }
                })
                .catch(error => {
                    container.style.opacity = '1';
                    container.innerHTML = `<div class="empty-state"><i class="fas fa-exclamation-circle" style="color:#ef4444;"></i><h3>Connection Error</h3><p>Unable to load trainees.</p><button onclick="loadTrainees()" style="margin-top:15px;padding:10px 25px;background:#8b5cf6;color:white;border:none;border-radius:8px;cursor:pointer;font-weight:600;"><i class="fas fa-sync-alt"></i> Retry</button></div>`;
                })
                .finally(() => { isFiltering = false; });
        }

        function setupRealTimeFilters() {
            const searchInput = document.getElementById('searchInput');
            const departmentSelect = document.getElementById('departmentSelect');
            const sectionSelect = document.getElementById('sectionSelect');
            const resetBtn = document.getElementById('resetFiltersBtn');
            const applyFilter = () => {
                clearTimeout(filterTimeout);
                filterTimeout = setTimeout(() => { loadTrainees(); }, 400);
            };
            if (searchInput) searchInput.addEventListener('input', applyFilter);
            if (departmentSelect) departmentSelect.addEventListener('change', applyFilter);
            if (sectionSelect) sectionSelect.addEventListener('change', applyFilter);
            if (resetBtn) {
                resetBtn.addEventListener('click', () => {
                    if (searchInput) searchInput.value = '';
                    if (departmentSelect) departmentSelect.value = '';
                    if (sectionSelect) sectionSelect.value = '';
                    activeQualification = '';
                    document.querySelectorAll('.qual-pill').forEach(p => p.classList.remove('active'));
                    loadTrainees();
                });
            }
        }

        function showSection(btn, deptId, section) {
            const sectionADiv = document.getElementById('section-' + deptId + '-A');
            const sectionBDiv = document.getElementById('section-' + deptId + '-B');
            if (sectionADiv) sectionADiv.classList.add('hidden');
            if (sectionBDiv) sectionBDiv.classList.add('hidden');
            const targetDiv = document.getElementById('section-' + deptId + '-' + section);
            if (targetDiv) targetDiv.classList.remove('hidden');
            const tabs = btn.parentElement.querySelectorAll('.section-tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            btn.classList.add('active');
            currentActiveSection = section;
        }

        function openModal(id) {
            document.getElementById(id).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeAllModals() {
            document.querySelectorAll('.modal-custom-overlay').forEach(modal => {
                modal.classList.remove('active');
            });
            document.body.style.overflow = '';
            const btn = document.getElementById('confirmDeleteBtn');
            if (btn) { btn.disabled = false; btn.textContent = 'Yes'; }
            const completeBtn = document.getElementById('confirmCompleteBtn');
            if (completeBtn) { completeBtn.disabled = false; completeBtn.innerHTML = 'Yes, Mark as Completed'; }
        }

        function openViewModal(id) {
            currentViewTraineeId = id;
            const modal = document.getElementById('viewTraineeModal');
            const body = document.getElementById('viewModalBody');
            body.innerHTML = `<div style="text-align:center;padding:40px;"><i class="fas fa-spinner fa-spin" style="font-size:1.8rem;color:#8b5cf6;"></i><p style="margin-top:12px;color:#64748b;">Loading...</p></div>`;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            fetch(`trainees.php?ajax=get_trainee_data&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const t = data.data;
                        const initials = (t.first_name?.charAt(0) || '') + (t.last_name?.charAt(0) || '');
                        const statusClass = t.status || 'active';
                        const displayName = (t.first_name || '') + ' ' + (t.last_name || '');
                        let birthdateDisplay = 'N/A';
                        let ageDisplay = 'N/A';
                        if (t.birthdate) {
                            try {
                                const bd = new Date(t.birthdate);
                                birthdateDisplay = bd.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
                                if (t.age !== null && t.age !== undefined) ageDisplay = t.age + ' years old';
                            } catch (e) { birthdateDisplay = t.birthdate; }
                        }
                        body.innerHTML = `
                            <div style="display:flex;align-items:center;gap:24px;margin-bottom:24px;flex-wrap:wrap;">
                                <div class="view-avatar-large" style="background: linear-gradient(135deg, ${t.dept_color}, ${t.dept_color}cc);">${initials.toUpperCase() || '?'}</div>
                                <div style="flex:1;">
                                    <h2 style="font-size:1.5rem;color:#0f172a;margin-bottom:6px;">${displayName}${t.suffix ? ' ' + t.suffix : ''}</h2>
                                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">
                                        <span class="view-badge ${statusClass}">${statusClass.charAt(0).toUpperCase() + statusClass.slice(1)}</span>
                                        <span class="view-badge" style="background:${t.dept_color};color:white;"><i class="fas ${t.dept_icon}"></i> ${t.course_code || 'N/A'}</span>
                                    </div>
                                    <div class="ref-id-display"><i class="fas fa-id-card"></i> ${t.reference_id || 'N/A'}</div>
                                </div>
                            </div>
                            <div class="view-section"><div class="section-label">Personal Information</div>
                                <div class="view-grid">
                                    <div class="section-value"><strong>First Name:</strong> ${t.first_name || 'N/A'}</div>
                                    <div class="section-value"><strong>Last Name:</strong> ${t.last_name || 'N/A'}</div>
                                    ${t.middle_initial ? `<div class="section-value"><strong>Middle Initial:</strong> ${t.middle_initial}.</div>` : ''}
                                    ${t.suffix ? `<div class="section-value"><strong>Suffix:</strong> ${t.suffix}</div>` : ''}
                                    <div class="section-value"><strong>Sex:</strong> ${t.sex || 'N/A'}</div>
                                    <div class="section-value"><strong>Birthdate:</strong> ${birthdateDisplay}</div>
                                    <div class="section-value"><strong>Age:</strong> ${ageDisplay}</div>
                                </div>
                            </div>
                            <div class="view-section"><div class="section-label">Address</div><div class="section-value">${t.address ? t.address : '<span style="color:#94a3b8;">Not provided</span>'}</div></div>
                            <div class="view-section"><div class="section-label">Academic Information</div>
                                <div class="view-grid"><div class="section-value"><strong>Section:</strong> ${t.section || 'N/A'}</div></div>
                            </div>
                            <div class="view-section"><div class="section-label">Department & Qualification</div>
                                <div class="view-grid">
                                    <div class="section-value"><strong>Department:</strong> ${t.course_name || 'N/A'}</div>
                                    <div class="section-value"><strong>Qualification:</strong> ${t.qualification_name || 'Not Assigned'}</div>
                                    ${t.qualification_code ? `<div class="section-value"><strong>Qualification Code:</strong> ${t.qualification_code}</div>` : ''}
                                </div>
                            </div>
                            <div class="view-section"><div class="section-label">Contact Information</div>
                                <div class="view-grid">
                                    <div class="section-value"><strong>Email:</strong> ${t.email || 'N/A'}</div>
                                    <div class="section-value"><strong>Contact:</strong> ${t.contact || 'N/A'}</div>
                                </div>
                            </div>
                        `;
                    } else {
                        body.innerHTML = `<div style="text-align:center;padding:40px;color:#ef4444;"><i class="fas fa-exclamation-circle" style="font-size:1.8rem;"></i><p style="margin-top:12px;">${data.message || 'Error'}</p></div>`;
                    }
                })
                .catch(error => {
                    body.innerHTML = `<div style="text-align:center;padding:40px;color:#ef4444;"><i class="fas fa-exclamation-circle" style="font-size:1.8rem;"></i><p style="margin-top:12px;">Error loading. Please try again.</p></div>`;
                });
        }

        function openEditFromView() {
            if (currentViewTraineeId) {
                closeAllModals();
                setTimeout(() => { openEditModal(currentViewTraineeId); }, 300);
            }
        }

        function openEditModal(id) {
            const modal = document.getElementById('editTraineeModal');
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            fetch(`trainees.php?ajax=get_trainee_data&id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const t = data.data;
                        document.getElementById('edit_id').value = t.id;
                        document.getElementById('edit_first_name').value = t.first_name || '';
                        document.getElementById('edit_last_name').value = t.last_name || '';
                        document.getElementById('edit_middle_initial').value = t.middle_initial || '';
                        document.getElementById('edit_suffix').value = t.suffix || '';
                        document.getElementById('edit_section').value = t.section || '';
                        document.getElementById('edit_contact').value = t.contact || '';
                        document.getElementById('edit_email').value = t.email || '';
                        document.getElementById('edit_sex').value = t.sex || '';
                        document.getElementById('edit_birthdate').value = t.birthdate || '';
                        document.getElementById('edit_address').value = t.address || '';
                        const refBox = document.getElementById('editRefIdBox');
                        const refVal = document.getElementById('editRefIdValue');
                        if (t.reference_id) { refVal.textContent = t.reference_id; refBox.style.display = 'flex'; }
                        else { refBox.style.display = 'none'; }
                        <?php if ($is_coordinator_role): ?>
                        const courseSelect = document.getElementById('edit_course_id');
                        courseSelect.value = t.course_id || '';
                        const qualSelect = document.getElementById('edit_qualification_id');
                        qualSelect.setAttribute('data-current', t.qualification_id || '');
                        loadQualificationsForEdit();
                        <?php else: ?>
                        const deptDisplay = document.getElementById('edit_department_display');
                        if (deptDisplay) deptDisplay.value = t.department || '<?= $trainer_dept_display ?>';
                        const qualSelect = document.getElementById('edit_qualification_id');
                        const courseId = document.getElementById('edit_course_id').value;
                        setTimeout(() => {
                            if (courseId) {
                                fetch(`trainees.php?ajax=get_qualifications&course_id=${courseId}`)
                                    .then(response => response.json())
                                    .then(data => {
                                        qualSelect.innerHTML = '<option value="">Select Qualification</option>';
                                        if (data.success && data.data && data.data.length > 0) {
                                            data.data.forEach(qual => {
                                                const selected = qual.id == t.qualification_id ? 'selected' : '';
                                                qualSelect.innerHTML += `<option value="${qual.id}" ${selected}>${qual.qualification_name} (${qual.qualification_code})</option>`;
                                            });
                                        }
                                    })
                                    .catch(() => {});
                            }
                        }, 100);
                        <?php endif; ?>
                    }
                })
                .catch(error => { showFloatingMessage('Error loading', 'error'); });
        }

        function openAddModal() {
            const modal = document.getElementById('addTraineeModal');
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            document.getElementById('add_reference_id').value = '';
            document.getElementById('addRefFeedback').className = 'ref-feedback';
            document.getElementById('add_reference_id').classList.remove('ref-valid', 'ref-invalid');
            document.getElementById('add_first_name').value = '';
            document.getElementById('add_last_name').value = '';
            document.getElementById('add_middle_initial').value = '';
            document.getElementById('add_suffix').value = '';
            document.getElementById('add_section').value = '';
            document.getElementById('add_sex').value = '';
            document.getElementById('add_contact').value = '';
            document.getElementById('add_email').value = '';
            document.getElementById('add_birthdate').value = '';
            document.getElementById('add_address').value = '';
            <?php if ($is_coordinator_role): ?>
            document.getElementById('add_course_id').value = '';
            document.getElementById('add_qualification_id').innerHTML = '<option value="">Select Department first</option>';
            <?php endif; ?>
        }

        function submitAddForm() {
            const form = document.getElementById('addTraineeForm');
            const formData = new FormData(form);
            const refInput = document.getElementById('add_reference_id');
            if (!refInput.value.trim()) { showFloatingMessage('Please enter a Reference ID.', 'error'); refInput.focus(); refInput.classList.add('ref-invalid'); return; }
            if (refInput.classList.contains('ref-invalid')) { showFloatingMessage('The Reference ID is already in use.', 'error'); refInput.focus(); return; }
            let isValid = true;
            form.querySelectorAll('[required]').forEach(field => {
                if (!field.value) { field.style.borderColor = '#ef4444'; isValid = false; }
                else { field.style.borderColor = '#e2e8f0'; }
            });
            if (!isValid) { showFloatingMessage('Please fill in all required fields', 'error'); return; }
            const submitBtn = form.querySelector('.btn-success-modal');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            submitBtn.disabled = true;
            fetch('trainees.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) { showFloatingMessage(data.message, 'success'); closeAllModals(); loadTrainees(); }
                else { showFloatingMessage(data.message || 'An error occurred', 'error'); }
            })
            .catch(error => { showFloatingMessage('An error occurred. Please try again.', 'error'); })
            .finally(() => { submitBtn.innerHTML = originalText; submitBtn.disabled = false; });
        }

        function submitEditForm() {
            const form = document.getElementById('editTraineeForm');
            const formData = new FormData(form);
            let isValid = true;
            form.querySelectorAll('[required]').forEach(field => {
                if (!field.value) { field.style.borderColor = '#ef4444'; isValid = false; }
                else { field.style.borderColor = '#e2e8f0'; }
            });
            if (!isValid) { showFloatingMessage('Please fill in all required fields', 'error'); return; }
            const submitBtn = form.querySelector('.btn-primary-modal');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
            submitBtn.disabled = true;
            fetch('trainees.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) { showFloatingMessage(data.message, 'success'); closeAllModals(); loadTrainees(); }
                else { showFloatingMessage(data.message || 'An error occurred', 'error'); }
            })
            .catch(error => { showFloatingMessage('An error occurred. Please try again.', 'error'); })
            .finally(() => { submitBtn.innerHTML = originalText; submitBtn.disabled = false; });
        }

        function openDepartmentTraineeModal(courseId, departmentName) {
            openAddModal();
            <?php if ($is_coordinator_role): ?>
            const deptSelect = document.getElementById('add_course_id');
            deptSelect.value = courseId;
            loadQualificationsForAdd();
            <?php endif; ?>
        }

        window.onclick = function(e) {
            if (e.target.classList.contains('modal-custom-overlay')) {
                e.target.classList.remove('active');
                document.body.style.overflow = '';
                const btn = document.getElementById('confirmDeleteBtn');
                if (btn) { btn.disabled = false; btn.textContent = 'Yes'; }
                const completeBtn = document.getElementById('confirmCompleteBtn');
                if (completeBtn) { completeBtn.disabled = false; completeBtn.innerHTML = 'Yes, Mark as Completed'; }
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($is_coordinator_role && !empty($qualification_pills)): ?>
            (function() {
                const firstPill = document.querySelector('.qual-pill');
                if (firstPill) {
                    const firstQualCode = firstPill.getAttribute('data-qualification') || '';
                    if (firstQualCode) { activeQualification = firstQualCode; firstPill.classList.add('active'); }
                }
            })();
            <?php endif; ?>
            loadTrainees();
            setupRealTimeFilters();
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') { closeAllModals(); }
            });
        });

        window.addEventListener('beforeunload', function() {
            if (statsRefreshInterval) { clearInterval(statsRefreshInterval); }
        });
    </script>
    <script src="/tesda_compliance/assets/js/seamless-nav.js"></script>
    <script data-no-instant>// InstantClick.init(); // DISABLED</script>
    <script src="/tesda_compliance/assets/js/jquery.min.js"></script>
    <script src="/tesda_compliance/assets/js/bootstrap.bundle.min.js"></script>
    <script src="/tesda_compliance/assets/js/app.js"></script>
</body>
</html>