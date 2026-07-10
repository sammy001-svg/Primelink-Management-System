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

// ── Self-heal table ──────────────────────────────────────────────────────────
function ensureBankTable(PDO $pdo): void {
    static $done = false; if ($done) return;
    try { $pdo->exec(
        "CREATE TABLE IF NOT EXISTS employee_bank_details (
            id           VARCHAR(36)  NOT NULL PRIMARY KEY,
            employee_id  VARCHAR(36)  NOT NULL,
            bank_name    VARCHAR(100) NOT NULL,
            branch_name  VARCHAR(100) NULL,
            account_name VARCHAR(150) NOT NULL,
            account_no   VARCHAR(50)  NOT NULL,
            account_type VARCHAR(30)  NOT NULL DEFAULT 'Savings',
            swift_code   VARCHAR(20)  NULL,
            is_primary   TINYINT(1)   NOT NULL DEFAULT 0,
            created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ); } catch (PDOException $e) {}
    $done = true;
}
ensureBankTable($pdo);

// ── Only process actions on direct POST ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

$redirect = trim($_POST['_redirect'] ?? '../hr.php');
$action   = trim($_POST['action'] ?? '');
$isAdmin  = $_SESSION['role'] === 'admin';

// ── save_bank (insert or update) ─────────────────────────────────────────────
if ($action === 'save_bank') {
    $bankId      = hrPostStr('bank_id');
    $empId       = hrPostStr('employee_id');
    $bankName    = hrPostStr('bank_name');
    $branchName  = hrPostStr('branch_name');
    $accountName = hrPostStr('account_name');
    $accountNo   = hrPostStr('account_no');
    $accountType = hrPostStr('account_type', 'Savings');
    $swiftCode   = hrPostStr('swift_code');
    $isPrimary   = hrPostStr('is_primary') === '1' ? 1 : 0;

    if (!$empId || !$bankName || !$accountName || !$accountNo) {
        hrFlash($redirect, 'error', 'Bank name, account name, and account number are required');
    }

    if ($isPrimary) {
        $pdo->prepare("UPDATE employee_bank_details SET is_primary=0 WHERE employee_id=?")->execute([$empId]);
    }

    if ($bankId) {
        $pdo->prepare(
            "UPDATE employee_bank_details
             SET bank_name=?, branch_name=?, account_name=?, account_no=?, account_type=?, swift_code=?, is_primary=?
             WHERE id=? AND employee_id=?"
        )->execute([$bankName, $branchName ?: null, $accountName, $accountNo, $accountType,
                    $swiftCode ?: null, $isPrimary, $bankId, $empId]);
        logAction($pdo, 'update', 'hr', $empId, "Bank details updated: {$bankName} {$accountNo}");
        hrFlash($redirect, 'success', 'bank_updated');
    } else {
        $id = generateUUID();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM employee_bank_details WHERE employee_id=?");
        $stmt->execute([$empId]); $count = (int)$stmt->fetchColumn();
        if ($count === 0) $isPrimary = 1;

        $pdo->prepare(
            "INSERT INTO employee_bank_details (id, employee_id, bank_name, branch_name, account_name, account_no, account_type, swift_code, is_primary)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([$id, $empId, $bankName, $branchName ?: null, $accountName, $accountNo, $accountType,
                    $swiftCode ?: null, $isPrimary]);
        logAction($pdo, 'create', 'hr', $empId, "Bank account added: {$bankName} {$accountNo}");
        hrFlash($redirect, 'success', 'bank_added');
    }
}

// ── set_primary ───────────────────────────────────────────────────────────────
if ($action === 'set_primary') {
    $bankId = hrPostStr('bank_id');
    $empId  = hrPostStr('employee_id');
    $pdo->prepare("UPDATE employee_bank_details SET is_primary=0 WHERE employee_id=?")->execute([$empId]);
    $pdo->prepare("UPDATE employee_bank_details SET is_primary=1 WHERE id=? AND employee_id=?")->execute([$bankId, $empId]);
    logAction($pdo, 'update', 'hr', $empId, 'Primary bank account changed');
    hrFlash($redirect, 'success', 'bank_primary_set');
}

// ── delete_bank ───────────────────────────────────────────────────────────────
if ($action === 'delete_bank') {
    $bankId = hrPostStr('bank_id');
    $empId  = hrPostStr('employee_id');
    $pdo->prepare("DELETE FROM employee_bank_details WHERE id=? AND employee_id=?")->execute([$bankId, $empId]);
    // Promote another account to primary if deleted was primary
    $pdo->prepare(
        "UPDATE employee_bank_details SET is_primary=1 WHERE employee_id=? AND is_primary=0 ORDER BY created_at ASC LIMIT 1"
    )->execute([$empId]);
    logAction($pdo, 'delete', 'hr', $empId, 'Bank account removed');
    hrFlash($redirect, 'success', 'bank_deleted');
}

hrFlash($redirect, 'error', "Unknown action: {$action}");
