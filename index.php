<?php
require_once __DIR__ . '/includes/init.php';
render_header('DPV hub', false);
$redirect = sanitize_redirect_path($_GET['redirect'] ?? '');
?>
<style>
/* Elegant Minimal Design */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}
@keyframes float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-8px); }
}
@keyframes gradientBG {
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
}

.hero-gradient {
    background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 50%, #f1f5f9 100%);
    background-size: 200% 200%;
    animation: gradientBG 12s ease infinite;
}

.accent-line {
    width: 60px;
    height: 3px;
    background: linear-gradient(90deg, #10b981 0%, #059669 100%);
    border-radius: 2px;
}

.login-card {
    animation: fadeIn 0.8s ease-out both;
    background: white;
    box-shadow: 0 25px 60px -12px rgba(0, 0, 0, 0.08);
}

.quote-card {
    animation: fadeIn 0.8s ease-out 0.2s both;
    background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
    border: 1px solid #a7f3d0;
}

.btn-elegant {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    transition: all 0.3s ease;
}
.btn-elegant:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 24px -4px rgba(16, 185, 129, 0.35);
}

.input-elegant {
    transition: all 0.2s ease;
    border: 2px solid #e2e8f0;
}
.input-elegant:focus {
    border-color: #10b981;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
    outline: none;
}

.logo-float {
    animation: float 4s ease-in-out infinite;
}

.quote-ar {
    font-family: 'Noto Kufi Arabic', 'Arial', sans-serif;
    direction: rtl;
}
</style>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Noto+Kufi+Arabic:wght@400;700&display=swap" rel="stylesheet">

<div class="hero-gradient min-h-screen">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 min-h-screen flex flex-col">
        
        <!-- Header -->
        <header class="py-6 flex items-center justify-between" style="animation: fadeIn 0.5s ease-out both;">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-xl flex items-center justify-center logo-float">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z"/>
                    </svg>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-slate-900">DPV hub</h1>
                    <p class="text-xs text-slate-500 font-medium">DP Volunteer</p>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="flex-1 flex items-center py-8">
            <div class="w-full grid lg:grid-cols-2 gap-12 lg:gap-16 items-center">
                
                <!-- Left: Content -->
                <div class="order-2 lg:order-1 space-y-8" style="animation: fadeIn 0.7s ease-out 0.1s both;">
                    
                    <!-- Headline -->
                    <div class="space-y-4">
                        <div class="accent-line"></div>
                        <h2 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-slate-900 leading-tight tracking-tight">
                            Commitment.<br>
                            <span class="text-emerald-600">Expertise.</span><br>
                            Patriotism.
                        </h2>
                        <p class="text-lg text-slate-600 max-w-md leading-relaxed">
                            A unified platform where dedication meets purpose — empowering volunteers across the nation.
                        </p>
                    </div>

                    <!-- Arabic Quote Card -->
                    <div class="quote-card rounded-2xl p-6 max-w-lg">
                        <p class="quote-ar text-slate-800 text-lg leading-loose">
                            "حيث يلتقي الالتزام بالخبرة، وحُب الوطن يُلهم كل عمل تطوعي — نقف موحدين كقوة واحدة، عائلة واحدة، إمارات واحدة."
                        </p>
                        <p class="quote-ar text-emerald-700 text-sm mt-3 font-semibold">— منصة متطوعي الإمارات</p>
                    </div>

                    <!-- Features -->
                    <div class="flex flex-wrap gap-4">
                        <div class="flex items-center gap-2 bg-white rounded-full px-4 py-2 shadow-sm border border-slate-100">
                            <svg class="w-4 h-4 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            <span class="text-sm font-medium text-slate-700">Secure Access</span>
                        </div>
                        <div class="flex items-center gap-2 bg-white rounded-full px-4 py-2 shadow-sm border border-slate-100">
                            <svg class="w-4 h-4 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            <span class="text-sm font-medium text-slate-700">Smart Attendance</span>
                        </div>
                        <div class="flex items-center gap-2 bg-white rounded-full px-4 py-2 shadow-sm border border-slate-100">
                            <svg class="w-4 h-4 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            <span class="text-sm font-medium text-slate-700">Digital ID Cards</span>
                        </div>
                    </div>
                </div>

                <!-- Right: Login Card -->
                <div class="order-1 lg:order-2">
                    <div class="login-card rounded-3xl p-8 sm:p-10 max-w-md mx-auto lg:ml-auto border border-slate-100">
                        
                        <!-- Card Header -->
                        <div class="text-center mb-8">
                            <div class="w-14 h-14 mx-auto bg-gradient-to-br from-slate-100 to-slate-200 rounded-2xl flex items-center justify-center mb-4">
                                <svg class="w-7 h-7 text-slate-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                            </div>
                            <h3 class="text-2xl font-bold text-slate-900">Welcome Back</h3>
                            <p class="text-slate-500 text-sm mt-1">Sign in to your account</p>
                        </div>

                        <!-- Login Form -->
                        <form action="<?=BASE_URL?>/auth/login.php" method="post" class="space-y-5">
                            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                            <?php if ($redirect): ?><input type="hidden" name="redirect" value="<?=h($redirect)?>"><?php endif; ?>
                            
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-2">DP Code</label>
                                <input name="dp_code" class="input-elegant w-full px-4 py-3 rounded-xl bg-slate-50 font-mono text-lg" placeholder="DP1234" required>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-2">Password</label>
                                <input type="password" name="password" class="input-elegant w-full px-4 py-3 rounded-xl bg-slate-50" placeholder="••••••••" required>
                            </div>
                            
                            <button class="btn-elegant w-full text-white font-semibold py-3.5 px-6 rounded-xl">
                                Sign In
                            </button>
                        </form>

                        <!-- Footer -->
                        <div class="mt-8 pt-6 border-t border-slate-100 text-center">
                            <p class="text-xs text-slate-400">
                                Admin-managed access only.<br>
                                Contact your administrator for credentials.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="py-6 text-center" style="animation: fadeIn 0.8s ease-out 0.5s both;">
            <p class="text-sm text-slate-400">
                © <?=date('Y')?> DPV hub · Fusion of volunteering & patriotism
            </p>
        </footer>
    </div>
</div>

<?php render_footer(); ?>
