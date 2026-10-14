<?php
// sidebar.php - TESDA Sidebar with RBAC Link Protection
// Place in: /tesda_compliance/sidebar.php

if (!function_exists('getBaseUrl')) {
    function getBaseUrl() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $script_name = $_SERVER['SCRIPT_NAME'];
        $base_path = rtrim(dirname($script_name), '/') . '/';
        if (strpos($base_path, '/reports/') !== false) {
            $base_path = str_replace('/reports/', '/', $base_path);
        }
        return $protocol . $host . $base_path;
    }
}

$user = getCurrentUser();
$current_page = basename($_SERVER['PHP_SELF']);
$base_url = getBaseUrl();

$is_coordinator = isCoordinator();
$is_trainer = isTrainer();

// ==========================================
// Get user profile picture and display info
// ==========================================
$profile_picture_url = '';
$user_display_name = $user['full_name'] ?? $user['username'] ?? 'User';
$user_role_display = ucfirst($user['role'] ?? 'User');
$user_qualification_display = '';

if ($user && !empty($user['id'])) {
    try {
        $pic_stmt = $pdo->prepare("SELECT profile_picture, full_name, role FROM users WHERE id = ? LIMIT 1");
        $pic_stmt->execute([$user['id']]);
        $pic_row = $pic_stmt->fetch(PDO::FETCH_ASSOC);
        if ($pic_row) {
            if (!empty($pic_row['profile_picture'])) {
                $profile_picture_url = '/tesda_compliance/' . ltrim($pic_row['profile_picture'], '/');
            }
            if (!empty($pic_row['full_name'])) {
                $user_display_name = $pic_row['full_name'];
            }
            if (!empty($pic_row['role'])) {
                $user_role_display = ucfirst($pic_row['role']);
            }
        }
    } catch (\Throwable $e) {
        error_log("Sidebar profile fetch error: " . $e->getMessage());
    }
}

// ==========================================
// Get user's assigned qualification(s)
// ==========================================
if ($user && !empty($user['id'])) {
    try {
        $q_stmt = $pdo->prepare("
            SELECT q.qualification_code, q.qualification_name
            FROM user_qualifications uq
            INNER JOIN qualifications q ON q.id = uq.qualification_id
            WHERE uq.user_id = ?
            ORDER BY q.qualification_code ASC
        ");
        $q_stmt->execute([$user['id']]);
        $q_rows = $q_stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($q_rows)) {
            $qual_labels = [];
            foreach ($q_rows as $q_row) {
                $code = trim($q_row['qualification_code'] ?? '');
                $name = trim($q_row['qualification_name'] ?? '');
                if ($code && $name) {
                    $qual_labels[] = $code . ' — ' . $name;
                } elseif ($code) {
                    $qual_labels[] = $code;
                } elseif ($name) {
                    $qual_labels[] = $name;
                }
            }
            if (!empty($qual_labels)) {
                $user_qualification_display = implode(', ', $qual_labels);
            }
        }
    } catch (\Throwable $e) {
        error_log("Sidebar qualification fetch error: " . $e->getMessage());
    }
}

// Get initials for fallback avatar
$name_parts = preg_split('/\s+/', trim($user_display_name));
$initials = '';
if (count($name_parts) >= 2) {
    $initials = strtoupper(
        mb_substr($name_parts[0], 0, 1) .
        mb_substr($name_parts[count($name_parts) - 1], 0, 1)
    );
} else {
    $initials = strtoupper(mb_substr($user_display_name, 0, 2));
}

// Get notification count
$notification_count = 0;
if ($user) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notification_logs WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user['id']]);
        $notification_count = $stmt->fetchColumn();
    } catch (Exception $e) {}
}

// Get notified logs count
$notified_count = 0;
if ($user) {
    try {
        $user_role = $user['role'] ?? '';
        $user_department = $user['department'] ?? '';

        $query = "SELECT COUNT(*) FROM notice_logs WHERE is_read = 0 AND is_archived = 0";
        $params = [];

        if ($user_role === 'coordinator') {
            $query .= " AND (target_audience IN ('all', 'coordinators') OR department_id = ? OR department_id IS NULL)";
            $params[] = $user_department;
        } elseif ($user_role === 'trainer') {
            $query .= " AND (target_audience IN ('all', 'trainers') OR department_id = ? OR department_id IS NULL)";
            $params[] = $user_department;
        } elseif ($user_role === 'trainee') {
            $query .= " AND (target_audience IN ('all', 'trainees') OR department_id = ? OR department_id IS NULL)";
            $params[] = $user_department;
        } else {
            $query .= " AND target_audience = 'all'";
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $notified_count = $stmt->fetchColumn();
    } catch (Exception $e) {}
}

// Get pending trainees count
$pending_count = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM trainees WHERE status = 'pending'");
    $pending_count = $stmt->fetchColumn();
} catch (Exception $e) {}

// Get trainees ready for pre-assessment
$eligible_count = 0;
if ($user) {
    try {
        $user_department = $user['department'] ?? '';

        if ($is_coordinator) {
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT ee.trainee_id)
                FROM evaluation_eligibility ee
                INNER JOIN trainees t ON ee.trainee_id = t.id
                WHERE ee.ready_for_assessment = 1
            ");
            $stmt->execute();
            $eligible_count = (int)$stmt->fetchColumn();
        } elseif ($is_trainer && !empty($user_department)) {
            $qual_ids = $_SESSION['qualification_ids'] ?? [];
            if (!empty($qual_ids)) {
                $placeholders = implode(',', array_fill(0, count($qual_ids), '?'));
                $stmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT ee.trainee_id)
                    FROM evaluation_eligibility ee
                    INNER JOIN trainees t ON ee.trainee_id = t.id
                    WHERE ee.ready_for_assessment = 1
                    AND t.qualification_id IN ($placeholders)
                ");
                $stmt->execute($qual_ids);
                $eligible_count = (int)$stmt->fetchColumn();
            }
        }
    } catch (Exception $e) {
        $eligible_count = 0;
    }
}

$is_reports_page = (strpos($current_page, 'report') !== false || $current_page == 'reports.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TESDA Compliance System</title>

    <link rel="stylesheet" href="/tesda_compliance/assets/css/poppins-local.css">
    <link rel="stylesheet" href="/tesda_compliance/assets/css/all.min.css">

    <script>
        (function() {
            try {
                if (localStorage.getItem('sidebar-collapsed') === 'true') {
                    document.documentElement.classList.add('sidebar-is-collapsed');
                }
                document.documentElement.classList.add('sidebar-init-hidden');
            } catch (e) {}

            document.addEventListener('DOMContentLoaded', function() {
                document.documentElement.classList.remove('sidebar-init-hidden');
            });

            window.addEventListener('pageshow', function(event) {
                try {
                    if (sessionStorage.getItem('tesda_logout_flag') === '1') {
                        sessionStorage.removeItem('tesda_logout_flag');
                        window.location.replace('<?= $base_url ?>login.php?_=' + Date.now());
                        return;
                    }
                    if (event.persisted) {
                        window.location.reload();
                    }
                } catch (e) {}
            });
        })();
    </script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f0f2f5;
            min-width: 1200px;
            overflow-x: auto;
            margin: 0;
        }

        body.sidebar-collapsed { min-width: 992px; }

        html.sidebar-init-hidden .sidebar,
        html.sidebar-init-hidden .sidebar-toggle {
            opacity: 0;
        }

        .sidebar, .sidebar-toggle {
            opacity: 1;
            transition: opacity 0.1s ease, width 0.25s ease, left 0.25s ease;
        }

        .main-content {
            opacity: 1;
            transition: margin-left 0.25s ease, width 0.25s ease;
        }

        /* SIDEBAR */
        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #1a1c2c 0%, #2d2f45 100%);
            color: white;
            position: fixed;
            height: 100vh;
            top: 0;
            left: 0;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            box-shadow: 4px 0 20px rgba(0,0,0,0.1);
            z-index: 100;
            transition: width 0.25s ease, opacity 0.1s ease;
        }

        .sidebar.collapsed,
        html.sidebar-is-collapsed .sidebar {
            width: 80px !important;
        }

        .sidebar.collapsed .sidebar-user-info,
        .sidebar.collapsed .sidebar-user-chevron,
        .sidebar.collapsed .menu-item span,
        .sidebar.collapsed .section-title,
        .sidebar.collapsed .logout-footer .logout-item span,
        html.sidebar-is-collapsed .sidebar .sidebar-user-info,
        html.sidebar-is-collapsed .sidebar .sidebar-user-chevron,
        html.sidebar-is-collapsed .sidebar .menu-item span,
        html.sidebar-is-collapsed .sidebar .section-title,
        html.sidebar-is-collapsed .sidebar .logout-footer .logout-item span {
            display: none !important;
        }

        .sidebar.collapsed .sidebar-header,
        html.sidebar-is-collapsed .sidebar .sidebar-header {
            justify-content: center;
            padding: 20px 0;
            gap: 0;
        }

        .sidebar.collapsed .sidebar-avatar,
        html.sidebar-is-collapsed .sidebar .sidebar-avatar {
            width: 48px;
            height: 48px;
        }

        .sidebar.collapsed .menu-item,
        html.sidebar-is-collapsed .sidebar .menu-item {
            justify-content: center;
            padding: 12px 0;
        }

        .sidebar.collapsed .menu-item i,
        html.sidebar-is-collapsed .sidebar .menu-item i {
            margin: 0;
            width: auto;
            font-size: 1.6rem;
        }

        .sidebar.collapsed .logout-footer,
        html.sidebar-is-collapsed .sidebar .logout-footer {
            justify-content: center;
            padding: 15px 0;
        }

        .sidebar.collapsed .logout-footer .logout-item,
        html.sidebar-is-collapsed .sidebar .logout-footer .logout-item {
            justify-content: center;
            padding: 10px 0;
        }

        .sidebar.collapsed .logout-footer .logout-item i,
        html.sidebar-is-collapsed .sidebar .logout-footer .logout-item i {
            margin: 0;
        }

        /* ========================================== */
        /* SIDEBAR USER PROFILE HEADER                 */
        /* ========================================== */
        .sidebar-header {
            padding: 20px 20px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            display: flex;
            align-items: center;
            gap: 14px;
            position: relative;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .sidebar-header:hover {
            background: rgba(255,255,255,0.03);
        }

        .sidebar-avatar {
            width: 54px;
            height: 54px;
            border-radius: 50%;
            overflow: hidden;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.35);
            border: 2px solid rgba(255, 255, 255, 0.15);
            transition: transform 0.2s ease;
        }

        .sidebar-header:hover .sidebar-avatar {
            transform: scale(1.05);
        }

        .sidebar-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .sidebar-initials {
            color: #ffffff;
            font-weight: 700;
            font-size: 1.15rem;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            line-height: 1;
        }

        .sidebar-user-info {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .sidebar-user-info h2 {
            font-size: 1.05rem;
            font-weight: 600;
            color: #ffffff;
            margin: 0;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 145px;
        }

        .sidebar-user-info p {
            color: #94a3b8;
            margin: 0;
            font-size: 0.82rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 145px;
        }

        .status-dot-online {
            display: inline-block;
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: #10b981;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.25);
            flex-shrink: 0;
        }

        .sidebar-user-chevron {
            color: #64748b;
            font-size: 0.85rem;
            transition: transform 0.2s ease;
            flex-shrink: 0;
        }

        .sidebar-header:hover .sidebar-user-chevron {
            color: #cbd5e1;
        }

        .sidebar-menu { padding: 15px 15px; flex: 1; }

        .menu-item {
            display: flex;
            align-items: center;
            padding: 10px 18px;
            margin: 3px 0;
            border-radius: 10px;
            color: #cbd5e0;
            text-decoration: none;
            transition: all 0.3s ease;
            white-space: nowrap;
            overflow: hidden;
            position: relative;
            cursor: pointer;
        }

        .menu-item i { width: 32px; font-size: 1.35rem; }
        .menu-item span { transition: all 0.3s; font-size: 1.1rem; font-weight: 500; }

        .menu-item:hover {
            background: rgba(255,255,255,0.1);
            color: white;
            transform: translateX(5px);
        }

        .menu-item.active {
            background: rgba(59, 130, 246, 0.25);
            backdrop-filter: blur(4px);
            color: white;
            box-shadow: 0 0 15px rgba(59, 130, 246, 0.2);
            border-left: 3px solid rgba(59, 130, 246, 0.8);
        }

        .menu-divider { height: 1px; background: rgba(255,255,255,0.1); margin: 12px 0; }

        .section-title {
            padding: 8px 18px;
            color: #94a3b8;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }

        .notification-badge {
            background: #ef4444;
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            margin-left: auto;
            min-width: 22px;
            text-align: center;
        }

        .notified-badge {
            background: #8b5cf6;
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            margin-left: auto;
            min-width: 22px;
            text-align: center;
        }

        .logout-footer {
            padding: 18px 22px;
            border-top: 1px solid rgba(255,255,255,0.1);
            background: linear-gradient(180deg, #2d2f45, #1a1c2c);
            margin-top: auto;
        }

        .logout-item {
            display: flex;
            align-items: center;
            padding: 10px 18px;
            margin: 0;
            border-radius: 10px;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
            white-space: nowrap;
            overflow: hidden;
            position: relative;
            cursor: pointer;
            background: rgba(220, 38, 38, 0.25);
            backdrop-filter: blur(4px);
            border-left: 3px solid rgba(220, 38, 38, 0.8);
            box-shadow: 0 0 15px rgba(220, 38, 38, 0.15);
        }

        .logout-item i { width: 32px; font-size: 1.35rem; color: #fca5a5; }
        .logout-item span { font-size: 1.1rem; font-weight: 500; }

        .logout-item:hover {
            background: rgba(220, 38, 38, 0.4);
            backdrop-filter: blur(6px);
            color: white;
            transform: translateX(5px);
            box-shadow: 0 0 20px rgba(220, 38, 38, 0.3);
        }

        .sidebar-toggle {
            position: fixed;
            left: 290px;
            top: 20px;
            background: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            z-index: 101;
            transition: left 0.25s ease, background 0.25s ease, opacity 0.1s ease;
            border: none;
            color: #1a1c2c;
        }

        .sidebar.collapsed ~ .sidebar-toggle,
        html.sidebar-is-collapsed .sidebar-toggle {
            left: 90px;
        }

        .sidebar-toggle:hover {
            background: rgba(59, 130, 246, 0.8);
            color: white;
            transform: scale(1.1);
        }

        .main-content {
            margin-left: 280px;
            padding: 30px;
            width: calc(100% - 280px);
            min-width: 920px;
            transition: margin-left 0.25s ease, width 0.25s ease;
        }

        body.sidebar-collapsed .main-content,
        html.sidebar-is-collapsed .main-content {
            margin-left: 80px;
            width: calc(100% - 80px);
        }

        /* ========================================== */
        /* JS TOOLTIP — FLOATING DIV                  */
        /* ========================================== */
        #sidebar-js-tooltip {
            position: fixed;
            background-color: #1e293b;
            color: #ffffff;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 500;
            font-family: 'Poppins', sans-serif;
            border-radius: 8px;
            white-space: nowrap;
            z-index: 999999;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
            pointer-events: none;
            opacity: 0;
            transform: translateX(-6px);
            transition: opacity 0.15s ease, transform 0.15s ease;
        }

        #sidebar-js-tooltip.visible {
            opacity: 1;
            transform: translateX(0);
        }

        #sidebar-js-tooltip::before {
            content: '';
            position: absolute;
            right: 100%;
            top: 50%;
            transform: translateY(-50%);
            border-width: 6px;
            border-style: solid;
            border-color: transparent #1e293b transparent transparent;
        }

        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.1); }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.3); border-radius: 10px; }
        .sidebar::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.5); }

        @media (max-width: 1200px) { body { min-width: 1200px; } }

        /* ========================================== */
        /* LOGOUT CONFIRMATION MODAL                  */
        /* ========================================== */
        .logout-modal-overlay {
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

        .logout-modal-overlay.active { display: flex; }

        @keyframes logoutFadeIn {
            from { opacity: 0; }
            to   { opacity: 1; }
        }

        @keyframes logoutPopIn {
            from { opacity: 0; transform: translateY(12px) scale(0.96); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        .logout-modal-card {
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

        .logout-modal-icon {
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

        .logout-modal-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 8px;
        }

        .logout-modal-text {
            font-size: 0.9rem;
            color: #64748b;
            line-height: 1.5;
            margin: 0 0 24px;
        }

        .logout-modal-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .logout-btn {
            flex: 1;
            padding: 11px 18px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-family: 'Poppins', 'Segoe UI', Tahoma, sans-serif;
        }

        .logout-btn-cancel {
            background: #f1f5f9;
            color: #475569;
            border: 2px solid #e2e8f0;
        }

        .logout-btn-cancel:hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        .logout-btn-confirm {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #ffffff;
            box-shadow: 0 6px 16px rgba(220, 38, 38, 0.3);
        }

        .logout-btn-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(220, 38, 38, 0.4);
        }

        .logout-btn-confirm:active,
        .logout-btn-cancel:active {
            transform: translateY(0);
        }

        /* ========================================== */
        /* ACCOUNT DETAILS MODAL                      */
        /* ========================================== */
        .account-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 999999;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: accountFadeIn 0.2s ease-out;
        }

        .account-modal-overlay.active { display: flex; }

        @keyframes accountFadeIn {
            from { opacity: 0; }
            to   { opacity: 1; }
        }

        @keyframes accountPopIn {
            from { opacity: 0; transform: translateY(16px) scale(0.96); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        .account-modal-card {
            background: #ffffff;
            border-radius: 24px;
            width: 100%;
            max-width: 900px;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.35);
            animation: accountPopIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            display: flex;
            flex-direction: column;
        }

        .account-modal-close {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #f1f5f9;
            border: none;
            font-size: 1.5rem;
            line-height: 1;
            color: #64748b;
            cursor: pointer;
            transition: all 0.2s ease;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .account-modal-close:hover {
            background: #fee2e2;
            color: #dc2626;
            transform: rotate(90deg);
        }

        .account-modal-body {
            display: grid;
            grid-template-columns: 260px 1fr;
            overflow: hidden;
            max-height: 90vh;
        }

        /* LEFT COLUMN */
        .account-modal-left {
            background: #f8fafc;
            padding: 30px 24px;
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            align-items: center;
            overflow-y: auto;
        }

        .account-avatar-wrapper {
            position: relative;
            width: 130px;
            height: 130px;
            margin-bottom: 16px;
        }

        .account-avatar {
            width: 130px;
            height: 130px;
            border-radius: 24px;
            background: linear-gradient(135deg, #3b82f6, #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(59, 130, 246, 0.25);
            border: 4px solid #ffffff;
        }

        .account-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .account-initials {
            color: #ffffff;
            font-weight: 700;
            font-size: 2.6rem;
            letter-spacing: 1px;
            text-transform: uppercase;
            line-height: 1;
        }

        .account-camera-overlay {
            position: absolute;
            bottom: 8px;
            right: 8px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #1e293b;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            cursor: pointer;
            border: 3px solid #ffffff;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            transition: all 0.2s ease;
            z-index: 5;
        }

        .account-camera-overlay:hover {
            background: #3b82f6;
            transform: scale(1.1);
        }

        .account-name-display {
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 4px;
            text-align: center;
            line-height: 1.3;
        }

        .account-role-display {
            font-size: 0.82rem;
            color: #64748b;
            margin: 0 0 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-weight: 500;
        }

        .account-qual-display {
            font-size: 0.78rem;
            color: #0369a1;
            background: #e0f2fe;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 6px 12px;
            margin: 0 0 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-weight: 600;
            text-align: center;
            line-height: 1.35;
            max-width: 100%;
            word-break: break-word;
        }

        .account-qual-display i {
            color: #0284c7;
            font-size: 0.75rem;
            flex-shrink: 0;
        }

        .account-tabs {
            width: 100%;
            display: flex;
            flex-direction: column;
            gap: 4px;
            margin-top: auto;
        }

        .account-tab {
            display: flex;
            align-items: center;
            gap: 10px;
            width: 100%;
            padding: 12px 16px;
            border-radius: 10px;
            background: transparent;
            border: none;
            font-family: 'Poppins', sans-serif;
            font-size: 0.88rem;
            font-weight: 500;
            color: #475569;
            cursor: pointer;
            text-align: left;
            transition: all 0.2s ease;
        }

        .account-tab i {
            width: 18px;
            font-size: 0.9rem;
            color: #94a3b8;
            transition: color 0.2s ease;
        }

        .account-tab:hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        .account-tab:hover i { color: #64748b; }

        .account-tab.active {
            background: #1e293b;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(30, 41, 59, 0.2);
        }

        .account-tab.active i { color: #ffffff; }

        /* RIGHT COLUMN */
        .account-modal-right {
            padding: 30px 32px;
            overflow-y: auto;
            max-height: 90vh;
        }

        .account-tab-content { display: none; }
        .account-tab-content.active { display: block; }

        .account-panel-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 4px;
        }

        .account-panel-subtitle {
            font-size: 0.85rem;
            color: #64748b;
            margin: 0 0 24px;
        }

        .account-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .account-form-group {
            display: flex;
            flex-direction: column;
        }

        .account-form-group.full-width {
            grid-column: 1 / -1;
        }

        .account-form-group label {
            font-size: 0.75rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .account-form-group input,
        .account-form-group select {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 0.9rem;
            font-family: 'Poppins', sans-serif;
            color: #1e293b;
            background: #f8fafc;
            transition: all 0.2s ease;
        }

        .account-form-group input:focus,
        .account-form-group select:focus {
            outline: none;
            border-color: #3b82f6;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.12);
        }

        .account-modal-footer {
            display: flex;
            justify-content: flex-end;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }

        .account-btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 28px;
            background: #3b82f6;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .account-btn-primary:hover:not(:disabled) {
            background: #2563eb;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.4);
        }

        .account-btn-primary:active:not(:disabled) {
            transform: translateY(0);
        }

        .account-toast {
            position: fixed;
            top: 24px;
            right: 24px;
            padding: 14px 24px;
            border-radius: 12px;
            color: #ffffff;
            font-weight: 600;
            font-size: 0.9rem;
            z-index: 9999999;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            animation: accountToastIn 0.3s ease-out;
        }

        .account-toast.success { background: #10b981; }
        .account-toast.error { background: #ef4444; }

        @keyframes accountToastIn {
            from { opacity: 0; transform: translateX(100px); }
            to   { opacity: 1; transform: translateX(0); }
        }

        @media (max-width: 768px) {
            .account-modal-body {
                grid-template-columns: 1fr;
                max-height: 90vh;
                overflow-y: auto;
            }
            .account-modal-left {
                border-right: none;
                border-bottom: 1px solid #e2e8f0;
                padding: 24px 20px;
            }
            .account-avatar-wrapper,
            .account-avatar { width: 100px; height: 100px; }
            .account-initials { font-size: 2rem; }
            .account-tabs {
                flex-direction: row;
                flex-wrap: wrap;
                margin-top: 16px;
            }
            .account-tab {
                padding: 8px 12px;
                font-size: 0.8rem;
            }
            .account-form-grid { grid-template-columns: 1fr; }
            .account-modal-right { padding: 24px 20px; }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar no-js" id="sidebar">
        <div class="sidebar-header" onclick="openAccountModal()" style="cursor: pointer;">
            <div class="sidebar-avatar">
                <?php if (!empty($profile_picture_url)): 
                    $relative = str_replace('/tesda_compliance/', '', $profile_picture_url);
                    $physical_path = __DIR__ . '/' . ltrim($relative, '/');
                    $v = file_exists($physical_path) ? filemtime($physical_path) : time();
                    $final_sidebar_pic = $profile_picture_url . '?v=' . $v;
                ?>
                    <img src="<?= htmlspecialchars($final_sidebar_pic) ?>"
                         alt="<?= htmlspecialchars($user_display_name) ?>"
                         onerror="this.style.display='none'; this.parentElement.innerHTML='<span class=\'sidebar-initials\'>' + '<?= htmlspecialchars($initials) ?>' + '</span>';">
                <?php else: ?>
                    <span class="sidebar-initials"><?= htmlspecialchars($initials) ?></span>
                <?php endif; ?>
            </div>
            <div class="sidebar-user-info">
                <h2><?= htmlspecialchars($user_display_name) ?></h2>
                <p>
                    <span class="status-dot-online"></span>
                    <?= htmlspecialchars($user_role_display) ?>
                </p>
            </div>
            <i class="fas fa-chevron-down sidebar-user-chevron"></i>
        </div>

        <div class="sidebar-menu">
            <a href="<?= $base_url ?>dashboard.php"
               class="menu-item <?= $current_page == 'dashboard.php' ? 'active' : '' ?>"
               data-tooltip="Dashboard">
                <i class="fas fa-chart-pie"></i><span>Dashboard</span>
            </a>

            <a href="<?= $base_url ?>trainees.php"
               class="menu-item <?= $current_page == 'trainees.php' ? 'active' : '' ?>"
               data-tooltip="Trainees">
                <i class="fas fa-users"></i><span>Trainees</span>
                <?php if ($pending_count > 0): ?><span class="notification-badge"><?= $pending_count ?></span><?php endif; ?>
            </a>

            <a href="<?= $base_url ?>tasks.php"
               class="menu-item <?= $current_page == 'tasks.php' ? 'active' : '' ?>"
               data-tooltip="Tasks">
                <i class="fas fa-tasks"></i><span>Tasks</span>
            </a>

            <div class="menu-divider"></div>

            <a href="<?= $base_url ?>evaluation.php"
               class="menu-item <?= $current_page == 'evaluation.php' ? 'active' : '' ?>"
               data-tooltip="Evaluation">
                <i class="fas fa-clipboard-check"></i><span>Evaluation</span>
            </a>

            <a href="<?= $base_url ?>pre_assessment.php"
               class="menu-item <?= $current_page == 'pre_assessment.php' ? 'active' : '' ?>"
               data-tooltip="Pre-Assessment">
                <i class="fas fa-check-double"></i><span>Pre-Assessment</span>
                <?php if ($eligible_count > 0): ?><span class="notification-badge" style="background: #10b981;"><?= $eligible_count ?></span><?php endif; ?>
            </a>

            <a href="<?= $base_url ?>progress.php"
               class="menu-item <?= $current_page == 'progress.php' ? 'active' : '' ?>"
               data-tooltip="Compliance Overview">
                <i class="fas fa-chart-line"></i><span>Compliance Overview</span>
            </a>

            <div class="menu-divider"></div>

            <a href="<?= $base_url ?>notified_logs.php"
               class="menu-item <?= $current_page == 'notified_logs.php' ? 'active' : '' ?>"
               data-tooltip="Notified Logs">
                <i class="fas fa-bullhorn"></i><span>Notified Logs</span>
                <?php if ($notified_count > 0): ?><span class="notified-badge"><?= $notified_count ?></span><?php endif; ?>
            </a>

            <a href="<?= $base_url ?>notification_history.php"
               class="menu-item <?= $current_page == 'notification_history.php' ? 'active' : '' ?>"
               data-tooltip="Notifications">
                <i class="fas fa-bell"></i><span>Notifications</span>
                <?php if ($notification_count > 0): ?><span class="notification-badge"><?= $notification_count ?></span><?php endif; ?>
            </a>

            <a href="<?= $base_url ?>competency_standards.php"
               class="menu-item <?= $current_page == 'competency_standards.php' ? 'active' : '' ?>"
               data-tooltip="Competency">
                <i class="fas fa-certificate"></i><span>Competency</span>
            </a>

            <a href="<?= $base_url ?>reports/reports.php"
               class="menu-item <?= $is_reports_page ? 'active' : '' ?>"
               data-tooltip="Reports">
                <i class="fas fa-file-alt"></i><span>Reports</span>
            </a>

            <?php if ($is_coordinator): ?>
            <div class="menu-divider"></div>

            <a href="<?= $base_url ?>users.php"
               class="menu-item <?= $current_page == 'users.php' ? 'active' : '' ?>"
               data-tooltip="Users">
                <i class="fas fa-users-cog"></i><span>Users</span>
            </a>

            <a href="<?= $base_url ?>settings.php"
               class="menu-item <?= $current_page == 'settings.php' ? 'active' : '' ?>"
               data-tooltip="Settings">
                <i class="fas fa-cog"></i><span>Settings</span>
            </a>
            <?php endif; ?>
        </div>

        <div class="logout-footer">
            <a href="<?= $base_url ?>logout.php"
               id="logoutTrigger"
               class="logout-item"
               data-logout-url="<?= $base_url ?>logout.php"
               data-tooltip="Logout">
                <i class="fas fa-sign-out-alt"></i><span>Logout</span>
            </a>
        </div>
    </div>

    <!-- JS TOOLTIP ELEMENT -->
    <div id="sidebar-js-tooltip" role="tooltip"></div>

    <!-- Sidebar Toggle -->
    <button class="sidebar-toggle" id="sidebar-toggle-btn" aria-label="Toggle Sidebar">
        <i class="fas fa-chevron-left" id="toggleIcon"></i>
    </button>

    <!-- Logout Confirmation Modal -->
    <div class="logout-modal-overlay" id="logoutModal">
        <div class="logout-modal-card">
            <div class="logout-modal-icon">
                <i class="fas fa-sign-out-alt"></i>
            </div>
            <h3 class="logout-modal-title">Confirm Logout</h3>
            <p class="logout-modal-text">Are you sure you want to logout?<br>You will need to sign in again to continue.</p>
            <div class="logout-modal-actions">
                <button type="button" class="logout-btn logout-btn-cancel" id="logoutCancel">Cancel</button>
                <button type="button" class="logout-btn logout-btn-confirm" id="logoutConfirm">Yes, Logout</button>
            </div>
        </div>
    </div>

    <!-- ACCOUNT DETAILS MODAL -->
    <div class="account-modal-overlay" id="accountModal">
        <div class="account-modal-card">
            <button class="account-modal-close" onclick="closeAccountModal()" aria-label="Close">&times;</button>

            <div class="account-modal-body">
                <div class="account-modal-left">
                    <div class="account-avatar-wrapper">
                        <div class="account-avatar" id="account_avatar_preview">
                            <?php if (!empty($profile_picture_url)): 
                                $rel = str_replace('/tesda_compliance/', '', $profile_picture_url);
                                $phys = __DIR__ . '/' . ltrim($rel, '/');
                                $v = file_exists($phys) ? filemtime($phys) : time();
                            ?>
                                <img src="<?= htmlspecialchars($profile_picture_url) ?>?v=<?= $v ?>"
                                     alt="<?= htmlspecialchars($user_display_name) ?>"
                                     onerror="this.style.display='none'; this.parentElement.innerHTML='<span class=\'account-initials\'>' + '<?= htmlspecialchars($initials) ?>' + '</span>';">
                            <?php else: ?>
                                <span class="account-initials"><?= htmlspecialchars($initials) ?></span>
                            <?php endif; ?>
                        </div>
                        <label for="account_picture_input" class="account-camera-overlay" title="Change picture">
                            <i class="fas fa-camera"></i>
                        </label>
                        <input type="file" id="account_picture_input" accept="image/*" style="display: none;" onchange="previewAccountPicture(this)">
                    </div>

                    <h3 class="account-name-display"><?= htmlspecialchars($user_display_name) ?></h3>
                    <p class="account-role-display">
                        <span class="status-dot-online"></span>
                        <?= htmlspecialchars($user_role_display) ?>
                    </p>

                    <?php if (!empty($user_qualification_display)): ?>
                        <div class="account-qual-display">
                            <i class="fas fa-graduation-cap"></i>
                            <span><?= htmlspecialchars($user_qualification_display) ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="account-tabs">
                        <button type="button" class="account-tab active" onclick="switchAccountTab(event, 'details')" data-tab="details">
                            <i class="fas fa-user"></i> Account Details
                        </button>
                        <button type="button" class="account-tab" onclick="switchAccountTab(event, 'security')" data-tab="security">
                            <i class="fas fa-shield-alt"></i> Security
                        </button>
                    </div>
                </div>

                <div class="account-modal-right">
                    <div class="account-tab-content active" data-tab-content="details">
                        <h3 class="account-panel-title">Account Details</h3>
                        <p class="account-panel-subtitle">Update your personal information</p>

                        <form method="POST" enctype="multipart/form-data" id="accountUpdateForm">
                            <input type="hidden" name="update_own_account" value="1">

                            <div class="account-form-grid">
                                <div class="account-form-group full-width">
                                    <label>Full Name</label>
                                    <input type="text" name="full_name" value="<?= htmlspecialchars($user_display_name) ?>" required>
                                </div>
                                <div class="account-form-group">
                                    <label>Username</label>
                                    <input type="text" value="<?= htmlspecialchars($user['username'] ?? '') ?>" disabled style="background:#f1f5f9; cursor: not-allowed;">
                                </div>
                                <div class="account-form-group">
                                    <label>Role</label>
                                    <input type="text" value="<?= htmlspecialchars($user_role_display) ?>" disabled style="background:#f1f5f9; cursor: not-allowed;">
                                </div>
                                <div class="account-form-group full-width">
                                    <label>Email Address</label>
                                    <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required>
                                </div>
                                <div class="account-form-group full-width">
                                    <label>Contact Number</label>
                                    <input type="text" name="contact_number" value="<?= htmlspecialchars($user['contact_number'] ?? '') ?>" placeholder="09123456789">
                                </div>
                            </div>

                            <div class="account-modal-footer">
                                <button type="submit" class="account-btn-primary">
                                    <i class="fas fa-save"></i> Update
                                </button>
                            </div>
                        </form>
                    </div>

                    <div class="account-tab-content" data-tab-content="security">
                        <h3 class="account-panel-title">Security</h3>
                        <p class="account-panel-subtitle">Manage your password and security settings</p>

                        <form method="POST" id="accountPasswordForm">
                            <input type="hidden" name="update_own_password" value="1">

                            <div class="account-form-grid">
                                <div class="account-form-group full-width">
                                    <label>Current Password</label>
                                    <input type="password" name="current_password" required minlength="6" placeholder="Enter current password">
                                </div>
                                <div class="account-form-group full-width">
                                    <label>New Password</label>
                                    <input type="password" name="new_password" required minlength="6" placeholder="Enter new password">
                                </div>
                                <div class="account-form-group full-width">
                                    <label>Confirm New Password</label>
                                    <input type="password" name="confirm_password" required minlength="6" placeholder="Re-enter new password">
                                </div>
                            </div>

                            <div class="account-modal-footer">
                                <button type="submit" class="account-btn-primary">
                                    <i class="fas fa-key"></i> Change Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SIDEBAR LOGIC -->
    <script>
        (function() {
            'use strict';

            var STORAGE_KEY = 'sidebar-collapsed';
            var SCROLL_KEY  = 'tesda_sidebar_scroll';

            function isCollapsed() {
                try { return localStorage.getItem(STORAGE_KEY) === 'true'; }
                catch (e) { return false; }
            }

            function applyState(collapsed) {
                var sidebar = document.getElementById('sidebar');
                var toggleIcon = document.getElementById('toggleIcon');
                if (!sidebar) return;

                if (collapsed) {
                    document.documentElement.classList.add('sidebar-is-collapsed');
                    sidebar.classList.add('collapsed');
                    document.body.classList.add('sidebar-collapsed');
                    if (toggleIcon) toggleIcon.className = 'fas fa-chevron-right';
                } else {
                    document.documentElement.classList.remove('sidebar-is-collapsed');
                    sidebar.classList.remove('collapsed');
                    document.body.classList.remove('sidebar-collapsed');
                    if (toggleIcon) toggleIcon.className = 'fas fa-chevron-left';
                }
            }

            applyState(isCollapsed());

            document.addEventListener('DOMContentLoaded', function() {
                var sidebar = document.getElementById('sidebar');
                var toggleBtn = document.getElementById('sidebar-toggle-btn');

                if (!sidebar) return;

                applyState(isCollapsed());
                document.documentElement.classList.remove('sidebar-init-hidden');

                try {
                    var savedScroll = sessionStorage.getItem(SCROLL_KEY);
                    if (savedScroll !== null) sidebar.scrollTop = parseInt(savedScroll, 10) || 0;
                } catch (e) {}

                sidebar.addEventListener('scroll', function() {
                    clearTimeout(this._scrollTimeout);
                    this._scrollTimeout = setTimeout(function() {
                        try { sessionStorage.setItem(SCROLL_KEY, String(sidebar.scrollTop)); } catch (e) {}
                    }, 100);
                });

                if (toggleBtn) {
                    toggleBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();

                        var nowCollapsed = !sidebar.classList.contains('collapsed');
                        applyState(nowCollapsed);

                        try { localStorage.setItem(STORAGE_KEY, String(nowCollapsed)); }
                        catch (err) {}
                    });
                }

                document.querySelectorAll('.sidebar .menu-item').forEach(function(link) {
                    link.addEventListener('click', function(e) {
                        e.stopPropagation();
                        document.querySelectorAll('.sidebar .menu-item').forEach(function(l) {
                            l.classList.remove('active');
                        });
                        this.classList.add('active');
                    });
                });

                setTimeout(function() { applyState(isCollapsed()); }, 300);
                setTimeout(function() { applyState(isCollapsed()); }, 1000);

                window.addEventListener('storage', function(e) {
                    if (e.key === STORAGE_KEY) applyState(e.newValue === 'true');
                });
            });

            window.addEventListener('load', function() {
                applyState(isCollapsed());
                document.documentElement.classList.remove('sidebar-init-hidden');
            });
        })();

        /* ========================================== */
        /* JS TOOLTIP LOGIC — only when collapsed     */
        /* ========================================== */
        (function() {
            'use strict';

            var tooltipEl = document.getElementById('sidebar-js-tooltip');
            var sidebar = document.getElementById('sidebar');
            if (!tooltipEl || !sidebar) return;

            function isCollapsed() {
                return sidebar.classList.contains('collapsed') ||
                       document.documentElement.classList.contains('sidebar-is-collapsed');
            }

            function showTooltip(target) {
                if (!isCollapsed()) return;

                var text = target.getAttribute('data-tooltip');
                if (!text) return;

                tooltipEl.textContent = text;
                tooltipEl.classList.add('visible');

                var rect = target.getBoundingClientRect();
                var tooltipRect = tooltipEl.getBoundingClientRect();

                var top = rect.top + (rect.height / 2) - (tooltipRect.height / 2);
                var left = rect.right + 12;

                if (top < 8) top = 8;
                if (top + tooltipRect.height > window.innerHeight - 8) {
                    top = window.innerHeight - tooltipRect.height - 8;
                }

                tooltipEl.style.top = top + 'px';
                tooltipEl.style.left = left + 'px';
            }

            function hideTooltip() {
                tooltipEl.classList.remove('visible');
            }

            var items = sidebar.querySelectorAll('.menu-item, .logout-item');

            items.forEach(function(item) {
                item.addEventListener('mouseenter', function() {
                    showTooltip(item);
                });

                item.addEventListener('mouseleave', function() {
                    hideTooltip();
                });
            });

            sidebar.addEventListener('scroll', hideTooltip);
            window.addEventListener('resize', hideTooltip);
        })();

        /* LOGOUT MODAL */
        (function() {
            'use strict';

            var trigger = document.getElementById('logoutTrigger');
            var modal   = document.getElementById('logoutModal');
            var cancelBtn  = document.getElementById('logoutCancel');
            var confirmBtn = document.getElementById('logoutConfirm');

            if (!trigger || !modal) return;

            function openLogoutModal(e) {
                if (e) e.preventDefault();
                e.stopPropagation();
                modal.classList.add('active');
            }

            function closeLogoutModal() {
                modal.classList.remove('active');
            }

            function performLogout() {
                var logoutUrl = trigger.getAttribute('data-logout-url') || 'logout.php';

                try {
                    sessionStorage.setItem('tesda_logout_flag', '1');
                    sessionStorage.removeItem('tesda_tab_active');
                } catch (e) {}

                window.location.href = logoutUrl;
            }

            trigger.addEventListener('click', openLogoutModal);

            if (cancelBtn) cancelBtn.addEventListener('click', closeLogoutModal);
            if (confirmBtn) confirmBtn.addEventListener('click', performLogout);

            modal.addEventListener('click', function(e) {
                if (e.target === modal) closeLogoutModal();
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('active')) {
                    closeLogoutModal();
                }
            });
        })();

        /* ACCOUNT MODAL */
        (function() {
            'use strict';

            var modal = document.getElementById('accountModal');
            if (!modal) return;

            window.openAccountModal = function() {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            };

            window.closeAccountModal = function() {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            };

            modal.addEventListener('click', function(e) {
                if (e.target === modal) closeAccountModal();
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('active')) {
                    closeAccountModal();
                }
            });

            window.switchAccountTab = function(e, tabName) {
                if (e) {
                    e.preventDefault();
                    e.stopPropagation();
                }

                document.querySelectorAll('.account-tab').forEach(function(t) {
                    t.classList.remove('active');
                });
                if (e && e.currentTarget) {
                    e.currentTarget.classList.add('active');
                } else {
                    var target = document.querySelector('.account-tab[data-tab="' + tabName + '"]');
                    if (target) target.classList.add('active');
                }

                document.querySelectorAll('.account-tab-content').forEach(function(c) {
                    c.classList.remove('active');
                });
                var content = document.querySelector('.account-tab-content[data-tab-content="' + tabName + '"]');
                if (content) content.classList.add('active');
            };

            window.previewAccountPicture = function(input) {
                if (!input.files || !input.files[0]) return;

                var file = input.files[0];

                var allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
                if (allowed.indexOf(file.type) === -1) {
                    showAccountToast('Invalid file type. JPG, PNG, GIF, WEBP only.', 'error');
                    input.value = '';
                    return;
                }

                if (file.size > 2 * 1024 * 1024) {
                    showAccountToast('File too large. Max 2MB.', 'error');
                    input.value = '';
                    return;
                }

                var reader = new FileReader();
                reader.onload = function(e) {
                    var preview = document.getElementById('account_avatar_preview');
                    preview.innerHTML = '<img src="' + e.target.result + '" alt="Preview">';
                };
                reader.readAsDataURL(file);
            };

            function showAccountToast(message, type) {
                var existing = document.querySelector('.account-toast');
                if (existing) existing.remove();

                var toast = document.createElement('div');
                toast.className = 'account-toast ' + (type || 'success');
                toast.innerHTML = '<i class="fas ' + (type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle') + '"></i> ' + message;
                document.body.appendChild(toast);

                setTimeout(function() {
                    if (toast.parentNode) toast.parentNode.removeChild(toast);
                }, 4000);
            }

            window.showAccountToast = showAccountToast;

            var updateForm = document.getElementById('accountUpdateForm');
            if (updateForm) {
                updateForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    var submitBtn = updateForm.querySelector('.account-btn-primary');
                    var originalText = submitBtn.innerHTML;
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

                    var formData = new FormData(updateForm);

                    var fileInput = document.getElementById('account_picture_input');
                    if (fileInput && fileInput.files && fileInput.files[0]) {
                        formData.append('profile_picture_file', fileInput.files[0]);
                    }

                    fetch('<?= $base_url ?>update_account.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            showAccountToast(data.message || 'Account updated successfully!', 'success');
                            setTimeout(function() { window.location.reload(); }, 1200);
                        } else {
                            showAccountToast(data.message || 'Update failed', 'error');
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalText;
                        }
                    })
                    .catch(function(err) {
                        showAccountToast('Server error. Please try again.', 'error');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    });
                });
            }

            var passwordForm = document.getElementById('accountPasswordForm');
            if (passwordForm) {
                passwordForm.addEventListener('submit', function(e) {
                    e.preventDefault();

                    var submitBtn = passwordForm.querySelector('.account-btn-primary');
                    var originalText = submitBtn.innerHTML;
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';

                    var formData = new FormData(passwordForm);

                    fetch('<?= $base_url ?>update_account.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) {
                            showAccountToast(data.message || 'Password changed successfully!', 'success');
                            passwordForm.reset();
                        } else {
                            showAccountToast(data.message || 'Password change failed', 'error');
                        }
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    })
                    .catch(function(err) {
                        showAccountToast('Server error. Please try again.', 'error');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalText;
                    });
                });
            }
        })();
    </script>

    <script src="<?= $base_url ?>assets/js/session-timeout.js"></script>
    <script src="/tesda_compliance/assets/js/seamless-nav.js"></script>
    <script src="/tesda_compliance/assets/js/jquery-3.6.0.min.js"></script>
    <script src="/tesda_compliance/assets/js/bootstrap.bundle.min.js"></script>
    <script src="/tesda_compliance/assets/js/app.js"></script>

    <script src="<?= $base_url ?>assets/js/auto-logout.js"></script>

    <script>
    (function () {
        'use strict';
        var HEARTBEAT_INTERVAL = 15000;
        var HEARTBEAT_URL = '<?= $base_url ?>heartbeat.php';

        function beat() {
            if (document.hidden) return;
            try {
                fetch(HEARTBEAT_URL, {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }).then(function (r) { return r.json(); })
                  .then(function (data) {
                      if (data && data.success === false &&
                          data.reason === 'not_logged_in') {
                          window.location.replace('<?= $base_url ?>login.php');
                      }
                  })
                  .catch(function () {});
            } catch (e) {}
        }

        beat();
        setInterval(beat, HEARTBEAT_INTERVAL);

        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) beat();
        });
    })();
    </script>
</body>
</html>