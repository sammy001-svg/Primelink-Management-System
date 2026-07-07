<?php
/**
 * Landlord Profile — Admin / Staff comprehensive view
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/audit.php';

$landlordId = trim($_GET['id'] ?? '');
if (!$landlordId) { header('Location: landlords.php'); exit(); }

$currency = getSetting($pdo, 'currency_symbol', 'KSh');
$tab      = $_GET['tab'] ?? 'overview';

// ── Schema self-heal ─────────────────────────────────────────────────
foreach ([
    "ALTER TABLE landlords        ADD COLUMN IF NOT EXISTS id_number        VARCHAR(100) NULL",
    "ALTER TABLE landlords        ADD COLUMN IF NOT EXISTS address          TEXT NULL",
    "ALTER TABLE landlords        ADD COLUMN IF NOT EXISTS fee_type         VARCHAR(20) NOT NULL DEFAULT 'percentage'",
    "ALTER TABLE landlords        ADD COLUMN IF NOT EXISTS nok_name         VARCHAR(255) NULL",
    "ALTER TABLE landlords        ADD COLUMN IF NOT EXISTS nok_phone        VARCHAR(50)  NULL",
    "ALTER TABLE landlords        ADD COLUMN IF NOT EXISTS nok_relationship VARCHAR(100) NULL",
    "ALTER TABLE landlords        ADD COLUMN IF NOT EXISTS status           VARCHAR(20) NOT NULL DEFAULT 'active'",
    "ALTER TABLE landlord_loans   ADD COLUMN IF NOT EXISTS principal_amount DECIMAL(15,2) DEFAULT 0",
    "ALTER TABLE landlord_loans   ADD COLUMN IF NOT EXISTS commission_rate  DECIMAL(5,2)  DEFAULT 0",
    "ALTER TABLE landlord_loans   ADD COLUMN IF NOT EXISTS commission_amount DECIMAL(15,2) DEFAULT 0",
    "ALTER TABLE landlord_loans   ADD COLUMN IF NOT EXISTS due_date         DATE NULL",
    "ALTER TABLE landlord_loans   ADD COLUMN IF NOT EXISTS lender_name      VARCHAR(255) NULL",
    "ALTER TABLE landlord_loans   ADD COLUMN IF NOT EXISTS description      TEXT NULL",
    "ALTER TABLE maintenance_requests ADD COLUMN IF NOT EXISTS landlord_decision VARCHAR(20) NULL",
    "ALTER TABLE maintenance_requests ADD COLUMN IF NOT EXISTS landlord_note     TEXT NULL",
] as $ddl) {
    try { $pdo->exec($ddl); } catch (PDOException $e) {}
}

// ── Documents table self-create ──────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS landlord_documents (
        id VARCHAR(64) PRIMARY KEY,
        landlord_id VARCHAR(64) NOT NULL,
        title VARCHAR(255) NOT NULL,
        category VARCHAR(100) DEFAULT 'General',
        file_url VARCHAR(500) NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_size INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        uploaded_by VARCHAR(64) NULL,
        INDEX idx_ll_docs_landlord (landlord_id)
    )");
} catch (PDOException $e) {}

// ── Landlord record ──────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM landlords WHERE id = ?");
$stmt->execute([$landlordId]);
$ll = $stmt->fetch();
if (!$ll) { header('Location: landlords.php?error=' . urlencode('Landlord not found.')); exit(); }

// ── Assigned properties with aggregates ─────────────────────────────
$stmt = $pdo->prepare("
    SELECT p.*,
           COUNT(DISTINCT u.id) AS unit_count,
           COALESCE(SUM(CASE WHEN u.status='Occupied' THEN 1 ELSE 0 END), 0) AS occupied_count,
           COALESCE(SUM(CASE WHEN u.status='Occupied' THEN u.monthly_rent ELSE 0 END), 0) AS rent_roll,
           COALESCE((
               SELECT SUM(i2.amount)
               FROM invoices i2
               JOIN leases l2 ON i2.lease_id = l2.id
               JOIN units u2 ON l2.unit_id = u2.id
               WHERE u2.property_id = p.id
               AND i2.status = 'Paid'
               AND MONTH(i2.created_at) = MONTH(NOW()) AND YEAR(i2.created_at) = YEAR(NOW())
           ), 0) AS income_mtd
    FROM properties p
    LEFT JOIN units u ON u.property_id = p.id
    WHERE p.landlord_id = ?
    GROUP BY p.id ORDER BY p.title
");
$stmt->execute([$landlordId]);
$properties = $stmt->fetchAll();

$totalPropCount  = count($properties);
$totalUnits      = (int)array_sum(array_column($properties, 'unit_count'));
$occupiedUnits   = (int)array_sum(array_column($properties, 'occupied_count'));
$monthlyRentRoll = (float)array_sum(array_column($properties, 'rent_roll'));

// ── Active tenants ────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT t.id, t.full_name, t.email, t.phone, t.status,
           u.unit_number, p.title AS property_title, p.id AS property_id,
           l.id AS lease_id, l.monthly_rent, l.start_date, l.end_date,
           COALESCE((
               SELECT SUM(i.amount) FROM invoices i
               WHERE i.tenant_id = t.id AND i.status NOT IN ('Paid','Cancelled')
           ), 0) AS outstanding
    FROM tenants t
    JOIN leases l ON l.tenant_id = t.id AND l.status = 'Active'
    JOIN units u ON l.unit_id = u.id
    JOIN properties p ON u.property_id = p.id
    WHERE p.landlord_id = ?
    ORDER BY t.full_name
");
$stmt->execute([$landlordId]);
$tenants = $stmt->fetchAll();
$activeTenantCount = count($tenants);

// ── Income aggregates ─────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN MONTH(i.created_at)=MONTH(NOW()) AND YEAR(i.created_at)=YEAR(NOW()) THEN i.amount ELSE 0 END) AS mtd,
        SUM(CASE WHEN YEAR(i.created_at)=YEAR(NOW()) THEN i.amount ELSE 0 END) AS ytd,
        SUM(i.amount) AS all_time
    FROM invoices i
    JOIN leases l ON i.lease_id = l.id
    JOIN units u ON l.unit_id = u.id
    JOIN properties p ON u.property_id = p.id
    WHERE p.landlord_id = ? AND i.status = 'Paid'
");
$stmt->execute([$landlordId]);
$inc = $stmt->fetch();
$incomeMTD   = (float)($inc['mtd']      ?? 0);
$incomeYTD   = (float)($inc['ytd']      ?? 0);
$incomeTotal = (float)($inc['all_time'] ?? 0);

// Outstanding across all properties
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(i.amount), 0)
    FROM invoices i
    JOIN leases l ON i.lease_id = l.id
    JOIN units u ON l.unit_id = u.id
    JOIN properties p ON u.property_id = p.id
    WHERE p.landlord_id = ? AND i.status NOT IN ('Paid','Cancelled')
");
$stmt->execute([$landlordId]);
$outstanding = (float)$stmt->fetchColumn();

// Management fee for this month
$feeType = $ll['fee_type'] ?? 'percentage';
$feeSetting = (float)($ll['management_fee'] ?? 10);
$mgmtFeeMTD = $feeType === 'percentage' ? ($incomeMTD * $feeSetting / 100) : $feeSetting;
$netMTD = max(0, $incomeMTD - $mgmtFeeMTD);

// ── 6-month income trend ──────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT DATE_FORMAT(i.created_at,'%Y-%m') AS ym, SUM(i.amount) AS total
    FROM invoices i
    JOIN leases l ON i.lease_id = l.id
    JOIN units u ON l.unit_id = u.id
    JOIN properties p ON u.property_id = p.id
    WHERE p.landlord_id = ? AND i.status = 'Paid'
    AND i.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY ym ORDER BY ym
");
$stmt->execute([$landlordId]);
$trendRaw = [];
foreach ($stmt->fetchAll() as $r) $trendRaw[$r['ym']] = (float)$r['total'];
$trendLabels = [];
$trendValues = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $trendLabels[] = date('M Y', strtotime("-$i months"));
    $trendValues[] = $trendRaw[$ym] ?? 0;
}

// ── Statement (recent 100 invoices) ──────────────────────────────────
$stmt = $pdo->prepare("
    SELECT i.id, i.created_at, i.due_date, i.amount, i.status, i.invoice_type,
           t.full_name AS tenant_name, u.unit_number, p.title AS property_title
    FROM invoices i
    JOIN leases l ON i.lease_id = l.id
    JOIN units u ON l.unit_id = u.id
    JOIN properties p ON u.property_id = p.id
    JOIN tenants t ON i.tenant_id = t.id
    WHERE p.landlord_id = ?
    ORDER BY i.created_at DESC LIMIT 150
");
$stmt->execute([$landlordId]);
$statements = $stmt->fetchAll();

// ── Maintenance requests ──────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT m.*, u.unit_number, p.title AS property_title, t.full_name AS tenant_name
    FROM maintenance_requests m
    LEFT JOIN units u ON m.unit_id = u.id
    LEFT JOIN properties p ON u.property_id = p.id
    LEFT JOIN tenants t ON m.tenant_id = t.id
    WHERE p.landlord_id = ?
    ORDER BY FIELD(m.status,'Pending','In Progress','Completed'), m.created_at DESC
");
$stmt->execute([$landlordId]);
$maintenance = $stmt->fetchAll();
$mntPending = count(array_filter($maintenance, fn($m) => $m['status'] === 'Pending'));
$mntInProg  = count(array_filter($maintenance, fn($m) => $m['status'] === 'In Progress'));
$mntDone    = count(array_filter($maintenance, fn($m) => $m['status'] === 'Completed'));

// ── Loans ─────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM landlord_loans WHERE landlord_id = ? ORDER BY created_at DESC");
$stmt->execute([$landlordId]);
$loans = $stmt->fetchAll();
$activeLoansAmt  = (float)array_sum(array_column(array_filter($loans, fn($l) => $l['status'] === 'Active'), 'total_repayable'));
$totalCommission = (float)array_sum(array_column($loans, 'commission_amount'));

// ── Advances ──────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM landlord_advances WHERE landlord_id = ? ORDER BY requested_at DESC");
$stmt->execute([$landlordId]);
$advances = $stmt->fetchAll();

// ── Statement period filter ───────────────────────────────────────────
$stmtPeriod = $_GET['stmt_period'] ?? 'all';
$stmtFiltered = match($stmtPeriod) {
    'month'   => array_values(array_filter($statements, fn($s) => date('Y-m', strtotime($s['created_at'])) === date('Y-m'))),
    '3months' => array_values(array_filter($statements, fn($s) => strtotime($s['created_at']) >= strtotime('-3 months'))),
    'ytd'     => array_values(array_filter($statements, fn($s) => date('Y', strtotime($s['created_at'])) === date('Y'))),
    default   => $statements,
};

// ── Documents ─────────────────────────────────────────────────────────
$documents = [];
try {
    $docStmt = $pdo->prepare("SELECT * FROM landlord_documents WHERE landlord_id = ? ORDER BY created_at DESC");
    $docStmt->execute([$landlordId]);
    $documents = $docStmt->fetchAll();
} catch (PDOException $e) {}

$llInitial = strtoupper(substr($ll['full_name'], 0, 1));
$pageTitle = htmlspecialchars($ll['full_name']) . ' — Landlord Profile';
$tabs = ['overview','properties','tenants','statement','maintenance','loans','profile','documents'];

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-6 animate-in">

    <!-- Back + toast -->
    <div class="flex items-center justify-between">
        <a href="landlords.php" class="inline-flex items-center gap-2 text-sm font-bold text-slate-500 hover:text-slate-900 dark:hover:text-white transition-colors">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5"/><path d="m12 5-7 7 7 7"/></svg>
            Back to Landlords
        </a>
        <button onclick="openModal('editLandlordInlineModal')"
                class="px-4 py-2 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-xl text-sm font-black text-slate-700 dark:text-slate-300 transition-all">
            Edit Profile
        </button>
    </div>

    <?php if (isset($_GET['success'])): ?>
    <div id="profToast" class="fixed bottom-6 right-6 z-50 flex items-center gap-3 px-5 py-3.5 bg-emerald-500 text-white text-sm font-bold rounded-2xl shadow-2xl">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <?php echo htmlspecialchars($_GET['success']); ?>
    </div>
    <script>setTimeout(()=>{ const t=document.getElementById('profToast'); if(t) t.remove(); }, 4000);</script>
    <?php elseif (isset($_GET['error'])): ?>
    <div id="profToast" class="fixed bottom-6 right-6 z-50 flex items-center gap-3 px-5 py-3.5 bg-red-500 text-white text-sm font-bold rounded-2xl shadow-2xl">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
    <script>setTimeout(()=>{ const t=document.getElementById('profToast'); if(t) t.remove(); }, 6000);</script>
    <?php endif; ?>

    <!-- ── HERO ─────────────────────────────────────────────────── -->
    <div class="rounded-2xl overflow-hidden bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 shadow-xl">
        <div class="p-6 sm:p-8">
            <div class="flex flex-col sm:flex-row items-start sm:items-center gap-5">
                <!-- Avatar -->
                <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-3xl font-black text-white shrink-0 shadow-lg ring-4 ring-emerald-500/20">
                    <?php echo $llInitial; ?>
                </div>
                <!-- Info -->
                <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-3 mb-1">
                        <h1 class="text-2xl font-black text-white"><?php echo htmlspecialchars($ll['full_name']); ?></h1>
                        <span class="px-2.5 py-1 rounded-xl text-[10px] font-black uppercase tracking-widest
                            <?php echo ($ll['status'] ?? 'active') === 'active'
                                ? 'bg-emerald-500/20 text-emerald-400 ring-1 ring-emerald-500/30'
                                : 'bg-slate-700 text-slate-400'; ?>">
                            <?php echo ucfirst($ll['status'] ?? 'active'); ?>
                        </span>
                    </div>
                    <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-3">LLD-<?php echo strtoupper(substr($ll['id'], 0, 8)); ?></p>
                    <div class="flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-400 font-medium">
                        <span class="flex items-center gap-1.5">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                            <?php echo htmlspecialchars($ll['email']); ?>
                        </span>
                        <?php if ($ll['phone']): ?>
                        <span class="flex items-center gap-1.5">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.93 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.88 1h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                            <?php echo htmlspecialchars($ll['phone']); ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($ll['address'] ?? ''): ?>
                        <span class="flex items-center gap-1.5">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                            <?php echo htmlspecialchars($ll['address']); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Fee badge -->
                <div class="text-right shrink-0">
                    <?php
                    $feeDisplay = $feeType === 'fixed'
                        ? $currency . ' ' . number_format($feeSetting, 2)
                        : number_format($feeSetting, 1) . '%';
                    ?>
                    <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Mgmt Fee</p>
                    <p class="text-2xl font-black <?php echo $feeType === 'fixed' ? 'text-blue-400' : 'text-orange-400'; ?>"><?php echo $feeDisplay; ?></p>
                    <p class="text-[10px] text-slate-500"><?php echo $feeType === 'fixed' ? 'fixed/payout' : 'of gross rent'; ?></p>
                </div>
            </div>
        </div>
        <!-- Hero stats strip -->
        <div class="border-t border-slate-700/50 grid grid-cols-2 sm:grid-cols-4 divide-x divide-slate-700/50">
            <div class="px-5 py-3.5 text-center">
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Properties</p>
                <p class="text-xl font-black text-white"><?php echo $totalPropCount; ?></p>
                <p class="text-[10px] text-slate-500"><?php echo $totalUnits; ?> units</p>
            </div>
            <div class="px-5 py-3.5 text-center">
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Monthly Rent</p>
                <p class="text-xl font-black text-emerald-400"><?php echo $currency; ?> <?php echo number_format($monthlyRentRoll); ?></p>
                <p class="text-[10px] text-slate-500">rent roll</p>
            </div>
            <div class="px-5 py-3.5 text-center">
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Active Tenants</p>
                <p class="text-xl font-black text-white"><?php echo $activeTenantCount; ?></p>
                <p class="text-[10px] text-slate-500"><?php echo $occupiedUnits; ?>/<?php echo $totalUnits; ?> occupied</p>
            </div>
            <div class="px-5 py-3.5 text-center">
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Outstanding</p>
                <p class="text-xl font-black <?php echo $outstanding > 0 ? 'text-red-400' : 'text-emerald-400'; ?>">
                    <?php echo $outstanding > 0 ? $currency . ' ' . number_format($outstanding) : 'Clear'; ?>
                </p>
                <p class="text-[10px] text-slate-500">unpaid</p>
            </div>
        </div>
    </div>

    <!-- ── KPI CARDS ─────────────────────────────────────────────── -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="glass-card p-4 border-l-[3px] border-blue-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Properties</p>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white mt-0.5"><?php echo $totalPropCount; ?></h3>
            <p class="text-[10px] text-blue-500 font-bold"><?php echo $totalUnits; ?> units total</p>
        </div>
        <div class="glass-card p-4 border-l-[3px] border-violet-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Active Tenants</p>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white mt-0.5"><?php echo $activeTenantCount; ?></h3>
            <p class="text-[10px] text-violet-500 font-bold"><?php echo $occupiedUnits; ?> / <?php echo $totalUnits; ?> occupied</p>
        </div>
        <div class="glass-card p-4 border-l-[3px] border-emerald-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">This Month</p>
            <h3 class="text-xl font-black text-emerald-500 mt-0.5"><?php echo $currency; ?> <?php echo number_format($incomeMTD); ?></h3>
            <p class="text-[10px] text-slate-400 font-bold">Net: <?php echo $currency; ?> <?php echo number_format($netMTD); ?></p>
        </div>
        <div class="glass-card p-4 border-l-[3px] border-orange-400">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">YTD Income</p>
            <h3 class="text-xl font-black text-slate-900 dark:text-white mt-0.5"><?php echo $currency; ?> <?php echo number_format($incomeYTD); ?></h3>
            <p class="text-[10px] text-orange-400 font-bold"><?php echo date('Y'); ?></p>
        </div>
        <div class="glass-card p-4 border-l-[3px] border-red-400">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Outstanding</p>
            <h3 class="text-xl font-black <?php echo $outstanding > 0 ? 'text-red-500' : 'text-emerald-500'; ?> mt-0.5">
                <?php echo $outstanding > 0 ? $currency . ' ' . number_format($outstanding) : 'Clear'; ?>
            </h3>
            <p class="text-[10px] text-slate-400 font-bold">unpaid invoices</p>
        </div>
    </div>

    <!-- ── TAB NAV ───────────────────────────────────────────────── -->
    <div class="flex gap-1 overflow-x-auto pb-1 border-b border-slate-100 dark:border-slate-800 no-scrollbar">
        <?php
        $tabDefs = [
            'overview'    => ['Overview',    'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
            'properties'  => ['Properties',  'M3 21h18M5 21V7l8-4v18M19 21V11l-6-4'],
            'tenants'     => ['Tenants',      'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2'],
            'statement'   => ['Statement',    'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z'],
            'maintenance' => ['Maintenance',  'M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7', $mntPending > 0 ? $mntPending : null],
            'loans'       => ['Loans',        'M12 1v22M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6'],
            'profile'     => ['Profile',      'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2'],
            'documents'   => ['Documents',    'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z|M14 2v6h6|M16 13H8|M16 17H8|M10 9H8', count($documents) > 0 ? count($documents) : null],
        ];
        foreach ($tabDefs as $key => $tabDef) {
            [$label, $path] = $tabDef;
            $badge = $tabDef[2] ?? null;
            $active = ($tab === $key);
            echo '<button onclick="switchTab(\'' . $key . '\')" id="tab_btn_' . $key . '"
                class="flex items-center gap-2 px-4 py-2.5 rounded-t-xl text-xs font-black uppercase tracking-wider whitespace-nowrap transition-all ' .
                ($active ? 'bg-white dark:bg-slate-800 text-slate-900 dark:text-white shadow-sm border border-slate-100 dark:border-slate-700 border-b-white dark:border-b-slate-800 -mb-px'
                         : 'text-slate-400 hover:text-slate-700 dark:hover:text-slate-200') . '">';
            echo '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">';
            foreach (explode('|', $path) as $p) echo "<path d=\"$p\"/>";
            echo '</svg>' . $label;
            if ($badge ?? null) {
                echo '<span class="px-1.5 py-0.5 bg-orange-500 text-white rounded-full text-[9px] font-black">' . $badge . '</span>';
            }
            echo '</button>';
        }
        ?>
    </div>

    <!-- ════════════════════════════════════════════════════════════ -->
    <!-- OVERVIEW TAB                                                -->
    <!-- ════════════════════════════════════════════════════════════ -->
    <div id="tab_overview" class="space-y-6 <?php echo $tab !== 'overview' ? 'hidden' : ''; ?>">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- Income chart -->
            <div class="lg:col-span-2 glass-card p-6">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">6-Month Income (Collected)</p>
                <canvas id="incomeChart" height="160"></canvas>
            </div>

            <!-- Top properties -->
            <div class="glass-card p-5 space-y-3">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Properties — Rent Roll</p>
                <?php if (empty($properties)): ?>
                <p class="text-sm text-slate-400 italic">No properties assigned.</p>
                <?php else: ?>
                <?php foreach ($properties as $prop): ?>
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center shrink-0">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-violet-400"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-black text-slate-800 dark:text-white truncate"><?php echo htmlspecialchars($prop['title']); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo $prop['occupied_count']; ?>/<?php echo $prop['unit_count']; ?> occupied</p>
                    </div>
                    <p class="text-xs font-black text-emerald-500 shrink-0"><?php echo $currency; ?> <?php echo number_format((float)$prop['rent_roll']); ?></p>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent maintenance + recent invoices -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="glass-card overflow-hidden">
                <div class="p-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Recent Maintenance</p>
                    <button onclick="switchTab('maintenance')" class="text-[10px] text-accent-green font-black hover:underline">View All →</button>
                </div>
                <?php $recentMnt = array_slice($maintenance, 0, 5); ?>
                <?php if (empty($recentMnt)): ?>
                <p class="p-6 text-sm text-slate-400 italic">No maintenance requests.</p>
                <?php else: ?>
                <div class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($recentMnt as $m): ?>
                    <div class="px-4 py-3 flex items-center gap-3">
                        <span class="w-2 h-2 rounded-full shrink-0
                            <?php echo match($m['status']) { 'Completed' => 'bg-emerald-400', 'In Progress' => 'bg-blue-400', default => 'bg-orange-400' }; ?>">
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-bold text-slate-800 dark:text-white truncate"><?php echo htmlspecialchars($m['title'] ?? 'Request'); ?></p>
                            <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($m['unit_number'] ?? ''); ?> · <?php echo htmlspecialchars($m['property_title'] ?? ''); ?></p>
                        </div>
                        <span class="text-[9px] font-black uppercase px-2 py-0.5 rounded-lg
                            <?php echo match($m['priority'] ?? 'Normal') { 'Urgent','Critical' => 'bg-red-100 text-red-600', 'High' => 'bg-orange-100 text-orange-600', default => 'bg-slate-100 text-slate-500' }; ?>">
                            <?php echo $m['priority'] ?? 'Normal'; ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="glass-card overflow-hidden">
                <div class="p-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Recent Invoices</p>
                    <button onclick="switchTab('statement')" class="text-[10px] text-accent-green font-black hover:underline">View All →</button>
                </div>
                <?php $recentInv = array_slice($statements, 0, 5); ?>
                <?php if (empty($recentInv)): ?>
                <p class="p-6 text-sm text-slate-400 italic">No invoices yet.</p>
                <?php else: ?>
                <div class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($recentInv as $inv): ?>
                    <div class="px-4 py-3 flex items-center gap-3">
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-bold text-slate-800 dark:text-white"><?php echo htmlspecialchars($inv['invoice_type']); ?> — <?php echo htmlspecialchars($inv['tenant_name']); ?></p>
                            <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($inv['unit_number']); ?> · <?php echo date('d M Y', strtotime($inv['created_at'])); ?></p>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="text-xs font-black text-slate-800 dark:text-white"><?php echo $currency; ?> <?php echo number_format((float)$inv['amount']); ?></p>
                            <span class="text-[9px] font-black px-1.5 py-0.5 rounded
                                <?php echo match($inv['status']) { 'Paid' => 'text-emerald-600 bg-emerald-50', 'Overdue' => 'text-red-600 bg-red-50', default => 'text-orange-600 bg-orange-50' }; ?>">
                                <?php echo $inv['status']; ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════ -->
    <!-- PROPERTIES TAB                                              -->
    <!-- ════════════════════════════════════════════════════════════ -->
    <div id="tab_properties" class="<?php echo $tab !== 'properties' ? 'hidden' : ''; ?>">
        <div class="glass-card overflow-hidden">
            <div class="p-5 border-b border-slate-100 dark:border-slate-800">
                <h3 class="font-black text-slate-900 dark:text-white">Assigned Properties</h3>
            </div>
            <?php if (empty($properties)): ?>
            <div class="p-12 text-center">
                <p class="text-slate-400 italic">No properties assigned to this landlord yet.</p>
                <a href="landlords.php" class="mt-3 inline-block text-accent-green font-black text-sm hover:underline">Assign from Landlords page →</a>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse min-w-[700px]">
                <thead>
                    <tr class="bg-slate-50 dark:bg-slate-800/50">
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Property</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Type</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Units</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Occupancy</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Rent Roll / mo</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">This Month</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($properties as $prop):
                        $occ = $prop['unit_count'] > 0 ? round($prop['occupied_count'] / $prop['unit_count'] * 100) : 0;
                    ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                        <td class="px-5 py-4">
                            <p class="font-black text-sm text-slate-900 dark:text-white"><?php echo htmlspecialchars($prop['title']); ?></p>
                            <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($prop['location'] ?? ''); ?></p>
                        </td>
                        <td class="px-5 py-4 text-xs font-bold text-slate-500 uppercase tracking-wide"><?php echo htmlspecialchars($prop['property_type'] ?? '—'); ?></td>
                        <td class="px-5 py-4">
                            <span class="font-black text-slate-800 dark:text-white"><?php echo $prop['occupied_count']; ?></span>
                            <span class="text-slate-400"> / <?php echo $prop['unit_count']; ?></span>
                            <p class="text-[10px] text-slate-400">occupied</p>
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex items-center gap-2">
                                <div class="w-16 h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full <?php echo $occ >= 80 ? 'bg-emerald-500' : ($occ >= 50 ? 'bg-orange-400' : 'bg-red-400'); ?>"
                                         style="width:<?php echo $occ; ?>%"></div>
                                </div>
                                <span class="text-xs font-black"><?php echo $occ; ?>%</span>
                            </div>
                        </td>
                        <td class="px-5 py-4 font-black text-sm text-emerald-500"><?php echo $currency; ?> <?php echo number_format((float)$prop['rent_roll']); ?></td>
                        <td class="px-5 py-4 font-black text-sm text-slate-700 dark:text-slate-300"><?php echo $currency; ?> <?php echo number_format((float)$prop['income_mtd']); ?></td>
                        <td class="px-5 py-4 text-right">
                            <a href="property_details.php?id=<?php echo $prop['id']; ?>"
                               class="text-[10px] font-black uppercase tracking-widest text-blue-500 hover:text-blue-700 transition-colors">
                                View →
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="bg-slate-50 dark:bg-slate-800/50 border-t-2 border-slate-200 dark:border-slate-700">
                        <td colspan="4" class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Totals</td>
                        <td class="px-5 py-3 font-black text-sm text-emerald-600"><?php echo $currency; ?> <?php echo number_format($monthlyRentRoll); ?></td>
                        <td class="px-5 py-3 font-black text-sm text-slate-700 dark:text-slate-300"><?php echo $currency; ?> <?php echo number_format($incomeMTD); ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════ -->
    <!-- TENANTS TAB                                                 -->
    <!-- ════════════════════════════════════════════════════════════ -->
    <div id="tab_tenants" class="<?php echo $tab !== 'tenants' ? 'hidden' : ''; ?>">
        <div class="glass-card overflow-hidden">
            <div class="p-5 border-b border-slate-100 dark:border-slate-800">
                <h3 class="font-black text-slate-900 dark:text-white">Active Tenants (<?php echo $activeTenantCount; ?>)</h3>
            </div>
            <?php if (empty($tenants)): ?>
            <p class="p-12 text-center text-slate-400 italic">No active tenants across these properties.</p>
            <?php else: ?>
            <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse min-w-[750px]">
                <thead>
                    <tr class="bg-slate-50 dark:bg-slate-800/50">
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tenant</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Unit / Property</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Monthly Rent</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Lease End</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Outstanding</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($tenants as $t): ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                        <td class="px-5 py-4">
                            <p class="font-black text-sm text-slate-900 dark:text-white"><?php echo htmlspecialchars($t['full_name']); ?></p>
                            <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($t['phone'] ?: $t['email']); ?></p>
                        </td>
                        <td class="px-5 py-4">
                            <p class="text-sm font-bold text-slate-700 dark:text-slate-300">Unit <?php echo htmlspecialchars($t['unit_number']); ?></p>
                            <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($t['property_title']); ?></p>
                        </td>
                        <td class="px-5 py-4 font-black text-sm text-emerald-500"><?php echo $currency; ?> <?php echo number_format((float)$t['monthly_rent']); ?></td>
                        <td class="px-5 py-4 text-xs text-slate-500">
                            <?php echo $t['end_date'] ? date('d M Y', strtotime($t['end_date'])) : '—'; ?>
                            <?php if ($t['end_date'] && strtotime($t['end_date']) <= strtotime('+60 days')): ?>
                            <span class="ml-1 text-[9px] font-black text-orange-500 uppercase">Expiring</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-4">
                            <?php if ($t['outstanding'] > 0): ?>
                            <span class="font-black text-sm text-red-500"><?php echo $currency; ?> <?php echo number_format((float)$t['outstanding']); ?></span>
                            <?php else: ?>
                            <span class="text-[10px] font-black text-emerald-500 bg-emerald-50 dark:bg-emerald-900/20 px-2 py-0.5 rounded-lg">Clear</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-4 text-right">
                            <a href="tenant_details.php?id=<?php echo $t['id']; ?>"
                               class="text-[10px] font-black uppercase tracking-widest text-blue-500 hover:text-blue-700 transition-colors">Profile →</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════ -->
    <!-- STATEMENT TAB                                               -->
    <!-- ════════════════════════════════════════════════════════════ -->
    <div id="tab_statement" class="space-y-5 <?php echo $tab !== 'statement' ? 'hidden' : ''; ?>">

        <!-- Period filter -->
        <div class="flex gap-2 flex-wrap">
            <?php foreach ([
                ['all',     'All Time'],
                ['month',   'This Month'],
                ['3months', 'Last 3 Months'],
                ['ytd',     'YTD ' . date('Y')],
            ] as [$pk, $plbl]): ?>
            <a href="?id=<?php echo urlencode($landlordId); ?>&tab=statement&stmt_period=<?php echo $pk; ?>"
               class="px-3 py-1.5 rounded-lg text-xs font-black transition-all <?php echo $stmtPeriod === $pk ? 'bg-slate-900 dark:bg-white text-white dark:text-slate-900' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 hover:text-slate-900 dark:hover:text-white'; ?>">
                <?php echo $plbl; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Summary bar -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <?php
            $stmtPaid     = array_sum(array_column(array_filter($stmtFiltered, fn($s) => $s['status'] === 'Paid'),    'amount'));
            $stmtUnpaid   = array_sum(array_column(array_filter($stmtFiltered, fn($s) => $s['status'] !== 'Paid' && $s['status'] !== 'Cancelled'), 'amount'));
            $stmtMgmtFee  = $feeType === 'percentage' ? ($stmtPaid * $feeSetting / 100) : ($feeSetting * 12);
            $stmtNet      = max(0, $stmtPaid - $stmtMgmtFee);
            ?>
            <div class="glass-card p-4 border-l-[3px] border-emerald-500">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Collected</p>
                <p class="text-xl font-black text-emerald-500 mt-1"><?php echo $currency; ?> <?php echo number_format($stmtPaid); ?></p>
            </div>
            <div class="glass-card p-4 border-l-[3px] border-orange-400">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Management Fee</p>
                <p class="text-xl font-black text-orange-500 mt-1"><?php echo $currency; ?> <?php echo number_format($stmtMgmtFee); ?></p>
                <p class="text-[10px] text-slate-400"><?php echo $feeDisplay; ?></p>
            </div>
            <div class="glass-card p-4 border-l-[3px] border-blue-500">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Net to Landlord</p>
                <p class="text-xl font-black text-blue-600 mt-1"><?php echo $currency; ?> <?php echo number_format($stmtNet); ?></p>
            </div>
            <div class="glass-card p-4 border-l-[3px] border-red-400">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Pending / Overdue</p>
                <p class="text-xl font-black text-red-500 mt-1"><?php echo $currency; ?> <?php echo number_format($stmtUnpaid); ?></p>
            </div>
        </div>

        <div class="glass-card overflow-hidden">
            <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <h3 class="font-black text-slate-900 dark:text-white">Transaction History</h3>
                <span class="text-[10px] text-slate-400 font-bold"><?php echo count($stmtFiltered); ?> entries<?php echo $stmtPeriod !== 'all' ? ' · filtered' : ''; ?></span>
            </div>
            <?php if (empty($stmtFiltered)): ?>
            <p class="p-12 text-center text-slate-400 italic">No transactions in this period.</p>
            <?php else: ?>
            <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse min-w-[700px]">
                <thead>
                    <tr class="bg-slate-50 dark:bg-slate-800/50">
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Type</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tenant</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Unit / Property</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($stmtFiltered as $s): ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20">
                        <td class="px-5 py-3 text-xs text-slate-500 font-medium whitespace-nowrap"><?php echo date('d M Y', strtotime($s['created_at'])); ?></td>
                        <td class="px-5 py-3">
                            <span class="text-[10px] font-black px-2 py-0.5 bg-slate-100 dark:bg-slate-800 rounded-lg text-slate-600 dark:text-slate-400">
                                <?php echo htmlspecialchars($s['invoice_type']); ?>
                            </span>
                        </td>
                        <td class="px-5 py-3 text-sm font-bold text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($s['tenant_name']); ?></td>
                        <td class="px-5 py-3">
                            <p class="text-xs text-slate-600 dark:text-slate-300 font-bold">Unit <?php echo htmlspecialchars($s['unit_number']); ?></p>
                            <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($s['property_title']); ?></p>
                        </td>
                        <td class="px-5 py-3 font-black text-sm text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format((float)$s['amount']); ?></td>
                        <td class="px-5 py-3">
                            <span class="text-[9px] font-black uppercase px-2 py-0.5 rounded-lg
                                <?php echo match($s['status']) { 'Paid' => 'bg-emerald-100 text-emerald-700', 'Overdue' => 'bg-red-100 text-red-700', 'Cancelled' => 'bg-slate-100 text-slate-500', default => 'bg-orange-100 text-orange-700' }; ?>">
                                <?php echo $s['status']; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════ -->
    <!-- MAINTENANCE TAB                                             -->
    <!-- ════════════════════════════════════════════════════════════ -->
    <div id="tab_maintenance" class="space-y-5 <?php echo $tab !== 'maintenance' ? 'hidden' : ''; ?>">

        <!-- Status filter tabs -->
        <div class="flex gap-2">
            <?php foreach ([
                ['all',         'All',         count($maintenance), ''],
                ['Pending',     'Pending',      $mntPending,         'text-orange-500'],
                ['In Progress', 'In Progress',  $mntInProg,          'text-blue-500'],
                ['Completed',   'Completed',    $mntDone,            'text-emerald-500'],
            ] as [$val, $lbl, $cnt, $cls]): ?>
            <button onclick="filterMaint('<?php echo htmlspecialchars($val, ENT_QUOTES); ?>')"
                    class="maint-filter-btn px-3 py-1.5 rounded-lg text-xs font-black transition-all <?php echo $val === 'all' ? 'bg-slate-900 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-500'; ?>"
                    data-val="<?php echo htmlspecialchars($val, ENT_QUOTES); ?>">
                <?php echo $lbl; ?> <span class="<?php echo $cls; ?>">(<?php echo $cnt; ?>)</span>
            </button>
            <?php endforeach; ?>
        </div>

        <div class="glass-card overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse min-w-[750px]">
                <thead>
                    <tr class="bg-slate-50 dark:bg-slate-800/50">
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Request</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Priority</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Unit / Property</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tenant</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Cost</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Decision</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800" id="maintTableBody">
                    <?php if (empty($maintenance)): ?>
                    <tr><td colspan="7" class="p-12 text-center text-slate-400 italic">No maintenance requests.</td></tr>
                    <?php else: ?>
                    <?php foreach ($maintenance as $m): ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 maint-row" data-status="<?php echo htmlspecialchars($m['status']); ?>">
                        <td class="px-5 py-3.5">
                            <p class="font-black text-sm text-slate-900 dark:text-white"><?php echo htmlspecialchars($m['title'] ?? 'Request'); ?></p>
                            <?php if ($m['description'] ?? ''): ?>
                            <p class="text-[10px] text-slate-400 truncate max-w-[160px]"><?php echo htmlspecialchars(substr($m['description'], 0, 60)); ?></p>
                            <?php endif; ?>
                            <p class="text-[10px] text-slate-300 dark:text-slate-600"><?php echo date('d M Y', strtotime($m['created_at'])); ?></p>
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="text-[9px] font-black uppercase px-2 py-0.5 rounded-lg
                                <?php echo match($m['priority'] ?? 'Normal') { 'Critical','Urgent' => 'bg-red-100 text-red-700', 'High' => 'bg-orange-100 text-orange-700', default => 'bg-slate-100 text-slate-500' }; ?>">
                                <?php echo $m['priority'] ?? 'Normal'; ?>
                            </span>
                        </td>
                        <td class="px-5 py-3.5">
                            <p class="text-sm font-bold text-slate-700 dark:text-slate-300">Unit <?php echo htmlspecialchars($m['unit_number'] ?? '—'); ?></p>
                            <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($m['property_title'] ?? ''); ?></p>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-slate-500"><?php echo htmlspecialchars($m['tenant_name'] ?? '—'); ?></td>
                        <td class="px-5 py-3.5 text-sm font-bold text-slate-700 dark:text-slate-300">
                            <?php echo !empty($m['actual_cost']) ? $currency . ' ' . number_format((float)$m['actual_cost']) : '—'; ?>
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="text-[9px] font-black uppercase px-2 py-0.5 rounded-lg
                                <?php echo match($m['status']) { 'Completed' => 'bg-emerald-100 text-emerald-700', 'In Progress' => 'bg-blue-100 text-blue-700', default => 'bg-orange-100 text-orange-700' }; ?>">
                                <?php echo $m['status']; ?>
                            </span>
                            <?php if ($m['landlord_decision'] ?? ''): ?>
                            <p class="text-[9px] font-black uppercase mt-0.5
                                <?php echo $m['landlord_decision'] === 'Approved' ? 'text-emerald-500' : 'text-red-500'; ?>">
                                LL: <?php echo $m['landlord_decision']; ?>
                            </p>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <?php if ($m['status'] === 'Pending'): ?>
                            <div class="flex items-center justify-end gap-1.5">
                                <form method="POST" action="actions/landlord_actions.php" class="inline">
                                    <input type="hidden" name="action" value="maintenance_decision">
                                    <input type="hidden" name="maintenance_id" value="<?php echo $m['id']; ?>">
                                    <input type="hidden" name="decision" value="Approved">
                                    <input type="hidden" name="_redirect" value="<?php echo urlencode('landlord_profile.php?id=' . $landlordId . '&tab=maintenance&success=Maintenance+approved.'); ?>">
                                    <button type="submit" class="px-3 py-1.5 bg-emerald-100 hover:bg-emerald-200 text-emerald-700 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                                        Approve
                                    </button>
                                </form>
                                <form method="POST" action="actions/landlord_actions.php" class="inline">
                                    <input type="hidden" name="action" value="maintenance_decision">
                                    <input type="hidden" name="maintenance_id" value="<?php echo $m['id']; ?>">
                                    <input type="hidden" name="decision" value="Denied">
                                    <input type="hidden" name="_redirect" value="<?php echo urlencode('landlord_profile.php?id=' . $landlordId . '&tab=maintenance&success=Maintenance+denied.'); ?>">
                                    <button type="submit" class="px-3 py-1.5 bg-red-100 hover:bg-red-200 text-red-700 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                                        Deny
                                    </button>
                                </form>
                            </div>
                            <?php else: ?>
                            <span class="text-[10px] text-slate-300">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════ -->
    <!-- LOANS & ADVANCES TAB                                        -->
    <!-- ════════════════════════════════════════════════════════════ -->
    <div id="tab_loans" class="space-y-6 <?php echo $tab !== 'loans' ? 'hidden' : ''; ?>">

        <!-- KPIs -->
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="glass-card p-5 border-l-[3px] border-red-400">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Active Loans</p>
                <p class="text-2xl font-black text-red-500 mt-1"><?php echo $currency; ?> <?php echo number_format($activeLoansAmt); ?></p>
                <p class="text-[10px] text-slate-400"><?php echo count(array_filter($loans, fn($l) => ($l['status'] ?? '') === 'Active')); ?> loan(s)</p>
            </div>
            <div class="glass-card p-5 border-l-[3px] border-violet-500">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Commission Earned</p>
                <p class="text-2xl font-black text-violet-500 mt-1"><?php echo $currency; ?> <?php echo number_format($totalCommission); ?></p>
                <p class="text-[10px] text-slate-400">all time</p>
            </div>
            <div class="glass-card p-5 border-l-[3px] border-blue-400">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Advances Given</p>
                <p class="text-2xl font-black text-blue-500 mt-1"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($advances, 'amount'))); ?></p>
                <p class="text-[10px] text-slate-400"><?php echo count($advances); ?> advance(s)</p>
            </div>
        </div>

        <!-- Loans table -->
        <div class="glass-card overflow-hidden">
            <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <h3 class="font-black text-slate-900 dark:text-white">Loans</h3>
                <button onclick="openModal('addLoanModal')" class="px-4 py-2 bg-slate-900 dark:bg-white text-white dark:text-slate-900 rounded-xl text-xs font-black uppercase tracking-widest hover:opacity-80 transition-all">
                    + Add Loan
                </button>
            </div>
            <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse min-w-[650px]">
                <thead>
                    <tr class="bg-slate-50 dark:bg-slate-800/50">
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Lender</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Principal</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Repayable</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Interest</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Commission</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Due Date</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php if (empty($loans)): ?>
                    <tr><td colspan="8" class="p-10 text-center text-slate-400 italic">No loans recorded.</td></tr>
                    <?php else: ?>
                    <?php foreach ($loans as $loan): ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20">
                        <td class="px-5 py-3.5 font-bold text-sm text-slate-800 dark:text-white"><?php echo htmlspecialchars($loan['lender_name'] ?? '—'); ?></td>
                        <td class="px-5 py-3.5 text-sm font-bold text-slate-600 dark:text-slate-300"><?php echo $currency; ?> <?php echo number_format((float)($loan['principal_amount'] ?? 0)); ?></td>
                        <td class="px-5 py-3.5 font-black text-sm text-red-500"><?php echo $currency; ?> <?php echo number_format((float)($loan['total_repayable'] ?? 0)); ?></td>
                        <td class="px-5 py-3.5 text-sm text-slate-500"><?php echo number_format((float)($loan['interest_rate'] ?? 0), 1); ?>%</td>
                        <td class="px-5 py-3.5">
                            <p class="font-black text-sm text-violet-600"><?php echo $currency; ?> <?php echo number_format((float)($loan['commission_amount'] ?? 0)); ?></p>
                            <?php if ($loan['commission_rate'] ?? 0): ?><p class="text-[10px] text-slate-400"><?php echo $loan['commission_rate']; ?>%</p><?php endif; ?>
                        </td>
                        <td class="px-5 py-3.5 text-xs text-slate-400"><?php echo $loan['due_date'] ? date('d M Y', strtotime($loan['due_date'])) : '—'; ?></td>
                        <td class="px-5 py-3.5">
                            <span class="text-[9px] font-black uppercase px-2 py-0.5 rounded-lg
                                <?php echo match($loan['status'] ?? 'Active') { 'Cleared' => 'bg-emerald-100 text-emerald-700', 'Defaulted' => 'bg-red-100 text-red-700', default => 'bg-orange-100 text-orange-700' }; ?>">
                                <?php echo $loan['status'] ?? 'Active'; ?>
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <form method="POST" action="actions/landlord_actions.php" class="inline"
                                  onsubmit="return confirm('Delete this loan record?')">
                                <input type="hidden" name="action" value="delete_loan">
                                <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                <input type="hidden" name="_redirect" value="<?php echo urlencode('landlord_profile.php?id=' . $landlordId . '&tab=loans'); ?>">
                                <button type="submit" class="text-[10px] font-black uppercase tracking-widest text-red-400 hover:text-red-600 transition-colors">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <!-- Advances table -->
        <div class="glass-card overflow-hidden">
            <div class="p-5 border-b border-slate-100 dark:border-slate-800">
                <h3 class="font-black text-slate-900 dark:text-white">Advances</h3>
            </div>
            <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse min-w-[500px]">
                <thead>
                    <tr class="bg-slate-50 dark:bg-slate-800/50">
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Purpose</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php if (empty($advances)): ?>
                    <tr><td colspan="4" class="p-8 text-center text-slate-400 italic">No advances recorded.</td></tr>
                    <?php else: ?>
                    <?php foreach ($advances as $adv): ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20">
                        <td class="px-5 py-3.5 font-bold text-sm text-slate-800 dark:text-white"><?php echo htmlspecialchars($adv['purpose'] ?? '—'); ?></td>
                        <td class="px-5 py-3.5 font-black text-sm text-blue-600"><?php echo $currency; ?> <?php echo number_format((float)$adv['amount']); ?></td>
                        <td class="px-5 py-3.5 text-xs text-slate-400"><?php echo date('d M Y', strtotime($adv['requested_at'])); ?></td>
                        <td class="px-5 py-3.5">
                            <span class="text-[9px] font-black uppercase px-2 py-0.5 rounded-lg
                                <?php echo $adv['is_deducted'] ? 'bg-slate-100 text-slate-500' : match($adv['status'] ?? 'Pending') { 'Approved' => 'bg-emerald-100 text-emerald-700', 'Declined' => 'bg-red-100 text-red-700', default => 'bg-orange-100 text-orange-700' }; ?>">
                                <?php echo $adv['is_deducted'] ? 'Deducted' : ($adv['status'] ?? 'Pending'); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════ -->
    <!-- PROFILE TAB                                                 -->
    <!-- ════════════════════════════════════════════════════════════ -->
    <div id="tab_profile" class="<?php echo $tab !== 'profile' ? 'hidden' : ''; ?>">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

            <!-- Personal details -->
            <div class="glass-card p-6 space-y-5">
                <h3 class="font-black text-slate-900 dark:text-white border-b border-slate-100 dark:border-slate-800 pb-3">Personal Details</h3>
                <?php foreach ([
                    ['Full Name',         $ll['full_name']],
                    ['Email',             $ll['email']],
                    ['Phone',             $ll['phone'] ?: '—'],
                    ['National ID',       $ll['id_number'] ?? '—'],
                    ['Residential Address', $ll['address'] ?? '—'],
                    ['Joined',            date('d M Y', strtotime($ll['created_at'] ?? 'now'))],
                ] as [$lbl, $val]): ?>
                <div class="flex justify-between items-start gap-4">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest shrink-0"><?php echo $lbl; ?></p>
                    <p class="text-sm font-bold text-slate-700 dark:text-slate-300 text-right"><?php echo htmlspecialchars((string)$val); ?></p>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Next of kin + Fee -->
            <div class="space-y-5">
                <div class="glass-card p-6 space-y-4">
                    <h3 class="font-black text-slate-900 dark:text-white border-b border-slate-100 dark:border-slate-800 pb-3">Next of Kin</h3>
                    <?php foreach ([
                        ['Name',         $ll['nok_name'] ?? '—'],
                        ['Phone',        $ll['nok_phone'] ?? '—'],
                        ['Relationship', $ll['nok_relationship'] ?? '—'],
                    ] as [$lbl, $val]): ?>
                    <div class="flex justify-between items-center gap-4">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest"><?php echo $lbl; ?></p>
                        <p class="text-sm font-bold text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars((string)$val); ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="glass-card p-6 space-y-4">
                    <h3 class="font-black text-slate-900 dark:text-white border-b border-slate-100 dark:border-slate-800 pb-3">Management Fee</h3>
                    <div class="flex justify-between items-center gap-4">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Type</p>
                        <p class="text-sm font-black <?php echo $feeType === 'fixed' ? 'text-blue-600' : 'text-orange-500'; ?>"><?php echo $feeType === 'fixed' ? 'Fixed Amount' : 'Percentage (%)'; ?></p>
                    </div>
                    <div class="flex justify-between items-center gap-4">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount</p>
                        <p class="text-2xl font-black <?php echo $feeType === 'fixed' ? 'text-blue-600' : 'text-orange-500'; ?>"><?php echo $feeDisplay; ?></p>
                    </div>
                    <p class="text-[10px] text-slate-400"><?php echo $feeType === 'fixed' ? 'Flat fee deducted per payout period.' : 'Deducted from gross rent collected each period.'; ?></p>
                </div>

                <div class="glass-card p-6">
                    <button onclick="openModal('editLandlordInlineModal')"
                            class="w-full py-3 bg-slate-900 dark:bg-white text-white dark:text-slate-900 rounded-xl text-sm font-black uppercase tracking-widest hover:opacity-80 transition-all">
                        Edit Profile
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- ════════════════════════════════════════════════════════════ -->
    <!-- DOCUMENTS TAB                                                -->
    <!-- ════════════════════════════════════════════════════════════ -->
    <div id="tab_documents" class="space-y-5 <?php echo $tab !== 'documents' ? 'hidden' : ''; ?>">

        <!-- Upload form -->
        <div class="glass-card p-6">
            <h3 class="font-black text-slate-900 dark:text-white mb-5">Upload Document</h3>
            <form action="actions/landlord_actions.php" method="POST" enctype="multipart/form-data"
                  class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
                <input type="hidden" name="action" value="upload_document">
                <input type="hidden" name="landlord_id" value="<?php echo $landlordId; ?>">
                <input type="hidden" name="_redirect" value="<?php echo urlencode('landlord_profile.php?id=' . $landlordId . '&tab=documents&success=Document+uploaded.'); ?>">
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Title *</label>
                    <input type="text" name="title" required placeholder="e.g. Lease Agreement" class="form-input">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Category</label>
                    <select name="category" class="form-input">
                        <?php foreach (['General','ID Document','Contract','Agreement','Receipt','Certificate','Other'] as $cat): ?>
                        <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">File * (PDF, DOC, JPG, PNG)</label>
                    <input type="file" name="document_file" required
                           accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx"
                           class="form-input file:mr-3 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-black file:bg-slate-200 dark:file:bg-slate-700 file:text-slate-700 dark:file:text-slate-300 cursor-pointer">
                </div>
                <div class="sm:col-span-3">
                    <button type="submit" class="btn-green px-6 py-2.5">Upload Document</button>
                </div>
            </form>
        </div>

        <!-- Documents list -->
        <div class="glass-card overflow-hidden">
            <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <h3 class="font-black text-slate-900 dark:text-white">Documents (<?php echo count($documents); ?>)</h3>
            </div>
            <?php if (empty($documents)): ?>
            <p class="p-12 text-center text-slate-400 italic">No documents uploaded yet.</p>
            <?php else: ?>
            <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse min-w-[540px]">
                <thead>
                    <tr class="bg-slate-50 dark:bg-slate-800/50">
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Title</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Category</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Size</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Uploaded</th>
                        <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($documents as $doc):
                        $sz = (int)$doc['file_size'];
                        $szLabel = $sz > 1048576 ? round($sz/1048576, 1) . ' MB' : round($sz/1024) . ' KB';
                    ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20">
                        <td class="px-5 py-3.5">
                            <p class="font-black text-sm text-slate-900 dark:text-white"><?php echo htmlspecialchars($doc['title']); ?></p>
                            <p class="text-[10px] text-slate-400 font-mono truncate max-w-[200px]"><?php echo htmlspecialchars($doc['file_name']); ?></p>
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="text-[10px] font-black px-2 py-0.5 bg-slate-100 dark:bg-slate-800 rounded-lg text-slate-600 dark:text-slate-400">
                                <?php echo htmlspecialchars($doc['category']); ?>
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-xs text-slate-400"><?php echo $szLabel; ?></td>
                        <td class="px-5 py-3.5 text-xs text-slate-400"><?php echo date('d M Y', strtotime($doc['created_at'])); ?></td>
                        <td class="px-5 py-3.5">
                            <div class="flex items-center justify-end gap-4">
                                <a href="../<?php echo htmlspecialchars($doc['file_url']); ?>" target="_blank"
                                   class="text-[10px] font-black uppercase tracking-widest text-blue-500 hover:text-blue-700 transition-colors">
                                    Download
                                </a>
                                <form method="POST" action="actions/landlord_actions.php" class="inline"
                                      onsubmit="return confirm('Delete this document?')">
                                    <input type="hidden" name="action" value="delete_document">
                                    <input type="hidden" name="document_id" value="<?php echo $doc['id']; ?>">
                                    <input type="hidden" name="_redirect" value="<?php echo urlencode('landlord_profile.php?id=' . $landlordId . '&tab=documents&success=Document+deleted.'); ?>">
                                    <button type="submit" class="text-[10px] font-black uppercase tracking-widest text-red-400 hover:text-red-600 transition-colors">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- end main wrapper -->


<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- ADD LOAN MODAL                                                    -->
<!-- ══════════════════════════════════════════════════════════════════ -->
<div id="addLoanModal" class="modal-overlay" style="display:none;">
    <div class="modal-card" style="max-width:560px;">
        <button onclick="closeModal('addLoanModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-1 text-slate-900 dark:text-white">Record Loan</h2>
        <p class="text-sm text-slate-400 mb-6">Log a loan for this landlord with commission details.</p>
        <form action="actions/landlord_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="add_loan">
            <input type="hidden" name="landlord_id" value="<?php echo $landlordId; ?>">
            <input type="hidden" name="_redirect" value="<?php echo urlencode('landlord_profile.php?id=' . $landlordId . '&tab=loans&success=Loan+recorded.'); ?>">

            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1.5 col-span-2">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Lender Name *</label>
                    <input type="text" name="lender_name" required placeholder="e.g. Equity Bank" class="form-input">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Principal Amount *</label>
                    <input type="number" name="principal_amount" required min="1" step="0.01" placeholder="0.00" class="form-input">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Total Repayable *</label>
                    <input type="number" name="total_repayable" required min="1" step="0.01" placeholder="0.00" class="form-input">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Interest Rate (%)</label>
                    <input type="number" name="interest_rate" min="0" max="100" step="0.1" value="0" class="form-input">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Commission Rate (%)</label>
                    <input type="number" name="commission_rate" id="loanCommRate" min="0" max="100" step="0.1" value="0" oninput="calcCommission()" class="form-input">
                </div>
                <div class="space-y-1.5 col-span-2">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">
                        Commission Amount <span id="commCalcHint" class="text-violet-400 normal-case font-medium"></span>
                    </label>
                    <input type="number" name="commission_amount" id="loanCommAmt" min="0" step="0.01" value="0" class="form-input">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Due Date</label>
                    <input type="date" name="due_date" class="form-input">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Status</label>
                    <select name="status" class="form-input">
                        <option value="Active">Active</option>
                        <option value="Cleared">Cleared</option>
                        <option value="Defaulted">Defaulted</option>
                    </select>
                </div>
                <div class="space-y-1.5 col-span-2">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Notes</label>
                    <textarea name="description" rows="2" placeholder="Optional notes about this loan…" class="form-input resize-none"></textarea>
                </div>
            </div>
            <button type="submit" class="btn-green w-full justify-center py-4">Record Loan</button>
        </form>
    </div>
</div>


<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- EDIT LANDLORD INLINE MODAL (mirrors landlords.php edit)          -->
<!-- ══════════════════════════════════════════════════════════════════ -->
<div id="editLandlordInlineModal" class="modal-overlay" style="display:none;">
    <div class="modal-card" style="max-width:640px;">
        <button onclick="closeModal('editLandlordInlineModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-1 text-slate-900 dark:text-white">Edit Landlord Profile</h2>
        <p class="text-sm text-slate-400 mb-7">Changes sync to login account immediately.</p>
        <form action="actions/landlord_actions.php" method="POST" class="space-y-6">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="landlord_id" value="<?php echo $landlordId; ?>">
            <input type="hidden" name="_redirect" value="<?php echo urlencode('landlord_profile.php?id=' . $landlordId . '&tab=profile&success=Profile+updated.'); ?>">

            <div class="space-y-4">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 dark:border-slate-800 pb-2">Personal Details</p>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Full Name *</label>
                        <input type="text" name="full_name" required value="<?php echo htmlspecialchars($ll['full_name']); ?>" class="form-input">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Phone</label>
                        <input type="text" name="phone" value="<?php echo htmlspecialchars($ll['phone'] ?? ''); ?>" class="form-input">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Email *</label>
                        <input type="email" name="email" required value="<?php echo htmlspecialchars($ll['email']); ?>" class="form-input">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">National ID</label>
                        <input type="text" name="id_number" value="<?php echo htmlspecialchars($ll['id_number'] ?? ''); ?>" class="form-input">
                    </div>
                    <div class="space-y-1.5 col-span-2">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Address</label>
                        <input type="text" name="address" value="<?php echo htmlspecialchars($ll['address'] ?? ''); ?>" class="form-input">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Status</label>
                        <select name="status" class="form-input">
                            <option value="active" <?php echo ($ll['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo ($ll['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 dark:border-slate-800 pb-2">Next of Kin</p>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Name</label>
                        <input type="text" name="nok_name" value="<?php echo htmlspecialchars($ll['nok_name'] ?? ''); ?>" class="form-input">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Phone</label>
                        <input type="text" name="nok_phone" value="<?php echo htmlspecialchars($ll['nok_phone'] ?? ''); ?>" class="form-input">
                    </div>
                    <div class="space-y-1.5 col-span-2">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Relationship</label>
                        <select name="nok_relationship" class="form-input">
                            <?php foreach (['','Spouse','Parent','Sibling','Child','Friend','Other'] as $rel): ?>
                            <option value="<?php echo $rel; ?>" <?php echo ($ll['nok_relationship'] ?? '') === $rel ? 'selected' : ''; ?>>
                                <?php echo $rel ?: '— Select —'; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 dark:border-slate-800 pb-2">Management Fee</p>
                <div class="flex gap-3">
                    <label class="flex-1 flex items-center gap-3 p-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 cursor-pointer has-[:checked]:border-orange-400 has-[:checked]:bg-orange-50 dark:has-[:checked]:bg-orange-900/10">
                        <input type="radio" name="fee_type" value="percentage" <?php echo ($ll['fee_type'] ?? 'percentage') === 'percentage' ? 'checked' : ''; ?> class="accent-orange-400">
                        <span class="font-black text-sm text-slate-800 dark:text-white">Percentage (%)</span>
                    </label>
                    <label class="flex-1 flex items-center gap-3 p-3 rounded-xl border-2 border-slate-200 dark:border-slate-700 cursor-pointer has-[:checked]:border-blue-400 has-[:checked]:bg-blue-50 dark:has-[:checked]:bg-blue-900/10">
                        <input type="radio" name="fee_type" value="fixed" <?php echo ($ll['fee_type'] ?? '') === 'fixed' ? 'checked' : ''; ?> class="accent-blue-400">
                        <span class="font-black text-sm text-slate-800 dark:text-white">Fixed Amount</span>
                    </label>
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Amount</label>
                    <input type="number" name="management_fee" value="<?php echo (float)($ll['management_fee'] ?? 10); ?>" min="0" step="0.01" class="form-input w-full">
                </div>
            </div>

            <button type="submit" class="btn-green w-full justify-center py-4">Save Changes</button>
        </form>
    </div>
</div>


<style>
.no-scrollbar::-webkit-scrollbar { display:none; }
.no-scrollbar { -ms-overflow-style:none; scrollbar-width:none; }
</style>

<script>
// ── Tab switching ─────────────────────────────────────────────────
function switchTab(key) {
    document.querySelectorAll('[id^="tab_"]').forEach(el => {
        if (el.id.startsWith('tab_btn_')) return;
        el.classList.add('hidden');
    });
    const panel = document.getElementById('tab_' + key);
    if (panel) panel.classList.remove('hidden');

    document.querySelectorAll('[id^="tab_btn_"]').forEach(btn => {
        const active = btn.id === 'tab_btn_' + key;
        btn.className = 'flex items-center gap-2 px-4 py-2.5 rounded-t-xl text-xs font-black uppercase tracking-wider whitespace-nowrap transition-all ' +
            (active ? 'bg-white dark:bg-slate-800 text-slate-900 dark:text-white shadow-sm border border-slate-100 dark:border-slate-700 border-b-white dark:border-b-slate-800 -mb-px'
                    : 'text-slate-400 hover:text-slate-700 dark:hover:text-slate-200');
    });

    history.replaceState(null, '', '?id=<?php echo urlencode($landlordId); ?>&tab=' + key);

    if (key === 'overview' && window._chartInited) return;
    if (key === 'overview') initChart();
}

// ── Income chart ──────────────────────────────────────────────────
function initChart() {
    window._chartInited = true;
    const ctx = document.getElementById('incomeChart');
    if (!ctx || !window.Chart) return;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($trendLabels); ?>,
            datasets: [{
                label: 'Income Collected',
                data: <?php echo json_encode($trendValues); ?>,
                backgroundColor: 'rgba(16,185,129,0.15)',
                borderColor: '#10b981',
                borderWidth: 2,
                borderRadius: 8,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: true,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 11, weight: '700' }, color: '#94a3b8' } },
                y: { grid: { color: 'rgba(148,163,184,0.1)' }, ticks: { font: { size: 11, weight: '700' }, color: '#94a3b8',
                    callback: v => '<?php echo addslashes($currency); ?> ' + (v >= 1000 ? (v/1000).toFixed(0) + 'k' : v)
                }}
            }
        }
    });
}

// ── Maintenance filter ────────────────────────────────────────────
function filterMaint(val) {
    document.querySelectorAll('.maint-row').forEach(r => {
        r.style.display = (val === 'all' || r.dataset.status === val) ? '' : 'none';
    });
    document.querySelectorAll('.maint-filter-btn').forEach(btn => {
        const active = btn.dataset.val === val;
        btn.className = 'maint-filter-btn px-3 py-1.5 rounded-lg text-xs font-black transition-all ' +
            (active ? 'bg-slate-900 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-500');
    });
}

// ── Commission auto-calc ──────────────────────────────────────────
function calcCommission() {
    const rate = parseFloat(document.getElementById('loanCommRate').value) || 0;
    const principal = parseFloat(document.querySelector('[name=principal_amount]')?.value) || 0;
    if (rate > 0 && principal > 0) {
        const amt = (principal * rate / 100).toFixed(2);
        document.getElementById('loanCommAmt').value = amt;
        document.getElementById('commCalcHint').textContent = '— auto-calc: ' + rate + '% of ' + principal.toLocaleString();
    }
}

// Init chart on page load if overview tab is active
window.addEventListener('DOMContentLoaded', () => {
    <?php if ($tab === 'overview'): ?>initChart();<?php endif; ?>
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
