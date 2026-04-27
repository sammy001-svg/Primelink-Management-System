<?php
/**
 * Database Migration Script
 * Adds missing columns to existing tables
 */

require_once __DIR__ . '/config/db.php';

$migrations = [
    // leases: add property_id, deposit, terms if missing
    "ALTER TABLE `leases` ADD COLUMN IF NOT EXISTS `property_id` VARCHAR(36) NULL AFTER `tenant_id`",
    "ALTER TABLE `leases` ADD COLUMN IF NOT EXISTS `deposit` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `monthly_rent`",
    "ALTER TABLE `leases` ADD COLUMN IF NOT EXISTS `terms` TEXT NULL AFTER `deposit`",
    // Add FK for property_id (ignore if already exists)
    "ALTER TABLE `leases` ADD CONSTRAINT `fk_leases_property` FOREIGN KEY (`property_id`) REFERENCES `properties`(`id`) ON DELETE SET NULL",
    // transactions: add description column if missing
    "ALTER TABLE `transactions` ADD COLUMN IF NOT EXISTS `description` TEXT NULL AFTER `payment_method`",
    // transactions: add Overdue to status enum if not present
    "ALTER TABLE `transactions` MODIFY COLUMN `status` ENUM('Paid','Pending','Failed','Overdue') DEFAULT 'Pending'",
    // notifications table
    "CREATE TABLE IF NOT EXISTS `notifications` (
        `id` VARCHAR(36) PRIMARY KEY,
        `user_id` VARCHAR(36),
        `title` VARCHAR(255) NOT NULL,
        `message` TEXT,
        `type` ENUM('info','success','warning','alert') DEFAULT 'info',
        `is_read` TINYINT(1) DEFAULT 0,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    // landlords table
    "CREATE TABLE IF NOT EXISTS `landlords` (
        `id` VARCHAR(36) PRIMARY KEY,
        `full_name` VARCHAR(255) NOT NULL,
        `email` VARCHAR(255) UNIQUE NOT NULL,
        `phone` VARCHAR(50),
        `user_id` VARCHAR(36),
        `payout_details` JSON,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`user_id`) REFERENCES `profiles`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    // add landlord_id to properties if missing
    "ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `landlord_id` VARCHAR(36) NULL AFTER `id`",
    "ALTER TABLE `properties` ADD CONSTRAINT `fk_properties_landlord` FOREIGN KEY (`landlord_id`) REFERENCES `landlords`(`id`) ON DELETE SET NULL",
    // tokens table
    "CREATE TABLE IF NOT EXISTS `tokens` (
        `id` VARCHAR(36) PRIMARY KEY,
        `tenant_id` VARCHAR(36),
        `property_id` VARCHAR(36),
        `unit_id` VARCHAR(36),
        `token_type` ENUM('Electricity', 'Water') NOT NULL,
        `token_code` VARCHAR(100) UNIQUE NOT NULL,
        `units_value` DECIMAL(15,2) NOT NULL,
        `amount` DECIMAL(15,2) NOT NULL,
        `status` ENUM('Active', 'Used') DEFAULT 'Active',
        `created_by` VARCHAR(36),
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`property_id`) REFERENCES `properties`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    // Expand tenants table
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `spouse_name` VARCHAR(255) NULL",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `id_no` VARCHAR(100) NULL",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `spouse_id_no` VARCHAR(100) NULL",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `id_copy_url` TEXT NULL",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `spouse_id_copy_url` TEXT NULL",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `spouse_phone` VARCHAR(50) NULL",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `marital_status` ENUM('Single', 'Married') DEFAULT 'Single'",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `has_kids` TINYINT(1) DEFAULT 0",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `current_address` TEXT NULL",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `spouse_email` VARCHAR(255) NULL",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `alt_contact` VARCHAR(255) NULL",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `spouse_alt_contact` VARCHAR(255) NULL",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `profession` VARCHAR(255) NULL",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `spouse_profession` VARCHAR(255) NULL",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `employer_name` VARCHAR(255) NULL",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `spouse_employer_name` VARCHAR(255) NULL",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `occupation_type` ENUM('Residential', 'Commercial') DEFAULT 'Residential'",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `business_name` VARCHAR(255) NULL",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `business_nature` TEXT NULL",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `business_location` TEXT NULL",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `next_of_kin_name` VARCHAR(255) NULL",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `next_of_kin_contact` VARCHAR(255) NULL",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `next_of_kin_relationship` VARCHAR(100) NULL",
    // Digital Signature columns
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `terms_accepted_at` TIMESTAMP NULL AFTER `status`",
    "ALTER TABLE `tenants` ADD COLUMN IF NOT EXISTS `signature_name` VARCHAR(255) NULL AFTER `terms_accepted_at`",
    // Documents table (ensuring it exists)
    "CREATE TABLE IF NOT EXISTS `documents` (
        `id` VARCHAR(36) PRIMARY KEY,
        `tenant_id` VARCHAR(36),
        `title` VARCHAR(255) NOT NULL,
        `category` ENUM('Lease', 'ID', 'Termination', 'Other') NOT NULL,
        `file_url` TEXT NOT NULL,
        `file_size` VARCHAR(50),
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    // Add address to profiles
    "ALTER TABLE `profiles` ADD COLUMN IF NOT EXISTS `address` TEXT NULL AFTER `phone` ",
    // Expand units table
    "ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `images` JSON NULL AFTER `status` ",
    "ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `category` VARCHAR(100) NULL AFTER `images` ",
    "ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `property_code` VARCHAR(50) NULL AFTER `area` ",
    "CREATE TABLE IF NOT EXISTS `landlord_payouts` (
        `id` VARCHAR(36) PRIMARY KEY,
        `landlord_id` VARCHAR(36) NOT NULL,
        `amount` DECIMAL(15, 2) NOT NULL,
        `fee_deducted` DECIMAL(15, 2) NOT NULL,
        `payout_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `reference_code` VARCHAR(50) UNIQUE NOT NULL,
        `status` ENUM('Pending', 'Completed', 'Failed') DEFAULT 'Completed',
        `method` ENUM('Bank', 'M-Pesa', 'Cash') DEFAULT 'Bank',
        FOREIGN KEY (`landlord_id`) REFERENCES `landlords`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

$results = [];
foreach ($migrations as $sql) {
    try {
        $pdo->exec($sql);
        $results[] = ['ok', $sql];
    } catch (PDOException $e) {
        // Duplicate FK errors are ignorable
        if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), '1826') !== false || strpos($e->getMessage(), '1061') !== false) {
            $results[] = ['skip', substr($sql, 0, 60) . '... (already applied)'];
        } else {
            $results[] = ['error', substr($sql, 0, 60) . '... — ' . $e->getMessage()];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>DB Migration</title>
<style>body{font-family:monospace;padding:2rem;background:#0f172a;color:#e2e8f0;} h1{color:#d4af37;} .ok{color:#4ade80;} .skip{color:#94a3b8;} .error{color:#f87171;} a{color:#d4af37;}</style>
</head>
<body>
<h1>🔧 Primelink DB Migration</h1>
<?php foreach ($results as [$status, $msg]): ?>
<p class="<?php echo $status; ?>">
    [<?php echo strtoupper($status); ?>] <?php echo htmlspecialchars($msg); ?>
</p>
<?php endforeach; ?>
<hr style="border-color:#334155;margin:2rem 0;">
<p>✅ Migration complete. <a href="leases.php">Go to Leases →</a> | <a href="dashboard.php">Dashboard →</a></p>
</body>
</html>
