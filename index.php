<?php
/**
 * PRINCIPLE ENTERPRISE - COMPLETE PROFESSIONAL SOLUTION
 * Full Server Integration + SystemAI Control
 * Production Ready
 */

session_start();

try {
    $pdo = new PDO(
        "pgsql:host=127.0.0.1;dbname=systemai",
        "systemai",
        "systemai",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    http_response_code(500);
    die('<h1>Database Error</h1><p>' . $e->getMessage() . '</p>');
}

// ============================================
// API ENDPOINTS - Complete Data & Actions
// ============================================

$api = $_GET['api'] ?? '';
$action = $_POST['action'] ?? '';

if (!empty($api)) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        switch($api) {
            case 'companies':
                $stmt = $pdo->query("SELECT id, company_id, name, country, status, created_at FROM companies ORDER BY created_at DESC LIMIT 100");
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['data' => $data, 'count' => count($data)]);
                break;
            
            case 'users':
                $stmt = $pdo->query("SELECT id, username, email, status, created_at FROM users ORDER BY created_at DESC LIMIT 100");
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['data' => $data, 'count' => count($data)]);
                break;
            
            case 'workers':
                $stmt = $pdo->query("SELECT id, name, worker_type, status, current_load, max_load, created_at FROM workers ORDER BY created_at DESC LIMIT 100");
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['data' => $data, 'count' => count($data)]);
                break;
            
            case 'tasks':
                $stmt = $pdo->query("SELECT id, name, status, priority, created_at FROM tasks ORDER BY created_at DESC LIMIT 100");
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['data' => $data, 'count' => count($data)]);
                break;
            
            case 'ai_leads':
                $stmt = $pdo->query("SELECT id, lead_id, name, status, created_at FROM ai_leads ORDER BY created_at DESC LIMIT 100");
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['data' => $data, 'count' => count($data)]);
                break;
            
            case 'projects':
                $stmt = $pdo->query("SELECT id, name, status, created_at FROM project_metadata ORDER BY created_at DESC LIMIT 100");
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['data' => $data, 'count' => count($data)]);
                break;
            
            case 'compliance':
                $stmt = $pdo->query("SELECT id, status, created_at FROM compliance_status ORDER BY created_at DESC LIMIT 100");
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['data' => $data, 'count' => count($data)]);
                break;
            
            case 'filesystem':
                $stmt = $pdo->query("SELECT COUNT(*) as total_files, SUM(size_bytes) as total_size FROM filesystem_index");
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode([
                    'total_files' => (int)$data['total_files'],
                    'total_size_gb' => round((float)$data['total_size'] / 1024 / 1024 / 1024, 2)
                ]);
                break;
            
            case 'systemai-status':
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM ai_leads");
                $leads = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM workers WHERE status = 'online'");
                $workers = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM tasks WHERE status = 'pending'");
                $tasks = $stmt->fetch(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'ai_leads_total' => (int)$leads['total'],
                    'workers_online' => (int)$workers['total'],
                    'tasks_pending' => (int)$tasks['total'],
                    'status' => 'operational'
                ]);
                break;
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// ACTION HANDLERS - Server-Side Updates
// ============================================

if (!empty($action)) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $type = $_POST['type'] ?? '';
        $id = $_POST['id'] ?? '';
        
        if ($action === 'edit') {
            if ($type === 'task' && $id) {
                $name = $_POST['name'] ?? '';
                $status = $_POST['status'] ?? '';
                $priority = $_POST['priority'] ?? '';
                
                $stmt = $pdo->prepare("UPDATE tasks SET name = ?, status = ?, priority = ? WHERE id = ?");
                $stmt->execute([$name, $status, $priority, $id]);
                
                echo json_encode(['success' => true, 'message' => 'Task updated']);
            }
            elseif ($type === 'ai_lead' && $id) {
                $name = $_POST['name'] ?? '';
                $status = $_POST['status'] ?? '';
                
                $stmt = $pdo->prepare("UPDATE ai_leads SET name = ?, status = ? WHERE id = ?");
                $stmt->execute([$name, $status, $id]);
                
                echo json_encode(['success' => true, 'message' => 'AI Lead updated']);
            }
            elseif ($type === 'worker' && $id) {
                $name = $_POST['name'] ?? '';
                $status = $_POST['status'] ?? '';
                
                $stmt = $pdo->prepare("UPDATE workers SET name = ?, status = ? WHERE id = ?");
                $stmt->execute([$name, $status, $id]);
                
                echo json_encode(['success' => true, 'message' => 'Worker updated']);
            }
        }
        elseif ($action === 'delete') {
            if ($type === 'task' && $id) {
                $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Task deleted']);
            }
            elseif ($type === 'ai_lead' && $id) {
                $stmt = $pdo->prepare("DELETE FROM ai_leads WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'AI Lead deleted']);
            }
        }
        elseif ($action === 'systemai-scan') {
            require_once '/var/www/systemai/managers/SystemAIAutonomous.php';
            $systemai = new SystemAIAutonomous($pdo);
            $result = $systemai->autonomousWorkSession('/var/www');
            echo json_encode(['success' => true, 'result' => $result]);
        }
        elseif ($action === 'systemai-cleanup') {
            $report_file = '/var/www/systemai/workspaces/GARBAGE_REPORT.json';
            if (file_exists($report_file)) {
                $report = json_decode(file_get_contents($report_file), true);
                $deleted = 0;
                foreach ($report['files'] as $file) {
                    if (file_exists($file['path']) && @unlink($file['path'])) {
                        $deleted++;
                    }
                }
                echo json_encode(['success' => true, 'deleted' => $deleted, 'freed_mb' => $report['total_size_mb']]);
            } else {
                echo json_encode(['success' => false, 'error' => 'No garbage report']);
            }
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>PRINCIPLE - Enterprise Control Center</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, monospace;
            background: linear-gradient(135deg, #0a0e27 0%, #1a1f3a 100%);
            color: #e0e0e0;
        }
        .header {
            position: fixed;
            top: 0; left: 0; right: 0;
            height: 60px;
            background: linear-gradient(90deg, rgba(10,14,39,0.98) 0%, rgba(26,31,58,0.98) 100%);
            border-bottom: 2px solid #00ff88;
            display: flex;
            align-items: center;
            padding: 0 30px;
            z-index: 1000;
        }
        .logo {
            color: #00ff88;
            font-size: 16px;
            font-weight: bold;
            letter-spacing: 2px;
            margin-right: 80px;
        }
        .top-nav {
            display: flex;
            gap: 50px;
            flex: 1;
        }
        .nav-item {
            color: #888;
            cursor: pointer;
            font-size: 11px;
            text-transform: uppercase;
            padding: 5px 0;
            border-bottom: 2px solid transparent;
            transition: all 0.3s;
        }
        .nav-item:hover, .nav-item.active {
            color: #00ff88;
            border-bottom-color: #00ff88;
        }
        .header-right {
            display: flex;
            gap: 15px;
            margin-left: auto;
            align-items: center;
        }
        .status-dot {
            width: 10px;
            height: 10px;
            background: #00ff88;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .badge {
            background: rgba(0,255,136,0.1);
            border: 1px solid #00ff88;
            color: #00ff88;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 9px;
            font-weight: bold;
        }
        .container {
            margin-top: 60px;
            display: flex;
            height: calc(100vh - 60px);
        }
        .sidebar {
            width: 280px;
            background: rgba(15,24,53,0.98);
            border-right: 1px solid rgba(0,255,136,0.2);
            padding: 25px;
            overflow-y: auto;
        }
        .sidebar-section {
            margin: 30px 0;
        }
        .sidebar-title {
            color: #00ff88;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 12px;
            font-weight: bold;
        }
        .sidebar-item {
            padding: 10px 15px;
            color: #888;
            cursor: pointer;
            font-size: 11px;
            margin: 5px 0;
            border-radius: 6px;
            border-left: 3px solid transparent;
            transition: all 0.3s;
        }
        .sidebar-item:hover, .sidebar-item.active {
            background: rgba(0,255,136,0.08);
            color: #00ff88;
            border-left-color: #00ff88;
        }
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .page-header {
            padding: 25px 35px;
            border-bottom: 1px solid rgba(0,255,136,0.2);
            background: rgba(15,24,53,0.5);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .page-info {
            flex: 1;
        }
        .page-title {
            color: #00ff88;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 8px;
        }
        .page-desc {
            color: #888;
            font-size: 11px;
        }
        .page-actions {
            display: flex;
            gap: 10px;
        }
        .workspace {
            flex: 1;
            padding: 25px 35px;
            overflow-y: auto;
        }
        .metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .metric {
            background: rgba(0,255,136,0.05);
            border: 1px solid rgba(0,255,136,0.2);
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        .metric-value {
            font-size: 28px;
            font-weight: bold;
            color: #00ff88;
            margin: 8px 0;
        }
        .metric-label {
            font-size: 10px;
            color: #888;
            text-transform: uppercase;
        }
        .data-section {
            background: rgba(0,255,136,0.03);
            border: 1px solid rgba(0,255,136,0.1);
            border-radius: 8px;
            margin-bottom: 25px;
            overflow: hidden;
        }
        .data-header {
            padding: 15px 20px;
            border-bottom: 1px solid rgba(0,255,136,0.1);
            background: rgba(0,255,136,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .data-header-title {
            color: #00ff88;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .data-header-count {
            color: #888;
            font-size: 10px;
        }
        .data-controls {
            display: flex;
            gap: 8px;
        }
        .btn {
            padding: 8px 16px;
            background: rgba(0,255,136,0.1);
            border: 1px solid #00ff88;
            color: #00ff88;
            border-radius: 6px;
            cursor: pointer;
            font-size: 9px;
            text-transform: uppercase;
            font-weight: bold;
            transition: all 0.3s;
        }
        .btn:hover {
            background: rgba(0,255,136,0.2);
        }
        .btn.danger {
            border-color: #ff4444;
            color: #ff4444;
            background: rgba(255,68,68,0.1);
        }
        .btn.danger:hover {
            background: rgba(255,68,68,0.2);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }
        thead {
            background: rgba(0,255,136,0.08);
            border-bottom: 1px solid rgba(0,255,136,0.2);
        }
        th {
            color: #00ff88;
            padding: 12px;
            text-align: left;
            font-weight: bold;
            text-transform: uppercase;
        }
        td {
            color: #888;
            padding: 10px 12px;
            border-bottom: 1px solid rgba(0,255,136,0.05);
        }
        tr:hover {
            background: rgba(0,255,136,0.08);
            color: #00ff88;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        .modal.active {
            display: flex;
        }
        .modal-content {
            background: rgba(15,24,53,0.98);
            border: 1px solid rgba(0,255,136,0.2);
            border-radius: 8px;
            padding: 30px;
            width: 90%;
            max-width: 500px;
        }
        .modal-title {
            color: #00ff88;
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-label {
            color: #888;
            font-size: 10px;
            text-transform: uppercase;
            margin-bottom: 5px;
            display: block;
        }
        .form-input {
            width: 100%;
            background: rgba(0,255,136,0.05);
            border: 1px solid rgba(0,255,136,0.2);
            color: #888;
            padding: 10px;
            border-radius: 6px;
            font-size: 11px;
            font-family: monospace;
        }
        .form-input:focus {
            outline: none;
            border-color: #00ff88;
            background: rgba(0,255,136,0.08);
            color: #00ff88;
        }
        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        .spinner {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid rgba(0,255,136,0.2);
            border-top: 2px solid #00ff88;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            margin-right: 8px;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">👑 PRINCIPLE</div>
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
                <div class="page-info">
                    <div class="page-title" id="pageTitle">Dashboard</div>
                    <div class="page-desc" id="pageDesc"></div>
                </div>
                <div class="page-actions" id="pageActions"></div>
            </div>
            
            <div class="workspace" id="workspace">
                Loading...
            </div>
        </div>
    </div>
    
    <div class="modal" id="editModal">
        <div class="modal-content">
            <div class="modal-title">Edit Item</div>
            <form id="editForm">
                <div class="form-group">
                    <label class="form-label">Name</label>
                    <input type="text" class="form-input" id="editName" placeholder="Name">
                </div>
                <div class="form-group">
                    <label class="form-label">Status</label>
                    <input type="text" class="form-input" id="editStatus" placeholder="Status">
                </div>
                <div class="form-group" id="editPriorityGroup" style="display:none;">
                    <label class="form-label">Priority</label>
                    <input type="text" class="form-input" id="editPriority" placeholder="Priority">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn" onclick="saveEdit()">Save</button>
                    <button type="button" class="btn danger" onclick="closeModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        const VIEWS = {
            'verwaltung': {
                title: '📋 Verwaltung',
                desc: 'Administration & Governance',
                sections: [
                    { label: '🏢 Unternehmen', api: 'companies' },
                    { label: '👤 Benutzer', api: 'users' }
                ]
            },
            'technik': {
                title: '⚙️ Technik',
                desc: 'Technical Infrastructure',
                sections: [
                    { label: '🤖 AI Leads', api: 'ai_leads' },
                    { label: '👷 Worker', api: 'workers' },
                    { label: '📁 Filesystem', api: 'filesystem' }
                ]
            },
            'systemai': {
                title: '🖥️ SystemAI',
                desc: 'AI-Driven Automation & Control',
                sections: [
                    { label: '🧠 AI Status', api: 'systemai-status' }
                ]
            },
            'unternehmen': {
                title: '📈 Unternehmen',
                desc: 'Business Operations',
                sections: [
                    { label: '📦 Projekte', api: 'projects' },
                    { label: '📌 Aufgaben', api: 'tasks' },
                    { label: '✅ Compliance', api: 'compliance' }
                ]
            }
        };
        
        let currentAPI = '';
        let currentLabel = '';
        let currentType = '';
        let allData = [];
        
        function buildUI() {
            const nav = document.getElementById('topNav');
            Object.keys(VIEWS).forEach((key, idx) => {
                const btn = document.createElement('div');
                btn.className = `nav-item ${idx === 0 ? 'active' : ''}`;
                btn.textContent = VIEWS[key].title;
                btn.onclick = (e) => {
                    document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
                    e.target.classList.add('active');
                    loadView(key);
                };
                nav.appendChild(btn);
            });
            
            const sidebar = document.getElementById('sidebar');
            Object.keys(VIEWS).forEach(viewKey => {
                const view = VIEWS[viewKey];
                const section = document.createElement('div');
                section.className = 'sidebar-section';
                
                const title = document.createElement('div');
                title.className = 'sidebar-title';
                title.textContent = view.title;
                section.appendChild(title);
                
                view.sections.forEach(item => {
                    const btn = document.createElement('div');
                    btn.className = 'sidebar-item';
                    btn.textContent = item.label;
                    btn.onclick = () => loadData(item.api, item.label);
                    section.appendChild(btn);
                });
                
                sidebar.appendChild(section);
            });
            
            loadView('verwaltung');
        }
        
        function loadView(key) {
            const view = VIEWS[key];
            document.getElementById('pageTitle').textContent = view.title;
            document.getElementById('pageDesc').textContent = view.desc;
            loadData(view.sections[0].api, view.sections[0].label);
        }
        
        async function loadData(api, label) {
            currentAPI = api;
            currentLabel = label;
            
            // Determine type from API
            if (api.includes('task')) currentType = 'task';
            else if (api.includes('ai_leads')) currentType = 'ai_lead';
            else if (api.includes('worker')) currentType = 'worker';
            
            // Show SystemAI actions
            const actions = document.getElementById('pageActions');
            actions.innerHTML = '';
            
            if (api === 'systemai-status') {
                const btn1 = document.createElement('button');
                btn1.className = 'btn';
                btn1.textContent = '🔍 Scan';
                btn1.onclick = () => systemaiAction('scan');
                actions.appendChild(btn1);
                
                const btn2 = document.createElement('button');
                btn2.className = 'btn danger';
                btn2.textContent = '🗑️ Cleanup';
                btn2.onclick = () => systemaiAction('cleanup');
                actions.appendChild(btn2);
            }
            
            try {
                const response = await fetch(`/?api=${api}`);
                const data = await response.json();
                
                allData = data.data || [];
                
                let html = '';
                
                if (data.ai_leads_total !== undefined) {
                    html += `<div class="metrics">
                        <div class="metric">
                            <div class="metric-label">AI Leads</div>
                            <div class="metric-value">${data.ai_leads_total}</div>
                        </div>
                        <div class="metric">
                            <div class="metric-label">Workers Online</div>
                            <div class="metric-value">${data.workers_online}</div>
                        </div>
                        <div class="metric">
                            <div class="metric-label">Tasks Pending</div>
                            <div class="metric-value">${data.tasks_pending}</div>
                        </div>
                    </div>`;
                }
                
                if (data.total_files !== undefined) {
                    html += `<div class="metrics">
                        <div class="metric">
                            <div class="metric-label">Files</div>
                            <div class="metric-value">${data.total_files}</div>
                        </div>
                        <div class="metric">
                            <div class="metric-label">Storage</div>
                            <div class="metric-value">${data.total_size_gb} GB</div>
                        </div>
                    </div>`;
                }
                
                if (allData.length > 0) {
                    html += `<div class="data-section">
                        <div class="data-header">
                            <div class="data-header-title">${label}</div>
                            <div class="data-header-count">${allData.length} Items</div>
                            <div class="data-controls">
                                <button class="btn" onclick="refreshData()">🔄 Refresh</button>
                            </div>
                        </div>
                        <table>
                            <thead><tr>`;
                    
                    const first = allData[0];
                    const cols = Object.keys(first).slice(0, 4);
                    cols.forEach(key => {
                        html += `<th>${key}</th>`;
                    });
                    html += `<th>Actions</th></tr></thead><tbody>`;
                    
                    allData.forEach((item) => {
                        html += '<tr>';
                        cols.forEach(key => {
                            let val = item[key];
                            if (typeof val === 'object') val = JSON.stringify(val);
                            html += `<td>${val}</td>`;
                        });
                        html += `<td>
                            <button class="btn" onclick="editItem('${item.id}', '${currentType}')">✏️ Edit</button>
                            <button class="btn danger" onclick="deleteItem('${item.id}', '${currentType}')">🗑️ Delete</button>
                        </td>`;
                        html += '</tr>';
                    });
                    
                    html += `</tbody></table></div>`;
                }
                
                document.getElementById('workspace').innerHTML = html;
            } catch (e) {
                document.getElementById('workspace').innerHTML = `<div style="color: #ff4444; padding: 20px;">Error: ${e.message}</div>`;
            }
        }
        
        function editItem(id, type) {
            const item = allData.find(i => i.id == id);
            if (!item) return;
            
            document.getElementById('editName').value = item.name || '';
            document.getElementById('editStatus').value = item.status || '';
            
            const priorityGroup = document.getElementById('editPriorityGroup');
            if (type === 'task') {
                priorityGroup.style.display = 'block';
                document.getElementById('editPriority').value = item.priority || '';
            } else {
                priorityGroup.style.display = 'none';
            }
            
            window.currentItem = { id, type };
            document.getElementById('editModal').classList.add('active');
        }
        
        function deleteItem(id, type) {
            if (!confirm('Delete this item?')) return;
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('type', type);
            formData.append('id', id);
            
            fetch('/', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        loadData(currentAPI, currentLabel);
                    } else {
                        alert('Error: ' + data.error);
                    }
                });
        }
        
        function closeModal() {
            document.getElementById('editModal').classList.remove('active');
        }
        
        async function saveEdit() {
            if (!window.currentItem) return;
            
            const formData = new FormData();
            formData.append('action', 'edit');
            formData.append('type', window.currentItem.type);
            formData.append('id', window.currentItem.id);
            formData.append('name', document.getElementById('editName').value);
            formData.append('status', document.getElementById('editStatus').value);
            if (window.currentItem.type === 'task') {
                formData.append('priority', document.getElementById('editPriority').value);
            }
            
            const response = await fetch('/', { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                closeModal();
                loadData(currentAPI, currentLabel);
            } else {
                alert('Error: ' + data.error);
            }
        }
        
        async function systemaiAction(action) {
            if (!confirm(`Execute SystemAI ${action}?`)) return;
            
            const formData = new FormData();
            formData.append('action', `systemai-${action}`);
            
            const response = await fetch('/', { method: 'POST', body: formData });
            const data = await response.json();
            
            if (data.success) {
                alert(`✓ ${action} completed`);
                loadData(currentAPI, currentLabel);
            } else {
                alert(`Error: ${data.error}`);
            }
        }
        
        function refreshData() {
            loadData(currentAPI, currentLabel);
        }
        
        buildUI();
    </script>
</body>
</html>
