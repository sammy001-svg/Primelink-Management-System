<?php
/**
 * Tenant Statement of Account
 * Primelink Management System
 *
 * A statement is a ledger, not a payment list: charges raised on one side,
 * money received on the other, and a running balance down the page.
 *
 * The piece that is easy to get wrong is the opening balance. Filter a
 * statement to "this month" and the rows before that month vanish — but what
 * the tenant owed on the first of the month does not. Without a brought-forward
 * figure the running balance starts at zero and every line below it is wrong.
 * openingBalance() is what makes a filtered statement honest.
 */

require_once __DIR__ . '/settings.php';

/* ═══════════════════════════════════════════════════════════════════════
   OPENING BALANCE
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * What the tenant owed immediately before a date: everything charged minus
 * everything received, across all time up to that point.
 *
 * @param string|null $before 'Y-m-d'; null means no cut-off, so zero
 */
function openingBalance(PDO $pdo, string $tenantId, ?string $before): float {
    if (!$before) return 0.0;

    $charged = 0.0;
    $paid    = 0.0;

    try {
        $c = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM invoices
            WHERE tenant_id = ? AND status <> 'Cancelled' AND DATE(created_at) < ?
        ");
        $c->execute([$tenantId, $before]);
        $charged = (float)$c->fetchColumn();

        $p = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) FROM transactions
            WHERE tenant_id = ? AND status = 'Paid' AND DATE(transaction_date) < ?
        ");
        $p->execute([$tenantId, $before]);
        $paid = (float)$p->fetchColumn();
    } catch (PDOException $e) {
        return 0.0;
    }

    return round($charged - $paid, 2);
}

/* ═══════════════════════════════════════════════════════════════════════
   THE LEDGER
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Charges and payments for a period, in date order, each carrying the running
 * balance after it.
 *
 * @return array{
 *   opening: float, rows: array<int,array>, charged: float,
 *   paid: float, closing: float
 * }
 */
function statementLedger(PDO $pdo, string $tenantId, ?string $from = null, ?string $to = null): array {
    $to = $to ?: date('Y-m-d');
    $opening = openingBalance($pdo, $tenantId, $from);

    $rows = [];

    // ── Charges ───────────────────────────────────────────────────────
    try {
        $sql = "SELECT id, invoice_type, amount, due_date, created_at, status, description
                FROM invoices
                WHERE tenant_id = ? AND status <> 'Cancelled'";
        $params = [$tenantId];
        if ($from) { $sql .= " AND DATE(created_at) >= ?"; $params[] = $from; }
        $sql .= " AND DATE(created_at) <= ?";
        $params[] = $to;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                'kind'        => 'charge',
                'id'          => (string)$r['id'],
                'date'        => substr((string)$r['created_at'], 0, 10),
                'title'       => (string)($r['invoice_type'] ?: 'Charge'),
                'detail'      => (string)($r['description'] ?? ''),
                'reference'   => '',
                'due_date'    => (string)($r['due_date'] ?? ''),
                'status'      => (string)($r['status'] ?? ''),
                'debit'       => round((float)$r['amount'], 2),
                'credit'      => 0.0,
            ];
        }
    } catch (PDOException $e) {}

    // ── Money received ────────────────────────────────────────────────
    try {
        $sql = "SELECT id, transaction_type, amount, transaction_date, payment_method,
                       reference_number, reference_code, payment_group
                FROM transactions
                WHERE tenant_id = ? AND status = 'Paid'";
        $params = [$tenantId];
        if ($from) { $sql .= " AND DATE(transaction_date) >= ?"; $params[] = $from; }
        $sql .= " AND DATE(transaction_date) <= ?";
        $params[] = $to;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                'kind'      => 'payment',
                'id'        => (string)$r['id'],
                'date'      => substr((string)$r['transaction_date'], 0, 10),
                'title'     => (string)($r['transaction_type'] ?: 'Payment'),
                'detail'    => (string)($r['payment_method'] ?? ''),
                'reference' => (string)($r['reference_number'] ?: ($r['reference_code'] ?? '')),
                'due_date'  => '',
                'status'    => 'Paid',
                'debit'     => 0.0,
                'credit'    => round((float)$r['amount'], 2),
                'group'     => (string)($r['payment_group'] ?? ''),
            ];
        }
    } catch (PDOException $e) {}

    // Charges before payments on the same day: a payment settles what was
    // already raised, so it reads correctly against the running balance.
    usort($rows, function ($a, $b) {
        $d = strcmp($a['date'], $b['date']);
        if ($d !== 0) return $d;
        if ($a['kind'] === $b['kind']) return 0;
        return $a['kind'] === 'charge' ? -1 : 1;
    });

    $running = $opening;
    $charged = 0.0;
    $paid    = 0.0;

    foreach ($rows as $i => $r) {
        $running += $r['debit'] - $r['credit'];
        $charged += $r['debit'];
        $paid    += $r['credit'];
        $rows[$i]['balance'] = round($running, 2);
    }

    return [
        'opening' => $opening,
        'rows'    => $rows,
        'charged' => round($charged, 2),
        'paid'    => round($paid, 2),
        'closing' => round($running, 2),
    ];
}

/* ═══════════════════════════════════════════════════════════════════════
   AGEING
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Outstanding balance split by how long it has been owed — the question every
 * landlord actually asks about a debtor.
 *
 * @return array{current: float, d30: float, d60: float, d90: float, total: float}
 */
function statementAgeing(PDO $pdo, string $tenantId): array {
    $out = ['current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'total' => 0.0];

    try {
        $stmt = $pdo->prepare("
            SELECT i.due_date, i.amount,
                   COALESCE(SUM(CASE WHEN t.status = 'Paid' THEN t.amount END), 0) AS paid
            FROM invoices i
            LEFT JOIN transactions t ON t.invoice_id = i.id
            WHERE i.tenant_id = ? AND i.status NOT IN ('Paid', 'Cancelled')
            GROUP BY i.id, i.due_date, i.amount
        ");
        $stmt->execute([$tenantId]);

        $today = strtotime('today');
        foreach ($stmt->fetchAll() as $r) {
            $balance = round((float)$r['amount'] - (float)$r['paid'], 2);
            if ($balance <= 0.009) continue;

            $due  = strtotime((string)$r['due_date']) ?: $today;
            $days = (int)floor(($today - $due) / 86400);

            if ($days <= 0)       $out['current'] += $balance;
            elseif ($days <= 30)  $out['d30']     += $balance;
            elseif ($days <= 60)  $out['d60']     += $balance;
            else                  $out['d90']     += $balance;

            $out['total'] += $balance;
        }
    } catch (PDOException $e) {}

    foreach ($out as $k => $v) $out[$k] = round($v, 2);
    return $out;
}

/** Human label for an ageing bucket. */
function ageingLabel(string $key): string {
    return [
        'current' => 'Not yet due',
        'd30'     => '1–30 days',
        'd60'     => '31–60 days',
        'd90'     => 'Over 60 days',
    ][$key] ?? $key;
}

/* ═══════════════════════════════════════════════════════════════════════
   PERIODS
   ═══════════════════════════════════════════════════════════════════════ */

/** The period choices offered on a statement, as [from, to, label]. */
function statementPeriod(string $key): array {
    switch ($key) {
        case 'this_month':
            return [date('Y-m-01'), date('Y-m-d'), date('F Y')];
        case 'last_month':
            return [
                date('Y-m-01', strtotime('first day of last month')),
                date('Y-m-t',  strtotime('last day of last month')),
                date('F Y',    strtotime('last month')),
            ];
        case 'last_3m':
            return [date('Y-m-d', strtotime('-3 months')), date('Y-m-d'), 'Last 3 months'];
        case 'this_year':
            return [date('Y-01-01'), date('Y-m-d'), 'Year ' . date('Y')];
        default:
            return [null, date('Y-m-d'), 'All time'];
    }
}
