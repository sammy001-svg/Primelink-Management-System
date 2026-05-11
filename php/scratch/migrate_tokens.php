<?php
require_once __DIR__ . '/../includes/auth.php';
try {
    $pdo->exec("ALTER TABLE tokens ADD COLUMN IF NOT EXISTS meter_number VARCHAR(100) AFTER unit_id");
    echo "Done";
} catch (PDOException $e) {
    // Try without IF NOT EXISTS (older MySQL)
    try {
        $pdo->exec("ALTER TABLE tokens ADD COLUMN meter_number VARCHAR(100) AFTER unit_id");
        echo "Done";
    } catch (PDOException $e2) {
        echo "Column may already exist: " . $e2->getMessage();
    }
}
?>
