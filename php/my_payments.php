<?php
/**
 * My Payments — Tenant Portal
 */
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/corrections.php';

ensureCorrectionSchema($pdo);

$currency  = getSetting($pdo, 'currency_symbol', 'KSh');
$pageTitle = "My Payments";
$role      = $_SESSION['role'] ?? 'tenant';

// Resolve tenant (tenants only see their own)
if ($role === 'tenant') {
    $tStmt = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
    $tStmt->execute([$_SESSION['user_id']]);
    $tenantId = $tStmt->fetchColumn() ?: null;
} else {
    $tenantId = $_GET['tenant_id'] ?? null;
}

// Year filter
$currentYear  = (int)date('Y');
$selYear      = isset($_GET['year']) ? (int)$_GET['year'] : $currentYear;
$yearRange    = range($currentYear, max($currentYear - 5, 2020), -1);

$transactions = [];
$kpiTotal     = 0;
$kpiCount     = 0;
$kpiYTD       = 0;

if ($tenantId) {
    // KPI aggregates
    $kpiStmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM transactions WHERE tenant_id = ? AND status = 'Paid'");
    $kpiStmt->execute([$tenantId]);
    [$kpiCount, $kpiTotal] = $kpiStmt->fetch(\PDO::FETCH_NUM);
    $kpiCount = (int)$kpiCount; $kpiTotal = (float)$kpiTotal;

    $ytdStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE tenant_id = ? AND status = 'Paid' AND YEAR(transaction_date) = YEAR(NOW())");
    $ytdStmt->execute([$tenantId]);
    $kpiYTD = (float)$ytdStmt->fetchColumn();

    // Transactions for selected year
    $txStmt = $pdo->prepare("
        SELECT tx.*, i.invoice_type, i.due_date
        FROM transactions tx
        LEFT JOIN invoices i ON tx.invoice_id = i.id
        WHERE tx.tenant_id = ?
          AND YEAR(tx.transaction_date) = ?
        ORDER BY tx.transaction_date DESC, tx.created_at DESC
    ");
    $txStmt->execute([$tenantId, $selYear]);
    $transactions = $txStmt->fetchAll();
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

    <!-- Page header -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">My Payments</h1>
            <p class="text-slate-500 dark:text-slate-400 font-medium text-sm mt-0.5">Your complete payment and transaction history.</p>
        </div>
        <div class="flex gap-3">
            <a href="view_statement.php" class="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-xs font-black uppercase tracking-widest hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                Full Statement
            </a>
        </div>
    </div>

    <?php if ($tenantId): ?>

    <!-- KPI strip -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="glass-card p-5 flex items-center gap-4">
            <div class="w-11 h-11 rounded-xl bg-green-50 dark:bg-green-900/20 text-green-500 flex items-center justify-center shrink-0">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Paid (All Time)</p>
                <p class="text-xl font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($kpiTotal); ?></p>
                <p class="text-[11px] text-slate-400 font-medium"><?php echo $kpiCount; ?> transaction<?php echo $kpiCount !== 1 ? 's' : ''; ?></p>
            </div>
        </div>
        <div class="glass-card p-5 flex items-center gap-4">
            <div class="w-11 h-11 rounded-xl bg-blue-50 dark:bg-blue-900/20 text-blue-500 flex items-center justify-center shrink-0">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>
            </div>
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Paid This Year</p>
                <p class="text-xl font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($kpiYTD); ?></p>
                <p class="text-[11px] text-slate-400 font-medium"><?php echo date('Y'); ?> YTD</p>
            </div>
        </div>
        <div class="glass-card p-5 flex items-center gap-4">
            <div class="w-11 h-11 rounded-xl bg-slate-50 dark:bg-slate-800 text-slate-500 flex items-center justify-center shrink-0">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Viewing</p>
                <p class="text-xl font-black text-slate-900 dark:text-white"><?php echo $selYear; ?></p>
                <p class="text-[11px] text-slate-400 font-medium"><?php echo count($transactions); ?> record<?php echo count($transactions) !== 1 ? 's' : ''; ?></p>
            </div>
        </div>
    </div>

    <!-- Year filter -->
    <div class="flex items-center gap-3 flex-wrap">
        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Filter by Year:</span>
        <div class="flex gap-2 flex-wrap">
            <?php foreach ($yearRange as $yr): ?>
            <a href="?year=<?php echo $yr; ?>"
               class="px-3 py-1.5 rounded-lg text-xs font-black transition-colors <?php echo $selYear === $yr ? 'bg-slate-900 dark:bg-white text-white dark:text-slate-900' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 hover:text-slate-900 dark:hover:text-white'; ?>">
                <?php echo $yr; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Transactions table -->
    <div class="glass-card overflow-hidden">
        <?php if (empty($transactions)): ?>
        <div class="text-center py-16">
            <div class="w-16 h-16 bg-slate-50 dark:bg-slate-900 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-slate-300"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <p class="text-slate-400 font-bold text-sm">No transactions recorded for <?php echo $selYear; ?>.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr>
                    <th>Date</th>
                    <th>Description</th>
                    <th>Invoice Type</th>
                    <th>Method</th>
                    <th class="text-right">Amount</th>
                    <th>Status</th>
                    <th class="text-right">Receipt</th>
                </tr></thead>
                <tbody>
                <?php foreach ($transactions as $tx):
                    $txRev = (int)($tx['revision_no'] ?? 0);
                ?>
                <tr>
                    <td class="font-medium text-slate-500 whitespace-nowrap"><?php echo date('M j, Y', strtotime($tx['transaction_date'])); ?></td>
                    <td>
                        <p class="font-bold text-sm"><?php echo htmlspecialchars($tx['transaction_type']); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars(docNumber(DOC_RECEIPT, $tx['id'], $txRev)); ?></p>
                        <?php if ($txRev > 0): ?>
                        <div class="mt-1"><?php echo correctedBadge($txRev); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($tx['description'])): ?>
                        <p class="text-[10px] text-slate-400 truncate max-w-xs"><?php echo htmlspecialchars($tx['description']); ?></p>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($tx['invoice_type']): ?>
                        <span class="badge badge-blue text-[9px]"><?php echo htmlspecialchars($tx['invoice_type']); ?></span>
                        <?php else: ?>
                        <span class="text-slate-300 dark:text-slate-700 text-sm">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-sm text-slate-500"><?php echo htmlspecialchars($tx['payment_method'] ?? 'M-Pesa'); ?></td>
                    <td class="text-right font-black text-slate-900 dark:text-white whitespace-nowrap">
                        <?php echo $currency; ?> <?php echo number_format($tx['amount']); ?>
                    </td>
                    <td>
                        <span class="badge badge-<?php echo $tx['status'] === 'Paid' ? 'green' : ($tx['status'] === 'Overdue' ? 'red' : 'orange'); ?>">
                            <?php echo $tx['status']; ?>
                        </span>
                    </td>
                    <td class="text-right">
                        <?php if ($tx['status'] === 'Paid'): ?>
                        <a href="receipt.php?id=<?php echo urlencode($tx['id']); ?>" target="_blank"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-900 dark:hover:bg-white hover:text-white dark:hover:text-slate-900 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all whitespace-nowrap">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
                            Receipt
                        </a>
                        <?php else: ?>
                        <span class="text-slate-200 dark:text-slate-700 text-sm">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="glass-card p-10 text-center">
        <p class="text-slate-400 font-medium">No tenant record found for your account. Please contact management.</p>
    </div>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
