<?php
/**
 * PRINCIPLE ENTERPRISE - COMPLETE PROFESSIONAL SOLUTION
 * Full Server Integration + SystemAI Control
 * Production Ready - Enterprise Grade
 */

session_start();

// ============================================
// CSRF TOKEN MANAGEMENT
// ============================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_token(): string {
    return $_SESSION['csrf_token'];
}

function csrf_validate(): bool {
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return hash_equals($_SESSION['csrf_token'], $token);
}

// ============================================
// RATE LIMITING (Session-based)
// ============================================
function rate_limit(string $key, int $max, int $window = 60): bool {
    $now    = time();
    $bucket = &$_SESSION['rate_limit'][$key];
    if (!isset($bucket) || ($now - $bucket['start']) > $window) {
        $bucket = ['start' => $now, 'count' => 0];
    }
    $bucket['count']++;
    return $bucket['count'] <= $max;
}

// ============================================
// INPUT SANITIZATION & VALIDATION
// ============================================
function clean(string $val, int $max = 255): string {
    return mb_substr(trim($val), 0, $max);
}

function clean_status(string $val, array $allowed): string {
    return in_array($val, $allowed, true) ? $val : $allowed[0];
}

// ============================================
// AUDIT LOG
// ============================================
function audit_log(string $action, string $table, $record_id, array $data = []): void {
    $log_dir = __DIR__ . '/logs';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    $entry = json_encode([
        'timestamp' => date('c'),
        'session'   => session_id(),
        'action'    => $action,
        'table'     => $table,
        'record_id' => $record_id,
        'data'      => $data,
        'ip'        => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]) . "\n";
    @file_put_contents($log_dir . '/audit.log', $entry, FILE_APPEND | LOCK_EX);
}

// ============================================
// DATABASE CONNECTION
// ============================================
try {
    $pdo = new PDO(
        "pgsql:host=127.0.0.1;dbname=systemai",
        "systemai",
        "systemai",
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (Exception $e) {
    http_response_code(500);
    die('<h1>Database Error</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>');
}

// ============================================
// API ENDPOINTS (GET)
// ============================================
$api = $_GET['api'] ?? '';

if (!empty($api)) {
    header('Content-Type: application/json; charset=utf-8');

    if (!rate_limit('api_' . $api, 120, 60)) {
        http_response_code(429);
        echo json_encode(['error' => 'Rate limit exceeded']);
        exit;
    }

    try {
        switch ($api) {
            case 'organizations':
                $stmt = $pdo->query("SELECT id, name FROM organization ORDER BY name");
                echo json_encode(['data' => $stmt->fetchAll()]);
                break;

            case 'companies':
                $search  = clean($_GET['search'] ?? '');
                $country = clean($_GET['country'] ?? '');
                $status  = clean($_GET['status'] ?? '');
                $page    = max(1, (int)($_GET['page'] ?? 1));
                $limit   = 50;
                $offset  = ($page - 1) * $limit;

                $where  = ['1=1'];
                $params = [];

                if ($search !== '') {
                    $where[]  = '(c.name ILIKE ? OR c.legal_name ILIKE ? OR c.company_id ILIKE ?)';
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                if ($country !== '') {
                    $where[]  = 'c.country = ?';
                    $params[] = $country;
                }
                if ($status !== '') {
                    $where[]  = 'c.status = ?';
                    $params[] = $status;
                }

                $whereSQL = implode(' AND ', $where);

                $countStmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM companies c WHERE $whereSQL"
                );
                $countStmt->execute($params);
                $total = (int)$countStmt->fetchColumn();

                $stmt = $pdo->prepare(
                    "SELECT c.id, c.company_id, c.organization_id,
                            COALESCE(o.name,'') AS organization_name,
                            c.name, c.legal_name, c.country, c.tax_id,
                            c.data_center_region, c.status, c.created_at
                     FROM companies c
                     LEFT JOIN organization o ON o.id = c.organization_id
                     WHERE $whereSQL
                     ORDER BY c.created_at DESC
                     LIMIT $limit OFFSET $offset"
                );
                $stmt->execute($params);
                echo json_encode([
                    'data'  => $stmt->fetchAll(),
                    'total' => $total,
                    'page'  => $page,
                    'pages' => (int)ceil(max($total, 1) / $limit),
                ]);
                break;

            case 'company_single':
                $id   = (int)($_GET['id'] ?? 0);
                $stmt = $pdo->prepare(
                    "SELECT c.*, COALESCE(o.name,'') AS organization_name
                     FROM companies c
                     LEFT JOIN organization o ON o.id = c.organization_id
                     WHERE c.id = ?"
                );
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                echo json_encode($row ?: ['error' => 'Not found']);
                break;

            case 'users':
                $stmt = $pdo->query(
                    "SELECT id, username, email, status, created_at
                     FROM users ORDER BY created_at DESC LIMIT 200"
                );
                echo json_encode(['data' => $stmt->fetchAll()]);
                break;

            case 'business_units':
                $stmt = $pdo->query(
                    "SELECT bu.id, bu.name, bu.company_id,
                            COALESCE(c.name,'') AS company_name, bu.created_at
                     FROM business_units bu
                     LEFT JOIN companies c ON c.id = bu.company_id
                     ORDER BY bu.created_at DESC LIMIT 200"
                );
                echo json_encode(['data' => $stmt->fetchAll()]);
                break;

            case 'workers':
                $stmt = $pdo->query(
                    "SELECT id, name, worker_type, status, current_load, max_load, created_at
                     FROM workers ORDER BY created_at DESC LIMIT 200"
                );
                echo json_encode(['data' => $stmt->fetchAll()]);
                break;

            case 'tasks':
                $stmt = $pdo->query(
                    "SELECT id, name, status, priority, created_at
                     FROM tasks ORDER BY created_at DESC LIMIT 200"
                );
                echo json_encode(['data' => $stmt->fetchAll()]);
                break;

            case 'ai_leads':
                $stmt = $pdo->query(
                    "SELECT id, lead_id, name, status, created_at
                     FROM ai_leads ORDER BY created_at DESC LIMIT 200"
                );
                echo json_encode(['data' => $stmt->fetchAll()]);
                break;

            case 'projects':
                $stmt = $pdo->query(
                    "SELECT id, name, status, created_at
                     FROM project_metadata ORDER BY created_at DESC LIMIT 200"
                );
                echo json_encode(['data' => $stmt->fetchAll()]);
                break;

            case 'compliance':
                $stmt = $pdo->query(
                    "SELECT id, status, created_at
                     FROM compliance_status ORDER BY created_at DESC LIMIT 200"
                );
                echo json_encode(['data' => $stmt->fetchAll()]);
                break;

            case 'filesystem':
                $stmt = $pdo->query(
                    "SELECT COUNT(*) AS total_files,
                            COALESCE(SUM(size_bytes), 0) AS total_size
                     FROM filesystem_index"
                );
                $row = $stmt->fetch();
                echo json_encode([
                    'total_files'   => (int)$row['total_files'],
                    'total_size_gb' => round((float)$row['total_size'] / 1024 / 1024 / 1024, 2),
                ]);
                break;

            case 'systemai-status':
                $leads     = (int)$pdo->query("SELECT COUNT(*) FROM ai_leads")->fetchColumn();
                $workers   = (int)$pdo->query("SELECT COUNT(*) FROM workers WHERE status='online'")->fetchColumn();
                $tasks     = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status='pending'")->fetchColumn();
                $companies = (int)$pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn();
                echo json_encode([
                    'ai_leads_total'  => $leads,
                    'workers_online'  => $workers,
                    'tasks_pending'   => $tasks,
                    'companies_total' => $companies,
                    'status'          => 'operational',
                ]);
                break;

            case 'audit_log':
                $log_file = __DIR__ . '/logs/audit.log';
                $entries  = [];
                if (file_exists($log_file)) {
                    $lines = array_reverse(array_filter(
                        explode("\n", file_get_contents($log_file))
                    ));
                    foreach (array_slice($lines, 0, 100) as $line) {
                        $entry = json_decode($line, true);
                        if ($entry) {
                            $entries[] = $entry;
                        }
                    }
                }
                echo json_encode(['data' => $entries]);
                break;

            default:
                http_response_code(404);
                echo json_encode(['error' => 'Unknown API endpoint']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// ACTION HANDLERS (POST)
// ============================================
$action = $_POST['action'] ?? '';

if (!empty($action)) {
    header('Content-Type: application/json; charset=utf-8');

    if (!csrf_validate()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'CSRF token invalid']);
        exit;
    }

    if (!rate_limit('post_action', 60, 60)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Rate limit exceeded']);
        exit;
    }

    try {
        $type = clean($_POST['type'] ?? '');
        $id   = (int)($_POST['id'] ?? 0);

        // ── CREATE ──────────────────────────────────────────────
        if ($action === 'create') {

            if ($type === 'company') {
                $company_id         = clean($_POST['company_id'] ?? '');
                $organization_id    = (int)($_POST['organization_id'] ?? 0);
                $name               = clean($_POST['name'] ?? '');
                $legal_name         = clean($_POST['legal_name'] ?? '');
                $country            = clean($_POST['country'] ?? '');
                $tax_id             = clean($_POST['tax_id'] ?? '');
                $data_center_region = clean($_POST['data_center_region'] ?? '', 50);
                $status             = clean_status(
                    $_POST['status'] ?? 'active',
                    ['active', 'inactive', 'suspended']
                );

                if ($company_id === '' || $organization_id === 0 || $name === '') {
                    echo json_encode([
                        'success' => false,
                        'error'   => 'company_id, organization_id and name are required',
                    ]);
                    exit;
                }

                $stmt = $pdo->prepare(
                    "INSERT INTO companies
                       (company_id, organization_id, name, legal_name,
                        country, tax_id, data_center_region, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $company_id, $organization_id, $name, $legal_name,
                    $country, $tax_id, $data_center_region, $status,
                ]);
                $newId = (int)$pdo->lastInsertId('companies_id_seq');
                audit_log('create', 'companies', $newId,
                    compact('company_id', 'name', 'country', 'status'));
                echo json_encode(['success' => true, 'message' => 'Company created', 'id' => $newId]);

            } elseif ($type === 'user') {
                $username    = clean($_POST['username'] ?? '');
                $email       = clean($_POST['email'] ?? '');
                $status_val  = clean_status($_POST['status'] ?? 'active', ['active', 'inactive']);
                $company_id_v = (int)($_POST['company_id'] ?? 0);

                if ($username === '' || $email === '') {
                    echo json_encode(['success' => false, 'error' => 'username and email are required']);
                    exit;
                }

                $stmt = $pdo->prepare(
                    "INSERT INTO users (username, email, status, company_id)
                     VALUES (?, ?, ?, ?)"
                );
                $stmt->execute([
                    $username, $email, $status_val,
                    $company_id_v > 0 ? $company_id_v : null,
                ]);
                $newId = (int)$pdo->lastInsertId('users_id_seq');
                audit_log('create', 'users', $newId,
                    compact('username', 'email', 'status_val'));
                echo json_encode(['success' => true, 'message' => 'User created', 'id' => $newId]);

            } elseif ($type === 'business_unit') {
                $name         = clean($_POST['name'] ?? '');
                $company_id_v = (int)($_POST['company_id'] ?? 0);

                if ($name === '') {
                    echo json_encode(['success' => false, 'error' => 'name is required']);
                    exit;
                }

                $stmt = $pdo->prepare(
                    "INSERT INTO business_units (name, company_id) VALUES (?, ?)"
                );
                $stmt->execute([$name, $company_id_v > 0 ? $company_id_v : null]);
                $newId = (int)$pdo->lastInsertId('business_units_id_seq');
                audit_log('create', 'business_units', $newId,
                    compact('name', 'company_id_v'));
                echo json_encode([
                    'success' => true, 'message' => 'Business unit created', 'id' => $newId,
                ]);

            } elseif ($type === 'task') {
                $name     = clean($_POST['name'] ?? '');
                $status_v = clean_status(
                    $_POST['status'] ?? 'pending',
                    ['pending', 'in_progress', 'done', 'failed']
                );
                $priority = clean_status(
                    $_POST['priority'] ?? 'medium',
                    ['low', 'medium', 'high', 'critical']
                );

                if ($name === '') {
                    echo json_encode(['success' => false, 'error' => 'name is required']);
                    exit;
                }

                $stmt = $pdo->prepare(
                    "INSERT INTO tasks (name, status, priority) VALUES (?, ?, ?)"
                );
                $stmt->execute([$name, $status_v, $priority]);
                $newId = (int)$pdo->lastInsertId('tasks_id_seq');
                audit_log('create', 'tasks', $newId,
                    compact('name', 'status_v', 'priority'));
                echo json_encode(['success' => true, 'message' => 'Task created', 'id' => $newId]);

            } elseif ($type === 'ai_lead') {
                $lead_id  = clean($_POST['lead_id'] ?? '');
                $name     = clean($_POST['name'] ?? '');
                $status_v = clean_status(
                    $_POST['status'] ?? 'new',
                    ['new', 'active', 'completed', 'failed']
                );

                if ($name === '') {
                    echo json_encode(['success' => false, 'error' => 'name is required']);
                    exit;
                }
                if ($lead_id === '') {
                    $lead_id = 'LEAD_' . strtoupper(bin2hex(random_bytes(4)));
                }

                $stmt = $pdo->prepare(
                    "INSERT INTO ai_leads (lead_id, name, status) VALUES (?, ?, ?)"
                );
                $stmt->execute([$lead_id, $name, $status_v]);
                $newId = (int)$pdo->lastInsertId('ai_leads_id_seq');
                audit_log('create', 'ai_leads', $newId,
                    compact('lead_id', 'name', 'status_v'));
                echo json_encode(['success' => true, 'message' => 'AI Lead created', 'id' => $newId]);

            } else {
                echo json_encode([
                    'success' => false,
                    'error'   => 'Unknown type: ' . htmlspecialchars($type),
                ]);
            }

        // ── EDIT ────────────────────────────────────────────────
        } elseif ($action === 'edit') {

            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid id']);
                exit;
            }

            if ($type === 'company') {
                $company_id         = clean($_POST['company_id'] ?? '');
                $organization_id    = (int)($_POST['organization_id'] ?? 0);
                $name               = clean($_POST['name'] ?? '');
                $legal_name         = clean($_POST['legal_name'] ?? '');
                $country            = clean($_POST['country'] ?? '');
                $tax_id             = clean($_POST['tax_id'] ?? '');
                $data_center_region = clean($_POST['data_center_region'] ?? '', 50);
                $status             = clean_status(
                    $_POST['status'] ?? 'active',
                    ['active', 'inactive', 'suspended']
                );

                if ($company_id === '' || $organization_id === 0 || $name === '') {
                    echo json_encode([
                        'success' => false,
                        'error'   => 'company_id, organization_id and name are required',
                    ]);
                    exit;
                }

                $stmt = $pdo->prepare(
                    "UPDATE companies SET
                       company_id=?, organization_id=?, name=?, legal_name=?,
                       country=?, tax_id=?, data_center_region=?, status=?
                     WHERE id=?"
                );
                $stmt->execute([
                    $company_id, $organization_id, $name, $legal_name,
                    $country, $tax_id, $data_center_region, $status, $id,
                ]);
                audit_log('edit', 'companies', $id,
                    compact('company_id', 'name', 'country', 'status'));
                echo json_encode(['success' => true, 'message' => 'Company updated']);

            } elseif ($type === 'user') {
                $username    = clean($_POST['username'] ?? '');
                $email       = clean($_POST['email'] ?? '');
                $status_val  = clean_status($_POST['status'] ?? 'active', ['active', 'inactive']);

                $stmt = $pdo->prepare(
                    "UPDATE users SET username=?, email=?, status=? WHERE id=?"
                );
                $stmt->execute([$username, $email, $status_val, $id]);
                audit_log('edit', 'users', $id,
                    compact('username', 'email', 'status_val'));
                echo json_encode(['success' => true, 'message' => 'User updated']);

            } elseif ($type === 'business_unit') {
                $name = clean($_POST['name'] ?? '');
                $stmt = $pdo->prepare("UPDATE business_units SET name=? WHERE id=?");
                $stmt->execute([$name, $id]);
                audit_log('edit', 'business_units', $id, compact('name'));
                echo json_encode(['success' => true, 'message' => 'Business unit updated']);

            } elseif ($type === 'task') {
                $name     = clean($_POST['name'] ?? '');
                $status_v = clean_status(
                    $_POST['status'] ?? 'pending',
                    ['pending', 'in_progress', 'done', 'failed']
                );
                $priority = clean_status(
                    $_POST['priority'] ?? 'medium',
                    ['low', 'medium', 'high', 'critical']
                );

                $stmt = $pdo->prepare(
                    "UPDATE tasks SET name=?, status=?, priority=? WHERE id=?"
                );
                $stmt->execute([$name, $status_v, $priority, $id]);
                audit_log('edit', 'tasks', $id,
                    compact('name', 'status_v', 'priority'));
                echo json_encode(['success' => true, 'message' => 'Task updated']);

            } elseif ($type === 'ai_lead') {
                $name     = clean($_POST['name'] ?? '');
                $status_v = clean_status(
                    $_POST['status'] ?? 'new',
                    ['new', 'active', 'completed', 'failed']
                );

                $stmt = $pdo->prepare(
                    "UPDATE ai_leads SET name=?, status=? WHERE id=?"
                );
                $stmt->execute([$name, $status_v, $id]);
                audit_log('edit', 'ai_leads', $id,
                    compact('name', 'status_v'));
                echo json_encode(['success' => true, 'message' => 'AI Lead updated']);

            } elseif ($type === 'worker') {
                $name     = clean($_POST['name'] ?? '');
                $status_v = clean_status(
                    $_POST['status'] ?? 'online',
                    ['online', 'offline', 'busy']
                );

                $stmt = $pdo->prepare(
                    "UPDATE workers SET name=?, status=? WHERE id=?"
                );
                $stmt->execute([$name, $status_v, $id]);
                audit_log('edit', 'workers', $id,
                    compact('name', 'status_v'));
                echo json_encode(['success' => true, 'message' => 'Worker updated']);

            } else {
                echo json_encode(['success' => false, 'error' => 'Unknown type']);
            }

        // ── DELETE ──────────────────────────────────────────────
        } elseif ($action === 'delete') {

            if ($id <= 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid id']);
                exit;
            }

            $allowed_types = ['company', 'user', 'business_unit', 'task', 'ai_lead'];
            if (!in_array($type, $allowed_types, true)) {
                echo json_encode(['success' => false, 'error' => 'Unknown type']);
                exit;
            }

            $table_map = [
                'company'       => 'companies',
                'user'          => 'users',
                'business_unit' => 'business_units',
                'task'          => 'tasks',
                'ai_lead'       => 'ai_leads',
            ];
            $table = $table_map[$type];
            $stmt  = $pdo->prepare("DELETE FROM $table WHERE id=?");
            $stmt->execute([$id]);
            audit_log('delete', $table, $id);
            echo json_encode(['success' => true, 'message' => ucfirst($type) . ' deleted']);

        // ── SYSTEMAI ACTIONS ────────────────────────────────────
        } elseif ($action === 'systemai-scan') {
            $autonomous_file = __DIR__ . '/managers/SystemAIAutonomous.php';
            if (file_exists($autonomous_file)) {
                require_once $autonomous_file;
                $systemai = new SystemAIAutonomous($pdo);
                $result   = $systemai->autonomousWorkSession('/var/www');
                audit_log('systemai_scan', 'system', null, []);
                echo json_encode(['success' => true, 'result' => $result]);
            } else {
                echo json_encode(['success' => false, 'error' => 'SystemAI Autonomous not available']);
            }

        } elseif ($action === 'systemai-cleanup') {
            $report_file = __DIR__ . '/workspaces/GARBAGE_REPORT.json';
            if (file_exists($report_file)) {
                $report  = json_decode(file_get_contents($report_file), true);
                $deleted = 0;
                foreach (($report['files'] ?? []) as $file) {
                    $path = realpath($file['path'] ?? '');
                    if ($path !== false
                        && strpos($path, '/var/www') === 0
                        && file_exists($path)
                        && @unlink($path)
                    ) {
                        $deleted++;
                    }
                }
                audit_log('systemai_cleanup', 'filesystem', null,
                    ['deleted' => $deleted]);
                echo json_encode([
                    'success'  => true,
                    'deleted'  => $deleted,
                    'freed_mb' => $report['total_size_mb'] ?? 0,
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'No garbage report found']);
            }

        } else {
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
        }

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// HTML FRONTEND
// ============================================
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PRINCIPLE – Enterprise Control Center</title>
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --green: #00ff88;
            --green-dim: rgba(0,255,136,.12);
            --green-border: rgba(0,255,136,.22);
            --bg: #0a0e27;
            --bg2: #0f1835;
            --text: #e0e0e0;
            --muted: #667;
            --red: #ff4444;
            --red-dim: rgba(255,68,68,.12);
            --yellow: #ffbb33;
        }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, monospace;
               background: var(--bg); color: var(--text); overflow-x: hidden; }

        /* HEADER */
        .header { position: fixed; top:0; left:0; right:0; height:56px;
                  background: linear-gradient(90deg,rgba(10,14,39,.98),rgba(26,31,58,.98));
                  border-bottom: 2px solid var(--green); display:flex; align-items:center;
                  padding: 0 24px; z-index:1000; gap:16px; }
        .logo { color:var(--green); font-size:15px; font-weight:700; letter-spacing:2px; white-space:nowrap; }
        .top-nav { display:flex; gap:4px; flex:1; overflow-x:auto; }
        .nav-item { color:#888; cursor:pointer; font-size:11px; text-transform:uppercase;
                    padding:8px 14px; border-radius:4px; white-space:nowrap; transition:all .2s; }
        .nav-item:hover,.nav-item.active { color:var(--green); background:var(--green-dim); }
        .header-right { display:flex; gap:10px; align-items:center; margin-left:auto; }
        .status-dot { width:9px; height:9px; background:var(--green); border-radius:50%; animation:pulse 2s infinite; }
        @keyframes pulse { 0%,100%{opacity:1}50%{opacity:.4} }
        .badge { background:var(--green-dim); border:1px solid var(--green); color:var(--green);
                 padding:3px 10px; border-radius:12px; font-size:9px; font-weight:700; }

        /* LAYOUT */
        .container { margin-top:56px; display:flex; height:calc(100vh - 56px); }
        .sidebar { width:220px; background:rgba(15,24,53,.98); border-right:1px solid var(--green-border);
                   padding:14px; overflow-y:auto; flex-shrink:0; }
        .sidebar-section { margin-bottom:22px; }
        .sidebar-title { color:var(--green); font-size:9px; text-transform:uppercase; letter-spacing:2px;
                         margin-bottom:8px; font-weight:700; }
        .sidebar-item { padding:8px 12px; color:#888; cursor:pointer; font-size:11px; margin:2px 0;
                        border-radius:4px; border-left:3px solid transparent; transition:all .2s; }
        .sidebar-item:hover,.sidebar-item.active { background:var(--green-dim); color:var(--green);
                                                    border-left-color:var(--green); }
        .main-content { flex:1; display:flex; flex-direction:column; min-width:0; }
        .page-header { padding:14px 22px; border-bottom:1px solid var(--green-border);
                       background:rgba(15,24,53,.5); display:flex; align-items:center;
                       gap:14px; flex-wrap:wrap; }
        .page-title { color:var(--green); font-size:18px; font-weight:700; }
        .page-desc  { color:#888; font-size:11px; }
        .page-actions { margin-left:auto; display:flex; gap:8px; flex-wrap:wrap; }
        .workspace { flex:1; padding:18px 22px; overflow-y:auto; }

        /* METRICS */
        .metrics { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr));
                   gap:12px; margin-bottom:22px; }
        .metric { background:var(--green-dim); border:1px solid var(--green-border);
                  border-radius:8px; padding:14px; text-align:center; }
        .metric-value { font-size:24px; font-weight:700; color:var(--green); margin:5px 0; }
        .metric-label { font-size:9px; color:#888; text-transform:uppercase; }

        /* SEARCH BAR */
        .search-bar { display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap; }
        .search-input { background:var(--green-dim); border:1px solid var(--green-border);
                        color:var(--text); padding:8px 12px; border-radius:6px; font-size:11px;
                        flex:1; min-width:140px; }
        .search-input:focus { outline:none; border-color:var(--green); }
        .search-select { background:var(--bg2); border:1px solid var(--green-border);
                         color:var(--text); padding:8px 12px; border-radius:6px; font-size:11px; }

        /* DATA TABLE */
        .data-section { background:rgba(0,255,136,.02); border:1px solid var(--green-border);
                        border-radius:8px; margin-bottom:18px; overflow:hidden; }
        .data-header { padding:11px 16px; border-bottom:1px solid var(--green-border);
                       background:rgba(0,255,136,.05); display:flex; align-items:center; gap:10px; }
        .data-header-title { color:var(--green); font-size:11px; font-weight:700;
                             text-transform:uppercase; flex:1; }
        .data-header-count { color:#888; font-size:10px; }
        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; font-size:10px; min-width:520px; }
        thead { background:rgba(0,255,136,.07); }
        th { color:var(--green); padding:9px 12px; text-align:left; font-weight:700;
             text-transform:uppercase; font-size:9px; white-space:nowrap; }
        td { color:#aaa; padding:8px 12px; border-bottom:1px solid rgba(0,255,136,.04);
             max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        tr:hover td { background:rgba(0,255,136,.05); color:#ddd; }

        /* PAGINATION */
        .pagination { display:flex; gap:5px; justify-content:center; padding:10px 0; }
        .page-btn { background:var(--green-dim); border:1px solid var(--green-border);
                    color:var(--green); padding:4px 10px; border-radius:4px; cursor:pointer; font-size:10px; }
        .page-btn.active { background:var(--green); color:var(--bg); font-weight:700; }
        .page-btn:hover:not(.active) { background:rgba(0,255,136,.22); }

        /* BUTTONS */
        .btn { padding:7px 14px; background:var(--green-dim); border:1px solid var(--green);
               color:var(--green); border-radius:5px; cursor:pointer; font-size:9px;
               text-transform:uppercase; font-weight:700; transition:all .2s; white-space:nowrap; }
        .btn:hover { background:rgba(0,255,136,.22); }
        .btn.primary { background:var(--green); color:var(--bg); }
        .btn.primary:hover { background:#00dd77; }
        .btn.danger { border-color:var(--red); color:var(--red); background:var(--red-dim); }
        .btn.danger:hover { background:rgba(255,68,68,.22); }
        .btn.sm { padding:3px 8px; font-size:8px; }

        /* MODAL */
        .modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.72);
                 z-index:2000; align-items:center; justify-content:center; }
        .modal.active { display:flex; }
        .modal-content { background:var(--bg2); border:1px solid var(--green-border);
                         border-radius:8px; padding:22px; width:90%; max-width:520px;
                         max-height:90vh; overflow-y:auto; }
        .modal-title { color:var(--green); font-size:14px; font-weight:700; margin-bottom:18px; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .form-grid .full { grid-column:1/-1; }
        .form-group { display:flex; flex-direction:column; gap:5px; }
        .form-label { color:#888; font-size:9px; text-transform:uppercase; }
        .form-input,.form-select { width:100%; background:rgba(0,255,136,.04);
                                   border:1px solid var(--green-border); color:var(--text);
                                   padding:8px 10px; border-radius:5px; font-size:11px;
                                   font-family:inherit; }
        .form-input:focus,.form-select:focus { outline:none; border-color:var(--green);
                                               background:rgba(0,255,136,.07); color:var(--green); }
        .form-select option { background:var(--bg2); }
        .modal-actions { display:flex; gap:10px; margin-top:18px; }
        .form-error { color:var(--red); font-size:10px; margin-top:8px; min-height:16px; }

        /* TOAST */
        .toast { position:fixed; bottom:20px; right:20px; background:#162a1e;
                 border:1px solid var(--green); color:var(--green); padding:10px 18px;
                 border-radius:6px; font-size:11px; z-index:3000; animation:fadein .3s;
                 max-width:300px; }
        .toast.error { border-color:var(--red); color:var(--red); background:#2a1616; }
        @keyframes fadein { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:none} }

        /* STATUS BADGES */
        .s-badge { display:inline-block; padding:2px 7px; border-radius:3px;
                   font-size:8px; font-weight:700; text-transform:uppercase; }
        .s-active,.s-online,.s-operational { background:rgba(0,255,136,.15); color:var(--green); }
        .s-inactive,.s-offline { background:rgba(120,120,120,.15); color:#888; }
        .s-suspended,.s-failed { background:var(--red-dim); color:var(--red); }
        .s-pending,.s-new { background:rgba(255,187,51,.12); color:var(--yellow); }
        .s-in-progress,.s-busy { background:rgba(80,160,255,.12); color:#5af; }
        .s-completed { background:rgba(0,200,80,.15); color:#0c8; }

        /* AUDIT LOG */
        .audit-entry { font-size:10px; color:#666; padding:5px 10px;
                       border-bottom:1px solid rgba(0,255,136,.04); font-family:monospace; }
        .a-create { color:var(--green); }
        .a-edit   { color:var(--yellow); }
        .a-delete { color:var(--red); }

        .spinner { display:inline-block; width:14px; height:14px;
                   border:2px solid rgba(0,255,136,.15); border-top-color:var(--green);
                   border-radius:50%; animation:spin .5s linear infinite; }
        @keyframes spin { to{transform:rotate(360deg)} }
        .loading-wrap { padding:40px; text-align:center; }
    </style>
</head>
<body>
<div class="header">
    <div class="logo">&#128081; PRINCIPLE</div>
    <div class="top-nav" id="topNav"></div>
    <div class="header-right">
        <div class="status-dot"></div>
        <div class="badge">PRODUCTION</div>
    </div>
</div>

<div class="container">
    <div class="sidebar" id="sidebar"></div>
    <div class="main-content">
        <div class="page-header">
            <div>
                <div class="page-title" id="pageTitle">Dashboard</div>
                <div class="page-desc"  id="pageDesc"></div>
            </div>
            <div class="page-actions" id="pageActions"></div>
        </div>
        <div class="workspace" id="workspace">
            <div class="loading-wrap"><div class="spinner"></div></div>
        </div>
    </div>
</div>

<!-- Universal Create/Edit Modal -->
<div class="modal" id="modal">
    <div class="modal-content">
        <div class="modal-title" id="modalTitle">—</div>
        <div id="modalBody"></div>
        <div class="form-error" id="modalError"></div>
        <div class="modal-actions">
            <button class="btn primary" onclick="modalSave()">Speichern</button>
            <button class="btn danger"  onclick="modalClose()">Abbrechen</button>
        </div>
    </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal" id="confirmModal">
    <div class="modal-content">
        <div class="modal-title">&#9888;&#65039; Löschen bestätigen</div>
        <div id="confirmMsg" style="color:#bbb;font-size:12px;margin-bottom:16px;line-height:1.5"></div>
        <div class="modal-actions">
            <button class="btn danger" id="confirmYes">Ja, löschen</button>
            <button class="btn" onclick="document.getElementById('confirmModal').classList.remove('active')">Abbrechen</button>
        </div>
    </div>
</div>

<script>
// ============================================
// GLOBALS
// ============================================
const CSRF = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let currentAPI    = '';
let currentLabel  = '';
let currentType   = '';
let currentPage   = 1;
let totalPages    = 1;
let allData       = [];
let orgs          = [];
let companiesCache = [];
let modalCallback = null;

// ============================================
// XSS-SAFE HELPER
// ============================================
function esc(str) {
    if (str === null || str === undefined) return '';
    const d = document.createElement('div');
    d.textContent = String(str);
    return d.innerHTML;
}

function statusBadge(val) {
    if (!val) return '';
    const cls = 's-' + String(val).toLowerCase().replace(/_/g, '-');
    return '<span class="s-badge ' + cls + '">' + esc(val) + '</span>';
}

// ============================================
// NAVIGATION STRUCTURE
// ============================================
const VIEWS = {
    verwaltung: {
        title: '\uD83C\uDFE2 Verwaltung',
        desc:  'Kunden & Administration',
        sections: [
            { label: '\uD83C\uDFE2 Unternehmen',   api: 'companies',      type: 'company' },
            { label: '\uD83D\uDC64 Benutzer',       api: 'users',          type: 'user' },
            { label: '\uD83D\uDCC2 Business Units', api: 'business_units', type: 'business_unit' },
        ],
    },
    technik: {
        title: '\u2699\uFE0F Technik',
        desc:  'Technical Infrastructure',
        sections: [
            { label: '\uD83E\uDD16 AI Leads',   api: 'ai_leads', type: 'ai_lead' },
            { label: '\uD83D\uDC77 Worker',     api: 'workers',  type: 'worker' },
            { label: '\uD83D\uDCC1 Filesystem', api: 'filesystem', type: null },
        ],
    },
    systemai: {
        title: '\uD83D\uDDA5\uFE0F SystemAI',
        desc:  'AI Automation & Control',
        sections: [
            { label: '\uD83D\uDCCA Status',    api: 'systemai-status', type: null },
            { label: '\uD83D\uDCCB Audit Log', api: 'audit_log',       type: null },
        ],
    },
    unternehmen: {
        title: '\uD83D\uDCC8 Business',
        desc:  'Business Operations',
        sections: [
            { label: '\uD83D\uDCCC Aufgaben',   api: 'tasks',      type: 'task' },
            { label: '\uD83D\uDCE6 Projekte',   api: 'projects',   type: null },
            { label: '\u2705 Compliance',       api: 'compliance', type: null },
        ],
    },
};

// ============================================
// BUILD NAV & SIDEBAR
// ============================================
function buildUI() {
    const nav = document.getElementById('topNav');
    const sb  = document.getElementById('sidebar');

    Object.keys(VIEWS).forEach((key, i) => {
        const v   = VIEWS[key];
        const btn = document.createElement('div');
        btn.className   = 'nav-item' + (i === 0 ? ' active' : '');
        btn.textContent = v.title;
        btn.dataset.view = key;
        btn.onclick = () => activateView(key);
        nav.appendChild(btn);

        const sec = document.createElement('div');
        sec.className   = 'sidebar-section';
        sec.dataset.view = key;

        const stitle = document.createElement('div');
        stitle.className   = 'sidebar-title';
        stitle.textContent = v.title;
        sec.appendChild(stitle);

        v.sections.forEach(s => {
            const item = document.createElement('div');
            item.className   = 'sidebar-item';
            item.textContent = s.label;
            item.dataset.api = s.api;
            item.onclick = () => { activateSidebar(item); loadSection(s); };
            sec.appendChild(item);
        });
        sb.appendChild(sec);
    });

    activateView('verwaltung');
}

function activateView(key) {
    document.querySelectorAll('.nav-item').forEach(el =>
        el.classList.toggle('active', el.dataset.view === key));
    const v = VIEWS[key];
    document.getElementById('pageTitle').textContent = v.title;
    document.getElementById('pageDesc').textContent  = v.desc;
    const first     = v.sections[0];
    const firstItem = document.querySelector('.sidebar-item[data-api="' + first.api + '"]');
    if (firstItem) activateSidebar(firstItem);
    loadSection(first);
}

function activateSidebar(el) {
    document.querySelectorAll('.sidebar-item').forEach(i => i.classList.remove('active'));
    el.classList.add('active');
}

// ============================================
// SECTION LOADER
// ============================================
async function loadSection(section) {
    currentAPI   = section.api;
    currentLabel = section.label;
    currentType  = section.type;
    currentPage  = 1;

    // Pre-load reference data for dropdowns
    if (orgs.length === 0) {
        try { const r = await getJSON('?api=organizations'); orgs = r.data || []; }
        catch (_) {}
    }
    if (companiesCache.length === 0 && section.api !== 'companies') {
        try { const r = await getJSON('?api=companies&page=1&search=&country=&status='); companiesCache = r.data || []; }
        catch (_) {}
    }

    // Build header actions
    const acts = document.getElementById('pageActions');
    acts.innerHTML = '';

    if (section.type && ['company','user','business_unit','task','ai_lead'].includes(section.type)) {
        const btn = document.createElement('button');
        btn.className   = 'btn primary';
        btn.textContent = '+ Neu anlegen';
        btn.onclick     = () => openCreateModal(section.type);
        acts.appendChild(btn);
    }

    if (section.api === 'systemai-status') {
        const b1 = document.createElement('button');
        b1.className   = 'btn';
        b1.textContent = '\uD83D\uDD0D Scan';
        b1.onclick     = () => systemaiAction('scan');
        acts.appendChild(b1);

        const b2 = document.createElement('button');
        b2.className   = 'btn danger';
        b2.textContent = '\uD83D\uDDD1\uFE0F Cleanup';
        b2.onclick     = () => systemaiAction('cleanup');
        acts.appendChild(b2);
    }

    const rf = document.createElement('button');
    rf.className   = 'btn';
    rf.textContent = '\uD83D\uDD04 Refresh';
    rf.onclick     = () => loadSection(section);
    acts.appendChild(rf);

    await renderSection(section);
}

async function renderSection(section) {
    const ws = document.getElementById('workspace');
    ws.innerHTML = '<div class="loading-wrap"><div class="spinner"></div></div>';
    try {
        switch (section.api) {
            case 'companies':      await renderCompanies(1); break;
            case 'audit_log':      await renderAuditLog();   break;
            case 'systemai-status':await renderStatus();     break;
            case 'filesystem':     await renderFilesystem();  break;
            default:
                const d = await getJSON('?api=' + section.api);
                allData = d.data || [];
                renderTable(section, allData);
        }
    } catch (e) {
        ws.innerHTML = '<div style="color:var(--red);padding:20px">Error: ' + esc(e.message) + '</div>';
    }
}

// ============================================
// COMPANIES: full CRUD + search + pagination
// ============================================
async function renderCompanies(page) {
    currentPage = page || 1;
    const search  = document.getElementById('co-search')  ? document.getElementById('co-search').value  : '';
    const country = document.getElementById('co-country') ? document.getElementById('co-country').value : '';
    const status  = document.getElementById('co-status')  ? document.getElementById('co-status').value  : '';

    const url  = '?api=companies&page=' + currentPage
               + '&search='  + encodeURIComponent(search)
               + '&country=' + encodeURIComponent(country)
               + '&status='  + encodeURIComponent(status);
    const json = await getJSON(url);
    allData    = json.data   || [];
    totalPages = json.pages  || 1;
    companiesCache = allData;

    const ws = document.getElementById('workspace');

    let html = '<div class="search-bar">'
        + '<input class="search-input" id="co-search" placeholder="Suche Name / ID \u2026" value="' + esc(search) + '">'
        + '<input class="search-input" id="co-country" placeholder="Land (z.B. DE)" value="' + esc(country) + '" style="max-width:130px">'
        + '<select class="search-select" id="co-status">'
        +   '<option value="">Alle Status</option>'
        +   '<option value="active"'    + (status === 'active'    ? ' selected' : '') + '>Active</option>'
        +   '<option value="inactive"'  + (status === 'inactive'  ? ' selected' : '') + '>Inactive</option>'
        +   '<option value="suspended"' + (status === 'suspended' ? ' selected' : '') + '>Suspended</option>'
        + '</select>'
        + '<button class="btn" onclick="renderCompanies(1)">Suchen</button>'
        + '</div>';

    if (allData.length === 0) {
        html += '<div style="color:#888;padding:30px;text-align:center">Keine Unternehmen gefunden</div>';
    } else {
        html += '<div class="data-section">'
            + '<div class="data-header">'
            +   '<span class="data-header-title">\uD83C\uDFE2 Unternehmen</span>'
            +   '<span class="data-header-count">' + esc(json.total) + ' gesamt</span>'
            + '</div>'
            + '<div class="table-wrap"><table>'
            + '<thead><tr>'
            + '<th>ID</th><th>Company ID</th><th>Name</th><th>Organisation</th>'
            + '<th>Land</th><th>Status</th><th>Erstellt</th><th>Aktionen</th>'
            + '</tr></thead><tbody>';

        allData.forEach(function(row) {
            html += '<tr>'
                + '<td>' + esc(row.id) + '</td>'
                + '<td>' + esc(row.company_id) + '</td>'
                + '<td style="max-width:150px" title="' + esc(row.name) + '">' + esc(row.name) + '</td>'
                + '<td>' + esc(row.organization_name || row.organization_id) + '</td>'
                + '<td>' + esc(row.country) + '</td>'
                + '<td>' + statusBadge(row.status) + '</td>'
                + '<td>' + esc((row.created_at || '').substring(0, 10)) + '</td>'
                + '<td>'
                +   '<button class="btn sm" onclick="openEditModal(\'company\',' + esc(row.id) + ')">\u270F\uFE0F Edit</button> '
                +   '<button class="btn sm danger" onclick="confirmDelete(\'company\',' + esc(row.id) + ',\'' + esc(row.name).replace(/'/g, "\\'") + '\')">\uD83D\uDDD1\uFE0F</button>'
                + '</td>'
                + '</tr>';
        });

        html += '</tbody></table></div></div>';

        if (totalPages > 1) {
            html += '<div class="pagination">';
            for (let p = 1; p <= totalPages; p++) {
                html += '<span class="page-btn' + (p === currentPage ? ' active' : '') + '" onclick="renderCompanies(' + p + ')">' + p + '</span>';
            }
            html += '</div>';
        }
    }

    ws.innerHTML = html;

    const si = document.getElementById('co-search');
    if (si) si.addEventListener('keydown', function(e) { if (e.key === 'Enter') renderCompanies(1); });
}

// ============================================
// GENERIC TABLE RENDERER
// ============================================
function renderTable(section, data) {
    const ws = document.getElementById('workspace');
    if (!data.length) {
        ws.innerHTML = '<div style="color:#888;padding:30px;text-align:center">Keine Einträge vorhanden</div>';
        return;
    }
    const cols = Object.keys(data[0]).slice(0, 5);
    const hasActions = section.type &&
        ['user','business_unit','task','ai_lead','worker'].includes(section.type);

    let html = '<div class="data-section"><div class="data-header">'
        + '<span class="data-header-title">' + esc(section.label) + '</span>'
        + '<span class="data-header-count">' + data.length + ' Einträge</span>'
        + '</div><div class="table-wrap"><table><thead><tr>';
    cols.forEach(function(c) { html += '<th>' + esc(c) + '</th>'; });
    if (hasActions) html += '<th>Aktionen</th>';
    html += '</tr></thead><tbody>';

    data.forEach(function(row) {
        html += '<tr>';
        cols.forEach(function(c) {
            if (c === 'status') { html += '<td>' + statusBadge(row[c]) + '</td>'; return; }
            let v = row[c];
            if (typeof v === 'object') v = JSON.stringify(v);
            html += '<td title="' + esc(v) + '">' + esc(v) + '</td>';
        });
        if (hasActions) {
            html += '<td>'
                + '<button class="btn sm" onclick="openEditModal(\'' + esc(section.type) + '\',' + esc(row.id) + ')">\u270F\uFE0F Edit</button> '
                + '<button class="btn sm danger" onclick="confirmDelete(\'' + esc(section.type) + '\',' + esc(row.id) + ',\'' + esc(String(row.name || row.id)).replace(/'/g, "\\'") + '\')">\uD83D\uDDD1\uFE0F</button>'
                + '</td>';
        }
        html += '</tr>';
    });
    html += '</tbody></table></div></div>';
    ws.innerHTML = html;
}

// ============================================
// SYSTEMAI STATUS
// ============================================
async function renderStatus() {
    const d  = await getJSON('?api=systemai-status');
    const ws = document.getElementById('workspace');
    ws.innerHTML = '<div class="metrics">'
        + '<div class="metric"><div class="metric-label">Unternehmen</div><div class="metric-value">' + esc(d.companies_total) + '</div></div>'
        + '<div class="metric"><div class="metric-label">AI Leads</div><div class="metric-value">'    + esc(d.ai_leads_total) + '</div></div>'
        + '<div class="metric"><div class="metric-label">Workers Online</div><div class="metric-value">' + esc(d.workers_online) + '</div></div>'
        + '<div class="metric"><div class="metric-label">Tasks Pending</div><div class="metric-value">' + esc(d.tasks_pending) + '</div></div>'
        + '<div class="metric"><div class="metric-label">System</div><div class="metric-value" style="font-size:13px">' + statusBadge(d.status) + '</div></div>'
        + '</div>'
        + '<div style="color:#888;font-size:11px;padding:4px 0">Aktualisiert: ' + new Date().toLocaleString('de-DE') + '</div>';
}

// ============================================
// FILESYSTEM
// ============================================
async function renderFilesystem() {
    const d  = await getJSON('?api=filesystem');
    const ws = document.getElementById('workspace');
    ws.innerHTML = '<div class="metrics">'
        + '<div class="metric"><div class="metric-label">Dateien</div><div class="metric-value">' + esc(d.total_files) + '</div></div>'
        + '<div class="metric"><div class="metric-label">Speicher</div><div class="metric-value">' + esc(d.total_size_gb) + ' GB</div></div>'
        + '</div>';
}

// ============================================
// AUDIT LOG
// ============================================
async function renderAuditLog() {
    const d  = await getJSON('?api=audit_log');
    const ws = document.getElementById('workspace');
    if (!d.data || !d.data.length) {
        ws.innerHTML = '<div style="color:#888;padding:30px;text-align:center">Noch keine Audit-Einträge</div>';
        return;
    }
    let html = '<div class="data-section"><div class="data-header">'
        + '<span class="data-header-title">\uD83D\uDCCB Audit Log</span>'
        + '<span class="data-header-count">' + d.data.length + ' Einträge</span>'
        + '</div>';
    d.data.forEach(function(e) {
        const act = e.action ? e.action.split('_')[0] : '';
        html += '<div class="audit-entry">'
            + '<span style="color:#555">' + esc(e.timestamp) + '</span> '
            + '<span class="a-' + esc(act) + '">[' + esc((e.action || '').toUpperCase()) + ']</span> '
            + '<span style="color:#aaa">' + esc(e.table) + ' #' + esc(e.record_id) + '</span>'
            + '</div>';
    });
    html += '</div>';
    ws.innerHTML = html;
}

// ============================================
// SYSTEMAI ACTIONS
// ============================================
async function systemaiAction(action) {
    if (!confirm('SystemAI ' + action + ' ausführen?')) return;
    try {
        const r = await postAction({ action: 'systemai-' + action });
        if (r.success) {
            toast('\u2713 ' + action + ' abgeschlossen');
            renderStatus();
        } else {
            toast(r.error || 'Fehler', true);
        }
    } catch (e) {
        toast(e.message, true);
    }
}

// ============================================
// MODAL: CREATE
// ============================================
function openCreateModal(type) {
    document.getElementById('modalTitle').textContent = 'Neu anlegen: ' + typeName(type);
    document.getElementById('modalError').textContent = '';
    document.getElementById('modalBody').innerHTML    = buildForm(type, null);
    modalCallback = async function() {
        const payload = collectForm(type);
        if (!payload) return;
        payload.action = 'create';
        payload.type   = type;
        try {
            const r = await postAction(payload);
            if (r.success) {
                toast(r.message || 'Erstellt');
                modalClose();
                if (type === 'company') { companiesCache = []; renderCompanies(1); }
                else reloadCurrent();
            } else {
                document.getElementById('modalError').textContent = r.error || 'Fehler';
            }
        } catch (e) {
            document.getElementById('modalError').textContent = e.message;
        }
    };
    document.getElementById('modal').classList.add('active');
}

// ============================================
// MODAL: EDIT
// ============================================
async function openEditModal(type, id) {
    let item = null;
    try {
        if (type === 'company') {
            item = await getJSON('?api=company_single&id=' + id);
        } else {
            item = allData.find(function(d) { return d.id == id; });
        }
    } catch (e) {}
    if (!item || item.error) { toast('Datensatz nicht gefunden', true); return; }

    document.getElementById('modalTitle').textContent = 'Bearbeiten: ' + typeName(type) + ' #' + id;
    document.getElementById('modalError').textContent = '';
    document.getElementById('modalBody').innerHTML    = buildForm(type, item);
    modalCallback = async function() {
        const payload = collectForm(type);
        if (!payload) return;
        payload.action = 'edit';
        payload.type   = type;
        payload.id     = id;
        try {
            const r = await postAction(payload);
            if (r.success) {
                toast(r.message || 'Gespeichert');
                modalClose();
                if (type === 'company') { companiesCache = []; renderCompanies(currentPage); }
                else reloadCurrent();
            } else {
                document.getElementById('modalError').textContent = r.error || 'Fehler';
            }
        } catch (e) {
            document.getElementById('modalError').textContent = e.message;
        }
    };
    document.getElementById('modal').classList.add('active');
}

// ============================================
// MODAL: DELETE CONFIRM
// ============================================
function confirmDelete(type, id, name) {
    document.getElementById('confirmMsg').textContent =
        'Möchten Sie "' + name + '" (' + typeName(type) + ' #' + id +
        ') wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.';
    document.getElementById('confirmYes').onclick = async function() {
        document.getElementById('confirmModal').classList.remove('active');
        try {
            const r = await postAction({ action: 'delete', type: type, id: id });
            if (r.success) {
                toast(r.message || 'Gelöscht');
                if (type === 'company') { companiesCache = []; renderCompanies(currentPage); }
                else reloadCurrent();
            } else {
                toast(r.error || 'Fehler beim Löschen', true);
            }
        } catch (e) {
            toast(e.message, true);
        }
    };
    document.getElementById('confirmModal').classList.add('active');
}

function reloadCurrent() {
    const sec = findSection(currentAPI);
    if (sec) loadSection(sec);
}

function findSection(api) {
    for (const vk of Object.keys(VIEWS)) {
        for (const s of VIEWS[vk].sections) {
            if (s.api === api) return s;
        }
    }
    return null;
}

function modalClose() {
    document.getElementById('modal').classList.remove('active');
    modalCallback = null;
}
function modalSave() { if (modalCallback) modalCallback(); }

// ============================================
// FORM BUILDER
// ============================================
function orgOptions(selected) {
    let html = '<option value="">-- Organisation wählen --</option>';
    orgs.forEach(function(o) {
        html += '<option value="' + esc(o.id) + '"' + (selected == o.id ? ' selected' : '') + '>'
             + esc(o.name) + '</option>';
    });
    return html;
}

function companyOptions(selected) {
    let html = '<option value="">-- Unternehmen wählen --</option>';
    companiesCache.forEach(function(c) {
        html += '<option value="' + esc(c.id) + '"' + (selected == c.id ? ' selected' : '') + '>'
             + esc(c.name) + '</option>';
    });
    return html;
}

function fv(item, field) {
    if (!item || item[field] === null || item[field] === undefined) return '';
    return esc(item[field]);
}

function selOpt(options, current) {
    return options.map(function(o) {
        return '<option value="' + esc(o) + '"' + (current === o ? ' selected' : '') + '>' + esc(o) + '</option>';
    }).join('');
}

function buildForm(type, item) {
    if (type === 'company') {
        return '<div class="form-grid">'
            + '<div class="form-group"><label class="form-label">Company ID *</label>'
            +   '<input class="form-input" id="f_company_id" maxlength="100" value="' + fv(item,'company_id') + '" placeholder="z.B. COMP-001"></div>'
            + '<div class="form-group"><label class="form-label">Organisation *</label>'
            +   '<select class="form-select" id="f_organization_id">' + orgOptions(item && item.organization_id) + '</select></div>'
            + '<div class="form-group"><label class="form-label">Name *</label>'
            +   '<input class="form-input" id="f_name" maxlength="255" value="' + fv(item,'name') + '" placeholder="Unternehmensname"></div>'
            + '<div class="form-group"><label class="form-label">Legal Name</label>'
            +   '<input class="form-input" id="f_legal_name" maxlength="255" value="' + fv(item,'legal_name') + '" placeholder="Offizieller Rechtsname"></div>'
            + '<div class="form-group"><label class="form-label">Land</label>'
            +   '<input class="form-input" id="f_country" maxlength="100" value="' + fv(item,'country') + '" placeholder="z.B. DE"></div>'
            + '<div class="form-group"><label class="form-label">Tax ID</label>'
            +   '<input class="form-input" id="f_tax_id" maxlength="100" value="' + fv(item,'tax_id') + '" placeholder="Steuernummer"></div>'
            + '<div class="form-group"><label class="form-label">Datacenter Region</label>'
            +   '<input class="form-input" id="f_data_center_region" maxlength="50" value="' + fv(item,'data_center_region') + '" placeholder="z.B. eu-central-1"></div>'
            + '<div class="form-group"><label class="form-label">Status</label>'
            +   '<select class="form-select" id="f_status">'
            +   selOpt(['active','inactive','suspended'], item && item.status)
            +   '</select></div>'
            + '</div>';
    }
    if (type === 'user') {
        return '<div class="form-grid">'
            + '<div class="form-group"><label class="form-label">Benutzername *</label>'
            +   '<input class="form-input" id="f_username" maxlength="100" value="' + fv(item,'username') + '"></div>'
            + '<div class="form-group"><label class="form-label">E-Mail *</label>'
            +   '<input class="form-input" id="f_email" type="email" maxlength="255" value="' + fv(item,'email') + '"></div>'
            + '<div class="form-group"><label class="form-label">Status</label>'
            +   '<select class="form-select" id="f_status">'
            +   selOpt(['active','inactive'], item && item.status) + '</select></div>'
            + '<div class="form-group"><label class="form-label">Unternehmen</label>'
            +   '<select class="form-select" id="f_company_id">' + companyOptions(item && item.company_id) + '</select></div>'
            + '</div>';
    }
    if (type === 'business_unit') {
        return '<div class="form-grid">'
            + '<div class="form-group full"><label class="form-label">Name *</label>'
            +   '<input class="form-input" id="f_name" maxlength="255" value="' + fv(item,'name') + '"></div>'
            + '<div class="form-group full"><label class="form-label">Unternehmen</label>'
            +   '<select class="form-select" id="f_company_id">' + companyOptions(item && item.company_id) + '</select></div>'
            + '</div>';
    }
    if (type === 'task') {
        return '<div class="form-grid">'
            + '<div class="form-group full"><label class="form-label">Name *</label>'
            +   '<input class="form-input" id="f_name" maxlength="255" value="' + fv(item,'name') + '"></div>'
            + '<div class="form-group"><label class="form-label">Status</label>'
            +   '<select class="form-select" id="f_status">'
            +   selOpt(['pending','in_progress','done','failed'], item && item.status) + '</select></div>'
            + '<div class="form-group"><label class="form-label">Priorität</label>'
            +   '<select class="form-select" id="f_priority">'
            +   selOpt(['low','medium','high','critical'], item && item.priority) + '</select></div>'
            + '</div>';
    }
    if (type === 'ai_lead') {
        return '<div class="form-grid">'
            + '<div class="form-group"><label class="form-label">Lead ID (optional)</label>'
            +   '<input class="form-input" id="f_lead_id" maxlength="100" value="' + fv(item,'lead_id') + '" placeholder="Auto-generiert"></div>'
            + '<div class="form-group"><label class="form-label">Name *</label>'
            +   '<input class="form-input" id="f_name" maxlength="255" value="' + fv(item,'name') + '"></div>'
            + '<div class="form-group"><label class="form-label">Status</label>'
            +   '<select class="form-select" id="f_status">'
            +   selOpt(['new','active','completed','failed'], item && item.status) + '</select></div>'
            + '</div>';
    }
    if (type === 'worker') {
        return '<div class="form-grid">'
            + '<div class="form-group full"><label class="form-label">Name *</label>'
            +   '<input class="form-input" id="f_name" maxlength="255" value="' + fv(item,'name') + '"></div>'
            + '<div class="form-group"><label class="form-label">Status</label>'
            +   '<select class="form-select" id="f_status">'
            +   selOpt(['online','offline','busy'], item && item.status) + '</select></div>'
            + '</div>';
    }
    return '<div style="color:#888">Unbekannter Typ</div>';
}

// ============================================
// FORM COLLECTOR
// ============================================
function gv(id) {
    const el = document.getElementById(id);
    return el ? el.value : '';
}

function collectForm(type) {
    const err = document.getElementById('modalError');
    err.textContent = '';
    const p = {};
    if (type === 'company') {
        p.company_id         = gv('f_company_id').trim();
        p.organization_id    = gv('f_organization_id');
        p.name               = gv('f_name').trim();
        p.legal_name         = gv('f_legal_name').trim();
        p.country            = gv('f_country').trim();
        p.tax_id             = gv('f_tax_id').trim();
        p.data_center_region = gv('f_data_center_region').trim();
        p.status             = gv('f_status');
        if (!p.company_id || !p.organization_id || !p.name) {
            err.textContent = 'Company ID, Organisation und Name sind Pflichtfelder';
            return null;
        }
    } else if (type === 'user') {
        p.username   = gv('f_username').trim();
        p.email      = gv('f_email').trim();
        p.status     = gv('f_status');
        p.company_id = gv('f_company_id');
        if (!p.username || !p.email) {
            err.textContent = 'Benutzername und E-Mail sind Pflichtfelder';
            return null;
        }
    } else if (type === 'business_unit') {
        p.name       = gv('f_name').trim();
        p.company_id = gv('f_company_id');
        if (!p.name) { err.textContent = 'Name ist Pflichtfeld'; return null; }
    } else if (type === 'task') {
        p.name     = gv('f_name').trim();
        p.status   = gv('f_status');
        p.priority = gv('f_priority');
        if (!p.name) { err.textContent = 'Name ist Pflichtfeld'; return null; }
    } else if (type === 'ai_lead') {
        p.lead_id = gv('f_lead_id').trim();
        p.name    = gv('f_name').trim();
        p.status  = gv('f_status');
        if (!p.name) { err.textContent = 'Name ist Pflichtfeld'; return null; }
    } else if (type === 'worker') {
        p.name   = gv('f_name').trim();
        p.status = gv('f_status');
    }
    return p;
}

function typeName(t) {
    const m = {
        company:'Unternehmen', user:'Benutzer', business_unit:'Business Unit',
        task:'Aufgabe', ai_lead:'AI Lead', worker:'Worker'
    };
    return m[t] || t;
}

// ============================================
// HTTP HELPERS
// ============================================
async function getJSON(url) {
    const resp = await fetch(url);
    if (!resp.ok) throw new Error('HTTP ' + resp.status);
    return resp.json();
}

async function postAction(payload) {
    payload.csrf_token = CSRF;
    const body = new URLSearchParams();
    Object.keys(payload).forEach(function(k) { body.append(k, payload[k]); });
    const resp = await fetch('/', { method: 'POST', body: body });
    return resp.json();
}

// ============================================
// TOAST
// ============================================
function toast(msg, isError) {
    const el    = document.createElement('div');
    el.className = 'toast' + (isError ? ' error' : '');
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(function() { el.remove(); }, 3500);
}

// ============================================
// CLOSE MODALS ON BACKDROP CLICK
// ============================================
document.getElementById('modal').addEventListener('click', function(e) {
    if (e.target === this) modalClose();
});
document.getElementById('confirmModal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('active');
});

// ============================================
// INIT
// ============================================
buildUI();
</script>
</body>
</html>
