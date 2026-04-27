<?php
/**
 * Payout Management Dashboard
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireRole('staff');

$pageTitle = "Intelligent Payouts";

// Fetch landlords and their pending income (simulated calculation)
// In a real system, this would sum collected rent since last payout
$landlords = $pdo->query("
    SELECT l.*, 
    (SELECT COALESCE(SUM(tr.amount), 0) 
     FROM transactions tr 
     JOIN tenants t ON tr.tenant_id = t.id
     JOIN leases ls ON t.id = ls.tenant_id
     JOIN units un ON ls.unit_id = un.id
     JOIN properties p ON un.property_id = p.id
     WHERE p.landlord_id = l.id AND tr.status = 'Paid'
    ) as total_collected
    FROM landlords l
    ORDER BY l.full_name
")->fetchAll();

$payouts = $pdo->query("
    SELECT p.*, l.full_name as landlord_name 
    FROM landlord_payouts p 
    JOIN landlords l ON p.landlord_id = l.id 
    ORDER BY p.payout_date DESC
")->fetchAll();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Intelligent Payouts</h1>
            <p class="text-slate-500 font-medium">Manage and automate landlord income distributions.</p>
        </div>
        <div class="flex gap-3">
            <div class="bg-accent-green/10 text-accent-green px-4 py-2 rounded-xl text-xs font-black uppercase tracking-widest flex items-center gap-2">
                <span class="w-2 h-2 bg-accent-green rounded-full animate-pulse"></span>
                System Auto-Sync Active
            </div>
        </div>
    </div>

    <!-- Payout Cards -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-2 space-y-6">
            <div class="glass-card overflow-hidden">
                <div class="p-6 border-b border-slate-100 dark:border-slate-800">
                    <h2 class="text-lg font-black">Distributions Queue</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-50 dark:bg-slate-800/50">
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Landlord</th>
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Gross Collected</th>
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Management Fee (10%)</th>
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Net Payout</th>
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <?php foreach ($landlords as $landlord): ?>
                            <?php if ($landlord['total_collected'] > 0): ?>
                            <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/50 transition-colors">
                                <td class="px-6 py-4">
                                    <p class="font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($landlord['full_name']); ?></p>
                                    <p class="text-[10px] text-slate-500 font-medium"><?php echo htmlspecialchars($landlord['email']); ?></p>
                                </td>
                                <td class="px-6 py-4 font-bold text-slate-700 dark:text-slate-300">KSh <?php echo number_format($landlord['total_collected']); ?></td>
                                <td class="px-6 py-4 font-bold text-orange-500">- KSh <?php echo number_format($landlord['total_collected'] * 0.1); ?></td>
                                <td class="px-6 py-4 font-black text-accent-green">KSh <?php echo number_format($landlord['total_collected'] * 0.9); ?></td>
                                <td class="px-6 py-4 text-right">
                                    <form action="actions/payout_actions.php" method="POST">
                                        <input type="hidden" name="action" value="process_payout">
                                        <input type="hidden" name="landlord_id" value="<?php echo $landlord['id']; ?>">
                                        <input type="hidden" name="amount" value="<?php echo $landlord['total_collected']; ?>">
                                        <button type="submit" class="btn-green text-[10px] py-2 px-4 rounded-lg shadow-lg shadow-accent-green/10">Execute Payout</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- History -->
            <div class="glass-card overflow-hidden">
                <div class="p-6 border-b border-slate-100 dark:border-slate-800">
                    <h2 class="text-lg font-black">Payout History</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-50 dark:bg-slate-800/50">
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Landlord</th>
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Reference</th>
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Net Amount</th>
                                <th class="px-6 py-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Receipt</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <?php foreach ($payouts as $payout): ?>
                            <tr>
                                <td class="px-6 py-4 text-sm font-medium text-slate-500"><?php echo date('M d, Y', strtotime($payout['payout_date'])); ?></td>
                                <td class="px-6 py-4 font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($payout['landlord_name']); ?></td>
                                <td class="px-6 py-4"><span class="px-2 py-1 bg-slate-100 dark:bg-slate-800 rounded font-mono text-[10px] text-slate-600 dark:text-slate-400 font-bold"><?php echo $payout['reference_code']; ?></span></td>
                                <td class="px-6 py-4 font-black ">KSh <?php echo number_format($payout['amount']); ?></td>
                                <td class="px-6 py-4 text-right">
                                    <a href="view_voucher.php?ref=<?php echo $payout['reference_code']; ?>" target="_blank" class="text-accent-orange font-black text-[10px] uppercase hover:underline">View Voucher →</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="space-y-6">
            <div class="glass-card p-6 bg-slate-900 text-white border-none relative overflow-hidden">
                <div class="relative z-10">
                    <p class="text-accent-orange font-black text-[10px] uppercase tracking-widest mb-1">Fee Management</p>
                    <h3 class="text-2xl font-black mb-4">Management Fees</h3>
                    <div class="space-y-4">
                        <div class="p-4 bg-white/5 rounded-xl border border-white/10">
                            <p class="text-xs text-slate-400 font-medium">Standard Management Fee</p>
                            <p class="text-xl font-black text-accent-green">10.0% <span class="text-[10px] font-medium text-slate-500 uppercase tracking-widest ml-1">Flat Rate</span></p>
                        </div>
                        <p class="text-xs text-slate-500 leading-relaxed">
                            Fees are automatically calculated and deducted during the payout execution. Digital vouchers will reflect the specific breakdown for landlord transparency.
                        </p>
                    </div>
                </div>
                <div class="absolute bottom-[-10%] right-[-10%] w-32 h-32 bg-accent-green/10 rounded-full blur-3xl"></div>
            </div>

            <div class="glass-card p-6">
                <h3 class="text-lg font-black mb-4Caps">Payout Summary</h3>
                <div class="space-y-3">
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500 font-medium">Total Payouts Done</span>
                        <span class="font-black"><?php echo count($payouts); ?></span>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500 font-medium">Total Value Released</span>
                        <span class="font-black text-accent-green">KSh <?php 
                        $totalReleased = array_sum(array_column($payouts, 'amount'));
                        echo number_format($totalReleased); 
                        ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
