<?php
/**
 * Tenant Dashboard (included in dashboard.php for tenants)
 */
require_once __DIR__ . '/settings.php';
$currency = getSetting($pdo, 'currency_symbol', 'KSh');

$tenantData        = null;
$myPayments        = [];
$myRequests        = [];
$totalOutstanding  = 0;
$nextInvoice       = null;
$paidYTD           = 0;
$myRecentInvoices  = [];

if (!empty($tenantId)) {
    // Lease info
    $leaseStmt = $pdo->prepare("
        SELECT l.*, p.title as property_title, p.location,
               u.unit_number, u.electricity_meter, u.water_meter, u.deposit_amount as unit_deposit,
               COALESCE(l.renewal_status, NULL) as renewal_status
        FROM leases l
        JOIN properties p ON l.property_id = p.id
        JOIN units u ON l.unit_id = u.id
        WHERE l.tenant_id = ? AND l.status = 'Active'
        ORDER BY l.created_at DESC LIMIT 1
    ");
    $leaseStmt->execute([$tenantId]);
    $tenantLease = $leaseStmt->fetch();

    // Payments (recent 5)
    $payStmt = $pdo->prepare("SELECT * FROM transactions WHERE tenant_id = ? ORDER BY transaction_date DESC LIMIT 5");
    $payStmt->execute([$tenantId]);
    $myPayments = $payStmt->fetchAll();

    // Maintenance requests (recent 5)
    $reqStmt = $pdo->prepare("SELECT * FROM maintenance_requests WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 5");
    $reqStmt->execute([$tenantId]);
    $myRequests = $reqStmt->fetchAll();

    // Active tokens
    $tokenStmt = $pdo->prepare("SELECT * FROM tokens WHERE tenant_id = ? AND status = 'Active' ORDER BY created_at DESC LIMIT 3");
    $tokenStmt->execute([$tenantId]);
    $myActiveTokens = $tokenStmt->fetchAll();

    // Outstanding balances per category
    $categoryBalances = [];
    $balStmt = $pdo->prepare("
        SELECT i.invoice_type,
               SUM(i.amount) - COALESCE(SUM(t.paid_amount), 0) as balance
        FROM invoices i
        LEFT JOIN (
            SELECT invoice_id, SUM(amount) as paid_amount
            FROM transactions WHERE status = 'Paid' GROUP BY invoice_id
        ) t ON i.id = t.invoice_id
        WHERE i.tenant_id = ? AND i.status != 'Paid'
        GROUP BY i.invoice_type
    ");
    $balStmt->execute([$tenantId]);
    foreach ($balStmt->fetchAll() as $b) {
        $categoryBalances[$b['invoice_type']] = (float)$b['balance'];
    }

    // Total outstanding (all unpaid)
    $totStmt = $pdo->prepare("
        SELECT COALESCE(SUM(i.amount - COALESCE(t.paid, 0)), 0)
        FROM invoices i
        LEFT JOIN (SELECT invoice_id, SUM(amount) as paid FROM transactions WHERE status='Paid' GROUP BY invoice_id) t ON i.id = t.invoice_id
        WHERE i.tenant_id = ? AND i.status NOT IN ('Paid','Cancelled')
    ");
    $totStmt->execute([$tenantId]);
    $totalOutstanding = (float)$totStmt->fetchColumn();

    // Next invoice due
    $nextInvStmt = $pdo->prepare("SELECT id, amount, due_date, invoice_type FROM invoices WHERE tenant_id = ? AND status IN ('Unpaid','Partial','Overdue') ORDER BY due_date ASC LIMIT 1");
    $nextInvStmt->execute([$tenantId]);
    $nextInvoice = $nextInvStmt->fetch();

    // YTD paid
    $ytdStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE tenant_id = ? AND status = 'Paid' AND YEAR(transaction_date) = YEAR(NOW())");
    $ytdStmt->execute([$tenantId]);
    $paidYTD = (float)$ytdStmt->fetchColumn();

    // Recent invoices (last 5, non-cancelled)
    $myInvStmt = $pdo->prepare("SELECT id, invoice_type, amount, due_date, status FROM invoices WHERE tenant_id = ? AND status != 'Cancelled' ORDER BY created_at DESC LIMIT 5");
    $myInvStmt->execute([$tenantId]);
    $myRecentInvoices = $myInvStmt->fetchAll();

    // Announcements
    $myPropertyId = $tenantLease['property_id'] ?? null;
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (id VARCHAR(36) NOT NULL PRIMARY KEY, title VARCHAR(255) NOT NULL, message TEXT NOT NULL, audience VARCHAR(20) NOT NULL DEFAULT 'all', property_id VARCHAR(36) DEFAULT NULL, urgency VARCHAR(20) NOT NULL DEFAULT 'Info', sent_by VARCHAR(36) NOT NULL, recipient_count INT NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)"); } catch (PDOException $e) {}
    $annStmt = $pdo->prepare("
        SELECT a.*, p.title AS property_title
        FROM   announcements a
        LEFT JOIN properties p ON a.property_id = p.id
        WHERE  a.created_at >= NOW() - INTERVAL 14 DAY
          AND  (a.audience = 'all' OR (a.audience = 'property' AND a.property_id = ?))
        ORDER  BY a.created_at DESC LIMIT 5
    ");
    $annStmt->execute([$myPropertyId]);
    $recentAnnouncements = $annStmt->fetchAll();

    // Stats
    $stats = [];
    $stats['my_requests']      = $pdo->query("SELECT COUNT(*) FROM maintenance_requests WHERE tenant_id = '$tenantId'")->fetchColumn();
    $stats['pending_requests'] = $pdo->query("SELECT COUNT(*) FROM maintenance_requests WHERE tenant_id = '$tenantId' AND status = 'Pending'")->fetchColumn();
    $stats['overdue_invoices'] = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE tenant_id = '$tenantId' AND status = 'Overdue'")->fetchColumn();
}

// Derived hero values
$daysToExpiry  = isset($tenantLease['end_date']) ? (int)floor((strtotime($tenantLease['end_date']) - time()) / 86400) : null;
$heroInitial   = strtoupper(substr($userName ?? 'T', 0, 1));
$heroFirstName = explode(' ', $userName ?? 'Tenant')[0];
?>
<div class="space-y-6 animate-in">

    <!-- ── Dark Hero ─────────────────────────────────────────── -->
    <div class="rounded-2xl overflow-hidden bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 shadow-xl">
        <div class="p-6 sm:p-8 flex flex-col sm:flex-row gap-6 items-start sm:items-center">
            <!-- Avatar -->
            <div class="w-16 h-16 rounded-2xl bg-emerald-500/20 flex items-center justify-center shrink-0 ring-4 ring-emerald-500/20 text-white text-2xl font-black select-none">
                <?php echo $heroInitial; ?>
            </div>
            <!-- Info -->
            <div class="flex-1 min-w-0">
                <p class="text-[9px] font-black text-emerald-400 uppercase tracking-widest mb-1">Tenant Portal</p>
                <h1 class="text-2xl font-black text-white leading-tight">
                    Welcome back, <?php echo htmlspecialchars($heroFirstName); ?>
                </h1>
                <?php if (!empty($tenantLease)): ?>
                <p class="text-slate-400 text-sm mt-1 flex items-center gap-2 flex-wrap">
                    <span>Unit <?php echo htmlspecialchars($tenantLease['unit_number']); ?></span>
                    <span class="text-slate-600">&middot;</span>
                    <span><?php echo htmlspecialchars($tenantLease['property_title']); ?></span>
                    <span class="text-slate-600">&middot;</span>
                    <span><?php echo htmlspecialchars($tenantLease['location']); ?></span>
                    <?php if ($daysToExpiry !== null && $daysToExpiry <= 60): ?>
                    <span class="px-2 py-0.5 rounded-full text-[9px] font-black <?php echo $daysToExpiry <= 14 ? 'bg-red-500/20 text-red-400' : 'bg-orange-500/20 text-orange-400'; ?>">
                        Lease expires in <?php echo $daysToExpiry; ?>d
                    </span>
                    <?php endif; ?>
                </p>
                <?php endif; ?>
            </div>
            <!-- Quick Actions -->
            <div class="flex sm:flex-col gap-2 shrink-0">
                <a href="my_invoices.php" class="px-4 py-2.5 bg-emerald-500 text-white rounded-xl font-black text-xs text-center hover:bg-emerald-600 transition-colors whitespace-nowrap">
                    My Invoices
                </a>
                <a href="view_statement.php" class="px-4 py-2.5 bg-slate-700 text-slate-300 rounded-xl font-black text-xs text-center hover:bg-slate-600 transition-colors whitespace-nowrap">
                    My Statement
                </a>
            </div>
        </div>
        <!-- Stats strip -->
        <div class="border-t border-slate-700/50 grid grid-cols-2 sm:grid-cols-4 divide-x divide-slate-700/50">
            <div class="px-5 py-4">
                <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest">Outstanding</p>
                <p class="text-lg font-black <?php echo $totalOutstanding > 0 ? 'text-red-400' : 'text-emerald-400'; ?> mt-0.5">
                    <?php echo $currency; ?> <?php echo number_format($totalOutstanding); ?>
                </p>
            </div>
            <div class="px-5 py-4">
                <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest">Next Due</p>
                <?php if ($nextInvoice): ?>
                <p class="text-lg font-black text-white mt-0.5"><?php echo date('d M', strtotime($nextInvoice['due_date'])); ?></p>
                <p class="text-[10px] text-slate-500"><?php echo $currency; ?> <?php echo number_format($nextInvoice['amount']); ?> · <?php echo $nextInvoice['invoice_type']; ?></p>
                <?php else: ?>
                <p class="text-lg font-black text-emerald-400 mt-0.5">All Clear</p>
                <?php endif; ?>
            </div>
            <div class="px-5 py-4">
                <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest">Paid YTD</p>
                <p class="text-lg font-black text-white mt-0.5"><?php echo $currency; ?> <?php echo number_format($paidYTD); ?></p>
            </div>
            <div class="px-5 py-4">
                <p class="text-[9px] font-black text-slate-500 uppercase tracking-widest">Lease Expires</p>
                <?php if (!empty($tenantLease)): ?>
                <p class="text-lg font-black <?php echo ($daysToExpiry !== null && $daysToExpiry <= 30) ? 'text-orange-400' : 'text-white'; ?> mt-0.5">
                    <?php echo date('d M Y', strtotime($tenantLease['end_date'])); ?>
                </p>
                <?php else: ?>
                <p class="text-lg font-black text-slate-600 mt-0.5">—</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Announcements ──────────────────────────────────────── -->
    <?php if (!empty($recentAnnouncements)): ?>
    <div class="space-y-3">
        <?php foreach ($recentAnnouncements as $ann):
            $annBg    = match($ann['urgency']) { 'Urgent' => 'bg-red-50 dark:bg-red-900/10 border-red-300 dark:border-red-700/40', 'Important' => 'bg-orange-50 dark:bg-orange-900/10 border-orange-300 dark:border-orange-700/40', default => 'bg-blue-50 dark:bg-blue-900/10 border-blue-300 dark:border-blue-700/40' };
            $annIcon  = match($ann['urgency']) { 'Urgent' => 'bg-red-100 dark:bg-red-900/30 text-red-500', 'Important' => 'bg-orange-100 dark:bg-orange-900/30 text-orange-500', default => 'bg-blue-100 dark:bg-blue-900/30 text-blue-500' };
            $annTitle = match($ann['urgency']) { 'Urgent' => 'text-red-700 dark:text-red-400', 'Important' => 'text-orange-700 dark:text-orange-400', default => 'text-blue-700 dark:text-blue-400' };
        ?>
        <div class="p-4 <?php echo $annBg; ?> border rounded-2xl flex items-start gap-3">
            <div class="w-9 h-9 <?php echo $annIcon; ?> rounded-xl flex items-center justify-center shrink-0 mt-0.5">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.18h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.77a16 16 0 0 0 6.29 6.29l.77-.86a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.73 16.92z"/></svg>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap mb-1">
                    <span class="text-[9px] font-black uppercase tracking-widest <?php echo $annTitle; ?>"><?php echo $ann['urgency']; ?></span>
                    <span class="text-[9px] text-slate-400 font-bold"><?php echo date('M d, Y', strtotime($ann['created_at'])); ?></span>
                    <?php if ($ann['audience'] === 'property' && $ann['property_title']): ?>
                    <span class="text-[9px] text-slate-400 font-bold">&middot; <?php echo htmlspecialchars($ann['property_title']); ?></span>
                    <?php endif; ?>
                </div>
                <p class="font-black text-sm text-slate-900 dark:text-white leading-snug"><?php echo htmlspecialchars($ann['title']); ?></p>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1 leading-relaxed"><?php echo nl2br(htmlspecialchars($ann['message'])); ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Lease Renewal Alert ───────────────────────────────── -->
    <?php if (!empty($tenantLease) && $daysToExpiry !== null && $daysToExpiry >= 0 && $daysToExpiry <= 60):
        $isUrgent = $daysToExpiry <= 14;
        $renewalStatus = $tenantLease['renewal_status'] ?? null;
        $alertBg = $isUrgent ? 'bg-red-50 dark:bg-red-900/10 border-red-200 dark:border-red-800/30' : 'bg-orange-50 dark:bg-orange-900/10 border-orange-200 dark:border-orange-800/30';
        $iconBg  = $isUrgent ? 'bg-red-100 dark:bg-red-900/30 text-red-500' : 'bg-orange-100 dark:bg-orange-900/30 text-orange-500';
        $titleClr = $isUrgent ? 'text-red-700 dark:text-red-400' : 'text-orange-700 dark:text-orange-400';
        $subClr   = $isUrgent ? 'text-red-500' : 'text-orange-500';
    ?>
    <div class="p-4 <?php echo $alertBg; ?> border rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 <?php echo $iconBg; ?> rounded-xl flex items-center justify-center shrink-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
            </div>
            <div>
                <p class="text-sm font-black <?php echo $titleClr; ?>">
                    <?php echo $isUrgent ? 'Lease Expiring Critically Soon' : 'Lease Renewal Reminder'; ?>
                    — <?php echo $daysToExpiry; ?> day<?php echo $daysToExpiry !== 1 ? 's' : ''; ?> left
                </p>
                <p class="text-[10px] <?php echo $subClr; ?> font-medium mt-0.5">
                    <?php echo htmlspecialchars($tenantLease['property_title']); ?> &middot; Unit <?php echo htmlspecialchars($tenantLease['unit_number']); ?> &middot; Expires <?php echo date('M d, Y', strtotime($tenantLease['end_date'])); ?>
                    <?php if ($renewalStatus === 'Offered'): ?>&middot; <span class="font-black text-blue-500">Renewal Offered ✓</span><?php endif; ?>
                    <?php if ($renewalStatus === 'Accepted'): ?>&middot; <span class="font-black text-green-500">Renewal Accepted ✓</span><?php endif; ?>
                </p>
            </div>
        </div>
        <a href="leases.php" class="px-5 py-2.5 <?php echo $isUrgent ? 'bg-red-500 hover:bg-red-600' : 'bg-orange-500 hover:bg-orange-600'; ?> text-white rounded-xl text-xs font-black whitespace-nowrap transition-colors self-start sm:self-auto">
            View Lease →
        </a>
    </div>
    <?php endif; ?>

    <!-- ── Overdue Invoice Alert ─────────────────────────────── -->
    <?php if (!empty($stats['overdue_invoices']) && $stats['overdue_invoices'] > 0): ?>
    <div class="p-4 bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-800/30 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-red-100 dark:bg-red-900/30 rounded-xl flex items-center justify-center text-red-500 shrink-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
            </div>
            <div>
                <p class="text-sm font-black text-red-700 dark:text-red-400">
                    You have <?php echo $stats['overdue_invoices']; ?> overdue invoice<?php echo $stats['overdue_invoices'] !== 1 ? 's' : ''; ?> past the due date.
                </p>
                <p class="text-[10px] text-red-500 font-medium mt-0.5">Please make payment immediately to avoid additional charges.</p>
            </div>
        </div>
        <a href="my_invoices.php?status=Overdue" class="px-5 py-2.5 bg-red-500 text-white rounded-xl text-xs font-black whitespace-nowrap hover:bg-red-600 transition-colors self-start sm:self-auto">
            View Overdue →
        </a>
    </div>
    <?php endif; ?>

    <!-- ── Main Grid ─────────────────────────────────────────── -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- Recent Invoices -->
        <div class="glass-card overflow-hidden">
            <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <h3 class="font-black text-slate-900 dark:text-white">Recent Invoices</h3>
                <a href="my_invoices.php" class="text-[10px] font-black text-slate-400 hover:text-accent-green uppercase tracking-widest transition-colors">View All →</a>
            </div>
            <?php if (empty($myRecentInvoices)): ?>
            <p class="text-sm text-slate-400 text-center py-8">No invoices on record.</p>
            <?php else: ?>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php
                $statusColors = ['Unpaid' => 'badge-orange', 'Paid' => 'badge-green', 'Overdue' => 'badge-red', 'Partial' => 'badge-blue', 'Cancelled' => 'badge'];
                foreach ($myRecentInvoices as $inv):
                    $badgeClass = $statusColors[$inv['status']] ?? 'badge';
                ?>
                <div class="flex items-center gap-4 px-5 py-3.5">
                    <div class="flex-1 min-w-0">
                        <p class="font-bold text-sm text-slate-900 dark:text-white truncate"><?php echo $inv['invoice_type']; ?></p>
                        <p class="text-[11px] text-slate-400 font-medium">Due <?php echo date('M j, Y', strtotime($inv['due_date'])); ?></p>
                    </div>
                    <div class="text-right shrink-0">
                        <p class="font-black text-sm text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($inv['amount']); ?></p>
                        <span class="badge <?php echo $badgeClass; ?> text-[9px] mt-0.5"><?php echo $inv['status']; ?></span>
                    </div>
                    <a href="view_invoice.php?id=<?php echo $inv['id']; ?>" class="w-8 h-8 flex items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-900 dark:hover:bg-white hover:text-white dark:hover:text-slate-900 transition-colors shrink-0">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- My Property & Unit -->
        <div class="glass-card p-6">
            <h3 class="font-black text-slate-900 dark:text-white mb-5">My Property & Unit</h3>
            <?php if (!empty($tenantLease)): ?>
            <div class="space-y-4">
                <div class="flex justify-between items-start p-4 bg-slate-50 dark:bg-slate-800/50 rounded-2xl border border-slate-100 dark:border-slate-800/30">
                    <div>
                        <p class="text-[10px] text-slate-400 font-black uppercase tracking-widest">Currently Renting</p>
                        <p class="text-base font-black text-slate-900 dark:text-white mt-0.5"><?php echo htmlspecialchars($tenantLease['property_title']); ?></p>
                        <p class="text-xs text-slate-500 font-medium flex items-center gap-1 mt-0.5">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                            <?php echo htmlspecialchars($tenantLease['location']); ?>
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] text-slate-400 font-black uppercase tracking-widest">Unit</p>
                        <p class="text-2xl font-black text-accent-green leading-none"><?php echo htmlspecialchars($tenantLease['unit_number']); ?></p>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="p-4 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl">
                        <p class="text-[9px] text-slate-400 font-black uppercase tracking-widest">Monthly Rent</p>
                        <p class="text-sm font-black text-slate-900 dark:text-white mt-0.5"><?php echo $currency; ?> <?php echo number_format($tenantLease['monthly_rent']); ?></p>
                    </div>
                    <div class="p-4 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl">
                        <p class="text-[9px] text-slate-400 font-black uppercase tracking-widest">Security Deposit</p>
                        <p class="text-sm font-black text-slate-900 dark:text-white mt-0.5"><?php echo $currency; ?> <?php echo number_format($tenantLease['unit_deposit']); ?></p>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="p-3.5 bg-blue-500/5 border border-blue-500/10 rounded-xl">
                        <p class="text-[9px] text-blue-400 font-black uppercase tracking-widest mb-1">Electricity Meter</p>
                        <p class="text-xs font-black text-slate-900 dark:text-white font-mono"><?php echo $tenantLease['electricity_meter'] ?: 'Not Assigned'; ?></p>
                    </div>
                    <div class="p-3.5 bg-cyan-500/5 border border-cyan-500/10 rounded-xl">
                        <p class="text-[9px] text-cyan-400 font-black uppercase tracking-widest mb-1">Water Meter</p>
                        <p class="text-xs font-black text-slate-900 dark:text-white font-mono"><?php echo $tenantLease['water_meter'] ?: 'Not Assigned'; ?></p>
                    </div>
                </div>
                <div class="p-3.5 bg-slate-50 dark:bg-slate-800/30 rounded-xl flex justify-between items-center">
                    <p class="text-[10px] text-slate-400 font-bold uppercase">Lease Expiry</p>
                    <p class="text-xs font-black text-slate-900 dark:text-white"><?php echo date('M j, Y', strtotime($tenantLease['end_date'])); ?></p>
                </div>
            </div>
            <?php else: ?>
            <div class="text-center py-12">
                <div class="w-16 h-16 bg-slate-50 dark:bg-slate-900 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-slate-300"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                </div>
                <p class="text-slate-400 text-sm font-medium">No active lease found. Contact management.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- Outstanding Balances -->
        <div class="glass-card p-6">
            <div class="flex justify-between items-center mb-5">
                <h3 class="font-black text-slate-900 dark:text-white">Outstanding Balances</h3>
                <a href="view_statement.php" class="flex items-center gap-1.5 text-[10px] font-black text-slate-400 hover:text-accent-green uppercase tracking-widest transition-colors">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
                    Statement →
                </a>
            </div>
            <div class="space-y-2.5">
                <?php
                $showBalances = [
                    ['label' => 'Rent',    'val' => $categoryBalances['Rent']    ?? 0, 'color' => 'text-blue-500'],
                    ['label' => 'Water',   'val' => $categoryBalances['Water']   ?? 0, 'color' => 'text-cyan-500'],
                    ['label' => 'Garbage', 'val' => $categoryBalances['Garbage'] ?? 0, 'color' => 'text-orange-500'],
                    ['label' => 'Deposit', 'val' => $categoryBalances['Deposit'] ?? 0, 'color' => 'text-indigo-500'],
                    ['label' => 'Other',   'val' => ($categoryBalances['Service Charge'] ?? 0) + ($categoryBalances['Other'] ?? 0), 'color' => 'text-purple-500'],
                ];
                $hasAnyBalance = false;
                foreach ($showBalances as $sb):
                    if ($sb['val'] > 0) $hasAnyBalance = true;
                ?>
                <div class="flex items-center justify-between p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                    <p class="text-xs font-bold text-slate-500"><?php echo $sb['label']; ?></p>
                    <p class="text-sm font-black <?php echo $sb['val'] > 0 ? $sb['color'] : 'text-slate-300 dark:text-slate-700'; ?>">
                        <?php echo $sb['val'] > 0 ? $currency . ' ' . number_format($sb['val']) : '—'; ?>
                    </p>
                </div>
                <?php endforeach; ?>
                <?php if (!$hasAnyBalance): ?>
                <div class="text-center py-4">
                    <p class="text-[10px] font-black text-accent-green uppercase tracking-widest">Account fully paid! ✓</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- My Maintenance Requests -->
        <div class="glass-card p-6">
            <div class="flex justify-between items-center mb-5">
                <h3 class="font-black text-slate-900 dark:text-white">Maintenance Requests</h3>
                <a href="maintenance.php" class="text-[10px] font-black text-slate-400 hover:text-accent-green uppercase tracking-widest transition-colors">View All →</a>
            </div>
            <?php if (empty($myRequests)): ?>
            <p class="text-sm text-slate-400 text-center py-8">No maintenance requests yet.</p>
            <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($myRequests as $req): ?>
                <div class="flex items-center gap-3 p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                    <span class="w-2 h-2 rounded-full shrink-0 <?php echo $req['status'] === 'Completed' ? 'bg-green-500' : ($req['status'] === 'In Progress' ? 'bg-blue-500' : 'bg-orange-500'); ?>"></span>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-bold truncate"><?php echo htmlspecialchars($req['title']); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo $req['status']; ?> &middot; <?php echo date('M j', strtotime($req['created_at'])); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- ── Utility Tokens ─────────────────────────────────────── -->
    <div class="glass-card p-6 bg-accent-green/5 border border-accent-green/10">
        <div class="flex justify-between items-center mb-5">
            <h3 class="font-black text-accent-green">Utility Tokens</h3>
            <a href="tokens.php" class="text-[10px] font-black text-accent-green uppercase tracking-widest hover:opacity-70 transition-opacity">Buy Tokens →</a>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <?php if (empty($myActiveTokens)): ?>
            <p class="col-span-3 text-[10px] text-slate-400 font-bold text-center py-4">No active tokens found.</p>
            <?php else: ?>
            <?php foreach ($myActiveTokens as $tok): ?>
            <div class="p-3 bg-white dark:bg-slate-800/80 rounded-xl border border-accent-green/20 flex justify-between items-center">
                <div>
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest leading-none"><?php echo $tok['token_type']; ?></p>
                    <p class="text-xs font-black text-slate-900 dark:text-white mt-1 font-mono"><?php echo htmlspecialchars($tok['token_code']); ?></p>
                </div>
                <span class="text-[9px] font-black text-accent-green"><?php echo number_format($tok['units_value'], 1); ?>U</span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Payment History ────────────────────────────────────── -->
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <h3 class="font-black text-slate-900 dark:text-white">Payment History</h3>
            <a href="my_payments.php" class="text-[10px] font-black text-slate-400 hover:text-accent-green uppercase tracking-widest transition-colors">All Transactions →</a>
        </div>
        <?php if (empty($myPayments)): ?>
        <p class="text-center text-slate-400 text-sm py-8">No payments recorded yet.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr>
                    <th>Date</th><th>Type</th><th>Amount</th><th>Method</th><th>Status</th><th class="text-right">Receipt</th>
                </tr></thead>
                <tbody>
                <?php foreach ($myPayments as $p): ?>
                <tr>
                    <td class="font-medium text-slate-500"><?php echo date('M j, Y', strtotime($p['transaction_date'])); ?></td>
                    <td class="font-bold"><?php echo htmlspecialchars($p['transaction_type']); ?></td>
                    <td class="font-black"><?php echo $currency; ?> <?php echo number_format($p['amount']); ?></td>
                    <td class="text-slate-500"><?php echo htmlspecialchars($p['payment_method'] ?? 'M-Pesa'); ?></td>
                    <td><span class="badge badge-<?php echo $p['status'] === 'Paid' ? 'green' : ($p['status'] === 'Overdue' ? 'red' : 'orange'); ?>"><?php echo $p['status']; ?></span></td>
                    <td class="text-right">
                        <?php if ($p['status'] === 'Paid'): ?>
                        <a href="receipt.php?id=<?php echo $p['id']; ?>" target="_blank"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-900 dark:hover:bg-white hover:text-white dark:hover:text-slate-900 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
                            Receipt
                        </a>
                        <?php else: ?>
                        <span class="text-slate-200 dark:text-slate-700 text-[10px]">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>
