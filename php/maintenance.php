<?php
/**
 * Maintenance Management Page
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/settings.php';

$user      = getCurrentUser($pdo);
$role      = $_SESSION['role'] ?? 'tenant';
$pageTitle = "Maintenance";
$currency  = getSetting($pdo, 'currency_symbol', 'KSh');

// ── Schema self-heal: cost-tracking columns ───────────────────────────
foreach ([
    "ALTER TABLE maintenance_requests ADD COLUMN vendor_name    VARCHAR(150) NULL",
    "ALTER TABLE maintenance_requests ADD COLUMN quoted_amount  DECIMAL(15,2) NULL",
    "ALTER TABLE maintenance_requests ADD COLUMN actual_cost    DECIMAL(15,2) NULL",
    "ALTER TABLE maintenance_requests ADD COLUMN cost_status    ENUM('Pending','Approved','Paid') NULL",
    "ALTER TABLE maintenance_requests ADD COLUMN vendor_notes   TEXT NULL",
] as $ddl) {
    try { $pdo->exec($ddl); } catch (PDOException $e) {}
}

$selectCols = "m.*, p.title as property_title, u.unit_number, t.full_name as tenant_name, e.full_name as assigned_agent_name";

if ($role === 'landlord') {
    $landlordId = getLandlordId($pdo);
    $stmt = $pdo->prepare("
        SELECT $selectCols
        FROM maintenance_requests m
        LEFT JOIN properties p ON m.property_id = p.id
        LEFT JOIN units u ON m.unit_id = u.id
        LEFT JOIN tenants t ON m.tenant_id = t.id
        LEFT JOIN employees e ON m.assigned_staff_id = e.id
        WHERE p.landlord_id = ?
        ORDER BY m.created_at DESC
    ");
    $stmt->execute([$landlordId]);
    $requests  = $stmt->fetchAll();
    $canManage = true;
} elseif ($role === 'tenant') {
    $stmt = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $tenant   = $stmt->fetch();
    $tenantId = $tenant['id'] ?? null;
    $stmt2    = $pdo->prepare("
        SELECT $selectCols
        FROM maintenance_requests m
        LEFT JOIN properties p ON m.property_id = p.id
        LEFT JOIN units u ON m.unit_id = u.id
        LEFT JOIN tenants t ON m.tenant_id = t.id
        LEFT JOIN employees e ON m.assigned_staff_id = e.id
        WHERE m.tenant_id = ?
        ORDER BY m.created_at DESC
    ");
    $stmt2->execute([$tenantId]);
    $requests  = $stmt2->fetchAll();
    $canManage = false;
} else {
    $requests = $pdo->query("
        SELECT $selectCols
        FROM maintenance_requests m
        LEFT JOIN properties p ON m.property_id = p.id
        LEFT JOIN units u ON m.unit_id = u.id
        LEFT JOIN tenants t ON m.tenant_id = t.id
        LEFT JOIN employees e ON m.assigned_staff_id = e.id
        ORDER BY m.created_at DESC
    ")->fetchAll();
    $canManage = true;
}

// ── Cost KPI aggregates (admin/staff/landlord only) ───────────────────
$costQuoted  = array_sum(array_map(fn($r) => (float)($r['quoted_amount'] ?? 0), $requests));
$costActual  = array_sum(array_map(fn($r) => (float)($r['actual_cost']   ?? 0), $requests));
$costPending = array_sum(array_map(fn($r) => (in_array($r['cost_status'] ?? '', ['Pending','Approved']) && $r['actual_cost'] > 0) ? (float)$r['actual_cost'] : 0, $requests));
$costPaid    = array_sum(array_map(fn($r) => ($r['cost_status'] ?? '') === 'Paid' ? (float)$r['actual_cost'] : 0, $requests));

// ── Fetch Staff for Assignment ────────────────────────────────────────
$staff = $pdo->query("SELECT id, full_name, role FROM employees WHERE status = 'Active'")->fetchAll();

// ── Fetch Properties / Units for the create modal ────────────────────
$allProperties = $pdo->query("SELECT id, title FROM properties ORDER BY title ASC")->fetchAll();
$allUnits      = $pdo->query("SELECT id, property_id, unit_number FROM units ORDER BY unit_number ASC")->fetchAll();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';

$priorityColor = fn($p) => match($p) {
    'Critical', 'Urgent' => 'text-red-500',
    'High'               => 'text-orange-500',
    default              => 'text-blue-500',
};
$costStatusBadge = fn($s) => match($s) {
    'Paid'     => 'badge-green',
    'Approved' => 'badge-blue',
    default    => 'badge-orange',
};
?>

<div class="space-y-8 animate-in">

    <?php
    $successMsg = match($_GET['success'] ?? '') {
        'created'      => 'Maintenance request submitted.',
        'updated'      => 'Request status updated.',
        'assigned'     => 'Agent assigned — request set to In Progress.',
        'pushed'       => 'Request escalated to landlord.',
        'decided'      => 'Landlord decision recorded.',
        'cost_updated' => 'Cost details saved.',
        default        => '',
    };
    ?>
    <?php if ($successMsg): ?>
    <div id="toast-success" class="p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 rounded-2xl font-bold text-sm">
        <?php echo $successMsg; ?>
    </div>
    <script>setTimeout(() => document.getElementById('toast-success')?.remove(), 3500);</script>
    <?php endif; ?>

    <!-- Page header -->
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
            <form action="actions/maintenance_actions.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                <input type="hidden" name="action" value="create">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Issue Title</label>
                    <input type="text" name="title" required placeholder="E.g. Leaking Faucet" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Description</label>
                    <textarea name="description" rows="3" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none"></textarea>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Upload Image / Take Picture</label>
                    <div class="relative group">
                        <input type="file" name="maintenance_image" accept="image/*" capture="environment" class="hidden" id="maintenance_image_input" onchange="updateFileName(this)">
                        <label for="maintenance_image_input" class="flex flex-col items-center justify-center w-full h-32 px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-2xl cursor-pointer hover:bg-slate-200 dark:hover:bg-slate-800 transition-all">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-slate-400 mb-2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                            <p id="file-name-display" class="text-xs font-bold text-slate-500">Tap to upload or take a photo</p>
                        </label>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Property</label>
                        <select name="property_id" id="maintPropertySelect" onchange="filterMaintUnits(this.value)" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                            <option value="">Select Property...</option>
                            <?php foreach ($allProperties as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars((string)$p['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Unit</label>
                        <select name="unit_id" id="maintUnitSelect" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                            <option value="">Select Unit...</option>
                        </select>
                    </div>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Priority</label>
                    <select name="priority" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                        <option>Normal</option>
                        <option>High</option>
                        <option>Urgent</option>
                        <option>Critical</option>
                    </select>
                </div>
                <button type="submit" class="btn-green w-full justify-center py-4">Submit Request</button>
            </form>
        </div>
    </div>

    <!-- Status KPI cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        <div class="glass-card p-6 border-l-4 border-orange-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Pending</p>
            <h3 class="text-2xl font-black"><?php echo count(array_filter($requests, fn($r) => $r['status'] === 'Pending')); ?></h3>
        </div>
        <div class="glass-card p-6 border-l-4 border-blue-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">In Progress</p>
            <h3 class="text-2xl font-black"><?php echo count(array_filter($requests, fn($r) => $r['status'] === 'In Progress')); ?></h3>
        </div>
        <div class="glass-card p-6 border-l-4 border-accent-green">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Completed</p>
            <h3 class="text-2xl font-black"><?php echo count(array_filter($requests, fn($r) => $r['status'] === 'Completed')); ?></h3>
        </div>
    </div>

    <!-- Cost KPI cards (admin/staff/landlord) -->
    <?php if ($role !== 'tenant' && ($costQuoted > 0 || $costActual > 0)): ?>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="glass-card p-5 border-l-4 border-slate-400">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Quoted</p>
            <p class="text-lg font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($costQuoted); ?></p>
        </div>
        <div class="glass-card p-5 border-l-4 border-blue-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Actual</p>
            <p class="text-lg font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($costActual); ?></p>
        </div>
        <div class="glass-card p-5 border-l-4 border-orange-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Pending Payment</p>
            <p class="text-lg font-black <?php echo $costPending > 0 ? 'text-orange-500' : 'text-slate-900 dark:text-white'; ?>"><?php echo $currency; ?> <?php echo number_format($costPending); ?></p>
        </div>
        <div class="glass-card p-5 border-l-4 border-accent-green">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Paid to Vendors</p>
            <p class="text-lg font-black text-accent-green"><?php echo $currency; ?> <?php echo number_format($costPaid); ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Requests List -->
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
        <?php if (empty($requests)): ?>
            <div class="col-span-full py-20 text-center glass-card">
                <p class="text-slate-400 font-medium">No maintenance requests found.</p>
            </div>
        <?php else: ?>
            <?php foreach ($requests as $req):
                $hasCost = !empty($req['vendor_name']) || !empty($req['actual_cost']) || !empty($req['quoted_amount']);
            ?>
            <div class="glass-card p-6 border-l-4 <?php echo $req['status'] === 'Completed' ? 'border-l-accent-green' : ($req['status'] === 'In Progress' ? 'border-l-blue-500' : 'border-l-orange-500'); ?> transition-all group overflow-hidden relative">
                <div class="flex justify-between items-start mb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-900 dark:text-white group-hover:bg-slate-900 group-hover:text-white transition-all shadow-inner">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                        </div>
                        <div>
                            <h3 class="text-sm font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars((string)$req['title']); ?></h3>
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">
                                <?php echo htmlspecialchars((string)($req['property_title'] ?: 'General')); ?>
                                <?php if ($req['unit_number']): ?> — Unit <?php echo htmlspecialchars((string)$req['unit_number']); ?><?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-col items-end gap-2">
                        <span class="px-3 py-1 bg-slate-100 dark:bg-slate-800 rounded-full text-[10px] font-black uppercase tracking-widest <?php echo $priorityColor($req['priority']); ?>">
                            <?php echo htmlspecialchars((string)$req['priority']); ?>
                        </span>
                        <?php if (!empty($req['pushed_to_landlord'])): ?>
                        <span class="px-2 py-0.5 rounded text-[8px] font-black uppercase tracking-tighter <?php echo $req['landlord_approval_status'] === 'Approved' ? 'bg-green-500 text-white' : ($req['landlord_approval_status'] === 'Rejected' ? 'bg-red-500 text-white' : 'bg-orange-100 text-orange-600'); ?>">
                            Landlord: <?php echo $req['landlord_approval_status']; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <p class="text-sm text-slate-600 dark:text-slate-400 mb-4 line-clamp-2 italic font-medium">"<?php echo htmlspecialchars((string)($req['description'] ?: 'No details provided.')); ?>"</p>

                <?php if ($req['image_path']): ?>
                <div class="mb-4">
                    <a href="<?php echo htmlspecialchars($req['image_path']); ?>" target="_blank" class="inline-flex items-center gap-2 px-3 py-2 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        View Photo Evidence
                    </a>
                </div>
                <?php endif; ?>

                <!-- Cost info strip (if any cost data logged) -->
                <?php if ($hasCost): ?>
                <div class="mb-4 p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-3 text-[11px]">
                        <?php if ($req['vendor_name']): ?>
                        <span class="font-black text-slate-400 uppercase tracking-widest">Vendor: <span class="text-slate-700 dark:text-slate-200 normal-case tracking-normal font-bold"><?php echo htmlspecialchars($req['vendor_name']); ?></span></span>
                        <?php endif; ?>
                        <?php if ($req['quoted_amount'] > 0): ?>
                        <span class="font-black text-slate-400 uppercase tracking-widest">Quote: <span class="text-slate-700 dark:text-slate-200 font-black"><?php echo $currency; ?> <?php echo number_format($req['quoted_amount']); ?></span></span>
                        <?php endif; ?>
                        <?php if ($req['actual_cost'] > 0): ?>
                        <span class="font-black text-slate-400 uppercase tracking-widest">Actual: <span class="<?php echo ($req['cost_status'] ?? '') === 'Paid' ? 'text-accent-green' : 'text-orange-500'; ?> font-black"><?php echo $currency; ?> <?php echo number_format($req['actual_cost']); ?></span></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($req['cost_status'])): ?>
                    <span class="badge <?php echo $costStatusBadge($req['cost_status']); ?>"><?php echo $req['cost_status']; ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="space-y-3 pt-4 border-t border-slate-100 dark:border-slate-800">
                    <!-- Dispatch & Assignment info -->
                    <div class="flex flex-wrap justify-between items-center gap-3">
                        <div class="flex items-center gap-2">
                            <div class="w-6 h-6 rounded-lg bg-slate-200 dark:bg-slate-700 font-black text-[8px] flex items-center justify-center">
                                <?php echo substr((string)($req['tenant_name'] ?? 'T'), 0, 1); ?>
                            </div>
                            <span class="text-[10px] font-bold text-slate-500 uppercase tracking-tight">Tenant: <?php echo htmlspecialchars((string)($req['tenant_name'] ?: 'Unknown')); ?></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-slate-400"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            <span class="text-[10px] font-black uppercase tracking-widest <?php echo $req['assigned_agent_name'] ? 'text-accent-green' : 'text-slate-400'; ?>">
                                Agent: <?php echo htmlspecialchars((string)($req['assigned_agent_name'] ?: 'Unallocated')); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Role-Based Action Controls -->
                    <?php if ($role === 'landlord' && !empty($req['pushed_to_landlord']) && $req['landlord_approval_status'] === 'Pending'): ?>
                        <div class="bg-orange-50 dark:bg-orange-500/5 p-4 rounded-xl border border-orange-200 dark:border-orange-500/20 flex justify-between items-center">
                            <p class="text-[10px] font-black text-orange-600 uppercase tracking-widest">Approval Requested</p>
                            <div class="flex gap-2">
                                <form action="actions/maintenance_actions.php" method="POST">
                                    <input type="hidden" name="id" value="<?php echo $req['id']; ?>">
                                    <input type="hidden" name="action" value="landlord_decision">
                                    <button name="decision" value="Approved" class="px-3 py-1.5 bg-accent-green text-white rounded-lg text-[9px] font-black uppercase hover:scale-105 transition-all">Approve</button>
                                    <button name="decision" value="Rejected" class="px-3 py-1.5 bg-red-500 text-white rounded-lg text-[9px] font-black uppercase hover:scale-105 transition-all">Deny</button>
                                </form>
                            </div>
                        </div>
                    <?php elseif ($role === 'admin' || $role === 'staff'): ?>
                        <div class="flex flex-col sm:flex-row gap-2">
                            <!-- Agent assign -->
                            <form action="actions/maintenance_actions.php" method="POST" class="flex-1 flex gap-2">
                                <input type="hidden" name="id" value="<?php echo $req['id']; ?>">
                                <input type="hidden" name="action" value="assign_agent">
                                <select name="staff_id" onchange="this.form.submit()" class="flex-1 px-4 py-2 bg-slate-50 dark:bg-slate-800 border-none rounded-xl text-[10px] font-black uppercase tracking-widest focus:ring-2 focus:ring-accent-green/20 outline-none">
                                    <option value="">Allocate Agent...</option>
                                    <?php foreach ($staff as $s): ?>
                                        <option value="<?php echo $s['id']; ?>" <?php echo $req['assigned_staff_id'] == $s['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string)$s['full_name']); ?> (<?php echo $s['role']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>

                            <!-- Status update -->
                            <form action="actions/maintenance_actions.php" method="POST">
                                <input type="hidden" name="id" value="<?php echo $req['id']; ?>">
                                <input type="hidden" name="action" value="update_status">
                                <select name="status" onchange="this.form.submit()" class="px-4 py-2 bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-xl text-[10px] font-black uppercase tracking-widest outline-none">
                                    <option value="Pending"     <?php echo $req['status'] === 'Pending'     ? 'selected' : ''; ?>>Pending</option>
                                    <option value="In Progress" <?php echo $req['status'] === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="Completed"   <?php echo $req['status'] === 'Completed'   ? 'selected' : ''; ?>>Completed</option>
                                </select>
                            </form>

                            <?php if (empty($req['pushed_to_landlord'])): ?>
                            <form action="actions/maintenance_actions.php" method="POST">
                                <input type="hidden" name="id" value="<?php echo $req['id']; ?>">
                                <input type="hidden" name="action" value="push_to_landlord">
                                <button type="submit" class="px-3 py-2 bg-slate-900 dark:bg-white dark:text-slate-900 text-white rounded-xl text-[10px] font-black uppercase tracking-widest hover:opacity-80 transition-all whitespace-nowrap">Escalate</button>
                            </form>
                            <?php endif; ?>
                        </div>

                        <!-- Log Cost button -->
                        <button onclick="openCostModal(<?php echo htmlspecialchars(json_encode([
                            'id'           => $req['id'],
                            'title'        => $req['title'],
                            'vendor_name'  => $req['vendor_name']  ?? '',
                            'quoted_amount'=> $req['quoted_amount'] ?? '',
                            'actual_cost'  => $req['actual_cost']   ?? '',
                            'cost_status'  => $req['cost_status']   ?? '',
                            'vendor_notes' => $req['vendor_notes']  ?? '',
                        ])); ?>)"
                            class="w-full py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest border border-slate-200 dark:border-slate-700 hover:bg-slate-900 dark:hover:bg-white hover:text-white dark:hover:text-slate-900 hover:border-transparent transition-all flex items-center justify-center gap-2">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                            <?php echo $hasCost ? 'Update Cost' : 'Log Cost'; ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ── Log Cost Modal ──────────────────────────────────────────────── -->
<div id="costModal" class="modal-overlay" style="display:none;">
    <div class="modal-card">
        <button onclick="closeModal('costModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-2">Log Cost</h2>
        <p id="costModalTitle" class="text-slate-500 font-medium text-sm mb-8"></p>

        <form action="actions/maintenance_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="update_cost">
            <input type="hidden" name="id" id="costModalId">

            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Vendor / Contractor Name</label>
                <input type="text" name="vendor_name" id="costVendorName" placeholder="E.g. ABC Plumbers" class="form-input w-full">
            </div>

            <div class="grid grid-cols-2 gap-5">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Quoted Amount (<?php echo $currency; ?>)</label>
                    <input type="number" name="quoted_amount" id="costQuotedAmount" min="0" step="0.01" placeholder="0.00" class="form-input w-full">
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Actual Cost (<?php echo $currency; ?>)</label>
                    <input type="number" name="actual_cost" id="costActual" min="0" step="0.01" placeholder="0.00" class="form-input w-full">
                </div>
            </div>

            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Payment Status</label>
                <select name="cost_status" id="costStatus" class="form-input w-full">
                    <option value="">— Not set —</option>
                    <option value="Pending">Pending</option>
                    <option value="Approved">Approved</option>
                    <option value="Paid">Paid</option>
                </select>
            </div>

            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Vendor Notes</label>
                <textarea name="vendor_notes" id="costNotes" rows="3" placeholder="Any notes about the work or vendor..." class="form-input w-full resize-none"></textarea>
            </div>

            <button type="submit" class="btn-green w-full justify-center py-4">Save Cost Details</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
const allUnits = <?php echo json_encode($allUnits); ?>;

function filterMaintUnits(propertyId) {
    const unitSelect = document.getElementById('maintUnitSelect');
    unitSelect.innerHTML = '<option value="">Select Unit...</option>';
    if (!propertyId) return;
    allUnits.filter(u => u.property_id == propertyId).forEach(u => {
        const opt = document.createElement('option');
        opt.value = u.id;
        opt.textContent = u.unit_number;
        unitSelect.appendChild(opt);
    });
}

function updateFileName(input) {
    const display = document.getElementById('file-name-display');
    if (input.files && input.files[0]) {
        display.textContent = input.files[0].name;
        display.classList.replace('text-slate-500', 'text-accent-green');
    } else {
        display.textContent = "Tap to upload or take a photo";
        display.classList.replace('text-accent-green', 'text-slate-500');
    }
}

function openCostModal(data) {
    document.getElementById('costModalId').value        = data.id           || '';
    document.getElementById('costModalTitle').textContent = data.title      || '';
    document.getElementById('costVendorName').value     = data.vendor_name  || '';
    document.getElementById('costQuotedAmount').value   = data.quoted_amount || '';
    document.getElementById('costActual').value         = data.actual_cost  || '';
    document.getElementById('costStatus').value         = data.cost_status  || '';
    document.getElementById('costNotes').value          = data.vendor_notes || '';
    openModal('costModal');
}
</script>
