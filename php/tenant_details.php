<?php
/**
 * Tenant Intelligence Hub - 360 Degree Profile
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['staff']); // Admin & Staff only

$tenantId = $_GET['id'] ?? null;
if (!$tenantId) {
    header("Location: tenants.php");
    exit();
}

// Fetch Tenant & Profile data
$stmt = $pdo->prepare("
    SELECT t.*, u.email as user_email, p.address as physical_address
    FROM tenants t
    JOIN users u ON t.user_id = u.id
    JOIN profiles p ON t.user_id = p.id
    WHERE t.id = ?
");
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();

if (!$tenant) {
    die("Tenant not found.");
}

// Fetch Current Active Lease & Unit
$stmt = $pdo->prepare("
    SELECT l.*, u.unit_number, u.unit_type, pr.title as property_title, pr.location as property_location
    FROM leases l
    JOIN units u ON l.unit_id = u.id
    JOIN properties pr ON u.property_id = pr.id
    WHERE l.tenant_id = ? AND l.status = 'Active'
    LIMIT 1
");
$stmt->execute([$tenantId]);
$activeLease = $stmt->fetch();

// Fetch Financials
$stmt = $pdo->prepare("SELECT * FROM transactions WHERE tenant_id = ? ORDER BY transaction_date DESC");
$stmt->execute([$tenantId]);
$transactions = $stmt->fetchAll();

$totalPaid = 0;
$totalPending = 0;

$balances = [
    'Rent' => 0,
    'Water' => 0,
    'Service Charge' => 0,
    'Penalty' => 0,
    'Other' => 0
];

foreach ($transactions as $tx) {
    if ($tx['status'] === 'Paid') {
        $totalPaid += $tx['amount'];
    } else {
        $totalPending += $tx['amount'];
        
        // Categorize balance
        $type = $tx['transaction_type'];
        if ($type === 'Rent' || $type === 'Deposit') $balances['Rent'] += $tx['amount'];
        elseif ($type === 'Water' || $type === 'Water Token') $balances['Water'] += $tx['amount'];
        elseif ($type === 'Service Charge') $balances['Service Charge'] += $tx['amount'];
        elseif ($type === 'Penalty') $balances['Penalty'] += $tx['amount'];
        else $balances['Other'] += $tx['amount'];
    }
}

$pageTitle = $tenant['full_name'] . " | Intelligence";
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">
    <!-- Header with Quick Stats -->
    <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6 bg-slate-900 rounded-[2.5rem] p-8 lg:p-12 text-white relative overflow-hidden shadow-2xl">
        <div class="relative z-10">
            <div class="flex items-center gap-2 mb-4">
                <a href="tenants.php" class="text-[10px] font-black text-slate-400 uppercase tracking-widest hover:text-accent-green transition-colors flex items-center gap-1">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m15 18-6-6 6-6"/></svg>
                    Back to Registry
                </a>
            </div>
            <div class="flex items-center gap-6">
                <div class="w-20 h-20 rounded-[2rem] bg-accent-green flex items-center justify-center text-3xl font-black shadow-xl ring-4 ring-white/10">
                    <?php echo substr($tenant['full_name'], 0, 1); ?>
                </div>
                <div>
                    <h1 class="text-3xl lg:text-4xl font-black tracking-tight"><?php echo htmlspecialchars((string)($tenant['full_name'] ?? '')); ?></h1>
                    <p class="text-slate-400 font-medium flex items-center gap-2 mt-1">
                        <span class="px-2 py-0.5 bg-white/10 rounded text-[10px] font-black uppercase tracking-widest text-accent-green">PRM-<?php echo substr((string)($tenant['id'] ?? ''), 0, 4); ?></span>
                        • <?php echo htmlspecialchars((string)($tenant['email'] ?? '')); ?>
                    </p>
                </div>
            </div>
        </div>
        
        <div class="flex gap-4 lg:gap-8 relative z-10 shrink-0">
            <div class="text-right">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Financial Balance</p>
                <h3 class="text-3xl font-black text-accent-orange">KSh <?php echo number_format($totalPending); ?></h3>
                <button onclick="triggerSTKPush('<?php echo $tenant['id']; ?>')" class="text-[10px] font-black text-white hover:text-accent-orange transition-colors uppercase tracking-widest mt-2 bg-white/5 px-3 py-1.5 rounded-full inline-block border border-white/10">⚡ STK Push</button>
            </div>
            <div class="w-px h-16 bg-white/10 hidden lg:block"></div>
            <div class="text-right">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Status</p>
                <span class="inline-block px-4 py-1.5 bg-accent-green text-white rounded-full text-[10px] font-black uppercase tracking-widest mt-1">
                    <?php echo (string)($tenant['status'] ?? 'Active'); ?>
                </span>
            </div>
        </div>

        <!-- Decorative elements -->
        <div class="absolute -right-12 -top-12 w-64 h-64 bg-accent-green/10 rounded-full blur-3xl"></div>
    </div>

    <!-- Tabs Navigation -->
    <div class="flex gap-2 p-1.5 bg-slate-100 dark:bg-slate-900 rounded-2xl w-max border border-slate-200 dark:border-slate-800">
        <button onclick="switchTab('overview')" id="tab-overview" class="tab-btn active px-6 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all">Overview</button>
        <button onclick="switchTab('profile')" id="tab-profile" class="tab-btn px-6 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all">Profile Intelligence</button>
        <button onclick="switchTab('financials')" id="tab-financials" class="tab-btn px-6 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all">Financial Portfolio</button>
        <button onclick="switchTab('security')" id="tab-security" class="tab-btn px-6 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest transition-all">Security</button>
    </div>

    <!-- Tab Contents -->
    <div id="content-overview" class="tab-content">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Left: Occupancy Detail -->
            <div class="lg:col-span-2 space-y-8">
                <div class="glass-card p-8 relative overflow-hidden">
                    <h3 class="text-sm font-black text-slate-400 uppercase tracking-widest mb-8 border-b border-slate-100 dark:border-slate-800 pb-4">Active Residency</h3>
                    <?php if ($activeLease): ?>
                    <div class="flex flex-col md:flex-row gap-8 items-center">
                        <div class="w-full md:w-48 h-32 rounded-3xl overflow-hidden shadow-2xl">
                            <img src="https://images.unsplash.com/photo-1570129477492-45c003edd2be?q=80&w=400" class="w-full h-full object-cover">
                        </div>
                        <div class="flex-1 space-y-2">
                            <p class="text-[10px] font-black text-accent-green uppercase tracking-widest">Currently Occupying</p>
                            <h4 class="text-2xl font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars((string)$activeLease['property_title']); ?> — Unit <?php echo htmlspecialchars((string)$activeLease['unit_number']); ?></h4>
                            <p class="text-sm font-medium text-slate-500 uppercase tracking-tighter"><?php echo htmlspecialchars((string)$activeLease['property_location']); ?> • <?php echo htmlspecialchars((string)$activeLease['unit_type']); ?></p>
                            <div class="pt-4 flex gap-4">
                                <a href="property_details.php?id=<?php echo $activeLease['property_id']; ?>" class="text-[10px] font-black text-slate-900 dark:text-white uppercase tracking-widest hover:text-accent-green transition-colors">Manage Unit →</a>
                                <a href="view_lease.php?tenant_id=<?php echo $tenant['id']; ?>" class="text-[10px] font-black text-slate-900 dark:text-white uppercase tracking-widest hover:text-accent-green transition-colors">View Lease Agreement</a>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="py-12 text-center">
                        <div class="w-16 h-16 bg-slate-50 dark:bg-slate-800 rounded-2xl flex items-center justify-center mx-auto mb-4 text-slate-300">
                             <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7"/><path d="M19 21V7"/></svg>
                        </div>
                        <p class="text-sm font-bold text-slate-400">No active residency found for this tenant.</p>
                        <a href="leases.php?action=new&tenant_id=<?php echo (string)($tenant['id'] ?? ''); ?>" class="btn-green mt-6 inline-flex">Assign Unit Now</a>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="glass-card p-6">
                        <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Identity Verification</h4>
                        <div class="space-y-4">
                            <div class="flex justify-between">
                                <span class="text-xs font-bold text-slate-500 uppercase">ID No.</span>
                                <span class="text-xs font-black"><?php echo (string)($tenant['id_no'] ?? 'Not Provided'); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-xs font-bold text-slate-500 uppercase">Verification</span>
                                <span class="text-xs font-black text-accent-green italic underline">Download Copy</span>
                            </div>
                        </div>
                    </div>
                    <div class="glass-card p-6">
                        <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Professional Index</h4>
                        <div class="space-y-4">
                            <div class="flex justify-between">
                                <span class="text-xs font-bold text-slate-500 uppercase">Profession</span>
                                <span class="text-xs font-black"><?php echo (string)($tenant['profession'] ?? 'Not Stated'); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-xs font-bold text-slate-500 uppercase">Employer</span>
                                <span class="text-xs font-black truncate max-w-[120px]"><?php echo (string)($tenant['employer_name'] ?? 'N/A'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: Next of Kin / Communication -->
            <div class="space-y-8">
                 <div class="glass-card p-6 bg-linear-to-br from-slate-900 to-slate-950 text-white border-none">
                    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-6">Next of Kin Registry</h3>
                    <div class="space-y-4 font-medium">
                        <div>
                            <p class="text-[8px] text-slate-500 uppercase font-black tracking-widest mb-1">Primary Guardian</p>
                            <p class="text-sm font-black"><?php echo htmlspecialchars((string)($tenant['next_of_kin_name'] ?? 'None Designated')); ?></p>
                        </div>
                        <div>
                            <p class="text-[8px] text-slate-500 uppercase font-black tracking-widest mb-1">Relationship</p>
                            <p class="text-xs font-bold text-accent-orange"><?php echo htmlspecialchars((string)($tenant['next_of_kin_relationship'] ?? 'N/A')); ?></p>
                        </div>
                        <div class="pt-2">
                             <a href="tel:<?php echo (string)($tenant['next_of_kin_contact'] ?? ''); ?>" class="w-full py-3 bg-white/5 border border-white/10 rounded-xl flex items-center justify-center gap-2 text-xs font-black uppercase tracking-widest hover:bg-white/10 transition-all">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                Emergency Call
                             </a>
                        </div>
                    </div>
                </div>

                <div class="glass-card p-6">
                    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-6">Management Actions</h3>
                    <div class="space-y-3">
                        <button onclick="sendReminder('<?php echo $tenant['id']; ?>', 'payment')" class="w-full p-4 bg-slate-50 dark:bg-slate-800/50 rounded-2xl flex items-center gap-4 text-xs font-black uppercase tracking-widest hover:translate-x-1 transition-all">
                            <div class="w-8 h-8 rounded-lg bg-accent-orange/10 text-accent-orange flex items-center justify-center"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg></div>
                            Log Payment Reminder
                        </button>
                        <button onclick="sendReminder('<?php echo $tenant['id']; ?>', 'maintenance')" class="w-full p-4 bg-slate-50 dark:bg-slate-800/50 rounded-2xl flex items-center gap-4 text-xs font-black uppercase tracking-widest hover:translate-x-1 transition-all">
                            <div class="w-8 h-8 rounded-lg bg-blue-500/10 text-blue-500 flex items-center justify-center"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg></div>
                            Schedule Visit
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab 2: Profile Editing -->
    <div id="content-profile" class="tab-content hidden animate-in slide-in-from-bottom-4">
        <div class="glass-card p-10">
            <h3 class="text-2xl font-black mb-8 tracking-tight">Intelligence Parameters</h3>
            <form action="actions/tenant_detail_actions.php" method="POST" class="space-y-8">
                <input type="hidden" name="action" value="update_profile">
                <input type="hidden" name="tenant_id" value="<?php echo (string)($tenant['id'] ?? ''); ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Primary Full Name</label>
                        <input type="text" name="full_name" value="<?php echo htmlspecialchars((string)($tenant['full_name'] ?? '')); ?>" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Phone Registry</label>
                        <input type="text" name="phone" value="<?php echo htmlspecialchars((string)($tenant['phone'] ?? '')); ?>" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Physical Residency Address</label>
                    <input type="text" name="address" value="<?php echo htmlspecialchars((string)($tenant['physical_address'] ?? '')); ?>" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 pt-8 border-t border-slate-100 dark:border-slate-800">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Profession</label>
                        <input type="text" name="profession" value="<?php echo htmlspecialchars((string)($tenant['profession'] ?? '')); ?>" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Employer Entity</label>
                        <input type="text" name="employer_name" value="<?php echo htmlspecialchars((string)($tenant['employer_name'] ?? '')); ?>" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Marital Status</label>
                        <select name="marital_status" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                            <option value="Single" <?php echo ($tenant['marital_status'] ?? 'Single') === 'Single' ? 'selected' : ''; ?>>Single</option>
                            <option value="Married" <?php echo ($tenant['marital_status'] ?? '') === 'Married' ? 'selected' : ''; ?>>Married</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn-green shadow-xl shadow-accent-green/10 px-12 py-4">Synchronize Intelligence</button>
            </form>
        </div>
    </div>

    <!-- Tab 3: Financials -->
    <div id="content-financials" class="tab-content hidden animate-in slide-in-from-bottom-4">
        <!-- Intelligent Balance Breakdown -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="glass-card p-6 border-l-4 border-l-accent-green shadow-xl shadow-accent-green/5">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Rent & Deposit</p>
                <h4 class="text-xl font-black text-slate-900 dark:text-white italic tracking-tighter">KSh <?php echo number_format($balances['Rent']); ?></h4>
            </div>
            <div class="glass-card p-6 border-l-4 border-l-blue-500 shadow-xl shadow-blue-500/5">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Water Ledger</p>
                <h4 class="text-xl font-black text-slate-900 dark:text-white italic tracking-tighter">KSh <?php echo number_format($balances['Water']); ?></h4>
            </div>
            <div class="glass-card p-6 border-l-4 border-l-purple-500 shadow-xl shadow-purple-500/5">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Service Charges</p>
                <h4 class="text-xl font-black text-slate-900 dark:text-white italic tracking-tighter">KSh <?php echo number_format($balances['Service Charge']); ?></h4>
            </div>
            <div class="glass-card p-6 border-l-4 border-l-red-500 shadow-xl shadow-red-500/5">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Penalties</p>
                <h4 class="text-xl font-black text-slate-900 dark:text-white italic tracking-tighter">KSh <?php echo number_format($balances['Penalty']); ?></h4>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">
            <div class="xl:col-span-2 space-y-8">
                <div class="glass-card overflow-hidden">
                    <div class="p-8 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
                        <div>
                            <h3 class="text-xl font-black tracking-tight">Financial Statement</h3>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Complete transaction lifecycle</p>
                        </div>
                        <button onclick="window.print()" class="p-3 bg-slate-50 dark:bg-slate-800 rounded-xl text-slate-500 hover:text-accent-green transition-all shadow-sm">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                        </button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50 dark:bg-slate-800/30">
                                <tr>
                                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Reference</th>
                                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Transaction Date</th>
                                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount</th>
                                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                <?php if (empty($transactions)): ?>
                                    <tr><td colspan="4" class="p-12 text-center text-slate-400 font-medium italic">No ledger entries recorded.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($transactions as $tx): ?>
                                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/10 transition-all font-medium">
                                        <td class="p-6">
                                            <p class="text-xs font-black text-slate-900 dark:text-white"><?php echo (string)($tx['reference_code'] ?? '') ?: '#TRX-'.substr((string)($tx['id'] ?? ''), 0, 6); ?></p>
                                        </td>
                                        <td class="p-6">
                                            <p class="text-xs text-slate-500"><?php echo date('M d, Y H:i', strtotime($tx['transaction_date'])); ?></p>
                                        </td>
                                        <td class="p-6">
                                            <p class="text-xs font-black">KSh <?php echo number_format($tx['amount']); ?></p>
                                        </td>
                                        <td class="p-6 text-right">
                                            <span class="px-2.5 py-1 <?php echo ($tx['status'] ?? '') === 'Paid' ? 'bg-accent-green/10 text-accent-green border-accent-green/20' : 'bg-red-500/10 text-red-500 border-red-500/20'; ?> rounded-full text-[9px] font-black uppercase border tracking-widest">
                                                <?php echo (string)($tx['status'] ?? 'Pending'); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="space-y-8">
                <div class="glass-card p-8 bg-slate-900 text-white relative overflow-hidden">
                    <div class="relative z-10">
                        <p class="text-[10px] font-black text-accent-orange uppercase tracking-[0.3em] mb-2">Instant Settlement</p>
                        <h4 class="text-2xl font-black mb-6 leading-tight">Request Arrears <br>via STK Push</h4>
                        <div class="space-y-4">
                            <div class="bg-white/5 p-4 rounded-xl border border-white/10">
                                <p class="text-[10px] text-slate-500 font-black uppercase mb-1">Target Account</p>
                                <p class="text-sm font-black text-white"><?php echo (string)($tenant['phone'] ?? ''); ?></p>
                            </div>
                            <div class="bg-white/5 p-4 rounded-xl border border-white/10">
                                <p class="text-[10px] text-slate-500 font-black uppercase mb-1">Unpaid Balance</p>
                                <p class="text-sm font-black text-accent-orange">KSh <?php echo number_format($totalPending); ?></p>
                            </div>
                             <button onclick="triggerSTKPush('<?php echo $tenant['id']; ?>')" class="w-full py-4 bg-accent-orange text-white rounded-2xl font-black text-xs uppercase tracking-widest shadow-2xl hover:scale-[1.02] active:scale-95 transition-all">Trigger STK Push</button>
                        </div>
                    </div>
                    <div class="absolute -right-8 -bottom-8 w-40 h-40 bg-accent-orange/10 rounded-full blur-3xl"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab 4: Security -->
    <div id="content-security" class="tab-content hidden animate-in slide-in-from-bottom-4">
        <div class="glass-card p-10 max-w-2xl">
            <h3 class="text-2xl font-black mb-8 tracking-tight text-red-500">Access Override</h3>
            <p class="text-sm font-medium text-slate-500 mb-8 leading-relaxed">Overwrite existing authentication protocols. Use this to force password resets in case of lockdown or access recovery requests.</p>
            
            <form action="actions/tenant_detail_actions.php" method="POST" class="space-y-6">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="tenant_id" value="<?php echo (string)($tenant['id'] ?? ''); ?>">
                
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">New Force Password</label>
                    <input type="password" name="new_password" required placeholder="••••••••" class="w-full px-5 py-4 bg-slate-50 dark:bg-slate-800 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-red-500/20 outline-none">
                </div>
                 <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Confirm Override</label>
                    <input type="password" name="confirm_password" required placeholder="••••••••" class="w-full px-5 py-4 bg-slate-50 dark:bg-slate-800 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-red-500/20 outline-none">
                </div>

                <button type="submit" class="bg-red-500 text-white px-12 py-4 rounded-2xl font-black text-xs uppercase tracking-widest shadow-xl shadow-red-500/10 transform transition hover:scale-105 active:scale-95">Reset Access Points</button>
            </form>
        </div>
    </div>
</div>

<script>
function switchTab(tab) {
    // Hide all contents
    document.querySelectorAll('.tab-content').forEach(c => c.classList.add('hidden'));
    // Remove active class from buttons
    document.querySelectorAll('.tab-btn').forEach(b => {
        b.classList.remove('active', 'bg-white', 'dark:bg-slate-800', 'shadow-xl', 'text-accent-green');
        b.classList.add('text-slate-500');
    });

    // Show designated content
    document.getElementById('content-' + tab).classList.remove('hidden');
    // Set active button
    const activeBtn = document.getElementById('tab-' + tab);
    activeBtn.classList.add('active', 'bg-white', 'dark:bg-slate-800', 'shadow-xl', 'text-accent-green');
    activeBtn.classList.remove('text-slate-500');
}

function triggerSTKPush(tenantId) {
    alert("STK Push Triggered for balance. Requesting M-Pesa authentication on tenant's device...");
}

function sendReminder(tenantId, type) {
    alert("Official reminder sent via SMS and Email Registry.");
}

// Set initial tab
switchTab('overview');
</script>

<style>
.tab-btn.active { color: #22c55e; }
@media print {
    .no-print, nav, .sidebar-wrap, .tab-btn, .btn-green, header { display: none !important; }
    .glass-card { border: none !important; box-shadow: none !important; }
    body { background: white !important; }
    .tab-content { display: block !important; }
    #content-financials { display: block !important; }
    #content-overview, #content-profile, #content-security { display: none !important; }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
