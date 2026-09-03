<?php
/**
 * Billing Run
 * Primelink Management System
 *
 * Walk a property unit by unit: see what each tenant already owes, enter this
 * month's rent, water reading and garbage, bill them, and move to the next.
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/billing_run.php';
require_once __DIR__ . '/includes/bank_accounts.php';
require_once __DIR__ . '/includes/tenant_notify.php';

ensureBillingRunSchema($pdo);

$currency  = getSetting($pdo, 'currency_symbol', 'KSh');
$dueDays   = (int)getSetting($pdo, 'invoice_due_days', '7');
$pageTitle = 'Billing Run';

$propertyId = trim($_GET['property_id'] ?? '');
$index      = max(0, (int)($_GET['i'] ?? 0));
$period     = date('Y-m');

$flashErr    = !empty($_GET['error'])  ? urldecode((string)$_GET['error'])  : '';
$flashNotice = !empty($_GET['notice']) ? urldecode((string)$_GET['notice']) : '';
$justBilled  = isset($_GET['billed'])  ? (float)$_GET['billed'] : null;
$justBatch   = trim($_GET['batch'] ?? '');
$justSkipped = isset($_GET['skipped']);

$property = $propertyId ? billingProperty($pdo, $propertyId) : null;
$tenants  = $property   ? billingRunTenants($pdo, $propertyId) : [];
$total    = count($tenants);
$done     = $property && $index >= $total;
$current  = (!$done && $total) ? $tenants[$index] : null;

// Everything needed to fill the current tenant's card
if ($current) {
    $arrears      = tenantArrearsByType($pdo, (string)$current['tenant_id']);
    $lastReading  = lastMeterReading($pdo, $current['unit_id'] ?? null);
    $alreadyDone  = billedChargesThisPeriod($pdo, (string)$current['tenant_id'], $period);
}
$progress = $property ? billingRunProgress($pdo, $propertyId, $period) : null;

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-5 animate-in">

    <?php if ($flashErr): ?>
    <div class="glass-card p-4 border-l-4 border-red-500 text-sm font-medium" style="color:var(--danger)">
        <?php echo htmlspecialchars($flashErr); ?>
    </div>
    <?php endif; ?>

<?php if (!$property): ?>
    <!-- ═══════════ STEP 1 · CHOOSE A PROPERTY ═══════════ -->
    <div>
        <h1 class="text-xl font-semibold tracking-tight" style="color:var(--text)">Billing Run</h1>
        <p class="text-[12.5px] mt-0.5" style="color:var(--text-muted)">
            Bill a property one unit at a time &mdash; arrears, water reading and this month's charges, tenant by tenant.
        </p>
    </div>

    <?php $props = billableProperties($pdo); ?>
    <?php if (!$props): ?>
    <div class="glass-card py-16 text-center">
        <p class="text-sm" style="color:var(--text-muted)">No property has active tenants to bill.</p>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        <?php foreach ($props as $p):
            $prog = billingRunProgress($pdo, (string)$p['id'], $period);
            $pct  = $prog['total'] ? round($prog['billed'] / $prog['total'] * 100) : 0;
        ?>
        <a href="?property_id=<?php echo urlencode((string)$p['id']); ?>&i=0" class="glass-card p-5 block hover:border-accent-green transition-colors">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-[14px] font-semibold truncate" style="color:var(--text)"><?php echo htmlspecialchars((string)$p['title']); ?></h2>
                    <p class="text-[11.5px] truncate" style="color:var(--text-subtle)"><?php echo htmlspecialchars((string)$p['location']); ?></p>
                </div>
                <span class="badge <?php echo $prog['remaining'] === 0 ? 'badge-green' : 'badge-orange'; ?>">
                    <?php echo $prog['billed']; ?>/<?php echo $prog['total']; ?>
                </span>
            </div>

            <div class="progress mt-3"><div class="progress-fill" style="width:<?php echo $pct; ?>%"></div></div>

            <div class="grid grid-cols-2 gap-3 mt-4 pt-3" style="border-top:1px solid var(--border)">
                <div>
                    <p class="text-[10.5px]" style="color:var(--text-subtle)">Water rate</p>
                    <p class="text-[13px] font-medium tabular" style="color:var(--text)">
                        <?php echo $currency; ?> <?php echo number_format((float)$p['water_rate'], 2); ?><span class="text-[10.5px]" style="color:var(--text-subtle)">/unit</span>
                    </p>
                </div>
                <div>
                    <p class="text-[10.5px]" style="color:var(--text-subtle)">Garbage</p>
                    <p class="text-[13px] font-medium tabular" style="color:var(--text)">
                        <?php echo $currency; ?> <?php echo number_format((float)$p['garbage_fee'], 2); ?><span class="text-[10.5px]" style="color:var(--text-subtle)">/month</span>
                    </p>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php elseif ($done): ?>
    <!-- ═══════════ RUN COMPLETE ═══════════ -->
    <div class="glass-card p-10 text-center">
        <div class="w-11 h-11 rounded-full flex items-center justify-center mx-auto mb-3"
             style="background:var(--accent-green-light);color:var(--accent-green)">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <h1 class="text-lg font-semibold" style="color:var(--text)">Run finished</h1>
        <p class="text-[12.5px] mt-1" style="color:var(--text-muted)">
            You reached the last unit on <strong style="color:var(--text)"><?php echo htmlspecialchars((string)$property['title']); ?></strong>.
            <?php echo $progress['billed']; ?> of <?php echo $progress['total']; ?> tenants have invoices for <?php echo date('F Y'); ?>.
        </p>
        <div class="flex gap-2 justify-center mt-5 flex-wrap">
            <a href="?property_id=<?php echo urlencode($propertyId); ?>&i=0" class="btn-ghost">Start again</a>
            <a href="billing_run.php" class="btn-ghost">Another property</a>
            <a href="invoices.php" class="btn-primary">View invoices</a>
        </div>
    </div>

<?php elseif (!$current): ?>
    <div class="glass-card py-16 text-center">
        <p class="text-sm" style="color:var(--text-muted)">This property has no active tenants.</p>
        <a href="billing_run.php" class="btn-ghost mt-4">Back to properties</a>
    </div>

<?php else: ?>
    <!-- ═══════════ STEP 2 · BILL THIS TENANT ═══════════ -->
    <?php
        $waterRate  = (float)$property['water_rate'];
        $garbageFee = (float)$property['garbage_fee'];
        $rentAmount = (float)($current['monthly_rent'] ?: 0);
        $pct        = $total ? round(($index / $total) * 100) : 0;
    ?>

    <!-- Run header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="min-w-0">
            <div class="flex items-center gap-2 flex-wrap">
                <a href="billing_run.php" class="text-[12.5px]" style="color:var(--text-subtle)">Billing run</a>
                <span style="color:var(--border-strong)">›</span>
                <h1 class="text-xl font-semibold tracking-tight truncate" style="color:var(--text)"><?php echo htmlspecialchars((string)$property['title']); ?></h1>
            </div>
            <p class="text-[12.5px] mt-0.5" style="color:var(--text-muted)">
                Billing for <?php echo date('F Y'); ?> ·
                water <?php echo $currency; ?> <?php echo number_format($waterRate, 2); ?>/unit ·
                garbage <?php echo $currency; ?> <?php echo number_format($garbageFee, 2); ?>/month
            </p>
        </div>
        <div class="text-right shrink-0">
            <p class="text-[11.5px]" style="color:var(--text-muted)">Tenant <?php echo $index + 1; ?> of <?php echo $total; ?></p>
            <div class="progress mt-1.5" style="width:150px"><div class="progress-fill" style="width:<?php echo $pct; ?>%"></div></div>
        </div>
    </div>

    <?php if ($justBilled !== null): ?>
    <div class="glass-card p-3.5 border-l-4 border-green-500 flex items-center justify-between gap-3 flex-wrap">
        <p class="text-[12.5px]" style="color:var(--text)">
            Previous tenant billed <strong><?php echo $currency; ?> <?php echo number_format($justBilled, 2); ?></strong>.
            <?php if ($flashNotice): ?><span style="color:var(--text-muted)">Notices: <?php echo htmlspecialchars($flashNotice); ?>.</span><?php endif; ?>
        </p>
        <?php if ($justBatch): ?>
        <a href="view_combined_invoice.php?batch_id=<?php echo urlencode($justBatch); ?>" target="_blank" class="btn-ghost" style="padding:4px 10px;font-size:11.5px;">View that invoice</a>
        <?php endif; ?>
    </div>
    <?php elseif ($justSkipped): ?>
    <div class="glass-card p-3.5 border-l-4" style="border-left-color:var(--warning)">
        <p class="text-[12.5px]" style="color:var(--text-muted)">Previous tenant skipped &mdash; no invoice raised.</p>
    </div>
    <?php endif; ?>

    <form action="actions/billing_run_actions.php" method="POST" class="space-y-4" id="billForm">
        <input type="hidden" name="action"      value="bill_tenant">
        <input type="hidden" name="property_id" value="<?php echo htmlspecialchars($propertyId); ?>">
        <input type="hidden" name="tenant_id"   value="<?php echo htmlspecialchars((string)$current['tenant_id']); ?>">
        <input type="hidden" name="lease_id"    value="<?php echo htmlspecialchars((string)$current['lease_id']); ?>">
        <input type="hidden" name="unit_id"     value="<?php echo htmlspecialchars((string)$current['unit_id']); ?>">
        <input type="hidden" name="index"       value="<?php echo $index; ?>">
        <input type="hidden" name="water_rate"  value="<?php echo htmlspecialchars((string)$waterRate); ?>">
        <input type="hidden" name="water_previous" value="<?php echo htmlspecialchars((string)$lastReading); ?>">

        <!-- Who we are billing -->
        <div class="glass-card p-5">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center text-[13px] font-semibold shrink-0"
                         style="background:var(--accent-green-light);color:var(--accent-green)">
                        <?php echo strtoupper(substr((string)$current['full_name'], 0, 1)); ?>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[15px] font-semibold truncate" style="color:var(--text)"><?php echo htmlspecialchars((string)$current['full_name']); ?></p>
                        <p class="text-[12px]" style="color:var(--text-muted)">
                            Unit <?php echo htmlspecialchars((string)$current['unit_number']); ?>
                            <?php if ($current['water_meter']): ?> · meter <?php echo htmlspecialchars((string)$current['water_meter']); ?><?php endif; ?>
                            <?php if ($current['phone']): ?> · <?php echo htmlspecialchars((string)$current['phone']); ?><?php endif; ?>
                        </p>
                    </div>
                </div>
                <div class="text-right shrink-0">
                    <p class="text-[11px]" style="color:var(--text-subtle)">Owed before this bill</p>
                    <p class="text-[17px] font-semibold tabular" style="color:<?php echo $arrears['total'] > 0 ? 'var(--danger)' : 'var(--positive)'; ?>">
                        <?php echo $currency; ?> <?php echo number_format($arrears['total'], 2); ?>
                    </p>
                </div>
            </div>

            <?php if ($alreadyDone): ?>
            <p class="text-[12px] mt-4 pt-3" style="border-top:1px solid var(--border);color:var(--warning)">
                Already invoiced this month for <strong><?php echo htmlspecialchars(implode(', ', $alreadyDone)); ?></strong>.
                Billing again will raise a second invoice.
            </p>
            <?php endif; ?>
        </div>

        <!-- Previous balance beside this month's charge -->
        <div class="glass-card overflow-hidden">
            <div class="grid items-center gap-3 px-5 py-2.5"
                 style="grid-template-columns:1fr 150px 190px;background:var(--surface-sunk);border-bottom:1px solid var(--border);">
                <span class="text-[11.5px]" style="color:var(--text-muted)">Charge</span>
                <span class="text-[11.5px] text-right" style="color:var(--text-muted)">Previous balance</span>
                <span class="text-[11.5px] text-right" style="color:var(--text-muted)">This month (<?php echo htmlspecialchars($currency); ?>)</span>
            </div>

            <!-- Rent -->
            <div class="grid items-center gap-3 px-5 py-3" style="grid-template-columns:1fr 150px 190px;border-bottom:1px solid var(--border);">
                <div>
                    <p class="text-[13px] font-medium" style="color:var(--text)">Rent</p>
                    <p class="text-[11px]" style="color:var(--text-subtle)">Lease amount <?php echo $currency; ?> <?php echo number_format($rentAmount, 2); ?></p>
                </div>
                <p class="text-right text-[13px] tabular" style="color:<?php echo $arrears['Rent'] > 0 ? 'var(--danger)' : 'var(--text-subtle)'; ?>">
                    <?php echo $arrears['Rent'] > 0 ? $currency . ' ' . number_format($arrears['Rent'], 2) : '—'; ?>
                </p>
                <input type="number" name="charge_rent" step="0.01" min="0" class="form-input text-right tabular"
                       value="<?php echo htmlspecialchars(number_format($rentAmount, 2, '.', '')); ?>" oninput="billRecalc()">
            </div>

            <!-- Water -->
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                <div class="grid items-center gap-3" style="grid-template-columns:1fr 150px 190px;">
                    <div>
                        <p class="text-[13px] font-medium" style="color:var(--text)">Water</p>
                        <p class="text-[11px]" style="color:var(--text-subtle)">
                            <?php echo $currency; ?> <?php echo number_format($waterRate, 2); ?> per unit consumed
                        </p>
                    </div>
                    <p class="text-right text-[13px] tabular" style="color:<?php echo $arrears['Water'] > 0 ? 'var(--danger)' : 'var(--text-subtle)'; ?>">
                        <?php echo $arrears['Water'] > 0 ? $currency . ' ' . number_format($arrears['Water'], 2) : '—'; ?>
                    </p>
                    <input type="number" name="charge_water" id="waterAmount" step="0.01" min="0"
                           class="form-input text-right tabular" value="0.00" oninput="billRecalc()">
                </div>

                <!-- Meter reading drives the amount above -->
                <div class="grid gap-3 mt-3 pt-3" style="grid-template-columns:1fr 1fr 1fr;border-top:1px dashed var(--border);">
                    <div>
                        <label class="text-[11px] block mb-1" style="color:var(--text-subtle)">Previous reading</label>
                        <input type="number" step="0.01" class="form-input tabular" id="prevReading" readonly
                               value="<?php echo htmlspecialchars(number_format($lastReading, 2, '.', '')); ?>"
                               style="background:var(--surface-sunk);">
                    </div>
                    <div>
                        <label class="text-[11px] block mb-1" style="color:var(--text-subtle)">Current reading</label>
                        <input type="number" step="0.01" min="0" name="water_current" id="currReading"
                               class="form-input tabular" placeholder="Enter reading" oninput="billWaterFromMeter()">
                    </div>
                    <div>
                        <label class="text-[11px] block mb-1" style="color:var(--text-subtle)">Units consumed</label>
                        <input type="text" class="form-input tabular" id="consumption" readonly value="—"
                               style="background:var(--surface-sunk);">
                    </div>
                </div>
                <p class="text-[11px] mt-2" id="waterWarn" style="color:var(--warning);display:none;"></p>
                <label class="flex items-center gap-2 mt-2.5 cursor-pointer select-none">
                    <input type="checkbox" id="waterFlat" onchange="billWaterMode(this.checked)"
                           style="width:14px;height:14px;accent-color:var(--accent-green);cursor:pointer;">
                    <span class="text-[11.5px]" style="color:var(--text-muted)">No meter &mdash; enter the water amount directly</span>
                </label>
                <input type="hidden" name="water_mode" id="waterMode" value="meter">
            </div>

            <!-- Garbage -->
            <div class="grid items-center gap-3 px-5 py-3" style="grid-template-columns:1fr 150px 190px;border-bottom:1px solid var(--border);">
                <div>
                    <p class="text-[13px] font-medium" style="color:var(--text)">Garbage</p>
                    <p class="text-[11px]" style="color:var(--text-subtle)">Property fee <?php echo $currency; ?> <?php echo number_format($garbageFee, 2); ?></p>
                </div>
                <p class="text-right text-[13px] tabular" style="color:<?php echo $arrears['Garbage'] > 0 ? 'var(--danger)' : 'var(--text-subtle)'; ?>">
                    <?php echo $arrears['Garbage'] > 0 ? $currency . ' ' . number_format($arrears['Garbage'], 2) : '—'; ?>
                </p>
                <input type="number" name="charge_garbage" step="0.01" min="0" class="form-input text-right tabular"
                       value="<?php echo htmlspecialchars(number_format($garbageFee, 2, '.', '')); ?>" oninput="billRecalc()">
            </div>

            <?php if ($arrears['Other'] > 0): ?>
            <div class="grid items-center gap-3 px-5 py-3" style="grid-template-columns:1fr 150px 190px;border-bottom:1px solid var(--border);">
                <div>
                    <p class="text-[13px] font-medium" style="color:var(--text)">Other charges</p>
                    <p class="text-[11px]" style="color:var(--text-subtle)">Not billed in this run</p>
                </div>
                <p class="text-right text-[13px] tabular" style="color:var(--danger)"><?php echo $currency; ?> <?php echo number_format($arrears['Other'], 2); ?></p>
                <p class="text-right text-[13px]" style="color:var(--text-subtle)">—</p>
            </div>
            <?php endif; ?>

            <!-- Totals -->
            <div class="grid items-center gap-3 px-5 py-3" style="grid-template-columns:1fr 150px 190px;background:var(--surface-sunk);">
                <span class="text-[12.5px] font-medium" style="color:var(--text)">Total</span>
                <span class="text-right text-[13px] font-medium tabular" style="color:var(--text-muted)"><?php echo $currency; ?> <?php echo number_format($arrears['total'], 2); ?></span>
                <span class="text-right text-[15px] font-semibold tabular" id="billTotal" style="color:var(--text)"><?php echo $currency; ?> 0.00</span>
            </div>
            <div class="px-5 py-2.5 text-right" style="border-top:1px solid var(--border);">
                <span class="text-[11.5px]" style="color:var(--text-muted)">Owing after this bill</span>
                <span class="ml-2 text-[15px] font-semibold tabular" id="billOwing" style="color:var(--danger)"></span>
            </div>
        </div>

        <!-- Invoice options -->
        <div class="glass-card p-5 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="space-y-1.5">
                <label class="text-[12px] font-medium" style="color:var(--text-muted)">Due date</label>
                <input type="date" name="due_date" class="form-input"
                       value="<?php echo date('Y-m-d', strtotime('+' . max(1, $dueDays) . ' days')); ?>">
            </div>
            <div class="space-y-1.5">
                <label class="text-[12px] font-medium" style="color:var(--text-muted)">Note on the invoice <span style="color:var(--text-subtle)">(optional)</span></label>
                <input type="text" name="description" class="form-input" placeholder="e.g. <?php echo date('F Y'); ?> charges">
            </div>
            <div class="md:col-span-2">
                <?php echo renderNotifyChannels($pdo, [
                    'email'         => $current['email'] ?? '',
                    'phone'         => $current['phone'] ?? '',
                    'email_checked' => true,
                    'sms_checked'   => true,
                ]); ?>
            </div>
        </div>

        <!-- Move through the run -->
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <div class="flex gap-2">
                <?php if ($index > 0): ?>
                <a href="?property_id=<?php echo urlencode($propertyId); ?>&i=<?php echo $index - 1; ?>" class="btn-ghost">← Previous</a>
                <?php endif; ?>
                <button type="submit" form="skipForm" class="btn-ghost">Skip this tenant</button>
            </div>
            <button type="submit" class="btn-primary" style="padding:9px 18px;">
                Bill <?php echo htmlspecialchars((string)explode(' ', trim((string)$current['full_name']))[0]); ?>
                <?php echo $index + 1 < $total ? '&amp; next →' : '&amp; finish'; ?>
            </button>
        </div>
    </form>

    <form action="actions/billing_run_actions.php" method="POST" id="skipForm" class="hidden">
        <input type="hidden" name="action"      value="skip_tenant">
        <input type="hidden" name="property_id" value="<?php echo htmlspecialchars($propertyId); ?>">
        <input type="hidden" name="index"       value="<?php echo $index; ?>">
    </form>

    <script>
    (function () {
        var CUR   = <?php echo json_encode($currency); ?>;
        var RATE  = <?php echo json_encode($waterRate); ?>;
        var PREV  = <?php echo json_encode($lastReading); ?>;
        var OWED  = <?php echo json_encode($arrears['total']); ?>;

        function money(v) {
            return CUR + ' ' + (parseFloat(v) || 0)
                .toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        function val(id) { var e = document.getElementById(id); return e ? (parseFloat(e.value) || 0) : 0; }

        window.billRecalc = function () {
            var total = 0;
            document.querySelectorAll('#billForm input[name^="charge_"]').forEach(function (i) {
                total += parseFloat(i.value) || 0;
            });
            document.getElementById('billTotal').textContent = money(total);
            document.getElementById('billOwing').textContent = money(OWED + total);
            return total;
        };

        // Consumption × rate, shown as it is typed so the reading can be checked
        window.billWaterFromMeter = function () {
            if (document.getElementById('waterMode').value !== 'meter') return;
            var curr = val('currReading');
            var warn = document.getElementById('waterWarn');
            var used, amount;

            if (document.getElementById('currReading').value === '') {
                document.getElementById('consumption').value = '—';
                document.getElementById('waterAmount').value = '0.00';
                warn.style.display = 'none';
                billRecalc();
                return;
            }

            if (curr < PREV) {
                // A meter reading backwards means it was replaced or rolled over
                used = curr;
                warn.textContent = 'Reading is below the previous one (' + PREV +
                                   '). Charging the reading itself — check the meter before billing.';
                warn.style.display = '';
            } else {
                used = curr - PREV;
                warn.style.display = 'none';
            }

            amount = used * RATE;
            document.getElementById('consumption').value = used.toLocaleString('en-KE', { maximumFractionDigits: 2 });
            document.getElementById('waterAmount').value = amount.toFixed(2);
            billRecalc();
        };

        window.billWaterMode = function (flat) {
            var amountEl = document.getElementById('waterAmount');
            document.getElementById('waterMode').value = flat ? 'amount' : 'meter';
            document.getElementById('currReading').disabled = flat;
            amountEl.readOnly = false;
            if (flat) {
                document.getElementById('consumption').value = '—';
                document.getElementById('waterWarn').style.display = 'none';
            } else {
                billWaterFromMeter();
            }
        };

        billRecalc();
    })();
    </script>
<?php endif; ?>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
