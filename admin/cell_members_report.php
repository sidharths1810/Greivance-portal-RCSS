<?php
/**
 * admin/cell_members_report.php
 * ---------------------------------------------------------------------------
 * Admin — Cell Members Report
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
$allowedRoles = ['ADMIN', 'MANAGEMENT', 'TEACHER'];

if (empty($_SESSION['user_id']) || !in_array($sessionRole, $allowedRoles, true)) {
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

function isValidDate(string $d): bool
{
    if ($d === '') return false;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
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
        error_log('[Cell Members Report Admin Profile] ' . $ex->getMessage());
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

$classOptions         = [];
$departmentOptions    = [];
$designationOptions   = [];
$grievanceTypeOptions = [];

if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, class_name FROM classes WHERE status = 'Active' ORDER BY class_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $classOptions[] = $row;
    } catch (Throwable $ex) { error_log('[Fetch Classes] ' . $ex->getMessage()); }

    try {
        $res = $conn->query("SELECT id, department_name FROM departments WHERE status = 'Active' ORDER BY department_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $departmentOptions[] = $row;
    } catch (Throwable $ex) { error_log('[Fetch Departments] ' . $ex->getMessage()); }

    try {
        $res = $conn->query("SELECT id, designation_name FROM designations WHERE status = 'Active' ORDER BY designation_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $designationOptions[] = $row;
    } catch (Throwable $ex) { error_log('[Fetch Designations] ' . $ex->getMessage()); }

    try {
        $res = $conn->query("SELECT id, type_name FROM grievance_types WHERE status = 'Active' ORDER BY type_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $grievanceTypeOptions[] = $row;
    } catch (Throwable $ex) { error_log('[Fetch Grievance Types] ' . $ex->getMessage()); }
}

$today       = date('Y-m-d');
$defaultFrom = date('Y-m-d', strtotime('-30 days'));

$filterFrom        = trim((string) ($_GET['from']            ?? $defaultFrom));
$filterTo          = trim((string) ($_GET['to']              ?? $today));
$filterClass       = (int) ($_GET['class_id']                ?? 0);
$filterDepartment  = (int) ($_GET['department_id']           ?? 0);
$filterDesignation = (int) ($_GET['designation_id']          ?? 0);
$filterType        = (int) ($_GET['grievance_type_id']       ?? 0);
$filterStatus      = trim((string) ($_GET['status']          ?? ''));

$validStatuses = ['', 'Approved', 'Pending', 'Rejected', 'Terminated'];
if (!in_array($filterStatus, $validStatuses, true)) {
    $filterStatus = '';
}

if (!isValidDate($filterFrom)) $filterFrom = $defaultFrom;
if (!isValidDate($filterTo))   $filterTo   = $today;

if (strtotime($filterFrom) > strtotime($filterTo)) {
    [$filterFrom, $filterTo] = [$filterTo, $filterFrom];
}

$rows = [];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  cm.id,
                        cm.name,
                        cm.email,
                        cm.mobile_number,
                        cm.member_type,
                        cm.designation_id,
                        cm.department_id,
                        cm.grievance_type_id,
                        d.designation_name,
                        dept.department_name,
                        gt.type_name,
                        u.status AS user_status,
                        u.created_at AS user_created_at
                FROM cell_members cm
                LEFT JOIN users u            ON cm.user_id            = u.id
                LEFT JOIN designations d     ON cm.designation_id     = d.id
                LEFT JOIN departments dept   ON cm.department_id      = dept.id
                LEFT JOIN grievance_types gt ON cm.grievance_type_id  = gt.id
                WHERE DATE(u.created_at) BETWEEN ? AND ?";

        $params = [$filterFrom, $filterTo];
        $types  = 'ss';

        if ($filterClass > 0) {
            // Class filter applies to students; for cell members it has no direct column,
            // so we don't join students here. Left as a soft no-op to preserve the UI.
        }

        if ($filterDepartment > 0) {
            $sql .= " AND cm.department_id = ?";
            $params[] = $filterDepartment;
            $types   .= 'i';
        }

        if ($filterDesignation > 0) {
            $sql .= " AND cm.designation_id = ?";
            $params[] = $filterDesignation;
            $types   .= 'i';
        }

        if ($filterType > 0) {
            $sql .= " AND cm.grievance_type_id = ?";
            $params[] = $filterType;
            $types   .= 'i';
        }

        if ($filterStatus !== '') {
            $sql .= " AND u.status = ?";
            $params[] = $filterStatus;
            $types   .= 's';
        }

        $sql .= " ORDER BY cm.id ASC";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Cell Members Report] ' . $ex->getMessage());
    }
}

function findName(array $list, int $id, string $key): string
{
    foreach ($list as $item) {
        if ((int) $item['id'] === $id) return (string) $item[$key];
    }
    return 'All';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Cell members report — Rajagiri College Grievance Redressal Portal." />
  <meta name="theme-color" content="#DB0878" />
  <title>Cell Members Report — RCSS Admin</title>
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

    .panel-hard { border: 2px solid #1F0A24; box-shadow: 6px 6px 0 #FFC93C; }

    @keyframes rise { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: none; } }
    .rise { animation: rise .6s cubic-bezier(.2, .7, .2, 1) both; }
    .rise-2 { animation-delay: .12s; }
    .rise-3 { animation-delay: .24s; }

    @keyframes modalIn { from { opacity: 0; transform: scale(.96); } to { opacity: 1; transform: scale(1); } }
    .animate-modal-in { animation: modalIn .25s cubic-bezier(.16, 1, .3, 1) forwards; }

    @keyframes confirmShake {
      0%, 100% { transform: translateX(0); } 20% { transform: translateX(-6px); } 40% { transform: translateX(6px); }
      60% { transform: translateX(-4px); } 80% { transform: translateX(4px); }
    }
    .animate-confirm-shake { animation: confirmShake .5s cubic-bezier(.16, 1, .3, 1); }

    .nav-icon-link { transition: transform .18s ease, background-color .18s ease, box-shadow .18s ease; }
    .nav-icon-link:hover { transform: translateY(-2px); }

    @media print {
      body * { visibility: hidden; }
      #reportSection, #reportSection * { visibility: visible; }
      #reportSection { position: absolute; left: 0; top: 0; width: 100%; padding: 0 !important; margin: 0 !important; }
      .no-print { display: none !important; }
      .report-table { width: 100%; border-collapse: collapse; font-size: 11px; }
      .report-table th, .report-table td { border: 1px solid #333; padding: 6px 8px; text-align: left; }
      .report-table th { background-color: #f3f3f3 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      @page { margin: 12mm; }
    }

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

    <aside class="w-20 on-ink bg-ink flex flex-col items-center py-4 fixed inset-y-0 left-0 z-40 no-print">
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
        <a href="grievance_report.php" class="nav-icon-link group relative w-12 h-12 rounded-xl bg-hot flex items-center justify-center text-white ring-2 ring-sun/60" title="Back to Reports Hub" aria-label="Back to Reports Hub">
          <i data-lucide="clipboard-list" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-ink text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50 border border-white/10">Back to Reports Hub</span>
        </a>
      </nav>

      <a href="#" data-logout-trigger="1" id="sidebarLogoutBtn" class="nav-icon-link group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-hot flex items-center justify-center text-white" title="Logout" aria-label="Logout">
        <i data-lucide="log-out" class="w-6 h-6 group-hover:translate-x-0.5 transition-transform"></i>
        <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-hot text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50">Logout</span>
      </a>
    </aside>

    <div class="flex-1 ml-20 flex flex-col min-h-screen">

      <header class="sticky top-0 z-30 bg-white border-b border-slate-200 no-print">
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
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
              <h1 class="font-display text-2xl md:text-3xl font-extrabold tracking-tight text-ink mb-2">Cell Members Report</h1>
              <nav class="flex flex-wrap items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
                <a href="dashboard.php" class="flex items-center rounded transition-colors hover:text-hot"><i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i> Dashboard</a>
                <span class="text-slate-300">/</span>
                <a href="grievance_report.php" class="rounded transition-colors hover:text-hot">Grievance Reports</a>
                <span class="text-slate-300">/</span>
                <span class="text-hot font-semibold">Cell Members Report</span>
              </nav>
            </div>

            <button type="button" onclick="window.print()"
                    class="btn-hard inline-flex items-center justify-center gap-2 rounded-xl bg-sun px-5 py-3 text-sm font-bold text-ink hover:bg-[#FFD35C] no-print">
              <i data-lucide="printer" class="w-4 h-4"></i>
              <span>Print report</span>
            </button>
          </div>
        </div>

        <!-- FILTER CARD -->
        <div class="max-w-6xl mx-auto mb-6 rise rise-2 no-print">
          <div class="bg-white rounded-2xl border-2 border-ink shadow-[6px_6px_0_#FFC93C] overflow-hidden">
            <div class="flex items-center justify-between px-6 py-4 border-b-2 border-ink bg-blush">
              <div class="flex items-center gap-3">
                <span class="w-9 h-9 rounded-lg bg-hot border-2 border-ink flex items-center justify-center">
                  <i data-lucide="sliders-horizontal" class="w-5 h-5 text-white"></i>
                </span>
                <h2 class="font-display font-bold text-ink">Report filters</h2>
              </div>
              <span class="text-xs font-semibold text-slate-500">Registration date · member profile · status</span>
            </div>

            <div class="p-6">
              <form method="GET" action="cell_members_report.php">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                  <div class="space-y-2">
                    <label for="from" class="block text-sm font-semibold text-ink">From date</label>
                    <input type="date" name="from" id="from" value="<?= e($filterFrom) ?>"
                           class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
                  </div>

                  <div class="space-y-2">
                    <label for="to" class="block text-sm font-semibold text-ink">To date</label>
                    <input type="date" name="to" id="to" value="<?= e($filterTo) ?>"
                           class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
                  </div>

                  <div class="space-y-2">
                    <label for="class_id" class="block text-sm font-semibold text-ink">Class</label>
                    <select name="class_id" id="class_id"
                            class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all">
                      <option value="0">All classes</option>
                      <?php foreach ($classOptions as $cl): ?>
                        <option value="<?= (int) $cl['id'] ?>" <?= $filterClass === (int) $cl['id'] ? 'selected' : '' ?>><?= e($cl['class_name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="space-y-2">
                    <label for="department_id" class="block text-sm font-semibold text-ink">Department</label>
                    <select name="department_id" id="department_id"
                            class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all">
                      <option value="0">All departments</option>
                      <?php foreach ($departmentOptions as $d): ?>
                        <option value="<?= (int) $d['id'] ?>" <?= $filterDepartment === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['department_name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="space-y-2">
                    <label for="designation_id" class="block text-sm font-semibold text-ink">Designation</label>
                    <select name="designation_id" id="designation_id"
                            class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all">
                      <option value="0">All designations</option>
                      <?php foreach ($designationOptions as $dg): ?>
                        <option value="<?= (int) $dg['id'] ?>" <?= $filterDesignation === (int) $dg['id'] ? 'selected' : '' ?>><?= e($dg['designation_name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="space-y-2">
                    <label for="grievance_type_id" class="block text-sm font-semibold text-ink">Grievance type</label>
                    <select name="grievance_type_id" id="grievance_type_id"
                            class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all">
                      <option value="0">All types</option>
                      <?php foreach ($grievanceTypeOptions as $gt): ?>
                        <option value="<?= (int) $gt['id'] ?>" <?= $filterType === (int) $gt['id'] ? 'selected' : '' ?>><?= e($gt['type_name']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="space-y-2">
                    <label for="status" class="block text-sm font-semibold text-ink">Status</label>
                    <select name="status" id="status"
                            class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all">
                      <option value="">All statuses</option>
                      <option value="Approved"   <?= $filterStatus === 'Approved'   ? 'selected' : '' ?>>Approved</option>
                      <option value="Pending"    <?= $filterStatus === 'Pending'    ? 'selected' : '' ?>>Pending</option>
                      <option value="Rejected"   <?= $filterStatus === 'Rejected'   ? 'selected' : '' ?>>Rejected</option>
                      <option value="Terminated" <?= $filterStatus === 'Terminated' ? 'selected' : '' ?>>Terminated</option>
                    </select>
                  </div>
                </div>

                <div class="mt-5 pt-5 border-t-2 border-dashed border-slate-200 flex flex-col sm:flex-row gap-3 sm:justify-end">
                  <a href="cell_members_report.php"
                     class="inline-flex items-center justify-center gap-2 rounded-xl bg-white border-2 border-ink px-6 py-3 text-sm font-bold text-ink hover:bg-blush transition-colors">
                    Reset
                  </a>
                  <button type="submit"
                          class="btn-hard inline-flex items-center justify-center gap-2 rounded-xl bg-hot px-8 py-3 text-sm font-bold text-white hover:bg-hotdark">
                    <i data-lucide="search" class="w-4 h-4"></i>
                    <span>Generate report</span>
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- REPORT -->
        <div class="max-w-6xl mx-auto rise rise-3">
          <section class="bg-white rounded-2xl border-2 border-ink shadow-[6px_6px_0_#FFC93C] overflow-hidden" id="reportSection">
            <div class="p-6 border-b-2 border-ink bg-white flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
              <div class="flex items-center gap-4">
                <img src="../public/rcss-logo.png" alt="RCSS" class="h-12 w-auto" />
                <div>
                  <h3 class="font-display font-bold text-ink text-lg">Cell Members Report</h3>
                  <p class="text-xs text-slate-500">Rajagiri College of Social Sciences · Grievance Redressal Portal</p>
                </div>
              </div>
              <div class="text-right text-xs text-slate-500">
                <strong class="text-ink text-sm"><?= e(date('d-m-Y')) ?></strong><br>
                <span class="font-display font-extrabold text-ink text-lg"><?= count($rows) ?></span> record(s)
              </div>
            </div>

            <div class="overflow-x-auto">
              <table class="w-full report-table">
                <thead>
                  <tr class="bg-ink text-white">
                    <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider">Sl.No.</th>
                    <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider">Email ID</th>
                    <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider">Mobile</th>
                    <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider">Designation</th>
                    <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider">Status</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                  <?php if (empty($rows)): ?>
                    <tr>
                      <td colspan="6" class="px-6 py-16 text-center text-slate-500">
                        <div class="flex flex-col items-center justify-center">
                          <span class="w-16 h-16 rounded-full bg-blush border-2 border-ink flex items-center justify-center mb-4">
                            <i data-lucide="users-round" class="w-8 h-8 text-hot"></i>
                          </span>
                          <p class="text-lg font-bold text-ink">No cell members match the selected filters.</p>
                          <p class="text-sm text-slate-500 mt-1">Try broadening the date range or filters.</p>
                        </div>
                      </td>
                    </tr>
                  <?php else: foreach ($rows as $i => $r):
                    $st  = (string) ($r['user_status'] ?? '—');
                    $cls = strtolower($st) === 'approved'
                        ? 'bg-[#DCEFE4] text-leaf border-leaf'
                        : (strtolower($st) === 'pending'
                            ? 'bg-[#FFF5D8] text-[#9A6500] border-[#F1DDA1]'
                            : 'bg-blush text-hot border-hot');
                  ?>
                    <tr class="hover:bg-blush/60 transition-colors">
                      <td class="px-4 py-3 text-sm text-slate-700"><?= $i + 1 ?></td>
                      <td class="px-4 py-3 text-sm font-bold text-ink"><?= e($r['name'] ?? '—') ?></td>
                      <td class="px-4 py-3 text-sm text-slate-600 break-all"><?= e($r['email'] ?? '—') ?></td>
                      <td class="px-4 py-3 text-sm text-slate-600 whitespace-nowrap"><?= e($r['mobile_number'] ?? '—') ?></td>
                      <td class="px-4 py-3 text-sm text-slate-700"><?= e($r['designation_name'] ?? '—') ?></td>
                      <td class="px-4 py-3 whitespace-nowrap">
                        <span class="inline-flex items-center px-3 py-1 text-xs font-bold rounded-full border-2 <?= $cls ?>"><?= e($st) ?></span>
                      </td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>

            <div class="px-6 py-4 border-t-2 border-ink bg-blush text-xs text-slate-600">
              Generated <?= date('d-m-Y H:i') ?> · Period <?= e(date('d/m/Y', strtotime($filterFrom))) ?> – <?= e(date('d/m/Y', strtotime($filterTo))) ?>
            </div>
          </section>
        </div>
      </main>

      <footer class="on-ink bg-ink border-t-[6px] border-sun mt-auto no-print">
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
                  <li><a href="grievance_report.php" class="inline-flex items-center gap-2 rounded transition-colors hover:text-white hover:underline underline-offset-4 group"><i data-lucide="arrow-right" class="w-3 h-3 text-sun group-hover:translate-x-0.5 transition-transform"></i><span>Reports Hub</span></a></li>
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

  <!-- LOGOUT CONFIRM -->
  <div id="logoutConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4 no-print">
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
      if (e.key === 'Escape' && logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) closeLogoutModal();
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>