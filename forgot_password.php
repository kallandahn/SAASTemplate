<?php
require_once 'session_start.php';
require 'onboarding/config.php';
require 'webmailconfig.php';
require 'vendor/autoload.php';

// Add reset_token column if it doesn't exist
try {
    $conn = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if column exists
    $stmt = $conn->query("(SHOW COLUMNS FROM users LIKE 'reset_token')");
    if ($stmt->rowCount() == 0) {
        // Column doesn't exist, so add it
        $conn->exec("ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL");
    }
} catch(PDOException $e) {
    // Silently continue if there's an error - the column might already exist
}

use PHPMailer\PHPMailer\PHPMailer;

// Load branding data
$brandingData = json_decode(file_get_contents('branding.json'), true);
$companyName = $brandingData['companyInfo']['name'] ?? 'Company Name';

// Extract commonly used values from branding
$colors = $brandingData['visualIdentity']['colors'];
$logoUrl = $brandingData['visualIdentity']['logoUrl']['primary'] ?? 'default-logo.png';

// Handle password reset request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if (isset($_POST['email'])) { // Sending reset link
            $email = $_POST['email'];
            $token = bin2hex(random_bytes(32));
            
            // Update user with reset token
            $stmt = $conn->prepare("UPDATE users SET reset_token = ? WHERE email = ?");
            $stmt->execute([$token, $email]);
            
            if ($stmt->rowCount() > 0) {
                // Send email
                $mail = new PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = 'server232.web-hosting.com';
                $mail->SMTPAuth = true;
                $mail->Username = $mailUsername;
                $mail->Password = $mailPassword;
                $mail->SMTPSecure = $mailSMTPSecure;
                $mail->Port = $mailPort;
                
                $mail->setFrom($brandingData['contactInformation']['email'], $companyName);
                $mail->addAddress($email);
                
                $resetLink = "https://" . $_SERVER['HTTP_HOST'] . "/forgot_password.php?token=" . $token;
                
                $mail->isHTML(true);
                $mail->Subject = 'Password Reset Request';
                $mail->Body = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
                        <div style='text-align: center; padding: 20px 0;'>
                            <img src='{$brandingData['visualIdentity']['logoUrl']['primary']}' alt='{$companyName}' style='max-width: 200px; height: auto;'>
                            <h1 style='color: {$colors['primaryText']}; margin-bottom: 10px;'>Reset Your Password</h1>
                            <p style='color: {$colors['secondaryText']}; font-size: 18px;'>{$brandingData['companyInfo']['tagline']}</p>
                        </div>

                        <div style='background-color: {$colors['background']}; border-radius: 8px; padding: 30px; margin: 20px 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1);'>
                            <p style='color: {$colors['primaryText']}; margin-bottom: 20px;'>We received a request to reset your password. Click the button below to create a new password:</p>
                            
                            <div style='text-align: center; margin: 30px 0;'>
                                <a href='{$resetLink}' 
                                   style='background-color: {$colors['button']}; 
                                          color: {$colors['buttonText']}; 
                                          padding: 12px 25px; 
                                          text-decoration: none; 
                                          border-radius: 5px; 
                                          font-weight: bold;
                                          display: inline-block;'>
                                    Reset Password
                                </a>
                            </div>

                            <p style='color: {$colors['secondaryText']}; font-size: 14px;'>If you didn't request this password reset, you can safely ignore this email.</p>
                        </div>

                        <div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;'>
                            <div style='color: {$colors['secondaryText']}; font-size: 14px; text-align: center;'>
                                <p style='margin-bottom: 15px;'><strong>Need help?</strong></p>
                                <p style='margin: 5px 0;'>Phone: {$brandingData['contactInformation']['phone']}</p>
                                <p style='margin: 5px 0;'>Email: {$brandingData['contactInformation']['email']}</p>
                                <p style='margin: 5px 0;'>{$brandingData['contactInformation']['address']}</p>
                            </div>

                            <div style='text-align: center; margin-top: 20px;'>
                                <a href='{$brandingData['socialMedia']['facebook']}' style='color: {$colors['primaryText']}; text-decoration: none; margin: 0 10px;'>Facebook</a>
                                <a href='{$brandingData['socialMedia']['twitter']}' style='color: {$colors['primaryText']}; text-decoration: none; margin: 0 10px;'>Twitter</a>
                                <a href='{$brandingData['socialMedia']['linkedin']}' style='color: {$colors['primaryText']}; text-decoration: none; margin: 0 10px;'>LinkedIn</a>
                            </div>

                            <p style='text-align: center; margin-top: 20px; color: {$colors['secondaryText']}; font-size: 12px;'>
                                © " . date('Y') . " {$companyName}. All rights reserved.
                            </p>
                        </div>
                    </div>
                ";

                // Plain text version as fallback
                $mail->AltBody = "Reset your password by clicking this link: {$resetLink}";
                
                $mail->send();
                $message = "Reset link has been sent to your email.";
            } else {
                $error = "Email not found.";
            }
        } elseif (isset($_POST['token']) && isset($_POST['password'])) { // Setting new password
            $token = $_POST['token'];
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            
            // Update password and clear token
            $stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL WHERE reset_token = ?");
            $stmt->execute([$password, $token]);
            
            if ($stmt->rowCount() > 0) {
                header("Location: index.php?message=Password+updated");
                exit();
            }
        }
    } catch(Exception $e) {
        $error = "An error occurred: " . $e->getMessage();
    }
}

// Check if we're showing the reset form
$showResetForm = isset($_GET['token']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | <?php echo htmlspecialchars($companyName); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, <?php echo $colors['background']; ?>, <?php echo adjustBrightness($colors['background'], -10); ?>);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        /* Add subtle animated background shapes */
        body::before,
        body::after {
            content: '';
            position: absolute;
            width: 1000px;
            height: 1000px;
            border-radius: 50%;
            background: <?php echo adjustBrightness($colors['button'], 30); ?>;
            opacity: 0.1;
            z-index: 0;
            animation: float 20s infinite linear;
        }

        body::before {
            top: -400px;
            left: -200px;
        }

        body::after {
            bottom: -400px;
            right: -200px;
            animation-delay: -10s;
        }

        @keyframes float {
            0% { transform: rotate(0deg) translate(0, 0); }
            50% { transform: rotate(180deg) translate(50px, 50px); }
            100% { transform: rotate(360deg) translate(0, 0); }
        }

        .container {
            width: 100%;
            max-width: 500px;
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1),
                        0 5px 15px rgba(0, 0, 0, 0.05);
            position: relative;
            z-index: 1;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin: 20px;
        }

        .logo-container {
            text-align: center;
            margin-bottom: 30px;
            transform: scale(1);
            transition: transform 0.3s ease;
        }

        .logo-container:hover {
            transform: scale(1.05);
        }

        .logo-container img {
            width: 180px;
            height: auto;
            margin-bottom: 15px;
        }

        h2 {
            color: <?php echo $colors['primaryText']; ?>;
            text-align: center;
            font-weight: 600;
            font-size: 1.8rem;
            margin-bottom: 30px;
            position: relative;
        }

        h2::after {
            content: '';
            display: block;
            width: 50px;
            height: 3px;
            background: <?php echo $colors['button']; ?>;
            margin: 10px auto 0;
            border-radius: 2px;
        }

        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: <?php echo $colors['secondaryText']; ?>;
            font-weight: 500;
            font-size: 0.9rem;
            transition: color 0.3s ease;
        }

        input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #eef1f6;
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            box-sizing: border-box;
            transition: all 0.3s ease;
            background: #f8fafc;
        }

        input:focus {
            outline: none;
            border-color: <?php echo $colors['button']; ?>;
            background: white;
            box-shadow: 0 0 0 4px <?php echo adjustBrightness($colors['button'], 40); ?>33;
        }

        button {
            width: 100%;
            padding: 14px;
            background: <?php echo $colors['button']; ?>;
            color: <?php echo $colors['buttonText']; ?>;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-family: 'Poppins', sans-serif;
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px <?php echo adjustBrightness($colors['button'], -20); ?>66;
            background: <?php echo adjustBrightness($colors['button'], -10); ?>;
        }

        button:active {
            transform: translateY(0);
        }

        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 10px;
            text-align: center;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .success { 
            background: #d4edda; 
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error { 
            background: #f8d7da; 
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .links {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eef1f6;
        }

        .links a {
            color: <?php echo $colors['secondaryText']; ?>;
            text-decoration: none;
            margin: 0 0.8rem;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            position: relative;
        }

        .links a::after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: -2px;
            left: 0;
            background: <?php echo $colors['button']; ?>;
            transition: width 0.3s ease;
        }

        .links a:hover {
            color: <?php echo $colors['primaryText']; ?>;
        }

        .links a:hover::after {
            width: 100%;
        }

        .links span {
            color: #d1d5db;
        }

        @media (max-width: 640px) {
            body {
                padding: 20px;
            }

            .container {
                padding: 30px 20px;
            }

            h2 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo-container">
            <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="<?php echo htmlspecialchars($companyName); ?>">
        </div>
        
        <?php if (isset($message)): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($showResetForm): ?>
            <h2>Set New Password</h2>
            <form method="POST">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($_GET['token']); ?>">
                <div class="form-group">
                    <label>New Password</label>
                    <input type="password" name="password" required>
                </div>
                <button type="submit">
                    <i class="fas fa-key"></i>
                    Update Password
                </button>
            </form>
        <?php else: ?>
            <h2>Reset Password</h2>
            <form method="POST">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" required>
                </div>
                <button type="submit">
                    <i class="fas fa-envelope"></i>
                    Send Reset Link
                </button>
            </form>
        <?php endif; ?>

        <div class="links">
            <a href="index.php">Back to Login</a>
            <span>|</span>
            <a href="signup.php">Create Account</a>
        </div>
    </div>
</body>
</html>

<?php
// Helper function to adjust color brightness
function adjustBrightness($hex, $percentage) {
    $hex = ltrim($hex, '#');
    
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    
    $r = max(0, min(255, $r + ($r * ($percentage / 100))));
    $g = max(0, min(255, $g + ($g * ($percentage / 100))));
    $b = max(0, min(255, $b + ($b * ($percentage / 100))));
    
    return sprintf("#%02x%02x%02x", $r, $g, $b);
}
?> 