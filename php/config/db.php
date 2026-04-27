<?php
/**
 * Database Configuration
 * Primelink Management System
 */

// Load environment variables from .env file
function loadEnv($path) {
    if (!file_exists($path)) return false;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            
            // Set in all available superglobals and environment
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
    return true;
}

// Function to get environment variable with fallback
function get_env_var($key, $default = null) {
    if (isset($_ENV[$key])) return $_ENV[$key];
    if (isset($_SERVER[$key])) return $_SERVER[$key];
    $val = getenv($key);
    return ($val !== false) ? $val : $default;
}

$env_loaded = loadEnv(__DIR__ . '/../.env');

$host = get_env_var('DB_HOST', 'localhost');
$dbname = get_env_var('DB_NAME', 'primelink_db');
$user = get_env_var('DB_USER', 'root');
$pass = get_env_var('DB_PASS', '');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_msg = "Database Connection Error: " . $e->getMessage();
    if (!$env_loaded) {
        $error_msg .= " (Note: .env file was NOT found in " . realpath(__DIR__ . '/../') . ")";
    }
    die($error_msg);
}
?>
