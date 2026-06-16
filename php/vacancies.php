<?php
/**
 * Vacancy Forecasting & Lease Pipeline
 * Primelink Management System — Phase 8
 *
 * Three sections:
 *   1. Currently Vacant   — units with status = 'Available'
 *   2. Expiring Soon      — active leases ending in the next 90 days
 *   3. Renewal Pipeline   — leases with a renewal offer sent
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/includes/settings.php';
$currency  = getSetting($pdo, 'currency_symbol', 'KSh');
$pageTitle = 'Vacancy Forecasting';

// ── Schema self-heal ─────────────────────────────────────────────────
try { $pdo->exec("ALTER TABLE `leases` ADD COLUMN `renewal_status` ENUM('Offered','Accepted','Declined') NULL AFTER `status`"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE `leases` ADD COLUMN `parent_lease_id` VARCHAR(36) NULL AFTER `renewal_status`"); } catch (PDOException $e) {}

// ── 1. Currently Vacant Units ─────────────────────────────────────────
$vacantUnits = $pdo->query("
    SELECT u.*, p.title AS property_title, p.location AS property_location,
           p.id AS property_id,
           DATEDIFF(CURDATE(), u.updated_at) AS days_vacant
    FROM units u
    JOIN properties p ON u.property_id = p.id
    WHERE u.status = 'Available'
    ORDER BY p.title, u.unit_number
")->fetchAll();

// ── 2. Expiring Soon (next 90 days) ──────────────────────────────────
$expiringSoon = $pdo->query("
    SELECT l.id, l.end_date, l.monthly_rent, l.renewal_status,
           DATEDIFF(l.end_date, CURDATE()) AS days_left,
           t.full_name AS tenant_name, t.email AS tenant_email, t.phone AS tenant_phone,
           t.id AS tenant_id,
           p.title AS property_title, p.id AS property_id,
           u.unit_number, u.unit_type, u.id AS unit_id
    FROM leases l
    JOIN tenants t ON l.tenant_id = t.id
    JOIN units u ON l.unit_id = u.id
    JOIN properties p ON u.property_id = p.id
    WHERE l.status = 'Active'
      AND l.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
    ORDER BY l.end_date ASC
")->fetchAll();

// ── 3. Renewal Pipeline — Offered / Accepted / Declined ──────────────
$renewalPipeline = $pdo->query("
    SELECT l.id, l.end_date, l.monthly_rent, l.renewal_status,
           DATEDIFF(l.end_date, CURDATE()) AS days_left,
           t.full_name AS tenant_name, t.id AS tenant_id,
           p.title AS property_title,
           u.unit_number
    FROM leases l
    JOIN tenants t ON l.tenant_id = t.id
    JOIN units u ON l.unit_id = u.id
    JOIN properties p ON u.property_id = p.id
    WHERE l.renewal_status IS NOT NULL
      AND l.status IN ('Active','Expired')
    ORDER BY FIELD(l.renewal_status,'Offered','Accepted','Declined'), l.end_date DESC
")->fetchAll();

// ── KPIs ─────────────────────────────────────────────────────────────
$totalVacant    = count($vacantUnits);
$expiringIn30   = count(array_filter($expiringSoon, fn($l) => $l['days_left'] <= 30));
$expiringIn60   = count(array_filter($expiringSoon, fn($l) => $l['days_left'] <= 60));
$offeredCount   = count(array_filter($renewalPipeline, fn($l) => $l['renewal_status'] === 'Offered'));
$acceptedCount  = count(array_filter($renewalPipeline, fn($l) => $l['renewal_status'] === 'Accepted'));
$lostRent       = array_sum(array_column($vacantUnits, 'monthly_rent'));

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

    <!-- ── Page Header ──────────────────────────────────────────────── -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white">Vacancy Forecasting</h1>
            <p class="text-slate-400 font-medium text-sm mt-1">Track vacant units, upcoming lease expirations, and renewal offers.</p>
        </div>
        <a href="leases.php" class="btn-primary gap-2 text-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
            Manage Leases
        </a>
    </div>

    <!-- ── KPI Strip ────────────────────────────────────────────────── -->
    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 gap-4">
        <div class="glass-card p-5 flex flex-col gap-2">
            <div class="w-9 h-9 rounded-xl bg-red-50 dark:bg-red-900/30 flex items-center justify-center text-red-500 mb-1">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
            </div>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white"><?php echo $totalVacant; ?></h3>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Vacant Units</p>
        </div>
        <div class="glass-card p-5 flex flex-col gap-2">
            <div class="w-9 h-9 rounded-xl bg-red-50 dark:bg-red-900/30 flex items-center justify-center text-red-500 mb-1">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
            </div>
            <h3 class="text-2xl font-black text-red-500"><?php echo $expiringIn30; ?></h3>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Exp. in 30d</p>
        </div>
        <div class="glass-card p-5 flex flex-col gap-2">
            <div class="w-9 h-9 rounded-xl bg-orange-50 dark:bg-orange-900/30 flex items-center justify-center text-orange-500 mb-1">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
            </div>
            <h3 class="text-2xl font-black text-orange-500"><?php echo $expiringIn60; ?></h3>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Exp. in 60d</p>
        </div>
        <div class="glass-card p-5 flex flex-col gap-2">
            <div class="w-9 h-9 rounded-xl bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center text-blue-500 mb-1">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21 21-6-6m6 6v-4.8m0 4.8h-4.8"/><path d="M3 16.2V21"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M3 3l5 5"/></svg>
            </div>
            <h3 class="text-2xl font-black text-blue-500"><?php echo $offeredCount; ?></h3>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Renewals Offered</p>
        </div>
        <div class="glass-card p-5 flex flex-col gap-2">
            <div class="w-9 h-9 rounded-xl bg-red-50 dark:bg-red-900/30 flex items-center justify-center text-red-500 mb-1">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" x2="12" y1="2" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
            <h3 class="text-xl font-black text-red-500"><?php echo $currency; ?> <?php echo number_format($lostRent); ?></h3>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Lost Rent/Mo</p>
        </div>
    </div>

    <!-- ── Expiring Soon ─────────────────────────────────────────────── -->
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <div>
                <h3 class="font-black text-slate-900 dark:text-white">Expiring in Next 90 Days</h3>
                <p class="text-[11px] text-slate-400 mt-0.5">Leases approaching expiry — action required to renew or relist.</p>
            </div>
            <span class="badge <?php echo count($expiringSoon) > 0 ? 'badge-orange' : 'badge-green'; ?>"><?php echo count($expiringSoon); ?></span>
        </div>
        <?php if (empty($expiringSoon)): ?>
        <div class="text-center py-14">
            <svg class="mx-auto text-green-200 dark:text-green-900 mb-3" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <p class="text-slate-400 font-bold text-sm">No leases expiring in the next 90 days.</p>
        </div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Tenant</th>
                        <th>Property & Unit</th>
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
                        if ($d <= 14)      { $urgency = 'Critical'; $urgClass = 'badge-red'; }
                        elseif ($d <= 30)  { $urgency = 'High';     $urgClass = 'badge-orange'; }
                        elseif ($d <= 60)  { $urgency = 'Medium';   $urgClass = 'badge-yellow'; }
                        else               { $urgency = 'Low';      $urgClass = 'badge-green'; }

                        $rs = $l['renewal_status'];
                        if ($rs === 'Offered')  { $rsBadge = 'badge-blue'; $rsText = 'Offered'; }
                        elseif ($rs === 'Accepted')  { $rsBadge = 'badge-green'; $rsText = 'Accepted'; }
                        elseif ($rs === 'Declined')  { $rsBadge = 'badge-red';   $rsText = 'Declined'; }
                        else                         { $rsBadge = 'bg-slate-100 dark:bg-slate-800 text-slate-400'; $rsText = 'None'; }
                    ?>
                    <tr>
                        <td>
                            <a href="tenant_details.php?id=<?php echo $l['tenant_id']; ?>" class="font-bold text-slate-900 dark:text-white hover:text-accent-green transition-colors"><?php echo htmlspecialchars($l['tenant_name']); ?></a>
                            <div class="text-xs text-slate-400"><?php echo htmlspecialchars($l['tenant_email']); ?></div>
                        </td>
                        <td>
                            <a href="property_details.php?id=<?php echo $l['property_id']; ?>" class="font-bold hover:text-accent-green transition-colors"><?php echo htmlspecialchars($l['property_title']); ?></a>
                            <div class="text-xs text-slate-400">Unit <?php echo htmlspecialchars($l['unit_number']); ?> &middot; <?php echo htmlspecialchars($l['unit_type'] ?? ''); ?></div>
                        </td>
                        <td>
                            <div class="font-black text-slate-900 dark:text-white text-sm"><?php echo date('M j, Y', strtotime($l['end_date'])); ?></div>
                            <div class="text-xs <?php echo $d <= 30 ? 'text-red-500 font-black' : 'text-slate-400'; ?>"><?php echo $d; ?> days left</div>
                        </td>
                        <td class="font-black text-sm"><?php echo $currency; ?> <?php echo number_format($l['monthly_rent']); ?></td>
                        <td><span class="badge <?php echo $urgClass; ?>"><?php echo $urgency; ?></span></td>
                        <td>
                            <span class="badge <?php echo $rsBadge; ?>"><?php echo $rsText; ?></span>
                        </td>
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
                                <button onclick="setRenewLeaseId('<?php echo $l['id']; ?>', '<?php echo $l['monthly_rent']; ?>')" class="px-3 py-1.5 bg-accent-green/10 text-accent-green hover:bg-accent-green hover:text-white rounded-lg text-[11px] font-black uppercase transition-all whitespace-nowrap">
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

    <!-- ── Two-column: Vacant Units + Renewal Pipeline ──────────────── -->
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">

        <!-- Vacant Units -->
        <div class="glass-card overflow-hidden">
            <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <div>
                    <h3 class="font-black text-slate-900 dark:text-white">Currently Vacant</h3>
                    <p class="text-[11px] text-slate-400 mt-0.5">Units available for immediate occupancy.</p>
                </div>
                <span class="badge <?php echo $totalVacant > 0 ? 'badge-red' : 'badge-green'; ?>"><?php echo $totalVacant; ?></span>
            </div>
            <?php if (empty($vacantUnits)): ?>
            <div class="text-center py-12">
                <p class="text-slate-400 font-bold text-sm">All units are occupied.</p>
            </div>
            <?php else: ?>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php foreach ($vacantUnits as $u): ?>
                <div class="flex items-center justify-between px-6 py-4 hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 shrink-0">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V7l8-4v18"/><path d="M19 21V11l-6-4"/></svg>
                        </div>
                        <div>
                            <a href="property_details.php?id=<?php echo $u['property_id']; ?>" class="font-bold text-slate-900 dark:text-white hover:text-accent-green transition-colors text-sm"><?php echo htmlspecialchars($u['property_title']); ?></a>
                            <div class="text-xs text-slate-400">Unit <?php echo htmlspecialchars($u['unit_number']); ?> &middot; <?php echo htmlspecialchars($u['unit_type'] ?? ''); ?></div>
                        </div>
                    </div>
                    <div class="text-right shrink-0">
                        <div class="font-black text-sm text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($u['monthly_rent'] ?? 0); ?></div>
                        <div class="text-[10px] text-slate-400 uppercase font-black">per month</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Renewal Pipeline -->
        <div class="glass-card overflow-hidden">
            <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <div>
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
                    if ($rs === 'Offered')       { $dot = 'bg-blue-500';   $rsTxt = 'Offered'; }
                    elseif ($rs === 'Accepted')  { $dot = 'bg-green-500';  $rsTxt = 'Accepted'; }
                    else                         { $dot = 'bg-red-500';    $rsTxt = 'Declined'; }
                ?>
                <div class="flex items-center justify-between px-6 py-4 hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors">
                    <div class="flex items-center gap-3">
                        <span class="w-2 h-2 rounded-full <?php echo $dot; ?> shrink-0"></span>
                        <div>
                            <a href="tenant_details.php?id=<?php echo $r['tenant_id']; ?>" class="font-bold text-slate-900 dark:text-white hover:text-accent-green transition-colors text-sm"><?php echo htmlspecialchars($r['tenant_name']); ?></a>
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

    </div>
</div>

<!-- Renew Lease Modal (re-used from leases.php) -->
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
            <input type="hidden" name="redirect" value="../vacancies.php?renewed=1">
            <div>
                <label class="form-label text-xs uppercase tracking-widest font-black text-slate-400">New End Date</label>
                <input type="date" name="new_end_date" required class="form-input">
            </div>
            <div>
                <label class="form-label text-xs uppercase tracking-widest font-black text-slate-400">Monthly Rent (Optional adjustment)</label>
                <input type="number" name="new_rent" id="renew_old_rent" class="form-input">
            </div>
            <button type="submit" class="w-full py-4 bg-blue-600 hover:bg-blue-700 text-white font-black rounded-xl shadow-xl shadow-blue-500/20 transition-all">
                Confirm Renewal
            </button>
        </form>
    </div>
</div>

<?php if (isset($_GET['renewed'])): ?>
<div id="renewedToast" class="fixed bottom-6 right-6 bg-green-500 text-white px-6 py-3 rounded-2xl shadow-2xl font-black text-sm z-50 flex items-center gap-2">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
    Lease renewed successfully!
</div>
<script>setTimeout(() => document.getElementById('renewedToast')?.remove(), 4000);</script>
<?php endif; ?>

<script>
function setRenewLeaseId(id, rent) {
    document.getElementById('renew_lease_id').value = id;
    document.getElementById('renew_old_rent').value = rent;
    openModal('renewLeaseModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
