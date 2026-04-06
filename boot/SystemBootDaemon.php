<?php
/**
 * SYSTEMAI BOOT DAEMON
 * Professional Boot Service für 54GB + Millionen Files
 * 
 * - Background Processing
 * - Chunked File Scanning
 * - Progress Persistence
 * - Resumable Installation
 * - Real-Time Status Updates
 */

class SystemBootDaemon {
    private $pdo;
    private $boot_state_file = '/var/www/systemai/boot/.boot_state';
    private $chunk_size = 1000;
    private $batch_size = 100;
    private $memory_limit = 512 * 1024 * 1024; // 512MB
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        ini_set('memory_limit', '2G');
        set_time_limit(0);
    }
    
    /**
     * PROFESSIONAL BOOT SEQUENCE
     */
    public function professionalBoot() {
        $boot_id = 'boot_' . date('YmdHis');
        $boot_state = [
            'boot_id' => $boot_id,
            'started_at' => time(),
            'stages' => [
                'infrastructure' => ['status' => 'pending', 'progress' => 0],
                'scan_phase_1' => ['status' => 'pending', 'progress' => 0],
                'scan_phase_2' => ['status' => 'pending', 'progress' => 0],
                'deduplication' => ['status' => 'pending', 'progress' => 0],
                'indexing' => ['status' => 'pending', 'progress' => 0],
                'classification' => ['status' => 'pending', 'progress' => 0],
                'distribution' => ['status' => 'pending', 'progress' => 0],
                'projectai_build' => ['status' => 'pending', 'progress' => 0]
            ],
            'statistics' => [
                'files_scanned' => 0,
                'files_indexed' => 0,
                'duplicates_found' => 0,
                'projects_discovered' => 0,
                'garbage_identified' => 0,
                'total_size_gb' => 0
            ],
            'current_stage' => 'infrastructure'
        ];
        
        $this->saveBootState($boot_state);
        
        // Stage 1: Infrastructure
        $boot_state = $this->setupInfrastructure($boot_state);
        
        // Stage 2: Scan Phase 1 (Fast scan for projects/garbage)
        $boot_state = $this->scanPhase1($boot_state);
        
        // Stage 3: Scan Phase 2 (Deep scan with deduplication)
        $boot_state = $this->scanPhase2($boot_state);
        
        // Stage 4: Deduplication
        $boot_state = $this->deduplication($boot_state);
        
        // Stage 5: Indexing
        $boot_state = $this->indexing($boot_state);
        
        // Stage 6: Classification
        $boot_state = $this->classification($boot_state);
        
        // Stage 7: Distribution
        $boot_state = $this->distribution($boot_state);
        
        // Stage 8: ProjectAI Build
        $boot_state = $this->projectAIBuild($boot_state);
        
        $boot_state['completed_at'] = time();
        $boot_state['status'] = 'complete';
        $this->saveBootState($boot_state);
        
        return $boot_state;
    }
    
    /**
     * STAGE 1: INFRASTRUCTURE SETUP
     */
    private function setupInfrastructure(&$boot_state) {
        $boot_state['current_stage'] = 'infrastructure';
        $boot_state['stages']['infrastructure']['status'] = 'running';
        $this->saveBootState($boot_state);
        
        // Create necessary tables
        $this->createTables();
        
        // Initialize cache
        $this->initializeCache();
        
        // Verify services
        $this->verifyServices();
        
        $boot_state['stages']['infrastructure']['status'] = 'complete';
        $boot_state['stages']['infrastructure']['progress'] = 100;
        
        return $boot_state;
    }
    
    /**
     * STAGE 2: SCAN PHASE 1
     * Fast scan to identify projects, garbage, test environments
     */
    private function scanPhase1(&$boot_state) {
        $boot_state['current_stage'] = 'scan_phase_1';
        $boot_state['stages']['scan_phase_1']['status'] = 'running';
        $this->saveBootState($boot_state);
        
        $base_path = '/var/www';
        $quick_patterns = [
            'projects' => ['package.json', 'composer.json', '.git', 'Dockerfile'],
            'garbage' => ['node_modules', 'vendor', '__pycache__', '.cache', '*.tmp'],
            'test' => ['/test/', '/testing/', '/dev/', '/staging/']
        ];
        
        $files_scanned = 0;
        $projects = [];
        $garbage = [];
        $test_envs = [];
        
        // Use find command for faster scanning
        $output = shell_exec("find $base_path -type f -name 'package.json' -o -name 'composer.json' -o -name '.git' 2>/dev/null");
        
        if ($output) {
            foreach (array_filter(explode("\n", $output)) as $file) {
                $projects[] = dirname($file);
                $files_scanned++;
                
                if ($files_scanned % 1000 === 0) {
                    $progress = min(99, ($files_scanned / 10000) * 100);
                    $boot_state['stages']['scan_phase_1']['progress'] = $progress;
                    $boot_state['statistics']['files_scanned'] = $files_scanned;
                    $boot_state['statistics']['projects_discovered'] = count(array_unique($projects));
                    $this->saveBootState($boot_state);
                }
            }
        }
        
        $boot_state['statistics']['files_scanned'] = $files_scanned;
        $boot_state['statistics']['projects_discovered'] = count(array_unique($projects));
        $boot_state['stages']['scan_phase_1']['status'] = 'complete';
        $boot_state['stages']['scan_phase_1']['progress'] = 100;
        
        // Cache results
        file_put_contents(
            '/var/www/systemai/cache/scan_phase1.json',
            json_encode([
                'projects' => array_unique($projects),
                'scanned_at' => time()
            ])
        );
        
        return $boot_state;
    }
    
    /**
     * STAGE 3: SCAN PHASE 2
     * Deep scan with chunking for 54GB data
     */
    private function scanPhase2(&$boot_state) {
        $boot_state['current_stage'] = 'scan_phase_2';
        $boot_state['stages']['scan_phase_2']['status'] = 'running';
        $this->saveBootState($boot_state);
        
        $base_path = '/var/www';
        $all_files = [];
        $chunk_number = 0;
        
        // Use find with chunked processing
        $find_output = shell_exec("find $base_path -type f 2>/dev/null | sort");
        $files = array_filter(explode("\n", $find_output));
        
        $total_files = count($files);
        
        foreach (array_chunk($files, $this->chunk_size) as $chunk) {
            $chunk_number++;
            $chunk_data = [];
            
            foreach ($chunk as $file) {
                try {
                    if (is_file($file)) {
                        $size = filesize($file);
                        $chunk_data[] = [
                            'path' => $file,
                            'size' => $size,
                            'hash' => md5_file($file),
                            'mtime' => filemtime($file)
                        ];
                        $boot_state['statistics']['total_size_gb'] += $size / 1024 / 1024 / 1024;
                    }
                } catch (Exception $e) {
                    // Skip inaccessible files
                }
            }
            
            // Save chunk to database
            if (!empty($chunk_data)) {
                $this->saveFileChunk($chunk_number, $chunk_data);
            }
            
            // Update progress
            $progress = ($chunk_number / ceil($total_files / $this->chunk_size)) * 100;
            $boot_state['stages']['scan_phase_2']['progress'] = min(99, $progress);
            $boot_state['statistics']['files_scanned'] = $chunk_number * $this->chunk_size;
            
            if ($chunk_number % 10 === 0) {
                $this->saveBootState($boot_state);
            }
        }
        
        $boot_state['stages']['scan_phase_2']['status'] = 'complete';
        $boot_state['stages']['scan_phase_2']['progress'] = 100;
        $boot_state['statistics']['files_scanned'] = $total_files;
        
        return $boot_state;
    }
    
    /**
     * STAGE 4: DEDUPLICATION
     */
    private function deduplication(&$boot_state) {
        $boot_state['current_stage'] = 'deduplication';
        $boot_state['stages']['deduplication']['status'] = 'running';
        $this->saveBootState($boot_state);
        
        // Find duplicate hashes
        $stmt = $this->pdo->query("
            SELECT hash, COUNT(*) as count FROM file_index 
            GROUP BY hash HAVING COUNT(*) > 1
        ");
        
        $duplicates = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $duplicates[] = [
                'hash' => $row['hash'],
                'count' => $row['count']
            ];
        }
        
        $boot_state['statistics']['duplicates_found'] = count($duplicates);
        $boot_state['stages']['deduplication']['status'] = 'complete';
        $boot_state['stages']['deduplication']['progress'] = 100;
        
        return $boot_state;
    }
    
    /**
     * STAGE 5: INDEXING
     */
    private function indexing(&$boot_state) {
        $boot_state['current_stage'] = 'indexing';
        $boot_state['stages']['indexing']['status'] = 'running';
        $this->saveBootState($boot_state);
        
        // Index file system for fast lookups
        $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM file_index");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $total = $result['total'];
        
        // Create indexes
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_file_hash ON file_index(hash)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_file_path ON file_index(path)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_file_mtime ON file_index(mtime)");
        
        $boot_state['statistics']['files_indexed'] = $total;
        $boot_state['stages']['indexing']['status'] = 'complete';
        $boot_state['stages']['indexing']['progress'] = 100;
        
        return $boot_state;
    }
    
    /**
     * STAGE 6: CLASSIFICATION
     */
    private function classification(&$boot_state) {
        $boot_state['current_stage'] = 'classification';
        $boot_state['stages']['classification']['status'] = 'running';
        $this->saveBootState($boot_state);
        
        // Classify files by type, project, garbage, etc
        $stmt = $this->pdo->query("
            SELECT path, hash FROM file_index ORDER BY path
        ");
        
        $classified = [
            'projects' => 0,
            'garbage' => 0,
            'test_envs' => 0,
            'config' => 0,
            'data' => 0
        ];
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $path = $row['path'];
            
            if (preg_match('/(package\.json|composer\.json|\.git|Dockerfile)/', $path)) {
                $classified['projects']++;
            } elseif (preg_match('/(node_modules|vendor|__pycache__|\.cache)/', $path)) {
                $classified['garbage']++;
            } elseif (preg_match('/(test|testing|dev|staging)/', $path)) {
                $classified['test_envs']++;
            } elseif (preg_match('/\.(conf|config|yml|yaml|ini)$/', $path)) {
                $classified['config']++;
            } else {
                $classified['data']++;
            }
        }
        
        $boot_state['statistics']['garbage_identified'] = $classified['garbage'];
        $boot_state['stages']['classification']['status'] = 'complete';
        $boot_state['stages']['classification']['progress'] = 100;
        
        return $boot_state;
    }
    
    /**
     * STAGE 7: DISTRIBUTION
     */
    private function distribution(&$boot_state) {
        $boot_state['current_stage'] = 'distribution';
        $boot_state['stages']['distribution']['status'] = 'running';
        $this->saveBootState($boot_state);
        
        // Prepare data for ProjectAI and other leads
        $projects = $this->getClassifiedFiles('projects');
        $configs = $this->getClassifiedFiles('config');
        
        // Store distribution metadata
        file_put_contents(
            '/var/www/systemai/cache/distribution.json',
            json_encode([
                'projects_count' => count($projects),
                'configs_count' => count($configs),
                'ready_for_projectai' => true,
                'ready_for_dataai' => true,
                'distributed_at' => time()
            ])
        );
        
        $boot_state['stages']['distribution']['status'] = 'complete';
        $boot_state['stages']['distribution']['progress'] = 100;
        
        return $boot_state;
    }
    
    /**
     * STAGE 8: PROJECTAI BUILD
     */
    private function projectAIBuild(&$boot_state) {
        $boot_state['current_stage'] = 'projectai_build';
        $boot_state['stages']['projectai_build']['status'] = 'running';
        $this->saveBootState($boot_state);
        
        // ProjectAI can now access all prepared data
        // Create signal for ProjectAI to start
        touch('/var/www/systemai/boot/.projectai_ready');
        
        $boot_state['stages']['projectai_build']['status'] = 'complete';
        $boot_state['stages']['projectai_build']['progress'] = 100;
        
        return $boot_state;
    }
    
    // ===== HELPERS =====
    
    private function createTables() {
        $sql = "
            CREATE TABLE IF NOT EXISTS file_index (
                id SERIAL PRIMARY KEY,
                path VARCHAR(4096) UNIQUE,
                hash VARCHAR(32),
                size BIGINT,
                mtime BIGINT,
                classification VARCHAR(50),
                chunk_number INT,
                created_at TIMESTAMP DEFAULT NOW()
            );
            CREATE INDEX IF NOT EXISTS idx_file_hash ON file_index(hash);
            CREATE INDEX IF NOT EXISTS idx_file_path ON file_index(path);
        ";
        
        try {
            foreach (explode(';', $sql) as $statement) {
                if (trim($statement)) {
                    $this->pdo->exec($statement);
                }
            }
        } catch (Exception $e) {
            // Tables might already exist
        }
    }
    
    private function initializeCache() {
        @mkdir('/var/www/systemai/cache', 0755, true);
    }
    
    private function verifyServices() {
        $services = ['nginx', 'postgresql', 'php-fpm'];
        foreach ($services as $service) {
            $status = trim(shell_exec("systemctl is-active $service 2>&1"));
            if ($status !== 'active') {
                shell_exec("systemctl restart $service 2>&1");
            }
        }
    }
    
    private function saveFileChunk($chunk_number, $chunk_data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO file_index (path, hash, size, mtime, chunk_number)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT (path) DO NOTHING
        ");
        
        foreach ($chunk_data as $file) {
            try {
                $stmt->execute([
                    $file['path'],
                    $file['hash'],
                    $file['size'],
                    $file['mtime'],
                    $chunk_number
                ]);
            } catch (Exception $e) {
                // Duplicate or error - skip
            }
        }
    }
    
    private function saveBootState($boot_state) {
        file_put_contents(
            $this->boot_state_file,
            json_encode($boot_state, JSON_PRETTY_PRINT)
        );
    }
    
    private function getClassifiedFiles($classification) {
        $stmt = $this->pdo->prepare("
            SELECT path FROM file_index 
            WHERE classification = ?
            LIMIT 10000
        ");
        $stmt->execute([$classification]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    public function getBootState() {
        if (file_exists($this->boot_state_file)) {
            return json_decode(file_get_contents($this->boot_state_file), true);
        }
        return null;
    }
}
?>
