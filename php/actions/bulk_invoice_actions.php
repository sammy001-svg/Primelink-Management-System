<?php
/**
 * Bulk Invoice Action Handler — Primelink Management System
 * Generates invoices in batch for all active leases matching the given filters.
 */

require_once __DIR__ . '/../includes/auth.php';
requireLogin(['admin', 'staff']);

require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/settings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../bulk_invoices.php");
    exit();
}

$month      = str_pad($_POST['month']       ?? date('m'), 2, '0', STR_PAD_LEFT);
$year       = (int)($_POST['year']          ?? date('Y'));
$type       = $_POST['type']                ?? 'Rent';
$propertyId = $_POST['property_id']         ?? 'all';
$amount     = (float)($_POST['amount']      ?? 0);
$dueDate    = $_POST['due_date']            ?? date('Y-m-d', strtotime('+7 days'));

$validTypes = ['Rent', 'Water', 'Garbage', 'Electricity', 'Deposit', 'Service Charge'];
if (!in_array($type, $validTypes, true)) {
    header("Location: ../bulk_invoices.php?error=invalid_type");
    exit();
}

// Build query for active leases
$propFilter = '';
$params     = [];
if ($propertyId !== 'all') {
    $propFilter = 'AND p.id = ?';
    $params[]   = $propertyId;
}

$sql = "
    SELECT
        l.id          AS lease_id,
        l.tenant_id,
        l.monthly_rent,
        t.full_name,
        t.user_id
    FROM leases l
    JOIN tenants    t  ON l.tenant_id   = t.id
    JOIN units      u  ON l.unit_id     = u.id
    JOIN properties p  ON u.property_id = p.id
    WHERE l.status = 'Active'
      AND t.status = 'Active'
      $propFilter
    ORDER BY p.title, u.unit_number
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leases = $stmt->fetchAll();

$generated = 0;
$skipped   = 0;

$insertStmt = $pdo->prepare(
    "INSERT INTO invoices (id, tenant_id, lease_id, amount, due_date, status, invoice_type)
     VALUES (?, ?, ?, ?, ?, 'Unpaid', ?)"
);

$dupCheckStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM invoices
     WHERE tenant_id = ? AND lease_id = ? AND invoice_type = ?
       AND MONTH(created_at) = ? AND YEAR(created_at) = ?
       AND status != 'Cancelled'"
);

foreach ($leases as $lease) {
    // Duplicate check
    $dupCheckStmt->execute([
        $lease['tenant_id'], $lease['lease_id'], $type, $month, $year
    ]);
    if ((int)$dupCheckStmt->fetchColumn() > 0) {
        $skipped++;
        continue;
    }

    // Determine amount: Rent uses lease monthly_rent; others use supplied amount
    $invAmount = ($type === 'Rent') ? (float)$lease['monthly_rent'] : $amount;

    if ($invAmount <= 0) {
        $skipped++;
        continue;
    }

    try {
        $invId = generateUUID();
        $insertStmt->execute([$invId, $lease['tenant_id'], $lease['lease_id'], $invAmount, $dueDate, $type]);

        // In-app notification for tenant
        if (!empty($lease['user_id'])) {
            $currency = getSetting($pdo, 'currency_symbol', 'KSh');
            $amtFmt   = $currency . ' ' . number_format($invAmount);
            createNotification(
                $pdo, $lease['user_id'],
                'New Invoice',
                "A {$type} invoice for {$amtFmt} has been issued, due on " . date('d M Y', strtotime($dueDate)) . '.',
                'warning'
            );
        }

        $generated++;
    } catch (PDOException $e) {
        // Skip on error (e.g. duplicate key race) — non-fatal
        $skipped++;
    }
}

// Audit log
$label = ucfirst($type) . " invoices for {$month}/{$year}";
if ($propertyId !== 'all') $label .= " (1 property)";
logAction($pdo, 'bulk_invoices_generated', 'Financials', '',
    "{$generated} {$label} generated, {$skipped} skipped");

header("Location: ../bulk_invoices.php?done=1&generated={$generated}&skipped={$skipped}");
exit();
