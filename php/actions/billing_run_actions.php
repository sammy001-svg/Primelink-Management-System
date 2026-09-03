<?php
/**
 * Billing Run Actions
 * Primelink Management System
 *
 * Bills one tenant, then advances the run to the next unit on the property.
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

$currency = getSetting($pdo, 'currency_symbol', 'KSh');
$action   = $_POST['action'] ?? '';

/** Back to the run, positioned on a given tenant index. */
function runBack(string $propertyId, int $index, array $extra = []): void {
    $q = ['property_id' => $propertyId, 'i' => max(0, $index)] + $extra;
    header('Location: ../billing_run.php?' . http_build_query($q));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../billing_run.php');
    exit;
}

/* ═══════════════════════════════════════════════════════════════════════
   BILL ONE TENANT
   ═══════════════════════════════════════════════════════════════════════ */
if ($action === 'bill_tenant') {
    requirePermission($pdo, 'invoices', 'create');

    $propertyId = trim($_POST['property_id'] ?? '');
    $tenantId   = trim($_POST['tenant_id']   ?? '');
    $leaseId    = trim($_POST['lease_id']    ?? '') ?: null;
    $unitId     = trim($_POST['unit_id']     ?? '') ?: null;
    $index      = (int)($_POST['index']      ?? 0);
    $dueDate    = trim($_POST['due_date']    ?? '') ?: date('Y-m-d', strtotime('+7 days'));
    $note       = trim($_POST['description'] ?? '');
    $notifyMail = !empty($_POST['notify_email']);
    $notifySms  = !empty($_POST['notify_sms']);

    if (!$propertyId || !$tenantId) {
        runBack($propertyId, $index, ['error' => 'Missing tenant or property.']);
    }

    $rent    = round((float)($_POST['charge_rent']    ?? 0), 2);
    $garbage = round((float)($_POST['charge_garbage'] ?? 0), 2);

    // Water is entered either as meter readings or as a flat amount
    $waterMode = ($_POST['water_mode'] ?? 'meter') === 'amount' ? 'amount' : 'meter';
    $prevRead  = round((float)($_POST['water_previous'] ?? 0), 2);
    $currRead  = round((float)($_POST['water_current']  ?? 0), 2);
    $waterRate = round((float)($_POST['water_rate']     ?? 0), 2);

    if ($waterMode === 'amount') {
        $water = round((float)($_POST['charge_water'] ?? 0), 2);
        $consumption = 0.0;
    } else {
        $calc  = waterCharge($prevRead, $currRead, $waterRate);
        $water = $calc['amount'];
        $consumption = $calc['consumption'];
    }

    foreach (['Rent' => $rent, 'Water' => $water, 'Garbage' => $garbage] as $label => $amt) {
        if ($amt < 0) runBack($propertyId, $index, ['error' => $label . ' cannot be negative.']);
    }

    $charges = array_filter(
        ['Rent' => $rent, 'Water' => $water, 'Garbage' => $garbage],
        fn($a) => $a > 0.009
    );

    if (!$charges) {
        runBack($propertyId, $index, ['error' => 'Nothing to bill — every charge was zero.']);
    }

    $batchId  = generateUUID();
    $total    = round(array_sum($charges), 2);
    $firstInv = null;

    try {
        $pdo->beginTransaction();

        $insert = $pdo->prepare("
            INSERT INTO invoices
                (id, tenant_id, lease_id, amount, due_date, status, invoice_type, description, batch_id, created_at)
            VALUES (?, ?, ?, ?, ?, 'Unpaid', ?, ?, ?, NOW())
        ");

        $waterInvoiceId = null;
        foreach ($charges as $type => $amount) {
            $invId    = generateUUID();
            $firstInv = $firstInv ?: $invId;

            $lineNote = $note;
            if ($type === 'Water' && $waterMode === 'meter') {
                $lineNote = trim(sprintf(
                    '%s (reading %s → %s, %s units @ %s %s)',
                    $note ?: 'Water consumption',
                    rtrim(rtrim(number_format($prevRead, 2), '0'), '.'),
                    rtrim(rtrim(number_format($currRead, 2), '0'), '.'),
                    rtrim(rtrim(number_format($consumption, 2), '0'), '.'),
                    $currency,
                    rtrim(rtrim(number_format($waterRate, 2), '0'), '.')
                ));
                $waterInvoiceId = $invId;
            }

            $insert->execute([
                $invId, $tenantId, $leaseId, $amount, $dueDate, $type,
                $lineNote ?: null, $batchId,
            ]);
        }

        // Keep the reading so next month's consumption can be measured from it
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
        runBack($propertyId, $index, ['error' => 'Could not raise the invoice: ' . $e->getMessage()]);
    }

    // ── Tell the tenant ───────────────────────────────────────────────
    $noticeSummary = '';
    if ($notifyMail || $notifySms) {
        $tRow = $pdo->prepare("
            SELECT t.id, t.full_name, t.email, t.phone, t.user_id, u.unit_number
            FROM tenants t
            LEFT JOIN leases l ON l.tenant_id = t.id AND l.status = 'Active'
            LEFT JOIN units  u ON l.unit_id = u.id
            WHERE t.id = ? LIMIT 1
        ");
        $tRow->execute([$tenantId]);
        $tenant = $tRow->fetch();

        if ($tenant) {
            $items = [];
            foreach ($charges as $type => $amount) {
                $items[] = ['invoice_type' => $type, 'amount' => $amount];
            }
            $notice = buildBundleNotice($pdo, $tenant, [
                'batch_id'    => $batchId,
                'items'       => $items,
                'total'       => $total,
                'due_date'    => $dueDate,
                'description' => $note,
                'unit_number' => $tenant['unit_number'] ?? '',
            ]);
            $res = dispatchTenantNotice(
                $pdo, $tenant, $notice,
                ['email' => $notifyMail, 'sms' => $notifySms],
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

    runBack($propertyId, $index + 1, [
        'billed' => $total,
        'batch'  => $batchId,
    ] + ($noticeSummary ? ['notice' => $noticeSummary] : []));
}

/* ═══════════════════════════════════════════════════════════════════════
   SKIP — move on without billing
   ═══════════════════════════════════════════════════════════════════════ */
if ($action === 'skip_tenant') {
    $propertyId = trim($_POST['property_id'] ?? '');
    $index      = (int)($_POST['index'] ?? 0);
    runBack($propertyId, $index + 1, ['skipped' => 1]);
}

header('Location: ../billing_run.php');
exit;
