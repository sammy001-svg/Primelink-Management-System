<?php
/**
 * Collection Accounts
 * Primelink Management System
 *
 * The company's own accounts — Co-op, KCB, Equity, M-Pesa paybills, the cash
 * box — that tenant payments are banked into. Every recorded payment points at
 * one of these, which is what makes bank reconciliation possible.
 */

require_once __DIR__ . '/includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/bank_accounts.php';

ensureBankAccountSchema($pdo);

$currency  = getSetting($pdo, 'currency_symbol', 'KSh');
$pageTitle = 'Collection Accounts';
$canEdit   = canDo($pdo, 'bank_accounts', 'edit');
$canDelete = canDo($pdo, 'bank_accounts', 'delete');

$flash    = !empty($_GET['success']) ? urldecode((string)$_GET['success']) : '';
$flashErr = !empty($_GET['error'])   ? urldecode((string)$_GET['error'])   : '';

$showArchived = !empty($_GET['archived']);
$accounts     = getBankAccounts($pdo, !$showArchived);
$balances     = bankAccountBalances($pdo);
$unbanked     = unbankedPaymentCount($pdo);

// Chart-of-accounts asset codes, for optional ledger linking
$ledgerAccounts = [];
try {
    $ledgerAccounts = $pdo->query(
        "SELECT id, code, name FROM accounts WHERE type = 'Asset' AND is_active = 1 ORDER BY code"
    )->fetchAll();
} catch (PDOException $e) {}

// Totals across every account
$totalBalance  = 0.0;
$totalReceived = 0.0;
foreach ($accounts as $a) {
    $b = $balances[$a['id']] ?? null;
    if ($b) {
        $totalBalance  += $b['balance'];
        $totalReceived += $b['received'];
    }
}

// Breakdown of unbanked payments by method, so they can be assigned in bulk
$unbankedByMethod = [];
if ($unbanked > 0) {
    try {
        $unbankedByMethod = $pdo->query("
            SELECT COALESCE(payment_method, 'Unknown') AS method,
                   COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total
            FROM transactions
            WHERE status = 'Paid' AND (bank_account_id IS NULL OR bank_account_id = '')
            GROUP BY COALESCE(payment_method, 'Unknown')
            ORDER BY cnt DESC
        ")->fetchAll();
    } catch (PDOException $e) {}
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Collection Accounts</h1>
            <p class="text-slate-500 dark:text-slate-400 font-medium text-sm">
                Where tenant payments are banked. Every payment recorded names one of these accounts.
            </p>
        </div>
        <?php if ($canEdit): ?>
        <button onclick="openAccountModal()" class="btn-green px-6 py-3 text-xs">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M5 12h14"/></svg>
            Add Account
        </button>
        <?php endif; ?>
    </div>

    <?php if ($flash): ?>
    <div class="glass-card p-4 border-l-4 border-green-500 text-sm font-bold text-green-700 dark:text-green-400"><?php echo htmlspecialchars($flash); ?></div>
    <?php endif; ?>
    <?php if ($flashErr): ?>
    <div class="glass-card p-4 border-l-4 border-red-500 text-sm font-bold text-red-600"><?php echo htmlspecialchars($flashErr); ?></div>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="glass-card p-5">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Active Accounts</p>
            <p class="text-2xl font-black text-slate-900 dark:text-white mt-1"><?php echo count(getBankAccounts($pdo)); ?></p>
        </div>
        <div class="glass-card p-5">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Total Collected</p>
            <p class="text-2xl font-black text-slate-900 dark:text-white mt-1"><?php echo $currency; ?> <?php echo number_format($totalReceived); ?></p>
        </div>
        <div class="glass-card p-5">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Balance On Hand</p>
            <p class="text-2xl font-black text-accent-green mt-1"><?php echo $currency; ?> <?php echo number_format($totalBalance); ?></p>
        </div>
        <div class="glass-card p-5 <?php echo $unbanked > 0 ? 'bg-amber-50 dark:bg-amber-900/10' : ''; ?>">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Unbanked Payments</p>
            <p class="text-2xl font-black <?php echo $unbanked > 0 ? 'text-amber-600' : 'text-slate-300 dark:text-slate-700'; ?> mt-1">
                <?php echo number_format($unbanked); ?>
            </p>
        </div>
    </div>

    <?php if ($unbanked > 0 && $canEdit && $accounts): ?>
    <!-- Backfill: payments recorded before accounts existed -->
    <div class="glass-card p-6 border-l-4 border-amber-400">
        <h2 class="text-sm font-black text-slate-900 dark:text-white mb-1">Assign Unbanked Payments</h2>
        <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
            <?php echo number_format($unbanked); ?> payment<?php echo $unbanked === 1 ? '' : 's'; ?>
            <?php echo $unbanked === 1 ? 'was' : 'were'; ?> recorded before collection accounts were set up.
            Assign them to the account the money actually went into so your balances reconcile.
        </p>

        <?php if ($unbankedByMethod): ?>
        <div class="flex flex-wrap gap-2 mb-4">
            <?php foreach ($unbankedByMethod as $m): ?>
            <span class="badge badge-orange">
                <?php echo htmlspecialchars((string)$m['method']); ?>:
                <?php echo (int)$m['cnt']; ?> · <?php echo $currency; ?> <?php echo number_format((float)$m['total']); ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form action="actions/bank_account_actions.php" method="POST"
              class="flex flex-wrap items-end gap-4"
              onsubmit="return confirm('Assign the matching unbanked payments to this account?')">
            <input type="hidden" name="action" value="bank_unassigned">
            <input type="hidden" name="_redirect" value="../bank_accounts.php">

            <div class="space-y-1.5 min-w-[200px]">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Payment Method</label>
                <select name="payment_method" class="form-input">
                    <option value="">All unbanked payments</option>
                    <?php foreach ($unbankedByMethod as $m): ?>
                    <?php if ($m['method'] !== 'Unknown'): ?>
                    <option value="<?php echo htmlspecialchars((string)$m['method']); ?>">
                        <?php echo htmlspecialchars((string)$m['method']); ?> (<?php echo (int)$m['cnt']; ?>)
                    </option>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="min-w-[220px]">
                <?php echo renderBankAccountSelect($pdo, ['label' => 'Banked Into', 'id' => 'backfill_account']); ?>
            </div>
            <button type="submit" class="btn-primary text-xs px-6 py-3">Assign</button>
        </form>
    </div>
    <?php endif; ?>

    <!-- Accounts -->
    <div class="flex items-center justify-between">
        <h2 class="text-sm font-black text-slate-900 dark:text-white uppercase tracking-widest">
            <?php echo $showArchived ? 'All Accounts' : 'Active Accounts'; ?>
        </h2>
        <a href="?<?php echo $showArchived ? '' : 'archived=1'; ?>" class="text-[11px] font-black text-slate-400 hover:text-accent-green uppercase tracking-widest">
            <?php echo $showArchived ? 'Hide archived' : 'Show archived'; ?>
        </a>
    </div>

    <?php if (empty($accounts)): ?>
    <div class="glass-card py-20 text-center">
        <p class="text-slate-400 font-medium">
            No collection accounts yet.
            <?php if ($canEdit): ?>Add your bank and M-Pesa accounts so payments can be tied to them.<?php endif; ?>
        </p>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        <?php foreach ($accounts as $a):
            $bal      = $balances[$a['id']] ?? ['opening' => 0, 'received' => 0, 'balance' => 0, 'payments' => 0];
            $archived = (int)$a['is_active'] === 0;
            $typeTone = match ($a['account_type']) {
                'Cash'                          => 'bg-slate-100 dark:bg-slate-800 text-slate-500',
                'M-Pesa Paybill', 'M-Pesa Till' => 'bg-green-50 dark:bg-green-900/20 text-green-600',
                default                         => 'bg-blue-50 dark:bg-blue-900/20 text-blue-600',
            };
        ?>
        <div class="glass-card p-6 <?php echo $archived ? 'opacity-60' : ''; ?>">
            <div class="flex items-start justify-between gap-3 mb-4">
                <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h3 class="font-black text-slate-900 dark:text-white truncate"><?php echo htmlspecialchars((string)$a['name']); ?></h3>
                        <?php if ((int)$a['is_default'] === 1): ?>
                        <span class="badge badge-green text-[9px]">Default</span>
                        <?php endif; ?>
                        <?php if ($archived): ?>
                        <span class="badge text-[9px]">Archived</span>
                        <?php endif; ?>
                    </div>
                    <span class="inline-block mt-1.5 px-2 py-0.5 rounded-lg text-[9px] font-black uppercase tracking-wide <?php echo $typeTone; ?>">
                        <?php echo htmlspecialchars((string)$a['account_type']); ?>
                    </span>
                </div>
                <?php if ($canEdit): ?>
                <button onclick='openAccountModal(<?php echo json_encode($a, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                        class="text-slate-400 hover:text-blue-500 transition-colors shrink-0" title="Edit">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4Z"/></svg>
                </button>
                <?php endif; ?>
            </div>

            <div class="space-y-1 mb-4 text-xs">
                <?php if ($a['bank_name']): ?>
                <p class="text-slate-500 dark:text-slate-400 font-bold"><?php echo htmlspecialchars((string)$a['bank_name']); ?><?php if ($a['branch']): ?> · <?php echo htmlspecialchars((string)$a['branch']); ?><?php endif; ?></p>
                <?php endif; ?>
                <?php if ($a['account_no']): ?>
                <p class="text-slate-400 font-mono">A/C <?php echo htmlspecialchars((string)$a['account_no']); ?></p>
                <?php endif; ?>
                <?php if ($a['paybill_no']): ?>
                <p class="text-slate-400 font-mono">Paybill <?php echo htmlspecialchars((string)$a['paybill_no']); ?></p>
                <?php endif; ?>
                <?php if ($a['default_for_method']): ?>
                <p class="text-slate-400">Default for <strong><?php echo htmlspecialchars((string)$a['default_for_method']); ?></strong> payments</p>
                <?php endif; ?>
            </div>

            <div class="pt-4 border-t border-slate-100 dark:border-slate-800 grid grid-cols-2 gap-3">
                <div>
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Collected</p>
                    <p class="text-sm font-black text-slate-900 dark:text-white"><?php echo $currency; ?> <?php echo number_format($bal['received']); ?></p>
                    <p class="text-[10px] text-slate-400"><?php echo (int)$bal['payments']; ?> payment<?php echo (int)$bal['payments'] === 1 ? '' : 's'; ?></p>
                </div>
                <div class="text-right">
                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Balance</p>
                    <p class="text-sm font-black text-accent-green"><?php echo $currency; ?> <?php echo number_format($bal['balance']); ?></p>
                    <?php if ($bal['opening'] > 0): ?>
                    <p class="text-[10px] text-slate-400">Opening <?php echo number_format($bal['opening']); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($canEdit): ?>
            <div class="pt-4 mt-2 flex gap-2">
                <form action="actions/bank_account_actions.php" method="POST" class="flex-1">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="account_id" value="<?php echo htmlspecialchars((string)$a['id']); ?>">
                    <input type="hidden" name="_redirect" value="../bank_accounts.php<?php echo $showArchived ? '?archived=1' : ''; ?>">
                    <button type="submit" class="w-full px-3 py-2 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 rounded-lg text-[10px] font-black uppercase transition-all">
                        <?php echo $archived ? 'Restore' : 'Archive'; ?>
                    </button>
                </form>
                <?php if ($canDelete && (int)$bal['payments'] === 0): ?>
                <form action="actions/bank_account_actions.php" method="POST" class="flex-1"
                      onsubmit="return confirm('Delete <?php echo htmlspecialchars((string)$a['name'], ENT_QUOTES); ?>? It has no payments recorded against it.')">
                    <input type="hidden" name="action" value="delete_account">
                    <input type="hidden" name="account_id" value="<?php echo htmlspecialchars((string)$a['id']); ?>">
                    <input type="hidden" name="_redirect" value="../bank_accounts.php<?php echo $showArchived ? '?archived=1' : ''; ?>">
                    <button type="submit" class="w-full px-3 py-2 bg-red-50 dark:bg-red-900/20 hover:bg-red-600 hover:text-white text-red-600 rounded-lg text-[10px] font-black uppercase transition-all">
                        Delete
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<?php if ($canEdit): ?>
<!-- ── Add / Edit Account Modal ──────────────────────────────────────── -->
<div class="modal-overlay" id="accountModal" style="display:none;" onclick="if(event.target===this)closeModal('accountModal')">
    <div class="modal-card max-w-xl" onclick="event.stopPropagation()">
        <button onclick="closeModal('accountModal')" class="absolute top-5 right-5 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>

        <h2 id="am_title" class="text-xl font-black text-slate-900 dark:text-white mb-1">Add Collection Account</h2>
        <p class="text-slate-400 text-sm font-medium mb-6">Staff pick this account when recording where a payment was banked.</p>

        <form action="actions/bank_account_actions.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="save_account">
            <input type="hidden" name="account_id" id="am_id">
            <input type="hidden" name="_redirect" value="../bank_accounts.php<?php echo $showArchived ? '?archived=1' : ''; ?>">

            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Display Name *</label>
                    <input type="text" name="name" id="am_name" required class="form-input" placeholder="e.g. Equity — Rent Collection">
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Account Type</label>
                    <select name="account_type" id="am_type" class="form-input" onchange="amToggleFields(this.value)">
                        <?php foreach (BANK_ACCOUNT_TYPES as $t): ?>
                        <option value="<?php echo $t; ?>"><?php echo $t; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="am_bank_fields" class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Bank</label>
                        <input type="text" name="bank_name" id="am_bank_name" class="form-input" placeholder="e.g. Equity Bank" list="am_bank_list">
                        <datalist id="am_bank_list">
                            <?php foreach ([
                                'Co-operative Bank', 'KCB Bank', 'Equity Bank', 'ABSA Bank', 'Standard Chartered',
                                'NCBA Bank', 'Diamond Trust Bank', 'I&M Bank', 'Family Bank', 'Stanbic Bank',
                                'National Bank', 'Gulf African Bank', 'Sidian Bank', 'Prime Bank',
                            ] as $b): ?>
                            <option value="<?php echo $b; ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Branch</label>
                        <input type="text" name="branch" id="am_branch" class="form-input" placeholder="e.g. Westlands">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Account Name</label>
                        <input type="text" name="account_name" id="am_account_name" class="form-input" placeholder="Name on the account">
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Account Number</label>
                        <input type="text" name="account_no" id="am_account_no" class="form-input" placeholder="e.g. 0123456789">
                    </div>
                </div>
            </div>

            <div id="am_mpesa_fields" class="space-y-1.5 hidden">
                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Paybill / Till Number</label>
                <input type="text" name="paybill_no" id="am_paybill" class="form-input" placeholder="e.g. 522522">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Opening Balance</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-bold pointer-events-none"><?php echo htmlspecialchars($currency); ?></span>
                        <input type="number" name="opening_balance" id="am_opening" step="0.01" value="0" class="form-input pl-10">
                    </div>
                </div>
                <div class="space-y-1.5">
                    <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Default For Method</label>
                    <select name="default_for_method" id="am_method" class="form-input">
                        <option value="">No automatic default</option>
                        <?php foreach (BANK_PAYMENT_METHODS as $m): ?>
                        <option value="<?php echo $m; ?>"><?php echo $m; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php if ($ledgerAccounts): ?>
            <div class="space-y-1.5">
                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">
                    Ledger Account <span class="normal-case font-normal text-slate-400">(optional — links to the chart of accounts)</span>
                </label>
                <select name="ledger_account_id" id="am_ledger" class="form-input">
                    <option value="">Not linked</option>
                    <?php foreach ($ledgerAccounts as $la): ?>
                    <option value="<?php echo htmlspecialchars((string)$la['id']); ?>">
                        <?php echo htmlspecialchars($la['code'] . ' — ' . $la['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="space-y-1.5">
                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest px-1">Notes <span class="normal-case font-normal text-slate-400">(optional)</span></label>
                <textarea name="notes" id="am_notes" rows="2" class="form-input resize-none" placeholder="Anything staff should know about this account…"></textarea>
            </div>

            <div class="bg-slate-50 dark:bg-slate-800/60 rounded-2xl p-4 space-y-3 border border-slate-100 dark:border-slate-700/60">
                <label class="flex items-center gap-3 cursor-pointer select-none">
                    <input type="checkbox" name="is_default" id="am_default" value="1" class="w-4 h-4 rounded accent-green-500 cursor-pointer">
                    <span class="text-sm font-bold text-slate-700 dark:text-slate-200">Make this the default account on payment forms</span>
                </label>
                <label class="flex items-center gap-3 cursor-pointer select-none">
                    <input type="checkbox" name="is_active" id="am_active" value="1" checked class="w-4 h-4 rounded accent-green-500 cursor-pointer">
                    <span class="text-sm font-bold text-slate-700 dark:text-slate-200">Active — show on payment forms</span>
                </label>
            </div>

            <button type="submit" class="w-full py-3.5 bg-accent-green hover:bg-green-600 text-white font-black rounded-xl shadow-lg transition-all text-xs uppercase tracking-widest">
                Save Account
            </button>
        </form>
    </div>
</div>

<script>
function amToggleFields(type) {
    const isMpesa = type === 'M-Pesa Paybill' || type === 'M-Pesa Till';
    const isCash  = type === 'Cash';
    document.getElementById('am_mpesa_fields').classList.toggle('hidden', !isMpesa);
    document.getElementById('am_bank_fields').classList.toggle('hidden', isMpesa || isCash);
}

function openAccountModal(account) {
    const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = v ?? ''; };

    if (account) {
        document.getElementById('am_title').textContent = 'Edit Collection Account';
        setVal('am_id',           account.id);
        setVal('am_name',         account.name);
        setVal('am_type',         account.account_type);
        setVal('am_bank_name',    account.bank_name);
        setVal('am_branch',       account.branch);
        setVal('am_account_name', account.account_name);
        setVal('am_account_no',   account.account_no);
        setVal('am_paybill',      account.paybill_no);
        setVal('am_opening',      account.opening_balance ?? 0);
        setVal('am_method',       account.default_for_method);
        setVal('am_ledger',       account.ledger_account_id);
        setVal('am_notes',        account.notes);
        document.getElementById('am_default').checked = String(account.is_default) === '1';
        document.getElementById('am_active').checked  = String(account.is_active)  === '1';
        amToggleFields(account.account_type);
    } else {
        document.getElementById('am_title').textContent = 'Add Collection Account';
        ['am_id','am_name','am_bank_name','am_branch','am_account_name','am_account_no',
         'am_paybill','am_method','am_ledger','am_notes'].forEach(id => setVal(id, ''));
        setVal('am_opening', 0);
        setVal('am_type', 'Bank');
        document.getElementById('am_default').checked = false;
        document.getElementById('am_active').checked  = true;
        amToggleFields('Bank');
    }
    openModal('accountModal');
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeModal('accountModal');
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
