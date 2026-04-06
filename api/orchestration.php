<?php
/**
 * ORCHESTRATION API
 * Entry point for all AI Lead actions
 */

session_start();

require_once __DIR__ . '/../managers/SystemAIManager.php';
require_once __DIR__ . '/../managers/ProjectAIManager.php';
require_once __DIR__ . '/../managers/PrincipleAIManager.php';

try {
    $pdo = new PDO(
        "pgsql:host=127.0.0.1;dbname=systemai",
        "systemai",
        "systemai",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    die(json_encode(['error' => 'DB connection failed']));
}

$api = $_GET['api'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Initialize managers
$systemai = new SystemAIManager($pdo);
$projectai = new ProjectAIManager($pdo);
$principle = new PrincipleAIManager($pdo, $systemai, $projectai);

if (!empty($api)) {
    header('Content-Type: application/json');
    
    // ===== PRINCIPLE: AUTONOMOUS INITIALIZATION =====
    if ($api === 'principle-init') {
        $result = $principle->autonomousInitialization();
        echo json_encode($result);
    }
    
    // ===== SYSTEMAI: SCAN SERVER =====
    elseif ($api === 'systemai-scan') {
        $result = $systemai->scanServer();
        echo json_encode(['status' => 'scan_complete', 'results' => $result]);
    }
    
    // ===== SYSTEMAI: EXECUTE ACTION =====
    elseif ($api === 'systemai-action') {
        $action_id = $_POST['action_id'] ?? '';
        $result = $systemai->executeAutonomousAction($action_id);
        echo json_encode($result);
    }
    
    // ===== SYSTEMAI: GET INTERFACE =====
    elseif ($api === 'systemai-interface') {
        $interface = $systemai->createActionableInterface();
        echo json_encode(['actions' => $interface]);
    }
    
    // ===== PROJECTAI: GET INTERFACE =====
    elseif ($api === 'projectai-interface') {
        $interface = $projectai->createActionableInterface();
        echo json_encode(['actions' => $interface]);
    }
    
    // ===== PRINCIPLE: EXECUTE ACTION =====
    elseif ($api === 'principle-action') {
        $ai_lead = $_POST['ai_lead'] ?? '';
        $action_id = $_POST['action_id'] ?? '';
        $result = $principle->autonomousExecuteAction($ai_lead, $action_id);
        echo json_encode($result);
    }
    
    exit;
}

// HTML UI
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>PRINCIPLE Orchestration</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: monospace;
            background: #0a0e27;
            color: #e0e0e0;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .card {
            background: rgba(0,255,136,0.05);
            border: 2px solid #00ff88;
            border-radius: 8px;
            padding: 20px;
            margin: 20px 0;
        }
        .title {
            color: #00ff88;
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 15px;
        }
        .btn {
            background: #00ff88;
            color: #0a0e27;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            margin: 5px;
        }
        .btn:hover {
            background: #00dd77;
        }
        .log {
            background: rgba(0,0,0,0.3);
            border: 1px solid rgba(0,255,136,0.2);
            border-radius: 4px;
            padding: 12px;
            margin: 10px 0;
            max-height: 400px;
            overflow-y: auto;
            font-size: 12px;
        }
        .log-entry {
            margin: 3px 0;
            color: #888;
        }
        .log-entry.success {
            color: #00ff88;
        }
        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin: 15px 0;
        }
        .action-item {
            background: rgba(0,255,136,0.08);
            border: 1px solid rgba(0,255,136,0.2);
            border-radius: 6px;
            padding: 12px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .action-item:hover {
            background: rgba(0,255,136,0.12);
            border-color: #00ff88;
        }
        .action-label {
            color: #00ff88;
            font-weight: bold;
            font-size: 11px;
        }
        .action-desc {
            color: #888;
            font-size: 9px;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="title">👑 PRINCIPLE Orchestration</div>
            <p style="color: #888; margin: 10px 0;">AI Lead Management & Autonomous Actions</p>
            <button class="btn" onclick="initSystem()">🚀 Initialize System (PRINCIPLE → SystemAI → ProjectAI)</button>
        </div>
        
        <div class="card">
            <div class="title">🖥️ SystemAI Actions</div>
            <div id="systemai-actions"></div>
        </div>
        
        <div class="card">
            <div class="title">📐 ProjectAI Actions</div>
            <div id="projectai-actions"></div>
        </div>
        
        <div class="card">
            <div class="title">📊 Execution Log</div>
            <div class="log" id="log"></div>
        </div>
    </div>
    
    <script>
        function log(message, type = 'info') {
            const logDiv = document.getElementById('log');
            const entry = document.createElement('div');
            entry.className = 'log-entry' + (type === 'success' ? ' success' : '');
            entry.textContent = '> ' + message;
            logDiv.appendChild(entry);
            logDiv.scrollTop = logDiv.scrollHeight;
        }
        
        async function initSystem() {
            log('PRINCIPLE: Starting autonomous initialization...');
            
            const response = await fetch('/api/orchestration.php?api=principle-init').then(r => r.json());
            
            response.log.forEach(msg => log(msg, msg.includes('complete') ? 'success' : 'info'));
            
            log('System initialization complete!', 'success');
            
            // Load actions
            loadActions();
        }
        
        async function loadActions() {
            const systemai_actions = await fetch('/api/orchestration.php?api=systemai-interface').then(r => r.json());
            const projectai_actions = await fetch('/api/orchestration.php?api=projectai-interface').then(r => r.json());
            
            let html = '<div class="action-grid">';
            Object.entries(systemai_actions.actions).forEach(([key, action]) => {
                html += `
                    <div class="action-item" onclick="executeAction('systemai', '${key}')">
                        <div class="action-label">${action.label}</div>
                        <div class="action-desc">${action.description}</div>
                    </div>
                `;
            });
            html += '</div>';
            document.getElementById('systemai-actions').innerHTML = html;
            
            html = '<div class="action-grid">';
            Object.entries(projectai_actions.actions).forEach(([key, action]) => {
                html += `
                    <div class="action-item" onclick="executeAction('projectai', '${key}')">
                        <div class="action-label">${action.label}</div>
                        <div class="action-desc">${action.description}</div>
                    </div>
                `;
            });
            html += '</div>';
            document.getElementById('projectai-actions').innerHTML = html;
        }
        
        async function executeAction(lead, action_id) {
            log(`Executing: ${action_id} (${lead})`);
            
            const response = await fetch('/api/orchestration.php?api=principle-action', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `ai_lead=${lead}&action_id=${action_id}`
            }).then(r => r.json());
            
            response.log.forEach(msg => log(msg));
            log('Action executed', 'success');
        }
        
        // Initialize on load
        loadActions();
    </script>
</body>
</html>
