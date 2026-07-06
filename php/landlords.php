<?php
/**
 * Landlord Management Page (Admin/Staff)
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/includes/settings.php';

$pageTitle = "Landlords";
$user      = getCurrentUser($pdo);
$currency  = getSetting($pdo, 'currency_symbol', 'KSh');

// Schema self-heal
foreach ([
    "ALTER TABLE landlords ADD COLUMN IF NOT EXISTS id_number        VARCHAR(100) NULL",
    "ALTER TABLE landlords ADD COLUMN IF NOT EXISTS address          TEXT NULL",
    "ALTER TABLE landlords ADD COLUMN IF NOT EXISTS fee_type         VARCHAR(20)  NOT NULL DEFAULT 'percentage'",
    "ALTER TABLE landlords ADD COLUMN IF NOT EXISTS nok_name         VARCHAR(255) NULL",
    "ALTER TABLE landlords ADD COLUMN IF NOT EXISTS nok_phone        VARCHAR(50)  NULL",
    "ALTER TABLE landlords ADD COLUMN IF NOT EXISTS nok_relationship VARCHAR(100) NULL",
    "ALTER TABLE landlords ADD COLUMN IF NOT EXISTS status           VARCHAR(20)  NOT NULL DEFAULT 'active'",
] as $ddl) {
    try { $pdo->exec($ddl); } catch (PDOException $e) {}
}

// Fetch landlords with aggregated counts
$landlords = $pdo->query("
    SELECT l.*,
           COUNT(DISTINCT p.id) AS property_count,
           (SELECT COUNT(*)
            FROM tenants t
            JOIN leases ls ON t.id = ls.tenant_id
            JOIN units u   ON ls.unit_id = u.id
            JOIN properties p2 ON u.property_id = p2.id
            WHERE p2.landlord_id = l.id AND ls.status = 'Active') AS tenant_count
    FROM landlords l
    LEFT JOIN properties p ON p.landlord_id = l.id
    GROUP BY l.id
    ORDER BY l.created_at DESC
")->fetchAll();

// Fetch all properties for assignment modal
$allProperties = $pdo->query("
    SELECT p.id, p.title, p.location, p.property_type, p.landlord_id,
           l.full_name AS current_landlord
    FROM properties p
    LEFT JOIN landlords l ON p.landlord_id = l.id
    ORDER BY p.title
")->fetchAll();

// KPI stats
$totalLandlords    = count($landlords);
$activeLandlords   = count(array_filter($landlords, fn($l) => ($l['status'] ?? 'active') === 'active'));
$propertiesManaged = count(array_filter($allProperties, fn($p) => !empty($p['landlord_id'])));
$unassignedProps   = count(array_filter($allProperties, fn($p) => empty($p['landlord_id'])));

// JS data payloads
$llData = [];
foreach ($landlords as $ll) {
    $llData[$ll['id']] = [
        'full_name'        => $ll['full_name'],
        'email'            => $ll['email'],
        'phone'            => $ll['phone']            ?? '',
        'id_number'        => $ll['id_number']        ?? '',
        'address'          => $ll['address']          ?? '',
        'fee_type'         => $ll['fee_type']         ?? 'percentage',
        'management_fee'   => (float)($ll['management_fee'] ?? 10),
        'nok_name'         => $ll['nok_name']         ?? '',
        'nok_phone'        => $ll['nok_phone']        ?? '',
        'nok_relationship' => $ll['nok_relationship'] ?? '',
        'status'           => $ll['status']           ?? 'active',
        'property_count'   => (int)$ll['property_count'],
        'tenant_count'     => (int)$ll['tenant_count'],
    ];
}

$propData = array_map(fn($p) => [
    'id'               => $p['id'],
    'title'            => $p['title'],
    'location'         => $p['location'] ?? '',
    'landlord_id'      => $p['landlord_id'],
    'current_landlord' => $p['current_landlord'],
], $allProperties);

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

    <!-- Toast notification -->
    <?php if (isset($_GET['success'])): ?>
    <div id="llToast" class="fixed bottom-6 right-6 z-50 flex items-center gap-3 px-5 py-3.5 bg-emerald-500 text-white text-sm font-bold rounded-2xl shadow-2xl">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <?php echo htmlspecialchars($_GET['success']); ?>
    </div>
    <script>setTimeout(()=>{ const t=document.getElementById('llToast'); if(t) t.remove(); }, 4000);</script>
    <?php elseif (isset($_GET['error'])): ?>
    <div id="llToast" class="fixed bottom-6 right-6 z-50 flex items-center gap-3 px-5 py-3.5 bg-red-500 text-white text-sm font-bold rounded-2xl shadow-2xl">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
    <script>setTimeout(()=>{ const t=document.getElementById('llToast'); if(t) t.remove(); }, 6000);</script>
    <?php endif; ?>

    <!-- Page header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Landlords</h1>
            <p class="text-slate-500 font-medium">Manage landlord profiles, fees, and property assignments.</p>
        </div>
        <button onclick="openModal('newLandlordModal')" class="btn-primary shrink-0">
            + Add Landlord
        </button>
    </div>

    <!-- KPI Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-5">
        <div class="glass-card p-5 border-l-[3px] border-emerald-500 cursor-pointer hover:border-emerald-400 transition-colors" onclick="filterLandlords('')">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Landlords</p>
            <h3 class="text-3xl font-black mt-1 text-slate-900 dark:text-white"><?php echo $totalLandlords; ?></h3>
            <p class="text-[10px] text-emerald-500 font-bold mt-1"><?php echo $activeLandlords; ?> active</p>
        </div>
        <div class="glass-card p-5 border-l-[3px] border-blue-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Properties Managed</p>
            <h3 class="text-3xl font-black mt-1 text-slate-900 dark:text-white"><?php echo $propertiesManaged; ?></h3>
            <p class="text-[10px] text-blue-500 font-bold mt-1">of <?php echo count($allProperties); ?> total</p>
        </div>
        <div class="glass-card p-5 border-l-[3px] border-violet-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Active Tenants</p>
            <h3 class="text-3xl font-black mt-1 text-slate-900 dark:text-white"><?php echo array_sum(array_column($landlords, 'tenant_count')); ?></h3>
            <p class="text-[10px] text-violet-500 font-bold mt-1">across all landlords</p>
        </div>
        <div class="glass-card p-5 border-l-[3px] border-orange-400">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Unassigned Properties</p>
            <h3 class="text-3xl font-black mt-1 text-slate-900 dark:text-white"><?php echo $unassignedProps; ?></h3>
            <p class="text-[10px] text-orange-400 font-bold mt-1">need landlord assignment</p>
        </div>
    </div>

    <!-- Search + Table -->
    <div class="glass-card overflow-hidden">
        <!-- Table header with search -->
        <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row sm:items-center gap-3">
            <h3 class="font-black text-base text-slate-900 dark:text-white shrink-0">Registered Landlords</h3>
            <div class="sm:ml-auto flex items-center gap-3">
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <input type="text" id="llSearch" placeholder="Search landlords…" oninput="filterLandlords(this.value)"
                           class="pl-9 pr-4 py-2 text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl font-medium text-slate-700 dark:text-slate-300 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-accent-green/40 w-56">
                </div>
            </div>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse min-w-[800px]">
            <thead>
                <tr class="bg-slate-50 dark:bg-slate-800/50">
                    <th class="px-5 py-3.5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Landlord</th>
                    <th class="px-5 py-3.5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Contact</th>
                    <th class="px-5 py-3.5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Properties</th>
                    <th class="px-5 py-3.5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Mgmt Fee</th>
                    <th class="px-5 py-3.5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                    <th class="px-5 py-3.5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800" id="llTableBody">
                <?php if (empty($landlords)): ?>
                <tr id="llEmptyRow">
                    <td colspan="6" class="py-20 text-center">
                        <div class="text-slate-300 dark:text-slate-600 text-5xl mb-4">🏠</div>
                        <p class="text-slate-400 font-bold">No landlords registered yet.</p>
                        <button onclick="openModal('newLandlordModal')" class="mt-4 text-accent-green font-black text-sm hover:underline">Add the first landlord →</button>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($landlords as $ll):
                    $feeType   = $ll['fee_type'] ?? 'percentage';
                    $feeAmt    = (float)($ll['management_fee'] ?? 10);
                    $feeDisplay = $feeType === 'fixed'
                        ? $currency . ' ' . number_format($feeAmt, 2)
                        : number_format($feeAmt, 1) . '%';
                    $status = $ll['status'] ?? 'active';
                    $initials = strtoupper(substr($ll['full_name'], 0, 1));
                    $colors = ['from-emerald-500 to-teal-600', 'from-blue-500 to-indigo-600', 'from-violet-500 to-purple-600', 'from-orange-400 to-rose-500'];
                    $colorClass = $colors[abs(crc32($ll['id'])) % 4];
                ?>
                <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/20 transition-all ll-row cursor-pointer"
                    data-name="<?php echo strtolower(htmlspecialchars($ll['full_name'])); ?>"
                    data-email="<?php echo strtolower(htmlspecialchars($ll['email'])); ?>"
                    onclick="window.location='landlord_profile.php?id=<?php echo $ll['id']; ?>'"
                    title="Open <?php echo htmlspecialchars($ll['full_name'], ENT_QUOTES); ?>'s profile">

                    <!-- Landlord identity -->
                    <td class="px-5 py-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br <?php echo $colorClass; ?> flex items-center justify-center text-sm font-black text-white shrink-0">
                                <?php echo $initials; ?>
                            </div>
                            <div>
                                <p class="font-black text-slate-900 dark:text-white text-sm leading-tight"><?php echo htmlspecialchars($ll['full_name']); ?></p>
                                <p class="text-[10px] text-slate-400 font-bold tracking-wide">LLD-<?php echo strtoupper(substr($ll['id'], 0, 6)); ?></p>
                            </div>
                        </div>
                    </td>

                    <!-- Contact -->
                    <td class="px-5 py-4">
                        <p class="text-sm font-bold text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($ll['email']); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($ll['phone'] ?: '—'); ?></p>
                    </td>

                    <!-- Properties -->
                    <td class="px-5 py-4">
                        <div class="flex flex-col gap-1">
                            <span class="badge badge-blue text-[10px] w-fit"><?php echo $ll['property_count']; ?> propert<?php echo $ll['property_count'] == 1 ? 'y' : 'ies'; ?></span>
                            <span class="text-[10px] text-slate-400 font-medium"><?php echo $ll['tenant_count']; ?> active tenant<?php echo $ll['tenant_count'] == 1 ? '' : 's'; ?></span>
                        </div>
                    </td>

                    <!-- Management Fee -->
                    <td class="px-5 py-4">
                        <p class="font-black text-sm <?php echo $feeType === 'fixed' ? 'text-blue-600 dark:text-blue-400' : 'text-orange-500'; ?>">
                            <?php echo $feeDisplay; ?>
                        </p>
                        <p class="text-[10px] text-slate-400"><?php echo $feeType === 'fixed' ? 'Fixed amount' : 'Of gross rent'; ?></p>
                    </td>

                    <!-- Status -->
                    <td class="px-5 py-4">
                        <span class="px-2.5 py-1 rounded-lg text-[10px] font-black uppercase tracking-widest
                            <?php echo $status === 'active'
                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'
                                : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'; ?>">
                            <?php echo ucfirst($status); ?>
                        </span>
                    </td>

                    <!-- Actions -->
                    <td class="px-5 py-4 text-right">
                        <div class="flex items-center justify-end gap-1.5" onclick="event.stopPropagation()">
                            <button onclick="openViewModal('<?php echo $ll['id']; ?>')"
                                    title="View Profile"
                                    class="p-2 rounded-lg text-slate-400 hover:text-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-all">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                            <button onclick="openEditModal('<?php echo $ll['id']; ?>')"
                                    title="Edit Profile"
                                    class="p-2 rounded-lg text-slate-400 hover:text-emerald-500 hover:bg-emerald-50 dark:hover:bg-emerald-900/20 transition-all">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                            <button onclick="openAssignModal('<?php echo $ll['id']; ?>','<?php echo htmlspecialchars($ll['full_name'], ENT_QUOTES); ?>')"
                                    title="Assign Properties"
                                    class="p-2 rounded-lg text-slate-400 hover:text-violet-500 hover:bg-violet-50 dark:hover:bg-violet-900/20 transition-all">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                            </button>
                            <form method="POST" action="actions/landlord_actions.php" class="inline"
                                  onsubmit="return confirm('Delete landlord <?php echo htmlspecialchars($ll['full_name'], ENT_QUOTES); ?>? This cannot be undone.')"
                                  onclick="event.stopPropagation()">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="landlord_id" value="<?php echo $ll['id']; ?>">
                                <button type="submit" title="Delete"
                                        class="p-2 rounded-lg text-slate-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-all">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr id="llNoResults" class="hidden">
                    <td colspan="6" class="py-12 text-center text-slate-400 italic text-sm">No landlords match your search.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Property Assignments Overview -->
    <div class="glass-card overflow-hidden">
        <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <h3 class="font-black text-base text-slate-900 dark:text-white">Property Assignments</h3>
            <span class="text-[10px] text-slate-400 font-bold"><?php echo count($allProperties); ?> total</span>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse min-w-[600px]">
            <thead>
                <tr class="bg-slate-50 dark:bg-slate-800/50">
                    <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Property</th>
                    <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Type</th>
                    <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest">Assigned Landlord</th>
                    <th class="px-5 py-3 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php foreach ($allProperties as $prop): ?>
                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                    <td class="px-5 py-3.5">
                        <p class="font-black text-sm text-slate-900 dark:text-white"><?php echo htmlspecialchars($prop['title']); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($prop['location'] ?? ''); ?></p>
                    </td>
                    <td class="px-5 py-3.5">
                        <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wide"><?php echo htmlspecialchars($prop['property_type'] ?? '—'); ?></span>
                    </td>
                    <td class="px-5 py-3.5">
                        <?php if ($prop['current_landlord']): ?>
                            <span class="badge badge-green"><?php echo htmlspecialchars($prop['current_landlord']); ?></span>
                        <?php else: ?>
                            <span class="badge badge-orange">Unassigned</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3.5 text-right">
                        <?php if ($prop['landlord_id']): ?>
                        <form action="actions/landlord_actions.php" method="POST" class="inline"
                              onsubmit="return confirm('Remove this property from its landlord?')">
                            <input type="hidden" name="action" value="unassign_property">
                            <input type="hidden" name="property_id" value="<?php echo $prop['id']; ?>">
                            <button type="submit" class="text-[10px] font-black uppercase tracking-widest text-red-400 hover:text-red-600 transition-colors">
                                Unassign
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- CREATE LANDLORD MODAL                                          -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div id="newLandlordModal" class="modal-overlay" style="display:none;">
    <div class="modal-card" style="max-width:640px;">
        <button onclick="closeModal('newLandlordModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-1 text-slate-900 dark:text-white">Add New Landlord</h2>
        <p class="text-sm text-slate-400 mb-7">Create a landlord account with full profile and next of kin details.</p>

        <form action="actions/landlord_actions.php" method="POST" class="space-y-7">
            <input type="hidden" name="action" value="create">

            <!-- Personal Details -->
            <div class="space-y-4">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 dark:border-slate-800 pb-2">Personal Details</p>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Full Name *</label>
                        <input type="text" name="full_name" required placeholder="e.g. John Kamau" class="form-input">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Phone</label>
                        <input type="text" name="phone" placeholder="+254 7XX XXX XXX" class="form-input">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Email (Login) *</label>
                        <input type="email" name="email" required placeholder="landlord@example.com" class="form-input">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">National ID / Passport</label>
                        <input type="text" name="id_number" placeholder="e.g. 12345678" class="form-input">
                    </div>
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Residential Address</label>
                    <input type="text" name="address" placeholder="e.g. Karen, Nairobi" class="form-input">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Initial Password *</label>
                    <input type="password" name="password" required placeholder="••••••••" class="form-input">
                </div>
            </div>

            <!-- Next of Kin -->
            <div class="space-y-4">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 dark:border-slate-800 pb-2">Next of Kin</p>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Full Name</label>
                        <input type="text" name="nok_name" placeholder="e.g. Jane Kamau" class="form-input">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Phone</label>
                        <input type="text" name="nok_phone" placeholder="+254 7XX XXX XXX" class="form-input">
                    </div>
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Relationship</label>
                    <select name="nok_relationship" class="form-input">
                        <option value="">— Select —</option>
                        <option value="Spouse">Spouse</option>
                        <option value="Parent">Parent</option>
                        <option value="Sibling">Sibling</option>
                        <option value="Child">Child</option>
                        <option value="Friend">Friend</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>

            <!-- Management Fee -->
            <div class="space-y-4">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 dark:border-slate-800 pb-2">Management Fee</p>
                <div class="flex gap-3">
                    <label class="flex-1 flex items-center gap-3 p-3.5 rounded-xl border-2 border-slate-200 dark:border-slate-700 cursor-pointer transition-all has-[:checked]:border-orange-400 has-[:checked]:bg-orange-50 dark:has-[:checked]:bg-orange-900/10">
                        <input type="radio" name="fee_type" value="percentage" checked onchange="toggleFeeLabel('new','percentage')" class="accent-orange-400">
                        <div>
                            <p class="font-black text-sm text-slate-800 dark:text-white">Percentage (%)</p>
                            <p class="text-[10px] text-slate-400">Deducted from gross rent collected</p>
                        </div>
                    </label>
                    <label class="flex-1 flex items-center gap-3 p-3.5 rounded-xl border-2 border-slate-200 dark:border-slate-700 cursor-pointer transition-all has-[:checked]:border-blue-400 has-[:checked]:bg-blue-50 dark:has-[:checked]:bg-blue-900/10">
                        <input type="radio" name="fee_type" value="fixed" onchange="toggleFeeLabel('new','fixed')" class="accent-blue-400">
                        <div>
                            <p class="font-black text-sm text-slate-800 dark:text-white">Fixed Amount</p>
                            <p class="text-[10px] text-slate-400">Flat fee per payout period</p>
                        </div>
                    </label>
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">
                        Amount <span id="newFeeLabel" class="text-orange-400">(percentage, 0–100)</span>
                    </label>
                    <input type="number" name="management_fee" id="newFeeInput" value="10" min="0" step="0.01" class="form-input w-full">
                </div>
            </div>

            <button type="submit" class="btn-green w-full justify-center py-4">Create Landlord Account</button>
        </form>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- EDIT LANDLORD MODAL                                            -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div id="editLandlordModal" class="modal-overlay" style="display:none;">
    <div class="modal-card" style="max-width:640px;">
        <button onclick="closeModal('editLandlordModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-1 text-slate-900 dark:text-white">Edit Landlord Profile</h2>
        <p class="text-sm text-slate-400 mb-7">Update landlord details. Password changes use the Reset Password feature.</p>

        <form action="actions/landlord_actions.php" method="POST" class="space-y-7">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="landlord_id" id="editLandlordId">

            <!-- Personal Details -->
            <div class="space-y-4">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 dark:border-slate-800 pb-2">Personal Details</p>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Full Name *</label>
                        <input type="text" name="full_name" id="editFullName" required class="form-input">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Phone</label>
                        <input type="text" name="phone" id="editPhone" class="form-input">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Email (Login) *</label>
                        <input type="email" name="email" id="editEmail" required class="form-input">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">National ID / Passport</label>
                        <input type="text" name="id_number" id="editIdNumber" class="form-input">
                    </div>
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Residential Address</label>
                    <input type="text" name="address" id="editAddress" class="form-input">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Account Status</label>
                    <select name="status" id="editStatus" class="form-input">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>

            <!-- Next of Kin -->
            <div class="space-y-4">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 dark:border-slate-800 pb-2">Next of Kin</p>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Full Name</label>
                        <input type="text" name="nok_name" id="editNokName" class="form-input">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Phone</label>
                        <input type="text" name="nok_phone" id="editNokPhone" class="form-input">
                    </div>
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Relationship</label>
                    <select name="nok_relationship" id="editNokRel" class="form-input">
                        <option value="">— Select —</option>
                        <option value="Spouse">Spouse</option>
                        <option value="Parent">Parent</option>
                        <option value="Sibling">Sibling</option>
                        <option value="Child">Child</option>
                        <option value="Friend">Friend</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>

            <!-- Management Fee -->
            <div class="space-y-4">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 dark:border-slate-800 pb-2">Management Fee</p>
                <div class="flex gap-3">
                    <label class="flex-1 flex items-center gap-3 p-3.5 rounded-xl border-2 border-slate-200 dark:border-slate-700 cursor-pointer transition-all has-[:checked]:border-orange-400 has-[:checked]:bg-orange-50 dark:has-[:checked]:bg-orange-900/10">
                        <input type="radio" name="fee_type" id="editFeeTypePct" value="percentage" onchange="toggleFeeLabel('edit','percentage')" class="accent-orange-400">
                        <div>
                            <p class="font-black text-sm text-slate-800 dark:text-white">Percentage (%)</p>
                            <p class="text-[10px] text-slate-400">Of gross rent collected</p>
                        </div>
                    </label>
                    <label class="flex-1 flex items-center gap-3 p-3.5 rounded-xl border-2 border-slate-200 dark:border-slate-700 cursor-pointer transition-all has-[:checked]:border-blue-400 has-[:checked]:bg-blue-50 dark:has-[:checked]:bg-blue-900/10">
                        <input type="radio" name="fee_type" id="editFeeTypeFixed" value="fixed" onchange="toggleFeeLabel('edit','fixed')" class="accent-blue-400">
                        <div>
                            <p class="font-black text-sm text-slate-800 dark:text-white">Fixed Amount</p>
                            <p class="text-[10px] text-slate-400">Flat fee per payout period</p>
                        </div>
                    </label>
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">
                        Amount <span id="editFeeLabel" class="text-orange-400">(percentage, 0–100)</span>
                    </label>
                    <input type="number" name="management_fee" id="editFeeInput" min="0" step="0.01" class="form-input w-full">
                </div>
            </div>

            <button type="submit" class="btn-green w-full justify-center py-4">Save Changes</button>
        </form>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- VIEW PROFILE MODAL                                             -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div id="viewProfileModal" class="modal-overlay" style="display:none;">
    <div class="modal-card" style="max-width:620px;">
        <button onclick="closeModal('viewProfileModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>

        <!-- Hero -->
        <div class="flex items-center gap-5 mb-8">
            <div id="viewAvatar" class="w-16 h-16 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-2xl font-black text-white shrink-0"></div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2.5 flex-wrap">
                    <h2 id="viewName" class="text-xl font-black text-slate-900 dark:text-white"></h2>
                    <span id="viewStatusBadge" class="px-2 py-0.5 rounded-lg text-[10px] font-black uppercase tracking-widest"></span>
                </div>
                <p id="viewRef" class="text-[10px] text-slate-400 font-bold tracking-wide mt-0.5"></p>
                <p id="viewEmail" class="text-sm text-slate-500 font-medium mt-1"></p>
            </div>
        </div>

        <!-- Details grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mb-8">
            <!-- Personal -->
            <div class="space-y-3">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 dark:border-slate-800 pb-1.5">Personal Details</p>
                <div class="space-y-2.5">
                    <div>
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Phone</p>
                        <p id="viewPhone" class="font-bold text-sm text-slate-700 dark:text-slate-300">—</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">National ID / Passport</p>
                        <p id="viewIdNumber" class="font-bold text-sm text-slate-700 dark:text-slate-300">—</p>
                    </div>
                    <div>
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Address</p>
                        <p id="viewAddress" class="font-bold text-sm text-slate-700 dark:text-slate-300">—</p>
                    </div>
                </div>
            </div>

            <!-- Management Fee + NOK -->
            <div class="space-y-3">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 dark:border-slate-800 pb-1.5">Management Fee</p>
                <div class="p-3.5 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                    <p id="viewFeeType" class="text-[10px] font-black text-slate-400 uppercase tracking-widest"></p>
                    <p id="viewFeeAmount" class="text-2xl font-black text-slate-900 dark:text-white mt-0.5"></p>
                </div>

                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 dark:border-slate-800 pb-1.5 mt-4">Next of Kin</p>
                <div class="space-y-1.5">
                    <p id="viewNokName" class="font-bold text-sm text-slate-700 dark:text-slate-300">—</p>
                    <p id="viewNokPhone" class="text-xs text-slate-400"></p>
                    <p id="viewNokRel" class="text-[10px] font-black text-slate-400 uppercase tracking-widest"></p>
                </div>
            </div>
        </div>

        <!-- Assigned Properties -->
        <div class="space-y-3">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100 dark:border-slate-800 pb-1.5">Assigned Properties</p>
            <div id="viewProperties" class="space-y-2 max-h-40 overflow-y-auto pr-1"></div>
        </div>

        <!-- Actions -->
        <div class="flex gap-3 mt-8" id="viewModalActions"></div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- ASSIGN PROPERTIES MODAL                                        -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div id="assignModal" class="modal-overlay" style="display:none;">
    <div class="modal-card" style="max-width:520px;">
        <button onclick="closeModal('assignModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-1 text-slate-900 dark:text-white">Assign Properties</h2>
        <p class="text-sm text-slate-400 mb-6">to <span id="assignTargetName" class="font-black text-slate-700 dark:text-white"></span></p>

        <form action="actions/landlord_actions.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="assign_properties">
            <input type="hidden" name="landlord_id" id="assignLandlordId">

            <div class="relative mb-3">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="text" placeholder="Filter properties…" oninput="filterAssignProps(this.value)"
                       class="pl-9 pr-4 py-2 w-full text-sm bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl font-medium text-slate-700 dark:text-slate-300 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-accent-green/40">
            </div>

            <div class="space-y-1.5 max-h-72 overflow-y-auto pr-1" id="assignPropList">
                <?php foreach ($allProperties as $prop): ?>
                <label class="assign-prop-item flex items-start gap-3 p-3 rounded-xl hover:bg-slate-50 dark:hover:bg-slate-800/50 cursor-pointer transition-colors"
                       data-title="<?php echo strtolower(htmlspecialchars($prop['title'])); ?>"
                       data-location="<?php echo strtolower(htmlspecialchars($prop['location'] ?? '')); ?>">
                    <input type="checkbox" name="property_ids[]"
                           value="<?php echo $prop['id']; ?>"
                           data-prop-id="<?php echo $prop['id']; ?>"
                           data-landlord="<?php echo htmlspecialchars($prop['landlord_id'] ?? ''); ?>"
                           class="assign-prop-check mt-0.5 w-4 h-4 accent-accent-green rounded shrink-0">
                    <div>
                        <p class="font-bold text-sm text-slate-800 dark:text-white"><?php echo htmlspecialchars($prop['title']); ?></p>
                        <p class="text-[10px] text-slate-400">
                            <?php echo htmlspecialchars($prop['location'] ?? ''); ?>
                            <?php if ($prop['current_landlord']): ?>
                                — <span class="text-orange-400 font-bold">Currently: <?php echo htmlspecialchars($prop['current_landlord']); ?></span>
                            <?php endif; ?>
                        </p>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="btn-green w-full justify-center py-4">Save Assignments</button>
        </form>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- JAVASCRIPT                                                     -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<script>
const llData   = <?php echo json_encode($llData,   JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const propData = <?php echo json_encode($propData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const currency = '<?php echo addslashes($currency); ?>';

// ── Search filter ─────────────────────────────────────────────────
function filterLandlords(q) {
    const rows   = document.querySelectorAll('.ll-row');
    const noRes  = document.getElementById('llNoResults');
    const term   = q.toLowerCase().trim();
    let visible  = 0;
    rows.forEach(r => {
        const match = !term || r.dataset.name.includes(term) || r.dataset.email.includes(term);
        r.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    if (noRes) noRes.classList.toggle('hidden', visible > 0);
    const inp = document.getElementById('llSearch');
    if (inp && inp.value !== q) inp.value = q;
}

// ── Fee label toggle ──────────────────────────────────────────────
function toggleFeeLabel(prefix, type) {
    const lbl = document.getElementById(prefix + 'FeeLabel');
    const inp = document.getElementById(prefix + 'FeeInput');
    if (type === 'fixed') {
        if (lbl) lbl.innerHTML = `<span class="text-blue-400">(fixed amount in ${currency})</span>`;
        if (inp && parseFloat(inp.value) <= 100 && parseFloat(inp.value) > 0) {
            // leave as-is — admin will enter the fixed amount
        }
    } else {
        if (lbl) lbl.innerHTML = '<span class="text-orange-400">(percentage, 0–100)</span>';
    }
}

// ── Open Edit Modal ───────────────────────────────────────────────
function openEditModal(id) {
    const d = llData[id];
    if (!d) return;

    document.getElementById('editLandlordId').value = id;
    document.getElementById('editFullName').value   = d.full_name;
    document.getElementById('editEmail').value      = d.email;
    document.getElementById('editPhone').value      = d.phone;
    document.getElementById('editIdNumber').value   = d.id_number;
    document.getElementById('editAddress').value    = d.address;
    document.getElementById('editNokName').value    = d.nok_name;
    document.getElementById('editNokPhone').value   = d.nok_phone;
    document.getElementById('editFeeInput').value   = d.management_fee;
    document.getElementById('editStatus').value     = d.status;

    // Fee type radio
    const pctR   = document.getElementById('editFeeTypePct');
    const fixedR = document.getElementById('editFeeTypeFixed');
    if (d.fee_type === 'fixed') {
        fixedR.checked = true; pctR.checked = false;
        toggleFeeLabel('edit', 'fixed');
    } else {
        pctR.checked = true; fixedR.checked = false;
        toggleFeeLabel('edit', 'percentage');
    }

    // NOK relationship
    const relSel = document.getElementById('editNokRel');
    if (relSel) {
        for (let o of relSel.options) o.selected = (o.value === d.nok_relationship);
    }

    openModal('editLandlordModal');
}

// ── Open View Profile Modal ───────────────────────────────────────
function openViewModal(id) {
    const d = llData[id];
    if (!d) return;

    // Avatar
    const av = document.getElementById('viewAvatar');
    av.textContent = d.full_name.charAt(0).toUpperCase();
    const colors = ['from-emerald-500 to-teal-600','from-blue-500 to-indigo-600','from-violet-500 to-purple-600','from-orange-400 to-rose-500'];
    av.className = 'w-16 h-16 rounded-2xl bg-gradient-to-br flex items-center justify-center text-2xl font-black text-white shrink-0';
    av.classList.add(...colors[Math.abs(hashCode(id)) % 4].split(' '));

    document.getElementById('viewName').textContent  = d.full_name;
    document.getElementById('viewRef').textContent   = 'LLD-' + id.substring(0, 6).toUpperCase();
    document.getElementById('viewEmail').textContent = d.email;

    // Status badge
    const badge = document.getElementById('viewStatusBadge');
    if (d.status === 'active') {
        badge.textContent  = 'Active';
        badge.className = 'px-2 py-0.5 rounded-lg text-[10px] font-black uppercase tracking-widest bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400';
    } else {
        badge.textContent  = 'Inactive';
        badge.className = 'px-2 py-0.5 rounded-lg text-[10px] font-black uppercase tracking-widest bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400';
    }

    setText('viewPhone',    d.phone    || '—');
    setText('viewIdNumber', d.id_number || '—');
    setText('viewAddress',  d.address  || '—');

    // Fee
    document.getElementById('viewFeeType').textContent   = d.fee_type === 'fixed' ? 'Fixed Amount' : 'Percentage of Gross Rent';
    document.getElementById('viewFeeAmount').textContent = d.fee_type === 'fixed'
        ? currency + ' ' + parseFloat(d.management_fee).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2})
        : parseFloat(d.management_fee).toFixed(1) + '%';

    // NOK
    setText('viewNokName',  d.nok_name  || '—');
    setText('viewNokPhone', d.nok_phone || '');
    setText('viewNokRel',   d.nok_relationship || '');

    // Assigned Properties
    const propsDiv = document.getElementById('viewProperties');
    const myProps  = propData.filter(p => p.landlord_id === id);
    if (myProps.length === 0) {
        propsDiv.innerHTML = '<p class="text-sm text-slate-400 italic">No properties assigned yet.</p>';
    } else {
        propsDiv.innerHTML = myProps.map(p => `
            <div class="flex items-center gap-2.5 p-2.5 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-violet-400 shrink-0"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <div>
                    <p class="font-bold text-sm text-slate-800 dark:text-white leading-tight">${escHtml(p.title)}</p>
                    <p class="text-[10px] text-slate-400">${escHtml(p.location)}</p>
                </div>
            </div>`).join('');
    }

    // Action buttons
    document.getElementById('viewModalActions').innerHTML = `
        <button onclick="closeModal('viewProfileModal'); openEditModal('${id}')"
                class="flex-1 btn-green justify-center py-3">Edit Profile</button>
        <button onclick="closeModal('viewProfileModal'); openAssignModal('${id}','${escHtml(d.full_name)}')"
                class="flex-1 px-4 py-3 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-white font-black rounded-xl text-sm transition-all">
            Assign Properties</button>`;

    openModal('viewProfileModal');
}

// ── Open Assign Properties Modal ──────────────────────────────────
function openAssignModal(landlordId, name) {
    document.getElementById('assignLandlordId').value      = landlordId;
    document.getElementById('assignTargetName').textContent = name;

    // Pre-check properties currently assigned to this landlord
    document.querySelectorAll('.assign-prop-check').forEach(cb => {
        cb.checked = (cb.dataset.landlord === landlordId);
    });

    // Clear search
    const searchInp = document.querySelector('#assignPropList').closest('form').querySelector('input[type=text]');
    if (searchInp) { searchInp.value = ''; filterAssignProps(''); }

    openModal('assignModal');
}

// ── Filter assign property list ───────────────────────────────────
function filterAssignProps(q) {
    const term = q.toLowerCase().trim();
    document.querySelectorAll('.assign-prop-item').forEach(item => {
        const match = !term || item.dataset.title.includes(term) || item.dataset.location.includes(term);
        item.style.display = match ? '' : 'none';
    });
}

// ── Helpers ───────────────────────────────────────────────────────
function setText(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
}
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function hashCode(str) {
    let h = 0;
    for (let i = 0; i < str.length; i++) h = ((h << 5) - h) + str.charCodeAt(i) | 0;
    return h;
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
