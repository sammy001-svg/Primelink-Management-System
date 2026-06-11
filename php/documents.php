<?php
/**
 * Document Management Page
 * Primelink Management System
 */

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = getCurrentUser($pdo);
$role = $_SESSION['role'] ?? 'tenant';
$pageTitle = "Documents";

// Resolve tenant scope
$tenantId = null;
if ($role === 'tenant') {
    $stmt = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $tenantId = $stmt->fetchColumn() ?: null;
}

// Fetch documents
if ($role === 'tenant' && $tenantId) {
    $stmt = $pdo->prepare("SELECT d.*, t.full_name as tenant_name FROM documents d LEFT JOIN tenants t ON d.tenant_id = t.id WHERE d.tenant_id = ? ORDER BY d.created_at DESC");
    $stmt->execute([$tenantId]);
    $documents = $stmt->fetchAll();
} else {
    $documents = $pdo->query("SELECT d.*, t.full_name as tenant_name FROM documents d LEFT JOIN tenants t ON d.tenant_id = t.id ORDER BY d.created_at DESC")->fetchAll();
}

// Tenants list for admin upload form
$tenants = [];
if (in_array($role, ['admin', 'staff'])) {
    $tenants = $pdo->query("SELECT id, full_name FROM tenants WHERE status='Active' ORDER BY full_name")->fetchAll();
}

$success = $_GET['success'] ?? '';
$error   = $_GET['error']   ?? '';

// Category icon map
$catIcon = [
    'Lease'       => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
    'ID'          => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>',
    'Termination' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="15" x2="15" y2="15"/></svg>',
    'Other'       => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>',
];

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="space-y-8 animate-in">

    <?php if ($success): ?>
    <div class="p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-2xl text-green-700 dark:text-green-400 text-sm font-bold">
        <?php echo $success === 'uploaded' ? 'Document uploaded successfully.' : 'Document deleted.'; ?>
    </div>
    <?php elseif ($error): ?>
    <div class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-2xl text-red-700 dark:text-red-400 text-sm font-bold">
        <?php echo htmlspecialchars($error === 'type' ? 'Invalid file type. Allowed: PDF, JPG, PNG, DOC, DOCX.' : ($error === 'size' ? 'File too large. Max 10MB.' : 'Upload failed. Please try again.')); ?>
    </div>
    <?php endif; ?>

    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Vault & Documents</h1>
            <p class="text-slate-500 dark:text-slate-400 font-medium text-sm">Secure storage for leases, IDs, and financial records.</p>
        </div>
        <button onclick="openModal('uploadDocModal')" class="btn-primary text-xs gap-2">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Upload Document
        </button>
    </div>

    <!-- Stats bar -->
    <?php
    $cats = ['Lease' => 0, 'ID' => 0, 'Termination' => 0, 'Other' => 0];
    foreach ($documents as $d) { $cats[$d['category']] = ($cats[$d['category']] ?? 0) + 1; }
    ?>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <?php foreach ($cats as $cat => $cnt): ?>
        <div class="glass-card p-4 flex items-center gap-3">
            <div class="text-slate-400"><?php echo $catIcon[$cat] ?? ''; ?></div>
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest"><?php echo $cat; ?></p>
                <p class="text-xl font-black text-slate-900 dark:text-white"><?php echo $cnt; ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Documents Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        <?php if (empty($documents)): ?>
        <div class="col-span-full py-20 text-center glass-card">
            <svg class="mx-auto mb-4 text-slate-300 dark:text-slate-700" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
            <p class="text-slate-400 font-bold">No documents uploaded yet.</p>
            <button onclick="openModal('uploadDocModal')" class="mt-4 btn-primary text-xs">Upload First Document</button>
        </div>
        <?php else: ?>
        <?php foreach ($documents as $doc): ?>
        <div class="glass-card p-6 flex flex-col justify-between group hover:border-accent-green/30 transition-all">
            <div class="space-y-4">
                <div class="w-12 h-12 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-400 group-hover:text-accent-green transition-colors">
                    <?php echo $catIcon[$doc['category']] ?? $catIcon['Other']; ?>
                </div>
                <div>
                    <h3 class="text-sm font-black text-slate-900 dark:text-white truncate" title="<?php echo htmlspecialchars($doc['title']); ?>"><?php echo htmlspecialchars($doc['title']); ?></h3>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">
                        <?php echo htmlspecialchars($doc['category']); ?>
                        <?php if ($doc['file_size']): ?> • <?php echo htmlspecialchars($doc['file_size']); ?><?php endif; ?>
                    </p>
                    <?php if ($doc['tenant_name']): ?>
                    <p class="text-[10px] text-slate-400 font-medium mt-1"><?php echo htmlspecialchars($doc['tenant_name']); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mt-6 flex justify-between items-center">
                <span class="text-[10px] font-bold text-slate-400"><?php echo date('M d, Y', strtotime($doc['created_at'])); ?></span>
                <div class="flex gap-2">
                    <a href="<?php echo htmlspecialchars($doc['file_url']); ?>" target="_blank"
                       class="p-2 h-8 w-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-accent-green hover:text-white transition-all" title="Download">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                    </a>
                    <?php if (in_array($role, ['admin', 'staff'])): ?>
                    <form action="actions/document_actions.php" method="POST" onsubmit="return confirm('Delete this document?')" style="display:inline">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="doc_id" value="<?php echo htmlspecialchars($doc['id']); ?>">
                        <input type="hidden" name="file_url" value="<?php echo htmlspecialchars($doc['file_url']); ?>">
                        <button type="submit" class="p-2 h-8 w-8 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-red-500 hover:text-white transition-all" title="Delete">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- ===== UPLOAD MODAL ===== -->
<div class="modal-overlay" id="uploadDocModal" style="display:none;">
    <div class="modal-card max-w-lg px-8 py-10">
        <button onclick="closeModal('uploadDocModal')" class="absolute top-6 right-6 text-slate-400 hover:text-slate-900 dark:hover:text-white transition-all transform hover:rotate-90">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
        </button>

        <h2 class="text-2xl font-black mb-6">Upload Document</h2>

        <form action="actions/document_actions.php" method="POST" enctype="multipart/form-data" class="space-y-5">
            <input type="hidden" name="action" value="upload">

            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Document Title</label>
                <input type="text" name="title" required placeholder="E.g. John Doe — Tenancy Agreement"
                       class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Category</label>
                    <select name="category" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                        <option value="Lease">Lease</option>
                        <option value="ID">ID Document</option>
                        <option value="Termination">Termination</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <?php if (!empty($tenants)): ?>
                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">Tenant (optional)</label>
                    <select name="tenant_id" class="w-full px-5 py-4 bg-slate-100 dark:bg-slate-800/50 border-none rounded-2xl text-sm font-bold focus:ring-2 focus:ring-accent-green/20 transition-all outline-none">
                        <option value="">— General —</option>
                        <?php foreach ($tenants as $t): ?>
                        <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else: ?>
                <input type="hidden" name="tenant_id" value="<?php echo $tenantId ?? ''; ?>">
                <?php endif; ?>
            </div>

            <div class="space-y-2">
                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-2">File</label>
                <label class="flex flex-col items-center justify-center w-full py-8 bg-slate-50 dark:bg-slate-800/40 border-2 border-dashed border-slate-200 dark:border-slate-700 rounded-2xl cursor-pointer hover:border-accent-green transition-all group">
                    <svg class="text-slate-300 group-hover:text-accent-green transition-colors mb-2" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <span class="text-xs font-bold text-slate-400" id="file-label">Click to select file</span>
                    <span class="text-[10px] text-slate-300 mt-1">PDF, JPG, PNG, DOC, DOCX — max 10MB</span>
                    <input type="file" name="document_file" id="document_file" required
                           accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="hidden"
                           onchange="document.getElementById('file-label').textContent = this.files[0]?.name || 'Click to select file'">
                </label>
            </div>

            <button type="submit" class="btn-primary w-full justify-center py-4 font-black">Upload Document →</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
