<?php
/**
 * System Settings Helper
 * Primelink Management System
 */

/**
 * Get a system setting by key, with optional fallback.
 * Auto-creates the table row from defaults if missing.
 */
function getSetting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return ($val !== false && $val !== null) ? $val : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

/**
 * Get multiple settings at once. Returns associative array.
 */
function getSettings(PDO $pdo, array $keys): array {
    $result = [];
    if (empty($keys)) return $result;
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    try {
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute($keys);
        foreach ($stmt->fetchAll() as $row) {
            $result[$row['setting_key']] = $row['setting_value'];
        }
    } catch (PDOException $e) {
        // Return empty if table not yet migrated
    }
    return $result;
}

/**
 * Persist a setting value.
 */
function setSetting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare(
        "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    );
    $stmt->execute([$key, $value]);
}

/**
 * Persist multiple settings at once from an associative array.
 */
function setSettings(PDO $pdo, array $data): void {
    foreach ($data as $key => $value) {
        setSetting($pdo, $key, $value ?? '');
    }
}
