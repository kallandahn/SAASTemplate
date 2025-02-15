<?php
require_once 'session_start.php';

// If user is already logged in, redirect to dashboard
if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

// Load branding data with error handling
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
    <title>Login | <?php echo htmlspecialchars($companyName); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700&display=swap" rel="stylesheet">
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

        .login-container {
            width: 100%;
            max-width: 450px;
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1),
                        0 5px 15px rgba(0, 0, 0, 0.05);
            position: relative;
            z-index: 1;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
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

        h1 {
            color: <?php echo $colors['primaryText']; ?>;
            text-align: center;
            font-weight: 600;
            font-size: 1.8rem;
            margin-bottom: 30px;
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
                background: linear-gradient(135deg, <?php echo $colors['background']; ?>, <?php echo adjustBrightness($colors['background'], -10); ?>);
            }

            .login-container {
                padding: 25px 20px;
                margin: 10px;
                width: calc(100% - 20px);
                max-width: none;
            }

            .logo-container img {
                width: 140px; /* Smaller logo for mobile */
            }

            h1 {
                font-size: 1.4rem;
                margin-bottom: 20px;
            }

            input {
                padding: 10px 12px; /* Slightly smaller input fields */
                font-size: 16px; /* Prevent zoom on iOS */
            }

            button {
                padding: 12px;
                font-size: 15px;
            }

            .links {
                margin-top: 20px;
                padding-top: 15px;
                display: flex;
                flex-direction: column;
                gap: 15px;
                align-items: center;
            }

            .links a {
                margin: 0;
                font-size: 1rem;
            }

            .links span {
                display: none; /* Hide the separator on mobile */
            }
        }

        /* Add this new media query for very small devices */
        @media (max-width: 350px) {
            .login-container {
                padding: 20px 15px;
            }

            h1 {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <main>
        <div class="login-container">
            <div class="logo-container">
                <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="<?php echo htmlspecialchars($companyName); ?>">
            </div>
            <h1>Login to <?php echo htmlspecialchars($companyName); ?></h1>
            <div id="error-message" class="error-message" style="display: none;"></div>
            <form id="loginForm">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit">Login</button>
            </form>
            <div class="links">
                <a href="signup.php">Create Account</a>
                <span>|</span>
                <a href="forgot_password.php">Forgot Password?</a>
            </div>
        </div>
    </main>

    <script>
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'login');
            
            fetch('onboarding/onboarding_handler.php', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                console.log('Response data:', data);
                const errorMessage = document.getElementById('error-message');
                if (data.success) {
                    window.location.href = 'dashboard.php';
                } else {
                    errorMessage.textContent = data.message || 'Login failed. Please check your credentials.';
                    errorMessage.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                const errorMessage = document.getElementById('error-message');
                errorMessage.textContent = 'An error occurred. Please try again.';
                errorMessage.style.display = 'block';
            });
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