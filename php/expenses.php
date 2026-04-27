<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin(['admin', 'staff']);

$pageTitle = "Business Expenses";
$user = getCurrentUser($pdo);
$searchTerm = $_GET['search'] ?? '';

// Fetch expenses with optional search
$expQuery = "
    SELECT e.*, p.title as property_title 
    FROM expenses e
    LEFT JOIN properties p ON e.property_id = p.id
";
if ($searchTerm) {
    $expQuery .= " WHERE e.description LIKE :search OR e.category LIKE :search ";
}
$expQuery .= " ORDER BY e.expense_date DESC";
$stmtExp = $pdo->prepare($expQuery);
if ($searchTerm) $stmtExp->execute(['search' => "%$searchTerm%"]);
else $stmtExp->execute();
$expenses = $stmtExp->fetchAll();

// Fetch properties for selection
$properties = $pdo->query("SELECT id, title FROM properties ORDER BY title")->fetchAll();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Business Expenses</h1>
            <p class="text-slate-500 font-medium">Track operational costs, utilities, and maintenance overheads.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3 w-full md:w-auto">
            <form class="relative flex-1 md:w-64">
                <input type="text" name="search" value="<?php echo htmlspecialchars((string)$searchTerm); ?>" placeholder="Search description or category..." class="w-full pl-10 pr-4 py-3 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-xs font-bold focus:ring-2 focus:ring-accent-green/20 outline-none transition-all">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            </form>
            <button onclick="openModal('newExpenseModal')" class="btn-primary">
                Record New Expense
            </button>
        </div>
    </div>

    <!-- Stats Bar -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <?php
        $currentMonth = date('Y-m');
        $stmt = $pdo->prepare("SELECT SUM(amount) as total FROM expenses WHERE DATE_FORMAT(expense_date, '%Y-%m') = ?");
        $stmt->execute([$currentMonth]);
        $monthlyTotal = $stmt->fetch()['total'] ?? 0;
        ?>
        <div class="glass-card p-6 border-l-4 border-red-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Monthly Outflow (<?php echo date('M'); ?>)</p>
            <h3 class="text-3xl font-black mt-1 text-red-600">KSh <?php echo number_format($monthlyTotal); ?></h3>
        </div>
    </div>

    <div class="glass-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50 dark:bg-slate-800/50">
                        <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Description</th>
                        <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Category</th>
                        <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Property</th>
                        <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount</th>
                        <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php if (empty($expenses)): ?>
                        <tr><td colspan="5" class="p-10 text-center text-slate-400 italic">No expenses recorded yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($expenses as $exp): ?>
                        <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                            <td class="p-6">
                                <p class="text-sm font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars((string)$exp['description']); ?></p>
                            </td>
                            <td class="p-6">
                                <span class="px-3 py-1 bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400 rounded-full text-[10px] font-black uppercase tracking-widest">
                                    <?php echo htmlspecialchars((string)$exp['category']); ?>
                                </span>
                            </td>
                            <td class="p-6">
                                <span class="text-xs font-medium text-slate-500 uppercase"><?php echo htmlspecialchars((string)($exp['property_title'] ?? 'General Business')); ?></span>
                            </td>
                            <td class="p-6 text-sm font-black text-red-500">KSh <?php echo number_format($exp['amount']); ?></td>
                            <td class="p-6 text-xs font-bold text-slate-400 uppercase"><?php echo date('M d, Y', strtotime($exp['expense_date'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- New Expense Modal -->
<div id="newExpenseModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <button onclick="closeModal('newExpenseModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-8">Record Business Expense</h2>
        <form action="actions/expense_actions.php" method="POST" class="space-y-6">
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Description</label>
                <input type="text" name="description" required placeholder="e.g., Office Electricity, Paint for Unit 4..." class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none transition-all">
            </div>
            <div class="grid grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Amount (KSh)</label>
                    <input type="number" name="amount" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none transition-all">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Category</label>
                    <select name="category" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none transition-all">
                        <option>Maintenance</option>
                        <option>Utilities</option>
                        <option>Salaries</option>
                        <option>Taxes</option>
                        <option>Marketing</option>
                        <option>Legal</option>
                        <option>Other</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Linked Property (Optional)</label>
                    <select name="property_id" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none transition-all">
                        <option value="">General Group / Office</option>
                        <?php foreach ($properties as $p): ?>
                            <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars((string)$p['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Date</label>
                    <input type="date" name="expense_date" value="<?php echo date('Y-m-d'); ?>" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none transition-all">
                </div>
            </div>
            <button type="submit" class="btn-primary w-full justify-center py-4">Record Financial Outflow</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
