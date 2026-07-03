<?php
/**
 * Late Payment Penalty Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../late_penalties.php');
    exit();
}

$action = $_POST['action'] ?? '';

$s = getSettings($pdo, [
    'penalty_enabled', 'penalty_grace_days', 'penalty_type',
    'penalty_amount', 'penalty_percentage', 'currency_symbol', 'invoice_due_days',
]);

$penEnabled  = ($s['penalty_enabled'] ?? '0') === '1';
$graceDays   = max(0, (int)($s['penalty_grace_days'] ?? 5));
$penType     = $s['penalty_type']       ?? 'fixed';
$penAmount   = (float)($s['penalty_amount']     ?? 500);
$penPct      = (float)($s['penalty_percentage'] ?? 5);
$currency    = $s['currency_symbol']    ?? 'KSh';
$dueDays     = max(1, (int)($s['invoice_due_days'] ?? 7));

if (!$penEnabled) {
    header('Location: ../late_penalties.php?error=' . urlencode('Penalties are disabled. Enable them in Settings first.'));
    exit();
}

function computePenaltyAmount(array $inv, string $type, float $fixed, float $pct): float {
    if ($type === 'percentage') {
        return round((float)$inv['amount'] * $pct / 100, 2);
    }
    return $fixed;
}

function applyPenaltyToInvoice(PDO $pdo, array $inv, float $penAmt, int $dueDays, string $currency): bool {
    if ($penAmt <= 0) return false;

    $dueDate  = date('Y-m-d', strtotime("+{$dueDays} days"));
    $penId    = generateUUID();

    $pdo->prepare("
        INSERT INTO invoices
            (id, tenant_id, lease_id, invoice_type, amount, due_date, status, source_invoice_id, notes, created_at)
        VALUES
            (?, ?, ?, 'Penalty', ?, ?, 'Pending', ?, ?, NOW())
    ")->execute([
        $penId,
        $inv['tenant_id'],
        $inv['lease_id'] ?? null,
        $penAmt,
        $dueDate,
        $inv['id'],
        'Late payment penalty for ' . ($inv['invoice_type'] ?? 'invoice') . ' due ' . date('M d, Y', strtotime($inv['due_date'])),
    ]);

    // Notify tenant
    if (!empty($inv['tenant_user_id'])) {
        $currencyFormatted = $currency . ' ' . number_format($penAmt);
        createNotification(
            $pdo,
            $inv['tenant_user_id'],
            'Late Payment Penalty Applied',
            "A penalty of {$currencyFormatted} has been added to your account for an overdue " .
            ($inv['invoice_type'] ?? 'invoice') . " (due " . date('M d, Y', strtotime($inv['due_date'])) . "). Please settle this at your earliest convenience.",
            'error'
        );
    }

    return true;
}

// Fetch eligible invoices (same logic as late_penalties.php)
function fetchEligible(PDO $pdo, int $graceDays): array {
    $stmt = $pdo->prepare("
        SELECT i.*,
               t.user_id AS tenant_user_id
        FROM   invoices i
        JOIN   tenants t ON i.tenant_id = t.id
        WHERE  i.status       NOT IN ('Paid', 'Cancelled')
          AND  i.invoice_type != 'Penalty'
          AND  i.due_date      <= CURDATE() - INTERVAL ? DAY
          AND  NOT EXISTS (
              SELECT 1 FROM invoices pen
              WHERE  pen.source_invoice_id = i.id
                AND  pen.invoice_type = 'Penalty'
          )
    ");
    $stmt->execute([$graceDays]);
    return $stmt->fetchAll();
}

// ─── apply_all ───────────────────────────────────────────────────────────────
if ($action === 'apply_all') {
    $eligible = fetchEligible($pdo, $graceDays);
    $applied  = 0;

    foreach ($eligible as $inv) {
        $penAmt = computePenaltyAmount($inv, $penType, $penAmount, $penPct);
        try {
            if (applyPenaltyToInvoice($pdo, $inv, $penAmt, $dueDays, $currency)) {
                $applied++;
            }
        } catch (PDOException $e) {
            // Skip duplicates silently
        }
    }

    logAction($pdo, 'penalties_applied_bulk', 'Financials', null,
        "{$applied} penalty invoice(s) created (grace={$graceDays}d, type={$penType})");

    header("Location: ../late_penalties.php?success=applied_all&count={$applied}");
    exit();
}

// ─── apply_single ────────────────────────────────────────────────────────────
if ($action === 'apply_single') {
    $invoiceId = trim($_POST['invoice_id'] ?? '');
    if (!$invoiceId) {
        header('Location: ../late_penalties.php?error=' . urlencode('Invalid invoice.'));
        exit();
    }

    // Fetch the specific invoice and verify it's eligible
    $stmt = $pdo->prepare("
        SELECT i.*, t.user_id AS tenant_user_id
        FROM   invoices i
        JOIN   tenants t ON i.tenant_id = t.id
        WHERE  i.id = ?
          AND  i.status NOT IN ('Paid', 'Cancelled')
          AND  i.invoice_type != 'Penalty'
          AND  i.due_date <= CURDATE() - INTERVAL ? DAY
          AND  NOT EXISTS (
              SELECT 1 FROM invoices pen
              WHERE pen.source_invoice_id = i.id
                AND pen.invoice_type = 'Penalty'
          )
    ");
    $stmt->execute([$invoiceId, $graceDays]);
    $inv = $stmt->fetch();

    if (!$inv) {
        header('Location: ../late_penalties.php?error=' . urlencode('Invoice not eligible or penalty already applied.'));
        exit();
    }

    $penAmt = computePenaltyAmount($inv, $penType, $penAmount, $penPct);

    try {
        applyPenaltyToInvoice($pdo, $inv, $penAmt, $dueDays, $currency);
        logAction($pdo, 'penalty_applied_single', 'Financials', $invoiceId,
            "Penalty {$currency} {$penAmt} applied to invoice {$invoiceId}");
        header('Location: ../late_penalties.php?success=applied_single');
    } catch (PDOException $e) {
        header('Location: ../late_penalties.php?error=' . urlencode('Could not apply penalty: ' . $e->getMessage()));
    }
    exit();
}

header('Location: ../late_penalties.php');
exit();
