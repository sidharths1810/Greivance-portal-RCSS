<?php
/**
 * admin/change_password.php
 * ---------------------------------------------------------------------------
 * Admin — Change Password
 * Rajagiri College Grievance Redressal Portal
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

$sessionRole = isset($_SESSION['role']) ? strtoupper((string) $_SESSION['role']) : '';

if (empty($_SESSION['user_id']) || $sessionRole !== 'ADMIN') {
    header('Location: ../login.php?role=admin');
    exit;
}

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

$userId = (int) $_SESSION['user_id'];

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$successMessage  = '';
$formErrors      = [];
$passwordChanged = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword     = (string) ($_POST['new_password']     ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

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

    if (empty($formErrors) && $conn !== null) {
        try {
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $user = $res->fetch_assoc();
                $currentHash = $user['password'] ?? '';

                if (!password_verify($currentPassword, $currentHash)) {
                    $formErrors[] = 'Current password is incorrect.';
                } elseif ($currentPassword === $newPassword) {
                    $formErrors[] = 'New password must be different from the current password.';
                } else {
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
            error_log('[Change Password] ' . $ex->getMessage());
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
  <meta name="description" content="Change password — Rajagiri College Grievance Redressal Portal." />
  <meta name="theme-color" content="#DB0878" />
  <title>Change Password — Admin | Rajagiri College Grievance Portal</title>
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
          }
        }
      }
    };
  </script>

  <link rel="stylesheet" href="../assets/css/index.css" />

  <style>
    html { scroll-behavior: smooth; }
    a:focus-visible, button:focus-visible, input:focus-visible, textarea:focus-visible, select:focus-visible {
      outline: 3px solid #1F0A24; outline-offset: 3px;
    }
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

    .strength-bar { display: block; height: 100%; width: 0; background: #006837; transition: width .25s ease, background-color .25s ease; }
    .strength-track { height: 6px; border-radius: 999px; background: #E8DDE6; overflow: hidden; margin-top: 7px; }

    .nav-icon-link { transition: transform .18s ease, background-color .18s ease, box-shadow .18s ease; }
    .nav-icon-link:hover { transform: translateY(-2px); }

    @media (prefers-reduced-motion: reduce) {
      html { scroll-behavior: auto; }
      *, *::before, *::after { animation: none !important; transition: none !important; }
    }
  </style>
</head>

<body class="min-h-screen bg-white text-slate-700 font-sans antialiased selection:bg-sun selection:text-ink">

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

    <aside class="w-20 on-ink bg-ink flex flex-col items-center py-4 fixed inset-y-0 left-0 z-40">
      <div class="absolute top-0 left-0 right-0 flex h-1.5" aria-hidden="true">
        <span class="flex-1 bg-hot"></span><span class="flex-1 bg-sun"></span><span class="flex-1 bg-leaf"></span><span class="flex-1 bg-grape"></span>
      </div>

      <a href="dashboard.php" class="nav-icon-link group relative mt-6 w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white" title="Dashboard" aria-label="Dashboard">
        <i data-lucide="home" class="w-6 h-6"></i>
        <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-ink text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50 border border-white/10">Dashboard</span>
      </a>

      <nav class="flex flex-col items-center space-y-4 flex-1 mt-8" aria-label="Admin navigation">
        <a href="profile.php" class="nav-icon-link group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white" title="Profile" aria-label="Profile">
          <i data-lucide="user" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-ink text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50 border border-white/10">Profile</span>
        </a>
        <a href="change_password.php" class="nav-icon-link group relative w-12 h-12 rounded-xl bg-hot flex items-center justify-center text-white ring-2 ring-sun/60" title="Change Password" aria-label="Change Password">
          <i data-lucide="key-round" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-ink text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50 border border-white/10">Change Password</span>
        </a>
      </nav>

      <a href="../logout.php?role=admin" id="sidebarLogoutBtn" class="nav-icon-link group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-hot flex items-center justify-center text-white" title="Logout" aria-label="Logout">
        <i data-lucide="log-out" class="w-6 h-6 group-hover:translate-x-0.5 transition-transform"></i>
        <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-hot text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50">Logout</span>
      </a>
    </aside>

    <div class="flex-1 ml-20 flex flex-col min-h-screen">

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

          <div class="flex items-center gap-3">
            <span class="w-10 h-10 rounded-full bg-hot flex items-center justify-center text-white border-2 border-ink shadow-[2px_2px_0_#FFC93C]">
              <i data-lucide="user" class="w-5 h-5 text-white"></i>
            </span>
            <span class="hidden sm:block text-sm font-bold text-ink"><?= e($_SESSION['username'] ?? 'Admin') ?></span>
          </div>
        </div>
      </header>

      <main id="main-content" class="flex-1 px-4 sm:px-6 py-8">

        <div class="max-w-5xl mx-auto mb-6 rise">
          <h1 class="font-display text-2xl md:text-3xl font-extrabold tracking-tight text-ink mb-2">Change Password</h1>
          <nav class="flex flex-wrap items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
            <a href="dashboard.php" class="flex items-center rounded transition-colors hover:text-hot"><i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i> Dashboard</a>
            <span class="text-slate-300">/</span>
            <span class="text-hot font-semibold">Change Password</span>
          </nav>
        </div>

        <?php if ($passwordChanged && $successMessage !== ''): ?>
          <div class="max-w-5xl mx-auto mb-6 rounded-xl border-2 border-leaf bg-[#DCEFE4] px-4 py-3 flex items-start gap-2">
            <i data-lucide="check-circle" class="w-5 h-5 text-leaf flex-shrink-0 mt-0.5"></i>
            <div>
              <p class="text-sm text-leaf font-semibold"><?= e($successMessage) ?></p>
              <p class="text-xs text-leaf mt-1">Redirecting to the dashboard in <span id="countdown">3</span> seconds.</p>
            </div>
          </div>
        <?php endif; ?>

        <?php if (!empty($formErrors)): ?>
          <div class="max-w-5xl mx-auto mb-6 rounded-xl border-2 border-red-300 bg-red-50 px-4 py-3 flex items-start gap-2">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <div class="text-sm text-red-700 space-y-1">
              <?php foreach ($formErrors as $err): ?><p><?= e($err) ?></p><?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="max-w-5xl mx-auto grid grid-cols-1 lg:grid-cols-3 gap-6 rise rise-2">

          <div class="lg:col-span-2 bg-white rounded-2xl border-2 border-ink shadow-[6px_6px_0_#FFC93C] overflow-hidden">
            <div class="bg-blush px-6 py-4 border-b-2 border-ink flex items-center justify-between gap-4">
              <div>
                <h2 class="font-display text-lg font-bold text-ink">Update your password</h2>
                <p class="text-xs text-slate-600 mt-1">Use a strong password to keep your administrator account secure.</p>
              </div>
              <span class="w-10 h-10 rounded-xl bg-hot border-2 border-ink flex items-center justify-center flex-shrink-0">
                <i data-lucide="key-round" class="w-5 h-5 text-white"></i>
              </span>
            </div>

            <form action="change_password.php" method="POST" class="p-6 space-y-5" novalidate>

              <div class="space-y-2">
                <label for="current_password" class="block text-sm font-semibold text-ink">Current Password <span class="text-hot">*</span></label>
                <div class="relative">
                  <input class="w-full px-4 py-3 pr-12 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all"
                         type="password" id="current_password" name="current_password" autocomplete="current-password" required placeholder="Enter current password">
                  <button type="button" onclick="togglePassword('current_password', this)" aria-label="Show password"
                          class="absolute right-2 top-1/2 -translate-y-1/2 w-9 h-9 rounded-lg text-slate-400 hover:text-hot hover:bg-blush flex items-center justify-center transition-colors">
                    <i data-lucide="eye" class="w-5 h-5"></i>
                  </button>
                </div>
              </div>

              <div class="space-y-2">
                <label for="new_password" class="block text-sm font-semibold text-ink">New Password <span class="text-hot">*</span></label>
                <div class="relative">
                  <input class="w-full px-4 py-3 pr-12 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all"
                         type="password" id="new_password" name="new_password" minlength="6" autocomplete="new-password" required placeholder="At least 6 characters" oninput="passwordState()">
                  <button type="button" onclick="togglePassword('new_password', this)" aria-label="Show password"
                          class="absolute right-2 top-1/2 -translate-y-1/2 w-9 h-9 rounded-lg text-slate-400 hover:text-hot hover:bg-blush flex items-center justify-center transition-colors">
                    <i data-lucide="eye" class="w-5 h-5"></i>
                  </button>
                </div>
                <div class="strength-track"><i id="strengthBar" class="strength-bar"></i></div>
                <div id="strengthText" class="text-xs text-slate-500">Password strength</div>
              </div>

              <div class="space-y-2">
                <label for="confirm_password" class="block text-sm font-semibold text-ink">Confirm New Password <span class="text-hot">*</span></label>
                <div class="relative">
                  <input class="w-full px-4 py-3 pr-12 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all"
                         type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required placeholder="Re-enter new password" oninput="passwordState()">
                  <button type="button" onclick="togglePassword('confirm_password', this)" aria-label="Show password"
                          class="absolute right-2 top-1/2 -translate-y-1/2 w-9 h-9 rounded-lg text-slate-400 hover:text-hot hover:bg-blush flex items-center justify-center transition-colors">
                    <i data-lucide="eye" class="w-5 h-5"></i>
                  </button>
                </div>
                <div id="matchText" class="text-xs text-slate-500"></div>
              </div>

              <div class="flex justify-end gap-3 pt-3">
                <a href="dashboard.php"
                   class="inline-flex items-center justify-center gap-2 rounded-xl bg-white border-2 border-ink px-6 py-3 text-sm font-bold text-ink hover:bg-blush transition-colors">
                  Cancel
                </a>
                <button class="btn-hard inline-flex items-center justify-center gap-2 rounded-xl bg-hot px-8 py-3 text-sm font-bold text-white hover:bg-hotdark" type="submit">
                  <i data-lucide="save" class="w-4 h-4"></i> Update Password
                </button>
              </div>

            </form>
          </div>

          <aside class="bg-grape text-white rounded-2xl border-2 border-ink shadow-[6px_6px_0_#FFC93C] p-6">
            <span class="w-12 h-12 rounded-xl bg-white/10 border-2 border-white/20 flex items-center justify-center">
              <i data-lucide="shield-check" class="w-6 h-6 text-sun"></i>
            </span>
            <h3 class="font-display text-xl font-bold mt-4">Keep your account protected.</h3>
            <p class="mt-2 text-sm text-white/85 leading-relaxed">Choose a password that is unique to this portal and difficult for others to guess.</p>
            <ul class="list-none p-0 m-0 mt-5 space-y-2.5">
              <li class="flex gap-2.5 text-sm text-white"><i data-lucide="check" class="w-4 h-4 text-sun flex-shrink-0 mt-0.5"></i><span>Use at least 6 characters</span></li>
              <li class="flex gap-2.5 text-sm text-white"><i data-lucide="check" class="w-4 h-4 text-sun flex-shrink-0 mt-0.5"></i><span>Mix letters, numbers and symbols</span></li>
              <li class="flex gap-2.5 text-sm text-white"><i data-lucide="check" class="w-4 h-4 text-sun flex-shrink-0 mt-0.5"></i><span>Never reuse an old password</span></li>
            </ul>
          </aside>

        </div>
      </main>

      <footer class="on-ink bg-ink border-t-[6px] border-sun mt-auto">
        <div class="px-4 sm:px-6 py-10">
          <div class="max-w-7xl mx-auto">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-8">
              <div class="flex items-start gap-4">
                <span class="inline-flex items-center gap-3 rounded-xl bg-white px-3 py-2">
                  <img src="../public/rcss-logo.png" alt="RCSS Logo" class="h-10 w-auto" />
                  <svg class="h-8 w-auto" width="128" height="40" viewBox="0 0 128 40" role="img" aria-label="Oréll Grievance"><use href="#orel-logo"></use></svg>
                </span>
                <div>
                  <p class="font-display font-bold text-white text-sm">Rajagiri College of Social Sciences</p>
                  <p class="text-xs text-slate-300 mt-1">Grievance Redressal Portal</p>
                </div>
              </div>
              <div>
                <h4 class="font-display font-bold text-sm text-sun mb-3">Quick Links</h4>
                <ul class="space-y-2 text-xs text-slate-200">
                  <li><a href="dashboard.php" class="inline-flex items-center gap-2 rounded transition-colors hover:text-white hover:underline underline-offset-4 group"><i data-lucide="arrow-right" class="w-3 h-3 text-sun group-hover:translate-x-0.5 transition-transform"></i><span>Dashboard</span></a></li>
                  <li><a href="profile.php" class="inline-flex items-center gap-2 rounded transition-colors hover:text-white hover:underline underline-offset-4 group"><i data-lucide="arrow-right" class="w-3 h-3 text-sun group-hover:translate-x-0.5 transition-transform"></i><span>My Profile</span></a></li>
                  <li><a href="change_password.php" class="inline-flex items-center gap-2 rounded transition-colors hover:text-white hover:underline underline-offset-4 group"><i data-lucide="arrow-right" class="w-3 h-3 text-sun group-hover:translate-x-0.5 transition-transform"></i><span>Change Password</span></a></li>
                </ul>
              </div>
              <div>
                <h4 class="font-display font-bold text-sm text-sun mb-3">Contact Support</h4>
                <ul class="space-y-2 text-xs text-slate-200">
                  <li class="flex items-center gap-2"><i data-lucide="mail" class="w-3.5 h-3.5 text-sun"></i><a href="mailto:admin.support@rajagiri.edu" class="rounded transition-colors hover:text-white hover:underline underline-offset-4">admin.support@rajagiri.edu</a></li>
                  <li class="flex items-center gap-2"><i data-lucide="phone" class="w-3.5 h-3.5 text-sun"></i><span>+91 484 XXX XXXX</span></li>
                  <li class="flex items-center gap-2"><i data-lucide="map-pin" class="w-3.5 h-3.5 text-sun"></i><span>Kalamassery, Kochi, Kerala</span></li>
                </ul>
              </div>
            </div>
            <div class="border-t border-white/15 pt-6">
              <div class="flex flex-col sm:flex-row items-center justify-between gap-2">
                <p class="text-xs text-slate-400 text-center sm:text-left">&copy; <?= date('Y') ?> <span class="font-bold text-white">Rajagiri College of Social Sciences</span>. All rights reserved.</p>
                <p class="text-xs text-slate-400">Powered by <span class="font-bold text-sun ml-1">Oréll Grievance</span></p>
              </div>
            </div>
          </div>
        </div>
      </footer>
    </div>
  </div>

  <script>
    if (typeof lucide !== 'undefined') lucide.createIcons();

    function togglePassword(id, btn) {
      const i = document.getElementById(id);
      const icon = btn.querySelector('i');
      i.type = i.type === 'password' ? 'text' : 'password';
      icon.setAttribute('data-lucide', i.type === 'password' ? 'eye' : 'eye-off');
      lucide.createIcons();
    }

    function passwordState() {
      const n = document.getElementById('new_password').value;
      const c = document.getElementById('confirm_password').value;
      const b = document.getElementById('strengthBar');
      const t = document.getElementById('strengthText');
      const m = document.getElementById('matchText');

      let score = 0;
      if (n.length >= 6) score++;
      if (/[A-Z]/.test(n)) score++;
      if (/[0-9]/.test(n)) score++;
      if (/[^A-Za-z0-9]/.test(n)) score++;

      b.style.width = (score * 25) + '%';
      t.textContent = n
        ? (['Very weak', 'Weak', 'Fair', 'Good', 'Strong'][score] || 'Very weak')
        : 'Password strength';
      t.style.color = score >= 3 ? '#006837' : '#6D6270';

      m.textContent = c ? (n === c ? 'Passwords match' : 'Passwords do not match') : '';
      m.style.color = c ? (n === c ? '#006837' : '#B42338') : '#6D6270';
    }

    <?php if ($passwordChanged && $successMessage !== ''): ?>
    let sec = 3;
    const cd = document.getElementById('countdown');
    const timer = setInterval(function () {
      sec--;
      if (cd) cd.textContent = Math.max(sec, 0);
      if (sec <= 0) { clearInterval(timer); location.href = 'dashboard.php'; }
    }, 1000);
    <?php endif; ?>

    (function () {
      const btn = document.getElementById('sidebarLogoutBtn');
      if (!btn) return;
      btn.addEventListener('click', function (e) {
        if (!window.confirm('Are you sure you want to log out?')) { e.preventDefault(); e.stopPropagation(); return false; }
      });
    })();
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>