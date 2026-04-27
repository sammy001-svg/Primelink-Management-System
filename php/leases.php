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
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($leases as $lease):
                        $isExpired = strtotime($lease['end_date']) < time();
                        $isExpiring = !$isExpired && strtotime($lease['end_date']) < strtotime('+30 days');
                        $statusBadge = $isExpired ? 'badge-red' : ($isExpiring ? 'badge-orange' : 'badge-green');
                        $statusText  = $isExpired ? 'Expired' : ($isExpiring ? 'Expiring Soon' : 'Active');
                    ?>
                    <tr>
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
                        <td class="font-black text-slate-900 dark:text-white">KSh <?php echo number_format($lease['monthly_rent']); ?></td>
                        <td class="text-slate-600 dark:text-slate-400">KSh <?php echo number_format($lease['deposit'] ?? 0); ?></td>
                        <td><span class="badge <?php echo $statusBadge; ?>"><?php echo $statusText; ?></span></td>
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
                <div><label class="form-label">Security Deposit (KSh)</label><input type="number" name="deposit" class="form-input"></div>
            </div>
            <div><label class="form-label">Terms & Notes</label><textarea name="terms" rows="2" class="form-input" style="resize:vertical;"></textarea></div>
            <button type="submit" class="btn-gold w-full justify-center py-4">Create Lease Agreement</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
