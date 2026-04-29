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

// Default permissions
$canAddTenant = in_array($role, ['admin', 'staff']);
$tenants = [];

// Landlords only see tenants in their properties
if ($role === 'landlord') {
    $landlordId = getLandlordId($pdo);
    if ($landlordId) {
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
    }
} else {
    // Admin/Staff logic
    requireRole(['admin', 'staff']);
    
    // AUTO-REPAIR: Ensure all users with tenant role have a record in the tenants table
    // This fixes "registered but invisible" tenants on cPanel
    $pdo->exec("
        INSERT IGNORE INTO tenants (id, user_id, full_name, email, phone, status, created_at)
        SELECT u.id, u.id, p.full_name, u.email, p.phone, 'Active', NOW()
        FROM users u
        JOIN profiles p ON u.id = p.id
        WHERE u.role = 'tenant'
        AND NOT EXISTS (SELECT 1 FROM tenants t WHERE t.user_id = u.id)
    ");

    $stmt = $pdo->query("SELECT t.*, u.id as user_uuid FROM tenants t LEFT JOIN profiles u ON t.user_id = u.id ORDER BY t.created_at DESC");
    $tenants = $stmt->fetchAll();
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
        <div class="modal-card max-w-2xl px-10 py-12 h-[90vh] overflow-y-auto">
            <button onclick="closeModal('newTenantModal')" class="absolute top-6 right-6 text-slate-400 hover:text-slate-900 transition-all transform hover:rotate-90">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            
            <h2 class="text-3xl font-black mb-10 tracking-tight">Register New Tenant</h2>
            
            <form action="actions/tenant_actions.php" method="POST" enctype="multipart/form-data" class="space-y-10">
                <input type="hidden" name="action" value="create">
                
                <!-- Section 1: Basic Info -->
                <div class="space-y-6">
                    <h3 class="text-xs font-black text-accent-green uppercase tracking-widest border-b border-slate-100 pb-2">1. Profile Details</h3>
                    <div class="grid grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Full Name</label>
                            <input type="text" name="full_name" required placeholder="John Doe" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Phone Number</label>
                            <input type="text" name="phone" placeholder="+254 7XX..." class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Residential Address</label>
                        <input type="text" name="address" required placeholder="Estate, Apartment, City" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                </div>

                <!-- Section 2: Identity -->
                <div class="space-y-6">
                    <h3 class="text-xs font-black text-accent-green uppercase tracking-widest border-b border-slate-100 pb-2">2. Identity & Family</h3>
                    <div class="grid grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">ID Number</label>
                            <input type="text" name="id_no" placeholder="3XXXXXXX" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">ID Copy Upload</label>
                            <input type="file" name="id_copy" class="w-full px-4 py-3 bg-slate-100 dark:bg-slate-800/50 rounded-2xl text-xs font-bold text-slate-400">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Marital Status</label>
                            <select name="marital_status" onchange="toggleAdminSpouseFields(this.value)" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                                <option value="Single">Single</option>
                                <option value="Married">Married</option>
                            </select>
                        </div>
                        <div class="flex items-center gap-3 px-2 pt-6">
                            <input type="checkbox" name="has_kids" id="admin_has_kids" class="w-5 h-5 accent-green rounded">
                            <label for="admin_has_kids" class="text-sm font-bold text-slate-500">Has Children</label>
                        </div>
                    </div>
                </div>

                <!-- Spouse Details (Conditional) -->
                <div id="admin-spouse-fields" class="hidden space-y-6 pt-4 animate-in slide-in-from-top-4">
                    <h3 class="text-xs font-black text-accent-orange uppercase tracking-widest border-b border-slate-100 pb-2">Spouse Information</h3>
                    <div class="grid grid-cols-2 gap-6">
                        <div class="space-y-2 col-span-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Spouse Name</label>
                            <input type="text" name="spouse_name" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-orange/20 outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Spouse Phone</label>
                            <input type="text" name="spouse_phone" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-orange/20 outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Spouse ID</label>
                            <input type="text" name="spouse_id_no" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-orange/20 outline-none">
                        </div>
                    </div>
                </div>

                <!-- Section 3: Professional -->
                <div class="space-y-6">
                    <h3 class="text-xs font-black text-accent-green uppercase tracking-widest border-b border-slate-100 pb-2">3. Professional & Business</h3>
                    <div class="grid grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Profession</label>
                            <input type="text" name="profession" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Employer</label>
                            <input type="text" name="employer_name" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Occupation Type</label>
                        <select name="occupation_type" onchange="toggleAdminBusinessFields(this.value)" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                            <option value="Residential">Residential</option>
                            <option value="Commercial">Commercial</option>
                        </select>
                    </div>
                    <div id="admin-business-fields" class="hidden grid-cols-2 gap-6 pt-4 animate-in slide-in-from-bottom-4">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Business Name</label>
                            <input type="text" name="business_name" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Nature of Business</label>
                            <input type="text" name="business_nature" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                        </div>
                    </div>
                </div>

                <!-- Section 4: Login -->
                <div class="space-y-6">
                    <h3 class="text-xs font-black text-slate-900 dark:text-white uppercase tracking-widest border-b border-slate-900/10 pb-2">4. Access Credentials</h3>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Login Email</label>
                        <input type="email" name="email" required placeholder="john@example.com" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-slate-900/10 outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Initial Password</label>
                        <input type="password" name="password" required placeholder="••••••••" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-slate-900/10 outline-none">
                    </div>
                </div>

                <div class="pt-6">
                    <button type="submit" class="btn-green w-full justify-center py-5 rounded-2xl shadow-xl shadow-accent-green/10 font-black italic tracking-tighter">Execute Tenant Registry →</button>
                    <p class="text-[10px] text-center text-slate-400 font-bold uppercase tracking-widest mt-4">Automated digital lease will be generated upon submission</p>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleAdminSpouseFields(status) {
            document.getElementById('admin-spouse-fields').classList.toggle('hidden', status !== 'Married');
        }
        function toggleAdminBusinessFields(type) {
            document.getElementById('admin-business-fields').classList.toggle('hidden', type !== 'Commercial');
        }
    </script>

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
                                    <a href="tenant_details.php?id=<?php echo $t['id']; ?>" class="text-sm font-black text-slate-900 dark:text-white hover:text-accent-green transition-colors"><?php echo htmlspecialchars((string)($t['full_name'] ?? '')); ?></a>
                                    <p class="text-[10px] font-bold text-slate-400 uppercase">PRM-<?php echo substr((string)($t['id'] ?? ''), 0, 4); ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="p-6">
                            <p class="text-sm font-bold text-slate-600 dark:text-slate-400"><?php echo htmlspecialchars((string)($t['email'] ?? '')); ?></p>
                            <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars((string)(($t['phone'] ?? '') ?: 'No phone')); ?></p>
                        </td>
                        <td class="p-6">
                            <span class="px-3 py-1 bg-green-500/10 text-green-500 rounded-full text-[10px] font-black uppercase tracking-widest">
                                <?php echo htmlspecialchars((string)($t['status'] ?? 'Active')); ?>
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
