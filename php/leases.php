<?php
/**
 * Leases Page
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = getCurrentUser($pdo);
$role = $_SESSION['role'] ?? 'tenant';
$pageTitle = "Leases";

// Proactive Self-Healing for schema drift
try {
    $pdo->query("SELECT signed_lease_url, termination_date, renewal_status, parent_lease_id FROM leases LIMIT 1");
} catch (PDOException $e) {
    if ($e->getCode() == '42S22') {
        $pdo->exec("ALTER TABLE `leases` ADD COLUMN IF NOT EXISTS `signed_lease_url` VARCHAR(255) NULL AFTER `status` ");
        $pdo->exec("ALTER TABLE `leases` ADD COLUMN IF NOT EXISTS `termination_date` DATE NULL AFTER `signed_lease_url` ");
        $pdo->exec("ALTER TABLE `leases` ADD COLUMN IF NOT EXISTS `termination_reason` TEXT NULL AFTER `termination_date` ");
        try { $pdo->exec("ALTER TABLE `leases` ADD COLUMN `renewal_status` ENUM('Offered','Accepted','Declined') NULL AFTER `status`"); } catch (PDOException $e2) {}
        try { $pdo->exec("ALTER TABLE `leases` ADD COLUMN `parent_lease_id` VARCHAR(36) NULL AFTER `renewal_status`"); } catch (PDOException $e2) {}
    }
}

// Scoping logic
if ($role === 'landlord') {
    $landlordId = getLandlordId($pdo);
    $leases = $pdo->prepare("
        SELECT l.*, t.full_name as tenant_name, t.email as tenant_email,
               p.title as property_title, p.location as property_location,
               l.renewal_status
        FROM leases l
        JOIN tenants t ON l.tenant_id = t.id
        JOIN properties p ON l.property_id = p.id
        WHERE p.landlord_id = ?
        ORDER BY l.created_at DESC
    ");
    $leases->execute([$landlordId]);
    $leases = $leases->fetchAll();
    $canCreateLease = false;
} elseif ($role === 'tenant') {
    $stmtT = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
    $stmtT->execute([$_SESSION['user_id']]);
    $tenantId = $stmtT->fetchColumn();

    $leases = $pdo->prepare("
        SELECT l.*, t.full_name as tenant_name, t.email as tenant_email,
               p.title as property_title, p.location as property_location,
               u.unit_number
        FROM leases l
        JOIN tenants t ON l.tenant_id = t.id
        JOIN properties p ON l.property_id = p.id
        LEFT JOIN units u ON l.unit_id = u.id
        WHERE l.tenant_id = ?
        ORDER BY l.created_at DESC
    ");
    $leases->execute([$tenantId]);
    $leases = $leases->fetchAll();
    $canCreateLease = false;
} else {
    requireRole(['admin', 'staff']);
    // Self-heal unit deposit columns (may not exist on older installs)
    foreach ([
        "ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `water_deposit`       DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `deposit_amount`",
        "ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `electricity_deposit` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `water_deposit`",
        "ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `goodwill`            DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `electricity_deposit`",
    ] as $_ddl) { try { $pdo->exec($_ddl); } catch (PDOException $_ex) {} }

    $leases = $pdo->query("
        SELECT l.*, t.id AS tenant_id_val, t.full_name AS tenant_name, t.email AS tenant_email,
               p.title AS property_title, p.location AS property_location,
               u.unit_number, u.water_deposit, u.electricity_deposit, u.goodwill,
               l.renewal_status
        FROM leases l
        JOIN tenants t ON l.tenant_id = t.id
        JOIN properties p ON l.property_id = p.id
        LEFT JOIN units u ON l.unit_id = u.id
        ORDER BY l.created_at DESC
    ")->fetchAll();
    $canCreateLease = true;
}

$allTenants    = $pdo->query("SELECT id, full_name FROM tenants ORDER BY full_name")->fetchAll();
$allProperties = $pdo->query("SELECT id, title, location FROM properties ORDER BY title")->fetchAll();

// ── Deposit refunds: self-heal table + fetch existing records ─────────────
try { $pdo->exec("CREATE TABLE IF NOT EXISTS lease_deposits (
    id VARCHAR(36) NOT NULL PRIMARY KEY,
    lease_id VARCHAR(36) NOT NULL,
    tenant_id VARCHAR(36) NOT NULL,
    total_deposit DECIMAL(15,2) NOT NULL DEFAULT 0,
    deduct_arrears DECIMAL(15,2) NOT NULL DEFAULT 0,
    deduct_maintenance DECIMAL(15,2) NOT NULL DEFAULT 0,
    deduct_cleaning DECIMAL(15,2) NOT NULL DEFAULT 0,
    deduct_damages DECIMAL(15,2) NOT NULL DEFAULT 0,
    deduct_other DECIMAL(15,2) NOT NULL DEFAULT 0,
    deduct_notes TEXT NULL,
    total_deductions DECIMAL(15,2) NOT NULL DEFAULT 0,
    net_refund DECIMAL(15,2) NOT NULL DEFAULT 0,
    scheduled_date DATE NULL,
    refund_method VARCHAR(50) NULL DEFAULT 'Bank Transfer',
    refund_reference VARCHAR(100) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Scheduled',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (PDOException $_ex) {}

$depositsByLease = [];
try {
    foreach ($pdo->query("SELECT * FROM lease_deposits")->fetchAll() as $dr) {
        $depositsByLease[$dr['lease_id']] = $dr;
    }
} catch (PDOException $_ex) {}

// ── Pre-fetch data for deposit modal (after termination redirect) ─────────
$depositLeaseId   = trim($_GET['deposit_lease'] ?? '');
$depositLeaseData = null;
if ($depositLeaseId && $role !== 'tenant') {
    $dlq = $pdo->prepare("
        SELECT l.id, l.deposit_amount, l.tenant_id, l.monthly_rent,
               t.full_name AS tenant_name, t.id AS tenant_id_val,
               u.unit_number,
               COALESCE(u.water_deposit, 0)       AS water_deposit,
               COALESCE(u.electricity_deposit, 0) AS electricity_deposit,
               COALESCE(u.goodwill, 0)            AS goodwill,
               COALESCE((SELECT SUM(amount) FROM invoices
                         WHERE tenant_id = t.id AND status NOT IN ('Paid','Cancelled')), 0) AS arrears
        FROM leases l
        JOIN tenants t ON l.tenant_id = t.id
        LEFT JOIN units u ON l.unit_id = u.id
        WHERE l.id = ?
    ");
    $dlq->execute([$depositLeaseId]);
    $depositLeaseData = $dlq->fetch() ?: null;
}

require_once __DIR__ . '/includes/settings.php';
$currency = getSetting($pdo, 'currency_symbol', 'KSh');

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">
    <?php if (isset($_GET['success'])): ?>
    <?php
    $toastMsg = match($_GET['success']) {
        'created'       => 'Lease created successfully!',
        'renewed'       => 'Lease renewed successfully — new period activated.',
        'terminated'    => 'Lease terminated. Please schedule the deposit refund below.',
        'uploaded'      => 'Signed lease document uploaded.',
        'offered'       => 'Renewal offer marked — tenant will be notified.',
        'accepted'      => 'Renewal offer marked as accepted.',
        'declined'      => 'Renewal declined. Consider relisting the unit.',
        'deposit_saved' => 'Deposit refund schedule saved.',
        'deposit_paid'  => 'Deposit marked as paid to tenant.',
        default         => 'Action completed successfully.',
    };
    ?>
    <div class="p-4 bg-green-500/10 border border-green-500/20 text-green-600 dark:text-green-400 rounded-2xl font-bold text-sm">
        <?php echo $toastMsg; ?>
    </div>
    <?php endif; ?>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white"><?php echo $role === 'tenant' ? 'My Lease Agreement' : 'Lease Management'; ?></h1>
            <p class="text-slate-400 font-medium text-sm"><?php 
                if ($role === 'landlord') echo 'Lease agreements for your properties.';
                elseif ($role === 'tenant') echo 'View your lease details, expiry dates, and download your agreement.';
                else echo 'Create and track active lease agreements'; 
            ?></p>
        </div>
        <?php if ($canCreateLease): ?>
        <button onclick="openModal('newLeaseModal')" class="btn-primary gap-2">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
            New Lease
        </button>
        <?php endif; ?>
    </div>

    <!-- Leases Table -->
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800">
            <h3 class="font-black text-slate-900 dark:text-white"><?php echo $role === 'tenant' ? 'Current Lease Details' : 'All Leases'; ?> <span class="text-slate-400 font-medium text-sm ml-2">(<?php echo count($leases); ?>)</span></h3>
        </div>
        <?php if (empty($leases)): ?>
        <div class="text-center py-16">
            <svg class="mx-auto text-slate-200 dark:text-slate-800 mb-4" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <p class="text-slate-400 font-bold">No leases found</p>
            <p class="text-slate-400 text-sm mt-1">Contact administration if you believe this is an error.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <?php if ($role !== 'tenant'): ?><th>Tenant</th><?php endif; ?>
                        <th>Property & Unit</th>
                        <th>Period</th>
                        <th>Monthly Rent</th>
                        <th>Renewal</th>
                        <th>Document</th>
                        <th>Status</th>
                        <th>Deposit</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leases as $lease):
                        $isExpired = $lease['status'] === 'Expired' || strtotime($lease['end_date']) < time();
                        $isExpiring = $lease['status'] === 'Active' && !$isExpired && strtotime($lease['end_date']) < strtotime('+30 days');
                        $isTerminated = $lease['status'] === 'Terminated';
                        
                        $statusBadge = $isTerminated ? 'badge-red' : ($isExpired ? 'badge-red' : ($isExpiring ? 'badge-orange' : 'badge-green'));
                        $statusText  = $isTerminated ? 'Terminated' : ($isExpired ? 'Expired' : ($isExpiring ? 'Expiring Soon' : 'Active'));
                        
                        // Renewal status
                        $rs = $lease['renewal_status'] ?? null;
                        if ($rs === 'Offered')       { $rsBadge = 'badge-blue';   $rsLabel = 'Offered'; }
                        elseif ($rs === 'Accepted')  { $rsBadge = 'badge-green';  $rsLabel = 'Accepted'; }
                        elseif ($rs === 'Declined')  { $rsBadge = 'badge-red';    $rsLabel = 'Declined'; }
                        else                         { $rsBadge = ''; $rsLabel = ''; }
                    ?>
                    <tr class="<?php echo $isTerminated ? 'opacity-60 grayscale-[0.5]' : ''; ?>">
                        <?php if ($role !== 'tenant'): ?>
                        <td>
                            <div class="font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($lease['tenant_name']); ?></div>
                            <div class="text-xs text-slate-400"><?php echo htmlspecialchars($lease['tenant_email']); ?></div>
                        </td>
                        <?php endif; ?>
                        <td>
                            <div class="font-bold"><?php echo htmlspecialchars($lease['property_title']); ?></div>
                            <div class="text-xs text-slate-400"><?php echo htmlspecialchars($lease['property_location']); ?> <?php echo !empty($lease['unit_number']) ? '— Unit ' . htmlspecialchars($lease['unit_number']) : ''; ?></div>
                        </td>
                        <td>
                            <div class="text-xs font-bold text-slate-600 dark:text-slate-400"><?php echo date('M j, Y', strtotime($lease['start_date'])); ?> —</div>
                            <div class="text-xs font-black text-slate-900 dark:text-white uppercase tracking-tighter">Expires <?php echo date('M j, Y', strtotime($lease['end_date'])); ?></div>
                        </td>
                        <td class="font-black text-slate-900 dark:text-white text-xs">KSh <?php echo number_format($lease['monthly_rent']); ?></td>
                        <td>
                            <?php if ($rsLabel): ?>
                                <span class="badge <?php echo $rsBadge; ?>"><?php echo $rsLabel; ?></span>
                            <?php elseif ($canCreateLease && !$isTerminated && !$isExpired): ?>
                                <form action="actions/lease_actions.php" method="POST" class="inline">
                                    <input type="hidden" name="action" value="mark_renewal_status">
                                    <input type="hidden" name="lease_id" value="<?php echo $lease['id']; ?>">
                                    <input type="hidden" name="renewal_status" value="Offered">
                                    <button type="submit" class="px-2 py-1 bg-blue-50 dark:bg-blue-900/20 text-blue-500 hover:bg-blue-100 rounded-lg text-[10px] font-black uppercase transition-colors whitespace-nowrap">
                                        Mark Offered
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="text-[10px] text-slate-300">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($lease['signed_lease_url'])): ?>
                                <a href="<?php echo htmlspecialchars($lease['signed_lease_url']); ?>" target="_blank" class="inline-flex items-center gap-2 px-3 py-1.5 bg-accent-green/10 text-accent-green rounded-lg text-[10px] font-black uppercase hover:bg-accent-green hover:text-white transition-all">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    Download Signed
                                </a>
                            <?php else: ?>
                                <a href="view_lease.php?lease_id=<?php echo $lease['id']; ?>" target="_blank" class="inline-flex items-center gap-2 px-3 py-1.5 bg-blue-500/10 text-blue-500 rounded-lg text-[10px] font-black uppercase hover:bg-blue-500 hover:text-white transition-all">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/></svg>
                                    View Digital
                                </a>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?php echo $statusBadge; ?>"><?php echo $statusText; ?></span></td>
                        <td>
                            <?php
                            $dep = $depositsByLease[$lease['id']] ?? null;
                            if ($isTerminated && $canCreateLease):
                                if (!$dep): ?>
                                    <button onclick="openDepositModal('<?php echo $lease['id']; ?>')"
                                        class="px-2.5 py-1 rounded-lg text-[10px] font-black uppercase tracking-widest bg-orange-100 dark:bg-orange-900/30 text-orange-600 hover:bg-orange-200 transition-colors whitespace-nowrap">
                                        Process Deposit
                                    </button>
                                <?php elseif ($dep['status'] === 'Paid'): ?>
                                    <span class="badge badge-green text-[10px]">Refund Paid</span>
                                <?php else: ?>
                                    <div class="flex items-center gap-1.5">
                                        <span class="badge badge-blue text-[10px]">Scheduled</span>
                                        <form method="POST" action="actions/lease_actions.php" class="inline">
                                            <input type="hidden" name="action" value="mark_deposit_paid">
                                            <input type="hidden" name="lease_id" value="<?php echo $lease['id']; ?>">
                                            <button type="submit" class="text-[9px] font-black text-green-500 hover:text-green-700 uppercase tracking-wider" title="Mark refund as paid">✓ Paid</button>
                                        </form>
                                    </div>
                                <?php endif;
                            else: ?>
                                <span class="text-[10px] text-slate-300 dark:text-slate-700">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-2">
                                <a href="view_lease.php?lease_id=<?php echo $lease['id']; ?>" target="_blank" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition-colors text-slate-400 hover:text-slate-900 dark:hover:text-white" title="View Full Terms">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                </a>
                                <?php if ($canCreateLease && !$isTerminated): ?>
                                <button onclick="setUploadLeaseId('<?php echo $lease['id']; ?>')" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition-colors text-slate-400 hover:text-accent-green" title="Upload Signed">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                </button>
                                <button onclick="setRenewLeaseId('<?php echo $lease['id']; ?>', '<?php echo $lease['monthly_rent']; ?>')" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition-colors text-slate-400 hover:text-blue-500" title="Renew">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21 21-6-6m6 6v-4.8m0 4.8h-4.8"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="m3 3 6 6m-6-6v4.8m0-4.8h4.8"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
                                </button>
                                <button onclick="setTerminateLeaseId('<?php echo $lease['id']; ?>')" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition-colors text-slate-400 hover:text-red-500" title="Terminate">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m18 6-12 12"/><path d="m6 6 12 12"/></svg>
                                </button>
                                <?php endif; ?>
                                <?php if ($isTerminated && $canCreateLease && isset($depositsByLease[$lease['id']])): ?>
                                <button onclick="openDepositModal('<?php echo $lease['id']; ?>')" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition-colors text-slate-400 hover:text-orange-500" title="Edit Deposit Refund">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- New Lease Modal -->
<div class="modal-overlay" id="newLeaseModal" style="display:none;">
    <div class="modal-card">
        <button onclick="closeModal('newLeaseModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-6">Create New Lease</h2>
        <form action="actions/lease_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="create">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label">Tenant</label>
                    <select name="tenant_id" required class="form-input">
                        <option value="">Select Tenant</option>
                        <?php foreach ($allTenants as $t): ?>
                        <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Property</label>
                    <select name="property_id" required class="form-input">
                        <option value="">Select Property</option>
                        <?php foreach ($allProperties as $p): ?>
                        <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['title']); ?> — <?php echo htmlspecialchars($p['location']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="form-label">Start Date</label><input type="date" name="start_date" required class="form-input"></div>
                <div><label class="form-label">End Date</label><input type="date" name="end_date" required class="form-input"></div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="form-label">Monthly Rent (KSh)</label><input type="number" name="monthly_rent" required class="form-input"></div>
                <div><label class="form-label">Security Deposit (KSh)</label><input type="number" name="deposit" class="form-input" placeholder="e.g. 15000"></div>
            </div>
            <div><label class="form-label">Terms & Notes</label><textarea name="terms" rows="2" class="form-input" style="resize:vertical;"></textarea></div>
            <button type="submit" class="btn-gold w-full justify-center py-4">Create Lease Agreement</button>
        </form>
    </div>
</div>

<!-- Upload Signed Lease Modal -->
<div class="modal-overlay" id="uploadLeaseModal" style="display:none;">
    <div class="modal-card max-w-md">
        <button onclick="closeModal('uploadLeaseModal')" class="absolute top-5 right-5 text-slate-400">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-4">Upload Signed Lease</h2>
        <p class="text-slate-400 text-sm mb-6 font-medium">Select the scanned PDF or image of the signed agreement.</p>
        <form action="actions/lease_actions.php" method="POST" enctype="multipart/form-data" class="space-y-5">
            <input type="hidden" name="action" value="upload_signed">
            <input type="hidden" name="lease_id" id="upload_lease_id">
            <div>
                <input type="file" name="signed_lease" required class="form-input" accept=".pdf,image/*">
            </div>
            <button type="submit" class="btn-primary w-full justify-center py-4">Process Document</button>
        </form>
    </div>
</div>

<!-- Renew Lease Modal -->
<div class="modal-overlay" id="renewLeaseModal" style="display:none;">
    <div class="modal-card max-w-md">
        <button onclick="closeModal('renewLeaseModal')" class="absolute top-5 right-5 text-slate-400">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-2">Renew Lease</h2>
        <p class="text-slate-400 text-sm mb-6 font-medium">Create a new lease period for this tenant.</p>
        <form action="actions/lease_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="renew">
            <input type="hidden" name="lease_id" id="renew_lease_id">
            <div>
                <label class="form-label text-xs uppercase tracking-widest font-black text-slate-400">New End Date</label>
                <input type="date" name="new_end_date" required class="form-input">
            </div>
            <div>
                <label class="form-label text-xs uppercase tracking-widest font-black text-slate-400">Monthly Rent (Optional adjustment)</label>
                <input type="number" name="new_rent" id="renew_old_rent" class="form-input">
            </div>
            <button type="submit" class="btn-blue w-full justify-center py-4 rounded-xl text-white font-black bg-blue-600 hover:bg-blue-700">Submit Renewal</button>
        </form>
    </div>
</div>

<!-- Terminate Lease Modal -->
<div class="modal-overlay" id="terminateLeaseModal" style="display:none;">
    <div class="modal-card max-w-md bg-red-50/50">
        <button onclick="closeModal('terminateLeaseModal')" class="absolute top-5 right-5 text-slate-400">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-2 text-red-600">Terminate Lease</h2>
        <p class="text-slate-600 text-sm mb-6 font-medium">Are you sure you want to terminate this lease? This action marks the tenant as having vacated.</p>
        <form action="actions/lease_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="terminate">
            <input type="hidden" name="lease_id" id="terminate_lease_id">
            <div>
                <label class="form-label text-red-800">Termination Date</label>
                <input type="date" name="termination_date" required class="form-input border-red-200 focus:border-red-500" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div>
                <label class="form-label text-red-800">Reason for Termination</label>
                <textarea name="reason" rows="3" class="form-input border-red-200 focus:border-red-500" placeholder="e.g. End of contract, Eviction, Tenant departure..."></textarea>
            </div>
            <button type="submit" class="w-full py-4 bg-red-600 hover:bg-red-700 text-white font-black rounded-xl shadow-xl shadow-red-500/20 transition-all">Confirm Termination</button>
        </form>
    </div>
</div>

<!-- ══════════ DEPOSIT REFUND MODAL ══════════ -->
<?php if ($canCreateLease): ?>
<div id="depositRefundModal" class="modal-overlay" style="display:none;">
    <div class="modal-card" style="max-width:600px;">
        <button onclick="closeModal('depositRefundModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-1">Deposit Refund</h2>
        <p class="text-slate-400 text-sm font-medium mb-6" id="dep_subtitle">Schedule deposit refund and record deductions</p>

        <form action="actions/lease_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="process_deposit">
            <input type="hidden" name="lease_id" id="dep_lease_id">
            <input type="hidden" name="tenant_id" id="dep_tenant_id">
            <input type="hidden" name="total_deposit" id="dep_total_deposit_hidden">
            <input type="hidden" name="net_refund" id="dep_net_refund_hidden">

            <!-- Tenant + Deposit Summary -->
            <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-700 space-y-3">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-green-500 to-emerald-600 flex items-center justify-center text-white font-black text-sm shrink-0" id="dep_avatar"></div>
                    <div>
                        <p class="font-black text-slate-900 dark:text-white text-sm" id="dep_tenant_name"></p>
                        <p class="text-[11px] text-slate-400" id="dep_unit_label"></p>
                    </div>
                </div>
                <div class="grid grid-cols-4 gap-2 pt-1">
                    <div class="p-2.5 rounded-xl bg-white dark:bg-slate-900 text-center">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-wider mb-0.5">Unit Dep.</p>
                        <p class="text-xs font-black text-slate-700 dark:text-slate-200" id="dep_unit_dep"></p>
                    </div>
                    <div class="p-2.5 rounded-xl bg-white dark:bg-slate-900 text-center">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-wider mb-0.5">Water Dep.</p>
                        <p class="text-xs font-black text-slate-700 dark:text-slate-200" id="dep_water_dep"></p>
                    </div>
                    <div class="p-2.5 rounded-xl bg-white dark:bg-slate-900 text-center">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-wider mb-0.5">Elec. Dep.</p>
                        <p class="text-xs font-black text-slate-700 dark:text-slate-200" id="dep_elec_dep"></p>
                    </div>
                    <div class="p-2.5 rounded-xl bg-white dark:bg-slate-900 text-center">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-wider mb-0.5">Goodwill</p>
                        <p class="text-xs font-black text-slate-700 dark:text-slate-200" id="dep_goodwill"></p>
                    </div>
                </div>
                <div class="flex items-center justify-between pt-1 border-t border-slate-100 dark:border-slate-700">
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Deposits Held</span>
                    <span class="font-black text-slate-900 dark:text-white" id="dep_total_display"></span>
                </div>
            </div>

            <!-- Deductions -->
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">Deductions from Deposit</p>
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Rent Arrears</label>
                            <input type="number" name="deduct_arrears" id="dep_ded_arrears" min="0" step="0.01" value="0"
                                oninput="recalcDeposit()" class="form-input text-sm py-2.5">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Maintenance / Repairs</label>
                            <input type="number" name="deduct_maintenance" id="dep_ded_maint" min="0" step="0.01" value="0"
                                oninput="recalcDeposit()" class="form-input text-sm py-2.5">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Cleaning</label>
                            <input type="number" name="deduct_cleaning" id="dep_ded_clean" min="0" step="0.01" value="0"
                                oninput="recalcDeposit()" class="form-input text-sm py-2.5">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Damages</label>
                            <input type="number" name="deduct_damages" id="dep_ded_damages" min="0" step="0.01" value="0"
                                oninput="recalcDeposit()" class="form-input text-sm py-2.5">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Other Deductions</label>
                            <input type="number" name="deduct_other" id="dep_ded_other" min="0" step="0.01" value="0"
                                oninput="recalcDeposit()" class="form-input text-sm py-2.5">
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Deduction Notes</label>
                            <input type="text" name="deduct_notes" id="dep_ded_notes" placeholder="e.g. broken window, 3 months arrears"
                                class="form-input text-sm py-2.5">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Net Refund Summary -->
            <div class="p-4 rounded-2xl border-2 border-accent-green/20 bg-accent-green/5 flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Deductions</p>
                    <p class="font-black text-red-500 text-lg" id="dep_total_ded">KSh 0</p>
                </div>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-slate-300"><path d="M5 12h14"/></svg>
                <div class="text-right">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Net Refund to Tenant</p>
                    <p class="font-black text-accent-green text-2xl" id="dep_net_refund">KSh 0</p>
                </div>
            </div>

            <!-- Refund Schedule -->
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3">Refund Schedule</p>
                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Refund Date</label>
                        <input type="date" name="scheduled_date" id="dep_sched_date" class="form-input text-sm py-2.5"
                            value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Payment Method</label>
                        <select name="refund_method" class="form-input text-sm py-2.5">
                            <option>Bank Transfer</option>
                            <option>M-Pesa</option>
                            <option>Cash</option>
                            <option>Check</option>
                        </select>
                    </div>
                    <div class="space-y-1.5 sm:col-span-2">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Reference / Notes</label>
                        <input type="text" name="refund_reference" placeholder="Bank ref, M-Pesa no., etc." class="form-input text-sm py-2.5">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-green w-full justify-center py-3.5 font-black">Save Deposit Refund Schedule →</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Embed lease data for JS deposit modal -->
<?php if ($canCreateLease):
$_leaseDepData = [];
foreach ($leases as $l) {
    $_leaseDepData[$l['id']] = [
        'tenant_name'         => $l['tenant_name']         ?? '',
        'tenant_id'           => $l['tenant_id_val']       ?? $l['tenant_id'] ?? '',
        'unit_number'         => $l['unit_number']         ?? '—',
        'deposit_amount'      => (float)($l['deposit_amount']      ?? 0),
        'water_deposit'       => (float)($l['water_deposit']       ?? 0),
        'electricity_deposit' => (float)($l['electricity_deposit'] ?? 0),
        'goodwill'            => (float)($l['goodwill']            ?? 0),
    ];
}
// Merge existing deposit records for pre-fill
$_existingDep = $depositsByLease;
?>
<script>
const _leaseDepData  = <?php echo json_encode($_leaseDepData,  JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const _existingDep   = <?php echo json_encode($_existingDep,   JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
const _depCurrency   = '<?php echo addslashes($currency); ?>';

function fmt(v) { return _depCurrency + ' ' + parseFloat(v||0).toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2}); }

function openDepositModal(leaseId) {
    const d = _leaseDepData[leaseId];
    if (!d) return;

    // Totals
    const unitDep  = d.deposit_amount;
    const waterDep = d.water_deposit;
    const elecDep  = d.electricity_deposit;
    const gwill    = d.goodwill;
    const total    = unitDep + waterDep + elecDep + gwill;

    // Populate header
    document.getElementById('dep_lease_id').value         = leaseId;
    document.getElementById('dep_tenant_id').value        = d.tenant_id;
    document.getElementById('dep_total_deposit_hidden').value = total.toFixed(2);
    document.getElementById('dep_avatar').textContent     = (d.tenant_name[0]||'?').toUpperCase();
    document.getElementById('dep_tenant_name').textContent= d.tenant_name;
    document.getElementById('dep_unit_label').textContent = 'Unit ' + d.unit_number;
    document.getElementById('dep_subtitle').textContent   = 'Lease: ' + leaseId.slice(0,8).toUpperCase();
    document.getElementById('dep_unit_dep').textContent   = fmt(unitDep);
    document.getElementById('dep_water_dep').textContent  = fmt(waterDep);
    document.getElementById('dep_elec_dep').textContent   = fmt(elecDep);
    document.getElementById('dep_goodwill').textContent   = fmt(gwill);
    document.getElementById('dep_total_display').textContent = fmt(total);

    // Pre-fill from existing record or defaults
    const ex = _existingDep[leaseId];
    document.getElementById('dep_ded_arrears').value  = ex ? ex.deduct_arrears  : 0;
    document.getElementById('dep_ded_maint').value    = ex ? ex.deduct_maintenance : 0;
    document.getElementById('dep_ded_clean').value    = ex ? ex.deduct_cleaning  : 0;
    document.getElementById('dep_ded_damages').value  = ex ? ex.deduct_damages   : 0;
    document.getElementById('dep_ded_other').value    = ex ? ex.deduct_other     : 0;
    document.getElementById('dep_ded_notes').value    = ex ? (ex.deduct_notes||'') : '';
    if (ex && ex.scheduled_date) document.getElementById('dep_sched_date').value = ex.scheduled_date;

    recalcDeposit();
    openModal('depositRefundModal');
}

function recalcDeposit() {
    const total   = parseFloat(document.getElementById('dep_total_deposit_hidden').value) || 0;
    const arrears = parseFloat(document.getElementById('dep_ded_arrears').value)  || 0;
    const maint   = parseFloat(document.getElementById('dep_ded_maint').value)    || 0;
    const clean   = parseFloat(document.getElementById('dep_ded_clean').value)    || 0;
    const damages = parseFloat(document.getElementById('dep_ded_damages').value)  || 0;
    const other   = parseFloat(document.getElementById('dep_ded_other').value)    || 0;

    const totalDed = arrears + maint + clean + damages + other;
    const net      = Math.max(0, total - totalDed);

    document.getElementById('dep_total_ded').textContent   = fmt(totalDed);
    document.getElementById('dep_net_refund').textContent  = fmt(net);
    document.getElementById('dep_net_refund_hidden').value = net.toFixed(2);
}

// Auto-open modal if redirected from termination
<?php if ($depositLeaseData): ?>
window.addEventListener('DOMContentLoaded', function() {
    // Pre-fill arrears from server data
    _leaseDepData[<?php echo json_encode($depositLeaseId); ?>] = _leaseDepData[<?php echo json_encode($depositLeaseId); ?>] || {};
    openDepositModal(<?php echo json_encode($depositLeaseId); ?>);
    // Set arrears to server-computed value
    document.getElementById('dep_ded_arrears').value = <?php echo (float)($depositLeaseData['arrears'] ?? 0); ?>;
    recalcDeposit();
});
<?php endif; ?>
</script>
<?php endif; ?>

<script>
function setUploadLeaseId(id) {
    document.getElementById('upload_lease_id').value = id;
    openModal('uploadLeaseModal');
}
function setRenewLeaseId(id, rent) {
    document.getElementById('renew_lease_id').value = id;
    document.getElementById('renew_old_rent').value = rent;
    openModal('renewLeaseModal');
}
function setTerminateLeaseId(id) {
    document.getElementById('terminate_lease_id').value = id;
    openModal('terminateLeaseModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
