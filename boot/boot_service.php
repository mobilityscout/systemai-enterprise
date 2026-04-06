<?php
/**
 * BOOT SERVICE API
 * Professional interface for boot operations
 */

require_once __DIR__ . '/SystemBootDaemon.php';

try {
    $pdo = new PDO(
        "pgsql:host=127.0.0.1;dbname=systemai",
        "systemai",
        "systemai",
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    die(json_encode(['error' => $e->getMessage()]));
}

$api = $_GET['api'] ?? '';
$action = $_POST['action'] ?? '';

if ($api === 'boot') {
    header('Content-Type: application/json');
    
    if ($action === 'start') {
        // Start boot in background
        $daemon = new SystemBootDaemon($pdo);
        
        // Fork to background
        $output = shell_exec("nohup php -r '
            require_once \"/var/www/systemai/boot/SystemBootDaemon.php\";
            \$pdo = new PDO(\"pgsql:host=127.0.0.1;dbname=systemai\", \"systemai\", \"systemai\");
            \$daemon = new SystemBootDaemon(\$pdo);
            \$daemon->professionalBoot();
        ' > /var/www/systemai/logs/boot.log 2>&1 &");
        
        echo json_encode([
            'status' => 'boot_started',
            'message' => 'Boot service running in background',
            'log_file' => '/var/www/systemai/logs/boot.log'
        ]);
    }
    
    elseif ($action === 'status') {
        $daemon = new SystemBootDaemon($pdo);
        $state = $daemon->getBootState();
        
        echo json_encode($state ?? [
            'status' => 'not_started',
            'message' => 'Boot service not initialized'
        ]);
    }
}
?>
