<?php
/**
 * My Invoices — Tenant Portal
 */
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/settings.php';

$currency  = getSetting($pdo, 'currency_symbol', 'KSh');
$pageTitle = "My Invoices";
$role      = $_SESSION['role'] ?? 'tenant';

// Resolve tenant record (tenants can only see their own)
if ($role === 'tenant') {
    $tStmt = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
    $tStmt->execute([$_SESSION['user_id']]);
    $tenantId = $tStmt->fetchColumn() ?: null;
} else {
    $tenantId = $_GET['tenant_id'] ?? null;
}

// Status filter
$validStatuses = ['Unpaid', 'Paid', 'Partial', 'Overdue', 'Cancelled'];
$statusFilter  = $_GET['status'] ?? 'all';
if ($statusFilter !== 'all' && !in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'all';
}

// Type filter
$typeFilter = trim($_GET['type'] ?? 'all');

$invoices   = [];
$statusSummary = [];
if ($tenantId) {
    // Summary counts per status
    $sumStmt = $pdo->prepare("SELECT status, COUNT(*) as cnt, COALESCE(SUM(amount),0) as total FROM invoices WHERE tenant_id = ? GROUP BY status");
    $sumStmt->execute([$tenantId]);
    foreach ($sumStmt->fetchAll() as $row) {
        $statusSummary[$row['status']] = ['cnt' => (int)$row['cnt'], 'total' => (float)$row['total']];
    }
    $totalAll = array_sum(array_column($statusSummary, 'cnt'));

    // Build query
    $params = [$tenantId];
    $extraWhere = '';
    if ($statusFilter !== 'all') {
        $extraWhere .= ' AND i.status = ?';
        $params[]   = $statusFilter;
    }
    if ($typeFilter !== 'all' && $typeFilter !== '') {
        $extraWhere .= ' AND i.invoice_type = ?';
        $params[]   = $typeFilter;
    }

    $stmt = $pdo->prepare("
        SELECT i.id, i.invoice_type, i.amount, i.due_date, i.status, i.created_at,
               u.unit_number, p.title as property_title,
               COALESCE(SUM(tx.amount), 0) as paid_amount
        FROM invoices i
        LEFT JOIN leases l ON i.lease_id = l.id
        LEFT JOIN units u ON l.unit_id = u.id
        LEFT JOIN properties p ON u.property_id = p.id
        LEFT JOIN transactions tx ON tx.invoice_id = i.id AND tx.status = 'Paid'
        WHERE i.tenant_id = ?
        $extraWhere
        GROUP BY i.id
        ORDER BY i.created_at DESC
    ");
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';

$statusBadge = ['Unpaid' => 'badge-orange', 'Paid' => 'badge-green', 'Partial' => 'badge-blue', 'Overdue' => 'badge-red', 'Cancelled' => 'badge'];
$typeBadge   = ['Rent' => 'badge-blue', 'Water' => 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/30 dark:text-cyan-400', 'Garbage' => 'badge-orange', 'Electricity' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400', 'Deposit' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400', 'Service Charge' => 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400'];
?>

<div class="space-y-8 animate-in">

    <!-- Page header -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">My Invoices</h1>
            <p class="text-slate-500 dark:text-slate-400 font-medium text-sm mt-0.5">All invoices issued to your account.</p>
        </div>
        <div class="flex gap-3">
            <a href="view_statement.php" class="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-xs font-black uppercase tracking-widest hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                My Statement
            </a>
        </div>
    </div>

    <!-- Summary KPI strip -->
    <?php if ($tenantId): ?>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <?php
        $kpiCards = [
            ['label' => 'Unpaid',  'status' => 'Unpaid',  'color' => 'text-orange-500', 'bg' => 'bg-orange-50 dark:bg-orange-900/10'],
            ['label' => 'Overdue', 'status' => 'Overdue', 'color' => 'text-red-500',    'bg' => 'bg-red-50 dark:bg-red-900/10'],
            ['label' => 'Paid',    'status' => 'Paid',    'color' => 'text-green-500',  'bg' => 'bg-green-50 dark:bg-green-900/10'],
            ['label' => 'Partial', 'status' => 'Partial', 'color' => 'text-blue-500',   'bg' => 'bg-blue-50 dark:bg-blue-900/10'],
        ];
        foreach ($kpiCards as $k):
            $cnt = $statusSummary[$k['status']]['cnt'] ?? 0;
            $tot = $statusSummary[$k['status']]['total'] ?? 0;
        ?>
        <div class="glass-card p-4 <?php echo $cnt > 0 ? $k['bg'] : ''; ?>">
            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest"><?php echo $k['label']; ?></p>
            <p class="text-xl font-black <?php echo $cnt > 0 ? $k['color'] : 'text-slate-300 dark:text-slate-700'; ?> mt-0.5"><?php echo $cnt; ?></p>
            <?php if ($cnt > 0): ?>
            <p class="text-[10px] font-bold text-slate-400"><?php echo $currency; ?> <?php echo number_format($tot); ?></p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="glass-card p-4 flex flex-wrap items-center gap-3">
        <!-- Status pills -->
        <div class="flex flex-wrap gap-2">
            <?php
            $filterPills = [
                'all'        => 'All (' . ($totalAll ?? 0) . ')',
                'Unpaid'     => 'Unpaid',
                'Overdue'    => 'Overdue',
                'Partial'    => 'Partial',
                'Paid'       => 'Paid',
                'Cancelled'  => 'Cancelled',
            ];
            foreach ($filterPills as $val => $label):
                $isActive = $statusFilter === $val;
                $href = '?' . http_build_query(['status' => $val] + ($typeFilter !== 'all' ? ['type' => $typeFilter] : []));
            ?>
            <a href="<?php echo $href; ?>"
               class="px-3 py-1.5 rounded-lg text-[11px] font-black transition-colors <?php echo $isActive ? 'bg-slate-900 dark:bg-white text-white dark:text-slate-900' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 hover:text-slate-900 dark:hover:text-white'; ?>">
                <?php echo $label; ?>
            </a>
            <?php endforeach; ?>
        </div>
        <!-- Type filter -->
        <div class="ml-auto flex items-center gap-2">
            <select onchange="location.href='?status=<?php echo urlencode($statusFilter); ?>&type='+this.value"
                    class="form-input py-1.5 text-xs">
                <option value="all" <?php echo $typeFilter === 'all' ? 'selected' : ''; ?>>All Types</option>
                <?php foreach (['Rent','Water','Garbage','Electricity','Deposit','Service Charge'] as $t): ?>
                <option value="<?php echo $t; ?>" <?php echo $typeFilter === $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Invoices table -->
    <div class="glass-card overflow-hidden">
        <?php if (empty($invoices)): ?>
        <div class="text-center py-16">
            <div class="w-16 h-16 bg-slate-50 dark:bg-slate-900 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-slate-300"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <p class="text-slate-400 font-bold text-sm">No invoices found<?php echo $statusFilter !== 'all' ? ' with status "' . $statusFilter . '"' : ''; ?>.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr>
                    <th>Type</th>
                    <th>Property / Unit</th>
                    <th class="text-right">Amount</th>
                    <th class="text-right">Paid</th>
                    <th>Due Date</th>
                    <th>Status</th>
                    <th class="text-right">Invoice</th>
                </tr></thead>
                <tbody>
                <?php foreach ($invoices as $inv):
                    $badge    = $statusBadge[$inv['status']] ?? 'badge';
                    $typeCls  = $typeBadge[$inv['invoice_type']] ?? 'badge';
                    $isPastDue = strtotime($inv['due_date']) < time();
                    $balance  = (float)$inv['amount'] - (float)$inv['paid_amount'];
                ?>
                <tr>
                    <td>
                        <span class="badge <?php echo $typeCls; ?>"><?php echo $inv['invoice_type']; ?></span>
                        <p class="text-[10px] text-slate-400 mt-1"><?php echo date('d M Y', strtotime($inv['created_at'])); ?></p>
                    </td>
                    <td class="text-sm text-slate-500 font-medium">
                        <?php if ($inv['property_title']): ?>
                        <?php echo htmlspecialchars($inv['property_title']); ?> / Unit <?php echo htmlspecialchars($inv['unit_number']); ?>
                        <?php else: ?>
                        <span class="text-slate-300 dark:text-slate-700">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right font-black text-slate-900 dark:text-white">
                        <?php echo $currency; ?> <?php echo number_format($inv['amount']); ?>
                    </td>
                    <td class="text-right font-bold <?php echo (float)$inv['paid_amount'] > 0 ? 'text-green-600 dark:text-green-400' : 'text-slate-300 dark:text-slate-700'; ?>">
                        <?php echo (float)$inv['paid_amount'] > 0 ? $currency . ' ' . number_format($inv['paid_amount']) : '—'; ?>
                    </td>
                    <td class="<?php echo ($isPastDue && $inv['status'] !== 'Paid' && $inv['status'] !== 'Cancelled') ? 'text-red-500 font-black' : 'text-slate-500 font-medium'; ?> text-sm">
                        <?php echo date('M j, Y', strtotime($inv['due_date'])); ?>
                        <?php if ($isPastDue && $inv['status'] === 'Unpaid'): ?>
                        <span class="block text-[9px]">Past due</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?php echo $badge; ?>"><?php echo $inv['status']; ?></span></td>
                    <td class="text-right">
                        <a href="view_invoice.php?id=<?php echo urlencode($inv['id']); ?>" target="_blank"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-900 dark:hover:bg-white hover:text-white dark:hover:text-slate-900 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            View
                        </a>
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
