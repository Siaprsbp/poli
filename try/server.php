<?php
// Aktifkan error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set path ke PHP
define('PHP_EXECUTABLE', getenv('PHP_EXECUTABLE'));

// Konfigurasi server dari environment variables
$port = getenv('PHP_SERVER_PORT') ?: 8083;
$host = getenv('PHP_SERVER_HOST') ?: '127.0.0.1';

// Pastikan rootDir selalu terdefinisi
$rootDir = dirname(__FILE__);
if (empty($rootDir)) {
    $rootDir = __DIR__;
}

// Fungsi untuk logging
function debug_log($message) {
    $logFile = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'server.log';
    file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
}

// Fungsi untuk format ukuran file
function formatFileSize($size) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($size >= 1024 && $i < count($units) - 1) {
        $size /= 1024;
        $i++;
    }
    return round($size, 2) . ' ' . $units[$i];
}

// Fungsi untuk generate directory listing dengan ikon
function generateDirectoryListing($path, $requestUri) {
    $files = scandir($path);
    $output = "<!DOCTYPE html>\n";
    $output .= "<html><head><title>Index of " . htmlspecialchars($requestUri) . "</title>\n";
    $output .= "<style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f4f5f8; }
        table { width: 100%; border-collapse: collapse; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
        th { background-color: #f8f9fa; color: #333; font-weight: 600; }
        a { color: #0066cc; text-decoration: none; display: flex; align-items: center; }
        a:hover { text-decoration: underline; }
        .icon { width: 20px; height: 20px; margin-right: 8px; }
        .folder { color: #ffd700; }
        .file { color: #666; }
        .folder-icon::before {
            content: '📁';
            margin-right: 8px;
        }
        .file-icon::before {
            content: '📄';
            margin-right: 8px;
        }
        .back-icon::before {
            content: '⬆️';
            margin-right: 8px;
        }
        h1 { color: #333; margin-bottom: 20px; }
        .path-nav { margin-bottom: 15px; padding: 10px; background: white; border-radius: 4px; }
    </style>\n";
    $output .= "</head><body>\n";
    
    // Tambahkan navigation path
    $output .= "<div class='path-nav'>Location: ";
    $pathParts = explode('/', trim($requestUri, '/'));
    $currentPath = '';
    $output .= "<a href='/'>/</a>";
    foreach ($pathParts as $part) {
        if ($part) {
            $currentPath .= '/' . $part;
            $output .= " / <a href='" . htmlspecialchars($currentPath) . "'>" . htmlspecialchars($part) . "</a>";
        }
    }
    $output .= "</div>\n";
    
    $output .= "<h1>Index of " . htmlspecialchars($requestUri) . "</h1>\n";
    $output .= "<table>\n";
    $output .= "<tr><th>Name</th><th>Last Modified</th><th>Size</th></tr>\n";
    $output .= "<tr><td><a href='../' class='back-icon'>..</a></td><td>-</td><td>-</td></tr>\n";

    // Pisahkan folder dan file
    $folders = [];
    $files = [];
    $allFiles = scandir($path);
    
    foreach ($allFiles as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $fullPath = $path . DIRECTORY_SEPARATOR . $file;
        if (is_dir($fullPath)) {
            $folders[] = $file;
        } else {
            $files[] = $file;
        }
    }

    // Sort folders dan files
    sort($folders);
    sort($files);

    // Tampilkan folders dulu
    foreach ($folders as $folder) {
        $fullPath = $path . DIRECTORY_SEPARATOR . $folder;
        $modTime = date('Y-m-d H:i:s', filemtime($fullPath));
        
        $output .= "<tr>\n";
        $output .= "<td><a href='" . htmlspecialchars($folder) . "/' class='folder-icon'>" . 
                  htmlspecialchars($folder) . "/</a></td>\n";
        $output .= "<td>" . htmlspecialchars($modTime) . "</td>\n";
        $output .= "<td>-</td>\n";
        $output .= "</tr>\n";
    }

    // Kemudian tampilkan files
    foreach ($files as $file) {
        $fullPath = $path . DIRECTORY_SEPARATOR . $file;
        $size = formatFileSize(filesize($fullPath));
        $modTime = date('Y-m-d H:i:s', filemtime($fullPath));
        
        $output .= "<tr>\n";
        $output .= "<td><a href='" . htmlspecialchars($file) . "' class='file-icon'>" . 
                  htmlspecialchars($file) . "</a></td>\n";
        $output .= "<td>" . htmlspecialchars($modTime) . "</td>\n";
        $output .= "<td>" . htmlspecialchars($size) . "</td>\n";
        $output .= "</tr>\n";
    }
    
    $output .= "</table></body></html>";
    return $output;
}

// Router untuk menangani request
$router = function($requestUri) use ($rootDir) {
    $path = parse_url($requestUri, PHP_URL_PATH);
    $filePath = $rootDir . str_replace('/', DIRECTORY_SEPARATOR, $path);
    
    // Security headers
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    
    // Handle root path
    if ($path === '/' || empty($path)) {
        $filePath = $rootDir;
    }
    
    // Prevent directory traversal
    $realPath = realpath($filePath);
    $realRootDir = realpath($rootDir);
    if ($realPath === false || strpos($realPath, $realRootDir) !== 0) {
        header('HTTP/1.0 403 Forbidden');
        echo '403 Forbidden: Access Denied';
        return false;
    }

    // Mapping content types
    $contentTypes = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'woff' => 'application/font-woff',
        'woff2' => 'application/font-woff2',
        'ttf' => 'application/font-ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'html' => 'text/html',
        'php' => 'text/html',
        'txt' => 'text/plain',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'pdf' => 'application/pdf'
    ];

    // Prevent headers already sent error
    if (!headers_sent()) {
        if (file_exists($filePath)) {
            if (is_dir($filePath)) {
                // Normalize directory path with trailing slash
                if (substr($path, -1) !== '/') {
                    $redirectPath = $path . '/';
                    if (!empty($_SERVER['QUERY_STRING'])) {
                        $redirectPath .= '?' . $_SERVER['QUERY_STRING'];
                    }
                    header('Location: ' . $redirectPath);
                    return true;
                }
                
                // Check for index files
                $indexFiles = ['index.php', 'index.html', 'index.htm'];
                foreach ($indexFiles as $indexFile) {
                    $indexPath = rtrim($filePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $indexFile;
                    if (file_exists($indexPath)) {
                        // Include PHP files, serve others directly
                        if (pathinfo($indexPath, PATHINFO_EXTENSION) === 'php') {
                            chdir(dirname($indexPath));
                            include($indexPath);
                            return true;
                        } else {
                            $filePath = $indexPath;
                            break;
                        }
                    }
                }
                
                if (is_dir($filePath)) {
                    // Show directory listing
                    header('Content-Type: text/html; charset=utf-8');
                    echo generateDirectoryListing($filePath, $path);
                    return true;
                }
            }

            // Handle PHP files
            if (pathinfo($filePath, PATHINFO_EXTENSION) === 'php') {
                chdir(dirname($filePath));
                include($filePath);
                return true;
            }

            // Handle static files
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $contentType = $contentTypes[$ext] ?? 'application/octet-stream';
            
            header('Content-Type: ' . $contentType . '; charset=utf-8');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            return true;
        }
        
        // File not found
        header("HTTP/1.0 404 Not Found");
        echo "<h1>404 Not Found</h1>";
        echo "<p>The requested resource <code>" . htmlspecialchars($path) . "</code> was not found on this server.</p>";
        return false;
    }
    
    echo "Cannot modify header information - headers already sent";
    return false;
};

// Start built-in server
debug_log("Starting PHP Server...");
debug_log("Host: " . $host);
debug_log("Port: " . $port);
debug_log("Document Root: " . $rootDir);

if (php_sapi_name() === 'cli-server') {
    return $router($_SERVER['REQUEST_URI']);
} 