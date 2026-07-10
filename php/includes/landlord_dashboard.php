<?php
/**
 * Landlord Dashboard — full portfolio view
 * Included from dashboard.php when role === 'landlord'
 */

require_once __DIR__ . '/settings.php';

$landlordId = getLandlordId($pdo);

if (!$landlordId) { ?>
<div class="glass-card p-16 text-center animate-in">
    <div class="w-16 h-16 bg-slate-100 dark:bg-slate-800 rounded-full flex items-center justify-center mx-auto mb-4">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-slate-300"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
    </div>
    <p class="text-xl font-black text-slate-900 dark:text-white mb-2">Account Setup Pending</p>
    <p class="text-slate-400 text-sm">Your landlord profile is being configured by the admin. Please check back soon.</p>
</div>
<?php return; }

$currency   = getSetting($pdo, 'currency_symbol', 'KSh');

$llFeeStmt = $pdo->prepare("SELECT management_fee, fee_type FROM landlords WHERE id = ?");
$llFeeStmt->execute([$landlordId]);
$llFeeRow   = $llFeeStmt->fetch();
$feeType    = $llFeeRow['fee_type']    ?? 'percentage';
$feeSetting = (float)($llFeeRow['management_fee'] ?? 10.0);

// ── KPI queries ────────────────────────────────────────────────────
$totalProps = $pdo->prepare("SELECT COUNT(*) FROM properties WHERE landlord_id = ?");
$totalProps->execute([$landlordId]);
$totalProps = (int)$totalProps->fetchColumn();

$occupiedUnits = $pdo->prepare("
    SELECT COUNT(*) FROM units u JOIN properties p ON u.property_id = p.id
    WHERE p.landlord_id = ? AND u.status = 'Occupied'");
$occupiedUnits->execute([$landlordId]);
$occupied = (int)$occupiedUnits->fetchColumn();

$totalUnits = $pdo->prepare("
    SELECT COUNT(*) FROM units u JOIN properties p ON u.property_id = p.id
    WHERE p.landlord_id = ?");
$totalUnits->execute([$landlordId]);
$totalUnitsCount = (int)$totalUnits->fetchColumn();
$vacant       = $totalUnitsCount - $occupied;
$occupancyPct = $totalUnitsCount > 0 ? round(($occupied / $totalUnitsCount) * 100) : 0;

$monthlyIncome = $pdo->prepare("
    SELECT COALESCE(SUM(tr.amount), 0)
    FROM transactions tr
    JOIN tenants t ON tr.tenant_id = t.id
    JOIN leases ls ON ls.tenant_id = t.id AND ls.status='Active'
    JOIN units u ON ls.unit_id = u.id
    JOIN properties p ON u.property_id = p.id
    WHERE p.landlord_id = ? AND tr.status='Paid'
      AND MONTH(tr.transaction_date)=MONTH(CURDATE())
      AND YEAR(tr.transaction_date)=YEAR(CURDATE())");
$monthlyIncome->execute([$landlordId]);
$income = (float)$monthlyIncome->fetchColumn();

$ytdStmt = $pdo->prepare("
    SELECT COALESCE(SUM(tr.amount), 0)
    FROM transactions tr
    JOIN tenants t ON tr.tenant_id = t.id
    JOIN leases ls ON ls.tenant_id = t.id AND ls.status='Active'
    JOIN units u ON ls.unit_id = u.id
    JOIN properties p ON u.property_id = p.id
    WHERE p.landlord_id = ? AND tr.status='Paid'
      AND YEAR(tr.transaction_date)=YEAR(CURDATE())");
$ytdStmt->execute([$landlordId]);
$ytdIncome = (float)$ytdStmt->fetchColumn();

$overdueStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT i.tenant_id)
    FROM invoices i
    JOIN tenants t ON i.tenant_id = t.id
    JOIN leases ls ON ls.tenant_id = t.id AND ls.status='Active'
    JOIN units u ON ls.unit_id = u.id
    JOIN properties p ON u.property_id = p.id
    WHERE p.landlord_id = ? AND i.status='Overdue'");
$overdueStmt->execute([$landlordId]);
$overdueTenantsCount = (int)$overdueStmt->fetchColumn();

$totalPaidOutStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM landlord_payouts WHERE landlord_id=?");
$totalPaidOutStmt->execute([$landlordId]);
$totalPaidOut = (float)$totalPaidOutStmt->fetchColumn();

// ── Revenue chart (last 6 months) ─────────────────────────────────
$chartLabels = [];
$chartData   = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('m', strtotime("-$i months"));
    $y = date('Y', strtotime("-$i months"));
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(tr.amount),0)
        FROM transactions tr
        JOIN tenants t ON tr.tenant_id=t.id
        JOIN leases ls ON ls.tenant_id=t.id AND ls.status='Active'
        JOIN units u ON ls.unit_id=u.id
        JOIN properties p ON u.property_id=p.id
        WHERE p.landlord_id=? AND tr.status='Paid'
          AND MONTH(tr.transaction_date)=? AND YEAR(tr.transaction_date)=?");
    $stmt->execute([$landlordId, $m, $y]);
    $chartLabels[] = date('M', strtotime("-$i months"));
    $chartData[]   = (float)$stmt->fetchColumn();
}

// ── Per-property performance ───────────────────────────────────────
$propStmt = $pdo->prepare("
    SELECT p.*,
           COUNT(DISTINCT u.id) as total_units,
           SUM(CASE WHEN u.status='Occupied' THEN 1 ELSE 0 END) as occ_units,
           SUM(CASE WHEN u.status='Available' THEN 1 ELSE 0 END) as vac_units,
           (SELECT COALESCE(SUM(tr.amount),0)
            FROM transactions tr
            JOIN tenants t2 ON tr.tenant_id=t2.id
            JOIN leases ls2 ON ls2.tenant_id=t2.id AND ls2.status='Active'
            JOIN units u2 ON ls2.unit_id=u2.id
            WHERE u2.property_id=p.id AND tr.status='Paid'
              AND MONTH(tr.transaction_date)=MONTH(CURDATE())
              AND YEAR(tr.transaction_date)=YEAR(CURDATE())
           ) as mtd_income,
           (SELECT COUNT(*)
            FROM invoices i
            JOIN tenants t3 ON i.tenant_id=t3.id
            JOIN leases ls3 ON ls3.tenant_id=t3.id AND ls3.status='Active'
            JOIN units u3 ON ls3.unit_id=u3.id
            WHERE u3.property_id=p.id AND i.status='Overdue'
           ) as overdue_count
    FROM properties p
    LEFT JOIN units u ON u.property_id=p.id
    WHERE p.landlord_id=?
    GROUP BY p.id
    ORDER BY p.created_at DESC");
$propStmt->execute([$landlordId]);
$properties = $propStmt->fetchAll();

// ── Recent maintenance ─────────────────────────────────────────────
$maintStmt = $pdo->prepare("
    SELECT m.*, p.title as property_title
    FROM maintenance_requests m
    JOIN properties p ON m.property_id=p.id
    WHERE p.landlord_id=?
    ORDER BY m.created_at DESC LIMIT 5");
$maintStmt->execute([$landlordId]);
$maintenance = $maintStmt->fetchAll();

// ── Recent transactions ────────────────────────────────────────────
$txStmt = $pdo->prepare("
    SELECT tr.*, t.full_name as tenant_name, p.title as property_title, u.unit_number
    FROM transactions tr
    JOIN tenants t ON tr.tenant_id=t.id
    JOIN leases ls ON ls.tenant_id=t.id AND ls.status='Active'
    JOIN units u ON ls.unit_id=u.id
    JOIN properties p ON u.property_id=p.id
    WHERE p.landlord_id=? AND tr.status='Paid'
    ORDER BY tr.transaction_date DESC LIMIT 8");
$txStmt->execute([$landlordId]);
$transactions = $txStmt->fetchAll();

// ── Payout history ─────────────────────────────────────────────────
$payoutStmt = $pdo->prepare("
    SELECT * FROM landlord_payouts WHERE landlord_id=? ORDER BY payout_date DESC LIMIT 6");
$payoutStmt->execute([$landlordId]);
$payouts = $payoutStmt->fetchAll();
$lastPayout = $payouts[0] ?? null;
?>

<div class="space-y-8 animate-in">

    <!-- Greeting + Actions -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
        <div>
            <h1 class="text-2xl lg:text-3xl font-black text-slate-900 dark:text-white tracking-tight">
                Good <?php $h=(int)date('H'); echo $h<12?'Morning':($h<17?'Afternoon':'Evening'); ?>, <?php echo htmlspecialchars(explode(' ',$user['full_name'])[0]); ?> 👋
            </h1>
            <p class="text-slate-500 dark:text-slate-400 font-medium text-sm mt-0.5"><?php echo date('l, F j, Y'); ?> — Your portfolio overview</p>
        </div>
        <div class="flex gap-3">
            <a href="landlord_tenants.php" class="px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl font-bold text-xs hover:shadow-md transition-all flex items-center gap-2">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                My Tenants
            </a>
            <a href="landlord_statement.php" class="btn-primary text-xs gap-2">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
                Portfolio Statement
            </a>
        </div>
    </div>

    <!-- KPI Row -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="glass-card p-5 flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-900/20 text-amber-500 flex items-center justify-center shrink-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
            </div>
            <div class="min-w-0">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest truncate">Properties</p>
                <h3 class="text-2xl font-black text-slate-900 dark:text-white"><?php echo $totalProps; ?></h3>
            </div>
        </div>
        <div class="glass-card p-5 flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-green-50 dark:bg-green-900/20 text-green-500 flex items-center justify-center shrink-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>
            </div>
            <div class="min-w-0">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest truncate">Occupancy</p>
                <h3 class="text-2xl font-black text-green-500"><?php echo $occupancyPct; ?>%</h3>
                <p class="text-[9px] text-slate-400"><?php echo $occupied; ?>/<?php echo $totalUnitsCount; ?> units</p>
            </div>
        </div>
        <div class="glass-card p-5 flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-900/20 text-blue-500 flex items-center justify-center shrink-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <div class="min-w-0">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest truncate">Income (MTD)</p>
                <h3 class="text-xl font-black text-blue-500"><?php echo $currency; ?> <?php echo number_format($income); ?></h3>
            </div>
        </div>
        <div class="glass-card p-5 flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-purple-50 dark:bg-purple-900/20 text-purple-500 flex items-center justify-center shrink-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
            </div>
            <div class="min-w-0">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest truncate">YTD Income</p>
                <h3 class="text-xl font-black text-purple-500"><?php echo $currency; ?> <?php echo number_format($ytdIncome); ?></h3>
            </div>
        </div>
        <?php if ($overdueTenantsCount > 0): ?>
        <div class="glass-card p-5 flex items-center gap-3 border border-red-200 dark:border-red-800/30">
            <div class="w-10 h-10 rounded-xl bg-red-50 dark:bg-red-900/20 text-red-500 flex items-center justify-center shrink-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
            </div>
            <div class="min-w-0">
                <p class="text-[9px] font-black text-red-400 uppercase tracking-widest truncate">Overdue</p>
                <h3 class="text-2xl font-black text-red-500"><?php echo $overdueTenantsCount; ?></h3>
                <p class="text-[9px] text-red-400">tenant<?php echo $overdueTenantsCount!==1?'s':''; ?></p>
            </div>
        </div>
        <?php else: ?>
        <div class="glass-card p-5 flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-orange-50 dark:bg-orange-900/20 text-orange-500 flex items-center justify-center shrink-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/></svg>
            </div>
            <div class="min-w-0">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest truncate">Vacant Units</p>
                <h3 class="text-2xl font-black text-orange-500"><?php echo $vacant; ?></h3>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Chart + Payout Summary row -->
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <!-- Revenue Chart -->
        <div class="xl:col-span-2 glass-card p-6 lg:p-8">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h3 class="text-lg font-black text-slate-900 dark:text-white">Revenue Trend</h3>
                    <p class="text-xs text-slate-400 font-medium">Your portfolio income — last 6 months</p>
                </div>
                <span class="badge badge-primary">Live</span>
            </div>
            <div style="height:200px;"><canvas id="landlordRevenueChart"></canvas></div>
        </div>

        <!-- Payout Summary -->
        <div class="glass-card p-6">
            <h3 class="font-black text-slate-900 dark:text-white mb-5">Payout Summary</h3>
            <div class="space-y-4">
                <div class="p-4 bg-slate-50 dark:bg-slate-800/50 rounded-2xl">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">YTD Gross Income</p>
                    <p class="text-lg font-black text-slate-900 dark:text-white mt-1"><?php echo $currency; ?> <?php echo number_format($ytdIncome); ?></p>
                </div>
                <?php
                $ytdFee = $feeType === 'fixed' ? $feeSetting : round($ytdIncome * $feeSetting / 100, 2);
                $ytdNet = $ytdIncome - $ytdFee;
                $feeLabel = $feeType === 'fixed' ? 'Fixed' : $feeSetting . '%';
                ?>
                <div class="p-4 bg-slate-50 dark:bg-slate-800/50 rounded-2xl">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Mgmt Fee (<?php echo $feeLabel; ?>)</p>
                    <p class="text-lg font-black text-red-500 mt-1">- <?php echo $currency; ?> <?php echo number_format($ytdFee); ?></p>
                </div>
                <div class="p-4 bg-accent-green/5 border border-accent-green/20 rounded-2xl">
                    <p class="text-[9px] font-black text-accent-green uppercase tracking-widest">Net (After Fee)</p>
                    <p class="text-lg font-black text-accent-green mt-1"><?php echo $currency; ?> <?php echo number_format($ytdNet); ?></p>
                </div>
                <div class="p-4 bg-slate-50 dark:bg-slate-800/50 rounded-2xl flex justify-between items-center">
                    <div>
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Total Paid Out</p>
                        <p class="text-sm font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($totalPaidOut); ?></p>
                    </div>
                    <?php if ($lastPayout): ?>
                    <p class="text-[9px] text-slate-400 font-bold"><?php echo date('M d, Y', strtotime($lastPayout['payout_date'])); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <a href="landlord_statement.php" class="mt-4 flex items-center justify-center gap-2 w-full py-3 bg-slate-900 dark:bg-white text-white dark:text-slate-900 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:opacity-90 transition-all">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                View Full Statement
            </a>
        </div>
    </div>

    <!-- Per-Property Performance -->
    <?php if (!empty($properties)): ?>
    <div class="space-y-4">
        <h3 class="text-lg font-black text-slate-900 dark:text-white">Property Performance</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
            <?php foreach ($properties as $prop):
                $propOccPct = $prop['total_units'] > 0 ? round(($prop['occ_units'] / $prop['total_units']) * 100) : 0;
            ?>
            <div class="glass-card p-6 <?php echo $prop['overdue_count'] > 0 ? 'border border-red-200 dark:border-red-800/30' : ''; ?>">
                <div class="flex justify-between items-start mb-4">
                    <div class="flex-1 min-w-0 mr-3">
                        <p class="font-black text-sm text-slate-900 dark:text-white truncate"><?php echo htmlspecialchars($prop['title']); ?></p>
                        <p class="text-[10px] text-slate-400 mt-0.5"><?php echo htmlspecialchars($prop['location']); ?></p>
                    </div>
                    <?php if ($prop['overdue_count'] > 0): ?>
                    <span class="px-2 py-0.5 bg-red-500/10 text-red-500 rounded-full text-[9px] font-black border border-red-500/20 whitespace-nowrap shrink-0">
                        <?php echo $prop['overdue_count']; ?> overdue
                    </span>
                    <?php else: ?>
                    <span class="px-2 py-0.5 bg-green-500/10 text-green-500 rounded-full text-[9px] font-black border border-green-500/20 shrink-0">On Track</span>
                    <?php endif; ?>
                </div>

                <!-- Occupancy bar -->
                <div class="mb-4">
                    <div class="flex justify-between text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1.5">
                        <span>Occupancy</span>
                        <span><?php echo $prop['occ_units']; ?>/<?php echo $prop['total_units']; ?> units &mdash; <?php echo $propOccPct; ?>%</span>
                    </div>
                    <div class="h-2 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                        <div class="h-full <?php echo $propOccPct >= 80 ? 'bg-green-500' : ($propOccPct >= 50 ? 'bg-orange-400' : 'bg-red-500'); ?> rounded-full transition-all" style="width:<?php echo $propOccPct; ?>%"></div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">MTD Income</p>
                        <p class="text-sm font-black text-slate-900 dark:text-white mt-0.5"><?php echo $currency; ?> <?php echo number_format($prop['mtd_income']); ?></p>
                    </div>
                    <div class="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Vacant</p>
                        <p class="text-sm font-black <?php echo $prop['vac_units'] > 0 ? 'text-orange-500' : 'text-accent-green'; ?> mt-0.5"><?php echo $prop['vac_units']; ?> unit<?php echo $prop['vac_units']!==1?'s':''; ?></p>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Bottom row: Recent Income + Maintenance + Payouts -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Recent Transactions -->
        <div class="lg:col-span-2 glass-card overflow-hidden">
            <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
                <h3 class="font-black text-slate-900 dark:text-white">Recent Income</h3>
                <a href="financials.php" class="text-[10px] text-accent-green font-black uppercase tracking-widest hover:opacity-70 transition-opacity">View All →</a>
            </div>
            <?php if (empty($transactions)): ?>
            <p class="p-8 text-center text-slate-400 text-sm italic">No transactions recorded yet.</p>
            <?php else: ?>
            <table class="w-full text-left border-collapse">
                <thead><tr class="bg-slate-50 dark:bg-slate-800/50">
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tenant</th>
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Type</th>
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount</th>
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Date</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($transactions as $tr): ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-colors">
                        <td class="p-4">
                            <p class="font-bold text-sm text-slate-900 dark:text-white"><?php echo htmlspecialchars($tr['tenant_name']); ?></p>
                            <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($tr['property_title']); ?> &mdash; <?php echo htmlspecialchars($tr['unit_number']); ?></p>
                        </td>
                        <td class="p-4 text-xs text-slate-500 font-medium"><?php echo htmlspecialchars($tr['transaction_type']); ?></td>
                        <td class="p-4 font-black text-sm text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($tr['amount']); ?></td>
                        <td class="p-4 text-xs text-slate-400 text-right font-medium"><?php echo date('M d, Y', strtotime($tr['transaction_date'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Maintenance + Payouts stacked -->
        <div class="space-y-6">
            <!-- Recent Maintenance -->
            <div class="glass-card overflow-hidden">
                <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
                    <h3 class="font-black text-slate-900 dark:text-white text-sm">Maintenance</h3>
                    <a href="maintenance.php" class="text-[10px] text-accent-green font-black uppercase tracking-widest">Manage →</a>
                </div>
                <div class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php if (empty($maintenance)): ?>
                    <p class="p-6 text-center text-slate-400 text-xs italic">No requests.</p>
                    <?php else: foreach ($maintenance as $req): ?>
                    <div class="p-4 flex items-center gap-3">
                        <span class="w-2 h-2 rounded-full shrink-0 <?php echo $req['status']=='Completed'?'bg-green-500':($req['status']=='In Progress'?'bg-blue-500':'bg-orange-500'); ?>"></span>
                        <div class="flex-1 min-w-0">
                            <p class="font-bold text-xs truncate text-slate-900 dark:text-white"><?php echo htmlspecialchars($req['title']); ?></p>
                            <p class="text-[9px] text-slate-400"><?php echo $req['status']; ?></p>
                        </div>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <!-- Payout History -->
            <div class="glass-card overflow-hidden">
                <div class="p-5 border-b border-slate-100 dark:border-slate-800">
                    <h3 class="font-black text-slate-900 dark:text-white text-sm">Payout History</h3>
                </div>
                <div class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php if (empty($payouts)): ?>
                    <p class="p-6 text-center text-slate-400 text-xs italic">No payouts recorded.</p>
                    <?php else: foreach ($payouts as $po): ?>
                    <div class="p-4 flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($po['amount']); ?></p>
                            <p class="text-[9px] text-slate-400"><?php echo date('M d, Y', strtotime($po['payout_date'])); ?></p>
                        </div>
                        <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase <?php echo $po['status']=='Completed'?'bg-green-500/10 text-green-500':'bg-orange-500/10 text-orange-500'; ?>">
                            <?php echo $po['status']; ?>
                        </span>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
(function(){
    const ctx = document.getElementById('landlordRevenueChart');
    if (!ctx) return;
    const isDark = document.documentElement.classList.contains('dark');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($chartLabels); ?>,
            datasets: [{
                label: 'Income',
                data: <?php echo json_encode($chartData); ?>,
                backgroundColor: 'rgba(34,197,94,0.15)',
                borderColor: '#22c55e',
                borderWidth: 2,
                borderRadius: 8,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: c => '<?php echo $currency; ?> ' + c.raw.toLocaleString() } }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 11, weight: '700' } } },
                y: { grid: { color: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.04)' },
                     ticks: { color: '#94a3b8', font: { size: 11 }, callback: v => '<?php echo $currency; ?> ' + v.toLocaleString() } }
            }
        }
    });
})();
</script>
