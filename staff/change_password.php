<?php
/**
 * staff/change_password.php
 * ---------------------------------------------------------------------------
 * Staff — Change Password
 * Rajagiri College Grievance Redressal Portal
 *
 * Handles:
 *   • Auth guard (TEACHER / NON_TEACHING only)
 *   • POST processing: verify current password → validate new → update BCRYPT hash
 *   • Auto-redirect to staff/dashboard.php after successful password change
 *   • Real-time password strength meter
 *   • Confirm password live match indicator
 *   • Header profile dropdown (avatar, name, email, links, logout)
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. SESSION START
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// 2. AUTH GUARD (Staff only — TEACHER or NON_TEACHING)
// ---------------------------------------------------------------------------
$sessionRole = isset($_SESSION['role']) ? strtoupper((string) $_SESSION['role']) : '';

if (empty($_SESSION['user_id']) || !in_array($sessionRole, ['TEACHER', 'NON_TEACHING'], true)) {
    header('Location: ../login.php?role=staff');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// ---------------------------------------------------------------------------
// 3. DATABASE CONNECTION
// ---------------------------------------------------------------------------
$dbFile = __DIR__ . '/../db_connect.php';

if (!file_exists($dbFile)) {
    die('Database configuration file (db_connect.php) not found.');
}

require_once $dbFile;

$dbError = null;

if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = @new mysqli('localhost', 'root', '', 'grievance_db');

    if ($conn->connect_error) {
        $dbError = 'Database connection failed.';
        $conn    = null;
    } else {
        $conn->set_charset('utf8mb4');
    }
}

if ($conn && $conn->connect_errno) {
    $dbError = 'Database connection failed.';
    $conn    = null;
}

// ---------------------------------------------------------------------------
// 4. HELPER — HTML ESCAPE
// ---------------------------------------------------------------------------
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// 5. FETCH STAFF DETAILS (for header chip + avatar + dropdown)
// ---------------------------------------------------------------------------
$staffData = [
    'username'      => $_SESSION['username'] ?? 'Staff',
    'name'          => '',
    'email'         => '',
    'profile_image' => '',
];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  u.username,
                        s.name,
                        s.email,
                        s.profile_image
                FROM users u
                LEFT JOIN staff s ON s.user_id = u.id
                WHERE u.id = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $staffData['username']      = $row['username']      ?? $staffData['username'];
                $staffData['name']          = $row['name']          ?? '';
                $staffData['email']         = $row['email']         ?? '';
                $staffData['profile_image'] = $row['profile_image'] ?? '';
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Staff Change Password Profile] ' . $ex->getMessage());
    }
}

$displayName  = !empty($staffData['name']) ? $staffData['name'] : $staffData['username'];
$displayEmail = !empty($staffData['email']) ? $staffData['email'] : 'staff@rajagiri.edu';

// ---------------------------------------------------------------------------
// PROFILE PICTURE RESOLUTION
//   Files stored at  <project-root>/uploads/profiles/...
//   From staff/ the browser URL is  ../uploads/profiles/...
// ---------------------------------------------------------------------------
$hasProfilePicture = false;
$profilePictureUrl = '';

if (!empty($staffData['profile_image'])) {
    $relative     = ltrim((string) $staffData['profile_image'], '/');
    $absolutePath = __DIR__ . '/../' . $relative;
    $browserPath  = '../' . $relative;

    if (file_exists($absolutePath) && is_file($absolutePath)) {
        $hasProfilePicture = true;
        $profilePictureUrl = $browserPath;
    }
}

// ---------------------------------------------------------------------------
// 6. HANDLE POST SUBMISSION
// ---------------------------------------------------------------------------
$successMessage  = '';
$formErrors      = [];
$passwordChanged = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ----- Collect & Sanitize -----
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword     = (string) ($_POST['new_password']     ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    // ----- Validation -----
    if ($currentPassword === '') {
        $formErrors[] = 'Current password is required.';
    }

    if ($newPassword === '') {
        $formErrors[] = 'New password is required.';
    } elseif (strlen($newPassword) < 6) {
        $formErrors[] = 'New password must be at least 6 characters.';
    }

    if ($confirmPassword === '') {
        $formErrors[] = 'Please confirm your new password.';
    } elseif ($newPassword !== $confirmPassword) {
        $formErrors[] = 'New password and Confirm password do not match.';
    }

    // ----- Fetch current hash & verify -----
    if (empty($formErrors) && $conn !== null) {
        try {
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $user        = $res->fetch_assoc();
                $currentHash = $user['password'] ?? '';

                if (!password_verify($currentPassword, $currentHash)) {
                    $formErrors[] = 'Current password is incorrect.';
                } elseif ($currentPassword === $newPassword) {
                    $formErrors[] = 'New password must be different from the current password.';
                } else {
                    // ----- Update password -----
                    $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

                    $stmtUpd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmtUpd->bind_param('si', $hashedPassword, $userId);

                    if ($stmtUpd->execute()) {
                        $successMessage  = 'Your password has been changed successfully.';
                        $passwordChanged = true;
                    } else {
                        $formErrors[] = 'Failed to update the password. Please try again.';
                    }
                    $stmtUpd->close();
                }
            } else {
                $formErrors[] = 'Unable to locate your account. Please try again.';
            }
            $stmt->close();

        } catch (Throwable $ex) {
            error_log('[Staff Change Password] ' . $ex->getMessage());
            $formErrors[] = 'A system error occurred. Please try again.';
        }
    }

    if ($dbError !== null && empty($formErrors)) {
        $formErrors[] = $dbError;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Change staff password — Rajagiri College Grievance Redressal Portal." />
  <meta name="theme-color" content="#DB0878" />
  <title>Change Password — Staff | Rajagiri College Grievance Portal</title>
  <link rel="icon" type="image/svg+xml" href="../public/favicon.svg" />

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700;12..96,800&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">

  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>

  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brandPurple: '#4A154B', brandPink: '#E5097F', brandGreen: '#006837', brandGold: '#C5A059',
            hot: '#DB0878', hotdark: '#B70664', sun: '#FFC93C', grape: '#4A154B', leaf: '#006837', ink: '#1F0A24', blush: '#FFF0F7'
          },
          fontFamily: {
            display: ['"Bricolage Grotesque"', 'system-ui', 'sans-serif'],
            sans: ['Figtree', 'system-ui', 'sans-serif']
          },
          keyframes: {
            countdown: {
              '0%':   { width: '100%' },
              '100%': { width: '0%' }
            }
          },
          animation: {
            'countdown': 'countdown 3s linear forwards'
          }
        }
      }
    };
  </script>

  <link rel="stylesheet" href="../assets/css/index.css" />

  <style>
    html { scroll-behavior: smooth; }
    a:focus-visible, button:focus-visible, input:focus-visible { outline: 3px solid #1F0A24; outline-offset: 3px; }
    .on-ink a:focus-visible, .on-ink button:focus-visible { outline-color: #fff; }
    .balance { text-wrap: balance; }

    .btn-hard {
      border: 2px solid #1F0A24; box-shadow: 4px 4px 0 #1F0A24;
      transition: transform .12s ease, box-shadow .12s ease, background-color .15s ease;
    }
    .btn-hard:hover  { transform: translate(2px, 2px); box-shadow: 2px 2px 0 #1F0A24; }
    .btn-hard:active { transform: translate(4px, 4px); box-shadow: 0 0 0 #1F0A24; }

    @keyframes rise { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: none; } }
    .rise { animation: rise .6s cubic-bezier(.2, .7, .2, 1) both; }
    .rise-2 { animation-delay: .12s; }

    @keyframes modalIn { from { opacity: 0; transform: scale(.96); } to { opacity: 1; transform: scale(1); } }
    .animate-modal-in { animation: modalIn .25s cubic-bezier(.16, 1, .3, 1) forwards; }

    @keyframes confirmShake {
      0%, 100% { transform: translateX(0); } 20% { transform: translateX(-6px); } 40% { transform: translateX(6px); }
      60% { transform: translateX(-4px); } 80% { transform: translateX(4px); }
    }
    .animate-confirm-shake { animation: confirmShake .5s cubic-bezier(.16, 1, .3, 1); }

    .nav-icon-link { transition: transform .18s ease, background-color .18s ease, box-shadow .18s ease; }
    .nav-icon-link:hover { transform: translateY(-2px); }

    @media (prefers-reduced-motion: reduce) {
      html { scroll-behavior: auto; }
      *, *::before, *::after { animation: none !important; transition: none !important; }
    }
  </style>
</head>

<body class="min-h-screen bg-white text-slate-700 font-sans antialiased selection:bg-sun selection:text-ink">

  <!-- Oréll Grievance logo symbol -->
  <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="orel-logo" viewBox="0 0 128 40" style="overflow:visible">
      <path fill="#DB0878" d="M10 0H26A10 10 0 0 1 36 10V20A10 10 0 0 1 26 30H16L8 38V29.8A10 10 0 0 1 0 20V10A10 10 0 0 1 10 0Z"/>
      <path d="M10 15.5l5.5 5.5L27 9.5" fill="none" stroke="#FFC93C" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round"/>
      <text x="46" y="21" font-family="Bricolage Grotesque, system-ui, sans-serif" font-size="23" font-weight="800" fill="#1F0A24">Oréll</text>
      <text x="46.5" y="35" font-family="Figtree, system-ui, sans-serif" font-size="11" font-weight="700" letter-spacing="0.6" fill="#DB0878">Grievance</text>
    </symbol>
  </svg>

  <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[60] focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-ink focus:shadow-lg">Skip to main content</a>

  <div class="flex min-h-screen">

    <!-- SIDEBAR -->
    <aside id="staffSidebar"
           class="w-20 on-ink bg-ink flex flex-col py-4 fixed inset-y-0 left-0 z-40
                  transition-all duration-300 ease-in-out overflow-hidden">

      <div class="absolute top-0 left-0 right-0 flex h-1.5" aria-hidden="true">
        <span class="flex-1 bg-hot"></span><span class="flex-1 bg-sun"></span><span class="flex-1 bg-leaf"></span><span class="flex-1 bg-grape"></span>
      </div>

      <button id="sidebarToggle"
              class="text-white/80 hover:text-white mt-6 mb-6 p-2 rounded-lg hover:bg-white/10 transition-colors flex items-center justify-center w-14 mx-auto"
              aria-label="Toggle sidebar">
        <i data-lucide="menu" class="w-6 h-6 flex-shrink-0"></i>
      </button>

      <nav class="flex flex-col space-y-2 flex-1 w-full px-3">

        <a href="dashboard.php"
           class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center text-white nav-icon-link px-3">
          <i data-lucide="home" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Dashboard</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-ink text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50 border border-white/10">Dashboard</span>
        </a>

        <a href="profile.php"
           class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center text-white nav-icon-link px-3">
          <i data-lucide="user" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">My Profile</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-ink text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50 border border-white/10">My Profile</span>
        </a>

        <a href="change_password.php"
           class="group relative w-full h-12 rounded-xl bg-hot ring-2 ring-sun/60 flex items-center text-white nav-icon-link px-3">
          <i data-lucide="key" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Change Password</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-ink text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50 border border-white/10">Change Password</span>
        </a>

      </nav>

      <a href="#" data-logout-trigger="1" id="sidebarLogoutBtn"
         class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-hot flex items-center text-white nav-icon-link mx-3 px-3"
         style="width: calc(100% - 1.5rem);" title="Logout">
        <i data-lucide="log-out" class="w-6 h-6 flex-shrink-0"></i>
        <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Logout</span>
        <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-hot text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50">Logout</span>
      </a>
    </aside>

    <!-- MAIN -->
    <div id="staffMain" class="flex-1 ml-20 flex flex-col min-h-screen transition-all duration-300">

      <header class="sticky top-0 z-30 bg-white border-b border-slate-200">
        <div class="flex h-1.5" aria-hidden="true">
          <span class="flex-1 bg-hot"></span><span class="flex-1 bg-sun"></span><span class="flex-1 bg-leaf"></span><span class="flex-1 bg-grape"></span>
        </div>

        <div class="flex items-center justify-between px-4 sm:px-6 py-3 md:py-4">
          <div class="flex items-center gap-4">
            <a href="dashboard.php" class="flex items-center gap-4 rounded-lg">
              <img src="../public/rcss-logo.png" alt="RCSS Logo" class="h-10 md:h-12 w-auto" />
              <span class="hidden sm:block h-8 w-px bg-slate-200" aria-hidden="true"></span>
              <svg class="hidden sm:block h-9 md:h-10 w-auto" width="128" height="40" viewBox="0 0 128 40" role="img" aria-label="Oréll Grievance"><use href="#orel-logo"></use></svg>
            </a>
          </div>

          <div class="relative" id="staff-dropdown-container">
            <button id="staff-dropdown-btn" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="staff-dropdown-menu"
                    class="flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 transition-colors hover:bg-blush">
              <?php if ($hasProfilePicture): ?>
                <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>" class="w-10 h-10 rounded-full object-cover border-2 border-ink shadow-[2px_2px_0_#FFC93C]" />
              <?php else: ?>
                <span class="w-10 h-10 rounded-full bg-hot flex items-center justify-center text-white border-2 border-ink shadow-[2px_2px_0_#FFC93C]">
                  <i data-lucide="user" class="w-5 h-5 text-white"></i>
                </span>
              <?php endif; ?>
              <span class="hidden sm:block text-sm font-bold text-ink"><?= e($displayName) ?></span>
              <i data-lucide="chevron-down" id="staff-chevron" class="w-4 h-4 text-slate-500 transition-transform duration-300"></i>
            </button>

            <div id="staff-dropdown-menu" class="hidden absolute right-0 z-50 mt-3 w-72 rounded-xl border-2 border-ink bg-white p-2 shadow-[6px_6px_0_#FFC93C]" role="menu">
              <div class="rounded-lg bg-blush px-3 py-3">
                <div class="flex items-center gap-3">
                  <?php if ($hasProfilePicture): ?>
                    <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>" class="w-12 h-12 rounded-full object-cover border-2 border-ink" />
                  <?php else: ?>
                    <span class="w-12 h-12 rounded-full bg-hot flex items-center justify-center text-white border-2 border-ink"><i data-lucide="user" class="w-6 h-6 text-white"></i></span>
                  <?php endif; ?>
                  <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-bold text-ink"><?= e($displayName) ?></p>
                    <p class="truncate text-xs text-slate-600"><?= e($displayEmail) ?></p>
                  </div>
                </div>
              </div>

              <a href="dashboard.php" role="menuitem" class="mt-1 flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 transition-colors hover:bg-blush hover:text-ink group">
                <i data-lucide="layout-dashboard" class="w-4 h-4 text-hot group-hover:scale-110 transition-transform"></i>
                <span class="font-semibold">Dashboard</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-ink transition-opacity"></i>
              </a>
              <a href="profile.php" role="menuitem" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 transition-colors hover:bg-blush hover:text-ink group">
                <i data-lucide="user" class="w-4 h-4 text-hot group-hover:scale-110 transition-transform"></i>
                <span class="font-semibold">My Profile</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-ink transition-opacity"></i>
              </a>
              <a href="change_password.php" role="menuitem" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-ink bg-blush font-bold">
                <i data-lucide="key" class="w-4 h-4 text-hot"></i>
                <span>Change Password</span>
              </a>

              <div class="my-2 border-t border-slate-200" role="separator"></div>

              <a href="#" data-logout-trigger="1" id="dropdownLogoutBtn" role="menuitem" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 transition-colors hover:bg-blush hover:text-hot group">
                <i data-lucide="log-out" class="w-4 h-4 text-hot group-hover:scale-110 transition-transform"></i>
                <span class="font-semibold">Logout</span>
              </a>
            </div>
          </div>
        </div>
      </header>

      <!-- PAGE CONTENT -->
      <main id="main-content" class="flex-1 px-4 sm:px-6 py-8">

        <div class="max-w-5xl mx-auto mb-6 rise">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
              <h1 class="font-display text-2xl md:text-3xl font-extrabold tracking-tight text-ink mb-2">Change Password</h1>
              <nav class="flex flex-wrap items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
                <a href="dashboard.php" class="flex items-center rounded transition-colors hover:text-hot"><i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i> Dashboard</a>
                <span class="text-slate-300">/</span>
                <span class="text-hot font-semibold">Change Password</span>
              </nav>
            </div>
          </div>
        </div>

        <!-- Success banner (auto-redirect countdown) -->
        <?php if ($passwordChanged && $successMessage !== ''): ?>
          <div id="success-banner"
               class="max-w-2xl mx-auto mb-6 rounded-xl border-2 border-leaf bg-[#DCEFE4] px-5 py-4 flex items-start gap-3 rise">
            <span class="w-10 h-10 rounded-full bg-leaf border-2 border-ink flex items-center justify-center flex-shrink-0">
              <i data-lucide="check-circle" class="w-6 h-6 text-white"></i>
            </span>
            <div class="flex-1">
              <p class="text-sm font-bold text-leaf">
                <?= e($successMessage) ?>
              </p>
              <p class="text-xs text-leaf/90 mt-1 font-semibold">
                Redirecting you to the dashboard in <span id="countdown-text">3</span> seconds…
              </p>
              <div class="h-1 bg-white/60 rounded-full overflow-hidden mt-3 border border-leaf/30">
                <div class="h-full bg-leaf animate-countdown"></div>
              </div>
            </div>
          </div>

          <script>
            (function () {
              var seconds = 3;
              var textEl = document.getElementById('countdown-text');
              var interval = setInterval(function () {
                seconds--;
                if (textEl) textEl.textContent = seconds > 0 ? seconds : 0;
                if (seconds <= 0) {
                  clearInterval(interval);
                  window.location.href = 'dashboard.php';
                }
              }, 1000);
            })();
          </script>
        <?php endif; ?>

        <!-- Errors -->
        <?php if (!empty($formErrors)): ?>
          <div class="max-w-2xl mx-auto mb-6 rounded-xl border-2 border-red-300 bg-red-50 px-4 py-3 flex items-start gap-2 rise">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <div class="text-sm text-red-700 space-y-1 font-semibold">
              <?php foreach ($formErrors as $err): ?>
                <p><?= e($err) ?></p>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- CHANGE PASSWORD CARD -->
        <div class="max-w-2xl mx-auto rise rise-2">
          <div class="bg-white rounded-2xl border-2 border-ink overflow-hidden shadow-[8px_8px_0_#FFC93C]">

            <!-- Card header -->
            <div class="flex items-center gap-3 px-6 py-4 border-b-2 border-ink bg-blush">
              <span class="w-12 h-12 rounded-xl bg-hot border-2 border-ink flex items-center justify-center flex-shrink-0">
                <i data-lucide="shield" class="w-6 h-6 text-white"></i>
              </span>
              <div>
                <h2 class="font-display text-lg font-bold text-ink">Update Your Password</h2>
                <p class="text-sm text-slate-600 mt-0.5">Ensure your account security with a strong password</p>
              </div>
            </div>

            <form action="change_password.php" method="POST" class="p-6 sm:p-8 space-y-6" novalidate>

              <!-- Current Password -->
              <div class="space-y-2">
                <label for="current_password" class="flex items-center text-sm font-semibold text-ink">
                  Current Password <span class="text-hot ml-1">*</span>
                </label>
                <div class="relative">
                  <i data-lucide="lock" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400 pointer-events-none"></i>
                  <input type="password" id="current_password" name="current_password" required
                         autocomplete="current-password" placeholder="Enter your current password"
                         class="w-full pl-11 pr-12 py-3 border-2 border-ink rounded-xl bg-white
                                focus:outline-none focus:ring-4 focus:ring-hot/10
                                transition-all text-ink font-medium placeholder-slate-400" />
                  <button type="button" onclick="togglePasswordVisibility('current_password', this)"
                          class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-hot transition-colors"
                          aria-label="Toggle password visibility">
                    <i data-lucide="eye" class="w-5 h-5"></i>
                  </button>
                </div>
              </div>

              <!-- New Password -->
              <div class="space-y-2">
                <label for="new_password" class="flex items-center text-sm font-semibold text-ink">
                  New Password <span class="text-hot ml-1">*</span>
                </label>
                <div class="relative">
                  <i data-lucide="key" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400 pointer-events-none"></i>
                  <input type="password" id="new_password" name="new_password" required minlength="6"
                         autocomplete="new-password" placeholder="Enter new password"
                         oninput="updatePasswordStrength(this.value)"
                         class="w-full pl-11 pr-12 py-3 border-2 border-ink rounded-xl bg-white
                                focus:outline-none focus:ring-4 focus:ring-hot/10
                                transition-all text-ink font-medium placeholder-slate-400" />
                  <button type="button" onclick="togglePasswordVisibility('new_password', this)"
                          class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-hot transition-colors"
                          aria-label="Toggle password visibility">
                    <i data-lucide="eye" class="w-5 h-5"></i>
                  </button>
                </div>

                <!-- Strength meter -->
                <div id="strength-wrapper" class="mt-3 space-y-2 hidden">
                  <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold text-slate-600">Password Strength</span>
                    <span id="strength-text" class="text-xs font-bold text-slate-500">Weak</span>
                  </div>
                  <div class="h-2 bg-slate-200 rounded-full overflow-hidden">
                    <div id="strength-bar" class="h-full transition-all duration-500 ease-out bg-red-500" style="width: 0%;"></div>
                  </div>
                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-3">
                    <div class="flex items-center gap-2 req-row" data-req="minLength">
                      <i data-lucide="alert-circle" class="w-4 h-4 text-slate-300 flex-shrink-0"></i>
                      <span class="text-xs text-slate-500">At least 8 characters</span>
                    </div>
                    <div class="flex items-center gap-2 req-row" data-req="hasUppercase">
                      <i data-lucide="alert-circle" class="w-4 h-4 text-slate-300 flex-shrink-0"></i>
                      <span class="text-xs text-slate-500">One uppercase letter</span>
                    </div>
                    <div class="flex items-center gap-2 req-row" data-req="hasLowercase">
                      <i data-lucide="alert-circle" class="w-4 h-4 text-slate-300 flex-shrink-0"></i>
                      <span class="text-xs text-slate-500">One lowercase letter</span>
                    </div>
                    <div class="flex items-center gap-2 req-row" data-req="hasNumber">
                      <i data-lucide="alert-circle" class="w-4 h-4 text-slate-300 flex-shrink-0"></i>
                      <span class="text-xs text-slate-500">One number</span>
                    </div>
                    <div class="flex items-center gap-2 req-row" data-req="hasSpecialChar">
                      <i data-lucide="alert-circle" class="w-4 h-4 text-slate-300 flex-shrink-0"></i>
                      <span class="text-xs text-slate-500">One special character</span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Confirm Password -->
              <div class="space-y-2">
                <label for="confirm_password" class="flex items-center text-sm font-semibold text-ink">
                  Confirm Password <span class="text-hot ml-1">*</span>
                </label>
                <div class="relative">
                  <i data-lucide="lock" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400 pointer-events-none"></i>
                  <input type="password" id="confirm_password" name="confirm_password" required
                         autocomplete="new-password" placeholder="Re-enter new password"
                         oninput="checkPasswordMatch()"
                         class="w-full pl-11 pr-14 py-3 border-2 border-ink rounded-xl bg-white
                                focus:outline-none focus:ring-4 focus:ring-hot/10
                                transition-all text-ink font-medium placeholder-slate-400" />
                  <div id="confirm-check" class="absolute right-11 top-1/2 -translate-y-1/2 pointer-events-none hidden">
                    <i data-lucide="check-circle" class="w-5 h-5 text-leaf"></i>
                  </div>
                  <button type="button" onclick="togglePasswordVisibility('confirm_password', this)"
                          class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-hot transition-colors"
                          aria-label="Toggle password visibility">
                    <i data-lucide="eye" class="w-5 h-5"></i>
                  </button>
                </div>
                <p id="match-error" class="text-sm text-red-500 font-semibold flex items-center mt-1 hidden">
                  <i data-lucide="alert-circle" class="w-4 h-4 mr-1"></i>
                  Passwords do not match
                </p>
              </div>

              <!-- Actions -->
              <div class="flex flex-col-reverse sm:flex-row justify-end items-center gap-3 pt-5 border-t-2 border-slate-100">

                <a href="dashboard.php"
                   class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl border-2 border-ink bg-white px-6 py-3 text-sm font-bold text-ink transition-colors hover:bg-slate-50">
                  <i data-lucide="arrow-left" class="w-4 h-4"></i>
                  <span>Back to Dashboard</span>
                </a>

                <button type="submit"
                        class="btn-hard w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-hot px-7 py-3 text-sm font-bold text-white hover:bg-hotdark">
                  <i data-lucide="shield" class="w-5 h-5"></i>
                  <span>Change Password</span>
                </button>

              </div>

              <!-- Security tips -->
              <div class="mt-6 p-4 bg-blush rounded-xl border-2 border-ink">
                <h3 class="text-sm font-bold text-ink mb-2 flex items-center gap-2">
                  <i data-lucide="shield" class="w-4 h-4 text-hot"></i>
                  Security Tips
                </h3>
                <ul class="text-xs text-slate-700 space-y-1 list-disc list-inside marker:text-hot">
                  <li>Never share your password with anyone</li>
                  <li>Use a unique password for each account</li>
                  <li>Change your password regularly (every 90 days)</li>
                  <li>Avoid using personal information in your password</li>
                </ul>
              </div>

            </form>

          </div>
        </div>

      </main>

      <!-- FOOTER -->
      <footer class="on-ink bg-ink border-t-[6px] border-sun mt-auto">
        <div class="px-4 sm:px-6 py-8">
          <div class="max-w-7xl mx-auto">
            <div class="flex flex-col sm:flex-row items-center justify-between gap-3 border-t border-white/15 pt-6">
              <p class="text-xs text-slate-400 text-center sm:text-left">
                &copy; <?= date('Y') ?>
                <span class="font-bold text-white">Rajagiri College of Social Sciences</span>.
                All rights reserved.
              </p>
              <p class="text-xs text-slate-400">
                Powered by <span class="font-bold text-sun ml-1">Oréll Grievance</span>
              </p>
            </div>
          </div>
        </div>
      </footer>

    </div>
  </div>

  <!-- LOGOUT MODAL -->
  <div id="logoutConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeLogoutModal()"></div>
    <div id="logoutConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-red-500"></div>
      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <span class="w-16 h-16 rounded-full bg-red-50 border-2 border-ink flex items-center justify-center mb-4">
          <i data-lucide="log-out" class="w-8 h-8 text-red-500"></i>
        </span>
        <h3 class="font-display text-xl font-bold text-ink mb-2">Log Out?</h3>
        <p class="text-sm text-slate-500 leading-relaxed">
          You are about to log out of
          <span class="font-bold text-hot break-words"><?= e($displayName) ?></span>.
        </p>
        <p class="text-xs text-slate-500 font-semibold mt-3 flex items-center gap-1.5">
          <i data-lucide="info" class="w-3.5 h-3.5"></i> You can log back in anytime.
        </p>
      </div>
      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeLogoutModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">Cancel</button>
        <button type="button" id="confirmLogoutBtn"
                class="btn-hard flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-red-500 px-5 py-3 text-sm font-bold text-white hover:bg-red-600">
          <i data-lucide="log-out" class="w-4 h-4"></i> Log Out
        </button>
      </div>
    </div>
  </div>

  <script>
    if (typeof lucide !== 'undefined') lucide.createIcons();

    // ---- Toggle Password Visibility ----
    function togglePasswordVisibility(inputId, btn) {
      const input = document.getElementById(inputId);
      if (!input) return;
      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';
      const icon = btn.querySelector('i');
      if (icon && typeof lucide !== 'undefined') {
        icon.setAttribute('data-lucide', isHidden ? 'eye-off' : 'eye');
        lucide.createIcons({ targets: [icon] });
      }
    }

    // ---- Password Strength Meter ----
    function updatePasswordStrength(password) {
      const wrapper = document.getElementById('strength-wrapper');
      const bar     = document.getElementById('strength-bar');
      const text    = document.getElementById('strength-text');
      if (!wrapper || !bar || !text) return;

      if (password === '') { wrapper.classList.add('hidden'); return; }
      wrapper.classList.remove('hidden');

      const reqs = {
        minLength:      password.length >= 8,
        hasUppercase:   /[A-Z]/.test(password),
        hasLowercase:   /[a-z]/.test(password),
        hasNumber:      /[0-9]/.test(password),
        hasSpecialChar: /[!@#$%^&*(),.?":{}|<>]/.test(password),
      };

      const score = Object.values(reqs).filter(Boolean).length;
      bar.style.width = (score / 5) * 100 + '%';

      bar.classList.remove('bg-red-500', 'bg-yellow-500', 'bg-blue-500', 'bg-leaf');
      text.classList.remove('text-red-500', 'text-yellow-500', 'text-blue-500', 'text-leaf');

      if (score <= 2)       { bar.classList.add('bg-red-500');    text.classList.add('text-red-500');    text.textContent = 'Weak'; }
      else if (score <= 3)  { bar.classList.add('bg-yellow-500'); text.classList.add('text-yellow-500'); text.textContent = 'Fair'; }
      else if (score <= 4)  { bar.classList.add('bg-blue-500');   text.classList.add('text-blue-500');   text.textContent = 'Good'; }
      else                  { bar.classList.add('bg-leaf');       text.classList.add('text-leaf');       text.textContent = 'Strong'; }

      document.querySelectorAll('.req-row').forEach(function (row) {
        const key = row.getAttribute('data-req');
        const icon = row.querySelector('i');
        const label = row.querySelector('span');
        if (!key || !icon) return;
        const met = !!reqs[key];
        icon.setAttribute('data-lucide', met ? 'check-circle' : 'alert-circle');
        icon.classList.toggle('text-leaf', met);
        icon.classList.toggle('text-slate-300', !met);
        if (label) {
          label.classList.toggle('text-leaf', met);
          label.classList.toggle('font-semibold', met);
          label.classList.toggle('text-slate-500', !met);
        }
      });

      if (typeof lucide !== 'undefined') {
        lucide.createIcons({ targets: document.querySelectorAll('.req-row i') });
      }
    }

    // ---- Confirm Password Match ----
    function checkPasswordMatch() {
      const newPwd     = document.getElementById('new_password').value;
      const confirmPwd = document.getElementById('confirm_password').value;
      const checkIcon  = document.getElementById('confirm-check');
      const errorMsg   = document.getElementById('match-error');
      const confirmInp = document.getElementById('confirm_password');

      if (confirmPwd === '') {
        checkIcon.classList.add('hidden');
        errorMsg.classList.add('hidden');
        confirmInp.classList.remove('border-leaf', 'border-red-400');
        confirmInp.classList.add('border-ink');
        return;
      }

      if (newPwd === confirmPwd) {
        checkIcon.classList.remove('hidden');
        errorMsg.classList.add('hidden');
        confirmInp.classList.remove('border-ink', 'border-red-400');
        confirmInp.classList.add('border-leaf');
      } else {
        checkIcon.classList.add('hidden');
        errorMsg.classList.remove('hidden');
        confirmInp.classList.remove('border-ink', 'border-leaf');
        confirmInp.classList.add('border-red-400');
      }
    }

    // ---- Sidebar expand / collapse ----
    (function () {
      const toggleBtn = document.getElementById('sidebarToggle');
      const sidebar   = document.getElementById('staffSidebar');
      const main      = document.getElementById('staffMain');
      if (!toggleBtn || !sidebar || !main) return;

      const labels   = sidebar.querySelectorAll('.sidebar-label');
      const tooltips = sidebar.querySelectorAll('.sidebar-tooltip');
      let expanded = false;

      toggleBtn.addEventListener('click', function () {
        expanded = !expanded;
        if (expanded) {
          sidebar.classList.remove('w-20'); sidebar.classList.add('w-64');
          main.classList.remove('ml-20');  main.classList.add('ml-64');
          labels.forEach(function (el) { el.classList.remove('opacity-0', 'w-0'); el.classList.add('opacity-100', 'w-auto'); });
          tooltips.forEach(function (el) { el.classList.add('hidden'); });
        } else {
          sidebar.classList.add('w-20'); sidebar.classList.remove('w-64');
          main.classList.add('ml-20');  main.classList.remove('ml-64');
          labels.forEach(function (el) { el.classList.add('opacity-0', 'w-0'); el.classList.remove('opacity-100', 'w-auto'); });
          tooltips.forEach(function (el) { el.classList.remove('hidden'); });
        }
        setTimeout(function () { if (typeof lucide !== 'undefined') lucide.createIcons(); }, 250);
      });
    })();

    // ---- Staff profile dropdown ----
    (function () {
      const btn       = document.getElementById('staff-dropdown-btn');
      const menu      = document.getElementById('staff-dropdown-menu');
      const chevron   = document.getElementById('staff-chevron');
      const container = document.getElementById('staff-dropdown-container');
      if (!btn || !menu || !container) return;

      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        const isOpen = !menu.classList.contains('hidden');
        if (isOpen) { menu.classList.add('hidden'); if (chevron) chevron.classList.remove('rotate-180'); btn.setAttribute('aria-expanded', 'false'); }
        else { menu.classList.remove('hidden'); if (chevron) chevron.classList.add('rotate-180'); btn.setAttribute('aria-expanded', 'true'); }
      });
      document.addEventListener('click', function (e) {
        if (!container.contains(e.target)) { menu.classList.add('hidden'); if (chevron) chevron.classList.remove('rotate-180'); btn.setAttribute('aria-expanded', 'false'); }
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { menu.classList.add('hidden'); if (chevron) chevron.classList.remove('rotate-180'); btn.setAttribute('aria-expanded', 'false'); }
      });
    })();

    // ---- Logout modal ----
    const logoutConfirmModal = document.getElementById('logoutConfirmModal');
    const logoutConfirmPanel = document.getElementById('logoutConfirmPanel');
    const confirmLogoutBtn   = document.getElementById('confirmLogoutBtn');
    const LOGOUT_URL = '../logout.php?role=staff';

    function openLogoutModal() {
      logoutConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      if (logoutConfirmPanel) {
        logoutConfirmPanel.classList.remove('animate-confirm-shake');
        void logoutConfirmPanel.offsetWidth;
        logoutConfirmPanel.classList.add('animate-confirm-shake');
      }
      setTimeout(function () { if (confirmLogoutBtn) confirmLogoutBtn.focus(); }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeLogoutModal() {
      logoutConfirmModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }
    [document.getElementById('sidebarLogoutBtn'), document.getElementById('dropdownLogoutBtn')].forEach(function (btn) {
      if (!btn) return;
      btn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); openLogoutModal(); });
    });
    if (confirmLogoutBtn) confirmLogoutBtn.addEventListener('click', function () {
      confirmLogoutBtn.classList.add('opacity-50', 'pointer-events-none');
      window.location.href = LOGOUT_URL;
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) closeLogoutModal();
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>