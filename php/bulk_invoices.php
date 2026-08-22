<?php
/**
 * Bulk Invoice Generator — Primelink Management System
 * Admin/staff select month, year, type, optional property, and amount;
 * preview which leases will be billed; then confirm to generate in batch.
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/tenant_notify.php';

$pageTitle   = "Bulk Invoice Generator";
$currency    = getSetting($pdo, 'currency_symbol', 'KSh');
$dueDays     = (int)getSetting($pdo, 'invoice_due_days', '7');
$invPrefix   = getSetting($pdo, 'invoice_prefix', 'INV');

// ── Result banner from action handler ────────────────────────────────
$done      = isset($_GET['done']);
$generated = (int)($_GET['generated'] ?? 0);
$skipped   = (int)($_GET['skipped']   ?? 0);

// ── Filter params (GET for preview, POST already handled by action) ──
$selMonth    = str_pad($_GET['month']       ?? date('m'), 2, '0', STR_PAD_LEFT);
$selYear     = (int)($_GET['year']          ?? date('Y'));
$selType     = $_GET['type']                ?? 'Rent';
$selProperty = $_GET['property_id']         ?? 'all';
$selAmount   = trim($_GET['amount']         ?? '');
$isPreviewing = isset($_GET['preview']);

// Valid invoice types
$invoiceTypes = ['Rent', 'Water', 'Garbage', 'Electricity', 'Deposit', 'Service Charge'];

// ── Properties list ───────────────────────────────────────────────────
$properties = $pdo->query("SELECT id, title FROM properties ORDER BY title")->fetchAll();

// ── Default due date ─────────────────────────────────────────────────
$defaultDue = date('Y-m-d', strtotime("+{$dueDays} days",
    mktime(0, 0, 0, (int)$selMonth, 1, $selYear)));

// ── Build preview query ───────────────────────────────────────────────
$previewRows = [];
$willGenerate = 0;
$willSkip     = 0;

if ($isPreviewing) {
    $propFilter = '';
    $propParams = [$selType, $selMonth, $selYear];

    if ($selProperty !== 'all') {
        $propFilter   = 'AND p.id = ?';
        $propParams[] = $selProperty;
    }

    $sql = "
        SELECT
            l.id          AS lease_id,
            l.tenant_id,
            l.monthly_rent,
            t.full_name,
            t.email,
            p.title       AS property_title,
            p.id          AS property_id,
            u.unit_number,
            (
                SELECT COUNT(*) FROM invoices i
                WHERE i.tenant_id = l.tenant_id
                  AND i.lease_id  = l.id
                  AND i.invoice_type = ?
                  AND MONTH(i.created_at) = ?
                  AND YEAR(i.created_at)  = ?
                  AND i.status != 'Cancelled'
            ) AS already_billed
        FROM leases l
        JOIN tenants    t  ON l.tenant_id    = t.id
        JOIN units      u  ON l.unit_id      = u.id
        JOIN properties p  ON u.property_id  = p.id
        WHERE l.status = 'Active'
          AND t.status = 'Active'
          $propFilter
        ORDER BY p.title, u.unit_number
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($propParams);
    $previewRows = $stmt->fetchAll();

    foreach ($previewRows as $row) {
        if ($row['already_billed'] > 0) $willSkip++;
        else $willGenerate++;
    }
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';

$monthNames = ['01'=>'January','02'=>'February','03'=>'March','04'=>'April',
               '05'=>'May','06'=>'June','07'=>'July','08'=>'August',
               '09'=>'September','10'=>'October','11'=>'November','12'=>'December'];

$typeColors = [
    'Rent'           => 'badge-blue',
    'Water'          => 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/30 dark:text-cyan-400',
    'Garbage'        => 'badge-orange',
    'Electricity'    => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
    'Deposit'        => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400',
    'Service Charge' => 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
];
$selTypeBadge = $typeColors[$selType] ?? 'badge';
?>

<div class="space-y-8 animate-in">

    <!-- Page header -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Bulk Invoice Generator</h1>
            <p class="text-slate-500 dark:text-slate-400 font-medium text-sm mt-0.5">Generate invoices for all active leases in one batch run.</p>
        </div>
        <a href="tenant_payments.php" class="text-[10px] font-black text-slate-400 hover:text-slate-900 dark:hover:text-white uppercase tracking-widest transition-colors">← Tenant Payments</a>
    </div>

    <?php if ($done): ?>
    <div class="p-5 rounded-2xl border <?php echo $generated > 0 ? 'bg-green-50 dark:bg-green-900/10 border-green-200 dark:border-green-800' : 'bg-slate-50 dark:bg-slate-900 border-slate-200 dark:border-slate-800'; ?> flex items-start gap-4">
        <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0 <?php echo $generated > 0 ? 'bg-green-100 dark:bg-green-900/30' : 'bg-slate-100 dark:bg-slate-800'; ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="<?php echo $generated > 0 ? 'text-accent-green' : 'text-slate-400'; ?>"><path d="M20 6 9 17l-5-5"/></svg>
        </div>
        <div>
            <p class="font-black text-slate-900 dark:text-white">Batch Complete</p>
            <p class="text-sm text-slate-500 font-medium mt-0.5">
                <?php echo $generated; ?> invoice<?php echo $generated !== 1 ? 's' : ''; ?> generated<?php if ($skipped > 0): ?>, <?php echo $skipped; ?> skipped (already billed)<?php endif; ?>.
            </p>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Filter form ─────────────────────────────────────────────── -->
    <div class="glass-card p-6">
        <h2 class="font-black text-slate-900 dark:text-white mb-6">Configure Batch</h2>
        <form method="GET" action="bulk_invoices.php">
            <input type="hidden" name="preview" value="1">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">

                <!-- Month -->
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Billing Month</label>
                    <select name="month" class="form-input w-full">
                        <?php foreach ($monthNames as $val => $label): ?>
                        <option value="<?php echo $val; ?>" <?php echo $selMonth === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Year -->
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Year</label>
                    <input type="number" name="year" value="<?php echo $selYear; ?>" min="2020" max="2035"
                           class="form-input w-full">
                </div>

                <!-- Invoice Type -->
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Invoice Type</label>
                    <select name="type" id="typeSelect" class="form-input w-full" onchange="toggleAmountField(this.value)">
                        <?php foreach ($invoiceTypes as $t): ?>
                        <option value="<?php echo $t; ?>" <?php echo $selType === $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Property -->
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Property</label>
                    <select name="property_id" class="form-input w-full">
                        <option value="all" <?php echo $selProperty === 'all' ? 'selected' : ''; ?>>All Properties</option>
                        <?php foreach ($properties as $p): ?>
                        <option value="<?php echo $p['id']; ?>" <?php echo $selProperty === $p['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['title']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Amount (hidden for Rent — uses lease monthly_rent) -->
                <div class="space-y-2" id="amountField" <?php echo $selType === 'Rent' ? 'style="display:none"' : ''; ?>>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Amount (<?php echo $currency; ?>)</label>
                    <input type="number" name="amount" value="<?php echo htmlspecialchars($selAmount); ?>"
                           min="0" step="0.01" placeholder="e.g. 500"
                           class="form-input w-full">
                    <p class="text-[10px] text-slate-400 px-1 font-medium">Applied uniformly to all selected leases.</p>
                </div>

                <!-- Due date info -->
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Default Due Date</label>
                    <div class="form-input w-full bg-slate-50 dark:bg-slate-900 text-slate-500 font-bold cursor-default select-none">
                        <?php echo date('d M Y', strtotime($defaultDue)); ?>
                        <span class="text-[10px] font-medium">(<?php echo $dueDays; ?>d from 1st of month)</span>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex justify-end">
                <button type="submit" class="btn-primary">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    Preview Batch
                </button>
            </div>
        </form>
    </div>

    <!-- ── Preview Table ───────────────────────────────────────────── -->
    <?php if ($isPreviewing): ?>
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <h2 class="font-black text-slate-900 dark:text-white">Batch Preview</h2>
                <span class="badge <?php echo $selTypeBadge; ?>"><?php echo $selType; ?></span>
                <span class="badge"><?php echo $monthNames[$selMonth]; ?> <?php echo $selYear; ?></span>
            </div>
            <div class="flex items-center gap-4 text-sm">
                <?php if ($willGenerate > 0): ?>
                <span class="flex items-center gap-1.5 font-black text-accent-green">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg>
                    <?php echo $willGenerate; ?> to generate
                </span>
                <?php endif; ?>
                <?php if ($willSkip > 0): ?>
                <span class="flex items-center gap-1.5 font-black text-slate-400">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    <?php echo $willSkip; ?> already billed
                </span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($previewRows)): ?>
        <p class="text-center text-slate-400 font-medium text-sm py-12">No active leases found for the selected filters.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr>
                    <th>Tenant</th>
                    <th>Property / Unit</th>
                    <th class="text-right">Amount</th>
                    <th class="text-center">Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($previewRows as $row):
                    $isSkipped = $row['already_billed'] > 0;
                    $amount = ($selType === 'Rent') ? $row['monthly_rent'] : (float)$selAmount;
                ?>
                <tr class="<?php echo $isSkipped ? 'opacity-50' : ''; ?>">
                    <td>
                        <p class="font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($row['full_name']); ?></p>
                        <p class="text-[11px] text-slate-400"><?php echo htmlspecialchars($row['email'] ?? ''); ?></p>
                    </td>
                    <td class="text-sm text-slate-500 font-medium">
                        <?php echo htmlspecialchars($row['property_title']); ?>
                        <span class="text-[11px]">/ Unit <?php echo htmlspecialchars($row['unit_number']); ?></span>
                    </td>
                    <td class="text-right font-black <?php echo $isSkipped ? 'text-slate-400' : 'text-slate-900 dark:text-white'; ?>">
                        <?php if ($selType === 'Rent'): ?>
                            <?php echo $currency; ?> <?php echo number_format($row['monthly_rent']); ?>
                        <?php elseif ($amount > 0): ?>
                            <?php echo $currency; ?> <?php echo number_format($amount); ?>
                        <?php else: ?>
                            <span class="text-slate-300 dark:text-slate-700">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($isSkipped): ?>
                        <span class="badge badge-orange">Already Billed</span>
                        <?php else: ?>
                        <span class="badge badge-green">Will Generate</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Confirm form -->
        <?php if ($willGenerate > 0): ?>
        <div class="p-6 border-t border-slate-100 dark:border-slate-800 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <p class="text-sm font-bold text-slate-600 dark:text-slate-300">
                This will create <span class="text-accent-green font-black"><?php echo $willGenerate; ?></span> invoice<?php echo $willGenerate !== 1 ? 's' : ''; ?> due on <span class="font-black"><?php echo date('d M Y', strtotime($defaultDue)); ?></span>.
                <?php if ($willSkip > 0): ?><?php echo $willSkip; ?> already-billed lease<?php echo $willSkip !== 1 ? 's' : ''; ?> will be skipped.<?php endif; ?>
            </p>
            <form method="POST" action="actions/bulk_invoice_actions.php" class="w-full sm:w-auto sm:min-w-[340px] space-y-4">
                <input type="hidden" name="month"       value="<?php echo $selMonth; ?>">
                <input type="hidden" name="year"        value="<?php echo $selYear; ?>">
                <input type="hidden" name="type"        value="<?php echo htmlspecialchars($selType); ?>">
                <input type="hidden" name="property_id" value="<?php echo htmlspecialchars($selProperty); ?>">
                <input type="hidden" name="amount"      value="<?php echo htmlspecialchars($selAmount); ?>">
                <input type="hidden" name="due_date"    value="<?php echo $defaultDue; ?>">
                <?php
                echo renderNotifyChannels($pdo, [
                    'recipients'    => $willGenerate,
                    'email_checked' => true,
                    'sms_checked'   => true,
                    'sms_preview'   => 'Dear Tenant, your ' . $selType . ' invoice INV-XXXXXXXX of '
                                     . $currency . ' 00,000.00 is due on 00 Xxx 0000.'
                                     . smsPaymentLine($pdo, 'A12') . ' - ' . smsSignature($pdo),
                ]);
                ?>
                <button type="submit"
                    class="w-full px-6 py-3 bg-accent-green text-white rounded-2xl font-black text-xs uppercase tracking-widest shadow-lg hover:shadow-xl transition-all flex items-center justify-center gap-2">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    Generate <?php echo $willGenerate; ?> Invoice<?php echo $willGenerate !== 1 ? 's' : ''; ?>
                </button>
            </form>
        </div>
        <?php else: ?>
        <div class="p-6 border-t border-slate-100 dark:border-slate-800 text-center">
            <p class="text-sm font-bold text-slate-500">All <?php echo count($previewRows); ?> active leases have already been billed for <?php echo $monthNames[$selMonth]; ?> <?php echo $selYear; ?> (<?php echo $selType; ?>).</p>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div><!-- /animate-in -->

<script>
function toggleAmountField(type) {
    const field = document.getElementById('amountField');
    field.style.display = type === 'Rent' ? 'none' : '';
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
