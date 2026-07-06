<?php
/**
 * Landlord Actions Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'staff']);

require_once __DIR__ . '/../includes/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../landlords.php');
    exit();
}

// Schema self-heal
foreach ([
    "ALTER TABLE landlords ADD COLUMN IF NOT EXISTS id_number        VARCHAR(100) NULL",
    "ALTER TABLE landlords ADD COLUMN IF NOT EXISTS address          TEXT NULL",
    "ALTER TABLE landlords ADD COLUMN IF NOT EXISTS fee_type         VARCHAR(20)  NOT NULL DEFAULT 'percentage'",
    "ALTER TABLE landlords ADD COLUMN IF NOT EXISTS nok_name         VARCHAR(255) NULL",
    "ALTER TABLE landlords ADD COLUMN IF NOT EXISTS nok_phone        VARCHAR(50)  NULL",
    "ALTER TABLE landlords ADD COLUMN IF NOT EXISTS nok_relationship VARCHAR(100) NULL",
    "ALTER TABLE landlords ADD COLUMN IF NOT EXISTS status           VARCHAR(20)  NOT NULL DEFAULT 'active'",
] as $ddl) {
    try { $pdo->exec($ddl); } catch (PDOException $e) {}
}

$action = $_POST['action'] ?? '';

switch ($action) {

    // ─── CREATE ──────────────────────────────────────────────────────────────
    case 'create':
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email']     ?? '');
        $phone    = trim($_POST['phone']     ?? '');
        $password = $_POST['password']        ?? '';
        $idNumber = trim($_POST['id_number'] ?? '');
        $address  = trim($_POST['address']   ?? '');
        $feeType  = in_array($_POST['fee_type'] ?? '', ['percentage', 'fixed'])
                        ? $_POST['fee_type'] : 'percentage';
        $mgmtFee  = max(0, (float)($_POST['management_fee'] ?? 10));
        if ($feeType === 'percentage') $mgmtFee = min(100, $mgmtFee);
        $nokName  = trim($_POST['nok_name']         ?? '');
        $nokPhone = trim($_POST['nok_phone']        ?? '');
        $nokRel   = trim($_POST['nok_relationship'] ?? '');

        if (!$fullName || !$email || !$password) {
            header('Location: ../landlords.php?error=' . urlencode('Name, email and password are required.'));
            exit();
        }

        try {
            $userId = generateUUID();
            $hash   = password_hash($password, PASSWORD_BCRYPT);

            $pdo->prepare("INSERT INTO users (id, email, password, role) VALUES (?, ?, ?, 'landlord')")
                ->execute([$userId, $email, $hash]);

            $pdo->prepare("INSERT INTO profiles (id, full_name, email, phone, role) VALUES (?, ?, ?, ?, 'landlord')")
                ->execute([$userId, $fullName, $email, $phone]);

            $landlordId = generateUUID();
            $pdo->prepare("
                INSERT INTO landlords
                    (id, full_name, email, phone, user_id, management_fee, fee_type,
                     id_number, address, nok_name, nok_phone, nok_relationship, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
            ")->execute([
                $landlordId, $fullName, $email, $phone, $userId, $mgmtFee, $feeType,
                $idNumber ?: null, $address  ?: null,
                $nokName  ?: null, $nokPhone ?: null, $nokRel ?: null,
            ]);

            logAction($pdo, 'landlord_created', 'Landlords', $landlordId, "New landlord: {$fullName}");
            header('Location: ../landlords.php?success=' . urlencode("Landlord account created for {$fullName}."));
        } catch (PDOException $e) {
            $msg = $e->getCode() == 23000 ? 'Email address is already registered.' : $e->getMessage();
            header('Location: ../landlords.php?error=' . urlencode($msg));
        }
        exit();

    // ─── EDIT ────────────────────────────────────────────────────────────────
    case 'edit':
        $landlordId = trim($_POST['landlord_id'] ?? '');
        $fullName   = trim($_POST['full_name']   ?? '');
        $email      = trim($_POST['email']       ?? '');
        $phone      = trim($_POST['phone']       ?? '');
        $idNumber   = trim($_POST['id_number']   ?? '');
        $address    = trim($_POST['address']     ?? '');
        $feeType    = in_array($_POST['fee_type'] ?? '', ['percentage', 'fixed'])
                          ? $_POST['fee_type'] : 'percentage';
        $mgmtFee    = max(0, (float)($_POST['management_fee'] ?? 10));
        if ($feeType === 'percentage') $mgmtFee = min(100, $mgmtFee);
        $nokName    = trim($_POST['nok_name']         ?? '');
        $nokPhone   = trim($_POST['nok_phone']        ?? '');
        $nokRel     = trim($_POST['nok_relationship'] ?? '');
        $status     = in_array($_POST['status'] ?? '', ['active', 'inactive'])
                          ? $_POST['status'] : 'active';
        $editRedir  = trim($_POST['_redirect'] ?? '') ?: '../landlords.php';

        if (!$landlordId || !$fullName) {
            header('Location: ' . $editRedir . '?error=' . urlencode('Invalid request.'));
            exit();
        }

        try {
            $stmt = $pdo->prepare("SELECT user_id FROM landlords WHERE id = ?");
            $stmt->execute([$landlordId]);
            $userId = $stmt->fetchColumn();

            $pdo->prepare("
                UPDATE landlords
                SET full_name=?, email=?, phone=?, management_fee=?, fee_type=?,
                    id_number=?, address=?, nok_name=?, nok_phone=?, nok_relationship=?, status=?
                WHERE id=?
            ")->execute([
                $fullName, $email, $phone, $mgmtFee, $feeType,
                $idNumber ?: null, $address  ?: null,
                $nokName  ?: null, $nokPhone ?: null, $nokRel ?: null,
                $status, $landlordId,
            ]);

            if ($userId) {
                $pdo->prepare("UPDATE users    SET email=?                          WHERE id=?")->execute([$email, $userId]);
                $pdo->prepare("UPDATE profiles SET full_name=?, email=?, phone=?    WHERE id=?")->execute([$fullName, $email, $phone, $userId]);
            }

            logAction($pdo, 'landlord_updated', 'Landlords', $landlordId, "Updated profile: {$fullName}");
            // If _redirect already has query params don't double-add success
            $sep = strpos($editRedir, '?') !== false ? '&' : '?';
            header('Location: ' . $editRedir . $sep . 'success=' . urlencode('Landlord profile updated successfully.'));
        } catch (PDOException $e) {
            header('Location: ' . $editRedir . '?error=' . urlencode($e->getMessage()));
        }
        exit();

    // ─── DELETE ──────────────────────────────────────────────────────────────
    case 'delete':
        $landlordId = trim($_POST['landlord_id'] ?? '');
        if (!$landlordId) {
            header('Location: ../landlords.php?error=invalid_landlord');
            exit();
        }

        try {
            $stmt = $pdo->prepare("SELECT full_name, user_id FROM landlords WHERE id = ?");
            $stmt->execute([$landlordId]);
            $ll = $stmt->fetch();
            if (!$ll) {
                header('Location: ../landlords.php?error=' . urlencode('Landlord not found.'));
                exit();
            }

            // Block delete if active leases exist on this landlord's properties
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM leases ls
                JOIN units u ON ls.unit_id = u.id
                JOIN properties p ON u.property_id = p.id
                WHERE p.landlord_id = ? AND ls.status = 'Active'
            ");
            $stmt->execute([$landlordId]);
            if ((int)$stmt->fetchColumn() > 0) {
                header('Location: ../landlords.php?error=' . urlencode('Cannot delete: landlord has active tenants. Reassign or terminate leases first.'));
                exit();
            }

            $pdo->prepare("UPDATE properties SET landlord_id = NULL WHERE landlord_id = ?")->execute([$landlordId]);
            $pdo->prepare("DELETE FROM landlords WHERE id = ?")->execute([$landlordId]);
            if ($ll['user_id']) {
                $pdo->prepare("DELETE FROM profiles WHERE id = ?")->execute([$ll['user_id']]);
                $pdo->prepare("DELETE FROM users    WHERE id = ?")->execute([$ll['user_id']]);
            }

            logAction($pdo, 'landlord_deleted', 'Landlords', $landlordId, "Deleted: {$ll['full_name']}");
            header('Location: ../landlords.php?success=' . urlencode("Landlord {$ll['full_name']} deleted."));
        } catch (PDOException $e) {
            header('Location: ../landlords.php?error=' . urlencode($e->getMessage()));
        }
        exit();

    // ─── ASSIGN PROPERTIES ───────────────────────────────────────────────────
    case 'assign_properties':
        $landlordId  = $_POST['landlord_id'] ?? '';
        $propertyIds = $_POST['property_ids'] ?? [];

        if (!$landlordId) {
            header('Location: ../landlords.php?error=invalid_landlord');
            exit();
        }

        try {
            $pdo->prepare("UPDATE properties SET landlord_id = NULL WHERE landlord_id = ?")
                ->execute([$landlordId]);

            if (!empty($propertyIds)) {
                $ph     = implode(',', array_fill(0, count($propertyIds), '?'));
                $params = array_merge([$landlordId], $propertyIds);
                $pdo->prepare("UPDATE properties SET landlord_id = ? WHERE id IN ($ph)")
                    ->execute($params);
            }

            logAction($pdo, 'properties_assigned', 'Landlords', $landlordId,
                count($propertyIds) . ' propert' . (count($propertyIds) === 1 ? 'y' : 'ies') . ' assigned.');
            header('Location: ../landlords.php?success=Properties+assigned+successfully');
        } catch (PDOException $e) {
            header('Location: ../landlords.php?error=' . urlencode($e->getMessage()));
        }
        exit();

    // ─── UNASSIGN PROPERTY ───────────────────────────────────────────────────
    case 'unassign_property':
        $propertyId = $_POST['property_id'] ?? '';
        try {
            $pdo->prepare("UPDATE properties SET landlord_id = NULL WHERE id = ?")
                ->execute([$propertyId]);
            header('Location: ../landlords.php?success=Property+unassigned');
        } catch (PDOException $e) {
            header('Location: ../landlords.php?error=' . urlencode($e->getMessage()));
        }
        exit();

    // ─── MAINTENANCE DECISION ────────────────────────────────────────────────
    case 'maintenance_decision':
        $maintId   = trim($_POST['maintenance_id'] ?? '');
        $decision  = in_array($_POST['decision'] ?? '', ['Approved', 'Denied'])
                         ? $_POST['decision'] : '';
        $redir     = trim($_POST['_redirect'] ?? '') ?: '../landlords.php';

        if (!$maintId || !$decision) {
            header('Location: ' . $redir . '&error=' . urlencode('Invalid request.'));
            exit();
        }

        try {
            $pdo->prepare("
                UPDATE maintenance_requests
                SET landlord_decision = ?
                WHERE id = ?
            ")->execute([$decision, $maintId]);

            logAction($pdo, 'maintenance_' . strtolower($decision), 'Maintenance', $maintId,
                "Landlord decision: {$decision}");
            header('Location: ' . $redir);
        } catch (PDOException $e) {
            header('Location: ' . $redir . '&error=' . urlencode($e->getMessage()));
        }
        exit();

    // ─── ADD LOAN ────────────────────────────────────────────────────────────
    case 'add_loan':
        $landlordId     = trim($_POST['landlord_id']     ?? '');
        $lenderName     = trim($_POST['lender_name']     ?? '');
        $principalAmt   = max(0, (float)($_POST['principal_amount']  ?? 0));
        $totalRepayable = max(0, (float)($_POST['total_repayable']   ?? 0));
        $interestRate   = max(0, (float)($_POST['interest_rate']     ?? 0));
        $commRate       = max(0, (float)($_POST['commission_rate']   ?? 0));
        $commAmt        = max(0, (float)($_POST['commission_amount'] ?? 0));
        $dueDate        = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $loanStatus     = in_array($_POST['status'] ?? '', ['Active','Cleared','Defaulted'])
                              ? $_POST['status'] : 'Active';
        $description    = trim($_POST['description'] ?? '');
        $loanRedir      = trim($_POST['_redirect'] ?? '') ?: '../landlords.php';

        if (!$landlordId || !$lenderName || $totalRepayable <= 0) {
            header('Location: ' . $loanRedir . '&error=' . urlencode('Lender name and amount are required.'));
            exit();
        }

        // Ensure columns exist
        foreach ([
            "ALTER TABLE landlord_loans ADD COLUMN IF NOT EXISTS principal_amount  DECIMAL(15,2) DEFAULT 0",
            "ALTER TABLE landlord_loans ADD COLUMN IF NOT EXISTS commission_rate   DECIMAL(5,2)  DEFAULT 0",
            "ALTER TABLE landlord_loans ADD COLUMN IF NOT EXISTS commission_amount DECIMAL(15,2) DEFAULT 0",
            "ALTER TABLE landlord_loans ADD COLUMN IF NOT EXISTS due_date          DATE NULL",
            "ALTER TABLE landlord_loans ADD COLUMN IF NOT EXISTS lender_name       VARCHAR(255)  NULL",
            "ALTER TABLE landlord_loans ADD COLUMN IF NOT EXISTS description       TEXT NULL",
        ] as $ddl) {
            try { $pdo->exec($ddl); } catch (PDOException $e) {}
        }

        try {
            $loanId = generateUUID();
            $pdo->prepare("
                INSERT INTO landlord_loans
                    (id, landlord_id, lender_name, principal_amount, total_repayable,
                     interest_rate, commission_rate, commission_amount,
                     due_date, status, description, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $loanId, $landlordId, $lenderName, $principalAmt, $totalRepayable,
                $interestRate, $commRate, $commAmt,
                $dueDate, $loanStatus, $description ?: null,
            ]);

            logAction($pdo, 'loan_added', 'Landlords', $landlordId,
                "Loan from {$lenderName}: {$totalRepayable}");
            header('Location: ' . $loanRedir);
        } catch (PDOException $e) {
            header('Location: ' . $loanRedir . '&error=' . urlencode($e->getMessage()));
        }
        exit();

    // ─── DELETE LOAN ─────────────────────────────────────────────────────────
    case 'delete_loan':
        $loanId    = trim($_POST['loan_id']   ?? '');
        $delRedir  = trim($_POST['_redirect'] ?? '') ?: '../landlords.php';

        if (!$loanId) {
            header('Location: ' . $delRedir);
            exit();
        }

        try {
            $row = $pdo->prepare("SELECT landlord_id FROM landlord_loans WHERE id = ?");
            $row->execute([$loanId]);
            $llId = $row->fetchColumn();

            $pdo->prepare("DELETE FROM landlord_loans WHERE id = ?")->execute([$loanId]);
            logAction($pdo, 'loan_deleted', 'Landlords', $llId ?: $loanId, "Loan {$loanId} deleted.");
            header('Location: ' . $delRedir);
        } catch (PDOException $e) {
            header('Location: ' . $delRedir . '&error=' . urlencode($e->getMessage()));
        }
        exit();

    default:
        header('Location: ../landlords.php');
        exit();
}
