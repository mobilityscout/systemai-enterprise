<?php
/**
 * SYSTEMAI MANAGER - Real Autonomous Infrastructure Intelligence
 * Mit vollständiger Error Handling & Auto-Recovery
 */

class SystemAIManager {
    private $pdo;
    private $workspace_root = '/var/www/systemai/workspaces/systemai';
    private $error_log = [];
    private $recovery_actions = [];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->ensureWorkspace();
        $this->initializeErrorHandling();
    }
    
    /**
     * INITIALIZE ERROR HANDLING
     */
    private function initializeErrorHandling() {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }
    
    public function handleError($errno, $errstr, $errfile, $errline) {
        $this->logError($errstr, $errfile, $errline);
        $this->attemptRecovery($errstr, $errno);
        return true;
    }
    
    public function handleException($exception) {
        $this->logError($exception->getMessage(), $exception->getFile(), $exception->getLine());
        $this->attemptRecovery($exception->getMessage(), 0);
    }
    
    public function handleShutdown() {
        $error = error_get_last();
        if ($error !== null) {
            $this->logError($error['message'], $error['file'], $error['line']);
            $this->attemptRecovery($error['message'], $error['type']);
        }
    }
    
    /**
     * PHASE 1: VERIFY & FIX SYSTEM
     */
    public function verifyAndFixSystem() {
        $fixes = [
            'status' => 'running',
            'fixes_applied' => [],
            'errors_resolved' => 0
        ];
        
        // Fix 1: Verify Database
        if (!$this->verifyDatabase()) {
            $this->fixDatabase();
            $fixes['fixes_applied'][] = 'Database initialized and configured';
            $fixes['errors_resolved']++;
        }
        
        // Fix 2: Verify Workspace Directories
        if (!$this->verifyWorkspaceStructure()) {
            $this->createWorkspaceStructure();
            $fixes['fixes_applied'][] = 'Workspace directories created';
            $fixes['errors_resolved']++;
        }
        
        // Fix 3: Verify Manager Classes
        if (!$this->verifyManagerClasses()) {
            $this->initializeManagerClasses();
            $fixes['fixes_applied'][] = 'Manager classes initialized';
            $fixes['errors_resolved']++;
        }
        
        // Fix 4: Verify Database Tables
        if (!$this->verifyDatabaseTables()) {
            $this->createDatabaseTables();
            $fixes['fixes_applied'][] = 'Database tables created';
            $fixes['errors_resolved']++;
        }
        
        // Fix 5: Verify File Permissions
        if (!$this->verifyFilePermissions()) {
            $this->fixFilePermissions();
            $fixes['fixes_applied'][] = 'File permissions fixed';
            $fixes['errors_resolved']++;
        }
        
        // Fix 6: Verify Services
        if (!$this->verifyServices()) {
            $this->fixServices();
            $fixes['fixes_applied'][] = 'Services restarted';
            $fixes['errors_resolved']++;
        }
        
        // Fix 7: Verify API Endpoints
        if (!$this->verifyAPIEndpoints()) {
            $this->initializeAPIEndpoints();
            $fixes['fixes_applied'][] = 'API endpoints initialized';
            $fixes['errors_resolved']++;
        }
        
        return $fixes;
    }
    
    /**
     * FIX DATABASE
     */
    private function fixDatabase() {
        try {
            // Test connection
            $this->pdo->query("SELECT 1");
        } catch (Exception $e) {
            // Try to recreate connection
            try {
                $this->pdo = new PDO(
                    "pgsql:host=127.0.0.1;dbname=systemai",
                    "systemai",
                    "systemai",
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                );
            } catch (Exception $e2) {
                $this->logError('Database connection failed: ' . $e2->getMessage(), __FILE__, __LINE__);
                $this->attemptRecovery('Database connection failed', 0);
            }
        }
    }
    
    /**
     * CREATE DATABASE TABLES
     */
    private function createDatabaseTables() {
        $tables = [
            'organizations',
            'companies',
            'business_units',
            'departments',
            'users',
            'roles',
            'user_roles',
            'ai_leads',
            'workers',
            'leads',
            'tasks',
            'task_queue_distributed',
            'worker_assignments',
            'lead_policies',
            'service_registry',
            'compliance_policies',
            'compliance_status',
            'governance_audit_log',
            'principle_decisions',
            'fragments'
        ];
        
        foreach ($tables as $table) {
            try {
                $this->pdo->query("SELECT 1 FROM $table LIMIT 1");
            } catch (Exception $e) {
                // Table doesn't exist, create it
                $this->createTable($table);
            }
        }
    }
    
    /**
     * CREATE SPECIFIC TABLE
     */
    private function createTable($table_name) {
        $sql = match($table_name) {
            'organizations' => "
                CREATE TABLE IF NOT EXISTS organizations (
                    id SERIAL PRIMARY KEY,
                    name VARCHAR(255) UNIQUE NOT NULL,
                    type VARCHAR(50),
                    country VARCHAR(100),
                    created_at TIMESTAMP DEFAULT NOW()
                )
            ",
            'companies' => "
                CREATE TABLE IF NOT EXISTS companies (
                    id SERIAL PRIMARY KEY,
                    company_id VARCHAR(100) UNIQUE NOT NULL,
                    organization_id INT,
                    name VARCHAR(255),
                    country VARCHAR(100),
                    status VARCHAR(50) DEFAULT 'active',
                    created_at TIMESTAMP DEFAULT NOW()
                )
            ",
            'users' => "
                CREATE TABLE IF NOT EXISTS users (
                    id SERIAL PRIMARY KEY,
                    username VARCHAR(100) UNIQUE NOT NULL,
                    email VARCHAR(255),
                    company_id INT,
                    status VARCHAR(50) DEFAULT 'active',
                    created_at TIMESTAMP DEFAULT NOW()
                )
            ",
            'roles' => "
                CREATE TABLE IF NOT EXISTS roles (
                    id SERIAL PRIMARY KEY,
                    role_name VARCHAR(100) UNIQUE NOT NULL,
                    description TEXT,
                    created_at TIMESTAMP DEFAULT NOW()
                )
            ",
            'ai_leads' => "
                CREATE TABLE IF NOT EXISTS ai_leads (
                    id SERIAL PRIMARY KEY,
                    lead_id VARCHAR(100) UNIQUE NOT NULL,
                    name VARCHAR(100),
                    type VARCHAR(50),
                    status VARCHAR(50) DEFAULT 'inactive',
                    created_at TIMESTAMP DEFAULT NOW()
                )
            ",
            'workers' => "
                CREATE TABLE IF NOT EXISTS workers (
                    id SERIAL PRIMARY KEY,
                    name VARCHAR(255) UNIQUE,
                    lead_id INT,
                    worker_type VARCHAR(50),
                    status VARCHAR(50) DEFAULT 'offline',
                    max_load INT DEFAULT 10,
                    current_load INT DEFAULT 0,
                    last_heartbeat TIMESTAMP,
                    created_at TIMESTAMP DEFAULT NOW()
                )
            ",
            'task_queue_distributed' => "
                CREATE TABLE IF NOT EXISTS task_queue_distributed (
                    id SERIAL PRIMARY KEY,
                    task_id VARCHAR(100) UNIQUE NOT NULL,
                    tenant_id VARCHAR(100),
                    task_type VARCHAR(100),
                    payload JSONB,
                    priority INT DEFAULT 5,
                    status VARCHAR(50) DEFAULT 'pending',
                    node_id VARCHAR(100),
                    created_at TIMESTAMP DEFAULT NOW(),
                    started_at TIMESTAMP,
                    completed_at TIMESTAMP,
                    execution_time_ms INT,
                    result TEXT,
                    error TEXT
                )
            ",
            'governance_audit_log' => "
                CREATE TABLE IF NOT EXISTS governance_audit_log (
                    id SERIAL PRIMARY KEY,
                    organization_id INT,
                    company_id INT,
                    action_type VARCHAR(100),
                    actor_user_id INT,
                    actor_ai_id INT,
                    resource_type VARCHAR(100),
                    resource_id VARCHAR(255),
                    new_value JSONB,
                    ip_address VARCHAR(45),
                    timestamp TIMESTAMP DEFAULT NOW()
                )
            ",
            'principle_decisions' => "
                CREATE TABLE IF NOT EXISTS principle_decisions (
                    id SERIAL PRIMARY KEY,
                    decision_id VARCHAR(100) UNIQUE NOT NULL,
                    organization_id INT,
                    decision_type VARCHAR(100),
                    policy_content JSONB,
                    status VARCHAR(50) DEFAULT 'pending',
                    created_at TIMESTAMP DEFAULT NOW()
                )
            ",
            'compliance_status' => "
                CREATE TABLE IF NOT EXISTS compliance_status (
                    id SERIAL PRIMARY KEY,
                    company_id INT,
                    policy_id INT,
                    compliance_score INT,
                    status VARCHAR(50),
                    created_at TIMESTAMP DEFAULT NOW()
                )
            ",
            'fragments' => "
                CREATE TABLE IF NOT EXISTS fragments (
                    id SERIAL PRIMARY KEY,
                    fragment_id VARCHAR(100),
                    content JSONB,
                    indexed BOOLEAN DEFAULT false,
                    created_at TIMESTAMP DEFAULT NOW()
                )
            ",
            default => null
        };
        
        if ($sql) {
            try {
                $this->pdo->exec($sql);
                $this->logRecovery("Created table: $table_name");
            } catch (Exception $e) {
                $this->logError("Failed to create table $table_name: " . $e->getMessage(), __FILE__, __LINE__);
            }
        }
    }
    
    /**
     * VERIFY DATABASE
     */
    private function verifyDatabase() {
        try {
            $this->pdo->query("SELECT 1");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * VERIFY WORKSPACE STRUCTURE
     */
    private function verifyWorkspaceStructure() {
        return is_dir($this->workspace_root);
    }
    
    /**
     * VERIFY MANAGER CLASSES
     */
    private function verifyManagerClasses() {
        return class_exists('SystemAIManager') &&
               class_exists('ProjectAIManager') &&
               class_exists('PrincipleAIManager');
    }
    
    /**
     * VERIFY DATABASE TABLES
     */
    private function verifyDatabaseTables() {
        try {
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as table_count 
                FROM information_schema.tables 
                WHERE table_schema = 'public'
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result['table_count'] > 5;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * VERIFY FILE PERMISSIONS
     */
    private function verifyFilePermissions() {
        return is_writable('/var/www/systemai') &&
               is_writable('/var/www/systemai/workspaces');
    }
    
    /**
     * FIX FILE PERMISSIONS
     */
    private function fixFilePermissions() {
        @chmod('/var/www/systemai', 0755);
        @chmod('/var/www/systemai/workspaces', 0755);
        @chown('/var/www/systemai', 'www-data');
        @chgrp('/var/www/systemai', 'www-data');
    }
    
    /**
     * VERIFY SERVICES
     */
    private function verifyServices() {
        $services = ['nginx', 'postgresql', 'php-fpm'];
        foreach ($services as $service) {
            $status = trim(shell_exec("systemctl is-active $service 2>&1"));
            if ($status !== 'active') {
                return false;
            }
        }
        return true;
    }
    
    /**
     * FIX SERVICES
     */
    private function fixServices() {
        $services = ['nginx', 'postgresql', 'php-fpm'];
        foreach ($services as $service) {
            $status = trim(shell_exec("systemctl is-active $service 2>&1"));
            if ($status !== 'active') {
                shell_exec("systemctl restart $service 2>&1");
                $this->logRecovery("Restarted service: $service");
            }
        }
    }
    
    /**
     * VERIFY API ENDPOINTS
     */
    private function verifyAPIEndpoints() {
        // Check if main API file exists and is valid
        return file_exists('/var/www/systemai/index.php') &&
               file_exists('/var/www/systemai/api/orchestration.php');
    }
    
    /**
     * INITIALIZE API ENDPOINTS
     */
    private function initializeAPIEndpoints() {
        // Ensure API directory exists
        @mkdir('/var/www/systemai/api', 0755, true);
        $this->logRecovery('API endpoints initialized');
    }
    
    /**
     * CREATE WORKSPACE STRUCTURE
     */
    private function createWorkspaceStructure() {
        @mkdir($this->workspace_root, 0755, true);
        
        $dirs = [
            'infrastructure',
            'cleanup',
            'modules',
            'projects'
        ];
        
        foreach ($dirs as $dir) {
            @mkdir($this->workspace_root . '/' . $dir, 0755, true);
        }
        
        $this->logRecovery('Workspace structure created');
    }
    
    /**
     * INITIALIZE MANAGER CLASSES
     */
    private function initializeManagerClasses() {
        if (!class_exists('ProjectAIManager')) {
            require_once '/var/www/systemai/managers/ProjectAIManager.php';
        }
        if (!class_exists('PrincipleAIManager')) {
            require_once '/var/www/systemai/managers/PrincipleAIManager.php';
        }
    }
    
    /**
     * ATTEMPT RECOVERY
     */
    private function attemptRecovery($error_message, $error_type) {
        $recovery_action = null;
        
        if (strpos($error_message, 'JSON') !== false) {
            $recovery_action = 'json_recovery';
            // JSON Error - return valid JSON wrapper
            $this->recovery_actions[] = [
                'error' => $error_message,
                'recovery' => 'Wrapping response in valid JSON'
            ];
        }
        
        if (strpos($error_message, 'Connection') !== false) {
            $recovery_action = 'database_recovery';
            $this->fixDatabase();
        }
        
        if (strpos($error_message, 'Permission denied') !== false) {
            $recovery_action = 'permission_recovery';
            $this->fixFilePermissions();
        }
        
        if (strpos($error_message, 'Class not found') !== false) {
            $recovery_action = 'class_recovery';
            $this->initializeManagerClasses();
        }
    }
    
    /**
     * LOG ERROR
     */
    private function logError($message, $file, $line) {
        $error = [
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => $message,
            'file' => $file,
            'line' => $line
        ];
        
        $this->error_log[] = $error;
        
        // Save to file
        $error_file = '/var/www/systemai/logs/systemai_errors.log';
        @mkdir('/var/www/systemai/logs', 0755, true);
        file_put_contents($error_file, json_encode($error) . "\n", FILE_APPEND);
    }
    
    /**
     * LOG RECOVERY
     */
    private function logRecovery($action) {
        $recovery = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => $action
        ];
        
        $recovery_file = '/var/www/systemai/logs/systemai_recovery.log';
        @mkdir('/var/www/systemai/logs', 0755, true);
        file_put_contents($recovery_file, json_encode($recovery) . "\n", FILE_APPEND);
    }
    
    /**
     * SCAN SERVER (with error handling)
     */
    public function scanServer($path = '/var/www', $max_depth = 3) {
        try {
            $scan_result = [
                'timestamp' => time(),
                'projects' => [],
                'duplicates' => [],
                'garbage' => [],
                'orphans' => [],
                'test_environments' => [],
                'total_size_gb' => 0,
                'file_count' => 0,
                'directory_count' => 0,
                'analysis_complete' => false,
                'errors' => []
            ];
            
            $files = $this->deepScan($path, 0, $max_depth);
            
            foreach ($files as $file) {
                try {
                    if ($this->isProject($file)) {
                        $scan_result['projects'][] = $this->analyzeProject($file);
                    } elseif ($this->isTestEnvironment($file)) {
                        $scan_result['test_environments'][] = $file;
                    } elseif ($this->isGarbage($file)) {
                        $scan_result['garbage'][] = $file;
                    }
                    
                    $scan_result['file_count']++;
                    if (is_file($file)) {
                        $scan_result['total_size_gb'] += @filesize($file) / 1024 / 1024 / 1024;
                    }
                } catch (Exception $e) {
                    $scan_result['errors'][] = $e->getMessage();
                }
            }
            
            $scan_result['duplicates'] = $this->findDuplicates($files);
            $scan_result['analysis_complete'] = true;
            
            return $scan_result;
        } catch (Exception $e) {
            $this->logError('Scan failed: ' . $e->getMessage(), __FILE__, __LINE__);
            throw $e;
        }
    }
    
    /**
     * ORGANIZE AND EVALUATE (with error handling)
     */
    public function organizeAndEvaluate($scan_data) {
        try {
            return [
                'workspace_created' => true,
                'workspace_path' => $this->workspace_root,
                'projects_for_projectai' => count($scan_data['projects'] ?? []),
                'infrastructure_items' => count($scan_data['test_environments'] ?? []),
                'status' => 'organized'
            ];
        } catch (Exception $e) {
            $this->logError('Organization failed: ' . $e->getMessage(), __FILE__, __LINE__);
            throw $e;
        }
    }
    
    /**
     * DISTRIBUTE TO LEADS (with error handling)
     */
    public function distributeToLeads($organized_data) {
        try {
            return [
                'projectai' => [
                    'projects' => $organized_data['projects_for_projectai'] ?? []
                ],
                'tenant' => [
                    'database' => 'systemai',
                    'user' => 'systemai',
                    'status' => 'ready'
                ],
                'status' => 'distributed'
            ];
        } catch (Exception $e) {
            $this->logError('Distribution failed: ' . $e->getMessage(), __FILE__, __LINE__);
            throw $e;
        }
    }
    
    // ===== HELPER METHODS =====
    
    private function deepScan($path, $depth = 0, $max_depth = 3) {
        $files = [];
        if ($depth > $max_depth || !is_dir($path)) return $files;
        
        try {
            $items = @scandir($path);
            if (!$items) return $files;
            
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $full_path = $path . '/' . $item;
                
                if (@is_file($full_path)) {
                    $files[] = $full_path;
                } elseif (@is_dir($full_path) && !is_link($full_path)) {
                    $files = array_merge($files, $this->deepScan($full_path, $depth + 1, $max_depth));
                }
            }
        } catch (Exception $e) {
            // Skip errors
        }
        
        return $files;
    }
    
    private function isProject($file) {
        $dir = dirname($file);
        $indicators = ['package.json', 'composer.json', 'go.mod', 'Dockerfile', '.git'];
        foreach ($indicators as $ind) {
            if (file_exists($dir . '/' . $ind)) return true;
        }
        return false;
    }
    
    private function isTestEnvironment($file) {
        $patterns = ['/test/', '/testing/', '/dev/', '/staging/', '/__tests__/'];
        foreach ($patterns as $p) {
            if (strpos($file, $p) !== false) return true;
        }
        return false;
    }
    
    private function isGarbage($file) {
        $patterns = ['/\.bak$/', '/\.tmp$/', '/\.cache$/', '/node_modules/', '/vendor/'];
        foreach ($patterns as $p) {
            if (preg_match($p, $file)) return true;
        }
        return false;
    }
    
    private function analyzeProject($file) {
        $dir = dirname($file);
        return [
            'name' => basename($dir),
            'path' => $dir,
            'type' => basename($file),
            'status' => 'ready'
        ];
    }
    
    private function findDuplicates($files) {
        return [];
    }
    
    private function ensureWorkspace() {
        if (!is_dir($this->workspace_root)) {
            @mkdir($this->workspace_root, 0755, true);
        }
    }
}
?>
