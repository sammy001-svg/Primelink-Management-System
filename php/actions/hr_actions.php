<?php
/**
 * HR Action Handler — Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'staff']);
require_once __DIR__ . '/../includes/audit.php';

// ── Schema self-heal ──────────────────────────────────────────────────────
$heals = [
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS staff_no VARCHAR(50) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS id_number VARCHAR(50) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS date_of_birth DATE NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS gender VARCHAR(20) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS hometown VARCHAR(150) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS physical_address TEXT NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS phone VARCHAR(30) NULL",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS employment_status VARCHAR(30) NOT NULL DEFAULT 'Permanent'",
    "ALTER TABLE employees ADD COLUMN IF NOT EXISTS contract_end_date DATE NULL",
    "CREATE TABLE IF NOT EXISTS employee_documents (
        id VARCHAR(36) PRIMARY KEY,
        employee_id VARCHAR(36) NOT NULL,
        doc_type VARCHAR(50) NOT NULL,
        doc_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        uploaded_by VARCHAR(36) NULL,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS employee_contacts (
        id VARCHAR(36) PRIMARY KEY,
        employee_id VARCHAR(36) NOT NULL,
        name VARCHAR(255) NOT NULL,
        phone VARCHAR(50) NOT NULL,
        relationship VARCHAR(100) NULL,
        is_next_of_kin TINYINT(1) DEFAULT 0,
        address TEXT NULL,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS employee_salary_history (
        id VARCHAR(36) PRIMARY KEY,
        employee_id VARCHAR(36) NOT NULL,
        effective_date DATE NOT NULL,
        old_salary DECIMAL(15,2) NOT NULL,
        new_salary DECIMAL(15,2) NOT NULL,
        reason TEXT NULL,
        reviewed_by VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS employee_warnings (
        id VARCHAR(36) PRIMARY KEY,
        employee_id VARCHAR(36) NOT NULL,
        warning_date DATE NOT NULL,
        severity VARCHAR(20) NOT NULL DEFAULT 'Written',
        reason TEXT NOT NULL,
        action_taken TEXT NULL,
        issued_by VARCHAR(255) NULL,
        file_path VARCHAR(500) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];
foreach ($heals as $sql) {
    try { $pdo->exec($sql); } catch (PDOException $e) {}
}

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
if ($action === 'create') {
    $id               = generateUUID();
    $fullName         = trim($_POST['full_name']         ?? '');
    $email            = strtolower(trim($_POST['email']  ?? ''));
    $phone            = trim($_POST['phone']             ?? '');
    $staffNo          = trim($_POST['staff_no']          ?? '');
    $idNumber         = trim($_POST['id_number']         ?? '');
    $role             = trim($_POST['role_title']        ?? '');
    $department       = trim($_POST['department']        ?? 'Operations');
    $salary           = (float)($_POST['salary']         ?? 0);
    $status           = $_POST['status']                 ?? 'Active';
    $empStatus        = $_POST['employment_status']      ?? 'Permanent';
    $contractEnd      = hrDate($_POST['contract_end_date'] ?? '');
    $dob              = hrDate($_POST['date_of_birth']   ?? '');
    $gender           = trim($_POST['gender']            ?? '');
    $hometown         = trim($_POST['hometown']          ?? '');
    $physAddr         = trim($_POST['physical_address']  ?? '');

    try {
        $pdo->prepare("
            INSERT INTO employees
                (id, full_name, email, phone, staff_no, id_number, role, department,
                 salary, status, employment_status, contract_end_date,
                 date_of_birth, gender, hometown, physical_address, hire_date)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ")->execute([$id, $fullName, $email, $phone, $staffNo, $idNumber, $role, $department,
                     $salary, $status, $empStatus, $contractEnd, $dob, $gender, $hometown, $physAddr]);

        logAction($pdo, 'employee_created', 'HR', $id, $fullName);
        header("Location: {$redirect}?success=created");
    } catch (PDOException $e) {
        header("Location: {$redirect}?error=" . urlencode($e->getMessage()));
    }
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

    $pdo->prepare("
        UPDATE employees SET
            full_name=?, email=?, phone=?, staff_no=?, id_number=?, role=?, department=?,
            salary=?, status=?, employment_status=?, contract_end_date=?,
            date_of_birth=?, gender=?, hometown=?, physical_address=?
        WHERE id=?
    ")->execute([$fullName, $email, $phone, $staffNo, $idNumber, $role, $department,
                 $salary, $status, $empStatus, $contractEnd, $dob, $gender, $hometown, $physAddr, $id]);

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

header("Location: {$redirect}");
exit();
