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
        <?php
            $msg   = $_GET['success'] ?? '';
            $phone = htmlspecialchars($_GET['phone'] ?? '');
            if ($msg === 'stk_sent') {
                echo '📲 STK Push sent to <strong>' . $phone . '</strong>! Enter your M-Pesa PIN on your phone to complete payment. Your token will be generated once confirmed.';
            } elseif ($msg === 'requested') {
                echo '✅ Token request submitted! Your token will be generated once payment is confirmed by admin.';
            } elseif ($msg === 'generated') {
                echo '🎉 Token generated! Code: <span class="font-mono bg-green-900/20 px-2 rounded">' . htmlspecialchars($_GET['code'] ?? '') . '</span>';
            } else {
                echo 'Token successfully ' . htmlspecialchars($msg) . '!';
            }
        ?>
    </div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
    <div class="p-4 bg-red-500/10 border border-red-500/20 text-red-500 rounded-xl font-bold text-sm">
        Error: <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
    <?php endif; ?>

    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Utility Tokens</h1>
            <p class="text-slate-500 font-medium">Generate and manage electricity and water tokens.</p>
        </div>
        <?php if ($role === 'tenant'): ?>
        <button onclick="openModal('buyTokenModal')" class="btn-green">
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
        <div class="glass-card p-6 border-l-4 border-accent-green">
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
                        <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Token Code / Meter</th>
                        <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Type</th>
                        <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Units</th>
                        <?php if ($role !== 'tenant'): ?>
                        <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tenant</th>
                        <?php endif; ?>
                        <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount</th>
                        <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                        <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php if (empty($tokens)): ?>
                    <tr><td colspan="8" class="p-20 text-center text-slate-400 font-bold italic">No tokens yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($tokens as $k): ?>
                    <tr class="group hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                        <td class="p-6">
                            <?php if ($k['status'] === 'Active'): ?>
                                <span class="font-mono font-black text-slate-900 dark:text-white select-all text-sm"><?php echo htmlspecialchars($k['token_code']); ?></span>
                            <?php else: ?>
                                <span class="text-xs text-slate-400 italic">Pending generation...</span>
                            <?php endif; ?>
                            <?php if (!empty($k['meter_number'])): ?>
                            <p class="text-[10px] text-slate-400 font-bold mt-0.5">Meter: <?php echo htmlspecialchars($k['meter_number']); ?></p>
                            <?php endif; ?>
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
                        <td class="p-6"><span class="text-xs font-black"><?php echo number_format($k['units_value'], 1); ?> <?php echo $k['token_type'] == 'Electricity' ? 'kWh' : 'L'; ?></span></td>
                        <?php if ($role !== 'tenant'): ?>
                        <td class="p-6">
                            <p class="text-xs font-bold"><?php echo htmlspecialchars($k['tenant_name'] ?? 'N/A'); ?></p>
                            <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($k['unit_number'] ?? ''); ?></p>
                        </td>
                        <?php endif; ?>
                        <td class="p-6"><span class="text-xs font-black">KSh <?php echo number_format($k['amount']); ?></span></td>
                        <td class="p-6"><span class="text-[10px] font-bold text-slate-400"><?php echo date('M j, Y H:i', strtotime($k['created_at'])); ?></span></td>
                        <td class="p-6 text-right">
                            <?php if ($k['status'] === 'Pending' && ($role === 'admin' || $role === 'staff')): ?>
                                <button onclick="openConfirmModal('<?php echo $k['id']; ?>', '<?php echo htmlspecialchars($k['tenant_name'] ?? ''); ?>', '<?php echo $k['token_type']; ?>', <?php echo $k['amount']; ?>)" class="px-3 py-1.5 bg-accent-green text-slate-900 rounded-lg text-[10px] font-black uppercase hover:scale-105 transition-all">
                                    Confirm &amp; Generate
                                </button>
                            <?php else: ?>
                                <span class="px-3 py-1 <?php echo $k['status'] == 'Active' ? 'bg-green-500/10 text-green-500' : 'bg-orange-500/10 text-orange-500'; ?> rounded-full text-[10px] font-black uppercase">
                                    <?php echo $k['status']; ?>
                                </span>
                            <?php endif; ?>
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
                <select name="tenant_id" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    <option value="">Choose Tenant</option>
                    <?php foreach ($tenants as $t): ?>
                    <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grid grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Utility Type</label>
                    <select name="token_type" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                        <option value="Electricity">Electricity</option>
                        <option value="Water">Water</option>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Amount (KSh)</label>
                    <input type="number" name="amount" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                </div>
            </div>

            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Units (kWh / Liters)</label>
                <input type="number" step="0.1" name="units_value" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
            </div>

            <button type="submit" class="btn-primary w-full justify-center py-4">Generate & Record Payment</button>
        </form>
    </div>
</div>

<!-- Modal: Buy Token (Tenant) -->
<div id="buyTokenModal" class="modal-overlay" style="display:none;">
    <div class="modal-card max-w-lg">
        <button onclick="closeModal('buyTokenModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-2 text-accent-green">Buy Utility Token</h2>
        <p class="text-xs text-slate-500 font-medium mb-8">Your token will be generated once payment is confirmed.</p>

        <form action="actions/token_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="purchase">

            <!-- Step 1: Utility Type -->
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">1. Select Utility Type</label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="cursor-pointer">
                        <input type="radio" name="token_type" value="Electricity" class="peer hidden" checked>
                        <div class="flex items-center gap-3 p-4 bg-slate-100 dark:bg-slate-800/50 rounded-xl border-2 border-transparent peer-checked:border-yellow-400 peer-checked:bg-yellow-50 dark:peer-checked:bg-yellow-900/10 transition-all">
                            <svg class="text-yellow-500 shrink-0" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                            <div>
                                <p class="text-xs font-black">Electricity</p>
                                <p class="text-[10px] text-slate-400">KPLC Token</p>
                            </div>
                        </div>
                    </label>
                    <label class="cursor-pointer">
                        <input type="radio" name="token_type" value="Water" class="peer hidden">
                        <div class="flex items-center gap-3 p-4 bg-slate-100 dark:bg-slate-800/50 rounded-xl border-2 border-transparent peer-checked:border-blue-400 peer-checked:bg-blue-50 dark:peer-checked:bg-blue-900/10 transition-all">
                            <svg class="text-blue-500 shrink-0" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/></svg>
                            <div>
                                <p class="text-xs font-black">Water</p>
                                <p class="text-[10px] text-slate-400">Water Token</p>
                            </div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Step 2: Meter Number -->
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">2. Meter Number</label>
                <input type="text" name="meter_number" required placeholder="E.g. 0101234567890" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold font-mono focus:ring-2 focus:ring-accent-green/20 outline-none tracking-wider">
            </div>

            <!-- Step 3: Amount -->
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">3. Amount (KSh)</label>
                <div class="grid grid-cols-4 gap-2 mb-2">
                    <?php foreach ([200, 500, 1000, 2000] as $amt): ?>
                    <label class="cursor-pointer">
                        <input type="radio" name="amount" value="<?php echo $amt; ?>" class="peer hidden">
                        <div class="p-3 text-center bg-slate-100 dark:bg-slate-800/50 rounded-xl font-black text-xs border-2 border-transparent peer-checked:border-accent-green peer-checked:text-accent-green transition-all">
                            <?php echo number_format($amt); ?>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
                <input type="number" name="amount" placeholder="Or enter custom amount..." min="50" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
            </div>

            <!-- Step 4: Payment Method -->
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">4. Payment Method</label>
                <select name="payment_method" id="token_payment_method" onchange="toggleTokenPhone(this.value)" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                    <option value="M-Pesa STK">M-Pesa STK Push</option>
                    <option value="M-Pesa Paybill">M-Pesa Paybill (1234567)</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                </select>
            </div>

            <!-- STK Push Phone Number (shown only for M-Pesa STK) -->
            <div id="stkPhoneBlock" class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Phone Number for STK Push</label>
                <div class="relative">
                    <span class="absolute left-5 top-1/2 -translate-y-1/2 text-sm font-black text-slate-400">+254</span>
                    <input type="tel" name="phone_number" id="stkPhoneInput" placeholder="7XXXXXXXX" maxlength="9"
                        class="w-full pl-16 pr-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold font-mono focus:ring-2 focus:ring-accent-green/20 outline-none tracking-widest">
                </div>
                <p class="text-[10px] text-slate-400 font-bold px-2">⚡ You will receive an M-Pesa prompt on your phone to enter your PIN.</p>
            </div>

            <!-- Paybill Instructions (shown only for Paybill) -->
            <div id="paybillBlock" class="hidden bg-orange-50 dark:bg-orange-900/10 p-4 rounded-xl border border-orange-200/50">
                <p class="text-[10px] font-black text-orange-600 uppercase mb-2">Paybill Instructions</p>
                <p class="text-xs font-bold text-slate-700 dark:text-slate-300">1. Go to M-Pesa → Lipa Na M-Pesa → Paybill</p>
                <p class="text-xs font-bold text-slate-700 dark:text-slate-300">2. Business No: <span class="font-black text-orange-600">1234567</span></p>
                <p class="text-xs font-bold text-slate-700 dark:text-slate-300">3. Account No: <span class="font-black">Your Meter Number</span></p>
            </div>

            <!-- Reference (optional) -->
            <div id="referenceBlock" class="hidden space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Transaction Reference / Code</label>
                <input type="text" name="reference" placeholder="E.g. QHX1234567" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold font-mono focus:ring-2 focus:ring-accent-green/20 outline-none tracking-wider">
            </div>

            <button type="submit" id="tokenSubmitBtn" class="btn-green w-full justify-center py-4 text-sm">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="mr-2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
                Send STK Push
            </button>
        </form>
    </div>
</div>

<!-- Modal: Confirm & Generate Token (Admin/Staff) -->
<div id="confirmTokenModal" class="modal-overlay" style="display:none;">
    <div class="modal-card max-w-md">
        <button onclick="closeModal('confirmTokenModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-xl font-black mb-2">Confirm Payment &amp; Generate Token</h2>
        <p class="text-xs text-slate-500 mb-6">Verify the payment has been received, then enter the units to generate the token.</p>
        <form action="actions/token_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="confirm_and_generate">
            <input type="hidden" name="token_id" id="confirm_token_id">
            <div class="bg-slate-50 dark:bg-slate-800/50 p-4 rounded-xl space-y-1" id="confirm_details">
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Units to Load</label>
                <input type="number" name="units_value" step="0.1" required placeholder="E.g. 50.0 kWh" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
            </div>
            <button type="submit" class="btn-green w-full justify-center py-4">✅ Confirm &amp; Generate Token</button>
        </form>
    </div>
</div>

<script>
function toggleTokenPhone(method) {
    const stkBlock    = document.getElementById('stkPhoneBlock');
    const paybillBlock = document.getElementById('paybillBlock');
    const refBlock    = document.getElementById('referenceBlock');
    const stkInput    = document.getElementById('stkPhoneInput');
    const submitBtn   = document.getElementById('tokenSubmitBtn');

    // Reset
    stkBlock.classList.add('hidden');
    paybillBlock.classList.add('hidden');
    refBlock.classList.add('hidden');
    stkInput.removeAttribute('required');

    if (method === 'M-Pesa STK') {
        stkBlock.classList.remove('hidden');
        stkInput.setAttribute('required', 'required');
        submitBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="mr-2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg> Send STK Push';
    } else if (method === 'M-Pesa Paybill') {
        paybillBlock.classList.remove('hidden');
        refBlock.classList.remove('hidden');
        submitBtn.innerHTML = '✅ Submit Payment Notification';
    } else {
        refBlock.classList.remove('hidden');
        submitBtn.innerHTML = '✅ Submit Bank Transfer Notification';
    }
}

function openConfirmModal(tokenId, tenantName, tokenType, amount) {
    document.getElementById('confirm_token_id').value = tokenId;
    const icon = tokenType === 'Electricity' ? '⚡' : '💧';
    document.getElementById('confirm_details').innerHTML = `
        <p class="text-xs font-bold text-slate-500">Tenant: <span class="text-slate-900 dark:text-white">${tenantName}</span></p>
        <p class="text-xs font-bold text-slate-500">Type: <span class="text-slate-900 dark:text-white">${icon} ${tokenType}</span></p>
        <p class="text-xs font-bold text-slate-500">Amount Paid: <span class="text-slate-900 dark:text-white font-black">KSh ${parseInt(amount).toLocaleString()}</span></p>
    `;
    openModal('confirmTokenModal');
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    const sel = document.getElementById('token_payment_method');
    if (sel) toggleTokenPhone(sel.value);
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
