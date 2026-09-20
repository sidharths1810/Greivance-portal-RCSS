<?php
/**
 * admin/grievance_details.php
 * ---------------------------------------------------------------------------
 * Admin — Grievance Details (list all filed grievances)
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

if (empty($_SESSION['user_id']) || ($sessionRole !== 'ADMIN' && $sessionRole !== 'MANAGEMENT' && $sessionRole !== 'TEACHER')) {
    header('Location: ../login.php?role=admin');
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

$adminData = [
    'username'        => $_SESSION['username'] ?? 'Admin',
    'name'            => '',
    'email'           => '',
    'profile_picture' => '',
];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  u.username,
                        ap.name,
                        ap.email,
                        ap.profile_picture
                FROM users u
                LEFT JOIN admin_profiles ap ON ap.user_id = u.id
                WHERE u.id = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $adminData['username']        = $row['username']        ?? $adminData['username'];
                $adminData['name']            = $row['name']            ?? '';
                $adminData['email']           = $row['email']           ?? '';
                $adminData['profile_picture'] = $row['profile_picture'] ?? '';
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Grievance Details Admin Profile] ' . $ex->getMessage());
    }
}

$displayName  = !empty($adminData['name']) ? $adminData['name'] : $adminData['username'];
$displayEmail = !empty($adminData['email']) ? $adminData['email'] : 'admin@rajagiri.edu';

$hasProfilePicture = false;
$profilePictureUrl = '';
if (!empty($adminData['profile_picture'])) {
    $relativeFromAdmin = '../' . ltrim((string) $adminData['profile_picture'], '/');
    if (file_exists(__DIR__ . '/../' . ltrim((string) $adminData['profile_picture'], '/'))) {
        $hasProfilePicture = true;
        $profilePictureUrl = $relativeFromAdmin;
    }
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

$search  = trim((string) ($_GET['q']       ?? ''));
$entries = (int) ($_GET['entries']          ?? 10);
$page    = (int) ($_GET['page']             ?? 1);

if (!in_array($entries, [10, 25, 50, 100], true)) {
    $entries = 10;
}
if ($page < 1) $page = 1;

$offset = ($page - 1) * $entries;

$grievances = [];
$totalRows  = 0;
$totalPages = 1;

if ($conn instanceof mysqli) {
    try {
        $whereSql = " WHERE 1=1";
        $params   = [];
        $types    = '';

        if ($search !== '') {
            $whereSql .= " AND (
                g.grievance_number LIKE ?
                OR g.subject LIKE ?
                OR gt.type_name LIKE ?
                OR COALESCE(s.name, p.name, cm.name, ap.name) LIKE ?
            )";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types   .= 'ssss';
        }

        $countSql = "SELECT COUNT(*) AS c
                     FROM grievances g
                     LEFT JOIN grievance_types gt ON g.grievance_type_id   = gt.id
                     LEFT JOIN users u            ON g.complainant_user_id = u.id
                     LEFT JOIN students s         ON u.id = s.user_id
                     LEFT JOIN parents p          ON u.id = p.user_id
                     LEFT JOIN cell_members cm    ON u.id = cm.user_id
                     LEFT JOIN admin_profiles ap  ON u.id = ap.user_id
                     $whereSql";

        $stmt = $conn->prepare($countSql);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res       = $stmt->get_result();
            $totalRows = (int) ($res ? ($res->fetch_assoc()['c'] ?? 0) : 0);
            $stmt->close();
        }

        $totalPages = max(1, (int) ceil($totalRows / $entries));

        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $entries;
        }

        $dataSql = "SELECT  g.id,
                            g.grievance_number,
                            g.subject,
                            g.description,
                            g.status,
                            g.reply_details,
                            g.feedback_details,
                            g.created_at,
                            g.updated_at,
                            g.attended_by,
                            gt.type_name,
                            u.role AS complainant_role,
                            COALESCE(s.name, p.name, cm.name, ap.name, u.username) AS complainant_name,
                            COALESCE(s.email, p.email, cm.email, ap.email) AS complainant_email,
                            att_cm.name AS attendee_name
                    FROM grievances g
                    LEFT JOIN grievance_types gt ON g.grievance_type_id   = gt.id
                    LEFT JOIN users u            ON g.complainant_user_id = u.id
                    LEFT JOIN students s         ON u.id = s.user_id
                    LEFT JOIN parents p          ON u.id = p.user_id
                    LEFT JOIN cell_members cm    ON u.id = cm.user_id
                    LEFT JOIN admin_profiles ap  ON u.id = ap.user_id
                    LEFT JOIN cell_members att_cm ON g.attended_by = att_cm.id
                    $whereSql
                    ORDER BY g.id DESC
                    LIMIT ? OFFSET ?";

        $dataParams   = $params;
        $dataTypes    = $types . 'ii';
        $dataParams[] = $entries;
        $dataParams[] = $offset;

        $stmt = $conn->prepare($dataSql);
        if ($stmt) {
            $stmt->bind_param($dataTypes, ...$dataParams);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $grievances[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Grievances] ' . $ex->getMessage());
    }
}

function statusBadgeClass(string $status): string
{
    return match (strtolower($status)) {
        'pending'     => 'bg-[#FFF5D8] text-[#9A6500] border-[#F1DDA1]',
        'in progress' => 'bg-sky-50 text-sky-800 border-sky-200',
        'disposed'    => 'bg-[#DCEFE4] text-leaf border-leaf',
        'closed'      => 'bg-slate-100 text-slate-700 border-slate-300',
        'reopened'    => 'bg-[#FFE0F0] text-hot border-hot',
        default       => 'bg-slate-100 text-slate-700 border-slate-300',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Grievance details — Rajagiri College Grievance Redressal Portal." />
  <meta name="theme-color" content="#DB0878" />
  <title>Grievance Details — Admin | Rajagiri College Grievance Portal</title>
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
    .rise-3 { animation-delay: .24s; }

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
        <a href="grievances.php" class="nav-icon-link group relative w-12 h-12 rounded-xl bg-hot flex items-center justify-center text-white ring-2 ring-sun/60" title="Back to Grievance Hub" aria-label="Back to Grievance Hub">
          <i data-lucide="file-text" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-ink text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50 border border-white/10">Back to Grievance Hub</span>
        </a>
      </nav>

      <a href="#" data-logout-trigger="1" id="sidebarLogoutBtn" class="nav-icon-link group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-hot flex items-center justify-center text-white" title="Logout" aria-label="Logout">
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

          <div class="relative" id="admin-dropdown-container">
            <button id="admin-dropdown-btn" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="admin-dropdown-menu"
                    class="flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 transition-colors hover:bg-blush">
              <?php if ($hasProfilePicture): ?>
                <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>" class="w-10 h-10 rounded-full object-cover border-2 border-ink shadow-[2px_2px_0_#FFC93C]" />
              <?php else: ?>
                <span class="w-10 h-10 rounded-full bg-hot flex items-center justify-center text-white border-2 border-ink shadow-[2px_2px_0_#FFC93C]">
                  <i data-lucide="user" class="w-5 h-5 text-white"></i>
                </span>
              <?php endif; ?>
              <span class="hidden sm:block text-sm font-bold text-ink"><?= e($displayName) ?></span>
              <i data-lucide="chevron-down" id="admin-chevron" class="w-4 h-4 text-slate-500 transition-transform duration-300"></i>
            </button>

            <div id="admin-dropdown-menu" class="hidden absolute right-0 z-50 mt-3 w-72 rounded-xl border-2 border-ink bg-white p-2 shadow-[6px_6px_0_#FFC93C]" role="menu">
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

        <div class="max-w-6xl mx-auto mb-6 rise">
          <h1 class="font-display text-2xl md:text-3xl font-extrabold tracking-tight text-ink mb-2">Grievance Details</h1>
          <nav class="flex flex-wrap items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
            <a href="dashboard.php" class="flex items-center rounded transition-colors hover:text-hot"><i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i> Dashboard</a>
            <span class="text-slate-300">/</span>
            <a href="grievances.php" class="rounded transition-colors hover:text-hot">Grievance</a>
            <span class="text-slate-300">/</span>
            <span class="text-hot font-semibold">Grievance Details</span>
          </nav>
        </div>

        <?php if ($flashSuccess !== ''): ?>
          <div id="flashSuccessBox" class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-leaf bg-[#DCEFE4] px-4 py-3 flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="check-circle" class="w-5 h-5 text-leaf flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-leaf font-semibold"><?= e($flashSuccess) ?></p>
          </div>
        <?php endif; ?>
        <?php if ($flashError !== ''): ?>
          <div id="flashErrorBox" class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-red-300 bg-red-50 px-4 py-3 flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-red-700 font-semibold"><?= e($flashError) ?></p>
          </div>
        <?php endif; ?>

        <div class="max-w-6xl mx-auto mb-5 rise rise-2">
          <form method="GET" action="grievance_details.php" id="filterForm" class="bg-white rounded-2xl border-2 border-ink px-5 py-4 shadow-[4px_4px_0_#FFC93C]">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div class="flex items-center gap-3">
                <span class="text-sm text-slate-600">Show</span>
                <select name="entries" id="entriesPerPage"
                        class="px-3 py-1.5 border-2 border-ink rounded-lg text-sm font-semibold text-ink bg-white focus:outline-none focus:ring-4 focus:ring-hot/10 transition-colors">
                  <option value="10"  <?= $entries === 10  ? 'selected' : '' ?>>10</option>
                  <option value="25"  <?= $entries === 25  ? 'selected' : '' ?>>25</option>
                  <option value="50"  <?= $entries === 50  ? 'selected' : '' ?>>50</option>
                  <option value="100" <?= $entries === 100 ? 'selected' : '' ?>>100</option>
                </select>
                <span class="text-sm text-slate-600">entries</span>
              </div>

              <div class="relative w-full sm:w-80">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                <input type="text" name="q" id="searchInput" value="<?= e($search) ?>" placeholder="Search.." autocomplete="off"
                       class="w-full pl-10 pr-4 py-2 border-2 border-ink rounded-lg text-sm bg-white focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
              </div>
            </div>
          </form>
        </div>

        <div class="max-w-6xl mx-auto rise rise-3">
          <div class="bg-white rounded-2xl border-2 border-ink overflow-hidden shadow-[6px_6px_0_#FFC93C]">
            <div class="overflow-x-auto">
              <table class="w-full" id="grievancesTable">
                <thead>
                  <tr class="bg-ink text-white">
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Sl.No.</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Grievance Number</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Grievance Type</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Name</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Date</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Subject</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Status</th>
                    <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">Actions</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Remainder/ Reopen</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100" id="grievancesTableBody">

                  <?php if (empty($grievances)): ?>
                    <tr>
                      <td colspan="9" class="px-6 py-16 text-center text-slate-500">
                        <div class="flex flex-col items-center justify-center">
                          <span class="w-16 h-16 rounded-full bg-blush border-2 border-ink flex items-center justify-center mb-4">
                            <i data-lucide="inbox" class="w-8 h-8 text-hot"></i>
                          </span>
                          <p class="text-lg font-bold text-ink">No grievances found</p>
                          <p class="text-sm text-slate-500 mt-1 mb-4">
                            <?= $search !== '' ? 'Try adjusting your search.' : 'No grievances have been filed yet.' ?>
                          </p>
                        </div>
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($grievances as $index => $gr): ?>
                      <?php
                        $grId         = (int) $gr['id'];
                        $grNumber     = (string) ($gr['grievance_number']  ?? '');
                        $grType       = (string) ($gr['type_name']         ?? 'N/A');
                        $grName       = (string) ($gr['complainant_name']  ?? 'N/A');
                        $grEmail      = (string) ($gr['complainant_email'] ?? '');
                        $grRole       = (string) ($gr['complainant_role']  ?? '');
                        $grDate       = (string) ($gr['created_at']        ?? '');
                        $grUpdated    = (string) ($gr['updated_at']        ?? '');
                        $grSubject    = (string) ($gr['subject']           ?? '');
                        $grDesc       = (string) ($gr['description']       ?? '');
                        $grReply      = (string) ($gr['reply_details']     ?? '');
                        $grFeedback   = (string) ($gr['feedback_details']  ?? '');
                        $grStatus     = (string) ($gr['status']            ?? 'Pending');
                        $grAttendee   = (string) ($gr['attendee_name']     ?? '');
                        $statusCls    = statusBadgeClass($grStatus);

                        $formattedDate = '—';
                        if ($grDate !== '') {
                            $ts = strtotime($grDate);
                            if ($ts !== false) {
                                $formattedDate = date('Y-m-d', $ts);
                            }
                        }

                        $formattedUpdated = '—';
                        if ($grUpdated !== '') {
                            $ts2 = strtotime($grUpdated);
                            if ($ts2 !== false) {
                                $formattedUpdated = date('d M Y, h:i A', $ts2);
                            }
                        }

                        $globalIndex = $offset + $index + 1;
                      ?>
                      <tr class="grievance-row hover:bg-blush/60 transition-colors group">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900"><?= $globalIndex ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-hot"><?= e($grNumber) ?></td>
                        <td class="px-6 py-4 text-sm text-slate-700 max-w-[220px]"><?= e($grType) ?></td>
                        <td class="px-6 py-4 text-sm text-ink font-semibold max-w-[180px]"><?= e($grName) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600"><?= e($formattedDate) ?></td>
                        <td class="px-6 py-4 text-sm text-slate-700 max-w-[220px]"><?= e($grSubject !== '' ? $grSubject : '—') ?></td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <span class="inline-flex items-center px-3 py-1 text-xs font-bold rounded-full border-2 <?= $statusCls ?>"><?= e($grStatus) ?></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <div class="flex items-center justify-center gap-1.5">
                            <button type="button" title="View grievance" aria-label="View"
                                    onclick='openViewGrievanceModal(
                                        <?= json_encode($grNumber) ?>,
                                        <?= json_encode($grType) ?>,
                                        <?= json_encode($grName) ?>,
                                        <?= json_encode($grEmail) ?>,
                                        <?= json_encode($grRole) ?>,
                                        <?= json_encode($grSubject) ?>,
                                        <?= json_encode($grDesc) ?>,
                                        <?= json_encode($grStatus) ?>,
                                        <?= json_encode($grReply) ?>,
                                        <?= json_encode($grFeedback) ?>,
                                        <?= json_encode($formattedDate) ?>,
                                        <?= json_encode($formattedUpdated) ?>,
                                        <?= json_encode($grAttendee) ?>
                                    )'
                                    class="w-9 h-9 rounded-full bg-blush border-2 border-ink flex items-center justify-center text-ink hover:bg-hot hover:text-white transition-all duration-200">
                              <i data-lucide="eye" class="w-4 h-4"></i>
                            </button>
                            <button type="button" title="Action summary" aria-label="Action summary"
                                    onclick='openActionSummary(
                                        <?= json_encode($grNumber) ?>,
                                        <?= json_encode($grSubject) ?>,
                                        <?= json_encode($formattedDate) ?>,
                                        <?= json_encode($grAttendee !== '' ? $grAttendee : 'No Data') ?>
                                    )'
                                    class="w-9 h-9 rounded-full bg-blush border-2 border-ink flex items-center justify-center text-ink hover:bg-grape hover:text-white transition-all duration-200">
                              <i data-lucide="list" class="w-4 h-4"></i>
                            </button>
                          </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <?php $needsAttention = in_array(strtolower($grStatus), ['reopened', 'pending'], true); ?>
                          <?php if ($needsAttention): ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-bold rounded-full bg-[#FFE0F0] text-hot border-2 border-hot">
                              <i data-lucide="alert-circle" class="w-3.5 h-3.5"></i>
                              Needs Attention
                            </span>
                          <?php else: ?>
                            <span class="text-sm text-slate-400">—</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <?php if ($totalRows > 0): ?>
              <div class="px-6 py-4 bg-blush border-t-2 border-ink flex flex-col sm:flex-row items-center justify-between gap-4">
                <p class="text-sm text-slate-600">
                  Showing <span class="font-semibold text-ink"><?= $offset + 1 ?></span> to
                  <span class="font-semibold text-ink"><?= min($offset + count($grievances), $totalRows) ?></span> of
                  <span class="font-semibold text-ink"><?= $totalRows ?></span> entries
                </p>

                <div class="flex items-center gap-2 flex-wrap justify-center">
                  <?php
                    $qsBase = 'grievance_details.php?entries=' . $entries;
                    if ($search !== '') $qsBase .= '&q=' . urlencode($search);
                  ?>

                  <?php if ($page > 1): ?>
                    <a href="<?= e($qsBase . '&page=' . ($page - 1)) ?>"
                       class="px-4 py-2 rounded-lg text-sm font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">Previous</a>
                  <?php else: ?>
                    <button type="button" class="px-4 py-2 rounded-lg text-sm font-semibold text-slate-400 bg-white border-2 border-slate-200 cursor-not-allowed" disabled>Previous</button>
                  <?php endif; ?>

                  <?php
                    $pageWindow = 2;
                    $startPage  = max(1, $page - $pageWindow);
                    $endPage    = min($totalPages, $page + $pageWindow);

                    if ($startPage > 1) {
                        echo '<a href="' . e($qsBase . '&page=1') . '" class="px-3 py-2 rounded-lg text-sm font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">1</a>';
                        if ($startPage > 2) echo '<span class="px-2 text-slate-400 font-bold">…</span>';
                    }

                    for ($p = $startPage; $p <= $endPage; $p++) {
                        if ($p === $page) {
                            echo '<span class="inline-flex items-center justify-center min-w-[36px] h-9 px-3 rounded-lg bg-hot border-2 border-ink text-white text-sm font-bold shadow-[2px_2px_0_#FFC93C]">' . $p . '</span>';
                        } else {
                            echo '<a href="' . e($qsBase . '&page=' . $p) . '" class="px-3 py-2 rounded-lg text-sm font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">' . $p . '</a>';
                        }
                    }

                    if ($endPage < $totalPages) {
                        if ($endPage < $totalPages - 1) echo '<span class="px-2 text-slate-400 font-bold">…</span>';
                        echo '<a href="' . e($qsBase . '&page=' . $totalPages) . '" class="px-3 py-2 rounded-lg text-sm font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">' . $totalPages . '</a>';
                    }
                  ?>

                  <?php if ($page < $totalPages): ?>
                    <a href="<?= e($qsBase . '&page=' . ($page + 1)) ?>"
                       class="px-4 py-2 rounded-lg text-sm font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">Next</a>
                  <?php else: ?>
                    <button type="button" class="px-4 py-2 rounded-lg text-sm font-semibold text-slate-400 bg-white border-2 border-slate-200 cursor-not-allowed" disabled>Next</button>
                  <?php endif; ?>
                </div>
              </div>
            <?php endif; ?>
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

  <!-- VIEW GRIEVANCE MODAL -->
  <div id="viewGrievanceModal" class="hidden fixed inset-0 z-[65] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeViewGrievanceModal()"></div>
    <div class="relative w-full max-w-3xl bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden max-h-[92vh] flex flex-col">
      <div class="h-1.5 w-full bg-hot"></div>
      <div class="flex items-center justify-between px-6 py-4 border-b-2 border-ink bg-blush">
        <h3 class="font-display text-lg md:text-xl font-bold text-ink">Grievance Details</h3>
        <button type="button" onclick="closeViewGrievanceModal()" aria-label="Close"
                class="w-8 h-8 rounded-lg border-2 border-ink bg-white hover:bg-blush flex items-center justify-center text-ink transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="p-6 overflow-y-auto flex-1 space-y-5">
        <div class="flex items-start gap-4 pb-4 border-b-2 border-slate-100">
          <span class="w-14 h-14 rounded-full bg-hot border-2 border-ink flex items-center justify-center text-white shadow-[2px_2px_0_#FFC93C] flex-shrink-0">
            <i data-lucide="file-text" class="w-7 h-7"></i>
          </span>
          <div class="min-w-0 flex-1">
            <p id="vgNumber" class="text-sm font-bold text-hot break-words">—</p>
            <p id="vgSubject" class="text-base font-bold text-ink break-words mt-0.5">—</p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
              <span id="vgStatus"></span>
              <span class="text-xs text-slate-500">·</span>
              <span class="text-xs text-slate-500" id="vgDate">—</span>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="bg-blush rounded-xl p-3 border-2 border-ink">
            <p class="text-[10px] font-bold uppercase tracking-wider text-hot mb-1">Grievance Type</p>
            <p id="vgType" class="text-sm font-semibold text-ink break-words">—</p>
          </div>
          <div class="bg-blush rounded-xl p-3 border-2 border-ink">
            <p class="text-[10px] font-bold uppercase tracking-wider text-hot mb-1">Complainant</p>
            <p id="vgComplainant" class="text-sm font-semibold text-ink break-words">—</p>
            <p id="vgComplainantMeta" class="text-xs text-slate-500 break-all mt-0.5">—</p>
          </div>
          <div class="bg-blush rounded-xl p-3 border-2 border-ink">
            <p class="text-[10px] font-bold uppercase tracking-wider text-hot mb-1">Submitted On</p>
            <p id="vgSubmitted" class="text-sm font-semibold text-ink">—</p>
          </div>
          <div class="bg-blush rounded-xl p-3 border-2 border-ink">
            <p class="text-[10px] font-bold uppercase tracking-wider text-hot mb-1">Last Updated</p>
            <p id="vgUpdated" class="text-sm font-semibold text-ink">—</p>
          </div>
          <div class="bg-blush rounded-xl p-3 border-2 border-ink sm:col-span-2">
            <p class="text-[10px] font-bold uppercase tracking-wider text-hot mb-1">Attendee</p>
            <p id="vgAttendee" class="text-sm font-semibold text-ink break-words">No Data</p>
          </div>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-hot mb-1.5">Description</p>
          <div class="bg-blush rounded-xl p-4 border-2 border-ink">
            <p id="vgDescription" class="text-sm text-slate-700 leading-relaxed whitespace-pre-line break-words">—</p>
          </div>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-leaf mb-1.5">Reply / Response</p>
          <div class="bg-[#DCEFE4] rounded-xl p-4 border-2 border-leaf">
            <p id="vgReply" class="text-sm text-leaf leading-relaxed whitespace-pre-line break-words">—</p>
          </div>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-[#B37A00] mb-1.5">Feedback</p>
          <div class="bg-[#FFF5D8] rounded-xl p-4 border-2 border-[#F1DDA1]">
            <p id="vgFeedback" class="text-sm text-[#9A6500] leading-relaxed whitespace-pre-line break-words">—</p>
          </div>
        </div>
      </div>

      <div class="px-6 py-4 bg-blush border-t-2 border-ink flex justify-end">
        <button type="button" onclick="closeViewGrievanceModal()"
                class="px-5 py-2.5 rounded-lg font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">Close</button>
      </div>
    </div>
  </div>

  <!-- ACTION SUMMARY MODAL -->
  <div id="actionSummaryModal" class="hidden fixed inset-0 z-[65] flex items-start justify-center p-4 pt-20">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeActionSummary()"></div>
    <div class="relative w-full max-w-2xl bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden">
      <div class="bg-hot px-5 py-2.5 border-b-2 border-ink">
        <p id="asTitle" class="text-sm font-semibold text-white truncate">—</p>
      </div>
      <div class="flex items-center justify-between px-6 pt-5 pb-3">
        <h3 class="font-display text-xl font-bold text-ink">Action Summary</h3>
        <button type="button" onclick="closeActionSummary()" aria-label="Close"
                class="w-8 h-8 rounded-lg border-2 border-ink bg-white hover:bg-blush flex items-center justify-center text-ink transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      <div class="px-6 pb-6">
        <div class="border-2 border-ink rounded-xl overflow-hidden">
          <div class="flex items-center justify-between px-4 py-4 border-b-2 border-ink bg-blush">
            <span class="text-sm font-semibold text-ink">Date</span>
            <span id="asDate" class="text-sm font-semibold text-ink">No Date</span>
          </div>
          <div class="flex items-center justify-between px-4 py-4 bg-white">
            <span class="text-sm font-semibold text-ink">Attendee</span>
            <span id="asAttendee" class="text-sm font-semibold text-ink">No Data</span>
          </div>
        </div>
      </div>
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
      const btn = document.getElementById('admin-dropdown-btn');
      const menu = document.getElementById('admin-dropdown-menu');
      const chevron = document.getElementById('admin-chevron');
      const container = document.getElementById('admin-dropdown-container');
      if (!btn || !menu || !container) return;
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        const open = !menu.classList.contains('hidden');
        if (open) { menu.classList.add('hidden'); if (chevron) chevron.classList.remove('rotate-180'); btn.setAttribute('aria-expanded', 'false'); }
        else { menu.classList.remove('hidden'); if (chevron) chevron.classList.add('rotate-180'); btn.setAttribute('aria-expanded', 'true'); }
      });
      document.addEventListener('click', function (e) {
        if (!container.contains(e.target)) { menu.classList.add('hidden'); if (chevron) chevron.classList.remove('rotate-180'); btn.setAttribute('aria-expanded', 'false'); }
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { menu.classList.add('hidden'); if (chevron) chevron.classList.remove('rotate-180'); btn.setAttribute('aria-expanded', 'false'); }
      });
    })();

    const viewGrievanceModal = document.getElementById('viewGrievanceModal');

    function statusBadgeHtml(status) {
      const s = (status || '').trim();
      const map = {
        'Pending':     'bg-[#FFF5D8] text-[#9A6500] border-[#F1DDA1]',
        'In Progress': 'bg-sky-50 text-sky-800 border-sky-200',
        'Disposed':    'bg-[#DCEFE4] text-leaf border-leaf',
        'Closed':      'bg-slate-100 text-slate-700 border-slate-300',
        'Reopened':    'bg-[#FFE0F0] text-hot border-hot'
      };
      const cls = map[s] || 'bg-slate-100 text-slate-700 border-slate-300';
      return '<span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-bold uppercase tracking-wider border-2 ' + cls + '">' + s + '</span>';
    }

    function openViewGrievanceModal(number, type, name, email, role, subject, description,
                                    status, reply, feedback, date, updated, attendee) {
      if (!viewGrievanceModal) return;
      document.getElementById('vgNumber').textContent    = number || '—';
      document.getElementById('vgSubject').textContent   = subject || '—';
      document.getElementById('vgStatus').innerHTML      = statusBadgeHtml(status);
      document.getElementById('vgDate').textContent      = date || '—';
      document.getElementById('vgType').textContent      = type || '—';
      document.getElementById('vgComplainant').textContent = name || '—';
      const metaParts = [];
      if (role)  metaParts.push(role);
      if (email) metaParts.push(email);
      document.getElementById('vgComplainantMeta').textContent = metaParts.length ? metaParts.join(' · ') : '—';
      document.getElementById('vgSubmitted').textContent = date || '—';
      document.getElementById('vgUpdated').textContent   = updated || '—';
      document.getElementById('vgAttendee').textContent  = (attendee && attendee.trim() !== '') ? attendee : 'No Data';
      document.getElementById('vgDescription').textContent = (description && description.trim() !== '') ? description : 'No description provided.';
      document.getElementById('vgReply').textContent = (reply && reply.trim() !== '') ? reply : 'No reply yet from the committee.';
      document.getElementById('vgFeedback').textContent = (feedback && feedback.trim() !== '') ? feedback : 'No feedback recorded.';
      viewGrievanceModal.classList.remove('hidden');
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeViewGrievanceModal() { viewGrievanceModal.classList.add('hidden'); }
    document.getElementById('viewGrievanceModal').addEventListener('click', function (e) { if (e.target.id === 'viewGrievanceModal') closeViewGrievanceModal(); });

    const actionSummaryModal = document.getElementById('actionSummaryModal');
    function openActionSummary(grievanceNumber, subject, date, attendee) {
      if (!actionSummaryModal) return;
      const bannerText = (subject && subject.trim() !== '') ? grievanceNumber + ' | ' + subject : grievanceNumber;
      document.getElementById('asTitle').textContent = bannerText || '—';
      document.getElementById('asDate').textContent = (date && date.trim() !== '') ? date : 'No Date';
      document.getElementById('asAttendee').textContent = (attendee && attendee.trim() !== '') ? attendee : 'No Data';
      actionSummaryModal.classList.remove('hidden');
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeActionSummary() { actionSummaryModal.classList.add('hidden'); }
    document.getElementById('actionSummaryModal').addEventListener('click', function (e) { if (e.target.id === 'actionSummaryModal') closeActionSummary(); });

    const logoutConfirmModal = document.getElementById('logoutConfirmModal');
    const logoutConfirmPanel = document.getElementById('logoutConfirmPanel');
    const confirmLogoutBtn = document.getElementById('confirmLogoutBtn');
    const LOGOUT_URL = '../logout.php?role=admin';
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
      if (e.key !== 'Escape') return;
      if (viewGrievanceModal && !viewGrievanceModal.classList.contains('hidden')) closeViewGrievanceModal();
      if (actionSummaryModal && !actionSummaryModal.classList.contains('hidden')) closeActionSummary();
      if (logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) closeLogoutModal();
    });

    (function () {
      const entriesSelect = document.getElementById('entriesPerPage');
      const filterForm = document.getElementById('filterForm');
      if (!entriesSelect || !filterForm) return;
      entriesSelect.addEventListener('change', function () { filterForm.submit(); });
    })();

    (function () {
      const searchInput = document.getElementById('searchInput');
      const tableBody = document.getElementById('grievancesTableBody');
      if (!searchInput || !tableBody) return;
      let debounceTimer = null;
      searchInput.addEventListener('input', function () {
        const term = this.value.toLowerCase().trim();
        tableBody.querySelectorAll('tr').forEach(function (row) {
          row.style.display = (term === '' || row.textContent.toLowerCase().indexOf(term) !== -1) ? '' : 'none';
        });
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
          const form = document.getElementById('filterForm');
          if (form) form.submit();
        }, 600);
      });
    })();
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>