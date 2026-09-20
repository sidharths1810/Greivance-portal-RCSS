<?php
/**
 * admin/dashboard.php
 * ---------------------------------------------------------------------------
 * Admin Dashboard — Rajagiri College Grievance Redressal Portal
 *
 * Auth Check   : case-insensitive role match against 'ADMIN'
 * DB Enum      : grievances.status IN ('Pending','In Progress','Disposed','Closed','Reopened')
 * Profile      : Fetches name + profile_picture from admin_profiles via LEFT JOIN
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
// 2. AUTH GUARD — case-insensitive role check
// ---------------------------------------------------------------------------
$sessionRole = isset($_SESSION['role']) ? strtoupper((string) $_SESSION['role']) : '';

if (empty($_SESSION['user_id']) || $sessionRole !== 'ADMIN') {
    header('Location: ../login.php?role=admin');
    exit;
}

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
        $dbError = 'Database connection failed: ' . $conn->connect_error;
        $conn    = null;
    } else {
        $conn->set_charset('utf8mb4');
    }
}

if ($conn && $conn->connect_errno) {
    $dbError = 'Database connection failed: ' . $conn->connect_error;
    $conn    = null;
}

// ---------------------------------------------------------------------------
// 4. FETCH ADMIN PROFILE (LEFT JOIN users + admin_profiles)
// ---------------------------------------------------------------------------
$adminData = [
    'username'        => 'Admin',
    'name'            => '',
    'email'           => 'admin@rajagiri.edu',
    'profile_picture' => '',
];

if ($conn !== null) {
    try {
        $sql = "SELECT  u.id,
                        u.username,
                        u.role,
                        u.status,
                        ap.name            AS name,
                        ap.email           AS email,
                        ap.profile_picture AS profile_picture
                FROM users u
                LEFT JOIN admin_profiles ap ON ap.user_id = u.id
                WHERE u.id = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);

        if ($stmt) {
            $userId = (int) $_SESSION['user_id'];
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();

                $adminData['username']        = $row['username']        ?? 'Admin';
                $adminData['name']            = $row['name']            ?? '';
                $adminData['email']           = $row['email']           ?? 'admin@rajagiri.edu';
                $adminData['profile_picture'] = $row['profile_picture'] ?? '';
            }

            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Admin Profile Fetch] ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 5. DISPLAY NAME FALLBACK LOGIC
// ---------------------------------------------------------------------------
$displayName = !empty($adminData['name'])
    ? $adminData['name']
    : $adminData['username'];

$adminEmail = !empty($adminData['email'])
    ? $adminData['email']
    : 'admin@rajagiri.edu';

// ---------------------------------------------------------------------------
// 6. PROFILE PICTURE RESOLUTION
// ---------------------------------------------------------------------------
$hasProfilePicture = false;
$profilePictureUrl = '';

if (!empty($adminData['profile_picture'])) {
    $relativeFromAdmin = '../' . ltrim((string) $adminData['profile_picture'], '/');

    if (file_exists(__DIR__ . '/../' . ltrim((string) $adminData['profile_picture'], '/'))) {
        $hasProfilePicture = true;
        $profilePictureUrl = $relativeFromAdmin;
    }
}

// ---------------------------------------------------------------------------
// 7. INITIALS (fallback when no profile picture)
// ---------------------------------------------------------------------------
$initials = 'A';
if (!empty($displayName)) {
    $parts = preg_split('/[\s._-]+/', trim((string) $displayName));
    if (is_array($parts) && count($parts) >= 2) {
        $initials = strtoupper(substr($parts[0], 0, 1) . substr(end($parts), 0, 1));
    } else {
        $initials = strtoupper(substr((string) $displayName, 0, 2));
    }
}

// ---------------------------------------------------------------------------
// 8. DYNAMIC METRIC QUERIES
// ---------------------------------------------------------------------------
$totalGrievances   = 0;
$pendingGrievances = 0;
$closedGrievances  = 0;

if ($conn !== null) {
    try {
        // ---- Total ----
        $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM grievances");
        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            $totalGrievances = (int) ($res->fetch_assoc()['total'] ?? 0);
            $stmt->close();
        }

        // ---- Pending ----
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM grievances
             WHERE status IN ('Pending', 'In Progress', 'Reopened')"
        );
        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            $pendingGrievances = (int) ($res->fetch_assoc()['total'] ?? 0);
            $stmt->close();
        }

        // ---- Closed ----
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM grievances
             WHERE status IN ('Closed', 'Disposed')"
        );
        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            $closedGrievances = (int) ($res->fetch_assoc()['total'] ?? 0);
            $stmt->close();
        }

    } catch (Throwable $ex) {
        error_log('[Admin Dashboard Metrics] ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 9. NAVIGATION CARDS CONFIG
// ---------------------------------------------------------------------------
$navCards = [
    ['title' => 'Settings',          'icon' => 'settings',       'href' => 'settings.php'],
    ['title' => 'Members',           'icon' => 'users',          'href' => 'members.php'],
    ['title' => 'Grievance',         'icon' => 'clipboard-list', 'href' => 'grievances.php'],
    ['title' => 'Grievance Reports', 'icon' => 'bar-chart-3',    'href' => 'grievance_report.php'],
    ['title' => 'Mail Log',          'icon' => 'mail',           'href' => 'mail_log.php'],
];

// ---------------------------------------------------------------------------
// 10. HELPER
// ---------------------------------------------------------------------------
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Admin Dashboard — Rajagiri College of Social Sciences Grievance Redressal Portal." />
  <meta name="theme-color" content="#DB0878" />
  <title>Admin Dashboard — Rajagiri College Grievance Portal</title>
  <link rel="icon" type="image/svg+xml" href="../public/favicon.svg" />

  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700;12..96,800&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Tailwind CSS CDN -->
  <script src="https://cdn.tailwindcss.com"></script>

  <!-- Lucide Icons CDN -->
  <script src="https://unpkg.com/lucide@latest"></script>

  <!-- Custom Tailwind Theme Config (matches index.php) -->
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            brandPurple: '#4A154B',
            brandPink: '#E5097F',
            brandGreen: '#006837',
            brandGold: '#C5A059',
            hot: '#DB0878',
            hotdark: '#B70664',
            sun: '#FFC93C',
            grape: '#4A154B',
            leaf: '#006837',
            ink: '#1F0A24',
            blush: '#FFF0F7'
          },
          fontFamily: {
            display: ['"Bricolage Grotesque"', 'system-ui', 'sans-serif'],
            sans: ['Figtree', 'system-ui', 'sans-serif']
          }
        }
      }
    }
  </script>

  <!-- Local Page CSS Link -->
  <link rel="stylesheet" href="../assets/css/index.css" />

  <!-- Design layer (mirrors index.php) -->
  <style>
    html { scroll-behavior: smooth; }

    /* Visible keyboard focus everywhere */
    a:focus-visible, button:focus-visible, input:focus-visible,
    textarea:focus-visible, select:focus-visible {
      outline: 3px solid #1F0A24;
      outline-offset: 3px;
    }
    .on-ink a:focus-visible, .on-ink button:focus-visible { outline-color: #fff; }

    .balance { text-wrap: balance; }

    /* Signature device: hard offset shadow on primary actions */
    .btn-hard {
      border: 2px solid #1F0A24;
      box-shadow: 4px 4px 0 #1F0A24;
      transition: transform .12s ease, box-shadow .12s ease, background-color .15s ease;
    }
    .btn-hard:hover  { transform: translate(2px, 2px); box-shadow: 2px 2px 0 #1F0A24; }
    .btn-hard:active { transform: translate(4px, 4px); box-shadow: 0 0 0 #1F0A24; }

    /* Card device: hard offset shadow */
    .card-hard {
      border: 2px solid #1F0A24;
      box-shadow: 6px 6px 0 #FFC93C;
      transition: transform .15s ease, box-shadow .15s ease;
    }
    .card-hard:hover {
      transform: translate(-2px, -2px);
      box-shadow: 10px 10px 0 #FFC93C;
    }

    /* One orchestrated entrance on page load */
    @keyframes rise {
      from { opacity: 0; transform: translateY(16px); }
      to   { opacity: 1; transform: none; }
    }
    .rise   { animation: rise .6s cubic-bezier(.2, .7, .2, 1) both; }
    .rise-2 { animation-delay: .12s; }
    .rise-3 { animation-delay: .24s; }

    /* Sidebar nav icon active/hover */
    .nav-icon-link {
      transition: transform .18s ease, background-color .18s ease, box-shadow .18s ease;
    }
    .nav-icon-link:hover { transform: translateY(-2px); }

    @media (prefers-reduced-motion: reduce) {
      html { scroll-behavior: auto; }
      *, *::before, *::after { animation: none !important; transition: none !important; }
    }
  </style>
</head>

<body class="min-h-screen bg-white text-slate-700 font-sans antialiased selection:bg-sun selection:text-ink">

  <!-- Oréll Grievance logo (inline SVG symbol — matches index.php) -->
  <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="orel-logo" viewBox="0 0 128 40" style="overflow:visible">
      <path fill="#DB0878" d="M10 0H26A10 10 0 0 1 36 10V20A10 10 0 0 1 26 30H16L8 38V29.8A10 10 0 0 1 0 20V10A10 10 0 0 1 10 0Z"/>
      <path d="M10 15.5l5.5 5.5L27 9.5" fill="none" stroke="#FFC93C" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round"/>
      <text x="46" y="21" font-family="Bricolage Grotesque, system-ui, sans-serif" font-size="23" font-weight="800" fill="#1F0A24">Oréll</text>
      <text x="46.5" y="35" font-family="Figtree, system-ui, sans-serif" font-size="11" font-weight="700" letter-spacing="0.6" fill="#DB0878">Grievance</text>
    </symbol>
  </svg>

  <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[60] focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-ink focus:shadow-lg">
    Skip to main content
  </a>

  <div class="flex min-h-screen">

    <!-- ============================================================
         SIDEBAR
         ============================================================ -->
    <aside class="w-20 on-ink bg-ink flex flex-col items-center py-4 fixed inset-y-0 left-0 z-40">

      <!-- Brand colour stripe (top) -->
      <div class="absolute top-0 left-0 right-0 flex h-1.5" aria-hidden="true">
        <span class="flex-1 bg-hot"></span>
        <span class="flex-1 bg-sun"></span>
        <span class="flex-1 bg-leaf"></span>
        <span class="flex-1 bg-grape"></span>
      </div>

      <!-- Home / Dashboard -->
      <a href="dashboard.php"
         class="nav-icon-link group relative mt-6 w-12 h-12 rounded-xl bg-hot flex items-center justify-center text-white ring-2 ring-sun/60"
         title="Dashboard" aria-label="Dashboard">
        <i data-lucide="home" class="w-6 h-6"></i>
        <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-ink text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50 border border-white/10">
          Dashboard
        </span>
      </a>

      <!-- Nav Icons -->
      <nav class="flex flex-col items-center space-y-4 flex-1 mt-8" aria-label="Admin navigation">

        <!-- Profile -->
        <a href="profile.php"
           class="nav-icon-link group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white"
           title="Profile" aria-label="Profile">
          <i data-lucide="user" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-ink text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50 border border-white/10">
            Profile
          </span>
        </a>

      </nav>

      <!-- Logout Button -->
      <a href="../logout.php?role=admin"
         id="sidebarLogoutBtn"
         class="nav-icon-link group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-hot flex items-center justify-center text-white"
         title="Logout" aria-label="Logout">
        <i data-lucide="log-out" class="w-6 h-6 group-hover:translate-x-0.5 transition-transform"></i>
        <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-hot text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50">
          Logout
        </span>
      </a>
    </aside>

    <!-- ============================================================
         MAIN CONTENT WRAPPER
         ============================================================ -->
    <div class="flex-1 ml-20 flex flex-col min-h-screen">

      <!-- ============ TOP HEADER ============ -->
      <header class="sticky top-0 z-30 bg-white border-b border-slate-200">
        <!-- Brand colour stripe -->
        <div class="flex h-1.5" aria-hidden="true">
          <span class="flex-1 bg-hot"></span>
          <span class="flex-1 bg-sun"></span>
          <span class="flex-1 bg-leaf"></span>
          <span class="flex-1 bg-grape"></span>
        </div>

        <div class="flex items-center justify-between px-4 sm:px-6 py-3 md:py-4">

          <!-- Left: Logos -->
          <div class="flex items-center gap-4">
            <a href="dashboard.php" class="flex items-center gap-4 rounded-lg">
              <img
                src="../public/rcss-logo.png"
                alt="RCSS Logo"
                class="h-10 md:h-12 w-auto"
              />
              <span class="hidden sm:block h-8 w-px bg-slate-200" aria-hidden="true"></span>
              <svg class="hidden sm:block h-9 md:h-10 w-auto" width="128" height="40" viewBox="0 0 128 40" role="img" aria-label="Oréll Grievance">
                <use href="#orel-logo"></use>
              </svg>
            </a>
          </div>

          <!-- Right: Admin Profile Dropdown -->
          <div class="relative" id="admin-dropdown-container">
            <button id="admin-dropdown-btn"
                    type="button"
                    aria-haspopup="true"
                    aria-expanded="false"
                    aria-controls="admin-dropdown-menu"
                    class="flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 transition-colors hover:bg-blush">

              <!-- PROFILE AVATAR (dynamic) -->
              <?php if ($hasProfilePicture): ?>
                <img
                  src="<?= e($profilePictureUrl) ?>"
                  alt="Admin Profile"
                  class="w-10 h-10 rounded-full object-cover border-2 border-ink shadow-[2px_2px_0_#FFC93C]"
                />
              <?php else: ?>
                <span class="w-10 h-10 rounded-full bg-hot flex items-center justify-center text-white border-2 border-ink shadow-[2px_2px_0_#FFC93C]">
                  <i data-lucide="user" class="w-5 h-5 text-white"></i>
                </span>
              <?php endif; ?>

              <!-- DISPLAY NAME -->
              <span class="hidden sm:block text-sm font-bold text-ink">
                <?= e($displayName) ?>
              </span>

              <i data-lucide="chevron-down" id="admin-chevron" class="w-4 h-4 text-slate-500 transition-transform duration-300"></i>
            </button>

            <!-- Dropdown Menu -->
            <div id="admin-dropdown-menu"
                 class="hidden absolute right-0 z-50 mt-3 w-72 rounded-xl border-2 border-ink bg-white p-2 shadow-[6px_6px_0_#FFC93C]"
                 role="menu">

              <!-- Dropdown Header -->
              <div class="rounded-lg bg-blush px-3 py-3">
                <div class="flex items-center gap-3">

                  <?php if ($hasProfilePicture): ?>
                    <img
                      src="<?= e($profilePictureUrl) ?>"
                      alt="Admin Profile"
                      class="w-12 h-12 rounded-full object-cover border-2 border-ink"
                    />
                  <?php else: ?>
                    <span class="w-12 h-12 rounded-full bg-hot flex items-center justify-center text-white border-2 border-ink">
                      <i data-lucide="user" class="w-6 h-6 text-white"></i>
                    </span>
                  <?php endif; ?>

                  <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-bold text-ink"><?= e($displayName) ?></p>
                    <p class="truncate text-xs text-slate-600"><?= e($adminEmail) ?></p>
                  </div>
                </div>
              </div>

              <!-- Option 1: My Profile -->
              <a href="profile.php"
                 role="menuitem"
                 class="mt-1 flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 transition-colors hover:bg-blush hover:text-ink group">
                <i data-lucide="user" class="w-4 h-4 text-hot group-hover:scale-110 transition-transform"></i>
                <span class="font-semibold">My Profile</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-ink transition-opacity"></i>
              </a>

              <!-- Option 2: Change Password -->
              <a href="change_password.php"
                 role="menuitem"
                 class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 transition-colors hover:bg-blush hover:text-ink group">
                <i data-lucide="key" class="w-4 h-4 text-[#B37A00] group-hover:scale-110 transition-transform"></i>
                <span class="font-semibold">Change Password</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-ink transition-opacity"></i>
              </a>

              <!-- Divider -->
              <div class="my-2 border-t border-slate-200" role="separator"></div>

              <!-- Option 3: Logout -->
              <a href="../logout.php?role=admin"
                 id="dropdownLogoutBtn"
                 role="menuitem"
                 class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 transition-colors hover:bg-blush hover:text-hot group">
                <i data-lucide="log-out" class="w-4 h-4 text-hot group-hover:scale-110 transition-transform"></i>
                <span class="font-semibold">Logout</span>
              </a>
            </div>
          </div>

        </div>
      </header>

      <!-- ============ PAGE CONTENT ============ -->
      <main id="main-content" class="flex-1 px-4 sm:px-6 py-8">

        <?php if ($dbError): ?>
          <div class="max-w-5xl mx-auto mb-6 flex items-start gap-2 rounded-xl border-2 border-amber-300 bg-amber-50 px-4 py-3">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-amber-700"><?= e($dbError) ?></p>
          </div>
        <?php endif; ?>

        <!-- Institution Title -->
        <div class="rise text-center mb-10">
          <h1 class="font-display balance text-2xl md:text-3xl lg:text-4xl font-extrabold tracking-tight text-ink mb-2">
            Rajagiri College of Social Sciences
          </h1>
          <h2 class="font-display text-lg md:text-xl font-bold text-hot">
            Admin Dashboard
          </h2>
        </div>

        <!-- ============ NAVIGATION CARDS (TOP ROW) ============ -->
        <div class="rise rise-2 grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 md:gap-5 max-w-6xl mx-auto mb-10">
          <?php foreach ($navCards as $card): ?>
            <a href="<?= e($card['href']) ?>"
               class="card-hard group relative bg-hot rounded-2xl p-5 md:p-6 text-white overflow-hidden">

              <div class="absolute -top-6 -right-6 w-20 h-20 bg-white/10 rounded-full group-hover:scale-150 transition-transform duration-500"></div>
              <div class="absolute -bottom-6 -left-6 w-16 h-16 bg-white/10 rounded-full group-hover:scale-150 transition-transform duration-500 delay-75"></div>

              <div class="relative flex flex-col items-center justify-center text-center">
                <span class="w-12 h-12 md:w-14 md:h-14 rounded-xl bg-sun flex items-center justify-center mb-3 md:mb-4 border-2 border-ink group-hover:scale-110 transition-transform duration-300">
                  <i data-lucide="<?= e($card['icon']) ?>" class="w-6 h-6 md:w-7 md:h-7 text-ink"></i>
                </span>
                <p class="text-xs md:text-sm font-bold leading-tight">
                  <?= e($card['title']) ?>
                </p>
              </div>
            </a>
          <?php endforeach; ?>
        </div>

        <!-- ============ METRIC PILL CARDS (MIDDLE ROW) ============ -->
        <div class="rise rise-3 grid grid-cols-1 md:grid-cols-3 gap-5 max-w-5xl mx-auto mb-12">

          <!-- Total -->
          <div class="flex items-center gap-4 bg-white rounded-full border-2 border-ink shadow-[6px_6px_0_#FFC93C] p-3 pr-6 transition-transform hover:-translate-y-1">
            <span class="w-14 h-14 md:w-16 md:h-16 rounded-full bg-hot flex items-center justify-center flex-shrink-0 border-2 border-ink">
              <i data-lucide="users-2" class="w-7 h-7 md:w-8 md:h-8 text-white"></i>
            </span>
            <div>
              <span class="block text-2xl md:text-3xl font-extrabold text-ink">
                <?= (int) $totalGrievances ?>
              </span>
              <span class="block text-sm md:text-base font-semibold text-hot">
                Total Grievance
              </span>
            </div>
          </div>

          <!-- Pending -->
          <div class="flex items-center gap-4 bg-white rounded-full border-2 border-ink shadow-[6px_6px_0_#FFC93C] p-3 pr-6 transition-transform hover:-translate-y-1">
            <span class="w-14 h-14 md:w-16 md:h-16 rounded-full bg-grape flex items-center justify-center flex-shrink-0 border-2 border-ink">
              <i data-lucide="users" class="w-7 h-7 md:w-8 md:h-8 text-white"></i>
            </span>
            <div>
              <span class="block text-2xl md:text-3xl font-extrabold text-ink">
                <?= (int) $pendingGrievances ?>
              </span>
              <span class="block text-sm md:text-base font-semibold text-grape">
                Pending Grievance
              </span>
            </div>
          </div>

          <!-- Closed -->
          <div class="flex items-center gap-4 bg-white rounded-full border-2 border-ink shadow-[6px_6px_0_#FFC93C] p-3 pr-6 transition-transform hover:-translate-y-1">
            <span class="w-14 h-14 md:w-16 md:h-16 rounded-full bg-leaf flex items-center justify-center flex-shrink-0 border-2 border-ink">
              <i data-lucide="check-circle" class="w-7 h-7 md:w-8 md:h-8 text-white"></i>
            </span>
            <div>
              <span class="block text-2xl md:text-3xl font-extrabold text-ink">
                <?= (int) $closedGrievances ?>
              </span>
              <span class="block text-sm md:text-base font-semibold text-leaf">
                Closed Grievance
              </span>
            </div>
          </div>

        </div>

        <!-- ============ GRIEVANCE SUMMARY CALLOUT ============ -->
        <div class="flex justify-center">
          <a href="summary_details.php"
             class="group relative flex items-center gap-5 bg-white rounded-3xl border-2 border-ink shadow-[6px_6px_0_#FFC93C] p-5 pr-8 transition-transform hover:-translate-y-1 hover:shadow-[10px_10px_0_#FFC93C]">

            <span class="relative w-20 h-20 md:w-24 md:h-24 rounded-2xl bg-sun flex items-center justify-center flex-shrink-0 border-2 border-ink overflow-hidden">
              <i data-lucide="star" class="absolute top-2 right-2 w-4 h-4 text-ink/70"></i>
              <i data-lucide="clipboard-edit" class="w-10 h-10 md:w-12 md:h-12 text-ink"></i>
            </span>

            <div>
              <h3 class="font-display text-base md:text-lg font-bold text-ink mb-1">
                Grievance Summary
              </h3>
              <p class="text-sm md:text-base font-semibold text-hot group-hover:text-grape transition-colors">
                Click here to open
              </p>
            </div>

            <span class="absolute bottom-0 left-0 right-0 h-1.5 bg-hot rounded-b-3xl transform scale-x-0 group-hover:scale-x-100 transition-transform duration-500 origin-left"></span>
          </a>
        </div>

      </main>

      <!-- ============ FOOTER ============ -->
      <footer class="on-ink bg-ink border-t-[6px] border-sun mt-auto">
        <div class="px-4 sm:px-6 py-10">
          <div class="max-w-7xl mx-auto">

            <!-- Footer Top: 3 Column Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-8">

              <!-- Col 1: Brand -->
              <div class="flex items-start gap-4">
                <span class="inline-flex items-center gap-3 rounded-xl bg-white px-3 py-2">
                  <img
                    src="../public/rcss-logo.png"
                    alt="RCSS Logo"
                    class="h-10 w-auto"
                  />
                  <svg class="h-8 w-auto" width="128" height="40" viewBox="0 0 128 40" role="img" aria-label="Oréll Grievance">
                    <use href="#orel-logo"></use>
                  </svg>
                </span>
                <div>
                  <p class="font-display font-bold text-white text-sm">
                    Rajagiri College of Social Sciences
                  </p>
                  <p class="text-xs text-slate-300 mt-1">
                    Grievance Redressal Portal
                  </p>
                </div>
              </div>

              <!-- Col 2: Quick Links -->
              <div>
                <h4 class="font-display font-bold text-sm text-sun mb-3">Quick Links</h4>
                <ul class="space-y-2 text-xs text-slate-200">
                  <li>
                    <a href="dashboard.php" class="inline-flex items-center gap-2 rounded transition-colors hover:text-white hover:underline underline-offset-4 group">
                      <i data-lucide="arrow-right" class="w-3 h-3 text-sun group-hover:translate-x-0.5 transition-transform"></i>
                      <span>Dashboard</span>
                    </a>
                  </li>
                  <li>
                    <a href="profile.php" class="inline-flex items-center gap-2 rounded transition-colors hover:text-white hover:underline underline-offset-4 group">
                      <i data-lucide="arrow-right" class="w-3 h-3 text-sun group-hover:translate-x-0.5 transition-transform"></i>
                      <span>My Profile</span>
                    </a>
                  </li>
                  <li>
                    <a href="change_password.php" class="inline-flex items-center gap-2 rounded transition-colors hover:text-white hover:underline underline-offset-4 group">
                      <i data-lucide="arrow-right" class="w-3 h-3 text-sun group-hover:translate-x-0.5 transition-transform"></i>
                      <span>Change Password</span>
                    </a>
                  </li>
                </ul>
              </div>

              <!-- Col 3: Contact -->
              <div>
                <h4 class="font-display font-bold text-sm text-sun mb-3">Contact Support</h4>
                <ul class="space-y-2 text-xs text-slate-200">
                  <li class="flex items-center gap-2">
                    <i data-lucide="mail" class="w-3.5 h-3.5 text-sun"></i>
                    <a href="mailto:admin.support@rajagiri.edu" class="rounded transition-colors hover:text-white hover:underline underline-offset-4">admin.support@rajagiri.edu</a>
                  </li>
                  <li class="flex items-center gap-2">
                    <i data-lucide="phone" class="w-3.5 h-3.5 text-sun"></i>
                    <span>+91 484 XXX XXXX</span>
                  </li>
                  <li class="flex items-center gap-2">
                    <i data-lucide="map-pin" class="w-3.5 h-3.5 text-sun"></i>
                    <span>Kalamassery, Kochi, Kerala</span>
                  </li>
                </ul>
              </div>

            </div>

            <!-- Footer Divider -->
            <div class="border-t border-white/15 pt-6">
              <div class="flex flex-col sm:flex-row items-center justify-between gap-2">
                <p class="text-xs text-slate-400 text-center sm:text-left">
                  &copy; <?= date('Y') ?>
                  <span class="font-bold text-white">Rajagiri College of Social Sciences</span>.
                  All rights reserved.
                </p>
                <p class="text-xs text-slate-400">
                  Powered by
                  <span class="font-bold text-sun ml-1">Oréll Grievance</span>
                </p>
              </div>
            </div>

          </div>
        </div>
      </footer>

    </div>

  </div>

  <!-- ====================== SCRIPTS ====================== -->
  <script>
    // Initialize Lucide icons
    if (typeof lucide !== 'undefined') {
      lucide.createIcons();
    }

    // ---- Admin Profile Dropdown ----
    (function () {
      const btn       = document.getElementById('admin-dropdown-btn');
      const menu      = document.getElementById('admin-dropdown-menu');
      const chevron   = document.getElementById('admin-chevron');
      const container = document.getElementById('admin-dropdown-container');

      if (!btn || !menu || !container) return;

      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        const isOpen = !menu.classList.contains('hidden');
        if (isOpen) {
          menu.classList.add('hidden');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded', 'false');
        } else {
          menu.classList.remove('hidden');
          if (chevron) chevron.classList.add('rotate-180');
          btn.setAttribute('aria-expanded', 'true');
        }
      });

      document.addEventListener('click', function (e) {
        if (!container.contains(e.target)) {
          menu.classList.add('hidden');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded', 'false');
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          menu.classList.add('hidden');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded', 'false');
        }
      });
    })();

    // ---- Logout Confirmation ----
    (function () {
      const logoutButtons = [
        document.getElementById('sidebarLogoutBtn'),
        document.getElementById('dropdownLogoutBtn'),
      ];

      logoutButtons.forEach(function (btn) {
        if (!btn) return;

        btn.addEventListener('click', function (e) {
          const confirmed = window.confirm('Are you sure you want to log out?');
          if (!confirmed) {
            e.preventDefault();
            e.stopPropagation();
            return false;
          }
          btn.classList.add('opacity-50', 'pointer-events-none');
        });
      });
    })();
  </script>

  <!-- Local JS -->
  <script src="../assets/js/index.js"></script>
</body>
</html>