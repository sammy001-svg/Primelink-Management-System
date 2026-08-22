<?php
/**
 * Tenant Notification Dispatcher
 * Primelink Management System
 *
 * One call fans a notice out across every channel a tenant can be reached on:
 *
 *   in-app   always (free, and the tenant portal is the system of record)
 *   email    when requested and an address is on file
 *   SMS      when requested, SMS is switched on, and a usable number is on file
 *
 * Each channel reports back independently so the caller can tell the user
 * exactly what got through — "emailed 12, texted 9, 3 had no phone number" —
 * rather than a vague "sent".
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/sms.php';

/**
 * Fan one notice out to a single tenant.
 *
 * @param array $tenant  ['id','full_name','email','phone','user_id']
 * @param array $notice  [
 *     'subject'      => email subject,
 *     'heading'      => email heading,
 *     'body_html'    => email body HTML,
 *     'cta_text'     => optional button label,
 *     'cta_url'      => optional button URL,
 *     'sms'          => plain-text SMS body,
 *     'inapp_title'  => in-app title,
 *     'inapp_body'   => in-app message,
 *     'inapp_type'   => info|success|warning|alert  (default 'info'),
 * ]
 * @param array $channels ['email' => bool, 'sms' => bool]
 * @param string $context short tag for the SMS log, e.g. 'invoice_issued'
 *
 * @return array{email:string, sms:string, inapp:string, sms_error:?string}
 *         Each channel is one of: sent | skipped | no_address | no_phone | failed | off
 */
function dispatchTenantNotice(
    PDO $pdo,
    array $tenant,
    array $notice,
    array $channels = ['email' => true, 'sms' => false],
    string $context = ''
): array {
    $result = ['email' => 'skipped', 'sms' => 'skipped', 'inapp' => 'skipped', 'sms_error' => null];

    // ── In-app: always, when the tenant has a portal login ────────────
    if (!empty($tenant['user_id']) && !empty($notice['inapp_title'])) {
        createNotification(
            $pdo,
            (string)$tenant['user_id'],
            (string)$notice['inapp_title'],
            (string)($notice['inapp_body'] ?? ''),
            (string)($notice['inapp_type'] ?? 'info')
        );
        $result['inapp'] = 'sent';
    }

    // ── Email ─────────────────────────────────────────────────────────
    if (!empty($channels['email'])) {
        if (empty($tenant['email'])) {
            $result['email'] = 'no_address';
        } else {
            $html = buildEmailHtml(
                $pdo,
                (string)($notice['heading'] ?? $notice['subject'] ?? 'Notification'),
                (string)($notice['body_html'] ?? ''),
                (string)($notice['cta_text'] ?? ''),
                (string)($notice['cta_url']  ?? '')
            );
            $sent = sendSystemEmail(
                $pdo,
                (string)$tenant['email'],
                (string)($notice['subject'] ?? 'Notification'),
                $html,
                (string)($tenant['full_name'] ?? '')
            );
            $result['email'] = $sent ? 'sent' : 'failed';
        }
    }

    // ── SMS ───────────────────────────────────────────────────────────
    if (!empty($channels['sms'])) {
        if (!smsIsActive($pdo)) {
            $result['sms']       = 'off';
            $result['sms_error'] = smsIsConfigured($pdo)
                ? 'SMS sending is switched off in Settings.'
                : 'SMS is not configured. Add your Shanfix Bulk SMS credentials in Settings.';
        } elseif (empty($notice['sms'])) {
            $result['sms'] = 'skipped';
        } elseif (normalizePhone($tenant['phone'] ?? null) === null) {
            $result['sms'] = 'no_phone';
        } else {
            $res = sendSms(
                $pdo,
                (string)$tenant['phone'],
                (string)$notice['sms'],
                $tenant['id'] ?? null,
                $context
            );
            $result['sms']       = $res['ok'] ? 'sent' : 'failed';
            $result['sms_error'] = $res['ok'] ? null : ($res['error'] ?? 'Unknown SMS error');
        }
    }

    return $result;
}

/**
 * Roll many per-tenant results into one sentence for a flash message.
 *
 * @param array<int,array> $results output of dispatchTenantNotice(), one per tenant
 */
function summariseNotices(array $results): string {
    $tally = ['email_sent' => 0, 'email_failed' => 0, 'no_address' => 0,
              'sms_sent' => 0, 'sms_failed' => 0, 'no_phone' => 0, 'sms_off' => false];

    foreach ($results as $r) {
        match ($r['email'] ?? '') {
            'sent'       => $tally['email_sent']++,
            'failed'     => $tally['email_failed']++,
            'no_address' => $tally['no_address']++,
            default      => null,
        };
        match ($r['sms'] ?? '') {
            'sent'     => $tally['sms_sent']++,
            'failed'   => $tally['sms_failed']++,
            'no_phone' => $tally['no_phone']++,
            'off'      => $tally['sms_off'] = true,
            default    => null,
        };
    }

    $parts = [];
    if ($tally['email_sent'])   $parts[] = $tally['email_sent'] . ' emailed';
    if ($tally['sms_sent'])     $parts[] = $tally['sms_sent'] . ' texted';
    if ($tally['email_failed']) $parts[] = $tally['email_failed'] . ' email failed';
    if ($tally['sms_failed'])   $parts[] = $tally['sms_failed'] . ' SMS failed';
    if ($tally['no_address'])   $parts[] = $tally['no_address'] . ' with no email address';
    if ($tally['no_phone'])     $parts[] = $tally['no_phone'] . ' with no valid phone number';
    if ($tally['sms_off'])      $parts[] = 'SMS is switched off';

    return $parts ? implode(', ', $parts) : 'no notices sent';
}

/* ═══════════════════════════════════════════════════════════════════════
   MESSAGE BUILDERS
   ═══════════════════════════════════════════════════════════════════════ */

/** Company signature used to close every SMS. */
function smsSignature(PDO $pdo): string {
    $sender = trim(getSetting($pdo, 'sms_sender_id', ''));
    if ($sender !== '') return $sender;

    $company = trim(getSetting($pdo, 'company_name', 'Primelink'));
    // Keep the signature short — it is billed by the character
    return strlen($company) > 20 ? substr($company, 0, 20) : $company;
}

/** "Pay via Paybill 123456, Acct A12." — omitted when no shortcode is set. */
function smsPaymentLine(PDO $pdo, string $unit = ''): string {
    $shortcode = trim(getSetting($pdo, 'mpesa_shortcode', ''));
    if ($shortcode === '' || $shortcode === '—') return '';

    return ' Pay via Paybill ' . $shortcode . ($unit !== '' ? ', Acct ' . $unit : '') . '.';
}

/** Absolute URL to a page in the app, resilient behind proxies. */
function appUrl(string $page, array $query = []): string {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Works whether the caller sits in /php or /php/actions
    $self = str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? ''));
    $base = str_contains($self, '/actions/')
        ? rtrim(dirname($self, 2), '/')
        : rtrim(dirname($self), '/');

    return "{$scheme}://{$host}{$base}/{$page}" . ($query ? '?' . http_build_query($query) : '');
}

/**
 * Build the notice for a single newly issued invoice.
 *
 * @param array $invoice ['id','invoice_type','amount','due_date','description','unit_number']
 */
function buildInvoiceNotice(PDO $pdo, array $tenant, array $invoice): array {
    require_once __DIR__ . '/corrections.php';

    $currency = getSetting($pdo, 'currency_symbol', 'KSh');
    $company  = getSetting($pdo, 'company_name', 'Primelink Management System');

    $docNo   = docNumber(DOC_INVOICE, (string)$invoice['id'], 0);
    $amount  = (float)$invoice['amount'];
    $amtFmt  = $currency . ' ' . number_format($amount, 2);
    $type    = (string)$invoice['invoice_type'];
    $due     = date('d M Y', strtotime((string)$invoice['due_date']));
    $unit    = (string)($invoice['unit_number'] ?? '');
    $name    = (string)($tenant['full_name'] ?? 'Tenant');
    $first   = explode(' ', trim($name))[0] ?: 'Tenant';
    $url     = appUrl('view_invoice.php', ['id' => $invoice['id']]);

    $rows = '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:10px;border-collapse:separate;overflow:hidden;margin:14px 0;">'
          . _noticeRow('Invoice No.', $docNo)
          . _noticeRow('Charge',      $type)
          . ($unit ? _noticeRow('Unit', $unit) : '')
          . _noticeRow('Amount Due',  $amtFmt)
          . _noticeRow('Due Date',    $due)
          . '</table>';

    $desc = trim((string)($invoice['description'] ?? ''));

    return [
        'subject'   => 'New Invoice ' . $docNo . ' — ' . $amtFmt . ' due ' . $due,
        'heading'   => 'New Invoice — ' . $docNo,
        'body_html' => '<p style="font-size:14px;color:#475569;">Dear <strong>' . htmlspecialchars($name) . '</strong>,</p>'
                     . '<p style="font-size:14px;color:#475569;margin-top:8px;">A new '
                     . htmlspecialchars(strtolower($type)) . ' invoice has been issued to your account.</p>'
                     . $rows
                     . ($desc ? '<p style="font-size:13px;color:#475569;"><strong>Note:</strong> ' . htmlspecialchars($desc) . '</p>' : '')
                     . _paymentHtml($pdo, $unit)
                     . '<p style="font-size:12px;color:#94a3b8;margin-top:14px;">Please quote <strong>'
                     . htmlspecialchars($docNo) . '</strong> when making payment.</p>',
        'cta_text'  => 'View Invoice',
        'cta_url'   => $url,
        'sms'       => "Dear {$first}, your {$type} invoice {$docNo} of {$amtFmt} is due on {$due}."
                     . smsPaymentLine($pdo, $unit) . ' - ' . smsSignature($pdo),
        'inapp_title' => 'New Invoice — ' . $docNo,
        'inapp_body'  => "A {$type} invoice of {$amtFmt} has been issued, due on {$due}.",
        'inapp_type'  => 'warning',
    ];
}

/**
 * Build the notice for a bundled (combined) invoice.
 *
 * @param array $bundle ['batch_id','items'=>[['invoice_type','amount'],…],'total','due_date','description','unit_number']
 */
function buildBundleNotice(PDO $pdo, array $tenant, array $bundle): array {
    $currency = getSetting($pdo, 'currency_symbol', 'KSh');

    $total   = (float)$bundle['total'];
    $amtFmt  = $currency . ' ' . number_format($total, 2);
    $due     = date('d M Y', strtotime((string)$bundle['due_date']));
    $unit    = (string)($bundle['unit_number'] ?? '');
    $name    = (string)($tenant['full_name'] ?? 'Tenant');
    $first   = explode(' ', trim($name))[0] ?: 'Tenant';
    $items   = $bundle['items'] ?? [];
    $count   = count($items);
    $url     = appUrl('view_combined_invoice.php', ['batch_id' => $bundle['batch_id']]);

    $typeList = implode(', ', array_map(fn($i) => (string)$i['invoice_type'], $items));

    $lines = '';
    foreach ($items as $i) {
        $lines .= _noticeRow((string)$i['invoice_type'], $currency . ' ' . number_format((float)$i['amount'], 2));
    }
    $lines .= '<tr style="background:#f8fafc;">'
            . '<td style="padding:10px 12px;font-size:12px;font-weight:900;color:#0f172a;">Total Due</td>'
            . '<td style="padding:10px 12px;font-size:13px;font-weight:900;color:#0f172a;text-align:right;">' . htmlspecialchars($amtFmt) . '</td>'
            . '</tr>';

    $table = '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:10px;border-collapse:separate;overflow:hidden;margin:14px 0;">'
           . $lines . '</table>';

    $desc = trim((string)($bundle['description'] ?? ''));

    return [
        'subject'   => 'Combined Invoice — ' . $amtFmt . ' due ' . $due,
        'heading'   => 'Combined Invoice — ' . $count . ' Charge' . ($count !== 1 ? 's' : ''),
        'body_html' => '<p style="font-size:14px;color:#475569;">Dear <strong>' . htmlspecialchars($name) . '</strong>,</p>'
                     . '<p style="font-size:14px;color:#475569;margin-top:8px;">A combined invoice covering '
                     . $count . ' charge' . ($count !== 1 ? 's' : '')
                     . ($unit ? ' for Unit ' . htmlspecialchars($unit) : '') . ' has been issued to your account.</p>'
                     . $table
                     . ($desc ? '<p style="font-size:13px;color:#475569;"><strong>Note:</strong> ' . htmlspecialchars($desc) . '</p>' : '')
                     . '<p style="font-size:13px;color:#475569;">Payment is due by <strong>' . $due . '</strong>.</p>'
                     . _paymentHtml($pdo, $unit),
        'cta_text'  => 'View Combined Invoice',
        'cta_url'   => $url,
        'sms'       => "Dear {$first}, a combined invoice of {$amtFmt} ({$typeList}) is due on {$due}."
                     . smsPaymentLine($pdo, $unit) . ' - ' . smsSignature($pdo),
        'inapp_title' => 'Combined Invoice Issued',
        'inapp_body'  => "{$count} charge(s) totalling {$amtFmt} have been invoiced. Due: {$due}.",
        'inapp_type'  => 'warning',
    ];
}

/** One label/value row for the notice tables above. */
function _noticeRow(string $label, string $value): string {
    return '<tr>'
         . '<td style="padding:9px 12px;border-bottom:1px solid #e2e8f0;font-size:12px;color:#64748b;font-weight:700;">'
         . htmlspecialchars($label) . '</td>'
         . '<td style="padding:9px 12px;border-bottom:1px solid #e2e8f0;font-size:12px;color:#0f172a;font-weight:900;text-align:right;">'
         . htmlspecialchars($value) . '</td>'
         . '</tr>';
}

/** M-Pesa payment instructions block, or '' when no shortcode is configured. */
function _paymentHtml(PDO $pdo, string $unit = ''): string {
    $shortcode = trim(getSetting($pdo, 'mpesa_shortcode', ''));
    if ($shortcode === '' || $shortcode === '—') return '';

    return '<div style="background:#f8fafc;border-radius:10px;padding:14px 16px;margin:14px 0;">'
         . '<p style="margin:0 0 4px;font-size:10px;font-weight:900;letter-spacing:.1em;text-transform:uppercase;color:#94a3b8;">How to Pay</p>'
         . '<p style="margin:0;font-size:13px;color:#0f172a;font-weight:700;">M-PESA Paybill ' . htmlspecialchars($shortcode)
         . ($unit !== '' ? ' &middot; Account ' . htmlspecialchars($unit) : '') . '</p>'
         . '</div>';
}

/* ═══════════════════════════════════════════════════════════════════════
   UI — channel picker
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Render the Email / SMS channel picker used on every "issue this document"
 * form. Shows the actual destination so the user knows what will happen, and
 * explains plainly when a channel is unavailable rather than silently
 * dropping the message.
 *
 * @param array $opts [
 *     'email'         => tenant email, or '' when unknown/many,
 *     'phone'         => tenant phone, or '' when unknown/many,
 *     'recipients'    => int, how many tenants (>1 switches to plural wording),
 *     'email_checked' => bool (default true),
 *     'sms_checked'   => bool (default false),
 *     'sms_preview'   => sample SMS body, for the length/cost hint,
 *     'target_label'  => used when the recipient is only known at submit time
 *                        (e.g. a modal shared across many rows). Both channels
 *                        stay enabled and the server reports per-tenant gaps.
 * ]
 */
function renderNotifyChannels(PDO $pdo, array $opts = []): string {
    $email      = trim((string)($opts['email'] ?? ''));
    $phone      = trim((string)($opts['phone'] ?? ''));
    $recipients = max(1, (int)($opts['recipients'] ?? 1));
    $many       = $recipients > 1;
    $preview    = trim((string)($opts['sms_preview'] ?? ''));

    $emailChecked = ($opts['email_checked'] ?? true)  ? 'checked' : '';
    $smsChecked   = ($opts['sms_checked']   ?? false) ? 'checked' : '';

    $smsConfigured = smsIsConfigured($pdo);
    $smsOn         = smsIsActive($pdo);
    $phoneOk       = $phone === '' ? null : (normalizePhone($phone) !== null);
    $label         = trim((string)($opts['target_label'] ?? ''));

    // ── Email row ─────────────────────────────────────────────────────
    if ($label !== '') {
        $emailTarget = htmlspecialchars($label);
        $emailWarn   = '';
    } elseif ($many) {
        $emailTarget = $recipients . ' tenants with an email address on file';
        $emailWarn   = '';
    } elseif ($email !== '') {
        $emailTarget = htmlspecialchars($email);
        $emailWarn   = '';
    } else {
        $emailTarget = 'No email address on file';
        $emailWarn   = 'disabled';
    }

    // ── SMS row ───────────────────────────────────────────────────────
    if (!$smsConfigured) {
        $smsTarget = 'Shanfix Bulk SMS is not configured';
        $smsWarn   = 'disabled';
        $smsChecked = '';
    } elseif (!$smsOn) {
        $smsTarget = 'SMS sending is switched off in Settings';
        $smsWarn   = 'disabled';
        $smsChecked = '';
    } elseif ($label !== '') {
        $smsTarget = htmlspecialchars($label);
        $smsWarn   = '';
    } elseif ($many) {
        $smsTarget = $recipients . ' tenants with a valid phone number';
        $smsWarn   = '';
    } elseif ($phoneOk === true) {
        $smsTarget = htmlspecialchars((string)normalizePhone($phone));
        $smsWarn   = '';
    } elseif ($phone !== '') {
        $smsTarget = 'Unreadable phone number: ' . htmlspecialchars($phone);
        $smsWarn   = 'disabled';
        $smsChecked = '';
    } else {
        $smsTarget = 'No phone number on file';
        $smsWarn   = 'disabled';
        $smsChecked = '';
    }

    // Cost hint — SMS is billed per 160-character part, per recipient
    $costHint = '';
    if ($preview !== '' && $smsOn) {
        $parts = smsParts($preview);
        $units = $parts * $recipients;
        $costHint = '<p style="margin:.4rem 0 0 1.85rem;font-size:10.5px;color:#94a3b8;">'
                  . 'About <strong>' . $units . '</strong> SMS unit' . ($units !== 1 ? 's' : '')
                  . ' (' . $parts . ' part' . ($parts !== 1 ? 's' : '')
                  . ($many ? ' &times; ' . $recipients . ' recipients' : '') . ').</p>';
    }

    $settingsLink = (!$smsConfigured || !$smsOn)
        ? '<a href="settings.php#sms" style="color:#2563eb;font-weight:700;text-decoration:underline;">Set up SMS</a>'
        : '';

    $dim = 'opacity:.55;';

    return <<<HTML
<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:1rem;padding:1rem;">
  <p style="margin:0 0 .7rem;font-size:10px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#94a3b8;">
    Notify the tenant
  </p>

  <label style="display:flex;align-items:flex-start;gap:.65rem;cursor:pointer;{$emailWarn}">
    <input type="checkbox" name="notify_email" value="1" {$emailChecked} {$emailWarn}
           style="width:16px;height:16px;margin-top:2px;accent-color:#2563eb;cursor:pointer;">
    <span>
      <span style="display:block;font-size:.85rem;font-weight:700;color:#334155;">Email</span>
      <span style="display:block;font-size:11px;color:#94a3b8;">{$emailTarget}</span>
    </span>
  </label>

  <label style="display:flex;align-items:flex-start;gap:.65rem;cursor:pointer;margin-top:.75rem;{$smsWarn}">
    <input type="checkbox" name="notify_sms" value="1" {$smsChecked} {$smsWarn}
           style="width:16px;height:16px;margin-top:2px;accent-color:#16a34a;cursor:pointer;">
    <span>
      <span style="display:block;font-size:.85rem;font-weight:700;color:#334155;">SMS <span style="font-weight:600;color:#94a3b8;">via Shanfix Bulk SMS</span></span>
      <span style="display:block;font-size:11px;color:#94a3b8;">{$smsTarget} {$settingsLink}</span>
    </span>
  </label>
  {$costHint}
</div>
HTML;
}
