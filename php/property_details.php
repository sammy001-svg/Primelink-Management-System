<?php
/**
 * Property Details Page
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/includes/settings.php';

$user       = getCurrentUser($pdo);
$role       = $_SESSION['role'] ?? 'staff';
$propertyId = $_GET['id'] ?? null;
$currency   = getSetting($pdo, 'currency_symbol', 'KSh');

if (!$propertyId) {
    header("Location: properties.php");
    exit();
}

// ── Property + landlord ───────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT p.*, l.id AS landlord_id_ref, l.full_name AS landlord_name,
           l.phone AS landlord_phone, l.email AS landlord_email
    FROM   properties p
    LEFT JOIN landlords l ON p.landlord_id = l.id
    WHERE  p.id = ?
");
try {
    $stmt->execute([$propertyId]);
} catch (PDOException $e) {
    if ($e->getCode() == '42S22') {
        $pdo->exec("ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `water_rate` DECIMAL(15,2) NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `garbage_fee` DECIMAL(15,2) NOT NULL DEFAULT 0");
        $stmt->execute([$propertyId]);
    } else { throw $e; }
}
$property = $stmt->fetch();
if (!$property) { die("Property not found."); }

// ── Units with active tenant + balances ───────────────────────────────
$unitSql = "
    SELECT u.*,
        l.id         AS lease_id,
        l.start_date AS lease_start,
        l.end_date   AS lease_end,
        t.id         AS tenant_id,
        t.full_name  AS tenant_name,
        t.phone      AS tenant_phone,
        t.email      AS tenant_email,
        COALESCE((SELECT SUM(inv.amount) FROM invoices inv
                  WHERE inv.tenant_id = t.id AND inv.status NOT IN ('Paid','Cancelled')), 0) AS outstanding_balance,
        (SELECT COUNT(*) FROM invoices inv
         WHERE inv.tenant_id = t.id AND inv.status NOT IN ('Paid','Cancelled')) AS unpaid_invoice_count
    FROM  units u
    LEFT JOIN leases  l ON l.unit_id = u.id AND l.status = 'Active'
    LEFT JOIN tenants t ON l.tenant_id = t.id
    WHERE u.property_id = ?
    ORDER BY u.unit_number ASC
";
$stmt = $pdo->prepare($unitSql);
try {
    $stmt->execute([$propertyId]);
} catch (PDOException $e) {
    if ($e->getCode() == '42S22') {
        $pdo->exec("ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `electricity_meter` VARCHAR(100) NULL AFTER `deposit_amount`");
        $pdo->exec("ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `water_meter` VARCHAR(100) NULL AFTER `electricity_meter`");
        $stmt = $pdo->prepare($unitSql);
        $stmt->execute([$propertyId]);
    } else { throw $e; }
}
$units = $stmt->fetchAll();

// Build keyed panel data for JS
$unitPanelData = [];
foreach ($units as $u) {
    $unitPanelData[$u['id']] = [
        'id'                => $u['id'],
        'unit_number'       => $u['unit_number'],
        'unit_type'         => $u['unit_type']        ?? '',
        'floor_number'      => $u['floor_number']      ?? 'G',
        'category'          => $u['category']          ?? '',
        'status'            => $u['status'],
        'monthly_rent'      => (float)$u['monthly_rent'],
        'deposit_amount'    => (float)$u['deposit_amount'],
        'electricity_meter' => $u['electricity_meter'] ?? '',
        'water_meter'       => $u['water_meter']       ?? '',
        'lease_start'       => $u['lease_start']       ?? null,
        'lease_end'         => $u['lease_end']         ?? null,
        'tenant_id'         => $u['tenant_id']         ?? null,
        'tenant_name'       => $u['tenant_name']       ?? null,
        'tenant_phone'      => $u['tenant_phone']      ?? null,
        'tenant_email'      => $u['tenant_email']      ?? null,
        'outstanding_balance' => (float)($u['outstanding_balance'] ?? 0),
        'unpaid_count'      => (int)($u['unpaid_invoice_count']   ?? 0),
    ];
}

// ── Core stats ────────────────────────────────────────────────────────
$totalUnits    = count($units);
$occupiedUnits = 0; $vacantUnits = 0; $totalRent = 0; $totalOutstanding = 0;
foreach ($units as $u) {
    if ($u['status'] === 'Occupied')      { $occupiedUnits++; $totalRent += $u['monthly_rent']; }
    elseif ($u['status'] === 'Available')   $vacantUnits++;
    $totalOutstanding += (float)($u['outstanding_balance'] ?? 0);
}
$occupancyRate = $totalUnits > 0 ? ($occupiedUnits / $totalUnits) * 100 : 0;

// ── Active tenants in this property ──────────────────────────────────
$stmt = $pdo->prepare("
    SELECT t.id, t.full_name, t.email, t.phone, t.status AS tenant_status,
           u.unit_number, u.id AS unit_id,
           l.id AS lease_id, l.monthly_rent, l.start_date, l.end_date,
           COALESCE((SELECT SUM(i.amount) FROM invoices i
                     WHERE i.tenant_id = t.id AND i.status NOT IN ('Paid','Cancelled')), 0) AS outstanding
    FROM tenants t
    JOIN leases l ON l.tenant_id = t.id AND l.status = 'Active'
    JOIN units  u ON l.unit_id = u.id
    WHERE u.property_id = ?
    ORDER BY t.full_name
");
$stmt->execute([$propertyId]);
$activeTenantsHere = $stmt->fetchAll();

// ── Income aggregates ─────────────────────────────────────────────────
$incomeAggs = ['mtd' => 0, 'ytd' => 0, 'all_time' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN MONTH(tx.transaction_date)=MONTH(NOW()) AND YEAR(tx.transaction_date)=YEAR(NOW()) THEN tx.amount ELSE 0 END),0) AS mtd,
            COALESCE(SUM(CASE WHEN YEAR(tx.transaction_date)=YEAR(NOW()) THEN tx.amount ELSE 0 END),0) AS ytd,
            COALESCE(SUM(tx.amount),0) AS all_time
        FROM transactions tx
        WHERE tx.status = 'Paid'
          AND tx.tenant_id IN (
              SELECT DISTINCT l.tenant_id FROM leases l
              JOIN units u ON l.unit_id = u.id WHERE u.property_id = ?
          )
    ");
    $stmt->execute([$propertyId]);
    $row = $stmt->fetch();
    if ($row) $incomeAggs = $row;
} catch (PDOException $_e) {}

// ── 6-month income trend ──────────────────────────────────────────────
$_trendRaw = [];
try {
    $_ts = $pdo->prepare("
        SELECT DATE_FORMAT(tx.transaction_date,'%Y-%m') AS ym, SUM(tx.amount) AS tot
        FROM transactions tx
        WHERE tx.status = 'Paid'
          AND tx.transaction_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
          AND tx.tenant_id IN (
              SELECT DISTINCT l.tenant_id FROM leases l
              JOIN units u ON l.unit_id = u.id WHERE u.property_id = ?
          )
        GROUP BY ym
    ");
    $_ts->execute([$propertyId]);
    foreach ($_ts->fetchAll() as $_tr) $_trendRaw[$_tr['ym']] = (float)$_tr['tot'];
} catch (PDOException $_e) {}
$trendLabels = $trendValues = [];
for ($i = 5; $i >= 0; $i--) {
    $trendLabels[] = date('M', strtotime("-$i months"));
    $trendValues[] = $_trendRaw[date('Y-m', strtotime("-$i months"))] ?? 0;
}

// ── Transactions for statement tab ────────────────────────────────────
$propertyTransactions = [];
try {
    $stmt = $pdo->prepare("
        SELECT tx.*, t.full_name AS tenant_name
        FROM transactions tx
        JOIN tenants t ON tx.tenant_id = t.id
        WHERE tx.tenant_id IN (
            SELECT DISTINCT l.tenant_id FROM leases l
            JOIN units u ON l.unit_id = u.id WHERE u.property_id = ?
        )
        ORDER BY tx.transaction_date DESC
        LIMIT 200
    ");
    $stmt->execute([$propertyId]);
    $propertyTransactions = $stmt->fetchAll();
} catch (PDOException $_e) {}

$stmtPeriod = $_GET['stmt_period'] ?? 'all';
$stmtTxArr = match($stmtPeriod) {
    'month'   => array_values(array_filter($propertyTransactions, fn($t) => date('Y-m', strtotime($t['transaction_date'])) === date('Y-m'))),
    '3months' => array_values(array_filter($propertyTransactions, fn($t) => strtotime($t['transaction_date']) >= strtotime('-3 months'))),
    'ytd'     => array_values(array_filter($propertyTransactions, fn($t) => date('Y', strtotime($t['transaction_date'])) === date('Y'))),
    default   => $propertyTransactions,
};
$stmtCollected = array_sum(array_map(fn($t) => $t['status'] === 'Paid' ? (float)$t['amount'] : 0, $stmtTxArr));

// ── Maintenance requests ──────────────────────────────────────────────
$maintenanceRequests = [];
$pendingMaint = 0;
try {
    $stmt = $pdo->prepare("
        SELECT m.*, u.unit_number, t.full_name AS tenant_name
        FROM maintenance_requests m
        LEFT JOIN units u ON m.unit_id = u.id
        LEFT JOIN tenants t ON m.tenant_id = t.id
        WHERE u.property_id = ?
        ORDER BY m.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$propertyId]);
    $maintenanceRequests = $stmt->fetchAll();
    $pendingMaint = count(array_filter($maintenanceRequests, fn($m) => ($m['status'] ?? '') === 'Pending'));
} catch (PDOException $_e) {}

// ── Landlords list for settings ───────────────────────────────────────
$landlords = [];
try {
    $landlords = $pdo->query("SELECT id, full_name FROM landlords ORDER BY full_name")->fetchAll();
} catch (PDOException $_e) {}

// ── Flash + tabs ──────────────────────────────────────────────────────
$flash    = $_GET['success'] ?? '';
$flashErr = $_GET['error']   ?? '';
$activeTab = $_GET['tab'] ?? 'overview';
$validTabs = ['overview', 'units', 'tenants', 'statement', 'maintenance', 'settings'];
if (!in_array($activeTab, $validTabs)) $activeTab = 'overview';

$pageTitle = ($property['title'] ?? 'Property') . " | Details";
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-6 animate-in">

<?php
$flashMsg = match($flash) {
    'unit_created'      => 'Unit registered successfully.',
    'unit_updated'      => 'Unit updated.',
    'unit_deleted'      => 'Unit deleted permanently.',
    'settings_updated'  => 'Property settings saved.',
    default             => '',
};
$flashErrMsg = match($flashErr) {
    'has_tenant' => 'Cannot delete: unit has an active tenant. Terminate the lease first.',
    'not_found'  => 'Unit not found.',
    default      => $flashErr ? htmlspecialchars($flashErr) : '',
};
?>
<?php if ($flashMsg): ?>
<div class="p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-2xl text-green-700 dark:text-green-400 text-sm font-bold"><?php echo $flashMsg; ?></div>
<?php elseif ($flashErrMsg): ?>
<div class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-2xl text-red-700 dark:text-red-400 text-sm font-bold"><?php echo $flashErrMsg; ?></div>
<?php endif; ?>

<!-- ══ HERO ════════════════════════════════════════════════════════════ -->
<div class="glass-card overflow-hidden">
    <?php
    $heroImgs = json_decode($property['images'] ?? '[]', true);
    $heroImg  = !empty($heroImgs) ? $heroImgs[0] : null;
    ?>
    <?php if ($heroImg): ?>
    <div class="relative h-28 overflow-hidden">
        <img src="<?php echo htmlspecialchars($heroImg); ?>" class="w-full h-full object-cover opacity-30">
        <div class="absolute inset-0 bg-gradient-to-b from-slate-900/60 to-slate-900"></div>
    </div>
    <div class="bg-slate-900 px-8 pb-8 -mt-1 relative overflow-hidden">
    <?php else: ?>
    <div class="bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 p-8 lg:p-10 relative overflow-hidden">
    <?php endif; ?>
        <div class="absolute -right-20 -top-20 w-80 h-80 bg-accent-green/5 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute -left-10 -bottom-10 w-60 h-60 bg-blue-500/5 rounded-full blur-3xl pointer-events-none"></div>

        <!-- Back link -->
        <a href="properties.php" class="inline-flex items-center gap-1.5 text-[10px] font-black text-slate-400 uppercase tracking-widest hover:text-accent-green transition-colors mb-6">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m15 18-6-6 6-6"/></svg>
            Back to Registry
        </a>

        <div class="flex flex-col lg:flex-row lg:items-start gap-6">
            <!-- Property icon -->
            <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-accent-green to-emerald-600 flex items-center justify-center shadow-xl ring-4 ring-accent-green/20 shrink-0">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            </div>

            <!-- Property info -->
            <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="px-2.5 py-1 bg-accent-green/20 text-accent-green rounded-full text-[9px] font-black uppercase tracking-widest border border-accent-green/30">
                        <?php echo htmlspecialchars($property['status'] ?? 'Active'); ?>
                    </span>
                    <span class="px-2.5 py-1 bg-blue-500/20 text-blue-400 rounded-full text-[9px] font-black uppercase tracking-widest border border-blue-500/20">
                        <?php echo htmlspecialchars($property['property_type'] ?? ''); ?>
                    </span>
                    <?php if (!empty($property['property_code'])): ?>
                    <span class="px-2.5 py-1 bg-slate-700 text-slate-300 rounded-full text-[9px] font-black uppercase tracking-widest border border-slate-600">
                        # <?php echo htmlspecialchars($property['property_code']); ?>
                    </span>
                    <?php endif; ?>
                </div>
                <h1 class="text-3xl lg:text-4xl font-black text-white tracking-tight mb-1">
                    <?php echo htmlspecialchars($property['title'] ?? ''); ?>
                </h1>
                <p class="text-slate-400 font-medium flex items-center gap-1.5 text-sm">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                    <?php echo htmlspecialchars($property['location'] ?? ''); ?>
                </p>
                <?php if ($property['landlord_name']): ?>
                <p class="text-slate-400 font-medium flex items-center gap-1.5 text-sm mt-1">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Owned by
                    <?php if (!empty($property['landlord_id'])): ?>
                    <a href="landlord_profile.php?id=<?php echo htmlspecialchars($property['landlord_id']); ?>" class="text-accent-green hover:underline font-black"><?php echo htmlspecialchars($property['landlord_name']); ?></a>
                    <?php else: ?>
                    <span class="text-slate-300 font-black"><?php echo htmlspecialchars($property['landlord_name']); ?></span>
                    <?php endif; ?>
                </p>
                <?php endif; ?>
            </div>

            <!-- Hero actions -->
            <div class="flex items-center gap-3 shrink-0">
                <button onclick="openModal('addUnitModal')" class="btn-green shadow-lg shadow-accent-green/20 text-sm">
                    + Add Unit
                </button>
                <button onclick="window.print()" class="p-3 bg-slate-800 border border-slate-700 rounded-xl text-slate-400 hover:text-accent-green transition-all">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                </button>
            </div>
        </div>

        <!-- Hero stats -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-8 pt-6 border-t border-slate-700/50">
            <div class="text-center">
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Occupancy</p>
                <p class="text-2xl font-black <?php echo $occupancyRate >= 80 ? 'text-accent-green' : ($occupancyRate >= 50 ? 'text-accent-orange' : 'text-red-400'); ?>">
                    <?php echo round($occupancyRate, 1); ?>%
                </p>
                <p class="text-[9px] text-slate-500 font-medium mt-0.5"><?php echo $occupiedUnits; ?>/<?php echo $totalUnits; ?> units</p>
            </div>
            <div class="text-center">
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Monthly Rent</p>
                <p class="text-2xl font-black text-white"><?php echo $currency; ?> <?php echo number_format($totalRent); ?></p>
                <p class="text-[9px] text-slate-500 font-medium mt-0.5">projected</p>
            </div>
            <div class="text-center">
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Outstanding</p>
                <p class="text-2xl font-black <?php echo $totalOutstanding > 0 ? 'text-red-400' : 'text-slate-400'; ?>">
                    <?php echo $currency; ?> <?php echo number_format($totalOutstanding); ?>
                </p>
                <p class="text-[9px] text-slate-500 font-medium mt-0.5">across all units</p>
            </div>
            <div class="text-center">
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Active Tenants</p>
                <p class="text-2xl font-black <?php echo count($activeTenantsHere) > 0 ? 'text-accent-green' : 'text-slate-400'; ?>">
                    <?php echo count($activeTenantsHere); ?>
                </p>
                <p class="text-[9px] text-slate-500 font-medium mt-0.5"><?php echo $vacantUnits; ?> vacant</p>
            </div>
        </div>
    </div>
</div>

<!-- ══ TAB NAV ══════════════════════════════════════════════════════════ -->
<?php
$tabs = [
    'overview'    => ['Overview', '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>', ''],
    'units'       => ['Units', '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>', $totalUnits > 0 ? (string)$totalUnits : ''],
    'tenants'     => ['Tenants', '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>', count($activeTenantsHere) > 0 ? (string)count($activeTenantsHere) : ''],
    'statement'   => ['Statement', '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>', ''],
    'maintenance' => ['Maintenance', '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>', $pendingMaint > 0 ? (string)$pendingMaint : ''],
    'settings'    => ['Settings', '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>', ''],
];
?>
<div class="glass-card p-2 overflow-x-auto">
    <nav class="flex gap-1 min-w-max">
        <?php foreach ($tabs as $key => [$label, $icon, $badge]): ?>
        <button onclick="switchTab('<?php echo $key; ?>')"
            class="tab-btn flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-black transition-all <?php echo $activeTab === $key ? 'bg-accent-green text-slate-900' : 'text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'; ?>"
            data-tab="<?php echo $key; ?>">
            <?php echo $icon; ?>
            <span><?php echo $label; ?></span>
            <?php if ($badge): ?>
            <span class="ml-0.5 px-1.5 py-0.5 bg-slate-900/20 text-current rounded text-[8px] font-black"><?php echo $badge; ?></span>
            <?php endif; ?>
        </button>
        <?php endforeach; ?>
    </nav>
</div>

<!-- ══ TAB: OVERVIEW ════════════════════════════════════════════════════ -->
<div id="content-overview" class="tab-content <?php echo $activeTab !== 'overview' ? 'hidden' : ''; ?> space-y-6">

    <!-- KPI row -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="glass-card p-5 border-l-4 border-accent-green">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Occupancy</p>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white"><?php echo round($occupancyRate, 1); ?>%</h3>
            <div class="w-full bg-slate-100 dark:bg-slate-800 h-1.5 rounded-full mt-2 overflow-hidden">
                <div class="bg-accent-green h-full rounded-full" style="width:<?php echo $occupancyRate; ?>%"></div>
            </div>
        </div>
        <div class="glass-card p-5 border-l-4 border-emerald-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">MTD Income</p>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($incomeAggs['mtd']); ?></h3>
            <p class="text-[10px] font-bold text-slate-400 mt-1 uppercase"><?php echo date('F Y'); ?></p>
        </div>
        <div class="glass-card p-5 border-l-4 border-blue-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">YTD Income</p>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($incomeAggs['ytd']); ?></h3>
            <p class="text-[10px] font-bold text-slate-400 mt-1 uppercase">Year to date</p>
        </div>
        <div class="glass-card p-5 border-l-4 border-accent-orange">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Rent Roll</p>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($totalRent); ?></h3>
            <p class="text-[10px] font-bold text-slate-400 mt-1 uppercase">Monthly projected</p>
        </div>
        <div class="glass-card p-5 border-l-4 border-red-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Outstanding</p>
            <h3 class="text-2xl font-black <?php echo $totalOutstanding > 0 ? 'text-red-500' : 'text-slate-900 dark:text-white'; ?>"><?php echo $currency; ?> <?php echo number_format($totalOutstanding); ?></h3>
            <p class="text-[10px] font-bold text-slate-400 mt-1 uppercase">All tenants</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

        <!-- Income chart -->
        <div class="lg:col-span-8 glass-card p-6">
            <h3 class="text-sm font-black tracking-tight mb-4">6-Month Income Trend</h3>
            <div class="relative h-48">
                <canvas id="incomeTrendChart"></canvas>
            </div>
        </div>

        <!-- Property quick info -->
        <div class="lg:col-span-4 space-y-4">
            <!-- Landlord -->
            <div class="glass-card p-5">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">Landlord</p>
                <?php if ($property['landlord_name']): ?>
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-accent-green to-emerald-600 flex items-center justify-center text-white font-black text-sm shrink-0">
                        <?php echo strtoupper(substr($property['landlord_name'], 0, 1)); ?>
                    </div>
                    <div class="min-w-0">
                        <?php if (!empty($property['landlord_id'])): ?>
                        <a href="landlord_profile.php?id=<?php echo htmlspecialchars($property['landlord_id']); ?>" class="font-black text-slate-900 dark:text-white hover:text-accent-green transition-colors text-sm truncate block"><?php echo htmlspecialchars($property['landlord_name']); ?></a>
                        <?php else: ?>
                        <p class="font-black text-slate-900 dark:text-white text-sm"><?php echo htmlspecialchars($property['landlord_name']); ?></p>
                        <?php endif; ?>
                        <p class="text-[10px] text-slate-400 font-medium truncate"><?php echo htmlspecialchars($property['landlord_phone'] ?? ''); ?></p>
                    </div>
                </div>
                <?php else: ?>
                <p class="text-xs text-slate-400 italic">No landlord assigned</p>
                <?php endif; ?>
            </div>
            <!-- Utilities -->
            <div class="glass-card p-5">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">Utility Rates</p>
                <div class="space-y-2">
                    <div class="flex justify-between items-center p-2.5 bg-blue-500/5 rounded-xl border border-blue-500/10">
                        <p class="text-xs font-bold text-slate-600 dark:text-slate-400">💧 Water</p>
                        <p class="text-xs font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($property['water_rate'] ?? 0, 2); ?>/unit</p>
                    </div>
                    <div class="flex justify-between items-center p-2.5 bg-orange-500/5 rounded-xl border border-orange-500/10">
                        <p class="text-xs font-bold text-slate-600 dark:text-slate-400">🗑️ Garbage</p>
                        <p class="text-xs font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($property['garbage_fee'] ?? 0); ?>/mo</p>
                    </div>
                    <div class="flex justify-between items-center p-2.5 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                        <p class="text-xs font-bold text-slate-600 dark:text-slate-400">📐 Area</p>
                        <p class="text-xs font-black text-slate-900 dark:text-white"><?php echo number_format($property['area'] ?? 0); ?> sqft</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent activity grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- Recent tenants -->
        <div class="glass-card overflow-hidden">
            <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
                <h3 class="text-sm font-black tracking-tight">Active Tenants</h3>
                <button onclick="switchTab('tenants')" class="text-[10px] font-black text-accent-green uppercase tracking-widest hover:underline">View All</button>
            </div>
            <?php if (empty($activeTenantsHere)): ?>
            <div class="p-8 text-center text-slate-400 italic text-xs font-medium">No active tenants</div>
            <?php else: ?>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php foreach (array_slice($activeTenantsHere, 0, 5) as $ten): ?>
                <div class="flex items-center gap-3 p-4 hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-green-500 to-emerald-600 flex items-center justify-center text-white font-black text-xs shrink-0">
                        <?php echo strtoupper(substr($ten['full_name'], 0, 1)); ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <a href="tenant_details.php?id=<?php echo $ten['id']; ?>" class="text-sm font-black text-slate-900 dark:text-white hover:text-accent-green transition-colors truncate block"><?php echo htmlspecialchars($ten['full_name']); ?></a>
                        <p class="text-[10px] text-slate-400 font-medium">Unit <?php echo htmlspecialchars($ten['unit_number']); ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs font-black <?php echo (float)$ten['outstanding'] > 0 ? 'text-red-500' : 'text-accent-green'; ?>"><?php echo $currency; ?> <?php echo number_format($ten['outstanding']); ?></p>
                        <p class="text-[9px] text-slate-400">outstanding</p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Recent maintenance -->
        <div class="glass-card overflow-hidden">
            <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
                <h3 class="text-sm font-black tracking-tight">Maintenance <span class="text-slate-400 font-medium text-xs"><?php echo $pendingMaint > 0 ? "· {$pendingMaint} pending" : ''; ?></span></h3>
                <button onclick="switchTab('maintenance')" class="text-[10px] font-black text-accent-green uppercase tracking-widest hover:underline">View All</button>
            </div>
            <?php if (empty($maintenanceRequests)): ?>
            <div class="p-8 text-center text-slate-400 italic text-xs font-medium">No maintenance requests</div>
            <?php else: ?>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php foreach (array_slice($maintenanceRequests, 0, 5) as $mr): ?>
                <?php
                $mStatus = $mr['status'] ?? 'Pending';
                $mColor = match($mStatus) {
                    'Resolved'   => 'bg-green-500/10 text-green-500',
                    'InProgress' => 'bg-blue-500/10 text-blue-500',
                    default      => 'bg-orange-500/10 text-orange-500',
                };
                ?>
                <div class="flex items-start gap-3 p-4">
                    <span class="px-2 py-0.5 <?php echo $mColor; ?> rounded text-[9px] font-black uppercase mt-0.5 shrink-0"><?php echo htmlspecialchars($mStatus); ?></span>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-black text-slate-900 dark:text-white truncate"><?php echo htmlspecialchars($mr['title'] ?? $mr['description'] ?? 'Request'); ?></p>
                        <p class="text-[10px] text-slate-400">Unit <?php echo htmlspecialchars($mr['unit_number'] ?? '—'); ?> · <?php echo htmlspecialchars($mr['tenant_name'] ?? '—'); ?></p>
                    </div>
                    <p class="text-[9px] text-slate-400 shrink-0"><?php echo date('d M', strtotime($mr['created_at'] ?? 'now')); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- Property description -->
    <?php if (!empty($property['description'])): ?>
    <div class="glass-card p-6">
        <h3 class="text-sm font-black tracking-tight mb-3">About This Property</h3>
        <div class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed font-medium">
            <?php echo nl2br(htmlspecialchars($property['description'])); ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Gallery -->
    <?php
    $imgs = json_decode($property['images'] ?? '[]', true);
    if (!empty($imgs) && count($imgs) > 1):
    ?>
    <div class="glass-card p-6">
        <h3 class="text-sm font-black tracking-tight mb-4">Gallery</h3>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <?php foreach (array_slice($imgs, 0, 8) as $img): ?>
            <div class="h-28 rounded-xl overflow-hidden shadow-inner">
                <img src="<?php echo htmlspecialchars($img); ?>" class="w-full h-full object-cover hover:scale-105 transition-transform duration-300">
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /overview -->

<!-- ══ TAB: UNITS ════════════════════════════════════════════════════════ -->
<div id="content-units" class="tab-content <?php echo $activeTab !== 'units' ? 'hidden' : ''; ?> space-y-6">

    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center flex-wrap gap-3">
            <div>
                <h2 class="text-lg font-black tracking-tight">Unit Registry</h2>
                <p class="text-xs text-slate-400 font-medium mt-0.5"><?php echo $occupiedUnits; ?> occupied · <?php echo $vacantUnits; ?> vacant · <?php echo $totalUnits - $occupiedUnits - $vacantUnits; ?> maintenance</p>
            </div>
            <button onclick="openModal('addUnitModal')" class="btn-green text-sm">+ Add Unit</button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead class="bg-slate-50/50 dark:bg-slate-800/30">
                    <tr>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Unit #</th>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Type & Meters</th>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tenant</th>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Rent</th>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Status</th>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php if (empty($units)): ?>
                        <tr><td colspan="6" class="p-12 text-center text-slate-400 font-medium italic">No units registered. <button onclick="openModal('addUnitModal')" class="text-accent-green hover:underline font-black">Add the first unit</button>.</td></tr>
                    <?php else: ?>
                        <?php foreach ($units as $unit): ?>
                        <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all group cursor-pointer"
                            onclick="openUnitPanel('<?php echo $unit['id']; ?>')"
                            title="Click to view unit details">
                            <td class="p-4">
                                <div class="flex items-center gap-3">
                                    <?php
                                    $uImgs = json_decode($unit['images'] ?? '[]', true);
                                    $uImg  = !empty($uImgs) ? $uImgs[0] : 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?q=80&w=200';
                                    ?>
                                    <div class="w-10 h-10 rounded-lg overflow-hidden border border-slate-100 dark:border-slate-800 shrink-0">
                                        <img src="<?php echo htmlspecialchars($uImg); ?>" class="w-full h-full object-cover">
                                    </div>
                                    <div>
                                        <p class="font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars((string)($unit['unit_number'] ?? '')); ?></p>
                                        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-tighter">Floor <?php echo (string)(($unit['floor_number'] ?? '') ?: 'G'); ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="p-4">
                                <p class="text-xs font-bold text-slate-600 dark:text-slate-400"><?php echo htmlspecialchars((string)($unit['unit_type'] ?? '')); ?></p>
                                <div class="flex gap-1.5 items-center mt-1">
                                    <span class="text-[8px] font-black uppercase px-1.5 py-0.5 bg-blue-500/10 text-blue-500 rounded border border-blue-500/10">⚡ <?php echo htmlspecialchars(($unit['electricity_meter'] ?? '') ?: '—'); ?></span>
                                    <span class="text-[8px] font-black uppercase px-1.5 py-0.5 bg-cyan-500/10 text-cyan-500 rounded border border-cyan-500/10">💧 <?php echo htmlspecialchars(($unit['water_meter'] ?? '') ?: '—'); ?></span>
                                </div>
                            </td>
                            <td class="p-4">
                                <?php if ($unit['tenant_name']): ?>
                                <a href="tenant_details.php?id=<?php echo $unit['tenant_id']; ?>" class="text-sm font-black text-slate-900 dark:text-white hover:text-accent-green transition-colors" onclick="event.stopPropagation()">
                                    <?php echo htmlspecialchars($unit['tenant_name']); ?>
                                </a>
                                <?php if ((float)($unit['outstanding_balance'] ?? 0) > 0): ?>
                                <p class="text-[10px] font-black text-red-500">Owes <?php echo $currency; ?> <?php echo number_format($unit['outstanding_balance']); ?></p>
                                <?php endif; ?>
                                <?php else: ?>
                                <span class="text-xs text-slate-400 italic">Vacant</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-4 text-right font-black text-sm">
                                <?php echo $currency; ?> <?php echo number_format($unit['monthly_rent']); ?>
                            </td>
                            <td class="p-4 text-center">
                                <span class="px-2.5 py-1 <?php echo $unit['status'] === 'Occupied' ? 'bg-accent-green/10 text-accent-green border-accent-green/20' : ($unit['status'] === 'Maintenance' ? 'bg-slate-100 dark:bg-slate-700 text-slate-500 border-slate-200 dark:border-slate-600' : 'bg-accent-orange/10 text-accent-orange border-accent-orange/20'); ?> rounded-full text-[9px] font-black uppercase tracking-widest border">
                                    <?php echo $unit['status']; ?>
                                </span>
                            </td>
                            <td class="p-4 text-right" onclick="event.stopPropagation()">
                                <div class="relative inline-block unit-menu-wrap">
                                    <button onclick="toggleUnitMenu(this)" class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-all">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="1" fill="currentColor"/><circle cx="12" cy="12" r="1" fill="currentColor"/><circle cx="12" cy="19" r="1" fill="currentColor"/></svg>
                                    </button>
                                    <div class="unit-action-menu hidden">
                                        <button onclick="openUnitPanel('<?php echo $unit['id']; ?>')" class="unit-action-item">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            View
                                        </button>
                                        <button onclick="openEditUnitModal(<?php echo json_encode($unit); ?>)" class="unit-action-item">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                                            Edit
                                        </button>
                                        <button onclick="confirmDeleteUnit('<?php echo $unit['id']; ?>', '<?php echo htmlspecialchars(addslashes($unit['unit_number'])); ?>')" class="unit-action-item text-red-500 hover:bg-red-50 dark:hover:bg-red-900/10">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /units -->

<!-- ══ TAB: TENANTS ══════════════════════════════════════════════════════ -->
<div id="content-tenants" class="tab-content <?php echo $activeTab !== 'tenants' ? 'hidden' : ''; ?>">

    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800">
            <h2 class="text-lg font-black tracking-tight">Active Tenants</h2>
            <p class="text-xs text-slate-400 font-medium mt-0.5"><?php echo count($activeTenantsHere); ?> tenants with active leases in this property</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead class="bg-slate-50/50 dark:bg-slate-800/30">
                    <tr>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tenant</th>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Unit</th>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Monthly Rent</th>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Lease Ends</th>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Outstanding</th>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php if (empty($activeTenantsHere)): ?>
                    <tr><td colspan="6" class="p-12 text-center text-slate-400 italic text-xs font-medium">No active tenants found for this property.</td></tr>
                    <?php else: ?>
                    <?php foreach ($activeTenantsHere as $ten): ?>
                    <?php
                    $leaseEnd = $ten['end_date'] ? new DateTime($ten['end_date']) : null;
                    $daysLeft  = $leaseEnd ? (new DateTime())->diff($leaseEnd)->days * ((new DateTime()) < $leaseEnd ? 1 : -1) : null;
                    $expiring  = $daysLeft !== null && $daysLeft <= 60 && $daysLeft >= 0;
                    $expired   = $daysLeft !== null && $daysLeft < 0;
                    ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-colors">
                        <td class="p-4">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-green-500 to-emerald-600 flex items-center justify-center text-white font-black text-sm shrink-0">
                                    <?php echo strtoupper(substr($ten['full_name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <p class="font-black text-slate-900 dark:text-white text-sm"><?php echo htmlspecialchars($ten['full_name']); ?></p>
                                    <p class="text-[10px] text-slate-400 font-medium"><?php echo htmlspecialchars($ten['phone'] ?? ''); ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="p-4 font-bold text-sm text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($ten['unit_number']); ?></td>
                        <td class="p-4 text-right font-black text-sm"><?php echo $currency; ?> <?php echo number_format($ten['monthly_rent']); ?></td>
                        <td class="p-4">
                            <?php if ($ten['end_date']): ?>
                            <span class="text-xs font-bold <?php echo $expired ? 'text-red-500' : ($expiring ? 'text-amber-500' : 'text-slate-600 dark:text-slate-400'); ?>">
                                <?php echo date('d M Y', strtotime($ten['end_date'])); ?>
                                <?php if ($expired): ?><span class="text-[9px] ml-1">(Expired)</span><?php elseif ($expiring): ?><span class="text-[9px] ml-1">(<?php echo $daysLeft; ?>d left)</span><?php endif; ?>
                            </span>
                            <?php else: ?>
                            <span class="text-xs text-slate-400 italic">Open-ended</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-4 text-right">
                            <span class="text-sm font-black <?php echo (float)$ten['outstanding'] > 0 ? 'text-red-500' : 'text-accent-green'; ?>">
                                <?php echo $currency; ?> <?php echo number_format($ten['outstanding']); ?>
                            </span>
                        </td>
                        <td class="p-4 text-right">
                            <a href="tenant_details.php?id=<?php echo $ten['id']; ?>" class="px-3 py-1.5 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg text-[10px] font-black hover:bg-accent-green hover:text-slate-900 transition-all">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /tenants -->

<!-- ══ TAB: STATEMENT ════════════════════════════════════════════════════ -->
<div id="content-statement" class="tab-content <?php echo $activeTab !== 'statement' ? 'hidden' : ''; ?> space-y-6">

    <!-- Period filter -->
    <div class="glass-card p-4">
        <div class="flex flex-wrap gap-2 items-center">
            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest mr-2">Period:</span>
            <?php foreach (['month' => 'This Month', '3months' => 'Last 3 Months', 'ytd' => 'Year to Date', 'all' => 'All Time'] as $pk => $pl): ?>
            <a href="?id=<?php echo $propertyId; ?>&tab=statement&stmt_period=<?php echo $pk; ?>"
               class="px-3 py-1.5 rounded-lg text-xs font-black transition-all <?php echo $stmtPeriod === $pk ? 'bg-accent-green text-slate-900' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400 hover:bg-slate-200 dark:hover:bg-slate-700'; ?>">
                <?php echo $pl; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Statement KPIs -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="glass-card p-5 border-l-4 border-accent-green">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Collected</p>
            <h3 class="text-2xl font-black text-accent-green"><?php echo $currency; ?> <?php echo number_format($stmtCollected); ?></h3>
            <p class="text-[10px] font-bold text-slate-400 mt-1 uppercase"><?php echo match($stmtPeriod) { 'month' => date('F Y'), '3months' => 'Last 3 months', 'ytd' => 'Year ' . date('Y'), default => 'All time' }; ?></p>
        </div>
        <div class="glass-card p-5 border-l-4 border-blue-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Transactions</p>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white"><?php echo count($stmtTxArr); ?></h3>
        </div>
        <div class="glass-card p-5 border-l-4 border-red-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Outstanding</p>
            <h3 class="text-2xl font-black text-red-500"><?php echo $currency; ?> <?php echo number_format($totalOutstanding); ?></h3>
            <p class="text-[10px] font-bold text-slate-400 mt-1 uppercase">All active tenants</p>
        </div>
    </div>

    <!-- Transactions table -->
    <div class="glass-card overflow-hidden">
        <div class="p-5 border-b border-slate-100 dark:border-slate-800">
            <h3 class="text-sm font-black tracking-tight">Transaction History</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead class="bg-slate-50/50 dark:bg-slate-800/30">
                    <tr>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tenant</th>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Type</th>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Reference</th>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Amount</th>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php if (empty($stmtTxArr)): ?>
                    <tr><td colspan="6" class="p-10 text-center text-slate-400 italic text-xs font-medium">No transactions for this period.</td></tr>
                    <?php else: ?>
                    <?php foreach ($stmtTxArr as $tx): ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-colors">
                        <td class="p-4 text-xs font-bold text-slate-600 dark:text-slate-400"><?php echo date('d M Y', strtotime($tx['transaction_date'])); ?></td>
                        <td class="p-4 text-xs font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($tx['tenant_name'] ?? '—'); ?></td>
                        <td class="p-4 text-xs font-bold text-slate-600 dark:text-slate-400"><?php echo htmlspecialchars($tx['transaction_type'] ?? 'Payment'); ?></td>
                        <td class="p-4 text-xs font-mono text-slate-500"><?php echo htmlspecialchars($tx['reference_code'] ?? '—'); ?></td>
                        <td class="p-4 text-right font-black text-sm"><?php echo $currency; ?> <?php echo number_format($tx['amount']); ?></td>
                        <td class="p-4 text-center">
                            <?php
                            $txStatus = $tx['status'] ?? 'Paid';
                            $txColor = match($txStatus) { 'Paid' => 'bg-green-500/10 text-green-500', 'Pending' => 'bg-orange-500/10 text-orange-500', default => 'bg-slate-100 dark:bg-slate-700 text-slate-500' };
                            ?>
                            <span class="px-2.5 py-1 <?php echo $txColor; ?> rounded-full text-[9px] font-black uppercase"><?php echo htmlspecialchars($txStatus); ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /statement -->

<!-- ══ TAB: MAINTENANCE ══════════════════════════════════════════════════ -->
<div id="content-maintenance" class="tab-content <?php echo $activeTab !== 'maintenance' ? 'hidden' : ''; ?>">

    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
            <div>
                <h2 class="text-lg font-black tracking-tight">Maintenance Requests</h2>
                <p class="text-xs text-slate-400 font-medium mt-0.5"><?php echo count($maintenanceRequests); ?> total · <?php echo $pendingMaint; ?> pending</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead class="bg-slate-50/50 dark:bg-slate-800/30">
                    <tr>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Request</th>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Unit</th>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Reported By</th>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                        <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php if (empty($maintenanceRequests)): ?>
                    <tr><td colspan="5" class="p-12 text-center text-slate-400 italic text-xs font-medium">No maintenance requests for this property.</td></tr>
                    <?php else: ?>
                    <?php foreach ($maintenanceRequests as $mr): ?>
                    <?php
                    $mrStatus = $mr['status'] ?? 'Pending';
                    $mrColor  = match($mrStatus) {
                        'Resolved'   => 'bg-green-500/10 text-green-500 border-green-500/20',
                        'InProgress' => 'bg-blue-500/10 text-blue-500 border-blue-500/20',
                        default      => 'bg-orange-500/10 text-orange-500 border-orange-500/20',
                    };
                    ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-colors">
                        <td class="p-4">
                            <p class="text-sm font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($mr['title'] ?? ($mr['description'] ?? 'Request')); ?></p>
                            <?php if (!empty($mr['description']) && !empty($mr['title'])): ?>
                            <p class="text-[10px] text-slate-400 font-medium mt-0.5 line-clamp-1"><?php echo htmlspecialchars($mr['description']); ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="p-4 font-bold text-sm text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($mr['unit_number'] ?? '—'); ?></td>
                        <td class="p-4 text-xs font-bold text-slate-600 dark:text-slate-400"><?php echo htmlspecialchars($mr['tenant_name'] ?? 'Staff'); ?></td>
                        <td class="p-4 text-xs font-bold text-slate-500"><?php echo date('d M Y', strtotime($mr['created_at'] ?? 'now')); ?></td>
                        <td class="p-4 text-center">
                            <span class="px-2.5 py-1 <?php echo $mrColor; ?> rounded-full text-[9px] font-black uppercase border"><?php echo htmlspecialchars($mrStatus); ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div><!-- /maintenance -->

<!-- ══ TAB: SETTINGS ═════════════════════════════════════════════════════ -->
<div id="content-settings" class="tab-content <?php echo $activeTab !== 'settings' ? 'hidden' : ''; ?> space-y-6">
    <?php if ($role !== 'admin' && $role !== 'staff'): ?>
    <div class="glass-card p-8 text-center text-slate-400 italic">Access restricted.</div>
    <?php else: ?>

    <form action="actions/property_actions.php" method="POST" enctype="multipart/form-data" class="space-y-6">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" value="<?php echo htmlspecialchars($propertyId); ?>">
        <input type="hidden" name="_redirect" value="property_details.php?id=<?php echo htmlspecialchars($propertyId); ?>&tab=settings&success=settings_updated">

        <!-- Basic Details -->
        <div class="glass-card p-6 space-y-5">
            <h3 class="text-sm font-black tracking-tight border-b border-slate-100 dark:border-slate-800 pb-3">Property Details</h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Property Title</label>
                    <input type="text" name="title" value="<?php echo htmlspecialchars($property['title'] ?? ''); ?>" required class="form-input">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Reference Code</label>
                    <input type="text" name="property_code" value="<?php echo htmlspecialchars($property['property_code'] ?? ''); ?>" placeholder="e.g. PRM-001" class="form-input">
                </div>
                <div class="space-y-2 md:col-span-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Location / Address</label>
                    <input type="text" name="location" value="<?php echo htmlspecialchars($property['location'] ?? ''); ?>" required class="form-input">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Property Type</label>
                    <select name="property_type" class="form-input">
                        <?php foreach (['Apartment', 'House', 'Commercial', 'Office', 'Warehouse', 'Land'] as $pt): ?>
                        <option value="<?php echo $pt; ?>" <?php echo ($property['property_type'] ?? '') === $pt ? 'selected' : ''; ?>><?php echo $pt; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</label>
                    <select name="status" class="form-input">
                        <?php foreach (['Available', 'Occupied', 'Under Maintenance', 'Inactive'] as $ps): ?>
                        <option value="<?php echo $ps; ?>" <?php echo ($property['status'] ?? '') === $ps ? 'selected' : ''; ?>><?php echo $ps; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Area (sqft)</label>
                    <input type="number" name="area" value="<?php echo htmlspecialchars($property['area'] ?? 0); ?>" class="form-input">
                </div>
            </div>

            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Description</label>
                <textarea name="description" rows="3" class="form-input resize-none"><?php echo htmlspecialchars($property['description'] ?? ''); ?></textarea>
            </div>
        </div>

        <!-- Utility Rates -->
        <div class="glass-card p-6 space-y-5">
            <h3 class="text-sm font-black tracking-tight border-b border-slate-100 dark:border-slate-800 pb-3">Utility Rates</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Water Rate (per unit)</label>
                    <input type="number" name="water_rate" step="0.01" value="<?php echo htmlspecialchars($property['water_rate'] ?? 0); ?>" class="form-input">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Garbage Fee (per month)</label>
                    <input type="number" name="garbage_fee" step="0.01" value="<?php echo htmlspecialchars($property['garbage_fee'] ?? 0); ?>" class="form-input">
                </div>
            </div>
        </div>

        <!-- Landlord Assignment -->
        <div class="glass-card p-6 space-y-5">
            <h3 class="text-sm font-black tracking-tight border-b border-slate-100 dark:border-slate-800 pb-3">Landlord Assignment</h3>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Assigned Landlord</label>
                <select name="landlord_id" class="form-input">
                    <option value="">— No landlord / Managed directly —</option>
                    <?php foreach ($landlords as $ll): ?>
                    <option value="<?php echo $ll['id']; ?>" <?php echo ($property['landlord_id'] ?? '') === $ll['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($ll['full_name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Photo upload -->
        <div class="glass-card p-6 space-y-4">
            <h3 class="text-sm font-black tracking-tight border-b border-slate-100 dark:border-slate-800 pb-3">Property Images</h3>
            <?php if (!empty($heroImgs)): ?>
            <div class="grid grid-cols-3 sm:grid-cols-6 gap-2 mb-4">
                <?php foreach (array_slice($heroImgs, 0, 6) as $gi): ?>
                <div class="h-16 rounded-lg overflow-hidden">
                    <img src="<?php echo htmlspecialchars($gi); ?>" class="w-full h-full object-cover">
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Upload Additional Images</label>
                <input type="file" name="property_images[]" multiple accept="image/*" class="w-full p-4 border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-2xl text-xs text-slate-400 font-bold hover:border-accent-green transition-all">
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <button type="button" onclick="switchTab('overview')" class="px-6 py-3 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-xl text-sm font-black hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">Cancel</button>
            <button type="submit" class="btn-green px-8 py-3 rounded-xl text-sm shadow-lg shadow-accent-green/20">Save Settings →</button>
        </div>
    </form>
    <?php endif; ?>
</div><!-- /settings -->

</div><!-- /main space-y-6 -->

<!-- ══ MODALS ════════════════════════════════════════════════════════════ -->

<!-- Add Unit Modal -->
<div id="addUnitModal" class="modal-overlay" style="display:none;">
    <div class="modal-card" style="max-width:600px;">
        <button onclick="closeModal('addUnitModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-8 tracking-tight">Register New Unit</h2>
        <form action="actions/unit_actions.php" method="POST" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="property_id" value="<?php echo $propertyId; ?>">
            <input type="hidden" name="_redirect" value="property_details.php?id=<?php echo $propertyId; ?>&tab=units&success=unit_created">
            <div class="grid grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Unit Number</label>
                    <input type="text" name="unit_number" required placeholder="E.g. A102" class="form-input">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Floor</label>
                    <input type="text" name="floor_number" placeholder="E.g. Ground" class="form-input">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Unit Type</label>
                    <select name="unit_type" class="form-input">
                        <option>1 Bedroom</option><option>2 Bedroom</option><option>3 Bedroom</option>
                        <option>Studio</option><option>Penthouse</option><option>Shop/Retail</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Category</label>
                    <input type="text" name="category" placeholder="E.g. Residential Lux" class="form-input">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Electricity Meter #</label>
                    <input type="text" name="electricity_meter" class="form-input">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Water Meter #</label>
                    <input type="text" name="water_meter" class="form-input">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Monthly Rent (<?php echo $currency; ?>)</label>
                    <input type="number" name="monthly_rent" required class="form-input">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Deposit Amount (<?php echo $currency; ?>)</label>
                    <input type="number" name="deposit_amount" required class="form-input">
                </div>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Status</label>
                <select name="status" class="form-input">
                    <option value="Available">Available</option>
                    <option value="Occupied">Occupied</option>
                    <option value="Maintenance">Maintenance</option>
                </select>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Unit Images</label>
                <input type="file" name="unit_images[]" multiple class="w-full p-4 border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-2xl text-xs text-slate-400 font-bold hover:border-accent-green transition-all">
            </div>
            <button type="submit" class="btn-green w-full justify-center py-4 rounded-2xl shadow-xl shadow-accent-green/10 font-black">Register Unit →</button>
        </form>
    </div>
</div>

<!-- Edit Unit Modal -->
<div id="editUnitModal" class="modal-overlay" style="display:none;">
    <div class="modal-card" style="max-width:600px;">
        <button onclick="closeModal('editUnitModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-8 tracking-tight">Edit Unit Details</h2>
        <form action="actions/unit_actions.php" method="POST" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="unit_id" id="edit_unit_id">
            <input type="hidden" name="property_id" value="<?php echo $propertyId; ?>">
            <input type="hidden" name="_redirect" value="property_details.php?id=<?php echo $propertyId; ?>&tab=units&success=unit_updated">
            <div class="grid grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Unit Number</label>
                    <input type="text" name="unit_number" id="edit_unit_number" required class="form-input">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Floor</label>
                    <input type="text" name="floor_number" id="edit_floor_number" class="form-input">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Unit Type</label>
                    <select name="unit_type" id="edit_unit_type" class="form-input">
                        <option>1 Bedroom</option><option>2 Bedroom</option><option>3 Bedroom</option>
                        <option>Studio</option><option>Penthouse</option><option>Shop/Retail</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Category</label>
                    <input type="text" name="category" id="edit_category" class="form-input">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Electricity Meter #</label>
                    <input type="text" name="electricity_meter" id="edit_electricity_meter" class="form-input">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Water Meter #</label>
                    <input type="text" name="water_meter" id="edit_water_meter" class="form-input">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Monthly Rent (<?php echo $currency; ?>)</label>
                    <input type="number" name="monthly_rent" id="edit_rent_amount" required class="form-input">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Deposit Amount (<?php echo $currency; ?>)</label>
                    <input type="number" name="deposit_amount" id="edit_deposit_amount" required class="form-input">
                </div>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Status</label>
                <select name="status" id="edit_status" class="form-input">
                    <option value="Available">Available</option>
                    <option value="Occupied">Occupied</option>
                    <option value="Maintenance">Maintenance</option>
                </select>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Add More Images</label>
                <input type="file" name="unit_images[]" multiple class="w-full p-4 border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-2xl text-xs text-slate-400 font-bold hover:border-accent-green transition-all">
            </div>
            <button type="submit" class="btn-green w-full justify-center py-4 rounded-2xl shadow-xl shadow-accent-green/10 font-black">Update Unit Details →</button>
        </form>
    </div>
</div>

<!-- Unit Slide Panel -->
<div id="unitPanel" class="fixed inset-0 z-50 flex justify-end" style="display:none;">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeUnitPanel()"></div>
    <div class="relative w-full max-w-md bg-white dark:bg-slate-900 h-full overflow-y-auto shadow-2xl flex flex-col border-l border-slate-100 dark:border-slate-800">
        <div class="flex items-center justify-between px-6 py-5 border-b border-slate-100 dark:border-slate-800 shrink-0">
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Unit Details</p>
                <h2 class="text-2xl font-black text-slate-900 dark:text-white" id="panel_unit_title">Unit —</h2>
            </div>
            <button onclick="closeUnitPanel()" class="w-9 h-9 flex items-center justify-center rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-900 dark:hover:text-white transition-all">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
        </div>
        <div class="p-6 space-y-6 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <span id="panel_status_badge" class="px-3 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest"></span>
                <span id="panel_unit_type" class="text-xs font-bold text-slate-500 dark:text-slate-400"></span>
                <span class="text-slate-300 dark:text-slate-600">·</span>
                <span id="panel_floor" class="text-xs font-bold text-slate-500 dark:text-slate-400"></span>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="p-4 bg-slate-50 dark:bg-slate-800/50 rounded-2xl">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Monthly Rent</p>
                    <p class="text-xl font-black text-slate-900 dark:text-white" id="panel_rent"></p>
                </div>
                <div class="p-4 bg-slate-50 dark:bg-slate-800/50 rounded-2xl">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Deposit</p>
                    <p class="text-xl font-black text-slate-900 dark:text-white" id="panel_deposit"></p>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="flex items-center gap-2 p-3 bg-blue-500/5 border border-blue-500/10 rounded-xl">
                    <span class="text-sm">⚡</span>
                    <div class="min-w-0">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Electricity</p>
                        <p class="text-xs font-black text-slate-700 dark:text-slate-300 truncate" id="panel_elec"></p>
                    </div>
                </div>
                <div class="flex items-center gap-2 p-3 bg-cyan-500/5 border border-cyan-500/10 rounded-xl">
                    <span class="text-sm">💧</span>
                    <div class="min-w-0">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Water</p>
                        <p class="text-xs font-black text-slate-700 dark:text-slate-300 truncate" id="panel_water"></p>
                    </div>
                </div>
            </div>
            <div id="panel_tenant_section" class="hidden space-y-4">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Current Tenant</p>
                <div class="p-5 rounded-2xl border border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/30 space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-green-500 to-emerald-600 flex items-center justify-center text-white font-black text-base shrink-0" id="panel_tenant_avatar"></div>
                        <div class="min-w-0">
                            <p class="font-black text-slate-900 dark:text-white truncate" id="panel_tenant_name"></p>
                            <p class="text-[10px] text-slate-400 font-medium truncate" id="panel_tenant_email"></p>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Phone</p>
                            <p class="text-xs font-bold text-slate-700 dark:text-slate-300" id="panel_tenant_phone"></p>
                        </div>
                        <div>
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Lease Start</p>
                            <p class="text-xs font-bold text-slate-700 dark:text-slate-300" id="panel_lease_start"></p>
                        </div>
                        <div>
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Lease End</p>
                            <p class="text-xs font-bold text-slate-700 dark:text-slate-300" id="panel_lease_end"></p>
                        </div>
                        <div>
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Outstanding</p>
                            <p class="text-xs font-black" id="panel_balance"></p>
                        </div>
                    </div>
                    <div class="flex gap-2 pt-1">
                        <a id="panel_view_tenant" href="#" class="flex-1 py-2.5 text-center text-[11px] font-black text-white bg-slate-900 dark:bg-slate-100 dark:text-slate-900 rounded-xl hover:opacity-80 transition-opacity">View Profile</a>
                        <a id="panel_view_invoices" href="#" class="flex-1 py-2.5 text-center text-[11px] font-black border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">Invoices</a>
                    </div>
                </div>
            </div>
            <div id="panel_vacant_section" class="hidden">
                <div class="p-6 border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-2xl text-center">
                    <p class="text-[10px] font-black text-orange-400 uppercase tracking-widest mb-1">Vacant Unit</p>
                    <p class="text-xs text-slate-500 font-medium">No active tenant assigned to this unit.</p>
                    <a href="tenants.php" class="inline-block mt-3 px-4 py-2 bg-accent-green text-slate-900 rounded-xl text-[10px] font-black uppercase tracking-widest hover:opacity-80 transition-opacity">Register Tenant</a>
                </div>
            </div>
        </div>
        <div class="px-6 py-4 border-t border-slate-100 dark:border-slate-800 flex gap-3 shrink-0">
            <button id="panel_edit_btn" class="flex-1 py-3 text-xs font-black border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">Edit Unit</button>
            <button id="panel_delete_btn" class="px-5 py-3 text-xs font-black bg-red-50 dark:bg-red-900/20 text-red-500 rounded-xl hover:bg-red-100 dark:hover:bg-red-900/30 transition-colors">Delete</button>
        </div>
    </div>
</div>

<!-- Hidden delete form -->
<form id="deleteUnitForm" action="actions/unit_actions.php" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="unit_id" id="deleteUnit_id">
    <input type="hidden" name="property_id" value="<?php echo htmlspecialchars($propertyId); ?>">
    <input type="hidden" name="_redirect" value="property_details.php?id=<?php echo htmlspecialchars($propertyId); ?>&tab=units&success=unit_deleted">
</form>

<style>
.unit-menu-wrap { position: relative; }
.unit-action-menu {
    position: absolute; right: 0; top: calc(100% + 4px);
    width: 160px; padding: 6px;
    background: #fff; border: 1px solid #e2e8f0;
    border-radius: 14px; box-shadow: 0 12px 40px rgba(0,0,0,.12);
    z-index: 200;
}
html.dark .unit-action-menu { background: #0f172a; border-color: #1e293b; box-shadow: 0 12px 40px rgba(0,0,0,.4); }
.unit-action-item {
    display: flex; align-items: center; gap: 8px;
    width: 100%; padding: 8px 12px;
    font-size: 12px; font-weight: 700; color: #475569;
    border-radius: 9px; background: none; border: none;
    cursor: pointer; text-align: left; transition: background .12s;
    text-decoration: none;
}
html.dark .unit-action-item { color: #94a3b8; }
.unit-action-item:hover { background: #f8fafc; color: #0f172a; }
html.dark .unit-action-item:hover { background: #1e293b; color: #f8fafc; }
</style>

<script>
/* ── Tab switching ───────────────────────────────────────────────── */
function switchTab(key) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
    document.querySelectorAll('.tab-btn').forEach(btn => {
        const active = btn.dataset.tab === key;
        btn.classList.toggle('bg-accent-green', active);
        btn.classList.toggle('text-slate-900', active);
        btn.classList.toggle('text-slate-500', !active);
        btn.classList.toggle('dark:text-slate-400', !active);
    });
    const el = document.getElementById('content-' + key);
    if (el) el.classList.remove('hidden');
    history.replaceState(null, '', '?id=<?php echo $propertyId; ?>&tab=' + key);
}

/* ── Income trend chart ──────────────────────────────────────────── */
const trendLabels = <?php echo json_encode($trendLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const trendValues = <?php echo json_encode($trendValues, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

document.addEventListener('DOMContentLoaded', () => {
    const ctx = document.getElementById('incomeTrendChart');
    if (!ctx || typeof Chart === 'undefined') return;
    const isDark = document.documentElement.classList.contains('dark');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: trendLabels,
            datasets: [{
                label: 'Income',
                data: trendValues,
                backgroundColor: 'rgba(16,185,129,.7)',
                borderRadius: 8,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { color: isDark ? '#64748b' : '#94a3b8', font: { weight: '700', size: 11 } } },
                y: { grid: { color: isDark ? '#1e293b' : '#f1f5f9' }, ticks: { color: isDark ? '#64748b' : '#94a3b8', font: { weight: '700', size: 11 }, callback: v => '<?php echo $currency; ?> ' + v.toLocaleString() } }
            }
        }
    });
});

/* ── Edit unit modal ─────────────────────────────────────────────── */
function openEditUnitModal(unit) {
    document.getElementById('edit_unit_id').value           = unit.id;
    document.getElementById('edit_unit_number').value       = unit.unit_number;
    document.getElementById('edit_floor_number').value      = unit.floor_number;
    document.getElementById('edit_unit_type').value         = unit.unit_type;
    document.getElementById('edit_category').value          = unit.category || '';
    document.getElementById('edit_electricity_meter').value = unit.electricity_meter || '';
    document.getElementById('edit_water_meter').value       = unit.water_meter || '';
    document.getElementById('edit_rent_amount').value       = unit.monthly_rent;
    document.getElementById('edit_deposit_amount').value    = unit.deposit_amount;
    document.getElementById('edit_status').value            = unit.status;
    openModal('editUnitModal');
}

/* ── Unit panel ──────────────────────────────────────────────────── */
const unitsData = <?php echo json_encode($unitPanelData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

function openUnitPanel(unitId) {
    const u = unitsData[unitId];
    if (!u) return;

    document.getElementById('panel_unit_title').textContent = 'Unit ' + u.unit_number;
    document.getElementById('panel_unit_type').textContent  = u.unit_type || '—';
    document.getElementById('panel_floor').textContent      = 'Floor ' + (u.floor_number || 'G');
    document.getElementById('panel_rent').textContent       = '<?php echo $currency; ?> ' + parseFloat(u.monthly_rent).toLocaleString();
    document.getElementById('panel_deposit').textContent    = '<?php echo $currency; ?> ' + parseFloat(u.deposit_amount).toLocaleString();
    document.getElementById('panel_elec').textContent       = u.electricity_meter || '—';
    document.getElementById('panel_water').textContent      = u.water_meter || '—';

    const sb = document.getElementById('panel_status_badge');
    const sc = {
        Occupied:    'bg-green-500/10 text-green-500 border border-green-500/20',
        Available:   'bg-orange-500/10 text-orange-500 border border-orange-500/20',
        Maintenance: 'bg-slate-200 dark:bg-slate-700 text-slate-500 border border-slate-300 dark:border-slate-600'
    };
    sb.className = 'px-3 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest ' + (sc[u.status] || '');
    sb.textContent = u.status;

    const ts = document.getElementById('panel_tenant_section');
    const vs = document.getElementById('panel_vacant_section');
    if (u.tenant_id) {
        ts.classList.remove('hidden'); vs.classList.add('hidden');
        document.getElementById('panel_tenant_avatar').textContent  = (u.tenant_name || '?')[0].toUpperCase();
        document.getElementById('panel_tenant_name').textContent    = u.tenant_name || '—';
        document.getElementById('panel_tenant_email').textContent   = u.tenant_email || '—';
        document.getElementById('panel_tenant_phone').textContent   = u.tenant_phone || '—';
        const fmtDate = d => d ? new Date(d).toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'}) : '—';
        document.getElementById('panel_lease_start').textContent = fmtDate(u.lease_start);
        document.getElementById('panel_lease_end').textContent   = u.lease_end ? fmtDate(u.lease_end) : 'Open-ended';
        const balEl = document.getElementById('panel_balance');
        balEl.textContent = '<?php echo $currency; ?> ' + parseFloat(u.outstanding_balance).toLocaleString();
        balEl.className   = 'text-xs font-black ' + (u.outstanding_balance > 0 ? 'text-red-500' : 'text-green-500');
        document.getElementById('panel_view_tenant').href   = 'tenant_details.php?id=' + u.tenant_id;
        document.getElementById('panel_view_invoices').href = 'tenant_details.php?id=' + u.tenant_id + '&tab=invoices';
    } else {
        ts.classList.add('hidden'); vs.classList.remove('hidden');
    }

    const editBtn   = document.getElementById('panel_edit_btn');
    const deleteBtn = document.getElementById('panel_delete_btn');
    if (editBtn)   editBtn.onclick   = () => { closeUnitPanel(); openEditUnitModal(u); };
    if (deleteBtn) deleteBtn.onclick = () => confirmDeleteUnit(u.id, u.unit_number);

    document.getElementById('unitPanel').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeUnitPanel() {
    document.getElementById('unitPanel').style.display = 'none';
    document.body.style.overflow = '';
}

/* ── 3-dot unit menu ─────────────────────────────────────────────── */
function toggleUnitMenu(btn) {
    const menu   = btn.nextElementSibling;
    const isOpen = !menu.classList.contains('hidden');
    document.querySelectorAll('.unit-action-menu').forEach(m => m.classList.add('hidden'));
    if (!isOpen) menu.classList.remove('hidden');
}
document.addEventListener('click', e => {
    if (!e.target.closest('.unit-menu-wrap'))
        document.querySelectorAll('.unit-action-menu').forEach(m => m.classList.add('hidden'));
});

/* ── Delete unit ─────────────────────────────────────────────────── */
function confirmDeleteUnit(id, number) {
    if (!confirm('Delete Unit ' + number + '?\n\nThis cannot be undone. Units with active tenants cannot be deleted.')) return;
    document.getElementById('deleteUnit_id').value = id;
    document.getElementById('deleteUnitForm').submit();
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeUnitPanel(); });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
