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

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">
    <?php if (isset($_GET['success'])): ?>
    <div class="p-4 bg-green-500/10 border border-green-500/20 text-green-500 rounded-xl font-bold text-sm animate-in fade-in slide-in-from-top-4">
        Property <?php echo $_GET['success'] == 'created' ? 'created' : 'deleted'; ?> successfully!
    </div>
    <?php endif; ?>

    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight"><?php echo $role === 'landlord' ? 'My Properties' : 'Property Management'; ?></h1>
            <p class="text-slate-500 font-medium"><?php echo $role === 'landlord' ? 'Your assigned properties and unit status.' : 'View and manage your real estate portfolio.'; ?></p>
        </div>
        <?php if ($role !== 'landlord'): ?>
        <button onclick="openModal('newPropertyModal')" class="btn-primary">
            + New Property
        </button>
        <?php endif; ?>
    </div>

    <!-- New Property Modal -->
    <div id="newPropertyModal" class="modal-overlay" style="display:none;">
        <div class="modal-card" style="max-width:680px;">
            <button onclick="closeModal('newPropertyModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
            </button>
            
            <h2 class="text-2xl font-black mb-8">Add New Property</h2>
            
            <form action="actions/property_actions.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                <input type="hidden" name="action" value="create">
                
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Property Title</label>
                    <input type="text" name="title" required placeholder="E.g. Primelink Heights" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                </div>

                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Location</label>
                        <input type="text" name="location" required placeholder="E.g. Nairobi, Kilimani" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Property Code</label>
                        <input type="text" name="property_code" placeholder="E.g. PL-001" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Description</label>
                    <textarea name="description" rows="3" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none resize-none"></textarea>
                </div>

                <div class="grid grid-cols-2 gap-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Type</label>
                        <select name="property_type" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                            <option>Apartment</option>
                            <option>Villa</option>
                            <option>Office</option>
                            <option>Commercial</option>
                            <option>Shop</option>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Area (Sqm)</label>
                        <input type="number" name="area" placeholder="E.g. 150" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Property Images</label>
                    <input type="file" name="property_images[]" multiple class="w-full p-4 border-2 border-dashed border-slate-200 dark:border-slate-800 rounded-2xl text-xs text-slate-400 font-bold hover:border-accent-green transition-all">
                </div>

                <button type="submit" class="btn-green w-full justify-center py-4 rounded-2xl shadow-xl shadow-accent-green/10 font-black">Register Property →</button>
            </form>
        </div>
    </div>

    <!-- Properties Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-8">
        <?php if (empty($properties)): ?>
            <!-- Placeholder if no properties found -->
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
                            <p class="text-[8px] font-black text-slate-400 uppercase">Footprint</p>
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
                        <a href="property_details.php?id=<?php echo $prop['id']; ?>" class="text-[10px] font-black text-accent-green uppercase tracking-widest hover:underline">Details →</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
