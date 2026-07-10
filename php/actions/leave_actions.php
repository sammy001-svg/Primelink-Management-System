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

// ── Self-heal tables + seed default types ─────────────────────────────────
function ensureLeaveTables(PDO $pdo): void {
    static $done = false; if ($done) return;
    $sqls = [
        "CREATE TABLE IF NOT EXISTS leave_types (
            id                VARCHAR(36)  NOT NULL PRIMARY KEY,
            name              VARCHAR(100) NOT NULL,
            days_per_year     INT          NOT NULL DEFAULT 21,
            color             VARCHAR(20)  NOT NULL DEFAULT 'green',
            carry_forward     TINYINT(1)   NOT NULL DEFAULT 0,
            requires_approval TINYINT(1)   NOT NULL DEFAULT 1,
            sort_order        INT          NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS leave_applications (
            id              VARCHAR(36)  NOT NULL PRIMARY KEY,
            employee_id     VARCHAR(36)  NOT NULL,
            leave_type_id   VARCHAR(36)  NOT NULL,
            start_date      DATE         NOT NULL,
            end_date        DATE         NOT NULL,
            days_requested  INT          NOT NULL,
            reason          TEXT         NULL,
            status          VARCHAR(20)  NOT NULL DEFAULT 'Pending',
            applied_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            reviewed_by     VARCHAR(255) NULL,
            reviewed_at     TIMESTAMP    NULL,
            review_notes    TEXT         NULL,
            FOREIGN KEY (employee_id)   REFERENCES employees(id)    ON DELETE CASCADE,
            FOREIGN KEY (leave_type_id) REFERENCES leave_types(id)  ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS leave_balances (
            id              VARCHAR(36) NOT NULL PRIMARY KEY,
            employee_id     VARCHAR(36) NOT NULL,
            leave_type_id   VARCHAR(36) NOT NULL,
            year            SMALLINT    NOT NULL,
            entitlement     INT         NOT NULL DEFAULT 0,
            used            INT         NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_bal (employee_id, leave_type_id, year),
            FOREIGN KEY (employee_id)   REFERENCES employees(id)   ON DELETE CASCADE,
            FOREIGN KEY (leave_type_id) REFERENCES leave_types(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($sqls as $sql) { try { $pdo->exec($sql); } catch (PDOException $e) {} }

    // Seed default leave types if table is empty
    $count = (int)$pdo->query("SELECT COUNT(*) FROM leave_types")->fetchColumn();
    if ($count === 0) {
        $defaults = [
            ['Annual Leave',       21, 'green',  1, 1, 1],
            ['Sick Leave',         15, 'orange', 0, 1, 2],
            ['Maternity Leave',    90, 'pink',   0, 1, 3],
            ['Paternity Leave',    14, 'blue',   0, 1, 4],
            ['Compassionate Leave', 3, 'purple', 0, 1, 5],
            ['Study Leave',         7, 'indigo', 0, 1, 6],
            ['Unpaid Leave',        0, 'slate',  0, 1, 7],
        ];
        $ins = $pdo->prepare("INSERT INTO leave_types (id,name,days_per_year,color,carry_forward,requires_approval,sort_order) VALUES (?,?,?,?,?,?,?)");
        foreach ($defaults as $d) {
            $ins->execute([generateUUID(), $d[0], $d[1], $d[2], $d[3], $d[4], $d[5]]);
        }
    }
    $done = true;
}
ensureLeaveTables($pdo);

// ── Helper: seed balances for employee × year ────────────────────────────
// (Functions below are available when file is included; action processing only runs on POST)

function seedBalances(PDO $pdo, string $empId, int $year): void {
    $types = $pdo->query("SELECT id, days_per_year FROM leave_types")->fetchAll();
    $ins = $pdo->prepare(
        "INSERT IGNORE INTO leave_balances (id, employee_id, leave_type_id, year, entitlement, used)
         VALUES (?, ?, ?, ?, ?, 0)"
    );
    foreach ($types as $t) {
        $ins->execute([generateUUID(), $empId, $t['id'], $year, $t['days_per_year']]);
    }
}

// ── Helper: working days between two dates (Mon–Fri) ────────────────────
function workingDays(string $from, string $to): int {
    $start = new DateTime($from);
    $end   = new DateTime($to);
    $end->modify('+1 day');
    $interval = new DateInterval('P1D');
    $days = 0;
    foreach (new DatePeriod($start, $interval, $end) as $d) {
        $dow = (int)$d->format('N'); // 1=Mon, 7=Sun
        if ($dow < 6) $days++;
    }
    return max(1, $days);
}

// ── Only process actions on direct POST ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

$redirect = trim($_POST['_redirect'] ?? '../leave.php');
$action   = trim($_POST['action'] ?? '');
$isAdmin  = $_SESSION['role'] === 'admin';

// ── apply ─────────────────────────────────────────────────────────────────
if ($action === 'apply_leave') {
    $empId       = hrPostStr('employee_id');
    $typeId      = hrPostStr('leave_type_id');
    $startDate   = hrPostStr('start_date');
    $endDate     = hrPostStr('end_date');
    $reason      = hrPostStr('reason');

    if (!$empId || !$typeId || !$startDate || !$endDate) { hrFlash($redirect, 'error', 'All fields required'); }
    if ($endDate < $startDate) { hrFlash($redirect, 'error', 'End date must be on or after start date'); }

    $days = workingDays($startDate, $endDate);

    // Check balance (skip for Unpaid Leave with days_per_year=0)
    $leaveType = $pdo->prepare("SELECT * FROM leave_types WHERE id=?");
    $leaveType->execute([$typeId]); $leaveType = $leaveType->fetch();
    if (!$leaveType) { hrFlash($redirect, 'error', 'Leave type not found'); }

    $year = (int)date('Y', strtotime($startDate));
    seedBalances($pdo, $empId, $year);

    if ($leaveType['days_per_year'] > 0) {
        $bal = $pdo->prepare("SELECT entitlement, used FROM leave_balances WHERE employee_id=? AND leave_type_id=? AND year=?");
        $bal->execute([$empId, $typeId, $year]);
        $bal = $bal->fetch();
        $remaining = ($bal['entitlement'] ?? 0) - ($bal['used'] ?? 0);
        if ($days > $remaining) {
            hrFlash($redirect, 'error', "Insufficient {$leaveType['name']} balance. Available: {$remaining} days, requested: {$days} days");
        }
    }

    $id = generateUUID();
    $pdo->prepare(
        "INSERT INTO leave_applications (id, employee_id, leave_type_id, start_date, end_date, days_requested, reason, status)
         VALUES (?,?,?,?,?,?,?,?)"
    )->execute([$id, $empId, $typeId, $startDate, $endDate, $days, $reason ?: null,
                $leaveType['requires_approval'] ? 'Pending' : 'Approved']);

    // If no approval needed, auto-deduct balance
    if (!$leaveType['requires_approval'] && $leaveType['days_per_year'] > 0) {
        $pdo->prepare("UPDATE leave_balances SET used = used + ? WHERE employee_id=? AND leave_type_id=? AND year=?")
            ->execute([$days, $empId, $typeId, $year]);
    }

    logAction($pdo, 'create', 'hr', $empId, "{$leaveType['name']} application: {$startDate} to {$endDate} ({$days} days)");
    hrFlash($redirect, 'success', "Leave application submitted ({$days} working days)");
}

// ── approve ───────────────────────────────────────────────────────────────
if ($action === 'approve_leave') {
    if (!$isAdmin) { hrFlash($redirect, 'error', 'Unauthorized'); }
    $appId       = hrPostStr('application_id');
    $reviewNotes = hrPostStr('review_notes');
    $reviewer    = $pdo->query("SELECT full_name FROM profiles WHERE id='{$_SESSION['user_id']}' LIMIT 1")->fetchColumn() ?: 'Admin';

    $app = $pdo->prepare("SELECT * FROM leave_applications WHERE id=?");
    $app->execute([$appId]); $app = $app->fetch();
    if (!$app || $app['status'] !== 'Pending') { hrFlash($redirect, 'error', 'Application not found or not pending'); }

    $pdo->prepare(
        "UPDATE leave_applications SET status='Approved', reviewed_by=?, reviewed_at=NOW(), review_notes=? WHERE id=?"
    )->execute([$reviewer, $reviewNotes ?: null, $appId]);

    // Deduct from balance
    $year = (int)date('Y', strtotime($app['start_date']));
    $leaveType = $pdo->prepare("SELECT days_per_year FROM leave_types WHERE id=?");
    $leaveType->execute([$app['leave_type_id']]); $lt = $leaveType->fetch();
    if (($lt['days_per_year'] ?? 0) > 0) {
        $pdo->prepare("UPDATE leave_balances SET used = used + ? WHERE employee_id=? AND leave_type_id=? AND year=?")
            ->execute([$app['days_requested'], $app['employee_id'], $app['leave_type_id'], $year]);
    }

    logAction($pdo, 'approve', 'hr', $appId, "Leave approved: {$app['days_requested']} days");
    hrFlash($redirect, 'success', 'Leave approved');
}

// ── reject ────────────────────────────────────────────────────────────────
if ($action === 'reject_leave') {
    if (!$isAdmin) { hrFlash($redirect, 'error', 'Unauthorized'); }
    $appId       = hrPostStr('application_id');
    $reviewNotes = hrPostStr('review_notes');
    $reviewer    = $pdo->query("SELECT full_name FROM profiles WHERE id='{$_SESSION['user_id']}' LIMIT 1")->fetchColumn() ?: 'Admin';

    $pdo->prepare(
        "UPDATE leave_applications SET status='Rejected', reviewed_by=?, reviewed_at=NOW(), review_notes=? WHERE id=? AND status='Pending'"
    )->execute([$reviewer, $reviewNotes ?: null, $appId]);
    logAction($pdo, 'reject', 'hr', $appId, 'Leave application rejected');
    hrFlash($redirect, 'success', 'Application rejected');
}

// ── cancel ────────────────────────────────────────────────────────────────
if ($action === 'cancel_leave') {
    $appId = hrPostStr('application_id');
    $app = $pdo->prepare("SELECT * FROM leave_applications WHERE id=?");
    $app->execute([$appId]); $app = $app->fetch();
    if (!$app) { hrFlash($redirect, 'error', 'Application not found'); }

    // Only admin or the employee owning it can cancel
    $ownerStmt = $pdo->prepare("SELECT user_id FROM employees WHERE id=?");
    $ownerStmt->execute([$app['employee_id']]); $owner = $ownerStmt->fetchColumn();
    if (!$isAdmin && $owner !== $_SESSION['user_id']) { hrFlash($redirect, 'error', 'Unauthorized'); }

    $pdo->prepare("UPDATE leave_applications SET status='Cancelled' WHERE id=? AND status IN ('Pending','Approved')")->execute([$appId]);

    // Restore balance if was approved
    if ($app['status'] === 'Approved') {
        $year = (int)date('Y', strtotime($app['start_date']));
        $lt = $pdo->prepare("SELECT days_per_year FROM leave_types WHERE id=?");
        $lt->execute([$app['leave_type_id']]); $lt = $lt->fetch();
        if (($lt['days_per_year'] ?? 0) > 0) {
            $pdo->prepare("UPDATE leave_balances SET used = GREATEST(0, used - ?) WHERE employee_id=? AND leave_type_id=? AND year=?")
                ->execute([$app['days_requested'], $app['employee_id'], $app['leave_type_id'], $year]);
        }
    }
    logAction($pdo, 'cancel', 'hr', $appId, 'Leave cancelled');
    hrFlash($redirect, 'success', 'Leave cancelled');
}

// ── adjust_balance (admin) ────────────────────────────────────────────────
if ($action === 'adjust_balance') {
    if (!$isAdmin) { hrFlash($redirect, 'error', 'Unauthorized'); }
    $empId   = hrPostStr('employee_id');
    $typeId  = hrPostStr('leave_type_id');
    $year    = (int)pd('year', (string)date('Y'));
    $entitle = (int)pd('entitlement');

    seedBalances($pdo, $empId, $year);
    $pdo->prepare("UPDATE leave_balances SET entitlement=? WHERE employee_id=? AND leave_type_id=? AND year=?")
        ->execute([$entitle, $empId, $typeId, $year]);
    logAction($pdo, 'adjust', 'hr', $empId, "Leave entitlement adjusted: {$entitle} days");
    hrFlash($redirect, 'success', 'Balance adjusted');
}

// ── create/edit leave type ────────────────────────────────────────────────
if ($action === 'save_leave_type') {
    if (!$isAdmin) { hrFlash($redirect, 'error', 'Unauthorized'); }
    $id       = hrPostStr('type_id');
    $name     = hrPostStr('name');
    $days     = (int)pd('days_per_year');
    $color    = hrPostStr('color', 'green');
    $carry    = hrPostStr('carry_forward') === '1' ? 1 : 0;
    $reqApprv = hrPostStr('requires_approval', '1') === '1' ? 1 : 0;
    $order    = (int)pd('sort_order', '0');

    if (!$name) { hrFlash($redirect, 'error', 'Leave type name required'); }
    if ($id) {
        $pdo->prepare("UPDATE leave_types SET name=?,days_per_year=?,color=?,carry_forward=?,requires_approval=?,sort_order=? WHERE id=?")
            ->execute([$name, $days, $color, $carry, $reqApprv, $order, $id]);
    } else {
        $pdo->prepare("INSERT INTO leave_types (id,name,days_per_year,color,carry_forward,requires_approval,sort_order) VALUES (?,?,?,?,?,?,?)")
            ->execute([generateUUID(), $name, $days, $color, $carry, $reqApprv, $order]);
    }
    hrFlash($redirect, 'success', 'Leave type saved');
}

// ── delete leave type ─────────────────────────────────────────────────────
if ($action === 'delete_leave_type') {
    if (!$isAdmin) { hrFlash($redirect, 'error', 'Unauthorized'); }
    $id = hrPostStr('type_id');
    $pdo->prepare("DELETE FROM leave_types WHERE id=?")->execute([$id]);
    hrFlash($redirect, 'success', 'Leave type deleted');
}

hrFlash($redirect, 'error', "Unknown action: {$action}");
