<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin(['admin', 'staff']);

$pageTitle = "Landlord Advances & Loans";
$user = getCurrentUser($pdo);
$searchTerm = $_GET['search'] ?? '';

// Fetch all landlord advances with optional search
$advQuery = "
    SELECT a.*, l.full_name as landlord_name 
    FROM landlord_advances a
    JOIN landlords l ON a.landlord_id = l.id
";
if ($searchTerm) {
    $advQuery .= " WHERE l.full_name LIKE :search OR a.purpose LIKE :search ";
}
$advQuery .= " ORDER BY a.requested_at DESC";
$stmtAdv = $pdo->prepare($advQuery);
if ($searchTerm) $stmtAdv->execute(['search' => "%$searchTerm%"]);
else $stmtAdv->execute();
$advances = $stmtAdv->fetchAll();

// Fetch active loans with optional search
$loanQuery = "
    SELECT ln.*, l.full_name as landlord_name 
    FROM landlord_loans ln
    JOIN landlords l ON ln.landlord_id = l.id
    WHERE ln.status = 'Active'
";
if ($searchTerm) {
    $loanQuery .= " AND l.full_name LIKE :search ";
}
$loanQuery .= " ORDER BY ln.created_at DESC";
$stmtLoan = $pdo->prepare($loanQuery);
if ($searchTerm) $stmtLoan->execute(['search' => "%$searchTerm%"]);
else $stmtLoan->execute();
$loans = $stmtLoan->fetchAll();

// Fetch landlords for the selection dropdown
$landlords = $pdo->query("SELECT id, full_name FROM landlords ORDER BY full_name")->fetchAll();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Landlord Advances & Loans</h1>
            <p class="text-slate-500 font-medium">Manage short-term advances and long-term interest-bearing loans.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3 w-full md:w-auto">
            <form class="relative flex-1 md:w-64">
                <input type="text" name="search" value="<?php echo htmlspecialchars((string)$searchTerm); ?>" placeholder="Search landlord name..." class="w-full pl-10 pr-4 py-3 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-xs font-bold focus:ring-2 focus:ring-accent-green/20 outline-none transition-all">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            </form>
            <button onclick="openModal('newLoanModal')" class="px-5 py-3 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-2xl font-bold text-sm hover:bg-slate-200 transition-all">
                Grant Loan
            </button>
            <button onclick="openModal('newAdvanceModal')" class="btn-primary">
                Record Advance
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- Advances Section -->
        <div class="space-y-6">
            <div class="glass-card overflow-hidden">
                <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
                    <h3 class="text-lg font-black">Monthly Advances</h3>
                    <span class="text-[10px] font-black text-blue-500 bg-blue-500/10 px-3 py-1 rounded-full uppercase tracking-widest">Auto-Deducted</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50 dark:bg-slate-800/50">
                                <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Landlord</th>
                                <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount</th>
                                <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                                <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <?php if (empty($advances)): ?>
                                <tr><td colspan="4" class="p-10 text-center text-slate-400 italic">No advances recorded.</td></tr>
                            <?php else: ?>
                                <?php foreach ($advances as $adv): ?>
                                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20">
                                    <td class="p-4 text-sm font-bold"><?php echo htmlspecialchars((string)$adv['landlord_name']); ?></td>
                                    <td class="p-4 text-sm font-black">KSh <?php echo number_format($adv['amount']); ?></td>
                                    <td class="p-4">
                                        <?php if ($adv['is_deducted']): ?>
                                            <span class="px-2 py-0.5 bg-slate-100 text-slate-400 rounded-full text-[9px] font-black uppercase">Deducted</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 <?php echo $adv['status'] == 'Approved' ? 'bg-green-500/10 text-green-500' : ($adv['status'] == 'Declined' ? 'bg-red-500/10 text-red-500' : 'bg-orange-500/10 text-orange-500'); ?> rounded-full text-[9px] font-black uppercase">
                                                <?php echo htmlspecialchars((string)$adv['status']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-4 text-right">
                                        <?php if ($adv['status'] == 'Pending'): ?>
                                            <form action="actions/landlord_fin_actions.php" method="POST" class="inline">
                                                <input type="hidden" name="action" value="approve_advance">
                                                <input type="hidden" name="id" value="<?php echo $adv['id']; ?>">
                                                <button type="submit" class="text-accent-green hover:underline text-xs font-bold mr-2">Approve</button>
                                            </form>
                                        <?php endif; ?>
                                        <a href="#" class="text-slate-400 hover:text-slate-900 transition-colors">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Loans Section -->
        <div class="space-y-6">
            <div class="glass-card overflow-hidden">
                <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
                    <h3 class="text-lg font-black">Active Loans</h3>
                    <span class="text-[10px] font-black text-accent-green bg-accent-green/10 px-3 py-1 rounded-full uppercase tracking-widest">Interest Applied</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-50 dark:bg-slate-800/50">
                                <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Landlord</th>
                                <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Repayable</th>
                                <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Interest</th>
                                <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <?php if (empty($loans)): ?>
                                <tr><td colspan="4" class="p-10 text-center text-slate-400 italic">No active loans.</td></tr>
                            <?php else: ?>
                                <?php foreach ($loans as $loan): ?>
                                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20">
                                    <td class="p-4 text-sm font-bold"><?php echo htmlspecialchars((string)$loan['landlord_name']); ?></td>
                                    <td class="p-4 text-sm font-black text-red-500">KSh <?php echo number_format($loan['total_repayable']); ?></td>
                                    <td class="p-4 text-sm font-bold text-slate-500"><?php echo $loan['interest_rate']; ?>%</td>
                                    <td class="p-4 text-xs text-slate-400"><?php echo date('M d, Y', strtotime($loan['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Advance Modal -->
<div id="newAdvanceModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <button onclick="closeModal('newAdvanceModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-6">Create Advance Request</h2>
        <form action="actions/landlord_fin_actions.php" method="POST" class="space-y-6">
            <input type="hidden" name="action" value="create_advance">
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Landlord</label>
                <select name="landlord_id" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none transition-all">
                    <?php foreach ($landlords as $l): ?>
                        <option value="<?php echo $l['id']; ?>"><?php echo htmlspecialchars((string)$l['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Advance Amount (KSh)</label>
                <input type="number" name="amount" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none transition-all">
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Purpose / Note</label>
                <textarea name="purpose" rows="2" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none transition-all"></textarea>
            </div>
            <button type="submit" class="btn-primary w-full justify-center py-4">Register Advance Request</button>
        </form>
    </div>
</div>

<!-- Add Loan Modal -->
<div id="newLoanModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <button onclick="closeModal('newLoanModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-6">Financial Loan Application</h2>
        <p class="text-xs text-slate-500 mb-6 font-medium italic underline">Note: Total repayable is calculated including the fixed interest rate specified.</p>
        <form action="actions/landlord_fin_actions.php" method="POST" class="space-y-6">
            <input type="hidden" name="action" value="create_loan">
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Landlord</label>
                <select name="landlord_id" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none transition-all">
                    <?php foreach ($landlords as $l): ?>
                        <option value="<?php echo $l['id']; ?>"><?php echo htmlspecialchars((string)$l['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="grid grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Principal (KSh)</label>
                    <input type="number" name="principal_amount" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none transition-all">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Interest Rate (%)</label>
                    <input type="number" name="interest_rate" value="5" step="0.1" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none transition-all">
                </div>
            </div>
            <button type="submit" class="btn-green w-full justify-center py-4">Issue Financial Loan</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
