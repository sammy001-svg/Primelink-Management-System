<?php
/**
 * Tenant Statement of Account
 * Primelink Management System
 *
 * Normal mode:  in-app ledger with period filter
 * ?print=1:     standalone printable HTML document (no sidebar)
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/statement.php';

$user      = getCurrentUser($pdo);
$role      = $_SESSION['role'] ?? 'tenant';
$tenantId  = $_GET['tenant_id'] ?? '';
$printMode = isset($_GET['print']) && $_GET['print'] === '1';

// Period filter
$period     = $_GET['period'] ?? 'all';
$customFrom = trim($_GET['from'] ?? '');
$customTo   = trim($_GET['to'] ?? '');

// SECURITY: Tenants can only see their own statement
if ($role === 'tenant') {
    $stmt = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $ownTenant = $stmt->fetch();
    $tenantId  = $ownTenant['id'] ?? null;
}

if (!$tenantId) {
    header("Location: " . ($role === 'tenant' ? "financials.php" : "tenant_payments.php"));
    exit();
}

// --- Resolve date range ---
$dateFrom = null;
$dateTo   = date('Y-m-d');

switch ($period) {
    case 'this_month':
        $dateFrom = date('Y-m-01');
        break;
    case 'last_month':
        $dateFrom = date('Y-m-01', strtotime('first day of last month'));
        $dateTo   = date('Y-m-t',  strtotime('last day of last month'));
        break;
    case 'last_3m':
        $dateFrom = date('Y-m-d', strtotime('-3 months'));
        break;
    case 'this_year':
        $dateFrom = date('Y-01-01');
        break;
    case 'custom':
        $dateFrom = $customFrom ?: null;
        $dateTo   = $customTo   ?: date('Y-m-d');
        break;
}

// --- Fetch tenant + property info ---
$stmt = $pdo->prepare("
    SELECT t.*, u.unit_number, p.title as property_title, l.id as lease_id, l.monthly_rent, l.start_date
    FROM tenants t
    LEFT JOIN leases l ON t.id = l.tenant_id AND l.status = 'Active'
    LEFT JOIN units u  ON l.unit_id = u.id
    LEFT JOIN properties p ON u.property_id = p.id
    WHERE t.id = ?
");
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch();
if (!$tenant) die("Tenant not found.");

// --- Company settings ---
$currency       = getSetting($pdo, 'currency_symbol', 'KSh');
$companyName    = getSetting($pdo, 'company_name',    'Primelink Management System');
$companyEmail   = getSetting($pdo, 'company_email',   '');
$companyPhone   = getSetting($pdo, 'company_phone',   '');
$companyAddr    = getSetting($pdo, 'company_address',  '');
$companyTagline = getSetting($pdo, 'company_tagline',  'Premium Property Management');
$invoiceFooter  = getSetting($pdo, 'invoice_footer',   '');

// --- Statement reference ---
$stmtMonthKey = ($period === 'last_month') ? date('Ym', strtotime('last month')) : date('Ym');
$stmtNumber   = 'STMT-' . $stmtMonthKey . '-' . strtoupper(substr(str_replace('-', '', $tenantId), 0, 6));

$periodLabels = [
    'all'        => 'All Time',
    'this_month' => date('F Y'),
    'last_month' => date('F Y', strtotime('last month')),
    'last_3m'    => 'Last 3 Months',
    'this_year'  => 'Year ' . date('Y'),
    'custom'     => ($dateFrom ? date('d M Y', strtotime($dateFrom)) : 'Start') . ' to ' . date('d M Y', strtotime($dateTo)),
];
$periodLabel = $periodLabels[$period] ?? 'All Time';

// --- Build ledger ---
// The shared module carries a brought-forward balance into a filtered period,
// so the running balance is right even when earlier rows are out of range.
$statement = statementLedger($pdo, $tenantId, $dateFrom, $dateTo);
$ageing    = statementAgeing($pdo, $tenantId);

$openingBal = $statement['opening'];

// Shape kept as the existing markup expects it
$ledger = array_map(fn($r) => $r + [
    'type'  => $r['kind'] === 'charge' ? 'Invoice' : 'Payment',
    'title' => $r['title'],
], $statement['rows']);

$totalDebit   = $statement['charged'];
$totalCredit  = $statement['paid'];
$balance      = $statement['closing'];
$overdueCount = count(array_filter($ledger, fn($i) => $i['type'] === 'Invoice' && ($i['status'] ?? '') === 'Overdue'));


// =========================================================
//  PRINT MODE — standalone printable document
// =========================================================
if ($printMode) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statement — <?php echo htmlspecialchars($tenant['full_name']); ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; color: #1e293b; background: white; }
        .page { width: 210mm; min-height: 297mm; margin: 0 auto; padding: 18mm 18mm 14mm; }

        /* ---- Header ---- */
        .doc-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; padding-bottom: 18px; border-bottom: 3px solid #0f172a; }
        .co-name    { font-size: 19pt; font-weight: 900; color: #0f172a; letter-spacing: -0.5px; }
        .co-tag     { font-size: 7.5pt; color: #64748b; margin-top: 2px; letter-spacing: 1.5px; text-transform: uppercase; }
        .co-contact { font-size: 8pt; color: #64748b; line-height: 1.8; margin-top: 5px; }
        .si-title   { font-size: 15pt; font-weight: 900; color: #0f172a; text-align: right; }
        .si-ref     { font-size: 8pt; color: #64748b; text-align: right; margin-top: 3px; }
        .si-period  { font-size: 9pt; font-weight: 700; color: #16a34a; text-align: right; margin-top: 2px; }

        /* ---- Tenant + Balance row ---- */
        .tb-row { display: flex; gap: 18px; margin: 18px 0; }
        .tb-info { flex: 2; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 13px 16px; }
        .tb-info h4 { font-size: 7pt; font-weight: 900; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; }
        .tb-info p  { font-size: 11pt; font-weight: 700; color: #0f172a; }
        .tb-info p.sub { font-size: 8pt; font-weight: 400; color: #64748b; margin-top: 2px; }
        .tb-bal { background: #0f172a; color: white; padding: 13px 16px; border-radius: 8px; text-align: right; min-width: 190px; }
        .tb-bal h4 { font-size: 7pt; font-weight: 900; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; margin-bottom: 5px; }
        .tb-bal p.amt { font-size: 20pt; font-weight: 900; }
        .amt-due   { color: #f87171; }
        .amt-clear { color: #4ade80; }
        .tb-bal p.ovd { font-size: 8pt; color: #fca5a5; margin-top: 4px; }

        /* ---- Summary row ---- */
        .sum-row { display: flex; gap: 14px; margin: 14px 0; }
        .sum-card { flex: 1; padding: 11px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
        .sum-card h5 { font-size: 7pt; font-weight: 900; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
        .sum-card p  { font-size: 12pt; font-weight: 900; }
        .c-dark  { color: #0f172a; }
        .c-green { color: #16a34a; }
        .c-red   { color: #dc2626; }

        /* ---- Ledger table ---- */
        table { width: 100%; border-collapse: collapse; margin: 14px 0; font-size: 9pt; }
        thead { background: #0f172a; color: white; }
        thead th { padding: 9px 10px; font-size: 7pt; font-weight: 900; text-transform: uppercase; letter-spacing: 0.8px; }
        thead th.r { text-align: right; }
        tbody tr { border-bottom: 1px solid #f1f5f9; }
        tbody tr.overdue-row { background: #fff8f8; }
        tbody td { padding: 8px 10px; vertical-align: top; }
        .date-cell { font-weight: 700; color: #0f172a; white-space: nowrap; }
        .desc-main { font-weight: 700; color: #1e293b; }
        .desc-sub  { font-size: 7.5pt; color: #94a3b8; display: block; margin-top: 2px; }
        .badge { display: inline-block; padding: 2px 7px; border-radius: 100px; font-size: 7pt; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px; }
        .b-inv { background: #eff6ff; color: #3b82f6; }
        .b-pay { background: #f0fdf4; color: #16a34a; }
        .b-ovd { background: #fef2f2; color: #dc2626; }
        .r { text-align: right; }
        .fw-b { font-weight: 900; }
        tfoot td { padding: 10px 10px; font-weight: 900; font-size: 9.5pt; border-top: 2px solid #e2e8f0; background: #f8fafc; }

        /* ---- Footer ---- */
        .doc-note { margin-top: 14px; padding: 10px 12px; background: #f8fafc; border-radius: 6px; font-size: 8pt; color: #64748b; }
        .doc-footer { margin-top: 24px; padding-top: 14px; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: flex-end; }
        .doc-footer p { font-size: 7.5pt; color: #94a3b8; }
        .sig-block { text-align: right; }
        .sig-block .sig-note { font-size: 7pt; color: #94a3b8; margin-bottom: 30px; }
        .sig-line { border-top: 1px solid #0f172a; padding-top: 4px; font-size: 7.5pt; font-weight: 700; color: #0f172a; width: 160px; margin-left: auto; }

        /* ---- Print / Screen tweaks ---- */
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .page { width: 100%; padding: 10mm 14mm; }
            .no-print { display: none !important; }
        }
        @media screen {
            body { background: #f1f5f9; }
            .page { border: 1px solid #e2e8f0; box-shadow: 0 4px 24px rgba(0,0,0,0.10); margin: 24px auto; }
            .action-bar { position: fixed; top: 0; left: 0; right: 0; background: #0f172a; padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; z-index: 100; }
            .action-bar p { color: #94a3b8; font-size: 12px; }
            .btn { padding: 9px 20px; background: #22c55e; color: white; border: none; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; text-decoration: none; }
            .btn-out { background: transparent; color: #cbd5e1; border: 1px solid #334155; margin-right: 8px; }
            body { padding-top: 54px; }
        }
    </style>
</head>
<body>
    <div class="action-bar no-print">
        <p><?php echo htmlspecialchars($stmtNumber); ?> &mdash; <?php echo htmlspecialchars($periodLabel); ?></p>
        <div>
            <a href="view_statement.php?tenant_id=<?php echo urlencode((string)$tenantId); ?>&period=<?php echo urlencode($period); ?>&from=<?php echo urlencode($customFrom); ?>&to=<?php echo urlencode($customTo); ?>" class="btn btn-out">← Back</a>
            <button onclick="window.print()" class="btn">Print / Save PDF</button>
        </div>
    </div>

    <div class="page">

        <!-- Header -->
        <div class="doc-header">
            <div>
                <div class="co-name"><?php echo htmlspecialchars($companyName); ?></div>
                <div class="co-tag"><?php echo htmlspecialchars($companyTagline); ?></div>
                <div class="co-contact">
                    <?php if ($companyAddr): ?><?php echo htmlspecialchars($companyAddr); ?><br><?php endif; ?>
                    <?php if ($companyEmail): ?><?php echo htmlspecialchars($companyEmail); ?><?php endif; ?>
                    <?php if ($companyPhone): ?> &bull; <?php echo htmlspecialchars($companyPhone); ?><?php endif; ?>
                </div>
            </div>
            <div>
                <div class="si-title">STATEMENT OF ACCOUNT</div>
                <div class="si-ref">Ref: <?php echo htmlspecialchars($stmtNumber); ?></div>
                <div class="si-ref">Generated: <?php echo date('d M Y, H:i'); ?></div>
                <div class="si-period">Period: <?php echo htmlspecialchars($periodLabel); ?></div>
            </div>
        </div>

        <!-- Tenant + Balance -->
        <div class="tb-row">
            <div class="tb-info">
                <h4>Account Holder</h4>
                <p><?php echo htmlspecialchars($tenant['full_name']); ?></p>
                <?php if (!empty($tenant['phone'])): ?>
                <p class="sub"><?php echo htmlspecialchars($tenant['phone']); ?></p>
                <?php endif; ?>
                <?php if (!empty($tenant['property_title'])): ?>
                <p class="sub" style="margin-top:5px;">
                    <?php echo htmlspecialchars($tenant['property_title']); ?> &mdash; Unit <?php echo htmlspecialchars($tenant['unit_number'] ?? ''); ?>
                </p>
                <?php endif; ?>
            </div>
            <div class="tb-bal">
                <h4>Current Balance</h4>
                <p class="amt <?php echo $balance > 0 ? 'amt-due' : 'amt-clear'; ?>">
                    <?php echo htmlspecialchars($currency); ?> <?php echo number_format($balance); ?>
                </p>
                <?php if ($overdueCount > 0): ?>
                <p class="ovd"><?php echo $overdueCount; ?> overdue invoice<?php echo $overdueCount !== 1 ? 's' : ''; ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Summary -->
        <div class="sum-row">
            <div class="sum-card">
                <h5>Total Charged</h5>
                <p class="c-dark"><?php echo htmlspecialchars($currency); ?> <?php echo number_format($totalDebit); ?></p>
            </div>
            <div class="sum-card">
                <h5>Total Paid</h5>
                <p class="c-green"><?php echo htmlspecialchars($currency); ?> <?php echo number_format($totalCredit); ?></p>
            </div>
            <div class="sum-card">
                <h5>Balance Due</h5>
                <p class="<?php echo $balance > 0 ? 'c-red' : 'c-green'; ?>"><?php echo htmlspecialchars($currency); ?> <?php echo number_format($balance); ?></p>
            </div>
        </div>

        <!-- Ledger -->
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Description</th>
                    <th>Type</th>
                    <th class="r">Charged (<?php echo htmlspecialchars($currency); ?>)</th>
                    <th class="r">Paid (<?php echo htmlspecialchars($currency); ?>)</th>
                    <th class="r">Balance (<?php echo htmlspecialchars($currency); ?>)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ledger)): ?>
                <tr><td colspan="6" style="padding:18px;text-align:center;color:#94a3b8;font-style:italic;">No transactions found for this period.</td></tr>
                <?php else: ?>
                <?php if ($dateFrom): ?>
                <tr style="background:#f8fafc;">
                    <td class="date-cell"><?php echo date('d M Y', strtotime($dateFrom)); ?></td>
                    <td colspan="2"><span class="desc-main">Balance brought forward</span></td>
                    <td class="r">—</td>
                    <td class="r">—</td>
                    <td class="r fw-b <?php echo $openingBal > 0 ? 'c-red' : 'c-green'; ?>"><?php echo number_format($openingBal, 2); ?></td>
                </tr>
                <?php endif; ?>
                <?php
                // Continue from what was already owed, not from zero
                $runBal = $openingBal;
                foreach ($ledger as $item):
                    $runBal += ($item['debit'] - $item['credit']);
                    $isOvd  = ($item['type'] === 'Invoice' && ($item['status'] ?? '') === 'Overdue');
                ?>
                <tr<?php echo $isOvd ? ' class="overdue-row"' : ''; ?>>
                    <td class="date-cell"><?php echo date('d M Y', strtotime($item['date'])); ?></td>
                    <td>
                        <span class="desc-main"><?php echo htmlspecialchars($item['title']); ?></span>
                        <?php if (!empty($item['payment_method'])): ?>
                        <span class="desc-sub">via <?php echo htmlspecialchars($item['payment_method']); ?></span>
                        <?php endif; ?>
                        <?php if ($isOvd): ?>
                        <span class="desc-sub c-red">Due: <?php echo date('d M Y', strtotime($item['due_date'])); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isOvd): ?>
                        <span class="badge b-ovd">OVERDUE</span>
                        <?php elseif ($item['type'] === 'Invoice'): ?>
                        <span class="badge b-inv">Invoice</span>
                        <?php else: ?>
                        <span class="badge b-pay">Payment</span>
                        <?php endif; ?>
                    </td>
                    <td class="r fw-b"><?php echo $item['debit'] > 0 ? number_format($item['debit'], 2) : '—'; ?></td>
                    <td class="r fw-b c-green"><?php echo $item['credit'] > 0 ? number_format($item['credit'], 2) : '—'; ?></td>
                    <td class="r fw-b <?php echo $runBal > 0 ? 'c-red' : 'c-green'; ?>"><?php echo number_format($runBal, 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3"><?php echo $dateFrom ? 'MOVEMENT THIS PERIOD' : 'TOTAL'; ?></td>
                    <td class="r"><?php echo number_format($totalDebit, 2); ?></td>
                    <td class="r c-green"><?php echo number_format($totalCredit, 2); ?></td>
                    <td class="r fw-b <?php echo $balance > 0 ? 'c-red' : 'c-green'; ?>" style="font-size:11pt;"><?php echo number_format($balance, 2); ?></td>
                </tr>
            </tfoot>
        </table>

        <?php if ($ageing['total'] > 0.009): ?>
        <table class="ledger" style="margin-top:16px;">
            <thead>
                <tr>
                    <th colspan="4">How long this balance has been owed</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <?php foreach (['current','d30','d60','d90'] as $bk): ?>
                    <td style="text-align:center;">
                        <span class="desc-sub"><?php echo htmlspecialchars(ageingLabel($bk)); ?></span>
                        <span class="desc-main <?php echo ($bk !== 'current' && $ageing[$bk] > 0) ? 'c-red' : ''; ?>">
                            <?php echo number_format($ageing[$bk], 2); ?>
                        </span>
                    </td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>

        <?php if ($invoiceFooter): ?>
        <div class="doc-note"><?php echo htmlspecialchars($invoiceFooter); ?></div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="doc-footer">
            <div>
                <p>System-generated statement from <?php echo htmlspecialchars($companyName); ?>.</p>
                <?php if ($companyEmail): ?>
                <p style="margin-top:3px;">For inquiries: <?php echo htmlspecialchars($companyEmail); ?></p>
                <?php endif; ?>
            </div>
            <div class="sig-block">
                <p class="sig-note">Authorised by</p>
                <div class="sig-line">Accounts Department</div>
            </div>
        </div>

    </div><!-- /page -->
</body>
</html>
<?php
    exit();
}


// =========================================================
//  NORMAL MODE — in-app view with sidebar
// =========================================================
$pageTitle = "Statement: " . $tenant['full_name'];
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

    <!-- Page header -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="<?php echo $role === 'tenant' ? 'financials.php' : 'tenant_payments.php'; ?>" class="p-2 bg-slate-100 dark:bg-slate-800 rounded-xl text-slate-500 hover:text-slate-900 transition-all">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
                </a>
                <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Statement of Account</h1>
            </div>
            <p class="text-slate-500 font-medium text-sm ml-12">
                <?php echo htmlspecialchars($tenant['full_name']); ?>
                <?php if (!empty($tenant['property_title'])): ?>
                    &mdash; <?php echo htmlspecialchars($tenant['property_title']); ?> Unit <?php echo htmlspecialchars($tenant['unit_number'] ?? ''); ?>
                <?php endif; ?>
            </p>
        </div>

        <div class="flex flex-wrap gap-3 items-center">
            <!-- Period filter -->
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="tenant_id" value="<?php echo htmlspecialchars((string)$tenantId); ?>">
                <select name="period" onchange="this.form.submit()" class="px-4 py-3 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-xs font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                    <option value="all"        <?php echo $period==='all'        ? 'selected' : ''; ?>>All Time</option>
                    <option value="this_month" <?php echo $period==='this_month' ? 'selected' : ''; ?>><?php echo date('F Y'); ?></option>
                    <option value="last_month" <?php echo $period==='last_month' ? 'selected' : ''; ?>><?php echo date('F Y', strtotime('last month')); ?></option>
                    <option value="last_3m"    <?php echo $period==='last_3m'    ? 'selected' : ''; ?>>Last 3 Months</option>
                    <option value="this_year"  <?php echo $period==='this_year'  ? 'selected' : ''; ?>>Year <?php echo date('Y'); ?></option>
                    <option value="custom"     <?php echo $period==='custom'     ? 'selected' : ''; ?>>Custom Range</option>
                </select>
                <?php if ($period === 'custom'): ?>
                <input type="date" name="from" value="<?php echo htmlspecialchars($customFrom); ?>" class="px-4 py-3 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-xs font-bold outline-none">
                <span class="text-slate-400 text-xs font-medium">to</span>
                <input type="date" name="to"   value="<?php echo htmlspecialchars($customTo); ?>"   class="px-4 py-3 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-xs font-bold outline-none">
                <button type="submit" class="px-4 py-3 bg-accent-green text-white rounded-2xl text-xs font-bold hover:bg-green-600 transition-colors">Apply</button>
                <?php endif; ?>
            </form>

            <!-- Print (opens standalone doc in new tab) -->
            <a href="view_statement.php?tenant_id=<?php echo urlencode((string)$tenantId); ?>&print=1&period=<?php echo urlencode($period); ?>&from=<?php echo urlencode($customFrom); ?>&to=<?php echo urlencode($customTo); ?>"
               target="_blank"
               class="px-6 py-3 bg-slate-900 dark:bg-white text-white dark:text-slate-900 rounded-2xl font-black text-xs uppercase tracking-widest shadow-lg hover:shadow-xl hover:scale-105 transition-all flex items-center gap-2.5">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                Print / Save PDF
            </a>
        </div>
    </div>

    <!-- Period badge + overdue indicator + statement ref -->
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex items-center gap-2 px-4 py-2 bg-slate-100 dark:bg-slate-800 rounded-full">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" class="text-slate-400"><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>
            <span class="text-[10px] font-black text-slate-600 dark:text-slate-400 uppercase tracking-widest"><?php echo htmlspecialchars($periodLabel); ?></span>
        </div>
        <?php if ($overdueCount > 0): ?>
        <div class="flex items-center gap-2 px-4 py-2 bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-800/30 rounded-full">
            <span class="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse"></span>
            <span class="text-[10px] font-black text-red-600 dark:text-red-400 uppercase tracking-widest"><?php echo $overdueCount; ?> Overdue Invoice<?php echo $overdueCount !== 1 ? 's' : ''; ?></span>
        </div>
        <?php endif; ?>
        <span class="text-[10px] font-black text-slate-400 ml-auto font-mono"><?php echo htmlspecialchars($stmtNumber); ?></span>
    </div>

    <!-- Summary cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-5">
        <div class="glass-card p-6 border-l-4 border-slate-900">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Tenant</p>
            <p class="text-sm font-black text-slate-900 dark:text-white leading-snug"><?php echo htmlspecialchars($tenant['full_name']); ?></p>
        </div>
        <div class="glass-card p-6 border-l-4 border-blue-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Charged</p>
            <p class="text-lg font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($totalDebit); ?></p>
        </div>
        <div class="glass-card p-6 border-l-4 border-accent-green">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Paid</p>
            <p class="text-lg font-black text-accent-green"><?php echo $currency; ?> <?php echo number_format($totalCredit); ?></p>
        </div>
        <?php if ($ageing['total'] > 0.009): ?>
        <div class="glass-card p-5 mb-4">
            <p class="section-label mb-3">How long this balance has been owed</p>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <?php foreach (['current','d30','d60','d90'] as $bk): ?>
                <div>
                    <p class="text-[11.5px]" style="color:var(--text-muted)"><?php echo htmlspecialchars(ageingLabel($bk)); ?></p>
                    <p class="text-[15px] font-semibold tabular mt-0.5"
                       style="color:<?php echo ($bk !== 'current' && $ageing[$bk] > 0.009) ? 'var(--danger)' : 'var(--text)'; ?>">
                        <?php echo $currency; ?> <?php echo number_format($ageing[$bk], 2); ?>
                    </p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="glass-card p-6 border-l-4 <?php echo $balance > 0 ? 'border-red-500' : 'border-accent-green'; ?>">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Balance Due</p>
            <p class="text-lg font-black <?php echo $balance > 0 ? 'text-red-500' : 'text-accent-green'; ?>"><?php echo $currency; ?> <?php echo number_format($balance); ?></p>
        </div>
    </div>

    <!-- Ledger table -->
    <div class="glass-card overflow-hidden border-none shadow-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-900 text-white">
                        <th class="p-5 text-[10px] font-black uppercase tracking-widest">Date</th>
                        <th class="p-5 text-[10px] font-black uppercase tracking-widest">Description</th>
                        <th class="p-5 text-[10px] font-black uppercase tracking-widest">Type</th>
                        <th class="p-5 text-[10px] font-black uppercase tracking-widest text-right">Charged</th>
                        <th class="p-5 text-[10px] font-black uppercase tracking-widest text-right">Paid</th>
                        <th class="p-5 text-[10px] font-black uppercase tracking-widest text-right">Running Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php if (empty($ledger)): ?>
                    <tr><td colspan="6" class="p-16 text-center text-slate-400 italic">No records found for this period.</td></tr>
                    <?php else: ?>
                    <?php
                    // Same brought-forward balance as the printed copy
                    $runBal = $openingBal;
                    foreach ($ledger as $item):
                        $runBal += ($item['debit'] - $item['credit']);
                        $isOvd   = ($item['type'] === 'Invoice' && ($item['status'] ?? '') === 'Overdue');
                    ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all <?php echo $isOvd ? 'bg-red-50/40 dark:bg-red-900/5' : ''; ?>">
                        <td class="p-5">
                            <p class="text-xs font-bold text-slate-900 dark:text-white whitespace-nowrap"><?php echo date('d M, Y', strtotime($item['date'])); ?></p>
                        </td>
                        <td class="p-5">
                            <p class="text-xs font-black text-slate-700 dark:text-slate-300"><?php echo htmlspecialchars($item['title']); ?></p>
                            <?php if (!empty($item['payment_method'])): ?>
                            <p class="text-[9px] font-black uppercase text-slate-400 tracking-tighter mt-0.5">via <?php echo htmlspecialchars($item['payment_method']); ?></p>
                            <?php endif; ?>
                            <?php if ($isOvd): ?>
                            <p class="text-[9px] font-black text-red-500 mt-0.5">Due: <?php echo date('d M, Y', strtotime($item['due_date'])); ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="p-5">
                            <?php if ($isOvd): ?>
                            <span class="px-2.5 py-1 bg-red-500/10 text-red-500 rounded-full text-[9px] font-black uppercase tracking-widest border border-red-500/20">Overdue</span>
                            <?php elseif ($item['type'] === 'Invoice'): ?>
                            <span class="px-2.5 py-1 bg-blue-500/10 text-blue-500 rounded-full text-[9px] font-black uppercase tracking-widest border border-blue-500/20">Invoice</span>
                            <?php else: ?>
                            <span class="px-2.5 py-1 bg-accent-green/10 text-accent-green rounded-full text-[9px] font-black uppercase tracking-widest border border-accent-green/20">Payment</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-5 text-right text-xs font-black <?php echo $item['debit'] > 0 ? 'text-slate-900 dark:text-white' : 'text-slate-300 dark:text-slate-700'; ?>">
                            <?php echo $item['debit'] > 0 ? $currency . ' ' . number_format($item['debit']) : '—'; ?>
                        </td>
                        <td class="p-5 text-right text-xs font-black <?php echo $item['credit'] > 0 ? 'text-accent-green' : 'text-slate-300 dark:text-slate-700'; ?>">
                            <?php echo $item['credit'] > 0 ? $currency . ' ' . number_format($item['credit']) : '—'; ?>
                        </td>
                        <td class="p-5 text-right text-sm font-black <?php echo $runBal > 0 ? 'text-red-500' : 'text-accent-green'; ?>">
                            <?php echo $currency; ?> <?php echo number_format($runBal); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="bg-slate-50 dark:bg-slate-900/50">
                        <td colspan="3" class="p-5 text-sm font-black text-slate-900 dark:text-white">Total Summary</td>
                        <td class="p-5 text-right text-sm font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($totalDebit); ?></td>
                        <td class="p-5 text-right text-sm font-black text-accent-green"><?php echo $currency; ?> <?php echo number_format($totalCredit); ?></td>
                        <td class="p-5 text-right text-lg font-black <?php echo $balance > 0 ? 'text-red-500' : 'text-accent-green'; ?>"><?php echo $currency; ?> <?php echo number_format($balance); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
