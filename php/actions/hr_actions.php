<?php
/**
 * HR Action Handler — Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'staff']);
require_once __DIR__ . '/../includes/audit.php';

// ── Schema ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../includes/hr_schema.php';
ensureHrSchema($pdo);

$action   = $_POST['action'] ?? '';
$redirect = trim($_POST['_redirect'] ?? '../hr.php');

// ── Helpers ───────────────────────────────────────────────────────────────
function hrDate(string $val): ?string {
    $val = trim($val);
    return ($val && $val !== '0000-00-00') ? $val : null;
}

function ensureUploadDir(string $sub): string {
    $dir = __DIR__ . '/../../php/uploads/' . $sub;
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

function doUpload(string $fileKey, string $subDir, array $allowed = ['pdf','jpg','jpeg','png','webp']): ?string {
    if (empty($_FILES[$fileKey]['tmp_name'])) return null;
    $f    = $_FILES[$fileKey];
    $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) return null;
    $dir  = ensureUploadDir($subDir);
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    if (move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        return 'uploads/' . $subDir . '/' . $name;
    }
    return null;
}

// ── CREATE employee ───────────────────────────────────────────────────────
// Onboarding captures the whole picture in one pass — person, post, contract,
// next of kin, statutory numbers, bank, and the paperwork — so a new file is
// complete on day one instead of being filled in piecemeal afterwards.
if ($action === 'create') {
    $id = generateUUID();

    // ── Person ────────────────────────────────────────────────────────
    $fullName   = trim($_POST['full_name']        ?? '');
    $email      = strtolower(trim($_POST['email'] ?? ''));
    $phone      = trim($_POST['phone']            ?? '');
    $altPhone   = trim($_POST['alt_phone']        ?? '');
    $staffNo    = trim($_POST['staff_no']         ?? '');
    $idNumber   = trim($_POST['id_number']        ?? '');
    $dob        = hrDate($_POST['date_of_birth']  ?? '');
    $gender     = trim($_POST['gender']           ?? '');
    $marital    = trim($_POST['marital_status']   ?? '');
    $hometown   = trim($_POST['hometown']         ?? '');
    $physAddr   = trim($_POST['physical_address'] ?? '');
    $postAddr   = trim($_POST['postal_address']   ?? '');

    // ── Post ──────────────────────────────────────────────────────────
    $role         = trim($_POST['role_title']    ?? '');
    $department   = trim($_POST['department']    ?? 'Operations');
    $salary       = (float)($_POST['salary']     ?? 0);
    $status       = $_POST['status']             ?? 'Active';
    $empStatus    = $_POST['employment_status']  ?? 'Permanent';
    $hireDate     = hrDate($_POST['hire_date']   ?? '') ?? date('Y-m-d');
    $contractFrom = hrDate($_POST['contract_start_date'] ?? '') ?? $hireDate;
    $contractEnd  = hrDate($_POST['contract_end_date']   ?? '');
    $workLocation = trim($_POST['work_location'] ?? '');
    $reportsTo    = trim($_POST['reports_to']    ?? '');
    $notes        = trim($_POST['notes']         ?? '');

    if ($fullName === '') {
        header("Location: {$redirect}?error=" . urlencode('Full name is required.'));
        exit();
    }
    if (!in_array($empStatus, HR_EMPLOYMENT_STATUSES, true)) $empStatus = 'Permanent';

    // A fixed-term engagement without an end date cannot be managed or renewed
    if (isFixedTerm($empStatus) && !$contractEnd) {
        header("Location: {$redirect}?error=" . urlencode(
            "A {$empStatus} engagement needs a contract end date so it can be tracked and renewed."
        ));
        exit();
    }
    if ($contractEnd && strtotime($contractEnd) <= strtotime($contractFrom)) {
        header("Location: {$redirect}?error=" . urlencode('The contract end date must fall after the start date.'));
        exit();
    }

    // Staff numbers are how payroll and HR refer to people — they must be unique
    if ($staffNo !== '') {
        $dupe = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE staff_no = ?");
        $dupe->execute([$staffNo]);
        if ((int)$dupe->fetchColumn() > 0) {
            header("Location: {$redirect}?error=" . urlencode("Staff number {$staffNo} is already in use."));
            exit();
        }
    }
    if ($idNumber !== '') {
        $dupe = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE id_number = ?");
        $dupe->execute([$idNumber]);
        if ((int)$dupe->fetchColumn() > 0) {
            header("Location: {$redirect}?error=" . urlencode("An employee with ID number {$idNumber} already exists."));
            exit();
        }
    }

    // Uploads happen before the transaction — a failed file write should not
    // leave a half-written employee record behind.
    $idCopyPath    = doUpload('id_copy_file',    'hr');
    $agreementPath = doUpload('agreement_file',  'hr');
    $photoPath     = doUpload('photo_file',      'hr', ['jpg', 'jpeg', 'png', 'webp']);

    try {
        $pdo->beginTransaction();

        $pdo->prepare("
            INSERT INTO employees
                (id, full_name, email, phone, alt_phone, staff_no, id_number, role, department,
                 salary, status, employment_status, hire_date, contract_start_date, contract_end_date,
                 date_of_birth, gender, marital_status, hometown, physical_address, postal_address,
                 work_location, reports_to, id_copy_url, agreement_url, photo_url, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $id, $fullName, $email ?: null, $phone ?: null, $altPhone ?: null,
            $staffNo ?: null, $idNumber ?: null, $role, $department,
            $salary, $status, $empStatus, $hireDate, $contractFrom, $contractEnd,
            $dob, $gender ?: null, $marital ?: null, $hometown ?: null,
            $physAddr ?: null, $postAddr ?: null,
            $workLocation ?: null, $reportsTo ?: null,
            $idCopyPath, $agreementPath, $photoPath, $notes ?: null,
        ]);

        // ── Opening contract ──────────────────────────────────────────
        $pdo->prepare("
            INSERT INTO employee_contracts
                (id, employee_id, contract_type, job_title, start_date, end_date,
                 gross_salary, terms, file_path, status, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,'Active',?)
        ")->execute([
            generateUUID(), $id, $empStatus, $role, $contractFrom, $contractEnd,
            $salary, trim($_POST['contract_terms'] ?? '') ?: null, $agreementPath,
            $_SESSION['user_id'] ?? null,
        ]);

        // ── Next of kin ───────────────────────────────────────────────
        $kinName = trim($_POST['kin_name'] ?? '');
        if ($kinName !== '') {
            $pdo->prepare("
                INSERT INTO employee_contacts
                    (id, employee_id, name, phone, alt_phone, email, relationship, is_next_of_kin, address)
                VALUES (?,?,?,?,?,?,?,1,?)
            ")->execute([
                generateUUID(), $id, $kinName,
                trim($_POST['kin_phone']        ?? ''),
                trim($_POST['kin_alt_phone']    ?? '') ?: null,
                trim($_POST['kin_email']        ?? '') ?: null,
                trim($_POST['kin_relationship'] ?? '') ?: null,
                trim($_POST['kin_address']      ?? '') ?: null,
            ]);
        }

        // ── Emergency contact (optional second person) ────────────────
        $emgName = trim($_POST['emergency_name'] ?? '');
        if ($emgName !== '') {
            $pdo->prepare("
                INSERT INTO employee_contacts
                    (id, employee_id, name, phone, relationship, is_next_of_kin, address)
                VALUES (?,?,?,?,?,0,?)
            ")->execute([
                generateUUID(), $id, $emgName,
                trim($_POST['emergency_phone']        ?? ''),
                trim($_POST['emergency_relationship'] ?? '') ?: null,
                trim($_POST['emergency_address']      ?? '') ?: null,
            ]);
        }

        // ── Statutory numbers, so payroll can run without a second pass ──
        $kraPin = trim($_POST['kra_pin']     ?? '');
        $nssfNo = trim($_POST['nssf_number'] ?? '');
        $shifNo = trim($_POST['shif_number'] ?? '');
        if ($kraPin !== '' || $nssfNo !== '' || $shifNo !== '') {
            try {
                $pdo->prepare("
                    INSERT INTO employee_tax_profile (employee_id, kra_pin, nssf_number, shif_number)
                    VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                        kra_pin = VALUES(kra_pin),
                        nssf_number = VALUES(nssf_number),
                        shif_number = VALUES(shif_number)
                ")->execute([$id, $kraPin ?: null, $nssfNo ?: null, $shifNo ?: null]);
            } catch (PDOException $e) {
                // The payroll module owns this table; skip if it is not installed yet
            }
        }

        // ── Salary account ────────────────────────────────────────────
        $bankName = trim($_POST['bank_name']   ?? '');
        $bankAcc  = trim($_POST['bank_account_no'] ?? '');
        if ($bankName !== '' && $bankAcc !== '') {
            $pdo->prepare("
                INSERT INTO employee_bank_details
                    (id, employee_id, bank_name, branch_name, account_name, account_no, account_type, is_primary)
                VALUES (?,?,?,?,?,?,?,1)
            ")->execute([
                generateUUID(), $id, $bankName,
                trim($_POST['bank_branch']       ?? '') ?: null,
                trim($_POST['bank_account_name'] ?? '') ?: $fullName,
                $bankAcc,
                trim($_POST['bank_account_type'] ?? 'Savings'),
            ]);
        }

        // ── Paperwork on file ─────────────────────────────────────────
        $docStmt = $pdo->prepare("
            INSERT INTO employee_documents (id, employee_id, doc_type, doc_name, file_path, uploaded_by)
            VALUES (?,?,?,?,?,?)
        ");
        foreach ([
            'id_copy'        => $idCopyPath,
            'agreement'      => $agreementPath,
            'passport_photo' => $photoPath,
        ] as $docType => $path) {
            if (!$path) continue;
            $docStmt->execute([
                generateUUID(), $id, $docType,
                HR_DOC_TYPES[$docType] ?? ucfirst($docType),
                $path, $_SESSION['user_id'] ?? null,
            ]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header("Location: {$redirect}?error=" . urlencode($e->getMessage()));
        exit();
    }

    // Tell the user what still needs collecting rather than implying the file is complete
    $emp = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $emp->execute([$id]);
    $missing = employeeMissingDetails($pdo, $emp->fetch() ?: []);

    logAction($pdo, 'employee_created', 'HR', $id,
        "{$fullName} ({$empStatus})" . ($staffNo ? " — {$staffNo}" : '')
        . ($missing ? ' | Outstanding: ' . implode(', ', $missing) : ' | File complete'));

    // The employee page computes and shows what is outstanding, so no need to
    // carry it in the URL — just land the user there.
    header("Location: ../hr_employee.php?id={$id}&success=created");
    exit();
}

// ── UPDATE profile ────────────────────────────────────────────────────────
if ($action === 'update_profile') {
    $id          = $_POST['employee_id']       ?? '';
    $fullName    = trim($_POST['full_name']    ?? '');
    $email       = strtolower(trim($_POST['email'] ?? ''));
    $phone       = trim($_POST['phone']        ?? '');
    $staffNo     = trim($_POST['staff_no']     ?? '');
    $idNumber    = trim($_POST['id_number']    ?? '');
    $role        = trim($_POST['role_title']   ?? '');
    $department  = trim($_POST['department']   ?? '');
    $salary      = (float)($_POST['salary']    ?? 0);
    $status      = $_POST['status']            ?? 'Active';
    $empStatus   = $_POST['employment_status'] ?? 'Permanent';
    $contractEnd = hrDate($_POST['contract_end_date'] ?? '');
    $dob         = hrDate($_POST['date_of_birth']     ?? '');
    $gender      = trim($_POST['gender']       ?? '');
    $hometown    = trim($_POST['hometown']     ?? '');
    $physAddr    = trim($_POST['physical_address']    ?? '');
    $altPhone    = trim($_POST['alt_phone']    ?? '');
    $marital     = trim($_POST['marital_status']      ?? '');
    $postAddr    = trim($_POST['postal_address']      ?? '');
    $workLoc     = trim($_POST['work_location']       ?? '');
    $reportsTo   = trim($_POST['reports_to']          ?? '');
    $hireDate    = hrDate($_POST['hire_date']            ?? '');
    $contractFrom= hrDate($_POST['contract_start_date']  ?? '');
    $notes       = trim($_POST['notes']        ?? '');

    if (!in_array($empStatus, HR_EMPLOYMENT_STATUSES, true)) $empStatus = 'Permanent';

    if (isFixedTerm($empStatus) && !$contractEnd) {
        header("Location: {$redirect}?error=" . urlencode(
            "A {$empStatus} engagement needs a contract end date so it can be tracked and renewed."
        ));
        exit();
    }

    // Staff and ID numbers identify a person across payroll and statutory filings
    foreach ([['staff_no', $staffNo, 'Staff number'], ['id_number', $idNumber, 'ID number']] as [$col, $val, $label]) {
        if ($val === '') continue;
        $dupe = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE {$col} = ? AND id <> ?");
        $dupe->execute([$val, $id]);
        if ((int)$dupe->fetchColumn() > 0) {
            header("Location: {$redirect}?error=" . urlencode("{$label} {$val} is already used by another employee."));
            exit();
        }
    }

    $pdo->prepare("
        UPDATE employees SET
            full_name=?, email=?, phone=?, alt_phone=?, staff_no=?, id_number=?, role=?, department=?,
            salary=?, status=?, employment_status=?, hire_date=COALESCE(?, hire_date),
            contract_start_date=?, contract_end_date=?,
            date_of_birth=?, gender=?, marital_status=?, hometown=?, physical_address=?, postal_address=?,
            work_location=?, reports_to=?, notes=?
        WHERE id=?
    ")->execute([$fullName, $email, $phone, $altPhone ?: null, $staffNo, $idNumber, $role, $department,
                 $salary, $status, $empStatus, $hireDate, $contractFrom, $contractEnd,
                 $dob, $gender, $marital ?: null, $hometown, $physAddr, $postAddr ?: null,
                 $workLoc ?: null, $reportsTo ?: null, $notes ?: null, $id]);

    logAction($pdo, 'employee_updated', 'HR', $id, $fullName);
    header("Location: {$redirect}?success=profile_updated");
    exit();
}

// ── UPLOAD document ───────────────────────────────────────────────────────
if ($action === 'upload_document') {
    $empId   = $_POST['employee_id'] ?? '';
    $docType = $_POST['doc_type']    ?? 'other';
    $docName = trim($_POST['doc_name'] ?? ucfirst(str_replace('_', ' ', $docType)));

    $path = doUpload('document_file', 'hr');
    if (!$path) {
        header("Location: {$redirect}?error=Upload+failed+or+invalid+file+type");
        exit();
    }

    $pdo->prepare("
        INSERT INTO employee_documents (id, employee_id, doc_type, doc_name, file_path, uploaded_by)
        VALUES (?,?,?,?,?,?)
    ")->execute([generateUUID(), $empId, $docType, $docName, $path, $_SESSION['user_id'] ?? null]);

    logAction($pdo, 'document_uploaded', 'HR', $empId, $docName);
    header("Location: {$redirect}?success=doc_uploaded&tab=documents");
    exit();
}

// ── DELETE document ───────────────────────────────────────────────────────
if ($action === 'delete_document') {
    $docId = $_POST['doc_id'] ?? '';
    $empId = $_POST['employee_id'] ?? '';
    $row   = $pdo->prepare("SELECT file_path FROM employee_documents WHERE id=? AND employee_id=?");
    $row->execute([$docId, $empId]);
    $doc = $row->fetch();
    if ($doc) {
        $full = __DIR__ . '/../../php/' . $doc['file_path'];
        if (file_exists($full)) unlink($full);
        $pdo->prepare("DELETE FROM employee_documents WHERE id=?")->execute([$docId]);
        logAction($pdo, 'document_deleted', 'HR', $empId, $docId);
    }
    header("Location: {$redirect}?success=doc_deleted&tab=documents");
    exit();
}

// ── ADD contact / next of kin ─────────────────────────────────────────────
if ($action === 'add_contact') {
    $empId      = $_POST['employee_id']  ?? '';
    $name       = trim($_POST['name']    ?? '');
    $phone      = trim($_POST['phone']   ?? '');
    $rel        = trim($_POST['relationship'] ?? '');
    $isKin      = isset($_POST['is_next_of_kin']) ? 1 : 0;
    $address    = trim($_POST['address'] ?? '');

    $pdo->prepare("
        INSERT INTO employee_contacts (id, employee_id, name, phone, relationship, is_next_of_kin, address)
        VALUES (?,?,?,?,?,?,?)
    ")->execute([generateUUID(), $empId, $name, $phone, $rel, $isKin, $address]);

    logAction($pdo, 'contact_added', 'HR', $empId, $name);
    header("Location: {$redirect}?success=contact_added&tab=contacts");
    exit();
}

// ── DELETE contact ────────────────────────────────────────────────────────
if ($action === 'delete_contact') {
    $cId   = $_POST['contact_id']   ?? '';
    $empId = $_POST['employee_id']  ?? '';
    $pdo->prepare("DELETE FROM employee_contacts WHERE id=? AND employee_id=?")->execute([$cId, $empId]);
    header("Location: {$redirect}?success=contact_deleted&tab=contacts");
    exit();
}

// ── ADD salary review ─────────────────────────────────────────────────────
if ($action === 'add_salary_review') {
    $empId     = $_POST['employee_id']   ?? '';
    $effDate   = hrDate($_POST['effective_date'] ?? '') ?? date('Y-m-d');
    $oldSal    = (float)($_POST['old_salary']    ?? 0);
    $newSal    = (float)($_POST['new_salary']    ?? 0);
    $reason    = trim($_POST['reason']           ?? '');
    $reviewer  = trim($_POST['reviewed_by']      ?? '');

    $pdo->prepare("
        INSERT INTO employee_salary_history (id, employee_id, effective_date, old_salary, new_salary, reason, reviewed_by)
        VALUES (?,?,?,?,?,?,?)
    ")->execute([generateUUID(), $empId, $effDate, $oldSal, $newSal, $reason, $reviewer]);

    // Update current salary on employee record
    $pdo->prepare("UPDATE employees SET salary=? WHERE id=?")->execute([$newSal, $empId]);

    logAction($pdo, 'salary_review', 'HR', $empId, "New salary: {$newSal}");
    header("Location: {$redirect}?success=salary_added&tab=salary");
    exit();
}

// ── ADD warning letter ────────────────────────────────────────────────────
if ($action === 'add_warning') {
    $empId     = $_POST['employee_id']   ?? '';
    $warnDate  = hrDate($_POST['warning_date'] ?? '') ?? date('Y-m-d');
    $severity  = $_POST['severity']      ?? 'Written';
    $reason    = trim($_POST['reason']   ?? '');
    $action2   = trim($_POST['action_taken'] ?? '');
    $issuedBy  = trim($_POST['issued_by']    ?? '');

    $filePath  = doUpload('warning_file', 'hr');

    $pdo->prepare("
        INSERT INTO employee_warnings (id, employee_id, warning_date, severity, reason, action_taken, issued_by, file_path)
        VALUES (?,?,?,?,?,?,?,?)
    ")->execute([generateUUID(), $empId, $warnDate, $severity, $reason, $action2, $issuedBy, $filePath]);

    logAction($pdo, 'warning_issued', 'HR', $empId, "{$severity} warning");
    header("Location: {$redirect}?success=warning_added&tab=warnings");
    exit();
}

// ── DELETE warning ────────────────────────────────────────────────────────
if ($action === 'delete_warning') {
    $wId   = $_POST['warning_id']   ?? '';
    $empId = $_POST['employee_id']  ?? '';
    $row   = $pdo->prepare("SELECT file_path FROM employee_warnings WHERE id=? AND employee_id=?");
    $row->execute([$wId, $empId]);
    $w = $row->fetch();
    if ($w) {
        if ($w['file_path']) {
            $full = __DIR__ . '/../../php/' . $w['file_path'];
            if (file_exists($full)) unlink($full);
        }
        $pdo->prepare("DELETE FROM employee_warnings WHERE id=?")->execute([$wId]);
        logAction($pdo, 'warning_deleted', 'HR', $empId, $wId);
    }
    header("Location: {$redirect}?success=warning_deleted&tab=warnings");
    exit();
}

/* ═══════════════════════════════════════════════════════════════════════
   CONTRACT MANAGEMENT
   An engagement is a series of contract terms. Each is recorded rather than
   overwritten, so you can see what someone was engaged on and when.
   ═══════════════════════════════════════════════════════════════════════ */

// ── ADD a contract term ───────────────────────────────────────────────────
if ($action === 'add_contract' || $action === 'renew_contract') {
    $empId     = trim($_POST['employee_id']   ?? '');
    $type      = trim($_POST['contract_type'] ?? 'Contract');
    $jobTitle  = trim($_POST['job_title']     ?? '');
    $startDate = hrDate($_POST['start_date']  ?? '') ?? date('Y-m-d');
    $endDate   = hrDate($_POST['end_date']    ?? '');
    $salary    = (float)($_POST['gross_salary'] ?? 0);
    $terms     = trim($_POST['terms']         ?? '');
    $renewedFrom = trim($_POST['renewed_from'] ?? '');

    $empRow = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $empRow->execute([$empId]);
    $employee = $empRow->fetch();

    if (!$employee) {
        header("Location: {$redirect}?error=" . urlencode('Employee not found.'));
        exit();
    }
    if (!in_array($type, HR_EMPLOYMENT_STATUSES, true)) $type = 'Contract';

    if (isFixedTerm($type) && !$endDate) {
        header("Location: {$redirect}?error=" . urlencode("A {$type} term needs an end date."));
        exit();
    }
    if ($endDate && strtotime($endDate) <= strtotime($startDate)) {
        header("Location: {$redirect}?error=" . urlencode('The end date must fall after the start date.'));
        exit();
    }

    $filePath = doUpload('contract_file', 'hr');

    try {
        $pdo->beginTransaction();

        // Close whatever term is currently running — an employee is only ever
        // engaged on one contract at a time.
        $priorId = null;
        $prior = $pdo->prepare("SELECT id FROM employee_contracts WHERE employee_id = ? AND status = 'Active' ORDER BY start_date DESC LIMIT 1");
        $prior->execute([$empId]);
        $priorId = $prior->fetchColumn() ?: null;

        if ($priorId) {
            $pdo->prepare("UPDATE employee_contracts SET status = 'Renewed' WHERE id = ?")->execute([$priorId]);
        }

        $contractId = generateUUID();
        $pdo->prepare("
            INSERT INTO employee_contracts
                (id, employee_id, contract_type, job_title, start_date, end_date,
                 gross_salary, terms, file_path, status, renewed_from, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,'Active',?,?)
        ")->execute([
            $contractId, $empId, $type, $jobTitle ?: $employee['role'], $startDate, $endDate,
            $salary ?: (float)$employee['salary'], $terms ?: null, $filePath,
            $renewedFrom ?: $priorId, $_SESSION['user_id'] ?? null,
        ]);

        // The employee record always mirrors the term currently in force
        $pdo->prepare("
            UPDATE employees
               SET employment_status = ?, contract_start_date = ?, contract_end_date = ?,
                   role = COALESCE(NULLIF(?, ''), role),
                   salary = CASE WHEN ? > 0 THEN ? ELSE salary END
             WHERE id = ?
        ")->execute([$type, $startDate, $endDate, $jobTitle, $salary, $salary, $empId]);

        // A renewal at a different rate is also a pay review
        if ($salary > 0 && abs($salary - (float)$employee['salary']) > 0.001) {
            $pdo->prepare("
                INSERT INTO employee_salary_history
                    (id, employee_id, effective_date, old_salary, new_salary, reason, reviewed_by)
                VALUES (?,?,?,?,?,?,?)
            ")->execute([
                generateUUID(), $empId, $startDate, (float)$employee['salary'], $salary,
                ($priorId ? 'Contract renewal' : 'New contract') . ' — ' . $type,
                $_SESSION['full_name'] ?? ($_SESSION['email'] ?? 'System'),
            ]);
        }

        if ($filePath) {
            $pdo->prepare("
                INSERT INTO employee_documents (id, employee_id, doc_type, doc_name, file_path, expires_on, uploaded_by)
                VALUES (?,?,?,?,?,?,?)
            ")->execute([
                generateUUID(), $empId, 'agreement',
                $type . ' agreement from ' . date('d M Y', strtotime($startDate)),
                $filePath, $endDate, $_SESSION['user_id'] ?? null,
            ]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header("Location: {$redirect}?error=" . urlencode($e->getMessage()));
        exit();
    }

    logAction($pdo, $priorId ? 'contract_renewed' : 'contract_added', 'HR', $empId,
        "{$type}: " . date('d M Y', strtotime($startDate))
        . ($endDate ? ' to ' . date('d M Y', strtotime($endDate)) : ' (open-ended)')
        . ($salary > 0 ? ' @ ' . number_format($salary, 2) : ''));

    header("Location: {$redirect}?success=" . ($priorId ? 'contract_renewed' : 'contract_added') . "&tab=contracts");
    exit();
}

// ── END a contract early ──────────────────────────────────────────────────
if ($action === 'end_contract') {
    $contractId = trim($_POST['contract_id'] ?? '');
    $empId      = trim($_POST['employee_id'] ?? '');
    $reason     = trim($_POST['ended_reason'] ?? '');
    $endedOn    = hrDate($_POST['ended_on'] ?? '') ?? date('Y-m-d');
    $terminate  = !empty($_POST['terminate_employee']);

    if ($reason === '') {
        header("Location: {$redirect}?error=" . urlencode('Give a reason — it stays on the employee record.'));
        exit();
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare("
            UPDATE employee_contracts
               SET status = 'Terminated', end_date = ?, ended_reason = ?
             WHERE id = ? AND employee_id = ?
        ")->execute([$endedOn, $reason, $contractId, $empId]);

        if ($terminate) {
            $pdo->prepare("
                UPDATE employees
                   SET status = 'Terminated', contract_end_date = ?, termination_date = ?, termination_reason = ?
                 WHERE id = ?
            ")->execute([$endedOn, $endedOn, $reason, $empId]);
        } else {
            $pdo->prepare("UPDATE employees SET contract_end_date = ? WHERE id = ?")->execute([$endedOn, $empId]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        header("Location: {$redirect}?error=" . urlencode($e->getMessage()));
        exit();
    }

    logAction($pdo, 'contract_ended', 'HR', $empId,
        'Ended ' . date('d M Y', strtotime($endedOn)) . ': ' . $reason
        . ($terminate ? ' | Employee marked Terminated' : ''));

    header("Location: {$redirect}?success=contract_ended&tab=contracts");
    exit();
}

// ── DELETE a contract record (only while nothing depends on it) ───────────
if ($action === 'delete_contract') {
    requireRole(['admin']);
    $contractId = trim($_POST['contract_id'] ?? '');
    $empId      = trim($_POST['employee_id'] ?? '');

    $row = $pdo->prepare("SELECT file_path FROM employee_contracts WHERE id = ? AND employee_id = ?");
    $row->execute([$contractId, $empId]);
    $contract = $row->fetch();

    if ($contract) {
        if (!empty($contract['file_path'])) {
            $full = __DIR__ . '/../../php/' . $contract['file_path'];
            if (file_exists($full)) unlink($full);
        }
        $pdo->prepare("DELETE FROM employee_contracts WHERE id = ?")->execute([$contractId]);
        logAction($pdo, 'contract_deleted', 'HR', $empId, $contractId);
    }

    header("Location: {$redirect}?success=contract_deleted&tab=contracts");
    exit();
}

header("Location: {$redirect}");
exit();
