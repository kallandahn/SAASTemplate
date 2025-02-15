<?php
// Include database configuration
require_once 'onboarding/config.php';

// Connect to database
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Add CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $table = $_POST['table'] ?? '';
    $id = $_POST['id'] ?? '';
    
    switch ($action) {
        case 'update':
            $updates = [];
            $values = [];
            $types = '';
            
            foreach ($_POST as $key => $value) {
                if (!in_array($key, ['action', 'table', 'id'])) {
                    $updates[] = "`$key` = ?";
                    $values[] = $value;
                    $types .= 's'; // Assuming string for all fields, adjust if needed
                }
            }
            
            $values[] = $id;
            $types .= 'i';
            
            $sql = "UPDATE `$table` SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$values);
            $stmt->execute();
            
            header('Location: ' . $_SERVER['PHP_SELF'] . '?success=Record updated successfully');
            exit;
            break;
            
        case 'delete':
            $stmt = $conn->prepare("DELETE FROM `$table` WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            
            header('Location: ' . $_SERVER['PHP_SELF'] . '?success=Record deleted successfully');
            exit;
            break;
            
        case 'create':
            $columns = [];
            $placeholders = [];
            $values = [];
            $types = '';
            
            foreach ($_POST as $key => $value) {
                if (!in_array($key, ['action', 'table'])) {
                    $columns[] = "`$key`";
                    $placeholders[] = "?";
                    // Hash password if this is the users table and the field is password
                    if ($table === 'users' && $key === 'password') {
                        $values[] = password_hash($value, PASSWORD_DEFAULT);
                    } else {
                        $values[] = $value;
                    }
                    $types .= 's';
                }
            }
            
            $sql = "INSERT INTO `$table` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
            try {
                $stmt = $conn->prepare($sql);
                if ($stmt === false) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=Failed to prepare statement: ' . $conn->error);
                    exit;
                }
                
                $stmt->bind_param($types, ...$values);
                $result = $stmt->execute();
                
                if (!$result) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?error=Failed to create record: ' . $stmt->error);
                    exit;
                }
                
                header('Location: ' . $_SERVER['PHP_SELF'] . '?success=Record created successfully');
                exit;
            } catch (Exception $e) {
                header('Location: ' . $_SERVER['PHP_SELF'] . '?error=Error: ' . urlencode($e->getMessage()));
                exit;
            }
            break;
    }
}

// Get all tables
$tables = [];
$result = $conn->query("SHOW TABLES");
while ($row = $result->fetch_row()) {
    $tables[] = $row[0];
}

// Get table structure and data for the selected table
$selectedTable = $_GET['table'] ?? $tables[0];
$columns = [];
$result = $conn->query("SHOW COLUMNS FROM `$selectedTable`");
while ($row = $result->fetch_assoc()) {
    $columns[] = $row;
}

// Get table data with pagination
$page = $_GET['page'] ?? 1;
$perPage = 10;
$start = ($page - 1) * $perPage;

$totalRows = $conn->query("SELECT COUNT(*) as count FROM `$selectedTable`")->fetch_assoc()['count'];
$totalPages = ceil($totalRows / $perPage);

$data = [];
$result = $conn->query("SELECT * FROM `$selectedTable` LIMIT $start, $perPage");
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

// Add this new endpoint handling for DataTables server-side processing
if (isset($_GET['action']) && $_GET['action'] === 'search') {
    $search = $_GET['search']['value'] ?? '';
    $start = $_GET['start'] ?? 0;
    $length = $_GET['length'] ?? 10;
    $draw = $_GET['draw'] ?? 1;
    
    // Build search conditions for all columns
    $searchConditions = [];
    $params = [];
    $types = '';
    
    if (!empty($search)) {
        foreach ($columns as $column) {
            $searchConditions[] = "`" . $column['Field'] . "` LIKE ?";
            $params[] = "%$search%";
            $types .= 's';
        }
    }
    
    // Get total records (before filtering)
    $totalQuery = "SELECT COUNT(*) as count FROM `$selectedTable`";
    $totalRecords = $conn->query($totalQuery)->fetch_assoc()['count'];
    
    // Create the filtered query
    $sql = "SELECT * FROM `$selectedTable`";
    if (!empty($searchConditions)) {
        $sql .= " WHERE " . implode(" OR ", $searchConditions);
    }
    
    // Add sorting
    if (isset($_GET['order'])) {
        $orderColumn = $_GET['order'][0]['column'] ?? 0;
        $orderDir = $_GET['order'][0]['dir'] ?? 'asc';
        $columnName = $columns[$orderColumn]['Field'];
        $sql .= " ORDER BY `$columnName` " . ($orderDir === 'asc' ? 'ASC' : 'DESC');
    }
    
    // Get total filtered count before adding LIMIT
    $countSql = "SELECT COUNT(*) as count FROM ($sql) as filtered_table";
    $stmt = $conn->prepare($countSql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $filteredRecords = $stmt->get_result()->fetch_assoc()['count'];
    
    // Add pagination
    $sql .= " LIMIT ?, ?";
    $params[] = (int)$start;
    $params[] = (int)$length;
    $types .= 'ii';
    
    // Prepare and execute the query
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Failed to prepare statement: ' . $conn->error,
            'sql' => $sql
        ]);
        exit;
    }
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode([
        'draw' => (int)$draw,
        'recordsTotal' => (int)$totalRecords,
        'recordsFiltered' => (int)$filteredRecords,
        'data' => $data
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Database Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.19/dist/sweetalert2.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', sans-serif;
        }
        .table-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
            margin: 20px 0;
            overflow-x: auto;
            width: 100%;
        }
        .nav-tabs .nav-link {
            color: #495057;
            border: none;
            border-bottom: 2px solid transparent;
        }
        .nav-tabs .nav-link.active {
            color: #007bff;
            border-bottom: 2px solid #007bff;
        }
        .edit-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
        }
        .delete-btn {
            background: #dc3545;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
        }
        .modal-header {
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .pagination {
            margin-top: 20px;
        }
        .table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        .success-message {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1050;
        }
        .dataTables_wrapper {
            width: 100%;
            overflow-x: auto;
        }
        .table {
            width: 100% !important;
            margin-bottom: 0;
        }
        @media screen and (max-width: 768px) {
            .table-container {
                max-width: 100vw;
                margin: 20px -15px;
                padding: 20px 15px;
            }
        }
    </style>
</head>
<body class="bg-light">
    <div class="container-fluid py-4">
        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success success-message" role="alert">
            <?php echo htmlspecialchars($_GET['success']); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger success-message" role="alert">
            <?php echo htmlspecialchars($_GET['error']); ?>
        </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-12">
                <h2 class="mb-4">Database Manager</h2>
                
                <!-- Table Navigation -->
                <ul class="nav nav-tabs mb-4">
                    <?php foreach ($tables as $table): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $selectedTable === $table ? 'active' : ''; ?>"
                           href="?table=<?php echo urlencode($table); ?>">
                            <?php echo htmlspecialchars($table); ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>

                <!-- Add New Record Button -->
                <button class="btn btn-primary mb-3" onclick="showCreateModal()">
                    Add New Record
                </button>

                <!-- Add this button just before the table-container div -->
                <button class="btn btn-info mb-3 ms-2" onclick="showAllApiCalls()">
                    View API Documentation
                </button>

                <!-- Table Container -->
                <div class="table-container">
                    <table class="table table-hover" id="dataTable">
                        <thead>
                            <tr>
                                <?php foreach ($columns as $column): ?>
                                <th><?php echo htmlspecialchars($column['Field']); ?></th>
                                <?php endforeach; ?>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data as $row): ?>
                            <tr>
                                <?php foreach ($columns as $column): ?>
                                <td><?php echo htmlspecialchars($row[$column['Field']] ?? ''); ?></td>
                                <?php endforeach; ?>
                                <td>
                                    <button class="edit-btn" onclick="showEditModal(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                        Edit
                                    </button>
                                    <button class="delete-btn" onclick="confirmDelete('<?php echo $selectedTable; ?>', <?php echo $row['id']; ?>)">
                                        Delete
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <nav>
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                                <a class="page-link" href="?table=<?php echo urlencode($selectedTable); ?>&page=<?php echo $i; ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editForm" method="POST">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="table" value="<?php echo htmlspecialchars($selectedTable); ?>">
                        <input type="hidden" name="id" id="editId">
                        <?php foreach ($columns as $column): ?>
                        <?php if ($column['Field'] !== 'id'): ?>
                        <div class="form-group">
                            <label><?php echo htmlspecialchars($column['Field']); ?></label>
                            <input type="text" class="form-control" name="<?php echo htmlspecialchars($column['Field']); ?>"
                                   id="edit_<?php echo htmlspecialchars($column['Field']); ?>">
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </form>
                    <button class="btn btn-info mt-2" onclick="showApiCall('update')">Show API Call</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Modal -->
    <div class="modal fade" id="createModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="createForm" method="POST">
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="table" value="<?php echo htmlspecialchars($selectedTable); ?>">
                        <?php foreach ($columns as $column): ?>
                        <?php if ($column['Field'] !== 'id'): ?>
                        <div class="form-group">
                            <label><?php echo htmlspecialchars($column['Field']); ?></label>
                            <input type="text" class="form-control" name="<?php echo htmlspecialchars($column['Field']); ?>">
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-primary">Create Record</button>
                    </form>
                    <button class="btn btn-info mt-2" onclick="showApiCall('create')">Show API Call</button>
                </div>
            </div>
        </div>
    </div>

    <!-- API Call Modal -->
    <div class="modal fade" id="apiCallModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">API Call Reference</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <h6>Endpoint:</h6>
                        <code id="apiEndpoint"></code>
                    </div>
                    <div class="mb-3">
                        <h6>Method:</h6>
                        <code id="apiMethod"></code>
                    </div>
                    <div class="mb-3">
                        <h6>Parameters:</h6>
                        <pre><code id="apiParams"></code></pre>
                    </div>
                    <div class="mb-3">
                        <h6>cURL Example:</h6>
                        <pre><code id="apiCurl"></code></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add this new modal for comprehensive API documentation -->
    <div class="modal fade" id="apiDocsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">API Documentation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <nav>
                        <div class="nav nav-tabs" id="api-tab" role="tablist">
                            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#create-tab">Create</button>
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#read-tab">Read</button>
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#update-tab">Update</button>
                            <button class="nav-link" data-bs-toggle="tab" data-bs-target="#delete-tab">Delete</button>
                        </div>
                    </nav>
                    <div class="tab-content pt-3" id="api-tabContent">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Update the script section with jQuery included first -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.0.19/dist/sweetalert2.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <script>
        // Initialize DataTables
        $(document).ready(function() {
            $('#dataTable').DataTable({
                "processing": true,
                "serverSide": true,
                "ajax": {
                    "url": "?action=search&table=<?php echo urlencode($selectedTable); ?>",
                    "type": "GET"
                },
                "columns": [
                    <?php foreach ($columns as $column): ?>
                    { "data": "<?php echo $column['Field']; ?>" },
                    <?php endforeach; ?>
                    {
                        "data": null,
                        "orderable": false,
                        "render": function(data, type, row) {
                            return `
                                <button class="edit-btn" onclick='showEditModal(${JSON.stringify(row)})'>
                                    Edit
                                </button>
                                <button class="delete-btn" onclick='confirmDelete("<?php echo $selectedTable; ?>", ${row.id})'>
                                    Delete
                                </button>
                            `;
                        }
                    }
                ],
                "pageLength": 10,
                "ordering": true,
                "searching": true,
                "responsive": true,
                "scrollX": true,
                "autoWidth": false
            });
        });

        // Show edit modal
        function showEditModal(data) {
            document.getElementById('editId').value = data.id;
            <?php foreach ($columns as $column): ?>
            <?php if ($column['Field'] !== 'id'): ?>
            document.getElementById('edit_<?php echo $column['Field']; ?>').value = data.<?php echo $column['Field']; ?>;
            <?php endif; ?>
            <?php endforeach; ?>
            new bootstrap.Modal(document.getElementById('editModal')).show();
        }

        // Show create modal
        function showCreateModal() {
            new bootstrap.Modal(document.getElementById('createModal')).show();
        }

        // Confirm delete
        function confirmDelete(table, id) {
            Swal.fire({
                title: 'Are you sure?',
                text: "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="table" value="${table}">
                        <input type="hidden" name="id" value="${id}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Auto-hide success message
        document.addEventListener('DOMContentLoaded', function() {
            const successMessage = document.querySelector('.success-message');
            if (successMessage) {
                setTimeout(() => {
                    successMessage.style.display = 'none';
                }, 3000);
            }
        });

        function showApiCall(operation) {
            const currentUrl = window.location.href.split('?')[0];
            const table = '<?php echo $selectedTable; ?>';
            let endpoint, method, params, curl;
            
            switch(operation) {
                case 'create':
                    endpoint = currentUrl;
                    method = 'POST';
                    params = {
                        action: 'create',
                        table: table,
                        // Add example fields based on table columns
                        <?php foreach ($columns as $column): ?>
                        <?php if ($column['Field'] !== 'id'): ?>
                        '<?php echo $column['Field']; ?>': 'value',
                        <?php endif; ?>
                        <?php endforeach; ?>
                    };
                    break;
                    
                case 'update':
                    endpoint = currentUrl;
                    method = 'POST';
                    params = {
                        action: 'update',
                        table: table,
                        id: '1', // Example ID
                        <?php foreach ($columns as $column): ?>
                        <?php if ($column['Field'] !== 'id'): ?>
                        '<?php echo $column['Field']; ?>': 'value',
                        <?php endif; ?>
                        <?php endforeach; ?>
                    };
                    break;
                    
                case 'delete':
                    endpoint = currentUrl;
                    method = 'POST';
                    params = {
                        action: 'delete',
                        table: table,
                        id: '1' // Example ID
                    };
                    break;
                    
                case 'read':
                    endpoint = currentUrl;
                    method = 'GET';
                    params = {
                        table: table,
                        page: '1',
                        action: 'search',
                        length: '10',
                        search: {
                            value: 'search term'
                        }
                    };
                    break;
            }
            
            // Generate cURL command
            const formData = new URLSearchParams(params).toString();
            curl = `curl -X ${method} "${endpoint}"`;
            if (method === 'POST') {
                curl += ` -d "${formData}"`;
            }
            
            // Show the API call modal
            document.getElementById('apiEndpoint').textContent = endpoint;
            document.getElementById('apiMethod').textContent = method;
            document.getElementById('apiParams').textContent = JSON.stringify(params, null, 2);
            document.getElementById('apiCurl').textContent = curl;
            
            // Hide current modal and show API modal
            $('.modal').modal('hide');
            new bootstrap.Modal(document.getElementById('apiCallModal')).show();
        }

        // Remove the previous API-related code and add this new function
        function showAllApiCalls() {
            const currentUrl = window.location.href.split('?')[0];
            const table = '<?php echo $selectedTable; ?>';
            
            const apiDocs = {
                create: {
                    title: 'Create Record',
                    method: 'POST',
                    params: {
                        action: 'create',
                        table: table,
                        <?php foreach ($columns as $column): ?>
                        <?php if ($column['Field'] !== 'id'): ?>
                        '<?php echo $column['Field']; ?>': 'value',
                        <?php endif; ?>
                        <?php endforeach; ?>
                    }
                },
                read: {
                    title: 'Read Records',
                    method: 'GET',
                    params: {
                        table: table,
                        action: 'search',
                        length: '10',
                        start: '0',
                        search: {
                            value: 'search term'
                        }
                    }
                },
                update: {
                    title: 'Update Record',
                    method: 'POST',
                    params: {
                        action: 'update',
                        table: table,
                        id: '1',
                        <?php foreach ($columns as $column): ?>
                        <?php if ($column['Field'] !== 'id'): ?>
                        '<?php echo $column['Field']; ?>': 'new value',
                        <?php endif; ?>
                        <?php endforeach; ?>
                    }
                },
                delete: {
                    title: 'Delete Record',
                    method: 'POST',
                    params: {
                        action: 'delete',
                        table: table,
                        id: '1'
                    }
                }
            };

            // Generate tab content
            const tabContent = document.getElementById('api-tabContent');
            tabContent.innerHTML = '';

            for (const [operation, data] of Object.entries(apiDocs)) {
                const formData = new URLSearchParams(data.params).toString();
                const curl = `curl -X ${data.method} "${currentUrl}"${data.method === 'POST' ? ` -d "${formData}"` : ''}`;
                
                const tabPane = document.createElement('div');
                tabPane.className = `tab-pane fade ${operation === 'create' ? 'show active' : ''}`;
                tabPane.id = `${operation}-tab`;
                
                tabPane.innerHTML = `
                    <div class="mb-3">
                        <h6 class="text-primary">${data.title}</h6>
                        <hr>
                    </div>
                    <div class="mb-3">
                        <h6>Endpoint:</h6>
                        <code>${currentUrl}</code>
                    </div>
                    <div class="mb-3">
                        <h6>Method:</h6>
                        <code>${data.method}</code>
                    </div>
                    <div class="mb-3">
                        <h6>Parameters:</h6>
                        <pre><code>${JSON.stringify(data.params, null, 2)}</code></pre>
                    </div>
                    <div class="mb-3">
                        <h6>cURL Example:</h6>
                        <pre><code>${curl}</code></pre>
                    </div>
                    <div class="mb-3">
                        <h6>Response:</h6>
                        <pre><code>${operation === 'read' ? 
                            JSON.stringify({
                                draw: 1,
                                recordsTotal: 100,
                                recordsFiltered: 10,
                                data: [{id: 1, /* other fields */}]
                            }, null, 2) : 
                            JSON.stringify({
                                success: true,
                                message: `Record ${operation}d successfully`
                            }, null, 2)
                        }</code></pre>
                    </div>
                `;
                
                tabContent.appendChild(tabPane);
            }

            new bootstrap.Modal(document.getElementById('apiDocsModal')).show();
        }

        // Remove the previous API-related elements
        document.querySelectorAll('th:last-child, td:last-child').forEach(el => {
            if (el.textContent.includes('Show API')) {
                el.remove();
            }
        });
    </script>
</body>
</html> 