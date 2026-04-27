<?php
/**
 * Financials Management Page
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = getCurrentUser($pdo);
$role = $_SESSION['role'] ?? 'tenant';
$pageTitle = "Financials";

// Fetch transactions and totals
$query = "SELECT tr.*, t.full_name as tenant_name 
          FROM transactions tr 
          LEFT JOIN tenants t ON tr.tenant_id = t.id";

if ($role === 'landlord') {
    $landlordId = getLandlordId($pdo);
    
    // PENDING ADVANCES logic: Get approved advances that haven't been deducted yet
    $stmtAdv = $pdo->prepare("SELECT SUM(amount) as total_advances FROM landlord_advances WHERE landlord_id = ? AND status = 'Approved' AND is_deducted = 0");
    $stmtAdv->execute([$landlordId]);
    $pendingAdvTotal = $stmtAdv->fetch()['total_advances'] ?? 0;

    $query = "SELECT tr.*, t.full_name as tenant_name 
              FROM transactions tr 
              JOIN tenants t ON tr.tenant_id = t.id
              JOIN leases ls ON ls.tenant_id = t.id
              JOIN units un ON ls.unit_id = un.id
              JOIN properties p ON un.property_id = p.id
              WHERE p.landlord_id = " . $pdo->quote($landlordId);
} elseif ($role === 'tenant') {
    $stmt = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $tenant = $stmt->fetch();
    $tenantId = $tenant['id'] ?? null;
    $query .= " WHERE tr.tenant_id = " . $pdo->quote($tenantId);
}

$query .= " ORDER BY tr.transaction_date DESC";
$transactions = $pdo->query($query)->fetchAll();

// Calculate summary stats (Current Month)
$currentMonth = date('Y-m');
$totalReceived = 0;
$pendingAmount = 0;

// Transactions summary for current month
$stmt = $pdo->prepare("SELECT 
    SUM(CASE WHEN status = 'Paid' THEN amount ELSE 0 END) as total_paid,
    SUM(CASE WHEN status = 'Pending' THEN amount ELSE 0 END) as total_pending
    FROM transactions 
    WHERE DATE_FORMAT(transaction_date, '%Y-%m') = ?");
$stmt->execute([$currentMonth]);
$monthlyStats = $stmt->fetch();
$totalReceived = $monthlyStats['total_paid'] ?? 0;
$pendingAmount = $monthlyStats['total_pending'] ?? 0;

// Monthly Landlord Payouts
$stmt = $pdo->prepare("SELECT SUM(amount) as total_payouts FROM landlord_payouts WHERE DATE_FORMAT(payout_date, '%Y-%m') = ? AND status = 'Completed'");
$stmt->execute([$currentMonth]);
$payoutStats = $stmt->fetch();
$totalMonthlyPayouts = $payoutStats['total_payouts'] ?? 0;

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';

// Fetch tenants for the dropdown (scoped if landlord)
if ($role === 'landlord') {
    $landlordId = getLandlordId($pdo);
    $allTenants = $pdo->query("
        SELECT DISTINCT t.id, t.full_name 
        FROM tenants t
        JOIN leases ls ON t.id = ls.tenant_id
        JOIN units un ON ls.unit_id = un.id
        JOIN properties p ON un.property_id = p.id
        WHERE p.landlord_id = " . $pdo->quote($landlordId) . "
        ORDER BY t.full_name
    ")->fetchAll();
} else {
    $allTenants = $pdo->query("SELECT id, full_name FROM tenants ORDER BY full_name")->fetchAll();
}
?>

<div class="space-y-8 animate-in">
    <?php if (isset($_GET['success'])): ?>
    <div class="p-4 bg-green-500/10 border border-green-500/20 text-green-500 rounded-xl font-bold text-sm animate-in fade-in slide-in-from-top-4">
        Transaction recorded successfully!
    </div>
    <?php endif; ?>

    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight"><?php echo $role === 'landlord' ? 'Financial Income' : 'Financial Overview'; ?></h1>
            <p class="text-slate-500 font-medium"><?php echo $role === 'landlord' ? 'Overview of earnings from your units.' : 'Track your payments, invoices, and revenue.'; ?></p>
        </div>
        <?php if ($role != 'tenant'): ?>
        <button onclick="openModal('newTransactionModal')" class="btn-primary">
            + New Transaction
        </button>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div class="glass-card p-6 border-l-4 border-green-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Monthly Collection (<?php echo date('M'); ?>)</p>
            <h3 class="text-3xl font-black mt-1 text-green-600">KSh <?php echo number_format($totalReceived); ?></h3>
        </div>
        <div class="glass-card p-6 border-l-4 border-orange-400">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Monthly Pending</p>
            <h3 class="text-3xl font-black mt-1 text-orange-500">KSh <?php echo number_format($pendingAmount); ?></h3>
        </div>
        <div class="glass-card p-6 border-l-4 border-blue-500">
            <?php if ($role === 'landlord'): ?>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">My Net Payable (<?php echo date('M'); ?>)</p>
                <?php 
                    $grossEarnings = $totalReceived * 0.9; 
                    $netPayable = $grossEarnings - ($pendingAdvTotal ?? 0);
                ?>
                <h3 class="text-3xl font-black mt-1 text-blue-600">KSh <?php echo number_format($netPayable); ?></h3>
                <?php if (($pendingAdvTotal ?? 0) > 0): ?>
                    <p class="text-[9px] font-bold text-red-500 uppercase mt-1 italic">- KSh <?php echo number_format($pendingAdvTotal); ?> Approved Advances</p>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Monthly Payouts</p>
                <h3 class="text-3xl font-black mt-1 text-blue-600">KSh <?php echo number_format($totalMonthlyPayouts); ?></h3>
            <?php endif; ?>
        </div>
    </div>

    <!-- New Transaction Modal -->
    <div id="newTransactionModal" class="modal-overlay" style="display:none;">
        <div class="modal-card">
            <button onclick="closeModal('newTransactionModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            <h2 class="text-2xl font-black mb-8">Record New Transaction</h2>
            <form action="actions/financial_actions.php" method="POST" class="space-y-6">
                <input type="hidden" name="action" value="create">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Tenant</label>
                    <select name="tenant_id" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                        <option value="">Select Tenant</option>
                        <?php foreach ($allTenants as $t): ?>
                            <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Amount (KSh)</label>
                        <input type="number" name="amount" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Type</label>
                        <select name="transaction_type" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                            <option>Rent</option>
                            <option>Service Charge</option>
                            <option>Deposit</option>
                            <option>Utility Bill</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Payment Method</label>
                        <select name="payment_method" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                            <option>M-Pesa</option>
                            <option>Bank Transfer</option>
                            <option>Cash</option>
                            <option>Check</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Status</label>
                        <select name="status" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                            <option>Paid</option>
                            <option>Pending</option>
                            <option>Overdue</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn-green w-full justify-center py-4">Record Transaction</button>
            </form>
        </div>
    </div>

    <!-- Transaction Table -->
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
            <h3 class="text-lg font-black tracking-tight">Recent Transactions</h3>
            <div class="flex gap-2">
                <button class="px-3 py-1 text-[10px] font-black uppercase tracking-widest border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800">Export CSV</button>
            </div>
        </div>
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-slate-50 dark:bg-slate-800/50">
                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Transaction</th>
                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Type</th>
                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount</th>
                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="5" class="p-20 text-center text-slate-400 italic font-medium">No transactions found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $tr): ?>
                    <tr class="group hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                        <td class="p-6">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 group-hover:text-accent-green transition-colors">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="14" x="3" y="5" rx="2"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                                </div>
                                <div>
                                    <p class="text-sm font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($tr['tenant_name'] ?: 'System'); ?></p>
                                    <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest"><?php echo htmlspecialchars($tr['payment_method'] ?: 'N/A'); ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="p-6">
                            <span class="text-xs font-bold text-slate-600 dark:text-slate-400"><?php echo htmlspecialchars($tr['transaction_type']); ?></span>
                        </td>
                        <td class="p-6">
                            <span class="text-sm font-black text-slate-900 dark:text-white">KSh <?php echo number_format($tr['amount']); ?></span>
                        </td>
                        <td class="p-6">
                            <span class="px-3 py-1 <?php echo $tr['status'] == 'Paid' ? 'bg-green-500/10 text-green-500' : 'bg-orange-500/10 text-orange-500'; ?> rounded-full text-[10px] font-black uppercase tracking-widest">
                                <?php echo htmlspecialchars($tr['status']); ?>
                            </span>
                        </td>
                        <td class="p-6 text-right">
                            <p class="text-xs font-bold text-slate-500"><?php echo date('M d, Y', strtotime($tr['transaction_date'])); ?></p>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
