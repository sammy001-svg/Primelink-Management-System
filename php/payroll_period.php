<?php
require_once 'includes/auth.php';
requireRole(['admin', 'staff']);
require_once 'includes/payroll_calc.php';
ensurePayrollTables($pdo);

$periodId = trim($_GET['id'] ?? '');
if (!$periodId) { header('Location: payroll.php'); exit(); }

$period = $pdo->prepare("SELECT * FROM payroll_periods WHERE id=?");
$period->execute([$periodId]);
$period = $period->fetch();
if (!$period) { header('Location: payroll.php?error=Period+not+found'); exit(); }

$msg = htmlspecialchars($_GET['success'] ?? '');
$err = htmlspecialchars($_GET['error']   ?? '');

$isAdmin    = $_SESSION['role'] === 'admin';
$isFinalised = $period['status'] === 'Finalised';

// Fetch entries with employee details
$entries = $pdo->prepare(
    "SELECT pe.*, e.full_name, e.staff_no, e.role_title, e.department,
            COALESCE(tp.kra_pin,'') kra_pin
     FROM payroll_entries pe
     JOIN employees e ON e.id = pe.employee_id
     LEFT JOIN employee_tax_profile tp ON tp.employee_id = pe.employee_id
     WHERE pe.period_id = ?
     ORDER BY e.full_name"
);
$entries->execute([$periodId]);
$entries = $entries->fetchAll();

// Totals
$totals = [
    'gross'     => 0, 'nssf_emp'  => 0, 'nssf_er'  => 0,
    'shif'      => 0, 'housing'   => 0, 'housing_er'=> 0,
    'paye'      => 0, 'helb'      => 0, 'loan'      => 0,
    'other_ded' => 0, 'total_ded' => 0, 'net'       => 0,
];
foreach ($entries as $e) {
    $totals['gross']     += $e['gross_pay'];
    $totals['nssf_emp']  += $e['nssf_employee'];
    $totals['nssf_er']   += $e['nssf_employer'];
    $totals['shif']      += $e['shif'];
    $totals['housing']   += $e['housing_levy'];
    $totals['housing_er']+= $e['housing_levy_employer'];
    $totals['paye']      += $e['paye'];
    $totals['helb']      += $e['helb'];
    $totals['loan']      += $e['loan_deduction'];
    $totals['other_ded'] += $e['other_deductions'];
    $totals['total_ded'] += $e['total_deductions'];
    $totals['net']       += $e['net_pay'];
}

$periodLabel = monthName((int)$period['period_month']) . ' ' . $period['period_year'];
$pageTitle   = "Payroll — {$periodLabel}";
include 'includes/header.php';
include 'includes/sidebar.php';
?>
<div class="main-content">
<?php include 'includes/topbar.php'; ?>
<div class="page-body">

<?php if ($msg): ?><div class="alert-success mb-4"><?php echo $msg; ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert-error mb-4"><?php echo $err; ?></div><?php endif; ?>

<!-- Header -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div>
        <a href="payroll.php" class="text-xs text-slate-500 hover:text-green-500">← All Periods</a>
        <h1 class="text-2xl font-black text-slate-900 dark:text-white mt-0.5"><?php echo $periodLabel; ?> Payroll</h1>
        <p class="text-sm text-slate-500">
            <span class="badge <?php echo match($period['status']) { 'Finalised' => 'badge-green', 'Processing' => 'badge-yellow', default => 'badge-gray' }; ?>"><?php echo $period['status']; ?></span>
            <?php if ($period['notes']): ?>&nbsp;· <?php echo htmlspecialchars($period['notes']); ?><?php endif; ?>
        </p>
    </div>
    <?php if ($isAdmin): ?>
    <div class="flex gap-2 flex-wrap">
        <?php if ($period['status'] === 'Draft'): ?>
        <form method="POST" action="actions/payroll_actions.php" onsubmit="return confirm('Generate payroll for all active employees?')">
            <input type="hidden" name="action" value="generate_payroll">
            <input type="hidden" name="period_id" value="<?php echo $periodId; ?>">
            <button class="btn-primary">⚡ Generate Payroll</button>
        </form>
        <?php elseif ($period['status'] === 'Processing'): ?>
        <form method="POST" action="actions/payroll_actions.php">
            <input type="hidden" name="action" value="generate_payroll">
            <input type="hidden" name="period_id" value="<?php echo $periodId; ?>">
            <button class="btn-secondary text-sm">↺ Re-generate</button>
        </form>
        <form method="POST" action="actions/payroll_actions.php" onsubmit="return confirm('Finalise this payroll? This cannot be undone.')">
            <input type="hidden" name="action" value="finalise_period">
            <input type="hidden" name="period_id" value="<?php echo $periodId; ?>">
            <button class="btn-green">✓ Finalise</button>
        </form>
        <?php elseif ($isFinalised): ?>
        <form method="POST" action="actions/payroll_actions.php" onsubmit="return confirm('Reopen this finalised period?')">
            <input type="hidden" name="action" value="reopen_period">
            <input type="hidden" name="period_id" value="<?php echo $periodId; ?>">
            <button class="btn-secondary text-sm">Reopen</button>
        </form>
        <?php endif; ?>
        <?php if ($period['status'] !== 'Finalised'): ?>
        <form method="POST" action="actions/payroll_actions.php" onsubmit="return confirm('Delete this period and all its entries?')">
            <input type="hidden" name="action" value="delete_period">
            <input type="hidden" name="period_id" value="<?php echo $periodId; ?>">
            <button class="btn-danger text-sm">Delete</button>
        </form>
        <?php endif; ?>
        <?php if ($entries): ?>
        <a href="p9.php?year=<?php echo $period['period_year']; ?>" class="btn-secondary text-sm" target="_blank">📄 P9 Forms</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Summary cards -->
<?php if ($entries): ?>
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <?php $cards = [
        ['Employees',   count($entries),                   'text-blue-400',  false],
        ['Gross Pay',   $totals['gross'],                  'text-slate-300', true],
        ['Deductions',  $totals['total_ded'],              'text-red-400',   true],
        ['Net Pay',     $totals['net'],                    'text-green-400', true],
    ];
    foreach ($cards as [$lbl, $val, $col, $fmt]): ?>
    <div class="glass-card p-4">
        <p class="text-xs text-slate-500 mb-1"><?php echo $lbl; ?></p>
        <p class="text-xl font-black <?php echo $col; ?>">
            <?php echo $fmt ? 'KSh ' . number_format($val, 2) : number_format($val); ?>
        </p>
    </div>
    <?php endforeach; ?>
</div>

<!-- Payroll table -->
<div class="glass-card overflow-hidden mb-6">
    <div class="px-5 py-3 border-b border-slate-200 dark:border-slate-700 text-sm font-bold text-slate-900 dark:text-white">
        Payroll Entries — <?php echo $periodLabel; ?>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 dark:border-slate-700 text-xs text-slate-500 uppercase tracking-wide">
                    <th class="px-4 py-2 text-left">Employee</th>
                    <th class="px-4 py-2 text-right">Basic</th>
                    <th class="px-4 py-2 text-right">Gross</th>
                    <th class="px-4 py-2 text-right">NSSF</th>
                    <th class="px-4 py-2 text-right">SHIF</th>
                    <th class="px-4 py-2 text-right">Housing</th>
                    <th class="px-4 py-2 text-right">PAYE</th>
                    <th class="px-4 py-2 text-right">Other</th>
                    <th class="px-4 py-2 text-right font-bold">Net Pay</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php foreach ($entries as $e): ?>
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/20">
                    <td class="px-4 py-2">
                        <p class="font-semibold text-slate-900 dark:text-white"><?php echo htmlspecialchars($e['full_name']); ?></p>
                        <p class="text-xs text-slate-400"><?php echo htmlspecialchars($e['role_title'] ?? ''); ?><?php if ($e['staff_no']): ?> · <?php echo htmlspecialchars($e['staff_no']); ?><?php endif; ?></p>
                    </td>
                    <td class="px-4 py-2 text-right"><?php echo number_format($e['basic_salary'], 0); ?></td>
                    <td class="px-4 py-2 text-right"><?php echo number_format($e['gross_pay'], 0); ?></td>
                    <td class="px-4 py-2 text-right text-orange-500"><?php echo number_format($e['nssf_employee'], 0); ?></td>
                    <td class="px-4 py-2 text-right text-orange-500"><?php echo number_format($e['shif'], 0); ?></td>
                    <td class="px-4 py-2 text-right text-orange-500"><?php echo number_format($e['housing_levy'], 0); ?></td>
                    <td class="px-4 py-2 text-right text-red-500"><?php echo number_format($e['paye'], 0); ?></td>
                    <td class="px-4 py-2 text-right text-slate-400"><?php echo number_format($e['helb'] + $e['loan_deduction'] + $e['advance_deduction'] + $e['other_deductions'], 0); ?></td>
                    <td class="px-4 py-2 text-right font-bold text-green-600 dark:text-green-400"><?php echo number_format($e['net_pay'], 0); ?></td>
                    <td class="px-4 py-2 text-right flex gap-1 justify-end">
                        <a href="payslip.php?entry_id=<?php echo $e['id']; ?>" target="_blank" class="text-xs text-blue-500 hover:underline">Payslip</a>
                        <?php if ($isAdmin && !$isFinalised): ?>
                        <button onclick="openEditEntry(<?php echo htmlspecialchars(json_encode($e), ENT_QUOTES); ?>)" class="text-xs text-green-500 hover:underline ml-2">Edit</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="border-t-2 border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800/50 font-bold text-slate-900 dark:text-white text-xs">
                <tr>
                    <td class="px-4 py-2">TOTALS</td>
                    <td class="px-4 py-2 text-right">—</td>
                    <td class="px-4 py-2 text-right"><?php echo number_format($totals['gross'], 0); ?></td>
                    <td class="px-4 py-2 text-right text-orange-500"><?php echo number_format($totals['nssf_emp'], 0); ?></td>
                    <td class="px-4 py-2 text-right text-orange-500"><?php echo number_format($totals['shif'], 0); ?></td>
                    <td class="px-4 py-2 text-right text-orange-500"><?php echo number_format($totals['housing'], 0); ?></td>
                    <td class="px-4 py-2 text-right text-red-500"><?php echo number_format($totals['paye'], 0); ?></td>
                    <td class="px-4 py-2 text-right"><?php echo number_format($totals['helb']+$totals['loan']+$totals['other_ded'], 0); ?></td>
                    <td class="px-4 py-2 text-right text-green-600 dark:text-green-400"><?php echo number_format($totals['net'], 0); ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Statutory Summary -->
<div class="glass-card p-5 mb-6">
    <h3 class="font-bold text-slate-900 dark:text-white mb-4">Statutory Remittance Summary</h3>
    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
        <?php $remit = [
            ['NSSF (Employee)',           $totals['nssf_emp'],  'text-orange-400'],
            ['NSSF (Employer)',           $totals['nssf_er'],   'text-orange-400'],
            ['Total NSSF',                $totals['nssf_emp']+$totals['nssf_er'], 'text-orange-500 font-bold'],
            ['SHIF (Employee)',           $totals['shif'],      'text-purple-400'],
            ['Housing Levy (Employee)',   $totals['housing'],   'text-blue-400'],
            ['Housing Levy (Employer)',   $totals['housing_er'],'text-blue-400'],
            ['Total Housing Levy',        $totals['housing']+$totals['housing_er'], 'text-blue-500 font-bold'],
            ['PAYE (to KRA)',             $totals['paye'],      'text-red-400'],
            ['HELB',                      $totals['helb'],      'text-slate-400'],
        ];
        foreach ($remit as [$lbl, $val, $col]): ?>
        <div class="flex justify-between items-center py-1 border-b border-slate-100 dark:border-slate-700">
            <span class="text-slate-500"><?php echo $lbl; ?></span>
            <span class="font-semibold <?php echo $col; ?>">KSh <?php echo number_format($val, 2); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php else: ?>
<div class="glass-card p-12 text-center text-slate-400">
    <svg class="w-12 h-12 mx-auto mb-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M12 2v20M2 12h20"/></svg>
    <p class="font-medium">No payroll entries yet.</p>
    <?php if ($isAdmin && $period['status'] === 'Draft'): ?>
    <p class="text-sm mt-1">Click "Generate Payroll" above to process all active employees.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

</div><!-- .page-body -->
</div><!-- .main-content -->

<!-- Edit Entry Modal -->
<div id="editEntryModal" class="modal-overlay hidden">
    <div class="modal-card max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-5 sticky top-0 bg-white dark:bg-slate-800 pb-3 border-b border-slate-200 dark:border-slate-700">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white" id="editEntryTitle">Edit Entry</h3>
            <button onclick="closeModal('editEntryModal')" class="text-slate-400 hover:text-slate-600 text-xl">&times;</button>
        </div>
        <form method="POST" action="actions/payroll_actions.php" id="editEntryForm">
            <input type="hidden" name="action" value="update_entry">
            <input type="hidden" name="period_id" value="<?php echo $periodId; ?>">
            <input type="hidden" name="entry_id" id="edit_entry_id">
            <input type="hidden" name="insurance_premiums" id="edit_ins" value="0">
            <input type="hidden" name="mortgage_interest"  id="edit_mort" value="0">

            <div class="space-y-4">
                <p class="text-xs text-slate-500">Changes are recalculated using statutory rates.</p>

                <!-- NSSF / SHIF toggles -->
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1 block">NSSF Type</label>
                        <select name="nssf_type" id="edit_nssf_type" class="form-input">
                            <option value="new">New (Tier I+II)</option>
                            <option value="old">Old (KSh 200 flat)</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1 block">Health Levy</label>
                        <select name="use_shif" id="edit_use_shif" class="form-input">
                            <option value="1">SHIF (2.75%)</option>
                            <option value="0">Old NHIF (tiered)</option>
                        </select>
                    </div>
                </div>

                <h4 class="text-sm font-bold text-slate-600 dark:text-slate-400 pt-2">Earnings</h4>
                <div class="grid grid-cols-2 gap-4">
                    <?php foreach ([
                        ['basic_salary',        'Basic Salary'],
                        ['house_allowance',      'House Allowance'],
                        ['transport_allowance',  'Transport Allowance'],
                        ['medical_allowance',    'Medical Allowance'],
                        ['other_allowances',     'Other Allowances'],
                    ] as [$fname, $flabel]): ?>
                    <div>
                        <label class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1 block"><?php echo $flabel; ?></label>
                        <input type="number" name="<?php echo $fname; ?>" id="edit_<?php echo $fname; ?>" step="0.01" min="0" class="form-input" value="0">
                    </div>
                    <?php endforeach; ?>
                </div>

                <h4 class="text-sm font-bold text-slate-600 dark:text-slate-400 pt-2">Other Deductions</h4>
                <div class="grid grid-cols-2 gap-4">
                    <?php foreach ([
                        ['helb',             'HELB'],
                        ['loan_deduction',   'Loan Deduction'],
                        ['advance_deduction','Advance Recovery'],
                        ['other_deductions', 'Other Deductions'],
                    ] as [$fname, $flabel]): ?>
                    <div>
                        <label class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1 block"><?php echo $flabel; ?></label>
                        <input type="number" name="<?php echo $fname; ?>" id="edit_<?php echo $fname; ?>" step="0.01" min="0" class="form-input" value="0">
                    </div>
                    <?php endforeach; ?>
                </div>

                <div>
                    <label class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1 block">Notes</label>
                    <textarea name="notes" id="edit_notes" rows="2" class="form-input"></textarea>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="submit" class="btn-primary flex-1">Save & Recalculate</button>
                <button type="button" onclick="closeModal('editEntryModal')" class="btn-secondary flex-1">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditEntry(e) {
    document.getElementById('editEntryTitle').textContent = 'Edit — ' + e.full_name;
    document.getElementById('edit_entry_id').value         = e.id;
    document.getElementById('edit_basic_salary').value     = e.basic_salary;
    document.getElementById('edit_house_allowance').value  = e.house_allowance;
    document.getElementById('edit_transport_allowance').value = e.transport_allowance;
    document.getElementById('edit_medical_allowance').value= e.medical_allowance;
    document.getElementById('edit_other_allowances').value = e.other_allowances;
    document.getElementById('edit_helb').value             = e.helb;
    document.getElementById('edit_loan_deduction').value   = e.loan_deduction;
    document.getElementById('edit_advance_deduction').value= e.advance_deduction;
    document.getElementById('edit_other_deductions').value = e.other_deductions;
    document.getElementById('edit_notes').value            = e.notes ?? '';
    openModal('editEntryModal');
}
</script>

<?php include 'includes/footer.php'; ?>
