<?php
/**
 * Journal Entries
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/includes/settings.php';

$pageTitle = 'Journal Entries';
$currency  = getSetting($pdo, 'currency_symbol', 'KSh');

// ── Schema self-heal & default accounts ──────────────────────────────
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

// Seed default accounts if needed
try {
    if ((int)$pdo->query("SELECT COUNT(*) FROM accounts")->fetchColumn() === 0) {
        require_once __DIR__ . '/actions/journal_actions.php'; // triggers seed via self_heal_journals()
    }
} catch (PDOException $e) {}

// ── Chart of accounts for select dropdowns ───────────────────────────
$allAccounts = [];
try {
    $allAccounts = $pdo->query("SELECT id, code, name, type FROM accounts WHERE is_active = 1 ORDER BY code ASC")->fetchAll();
} catch (PDOException $e) {}

// ── Journal entries ───────────────────────────────────────────────────
$selStatus = $_GET['status'] ?? '';
$selYear   = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$search    = trim($_GET['q'] ?? '');

$validStatuses = ['Draft', 'Posted', 'Reversed'];
if (!in_array($selStatus, $validStatuses, true)) $selStatus = '';

$yearRange = range((int)date('Y'), max((int)date('Y') - 5, 2020), -1);

$params  = [];
$where   = ["YEAR(je.entry_date) = ?"];
$params[] = $selYear;
if ($selStatus) { $where[] = "je.status = ?"; $params[] = $selStatus; }
if ($search)    { $where[] = "(je.reference LIKE ? OR je.narration LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

$whereClause = 'WHERE ' . implode(' AND ', $where);

try {
    $entries = $pdo->prepare("
        SELECT je.*, u.full_name AS created_by_name
        FROM journal_entries je
        LEFT JOIN users u ON je.created_by = u.id
        $whereClause
        ORDER BY je.entry_date DESC, je.created_at DESC
        LIMIT 500
    ");
    $entries->execute($params);
    $entries = $entries->fetchAll();
} catch (PDOException $e) { $entries = []; }

// ── KPIs ─────────────────────────────────────────────────────────────
$kpiTotal    = count($entries);
$kpiDraft    = count(array_filter($entries, fn($e) => $e['status'] === 'Draft'));
$kpiPosted   = count(array_filter($entries, fn($e) => $e['status'] === 'Posted'));
$kpiPostedAmt = array_sum(array_map(fn($e) => $e['status'] === 'Posted' ? (float)$e['total_amount'] : 0, $entries));

// ── Tab counts (for current year, no other filters) ───────────────────
try {
    $tabCounts = [];
    $tcStmt = $pdo->prepare("SELECT status, COUNT(*) as n FROM journal_entries WHERE YEAR(entry_date) = ? GROUP BY status");
    $tcStmt->execute([$selYear]);
    foreach ($tcStmt->fetchAll() as $r) $tabCounts[$r['status']] = (int)$r['n'];
    $tabCounts[''] = array_sum($tabCounts);
} catch (PDOException $e) { $tabCounts = ['' => 0]; }

// ── Flash messages ────────────────────────────────────────────────────
$flash = $flashErr = '';
$successMap = [
    'entry_created'   => 'Journal entry created.',
    'entry_posted'    => 'Entry posted to the ledger.',
    'entry_reversed'  => 'Reversal entry created and original marked Reversed.',
    'entry_deleted'   => 'Draft entry deleted.',
    'account_created' => 'Account added to chart of accounts.',
    'account_updated' => 'Account updated.',
];
if (!empty($_GET['success'])) $flash    = $successMap[$_GET['success']] ?? 'Done.';
if (!empty($_GET['error']))   $flashErr = htmlspecialchars(urldecode($_GET['error']));

// ── Pre-fetch all lines for entry detail modals ───────────────────────
$entryLines = [];
if (!empty($entries)) {
    $ids = array_column($entries, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        $ls = $pdo->prepare("
            SELECT jl.*, a.code AS account_code, a.name AS account_name, a.type AS account_type
            FROM journal_lines jl
            JOIN accounts a ON jl.account_id = a.id
            WHERE jl.journal_entry_id IN ($placeholders)
            ORDER BY jl.debit DESC, jl.credit DESC
        ");
        $ls->execute($ids);
        foreach ($ls->fetchAll() as $line) {
            $entryLines[$line['journal_entry_id']][] = $line;
        }
    } catch (PDOException $e) {}
}

// ── JS data object ────────────────────────────────────────────────────
$jeData = [];
foreach ($entries as $e) {
    $lines = $entryLines[$e['id']] ?? [];
    $jeData[$e['id']] = [
        'id'        => $e['id'],
        'date'      => $e['entry_date'],
        'ref'       => $e['reference'],
        'narration' => $e['narration'],
        'status'    => $e['status'],
        'amount'    => (float)$e['total_amount'],
        'by'        => $e['created_by_name'] ?? '—',
        'lines'     => array_map(fn($l) => [
            'account_code' => $l['account_code'],
            'account_name' => $l['account_name'],
            'desc'         => $l['description'] ?? '',
            'debit'        => (float)$l['debit'],
            'credit'       => (float)$l['credit'],
        ], $lines),
    ];
}

// Status badge helper
function jeBadge(string $status): string {
    return match($status) {
        'Posted'   => 'badge badge-green',
        'Draft'    => 'badge badge-blue',
        'Reversed' => 'badge',
        default    => 'badge',
    };
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

<!-- ── Page header ── -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-end gap-4">
    <div>
        <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Journal Entries</h1>
        <p class="text-slate-500 dark:text-slate-400 text-sm font-medium mt-0.5">Double-entry accounting journal — balanced debit/credit entries.</p>
    </div>
    <div class="flex gap-3">
        <a href="accounts.php" class="flex items-center gap-2 px-4 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-xs font-black uppercase tracking-widest hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><path d="M22 10H2"/></svg>
            Chart of Accounts
        </a>
        <button onclick="openModal('createJeModal')"
            class="flex items-center gap-2 px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-xs font-black uppercase tracking-widest shadow-lg shadow-blue-500/20 transition-colors">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v20M2 12h20"/></svg>
            New Entry
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
        ['label' => 'Total Entries',   'value' => $kpiTotal,   'sub' => 'this year', 'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>', 'color' => 'slate'],
        ['label' => 'Draft',           'value' => $kpiDraft,   'sub' => 'awaiting post', 'icon' => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4Z"/>', 'color' => 'blue'],
        ['label' => 'Posted',          'value' => $kpiPosted,  'sub' => 'in ledger', 'icon' => '<polyline points="20 6 9 17 4 12"/>', 'color' => 'green'],
        ['label' => 'Posted Volume',   'value' => $currency . ' ' . number_format($kpiPostedAmt), 'sub' => 'total debits posted', 'icon' => '<line x1="12" y1="2" x2="12" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>', 'color' => 'emerald'],
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

<!-- ── Year filter ── -->
<div class="flex items-center gap-3 flex-wrap">
    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Year:</span>
    <?php foreach ($yearRange as $yr): ?>
    <a href="?year=<?php echo $yr; ?>&status=<?php echo urlencode($selStatus); ?>&q=<?php echo urlencode($search); ?>"
       class="px-3 py-1.5 rounded-lg text-xs font-black transition-colors <?php echo $selYear === $yr ? 'bg-slate-900 dark:bg-white text-white dark:text-slate-900' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 hover:text-slate-900 dark:hover:text-white'; ?>">
        <?php echo $yr; ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- ── Status tabs + search ── -->
<div class="flex flex-col sm:flex-row gap-4 items-start sm:items-center justify-between">
    <div class="flex gap-1.5 bg-slate-100 dark:bg-slate-800/60 p-1 rounded-xl flex-wrap">
        <?php foreach (['' => 'All', 'Draft' => 'Draft', 'Posted' => 'Posted', 'Reversed' => 'Reversed'] as $s => $label): ?>
        <a href="?year=<?php echo $selYear; ?>&status=<?php echo urlencode($s); ?>&q=<?php echo urlencode($search); ?>"
           class="px-3.5 py-2 rounded-lg text-xs font-black transition-all <?php echo $selStatus === $s ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-sm' : 'text-slate-500 hover:text-slate-800 dark:hover:text-slate-200'; ?>">
            <?php echo $label; ?>
            <?php if (isset($tabCounts[$s])): ?>
            <span class="ml-1 text-[9px] opacity-70">(<?php echo $tabCounts[$s]; ?>)</span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <form method="GET" class="relative">
        <input type="hidden" name="year" value="<?php echo $selYear; ?>">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($selStatus); ?>">
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>"
               placeholder="Search reference or narration…"
               class="pl-9 pr-4 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 border-0 text-sm font-medium text-slate-700 dark:text-slate-200 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-blue-500 w-72">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
    </form>
</div>

<!-- ── Journal entries table ── -->
<div class="glass-card overflow-hidden">
    <?php if (empty($entries)): ?>
    <div class="text-center py-16">
        <div class="w-16 h-16 bg-slate-50 dark:bg-slate-900 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-slate-300"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </div>
        <p class="text-slate-400 font-bold text-sm">No journal entries found.</p>
        <p class="text-slate-300 dark:text-slate-600 text-xs mt-1">Create your first entry using the New Entry button.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="data-table">
            <thead><tr>
                <th>Date</th>
                <th>Reference</th>
                <th>Narration</th>
                <th class="text-right">Amount (Dr)</th>
                <th>Status</th>
                <th>Created By</th>
                <th class="text-right">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($entries as $je): ?>
            <tr>
                <td class="whitespace-nowrap text-slate-500 font-medium">
                    <?php echo date('M j, Y', strtotime($je['entry_date'])); ?>
                </td>
                <td>
                    <span class="font-black text-slate-900 dark:text-white text-sm tracking-tight"><?php echo htmlspecialchars($je['reference']); ?></span>
                </td>
                <td class="max-w-sm">
                    <p class="text-sm font-medium text-slate-700 dark:text-slate-300 truncate"><?php echo htmlspecialchars($je['narration']); ?></p>
                    <?php if (!empty($je['reversed_entry_id'])): ?>
                    <p class="text-[10px] text-orange-500 font-bold mt-0.5">Reversal entry</p>
                    <?php endif; ?>
                </td>
                <td class="text-right font-black text-slate-900 dark:text-white whitespace-nowrap">
                    <?php echo $currency; ?> <?php echo number_format($je['total_amount']); ?>
                </td>
                <td><span class="<?php echo jeBadge($je['status']); ?>"><?php echo $je['status']; ?></span></td>
                <td class="text-sm text-slate-400"><?php echo htmlspecialchars($je['created_by_name'] ?? '—'); ?></td>
                <td class="text-right">
                    <div class="flex items-center justify-end gap-1.5">
                        <button onclick="openViewModal('<?php echo $je['id']; ?>')"
                            class="px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 rounded-lg text-[10px] font-black uppercase transition-all">
                            View
                        </button>
                        <?php if ($je['status'] === 'Draft'): ?>
                        <button onclick="openPostModal('<?php echo $je['id']; ?>','<?php echo htmlspecialchars($je['reference']); ?>')"
                            class="px-3 py-1.5 bg-green-50 dark:bg-green-900/20 hover:bg-green-100 text-green-600 rounded-lg text-[10px] font-black uppercase transition-all whitespace-nowrap">
                            Post
                        </button>
                        <form action="actions/journal_actions.php" method="POST" class="inline"
                              onsubmit="return confirm('Delete this draft entry? This cannot be undone.')">
                            <input type="hidden" name="action" value="delete_entry">
                            <input type="hidden" name="entry_id" value="<?php echo $je['id']; ?>">
                            <input type="hidden" name="_redirect" value="../journals.php?year=<?php echo $selYear; ?>">
                            <button class="px-3 py-1.5 bg-red-50 dark:bg-red-900/10 hover:bg-red-100 text-red-500 rounded-lg text-[10px] font-black uppercase transition-all">
                                Delete
                            </button>
                        </form>
                        <?php elseif ($je['status'] === 'Posted'): ?>
                        <button onclick="openReverseModal('<?php echo $je['id']; ?>','<?php echo htmlspecialchars($je['reference']); ?>')"
                            class="px-3 py-1.5 bg-orange-50 dark:bg-orange-900/20 hover:bg-orange-100 text-orange-500 rounded-lg text-[10px] font-black uppercase transition-all whitespace-nowrap">
                            Reverse
                        </button>
                        <?php endif; ?>
                        <a href="journal_entry.php?id=<?php echo urlencode($je['id']); ?>" target="_blank"
                            class="px-3 py-1.5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-500 rounded-lg text-[10px] font-black uppercase transition-all">
                            Print
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

</div><!-- /space-y-8 -->

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- CREATE JOURNAL ENTRY MODAL                                          -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="createJeModal" style="display:none;" onclick="if(event.target===this)closeModal('createJeModal')">
    <div class="modal-card max-w-3xl" onclick="event.stopPropagation()">
        <button onclick="closeModal('createJeModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>

        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-900/30 text-blue-500 flex items-center justify-center shrink-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="M9 15l3 3 3-3"/></svg>
            </div>
            <div>
                <h2 class="text-xl font-black text-slate-900 dark:text-white">New Journal Entry</h2>
                <p class="text-slate-400 text-sm font-medium">Total debits must equal total credits.</p>
            </div>
        </div>

        <form id="jeForm" action="actions/journal_actions.php" method="POST" onsubmit="return validateJeBalance()">
            <input type="hidden" name="action" value="create_entry">
            <input type="hidden" name="_redirect" value="../journals.php?year=<?php echo $selYear; ?>">

            <!-- Header fields -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Entry Date *</label>
                    <input type="date" name="entry_date" required class="form-input" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Reference <span class="normal-case font-normal text-slate-400">(auto if blank)</span></label>
                    <input type="text" name="reference" class="form-input" placeholder="e.g. JE-2026-0001">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Narration *</label>
                    <input type="text" name="narration" required class="form-input" placeholder="Brief description of this entry…">
                </div>
            </div>

            <!-- Lines table -->
            <div class="mb-4">
                <div class="grid grid-cols-[1fr_1fr_110px_110px_36px] gap-2 mb-2 px-1">
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Account</span>
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Description</span>
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Debit</span>
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Credit</span>
                    <span></span>
                </div>
                <div id="je_lines" class="space-y-2">
                    <!-- Lines rendered by JS -->
                </div>
                <button type="button" onclick="addJeLine()"
                    class="mt-3 flex items-center gap-2 px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-xs font-black uppercase tracking-widest hover:bg-slate-200 transition-colors">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v20M2 12h20"/></svg>
                    Add Line
                </button>
            </div>

            <!-- Totals strip -->
            <div class="flex items-center justify-between bg-slate-50 dark:bg-slate-800/60 rounded-2xl px-5 py-4 mb-5 border border-slate-100 dark:border-slate-700/60">
                <div class="text-sm font-black text-slate-500">Balance check:</div>
                <div class="flex gap-8 items-center">
                    <div class="text-right">
                        <p class="text-[10px] font-black text-slate-400 uppercase">Total Debit</p>
                        <p id="je_total_dr" class="text-base font-black text-slate-900 dark:text-white"><?php echo $currency; ?> 0</p>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] font-black text-slate-400 uppercase">Total Credit</p>
                        <p id="je_total_cr" class="text-base font-black text-slate-900 dark:text-white"><?php echo $currency; ?> 0</p>
                    </div>
                    <div id="je_balance_status" class="px-3 py-1.5 rounded-lg text-xs font-black uppercase bg-slate-200 dark:bg-slate-700 text-slate-400">
                        Not balanced
                    </div>
                </div>
            </div>

            <!-- Post now checkbox -->
            <div class="flex items-center gap-3 mb-5">
                <input type="checkbox" name="post_now" id="je_post_now" value="1" class="w-4 h-4 accent-blue-500 cursor-pointer rounded">
                <label for="je_post_now" class="text-sm font-bold text-slate-700 dark:text-slate-200 cursor-pointer">Post immediately (cannot be edited after posting)</label>
            </div>

            <button type="submit" class="w-full py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-black rounded-xl shadow-lg shadow-blue-500/20 transition-all flex items-center justify-center gap-2">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                Save Journal Entry
            </button>
        </form>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- VIEW ENTRY MODAL                                                    -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="viewJeModal" style="display:none;" onclick="if(event.target===this)closeModal('viewJeModal')">
    <div class="modal-card max-w-2xl" onclick="event.stopPropagation()">
        <button onclick="closeModal('viewJeModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-600">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>
        <div class="flex items-center gap-3 mb-5">
            <div class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-500 flex items-center justify-center shrink-0">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div>
                <h2 id="vje_ref" class="text-xl font-black text-slate-900 dark:text-white">—</h2>
                <p id="vje_narration" class="text-slate-400 text-sm font-medium">—</p>
            </div>
        </div>
        <div class="flex gap-6 text-sm mb-6">
            <div><span class="text-[10px] font-black text-slate-400 uppercase tracking-widest block">Date</span><span id="vje_date" class="font-bold text-slate-700 dark:text-slate-200">—</span></div>
            <div><span class="text-[10px] font-black text-slate-400 uppercase tracking-widest block">Status</span><span id="vje_status" class="font-bold">—</span></div>
            <div><span class="text-[10px] font-black text-slate-400 uppercase tracking-widest block">Created By</span><span id="vje_by" class="font-bold text-slate-700 dark:text-slate-200">—</span></div>
        </div>
        <!-- Lines -->
        <div class="rounded-2xl overflow-hidden border border-slate-200 dark:border-slate-700 mb-4">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Account</th>
                        <th class="px-4 py-3 text-left text-[10px] font-black text-slate-400 uppercase tracking-widest">Description</th>
                        <th class="px-4 py-3 text-right text-[10px] font-black text-slate-400 uppercase tracking-widest">Debit</th>
                        <th class="px-4 py-3 text-right text-[10px] font-black text-slate-400 uppercase tracking-widest">Credit</th>
                    </tr>
                </thead>
                <tbody id="vje_lines"></tbody>
                <tfoot>
                    <tr class="bg-slate-50 dark:bg-slate-800 border-t border-slate-200 dark:border-slate-700">
                        <td colspan="2" class="px-4 py-3 text-xs font-black text-slate-500 uppercase tracking-widest">Totals</td>
                        <td id="vje_total_dr" class="px-4 py-3 text-right font-black text-slate-900 dark:text-white"></td>
                        <td id="vje_total_cr" class="px-4 py-3 text-right font-black text-slate-900 dark:text-white"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <a id="vje_print_link" href="#" target="_blank"
           class="flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 text-xs font-black uppercase tracking-widest hover:bg-slate-200 transition-colors">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z"/></svg>
            Open Print View
        </a>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- POST CONFIRM MODAL                                                  -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="postJeModal" style="display:none;" onclick="if(event.target===this)closeModal('postJeModal')">
    <div class="modal-card max-w-md" onclick="event.stopPropagation()">
        <div class="text-center py-4">
            <div class="w-14 h-14 rounded-full bg-green-50 dark:bg-green-900/20 text-green-500 flex items-center justify-center mx-auto mb-4">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <h2 class="text-xl font-black text-slate-900 dark:text-white mb-2">Post Entry to Ledger?</h2>
            <p class="text-slate-400 text-sm font-medium mb-1">Reference: <span id="post_ref" class="font-black text-slate-700 dark:text-slate-200"></span></p>
            <p class="text-slate-400 text-xs mb-6">Once posted, this entry cannot be edited — only reversed.</p>
            <form action="actions/journal_actions.php" method="POST">
                <input type="hidden" name="action" value="post_entry">
                <input type="hidden" name="entry_id" id="post_entry_id">
                <input type="hidden" name="_redirect" value="../journals.php?year=<?php echo $selYear; ?>">
                <div class="flex gap-3">
                    <button type="button" onclick="closeModal('postJeModal')" class="flex-1 py-3 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-black text-sm">Cancel</button>
                    <button type="submit" class="flex-1 py-3 rounded-xl bg-green-500 hover:bg-green-600 text-white font-black text-sm shadow-lg shadow-green-500/20">Post Entry</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════ -->
<!-- REVERSE CONFIRM MODAL                                               -->
<!-- ════════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="reverseJeModal" style="display:none;" onclick="if(event.target===this)closeModal('reverseJeModal')">
    <div class="modal-card max-w-md" onclick="event.stopPropagation()">
        <div class="text-center py-4">
            <div class="w-14 h-14 rounded-full bg-orange-50 dark:bg-orange-900/20 text-orange-500 flex items-center justify-center mx-auto mb-4">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.35"/></svg>
            </div>
            <h2 class="text-xl font-black text-slate-900 dark:text-white mb-2">Reverse This Entry?</h2>
            <p class="text-slate-400 text-sm font-medium mb-4">Reference: <span id="rev_ref" class="font-black text-slate-700 dark:text-slate-200"></span></p>
            <p class="text-slate-400 text-xs mb-6">A new reversal entry will be created with swapped debits/credits, and the original will be marked Reversed.</p>
            <form action="actions/journal_actions.php" method="POST" class="space-y-3">
                <input type="hidden" name="action" value="reverse_entry">
                <input type="hidden" name="entry_id" id="rev_entry_id">
                <input type="hidden" name="_redirect" value="../journals.php?year=<?php echo $selYear; ?>">
                <div class="space-y-1.5 text-left">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Reason <span class="normal-case font-normal">(optional)</span></label>
                    <input type="text" name="reason" class="form-input" placeholder="e.g. Entry posted in error…">
                </div>
                <div class="flex gap-3 mt-2">
                    <button type="button" onclick="closeModal('reverseJeModal')" class="flex-1 py-3 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 font-black text-sm">Cancel</button>
                    <button type="submit" class="flex-1 py-3 rounded-xl bg-orange-500 hover:bg-orange-600 text-white font-black text-sm shadow-lg shadow-orange-500/20">Create Reversal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ── Data & config ─────────────────────────────────────────────────────
const jeData   = <?php echo json_encode($jeData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const jeCur    = <?php echo json_encode($currency); ?>;
const jeAccounts = <?php echo json_encode(array_values($allAccounts), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

// ── Account select HTML (shared across all line rows) ─────────────────
function buildAccountOptions(selectedId = '') {
    let html = '<option value="">— Select account —</option>';
    const groups = {};
    jeAccounts.forEach(a => {
        if (!groups[a.type]) groups[a.type] = [];
        groups[a.type].push(a);
    });
    for (const type of ['Asset','Liability','Equity','Revenue','Expense']) {
        if (!groups[type]) continue;
        html += `<optgroup label="${type}">`;
        groups[type].forEach(a => {
            const sel = a.id === selectedId ? ' selected' : '';
            html += `<option value="${a.id}"${sel}>${a.code} — ${a.name}</option>`;
        });
        html += '</optgroup>';
    }
    return html;
}

// ── Create entry modal: line management ───────────────────────────────
let jeLineCount = 0;

function addJeLine(debitVal = '', creditVal = '', descVal = '', accountId = '') {
    const n   = jeLineCount++;
    const div = document.createElement('div');
    div.className = 'grid grid-cols-[1fr_1fr_110px_110px_36px] gap-2 items-center je-line';
    div.innerHTML = `
        <select name="account_id[]" onchange="jeCalcTotals()" required
            class="form-input text-sm py-2.5">${buildAccountOptions(accountId)}</select>
        <input type="text" name="line_desc[]" value="${descVal}"
            class="form-input text-sm py-2.5" placeholder="Line description…">
        <input type="number" name="debit[]" value="${debitVal}" min="0" step="0.01"
            class="form-input text-sm py-2.5 text-right" placeholder="0.00" oninput="jeCalcTotals()">
        <input type="number" name="credit[]" value="${creditVal}" min="0" step="0.01"
            class="form-input text-sm py-2.5 text-right" placeholder="0.00" oninput="jeCalcTotals()">
        <button type="button" onclick="removeJeLine(this)"
            class="w-9 h-9 flex items-center justify-center rounded-xl bg-red-50 dark:bg-red-900/10 text-red-400 hover:bg-red-100 hover:text-red-600 transition-colors shrink-0">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>`;
    document.getElementById('je_lines').appendChild(div);
    jeCalcTotals();
}

function removeJeLine(btn) {
    const lines = document.querySelectorAll('.je-line');
    if (lines.length <= 2) return;
    btn.closest('.je-line').remove();
    jeCalcTotals();
}

function jeCalcTotals() {
    let dr = 0, cr = 0;
    document.querySelectorAll('.je-line').forEach(row => {
        dr += parseFloat(row.querySelector('[name="debit[]"]').value)  || 0;
        cr += parseFloat(row.querySelector('[name="credit[]"]').value) || 0;
    });
    document.getElementById('je_total_dr').textContent = jeCur + ' ' + dr.toLocaleString(undefined, {minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('je_total_cr').textContent = jeCur + ' ' + cr.toLocaleString(undefined, {minimumFractionDigits:2,maximumFractionDigits:2});
    const balanced = dr > 0 && Math.abs(dr - cr) < 0.005;
    const statusEl = document.getElementById('je_balance_status');
    statusEl.textContent  = balanced ? 'Balanced ✓' : 'Not balanced';
    statusEl.className    = 'px-3 py-1.5 rounded-lg text-xs font-black uppercase ' +
        (balanced ? 'bg-green-100 dark:bg-green-900/30 text-green-600' : 'bg-red-100 dark:bg-red-900/20 text-red-500');
}

function validateJeBalance() {
    let dr = 0, cr = 0;
    document.querySelectorAll('.je-line').forEach(row => {
        dr += parseFloat(row.querySelector('[name="debit[]"]').value)  || 0;
        cr += parseFloat(row.querySelector('[name="credit[]"]').value) || 0;
    });
    if (Math.abs(dr - cr) >= 0.005) {
        alert('Entry is not balanced.\nTotal Debits: ' + jeCur + ' ' + dr.toFixed(2) + '\nTotal Credits: ' + jeCur + ' ' + cr.toFixed(2) + '\n\nPlease ensure debits equal credits before saving.');
        return false;
    }
    const lines = document.querySelectorAll('.je-line');
    let hasData = 0;
    lines.forEach(row => {
        const d = parseFloat(row.querySelector('[name="debit[]"]').value)  || 0;
        const c = parseFloat(row.querySelector('[name="credit[]"]').value) || 0;
        if (d > 0 || c > 0) hasData++;
    });
    if (hasData < 2) {
        alert('At least 2 non-zero lines are required.');
        return false;
    }
    return true;
}

// Initialise with 2 empty lines when modal opens
document.getElementById('createJeModal').addEventListener('show', function() {
    document.getElementById('je_lines').innerHTML = '';
    jeLineCount = 0;
    addJeLine(); addJeLine();
});

// Patch openModal to trigger 'show' event so we can initialise lines
const _origOpenModal = openModal;
openModal = function(id) {
    _origOpenModal(id);
    document.getElementById(id)?.dispatchEvent(new Event('show'));
};

// ── View entry modal ──────────────────────────────────────────────────
function openViewModal(id) {
    const e = jeData[id];
    if (!e) return;
    document.getElementById('vje_ref').textContent      = e.ref;
    document.getElementById('vje_narration').textContent= e.narration;
    document.getElementById('vje_date').textContent     = new Date(e.date + 'T00:00:00').toLocaleDateString('en-KE', {year:'numeric',month:'short',day:'numeric'});
    document.getElementById('vje_by').textContent       = e.by;
    document.getElementById('vje_status').innerHTML     = e.status === 'Posted'
        ? '<span class="badge badge-green">Posted</span>'
        : e.status === 'Draft' ? '<span class="badge badge-blue">Draft</span>'
        : '<span class="badge">Reversed</span>';
    document.getElementById('vje_print_link').href      = 'journal_entry.php?id=' + encodeURIComponent(id);

    let tbody = '', totalDr = 0, totalCr = 0;
    e.lines.forEach(l => {
        totalDr += l.debit; totalCr += l.credit;
        tbody += `<tr class="border-b border-slate-100 dark:border-slate-800">
            <td class="px-4 py-3">
                <span class="font-black text-slate-900 dark:text-white text-xs">${l.account_code}</span>
                <span class="text-slate-500 text-xs ml-1">${l.account_name}</span>
            </td>
            <td class="px-4 py-3 text-xs text-slate-400">${l.desc || '—'}</td>
            <td class="px-4 py-3 text-right font-bold text-sm ${l.debit > 0 ? 'text-slate-900 dark:text-white' : 'text-slate-300 dark:text-slate-700'}">${l.debit > 0 ? jeCur + ' ' + l.debit.toLocaleString() : '—'}</td>
            <td class="px-4 py-3 text-right font-bold text-sm ${l.credit > 0 ? 'text-slate-900 dark:text-white' : 'text-slate-300 dark:text-slate-700'}">${l.credit > 0 ? jeCur + ' ' + l.credit.toLocaleString() : '—'}</td>
        </tr>`;
    });
    document.getElementById('vje_lines').innerHTML     = tbody;
    document.getElementById('vje_total_dr').textContent = jeCur + ' ' + totalDr.toLocaleString();
    document.getElementById('vje_total_cr').textContent = jeCur + ' ' + totalCr.toLocaleString();
    openModal('viewJeModal');
}

// ── Post/Reverse modals ───────────────────────────────────────────────
function openPostModal(id, ref) {
    document.getElementById('post_entry_id').value = id;
    document.getElementById('post_ref').textContent = ref;
    openModal('postJeModal');
}

function openReverseModal(id, ref) {
    document.getElementById('rev_entry_id').value  = id;
    document.getElementById('rev_ref').textContent = ref;
    openModal('reverseJeModal');
}

// ── Escape ────────────────────────────────────────────────────────────
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        ['createJeModal','viewJeModal','postJeModal','reverseJeModal'].forEach(id => closeModal(id));
    }
});

// Init lines on page load (in case modal is pre-opened)
(function() {
    addJeLine(); addJeLine();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
