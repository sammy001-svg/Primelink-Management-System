<?php
/**
 * Tenant Management — Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$role = $_SESSION['role'] ?? 'tenant';
if ($role === 'tenant') { header('Location: dashboard.php'); exit(); }

$pageTitle = "Tenants";
$canManage = in_array($role, ['admin', 'staff']);

require_once __DIR__ . '/includes/settings.php';
$currency = getSetting($pdo, 'currency_symbol', 'KSh');

// ── Schema self-heal ──────────────────────────────────────────────
try { $pdo->query("SELECT monthly_rent FROM units LIMIT 1"); } catch (PDOException $e) {
    if ($e->getCode() == '42S22') {
        try { $pdo->exec("ALTER TABLE `units` CHANGE COLUMN `rent_amount` `monthly_rent` DECIMAL(15,2) NOT NULL DEFAULT 0"); }
        catch (Exception $ex) { $pdo->exec("ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `monthly_rent` DECIMAL(15,2) NOT NULL DEFAULT 0"); }
        $pdo->exec("ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `deposit_amount` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `monthly_rent`");
    }
}

// ── Auto-repair orphan tenants (admin/staff only) ─────────────────
if ($canManage) {
    $pdo->exec("
        INSERT IGNORE INTO tenants (id, user_id, full_name, email, phone, status, created_at)
        SELECT u.id, u.id, p.full_name, u.email, p.phone, 'Active', NOW()
        FROM users u JOIN profiles p ON u.id = p.id
        WHERE u.role = 'tenant'
        AND NOT EXISTS (SELECT 1 FROM tenants t WHERE t.user_id = u.id)
    ");
}

// ── Tenant list query with unit + overdue info ────────────────────
if ($role === 'landlord') {
    $landlordId = getLandlordId($pdo);
    $stmt = $pdo->prepare("
        SELECT t.*,
            l.id       AS lease_id,
            l.status   AS lease_status,
            l.end_date AS lease_end,
            u.unit_number, u.monthly_rent,
            pr.title   AS property_title,
            (SELECT COUNT(*) FROM invoices i WHERE i.tenant_id = t.id AND i.status = 'Overdue') AS overdue_count,
            (SELECT MAX(tr.transaction_date) FROM transactions tr WHERE tr.tenant_id = t.id AND tr.status = 'Paid') AS last_payment
        FROM tenants t
        LEFT JOIN leases l      ON l.tenant_id = t.id AND l.status = 'Active'
        LEFT JOIN units u       ON l.unit_id = u.id
        LEFT JOIN properties pr ON u.property_id = pr.id
        JOIN leases ls2   ON ls2.tenant_id = t.id AND ls2.status = 'Active'
        JOIN units un2    ON ls2.unit_id = un2.id
        JOIN properties p ON un2.property_id = p.id
        WHERE p.landlord_id = ?
        GROUP BY t.id ORDER BY t.created_at DESC
    ");
    $stmt->execute([$landlordId]);
} else {
    requireRole(['admin', 'staff']);
    $stmt = $pdo->query("
        SELECT t.*,
            l.id         AS lease_id,
            l.status     AS lease_status,
            l.start_date AS lease_start,
            l.end_date   AS lease_end,
            u.unit_number, u.monthly_rent,
            pr.id    AS property_id_fk,
            pr.title AS property_title,
            (SELECT COUNT(*) FROM invoices i WHERE i.tenant_id = t.id AND i.status = 'Overdue') AS overdue_count,
            (SELECT MAX(tr.transaction_date) FROM transactions tr WHERE tr.tenant_id = t.id AND tr.status = 'Paid') AS last_payment,
            COALESCE((SELECT SUM(i.amount) FROM invoices i WHERE i.tenant_id = t.id AND i.status NOT IN ('Paid','Cancelled')), 0) AS outstanding_balance
        FROM tenants t
        LEFT JOIN leases l      ON l.tenant_id = t.id AND l.status = 'Active'
        LEFT JOIN units u       ON l.unit_id = u.id
        LEFT JOIN properties pr ON u.property_id = pr.id
        ORDER BY t.created_at DESC
    ");
}
$tenants = $stmt->fetchAll();

// ── Panel data keyed by ID (for JS slide panel) ───────────────────
$tenantPanelData = [];
foreach ($tenants as $_pt) {
    $tenantPanelData[$_pt['id']] = [
        'id'                  => $_pt['id'],
        'full_name'           => $_pt['full_name']      ?? '',
        'email'               => $_pt['email']          ?? '',
        'phone'               => $_pt['phone']          ?? '',
        'status'              => $_pt['status']         ?? 'Active',
        'created_at'          => $_pt['created_at']     ?? '',
        'unit_number'         => $_pt['unit_number']    ?? null,
        'property_title'      => $_pt['property_title'] ?? null,
        'property_id_fk'      => $_pt['property_id_fk'] ?? null,
        'monthly_rent'        => (float)($_pt['monthly_rent'] ?? 0),
        'lease_id'            => $_pt['lease_id']       ?? null,
        'lease_start'         => $_pt['lease_start']    ?? null,
        'lease_end'           => $_pt['lease_end']      ?? null,
        'outstanding_balance' => (float)($_pt['outstanding_balance'] ?? 0),
        'overdue_count'       => (int)($_pt['overdue_count']  ?? 0),
    ];
}

// ── KPI counts ───────────────────────────────────────────────────
$kpiTotal    = count($tenants);
$kpiActive   = count(array_filter($tenants, fn($t) => ($t['status'] ?? '') === 'Active'));
$kpiInactive = $kpiTotal - $kpiActive;
$kpiOverdue  = count(array_filter($tenants, fn($t) => ($t['overdue_count'] ?? 0) > 0));
$kpiNoLease  = count(array_filter($tenants, fn($t) => empty($t['lease_id'])));
$kpiYield    = (int)array_sum(array_column(array_filter($tenants, fn($t) => !empty($t['lease_id'])), 'monthly_rent'));

// ── Properties + units for "Add Tenant" modal ─────────────────────
$allProperties = $pdo->query("SELECT id, title, location FROM properties ORDER BY title")->fetchAll();
$allUnits = $pdo->query("SELECT id, property_id, unit_number, monthly_rent, deposit_amount, status FROM units WHERE status = 'Available' ORDER BY unit_number")->fetchAll();

$success = $_GET['success'] ?? '';
$error   = $_GET['error']   ?? '';

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-7 animate-in">

<?php
$toastMsg = match($success) {
    'created'         => 'Tenant registered successfully.',
    'profile_updated' => 'Tenant details updated.',
    'unit_assigned'   => 'Unit assigned and lease created.',
    'tenant_deleted'  => 'Tenant deleted permanently.',
    'password_reset'  => 'Password reset successfully.',
    default           => ''
};
$errorMsg = match($error) {
    'has_lease'         => 'Cannot delete: tenant has lease history. Terminate the lease first.',
    'not_found'         => 'Tenant record not found.',
    'passwords_mismatch'=> 'Passwords do not match.',
    default             => $error ? htmlspecialchars($error) : ''
};
?>
<?php if ($toastMsg): ?>
<div class="p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-2xl text-green-700 dark:text-green-400 text-sm font-bold"><?php echo $toastMsg; ?></div>
<?php elseif ($errorMsg): ?>
<div class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-2xl text-red-700 dark:text-red-400 text-sm font-bold"><?php echo $errorMsg; ?></div>
<?php endif; ?>

    <!-- ── Page Header ──────────────────────────────────────── -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-2xl lg:text-3xl font-black text-slate-900 dark:text-white tracking-tight">Tenant Registry</h1>
                <span class="px-2.5 py-1 bg-slate-100 dark:bg-slate-800 rounded-lg text-[10px] font-black text-slate-500 uppercase tracking-widest"><?php echo $kpiTotal; ?> total</span>
            </div>
            <p class="text-slate-500 font-medium text-sm mt-0.5"><?php echo $role === 'landlord' ? 'Tenants across your properties.' : 'Manage leaseholders, financials and residency.'; ?></p>
        </div>
        <?php if ($canManage): ?>
        <button onclick="openModal('newTenantModal')" class="btn-green gap-2 shrink-0">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
            Register Tenant
        </button>
        <?php endif; ?>
    </div>

    <!-- ── KPI Stats ─────────────────────────────────────────── -->
    <div class="grid grid-cols-3 lg:grid-cols-6 gap-3">
        <div class="glass-card p-4 lg:p-5 flex items-center gap-3 cursor-pointer hover:-translate-y-0.5 transition-transform col-span-1" onclick="filterByStatus('')">
            <div class="w-9 h-9 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-500 shrink-0">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            </div>
            <div>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Total</p>
                <h3 class="text-xl font-black text-slate-900 dark:text-white"><?php echo $kpiTotal; ?></h3>
            </div>
        </div>
        <div class="glass-card p-4 lg:p-5 flex items-center gap-3 cursor-pointer hover:-translate-y-0.5 transition-transform" onclick="filterByStatus('Active')">
            <div class="w-9 h-9 rounded-xl bg-green-50 dark:bg-green-900/30 flex items-center justify-center text-green-500 shrink-0">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Active</p>
                <h3 class="text-xl font-black text-green-500"><?php echo $kpiActive; ?></h3>
            </div>
        </div>
        <div class="glass-card p-4 lg:p-5 flex items-center gap-3 cursor-pointer hover:-translate-y-0.5 transition-transform" onclick="filterByStatus('overdue')">
            <div class="w-9 h-9 rounded-xl bg-red-50 dark:bg-red-900/30 flex items-center justify-center text-red-500 shrink-0">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <div>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Overdue</p>
                <h3 class="text-xl font-black text-red-500"><?php echo $kpiOverdue; ?></h3>
            </div>
        </div>
        <div class="glass-card p-4 lg:p-5 flex items-center gap-3 cursor-pointer hover:-translate-y-0.5 transition-transform" onclick="filterByStatus('nolease')">
            <div class="w-9 h-9 rounded-xl bg-orange-50 dark:bg-orange-900/30 flex items-center justify-center text-orange-500 shrink-0">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
            </div>
            <div>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">No Unit</p>
                <h3 class="text-xl font-black text-orange-500"><?php echo $kpiNoLease; ?></h3>
            </div>
        </div>
        <div class="glass-card p-4 lg:p-5 flex items-center gap-3 cursor-pointer hover:-translate-y-0.5 transition-transform" onclick="filterByStatus('Inactive')">
            <div class="w-9 h-9 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 shrink-0">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
            </div>
            <div>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Inactive</p>
                <h3 class="text-xl font-black text-slate-400"><?php echo $kpiInactive; ?></h3>
            </div>
        </div>
        <div class="glass-card p-4 lg:p-5 flex items-center gap-3 border-l-2 border-accent-green">
            <div class="w-9 h-9 rounded-xl bg-accent-green/10 flex items-center justify-center text-accent-green shrink-0">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <div>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Rent Yield</p>
                <h3 class="text-base font-black text-accent-green"><?php echo $currency; ?> <?php echo number_format($kpiYield); ?></h3>
                <p class="text-[9px] text-slate-400 font-bold">/month</p>
            </div>
        </div>
    </div>

    <!-- ── Search & Filter Bar ───────────────────────────────── -->
    <div class="glass-card p-4 flex flex-col sm:flex-row gap-3">
        <div class="flex-1 relative">
            <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input type="text" id="tenantSearch" oninput="filterTable()" placeholder="Search by name, email, phone or unit…"
                   class="w-full pl-10 pr-4 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl text-sm font-medium text-slate-700 dark:text-slate-300 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-green-400/30">
        </div>
        <select id="statusFilter" onchange="filterTable()" class="px-4 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl text-sm font-bold text-slate-700 dark:text-slate-300 focus:outline-none focus:ring-2 focus:ring-green-400/30">
            <option value="">All Statuses</option>
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
            <option value="overdue">Has Overdue</option>
            <option value="nolease">No Unit Assigned</option>
        </select>
        <?php if (!empty($allProperties)): ?>
        <select id="propertyFilter" onchange="filterTable()" class="px-4 py-2.5 bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-xl text-sm font-bold text-slate-700 dark:text-slate-300 focus:outline-none focus:ring-2 focus:ring-green-400/30">
            <option value="">All Properties</option>
            <?php foreach ($allProperties as $ap): ?>
            <option value="<?php echo $ap['id']; ?>"><?php echo htmlspecialchars($ap['title']); ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <div id="filterCount" class="hidden px-4 py-2.5 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl text-sm font-black text-green-600 flex items-center gap-2 shrink-0">
            <span id="filterCountText"></span>
            <button onclick="clearFilters()" class="hover:text-green-800">×</button>
        </div>
    </div>

    <!-- ── Tenant Table ──────────────────────────────────────── -->
    <div class="glass-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left data-table" id="tenantTable">
                <thead>
                    <tr class="bg-slate-50 dark:bg-slate-800/50 border-b border-slate-100 dark:border-slate-800">
                        <th class="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-left">Tenant</th>
                        <th class="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest hidden md:table-cell text-left">Unit / Property</th>
                        <th class="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest hidden lg:table-cell text-left">Rent</th>
                        <th class="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest hidden xl:table-cell text-left">Balance</th>
                        <th class="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest hidden sm:table-cell text-left">Status</th>
                        <th class="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest hidden xl:table-cell text-left">Last Payment</th>
                        <th class="px-6 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800" id="tenantTbody">
                    <?php if (empty($tenants)): ?>
                    <tr><td colspan="7">
                        <div class="empty-state">
                            <div class="empty-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
                            <p class="font-black text-slate-500 mt-3 text-sm">No tenants registered yet.</p>
                            <?php if ($canManage): ?>
                            <button onclick="openModal('newTenantModal')" class="btn-green mt-4 text-xs">Register First Tenant</button>
                            <?php endif; ?>
                        </div>
                    </td></tr>
                    <?php else: ?>
                    <?php foreach ($tenants as $t):
                        $isActive   = ($t['status'] ?? 'Active') === 'Active';
                        $hasOverdue = (int)($t['overdue_count'] ?? 0) > 0;
                        $hasLease   = !empty($t['lease_id']);
                        $leaseExpiring = $hasLease && !empty($t['lease_end']) && strtotime($t['lease_end']) <= strtotime('+60 days');
                        // data attributes for JS filtering
                        $outstanding    = (float)($t['outstanding_balance'] ?? 0);
                        $filterData = json_encode([
                            'name'    => strtolower($t['full_name'] ?? ''),
                            'email'   => strtolower($t['email'] ?? ''),
                            'phone'   => strtolower($t['phone'] ?? ''),
                            'unit'    => strtolower($t['unit_number'] ?? ''),
                            'prop'    => strtolower($t['property_title'] ?? ''),
                            'propid'  => $t['property_id_fk'] ?? '',
                            'status'  => $t['status'] ?? 'Active',
                            'overdue' => $hasOverdue,
                            'nolease' => !$hasLease,
                        ]);
                        // Row left-border class
                        $rowBorder = $hasOverdue ? 'tenant-row-overdue' : ($leaseExpiring ? 'tenant-row-expiring' : '');
                    ?>
                    <tr class="group hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all cursor-pointer <?php echo $rowBorder; ?>"
                        data-filter='<?php echo htmlspecialchars($filterData); ?>'
                        onclick="openTenantPanel('<?php echo $t['id']; ?>')"
                        title="Click to view tenant details">
                        <td class="px-6 py-4">
                            <?php
                            // Avatar color based on first char
                            $avatarColors = ['A'=>'from-violet-500 to-purple-600','B'=>'from-blue-500 to-blue-600','C'=>'from-cyan-500 to-cyan-600','D'=>'from-teal-500 to-teal-600','E'=>'from-green-500 to-emerald-600','F'=>'from-lime-500 to-green-600','G'=>'from-yellow-500 to-amber-600','H'=>'from-orange-500 to-orange-600','I'=>'from-red-500 to-red-600','J'=>'from-pink-500 to-rose-600','K'=>'from-fuchsia-500 to-pink-600','L'=>'from-purple-500 to-violet-600','M'=>'from-indigo-500 to-blue-600','N'=>'from-sky-500 to-cyan-600','O'=>'from-emerald-500 to-teal-600','P'=>'from-green-600 to-green-700','Q'=>'from-amber-500 to-yellow-600','R'=>'from-orange-600 to-red-500','S'=>'from-red-600 to-rose-600','T'=>'from-rose-500 to-pink-600','U'=>'from-fuchsia-600 to-purple-600','V'=>'from-violet-600 to-indigo-600','W'=>'from-indigo-600 to-blue-600','X'=>'from-blue-600 to-sky-600','Y'=>'from-sky-600 to-cyan-500','Z'=>'from-cyan-600 to-teal-500'];
                            $firstChar = strtoupper(substr($t['full_name'] ?? 'A', 0, 1));
                            $avatarGrad = $avatarColors[$firstChar] ?? 'from-green-500 to-emerald-600';
                        ?>
                        <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-gradient-to-br <?php echo $avatarGrad; ?> flex items-center justify-center text-white font-black text-sm shadow-sm shrink-0 ring-2 ring-white dark:ring-slate-900">
                                    <?php echo $firstChar; ?>
                                </div>
                                <div class="min-w-0">
                                    <button onclick="event.stopPropagation(); window.location='tenant_details.php?id=<?php echo $t['id']; ?>'"
                                        class="text-sm font-black text-slate-900 dark:text-white hover:text-accent-green transition-colors truncate block text-left">
                                        <?php echo htmlspecialchars($t['full_name'] ?? ''); ?>
                                    </button>
                                    <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                        <?php if (!empty($t['phone'])): ?>
                                        <span class="text-[9px] font-bold text-slate-400"><?php echo htmlspecialchars($t['phone']); ?></span>
                                        <span class="text-slate-200 dark:text-slate-700">·</span>
                                        <?php endif; ?>
                                        <span class="text-[9px] font-black text-slate-300 dark:text-slate-600 uppercase">PRM-<?php echo substr($t['id'], 0, 6); ?></span>
                                        <?php if ($hasOverdue): ?>
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 bg-red-50 dark:bg-red-900/20 text-red-500 rounded text-[9px] font-black uppercase">
                                            <svg width="7" height="7" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="10"/></svg>
                                            <?php echo $t['overdue_count']; ?> overdue
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($leaseExpiring): ?>
                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 bg-orange-50 dark:bg-orange-900/20 text-orange-500 rounded text-[9px] font-black uppercase">
                                            Expiring
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 hidden md:table-cell">
                            <?php if ($hasLease): ?>
                            <p class="text-sm font-bold text-slate-700 dark:text-slate-300">Unit <?php echo htmlspecialchars($t['unit_number'] ?? ''); ?></p>
                            <p class="text-[9px] text-slate-400 font-medium truncate max-w-[150px]"><?php echo htmlspecialchars($t['property_title'] ?? ''); ?></p>
                            <?php if (!empty($t['lease_end'])): ?>
                            <p class="text-[9px] font-bold text-slate-300 dark:text-slate-600 mt-0.5">Until <?php echo date('M Y', strtotime($t['lease_end'])); ?></p>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="text-[10px] font-black text-orange-400 uppercase bg-orange-50 dark:bg-orange-900/20 px-2 py-1 rounded-lg">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 hidden lg:table-cell">
                            <?php if ($hasLease && !empty($t['monthly_rent'])): ?>
                            <p class="text-sm font-black text-slate-700 dark:text-slate-300"><?php echo $currency; ?> <?php echo number_format((float)$t['monthly_rent']); ?></p>
                            <p class="text-[9px] text-slate-400">per month</p>
                            <?php else: ?>
                            <span class="text-slate-300 dark:text-slate-600 text-xs">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 hidden xl:table-cell">
                            <?php if ($outstanding > 0): ?>
                            <p class="text-sm font-black text-red-500"><?php echo $currency; ?> <?php echo number_format($outstanding); ?></p>
                            <p class="text-[9px] font-bold text-red-400"><?php echo $t['overdue_count'] ?? 0; ?> unpaid invoice<?php echo ($t['overdue_count'] ?? 0) != 1 ? 's' : ''; ?></p>
                            <?php else: ?>
                            <span class="text-[10px] font-black text-green-500 bg-green-50 dark:bg-green-900/20 px-2 py-1 rounded-lg">Clear</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 hidden sm:table-cell">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[9px] font-black uppercase tracking-widest
                                <?php echo $isActive ? 'bg-green-500/10 text-green-500' : 'bg-slate-200 dark:bg-slate-700 text-slate-500'; ?>">
                                <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                                <?php echo htmlspecialchars($t['status'] ?? 'Active'); ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 hidden xl:table-cell">
                            <?php if (!empty($t['last_payment'])): ?>
                            <p class="text-xs font-bold text-slate-600 dark:text-slate-400"><?php echo date('M d, Y', strtotime($t['last_payment'])); ?></p>
                            <?php else: ?>
                            <span class="text-[10px] text-slate-300 dark:text-slate-600">Never</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-right" onclick="event.stopPropagation()">
                            <div class="relative inline-block action-menu-wrap">
                                <button onclick="toggleActionMenu(this)"
                                    class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-all">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="1" fill="currentColor"/><circle cx="12" cy="12" r="1" fill="currentColor"/><circle cx="12" cy="19" r="1" fill="currentColor"/></svg>
                                </button>
                                <div class="action-menu hidden">
                                    <a href="tenant_details.php?id=<?php echo $t['id']; ?>" class="action-item">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        View Full Profile
                                    </a>
                                    <?php if ($canManage): ?>
                                    <a href="tenant_details.php?id=<?php echo $t['id']; ?>&tab=invoices" class="action-item">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                                        View Invoices
                                    </a>
                                    <a href="tenant_payments.php?tenant=<?php echo $t['id']; ?>" class="action-item">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                        Payment History
                                    </a>
                                    <div class="action-divider"></div>
                                    <form method="POST" action="actions/tenant_detail_actions.php" style="display:contents">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="tenant_id" value="<?php echo $t['id']; ?>">
                                        <input type="hidden" name="current_status" value="<?php echo htmlspecialchars($t['status'] ?? 'Active'); ?>">
                                        <input type="hidden" name="redirect" value="tenants.php">
                                        <button type="submit" class="action-item <?php echo $isActive ? 'text-red-500 hover:bg-red-50 dark:hover:bg-red-900/10' : 'text-green-500 hover:bg-green-50 dark:hover:bg-green-900/10'; ?>">
                                            <?php if ($isActive): ?>
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                                            Deactivate Tenant
                                            <?php else: ?>
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                                            Reactivate Tenant
                                            <?php endif; ?>
                                        </button>
                                    </form>
                                    <?php
                                    $_editD = json_encode(['id'=>$t['id'],'name'=>$t['full_name']??'','phone'=>$t['phone']??'','email'=>$t['email']??'']);
                                    $_tId   = json_encode($t['id']);
                                    $_tName = json_encode($t['full_name'] ?? '');
                                    ?>
                                    <div class="action-divider"></div>
                                    <button onclick='openEditModal(<?php echo $_editD; ?>)' class="action-item">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        Edit Details
                                    </button>
                                    <button onclick='openAssignUnitModal(<?php echo $_tId; ?>, <?php echo $_tName; ?>)' class="action-item">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
                                        <?php echo $hasLease ? 'Change Unit' : 'Assign Unit'; ?>
                                    </button>
                                    <button onclick='openResetPassModal(<?php echo $_tId; ?>, <?php echo $_tName; ?>)' class="action-item">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                        Reset Password
                                    </button>
                                    <div class="action-divider"></div>
                                    <button onclick='confirmDelete(<?php echo $_tId; ?>, <?php echo $_tName; ?>)' class="action-item text-red-500 hover:bg-red-50 dark:hover:bg-red-900/10">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                                        Delete Tenant
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <!-- Empty state shown when JS filter returns 0 -->
        <div id="noResults" class="hidden">
            <div class="empty-state py-16">
                <div class="empty-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg></div>
                <p class="font-black text-slate-500 mt-3 text-sm">No tenants match your search.</p>
                <button onclick="clearFilters()" class="btn-primary mt-4 text-xs">Clear Filters</button>
            </div>
        </div>
    </div>

    <!-- ── Add Tenant Modal ──────────────────────────────────── -->
    <?php if ($canManage): ?>
    <div id="newTenantModal" class="modal-overlay" style="display:none;">
        <div class="modal-card max-w-2xl">
            <button onclick="closeModal('newTenantModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-900 dark:hover:text-white transition-all hover:rotate-90 transform">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            <h2 class="text-2xl font-black mb-1 tracking-tight">Register New Tenant</h2>
            <p class="text-xs text-slate-400 font-medium mb-8">Complete all required sections. A portal account will be created automatically.</p>

            <form action="actions/tenant_actions.php" method="POST" enctype="multipart/form-data" class="space-y-8 max-h-[75vh] overflow-y-auto pr-1">
                <input type="hidden" name="action" value="create">

                <!-- 0: Lease Assignment -->
                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <span class="w-6 h-6 rounded-lg bg-accent-green text-slate-900 flex items-center justify-center text-[10px] font-black shrink-0">0</span>
                        <h3 class="text-xs font-black text-slate-700 dark:text-slate-300 uppercase tracking-widest">Lease Assignment <span class="text-slate-400 normal-case font-medium">(optional)</span></h3>
                    </div>
                    <div class="grid grid-cols-2 gap-4 pl-9">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Property</label>
                            <select name="property_id" id="admin_property_select" onchange="filterAdminUnits(this.value)"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                                <option value="">Select Property</option>
                                <?php foreach ($allProperties as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Unit</label>
                            <select name="unit_id" id="admin_unit_select"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                                <option value="">Select Unit</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- 1: Personal Info -->
                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <span class="w-6 h-6 rounded-lg bg-blue-500 text-white flex items-center justify-center text-[10px] font-black shrink-0">1</span>
                        <h3 class="text-xs font-black text-slate-700 dark:text-slate-300 uppercase tracking-widest">Personal Details</h3>
                    </div>
                    <div class="grid grid-cols-2 gap-4 pl-9">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Full Name *</label>
                            <input type="text" name="full_name" required placeholder="John Doe"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Phone</label>
                            <input type="text" name="phone" placeholder="+254 7XX XXX XXX"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        </div>
                        <div class="space-y-1.5 col-span-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Residential Address *</label>
                            <input type="text" name="address" required placeholder="Estate, Apartment, City"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        </div>
                    </div>
                </div>

                <!-- 2: Identity -->
                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <span class="w-6 h-6 rounded-lg bg-purple-500 text-white flex items-center justify-center text-[10px] font-black shrink-0">2</span>
                        <h3 class="text-xs font-black text-slate-700 dark:text-slate-300 uppercase tracking-widest">Identity & Family</h3>
                    </div>
                    <div class="grid grid-cols-2 gap-4 pl-9">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">ID Number</label>
                            <input type="text" name="id_no" placeholder="3XXXXXXX"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">ID Copy (Upload)</label>
                            <input type="file" name="id_copy" accept=".pdf,.jpg,.jpeg,.png"
                                class="w-full px-3 py-2.5 bg-slate-100 dark:bg-slate-800/60 rounded-xl text-xs font-bold text-slate-500 file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-black file:bg-slate-200 file:text-slate-700">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Marital Status</label>
                            <select name="marital_status" onchange="toggleAdminSpouseFields(this.value)"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                                <option value="Single">Single</option>
                                <option value="Married">Married</option>
                            </select>
                        </div>
                        <div class="flex items-center gap-3 pt-6">
                            <input type="checkbox" name="has_kids" id="admin_has_kids" class="w-4 h-4 accent-green-500 rounded">
                            <label for="admin_has_kids" class="text-sm font-bold text-slate-600 dark:text-slate-400">Has Children</label>
                        </div>
                    </div>
                    <!-- Spouse fields (conditional) -->
                    <div id="admin-spouse-fields" class="hidden grid grid-cols-3 gap-4 pl-9 pt-2 border-t border-dashed border-slate-200 dark:border-slate-700">
                        <div class="col-span-3 pt-2">
                            <p class="text-[10px] font-black text-orange-500 uppercase tracking-widest">Spouse Information</p>
                        </div>
                        <div class="space-y-1.5 col-span-3 md:col-span-1">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Spouse Name</label>
                            <input type="text" name="spouse_name" class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Spouse Phone</label>
                            <input type="text" name="spouse_phone" class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Spouse ID No.</label>
                            <input type="text" name="spouse_id_no" class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold outline-none">
                        </div>
                    </div>
                </div>

                <!-- 3: Next of Kin -->
                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <span class="w-6 h-6 rounded-lg bg-orange-500 text-white flex items-center justify-center text-[10px] font-black shrink-0">3</span>
                        <h3 class="text-xs font-black text-slate-700 dark:text-slate-300 uppercase tracking-widest">Next of Kin</h3>
                    </div>
                    <div class="grid grid-cols-3 gap-4 pl-9">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Full Name</label>
                            <input type="text" name="nok_name" placeholder="Jane Doe"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Relationship</label>
                            <input type="text" name="nok_relationship" placeholder="Spouse / Parent / Sibling"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Phone</label>
                            <input type="text" name="nok_contact" placeholder="+254 7XX..."
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold outline-none">
                        </div>
                    </div>
                </div>

                <!-- 4: Professional -->
                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <span class="w-6 h-6 rounded-lg bg-slate-600 text-white flex items-center justify-center text-[10px] font-black shrink-0">4</span>
                        <h3 class="text-xs font-black text-slate-700 dark:text-slate-300 uppercase tracking-widest">Professional Info</h3>
                    </div>
                    <div class="grid grid-cols-2 gap-4 pl-9">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Profession</label>
                            <input type="text" name="profession" class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Employer</label>
                            <input type="text" name="employer_name" class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold outline-none">
                        </div>
                    </div>
                </div>

                <!-- 5: Access -->
                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <span class="w-6 h-6 rounded-lg bg-slate-900 dark:bg-white text-white dark:text-slate-900 flex items-center justify-center text-[10px] font-black shrink-0">5</span>
                        <h3 class="text-xs font-black text-slate-700 dark:text-slate-300 uppercase tracking-widest">Portal Credentials</h3>
                    </div>
                    <div class="grid grid-cols-2 gap-4 pl-9">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Login Email *</label>
                            <input type="email" name="email" required placeholder="john@example.com"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-slate-900/10 outline-none">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Initial Password *</label>
                            <input type="password" name="password" required placeholder="••••••••"
                                class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-slate-900/10 outline-none">
                        </div>
                    </div>
                </div>

                <div class="pt-4 pb-2">
                    <button type="submit" class="btn-green w-full justify-center py-4 text-sm font-black">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Register Tenant & Generate Credentials
                    </button>
                    <p class="text-[10px] text-center text-slate-400 font-bold uppercase tracking-widest mt-3">A digital lease will be created automatically if a unit is selected.</p>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Edit Details Modal ──────────────────────────────────── -->
    <?php if ($canManage): ?>
    <div id="editTenantModal" class="modal-overlay" style="display:none;">
        <div class="modal-card max-w-lg px-8 py-10">
            <button onclick="closeModal('editTenantModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-900 dark:hover:text-white transition-all hover:rotate-90 transform">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            <h2 class="text-2xl font-black mb-1 tracking-tight">Edit Tenant</h2>
            <p class="text-xs text-slate-400 font-medium mb-8">Update basic contact information.</p>
            <form action="actions/tenant_detail_actions.php" method="POST" class="space-y-5">
                <input type="hidden" name="action" value="quick_edit">
                <input type="hidden" name="tenant_id" id="editTenant_id">
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Full Name *</label>
                    <input type="text" name="full_name" id="editTenant_name" required
                        class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Phone</label>
                        <input type="text" name="phone" id="editTenant_phone"
                            class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Email</label>
                        <input type="email" name="email" id="editTenant_email"
                            class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                    </div>
                </div>
                <button type="submit" class="btn-primary w-full justify-center py-4 font-black">Save Changes →</button>
            </form>
        </div>
    </div>

    <!-- ── Assign Unit Modal ────────────────────────────────────── -->
    <div id="assignUnitModal" class="modal-overlay" style="display:none;">
        <div class="modal-card max-w-lg px-8 py-10">
            <button onclick="closeModal('assignUnitModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-900 dark:hover:text-white transition-all hover:rotate-90 transform">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            <h2 class="text-2xl font-black mb-1 tracking-tight">Assign Unit</h2>
            <p class="text-xs text-slate-400 font-medium mb-1">Assigning to: <span id="assign_tenant_name" class="font-black text-slate-700 dark:text-slate-200"></span></p>
            <p class="text-[10px] text-orange-500 font-bold uppercase tracking-widest mb-8">Only tenants without an active lease can be assigned here.</p>
            <form action="actions/tenant_actions.php" method="POST" class="space-y-5">
                <input type="hidden" name="action" value="assign_unit">
                <input type="hidden" name="tenant_id" id="assign_tenant_id">
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Property *</label>
                        <select name="property_id" id="assign_property_id" required onchange="filterAssignUnits(this.value)"
                            class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                            <option value="">Select Property</option>
                            <?php foreach ($allProperties as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Unit *</label>
                        <select name="unit_id" id="assign_unit_id" required onchange="fillAssignRent(this)"
                            class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                            <option value="">Select Property First</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Monthly Rent *</label>
                        <input type="number" name="monthly_rent" id="assign_monthly_rent" required min="1" step="0.01" placeholder="Auto-fills from unit"
                            class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Deposit Amount</label>
                        <input type="number" name="deposit_amount" id="assign_deposit_amount" min="0" step="0.01" placeholder="0"
                            class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Lease Start *</label>
                        <input type="date" name="start_date" id="assign_start_date" required
                            class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Lease End <span class="normal-case font-medium">(optional)</span></label>
                        <input type="date" name="end_date" id="assign_end_date"
                            class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                    </div>
                </div>
                <button type="submit" class="btn-green w-full justify-center py-4 font-black">Assign Unit & Create Lease →</button>
            </form>
        </div>
    </div>

    <!-- ── Reset Password Modal ─────────────────────────────────── -->
    <div id="resetPassModal" class="modal-overlay" style="display:none;">
        <div class="modal-card max-w-md px-8 py-10">
            <button onclick="closeModal('resetPassModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-900 dark:hover:text-white transition-all hover:rotate-90 transform">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            <h2 class="text-2xl font-black mb-1 tracking-tight">Reset Password</h2>
            <p class="text-xs text-slate-400 font-medium mb-8">Setting new password for: <span id="resetPass_tenant_name" class="font-black text-slate-700 dark:text-slate-200"></span></p>
            <form action="actions/tenant_detail_actions.php" method="POST" class="space-y-5">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="tenant_id" id="resetPass_tenant_id">
                <input type="hidden" name="_redirect" value="tenants.php">
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">New Password *</label>
                    <input type="password" name="new_password" id="resetPass_new" required minlength="6" placeholder="••••••••"
                        class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Confirm Password *</label>
                    <input type="password" name="confirm_password" id="resetPass_confirm" required minlength="6" placeholder="••••••••"
                        class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/60 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                </div>
                <button type="submit" class="btn-primary w-full justify-center py-4 font-black">Set New Password →</button>
            </form>
        </div>
    </div>

    <!-- ── Hidden Delete Form ───────────────────────────────────── -->
    <form id="deleteForm" action="actions/tenant_actions.php" method="POST" style="display:none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="tenant_id" id="deleteTenant_id">
    </form>
    <?php endif; ?>

</div><!-- end space-y-7 -->

<!-- ── Tenant Quick-View Slide Panel ──────────────────────────────── -->
<div id="tenantPanel" class="fixed inset-0 z-50 flex justify-end" style="display:none;">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeTenantPanel()"></div>
    <div class="relative w-full max-w-md bg-white dark:bg-slate-900 h-full overflow-y-auto shadow-2xl flex flex-col border-l border-slate-100 dark:border-slate-800">

        <!-- Header -->
        <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-800 shrink-0">
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-4 min-w-0">
                    <div id="tp_avatar" class="w-14 h-14 rounded-2xl bg-gradient-to-br from-green-500 to-emerald-600 flex items-center justify-center text-white font-black text-2xl shrink-0 shadow-md"></div>
                    <div class="min-w-0">
                        <h2 class="text-xl font-black text-slate-900 dark:text-white truncate" id="tp_name"></h2>
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest" id="tp_prm"></p>
                        <span id="tp_status_badge" class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-widest mt-1"></span>
                    </div>
                </div>
                <button onclick="closeTenantPanel()" class="w-9 h-9 flex items-center justify-center rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-900 dark:hover:text-white transition-all shrink-0">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
        </div>

        <div class="p-6 space-y-5 flex-1">

            <!-- Contact info -->
            <div class="space-y-2">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Contact</p>
                <div class="space-y-1.5">
                    <div class="flex items-center gap-3 p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                        <svg class="text-slate-400 shrink-0" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                        <span class="text-xs font-bold text-slate-700 dark:text-slate-300 truncate" id="tp_email"></span>
                    </div>
                    <div class="flex items-center gap-3 p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl" id="tp_phone_row">
                        <svg class="text-slate-400 shrink-0" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.93 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.88 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        <span class="text-xs font-bold text-slate-700 dark:text-slate-300" id="tp_phone"></span>
                    </div>
                </div>
            </div>

            <!-- Unit / Lease -->
            <div id="tp_lease_section">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2">Current Lease</p>
                <div class="p-4 rounded-2xl border border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/30 space-y-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-black text-slate-900 dark:text-white" id="tp_unit"></p>
                            <p class="text-[10px] text-slate-400 font-medium" id="tp_property"></p>
                        </div>
                        <p class="text-lg font-black text-accent-green" id="tp_rent"></p>
                    </div>
                    <div class="grid grid-cols-2 gap-2 text-[10px]">
                        <div>
                            <p class="font-black text-slate-400 uppercase tracking-widest mb-0.5">Lease Start</p>
                            <p class="font-bold text-slate-600 dark:text-slate-300" id="tp_lease_start"></p>
                        </div>
                        <div>
                            <p class="font-black text-slate-400 uppercase tracking-widest mb-0.5">Lease End</p>
                            <p class="font-bold text-slate-600 dark:text-slate-300" id="tp_lease_end"></p>
                        </div>
                    </div>
                </div>
            </div>
            <div id="tp_nolease_section" class="hidden">
                <div class="p-4 border-2 border-dashed border-orange-200 dark:border-orange-900/40 rounded-2xl text-center">
                    <p class="text-[10px] font-black text-orange-400 uppercase tracking-widest mb-1">No Active Lease</p>
                    <p class="text-xs text-slate-500 font-medium">This tenant has not been assigned a unit.</p>
                </div>
            </div>

            <!-- Balance -->
            <div>
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-2">Outstanding Balance</p>
                <div class="flex items-center justify-between p-4 rounded-2xl border" id="tp_balance_card">
                    <div>
                        <p class="text-2xl font-black" id="tp_balance_amount"></p>
                        <p class="text-[10px] font-bold text-slate-400" id="tp_balance_sub"></p>
                    </div>
                    <div id="tp_balance_icon" class="w-10 h-10 rounded-xl flex items-center justify-center">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                </div>
            </div>

            <!-- Member since -->
            <p class="text-[9px] text-slate-400 font-bold text-center" id="tp_since"></p>
        </div>

        <!-- Panel footer -->
        <div class="px-6 py-4 border-t border-slate-100 dark:border-slate-800 space-y-2 shrink-0">
            <div class="grid grid-cols-2 gap-2">
                <a id="tp_profile_link" href="#" onclick="event.stopPropagation()"
                   class="py-2.5 text-center text-[11px] font-black text-white bg-slate-900 dark:bg-slate-100 dark:text-slate-900 rounded-xl hover:opacity-80 transition-opacity">
                    View Full Profile
                </a>
                <a id="tp_invoices_link" href="#" onclick="event.stopPropagation()"
                   class="py-2.5 text-center text-[11px] font-black border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                    View Invoices
                </a>
            </div>
            <?php if ($canManage): ?>
            <div class="grid grid-cols-3 gap-2">
                <button id="tp_edit_btn" class="py-2 text-[11px] font-black border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">Edit</button>
                <button id="tp_assign_btn" class="py-2 text-[11px] font-black border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">Unit</button>
                <button id="tp_pass_btn" class="py-2 text-[11px] font-black border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">Password</button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
/* Action dropdown */
.action-menu-wrap { }
.action-menu {
    position: absolute;
    right: 0; top: calc(100% + 4px);
    width: 210px; padding: 6px;
    background: #fff; border: 1px solid #e2e8f0;
    border-radius: 14px; box-shadow: 0 12px 40px rgba(0,0,0,0.12);
    z-index: 200; overflow: hidden;
}
html.dark .action-menu { background: #0f172a; border-color: #1e293b; box-shadow: 0 12px 40px rgba(0,0,0,0.4); }
.action-item {
    display: flex; align-items: center; gap: 10px;
    width: 100%; padding: 9px 12px;
    font-size: 12px; font-weight: 700; color: #475569;
    text-decoration: none; border-radius: 9px;
    transition: background 0.12s; background: none;
    border: none; cursor: pointer; text-align: left;
}
html.dark .action-item { color: #94a3b8; }
.action-item:hover { background: #f8fafc; color: #0f172a; }
html.dark .action-item:hover { background: #1e293b; color: #f8fafc; }
.action-divider { height: 1px; background: #f1f5f9; margin: 4px 0; }
html.dark .action-divider { background: #1e293b; }
/* Row status accents */
.tenant-row-overdue  { border-left: 3px solid #ef4444; }
.tenant-row-expiring { border-left: 3px solid #f97316; }
</style>

<script>
/* ── Data for modals ────────────────────────── */
const allUnits = <?php echo json_encode($allUnits); ?>;
const allProps = <?php echo json_encode($allProperties); ?>;
function filterAdminUnits(propertyId) {
    const sel = document.getElementById('admin_unit_select');
    sel.innerHTML = '<option value="">Select Unit</option>';
    if (!propertyId) return;
    allUnits.filter(u => u.property_id === propertyId).forEach(u => {
        const o = document.createElement('option');
        o.value = u.id;
        o.textContent = `Unit ${u.unit_number} — <?php echo $currency; ?> ${parseInt(u.monthly_rent).toLocaleString()}/mo`;
        sel.appendChild(o);
    });
}
function toggleAdminSpouseFields(v) {
    const el = document.getElementById('admin-spouse-fields');
    el.classList.toggle('hidden', v !== 'Married');
    if (v === 'Married') el.classList.add('grid');
}

/* ── Action dropdown ─────────────────────── */
function toggleActionMenu(btn) {
    const menu = btn.nextElementSibling;
    const isOpen = !menu.classList.contains('hidden');
    // Close all other menus
    document.querySelectorAll('.action-menu').forEach(m => m.classList.add('hidden'));
    if (!isOpen) menu.classList.remove('hidden');
}
document.addEventListener('click', e => {
    if (!e.target.closest('.action-menu-wrap')) {
        document.querySelectorAll('.action-menu').forEach(m => m.classList.add('hidden'));
    }
});

/* ── Table filtering ─────────────────────── */
function filterTable() {
    const q      = document.getElementById('tenantSearch').value.toLowerCase().trim();
    const status = document.getElementById('statusFilter').value;
    const propEl = document.getElementById('propertyFilter');
    const propId = propEl ? propEl.value : '';
    const rows   = document.querySelectorAll('#tenantTbody tr[data-filter]');
    let visible  = 0;

    rows.forEach(row => {
        const d = JSON.parse(row.dataset.filter);
        const matchQ = !q ||
            d.name.includes(q) || d.email.includes(q) ||
            d.phone.includes(q) || d.unit.includes(q) || d.prop.includes(q);
        const matchS =
            !status ? true :
            status === 'overdue'  ? d.overdue :
            status === 'nolease'  ? d.nolease :
            d.status === status;
        const matchP = !propId || d.propid === propId;

        const show = matchQ && matchS && matchP;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    const countEl  = document.getElementById('filterCount');
    const countTxt = document.getElementById('filterCountText');
    const hasFilter = q || status || propId;
    if (hasFilter) {
        countEl.classList.remove('hidden');
        countEl.classList.add('flex');
        countTxt.textContent = `${visible} result${visible !== 1 ? 's' : ''}`;
    } else {
        countEl.classList.add('hidden');
        countEl.classList.remove('flex');
    }
    document.getElementById('noResults').classList.toggle('hidden', visible > 0 || rows.length === 0);
}

function filterByStatus(status) {
    document.getElementById('statusFilter').value = status;
    document.getElementById('tenantSearch').value = '';
    const propEl = document.getElementById('propertyFilter');
    if (propEl) propEl.value = '';
    filterTable();
}

function clearFilters() {
    document.getElementById('tenantSearch').value = '';
    document.getElementById('statusFilter').value = '';
    const propEl = document.getElementById('propertyFilter');
    if (propEl) propEl.value = '';
    filterTable();
}

/* ── Edit Details Modal ─────────────────────── */
function openEditModal(d) {
    document.getElementById('editTenant_id').value    = d.id;
    document.getElementById('editTenant_name').value  = d.name;
    document.getElementById('editTenant_phone').value = d.phone;
    document.getElementById('editTenant_email').value = d.email;
    openModal('editTenantModal');
}

/* ── Assign Unit Modal ──────────────────────── */
function openAssignUnitModal(id, name) {
    document.getElementById('assign_tenant_id').value       = id;
    document.getElementById('assign_tenant_name').textContent = name;
    document.getElementById('assign_property_id').value     = '';
    document.getElementById('assign_unit_id').innerHTML     = '<option value="">Select Property First</option>';
    document.getElementById('assign_monthly_rent').value    = '';
    document.getElementById('assign_deposit_amount').value  = '';
    document.getElementById('assign_start_date').value      = new Date().toISOString().slice(0, 10);
    document.getElementById('assign_end_date').value        = '';
    openModal('assignUnitModal');
}
function filterAssignUnits(propId) {
    const sel = document.getElementById('assign_unit_id');
    sel.innerHTML = '<option value="">Select Unit</option>';
    document.getElementById('assign_monthly_rent').value   = '';
    document.getElementById('assign_deposit_amount').value = '';
    if (!propId) return;
    allUnits.filter(u => u.property_id === propId).forEach(u => {
        const o = document.createElement('option');
        o.value = u.id;
        o.dataset.rent    = u.monthly_rent;
        o.dataset.deposit = u.deposit_amount || '0';
        o.textContent = `Unit ${u.unit_number} — <?php echo $currency; ?> ${parseInt(u.monthly_rent).toLocaleString()}/mo`;
        sel.appendChild(o);
    });
}
function fillAssignRent(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.rent) {
        document.getElementById('assign_monthly_rent').value   = opt.dataset.rent;
        document.getElementById('assign_deposit_amount').value = opt.dataset.deposit;
    }
}

/* ── Reset Password Modal ───────────────────── */
function openResetPassModal(id, name) {
    document.getElementById('resetPass_tenant_id').value         = id;
    document.getElementById('resetPass_tenant_name').textContent = name;
    document.getElementById('resetPass_new').value               = '';
    document.getElementById('resetPass_confirm').value           = '';
    openModal('resetPassModal');
}

/* ── Delete Tenant ──────────────────────────── */
function confirmDelete(id, name) {
    if (!confirm(`Permanently delete "${name}"?\n\nThis cannot be undone. Tenants with any lease history cannot be deleted — terminate the lease first.`)) return;
    document.getElementById('deleteTenant_id').value = id;
    document.getElementById('deleteForm').submit();
}

/* ── Tenant quick-view panel ─────────────────────── */
const tenantPanelData = <?php echo json_encode($tenantPanelData); ?>;

const avatarGrads = {
    A:'from-violet-500 to-purple-600',B:'from-blue-500 to-blue-600',C:'from-cyan-500 to-cyan-600',
    D:'from-teal-500 to-teal-600',E:'from-green-500 to-emerald-600',F:'from-lime-500 to-green-600',
    G:'from-yellow-500 to-amber-600',H:'from-orange-500 to-orange-600',I:'from-red-500 to-red-600',
    J:'from-pink-500 to-rose-600',K:'from-fuchsia-500 to-pink-600',L:'from-purple-500 to-violet-600',
    M:'from-indigo-500 to-blue-600',N:'from-sky-500 to-cyan-600',O:'from-emerald-500 to-teal-600',
    P:'from-green-600 to-green-700',Q:'from-amber-500 to-yellow-600',R:'from-orange-600 to-red-500',
    S:'from-red-600 to-rose-600',T:'from-rose-500 to-pink-600',U:'from-fuchsia-600 to-purple-600',
    V:'from-violet-600 to-indigo-600',W:'from-indigo-600 to-blue-600',X:'from-blue-600 to-sky-600',
    Y:'from-sky-600 to-cyan-500',Z:'from-cyan-600 to-teal-500'
};

function openTenantPanel(id) {
    const t = tenantPanelData[id];
    if (!t) return;

    const initial = (t.full_name || '?')[0].toUpperCase();
    const grad    = avatarGrads[initial] || 'from-green-500 to-emerald-600';

    document.getElementById('tp_avatar').className = `w-14 h-14 rounded-2xl bg-gradient-to-br ${grad} flex items-center justify-center text-white font-black text-2xl shrink-0 shadow-md`;
    document.getElementById('tp_avatar').textContent  = initial;
    document.getElementById('tp_name').textContent    = t.full_name || '—';
    document.getElementById('tp_prm').textContent     = 'PRM-' + id.slice(0, 8).toUpperCase();
    document.getElementById('tp_email').textContent   = t.email || '—';
    document.getElementById('tp_phone').textContent   = t.phone || '—';
    document.getElementById('tp_phone_row').style.display = t.phone ? '' : 'none';

    // Status badge
    const sb = document.getElementById('tp_status_badge');
    if (t.status === 'Active') {
        sb.className = 'inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-widest bg-green-500/10 text-green-500';
        sb.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-current"></span> Active';
    } else {
        sb.className = 'inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[9px] font-black uppercase tracking-widest bg-slate-200 dark:bg-slate-700 text-slate-500';
        sb.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-current"></span> Inactive';
    }

    // Lease section
    const ls = document.getElementById('tp_lease_section');
    const ns = document.getElementById('tp_nolease_section');
    if (t.lease_id) {
        ls.classList.remove('hidden'); ns.classList.add('hidden');
        document.getElementById('tp_unit').textContent     = 'Unit ' + (t.unit_number || '—');
        document.getElementById('tp_property').textContent = t.property_title || '—';
        document.getElementById('tp_rent').textContent     = '<?php echo $currency; ?> ' + parseFloat(t.monthly_rent).toLocaleString() + '/mo';
        const fmtD = d => d ? new Date(d).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}) : '—';
        document.getElementById('tp_lease_start').textContent = fmtD(t.lease_start);
        document.getElementById('tp_lease_end').textContent   = t.lease_end ? fmtD(t.lease_end) : 'Open-ended';
    } else {
        ls.classList.add('hidden'); ns.classList.remove('hidden');
    }

    // Balance
    const bal   = parseFloat(t.outstanding_balance || 0);
    const balEl = document.getElementById('tp_balance_amount');
    const balCard  = document.getElementById('tp_balance_card');
    const balIcon  = document.getElementById('tp_balance_icon');
    const balSub   = document.getElementById('tp_balance_sub');
    if (bal > 0) {
        balEl.textContent = '<?php echo $currency; ?> ' + bal.toLocaleString();
        balEl.className   = 'text-2xl font-black text-red-500';
        balCard.className = 'flex items-center justify-between p-4 rounded-2xl border border-red-200 dark:border-red-900/40 bg-red-50 dark:bg-red-900/10';
        balIcon.className = 'w-10 h-10 rounded-xl bg-red-100 dark:bg-red-900/30 text-red-400 flex items-center justify-center';
        balSub.textContent = (t.overdue_count || 0) + ' unpaid invoice' + ((t.overdue_count || 0) !== 1 ? 's' : '');
        balSub.className = 'text-[10px] font-bold text-red-400';
    } else {
        balEl.textContent = 'Clear';
        balEl.className   = 'text-2xl font-black text-green-500';
        balCard.className = 'flex items-center justify-between p-4 rounded-2xl border border-green-200 dark:border-green-900/40 bg-green-50 dark:bg-green-900/10';
        balIcon.className = 'w-10 h-10 rounded-xl bg-green-100 dark:bg-green-900/30 text-green-500 flex items-center justify-center';
        balSub.textContent = 'No outstanding amount';
        balSub.className = 'text-[10px] font-bold text-green-400';
    }

    // Member since
    const since = document.getElementById('tp_since');
    since.textContent = t.created_at ? 'Member since ' + new Date(t.created_at).toLocaleDateString('en-GB', {day:'2-digit', month:'long', year:'numeric'}) : '';

    // Quick links
    document.getElementById('tp_profile_link').href  = 'tenant_details.php?id=' + id;
    document.getElementById('tp_invoices_link').href = 'tenant_details.php?id=' + id + '&tab=invoices';

    // Footer action buttons
    const editBtn = document.getElementById('tp_edit_btn');
    const asnBtn  = document.getElementById('tp_assign_btn');
    const passBtn = document.getElementById('tp_pass_btn');
    if (editBtn) {
        const ed = {id: t.id, name: t.full_name, phone: t.phone, email: t.email};
        editBtn.onclick = () => { closeTenantPanel(); openEditModal(ed); };
        asnBtn.onclick  = () => { closeTenantPanel(); openAssignUnitModal(t.id, t.full_name); };
        passBtn.onclick = () => { closeTenantPanel(); openResetPassModal(t.id, t.full_name); };
    }

    document.getElementById('tenantPanel').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeTenantPanel() {
    document.getElementById('tenantPanel').style.display = 'none';
    document.body.style.overflow = '';
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeTenantPanel(); });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
