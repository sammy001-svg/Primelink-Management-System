<?php
/**
 * Billing Run API
 * Primelink Management System
 *
 * Drives the billing run inside the Generate Invoices dialog. Everything is
 * JSON so the dialog never has to close: pick a property, walk the tenants,
 * bill each one, all without a page load.
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/corrections.php';
require_once __DIR__ . '/../includes/billing_run.php';
require_once __DIR__ . '/../includes/tenant_notify.php';

ensureCorrectionSchema($pdo);
ensureBillingRunSchema($pdo);

header('Content-Type: application/json');

$currency = getSetting($pdo, 'currency_symbol', 'KSh');
$action   = $_REQUEST['action'] ?? '';

function jsonOut(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

/* ═══════════════════════════════════════════════════════════════════════
   LOAD A RUN
   ═══════════════════════════════════════════════════════════════════════ */
if ($action === 'run') {
    $propertyId = trim($_GET['property_id'] ?? '');
    if (!$propertyId) jsonOut(['ok' => false, 'error' => 'No property given.'], 400);

    $payload = billingRunPayload($pdo, $propertyId, date('Y-m'));
    if (!($payload['ok'] ?? false)) jsonOut($payload, 404);

    $payload['currency'] = $currency;
    $payload['due_date'] = date('Y-m-d', strtotime('+' . max(1, (int)getSetting($pdo, 'invoice_due_days', '7')) . ' days'));
    jsonOut($payload);
}

/* ═══════════════════════════════════════════════════════════════════════
   BILL ONE TENANT
   ═══════════════════════════════════════════════════════════════════════ */
if ($action === 'bill') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonOut(['ok' => false, 'error' => 'Billing must be posted.'], 405);
    }
    if (!canDo($pdo, 'invoices', 'create')) {
        jsonOut(['ok' => false, 'error' => 'You do not have permission to raise invoices.'], 403);
    }

    $tenantId = trim($_POST['tenant_id'] ?? '');
    $leaseId  = trim($_POST['lease_id']  ?? '') ?: null;
    $unitId   = trim($_POST['unit_id']   ?? '') ?: null;
    $dueDate  = trim($_POST['due_date']  ?? '') ?: date('Y-m-d', strtotime('+7 days'));
    $note     = trim($_POST['description'] ?? '');

    if (!$tenantId) jsonOut(['ok' => false, 'error' => 'No tenant given.'], 400);

    $rent    = round((float)($_POST['charge_rent']    ?? 0), 2);
    $garbage = round((float)($_POST['charge_garbage'] ?? 0), 2);

    $waterMode  = ($_POST['water_mode'] ?? 'meter') === 'amount' ? 'amount' : 'meter';
    $prevRead   = round((float)($_POST['water_previous'] ?? 0), 2);
    $currRead   = round((float)($_POST['water_current']  ?? 0), 2);
    $waterRate  = round((float)($_POST['water_rate']     ?? 0), 2);

    // The standing charge is read from the property, never from the form —
    // the browser must not be able to talk the bill down.
    $waterFixed = 0.0;
    try {
        $fx = $pdo->prepare("SELECT COALESCE(p.water_fixed_charge, 0)
                             FROM leases l JOIN units u ON l.unit_id = u.id
                             JOIN properties p ON u.property_id = p.id
                             WHERE l.id = ? LIMIT 1");
        $fx->execute([$leaseId]);
        $waterFixed = round((float)$fx->fetchColumn(), 2);
    } catch (PDOException $e) {}

    if ($waterMode === 'amount') {
        $water = round((float)($_POST['charge_water'] ?? 0), 2);
        $consumption = 0.0;
        $usageAmount = $water;
        $appliedFixed = 0.0;   // a hand-entered figure is taken as the whole bill
    } else {
        $calc = waterCharge($prevRead, $currRead, $waterRate, $waterFixed);
        $water        = $calc['amount'];
        $consumption  = $calc['consumption'];
        $usageAmount  = $calc['usage_amount'];
        $appliedFixed = $calc['fixed'];
    }

    foreach (['Rent' => $rent, 'Water' => $water, 'Garbage' => $garbage] as $label => $amt) {
        if ($amt < 0) jsonOut(['ok' => false, 'error' => $label . ' cannot be negative.'], 422);
    }

    $charges = array_filter(
        ['Rent' => $rent, 'Water' => $water, 'Garbage' => $garbage],
        fn($a) => $a > 0.009
    );
    if (!$charges) {
        jsonOut(['ok' => false, 'error' => 'Nothing to bill — every charge was zero.'], 422);
    }

    $batchId = generateUUID();
    $total   = round(array_sum($charges), 2);

    try {
        $pdo->beginTransaction();

        $insert = $pdo->prepare("
            INSERT INTO invoices
                (id, tenant_id, lease_id, amount, due_date, status, invoice_type, description, batch_id, created_at)
            VALUES (?, ?, ?, ?, ?, 'Unpaid', ?, ?, ?, NOW())
        ");

        $waterInvoiceId = null;
        foreach ($charges as $type => $amount) {
            $invId = generateUUID();

            $lineNote = $note;
            if ($type === 'Water' && $waterMode === 'meter') {
                $lineNote = trim(sprintf(
                    '%s (reading %s → %s, %s units @ %s %s = %s %s%s)',
                    $note ?: 'Water consumption',
                    rtrim(rtrim(number_format($prevRead, 2), '0'), '.'),
                    rtrim(rtrim(number_format($currRead, 2), '0'), '.'),
                    rtrim(rtrim(number_format($consumption, 2), '0'), '.'),
                    $currency,
                    rtrim(rtrim(number_format($waterRate, 2), '0'), '.'),
                    $currency,
                    number_format($usageAmount, 2),
                    $appliedFixed > 0.009
                        ? sprintf(' + %s %s standing charge', $currency, number_format($appliedFixed, 2))
                        : ''
                ));
                $waterInvoiceId = $invId;
            }

            $insert->execute([
                $invId, $tenantId, $leaseId, $amount, $dueDate, $type,
                $lineNote ?: null, $batchId,
            ]);
        }

        if ($waterMode === 'meter' && isset($charges['Water'])) {
            $pdo->prepare("
                INSERT INTO meter_readings
                    (id, unit_id, tenant_id, meter_type, previous_reading, current_reading,
                     consumption, rate, amount, reading_date, invoice_id, recorded_by)
                VALUES (?, ?, ?, 'Water', ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                generateUUID(), $unitId, $tenantId, $prevRead, $currRead,
                $consumption, $waterRate, $water, date('Y-m-d'),
                $waterInvoiceId, $_SESSION['user_id'] ?? null,
            ]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        jsonOut(['ok' => false, 'error' => 'Could not raise the invoice: ' . $e->getMessage()], 500);
    }

    // ── Tell the tenant ───────────────────────────────────────────────
    $noticeSummary = '';
    if (!empty($_POST['notify_email']) || !empty($_POST['notify_sms'])) {
        $tRow = $pdo->prepare("
            SELECT t.id, t.full_name, t.email, t.phone, t.user_id, u.unit_number
            FROM tenants t
            LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'Active'
            LEFT JOIN units  u ON l.unit_id = u.id
            WHERE t.id = ? LIMIT 1
        ");
        $tRow->execute([$tenantId]);
        if ($tenant = $tRow->fetch()) {
            $items = [];
            foreach ($charges as $type => $amount) {
                $items[] = ['invoice_type' => $type, 'amount' => $amount];
            }
            $res = dispatchTenantNotice(
                $pdo, $tenant,
                buildBundleNotice($pdo, $tenant, [
                    'batch_id'    => $batchId,
                    'items'       => $items,
                    'total'       => $total,
                    'due_date'    => $dueDate,
                    'description' => $note,
                    'unit_number' => $tenant['unit_number'] ?? '',
                ]),
                ['email' => !empty($_POST['notify_email']), 'sms' => !empty($_POST['notify_sms'])],
                'billing_run'
            );
            $noticeSummary = summariseNotices([$res]);
        }
    }

    logAction($pdo, 'billing_run_invoice', 'Invoices', $batchId,
        implode(' · ', array_map(
            fn($t, $a) => $t . ' ' . $currency . ' ' . number_format($a, 2),
            array_keys($charges), $charges
        ))
        . ' | Total ' . $currency . ' ' . number_format($total, 2)
        . ' | Due ' . $dueDate
        . ($noticeSummary ? ' | Notice: ' . $noticeSummary : ''));

    jsonOut([
        'ok'       => true,
        'total'    => $total,
        'batch_id' => $batchId,
        'charges'  => $charges,
        'notice'   => $noticeSummary,
    ]);
}

jsonOut(['ok' => false, 'error' => 'Unknown action.'], 400);
