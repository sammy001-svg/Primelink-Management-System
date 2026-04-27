<?php
/**
 * Tenant Management Page
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user   = getCurrentUser($pdo);
$role   = $_SESSION['role'] ?? 'tenant';
$pageTitle = "Tenants";

if ($role === 'tenant') {
    header('Location: dashboard.php');
    exit();
}

// Landlords only see tenants in their properties
if ($role === 'landlord') {
    $landlordId = getLandlordId($pdo);
    $stmt = $pdo->prepare("
        SELECT DISTINCT t.*, u.id as user_uuid
        FROM tenants t
        LEFT JOIN profiles u ON t.user_id = u.id
        JOIN leases ls ON ls.tenant_id = t.id AND ls.status = 'Active'
        JOIN units un ON ls.unit_id = un.id
        JOIN properties p ON un.property_id = p.id
        WHERE p.landlord_id = ?
        ORDER BY t.created_at DESC
    ");
    $stmt->execute([$landlordId]);
    $tenants = $stmt->fetchAll();
    $canAddTenant = false;
} else {
    requireRole(['staff']);
    $stmt = $pdo->query("SELECT t.*, u.id as user_uuid FROM tenants t LEFT JOIN profiles u ON t.user_id = u.id ORDER BY t.created_at DESC");
    $tenants = $stmt->fetchAll();
    $canAddTenant = true;
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">
    <?php if (isset($_GET['success'])): ?>
    <div class="p-4 bg-green-500/10 border border-green-500/20 text-green-500 rounded-xl font-bold text-sm animate-in fade-in slide-in-from-top-4">
        Tenant created successfully!
    </div>
    <?php endif; ?>

    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Tenant Management</h1>
            <p class="text-slate-500 font-medium"><?php echo $role === 'landlord' ? 'Tenants across your properties.' : 'Manage leaseholders and their records.'; ?></p>
        </div>
        <?php if ($canAddTenant): ?>
        <button onclick="openModal('newTenantModal')" class="btn-primary">
            + Add Tenant
        </button>
        <?php endif; ?>
    </div>

    <!-- New Tenant Modal -->
    <div id="newTenantModal" class="modal-overlay" style="display:none;">
        <div class="modal-card">
            <button onclick="closeModal('newTenantModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            
            <h2 class="text-2xl font-black mb-8">Register New Tenant</h2>
            
            <form action="actions/tenant_actions.php" method="POST" class="space-y-6">
                <input type="hidden" name="action" value="create">
                
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Full Name</label>
                    <input type="text" name="full_name" required placeholder="John Doe" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Email Address</label>
                    <input type="email" name="email" required placeholder="john@example.com" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Phone Number</label>
                    <input type="text" name="phone" placeholder="+254..." class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Initial Password</label>
                    <input type="password" name="password" required placeholder="••••••••" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                </div>

                <button type="submit" class="btn-green w-full justify-center py-4">Register Tenant</button>
            </form>
        </div>
    </div>

    <!-- Search/Filter Bar -->
    <div class="glass-card p-4 flex gap-4">
        <div class="flex-1 relative">
            <svg class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input type="text" placeholder="Search tenants by name or email..." class="w-full pl-12 pr-4 py-2 bg-slate-50 dark:bg-slate-800 border-none rounded-xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20">
        </div>
        <select class="px-4 py-2 bg-slate-50 dark:bg-slate-800 rounded-xl text-sm font-bold border-none">
            <option>All Status</option>
            <option>Active</option>
            <option>Pending</option>
            <option>Inactive</option>
        </select>
    </div>

    <!-- Tenants List -->
    <div class="glass-card overflow-hidden">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-slate-50 dark:bg-slate-800/50">
                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tenant</th>
                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Contact</th>
                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Joined</th>
                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php if (empty($tenants)): ?>
                    <tr>
                        <td colspan="5" class="p-20 text-center text-slate-400 italic font-medium">No tenants registered yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tenants as $t): ?>
                    <tr class="group hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                        <td class="p-6">
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-accent-green font-black shadow-inner">
                                    <?php echo substr($t['full_name'], 0, 1); ?>
                                </div>
                                <div>
                                    <p class="text-sm font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($t['full_name']); ?></p>
                                    <p class="text-[10px] font-bold text-slate-400 uppercase">PRM-<?php echo substr($t['id'], 0, 4); ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="p-6">
                            <p class="text-sm font-bold text-slate-600 dark:text-slate-400"><?php echo htmlspecialchars($t['email']); ?></p>
                            <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($t['phone'] ?: 'No phone'); ?></p>
                        </td>
                        <td class="p-6">
                            <span class="px-3 py-1 bg-green-500/10 text-green-500 rounded-full text-[10px] font-black uppercase tracking-widest">
                                <?php echo htmlspecialchars($t['status']); ?>
                            </span>
                        </td>
                        <td class="p-6">
                            <p class="text-xs font-bold text-slate-600 dark:text-slate-400"><?php echo date('M d, Y', strtotime($t['created_at'])); ?></p>
                        </td>
                        <td class="p-6 text-right">
                            <button class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 hover:text-accent-green transition-all">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
