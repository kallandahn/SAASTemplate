<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Upload Screen</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Poppins', Arial, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f0f2f5;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo {
            max-width: 96px;
            height: auto;
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 20px;
        }
        .upload-form {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="text"], input[type="file"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-family: 'Poppins', Arial, sans-serif;
        }
        button {
            background-color: #4a90e2;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-family: 'Poppins', Arial, sans-serif;
        }
        button:hover {
            background-color: #357ae8;
        }
        .file-list {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .file-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        .file-item:last-child {
            border-bottom: none;
        }
        .file-actions {
            display: flex;
            gap: 10px;
        }
        .rename-input {
            display: none;
            width: 200px;
        }
    </style>
</head>
<body>
    <div class="header">
        <img src="https://d1muf25xaso8hp.cloudfront.net/https%3A%2F%2F5c7f4d6349b17b0496c1b81053695fa4.cdn.bubble.io%2Ff1715892487043x723030680359939600%2FMy-AI-Team-Logo_1-0701195.png?w=96&h=51&auto=compress&dpr=2&fit=max" alt="MyAiTeam Logo" class="logo">
        <h1>Admin Upload Screen</h1>
    </div>

    <div class="upload-form">
        <form action="" method="post" enctype="multipart/form-data">
            <div class="form-group">
                <label for="file-name">File Name:</label>
                <input type="text" id="file-name" name="file-name" required>
            </div>
            <div class="form-group">
                <label for="file-upload">Select File:</label>
                <input type="file" id="file-upload" name="file-upload" required>
            </div>
            <button type="submit" name="upload">Upload File</button>
        </form>
    </div>

    <div class="file-list">
        <h2>Uploaded Files</h2>
        <?php
        $directory = './';
        $files = scandir($directory);
        foreach($files as $file) {
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            if($file != '.' && $file != '..' && $file != 'index.php' && 
               ($extension == 'php' || $extension == 'html' || $extension == 'htm')) {
                echo '<div class="file-item">';
                echo '<span class="file-name">' . $file . '</span>';
                echo '<div class="file-actions">';
                echo '<button onclick="renameFile(\'' . $file . '\')">Rename</button>';
                echo '<button onclick="deleteFile(\'' . $file . '\')">Delete</button>';
                echo '</div>';
                echo '<input type="text" class="rename-input" data-file="' . $file . '">';
                echo '</div>';
            }
        }
        ?>
    </div>

    <script>
        function renameFile(fileName) {
            const fileItem = document.querySelector(`.file-item:has(.file-name:contains('${fileName}'))`);
            const renameInput = fileItem.querySelector('.rename-input');
            const fileNameSpan = fileItem.querySelector('.file-name');
            
            if (renameInput.style.display === 'none' || renameInput.style.display === '') {
                renameInput.style.display = 'inline-block';
                renameInput.value = fileName;
                fileNameSpan.style.display = 'none';
            } else {
                const newName = renameInput.value;
                if (newName && newName !== fileName) {
                    fetch('rename.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `oldName=${fileName}&newName=${newName}`
                    })
                    .then(response => response.text())
                    .then(result => {
                        if (result === 'success') {
                            fileNameSpan.textContent = newName;
                            renameInput.dataset.file = newName;
                        } else {
                            alert('Failed to rename file');
                        }
                    });
                }
                renameInput.style.display = 'none';
                fileNameSpan.style.display = 'inline-block';
            }
        }

        function deleteFile(fileName) {
            if (confirm(`Are you sure you want to delete ${fileName}?`)) {
                fetch('delete.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `fileName=${fileName}`
                })
                .then(response => response.text())
                .then(result => {
                    if (result === 'success') {
                        const fileItem = document.querySelector(`.file-item:has(.file-name:contains('${fileName}'))`);
                        fileItem.remove();
                    } else {
                        alert('Failed to delete file');
                    }
                });
            }
        }
    </script>

    <?php
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['upload'])) {
        $target_dir = "./";
        $file_name = $_POST['file-name'];
        $file_extension = pathinfo($_FILES["file-upload"]["name"], PATHINFO_EXTENSION);
        $target_file = $target_dir . $file_name . "." . $file_extension;
        
        if (move_uploaded_file($_FILES["file-upload"]["tmp_name"], $target_file)) {
            // Check if the uploaded file is PHP and doesn't already include protected-page.php
            if ($file_extension == 'php') {
                $content = file_get_contents($target_file);
                
                // Check if protected-page.php is not already included
                if (strpos($content, 'require_once \'protected-page.php\';') === false && 
                    strpos($content, "require_once 'protected-page.php';") === false && 
                    strpos($content, 'require_once "protected-page.php";') === false && 
                    strpos($content, "require_once \"protected-page.php\";") === false) {
                    
                    // Don't add to specific files
                    $excluded_files = ['login.php', 'logout.php', 'register.php', 'session_start.php', 'db_connection.php'];
                    $current_file = strtolower($file_name . '.' . $file_extension);
                    
                    if (!in_array($current_file, $excluded_files)) {
                        // Add protected-page.php requirement at the start of the PHP code
                        $new_content = "<?php\nrequire_once 'protected-page.php';\n?>\n" . $content;
                        file_put_contents($target_file, $new_content);
                    }
                }
            }
            
            echo "<script>alert('File uploaded successfully.');</script>";
            echo "<script>window.location.reload();</script>";
        } else {
            echo "<script>alert('Sorry, there was an error uploading your file.');</script>";
        }
    }
    ?>
</body>
</html>

<?php
// rename.php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $oldName = $_POST['oldName'];
    $newName = $_POST['newName'];
    
    if (rename($oldName, $newName)) {
        echo "success";
    } else {
        echo "error";
    }
}
?>

<?php
// delete.php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fileName = $_POST['fileName'];
    
    if (unlink($fileName)) {
        echo "success";
    } else {
        echo "error";
    }
}
?>