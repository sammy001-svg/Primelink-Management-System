<?php
/**
 * Billing Run
 * Primelink Management System
 *
 * Billing a block is a walk, not a batch. Someone goes unit to unit reading
 * water meters, and for each tenant needs to see what is still owed before
 * entering this month's charges.
 *
 * This module supports that: it lists the tenants on a property in unit order,
 * reports each one's arrears split by charge type, and remembers the last water
 * meter reading so consumption can be worked out rather than guessed.
 *
 * The charges raised for one tenant share a batch_id, so they present as a
 * single combined invoice (see view_combined_invoice.php).
 */

require_once __DIR__ . '/settings.php';

/** Charges a billing run raises, in the order they are shown. */
const BILLING_RUN_CHARGES = ['Rent', 'Water', 'Garbage'];

/* ═══════════════════════════════════════════════════════════════════════
   SCHEMA
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Water is charged on consumption, so each reading has to be kept — next
 * month's bill is meaningless without this month's number.
 */
function ensureBillingRunSchema(PDO $pdo, bool $force = false): void {
    static $done = false;
    if ($done && !$force) return;
    $done = true;

    $cacheKey = 'pl_billing_run_schema_v1';
    if (!$force && !empty($_SESSION[$cacheKey])) return;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `meter_readings` (
                `id`               VARCHAR(36) PRIMARY KEY,
                `unit_id`          VARCHAR(36)   NULL,
                `tenant_id`        VARCHAR(36)   NULL,
                `meter_type`       VARCHAR(20)   NOT NULL DEFAULT 'Water',
                `previous_reading` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `current_reading`  DECIMAL(15,2) NOT NULL DEFAULT 0,
                `consumption`      DECIMAL(15,2) NOT NULL DEFAULT 0,
                `rate`             DECIMAL(15,2) NOT NULL DEFAULT 0,
                `amount`           DECIMAL(15,2) NOT NULL DEFAULT 0,
                `reading_date`     DATE          NOT NULL,
                `invoice_id`       VARCHAR(36)   NULL,
                `recorded_by`      VARCHAR(36)   NULL,
                `created_at`       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_reading_unit` (`unit_id`, `meter_type`),
                INDEX `idx_reading_date` (`reading_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {}

    if (function_exists('ensureColumn')) {
        ensureColumn($pdo, 'invoices', 'batch_id', 'VARCHAR(36) NULL');
        ensureColumn($pdo, 'invoices', 'description', 'TEXT NULL');
    }

    $_SESSION[$cacheKey] = 1;
}

/* ═══════════════════════════════════════════════════════════════════════
   THE RUN
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Properties available to bill, with their standing charges and how many
 * tenants are on each.
 */
function billableProperties(PDO $pdo): array {
    try {
        return $pdo->query("
            SELECT p.id, p.title, p.location, p.water_rate, p.garbage_fee,
                   COUNT(DISTINCT l.tenant_id) AS tenant_count
            FROM properties p
            LEFT JOIN units  u ON u.property_id = p.id
            LEFT JOIN leases l ON l.unit_id = u.id AND l.status = 'Active'
            GROUP BY p.id, p.title, p.location, p.water_rate, p.garbage_fee
            HAVING tenant_count > 0
            ORDER BY p.title ASC
        ")->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** One property with its standing charges. */
function billingProperty(PDO $pdo, string $propertyId): ?array {
    try {
        $stmt = $pdo->prepare("SELECT * FROM properties WHERE id = ?");
        $stmt->execute([$propertyId]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * Every tenant on a property, in unit order — this is the walking order,
 * so the run follows the same path a caretaker would.
 */
function billingRunTenants(PDO $pdo, string $propertyId): array {
    try {
        $stmt = $pdo->prepare("
            SELECT l.id AS lease_id, l.monthly_rent,
                   t.id AS tenant_id, t.full_name, t.phone, t.email, t.user_id,
                   u.id AS unit_id, u.unit_number, u.water_meter
            FROM leases l
            JOIN tenants t ON l.tenant_id = t.id
            JOIN units   u ON l.unit_id   = u.id
            WHERE u.property_id = ? AND l.status = 'Active' AND t.status = 'Active'
            ORDER BY LENGTH(u.unit_number), u.unit_number ASC
        ");
        $stmt->execute([$propertyId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   WHAT IS STILL OWED
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Arrears for one tenant, split by charge type — what shows in the
 * "previous balance" column beside each charge.
 *
 * @return array{Rent: float, Water: float, Garbage: float, Other: float, total: float}
 */
function tenantArrearsByType(PDO $pdo, string $tenantId): array {
    $out = ['Rent' => 0.0, 'Water' => 0.0, 'Garbage' => 0.0, 'Other' => 0.0, 'total' => 0.0];

    try {
        $stmt = $pdo->prepare("
            SELECT i.invoice_type, i.amount,
                   COALESCE(SUM(CASE WHEN t.status = 'Paid' THEN t.amount END), 0) AS paid
            FROM invoices i
            LEFT JOIN transactions t ON t.invoice_id = i.id
            WHERE i.tenant_id = ? AND i.status NOT IN ('Paid', 'Cancelled')
            GROUP BY i.id, i.invoice_type, i.amount
        ");
        $stmt->execute([$tenantId]);

        foreach ($stmt->fetchAll() as $r) {
            $balance = round((float)$r['amount'] - (float)$r['paid'], 2);
            if ($balance <= 0.009) continue;

            $type = (string)$r['invoice_type'];
            $key  = in_array($type, BILLING_RUN_CHARGES, true) ? $type : 'Other';
            $out[$key]  += $balance;
            $out['total'] += $balance;
        }
    } catch (PDOException $e) {
        // No invoices yet — everything stays zero
    }

    foreach ($out as $k => $v) $out[$k] = round($v, 2);
    return $out;
}

/**
 * The last water reading taken for a unit, so this month's consumption can be
 * measured from it. Returns 0 when the meter has never been read.
 */
function lastMeterReading(PDO $pdo, ?string $unitId, string $meterType = 'Water'): float {
    if (!$unitId) return 0.0;
    try {
        $stmt = $pdo->prepare("
            SELECT current_reading FROM meter_readings
            WHERE unit_id = ? AND meter_type = ?
            ORDER BY reading_date DESC, created_at DESC LIMIT 1
        ");
        $stmt->execute([$unitId, $meterType]);
        $val = $stmt->fetchColumn();
        return $val === false ? 0.0 : (float)$val;
    } catch (PDOException $e) {
        return 0.0;
    }
}

/**
 * Has this tenant already been billed for this charge in the given month?
 * Prevents a run being accidentally repeated and double-billing a block.
 *
 * The period is matched as a half-open date range rather than by formatting
 * created_at: wrapping a column in a function stops the index being used, and
 * DATE_FORMAT is MySQL-only.
 *
 * @param string $period 'YYYY-MM'
 */
function alreadyBilledThisPeriod(PDO $pdo, string $tenantId, string $type, string $period): bool {
    [$from, $until] = periodBounds($period);
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM invoices
            WHERE tenant_id = ? AND invoice_type = ?
              AND created_at >= ? AND created_at < ?
              AND status <> 'Cancelled'
        ");
        $stmt->execute([$tenantId, $type, $from, $until]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * First instant of a 'YYYY-MM' period, and the first instant of the next —
 * a half-open range, so nothing on the boundary is counted twice.
 *
 * @return array{0:string, 1:string}
 */
function periodBounds(string $period): array {
    $start = $period . '-01 00:00:00';
    $next  = date('Y-m-01 00:00:00', strtotime($period . '-01 +1 month'));
    return [$start, $next];
}

/** Which of this run's charges the tenant already has for the period. */
function billedChargesThisPeriod(PDO $pdo, string $tenantId, string $period): array {
    $done = [];
    foreach (BILLING_RUN_CHARGES as $type) {
        if (alreadyBilledThisPeriod($pdo, $tenantId, $type, $period)) $done[] = $type;
    }
    return $done;
}

/* ═══════════════════════════════════════════════════════════════════════
   PROGRESS
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * How far a run has got: how many tenants on the property already have
 * invoices for the period.
 *
 * Answered in one aggregate rather than a query per tenant — this is rendered
 * for every property in the invoice launcher, so an N+1 here is felt on a page
 * that is not even about billing.
 *
 * @return array{billed:int, total:int, remaining:int}
 */
function billingRunProgress(PDO $pdo, string $propertyId, string $period): array {
    [$from, $until] = periodBounds($period);

    $types  = BILLING_RUN_CHARGES;
    $slots  = implode(',', array_fill(0, count($types), '?'));

    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT l.tenant_id) AS total,
                   COUNT(DISTINCT CASE WHEN i.id IS NOT NULL THEN l.tenant_id END) AS billed
            FROM leases l
            JOIN units   u ON l.unit_id   = u.id
            JOIN tenants t ON l.tenant_id = t.id
            LEFT JOIN invoices i
                   ON i.tenant_id = l.tenant_id
                  AND i.created_at >= ? AND i.created_at < ?
                  AND i.status <> 'Cancelled'
                  AND i.invoice_type IN ({$slots})
            WHERE u.property_id = ? AND l.status = 'Active' AND t.status = 'Active'
        ");
        $stmt->execute(array_merge([$from, $until], $types, [$propertyId]));
        $row = $stmt->fetch() ?: ['total' => 0, 'billed' => 0];

        $total  = (int)$row['total'];
        $billed = (int)$row['billed'];
    } catch (PDOException $e) {
        $total = $billed = 0;
    }

    return [
        'billed'    => $billed,
        'total'     => $total,
        'remaining' => max(0, $total - $billed),
    ];
}

/**
 * Water consumption and what it costs.
 *
 * A meter that reads lower than last time has either been replaced or rolled
 * over; rather than bill a negative amount, consumption is treated as the
 * reading itself and the caller is told, so a human can check.
 *
 * @return array{consumption: float, amount: float, warning: ?string}
 */
function waterCharge(float $previous, float $current, float $rate): array {
    if ($current < $previous) {
        return [
            'consumption' => round($current, 2),
            'amount'      => round($current * $rate, 2),
            'warning'     => 'Current reading is lower than the previous one ('
                           . rtrim(rtrim(number_format($previous, 2), '0'), '.')
                           . '). Treated as a new or replaced meter — check before billing.',
        ];
    }
    $consumption = round($current - $previous, 2);
    return [
        'consumption' => $consumption,
        'amount'      => round($consumption * $rate, 2),
        'warning'     => null,
    ];
}

/* ═══════════════════════════════════════════════════════════════════════
   BATCH LOOKUPS
   The run is loaded in one go for the whole property, so the per-tenant
   helpers above would mean a query each. These answer for everyone at once.
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Arrears split by charge type for many tenants at once.
 *
 * @param array<int,string> $tenantIds
 * @return array<string, array> tenant id => same shape as tenantArrearsByType()
 */
function arrearsForTenants(PDO $pdo, array $tenantIds): array {
    $blank = ['Rent' => 0.0, 'Water' => 0.0, 'Garbage' => 0.0, 'Other' => 0.0, 'total' => 0.0];
    $out = [];
    foreach ($tenantIds as $id) $out[$id] = $blank;

    if (!$tenantIds) return $out;

    $slots = implode(',', array_fill(0, count($tenantIds), '?'));
    try {
        $stmt = $pdo->prepare("
            SELECT i.tenant_id, i.invoice_type, i.amount,
                   COALESCE(SUM(CASE WHEN t.status = 'Paid' THEN t.amount END), 0) AS paid
            FROM invoices i
            LEFT JOIN transactions t ON t.invoice_id = i.id
            WHERE i.tenant_id IN ({$slots}) AND i.status NOT IN ('Paid', 'Cancelled')
            GROUP BY i.id, i.tenant_id, i.invoice_type, i.amount
        ");
        $stmt->execute(array_values($tenantIds));

        foreach ($stmt->fetchAll() as $r) {
            $balance = round((float)$r['amount'] - (float)$r['paid'], 2);
            if ($balance <= 0.009) continue;

            $tid  = (string)$r['tenant_id'];
            $type = (string)$r['invoice_type'];
            $key  = in_array($type, BILLING_RUN_CHARGES, true) ? $type : 'Other';

            if (!isset($out[$tid])) $out[$tid] = $blank;
            $out[$tid][$key]    = round($out[$tid][$key] + $balance, 2);
            $out[$tid]['total'] = round($out[$tid]['total'] + $balance, 2);
        }
    } catch (PDOException $e) {
        // Nothing outstanding that we can see
    }
    return $out;
}

/**
 * The latest reading for many units at once.
 *
 * @param array<int,string> $unitIds
 * @return array<string, float> unit id => last reading (0 when never read)
 */
function lastReadingsForUnits(PDO $pdo, array $unitIds, string $meterType = 'Water'): array {
    $out = [];
    foreach ($unitIds as $id) $out[$id] = 0.0;
    if (!$unitIds) return $out;

    $slots = implode(',', array_fill(0, count($unitIds), '?'));
    try {
        // Latest row per unit: order the lot, then keep the first seen
        $stmt = $pdo->prepare("
            SELECT unit_id, current_reading
            FROM meter_readings
            WHERE unit_id IN ({$slots}) AND meter_type = ?
            ORDER BY reading_date DESC, created_at DESC
        ");
        $stmt->execute(array_merge(array_values($unitIds), [$meterType]));

        $seen = [];
        foreach ($stmt->fetchAll() as $r) {
            $uid = (string)$r['unit_id'];
            if (isset($seen[$uid])) continue;
            $seen[$uid] = true;
            $out[$uid] = (float)$r['current_reading'];
        }
    } catch (PDOException $e) {}
    return $out;
}

/**
 * Which of this run's charges each tenant already has for the period.
 *
 * @param array<int,string> $tenantIds
 * @return array<string, array<int,string>> tenant id => list of charge types
 */
function billedChargesForTenants(PDO $pdo, array $tenantIds, string $period): array {
    $out = [];
    foreach ($tenantIds as $id) $out[$id] = [];
    if (!$tenantIds) return $out;

    [$from, $until] = periodBounds($period);
    $tSlots = implode(',', array_fill(0, count($tenantIds), '?'));
    $cSlots = implode(',', array_fill(0, count(BILLING_RUN_CHARGES), '?'));

    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT tenant_id, invoice_type
            FROM invoices
            WHERE tenant_id IN ({$tSlots})
              AND invoice_type IN ({$cSlots})
              AND created_at >= ? AND created_at < ?
              AND status <> 'Cancelled'
        ");
        $stmt->execute(array_merge(
            array_values($tenantIds), BILLING_RUN_CHARGES, [$from, $until]
        ));
        foreach ($stmt->fetchAll() as $r) {
            $out[(string)$r['tenant_id']][] = (string)$r['invoice_type'];
        }
    } catch (PDOException $e) {}
    return $out;
}

/**
 * Everything the in-modal run needs for one property, in a single payload.
 */
function billingRunPayload(PDO $pdo, string $propertyId, string $period): array {
    $property = billingProperty($pdo, $propertyId);
    if (!$property) return ['ok' => false, 'error' => 'Property not found.'];

    $tenants   = billingRunTenants($pdo, $propertyId);
    $tenantIds = array_map(fn($t) => (string)$t['tenant_id'], $tenants);
    $unitIds   = array_values(array_filter(array_map(fn($t) => (string)$t['unit_id'], $tenants)));

    $arrears  = arrearsForTenants($pdo, $tenantIds);
    $readings = lastReadingsForUnits($pdo, $unitIds);
    $billed   = billedChargesForTenants($pdo, $tenantIds, $period);

    $rows = [];
    foreach ($tenants as $t) {
        $tid = (string)$t['tenant_id'];
        $uid = (string)$t['unit_id'];
        $rows[] = [
            'tenant_id'    => $tid,
            'lease_id'     => (string)$t['lease_id'],
            'unit_id'      => $uid,
            'name'         => (string)$t['full_name'],
            'unit_number'  => (string)$t['unit_number'],
            'water_meter'  => (string)($t['water_meter'] ?? ''),
            'phone'        => (string)($t['phone'] ?? ''),
            'email'        => (string)($t['email'] ?? ''),
            'monthly_rent' => (float)($t['monthly_rent'] ?? 0),
            'arrears'      => $arrears[$tid]  ?? [],
            'last_reading' => $readings[$uid] ?? 0.0,
            'already'      => $billed[$tid]   ?? [],
        ];
    }

    return [
        'ok'       => true,
        'property' => [
            'id'          => (string)$property['id'],
            'title'       => (string)$property['title'],
            'location'    => (string)$property['location'],
            'water_rate'  => (float)$property['water_rate'],
            'garbage_fee' => (float)$property['garbage_fee'],
        ],
        'tenants' => $rows,
        'period'  => $period,
    ];
}
