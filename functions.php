<?php
/**
 * DPV Hub Main Functions Bootstrap
 * 
 * This file loads all helper modules and provides layout functions.
 * Individual helper files contain domain-specific functions.
 * 
 * @package DPVHub
 * @version 2.0.0 - Modular Architecture
 * @since 1.0.0
 * 
 * HELPER MODULES:
 * - helpers_core.php     : CSRF, redirect, sanitize, flash, passwords
 * - helpers_auth.php     : Authentication, roles, permissions, throttling
 * - helpers_date.php     : Date/time formatting (Dubai timezone)
 * - helpers_validation.php : Input validation
 * - helpers_upload.php   : File upload handlers
 * - helpers_qr.php       : QR code generation, DP code pool
 * - helpers_activity.php : Activity logging
 * - helpers_consent.php  : Consent management
 */

// =====================================================
// CORE BOOTSTRAP
// =====================================================

require_once __DIR__ . '/config.php';

// Hardened session start (runs once).
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', 1);
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        @ini_set('session.cookie_secure', 1);
    }
    session_name('dpvhubsess');
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/phpqrcode.php';
require_once __DIR__ . '/mail_service.php';

// =====================================================
// LOAD HELPER MODULES
// =====================================================

require_once __DIR__ . '/includes/helpers_core.php';
require_once __DIR__ . '/includes/helpers_auth.php';
require_once __DIR__ . '/includes/helpers_date.php';
require_once __DIR__ . '/includes/helpers_validation.php';
require_once __DIR__ . '/includes/helpers_upload.php';
require_once __DIR__ . '/includes/helpers_qr.php';
require_once __DIR__ . '/includes/helpers_activity.php';
require_once __DIR__ . '/includes/helpers_consent.php';
require_once __DIR__ . '/includes/helpers_user.php';

// =====================================================
// CENTRALIZED CONFIG HELPERS
// =====================================================

/**
 * Get application config value using dot notation.
 * Example: app_config('redirects.after_login.admin')
 */
function app_config(?string $key = null)
{
    static $config = null;
    if ($config === null) {
        $configFile = __DIR__ . '/config/app.php';
        $config = file_exists($configFile) ? require $configFile : [];
    }
    if ($key === null) {
        return $config;
    }
    $keys = explode('.', $key);
    $value = $config;
    foreach ($keys as $k) {
        if (!is_array($value) || !array_key_exists($k, $value)) {
            return null;
        }
        $value = $value[$k];
    }
    return $value;
}

/**
 * Get redirect URL for a role based on redirect type.
 * Example: get_role_redirect('after_login', 'admin') => BASE_URL . '/user/dashboard.php'
 */
function get_role_redirect(string $type, string $role): string
{
    $redirects = app_config('redirects.' . $type);
    if ($redirects && isset($redirects[$role])) {
        return BASE_URL . $redirects[$role];
    }
    // Fallback to default
    $default = app_config('redirects.default') ?? '/user/dashboard.php';
    return BASE_URL . $default;
}

/**
 * Get navigation home URL for a role.
 * Example: get_nav_home('admin') => BASE_URL . '/user/dashboard.php'
 */
function get_nav_home(string $role): string
{
    $homes = app_config('nav_home');
    if ($homes && isset($homes[$role])) {
        return BASE_URL . $homes[$role];
    }
    return BASE_URL . '/user/dashboard.php';
}

// =====================================================
// SMTP MAILER (kept here due to complexity)
// =====================================================


// =====================================================
// LAYOUT: HEADER & FOOTER
// =====================================================

// LAYOUT: HEADER & FOOTER
// =====================================================

function render_header(string $title = 'DPV hub', bool $showNav = true): void
{
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');
        // SECURITY: Content Security Policy to mitigate XSS (local assets only)
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none';");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
    $fullTitle = h($title . ' - ' . APP_NAME);
    echo "<!doctype html><html lang='en'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1'>";
    // Tailwind v4 - compiled locally via npm run build
    echo "<link rel='stylesheet' href='" . BASE_URL . "/assets/css/style.css?v=20251218'>";
    // DPV Hub Unified Design System - modular, themeable CSS
    echo "<link rel='stylesheet' href='" . BASE_URL . "/assets/css/dpvhub-ui.css?v=20251218'>";
    // Cache-bust custom CSS to avoid stale styles on clients
    echo "<link rel='stylesheet' href='" . BASE_URL . "/assets/css/custom.css?v=20251218'>";
    echo "<title>$fullTitle</title></head><body class='text-slate-900 min-h-screen'>";
    if ($showNav) {
        $u = current_user();
        $navLinks = [];
        // Normalize path (trim query + trailing slash) for active state detection
        $cleanPath = function ($val) {
            $p = parse_url($val ?? '', PHP_URL_PATH);
            if ($p === null || $p === false) {
                return '';
            }
            $p = rtrim($p, '/');
            return $p === '' ? '/' : $p;
        };
        $stripBase = function (string $path) {
            if (!BASE_URL)
                return $path;
            return (strpos($path, BASE_URL) === 0) ? substr($path, strlen(BASE_URL)) : $path;
        };
        $normalizeNavPath = function ($val) use ($cleanPath, $stripBase) {
            $p = $cleanPath($val);
            $pNoBase = $cleanPath($stripBase($p));
            return $pNoBase;
        };
        $currentPath = $cleanPath($_SERVER['REQUEST_URI'] ?? '');
        $currentPathNoBase = $cleanPath($stripBase($currentPath));
        $currentNavPath = $normalizeNavPath($_SERVER['REQUEST_URI'] ?? '');
        $scriptPath = parse_url($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? ''), PHP_URL_PATH);
        $scriptFile = basename($scriptPath);
        $currentFile = basename($currentPath ?: $scriptPath);
        // Pattern aliases so subpages highlight their parent menu
        $activePatterns = [
            BASE_URL . '/admin/dashboard.php' => ['#^' . preg_quote(BASE_URL . '/admin/dashboard', '#') . '#'],
            BASE_URL . '/user/dashboard.php' => ['#^' . preg_quote(BASE_URL . '/user/dashboard', '#') . '#'],
            BASE_URL . '/admin/users.php' => ['#^' . preg_quote(BASE_URL . '/admin/user', '#') . '#'],
            BASE_URL . '/admin/admins.php' => ['#^' . preg_quote(BASE_URL . '/admin/admin', '#') . '#'],
            BASE_URL . '/admin/events.php' => [
                '#^' . preg_quote(BASE_URL . '/admin/event', '#') . '#',
                '#^' . preg_quote(BASE_URL . '/event-admin', '#') . '#'
            ],
            BASE_URL . '/admin/custom_export.php' => ['#^' . preg_quote(BASE_URL . '/admin/custom_export', '#') . '#'],
            BASE_URL . '/admin/logs.php' => ['#^' . preg_quote(BASE_URL . '/admin/log', '#') . '#'],
            BASE_URL . '/admin/settings_role.php' => ['#^' . preg_quote(BASE_URL . '/admin/settings_role', '#') . '#'],
        ];
        if ($u) {
            // Navigation home link (uses centralized config)
            $dashLink = get_nav_home($u['role']);
            if (is_super_admin()) {
                $navLinks = [
                    ['label' => 'Dashboard', 'href' => $dashLink],
                    ['label' => 'Users', 'href' => BASE_URL . '/admin/users.php'],
                    ['label' => 'Admins', 'href' => BASE_URL . '/admin/admins.php'],
                    ['label' => 'Events', 'href' => BASE_URL . '/admin/events.php'],
                    ['label' => 'Custom CSV', 'href' => BASE_URL . '/admin/custom_export.php'],
                    ['label' => 'Logs', 'href' => BASE_URL . '/admin/logs.php'],
                    ['label' => 'General Role', 'href' => BASE_URL . '/admin/settings_role.php'],
                    ['label' => 'Consent', 'href' => BASE_URL . '/admin/consent_manager.php'],
                ];
            } elseif (has_role('admin')) {
                $navLinks[] = ['label' => 'Dashboard', 'href' => $dashLink];
                if (has_permission('manage_users')) {
                    $navLinks[] = ['label' => 'Users', 'href' => BASE_URL . '/admin/users.php'];
                }
                if (has_permission('manage_events')) {
                    $navLinks[] = ['label' => 'Events', 'href' => BASE_URL . '/admin/events.php'];
                }
                if (has_permission('manage_exports')) {
                    $navLinks[] = ['label' => 'Custom CSV', 'href' => BASE_URL . '/admin/custom_export.php'];
                }
                if (has_permission('view_logs')) {
                    $navLinks[] = ['label' => 'Logs', 'href' => BASE_URL . '/admin/logs.php'];
                }
            } else {
                $navLinks[] = ['label' => 'Dashboard', 'href' => $dashLink];
            }
            $navLinks[] = ['label' => 'Logout', 'href' => BASE_URL . '/auth/logout.php', 'class' => 'nav-logout'];
        } else {
            $navLinks[] = ['label' => 'Login', 'href' => BASE_URL . '/auth/login.php'];
        }
        echo "<header class='bg-white shadow-md sticky top-0 z-30 border-b border-slate-100'>";
        echo "<div class='max-w-6xl mx-auto px-4 py-3'>";
        echo "<div class='flex items-center justify-between gap-3'>";
        echo "<div class='flex items-center gap-3'>";
        echo "<button type='button' class='nav-toggle md:hidden' aria-controls='mainNav' aria-expanded='false' data-nav-toggle='mainNav'>Menu</button>";
        echo "<a class='font-black text-xl text-emerald-700 tracking-tight' href='" . BASE_URL . "/'>DPV hub</a>";
        echo "</div>";
        if ($u) {
            echo "<div class='text-xs sm:text-sm text-slate-600 text-right flex-1 md:flex-none md:text-base'>Hi, " . h($u['full_name']) . "</div>";
        }
        echo "</div>";
        echo "<nav id='mainNav' data-base='" . h(BASE_URL) . "' class='nav-drawer hidden md:flex flex-col md:flex-row md:items-center gap-2 md:gap-3 mt-3 md:mt-2 text-sm font-semibold'>";
        echo "<div class='flex flex-wrap gap-2'>";
        foreach ($navLinks as $link) {
            $class = 'nav-link';
            $linkPath = $cleanPath($link['href']);
            $linkPathNoBase = $cleanPath($stripBase($linkPath));
            $linkNavPath = $normalizeNavPath($link['href']);
            $linkFile = basename($linkPath);
            $labelSlug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $link['label']), '-'));
            $isActive = false;
            if ($linkNavPath && ($currentNavPath === $linkNavPath || strpos($currentNavPath, $linkNavPath . '/') === 0 || strpos($linkNavPath, $currentNavPath . '/') === 0)) {
                $isActive = true;
            } elseif ($linkPath && ($currentPath === $linkPath || strpos($currentPath, $linkPath . '/') === 0)) {
                $isActive = true;
            } elseif ($linkPathNoBase && ($currentPathNoBase === $linkPathNoBase || strpos($currentPathNoBase, $linkPathNoBase . '/') === 0)) {
                $isActive = true;
            } elseif (isset($activePatterns[$linkPath])) {
                foreach ($activePatterns[$linkPath] as $pattern) {
                    if (preg_match($pattern, $currentPath)) {
                        $isActive = true;
                        break;
                    }
                }
            } elseif ($linkFile && ($currentFile === $linkFile || $scriptFile === $linkFile || strpos($scriptPath, $linkPathNoBase) !== false)) {
                $isActive = true;
            } elseif ($labelSlug && $labelSlug !== 'logout') {
                if (strpos(strtolower($currentPathNoBase), $labelSlug) !== false || strpos(strtolower($currentFile), $labelSlug) !== false) {
                    $isActive = true;
                }
            }
            if ($isActive) {
                $class .= ' nav-link-active';
            }
            if (!empty($link['class'])) {
                $class .= ' ' . $link['class'];
            }
            $dataNavPath = $linkNavPath ?: $linkPathNoBase ?: $linkPath;
            $ariaCurrent = $isActive ? " aria-current='page'" : " aria-current='false'";
            echo "<a class='$class' data-nav-path='" . h($dataNavPath) . "' data-nav-label='" . h($labelSlug) . "' data-nav-file='" . h($linkFile) . "'$ariaCurrent href='" . h($link['href']) . "'>" . h($link['label']) . "</a>";
        }
        echo "</div>";
        echo "</nav>";
        echo "</div></header>";
        echo "<script>
(function() {
  var nav = document.getElementById('mainNav');
  if (!nav) return;
  var btn = document.querySelector('[data-nav-toggle=\"mainNav\"]');
  if (btn) {
    btn.addEventListener('click', function() {
      var hiddenNow = nav.classList.toggle('hidden');
      btn.setAttribute('aria-expanded', hiddenNow ? 'false' : 'true');
    });
  }
  var links = Array.prototype.slice.call(nav.querySelectorAll('a.nav-link'));
  var normalize = function(p) {
    var pathVal = p || '/';
    try { pathVal = new URL(p, window.location.origin).pathname; } catch(e) {}
    pathVal = pathVal.replace(/\\/+$/, '') || '/';
    return pathVal;
  };
  var current = normalize(window.location.pathname);
  var best = null;
  var bestScore = -1;
  var setActive = function(target) {
    if (!target) return;
    links.forEach(function(l) { l.classList.remove('nav-link-active'); l.setAttribute('aria-current','false'); });
    target.classList.add('nav-link-active');
    target.setAttribute('aria-current','page');
  };
  links.forEach(function(a) {
    var lp = normalize(a.getAttribute('href') || '');
    var score = 0;
    if (current === lp) {
      score = lp.length + 100;
    } else if (current.indexOf(lp + '/') === 0) {
      score = lp.length;
    } else if (lp.indexOf(current + '/') === 0) {
      score = current.length;
    } else {
      var file = lp.split('/').pop();
      var curFile = current.split('/').pop();
      if (file && file === curFile) {
        score = file.length;
      }
    }
    if (score > bestScore) {
      bestScore = score;
      best = a;
    }
    a.addEventListener('click', function() {
      var hrefStore = a.getAttribute('href') || '';
      try { sessionStorage.setItem('nav_active_href', hrefStore); } catch(e) {}
      setActive(a);
      if (window.innerWidth < 768 && btn) {
        nav.classList.add('hidden');
        btn.setAttribute('aria-expanded', 'false');
      }
    });
  });
  var stored = null;
  try { stored = sessionStorage.getItem('nav_active_href') || null; } catch(e) {}
  var storedMatch = stored ? links.find(function(a){ return (a.getAttribute('href') || '') === stored; }) : null;
  setActive(storedMatch || best);
})();
</script>";
    }
}

function render_footer(): void
{
    // Load unified UI JavaScript for modals, card expansion, etc.
    echo "<script src='" . BASE_URL . "/assets/js/dpvhub-ui.js?v=20251218'></script>";
    echo "<footer class='sticky-footer text-center text-sm text-slate-600'>";
    echo "Designed &amp; Developed with <span class='heart-beat'>&hearts;</span> by <a class='text-emerald-700 font-semibold' href='https://alavi.vip/' target='_blank'>Mohammad Taghi Alavi</a>";
    echo "</footer>";
    echo "</body></html>";
}
