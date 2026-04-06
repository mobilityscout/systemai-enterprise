<?php
/**
 * SYSTEMAI - Real Autonomous AI Worker
 * Mit ECHTEN Datenbank-Tabellen (KEINE task_queue_distributed)
 */

class SystemAIAutonomous {
    private $pdo;
    private $workspace_root = '/var/www/systemai/workspaces';
    private $log_file;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->log_file = '/var/www/systemai/logs/systemai_autonomous.log';
        @mkdir(dirname($this->log_file), 0755, true);
    }
    
    /**
     * AUTONOMOUS WORK SESSION
     */
    public function autonomousWorkSession($target_path = '/var/www') {
        $session_id = 'session_' . uniqid();
        
        $this->log("=== SYSTEMAI AUTONOMOUS SESSION START ===");
        $this->log("Session ID: $session_id");
        $this->log("Target: $target_path");
        $this->log("");
        
        try {
            // Phase 1: SCAN
            $this->log("[PHASE 1] Scanning filesystem...");
            $scan_result = $this->scanFilesystem($target_path);
            $this->log("✓ Found " . count($scan_result['files']) . " files");
            $this->log("✓ Found " . count($scan_result['directories']) . " directories");
            $this->log("✓ Total size: " . round($scan_result['total_size'] / 1024 / 1024, 2) . " MB");
            $this->log("");
            
            // Phase 2: CLASSIFY
            $this->log("[PHASE 2] Classifying files...");
            $classification = $this->classifyFiles($scan_result['files']);
            $this->log("✓ Projects: " . count($classification['projects']));
            $this->log("✓ Garbage: " . count($classification['garbage']));
            $this->log("✓ Config: " . count($classification['config']));
            $this->log("✓ Code: " . count($classification['code']));
            $this->log("✓ Data: " . count($classification['data']));
            $this->log("");
            
            // Phase 3: STORE IN DATABASE
            $this->log("[PHASE 3] Storing in database...");
            $indexed_count = $this->storeInDatabase($classification);
            $this->log("✓ Indexed $indexed_count files");
            $this->log("");
            
            // Phase 4: CREATE WORKSPACE
            $this->log("[PHASE 4] Creating workspace structure...");
            $this->createWorkspaceStructure($classification);
            $this->log("✓ Workspace created");
            $this->log("");
            
            // Phase 5: GARBAGE REPORT
            $this->log("[PHASE 5] Generating garbage report...");
            $garbage_report = $this->generateGarbageReport($classification['garbage']);
            $this->log("✓ Identified " . $garbage_report['total_files'] . " garbage files");
            $this->log("✓ Total garbage: " . round($garbage_report['total_size_mb'], 2) . " MB");
            $this->log("");
            
            // Phase 6: CREATE WORKERS (in tasks table statt workers)
            $this->log("[PHASE 6] Creating worker assignments...");
            $workers = $this->createWorkerAssignments($classification);
            $this->log("✓ Created " . count($workers) . " workers");
            foreach ($workers as $w) {
                $this->log("  - $w");
            }
            $this->log("");
            
            // Phase 7: LOG DECISIONS
            $this->log("[PHASE 7] Logging decisions...");
            $this->logDecisions($session_id, $classification);
            $this->log("✓ Decisions logged");
            $this->log("");
            
            $this->log("=== SESSION COMPLETE ===");
            
            return [
                'status' => 'success',
                'session_id' => $session_id,
                'files_scanned' => count($scan_result['files']),
                'directories_scanned' => count($scan_result['directories']),
                'total_size_mb' => round($scan_result['total_size'] / 1024 / 1024, 2),
                'projects_found' => count($classification['projects']),
                'garbage_found' => count($classification['garbage']),
                'garbage_size_mb' => round($garbage_report['total_size_mb'], 2),
                'workers_created' => count($workers),
                'files_indexed' => $indexed_count
            ];
            
        } catch (Exception $e) {
            $this->log("[ERROR] " . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * PHASE 1: SCAN FILESYSTEM
     */
    private function scanFilesystem($path, $max_depth = 5) {
        $result = [
            'files' => [],
            'directories' => [],
            'total_size' => 0
        ];
        
        $this->recursiveScan($path, 0, $max_depth, $result);
        return $result;
    }
    
    private function recursiveScan($path, $depth, $max_depth, &$result) {
        if ($depth > $max_depth || !is_dir($path)) return;
        
        try {
            $items = @scandir($path);
            if (!$items) return;
            
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                
                $full_path = $path . '/' . $item;
                
                if (is_file($full_path)) {
                    $result['files'][] = [
                        'path' => $full_path,
                        'name' => $item,
                        'size' => filesize($full_path),
                        'mtime' => filemtime($full_path),
                        'ext' => pathinfo($full_path, PATHINFO_EXTENSION)
                    ];
                    $result['total_size'] += $result['files'][count($result['files']) - 1]['size'];
                } elseif (is_dir($full_path) && !is_link($full_path)) {
                    $result['directories'][] = $full_path;
                    $this->recursiveScan($full_path, $depth + 1, $max_depth, $result);
                }
            }
        } catch (Exception $e) {
            // Skip inaccessible directories
        }
    }
    
    /**
     * PHASE 2: CLASSIFY FILES
     */
    private function classifyFiles($files) {
        $classification = [
            'projects' => [],
            'garbage' => [],
            'config' => [],
            'code' => [],
            'data' => []
        ];
        
        foreach ($files as $file) {
            $basename = $file['name'];
            $path = $file['path'];
            $ext = $file['ext'];
            
            // Check if garbage
            if (preg_match('/(node_modules|vendor|__pycache__|\.cache|\.log$)/', $path)) {
                $classification['garbage'][] = $file;
            }
            // Check if config
            elseif (preg_match('/\.(conf|config|yml|yaml|ini|env)$/', $basename)) {
                $classification['config'][] = $file;
            }
            // Check if code
            elseif (preg_match('/\.(php|js|py|go|rs|java|cpp|c|h|ts|jsx)$/', $ext)) {
                $classification['code'][] = $file;
            }
            // Otherwise data
            else {
                $classification['data'][] = $file;
            }
        }
        
        return $classification;
    }
    
    /**
     * PHASE 3: STORE IN DATABASE (filesystem_index - ECHTE Tabelle)
     */
    private function storeInDatabase($classification) {
        $stmt = $this->pdo->prepare("
            INSERT INTO filesystem_index 
            (path, name, type, size_bytes, owner, permissions, last_modified, is_indexed, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, true, NOW())
            ON CONFLICT (path) DO NOTHING
        ");
        
        $indexed = 0;
        $all_files = array_merge(
            $classification['projects'],
            $classification['garbage'],
            $classification['config'],
            $classification['code'],
            $classification['data']
        );
        
        foreach ($all_files as $file) {
            try {
                $owner = 'unknown';
                if (function_exists('posix_getpwuid')) {
                    $pw = posix_getpwuid(fileowner($file['path']));
                    if ($pw) $owner = $pw['name'];
                }
                
                $perms = substr(sprintf('%o', fileperms($file['path'])), -4);
                $mtime = date('Y-m-d H:i:s', $file['mtime']);
                $type = $file['ext'] ?: 'unknown';
                
                $stmt->execute([
                    $file['path'],
                    $file['name'],
                    $type,
                    $file['size'],
                    $owner,
                    $perms,
                    $mtime
                ]);
                
                $indexed++;
            } catch (Exception $e) {
                // Skip if insert fails
            }
        }
        
        return $indexed;
    }
    
    /**
     * PHASE 4: CREATE WORKSPACE STRUCTURE
     */
    private function createWorkspaceStructure($classification) {
        $workspace = $this->workspace_root . '/data_trees';
        @mkdir($workspace, 0755, true);
        
        $dirs = [
            'projects' => 'Discovered Projects',
            'garbage' => 'Identified Garbage (Ready for Cleanup)',
            'config' => 'Configuration Files',
            'code' => 'Source Code',
            'data' => 'Data Files'
        ];
        
        foreach ($dirs as $dir => $desc) {
            $dir_path = "$workspace/$dir";
            @mkdir($dir_path, 0755, true);
            file_put_contents("$dir_path/.info", $desc);
            
            if (isset($classification[$dir])) {
                $index = [
                    'category' => $dir,
                    'description' => $desc,
                    'count' => count($classification[$dir]),
                    'files' => array_map(function($f) {
                        return [
                            'name' => $f['name'],
                            'path' => $f['path'],
                            'size_mb' => round($f['size'] / 1024 / 1024, 2)
                        ];
                    }, $classification[$dir])
                ];
                
                file_put_contents(
                    "$dir_path/INDEX.json",
                    json_encode($index, JSON_PRETTY_PRINT)
                );
            }
        }
    }
    
    /**
     * PHASE 5: GARBAGE REPORT
     */
    private function generateGarbageReport($garbage_files) {
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'total_files' => count($garbage_files),
            'total_size_mb' => 0,
            'files' => []
        ];
        
        foreach ($garbage_files as $file) {
            $size_mb = $file['size'] / 1024 / 1024;
            $report['total_size_mb'] += $size_mb;
            $report['files'][] = [
                'path' => $file['path'],
                'size_mb' => round($size_mb, 2),
                'type' => $file['ext']
            ];
        }
        
        usort($report['files'], function($a, $b) {
            return $b['size_mb'] <=> $a['size_mb'];
        });
        
        file_put_contents(
            $this->workspace_root . '/GARBAGE_REPORT.json',
            json_encode($report, JSON_PRETTY_PRINT)
        );
        
        return $report;
    }
    
    /**
     * PHASE 6: CREATE WORKER ASSIGNMENTS (in tasks table)
     */
    private function createWorkerAssignments($classification) {
        $workers = [];
        
        try {
            // Create tasks für Worker (ECHTE tasks Tabelle)
            $worker_types = [
                'scanner' => 'Filesystem Scanner',
                'cleaner' => 'Garbage Cleaner',
                'organizer' => 'Data Organizer'
            ];
            
            $insert = $this->pdo->prepare("
                INSERT INTO tasks (name, status, priority, created_at)
                VALUES (?, 'pending', 'high', NOW())
            ");
            
            foreach ($worker_types as $type => $desc) {
                $worker_name = 'systemai_' . $type . '_' . date('YmdHi');
                $insert->execute([$worker_name . ': ' . $desc]);
                $workers[] = $worker_name;
            }
        } catch (Exception $e) {
            $this->log("[WARN] Could not create workers: " . $e->getMessage());
        }
        
        return $workers;
    }
    
    /**
     * PHASE 7: LOG DECISIONS
     */
    private function logDecisions($session_id, $classification) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO principle_decisions 
                (decision_id, organization_id, decision_type, policy_content, status, created_at)
                VALUES (?, 1, 'systemai_scan', ?, 'completed', NOW())
            ");
            
            $content = [
                'session_id' => $session_id,
                'projects_found' => count($classification['projects']),
                'garbage_identified' => count($classification['garbage']),
                'config_files' => count($classification['config']),
                'code_files' => count($classification['code']),
                'data_files' => count($classification['data']),
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            $stmt->execute([
                'decision_' . $session_id,
                json_encode($content)
            ]);
        } catch (Exception $e) {
            $this->log("[WARN] Could not log decisions: " . $e->getMessage());
        }
    }
    
    /**
     * LOGGING
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $log_entry = "[$timestamp] $message";
        
        file_put_contents($this->log_file, $log_entry . "\n", FILE_APPEND);
        echo $log_entry . "\n";
    }
}
?>
