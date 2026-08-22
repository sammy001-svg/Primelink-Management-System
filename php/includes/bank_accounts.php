<?php
/**
 * Bank / Deposit Account Registry
 * Primelink Management System
 *
 * Every payment recorded has to say *where the money landed* — Co-op, KCB,
 * Equity, an M-Pesa paybill, or the cash box. Without that a receipt tells you
 * a tenant paid but not which account to reconcile it against.
 *
 * `bank_accounts` holds the company's own collection accounts (not tenant or
 * employee bank details, which live elsewhere). Each transaction carries a
 * `bank_account_id` pointing at the account it was deposited into.
 */

require_once __DIR__ . '/settings.php';

/** Account kinds a landlord actually collects into. */
const BANK_ACCOUNT_TYPES = ['Bank', 'M-Pesa Paybill', 'M-Pesa Till', 'Cash', 'Other'];

/** Payment methods that can be defaulted to an account. */
const BANK_PAYMENT_METHODS = ['Cash', 'M-Pesa', 'Bank Transfer', 'Cheque', 'Other'];

/* ═══════════════════════════════════════════════════════════════════════
   SCHEMA
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Create the registry and hang `bank_account_id` off transactions.
 * Idempotent, and cached per session so page loads cost nothing.
 */
function ensureBankAccountSchema(PDO $pdo, bool $force = false): void {
    static $done = false;
    if ($done && !$force) return;
    $done = true;

    $cacheKey = 'pl_bank_accounts_schema_v1';
    if (!$force && !empty($_SESSION[$cacheKey])) return;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `bank_accounts` (
                `id`                 VARCHAR(36) PRIMARY KEY,
                `name`               VARCHAR(120)  NOT NULL,
                `bank_name`          VARCHAR(120)  NULL,
                `account_name`       VARCHAR(150)  NULL,
                `account_no`         VARCHAR(60)   NULL,
                `branch`             VARCHAR(120)  NULL,
                `account_type`       VARCHAR(30)   NOT NULL DEFAULT 'Bank',
                `paybill_no`         VARCHAR(30)   NULL,
                `opening_balance`    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                `ledger_account_id`  VARCHAR(36)   NULL,
                `default_for_method` VARCHAR(30)   NULL,
                `is_active`          TINYINT(1)    NOT NULL DEFAULT 1,
                `is_default`         TINYINT(1)    NOT NULL DEFAULT 0,
                `sort_order`         INT           NOT NULL DEFAULT 0,
                `notes`              TEXT          NULL,
                `created_at`         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_bankacc_active` (`is_active`),
                INDEX `idx_bankacc_method` (`default_for_method`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {}

    // The link from a payment to the account it was banked into
    if (function_exists('ensureColumn')) {
        ensureColumn($pdo, 'transactions', 'bank_account_id', 'VARCHAR(36) NULL');
    } else {
        try { $pdo->exec("ALTER TABLE `transactions` ADD COLUMN `bank_account_id` VARCHAR(36) NULL"); }
        catch (PDOException $e) {}
    }
    try { $pdo->exec("CREATE INDEX `idx_tx_bank_account` ON `transactions` (`bank_account_id`)"); }
    catch (PDOException $e) {}

    seedDefaultBankAccounts($pdo);

    $_SESSION[$cacheKey] = 1;
}

/**
 * A brand-new install has nowhere to bank a payment, which would make the
 * "Deposited To" field useless on day one. Seed the two accounts every
 * landlord has — the cash box, and the M-Pesa paybill if one is configured.
 * Real bank accounts are the user's to add; guessing at them would be wrong.
 */
function seedDefaultBankAccounts(PDO $pdo): void {
    try {
        if ((int)$pdo->query("SELECT COUNT(*) FROM bank_accounts")->fetchColumn() > 0) return;

        $insert = $pdo->prepare("
            INSERT INTO bank_accounts (id, name, account_type, paybill_no, default_for_method, is_default, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([generateUUID(), 'Cash on Hand', 'Cash', null, 'Cash', 1, 10]);

        $shortcode = trim(getSetting($pdo, 'mpesa_shortcode', ''));
        if ($shortcode !== '' && $shortcode !== '—') {
            $insert->execute([
                generateUUID(), 'M-Pesa ' . $shortcode, 'M-Pesa Paybill', $shortcode, 'M-Pesa', 0, 20,
            ]);
        }
    } catch (PDOException $e) {
        // Non-fatal — the user can add accounts by hand
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   READING
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * All collection accounts, ordered for display.
 *
 * @param bool $activeOnly hide archived accounts (default true)
 */
function getBankAccounts(PDO $pdo, bool $activeOnly = true): array {
    try {
        $sql = "SELECT * FROM bank_accounts"
             . ($activeOnly ? " WHERE is_active = 1" : "")
             . " ORDER BY sort_order ASC, name ASC";
        return $pdo->query($sql)->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** One account, or null. */
function getBankAccount(PDO $pdo, ?string $id): ?array {
    if (!$id) return null;
    try {
        $stmt = $pdo->prepare("SELECT * FROM bank_accounts WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Which account a payment should default to.
 * Prefers one flagged for this payment method, then the overall default.
 */
function defaultBankAccountId(PDO $pdo, string $method = ''): ?string {
    $accounts = getBankAccounts($pdo);
    if (!$accounts) return null;

    if ($method !== '') {
        foreach ($accounts as $a) {
            if (($a['default_for_method'] ?? '') === $method) return $a['id'];
        }
    }
    foreach ($accounts as $a) {
        if ((int)($a['is_default'] ?? 0) === 1) return $a['id'];
    }
    return $accounts[0]['id'];
}

/**
 * Display label for an account: "Equity Bank — 0123456789".
 * Falls back to a plain dash so receipts never print an empty field.
 */
function bankAccountLabel(?array $account, bool $withNumber = true): string {
    if (!$account) return '—';

    $label = (string)$account['name'];

    if ($withNumber) {
        $ref = trim((string)($account['account_no'] ?: $account['paybill_no'] ?: ''));
        if ($ref !== '') {
            // Show only the tail of a bank account number on tenant-facing copies
            $label .= ' — ' . $ref;
        }
    }
    return $label;
}

/** Same, but masking all but the last four digits — for tenant-facing documents. */
function bankAccountLabelMasked(?array $account): string {
    if (!$account) return '—';

    $label = (string)$account['name'];
    $ref   = trim((string)($account['account_no'] ?: ''));

    if ($ref !== '' && strlen($ref) > 4) {
        $label .= ' — ****' . substr($ref, -4);
    } elseif (!empty($account['paybill_no'])) {
        $label .= ' — ' . $account['paybill_no'];   // paybills are public
    }
    return $label;
}

/**
 * Money received into each account, keyed by account id.
 * Opening balance plus every Paid transaction banked there.
 */
function bankAccountBalances(PDO $pdo): array {
    $totals = [];
    try {
        $rows = $pdo->query("
            SELECT b.id,
                   b.opening_balance,
                   COALESCE(SUM(CASE WHEN t.status = 'Paid' THEN t.amount END), 0) AS received,
                   COUNT(CASE WHEN t.status = 'Paid' THEN 1 END)                   AS payments
            FROM bank_accounts b
            LEFT JOIN transactions t ON t.bank_account_id = b.id
            GROUP BY b.id, b.opening_balance
        ")->fetchAll();

        foreach ($rows as $r) {
            $totals[$r['id']] = [
                'opening'  => (float)$r['opening_balance'],
                'received' => (float)$r['received'],
                'balance'  => (float)$r['opening_balance'] + (float)$r['received'],
                'payments' => (int)$r['payments'],
            ];
        }
    } catch (PDOException $e) {}
    return $totals;
}

/** Payments recorded without an account — the reconciliation backlog. */
function unbankedPaymentCount(PDO $pdo): int {
    try {
        return (int)$pdo->query(
            "SELECT COUNT(*) FROM transactions
             WHERE status = 'Paid' AND (bank_account_id IS NULL OR bank_account_id = '')"
        )->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   UI
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * The "Deposited To" picker shown on every record-a-payment form.
 *
 * When no accounts exist yet the field explains how to add them rather than
 * rendering an empty dropdown — and stays optional, so payment recording is
 * never blocked by unfinished setup.
 *
 * @param array $opts [
 *     'selected'   => currently chosen account id,
 *     'method'     => payment method, used to pick the default,
 *     'name'       => field name (default 'bank_account_id'),
 *     'id'         => element id (default 'bank_account_id'),
 *     'class'      => CSS classes for the <select>,
 *     'label'      => field label (default 'Deposited To'),
 *     'label_class'=> CSS classes for the label,
 *     'required'   => force required (default: required when accounts exist),
 * ]
 */
function renderBankAccountSelect(PDO $pdo, array $opts = []): string {
    $accounts = getBankAccounts($pdo);

    $name       = $opts['name']        ?? 'bank_account_id';
    $elId       = $opts['id']          ?? 'bank_account_id';
    $class      = $opts['class']       ?? 'form-input';
    $label      = $opts['label']       ?? 'Deposited To';
    $labelClass = $opts['label_class'] ?? 'text-[10px] font-black text-slate-400 uppercase tracking-widest';
    $method     = (string)($opts['method'] ?? '');
    $selected   = (string)($opts['selected'] ?? '');

    if ($selected === '' && $accounts) {
        $selected = (string)(defaultBankAccountId($pdo, $method) ?? '');
    }

    $labelHtml = '<label for="' . htmlspecialchars($elId) . '" class="' . htmlspecialchars($labelClass) . '">'
               . htmlspecialchars($label) . '</label>';

    if (!$accounts) {
        $canManage = ($_SESSION['role'] ?? '') === 'admin';
        $link = $canManage
            ? ' <a href="bank_accounts.php" style="color:#2563eb;font-weight:700;text-decoration:underline;">Add one</a>.'
            : ' Ask an administrator to add them.';
        return '<div class="space-y-1.5">' . $labelHtml
             . '<p style="font-size:11.5px;color:#94a3b8;line-height:1.5;margin:.35rem 0 0;">'
             . 'No collection accounts have been set up yet, so this payment will not be tied to an account.'
             . $link . '</p></div>';
    }

    $required = array_key_exists('required', $opts) ? (bool)$opts['required'] : true;
    $req      = $required ? ' required' : '';

    $options = $required ? '' : '<option value="">— Not specified —</option>';
    foreach ($accounts as $a) {
        $isSel = ((string)$a['id'] === $selected) ? ' selected' : '';
        $meta  = trim((string)($a['account_no'] ?: $a['paybill_no'] ?: ''));
        $text  = $a['name'] . ($meta !== '' ? ' · ' . $meta : '') . ' (' . $a['account_type'] . ')';
        $options .= '<option value="' . htmlspecialchars((string)$a['id']) . '"'
                  . ' data-method="' . htmlspecialchars((string)($a['default_for_method'] ?? '')) . '"'
                  . $isSel . '>' . htmlspecialchars($text) . '</option>';
    }

    return '<div class="space-y-1.5">' . $labelHtml
         . '<select name="' . htmlspecialchars($name) . '" id="' . htmlspecialchars($elId) . '"'
         . ' class="' . htmlspecialchars($class) . '"' . $req . '>' . $options . '</select>'
         . '</div>';
}

/** Small inline badge naming the account a payment landed in. */
function bankAccountBadge(?array $account): string {
    if (!$account) {
        return '<span style="display:inline-flex;align-items:center;background:#fef3c7;color:#92400e;border:1px solid #fde68a;'
             . 'font-size:9px;font-weight:900;letter-spacing:.06em;text-transform:uppercase;padding:2px 6px;border-radius:5px;'
             . 'white-space:nowrap;" title="This payment is not tied to a collection account">Unbanked</span>';
    }

    $tone = match ($account['account_type']) {
        'Cash'                        => ['#f1f5f9', '#475569', '#e2e8f0'],
        'M-Pesa Paybill', 'M-Pesa Till' => ['#ecfdf5', '#047857', '#a7f3d0'],
        default                       => ['#eff6ff', '#1d4ed8', '#bfdbfe'],
    };

    return '<span style="display:inline-flex;align-items:center;background:' . $tone[0] . ';color:' . $tone[1]
         . ';border:1px solid ' . $tone[2] . ';font-size:9px;font-weight:900;letter-spacing:.06em;'
         . 'text-transform:uppercase;padding:2px 6px;border-radius:5px;white-space:nowrap;">'
         . htmlspecialchars((string)$account['name']) . '</span>';
}
