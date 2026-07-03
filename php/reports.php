<?php
/**
 * Financial Reports & Analytics
 * Primelink Management System — Phase 7B
 *
 * Tabs: Overview · Occupancy · Overdue Aging · Property Performance · Rent Roll
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);
require_once __DIR__ . '/includes/settings.php';

$pageTitle = "Reports & Analytics";
$user      = getCurrentUser($pdo);
$currency  = getSetting($pdo, 'currency_symbol', 'KSh');

$tab              = $_GET['tab']         ?? 'overview';
$selectedMonth    = str_pad($_GET['month']       ?? date('m'), 2, '0', STR_PAD_LEFT);
$selectedYear     = (int)($_GET['year']          ?? date('Y'));
$selectedProperty = $_GET['property_id'] ?? 'all';

$properties = $pdo->query("SELECT id, title FROM properties ORDER BY title")->fetchAll();

// ── CSV export (Rent Roll) ─────────────────────────────────────────
if ($tab === 'rent_roll' && isset($_GET['export']) && $_GET['export'] === 'csv') {
    $rrStmt = $pdo->prepare("
        SELECT t.full_name, p.title as property_title, u.unit_number,
               l.monthly_rent, l.start_date, l.end_date, l.status
        FROM leases l
        JOIN tenants t ON l.tenant_id = t.id
        JOIN units u ON l.unit_id = u.id
        JOIN properties p ON u.property_id = p.id
        WHERE l.status = 'Active'
        ORDER BY p.title, u.unit_number
    ");
    $rrStmt->execute();
    $rrRows = $rrStmt->fetchAll();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="rent_roll_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Tenant', 'Property', 'Unit', 'Monthly Rent', 'Lease Start', 'Lease End', 'Status']);
    foreach ($rrRows as $r) {
        fputcsv($out, [
            $r['full_name'], $r['property_title'], $r['unit_number'],
            $r['monthly_rent'], $r['start_date'], $r['end_date'], $r['status']
        ]);
    }
    fclose($out);
    exit();
}

// ── Tab: Overview ──────────────────────────────────────────────────
$gross = 0; $rentIncome = 0; $otherIncome = 0; $expenseTotal = 0;
$collectionRate = 0; $invoicedTotal = 0;

if ($tab === 'overview') {
    $pFilter = $selectedProperty !== 'all' ? " AND lease_id IN (SELECT id FROM leases WHERE property_id = ?)" : '';
    $incParams = [$selectedMonth, $selectedYear];
    if ($selectedProperty !== 'all') $incParams[] = $selectedProperty;

    $incStmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN transaction_type='Rent' AND status='Paid' THEN amount ELSE 0 END) as rent_income,
            SUM(CASE WHEN transaction_type!='Rent' AND status='Paid' THEN amount ELSE 0 END) as other_income
        FROM transactions
        WHERE DATE_FORMAT(transaction_date,'%m')=? AND DATE_FORMAT(transaction_date,'%Y')=?
        $pFilter");
    $incStmt->execute($incParams);
    $inc = $incStmt->fetch();
    $rentIncome  = (float)($inc['rent_income']  ?? 0);
    $otherIncome = (float)($inc['other_income'] ?? 0);
    $gross       = $rentIncome + $otherIncome;

    $exParams = [$selectedMonth, $selectedYear];
    if ($selectedProperty !== 'all') $exParams[] = $selectedProperty;
    $exFilter = $selectedProperty !== 'all' ? " AND property_id = ?" : '';
    $exStmt   = $pdo->prepare("SELECT SUM(amount) FROM expenses WHERE DATE_FORMAT(expense_date,'%m')=? AND DATE_FORMAT(expense_date,'%Y')=? $exFilter");
    $exStmt->execute($exParams);
    $expenseTotal = (float)($exStmt->fetchColumn() ?? 0);

    $invStmt = $pdo->prepare("
        SELECT SUM(amount) FROM invoices
        WHERE DATE_FORMAT(created_at,'%m')=? AND DATE_FORMAT(created_at,'%Y')=?
        $pFilter");
    $invStmt->execute($incParams);
    $invoicedTotal = (float)($invStmt->fetchColumn() ?? 0);
    $collectionRate = $invoicedTotal > 0 ? round(($gross / $invoicedTotal) * 100, 1) : 0;

    // 6-month collection trend for chart
    $collectionMonths = [];
    $collectionRates  = [];
    $collectedByMonth = [];
    for ($i = 5; $i >= 0; $i--) {
        $m = date('m', strtotime("-$i months"));
        $y = date('Y', strtotime("-$i months"));
        $cStmt = $pdo->prepare("SELECT SUM(amount) FROM transactions WHERE status='Paid' AND DATE_FORMAT(transaction_date,'%m')=? AND DATE_FORMAT(transaction_date,'%Y')=?");
        $cStmt->execute([$m, $y]);
        $collected = (float)$cStmt->fetchColumn();

        $iStmt = $pdo->prepare("SELECT SUM(amount) FROM invoices WHERE DATE_FORMAT(created_at,'%m')=? AND DATE_FORMAT(created_at,'%Y')=?");
        $iStmt->execute([$m, $y]);
        $invoiced = (float)$iStmt->fetchColumn();

        $collectionMonths[]  = date('M Y', strtotime("-$i months"));
        $collectedByMonth[]  = $collected;
        $collectionRates[]   = $invoiced > 0 ? round(($collected / $invoiced) * 100, 1) : 0;
    }
}

// ── Tab: Occupancy ─────────────────────────────────────────────────
$occupancyData = [];
if ($tab === 'occupancy') {
    $occStmt = $pdo->prepare("
        SELECT p.id, p.title, p.location,
               COUNT(u.id) as total_units,
               SUM(CASE WHEN u.status='Occupied'  THEN 1 ELSE 0 END) as occ,
               SUM(CASE WHEN u.status='Available' THEN 1 ELSE 0 END) as vac,
               COALESCE(AVG(CASE WHEN u.monthly_rent > 0 THEN u.monthly_rent END), 0) as avg_rent
        FROM properties p
        LEFT JOIN units u ON u.property_id = p.id
        " . ($selectedProperty !== 'all' ? "WHERE p.id = ?" : "") . "
        GROUP BY p.id ORDER BY p.title");
    $selectedProperty !== 'all' ? $occStmt->execute([$selectedProperty]) : $occStmt->execute();
    $occupancyData = $occStmt->fetchAll();

    $totalAllUnits = array_sum(array_column($occupancyData, 'total_units'));
    $totalOccUnits = array_sum(array_column($occupancyData, 'occ'));
    $portfolioOccPct = $totalAllUnits > 0 ? round(($totalOccUnits / $totalAllUnits) * 100, 1) : 0;
}

// ── Tab: Overdue Aging ─────────────────────────────────────────────
$agingBuckets = [
    '1–30 days'  => [],
    '31–60 days' => [],
    '61–90 days' => [],
    '90+ days'   => [],
];
if ($tab === 'aging') {
    $agingStmt = $pdo->prepare("
        SELECT i.id, i.amount, i.due_date, i.invoice_type,
               t.full_name as tenant_name,
               p.title as property_title, u.unit_number,
               DATEDIFF(CURDATE(), i.due_date) as days_overdue
        FROM invoices i
        JOIN tenants t ON i.tenant_id = t.id
        JOIN leases l ON l.tenant_id = t.id AND l.status='Active'
        JOIN units u ON l.unit_id = u.id
        JOIN properties p ON u.property_id = p.id
        WHERE i.status='Overdue'
        " . ($selectedProperty !== 'all' ? "AND p.id = ?" : "") . "
        ORDER BY days_overdue DESC");
    $selectedProperty !== 'all' ? $agingStmt->execute([$selectedProperty]) : $agingStmt->execute();
    $overdueRows = $agingStmt->fetchAll();

    foreach ($overdueRows as $row) {
        $d = (int)$row['days_overdue'];
        if ($d <= 30) $agingBuckets['1–30 days'][]  = $row;
        elseif ($d <= 60) $agingBuckets['31–60 days'][] = $row;
        elseif ($d <= 90) $agingBuckets['61–90 days'][] = $row;
        else              $agingBuckets['90+ days'][]   = $row;
    }
}

// ── Tab: Property Performance ──────────────────────────────────────
$perfData = [];
if ($tab === 'performance') {
    $perfStmt = $pdo->prepare("
        SELECT p.id, p.title,
               COUNT(DISTINCT u.id) as total_units,
               SUM(CASE WHEN u.status='Occupied' THEN 1 ELSE 0 END) as occ_units,
               COALESCE((
                   SELECT SUM(tr.amount)
                   FROM transactions tr
                   JOIN tenants t2 ON tr.tenant_id=t2.id
                   JOIN leases l2 ON l2.tenant_id=t2.id AND l2.status='Active'
                   JOIN units u2 ON l2.unit_id=u2.id
                   WHERE u2.property_id=p.id AND tr.status='Paid'
                     AND DATE_FORMAT(tr.transaction_date,'%m')=? AND DATE_FORMAT(tr.transaction_date,'%Y')=?
               ), 0) as mtd_income,
               COALESCE((
                   SELECT SUM(tr.amount)
                   FROM transactions tr
                   JOIN tenants t2 ON tr.tenant_id=t2.id
                   JOIN leases l2 ON l2.tenant_id=t2.id AND l2.status='Active'
                   JOIN units u2 ON l2.unit_id=u2.id
                   WHERE u2.property_id=p.id AND tr.status='Paid'
                     AND YEAR(tr.transaction_date)=?
               ), 0) as ytd_income,
               COALESCE((
                   SELECT SUM(e.amount)
                   FROM expenses e
                   WHERE e.property_id=p.id
                     AND DATE_FORMAT(e.expense_date,'%m')=? AND DATE_FORMAT(e.expense_date,'%Y')=?
               ), 0) as mtd_expenses,
               COALESCE((SELECT COUNT(*) FROM invoices i
                   JOIN tenants t3 ON i.tenant_id=t3.id
                   JOIN leases l3 ON l3.tenant_id=t3.id AND l3.status='Active'
                   JOIN units u3 ON l3.unit_id=u3.id
                   WHERE u3.property_id=p.id AND i.status='Overdue'
               ), 0) as overdue_count
        FROM properties p
        LEFT JOIN units u ON u.property_id=p.id
        GROUP BY p.id ORDER BY ytd_income DESC");
    $perfStmt->execute([$selectedMonth, $selectedYear, $selectedYear, $selectedMonth, $selectedYear]);
    $perfData = $perfStmt->fetchAll();
}

// ── Tab: Expenses ──────────────────────────────────────────────────
$expCatBreakdown = [];
$expMonthlyTrend = [];
$expTrendMonths  = [];
$expYTD          = 0;
$expMTD          = 0;
if ($tab === 'expenses') {
    $expFilter   = $selectedProperty !== 'all' ? " AND property_id = ?" : '';
    $expBaseArgs = $selectedProperty !== 'all' ? [$selectedProperty] : [];

    // YTD total
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE YEAR(expense_date)=? $expFilter");
    $stmt->execute(array_merge([$selectedYear], $expBaseArgs));
    $expYTD = (float)$stmt->fetchColumn();

    // MTD total
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE_FORMAT(expense_date,'%m')=? AND DATE_FORMAT(expense_date,'%Y')=? $expFilter");
    $stmt->execute(array_merge([$selectedMonth, $selectedYear], $expBaseArgs));
    $expMTD = (float)$stmt->fetchColumn();

    // Category breakdown (YTD)
    $catArgs = array_merge([$selectedYear], $expBaseArgs);
    $stmt = $pdo->prepare("SELECT category, SUM(amount) as total, COUNT(*) as cnt FROM expenses WHERE YEAR(expense_date)=? $expFilter GROUP BY category ORDER BY total DESC");
    $stmt->execute($catArgs);
    $expCatBreakdown = $stmt->fetchAll();

    // 6-month monthly trend
    for ($i = 5; $i >= 0; $i--) {
        $m = date('m', strtotime("-$i months"));
        $y = date('Y', strtotime("-$i months"));
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE_FORMAT(expense_date,'%m')=? AND DATE_FORMAT(expense_date,'%Y')=? $expFilter");
        $stmt->execute(array_merge([$m, $y], $expBaseArgs));
        $expTrendMonths[]  = date('M Y', strtotime("-$i months"));
        $expMonthlyTrend[] = (float)$stmt->fetchColumn();
    }
}

// ── Tab: Rent Roll ─────────────────────────────────────────────────
$rentRollData = [];
$totalRentRoll = 0;
if ($tab === 'rent_roll') {
    $rrFilter = $selectedProperty !== 'all' ? "AND p.id = ?" : '';
    $rrStmt   = $pdo->prepare("
        SELECT t.full_name, t.phone,
               p.title as property_title, u.unit_number, u.monthly_rent as unit_rent,
               l.monthly_rent, l.start_date, l.end_date, l.id as lease_id,
               (SELECT COUNT(*) FROM invoices i WHERE i.tenant_id = t.id AND i.status='Overdue') as overdue_count
        FROM leases l
        JOIN tenants t ON l.tenant_id = t.id
        JOIN units u ON l.unit_id = u.id
        JOIN properties p ON u.property_id = p.id
        WHERE l.status = 'Active'
        $rrFilter
        ORDER BY p.title, u.unit_number");
    $selectedProperty !== 'all' ? $rrStmt->execute([$selectedProperty]) : $rrStmt->execute();
    $rentRollData  = $rrStmt->fetchAll();
    $totalRentRoll = array_sum(array_column($rentRollData, 'monthly_rent'));
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

    <!-- Page header + global filters -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Reports &amp; Analytics</h1>
            <p class="text-slate-500 font-medium">Performance metrics, occupancy trends, and financial analysis.</p>
        </div>
        <form method="GET" class="flex flex-wrap gap-2 items-center bg-white dark:bg-slate-900 p-2 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-800">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
            <select name="property_id" onchange="this.form.submit()" class="px-4 py-2 bg-slate-50 dark:bg-slate-800 border-none rounded-xl text-xs font-bold outline-none">
                <option value="all">All Properties</option>
                <?php foreach ($properties as $p): ?>
                <option value="<?php echo $p['id']; ?>" <?php echo $selectedProperty===$p['id']?'selected':''; ?>><?php echo htmlspecialchars($p['title']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="month" class="px-4 py-2 bg-slate-50 dark:bg-slate-800 border-none rounded-xl text-xs font-bold outline-none">
                <?php for ($m=1;$m<=12;$m++): ?>
                <option value="<?php echo sprintf('%02d',$m); ?>" <?php echo $selectedMonth==sprintf('%02d',$m)?'selected':''; ?>><?php echo date('F',mktime(0,0,0,$m,1)); ?></option>
                <?php endfor; ?>
            </select>
            <select name="year" class="px-4 py-2 bg-slate-50 dark:bg-slate-800 border-none rounded-xl text-xs font-bold outline-none">
                <?php for ($y=date('Y');$y>=2023;$y--): ?>
                <option value="<?php echo $y; ?>" <?php echo $selectedYear==$y?'selected':''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="px-5 py-2 bg-slate-900 dark:bg-white text-white dark:text-slate-900 rounded-xl text-xs font-black uppercase tracking-widest hover:opacity-90 transition-all">Apply</button>
        </form>
    </div>

    <!-- Tab navigation -->
    <div class="flex flex-wrap gap-1 p-1.5 bg-slate-100 dark:bg-slate-800 rounded-2xl w-fit">
        <?php
        $tabs = [
            'overview'    => ['Overview',     '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>'],
            'occupancy'   => ['Occupancy',    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>'],
            'aging'       => ['Overdue Aging','<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>'],
            'performance' => ['Property Performance','<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>'],
            'rent_roll'   => ['Rent Roll',    '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/></svg>'],
            'expenses'    => ['Expenses',     '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>'],
        ];
        foreach ($tabs as $tKey => [$tLabel, $tIcon]):
            $isActive = $tab === $tKey;
        ?>
        <a href="?tab=<?php echo $tKey; ?>&month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?>&property_id=<?php echo urlencode($selectedProperty); ?>"
           class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-black transition-all <?php echo $isActive ? 'bg-white dark:bg-slate-900 text-slate-900 dark:text-white shadow-sm' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'; ?>">
            <?php echo $tIcon; ?>
            <?php echo $tLabel; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($tab === 'overview'): ?>
    <!-- ===== OVERVIEW ===== -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-5">
        <div class="glass-card p-6 border-l-4 border-green-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Gross Income</p>
            <h3 class="text-2xl font-black mt-1 text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($gross); ?></h3>
            <p class="text-[10px] text-slate-400 mt-1"><?php echo date('F Y', mktime(0,0,0,(int)$selectedMonth,1,$selectedYear)); ?></p>
        </div>
        <div class="glass-card p-6 border-l-4 border-red-400">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Expenses</p>
            <h3 class="text-2xl font-black mt-1 text-red-500"><?php echo $currency; ?> <?php echo number_format($expenseTotal); ?></h3>
        </div>
        <div class="glass-card p-6 border-l-4 border-accent-green">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Net Operating Income</p>
            <?php $noi = $gross - $expenseTotal; ?>
            <h3 class="text-2xl font-black mt-1 <?php echo $noi>=0?'text-accent-green':'text-red-500'; ?>"><?php echo $currency; ?> <?php echo number_format($noi); ?></h3>
        </div>
        <div class="glass-card p-6 border-l-4 border-blue-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Collection Rate</p>
            <h3 class="text-2xl font-black mt-1 text-blue-600"><?php echo $collectionRate; ?>%</h3>
            <p class="text-[10px] text-slate-400 mt-1"><?php echo $currency; ?> <?php echo number_format($gross); ?> / <?php echo number_format($invoicedTotal); ?></p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Collection trend chart -->
        <div class="lg:col-span-2 glass-card p-6">
            <h3 class="font-black text-slate-900 dark:text-white mb-6">Collection Rate — Last 6 Months</h3>
            <div style="height:220px;"><canvas id="collectionChart"></canvas></div>
        </div>

        <!-- Income breakdown -->
        <div class="glass-card p-6">
            <h3 class="font-black text-slate-900 dark:text-white mb-5">Income Breakdown</h3>
            <div class="space-y-5">
                <?php
                $gd = max($gross, 1);
                $bars = [
                    ['label' => 'Rent Revenue',  'val' => $rentIncome,  'pct' => round(($rentIncome/$gd)*100),  'color' => 'bg-accent-green'],
                    ['label' => 'Other Income',  'val' => $otherIncome, 'pct' => round(($otherIncome/$gd)*100), 'color' => 'bg-blue-500'],
                    ['label' => 'Expenses',      'val' => $expenseTotal,'pct' => min(100,round(($expenseTotal/$gd)*100)), 'color' => 'bg-red-400'],
                ];
                foreach ($bars as $bar): ?>
                <div>
                    <div class="flex justify-between text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">
                        <span><?php echo $bar['label']; ?></span>
                        <span><?php echo $currency; ?> <?php echo number_format($bar['val']); ?></span>
                    </div>
                    <div class="h-2.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                        <div class="h-full <?php echo $bar['color']; ?> rounded-full" style="width:<?php echo $bar['pct']; ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="pt-4 border-t border-slate-100 dark:border-slate-800 space-y-2">
                    <div class="flex justify-between text-xs font-bold">
                        <span class="text-slate-500">Efficiency Ratio</span>
                        <?php $eff = $gross > 0 ? round((($gross-$expenseTotal)/$gross)*100, 1) : 0; ?>
                        <span class="font-black text-<?php echo $eff>=70?'green':'orange'; ?>-500"><?php echo $eff; ?>%</span>
                    </div>
                    <div class="flex justify-between text-xs font-bold">
                        <span class="text-slate-500">Est. Tax (10%)</span>
                        <span class="font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($gross * 0.10); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Collection history table -->
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800">
            <h3 class="font-black text-slate-900 dark:text-white">Monthly Collection History</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead><tr class="bg-slate-50 dark:bg-slate-800/50">
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Month</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Invoiced</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Collected</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Collection Rate</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php for ($i=0;$i<6;$i++):
                        $m = date('m', strtotime("-".(5-$i)." months"));
                        $y = date('Y', strtotime("-".(5-$i)." months"));
                        $inv2Stmt = $pdo->prepare("SELECT SUM(amount) FROM invoices WHERE DATE_FORMAT(created_at,'%m')=? AND DATE_FORMAT(created_at,'%Y')=?");
                        $inv2Stmt->execute([$m, $y]);
                        $inv2 = (float)$inv2Stmt->fetchColumn();
                        $col2 = $collectedByMonth[$i];
                        $rate2 = $inv2 > 0 ? round(($col2/$inv2)*100, 1) : 0;
                    ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                        <td class="p-5 font-bold text-sm"><?php echo $collectionMonths[$i]; ?></td>
                        <td class="p-5 text-right text-sm text-slate-600 dark:text-slate-400"><?php echo $currency; ?> <?php echo number_format($inv2); ?></td>
                        <td class="p-5 text-right text-sm font-black text-accent-green"><?php echo $currency; ?> <?php echo number_format($col2); ?></td>
                        <td class="p-5 text-right">
                            <span class="px-3 py-1 text-xs font-black rounded-full <?php echo $rate2>=90?'bg-green-500/10 text-green-500':($rate2>=70?'bg-blue-500/10 text-blue-500':($rate2>=50?'bg-orange-500/10 text-orange-500':'bg-red-500/10 text-red-500')); ?>">
                                <?php echo $rate2; ?>%
                            </span>
                        </td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php elseif ($tab === 'occupancy'): ?>
    <!-- ===== OCCUPANCY ANALYSIS ===== -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        <div class="glass-card p-6 border-l-4 border-blue-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Portfolio Occupancy</p>
            <p class="text-3xl font-black <?php echo $portfolioOccPct>=80?'text-green-500':($portfolioOccPct>=50?'text-orange-500':'text-red-500'); ?>"><?php echo $portfolioOccPct; ?>%</p>
        </div>
        <div class="glass-card p-6 border-l-4 border-green-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Occupied Units</p>
            <p class="text-3xl font-black text-green-500"><?php echo $totalOccUnits; ?></p>
        </div>
        <div class="glass-card p-6 border-l-4 border-orange-400">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Vacant Units</p>
            <p class="text-3xl font-black text-orange-500"><?php echo $totalAllUnits - $totalOccUnits; ?></p>
        </div>
    </div>

    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800">
            <h3 class="font-black text-slate-900 dark:text-white">Occupancy by Property</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead><tr class="bg-slate-50 dark:bg-slate-800/50">
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Property</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Location</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Units</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Occupied</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Vacant</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Avg Rent</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Occupancy Rate</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Lost Revenue</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($occupancyData as $od):
                        $occPct = $od['total_units'] > 0 ? round(($od['occ']/$od['total_units'])*100,1) : 0;
                        $lostRev = $od['vac'] * $od['avg_rent'];
                    ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                        <td class="p-5 font-black text-sm text-slate-900 dark:text-white"><?php echo htmlspecialchars($od['title']); ?></td>
                        <td class="p-5 text-xs text-slate-500"><?php echo htmlspecialchars($od['location']); ?></td>
                        <td class="p-5 text-sm font-bold"><?php echo $od['total_units']; ?></td>
                        <td class="p-5"><span class="badge badge-green"><?php echo $od['occ']; ?></span></td>
                        <td class="p-5"><span class="badge <?php echo $od['vac']>0?'badge-orange':'badge-green'; ?>"><?php echo $od['vac']; ?></span></td>
                        <td class="p-5 text-xs font-bold"><?php echo $currency; ?> <?php echo number_format($od['avg_rent']); ?></td>
                        <td class="p-5 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <div class="w-20 h-2 bg-slate-100 dark:bg-slate-700 rounded-full overflow-hidden">
                                    <div class="h-full <?php echo $occPct>=80?'bg-green-500':($occPct>=50?'bg-orange-400':'bg-red-500'); ?> rounded-full" style="width:<?php echo $occPct; ?>%"></div>
                                </div>
                                <span class="text-xs font-black <?php echo $occPct>=80?'text-green-500':($occPct>=50?'text-orange-500':'text-red-500'); ?>"><?php echo $occPct; ?>%</span>
                            </div>
                        </td>
                        <td class="p-5 text-right text-xs font-black <?php echo $lostRev>0?'text-red-500':'text-slate-400'; ?>">
                            <?php echo $lostRev > 0 ? $currency . ' ' . number_format($lostRev) : '—'; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php elseif ($tab === 'aging'): ?>
    <!-- ===== OVERDUE AGING ===== -->
    <?php
    $totalOvd = array_sum(array_map(fn($b) => array_sum(array_column($b,'amount')), $agingBuckets));
    $totalOvdCount = array_sum(array_map('count', $agingBuckets));
    ?>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-5">
        <?php
        $bucketMeta = [
            '1–30 days'  => ['border-yellow-400', 'text-yellow-500', 'bg-yellow-50 dark:bg-yellow-900/20'],
            '31–60 days' => ['border-orange-500', 'text-orange-500', 'bg-orange-50 dark:bg-orange-900/20'],
            '61–90 days' => ['border-red-400',    'text-red-500',    'bg-red-50 dark:bg-red-900/20'],
            '90+ days'   => ['border-red-700',    'text-red-700',    'bg-red-100/80 dark:bg-red-900/30'],
        ];
        foreach ($bucketMeta as $bKey => [$bBorder, $bText, $bBg]):
            $bRows = $agingBuckets[$bKey];
            $bAmt  = array_sum(array_column($bRows, 'amount'));
        ?>
        <div class="glass-card p-6 border-l-4 <?php echo $bBorder; ?>">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest"><?php echo $bKey; ?></p>
            <p class="text-2xl font-black <?php echo $bText; ?> mt-1"><?php echo count($bRows); ?></p>
            <p class="text-xs font-bold text-slate-500 mt-0.5"><?php echo $currency; ?> <?php echo number_format($bAmt); ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <?php foreach ($agingBuckets as $bKey => $bRows):
        if (empty($bRows)) continue;
        [$bBorder, $bText] = $bucketMeta[$bKey];
    ?>
    <div class="glass-card overflow-hidden">
        <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center gap-3">
            <h3 class="font-black text-slate-900 dark:text-white"><?php echo $bKey; ?></h3>
            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black <?php echo $bText; ?> bg-current/10" style="background:currentColor!important;opacity:unset;">
                <span style="color:inherit;"><?php echo count($bRows); ?> invoice<?php echo count($bRows)!==1?'s':''; ?></span>
            </span>
            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-black <?php echo $bText; ?> ml-1"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($bRows,'amount'))); ?></span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead><tr class="bg-slate-50 dark:bg-slate-800/50">
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tenant</th>
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Property / Unit</th>
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Type</th>
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Due Date</th>
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Days Overdue</th>
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Amount</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($bRows as $row): ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                        <td class="p-4 font-bold text-sm text-slate-900 dark:text-white"><?php echo htmlspecialchars($row['tenant_name']); ?></td>
                        <td class="p-4">
                            <p class="text-xs font-bold"><?php echo htmlspecialchars($row['property_title']); ?></p>
                            <p class="text-[10px] text-slate-400">Unit <?php echo htmlspecialchars($row['unit_number']); ?></p>
                        </td>
                        <td class="p-4 text-xs text-slate-500 font-medium"><?php echo htmlspecialchars($row['invoice_type']); ?></td>
                        <td class="p-4 text-xs font-bold text-slate-600"><?php echo date('d M Y', strtotime($row['due_date'])); ?></td>
                        <td class="p-4 text-right">
                            <span class="px-2 py-0.5 bg-red-500/10 text-red-500 rounded-full text-xs font-black"><?php echo $row['days_overdue']; ?>d</span>
                        </td>
                        <td class="p-4 text-right font-black text-sm text-red-500"><?php echo $currency; ?> <?php echo number_format($row['amount']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if ($totalOvdCount === 0): ?>
    <div class="glass-card p-16 text-center">
        <p class="text-3xl mb-2">✅</p>
        <p class="font-black text-slate-900 dark:text-white text-lg">No Overdue Invoices</p>
        <p class="text-slate-400 text-sm mt-1">All invoices are current — great collection health!</p>
    </div>
    <?php endif; ?>

    <?php elseif ($tab === 'performance'): ?>
    <!-- ===== PROPERTY PERFORMANCE ===== -->
    <?php
    $totalMTD = array_sum(array_column($perfData, 'mtd_income'));
    $totalYTD = array_sum(array_column($perfData, 'ytd_income'));
    $topProp  = !empty($perfData) ? $perfData[0] : null;
    ?>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        <div class="glass-card p-6 border-l-4 border-blue-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Portfolio MTD Income</p>
            <p class="text-2xl font-black text-blue-500"><?php echo $currency; ?> <?php echo number_format($totalMTD); ?></p>
        </div>
        <div class="glass-card p-6 border-l-4 border-purple-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Portfolio YTD Income</p>
            <p class="text-2xl font-black text-purple-500"><?php echo $currency; ?> <?php echo number_format($totalYTD); ?></p>
        </div>
        <div class="glass-card p-6 border-l-4 border-accent-green">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Top Performer</p>
            <p class="text-lg font-black text-accent-green leading-tight"><?php echo $topProp ? htmlspecialchars($topProp['title']) : '—'; ?></p>
            <?php if ($topProp): ?><p class="text-[10px] text-slate-400"><?php echo $currency; ?> <?php echo number_format($topProp['ytd_income']); ?> YTD</p><?php endif; ?>
        </div>
    </div>

    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800">
            <h3 class="font-black text-slate-900 dark:text-white">Property Performance Comparison</h3>
            <p class="text-xs text-slate-400 mt-0.5">Showing MTD income for <?php echo date('F Y', mktime(0,0,0,(int)$selectedMonth,1,$selectedYear)); ?> and YTD for <?php echo $selectedYear; ?></p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead><tr class="bg-slate-900 text-white">
                    <th class="p-5 text-[10px] font-black uppercase tracking-widest">Property</th>
                    <th class="p-5 text-[10px] font-black uppercase tracking-widest">Units</th>
                    <th class="p-5 text-[10px] font-black uppercase tracking-widest">Occupancy</th>
                    <th class="p-5 text-[10px] font-black uppercase tracking-widest text-right">MTD Income</th>
                    <th class="p-5 text-[10px] font-black uppercase tracking-widest text-right">MTD Expenses</th>
                    <th class="p-5 text-[10px] font-black uppercase tracking-widest text-right">MTD NOI</th>
                    <th class="p-5 text-[10px] font-black uppercase tracking-widest text-right">YTD Income</th>
                    <th class="p-5 text-[10px] font-black uppercase tracking-widest text-right">Overdue</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($perfData as $pd):
                        $pdOccPct = $pd['total_units'] > 0 ? round(($pd['occ_units']/$pd['total_units'])*100) : 0;
                        $pdNoi    = $pd['mtd_income'] - $pd['mtd_expenses'];
                    ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                        <td class="p-5 font-black text-sm text-slate-900 dark:text-white"><?php echo htmlspecialchars($pd['title']); ?></td>
                        <td class="p-5 text-sm font-bold"><?php echo $pd['occ_units']; ?>/<?php echo $pd['total_units']; ?></td>
                        <td class="p-5 text-sm font-black <?php echo $pdOccPct>=80?'text-green-500':($pdOccPct>=50?'text-orange-500':'text-red-500'); ?>"><?php echo $pdOccPct; ?>%</td>
                        <td class="p-5 text-right text-sm font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($pd['mtd_income']); ?></td>
                        <td class="p-5 text-right text-sm font-black text-red-500">
                            <?php echo $pd['mtd_expenses'] > 0 ? $currency . ' ' . number_format($pd['mtd_expenses']) : '—'; ?>
                        </td>
                        <td class="p-5 text-right text-sm font-black <?php echo $pdNoi>=0?'text-accent-green':'text-red-500'; ?>"><?php echo $currency; ?> <?php echo number_format($pdNoi); ?></td>
                        <td class="p-5 text-right text-sm font-black text-purple-500"><?php echo $currency; ?> <?php echo number_format($pd['ytd_income']); ?></td>
                        <td class="p-5 text-right">
                            <?php if ($pd['overdue_count'] > 0): ?>
                            <span class="px-2 py-0.5 bg-red-500/10 text-red-500 rounded-full text-[10px] font-black"><?php echo $pd['overdue_count']; ?></span>
                            <?php else: ?>
                            <span class="text-green-500 text-xs font-black">✓</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="bg-slate-50 dark:bg-slate-900/50">
                    <td colspan="3" class="p-5 text-sm font-black text-slate-900 dark:text-white">Portfolio Total</td>
                    <td class="p-5 text-right text-sm font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($totalMTD); ?></td>
                    <td class="p-5 text-right text-sm font-black text-red-500"><?php echo $currency; ?> <?php echo number_format(array_sum(array_column($perfData,'mtd_expenses'))); ?></td>
                    <td class="p-5 text-right text-sm font-black text-accent-green"><?php echo $currency; ?> <?php echo number_format($totalMTD - array_sum(array_column($perfData,'mtd_expenses'))); ?></td>
                    <td class="p-5 text-right text-sm font-black text-purple-500"><?php echo $currency; ?> <?php echo number_format($totalYTD); ?></td>
                    <td class="p-5"></td>
                </tr></tfoot>
            </table>
        </div>
    </div>

    <?php elseif ($tab === 'rent_roll'): ?>
    <!-- ===== RENT ROLL ===== -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        <div class="glass-card p-6 border-l-4 border-accent-green">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Active Leases</p>
            <p class="text-3xl font-black text-slate-900 dark:text-white"><?php echo count($rentRollData); ?></p>
        </div>
        <div class="glass-card p-6 border-l-4 border-blue-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Monthly Rent Roll</p>
            <p class="text-2xl font-black text-blue-500"><?php echo $currency; ?> <?php echo number_format($totalRentRoll); ?></p>
        </div>
        <div class="glass-card p-6 border-l-4 border-orange-400">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Annual Rent Roll</p>
            <p class="text-2xl font-black text-orange-500"><?php echo $currency; ?> <?php echo number_format($totalRentRoll * 12); ?></p>
        </div>
    </div>

    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
            <h3 class="font-black text-slate-900 dark:text-white">Active Rent Roll</h3>
            <a href="?tab=rent_roll&property_id=<?php echo urlencode($selectedProperty); ?>&export=csv"
               class="flex items-center gap-2 px-4 py-2 bg-slate-100 dark:bg-slate-800 hover:bg-accent-green hover:text-white transition-all rounded-xl text-xs font-black text-slate-700 dark:text-slate-300">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Export CSV
            </a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead><tr class="bg-slate-900 text-white">
                    <th class="p-5 text-[10px] font-black uppercase tracking-widest">Tenant</th>
                    <th class="p-5 text-[10px] font-black uppercase tracking-widest">Property</th>
                    <th class="p-5 text-[10px] font-black uppercase tracking-widest">Unit</th>
                    <th class="p-5 text-[10px] font-black uppercase tracking-widest text-right">Monthly Rent</th>
                    <th class="p-5 text-[10px] font-black uppercase tracking-widest">Lease Start</th>
                    <th class="p-5 text-[10px] font-black uppercase tracking-widest">Lease End</th>
                    <th class="p-5 text-[10px] font-black uppercase tracking-widest text-center">Status</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php if (empty($rentRollData)): ?>
                    <tr><td colspan="7" class="p-12 text-center text-slate-400 italic">No active leases found.</td></tr>
                    <?php else: foreach ($rentRollData as $rr):
                        $daysLeft  = (int)ceil((strtotime($rr['end_date']) - time()) / 86400);
                        $expiring  = $daysLeft <= 60 && $daysLeft >= 0;
                        $expired   = $daysLeft < 0;
                    ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all <?php echo $rr['overdue_count']>0?'bg-red-50/30 dark:bg-red-900/5':''; ?>">
                        <td class="p-5">
                            <p class="font-bold text-sm text-slate-900 dark:text-white"><?php echo htmlspecialchars($rr['full_name']); ?></p>
                            <?php if (!empty($rr['phone'])): ?>
                            <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($rr['phone']); ?></p>
                            <?php endif; ?>
                            <?php if ($rr['overdue_count']>0): ?>
                            <span class="text-[9px] font-black text-red-500"><?php echo $rr['overdue_count']; ?> overdue</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-5 text-xs font-bold"><?php echo htmlspecialchars($rr['property_title']); ?></td>
                        <td class="p-5 text-xs font-bold"><?php echo htmlspecialchars($rr['unit_number']); ?></td>
                        <td class="p-5 text-right font-black text-sm text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($rr['monthly_rent']); ?></td>
                        <td class="p-5 text-xs text-slate-500"><?php echo date('d M Y', strtotime($rr['start_date'])); ?></td>
                        <td class="p-5 text-xs <?php echo $expired?'text-red-500 font-black':($expiring?'text-orange-500 font-black':'text-slate-500'); ?>">
                            <?php echo date('d M Y', strtotime($rr['end_date'])); ?>
                            <?php if ($expiring): ?><br><span class="text-[9px]"><?php echo $daysLeft; ?>d left</span><?php endif; ?>
                            <?php if ($expired):  ?><br><span class="text-[9px]">Expired</span><?php endif; ?>
                        </td>
                        <td class="p-5 text-center">
                            <span class="badge badge-green">Active</span>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($rentRollData)): ?>
                <tfoot><tr class="bg-slate-50 dark:bg-slate-900/50">
                    <td colspan="3" class="p-5 font-black text-sm text-slate-900 dark:text-white">Total Monthly Rent Roll</td>
                    <td class="p-5 text-right text-lg font-black text-blue-500"><?php echo $currency; ?> <?php echo number_format($totalRentRoll); ?></td>
                    <td colspan="3" class="p-5 text-xs text-slate-400"><?php echo count($rentRollData); ?> active leases &mdash; Annual: <?php echo $currency; ?> <?php echo number_format($totalRentRoll*12); ?></td>
                </tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
    <?php elseif ($tab === 'expenses'): ?>
    <!-- ===== EXPENSES ANALYSIS ===== -->
    <?php
    $expCatTotal = array_sum(array_column($expCatBreakdown, 'total'));
    $expCatColors = [
        'Maintenance' => '#f97316',
        'Utilities'   => '#3b82f6',
        'Salaries'    => '#a855f7',
        'Taxes'       => '#ef4444',
        'Insurance'   => '#6366f1',
        'Marketing'   => '#ec4899',
        'Legal'       => '#eab308',
        'Repairs'     => '#f59e0b',
        'Cleaning'    => '#14b8a6',
        'Other'       => '#94a3b8',
    ];
    ?>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        <div class="glass-card p-6 border-l-4 border-red-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">MTD Expenses</p>
            <p class="text-2xl font-black text-red-500"><?php echo $currency; ?> <?php echo number_format($expMTD); ?></p>
            <p class="text-[10px] text-slate-400 mt-1"><?php echo date('F Y', mktime(0,0,0,(int)$selectedMonth,1,$selectedYear)); ?></p>
        </div>
        <div class="glass-card p-6 border-l-4 border-orange-400">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">YTD Expenses</p>
            <p class="text-2xl font-black text-orange-500"><?php echo $currency; ?> <?php echo number_format($expYTD); ?></p>
            <p class="text-[10px] text-slate-400 mt-1"><?php echo $selectedYear; ?> year-to-date</p>
        </div>
        <div class="glass-card p-6 border-l-4 border-slate-400">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Categories</p>
            <p class="text-2xl font-black text-slate-900 dark:text-white"><?php echo count($expCatBreakdown); ?></p>
            <p class="text-[10px] text-slate-400 mt-1">spending categories</p>
        </div>
    </div>

    <?php if (!empty($expCatBreakdown)): ?>
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
        <!-- Trend chart -->
        <div class="lg:col-span-3 glass-card p-6">
            <h3 class="font-black text-slate-900 dark:text-white mb-1">Monthly Expense Trend</h3>
            <p class="text-xs text-slate-400 mb-6">Last 6 months</p>
            <div style="height:220px;"><canvas id="expTrendChart"></canvas></div>
        </div>

        <!-- Category breakdown -->
        <div class="lg:col-span-2 glass-card p-6">
            <h3 class="font-black text-slate-900 dark:text-white mb-1">By Category</h3>
            <p class="text-xs text-slate-400 mb-6"><?php echo $selectedYear; ?> YTD — <?php echo $currency; ?> <?php echo number_format($expCatTotal); ?> total</p>
            <div class="space-y-3">
                <?php foreach ($expCatBreakdown as $cb):
                    $pct   = $expCatTotal > 0 ? round(($cb['total'] / $expCatTotal) * 100, 1) : 0;
                    $color = $expCatColors[$cb['category']] ?? '#94a3b8';
                ?>
                <div>
                    <div class="flex items-center justify-between mb-1">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background:<?php echo $color; ?>"></span>
                            <span class="text-xs font-bold text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($cb['category']); ?></span>
                            <span class="text-[10px] text-slate-400"><?php echo $pct; ?>%</span>
                        </div>
                        <span class="text-xs font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($cb['total']); ?></span>
                    </div>
                    <div class="h-1.5 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                        <div class="h-full rounded-full" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Expense category table -->
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <h3 class="font-black text-slate-900 dark:text-white">Category Breakdown — <?php echo $selectedYear; ?></h3>
            <a href="expenses.php" class="text-xs font-black text-accent-green hover:underline">View All Expenses →</a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead><tr class="bg-slate-50 dark:bg-slate-800/50">
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Category</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Transactions</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Total Spent</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">% of Total</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Avg per Transaction</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($expCatBreakdown as $cb):
                        $pct = $expCatTotal > 0 ? round(($cb['total'] / $expCatTotal) * 100, 1) : 0;
                        $avg = $cb['cnt'] > 0 ? $cb['total'] / $cb['cnt'] : 0;
                        $color = $expCatColors[$cb['category']] ?? '#94a3b8';
                    ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                        <td class="p-5">
                            <div class="flex items-center gap-2.5">
                                <span class="w-3 h-3 rounded-full shrink-0" style="background:<?php echo $color; ?>"></span>
                                <span class="font-bold text-sm text-slate-900 dark:text-white"><?php echo htmlspecialchars($cb['category']); ?></span>
                            </div>
                        </td>
                        <td class="p-5 text-right text-sm text-slate-600 dark:text-slate-400"><?php echo $cb['cnt']; ?></td>
                        <td class="p-5 text-right font-black text-sm text-red-500"><?php echo $currency; ?> <?php echo number_format($cb['total']); ?></td>
                        <td class="p-5 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <div class="w-16 h-1.5 bg-slate-100 dark:bg-slate-700 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full" style="width:<?php echo $pct; ?>%;background:<?php echo $color; ?>"></div>
                                </div>
                                <span class="text-xs font-black text-slate-600 dark:text-slate-400"><?php echo $pct; ?>%</span>
                            </div>
                        </td>
                        <td class="p-5 text-right text-sm text-slate-600 dark:text-slate-400"><?php echo $currency; ?> <?php echo number_format($avg); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="bg-slate-50 dark:bg-slate-900/50">
                    <td class="p-5 font-black text-sm text-slate-900 dark:text-white">Total</td>
                    <td class="p-5 text-right font-black text-sm"><?php echo array_sum(array_column($expCatBreakdown, 'cnt')); ?></td>
                    <td class="p-5 text-right font-black text-sm text-red-500"><?php echo $currency; ?> <?php echo number_format($expCatTotal); ?></td>
                    <td colspan="2" class="p-5"></td>
                </tr></tfoot>
            </table>
        </div>
    </div>
    <?php else: ?>
    <div class="glass-card p-16 text-center">
        <p class="font-black text-slate-900 dark:text-white text-lg">No Expenses Recorded</p>
        <p class="text-slate-400 text-sm mt-1">Start tracking business expenses from the <a href="expenses.php" class="text-accent-green hover:underline font-bold">Expenses</a> page.</p>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</div>

<?php if ($tab === 'overview'): ?>
<script>
(function(){
    const ctx = document.getElementById('collectionChart');
    if (!ctx) return;
    const isDark = document.documentElement.classList.contains('dark');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($collectionMonths); ?>,
            datasets: [
                {
                    label: 'Collected',
                    data: <?php echo json_encode($collectedByMonth); ?>,
                    borderColor: '#22c55e', backgroundColor: 'rgba(34,197,94,0.08)',
                    fill: true, tension: 0.4, borderWidth: 2.5,
                    pointBackgroundColor: '#22c55e', pointRadius: 4, yAxisID: 'y'
                },
                {
                    label: 'Collection Rate %',
                    data: <?php echo json_encode($collectionRates); ?>,
                    borderColor: '#3b82f6', backgroundColor: 'transparent',
                    tension: 0.4, borderWidth: 2, borderDash: [4,3],
                    pointBackgroundColor: '#3b82f6', pointRadius: 4, yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11, weight: '700' } } },
                tooltip: {
                    callbacks: {
                        label: c => c.datasetIndex === 0
                            ? '<?php echo $currency; ?> ' + c.raw.toLocaleString()
                            : c.raw + '%'
                    }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { size: 11, weight: '700' } } },
                y: {
                    position: 'left',
                    grid: { color: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.04)' },
                    ticks: { color: '#94a3b8', font: { size: 11 }, callback: v => '<?php echo $currency; ?> ' + v.toLocaleString() }
                },
                y1: {
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    min: 0, max: 100,
                    ticks: { color: '#94a3b8', font: { size: 11 }, callback: v => v + '%' }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<?php if ($tab === 'expenses' && !empty($expCatBreakdown)): ?>
<script>
(function(){
    const ctx = document.getElementById('expTrendChart');
    if (!ctx) return;
    const isDark = document.documentElement.classList.contains('dark');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($expTrendMonths); ?>,
            datasets: [{
                label: 'Expenses',
                data: <?php echo json_encode($expMonthlyTrend); ?>,
                backgroundColor: 'rgba(239,68,68,0.15)',
                borderColor: '#ef4444',
                borderWidth: 2,
                borderRadius: 8,
                hoverBackgroundColor: 'rgba(239,68,68,0.30)',
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
                y: {
                    grid: { color: isDark ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.04)' },
                    ticks: { color: '#94a3b8', font: { size: 11 }, callback: v => '<?php echo $currency; ?> ' + v.toLocaleString() }
                }
            }
        }
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
