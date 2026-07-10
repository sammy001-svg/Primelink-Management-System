<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);
require_once __DIR__ . '/includes/payroll_calc.php';
ensurePayrollTables($pdo);

$msg = htmlspecialchars($_GET['success'] ?? '');
$err = htmlspecialchars($_GET['error']   ?? '');

$statsRow = $pdo->query(
    "SELECT COUNT(*) total, SUM(status='Draft') drafts, SUM(status='Processing') processing, SUM(status='Finalised') finalised FROM payroll_periods"
)->fetch();

$periods = $pdo->query(
    "SELECT pp.*, COUNT(pe.id) entry_count, COALESCE(SUM(pe.gross_pay),0) total_gross, COALESCE(SUM(pe.net_pay),0) total_net
     FROM payroll_periods pp
     LEFT JOIN payroll_entries pe ON pe.period_id = pp.id
     GROUP BY pp.id
     ORDER BY pp.period_year DESC, pp.period_month DESC"
)->fetchAll();

$curYear  = (int)date('Y');
$curMonth = (int)date('n');
$isAdmin  = $_SESSION['role'] === 'admin';

$pageTitle = 'Payroll';
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
<div class="flex items-center justify-between">
    <div>
        <h1 class="text-2xl font-black text-slate-900 dark:text-white">Payroll</h1>
        <p class="text-sm text-slate-500 mt-0.5">Process monthly staff payroll with statutory deductions</p>
    </div>
    <?php if ($isAdmin): ?>
    <button onclick="openModal('createPeriodModal')" class="btn-primary">+ New Period</button>
    <?php endif; ?>
</div>

<!-- Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <?php foreach ([
        ['Total Periods', $statsRow['total']      ?? 0, 'text-blue-400'],
        ['Draft',         $statsRow['drafts']      ?? 0, 'text-slate-400'],
        ['Processing',    $statsRow['processing']  ?? 0, 'text-yellow-400'],
        ['Finalised',     $statsRow['finalised']   ?? 0, 'text-green-400'],
    ] as [$label, $val, $col]): ?>
    <div class="glass-card p-4">
        <p class="text-xs text-slate-500 mb-1"><?php echo $label; ?></p>
        <p class="text-2xl font-black <?php echo $col; ?>"><?php echo number_format((int)$val); ?></p>
    </div>
    <?php endforeach; ?>
</div>

<!-- Periods table -->
<div class="glass-card overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-200 dark:border-slate-700 font-bold text-slate-900 dark:text-white text-sm">
        Payroll Periods
    </div>
    <?php if (!$periods): ?>
    <div class="p-12 text-center text-slate-400">
        <svg class="w-12 h-12 mx-auto mb-3 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
        <p>No payroll periods yet.</p>
        <?php if ($isAdmin): ?><p class="text-sm mt-1">Click "+ New Period" to get started.</p><?php endif; ?>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 dark:border-slate-700 text-xs text-slate-500 uppercase tracking-wide">
                    <th class="px-5 py-3 text-left">Period</th>
                    <th class="px-5 py-3 text-left">Status</th>
                    <th class="px-5 py-3 text-right">Employees</th>
                    <th class="px-5 py-3 text-right">Gross Pay</th>
                    <th class="px-5 py-3 text-right">Net Pay</th>
                    <th class="px-5 py-3 text-left">Created</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php foreach ($periods as $p):
                    $statusCls = match($p['status']) {
                        'Finalised'  => 'badge badge-green',
                        'Processing' => 'badge badge-orange',
                        default      => 'badge badge-slate',
                    };
                ?>
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/30 transition-colors">
                    <td class="px-5 py-3 font-bold text-slate-900 dark:text-white">
                        <?php echo monthName((int)$p['period_month']) . ' ' . $p['period_year']; ?>
                    </td>
                    <td class="px-5 py-3"><span class="<?php echo $statusCls; ?>"><?php echo $p['status']; ?></span></td>
                    <td class="px-5 py-3 text-right"><?php echo number_format((int)$p['entry_count']); ?></td>
                    <td class="px-5 py-3 text-right text-slate-600 dark:text-slate-300">KSh <?php echo number_format((float)$p['total_gross'], 2); ?></td>
                    <td class="px-5 py-3 text-right font-semibold text-green-600 dark:text-green-400">KSh <?php echo number_format((float)$p['total_net'], 2); ?></td>
                    <td class="px-5 py-3 text-slate-500"><?php echo date('d M Y', strtotime($p['created_at'])); ?></td>
                    <td class="px-5 py-3 text-right">
                        <a href="payroll_period.php?id=<?php echo $p['id']; ?>" class="text-xs font-bold text-green-500 hover:underline">Open →</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

</div><!-- .space-y-6 -->

<!-- Create Period Modal -->
<div id="createPeriodModal" class="modal-overlay hidden">
    <div class="modal-card max-w-md w-full">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">New Payroll Period</h3>
            <button onclick="closeModal('createPeriodModal')" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
        </div>
        <form method="POST" action="actions/payroll_actions.php">
            <input type="hidden" name="action" value="create_period">
            <input type="hidden" name="_redirect" value="payroll.php">
            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Year</label>
                        <select name="period_year" class="form-input w-full">
                            <?php for ($y = $curYear + 1; $y >= $curYear - 3; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y === $curYear ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Month</label>
                        <select name="period_month" class="form-input w-full">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m === $curMonth ? 'selected' : ''; ?>><?php echo monthName($m); ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 block">Notes (optional)</label>
                    <textarea name="notes" rows="2" class="form-input w-full" placeholder="Any notes about this period..."></textarea>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="submit" class="btn-primary flex-1">Create Period</button>
                <button type="button" onclick="closeModal('createPeriodModal')" class="btn-secondary flex-1">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
