<?php
/**
 * Staff Permissions — Primelink Management System
 * Admins always have full access.
 * Staff access is controlled per-module via the admin Permissions page.
 */

// Module definitions: key => available actions
const PERM_MODULES = [
    'dashboard'      => ['view'],
    'command_center' => ['view'],
    'properties'     => ['view', 'create', 'edit', 'delete'],
    'tenants'        => ['view', 'create', 'edit', 'delete'],
    'leases'         => ['view', 'create', 'edit', 'delete'],
    'vacancies'      => ['view'],
    'maintenance'    => ['view', 'create', 'edit', 'delete'],
    'tokens'         => ['view', 'create'],
    'documents'      => ['view', 'create', 'delete'],
    'financials'     => ['view'],
    'invoices'       => ['view', 'create', 'edit', 'delete'],
    'bulk_invoices'  => ['view', 'create'],
    'late_penalties' => ['view', 'create'],
    'payments'       => ['view', 'create', 'edit'],
    'payouts'        => ['view', 'create'],
    'expenses'       => ['view', 'create', 'edit', 'delete'],
    'journals'       => ['view', 'create', 'edit'],
    'accounts'       => ['view', 'create', 'edit'],
    'reports'        => ['view'],
    'landlords'      => ['view', 'create', 'edit', 'delete'],
    'hr'             => ['view', 'create', 'edit', 'delete'],
    'payroll'        => ['view', 'create', 'edit'],
    'leave'          => ['view', 'create', 'edit'],
    'announcements'  => ['view', 'create', 'edit', 'delete'],
];

// Human-readable labels per module (for the admin UI)
const PERM_MODULE_LABELS = [
    'dashboard'      => 'Dashboard',
    'command_center' => 'Command Center',
    'properties'     => 'Properties',
    'tenants'        => 'Tenants',
    'leases'         => 'Leases',
    'vacancies'      => 'Vacancy Forecasting',
    'maintenance'    => 'Maintenance',
    'tokens'         => 'Utility Tokens',
    'documents'      => 'Documents',
    'financials'     => 'Financials Overview',
    'invoices'       => 'Invoices & Billing',
    'bulk_invoices'  => 'Bulk Invoice Generator',
    'late_penalties' => 'Late Penalties',
    'payments'       => 'Payments',
    'payouts'        => 'Landlord Payouts',
    'expenses'       => 'Business Expenses',
    'journals'       => 'Journal Entries',
    'accounts'       => 'Chart of Accounts',
    'reports'        => 'Reports & Analytics',
    'landlords'      => 'Landlords Registry',
    'hr'             => 'HR & Personnel',
    'payroll'        => 'Payroll',
    'leave'          => 'Leave Management',
    'announcements'  => 'Announcements',
];

// Sidebar section grouping (used for the permissions matrix display)
const PERM_GROUPS = [
    'Overview'             => ['dashboard', 'command_center'],
    'Property Management'  => ['properties', 'tenants', 'leases', 'vacancies'],
    'Operations'           => ['maintenance', 'tokens', 'documents'],
    'Finance'              => ['financials', 'invoices', 'bulk_invoices', 'late_penalties', 'payments', 'payouts', 'expenses', 'journals', 'accounts', 'reports'],
    'Administration'       => ['landlords', 'hr', 'payroll', 'leave', 'announcements'],
];

// Map: sidebar href → permission module key
const PERM_PAGE_MAP = [
    'dashboard.php'         => 'dashboard',
    'command_center.php'    => 'command_center',
    'properties.php'        => 'properties',
    'property_details.php'  => 'properties',
    'tenants.php'           => 'tenants',
    'tenant_details.php'    => 'tenants',
    'leases.php'            => 'leases',
    'view_lease.php'        => 'leases',
    'vacancies.php'         => 'vacancies',
    'maintenance.php'       => 'maintenance',
    'tokens.php'            => 'tokens',
    'documents.php'         => 'documents',
    'financials.php'        => 'financials',
    'tenant_payments.php'   => 'invoices',
    'invoices.php'          => 'invoices',
    'corrections_register.php' => 'invoices',
    'bulk_invoices.php'     => 'bulk_invoices',
    'late_penalties.php'    => 'late_penalties',
    'landlord_payouts.php'  => 'payouts',
    'expenses.php'          => 'expenses',
    'journals.php'          => 'journals',
    'journal_entry.php'     => 'journals',
    'accounts.php'          => 'accounts',
    'reports.php'           => 'reports',
    'landlords.php'         => 'landlords',
    'hr.php'                => 'hr',
    'hr_employee.php'       => 'hr',
    'payroll.php'           => 'payroll',
    'payroll_period.php'    => 'payroll',
    'leave.php'             => 'leave',
    'announcements.php'     => 'announcements',
];

/**
 * Self-heal the staff_permissions table.
 */
function _ensurePermissionsTable(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS staff_permissions (
            user_id    VARCHAR(36) NOT NULL PRIMARY KEY,
            permissions JSON NOT NULL,
            updated_by  VARCHAR(36) NULL,
            updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {}
    $done = true;
}

/**
 * Load and cache permissions for the current session user.
 * Returns null  → no record set; treat as FULL ACCESS (unrestricted staff or admin).
 * Returns array → explicit grant map. Keys are "module.action", values are truthy.
 */
function loadPermissions(PDO $pdo): ?array {
    if (($_SESSION['role'] ?? '') === 'admin') return null;

    // Already cached in session (null is a valid cached value, so use array_key_exists)
    if (array_key_exists('_perms', $_SESSION)) return $_SESSION['_perms'];

    _ensurePermissionsTable($pdo);

    $stmt = $pdo->prepare("SELECT permissions FROM staff_permissions WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id'] ?? '']);
    $row = $stmt->fetch();

    if (!$row) {
        $_SESSION['_perms'] = null; // no explicit restrictions → full access
        return null;
    }

    $perms = json_decode($row['permissions'], true) ?? [];
    $_SESSION['_perms'] = $perms;
    return $perms;
}

/**
 * Check if the current user can perform $action on $module.
 * Admins and unrestricted staff (no record) → always true.
 */
function canDo(PDO $pdo, string $module, string $action = 'view'): bool {
    $perms = loadPermissions($pdo);
    if ($perms === null) return true;
    return !empty($perms["{$module}.{$action}"]);
}

/**
 * Require a permission or render a 403 and exit.
 */
function requirePermission(PDO $pdo, string $module, string $action = 'view'): void {
    if (canDo($pdo, $module, $action)) return;

    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
        <title>Access Denied — Primelink</title>
        <script src="https://cdn.tailwindcss.com"></script></head>
        <body class="min-h-screen bg-slate-950 flex items-center justify-center p-8 font-sans">
        <div class="text-center max-w-sm">
            <div class="text-6xl font-black text-red-500 mb-4">403</div>
            <p class="text-xl font-black text-white mb-2">Access Denied</p>
            <p class="text-slate-400 font-medium mb-8">You don\'t have permission to access this section. Contact your administrator.</p>
            <a href="dashboard.php" class="inline-block px-6 py-3 bg-green-500 text-white rounded-2xl font-black text-sm uppercase tracking-widest hover:bg-green-600 transition-all">← Back to Dashboard</a>
        </div></body></html>';
    exit();
}

/**
 * Clear the permission cache from the session (call after updating permissions).
 */
function clearPermissionCache(): void {
    unset($_SESSION['_perms']);
}
