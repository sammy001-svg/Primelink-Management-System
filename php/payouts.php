<?php
/**
 * Landlord Auto-Payout Calculator
 * Primelink Management System
 *
 * Calculates per-landlord net payout for a selected period:
 *   Gross Rent Collected
 *   – Management Fee %
 *   – Maintenance Costs (cost_status = 'Paid' in period)
 *   – Property Expenses (in period)
 *   – Outstanding Advances (approved, not yet deducted)
 *   = Net Payout
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/includes/settings.php';

$pageTitle = "Payout Calculator";
$currency  = getSetting($pdo, 'currency_symbol', 'KSh');

// ── Schema self-heal ──────────────────────────────────────────────────
foreach ([
    "ALTER TABLE landlords ADD COLUMN management_fee DECIMAL(5,2) NOT NULL DEFAULT 10.00",
    "ALTER TABLE landlord_payouts ADD COLUMN gross_amount DECIMAL(15,2) NULL",
    "ALTER TABLE landlord_payouts ADD COLUMN maintenance_deduction DECIMAL(15,2) NULL DEFAULT 0",
    "ALTER TABLE landlord_payouts ADD COLUMN expense_deduction DECIMAL(15,2) NULL DEFAULT 0",
    "ALTER TABLE landlord_payouts ADD COLUMN advance_deduction DECIMAL(15,2) NULL DEFAULT 0",
    "ALTER TABLE landlord_payouts ADD COLUMN period_month TINYINT(2) NULL",
    "ALTER TABLE landlord_payouts ADD COLUMN period_year SMALLINT(4) NULL",
    "ALTER TABLE landlord_payouts ADD COLUMN payment_method VARCHAR(50) NULL",
] as $ddl) {
    try { $pdo->exec($ddl); } catch (PDOException $e) {}
}

// ── Period filter ─────────────────────────────────────────────────────
$selMonth = str_pad($_GET['month'] ?? date('m'), 2, '0', STR_PAD_LEFT);
$selYear  = (int)($_GET['year']   ?? date('Y'));

$monthNames = ['01'=>'January','02'=>'February','03'=>'March','04'=>'April',
               '05'=>'May','06'=>'June','07'=>'July','08'=>'August',
               '09'=>'September','10'=>'October','11'=>'November','12'=>'December'];

// ── Result banner ─────────────────────────────────────────────────────
$doneRef = $_GET['ref'] ?? '';
$success = $_GET['success'] ?? '';

// ── Fetch all landlords ───────────────────────────────────────────────
$landlordRows = $pdo->query("SELECT id, full_name, email, phone, management_fee, fee_type FROM landlords ORDER BY full_name")->fetchAll();

// ── Per-landlord calculations ─────────────────────────────────────────
$calcStmtRent = $pdo->prepare("
    SELECT COALESCE(SUM(tr.amount), 0)
    FROM transactions tr
    JOIN tenants ten ON tr.tenant_id = ten.id
    JOIN leases ls   ON ten.id = ls.tenant_id
    JOIN units u     ON ls.unit_id = u.id
    JOIN properties p ON u.property_id = p.id
    WHERE p.landlord_id = ?
      AND tr.status = 'Paid'
      AND MONTH(tr.transaction_date) = ?
      AND YEAR(tr.transaction_date)  = ?
");

$calcStmtMaint = $pdo->prepare("
    SELECT COALESCE(SUM(m.actual_cost), 0)
    FROM maintenance_requests m
    JOIN properties p ON m.property_id = p.id
    WHERE p.landlord_id = ?
      AND m.cost_status = 'Paid'
      AND MONTH(COALESCE(m.updated_at, m.created_at)) = ?
      AND YEAR(COALESCE(m.updated_at,  m.created_at)) = ?
");

$calcStmtExp = $pdo->prepare("
    SELECT COALESCE(SUM(e.amount), 0)
    FROM expenses e
    WHERE e.property_id IN (SELECT id FROM properties WHERE landlord_id = ?)
      AND MONTH(e.expense_date) = ?
      AND YEAR(e.expense_date)  = ?
");

$calcStmtAdv = $pdo->prepare("
    SELECT COALESCE(SUM(a.amount), 0)
    FROM landlord_advances a
    WHERE a.landlord_id = ?
      AND a.status = 'Approved'
      AND (a.is_deducted = 0 OR a.is_deducted IS NULL)
");

$calcs = [];
foreach ($landlordRows as $ll) {
    $calcStmtRent->execute([$ll['id'], $selMonth, $selYear]);
    $gross = (float)$calcStmtRent->fetchColumn();

    $calcStmtMaint->execute([$ll['id'], $selMonth, $selYear]);
    $maintDed = (float)$calcStmtMaint->fetchColumn();

    $calcStmtExp->execute([$ll['id'], $selMonth, $selYear]);
    $expDed = (float)$calcStmtExp->fetchColumn();

    $calcStmtAdv->execute([$ll['id']]);
    $advDed = (float)$calcStmtAdv->fetchColumn();

    $feeType    = $ll['fee_type'] ?? 'percentage';
    $feeSetting = (float)($ll['management_fee'] ?? 10.0);
    $feeDed     = $feeType === 'fixed' ? $feeSetting : round($gross * $feeSetting / 100, 2);
    $net        = max(0, $gross - $feeDed - $maintDed - $expDed - $advDed);

    // Has this landlord already been paid for this period?
    $paidStmt = $pdo->prepare("SELECT COUNT(*) FROM landlord_payouts WHERE landlord_id = ? AND period_month = ? AND period_year = ?");
    $paidStmt->execute([$ll['id'], (int)$selMonth, $selYear]);
    $alreadyPaid = (int)$paidStmt->fetchColumn() > 0;

    $calcs[$ll['id']] = compact('gross','feeDed','feeType','feeSetting','maintDed','expDed','advDed','net','alreadyPaid');
}

// ── Payout history ────────────────────────────────────────────────────
$payouts = $pdo->query("
    SELECT p.*, l.full_name as landlord_name
    FROM landlord_payouts p
    JOIN landlords l ON p.landlord_id = l.id
    ORDER BY p.payout_date DESC
    LIMIT 50
")->fetchAll();

$totalReleased = array_sum(array_column($payouts, 'amount'));

// ── KPI aggregates ────────────────────────────────────────────────────
$kpiYTD = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM landlord_payouts WHERE status='Completed' AND YEAR(payout_date)=YEAR(NOW())")->fetchColumn();
$kpiMTD = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM landlord_payouts WHERE status='Completed' AND YEAR(payout_date)=YEAR(NOW()) AND MONTH(payout_date)=MONTH(NOW())")->fetchColumn();
$kpiPendingAdv = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM landlord_advances WHERE status='Approved' AND (is_deducted=0 OR is_deducted IS NULL)")->fetchColumn();
$kpiActiveLandlords = (int)$pdo->query("SELECT COUNT(*) FROM landlords WHERE status='active'")->fetchColumn();

// Period totals for selected month (sum across all landlords)
$periodTotalGross = (float)array_sum(array_column(array_map(fn($l) => $calcs[$l['id']], $landlordRows), 'gross'));
$periodTotalNet   = (float)array_sum(array_column(array_map(fn($l) => $calcs[$l['id']], $landlordRows), 'net'));

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

    <!-- Page header -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Payout Calculator</h1>
            <p class="text-slate-500 dark:text-slate-400 font-medium text-sm mt-0.5">Calculate and disburse landlord payouts with automatic deductions.</p>
        </div>
        <a href="landlord_payouts.php" class="text-[10px] font-black text-slate-400 hover:text-slate-900 dark:hover:text-white uppercase tracking-widest transition-colors">Advances & Loans →</a>
    </div>

    <!-- ── KPI Strip ────────────────────────────────────────────────── -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="glass-card p-5 border-l-[3px] border-emerald-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">YTD Released</p>
            <h3 class="text-xl font-black text-emerald-500 mt-1"><?php echo $currency; ?> <?php echo number_format($kpiYTD); ?></h3>
            <p class="text-[10px] text-slate-400 font-bold"><?php echo date('Y'); ?> total</p>
        </div>
        <div class="glass-card p-5 border-l-[3px] border-blue-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">This Month</p>
            <h3 class="text-xl font-black text-blue-500 mt-1"><?php echo $currency; ?> <?php echo number_format($kpiMTD); ?></h3>
            <p class="text-[10px] text-slate-400 font-bold"><?php echo date('M Y'); ?> payouts</p>
        </div>
        <div class="glass-card p-5 border-l-[3px] border-violet-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Pending Advances</p>
            <h3 class="text-xl font-black <?php echo $kpiPendingAdv > 0 ? 'text-violet-500' : 'text-slate-400'; ?> mt-1">
                <?php echo $kpiPendingAdv > 0 ? $currency . ' ' . number_format($kpiPendingAdv) : 'None'; ?>
            </h3>
            <p class="text-[10px] text-slate-400 font-bold">to deduct</p>
        </div>
        <div class="glass-card p-5 border-l-[3px] border-orange-400">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Active Landlords</p>
            <h3 class="text-2xl font-black text-slate-900 dark:text-white mt-1"><?php echo $kpiActiveLandlords; ?></h3>
            <p class="text-[10px] text-slate-400 font-bold"><?php echo count(array_filter($calcs, fn($c) => $c['gross'] > 0)); ?> with income this period</p>
        </div>
    </div>

    <!-- ── Period totals summary ─────────────────────────────────────── -->
    <?php if ($periodTotalGross > 0): ?>
    <div class="rounded-2xl bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 p-5 flex flex-wrap gap-6 items-center justify-between">
        <div>
            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-1"><?php echo $monthNames[$selMonth]; ?> <?php echo $selYear; ?> — Period Summary</p>
            <p class="text-white font-medium text-sm">Gross rent collected across all landlords for the selected period</p>
        </div>
        <div class="flex gap-8">
            <div class="text-center">
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Gross Rent</p>
                <p class="text-xl font-black text-white"><?php echo $currency; ?> <?php echo number_format($periodTotalGross); ?></p>
            </div>
            <div class="text-center">
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Net to Landlords</p>
                <p class="text-xl font-black text-emerald-400"><?php echo $currency; ?> <?php echo number_format($periodTotalNet); ?></p>
            </div>
            <div class="text-center">
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Deductions</p>
                <p class="text-xl font-black text-orange-400"><?php echo $currency; ?> <?php echo number_format($periodTotalGross - $periodTotalNet); ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($success === 'processed' && $doneRef): ?>
    <div class="p-5 bg-green-50 dark:bg-green-900/10 border border-green-200 dark:border-green-800 rounded-2xl flex items-start gap-4">
        <div class="w-9 h-9 rounded-xl bg-green-100 dark:bg-green-900/30 flex items-center justify-center shrink-0">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="text-accent-green"><path d="M20 6 9 17l-5-5"/></svg>
        </div>
        <div>
            <p class="font-black text-slate-900 dark:text-white">Payout Processed</p>
            <p class="text-sm text-slate-500 font-medium mt-0.5">
                Reference: <span class="font-mono font-black"><?php echo htmlspecialchars($doneRef); ?></span>
                &nbsp;—&nbsp;
                <a href="view_voucher.php?ref=<?php echo urlencode($doneRef); ?>" target="_blank" class="text-accent-green font-black hover:underline">View Remittance Statement →</a>
            </p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Period selector -->
    <form method="GET" class="glass-card p-5 flex flex-wrap items-end gap-4">
        <div class="space-y-1.5 flex-1 min-w-[140px]">
            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Month</label>
            <select name="month" class="form-input w-full">
                <?php foreach ($monthNames as $val => $label): ?>
                <option value="<?php echo $val; ?>" <?php echo $selMonth === $val ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="space-y-1.5 w-28">
            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Year</label>
            <input type="number" name="year" value="<?php echo $selYear; ?>" min="2020" max="2035" class="form-input w-full">
        </div>
        <button type="submit" class="btn-primary">Recalculate</button>
    </form>

    <!-- ── Per-Landlord Breakdown ─────────────────────────────────── -->
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <div>
                <h2 class="font-black text-slate-900 dark:text-white">Payout Breakdown — <?php echo $monthNames[$selMonth]; ?> <?php echo $selYear; ?></h2>
                <p class="text-[11px] text-slate-400 font-medium mt-0.5">Gross rent collected minus management fee, maintenance, expenses, and pending advances.</p>
            </div>
        </div>

        <?php if (empty($landlordRows)): ?>
        <p class="text-center text-slate-400 font-medium py-12">No landlords registered yet.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr>
                    <th>Landlord</th>
                    <th class="text-right">Gross Rent</th>
                    <th class="text-right">Mgmt Fee</th>
                    <th class="text-right">Maintenance</th>
                    <th class="text-right">Expenses</th>
                    <th class="text-right">Advances</th>
                    <th class="text-right">Net Payout</th>
                    <th class="text-right">Action</th>
                </tr></thead>
                <tbody>
                <?php foreach ($landlordRows as $ll):
                    $c = $calcs[$ll['id']];
                    $hasActivity = $c['gross'] > 0 || $c['maintDed'] > 0 || $c['expDed'] > 0;
                ?>
                <tr class="<?php echo !$hasActivity ? 'opacity-40' : ''; ?>">
                    <td>
                        <p class="font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($ll['full_name']); ?></p>
                        <p class="text-[11px] text-slate-400">
                            <?php echo $c['feeType'] === 'fixed'
                                ? $currency . ' ' . number_format($c['feeSetting']) . ' fixed mgmt fee'
                                : $c['feeSetting'] . '% mgmt fee'; ?>
                        </p>
                    </td>
                    <td class="text-right font-black text-slate-900 dark:text-white">
                        <?php echo $c['gross'] > 0 ? $currency . ' ' . number_format($c['gross']) : '—'; ?>
                    </td>
                    <td class="text-right font-bold text-orange-500">
                        <?php echo $c['feeDed'] > 0 ? '– ' . $currency . ' ' . number_format($c['feeDed']) : '—'; ?>
                    </td>
                    <td class="text-right font-bold text-red-400">
                        <?php echo $c['maintDed'] > 0 ? '– ' . $currency . ' ' . number_format($c['maintDed']) : '—'; ?>
                    </td>
                    <td class="text-right font-bold text-red-400">
                        <?php echo $c['expDed'] > 0 ? '– ' . $currency . ' ' . number_format($c['expDed']) : '—'; ?>
                    </td>
                    <td class="text-right font-bold text-purple-500">
                        <?php echo $c['advDed'] > 0 ? '– ' . $currency . ' ' . number_format($c['advDed']) : '—'; ?>
                    </td>
                    <td class="text-right font-black <?php echo $c['net'] > 0 ? 'text-accent-green' : 'text-slate-400'; ?>">
                        <?php echo $currency; ?> <?php echo number_format($c['net']); ?>
                    </td>
                    <td class="text-right">
                        <?php if ($c['alreadyPaid']): ?>
                        <span class="badge badge-green">Paid</span>
                        <?php elseif ($c['net'] > 0): ?>
                        <button onclick="openPayoutModal(<?php echo htmlspecialchars(json_encode([
                            'id'      => $ll['id'],
                            'name'    => $ll['full_name'],
                            'gross'   => $c['gross'],
                            'feeDed'  => $c['feeDed'],
                            'feeType'    => $c['feeType'],
                            'feeSetting' => $c['feeSetting'],
                            'maint'   => $c['maintDed'],
                            'exp'     => $c['expDed'],
                            'adv'     => $c['advDed'],
                            'net'     => $c['net'],
                            'month'   => $selMonth,
                            'year'    => $selYear,
                        ])); ?>)"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-accent-green text-white rounded-lg text-[10px] font-black uppercase tracking-widest hover:scale-105 transition-all shadow-md shadow-accent-green/20">
                            Process
                        </button>
                        <?php else: ?>
                        <span class="text-slate-300 dark:text-slate-700 text-[11px] font-bold">No income</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Payout History ──────────────────────────────────────────── -->
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
            <h2 class="font-black text-slate-900 dark:text-white">Payout History</h2>
            <span class="text-[11px] font-black text-accent-green"><?php echo $currency; ?> <?php echo number_format($totalReleased); ?> total released</span>
        </div>
        <?php if (empty($payouts)): ?>
        <p class="text-center text-slate-400 font-medium text-sm py-10">No payouts recorded yet.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead><tr>
                    <th>Date</th>
                    <th>Landlord</th>
                    <th>Period</th>
                    <th>Reference</th>
                    <th class="text-right">Net Amount</th>
                    <th class="text-right">Voucher</th>
                </tr></thead>
                <tbody>
                <?php foreach ($payouts as $po): ?>
                <tr>
                    <td class="text-slate-500 font-medium text-sm"><?php echo date('d M Y', strtotime($po['payout_date'])); ?></td>
                    <td class="font-black text-slate-900 dark:text-white"><?php echo htmlspecialchars($po['landlord_name']); ?></td>
                    <td class="text-slate-500 text-sm font-medium">
                        <?php if (!empty($po['period_month']) && !empty($po['period_year'])): ?>
                        <?php echo $monthNames[str_pad($po['period_month'], 2, '0', STR_PAD_LEFT)] ?? ''; ?> <?php echo $po['period_year']; ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><span class="font-mono text-[11px] font-black px-2 py-1 bg-slate-100 dark:bg-slate-800 rounded-lg"><?php echo htmlspecialchars($po['reference_code']); ?></span></td>
                    <td class="text-right font-black text-accent-green"><?php echo $currency; ?> <?php echo number_format($po['amount']); ?></td>
                    <td class="text-right">
                        <a href="view_voucher.php?ref=<?php echo urlencode($po['reference_code']); ?>" target="_blank"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-900 dark:hover:bg-white hover:text-white dark:hover:text-slate-900 rounded-lg text-[10px] font-black uppercase tracking-widest transition-all">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            Voucher
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /animate-in -->

<!-- ── Process Payout Modal ───────────────────────────────────────── -->
<div id="payoutModal" class="modal-overlay" style="display:none;">
    <div class="modal-card max-w-lg">
        <button onclick="closeModal('payoutModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <h2 class="text-2xl font-black mb-1">Process Payout</h2>
        <p id="payoutModalName" class="text-slate-500 font-medium text-sm mb-6"></p>

        <!-- Breakdown summary -->
        <div id="payoutBreakdown" class="mb-6 space-y-2 p-4 bg-slate-50 dark:bg-slate-900 rounded-2xl text-sm font-bold"></div>

        <form action="actions/payout_actions.php" method="POST" class="space-y-5">
            <input type="hidden" name="action" value="process_payout">
            <input type="hidden" name="landlord_id" id="pModalLandlordId">
            <input type="hidden" name="gross_amount" id="pModalGross">
            <input type="hidden" name="fee_deducted" id="pModalFee">
            <input type="hidden" name="maintenance_deduction" id="pModalMaint">
            <input type="hidden" name="expense_deduction" id="pModalExp">
            <input type="hidden" name="advance_deduction" id="pModalAdv">
            <input type="hidden" name="net_amount" id="pModalNet">
            <input type="hidden" name="period_month" id="pModalMonth">
            <input type="hidden" name="period_year" id="pModalYear">

            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Payment Method</label>
                <select name="method" class="form-input w-full">
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="M-Pesa">M-Pesa</option>
                    <option value="Cheque">Cheque</option>
                    <option value="Cash">Cash</option>
                </select>
            </div>

            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-1">Notes (optional)</label>
                <textarea name="notes" rows="2" placeholder="Any remittance note..." class="form-input w-full resize-none"></textarea>
            </div>

            <button type="submit" class="btn-green w-full justify-center py-4">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                Confirm & Process
            </button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
function openPayoutModal(data) {
    const cur = '<?php echo addslashes($currency); ?>';
    document.getElementById('payoutModalName').textContent  = data.name;
    document.getElementById('pModalLandlordId').value = data.id;
    document.getElementById('pModalGross').value      = data.gross;
    document.getElementById('pModalFee').value        = data.feeDed;
    document.getElementById('pModalMaint').value      = data.maint;
    document.getElementById('pModalExp').value        = data.exp;
    document.getElementById('pModalAdv').value        = data.adv;
    document.getElementById('pModalNet').value        = data.net;
    document.getElementById('pModalMonth').value      = data.month;
    document.getElementById('pModalYear').value       = data.year;

    const fmt = n => cur + ' ' + n.toLocaleString('en-KE', {minimumFractionDigits: 0, maximumFractionDigits: 0});
    const rows = [
        ['Gross Rent Collected', fmt(data.gross), 'text-slate-900 dark:text-white'],
        ['Management Fee (' + (data.feeType === 'fixed' ? 'Fixed' : data.feeSetting + '%') + ')', '– ' + fmt(data.feeDed), 'text-orange-500'],
    ];
    if (data.maint > 0) rows.push(['Maintenance Costs', '– ' + fmt(data.maint), 'text-red-400']);
    if (data.exp > 0)   rows.push(['Property Expenses', '– ' + fmt(data.exp),   'text-red-400']);
    if (data.adv > 0)   rows.push(['Pending Advances',  '– ' + fmt(data.adv),   'text-purple-500']);
    rows.push(['Net Payout', fmt(data.net), 'text-accent-green font-black text-base border-t border-slate-200 dark:border-slate-700 pt-2 mt-1']);

    document.getElementById('payoutBreakdown').innerHTML = rows.map(([label, val, cls]) =>
        `<div class="flex justify-between items-center ${cls}"><span class="text-slate-500 font-medium">${label}</span><span class="${cls}">${val}</span></div>`
    ).join('');

    openModal('payoutModal');
}
</script>
