<?php
/**
 * Payment Allocation
 * Primelink Management System
 *
 * A tenant hands over one sum, but that sum settles several different charges —
 * rent, water, garbage, service charge. Recording it as a single lump loses the
 * breakdown, so the books can't say how much of the month's collection was rent
 * and how much was utilities.
 *
 * A payment is therefore recorded as one posting per charge, all sharing a
 * `payment_group` id:
 *
 *   • each line keeps its own transaction_type, so every existing report that
 *     groups by charge type stays correct with no changes
 *   • each line can carry its own invoice_id, so the right invoice gets settled
 *   • the shared group id lets a receipt present them as one document
 *
 * The alternative — one transaction plus a separate allocations table — would
 * have made every per-type revenue figure in the system wrong for any mixed
 * payment, so it was not worth the tidier-looking receipt.
 */

require_once __DIR__ . '/settings.php';

/** Charges a tenant payment can be allocated against. */
const PAYMENT_CHARGE_TYPES = [
    'Rent',
    'Water',
    'Garbage',
    'Electricity',
    'Service Charge',
    'Deposit',
    'Penalty',
    'Other',
];

/**
 * Add the grouping column. Idempotent, cached per session.
 */
function ensurePaymentAllocSchema(PDO $pdo, bool $force = false): void {
    static $done = false;
    if ($done && !$force) return;
    $done = true;

    $cacheKey = 'pl_payment_alloc_schema_v1';
    if (!$force && !empty($_SESSION[$cacheKey])) return;

    if (function_exists('ensureColumn')) {
        ensureColumn($pdo, 'transactions', 'payment_group', 'VARCHAR(36) NULL');
    } else {
        try { $pdo->exec("ALTER TABLE `transactions` ADD COLUMN `payment_group` VARCHAR(36) NULL"); }
        catch (PDOException $e) {}
    }
    try { $pdo->exec("CREATE INDEX `idx_tx_paygroup` ON `transactions` (`payment_group`)"); }
    catch (PDOException $e) {}

    $_SESSION[$cacheKey] = 1;
}

/* ═══════════════════════════════════════════════════════════════════════
   WHAT A TENANT CURRENTLY OWES
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Unsettled invoices for every tenant, oldest first, keyed by tenant id.
 * This is what pre-fills the allocation rows, so staff see exactly what the
 * money is meant to cover instead of typing it from memory.
 *
 * @return array<string, array<int, array>> tenant id => list of outstanding lines
 */
function outstandingByTenant(PDO $pdo, ?string $tenantId = null): array {
    $sql = "
        SELECT i.id, i.tenant_id, i.invoice_type, i.amount, i.due_date,
               COALESCE(SUM(CASE WHEN t.status = 'Paid' THEN t.amount END), 0) AS paid
        FROM invoices i
        LEFT JOIN transactions t ON t.invoice_id = i.id
        WHERE i.status NOT IN ('Paid', 'Cancelled')
    ";
    $params = [];
    if ($tenantId !== null) {
        $sql .= " AND i.tenant_id = ?";
        $params[] = $tenantId;
    }
    $sql .= " GROUP BY i.id, i.tenant_id, i.invoice_type, i.amount, i.due_date
              ORDER BY i.due_date ASC";

    $out = [];
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) {
            $balance = round((float)$r['amount'] - (float)$r['paid'], 2);
            if ($balance <= 0.009) continue;          // fully settled already

            $out[$r['tenant_id']][] = [
                'invoice_id' => (string)$r['id'],
                'type'       => (string)($r['invoice_type'] ?: 'Other'),
                'balance'    => $balance,
                'due_date'   => (string)$r['due_date'],
                'overdue'    => strtotime((string)$r['due_date']) < strtotime('today'),
            ];
        }
    } catch (PDOException $e) {
        // No invoices table yet, or a schema gap — the form still works unfilled
    }
    return $out;
}

/* ═══════════════════════════════════════════════════════════════════════
   READING A RECORDED PAYMENT BACK
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Every posting that made up one payment, for the receipt.
 * Returns [] when the payment was a single undivided line.
 */
function paymentGroupLines(PDO $pdo, ?string $groupId): array {
    if (!$groupId) return [];
    try {
        $stmt = $pdo->prepare("
            SELECT id, transaction_type, amount, invoice_id
            FROM transactions
            WHERE payment_group = ?
            ORDER BY created_at ASC, id ASC
        ");
        $stmt->execute([$groupId]);
        $rows = $stmt->fetchAll();
        return count($rows) > 1 ? $rows : [];   // one line is not a breakdown
    } catch (PDOException $e) {
        return [];
    }
}

/** Total of a grouped payment. */
function paymentGroupTotal(array $lines): float {
    $total = 0.0;
    foreach ($lines as $l) $total += (float)$l['amount'];
    return round($total, 2);
}

/* ═══════════════════════════════════════════════════════════════════════
   RECORDING
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Read allocation rows off a submitted form.
 * Blank and zero rows are dropped; anything negative is rejected outright.
 *
 * @return array{lines: array<int,array>, total: float, error: ?string}
 */
function parseAllocationInput(array $post): array {
    $types    = $post['alloc_type']    ?? [];
    $amounts  = $post['alloc_amount']  ?? [];
    $invoices = $post['alloc_invoice'] ?? [];

    if (!is_array($types) || !is_array($amounts)) {
        return ['lines' => [], 'total' => 0.0, 'error' => null];
    }

    $lines = [];
    $total = 0.0;

    foreach ($types as $i => $type) {
        $raw = $amounts[$i] ?? '';
        if (trim((string)$raw) === '') continue;

        $amount = round((float)$raw, 2);
        if ($amount < 0) {
            return ['lines' => [], 'total' => 0.0,
                    'error' => 'A payment line cannot be negative.'];
        }
        if ($amount === 0.0) continue;

        $type = trim((string)$type);
        if (!in_array($type, PAYMENT_CHARGE_TYPES, true)) $type = 'Other';

        $lines[] = [
            'type'       => $type,
            'amount'     => $amount,
            'invoice_id' => trim((string)($invoices[$i] ?? '')) ?: null,
        ];
        $total += $amount;
    }

    return ['lines' => $lines, 'total' => round($total, 2), 'error' => null];
}

/**
 * Recalculate an invoice's status from what has actually been received.
 * Called after allocating money against it.
 */
function resettleInvoice(PDO $pdo, ?string $invoiceId): void {
    if (!$invoiceId) return;
    try {
        $paid = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE invoice_id = ? AND status = 'Paid'");
        $paid->execute([$invoiceId]);
        $totalPaid = (float)$paid->fetchColumn();

        $inv = $pdo->prepare("SELECT amount, due_date, status FROM invoices WHERE id = ?");
        $inv->execute([$invoiceId]);
        $row = $inv->fetch();
        if (!$row || (float)$row['amount'] <= 0 || $row['status'] === 'Cancelled') return;

        $amount  = (float)$row['amount'];
        $overdue = strtotime((string)$row['due_date']) < strtotime('today');

        if ($totalPaid >= $amount - 0.009) {
            $status = 'Paid';
        } elseif ($totalPaid > 0) {
            $status = $overdue ? 'Overdue' : 'Partial';
        } else {
            $status = $overdue ? 'Overdue' : 'Unpaid';
        }

        $pdo->prepare("UPDATE invoices SET status = ? WHERE id = ?")->execute([$status, $invoiceId]);
    } catch (PDOException $e) {
        // Non-fatal: the payment is recorded either way
    }
}

/** One-line summary of an allocation, for audit logs and notifications. */
function summariseAllocation(array $lines, string $currency = 'KSh'): string {
    if (!$lines) return '';
    $parts = [];
    foreach ($lines as $l) {
        $parts[] = $l['type'] . ' ' . $currency . ' ' . number_format((float)$l['amount'], 2);
    }
    return implode(' · ', $parts);
}

/* ═══════════════════════════════════════════════════════════════════════
   RECEIPTS
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * A tenant's payments as receipts, newest first.
 *
 * Postings that share a payment_group are one receipt with several lines, so a
 * tenant who paid rent, water and garbage together sees one document rather
 * than three. Ungrouped postings stand alone.
 *
 * @return array<int, array> each: id, date, method, reference, total,
 *                           lines[], group_id, bank_account_id, status
 */
function tenantReceipts(PDO $pdo, string $tenantId, int $limit = 200): array {
    try {
        $stmt = $pdo->prepare("
            SELECT id, amount, transaction_type, status, payment_method,
                   reference_number, reference_code, description,
                   transaction_date, payment_group, bank_account_id,
                   revision_no, created_at
            FROM transactions
            WHERE tenant_id = ?
            ORDER BY transaction_date DESC, created_at DESC
            LIMIT {$limit}
        ");
        $stmt->execute([$tenantId]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }

    $receipts = [];
    $byGroup  = [];

    foreach ($rows as $r) {
        $line = [
            'type'   => (string)($r['transaction_type'] ?? 'Payment'),
            'amount' => (float)$r['amount'],
        ];

        $group = trim((string)($r['payment_group'] ?? ''));

        if ($group !== '' && isset($byGroup[$group])) {
            // Another line on a receipt we have already started
            $idx = $byGroup[$group];
            $receipts[$idx]['lines'][] = $line;
            $receipts[$idx]['total']   = round($receipts[$idx]['total'] + $line['amount'], 2);
            continue;
        }

        $receipt = [
            'id'              => (string)$r['id'],
            'group_id'        => $group ?: null,
            'date'            => (string)($r['transaction_date'] ?: $r['created_at']),
            'method'          => (string)($r['payment_method'] ?? ''),
            'reference'       => (string)($r['reference_number'] ?: ($r['reference_code'] ?? '')),
            'description'     => (string)($r['description'] ?? ''),
            'status'          => (string)($r['status'] ?? ''),
            'bank_account_id' => (string)($r['bank_account_id'] ?? ''),
            'revision_no'     => (int)($r['revision_no'] ?? 0),
            'total'           => round((float)$r['amount'], 2),
            'lines'           => [$line],
        ];

        $receipts[] = $receipt;
        if ($group !== '') $byGroup[$group] = count($receipts) - 1;
    }

    return $receipts;
}

/**
 * What a tenant still owes across every unsettled invoice.
 * Printed on a receipt so the tenant leaves knowing where they stand.
 */
function tenantOutstandingTotal(PDO $pdo, ?string $tenantId): float {
    if (!$tenantId) return 0.0;
    $total = 0.0;
    foreach (outstandingByTenant($pdo, $tenantId)[$tenantId] ?? [] as $line) {
        $total += (float)$line['balance'];
    }
    return round($total, 2);
}

/** Total actually received from a tenant (confirmed payments only). */
function tenantPaidTotal(PDO $pdo, string $tenantId): float {
    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE tenant_id = ? AND status = 'Paid'");
        $stmt->execute([$tenantId]);
        return round((float)$stmt->fetchColumn(), 2);
    } catch (PDOException $e) {
        return 0.0;
    }
}
