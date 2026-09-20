<?php
/**
 * admin/classes.php
 * ---------------------------------------------------------------------------
 * Admin — Class/Semester Management
 * Rajagiri College Grievance Redressal Portal
 *
 * Features:
 *   • View classes grouped by course
 *   • Create / Edit / Delete classes
 *   • Live student count per class
 *   • Click a class card → navigate to student list for that class
 *   • Flash messages auto-dismiss after 3 seconds
 *   • 3-column grid layout for class cards
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
        error_log('[Classes Admin Profile] ' . $ex->getMessage());
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

    if ($action === 'create_class') {
        $courseId  = (int) ($_POST['course_id'] ?? 0);
        $className = trim((string) ($_POST['class_name'] ?? ''));

        if ($courseId <= 0) {
            $flashError = 'Please select a valid course.';
        } elseif ($className === '') {
            $flashError = 'Class name is required.';
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO classes (course_id, class_name) VALUES (?, ?)");
                $stmt->bind_param('is', $courseId, $className);
                if ($stmt->execute()) {
                    $flashSuccess = 'Class created successfully.';
                } else {
                    $flashError = 'Failed to create class.';
                }
                $stmt->close();
            } catch (Throwable $ex) {
                error_log('[Create Class] ' . $ex->getMessage());
                $flashError = 'A system error occurred while creating the class.';
            }
        }
    }

    if ($action === 'edit_class') {
        $classId   = (int) ($_POST['class_id'] ?? 0);
        $courseId  = (int) ($_POST['course_id'] ?? 0);
        $className = trim((string) ($_POST['class_name'] ?? ''));

        if ($classId <= 0 || $courseId <= 0) {
            $flashError = 'Invalid class or course selection.';
        } elseif ($className === '') {
            $flashError = 'Class name is required.';
        } else {
            try {
                $stmt = $conn->prepare("UPDATE classes SET class_name = ?, course_id = ? WHERE id = ?");
                $stmt->bind_param('sii', $className, $courseId, $classId);
                if ($stmt->execute()) {
                    $flashSuccess = 'Class updated successfully.';
                } else {
                    $flashError = 'Failed to update class.';
                }
                $stmt->close();
            } catch (Throwable $ex) {
                error_log('[Edit Class] ' . $ex->getMessage());
                $flashError = 'A system error occurred while updating the class.';
            }
        }
    }

    if ($action === 'delete_class') {
        $classId = (int) ($_POST['class_id'] ?? 0);
        if ($classId > 0) {
            try {
                $chk = $conn->prepare("SELECT COUNT(*) AS c FROM students WHERE class_id = ?");
                $chk->bind_param('i', $classId);
                $chk->execute();
                $cnt = (int) ($chk->get_result()->fetch_assoc()['c'] ?? 0);
                $chk->close();

                if ($cnt > 0) {
                    $flashError = "Cannot delete this class — {$cnt} student(s) are still assigned to it.";
                } else {
                    $stmt = $conn->prepare("DELETE FROM classes WHERE id = ?");
                    $stmt->bind_param('i', $classId);
                    if ($stmt->execute()) {
                        $flashSuccess = 'Class deleted successfully.';
                    } else {
                        $flashError = 'Failed to delete class.';
                    }
                    $stmt->close();
                }
            } catch (Throwable $ex) {
                error_log('[Delete Class] ' . $ex->getMessage());
                $flashError = 'A system error occurred while deleting the class.';
            }
        }
    }

    if ($flashSuccess !== '' || $flashError !== '') {
        $_SESSION['flash_success'] = $flashSuccess;
        $_SESSION['flash_error']   = $flashError;
        header('Location: classes.php');
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

$courses = [];
$classesByCourse = [];

if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, course_name FROM courses WHERE status = 'active' OR status IS NULL OR status = '' ORDER BY course_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $courses[] = $row;
            }
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Courses] ' . $ex->getMessage());
    }

    try {
        $sql = "SELECT  cl.id,
                        cl.class_name,
                        cl.course_id,
                        co.course_name,
                        COUNT(st.id) AS student_count
                FROM classes cl
                JOIN courses co ON cl.course_id = co.id
                LEFT JOIN students st ON cl.id = st.class_id
                GROUP BY cl.id
                ORDER BY co.course_name ASC, cl.id ASC";

        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $courseName = $row['course_name'] ?? 'Unassigned';
                if (!isset($classesByCourse[$courseName])) {
                    $classesByCourse[$courseName] = [];
                }
                $classesByCourse[$courseName][] = $row;
            }
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Classes] ' . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Class / semester management — Rajagiri College Grievance Redressal Portal." />
  <meta name="theme-color" content="#DB0878" />
  <title>Class / Semester Management — Admin | Rajagiri College Grievance Portal</title>
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

    .class-card {
      border: 2px solid #1F0A24; box-shadow: 6px 6px 0 #FFC93C;
      transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
      display: flex; flex-direction: column; justify-content: space-between;
    }
    .class-card:hover { transform: translate(-3px, -3px); box-shadow: 12px 12px 0 #FFC93C; }

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

    <!-- SIDEBAR -->
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

    <!-- MAIN -->
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

        <!-- Breadcrumb + Action -->
        <div class="max-w-6xl mx-auto mb-6 rise">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
              <h1 class="font-display text-2xl md:text-3xl font-extrabold tracking-tight text-ink mb-2">Class / Semester</h1>
              <nav class="flex flex-wrap items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
                <a href="dashboard.php" class="flex items-center rounded transition-colors hover:text-hot"><i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i> Dashboard</a>
                <span class="text-slate-300">/</span>
                <a href="settings.php" class="rounded transition-colors hover:text-hot">Settings</a>
                <span class="text-slate-300">/</span>
                <span class="text-hot font-semibold">Class / Semester</span>
              </nav>
            </div>

            <button type="button" onclick="openClassModal('create')" title="Add Class" aria-label="Add class"
                    class="btn-hard inline-flex items-center justify-center gap-2 rounded-xl bg-hot px-5 py-3 text-sm font-bold text-white hover:bg-hotdark">
              <i data-lucide="plus" class="w-4 h-4"></i>
              <span>Add Class</span>
            </button>
          </div>
        </div>

        <!-- Flash -->
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

        <!-- Notice -->
        <div class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-ink bg-sun px-4 py-3 flex items-start gap-3 rise rise-2">
          <i data-lucide="mouse-pointer-click" class="w-5 h-5 text-ink flex-shrink-0 mt-0.5"></i>
          <p class="text-sm text-ink font-semibold">
            Click a class card to manage its students. Use the edit and delete controls on each card to maintain the structure.
          </p>
        </div>

        <?php if (empty($classesByCourse)): ?>
          <div class="max-w-6xl mx-auto rise rise-3">
            <div class="bg-white rounded-2xl border-2 border-ink shadow-[6px_6px_0_#FFC93C] py-16 px-6 text-center">
              <span class="w-16 h-16 rounded-full bg-blush border-2 border-ink flex items-center justify-center mb-4 mx-auto">
                <i data-lucide="graduation-cap" class="w-8 h-8 text-hot"></i>
              </span>
              <p class="text-lg font-bold text-ink">No classes yet</p>
              <p class="text-sm text-slate-500 mt-1 mb-4">Create your first class or semester to get started.</p>
              <button type="button" onclick="openClassModal('create')"
                      class="btn-hard inline-flex items-center justify-center gap-2 rounded-xl bg-hot px-6 py-3 text-sm font-bold text-white hover:bg-hotdark">
                <i data-lucide="plus" class="w-4 h-4"></i> Add First Class
              </button>
            </div>
          </div>
        <?php else: ?>
          <div class="max-w-6xl mx-auto rise rise-3">
            <?php foreach ($classesByCourse as $courseName => $classes): ?>
              <div class="mb-10">
                <h2 class="font-display flex items-center gap-3 text-lg md:text-xl font-bold text-ink mb-4">
                  <span class="w-3 h-3 rounded-full bg-hot shadow-[0_0_0_6px_rgba(219,8,120,.15)]"></span>
                  <?= e($courseName) ?>
                </h2>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-5">
                  <?php foreach ($classes as $class): ?>
                    <?php
                      $studentCount = (int) ($class['student_count'] ?? 0);
                      $classId      = (int) $class['id'];
                      $courseId     = (int) ($class['course_id'] ?? 0);
                      $classNameVal = (string) ($class['class_name'] ?? '');
                    ?>
                    <a class="class-card group rounded-2xl bg-white p-5 relative"
                       href="<?= e('students.php?class_id=' . $classId) ?>"
                       title="Manage students of <?= e($classNameVal) ?>">

                      <div class="flex items-start justify-between gap-3">
                        <span class="inline-flex items-center gap-2 bg-blush border-2 border-ink rounded-full px-3 py-1 text-xs font-bold text-ink">
                          <i data-lucide="users" class="w-3.5 h-3.5 text-hot"></i>
                          <?= $studentCount ?> students
                        </span>

                        <div class="flex items-center gap-1.5">
                          <button type="button" title="Edit class" aria-label="Edit class"
                                  onclick='event.preventDefault();event.stopPropagation();openClassModal("edit",<?= $classId ?>,<?= $courseId ?>,<?= json_encode($classNameVal) ?>)'
                                  class="w-8 h-8 rounded-full bg-white border-2 border-ink flex items-center justify-center text-ink hover:bg-hot hover:text-white transition-colors">
                            <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                          </button>
                          <button type="button" title="Delete class" aria-label="Delete class"
                                  onclick='event.preventDefault();event.stopPropagation();confirmDeleteClass(<?= $classId ?>,<?= json_encode($classNameVal) ?>)'
                                  class="w-8 h-8 rounded-full bg-white border-2 border-ink flex items-center justify-center text-ink hover:bg-red-500 hover:text-white transition-colors">
                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                          </button>
                        </div>
                      </div>

                      <div class="mt-5">
                        <p class="font-display text-lg font-bold text-ink leading-tight"><?= e($classNameVal) ?></p>
                        <p class="text-[11px] font-bold text-hot uppercase tracking-wider mt-1"><?= e($class['course_name']) ?></p>
                      </div>

                      <div class="mt-4 flex items-center gap-1.5 text-xs font-semibold text-slate-500 group-hover:text-hot transition-colors">
                        <i data-lucide="arrow-up-right" class="w-3.5 h-3.5"></i>
                        <span>Open student list</span>
                      </div>
                    </a>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </main>

      <!-- FOOTER -->
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

  <!-- CLASS MODAL -->
  <div id="classModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeClassModal()"></div>
    <div class="relative w-full max-w-lg bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-hot"></div>
      <div class="flex items-center justify-between px-6 py-4 border-b-2 border-ink bg-blush">
        <h3 id="classModalTitle" class="font-display text-lg font-bold text-ink">Create Class</h3>
        <button type="button" onclick="closeClassModal()" aria-label="Close"
                class="w-8 h-8 rounded-lg border-2 border-ink bg-white hover:bg-blush flex items-center justify-center text-ink transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>
      <form id="classForm" method="POST" action="classes.php" class="p-6 space-y-5">
        <input type="hidden" name="action" id="formAction" value="create_class">
        <input type="hidden" name="class_id" id="formClassId" value="">
        <div class="space-y-2">
          <label for="course_id" class="block text-sm font-semibold text-ink">Course <span class="text-hot">*</span></label>
          <select class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all"
                  name="course_id" id="course_id" required>
            <option value="">-- Select Course --</option>
            <?php foreach ($courses as $course): ?>
              <option value="<?= (int) $course['id'] ?>"><?= e($course['course_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="space-y-2">
          <label for="class_name" class="block text-sm font-semibold text-ink">Class Name <span class="text-hot">*</span></label>
          <input class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all"
                 type="text" name="class_name" id="class_name" required placeholder="e.g. SEMESTER I">
        </div>
        <div class="flex justify-center pt-3 gap-3">
          <button type="button" onclick="closeClassModal()"
                  class="px-6 py-3 rounded-xl font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">Cancel</button>
          <button type="submit" class="btn-hard inline-flex items-center gap-2 rounded-xl bg-hot px-8 py-3 text-sm font-bold text-white hover:bg-hotdark">
            <i data-lucide="save" class="w-4 h-4"></i> Save Class
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- DELETE CONFIRM -->
  <div id="deleteConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeleteModal()"></div>
    <div id="deleteConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-red-500"></div>
      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <span class="w-16 h-16 rounded-full bg-red-50 border-2 border-ink flex items-center justify-center mb-4">
          <i data-lucide="trash-2" class="w-8 h-8 text-red-500"></i>
        </span>
        <h3 class="font-display text-xl font-bold text-ink mb-2">Delete Class?</h3>
        <p class="text-sm text-slate-500 leading-relaxed">
          You are about to permanently delete <strong id="deleteClassNameDisplay" class="font-bold text-hot break-words">this class</strong>. A class with assigned students cannot be deleted.
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
          <i data-lucide="trash-2" class="w-4 h-4"></i> Delete Class
        </button>
      </div>
    </div>
  </div>

  <form id="deleteForm" method="POST" action="classes.php" class="hidden">
    <input type="hidden" name="action" value="delete_class">
    <input type="hidden" name="class_id" id="deleteClassId">
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

    const classModal = document.getElementById('classModal');
    const classForm = document.getElementById('classForm');
    const formAction = document.getElementById('formAction');
    const formClassId = document.getElementById('formClassId');
    const courseSelect = document.getElementById('course_id');
    const classNameInput = document.getElementById('class_name');

    function openClassModal(mode, id, course, name) {
      classModal.classList.remove('hidden');
      if (mode === 'edit') {
        document.getElementById('classModalTitle').textContent = 'Edit Class';
        formAction.value = 'edit_class';
        formClassId.value = id || '';
        courseSelect.value = String(course || '');
        classNameInput.value = name || '';
      } else {
        document.getElementById('classModalTitle').textContent = 'Create Class';
        formAction.value = 'create_class';
        formClassId.value = '';
        classForm.reset();
      }
      setTimeout(function () { courseSelect && courseSelect.focus(); }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeClassModal() {
      classModal.classList.add('hidden');
      classForm.reset();
      formAction.value = 'create_class';
      formClassId.value = '';
    }

    document.getElementById('classModal').addEventListener('click', function (e) { if (e.target.id === 'classModal') closeClassModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !classModal.classList.contains('hidden')) closeClassModal(); });

    let pendingDelete = null;
    function confirmDeleteClass(id, name) {
      pendingDelete = id;
      document.getElementById('deleteClassNameDisplay').textContent = '"' + name + '"';
      const modal = document.getElementById('deleteConfirmModal');
      modal.classList.remove('hidden');
      const panel = document.getElementById('deleteConfirmPanel');
      panel.classList.remove('animate-confirm-shake'); void panel.offsetWidth; panel.classList.add('animate-confirm-shake');
      setTimeout(function () { document.getElementById('confirmDeleteBtn').focus(); }, 60);
    }
    function closeDeleteModal() {
      document.getElementById('deleteConfirmModal').classList.add('hidden');
      pendingDelete = null;
    }
    document.getElementById('confirmDeleteBtn').addEventListener('click', function () {
      if (pendingDelete) {
        document.getElementById('deleteClassId').value = pendingDelete;
        document.getElementById('deleteForm').submit();
      }
    });
    document.getElementById('deleteConfirmModal').addEventListener('click', function (e) { if (e.target.id === 'deleteConfirmModal') closeDeleteModal(); });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>