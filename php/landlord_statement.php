<?php
/**
 * Landlord Portfolio Income Statement
 * Dual mode: in-app view + ?print=1 standalone printable document
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin(['landlord']);
require_once __DIR__ . '/includes/settings.php';

$user       = getCurrentUser($pdo);
$landlordId = getLandlordId($pdo);
$printMode  = isset($_GET['print']) && $_GET['print'] === '1';

if (!$landlordId) { header("Location: dashboard.php"); exit(); }

$currency   = getSetting($pdo, 'currency_symbol', 'KSh');
$companyName    = getSetting($pdo, 'company_name',    'Primelink Management System');
$companyEmail   = getSetting($pdo, 'company_email',   '');
$companyPhone   = getSetting($pdo, 'company_phone',   '');
$companyAddr    = getSetting($pdo, 'company_address',  '');
$companyTagline = getSetting($pdo, 'company_tagline',  'Premium Property Management');
$invoiceFooter  = getSetting($pdo, 'invoice_footer',   '');

// ── Period filter ──────────────────────────────────────────────────
$period     = $_GET['period'] ?? 'this_year';
$customFrom = trim($_GET['from'] ?? '');
$customTo   = trim($_GET['to']   ?? '');
$dateFrom   = null;
$dateTo     = date('Y-m-d');

switch ($period) {
    case 'this_month':  $dateFrom = date('Y-m-01'); break;
    case 'last_month':
        $dateFrom = date('Y-m-01', strtotime('first day of last month'));
        $dateTo   = date('Y-m-t',  strtotime('last day of last month'));
        break;
    case 'last_3m':     $dateFrom = date('Y-m-d', strtotime('-3 months')); break;
    case 'this_year':   $dateFrom = date('Y-01-01'); break;
    case 'custom':
        $dateFrom = $customFrom ?: null;
        $dateTo   = $customTo   ?: date('Y-m-d');
        break;
}

$periodLabels = [
    'this_month'  => date('F Y'),
    'last_month'  => date('F Y', strtotime('last month')),
    'last_3m'     => 'Last 3 Months',
    'this_year'   => 'Year ' . date('Y'),
    'custom'      => ($dateFrom ? date('d M Y', strtotime($dateFrom)) : 'Start') . ' — ' . date('d M Y', strtotime($dateTo)),
];
$periodLabel = $periodLabels[$period] ?? date('Y');

// ── Landlord info ──────────────────────────────────────────────────
$llStmt = $pdo->prepare("SELECT * FROM landlords WHERE id = ?");
$llStmt->execute([$landlordId]);
$landlord = $llStmt->fetch();

$feeType    = $landlord['fee_type']     ?? 'percentage';
$feeSetting = (float)($landlord['management_fee'] ?? 10.0);
$feeLabelStr = $feeType === 'fixed' ? 'Fixed' : $feeSetting . '%';

// ── Per-property income ────────────────────────────────────────────
$dateWhere = $dateFrom ? "AND DATE(tr.transaction_date) BETWEEN ? AND ?" : "";
$propStmt  = $pdo->prepare("
    SELECT p.id, p.title, p.location,
           COUNT(DISTINCT u.id) as total_units,
           SUM(CASE WHEN u.status='Occupied' THEN 1 ELSE 0 END) as occ_units,
           COALESCE((
               SELECT SUM(tr2.amount)
               FROM transactions tr2
               JOIN tenants t2 ON tr2.tenant_id=t2.id
               JOIN leases ls2 ON ls2.tenant_id=t2.id AND ls2.status='Active'
               JOIN units u2 ON ls2.unit_id=u2.id
               WHERE u2.property_id=p.id AND tr2.status='Paid'
                 $dateWhere
           ), 0) as income
    FROM properties p
    LEFT JOIN units u ON u.property_id=p.id
    WHERE p.landlord_id=?
    GROUP BY p.id
    ORDER BY p.title");
$propParams = $dateFrom ? [$dateFrom, $dateTo, $dateFrom, $dateTo, $landlordId] : [$landlordId];
$propStmt->execute($propParams);
$propertyRows = $propStmt->fetchAll();

// ── Totals ─────────────────────────────────────────────────────────
$grossIncome   = array_sum(array_column($propertyRows, 'income'));
$managementFee = $feeType === 'fixed' ? $feeSetting : round($grossIncome * $feeSetting / 100, 2);
$netPayable    = $grossIncome - $managementFee;

// ── Payout history for the period ─────────────────────────────────
$poBefore = $dateFrom ? "AND DATE(payout_date) BETWEEN ? AND ?" : "";
$poStmt = $pdo->prepare("SELECT * FROM landlord_payouts WHERE landlord_id=? $poBefore ORDER BY payout_date DESC");
$poParams = $dateFrom ? [$landlordId, $dateFrom, $dateTo] : [$landlordId];
$poStmt->execute($poParams);
$payouts = $poStmt->fetchAll();
$totalPaidOut = array_sum(array_column($payouts, 'amount'));
$balanceDue   = $netPayable - $totalPaidOut;

// Statement ref
$stmtRef = 'LSTMT-' . date('Ym') . '-' . strtoupper(substr(str_replace('-','',$landlordId),0,6));


// =========================================================
// PRINT MODE
// =========================================================
if ($printMode) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portfolio Statement — <?php echo htmlspecialchars($landlord['full_name'] ?? ''); ?></title>
    <style>
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:Arial,sans-serif; font-size:10pt; color:#1e293b; background:white; }
        .page { width:210mm; min-height:297mm; margin:0 auto; padding:18mm 18mm 14mm; }
        .doc-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:22px; padding-bottom:16px; border-bottom:3px solid #0f172a; }
        .co-name { font-size:18pt; font-weight:900; color:#0f172a; letter-spacing:-0.5px; }
        .co-tag  { font-size:7.5pt; color:#64748b; text-transform:uppercase; letter-spacing:1.5px; margin-top:2px; }
        .co-contact { font-size:8pt; color:#64748b; line-height:1.8; margin-top:4px; }
        .si-title  { font-size:14pt; font-weight:900; text-align:right; }
        .si-ref    { font-size:8pt; color:#64748b; text-align:right; margin-top:2px; }
        .si-period { font-size:9pt; font-weight:700; color:#22c55e; text-align:right; margin-top:2px; }
        .ll-row { display:flex; gap:16px; margin:16px 0; }
        .ll-box { flex:2; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:12px 15px; }
        .ll-box h4 { font-size:7pt; font-weight:900; text-transform:uppercase; letter-spacing:1px; color:#94a3b8; margin-bottom:4px; }
        .ll-box p  { font-size:11pt; font-weight:700; color:#0f172a; }
        .ll-box p.sub { font-size:8pt; color:#64748b; font-weight:400; margin-top:2px; }
        .sum-box { background:#0f172a; padding:12px 15px; border-radius:8px; text-align:right; min-width:200px; }
        .sum-box h4 { font-size:7pt; font-weight:900; text-transform:uppercase; letter-spacing:1px; color:#94a3b8; margin-bottom:4px; }
        .sum-box .big { font-size:18pt; font-weight:900; }
        .sum-box .c-g { color:#4ade80; }
        .sum-box .c-r { color:#f87171; }
        .sum-box .sub { font-size:8pt; color:#94a3b8; margin-top:3px; }
        section { margin:16px 0; }
        section h2 { font-size:10pt; font-weight:900; text-transform:uppercase; letter-spacing:0.8px; color:#0f172a; padding-bottom:6px; border-bottom:1.5px solid #e2e8f0; margin-bottom:10px; }
        table { width:100%; border-collapse:collapse; font-size:9pt; }
        thead { background:#0f172a; color:white; }
        thead th { padding:8px 10px; font-size:7pt; font-weight:900; text-transform:uppercase; letter-spacing:0.8px; }
        thead th.r { text-align:right; }
        tbody tr { border-bottom:1px solid #f1f5f9; }
        tbody td { padding:8px 10px; }
        tfoot td { padding:9px 10px; font-weight:900; font-size:9.5pt; border-top:2px solid #e2e8f0; background:#f8fafc; }
        .r { text-align:right; }
        .fw9 { font-weight:900; }
        .c-green { color:#16a34a; }
        .c-red   { color:#dc2626; }
        .c-dark  { color:#0f172a; }
        .fee-table { margin-top:10px; }
        .fee-row { display:flex; justify-content:space-between; padding:7px 10px; }
        .fee-row.alt { background:#f8fafc; }
        .fee-row.total { border-top:2px solid #0f172a; font-weight:900; font-size:11pt; margin-top:4px; }
        .doc-footer { margin-top:22px; padding-top:14px; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:flex-end; }
        .doc-footer p { font-size:7.5pt; color:#94a3b8; }
        .sig-block .sig-note { font-size:7pt; color:#94a3b8; margin-bottom:28px; text-align:right; }
        .sig-line { border-top:1px solid #0f172a; padding-top:4px; font-size:7.5pt; font-weight:700; color:#0f172a; width:160px; text-align:center; margin-left:auto; }
        @media print { body{-webkit-print-color-adjust:exact;print-color-adjust:exact;} .page{width:100%;padding:10mm 14mm;} .no-print{display:none!important;} }
        @media screen { body{background:#f1f5f9;padding-top:54px;} .page{border:1px solid #e2e8f0;box-shadow:0 4px 24px rgba(0,0,0,0.10);margin:24px auto;} .action-bar{position:fixed;top:0;left:0;right:0;background:#0f172a;padding:12px 24px;display:flex;justify-content:space-between;align-items:center;z-index:100;} .action-bar p{color:#94a3b8;font-size:12px;} .btn{padding:9px 20px;background:#22c55e;color:white;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;} .btn-out{background:transparent;color:#cbd5e1;border:1px solid #334155;margin-right:8px;} }
    </style>
</head>
<body>
<div class="action-bar no-print">
    <p><?php echo htmlspecialchars($stmtRef); ?> &mdash; <?php echo htmlspecialchars($periodLabel); ?></p>
    <div>
        <a href="landlord_statement.php?period=<?php echo urlencode($period); ?>&from=<?php echo urlencode($customFrom); ?>&to=<?php echo urlencode($customTo); ?>" class="btn btn-out">← Back</a>
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
                <?php echo htmlspecialchars($companyEmail); ?><?php if ($companyPhone): ?> &bull; <?php echo htmlspecialchars($companyPhone); ?><?php endif; ?>
            </div>
        </div>
        <div>
            <div class="si-title">LANDLORD INCOME STATEMENT</div>
            <div class="si-ref">Ref: <?php echo htmlspecialchars($stmtRef); ?></div>
            <div class="si-ref">Generated: <?php echo date('d M Y, H:i'); ?></div>
            <div class="si-period">Period: <?php echo htmlspecialchars($periodLabel); ?></div>
        </div>
    </div>

    <!-- Landlord + Net Balance -->
    <div class="ll-row">
        <div class="ll-box">
            <h4>Landlord</h4>
            <p><?php echo htmlspecialchars($landlord['full_name'] ?? ''); ?></p>
            <?php if (!empty($landlord['email'])): ?><p class="sub"><?php echo htmlspecialchars($landlord['email']); ?></p><?php endif; ?>
            <?php if (!empty($landlord['phone'])): ?><p class="sub"><?php echo htmlspecialchars($landlord['phone']); ?></p><?php endif; ?>
        </div>
        <div class="sum-box">
            <h4>Balance Due to Landlord</h4>
            <p class="big <?php echo $balanceDue > 0 ? 'c-g' : 'c-r'; ?>"><?php echo $currency; ?> <?php echo number_format($balanceDue); ?></p>
            <p class="sub">Period: <?php echo htmlspecialchars($periodLabel); ?></p>
        </div>
    </div>

    <!-- Per-Property Income -->
    <section>
        <h2>Income by Property</h2>
        <table>
            <thead><tr>
                <th>Property</th>
                <th>Location</th>
                <th>Units</th>
                <th>Occupancy</th>
                <th class="r">Gross Income (<?php echo $currency; ?>)</th>
            </tr></thead>
            <tbody>
                <?php if (empty($propertyRows)): ?>
                <tr><td colspan="5" style="padding:14px;text-align:center;color:#94a3b8;font-style:italic;">No income recorded for this period.</td></tr>
                <?php else: foreach ($propertyRows as $row):
                    $occPct = $row['total_units'] > 0 ? round(($row['occ_units']/$row['total_units'])*100) : 0;
                ?>
                <tr>
                    <td class="fw9"><?php echo htmlspecialchars($row['title']); ?></td>
                    <td style="color:#64748b;font-size:8.5pt;"><?php echo htmlspecialchars($row['location']); ?></td>
                    <td><?php echo $row['occ_units']; ?>/<?php echo $row['total_units']; ?></td>
                    <td><?php echo $occPct; ?>%</td>
                    <td class="r fw9"><?php echo number_format($row['income'], 2); ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="fw9">TOTAL GROSS INCOME</td>
                    <td class="r fw9"><?php echo number_format($grossIncome, 2); ?></td>
                </tr>
            </tfoot>
        </table>
    </section>

    <!-- Fee Calculation -->
    <section>
        <h2>Fee Calculation &amp; Net Payable</h2>
        <div class="fee-table">
            <div class="fee-row"><span>Gross Income</span><span class="fw9"><?php echo $currency; ?> <?php echo number_format($grossIncome, 2); ?></span></div>
            <div class="fee-row alt"><span>Management Fee (<?php echo $feeLabelStr; ?>)</span><span class="fw9 c-red">- <?php echo $currency; ?> <?php echo number_format($managementFee, 2); ?></span></div>
            <div class="fee-row total"><span>Net Payable to Landlord</span><span class="c-green"><?php echo $currency; ?> <?php echo number_format($netPayable, 2); ?></span></div>
        </div>
    </section>

    <!-- Payout History -->
    <section>
        <h2>Payout History (This Period)</h2>
        <table>
            <thead><tr>
                <th>Date</th><th>Method</th><th>Reference</th><th>Status</th><th class="r">Amount (<?php echo $currency; ?>)</th>
            </tr></thead>
            <tbody>
                <?php if (empty($payouts)): ?>
                <tr><td colspan="5" style="padding:14px;text-align:center;color:#94a3b8;font-style:italic;">No payouts recorded for this period.</td></tr>
                <?php else: foreach ($payouts as $po): ?>
                <tr>
                    <td><?php echo date('d M Y', strtotime($po['payout_date'])); ?></td>
                    <td><?php echo htmlspecialchars($po['method'] ?? ''); ?></td>
                    <td style="font-family:monospace;font-size:8.5pt;"><?php echo htmlspecialchars($po['reference_code'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($po['status']); ?></td>
                    <td class="r fw9"><?php echo number_format($po['amount'], 2); ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" class="fw9">Total Paid Out</td>
                    <td class="r fw9 c-green"><?php echo number_format($totalPaidOut, 2); ?></td>
                </tr>
                <tr style="background:#0f172a;color:white;">
                    <td colspan="4" class="fw9" style="color:white;">Balance Due to Landlord</td>
                    <td class="r fw9 <?php echo $balanceDue > 0 ? '' : 'c-red'; ?>" style="font-size:12pt;color:<?php echo $balanceDue > 0 ? '#4ade80' : '#f87171'; ?>"><?php echo $currency; ?> <?php echo number_format($balanceDue, 2); ?></td>
                </tr>
            </tfoot>
        </table>
    </section>

    <?php if ($invoiceFooter): ?>
    <p style="font-size:8pt;color:#64748b;margin-top:12px;padding:10px 12px;background:#f8fafc;border-radius:6px;"><?php echo htmlspecialchars($invoiceFooter); ?></p>
    <?php endif; ?>

    <div class="doc-footer">
        <div>
            <p>Generated by <?php echo htmlspecialchars($companyName); ?> — <?php echo date('d M Y'); ?></p>
            <?php if ($companyEmail): ?><p style="margin-top:3px;">Inquiries: <?php echo htmlspecialchars($companyEmail); ?></p><?php endif; ?>
        </div>
        <div class="sig-block">
            <p class="sig-note">Authorised by</p>
            <div class="sig-line">Accounts Department</div>
        </div>
    </div>
</div>
</body>
</html>
<?php
    exit();
}


// =========================================================
// NORMAL IN-APP MODE
// =========================================================
$pageTitle = "Portfolio Statement";
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

    <!-- Header -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-6">
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="dashboard.php" class="p-2 bg-slate-100 dark:bg-slate-800 rounded-xl text-slate-500 hover:text-slate-900 transition-all">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
                </a>
                <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Portfolio Statement</h1>
            </div>
            <p class="text-slate-500 font-medium ml-11 text-sm">Income &amp; payout summary — <?php echo htmlspecialchars($landlord['full_name'] ?? ''); ?></p>
        </div>
        <div class="flex flex-wrap gap-3 items-center">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <select name="period" onchange="this.form.submit()" class="px-4 py-3 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-xs font-bold focus:ring-2 focus:ring-accent-green/20 outline-none">
                    <option value="this_month"  <?php echo $period==='this_month'  ? 'selected' : ''; ?>><?php echo date('F Y'); ?></option>
                    <option value="last_month"  <?php echo $period==='last_month'  ? 'selected' : ''; ?>><?php echo date('F Y', strtotime('last month')); ?></option>
                    <option value="last_3m"     <?php echo $period==='last_3m'     ? 'selected' : ''; ?>>Last 3 Months</option>
                    <option value="this_year"   <?php echo $period==='this_year'   ? 'selected' : ''; ?>>Year <?php echo date('Y'); ?></option>
                    <option value="custom"      <?php echo $period==='custom'      ? 'selected' : ''; ?>>Custom Range</option>
                </select>
                <?php if ($period==='custom'): ?>
                <input type="date" name="from" value="<?php echo htmlspecialchars($customFrom); ?>" class="px-4 py-3 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-xs font-bold outline-none">
                <span class="text-slate-400 text-xs">to</span>
                <input type="date" name="to" value="<?php echo htmlspecialchars($customTo); ?>" class="px-4 py-3 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl text-xs font-bold outline-none">
                <button type="submit" class="px-4 py-3 bg-accent-green text-white rounded-2xl text-xs font-bold">Apply</button>
                <?php endif; ?>
            </form>
            <a href="landlord_statement.php?print=1&period=<?php echo urlencode($period); ?>&from=<?php echo urlencode($customFrom); ?>&to=<?php echo urlencode($customTo); ?>"
               target="_blank"
               class="px-5 py-3 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl font-bold text-sm shadow-sm hover:shadow-md transition-all flex items-center gap-2">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                Print Statement
            </a>
        </div>
    </div>

    <!-- Period badge + ref -->
    <div class="flex items-center gap-3">
        <div class="flex items-center gap-2 px-4 py-2 bg-slate-100 dark:bg-slate-800 rounded-full">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" class="text-slate-400"><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>
            <span class="text-[10px] font-black text-slate-600 dark:text-slate-400 uppercase tracking-widest"><?php echo htmlspecialchars($periodLabel); ?></span>
        </div>
        <span class="text-[10px] font-black text-slate-400 font-mono ml-auto"><?php echo htmlspecialchars($stmtRef); ?></span>
    </div>

    <!-- Summary cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        <div class="glass-card p-6 border-l-4 border-blue-500">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Gross Income</p>
            <p class="text-xl font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($grossIncome); ?></p>
        </div>
        <div class="glass-card p-6 border-l-4 border-red-400">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Management Fee (<?php echo $feeLabelStr; ?>)</p>
            <p class="text-xl font-black text-red-500">- <?php echo $currency; ?> <?php echo number_format($managementFee); ?></p>
        </div>
        <div class="glass-card p-6 border-l-4 border-accent-green">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Net Payable</p>
            <p class="text-xl font-black text-accent-green"><?php echo $currency; ?> <?php echo number_format($netPayable); ?></p>
        </div>
        <div class="glass-card p-6 border-l-4 <?php echo $balanceDue > 0 ? 'border-accent-green' : 'border-red-500'; ?>">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Balance Due</p>
            <p class="text-xl font-black <?php echo $balanceDue > 0 ? 'text-accent-green' : 'text-red-500'; ?>"><?php echo $currency; ?> <?php echo number_format($balanceDue); ?></p>
        </div>
    </div>

    <!-- Per-property income table -->
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800">
            <h3 class="font-black text-slate-900 dark:text-white">Income by Property</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead><tr class="bg-slate-900 text-white">
                    <th class="p-5 text-[10px] font-black uppercase tracking-widest">Property</th>
                    <th class="p-5 text-[10px] font-black uppercase tracking-widest">Location</th>
                    <th class="p-5 text-[10px] font-black uppercase tracking-widest">Units</th>
                    <th class="p-5 text-[10px] font-black uppercase tracking-widest">Occupancy</th>
                    <th class="p-5 text-[10px] font-black uppercase tracking-widest text-right">Gross Income</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php if (empty($propertyRows)): ?>
                    <tr><td colspan="5" class="p-12 text-center text-slate-400 italic">No income recorded for this period.</td></tr>
                    <?php else: foreach ($propertyRows as $row):
                        $occPct = $row['total_units'] > 0 ? round(($row['occ_units']/$row['total_units'])*100) : 0;
                    ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                        <td class="p-5 font-black text-sm text-slate-900 dark:text-white"><?php echo htmlspecialchars($row['title']); ?></td>
                        <td class="p-5 text-xs text-slate-500"><?php echo htmlspecialchars($row['location']); ?></td>
                        <td class="p-5 text-xs font-bold"><?php echo $row['occ_units']; ?>/<?php echo $row['total_units']; ?></td>
                        <td class="p-5">
                            <div class="flex items-center gap-2">
                                <div class="flex-1 h-1.5 bg-slate-100 dark:bg-slate-700 rounded-full overflow-hidden" style="max-width:60px">
                                    <div class="h-full <?php echo $occPct>=80?'bg-green-500':($occPct>=50?'bg-orange-400':'bg-red-500'); ?> rounded-full" style="width:<?php echo $occPct; ?>%"></div>
                                </div>
                                <span class="text-xs font-black"><?php echo $occPct; ?>%</span>
                            </div>
                        </td>
                        <td class="p-5 text-right text-sm font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($row['income']); ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <tfoot><tr class="bg-slate-50 dark:bg-slate-900/50">
                    <td colspan="4" class="p-5 font-black text-sm text-slate-900 dark:text-white">Total Gross Income</td>
                    <td class="p-5 text-right text-lg font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($grossIncome); ?></td>
                </tr></tfoot>
            </table>
        </div>
    </div>

    <!-- Fee calculation block -->
    <div class="glass-card p-6">
        <h3 class="font-black text-slate-900 dark:text-white mb-5">Fee &amp; Net Calculation</h3>
        <div class="max-w-sm space-y-3">
            <div class="flex justify-between items-center p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                <span class="text-sm font-bold text-slate-600 dark:text-slate-300">Gross Income</span>
                <span class="text-sm font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($grossIncome); ?></span>
            </div>
            <div class="flex justify-between items-center p-3 bg-red-50 dark:bg-red-900/10 rounded-xl">
                <span class="text-sm font-bold text-slate-600 dark:text-slate-300">Management Fee (<?php echo $feeLabelStr; ?>)</span>
                <span class="text-sm font-black text-red-500">– <?php echo $currency; ?> <?php echo number_format($managementFee); ?></span>
            </div>
            <div class="flex justify-between items-center p-3 bg-accent-green/5 border border-accent-green/20 rounded-xl">
                <span class="text-sm font-black text-slate-900 dark:text-white">Net Payable</span>
                <span class="text-sm font-black text-accent-green"><?php echo $currency; ?> <?php echo number_format($netPayable); ?></span>
            </div>
            <div class="flex justify-between items-center p-3 bg-slate-50 dark:bg-slate-800/50 rounded-xl">
                <span class="text-sm font-bold text-slate-600 dark:text-slate-300">Total Paid Out</span>
                <span class="text-sm font-black text-slate-900 dark:text-white">– <?php echo $currency; ?> <?php echo number_format($totalPaidOut); ?></span>
            </div>
            <div class="flex justify-between items-center p-4 bg-slate-900 dark:bg-white rounded-xl">
                <span class="text-sm font-black text-white dark:text-slate-900">Balance Due to You</span>
                <span class="text-lg font-black <?php echo $balanceDue > 0 ? 'text-accent-green' : 'text-red-400'; ?>"><?php echo $currency; ?> <?php echo number_format($balanceDue); ?></span>
            </div>
        </div>
    </div>

    <!-- Payout history table -->
    <div class="glass-card overflow-hidden">
        <div class="p-6 border-b border-slate-100 dark:border-slate-800">
            <h3 class="font-black text-slate-900 dark:text-white">Payout History</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead><tr class="bg-slate-50 dark:bg-slate-800/50">
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Date</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Method</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Reference</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                    <th class="p-5 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Amount</th>
                </tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php if (empty($payouts)): ?>
                    <tr><td colspan="5" class="p-12 text-center text-slate-400 italic">No payouts recorded for this period.</td></tr>
                    <?php else: foreach ($payouts as $po): ?>
                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/20 transition-all">
                        <td class="p-5 text-xs font-bold"><?php echo date('d M Y', strtotime($po['payout_date'])); ?></td>
                        <td class="p-5 text-xs text-slate-500"><?php echo htmlspecialchars($po['method'] ?? ''); ?></td>
                        <td class="p-5 text-xs font-mono text-slate-600 dark:text-slate-400"><?php echo htmlspecialchars($po['reference_code'] ?? ''); ?></td>
                        <td class="p-5">
                            <span class="px-2 py-0.5 <?php echo $po['status']==='Completed'?'bg-green-500/10 text-green-500':'bg-orange-500/10 text-orange-500'; ?> rounded-full text-[9px] font-black uppercase"><?php echo $po['status']; ?></span>
                        </td>
                        <td class="p-5 text-right text-sm font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($po['amount']); ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
                <?php if (!empty($payouts)): ?>
                <tfoot><tr class="bg-slate-50 dark:bg-slate-900/50">
                    <td colspan="4" class="p-5 font-black text-sm text-slate-900 dark:text-white">Total Paid Out</td>
                    <td class="p-5 text-right text-sm font-black text-accent-green"><?php echo $currency; ?> <?php echo number_format($totalPaidOut); ?></td>
                </tr></tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
