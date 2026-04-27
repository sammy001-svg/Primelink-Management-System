<?php
// Shared sidebar nav links - used in both desktop sidebar and mobile drawer
$current_page = basename($_SERVER['PHP_SELF']);
$user_role = $_SESSION['role'] ?? 'tenant';

$nav_sections = [];

// ─── TENANT ────────────────────────────────────────────
if ($user_role === 'tenant') {
    $nav_sections[] = ['title' => 'My Account', 'links' => [
        ['href' => 'dashboard.php',    'label' => 'Dashboard',    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>'],
        ['href' => 'leases.php',       'label' => 'My Lease',     'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'],
        ['href' => 'financials.php',   'label' => 'Payments',     'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>'],
        ['href' => 'maintenance.php',  'label' => 'Maintenance',  'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>'],
        ['href' => 'tokens.php',       'label' => 'Utility Tokens','icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z" stroke-opacity="0.3"/></svg>'],
        ['href' => 'profile.php',      'label' => 'My Profile',   'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-8 8-8s8 4 8 8"/></svg>'],
    ]];

// ─── LANDLORD ──────────────────────────────────────────
} elseif ($user_role === 'landlord') {
    $nav_sections[] = ['title' => 'My Portfolio', 'links' => [
        ['href' => 'dashboard.php',   'label' => 'Dashboard',     'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>'],
        ['href' => 'properties.php',  'label' => 'My Properties', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>'],
        ['href' => 'tenants.php',     'label' => 'Tenants',       'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>'],
        ['href' => 'maintenance.php', 'label' => 'Maintenance',   'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>'],
        ['href' => 'financials.php',  'label' => 'Income',        'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>'],
        ['href' => 'leases.php',      'label' => 'Leases',        'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'],
        ['href' => 'tokens.php',      'label' => 'Utility Tokens','icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z" stroke-opacity="0.3"/></svg>'],
    ]];
    $nav_sections[] = ['title' => 'Account', 'links' => [
        ['href' => 'profile.php',     'label' => 'My Profile',    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-8 8-8s8 4 8 8"/></svg>'],
        ['href' => 'notifications.php','label'=> 'Notifications', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>'],
    ]];

// ─── ADMIN / STAFF ─────────────────────────────────────
} else {
    $main_links = [
        ['href' => 'dashboard.php',    'label' => 'Dashboard',    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>'],
        ['href' => 'properties.php',   'label' => 'Properties',   'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>'],
        ['href' => 'tenants.php',      'label' => 'Tenants List', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'],
        ['href' => 'maintenance.php',  'label' => 'Maintenance',  'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>'],
        [
            'label' => 'Financials', 
            'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
            'sub_links' => [
                ['href' => 'financials.php',   'label' => 'Overview'],
                ['href' => 'landlord_payouts.php', 'label' => 'Landlords & Advances'],
                ['href' => 'tenant_payments.php', 'label' => 'Tenants & Invoices'],
                ['href' => 'expenses.php',      'label' => 'Business Expenses'],
                ['href' => 'reports.php',       'label' => 'Financial Reports'],
            ]
        ],
        ['href' => 'leases.php',       'label' => 'Leases',       'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'],
        ['href' => 'tokens.php',       'label' => 'Utility Tokens','icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z" stroke-opacity="0.3"/></svg>'],
        ['href' => 'documents.php',    'label' => 'Documents',    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>'],
    ];
    $nav_sections[] = ['title' => 'Main Menu', 'links' => $main_links];

    $admin_links = [
        ['href' => 'landlords.php',    'label' => 'Landlords Registry', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M3 7V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2"/><path d="M5 21V7"/><path d="M19 21V7"/><path d="M9 21v-4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v4"/></svg>'],
        ['href' => 'hr.php',           'label' => 'HR & Personnel','icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'],
    ];
    $nav_sections[] = ['title' => 'Admin', 'links' => $admin_links];

    $nav_sections[] = ['title' => 'Account', 'links' => [
        ['href' => 'profile.php',       'label' => 'My Profile',    'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-8 8-8s8 4 8 8"/></svg>'],
        ['href' => 'notifications.php', 'label' => 'Notifications', 'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>'],
    ]];
}
?>

<?php foreach ($nav_sections as $section): ?>
<div class="mb-6">
    <p class="text-[9px] font-black text-slate-400 uppercase tracking-[0.15em] px-4 mb-2"><?php echo $section['title']; ?></p>
    <div class="space-y-1">
        <?php foreach ($section['links'] as $link):
            $isActive = isset($link['href']) && $current_page === $link['href'];
            $hasSubLinks = isset($link['sub_links']);
            $isParentActive = false;
            
            if ($hasSubLinks) {
                foreach ($link['sub_links'] as $sub) {
                    if ($current_page === $sub['href']) {
                        $isParentActive = true;
                        break;
                    }
                }
            }
        ?>
        <div class="space-y-1" 
             <?php if ($hasSubLinks): ?> 
                onmouseenter="toggleSubMenu('menu-<?php echo md5($link['label']); ?>', true)" 
                onmouseleave="toggleSubMenu('menu-<?php echo md5($link['label']); ?>', false)" 
             <?php endif; ?>>
            <?php if ($hasSubLinks): ?>
                <button onclick="toggleSubMenu('menu-<?php echo md5($link['label']); ?>')" class="w-full sidebar-link <?php echo $isParentActive ? 'active' : ''; ?> flex justify-between items-center group">
                    <div class="flex items-center gap-3">
                        <span class="sidebar-icon-wrap <?php echo $isParentActive ? 'text-white dark:text-slate-900' : 'text-slate-400'; ?>"><?php echo $link['icon']; ?></span>
                        <?php echo $link['label']; ?>
                    </div>
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" class="transition-transform <?php echo $isParentActive ? 'rotate-180' : ''; ?>"><path d="m6 9 6 6 6-6"/></svg>
                </button>
                <div id="menu-<?php echo md5($link['label']); ?>" class="<?php echo $isParentActive ? '' : 'hidden'; ?> pl-11 pr-4 space-y-1 py-1">
                    <?php foreach ($link['sub_links'] as $sub):
                        $isSubActive = $current_page === $sub['href'];
                    ?>
                        <a href="<?php echo $sub['href']; ?>" class="block py-2 text-xs font-bold <?php echo $isSubActive ? 'text-accent-green' : 'text-slate-500 hover:text-slate-900 dark:hover:text-white'; ?> transition-colors">
                            <?php echo $sub['label']; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <a href="<?php echo $link['href']; ?>" class="sidebar-link <?php echo $isActive ? 'active' : ''; ?>">
                    <span class="sidebar-icon-wrap <?php echo $isActive ? 'text-white dark:text-slate-900' : 'text-slate-400'; ?>"><?php echo $link['icon']; ?></span>
                    <?php echo $link['label']; ?>
                </a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
