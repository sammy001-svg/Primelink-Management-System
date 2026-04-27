<?php
/**
 * Utility Tokens Page
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = getCurrentUser($pdo);
$role = $_SESSION['role'] ?? 'tenant';
$pageTitle = "Utility Tokens";

// Scoped Data Fetching
if ($role === 'tenant') {
    $stmtT = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
    $stmtT->execute([$_SESSION['user_id']]);
    $tenantId = $stmtT->fetchColumn(); $landlordId = null;

    $stmt = $pdo->prepare("
        SELECT k.*, p.title as property_title, u.unit_number 
        FROM tokens k
        LEFT JOIN properties p ON k.property_id = p.id
        LEFT JOIN units u ON k.unit_id = u.id
        WHERE k.tenant_id = ?
        ORDER BY k.created_at DESC
    ");
    $stmt->execute([$tenantId]);
} elseif ($role === 'landlord') {
    $landlordId = getLandlordId($pdo);
    $stmt = $pdo->prepare("
        SELECT k.*, t.full_name as tenant_name, p.title as property_title, u.unit_number 
        FROM tokens k
        LEFT JOIN tenants t ON k.tenant_id = t.id
        LEFT JOIN properties p ON k.property_id = p.id
        LEFT JOIN units u ON k.unit_id = u.id
        WHERE p.landlord_id = ?
        ORDER BY k.created_at DESC
    ");
    $stmt->execute([$landlordId]);
} else {
    $landlordId = null;
    $stmt = $pdo->query("
        SELECT k.*, t.full_name as tenant_name, p.title as property_title, u.unit_number 
        FROM tokens k
        LEFT JOIN tenants t ON k.tenant_id = t.id
        LEFT JOIN properties p ON k.property_id = p.id
        LEFT JOIN units u ON k.unit_id = u.id
        ORDER BY k.created_at DESC
    ");
}
$tokens = $stmt->fetchAll();

// Fetch tenants for dropdown (Staff/Landlord only)
if ($role === 'landlord') {
    $tenants = $pdo->prepare("
        SELECT DISTINCT t.id, t.full_name 
        FROM tenants t
        JOIN leases ls ON t.id = ls.tenant_id
        JOIN properties p ON ls.property_id = p.id
        WHERE p.landlord_id = ?
    ");
    $tenants->execute([$landlordId]);
    $tenants = $tenants->fetchAll();
} else {
    $tenants = $pdo->query("SELECT id, full_name FROM tenants ORDER BY full_name")->fetchAll();
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">
    <?php if (isset($_GET['success'])): ?>
    <div class="p-4 bg-green-500/10 border border-green-500/20 text-green-500 rounded-xl font-bold text-sm">
        Token successfully <?php echo $_GET['success']; ?>!
    </div>
    <?php endif; ?>

    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Utility Tokens</h1>
            <p class="text-slate-500 font-medium">Generate and manage electricity and water tokens.</p>
        </div>
        <?php if ($role === 'tenant'): ?>
        <button onclick="openModal('buyTokenModal')" class="btn-gold">
            Buy Tokens
        </button>
        <?php else: ?>
        <button onclick="openModal('generateTokenModal')" class="btn-primary">
            + Generate Token
        </button>
        <?php endif; ?>
    </div>

    <!-- Token Stats -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <div class="glass-card p-6 border-l-4 border-blue-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Active Tokens</p>
            <h3 class="text-3xl font-black mt-1"><?php echo count(array_filter($tokens, fn($k) => $k['status'] == 'Active')); ?></h3>
        </div>
        <div class="glass-card p-6 border-l-4 border-accent-gold">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Electricity Tokens</p>
            <h3 class="text-3xl font-black mt-1"><?php echo count(array_filter($tokens, fn($k) => $k['token_type'] == 'Electricity')); ?></h3>
        </div>
        <div class="glass-card p-6 border-l-4 border-green-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Water Tokens</p>
            <h3 class="text-3xl font-black mt-1"><?php echo count(array_filter($tokens, fn($k) => $k['token_type'] == 'Water')); ?></h3>
        </div>
    </div>

    <!-- History Table -->
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800">
            <h3 class="text-lg font-black">Token History</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-slate-50 dark:bg-slate-800/50">
                        <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Code</th>
                        <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Type</th>
                        <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Value</th>
                        <?php if ($role !== 'tenant'): ?>
                        <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tenant</th>
                        <?php endif; ?>
                        <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount</th>
                        <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                        <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php if (empty($tokens)): ?>
                    <tr><td colspan="7" class="p-20 text-center text-slate-400 font-bold italic">No tokens generated yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($tokens as $k): ?>
                    <tr class="group hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                        <td class="p-6">
                            <span class="font-black text-slate-900 dark:text-white select-all"><?php echo htmlspecialchars($k['token_code']); ?></span>
                        </td>
                        <td class="p-6">
                            <div class="flex items-center gap-2">
                                <?php if ($k['token_type'] == 'Electricity'): ?>
                                <svg class="text-yellow-500" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                                <?php else: ?>
                                <svg class="text-blue-500" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/></svg>
                                <?php endif; ?>
                                <span class="text-xs font-bold"><?php echo $k['token_type']; ?></span>
                            </div>
                        </td>
                        <td class="p-6">
                            <span class="text-xs font-black"><?php echo number_format($k['units_value'], 1); ?> <?php echo $k['token_type'] == 'Electricity' ? 'kWh' : 'Units'; ?></span>
                        </td>
                        <?php if ($role !== 'tenant'): ?>
                        <td class="p-6">
                            <p class="text-xs font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($k['tenant_name'] ?: 'N/A'); ?></p>
                            <p class="text-[10px] text-slate-400 font-bold"><?php echo htmlspecialchars($k['property_title'] ?: ''); ?> - <?php echo htmlspecialchars($k['unit_number'] ?: ''); ?></p>
                        </td>
                        <?php endif; ?>
                        <td class="p-6">
                            <span class="text-xs font-black">KSh <?php echo number_format($k['amount']); ?></span>
                        </td>
                        <td class="p-6">
                            <span class="text-[10px] font-bold text-slate-400"><?php echo date('M j, Y H:i', strtotime($k['created_at'])); ?></span>
                        </td>
                        <td class="p-6 text-right">
                            <span class="px-3 py-1 <?php echo $k['status'] == 'Active' ? 'bg-green-500/10 text-green-500' : 'bg-slate-500/10 text-slate-400'; ?> rounded-full text-[10px] font-black uppercase tracking-widest">
                                <?php echo $k['status']; ?>
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

<!-- Modal: Generate Token (Admin/Landlord) -->
<div id="generateTokenModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <button onclick="closeModal('generateTokenModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-8">Generate Utility Token</h2>
        <form action="actions/token_actions.php" method="POST" class="space-y-6">
            <input type="hidden" name="action" value="generate">
            
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Select Tenant</label>
                <select name="tenant_id" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-gold/20 transition-all outline-none">
                    <option value="">Choose Tenant</option>
                    <?php foreach ($tenants as $t): ?>
                    <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Utility Type</label>
                    <select name="token_type" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-gold/20 transition-all outline-none">
                        <option value="Electricity">Electricity</option>
                        <option value="Water">Water</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Amount (KSh)</label>
                    <input type="number" name="amount" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-gold/20 transition-all outline-none">
                </div>
            </div>

            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Units (kWh / Liters)</label>
                <input type="number" step="0.1" name="units_value" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-gold/20 transition-all outline-none">
            </div>

            <button type="submit" class="btn-primary w-full justify-center py-4">Generate & Record Payment</button>
        </form>
    </div>
</div>

<!-- Modal: Buy Token (Tenant) -->
<div id="buyTokenModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <button onclick="closeModal('buyTokenModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-8 text-center text-accent-gold">Buy Tokens</h2>
        <div class="p-6 bg-accent-gold/5 border border-accent-gold/10 rounded-2xl mb-8">
            <p class="text-xs text-center text-slate-500 font-bold leading-relaxed">
                Tokens will be generated automatically upon payment verification via your registered phone number.
            </p>
        </div>
        <form action="actions/token_actions.php" method="POST" class="space-y-6">
            <input type="hidden" name="action" value="purchase">
            
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Utility Type</label>
                <select name="token_type" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-gold/20 transition-all outline-none">
                    <option value="Electricity">Electricity (Token)</option>
                    <option value="Water">Water (Token)</option>
                </select>
            </div>

            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Select Amount</label>
                <div class="grid grid-cols-3 gap-3">
                    <?php foreach ([200, 500, 1000] as $amt): ?>
                    <label class="cursor-pointer">
                        <input type="radio" name="amount" value="<?php echo $amt; ?>" class="peer hidden" <?php echo ($amt == 500) ? 'checked' : ''; ?>>
                        <div class="p-4 text-center bg-slate-100 dark:bg-slate-800/50 rounded-xl font-black text-sm border-2 border-transparent peer-checked:border-accent-gold peer-checked:text-accent-gold transition-all">
                            KSh <?php echo $amt; ?>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Custom Amount</label>
                <input type="number" name="amount_custom" placeholder="Other amount..." class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-gold/20 transition-all outline-none">
            </div>

            <button type="submit" class="btn-gold w-full justify-center py-4">Pay with M-Pesa</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
