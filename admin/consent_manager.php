<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
if (!is_super_admin()) {
    http_response_code(403);
    exit('Forbidden');
}

$notice = null;
$error = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $error = 'Session expired. Please refresh the page.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'publish') {
            $contentEn = $_POST['content_en'] ?? '';
            $contentAr = $_POST['content_ar'] ?? '';
            $contentUr = $_POST['content_ur'] ?? '';

            try {
                $newVersionId = publish_consent_version($contentEn, $contentAr, $contentUr, (int) current_user()['id']);
                log_action('consent_publish', 'consent_version', $newVersionId, [
                    'version_id' => $newVersionId
                ]);
                $notice = "New consent version (v$newVersionId) published successfully. All users will be required to sign.";
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }

    // PRG pattern
    if ($notice) {
        flash('success', $notice);
        redirect(BASE_URL . '/admin/consent_manager.php');
    }
}

$notice = flash('success') ?: $notice;
$latestVersion = get_latest_consent_version();
$versionHistory = get_consent_versions_history(20);

// Get draft content (from latest version or empty)
$draftEn = $latestVersion['content_en'] ?? '';
$draftAr = $latestVersion['content_ar'] ?? '';
$draftUr = $latestVersion['content_ur'] ?? '';

render_header('Consent Manager');
?>
<style>
    .tab-btn {
        padding: 0.75rem 1.5rem;
        font-weight: 600;
        border-radius: 12px 12px 0 0;
        border: 1px solid #e2e8f0;
        border-bottom: none;
        background: #f8fafc;
        color: #64748b;
        cursor: pointer;
        transition: all 0.2s;
    }

    .tab-btn.active {
        background: white;
        color: #059669;
        border-color: #d1d5db;
    }

    .tab-btn:hover:not(.active) {
        background: #f1f5f9;
    }

    .tab-content {
        display: none;
    }

    .tab-content.active {
        display: block;
    }

    .content-editor {
        width: 100%;
        min-height: 300px;
        padding: 1rem;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        font-family: inherit;
        font-size: 0.95rem;
        line-height: 1.6;
        resize: vertical;
    }

    .content-editor:focus {
        outline: none;
        border-color: #059669;
        box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
    }

    .content-editor.rtl {
        direction: rtl;
        text-align: right;
    }

    .version-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.25rem 0.75rem;
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        color: white;
        font-weight: 700;
        font-size: 0.75rem;
        border-radius: 999px;
    }

    .history-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.75rem 1rem;
        border-radius: 12px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
    }

    .history-row:hover {
        background: #f1f5f9;
    }
</style>

<div class="dpv-container dpv-space-y-6">
    <!-- Page Header -->
    <div class="dpv-page-header">
        <div class="dpv-page-header__layout">
            <div>
                <p class="dpv-page-header__eyebrow">📋 Compliance & Consent</p>
                <h1 class="dpv-page-header__title">Consent Manager</h1>
                <p class="dpv-page-header__subtitle">Manage Terms & Waiver documents. Users must sign the latest version
                    to access the platform.</p>
            </div>
            <div class="dpv-page-header__actions">
                <a href="<?= dashboard_url() ?>" class="dpv-page-header__btn">
                    ← Dashboard
                </a>
            </div>
        </div>
    </div>

    <?php if ($notice): ?>
        <div class="dpv-alert dpv-alert--success">
            <span>✅</span>
            <span><?= h($notice) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="dpv-alert dpv-alert--error">
            <span>⚠️</span>
            <span><?= h($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Current Status -->
    <div class="bg-white rounded-2xl shadow border border-slate-100 p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-slate-500">Current Active Version</p>
                <?php if ($latestVersion): ?>
                    <div class="flex items-center gap-3 mt-1">
                        <span class="version-badge">v<?= $latestVersion['id'] ?></span>
                        <span class="text-sm text-slate-600">📅 Published
                            <?= format_dubai_datetime($latestVersion['published_at'], 'full') ?></span>
                    </div>
                <?php else: ?>
                    <p class="text-lg font-bold text-amber-600 mt-1">No version published yet</p>
                    <p class="text-sm text-slate-500">Users can access the platform without consent until you publish the
                        first version.</p>
                <?php endif; ?>
            </div>
            <div class="text-right">
                <p class="text-sm text-slate-500">Total Versions</p>
                <p class="text-2xl font-black text-slate-900"><?= count($versionHistory) ?></p>
            </div>
        </div>
    </div>

    <!-- Editor Section -->
    <form method="post" class="bg-white rounded-2xl shadow border border-slate-100 overflow-hidden" id="consentForm">
        <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="publish">

        <div class="p-5 border-b border-slate-100">
            <h2 class="text-lg font-bold text-slate-900">Edit Consent Document</h2>
            <p class="text-sm text-slate-500">All three languages are required. HTML formatting is allowed (safe tags
                only).</p>
        </div>

        <!-- Language Tabs -->
        <div class="flex border-b border-slate-200 px-5 pt-4 gap-2">
            <button type="button" class="tab-btn active" data-tab="en">🇬🇧 English</button>
            <button type="button" class="tab-btn" data-tab="ar">🇦🇪 العربية</button>
            <button type="button" class="tab-btn" data-tab="ur">🇵🇰 اردو</button>
        </div>

        <!-- Tab Contents -->
        <div class="p-5">
            <div class="tab-content active" data-tab-content="en">
                <label class="block text-sm font-semibold text-slate-700 mb-2">English Content (LTR)</label>
                <textarea name="content_en" class="content-editor"
                    placeholder="Enter Terms & Conditions in English..."><?= h($draftEn) ?></textarea>
            </div>
            <div class="tab-content" data-tab-content="ar">
                <label class="block text-sm font-semibold text-slate-700 mb-2">Arabic Content (RTL)</label>
                <textarea name="content_ar" class="content-editor rtl"
                    placeholder="أدخل الشروط والأحكام بالعربية..."><?= h($draftAr) ?></textarea>
            </div>
            <div class="tab-content" data-tab-content="ur">
                <label class="block text-sm font-semibold text-slate-700 mb-2">Urdu Content (RTL)</label>
                <textarea name="content_ur" class="content-editor rtl"
                    placeholder="اردو میں شرائط و ضوابط درج کریں..."><?= h($draftUr) ?></textarea>
            </div>

            <div class="mt-4 p-4 bg-amber-50 border border-amber-100 rounded-xl">
                <p class="text-sm text-amber-800">
                    <strong>⚠️ Important:</strong> Publishing a new version will require ALL users to re-sign.
                    This action creates a new version and cannot be undone.
                </p>
            </div>

            <div class="mt-4 flex items-center gap-3">
                <button type="submit" class="btn-primary px-6 py-3" id="publishBtn" disabled>
                    <span class="mr-2">📝</span> Publish Update
                </button>
                <span class="text-sm text-slate-500" id="validationStatus">Fill all 3 languages to enable
                    publishing</span>
            </div>
        </div>
    </form>

    <!-- Version History -->
    <?php if ($versionHistory): ?>
        <div class="bg-white rounded-2xl shadow border border-slate-100 overflow-hidden">
            <div class="p-5 border-b border-slate-100">
                <h2 class="text-lg font-bold text-slate-900">Version History</h2>
                <p class="text-sm text-slate-500">All previous versions are preserved for legal audit purposes.</p>
            </div>
            <div class="p-5 space-y-3">
                <?php foreach ($versionHistory as $version): ?>
                    <div class="history-row">
                        <div class="flex items-center gap-3">
                            <span class="version-badge">v<?= $version['id'] ?></span>
                            <div>
                                <p class="font-semibold text-slate-900">
                                    <?= format_dubai_datetime($version['published_at'], 'date') ?>
                                    <span class="text-slate-500 font-normal">at
                                        <?= format_dubai_datetime($version['published_at'], 'time') ?></span>
                                </p>
                                <p class="text-xs text-slate-500">Published by
                                    <?= h($version['published_by_name'] ?? 'Unknown') ?>
                                </p>
                            </div>
                        </div>
                        <button type="button" class="text-sm text-emerald-700 font-semibold hover:underline view-version-btn"
                            data-version-id="<?= $version['id'] ?>" data-content-en="<?= h($version['content_en']) ?>"
                            data-content-ar="<?= h($version['content_ar']) ?>"
                            data-content-ur="<?= h($version['content_ur']) ?>">
                            View Content
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- View Version Modal -->
<div id="viewModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-hidden flex flex-col">
        <div class="p-5 border-b border-slate-100 flex items-center justify-between">
            <div>
                <p class="text-sm text-slate-500">Viewing Version</p>
                <h3 class="text-lg font-bold text-slate-900" id="modalVersionTitle">v1</h3>
            </div>
            <button type="button" id="closeModal" class="text-slate-400 hover:text-slate-600 text-2xl">&times;</button>
        </div>
        <div class="flex border-b border-slate-200 px-5 pt-4 gap-2">
            <button type="button" class="modal-tab-btn tab-btn active" data-modal-tab="en">🇬🇧 English</button>
            <button type="button" class="modal-tab-btn tab-btn" data-modal-tab="ar">🇦🇪 العربية</button>
            <button type="button" class="modal-tab-btn tab-btn" data-modal-tab="ur">🇵🇰 اردو</button>
        </div>
        <div class="p-5 overflow-y-auto flex-1">
            <div class="modal-tab-content" data-modal-tab-content="en" id="modalContentEn"></div>
            <div class="modal-tab-content hidden" data-modal-tab-content="ar" id="modalContentAr" dir="rtl"></div>
            <div class="modal-tab-content hidden" data-modal-tab-content="ur" id="modalContentUr" dir="rtl"></div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Tab switching for editor
        const tabBtns = document.querySelectorAll('.tab-btn:not(.modal-tab-btn)');
        const tabContents = document.querySelectorAll('.tab-content');

        tabBtns.forEach(btn => {
            btn.addEventListener('click', function () {
                const tab = this.getAttribute('data-tab');
                tabBtns.forEach(b => b.classList.remove('active'));
                tabContents.forEach(c => c.classList.remove('active'));
                this.classList.add('active');
                document.querySelector(`[data-tab-content="${tab}"]`).classList.add('active');
            });
        });

        // Form validation
        const form = document.getElementById('consentForm');
        const publishBtn = document.getElementById('publishBtn');
        const validationStatus = document.getElementById('validationStatus');
        const textareas = form.querySelectorAll('textarea');

        function checkValidation() {
            let allFilled = true;
            textareas.forEach(ta => {
                if (ta.value.trim() === '') allFilled = false;
            });

            publishBtn.disabled = !allFilled;
            if (allFilled) {
                validationStatus.textContent = '✓ Ready to publish';
                validationStatus.className = 'text-sm text-emerald-600 font-semibold';
            } else {
                validationStatus.textContent = 'Fill all 3 languages to enable publishing';
                validationStatus.className = 'text-sm text-slate-500';
            }
        }

        textareas.forEach(ta => {
            ta.addEventListener('input', checkValidation);
        });
        checkValidation();

        // Publish confirmation
        form.addEventListener('submit', function (e) {
            if (!confirm('Are you sure you want to publish a new version? All users will be required to re-sign the consent.')) {
                e.preventDefault();
            }
        });

        // View version modal
        const viewModal = document.getElementById('viewModal');
        const closeModal = document.getElementById('closeModal');
        const modalVersionTitle = document.getElementById('modalVersionTitle');
        const modalContentEn = document.getElementById('modalContentEn');
        const modalContentAr = document.getElementById('modalContentAr');
        const modalContentUr = document.getElementById('modalContentUr');

        document.querySelectorAll('.view-version-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const id = this.getAttribute('data-version-id');
                const contentEn = this.getAttribute('data-content-en');
                const contentAr = this.getAttribute('data-content-ar');
                const contentUr = this.getAttribute('data-content-ur');

                modalVersionTitle.textContent = 'v' + id;
                modalContentEn.innerHTML = contentEn;
                modalContentAr.innerHTML = contentAr;
                modalContentUr.innerHTML = contentUr;

                // Reset tabs
                document.querySelectorAll('.modal-tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.modal-tab-content').forEach(c => c.classList.add('hidden'));
                document.querySelector('.modal-tab-btn').classList.add('active');
                document.querySelector('.modal-tab-content').classList.remove('hidden');

                viewModal.classList.remove('hidden');
                viewModal.classList.add('flex');
            });
        });

        // Modal tab switching
        document.querySelectorAll('.modal-tab-btn').forEach(btn => {
            btn.addEventListener('click', function () {
                const tab = this.getAttribute('data-modal-tab');
                document.querySelectorAll('.modal-tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.modal-tab-content').forEach(c => c.classList.add('hidden'));
                this.classList.add('active');
                document.querySelector(`[data-modal-tab-content="${tab}"]`).classList.remove('hidden');
            });
        });

        closeModal.addEventListener('click', function () {
            viewModal.classList.add('hidden');
            viewModal.classList.remove('flex');
        });

        viewModal.addEventListener('click', function (e) {
            if (e.target === viewModal) {
                viewModal.classList.add('hidden');
                viewModal.classList.remove('flex');
            }
        });
    });
</script>
<?php render_footer(); ?>