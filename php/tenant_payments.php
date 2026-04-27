<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin(['admin', 'staff']);

$pageTitle = "Tenant Payments & Invoices";
$user = getCurrentUser($pdo);
$searchTerm = $_GET['search'] ?? '';

// Fetch all tenants (with their active leases if any)
$tenants = $pdo->query("
    SELECT t.id, t.full_name, t.phone, u.unit_number, p.title as property_title, l.monthly_rent
    FROM tenants t
    LEFT JOIN leases l ON t.id = l.tenant_id AND l.status = 'Active'
    LEFT JOIN units u ON l.unit_id = u.id
    LEFT JOIN properties p ON u.property_id = p.id
    WHERE t.status = 'Active'
    ORDER BY t.full_name
")->fetchAll();

// Fetch recent invoices with optional search
$invoiceQuery = "
    SELECT i.*, t.full_name as tenant_name 
    FROM invoices i
    JOIN tenants t ON i.tenant_id = t.id
";

if ($searchTerm) {
    $invoiceQuery .= " WHERE t.full_name LIKE :search OR i.id LIKE :search OR i.invoice_type LIKE :search ";
}

$invoiceQuery .= " ORDER BY i.created_at DESC LIMIT 50";
$stmt = $pdo->prepare($invoiceQuery);
if ($searchTerm) {
    $stmt->execute(['search' => "%$searchTerm%"]);
} else {
    $stmt->execute();
}
$invoices = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Tenant Payments</h1>
            <p class="text-slate-500 font-medium">Manage invoices, record payments, and track tenant balances.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3 w-full md:w-auto">
            <form class="relative flex-1 md:w-64">
                <input type="text" name="search" value="<?php echo htmlspecialchars((string)$searchTerm); ?>" placeholder="Search name, ID or type..." class="w-full pl-10 pr-4 py-3 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-xs font-bold focus:ring-2 focus:ring-accent-green/20 outline-none transition-all">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            </form>
            <button onclick="openModal('newInvoiceModal')" class="px-5 py-3 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-2xl font-bold text-sm hover:bg-slate-200 transition-all">
                Generate Invoice
            </button>
            <button onclick="openModal('recordPaymentModal')" class="btn-primary">
                Record Payment
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Invoices List -->
        <div class="lg:col-span-2 space-y-6">
            <div class="glass-card overflow-hidden">
                <div class="p-6 border-b border-slate-100 dark:border-slate-800">
                    <h3 class="text-lg font-black">Recent Invoices</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50 dark:bg-slate-800/50">
                                <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Invoice</th>
                                <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Type</th>
                                <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount</th>
                                <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                                <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <?php if (empty($invoices)): ?>
                                <tr><td colspan="5" class="p-10 text-center text-slate-400 italic">No invoices generated yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($invoices as $inv): ?>
                                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                                    <td class="p-6">
                                        <p class="text-sm font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars((string)$inv['tenant_name']); ?></p>
                                        <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest">Due: <?php echo date('M d, Y', strtotime($inv['due_date'])); ?></p>
                                    </td>
                                    <td class="p-6">
                                        <span class="text-xs font-bold text-slate-600 dark:text-slate-400"><?php echo htmlspecialchars((string)$inv['invoice_type']); ?></span>
                                    </td>
                                    <td class="p-6 text-sm font-black text-slate-900 dark:text-white">KSh <?php echo number_format($inv['amount']); ?></td>
                                    <td class="p-6">
                                        <span class="px-3 py-1 <?php echo $inv['status'] == 'Paid' ? 'bg-green-500/10 text-green-500' : 'bg-orange-500/10 text-orange-500'; ?> rounded-full text-[10px] font-black uppercase tracking-widest">
                                            <?php echo htmlspecialchars((string)$inv['status']); ?>
                                        </span>
                                    </td>
                                    <td class="p-6 text-right">
                                        <a href="view_invoice.php?id=<?php echo $inv['id']; ?>" class="text-accent-green hover:underline text-xs font-bold">Details</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tenant Balances Quick View -->
        <div class="space-y-6">
            <div class="glass-card p-6">
                <h3 class="text-lg font-black mb-6 tracking-tight">Active Tenants</h3>
                <div class="space-y-4">
                    <?php foreach ($tenants as $t): ?>
                    <div class="flex items-center justify-between p-4 bg-slate-50 dark:bg-slate-800/50 rounded-2xl">
                        <div>
                            <p class="text-sm font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars((string)$t['full_name']); ?></p>
                            <p class="text-[10px] text-slate-400 font-black uppercase tracking-widest"><?php echo htmlspecialchars((string)$t['property_title']); ?> - <?php echo htmlspecialchars((string)$t['unit_number']); ?></p>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] text-slate-400 font-black uppercase tracking-widest">Monthly Rent</p>
                            <p class="text-sm font-black text-accent-green">KSh <?php echo number_format($t['monthly_rent']); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Record Payment Modal -->
<div id="recordPaymentModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <button onclick="closeModal('recordPaymentModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-8">Record Manual Payment</h2>
        <form action="actions/financial_actions.php" method="POST" class="space-y-6">
            <input type="hidden" name="action" value="create">
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Select Tenant</label>
                <select name="tenant_id" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    <option value="">Select Tenant...</option>
                    <?php foreach ($tenants as $t): ?>
                        <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars((string)$t['full_name']); ?> (<?php echo htmlspecialchars((string)$t['unit_number']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Amount (KSh)</label>
                    <input type="number" name="amount" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Category</label>
                    <select name="transaction_type" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                        <option>Rent</option>
                        <option>Water</option>
                        <option>Service Charge</option>
                        <option>Penalty</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Method</label>
                    <select name="payment_method" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                        <option>Cash</option>
                        <option>Bank Transfer</option>
                        <option>M-Pesa (Reference)</option>
                        <option>Check</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Payment Date</label>
                    <input type="date" name="transaction_date" value="<?php echo date('Y-m-d'); ?>" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                </div>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Notes</label>
                <textarea name="description" rows="2" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none"></textarea>
            </div>
            <button type="submit" class="btn-green w-full justify-center py-4">Confirm Payment Receipt</button>
        </form>
    </div>
</div>

<!-- New Invoice Modal -->
<div id="newInvoiceModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <button onclick="closeModal('newInvoiceModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-8">Generate Professional Invoice</h2>
        <form action="actions/financial_actions.php" method="POST" class="space-y-6">
            <input type="hidden" name="action" value="generate_invoice">
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Select Tenant</label>
                <select name="tenant_id" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    <option value="">Select Tenant...</option>
                    <?php foreach ($tenants as $t): ?>
                        <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars((string)$t['full_name']); ?> (<?php echo htmlspecialchars((string)$t['unit_number']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Invoice Amount (KSh)</label>
                    <input type="number" name="amount" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Invoice Type</label>
                    <select name="invoice_type" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                        <option>Rent</option>
                        <option>Water</option>
                        <option>Service Charge</option>
                        <option>Penalty</option>
                        <option>Other</option>
                    </select>
                </div>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Payment Due Date</label>
                <input type="date" name="due_date" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
            </div>
            <button type="submit" class="btn-primary w-full justify-center py-4">Generate & Send Invoice</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
