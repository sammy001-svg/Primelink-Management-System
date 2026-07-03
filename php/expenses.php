<?php
/**
 * Business Expenses
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/includes/settings.php';

$pageTitle = "Business Expenses";
$user      = getCurrentUser($pdo);
$currency  = getSetting($pdo, 'currency_symbol', 'KSh');

// Schema self-heal
foreach ([
    "ALTER TABLE expenses ADD COLUMN IF NOT EXISTS vendor     VARCHAR(255) NULL",
    "ALTER TABLE expenses ADD COLUMN IF NOT EXISTS notes      TEXT NULL",
    "ALTER TABLE expenses ADD COLUMN IF NOT EXISTS created_by VARCHAR(36) NULL",
] as $ddl) {
    try { $pdo->exec($ddl); } catch (PDOException $e) {}
}

// ── Fetch data ────────────────────────────────────────────────────────
$properties = $pdo->query("SELECT id, title FROM properties ORDER BY title")->fetchAll();

$expenses = $pdo->query("
    SELECT e.*, p.title AS property_title
    FROM expenses e
    LEFT JOIN properties p ON e.property_id = p.id
    ORDER BY e.expense_date DESC, e.id DESC
    LIMIT 800
")->fetchAll();

// ── KPI aggregates ────────────────────────────────────────────────────
$thisMonth = date('Y-m');
$thisYear  = date('Y');

$kpiMonth = (float)$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m') = ?")->execute([$thisMonth]) && true
    ? (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m') = '$thisMonth'")->fetchColumn()
    : 0;

$kpiYear  = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE YEAR(expense_date) = $thisYear")->fetchColumn();
$kpiAll   = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM expenses")->fetchColumn();
$kpiCount = (int)$pdo->query("SELECT COUNT(*) FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m') = '$thisMonth'")->fetchColumn();

// Category breakdown for chart (this year)
$catStmt = $pdo->query("
    SELECT category, SUM(amount) as total
    FROM expenses
    WHERE YEAR(expense_date) = $thisYear
    GROUP BY category
    ORDER BY total DESC
");
$catData = $catStmt->fetchAll();

// ── Flash ─────────────────────────────────────────────────────────────
$flash    = match($_GET['success'] ?? '') {
    'expense_recorded' => 'Expense recorded.',
    'expense_updated'  => 'Expense updated.',
    'expense_deleted'  => 'Expense deleted.',
    default            => '',
};
$flashErr = urldecode($_GET['error'] ?? '');

$categories = ['Maintenance','Utilities','Salaries','Taxes','Insurance','Marketing','Legal','Repairs','Cleaning','Other'];

$catColors = [
    'Maintenance' => 'bg-orange-50 dark:bg-orange-900/20 text-orange-600 dark:text-orange-400',
    'Utilities'   => 'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400',
    'Salaries'    => 'bg-purple-50 dark:bg-purple-900/20 text-purple-600 dark:text-purple-400',
    'Taxes'       => 'bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400',
    'Insurance'   => 'bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-400',
    'Marketing'   => 'bg-pink-50 dark:bg-pink-900/20 text-pink-600 dark:text-pink-400',
    'Legal'       => 'bg-yellow-50 dark:bg-yellow-900/20 text-yellow-700 dark:text-yellow-400',
    'Repairs'     => 'bg-amber-50 dark:bg-amber-900/20 text-amber-600 dark:text-amber-400',
    'Cleaning'    => 'bg-teal-50 dark:bg-teal-900/20 text-teal-600 dark:text-teal-400',
    'Other'       => 'bg-slate-100 dark:bg-slate-800 text-slate-500',
];

// Keyed expense data for edit modal
$expPanelData = [];
foreach ($expenses as $e) {
    $expPanelData[$e['id']] = [
        'description' => $e['description'],
        'amount'      => $e['amount'],
        'category'    => $e['category'],
        'vendor'      => $e['vendor'] ?? '',
        'notes'       => $e['notes']  ?? '',
        'property_id' => $e['property_id'] ?? '',
        'expense_date'=> $e['expense_date'],
    ];
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

    <!-- ── Toast ─────────────────────────────────────────────────────── -->
    <?php if ($flash): ?>
    <div id="expToast" class="fixed bottom-6 right-6 z-50 bg-green-500 text-white px-6 py-3.5 rounded-2xl shadow-2xl font-black text-sm flex items-center gap-2.5">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <?php echo htmlspecialchars($flash); ?>
    </div>
    <script>setTimeout(() => document.getElementById('expToast')?.remove(), 4000);</script>
    <?php endif; ?>
    <?php if ($flashErr): ?>
    <div id="expErrToast" class="fixed bottom-6 right-6 z-50 bg-red-500 text-white px-6 py-3.5 rounded-2xl shadow-2xl font-black text-sm flex items-center gap-2.5">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>
        <?php echo htmlspecialchars($flashErr); ?>
    </div>
    <script>setTimeout(() => document.getElementById('expErrToast')?.remove(), 5000);</script>
    <?php endif; ?>

    <!-- ── Page Header ───────────────────────────────────────────────── -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white">Business Expenses</h1>
            <p class="text-slate-400 font-medium text-sm mt-1">Track operational costs, vendor payments, and overheads.</p>
        </div>
        <button onclick="openModal('newExpenseModal')" class="btn-primary gap-2 self-start sm:self-auto">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
            Record Expense
        </button>
    </div>

    <!-- ── KPI Strip ─────────────────────────────────────────────────── -->
    <div class="grid grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="glass-card p-5 border-l-[3px] border-red-400">
            <div class="w-8 h-8 rounded-xl bg-red-50 dark:bg-red-900/30 flex items-center justify-center text-red-500 mb-3">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
            </div>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">This Month</p>
            <h3 class="text-xl font-black text-red-500 mt-1"><?php echo $currency; ?> <?php echo number_format($kpiMonth); ?></h3>
            <p class="text-[10px] text-slate-400 mt-1"><?php echo $kpiCount; ?> transaction<?php echo $kpiCount !== 1 ? 's' : ''; ?></p>
        </div>
        <div class="glass-card p-5 border-l-[3px] border-orange-400">
            <div class="w-8 h-8 rounded-xl bg-orange-50 dark:bg-orange-900/30 flex items-center justify-center text-orange-500 mb-3">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
            </div>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">This Year</p>
            <h3 class="text-xl font-black text-orange-500 mt-1"><?php echo $currency; ?> <?php echo number_format($kpiYear); ?></h3>
            <p class="text-[10px] text-slate-400 mt-1"><?php echo date('Y'); ?> YTD</p>
        </div>
        <div class="glass-card p-5 border-l-[3px] border-slate-400">
            <div class="w-8 h-8 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-500 mb-3">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">All Time</p>
            <h3 class="text-xl font-black text-slate-900 dark:text-white mt-1"><?php echo $currency; ?> <?php echo number_format($kpiAll); ?></h3>
            <p class="text-[10px] text-slate-400 mt-1"><?php echo count($expenses); ?> total records</p>
        </div>
        <!-- Category breakdown mini -->
        <div class="glass-card p-5 border-l-[3px] border-purple-400">
            <div class="w-8 h-8 rounded-xl bg-purple-50 dark:bg-purple-900/30 flex items-center justify-center text-purple-500 mb-3">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
            </div>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Top Category</p>
            <?php if (!empty($catData)): ?>
            <h3 class="text-sm font-black text-purple-500 mt-1"><?php echo htmlspecialchars($catData[0]['category']); ?></h3>
            <p class="text-[10px] text-slate-400 mt-0.5"><?php echo $currency; ?> <?php echo number_format($catData[0]['total']); ?> this year</p>
            <?php else: ?>
            <h3 class="text-sm font-black text-slate-400 mt-1">No data yet</h3>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Expenses Table ────────────────────────────────────────────── -->
    <div class="glass-card overflow-hidden">
        <!-- Filters -->
        <div class="p-6 border-b border-slate-100 dark:border-slate-800">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <!-- Category tabs -->
                <div class="flex items-center gap-1 p-1 bg-slate-100 dark:bg-slate-800/80 rounded-xl w-fit overflow-x-auto shrink-0" style="max-width:100%">
                    <button onclick="filterExpCat('')" id="etab_all"
                        class="exp-tab px-3 py-1.5 rounded-lg text-[11px] font-black uppercase tracking-wide whitespace-nowrap transition-all bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-sm">
                        All
                    </button>
                    <?php foreach ($categories as $cat): ?>
                    <button onclick="filterExpCat('<?php echo $cat; ?>')" id="etab_<?php echo strtolower($cat); ?>"
                        class="exp-tab px-3 py-1.5 rounded-lg text-[11px] font-black uppercase tracking-wide whitespace-nowrap transition-all text-slate-500 hover:text-slate-700 dark:hover:text-slate-300">
                        <?php echo $cat; ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <!-- Search + property -->
                <div class="flex items-center gap-3 flex-wrap">
                    <div class="relative">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" id="expSearch" oninput="applyExpFilters()" placeholder="Search description, vendor…"
                            class="pl-8 pr-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800/60 text-sm font-medium text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-accent-green/30 w-52 transition">
                    </div>
                    <?php if (count($properties) > 1): ?>
                    <select id="expPropFilter" onchange="applyExpFilters()"
                        class="py-2 px-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800/60 text-sm font-bold text-slate-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-green/30 transition">
                        <option value="">All Properties</option>
                        <?php foreach ($properties as $p): ?>
                        <option value="<?php echo htmlspecialchars($p['id']); ?>"><?php echo htmlspecialchars($p['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (empty($expenses)): ?>
        <div class="text-center py-16">
            <div class="w-14 h-14 rounded-2xl bg-slate-50 dark:bg-slate-800 flex items-center justify-center text-slate-300 mx-auto mb-4">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <p class="text-slate-400 font-bold text-sm">No expenses recorded yet.</p>
            <button onclick="openModal('newExpenseModal')" class="mt-4 btn-primary text-sm">Record First Expense</button>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="data-table" id="expTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Category</th>
                        <th>Vendor</th>
                        <th>Property</th>
                        <th class="text-right">Amount</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expenses as $exp):
                        $catBadge = $catColors[$exp['category'] ?? 'Other'] ?? $catColors['Other'];
                    ?>
                    <tr class="exp-row"
                        data-category="<?php echo htmlspecialchars($exp['category'] ?? ''); ?>"
                        data-propid="<?php echo htmlspecialchars($exp['property_id'] ?? ''); ?>"
                        data-search="<?php echo strtolower(htmlspecialchars(($exp['description'] ?? '') . ' ' . ($exp['vendor'] ?? '') . ' ' . ($exp['property_title'] ?? ''))); ?>">
                        <td class="whitespace-nowrap">
                            <div class="font-bold text-sm text-slate-900 dark:text-white"><?php echo date('d M Y', strtotime($exp['expense_date'])); ?></div>
                            <div class="text-[10px] text-slate-400"><?php echo date('l', strtotime($exp['expense_date'])); ?></div>
                        </td>
                        <td style="max-width:200px">
                            <div class="font-bold text-sm text-slate-900 dark:text-white"><?php echo htmlspecialchars($exp['description']); ?></div>
                            <?php if (!empty($exp['notes'])): ?>
                            <div class="text-xs text-slate-400 mt-0.5 line-clamp-1"><?php echo htmlspecialchars($exp['notes']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="px-2.5 py-1 rounded-lg text-[10px] font-black uppercase tracking-wide <?php echo $catBadge; ?>">
                                <?php echo htmlspecialchars($exp['category'] ?? 'Other'); ?>
                            </span>
                        </td>
                        <td class="text-sm text-slate-600 dark:text-slate-400">
                            <?php echo htmlspecialchars($exp['vendor'] ?? '—'); ?>
                        </td>
                        <td class="text-sm text-slate-600 dark:text-slate-400">
                            <?php echo htmlspecialchars($exp['property_title'] ?? 'General'); ?>
                        </td>
                        <td class="text-right font-black text-sm text-red-500 whitespace-nowrap">
                            <?php echo $currency; ?> <?php echo number_format((float)$exp['amount']); ?>
                        </td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-2">
                                <button onclick="openEditModal('<?php echo $exp['id']; ?>')"
                                    class="p-1.5 hover:bg-slate-100 dark:hover:bg-slate-700 rounded-lg transition-colors text-slate-400 hover:text-slate-900 dark:hover:text-white">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                </button>
                                <form action="actions/expense_actions.php" method="POST" class="inline"
                                      onsubmit="return confirm('Delete this expense? This cannot be undone.')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $exp['id']; ?>">
                                    <input type="hidden" name="_redirect" value="../expenses.php">
                                    <button type="submit"
                                        class="p-1.5 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors text-slate-400 hover:text-red-500">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="m19 6-.867 12.142A2 2 0 0 1 16.138 20H7.862a2 2 0 0 1-1.995-1.858L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="expEmpty" class="hidden text-center py-10">
            <p class="text-slate-400 font-bold text-sm">No expenses match your filter.</p>
        </div>
        <!-- Running total footer -->
        <div class="p-4 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <span class="text-[11px] font-black text-slate-400 uppercase tracking-widest" id="expRowCount"><?php echo count($expenses); ?> expenses</span>
            <span class="text-sm font-black text-slate-900 dark:text-white">
                Total: <span class="text-red-500" id="expTotal"><?php echo $currency; ?> <?php echo number_format($kpiAll); ?></span>
            </span>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Category Breakdown ──────────────────────────────────────── -->
    <?php if (!empty($catData)): ?>
    <div class="glass-card p-6">
        <h3 class="font-black text-slate-900 dark:text-white mb-1">Spending by Category</h3>
        <p class="text-xs text-slate-400 mb-6"><?php echo date('Y'); ?> year-to-date</p>
        <div class="space-y-4">
            <?php
            $catTotal = array_sum(array_column($catData, 'total'));
            foreach ($catData as $cd):
                $pct = $catTotal > 0 ? round(($cd['total'] / $catTotal) * 100, 1) : 0;
                $badge = $catColors[$cd['category']] ?? $catColors['Other'];
            ?>
            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <div class="flex items-center gap-2">
                        <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-wide <?php echo $badge; ?>"><?php echo htmlspecialchars($cd['category']); ?></span>
                        <span class="text-[10px] font-black text-slate-400"><?php echo $pct; ?>%</span>
                    </div>
                    <span class="text-sm font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($cd['total']); ?></span>
                </div>
                <div class="h-2 bg-slate-100 dark:bg-slate-800 rounded-full overflow-hidden">
                    <div class="h-full bg-red-400 rounded-full transition-all" style="width:<?php echo $pct; ?>%"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- NEW EXPENSE MODAL                                                  -->
<!-- ══════════════════════════════════════════════════════════════════ -->
<div id="newExpenseModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <button onclick="closeModal('newExpenseModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-8">Record Expense</h2>
        <form action="actions/expense_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="_redirect" value="../expenses.php">
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Description</label>
                <input type="text" name="description" required placeholder="e.g. Office electricity bill" class="form-input w-full">
            </div>
            <div class="grid grid-cols-2 gap-5">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Amount (<?php echo $currency; ?>)</label>
                    <input type="number" name="amount" required min="0" step="0.01" placeholder="0.00" class="form-input w-full">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Category</label>
                    <select name="category" class="form-input w-full">
                        <?php foreach ($categories as $cat): ?>
                        <option><?php echo $cat; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-5">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Vendor / Payee</label>
                    <input type="text" name="vendor" placeholder="e.g. Kenya Power" class="form-input w-full">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Date</label>
                    <input type="date" name="expense_date" value="<?php echo date('Y-m-d'); ?>" class="form-input w-full">
                </div>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Linked Property</label>
                <select name="property_id" class="form-input w-full">
                    <option value="">General / Office (Not property-specific)</option>
                    <?php foreach ($properties as $p): ?>
                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Notes (Optional)</label>
                <textarea name="notes" rows="2" placeholder="Any relevant details…" class="form-input w-full resize-none"></textarea>
            </div>
            <button type="submit" class="btn-primary w-full justify-center py-4">Save Expense</button>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- EDIT EXPENSE MODAL                                                 -->
<!-- ══════════════════════════════════════════════════════════════════ -->
<div id="editExpenseModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <button onclick="closeModal('editExpenseModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-8">Edit Expense</h2>
        <form action="actions/expense_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="_redirect" value="../expenses.php">
            <input type="hidden" name="id" id="editExpId">
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Description</label>
                <input type="text" name="description" id="editExpDesc" required class="form-input w-full">
            </div>
            <div class="grid grid-cols-2 gap-5">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Amount (<?php echo $currency; ?>)</label>
                    <input type="number" name="amount" id="editExpAmount" required min="0" step="0.01" class="form-input w-full">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Category</label>
                    <select name="category" id="editExpCat" class="form-input w-full">
                        <?php foreach ($categories as $cat): ?>
                        <option><?php echo $cat; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-5">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Vendor / Payee</label>
                    <input type="text" name="vendor" id="editExpVendor" class="form-input w-full">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Date</label>
                    <input type="date" name="expense_date" id="editExpDate" class="form-input w-full">
                </div>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Linked Property</label>
                <select name="property_id" id="editExpProp" class="form-input w-full">
                    <option value="">General / Office</option>
                    <?php foreach ($properties as $p): ?>
                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Notes</label>
                <textarea name="notes" id="editExpNotes" rows="2" class="form-input w-full resize-none"></textarea>
            </div>
            <button type="submit" class="btn-green w-full justify-center py-4">Update Expense</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
const expData = <?php echo json_encode($expPanelData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
let currentExpCat = '';

function openEditModal(id) {
    const d = expData[id];
    if (!d) return;
    document.getElementById('editExpId').value      = id;
    document.getElementById('editExpDesc').value    = d.description;
    document.getElementById('editExpAmount').value  = d.amount;
    document.getElementById('editExpCat').value     = d.category;
    document.getElementById('editExpVendor').value  = d.vendor;
    document.getElementById('editExpDate').value    = d.expense_date;
    document.getElementById('editExpProp').value    = d.property_id;
    document.getElementById('editExpNotes').value   = d.notes;
    openModal('editExpenseModal');
}

function filterExpCat(cat) {
    currentExpCat = cat;
    applyExpFilters();
    document.querySelectorAll('.exp-tab').forEach(t => {
        t.classList.remove('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-sm');
        t.classList.add('text-slate-500');
    });
    const el = document.getElementById('etab_' + (cat || 'all').toLowerCase());
    if (el) {
        el.classList.add('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-sm');
        el.classList.remove('text-slate-500');
    }
}

function applyExpFilters() {
    const q     = (document.getElementById('expSearch')?.value || '').toLowerCase().trim();
    const prop  = document.getElementById('expPropFilter')?.value || '';
    const rows  = document.querySelectorAll('.exp-row');
    let visible = 0;
    let total   = 0;

    rows.forEach(row => {
        const matchCat  = !currentExpCat || row.dataset.category === currentExpCat;
        const matchProp = !prop || row.dataset.propid === prop;
        const matchQ    = !q   || (row.dataset.search || '').includes(q);
        const show = matchCat && matchProp && matchQ;
        row.style.display = show ? '' : 'none';
        if (show) { visible++; }
    });

    const emptyEl = document.getElementById('expEmpty');
    if (emptyEl) emptyEl.classList.toggle('hidden', visible > 0);

    const countEl = document.getElementById('expRowCount');
    if (countEl) countEl.textContent = visible + ' expense' + (visible !== 1 ? 's' : '');
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeModal('newExpenseModal');
        closeModal('editExpenseModal');
    }
});
</script>
