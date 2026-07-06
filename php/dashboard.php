<?php
/**
 * Main Dashboard — Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$role     = $_SESSION['role'] ?? 'tenant';
$user     = getCurrentUser($pdo);
$userName = $user['full_name'] ?? 'User';
$pageTitle = "Dashboard";

// ── Route landlords ──────────────────────────────────────────────
if ($role === 'landlord') {
    include __DIR__ . '/includes/header.php';
    include __DIR__ . '/includes/sidebar.php';
    include __DIR__ . '/includes/landlord_dashboard.php';
    include __DIR__ . '/includes/footer.php';
    exit();
}

// ── Route tenants ────────────────────────────────────────────────
if ($role === 'tenant') {
    $stmt = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $tenantId = $stmt->fetchColumn();
    include __DIR__ . '/includes/header.php';
    include __DIR__ . '/includes/sidebar.php';
    include __DIR__ . '/includes/tenant_dashboard.php';
    include __DIR__ . '/includes/footer.php';
    exit();
}

// ── Admin / Staff only ──────────────────────────────────────────
require_once __DIR__ . '/includes/automated_billing.php';
require_once __DIR__ . '/includes/overdue_billing.php';
require_once __DIR__ . '/includes/lease_reminders.php';
runAutomatedBilling($pdo);
runOverdueBilling($pdo);
runLeaseReminders($pdo);

require_once __DIR__ . '/includes/settings.php';
$currency = getSetting($pdo, 'currency_symbol', 'KSh');

// ── KPI Stats ───────────────────────────────────────────────────
$totalProperties   = (int)$pdo->query("SELECT COUNT(*) FROM properties")->fetchColumn();
$activeTenants     = (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE status='Active'")->fetchColumn();
$pendingMaint      = (int)$pdo->query("SELECT COUNT(*) FROM maintenance_requests WHERE status='Pending'")->fetchColumn();
$overdueInvoices   = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status='Overdue'")->fetchColumn();
$overdueTenants    = (int)$pdo->query("SELECT COUNT(DISTINCT tenant_id) FROM invoices WHERE status='Overdue'")->fetchColumn();

$totalUnits    = (int)$pdo->query("SELECT COUNT(*) FROM units")->fetchColumn();
$occupiedUnits = (int)$pdo->query("SELECT COUNT(*) FROM units WHERE status='Occupied'")->fetchColumn();
$vacantUnits   = $totalUnits - $occupiedUnits;
$occupancyRate = $totalUnits > 0 ? round(($occupiedUnits / $totalUnits) * 100, 1) : 0;

$revMTD       = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='Paid' AND MONTH(transaction_date)=MONTH(NOW()) AND YEAR(transaction_date)=YEAR(NOW())")->fetchColumn();
$revYTD       = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='Paid' AND YEAR(transaction_date)=YEAR(NOW())")->fetchColumn();
$outstanding  = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status IN ('Unpaid','Overdue')")->fetchColumn();

$revLastMonth = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='Paid' AND MONTH(transaction_date)=MONTH(DATE_SUB(NOW(),INTERVAL 1 MONTH)) AND YEAR(transaction_date)=YEAR(DATE_SUB(NOW(),INTERVAL 1 MONTH))")->fetchColumn();
$revTrendPct  = $revLastMonth > 0 ? round((($revMTD - $revLastMonth) / $revLastMonth) * 100, 1) : null;

// ── Charts ──────────────────────────────────────────────────────
$chartLabels   = [];
$chartCollected = [];
$chartInvoiced  = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('m', strtotime("-$i months"));
    $y = date('Y', strtotime("-$i months"));
    $chartLabels[]   = date('M', strtotime("-$i months"));
    $cStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='Paid' AND DATE_FORMAT(transaction_date,'%m')=? AND DATE_FORMAT(transaction_date,'%Y')=?");
    $cStmt->execute([$m, $y]); $chartCollected[] = (float)$cStmt->fetchColumn();
    $iStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE DATE_FORMAT(created_at,'%m')=? AND DATE_FORMAT(created_at,'%Y')=?");
    $iStmt->execute([$m, $y]); $chartInvoiced[]  = (float)$iStmt->fetchColumn();
}

// ── Expiring Leases (next 60 days) ─────────────────────────────
$expiringLeases = $pdo->query("
    SELECT l.id, l.end_date, l.renewal_status, t.full_name, t.id AS tenant_id,
           p.title as prop_title, p.id as prop_id, u.unit_number,
           DATEDIFF(l.end_date, CURDATE()) as days_left
    FROM leases l
    JOIN tenants t ON l.tenant_id = t.id
    JOIN units u ON l.unit_id = u.id
    JOIN properties p ON u.property_id = p.id
    WHERE l.status='Active' AND l.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
    ORDER BY l.end_date ASC LIMIT 8
")->fetchAll();

// ── Recent Activity ─────────────────────────────────────────────
$recentTransactions = $pdo->query("
    SELECT t.full_name, tx.amount, tx.status, tx.transaction_date, tx.transaction_type
    FROM transactions tx JOIN tenants t ON tx.tenant_id = t.id
    ORDER BY tx.transaction_date DESC LIMIT 5
")->fetchAll();

$recentMaint = $pdo->query("
    SELECT mr.title, mr.status, mr.priority, mr.created_at,
           t.full_name as tenant_name, p.title as prop_title
    FROM maintenance_requests mr
    LEFT JOIN tenants t ON mr.tenant_id = t.id
    LEFT JOIN properties p ON mr.property_id = p.id
    ORDER BY mr.created_at DESC LIMIT 5
")->fetchAll();

// ── Portfolio health score (0-100) ─────────────────────────────
$healthScore = 0;
if ($totalUnits > 0)      $healthScore += ($occupancyRate * 0.4);    // 40 pts
if ($activeTenants > 0)   $healthScore += ($overdueInvoices === 0 ? 30 : max(0, 30 - ($overdueInvoices * 3))); // 30 pts
if ($pendingMaint <= 3)   $healthScore += 20;                         // 20 pts
elseif ($pendingMaint <= 8) $healthScore += 10;
$revRateNorm  = $chartInvoiced[5] > 0 ? ($chartCollected[5] / $chartInvoiced[5]) * 10 : 10;
$healthScore += min(10, $revRateNorm);                                 // 10 pts
$healthScore  = min(100, round($healthScore));
$healthLabel  = $healthScore >= 80 ? 'Excellent' : ($healthScore >= 60 ? 'Good' : ($healthScore >= 40 ? 'Fair' : 'Needs Attention'));
$healthColor  = $healthScore >= 80 ? 'text-green-500' : ($healthScore >= 60 ? 'text-blue-500' : ($healthScore >= 40 ? 'text-orange-500' : 'text-red-500'));

// ── Collection rate ─────────────────────────────────────────────
$mtdInvoiced    = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
$collectionRate = $mtdInvoiced > 0 ? round(($revMTD / $mtdInvoiced) * 100, 1) : ($revMTD > 0 ? 100 : 0);

// ── Per-property snapshot ───────────────────────────────────────
$propertyStats = [];
try {
    $propertyStats = $pdo->query("
        SELECT p.id, p.title, p.location, p.property_type,
               COUNT(u.id) AS total_units,
               SUM(CASE WHEN u.status='Occupied' THEN 1 ELSE 0 END) AS occupied_units,
               COALESCE(SUM(CASE WHEN u.status='Occupied' THEN u.monthly_rent ELSE 0 END), 0) AS monthly_rent,
               COALESCE((
                   SELECT SUM(i.amount) FROM invoices i
                   JOIN leases l2 ON i.tenant_id = l2.tenant_id AND l2.status='Active'
                   JOIN units u2 ON l2.unit_id = u2.id
                   WHERE u2.property_id = p.id AND i.status IN ('Unpaid','Overdue')
               ), 0) AS outstanding
        FROM properties p
        LEFT JOIN units u ON u.property_id = p.id
        GROUP BY p.id, p.title, p.location, p.property_type
        ORDER BY monthly_rent DESC
        LIMIT 12
    ")->fetchAll();
} catch (PDOException $_e) {}

// ── Top debtors ─────────────────────────────────────────────────
$topDebtors = [];
try {
    $topDebtors = $pdo->query("
        SELECT t.id, t.full_name, u.unit_number, p.title AS prop_title,
               COALESCE(SUM(i.amount), 0) AS total_outstanding
        FROM invoices i
        JOIN tenants t ON i.tenant_id = t.id
        LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'Active'
        LEFT JOIN units u ON l.unit_id = u.id
        LEFT JOIN properties p ON u.property_id = p.id
        WHERE i.status IN ('Unpaid','Overdue')
        GROUP BY t.id, t.full_name, u.unit_number, p.title
        HAVING total_outstanding > 0
        ORDER BY total_outstanding DESC
        LIMIT 5
    ")->fetchAll();
} catch (PDOException $_e) {}

// ── Landlords list for new-property modal ───────────────────────
$landlordList = [];
try {
    $landlordList = $pdo->query("SELECT id, full_name FROM landlords ORDER BY full_name")->fetchAll();
} catch (PDOException $_e) {}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-7 animate-in">

    <!-- ── Greeting ──────────────────────────────────────── -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-3 flex-wrap">
                <h1 class="text-2xl lg:text-3xl font-black text-slate-900 dark:text-white tracking-tight">
                    Good <?php echo date('H') < 12 ? 'morning' : (date('H') < 18 ? 'afternoon' : 'evening'); ?>, <?php echo htmlspecialchars(explode(' ', $userName)[0]); ?>
                </h1>
                <div class="flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-black border <?php echo $healthScore >= 80 ? 'border-green-200 dark:border-green-800 text-green-600 bg-green-50 dark:bg-green-900/20' : ($healthScore >= 60 ? 'border-blue-200 dark:border-blue-800 text-blue-600 bg-blue-50 dark:bg-blue-900/20' : 'border-orange-200 dark:border-orange-800 text-orange-600 bg-orange-50 dark:bg-orange-900/20'); ?>">
                    <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                    Portfolio Health: <?php echo $healthLabel; ?>
                </div>
            </div>
            <p class="text-slate-500 dark:text-slate-400 font-medium text-sm mt-1"><?php echo date('l, F j, Y'); ?> &nbsp;·&nbsp; <?php echo $totalProperties; ?> propert<?php echo $totalProperties !== 1 ? 'ies' : 'y'; ?>, <?php echo $totalUnits; ?> units</p>
        </div>
        <div class="flex gap-2.5 flex-wrap">
            <button onclick="openModal('newPropertyModal')" class="btn-primary text-xs gap-2">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                New Property
            </button>
            <a href="tenants.php?action=new" class="btn-green text-xs gap-2">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                Register Tenant
            </a>
        </div>
    </div>

    <!-- ── Income Summary Strip ─────────────────────────── -->
    <div class="glass-card bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 border-none overflow-hidden relative">
        <div class="absolute -right-20 -top-20 w-80 h-80 bg-accent-green/5 rounded-full blur-3xl pointer-events-none"></div>
        <div class="grid grid-cols-2 sm:grid-cols-4 divide-x divide-slate-700/50">
            <div class="px-6 py-5 text-center">
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1"><?php echo date('M Y'); ?> Collected</p>
                <p class="text-2xl font-black text-white"><?php echo $currency; ?> <?php echo number_format($revMTD); ?></p>
                <?php if ($revTrendPct !== null): ?>
                <p class="text-[10px] font-bold mt-1 <?php echo $revTrendPct >= 0 ? 'text-accent-green' : 'text-red-400'; ?>">
                    <?php echo $revTrendPct >= 0 ? '▲' : '▼'; ?> <?php echo abs($revTrendPct); ?>% vs last month
                </p>
                <?php endif; ?>
            </div>
            <div class="px-6 py-5 text-center">
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">YTD <?php echo date('Y'); ?></p>
                <p class="text-2xl font-black text-white"><?php echo $currency; ?> <?php echo number_format($revYTD); ?></p>
                <p class="text-[10px] text-slate-500 font-medium mt-1">year to date</p>
            </div>
            <div class="px-6 py-5 text-center">
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Outstanding</p>
                <p class="text-2xl font-black <?php echo $outstanding > 0 ? 'text-red-400' : 'text-accent-green'; ?>"><?php echo $currency; ?> <?php echo number_format($outstanding); ?></p>
                <p class="text-[10px] text-slate-500 font-medium mt-1"><?php echo $overdueTenants; ?> tenant<?php echo $overdueTenants !== 1 ? 's' : ''; ?> owing</p>
            </div>
            <div class="px-6 py-5 text-center">
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1">Collection Rate</p>
                <p class="text-2xl font-black <?php echo $collectionRate >= 90 ? 'text-accent-green' : ($collectionRate >= 70 ? 'text-amber-400' : 'text-red-400'); ?>"><?php echo $collectionRate; ?>%</p>
                <div class="w-full bg-slate-700 h-1 rounded-full mt-2 overflow-hidden mx-auto" style="max-width:80px;">
                    <div class="h-full rounded-full <?php echo $collectionRate >= 90 ? 'bg-accent-green' : ($collectionRate >= 70 ? 'bg-amber-400' : 'bg-red-400'); ?>" style="width:<?php echo min(100, $collectionRate); ?>%"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── 6 KPI Cards ───────────────────────────────────── -->
    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-4">

        <!-- Properties -->
        <div class="glass-card stat-card p-5 flex flex-col gap-2 hover:-translate-y-0.5 transition-transform cursor-pointer" onclick="window.location='properties.php'">
            <div class="flex items-center justify-between">
                <div class="w-9 h-9 rounded-xl bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center text-blue-500">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
                </div>
                <span class="text-[9px] font-black text-slate-300 dark:text-slate-600 uppercase tracking-widest">Props</span>
            </div>
            <div>
                <h3 class="text-2xl font-black text-slate-900 dark:text-white"><?php echo $totalProperties; ?></h3>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mt-0.5">Properties</p>
            </div>
        </div>

        <!-- Tenants -->
        <div class="glass-card stat-card p-5 flex flex-col gap-2 hover:-translate-y-0.5 transition-transform cursor-pointer" onclick="window.location='tenants.php'">
            <div class="flex items-center justify-between">
                <div class="w-9 h-9 rounded-xl bg-green-50 dark:bg-green-900/30 flex items-center justify-center text-green-500">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <span class="text-[9px] font-black text-slate-300 dark:text-slate-600 uppercase tracking-widest">Active</span>
            </div>
            <div>
                <h3 class="text-2xl font-black text-slate-900 dark:text-white"><?php echo $activeTenants; ?></h3>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mt-0.5">Tenants</p>
            </div>
        </div>

        <!-- Occupancy -->
        <div class="glass-card stat-card p-5 flex flex-col gap-2 hover:-translate-y-0.5 transition-transform cursor-pointer" onclick="window.location='reports.php?tab=occupancy'">
            <div class="flex items-center justify-between">
                <div class="w-9 h-9 rounded-xl <?php echo $occupancyRate >= 80 ? 'bg-green-50 dark:bg-green-900/30 text-green-500' : ($occupancyRate >= 50 ? 'bg-orange-50 dark:bg-orange-900/30 text-orange-500' : 'bg-red-50 dark:bg-red-900/30 text-red-500'); ?> flex items-center justify-center">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/><path d="M15 3v18"/></svg>
                </div>
                <span class="text-[9px] font-black <?php echo $vacantUnits > 0 ? 'text-orange-400' : 'text-slate-300 dark:text-slate-600'; ?> uppercase tracking-widest"><?php echo $vacantUnits; ?> vac</span>
            </div>
            <div>
                <h3 class="text-2xl font-black <?php echo $occupancyRate >= 80 ? 'text-green-500' : ($occupancyRate >= 50 ? 'text-orange-500' : 'text-red-500'); ?>"><?php echo $occupancyRate; ?>%</h3>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mt-0.5">Occupancy</p>
            </div>
            <div class="progress"><div class="progress-fill <?php echo $occupancyRate >= 80 ? 'bg-green-500' : ($occupancyRate >= 50 ? 'bg-orange-400' : 'bg-red-500'); ?>" style="width:<?php echo $occupancyRate; ?>%"></div></div>
        </div>

        <!-- Revenue MTD -->
        <div class="glass-card stat-card p-5 flex flex-col gap-2 hover:-translate-y-0.5 transition-transform cursor-pointer" onclick="window.location='financials.php'">
            <div class="flex items-center justify-between">
                <div class="w-9 h-9 rounded-xl bg-emerald-50 dark:bg-emerald-900/30 flex items-center justify-center text-emerald-500">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                </div>
                <?php if ($revTrendPct !== null): ?>
                <span class="kpi-trend <?php echo $revTrendPct >= 0 ? 'up' : 'down'; ?>">
                    <?php echo $revTrendPct >= 0 ? '▲' : '▼'; ?> <?php echo abs($revTrendPct); ?>%
                </span>
                <?php endif; ?>
            </div>
            <div>
                <h3 class="text-xl font-black text-slate-900 dark:text-white leading-tight"><?php echo $currency; ?> <?php echo number_format($revMTD); ?></h3>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mt-0.5">Rev. MTD</p>
            </div>
        </div>

        <!-- Maintenance -->
        <div class="glass-card stat-card p-5 flex flex-col gap-2 hover:-translate-y-0.5 transition-transform cursor-pointer" onclick="window.location='maintenance.php?status=Pending'">
            <div class="flex items-center justify-between">
                <div class="w-9 h-9 rounded-xl <?php echo $pendingMaint > 5 ? 'bg-red-50 dark:bg-red-900/30 text-red-500' : ($pendingMaint > 0 ? 'bg-orange-50 dark:bg-orange-900/30 text-orange-500' : 'bg-green-50 dark:bg-green-900/30 text-green-500'); ?> flex items-center justify-center">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                </div>
                <span class="text-[9px] font-black text-slate-300 dark:text-slate-600 uppercase tracking-widest">Pending</span>
            </div>
            <div>
                <h3 class="text-2xl font-black <?php echo $pendingMaint > 5 ? 'text-red-500' : ($pendingMaint > 0 ? 'text-orange-500' : 'text-green-500'); ?>"><?php echo $pendingMaint; ?></h3>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mt-0.5">Maintenance</p>
            </div>
        </div>

        <!-- Overdue -->
        <div class="glass-card stat-card p-5 flex flex-col gap-2 hover:-translate-y-0.5 transition-transform cursor-pointer" onclick="window.location='tenant_payments.php?filter=overdue'">
            <div class="flex items-center justify-between">
                <div class="w-9 h-9 rounded-xl <?php echo $overdueInvoices > 0 ? 'bg-red-50 dark:bg-red-900/30 text-red-500' : 'bg-green-50 dark:bg-green-900/30 text-green-500'; ?> flex items-center justify-center">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                </div>
                <?php if ($overdueTenants > 0): ?>
                <span class="text-[9px] font-black text-red-400 uppercase tracking-widest"><?php echo $overdueTenants; ?> ten.</span>
                <?php endif; ?>
            </div>
            <div>
                <h3 class="text-2xl font-black <?php echo $overdueInvoices > 0 ? 'text-red-500' : 'text-green-500'; ?>"><?php echo $overdueInvoices; ?></h3>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mt-0.5">Overdue</p>
            </div>
        </div>

    </div>

    <!-- ── Overdue Alert ──────────────────────────────────── -->
    <?php if ($overdueInvoices > 0): ?>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-4 bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-800/30 rounded-2xl">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-red-100 dark:bg-red-900/30 rounded-xl flex items-center justify-center text-red-500 shrink-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
            </div>
            <div>
                <p class="text-sm font-black text-red-700 dark:text-red-400">
                    <?php echo $overdueInvoices; ?> Overdue Invoice<?php echo $overdueInvoices !== 1 ? 's' : ''; ?> — <?php echo $overdueTenants; ?> Tenant<?php echo $overdueTenants !== 1 ? 's' : ''; ?> Affected
                </p>
                <p class="text-[10px] text-red-500 mt-0.5">Past due dates. Outstanding: <?php echo $currency; ?> <?php echo number_format($outstanding); ?></p>
            </div>
        </div>
        <div class="flex gap-2 shrink-0">
            <a href="reports.php?tab=aging" class="px-4 py-2 bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 rounded-xl text-xs font-black whitespace-nowrap hover:bg-red-200 transition-colors">View Aging →</a>
            <a href="tenant_payments.php?filter=overdue" class="px-4 py-2 bg-red-500 text-white rounded-xl text-xs font-black whitespace-nowrap hover:bg-red-600 transition-colors">Manage Overdue →</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Main Grid ─────────────────────────────────────── -->
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

        <!-- LEFT: Chart + Recent Activity -->
        <div class="xl:col-span-2 space-y-6">

            <!-- Revenue vs Invoiced Chart -->
            <div class="glass-card p-6 lg:p-8">
                <div class="flex items-start justify-between mb-6 gap-4">
                    <div>
                        <h3 class="text-lg font-black text-slate-900 dark:text-white">Revenue vs Invoiced</h3>
                        <p class="text-xs text-slate-400 font-medium mt-0.5">6-month collection performance</p>
                    </div>
                    <div class="flex items-center gap-4 shrink-0">
                        <div class="flex items-center gap-1.5 text-[10px] font-bold text-slate-400">
                            <span class="w-3 h-1 rounded-full bg-accent-green"></span>Collected
                        </div>
                        <div class="flex items-center gap-1.5 text-[10px] font-bold text-slate-400">
                            <span class="w-3 h-1 rounded-full bg-blue-400"></span>Invoiced
                        </div>
                        <div class="flex items-center gap-2 px-3 py-1 bg-green-50 dark:bg-green-900/20 rounded-xl">
                            <span class="text-[10px] font-black text-green-600">YTD <?php echo $currency; ?> <?php echo number_format($revYTD); ?></span>
                        </div>
                    </div>
                </div>
                <div style="height:200px;"><canvas id="revenueChart"></canvas></div>
            </div>

            <!-- Recent Payments + Maintenance grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                <!-- Recent Payments -->
                <div class="glass-card">
                    <div class="flex justify-between items-center p-6 pb-4">
                        <h3 class="font-black text-slate-900 dark:text-white">Recent Payments</h3>
                        <a href="financials.php" class="text-[10px] font-black text-slate-400 hover:text-accent-green transition-colors uppercase tracking-widest">All →</a>
                    </div>
                    <?php if (empty($recentTransactions)): ?>
                    <div class="empty-state pb-8">
                        <div class="empty-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
                        <p class="text-sm text-slate-400">No payments recorded yet</p>
                    </div>
                    <?php else: ?>
                    <div class="divide-y divide-slate-100 dark:divide-slate-800">
                        <?php foreach ($recentTransactions as $tx): ?>
                        <div class="flex items-center gap-3 px-6 py-3.5 hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                            <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0 font-black text-xs
                                <?php echo $tx['status'] === 'Paid' ? 'bg-green-100 dark:bg-green-900/30 text-green-600' : 'bg-orange-100 dark:bg-orange-900/30 text-orange-500'; ?>">
                                <?php echo strtoupper(substr($tx['full_name'], 0, 1)); ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold truncate text-slate-900 dark:text-white"><?php echo htmlspecialchars($tx['full_name']); ?></p>
                                <p class="text-[10px] text-slate-400 font-medium"><?php echo date('d M Y', strtotime($tx['transaction_date'])); ?> · <?php echo $tx['transaction_type'] ?? 'Payment'; ?></p>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="text-sm font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($tx['amount']); ?></p>
                                <span class="badge <?php echo $tx['status'] === 'Paid' ? 'badge-green' : ($tx['status'] === 'Overdue' ? 'badge-red' : 'badge-orange'); ?> text-[9px]"><?php echo $tx['status']; ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Maintenance -->
                <div class="glass-card">
                    <div class="flex justify-between items-center p-6 pb-4">
                        <h3 class="font-black text-slate-900 dark:text-white">Maintenance</h3>
                        <a href="maintenance.php" class="text-[10px] font-black text-slate-400 hover:text-accent-green transition-colors uppercase tracking-widest">All →</a>
                    </div>
                    <?php if (empty($recentMaint)): ?>
                    <div class="empty-state pb-8">
                        <div class="empty-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></div>
                        <p class="text-sm text-slate-400">No maintenance requests</p>
                    </div>
                    <?php else: ?>
                    <div class="divide-y divide-slate-100 dark:divide-slate-800">
                        <?php foreach ($recentMaint as $req): ?>
                        <div class="flex items-start gap-3 px-6 py-3.5 hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                            <span class="w-2 h-2 rounded-full mt-2 shrink-0 <?php echo $req['status'] === 'Completed' ? 'bg-green-500' : ($req['status'] === 'In Progress' ? 'bg-blue-500' : ($req['priority'] === 'High' ? 'bg-red-500' : 'bg-orange-500')); ?>"></span>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-slate-900 dark:text-white truncate"><?php echo htmlspecialchars($req['title']); ?></p>
                                <p class="text-[10px] text-slate-400 font-medium">
                                    <?php echo htmlspecialchars($req['tenant_name'] ?? 'Unknown'); ?>
                                    <?php if ($req['prop_title']): ?> · <?php echo htmlspecialchars($req['prop_title']); ?><?php endif; ?>
                                </p>
                            </div>
                            <span class="text-[9px] font-black uppercase px-2 py-0.5 rounded-full shrink-0
                                <?php echo $req['status'] === 'Completed' ? 'bg-green-100 text-green-600 dark:bg-green-900/30' : ($req['status'] === 'In Progress' ? 'bg-blue-100 text-blue-600 dark:bg-blue-900/30' : 'bg-orange-100 text-orange-600 dark:bg-orange-900/30'); ?>">
                                <?php echo $req['status']; ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- end left col -->

        <!-- RIGHT: Quick Actions + Expiring Leases + Stats -->
        <div class="space-y-6">

            <!-- Quick Actions -->
            <div class="glass-card p-6">
                <h3 class="font-black text-slate-900 dark:text-white mb-4 text-sm">Quick Actions</h3>
                <div class="space-y-1.5">
                    <?php
                    $quickActions = [
                        ['href' => 'properties.php',          'label' => 'Add New Property',    'icon_bg' => 'bg-blue-50 dark:bg-blue-900/30 text-blue-500',   'icon' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/></svg>'],
                        ['href' => 'tenants.php?action=new',  'label' => 'Register Tenant',     'icon_bg' => 'bg-green-50 dark:bg-green-900/30 text-green-500', 'icon' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>'],
                        ['href' => 'leases.php?action=new',   'label' => 'Create Lease',        'icon_bg' => 'bg-purple-50 dark:bg-purple-900/30 text-purple-500','icon' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'],
                        ['href' => 'financials.php',          'label' => 'Record Payment',      'icon_bg' => 'bg-orange-50 dark:bg-orange-900/30 text-orange-500','icon' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>'],
                        ['href' => 'tenant_payments.php',     'label' => 'Generate Invoice',    'icon_bg' => 'bg-yellow-50 dark:bg-yellow-900/30 text-yellow-600','icon' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/></svg>'],
                        ['href' => 'reports.php',             'label' => 'View Reports',        'icon_bg' => 'bg-slate-100 dark:bg-slate-800 text-slate-500',    'icon' => '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/></svg>'],
                    ];
                    foreach ($quickActions as $qa): ?>
                    <a href="<?php echo $qa['href']; ?>" class="flex items-center gap-3 p-3 bg-slate-50 dark:bg-slate-800/40 rounded-xl text-sm font-bold text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 hover:text-accent-green transition-all">
                        <span class="w-8 h-8 rounded-lg <?php echo $qa['icon_bg']; ?> flex items-center justify-center shrink-0">
                            <?php echo $qa['icon']; ?>
                        </span>
                        <?php echo $qa['label']; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Expiring Leases / Vacancy Forecast -->
            <div class="glass-card overflow-hidden">
                <div class="flex justify-between items-center p-5 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h3 class="font-black text-slate-900 dark:text-white text-sm">Expiring Leases</h3>
                        <p class="text-[10px] text-slate-400 font-medium">Next 60 days
                            <?php if (!empty($expiringLeases)): ?>
                            — <span class="<?php echo count(array_filter($expiringLeases, fn($e) => (int)$e['days_left'] <= 14)) > 0 ? 'text-red-500 font-black' : 'text-slate-400'; ?>"><?php echo count($expiringLeases); ?> lease<?php echo count($expiringLeases) !== 1 ? 's' : ''; ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <a href="vacancies.php" class="text-[10px] font-black text-accent-green hover:opacity-70 transition-opacity uppercase tracking-widest">Full Forecast →</a>
                </div>
                <?php if (!empty($expiringLeases)): ?>
                <div class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($expiringLeases as $el):
                        $d = (int)$el['days_left'];
                        $urgencyClass = $d <= 14 ? 'urgency-critical' : ($d <= 30 ? 'urgency-warning' : 'urgency-normal');
                        $rs = $el['renewal_status'] ?? null;
                    ?>
                    <div class="flex items-center gap-3 px-5 py-3 hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                        <div class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-xs font-black text-slate-600 dark:text-slate-300 shrink-0">
                            <?php echo strtoupper(substr($el['full_name'], 0, 1)); ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <a href="tenant_details.php?id=<?php echo $el['tenant_id']; ?>" class="text-xs font-bold text-slate-900 dark:text-white truncate hover:text-accent-green transition-colors"><?php echo htmlspecialchars($el['full_name']); ?></a>
                            <p class="text-[10px] text-slate-400 truncate">Unit <?php echo htmlspecialchars($el['unit_number']); ?> · <?php echo htmlspecialchars($el['prop_title']); ?></p>
                        </div>
                        <div class="flex items-center gap-1.5 shrink-0">
                            <?php if ($rs === 'Offered'): ?>
                            <span class="px-1.5 py-0.5 rounded text-[8px] font-black bg-blue-100 text-blue-600 dark:bg-blue-900/30 uppercase">Offered</span>
                            <?php elseif ($rs === 'Accepted'): ?>
                            <span class="px-1.5 py-0.5 rounded text-[8px] font-black bg-green-100 text-green-600 dark:bg-green-900/30 uppercase">Accepted</span>
                            <?php elseif ($rs === 'Declined'): ?>
                            <span class="px-1.5 py-0.5 rounded text-[8px] font-black bg-red-100 text-red-500 dark:bg-red-900/30 uppercase">Declined</span>
                            <?php endif; ?>
                            <span class="px-2 py-0.5 rounded-full text-[9px] font-black border <?php echo $urgencyClass; ?>">
                                <?php echo $d; ?>d
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="p-3 border-t border-slate-100 dark:border-slate-800">
                    <a href="vacancies.php" class="flex items-center justify-center gap-2 w-full py-2 bg-slate-50 dark:bg-slate-800/60 rounded-xl text-[11px] font-black text-slate-500 hover:text-accent-green hover:bg-slate-100 dark:hover:bg-slate-800 transition-all uppercase tracking-widest">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/></svg>
                        View Vacancy Forecast
                    </a>
                </div>
                <?php else: ?>
                <div class="p-6 text-center">
                    <svg class="mx-auto text-green-200 dark:text-green-900 mb-2" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="20 6 9 17 4 12"/></svg>
                    <p class="text-slate-400 font-bold text-xs">No leases expiring in the next 60 days.</p>
                    <a href="vacancies.php" class="mt-3 inline-block text-[11px] font-black text-accent-green hover:opacity-70 uppercase tracking-widest">Vacancy Forecast →</a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Top Debtors -->
            <?php if (!empty($topDebtors)): ?>
            <div class="glass-card overflow-hidden">
                <div class="flex justify-between items-center p-5 border-b border-slate-100 dark:border-slate-800">
                    <div>
                        <h3 class="font-black text-slate-900 dark:text-white text-sm">Top Debtors</h3>
                        <p class="text-[10px] text-slate-400 font-medium">Highest outstanding balances</p>
                    </div>
                    <a href="tenant_payments.php?filter=overdue" class="text-[10px] font-black text-accent-green hover:opacity-70 uppercase tracking-widest">All →</a>
                </div>
                <div class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($topDebtors as $deb): ?>
                    <div class="flex items-center gap-3 px-5 py-3 hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-colors">
                        <div class="w-8 h-8 rounded-lg bg-red-50 dark:bg-red-900/20 flex items-center justify-center text-red-500 font-black text-xs shrink-0">
                            <?php echo strtoupper(substr($deb['full_name'], 0, 1)); ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <a href="tenant_details.php?id=<?php echo $deb['id']; ?>" class="text-xs font-bold text-slate-900 dark:text-white truncate hover:text-accent-green transition-colors block"><?php echo htmlspecialchars($deb['full_name']); ?></a>
                            <p class="text-[10px] text-slate-400 truncate"><?php echo htmlspecialchars(($deb['prop_title'] ?? '') . ($deb['unit_number'] ? ' · Unit ' . $deb['unit_number'] : '')); ?></p>
                        </div>
                        <p class="text-xs font-black text-red-500 shrink-0"><?php echo $currency; ?> <?php echo number_format($deb['total_outstanding']); ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Portfolio Health -->
            <div class="glass-card p-6 bg-slate-900 dark:bg-slate-800 border-none text-white">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-black text-white text-sm">Portfolio Health</h3>
                    <span class="text-2xl font-black <?php echo $healthColor; ?>"><?php echo $healthScore; ?></span>
                </div>
                <div class="health-pips mb-3">
                    <?php for ($pip = 1; $pip <= 10; $pip++):
                        $filled = ($pip * 10) <= $healthScore;
                        $pipClass = $filled ? ($healthScore >= 80 ? 'lit-green' : ($healthScore >= 60 ? 'lit-yellow' : 'lit-red')) : '';
                    ?>
                    <div class="health-pip <?php echo $pipClass; ?>"></div>
                    <?php endfor; ?>
                </div>
                <p class="text-[10px] text-slate-400 font-medium leading-relaxed">
                    <?php echo $healthLabel; ?> — Based on occupancy (<?php echo $occupancyRate; ?>%), overdue invoices (<?php echo $overdueInvoices; ?>), maintenance queue (<?php echo $pendingMaint; ?>), and collection rate.
                </p>
                <a href="reports.php" class="mt-4 flex items-center gap-2 text-[10px] font-black text-accent-green hover:opacity-80 transition-opacity uppercase tracking-widest">
                    View Full Report →
                </a>
            </div>

        </div><!-- end right col -->
    </div><!-- end main grid -->

</div>

<!-- Revenue Chart Script -->
<script>
(function() {
    const ctx = document.getElementById('revenueChart');
    if (!ctx) return;
    const isDark = document.documentElement.classList.contains('dark');
    const gridColor = isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.04)';
    const tickColor = '#94a3b8';
    const allData = [...<?php echo json_encode($chartCollected); ?>, ...<?php echo json_encode($chartInvoiced); ?>];
    const dataMax = Math.max(...allData, 0);
    const suggestedMax = dataMax > 0 ? undefined : 10000;

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chartLabels); ?>,
            datasets: [
                {
                    label: 'Collected',
                    data: <?php echo json_encode($chartCollected); ?>,
                    backgroundColor: 'rgba(34,197,94,0.85)',
                    borderRadius: 6,
                    borderSkipped: false,
                    order: 2,
                },
                {
                    label: 'Invoiced',
                    type: 'line',
                    data: <?php echo json_encode($chartInvoiced); ?>,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59,130,246,0.05)',
                    fill: true,
                    tension: 0.45,
                    borderWidth: 2,
                    pointBackgroundColor: '#3b82f6',
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    order: 1,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: isDark ? '#0f172a' : '#fff',
                    titleColor: isDark ? '#f8fafc' : '#0f172a',
                    bodyColor: '#94a3b8',
                    borderColor: isDark ? '#1e293b' : '#e2e8f0',
                    borderWidth: 1,
                    padding: 12,
                    callbacks: {
                        label: c => `${c.dataset.label}: <?php echo $currency; ?> ${c.raw.toLocaleString()}`
                    }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: tickColor, font: { size: 11, weight: '700' } } },
                y: {
                    beginAtZero: true,
                    min: 0,
                    suggestedMax: suggestedMax,
                    grid: { color: gridColor },
                    ticks: {
                        color: tickColor,
                        font: { size: 11 },
                        callback: v => {
                            if (v === 0) return '<?php echo $currency; ?> 0';
                            if (v >= 1000000) return '<?php echo $currency; ?> ' + (v/1000000).toFixed(1) + 'M';
                            if (v >= 1000) return '<?php echo $currency; ?> ' + (v/1000).toFixed(v >= 10000 ? 0 : 1) + 'K';
                            return '<?php echo $currency; ?> ' + v.toLocaleString();
                        }
                    }
                }
            }
        }
    });
})();
</script>

<!-- New Property Modal -->
<div class="modal-overlay" id="newPropertyModal" style="display:none;">
    <div class="modal-card max-w-2xl">
        <button onclick="closeModal('newPropertyModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-900 dark:hover:text-white transition-all hover:rotate-90 transform">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-6">Add New Property</h2>
        <form action="actions/property_actions.php" method="POST" enctype="multipart/form-data" class="space-y-5">
            <input type="hidden" name="action" value="create">
            <div class="space-y-2">
                <label class="form-label">Property Title *</label>
                <input type="text" name="title" required placeholder="E.g. Primelink Heights" class="w-full px-4 py-3.5 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label class="form-label">Location *</label>
                    <input type="text" name="location" required placeholder="E.g. Nairobi, Kilimani" class="w-full px-4 py-3.5 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                </div>
                <div class="space-y-2">
                    <label class="form-label">Property Code</label>
                    <input type="text" name="property_code" placeholder="E.g. PL-001" class="w-full px-4 py-3.5 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label class="form-label">Type</label>
                    <select name="property_type" class="w-full px-4 py-3.5 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        <option>Apartment</option><option>Villa</option><option>Office</option><option>Commercial</option><option>Shop</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="form-label">Area (Sqm)</label>
                    <input type="number" name="area" placeholder="E.g. 150" class="w-full px-4 py-3.5 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                </div>
            </div>
            <div class="space-y-2">
                <label class="form-label">Description</label>
                <textarea name="description" rows="2" class="w-full px-4 py-3.5 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none resize-none"></textarea>
            </div>
            <div class="space-y-2">
                <label class="form-label">Property Images</label>
                <input type="file" name="property_images[]" multiple class="w-full p-4 border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-2xl text-xs text-slate-400 font-bold hover:border-accent-green transition-all cursor-pointer">
            </div>
            <button type="submit" class="btn-green w-full justify-center py-4 rounded-2xl text-sm font-black tracking-wide">
                Register Property →
            </button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
