<?php
/**
 * Journal Entry & Chart of Accounts Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/../includes/audit.php';

$action   = $_POST['action'] ?? '';
$redirect = trim($_POST['_redirect'] ?? '../journals.php');

self_heal_journals($pdo);

// ── Create Journal Entry ──────────────────────────────────────────────
if ($action === 'create_entry') {
    $entryDate  = trim($_POST['entry_date'] ?? date('Y-m-d'));
    $narration  = trim($_POST['narration']  ?? '');
    $reference  = strtoupper(trim($_POST['reference'] ?? ''));
    $postNow    = !empty($_POST['post_now']);

    $accountIds  = $_POST['account_id']  ?? [];
    $lineDescs   = $_POST['line_desc']   ?? [];
    $debits      = $_POST['debit']       ?? [];
    $credits     = $_POST['credit']      ?? [];

    if (!$entryDate || !$narration) {
        header('Location: ' . $redirect . '?error=' . urlencode('Date and narration are required.'));
        exit;
    }

    $totalDebit = $totalCredit = 0;
    $lines = [];
    foreach ($accountIds as $i => $accId) {
        $d = (float)($debits[$i]  ?? 0);
        $c = (float)($credits[$i] ?? 0);
        if (!$accId || ($d == 0 && $c == 0)) continue;
        $totalDebit  += $d;
        $totalCredit += $c;
        $lines[] = ['account_id' => $accId, 'desc' => trim($lineDescs[$i] ?? ''), 'debit' => $d, 'credit' => $c];
    }

    if (count($lines) < 2) {
        header('Location: ' . $redirect . '?error=' . urlencode('At least 2 non-zero lines are required.'));
        exit;
    }
    if (round($totalDebit, 2) !== round($totalCredit, 2)) {
        header('Location: ' . $redirect . '?error=' . urlencode('Entry is not balanced. Debits (' . number_format($totalDebit, 2) . ') ≠ Credits (' . number_format($totalCredit, 2) . ').'));
        exit;
    }

    if (!$reference) $reference = next_journal_ref($pdo);

    $entryId  = bin2hex(random_bytes(18));
    $status   = $postNow ? 'Posted' : 'Draft';
    $postedAt = $postNow ? date('Y-m-d H:i:s') : null;

    $pdo->prepare("INSERT INTO journal_entries (id, entry_date, reference, narration, status, total_amount, created_by, posted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$entryId, $entryDate, $reference, $narration, $status, $totalDebit, $_SESSION['user_id'] ?? null, $postedAt]);

    $ls = $pdo->prepare("INSERT INTO journal_lines (id, journal_entry_id, account_id, description, debit, credit) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($lines as $line) {
        $ls->execute([bin2hex(random_bytes(18)), $entryId, $line['account_id'], $line['desc'] ?: null, $line['debit'], $line['credit']]);
    }

    logAction($pdo, 'journal_created', 'Journals', $entryId, "{$reference} — {$narration} ({$status})");

    header('Location: ' . $redirect . '?success=entry_created');
    exit;
}

// ── Post Entry ────────────────────────────────────────────────────────
if ($action === 'post_entry') {
    $entryId = trim($_POST['entry_id'] ?? '');
    $stmt = $pdo->prepare("SELECT * FROM journal_entries WHERE id = ? AND status = 'Draft'");
    $stmt->execute([$entryId]);
    $entry = $stmt->fetch();
    if (!$entry) {
        header('Location: ' . $redirect . '?error=' . urlencode('Entry not found or already posted.'));
        exit;
    }

    $pdo->prepare("UPDATE journal_entries SET status = 'Posted', posted_at = NOW() WHERE id = ?")
        ->execute([$entryId]);
    logAction($pdo, 'journal_posted', 'Journals', $entryId, "Posted: {$entry['reference']} — {$entry['narration']}");

    header('Location: ' . $redirect . '?success=entry_posted');
    exit;
}

// ── Reverse Entry ─────────────────────────────────────────────────────
if ($action === 'reverse_entry') {
    $entryId = trim($_POST['entry_id'] ?? '');
    $reason  = trim($_POST['reason']   ?? '');

    $stmt = $pdo->prepare("SELECT * FROM journal_entries WHERE id = ? AND status = 'Posted'");
    $stmt->execute([$entryId]);
    $entry = $stmt->fetch();
    if (!$entry) {
        header('Location: ' . $redirect . '?error=' . urlencode('Entry not found or not in Posted status.'));
        exit;
    }

    $linesStmt = $pdo->prepare("SELECT * FROM journal_lines WHERE journal_entry_id = ?");
    $linesStmt->execute([$entryId]);
    $origLines = $linesStmt->fetchAll();

    $revId        = bin2hex(random_bytes(18));
    $revRef       = 'REV-' . $entry['reference'];
    $revNarration = 'REVERSAL of ' . $entry['reference'] . ($reason ? ' — ' . $reason : '');

    $pdo->prepare("INSERT INTO journal_entries (id, entry_date, reference, narration, status, total_amount, reversed_entry_id, created_by, posted_at) VALUES (?, ?, ?, ?, 'Posted', ?, ?, ?, NOW())")
        ->execute([$revId, date('Y-m-d'), $revRef, $revNarration, $entry['total_amount'], $entryId, $_SESSION['user_id'] ?? null]);

    $ls = $pdo->prepare("INSERT INTO journal_lines (id, journal_entry_id, account_id, description, debit, credit) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($origLines as $ol) {
        $ls->execute([bin2hex(random_bytes(18)), $revId, $ol['account_id'], 'Reversal: ' . ($ol['description'] ?? ''), $ol['credit'], $ol['debit']]);
    }

    $pdo->prepare("UPDATE journal_entries SET status = 'Reversed' WHERE id = ?")
        ->execute([$entryId]);

    logAction($pdo, 'journal_reversed', 'Journals', $entryId, "Reversed {$entry['reference']} → {$revRef}");

    header('Location: ' . $redirect . '?success=entry_reversed');
    exit;
}

// ── Delete Draft Entry ────────────────────────────────────────────────
if ($action === 'delete_entry') {
    $entryId = trim($_POST['entry_id'] ?? '');
    $stmt = $pdo->prepare("SELECT reference FROM journal_entries WHERE id = ? AND status = 'Draft'");
    $stmt->execute([$entryId]);
    $entry = $stmt->fetch();
    if (!$entry) {
        header('Location: ' . $redirect . '?error=' . urlencode('Only draft entries can be deleted.'));
        exit;
    }

    $pdo->prepare("DELETE FROM journal_lines WHERE journal_entry_id = ?")->execute([$entryId]);
    $pdo->prepare("DELETE FROM journal_entries WHERE id = ?")->execute([$entryId]);
    logAction($pdo, 'journal_deleted', 'Journals', $entryId, "Deleted draft: {$entry['reference']}");

    header('Location: ' . $redirect . '?success=entry_deleted');
    exit;
}

// ── Create Account ────────────────────────────────────────────────────
if ($action === 'create_account') {
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $name = trim($_POST['name'] ?? '');
    $type = trim($_POST['type'] ?? '');
    $desc = trim($_POST['description'] ?? '');

    $validTypes = ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'];
    if (!$code || !$name || !in_array($type, $validTypes, true)) {
        header('Location: ' . $redirect . '?error=' . urlencode('Account code, name, and type are required.'));
        exit;
    }

    try {
        $accId = bin2hex(random_bytes(18));
        $pdo->prepare("INSERT INTO accounts (id, code, name, type, description) VALUES (?, ?, ?, ?, ?)")
            ->execute([$accId, $code, $name, $type, $desc ?: null]);
        logAction($pdo, 'account_created', 'Journals', $accId, "{$code} {$name} ({$type})");
        header('Location: ' . $redirect . '?success=account_created');
    } catch (PDOException $e) {
        header('Location: ' . $redirect . '?error=' . urlencode('Account code already exists.'));
    }
    exit;
}

// ── Update Account ────────────────────────────────────────────────────
if ($action === 'update_account') {
    $accId = trim($_POST['account_id'] ?? '');
    $name  = trim($_POST['name']        ?? '');
    $type  = trim($_POST['type']        ?? '');
    $desc  = trim($_POST['description'] ?? '');

    $validTypes = ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense'];
    if (!$accId || !$name || !in_array($type, $validTypes, true)) {
        header('Location: ' . $redirect . '?error=' . urlencode('Name and type are required.'));
        exit;
    }

    $pdo->prepare("UPDATE accounts SET name = ?, type = ?, description = ? WHERE id = ?")
        ->execute([$name, $type, $desc ?: null, $accId]);
    logAction($pdo, 'account_updated', 'Journals', $accId, "Updated: {$name}");

    header('Location: ' . $redirect . '?success=account_updated');
    exit;
}

// ── Toggle Account Active ─────────────────────────────────────────────
if ($action === 'toggle_account') {
    $accId = trim($_POST['account_id'] ?? '');
    $pdo->prepare("UPDATE accounts SET is_active = 1 - is_active WHERE id = ?")->execute([$accId]);
    header('Location: ' . $redirect . '?success=account_updated');
    exit;
}

header('Location: ' . $redirect);
exit;

// ─────────────────────────────────────────────────────────────────────
function next_journal_ref(PDO $pdo): string {
    $year  = date('Y');
    $stmt  = $pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE YEAR(created_at) = ?");
    $stmt->execute([$year]);
    $n = (int)$stmt->fetchColumn() + 1;
    return 'JE-' . $year . '-' . str_pad($n, 4, '0', STR_PAD_LEFT);
}

function self_heal_journals(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS accounts (
            id          VARCHAR(36)  NOT NULL PRIMARY KEY,
            code        VARCHAR(20)  NOT NULL UNIQUE,
            name        VARCHAR(100) NOT NULL,
            type        ENUM('Asset','Liability','Equity','Revenue','Expense') NOT NULL,
            description TEXT         NULL,
            is_active   TINYINT(1)   NOT NULL DEFAULT 1,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS journal_entries (
            id                 VARCHAR(36)  NOT NULL PRIMARY KEY,
            entry_date         DATE         NOT NULL,
            reference          VARCHAR(50)  NOT NULL,
            narration          TEXT         NOT NULL,
            status             ENUM('Draft','Posted','Reversed') NOT NULL DEFAULT 'Draft',
            total_amount       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
            reversed_entry_id  VARCHAR(36)  NULL,
            created_by         VARCHAR(36)  NULL,
            created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            posted_at          DATETIME     NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS journal_lines (
            id                VARCHAR(36)    NOT NULL PRIMARY KEY,
            journal_entry_id  VARCHAR(36)    NOT NULL,
            account_id        VARCHAR(36)    NOT NULL,
            description       VARCHAR(255)   NULL,
            debit             DECIMAL(15,2)  NOT NULL DEFAULT 0.00,
            credit            DECIMAL(15,2)  NOT NULL DEFAULT 0.00,
            created_at        DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_jl_entry   (journal_entry_id),
            KEY idx_jl_account (account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {}

    // Seed default chart of accounts on first run
    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM accounts")->fetchColumn();
        if ($count === 0) seed_default_accounts($pdo);
    } catch (PDOException $e) {}
}

function seed_default_accounts(PDO $pdo): void {
    $accounts = [
        // Assets
        ['1000', 'Cash and Cash Equivalents',   'Asset'],
        ['1100', 'Rent Receivable',              'Asset'],
        ['1200', 'Security Deposits Held',       'Asset'],
        ['1300', 'Prepaid Expenses',             'Asset'],
        ['1400', 'Other Receivables',            'Asset'],
        ['1500', 'Property & Equipment',         'Asset'],
        ['1600', 'Accumulated Depreciation',     'Asset'],
        // Liabilities
        ['2000', 'Accounts Payable',             'Liability'],
        ['2100', 'Security Deposits Payable',    'Liability'],
        ['2200', 'Accrued Liabilities',          'Liability'],
        ['2300', 'Owner Distributions Payable',  'Liability'],
        // Equity
        ['3000', "Owner's Capital",              'Equity'],
        ['3100', 'Retained Earnings',            'Equity'],
        ['3200', 'Owner Draws / Withdrawals',    'Equity'],
        // Revenue
        ['4000', 'Rental Income',                'Revenue'],
        ['4100', 'Water Charges Income',         'Revenue'],
        ['4200', 'Electricity Income',           'Revenue'],
        ['4300', 'Garbage Charges Income',       'Revenue'],
        ['4400', 'Late Penalty Income',          'Revenue'],
        ['4500', 'Service Charge Income',        'Revenue'],
        ['4600', 'Management Fee Income',        'Revenue'],
        ['4700', 'Other Income',                 'Revenue'],
        // Expenses
        ['5000', 'Repairs & Maintenance',        'Expense'],
        ['5100', 'Salaries & Wages',             'Expense'],
        ['5200', 'Management Fees',              'Expense'],
        ['5300', 'Utilities (Company Paid)',     'Expense'],
        ['5400', 'Insurance',                    'Expense'],
        ['5500', 'Property Tax & Rates',         'Expense'],
        ['5600', 'Advertising & Marketing',      'Expense'],
        ['5700', 'Bank Charges',                 'Expense'],
        ['5800', 'Depreciation',                 'Expense'],
        ['5900', 'Other Expenses',               'Expense'],
    ];

    $stmt = $pdo->prepare("INSERT IGNORE INTO accounts (id, code, name, type) VALUES (?, ?, ?, ?)");
    foreach ($accounts as [$code, $name, $type]) {
        try { $stmt->execute([bin2hex(random_bytes(18)), $code, $name, $type]); } catch (PDOException $e) {}
    }
}
