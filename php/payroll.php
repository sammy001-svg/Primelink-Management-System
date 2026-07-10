<?php
require_once 'includes/auth.php';
requireRole(['admin', 'staff']);
require_once 'includes/payroll_calc.php';
ensurePayrollTables($pdo);

$msg = htmlspecialchars($_GET['success'] ?? '');
$err = htmlspecialchars($_GET['error']   ?? '');

// Stats
$statsRow  = $pdo->query("SELECT COUNT(*) total, SUM(status='Draft') drafts, SUM(status='Processing') processing, SUM(status='Finalised') finalised FROM payroll_periods")->fetch();
$lastEntry = $pdo->query("SELECT SUM(net_pay) total_net, COUNT(DISTINCT employee_id) headcount FROM payroll_entries pe JOIN payroll_periods pp ON pp.id=pe.period_id WHERE pp.status='Finalised' ORDER BY pp.period_year DESC, pp.period_month DESC LIMIT 1")->fetch();

// Periods list
$periods = $pdo->query(
    "SELECT pp.*, COUNT(pe.id) entry_count, SUM(pe.gross_pay) total_gross, SUM(pe.net_pay) total_net
     FROM payroll_periods pp
     LEFT JOIN payroll_entries pe ON pe.period_id = pp.id
     GROUP BY pp.id
     ORDER BY pp.period_year DESC, pp.period_month DESC"
)->fetchAll();

$curYear  = (int)date('Y');
$curMonth = (int)date('n');

$pageTitle = 'Payroll';
include 'includes/header.php';
include 'includes/sidebar.php';
?>
<div class="main-content">
<?php include 'includes/topbar.php'; ?>
<div class="page-body">

<?php if ($msg): ?><div class="alert-success mb-4"><?php echo $msg; ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert-error mb-4"><?php echo $err; ?></div><?php endif; ?>

<!-- Header -->
<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-2xl font-black text-slate-900 dark:text-white">Payroll</h1>
        <p class="text-sm text-slate-500 mt-0.5">Process monthly payroll with statutory deductions</p>
    </div>
    <?php if ($_SESSION['role'] === 'admin'): ?>
    <button onclick="openModal('createPeriodModal')" class="btn-primary">+ New Payroll Period</button>
    <?php endif; ?>
</div>

<!-- Stats row -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <?php
    $stats = [
        ['Total Periods',  $statsRow['total']      ?? 0, 'text-blue-400'],
        ['Draft',          $statsRow['drafts']      ?? 0, 'text-slate-400'],
        ['Processing',     $statsRow['processing']  ?? 0, 'text-yellow-400'],
        ['Finalised',      $statsRow['finalised']   ?? 0, 'text-green-400'],
    ];
    foreach ($stats as [$label, $val, $col]): ?>
    <div class="glass-card p-4">
        <p class="text-xs text-slate-500 mb-1"><?php echo $label; ?></p>
        <p class="text-2xl font-black <?php echo $col; ?>"><?php echo number_format($val); ?></p>
    </div>
    <?php endforeach; ?>
</div>

<!-- Periods table -->
<div class="glass-card overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between">
        <h2 class="font-bold text-slate-900 dark:text-white">Payroll Periods</h2>
    </div>
    <?php if (!$periods): ?>
    <div class="p-12 text-center text-slate-400">
        <svg class="w-12 h-12 mx-auto mb-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M9 7H6a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-3"/><path d="M9 7V4a1 1 0 0 1 1-1h8l3 3v8a1 1 0 0 1-1 1h-3"/></svg>
        <p>No payroll periods yet. Create the first one.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 dark:border-slate-700 text-left text-xs text-slate-500 uppercase tracking-wide">
                    <th class="px-5 py-3">Period</th>
                    <th class="px-5 py-3">Status</th>
                    <th class="px-5 py-3">Employees</th>
                    <th class="px-5 py-3">Gross Pay</th>
                    <th class="px-5 py-3">Net Pay</th>
                    <th class="px-5 py-3">Created</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php foreach ($periods as $p):
                    $statusCls = match($p['status']) {
                        'Finalised'  => 'badge badge-green',
                        'Processing' => 'badge badge-yellow',
                        default      => 'badge badge-gray',
                    };
                ?>
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/30">
                    <td class="px-5 py-3 font-bold text-slate-900 dark:text-white">
                        <?php echo monthName((int)$p['period_month']) . ' ' . $p['period_year']; ?>
                    </td>
                    <td class="px-5 py-3"><span class="<?php echo $statusCls; ?>"><?php echo $p['status']; ?></span></td>
                    <td class="px-5 py-3"><?php echo number_format((int)$p['entry_count']); ?></td>
                    <td class="px-5 py-3">KSh <?php echo number_format((float)$p['total_gross'], 2); ?></td>
                    <td class="px-5 py-3 font-semibold text-green-600 dark:text-green-400">KSh <?php echo number_format((float)$p['total_net'], 2); ?></td>
                    <td class="px-5 py-3 text-slate-500"><?php echo date('d M Y', strtotime($p['created_at'])); ?></td>
                    <td class="px-5 py-3">
                        <a href="payroll_period.php?id=<?php echo $p['id']; ?>" class="btn-primary text-xs py-1 px-3">Open →</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

</div><!-- .page-body -->
</div><!-- .main-content -->

<!-- Create Period Modal -->
<div id="createPeriodModal" class="modal-overlay hidden">
    <div class="modal-card max-w-md w-full">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">New Payroll Period</h3>
            <button onclick="closeModal('createPeriodModal')" class="text-slate-400 hover:text-slate-600 text-xl">&times;</button>
        </div>
        <form method="POST" action="actions/payroll_actions.php">
            <input type="hidden" name="action" value="create_period">
            <input type="hidden" name="_redirect" value="payroll.php">
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Year</label>
                        <select name="period_year" class="form-input">
                            <?php for ($y = $curYear; $y >= $curYear - 3; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y === $curYear ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Month</label>
                        <select name="period_month" class="form-input">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m === $curMonth ? 'selected' : ''; ?>><?php echo monthName($m); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Notes (optional)</label>
                    <textarea name="notes" rows="2" class="form-input" placeholder="Any notes about this period..."></textarea>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="submit" class="btn-primary flex-1">Create Period</button>
                <button type="button" onclick="closeModal('createPeriodModal')" class="btn-secondary flex-1">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
