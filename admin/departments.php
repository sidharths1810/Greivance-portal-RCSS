<?php
/**
 * admin/departments.php
 * ---------------------------------------------------------------------------
 * Admin — Department Management
 * Rajagiri College Grievance Redressal Portal
 *
 * Features:
 *   • View all departments in a searchable table
 *   • Create / Edit / Delete departments
 *   • Toggle status (Active / Inactive)
 *   • Live search + entries-per-page dropdown
 *   • Themed delete & toggle confirmation modals
 *   • Flash messages auto-dismiss after 3 seconds
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
        $sql = "SELECT  u.username, ap.name, ap.email, ap.profile_picture
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
        error_log('[Departments Admin Profile] ' . $ex->getMessage());
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn instanceof mysqli) {

    $action = $_POST['action'] ?? '';

    if ($action === 'create_department') {
        $departmentName = trim((string) ($_POST['department_name'] ?? ''));
        $description    = trim((string) ($_POST['description']     ?? ''));

        if ($departmentName === '') {
            $flashError = 'Department name is required.';
        }

        if ($flashError === '') {
            try {
                $stmt = $conn->prepare("INSERT INTO departments (department_name, description, status) VALUES (?, ?, 'Active')");
                $stmt->bind_param('ss', $departmentName, $description);
                if ($stmt->execute()) {
                    $flashSuccess = 'Department created successfully.';
                } else {
                    $flashError = 'Failed to create department.';
                }
                $stmt->close();
            } catch (Throwable $ex) {
                error_log('[Create Department] ' . $ex->getMessage());
                $flashError = 'A system error occurred while creating the department.';
            }
        }
    }

    if ($action === 'edit_department') {
        $departmentId   = (int) ($_POST['department_id']   ?? 0);
        $departmentName = trim((string) ($_POST['department_name'] ?? ''));
        $description    = trim((string) ($_POST['description']     ?? ''));
        $status         = trim((string) ($_POST['status']          ?? 'Active'));

        if (!in_array($status, ['Active', 'Inactive'], true)) {
            $status = 'Active';
        }

        if ($departmentId <= 0) {
            $flashError = 'Invalid department.';
        } elseif ($departmentName === '') {
            $flashError = 'Department name is required.';
        }

        if ($flashError === '') {
            try {
                $stmt = $conn->prepare("UPDATE departments SET department_name = ?, description = ?, status = ? WHERE id = ?");
                $stmt->bind_param('sssi', $departmentName, $description, $status, $departmentId);
                if ($stmt->execute()) {
                    $flashSuccess = 'Department updated successfully.';
                } else {
                    $flashError = 'Failed to update department.';
                }
                $stmt->close();
            } catch (Throwable $ex) {
                error_log('[Edit Department] ' . $ex->getMessage());
                $flashError = 'A system error occurred while updating the department.';
            }
        }
    }

    if ($action === 'delete_department') {
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        if ($departmentId > 0) {
            try {
                $stmt = $conn->prepare("DELETE FROM departments WHERE id = ?");
                $stmt->bind_param('i', $departmentId);
                if ($stmt->execute()) {
                    $flashSuccess = 'Department deleted successfully.';
                } else {
                    $flashError = 'Failed to delete department.';
                }
                $stmt->close();
            } catch (Throwable $ex) {
                error_log('[Delete Department] ' . $ex->getMessage());
                $flashError = 'A system error occurred while deleting the department.';
            }
        }
    }

    if ($action === 'toggle_status') {
        $departmentId = (int) ($_POST['department_id'] ?? 0);
        if ($departmentId > 0) {
            try {
                $stmtG = $conn->prepare("SELECT status FROM departments WHERE id = ? LIMIT 1");
                $stmtG->bind_param('i', $departmentId);
                $stmtG->execute();
                $resG = $stmtG->get_result();
                $currentStatus = $resG && $resG->num_rows > 0 ? (string) ($resG->fetch_assoc()['status'] ?? 'Active') : 'Active';
                $stmtG->close();

                $newStatus = (strcasecmp($currentStatus, 'Active') === 0) ? 'Inactive' : 'Active';

                $stmt = $conn->prepare("UPDATE departments SET status = ? WHERE id = ?");
                $stmt->bind_param('si', $newStatus, $departmentId);
                if ($stmt->execute()) {
                    $flashSuccess = 'Department status updated to ' . $newStatus . '.';
                } else {
                    $flashError = 'Failed to update department status.';
                }
                $stmt->close();
            } catch (Throwable $ex) {
                error_log('[Toggle Status] ' . $ex->getMessage());
                $flashError = 'A system error occurred while updating the status.';
            }
        }
    }

    if ($flashSuccess !== '' || $flashError !== '') {
        $_SESSION['flash_success'] = $flashSuccess;
        $_SESSION['flash_error']   = $flashError;
        header('Location: departments.php');
        exit;
    }
}

if (!empty($_SESSION['flash_success'])) {
    $flashSuccess = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $flashError = (string) $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

$departments = [];

if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, department_name, description, status, created_at FROM departments ORDER BY id ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $departments[] = $row;
            }
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Departments] ' . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Department management — Rajagiri College Grievance Redressal Portal." />
  <meta name="theme-color" content="#DB0878" />
  <title>Department — Admin | Rajagiri College Grievance Portal</title>
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
        <a href="settings.php" class="nav-icon-link group relative w-12 h-12 rounded-xl bg-hot flex items-center justify-center text-white ring-2 ring-sun/60" title="Settings" aria-label="Settings">
          <i data-lucide="settings" class="w-6 h-6 group-hover:rotate-90 transition-transform duration-500"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-ink text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50 border border-white/10">Settings</span>
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
              <a href="settings.php" role="menuitem" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 transition-colors hover:bg-blush hover:text-ink group">
                <i data-lucide="settings" class="w-4 h-4 text-hot group-hover:scale-110 transition-transform"></i>
                <span class="font-semibold">Settings</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-ink transition-opacity"></i>
              </a>
              <a href="change_password.php" role="menuitem" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 transition-colors hover:bg-blush hover:text-ink group">
                <i data-lucide="key" class="w-4 h-4 text-[#B37A00] group-hover:scale-110 transition-transform"></i>
                <span class="font-semibold">Change Password</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-ink transition-opacity"></i>
              </a>

              <div class="my-2 border-t border-slate-200" role="separator"></div>

              <a href="../logout.php?role=admin" id="dropdownLogoutBtn" role="menuitem" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 transition-colors hover:bg-blush hover:text-hot group">
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
              <h1 class="font-display text-2xl md:text-3xl font-extrabold tracking-tight text-ink mb-2">Department</h1>
              <nav class="flex flex-wrap items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
                <a href="dashboard.php" class="flex items-center rounded transition-colors hover:text-hot"><i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i> Dashboard</a>
                <span class="text-slate-300">/</span>
                <a href="settings.php" class="rounded transition-colors hover:text-hot">Settings</a>
                <span class="text-slate-300">/</span>
                <span class="text-hot font-semibold">Department</span>
              </nav>
            </div>

            <button type="button" onclick="openDepartmentModal('add')" title="Add Department" aria-label="Add department"
                    class="btn-hard inline-flex items-center justify-center w-11 h-11 rounded-xl bg-hot text-white hover:bg-hotdark">
              <i data-lucide="plus" class="w-5 h-5"></i>
            </button>
          </div>
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
          <div class="bg-white rounded-2xl border-2 border-ink px-5 py-4 shadow-[4px_4px_0_#FFC93C]">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div class="flex items-center gap-3">
                <span class="text-sm text-slate-600">Show</span>
                <select id="entriesPerPage" class="px-3 py-1.5 border-2 border-ink rounded-lg text-sm font-semibold text-ink bg-white focus:outline-none focus:ring-4 focus:ring-hot/10 transition-colors">
                  <option value="10" selected>10</option>
                  <option value="25">25</option>
                  <option value="50">50</option>
                  <option value="100">100</option>
                </select>
                <span class="text-sm text-slate-600">entries</span>
              </div>

              <div class="relative w-full sm:w-80">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                <input type="text" id="searchInput" placeholder="Search.."
                       class="w-full pl-10 pr-4 py-2 border-2 border-ink rounded-lg text-sm bg-white focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
              </div>
            </div>
          </div>
        </div>

        <div class="max-w-6xl mx-auto rise rise-3">
          <div class="bg-white rounded-2xl border-2 border-ink overflow-hidden shadow-[6px_6px_0_#FFC93C]">
            <div class="overflow-x-auto">
              <table class="w-full" id="departmentsTable">
                <thead>
                  <tr class="bg-ink text-white">
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Sl.No.</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Department</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Description</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Status</th>
                    <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100" id="departmentsTableBody">

                  <?php if (empty($departments)): ?>
                    <tr>
                      <td colspan="5" class="px-6 py-16 text-center text-slate-500">
                        <div class="flex flex-col items-center justify-center">
                          <span class="w-16 h-16 rounded-full bg-blush border-2 border-ink flex items-center justify-center mb-4">
                            <i data-lucide="building-2" class="w-8 h-8 text-hot"></i>
                          </span>
                          <p class="text-lg font-bold text-ink">No departments yet</p>
                          <p class="text-sm text-slate-500 mt-1 mb-4">Click "Add Department" to create your first department.</p>
                        </div>
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($departments as $index => $department): ?>
                      <?php
                        $departmentId   = (int) $department['id'];
                        $departmentName = (string) ($department['department_name'] ?? '');
                        $departmentDesc = (string) ($department['description']     ?? '');
                        $status         = (string) ($department['status']          ?? 'Active');
                        $isActive       = (strcasecmp($status, 'Active') === 0);
                        $statusCls      = $isActive ? 'bg-[#DCEFE4] text-leaf border-leaf' : 'bg-slate-100 text-slate-700 border-slate-300';
                      ?>
                      <tr class="department-row hover:bg-blush/60 transition-colors group">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900"><?= $index + 1 ?></td>
                        <td class="px-6 py-4 text-sm font-bold text-ink"><?= e($departmentName) ?></td>
                        <td class="px-6 py-4 text-sm text-slate-600 max-w-[320px]"><?= e($departmentDesc !== '' ? $departmentDesc : '—') ?></td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <span class="inline-flex items-center px-3 py-1 text-xs font-bold rounded-full border-2 <?= $statusCls ?>"><?= e($status) ?></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <div class="flex items-center justify-center gap-1.5">
                            <button type="button" title="Edit department" aria-label="Edit department"
                                    onclick='openDepartmentModal("edit", <?= $departmentId ?>, <?= json_encode($departmentName) ?>, <?= json_encode($departmentDesc) ?>, <?= json_encode($status) ?>)'
                                    class="w-9 h-9 rounded-full bg-blush border-2 border-ink flex items-center justify-center text-ink hover:bg-hot hover:text-white transition-all duration-200">
                              <i data-lucide="pencil" class="w-4 h-4"></i>
                            </button>
                            <button type="button" title="Delete department" aria-label="Delete department"
                                    onclick='confirmDeleteDepartment(<?= $departmentId ?>, <?= json_encode($departmentName) ?>)'
                                    class="w-9 h-9 rounded-full bg-blush border-2 border-ink flex items-center justify-center text-ink hover:bg-red-500 hover:text-white transition-all duration-200">
                              <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                            <button type="button" title="<?= $isActive ? 'Deactivate department' : 'Activate department' ?>" aria-label="Toggle status"
                                    onclick='confirmToggleStatus(<?= $departmentId ?>, <?= json_encode($departmentName) ?>, <?= json_encode($status) ?>)'
                                    class="w-9 h-9 rounded-full bg-blush border-2 border-ink flex items-center justify-center text-ink hover:bg-grape hover:text-white transition-all duration-200">
                              <i data-lucide="<?= $isActive ? 'power-off' : 'power' ?>" class="w-4 h-4"></i>
                            </button>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <?php if (!empty($departments)): ?>
              <div class="px-6 py-4 bg-blush border-t-2 border-ink flex flex-col sm:flex-row items-center justify-between gap-4">
                <p class="text-sm text-slate-600">
                  Showing <span class="font-semibold text-ink">1</span> to
                  <span class="font-semibold text-ink"><?= count($departments) ?></span> of
                  <span class="font-semibold text-ink"><?= count($departments) ?></span> entries
                </p>
                <div class="flex items-center gap-2">
                  <button type="button" class="px-4 py-2 rounded-lg text-sm font-semibold text-slate-400 cursor-not-allowed" disabled>Previous</button>
                  <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-hot border-2 border-ink text-white text-sm font-bold shadow-[2px_2px_0_#FFC93C]">1</span>
                  <button type="button" class="px-4 py-2 rounded-lg text-sm font-semibold text-slate-400 cursor-not-allowed" disabled>Next</button>
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
                  <li><a href="settings.php" class="inline-flex items-center gap-2 rounded transition-colors hover:text-white hover:underline underline-offset-4 group"><i data-lucide="arrow-right" class="w-3 h-3 text-sun group-hover:translate-x-0.5 transition-transform"></i><span>Settings</span></a></li>
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

  <!-- DEPARTMENT MODAL -->
  <div id="departmentModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDepartmentModal()"></div>
    <div class="relative w-full max-w-lg bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-hot"></div>
      <div class="flex items-center justify-between px-6 py-4 border-b-2 border-ink bg-blush">
        <h3 id="departmentModalTitle" class="font-display text-lg font-bold text-ink">Add Department</h3>
        <button type="button" onclick="closeDepartmentModal()" aria-label="Close"
                class="w-8 h-8 rounded-lg border-2 border-ink bg-white hover:bg-blush flex items-center justify-center text-ink transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      <form id="departmentForm" method="POST" action="departments.php" class="p-6 space-y-5">
        <input type="hidden" name="action" id="formAction" value="create_department" />
        <input type="hidden" name="department_id" id="formDepartmentId" value="" />

        <div class="space-y-2">
          <label for="department_name" class="block text-sm font-semibold text-ink">Department Name <span class="text-hot">*</span></label>
          <input type="text" name="department_name" id="department_name" required placeholder="e.g. Computer Science"
                 class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
        </div>

        <div class="space-y-2">
          <label for="description" class="block text-sm font-semibold text-ink">Description</label>
          <textarea name="description" id="description" rows="3" placeholder="e.g. Department of Computer Science"
                    class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 resize-none focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all"></textarea>
        </div>

        <div class="space-y-2" id="statusFieldWrapper" style="display: none;">
          <label for="status" class="block text-sm font-semibold text-ink">Status <span class="text-hot">*</span></label>
          <select name="status" id="status"
                  class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all">
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
          </select>
        </div>

        <div class="flex justify-center pt-3 gap-3">
          <button type="button" onclick="closeDepartmentModal()"
                  class="px-6 py-3 rounded-xl font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">Cancel</button>
          <button type="submit" class="btn-hard inline-flex items-center gap-2 rounded-xl bg-hot px-8 py-3 text-sm font-bold text-white hover:bg-hotdark">
            <i data-lucide="save" class="w-4 h-4"></i> Save
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- DELETE CONFIRM MODAL -->
  <div id="deleteConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeleteModal()"></div>
    <div id="deleteConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-red-500"></div>
      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <span class="w-16 h-16 rounded-full bg-red-50 border-2 border-ink flex items-center justify-center mb-4">
          <i data-lucide="trash-2" class="w-8 h-8 text-red-500"></i>
        </span>
        <h3 class="font-display text-xl font-bold text-ink mb-2">Delete Department?</h3>
        <p class="text-sm text-slate-500 leading-relaxed">
          You are about to permanently delete
          <span id="deleteDepartmentNameDisplay" class="font-bold text-hot break-words">this department</span>.
        </p>
        <p class="text-xs text-red-500 font-semibold mt-3 flex items-center gap-1.5">
          <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i> This action cannot be undone.
        </p>
      </div>
      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeDeleteModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">Cancel</button>
        <button type="button" id="confirmDeleteBtn"
                class="btn-hard flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-red-500 px-5 py-3 text-sm font-bold text-white hover:bg-red-600">
          <i data-lucide="trash-2" class="w-4 h-4"></i> Delete
        </button>
      </div>
    </div>
  </div>

  <!-- TOGGLE STATUS MODAL -->
  <div id="toggleConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeToggleModal()"></div>
    <div id="toggleConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-grape"></div>
      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <span class="w-16 h-16 rounded-full bg-sun border-2 border-ink flex items-center justify-center mb-4">
          <i id="toggleIcon" data-lucide="power" class="w-8 h-8 text-ink"></i>
        </span>
        <h3 id="toggleModalTitle" class="font-display text-xl font-bold text-ink mb-2">Change Status?</h3>
        <p id="toggleModalDescription" class="text-sm text-slate-500 leading-relaxed">
          The status of <span class="font-bold text-hot break-words">this department</span> will be changed.
        </p>
      </div>
      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeToggleModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">Cancel</button>
        <button type="button" id="confirmToggleBtn"
                class="btn-hard flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-grape px-5 py-3 text-sm font-bold text-white hover:bg-ink">
          <i data-lucide="refresh-cw" class="w-4 h-4"></i>
          <span id="confirmToggleBtnLabel">Confirm</span>
        </button>
      </div>
    </div>
  </div>

  <form id="deleteForm" method="POST" action="departments.php" class="hidden">
    <input type="hidden" name="action" value="delete_department" />
    <input type="hidden" name="department_id" id="deleteDepartmentId" value="" />
  </form>

  <form id="toggleForm" method="POST" action="departments.php" class="hidden">
    <input type="hidden" name="action" value="toggle_status" />
    <input type="hidden" name="department_id" id="toggleDepartmentId" value="" />
  </form>

  <script>
    if (typeof lucide !== 'undefined') lucide.createIcons();

    (function () {
      ['flashSuccessBox', 'flashErrorBox'].forEach(function (id) {
        const box = document.getElementById(id);
        if (!box) return;
        setTimeout(function () {
          box.classList.remove('animate-flash-in');
          box.classList.add('animate-flash-out');
          setTimeout(function () { box.parentNode && box.parentNode.removeChild(box); }, 500);
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
        const isOpen = !menu.classList.contains('hidden');
        if (isOpen) { menu.classList.add('hidden'); chevron && chevron.classList.remove('rotate-180'); btn.setAttribute('aria-expanded', 'false'); }
        else { menu.classList.remove('hidden'); chevron && chevron.classList.add('rotate-180'); btn.setAttribute('aria-expanded', 'true'); }
      });
      document.addEventListener('click', function (e) {
        if (!container.contains(e.target)) { menu.classList.add('hidden'); chevron && chevron.classList.remove('rotate-180'); btn.setAttribute('aria-expanded', 'false'); }
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { menu.classList.add('hidden'); chevron && chevron.classList.remove('rotate-180'); btn.setAttribute('aria-expanded', 'false'); }
      });
    })();

    (function () {
      ['sidebarLogoutBtn', 'dropdownLogoutBtn'].forEach(function (id) {
        const btn = document.getElementById(id);
        if (!btn) return;
        btn.addEventListener('click', function (e) {
          if (!window.confirm('Are you sure you want to log out?')) { e.preventDefault(); e.stopPropagation(); return false; }
          btn.classList.add('opacity-50', 'pointer-events-none');
        });
      });
    })();

    // Department Add/Edit
    const departmentModal = document.getElementById('departmentModal');
    const departmentModalTitle = document.getElementById('departmentModalTitle');
    const departmentForm = document.getElementById('departmentForm');
    const formAction = document.getElementById('formAction');
    const formDepartmentId = document.getElementById('formDepartmentId');
    const nameInput = document.getElementById('department_name');
    const descriptionInput = document.getElementById('description');
    const statusInput = document.getElementById('status');
    const statusFieldWrapper = document.getElementById('statusFieldWrapper');

    function openDepartmentModal(mode, id, name, description, status) {
      departmentModal.classList.remove('hidden');
      if (mode === 'edit') {
        departmentModalTitle.textContent = 'Edit Department';
        formAction.value = 'edit_department';
        formDepartmentId.value = id || '';
        nameInput.value = name || '';
        descriptionInput.value = description || '';
        statusInput.value = status || 'Active';
        if (statusFieldWrapper) statusFieldWrapper.style.display = '';
        if (statusInput) statusInput.setAttribute('required', 'required');
      } else {
        departmentModalTitle.textContent = 'Add Department';
        formAction.value = 'create_department';
        formDepartmentId.value = '';
        departmentForm.reset();
        if (statusFieldWrapper) statusFieldWrapper.style.display = 'none';
        if (statusInput) statusInput.removeAttribute('required');
      }
      setTimeout(function () { nameInput && nameInput.focus(); }, 50);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeDepartmentModal() {
      departmentModal.classList.add('hidden');
      departmentForm.reset();
      formAction.value = 'create_department';
      formDepartmentId.value = '';
    }
    document.getElementById('departmentModal').addEventListener('click', function (e) { if (e.target.id === 'departmentModal') closeDepartmentModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !departmentModal.classList.contains('hidden')) closeDepartmentModal(); });

    // Delete
    const deleteConfirmModal = document.getElementById('deleteConfirmModal');
    const deleteConfirmPanel = document.getElementById('deleteConfirmPanel');
    const deleteDepartmentNameEl = document.getElementById('deleteDepartmentNameDisplay');
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    let pendingDeleteId = null;

    function confirmDeleteDepartment(departmentId, departmentName) {
      pendingDeleteId = departmentId;
      if (deleteDepartmentNameEl) deleteDepartmentNameEl.textContent = '"' + departmentName + '"';
      deleteConfirmModal.classList.remove('hidden');
      if (deleteConfirmPanel) {
        deleteConfirmPanel.classList.remove('animate-confirm-shake'); void deleteConfirmPanel.offsetWidth; deleteConfirmPanel.classList.add('animate-confirm-shake');
      }
      setTimeout(function () { if (confirmDeleteBtn) confirmDeleteBtn.focus(); }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeDeleteModal() { deleteConfirmModal.classList.add('hidden'); pendingDeleteId = null; }
    if (confirmDeleteBtn) {
      confirmDeleteBtn.addEventListener('click', function () {
        if (pendingDeleteId === null || pendingDeleteId === undefined) { closeDeleteModal(); return; }
        const delIdInput = document.getElementById('deleteDepartmentId');
        const delForm = document.getElementById('deleteForm');
        if (delIdInput && delForm) { delIdInput.value = String(pendingDeleteId); delForm.submit(); }
        else closeDeleteModal();
      });
    }
    document.getElementById('deleteConfirmModal').addEventListener('click', function (e) { if (e.target.id === 'deleteConfirmModal') closeDeleteModal(); });

    // Toggle
    const toggleConfirmModal = document.getElementById('toggleConfirmModal');
    const toggleConfirmPanel = document.getElementById('toggleConfirmPanel');
    const toggleModalTitle = document.getElementById('toggleModalTitle');
    const toggleModalDescription = document.getElementById('toggleModalDescription');
    const toggleIcon = document.getElementById('toggleIcon');
    const confirmToggleBtn = document.getElementById('confirmToggleBtn');
    const confirmToggleBtnLabel = document.getElementById('confirmToggleBtnLabel');
    let pendingToggleId = null;

    function confirmToggleStatus(departmentId, departmentName, currentStatus) {
      pendingToggleId = departmentId;
      const isCurrentlyActive = (String(currentStatus).toLowerCase() === 'active');
      if (isCurrentlyActive) {
        if (toggleModalTitle) toggleModalTitle.textContent = 'Deactivate Department?';
        if (toggleModalDescription) toggleModalDescription.innerHTML = 'The department <span class="font-bold text-hot break-words">"' + departmentName + '"</span> will be marked as <span class="font-bold text-hot">Inactive</span>.';
        if (confirmToggleBtnLabel) confirmToggleBtnLabel.textContent = 'Deactivate';
        if (toggleIcon) toggleIcon.setAttribute('data-lucide', 'power-off');
      } else {
        if (toggleModalTitle) toggleModalTitle.textContent = 'Activate Department?';
        if (toggleModalDescription) toggleModalDescription.innerHTML = 'The department <span class="font-bold text-hot break-words">"' + departmentName + '"</span> will be marked as <span class="font-bold text-hot">Active</span>.';
        if (confirmToggleBtnLabel) confirmToggleBtnLabel.textContent = 'Activate';
        if (toggleIcon) toggleIcon.setAttribute('data-lucide', 'power');
      }
      toggleConfirmModal.classList.remove('hidden');
      if (toggleConfirmPanel) {
        toggleConfirmPanel.classList.remove('animate-confirm-shake'); void toggleConfirmPanel.offsetWidth; toggleConfirmPanel.classList.add('animate-confirm-shake');
      }
      setTimeout(function () { if (confirmToggleBtn) confirmToggleBtn.focus(); }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeToggleModal() { toggleConfirmModal.classList.add('hidden'); pendingToggleId = null; }
    if (confirmToggleBtn) {
      confirmToggleBtn.addEventListener('click', function () {
        if (pendingToggleId === null || pendingToggleId === undefined) { closeToggleModal(); return; }
        const toggleIdInput = document.getElementById('toggleDepartmentId');
        const toggleForm = document.getElementById('toggleForm');
        if (toggleIdInput && toggleForm) { toggleIdInput.value = String(pendingToggleId); toggleForm.submit(); }
        else closeToggleModal();
      });
    }
    document.getElementById('toggleConfirmModal').addEventListener('click', function (e) { if (e.target.id === 'toggleConfirmModal') closeToggleModal(); });

    // Live search
    (function () {
      const searchInput = document.getElementById('searchInput');
      const tableBody = document.getElementById('departmentsTableBody');
      if (!searchInput || !tableBody) return;
      searchInput.addEventListener('input', function () {
        const term = this.value.toLowerCase().trim();
        tableBody.querySelectorAll('tr').forEach(function (row) {
          row.style.display = (term === '' || row.textContent.toLowerCase().indexOf(term) !== -1) ? '' : 'none';
        });
      });
    })();
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>