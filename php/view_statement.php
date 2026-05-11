<?php
/**
 * Detailed Tenant Financial Statement
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = getCurrentUser($pdo);
$role = $_SESSION['role'] ?? 'tenant';
$tenantId = $_GET['tenant_id'] ?? '';

// SECURITY: Tenants can only see their own statement
if ($role === 'tenant') {
    $stmt = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $ownTenant = $stmt->fetch();
    $tenantId = $ownTenant['id'] ?? null;
}

if (!$tenantId) {
    header("Location: " . ($role === 'tenant' ? "financials.php" : "tenant_payments.php"));
    exit();
}

// Fetch tenant and property info
$stmt = $pdo->prepare("
    SELECT t.*, u.unit_number, p.title as property_title, l.id as lease_id, l.monthly_rent, l.start_date
    FROM tenants t
    LEFT JOIN leases l ON t.id = l.tenant_id AND l.status = 'Active'
    LEFT JOIN units u ON l.unit_id = u.id
    LEFT JOIN properties p ON u.property_id = p.id
    WHERE t.id = ?
");
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();

if (!$tenant) {
    die("Tenant not found.");
}

// Fetch all ledger items (Invoices and Transactions)
$ledger = [];

// Get Invoices
$stmt = $pdo->prepare("SELECT id, invoice_type as title, amount, created_at as date, 'Invoice' as type FROM invoices WHERE tenant_id = ?");
$stmt->execute([$tenantId]);
while ($row = $stmt->fetch()) {
    $row['debit'] = $row['amount'];
    $row['credit'] = 0;
    $ledger[] = $row;
}

// Get Transactions (Payments)
$stmt = $pdo->prepare("SELECT id, transaction_type as title, amount, transaction_date as date, 'Payment' as type, payment_method FROM transactions WHERE tenant_id = ? AND status = 'Paid'");
$stmt->execute([$tenantId]);
while ($row = $stmt->fetch()) {
    $row['debit'] = 0;
    $row['credit'] = $row['amount'];
    $ledger[] = $row;
}

// Sort ledger by date
usort($ledger, function($a, $b) {
    return strtotime($a['date']) - strtotime($b['date']);
});

$pageTitle = "Statement: " . $tenant['full_name'];
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">
    <div class="flex justify-between items-end">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <a href="<?php echo ($role === 'tenant' ? 'financials.php' : 'tenant_payments.php'); ?>" class="p-2 bg-slate-100 dark:bg-slate-800 rounded-xl text-slate-500 hover:text-slate-900 transition-all">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
                </a>
                <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Statement of Account</h1>
            </div>
            <p class="text-slate-500 font-medium ml-12">Detailed financial history for <?php echo htmlspecialchars($tenant['full_name']); ?></p>
        </div>
        <div class="flex gap-3">
            <button onclick="window.print()" class="px-6 py-3 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl font-bold text-sm shadow-sm hover:shadow-md transition-all flex items-center gap-2">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                Print Statement
            </button>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="glass-card p-6 border-l-4 border-slate-900">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Tenant Name</p>
            <p class="text-lg font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($tenant['full_name']); ?></p>
        </div>
        <div class="glass-card p-6 border-l-4 border-blue-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Property & Unit</p>
            <p class="text-lg font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($tenant['property_title'] ?? 'N/A'); ?> - <?php echo htmlspecialchars($tenant['unit_number'] ?? 'N/A'); ?></p>
        </div>
        <?php
        $totalDebit = array_sum(array_column($ledger, 'debit'));
        $totalCredit = array_sum(array_column($ledger, 'credit'));
        $balance = $totalDebit - $totalCredit;
        ?>
        <div class="glass-card p-6 border-l-4 border-accent-green">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Paid</p>
            <p class="text-lg font-black text-accent-green">KSh <?php echo number_format($totalCredit); ?></p>
        </div>
        <div class="glass-card p-6 border-l-4 <?php echo $balance > 0 ? 'border-red-500' : 'border-accent-green'; ?>">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Current Balance</p>
            <p class="text-lg font-black <?php echo $balance > 0 ? 'text-red-500' : 'text-accent-green'; ?>">KSh <?php echo number_format($balance); ?></p>
        </div>
    </div>

    <!-- Statement Table -->
    <div class="glass-card overflow-hidden border-none shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-900 text-white">
                        <th class="p-6 text-[10px] font-black uppercase tracking-widest">Date</th>
                        <th class="p-6 text-[10px] font-black uppercase tracking-widest">Description</th>
                        <th class="p-6 text-[10px] font-black uppercase tracking-widest">Type</th>
                        <th class="p-6 text-[10px] font-black uppercase tracking-widest text-right">Debit (Due)</th>
                        <th class="p-6 text-[10px] font-black uppercase tracking-widest text-right">Credit (Paid)</th>
                        <th class="p-6 text-[10px] font-black uppercase tracking-widest text-right">Running Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php if (empty($ledger)): ?>
                        <tr><td colspan="6" class="p-20 text-center text-slate-400 font-medium italic">No financial records found for this tenant.</td></tr>
                    <?php else: ?>
                        <?php 
                        $runningBal = 0;
                        foreach ($ledger as $item): 
                            $runningBal += ($item['debit'] - $item['credit']);
                        ?>
                        <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                            <td class="p-6">
                                <p class="text-xs font-bold text-slate-900 dark:text-white"><?php echo date('d M, Y', strtotime($item['date'])); ?></p>
                            </td>
                            <td class="p-6">
                                <p class="text-xs font-black text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($item['title']); ?></p>
                                <?php if (!empty($item['payment_method'])): ?>
                                    <p class="text-[9px] font-black uppercase text-slate-400 tracking-tighter">via <?php echo htmlspecialchars($item['payment_method']); ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="p-6">
                                <span class="px-2.5 py-1 <?php echo $item['type'] === 'Invoice' ? 'bg-blue-500/10 text-blue-500' : 'bg-accent-green/10 text-accent-green'; ?> rounded-full text-[9px] font-black uppercase tracking-widest border <?php echo $item['type'] === 'Invoice' ? 'border-blue-500/20' : 'border-accent-green/20'; ?>">
                                    <?php echo $item['type']; ?>
                                </span>
                            </td>
                            <td class="p-6 text-right text-xs font-black <?php echo $item['debit'] > 0 ? 'text-slate-900 dark:text-white' : 'text-slate-300 dark:text-slate-700'; ?>">
                                <?php echo $item['debit'] > 0 ? 'KSh ' . number_format($item['debit']) : '-'; ?>
                            </td>
                            <td class="p-6 text-right text-xs font-black <?php echo $item['credit'] > 0 ? 'text-accent-green' : 'text-slate-300 dark:text-slate-700'; ?>">
                                <?php echo $item['credit'] > 0 ? 'KSh ' . number_format($item['credit']) : '-'; ?>
                            </td>
                            <td class="p-6 text-right text-sm font-black <?php echo $runningBal > 0 ? 'text-red-500' : 'text-accent-green'; ?>">
                                KSh <?php echo number_format($runningBal); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="bg-slate-50 dark:bg-slate-900/50">
                        <td colspan="3" class="p-6 text-sm font-black text-slate-900 dark:text-white">Total Summary</td>
                        <td class="p-6 text-right text-sm font-black text-slate-900 dark:text-white">KSh <?php echo number_format($totalDebit); ?></td>
                        <td class="p-6 text-right text-sm font-black text-accent-green">KSh <?php echo number_format($totalCredit); ?></td>
                        <td class="p-6 text-right text-lg font-black <?php echo $balance > 0 ? 'text-red-500' : 'text-accent-green'; ?>">KSh <?php echo number_format($balance); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
