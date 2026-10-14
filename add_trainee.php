<?php
// add_trainee.php - Standalone Add Trainee Page
require_once 'config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn()) {
    redirect('index.php');
}

$user = getCurrentUser();
$db = $pdo;

$error = '';
$success = '';

require_once __DIR__ . '/helpers/helpers/PusherHelper.php';

$user_role = $_SESSION['role'] ?? 'admin';
$user_qualifications = $_SESSION['qualification_ids'] ?? [];
$is_trainer = ($user_role === 'trainer');

$courses = $pdo->query("SELECT * FROM courses ORDER BY course_code")->fetchAll();

if ($is_trainer && !empty($user_qualifications)) {
    $qual_ids = implode(',', array_map('intval', $user_qualifications));
    $qualifications = $pdo->query("
        SELECT q.*, c.course_code 
        FROM qualifications q
        JOIN courses c ON q.course_id = c.id
        WHERE q.id IN ($qual_ids)
        ORDER BY c.course_code, q.qualification_name
    ")->fetchAll();
} else {
    $qualifications = $pdo->query("
        SELECT q.*, c.course_code 
        FROM qualifications q
        JOIN courses c ON q.course_id = c.id
        ORDER BY c.course_code, q.qualification_name
    ")->fetchAll();
}

// ==========================================
// AJAX: Get qualifications by department
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_qualifications') {
    header('Content-Type: application/json');
    
    $course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
    
    if ($course_id > 0) {
        if ($is_trainer && !empty($user_qualifications)) {
            $qual_ids = implode(',', array_map('intval', $user_qualifications));
            $stmt = $pdo->prepare("
                SELECT id, qualification_name, qualification_code 
                FROM qualifications 
                WHERE course_id = ? AND id IN ($qual_ids)
                ORDER BY qualification_name
            ");
            $stmt->execute([$course_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT id, qualification_name, qualification_code 
                FROM qualifications 
                WHERE course_id = ?
                ORDER BY qualification_name
            ");
            $stmt->execute([$course_id]);
        }
        
        $qualifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $qualifications]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid course ID']);
    }
    exit;
}

// ==========================================
// AJAX: Check if Reference ID already exists (live validation)
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 'check_reference_id') {
    header('Content-Type: application/json');
    
    $reference_id = trim($_GET['reference_id'] ?? '');
    
    if ($reference_id === '') {
        echo json_encode(['success' => false, 'exists' => false, 'message' => 'Reference ID is required']);
        exit;
    }
    
    try {
        // Check against active trainees (not soft-deleted)
        $stmt = $pdo->prepare("
            SELECT id, first_name, last_name 
            FROM trainees 
            WHERE reference_id = ? AND deleted_at IS NULL 
            LIMIT 1
        ");
        $stmt->execute([$reference_id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            echo json_encode([
                'success' => true,
                'exists' => true,
                'message' => 'This Reference ID is already used by ' . $existing['first_name'] . ' ' . $existing['last_name'] . '.'
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'exists' => false,
                'message' => 'Reference ID is available.'
            ]);
        }
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'exists' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
    }
    exit;
}

// ==========================================
// HANDLE POST: Add Trainee
// ==========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add') {
    try {
        $first_name      = trim($_POST['first_name']);
        $last_name       = trim($_POST['last_name']);
        $middle_initial  = trim($_POST['middle_initial'] ?? '');
        $suffix          = trim($_POST['suffix'] ?? '');
        $section         = $_POST['section'] ?? '';
        $email           = trim($_POST['email'] ?? '');
        $contact         = trim($_POST['contact'] ?? '');
        $course_id       = $_POST['course_id'] ?? 0;
        $sex             = $_POST['sex'] ?? '';
        $qualification_id = !empty($_POST['qualification_id']) ? $_POST['qualification_id'] : null;
        $batch_code      = isset($_POST['batch_code']) ? trim($_POST['batch_code']) : '';
        $reference_id    = trim($_POST['reference_id'] ?? '');
        $year_level      = $_POST['year_level'] ?? '1st Year'; // NEW: from form
        
        // Validation
        if (empty($reference_id))                          throw new Exception("Reference ID is required.");
        if (empty($first_name) || empty($last_name))       throw new Exception("First name and last name are required.");
        if (empty($section))                               throw new Exception("Please select a section.");
        if (empty($sex))                                   throw new Exception("Please select sex.");
        if (empty($course_id))                             throw new Exception("Please select a department.");
        if (empty($year_level))                            throw new Exception("Please select year level.");
        if (!in_array($year_level, ['1st Year', '2nd Year'])) throw new Exception("Invalid year level selected.");

        // Reference ID duplicate check (before insert)
        $dup_check = $pdo->prepare("
            SELECT id, first_name, last_name 
            FROM trainees 
            WHERE reference_id = ? AND deleted_at IS NULL 
            LIMIT 1
        ");
        $dup_check->execute([$reference_id]);
        $duplicate = $dup_check->fetch(PDO::FETCH_ASSOC);
        if ($duplicate) {
            throw new Exception("Reference ID '{$reference_id}' is already assigned to {$duplicate['first_name']} {$duplicate['last_name']}. Please use a different one.");
        }

        // Trainer authorization
        if ($is_trainer && !empty($user_qualifications)) {
            if (empty($qualification_id) || !in_array($qualification_id, $user_qualifications)) {
                throw new Exception("You are not authorized to add a trainee to this qualification.");
            }
        }
        
        $dept_stmt = $pdo->prepare("SELECT course_code FROM courses WHERE id = ?");
        $dept_stmt->execute([$course_id]);
        $department = $dept_stmt->fetchColumn();
        
        if (!$department) throw new Exception("Invalid department selected");
        
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            INSERT INTO trainees 
            (reference_id, first_name, last_name, middle_initial, suffix, year_level, section, email, contact, department, sex, qualification_id, batch_code) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $reference_id,
            $first_name, $last_name, $middle_initial, $suffix,
            $year_level, $section, $email, $contact,
            $department, $sex, $qualification_id, $batch_code
        ]);
        
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
            $payload = [
                'trainee_id'      => $trainee_id,
                'reference_id'    => $reference_id,
                'trainee_name'    => $first_name . ' ' . $last_name,
                'department'      => $department,
                'qualification_id'=> $qualification_id,
                'section'         => $section,
                'year_level'      => $year_level
            ];
            triggerPusherUpdate('trainee_added', $payload, 'compliance-channel');
        } catch (Exception $e) { /* non-blocking */ }
        
        $_SESSION['success_message'] = "Trainee added successfully! Reference ID: " . $reference_id;
        header('Location: trainees.php');
        exit;
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    } catch(PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        // Handle MySQL unique constraint violation as a friendly error
        if ($e->getCode() == 23000) {
            $error = "This Reference ID is already in use. Please enter a different one.";
        } else {
            $error = "Database error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Trainee - TESDA Compliance</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
    <link rel="stylesheet" href="/tesda_compliance/assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="/tesda_compliance/assets/css/bootstrap.min.css">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }

        body {
            background: #f0f2f5;
            min-width: 1200px;
            overflow-x: auto;
            margin: 0;
            transition: all 0.3s ease;
        }
        body.sidebar-collapsed { min-width: 992px; }

        .main-content {
            margin-left: 280px;
            padding: 30px;
            width: calc(100% - 280px);
            min-width: 920px;
            transition: margin-left 0.3s ease, width 0.3s ease;
            animation: fadeInPage 0.4s ease-out;
        }

        body.sidebar-collapsed .main-content { margin-left: 80px; width: calc(100% - 80px); }

        @keyframes fadeInPage {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
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
            background: linear-gradient(135deg, #1e293b, #10b981);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0;
        }

        .header-left h1 i {
            background: linear-gradient(135deg, #10b981, #059669);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 1.8rem;
        }

        .header-left p { color: #64748b; font-size: 1rem; margin-top: 4px; }

        .header-right { display: flex; align-items: center; gap: 18px; }

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

        .role-badge i { font-size: 1.4rem; color: #10b981; }

        .role-badge .role-name {
            font-weight: 600;
            font-size: 1.1rem;
            color: #1e293b;
            letter-spacing: 0.3px;
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
        .btn-back:hover { background: #e2e8f0; transform: translateY(-2px); }

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
        .form-card .card-title i { color: #10b981; }

        .form-group { margin-bottom: 16px; }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #475569;
            font-weight: 600;
            font-size: 0.85rem;
            letter-spacing: 0.3px;
        }
        .form-group label .required { color: #ef4444; margin-left: 2px; }

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
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16,185,129,0.1);
        }

        .form-group input:disabled,
        .form-group select:disabled { background: #f1f5f9; cursor: not-allowed; }

        .form-group .help-text { font-size: 0.75rem; color: #64748b; margin-top: 4px; }

        /* Reference ID validation states */
        .form-group input.ref-valid {
            border-color: #10b981 !important;
            background: #f0fdf4;
        }
        .form-group input.ref-invalid {
            border-color: #ef4444 !important;
            background: #fef2f2;
        }
        .ref-feedback {
            font-size: 0.75rem;
            margin-top: 4px;
            font-weight: 500;
            display: none;
        }
        .ref-feedback.show { display: block; }
        .ref-feedback.valid { color: #059669; }
        .ref-feedback.invalid { color: #dc2626; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-row-3 { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 16px; }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }

        .btn-save {
            background: linear-gradient(135deg, #10b981, #059669);
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
            box-shadow: 0 5px 15px rgba(16,185,129,0.3);
            flex: 1;
        }
        .btn-save:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(16,185,129,0.4); }
        .btn-save:disabled { opacity: 0.7; cursor: not-allowed; transform: none !important; }

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
        .btn-cancel:hover { background: #e2e8f0; transform: translateY(-2px); }

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
    
    <div class="main-content">
        <div class="header">
            <div class="header-left">
                <h1><i class="fas fa-user-plus"></i> Add New Trainee</h1>
                <p>Add a new trainee to the system</p>
            </div>
            <div class="header-right">
                <div class="role-badge">
                    <i class="fas fa-user-circle"></i>
                    <span class="role-name"><?= htmlspecialchars($user['full_name'] ?? $user['username']) ?></span>
                </div>
                <a href="trainees.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="message"><i class="fas fa-check-circle"></i> <?= $success ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="error"><i class="fas fa-exclamation-circle"></i> <?= $error ?></div>
        <?php endif; ?>

        <div class="form-card">
            <div class="card-title">
                <i class="fas fa-user-plus"></i> Trainee Information
            </div>
            
            <form method="POST" id="addTraineeForm">
                <input type="hidden" name="action" value="add">
                
                <!-- Reference ID field -->
                <div class="form-group">
                    <label>Reference ID <span class="required">*</span></label>
                    <input type="text" name="reference_id" id="reference_id" 
                           placeholder="e.g., TESDA-000010-122B" 
                           maxlength="50" required autocomplete="off">
                    <div class="help-text">Enter the Reference ID manually. Duplicates are not allowed.</div>
                    <div class="ref-feedback" id="refFeedback"></div>
                </div>

                <div class="form-row-3">
                    <div class="form-group">
                        <label>First Name <span class="required">*</span></label>
                        <input type="text" name="first_name" id="first_name" required>
                    </div>
                    <div class="form-group">
                        <label>M.I.</label>
                        <input type="text" name="middle_initial" id="middle_initial" maxlength="5" placeholder="M">
                    </div>
                    <div class="form-group">
                        <label>Suffix</label>
                        <select name="suffix" id="suffix">
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
                    <input type="text" name="last_name" id="last_name" required>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Section <span class="required">*</span></label>
                        <select name="section" id="section" required>
                            <option value="">Select Section</option>
                            <option value="A">Section A</option>
                            <option value="B">Section B</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sex <span class="required">*</span></label>
                        <select name="sex" id="sex" required>
                            <option value="">Select Sex</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                        </select>
                    </div>
                </div>

                <!-- ============================================
                     YEAR LEVEL DROPDOWN (1st Year & 2nd Year)
                     ============================================ -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Year Level <span class="required">*</span></label>
                        <select name="year_level" id="year_level" required>
                            <option value="">Select Year Level</option>
                            <option value="1st Year">1st Year</option>
                            <option value="2nd Year">2nd Year</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <!-- Empty column to keep grid alignment -->
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Department <span class="required">*</span></label>
                    <select name="course_id" id="course_id" required onchange="loadQualifications()">
                        <option value="">Select Department</option>
                        <?php foreach ($courses as $course): ?>
                        <option value="<?= $course['id'] ?>"><?= htmlspecialchars($course['course_name']) ?> (<?= $course['course_code'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Qualification</label>
                    <select name="qualification_id" id="qualification_id">
                        <option value="">Select Department first</option>
                    </select>
                    <div class="help-text">Select the qualification the trainee is enrolled in</div>
                </div>
                
                <div class="form-group">
                    <label>Batch Code</label>
                    <input type="text" name="batch_code" id="batch_code" placeholder="e.g., BATCH-2024-A" maxlength="50">
                    <div class="help-text">Manually enter the batch code</div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="text" name="contact" id="contact" placeholder="Contact Number">
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" id="email" placeholder="Email Address">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn-save" id="submitBtn">
                        <i class="fas fa-save"></i> Add Trainee
                    </button>
                    <a href="trainees.php" class="btn-cancel">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        function loadQualifications() {
            const courseId = document.getElementById('course_id').value;
            const qualSelect = document.getElementById('qualification_id');
            
            if (!courseId) {
                qualSelect.innerHTML = '<option value="">Select Department first</option>';
                return;
            }
            
            fetch('add_trainee.php?ajax=get_qualifications&course_id=' + courseId)
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    qualSelect.innerHTML = '<option value="">Select Qualification</option>';
                    if (data.success && data.data && data.data.length > 0) {
                        data.data.forEach(function(qual) {
                            qualSelect.innerHTML += '<option value="' + qual.id + '">' + qual.qualification_name + ' (' + qual.qualification_code + ')</option>';
                        });

                        if (data.data.length === 1 && <?= $is_trainer ? 'true' : 'false' ?>) {
                            qualSelect.value = data.data[0].id;
                        }
                    } else {
                        qualSelect.innerHTML = '<option value="">No qualifications available</option>';
                    }
                })
                .catch(function() {
                    qualSelect.innerHTML = '<option value="">Error loading qualifications</option>';
                });
        }

        // ==========================================
        // Live Reference ID duplicate check
        // ==========================================
        (function() {
            const refInput = document.getElementById('reference_id');
            const refFeedback = document.getElementById('refFeedback');
            const submitBtn = document.getElementById('submitBtn');
            let debounceTimer = null;
            let lastChecked = '';
            let refIsValid = false;

            if (!refInput) return;

            function setFeedback(message, isValid) {
                refFeedback.textContent = message;
                refFeedback.className = 'ref-feedback show ' + (isValid ? 'valid' : 'invalid');
                refInput.classList.remove('ref-valid', 'ref-invalid');
                refInput.classList.add(isValid ? 'ref-valid' : 'ref-invalid');
                refIsValid = isValid;
            }

            function clearFeedback() {
                refFeedback.className = 'ref-feedback';
                refInput.classList.remove('ref-valid', 'ref-invalid');
                refIsValid = false;
            }

            refInput.addEventListener('input', function() {
                const value = refInput.value.trim();

                if (value === '') {
                    clearFeedback();
                    return;
                }

                if (value === lastChecked) return;

                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function() {
                    lastChecked = value;

                    fetch('add_trainee.php?ajax=check_reference_id&reference_id=' + encodeURIComponent(value))
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (!data.success) {
                                setFeedback('⚠ ' + (data.message || 'Unable to check.'), false);
                                return;
                            }
                            if (data.exists) {
                                setFeedback('✕ ' + data.message, false);
                            } else {
                                setFeedback('✓ ' + data.message, true);
                            }
                        })
                        .catch(function() {
                            setFeedback('⚠ Could not verify. Please try again.', false);
                        });
                }, 450);
            });

            // Block form submit if Reference ID is known to be invalid
            document.getElementById('addTraineeForm').addEventListener('submit', function(e) {
                const value = refInput.value.trim();

                if (value === '') {
                    e.preventDefault();
                    setFeedback('✕ Reference ID is required.', false);
                    refInput.focus();
                    return;
                }

                // If the field is marked invalid (duplicate found), block submit
                if (refInput.classList.contains('ref-invalid')) {
                    e.preventDefault();
                    setFeedback('✕ Please fix the Reference ID before submitting.', false);
                    refInput.focus();
                    return;
                }

                // Disable the button to prevent double submit
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            });
        })();

        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($is_trainer && count($qualifications) === 1): ?>
                const singleQual = <?= json_encode($qualifications[0]) ?>;
                const courseSelect = document.getElementById('course_id');
                
                if (courseSelect) {
                    courseSelect.value = singleQual.course_id;
                    loadQualifications();
                    
                    setTimeout(function() {
                        const qualSelect = document.getElementById('qualification_id');
                        if (qualSelect) qualSelect.value = singleQual.id;
                    }, 300);
                }
            <?php endif; ?>
        });

        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b') {
                e.preventDefault();
                const toggleBtn = document.getElementById('sidebar-toggle-btn');
                if (toggleBtn) toggleBtn.click();
            }
        });
    </script>
    <script src="/tesda_compliance/assets/js/seamless-nav.js"></script>
    <script src="/tesda_compliance/assets/js/jquery.min.js"></script>
    <script src="/tesda_compliance/assets/js/bootstrap.bundle.min.js"></script>
    <script src="/tesda_compliance/assets/js/app.js"></script>
</body>
</html>