<?php
// Add these at the very top of the file
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'session_start.php';
require_once 'onboarding/config.php';
require_once 'db_connection.php';  // Add this line
require_once 'first_config.php';


// Call the function to ensure table exists
if (!ensureUsersTableExists()) {
    die("Failed to initialize database. Please contact administrator.");
}

// If user is already logged in, redirect to dashboard
if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

// Load branding data from database with error handling
$brandingData = [];
$brandingFile = 'branding.json';
if (file_exists($brandingFile)) {
    $brandingData = json_decode(file_get_contents($brandingFile), true);
} else {
    die("Branding configuration file not found!");
}

// Extract commonly used values
$companyName = $brandingData['companyInfo']['name'] ?? 'Company Name';
$logoUrl = $brandingData['visualIdentity']['logoUrl']['primary'] ?? 'default-logo.png';
$colors = $brandingData['visualIdentity']['colors'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up | <?php echo htmlspecialchars($companyName); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            background: linear-gradient(135deg, <?php echo $colors['background']; ?>, <?php echo adjustBrightness($colors['background'], -15); ?>);
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            position: relative;
            overflow-y: auto;
            box-sizing: border-box;
        }

        .signup-container {
            width: 100%;
            max-width: 400px;
            background: rgba(255, 255, 255, 0.95);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            position: relative;
            z-index: 1;
            margin: 0 auto;
            margin-top: 20px;
        }

        .logo-container {
            text-align: center;
            margin-bottom: 20px;
            transform: scale(1);
            transition: transform 0.3s ease;
        }

        .logo-container:hover {
            transform: scale(1.05);
        }

        .logo-container img {
            max-width: 150px;
            width: 100%;
            height: auto;
            margin-bottom: 10px;
        }

        h1 {
            color: <?php echo $colors['primaryText']; ?>;
            text-align: center;
            font-weight: 600;
            font-size: 1.8rem;
            margin-bottom: 20px;
            position: relative;
        }

        h1::after {
            content: '';
            display: block;
            width: 50px;
            height: 3px;
            background: <?php echo $colors['button']; ?>;
            margin: 10px auto 0;
            border-radius: 2px;
        }

        .form-group {
            margin-bottom: 20px;
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

        .error-message {
            color: #dc2626;
            font-size: 14px;
            margin-top: 5px;
            padding: 10px;
            border-radius: 8px;
            background: #fee2e2;
            border: 1px solid #fecaca;
            display: none;
        }

        @media (max-width: 640px) {
            body {
                padding: 15px;
            }

            .signup-container {
                padding: 20px;
                margin: 0 auto;
            }

            .logo-container img {
                max-width: 120px;
            }

            h1 {
                font-size: 1.5rem;
                margin-bottom: 25px;
            }

            .form-group {
                margin-bottom: 18px;
            }

            label {
                font-size: 0.95rem;
                margin-bottom: 6px;
            }

            input {
                padding: 14px;
                font-size: 16px;
                border-radius: 12px;
                background: rgba(255, 255, 255, 0.9);
            }

            button {
                padding: 16px;
                font-size: 16px;
                border-radius: 12px;
                margin-top: 10px;
            }

            .links {
                margin-top: 25px;
                padding-top: 20px;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 15px;
                text-align: center;
            }

            .links a {
                margin: 0;
                font-size: 0.95rem;
                padding: 8px;
                border-radius: 8px;
                background: rgba(255, 255, 255, 0.5);
                backdrop-filter: blur(5px);
            }

            .links span {
                display: none;
            }
        }

        /* Improved styles for very small devices */
        @media (max-width: 350px) {
            .signup-container {
                padding: 20px;
                margin: 5px;
            }

            h1 {
                font-size: 1.3rem;
            }

            input {
                padding: 12px;
            }

            .links {
                grid-template-columns: 1fr;
            }
        }

        /* Add support for tall phones */
        @media (min-height: 800px) {
            .signup-container {
                margin: 40px auto;
            }
        }

        /* Add support for landscape mode */
        @media (max-width: 900px) and (orientation: landscape) {
            body {
                padding: 20px;
            }

            .signup-container {
                margin: 10px auto;
                max-width: 600px;
            }

            .form-group {
                margin-bottom: 15px;
            }
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
    </style>
</head>
<body>
    <div class="signup-container">
        <div class="logo-container">
            <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="<?php echo htmlspecialchars($companyName); ?>">
        </div>
        <h1>Create Account</h1>
        <form id="signupForm" action="/process_signup.php" method="POST" novalidate>
            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" required>
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required>
            </div>
            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" required>
            </div>
            <input type="hidden" id="user_type" name="user_type" value="user">
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
                <div class="error-message" id="passwordError">Passwords do not match</div>
            </div>
            <button type="submit">
                <i class="fas fa-user-plus"></i>
                Create Account
            </button>
        </form>
        <div class="links">
            <a href="login.php">Login</a>
            <span>|</span>
            <a href="forgot_password.php">Forgot Password?</a>
        </div>
    </div>

    <script>
        const form = document.getElementById('signupForm');
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        const passwordError = document.getElementById('passwordError');

        form.addEventListener('submit', function(e) {
            console.log('Form data:', new FormData(form));
            
            if (password.value !== confirmPassword.value) {
                e.preventDefault();
                passwordError.style.display = 'block';
            } else {
                passwordError.style.display = 'none';
            }
        });
    </script>
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