<?php
require_once 'includes/auth.php';
requireRole(['admin', 'staff']);
require_once 'includes/settings.php';
require_once 'includes/payroll_calc.php';
ensurePayrollTables($pdo);

$entryId = trim($_GET['entry_id'] ?? '');
if (!$entryId) { header('Location: payroll.php'); exit(); }

$entry = $pdo->prepare(
    "SELECT pe.*, pp.period_year, pp.period_month,
            e.full_name, e.staff_no, e.id_number, e.role_title, e.department,
            e.email AS emp_email,
            COALESCE(tp.kra_pin,'—') kra_pin,
            COALESCE(tp.nssf_number,'—') nssf_number,
            COALESCE(tp.shif_number,'—') shif_number
     FROM payroll_entries pe
     JOIN payroll_periods pp ON pp.id = pe.period_id
     JOIN employees e ON e.id = pe.employee_id
     LEFT JOIN employee_tax_profile tp ON tp.employee_id = pe.employee_id
     WHERE pe.id = ?"
);
$entry->execute([$entryId]);
$e = $entry->fetch();
if (!$e) { header('Location: payroll.php?error=Entry+not+found'); exit(); }

// Company info from settings
$companyName = getSetting($pdo, 'company_name', 'Primelink Management');
$companyAddr = getSetting($pdo, 'company_address', '');
$companyPhone= getSetting($pdo, 'company_phone', '');

$periodLabel = monthName((int)$e['period_month']) . ' ' . $e['period_year'];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Payslip — <?php echo htmlspecialchars($e['full_name']); ?> — <?php echo $periodLabel; ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: 'Segoe UI', Arial, sans-serif; font-size:11px; color:#1a1a1a; background:#f5f5f5; }
.page { max-width:720px; margin:20px auto; background:#fff; box-shadow:0 2px 10px rgba(0,0,0,.12); padding:30px; }
.header { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:3px solid #16a34a; padding-bottom:16px; margin-bottom:16px; }
.company-name { font-size:18px; font-weight:900; color:#16a34a; }
.company-info { font-size:10px; color:#555; margin-top:4px; }
.payslip-title { text-align:right; }
.payslip-title h2 { font-size:16px; font-weight:800; color:#1a1a1a; text-transform:uppercase; letter-spacing:1px; }
.payslip-title p { font-size:11px; color:#555; }
.employee-box { background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:12px 16px; display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:16px; }
.employee-box .item { }
.employee-box .label { font-size:9px; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; }
.employee-box .val { font-weight:700; color:#111; margin-top:1px; }
.section-title { font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.8px; color:#6b7280; margin-bottom:6px; margin-top:14px; }
table { width:100%; border-collapse:collapse; }
th { background:#f3f4f6; text-align:left; padding:6px 10px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#374151; }
th.r, td.r { text-align:right; }
td { padding:6px 10px; border-bottom:1px solid #f1f1f1; }
tr:hover td { background:#fafafa; }
.total-row td { background:#f9fafb; font-weight:700; border-top:2px solid #d1d5db; }
.net-box { background:linear-gradient(135deg,#16a34a,#22c55e); color:#fff; border-radius:8px; padding:16px 20px; display:flex; justify-content:space-between; align-items:center; margin-top:16px; }
.net-box .label { font-size:11px; opacity:.85; }
.net-box .amount { font-size:24px; font-weight:900; }
.statutory-note { font-size:9px; color:#9ca3af; margin-top:14px; border-top:1px dashed #e5e7eb; padding-top:10px; }
.print-btn { display:block; width:120px; margin:16px auto 0; padding:8px; background:#16a34a; color:#fff; border:none; border-radius:6px; font-weight:700; cursor:pointer; font-size:12px; text-align:center; }
@media print {
    body { background:#fff; }
    .page { margin:0; box-shadow:none; }
    .print-btn { display:none; }
}
</style>
</head>
<body>
<div class="page">
    <!-- Header -->
    <div class="header">
        <div>
            <div class="company-name"><?php echo htmlspecialchars($companyName); ?></div>
            <div class="company-info">
                <?php echo htmlspecialchars($companyAddr); ?><?php if ($companyAddr && $companyPhone): ?> · <?php endif; ?><?php echo htmlspecialchars($companyPhone); ?>
            </div>
        </div>
        <div class="payslip-title">
            <h2>Pay Slip</h2>
            <p><?php echo $periodLabel; ?></p>
        </div>
    </div>

    <!-- Employee details -->
    <div class="employee-box">
        <div class="item"><div class="label">Employee Name</div><div class="val"><?php echo htmlspecialchars($e['full_name']); ?></div></div>
        <div class="item"><div class="label">Staff No.</div><div class="val"><?php echo htmlspecialchars($e['staff_no'] ?: '—'); ?></div></div>
        <div class="item"><div class="label">ID No.</div><div class="val"><?php echo htmlspecialchars($e['id_number'] ?: '—'); ?></div></div>
        <div class="item"><div class="label">KRA PIN</div><div class="val"><?php echo htmlspecialchars($e['kra_pin']); ?></div></div>
        <div class="item"><div class="label">NSSF No.</div><div class="val"><?php echo htmlspecialchars($e['nssf_number']); ?></div></div>
        <div class="item"><div class="label">SHIF No.</div><div class="val"><?php echo htmlspecialchars($e['shif_number']); ?></div></div>
        <div class="item"><div class="label">Job Title</div><div class="val"><?php echo htmlspecialchars($e['role_title'] ?: '—'); ?></div></div>
        <div class="item"><div class="label">Department</div><div class="val"><?php echo htmlspecialchars($e['department'] ?: '—'); ?></div></div>
    </div>

    <!-- Earnings -->
    <div class="section-title">Earnings</div>
    <table>
        <thead><tr><th>Description</th><th class="r">Amount (KSh)</th></tr></thead>
        <tbody>
            <tr><td>Basic Salary</td><td class="r"><?php echo number_format($e['basic_salary'], 2); ?></td></tr>
            <?php if ($e['house_allowance'] > 0): ?>
            <tr><td>House Allowance</td><td class="r"><?php echo number_format($e['house_allowance'], 2); ?></td></tr>
            <?php endif; ?>
            <?php if ($e['transport_allowance'] > 0): ?>
            <tr><td>Transport Allowance</td><td class="r"><?php echo number_format($e['transport_allowance'], 2); ?></td></tr>
            <?php endif; ?>
            <?php if ($e['medical_allowance'] > 0): ?>
            <tr><td>Medical Allowance</td><td class="r"><?php echo number_format($e['medical_allowance'], 2); ?></td></tr>
            <?php endif; ?>
            <?php if ($e['other_allowances'] > 0): ?>
            <tr><td>Other Allowances</td><td class="r"><?php echo number_format($e['other_allowances'], 2); ?></td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr class="total-row"><td>Gross Pay</td><td class="r"><?php echo number_format($e['gross_pay'], 2); ?></td></tr>
        </tfoot>
    </table>

    <!-- Deductions -->
    <div class="section-title">Deductions</div>
    <table>
        <thead><tr><th>Description</th><th class="r">Amount (KSh)</th></tr></thead>
        <tbody>
            <tr><td>NSSF (Employee)</td><td class="r"><?php echo number_format($e['nssf_employee'], 2); ?></td></tr>
            <tr><td>SHIF / NHIF</td><td class="r"><?php echo number_format($e['shif'], 2); ?></td></tr>
            <tr><td>Affordable Housing Levy</td><td class="r"><?php echo number_format($e['housing_levy'], 2); ?></td></tr>
            <tr><td>PAYE (Tax)</td><td class="r"><?php echo number_format($e['paye'], 2); ?></td></tr>
            <?php if ($e['helb'] > 0): ?>
            <tr><td>HELB Deduction</td><td class="r"><?php echo number_format($e['helb'], 2); ?></td></tr>
            <?php endif; ?>
            <?php if ($e['loan_deduction'] > 0): ?>
            <tr><td>Loan Deduction</td><td class="r"><?php echo number_format($e['loan_deduction'], 2); ?></td></tr>
            <?php endif; ?>
            <?php if ($e['advance_deduction'] > 0): ?>
            <tr><td>Advance Recovery</td><td class="r"><?php echo number_format($e['advance_deduction'], 2); ?></td></tr>
            <?php endif; ?>
            <?php if ($e['other_deductions'] > 0): ?>
            <tr><td>Other Deductions</td><td class="r"><?php echo number_format($e['other_deductions'], 2); ?></td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr class="total-row"><td>Total Deductions</td><td class="r"><?php echo number_format($e['total_deductions'], 2); ?></td></tr>
        </tfoot>
    </table>

    <!-- Tax computation summary -->
    <div class="section-title">Tax Computation</div>
    <table>
        <tbody>
            <tr><td>Taxable Pay</td><td class="r"><?php echo number_format($e['taxable_pay'], 2); ?></td></tr>
            <tr><td>Tax Before Relief</td><td class="r"><?php echo number_format($e['paye_before_relief'], 2); ?></td></tr>
            <tr><td>Personal Relief</td><td class="r">(<?php echo number_format($e['personal_relief'], 2); ?>)</td></tr>
            <?php if ($e['insurance_relief'] > 0): ?>
            <tr><td>Insurance Relief</td><td class="r">(<?php echo number_format($e['insurance_relief'], 2); ?>)</td></tr>
            <?php endif; ?>
            <tr class="total-row"><td>PAYE Payable</td><td class="r"><?php echo number_format($e['paye'], 2); ?></td></tr>
        </tbody>
    </table>

    <!-- Employer contributions -->
    <div class="section-title">Employer Contributions (for reference)</div>
    <table>
        <tbody>
            <tr><td>NSSF (Employer)</td><td class="r"><?php echo number_format($e['nssf_employer'], 2); ?></td></tr>
            <tr><td>Affordable Housing Levy (Employer)</td><td class="r"><?php echo number_format($e['housing_levy_employer'], 2); ?></td></tr>
        </tbody>
    </table>

    <!-- Net Pay -->
    <div class="net-box">
        <div>
            <div class="label">Net Pay for <?php echo $periodLabel; ?></div>
            <div style="font-size:10px;opacity:.75;margin-top:2px;">NSSF · SHIF · Housing Levy · PAYE deducted</div>
        </div>
        <div class="amount">KSh <?php echo number_format($e['net_pay'], 2); ?></div>
    </div>

    <div class="statutory-note">
        This payslip is computer-generated. Statutory deductions remitted to KRA (PAYE), NSSF, SHIF, and the Affordable Housing Fund as required by Kenya law.
        Personal Relief: KSh 2,400/month applied automatically.
    </div>

    <button class="print-btn" onclick="window.print()">Print / Save PDF</button>
</div>
</body>
</html>
