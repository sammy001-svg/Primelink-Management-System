<?php
require_once __DIR__ . '/permissions.php';

$current_page = basename($_SERVER['PHP_SELF']);
$user_role    = $_SESSION['role'] ?? 'tenant';
$_isAdmin     = $user_role === 'admin';

$nav_sections = [];

// ─── TENANT ──────────────────────────────────────────────
if ($user_role === 'tenant') {
    $nav_sections[] = ['title' => 'My Account', 'links' => [
        ['href' => 'dashboard.php',      'label' => 'Dashboard',      'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>'],
        ['href' => 'my_invoices.php',    'label' => 'My Invoices',    'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" x2="10"/></svg>'],
        ['href' => 'my_payments.php',    'label' => 'My Payments',    'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>'],
        ['href' => 'view_statement.php', 'label' => 'My Statement',   'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>'],
        ['href' => 'leases.php',         'label' => 'My Lease',       'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'],
        ['href' => 'maintenance.php',    'label' => 'Maintenance',    'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>'],
        ['href' => 'tokens.php',         'label' => 'Utility Tokens', 'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>'],
    ]];
    $nav_sections[] = ['title' => 'Account', 'links' => [
        ['href' => 'profile.php',        'label' => 'My Profile',     'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-8 8-8s8 4 8 8"/></svg>'],
        ['href' => 'notifications.php',  'label' => 'Notifications',  'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>'],
    ]];

// ─── LANDLORD ─────────────────────────────────────────────
} elseif ($user_role === 'landlord') {
    $nav_sections[] = ['title' => 'My Portfolio', 'links' => [
        ['href' => 'dashboard.php',        'label' => 'Dashboard',      'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>'],
        ['href' => 'properties.php',       'label' => 'My Properties',  'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>'],
        ['href' => 'landlord_tenants.php', 'label' => 'My Tenants',     'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>'],
        ['href' => 'maintenance.php',      'label' => 'Maintenance',    'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>'],
        ['href' => 'financials.php',       'label' => 'Income',         'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>'],
        ['href' => 'landlord_statement.php','label' => 'My Statement',  'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>'],
        ['href' => 'leases.php',           'label' => 'Leases',         'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'],
        ['href' => 'tokens.php',           'label' => 'Utility Tokens', 'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>'],
    ]];
    $nav_sections[] = ['title' => 'Account', 'links' => [
        ['href' => 'profile.php',      'label' => 'My Profile',    'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-8 8-8s8 4 8 8"/></svg>'],
        ['href' => 'notifications.php','label' => 'Notifications', 'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>'],
    ]];

// ─── ADMIN / STAFF ────────────────────────────────────────
} else {

    // Helper: only add link if user has view permission for that module (or no module = always show)
    $addLink = function(array &$links, array $link) use ($pdo): void {
        $mod = $link['module'] ?? null;
        if ($mod && !canDo($pdo, $mod, 'view')) return;
        unset($link['module']);
        $links[] = $link;
    };

    // ── Overview ──────────────────────────────────────────
    $ovLinks = [];
    $addLink($ovLinks, ['href' => 'dashboard.php',      'label' => 'Dashboard',      'module' => 'dashboard',      'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>']);
    $addLink($ovLinks, ['href' => 'command_center.php', 'label' => 'Command Center', 'module' => 'command_center', 'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v4M12 19v4M4.22 4.22l2.83 2.83M16.95 16.95l2.83 2.83M1 12h4M19 12h4M4.22 19.78l2.83-2.83M16.95 7.05l2.83-2.83"/></svg>']);
    if ($ovLinks) $nav_sections[] = ['title' => 'Overview', 'links' => $ovLinks];

    // ── Property Management ───────────────────────────────
    $pmLinks = [];
    $addLink($pmLinks, ['href' => 'properties.php', 'label' => 'Properties', 'module' => 'properties', 'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>']);
    $addLink($pmLinks, ['href' => 'tenants.php',    'label' => 'Tenants',    'module' => 'tenants',    'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>']);
    $addLink($pmLinks, ['href' => 'leases.php',     'label' => 'Leases',     'module' => 'leases',     'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>']);
    $addLink($pmLinks, ['href' => 'vacancies.php',  'label' => 'Vacancies',  'module' => 'vacancies',  'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/><line x1="9" y1="21" x2="9" y2="10"/></svg>']);
    if ($pmLinks) $nav_sections[] = ['title' => 'Property Management', 'links' => $pmLinks];

    // ── Operations ────────────────────────────────────────
    $opLinks = [];
    $addLink($opLinks, ['href' => 'maintenance.php', 'label' => 'Maintenance',    'module' => 'maintenance', 'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>']);
    $addLink($opLinks, ['href' => 'tokens.php',      'label' => 'Utility Tokens', 'module' => 'tokens',      'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>']);
    $addLink($opLinks, ['href' => 'documents.php',   'label' => 'Documents',      'module' => 'documents',   'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>']);
    if ($opLinks) $nav_sections[] = ['title' => 'Operations', 'links' => $opLinks];

    // ── Finance ───────────────────────────────────────────
    $finSubs = [];
    $addSubLink = function(array &$subs, string $href, string $label, string $mod) use ($pdo): void {
        if (canDo($pdo, $mod, 'view')) $subs[] = ['href' => $href, 'label' => $label];
    };
    $addSubLink($finSubs, 'financials.php',       'Overview',                'financials');
    $addSubLink($finSubs, 'tenant_payments.php',  'Tenants & Invoices',      'invoices');
    $addSubLink($finSubs, 'bulk_invoices.php',    'Bulk Invoice Generator',  'bulk_invoices');
    $addSubLink($finSubs, 'late_penalties.php',   'Late Penalties',          'late_penalties');
    $addSubLink($finSubs, 'landlord_payouts.php', 'Landlords & Payouts',     'payouts');
    $addSubLink($finSubs, 'expenses.php',         'Business Expenses',       'expenses');
    $addSubLink($finSubs, 'journals.php',         'Journal Entries',         'journals');
    $addSubLink($finSubs, 'accounts.php',         'Chart of Accounts',       'accounts');
    $addSubLink($finSubs, 'reports.php',          'Reports & Analytics',     'reports');
    if ($finSubs) {
        $nav_sections[] = ['title' => 'Finance', 'links' => [[
            'label'     => 'Financials',
            'icon'      => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
            'sub_links' => $finSubs,
        ]]];
    }

    // ── Administration ────────────────────────────────────
    $adLinks = [];
    $addLink($adLinks, ['href' => 'landlords.php', 'label' => 'Landlords Registry', 'module' => 'landlords', 'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M3 7V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2"/><path d="M5 21V7"/><path d="M19 21V7"/><path d="M9 21v-4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v4"/></svg>']);
    $addLink($adLinks, ['href' => 'hr.php',        'label' => 'HR & Personnel',     'module' => 'hr',        'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>']);
    $addLink($adLinks, ['href' => 'payroll.php',   'label' => 'Payroll',            'module' => 'payroll',   'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>']);
    // Admin-only links
    if ($_isAdmin) {
        $adLinks[] = ['href' => 'users.php',       'label' => 'Users',              'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/><path d="M19 8a3 3 0 0 1 0 6"/><path d="M21 20c0-3-2-5.5-5-6.3"/></svg>'];
        $adLinks[] = ['href' => 'permissions.php', 'label' => 'Staff Permissions',  'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>'];
        $adLinks[] = ['href' => 'audit_log.php',   'label' => 'Audit Log',          'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>'];
        $adLinks[] = ['href' => 'settings.php',    'label' => 'Settings',           'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>'];
    }
    if ($adLinks) $nav_sections[] = ['title' => 'Administration', 'links' => $adLinks];

    // ── Communication ─────────────────────────────────────
    $comLinks = [];
    $addLink($comLinks, ['href' => 'announcements.php', 'label' => 'Announcements', 'module' => 'announcements', 'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.18h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.77a16 16 0 0 0 6.29 6.29l.77-.86a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.73 16.92z"/></svg>']);
    $comLinks[] = ['href' => 'notifications.php', 'label' => 'Notifications', 'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>'];
    if ($comLinks) $nav_sections[] = ['title' => 'Communication', 'links' => $comLinks];

    // ── My Account ────────────────────────────────────────
    $nav_sections[] = ['title' => 'My Account', 'links' => [
        ['href' => 'profile.php', 'label' => 'My Profile', 'icon' => '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-8 8-8s8 4 8 8"/></svg>'],
    ]];
}
?>

<?php foreach ($nav_sections as $section): ?>
<div class="mb-5">
    <p class="nav-section-title text-[9px] font-black text-slate-400 uppercase tracking-[0.15em] px-3 mb-1.5"><?php echo $section['title']; ?></p>
    <div class="space-y-0.5">
        <?php foreach ($section['links'] as $link):
            $isActive     = isset($link['href']) && $current_page === $link['href'];
            $hasSubLinks  = isset($link['sub_links']);
            $isParentActive = false;
            if ($hasSubLinks) {
                foreach ($link['sub_links'] as $sub) {
                    if ($current_page === $sub['href']) { $isParentActive = true; break; }
                }
            }
        ?>
        <div>
            <?php if ($hasSubLinks): ?>
                <button onclick="toggleSubMenu(this)"
                    class="w-full sidebar-link <?php echo $isParentActive ? 'active' : ''; ?> flex justify-between items-center"
                    data-label="<?php echo htmlspecialchars($link['label']); ?>">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="sidebar-icon-wrap <?php echo $isParentActive ? 'text-white dark:text-slate-900' : 'text-slate-400'; ?>"><?php echo $link['icon']; ?></span>
                        <span class="nav-label truncate"><?php echo $link['label']; ?></span>
                    </div>
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" class="sidebar-submenu-caret shrink-0 transition-transform <?php echo $isParentActive ? 'rotate-180' : ''; ?>"><path d="m6 9 6 6 6-6"/></svg>
                </button>
                <div class="sidebar-sub-links <?php echo $isParentActive ? '' : 'hidden'; ?> pl-[43px] pr-2 space-y-0.5 pt-1 pb-1">
                    <?php foreach ($link['sub_links'] as $sub):
                        $isSubActive = $current_page === $sub['href'];
                    ?>
                        <a href="<?php echo $sub['href']; ?>"
                           class="block py-2 px-3 text-[12px] font-bold rounded-lg transition-colors <?php echo $isSubActive ? 'text-accent-green bg-green-50 dark:bg-green-900/10' : 'text-slate-500 hover:text-slate-900 dark:hover:text-white hover:bg-slate-50 dark:hover:bg-slate-800/50'; ?>">
                            <?php echo $sub['label']; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <a href="<?php echo $link['href']; ?>"
                   class="sidebar-link <?php echo $isActive ? 'active' : ''; ?>"
                   data-label="<?php echo htmlspecialchars($link['label']); ?>">
                    <span class="sidebar-icon-wrap <?php echo $isActive ? 'text-white dark:text-slate-900' : 'text-slate-400'; ?>"><?php echo $link['icon']; ?></span>
                    <span class="nav-label"><?php echo $link['label']; ?></span>
                </a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
