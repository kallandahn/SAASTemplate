<?php
session_start();

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function isAdmin() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    require 'db_connection.php';
    $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $user && $user['is_admin'];
}

function requireAdmin() {
    if (!isAdmin()) {
        header("Location: login.php");
        exit();
    }
}

function adminCheck(){
    isLoggedIn();
    requireAdmin();
}
?> 