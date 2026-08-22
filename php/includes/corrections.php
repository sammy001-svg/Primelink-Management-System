<?php
/**
 * Document Corrections Engine
 * Primelink Management System
 *
 * Posted invoices and receipts are accounting documents — they are never silently
 * overwritten. Every change to a posted document creates a *revision*:
 *
 *   • the full pre-change record is snapshotted into `document_revisions`
 *   • a field-level diff (old → new) is stored alongside a mandatory reason
 *   • the document's revision_no is incremented
 *   • the document number gains a revision suffix   (INV-AB12CD34 → INV-AB12CD34-R1)
 *   • every rendered/printed copy carries a CORRECTED stamp + revision history
 *   • the tenant is issued a CORRECTED notice showing exactly what changed
 *
 * This gives a full, defensible audit trail without ever destroying history.
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/tenant_notify.php';

const DOC_INVOICE = 'invoice';
const DOC_RECEIPT = 'receipt';

/* ═══════════════════════════════════════════════════════════════════════
   SCHEMA
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Add a column only if it is missing.
 * Works on both MySQL 8 (no ADD COLUMN IF NOT EXISTS) and MariaDB.
 */
function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void {
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?"
        );
        $stmt->execute([$table, $column]);
        if ((int)$stmt->fetchColumn() > 0) return;
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    } catch (PDOException $e) {
        // Non-fatal — never break a page over a self-heal
    }
}

/**
 * Idempotently create/patch everything the corrections engine needs.
 * Cached per session so normal page loads cost nothing.
 */
function ensureCorrectionSchema(PDO $pdo, bool $force = false): void {
    static $done = false;
    if ($done && !$force) return;
    $done = true;

    $cacheKey = 'pl_corrections_schema_v1';
    if (!$force && !empty($_SESSION[$cacheKey])) return;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `document_revisions` (
                `id`              VARCHAR(36) PRIMARY KEY,
                `doc_type`        VARCHAR(20)  NOT NULL,
                `doc_id`          VARCHAR(36)  NOT NULL,
                `revision_no`     INT          NOT NULL DEFAULT 1,
                `reason`          TEXT         NULL,
                `changes_json`    LONGTEXT     NULL,
                `snapshot_json`   LONGTEXT     NULL,
                `tenant_notified` TINYINT(1)   NOT NULL DEFAULT 0,
                `notified_at`     DATETIME     NULL,
                `changed_by`      VARCHAR(36)  NULL,
                `changed_by_name` VARCHAR(255) NULL,
                `ip_address`      VARCHAR(64)  NULL,
                `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_docrev_doc` (`doc_type`, `doc_id`),
                INDEX `idx_docrev_date` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (PDOException $e) {}

    // Revision tracking on the documents themselves
    foreach (['invoices', 'transactions'] as $table) {
        ensureColumn($pdo, $table, 'revision_no',            'INT NOT NULL DEFAULT 0');
        ensureColumn($pdo, $table, 'last_corrected_at',      'DATETIME NULL');
        ensureColumn($pdo, $table, 'last_correction_reason', 'TEXT NULL');
        ensureColumn($pdo, $table, 'corrected_by',           'VARCHAR(36) NULL');
    }

    ensureColumn($pdo, 'invoices',     'description',      'TEXT NULL');
    ensureColumn($pdo, 'transactions', 'description',      'TEXT NULL');
    ensureColumn($pdo, 'transactions', 'reference_number', 'VARCHAR(255) NULL');

    $_SESSION[$cacheKey] = 1;
}

/* ═══════════════════════════════════════════════════════════════════════
   DOCUMENT NUMBERING
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Canonical document number. Revision > 0 appends the revision suffix so a
 * corrected copy can never be mistaken for the original it replaces.
 *
 *   docNumber('invoice', $id, 0) => INV-AB12CD34
 *   docNumber('invoice', $id, 2) => INV-AB12CD34-R2
 */
function docNumber(string $docType, string $docId, int $revision = 0): string {
    $base = docPrefix($docType) . '-' . strtoupper(substr((string)$docId, 0, 8));
    return $revision > 0 ? $base . '-R' . $revision : $base;
}

/** Document number prefix, honouring the configured invoice/receipt prefixes. */
function docPrefix(string $docType): string {
    static $cache = null;
    if ($cache === null) {
        $pdo   = $GLOBALS['pdo'] ?? null;
        $cache = [
            DOC_INVOICE => $pdo instanceof PDO ? getSetting($pdo, 'invoice_prefix', 'INV') : 'INV',
            DOC_RECEIPT => $pdo instanceof PDO ? getSetting($pdo, 'receipt_prefix', 'RCT') : 'RCT',
        ];
    }
    return $cache[$docType] ?? 'DOC';
}

/** The number this revision replaces (one step back). */
function supersededDocNumber(string $docType, string $docId, int $revision): string {
    return docNumber($docType, $docId, max(0, $revision - 1));
}

function docTypeLabel(string $docType): string {
    return $docType === DOC_RECEIPT ? 'Receipt' : 'Invoice';
}

/* ═══════════════════════════════════════════════════════════════════════
   DIFFING
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Build a human-readable field diff between the stored record and the new values.
 *
 * @param array $fields  field => ['label' => 'Amount', 'format' => 'money'|'date'|'text']
 * @return array  list of ['field','label','from','to']
 */
function buildDiff(array $old, array $new, array $fields, string $currency = 'KSh'): array {
    $fmt = function ($value, string $format) use ($currency): string {
        if ($value === null || $value === '') return '—';
        switch ($format) {
            case 'money': return $currency . ' ' . number_format((float)$value, 2);
            case 'date':  return ($ts = strtotime((string)$value)) ? date('d M Y', $ts) : (string)$value;
            default:      return (string)$value;
        }
    };

    $diff = [];
    foreach ($fields as $key => $meta) {
        if (!array_key_exists($key, $new)) continue;

        $format = $meta['format'] ?? 'text';
        $oldVal = $old[$key] ?? null;
        $newVal = $new[$key];

        // Compare in a type-appropriate way to avoid phantom changes
        if ($format === 'money') {
            $changed = abs((float)$oldVal - (float)$newVal) > 0.001;
        } elseif ($format === 'date') {
            $oldTs = $oldVal ? strtotime((string)$oldVal) : false;
            $newTs = $newVal ? strtotime((string)$newVal) : false;
            $changed = ($oldTs ? date('Y-m-d', $oldTs) : '') !== ($newTs ? date('Y-m-d', $newTs) : '');
        } else {
            $changed = trim((string)$oldVal) !== trim((string)$newVal);
        }
        if (!$changed) continue;

        $diff[] = [
            'field' => $key,
            'label' => $meta['label'] ?? ucfirst(str_replace('_', ' ', $key)),
            'from'  => $fmt($oldVal, $format),
            'to'    => $fmt($newVal, $format),
        ];
    }
    return $diff;
}

/** Short one-line summary of a diff, for audit log / notification text. */
function summariseDiff(array $diff): string {
    if (!$diff) return 'no field changes';
    return implode('; ', array_map(fn($c) => "{$c['label']}: {$c['from']} -> {$c['to']}", $diff));
}

/* ═══════════════════════════════════════════════════════════════════════
   REVISION RECORDING
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * Persist a revision record. Call *before* (or inside the same transaction as)
 * the UPDATE, passing the untouched pre-change row as $snapshot.
 *
 * @return string  the new revision id
 */
function recordRevision(
    PDO $pdo,
    string $docType,
    string $docId,
    int $revisionNo,
    array $snapshot,
    array $diff,
    string $reason,
    bool $tenantNotified = false
): string {
    $revId = generateUUID();

    // Never persist joined tenant PII into the snapshot — the document row only
    $snapshot = array_filter(
        $snapshot,
        fn($k) => !in_array($k, ['full_name', 'email', 'tenant_user_id', 'tenant_name', 'tenant_email'], true),
        ARRAY_FILTER_USE_KEY
    );

    try {
        $pdo->prepare("
            INSERT INTO document_revisions
                (id, doc_type, doc_id, revision_no, reason, changes_json, snapshot_json,
                 tenant_notified, notified_at, changed_by, changed_by_name, ip_address, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $revId,
            $docType,
            $docId,
            $revisionNo,
            $reason ?: null,
            json_encode($diff, JSON_UNESCAPED_UNICODE),
            json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            $tenantNotified ? 1 : 0,
            $tenantNotified ? date('Y-m-d H:i:s') : null,
            $_SESSION['user_id']   ?? null,
            $_SESSION['full_name'] ?? ($_SESSION['email'] ?? 'System'),
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (PDOException $e) {
        // Revision logging must never abort the correction itself
    }
    return $revId;
}

/** Full revision history for a document, oldest first. */
function getRevisions(PDO $pdo, string $docType, string $docId): array {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM document_revisions
            WHERE doc_type = ? AND doc_id = ?
            ORDER BY revision_no ASC, created_at ASC
        ");
        $stmt->execute([$docType, $docId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['changes'] = json_decode($r['changes_json'] ?? '[]', true) ?: [];
        }
        return $rows;
    } catch (PDOException $e) {
        return [];
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   PRESENTATION — printed / on-screen documents
   ═══════════════════════════════════════════════════════════════════════ */

/**
 * The banner that marks a document as corrected. Rendered at the very top of
 * the invoice/receipt so it is impossible to miss on screen or in print.
 */
function renderCorrectionBanner(array $doc, string $docType): string {
    $rev = (int)($doc['revision_no'] ?? 0);
    if ($rev < 1) return '';

    $docId  = (string)$doc['id'];
    $label  = strtoupper(docTypeLabel($docType));
    $thisNo = htmlspecialchars(docNumber($docType, $docId, $rev));
    $prevNo = htmlspecialchars(supersededDocNumber($docType, $docId, $rev));
    $when   = !empty($doc['last_corrected_at'])
        ? date('d M Y, H:i', strtotime((string)$doc['last_corrected_at']))
        : date('d M Y');

    $reason    = trim((string)($doc['last_correction_reason'] ?? ''));
    $reasonRow = $reason
        ? '<p style="margin:8px 0 0;font-size:11px;color:#7f1d1d;"><strong style="text-transform:uppercase;letter-spacing:.08em;font-size:9px;">Reason:</strong> '
          . htmlspecialchars($reason) . '</p>'
        : '';

    return <<<HTML
<div class="correction-banner" style="position:relative;z-index:2;border:2px solid #dc2626;background:#fef2f2;border-radius:1rem;padding:1rem 1.25rem;margin-bottom:2rem;-webkit-print-color-adjust:exact;print-color-adjust:exact;">
  <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;">
    <span style="display:inline-flex;align-items:center;gap:.35rem;background:#dc2626;color:#fff;font-size:10px;font-weight:900;letter-spacing:.14em;text-transform:uppercase;padding:.35rem .7rem;border-radius:.5rem;">
      &#9888; Corrected {$label}
    </span>
    <span style="font-size:12px;font-weight:900;color:#991b1b;letter-spacing:.05em;">Revision {$rev} &middot; {$thisNo}</span>
    <span style="margin-left:auto;font-size:10px;font-weight:700;color:#b91c1c;">Corrected on {$when}</span>
  </div>
  <p style="margin:10px 0 0;font-size:11.5px;color:#7f1d1d;line-height:1.55;">
    This revised document <strong>supersedes {$prevNo}</strong> and any earlier copy you may hold.
    Please destroy previous versions and use this one for your records and payments.
  </p>
  {$reasonRow}
</div>
HTML;
}

/**
 * Diagonal CORRECTED watermark for on-screen and printed copies.
 * Include once per corrected document page.
 */
function renderCorrectionWatermark(array $doc): string {
    if ((int)($doc['revision_no'] ?? 0) < 1) return '';
    return <<<HTML
<div aria-hidden="true" style="position:fixed;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-28deg);
     font-family:Arial,sans-serif;font-size:96px;font-weight:900;letter-spacing:.08em;color:rgba(220,38,38,0.07);
     pointer-events:none;z-index:0;white-space:nowrap;-webkit-print-color-adjust:exact;print-color-adjust:exact;">
  CORRECTED
</div>
HTML;
}

/**
 * Revision history table shown on the document.
 * Staff see who made each change; tenants see the issuer only.
 */
function renderRevisionHistory(PDO $pdo, string $docType, string $docId, bool $showActor = true): string {
    $revisions = getRevisions($pdo, $docType, $docId);
    if (!$revisions) return '';

    $rows = '';
    foreach ($revisions as $r) {
        $changes = $r['changes'] ?: [];
        $list = $changes
            ? implode('', array_map(
                fn($c) => '<div style="font-size:11px;color:#475569;margin:2px 0;">'
                        . '<strong>' . htmlspecialchars((string)$c['label']) . ':</strong> '
                        . '<span style="color:#94a3b8;text-decoration:line-through;">' . htmlspecialchars((string)$c['from']) . '</span> '
                        . '&rarr; <strong style="color:#0f172a;">' . htmlspecialchars((string)$c['to']) . '</strong></div>',
                $changes))
            : '<div style="font-size:11px;color:#94a3b8;font-style:italic;">Minor correction</div>';

        $reason = trim((string)($r['reason'] ?? ''));
        if ($reason) {
            $list .= '<div style="font-size:10.5px;color:#64748b;margin-top:4px;font-style:italic;">Reason: '
                   . htmlspecialchars($reason) . '</div>';
        }

        $actor = $showActor
            ? htmlspecialchars((string)($r['changed_by_name'] ?? 'System'))
            : 'Management';

        $rows .= '<tr style="border-bottom:1px solid #f1f5f9;">'
               . '<td style="padding:10px 8px;vertical-align:top;font-size:11px;font-weight:900;color:#dc2626;white-space:nowrap;">R'
               . (int)$r['revision_no'] . '</td>'
               . '<td style="padding:10px 8px;vertical-align:top;font-size:11px;color:#64748b;white-space:nowrap;">'
               . date('d M Y H:i', strtotime((string)$r['created_at'])) . '</td>'
               . '<td style="padding:10px 8px;vertical-align:top;">' . $list . '</td>'
               . '<td style="padding:10px 8px;vertical-align:top;font-size:11px;color:#64748b;white-space:nowrap;">' . $actor . '</td>'
               . '</tr>';
    }

    return <<<HTML
<div style="position:relative;z-index:2;margin-top:2.5rem;border:1px solid #e2e8f0;border-radius:1.25rem;padding:1.5rem;background:#fff;">
  <p style="margin:0 0 .9rem;font-size:10px;font-weight:900;letter-spacing:.14em;text-transform:uppercase;color:#94a3b8;">
    Correction History &mdash; Audit Trail
  </p>
  <div style="overflow-x:auto;">
  <table style="width:100%;border-collapse:collapse;">
    <thead>
      <tr style="border-bottom:2px solid #e2e8f0;">
        <th style="text-align:left;padding:0 8px 8px;font-size:9px;font-weight:900;letter-spacing:.1em;text-transform:uppercase;color:#94a3b8;">Rev</th>
        <th style="text-align:left;padding:0 8px 8px;font-size:9px;font-weight:900;letter-spacing:.1em;text-transform:uppercase;color:#94a3b8;">Date</th>
        <th style="text-align:left;padding:0 8px 8px;font-size:9px;font-weight:900;letter-spacing:.1em;text-transform:uppercase;color:#94a3b8;">What Changed</th>
        <th style="text-align:left;padding:0 8px 8px;font-size:9px;font-weight:900;letter-spacing:.1em;text-transform:uppercase;color:#94a3b8;">By</th>
      </tr>
    </thead>
    <tbody>{$rows}</tbody>
  </table>
  </div>
</div>
HTML;
}

/** Small inline badge for list views. */
function correctedBadge(int $revision, string $extraClass = ''): string {
    if ($revision < 1) return '';
    return '<span class="' . $extraClass . '" style="display:inline-flex;align-items:center;gap:3px;background:#fef2f2;color:#b91c1c;'
         . 'border:1px solid #fecaca;font-size:9px;font-weight:900;letter-spacing:.08em;text-transform:uppercase;'
         . 'padding:2px 6px;border-radius:5px;white-space:nowrap;" title="This document has been corrected">'
         . 'Corrected &middot; R' . $revision . '</span>';
}

/* ═══════════════════════════════════════════════════════════════════════
   TENANT NOTICE
   ═══════════════════════════════════════════════════════════════════════ */

/** Absolute URL back to the document, resilient behind proxies. */
function documentUrl(string $docType, string $docId): string {
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['PHP_SELF'] ?? ''), 2)), '/');
    $page   = $docType === DOC_RECEIPT ? 'view_receipt.php' : 'view_invoice.php';
    return "{$scheme}://{$host}{$base}/php/{$page}?id=" . urlencode($docId);
}

/**
 * Issue the tenant a CORRECTED notice across the requested channels.
 *
 * @param array $tenant   ['id','full_name','email','phone','tenant_user_id']
 * @param array $channels ['email' => bool, 'sms' => bool]
 *
 * @return array per-channel result from dispatchTenantNotice()
 */
function sendCorrectionNotice(
    PDO $pdo,
    string $docType,
    string $docId,
    int $revisionNo,
    array $tenant,          // ['id','full_name','email','phone','tenant_user_id']
    array $diff,
    string $reason,
    array $summary = [],    // ['Amount' => 'KSh 12,000.00', ...] headline values after correction
    array $channels = ['email' => true, 'sms' => false]
): array {
    $label  = docTypeLabel($docType);
    $thisNo = docNumber($docType, $docId, $revisionNo);
    $prevNo = supersededDocNumber($docType, $docId, $revisionNo);
    $name   = (string)($tenant['full_name'] ?? 'Tenant');

    // Changes table — old struck through, new bolded
    if ($diff) {
        $rows = '';
        foreach ($diff as $c) {
            $rows .= '<tr>'
                . '<td style="padding:9px 12px;border-bottom:1px solid #e2e8f0;font-size:12px;color:#64748b;font-weight:700;">'
                . htmlspecialchars((string)$c['label']) . '</td>'
                . '<td style="padding:9px 12px;border-bottom:1px solid #e2e8f0;font-size:12px;color:#94a3b8;text-decoration:line-through;">'
                . htmlspecialchars((string)$c['from']) . '</td>'
                . '<td style="padding:9px 12px;border-bottom:1px solid #e2e8f0;font-size:12px;color:#0f172a;font-weight:900;">'
                . htmlspecialchars((string)$c['to']) . '</td>'
                . '</tr>';
        }
        $changeHtml =
            '<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:10px;border-collapse:separate;overflow:hidden;margin:14px 0;">'
          . '<tr style="background:#f8fafc;">'
          . '<th style="text-align:left;padding:9px 12px;font-size:10px;font-weight:900;letter-spacing:.1em;text-transform:uppercase;color:#94a3b8;">Field</th>'
          . '<th style="text-align:left;padding:9px 12px;font-size:10px;font-weight:900;letter-spacing:.1em;text-transform:uppercase;color:#94a3b8;">Was</th>'
          . '<th style="text-align:left;padding:9px 12px;font-size:10px;font-weight:900;letter-spacing:.1em;text-transform:uppercase;color:#94a3b8;">Now</th>'
          . '</tr>' . $rows . '</table>';
    } else {
        $changeHtml = '<p style="font-size:13px;color:#475569;">A minor correction was applied to this document.</p>';
    }

    $summaryHtml = '';
    if ($summary) {
        $items = '';
        foreach ($summary as $k => $v) {
            $items .= '<tr><td style="padding:4px 0;font-size:12px;color:#64748b;">' . htmlspecialchars((string)$k)
                    . '</td><td style="padding:4px 0;font-size:12px;color:#0f172a;font-weight:900;text-align:right;">'
                    . htmlspecialchars((string)$v) . '</td></tr>';
        }
        $summaryHtml = '<div style="background:#f8fafc;border-radius:10px;padding:14px 16px;margin:14px 0;">'
                     . '<p style="margin:0 0 6px;font-size:10px;font-weight:900;letter-spacing:.1em;text-transform:uppercase;color:#94a3b8;">Corrected Details</p>'
                     . '<table width="100%" cellpadding="0" cellspacing="0">' . $items . '</table></div>';
    }

    $reasonHtml = trim($reason)
        ? '<p style="font-size:13px;color:#475569;margin-top:10px;"><strong>Reason for correction:</strong> '
          . htmlspecialchars($reason) . '</p>'
        : '';

    $body =
        '<div style="border:2px solid #dc2626;background:#fef2f2;border-radius:10px;padding:12px 16px;margin-bottom:18px;">'
      . '<p style="margin:0;font-size:11px;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:#dc2626;">&#9888; Corrected '
      . $label . ' &middot; Revision ' . $revisionNo . '</p>'
      . '<p style="margin:6px 0 0;font-size:13px;color:#7f1d1d;font-weight:700;">' . htmlspecialchars($thisNo) . '</p>'
      . '</div>'
      . '<p style="font-size:14px;color:#475569;">Dear <strong>' . htmlspecialchars($name) . '</strong>,</p>'
      . '<p style="font-size:14px;color:#475569;margin-top:8px;">Your ' . strtolower($label)
      . ' <strong>' . htmlspecialchars($prevNo) . '</strong> has been corrected and re-issued as <strong>'
      . htmlspecialchars($thisNo) . '</strong>. The changes are set out below:</p>'
      . $changeHtml
      . $summaryHtml
      . $reasonHtml
      . '<p style="font-size:12px;color:#94a3b8;margin-top:16px;border-top:1px solid #e2e8f0;padding-top:12px;">'
      . 'This corrected ' . strtolower($label) . ' replaces <strong>' . htmlspecialchars($prevNo)
      . '</strong> and any earlier copy. Please discard previous versions and use this one for your records'
      . ($docType === DOC_INVOICE ? ' and payments' : '') . '. '
      . 'If anything still looks incorrect, contact us and quote ' . htmlspecialchars($thisNo) . '.</p>';

    // SMS has to say the same thing in ~160 characters: what changed and what
    // now stands. Lead with CORRECTED so it reads right on a lock screen.
    $first    = explode(' ', trim($name))[0] ?: 'Tenant';
    $headline = '';
    foreach ($diff as $c) {
        if (in_array($c['field'], ['amount', 'due_date'], true)) {
            $headline .= ' ' . $c['label'] . ' is now ' . $c['to'] . '.';
        }
    }
    if ($headline === '' && $diff) {
        $headline = ' ' . $diff[0]['label'] . ' is now ' . $diff[0]['to'] . '.';
    }

    $smsText = "CORRECTED: Dear {$first}, your {$label} {$prevNo} has been revised to {$thisNo}."
             . $headline
             . ($reason !== '' ? ' Reason: ' . $reason . '.' : '')
             . ' This replaces the earlier copy. - ' . smsSignature($pdo);

    return dispatchTenantNotice(
        $pdo,
        [
            'id'        => $tenant['id'] ?? null,
            'full_name' => $name,
            'email'     => $tenant['email'] ?? '',
            'phone'     => $tenant['phone'] ?? '',
            'user_id'   => $tenant['tenant_user_id'] ?? ($tenant['user_id'] ?? null),
        ],
        [
            'subject'     => 'CORRECTED ' . strtoupper($label) . ' ' . $thisNo,
            'heading'     => 'Corrected ' . $label . ' — ' . $thisNo,
            'body_html'   => $body,
            'cta_text'    => 'View Corrected ' . $label,
            'cta_url'     => documentUrl($docType, $docId),
            'sms'         => $smsText,
            'inapp_title' => 'Corrected ' . $label . ' — ' . $thisNo,
            'inapp_body'  => 'Your ' . strtolower($label) . ' has been corrected (revision ' . $revisionNo . '). '
                           . ($diff ? summariseDiff($diff) . '. ' : '')
                           . ($reason ? 'Reason: ' . $reason . '. ' : '')
                           . 'This copy replaces ' . $prevNo . '.',
            'inapp_type'  => 'warning',
        ],
        $channels,
        'document_corrected'
    );
}
