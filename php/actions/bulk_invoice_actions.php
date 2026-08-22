<?php
/**
 * Bulk Invoice Action Handler — Primelink Management System
 * Generates invoices in batch for all active leases matching the given filters.
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/tenant_notify.php';

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

$notifyEmail = !empty($_POST['notify_email']);
$notifySms   = !empty($_POST['notify_sms']);

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
        t.id          AS tenant_pk,
        t.full_name,
        t.email,
        t.phone,
        t.user_id,
        u.unit_number
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

$generated  = 0;
$skipped    = 0;
$issued     = [];   // [tenant row, invoice id, amount] for each invoice actually created

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

        $issued[] = ['lease' => $lease, 'invoice_id' => $invId, 'amount' => $invAmount];
        $generated++;
    } catch (PDOException $e) {
        // Skip on error (e.g. duplicate key race) — non-fatal
        $skipped++;
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   NOTIFY
   Invoices are written first and notified second, so a gateway problem can
   never leave a tenant billed-but-not-invoiced (or vice versa).
   ═══════════════════════════════════════════════════════════════════════ */
$noticeSummary = '';

if ($issued) {
    $results = [];

    // Personalised SMS means one gateway call per tenant, paced to stay inside
    // the 60/minute limit — a large run legitimately takes a few minutes.
    if ($notifySms && count($issued) > 20) {
        @set_time_limit(0);
        ignore_user_abort(true);
    }

    foreach ($issued as $row) {
        $lease  = $row['lease'];
        $tenant = [
            'id'        => $lease['tenant_pk'] ?? $lease['tenant_id'],
            'full_name' => $lease['full_name'],
            'email'     => $lease['email']   ?? '',
            'phone'     => $lease['phone']   ?? '',
            'user_id'   => $lease['user_id'] ?? null,
        ];

        $notice = buildInvoiceNotice($pdo, $tenant, [
            'id'           => $row['invoice_id'],
            'invoice_type' => $type,
            'amount'       => $row['amount'],
            'due_date'     => $dueDate,
            'description'  => '',
            'unit_number'  => $lease['unit_number'] ?? '',
        ]);

        // The in-app notice always goes; email/SMS only when asked for
        $results[] = dispatchTenantNotice(
            $pdo,
            $tenant,
            $notice,
            ['email' => $notifyEmail, 'sms' => $notifySms],
            'bulk_invoice_issued'
        );
    }

    if ($notifyEmail || $notifySms) {
        $noticeSummary = summariseNotices($results);
    }
}

// Audit log
$label = ucfirst($type) . " invoices for {$month}/{$year}";
if ($propertyId !== 'all') $label .= " (1 property)";
logAction($pdo, 'bulk_invoices_generated', 'Financials', '',
    "{$generated} {$label} generated, {$skipped} skipped"
    . ($noticeSummary ? " | Notice: {$noticeSummary}" : ''));

$q = $noticeSummary ? '&notice=' . urlencode($noticeSummary) : '';
header("Location: ../bulk_invoices.php?done=1&generated={$generated}&skipped={$skipped}{$q}");
exit();
