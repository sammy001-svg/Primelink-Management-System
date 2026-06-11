<?php
/**
 * Tenant Dashboard (included in dashboard.php for tenants)
 */
$tenantData = null;
$myPayments = [];
$myRequests = [];

if (!empty($tenantId)) {
    // Lease info
    $leaseStmt = $pdo->prepare("
        SELECT l.*, p.title as property_title, p.location, 
               u.unit_number, u.electricity_meter, u.water_meter, u.deposit_amount as unit_deposit
        FROM leases l 
        JOIN properties p ON l.property_id = p.id 
        JOIN units u ON l.unit_id = u.id
        WHERE l.tenant_id = ? 
        ORDER BY l.created_at DESC LIMIT 1
    ");
    $leaseStmt->execute([$tenantId]);
    $tenantLease = $leaseStmt->fetch();

    // Payments
    $payStmt = $pdo->prepare("SELECT * FROM transactions WHERE tenant_id = ? ORDER BY transaction_date DESC LIMIT 5");
    $payStmt->execute([$tenantId]);
    $myPayments = $payStmt->fetchAll();

    // Requests
    $reqStmt = $pdo->prepare("SELECT * FROM maintenance_requests WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 5");
    $reqStmt->execute([$tenantId]);
    $myRequests = $reqStmt->fetchAll();

    // Recent Tokens
    $tokenStmt = $pdo->prepare("SELECT * FROM tokens WHERE tenant_id = ? AND status = 'Active' ORDER BY created_at DESC LIMIT 3");
    $tokenStmt->execute([$tenantId]);
    $myActiveTokens = $tokenStmt->fetchAll();

    // Balances
    $categoryBalances = [];
    $balStmt = $pdo->prepare("
        SELECT 
            i.invoice_type,
            SUM(i.amount) - COALESCE(SUM(t.paid_amount), 0) as balance
        FROM invoices i
        LEFT JOIN (
            SELECT invoice_id, SUM(amount) as paid_amount 
            FROM transactions 
            WHERE status = 'Paid' 
            GROUP BY invoice_id
        ) t ON i.id = t.invoice_id
        WHERE i.tenant_id = ? AND i.status != 'Paid'
        GROUP BY i.invoice_type
    ");
    $balStmt->execute([$tenantId]);
    $balances = $balStmt->fetchAll();
    foreach ($balances as $b) {
        $categoryBalances[$b['invoice_type']] = (float)$b['balance'];
    }

    // Tenant Specific Stats for cards
    $stats = [];
    $stats['my_requests']      = $pdo->query("SELECT COUNT(*) FROM maintenance_requests WHERE tenant_id = '$tenantId'")->fetchColumn();
    $stats['pending_requests'] = $pdo->query("SELECT COUNT(*) FROM maintenance_requests WHERE tenant_id = '$tenantId' AND status = 'Pending'")->fetchColumn();
    $stats['my_payments']      = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE tenant_id = '$tenantId' AND status = 'Paid'")->fetchColumn();
    $stats['overdue_invoices'] = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE tenant_id = '$tenantId' AND status = 'Overdue'")->fetchColumn();
}
?>
<div class="space-y-6">

    <!-- Overdue alert — only shown when the tenant has overdue invoices -->
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
        <a href="financials.php" class="px-5 py-2.5 bg-red-500 text-white rounded-xl text-xs font-black whitespace-nowrap hover:bg-red-600 transition-colors self-start sm:self-auto">
            Pay Now →
        </a>
    </div>
    <?php endif; ?>

    <!-- Stats Row -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="glass-card stat-card p-5 flex items-center gap-4">
            <div class="w-11 h-11 rounded-xl bg-blue-50 dark:bg-blue-900/20 text-blue-500 flex items-center justify-center shrink-0">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
            </div>
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">My Requests</p>
                <h3 class="text-2xl font-black"><?php echo $stats['my_requests'] ?? 0; ?></h3>
            </div>
        </div>
        <div class="glass-card stat-card p-5 flex items-center gap-4">
            <div class="w-11 h-11 rounded-xl bg-orange-50 dark:bg-orange-900/20 text-orange-500 flex items-center justify-center shrink-0">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/></svg>
            </div>
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Pending</p>
                <h3 class="text-2xl font-black"><?php echo $stats['pending_requests'] ?? 0; ?></h3>
            </div>
        </div>
        <div class="glass-card stat-card p-5 flex items-center gap-4">
            <div class="w-11 h-11 rounded-xl bg-green-50 dark:bg-green-900/20 text-green-500 flex items-center justify-center shrink-0">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Paid</p>
                <h3 class="text-2xl font-black">KSh <?php echo number_format($stats['my_payments'] ?? 0); ?></h3>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- My Lease & Unit -->
        <div class="glass-card p-6">
            <h3 class="font-black text-slate-900 dark:text-white mb-5">My Property & Unit</h3>
            <?php if (!empty($tenantLease)): ?>
            <div class="space-y-6">
                <!-- Property Info -->
                <div class="flex justify-between items-start p-5 bg-slate-50 dark:bg-slate-800/50 rounded-2xl border border-slate-100 dark:border-slate-800/30">
                    <div>
                        <p class="text-[10px] text-slate-400 font-black uppercase tracking-widest">Currently Renting</p>
                        <p class="text-lg font-black text-slate-900 dark:text-white mt-1"><?php echo htmlspecialchars($tenantLease['property_title']); ?></p>
                        <p class="text-xs text-slate-500 font-medium flex items-center gap-1">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                            <?php echo htmlspecialchars($tenantLease['location']); ?>
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] text-slate-400 font-black uppercase tracking-widest">Unit</p>
                        <p class="text-2xl font-black text-accent-green leading-none"><?php echo htmlspecialchars($tenantLease['unit_number']); ?></p>
                    </div>
                </div>

                <!-- Financials -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="p-4 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl shadow-xs">
                        <p class="text-[9px] text-slate-400 font-black uppercase tracking-widest">Monthly Rent</p>
                        <p class="text-base font-black text-slate-900 dark:text-white">KSh <?php echo number_format($tenantLease['monthly_rent']); ?></p>
                    </div>
                    <div class="p-4 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl shadow-xs">
                        <p class="text-[9px] text-slate-400 font-black uppercase tracking-widest">Security Deposit</p>
                        <p class="text-base font-black text-slate-900 dark:text-white">KSh <?php echo number_format($tenantLease['unit_deposit']); ?></p>
                    </div>
                </div>

                <!-- Utility Meters -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="p-4 bg-linear-to-br from-blue-500/5 to-transparent border border-blue-500/10 rounded-2xl">
                        <p class="text-[9px] text-blue-400 font-black uppercase tracking-widest mb-1 flex items-center gap-1">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                            Electricity Meter
                        </p>
                        <p class="text-sm font-black text-slate-900 dark:text-white font-mono"><?php echo $tenantLease['electricity_meter'] ?: 'Not Assigned'; ?></p>
                    </div>
                    <div class="p-4 bg-linear-to-br from-cyan-500/5 to-transparent border border-cyan-500/10 rounded-2xl">
                        <p class="text-[9px] text-cyan-400 font-black uppercase tracking-widest mb-1 flex items-center gap-1">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z" stroke-opacity="0.3"/></svg>
                            Water Meter
                        </p>
                        <p class="text-sm font-black text-slate-900 dark:text-white font-mono"><?php echo $tenantLease['water_meter'] ?: 'Not Assigned'; ?></p>
                    </div>
                </div>

                <!-- Expiry Info -->
                <div class="p-4 bg-slate-50 dark:bg-slate-800/30 rounded-2xl flex justify-between items-center">
                    <p class="text-[10px] text-slate-400 font-bold uppercase">Lease Agreement Expiry</p>
                    <p class="text-xs font-black text-slate-900 dark:text-white"><?php echo date('M j, Y', strtotime($tenantLease['end_date'])); ?></p>
                </div>
            </div>
            <?php else: ?>
            <div class="text-center py-12">
                <div class="w-16 h-16 bg-slate-50 dark:bg-slate-900 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-slate-300"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                </div>
                <p class="text-slate-400 text-sm font-medium">No active lease found. Please contact management for unit assignment.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- My Maintenance Requests -->
        <div class="glass-card p-6">
            <div class="flex justify-between items-center mb-5">
                <h3 class="font-black text-slate-900 dark:text-white">My Requests</h3>
                <a href="maintenance.php" class="text-[10px] font-black text-slate-400 hover:text-accent-green uppercase tracking-widest transition-colors">View All →</a>
            </div>
            <?php if (empty($myRequests)): ?>
            <p class="text-sm text-slate-400 text-center py-6">No maintenance requests yet</p>
            <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($myRequests as $req): ?>
                <div class="flex items-center gap-3 p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                    <span class="w-2 h-2 rounded-full shrink-0 <?php echo $req['status']=='Completed' ? 'bg-green-500' : ($req['status']=='In Progress' ? 'bg-blue-500' : 'bg-orange-500'); ?>"></span>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-bold truncate"><?php echo htmlspecialchars($req['title']); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo $req['status']; ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
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
            <div class="space-y-3">
                <?php
                $showBalances = [
                    ['label' => 'Rent',  'val' => $categoryBalances['Rent'] ?? 0, 'color' => 'text-blue-500'],
                    ['label' => 'Water', 'val' => $categoryBalances['Water'] ?? 0, 'color' => 'text-cyan-500'],
                    ['label' => 'Garbage', 'val' => $categoryBalances['Garbage'] ?? 0, 'color' => 'text-orange-500'],
                    ['label' => 'Deposit', 'val' => $categoryBalances['Deposit'] ?? 0, 'color' => 'text-indigo-500'],
                    ['label' => 'Other', 'val' => ($categoryBalances['Service Charge'] ?? 0) + ($categoryBalances['Other'] ?? 0), 'color' => 'text-purple-500'],
                ];
                $hasAnyBalance = false;
                foreach ($showBalances as $sb): 
                    if ($sb['val'] > 0) $hasAnyBalance = true;
                ?>
                <div class="flex items-center justify-between p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                    <p class="text-xs font-bold text-slate-500"><?php echo $sb['label']; ?></p>
                    <p class="text-sm font-black <?php echo $sb['color']; ?>">KSh <?php echo number_format($sb['val']); ?></p>
                </div>
                <?php endforeach; ?>
                
                <?php if (!$hasAnyBalance): ?>
                <div class="text-center py-4">
                    <p class="text-[10px] font-black text-green-500 uppercase tracking-widest">Account fully paid! ✨</p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Utility Tokens -->
        <div class="glass-card p-6 bg-accent-green/5 border border-accent-green/10">
            <div class="flex justify-between items-center mb-5">
                <h3 class="font-black text-accent-green">Utility Tokens</h3>
                <a href="tokens.php" class="text-[10px] font-black text-accent-green uppercase tracking-widest">Buy Tokens →</a>
            </div>
            <div class="space-y-3">
                <?php if (empty($myActiveTokens)): ?>
                <p class="text-[10px] text-slate-400 font-bold text-center py-4">No active tokens found.</p>
                <?php else: ?>
                <?php foreach ($myActiveTokens as $tok): ?>
                <div class="p-3 bg-white dark:bg-slate-800/80 rounded-xl border border-accent-green/20 flex justify-between items-center">
                    <div>
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest leading-none"><?php echo $tok['token_type']; ?></p>
                        <p class="text-xs font-black text-slate-900 dark:text-white mt-1"><?php echo htmlspecialchars($tok['token_code']); ?></p>
                    </div>
                    <span class="text-[9px] font-black text-accent-green"><?php echo number_format($tok['units_value'], 1); ?>U</span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- Payment History -->
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800">
            <h3 class="font-black text-slate-900 dark:text-white">Payment History</h3>
        </div>
        <?php if (empty($myPayments)): ?>
        <p class="text-center text-slate-400 text-sm py-8">No payments recorded yet.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr>
                    <th>Date</th><th>Type</th><th>Amount</th><th>Method</th><th>Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($myPayments as $p): ?>
                <tr>
                    <td class="font-medium text-slate-500"><?php echo date('M j, Y', strtotime($p['transaction_date'])); ?></td>
                    <td class="font-bold"><?php echo htmlspecialchars($p['transaction_type']); ?></td>
                    <td class="font-black">KSh <?php echo number_format($p['amount']); ?></td>
                    <td class="text-slate-500"><?php echo htmlspecialchars($p['payment_method'] ?? 'M-Pesa'); ?></td>
                    <td><span class="badge badge-<?php echo $p['status']=='Paid' ? 'green' : ($p['status']=='Overdue' ? 'red' : 'orange'); ?>"><?php echo $p['status']; ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
