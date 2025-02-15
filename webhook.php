<?php
// Allow requests from any domain with '*'
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');    // Cache preflight for 24 hours

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('HTTP/1.1 200 OK');
    exit;
}

// Include database configuration
require_once 'onboarding/config.php';

// Connect to database
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create tables if they don't exist
$sql = "CREATE TABLE IF NOT EXISTS webhooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    webhook_url VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$conn->query($sql);

$sql = "CREATE TABLE IF NOT EXISTS webhook_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    webhook_id INT,
    payload TEXT,
    received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (webhook_id) REFERENCES webhooks(id)
)";
$conn->query($sql);

// Add new table for mapping configuration
$sql = "CREATE TABLE IF NOT EXISTS webhook_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    webhook_id INT,
    target_table VARCHAR(255) NOT NULL,
    source_field VARCHAR(255) NOT NULL,
    target_field VARCHAR(255) NOT NULL,
    transform_rule TEXT,
    is_required BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (webhook_id) REFERENCES webhooks(id)
)";
$conn->query($sql);

// Handle webhook creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_webhook'])) {
    $webhook_url = uniqid('webhook_');
    $sql = "INSERT INTO webhooks (webhook_url) VALUES (?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $webhook_url);
    
    if ($stmt->execute()) {
        // Redirect back to the page with a success message
        header('Location: ' . $_SERVER['PHP_SELF'] . '?webhook_created=1&url=' . $webhook_url);
    } else {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?webhook_error=1');
    }
    exit;
}

// Handle incoming webhook data
$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('/webhooks\.php\/webhook\/(.+)/', $request_path, $matches) || 
    preg_match('/\?webhook=(.+)/', $_SERVER['REQUEST_URI'], $matches)) {
    $webhook_url = $matches[1];
    
    // Get webhook ID
    $sql = "SELECT id FROM webhooks WHERE webhook_url = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $webhook_url);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $webhook_id = $row['id'];
        
        // Get all request data
        $payload = file_get_contents('php://input');
        $headers = getallheaders();
        $method = $_SERVER['REQUEST_METHOD'];
        
        // Handle different content types
        $parsedPayload = [];
        $contentType = isset($headers['Content-Type']) ? $headers['Content-Type'] : '';
        
        if (strpos($contentType, 'application/json') !== false) {
            $parsedPayload = json_decode($payload, true) ?? [];
        } elseif (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
            parse_str($payload, $parsedPayload);
        } elseif (strpos($contentType, 'multipart/form-data') !== false) {
            $parsedPayload = $_POST;
        }
        
        // Combine relevant request information
        $webhook_data = [
            'method' => $method,
            'headers' => $headers,
            'payload' => $parsedPayload,
            'query' => $_GET,
            'raw_payload' => $payload
        ];
        
        // Store raw webhook data
        $sql = "INSERT INTO webhook_data (webhook_id, payload) VALUES (?, ?)";
        $stmt = $conn->prepare($sql);
        $json_payload = json_encode($webhook_data, JSON_PRETTY_PRINT);
        $stmt->bind_param("is", $webhook_id, $json_payload);
        $stmt->execute();
        
        // Process mapped data
        $mapping_results = processWebhookWithMapping($conn, $webhook_id, $webhook_data);
        
        // Send response
        header('Content-Type: application/json');
        http_response_code(200);
        echo json_encode([
            'status' => 'success',
            'message' => 'Webhook received and processed',
            'mapping_results' => $mapping_results
        ]);
        exit;
    }
}

// Add this near the top of the file, after database connection and before any HTML output
if (isset($_GET['get_db_structure'])) {
    header('Content-Type: application/json');
    
    try {
        $tables = [];
        
        // Get all tables
        $tablesResult = $conn->query("SHOW TABLES");
        while ($table = $tablesResult->fetch_array(MYSQLI_NUM)) {
            $tableName = $table[0];
            $tableInfo = [
                'name' => $tableName,
                'columns' => [],
                'foreign_keys' => []
            ];
            
            // Get columns
            $columnsResult = $conn->query("SHOW COLUMNS FROM `$tableName`");
            while ($column = $columnsResult->fetch_assoc()) {
                $tableInfo['columns'][] = [
                    'name' => $column['Field'],
                    'type' => $column['Type'],
                    'required' => $column['Null'] === 'NO',
                    'key' => $column['Key'],
                    'default' => $column['Default']
                ];
            }
            
            // Get foreign keys
            $foreignKeysResult = $conn->query("
                SELECT 
                    COLUMN_NAME,
                    REFERENCED_TABLE_NAME,
                    REFERENCED_COLUMN_NAME 
                FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = '$tableName' 
                    AND REFERENCED_TABLE_NAME IS NOT NULL
            ");
            
            while ($fk = $foreignKeysResult->fetch_assoc()) {
                $tableInfo['foreign_keys'][] = $fk;
            }
            
            $tables[] = $tableInfo;
        }
        
        echo json_encode($tables);
    } catch (Exception $e) {
        echo json_encode(['error' => true, 'message' => $e->getMessage()]);
    }
    exit;
}

// Add this near the top of your PHP file, after database connection
if (isset($_GET['check_webhook_data'])) {
    header('Content-Type: application/json');
    $webhook_url = $_GET['webhook_url'];
    $last_check = $_GET['last_check'];
    
    // Get webhook ID
    $sql = "SELECT id FROM webhooks WHERE webhook_url = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $webhook_url);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $webhook_id = $row['id'];
        
        // Check for new data since last check
        $sql = "SELECT payload, received_at FROM webhook_data 
                WHERE webhook_id = ? AND received_at > ? 
                ORDER BY received_at DESC LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $webhook_id, $last_check);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            echo json_encode([
                'has_new_data' => true,
                'data' => json_decode($row['payload'], true),
                'received_at' => $row['received_at']
            ]);
            exit;
        }
    }
    
    echo json_encode(['has_new_data' => false]);
    exit;
}

// Helper function to extract nested values from array using dot notation
function extractValueFromPath($data, $path) {
    $keys = explode('.', $path);
    $value = $data;
    
    // Debug logging
    error_log("Extracting path: " . $path);
    error_log("Initial data: " . print_r($data, true));
    
    foreach ($keys as $key) {
        // Check if the key exists in the current level
        if (is_array($value) && isset($value[$key])) {
            $value = $value[$key];
        } else {
            // Check in payload structure
            if (isset($data['payload']) && is_array($data['payload'])) {
                if ($keys[0] === 'raw_payload' && isset($data['raw_payload'])) {
                    // Try to decode raw_payload if it's a JSON string
                    $rawPayload = is_string($data['raw_payload']) ? 
                        json_decode($data['raw_payload'], true) : 
                        $data['raw_payload'];
                    
                    // Remove 'raw_payload' from keys and look for remaining path
                    array_shift($keys);
                    $value = $rawPayload;
                    foreach ($keys as $remainingKey) {
                        if (isset($value[$remainingKey])) {
                            $value = $value[$remainingKey];
                        } else {
                            error_log("Key not found in raw_payload: " . $remainingKey);
                            return null;
                        }
                    }
                    return $value;
                }
                
                if ($keys[0] === 'query' && isset($data['query'])) {
                    // Handle query parameters
                    array_shift($keys);
                    $value = $data['query'];
                    foreach ($keys as $remainingKey) {
                        if (isset($value[$remainingKey])) {
                            $value = $value[$remainingKey];
                        } else {
                            error_log("Key not found in query: " . $remainingKey);
                            return null;
                        }
                    }
                    return $value;
                }
            }
            
            error_log("Key not found: " . $key);
            return null;
        }
    }
    
    error_log("Extracted value: " . print_r($value, true));
    return $value;
}

// Helper function to apply transformation rules
function applyTransform($value, $rule, $webhook_data = null) {
    if (empty($rule)) return $value;
    
    // Parse the rule if it's a REPLACE rule with parameters
    if (strpos($rule, 'REPLACE:') === 0) {
        $replacement = substr($rule, 8); // Remove 'REPLACE:' prefix
        return $replacement;
    }
    
    // Add IF/THEN handling
    if (strpos($rule, 'IF/THEN:') === 0) {
        $ruleConfig = json_decode(substr($rule, 8), true);
        if (!$ruleConfig) return $value;
        
        foreach ($ruleConfig['conditions'] as $condition) {
            $checkValue = extractValueFromPath($webhook_data, $condition['field']);
            
            if ($condition['operator'] === 'equals' && $checkValue == $condition['value']) {
                return $condition['result'];
            }
            if ($condition['operator'] === 'contains' && 
                ((is_array($checkValue) && in_array($condition['value'], $checkValue)) ||
                 (is_string($checkValue) && strpos($checkValue, $condition['value']) !== false))) {
                return $condition['result'];
            }
        }
        return $ruleConfig['default'] ?? $value;
    }
    
    switch ($rule) {
        case 'UPPERCASE':
            return strtoupper($value);
        case 'LOWERCASE':
            return strtolower($value);
        case 'TRIM':
            return trim($value);
        case 'TIMESTAMP':
            // Use the webhook received timestamp if available
            if ($webhook_data && isset($webhook_data['received_at'])) {
                return $webhook_data['received_at'];
            }
            return date('Y-m-d H:i:s');
        default:
            return $value;
    }
}

// Function to process webhook data according to mappings
function processWebhookWithMapping($conn, $webhook_id, $data) {
    // Add received timestamp to the data
    $data['received_at'] = date('Y-m-d H:i:s');
    
    // Get mappings for this webhook
    $sql = "SELECT * FROM webhook_mappings WHERE webhook_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $webhook_id);
    $stmt->execute();
    $mappings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    // Group mappings by target table
    $tableMap = [];
    foreach ($mappings as $mapping) {
        $tableMap[$mapping['target_table']][] = $mapping;
    }
    
    $results = [];
    
    // Process each target table
    foreach ($tableMap as $table => $fields) {
        $insertData = [];
        $missingRequired = false;
        $uniqueFields = [];  // Store fields that should be checked for duplicates
        
        // First pass: collect all field data and identify unique fields
        foreach ($fields as $field) {
            $value = extractValueFromPath($data, $field['source_field']);
            
            if ($field['transform_rule']) {
                $value = applyTransform($value, $field['transform_rule'], $data);
            }
            
            if ($field['is_required'] && $value === null) {
                $missingRequired = true;
                break;
            }
            
            $insertData[$field['target_field']] = $value;
            
            // Consider email, phone, or specific ID fields as unique identifiers
            if (strpos(strtolower($field['target_field']), 'email') !== false ||
                strpos(strtolower($field['target_field']), 'phone') !== false ||
                strpos(strtolower($field['target_field']), 'id') !== false) {
                $uniqueFields[$field['target_field']] = $value;
            }
        }
        
        if ($missingRequired) {
            $results[$table] = ['status' => 'error', 'message' => 'Missing required fields'];
            continue;
        }
        
        // Check for existing record if we have unique fields
        $existingId = null;
        if (!empty($uniqueFields)) {
            $whereConditions = [];
            $whereValues = [];
            $whereTypes = '';
            
            foreach ($uniqueFields as $field => $value) {
                if ($value !== null) {
                    $whereConditions[] = "$field = ?";
                    $whereValues[] = $value;
                    $whereTypes .= 's';
                }
            }
            
            if (!empty($whereConditions)) {
                $checkSql = "SELECT id FROM $table WHERE " . implode(' OR ', $whereConditions);
                $checkStmt = $conn->prepare($checkSql);
                if ($checkStmt) {
                    $checkStmt->bind_param($whereTypes, ...$whereValues);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    if ($row = $checkResult->fetch_assoc()) {
                        $existingId = $row['id'];
                    }
                }
            }
        }
        
        if ($existingId) {
            // Update existing record
            $updatePairs = [];
            foreach ($insertData as $field => $value) {
                $updatePairs[] = "$field = ?";
            }
            $sql = "UPDATE $table SET " . implode(', ', $updatePairs) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            
            if ($stmt) {
                $types = str_repeat('s', count($insertData)) . 'i';
                $values = array_values($insertData);
                $values[] = $existingId;
                $stmt->bind_param($types, ...$values);
                
                if ($stmt->execute()) {
                    $results[$table] = [
                        'status' => 'success',
                        'message' => 'Record updated successfully',
                        'id' => $existingId,
                        'action' => 'updated'
                    ];
                } else {
                    $results[$table] = [
                        'status' => 'error',
                        'message' => 'Update failed: ' . $stmt->error
                    ];
                }
            }
        } else {
            // Insert new record
            $columns = implode(', ', array_keys($insertData));
            $values = implode(', ', array_fill(0, count($insertData), '?'));
            $sql = "INSERT INTO $table ($columns) VALUES ($values)";
            
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $types = str_repeat('s', count($insertData));
                $stmt->bind_param($types, ...array_values($insertData));
                
                if ($stmt->execute()) {
                    $results[$table] = [
                        'status' => 'success',
                        'message' => 'Data inserted successfully',
                        'insert_id' => $stmt->insert_id,
                        'action' => 'inserted'
                    ];
                } else {
                    $results[$table] = [
                        'status' => 'error',
                        'message' => 'Insert failed: ' . $stmt->error
                    ];
                }
            }
        }
    }
    
    return $results;
}

// Add mapping management interface
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_mapping'])) {
    $webhook_id = $_POST['webhook_id'];
    $target_table = $_POST['target_table'];
    $source_field = $_POST['source_field'];
    $target_field = $_POST['target_field'];
    $transform_rule = $_POST['transform_rule'];
    $is_required = isset($_POST['is_required']) ? 1 : 0;
    
    // If it's a REPLACE transformation, construct the rule with the replacement value
    if (isset($_POST['replacement_value'])) {
        $transform_rule = 'REPLACE:' . $_POST['replacement_value'];
    }
    
    $sql = "INSERT INTO webhook_mappings (webhook_id, target_table, source_field, target_field, transform_rule, is_required) 
            VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("issssi", $webhook_id, $target_table, $source_field, $target_field, $transform_rule, $is_required);
    
    if ($stmt->execute()) {
        // Redirect after successful submission
        header('Location: ' . $_SERVER['PHP_SELF'] . '?mapping_added=1');
        exit;
    }
}

// Similar redirects for other form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_webhook'])) {
    $webhook_id = $_POST['webhook_id'];
    $conn->query("DELETE FROM webhook_data WHERE webhook_id = $webhook_id");
    $conn->query("DELETE FROM webhook_mappings WHERE webhook_id = $webhook_id");
    $conn->query("DELETE FROM webhooks WHERE id = $webhook_id");
    header('Location: ' . $_SERVER['PHP_SELF'] . '?webhook_deleted=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_mapping'])) {
    $mapping_id = $_POST['mapping_id'];
    $conn->query("DELETE FROM webhook_mappings WHERE id = $mapping_id");
    header('Location: ' . $_SERVER['PHP_SELF'] . '?mapping_deleted=1');
    exit;
}

// Add this new endpoint near other POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_mapping'])) {
    $mapping_id = $_POST['mapping_id'];
    $source_field = $_POST['source_field'];
    $target_field = $_POST['target_field'];
    $transform_rule = $_POST['transform_rule'];
    $is_required = isset($_POST['is_required']) ? 1 : 0;
    
    if (isset($_POST['replacement_value'])) {
        $transform_rule = 'REPLACE:' . $_POST['replacement_value'];
    }
    
    $sql = "UPDATE webhook_mappings SET source_field=?, target_field=?, transform_rule=?, is_required=? WHERE id=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssii", $source_field, $target_field, $transform_rule, $is_required, $mapping_id);
    $stmt->execute();
    
    header('Location: ' . $_SERVER['PHP_SELF'] . '?mapping_updated=1');
    exit;
}

// Add this near the other form handling code
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_if_then_rule'])) {
    $mapping_id = $_POST['mapping_id'];
    $check_field = $_POST['check_field'];
    $check_operator = $_POST['check_operator'];
    $check_value = $_POST['check_value'];
    $result_value = $_POST['result_value'];
    $default_value = $_POST['default_value'];
    
    $rule = [
        'conditions' => [
            [
                'field' => $check_field,
                'operator' => $check_operator,
                'value' => $check_value,
                'result' => $result_value
            ]
        ],
        'default' => $default_value
    ];
    
    $transform_rule = 'IF/THEN:' . json_encode($rule);
    
    $sql = "UPDATE webhook_mappings SET transform_rule = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $transform_rule, $mapping_id);
    $stmt->execute();
    
    header('Location: ' . $_SERVER['PHP_SELF'] . '?rule_added=1');
    exit;
}

// Add this function near the top of the file with other functions, before any HTML output
function formatJsonTree($data, $path = '') {
    if (is_string($data)) {
        // Try to parse JSON string if it's a string
        $parsed = json_decode($data, true);
        if ($parsed !== null) {
            $data = $parsed;
        }
    }
    
    if (!is_array($data)) {
        return '<div class="json-empty">Invalid data format</div>';
    }

    $html = '';  // Changed from 'let html' to '$html'
    foreach ($data as $key => $value) {
        $currentPath = $path ? $path . '.' . $key : $key;
        $html .= '<div class="json-line">';
        
        if (is_array($value) || is_object($value)) {
            $html .= '<div class="json-object">';
            $html .= '<div class="json-header" onclick="toggleNested(this)">';
            $html .= '▼ <span class="json-key">' . htmlspecialchars($key) . ':</span>';
            // Update the onclick handler here to use the new copyPath function
            $html .= '<button class="copy-path" onclick="event.stopPropagation(); copyPath(\'' . $currentPath . '\')">Map Field</button>';
            $html .= '</div>';
            $html .= '<div class="json-nested">';
            $html .= formatJsonTree($value, $currentPath);
            $html .= '</div></div>';
        } else {
            $displayValue = $value === null ? 'null' : htmlspecialchars($value);
            $html .= '<span class="json-key">' . htmlspecialchars($key) . ':</span>';
            $html .= '<span class="json-value">' . $displayValue . '</span>';
            // Update the button text and onclick handler here as well
            $html .= '<button class="copy-path" onclick="copyPath(\'' . $currentPath . '\')">Map Field</button>';
        }
        
        $html .= '</div>';
    }
    return $html;
}

// Add these functions near the top of the file with other functions
function getWebhookStats($conn, $webhook_id = null) {
    $stats = [];
    
    if ($webhook_id) {
        // Individual webhook stats
        $sql = "SELECT 
            COUNT(*) as total_requests,
            MIN(received_at) as first_request,
            MAX(received_at) as last_request,
            COUNT(DISTINCT DATE(received_at)) as unique_days
        FROM webhook_data 
        WHERE webhook_id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $webhook_id);
    } else {
        // Overall stats
        $sql = "SELECT 
            COUNT(*) as total_requests,
            MIN(received_at) as first_request,
            MAX(received_at) as last_request,
            COUNT(DISTINCT DATE(received_at)) as unique_days,
            COUNT(DISTINCT webhook_id) as total_webhooks
        FROM webhook_data";
        
        $stmt = $conn->prepare($sql);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
    
    // Add recent activity
    $sql = $webhook_id 
        ? "SELECT received_at, payload FROM webhook_data WHERE webhook_id = ? ORDER BY received_at DESC LIMIT 5"
        : "SELECT w.webhook_url, wd.received_at, wd.payload 
           FROM webhook_data wd 
           JOIN webhooks w ON wd.webhook_id = w.id 
           ORDER BY received_at DESC LIMIT 5";
    
    $stmt = $conn->prepare($sql);
    if ($webhook_id) {
        $stmt->bind_param("i", $webhook_id);
    }
    $stmt->execute();
    $stats['recent_activity'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    return $stats;
}

// First, add this helper function near the top with other functions
function getTimeAgo($timestamp) {
    $time = strtotime($timestamp);
    $current = time();
    $diff = $current - $time;
    
    // If the timestamp is in the future, it's likely a timezone issue
    // Let's handle it by using server's timezone
    if ($diff < 0) {
        date_default_timezone_set('America/New_York'); // Or your server's timezone
        $current = time();
        $diff = $current - $time;
    }
    
    $intervals = array(
        31536000 => 'year',
        2592000 => 'month',
        604800 => 'week',
        86400 => 'day',
        3600 => 'hour',
        60 => 'minute',
        1 => 'second'
    );
    
    // If less than 30 seconds, show "just now"
    if ($diff < 30) {
        return 'just now';
    }
    
    foreach ($intervals as $secs => $str) {
        $d = $diff / $secs;
        if ($d >= 1) {
            $r = round($d);
            return $r . ' ' . $str . ($r > 1 ? 's' : '') . ' ago';
        }
    }
    
    return 'just now';
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Webhook Manager</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f0f0f0;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .webhook-container {
            background: white;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .webhook-url {
            word-break: break-all;
            padding: 10px;
            background: #f5f5f5;
            border-radius: 3px;
        }
        
        .webhook-data {
            margin-top: 10px;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .data-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        
        button {
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }
        
        button:hover {
            background: #0056b3;
        }
        
        pre {
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .mapping-section {
            margin-top: 30px;
            background: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .mapping-form {
            max-width: 600px;
            margin: 20px 0;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
        }
        
        .form-group input[type="text"],
        .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 3px;
        }
        
        .mapping-item {
            padding: 10px;
            border: 1px solid #eee;
            margin-bottom: 10px;
            border-radius: 3px;
        }
        
        .mappings-list {
            margin-top: 20px;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border-radius: 8px;
            width: 80%;
            max-width: 1000px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .close {
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .webhook-url-container {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        
        .waiting-spinner {
            text-align: center;
            margin: 30px 0;
        }
        
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .mapping-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 20px;
        }
        
        .received-data, .database-structure {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
        }
        
        .save-mapping-btn {
            margin-top: 20px;
            padding: 10px 20px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        
        .save-mapping-btn:hover {
            background: #218838;
        }
        
        .mapping-field {
            margin: 10px 0;
            padding: 10px;
            background: white;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .json-tree {
            font-family: monospace;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            max-height: 600px;
            overflow-y: auto;
        }
        
        .json-line {
            padding: 3px 0;
            margin-left: 20px;
            border-radius: 3px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .json-header {
            cursor: pointer;
            padding: 2px 5px;
            border-radius: 3px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .json-header:hover {
            background: #e9ecef;
        }
        
        .json-nested {
            margin-left: 20px;
            border-left: 1px dashed #ccc;
            padding-left: 10px;
        }
        
        .json-key {
            color: #2c3e50;
            font-weight: bold;
        }
        
        .json-value {
            color: #27ae60;
        }
        
        .copy-path {
            opacity: 0;
            padding: 2px 8px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.8em;
            transition: opacity 0.2s;
        }
        
        .json-line:hover .copy-path,
        .json-header:hover .copy-path {
            opacity: 1;
        }
        
        .json-object {
            width: 100%;
        }
        
        /* Highlight important fields */
        .json-line:has(.json-key:contains("email")),
        .json-line:has(.json-key:contains("name")),
        .json-line:has(.json-key:contains("phone")) {
            background: #f8f9ec;
        }
        
        .database-explorer {
            background: white;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .explorer-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-top: 15px;
        }
        
        .table-details {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
        }
        
        .column-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
            display: grid;
            grid-template-columns: 2fr 2fr 1fr;
            gap: 10px;
            align-items: center;
        }
        
        .column-item:hover {
            background: #f0f0f0;
        }
        
        .column-name {
            font-weight: bold;
        }
        
        .column-type {
            color: #666;
        }
        
        .column-required {
            color: #dc3545;
            font-size: 0.9em;
        }
        
        .foreign-key {
            color: #007bff;
            font-size: 0.9em;
            grid-column: 1 / -1;
            padding-left: 20px;
        }
        
        .copy-path {
            padding: 2px 8px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.8em;
        }
        
        .copy-path:hover {
            background: #218838;
        }
        
        .replacement-input {
            width: 100%;
            padding: 8px;
            margin-top: 5px;
            border: 1px solid #ddd;
            border-radius: 3px;
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid transparent;
            border-radius: 4px;
        }
        
        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
        
        /* Make alerts dismissible after 5 seconds */
        .alert {
            animation: fadeOut 0.5s ease 5s forwards;
        }
        
        @keyframes fadeOut {
            from { opacity: 1; }
            to { opacity: 0; visibility: hidden; }
        }
        
        .if-then-form {
            background: #f5f5f5;
            padding: 10px;
            margin-top: 10px;
            border-radius: 4px;
        }
        .if-then-form .form-group {
            margin-bottom: 10px;
        }
        .if-then-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .if-then-form input,
        .if-then-form select {
            width: 100%;
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 3px;
        }
        
        .stats-container {
            margin-bottom: 30px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .stat-box {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #007bff;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        
        .recent-activity {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .activity-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-time {
            color: #666;
            font-size: 14px;
        }
        
        .time-ago {
            color: #999;
            font-size: 12px;
            margin-left: 5px;
        }
        
        .activity-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .activity-webhook {
            color: #007bff;
            text-decoration: none;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .activity-webhook:hover {
            text-decoration: underline;
        }
        
        .preview-btn {
            background: none;
            border: none;
            padding: 5px;
            cursor: pointer;
            color: #6c757d;
            display: flex;
            align-items: center;
            transition: color 0.2s;
        }
        
        .preview-btn:hover {
            color: #007bff;
        }
        
        #previewModal .modal-content {
            width: 90%;
            max-width: 800px;
        }
        
        #previewContent {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            white-space: pre-wrap;
        }
        
        .mapping-modal .modal-content {
            padding: 25px;
        }
        
        .mapping-modal .form-group {
            margin-bottom: 15px;
        }
        
        .mapping-modal label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .mapping-modal select,
        .mapping-modal input[type="text"] {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-top: 5px;
        }
        
        .mapping-modal .save-mapping-btn {
            width: 100%;
            padding: 10px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            margin-top: 20px;
        }
        
        .mapping-modal .save-mapping-btn:hover {
            background: #218838;
        }
        
        .source-field-display {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #dee2e6;
        }
        
        .source-field-display .field-path {
            color: #007bff;
            font-family: monospace;
            font-size: 14px;
            margin-left: 8px;
            word-break: break-all;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Webhook Manager</h1>
        
        <div class="stats-container">
            <div class="overall-stats">
                <h2>Overall Statistics</h2>
                <?php
                $overallStats = getWebhookStats($conn);
                ?>
                <div class="stats-grid">
                    <div class="stat-box">
                        <div class="stat-value"><?php echo number_format($overallStats['total_requests']); ?></div>
                        <div class="stat-label">Total Requests</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value"><?php echo number_format($overallStats['total_webhooks']); ?></div>
                        <div class="stat-label">Active Webhooks</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value"><?php echo number_format($overallStats['unique_days']); ?></div>
                        <div class="stat-label">Days Active</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-value"><?php echo $overallStats['last_request'] ? date('M d, Y', strtotime($overallStats['last_request'])) : 'N/A'; ?></div>
                        <div class="stat-label">Last Activity</div>
                    </div>
                </div>
                
                <h3>Recent Activity</h3>
                <div class="recent-activity">
                    <?php foreach ($overallStats['recent_activity'] as $activity): 
                        $timeAgo = getTimeAgo($activity['received_at']);
                        $payload = json_decode($activity['payload'], true);
                    ?>
                        <div class="activity-item">
                            <div class="activity-time">
                                <?php echo date('M d, Y H:i:s', strtotime($activity['received_at'])); ?>
                                <span class="time-ago">(<?php echo $timeAgo; ?>)</span>
                            </div>
                            <div class="activity-actions">
                                <a href="#webhook_<?php echo $activity['webhook_url']; ?>" 
                                   class="activity-webhook" 
                                   onclick="scrollToWebhook('<?php echo $activity['webhook_url']; ?>')">
                                    <?php echo htmlspecialchars($activity['webhook_url']); ?>
                                </a>
                                <button class="preview-btn" 
                                        onclick="previewPayload(<?php echo htmlspecialchars(json_encode($payload)); ?>)"
                                        title="Preview Data">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                        <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.134 13.134 0 0 1 1.172 8z"/>
                                        <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5M4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <?php
        // Display success messages
        if (isset($_GET['webhook_created'])) {
            $url = "https://" . $_SERVER['HTTP_HOST'] . "/webhooks.php?webhook=" . $_GET['url'];
            echo '<div class="alert alert-success">New webhook created! URL: <code>' . htmlspecialchars($url) . '</code></div>';
        }
        if (isset($_GET['webhook_error'])) {
            echo '<div class="alert alert-error">Error creating webhook!</div>';
        }
        if (isset($_GET['mapping_added'])) {
            echo '<div class="alert alert-success">Mapping added successfully!</div>';
        }
        if (isset($_GET['webhook_deleted'])) {
            echo '<div class="alert alert-success">Webhook deleted successfully!</div>';
        }
        if (isset($_GET['mapping_deleted'])) {
            echo '<div class="alert alert-success">Mapping deleted successfully!</div>';
        }
        if (isset($_GET['mapping_updated'])) {
            echo '<div class="alert alert-success">Mapping updated successfully!</div>';
        }
        if (isset($_GET['rule_added'])) {
            echo '<div class="alert alert-success">Rule added successfully!</div>';
        }
        ?>
        
        <form method="POST" style="display: inline;">
            <input type="hidden" name="create_webhook" value="1">
            <button type="submit">Create New Webhook</button>
        </form>

        <button onclick="window.location.reload()" style="float: right; background: #28a745; padding: 10px 20px; border: none; border-radius: 3px; color: white; cursor: pointer; display: flex; align-items: center; gap: 5px;">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                <path d="M8 3a5 5 0 0 0-5 5v.5a.5.5 0 0 1-1 0V8a6 6 0 1 1 6 6 6 6 0 0 1-6-6V7a.5.5 0 0 1 1 0v1a5 5 0 1 0 5-5z"/>
                <path d="M8 4.5a.5.5 0 0 1 .5.5v3.5l2.146-2.146a.5.5 0 0 1 .708.708l-3 3a.5.5 0 0 1-.708 0l-3-3a.5.5 0 1 1 .708-.708L7.5 8.5V5a.5.5 0 0 1 .5-.5z"/>
            </svg>
            Refresh Data
        </button>

        <div id="webhooks">
            <?php
            $sql = "SELECT * FROM webhooks ORDER BY created_at DESC";
            $result = $conn->query($sql);
            
            while($webhook = $result->fetch_assoc()) {
                echo '<div class="webhook-container" id="webhook_' . $webhook['webhook_url'] . '">';
                echo '<button onclick="deleteWebhook(' . $webhook['id'] . ')" style="float:right;background:#dc3545;">Delete</button>';
                echo '<h3>Webhook URL:</h3>';
                echo '<div class="webhook-url">https://' . $_SERVER['HTTP_HOST'] . '/webhooks.php?webhook=' . $webhook['webhook_url'] . '</div>';
                
                // Add individual webhook stats
                $webhookStats = getWebhookStats($conn, $webhook['id']);
                echo '<div class="webhook-stats">';
                echo '<div class="stats-grid">';
                echo '<div class="stat-box">';
                echo '<div class="stat-value">' . number_format($webhookStats['total_requests']) . '</div>';
                echo '<div class="stat-label">Total Requests</div>';
                echo '</div>';
                echo '<div class="stat-box">';
                echo '<div class="stat-value">' . number_format($webhookStats['unique_days']) . '</div>';
                echo '<div class="stat-label">Days Active</div>';
                echo '</div>';
                echo '<div class="stat-box">';
                echo '<div class="stat-value">' . ($webhookStats['last_request'] ? date('M d, Y', strtotime($webhookStats['last_request'])) : 'N/A') . '</div>';
                echo '<div class="stat-label">Last Request</div>';
                echo '</div>';
                echo '</div>';
                echo '</div>';
                
                $sql = "SELECT * FROM webhook_data WHERE webhook_id = ? ORDER BY received_at DESC";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("i", $webhook['id']);
                $stmt->execute();
                $data_result = $stmt->get_result();
                
                echo '<div class="webhook-data">';
                while($data = $data_result->fetch_assoc()) {
                    echo '<div class="data-item">';
                    $payload = json_decode($data['payload'], true);
                    if ($payload && isset($payload['raw_payload'])) {
                        // If raw_payload exists and is a JSON string, parse it
                        $rawData = json_decode($payload['raw_payload'], true);
                        if ($rawData) {
                            $payload['raw_payload'] = $rawData;
                        }
                    }
                    echo '<div class="json-tree">';
                    echo formatJsonTree($payload);
                    echo '</div>';
                    echo '<small>Received at: ' . $data['received_at'] . '</small>';
                    echo '</div>';
                }
                echo '</div>';
                echo '</div>';
            }
            ?>
        </div>
    </div>

    <div class="mapping-section">
        <h2>Webhook Mapping Configuration</h2>
        <div class="database-explorer">
            <h3>Database Structure Explorer</h3>
            <div class="explorer-container">
                <div class="tables-list">
                    <select id="tableExplorer" onchange="showTableDetails(this.value)">
                        <option value="">Select a table...</option>
                    </select>
                </div>
                <div id="tableDetails" class="table-details">
                    <!-- Table details will be shown here -->
                </div>
            </div>
        </div>
        <form method="POST" class="mapping-form">
            <input type="hidden" name="add_mapping" value="1">
            
            <div class="form-group">
                <label>Webhook:</label>
                <select name="webhook_id" required>
                    <?php
                    $sql = "SELECT id, webhook_url FROM webhooks";
                    $result = $conn->query($sql);
                    while ($row = $result->fetch_assoc()) {
                        echo "<option value='{$row['id']}'>{$row['webhook_url']}</option>";
                    }
                    ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Target Table:</label>
                <select name="target_table" id="targetTableSelect" required onchange="updateTargetFields()">
                    <option value="">Select a table...</option>
                    <?php
                    // Get all tables from the database
                    $tablesResult = $conn->query("SHOW TABLES");
                    while ($table = $tablesResult->fetch_array(MYSQLI_NUM)) {
                        $tableName = $table[0];
                        echo "<option value='{$tableName}'>{$tableName}</option>";
                    }
                    ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Source Field (use dot notation, e.g., payload.email):</label>
                <input type="text" name="source_field" required>
            </div>
            
            <div class="form-group">
                <label>Target Field (database column):</label>
                <select name="target_field" id="targetFieldSelect" required>
                    <option value="">Select a table first...</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Transform Rule:</label>
                <select class="transform-select" onchange="handleTransformSelect(this)">
                    <option value="">No transformation</option>
                    <option value="UPPERCASE">UPPERCASE</option>
                    <option value="LOWERCASE">LOWERCASE</option>
                    <option value="TRIM">TRIM</option>
                    <option value="TIMESTAMP">TIMESTAMP</option>
                    <option value="REPLACE">REPLACE</option>
                    <option value="IF/THEN">IF/THEN</option>
                </select>
                <input type="hidden" name="transform_rule" class="transform-rule-input">
            </div>
            
            <div class="form-group">
                <label>
                    <input type="checkbox" name="is_required">
                    Required Field
                </label>
            </div>
            
            <button type="submit">Add Mapping</button>
        </form>
        
        <h3>Existing Mappings</h3>
        <div class="mappings-list">
            <?php
            $sql = "SELECT m.*, w.webhook_url 
                    FROM webhook_mappings m 
                    JOIN webhooks w ON m.webhook_id = w.id 
                    ORDER BY m.webhook_id, m.target_table";
            $result = $conn->query($sql);
            
            while ($mapping = $result->fetch_assoc()) {
                echo "<div class='mapping-item' id='mapping_{$mapping['id']}'>";
                echo "<button onclick='deleteMapping({$mapping['id']})' style='float:right;background:#dc3545;'>Delete</button>";
                echo "<button onclick='editMapping({$mapping['id']})' style='float:right;background:#007bff;margin-right:5px;'>Edit</button>";
                echo "<strong>Webhook:</strong> {$mapping['webhook_url']}<br>";
                echo "<strong>Table:</strong> {$mapping['target_table']}<br>";
                echo "<strong>Mapping:</strong> <span class='mapping-data'>{$mapping['source_field']} → {$mapping['target_field']}</span>";
                echo "<form class='edit-form' style='display:none;margin-top:10px;' onsubmit='return updateMapping(event, {$mapping['id']})'>";
                echo "<input type='hidden' name='update_mapping' value='1'>";
                echo "<input type='hidden' name='mapping_id' value='{$mapping['id']}'>";
                echo "<input type='text' name='source_field' value='{$mapping['source_field']}' placeholder='Source field'><br>";
                echo "<input type='text' name='target_field' value='{$mapping['target_field']}' placeholder='Target field'><br>";
                echo "<select name='transform_rule' onchange='handleTransformSelect(this)'>";
                echo "<option value=''>No transformation</option>";
                echo "<option value='UPPERCASE'" . ($mapping['transform_rule'] === 'UPPERCASE' ? ' selected' : '') . ">UPPERCASE</option>";
                echo "<option value='LOWERCASE'" . ($mapping['transform_rule'] === 'LOWERCASE' ? ' selected' : '') . ">LOWERCASE</option>";
                echo "<option value='TRIM'" . ($mapping['transform_rule'] === 'TRIM' ? ' selected' : '') . ">TRIM</option>";
                echo "<option value='TIMESTAMP'" . ($mapping['transform_rule'] === 'TIMESTAMP' ? ' selected' : '') . ">TIMESTAMP</option>";
                echo "<option value='REPLACE'" . (strpos($mapping['transform_rule'], 'REPLACE:') === 0 ? ' selected' : '') . ">REPLACE</option>";
                echo "</select>";
                if (strpos($mapping['transform_rule'], 'REPLACE:') === 0) {
                    $replaceValue = substr($mapping['transform_rule'], 8);
                    echo "<input type='text' name='replacement_value' value='{$replaceValue}' class='replacement-input'>";
                }
                echo "<label><input type='checkbox' name='is_required'" . ($mapping['is_required'] ? ' checked' : '') . "> Required</label><br>";
                echo "<button type='submit' style='background:#28a745;'>Save</button>";
                echo "<button type='button' onclick='cancelEdit({$mapping['id']})' style='background:#6c757d;'>Cancel</button>";
                echo "</form>";
                echo "</div>";
            }
            ?>
        </div>
    </div>

    <div id="webhookCatcherModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Waiting for Webhook Data</h2>
            <div class="webhook-url-container">
                <p>Send a request to:</p>
                <div class="webhook-url" id="modalWebhookUrl"></div>
                <button onclick="copyWebhookUrl()">Copy URL</button>
            </div>
            <div class="waiting-spinner">
                <div class="spinner"></div>
                <p>Waiting for data...</p>
            </div>
            <div id="receivedDataSection" style="display: none;">
                <h3>Data Received!</h3>
                <div class="mapping-container">
                    <div class="received-data">
                        <h4>Received Data Structure</h4>
                        <div id="jsonTree"></div>
                    </div>
                    <div class="database-structure">
                        <h4>Database Tables</h4>
                        <select id="tableSelect" onchange="loadColumns()">
                            <option value="">Select a table...</option>
                        </select>
                        <div id="columnMappings"></div>
                    </div>
                </div>
                <button onclick="saveMapping()" class="save-mapping-btn">Save Mapping</button>
            </div>
        </div>
    </div>

    <!-- Add a modal for payload preview -->
    <div id="previewModal" class="modal">
        <div class="modal-content">
            <span class="close" id="closePreviewModal">&times;</span>
            <h2>Webhook Data Preview</h2>
            <pre id="previewContent"></pre>
        </div>
    </div>

    <script>
    let currentWebhookId = null;
    let currentWebhookUrl = null;
    let lastCheckTime = null;
    let webhookData = null;
    let dbStructure = null;

    function createWebhook() {
        fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'create_webhook=1'
        })
        .then(response => response.json())
        .then(data => {
            currentWebhookUrl = `https://${window.location.host}/webhooks.php?webhook=${data.url}`;
            currentWebhookId = data.webhook_id;
            // Set initial check time to now
            lastCheckTime = new Date().toISOString().slice(0, 19).replace('T', ' ');
            
            document.getElementById('modalWebhookUrl').textContent = currentWebhookUrl;
            document.getElementById('webhookCatcherModal').style.display = 'block';
            document.getElementById('receivedDataSection').style.display = 'none';
            
            pollForWebhookData();
            loadDatabaseStructure();
        });
    }

    function pollForWebhookData() {
        const webhookUrl = currentWebhookUrl.split('webhook=')[1];
        
        // Add timestamp to prevent caching
        const timestamp = new Date().getTime();
        fetch(`webhooks.php?check_webhook_data=1&webhook_url=${webhookUrl}&last_check=${lastCheckTime}&_=${timestamp}`)
            .then(response => response.json())
            .then(data => {
                if (data.has_new_data) {
                    webhookData = data.data;
                    lastCheckTime = data.received_at;
                    showReceivedData();
                } else {
                    // Continue polling
                    setTimeout(pollForWebhookData, 2000);
                }
            })
            .catch(error => {
                console.error('Polling error:', error);
                setTimeout(pollForWebhookData, 2000);
            });
    }

    function showReceivedData() {
        document.querySelector('.waiting-spinner').style.display = 'none';
        document.getElementById('receivedDataSection').style.display = 'block';
        
        const jsonTree = document.getElementById('jsonTree');
        
        console.log('Received webhook data:', webhookData);
        
        let formattedHtml = '<div class="json-tree">';
        formattedHtml += formatJsonToHtml(webhookData);
        formattedHtml += '</div>';
        
        jsonTree.innerHTML = formattedHtml;
    }

    function loadDatabaseStructure() {
        fetch('webhooks.php?get_db_structure=1')
            .then(response => response.json())
            .then(data => {
                console.log('Received database structure:', data); // Debug log
                dbStructure = data;
                const select = document.getElementById('tableExplorer');
                select.innerHTML = '<option value="">Select a table...</option>';
                
                if (data.error) {
                    console.error('Database error:', data.error, data.message);
                    return;
                }
                
                if (data.length === 0) {
                    console.log('No tables found in response');
                    return;
                }
                
                data.forEach(table => {
                    console.log('Adding table:', table.name); // Debug log
                    const option = document.createElement('option');
                    option.value = table.name;
                    option.textContent = table.name;
                    select.appendChild(option);
                });
            })
            .catch(error => {
                console.error('Failed to load database structure:', error);
            });
    }

    function showTableDetails(tableName) {
        if (!tableName) return;
        
        const table = dbStructure.find(t => t.name === tableName);
        const details = document.getElementById('tableDetails');
        
        let html = `<h4>${tableName}</h4>`;
        
        table.columns.forEach(column => {
            html += `
                <div class="column-item">
                    <div class="column-name">${column.name}</div>
                    <div class="column-type">${column.type}</div>
                    <div>
                        ${column.required ? '<span class="column-required">Required</span>' : ''}
                        <button class="copy-path" onclick="copyFieldPath('${column.name}')">Copy Path</button>
                    </div>
                    ${column.comment ? `<div class="column-comment">${column.comment}</div>` : ''}
                </div>
            `;
            
            // Show foreign key relationship if exists
            const fk = table.foreign_keys.find(fk => fk.COLUMN_NAME === column.name);
            if (fk) {
                html += `
                    <div class="foreign-key">
                        ↳ References ${fk.REFERENCED_TABLE_NAME}.${fk.REFERENCED_COLUMN_NAME}
                    </div>
                `;
            }
        });
        
        details.innerHTML = html;
    }

    function copyFieldPath(columnName) {
        // You can customize this based on your needs
        const path = `payload.${columnName.toLowerCase()}`;
        navigator.clipboard.writeText(path);
        alert(`Copied path: ${path}`);
    }

    function loadColumns() {
        const tableSelect = document.getElementById('tableSelect');
        const selectedTable = tableSelect.value;
        
        if (!selectedTable) return;
        
        fetch('webhooks.php?get_db_structure=1')
            .then(response => response.json())
            .then(tables => {
                const table = tables.find(t => t.name === selectedTable);
                const mappingContainer = document.getElementById('columnMappings');
                mappingContainer.innerHTML = '';
                
                table.columns.forEach(column => {
                    const mappingField = document.createElement('div');
                    mappingField.className = 'mapping-field';
                    mappingField.innerHTML = `
                        <label>${column.name} (${column.type})</label>
                        <input type="text" 
                               placeholder="Enter source field path"
                               data-column="${column.name}"
                               class="mapping-input">
                        <select class="transform-select">
                            <option value="">No transformation</option>
                            <option value="UPPERCASE">UPPERCASE</option>
                            <option value="LOWERCASE">LOWERCASE</option>
                            <option value="TRIM">TRIM</option>
                            <option value="TIMESTAMP">TIMESTAMP</option>
                        </select>
                        <label>
                            <input type="checkbox" class="required-checkbox">
                            Required
                        </label>
                    `;
                    mappingContainer.appendChild(mappingField);
                });
            });
    }

    function handleTransformSelect(select) {
        const parent = select.parentNode;
        const transformRuleInput = parent.querySelector('.transform-rule-input');
        const oldInput = parent.querySelector('.rule-input');
        if (oldInput) oldInput.remove();
        
        if (select.value === 'REPLACE') {
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'replacement-input rule-input';
            input.name = 'replacement_value';
            input.placeholder = 'Enter replacement value';
            input.onchange = function() {
                transformRuleInput.value = 'REPLACE:' + this.value;
            };
            select.parentNode.appendChild(input);
        } 
        else if (select.value === 'IF/THEN') {
            // Get the source field from the hidden input
            const sourceField = parent.closest('form').querySelector('input[name="source_field"]').value;
            
            const form = document.createElement('div');
            form.className = 'rule-input if-then-form';
            form.innerHTML = `
                <div class="form-group">
                    <label>If this field:</label>
                    <input type="text" class="check-value" placeholder="equals this value">
                </div>
                <div class="form-group">
                    <label>Is:</label>
                    <select class="check-operator">
                        <option value="equals">Equals</option>
                        <option value="contains">Contains</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Then set to:</label>
                    <input type="text" class="result-value" placeholder="this value">
                </div>
                <div class="form-group">
                    <label>Otherwise set to:</label>
                    <input type="text" class="default-value" placeholder="default value">
                </div>
            `;
            
            // Add change handler for all inputs
            form.querySelectorAll('input, select').forEach(input => {
                input.onchange = function() {
                    const rule = {
                        conditions: [{
                            field: sourceField, // Use the current field path
                            operator: form.querySelector('.check-operator').value,
                            value: form.querySelector('.check-value').value,
                            result: form.querySelector('.result-value').value
                        }],
                        default: form.querySelector('.default-value').value
                    };
                    transformRuleInput.value = 'IF/THEN:' + JSON.stringify(rule);
                };
            });
            
            select.parentNode.appendChild(form);
        }
        else {
            transformRuleInput.value = select.value;
        }
    }

    function saveMapping() {
        const mappings = [];
        
        document.querySelectorAll('.mapping-field').forEach(field => {
            const sourceField = field.querySelector('.mapping-input').value;
            const transformSelect = field.querySelector('.transform-select');
            let transformRule = transformSelect.value;
            
            if (transformRule === 'REPLACE') {
                const replacementValue = field.querySelector('.replacement-input')?.value || '';
                transformRule = `REPLACE:${replacementValue}`;
            }
            
            if (sourceField) {
                mappings.push({
                    webhook_id: currentWebhookId,
                    target_table: tableSelect.value,
                    source_field: sourceField,
                    target_field: field.querySelector('.mapping-input').dataset.column,
                    transform_rule: transformRule,
                    is_required: field.querySelector('.required-checkbox').checked
                });
            }
        });
        
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = window.location.href;
        
        // Add hidden fields for the mapping data
        const addMappingInput = document.createElement('input');
        addMappingInput.type = 'hidden';
        addMappingInput.name = 'add_mapping';
        addMappingInput.value = '1';
        form.appendChild(addMappingInput);
        
        // Add the mapping data as hidden fields
        Object.entries(mappings[0]).forEach(([key, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = value;
            form.appendChild(input);
        });
        
        // Submit the form
        document.body.appendChild(form);
        form.submit();
    }

    function copyWebhookUrl() {
        navigator.clipboard.writeText(currentWebhookUrl);
        alert('Webhook URL copied to clipboard!');
    }

    function formatJsonToHtml(obj, level = 0) {
        if (!obj) return '<div class="json-empty">No data received</div>';
        
        const indent = '  '.repeat(level);
        let html = '';
        
        for (const key in obj) {
            const value = obj[key];
            const displayValue = typeof value === 'object' && value !== null 
                ? ''
                : `<span class="json-value">${JSON.stringify(value)}</span>`;
            
            html += `
                <div class="json-line" style="padding-left: ${level * 20}px">
                    <span class="json-key">${key}:</span>
                    ${displayValue}
                </div>
            `;
            
            if (typeof value === 'object' && value !== null) {
                html += formatJsonToHtml(value, level + 1);
            }
        }
        
        return html;
    }

    // Close modal when clicking the X
    document.querySelector('.close').onclick = function() {
        document.getElementById('webhookCatcherModal').style.display = 'none';
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('webhookCatcherModal');
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    }

    // Make sure the element exists before trying to load the structure
    document.addEventListener('DOMContentLoaded', () => {
        const select = document.getElementById('tableExplorer');
        if (!select) {
            console.error('Could not find tableExplorer element');
            return;
        }
        loadDatabaseStructure();
    });

    function deleteWebhook(id) {
        if (confirm('Delete this webhook and all its data?')) {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'delete_webhook=1&webhook_id=' + id
            }).then(() => location.reload());
        }
    }

    function deleteMapping(id) {
        if (confirm('Delete this mapping?')) {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'delete_mapping=1&mapping_id=' + id
            }).then(() => location.reload());
        }
    }

    // Function to copy path to clipboard
    function copyPath(path) {
        console.log('Mapping path:', path); // Debug log
        
        // Create and show the mapping modal
        const mappingModal = document.createElement('div');
        mappingModal.className = 'modal mapping-modal';
        mappingModal.style.display = 'block';
        mappingModal.innerHTML = `
            <div class="modal-content" style="max-width: 500px;">
                <span class="close">&times;</span>
                <h3>Quick Field Mapping</h3>
                <div class="source-field-display">
                    <strong>Mapping Field:</strong> 
                    <span class="field-path">${path}</span>
                </div>
                <form id="quickMappingForm" class="mapping-form">
                    <input type="hidden" name="add_mapping" value="1">
                    <input type="hidden" name="source_field" value="${path}">
                    
                    <div class="form-group">
                        <label>Target Table:</label>
                        <select name="target_table" id="quickTargetTable" required onchange="updateQuickTargetFields()">
                            <option value="">Select a table...</option>
                            ${Array.from(document.getElementById('targetTableSelect').options)
                                .map(opt => `<option value="${opt.value}">${opt.value}</option>`)
                                .join('')}
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Target Field:</label>
                        <select name="target_field" id="quickTargetField" required>
                            <option value="">Select a table first...</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Transform Rule:</label>
                        <select class="transform-select" onchange="handleTransformSelect(this)">
                            <option value="">No transformation</option>
                            <option value="UPPERCASE">UPPERCASE</option>
                            <option value="LOWERCASE">LOWERCASE</option>
                            <option value="TRIM">TRIM</option>
                            <option value="TIMESTAMP">TIMESTAMP</option>
                            <option value="REPLACE">REPLACE</option>
                            <option value="IF/THEN">IF/THEN</option>
                        </select>
                        <input type="hidden" name="transform_rule" class="transform-rule-input">
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="is_required">
                            Required Field
                        </label>
                    </div>
                    
                    <div class="form-group">
                        <label>Webhook:</label>
                        <select name="webhook_id" required>
                            ${Array.from(document.querySelector('select[name="webhook_id"]').options)
                                .map(opt => `<option value="${opt.value}">${opt.textContent}</option>`)
                                .join('')}
                        </select>
                    </div>
                    
                    <button type="submit" class="save-mapping-btn">Save Mapping</button>
                </form>
            </div>
        `;
        
        // Add these styles specifically for the source field display
        const sourceFieldStyles = `
            .source-field-display {
                background: #f8f9fa;
                padding: 12px;
                border-radius: 4px;
                margin-bottom: 20px;
                border: 1px solid #dee2e6;
            }
            
            .source-field-display .field-path {
                color: #007bff;
                font-family: monospace;
                font-size: 14px;
                margin-left: 8px;
                word-break: break-all;
            }
        `;
        
        // Add the styles
        const style = document.createElement('style');
        style.textContent = sourceFieldStyles;
        document.head.appendChild(style);
        
        document.body.appendChild(mappingModal);
        
        // Rest of the event handlers...
        const closeBtn = mappingModal.querySelector('.close');
        closeBtn.onclick = () => {
            mappingModal.remove();
        };
        
        window.onclick = (event) => {
            if (event.target === mappingModal) {
                mappingModal.remove();
            }
        };
        
        const form = mappingModal.querySelector('#quickMappingForm');
        form.onsubmit = (e) => {
            e.preventDefault();
            console.log('Form submitted');
            fetch('', {
                method: 'POST',
                body: new FormData(form)
            }).then(() => {
                mappingModal.remove();
                location.reload();
            });
        };
    }

    function updateQuickTargetFields() {
        const tableSelect = document.getElementById('quickTargetTable');
        const fieldSelect = document.getElementById('quickTargetField');
        const selectedTable = tableSelect.value;
        
        // Clear current options
        fieldSelect.innerHTML = '<option value="">Select a field...</option>';
        
        if (!selectedTable) return;
        
        // Fetch columns for the selected table
        fetch(`webhooks.php?get_db_structure=1`)
            .then(response => response.json())
            .then(tables => {
                const table = tables.find(t => t.name === selectedTable);
                if (table && table.columns) {
                    table.columns.forEach(column => {
                        const option = document.createElement('option');
                        option.value = column.name;
                        option.textContent = `${column.name} (${column.type})${column.required ? ' *' : ''}`;
                        fieldSelect.appendChild(option);
                    });
                }
            })
            .catch(error => console.error('Error loading table columns:', error));
    }

    // When displaying existing mappings, add this logic
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.transform-select').forEach(select => {
            const transformRule = select.parentNode.querySelector('.transform-rule-input').value;
            if (transformRule && transformRule.startsWith('REPLACE:')) {
                select.value = 'REPLACE';
                handleTransformSelect(select);
                const replacementInput = select.parentNode.querySelector('.replacement-input');
                if (replacementInput) {
                    replacementInput.value = transformRule.substring(8);
                }
            }
        });
    });

    // Add these functions inside the existing script tag
    function editMapping(id) {
        document.querySelectorAll('.edit-form').forEach(f => f.style.display = 'none');
        document.querySelector(`#mapping_${id} .edit-form`).style.display = 'block';
        document.querySelector(`#mapping_${id} .mapping-data`).style.display = 'none';
    }

    function cancelEdit(id) {
        document.querySelector(`#mapping_${id} .edit-form`).style.display = 'none';
        document.querySelector(`#mapping_${id} .mapping-data`).style.display = 'inline';
    }

    function updateMapping(event, id) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        
        fetch('', {
            method: 'POST',
            body: formData
        }).then(() => location.reload());
        
        return false;
    }

    // Add some CSS for the form
    const style = document.createElement('style');
    style.textContent = `
        .if-then-form {
            background: #f5f5f5;
            padding: 10px;
            margin-top: 10px;
            border-radius: 4px;
        }
        .if-then-form .form-group {
            margin-bottom: 10px;
        }
        .if-then-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .if-then-form input,
        .if-then-form select {
            width: 100%;
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 3px;
        }
    `;
    document.head.appendChild(style);

    function scrollToWebhook(webhookUrl) {
        const element = document.getElementById('webhook_' + webhookUrl);
        if (element) {
            element.scrollIntoView({ behavior: 'smooth', block: 'start' });
            element.style.backgroundColor = '#fff3cd';
            setTimeout(() => {
                element.style.backgroundColor = '';
                element.style.transition = 'background-color 1s ease';
            }, 100);
        }
    }

    function previewPayload(payload) {
        const modal = document.getElementById('previewModal');
        const content = document.getElementById('previewContent');
        content.textContent = JSON.stringify(payload, null, 2);
        modal.style.display = 'block';
    }

    // Modal close functionality
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('previewModal');
        const closeBtn = document.getElementById('closePreviewModal');

        // Close on X button click
        closeBtn.addEventListener('click', function() {
            modal.style.display = 'none';
        });

        // Close on outside click
        modal.addEventListener('click', function(event) {
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });

        // Close on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && modal.style.display === 'block') {
                modal.style.display = 'none';
            }
        });
    });

    function updateTargetFields() {
        const tableSelect = document.getElementById('targetTableSelect');
        const fieldSelect = document.getElementById('targetFieldSelect');
        const selectedTable = tableSelect.value;
        
        // Clear current options
        fieldSelect.innerHTML = '<option value="">Select a field...</option>';
        
        if (!selectedTable) return;
        
        // Fetch columns for the selected table
        fetch(`webhooks.php?get_db_structure=1`)
            .then(response => response.json())
            .then(tables => {
                const table = tables.find(t => t.name === selectedTable);
                if (table && table.columns) {
                    table.columns.forEach(column => {
                        const option = document.createElement('option');
                        option.value = column.name;
                        option.textContent = `${column.name} (${column.type})${column.required ? ' *' : ''}`;
                        fieldSelect.appendChild(option);
                    });
                }
            })
            .catch(error => console.error('Error loading table columns:', error));
    }

    // Add some styling for required fields
    document.addEventListener('DOMContentLoaded', function() {
        const style = document.createElement('style');
        style.textContent = `
            #targetFieldSelect option:contains('*') {
                font-weight: bold;
                color: #dc3545;
            }
        `;
        document.head.appendChild(style);
    });

    // Add this to your existing JavaScript section
    function toggleNested(element) {
        const nestedContent = element.nextElementSibling;
        if (nestedContent.style.display === 'none') {
            nestedContent.style.display = 'block';
            element.firstChild.textContent = '▼';
        } else {
            nestedContent.style.display = 'none';
            element.firstChild.textContent = '▶';
        }
    }
    </script>
</body>
</html>
