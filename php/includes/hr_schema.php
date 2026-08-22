<?php
/**
 * HR Schema
 * Primelink Management System
 *
 * One place that owns the personnel tables, so hr.php, hr_employee.php and the
 * action handlers stop each carrying their own drifting copy of the DDL.
 *
 * An employee record spans several tables:
 *   employees                 the person and their current post
 *   employee_contracts        every contract term they have been engaged on
 *   employee_contacts         next of kin and emergency contacts
 *   employee_documents        ID copy, signed agreement, certificates
 *   employee_salary_history   every pay review
 *   employee_warnings         disciplinary record
 *   employee_bank_details     where their salary is paid
 *   employee_tax_profile      KRA / NSSF / SHIF and allowances (payroll module)
 */

/** Employment statuses an employee can hold. */
const HR_EMPLOYMENT_STATUSES = ['Permanent', 'Contract', 'Probation', 'Casual', 'Internship'];

/** Statuses that are time-bound and therefore need an end date. */
const HR_FIXED_TERM_STATUSES = ['Contract', 'Probation', 'Casual', 'Internship'];

/** Lifecycle of a single contract record. */
const HR_CONTRACT_STATUSES = ['Active', 'Renewed', 'Expired', 'Terminated'];

/** Document kinds captured during onboarding. */
const HR_DOC_TYPES = [
    'id_copy'       => 'National ID Copy',
    'agreement'     => 'Employment Agreement',
    'passport_photo' => 'Passport Photo',
    'kra_pin'       => 'KRA PIN Certificate',
    'certificate'   => 'Academic Certificate',
    'cv'            => 'CV / Résumé',
    'good_conduct'  => 'Certificate of Good Conduct',
    'nssf_card'     => 'NSSF Card',
    'shif_card'     => 'SHIF / NHIF Card',
    'other'         => 'Other',
];

/**
 * Create and patch every personnel table. Idempotent, and cached per session
 * so ordinary page loads cost nothing.
 */
function ensureHrSchema(PDO $pdo, bool $force = false): void {
    static $done = false;
    if ($done && !$force) return;
    $done = true;

    $cacheKey = 'pl_hr_schema_v1';
    if (!$force && !empty($_SESSION[$cacheKey])) return;

    // ── Employee record ───────────────────────────────────────────────
    $columns = [
        'staff_no'            => 'VARCHAR(50) NULL',
        'id_number'           => 'VARCHAR(50) NULL',
        'date_of_birth'       => 'DATE NULL',
        'gender'              => 'VARCHAR(20) NULL',
        'marital_status'      => 'VARCHAR(20) NULL',
        'hometown'            => 'VARCHAR(150) NULL',
        'physical_address'    => 'TEXT NULL',
        'postal_address'      => 'VARCHAR(150) NULL',
        'phone'               => 'VARCHAR(30) NULL',
        'alt_phone'           => 'VARCHAR(30) NULL',
        'employment_status'   => "VARCHAR(30) NOT NULL DEFAULT 'Permanent'",
        'contract_start_date' => 'DATE NULL',
        'contract_end_date'   => 'DATE NULL',
        'work_location'       => 'VARCHAR(150) NULL',
        'reports_to'          => 'VARCHAR(150) NULL',
        'id_copy_url'         => 'VARCHAR(500) NULL',
        'agreement_url'       => 'VARCHAR(500) NULL',
        'photo_url'           => 'VARCHAR(500) NULL',
        'termination_date'    => 'DATE NULL',
        'termination_reason'  => 'TEXT NULL',
        'notes'               => 'TEXT NULL',
    ];
    foreach ($columns as $col => $def) {
        hrEnsureColumn($pdo, 'employees', $col, $def);
    }

    // ── Contracts: one row per engagement term ────────────────────────
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `employee_contracts` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {}

    // ── Supporting records ────────────────────────────────────────────
    $tables = [
        "CREATE TABLE IF NOT EXISTS `employee_documents` (
            `id`          VARCHAR(36) PRIMARY KEY,
            `employee_id` VARCHAR(36)  NOT NULL,
            `doc_type`    VARCHAR(50)  NOT NULL,
            `doc_name`    VARCHAR(255) NOT NULL,
            `file_path`   VARCHAR(500) NOT NULL,
            `expires_on`  DATE         NULL,
            `uploaded_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            `uploaded_by` VARCHAR(36)  NULL,
            INDEX `idx_empdoc_emp` (`employee_id`),
            FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `employee_contacts` (
            `id`             VARCHAR(36) PRIMARY KEY,
            `employee_id`    VARCHAR(36)  NOT NULL,
            `name`           VARCHAR(255) NOT NULL,
            `phone`          VARCHAR(50)  NOT NULL,
            `alt_phone`      VARCHAR(50)  NULL,
            `email`          VARCHAR(150) NULL,
            `relationship`   VARCHAR(100) NULL,
            `is_next_of_kin` TINYINT(1)   DEFAULT 0,
            `address`        TEXT         NULL,
            `created_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_empcontact_emp` (`employee_id`),
            FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `employee_salary_history` (
            `id`             VARCHAR(36) PRIMARY KEY,
            `employee_id`    VARCHAR(36)   NOT NULL,
            `effective_date` DATE          NOT NULL,
            `old_salary`     DECIMAL(15,2) NOT NULL,
            `new_salary`     DECIMAL(15,2) NOT NULL,
            `reason`         TEXT          NULL,
            `reviewed_by`    VARCHAR(255)  NULL,
            `created_at`     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_empsalary_emp` (`employee_id`),
            FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `employee_warnings` (
            `id`           VARCHAR(36) PRIMARY KEY,
            `employee_id`  VARCHAR(36)  NOT NULL,
            `warning_date` DATE         NOT NULL,
            `severity`     VARCHAR(20)  NOT NULL DEFAULT 'Written',
            `reason`       TEXT         NOT NULL,
            `action_taken` TEXT         NULL,
            `issued_by`    VARCHAR(255) NULL,
            `file_path`    VARCHAR(500) NULL,
            `expires_on`   DATE         NULL,
            `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_empwarn_emp` (`employee_id`),
            FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS `employee_bank_details` (
            `id`           VARCHAR(36) PRIMARY KEY,
            `employee_id`  VARCHAR(36)  NOT NULL,
            `bank_name`    VARCHAR(100) NOT NULL,
            `branch_name`  VARCHAR(100) NULL,
            `account_name` VARCHAR(150) NOT NULL,
            `account_no`   VARCHAR(50)  NOT NULL,
            `account_type` VARCHAR(30)  NOT NULL DEFAULT 'Savings',
            `swift_code`   VARCHAR(20)  NULL,
            `is_primary`   TINYINT(1)   NOT NULL DEFAULT 0,
            `created_at`   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_empbank_emp` (`employee_id`),
            FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($tables as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) {}
    }

    // Older installs may predate these columns
    hrEnsureColumn($pdo, 'employee_contacts',  'alt_phone',  'VARCHAR(50) NULL');
    hrEnsureColumn($pdo, 'employee_contacts',  'email',      'VARCHAR(150) NULL');
    hrEnsureColumn($pdo, 'employee_documents', 'expires_on', 'DATE NULL');
    hrEnsureColumn($pdo, 'employee_warnings',  'expires_on', 'DATE NULL');

    $_SESSION[$cacheKey] = 1;
}

/**
 * Add a column only if missing. Works on MySQL 8 as well as MariaDB.
 * Mirrors ensureColumn() in corrections.php but stands alone so the HR
 * module does not depend on the billing module being loaded.
 */
function hrEnsureColumn(PDO $pdo, string $table, string $column, string $definition): void {
    if (function_exists('ensureColumn')) {
        ensureColumn($pdo, $table, $column, $definition);
        return;
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
        );
        $stmt->execute([$table, $column]);
        if ((int)$stmt->fetchColumn() > 0) return;
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    } catch (PDOException $e) {
        // Non-fatal
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   CONTRACTS
   ═══════════════════════════════════════════════════════════════════════ */

/** Every contract for an employee, newest first. */
function getEmployeeContracts(PDO $pdo, string $employeeId): array {
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM employee_contracts WHERE employee_id = ?
             ORDER BY start_date DESC, created_at DESC"
        );
        $stmt->execute([$employeeId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** The contract currently in force, or null. */
function getActiveContract(PDO $pdo, string $employeeId): ?array {
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM employee_contracts WHERE employee_id = ? AND status = 'Active'
             ORDER BY start_date DESC LIMIT 1"
        );
        $stmt->execute([$employeeId]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Days until a contract ends. Negative once it has lapsed, null for an
 * open-ended engagement.
 */
function contractDaysRemaining(?string $endDate): ?int {
    if (!$endDate) return null;
    $end = strtotime($endDate);
    if (!$end) return null;
    return (int)floor(($end - strtotime('today')) / 86400);
}

/**
 * How a contract's remaining time should read on screen.
 * Returns ['label', 'tone'] where tone is one of: ok | warn | urgent | expired.
 */
function contractExpiryState(?string $endDate): array {
    $days = contractDaysRemaining($endDate);

    if ($days === null)  return ['label' => 'Open-ended', 'tone' => 'ok'];
    if ($days < 0)       return ['label' => abs($days) . 'd overdue', 'tone' => 'expired'];
    if ($days === 0)     return ['label' => 'Ends today',  'tone' => 'urgent'];
    if ($days <= 30)     return ['label' => $days . 'd left', 'tone' => 'urgent'];
    if ($days <= 60)     return ['label' => $days . 'd left', 'tone' => 'warn'];

    return ['label' => $days . 'd left', 'tone' => 'ok'];
}

/**
 * Contracts approaching their end date or already past it, across all staff.
 * This is what drives the expiry warnings on the HR dashboard.
 *
 * @param int $withinDays look-ahead window (default 60)
 */
function expiringContracts(PDO $pdo, int $withinDays = 60): array {
    // The cutoff is worked out here rather than in SQL so the query stays
    // portable — no DATE_ADD/INTERVAL dialect to trip over.
    $cutoff = date('Y-m-d', strtotime("+{$withinDays} days"));

    try {
        $stmt = $pdo->prepare("
            SELECT c.*, e.full_name, e.staff_no, e.status AS employee_status
            FROM employee_contracts c
            JOIN employees e ON e.id = c.employee_id
            WHERE c.status = 'Active'
              AND c.end_date IS NOT NULL
              AND c.end_date <= ?
              AND (e.status IS NULL OR e.status <> 'Terminated')
            ORDER BY c.end_date ASC
        ");
        $stmt->execute([$cutoff]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Mark contracts whose end date has passed as Expired.
 * Cheap enough to run on an HR page load, and keeps the register honest
 * without needing a cron job.
 */
function expireLapsedContracts(PDO $pdo): int {
    try {
        $stmt = $pdo->prepare(
            "UPDATE employee_contracts SET status = 'Expired'
             WHERE status = 'Active' AND end_date IS NOT NULL AND end_date < ?"
        );
        $stmt->execute([date('Y-m-d')]);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        return 0;
    }
}

/** Does this employment status need an end date? */
function isFixedTerm(string $employmentStatus): bool {
    return in_array($employmentStatus, HR_FIXED_TERM_STATUSES, true);
}

/* ═══════════════════════════════════════════════════════════════════════
   COMPLETENESS
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Which onboarding details are still missing for an employee.
 * Registration captures everything in one pass, but records created before
 * that — or rushed through — need somewhere the gaps are visible.
 *
 * @return array<int,string> human-readable list of what is outstanding
 */
function employeeMissingDetails(PDO $pdo, array $employee): array {
    $missing = [];

    $required = [
        'staff_no'         => 'Staff number',
        'id_number'        => 'National ID number',
        'phone'            => 'Phone number',
        'date_of_birth'    => 'Date of birth',
        'physical_address' => 'Physical address',
        'hometown'         => 'Hometown',
    ];
    foreach ($required as $field => $label) {
        if (trim((string)($employee[$field] ?? '')) === '') $missing[] = $label;
    }

    $empId = (string)$employee['id'];

    try {
        $kin = $pdo->prepare("SELECT COUNT(*) FROM employee_contacts WHERE employee_id = ? AND is_next_of_kin = 1");
        $kin->execute([$empId]);
        if ((int)$kin->fetchColumn() === 0) $missing[] = 'Next of kin';

        $docs = $pdo->prepare("SELECT doc_type FROM employee_documents WHERE employee_id = ?");
        $docs->execute([$empId]);
        $have = $docs->fetchAll(PDO::FETCH_COLUMN);

        if (!in_array('id_copy', $have, true))   $missing[] = 'ID copy';
        if (!in_array('agreement', $have, true)) $missing[] = 'Signed agreement';

        $bank = $pdo->prepare("SELECT COUNT(*) FROM employee_bank_details WHERE employee_id = ?");
        $bank->execute([$empId]);
        if ((int)$bank->fetchColumn() === 0) $missing[] = 'Bank details';
    } catch (PDOException $e) {
        // A missing table simply means nothing extra to report
    }

    if (isFixedTerm((string)($employee['employment_status'] ?? '')) && empty($employee['contract_end_date'])) {
        $missing[] = 'Contract end date';
    }

    return $missing;
}
