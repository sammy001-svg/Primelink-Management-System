<?php
/**
 * Bank / Deposit Account Actions
 * Primelink Management System
 *
 * Manages the company's own collection accounts (Co-op, KCB, Equity, M-Pesa,
 * cash box) that tenant payments are banked into.
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/bank_accounts.php';

ensureBankAccountSchema($pdo);

$redirect = trim($_POST['_redirect'] ?? '../bank_accounts.php');
$action   = trim($_POST['action']    ?? '');

/** Redirect back with a message. */
function bankBack(string $redirect, string $key, string $value): void {
    $sep = str_contains($redirect, '?') ? '&' : '?';
    header('Location: ' . $redirect . $sep . $key . '=' . urlencode($value));
    exit;
}

$fail = fn(string $msg) => bankBack($redirect, 'error', $msg);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../bank_accounts.php');
    exit;
}

/* ═══════════════════════════════════════════════════════════════════════
   CREATE / UPDATE
   ═══════════════════════════════════════════════════════════════════════ */
if ($action === 'save_account') {
    requirePermission($pdo, 'bank_accounts', 'edit');

    $id          = trim($_POST['account_id']         ?? '');
    $name        = trim($_POST['name']               ?? '');
    $bankName    = trim($_POST['bank_name']          ?? '');
    $accountName = trim($_POST['account_name']       ?? '');
    $accountNo   = trim($_POST['account_no']         ?? '');
    $branch      = trim($_POST['branch']             ?? '');
    $type        = trim($_POST['account_type']       ?? 'Bank');
    $paybill     = trim($_POST['paybill_no']         ?? '');
    $opening     = round((float)($_POST['opening_balance'] ?? 0), 2);
    $ledgerId    = trim($_POST['ledger_account_id']  ?? '');
    $defMethod   = trim($_POST['default_for_method'] ?? '');
    $notes       = trim($_POST['notes']              ?? '');
    $isDefault   = !empty($_POST['is_default']);
    $isActive    = !empty($_POST['is_active']);
    $sortOrder   = (int)($_POST['sort_order'] ?? 0);

    if ($name === '') $fail('Give the account a name — that is what staff pick from when recording a payment.');

    if (!in_array($type, BANK_ACCOUNT_TYPES, true)) $type = 'Bank';
    if ($defMethod !== '' && !in_array($defMethod, BANK_PAYMENT_METHODS, true)) $defMethod = '';

    // A bank account without a number cannot be reconciled against a statement
    if ($type === 'Bank' && $accountNo === '') {
        $fail('A bank account needs its account number so payments can be reconciled against the statement.');
    }
    if (in_array($type, ['M-Pesa Paybill', 'M-Pesa Till'], true) && $paybill === '') {
        $fail('Enter the paybill or till number for this M-Pesa account.');
    }

    try {
        $pdo->beginTransaction();

        // Only one account can be the overall default, and only one per method
        if ($isDefault) {
            $pdo->prepare("UPDATE bank_accounts SET is_default = 0")->execute();
        }
        if ($defMethod !== '') {
            $clear = $pdo->prepare("UPDATE bank_accounts SET default_for_method = NULL WHERE default_for_method = ?"
                                 . ($id ? " AND id <> ?" : ""));
            $id ? $clear->execute([$defMethod, $id]) : $clear->execute([$defMethod]);
        }

        if ($id) {
            $pdo->prepare("
                UPDATE bank_accounts
                   SET name = ?, bank_name = ?, account_name = ?, account_no = ?, branch = ?,
                       account_type = ?, paybill_no = ?, opening_balance = ?, ledger_account_id = ?,
                       default_for_method = ?, is_default = ?, is_active = ?, sort_order = ?, notes = ?
                 WHERE id = ?
            ")->execute([
                $name, $bankName ?: null, $accountName ?: null, $accountNo ?: null, $branch ?: null,
                $type, $paybill ?: null, $opening, $ledgerId ?: null,
                $defMethod ?: null, $isDefault ? 1 : 0, $isActive ? 1 : 0, $sortOrder, $notes ?: null,
                $id,
            ]);
            $verb = 'updated';
        } else {
            $id = generateUUID();
            $pdo->prepare("
                INSERT INTO bank_accounts
                    (id, name, bank_name, account_name, account_no, branch, account_type, paybill_no,
                     opening_balance, ledger_account_id, default_for_method, is_default, is_active, sort_order, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $id, $name, $bankName ?: null, $accountName ?: null, $accountNo ?: null, $branch ?: null,
                $type, $paybill ?: null, $opening, $ledgerId ?: null,
                $defMethod ?: null, $isDefault ? 1 : 0, $isActive ? 1 : 0, $sortOrder, $notes ?: null,
            ]);
            $verb = 'added';
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $fail('Could not save the account: ' . $e->getMessage());
    }

    logAction($pdo, 'bank_account_' . $verb, 'Financials', $id,
        "{$name} ({$type})" . ($accountNo ? " — {$accountNo}" : '') . ($paybill ? " — {$paybill}" : ''));

    bankBack($redirect, 'success', "Collection account {$verb}: {$name}.");
}

/* ═══════════════════════════════════════════════════════════════════════
   ARCHIVE / RESTORE
   An account with payments against it is never deleted — that would orphan
   the history. It is archived so it stops appearing on new payment forms.
   ═══════════════════════════════════════════════════════════════════════ */
if ($action === 'toggle_active') {
    requirePermission($pdo, 'bank_accounts', 'edit');

    $id = trim($_POST['account_id'] ?? '');
    $account = getBankAccount($pdo, $id);
    if (!$account) $fail('Account not found.');

    $newState = (int)$account['is_active'] === 1 ? 0 : 1;

    // Never leave every account archived — payments would have nowhere to go
    if ($newState === 0) {
        $remaining = (int)$pdo->query("SELECT COUNT(*) FROM bank_accounts WHERE is_active = 1")->fetchColumn();
        if ($remaining <= 1) {
            $fail('This is your only active collection account. Add another before archiving this one.');
        }
    }

    $pdo->prepare("UPDATE bank_accounts SET is_active = ?, is_default = CASE WHEN ? = 0 THEN 0 ELSE is_default END WHERE id = ?")
        ->execute([$newState, $newState, $id]);

    logAction($pdo, $newState ? 'bank_account_restored' : 'bank_account_archived', 'Financials', $id,
        (string)$account['name']);

    bankBack($redirect, 'success',
        $account['name'] . ($newState ? ' restored.' : ' archived — it will no longer appear on payment forms.'));
}

/* ═══════════════════════════════════════════════════════════════════════
   DELETE — only ever allowed while an account has no payment history
   ═══════════════════════════════════════════════════════════════════════ */
if ($action === 'delete_account') {
    requirePermission($pdo, 'bank_accounts', 'delete');

    $id = trim($_POST['account_id'] ?? '');
    $account = getBankAccount($pdo, $id);
    if (!$account) $fail('Account not found.');

    $used = $pdo->prepare("SELECT COUNT(*) FROM transactions WHERE bank_account_id = ?");
    $used->execute([$id]);
    $count = (int)$used->fetchColumn();

    if ($count > 0) {
        $fail(sprintf(
            '%s has %d payment%s recorded against it and cannot be deleted. Archive it instead — the history stays intact.',
            $account['name'], $count, $count === 1 ? '' : 's'
        ));
    }

    $pdo->prepare("DELETE FROM bank_accounts WHERE id = ?")->execute([$id]);
    logAction($pdo, 'bank_account_deleted', 'Financials', $id, (string)$account['name']);

    bankBack($redirect, 'success', $account['name'] . ' deleted.');
}

/* ═══════════════════════════════════════════════════════════════════════
   ASSIGN AN ACCOUNT TO PAYMENTS RECORDED BEFORE THIS FEATURE EXISTED
   ═══════════════════════════════════════════════════════════════════════ */
if ($action === 'bank_unassigned') {
    requirePermission($pdo, 'bank_accounts', 'edit');

    $accountId = trim($_POST['bank_account_id'] ?? '');
    $method    = trim($_POST['payment_method']  ?? '');

    $account = getBankAccount($pdo, $accountId);
    if (!$account) $fail('Choose the account these payments were banked into.');

    $params = [$accountId];
    $sql    = "UPDATE transactions SET bank_account_id = ?
               WHERE status = 'Paid' AND (bank_account_id IS NULL OR bank_account_id = '')";

    if ($method !== '') {
        $sql     .= " AND payment_method = ?";
        $params[] = $method;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $updated = $stmt->rowCount();

    logAction($pdo, 'payments_banked', 'Financials', $accountId,
        "{$updated} previously unbanked payment(s) assigned to {$account['name']}"
        . ($method !== '' ? " (method: {$method})" : ''));

    bankBack($redirect, 'success',
        $updated > 0
            ? "{$updated} payment" . ($updated === 1 ? '' : 's') . " assigned to {$account['name']}."
            : 'No unbanked payments matched.');
}

header('Location: ../bank_accounts.php');
exit;
