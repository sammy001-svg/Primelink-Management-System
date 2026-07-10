<?php
/**
 * Chart of Accounts
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/includes/settings.php';

$pageTitle = 'Chart of Accounts';
$currency  = getSetting($pdo, 'currency_symbol', 'KSh');

// ── Schema self-heal ──────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS accounts (
        id VARCHAR(36) NOT NULL PRIMARY KEY, code VARCHAR(20) NOT NULL UNIQUE,
        name VARCHAR(100) NOT NULL, type ENUM('Asset','Liability','Equity','Revenue','Expense') NOT NULL,
        description TEXT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS journal_entries (
        id VARCHAR(36) NOT NULL PRIMARY KEY, entry_date DATE NOT NULL,
        reference VARCHAR(50) NOT NULL, narration TEXT NOT NULL,
        status ENUM('Draft','Posted','Reversed') NOT NULL DEFAULT 'Draft',
        total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        reversed_entry_id VARCHAR(36) NULL, created_by VARCHAR(36) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, posted_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS journal_lines (
        id VARCHAR(36) NOT NULL PRIMARY KEY, journal_entry_id VARCHAR(36) NOT NULL,
        account_id VARCHAR(36) NOT NULL, description VARCHAR(255) NULL,
        debit DECIMAL(15,2) NOT NULL DEFAULT 0.00, credit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY (journal_entry_id), KEY (account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {}

// Seed defaults if empty
try {
    if ((int)$pdo->query("SELECT COUNT(*) FROM accounts")->fetchColumn() === 0) {
        require_once __DIR__ . '/actions/journal_actions.php';
    }
} catch (PDOException $e) {}

// ── Fetch accounts with ledger totals ─────────────────────────────────
try {
    $accounts = $pdo->query("
        SELECT a.*,
            COALESCE(SUM(jl.debit),  0) AS total_debit,
            COALESCE(SUM(jl.credit), 0) AS total_credit,
            COUNT(DISTINCT jl.journal_entry_id) AS entry_count
        FROM accounts a
        LEFT JOIN journal_lines jl ON jl.account_id = a.id
        LEFT JOIN journal_entries je ON jl.journal_entry_id = je.id AND je.status = 'Posted'
        GROUP BY a.id
        ORDER BY a.code ASC
    ")->fetchAll();
} catch (PDOException $e) { $accounts = []; }

// Group by type
$grouped = [];
foreach ($accounts as $acc) {
    $grouped[$acc['type']][] = $acc;
}
$typeOrder = ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'];

// KPIs
$totalAccounts = count($accounts);
$activeAccounts = count(array_filter($accounts, fn($a) => $a['is_active']));
$totalRevenue   = 0; $totalExpense = 0;
foreach ($accounts as $a) {
    if ($a['type'] === 'Revenue') $totalRevenue  += ($a['total_credit'] - $a['total_debit']);
    if ($a['type'] === 'Expense') $totalExpense  += ($a['total_debit']  - $a['total_credit']);
}

// Flash
$flash = $flashErr = '';
$successMap = [
    'account_created' => 'Account added to chart of accounts.',
    'account_updated' => 'Account updated successfully.',
];
if (!empty($_GET['success'])) $flash    = $successMap[$_GET['success']] ?? 'Done.';
if (!empty($_GET['error']))   $flashErr = htmlspecialchars(urldecode($_GET['error']));

// Build JS data for edit modal
$accData = [];
foreach ($accounts as $a) {
    $accData[$a['id']] = [
        'id'   => $a['id'],
        'code' => $a['code'],
        'name' => $a['name'],
        'type' => $a['type'],
        'desc' => $a['description'] ?? '',
    ];
}

// Type colors
$typeColors = [
    'Asset'     => ['bg' => 'blue',   'ring' => 'blue-500'],
    'Liability' => ['bg' => 'red',    'ring' => 'red-500'],
    'Equity'    => ['bg' => 'purple', 'ring' => 'purple-500'],
    'Revenue'   => ['bg' => 'green',  'ring' => 'green-500'],
    'Expense'   => ['bg' => 'orange', 'ring' => 'orange-500'],
];

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

<!-- ── Page header ── -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div>
        <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Chart of Accounts</h1>
        <p class="text-slate-500 dark:text-slate-400 text-sm font-medium mt-0.5">Manage the accounts used in your double-entry journal entries.</p>
    </div>
    <div class="flex gap-3">
        <a href="journals.php" class="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-xs font-black uppercase tracking-widest hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
            Journal Entries
        </a>
        <button onclick="openModal('addAccModal')"
            class="flex items-center gap-2 px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-black uppercase tracking-widest shadow-lg shadow-blue-500/20 transition-colors">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v20M2 12h20"/></svg>
            Add Account
        </button>
    </div>
</div>

<?php if ($flash): ?>
<div class="flex items-center gap-3 px-5 py-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-300 rounded-2xl text-sm font-bold">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
    <?php echo htmlspecialchars($flash); ?>
</div>
<?php endif; ?>
<?php if ($flashErr): ?>
<div class="flex items-center gap-3 px-5 py-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 rounded-2xl text-sm font-bold">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <?php echo $flashErr; ?>
</div>
<?php endif; ?>

<!-- ── KPI strip ── -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
    <?php $kpis = [
        ['label' => 'Total Accounts',  'value' => $totalAccounts,                               'sub' => $activeAccounts . ' active',        'color' => 'slate',  'icon' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><path d="M22 10H2"/>'],
        ['label' => 'Account Types',   'value' => count(array_filter($grouped)),                'sub' => 'categories in use',                'color' => 'blue',   'icon' => '<path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>'],
        ['label' => 'Total Revenue',   'value' => $currency . ' ' . number_format($totalRevenue), 'sub' => 'from posted entries',            'color' => 'green',  'icon' => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>'],
        ['label' => 'Total Expenses',  'value' => $currency . ' ' . number_format($totalExpense), 'sub' => 'from posted entries',            'color' => 'orange', 'icon' => '<polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/>'],
    ];
    foreach ($kpis as $kpi): ?>
    <div class="glass-card p-5 flex items-center gap-4">
        <div class="w-11 h-11 rounded-xl bg-<?php echo $kpi['color']; ?>-50 dark:bg-<?php echo $kpi['color']; ?>-900/20 text-<?php echo $kpi['color']; ?>-500 flex items-center justify-center shrink-0">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><?php echo $kpi['icon']; ?></svg>
        </div>
        <div class="min-w-0">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest"><?php echo $kpi['label']; ?></p>
            <p class="text-xl font-black text-slate-900 dark:text-white truncate"><?php echo $kpi['value']; ?></p>
            <p class="text-[11px] text-slate-400 font-medium"><?php echo $kpi['sub']; ?></p>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Account sections by type ── -->
<?php foreach ($typeOrder as $type):
    if (empty($grouped[$type])) continue;
    $tc = $typeColors[$type] ?? ['bg' => 'slate', 'ring' => 'slate-500'];
    $typeAccounts = $grouped[$type];
    $activeCount  = count(array_filter($typeAccounts, fn($a) => $a['is_active']));
?>
<div class="glass-card overflow-hidden">
    <!-- Section header -->
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-700/60">
        <div class="flex items-center gap-3">
            <span class="w-3 h-3 rounded-full bg-<?php echo $tc['bg']; ?>-500 inline-block"></span>
            <h2 class="text-base font-black text-slate-900 dark:text-white"><?php echo $type; ?> Accounts</h2>
            <span class="text-[10px] font-black text-slate-400 bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded-full">
                <?php echo $activeCount; ?> active
            </span>
        </div>
        <?php
        // Type balance summary
        $typeDebit  = array_sum(array_column($typeAccounts, 'total_debit'));
        $typeCredit = array_sum(array_column($typeAccounts, 'total_credit'));
        $netBalance = $type === 'Asset' || $type === 'Expense' ? ($typeDebit - $typeCredit) : ($typeCredit - $typeDebit);
        ?>
        <div class="text-right">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Net Balance</p>
            <p class="text-sm font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($netBalance); ?></p>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr>
                <th class="w-24">Code</th>
                <th>Account Name</th>
                <th>Description</th>
                <th class="text-right">Total Debit</th>
                <th class="text-right">Total Credit</th>
                <th class="text-right">Net Balance</th>
                <th>Status</th>
                <th class="text-right">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($typeAccounts as $acc):
                $dr   = (float)$acc['total_debit'];
                $cr   = (float)$acc['total_credit'];
                $net  = in_array($type, ['Asset', 'Expense']) ? ($dr - $cr) : ($cr - $dr);
            ?>
            <tr class="<?php echo !$acc['is_active'] ? 'opacity-40' : ''; ?>">
                <td>
                    <span class="font-black text-slate-900 dark:text-white text-sm tracking-tight"><?php echo htmlspecialchars($acc['code']); ?></span>
                </td>
                <td>
                    <p class="font-bold text-slate-700 dark:text-slate-200"><?php echo htmlspecialchars($acc['name']); ?></p>
                    <?php if ($acc['entry_count'] > 0): ?>
                    <p class="text-[10px] text-slate-400"><?php echo $acc['entry_count']; ?> journal entr<?php echo $acc['entry_count'] === 1 ? 'y' : 'ies'; ?></p>
                    <?php endif; ?>
                </td>
                <td class="text-sm text-slate-400 italic max-w-xs">
                    <?php echo $acc['description'] ? htmlspecialchars($acc['description']) : '—'; ?>
                </td>
                <td class="text-right font-bold text-slate-600 dark:text-slate-300 whitespace-nowrap text-sm">
                    <?php echo $dr > 0 ? $currency . ' ' . number_format($dr) : '—'; ?>
                </td>
                <td class="text-right font-bold text-slate-600 dark:text-slate-300 whitespace-nowrap text-sm">
                    <?php echo $cr > 0 ? $currency . ' ' . number_format($cr) : '—'; ?>
                </td>
                <td class="text-right font-black whitespace-nowrap <?php echo $net >= 0 ? 'text-green-600' : 'text-red-500'; ?>">
                    <?php echo $currency; ?> <?php echo number_format(abs($net)); ?>
                    <?php echo $net < 0 ? '<span class="text-[9px] font-black text-red-400 ml-0.5">(CR)</span>' : ''; ?>
                </td>
                <td>
                    <span class="badge <?php echo $acc['is_active'] ? 'badge-green' : ''; ?>">
                        <?php echo $acc['is_active'] ? 'Active' : 'Inactive'; ?>
                    </span>
                </td>
                <td class="text-right">
                    <div class="flex items-center justify-end gap-1.5">
                        <button onclick="openEditAcc('<?php echo $acc['id']; ?>')"
                            class="px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 rounded-lg text-[10px] font-black uppercase transition-all">
                            Edit
                        </button>
                        <form action="actions/journal_actions.php" method="POST" class="inline">
                            <input type="hidden" name="action" value="toggle_account">
                            <input type="hidden" name="account_id" value="<?php echo $acc['id']; ?>">
                            <input type="hidden" name="_redirect" value="../accounts.php">
                            <button type="submit"
                                class="px-3 py-1.5 rounded-lg text-[10px] font-black uppercase transition-all <?php echo $acc['is_active'] ? 'bg-red-50 dark:bg-red-900/10 text-red-500 hover:bg-red-100' : 'bg-green-50 dark:bg-green-900/20 text-green-600 hover:bg-green-100'; ?>">
                                <?php echo $acc['is_active'] ? 'Deactivate' : 'Activate'; ?>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

</div><!-- /space-y-8 -->

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- ADD ACCOUNT MODAL                                                   -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="addAccModal" style="display:none;" onclick="if(event.target===this)closeModal('addAccModal')">
    <div class="modal-card max-w-lg" onclick="event.stopPropagation()">
        <button onclick="closeModal('addAccModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-600">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>

        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-900/30 text-blue-500 flex items-center justify-center shrink-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><path d="M22 10H2"/></svg>
            </div>
            <div>
                <h2 class="text-xl font-black text-slate-900 dark:text-white">Add Account</h2>
                <p class="text-slate-400 text-sm font-medium">Add to the chart of accounts.</p>
            </div>
        </div>

        <form action="actions/journal_actions.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="create_account">
            <input type="hidden" name="_redirect" value="../accounts.php">

            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Account Code *</label>
                    <input type="text" name="code" required class="form-input" placeholder="e.g. 1100">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Account Type *</label>
                    <select name="type" required class="form-input">
                        <option value="">— Select type —</option>
                        <option value="Asset">Asset</option>
                        <option value="Liability">Liability</option>
                        <option value="Equity">Equity</option>
                        <option value="Revenue">Revenue</option>
                        <option value="Expense">Expense</option>
                    </select>
                </div>
            </div>

            <div class="space-y-1.5">
                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Account Name *</label>
                <input type="text" name="name" required class="form-input" placeholder="e.g. Prepaid Insurance">
            </div>

            <div class="space-y-1.5">
                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Description <span class="normal-case font-normal text-slate-400">(optional)</span></label>
                <textarea name="description" rows="2" class="form-input resize-none" placeholder="Brief description of this account…"></textarea>
            </div>

            <button type="submit" class="w-full py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-black rounded-xl shadow-lg shadow-blue-500/20 transition-all flex items-center justify-center gap-2 mt-2">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Add Account
            </button>
        </form>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- EDIT ACCOUNT MODAL                                                  -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editAccModal" style="display:none;" onclick="if(event.target===this)closeModal('editAccModal')">
    <div class="modal-card max-w-lg" onclick="event.stopPropagation()">
        <button onclick="closeModal('editAccModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-600">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>

        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-500 flex items-center justify-center shrink-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4Z"/></svg>
            </div>
            <div>
                <h2 class="text-xl font-black text-slate-900 dark:text-white">Edit Account</h2>
                <p id="ea_code_label" class="text-slate-400 text-sm font-medium">—</p>
            </div>
        </div>

        <form action="actions/journal_actions.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="update_account">
            <input type="hidden" name="account_id" id="ea_account_id">
            <input type="hidden" name="_redirect" value="../accounts.php">

            <div class="space-y-1.5">
                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Account Name *</label>
                <input type="text" name="name" id="ea_name" required class="form-input">
            </div>
            <div class="space-y-1.5">
                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Account Type *</label>
                <select name="type" id="ea_type" required class="form-input">
                    <option value="Asset">Asset</option>
                    <option value="Liability">Liability</option>
                    <option value="Equity">Equity</option>
                    <option value="Revenue">Revenue</option>
                    <option value="Expense">Expense</option>
                </select>
            </div>
            <div class="space-y-1.5">
                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Description</label>
                <textarea name="description" id="ea_desc" rows="2" class="form-input resize-none"></textarea>
            </div>
            <button type="submit" class="w-full py-3.5 bg-slate-900 dark:bg-white hover:opacity-90 text-white dark:text-slate-900 font-black rounded-xl transition-all flex items-center justify-center gap-2 mt-2">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Save Changes
            </button>
        </form>
    </div>
</div>

<script>
const accData = <?php echo json_encode($accData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

function openEditAcc(id) {
    const a = accData[id];
    if (!a) return;
    document.getElementById('ea_account_id').value    = a.id;
    document.getElementById('ea_code_label').textContent = a.code;
    document.getElementById('ea_name').value           = a.name;
    document.getElementById('ea_type').value           = a.type;
    document.getElementById('ea_desc').value           = a.desc || '';
    openModal('editAccModal');
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeModal('addAccModal');
        closeModal('editAccModal');
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
