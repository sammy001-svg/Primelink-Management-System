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
    $pdo->query("SELECT signed_lease_url, termination_date FROM leases LIMIT 1");
} catch (PDOException $e) {
    if ($e->getCode() == '42S22') {
        $pdo->exec("ALTER TABLE `leases` ADD COLUMN IF NOT EXISTS `signed_lease_url` VARCHAR(255) NULL AFTER `status` ");
        $pdo->exec("ALTER TABLE `leases` ADD COLUMN IF NOT EXISTS `termination_date` DATE NULL AFTER `signed_lease_url` ");
        $pdo->exec("ALTER TABLE `leases` ADD COLUMN IF NOT EXISTS `termination_reason` TEXT NULL AFTER `termination_date` ");
    }
}

// Scoping logic
if ($role === 'landlord') {
    $landlordId = getLandlordId($pdo);
    $leases = $pdo->prepare("
        SELECT l.*, t.full_name as tenant_name, t.email as tenant_email,
               p.title as property_title, p.location as property_location
        FROM leases l
        JOIN tenants t ON l.tenant_id = t.id
        JOIN properties p ON l.property_id = p.id
        WHERE p.landlord_id = ?
        ORDER BY l.created_at DESC
    ");
    $leases->execute([$landlordId]);
    $leases = $leases->fetchAll();
    $canCreateLease = false;
} else {
    requireRole(['staff']);
    $leases = $pdo->query("
        SELECT l.*, t.full_name as tenant_name, t.email as tenant_email,
               p.title as property_title, p.location as property_location
        FROM leases l
        JOIN tenants t ON l.tenant_id = t.id
        JOIN properties p ON l.property_id = p.id
        ORDER BY l.created_at DESC
    ")->fetchAll();
    $canCreateLease = true;
}

$allTenants    = $pdo->query("SELECT id, full_name FROM tenants ORDER BY full_name")->fetchAll();
$allProperties = $pdo->query("SELECT id, title, location FROM properties ORDER BY title")->fetchAll();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">
    <?php if (isset($_GET['success'])): ?>
    <div class="success-toast p-4 bg-green-500/10 border border-green-500/20 text-green-600 dark:text-green-400 rounded-2xl font-bold text-sm">
        Lease <?php echo $_GET['success'] == 'created' ? 'created' : 'updated'; ?> successfully!
    </div>
    <?php endif; ?>

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white">Lease Management</h1>
            <p class="text-slate-400 font-medium text-sm"><?php echo $role === 'landlord' ? 'Lease agreements for your properties.' : 'Create and track active lease agreements'; ?></p>
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
            <h3 class="font-black text-slate-900 dark:text-white">All Leases <span class="text-slate-400 font-medium text-sm ml-2">(<?php echo count($leases); ?>)</span></h3>
        </div>
        <?php if (empty($leases)): ?>
        <div class="text-center py-16">
            <svg class="mx-auto text-slate-200 dark:text-slate-800 mb-4" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <p class="text-slate-400 font-bold">No leases created yet</p>
            <p class="text-slate-400 text-sm mt-1">Click "New Lease" to get started</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Tenant</th>
                        <th>Property</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Monthly Rent</th>
                        <th>Deposit</th>
                        <th>Status</th>
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
                    ?>
                    <tr class="<?php echo $isTerminated ? 'opacity-60 grayscale-[0.5]' : ''; ?>">
                        <td>
                            <div class="font-bold text-slate-900 dark:text-white"><?php echo htmlspecialchars($lease['tenant_name']); ?></div>
                            <div class="text-xs text-slate-400"><?php echo htmlspecialchars($lease['tenant_email']); ?></div>
                        </td>
                        <td>
                            <div class="font-bold"><?php echo htmlspecialchars($lease['property_title']); ?></div>
                            <div class="text-xs text-slate-400"><?php echo htmlspecialchars($lease['property_location']); ?></div>
                        </td>
                        <td class="text-slate-600 dark:text-slate-400"><?php echo date('M j, Y', strtotime($lease['start_date'])); ?></td>
                        <td class="text-slate-600 dark:text-slate-400"><?php echo date('M j, Y', strtotime($lease['end_date'])); ?></td>
                        <td class="font-black text-slate-900 dark:text-white text-xs">KSh <?php echo number_format($lease['monthly_rent']); ?></td>
                        <td class="font-black text-slate-900 dark:text-white text-xs">KSh <?php echo number_format($lease['deposit_amount']); ?></td>
                        <td>
                            <?php if (!empty($lease['signed_lease_url'])): ?>
                                <a href="<?php echo htmlspecialchars($lease['signed_lease_url']); ?>" target="_blank" class="flex items-center gap-1 text-accent-green font-bold hover:underline">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    Signed
                                </a>
                            <?php else: ?>
                                <span class="text-slate-300 text-xs italic">No Doc</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?php echo $statusBadge; ?>"><?php echo $statusText; ?></span></td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-2">
                                <a href="view_lease.php?lease_id=<?php echo $lease['id']; ?>" target="_blank" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition-colors text-slate-400 hover:text-slate-900 dark:hover:text-white" title="View Draft">
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
