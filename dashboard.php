<?php
require_once 'session_start.php';

// Redirect to login if not logged in
if (!isLoggedIn()) {
    header("Location: login.php");
    exit();
}

// Get user ID from session
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// Include database connection
require_once 'db_connection.php';

// Load branding configuration
$brandingConfig = json_decode(file_get_contents('branding.json'), true);

// After loading branding configuration, add:
$navigationConfig = json_decode(file_get_contents('navigation.json'), true);

// Fetch user's full name and admin status from database
$stmt = $conn->prepare("SELECT full_name, is_admin, user_type FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$userName = $user ? $user['full_name'] : 'Guest';
$userType = $user ? $user['user_type'] : 'guest';
$isAdmin = $user && $user['is_admin'] ? true : false;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($brandingConfig['companyInfo']['name']); ?> - Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($brandingConfig['visualIdentity']['logoUrl']['favicon']); ?>">
    <link rel="apple-touch-icon" href="<?php echo htmlspecialchars($brandingConfig['visualIdentity']['logoUrl']['favicon']); ?>">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: <?php echo $brandingConfig['visualIdentity']['colors']['background']; ?>;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .page {
            display: none;
            animation: fadeIn 0.5s ease;
        }
        .page.active {
            display: block;
        }
        .sidebar-item {
            transition: all 0.3s ease;
            color: <?php echo $brandingConfig['visualIdentity']['colors']['primaryText']; ?>;
        }
        .sidebar-item:hover {
            background-color: <?php echo $brandingConfig['visualIdentity']['colors']['button']; ?>;
            color: <?php echo $brandingConfig['visualIdentity']['colors']['buttonText']; ?>;
        }
        .sidebar-item.active {
            background-color: <?php echo $brandingConfig['visualIdentity']['colors']['button']; ?>;
            color: <?php echo $brandingConfig['visualIdentity']['colors']['buttonText']; ?>;
        }
        iframe {
            width: 100%;
            height: calc(120vh - 80px);
            border: none;
        }
        .admin-dropdown {
            overflow: hidden;
            transition: max-height 0.3s ease-in-out;
            max-height: 0;
            background-color: rgba(0, 0, 0, 0.03);
            margin: 0 8px;
            border-radius: 8px;
        }

        .admin-dropdown.show {
            max-height: 300px; /* Adjust based on content */
        }

        .admin-dropdown .sidebar-item {
            padding-left: 2.5rem;
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .admin-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-right: 1rem;
        }

        .admin-header i.fa-chevron-down {
            transition: transform 0.3s ease;
        }

        .admin-header.active i.fa-chevron-down {
            transform: rotate(180deg);
        }
    </style>
</head>
<body class="bg-[<?php echo $brandingConfig['visualIdentity']['colors']['background']; ?>]">
    <div class="flex">
        <!-- Sidebar for desktop -->
        <div id="sidebar" class="hidden lg:block w-64 h-screen bg-white fixed left-0 top-0 transition-all duration-300 ease-in-out shadow-lg">
            <div class="p-5 text-center">
                <img class="w-32 mx-auto" src="<?php echo htmlspecialchars($brandingConfig['visualIdentity']['logoUrl']['primary']); ?>" 
                     alt="<?php echo htmlspecialchars($brandingConfig['companyInfo']['name']); ?>">
                <p class="text-sm mt-2" style="color: <?php echo $brandingConfig['visualIdentity']['colors']['secondaryText']; ?>">
                    <?php echo htmlspecialchars($brandingConfig['companyInfo']['tagline']); ?>
                </p>
            </div>
            <ul class="mt-6" id="nav-links">
                <!-- Navigation items will be dynamically added here -->
            </ul>
            <div class="absolute bottom-0 left-0 w-full p-4" style="background-color: <?php echo $brandingConfig['visualIdentity']['colors']['background']; ?>">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-semibold" id="username" style="color: <?php echo $brandingConfig['visualIdentity']['colors']['primaryText']; ?>">
                            <?php echo htmlspecialchars($userName); ?>
                        </p>
                        <p class="text-xs" id="plan-level" style="color: <?php echo $brandingConfig['visualIdentity']['colors']['secondaryText']; ?>">
                            <?php echo htmlspecialchars($userType); ?>
                        </p>
                    </div>
                    <button id="logout-btn" class="text-sm text-red-500 hover:text-red-700">Logout</button>
                </div>
            </div>
        </div>

        <!-- Main content -->
        <div class="flex-1 lg:ml-64">
            <!-- Hamburger menu for mobile -->
            <div class="lg:hidden">
                <button id="menu-toggle" class="text-blue-500 p-4">
                    <i class="fas fa-bars"></i>
                </button>
            </div>

            <!-- Content area -->
            <div class="p-6" id="content-area">
                <!-- Pages will be dynamically loaded here -->
            </div>
        </div>
    </div>

    <script>
        // Menu toggle functionality
        const menuToggle = document.getElementById('menu-toggle');
        const sidebar = document.getElementById('sidebar');
        
        menuToggle.addEventListener('click', () => {
            sidebar.classList.toggle('hidden');
        });

        // Navigation setup
        const navLinks = document.getElementById('nav-links');
        const contentArea = document.getElementById('content-area');

        function hasPermission(permissions) {
            const userType = <?php echo json_encode($userType); ?>;
            const isAdmin = <?php echo json_encode($isAdmin); ?>;
            
            // Admins always have access
            if (isAdmin) return true;
            
            // Check if user type matches any of the required permissions
            if (Array.isArray(permissions)) {
                return permissions.includes(userType);
            } else if (typeof permissions === 'string') {
                return permissions === userType;
            }
            
            return false;
        }

        function createNavigationSection(section) {
            // Skip section if user doesn't have permission
            if (!hasPermission(section.permissions)) {
                return null;
            }

            const sectionDiv = document.createElement('div');
            sectionDiv.className = 'nav-section mb-4';

            // Add section title if it exists
            if (section.title) {
                const title = document.createElement('div');
                title.className = 'text-xs font-semibold px-6 mb-2 uppercase tracking-wider';
                title.style.color = '<?php echo $brandingConfig['visualIdentity']['colors']['secondaryText']; ?>';
                title.textContent = section.title;
                sectionDiv.appendChild(title);
            }

            // Create dropdown container
            const itemsContainer = document.createElement('div');
            itemsContainer.className = 'nav-items';

            // Add items to section
            section.items.forEach(item => {
                const navItem = document.createElement('div');
                navItem.className = 'sidebar-item py-3 px-6 text-black cursor-pointer rounded-lg mx-2 mb-2';
                navItem.setAttribute('data-page', item.id);
                navItem.innerHTML = `<i class="fas fa-${item.icon} mr-2"></i>${item.title}`;
                
                navItem.addEventListener('click', () => {
                    document.querySelectorAll('.sidebar-item').forEach(i => i.classList.remove('active'));
                    navItem.classList.add('active');
                    loadPage(item.id, item.url);

                    if (window.innerWidth < 1024) {
                        sidebar.classList.add('hidden');
                    }
                });

                itemsContainer.appendChild(navItem);
            });

            sectionDiv.appendChild(itemsContainer);
            return sectionDiv;
        }

        // Load navigation configuration
        const navigationConfig = <?php echo json_encode($navigationConfig); ?>;
        
        // Load default navigation sections
        navigationConfig.default.sections.forEach(section => {
            const sectionElement = createNavigationSection(section);
            if (sectionElement) {
                navLinks.appendChild(sectionElement);
            }
        });

        <?php if ($isAdmin): ?>
        // Load admin navigation sections
        navigationConfig.admin.sections.forEach(section => {
            const sectionElement = createNavigationSection(section);
            if (sectionElement) {
                navLinks.appendChild(sectionElement);
            }
        });
        <?php endif; ?>

        // Function to load page content
        function loadPage(id, url) {
            contentArea.innerHTML = `<iframe src="${url}" id="${id}-frame"></iframe>`;
        }

        // Responsive sidebar
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 1024) {
                sidebar.classList.remove('hidden');
            } else {
                sidebar.classList.add('hidden');
            }
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (event) => {
            const isClickInsideSidebar = sidebar.contains(event.target);
            const isClickOnMenuToggle = menuToggle.contains(event.target);

            if (!isClickInsideSidebar && !isClickOnMenuToggle && window.innerWidth < 1024) {
                sidebar.classList.add('hidden');
            }
        });

        // Logout functionality
        const logoutBtn = document.getElementById('logout-btn');
        logoutBtn.addEventListener('click', () => {
            window.location.href = 'logout.php';
        });

        // Update user info in the sidebar
        document.getElementById('username').textContent = <?php echo json_encode($userName); ?>;
        document.getElementById('plan-level').textContent = <?php echo json_encode($userType); ?>;

        // Update document title dynamically
        document.title = <?php echo json_encode($brandingConfig['companyInfo']['name']); ?> + ' - Dashboard';

        // Update favicon dynamically
        const setFavicon = (url) => {
            const favicon = document.querySelector('link[rel="icon"]');
            const appleTouchIcon = document.querySelector('link[rel="apple-touch-icon"]');
            favicon.href = url;
            appleTouchIcon.href = url;
        };
        setFavicon(<?php echo json_encode($brandingConfig['visualIdentity']['logoUrl']['favicon']); ?>);
    </script>
</body>
</html>