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
$stmt->execute([$propertyId]);
$property = $stmt->fetch();

if (!$property) {
    die("Property not found.");
}

// Fetch units
$stmt = $pdo->prepare("SELECT * FROM units WHERE property_id = ? ORDER BY unit_number ASC");
$stmt->execute([$propertyId]);
$units = $stmt->fetchAll();

// Calculate stats
$totalUnits = count($units);
$occupiedUnits = 0;
$vacantUnits = 0;
$totalRent = 0;

foreach ($units as $u) {
    if ($u['status'] === 'Occupied') {
        $occupiedUnits++;
        $totalRent += $u['rent_amount'];
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
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Monthly Rent (KSh)</label>
                        <input type="number" name="rent_amount" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Status</label>
                        <select name="status" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                            <option value="Available">Available</option>
                            <option value="Occupied">Occupied</option>
                            <option value="Maintenance">Maintenance</option>
                        </select>
                    </div>
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
                                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all group">
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
                                        <p class="text-[9px] font-black uppercase text-accent-green/70"><?php echo htmlspecialchars((string)(($unit['category'] ?? '') ?: 'Uncategorized')); ?></p>
                                    </td>
                                    <td class="p-4 text-right font-black text-sm">
                                        KSh <?php echo number_format($unit['rent_amount']); ?>
                                    </td>
                                    <td class="p-4 text-center">
                                        <span class="px-2.5 py-1 <?php echo $unit['status'] === 'Occupied' ? 'bg-accent-green/10 text-accent-green' : ($unit['status'] === 'Maintenance' ? 'bg-slate-100 text-slate-500' : 'bg-accent-orange/10 text-accent-orange'); ?> rounded-full text-[9px] font-black uppercase tracking-widest border <?php echo $unit['status'] === 'Occupied' ? 'border-accent-green/20' : ($unit['status'] === 'Maintenance' ? 'border-slate-200' : 'border-accent-orange/20'); ?>">
                                            <?php echo $unit['status']; ?>
                                        </span>
                                    </td>
                                    <td class="p-4 text-right">
                                        <div class="flex justify-end gap-2">
                                            <?php if ($role === 'admin' || $role === 'staff'): ?>
                                            <button onclick='openEditUnitModal(<?php echo json_encode($unit); ?>)' class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 hover:text-accent-green transition-all" title="Edit Unit">
                                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                                            </button>
                                            <?php endif; ?>
                                            <button class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg text-slate-400 hover:text-accent-green transition-all">
                                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/></svg>
                                            </button>
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
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Monthly Rent (KSh)</label>
                        <input type="number" name="rent_amount" id="edit_rent_amount" required class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Status</label>
                        <select name="status" id="edit_status" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                            <option value="Available">Available</option>
                            <option value="Occupied">Occupied</option>
                            <option value="Maintenance">Maintenance</option>
                        </select>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Add More Images</label>
                    <input type="file" name="unit_images[]" multiple class="w-full p-4 border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-2xl text-xs text-slate-400 font-bold hover:border-accent-green transition-all">
                </div>

                <button type="submit" class="btn-green w-full justify-center py-4 rounded-2xl shadow-xl shadow-accent-green/10 font-black">Update Unit Details →</button>
            </form>
        </div>
    </div>

    <script>
    function openEditUnitModal(unit) {
        document.getElementById('edit_unit_id').value = unit.id;
        document.getElementById('edit_unit_number').value = unit.unit_number;
        document.getElementById('edit_floor_number').value = unit.floor_number;
        document.getElementById('edit_unit_type').value = unit.unit_type;
        document.getElementById('edit_category').value = unit.category || '';
        document.getElementById('edit_rent_amount').value = unit.rent_amount;
        document.getElementById('edit_status').value = unit.status;
        openModal('editUnitModal');
    }
    </script>
<?php include __DIR__ . '/includes/footer.php'; ?>
