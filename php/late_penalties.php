<?php
/**
 * Late Payment Penalty Manager
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);

$pageTitle = "Late Payment Penalties";

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/audit.php';

// Self-heal: add source_invoice_id to invoices so we can link penalties back
foreach ([
    "ALTER TABLE invoices ADD COLUMN source_invoice_id VARCHAR(36) DEFAULT NULL",
    "ALTER TABLE invoices ADD INDEX idx_source_invoice (source_invoice_id)",
] as $ddl) {
    try { $pdo->exec($ddl); } catch (PDOException $e) {}
}

$s = getSettings($pdo, [
    'penalty_enabled', 'penalty_grace_days', 'penalty_type',
    'penalty_amount', 'penalty_percentage', 'currency_symbol', 'invoice_due_days',
]);
$penEnabled    = ($s['penalty_enabled']    ?? '0') === '1';
$graceDays     = max(0, (int)($s['penalty_grace_days'] ?? 5));
$penType       = $s['penalty_type']        ?? 'fixed';
$penAmount     = (float)($s['penalty_amount']      ?? 500);
$penPct        = (float)($s['penalty_percentage']  ?? 5);
$currency      = $s['currency_symbol']     ?? 'KSh';
$dueDays       = max(1, (int)($s['invoice_due_days'] ?? 7));

// Eligible invoices: overdue/unpaid, past grace period, no existing penalty child
$eligible = [];
if ($penEnabled) {
    $eligible = $pdo->prepare("
        SELECT i.*,
               t.user_id   AS tenant_user_id,
               pr.full_name AS tenant_name,
               pr.email     AS tenant_email,
               prop.title   AS property_title,
               u.unit_number,
               DATEDIFF(CURDATE(), i.due_date) AS days_overdue
        FROM   invoices i
        JOIN   tenants  t   ON i.tenant_id = t.id
        JOIN   profiles pr  ON t.user_id   = pr.id
        LEFT JOIN leases ls  ON i.lease_id  = ls.id
        LEFT JOIN units  u   ON ls.unit_id  = u.id
        LEFT JOIN properties prop ON ls.property_id = prop.id
        WHERE  i.status       NOT IN ('Paid', 'Cancelled')
          AND  i.invoice_type != 'Penalty'
          AND  i.due_date      <= CURDATE() - INTERVAL ? DAY
          AND  NOT EXISTS (
              SELECT 1 FROM invoices pen
              WHERE  pen.source_invoice_id = i.id
                AND  pen.invoice_type = 'Penalty'
          )
        ORDER  BY i.due_date ASC
    ");
    $eligible->execute([$graceDays]);
    $eligible = $eligible->fetchAll();
}

// Compute penalty amounts per row
$computePenalty = function(array $inv) use ($penType, $penAmount, $penPct): float {
    if ($penType === 'percentage') {
        return round((float)$inv['amount'] * $penPct / 100, 2);
    }
    return $penAmount;
};

$totalEligible   = count($eligible);
$totalPenaltyAmt = array_sum(array_map($computePenalty, $eligible));

// Applied penalties log (most recent 50)
$appliedLog = $pdo->query("
    SELECT pen.*,
           pr.full_name  AS tenant_name,
           prop.title    AS property_title,
           u.unit_number,
           src.invoice_type AS source_type,
           src.amount       AS source_amount,
           src.due_date     AS source_due_date
    FROM   invoices pen
    LEFT JOIN invoices src ON pen.source_invoice_id = src.id
    JOIN   tenants  t   ON pen.tenant_id = t.id
    JOIN   profiles pr  ON t.user_id     = pr.id
    LEFT JOIN leases ls  ON pen.lease_id  = ls.id
    LEFT JOIN units  u   ON ls.unit_id    = u.id
    LEFT JOIN properties prop ON ls.property_id = prop.id
    WHERE  pen.invoice_type = 'Penalty'
    ORDER  BY pen.created_at DESC
    LIMIT  50
")->fetchAll();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

    <!-- Toast -->
    <?php if (isset($_GET['success'])): ?>
    <div id="toast" class="fixed top-6 right-6 z-50 bg-slate-900 dark:bg-white text-white dark:text-slate-900 px-6 py-4 rounded-2xl shadow-2xl flex items-center gap-3 font-bold text-sm">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <?php
        $msg = match($_GET['success']) {
            'applied_all'    => number_format((int)($_GET['count'] ?? 0)) . ' penalty invoice(s) generated.',
            'applied_single' => 'Penalty invoice applied.',
            default          => 'Done.',
        };
        echo htmlspecialchars($msg);
        ?>
    </div>
    <script>setTimeout(() => { const t=document.getElementById('toast'); if(t) t.style.display='none'; }, 4000);</script>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
    <div class="p-4 bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-800/30 text-red-600 font-bold rounded-2xl text-sm">
        <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
    <?php endif; ?>

    <!-- Page header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Late Payment Penalties</h1>
            <p class="text-slate-500 font-medium">Automatically charge penalty fees on overdue invoices past the grace period.</p>
        </div>
        <a href="settings.php" onclick="event.preventDefault(); window.location.href='settings.php#penalties'"
           class="inline-flex items-center gap-2 px-4 py-2.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-xl text-xs font-black uppercase tracking-widest transition-all self-start sm:self-auto">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
            Penalty Settings
        </a>
    </div>

    <!-- Status / disabled banner -->
    <?php if (!$penEnabled): ?>
    <div class="p-5 bg-orange-50 dark:bg-orange-900/10 border border-orange-200 dark:border-orange-800/30 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-orange-100 dark:bg-orange-900/30 text-orange-500 rounded-xl flex items-center justify-center shrink-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <div>
                <p class="font-black text-orange-700 dark:text-orange-400 text-sm">Penalties are currently disabled</p>
                <p class="text-[10px] text-orange-500 mt-0.5">Enable penalty charging in Settings → Penalties to start using this feature.</p>
            </div>
        </div>
        <a href="settings.php" class="px-5 py-2.5 bg-orange-500 hover:bg-orange-600 text-white rounded-xl text-xs font-black transition-colors self-start sm:self-auto whitespace-nowrap">
            Go to Settings →
        </a>
    </div>
    <?php else: ?>

    <!-- Config summary strip -->
    <div class="glass-card p-5 flex flex-wrap gap-6 items-center">
        <div>
            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Grace Period</p>
            <p class="text-lg font-black text-slate-900 dark:text-white"><?php echo $graceDays; ?> days</p>
        </div>
        <div class="w-px h-8 bg-slate-200 dark:bg-slate-700"></div>
        <div>
            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Penalty Type</p>
            <p class="text-lg font-black text-slate-900 dark:text-white"><?php echo ucfirst($penType); ?></p>
        </div>
        <div class="w-px h-8 bg-slate-200 dark:bg-slate-700"></div>
        <div>
            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Penalty Amount</p>
            <p class="text-lg font-black text-red-500">
                <?php echo $penType === 'percentage' ? number_format($penPct, 1) . '%' : $currency . ' ' . number_format($penAmount); ?>
            </p>
        </div>
        <div class="w-px h-8 bg-slate-200 dark:bg-slate-700"></div>
        <div>
            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Penalty Due In</p>
            <p class="text-lg font-black text-slate-900 dark:text-white"><?php echo $dueDays; ?> days</p>
        </div>
    </div>

    <!-- KPI cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        <div class="glass-card p-6 border-l-4 <?php echo $totalEligible > 0 ? 'border-red-500' : 'border-slate-200 dark:border-slate-700'; ?>">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Eligible Invoices</p>
            <h3 class="text-3xl font-black mt-1 <?php echo $totalEligible > 0 ? 'text-red-500' : ''; ?>"><?php echo $totalEligible; ?></h3>
            <p class="text-[10px] text-slate-400 mt-1">past <?php echo $graceDays; ?>-day grace, no penalty yet</p>
        </div>
        <div class="glass-card p-6 border-l-4 <?php echo $totalPenaltyAmt > 0 ? 'border-orange-500' : 'border-slate-200 dark:border-slate-700'; ?>">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Penalties to Apply</p>
            <h3 class="text-3xl font-black mt-1 <?php echo $totalPenaltyAmt > 0 ? 'text-orange-500' : ''; ?>">
                <?php echo $currency . ' ' . number_format($totalPenaltyAmt); ?>
            </h3>
        </div>
        <div class="glass-card p-6 border-l-4 border-accent-green">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Penalties Applied (Total)</p>
            <h3 class="text-3xl font-black mt-1"><?php echo count($appliedLog); ?></h3>
            <p class="text-[10px] text-slate-400 mt-1">penalty invoices created</p>
        </div>
    </div>

    <!-- Eligible Invoices Table -->
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h3 class="font-black text-lg">Eligible for Penalty</h3>
                <p class="text-xs text-slate-400 mt-0.5">Overdue invoices past the <?php echo $graceDays; ?>-day grace period without an existing penalty charge.</p>
            </div>
            <?php if ($totalEligible > 0): ?>
            <form action="actions/penalty_actions.php" method="POST" onsubmit="return confirm('Apply penalty invoices to all <?php echo $totalEligible; ?> eligible invoice(s)?\n\nThis will create <?php echo $totalEligible; ?> new Penalty invoice(s) and notify each tenant.')">
                <input type="hidden" name="action" value="apply_all">
                <button type="submit" class="btn-primary flex items-center gap-2 whitespace-nowrap bg-red-500 hover:bg-red-600 border-red-500">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m8 3 4 8 5-5 5 15H2L8 3z"/></svg>
                    Apply All (<?php echo $totalEligible; ?>)
                </button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (empty($eligible)): ?>
        <div class="p-16 text-center">
            <div class="w-16 h-16 mx-auto bg-green-50 dark:bg-green-900/20 rounded-2xl flex items-center justify-center mb-4">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="text-accent-green"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </div>
            <p class="font-black text-slate-700 dark:text-slate-300">No eligible invoices</p>
            <p class="text-sm text-slate-400 mt-1">All overdue invoices are either within the grace period or already have a penalty.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-slate-50 dark:bg-slate-800/50">
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tenant</th>
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Invoice</th>
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Original Amount</th>
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Days Overdue</th>
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Penalty</th>
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php foreach ($eligible as $inv):
                    $penAmt = $computePenalty($inv);
                    $overdueDays = (int)$inv['days_overdue'];
                    $urgency = $overdueDays >= 30 ? 'text-red-500' : ($overdueDays >= 14 ? 'text-orange-500' : 'text-yellow-500');
                ?>
                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                    <td class="p-4">
                        <p class="font-black text-sm text-slate-900 dark:text-white"><?php echo htmlspecialchars($inv['tenant_name']); ?></p>
                        <p class="text-[10px] text-slate-400">
                            <?php echo htmlspecialchars($inv['property_title'] ?? '—'); ?>
                            <?php if ($inv['unit_number']): ?> · Unit <?php echo htmlspecialchars($inv['unit_number']); ?><?php endif; ?>
                        </p>
                    </td>
                    <td class="p-4">
                        <span class="badge badge-red"><?php echo htmlspecialchars($inv['invoice_type']); ?></span>
                        <p class="text-[10px] text-slate-400 mt-1">Due <?php echo date('M d, Y', strtotime($inv['due_date'])); ?></p>
                    </td>
                    <td class="p-4">
                        <span class="font-black text-sm"><?php echo $currency . ' ' . number_format((float)$inv['amount']); ?></span>
                    </td>
                    <td class="p-4">
                        <span class="font-black text-sm <?php echo $urgency; ?>"><?php echo $overdueDays; ?> days</span>
                    </td>
                    <td class="p-4">
                        <span class="font-black text-sm text-red-500">+ <?php echo $currency . ' ' . number_format($penAmt); ?></span>
                        <?php if ($penType === 'percentage'): ?>
                        <p class="text-[10px] text-slate-400"><?php echo number_format($penPct, 1); ?>% of original</p>
                        <?php endif; ?>
                    </td>
                    <td class="p-4 text-right">
                        <form action="actions/penalty_actions.php" method="POST" class="inline">
                            <input type="hidden" name="action" value="apply_single">
                            <input type="hidden" name="invoice_id" value="<?php echo $inv['id']; ?>">
                            <button type="submit"
                                    onclick="return confirm('Apply a penalty of <?php echo $currency . ' ' . number_format($penAmt); ?> to this invoice?')"
                                    class="px-3 py-1.5 bg-red-50 dark:bg-red-900/20 text-red-500 hover:bg-red-500 hover:text-white rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                                Apply
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <?php endif; // penalty enabled ?>

    <!-- Applied Penalties Log -->
    <?php if (!empty($appliedLog)): ?>
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800">
            <h3 class="font-black text-lg">Applied Penalties Log</h3>
            <p class="text-xs text-slate-400 mt-0.5">Most recent 50 penalty invoices created by the system.</p>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-slate-50 dark:bg-slate-800/50">
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tenant</th>
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Penalty Amount</th>
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Original Invoice</th>
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                    <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Applied On</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php foreach ($appliedLog as $pen): ?>
                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                    <td class="p-4">
                        <p class="font-bold text-sm text-slate-900 dark:text-white"><?php echo htmlspecialchars($pen['tenant_name']); ?></p>
                        <p class="text-[10px] text-slate-400">
                            <?php echo htmlspecialchars($pen['property_title'] ?? '—'); ?>
                            <?php if ($pen['unit_number']): ?> · Unit <?php echo htmlspecialchars($pen['unit_number']); ?><?php endif; ?>
                        </p>
                    </td>
                    <td class="p-4">
                        <span class="font-black text-red-500"><?php echo $currency . ' ' . number_format((float)$pen['amount']); ?></span>
                    </td>
                    <td class="p-4">
                        <?php if ($pen['source_type']): ?>
                        <span class="text-xs font-bold text-slate-500"><?php echo htmlspecialchars($pen['source_type']); ?></span>
                        <p class="text-[10px] text-slate-400">Was due <?php echo date('M d, Y', strtotime($pen['source_due_date'])); ?></p>
                        <?php else: ?><span class="text-slate-300 dark:text-slate-600 text-xs">—</span><?php endif; ?>
                    </td>
                    <td class="p-4">
                        <?php
                        $statusClass = match($pen['status']) {
                            'Paid'    => 'badge-green',
                            'Overdue' => 'badge-red',
                            default   => 'badge-orange',
                        };
                        ?>
                        <span class="badge <?php echo $statusClass; ?>"><?php echo $pen['status']; ?></span>
                    </td>
                    <td class="p-4">
                        <span class="text-xs font-bold text-slate-500"><?php echo date('M d, Y H:i', strtotime($pen['created_at'])); ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
