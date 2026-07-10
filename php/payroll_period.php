<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);
require_once __DIR__ . '/includes/payroll_calc.php';
ensurePayrollTables($pdo);

$periodId = trim($_GET['id'] ?? '');
if (!$periodId) { header('Location: payroll.php'); exit(); }

$period = $pdo->prepare("SELECT * FROM payroll_periods WHERE id=?");
$period->execute([$periodId]);
$period = $period->fetch();
if (!$period) { header('Location: payroll.php?error=Period+not+found'); exit(); }

$msg      = htmlspecialchars($_GET['success'] ?? '');
$err      = htmlspecialchars($_GET['error']   ?? '');
$isAdmin  = $_SESSION['role'] === 'admin';
$isFinal  = $period['status'] === 'Finalised';

$entries = $pdo->prepare(
    "SELECT pe.*, e.full_name, e.staff_no, e.role_title, e.department
     FROM payroll_entries pe
     JOIN employees e ON e.id = pe.employee_id
     WHERE pe.period_id = ?
     ORDER BY e.full_name"
);
$entries->execute([$periodId]);
$entries = $entries->fetchAll();

$tot = array_fill_keys(['gross','nssf_ee','nssf_er','shif','housing','housing_er','paye','helb','loan','other','total_ded','net'], 0.0);
foreach ($entries as $e) {
    $tot['gross']      += $e['gross_pay'];
    $tot['nssf_ee']    += $e['nssf_employee'];
    $tot['nssf_er']    += $e['nssf_employer'];
    $tot['shif']       += $e['shif'];
    $tot['housing']    += $e['housing_levy'];
    $tot['housing_er'] += $e['housing_levy_employer'];
    $tot['paye']       += $e['paye'];
    $tot['helb']       += $e['helb'];
    $tot['loan']       += $e['loan_deduction'];
    $tot['other']      += $e['advance_deduction'] + $e['other_deductions'];
    $tot['total_ded']  += $e['total_deductions'];
    $tot['net']        += $e['net_pay'];
}

$periodLabel = monthName((int)$period['period_month']) . ' ' . $period['period_year'];
$pageTitle   = "Payroll — {$periodLabel}";
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-6 animate-in">

<?php if ($msg): ?>
<div class="p-4 bg-green-500/10 border border-green-500/20 text-green-600 dark:text-green-400 rounded-2xl text-sm font-bold"><?php echo $msg; ?></div>
<?php endif; ?>
<?php if ($err): ?>
<div class="p-4 bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-400 rounded-2xl text-sm font-bold"><?php echo $err; ?></div>
<?php endif; ?>

<!-- Header -->
<div class="flex flex-wrap items-start justify-between gap-3">
    <div>
        <a href="payroll.php" class="text-xs text-slate-500 hover:text-green-500 font-bold">← Payroll Periods</a>
        <h1 class="text-2xl font-black text-slate-900 dark:text-white mt-1"><?php echo $periodLabel; ?> Payroll</h1>
        <div class="flex items-center gap-2 mt-1">
            <span class="badge <?php echo match($period['status']) { 'Finalised' => 'badge-green', 'Processing' => 'badge-orange', default => 'badge-slate' }; ?>">
                <?php echo $period['status']; ?>
            </span>
            <?php if ($period['notes']): ?>
            <span class="text-xs text-slate-400"><?php echo htmlspecialchars($period['notes']); ?></span>
            <?php endif; ?>
        </div>
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
        <form method="POST" action="actions/payroll_actions.php" onsubmit="return confirm('Re-generate? This overwrites existing entries.')">
            <input type="hidden" name="action" value="generate_payroll">
            <input type="hidden" name="period_id" value="<?php echo $periodId; ?>">
            <button class="btn-secondary text-sm">↺ Re-generate</button>
        </form>
        <form method="POST" action="actions/payroll_actions.php" onsubmit="return confirm('Finalise payroll? Entries will be locked.')">
            <input type="hidden" name="action" value="finalise_period">
            <input type="hidden" name="period_id" value="<?php echo $periodId; ?>">
            <button class="btn-green">✓ Finalise</button>
        </form>
        <?php elseif ($isFinal): ?>
        <form method="POST" action="actions/payroll_actions.php" onsubmit="return confirm('Reopen this finalised period?')">
            <input type="hidden" name="action" value="reopen_period">
            <input type="hidden" name="period_id" value="<?php echo $periodId; ?>">
            <button class="btn-secondary text-sm">Reopen</button>
        </form>
        <?php endif; ?>
        <?php if (!$isFinal): ?>
        <form method="POST" action="actions/payroll_actions.php" onsubmit="return confirm('Delete this period and all entries?')">
            <input type="hidden" name="action" value="delete_period">
            <input type="hidden" name="period_id" value="<?php echo $periodId; ?>">
            <button class="btn-danger text-sm">Delete</button>
        </form>
        <?php endif; ?>
        <?php if ($entries): ?>
        <a href="p9.php?year=<?php echo $period['period_year']; ?>" target="_blank" class="btn-secondary text-sm">📄 P9 Forms</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($entries): ?>
<!-- Summary cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <?php foreach ([
        ['Employees',   count($entries),  'text-blue-400',  false],
        ['Gross Pay',   $tot['gross'],    'text-slate-300', true],
        ['Deductions',  $tot['total_ded'],'text-red-400',   true],
        ['Net Pay',     $tot['net'],      'text-green-400', true],
    ] as [$lbl, $val, $col, $fmt]): ?>
    <div class="glass-card p-4">
        <p class="text-xs text-slate-500 mb-1"><?php echo $lbl; ?></p>
        <p class="text-xl font-black <?php echo $col; ?>">
            <?php echo $fmt ? 'KSh ' . number_format($val, 2) : number_format((int)$val); ?>
        </p>
    </div>
    <?php endforeach; ?>
</div>

<!-- Payroll entries table -->
<div class="glass-card overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-200 dark:border-slate-700 text-sm font-bold text-slate-900 dark:text-white">
        Entries — <?php echo count($entries); ?> employees
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 dark:border-slate-700 text-[10px] text-slate-500 uppercase tracking-wide bg-slate-50 dark:bg-slate-800/50">
                    <th class="px-4 py-2 text-left">Employee</th>
                    <th class="px-4 py-2 text-right">Basic</th>
                    <th class="px-4 py-2 text-right">Gross</th>
                    <th class="px-4 py-2 text-right">NSSF</th>
                    <th class="px-4 py-2 text-right">SHIF</th>
                    <th class="px-4 py-2 text-right">Hsg Levy</th>
                    <th class="px-4 py-2 text-right">PAYE</th>
                    <th class="px-4 py-2 text-right">Other</th>
                    <th class="px-4 py-2 text-right font-black text-slate-700 dark:text-slate-300">Net Pay</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php foreach ($entries as $e): ?>
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/20 transition-colors">
                    <td class="px-4 py-2.5">
                        <p class="font-semibold text-slate-900 dark:text-white text-xs"><?php echo htmlspecialchars($e['full_name']); ?></p>
                        <p class="text-[10px] text-slate-400"><?php echo htmlspecialchars($e['role_title'] ?? ''); ?><?php if ($e['staff_no']): ?> · <?php echo htmlspecialchars($e['staff_no']); ?><?php endif; ?></p>
                    </td>
                    <td class="px-4 py-2.5 text-right text-xs"><?php echo number_format($e['basic_salary'], 0); ?></td>
                    <td class="px-4 py-2.5 text-right text-xs"><?php echo number_format($e['gross_pay'], 0); ?></td>
                    <td class="px-4 py-2.5 text-right text-xs text-orange-500"><?php echo number_format($e['nssf_employee'], 0); ?></td>
                    <td class="px-4 py-2.5 text-right text-xs text-orange-500"><?php echo number_format($e['shif'], 0); ?></td>
                    <td class="px-4 py-2.5 text-right text-xs text-orange-500"><?php echo number_format($e['housing_levy'], 0); ?></td>
                    <td class="px-4 py-2.5 text-right text-xs text-red-500"><?php echo number_format($e['paye'], 0); ?></td>
                    <td class="px-4 py-2.5 text-right text-xs text-slate-400"><?php echo number_format($e['helb'] + $e['loan_deduction'] + $e['advance_deduction'] + $e['other_deductions'], 0); ?></td>
                    <td class="px-4 py-2.5 text-right font-bold text-green-600 dark:text-green-400 text-xs"><?php echo number_format($e['net_pay'], 0); ?></td>
                    <td class="px-4 py-2.5 text-right">
                        <div class="flex items-center gap-2 justify-end">
                            <a href="payslip.php?entry_id=<?php echo $e['id']; ?>" target="_blank" class="text-[10px] font-bold text-blue-500 hover:underline">Payslip</a>
                            <?php if ($isAdmin && !$isFinal): ?>
                            <button onclick="openEditEntry(<?php echo htmlspecialchars(json_encode($e), ENT_QUOTES); ?>)" class="text-[10px] font-bold text-green-500 hover:underline">Edit</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="border-t-2 border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800/50 font-bold text-xs">
                    <td class="px-4 py-2 text-slate-900 dark:text-white">TOTALS</td>
                    <td class="px-4 py-2 text-right">—</td>
                    <td class="px-4 py-2 text-right"><?php echo number_format($tot['gross'], 0); ?></td>
                    <td class="px-4 py-2 text-right text-orange-500"><?php echo number_format($tot['nssf_ee'], 0); ?></td>
                    <td class="px-4 py-2 text-right text-orange-500"><?php echo number_format($tot['shif'], 0); ?></td>
                    <td class="px-4 py-2 text-right text-orange-500"><?php echo number_format($tot['housing'], 0); ?></td>
                    <td class="px-4 py-2 text-right text-red-500"><?php echo number_format($tot['paye'], 0); ?></td>
                    <td class="px-4 py-2 text-right"><?php echo number_format($tot['helb'] + $tot['loan'] + $tot['other'], 0); ?></td>
                    <td class="px-4 py-2 text-right text-green-600 dark:text-green-400"><?php echo number_format($tot['net'], 0); ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Statutory Remittance Summary -->
<div class="glass-card p-6">
    <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Statutory Remittance Summary</h3>
    <div class="grid grid-cols-2 md:grid-cols-3 gap-3 text-sm">
        <?php foreach ([
            ['NSSF Employee',         $tot['nssf_ee'],              'text-orange-400'],
            ['NSSF Employer',         $tot['nssf_er'],              'text-orange-400'],
            ['Total NSSF',            $tot['nssf_ee']+$tot['nssf_er'],'text-orange-500 font-bold'],
            ['SHIF (Employee)',        $tot['shif'],                 'text-purple-400'],
            ['Housing Levy Employee', $tot['housing'],              'text-blue-400'],
            ['Housing Levy Employer', $tot['housing_er'],           'text-blue-400'],
            ['Total Housing Levy',    $tot['housing']+$tot['housing_er'],'text-blue-500 font-bold'],
            ['PAYE to KRA',          $tot['paye'],                  'text-red-500 font-bold'],
            ['HELB Deductions',       $tot['helb'],                  'text-slate-400'],
        ] as [$lbl, $val, $col]): ?>
        <div class="flex justify-between items-center py-1.5 border-b border-slate-100 dark:border-slate-700">
            <span class="text-slate-500 text-xs"><?php echo $lbl; ?></span>
            <span class="<?php echo $col; ?> text-xs">KSh <?php echo number_format($val, 2); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php else: ?>
<div class="glass-card p-16 text-center text-slate-400">
    <svg class="w-12 h-12 mx-auto mb-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
    <p class="font-medium">No payroll entries yet.</p>
    <?php if ($isAdmin && $period['status'] === 'Draft'): ?>
    <p class="text-sm mt-1">Click "⚡ Generate Payroll" above to process all active employees.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

</div><!-- .space-y-6 -->

<!-- Edit Entry Modal -->
<?php if ($isAdmin && !$isFinal): ?>
<div id="editEntryModal" class="modal-overlay hidden">
    <div class="modal-card max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white" id="editEntryTitle">Edit Entry</h3>
            <button onclick="closeModal('editEntryModal')" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
        </div>
        <form method="POST" action="actions/payroll_actions.php">
            <input type="hidden" name="action" value="update_entry">
            <input type="hidden" name="period_id" value="<?php echo $periodId; ?>">
            <input type="hidden" name="entry_id" id="edit_entry_id">
            <input type="hidden" name="insurance_premiums" value="0">
            <input type="hidden" name="mortgage_interest"  value="0">

            <div class="space-y-4">
                <p class="text-xs text-slate-500">Changes trigger a full statutory recalculation.</p>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">NSSF Scheme</label>
                        <select name="nssf_type" id="edit_nssf_type" class="form-input w-full">
                            <option value="new">New (Tier I+II)</option>
                            <option value="old">Old (KSh 200)</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Health</label>
                        <select name="use_shif" id="edit_use_shif" class="form-input w-full">
                            <option value="1">SHIF 2.75%</option>
                            <option value="0">Old NHIF</option>
                        </select>
                    </div>
                </div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest pt-1">Earnings</p>
                <div class="grid grid-cols-2 gap-3">
                    <?php foreach ([
                        ['basic_salary','Basic Salary'],['house_allowance','House Allow.'],
                        ['transport_allowance','Transport'],['medical_allowance','Medical'],['other_allowances','Other Allow.'],
                    ] as [$f,$l]): ?>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1 block"><?php echo $l; ?></label>
                        <input type="number" name="<?php echo $f; ?>" id="edit_<?php echo $f; ?>" step="0.01" min="0" class="form-input w-full" value="0">
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest pt-1">Other Deductions</p>
                <div class="grid grid-cols-2 gap-3">
                    <?php foreach ([
                        ['helb','HELB'],['loan_deduction','Loan'],
                        ['advance_deduction','Advance'],['other_deductions','Other'],
                    ] as [$f,$l]): ?>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1 block"><?php echo $l; ?></label>
                        <input type="number" name="<?php echo $f; ?>" id="edit_<?php echo $f; ?>" step="0.01" min="0" class="form-input w-full" value="0">
                    </div>
                    <?php endforeach; ?>
                </div>
                <div>
                    <label class="text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1 block">Notes</label>
                    <textarea name="notes" id="edit_notes" rows="2" class="form-input w-full"></textarea>
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
    document.getElementById('edit_entry_id').value              = e.id;
    document.getElementById('edit_basic_salary').value          = e.basic_salary;
    document.getElementById('edit_house_allowance').value       = e.house_allowance;
    document.getElementById('edit_transport_allowance').value   = e.transport_allowance;
    document.getElementById('edit_medical_allowance').value     = e.medical_allowance;
    document.getElementById('edit_other_allowances').value      = e.other_allowances;
    document.getElementById('edit_helb').value                  = e.helb;
    document.getElementById('edit_loan_deduction').value        = e.loan_deduction;
    document.getElementById('edit_advance_deduction').value     = e.advance_deduction;
    document.getElementById('edit_other_deductions').value      = e.other_deductions;
    document.getElementById('edit_notes').value                 = e.notes || '';
    openModal('editEntryModal');
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
