<?php
/**
 * Automated Database Setup
 * Primelink Management System
 */

// Basic connection to MySQL (without selecting a DB)
$host = '127.0.0.1';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Connected to MySQL server...<br>";

    // 1. Create Database if not exists
    $dbname = 'primelink_db';
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database `$dbname` verified/created.<br>";

    $pdo->exec("USE `$dbname` ;"); // Use the newly created/verified dB
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Successfully connected to `$dbname`.Checking tables...<br>";

    // 3. Check if tables exist, if not import schema
    $tables = $pdo->query("SHOW TABLES")->fetchAll();
    if (empty($tables)) {
        echo "Importing schema...<br>";
        $sqlPath = __DIR__ . '/../mysql_schema.sql';
        if (file_exists($sqlPath)) {
            $sql = file_get_contents($sqlPath);
            // Simple split by semicolon (careful with triggers, but our schema is simple)
            $pdo->exec($sql);
            echo "Schema imported successfully.<br>";
        } else {
            echo "Error: mysql_schema.sql not found at " . $sqlPath . "<br>";
        }
    } else {
        echo "Database already has tables. Skipping import.<br>";
    }

    echo "<strong>Setup Complete!</strong> You can now visit <a href='seed.php'>seed.php</a> to create test accounts.";

} catch (PDOException $e) {
    echo "<div style='color:red;'><strong>Setup Error:</strong> " . $e->getMessage() . "</div>";
    echo "Please ensure your MySQL server is running and the credentials in your .env or this script are correct.";
}
?>
