<?php
/**
 * Expense Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/../includes/audit.php';

// Schema self-heal
foreach ([
    "ALTER TABLE expenses ADD COLUMN IF NOT EXISTS vendor      VARCHAR(255) NULL",
    "ALTER TABLE expenses ADD COLUMN IF NOT EXISTS notes       TEXT NULL",
    "ALTER TABLE expenses ADD COLUMN IF NOT EXISTS created_by  VARCHAR(36) NULL",
] as $ddl) {
    try { $pdo->exec($ddl); } catch (PDOException $e) {}
}

$action   = $_POST['action']    ?? '';
$redirect = trim($_POST['_redirect'] ?? '../expenses.php');

// ── Create ────────────────────────────────────────────────────────────
if ($action === 'create') {
    $description = trim($_POST['description'] ?? '');
    $amount      = (float)($_POST['amount'] ?? 0);
    $category    = $_POST['category']    ?? 'Other';
    $vendor      = trim($_POST['vendor'] ?? '');
    $notes       = trim($_POST['notes']  ?? '');
    $propertyId  = !empty($_POST['property_id']) ? $_POST['property_id'] : null;
    $expenseDate = $_POST['expense_date'] ?? date('Y-m-d');

    if (!$description || $amount <= 0) {
        header('Location: ' . $redirect . '?error=' . urlencode('Description and a valid amount are required.'));
        exit();
    }

    try {
        $pdo->prepare("
            INSERT INTO expenses (id, description, amount, category, vendor, notes, property_id, expense_date, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([generateUUID(), $description, $amount, $category, $vendor ?: null, $notes ?: null, $propertyId, $expenseDate, $_SESSION['user_id'] ?? null]);

        logAction($pdo, 'expense_created', 'Expenses', null, "{$category}: {$description} — " . number_format($amount));
        header('Location: ' . $redirect . '?success=expense_recorded');
    } catch (PDOException $e) {
        header('Location: ' . $redirect . '?error=' . urlencode($e->getMessage()));
    }
    exit();
}

// ── Edit ──────────────────────────────────────────────────────────────
if ($action === 'edit') {
    $id          = trim($_POST['id'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount      = (float)($_POST['amount'] ?? 0);
    $category    = $_POST['category']    ?? 'Other';
    $vendor      = trim($_POST['vendor'] ?? '');
    $notes       = trim($_POST['notes']  ?? '');
    $propertyId  = !empty($_POST['property_id']) ? $_POST['property_id'] : null;
    $expenseDate = $_POST['expense_date'] ?? date('Y-m-d');

    if (!$id || !$description || $amount <= 0) {
        header('Location: ' . $redirect . '?error=' . urlencode('Invalid data submitted.'));
        exit();
    }

    try {
        $pdo->prepare("
            UPDATE expenses
            SET description = ?, amount = ?, category = ?, vendor = ?, notes = ?, property_id = ?, expense_date = ?
            WHERE id = ?
        ")->execute([$description, $amount, $category, $vendor ?: null, $notes ?: null, $propertyId, $expenseDate, $id]);

        logAction($pdo, 'expense_updated', 'Expenses', $id, "{$category}: {$description}");
        header('Location: ' . $redirect . '?success=expense_updated');
    } catch (PDOException $e) {
        header('Location: ' . $redirect . '?error=' . urlencode($e->getMessage()));
    }
    exit();
}

// ── Delete ────────────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = trim($_POST['id'] ?? '');
    if (!$id) { header('Location: ' . $redirect); exit(); }

    try {
        $row = $pdo->prepare("SELECT description, amount, category FROM expenses WHERE id = ?");
        $row->execute([$id]);
        $exp = $row->fetch();

        $pdo->prepare("DELETE FROM expenses WHERE id = ?")->execute([$id]);
        logAction($pdo, 'expense_deleted', 'Expenses', $id, $exp ? "{$exp['category']}: {$exp['description']}" : $id);
        header('Location: ' . $redirect . '?success=expense_deleted');
    } catch (PDOException $e) {
        header('Location: ' . $redirect . '?error=' . urlencode($e->getMessage()));
    }
    exit();
}

header('Location: ' . $redirect);
exit();
?>
