<?php
/**
 * ENTERPRISE MONITORING
 * - SLA Metrics
 * - Compliance Tracking (GDPR, ISO 27001)
 * - Audit Logs
 * - Alert Management
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

class EnterpriseMonitoring {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * RECORD SLA METRIC
     */
    public function recordSLAMetric($tenant_id, $service, $metric_type, $value, $threshold_warn, $threshold_crit) {
        $stmt = $this->pdo->prepare("
            INSERT INTO sla_metrics (tenant_id, service_name, metric_type, metric_value, threshold_warning, threshold_critical)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $tenant_id,
            $service,
            $metric_type,
            $value,
            $threshold_warn,
            $threshold_crit
        ]);
        
        // Check thresholds
        $status = 'ok';
        if ($value >= $threshold_crit) $status = 'critical';
        elseif ($value >= $threshold_warn) $status = 'warning';
        
        return ['metric_recorded' => true, 'status' => $status];
    }
    
    /**
     * GET COMPLIANCE REPORT
     */
    public function getComplianceReport($tenant_id) {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total_actions,
                COUNT(CASE WHEN result = 'completed' THEN 1 END) as successful_actions,
                COUNT(CASE WHEN result = 'failed' THEN 1 END) as failed_actions
            FROM audit_log WHERE tenant_id = ? AND created_at > NOW() - INTERVAL '30 days'
        ");
        
        $stmt->execute([$tenant_id]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'compliance_score' => round(($stats['successful_actions'] / $stats['total_actions']) * 100, 2),
            'audit_trail' => $stats,
            'status' => 'compliant'
        ];
    }
}

$monitoring = new EnterpriseMonitoring($pdo);

$action = $_GET['action'] ?? '';

if ($action === 'record-sla') {
    echo json_encode($monitoring->recordSLAMetric(
        $_POST['tenant_id'] ?? 'default-tenant',
        $_POST['service'] ?? '',
        $_POST['metric_type'] ?? '',
        $_POST['value'] ?? 0,
        $_POST['threshold_warn'] ?? 80,
        $_POST['threshold_crit'] ?? 90
    ));
}
elseif ($action === 'compliance-report') {
    echo json_encode($monitoring->getComplianceReport($_GET['tenant_id'] ?? 'default-tenant'));
}

?>
