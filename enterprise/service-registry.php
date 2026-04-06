<?php
/**
 * SERVICE REGISTRY
 * - Service Discovery (für Kubernetes/Consul)
 * - Health Checks
 * - Load Balancing
 * - Service Mesh Ready
 */

header('Content-Type: application/json');

try {
    $pdo = new PDO(
        "pgsql:host=127.0.0.1;dbname=systemai",
        "systemai",
        "systemai"
    );
} catch (Exception $e) {
    die(json_encode(['error' => 'DB connection failed']));
}

class ServiceRegistry {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * REGISTER SERVICE
     */
    public function registerService($service_name, $endpoint, $port, $node_id) {
        $stmt = $this->pdo->prepare("
            INSERT INTO service_registry (service_name, node_id, endpoint, port, status, last_health_check)
            VALUES (?, ?, ?, ?, 'healthy', NOW())
            ON CONFLICT (service_name, node_id) DO UPDATE SET last_health_check = NOW()
        ");
        
        $stmt->execute([$service_name, $node_id, $endpoint, $port]);
        
        return ['status' => 'registered', 'service' => $service_name];
    }
    
    /**
     * GET SERVICE INSTANCES
     */
    public function getServiceInstances($service_name) {
        $stmt = $this->pdo->prepare("
            SELECT node_id, endpoint, port, status 
            FROM service_registry 
            WHERE service_name = ? AND status = 'healthy'
            ORDER BY RANDOM()
        ");
        
        $stmt->execute([$service_name]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * HEALTH CHECK
     */
    public function healthCheck($service_name, $node_id) {
        $stmt = $this->pdo->prepare("
            UPDATE service_registry 
            SET last_health_check = NOW(), status = 'healthy'
            WHERE service_name = ? AND node_id = ?
        ");
        
        $stmt->execute([$service_name, $node_id]);
        
        return ['status' => 'healthy'];
    }
}

$action = $_GET['action'] ?? '';
$registry = new ServiceRegistry($pdo);

if ($action === 'register') {
    echo json_encode($registry->registerService(
        $_POST['service'] ?? '',
        $_POST['endpoint'] ?? '',
        $_POST['port'] ?? 0,
        $_POST['node_id'] ?? 'node-001'
    ));
}
elseif ($action === 'get-instances') {
    echo json_encode($registry->getServiceInstances($_GET['service'] ?? ''));
}
elseif ($action === 'health-check') {
    echo json_encode($registry->healthCheck(
        $_POST['service'] ?? '',
        $_POST['node_id'] ?? 'node-001'
    ));
}

?>
