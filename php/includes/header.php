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
    'reports.php'            => ['Reports & Analytics', 'Financials',      'financials.php'],
    'landlords.php'          => ['Landlords Registry',  'Dashboard',       'dashboard.php'],
    'tokens.php'             => ['Utility Tokens',      'Dashboard',       'dashboard.php'],
    'documents.php'          => ['Documents',           'Dashboard',       'dashboard.php'],
    'users.php'              => ['User Management',     'Dashboard',       'dashboard.php'],
    'audit_log.php'          => ['Audit Log',           'Dashboard',       'dashboard.php'],
    'settings.php'           => ['Settings',            'Dashboard',       'dashboard.php'],
    'hr.php'                 => ['HR & Personnel',      'Dashboard',       'dashboard.php'],
    'profile.php'            => ['My Profile',          'Dashboard',       'dashboard.php'],
    'notifications.php'      => ['Notifications',       'Dashboard',       'dashboard.php'],
    'vacancies.php'          => ['Vacancy Forecasting',  'Leases',          'leases.php'],
    'command_center.php'     => ['Command Center',       'Dashboard',       'dashboard.php'],
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
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: { 'accent-green': '#22c55e' },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        heading: ['Outfit', 'sans-serif'],
                    }
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
<body class="bg-slate-50 dark:bg-slate-950 text-slate-900 dark:text-slate-50 min-h-screen font-sans antialiased selection:bg-green-300/30">

<!-- ===== MOBILE DRAWER ===== -->
<div class="mobile-drawer" id="mobileDrawer" onclick="closeMobileDrawer(event)">
    <div class="drawer-overlay"></div>
    <div class="drawer-panel" id="drawerPanel">
        <div class="flex items-center gap-3 mb-8 px-2">
            <div class="w-9 h-9 bg-accent-green rounded-xl flex items-center justify-center text-slate-900 shadow-lg">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            </div>
            <div>
                <h1 class="text-[16px] font-black tracking-tight text-slate-900 dark:text-white leading-none">PRIMELINK</h1>
                <p class="text-[8px] font-black text-accent-green uppercase tracking-[0.22em]">Management</p>
            </div>
        </div>
        <?php include __DIR__ . '/sidebar_nav.php'; ?>
    </div>
</div>

<div class="flex min-h-screen">
