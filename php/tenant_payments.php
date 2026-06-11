<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin(['admin', 'staff']);
require_once __DIR__ . '/includes/settings.php';

$pageTitle  = "Tenant Payments & Invoices";
$user       = getCurrentUser($pdo);
$searchTerm = $_GET['search'] ?? '';
$filter     = $_GET['filter'] ?? '';   // 'overdue' to show only overdue

$currency = getSetting($pdo, 'currency_symbol', 'KSh');

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

// Overdue summary stats
$overdueInvoiceCount = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status='Overdue'")->fetchColumn();
$overdueTenantCount  = (int)$pdo->query("SELECT COUNT(DISTINCT tenant_id) FROM invoices WHERE status='Overdue'")->fetchColumn();
$overdueAmountTotal  = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status='Overdue'")->fetchColumn();

// Fetch invoices with optional search + filter
$conditions = [];
$params     = [];

if ($searchTerm) {
    $conditions[] = "(t.full_name LIKE :search OR i.id LIKE :search OR i.invoice_type LIKE :search)";
    $params['search'] = "%$searchTerm%";
}

if ($filter === 'overdue') {
    $conditions[] = "i.status = 'Overdue'";
}

$whereClause  = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$invoiceQuery = "SELECT i.*, t.full_name as tenant_name FROM invoices i JOIN tenants t ON i.tenant_id = t.id $whereClause ORDER BY i.created_at DESC LIMIT 100";
$stmt = $pdo->prepare($invoiceQuery);
$stmt->execute($params);
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
                <?php if ($filter): ?><input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>"><?php endif; ?>
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

    <!-- Overdue summary + filter strip -->
    <?php if ($overdueInvoiceCount > 0): ?>
    <div class="p-4 bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-800/30 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex flex-wrap gap-6">
            <div>
                <p class="text-[9px] font-black text-red-400 uppercase tracking-widest">Overdue Invoices</p>
                <p class="text-2xl font-black text-red-600 dark:text-red-400"><?php echo $overdueInvoiceCount; ?></p>
            </div>
            <div>
                <p class="text-[9px] font-black text-red-400 uppercase tracking-widest">Affected Tenants</p>
                <p class="text-2xl font-black text-red-600 dark:text-red-400"><?php echo $overdueTenantCount; ?></p>
            </div>
            <div>
                <p class="text-[9px] font-black text-red-400 uppercase tracking-widest">Overdue Amount</p>
                <p class="text-2xl font-black text-red-600 dark:text-red-400"><?php echo $currency; ?> <?php echo number_format($overdueAmountTotal); ?></p>
            </div>
        </div>
        <div class="flex gap-2">
            <?php if ($filter === 'overdue'): ?>
            <a href="tenant_payments.php" class="px-4 py-2.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 rounded-xl text-xs font-black hover:bg-slate-50 transition-colors">Show All</a>
            <?php else: ?>
            <a href="tenant_payments.php?filter=overdue" class="px-4 py-2.5 bg-red-500 text-white rounded-xl text-xs font-black hover:bg-red-600 transition-colors">View Overdue Only</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($filter === 'overdue'): ?>
    <div class="flex items-center gap-2">
        <span class="px-3 py-1.5 bg-red-500/10 text-red-500 border border-red-500/20 rounded-full text-[10px] font-black uppercase tracking-widest">Filtered: Overdue Only</span>
        <a href="tenant_payments.php" class="text-[10px] font-black text-slate-400 hover:text-slate-700 transition-colors">Clear filter ×</a>
    </div>
    <?php endif; ?>

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
                                        <?php
                                        $statusClasses = match($inv['status']) {
                                            'Paid'    => 'bg-green-500/10 text-green-500 border-green-500/20',
                                            'Overdue' => 'bg-red-500/10 text-red-500 border-red-500/20',
                                            default   => 'bg-orange-500/10 text-orange-500 border-orange-500/20',
                                        };
                                        ?>
                                        <span class="px-3 py-1 <?php echo $statusClasses; ?> border rounded-full text-[10px] font-black uppercase tracking-widest">
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
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-lg font-black tracking-tight">Active Tenants</h3>
                    <span class="text-[10px] font-black uppercase text-slate-400">Financial Summary</span>
                </div>
                <div class="space-y-4">
                    <?php foreach ($tenants as $t): 
                        // Fetch financial summary for this tenant
                        $finStmt = $pdo->prepare("
                            SELECT 
                                (SELECT SUM(amount) FROM invoices WHERE tenant_id = ?) as total_invoiced,
                                (SELECT SUM(amount) FROM transactions WHERE tenant_id = ? AND status = 'Paid') as total_paid
                        ");
                        $finStmt->execute([$t['id'], $t['id']]);
                        $fin = $finStmt->fetch();
                        $totalInv  = $fin['total_invoiced'] ?? 0;
                        $totalPaid = $fin['total_paid']     ?? 0;
                        $balance   = $totalInv - $totalPaid;
                        $ovdChk = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE tenant_id = ? AND status = 'Overdue'");
                        $ovdChk->execute([$t['id']]);
                        $tenantOverdueCount = (int)$ovdChk->fetchColumn();
                    ?>
                    <div class="p-5 bg-slate-50 dark:bg-slate-800/50 rounded-2xl border <?php echo $tenantOverdueCount > 0 ? 'border-red-200 dark:border-red-800/30' : 'border-slate-100 dark:border-slate-800/50 hover:border-accent-green/30'; ?> transition-all group">
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <p class="text-sm font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars((string)$t['full_name']); ?></p>
                                <p class="text-[10px] text-slate-400 font-black uppercase tracking-widest"><?php echo htmlspecialchars((string)$t['property_title']); ?> - <?php echo htmlspecialchars((string)$t['unit_number']); ?></p>
                                <?php if ($tenantOverdueCount > 0): ?>
                                <span class="inline-flex items-center gap-1 mt-1 px-2 py-0.5 bg-red-500/10 text-red-500 rounded-full text-[9px] font-black border border-red-500/20">
                                    <span class="w-1 h-1 rounded-full bg-red-500"></span>
                                    <?php echo $tenantOverdueCount; ?> overdue
                                </span>
                                <?php endif; ?>
                            </div>
                            <a href="view_statement.php?tenant_id=<?php echo $t['id']; ?>" class="p-2 bg-white dark:bg-slate-800 rounded-xl border border-slate-100 dark:border-slate-700 text-accent-green opacity-0 group-hover:opacity-100 transition-all shadow-sm" title="View Detailed Statement">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
                            </a>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-1">
                                <p class="text-[9px] text-slate-400 font-black uppercase tracking-widest">Total Paid</p>
                                <p class="text-xs font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($totalPaid); ?></p>
                            </div>
                            <div class="space-y-1 text-right">
                                <p class="text-[9px] text-slate-400 font-black uppercase tracking-widest">Balance Due</p>
                                <p class="text-xs font-black <?php echo $balance > 0 ? 'text-red-500' : 'text-accent-green'; ?>">
                                    <?php echo $currency; ?> <?php echo number_format($balance); ?>
                                </p>
                            </div>
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
                        <option>Waste</option>
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
        <h2 class="text-2xl font-black mb-4">Generate Professional Invoice</h2>
        <div class="p-4 bg-blue-50 dark:bg-blue-900/10 rounded-2xl mb-6 border border-blue-100 dark:border-blue-800/30">
            <p class="text-[10px] text-blue-600 dark:text-blue-400 font-bold leading-relaxed">
                <span class="uppercase">Automated Billing Active:</span> Rent and Garbage fees are automatically billed to all active tenants on the 1st of every month. Use this form primarily for <strong>Water</strong> bills and special items.
            </p>
        </div>
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
                        <option selected>Water</option>
                        <option>Rent</option>
                        <option>Garbage</option>
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
