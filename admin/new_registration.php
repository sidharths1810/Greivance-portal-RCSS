<?php
/**
 * admin/new_registration.php
 * ---------------------------------------------------------------------------
 * Admin — New Registration Approvals
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

function isValidMobile(string $mobile): bool
{
    return (bool) preg_match('/^[0-9]{10}$/', $mobile);
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
        error_log('[New Registrations Admin Profile] ' . $ex->getMessage());
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

    if ($action === 'approve_single') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        if ($targetUserId > 0) {
            try {
                $stmt = $conn->prepare("UPDATE users SET status = 'Approved' WHERE id = ? AND status = 'Pending'");
                $stmt->bind_param('i', $targetUserId);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $flashSuccess = 'Registration approved successfully.';
                } else {
                    $flashError = 'Unable to approve this registration. It may already be approved or removed.';
                }
                $stmt->close();
            } catch (Throwable $ex) {
                error_log('[Approve Single] ' . $ex->getMessage());
                $flashError = 'A system error occurred while approving the registration.';
            }
        }
    }

    if ($action === 'approve_bulk') {
        $ids = $_POST['user_ids'] ?? [];
        if (is_array($ids) && !empty($ids)) {
            $cleanIds = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));

            if (!empty($cleanIds)) {
                try {
                    $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
                    $types        = str_repeat('i', count($cleanIds));

                    $stmt = $conn->prepare("UPDATE users SET status = 'Approved' WHERE id IN ($placeholders) AND status = 'Pending'");
                    $stmt->bind_param($types, ...$cleanIds);
                    $stmt->execute();
                    $affected = $stmt->affected_rows;
                    $stmt->close();

                    if ($affected > 0) {
                        $flashSuccess = $affected . ' registration(s) approved successfully.';
                    } else {
                        $flashError = 'No pending registrations were approved.';
                    }
                } catch (Throwable $ex) {
                    error_log('[Approve Bulk] ' . $ex->getMessage());
                    $flashError = 'A system error occurred while approving the registrations.';
                }
            } else {
                $flashError = 'No valid registrations selected.';
            }
        } else {
            $flashError = 'Please select at least one registration to approve.';
        }
    }

    if ($action === 'delete_registration') {
        $targetUserId = (int) ($_POST['user_id'] ?? 0);
        if ($targetUserId > 0) {
            try {
                $conn->begin_transaction();
                $conn->query("DELETE FROM students     WHERE user_id = " . $targetUserId);
                $conn->query("DELETE FROM parents      WHERE user_id = " . $targetUserId);
                $conn->query("DELETE FROM cell_members WHERE user_id = " . $targetUserId);
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->bind_param('i', $targetUserId);
                $stmt->execute();
                $stmt->close();
                $conn->commit();
                $flashSuccess = 'Registration deleted successfully.';
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Delete Registration] ' . $ex->getMessage());
                $flashError = 'A system error occurred while deleting the registration.';
            }
        }
    }

    if ($action === 'edit_registration') {
        $targetUserId = (int)    ($_POST['user_id'] ?? 0);
        $name         = trim((string) ($_POST['name']    ?? ''));
        $email        = trim((string) ($_POST['email']   ?? ''));
        $address      = trim((string) ($_POST['address'] ?? ''));
        $role         = trim((string) ($_POST['role']    ?? ''));

        if ($targetUserId <= 0) {
            $flashError = 'Invalid registration.';
        } elseif ($name === '' || $email === '') {
            $flashError = 'Name and Email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flashError = 'Please enter a valid email address.';
        }

        if ($flashError === '') {
            try {
                $conn->begin_transaction();
                $updated = false;

                if ($role === 'STUDENT') {
                    $stmt = $conn->prepare("UPDATE students SET name = ?, email = ?, address = ? WHERE user_id = ?");
                    $stmt->bind_param('sssi', $name, $email, $address, $targetUserId);
                    $stmt->execute();
                    $updated = $stmt->affected_rows >= 0;
                    $stmt->close();
                } elseif ($role === 'PARENT') {
                    $stmt = $conn->prepare("UPDATE parents SET name = ?, email = ? WHERE user_id = ?");
                    $stmt->bind_param('ssi', $name, $email, $targetUserId);
                    $stmt->execute();
                    $updated = $stmt->affected_rows >= 0;
                    $stmt->close();
                } else {
                    $stmt = $conn->prepare("UPDATE cell_members SET name = ?, email = ? WHERE user_id = ?");
                    $stmt->bind_param('ssi', $name, $email, $targetUserId);
                    $stmt->execute();
                    $updated = $stmt->affected_rows >= 0;
                    $stmt->close();
                }

                $conn->commit();
                if ($updated) $flashSuccess = 'Registration details updated successfully.';
                else $flashError = 'No changes were saved.';
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Edit Registration] ' . $ex->getMessage());
                $flashError = 'A system error occurred while updating the registration.';
            }
        }
    }

    if ($flashSuccess !== '' || $flashError !== '') {
        $_SESSION['flash_success'] = $flashSuccess;
        $_SESSION['flash_error']   = $flashError;
        header('Location: new_registration.php');
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

$registrations = [];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  u.id AS user_id,
                        u.username,
                        u.role,
                        u.status,
                        COALESCE(s.name,   p.name,   cm.name)   AS name,
                        COALESCE(s.email,  p.email,  cm.email)  AS email,
                        COALESCE(s.address, 'N/A')              AS address
                FROM users u
                LEFT JOIN students     s  ON u.id = s.user_id
                LEFT JOIN parents      p  ON u.id = p.user_id
                LEFT JOIN cell_members cm ON u.id = cm.user_id
                WHERE u.status = 'Pending'
                ORDER BY u.id DESC";

        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) $registrations[] = $row;
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Pending Registrations] ' . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="New registration approvals — Rajagiri College Grievance Redressal Portal." />
  <meta name="theme-color" content="#DB0878" />
  <title>New Registration — Admin | Rajagiri College Grievance Portal</title>
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
        <a href="members.php" class="nav-icon-link group relative w-12 h-12 rounded-xl bg-hot flex items-center justify-center text-white ring-2 ring-sun/60" title="Back to Members" aria-label="Back to Members">
          <i data-lucide="users" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-ink text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50 border border-white/10">Back to Members</span>
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
              <h1 class="font-display text-2xl md:text-3xl font-extrabold tracking-tight text-ink mb-2">New Registration</h1>
              <nav class="flex flex-wrap items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
                <a href="dashboard.php" class="flex items-center rounded transition-colors hover:text-hot"><i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i> Dashboard</a>
                <span class="text-slate-300">/</span>
                <a href="members.php" class="rounded transition-colors hover:text-hot">Members</a>
                <span class="text-slate-300">/</span>
                <span class="text-hot font-semibold">New Registration</span>
              </nav>
            </div>

            <button type="button" onclick="approveChecked()" title="Approve Selected Registrations" aria-label="Approve selected"
                    class="btn-hard inline-flex items-center justify-center gap-2 rounded-xl bg-leaf px-5 py-3 text-sm font-bold text-white hover:bg-[#005830]">
              <i data-lucide="check-check" class="w-4 h-4"></i>
              <span class="hidden sm:inline">Approve Selected</span>
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

            <form id="bulkApproveForm" method="POST" action="new_registration.php">
              <input type="hidden" name="action" value="approve_bulk" />

              <div class="overflow-x-auto">
                <table class="w-full" id="registrationsTable">
                  <thead>
                    <tr class="bg-ink text-white">
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Sl.No.</th>
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Name</th>
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Address</th>
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Email Id</th>
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Role</th>
                      <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                          <input type="checkbox" id="selectAllCheckbox"
                                 class="w-4 h-4 rounded border-slate-300 text-hot focus:ring-hot/30 cursor-pointer" />
                          <span>Select All</span>
                        </label>
                      </th>
                      <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">Actions</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100" id="registrationsTableBody">
                    <?php if (empty($registrations)): ?>
                      <tr>
                        <td colspan="7" class="px-6 py-16 text-center text-slate-500">
                          <div class="flex flex-col items-center justify-center">
                            <span class="w-16 h-16 rounded-full bg-blush border-2 border-ink flex items-center justify-center mb-4">
                              <i data-lucide="user-check" class="w-8 h-8 text-hot"></i>
                            </span>
                            <p class="text-lg font-bold text-ink">No pending registrations</p>
                            <p class="text-sm text-slate-500 mt-1 mb-4">New sign-ups will appear here for approval.</p>
                          </div>
                        </td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($registrations as $index => $reg): ?>
                        <?php
                          $regUserId = (int) $reg['user_id'];
                          $regName = (string) ($reg['name'] ?? 'N/A');
                          $regEmail = (string) ($reg['email'] ?? 'N/A');
                          $regAddr = (string) ($reg['address'] ?? 'N/A');
                          $regRole = (string) ($reg['role'] ?? '');
                        ?>
                        <tr class="registration-row hover:bg-blush/60 transition-colors group">
                          <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900"><?= $index + 1 ?></td>
                          <td class="px-6 py-4 text-sm font-bold text-ink"><?= e($regName) ?></td>
                          <td class="px-6 py-4 text-sm text-slate-600 max-w-[200px]"><?= e($regAddr) ?></td>
                          <td class="px-6 py-4 text-sm text-slate-600 break-all"><?= e($regEmail) ?></td>
                          <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-hot"><?= e($regRole) ?></td>
                          <td class="px-6 py-4 whitespace-nowrap text-center">
                            <input type="checkbox" name="user_ids[]" value="<?= $regUserId ?>"
                                   class="reg-checkbox w-4 h-4 rounded border-slate-300 text-hot focus:ring-hot/30 cursor-pointer" />
                          </td>
                          <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center justify-center gap-1.5">
                              <button type="button" title="Approve this registration" aria-label="Approve"
                                      onclick='confirmApprove(<?= $regUserId ?>, <?= json_encode($regName) ?>)'
                                      class="w-9 h-9 rounded-full bg-blush border-2 border-ink flex items-center justify-center text-ink hover:bg-leaf hover:text-white transition-all duration-200">
                                <i data-lucide="check" class="w-4 h-4"></i>
                              </button>
                              <button type="button" title="Edit registration" aria-label="Edit"
                                      onclick='openEditModal(<?= $regUserId ?>, <?= json_encode($regName) ?>, <?= json_encode($regEmail) ?>, <?= json_encode($regAddr) ?>, <?= json_encode($regRole) ?>)'
                                      class="w-9 h-9 rounded-full bg-blush border-2 border-ink flex items-center justify-center text-ink hover:bg-hot hover:text-white transition-all duration-200">
                                <i data-lucide="pencil" class="w-4 h-4"></i>
                              </button>
                              <button type="button" title="Delete registration" aria-label="Delete"
                                      onclick='confirmDelete(<?= $regUserId ?>, <?= json_encode($regName) ?>)'
                                      class="w-9 h-9 rounded-full bg-blush border-2 border-ink flex items-center justify-center text-ink hover:bg-red-500 hover:text-white transition-all duration-200">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                              </button>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </form>

            <?php if (!empty($registrations)): ?>
              <div class="px-6 py-4 bg-blush border-t-2 border-ink flex flex-col sm:flex-row items-center justify-between gap-4">
                <p class="text-sm text-slate-600">
                  Showing <span class="font-semibold text-ink">1</span> to
                  <span class="font-semibold text-ink"><?= count($registrations) ?></span> of
                  <span class="font-semibold text-ink"><?= count($registrations) ?></span> entries
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
                  <li><a href="members.php" class="inline-flex items-center gap-2 rounded transition-colors hover:text-white hover:underline underline-offset-4 group"><i data-lucide="arrow-right" class="w-3 h-3 text-sun group-hover:translate-x-0.5 transition-transform"></i><span>Members</span></a></li>
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

  <!-- EDIT MODAL -->
  <div id="editModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeEditModal()"></div>
    <div class="relative w-full max-w-lg bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-hot"></div>
      <div class="flex items-center justify-between px-6 py-4 border-b-2 border-ink bg-blush">
        <h3 class="font-display text-lg font-bold text-ink">Edit Registration</h3>
        <button type="button" onclick="closeEditModal()" aria-label="Close"
                class="w-8 h-8 rounded-lg border-2 border-ink bg-white hover:bg-blush flex items-center justify-center text-ink transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="editForm" method="POST" action="new_registration.php" class="p-6 space-y-4">
        <input type="hidden" name="action" value="edit_registration" />
        <input type="hidden" name="user_id" id="editUserId" value="" />
        <input type="hidden" name="role"    id="editRole"   value="" />

        <div class="space-y-2">
          <label for="editName" class="block text-sm font-semibold text-ink">Full Name <span class="text-hot">*</span></label>
          <input type="text" name="name" id="editName" required
                 class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
        </div>

        <div class="space-y-2">
          <label for="editEmail" class="block text-sm font-semibold text-ink">Email <span class="text-hot">*</span></label>
          <input type="email" name="email" id="editEmail" required
                 class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
        </div>

        <div class="space-y-2" id="editAddressWrapper">
          <label for="editAddress" class="block text-sm font-semibold text-ink">Address</label>
          <textarea name="address" id="editAddress" rows="2" placeholder="e.g. Kochi, Kerala"
                    class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 resize-none focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all"></textarea>
        </div>

        <div class="flex justify-center pt-3 gap-3">
          <button type="button" onclick="closeEditModal()"
                  class="px-6 py-3 rounded-xl font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">Cancel</button>
          <button type="submit" class="btn-hard inline-flex items-center gap-2 rounded-xl bg-hot px-8 py-3 text-sm font-bold text-white hover:bg-hotdark">
            <i data-lucide="save" class="w-4 h-4"></i> Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- APPROVE CONFIRM -->
  <div id="approveConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeApproveModal()"></div>
    <div id="approveConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-leaf"></div>
      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <span class="w-16 h-16 rounded-full bg-[#DCEFE4] border-2 border-ink flex items-center justify-center mb-4">
          <i data-lucide="user-check" class="w-8 h-8 text-leaf"></i>
        </span>
        <h3 class="font-display text-xl font-bold text-ink mb-2">Approve Registration?</h3>
        <p class="text-sm text-slate-500 leading-relaxed">
          <span id="approveNameDisplay" class="font-bold text-hot break-words">This registration</span>
          will be marked as <span class="font-bold text-leaf">Approved</span> and the user will be able to log in.
        </p>
      </div>
      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeApproveModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">Cancel</button>
        <button type="button" id="confirmApproveBtn"
                class="btn-hard flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-leaf px-5 py-3 text-sm font-bold text-white hover:bg-[#005830]">
          <i data-lucide="check-circle" class="w-4 h-4"></i> Approve
        </button>
      </div>
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
        <h3 class="font-display text-xl font-bold text-ink mb-2">Delete Registration?</h3>
        <p class="text-sm text-slate-500 leading-relaxed">
          You are about to permanently delete
          <span id="deleteNameDisplay" class="font-bold text-hot break-words">this registration</span>.
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

  <form id="approveForm" method="POST" action="new_registration.php" class="hidden">
    <input type="hidden" name="action" value="approve_single" />
    <input type="hidden" name="user_id" id="approveUserId" value="" />
  </form>

  <form id="deleteForm" method="POST" action="new_registration.php" class="hidden">
    <input type="hidden" name="action" value="delete_registration" />
    <input type="hidden" name="user_id" id="deleteUserId" value="" />
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
    })();

    (function () {
      ['sidebarLogoutBtn', 'dropdownLogoutBtn'].forEach(function (id) {
        const btn = document.getElementById(id);
        if (!btn) return;
        btn.addEventListener('click', function (e) {
          if (!window.confirm('Are you sure you want to log out?')) { e.preventDefault(); e.stopPropagation(); return false; }
        });
      });
    })();

    (function () {
      const selectAll = document.getElementById('selectAllCheckbox');
      const checkboxes = document.querySelectorAll('.reg-checkbox');
      if (!selectAll) return;
      selectAll.addEventListener('change', function () {
        checkboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
      });
      checkboxes.forEach(function (cb) {
        cb.addEventListener('change', function () {
          const allChecked = Array.from(checkboxes).every(c => c.checked);
          const anyChecked = Array.from(checkboxes).some(c => c.checked);
          selectAll.checked = allChecked;
          selectAll.indeterminate = anyChecked && !allChecked;
        });
      });
    })();

    function approveChecked() {
      const checked = document.querySelectorAll('.reg-checkbox:checked');
      if (checked.length === 0) { alert('Please select at least one registration to approve.'); return; }
      const form = document.getElementById('bulkApproveForm');
      if (form) form.submit();
    }

    const editModal = document.getElementById('editModal');
    const editAddressWrapper = document.getElementById('editAddressWrapper');
    function openEditModal(userId, name, email, address, role) {
      editModal.classList.remove('hidden');
      document.getElementById('editUserId').value = userId || '';
      document.getElementById('editRole').value = role || '';
      document.getElementById('editName').value = name || '';
      document.getElementById('editEmail').value = email || '';
      document.getElementById('editAddress').value = (address && address !== 'N/A') ? address : '';
      if (editAddressWrapper) editAddressWrapper.style.display = (role === 'STUDENT') ? '' : 'none';
      setTimeout(() => document.getElementById('editName').focus(), 50);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeEditModal() { editModal.classList.add('hidden'); document.getElementById('editForm').reset(); }
    document.getElementById('editModal').addEventListener('click', function (e) { if (e.target.id === 'editModal') closeEditModal(); });

    const approveConfirmModal = document.getElementById('approveConfirmModal');
    const approveConfirmPanel = document.getElementById('approveConfirmPanel');
    const approveNameDisplay = document.getElementById('approveNameDisplay');
    const confirmApproveBtn = document.getElementById('confirmApproveBtn');
    let pendingApproveId = null;
    function confirmApprove(userId, name) {
      pendingApproveId = userId;
      if (approveNameDisplay) approveNameDisplay.textContent = '"' + name + '"';
      approveConfirmModal.classList.remove('hidden');
      if (approveConfirmPanel) { approveConfirmPanel.classList.remove('animate-confirm-shake'); void approveConfirmPanel.offsetWidth; approveConfirmPanel.classList.add('animate-confirm-shake'); }
      setTimeout(() => { if (confirmApproveBtn) confirmApproveBtn.focus(); }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeApproveModal() { approveConfirmModal.classList.add('hidden'); pendingApproveId = null; }
    if (confirmApproveBtn) confirmApproveBtn.addEventListener('click', function () {
      if (pendingApproveId === null) return closeApproveModal();
      const input = document.getElementById('approveUserId');
      const form = document.getElementById('approveForm');
      if (input && form) { input.value = String(pendingApproveId); form.submit(); }
    });
    document.getElementById('approveConfirmModal').addEventListener('click', function (e) { if (e.target.id === 'approveConfirmModal') closeApproveModal(); });

    const deleteConfirmModal = document.getElementById('deleteConfirmModal');
    const deleteConfirmPanel = document.getElementById('deleteConfirmPanel');
    const deleteNameDisplay = document.getElementById('deleteNameDisplay');
    const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    let pendingDeleteId = null;
    function confirmDelete(userId, name) {
      pendingDeleteId = userId;
      if (deleteNameDisplay) deleteNameDisplay.textContent = '"' + name + '"';
      deleteConfirmModal.classList.remove('hidden');
      if (deleteConfirmPanel) { deleteConfirmPanel.classList.remove('animate-confirm-shake'); void deleteConfirmPanel.offsetWidth; deleteConfirmPanel.classList.add('animate-confirm-shake'); }
      setTimeout(() => { if (confirmDeleteBtn) confirmDeleteBtn.focus(); }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeDeleteModal() { deleteConfirmModal.classList.add('hidden'); pendingDeleteId = null; }
    if (confirmDeleteBtn) confirmDeleteBtn.addEventListener('click', function () {
      if (pendingDeleteId === null) return closeDeleteModal();
      const input = document.getElementById('deleteUserId');
      const form = document.getElementById('deleteForm');
      if (input && form) { input.value = String(pendingDeleteId); form.submit(); }
    });
    document.getElementById('deleteConfirmModal').addEventListener('click', function (e) { if (e.target.id === 'deleteConfirmModal') closeDeleteModal(); });

    (function () {
      const searchInput = document.getElementById('searchInput');
      const tableBody = document.getElementById('registrationsTableBody');
      if (!searchInput || !tableBody) return;
      searchInput.addEventListener('input', function () {
        const term = this.value.toLowerCase().trim();
        tableBody.querySelectorAll('tr').forEach(function (row) {
          row.style.display = (term === '' || row.textContent.toLowerCase().indexOf(term) !== -1) ? '' : 'none';
        });
      });
    })();

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      if (editModal && !editModal.classList.contains('hidden')) closeEditModal();
      if (approveConfirmModal && !approveConfirmModal.classList.contains('hidden')) closeApproveModal();
      if (deleteConfirmModal && !deleteConfirmModal.classList.contains('hidden')) closeDeleteModal();
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>