<?php
require_once 'session_start.php';

// Redirect to login if not logged in
if (!isLoggedIn()) {
    header("Location: login.php");
    exit();
}

// Get user ID from session and check admin status
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// Include database connection
require_once 'db_connection.php';

// Verify admin status
$stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Redirect non-admin users
if (!$user || !$user['is_admin']) {
    header("Location: dashboard.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jsonData = file_get_contents('php://input');
    
    // Validate JSON data
    $data = json_decode($jsonData, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        // Create backup of existing file
        if (file_exists('branding.json')) {
            copy('branding.json', 'branding.backup.json');
        }
        
        // Save the new data
        if (file_put_contents('branding.json', json_encode($data, JSON_PRETTY_PRINT))) {
            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Changes saved successfully']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to save changes']);
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branding Manager</title>
    <!-- Add dynamic favicon -->
    <link rel="icon" type="image/png" id="pageFavicon">
    <style>
        :root {
            /* Change default colors to neutral values */
            --background-color: #ffffff;
            --primary-text: #333333;
            --secondary-text: #666666;
            --button-color: #e0e0e0;  /* Changed from #FDD600 to a neutral gray */
            --button-text: #333333;   /* Changed from #ffffff to dark text */
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: var(--background-color);
            color: var(--primary-text);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            text-align: center;
            margin-bottom: 40px;
        }

        .header h1 {
            color: var(--primary-text);
            font-size: 2.5em;
            margin-bottom: 10px;
        }

        .header p {
            color: var(--secondary-text);
        }

        .section-card {
            background: #ffffff !important; /* Always white background */
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            animation: fadeIn 0.3s ease-out;
        }

        .section-title {
            font-size: 1.5em;
            margin-bottom: 25px;
            color: var(--primary-text);
            border-bottom: 2px solid var(--button-color);
            padding-bottom: 12px;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--primary-text);
            font-size: 0.95rem;
            letter-spacing: 0.3px;
        }

        input, textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s ease;
            box-sizing: border-box;
            background-color: #f8f9fa;
            color: var(--primary-text);
        }

        input:focus, textarea:focus {
            outline: none;
            border-color: var(--button-color);
            box-shadow: 0 0 0 3px rgba(253, 214, 0, 0.1);
            background-color: #ffffff;
        }

        input:hover, textarea:hover {
            border-color: #d0d0d0;
            background-color: #ffffff;
        }

        .color-input {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .color-input input[type="color"] {
            -webkit-appearance: none;
            width: 50px;
            height: 50px;
            padding: 0;
            border: none;
            border-radius: 8px;
            cursor: pointer;
        }

        .color-input input[type="color"]::-webkit-color-swatch-wrapper {
            padding: 0;
        }

        .color-input input[type="color"]::-webkit-color-swatch {
            border: none;
            border-radius: 8px;
        }

        .color-input input[type="text"] {
            max-width: 120px;
            text-transform: uppercase;
            font-family: monospace;
            font-size: 14px;
            text-align: center;
            letter-spacing: 1px;
        }

        /* Style improvements for specific input types */
        input[type="url"], 
        input[type="email"] {
            font-family: monospace;
            font-size: 13px;
        }

        input[type="number"] {
            max-width: 200px;
        }

        textarea {
            min-height: 80px;
            resize: vertical;
            line-height: 1.5;
        }

        /* Optional: Add icons to URL inputs */
        .form-group.url-input {
            position: relative;
        }

        .form-group.url-input input {
            padding-left: 40px;
        }

        .form-group.url-input::before {
            content: "🔗";
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 16px;
            opacity: 0.5;
        }

        .buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }

        button {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .save-btn {
            /* Add transition for smooth color changes */
            background-color: var(--button-color);
            color: var(--button-text);
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        .save-btn:hover {
            background-color: var(--button-color);
            filter: brightness(0.9);
        }

        .reset-btn {
            background-color: #f5f5f5;
            color: var(--primary-text);
        }

        .reset-btn:hover {
            background-color: #e0e0e0;
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
        }

        /* Add subtle animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logo-preview-container {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            margin-bottom: 20px;
            width: 100%;
        }

        .logo-preview {
            max-width: 200px;
            max-height: 100px;
            margin: 10px 0;
            object-fit: contain;
            display: block;
        }

        .favicon-preview {
            width: 32px;
            height: 32px;
            object-fit: contain;
        }

        .preview-box {
            padding: 15px;
            border: 1px dashed #e0e0e0;
            border-radius: 8px;
            margin-top: 8px;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .preview-label {
            font-size: 0.85rem;
            color: var(--secondary-text);
            margin-bottom: 4px;
        }

        .image-preview-error {
            color: #dc3545;
            font-size: 0.85rem;
            margin-top: 4px;
        }

        /* Tab Navigation Styles */
        .tabs-container {
            margin-top: 40px;
        }

        .tab-nav {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .tab-button {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: #f8f9fa;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--secondary-text);
            transition: all 0.2s ease;
        }

        .tab-button:hover {
            background: #e9ecef;
        }

        .tab-button.active {
            background: var(--button-color);
            color: var(--button-text);
        }

        .tab-button i {
            font-size: 1.2em;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease-out;
        }

        /* Icons for tabs */
        .tab-button::before {
            font-size: 1.2em;
            opacity: 0.8;
        }

        .tab-button[data-tab="company"]::before {
            content: "🏢";
        }

        .tab-button[data-tab="visual"]::before {
            content: "🎨";
        }

        .tab-button[data-tab="social"]::before {
            content: "🌐";
        }

        .tab-button[data-tab="contact"]::before {
            content: "📞";
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <!-- Removed logo container -->
            <h1>Branding Manager</h1>
            <p>Update your brand settings with ease</p>
        </div>

        <form id="brandingForm">
            <div class="tabs-container">
                <!-- Tab Navigation -->
                <div class="tab-nav">
                    <button type="button" class="tab-button active" data-tab="company">Company Info</button>
                    <button type="button" class="tab-button" data-tab="visual">Visual Identity</button>
                    <button type="button" class="tab-button" data-tab="social">Social Media</button>
                    <button type="button" class="tab-button" data-tab="contact">Contact Info</button>
                </div>

                <!-- Company Info Tab -->
                <div class="tab-content section-card active" data-tab="company">
                    <h2 class="section-title">Company Information</h2>
                    <div class="form-group">
                        <label for="companyName">Company Name</label>
                        <input type="text" id="companyName" name="companyInfo.name">
                    </div>
                    <div class="form-group">
                        <label for="tagline">Tagline</label>
                        <input type="text" id="tagline" name="companyInfo.tagline">
                    </div>
                    <div class="form-group">
                        <label for="foundedYear">Founded Year</label>
                        <input type="number" id="foundedYear" name="companyInfo.foundedYear">
                    </div>
                    <div class="form-group">
                        <label for="location">Headquarters Location</label>
                        <input type="text" id="location" name="companyInfo.headquartersLocation">
                    </div>
                    <div class="form-group url-input">
                        <label for="website">Website URL</label>
                        <input type="url" id="website" name="companyInfo.websiteUrl">
                    </div>
                </div>

                <!-- Visual Identity Tab -->
                <div class="tab-content section-card" data-tab="visual">
                    <h2 class="section-title">Visual Identity</h2>
                    <div class="form-group">
                        <label for="primaryLogo">Primary Logo URL</label>
                        <input type="text" id="primaryLogo" name="visualIdentity.logoUrl.primary">
                        <div class="preview-box">
                            <div>
                                <div class="preview-label">Logo Preview:</div>
                                <img src="" alt="Logo Preview" class="logo-preview" id="logoPreview">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="favicon">Favicon URL</label>
                        <input type="text" id="favicon" name="visualIdentity.logoUrl.favicon">
                        <div class="preview-box">
                            <div>
                                <div class="preview-label">Favicon Preview:</div>
                                <img src="" alt="Favicon Preview" class="favicon-preview" id="faviconPreview">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="socialShare">Social Share Image URL</label>
                        <input type="text" id="socialShare" name="visualIdentity.logoUrl.socialShare">
                    </div>
                    
                    <h3>Colors</h3>
                    <div class="form-group">
                        <label for="backgroundColor">Background Color</label>
                        <div class="color-input">
                            <input type="color" id="backgroundColor" name="visualIdentity.colors.background">
                            <input type="text" id="backgroundColorText" placeholder="#ffffff">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="primaryText">Primary Text Color</label>
                        <div class="color-input">
                            <input type="color" id="primaryText" name="visualIdentity.colors.primaryText">
                            <input type="text" id="primaryTextText" placeholder="#333333">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="secondaryText">Secondary Text Color</label>
                        <div class="color-input">
                            <input type="color" id="secondaryText" name="visualIdentity.colors.secondaryText">
                            <input type="text" id="secondaryTextText" placeholder="#666666">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="buttonColor">Button Color</label>
                        <div class="color-input">
                            <input type="color" id="buttonColor" name="visualIdentity.colors.button">
                            <input type="text" id="buttonColorText" placeholder="#FDD600">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="buttonText">Button Text Color</label>
                        <div class="color-input">
                            <input type="color" id="buttonText" name="visualIdentity.colors.buttonText">
                            <input type="text" id="buttonTextText" placeholder="#ffffff">
                        </div>
                    </div>
                </div>

                <!-- Social Media Tab -->
                <div class="tab-content section-card" data-tab="social">
                    <h2 class="section-title">Social Media</h2>
                    <div class="form-group">
                        <label for="facebook">Facebook URL</label>
                        <input type="url" id="facebook" name="socialMedia.facebook">
                    </div>
                    <div class="form-group">
                        <label for="twitter">Twitter URL</label>
                        <input type="url" id="twitter" name="socialMedia.twitter">
                    </div>
                    <div class="form-group">
                        <label for="instagram">Instagram URL</label>
                        <input type="url" id="instagram" name="socialMedia.instagram">
                    </div>
                    <div class="form-group">
                        <label for="linkedin">LinkedIn URL</label>
                        <input type="url" id="linkedin" name="socialMedia.linkedin">
                    </div>
                    <div class="form-group">
                        <label for="youtube">YouTube URL</label>
                        <input type="url" id="youtube" name="socialMedia.youtube">
                    </div>
                </div>

                <!-- Contact Info Tab -->
                <div class="tab-content section-card" data-tab="contact">
                    <h2 class="section-title">Contact Information</h2>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="contactInformation.email">
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="tel" id="phone" name="contactInformation.phone">
                    </div>
                    <div class="form-group">
                        <label for="address">Address</label>
                        <textarea id="address" name="contactInformation.address" rows="2"></textarea>
                    </div>
                </div>
            </div>

            <div class="buttons">
                <button type="button" class="reset-btn" onclick="resetForm()">Reset</button>
                <button type="submit" class="save-btn">Save Changes</button>
            </div>
        </form>
    </div>

    <script>
        // Load initial data
        fetch('branding.json')
            .then(response => response.json())
            .then(data => {
                populateForm(data);
            })
            .catch(error => {
                console.error('Error loading branding data:', error);
                alert('Error loading branding data. Please try again.');
            });

        function populateForm(data) {
            // Recursively set form values
            function setFormValues(obj, prefix = '') {
                for (const [key, value] of Object.entries(obj)) {
                    if (typeof value === 'object' && value !== null) {
                        setFormValues(value, prefix + key + '.');
                    } else {
                        const input = document.querySelector(`[name="${prefix}${key}"]`);
                        if (input) {
                            input.value = value;
                            // Trigger change event for logo/favicon inputs
                            if (input.id === 'primaryLogo' || input.id === 'favicon') {
                                input.dispatchEvent(new Event('change'));
                            }
                        }
                    }
                }
            }

            setFormValues(data);
            updateImagePreviews(data);
            
            // Update page colors
            if (data.visualIdentity && data.visualIdentity.colors) {
                updatePageColors(data.visualIdentity.colors);
            }
        }

        function resetForm() {
            if (confirm('Are you sure you want to reset all changes?')) {
                fetch('branding.json')
                    .then(response => response.json())
                    .then(data => {
                        populateForm(data);
                    });
            }
        }

        document.getElementById('brandingForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            // Show loading state
            const saveBtn = document.querySelector('.save-btn');
            const originalText = saveBtn.textContent;
            saveBtn.textContent = 'Saving...';
            saveBtn.disabled = true;

            // Collect form data
            const formData = new FormData(this);
            const brandingData = {};

            formData.forEach((value, key) => {
                const keys = key.split('.');
                let current = brandingData;
                
                keys.forEach((k, i) => {
                    if (i === keys.length - 1) {
                        current[k] = value;
                    } else {
                        current[k] = current[k] || {};
                        current = current[k];
                    }
                });
            });

            try {
                // Save to file using PHP
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(brandingData)
                });

                const result = await response.json();

                if (result.success) {
                    alert('Changes saved successfully!');
                } else {
                    throw new Error(result.message || 'Failed to save changes');
                }
            } catch (error) {
                console.error('Error saving data:', error);
                alert(`Error saving changes: ${error.message}`);
            } finally {
                // Restore button state
                saveBtn.textContent = originalText;
                saveBtn.disabled = false;
            }
        });

        // Add this function to update the page colors
        function updatePageColors(colors) {
            const root = document.documentElement;
            root.style.setProperty('--background-color', colors.background || '#ffffff');
            root.style.setProperty('--primary-text', colors.primaryText || '#333333');
            root.style.setProperty('--secondary-text', colors.secondaryText || '#666666');
            root.style.setProperty('--button-color', colors.button || '#e0e0e0');
            root.style.setProperty('--button-text', colors.buttonText || '#333333');
        }

        // Add color input event listeners
        document.querySelectorAll('input[type="color"]').forEach(input => {
            const textInput = input.parentElement.querySelector('input[type="text"]');
            
            input.addEventListener('input', () => {
                textInput.value = input.value.toUpperCase();
                
                // Update page colors in real-time
                const colors = {
                    background: document.querySelector('[name="visualIdentity.colors.background"]').value,
                    primaryText: document.querySelector('[name="visualIdentity.colors.primaryText"]').value,
                    secondaryText: document.querySelector('[name="visualIdentity.colors.secondaryText"]').value,
                    button: document.querySelector('[name="visualIdentity.colors.button"]').value,
                    buttonText: document.querySelector('[name="visualIdentity.colors.buttonText"]').value
                };
                updatePageColors(colors);
            });

            textInput.addEventListener('input', () => {
                if (/^#[0-9A-F]{6}$/i.test(textInput.value)) {
                    input.value = textInput.value;
                    // Trigger the color input event to update the page
                    input.dispatchEvent(new Event('input'));
                }
            });
        });

        // Add image preview functionality
        function updateImagePreviews(data) {
            const logoUrls = [
                { id: 'headerLogo', url: data.visualIdentity?.logoUrl?.primary },
                { id: 'logoPreview', url: data.visualIdentity?.logoUrl?.primary },
                { id: 'faviconPreview', url: data.visualIdentity?.logoUrl?.favicon }
            ];

            // Update favicon
            const favicon = document.getElementById('pageFavicon');
            if (data.visualIdentity?.logoUrl?.favicon) {
                favicon.href = data.visualIdentity.logoUrl.favicon;
            }

            // Update all image previews
            logoUrls.forEach(({id, url}) => {
                const imgElement = document.getElementById(id);
                if (imgElement && url) {
                    imgElement.src = url;
                    imgElement.onerror = function() {
                        this.src = 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100%" height="100%" fill="%23f8f9fa"/><text x="50%" y="50%" font-family="Arial" font-size="14" fill="%23999" text-anchor="middle" dy=".3em">Image not found</text></svg>';
                    };
                }
            });
        }

        // Add event listeners for logo/favicon URL changes
        ['primaryLogo', 'favicon'].forEach(id => {
            const input = document.getElementById(id);
            input.addEventListener('change', () => {
                const data = {
                    visualIdentity: {
                        logoUrl: {
                            primary: document.getElementById('primaryLogo').value,
                            favicon: document.getElementById('favicon').value
                        }
                    }
                };
                updateImagePreviews(data);
            });
        });

        // Add tab functionality
        document.querySelectorAll('.tab-button').forEach(button => {
            button.addEventListener('click', () => {
                // Remove active class from all tabs
                document.querySelectorAll('.tab-button').forEach(btn => {
                    btn.classList.remove('active');
                });
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });

                // Add active class to clicked tab
                button.classList.add('active');
                document.querySelector(`.tab-content[data-tab="${button.dataset.tab}"]`).classList.add('active');
            });
        });
    </script>
</body>
</html> 