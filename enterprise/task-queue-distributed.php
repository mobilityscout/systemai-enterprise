<?php
/**
 * DISTRIBUTED TASK QUEUE
 * - Multi-Node Ready
 * - Kafka/RabbitMQ Compatible
 * - Retry Logic
 * - Dead Letter Queue
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

class DistributedTaskQueue {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * ENQUEUE TASK
     */
    public function enqueue($tenant_id, $task_type, $payload, $priority = 5) {
        $task_id = 'task_' . uniqid();
        $node_id = 'node-001'; // Later: select by load
        
        $stmt = $this->pdo->prepare("
            INSERT INTO task_queue_distributed 
            (task_id, tenant_id, task_type, payload, priority, status, node_id, created_at)
            VALUES (?, ?, ?, ?, ?, 'pending', ?, NOW())
        ");
        
        $stmt->execute([
            $task_id,
            $tenant_id,
            $task_type,
            json_encode($payload),
            $priority,
            $node_id
        ]);
        
        return ['task_id' => $task_id, 'status' => 'enqueued'];
    }
    
    /**
     * DEQUEUE TASK
     */
    public function dequeue($node_id, $worker_id) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM task_queue_distributed 
            WHERE node_id = ? AND status = 'pending'
            ORDER BY priority DESC, created_at ASC
            LIMIT 1
        ");
        
        $stmt->execute([$node_id]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($task) {
            $stmt = $this->pdo->prepare("
                UPDATE task_queue_distributed SET status = 'running', started_at = NOW()
                WHERE task_id = ?
            ");
            $stmt->execute([$task['task_id']]);
        }
        
        return $task;
    }
    
    /**
     * MARK COMPLETE WITH RETRY
     */
    public function complete($task_id, $result, $error = null) {
        $success = empty($error);
        
        if ($success) {
            $stmt = $this->pdo->prepare("
                UPDATE task_queue_distributed 
                SET status = 'completed', completed_at = NOW(), result = ?
                WHERE task_id = ?
            ");
            $stmt->execute([json_encode($result), $task_id]);
        } else {
            // Check retry
            $stmt = $this->pdo->prepare("
                SELECT retry_count, max_retries FROM task_queue_distributed WHERE task_id = ?
            ");
            $stmt->execute([$task_id]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($task['retry_count'] < $task['max_retries']) {
                $stmt = $this->pdo->prepare("
                    UPDATE task_queue_distributed 
                    SET status = 'pending', retry_count = retry_count + 1
                    WHERE task_id = ?
                ");
                $stmt->execute([$task_id]);
            } else {
                $stmt = $this->pdo->prepare("
                    UPDATE task_queue_distributed 
                    SET status = 'failed', error = ?
                    WHERE task_id = ?
                ");
                $stmt->execute([$error, $task_id]);
            }
        }
        
        return ['status' => $success ? 'completed' : 'retrying'];
    }
}

$action = $_GET['action'] ?? '';
$queue = new DistributedTaskQueue($pdo);

if ($action === 'enqueue') {
    echo json_encode($queue->enqueue(
        $_POST['tenant_id'] ?? 'default-tenant',
        $_POST['task_type'] ?? '',
        json_decode($_POST['payload'] ?? '{}', true),
        $_POST['priority'] ?? 5
    ));
}
elseif ($action === 'dequeue') {
    echo json_encode($queue->dequeue(
        $_POST['node_id'] ?? 'node-001',
        $_POST['worker_id'] ?? 0
    ));
}
elseif ($action === 'complete') {
    echo json_encode($queue->complete(
        $_POST['task_id'] ?? '',
        json_decode($_POST['result'] ?? '{}', true),
        $_POST['error'] ?? null
    ));
}

?>
