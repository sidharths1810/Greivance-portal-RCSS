<?php
/**
 * parent/profile.php
 * ---------------------------------------------------------------------------
 * Parent Profile (View Only) — Rajagiri College Grievance Redressal Portal
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

if (empty($_SESSION['user_id']) || $sessionRole !== 'PARENT') {
    header('Location: ../login.php?role=parent');
    exit;
}

$userId = (int) $_SESSION['user_id'];

$dbFile = __DIR__ . '/../db_connect.php';

$dbError = null;
$conn    = null;

if (!file_exists($dbFile)) {
    $dbError = 'Database configuration file (db_connect.php) not found.';
} else {
    require_once $dbFile;

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
}

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$flashSuccess = '';
$flashError   = '';

if (!empty($_SESSION['flash_success'])) {
    $flashSuccess = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $flashError = (string) $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

$profile = [
    'username'       => $_SESSION['username'] ?? 'Parent',
    'name'           => '',
    'address'        => '',
    'email'          => '',
    'contact_number' => '',
    'profile_image'  => '',
];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  u.username,
                        p.name            AS name,
                        p.address         AS address,
                        p.email           AS email,
                        p.contact_number  AS contact_number,
                        p.profile_image   AS profile_image
                FROM users u
                LEFT JOIN parents p ON p.user_id = u.id
                WHERE u.id = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $profile['username']       = $row['username']       ?? $profile['username'];
                $profile['name']           = $row['name']           ?? '';
                $profile['address']        = $row['address']        ?? '';
                $profile['email']          = $row['email']          ?? '';
                $profile['contact_number'] = $row['contact_number'] ?? '';
                $profile['profile_image']  = $row['profile_image']  ?? '';
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Parent Profile Fetch] ' . $ex->getMessage());
        $dbError = 'Unable to load profile data at this time.';
    }
}

$displayName    = !empty($profile['name'])           ? $profile['name']           : $profile['username'];
$displayAddress = !empty($profile['address'])        ? $profile['address']        : 'Not provided';
$displayEmail   = !empty($profile['email'])          ? $profile['email']          : 'Not provided';
$displayMobile  = !empty($profile['contact_number']) ? $profile['contact_number'] : 'Not provided';

$hasProfilePicture = false;
$profilePictureUrl = '';

if (!empty($profile['profile_image'])) {
    $relative     = ltrim((string) $profile['profile_image'], '/');
    $absolutePath = __DIR__ . '/../' . $relative;
    $browserPath  = '../' . $relative;

    if (file_exists($absolutePath) && is_file($absolutePath)) {
        $hasProfilePicture = true;
        $profilePictureUrl = $browserPath;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Parent profile — Rajagiri College Grievance Redressal Portal." />
  <meta name="theme-color" content="#DB0878" />
  <title>User Details — Parent | Rajagiri College Grievance Portal</title>
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

    .card-hard { border: 2px solid #1F0A24; box-shadow: 6px 6px 0 #FFC93C; }

    @keyframes rise { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: none; } }
    .rise { animation: rise .6s cubic-bezier(.2, .7, .2, 1) both; }
    .rise-2 { animation-delay: .12s; }

    @keyframes flashIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes flashOut { from { opacity: 1; transform: translateY(0); max-height: 200px; } to { opacity: 0; transform: translateY(-10px); max-height: 0; } }
    .animate-flash-in  { animation: flashIn .35s cubic-bezier(.16, 1, .3, 1) forwards; }
    .animate-flash-out { animation: flashOut .45s cubic-bezier(.4, 0, 1, 1) forwards; }

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

    <aside id="parentSidebar"
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
           class="group relative w-full h-12 rounded-xl bg-hot ring-2 ring-sun/60 flex items-center text-white nav-icon-link px-3">
          <i data-lucide="user" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">My Profile</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-ink text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50 border border-white/10">My Profile</span>
        </a>

        <a href="change_password.php"
           class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center text-white nav-icon-link px-3">
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

    <div id="parentMain" class="flex-1 ml-20 flex flex-col min-h-screen transition-all duration-300">

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

          <div class="relative" id="parent-dropdown-container">
            <button id="parent-dropdown-btn" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="parent-dropdown-menu"
                    class="flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 transition-colors hover:bg-blush">
              <?php if ($hasProfilePicture): ?>
                <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>" class="w-10 h-10 rounded-full object-cover border-2 border-ink shadow-[2px_2px_0_#FFC93C]" />
              <?php else: ?>
                <span class="w-10 h-10 rounded-full bg-hot flex items-center justify-center text-white border-2 border-ink shadow-[2px_2px_0_#FFC93C]">
                  <i data-lucide="user" class="w-5 h-5 text-white"></i>
                </span>
              <?php endif; ?>
              <span class="hidden sm:block text-sm font-bold text-ink"><?= e($displayName) ?></span>
              <i data-lucide="chevron-down" id="parent-chevron" class="w-4 h-4 text-slate-500 transition-transform duration-300"></i>
            </button>

            <div id="parent-dropdown-menu" class="hidden absolute right-0 z-50 mt-3 w-72 rounded-xl border-2 border-ink bg-white p-2 shadow-[6px_6px_0_#FFC93C]" role="menu">
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
              <a href="profile.php" role="menuitem" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-ink bg-blush font-semibold">
                <i data-lucide="user" class="w-4 h-4 text-hot"></i>
                <span class="font-semibold">My Profile</span>
              </a>
              <a href="change_password.php" role="menuitem" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 transition-colors hover:bg-blush hover:text-ink group">
                <i data-lucide="key" class="w-4 h-4 text-[#B37A00] group-hover:scale-110 transition-transform"></i>
                <span class="font-semibold">Change Password</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-ink transition-opacity"></i>
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

      <main id="main-content" class="flex-1 px-4 sm:px-6 py-8">

        <div class="max-w-3xl mx-auto mb-8 rise">
          <h1 class="font-display text-2xl md:text-3xl font-extrabold tracking-tight text-ink mb-3 flex items-center gap-3">
            <span class="w-10 h-10 rounded-xl bg-hot border-2 border-ink flex items-center justify-center shadow-[2px_2px_0_#FFC93C]">
              <i data-lucide="user" class="w-5 h-5 text-white"></i>
            </span>
            User Details
          </h1>
          <nav class="flex flex-wrap items-center gap-2 text-sm text-slate-500 ml-1" aria-label="Breadcrumb">
            <a href="dashboard.php" class="flex items-center rounded transition-colors hover:text-hot"><i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i> Dashboard</a>
            <span class="text-slate-300">/</span>
            <a href="dashboard.php" class="rounded transition-colors hover:text-hot">Grievance</a>
            <span class="text-slate-300">/</span>
            <span class="text-hot font-semibold">User Details</span>
          </nav>
        </div>

        <?php if ($flashSuccess !== ''): ?>
          <div id="flashSuccessBox" class="max-w-3xl mx-auto mb-6 rounded-xl border-2 border-leaf bg-[#DCEFE4] px-4 py-3 flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="check-circle" class="w-5 h-5 text-leaf flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-leaf font-semibold"><?= e($flashSuccess) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
          <div id="flashErrorBox" class="max-w-3xl mx-auto mb-6 rounded-xl border-2 border-red-300 bg-red-50 px-4 py-3 flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-red-700 font-semibold"><?= e($flashError) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($dbError): ?>
          <div class="max-w-3xl mx-auto mb-6 rounded-xl border-2 border-amber-300 bg-amber-50 px-4 py-3 flex items-start gap-2">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-amber-700 font-semibold"><?= e($dbError) ?></p>
          </div>
        <?php endif; ?>

        <div class="max-w-3xl mx-auto rise rise-2">
          <div class="bg-white rounded-2xl border-2 border-ink shadow-[6px_6px_0_#FFC93C] overflow-hidden">

            <div class="bg-blush px-6 py-4 border-b-2 border-ink">
              <h2 class="font-display text-lg md:text-xl font-bold text-ink flex items-center gap-2">
                <i data-lucide="id-card" class="w-5 h-5 text-hot"></i>
                User Details
              </h2>
            </div>

            <div class="relative px-6 py-12 overflow-hidden bg-hot">
              <div class="pointer-events-none absolute -bottom-16 -left-12 w-48 h-48 rounded-full bg-grape" aria-hidden="true"></div>
              <div class="pointer-events-none absolute -top-12 right-8 w-40 h-40 rounded-full border-[20px] border-white/10" aria-hidden="true"></div>
              <div class="pointer-events-none absolute top-6 right-16 w-16 h-16 rounded-full bg-sun/40" aria-hidden="true"></div>

              <div class="relative flex flex-col items-center justify-center">
                <div class="relative">
                  <div class="relative w-32 h-32 md:w-36 md:h-36 rounded-full bg-white p-2 shadow-[6px_6px_0_#1F0A24] border-2 border-ink">
                    <div class="w-full h-full rounded-full overflow-hidden bg-blush">
                      <?php if ($hasProfilePicture): ?>
                        <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>" class="w-full h-full object-cover" />
                      <?php else: ?>
                        <div class="w-full h-full bg-hot flex items-center justify-center text-white">
                          <i data-lucide="user" class="w-14 h-14 md:w-16 md:h-16"></i>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <h3 class="mt-5 font-display text-2xl md:text-3xl font-extrabold text-white tracking-tight">
                  <?= e($displayName) ?>
                </h3>

                <div class="mt-3 inline-flex items-center gap-2 bg-white border-2 border-ink px-3 py-1 rounded-full shadow-[2px_2px_0_#FFC93C]">
                  <i data-lucide="users" class="w-3.5 h-3.5 text-hot"></i>
                  <span class="text-xs font-bold text-ink uppercase tracking-wider">Parent</span>
                </div>
              </div>
            </div>

            <div class="px-6 md:px-10 py-8 bg-white border-t-2 border-ink">
              <div class="flex items-center mb-5">
                <i data-lucide="clipboard-list" class="w-4 h-4 text-hot mr-2"></i>
                <h4 class="text-xs font-bold text-slate-500 uppercase tracking-wider">Personal Information</h4>
              </div>

              <div class="bg-white rounded-2xl border-2 border-ink overflow-hidden">
                <table class="w-full">
                  <tbody class="divide-y divide-slate-200">

                    <tr class="group/row hover:bg-blush transition-colors">
                      <td class="w-1/3 px-5 py-5 align-top">
                        <div class="flex items-center text-xs font-bold text-slate-500 uppercase tracking-wider group-hover/row:text-hot transition-colors">
                          <span class="w-8 h-8 rounded-lg bg-sun border-2 border-ink flex items-center justify-center mr-2.5">
                            <i data-lucide="user" class="w-4 h-4 text-ink"></i>
                          </span>
                          Name
                        </div>
                      </td>
                      <td class="px-5 py-5 align-top">
                        <p class="text-ink font-semibold text-base"><?= e($displayName) ?></p>
                      </td>
                    </tr>

                    <tr class="group/row hover:bg-blush transition-colors">
                      <td class="w-1/3 px-5 py-5 align-top">
                        <div class="flex items-center text-xs font-bold text-slate-500 uppercase tracking-wider group-hover/row:text-hot transition-colors">
                          <span class="w-8 h-8 rounded-lg bg-sun border-2 border-ink flex items-center justify-center mr-2.5">
                            <i data-lucide="map-pin" class="w-4 h-4 text-ink"></i>
                          </span>
                          Address
                        </div>
                      </td>
                      <td class="px-5 py-5 align-top">
                        <p class="text-slate-700 text-base leading-relaxed <?= $displayAddress === 'Not provided' ? 'italic text-slate-400' : '' ?>"><?= e($displayAddress) ?></p>
                      </td>
                    </tr>

                    <tr class="group/row hover:bg-blush transition-colors">
                      <td class="w-1/3 px-5 py-5 align-top">
                        <div class="flex items-center text-xs font-bold text-slate-500 uppercase tracking-wider group-hover/row:text-hot transition-colors">
                          <span class="w-8 h-8 rounded-lg bg-sun border-2 border-ink flex items-center justify-center mr-2.5">
                            <i data-lucide="mail" class="w-4 h-4 text-ink"></i>
                          </span>
                          Email
                        </div>
                      </td>
                      <td class="px-5 py-5 align-top">
                        <p class="text-slate-700 text-base break-all <?= $displayEmail === 'Not provided' ? 'italic text-slate-400' : '' ?>"><?= e($displayEmail) ?></p>
                      </td>
                    </tr>

                    <tr class="group/row hover:bg-blush transition-colors">
                      <td class="w-1/3 px-5 py-5 align-top">
                        <div class="flex items-center text-xs font-bold text-slate-500 uppercase tracking-wider group-hover/row:text-hot transition-colors">
                          <span class="w-8 h-8 rounded-lg bg-sun border-2 border-ink flex items-center justify-center mr-2.5">
                            <i data-lucide="phone" class="w-4 h-4 text-ink"></i>
                          </span>
                          Contact Number
                        </div>
                      </td>
                      <td class="px-5 py-5 align-top">
                        <p class="text-slate-700 text-base <?= $displayMobile === 'Not provided' ? 'italic text-slate-400' : '' ?>"><?= e($displayMobile) ?></p>
                      </td>
                    </tr>

                  </tbody>
                </table>
              </div>
            </div>

            <div class="px-6 md:px-10 py-6 bg-white border-t-2 border-ink flex flex-col sm:flex-row justify-between items-center gap-3">
              <a href="dashboard.php"
                 class="inline-flex w-full sm:w-auto items-center justify-center gap-2 rounded-xl border-2 border-ink bg-white px-6 py-3 text-sm font-bold text-ink transition-colors hover:bg-blush">
                <i data-lucide="arrow-left" class="w-4 h-4"></i>
                <span>Back to Dashboard</span>
              </a>

              <a href="edit_profile.php"
                 class="btn-hard inline-flex w-full sm:w-auto items-center justify-center gap-2 rounded-xl bg-hot px-8 py-3 text-sm font-bold text-white hover:bg-hotdark">
                <i data-lucide="pencil" class="w-4 h-4"></i>
                <span>Edit</span>
                <i data-lucide="arrow-right" class="w-4 h-4"></i>
              </a>
            </div>
          </div>
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
                  <li class="flex items-center gap-2"><i data-lucide="mail" class="w-3.5 h-3.5 text-sun"></i><a href="mailto:parent.grievance@rajagiri.edu" class="rounded transition-colors hover:text-white hover:underline underline-offset-4">parent.grievance@rajagiri.edu</a></li>
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

  <!-- LOGOUT CONFIRM -->
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
          Any unsaved changes will be lost.
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

    (function () {
      ['flashSuccessBox', 'flashErrorBox'].forEach(function (id) {
        const box = document.getElementById(id);
        if (!box) return;
        setTimeout(function () {
          box.classList.remove('animate-flash-in');
          box.classList.add('animate-flash-out');
          setTimeout(function () { if (box.parentNode) box.parentNode.removeChild(box); }, 500);
        }, 3000);
      });
    })();

    (function () {
      const toggleBtn = document.getElementById('sidebarToggle');
      const sidebar   = document.getElementById('parentSidebar');
      const main      = document.getElementById('parentMain');
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

    (function () {
      const btn       = document.getElementById('parent-dropdown-btn');
      const menu      = document.getElementById('parent-dropdown-menu');
      const chevron   = document.getElementById('parent-chevron');
      const container = document.getElementById('parent-dropdown-container');
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

    const logoutConfirmModal = document.getElementById('logoutConfirmModal');
    const logoutConfirmPanel = document.getElementById('logoutConfirmPanel');
    const confirmLogoutBtn   = document.getElementById('confirmLogoutBtn');
    const LOGOUT_URL = '../logout.php?role=parent';

    function openLogoutModal() {
      logoutConfirmModal.classList.remove('hidden');
      if (logoutConfirmPanel) { logoutConfirmPanel.classList.remove('animate-confirm-shake'); void logoutConfirmPanel.offsetWidth; logoutConfirmPanel.classList.add('animate-confirm-shake'); }
      setTimeout(function () { if (confirmLogoutBtn) confirmLogoutBtn.focus(); }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeLogoutModal() { logoutConfirmModal.classList.add('hidden'); }

    [document.getElementById('sidebarLogoutBtn'), document.getElementById('dropdownLogoutBtn')].forEach(function (btn) {
      if (!btn) return;
      btn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); openLogoutModal(); });
    });
    if (confirmLogoutBtn) confirmLogoutBtn.addEventListener('click', function () {
      confirmLogoutBtn.classList.add('opacity-50', 'pointer-events-none');
      window.location.href = LOGOUT_URL;
    });
    document.getElementById('logoutConfirmModal').addEventListener('click', function (e) { if (e.target.id === 'logoutConfirmModal') closeLogoutModal(); });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) closeLogoutModal();
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>