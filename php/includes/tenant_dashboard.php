<?php
/**
 * Tenant Dashboard (included in dashboard.php for tenants)
 */
$tenantData = null;
$myPayments = [];
$myRequests = [];

if (!empty($tenantId)) {
    // Lease info
    $leaseStmt = $pdo->prepare("SELECT l.*, p.title as property_title, p.location FROM leases l JOIN properties p ON l.property_id = p.id WHERE l.tenant_id = ? ORDER BY l.created_at DESC LIMIT 1");
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
}
?>
<div class="space-y-6">

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

        <!-- My Lease -->
        <div class="glass-card p-6">
            <h3 class="font-black text-slate-900 dark:text-white mb-5">My Current Lease</h3>
            <?php if (!empty($tenantLease)): ?>
            <div class="space-y-4">
                <div class="flex justify-between items-start p-4 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                    <div>
                        <p class="text-xs text-slate-400 font-bold uppercase">Property</p>
                        <p class="font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($tenantLease['property_title']); ?></p>
                        <p class="text-sm text-slate-500"><?php echo htmlspecialchars($tenantLease['location']); ?></p>
                    </div>
                    <span class="badge badge-green">Active</span>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                        <p class="text-[10px] text-slate-400 font-black uppercase">Monthly Rent</p>
                        <p class="font-black text-slate-900 dark:text-white">KSh <?php echo number_format($tenantLease['monthly_rent']); ?></p>
                    </div>
                    <div class="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                        <p class="text-[10px] text-slate-400 font-black uppercase">Lease Ends</p>
                        <p class="font-black text-slate-900 dark:text-white"><?php echo date('M j, Y', strtotime($tenantLease['end_date'])); ?></p>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="text-center py-8">
                <p class="text-slate-400 text-sm font-medium">No lease found. Contact your property manager.</p>
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
