<?php
/**
 * Vacancy Forecasting & Lease Pipeline
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/includes/settings.php';
$currency  = getSetting($pdo, 'currency_symbol', 'KSh');
$pageTitle = 'Vacancies';

// ── Schema self-heal ─────────────────────────────────────────────────
try { $pdo->exec("ALTER TABLE `leases` ADD COLUMN `renewal_status` ENUM('Offered','Accepted','Declined') NULL AFTER `status`"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE `leases` ADD COLUMN `parent_lease_id` VARCHAR(36) NULL AFTER `renewal_status`"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE `units` ADD COLUMN `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"); } catch (PDOException $e) {}

// ── 1. Currently Vacant Units ─────────────────────────────────────────
$vacantUnits = $pdo->query("
    SELECT u.*, p.title AS property_title, p.location AS property_location, p.id AS property_id,
           GREATEST(0, DATEDIFF(CURDATE(), COALESCE(u.updated_at, NOW()))) AS days_vacant
    FROM units u
    JOIN properties p ON u.property_id = p.id
    WHERE u.status = 'Available'
    ORDER BY p.title, u.unit_number
")->fetchAll();

// ── 2. Expiring Soon (next 90 days) ──────────────────────────────────
$expiringSoon = $pdo->query("
    SELECT l.id, l.end_date, l.monthly_rent, l.renewal_status,
           DATEDIFF(l.end_date, CURDATE()) AS days_left,
           t.full_name AS tenant_name, t.email AS tenant_email, t.phone AS tenant_phone, t.id AS tenant_id,
           p.title AS property_title, p.id AS property_id,
           u.unit_number, u.unit_type, u.id AS unit_id
    FROM leases l
    JOIN tenants t ON l.tenant_id = t.id
    JOIN units u   ON l.unit_id   = u.id
    JOIN properties p ON u.property_id = p.id
    WHERE l.status = 'Active'
      AND l.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
    ORDER BY l.end_date ASC
")->fetchAll();

// ── 3. Renewal Pipeline ───────────────────────────────────────────────
$renewalPipeline = $pdo->query("
    SELECT l.id, l.end_date, l.monthly_rent, l.renewal_status,
           DATEDIFF(l.end_date, CURDATE()) AS days_left,
           t.full_name AS tenant_name, t.id AS tenant_id,
           p.title AS property_title,
           u.unit_number
    FROM leases l
    JOIN tenants t ON l.tenant_id = t.id
    JOIN units u   ON l.unit_id   = u.id
    JOIN properties p ON u.property_id = p.id
    WHERE l.renewal_status IS NOT NULL
      AND l.status IN ('Active','Expired')
    ORDER BY FIELD(l.renewal_status,'Offered','Accepted','Declined'), l.end_date DESC
")->fetchAll();

// ── 4. Tenants without an active lease (for assign modal) ─────────────
$availableTenants = $pdo->query("
    SELECT t.id, t.full_name, t.phone, t.email
    FROM tenants t
    WHERE t.status = 'Active'
      AND NOT EXISTS (SELECT 1 FROM leases l WHERE l.tenant_id = t.id AND l.status = 'Active')
    ORDER BY t.full_name
")->fetchAll();

// ── KPIs ─────────────────────────────────────────────────────────────
$totalVacant  = count($vacantUnits);
$expiringIn30 = count(array_filter($expiringSoon, fn($l) => $l['days_left'] <= 30));
$expiringIn60 = count(array_filter($expiringSoon, fn($l) => $l['days_left'] <= 60));
$offeredCount = count(array_filter($renewalPipeline, fn($l) => $l['renewal_status'] === 'Offered'));
$lostRent     = array_sum(array_column($vacantUnits, 'monthly_rent'));

// ── JS data keyed by unit ID ──────────────────────────────────────────
$vacantUnitData = [];
foreach ($vacantUnits as $u) {
    $vacantUnitData[$u['id']] = [
        'id'             => $u['id'],
        'unit_number'    => $u['unit_number'],
        'unit_type'      => $u['unit_type'] ?? '',
        'property_title' => $u['property_title'],
        'property_id'    => $u['property_id'],
        'monthly_rent'   => (float)($u['monthly_rent'] ?? 0),
        'deposit_amount' => (float)($u['deposit_amount'] ?? 0),
        'floor_number'   => $u['floor_number'] ?? '',
        'days_vacant'    => (int)($u['days_vacant'] ?? 0),
    ];
}

// ── Property list for filter dropdown ────────────────────────────────
$vacantProperties = [];
foreach ($vacantUnits as $u) {
    $vacantProperties[$u['property_id']] = $u['property_title'];
}

// ── Flash messages ────────────────────────────────────────────────────
$flash = $flashErr = '';
$successMap = [
    'unit_assigned' => 'Tenant has been successfully assigned to the unit.',
    'renewed'       => 'Lease renewed successfully.',
];
if (!empty($_GET['success'])) $flash    = $successMap[$_GET['success']] ?? 'Action completed.';
if (!empty($_GET['renewed']))  $flash    = 'Lease renewed successfully.';
if (!empty($_GET['error']))   $flashErr = htmlspecialchars(urldecode($_GET['error']));

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

    <!-- ── Toasts ───────────────────────────────────────────────────── -->
    <?php if ($flash): ?>
    <div id="vacToast" class="fixed bottom-6 right-6 z-50 bg-green-500 text-white px-6 py-3.5 rounded-2xl shadow-2xl font-black text-sm flex items-center gap-2.5">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <?php echo htmlspecialchars($flash); ?>
    </div>
    <script>setTimeout(() => document.getElementById('vacToast')?.remove(), 4500);</script>
    <?php endif; ?>
    <?php if ($flashErr): ?>
    <div id="vacErrToast" class="fixed bottom-6 right-6 z-50 bg-red-500 text-white px-6 py-3.5 rounded-2xl shadow-2xl font-black text-sm flex items-center gap-2.5">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
        <?php echo $flashErr; ?>
    </div>
    <script>setTimeout(() => document.getElementById('vacErrToast')?.remove(), 5500);</script>
    <?php endif; ?>

    <!-- ── Page Header ──────────────────────────────────────────────── -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white">Vacancies</h1>
            <p class="text-slate-400 font-medium text-sm mt-1">Track vacant units, expiring leases, and fill gaps fast.</p>
        </div>
        <a href="leases.php" class="btn-primary gap-2 text-sm self-start sm:self-auto">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
            Manage Leases
        </a>
    </div>

    <!-- ── KPI Strip ────────────────────────────────────────────────── -->
    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 gap-4">
        <div class="glass-card p-5 flex flex-col gap-2 border-l-[3px] border-red-400">
            <div class="w-9 h-9 rounded-xl bg-red-50 dark:bg-red-900/30 flex items-center justify-center text-red-500 mb-1">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
            </div>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white"><?php echo $totalVacant; ?></h3>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Vacant Units</p>
        </div>
        <div class="glass-card p-5 flex flex-col gap-2 border-l-[3px] border-red-400">
            <div class="w-9 h-9 rounded-xl bg-red-50 dark:bg-red-900/30 flex items-center justify-center text-red-500 mb-1">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
            </div>
            <h3 class="text-2xl font-black text-red-500"><?php echo $expiringIn30; ?></h3>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Exp. in 30d</p>
        </div>
        <div class="glass-card p-5 flex flex-col gap-2 border-l-[3px] border-orange-400">
            <div class="w-9 h-9 rounded-xl bg-orange-50 dark:bg-orange-900/30 flex items-center justify-center text-orange-500 mb-1">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
            </div>
            <h3 class="text-2xl font-black text-orange-500"><?php echo $expiringIn60; ?></h3>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Exp. in 60d</p>
        </div>
        <div class="glass-card p-5 flex flex-col gap-2 border-l-[3px] border-blue-400">
            <div class="w-9 h-9 rounded-xl bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center text-blue-500 mb-1">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21 21-6-6m6 6v-4.8m0 4.8h-4.8"/><path d="M3 16.2V21"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M3 3l5 5"/></svg>
            </div>
            <h3 class="text-2xl font-black text-blue-500"><?php echo $offeredCount; ?></h3>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Renewals Offered</p>
        </div>
        <div class="glass-card p-5 flex flex-col gap-2 border-l-[3px] border-red-400">
            <div class="w-9 h-9 rounded-xl bg-red-50 dark:bg-red-900/30 flex items-center justify-center text-red-500 mb-1">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <h3 class="text-xl font-black text-red-500"><?php echo $currency; ?> <?php echo number_format($lostRent); ?></h3>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Lost Rent/Mo</p>
        </div>
    </div>

    <!-- ── Currently Vacant ─────────────────────────────────────────── -->
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-red-50 dark:bg-red-900/30 flex items-center justify-center text-red-500 shrink-0">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
                    </div>
                    <div>
                        <h3 class="font-black text-slate-900 dark:text-white">Currently Vacant</h3>
                        <p class="text-[11px] text-slate-400 mt-0.5">Units available for occupancy. Click <strong>Assign Tenant</strong> to fill a vacancy.</p>
                    </div>
                    <span class="badge <?php echo $totalVacant > 0 ? 'badge-red' : 'badge-green'; ?> ml-1"><?php echo $totalVacant; ?></span>
                </div>
                <?php if (!empty($vacantUnits)): ?>
                <div class="flex items-center gap-3 flex-wrap">
                    <div class="relative">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" id="vacSearch" oninput="filterVacancies()" placeholder="Search unit or property…"
                            class="pl-8 pr-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800/60 text-sm font-medium text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-accent-green/30 w-52 transition">
                    </div>
                    <?php if (count($vacantProperties) > 1): ?>
                    <select id="vacPropFilter" onchange="filterVacancies()"
                        class="py-2 px-3 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800/60 text-sm font-bold text-slate-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-green/30 transition">
                        <option value="">All Properties</option>
                        <?php foreach ($vacantProperties as $pid => $ptitle): ?>
                        <option value="<?php echo htmlspecialchars($pid); ?>"><?php echo htmlspecialchars($ptitle); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($vacantUnits)): ?>
        <div class="text-center py-16">
            <div class="w-14 h-14 rounded-2xl bg-green-50 dark:bg-green-900/20 flex items-center justify-center text-green-400 mx-auto mb-4">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <p class="text-slate-800 dark:text-white font-black text-base mb-1">All Units Occupied</p>
            <p class="text-slate-400 font-medium text-sm">No vacant units at this time. Great work!</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="data-table" id="vacTable">
                <thead>
                    <tr>
                        <th>Property</th>
                        <th>Unit</th>
                        <th>Type</th>
                        <th>Floor</th>
                        <th>Monthly Rent</th>
                        <th>Deposit</th>
                        <th>Days Vacant</th>
                        <th class="text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vacantUnits as $u):
                        $dv = (int)($u['days_vacant'] ?? 0);
                        if ($dv <= 14)     { $dvClass = 'text-green-600 dark:text-green-400'; }
                        elseif ($dv <= 30) { $dvClass = 'text-orange-500 font-bold'; }
                        else               { $dvClass = 'text-red-500 font-black'; }
                    ?>
                    <tr class="vac-row"
                        data-search="<?php echo strtolower(htmlspecialchars($u['unit_number'] . ' ' . $u['property_title'] . ' ' . ($u['unit_type'] ?? '') . ' ' . ($u['property_location'] ?? ''))); ?>"
                        data-propid="<?php echo htmlspecialchars($u['property_id']); ?>">
                        <td>
                            <a href="property_details.php?id=<?php echo $u['property_id']; ?>" class="font-bold text-slate-900 dark:text-white hover:text-accent-green transition-colors">
                                <?php echo htmlspecialchars($u['property_title']); ?>
                            </a>
                            <?php if (!empty($u['property_location'])): ?>
                            <div class="text-xs text-slate-400"><?php echo htmlspecialchars($u['property_location']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="font-black text-slate-900 dark:text-white">Unit <?php echo htmlspecialchars($u['unit_number']); ?></td>
                        <td class="text-slate-600 dark:text-slate-300 text-sm"><?php echo htmlspecialchars($u['unit_type'] ?? '—'); ?></td>
                        <td class="text-slate-500 dark:text-slate-400 text-sm"><?php echo htmlspecialchars($u['floor_number'] ?? 'G'); ?></td>
                        <td class="font-black text-sm text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($u['monthly_rent'] ?? 0); ?></td>
                        <td class="text-sm text-slate-600 dark:text-slate-300"><?php echo $currency; ?> <?php echo number_format($u['deposit_amount'] ?? 0); ?></td>
                        <td>
                            <span class="<?php echo $dvClass; ?> text-sm"><?php echo $dv; ?>d</span>
                        </td>
                        <td class="text-right">
                            <button onclick="openAssignModal('<?php echo $u['id']; ?>')"
                                class="inline-flex items-center gap-1.5 px-4 py-2 bg-accent-green/10 text-accent-green hover:bg-accent-green hover:text-white rounded-xl text-[11px] font-black uppercase tracking-wide transition-all whitespace-nowrap">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                Assign Tenant
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="vacEmpty" class="hidden text-center py-10">
            <p class="text-slate-400 font-bold text-sm">No vacant units match your search.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Expiring in Next 90 Days ──────────────────────────────────── -->
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-orange-50 dark:bg-orange-900/30 flex items-center justify-center text-orange-500 shrink-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
            </div>
            <div class="flex-1">
                <h3 class="font-black text-slate-900 dark:text-white">Expiring in Next 90 Days</h3>
                <p class="text-[11px] text-slate-400 mt-0.5">Leases approaching expiry — renew or relist before they lapse.</p>
            </div>
            <span class="badge <?php echo count($expiringSoon) > 0 ? 'badge-orange' : 'badge-green'; ?>"><?php echo count($expiringSoon); ?></span>
        </div>
        <?php if (empty($expiringSoon)): ?>
        <div class="text-center py-14">
            <div class="w-12 h-12 rounded-2xl bg-green-50 dark:bg-green-900/20 flex items-center justify-center text-green-400 mx-auto mb-3">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <p class="text-slate-400 font-bold text-sm">No leases expiring in the next 90 days.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Tenant</th>
                        <th>Property &amp; Unit</th>
                        <th>Expires</th>
                        <th>Monthly Rent</th>
                        <th>Urgency</th>
                        <th>Renewal Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expiringSoon as $l):
                        $d = (int)$l['days_left'];
                        if ($d <= 14)     { $urgency = 'Critical'; $urgClass = 'badge-red'; }
                        elseif ($d <= 30) { $urgency = 'High';     $urgClass = 'badge-orange'; }
                        elseif ($d <= 60) { $urgency = 'Medium';   $urgClass = 'badge-yellow'; }
                        else              { $urgency = 'Low';       $urgClass = 'badge-green'; }
                        $rs = $l['renewal_status'];
                        if ($rs === 'Offered')      { $rsBadge = 'badge-blue';  $rsText = 'Offered'; }
                        elseif ($rs === 'Accepted') { $rsBadge = 'badge-green'; $rsText = 'Accepted'; }
                        elseif ($rs === 'Declined') { $rsBadge = 'badge-red';   $rsText = 'Declined'; }
                        else                        { $rsBadge = 'bg-slate-100 dark:bg-slate-800 text-slate-400'; $rsText = 'None'; }
                    ?>
                    <tr>
                        <td>
                            <a href="tenant_details.php?id=<?php echo $l['tenant_id']; ?>" class="font-bold text-slate-900 dark:text-white hover:text-accent-green transition-colors">
                                <?php echo htmlspecialchars($l['tenant_name']); ?>
                            </a>
                            <div class="text-xs text-slate-400"><?php echo htmlspecialchars($l['tenant_email']); ?></div>
                        </td>
                        <td>
                            <a href="property_details.php?id=<?php echo $l['property_id']; ?>" class="font-bold hover:text-accent-green transition-colors">
                                <?php echo htmlspecialchars($l['property_title']); ?>
                            </a>
                            <div class="text-xs text-slate-400">Unit <?php echo htmlspecialchars($l['unit_number']); ?> &middot; <?php echo htmlspecialchars($l['unit_type'] ?? ''); ?></div>
                        </td>
                        <td>
                            <div class="font-black text-slate-900 dark:text-white text-sm"><?php echo date('M j, Y', strtotime($l['end_date'])); ?></div>
                            <div class="text-xs <?php echo $d <= 30 ? 'text-red-500 font-black' : 'text-slate-400'; ?>"><?php echo $d; ?> days left</div>
                        </td>
                        <td class="font-black text-sm"><?php echo $currency; ?> <?php echo number_format($l['monthly_rent']); ?></td>
                        <td><span class="badge <?php echo $urgClass; ?>"><?php echo $urgency; ?></span></td>
                        <td><span class="badge <?php echo $rsBadge; ?>"><?php echo $rsText; ?></span></td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-2">
                                <?php if (!$rs): ?>
                                <form action="actions/lease_actions.php" method="POST" class="inline">
                                    <input type="hidden" name="action" value="mark_renewal_status">
                                    <input type="hidden" name="lease_id" value="<?php echo $l['id']; ?>">
                                    <input type="hidden" name="renewal_status" value="Offered">
                                    <input type="hidden" name="redirect" value="../vacancies.php">
                                    <button type="submit" class="px-3 py-1.5 bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-900/40 rounded-lg text-[11px] font-black uppercase transition-colors whitespace-nowrap">
                                        Mark Offered
                                    </button>
                                </form>
                                <?php endif; ?>
                                <button onclick="setRenewLeaseId('<?php echo $l['id']; ?>', '<?php echo $l['monthly_rent']; ?>')"
                                    class="px-3 py-1.5 bg-accent-green/10 text-accent-green hover:bg-accent-green hover:text-white rounded-lg text-[11px] font-black uppercase transition-all whitespace-nowrap">
                                    Renew
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Renewal Pipeline ──────────────────────────────────────────── -->
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center text-blue-500 shrink-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"/><path d="M16 16h5v5"/></svg>
            </div>
            <div class="flex-1">
                <h3 class="font-black text-slate-900 dark:text-white">Renewal Pipeline</h3>
                <p class="text-[11px] text-slate-400 mt-0.5">Leases with a renewal offer in progress.</p>
            </div>
            <span class="badge badge-blue"><?php echo count($renewalPipeline); ?></span>
        </div>
        <?php if (empty($renewalPipeline)): ?>
        <div class="text-center py-12">
            <p class="text-slate-400 font-bold text-sm">No renewal offers sent yet.</p>
            <p class="text-slate-400 text-xs mt-1">Use "Mark Offered" on expiring leases above to start the pipeline.</p>
        </div>
        <?php else: ?>
        <div class="divide-y divide-slate-100 dark:divide-slate-800">
            <?php foreach ($renewalPipeline as $r):
                $rs = $r['renewal_status'];
                if ($rs === 'Offered')       { $dot = 'bg-blue-500';  $rsTxt = 'Offered'; }
                elseif ($rs === 'Accepted')  { $dot = 'bg-green-500'; $rsTxt = 'Accepted'; }
                else                         { $dot = 'bg-red-500';   $rsTxt = 'Declined'; }
            ?>
            <div class="flex items-center justify-between px-6 py-4 hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors">
                <div class="flex items-center gap-3">
                    <span class="w-2.5 h-2.5 rounded-full <?php echo $dot; ?> shrink-0"></span>
                    <div>
                        <a href="tenant_details.php?id=<?php echo $r['tenant_id']; ?>" class="font-bold text-slate-900 dark:text-white hover:text-accent-green transition-colors text-sm">
                            <?php echo htmlspecialchars($r['tenant_name']); ?>
                        </a>
                        <div class="text-xs text-slate-400"><?php echo htmlspecialchars($r['property_title']); ?> &middot; Unit <?php echo htmlspecialchars($r['unit_number']); ?></div>
                    </div>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <div class="text-right text-xs text-slate-400 hidden sm:block">
                        <?php echo $r['days_left'] >= 0 ? $r['days_left'] . ' days left' : 'Expired'; ?>
                    </div>
                    <?php if ($rs === 'Offered'): ?>
                    <div class="flex gap-1">
                        <form action="actions/lease_actions.php" method="POST" class="inline">
                            <input type="hidden" name="action" value="mark_renewal_status">
                            <input type="hidden" name="lease_id" value="<?php echo $r['id']; ?>">
                            <input type="hidden" name="renewal_status" value="Accepted">
                            <input type="hidden" name="redirect" value="../vacancies.php">
                            <button type="submit" class="px-2 py-1 bg-green-50 dark:bg-green-900/20 text-green-600 hover:bg-green-100 rounded-lg text-[10px] font-black uppercase transition-colors">Accept</button>
                        </form>
                        <form action="actions/lease_actions.php" method="POST" class="inline">
                            <input type="hidden" name="action" value="mark_renewal_status">
                            <input type="hidden" name="lease_id" value="<?php echo $r['id']; ?>">
                            <input type="hidden" name="renewal_status" value="Declined">
                            <input type="hidden" name="redirect" value="../vacancies.php">
                            <button type="submit" class="px-2 py-1 bg-red-50 dark:bg-red-900/20 text-red-500 hover:bg-red-100 rounded-lg text-[10px] font-black uppercase transition-colors">Decline</button>
                        </form>
                    </div>
                    <?php elseif ($rs === 'Accepted'): ?>
                    <button onclick="setRenewLeaseId('<?php echo $r['id']; ?>', '<?php echo $r['monthly_rent']; ?>')"
                        class="px-3 py-1 bg-accent-green/10 text-accent-green hover:bg-accent-green hover:text-white rounded-lg text-[10px] font-black uppercase transition-all">
                        Renew Now
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /space-y-8 -->

<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- ASSIGN TENANT MODAL                                               -->
<!-- ══════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="assignTenantModal" style="display:none;" onclick="if(event.target===this)closeModal('assignTenantModal')">
    <div class="modal-card max-w-lg" onclick="event.stopPropagation()">
        <button onclick="closeModal('assignTenantModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>

        <!-- Header -->
        <div class="flex items-center gap-3 mb-6">
            <div class="w-11 h-11 rounded-xl bg-accent-green/10 flex items-center justify-center text-accent-green shrink-0">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
            <div>
                <h2 class="text-xl font-black text-slate-900 dark:text-white">Assign Tenant to Unit</h2>
                <p class="text-slate-400 text-sm font-medium">A new lease will be created immediately.</p>
            </div>
        </div>

        <!-- Unit info card -->
        <div class="bg-slate-50 dark:bg-slate-800/60 rounded-2xl p-4 mb-6 flex items-center gap-4 border border-slate-100 dark:border-slate-700/60">
            <div class="w-10 h-10 rounded-xl bg-white dark:bg-slate-700 flex items-center justify-center text-slate-500 shrink-0 shadow-sm">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
            </div>
            <div>
                <div id="am_unitLabel" class="font-black text-slate-900 dark:text-white text-sm">—</div>
                <div id="am_unitSub" class="text-xs text-slate-400 mt-0.5">—</div>
            </div>
        </div>

        <!-- Form -->
        <form action="actions/tenant_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="assign_unit">
            <input type="hidden" name="unit_id" id="am_unit_id">
            <input type="hidden" name="property_id" id="am_property_id">
            <input type="hidden" name="_redirect" value="../vacancies.php">

            <!-- Tenant picker -->
            <div>
                <label class="form-label text-xs uppercase tracking-widest font-black text-slate-400 mb-2 block">Select Tenant</label>
                <?php if (empty($availableTenants)): ?>
                <div class="flex items-start gap-3 px-4 py-3 rounded-xl bg-orange-50 dark:bg-orange-900/20 border border-orange-100 dark:border-orange-800/40">
                    <svg class="text-orange-500 mt-0.5 shrink-0" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                    <p class="text-orange-700 dark:text-orange-300 text-sm font-bold">
                        No tenants without an active lease.
                        <a href="tenants.php" class="underline ml-1 hover:no-underline">Register a new tenant first.</a>
                    </p>
                </div>
                <?php else: ?>
                <div class="relative mb-2">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                    <input type="text" id="am_tenantSearch" oninput="filterAssignTenants()" placeholder="Search by name, phone or email…"
                        class="w-full pl-8 pr-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-accent-green/30 transition">
                </div>
                <select name="tenant_id" id="am_tenantSelect" required size="5"
                    class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm font-medium text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-accent-green/30 p-2 transition">
                    <?php foreach ($availableTenants as $t): ?>
                    <option value="<?php echo $t['id']; ?>"
                        data-search="<?php echo strtolower(htmlspecialchars($t['full_name'] . ' ' . $t['phone'] . ' ' . $t['email'])); ?>">
                        <?php echo htmlspecialchars($t['full_name']); ?><?php if ($t['phone']): ?> &middot; <?php echo htmlspecialchars($t['phone']); ?><?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>

            <!-- Dates -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label text-xs uppercase tracking-widest font-black text-slate-400">Lease Start</label>
                    <input type="date" name="start_date" required class="form-input" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div>
                    <label class="form-label text-xs uppercase tracking-widest font-black text-slate-400">
                        Lease End <span class="normal-case font-normal text-slate-400">(optional)</span>
                    </label>
                    <input type="date" name="end_date" class="form-input">
                </div>
            </div>

            <!-- Rent & Deposit -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="form-label text-xs uppercase tracking-widest font-black text-slate-400">Monthly Rent</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-bold pointer-events-none"><?php echo htmlspecialchars($currency); ?></span>
                        <input type="number" name="monthly_rent" id="am_monthly_rent" required class="form-input pl-12" step="0.01" min="1">
                    </div>
                </div>
                <div>
                    <label class="form-label text-xs uppercase tracking-widest font-black text-slate-400">Security Deposit</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-bold pointer-events-none"><?php echo htmlspecialchars($currency); ?></span>
                        <input type="number" name="deposit_amount" id="am_deposit_amount" class="form-input pl-12" step="0.01" min="0">
                    </div>
                </div>
            </div>

            <button type="submit" <?php echo empty($availableTenants) ? 'disabled' : ''; ?>
                class="w-full py-4 bg-accent-green hover:bg-green-600 disabled:opacity-40 disabled:cursor-not-allowed text-white font-black rounded-xl shadow-xl shadow-green-500/20 transition-all flex items-center justify-center gap-2">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Assign &amp; Create Lease
            </button>
        </form>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- RENEW LEASE MODAL (existing)                                      -->
<!-- ══════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="renewLeaseModal" style="display:none;">
    <div class="modal-card max-w-md">
        <button onclick="closeModal('renewLeaseModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-600 transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-2">Renew Lease</h2>
        <p class="text-slate-400 text-sm mb-6 font-medium">Create a new lease period for this tenant.</p>
        <form action="actions/lease_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="renew">
            <input type="hidden" name="lease_id" id="renew_lease_id">
            <input type="hidden" name="redirect" value="../vacancies.php?success=renewed">
            <div>
                <label class="form-label text-xs uppercase tracking-widest font-black text-slate-400">New End Date</label>
                <input type="date" name="new_end_date" required class="form-input">
            </div>
            <div>
                <label class="form-label text-xs uppercase tracking-widest font-black text-slate-400">Monthly Rent (optional adjustment)</label>
                <input type="number" name="new_rent" id="renew_old_rent" class="form-input">
            </div>
            <button type="submit" class="w-full py-4 bg-blue-600 hover:bg-blue-700 text-white font-black rounded-xl shadow-xl shadow-blue-500/20 transition-all">
                Confirm Renewal
            </button>
        </form>
    </div>
</div>

<script>
const vacantUnitData = <?php echo json_encode($vacantUnitData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

function openAssignModal(unitId) {
    const u = vacantUnitData[unitId];
    if (!u) return;
    document.getElementById('am_unit_id').value     = u.id;
    document.getElementById('am_property_id').value = u.property_id;
    document.getElementById('am_unitLabel').textContent = 'Unit ' + u.unit_number + ' · ' + u.property_title;
    document.getElementById('am_unitSub').textContent   = 'Floor ' + (u.floor_number || 'G') + ' · ' + (u.unit_type || 'N/A');
    const rentEl = document.getElementById('am_monthly_rent');
    const depEl  = document.getElementById('am_deposit_amount');
    if (rentEl) rentEl.value = u.monthly_rent;
    if (depEl)  depEl.value  = u.deposit_amount;
    const searchEl = document.getElementById('am_tenantSearch');
    if (searchEl) { searchEl.value = ''; filterAssignTenants(); }
    const sel = document.getElementById('am_tenantSelect');
    if (sel) sel.selectedIndex = -1;
    openModal('assignTenantModal');
}

function filterAssignTenants() {
    const q = (document.getElementById('am_tenantSearch')?.value || '').toLowerCase().trim();
    document.querySelectorAll('#am_tenantSelect option').forEach(o => {
        o.style.display = !q || (o.dataset.search || '').includes(q) ? '' : 'none';
    });
}

function filterVacancies() {
    const q    = (document.getElementById('vacSearch')?.value || '').toLowerCase().trim();
    const prop = document.getElementById('vacPropFilter')?.value || '';
    const rows = document.querySelectorAll('.vac-row');
    let visible = 0;
    rows.forEach(row => {
        const match = (!q || (row.dataset.search || '').includes(q)) && (!prop || row.dataset.propid === prop);
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    const emptyEl = document.getElementById('vacEmpty');
    if (emptyEl) emptyEl.classList.toggle('hidden', visible > 0);
}

function setRenewLeaseId(id, rent) {
    document.getElementById('renew_lease_id').value = id;
    document.getElementById('renew_old_rent').value = rent;
    openModal('renewLeaseModal');
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeModal('assignTenantModal');
        closeModal('renewLeaseModal');
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
