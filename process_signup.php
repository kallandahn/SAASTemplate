<?php
// Add these debug lines at the very start of the file
error_log("Raw POST data: " . file_get_contents('php://input'));
error_log("POST array contents: " . print_r($_POST, true));
session_start();

// Add these lines at the very top of your file
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';
require 'onboarding/config.php';  // Add this line to include config file

// Load branding information
$branding = json_decode(file_get_contents('branding.json'), true);


try {
    // Add these debug lines at the start of the try block
    error_log("POST data received: " . print_r($_POST, true));
    
    // More detailed validation
    $errors = [];
    if (empty($_POST['full_name'])) $errors[] = "Full name is required";
    if (empty($_POST['email'])) $errors[] = "Email is required";
    if (empty($_POST['password'])) $errors[] = "Password is required";

    if (!empty($errors)) {
        die("Form validation failed: " . implode(", ", $errors));
    }

    // Create database connection using variables from config.php
    $conn = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Update to use full_name instead of name
    $name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $user_type = trim($_POST['user_type']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT); // Encrypt password
    $created_at = date('Y-m-d H:i:s');
 
    // Check if email already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->rowCount() > 0) {
        die("Email already registered. Please use a different email.");
    }

    // Insert user data
    $sql = "INSERT INTO users (full_name, email, phone, user_type, password, created_at) 
            VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$name, $email, $phone, $user_type, $password, $created_at]);

    // Get the user ID after successful insertion
    $user_id = $conn->lastInsertId();
    
    // Set up session variables
    $_SESSION['user_id'] = $user_id;
    $_SESSION['user_name'] = $name;
    $_SESSION['user_type'] = $user_type;
    $_SESSION['logged_in'] = true;

    // Redirect to dashboard
    header("Location: dashboard.php");
    exit();

} catch(PDOException $e) {
    die("Registration failed: " . $e->getMessage());
}
?> 