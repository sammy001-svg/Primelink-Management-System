<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/../includes/payroll_calc.php';
require_once __DIR__ . '/../includes/audit.php';

ensurePayrollTables($pdo);

$action   = trim($_POST['action'] ?? '');
$redirect = trim($_POST['_redirect'] ?? '../payroll.php');

// ── helpers ─────────────────────────────────────────────────────────────────
function pd(string $k, $def = ''): string { return trim((string)($_POST[$k] ?? $def)); }
function pf(string $k, float $def = 0.0): float { return (float)($_POST[$k] ?? $def); }

function flashRedirect(string $url, string $type, string $msg): void {
    $sep = str_contains($url, '?') ? '&' : '?';
    header("Location: {$url}{$sep}{$type}=" . urlencode($msg));
    exit();
}

// ── create_period ─────────────────────────────────────────────────────────
if ($action === 'create_period') {
    requireRole(['admin']);
    $year  = (int)pd('period_year');
    $month = (int)pd('period_month');
    $notes = pd('notes');
    if ($year < 2020 || $year > 2099 || $month < 1 || $month > 12) {
        flashRedirect($redirect, 'error', 'Invalid year or month');
    }
    try {
        $id = generateUUID();
        $s  = $pdo->prepare("INSERT INTO payroll_periods (id, period_year, period_month, notes, created_by) VALUES (?,?,?,?,?)");
        $s->execute([$id, $year, $month, $notes ?: null, $_SESSION['user_id']]);
        logAction($pdo, 'create', 'payroll', $id, "Created payroll period {$year}-{$month}");
        flashRedirect("../payroll_period.php?id={$id}", 'success', 'Period created');
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            $existing = $pdo->prepare("SELECT id FROM payroll_periods WHERE period_year=? AND period_month=?");
            $existing->execute([$year, $month]);
            $row = $existing->fetch();
            if ($row) { header("Location: ../payroll_period.php?id={$row['id']}"); exit(); }
        }
        flashRedirect($redirect, 'error', 'Could not create period: ' . $e->getMessage());
    }
}

// ── generate_payroll (bulk) ────────────────────────────────────────────────
if ($action === 'generate_payroll') {
    requireRole(['admin']);
    $periodId = pd('period_id');
    $period   = $pdo->prepare("SELECT * FROM payroll_periods WHERE id=?");
    $period->execute([$periodId]);
    $period = $period->fetch();
    if (!$period) { flashRedirect($redirect, 'error', 'Period not found'); }
    if ($period['status'] === 'Finalised') { flashRedirect($redirect, 'error', 'Period already finalised'); }

    // Fetch all active employees + their tax profile
    $emps = $pdo->query(
        "SELECT e.*, COALESCE(tp.kra_pin,'') kra_pin,
            COALESCE(tp.nssf_number,'') nssf_number,
            COALESCE(tp.shif_number,'') shif_number,
            COALESCE(tp.house_allowance,0)     house_allowance,
            COALESCE(tp.transport_allowance,0) transport_allowance,
            COALESCE(tp.medical_allowance,0)   medical_allowance,
            COALESCE(tp.other_allowances,0)    other_allowances,
            COALESCE(tp.helb_amount,0)         helb_amount,
            COALESCE(tp.loan_amount,0)         loan_amount,
            COALESCE(tp.insurance_premiums,0)  insurance_premiums,
            COALESCE(tp.mortgage_interest,0)   mortgage_interest,
            COALESCE(tp.nssf_type,'new')       nssf_type,
            COALESCE(tp.use_shif,1)            use_shif
         FROM employees e
         LEFT JOIN employee_tax_profile tp ON tp.employee_id = e.id
         WHERE e.status = 'Active'"
    )->fetchAll();

    $ins = $pdo->prepare(
        "INSERT INTO payroll_entries
            (id, period_id, employee_id, basic_salary, house_allowance, transport_allowance,
             medical_allowance, other_allowances, gross_pay, nssf_employee, nssf_employer,
             shif, housing_levy, housing_levy_employer, paye, taxable_pay, paye_before_relief,
             personal_relief, insurance_relief, helb, loan_deduction, advance_deduction,
             other_deductions, total_deductions, net_pay)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            basic_salary=VALUES(basic_salary), house_allowance=VALUES(house_allowance),
            transport_allowance=VALUES(transport_allowance), medical_allowance=VALUES(medical_allowance),
            other_allowances=VALUES(other_allowances), gross_pay=VALUES(gross_pay),
            nssf_employee=VALUES(nssf_employee), nssf_employer=VALUES(nssf_employer),
            shif=VALUES(shif), housing_levy=VALUES(housing_levy),
            housing_levy_employer=VALUES(housing_levy_employer), paye=VALUES(paye),
            taxable_pay=VALUES(taxable_pay), paye_before_relief=VALUES(paye_before_relief),
            personal_relief=VALUES(personal_relief), insurance_relief=VALUES(insurance_relief),
            helb=VALUES(helb), loan_deduction=VALUES(loan_deduction),
            advance_deduction=VALUES(advance_deduction), other_deductions=VALUES(other_deductions),
            total_deductions=VALUES(total_deductions), net_pay=VALUES(net_pay)"
    );

    $count = 0;
    foreach ($emps as $e) {
        $c = calculateFullPayroll((float)$e['salary'], $e);
        $ins->execute([
            generateUUID(), $periodId, $e['id'],
            $c['basic_salary'], $c['house_allowance'], $c['transport_allowance'],
            $c['medical_allowance'], $c['other_allowances'], $c['gross_pay'],
            $c['nssf_employee'], $c['nssf_employer'], $c['shif'],
            $c['housing_levy'], $c['housing_levy_employer'],
            $c['paye'], $c['taxable_pay'], $c['paye_before_relief'],
            $c['personal_relief'], $c['insurance_relief'],
            $c['helb'], $c['loan_deduction'], $c['advance_deduction'],
            $c['other_deductions'], $c['total_deductions'], $c['net_pay'],
        ]);
        $count++;
    }
    // Move to Processing status
    $pdo->prepare("UPDATE payroll_periods SET status='Processing' WHERE id=? AND status='Draft'")->execute([$periodId]);
    logAction($pdo, 'generate', 'payroll', $periodId, "Generated payroll for {$count} employees");
    flashRedirect("../payroll_period.php?id={$periodId}", 'success', "Payroll generated for {$count} employees");
}

// ── update_entry ───────────────────────────────────────────────────────────
if ($action === 'update_entry') {
    requireRole(['admin']);
    $entryId  = pd('entry_id');
    $periodId = pd('period_id');

    // Re-calculate from posted values
    $tp = [
        'house_allowance'     => pf('house_allowance'),
        'transport_allowance' => pf('transport_allowance'),
        'medical_allowance'   => pf('medical_allowance'),
        'other_allowances'    => pf('other_allowances'),
        'helb_amount'         => pf('helb'),
        'loan_amount'         => pf('loan_deduction'),
        'advance_deduction'   => pf('advance_deduction'),
        'other_deduction'     => pf('other_deductions'),
        'insurance_premiums'  => pf('insurance_premiums'),
        'mortgage_interest'   => pf('mortgage_interest'),
        'nssf_type'           => pd('nssf_type', 'new'),
        'use_shif'            => (pd('use_shif', '1') === '1') ? true : false,
    ];
    $c = calculateFullPayroll(pf('basic_salary'), $tp);

    $upd = $pdo->prepare(
        "UPDATE payroll_entries SET
            basic_salary=?, house_allowance=?, transport_allowance=?,
            medical_allowance=?, other_allowances=?, gross_pay=?,
            nssf_employee=?, nssf_employer=?, shif=?,
            housing_levy=?, housing_levy_employer=?,
            paye=?, taxable_pay=?, paye_before_relief=?, personal_relief=?, insurance_relief=?,
            helb=?, loan_deduction=?, advance_deduction=?, other_deductions=?,
            total_deductions=?, net_pay=?, notes=?
         WHERE id=?"
    );
    $upd->execute([
        $c['basic_salary'], $c['house_allowance'], $c['transport_allowance'],
        $c['medical_allowance'], $c['other_allowances'], $c['gross_pay'],
        $c['nssf_employee'], $c['nssf_employer'], $c['shif'],
        $c['housing_levy'], $c['housing_levy_employer'],
        $c['paye'], $c['taxable_pay'], $c['paye_before_relief'],
        $c['personal_relief'], $c['insurance_relief'],
        $c['helb'], $c['loan_deduction'], $c['advance_deduction'],
        $c['other_deductions'], $c['total_deductions'], $c['net_pay'],
        pd('notes'), $entryId,
    ]);
    flashRedirect("../payroll_period.php?id={$periodId}", 'success', 'Entry updated');
}

// ── finalise_period ───────────────────────────────────────────────────────
if ($action === 'finalise_period') {
    requireRole(['admin']);
    $periodId = pd('period_id');
    $s = $pdo->prepare("UPDATE payroll_periods SET status='Finalised', processed_at=NOW() WHERE id=? AND status='Processing'");
    $s->execute([$periodId]);
    logAction($pdo, 'finalise', 'payroll', $periodId, 'Payroll period finalised');
    flashRedirect("../payroll_period.php?id={$periodId}", 'success', 'Period finalised');
}

// ── reopen_period ─────────────────────────────────────────────────────────
if ($action === 'reopen_period') {
    requireRole(['admin']);
    $periodId = pd('period_id');
    $pdo->prepare("UPDATE payroll_periods SET status='Processing', processed_at=NULL WHERE id=?")->execute([$periodId]);
    flashRedirect("../payroll_period.php?id={$periodId}", 'success', 'Period reopened');
}

// ── delete_period ─────────────────────────────────────────────────────────
if ($action === 'delete_period') {
    requireRole(['admin']);
    $periodId = pd('period_id');
    $pdo->prepare("DELETE FROM payroll_periods WHERE id=? AND status != 'Finalised'")->execute([$periodId]);
    flashRedirect('../payroll.php', 'success', 'Period deleted');
}

// ── save_tax_profile ──────────────────────────────────────────────────────
if ($action === 'save_tax_profile') {
    $empId = pd('employee_id');
    $pdo->prepare(
        "INSERT INTO employee_tax_profile
            (employee_id, kra_pin, nssf_number, shif_number,
             house_allowance, transport_allowance, medical_allowance, other_allowances,
             helb_amount, loan_amount, insurance_premiums, mortgage_interest,
             nssf_type, use_shif)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            kra_pin=VALUES(kra_pin), nssf_number=VALUES(nssf_number),
            shif_number=VALUES(shif_number),
            house_allowance=VALUES(house_allowance),
            transport_allowance=VALUES(transport_allowance),
            medical_allowance=VALUES(medical_allowance),
            other_allowances=VALUES(other_allowances),
            helb_amount=VALUES(helb_amount), loan_amount=VALUES(loan_amount),
            insurance_premiums=VALUES(insurance_premiums),
            mortgage_interest=VALUES(mortgage_interest),
            nssf_type=VALUES(nssf_type), use_shif=VALUES(use_shif)"
    )->execute([
        $empId, pd('kra_pin'), pd('nssf_number'), pd('shif_number'),
        pf('house_allowance'), pf('transport_allowance'),
        pf('medical_allowance'), pf('other_allowances'),
        pf('helb_amount'), pf('loan_amount'),
        pf('insurance_premiums'), pf('mortgage_interest'),
        pd('nssf_type', 'new'), (pd('use_shif', '1') === '1') ? 1 : 0,
    ]);
    logAction($pdo, 'update', 'hr', $empId, 'Tax profile updated');
    flashRedirect($redirect, 'success', 'Tax profile saved');
}

flashRedirect($redirect, 'error', "Unknown action: {$action}");
