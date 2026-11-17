<?php
/**
 * PC Uptime Tracker - Central Website API (Hostinger Ready)
 * Pure PHP (No frameworks). Supports multiple PCs with custom names.
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Connect to Database (SQLite default or Hostinger MySQL)
try {
    if (DB_DRIVER === 'mysql') {
        $dsn = 'mysql:host=' . MYSQL_HOST . ';dbname=' . MYSQL_DB . ';charset=utf8mb4';
        $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS);
    } else {
        $dbDir = dirname(SQLITE_DB_PATH);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }
        $pdo = new PDO('sqlite:' . SQLITE_DB_PATH);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Exception $e) {
    jsonResponse(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
}

// Initialize Schema
initDatabase($pdo);

// Parse request
$action = $_GET['action'] ?? '';
$rawInput = file_get_contents('php://input');
$inputData = json_decode($rawInput, true) ?? [];
if (empty($action) && isset($inputData['action'])) {
    $action = $inputData['action'];
}

// Verify API Secret Key on POST requests (if configured in config.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && API_SECRET_KEY !== '') {
    $providedKey = $_SERVER['HTTP_X_API_KEY'] ?? ($inputData['api_key'] ?? ($_GET['api_key'] ?? ''));
    if ($providedKey !== API_SECRET_KEY) {
        jsonResponse(['success' => false, 'error' => 'Unauthorized: Invalid API Key'], 401);
    }
}

switch ($action) {
    case 'heartbeat':
        handleHeartbeat($pdo, $inputData);
        break;

    case 'sync':
        handleSync($pdo, $inputData);
        break;

    case 'offline':
        handleOffline($pdo, $inputData);
        break;

    case 'dashboard':
        handleDashboard($pdo);
        break;

    case 'devices':
        jsonResponse(['success' => true, 'devices' => getAllDevices($pdo)]);
        break;

    case 'status':
        handleDashboard($pdo);
        break;

    default:
        jsonResponse([
            'success'   => true,
            'message'   => 'PC Uptime Tracker Website API is active',
            'flow'      => 'CLI (Any PC) -> Website API (Hostinger)',
            'time'      => date('Y-m-d H:i:s'),
            'endpoints' => [
                'POST ?action=heartbeat' => 'Receive live uptime & status from a PC CLI',
                'POST ?action=sync'      => 'Receive boot/shutdown history from a PC CLI',
                'POST ?action=offline'   => 'Mark a PC as turned off / offline immediately',
                'GET  ?action=dashboard' => 'Fetch all PCs, stats, and session history for the Web UI'
            ]
        ]);
        break;
}

/**
 * Create tables automatically if they do not exist
 */
function initDatabase(PDO $pdo) {
    if (DB_DRIVER === 'mysql') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS devices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pc_name VARCHAR(100) UNIQUE NOT NULL,
                system_hostname VARCHAR(100),
                os VARCHAR(50),
                ip_address VARCHAR(45),
                first_seen DATETIME NOT NULL,
                last_seen DATETIME NOT NULL,
                status VARCHAR(20) DEFAULT 'offline',
                last_boot DATETIME,
                last_uptime_seconds INT DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pc_name VARCHAR(100) NOT NULL,
                session_key VARCHAR(190) UNIQUE NOT NULL,
                boot_time DATETIME NOT NULL,
                shutdown_time DATETIME NULL,
                duration_seconds INT DEFAULT 0,
                shutdown_type VARCHAR(30) DEFAULT 'active',
                is_current TINYINT DEFAULT 0,
                updated_at DATETIME NOT NULL,
                INDEX idx_pc_name (pc_name),
                INDEX idx_boot_time (boot_time)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS devices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pc_name TEXT UNIQUE NOT NULL,
                system_hostname TEXT,
                os TEXT,
                ip_address TEXT,
                first_seen DATETIME NOT NULL,
                last_seen DATETIME NOT NULL,
                status TEXT DEFAULT 'offline',
                last_boot DATETIME,
                last_uptime_seconds INTEGER DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                pc_name TEXT NOT NULL,
                session_key TEXT UNIQUE NOT NULL,
                boot_time DATETIME NOT NULL,
                shutdown_time DATETIME,
                duration_seconds INTEGER DEFAULT 0,
                shutdown_type TEXT DEFAULT 'active',
                is_current INTEGER DEFAULT 0,
                updated_at DATETIME NOT NULL
            );

            CREATE INDEX IF NOT EXISTS idx_sessions_pc ON sessions(pc_name);
            CREATE INDEX IF NOT EXISTS idx_sessions_boot ON sessions(boot_time);
        ");
    }
}

/**
 * Receive live heartbeat from a PC CLI
 */
function handleHeartbeat(PDO $pdo, array $data) {
    $pcName = trim($data['pc_name'] ?? ($data['hostname'] ?? ''));
    $sysHost = trim($data['system_hostname'] ?? ($data['hostname'] ?? $pcName));
    $os = trim($data['os'] ?? 'Windows');
    $bootTime = trim($data['boot_time'] ?? date('Y-m-d H:i:s'));
    $uptimeSeconds = max(0, (int)($data['uptime_seconds'] ?? 0));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $now = date('Y-m-d H:i:s');

    if (empty($pcName)) {
        jsonResponse(['success' => false, 'error' => 'pc_name is required'], 400);
    }

    upsertDevice($pdo, $pcName, $sysHost, $os, $ip, $now, 'online', $bootTime, $uptimeSeconds);

    $sessionKey = $pcName . '_' . $bootTime;
    upsertSession($pdo, $pcName, $sessionKey, $bootTime, null, $uptimeSeconds, 'active', 1, $now);

    // Ensure older sessions for this PC are marked not current
    $stmtOld = $pdo->prepare("
        UPDATE sessions
        SET is_current = 0,
            shutdown_type = CASE WHEN shutdown_type = 'active' THEN 'shutdown_clean' ELSE shutdown_type END
        WHERE pc_name = :pc AND session_key != :key AND is_current = 1
    ");
    $stmtOld->execute([':pc' => $pcName, ':key' => $sessionKey]);

    jsonResponse([
        'success'     => true,
        'message'     => "Heartbeat recorded for [{$pcName}]",
        'pc_name'     => $pcName,
        'server_time' => $now
    ]);
}

/**
 * Receive historical boot/shutdown sessions from a PC CLI
 */
function handleSync(PDO $pdo, array $data) {
    $pcName = trim($data['pc_name'] ?? ($data['hostname'] ?? ''));
    $sysHost = trim($data['system_hostname'] ?? ($data['hostname'] ?? $pcName));
    $os = trim($data['os'] ?? 'Windows');
    $sessions = $data['sessions'] ?? [];
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $now = date('Y-m-d H:i:s');

    if (empty($pcName)) {
        jsonResponse(['success' => false, 'error' => 'pc_name is required'], 400);
    }
    if (!is_array($sessions)) {
        jsonResponse(['success' => false, 'error' => 'sessions must be an array'], 400);
    }

    $syncedCount = 0;
    $latestBoot = $now;
    $latestUptime = 0;

    foreach ($sessions as $idx => $s) {
        $bootTime = trim($s['boot_time'] ?? '');
        if (empty($bootTime)) continue;

        $shutdownTime = !empty($s['shutdown_time']) ? trim($s['shutdown_time']) : null;
        $shutdownType = trim($s['shutdown_type'] ?? 'shutdown_clean');
        $duration = max(0, (int)($s['duration_seconds'] ?? 0));
        $isCurrent = !empty($s['is_current']) ? 1 : 0;

        if ($duration <= 0 && $shutdownTime) {
            $duration = max(0, strtotime($shutdownTime) - strtotime($bootTime));
        }

        if ($idx === 0 || $isCurrent) {
            $latestBoot = $bootTime;
            if ($isCurrent) {
                $latestUptime = $duration;
            }
        }

        $sessionKey = $pcName . '_' . $bootTime;
        upsertSession($pdo, $pcName, $sessionKey, $bootTime, $shutdownTime, $duration, $shutdownType, $isCurrent, $now);
        $syncedCount++;
    }

    upsertDevice($pdo, $pcName, $sysHost, $os, $ip, $now, 'online', $latestBoot, $latestUptime);

    jsonResponse([
        'success'      => true,
        'message'      => "Synced {$syncedCount} sessions for [{$pcName}]",
        'pc_name'      => $pcName,
        'synced_count' => $syncedCount
    ]);
}

/**
 * Explicitly mark a PC as offline / turned off
 */
function handleOffline(PDO $pdo, array $data) {
    $pcName = trim($data['pc_name'] ?? ($data['hostname'] ?? ''));
    $uptimeSeconds = max(0, (int)($data['uptime_seconds'] ?? 0));
    $now = date('Y-m-d H:i:s');

    if (empty($pcName)) {
        jsonResponse(['success' => false, 'error' => 'pc_name is required'], 400);
    }

    $stmt = $pdo->prepare("UPDATE devices SET status = 'offline', last_seen = :now WHERE pc_name = :pc");
    $stmt->execute([':now' => $now, ':pc' => $pcName]);

    $stmtSess = $pdo->prepare("
        UPDATE sessions
        SET is_current = 0,
            shutdown_time = :now,
            shutdown_type = 'shutdown_clean',
            duration_seconds = CASE WHEN :up > duration_seconds THEN :up ELSE duration_seconds END,
            updated_at = :now
        WHERE pc_name = :pc AND is_current = 1
    ");
    $stmtSess->execute([':now' => $now, ':up' => $uptimeSeconds, ':pc' => $pcName]);

    jsonResponse([
        'success' => true,
        'message' => "PC [{$pcName}] marked as offline at {$now}"
    ]);
}

/**
 * Return complete multi-PC dashboard payload
 */
function handleDashboard(PDO $pdo) {
    $selectedPc = trim($_GET['pc'] ?? ($_GET['hostname'] ?? 'all'));
    if ($selectedPc === '') $selectedPc = 'all';

    $devices = getAllDevices($pdo);
    $onlineCount = 0;
    foreach ($devices as $d) {
        if ($d['is_online']) $onlineCount++;
    }

    // Filter clause
    $hasFilter = ($selectedPc !== 'all');
    $whereClause = $hasFilter ? "WHERE pc_name = :pc" : "";
    $andClause   = $hasFilter ? "AND pc_name = :pc" : "";

    // Sessions list
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
    $sqlSessions = "
        SELECT id, pc_name, boot_time, shutdown_time, duration_seconds, shutdown_type, is_current, updated_at
        FROM sessions
        {$whereClause}
        ORDER BY boot_time DESC
        LIMIT {$limit}
    ";
    $stmtSess = $pdo->prepare($sqlSessions);
    if ($hasFilter) $stmtSess->bindValue(':pc', $selectedPc);
    $stmtSess->execute();
    $sessions = $stmtSess->fetchAll();

    // Build lookup of online states per PC
    $onlineMap = [];
    foreach ($devices as $d) {
        $onlineMap[$d['pc_name']] = $d;
    }

    foreach ($sessions as &$s) {
        $pcDev = $onlineMap[$s['pc_name']] ?? null;
        if ($s['is_current'] && $pcDev && $pcDev['is_online']) {
            $s['duration_seconds'] = max((int)$s['duration_seconds'], (int)$pcDev['uptime_seconds']);
        } elseif ($s['is_current'] && (!$pcDev || !$pcDev['is_online'])) {
            $s['is_current'] = 0;
            $s['shutdown_type'] = 'offline_timeout';
            $s['shutdown_time'] = $pcDev ? $pcDev['last_seen'] : $s['updated_at'];
        }
        $s['duration_formatted'] = formatDuration($s['duration_seconds']);
    }

    // Aggregated Statistics
    $todayStart = date('Y-m-d 00:00:00');
    $sevenDaysAgo = date('Y-m-d 00:00:00', strtotime('-6 days'));

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM sessions {$whereClause}");
    if ($hasFilter) $stmtCount->execute([':pc' => $selectedPc]); else $stmtCount->execute();
    $totalSessions = (int)$stmtCount->fetchColumn();

    $stmtTotalUp = $pdo->prepare("SELECT SUM(duration_seconds) FROM sessions {$whereClause}");
    if ($hasFilter) $stmtTotalUp->execute([':pc' => $selectedPc]); else $stmtTotalUp->execute();
    $totalUptimeSeconds = (int)$stmtTotalUp->fetchColumn();

    $stmtTodayUp = $pdo->prepare("SELECT SUM(duration_seconds) FROM sessions WHERE boot_time >= :today {$andClause}");
    $paramsToday = [':today' => $todayStart];
    if ($hasFilter) $paramsToday[':pc'] = $selectedPc;
    $stmtTodayUp->execute($paramsToday);
    $todayUptimeSeconds = (int)$stmtTodayUp->fetchColumn();

    $stmtDirty = $pdo->prepare("SELECT COUNT(*) FROM sessions WHERE shutdown_type = 'shutdown_dirty' {$andClause}");
    $paramsDirty = [];
    if ($hasFilter) $paramsDirty[':pc'] = $selectedPc;
    $stmtDirty->execute($paramsDirty);
    $unexpectedCount = (int)$stmtDirty->fetchColumn();

    // 7-day chart history (fill all 7 days cleanly)
    $dateFunc = (DB_DRIVER === 'mysql') ? "DATE(boot_time)" : "date(boot_time)";
    $stmtChart = $pdo->prepare("
        SELECT {$dateFunc} as log_date, SUM(duration_seconds) as total_seconds, COUNT(*) as session_count
        FROM sessions
        WHERE boot_time >= :start {$andClause}
        GROUP BY {$dateFunc}
        ORDER BY log_date ASC
    ");
    $paramsChart = [':start' => $sevenDaysAgo];
    if ($hasFilter) $paramsChart[':pc'] = $selectedPc;
    $stmtChart->execute($paramsChart);
    $rawChart = $stmtChart->fetchAll();

    $chartMap = [];
    foreach ($rawChart as $row) {
        $chartMap[$row['log_date']] = [
            'total_seconds' => (int)$row['total_seconds'],
            'session_count' => (int)$row['session_count'],
            'hours'         => round(((int)$row['total_seconds']) / 3600, 2)
        ];
    }

    $dailyHistory = [];
    for ($i = 6; $i >= 0; $i--) {
        $dStr = date('Y-m-d', strtotime("-{$i} days"));
        $entry = $chartMap[$dStr] ?? ['total_seconds' => 0, 'session_count' => 0, 'hours' => 0];
        $dailyHistory[] = [
            'log_date'      => $dStr,
            'label'         => date('M d', strtotime($dStr)),
            'total_seconds' => $entry['total_seconds'],
            'session_count' => $entry['session_count'],
            'hours'         => $entry['hours']
        ];
    }

    jsonResponse([
        'success'      => true,
        'server_time'  => date('Y-m-d H:i:s'),
        'selected_pc'  => $selectedPc,
        'fleet'        => [
            'total_pcs'    => count($devices),
            'online_pcs'   => $onlineCount,
            'offline_pcs'  => count($devices) - $onlineCount
        ],
        'devices'      => $devices,
        'stats'        => [
            'total_sessions'         => $totalSessions,
            'total_uptime_seconds'   => $totalUptimeSeconds,
            'total_uptime_formatted' => formatDuration($totalUptimeSeconds),
            'today_uptime_seconds'   => $todayUptimeSeconds,
            'today_uptime_formatted' => formatDuration($todayUptimeSeconds),
            'unexpected_shutdowns'   => $unexpectedCount,
            'daily_history'          => $dailyHistory
        ],
        'sessions'     => $sessions
    ]);
}

/**
 * Fetch all registered PCs and calculate live online state
 */
function getAllDevices(PDO $pdo) {
    $rows = $pdo->query("SELECT * FROM devices ORDER BY last_seen DESC")->fetchAll();
    $devices = [];
    $nowTs = time();

    foreach ($rows as $dev) {
        $lastSeenTs = strtotime($dev['last_seen']);
        $agoSeconds = max(0, $nowTs - $lastSeenTs);
        $isOnline = ($dev['status'] === 'online') && ($agoSeconds <= ONLINE_THRESHOLD_SECONDS);

        $uptime = (int)$dev['last_uptime_seconds'];
        if ($isOnline && !empty($dev['last_boot'])) {
            $bootTs = strtotime($dev['last_boot']);
            if ($bootTs > 0 && $nowTs >= $bootTs) {
                $uptime = max($uptime, $nowTs - $bootTs);
            }
        }

        $devices[] = [
            'id'               => (int)$dev['id'],
            'pc_name'          => $dev['pc_name'],
            'system_hostname'  => $dev['system_hostname'],
            'os'               => $dev['os'],
            'ip_address'       => $dev['ip_address'],
            'status'           => $isOnline ? 'online' : 'offline',
            'is_online'        => $isOnline,
            'last_boot'        => $dev['last_boot'],
            'last_seen'        => $dev['last_seen'],
            'last_seen_ago'    => formatDuration($agoSeconds) . ' ago',
            'uptime_seconds'   => $isOnline ? $uptime : 0,
            'last_uptime_sec'  => $uptime,
            'uptime_formatted' => $isOnline ? formatDuration($uptime) : 'Offline (Last: ' . formatDuration($uptime) . ')'
        ];
    }

    return $devices;
}

/**
 * Cross-DB Upsert for Devices (works on both SQLite and MySQL)
 */
function upsertDevice(PDO $pdo, $pcName, $sysHost, $os, $ip, $now, $status, $bootTime, $uptimeSeconds) {
    $stmtCheck = $pdo->prepare("SELECT id FROM devices WHERE pc_name = :pc LIMIT 1");
    $stmtCheck->execute([':pc' => $pcName]);
    $exists = $stmtCheck->fetchColumn();

    if ($exists) {
        $stmt = $pdo->prepare("
            UPDATE devices
            SET system_hostname = :sys,
                os = :os,
                ip_address = :ip,
                last_seen = :now,
                status = :status,
                last_boot = :boot,
                last_uptime_seconds = :up
            WHERE pc_name = :pc
        ");
        $stmt->execute([
            ':sys'    => $sysHost,
            ':os'     => $os,
            ':ip'     => $ip,
            ':now'    => $now,
            ':status' => $status,
            ':boot'   => $bootTime,
            ':up'     => $uptimeSeconds,
            ':pc'     => $pcName
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO devices (pc_name, system_hostname, os, ip_address, first_seen, last_seen, status, last_boot, last_uptime_seconds)
            VALUES (:pc, :sys, :os, :ip, :now, :now, :status, :boot, :up)
        ");
        $stmt->execute([
            ':pc'     => $pcName,
            ':sys'    => $sysHost,
            ':os'     => $os,
            ':ip'     => $ip,
            ':now'    => $now,
            ':status' => $status,
            ':boot'   => $bootTime,
            ':up'     => $uptimeSeconds
        ]);
    }
}

/**
 * Cross-DB Upsert for Sessions (works on both SQLite and MySQL)
 */
function upsertSession(PDO $pdo, $pcName, $sessionKey, $bootTime, $shutdownTime, $duration, $shutdownType, $isCurrent, $now) {
    $stmtCheck = $pdo->prepare("SELECT id, duration_seconds, shutdown_time FROM sessions WHERE session_key = :k LIMIT 1");
    $stmtCheck->execute([':k' => $sessionKey]);
    $existing = $stmtCheck->fetch();

    if ($existing) {
        $finalDuration = max((int)$existing['duration_seconds'], (int)$duration);
        $finalShutdown = $shutdownTime ?: $existing['shutdown_time'];
        if ($isCurrent) {
            $finalShutdown = null;
        }

        $stmt = $pdo->prepare("
            UPDATE sessions
            SET shutdown_time = :shut,
                duration_seconds = :dur,
                shutdown_type = :type,
                is_current = :curr,
                updated_at = :now
            WHERE session_key = :k
        ");
        $stmt->execute([
            ':shut' => $finalShutdown,
            ':dur'  => $finalDuration,
            ':type' => $shutdownType,
            ':curr' => $isCurrent,
            ':now'  => $now,
            ':k'    => $sessionKey
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO sessions (pc_name, session_key, boot_time, shutdown_time, duration_seconds, shutdown_type, is_current, updated_at)
            VALUES (:pc, :k, :boot, :shut, :dur, :type, :curr, :now)
        ");
        $stmt->execute([
            ':pc'   => $pcName,
            ':k'    => $sessionKey,
            ':boot' => $bootTime,
            ':shut' => $shutdownTime,
            ':dur'  => $duration,
            ':type' => $shutdownType,
            ':curr' => $isCurrent,
            ':now'  => $now
        ]);
    }
}

function formatDuration($seconds) {
    $seconds = (int)$seconds;
    if ($seconds <= 0) return '0s';

    $d = floor($seconds / 86400);
    $h = floor(($seconds % 86400) / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;

    $parts = [];
    if ($d > 0) $parts[] = "{$d}d";
    if ($h > 0) $parts[] = "{$h}h";
    if ($m > 0) $parts[] = "{$m}m";
    if ($s > 0 || empty($parts)) $parts[] = "{$s}s";

    return implode(' ', $parts);
}

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit();
}
