<?php
/**
 * Landlord — My Tenants
 * Scoped view of all tenants across the landlord's properties.
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin(['landlord']);
require_once __DIR__ . '/includes/settings.php';

$pageTitle = "My Tenants";
$user      = getCurrentUser($pdo);
$landlordId = getLandlordId($pdo);

if (!$landlordId) {
    header("Location: dashboard.php");
    exit();
}

$currency = getSetting($pdo, 'currency_symbol', 'KSh');
$search   = trim($_GET['search'] ?? '');

// All active tenants in this landlord's properties
$params = [$landlordId];
$searchWhere = '';
if ($search) {
    $searchWhere = "AND (t.full_name LIKE ? OR t.phone LIKE ? OR u.unit_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$stmt = $pdo->prepare("
    SELECT
        t.id, t.full_name, t.phone, t.email, t.status as tenant_status,
        u.unit_number, p.title as property_title, p.id as property_id,
        l.id as lease_id, l.monthly_rent, l.start_date, l.end_date,
        COALESCE((SELECT SUM(i.amount) FROM invoices i WHERE i.tenant_id = t.id), 0)                               AS total_invoiced,
        COALESCE((SELECT SUM(tx.amount) FROM transactions tx WHERE tx.tenant_id = t.id AND tx.status = 'Paid'), 0) AS total_paid,
        (SELECT COUNT(*) FROM invoices i WHERE i.tenant_id = t.id AND i.status = 'Overdue')                        AS overdue_count,
        (SELECT COUNT(*) FROM invoices i WHERE i.tenant_id = t.id AND i.status = 'Unpaid')                         AS unpaid_count
    FROM tenants t
    JOIN leases l ON t.id = l.tenant_id AND l.status = 'Active'
    JOIN units u ON l.unit_id = u.id
    JOIN properties p ON u.property_id = p.id
    WHERE p.landlord_id = ? AND t.status = 'Active'
    $searchWhere
    ORDER BY p.title, u.unit_number
");
$stmt->execute($params);
$tenants = $stmt->fetchAll();

// Summary stats
$totalOutstanding  = 0;
$totalOverdueCount = 0;
$totalTenantsCount = count($tenants);
foreach ($tenants as $t) {
    $bal = $t['total_invoiced'] - $t['total_paid'];
    if ($bal > 0) $totalOutstanding += $bal;
    if ($t['overdue_count'] > 0) $totalOverdueCount++;
}

// Group by property
$grouped = [];
foreach ($tenants as $t) {
    $grouped[$t['property_id']]['title'] = $t['property_title'];
    $grouped[$t['property_id']]['tenants'][] = $t;
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

    <!-- Header -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="dashboard.php" class="p-2 bg-slate-100 dark:bg-slate-800 rounded-xl text-slate-500 hover:text-slate-900 transition-all">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
                </a>
                <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">My Tenants</h1>
            </div>
            <p class="text-slate-500 font-medium ml-11">All tenants across your <?php echo count($grouped); ?> propert<?php echo count($grouped)!==1?'ies':'y'; ?></p>
        </div>
        <form class="relative w-full md:w-72">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search name, unit..." class="w-full pl-10 pr-4 py-3 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-xs font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
        </form>
    </div>

    <!-- Summary cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        <div class="glass-card p-6 border-l-4 border-blue-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Active Tenants</p>
            <p class="text-3xl font-black text-slate-900 dark:text-white"><?php echo $totalTenantsCount; ?></p>
        </div>
        <div class="glass-card p-6 border-l-4 <?php echo $totalOutstanding > 0 ? 'border-orange-500' : 'border-accent-green'; ?>">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Outstanding Balance</p>
            <p class="text-2xl font-black <?php echo $totalOutstanding > 0 ? 'text-orange-500' : 'text-accent-green'; ?>"><?php echo $currency; ?> <?php echo number_format($totalOutstanding); ?></p>
        </div>
        <div class="glass-card p-6 border-l-4 <?php echo $totalOverdueCount > 0 ? 'border-red-500' : 'border-accent-green'; ?>">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Tenants with Overdue</p>
            <p class="text-3xl font-black <?php echo $totalOverdueCount > 0 ? 'text-red-500' : 'text-accent-green'; ?>"><?php echo $totalOverdueCount; ?></p>
        </div>
    </div>

    <!-- Grouped tenant list -->
    <?php if (empty($tenants)): ?>
    <div class="glass-card p-16 text-center">
        <p class="text-slate-400 italic"><?php echo $search ? 'No tenants match your search.' : 'No active tenants found across your properties.'; ?></p>
    </div>
    <?php else: ?>
    <?php foreach ($grouped as $propId => $group): ?>
    <div class="space-y-4">
        <div class="flex items-center gap-3">
            <h3 class="font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($group['title']); ?></h3>
            <span class="badge badge-blue"><?php echo count($group['tenants']); ?> tenant<?php echo count($group['tenants'])!==1?'s':''; ?></span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            <?php foreach ($group['tenants'] as $t):
                $balance = $t['total_invoiced'] - $t['total_paid'];
                $leaseEndDate = $t['end_date'] ?? null;
                $daysToExpiry = $leaseEndDate ? (int)ceil((strtotime($leaseEndDate) - time()) / 86400) : null;
            ?>
            <div class="glass-card p-5 <?php echo $t['overdue_count'] > 0 ? 'border border-red-200 dark:border-red-800/30' : ''; ?> hover:shadow-lg transition-shadow">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-linear-to-br from-accent-green to-emerald-600 flex items-center justify-center text-sm font-black text-white shrink-0">
                            <?php echo strtoupper(substr($t['full_name'], 0, 1)); ?>
                        </div>
                        <div>
                            <p class="font-black text-sm text-slate-900 dark:text-white leading-tight"><?php echo htmlspecialchars($t['full_name']); ?></p>
                            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest">Unit <?php echo htmlspecialchars($t['unit_number']); ?></p>
                        </div>
                    </div>
                    <?php if ($t['overdue_count'] > 0): ?>
                    <span class="px-2 py-0.5 bg-red-500/10 text-red-500 rounded-full text-[9px] font-black border border-red-500/20 whitespace-nowrap">
                        <?php echo $t['overdue_count']; ?> overdue
                    </span>
                    <?php elseif ($balance <= 0): ?>
                    <span class="px-2 py-0.5 bg-green-500/10 text-green-500 rounded-full text-[9px] font-black border border-green-500/20">Paid Up</span>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div class="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Monthly Rent</p>
                        <p class="text-xs font-black text-slate-900 dark:text-white mt-0.5"><?php echo $currency; ?> <?php echo number_format($t['monthly_rent']); ?></p>
                    </div>
                    <div class="p-3 <?php echo $balance > 0 ? 'bg-red-50 dark:bg-red-900/10' : 'bg-green-50 dark:bg-green-900/10'; ?> rounded-xl">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Balance Due</p>
                        <p class="text-xs font-black <?php echo $balance > 0 ? 'text-red-500' : 'text-accent-green'; ?> mt-0.5"><?php echo $currency; ?> <?php echo number_format(max(0, $balance)); ?></p>
                    </div>
                </div>

                <?php if ($leaseEndDate): ?>
                <div class="flex items-center gap-2 mb-4 text-[10px] <?php echo $daysToExpiry !== null && $daysToExpiry < 60 ? 'text-orange-500' : 'text-slate-400'; ?> font-bold">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>
                    Lease ends <?php echo date('d M Y', strtotime($leaseEndDate)); ?>
                    <?php if ($daysToExpiry !== null && $daysToExpiry < 60 && $daysToExpiry >= 0): ?>
                    — <span class="text-orange-500"><?php echo $daysToExpiry; ?>d</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="flex gap-2">
                    <a href="view_statement.php?tenant_id=<?php echo $t['id']; ?>" class="flex-1 py-2 text-center bg-slate-100 dark:bg-slate-800 hover:bg-accent-green hover:text-white transition-all rounded-xl text-[10px] font-black uppercase tracking-widest text-slate-700 dark:text-slate-300">
                        Statement
                    </a>
                    <?php if (!empty($t['phone'])): ?>
                    <a href="tel:<?php echo htmlspecialchars($t['phone']); ?>" class="px-3 py-2 bg-slate-100 dark:bg-slate-800 hover:bg-blue-500 hover:text-white transition-all rounded-xl text-slate-500">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3-8.57A2 2 0 0 1 3.71 1.5h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 9.09a16 16 0 0 0 5.09 5.09l1.96-1.96a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 21.89 14z"/></svg>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
