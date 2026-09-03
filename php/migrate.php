<?php
/**
 * Database Migration Script
 * Adds missing columns to existing tables
 */

require_once __DIR__ . '/config/db.php';

$migrations = [
    // leases: add property_id, deposit, terms if missing
    "ALTER TABLE `leases` ADD COLUMN IF NOT EXISTS `property_id` VARCHAR(36) NULL AFTER `tenant_id`",
    "ALTER TABLE `leases` ADD COLUMN IF NOT EXISTS `deposit_amount` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `monthly_rent` ",
    "ALTER TABLE `leases` ADD COLUMN IF NOT EXISTS `terms` TEXT NULL AFTER `deposit_amount` ",
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
    "ALTER TABLE `landlord_payouts` ADD CONSTRAINT `fk_payouts_landlord` FOREIGN KEY (`landlord_id`) REFERENCES `landlords`(`id`) ON DELETE CASCADE",
    // Fix units table columns
    "ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `monthly_rent` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `unit_type` ",
    "ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `deposit_amount` DECIMAL(15, 2) NOT NULL DEFAULT 0 AFTER `monthly_rent` ",
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
    "ALTER TABLE `leases` ADD COLUMN IF NOT EXISTS `signed_lease_url` VARCHAR(255) NULL AFTER `status` ",
    "ALTER TABLE `leases` ADD COLUMN IF NOT EXISTS `termination_date` DATE NULL AFTER `signed_lease_url` ",
    "ALTER TABLE `leases` ADD COLUMN IF NOT EXISTS `termination_reason` TEXT NULL AFTER `termination_date` ",
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
    // System settings key-value store
    "CREATE TABLE IF NOT EXISTS `system_settings` (
        `setting_key` VARCHAR(100) PRIMARY KEY,
        `setting_value` TEXT,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    // Seed default settings (ignore if already set)
    "INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES
        ('company_name',        'Primelink Management System'),
        ('company_email',       'info@primelink.co.ke'),
        ('company_phone',       '+254 700 000000'),
        ('company_address',     'Nairobi, Kenya'),
        ('company_tagline',     'Premium Property Management'),
        ('currency_symbol',     'KSh'),
        ('invoice_prefix',      'INV'),
        ('invoice_due_days',    '7'),
        ('invoice_footer',      'Thank you for your payment. For inquiries contact us at the above details.'),
        ('mpesa_shortcode',     '174379'),
        ('mpesa_consumer_key',  ''),
        ('mpesa_consumer_secret',''),
        ('mpesa_passkey',       ''),
        ('mpesa_callback_url',  ''),
        ('mpesa_environment',   'sandbox'),
        ('fiscal_year_start',   '1'),
        ('mail_driver',         'mail'),
        ('smtp_host',           ''),
        ('smtp_port',           '587'),
        ('smtp_user',           ''),
        ('smtp_pass',           ''),
        ('smtp_from_name',      'Primelink Management'),
        ('smtp_from_email',     'noreply@primelink.co.ke'),
        ('notify_on_payment',   '1'),
        ('notify_on_maintenance','1'),
        ('notify_on_lease',     '1'),
        ('management_fee_rate', '10')",
    // Add garbage_fee to properties table
    "ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `garbage_fee` DECIMAL(15,2) NOT NULL DEFAULT 0",
    // Ensure invoices.status supports Overdue (in case column was created before this value was added)
    "ALTER TABLE `invoices` MODIFY COLUMN `status` ENUM('Unpaid','Paid','Partial','Overdue','Cancelled') NOT NULL DEFAULT 'Unpaid'",
    // Add status to users table
    "ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active' AFTER `role`",
    // Audit log table
    "CREATE TABLE IF NOT EXISTS `audit_logs` (
        `id` VARCHAR(36) PRIMARY KEY,
        `user_id` VARCHAR(36) NULL,
        `user_name` VARCHAR(255),
        `action` VARCHAR(100) NOT NULL,
        `module` VARCHAR(100),
        `record_id` VARCHAR(36),
        `description` TEXT,
        `ip_address` VARCHAR(45),
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_module` (`module`),
        INDEX `idx_user`   (`user_id`),
        INDEX `idx_created`(`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    // ── Document corrections: posted invoices/receipts are revised, never overwritten ──
    "CREATE TABLE IF NOT EXISTS `document_revisions` (
        `id`              VARCHAR(36) PRIMARY KEY,
        `doc_type`        VARCHAR(20)  NOT NULL,
        `doc_id`          VARCHAR(36)  NOT NULL,
        `revision_no`     INT          NOT NULL DEFAULT 1,
        `reason`          TEXT         NULL,
        `changes_json`    LONGTEXT     NULL,
        `snapshot_json`   LONGTEXT     NULL,
        `tenant_notified` TINYINT(1)   NOT NULL DEFAULT 0,
        `notified_at`     DATETIME     NULL,
        `changed_by`      VARCHAR(36)  NULL,
        `changed_by_name` VARCHAR(255) NULL,
        `ip_address`      VARCHAR(64)  NULL,
        `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_docrev_doc` (`doc_type`, `doc_id`),
        INDEX `idx_docrev_date` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "ALTER TABLE `invoices`     ADD COLUMN IF NOT EXISTS `revision_no` INT NOT NULL DEFAULT 0",
    "ALTER TABLE `invoices`     ADD COLUMN IF NOT EXISTS `last_corrected_at` DATETIME NULL",
    "ALTER TABLE `invoices`     ADD COLUMN IF NOT EXISTS `last_correction_reason` TEXT NULL",
    "ALTER TABLE `invoices`     ADD COLUMN IF NOT EXISTS `corrected_by` VARCHAR(36) NULL",
    "ALTER TABLE `invoices`     ADD COLUMN IF NOT EXISTS `description` TEXT NULL",
    "ALTER TABLE `transactions` ADD COLUMN IF NOT EXISTS `revision_no` INT NOT NULL DEFAULT 0",
    "ALTER TABLE `transactions` ADD COLUMN IF NOT EXISTS `last_corrected_at` DATETIME NULL",
    "ALTER TABLE `transactions` ADD COLUMN IF NOT EXISTS `last_correction_reason` TEXT NULL",
    "ALTER TABLE `transactions` ADD COLUMN IF NOT EXISTS `corrected_by` VARCHAR(36) NULL",
    "ALTER TABLE `transactions` ADD COLUMN IF NOT EXISTS `reference_number` VARCHAR(255) NULL",
    // ── SMS delivery log (Shanfix Bulk SMS) ──
    "CREATE TABLE IF NOT EXISTS `sms_log` (
        `id`           VARCHAR(36) PRIMARY KEY,
        `tenant_id`    VARCHAR(36)   NULL,
        `phone`        VARCHAR(20)   NULL,
        `message`      TEXT          NULL,
        `parts`        INT           NOT NULL DEFAULT 1,
        `status`       VARCHAR(20)   NOT NULL DEFAULT 'Pending',
        `provider_ref` VARCHAR(120)  NULL,
        `units`        DECIMAL(10,4) NULL,
        `error`        TEXT          NULL,
        `context`      VARCHAR(60)   NULL,
        `sent_by`      VARCHAR(36)   NULL,
        `created_at`   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_smslog_tenant`  (`tenant_id`),
        INDEX `idx_smslog_created` (`created_at`),
        INDEX `idx_smslog_status`  (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    // ── Collection accounts: where tenant payments are banked ──
    "CREATE TABLE IF NOT EXISTS `bank_accounts` (
        `id`                 VARCHAR(36) PRIMARY KEY,
        `name`               VARCHAR(120)  NOT NULL,
        `bank_name`          VARCHAR(120)  NULL,
        `account_name`       VARCHAR(150)  NULL,
        `account_no`         VARCHAR(60)   NULL,
        `branch`             VARCHAR(120)  NULL,
        `account_type`       VARCHAR(30)   NOT NULL DEFAULT 'Bank',
        `paybill_no`         VARCHAR(30)   NULL,
        `opening_balance`    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        `ledger_account_id`  VARCHAR(36)   NULL,
        `default_for_method` VARCHAR(30)   NULL,
        `is_active`          TINYINT(1)    NOT NULL DEFAULT 1,
        `is_default`         TINYINT(1)    NOT NULL DEFAULT 0,
        `sort_order`         INT           NOT NULL DEFAULT 0,
        `notes`              TEXT          NULL,
        `created_at`         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_bankacc_active` (`is_active`),
        INDEX `idx_bankacc_method` (`default_for_method`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    // ── Repair transactions tables built by an older, minimal self-heal ──
    "ALTER TABLE `transactions` ADD COLUMN IF NOT EXISTS `tenant_id` VARCHAR(36) NULL",
    "ALTER TABLE `transactions` ADD COLUMN IF NOT EXISTS `lease_id` VARCHAR(36) NULL",
    "ALTER TABLE `transactions` ADD COLUMN IF NOT EXISTS `invoice_id` VARCHAR(36) NULL",
    "ALTER TABLE `transactions` ADD COLUMN IF NOT EXISTS `payment_method` VARCHAR(50) NULL",
    "ALTER TABLE `transactions` ADD COLUMN IF NOT EXISTS `description` TEXT NULL",
    "ALTER TABLE `transactions` ADD COLUMN IF NOT EXISTS `reference_code` VARCHAR(255) NULL",
    "ALTER TABLE `transactions` ADD COLUMN IF NOT EXISTS `transaction_date` DATE NULL",
    "CREATE INDEX `idx_tx_invoice` ON `transactions` (`invoice_id`)",
    "CREATE INDEX `idx_tx_tenant`  ON `transactions` (`tenant_id`)",
    // ── Payment allocation: one posting per charge, grouped as one receipt ──
    "ALTER TABLE `transactions` ADD COLUMN IF NOT EXISTS `payment_group` VARCHAR(36) NULL",
    "CREATE INDEX `idx_tx_paygroup` ON `transactions` (`payment_group`)",
    "ALTER TABLE `transactions` ADD COLUMN IF NOT EXISTS `bank_account_id` VARCHAR(36) NULL",
    "CREATE INDEX `idx_tx_bank_account` ON `transactions` (`bank_account_id`)",
    // ── HR: fuller staff records ──
    "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `alt_phone` VARCHAR(30) NULL",
    "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `marital_status` VARCHAR(20) NULL",
    "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `postal_address` VARCHAR(150) NULL",
    "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `contract_start_date` DATE NULL",
    "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `work_location` VARCHAR(150) NULL",
    "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `reports_to` VARCHAR(150) NULL",
    "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `id_copy_url` VARCHAR(500) NULL",
    "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `agreement_url` VARCHAR(500) NULL",
    "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `photo_url` VARCHAR(500) NULL",
    "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `termination_date` DATE NULL",
    "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `termination_reason` TEXT NULL",
    "ALTER TABLE `employees` ADD COLUMN IF NOT EXISTS `notes` TEXT NULL",
    "ALTER TABLE `employee_contacts`  ADD COLUMN IF NOT EXISTS `alt_phone` VARCHAR(50) NULL",
    "ALTER TABLE `employee_contacts`  ADD COLUMN IF NOT EXISTS `email` VARCHAR(150) NULL",
    "ALTER TABLE `employee_documents` ADD COLUMN IF NOT EXISTS `expires_on` DATE NULL",
    "ALTER TABLE `employee_warnings`  ADD COLUMN IF NOT EXISTS `expires_on` DATE NULL",
    // ── HR: contract management ──
    "CREATE TABLE IF NOT EXISTS `employee_contracts` (
        `id`            VARCHAR(36) PRIMARY KEY,
        `employee_id`   VARCHAR(36)   NOT NULL,
        `contract_type` VARCHAR(30)   NOT NULL DEFAULT 'Contract',
        `job_title`     VARCHAR(150)  NULL,
        `start_date`    DATE          NOT NULL,
        `end_date`      DATE          NULL,
        `gross_salary`  DECIMAL(15,2) NULL,
        `terms`         TEXT          NULL,
        `file_path`     VARCHAR(500)  NULL,
        `status`        VARCHAR(20)   NOT NULL DEFAULT 'Active',
        `renewed_from`  VARCHAR(36)   NULL,
        `ended_reason`  TEXT          NULL,
        `created_by`    VARCHAR(36)   NULL,
        `created_at`    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_empcontract_emp`    (`employee_id`),
        INDEX `idx_empcontract_status` (`status`),
        INDEX `idx_empcontract_end`    (`end_date`),
        FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
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
