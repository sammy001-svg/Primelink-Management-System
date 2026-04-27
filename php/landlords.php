<?php
/**
 * Landlord Management Page (Admin/Staff Only)
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['staff']); // admin/staff only

$pageTitle = "Landlords";
$user = getCurrentUser($pdo);

// Fetch all landlords with assigned property counts
$landlords = $pdo->query("
    SELECT l.*, 
           COUNT(p.id) as property_count,
           (SELECT COUNT(*) FROM tenants t 
            JOIN leases ls ON t.id = ls.tenant_id 
            JOIN units u ON ls.unit_id = u.id 
            JOIN properties p2 ON u.property_id = p2.id 
            WHERE p2.landlord_id = l.id AND ls.status = 'Active') as tenant_count
    FROM landlords l 
    LEFT JOIN properties p ON p.landlord_id = l.id
    GROUP BY l.id
    ORDER BY l.created_at DESC
")->fetchAll();

// Fetch all properties for assignment
$allProperties = $pdo->query("
    SELECT p.*, l.full_name as current_landlord 
    FROM properties p 
    LEFT JOIN landlords l ON p.landlord_id = l.id
    ORDER BY p.title
")->fetchAll();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">
    <?php if (isset($_GET['success'])): ?>
    <div class="p-4 bg-green-500/10 border border-green-500/20 text-green-500 rounded-xl font-bold text-sm">
        <?php echo htmlspecialchars($_GET['success']); ?>
    </div>
    <?php endif; ?>

    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Landlords</h1>
            <p class="text-slate-500 font-medium">Manage landlord accounts and their property assignments.</p>
        </div>
        <button onclick="openModal('newLandlordModal')" class="btn-primary">
            + Add Landlord
        </button>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
        <div class="glass-card p-6 border-l-4 border-accent-gold">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Landlords</p>
            <h3 class="text-3xl font-black mt-1"><?php echo count($landlords); ?></h3>
        </div>
        <div class="glass-card p-6 border-l-4 border-blue-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Assigned Properties</p>
            <h3 class="text-3xl font-black mt-1"><?php echo count(array_filter($allProperties, fn($p) => !empty($p['landlord_id']))); ?></h3>
        </div>
        <div class="glass-card p-6 border-l-4 border-slate-400">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Unassigned Properties</p>
            <h3 class="text-3xl font-black mt-1"><?php echo count(array_filter($allProperties, fn($p) => empty($p['landlord_id']))); ?></h3>
        </div>
    </div>

    <!-- Landlords Table -->
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800">
            <h3 class="font-black text-lg">Registered Landlords</h3>
        </div>
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-slate-50 dark:bg-slate-800/50">
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Landlord</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Contact</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Properties</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Active Tenants</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php if (empty($landlords)): ?>
                <tr>
                    <td colspan="5" class="p-16 text-center text-slate-400 italic">No landlords registered yet.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($landlords as $ll): ?>
                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all group">
                    <td class="p-5">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-linear-to-br from-accent-gold to-amber-600 flex items-center justify-center text-sm font-black text-white shrink-0">
                                <?php echo strtoupper(substr($ll['full_name'], 0, 1)); ?>
                            </div>
                            <div>
                                <p class="font-black text-slate-900 dark:text-white text-sm"><?php echo htmlspecialchars($ll['full_name']); ?></p>
                                <p class="text-[10px] text-slate-400 font-bold">LLD-<?php echo substr($ll['id'], 0, 6); ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="p-5">
                        <p class="text-sm font-bold text-slate-600 dark:text-slate-300"><?php echo htmlspecialchars($ll['email']); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($ll['phone'] ?: 'No phone'); ?></p>
                    </td>
                    <td class="p-5">
                        <span class="badge badge-blue"><?php echo $ll['property_count']; ?> Properties</span>
                    </td>
                    <td class="p-5">
                        <span class="text-sm font-bold"><?php echo $ll['tenant_count']; ?></span>
                    </td>
                    <td class="p-5 text-right">
                        <button onclick="openAssignModal('<?php echo $ll['id']; ?>','<?php echo htmlspecialchars($ll['full_name']); ?>')" 
                                class="px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-accent-gold/10 hover:text-accent-gold rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                            Assign Properties
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Property Assignments Overview -->
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800">
            <h3 class="font-black text-lg">Property Assignments</h3>
        </div>
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-slate-50 dark:bg-slate-800/50">
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Property</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Type</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Assigned To</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php foreach ($allProperties as $prop): ?>
                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                    <td class="p-5">
                        <p class="font-black text-sm text-slate-900 dark:text-white"><?php echo htmlspecialchars($prop['title']); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($prop['location']); ?></p>
                    </td>
                    <td class="p-5">
                        <span class="text-xs font-bold text-slate-500"><?php echo htmlspecialchars($prop['property_type']); ?></span>
                    </td>
                    <td class="p-5">
                        <?php if ($prop['current_landlord']): ?>
                            <span class="badge badge-green"><?php echo htmlspecialchars($prop['current_landlord']); ?></span>
                        <?php else: ?>
                            <span class="badge badge-orange">Unassigned</span>
                        <?php endif; ?>
                    </td>
                    <td class="p-5 text-right">
                        <form action="actions/landlord_actions.php" method="POST" class="inline">
                            <input type="hidden" name="action" value="unassign_property">
                            <input type="hidden" name="property_id" value="<?php echo $prop['id']; ?>">
                            <?php if ($prop['landlord_id']): ?>
                            <button type="submit" onclick="return confirm('Remove this property from the landlord?')"
                                class="text-[10px] font-black uppercase tracking-widest text-red-400 hover:text-red-600 transition-colors">
                                Unassign
                            </button>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Landlord Modal -->
<div id="newLandlordModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <button onclick="closeModal('newLandlordModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-6">Add New Landlord</h2>
        <form action="actions/landlord_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="create">
            <div class="grid grid-cols-2 gap-5">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Full Name</label>
                    <input type="text" name="full_name" required placeholder="John Kamau" class="form-input">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Phone</label>
                    <input type="text" name="phone" placeholder="+254..." class="form-input">
                </div>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Email (Login)</label>
                <input type="email" name="email" required placeholder="landlord@example.com" class="form-input">
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Initial Password</label>
                <input type="password" name="password" required placeholder="••••••••" class="form-input">
            </div>
            <button type="submit" class="btn-gold w-full justify-center py-4">Create Landlord Account</button>
        </form>
    </div>
</div>

<!-- Assign Properties Modal -->
<div id="assignModal" class="modal-overlay" style="display:none;">
    <div class="modal-card" style="max-width:560px;">
        <button onclick="closeModal('assignModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-2">Assign Properties</h2>
        <p class="text-sm text-slate-400 mb-6">to <span id="assignTargetName" class="font-black text-slate-700 dark:text-white"></span></p>
        <form action="actions/landlord_actions.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="assign_properties">
            <input type="hidden" name="landlord_id" id="assignLandlordId">
            <div class="space-y-2 max-h-64 overflow-y-auto pr-1">
                <?php foreach ($allProperties as $prop): ?>
                <label class="flex items-center gap-3 p-3 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800/50 cursor-pointer transition-colors">
                    <input type="checkbox" name="property_ids[]" value="<?php echo $prop['id']; ?>"
                           class="w-4 h-4 accent-amber-500 rounded"
                           <?php echo ($prop['current_landlord'] && $prop['landlord_id'] === null) ? '' : ''; ?>>
                    <div>
                        <p class="font-bold text-sm"><?php echo htmlspecialchars($prop['title']); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($prop['location']); ?>
                            <?php if ($prop['current_landlord']): ?>
                                — <span class="text-orange-400">Currently: <?php echo htmlspecialchars($prop['current_landlord']); ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn-gold w-full justify-center py-4">Save Assignments</button>
        </form>
    </div>
</div>

<script>
function openAssignModal(landlordId, name) {
    document.getElementById('assignLandlordId').value = landlordId;
    document.getElementById('assignTargetName').textContent = name;
    openModal('assignModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
