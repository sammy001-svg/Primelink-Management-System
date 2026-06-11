<?php
/**
 * Document Action Handler
 * Primelink Management System
 */

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

require_once __DIR__ . '/../includes/audit.php';

$action = $_POST['action'] ?? '';

// ──────────────────────────────────────────────────────────
// UPLOAD
// ──────────────────────────────────────────────────────────
if ($action === 'upload') {
    $title     = trim($_POST['title']     ?? '');
    $category  = $_POST['category']       ?? 'Other';
    $tenantId  = $_POST['tenant_id']      ?? null;

    $allowedExt  = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
    $maxSize     = 10 * 1024 * 1024; // 10MB
    $uploadDir   = __DIR__ . '/../../uploads/documents/';

    if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
        header("Location: ../documents.php?error=upload");
        exit;
    }

    $file    = $_FILES['document_file'];
    $origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($origExt, $allowedExt)) {
        header("Location: ../documents.php?error=type");
        exit;
    }
    if ($file['size'] > $maxSize) {
        header("Location: ../documents.php?error=size");
        exit;
    }

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $newFilename = generateUUID() . '.' . $origExt;
    $destPath    = $uploadDir . $newFilename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        header("Location: ../documents.php?error=upload");
        exit;
    }

    $fileUrl  = 'uploads/documents/' . $newFilename;
    $fileSize = formatFileSize($file['size']);
    $id       = generateUUID();

    $stmt = $pdo->prepare(
        "INSERT INTO documents (id, tenant_id, title, category, file_url, file_size, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmt->execute([$id, $tenantId ?: null, $title, $category, $fileUrl, $fileSize]);

    logAction($pdo, 'document_uploaded', 'Documents', $id, "Uploaded: {$title} ({$category})");

    header("Location: ../documents.php?success=uploaded");
    exit;
}

// ──────────────────────────────────────────────────────────
// DELETE
// ──────────────────────────────────────────────────────────
if ($action === 'delete') {
    requireLogin(['admin', 'staff']);

    $docId   = $_POST['doc_id']   ?? '';
    $fileUrl = $_POST['file_url'] ?? '';

    $stmt = $pdo->prepare("DELETE FROM documents WHERE id = ?");
    $stmt->execute([$docId]);

    // Remove physical file
    $fullPath = __DIR__ . '/../../' . $fileUrl;
    if (file_exists($fullPath)) {
        @unlink($fullPath);
    }

    logAction($pdo, 'document_deleted', 'Documents', $docId, "Deleted file: {$fileUrl}");

    header("Location: ../documents.php?success=deleted");
    exit;
}

header("Location: ../documents.php");
exit;

function formatFileSize(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
