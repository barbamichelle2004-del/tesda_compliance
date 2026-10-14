<?php
// ============================================================
// 🔒 PREVENT LOADING THIS FILE TWICE
// ============================================================
if (defined('CONFIG_LOADED')) {
    return;
}
define('CONFIG_LOADED', true);

// ============================================================
// 🐛 ERROR REPORTING
// Shows errors in dev, hides them in production
// ============================================================
$__is_dev = false;
if (isset($_SERVER['HTTP_HOST']) && (
    strpos($_SERVER['HTTP_HOST'], 'localhost') !== false ||
    strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false
)) {
    $__is_dev = true;
}

if ($__is_dev) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
}
ini_set('log_errors', '1');

// ============================================================
// 🔑 SESSION START
// Starts the login session (only ONE time for whole system)
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        session_name('APP_SESSION_ID');

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }
}

// ============================================================
// 🕐 TIMEZONE
// Sets Philippine timezone
// ============================================================
date_default_timezone_set('Asia/Manila');

// ============================================================
// 📂 ENV LOADER CLASS
// Reads the .env file (DB passwords, API keys, etc.)
// ============================================================
if (!class_exists('EnvLoader')) {
    class EnvLoader
    {
        private static $env = [];
        private static $loaded = false;

        // 📖 Load the .env file and parse each line
        public static function load($filePath = null)
        {
            if (self::$loaded) {
                return self::$env;
            }

            if ($filePath === null) {
                $filePath = __DIR__ . '/../.env';
            }

            if (!file_exists($filePath)) {
                $alternatePaths = [
                    __DIR__ . '/.env',
                    dirname(__DIR__) . '/.env',
                ];
                foreach ($alternatePaths as $alt) {
                    if (file_exists($alt)) {
                        $filePath = $alt;
                        break;
                    }
                }
            }

            if (file_exists($filePath)) {
                $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($lines !== false) {
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (strpos($line, '#') === 0 || empty($line)) {
                            continue;
                        }
                        $parts = explode('=', $line, 2);
                        if (count($parts) === 2) {
                            $key = trim($parts[0]);
                            $value = trim($parts[1]);
                            if (strlen($value) >= 2) {
                                if (($value[0] === '"' && $value[strlen($value) - 1] === '"') ||
                                    ($value[0] === "'" && $value[strlen($value) - 1] === "'")) {
                                    $value = substr($value, 1, -1);
                                }
                            }
                            self::$env[$key] = $value;
                            $_ENV[$key] = $value;
                            putenv("$key=$value");
                        }
                    }
                }
            }

            self::$loaded = true;
            return self::$env;
        }

        // 🔎 Get a value from .env by its key name
        public static function get($key, $default = null)
        {
            if (!self::$loaded) {
                self::load();
            }
            $val = self::$env[$key] ?? $_ENV[$key] ?? getenv($key);
            return ($val !== false && $val !== null && $val !== '') ? $val : $default;
        }

        // 🧪 Check if we are in dev mode
        public static function isDevelopment()
        {
            return self::get('APP_ENV', 'development') === 'development';
        }
    }
}

EnvLoader::load();

// ============================================================
// 🗄️ DATABASE CLASS
// Handles all MySQL connection + queries (Singleton pattern)
// ============================================================
if (!class_exists('Database')) {
    class Database
    {
        private static $instance = null;
        private static $config = [];
        private static $initialized = false;

        // ⚙️ Load DB credentials from .env
        private static function init()
        {
            if (self::$initialized) {
                return;
            }

            self::$config = [
                'host'    => EnvLoader::get('DB_HOST', '127.0.0.1'),
                'port'    => EnvLoader::get('DB_PORT', '3306'),
                'name'    => EnvLoader::get('DB_NAME', 'tesda_compliance'),
                'user'    => EnvLoader::get('DB_USER', 'root'),
                'pass'    => EnvLoader::get('DB_PASS', ''),
                'charset' => EnvLoader::get('DB_CHARSET', 'utf8mb4'),
            ];

            if (self::$config['host'] === 'localhost') {
                self::$config['host'] = '127.0.0.1';
            }

            self::$initialized = true;
        }

        // 📋 Return DB config array
        public static function getConfig()
        {
            self::init();
            return self::$config;
        }

        // 🔌 Create / return ONE PDO connection (reused everywhere)
        public static function getConnection()
        {
            self::init();

            if (self::$instance !== null) {
                return self::$instance;
            }

            $host    = self::$config['host'];
            $port    = self::$config['port'];
            $dbname  = self::$config['name'];
            $user    = self::$config['user'];
            $pass    = self::$config['pass'];
            $charset = self::$config['charset'];

            try {
                $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=$charset";

                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES $charset COLLATE utf8mb4_unicode_ci",
                ];

                self::$instance = new PDO($dsn, $user, $pass, $options);
                return self::$instance;
            } catch (PDOException $e) {
                if (EnvLoader::isDevelopment()) {
                    throw new RuntimeException('Database connection failed: ' . $e->getMessage());
                } else {
                    error_log('Database connection failed: ' . $e->getMessage());
                    throw new RuntimeException('Database connection failed. Please check your configuration.');
                }
            }
        }

        // ▶️ Run a SQL query with prepared params
        public static function query($sql, $params = [])
        {
            $db = self::getConnection();
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        }

        // ▶️ Alias for query()
        public static function execute($sql, $params = [])
        {
            return self::query($sql, $params);
        }

        // 1️⃣ Fetch a SINGLE row
        public static function fetchOne($sql, $params = [])
        {
            $stmt = self::query($sql, $params);
            return $stmt->fetch() ?: null;
        }

        // 📚 Fetch MULTIPLE rows
        public static function fetchAll($sql, $params = [])
        {
            $stmt = self::query($sql, $params);
            return $stmt->fetchAll() ?: [];
        }

        // 🔢 Fetch a SINGLE column value
        public static function fetchColumn($sql, $params = [])
        {
            $stmt = self::query($sql, $params);
            return $stmt->fetchColumn();
        }

        // ➕ INSERT a row using array data
        public static function insert($table, $data)
        {
            $fields = array_keys($data);
            $placeholders = array_fill(0, count($fields), '?');
            $sql = "INSERT INTO $table (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
            self::query($sql, array_values($data));
            return self::getConnection()->lastInsertId();
        }

        // ✏️ UPDATE rows using array data
        public static function update($table, $data, $where, $whereParams = [])
        {
            $sets = [];
            $params = [];
            foreach ($data as $field => $value) {
                $sets[] = "$field = ?";
                $params[] = $value;
            }
            $params = array_merge($params, $whereParams);
            $sql = "UPDATE $table SET " . implode(', ', $sets) . " WHERE $where";
            $stmt = self::query($sql, $params);
            return $stmt->rowCount();
        }

        // 🗑️ DELETE rows
        public static function delete($table, $where, $params = [])
        {
            $sql = "DELETE FROM $table WHERE $where";
            $stmt = self::query($sql, $params);
            return $stmt->rowCount();
        }

        // 🔓 Start a DB transaction
        public static function beginTransaction()
        {
            self::getConnection()->beginTransaction();
        }

        // ✅ Save the transaction
        public static function commit()
        {
            self::getConnection()->commit();
        }

        // ❌ Undo the transaction
        public static function rollBack()
        {
            self::getConnection()->rollBack();
        }

        // ❓ Check if inside a transaction
        public static function inTransaction()
        {
            return self::getConnection()->inTransaction();
        }

        // 🔑 Get the ID of the last inserted row
        public static function lastInsertId()
        {
            return self::getConnection()->lastInsertId();
        }

        // 🛡️ Escape special chars for LIKE queries
        public static function escapeLike($string)
        {
            return addcslashes($string, '%_\\');
        }

        // 🔄 Reset the DB connection
        public static function reset()
        {
            self::$instance = null;
            self::$initialized = false;
        }
    }
}

// ============================================================
// 🌍 GLOBAL $pdo VARIABLE
// Creates the main PDO connection used by all pages
// ============================================================
try {
    $pdo = Database::getConnection();
    $GLOBALS['pdo'] = $pdo;
} catch (RuntimeException $e) {
    if (EnvLoader::isDevelopment()) {
        die($e->getMessage());
    } else {
        die('Database connection failed. Please check your configuration.');
    }
}

$conn = $pdo;
$GLOBALS['conn'] = $conn;
$GLOBALS['db']   = $pdo;

// ============================================================
// 📧 LOAD OPTIONAL SERVICES (Email + SMS)
// Only loads if files exist
// ============================================================
if (file_exists(__DIR__ . '/MailService.php')) {
    require_once __DIR__ . '/MailService.php';
}
if (file_exists(__DIR__ . '/SMSService.php')) {
    require_once __DIR__ . '/SMSService.php';
}

// ============================================================
// 🧩 LOAD HELPER FILES
// Custom helper classes (CSRF, Auth, SMS, Pusher, WebSocket)
// ============================================================
$__helpers_dir = dirname(__DIR__) . '/helpers';

$__helper_files = [
    'CsrfHelper.php',
    'AuthHelper.php',
    'PhilSmsHelper.php',
    'PusherHelper.php',
    'WebSocketHelper.php',
];

foreach ($__helper_files as $__helper_file) {
    $__helper_path = $__helpers_dir . '/' . $__helper_file;
    if (file_exists($__helper_path)) {
        require_once $__helper_path;
    }
}
unset($__helpers_dir, $__helper_files, $__helper_file, $__helper_path);

// ============================================================
// 🏷️ APP CONSTANTS
// App name, URL, environment, debug mode
// ============================================================
if (!defined('APP_NAME'))  define('APP_NAME',  EnvLoader::get('APP_NAME',  'TESDA Compliance System'));
if (!defined('APP_URL'))   define('APP_URL',   EnvLoader::get('APP_URL',   'http://localhost/tesda_compliance'));
if (!defined('APP_ENV'))   define('APP_ENV',   EnvLoader::get('APP_ENV',   'development'));
if (!defined('APP_DEBUG')) define('APP_DEBUG', EnvLoader::get('APP_DEBUG', 'true') === 'true');

// ============================================================
// 📧 SMTP CONSTANTS
// Email server settings (Gmail, etc.)
// ============================================================
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST',       EnvLoader::get('SMTP_HOST', 'smtp.gmail.com'));
    define('SMTP_PORT',       (int)EnvLoader::get('SMTP_PORT', 587));
    define('SMTP_USERNAME',   EnvLoader::get('SMTP_USERNAME', ''));
    define('SMTP_PASSWORD',   EnvLoader::get('SMTP_PASSWORD', ''));
    define('SMTP_FROM_EMAIL', EnvLoader::get('SMTP_FROM_EMAIL', ''));
    define('SMTP_FROM_NAME',  EnvLoader::get('SMTP_FROM_NAME', 'TESDA Compliance System'));
    define('SMTP_ENCRYPTION', EnvLoader::get('SMTP_ENCRYPTION', 'tls'));
    define('SMTP_DEBUG',      (int)EnvLoader::get('SMTP_DEBUG', 0));
}

// ============================================================
// 📱 PHILSMS CONSTANTS
// SMS API settings
// ============================================================
if (!defined('PHILSMS_API_TOKEN')) {
    define('PHILSMS_API_TOKEN', EnvLoader::get('PHILSMS_API_TOKEN', ''));
    define('PHILSMS_SENDER_ID', EnvLoader::get('PHILSMS_SENDER_ID', 'PhilSMS'));
    define('PHILSMS_API_URL',   EnvLoader::get('PHILSMS_API_URL', 'https://dashboard.philsms.com/api/v3/sms/send'));
}

if (!defined('SMS_API_KEY')) {
    define('SMS_API_KEY',   PHILSMS_API_TOKEN);
    define('SMS_SENDER_NAME', PHILSMS_SENDER_ID);
    define('SMS_API_URL',   PHILSMS_API_URL);
}

// ============================================================
// 🔑 SESSION CONSTANTS
// Session timeout + cookie name
// ============================================================
if (!defined('SESSION_TIMEOUT')) {
    define('SESSION_TIMEOUT', (int)EnvLoader::get('SESSION_TIMEOUT', 300));
    define('SESSION_NAME',    EnvLoader::get('SESSION_NAME', 'APP_SESSION_ID'));
}

// ============================================================
// 📡 PUSHER CONSTANTS
// Cloud real-time broadcasting credentials
// ============================================================
if (!defined('PUSHER_APP_ID')) {
    define('PUSHER_APP_ID',  EnvLoader::get('PUSHER_APP_ID',  ''));
    define('PUSHER_KEY',     EnvLoader::get('PUSHER_KEY',     ''));
    define('PUSHER_SECRET',  EnvLoader::get('PUSHER_SECRET',  ''));
    define('PUSHER_CLUSTER', EnvLoader::get('PUSHER_CLUSTER', 'ap1'));
}

// ============================================================
// 🌐 getBaseUrl() — Get the base URL of the system
// Example: http://localhost/tesda_compliance/
// ============================================================
if (!function_exists('getBaseUrl')) {
    function getBaseUrl() {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script_name = $_SERVER['SCRIPT_NAME'] ?? '/';
        $base_path = rtrim(dirname($script_name), '/') . '/';

        if (strpos($base_path, '/reports/') !== false) {
            $base_path = str_replace('/reports/', '/', $base_path);
        }
        return $protocol . $host . $base_path;
    }
}

// ============================================================
// 🔐 isLoggedIn() — Check if user is logged in
// Returns true if $_SESSION['user_id'] exists
// ============================================================
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
}

// ============================================================
// 👤 getCurrentUser() — Get the logged-in user's data
// Returns user row from database
// ============================================================
if (!function_exists('getCurrentUser')) {
    function getCurrentUser() {
        if (!isset($_SESSION['user_id'])) return null;

        try {
            return Database::fetchOne("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);
        } catch (RuntimeException $e) {
            error_log("Error getting current user: " . $e->getMessage());
            return null;
        }
    }
}

// ============================================================
// 🚀 redirect() — Send user to another page
// Optional: store an error message in session
// ============================================================
if (!function_exists('redirect')) {
    function redirect($url, $message = null) {
        if ($message) {
            $_SESSION['error_message'] = $message;
        }
        if (!headers_sent()) {
            header("Location: " . $url);
        } else {
            echo "<script>window.location.href='" . addslashes($url) . "';</script>";
        }
        exit();
    }
}

// ============================================================
// 🎓 isTrainer() — Check if logged-in user is a TRAINER
// ============================================================
if (!function_exists('isTrainer')) {
    function isTrainer() {
        return isset($_SESSION['role']) && strtolower($_SESSION['role']) === 'trainer';
    }
}

// ============================================================
// 👔 isCoordinator() — Check if logged-in user is COORDINATOR / ADMIN
// ============================================================
if (!function_exists('isCoordinator')) {
    function isCoordinator() {
        return isset($_SESSION['role']) &&
               (strtolower($_SESSION['role']) === 'coordinator' ||
                strtolower($_SESSION['role']) === 'admin');
    }
}

// ============================================================
// 📚 getTrainerQualificationIds() — Get the qualifications assigned to a trainer
// Returns an array of qualification IDs
// ============================================================
if (!function_exists('getTrainerQualificationIds')) {
    function getTrainerQualificationIds($user_id) {
        try {
            $check = Database::query("SHOW TABLES LIKE 'user_qualifications'");
            if ($check->rowCount() == 0) {
                return [];
            }

            $result = Database::fetchAll(
                "SELECT qualification_id FROM user_qualifications WHERE user_id = ?",
                [$user_id]
            );
            return array_column($result, 'qualification_id');
        } catch (RuntimeException $e) {
            error_log("Error getting trainer qualifications: " . $e->getMessage());
            return [];
        }
    }
}

// ============================================================
// 🧠 getUserAccessContext() — Get current user's role + permissions
// Used for RBAC (Role-Based Access Control)
// ============================================================
if (!function_exists('getUserAccessContext')) {
    function getUserAccessContext() {
        $user_id = $_SESSION['user_id'] ?? 0;
        $qualification_ids = $_SESSION['qualification_ids'] ?? [];

        if (empty($qualification_ids) && isTrainer() && $user_id > 0) {
            $qualification_ids = getTrainerQualificationIds($user_id);
            $_SESSION['qualification_ids'] = $qualification_ids;
        }

        return [
            'role'              => $_SESSION['role'] ?? '',
            'department'        => $_SESSION['department'] ?? '',
            'qualification_ids' => $qualification_ids,
            'is_coordinator'    => isCoordinator(),
            'is_trainer'        => isTrainer()
        ];
    }
}

// ============================================================
// 🚫 requireCoordinatorAccess() — Block trainers from coordinator-only pages
// Redirects to dashboard if not coordinator
// ============================================================
if (!function_exists('requireCoordinatorAccess')) {
    function requireCoordinatorAccess() {
        if (!isset($_SESSION['user_id'])) {
            redirect('login.php');
        }
        if (isTrainer()) {
            $_SESSION['error_message'] = "Access Denied: You don't have permission to access this page.";
            redirect('dashboard.php');
        }
    }
}

// ============================================================
// ✅ hasQualificationAccess() — Check if user can access a qualification
// Coordinators = always true; Trainers = only their assigned quals
// ============================================================
if (!function_exists('hasQualificationAccess')) {
    function hasQualificationAccess($qualification_id) {
        if (isCoordinator()) return true;
        if (!isTrainer()) return false;
        $qual_ids = $_SESSION['qualification_ids'] ?? [];
        return in_array($qualification_id, $qual_ids);
    }
}

// ============================================================
// 🛡️ sanitize() — Clean user input (XSS protection)
// ============================================================
if (!function_exists('sanitize')) {
    function sanitize($input) {
        if (is_null($input)) return '';
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}

// ============================================================
// 🎨 getDepartmentColor() — Get color code for a department
// ============================================================
if (!function_exists('getDepartmentColor')) {
    function getDepartmentColor($code) {
        $colors = ['HRS' => '#8b5cf6', 'CSS' => '#10b981', 'ATS' => '#f59e0b'];
        return $colors[$code] ?? '#6b7280';
    }
}

// ============================================================
// 🖼️ getDepartmentIcon() — Get Font Awesome icon for a department
// ============================================================
if (!function_exists('getDepartmentIcon')) {
    function getDepartmentIcon($code) {
        $icons = ['HRS' => 'fa-hotel', 'CSS' => 'fa-laptop-code', 'ATS' => 'fa-cogs'];
        return $icons[$code] ?? 'fa-building';
    }
}

// ============================================================
// 📛 getDepartmentName() — Get full name of a department
// ============================================================
if (!function_exists('getDepartmentName')) {
    function getDepartmentName($code) {
        $names = [
            'HRS' => 'Hotel and Restaurant Services',
            'CSS' => 'Computer Systems Servicing',
            'ATS' => 'Automotive Mechanics'
        ];
        return $names[$code] ?? $code;
    }
}

// ============================================================
// 👤 getUserFullName() — Get the logged-in user's full name
// ============================================================
if (!function_exists('getUserFullName')) {
    function getUserFullName() {
        $user = getCurrentUser();
        return $user['full_name'] ?? $user['username'] ?? 'User';
    }
}

// ============================================================
// ✔️ isUserActive() — Check if user's status is "active"
// ============================================================
if (!function_exists('isUserActive')) {
    function isUserActive() {
        $user = getCurrentUser();
        return isset($user['status']) && strtolower($user['status']) === 'active';
    }
}

// ============================================================
// 🐞 debug() — Print data for debugging (only when APP_DEBUG = true)
// ============================================================
if (!function_exists('debug')) {
    function debug($data) {
        if (APP_DEBUG) {
            echo '<pre style="background:#1e293b;color:#e2e8f0;padding:15px;border-radius:8px;font-family:monospace;font-size:13px;overflow-x:auto;margin:10px 0;">';
            print_r($data);
            echo '</pre>';
        }
    }
}

// ============================================================
// 🚫 send_no_cache_headers() — Prevent browser caching pages
// Forces fresh reload every time (security)
// ============================================================
if (!function_exists('send_no_cache_headers')) {
    function send_no_cache_headers(): void {
        if (headers_sent()) return;
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");
        header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
    }
}

// ============================================================
// 🔐 verify_session_token() — Compare session token vs DB token
// Prevents session hijacking
// ============================================================
if (!function_exists('verify_session_token')) {
    function verify_session_token(): bool {
        if (empty($_SESSION['user_id'])) return false;

        $dbToken    = '';
        $localToken = $_SESSION['active_session_id'] ?? '';

        try {
            $row = Database::fetchOne(
                "SELECT active_session_id FROM users WHERE id = ? LIMIT 1",
                [$_SESSION['user_id']]
            );
            $dbToken = $row['active_session_id'] ?? '';
        } catch (\Throwable $e) {
            error_log("verify_session_token error: " . $e->getMessage());
            return false;
        }

        if ($dbToken === '' || $localToken === '' || !hash_equals($dbToken, $localToken)) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            return false;
        }
        return true;
    }
}

// ============================================================
// 🧭 emit_guard_javascript() — Inject JS to block back-button cache
// ============================================================
if (!function_exists('emit_guard_javascript')) {
    function emit_guard_javascript(string $mode): void {
        if (!empty($GLOBALS['__nav_guard_js_emitted'])) return;
        $GLOBALS['__nav_guard_js_emitted'] = true;
        $modeJson = json_encode($mode);
        ?>
        <script>
        (function () {
            var MODE = <?= $modeJson ?>;

            // Push a sentinel so the first BACK press hits us.
            try {
                history.pushState({ guard: MODE, t: Date.now() }, '', location.href);
            } catch (e) {}

            // If page comes from bfcache (BACK/FORWARD), force a
            // fresh server round-trip with a cache-buster query.
            window.addEventListener('pageshow', function (event) {
                if (event.persisted) {
                    var sep = location.href.indexOf('?') === -1 ? '?' : '&';
                    location.replace(location.href + sep + '_=' + Date.now());
                }
            });

            // On BACK/FORWARD, re-ask the server.
            window.addEventListener('popstate', function () {
                var sep = location.href.indexOf('?') === -1 ? '?' : '&';
                location.replace(location.href + sep + '_=' + Date.now());
            });
        })();
        </script>
        <?php
    }
}

// ============================================================
// 🛡️ enforce_navigation_guard() — Master function for page protection
// Combines cache headers + token check + JS guard
// Usage: enforce_navigation_guard('auth') — requires login
//        enforce_navigation_guard('guest') — for login page only
// ============================================================
if (!function_exists('enforce_navigation_guard')) {
    function enforce_navigation_guard(string $mode = 'auth',
                                      string $loginUrl = 'login.php',
                                      string $dashboardUrl = 'dashboard.php'): void {
        if (!empty($GLOBALS['__nav_guard_done'])) return;
        $GLOBALS['__nav_guard_done'] = true;

        send_no_cache_headers();

        $loggedIn = !empty($_SESSION['user_id']) && verify_session_token();

        if ($mode === 'guest') {
            if ($loggedIn) {
                header("Location: " . $dashboardUrl);
                exit();
            }
            emit_guard_javascript('guest');
            return;
        }

        if ($mode === 'auth') {
            if (!$loggedIn) {
                header("Location: " . $loginUrl);
                exit();
            }
            emit_guard_javascript('auth');
            return;
        }
    }
}

// ============================================================
// 📦 $config ARRAY — All settings in ONE array
// Used by pages that need config values (e.g., MailService)
// ============================================================
$__db_config = Database::getConfig();

$config = [
    // 🏷️ App settings
    'app' => [
        'env'   => APP_ENV,
        'debug' => APP_DEBUG,
        'name'  => APP_NAME,
        'url'   => APP_URL,
    ],
    // 🗄️ Database settings
    'database' => [
        'host'    => $__db_config['host']    ?? EnvLoader::get('DB_HOST', '127.0.0.1'),
        'port'    => $__db_config['port']    ?? EnvLoader::get('DB_PORT', '3306'),
        'name'    => $__db_config['name']    ?? EnvLoader::get('DB_NAME'),
        'user'    => $__db_config['user']    ?? EnvLoader::get('DB_USER'),
        'pass'    => $__db_config['pass']    ?? EnvLoader::get('DB_PASS'),
        'charset' => $__db_config['charset'] ?? EnvLoader::get('DB_CHARSET', 'utf8mb4'),
    ],
    // 📧 Email settings
    'mail' => [
        'host'       => SMTP_HOST,
        'port'       => SMTP_PORT,
        'username'   => SMTP_USERNAME,
        'password'   => SMTP_PASSWORD,
        'from_email' => SMTP_FROM_EMAIL,
        'from_name'  => SMTP_FROM_NAME,
        'encryption' => SMTP_ENCRYPTION,
        'debug'      => SMTP_DEBUG,
    ],
    // 📱 SMS settings
    'sms' => [
        'api_token' => PHILSMS_API_TOKEN,
        'sender_id' => PHILSMS_SENDER_ID,
        'api_url'   => PHILSMS_API_URL,
    ],
    // 🔑 Session settings
    'session' => [
        'timeout' => SESSION_TIMEOUT,
        'name'    => SESSION_NAME,
    ],
    // 📡 Real-time settings
    'pusher' => [
        'app_id'  => PUSHER_APP_ID,
        'key'     => PUSHER_KEY,
        'secret'  => PUSHER_SECRET,
        'cluster' => PUSHER_CLUSTER,
    ],
];

return $config;