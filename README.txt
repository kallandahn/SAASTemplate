# Installation Guide

## Prerequisites
- PHP 7.4 or higher
- MySQL/MariaDB database
- Web server (Apache/Nginx)
(standard CPANEL hosting meets all requirements)
For local development, you can use XAMPP or WAMP.

## Configuration Steps

### 1. Database Configuration
Navigate to `onboarding/config.php` and update the following database credentials:
```php
$db_host = "localhost";     // Your database host
$db_name = "your_db_name";  // Your database name
$db_user = "your_username"; // Your database username
$db_pass = "your_password"; // Your database password
```

### 2. Email Configuration 
Navigate to `webmailconfig.php` and configure your email settings:
```php
$mail_host = "smtp.example.com";     // SMTP server
$mail_username = "your@email.com";   // SMTP username
$mail_password = "your_password";    // SMTP password
$mail_port = 587;                    // SMTP port (usually 587 for TLS)
$mail_encryption = "tls";            // Encryption type (tls/ssl)
```

### 3. First Time Setup
1. Upload all files to your web server or local server
2. Access the application through your web browser visiting the signup.php page and then proceeding to the login.php page.
3. The system will automatically:
   - Create required database tables
   - Create a default admin user:
     - Email: admin@test.com
     - Password: 123456

### 4. Security Recommendations
- Change the default admin password immediately after first login
- Configure SSL/TLS for secure communication
- Update file permissions appropriately
- Keep PHP and all dependencies up to date

## Support
If you encounter any issues during installation, please check:
1. PHP error logs
2. Database connection settings
3. Email configuration settings
4. File permissions

## License
MIT License with Additional Restriction

Copyright (c) 2025 MAITeam

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Template"), to use
the Template to create software applications subject to the following conditions:

1. The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Template.

2. The Template may not be resold, redistributed, or offered as a template
to third parties.

3. You may use this Template to create and distribute software applications
without restriction.

THE TEMPLATE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE TEMPLATE OR THE USE OR OTHER DEALINGS IN THE
TEMPLATE.

