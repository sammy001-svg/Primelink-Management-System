<?php
require_once __DIR__ . '/auth.php';
$user        = getCurrentUser($pdo);
$userName    = $user['full_name'] ?? ($_SESSION['email'] ?? 'User');
$userInitial = strtoupper(substr($userName, 0, 1));
$userRole    = $_SESSION['role'] ?? 'tenant';

// Breadcrumb map: page => [label, parent-label, parent-href]
$_breadcrumbMap = [
    'dashboard.php'          => ['Dashboard',           null,              null],
    'properties.php'         => ['Properties',          'Dashboard',       'dashboard.php'],
    'property_details.php'   => ['Property Details',    'Properties',      'properties.php'],
    'tenants.php'            => ['Tenants',             'Dashboard',       'dashboard.php'],
    'tenant_details.php'     => ['Tenant Details',      'Tenants',         'tenants.php'],
    'leases.php'             => ['Leases',              'Dashboard',       'dashboard.php'],
    'view_lease.php'         => ['Lease Details',       'Leases',          'leases.php'],
    'maintenance.php'        => ['Maintenance',         'Dashboard',       'dashboard.php'],
    'financials.php'         => ['Financials',          'Dashboard',       'dashboard.php'],
    'tenant_payments.php'    => ['Tenant Payments',     'Financials',      'financials.php'],
    'landlord_payouts.php'   => ['Landlord Payouts',    'Financials',      'financials.php'],
    'expenses.php'           => ['Expenses',            'Financials',      'financials.php'],
    'journals.php'           => ['Journal Entries',     'Financials',      'financials.php'],
    'accounts.php'           => ['Chart of Accounts',   'Financials',      'financials.php'],
    'journal_entry.php'      => ['Journal Entry',       'Journal Entries', 'journals.php'],
    'reports.php'            => ['Reports & Analytics', 'Financials',      'financials.php'],
    'landlords.php'          => ['Landlords Registry',  'Dashboard',       'dashboard.php'],
    'tokens.php'             => ['Utility Tokens',      'Dashboard',       'dashboard.php'],
    'documents.php'          => ['Documents',           'Dashboard',       'dashboard.php'],
    'users.php'              => ['User Management',     'Dashboard',       'dashboard.php'],
    'permissions.php'        => ['Staff Permissions',   'User Management', 'users.php'],
    'audit_log.php'          => ['Audit Log',           'Dashboard',       'dashboard.php'],
    'settings.php'           => ['Settings',            'Dashboard',       'dashboard.php'],
    'hr.php'                 => ['HR & Personnel',      'Dashboard',       'dashboard.php'],
    'hr_employee.php'        => ['Employee Profile',    'HR & Personnel',  'hr.php'],
    'payroll.php'            => ['Payroll',             'Dashboard',       'dashboard.php'],
    'payroll_period.php'     => ['Payroll Period',      'Payroll',         'payroll.php'],
    'payslip.php'            => ['Payslip',             'Payroll',         'payroll.php'],
    'p9.php'                 => ['P9 Tax Form',         'Payroll',         'payroll.php'],
    'leave.php'              => ['Leave Management',    'Dashboard',       'dashboard.php'],
    'profile.php'            => ['My Profile',          'Dashboard',       'dashboard.php'],
    'notifications.php'      => ['Notifications',       'Dashboard',       'dashboard.php'],
    'vacancies.php'          => ['Vacancy Forecasting',  'Leases',          'leases.php'],
    'command_center.php'     => ['Command Center',       'Dashboard',       'dashboard.php'],
    'announcements.php'      => ['Announcements',         'Dashboard',       'dashboard.php'],
    'late_penalties.php'     => ['Late Penalties',        'Tenant Payments', 'tenant_payments.php'],
    'bulk_invoices.php'      => ['Bulk Invoice Generator','Tenant Payments', 'tenant_payments.php'],
    'view_statement.php'     => ['Tenant Statement',    'Financials',      'financials.php'],
    'landlord_statement.php' => ['My Statement',        'Dashboard',       'dashboard.php'],
    'landlord_tenants.php'   => ['My Tenants',          'Dashboard',       'dashboard.php'],
];
$_curPage   = basename($_SERVER['PHP_SELF']);
$_crumb     = $_breadcrumbMap[$_curPage] ?? [isset($pageTitle) ? $pageTitle : ucfirst(str_replace(['.php','_'], ['',' '], $_curPage)), null, null];
?>
<!DOCTYPE html>
<html lang="en" class="dark" id="html-root">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) . " | Primelink" : "Primelink Management System"; ?></title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="<?php echo str_repeat('../', substr_count(basename($_SERVER['PHP_SELF']), '/')) ?>css/style.css">
    <script>
        // The page markup expresses *intent* ("this is emphasised"); the design
        // system decides what emphasis actually looks like. Retuning the scale
        // here calms ~2,400 font-black and ~1,300 tracking-widest utilities at
        // once, instead of editing every page. 900-weight text everywhere was
        // the main reason the old UI had no hierarchy.
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        'accent-green':  '#16a34a',
                        'accent-orange': '#b45309',
                    },
                    fontFamily: {
                        sans:    ['Inter', 'system-ui', 'sans-serif'],
                        heading: ['Inter', 'system-ui', 'sans-serif'],
                    },
                    fontWeight: {
                        normal:    '400',
                        medium:    '500',
                        semibold:  '600',
                        bold:      '600',
                        extrabold: '600',
                        black:     '600',
                    },
                    letterSpacing: {
                        tight:   '-0.018em',
                        normal:  '0',
                        wide:    '0.012em',
                        wider:   '0.022em',
                        widest:  '0.035em',
                    },
                    borderRadius: {
                        lg:  '8px',
                        xl:  '9px',
                        '2xl': '10px',
                        '3xl': '12px',
                    },
                    boxShadow: {
                        sm:  '0 1px 2px rgba(16,24,40,0.04)',
                        DEFAULT: '0 1px 2px rgba(16,24,40,0.04), 0 1px 3px rgba(16,24,40,0.06)',
                        md:  '0 1px 2px rgba(16,24,40,0.04), 0 1px 3px rgba(16,24,40,0.06)',
                        lg:  '0 2px 6px rgba(16,24,40,0.06)',
                        xl:  '0 8px 20px -6px rgba(16,24,40,0.12)',
                        '2xl': '0 12px 32px -8px rgba(16,24,40,0.18)',
                    },
                }
            }
        };
        (function() {
            const saved = localStorage.getItem('theme');
            if (saved === 'light') document.documentElement.classList.remove('dark');
            else document.documentElement.classList.add('dark');
        })();
    </script>
</head>
<body class="min-h-screen font-sans antialiased">

<!-- ===== MOBILE DRAWER ===== -->
<div class="mobile-drawer" id="mobileDrawer" onclick="closeMobileDrawer(event)">
    <div class="drawer-overlay"></div>
    <div class="drawer-panel" id="drawerPanel">
        <div class="flex items-center gap-2.5 mb-5 px-1.5">
            <div class="w-7 h-7 bg-accent-green rounded-lg flex items-center justify-center text-white shrink-0">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            </div>
            <div class="leading-tight">
                <p class="text-[13.5px] font-semibold tracking-tight" style="color:var(--text)">Primelink</p>
                <p class="text-[10.5px]" style="color:var(--text-subtle)">Management</p>
            </div>
        </div>
        <?php include __DIR__ . '/sidebar_nav.php'; ?>
    </div>
</div>

<div class="flex min-h-screen">
