<?php
/**
 * Property Management Page
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user    = getCurrentUser($pdo);
$role    = $_SESSION['role'] ?? 'tenant';
$pageTitle = "Properties";

// Landlords only see their own properties
if ($role === 'landlord') {
    $landlordId = getLandlordId($pdo);
    $stmt = $pdo->prepare("
        SELECT p.*, l.full_name as landlord_name,
               COUNT(u.id) as total_units,
               SUM(CASE WHEN u.status='Occupied' THEN 1 ELSE 0 END) as occupied_units,
               SUM(CASE WHEN u.status='Available' THEN 1 ELSE 0 END) as vacant_units
        FROM properties p
        LEFT JOIN landlords l ON p.landlord_id = l.id
        LEFT JOIN units u ON u.property_id = p.id
        WHERE p.landlord_id = ?
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$landlordId]);
} else {
    requireRole(['staff']);
    $landlordId = null;
    $stmt = $pdo->query("
        SELECT p.*, l.full_name as landlord_name,
               COUNT(u.id) as total_units,
               SUM(CASE WHEN u.status='Occupied' THEN 1 ELSE 0 END) as occupied_units,
               SUM(CASE WHEN u.status='Available' THEN 1 ELSE 0 END) as vacant_units
        FROM properties p
        LEFT JOIN landlords l ON p.landlord_id = l.id
        LEFT JOIN units u ON u.property_id = p.id
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ");
}
$properties = $stmt->fetchAll();

// Fetch landlords for modals
$lands = $pdo->query("SELECT id, full_name FROM landlords ORDER BY full_name")->fetchAll();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">
    <?php if (isset($_GET['success'])): ?>
    <div class="p-4 bg-green-500/10 border border-green-500/20 text-green-500 rounded-xl font-bold text-sm animate-in fade-in slide-in-from-top-4">
        Property <?php echo htmlspecialchars($_GET['success']); ?> successfully!
    </div>
    <?php endif; ?>

    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight"><?php echo $role === 'landlord' ? 'My Properties' : 'Property Management'; ?></h1>
            <p class="text-slate-500 font-medium"><?php echo $role === 'landlord' ? 'Your assigned properties and unit status.' : 'View and manage your real estate portfolio.'; ?></p>
        </div>
        <?php if ($role !== 'landlord'): ?>
        <button onclick="openModal('addPropertyModal')" class="btn-primary">
            + New Property
        </button>
        <?php endif; ?>
    </div>

    <!-- Properties Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-8">
        <?php if (empty($properties)): ?>
            <div class="col-span-full py-20 text-center glass-card">
                <p class="text-slate-400 font-medium">No properties found. Start by adding a new one.</p>
            </div>
        <?php else: ?>
            <?php foreach ($properties as $prop): ?>
            <div class="glass-card group overflow-hidden hover:ring-2 hover:ring-accent-green/30 transition-all duration-300">
                <a href="property_details.php?id=<?php echo $prop['id']; ?>" class="block relative h-48 overflow-hidden">
                    <?php 
                    $imgs = json_decode($prop['images'], true);
                    $imgUrl = !empty($imgs) ? $imgs[0] : 'https://images.unsplash.com/photo-1613490493576-7fde63acd811?q=80&w=800';
                    ?>
                    <img src="<?php echo htmlspecialchars((string)($imgUrl ?? '')); ?>" alt="Property" class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700">
                    <div class="absolute inset-0 bg-black/20 group-hover:bg-black/0 transition-colors"></div>
                    <div class="absolute top-4 right-4">
                        <span class="px-3 py-1 bg-white/90 dark:bg-slate-900/90 backdrop-blur-md rounded-full text-[10px] font-black uppercase tracking-widest text-accent-green shadow-lg">
                            <?php echo htmlspecialchars((string)($prop['status'] ?? 'Active')); ?>
                        </span>
                    </div>
                </a>
                <div class="p-6 space-y-4">
                    <a href="property_details.php?id=<?php echo $prop['id']; ?>" class="block group/link">
                        <h3 class="text-lg font-black text-slate-900 dark:text-white group-hover/link:text-accent-green transition-colors"><?php echo htmlspecialchars((string)($prop['title'] ?? '')); ?></h3>
                        <p class="text-xs text-slate-500 flex items-center gap-1 mt-0.5">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                            <?php echo htmlspecialchars((string)($prop['location'] ?? '')); ?>
                        </p>
                    </a>
                    
                    <div class="grid grid-cols-3 gap-2">
                        <div class="bg-slate-50 dark:bg-slate-800 p-2 rounded-lg text-center">
                            <p class="text-[8px] font-black text-slate-400 uppercase">Type</p>
                            <p class="text-[10px] font-bold"><?php echo htmlspecialchars((string)($prop['property_type'] ?? '')); ?></p>
                        </div>
                        <div class="bg-slate-50 dark:bg-slate-800 p-2 rounded-lg text-center">
                            <p class="text-[8px] font-black text-slate-400 uppercase">Code</p>
                            <p class="text-[10px] font-bold truncate px-1"><?php echo htmlspecialchars((string)($prop['property_code'] ?? 'N/A')); ?></p>
                        </div>
                        <div class="bg-slate-50 dark:bg-slate-800 p-2 rounded-lg text-center">
                            <p class="text-[8px] font-black text-slate-400 uppercase">Area</p>
                            <p class="text-[10px] font-bold"><?php echo (int)$prop['area']; ?> Sqm</p>
                        </div>
                    </div>

                    <div class="flex justify-between items-center pt-2">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-full bg-accent-green flex items-center justify-center text-xs font-black">
                                <?php echo substr($prop['landlord_name'] ?? 'U', 0, 1); ?>
                            </div>
                            <p class="text-[10px] font-bold text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($prop['landlord_name'] ?? 'Unassigned'); ?></p>
                        </div>
                        <div class="flex items-center gap-1">
                            <?php if ($role !== 'landlord'): ?>
                            <button onclick='openEditPropertyModal(<?php echo json_encode($prop); ?>)' class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition-colors text-slate-400 hover:text-blue-500" title="Edit">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                            </button>
                            <button onclick="confirmDeleteProperty('<?php echo $prop['id']; ?>', '<?php echo addslashes($prop['title']); ?>')" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition-colors text-slate-400 hover:text-red-500" title="Delete">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                            </button>
                            <?php endif; ?>
                            <a href="property_details.php?id=<?php echo $prop['id']; ?>" class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition-colors text-slate-400 hover:text-accent-green" title="Details">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Add Property Modal -->
<div class="modal-overlay" id="addPropertyModal" style="display:none;">
    <div class="modal-card">
        <button onclick="closeModal('addPropertyModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-6">Add New Property</h2>
        <form action="actions/property_actions.php" method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="action" value="create">
            <div class="grid grid-cols-2 gap-4">
                <div><label class="form-label">Title</label><input type="text" name="title" required class="form-input" placeholder="e.g. Primelink Plaza"></div>
                <div><label class="form-label">Property Code</label><input type="text" name="property_code" required class="form-input" placeholder="e.g. PP001"></div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="form-label">Location</label><input type="text" name="location" required class="form-input" placeholder="e.g. Nairobi, CBD"></div>
                <div><label class="form-label">Property Type</label>
                    <select name="property_type" class="form-input">
                        <option value="Apartment">Apartment</option>
                        <option value="Villa">Villa</option>
                        <option value="Office">Office</option>
                        <option value="Shop">Shop</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="form-label">Landlord</label>
                    <select name="landlord_id" class="form-input">
                        <option value="">Select Landlord</option>
                        <?php foreach ($lands as $l): ?>
                        <option value="<?php echo $l['id']; ?>"><?php echo htmlspecialchars($l['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label class="form-label">Total Area (Sqm)</label><input type="number" name="area" class="form-input"></div>
            </div>
            <div><label class="form-label">Description</label><textarea name="description" rows="3" class="form-input"></textarea></div>
            <div><label class="form-label">Images</label><input type="file" name="property_images[]" multiple class="form-input" accept="image/*"></div>
            <button type="submit" class="w-full py-4 bg-accent-green text-slate-900 font-bold rounded-xl hover:opacity-90 transition-all">Save Property →</button>
        </form>
    </div>
</div>

<!-- Edit Property Modal -->
<div class="modal-overlay" id="editPropertyModal" style="display:none;">
    <div class="modal-card">
        <button onclick="closeModal('editPropertyModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-1">Edit Property</h2>
        <p class="text-slate-400 text-xs font-bold uppercase tracking-widest mb-6">Update core records</p>
        
        <form action="actions/property_actions.php" method="POST" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" id="edit_prop_id">
            <div class="grid grid-cols-2 gap-4">
                <div><label class="form-label">Title</label><input type="text" name="title" id="edit_prop_title" required class="form-input"></div>
                <div><label class="form-label">Property Code</label><input type="text" name="property_code" id="edit_prop_code" required class="form-input"></div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="form-label">Location</label><input type="text" name="location" id="edit_prop_location" required class="form-input"></div>
                <div><label class="form-label">Property Type</label>
                    <select name="property_type" id="edit_prop_type" class="form-input">
                        <option value="Apartment">Apartment</option>
                        <option value="Villa">Villa</option>
                        <option value="Office">Office</option>
                        <option value="Shop">Shop</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="form-label">Landlord</label>
                    <select name="landlord_id" id="edit_prop_landlord" class="form-input">
                        <option value="">Select Landlord</option>
                        <?php foreach ($lands as $l): ?>
                        <option value="<?php echo $l['id']; ?>"><?php echo htmlspecialchars($l['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label class="form-label">Total Area (Sqm)</label><input type="number" name="area" id="edit_prop_area" class="form-input"></div>
            </div>
            <div>
                <label class="form-label">Status</label>
                <select name="status" id="edit_prop_status" class="form-input">
                    <option value="Available">Available</option>
                    <option value="Inactive">Inactive</option>
                    <option value="Under Maintenance">Under Maintenance</option>
                    <option value="Sold">Sold</option>
                </select>
            </div>
            <div><label class="form-label">Description</label><textarea name="description" id="edit_prop_description" rows="3" class="form-input"></textarea></div>
            <div><label class="form-label">Add More Images</label><input type="file" name="property_images[]" multiple class="form-input" accept="image/*"></div>
            <button type="submit" class="w-full py-4 bg-slate-900 text-white font-black rounded-xl hover:bg-slate-800 transition-colors">Apply Changes →</button>
        </form>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal-overlay" id="deletePropertyModal" style="display:none;">
    <div class="modal-card max-w-md">
        <h2 class="text-2xl font-black mb-2 text-red-600">Delete Property</h2>
        <p class="text-slate-600 mb-6 font-medium">Are you sure you want to delete <span id="delete_prop_name" class="font-bold text-slate-900"></span>? This will also remove all associated units and leases.</p>
        <form action="actions/property_actions.php" method="POST" class="flex gap-4">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delete_prop_id">
            <button type="button" onclick="closeModal('deletePropertyModal')" class="flex-1 py-4 bg-slate-100 text-slate-600 font-bold rounded-xl transition-all hover:bg-slate-200">Cancel</button>
            <button type="submit" class="flex-1 py-4 bg-red-600 text-white font-bold rounded-xl shadow-lg shadow-red-500/20 hover:bg-red-700 transition-all">Delete Forever</button>
        </form>
    </div>
</div>

<script>
function openEditPropertyModal(prop) {
    document.getElementById('edit_prop_id').value = prop.id;
    document.getElementById('edit_prop_title').value = prop.title;
    document.getElementById('edit_prop_code').value = prop.property_code || '';
    document.getElementById('edit_prop_location').value = prop.location;
    document.getElementById('edit_prop_type').value = prop.property_type;
    document.getElementById('edit_prop_landlord').value = prop.landlord_id || '';
    document.getElementById('edit_prop_area').value = prop.area;
    document.getElementById('edit_prop_status').value = prop.status;
    document.getElementById('edit_prop_description').value = prop.description;
    openModal('editPropertyModal');
}

function confirmDeleteProperty(id, name) {
    document.getElementById('delete_prop_id').value = id;
    document.getElementById('delete_prop_name').innerText = name;
    openModal('deletePropertyModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
