<?php
require_once 'includes/auth.php';
requireRole(['admin', 'staff']);
require_once 'includes/settings.php';
require_once 'includes/payroll_calc.php';
ensurePayrollTables($pdo);

$year  = (int)($_GET['year'] ?? date('Y'));
$empId = trim($_GET['employee_id'] ?? '');  // Optional: single employee

// Company info
$companyName = getSetting($pdo, 'company_name', 'Primelink Management');
$companyAddr = getSetting($pdo, 'company_address', '');
$companyPin  = getSetting($pdo, 'kra_pin', '');

// Build employee list
if ($empId) {
    $empQ = $pdo->prepare("SELECT id, full_name, staff_no, role_title, id_number FROM employees WHERE id=?");
    $empQ->execute([$empId]);
    $employees = $empQ->fetchAll();
} else {
    $employees = $pdo->query(
        "SELECT id, full_name, staff_no, role_title, id_number FROM employees WHERE status='Active' ORDER BY full_name"
    )->fetchAll();
}

// For each employee, get 12 months of data for the year
function getEmployeeP9(PDO $pdo, string $employeeId, int $year): array {
    $rows = $pdo->prepare(
        "SELECT pp.period_month AS month, pe.*
         FROM payroll_entries pe
         JOIN payroll_periods pp ON pp.id = pe.period_id
         WHERE pe.employee_id = ? AND pp.period_year = ? AND pp.status = 'Finalised'
         ORDER BY pp.period_month"
    );
    $rows->execute([$employeeId, $year]);
    $entries = [];
    foreach ($rows->fetchAll() as $r) {
        $entries[(int)$r['month']] = $r;
    }

    $tp = $pdo->prepare("SELECT * FROM employee_tax_profile WHERE employee_id=?");
    $tp->execute([$employeeId]);
    $tp = $tp->fetch() ?: [];

    return ['entries' => $entries, 'tp' => $tp];
}

$months = range(1, 12);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>P9 Tax Deduction Card — <?php echo $year; ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: Arial, sans-serif; font-size:10px; color:#000; background:#f5f5f5; }
.page { max-width:900px; margin:16px auto; background:#fff; border:1px solid #ccc; padding:20px; page-break-after:always; }
.page:last-child { page-break-after:auto; }
.p9-header { text-align:center; border-bottom:2px solid #000; padding-bottom:8px; margin-bottom:10px; }
.p9-header h1 { font-size:13px; font-weight:900; text-transform:uppercase; }
.p9-header h2 { font-size:11px; font-weight:700; }
.info-grid { display:grid; grid-template-columns:1fr 1fr; gap:0; margin-bottom:10px; border:1px solid #bbb; }
.info-cell { padding:4px 8px; border-right:1px solid #bbb; border-bottom:1px solid #bbb; }
.info-cell:last-child { border-right:none; }
.info-label { font-size:8.5px; color:#555; text-transform:uppercase; letter-spacing:.3px; }
.info-val { font-weight:700; margin-top:1px; }
table.p9 { width:100%; border-collapse:collapse; margin-bottom:10px; }
table.p9 th { background:#1a1a1a; color:#fff; text-align:center; padding:4px 3px; font-size:8.5px; border:1px solid #444; }
table.p9 td { border:1px solid #ccc; padding:3px 5px; text-align:right; font-size:9px; }
table.p9 td.month { text-align:center; font-weight:700; }
table.p9 tr.total-row td { background:#f3f4f6; font-weight:700; border-top:2px solid #888; }
table.p9 tr.total-row td.month { text-align:center; }
.relief-table { width:100%; margin-bottom:8px; }
.relief-table td { padding:3px 8px; font-size:9px; border-bottom:1px solid #eee; }
.relief-table td:last-child { text-align:right; font-weight:700; }
.sign-area { display:flex; justify-content:space-between; margin-top:14px; padding-top:10px; border-top:1px dashed #ccc; font-size:9px; }
.sign-line { width:200px; border-bottom:1px solid #000; padding-bottom:2px; text-align:center; margin-top:18px; }
.print-btn { display:block; width:140px; margin:12px auto; padding:8px; background:#16a34a; color:#fff; border:none; border-radius:4px; font-weight:700; cursor:pointer; font-size:11px; }
@media print {
    body { background:#fff; }
    .print-btn { display:none; }
    .page { border:none; margin:0; box-shadow:none; }
}
</style>
</head>
<body>

<div style="text-align:center;margin:12px 0;">
    <button class="print-btn" onclick="window.print()">🖨 Print All P9 Forms</button>
    <?php if (!$empId): ?>
    <a href="payroll.php" style="display:inline-block;margin-left:12px;padding:8px 14px;background:#e5e7eb;border-radius:4px;text-decoration:none;color:#333;font-size:11px;">← Back to Payroll</a>
    <?php endif; ?>
</div>

<?php if (!$employees): ?>
<div style="text-align:center;padding:40px;color:#6b7280;">No active employees found.</div>
<?php endif; ?>

<?php foreach ($employees as $emp):
    $data = getEmployeeP9($pdo, $emp['id'], $year);
    $entries = $data['entries'];
    $tp      = $data['tp'];

    // Compute annual totals
    $annuals = array_fill_keys([
        'gross_pay','basic_salary','house_allowance','transport_allowance',
        'nssf_employee','shif','housing_levy','taxable_pay',
        'paye_before_relief','personal_relief','insurance_relief','paye',
    ], 0.0);
    foreach ($entries as $row) {
        foreach ($annuals as $k => $_) { $annuals[$k] += (float)($row[$k] ?? 0); }
    }
?>
<div class="page">
    <!-- Header -->
    <div class="p9-header">
        <h1>P9 — Tax Deduction Card</h1>
        <h2>Year of Income: <?php echo $year; ?></h2>
        <p style="font-size:9px;color:#555;margin-top:3px;">Kenya Revenue Authority — Employer's Return Schedule</p>
    </div>

    <!-- Employer / Employee Info -->
    <div class="info-grid">
        <div class="info-cell"><div class="info-label">Employer Name</div><div class="info-val"><?php echo htmlspecialchars($companyName); ?></div></div>
        <div class="info-cell"><div class="info-label">Employer KRA PIN</div><div class="info-val"><?php echo htmlspecialchars($companyPin ?: '—'); ?></div></div>
        <div class="info-cell"><div class="info-label">Employee Name</div><div class="info-val"><?php echo htmlspecialchars($emp['full_name']); ?></div></div>
        <div class="info-cell"><div class="info-label">Employee KRA PIN</div><div class="info-val"><?php echo htmlspecialchars($tp['kra_pin'] ?? '—'); ?></div></div>
        <div class="info-cell"><div class="info-label">Staff No.</div><div class="info-val"><?php echo htmlspecialchars($emp['staff_no'] ?: '—'); ?></div></div>
        <div class="info-cell"><div class="info-label">ID No.</div><div class="info-val"><?php echo htmlspecialchars($emp['id_number'] ?: '—'); ?></div></div>
        <div class="info-cell"><div class="info-label">NSSF No.</div><div class="info-val"><?php echo htmlspecialchars($tp['nssf_number'] ?? '—'); ?></div></div>
        <div class="info-cell"><div class="info-label">SHIF No.</div><div class="info-val"><?php echo htmlspecialchars($tp['shif_number'] ?? '—'); ?></div></div>
        <div class="info-cell" style="grid-column:span 2"><div class="info-label">Designation / Department</div><div class="info-val"><?php echo htmlspecialchars($emp['role_title'] ?: '—'); ?></div></div>
    </div>

    <!-- Monthly breakdown -->
    <table class="p9">
        <thead>
            <tr>
                <th style="width:28px">Mth</th>
                <th>Gross Pay</th>
                <th>Basic</th>
                <th>House Allow.</th>
                <th>Transport</th>
                <th>NSSF (Ee)</th>
                <th>SHIF</th>
                <th>Hsg Levy</th>
                <th>Taxable Pay</th>
                <th>Tax (gross)</th>
                <th>Personal Relief</th>
                <th>Ins. Relief</th>
                <th>PAYE</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($months as $m):
                $r = $entries[$m] ?? null;
                $isEmpty = !$r;
            ?>
            <tr <?php echo $isEmpty ? 'style="color:#ccc"' : ''; ?>>
                <td class="month"><?php echo date('M', mktime(0,0,0,$m,1)); ?></td>
                <td><?php echo $r ? number_format($r['gross_pay'],2) : '—'; ?></td>
                <td><?php echo $r ? number_format($r['basic_salary'],2) : ''; ?></td>
                <td><?php echo $r ? number_format($r['house_allowance'],2) : ''; ?></td>
                <td><?php echo $r ? number_format($r['transport_allowance'],2) : ''; ?></td>
                <td><?php echo $r ? number_format($r['nssf_employee'],2) : ''; ?></td>
                <td><?php echo $r ? number_format($r['shif'],2) : ''; ?></td>
                <td><?php echo $r ? number_format($r['housing_levy'],2) : ''; ?></td>
                <td><?php echo $r ? number_format($r['taxable_pay'],2) : ''; ?></td>
                <td><?php echo $r ? number_format($r['paye_before_relief'],2) : ''; ?></td>
                <td><?php echo $r ? number_format($r['personal_relief'],2) : ''; ?></td>
                <td><?php echo $r ? number_format($r['insurance_relief'],2) : ''; ?></td>
                <td style="font-weight:700;color:#b91c1c"><?php echo $r ? number_format($r['paye'],2) : '—'; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td class="month">TOTAL</td>
                <?php foreach ([
                    'gross_pay','basic_salary','house_allowance','transport_allowance',
                    'nssf_employee','shif','housing_levy','taxable_pay',
                    'paye_before_relief','personal_relief','insurance_relief','paye',
                ] as $k): ?>
                <td><?php echo number_format($annuals[$k], 2); ?></td>
                <?php endforeach; ?>
            </tr>
        </tfoot>
    </table>

    <!-- Relief summary -->
    <table class="relief-table">
        <tr><td>Annual Gross Pay</td><td>KSh <?php echo number_format($annuals['gross_pay'], 2); ?></td></tr>
        <tr><td>Annual Taxable Pay</td><td>KSh <?php echo number_format($annuals['taxable_pay'], 2); ?></td></tr>
        <tr><td>Annual Tax Before Relief</td><td>KSh <?php echo number_format($annuals['paye_before_relief'], 2); ?></td></tr>
        <tr><td>Total Personal Relief (KSh 2,400 × months processed)</td><td>KSh <?php echo number_format($annuals['personal_relief'], 2); ?></td></tr>
        <tr><td>Insurance Relief (15% of premiums)</td><td>KSh <?php echo number_format($annuals['insurance_relief'], 2); ?></td></tr>
        <tr style="font-weight:700;font-size:10.5px;border-top:1px solid #888"><td>Annual PAYE Paid</td><td style="color:#b91c1c">KSh <?php echo number_format($annuals['paye'], 2); ?></td></tr>
    </table>

    <!-- Signatures -->
    <div class="sign-area">
        <div>
            <div class="sign-line">Employee Signature / Date</div>
        </div>
        <div>
            <div class="sign-line">Employer Signature / Date</div>
        </div>
        <div>
            <div class="sign-line">Designation / Stamp</div>
        </div>
    </div>

    <p style="font-size:8px;color:#9ca3af;margin-top:8px;text-align:center;">
        Generated by <?php echo htmlspecialchars($companyName); ?> · <?php echo date('d M Y'); ?> · P9 Tax Deduction Card as prescribed by the Kenya Revenue Authority
    </p>
</div>
<?php endforeach; ?>

</body>
</html>
