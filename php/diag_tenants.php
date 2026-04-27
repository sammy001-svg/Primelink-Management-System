<?php
require_once 'config/db.php';

$tenantsInUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'tenant'")->fetchColumn();
$tenantsInTenants = $pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
$orphans = $pdo->query("SELECT COUNT(*) FROM users u WHERE u.role = 'tenant' AND NOT EXISTS (SELECT 1 FROM tenants t WHERE t.user_id = u.id)")->fetchColumn();

echo "Tenants in users table: $tenantsInUsers\n";
echo "Tenants in tenants table: $tenantsInTenants\n";
echo "Orphaned tenants: $orphans\n";

if ($orphans > 0) {
    echo "\nOrphan List:\n";
    $stmt = $pdo->query("SELECT u.email, p.full_name FROM users u JOIN profiles p ON u.id = p.id WHERE u.role = 'tenant' AND NOT EXISTS (SELECT 1 FROM tenants t WHERE t.user_id = u.id)");
    while ($row = $stmt->fetch()) {
        echo "- " . $row['full_name'] . " (" . $row['email'] . ")\n";
    }
}
?>
