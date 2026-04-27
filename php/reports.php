<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin(['admin', 'staff']);

$pageTitle = "Financial Reports";
$user = getCurrentUser($pdo);

$selectedMonth = $_GET['month'] ?? date('m');
$selectedYear = $_GET['year'] ?? date('Y');
$selectedProperty = $_GET['property_id'] ?? 'all';

// Fetch Properties
$properties = $pdo->query("SELECT id, title FROM properties ORDER BY title")->fetchAll();

// 1. Property Income Statement Logic
$incomeQuery = "SELECT 
    SUM(CASE WHEN transaction_type = 'Rent' AND status = 'Paid' THEN amount ELSE 0 END) as rent_income,
    SUM(CASE WHEN transaction_type != 'Rent' AND status = 'Paid' THEN amount ELSE 0 END) as other_income
    FROM transactions 
    WHERE DATE_FORMAT(transaction_date, '%m') = ? 
    AND DATE_FORMAT(transaction_date, '%Y') = ?";
$params = [$selectedMonth, $selectedYear];

if ($selectedProperty !== 'all') {
    $incomeQuery .= " AND lease_id IN (SELECT id FROM leases WHERE property_id = ?)";
    $params[] = $selectedProperty;
}
$stmt = $pdo->prepare($incomeQuery);
$stmt->execute($params);
$income = $stmt->fetch();

// 2. Expense Summary
$expenseQuery = "SELECT SUM(amount) as total_expenses FROM expenses WHERE DATE_FORMAT(expense_date, '%m') = ? AND DATE_FORMAT(expense_date, '%Y') = ?";
$exParams = [$selectedMonth, $selectedYear];
if ($selectedProperty !== 'all') {
    $expenseQuery .= " AND property_id = ?";
    $exParams[] = $selectedProperty;
}
$stmtEx = $pdo->prepare($expenseQuery);
$stmtEx->execute($exParams);
$expenseTotal = $stmtEx->fetch()['total_expenses'] ?? 0;

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Financial Reports</h1>
            <p class="text-slate-500 font-medium">Generate professional statements and analyze system-wide performance.</p>
        </div>
        <form class="flex flex-wrap gap-3 bg-white dark:bg-slate-900 p-2 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-800">
            <select name="property_id" class="px-4 py-2 bg-slate-50 dark:bg-slate-800 border-none rounded-xl text-xs font-bold outline-none">
                <option value="all">All Properties</option>
                <?php foreach ($properties as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo $selectedProperty == $p['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$p['title']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="month" class="px-4 py-2 bg-slate-50 dark:bg-slate-800 border-none rounded-xl text-xs font-bold outline-none">
                <?php for($m=1; $m<=12; $m++): ?>
                    <option value="<?php echo sprintf('%02d', $m); ?>" <?php echo $selectedMonth == $m ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                <?php endfor; ?>
            </select>
            <select name="year" class="px-4 py-2 bg-slate-50 dark:bg-slate-800 border-none rounded-xl text-xs font-bold outline-none">
                <?php for($y=date('Y'); $y>=2024; $y--): ?>
                    <option value="<?php echo $y; ?>" <?php echo $selectedYear == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="px-6 py-2 bg-slate-900 dark:bg-white text-white dark:text-slate-900 rounded-xl text-xs font-black uppercase tracking-widest hover:opacity-90 transition-all">Filter</button>
        </form>
    </div>

    <!-- Quick Analytics -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="glass-card p-6 border-l-4 border-green-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest text-wrap">Gross Income</p>
            <h3 class="text-2xl font-black mt-1 text-slate-900 dark:text-white">KSh <?php echo number_format($income['rent_income'] + $income['other_income']); ?></h3>
        </div>
        <div class="glass-card p-6 border-l-4 border-red-400">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Expenses</p>
            <h3 class="text-2xl font-black mt-1 text-red-500">KSh <?php echo number_format($expenseTotal); ?></h3>
        </div>
        <div class="glass-card p-6 border-l-4 border-accent-green">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Net Operating Income</p>
            <h3 class="text-2xl font-black mt-1 text-accent-green">KSh <?php echo number_format($income['rent_income'] + $income['other_income'] - $expenseTotal); ?></h3>
        </div>
        <div class="glass-card p-6 border-l-4 border-blue-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Efficiency Ratio</p>
            <h3 class="text-2xl font-black mt-1 text-blue-600">
                <?php 
                $gross = ($income['rent_income'] + $income['other_income']) ?: 1;
                echo number_format((($gross - $expenseTotal) / $gross) * 100, 1); 
                ?>%
            </h3>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 text-wrap">
        <!-- Statement Generators -->
        <div class="lg:col-span-2 space-y-6">
            <div class="glass-card p-8">
                <h3 class="text-xl font-black mb-6">Statement Generator Center</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-wrap">
                    <div class="p-6 bg-slate-50 dark:bg-slate-800/50 rounded-3xl border border-dashed border-slate-200 dark:border-slate-700 hover:border-accent-green transition-all group">
                        <div class="flex items-center gap-4 mb-4">
                            <div class="p-3 bg-white dark:bg-slate-800 rounded-xl text-accent-green">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                            </div>
                            <h4 class="font-black text-lg">Tenant Annual Statement</h4>
                        </div>
                        <p class="text-xs text-slate-500 font-medium mb-6 leading-relaxed">Generate a comprehensive audit trail of all rent and utility payments for a specific tenant across the fiscal year.</p>
                        <button onclick="alert('Module loading... Generating PDF Statement.')" class="w-full py-3 bg-slate-900 dark:bg-white text-white dark:text-slate-900 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:scale-[1.02] active:scale-[0.98] transition-all">Download PDF Statement</button>
                    </div>

                    <div class="p-6 bg-slate-50 dark:bg-slate-800/50 rounded-3xl border border-dashed border-slate-200 dark:border-slate-700 hover:border-blue-500 transition-all group">
                        <div class="flex items-center gap-4 mb-4">
                            <div class="p-3 bg-white dark:bg-slate-800 rounded-xl text-blue-500">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="m9 16 2 2 4-4"/></svg>
                            </div>
                            <h4 class="font-black text-lg">Landlord Payout Summary</h4>
                        </div>
                        <p class="text-xs text-slate-500 font-medium mb-6 leading-relaxed">Review disbursements, agency fees, and advances deducted for this landlord within the selected period.</p>
                        <button onclick="alert('Module loading... Generating Payout Report.')" class="w-full py-3 bg-blue-600 text-white rounded-2xl text-[10px] font-black uppercase tracking-widest hover:scale-[1.02] active:scale-[0.98] transition-all">Generate Payout Report</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Property Breakdown -->
        <div class="glass-card p-6 overflow-hidden">
            <h3 class="text-lg font-black mb-6">Income vs Expenses</h3>
            <div class="space-y-6">
                <!-- Data bars visualization -->
                <div class="space-y-2">
                    <div class="flex justify-between text-[10px] font-black text-slate-400 uppercase tracking-widest">
                        <span>Rent Revenue</span>
                        <span>KSh <?php echo number_format($income['rent_income']); ?></span>
                    </div>
                    <div class="h-3 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                        <?php 
                        $rentPct = ($income['rent_income'] / $gross) * 100;
                        ?>
                        <div class="h-full bg-accent-green rounded-full shadow-lg" style="width: <?php echo $rentPct; ?>%"></div>
                    </div>
                </div>

                <div class="space-y-2">
                    <div class="flex justify-between text-[10px] font-black text-slate-400 uppercase tracking-widest">
                        <span>Business Expenses</span>
                        <span>KSh <?php echo number_format($expenseTotal); ?></span>
                    </div>
                    <div class="h-3 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                        <?php 
                        $expPct = ($expenseTotal / $gross) * 100;
                        if ($expPct > 100) $expPct = 100;
                        ?>
                        <div class="h-full bg-red-400 rounded-full shadow-lg" style="width: <?php echo $expPct; ?>%"></div>
                    </div>
                </div>

                <div class="pt-6 border-t border-slate-100 dark:border-slate-800">
                    <div class="p-4 bg-slate-900 dark:bg-white text-white dark:text-slate-900 rounded-2xl shadow-xl">
                        <p class="text-[10px] font-black uppercase tracking-widest opacity-60">Estimated Local Tax (10%)</p>
                        <p class="text-xl font-black">KSh <?php echo number_format(($income['rent_income'] + $income['other_income']) * 0.1); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
