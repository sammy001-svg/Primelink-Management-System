<?php
/**
 * Maintenance Management Page
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user   = getCurrentUser($pdo);
$role   = $_SESSION['role'] ?? 'tenant';
$pageTitle = "Maintenance";

if ($role === 'landlord') {
    $landlordId = getLandlordId($pdo);
    $stmt = $pdo->prepare("
        SELECT m.*, p.title as property_title, t.full_name as tenant_name
        FROM maintenance_requests m
        LEFT JOIN properties p ON m.property_id = p.id
        LEFT JOIN tenants t ON m.tenant_id = t.id
        WHERE p.landlord_id = ?
        ORDER BY m.created_at DESC
    ");
    $stmt->execute([$landlordId]);
    $requests = $stmt->fetchAll();
    $canManage = true; // Landlords can update status
} elseif ($role === 'tenant') {
    $stmt = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $tenant   = $stmt->fetch();
    $tenantId = $tenant['id'] ?? null;
    $stmt2 = $pdo->prepare("
        SELECT m.*, p.title as property_title, t.full_name as tenant_name
        FROM maintenance_requests m
        LEFT JOIN properties p ON m.property_id = p.id
        LEFT JOIN tenants t ON m.tenant_id = t.id
        WHERE m.tenant_id = ?
        ORDER BY m.created_at DESC
    ");
    $stmt2->execute([$tenantId]);
    $requests  = $stmt2->fetchAll();
    $canManage = false;
} else {
    $requests  = $pdo->query("
        SELECT m.*, p.title as property_title, t.full_name as tenant_name
        FROM maintenance_requests m
        LEFT JOIN properties p ON m.property_id = p.id
        LEFT JOIN tenants t ON m.tenant_id = t.id
        ORDER BY m.created_at DESC
    ")->fetchAll();
    $canManage = true;
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">
    <?php if (isset($_GET['success'])): ?>
    <div class="p-4 bg-green-500/10 border border-green-500/20 text-green-500 rounded-xl font-bold text-sm animate-in fade-in slide-in-from-top-4">
        Maintenance request <?php echo $_GET['success'] == 'created' ? 'created' : 'updated'; ?> successfully!
    </div>
    <?php endif; ?>

    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Maintenance Requests</h1>
            <p class="text-slate-500 font-medium">Track and manage property maintenance issues.</p>
        </div>
        <button onclick="openModal('newRequestModal')" class="btn-primary">
            + New Request
        </button>
    </div>

    <!-- New Request Modal -->
    <div id="newRequestModal" class="modal-overlay" style="display:none;">
        <div class="modal-card">
            <button onclick="closeModal('newRequestModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            <h2 class="text-2xl font-black mb-8">Submit Maintenance Request</h2>
            <form action="actions/maintenance_actions.php" method="POST" class="space-y-6">
                <input type="hidden" name="action" value="create">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Issue Title</label>
                    <input type="text" name="title" required placeholder="E.g. Leaking Faucet" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-gold/20 transition-all outline-none">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Description</label>
                    <textarea name="description" rows="3" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-gold/20 transition-all outline-none"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Priority</label>
                        <select name="priority" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-gold/20 transition-all outline-none">
                            <option>Normal</option>
                            <option>High</option>
                            <option>Urgent</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn-gold w-full justify-center py-4">Submit Request</button>
            </form>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
        <div class="glass-card p-6 border-l-4 border-orange-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Pending</p>
            <h3 class="text-2xl font-black"><?php echo count(array_filter($requests, fn($r) => $r['status'] == 'Pending')); ?></h3>
        </div>
        <div class="glass-card p-6 border-l-4 border-blue-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">In Progress</p>
            <h3 class="text-2xl font-black"><?php echo count(array_filter($requests, fn($r) => $r['status'] == 'In Progress')); ?></h3>
        </div>
        <div class="glass-card p-6 border-l-4 border-green-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Completed</p>
            <h3 class="text-2xl font-black"><?php echo count(array_filter($requests, fn($r) => $r['status'] == 'Completed')); ?></h3>
        </div>
    </div>

    <!-- Requests List -->
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <?php if (empty($requests)): ?>
            <div class="col-span-full py-20 text-center glass-card">
                <p class="text-slate-400 font-medium">No maintenance requests found.</p>
            </div>
        <?php else: ?>
            <?php foreach ($requests as $req): ?>
            <div class="glass-card p-6 hover:border-accent-gold/30 transition-all group">
                <div class="flex justify-between items-start mb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-900 dark:text-white group-hover:bg-slate-900 group-hover:text-white transition-all">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                        </div>
                        <div>
                            <h3 class="text-sm font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($req['title']); ?></h3>
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest"><?php echo htmlspecialchars($req['property_title'] ?: 'General'); ?></p>
                        </div>
                    </div>
                    <span class="px-3 py-1 bg-slate-100 dark:bg-slate-800 rounded-full text-[10px] font-black uppercase tracking-widest <?php echo $req['priority'] == 'Urgent' ? 'text-red-500' : ($req['priority'] == 'High' ? 'text-orange-500' : 'text-blue-500'); ?>">
                        <?php echo htmlspecialchars($req['priority']); ?>
                    </span>
                </div>
                
                <p class="text-sm text-slate-600 dark:text-slate-400 mb-6 line-clamp-2"><?php echo htmlspecialchars($req['description'] ?: 'No description provided.'); ?></p>

                <div class="flex justify-between items-center pt-4 border-t border-slate-100 dark:border-slate-800">
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded-full bg-slate-200 dark:bg-slate-700 font-black text-[8px] flex items-center justify-center">
                            <?php echo substr($req['tenant_name'] ?? 'T', 0, 1); ?>
                        </div>
                        <span class="text-[10px] font-bold text-slate-500"><?php echo htmlspecialchars($req['tenant_name'] ?: 'Unknown'); ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full <?php echo $req['status'] == 'Completed' ? 'bg-green-500' : ($req['status'] == 'In Progress' ? 'bg-blue-500' : 'bg-orange-500'); ?>"></span>
                        
                    <?php if ($canManage): ?>
                        <form action="actions/maintenance_actions.php" method="POST" class="inline">
                            <input type="hidden" name="id" value="<?php echo $req['id']; ?>">
                            <input type="hidden" name="action" value="update_status">
                            <select name="status" onchange="this.form.submit()" class="bg-transparent text-[10px] font-black uppercase tracking-widest text-slate-400 outline-none cursor-pointer border-none p-0 focus:ring-0">
                                <option value="Pending" <?php echo $req['status'] == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="In Progress" <?php echo $req['status'] == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="Completed" <?php echo $req['status'] == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                            </select>
                        </form>
                        <span class="text-[10px] font-black uppercase tracking-widest text-slate-400"><?php echo htmlspecialchars($req['status']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
