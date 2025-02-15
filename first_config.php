<?php
// Add this function at the top of the file
function ensureUsersTableExists() {
    global $db_host, $db_name, $db_user, $db_pass;  // Use global variables
    
    try {
        $conn = new PDO("mysql:host=" . $db_host . ";dbname=" . $db_name, $db_user, $db_pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create users table if it doesn't exist with all required fields
        $sql = "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE,
            full_name VARCHAR(100) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            phone VARCHAR(20),
            password VARCHAR(255) NOT NULL,
            is_admin BOOLEAN DEFAULT 0,
            user_type VARCHAR(20) DEFAULT 'user',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $conn->exec($sql);
        
          // Check if admin user exists
          $stmt = $conn->prepare("SELECT id FROM users WHERE email = 'admin@test.com'");
          $stmt->execute();
          
          if ($stmt->rowCount() == 0) {
              // Create default admin user if it doesn't exist
              $hashedPassword = password_hash('123456', PASSWORD_DEFAULT);
              $sql = "INSERT INTO users (username, full_name, email, password, is_admin, user_type) 
                     VALUES ('admin', 'Admin User', 'admin@test.com', :password, 1, 'user')";
              
              $stmt = $conn->prepare($sql);
              $stmt->execute(['password' => $hashedPassword]);
          }
          
          return true;

    } catch(PDOException $e) {
        error_log("Failed to create users table: " . $e->getMessage());
        return false;
    }
}

// Call the function to ensure table exists
if (!ensureUsersTableExists()) {
    die("Failed to initialize database. Please contact administrator.");
}

?>