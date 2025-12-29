<?php
require_once __DIR__ . '/../includes/init.php';

if (current_user() && has_role('admin')) {
    redirect(BASE_URL . '/admin/users.php');
}

render_header('Registration');
?>
<div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-slate-50 via-white to-emerald-50 p-6">
  <div class="bg-white border border-slate-100 shadow-2xl rounded-3xl p-8 max-w-lg w-full space-y-4">
    <h1 class="text-2xl font-bold text-slate-900">Registration is admin-only</h1>
    <p class="text-slate-700">Public sign-up is disabled. Please contact your DPV hub Admin or Super Admin to create a volunteer profile and receive your DP Code + password.</p>
    <a class="btn-primary justify-center w-full" href="<?=BASE_URL?>/auth/login.php">Go to login</a>
  </div>
</div>
<?php render_footer(); ?>
