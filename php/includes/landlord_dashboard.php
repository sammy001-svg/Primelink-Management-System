<?php
/**
 * Landlord Dashboard
 * Shown when role === 'landlord'
 */

$landlordId = getLandlordId($pdo);

// If the landlord record doesn't exist yet, show a friendly message
if (!$landlordId) { ?>
<div class="glass-card p-16 text-center animate-in">
    <p class="text-2xl font-black text-slate-900 dark:text-white mb-2">Account Setup Pending</p>
    <p class="text-slate-400">Your landlord profile is being configured by the admin. Please check back soon.</p>
</div>
<?php return; }

// KPI Data
$myProps = $pdo->prepare("SELECT COUNT(*) FROM properties WHERE landlord_id = ?");
$myProps->execute([$landlordId]);
$totalProps = $myProps->fetchColumn();

$occupiedUnits = $pdo->prepare("
    SELECT COUNT(*) FROM units u
    JOIN properties p ON u.property_id = p.id
    WHERE p.landlord_id = ? AND u.status = 'Occupied'
");
$occupiedUnits->execute([$landlordId]);
$occupied = $occupiedUnits->fetchColumn();

$vacantUnits = $pdo->prepare("
    SELECT COUNT(*) FROM units u
    JOIN properties p ON u.property_id = p.id
    WHERE p.landlord_id = ? AND u.status = 'Available'
");
$vacantUnits->execute([$landlordId]);
$vacant = $vacantUnits->fetchColumn();

$monthlyIncome = $pdo->prepare("
    SELECT COALESCE(SUM(tr.amount), 0)
    FROM transactions tr
    JOIN tenants t ON tr.tenant_id = t.id
    JOIN leases ls ON ls.tenant_id = t.id
    JOIN units u ON ls.unit_id = u.id
    JOIN properties p ON u.property_id = p.id
    WHERE p.landlord_id = ?
      AND tr.status = 'Paid'
      AND MONTH(tr.transaction_date) = MONTH(CURDATE())
      AND YEAR(tr.transaction_date)  = YEAR(CURDATE())
");
$monthlyIncome->execute([$landlordId]);
$income = $monthlyIncome->fetchColumn();

// Properties with units summary
$properties = $pdo->prepare("
    SELECT p.*,
           COUNT(u.id) as total_units,
           SUM(CASE WHEN u.status='Occupied' THEN 1 ELSE 0 END) as occ_units,
           SUM(CASE WHEN u.status='Available' THEN 1 ELSE 0 END) as vac_units
    FROM properties p
    LEFT JOIN units u ON u.property_id = p.id
    WHERE p.landlord_id = ?
    GROUP BY p.id
    ORDER BY p.created_at DESC
");
$properties->execute([$landlordId]);
$properties = $properties->fetchAll();

// Recent maintenance
$maintenance = $pdo->prepare("
    SELECT m.*, p.title as property_title
    FROM maintenance_requests m
    JOIN properties p ON m.property_id = p.id
    WHERE p.landlord_id = ?
    ORDER BY m.created_at DESC
    LIMIT 5
");
$maintenance->execute([$landlordId]);
$maintenance = $maintenance->fetchAll();

// Recent transactions
$transactions = $pdo->prepare("
    SELECT tr.*, t.full_name as tenant_name
    FROM transactions tr
    JOIN tenants t ON tr.tenant_id = t.id
    JOIN leases ls ON ls.tenant_id = t.id
    JOIN units u ON ls.unit_id = u.id
    JOIN properties p ON u.property_id = p.id
    WHERE p.landlord_id = ?
    ORDER BY tr.transaction_date DESC
    LIMIT 6
");
$transactions->execute([$landlordId]);
$transactions = $transactions->fetchAll();
?>

<div class="space-y-8 animate-in">
    <!-- Greeting -->
    <div>
        <h1 class="text-3xl font-black text-slate-900 dark:text-white">
            Good <?php
                $h = (int)date('H');
                echo $h < 12 ? 'Morning' : ($h < 17 ? 'Afternoon' : 'Evening');
            ?>, <?php echo htmlspecialchars(explode(' ', $user['full_name'])[0]); ?> 👋
        </h1>
        <p class="text-slate-400 font-medium mt-1"><?php echo date('l, F j, Y'); ?> — Your property portfolio overview</p>
    </div>

    <!-- KPI Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-5">
        <div class="glass-card p-6 hover:border-accent-gold/30 transition-all group">
            <div class="flex items-center justify-between mb-4">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">My Properties</p>
                <div class="w-9 h-9 rounded-xl bg-amber-50 dark:bg-amber-900/20 text-accent-gold flex items-center justify-center">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
                </div>
            </div>
            <p class="text-3xl font-black text-slate-900 dark:text-white"><?php echo $totalProps; ?></p>
        </div>
        <div class="glass-card p-6 hover:border-green-500/30 transition-all">
            <div class="flex items-center justify-between mb-4">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Occupied Units</p>
                <div class="w-9 h-9 rounded-xl bg-green-50 dark:bg-green-900/20 text-green-500 flex items-center justify-center">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>
                </div>
            </div>
            <p class="text-3xl font-black text-green-500"><?php echo $occupied; ?></p>
        </div>
        <div class="glass-card p-6 hover:border-orange-500/30 transition-all">
            <div class="flex items-center justify-between mb-4">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Vacant Units</p>
                <div class="w-9 h-9 rounded-xl bg-orange-50 dark:bg-orange-900/20 text-orange-500 flex items-center justify-center">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/></svg>
                </div>
            </div>
            <p class="text-3xl font-black text-orange-500"><?php echo $vacant; ?></p>
        </div>
        <div class="glass-card p-6 hover:border-blue-500/30 transition-all">
            <div class="flex items-center justify-between mb-4">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Monthly Income</p>
                <div class="w-9 h-9 rounded-xl bg-blue-50 dark:bg-blue-900/20 text-blue-500 flex items-center justify-center">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                </div>
            </div>
            <p class="text-3xl font-black text-blue-500">KSh <?php echo number_format($income); ?></p>
        </div>
    </div>

    <!-- Properties & Maintenance Row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- My Properties -->
        <div class="glass-card overflow-hidden">
            <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <h3 class="font-black">My Properties</h3>
                <a href="properties.php" class="text-[10px] text-accent-gold font-black uppercase tracking-widest">View All →</a>
            </div>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php if (empty($properties)): ?>
                <p class="p-8 text-center text-slate-400 text-sm">No properties assigned yet.</p>
                <?php else: foreach ($properties as $prop): ?>
                <div class="p-5 flex items-start justify-between hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-colors">
                    <div>
                        <p class="font-black text-sm text-slate-900 dark:text-white"><?php echo htmlspecialchars($prop['title']); ?></p>
                        <p class="text-[10px] text-slate-400 mt-0.5"><?php echo htmlspecialchars($prop['location']); ?></p>
                        <div class="flex gap-2 mt-2">
                            <span class="badge badge-green"><?php echo $prop['occ_units']; ?> Occupied</span>
                            <span class="badge badge-orange"><?php echo $prop['vac_units']; ?> Vacant</span>
                        </div>
                    </div>
                    <span class="badge badge-blue"><?php echo $prop['total_units']; ?> Units</span>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- Recent Maintenance -->
        <div class="glass-card overflow-hidden">
            <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <h3 class="font-black">Maintenance Requests</h3>
                <a href="maintenance.php" class="text-[10px] text-accent-gold font-black uppercase tracking-widest">Manage →</a>
            </div>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php if (empty($maintenance)): ?>
                <p class="p-8 text-center text-slate-400 text-sm">No maintenance requests.</p>
                <?php else: foreach ($maintenance as $req): ?>
                <div class="p-5 flex items-start justify-between hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-colors">
                    <div>
                        <p class="font-black text-sm text-slate-900 dark:text-white"><?php echo htmlspecialchars($req['title']); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($req['property_title']); ?></p>
                    </div>
                    <span class="px-2 py-0.5 rounded-full text-[9px] font-black uppercase <?php echo $req['status'] == 'Completed' ? 'bg-green-500/10 text-green-500' : ($req['status'] == 'In Progress' ? 'bg-blue-500/10 text-blue-500' : 'bg-orange-500/10 text-orange-500'); ?>">
                        <?php echo $req['status']; ?>
                    </span>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent Income -->
    <?php if (!empty($transactions)): ?>
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <h3 class="font-black">Recent Income</h3>
            <a href="financials.php" class="text-[10px] text-accent-gold font-black uppercase tracking-widest">View All →</a>
        </div>
        <table class="w-full text-left border-collapse">
            <thead><tr class="bg-slate-50 dark:bg-slate-800/50">
                <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Tenant</th>
                <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Type</th>
                <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest">Amount</th>
                <th class="p-4 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Date</th>
            </tr></thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php foreach ($transactions as $tr): ?>
                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-colors">
                    <td class="p-4 font-bold text-sm"><?php echo htmlspecialchars($tr['tenant_name']); ?></td>
                    <td class="p-4 text-xs text-slate-500"><?php echo htmlspecialchars($tr['transaction_type']); ?></td>
                    <td class="p-4 font-black text-slate-900 dark:text-white text-sm">KSh <?php echo number_format($tr['amount']); ?></td>
                    <td class="p-4 text-xs text-slate-400 text-right"><?php echo date('M d, Y', strtotime($tr['transaction_date'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
