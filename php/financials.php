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

// Fetch category-wise balances for tenants
$balancesBreakdown = [
    'Rent' => 0,
    'Water' => 0,
    'Garbage' => 0,
    'Deposit' => 0,
    'Other' => 0,
    'Previous' => 0
];
if ($role === 'tenant' && $tenantId) {
    $currentMonthStart = date('Y-m-01');
    $balStmt = $pdo->prepare("
        SELECT 
            i.invoice_type,
            i.created_at,
            (i.amount - COALESCE(t.paid_amount, 0)) as outstanding
        FROM invoices i
        LEFT JOIN (
            SELECT invoice_id, SUM(amount) as paid_amount 
            FROM transactions 
            WHERE status = 'Paid' 
            GROUP BY invoice_id
        ) t ON i.id = t.invoice_id
        WHERE i.tenant_id = ? AND i.status != 'Paid'
    ");
    $balStmt->execute([$tenantId]);
    $allOutstanding = $balStmt->fetchAll();
    
    foreach ($allOutstanding as $inv) {
        $isPrevious = strtotime($inv['created_at']) < strtotime($currentMonthStart);
        if ($isPrevious) {
            $balancesBreakdown['Previous'] += (float)$inv['outstanding'];
        } else {
            $type = $inv['invoice_type'];
            if (isset($balancesBreakdown[$type])) {
                $balancesBreakdown[$type] += (float)$inv['outstanding'];
            } else {
                $balancesBreakdown['Other'] += (float)$inv['outstanding'];
            }
        }
    }
}
$totalBalance = array_sum($balancesBreakdown);

// ── Fetch invoices for tenant invoice list ─────────────────────────────
$tenantInvoices = [];
if ($role === 'tenant' && !empty($tenantId)) {
    $invStmt = $pdo->prepare("
        SELECT i.*, l.start_date as lease_start
        FROM invoices i
        LEFT JOIN leases l ON i.lease_id = l.id
        WHERE i.tenant_id = ?
        ORDER BY
            FIELD(i.status, 'Overdue', 'Unpaid', 'Paid'),
            i.due_date ASC
        LIMIT 60
    ");
    $invStmt->execute([$tenantId]);
    $tenantInvoices = $invStmt->fetchAll();
}

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
        <?php 
            if ($_GET['success'] === 'payment_submitted') echo "Payment notification sent for admin confirmation!";
            else echo "Transaction recorded successfully!";
        ?>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
    <div class="p-4 bg-red-500/10 border border-red-500/20 text-red-500 rounded-xl font-bold text-sm animate-in fade-in slide-in-from-top-4">
        Error: <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
    <?php endif; ?>

    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight"><?php echo $role === 'landlord' ? 'Financial Income' : 'Financial Overview'; ?></h1>
            <p class="text-slate-500 font-medium"><?php echo $role === 'landlord' ? 'Overview of earnings from your units.' : 'Track your payments, invoices, and revenue.'; ?></p>
        </div>
        <div class="flex items-center gap-3">
            <?php if ($role !== 'tenant'): ?>
                <a href="tenant_payments.php" class="px-5 py-3 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl font-bold text-sm shadow-sm hover:shadow-md transition-all flex items-center gap-2">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Tenant Statements
                </a>
                <button onclick="openModal('newTransactionModal')" class="btn-primary">
                    + New Transaction
                </button>
            <?php else: ?>
                <a href="view_statement.php?tenant_id=<?php echo $tenantId; ?>" class="px-5 py-3 bg-slate-900 text-white dark:bg-slate-50 dark:text-slate-900 rounded-2xl font-bold text-sm shadow-lg hover:scale-105 transition-all flex items-center gap-2">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    My Financial Statement
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($role === 'tenant'): ?>
    <!-- Balance Statement Card -->
    <div class="glass-card overflow-hidden border-none shadow-2xl">
        <div class="grid grid-cols-1 lg:grid-cols-3">
            <!-- Breakdown Section -->
            <div class="p-8 lg:p-10 lg:col-span-2">
                <div class="flex items-center gap-3 mb-8">
                    <div class="w-10 h-10 rounded-xl bg-accent-green/10 flex items-center justify-center text-accent-green">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 12V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h7"/><path d="M16 19h6"/><path d="M19 16v6"/><circle cx="16" cy="12" r="2"/></svg>
                    </div>
                    <div>
                        <h3 class="text-xl font-black text-slate-900 dark:text-white leading-tight">Balance Statement</h3>
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Detailed monthly breakdown</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-y-6 gap-x-12">
                    <div class="flex justify-between items-center pb-2 border-b border-slate-100 dark:border-slate-800">
                        <span class="text-sm font-bold text-slate-500">Rent Balance</span>
                        <span class="text-sm font-black text-slate-900 dark:text-white">KSh <?php echo number_format($balancesBreakdown['Rent']); ?></span>
                    </div>
                    <div class="flex justify-between items-center pb-2 border-b border-slate-100 dark:border-slate-800">
                        <span class="text-sm font-bold text-slate-500">Water Bill</span>
                        <span class="text-sm font-black text-slate-900 dark:text-white">KSh <?php echo number_format($balancesBreakdown['Water']); ?></span>
                    </div>
                    <div class="flex justify-between items-center pb-2 border-b border-slate-100 dark:border-slate-800">
                        <span class="text-sm font-bold text-slate-500">Garbage Fee</span>
                        <span class="text-sm font-black text-slate-900 dark:text-white">KSh <?php echo number_format($balancesBreakdown['Garbage']); ?></span>
                    </div>
                    <div class="flex justify-between items-center pb-2 border-b border-slate-100 dark:border-slate-800">
                        <span class="text-sm font-bold text-slate-500">Previous Balance</span>
                        <span class="text-sm font-black text-red-500">KSh <?php echo number_format($balancesBreakdown['Previous']); ?></span>
                    </div>
                    <div class="flex justify-between items-center pb-2 border-b border-slate-100 dark:border-slate-800">
                        <span class="text-sm font-bold text-slate-500">Security Deposit</span>
                        <span class="text-sm font-black text-slate-900 dark:text-white">KSh <?php echo number_format($balancesBreakdown['Deposit']); ?></span>
                    </div>
                    <div class="flex justify-between items-center pb-2 border-b border-slate-100 dark:border-slate-800">
                        <span class="text-sm font-bold text-slate-500">Other Utilities</span>
                        <span class="text-sm font-black text-slate-900 dark:text-white">KSh <?php echo number_format($balancesBreakdown['Other']); ?></span>
                    </div>
                </div>
            </div>

            <!-- Total Section -->
            <div class="bg-slate-900 dark:bg-slate-950 p-8 lg:p-10 flex flex-col justify-center items-center text-center relative overflow-hidden">
                <div class="relative z-10">
                    <p class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] mb-2">Total Outstanding</p>
                    <h2 class="text-4xl font-black text-white mb-8">KSh <?php echo number_format($totalBalance); ?></h2>
                    <button onclick="togglePaymentCenter()" class="w-full bg-accent-green hover:bg-green-400 text-slate-950 font-black py-4 px-8 rounded-2xl transition-all shadow-lg shadow-green-500/20 active:scale-95 flex items-center justify-center gap-2">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M12 2v20M2 12h20"/></svg>
                        PAY NOW
                    </button>
                </div>
                <!-- Decorative Circle -->
                <div class="absolute top-0 right-0 w-32 h-32 bg-accent-green/10 rounded-full blur-2xl -translate-y-1/2 translate-x-1/2"></div>
            </div>
        </div>
    </div>

    <!-- Payment Center (Initially Hidden) -->
    <div id="paymentCenterSection" style="display:none;" class="glass-card p-6 lg:p-8 overflow-hidden relative animate-in slide-in-from-top-4 duration-500">
        <div class="relative z-10">
            <div class="flex justify-between items-center mb-8">
                <div>
                    <h3 class="text-xl font-black text-slate-900 dark:text-white mb-1">Select Payment Method</h3>
                    <p class="text-sm text-slate-500 font-medium">Choose how you want to settle your KSh <?php echo number_format($totalBalance); ?> balance.</p>
                </div>
                <button onclick="togglePaymentCenter()" class="text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- M-Pesa STK -->
                <button onclick="openModal('mpesaModal')" class="flex items-center gap-4 p-4 bg-green-50 dark:bg-green-900/20 rounded-2xl border border-green-500/10 hover:border-green-500/30 transition-all text-left group">
                    <div class="w-12 h-12 rounded-xl bg-green-500 flex items-center justify-center text-white shrink-0 group-hover:scale-110 transition-transform">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
                    </div>
                    <div>
                        <p class="text-xs font-black text-green-600 uppercase tracking-widest">M-Pesa STK</p>
                        <p class="text-[10px] text-slate-500 font-bold">Instant Push Request</p>
                    </div>
                </button>

                <!-- Bank Transfer -->
                <button onclick="openModal('bankModal')" class="flex items-center gap-4 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-2xl border border-blue-500/10 hover:border-blue-500/30 transition-all text-left group">
                    <div class="w-12 h-12 rounded-xl bg-blue-600 flex items-center justify-center text-white shrink-0 group-hover:scale-110 transition-transform">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 21h18M3 10h18M5 10v11M19 10v11M12 10v11M7 10v11M17 10v11M12 2L3 10h18z"/></svg>
                    </div>
                    <div>
                        <p class="text-xs font-black text-blue-600 uppercase tracking-widest">Bank Transfer</p>
                        <p class="text-[10px] text-slate-500 font-bold">Direct EFT / RTGS</p>
                    </div>
                </button>

                <!-- Cheque Payment -->
                <button onclick="openModal('chequeModal')" class="flex items-center gap-4 p-4 bg-purple-50 dark:bg-purple-900/20 rounded-2xl border border-purple-500/10 hover:border-purple-500/30 transition-all text-left group">
                    <div class="w-12 h-12 rounded-xl bg-purple-600 flex items-center justify-center text-white shrink-0 group-hover:scale-110 transition-transform">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M2 3h20v18H2zM2 8h20M7 13h10M7 17h5"/></svg>
                    </div>
                    <div>
                        <p class="text-xs font-black text-purple-600 uppercase tracking-widest">Cheque</p>
                        <p class="text-[10px] text-slate-500 font-bold">Upload Deposit Slip</p>
                    </div>
                </button>

                <!-- Paybill -->
                <button onclick="openModal('paybillModal')" class="flex items-center gap-4 p-4 bg-orange-50 dark:bg-orange-900/20 rounded-2xl border border-orange-500/10 hover:border-orange-500/30 transition-all text-left group">
                    <div class="w-12 h-12 rounded-xl bg-orange-500 flex items-center justify-center text-white shrink-0 group-hover:scale-110 transition-transform">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M7 7h10M7 12h10M7 17h10"/></svg>
                    </div>
                    <div>
                        <p class="text-xs font-black text-orange-600 uppercase tracking-widest">Manual Paybill</p>
                        <p class="text-[10px] text-slate-500 font-bold">Using 1234567</p>
                    </div>
                </button>
            </div>
        </div>
    </div>

    <script>
        function togglePaymentCenter() {
            const section = document.getElementById('paymentCenterSection');
            if (section.style.display === 'none') {
                section.style.display = 'block';
                section.scrollIntoView({ behavior: 'smooth' });
            } else {
                section.style.display = 'none';
            }
        }
    </script>
    <?php else: ?>
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
    <?php endif; ?>

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

    <?php if ($role === 'tenant' && !empty($tenantInvoices)): ?>
    <!-- ── My Invoices (Tenant View) ───────────────────────────────── -->
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div>
                <h3 class="text-lg font-black text-slate-900 dark:text-white tracking-tight">My Invoices</h3>
                <p class="text-[11px] text-slate-400 font-medium mt-0.5">All charges issued to your account — click to view or print any invoice.</p>
            </div>
            <a href="view_statement.php" class="inline-flex items-center gap-2 px-4 py-2 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl text-[11px] font-black uppercase tracking-widest hover:opacity-80 transition-opacity shrink-0">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Full Statement
            </a>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Due Date</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $invPrefix = getSetting($pdo, 'invoice_prefix', 'INV');
                    foreach ($tenantInvoices as $inv):
                        $isPaid     = $inv['status'] === 'Paid';
                        $isOverdue  = $inv['status'] === 'Overdue';
                        $isUnpaid   = $inv['status'] === 'Unpaid';
                        $statusBadge = $isPaid ? 'badge-green' : ($isOverdue ? 'badge-red' : 'badge-orange');
                        $isPastDue   = !$isPaid && strtotime($inv['due_date']) < time();
                    ?>
                    <tr class="<?php echo $isOverdue ? 'bg-red-50/30 dark:bg-red-900/5' : ''; ?>">
                        <td>
                            <div class="font-black text-slate-900 dark:text-white text-sm">
                                <?php echo $invPrefix; ?>-<?php echo strtoupper(substr($inv['id'], 0, 8)); ?>
                            </div>
                            <div class="text-[10px] text-slate-400">Issued <?php echo date('M j, Y', strtotime($inv['created_at'])); ?></div>
                        </td>
                        <td>
                            <div class="flex items-center gap-2">
                                <?php
                                $typeColors = [
                                    'Rent'          => 'bg-blue-50 dark:bg-blue-900/20 text-blue-500',
                                    'Water'         => 'bg-cyan-50 dark:bg-cyan-900/20 text-cyan-500',
                                    'Garbage'       => 'bg-orange-50 dark:bg-orange-900/20 text-orange-500',
                                    'Electricity'   => 'bg-yellow-50 dark:bg-yellow-900/20 text-yellow-600',
                                    'Deposit'       => 'bg-indigo-50 dark:bg-indigo-900/20 text-indigo-500',
                                    'Service Charge'=> 'bg-purple-50 dark:bg-purple-900/20 text-purple-500',
                                ];
                                $tc = $typeColors[$inv['invoice_type']] ?? 'bg-slate-50 dark:bg-slate-800 text-slate-500';
                                ?>
                                <span class="px-2 py-0.5 rounded-lg text-[10px] font-black uppercase <?php echo $tc; ?>">
                                    <?php echo htmlspecialchars($inv['invoice_type']); ?>
                                </span>
                            </div>
                        </td>
                        <td class="font-black text-slate-900 dark:text-white">
                            KSh <?php echo number_format($inv['amount']); ?>
                        </td>
                        <td>
                            <div class="text-sm font-bold <?php echo $isPastDue && !$isPaid ? 'text-red-500' : 'text-slate-600 dark:text-slate-400'; ?>">
                                <?php echo date('M j, Y', strtotime($inv['due_date'])); ?>
                            </div>
                            <?php if ($isPastDue && !$isPaid): ?>
                            <div class="text-[10px] text-red-500 font-black">
                                <?php echo (int)ceil((time() - strtotime($inv['due_date'])) / 86400); ?>d overdue
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?php echo $statusBadge; ?>"><?php echo $inv['status']; ?></span></td>
                        <td class="text-right">
                            <a href="view_invoice.php?id=<?php echo $inv['id']; ?>" target="_blank"
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-900 dark:hover:bg-white hover:text-white dark:hover:text-slate-900 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                                View
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($tenantInvoices) >= 60): ?>
        <div class="p-4 text-center border-t border-slate-100 dark:border-slate-800">
            <a href="view_statement.php" class="text-[11px] font-black text-accent-green hover:opacity-70 uppercase tracking-widest">View full statement for all invoices →</a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

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
                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Receipt</th>
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
                                    <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest"><?php echo date('M d, Y', strtotime($tr['transaction_date'])); ?></p>
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
                            <span class="px-3 py-1 <?php 
                                echo $tr['status'] == 'Paid' ? 'bg-green-500/10 text-green-500' : 
                                    ($tr['status'] == 'Pending' ? 'bg-blue-500/10 text-blue-500' : 'bg-orange-500/10 text-orange-500'); 
                            ?> rounded-full text-[10px] font-black uppercase tracking-widest">
                                <?php echo htmlspecialchars($tr['status']); ?>
                            </span>
                        </td>
                        <td class="p-6 text-right">
                            <a href="receipt.php?id=<?php echo $tr['id']; ?>" class="inline-flex items-center gap-2 px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-900 dark:hover:bg-white hover:text-white dark:hover:text-slate-900 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
                                View
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($role === 'tenant'): ?>
<!-- Payment Modals for Tenants -->

<!-- M-Pesa Modal -->
<div id="mpesaModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <button onclick="closeModal('mpesaModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-4">Pay with M-Pesa</h2>
        <p class="text-xs text-slate-500 mb-6">Enter your phone number below. You will receive an STK push on your phone to enter your M-Pesa PIN.</p>
        <form action="actions/financial_actions.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
            <input type="hidden" name="payment_method" value="M-Pesa STK">
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Phone Number</label>
                <input type="text" name="phone" placeholder="2547XXXXXXXX" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Amount to Pay (KSh)</label>
                <input type="number" name="amount" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
            </div>
            <button type="submit" class="btn-green w-full justify-center py-4">Initiate STK Push</button>
        </form>
    </div>
</div>

<!-- Bank Transfer Modal -->
<div id="bankModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <button onclick="closeModal('bankModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-4">Bank Transfer</h2>
        <div class="bg-blue-50 dark:bg-blue-900/10 p-4 rounded-xl mb-6">
            <p class="text-[10px] font-black text-blue-600 uppercase mb-2">Account Details</p>
            <p class="text-xs font-bold text-slate-700 dark:text-slate-300">Bank: Primelink National Bank</p>
            <p class="text-xs font-bold text-slate-700 dark:text-slate-300">Acc Name: Primelink Management System</p>
            <p class="text-xs font-bold text-slate-700 dark:text-slate-300">Acc No: 1234 5678 9012</p>
            <p class="text-xs font-bold text-slate-700 dark:text-slate-300 text-blue-600 mt-2">Reference: Your Name / Unit No</p>
        </div>
        <form action="actions/financial_actions.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
            <input type="hidden" name="payment_method" value="Bank Transfer">
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Amount Transferred</label>
                <input type="number" name="amount" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Reference / Transaction ID</label>
                <input type="text" name="description" placeholder="E.g. BANK-TX-9988" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
            </div>
            <button type="submit" class="btn-primary w-full justify-center py-4">Notify Admin of Transfer</button>
        </form>
    </div>
</div>

<!-- Cheque Modal -->
<div id="chequeModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <button onclick="closeModal('chequeModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-4">Cheque Payment</h2>
        <p class="text-xs text-slate-500 mb-6">Please record the cheque number and amount. You may need to present the physical cheque at the management office.</p>
        <form action="actions/financial_actions.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
            <input type="hidden" name="payment_method" value="Cheque">
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Cheque Amount</label>
                <input type="number" name="amount" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Cheque Number</label>
                <input type="text" name="description" placeholder="E.g. CHQ-001122" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
            </div>
            <button type="submit" class="btn-primary w-full justify-center py-4">Record Cheque Submission</button>
        </form>
    </div>
</div>

<!-- Paybill Modal -->
<div id="paybillModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <button onclick="closeModal('paybillModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-4">Manual Paybill</h2>
        <div class="bg-orange-50 dark:bg-orange-900/10 p-4 rounded-xl mb-6">
            <p class="text-[10px] font-black text-orange-600 uppercase mb-2">Instructions</p>
            <p class="text-xs font-bold text-slate-700 dark:text-slate-300">1. Go to M-Pesa Menu</p>
            <p class="text-xs font-bold text-slate-700 dark:text-slate-300">2. Select Lipa Na M-Pesa > Paybill</p>
            <p class="text-xs font-bold text-slate-700 dark:text-slate-300">3. Business No: <span class="font-black">1234567</span></p>
            <p class="text-xs font-bold text-slate-700 dark:text-slate-300">4. Account No: <span class="font-black"><?php echo $tenantId; ?></span></p>
        </div>
        <form action="actions/financial_actions.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="tenant_id" value="<?php echo $tenantId; ?>">
            <input type="hidden" name="payment_method" value="M-Pesa Paybill">
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Amount Paid</label>
                <input type="number" name="amount" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">M-Pesa Reference Code</label>
                <input type="text" name="description" placeholder="E.g. RCX1234567" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
            </div>
            <button type="submit" class="btn-green w-full justify-center py-4">Notify Admin of Payment</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
