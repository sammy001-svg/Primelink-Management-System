<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/bank_accounts.php';
require_once __DIR__ . '/includes/payment_alloc.php';
require_once __DIR__ . '/includes/billing_run.php';

ensureBankAccountSchema($pdo);
ensurePaymentAllocSchema($pdo);
ensureBillingRunSchema($pdo);

// Properties available to walk, for the invoice launcher
$runProperties = billableProperties($pdo);
$runPeriod     = date('Y-m');

$pageTitle  = "Tenant Payments & Invoices";
$user       = getCurrentUser($pdo);
$searchTerm = $_GET['search'] ?? '';
$filter     = $_GET['filter'] ?? '';   // 'overdue' to show only overdue

$currency = getSetting($pdo, 'currency_symbol', 'KSh');

// Fetch all tenants with lease, unit, and balance details for auto-population
$tenants = $pdo->query("
    SELECT t.id, t.full_name, t.phone, t.email,
           u.unit_number, u.id AS unit_id,
           p.title AS property_title,
           l.id AS lease_id, l.monthly_rent, l.start_date, l.end_date,
           COALESCE((
               SELECT SUM(i.amount)
               FROM invoices i
               WHERE i.tenant_id = t.id AND i.status NOT IN ('Paid','Cancelled')
           ), 0) AS arrears
    FROM tenants t
    LEFT JOIN leases l ON t.id = l.tenant_id AND l.status = 'Active'
    LEFT JOIN units u ON l.unit_id = u.id
    LEFT JOIN properties p ON u.property_id = p.id
    WHERE t.status = 'Active'
    ORDER BY t.full_name
")->fetchAll();

// Build JS-friendly map keyed by tenant id
$tenantPayMap = [];
foreach ($tenants as $t) {
    $tenantPayMap[$t['id']] = [
        'name'          => $t['full_name'],
        'phone'         => $t['phone']          ?? '',
        'email'         => $t['email']          ?? '',
        'unit_number'   => $t['unit_number']    ?? '—',
        'property'      => $t['property_title'] ?? '—',
        'monthly_rent'  => (float)($t['monthly_rent'] ?? 0),
        'arrears'       => (float)($t['arrears']       ?? 0),
        'lease_end'     => $t['end_date']       ?? '',
    ];
}

// What each tenant still owes, so the payment form can pre-fill the split
// instead of asking staff to remember it
$outstandingMap = outstandingByTenant($pdo);

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
            <button onclick="openModal('newInvoiceModal')" class="btn-ghost">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
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
    <div class="modal-card" style="max-width:540px;">
        <button type="button" onclick="payCancel()" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 transition-colors" aria-label="Cancel">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-6">Record Manual Payment</h2>
        <form action="actions/financial_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="create">
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Select Tenant</label>
                <select name="tenant_id" id="pay_tenant_sel" required onchange="onPayTenantChange(this.value)"
                    class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    <option value="">— Select tenant —</option>
                    <?php foreach ($tenants as $t): ?>
                        <option value="<?php echo $t['id']; ?>">
                            <?php echo htmlspecialchars((string)$t['full_name']); ?>
                            <?php if ($t['unit_number']): ?> · Unit <?php echo htmlspecialchars((string)$t['unit_number']); ?><?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Tenant Info Card (hidden until a tenant is selected) -->
            <div id="pay_tenant_card" class="hidden rounded-2xl border border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/40 overflow-hidden">
                <div class="flex items-center gap-3 px-4 py-3 border-b border-slate-100 dark:border-slate-700">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-green-500 to-emerald-600 flex items-center justify-center text-white font-black text-sm shrink-0" id="pay_tc_avatar"></div>
                    <div class="flex-1 min-w-0">
                        <p class="font-black text-slate-900 dark:text-white text-sm truncate" id="pay_tc_name"></p>
                        <p class="text-[10px] text-slate-400 font-medium truncate" id="pay_tc_unit"></p>
                    </div>
                    <div class="text-right shrink-0">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Monthly Rent</p>
                        <p class="font-black text-slate-700 dark:text-slate-200 text-sm" id="pay_tc_rent"></p>
                    </div>
                </div>
                <div class="grid grid-cols-2 divide-x divide-slate-100 dark:divide-slate-700">
                    <div class="px-4 py-3">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Rent Arrears</p>
                        <p class="font-black text-sm" id="pay_tc_arrears"></p>
                    </div>
                    <div class="px-4 py-3">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Property</p>
                        <p class="font-bold text-slate-600 dark:text-slate-400 text-xs truncate" id="pay_tc_property"></p>
                    </div>
                </div>

                <!-- What this payment does to the balance -->
                <div class="grid grid-cols-2 divide-x" style="border-top:1px solid var(--border);background:var(--surface-sunk);">
                    <div class="px-4 py-3">
                        <label for="pay_tc_paying" class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5 block">Paying Now</label>
                        <div class="flex items-center gap-1.5">
                            <span class="text-[11px] shrink-0" style="color:var(--text-subtle)"><?php echo htmlspecialchars($currency); ?></span>
                            <input type="number" id="pay_tc_paying" step="0.01" min="0" placeholder="0.00"
                                   class="form-input tabular" style="padding:4px 8px;font-size:13px;font-weight:600;"
                                   oninput="allocSpread(this.value)">
                        </div>
                        <p class="text-[10px] mt-1" style="color:var(--text-subtle)">Type the total handed over &mdash; it spreads across the charges below</p>
                    </div>
                    <div class="px-4 py-3">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Balance After</p>
                        <p class="font-black text-sm tabular" id="pay_tc_after">&mdash;</p>
                    </div>
                </div>
            </div>

            <!-- ── What the payment is for ──────────────────────────── -->
            <div class="space-y-2">
                <div class="flex items-baseline justify-between px-1">
                    <label class="section-label">What is this payment for?</label>
                    <span class="text-[11px]" style="color:var(--text-subtle)" id="pay_alloc_hint">Select a tenant to load outstanding charges</span>
                </div>

                <div class="rounded-xl overflow-hidden" style="border:1px solid var(--border);">
                    <div class="grid items-center gap-2 px-3 py-2" style="grid-template-columns:1fr 150px 120px 28px;background:var(--surface-sunk);border-bottom:1px solid var(--border);">
                        <span class="text-[11px]" style="color:var(--text-muted)">Charge</span>
                        <span class="text-[11px]" style="color:var(--text-muted)">For which month</span>
                        <span class="text-[11px] text-right" style="color:var(--text-muted)">Amount (<?php echo htmlspecialchars($currency); ?>)</span>
                        <span></span>
                    </div>

                    <div id="pay_alloc_rows"></div>

                    <div class="flex items-center justify-between px-3 py-2" style="border-top:1px solid var(--border);background:var(--surface-sunk);">
                        <button type="button" onclick="allocAddRow()" class="btn-ghost" style="padding:4px 9px;font-size:11.5px;">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
                            Add charge
                        </button>
                        <div class="text-right">
                            <span class="text-[11px]" style="color:var(--text-muted)">Total received</span>
                            <span class="ml-2 text-[15px] font-semibold tabular" id="pay_alloc_total" style="color:var(--text)"><?php echo htmlspecialchars($currency); ?> 0.00</span>
                        </div>
                    </div>
                </div>
                <p class="text-[11px] px-1" id="pay_alloc_warn" style="color:var(--warning);display:none;"></p>
            </div>
            <div class="grid grid-cols-2 gap-4">
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
                    <input type="date" name="transaction_date" value="<?php echo date('Y-m-d'); ?>"
                        class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                </div>
            </div>

            <?php echo renderBankAccountSelect($pdo, [
                'id'          => 'pay_bank_account',
                'label'       => 'Deposited To',
                'label_class' => 'text-[10px] font-black text-slate-400 uppercase tracking-widest px-2',
                'class'       => 'w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none',
            ]); ?>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Notes / Reference</label>
                <textarea name="description" rows="2" placeholder="M-Pesa ref, bank slip no., etc."
                    class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none resize-none"></textarea>
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="payCancel()" class="btn-ghost" style="padding:12px 20px;">Cancel</button>
                <button type="submit" class="btn-green flex-1 justify-center py-4">Confirm Payment Receipt</button>
            </div>
        </form>
    </div>
</div>

<script>
const _tenantPayData = <?php echo json_encode($tenantPayMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const _payOutstanding = <?php echo json_encode($outstandingMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const _payChargeTypes = <?php echo json_encode(PAYMENT_CHARGE_TYPES); ?>;
const _payCurrency   = '<?php echo addslashes($currency); ?>';

/* ══════════════════════════════════════════════════════════════════════
   PAYMENT ALLOCATION
   A tenant hands over one sum that settles several charges. These rows say
   which charge got what, so the books keep the breakdown.
   ══════════════════════════════════════════════════════════════════════ */
(function () {
  var rowsEl = document.getElementById('pay_alloc_rows');
  if (!rowsEl) return;

  var currentTenantId = '';

  var money = function (v) {
    return _payCurrency + ' ' + (parseFloat(v) || 0)
      .toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  };

  function esc(v) {
    return String(v == null ? '' : v)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

  /** "Aug 2026" from a YYYY-MM-DD due date. */
  function monthLabel(dateStr) {
    if (!dateStr) return '';
    var parts = String(dateStr).split('-');
    if (parts.length < 2) return dateStr;
    var m = parseInt(parts[1], 10) - 1;
    return (MONTHS[m] || parts[1]) + ' ' + parts[0];
  }

  /**
   * Which outstanding months a charge can be applied to. Staff pick the month
   * so a payment settles the invoice it was actually meant for, rather than
   * whichever the system guessed.
   */
  function monthOptions(type, selectedInvoice) {
    var lines = (_payOutstanding[currentTenantId] || []).filter(function (l) {
      return l.type === type;
    });

    var html = '';
    lines.forEach(function (l) {
      var sel = (l.invoice_id === selectedInvoice) ? ' selected' : '';
      html += '<option value="' + esc(l.invoice_id) + '" data-balance="' + esc(l.balance) + '"' + sel + '>' +
                esc(monthLabel(l.due_date)) + ' · ' + money(l.balance) +
              '</option>';
    });
    // Money can also arrive ahead of any invoice
    html += '<option value=""' + (selectedInvoice ? '' : ' selected') + '>Not tied to a month</option>';
    return html;
  }

  function rowHtml(type, amount, invoiceId, note) {
    var opts = _payChargeTypes.map(function (t) {
      return '<option value="' + esc(t) + '"' + (t === type ? ' selected' : '') + '>' + esc(t) + '</option>';
    }).join('');

    return '' +
      '<div class="alloc-row grid items-center gap-2 px-3 py-2" style="grid-template-columns:1fr 150px 120px 28px;border-bottom:1px solid var(--border);">' +
        '<div class="min-w-0">' +
          '<select name="alloc_type[]" class="form-input" style="padding:5px 8px;font-size:12.5px;" onchange="allocTypeChanged(this)">' + opts + '</select>' +
          (note ? '<p class="text-[10.5px] mt-1 truncate" style="color:var(--text-subtle)">' + esc(note) + '</p>' : '') +
        '</div>' +
        '<select name="alloc_invoice[]" class="form-input alloc-month" style="padding:5px 8px;font-size:12.5px;" onchange="allocMonthChanged(this)">' +
          monthOptions(type, invoiceId || '') +
        '</select>' +
        '<input type="number" name="alloc_amount[]" step="0.01" min="0" placeholder="0.00" ' +
               'value="' + (amount != null ? esc(amount) : '') + '" ' +
               'class="form-input text-right tabular" style="padding:5px 8px;font-size:12.5px;" oninput="allocRecalc()">' +
        '<button type="button" class="topbar-btn" style="width:24px;height:24px;" onclick="allocRemoveRow(this)" aria-label="Remove charge">' +
          '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>' +
        '</button>' +
      '</div>';
  }

  /** Changing the charge reloads the months that charge is outstanding for. */
  window.allocTypeChanged = function (sel) {
    var row   = sel.closest('.alloc-row');
    var month = row.querySelector('.alloc-month');
    month.innerHTML = monthOptions(sel.value, '');
    allocMonthChanged(month);
  };

  /** Picking a month offers that invoice's outstanding balance as the amount. */
  window.allocMonthChanged = function (sel) {
    var row = sel.closest('.alloc-row');
    var amt = row.querySelector('input[name="alloc_amount[]"]');
    var opt = sel.options[sel.selectedIndex];
    var bal = opt ? parseFloat(opt.dataset.balance) : NaN;

    // Only fill a blank or untouched box — never overwrite a typed figure
    if (!isNaN(bal) && (amt.value === '' || amt.dataset.auto === '1')) {
      amt.value = bal.toFixed(2);
      amt.dataset.auto = '1';
    }
    allocRecalc();
  };

  window.allocAddRow = function (type, amount, invoiceId, note) {
    rowsEl.insertAdjacentHTML('beforeend', rowHtml(type || 'Rent', amount, invoiceId, note));
    allocRecalc();
  };

  window.allocRemoveRow = function (btn) {
    var row = btn.closest('.alloc-row');
    if (!row) return;

    var amt  = row.querySelector('input[name="alloc_amount[]"]');
    var type = row.querySelector('select[name="alloc_type[]"]');
    var val  = parseFloat(amt && amt.value) || 0;

    // Only interrupt when there is something to lose
    if (val > 0) {
      var label = type ? type.options[type.selectedIndex].text : 'this charge';
      if (!confirm('Remove ' + label + ' of ' + money(val) + ' from this payment?')) return;
    }

    row.remove();
    if (!rowsEl.querySelector('.alloc-row')) window.allocAddRow('Rent');
    allocRecalc();
  };

  // Guard so the two inputs do not chase each other
  var spreading = false;

  /**
   * Take the sum a tenant actually handed over and apportion it across the
   * charges, oldest first — which is how arrears are normally cleared.
   * Anything beyond what is owed lands on the first charge as an advance.
   */
  window.allocSpread = function (raw) {
    var pot  = parseFloat(raw) || 0;
    var rows = Array.prototype.slice.call(rowsEl.querySelectorAll('.alloc-row'));
    if (!rows.length) return;

    spreading = true;

    rows.forEach(function (row) {
      var amt   = row.querySelector('input[name="alloc_amount[]"]');
      var month = row.querySelector('.alloc-month');
      var opt   = month ? month.options[month.selectedIndex] : null;
      var owed  = opt ? parseFloat(opt.dataset.balance) : NaN;

      if (pot <= 0.009) { amt.value = ''; amt.dataset.auto = '1'; return; }

      // A charge tied to a month takes at most what that month still owes;
      // one not tied to a month can absorb whatever is left.
      var take = isNaN(owed) ? pot : Math.min(pot, owed);
      take = Math.round(take * 100) / 100;

      amt.value = take > 0 ? take.toFixed(2) : '';
      amt.dataset.auto = '1';
      pot = Math.round((pot - take) * 100) / 100;
    });

    // More than everything owed — the surplus rides on the first charge
    if (pot > 0.009) {
      var first = rows[0].querySelector('input[name="alloc_amount[]"]');
      first.value = ((parseFloat(first.value) || 0) + pot).toFixed(2);
      first.dataset.auto = '1';
    }

    spreading = false;
    allocRecalc(true);
  };

  window.allocRecalc = function (fromSpread) {
    var total = 0;
    rowsEl.querySelectorAll('input[name="alloc_amount[]"]').forEach(function (i) {
      total += parseFloat(i.value) || 0;
    });
    document.getElementById('pay_alloc_total').textContent = money(total);

    // Mirror the effect on the tenant card, so the new balance is visible
    // while the split is being entered
    var owedNow  = parseFloat(rowsEl.dataset.owed || '0');
    var payingEl = document.getElementById('pay_tc_paying');
    var afterEl  = document.getElementById('pay_tc_after');
    if (payingEl && afterEl) {
      // Only mirror the rows back up when the change came from the rows,
      // otherwise the field rewrites itself mid-keystroke
      if (!fromSpread && document.activeElement !== payingEl) {
        payingEl.value = total > 0 ? total.toFixed(2) : '';
      }
      var after = owedNow - total;
      afterEl.textContent = money(Math.abs(after) < 0.005 ? 0 : after);
      afterEl.style.color = after > 0.009 ? 'var(--danger)'
                          : (after < -0.009 ? 'var(--info)' : 'var(--positive)');
      afterEl.title = after < -0.009 ? 'Overpayment — will sit as credit' : '';
    }

    // Flag when the split exceeds what is actually outstanding — overpaying is
    // allowed (it becomes credit), but it should never be silent.
    var warn = document.getElementById('pay_alloc_warn');
    var owed = parseFloat(rowsEl.dataset.owed || '0');
    if (owed > 0 && total > owed + 0.009) {
      warn.textContent = 'This is ' + money(total - owed) + ' more than the ' +
                         money(owed) + ' currently outstanding.';
      warn.style.display = '';
    } else {
      warn.style.display = 'none';
    }
    return total;
  };

  window.allocLoadForTenant = function (tenantId, tenant) {
    currentTenantId = tenantId;
    var payingEl = document.getElementById('pay_tc_paying');
    if (payingEl) payingEl.value = '';
    var lines = _payOutstanding[tenantId] || [];
    var hint  = document.getElementById('pay_alloc_hint');
    rowsEl.innerHTML = '';

    if (lines.length) {
      var owed = 0;
      lines.forEach(function (l) {
        owed += parseFloat(l.balance) || 0;
        var due = l.due_date ? new Date(l.due_date + 'T00:00:00') : null;
        var note = 'Invoice due ' + (due
          ? due.toLocaleDateString('en-KE', { day: 'numeric', month: 'short', year: 'numeric' })
          : '—') + (l.overdue ? ' · overdue' : '');
        window.allocAddRow(l.type, (parseFloat(l.balance) || 0).toFixed(2), l.invoice_id, note);
      });
      rowsEl.dataset.owed = owed.toFixed(2);
      hint.textContent = lines.length + ' outstanding charge' + (lines.length === 1 ? '' : 's') +
                         ' · ' + money(owed) + ' owed';
    } else {
      // Nothing outstanding — offer a rent line at the lease amount as a start
      rowsEl.dataset.owed = '0';
      window.allocAddRow('Rent', tenant && tenant.monthly_rent ? tenant.monthly_rent.toFixed(2) : '');
      hint.textContent = 'No outstanding invoices — enter the charges manually';
    }
    allocRecalc();
  };

  // Start with one empty row so the form is never blank
  window.allocAddRow('Rent');
})();

/**
 * Abandoning a part-entered payment loses work, so it is confirmed first.
 * A blank form closes without comment — there is nothing to lose.
 */
function payCancel() {
    var entered = false;

    document.querySelectorAll('#pay_alloc_rows input[name="alloc_amount[]"]').forEach(function (i) {
        if ((parseFloat(i.value) || 0) > 0) entered = true;
    });
    var sel = document.getElementById('pay_tenant_sel');
    if (sel && sel.value) entered = true;

    if (entered && !confirm('Discard this payment? Nothing entered here has been saved.')) return;

    var form = document.querySelector('#recordPaymentModal form');
    if (form) form.reset();
    var rows = document.getElementById('pay_alloc_rows');
    if (rows) { rows.innerHTML = ''; rows.dataset.owed = '0'; }
    var paying = document.getElementById('pay_tc_paying');
    if (paying) paying.value = '';
    var card = document.getElementById('pay_tenant_card');
    if (card) card.classList.add('hidden');
    if (window.allocAddRow) window.allocAddRow('Rent');

    closeModal('recordPaymentModal');
}

function onPayTenantChange(id) {
    const card = document.getElementById('pay_tenant_card');
    if (!id || !_tenantPayData[id]) { card.classList.add('hidden'); return; }
    const t = _tenantPayData[id];
    const fmt = v => _payCurrency + ' ' + parseFloat(v).toLocaleString('en-KE', {minimumFractionDigits: 2, maximumFractionDigits: 2});

    document.getElementById('pay_tc_avatar').textContent   = t.name[0].toUpperCase();
    document.getElementById('pay_tc_name').textContent     = t.name;
    document.getElementById('pay_tc_unit').textContent     = t.unit_number !== '—' ? 'Unit ' + t.unit_number + ' · ' + t.property : t.property;
    document.getElementById('pay_tc_rent').textContent     = fmt(t.monthly_rent);
    document.getElementById('pay_tc_property').textContent = t.property;

    const arrearsEl = document.getElementById('pay_tc_arrears');
    arrearsEl.textContent  = fmt(t.arrears);
    arrearsEl.className    = 'font-black text-sm ' + (t.arrears > 0 ? 'text-red-500' : 'text-green-500');

    allocLoadForTenant(id, t);

    card.classList.remove('hidden');
}
</script>

<!-- New Invoice Modal -->
<div id="newInvoiceModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <button onclick="closeModal('newInvoiceModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-lg font-semibold mb-1" style="color:var(--text)">Generate Invoices</h2>
        <p class="text-[12.5px] mb-5" style="color:var(--text-muted)">
            Bill a whole property unit by unit, or raise a single charge for one tenant.
        </p>

        <!-- ── Billing run: the normal way to invoice ──────────────── -->
        <p class="section-label mb-2">Billing run</p>
        <?php if (!$runProperties): ?>
        <div class="rounded-xl px-4 py-5 text-center" style="border:1px solid var(--border);">
            <p class="text-[12.5px]" style="color:var(--text-muted)">No property has active tenants to bill yet.</p>
        </div>
        <?php else: ?>
        <div class="rounded-xl overflow-hidden" style="border:1px solid var(--border);">
            <?php foreach ($runProperties as $rp):
                $rprog = billingRunProgress($pdo, (string)$rp['id'], $runPeriod);
                $rpct  = $rprog['total'] ? round($rprog['billed'] / $rprog['total'] * 100) : 0;
                $rdone = $rprog['remaining'] === 0;
            ?>
            <a href="billing_run.php?property_id=<?php echo urlencode((string)$rp['id']); ?>&i=0"
               class="flex items-center gap-3 px-4 py-3 transition-colors"
               style="border-bottom:1px solid var(--border);"
               onmouseover="this.style.background='var(--surface-hover)'"
               onmouseout="this.style.background='transparent'">
                <div class="flex-1 min-w-0">
                    <p class="text-[13px] font-medium truncate" style="color:var(--text)"><?php echo htmlspecialchars((string)$rp['title']); ?></p>
                    <p class="text-[11px] truncate" style="color:var(--text-subtle)">
                        <?php echo htmlspecialchars((string)$rp['location']); ?>
                        &middot; water <?php echo $currency; ?> <?php echo number_format((float)$rp['water_rate'], 2); ?>/unit
                        &middot; garbage <?php echo $currency; ?> <?php echo number_format((float)$rp['garbage_fee'], 2); ?>
                    </p>
                    <div class="progress mt-1.5" style="max-width:180px;">
                        <div class="progress-fill" style="width:<?php echo $rpct; ?>%"></div>
                    </div>
                </div>
                <div class="text-right shrink-0">
                    <span class="badge <?php echo $rdone ? 'badge-green' : 'badge-orange'; ?>">
                        <?php echo $rprog['billed']; ?>/<?php echo $rprog['total']; ?> billed
                    </span>
                    <p class="text-[10.5px] mt-1" style="color:var(--text-subtle)">
                        <?php echo $rdone ? 'Done for ' . date('M') : $rprog['remaining'] . ' to go'; ?>
                    </p>
                </div>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="shrink-0" style="color:var(--text-subtle)"><path d="m9 18 6-6-6-6"/></svg>
            </a>
            <?php endforeach; ?>
        </div>
        <p class="text-[11px] mt-2 px-1" style="color:var(--text-subtle)">
            Each tenant's arrears and last water reading are shown as you go, and charges are raised as one combined invoice.
        </p>
        <?php endif; ?>

        <!-- ── One-off charge ─────────────────────────────────────── -->
        <div class="mt-5 pt-4" style="border-top:1px solid var(--border);">
            <button type="button" onclick="toggleSingleInvoice()" id="singleInvToggle"
                    class="flex items-center gap-2 text-[12.5px] font-medium" style="color:var(--text-muted)">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" id="singleInvCaret" style="transition:transform .15s"><path d="m9 18 6-6-6-6"/></svg>
                Raise a single charge for one tenant instead
            </button>

            <form action="actions/financial_actions.php" method="POST" class="space-y-4 mt-4 hidden" id="singleInvForm">
                <input type="hidden" name="action" value="generate_invoice">
                <div class="space-y-1.5">
                    <label class="text-[12px] font-medium" style="color:var(--text-muted)">Tenant</label>
                    <select name="tenant_id" required class="form-input">
                        <option value="">Select tenant…</option>
                        <?php foreach ($tenants as $t): ?>
                            <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars((string)$t['full_name']); ?> (<?php echo htmlspecialchars((string)$t['unit_number']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="text-[12px] font-medium" style="color:var(--text-muted)">Amount (<?php echo htmlspecialchars($currency); ?>)</label>
                        <input type="number" name="amount" step="0.01" min="0" required class="form-input text-right tabular">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[12px] font-medium" style="color:var(--text-muted)">Charge</label>
                        <select name="invoice_type" class="form-input">
                            <option>Penalty</option>
                            <option>Water</option>
                            <option>Rent</option>
                            <option>Garbage</option>
                            <option>Service Charge</option>
                            <option>Deposit</option>
                            <option>Other</option>
                        </select>
                    </div>
                </div>
                <div class="space-y-1.5">
                    <label class="text-[12px] font-medium" style="color:var(--text-muted)">Due date</label>
                    <input type="date" name="due_date" value="<?php echo date('Y-m-d', strtotime('+' . max(1, (int)getSetting($pdo, 'invoice_due_days', '7')) . ' days')); ?>" class="form-input">
                </div>
                <button type="submit" class="btn-primary w-full justify-center" style="padding:10px;">Raise invoice</button>
            </form>
        </div>
    </div>
</div>

<script>
function toggleSingleInvoice() {
    var form  = document.getElementById('singleInvForm');
    var caret = document.getElementById('singleInvCaret');
    var open  = form.classList.toggle('hidden') === false;
    caret.style.transform = open ? 'rotate(90deg)' : '';
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
