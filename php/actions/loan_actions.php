<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'staff']);
require_once __DIR__ . '/../includes/audit.php';

if (!function_exists('hrPostStr')) {
    function hrPostStr(string $k, string $def = ''): string { return trim((string)($_POST[$k] ?? $def)); }
    function hrPostFlt(string $k, float $d = 0.0): float   { return (float)($_POST[$k] ?? $d); }
    function hrFlash(string $url, string $t, string $m): void {
        header('Location: ' . $url . (str_contains($url, '?') ? '&' : '?') . $t . '=' . urlencode($m)); exit();
    }
}

// ── Self-heal table ──────────────────────────────────────────────────────
function ensureLoansTable(PDO $pdo): void {
    static $done = false; if ($done) return;
    try { $pdo->exec(
        "CREATE TABLE IF NOT EXISTS employee_loans (
            id                VARCHAR(36)   NOT NULL PRIMARY KEY,
            employee_id       VARCHAR(36)   NOT NULL,
            loan_type         VARCHAR(20)   NOT NULL DEFAULT 'Loan',
            amount            DECIMAL(15,2) NOT NULL,
            approved_amount   DECIMAL(15,2) NULL,
            purpose           TEXT          NULL,
            applied_date      DATE          NOT NULL,
            status            VARCHAR(20)   NOT NULL DEFAULT 'Pending',
            approved_by       VARCHAR(255)  NULL,
            approval_date     DATE          NULL,
            disbursed_date    DATE          NULL,
            monthly_deduction DECIMAL(15,2) NOT NULL DEFAULT 0,
            balance_remaining DECIMAL(15,2) NOT NULL DEFAULT 0,
            notes             TEXT          NULL,
            created_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ); } catch (PDOException $e) {}
    $done = true;
}
ensureLoansTable($pdo);

// ── Only process actions on direct POST ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

$redirect = trim($_POST['_redirect'] ?? '../hr.php');
$action   = trim($_POST['action'] ?? '');
$isAdmin  = $_SESSION['role'] === 'admin';

// ── apply ────────────────────────────────────────────────────────────────
if ($action === 'apply_loan') {
    $empId       = hrPostStr('employee_id');
    $loanType    = in_array(pd('loan_type'), ['Loan','Advance']) ? hrPostStr('loan_type') : 'Loan';
    $amount      = hrPostFlt('amount');
    $purpose     = hrPostStr('purpose');
    $appliedDate = hrPostStr('applied_date') ?: date('Y-m-d');

    if ($amount <= 0 || !$empId) { hrFlash($redirect, 'error', 'Invalid amount or employee'); }

    $id = generateUUID();
    $pdo->prepare(
        "INSERT INTO employee_loans (id, employee_id, loan_type, amount, purpose, applied_date, status)
         VALUES (?,?,?,?,?,?,'Pending')"
    )->execute([$id, $empId, $loanType, $amount, $purpose ?: null, $appliedDate]);
    logAction($pdo, 'create', 'hr', $empId, "{$loanType} application of KSh " . number_format($amount, 2));
    hrFlash($redirect, 'success', "{$loanType} application submitted");
}

// ── approve ──────────────────────────────────────────────────────────────
if ($action === 'approve_loan') {
    if (!$isAdmin) { hrFlash($redirect, 'error', 'Unauthorized'); }
    $loanId          = hrPostStr('loan_id');
    $approvedAmount  = hrPostFlt('approved_amount');
    $monthlyDed      = hrPostFlt('monthly_deduction');
    $disbursedDate   = hrPostStr('disbursed_date') ?: date('Y-m-d');
    $notes           = hrPostStr('notes');
    $approvedBy      = hrPostStr('approved_by') ?: ($pdo->query("SELECT full_name FROM profiles WHERE id='{$_SESSION['user_id']}' LIMIT 1")->fetchColumn() ?: 'Admin');

    $pdo->prepare(
        "UPDATE employee_loans SET status='Active', approved_amount=?, monthly_deduction=?,
            balance_remaining=?, approved_by=?, approval_date=CURDATE(), disbursed_date=?, notes=?
         WHERE id=? AND status='Pending'"
    )->execute([$approvedAmount, $monthlyDed, $approvedAmount, $approvedBy, $disbursedDate, $notes ?: null, $loanId]);
    logAction($pdo, 'approve', 'hr', $loanId, "Loan approved KSh " . number_format($approvedAmount, 2));
    hrFlash($redirect, 'success', 'Loan approved');
}

// ── reject ───────────────────────────────────────────────────────────────
if ($action === 'reject_loan') {
    if (!$isAdmin) { hrFlash($redirect, 'error', 'Unauthorized'); }
    $loanId = hrPostStr('loan_id');
    $notes  = hrPostStr('notes');
    $pdo->prepare("UPDATE employee_loans SET status='Rejected', notes=? WHERE id=? AND status='Pending'")->execute([$notes ?: null, $loanId]);
    logAction($pdo, 'reject', 'hr', $loanId, 'Loan application rejected');
    hrFlash($redirect, 'success', 'Application rejected');
}

// ── record repayment ─────────────────────────────────────────────────────
if ($action === 'repay_loan') {
    if (!$isAdmin) { hrFlash($redirect, 'error', 'Unauthorized'); }
    $loanId  = hrPostStr('loan_id');
    $amount  = hrPostFlt('amount');
    if ($amount <= 0) { hrFlash($redirect, 'error', 'Invalid repayment amount'); }

    // Reduce balance
    $pdo->prepare(
        "UPDATE employee_loans SET balance_remaining = GREATEST(0, balance_remaining - ?)
         WHERE id=?"
    )->execute([$amount, $loanId]);

    // Auto-complete if balance hits 0
    $pdo->prepare(
        "UPDATE employee_loans SET status='Completed' WHERE id=? AND balance_remaining <= 0 AND status='Active'"
    )->execute([$loanId]);

    logAction($pdo, 'repay', 'hr', $loanId, "Repayment KSh " . number_format($amount, 2));
    hrFlash($redirect, 'success', 'Repayment recorded');
}

// ── close (manual complete) ───────────────────────────────────────────────
if ($action === 'close_loan') {
    if (!$isAdmin) { hrFlash($redirect, 'error', 'Unauthorized'); }
    $loanId = hrPostStr('loan_id');
    $pdo->prepare("UPDATE employee_loans SET status='Completed', balance_remaining=0 WHERE id=?")->execute([$loanId]);
    hrFlash($redirect, 'success', 'Loan marked as completed');
}

// ── delete (pending only) ─────────────────────────────────────────────────
if ($action === 'delete_loan') {
    if (!$isAdmin) { hrFlash($redirect, 'error', 'Unauthorized'); }
    $loanId = hrPostStr('loan_id');
    $pdo->prepare("DELETE FROM employee_loans WHERE id=? AND status='Pending'")->execute([$loanId]);
    hrFlash($redirect, 'success', 'Application deleted');
}

hrFlash($redirect, 'error', "Unknown action: {$action}");
