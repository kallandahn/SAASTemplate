<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

if (!isset($_POST['action'])) {
    echo json_encode(['success' => false, 'message' => 'No action specified']);
    exit;
}

switch ($_POST['action']) {
    case 'test_db':
        $host = $_POST['host'];
        $dbname = $_POST['name'];
        $username = $_POST['username'];
        $password = $_POST['password'];

        try {
            // Test connection without database
            $conn = new PDO("mysql:host=$host", $username, $password);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // If connection successful, try to create database and tables
            if (createDatabase($host, $username, $password, $dbname)) {
                echo json_encode(['success' => true, 'message' => 'Database connection and setup successful']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create database or tables']);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
        }
        break;

    case 'create_admin':
        if (!file_exists('config.php')) {
            echo json_encode(['success' => false, 'message' => 'Database configuration not found']);
            exit;
        }

        require_once 'config.php';

        $username = $_POST['username'];
        $email = $_POST['email'];
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

        try {
            $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $stmt = $conn->prepare("INSERT INTO users (username, email, password, is_admin) VALUES (?, ?, ?, 1)");
            $stmt->execute([$username, $email, $password]);
            
            // Start session and store login data
            session_start();
            $_SESSION['user_id'] = $conn->lastInsertId();
            $_SESSION['username'] = $username;
            $_SESSION['is_admin'] = 1;

            echo json_encode(['success' => true, 'message' => 'Admin account created and logged in successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Failed to create admin account: ' . $e->getMessage()]);
        }
        break;

    case 'login':
        if (!file_exists('config.php')) {
            echo json_encode(['success' => false, 'message' => 'Database configuration not found']);
            exit;
        }

        require_once 'config.php';

        // Add input validation
        if (!isset($_POST['email']) || !isset($_POST['password'])) {
            echo json_encode(['success' => false, 'message' => 'Email and password are required']);
            exit;
        }

        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'];

        try {
            $conn = new PDO("mysql:host=" . $db_host . ";dbname=" . $db_name, $db_user, $db_pass);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Add debugging
            error_log("Attempting login for email: " . $email);

            $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Debug user data
            error_log("User found: " . ($user ? "Yes" : "No"));
            
            if ($user && password_verify($password, $user['password'])) {
                error_log("Password verified successfully");
                // Start session and store login data
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['is_admin'] = $user['is_admin'];
                $_SESSION['user_name'] = $user['full_name'];

                echo json_encode(['success' => true, 'message' => 'Login successful']);
            } else {
                error_log("Password verification failed");
                echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
            }
        } catch (PDOException $e) {
            error_log("Database error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Login failed: ' . $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

// Include the createDatabase function from onboarding.php
function createDatabase($host, $user, $pass, $dbname) {
    try {
        $conn = new PDO("mysql:host=$host", $user, $pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create database
        $conn->exec("CREATE DATABASE IF NOT EXISTS $dbname");
        
        // Select the database
        $conn->exec("USE $dbname");
        
        // Create users table
        $sql = "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            is_admin BOOLEAN DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $conn->exec($sql);
        
        // Create config file
        $config = "<?php
            define('DB_HOST', '$host');
            define('DB_NAME', '$dbname');
            define('DB_USER', '$user');
            define('DB_PASS', '$pass');
        ?>";
        file_put_contents('config.php', $config);
        
        return true;
    } catch(PDOException $e) {
        return false;
    }
}