<?php
/**
 * Lease Expiry Reminder Engine
 * Primelink Management System — Phase 8
 *
 * Sends in-app + email reminders at 60 / 30 / 14 days before lease expiry.
 * Also auto-expires leases whose end_date has passed.
 * Called once per dashboard load; duplicate sends are prevented by lease_reminder_log.
 */

require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/settings.php';

function runLeaseReminders(PDO $pdo): int {

    // ── Self-healing schema ───────────────────────────────────────────
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `lease_reminder_log` (
            `id`          VARCHAR(36) NOT NULL PRIMARY KEY,
            `lease_id`    VARCHAR(36) NOT NULL,
            `days_before` INT NOT NULL,
            `sent_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uq_reminder` (`lease_id`, `days_before`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) { /* table exists */ }

    // Add renewal_status column to leases
    try {
        $pdo->exec("ALTER TABLE `leases`
            ADD COLUMN `renewal_status` ENUM('Offered','Accepted','Declined') NULL AFTER `status`");
    } catch (PDOException $e) { /* column exists */ }

    // Add parent_lease_id to track renewal chains
    try {
        $pdo->exec("ALTER TABLE `leases`
            ADD COLUMN `parent_lease_id` VARCHAR(36) NULL AFTER `renewal_status`");
    } catch (PDOException $e) { /* column exists */ }

    // ── Auto-expire leases whose end_date has passed ──────────────────
    $pdo->exec("
        UPDATE leases SET status = 'Expired'
        WHERE status = 'Active' AND end_date < CURDATE()
    ");

    $currency  = getSetting($pdo, 'currency_symbol', 'KSh');
    $thresholds = [60, 30, 14];
    $sent = 0;

    foreach ($thresholds as $days) {
        $stmt = $pdo->prepare("
            SELECT l.id AS lease_id, l.end_date, l.tenant_id, l.monthly_rent,
                   t.full_name AS tenant_name, t.email AS tenant_email,
                   u2.id AS tenant_user_id,
                   p.title AS property_title, p.location AS property_location,
                   un.unit_number
            FROM leases l
            JOIN tenants  t  ON l.tenant_id    = t.id
            JOIN units    un ON l.unit_id       = un.id
            JOIN properties p ON un.property_id = p.id
            JOIN users    u2 ON t.user_id       = u2.id
            WHERE l.status = 'Active'
              AND DATEDIFF(l.end_date, CURDATE()) BETWEEN ? AND ?
              AND NOT EXISTS (
                  SELECT 1 FROM lease_reminder_log r
                  WHERE r.lease_id = l.id AND r.days_before = ?
              )
        ");
        // Match within the day window so daily cron (or dashboard hit) triggers once
        $stmt->execute([$days - 1, $days, $days]);
        $leases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($leases as $lease) {
            $expiry   = date('M d, Y', strtotime($lease['end_date']));
            $type     = $days <= 14 ? 'warning' : 'info';
            $title    = "Lease Expiring in {$days} Days";
            $tenantMsg = "Your lease for {$lease['property_title']} (Unit {$lease['unit_number']}) expires on {$expiry}. Please contact us to arrange renewal.";
            $staffMsg  = "{$lease['tenant_name']}'s lease — {$lease['property_title']} Unit {$lease['unit_number']} — expires in {$days} days ({$expiry}).";

            // In-app: tenant
            createNotification($pdo, $lease['tenant_user_id'], $title, $tenantMsg, $type);

            // In-app: all staff
            notifyAllStaff($pdo, $title . ': ' . $lease['tenant_name'], $staffMsg, $type);

            // Email to tenant
            $html = buildEmailHtml(
                $pdo,
                $title,
                "<p style='margin:0 0 16px;color:#334155;font-size:15px;line-height:1.6'>Dear <strong>{$lease['tenant_name']}</strong>,</p>
                 <p style='margin:0 0 16px;color:#334155;font-size:15px;line-height:1.6'>This is a courtesy reminder that your tenancy agreement will expire in <strong>{$days} days</strong>.</p>
                 <table style='width:100%;border-collapse:collapse;margin:20px 0;border-radius:10px;overflow:hidden'>
                   <tr style='background:#f8fafc'><td style='padding:12px 16px;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:1px'>Property</td><td style='padding:12px 16px;font-size:14px;font-weight:700;color:#0f172a'>{$lease['property_title']}</td></tr>
                   <tr><td style='padding:12px 16px;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:1px'>Unit</td><td style='padding:12px 16px;font-size:14px;font-weight:700;color:#0f172a'>{$lease['unit_number']}</td></tr>
                   <tr style='background:#f8fafc'><td style='padding:12px 16px;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:1px'>Expiry Date</td><td style='padding:12px 16px;font-size:14px;font-weight:900;color:#ef4444'>{$expiry}</td></tr>
                   <tr><td style='padding:12px 16px;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:1px'>Monthly Rent</td><td style='padding:12px 16px;font-size:14px;font-weight:700;color:#0f172a'>{$currency} " . number_format($lease['monthly_rent']) . "</td></tr>
                 </table>
                 <p style='margin:0 0 8px;color:#334155;font-size:14px;line-height:1.6'>To ensure uninterrupted tenancy, please contact our management office at your earliest convenience to discuss renewal terms.</p>",
                'View My Lease',
                '../leases.php'
            );
            sendSystemEmail($pdo, $lease['tenant_email'], $title . ' — ' . $lease['property_title'], $html, $lease['tenant_name']);

            // Log so we never send this threshold again for this lease
            try {
                $pdo->prepare("INSERT IGNORE INTO lease_reminder_log (id, lease_id, days_before) VALUES (?, ?, ?)")
                    ->execute([generateUUID(), $lease['lease_id'], $days]);
            } catch (PDOException $e) { /* ignore */ }

            $sent++;
        }
    }

    return $sent;
}
