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

// ── Schema self-heal: cost-tracking + assignment columns ──────────────
foreach ([
    "ALTER TABLE maintenance_requests ADD COLUMN vendor_name    VARCHAR(150) NULL",
    "ALTER TABLE maintenance_requests ADD COLUMN quoted_amount  DECIMAL(15,2) NULL",
    "ALTER TABLE maintenance_requests ADD COLUMN actual_cost    DECIMAL(15,2) NULL",
    "ALTER TABLE maintenance_requests ADD COLUMN cost_status    ENUM('Pending','Approved','Paid') NULL",
    "ALTER TABLE maintenance_requests ADD COLUMN vendor_notes   TEXT NULL",
    "ALTER TABLE maintenance_requests ADD COLUMN pushed_to_landlord TINYINT(1) DEFAULT 0",
    "ALTER TABLE maintenance_requests ADD COLUMN landlord_approval_status ENUM('Pending','Approved','Rejected') DEFAULT 'Pending'",
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

// ── KPI aggregates ────────────────────────────────────────────────────
$cntPending    = count(array_filter($requests, fn($r) => $r['status'] === 'Pending'));
$cntInProgress = count(array_filter($requests, fn($r) => $r['status'] === 'In Progress'));
$cntCompleted  = count(array_filter($requests, fn($r) => $r['status'] === 'Completed'));
$cntUrgent     = count(array_filter($requests, fn($r) => in_array($r['priority'] ?? '', ['Urgent', 'Critical'])));
$costActual    = array_sum(array_map(fn($r) => (float)($r['actual_cost'] ?? 0), $requests));
$costPaid      = array_sum(array_map(fn($r) => ($r['cost_status'] ?? '') === 'Paid' ? (float)$r['actual_cost'] : 0, $requests));

// ── Staff list for assignment ─────────────────────────────────────────
$staff = $pdo->query("SELECT id, full_name, role FROM employees WHERE status = 'Active'")->fetchAll();

// ── Properties / Units for create modal ──────────────────────────────
$allProperties = $pdo->query("SELECT id, title FROM properties ORDER BY title ASC")->fetchAll();
$allUnits      = $pdo->query("SELECT id, property_id, unit_number FROM units ORDER BY unit_number ASC")->fetchAll();

// ── Flash message ─────────────────────────────────────────────────────
$flash = match($_GET['success'] ?? '') {
    'created'      => 'Maintenance request submitted.',
    'updated'      => 'Request status updated.',
    'assigned'     => 'Agent assigned — request set to In Progress.',
    'pushed'       => 'Request escalated to landlord.',
    'decided'      => 'Landlord decision recorded.',
    'cost_updated' => 'Cost details saved.',
    default        => '',
};

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';

$priorityBadge = fn($p) => match($p ?? 'Normal') {
    'Critical', 'Urgent' => 'bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400',
    'High'               => 'bg-orange-50 dark:bg-orange-900/20 text-orange-600',
    'Normal'             => 'bg-blue-50 dark:bg-blue-900/20 text-blue-600',
    default              => 'bg-slate-50 dark:bg-slate-800 text-slate-500',
};
$costStatusBadge = fn($s) => match($s) {
    'Paid'     => 'badge-green',
    'Approved' => 'badge-blue',
    default    => 'badge-orange',
};
?>

<div class="space-y-8 animate-in">

    <!-- ── Toast ────────────────────────────────────────────────────── -->
    <?php if ($flash): ?>
    <div id="maintToast" class="fixed bottom-6 right-6 z-50 bg-green-500 text-white px-6 py-3.5 rounded-2xl shadow-2xl font-black text-sm flex items-center gap-2.5">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <?php echo htmlspecialchars($flash); ?>
    </div>
    <script>setTimeout(() => document.getElementById('maintToast')?.remove(), 4000);</script>
    <?php endif; ?>

    <!-- ── Page Header ──────────────────────────────────────────────── -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white">Maintenance</h1>
            <p class="text-slate-400 font-medium text-sm mt-1">Track, assign, and resolve property maintenance requests.</p>
        </div>
        <button onclick="openModal('newRequestModal')" class="btn-primary gap-2 self-start sm:self-auto">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
            New Request
        </button>
    </div>

    <!-- ── KPI Strip ────────────────────────────────────────────────── -->
    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-4">
        <div class="glass-card p-5 flex flex-col gap-2 border-l-[3px] border-orange-400 cursor-pointer" onclick="filterMaintStatus('Pending')">
            <div class="w-8 h-8 rounded-xl bg-orange-50 dark:bg-orange-900/30 flex items-center justify-center text-orange-500 mb-1">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <h3 class="text-2xl font-black text-orange-500"><?php echo $cntPending; ?></h3>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Pending</p>
        </div>
        <div class="glass-card p-5 flex flex-col gap-2 border-l-[3px] border-blue-400 cursor-pointer" onclick="filterMaintStatus('In Progress')">
            <div class="w-8 h-8 rounded-xl bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center text-blue-500 mb-1">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
            </div>
            <h3 class="text-2xl font-black text-blue-500"><?php echo $cntInProgress; ?></h3>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">In Progress</p>
        </div>
        <div class="glass-card p-5 flex flex-col gap-2 border-l-[3px] border-green-400 cursor-pointer" onclick="filterMaintStatus('Completed')">
            <div class="w-8 h-8 rounded-xl bg-green-50 dark:bg-green-900/30 flex items-center justify-center text-green-500 mb-1">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <h3 class="text-2xl font-black text-green-500"><?php echo $cntCompleted; ?></h3>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Completed</p>
        </div>
        <div class="glass-card p-5 flex flex-col gap-2 border-l-[3px] border-red-400 cursor-pointer" onclick="filterMaintPriority('Urgent')">
            <div class="w-8 h-8 rounded-xl bg-red-50 dark:bg-red-900/30 flex items-center justify-center text-red-500 mb-1">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12.01" y1="17" y2="17"/></svg>
            </div>
            <h3 class="text-2xl font-black text-red-500"><?php echo $cntUrgent; ?></h3>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Urgent / Critical</p>
        </div>
        <?php if ($role !== 'tenant'): ?>
        <div class="glass-card p-5 flex flex-col gap-2 border-l-[3px] border-slate-400">
            <div class="w-8 h-8 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-500 mb-1">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <h3 class="text-lg font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($costActual); ?></h3>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Cost</p>
        </div>
        <div class="glass-card p-5 flex flex-col gap-2 border-l-[3px] border-accent-green">
            <div class="w-8 h-8 rounded-xl bg-green-50 dark:bg-green-900/30 flex items-center justify-center text-green-500 mb-1">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <h3 class="text-lg font-black text-accent-green"><?php echo $currency; ?> <?php echo number_format($costPaid); ?></h3>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Paid to Vendors</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Requests Table ────────────────────────────────────────────── -->
    <div class="glass-card overflow-hidden">
        <!-- Header + filters -->
        <div class="p-6 border-b border-slate-100 dark:border-slate-800">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <!-- Status tabs -->
                <div class="flex items-center gap-1 p-1 bg-slate-100 dark:bg-slate-800/80 rounded-xl w-fit shrink-0">
                    <?php foreach (['All' => '', 'Pending' => 'Pending', 'In Progress' => 'In Progress', 'Completed' => 'Completed'] as $label => $val): ?>
                    <button onclick="filterMaintStatus('<?php echo $val; ?>')" id="mtab_<?php echo strtolower(str_replace(' ', '', $label)); ?>"
                        class="maint-tab px-3 py-1.5 rounded-lg text-[11px] font-black uppercase tracking-wide transition-all <?php echo $label === 'All' ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-sm' : 'text-slate-500 hover:text-slate-700 dark:hover:text-slate-300'; ?>">
                        <?php echo $label; ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <!-- Search + priority filter -->
                <div class="flex items-center gap-3 flex-wrap">
                    <div class="relative">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" id="maintSearch" oninput="applyMaintFilters()" placeholder="Search title, tenant, unit…"
                            class="pl-8 pr-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800/60 text-sm font-medium text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-accent-green/30 w-52 transition">
                    </div>
                    <select id="maintPriorityFilter" onchange="applyMaintFilters()"
                        class="py-2 px-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800/60 text-sm font-bold text-slate-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-green/30 transition">
                        <option value="">All Priorities</option>
                        <option value="Critical">Critical</option>
                        <option value="Urgent">Urgent</option>
                        <option value="High">High</option>
                        <option value="Normal">Normal</option>
                    </select>
                </div>
            </div>
        </div>

        <?php if (empty($requests)): ?>
        <div class="text-center py-16">
            <div class="w-14 h-14 rounded-2xl bg-slate-50 dark:bg-slate-800 flex items-center justify-center text-slate-300 mx-auto mb-4">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
            </div>
            <p class="text-slate-400 font-bold text-sm">No maintenance requests found.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="data-table" id="maintTable">
                <thead>
                    <tr>
                        <th>Priority</th>
                        <th>Request</th>
                        <th>Tenant</th>
                        <th>Property / Unit</th>
                        <th>Days Open</th>
                        <?php if ($canManage): ?>
                        <th>Assigned To</th>
                        <?php endif; ?>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $req):
                        $daysOpen   = max(0, (int)round((time() - strtotime($req['created_at'])) / 86400));
                        $daysClass  = $daysOpen >= 7 ? 'text-red-500 font-black' : ($daysOpen >= 3 ? 'text-orange-500 font-bold' : 'text-green-600 dark:text-green-400');
                        $hasCost    = !empty($req['vendor_name']) || !empty($req['actual_cost']) || !empty($req['quoted_amount']);
                        $rowBorder  = match($req['status']) {
                            'In Progress' => 'border-l-[3px] border-blue-400',
                            'Completed'   => 'border-l-[3px] border-green-400',
                            default       => 'border-l-[3px] border-orange-400',
                        };
                        $prio = $req['priority'] ?? 'Normal';
                    ?>
                    <tr class="maint-row <?php echo $rowBorder; ?>"
                        data-status="<?php echo htmlspecialchars($req['status']); ?>"
                        data-priority="<?php echo htmlspecialchars($prio); ?>"
                        data-search="<?php echo strtolower(htmlspecialchars($req['title'] . ' ' . ($req['tenant_name'] ?? '') . ' ' . ($req['property_title'] ?? '') . ' ' . ($req['unit_number'] ?? ''))); ?>">
                        <td>
                            <span class="px-2.5 py-1 rounded-lg text-[10px] font-black uppercase tracking-wide <?php echo $priorityBadge($prio); ?>">
                                <?php echo htmlspecialchars($prio); ?>
                            </span>
                        </td>
                        <td style="max-width:200px">
                            <div class="font-bold text-slate-900 dark:text-white text-sm leading-tight">
                                <?php echo htmlspecialchars($req['title']); ?>
                            </div>
                            <div class="text-xs text-slate-400 mt-0.5 line-clamp-1">
                                <?php echo htmlspecialchars(mb_strimwidth($req['description'] ?? '', 0, 60, '…')); ?>
                            </div>
                            <?php if ($req['image_path']): ?>
                            <a href="<?php echo htmlspecialchars($req['image_path']); ?>" target="_blank"
                               class="inline-flex items-center gap-1 mt-1 text-[10px] font-black text-accent-green hover:underline">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                Photo
                            </a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-bold text-sm text-slate-900 dark:text-white">
                                <?php echo htmlspecialchars($req['tenant_name'] ?? 'N/A'); ?>
                            </div>
                        </td>
                        <td>
                            <div class="font-bold text-sm text-slate-900 dark:text-white">
                                <?php echo htmlspecialchars($req['property_title'] ?? '—'); ?>
                            </div>
                            <?php if ($req['unit_number']): ?>
                            <div class="text-xs text-slate-400">Unit <?php echo htmlspecialchars($req['unit_number']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="<?php echo $daysClass; ?> text-sm"><?php echo $daysOpen; ?>d</span>
                        </td>

                        <?php if ($canManage): ?>
                        <td style="min-width:140px">
                            <?php if (in_array($role, ['admin', 'staff'])): ?>
                            <form action="actions/maintenance_actions.php" method="POST">
                                <input type="hidden" name="id" value="<?php echo $req['id']; ?>">
                                <input type="hidden" name="action" value="assign_agent">
                                <input type="hidden" name="redirect" value="../maintenance.php">
                                <select name="staff_id" onchange="this.form.submit()"
                                    class="w-full px-2 py-1.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-[11px] font-bold focus:ring-2 focus:ring-accent-green/20 outline-none transition">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($staff as $s): ?>
                                    <option value="<?php echo $s['id']; ?>" <?php echo ($req['assigned_staff_id'] ?? '') == $s['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($s['full_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                            <?php else: ?>
                            <span class="text-sm text-slate-600 dark:text-slate-400">
                                <?php echo htmlspecialchars($req['assigned_agent_name'] ?? 'Unassigned'); ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>

                        <td style="min-width:130px">
                            <?php if (in_array($role, ['admin', 'staff'])): ?>
                            <form action="actions/maintenance_actions.php" method="POST">
                                <input type="hidden" name="id" value="<?php echo $req['id']; ?>">
                                <input type="hidden" name="action" value="update_status">
                                <select name="status" onchange="this.form.submit()"
                                    class="w-full px-2 py-1.5 bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg text-[11px] font-black outline-none transition
                                    <?php echo $req['status'] === 'Completed' ? 'text-green-600 dark:text-green-400' : ($req['status'] === 'In Progress' ? 'text-blue-600 dark:text-blue-400' : 'text-orange-600'); ?>">
                                    <option value="Pending"     <?php echo $req['status'] === 'Pending'     ? 'selected' : ''; ?>>Pending</option>
                                    <option value="In Progress" <?php echo $req['status'] === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="Completed"   <?php echo $req['status'] === 'Completed'   ? 'selected' : ''; ?>>Completed</option>
                                </select>
                            </form>
                            <?php else: ?>
                            <?php
                            $sBadge = match($req['status']) {
                                'Completed'   => 'badge-green',
                                'In Progress' => 'badge-blue',
                                default       => 'badge-orange',
                            };
                            ?>
                            <span class="badge <?php echo $sBadge; ?>"><?php echo $req['status']; ?></span>
                            <?php endif; ?>
                        </td>

                        <td class="text-right">
                            <div class="flex items-center justify-end gap-1.5 flex-wrap">
                                <?php if ($role === 'landlord' && !empty($req['pushed_to_landlord']) && ($req['landlord_approval_status'] ?? '') === 'Pending'): ?>
                                <form action="actions/maintenance_actions.php" method="POST" class="inline">
                                    <input type="hidden" name="id" value="<?php echo $req['id']; ?>">
                                    <input type="hidden" name="action" value="landlord_decision">
                                    <button name="decision" value="Approved" class="px-2 py-1.5 bg-green-50 dark:bg-green-900/20 text-green-600 hover:bg-green-100 rounded-lg text-[10px] font-black uppercase transition-colors">Approve</button>
                                    <button name="decision" value="Rejected" class="px-2 py-1.5 bg-red-50 dark:bg-red-900/20 text-red-500 hover:bg-red-100 rounded-lg text-[10px] font-black uppercase transition-colors ml-1">Deny</button>
                                </form>
                                <?php elseif (in_array($role, ['admin', 'staff'])): ?>
                                <button onclick="openCostModal(<?php echo htmlspecialchars(json_encode([
                                    'id'            => $req['id'],
                                    'title'         => $req['title'],
                                    'vendor_name'   => $req['vendor_name']   ?? '',
                                    'quoted_amount' => $req['quoted_amount']  ?? '',
                                    'actual_cost'   => $req['actual_cost']    ?? '',
                                    'cost_status'   => $req['cost_status']    ?? '',
                                    'vendor_notes'  => $req['vendor_notes']   ?? '',
                                ])); ?>)"
                                    class="inline-flex items-center gap-1 px-2.5 py-1.5 text-[10px] font-black uppercase border border-slate-200 dark:border-slate-700 hover:bg-slate-900 dark:hover:bg-white hover:text-white dark:hover:text-slate-900 hover:border-transparent rounded-lg transition-all whitespace-nowrap">
                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                    <?php echo $hasCost ? 'Cost' : 'Log Cost'; ?>
                                </button>
                                <?php if (empty($req['pushed_to_landlord'])): ?>
                                <form action="actions/maintenance_actions.php" method="POST" class="inline">
                                    <input type="hidden" name="id" value="<?php echo $req['id']; ?>">
                                    <input type="hidden" name="action" value="push_to_landlord">
                                    <button type="submit" class="px-2.5 py-1.5 bg-slate-900 dark:bg-white dark:text-slate-900 text-white rounded-lg text-[10px] font-black uppercase hover:opacity-80 transition-all whitespace-nowrap">
                                        Escalate
                                    </button>
                                </form>
                                <?php else: ?>
                                <?php
                                $las = $req['landlord_approval_status'] ?? 'Pending';
                                $lasBadge = match($las) { 'Approved' => 'badge-green', 'Rejected' => 'badge-red', default => 'badge-orange' };
                                ?>
                                <span class="badge <?php echo $lasBadge; ?> text-[9px]">L: <?php echo $las; ?></span>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="maintEmpty" class="hidden text-center py-10">
            <p class="text-slate-400 font-bold text-sm">No requests match your current filter.</p>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /space-y-8 -->

<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- NEW REQUEST MODAL                                                  -->
<!-- ══════════════════════════════════════════════════════════════════ -->
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
                <input type="text" name="title" required placeholder="E.g. Leaking Faucet" class="form-input w-full">
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Description</label>
                <textarea name="description" rows="3" required class="form-input w-full resize-none"></textarea>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Upload Image / Take Picture</label>
                <div class="relative group">
                    <input type="file" name="maintenance_image" accept="image/*" capture="environment" class="hidden" id="maintenance_image_input" onchange="updateFileName(this)">
                    <label for="maintenance_image_input" class="flex flex-col items-center justify-center w-full h-28 border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-2xl cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800/60 transition-all">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-slate-400 mb-2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                        <p id="file-name-display" class="text-xs font-bold text-slate-500">Tap to upload or take a photo</p>
                    </label>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Property</label>
                    <select name="property_id" id="maintPropertySelect" onchange="filterMaintUnits(this.value)" class="form-input w-full">
                        <option value="">Select Property…</option>
                        <?php foreach ($allProperties as $p): ?>
                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars((string)$p['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Unit</label>
                    <select name="unit_id" id="maintUnitSelect" class="form-input w-full">
                        <option value="">Select Unit…</option>
                    </select>
                </div>
            </div>
            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Priority</label>
                <select name="priority" class="form-input w-full">
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

<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- LOG COST MODAL                                                     -->
<!-- ══════════════════════════════════════════════════════════════════ -->
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
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Quoted (<?php echo $currency; ?>)</label>
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
                <textarea name="vendor_notes" id="costNotes" rows="3" placeholder="Any notes about the work or vendor…" class="form-input w-full resize-none"></textarea>
            </div>
            <button type="submit" class="btn-green w-full justify-center py-4">Save Cost Details</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
const allUnits = <?php echo json_encode($allUnits); ?>;
let currentMaintStatus   = '';
let currentMaintPriority = '';

function filterMaintUnits(propertyId) {
    const unitSelect = document.getElementById('maintUnitSelect');
    unitSelect.innerHTML = '<option value="">Select Unit…</option>';
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
        display.textContent = 'Tap to upload or take a photo';
        display.classList.replace('text-accent-green', 'text-slate-500');
    }
}

function openCostModal(data) {
    document.getElementById('costModalId').value       = data.id           || '';
    document.getElementById('costModalTitle').textContent = data.title     || '';
    document.getElementById('costVendorName').value    = data.vendor_name  || '';
    document.getElementById('costQuotedAmount').value  = data.quoted_amount || '';
    document.getElementById('costActual').value        = data.actual_cost  || '';
    document.getElementById('costStatus').value        = data.cost_status  || '';
    document.getElementById('costNotes').value         = data.vendor_notes || '';
    openModal('costModal');
}

function filterMaintStatus(status) {
    currentMaintStatus   = status;
    currentMaintPriority = '';
    applyMaintFilters();
    document.querySelectorAll('.maint-tab').forEach(t => {
        t.classList.remove('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-sm');
        t.classList.add('text-slate-500');
    });
    const tid = 'mtab_' + (status || 'all').toLowerCase().replace(/\s/g, '');
    const el = document.getElementById(tid);
    if (el) {
        el.classList.add('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-sm');
        el.classList.remove('text-slate-500');
    }
}

function filterMaintPriority(priority) {
    currentMaintPriority = priority;
    currentMaintStatus   = '';
    document.getElementById('maintPriorityFilter').value = priority;
    // Reset status tabs
    document.querySelectorAll('.maint-tab').forEach(t => {
        t.classList.remove('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-sm');
        t.classList.add('text-slate-500');
    });
    const allTab = document.getElementById('mtab_all');
    if (allTab) { allTab.classList.add('bg-white', 'dark:bg-slate-700', 'text-slate-900', 'dark:text-white', 'shadow-sm'); allTab.classList.remove('text-slate-500'); }
    applyMaintFilters();
}

function applyMaintFilters() {
    const q     = (document.getElementById('maintSearch')?.value || '').toLowerCase().trim();
    const prio  = document.getElementById('maintPriorityFilter')?.value || currentMaintPriority;
    const rows  = document.querySelectorAll('.maint-row');
    let visible = 0;
    rows.forEach(row => {
        const match =
            (!currentMaintStatus || row.dataset.status   === currentMaintStatus) &&
            (!prio               || row.dataset.priority === prio) &&
            (!q                  || (row.dataset.search || '').includes(q));
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    const emptyEl = document.getElementById('maintEmpty');
    if (emptyEl) emptyEl.classList.toggle('hidden', visible > 0);
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeModal('newRequestModal');
        closeModal('costModal');
    }
});
</script>
