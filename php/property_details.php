<?php
/**
 * Property Details Page
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = getCurrentUser($pdo);
$role = $_SESSION['role'] ?? 'tenant';
$propertyId = $_GET['id'] ?? null;

if (!$propertyId) {
    header("Location: properties.php");
    exit();
}

// Fetch property details
$stmt = $pdo->prepare("
    SELECT p.*, l.full_name as landlord_name, l.phone as landlord_phone, l.email as landlord_email
    FROM properties p
    LEFT JOIN landlords l ON p.landlord_id = l.id
    WHERE p.id = ?
");
try {
    $stmt->execute([$propertyId]);
} catch (PDOException $e) {
    if ($e->getCode() == '42S22') {
        $pdo->exec("ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `water_rate` DECIMAL(15,2) NOT NULL DEFAULT 0 ");
        $pdo->exec("ALTER TABLE `properties` ADD COLUMN IF NOT EXISTS `garbage_fee` DECIMAL(15,2) NOT NULL DEFAULT 0 ");
        $stmt->execute([$propertyId]);
    } else {
        throw $e;
    }
}
$property = $stmt->fetch();

if (!$property) {
    die("Property not found.");
}

// Fetch units with active tenant + outstanding balance
$unitSql = "
    SELECT u.*,
        l.id         AS lease_id,
        l.start_date AS lease_start,
        l.end_date   AS lease_end,
        t.id         AS tenant_id,
        t.full_name  AS tenant_name,
        t.phone      AS tenant_phone,
        t.email      AS tenant_email,
        COALESCE((
            SELECT SUM(inv.amount)
            FROM   invoices inv
            WHERE  inv.tenant_id = t.id
              AND  inv.status NOT IN ('Paid','Cancelled')
        ), 0) AS outstanding_balance,
        (
            SELECT COUNT(*)
            FROM   invoices inv
            WHERE  inv.tenant_id = t.id
              AND  inv.status NOT IN ('Paid','Cancelled')
        ) AS unpaid_invoice_count
    FROM  units u
    LEFT JOIN leases  l ON l.unit_id = u.id AND l.status = 'Active'
    LEFT JOIN tenants t ON l.tenant_id = t.id
    WHERE u.property_id = ?
    ORDER BY u.unit_number ASC
";
$stmt = $pdo->prepare($unitSql);
try {
    $stmt->execute([$propertyId]);
} catch (PDOException $e) {
    if ($e->getCode() == '42S22') {
        $pdo->exec("ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `electricity_meter` VARCHAR(100) NULL AFTER `deposit_amount` ");
        $pdo->exec("ALTER TABLE `units` ADD COLUMN IF NOT EXISTS `water_meter` VARCHAR(100) NULL AFTER `electricity_meter` ");
        $stmt = $pdo->prepare($unitSql);
        $stmt->execute([$propertyId]);
    } else {
        throw $e;
    }
}
$units = $stmt->fetchAll();

// Build keyed panel data for JS (avoids quoting issues in onclick attrs)
$unitPanelData = [];
foreach ($units as $u) {
    $unitPanelData[$u['id']] = [
        'id'                => $u['id'],
        'unit_number'       => $u['unit_number'],
        'unit_type'         => $u['unit_type']         ?? '',
        'floor_number'      => $u['floor_number']       ?? 'G',
        'category'          => $u['category']           ?? '',
        'status'            => $u['status'],
        'monthly_rent'      => (float)$u['monthly_rent'],
        'deposit_amount'    => (float)$u['deposit_amount'],
        'electricity_meter' => $u['electricity_meter']  ?? '',
        'water_meter'       => $u['water_meter']        ?? '',
        'lease_start'       => $u['lease_start']        ?? null,
        'lease_end'         => $u['lease_end']          ?? null,
        'tenant_id'         => $u['tenant_id']          ?? null,
        'tenant_name'       => $u['tenant_name']        ?? null,
        'tenant_phone'      => $u['tenant_phone']       ?? null,
        'tenant_email'      => $u['tenant_email']       ?? null,
        'outstanding_balance' => (float)($u['outstanding_balance'] ?? 0),
        'unpaid_count'      => (int)($u['unpaid_invoice_count']    ?? 0),
    ];
}

$flash    = $_GET['success'] ?? '';
$flashErr = $_GET['error']   ?? '';

// Calculate stats
$totalUnits = count($units);
$occupiedUnits = 0;
$vacantUnits = 0;
$totalRent = 0;

foreach ($units as $u) {
    if ($u['status'] === 'Occupied') {
        $occupiedUnits++;
        $totalRent += $u['monthly_rent'];
    } else if ($u['status'] === 'Available') {
        $vacantUnits++;
    }
}

$occupancyRate = $totalUnits > 0 ? ($occupiedUnits / $totalUnits) * 100 : 0;

$pageTitle = $property['title'] . " | Details";
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

<?php
$flashMsg = match($flash) {
    'unit_created' => 'Unit registered successfully.',
    'unit_updated' => 'Unit updated.',
    'unit_deleted' => 'Unit deleted permanently.',
    default        => ''
};
$flashErrMsg = match($flashErr) {
    'has_tenant' => 'Cannot delete: unit has an active tenant. Terminate the lease first.',
    'not_found'  => 'Unit not found.',
    default      => $flashErr ? htmlspecialchars($flashErr) : '',
};
?>
<?php if ($flashMsg): ?>
<div class="p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-2xl text-green-700 dark:text-green-400 text-sm font-bold"><?php echo $flashMsg; ?></div>
<?php elseif ($flashErrMsg): ?>
<div class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-2xl text-red-700 dark:text-red-400 text-sm font-bold"><?php echo $flashErrMsg; ?></div>
<?php endif; ?>

    <!-- Header -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
        <div>
            <div class="flex items-center gap-2 mb-2">
                <a href="properties.php" class="text-[10px] font-black text-slate-400 uppercase tracking-widest hover:text-accent-green transition-colors flex items-center gap-1">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m15 18-6-6 6-6"/></svg>
                    Back to Registry
                </a>
            </div>
            <h1 class="text-4xl font-black text-slate-900 dark:text-white tracking-tight"><?php echo htmlspecialchars((string)($property['title'] ?? '')); ?></h1>
            <p class="text-slate-500 font-medium flex items-center gap-1 mt-1">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                <?php echo htmlspecialchars((string)($property['location'] ?? '')); ?>
            </p>
        </div>
        <div class="flex items-center gap-3">
            <span class="px-4 py-2 bg-accent-green/10 text-accent-green rounded-xl text-xs font-black uppercase tracking-widest border border-accent-green/20 shadow-sm">
                <?php echo $property['status']; ?>
            </span>
            <?php if ($role === 'admin' || $role === 'staff'): ?>
            <button onclick="openModal('addUnitModal')" class="btn-green shadow-lg shadow-accent-green/20">
                + Add Unit
            </button>
            <?php endif; ?>
            <?php if ($role !== 'tenant'): ?>
            <button onclick="window.print()" class="p-3 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-xl text-slate-500 hover:text-accent-green transition-all shadow-sm">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Unit Modal -->
    <div id="addUnitModal" class="modal-overlay" style="display:none;">
        <div class="modal-card" style="max-width:600px;">
            <button onclick="closeModal('addUnitModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            
            <h2 class="text-2xl font-black mb-8 tracking-tight">Register New Unit</h2>
            
            <form action="actions/unit_actions.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="property_id" value="<?php echo $propertyId; ?>">
                
                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Unit Number</label>
                        <input type="text" name="unit_number" required placeholder="E.g. A102" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Floor</label>
                        <input type="text" name="floor_number" placeholder="E.g. Ground" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Unit Type</label>
                        <select name="unit_type" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                            <option>1 Bedroom</option>
                            <option>2 Bedroom</option>
                            <option>3 Bedroom</option>
                            <option>Studio</option>
                            <option>Penthouse</option>
                            <option>Shop/Retail</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Category</label>
                        <input type="text" name="category" placeholder="E.g. Residential Lux" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Electricity Meter #</label>
                        <input type="text" name="electricity_meter" placeholder="E.g. 142XXX..." class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Water Meter #</label>
                        <input type="text" name="water_meter" placeholder="E.g. WM-001..." class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Monthly Rent (KSh)</label>
                        <input type="number" name="monthly_rent" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Deposit Amount (KSh)</label>
                        <input type="number" name="deposit_amount" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Status</label>
                    <select name="status" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                        <option value="Available">Available</option>
                        <option value="Occupied">Occupied</option>
                        <option value="Maintenance">Maintenance</option>
                    </select>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Unit Images</label>
                    <input type="file" name="unit_images[]" multiple class="w-full p-4 border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-2xl text-xs text-slate-400 font-bold hover:border-accent-green transition-all">
                </div>

                <button type="submit" class="btn-green w-full justify-center py-4 rounded-2xl shadow-xl shadow-accent-green/10 font-black">Register Unit →</button>
            </form>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="glass-card p-6 border-l-4 border-accent-green relative overflow-hidden">
            <div class="absolute -right-4 -top-4 w-24 h-24 bg-accent-green/5 rounded-full blur-2xl"></div>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Occupancy Rate</p>
            <h3 class="text-3xl font-black text-slate-900 dark:text-white"><?php echo round($occupancyRate, 1); ?>%</h3>
            <div class="w-full bg-slate-100 dark:bg-slate-800 h-1.5 rounded-full mt-3 overflow-hidden">
                <div class="bg-accent-green h-full rounded-full transition-all duration-1000" style="width: <?php echo $occupancyRate; ?>%"></div>
            </div>
        </div>
        <div class="glass-card p-6 border-l-4 border-accent-orange relative overflow-hidden">
            <div class="absolute -right-4 -top-4 w-24 h-24 bg-accent-orange/5 rounded-full blur-2xl"></div>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Rent Yield</p>
            <h3 class="text-3xl font-black text-slate-900 dark:text-white">KSh <?php echo number_format($totalRent); ?></h3>
            <p class="text-[10px] font-bold text-slate-400 mt-1 uppercase">Monthly Projected</p>
        </div>
        <div class="glass-card p-6 border-l-4 border-slate-900 dark:border-white relative overflow-hidden">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Units</p>
            <h3 class="text-3xl font-black text-slate-900 dark:text-white"><?php echo $totalUnits; ?></h3>
            <p class="text-[10px] font-bold text-slate-400 mt-1 uppercase"><?php echo $vacantUnits; ?> Vacancies Available</p>
        </div>
        <div class="glass-card p-6 border-l-4 border-blue-500 relative overflow-hidden">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Property Type</p>
            <h3 class="text-3xl font-black text-slate-900 dark:text-white"><?php echo $property['property_type']; ?></h3>
            <p class="text-[10px] font-bold text-slate-400 mt-1 uppercase"><?php echo $property['area']; ?> Sqft Footprint</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        <!-- Units Tabulation -->
        <div class="lg:col-span-8 space-y-6">
            <div class="glass-card overflow-hidden">
                <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
                    <h2 class="text-lg font-black tracking-tight">Unit Registry</h2>
                    <div class="flex gap-2">
                        <span class="px-2 py-1 bg-slate-100 dark:bg-slate-800 rounded text-[10px] font-black uppercase text-slate-500"><?php echo $occupiedUnits; ?> Occupied</span>
                        <span class="px-2 py-1 bg-slate-100 dark:bg-slate-800 rounded text-[10px] font-black uppercase text-slate-500"><?php echo $vacantUnits; ?> Vacant</span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="bg-slate-50/50 dark:bg-slate-800/30">
                            <tr>
                                <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Unit #</th>
                                <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Type & Category</th>
                                <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Rent</th>
                                <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-center">Status</th>
                                <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <?php if (empty($units)): ?>
                                <tr><td colspan="5" class="p-12 text-center text-slate-400 font-medium italic">No units registered for this property.</td></tr>
                            <?php else: ?>
                                <?php foreach ($units as $unit): ?>
                                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all group cursor-pointer"
                                    onclick="openUnitPanel('<?php echo $unit['id']; ?>')"
                                    title="Click to view unit details">
                                    <td class="p-4">
                                        <div class="flex items-center gap-3">
                                            <?php 
                                            $uImgs = json_decode($unit['images'] ?? '[]', true);
                                            $uImg = !empty($uImgs) ? $uImgs[0] : 'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?q=80&w=200';
                                            ?>
                                            <div class="w-10 h-10 rounded-lg overflow-hidden border border-slate-100 dark:border-slate-800">
                                                <img src="<?php echo htmlspecialchars($uImg); ?>" class="w-full h-full object-cover">
                                            </div>
                                            <div>
                                                <p class="font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars((string)($unit['unit_number'] ?? '')); ?></p>
                                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-tighter">Floor <?php echo (string)(($unit['floor_number'] ?? '') ?: 'G'); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="p-4">
                                         <p class="text-xs font-bold text-slate-600 dark:text-slate-400"><?php echo htmlspecialchars((string)($unit['unit_type'] ?? '')); ?></p>
                                         <div class="flex gap-2 items-center mt-1">
                                             <span class="text-[8px] font-black uppercase px-1.5 py-0.5 bg-blue-500/10 text-blue-500 rounded border border-blue-500/10">⚡ <?php echo htmlspecialchars(($unit['electricity_meter'] ?? '') ?: 'No Meter'); ?></span>
                                             <span class="text-[8px] font-black uppercase px-1.5 py-0.5 bg-cyan-500/10 text-cyan-500 rounded border border-cyan-500/10">💧 <?php echo htmlspecialchars(($unit['water_meter'] ?? '') ?: 'No Meter'); ?></span>
                                         </div>
                                     </td>
                                    <td class="p-4 text-right font-black text-sm">
                                        KSh <?php echo number_format($unit['monthly_rent']); ?>
                                    </td>
                                    <td class="p-4 text-center">
                                        <span class="px-2.5 py-1 <?php echo $unit['status'] === 'Occupied' ? 'bg-accent-green/10 text-accent-green' : ($unit['status'] === 'Maintenance' ? 'bg-slate-100 text-slate-500' : 'bg-accent-orange/10 text-accent-orange'); ?> rounded-full text-[9px] font-black uppercase tracking-widest border <?php echo $unit['status'] === 'Occupied' ? 'border-accent-green/20' : ($unit['status'] === 'Maintenance' ? 'border-slate-200' : 'border-accent-orange/20'); ?>">
                                            <?php echo $unit['status']; ?>
                                        </span>
                                    </td>
                                    <td class="p-4 text-right" onclick="event.stopPropagation()">
                                        <div class="relative inline-block unit-menu-wrap">
                                            <button onclick="toggleUnitMenu(this)"
                                                class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-all">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="1" fill="currentColor"/><circle cx="12" cy="12" r="1" fill="currentColor"/><circle cx="12" cy="19" r="1" fill="currentColor"/></svg>
                                            </button>
                                            <div class="unit-action-menu hidden">
                                                <button onclick="openUnitPanel('<?php echo $unit['id']; ?>')" class="unit-action-item">
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                                    View
                                                </button>
                                                <?php if ($role === 'admin' || $role === 'staff'): ?>
                                                <button onclick="openEditUnitModal(<?php echo json_encode($unit); ?>)" class="unit-action-item">
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                                                    Edit
                                                </button>
                                                <button onclick="confirmDeleteUnit('<?php echo $unit['id']; ?>', '<?php echo htmlspecialchars(addslashes($unit['unit_number'])); ?>')" class="unit-action-item text-red-500 hover:bg-red-50 dark:hover:bg-red-900/10">
                                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                                                    Delete
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="glass-card p-6 space-y-4">
                <h3 class="text-lg font-black tracking-tight">Property Description</h3>
                <div class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed font-medium">
                    <?php echo nl2br(htmlspecialchars($property['description'] ?: 'No description provided for this property.')); ?>
                </div>
            </div>
        </div>

        <!-- Details Sidebar -->
        <div class="lg:col-span-4 space-y-8">
            <!-- Landlord Contact -->
            <div class="glass-card p-6 space-y-6">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 rounded-2xl bg-linear-to-br from-accent-green to-emerald-600 flex items-center justify-center text-white font-black text-xl shadow-lg ring-4 ring-accent-green/10">
                        <?php echo substr($property['landlord_name'] ?: 'U', 0, 1); ?>
                    </div>
                    <div>
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Owned by</p>
                        <h4 class="text-lg font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($property['landlord_name'] ?? 'Unassigned'); ?></h4>
                    </div>
                </div>
                
                <?php if ($property['landlord_name']): ?>
                <div class="space-y-3">
                    <div class="flex items-center gap-3 p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                        <div class="text-accent-orange"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg></div>
                        <p class="text-sm font-bold tracking-tight text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($property['landlord_phone'] ?: 'N/A'); ?></p>
                    </div>
                    <div class="flex items-center gap-3 p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                        <div class="text-accent-orange"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg></div>
                        <p class="text-sm font-bold tracking-tight text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($property['landlord_email'] ?: 'N/A'); ?></p>
                    </div>
                </div>
                <!-- Contact Button -->
                <a href="mailto:<?php echo $property['landlord_email'] ?: 'admin@primelink.com'; ?>" class="block w-full py-4 text-center bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-black text-xs uppercase tracking-widest hover:translate-y-[-2px] transition-all shadow-xl">Send Inquiry</a>
                <?php else: ?>
                <div class="p-4 bg-orange-500/5 border border-dashed border-orange-500/20 rounded-xl text-center">
                    <p class="text-[10px] font-bold text-orange-500 uppercase tracking-widest mb-1">Management Required</p>
                    <p class="text-xs text-slate-500 font-medium leading-relaxed">This property has no assigned landlord and is managed by PrimeLink directly.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Utility Rates -->
            <div class="glass-card p-6 space-y-4">
                <h3 class="text-lg font-black tracking-tight">Utility Rates</h3>
                <div class="space-y-3">
                    <div class="flex justify-between items-center p-3 bg-blue-500/5 rounded-xl border border-blue-500/10">
                        <div class="flex items-center gap-2">
                            <span class="text-blue-500">💧</span>
                            <p class="text-xs font-bold text-slate-600 dark:text-slate-400">Water Rate</p>
                        </div>
                        <p class="text-sm font-black text-slate-900 dark:text-white">KSh <?php echo number_format($property['water_rate'] ?? 0, 2); ?>/Unit</p>
                    </div>
                    <div class="flex justify-between items-center p-3 bg-orange-500/5 rounded-xl border border-orange-500/10">
                        <div class="flex items-center gap-2">
                            <span class="text-orange-500">🗑️</span>
                            <p class="text-xs font-bold text-slate-600 dark:text-slate-400">Garbage Fee</p>
                        </div>
                        <p class="text-sm font-black text-slate-900 dark:text-white">KSh <?php echo number_format($property['garbage_fee'] ?? 0); ?>/mo</p>
                    </div>
                </div>
            </div>

            <!-- Gallery Overlay -->
            <div class="glass-card p-6 space-y-4">
                <h3 class="text-lg font-black tracking-tight">Media Presence</h3>
                <div class="grid grid-cols-2 gap-4">
                    <?php 
                    $imgs = json_decode($property['images'], true);
                    if (!empty($imgs)):
                        foreach (array_slice($imgs, 0, 4) as $img): ?>
                            <div class="h-24 rounded-xl overflow-hidden shadow-inner">
                                <img src="<?php echo htmlspecialchars($img); ?>" class="w-full h-full object-cover">
                            </div>
                        <?php endforeach; 
                    else: ?>
                        <div class="col-span-2 h-32 bg-slate-100 dark:bg-slate-800/50 rounded-xl flex items-center justify-center border border-dashed border-slate-200 dark:border-slate-800 text-slate-400 italic text-xs font-medium">No additional photos</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

    <!-- Unit Details Slide Panel -->
    <div id="unitPanel" class="fixed inset-0 z-50 flex justify-end" style="display:none;">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeUnitPanel()"></div>
        <div class="relative w-full max-w-md bg-white dark:bg-slate-900 h-full overflow-y-auto shadow-2xl flex flex-col border-l border-slate-100 dark:border-slate-800">

            <!-- Panel Header -->
            <div class="flex items-center justify-between px-6 py-5 border-b border-slate-100 dark:border-slate-800 shrink-0">
                <div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Unit Details</p>
                    <h2 class="text-2xl font-black text-slate-900 dark:text-white" id="panel_unit_title">Unit —</h2>
                </div>
                <button onclick="closeUnitPanel()" class="w-9 h-9 flex items-center justify-center rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-900 dark:hover:text-white transition-all">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>

            <div class="p-6 space-y-6 flex-1">
                <!-- Status + Meta -->
                <div class="flex flex-wrap items-center gap-2">
                    <span id="panel_status_badge" class="px-3 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest"></span>
                    <span id="panel_unit_type" class="text-xs font-bold text-slate-500 dark:text-slate-400"></span>
                    <span class="text-slate-300 dark:text-slate-600">·</span>
                    <span id="panel_floor" class="text-xs font-bold text-slate-500 dark:text-slate-400"></span>
                </div>

                <!-- Rent / Deposit -->
                <div class="grid grid-cols-2 gap-3">
                    <div class="p-4 bg-slate-50 dark:bg-slate-800/50 rounded-2xl">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Monthly Rent</p>
                        <p class="text-xl font-black text-slate-900 dark:text-white" id="panel_rent"></p>
                    </div>
                    <div class="p-4 bg-slate-50 dark:bg-slate-800/50 rounded-2xl">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Deposit</p>
                        <p class="text-xl font-black text-slate-900 dark:text-white" id="panel_deposit"></p>
                    </div>
                </div>

                <!-- Meters -->
                <div class="grid grid-cols-2 gap-3">
                    <div class="flex items-center gap-2 p-3 bg-blue-500/5 border border-blue-500/10 rounded-xl">
                        <span class="text-sm">⚡</span>
                        <div class="min-w-0">
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Electricity</p>
                            <p class="text-xs font-black text-slate-700 dark:text-slate-300 truncate" id="panel_elec"></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 p-3 bg-cyan-500/5 border border-cyan-500/10 rounded-xl">
                        <span class="text-sm">💧</span>
                        <div class="min-w-0">
                            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Water</p>
                            <p class="text-xs font-black text-slate-700 dark:text-slate-300 truncate" id="panel_water"></p>
                        </div>
                    </div>
                </div>

                <!-- Tenant (occupied) -->
                <div id="panel_tenant_section" class="hidden space-y-4">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Current Tenant</p>
                    <div class="p-5 rounded-2xl border border-slate-100 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/30 space-y-4">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-green-500 to-emerald-600 flex items-center justify-center text-white font-black text-base shrink-0" id="panel_tenant_avatar"></div>
                            <div class="min-w-0">
                                <p class="font-black text-slate-900 dark:text-white truncate" id="panel_tenant_name"></p>
                                <p class="text-[10px] text-slate-400 font-medium truncate" id="panel_tenant_email"></p>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Phone</p>
                                <p class="text-xs font-bold text-slate-700 dark:text-slate-300" id="panel_tenant_phone"></p>
                            </div>
                            <div>
                                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Lease Start</p>
                                <p class="text-xs font-bold text-slate-700 dark:text-slate-300" id="panel_lease_start"></p>
                            </div>
                            <div>
                                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Lease End</p>
                                <p class="text-xs font-bold text-slate-700 dark:text-slate-300" id="panel_lease_end"></p>
                            </div>
                            <div>
                                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Outstanding Balance</p>
                                <p class="text-xs font-black" id="panel_balance"></p>
                            </div>
                        </div>
                        <div class="flex gap-2 pt-1">
                            <a id="panel_view_tenant" href="#" class="flex-1 py-2.5 text-center text-[11px] font-black text-white bg-slate-900 dark:bg-slate-100 dark:text-slate-900 rounded-xl hover:opacity-80 transition-opacity">
                                View Profile
                            </a>
                            <a id="panel_view_invoices" href="#" class="flex-1 py-2.5 text-center text-[11px] font-black border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                                Invoices
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Vacant state -->
                <div id="panel_vacant_section" class="hidden">
                    <div class="p-6 border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-2xl text-center">
                        <p class="text-[10px] font-black text-orange-400 uppercase tracking-widest mb-1">Vacant Unit</p>
                        <p class="text-xs text-slate-500 font-medium">No active tenant assigned to this unit.</p>
                        <a href="tenants.php" class="inline-block mt-3 px-4 py-2 bg-accent-green text-slate-900 rounded-xl text-[10px] font-black uppercase tracking-widest hover:opacity-80 transition-opacity">
                            Register Tenant
                        </a>
                    </div>
                </div>
            </div>

            <!-- Panel Footer (admin/staff) -->
            <?php if ($role === 'admin' || $role === 'staff'): ?>
            <div class="px-6 py-4 border-t border-slate-100 dark:border-slate-800 flex gap-3 shrink-0">
                <button id="panel_edit_btn" class="flex-1 py-3 text-xs font-black border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                    Edit Unit
                </button>
                <button id="panel_delete_btn" class="px-5 py-3 text-xs font-black bg-red-50 dark:bg-red-900/20 text-red-500 rounded-xl hover:bg-red-100 dark:hover:bg-red-900/30 transition-colors">
                    Delete
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Hidden delete form -->
    <form id="deleteUnitForm" action="actions/unit_actions.php" method="POST" style="display:none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="unit_id" id="deleteUnit_id">
        <input type="hidden" name="property_id" value="<?php echo htmlspecialchars($propertyId); ?>">
    </form>

    <!-- Edit Unit Modal -->
    <div id="editUnitModal" class="modal-overlay" style="display:none;">
        <div class="modal-card" style="max-width:600px;">
            <button onclick="closeModal('editUnitModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            
            <h2 class="text-2xl font-black mb-8 tracking-tight">Edit Unit Details</h2>
            
            <form action="actions/unit_actions.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="unit_id" id="edit_unit_id">
                <input type="hidden" name="property_id" value="<?php echo $propertyId; ?>">
                
                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Unit Number</label>
                        <input type="text" name="unit_number" id="edit_unit_number" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Floor</label>
                        <input type="text" name="floor_number" id="edit_floor_number" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Unit Type</label>
                        <select name="unit_type" id="edit_unit_type" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                            <option>1 Bedroom</option>
                            <option>2 Bedroom</option>
                            <option>3 Bedroom</option>
                            <option>Studio</option>
                            <option>Penthouse</option>
                            <option>Shop/Retail</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Category</label>
                        <input type="text" name="category" id="edit_category" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Electricity Meter #</label>
                        <input type="text" name="electricity_meter" id="edit_electricity_meter" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Water Meter #</label>
                        <input type="text" name="water_meter" id="edit_water_meter" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Monthly Rent (KSh)</label>
                        <input type="number" name="monthly_rent" id="edit_rent_amount" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Deposit Amount (KSh)</label>
                        <input type="number" name="deposit_amount" id="edit_deposit_amount" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Status</label>
                    <select name="status" id="edit_status" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                        <option value="Available">Available</option>
                        <option value="Occupied">Occupied</option>
                        <option value="Maintenance">Maintenance</option>
                    </select>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Add More Images</label>
                    <input type="file" name="unit_images[]" multiple class="w-full p-4 border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-2xl text-xs text-slate-400 font-bold hover:border-accent-green transition-all">
                </div>

                <button type="submit" class="btn-green w-full justify-center py-4 rounded-2xl shadow-xl shadow-accent-green/10 font-black">Update Unit Details →</button>
            </form>
        </div>
    </div>

    <style>
    /* Unit 3-dot action menu */
    .unit-menu-wrap { position: relative; }
    .unit-action-menu {
        position: absolute; right: 0; top: calc(100% + 4px);
        width: 160px; padding: 6px;
        background: #fff; border: 1px solid #e2e8f0;
        border-radius: 14px; box-shadow: 0 12px 40px rgba(0,0,0,.12);
        z-index: 200;
    }
    html.dark .unit-action-menu { background: #0f172a; border-color: #1e293b; box-shadow: 0 12px 40px rgba(0,0,0,.4); }
    .unit-action-item {
        display: flex; align-items: center; gap: 8px;
        width: 100%; padding: 8px 12px;
        font-size: 12px; font-weight: 700; color: #475569;
        border-radius: 9px; background: none; border: none;
        cursor: pointer; text-align: left; transition: background .12s;
        text-decoration: none;
    }
    html.dark .unit-action-item { color: #94a3b8; }
    .unit-action-item:hover { background: #f8fafc; color: #0f172a; }
    html.dark .unit-action-item:hover { background: #1e293b; color: #f8fafc; }
    </style>

    <script>
    /* ── Edit unit modal ─────────────────────────────────────────── */
    function openEditUnitModal(unit) {
        document.getElementById('edit_unit_id').value             = unit.id;
        document.getElementById('edit_unit_number').value         = unit.unit_number;
        document.getElementById('edit_floor_number').value        = unit.floor_number;
        document.getElementById('edit_unit_type').value           = unit.unit_type;
        document.getElementById('edit_category').value            = unit.category || '';
        document.getElementById('edit_electricity_meter').value   = unit.electricity_meter || '';
        document.getElementById('edit_water_meter').value         = unit.water_meter || '';
        document.getElementById('edit_rent_amount').value         = unit.monthly_rent;
        document.getElementById('edit_deposit_amount').value      = unit.deposit_amount;
        document.getElementById('edit_status').value              = unit.status;
        openModal('editUnitModal');
    }

    /* ── Unit panel data (keyed by id) ───────────────────────────── */
    const unitsData = <?php echo json_encode($unitPanelData); ?>;

    function openUnitPanel(unitId) {
        const u = unitsData[unitId];
        if (!u) return;

        document.getElementById('panel_unit_title').textContent = 'Unit ' + u.unit_number;
        document.getElementById('panel_unit_type').textContent  = u.unit_type || '—';
        document.getElementById('panel_floor').textContent      = 'Floor ' + (u.floor_number || 'G');
        document.getElementById('panel_rent').textContent       = 'KSh ' + parseFloat(u.monthly_rent).toLocaleString();
        document.getElementById('panel_deposit').textContent    = 'KSh ' + parseFloat(u.deposit_amount).toLocaleString();
        document.getElementById('panel_elec').textContent       = u.electricity_meter || '—';
        document.getElementById('panel_water').textContent      = u.water_meter || '—';

        // Status badge
        const sb = document.getElementById('panel_status_badge');
        const sc = { Occupied: 'bg-green-500/10 text-green-500 border border-green-500/20', Available: 'bg-orange-500/10 text-orange-500 border border-orange-500/20', Maintenance: 'bg-slate-200 dark:bg-slate-700 text-slate-500 border border-slate-300 dark:border-slate-600' };
        sb.className = 'px-3 py-1.5 rounded-full text-[10px] font-black uppercase tracking-widest ' + (sc[u.status] || '');
        sb.textContent = u.status;

        // Tenant vs vacant
        const ts = document.getElementById('panel_tenant_section');
        const vs = document.getElementById('panel_vacant_section');
        if (u.tenant_id) {
            ts.classList.remove('hidden');
            vs.classList.add('hidden');
            document.getElementById('panel_tenant_avatar').textContent  = (u.tenant_name || '?')[0].toUpperCase();
            document.getElementById('panel_tenant_name').textContent    = u.tenant_name || '—';
            document.getElementById('panel_tenant_email').textContent   = u.tenant_email || '—';
            document.getElementById('panel_tenant_phone').textContent   = u.tenant_phone || '—';

            const fmtDate = d => d ? new Date(d).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}) : '—';
            document.getElementById('panel_lease_start').textContent = fmtDate(u.lease_start);
            document.getElementById('panel_lease_end').textContent   = u.lease_end ? fmtDate(u.lease_end) : 'Open-ended';

            const balEl = document.getElementById('panel_balance');
            balEl.textContent = 'KSh ' + parseFloat(u.outstanding_balance).toLocaleString();
            balEl.className   = 'text-xs font-black ' + (u.outstanding_balance > 0 ? 'text-red-500' : 'text-green-500');

            document.getElementById('panel_view_tenant').href   = 'tenant_details.php?id=' + u.tenant_id;
            document.getElementById('panel_view_invoices').href = 'tenant_details.php?id=' + u.tenant_id + '&tab=invoices';
        } else {
            ts.classList.add('hidden');
            vs.classList.remove('hidden');
        }

        // Footer edit/delete buttons
        const editBtn   = document.getElementById('panel_edit_btn');
        const deleteBtn = document.getElementById('panel_delete_btn');
        if (editBtn)   editBtn.onclick   = () => { closeUnitPanel(); openEditUnitModal(u); };
        if (deleteBtn) deleteBtn.onclick = () => confirmDeleteUnit(u.id, u.unit_number);

        document.getElementById('unitPanel').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeUnitPanel() {
        document.getElementById('unitPanel').style.display = 'none';
        document.body.style.overflow = '';
    }

    /* ── 3-dot unit menu ─────────────────────────────────────────── */
    function toggleUnitMenu(btn) {
        const menu   = btn.nextElementSibling;
        const isOpen = !menu.classList.contains('hidden');
        document.querySelectorAll('.unit-action-menu').forEach(m => m.classList.add('hidden'));
        if (!isOpen) menu.classList.remove('hidden');
    }
    document.addEventListener('click', e => {
        if (!e.target.closest('.unit-menu-wrap'))
            document.querySelectorAll('.unit-action-menu').forEach(m => m.classList.add('hidden'));
    });

    /* ── Delete unit ─────────────────────────────────────────────── */
    function confirmDeleteUnit(id, number) {
        if (!confirm(`Delete Unit ${number}?\n\nThis cannot be undone. Units with active tenants or lease history cannot be deleted.`)) return;
        document.getElementById('deleteUnit_id').value = id;
        document.getElementById('deleteUnitForm').submit();
    }

    /* Close panel on Escape key */
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeUnitPanel(); });
    </script>
<?php include __DIR__ . '/includes/footer.php'; ?>
