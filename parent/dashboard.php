<?php
/**
 * parent/dashboard.php
 * ---------------------------------------------------------------------------
 * Parent — Grievance Details Dashboard
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

function statusBadge(string $status): string
{
    $status = trim($status);

    $map = [
        'Pending'     => 'bg-[#FFF5D8] text-[#9A6500] border-[#F1DDA1]',
        'In Progress' => 'bg-sky-50 text-sky-800 border-sky-200',
        'Disposed'    => 'bg-[#DCEFE4] text-leaf border-leaf',
        'Closed'      => 'bg-slate-100 text-slate-700 border-slate-300',
        'Reopened'    => 'bg-[#FFE0F0] text-hot border-hot',
    ];

    $classes = $map[$status] ?? 'bg-slate-100 text-slate-700 border-slate-300';

    return '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border-2 '
        . $classes . '">' . e($status) . '</span>';
}

if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Throwable $ex) {
        $_SESSION['csrf_token'] = md5(uniqid((string) mt_rand(), true));
    }
}
$csrfToken = (string) $_SESSION['csrf_token'];

$flashSuccess = '';
$flashError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = (string) ($_POST['action'] ?? '');

    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postedToken)) {
        $flashError = 'Invalid session token. Please refresh and try again.';
        $_SESSION['flash_error'] = $flashError;
        header('Location: dashboard.php');
        exit;
    }

    if ($action === 'create_grievance') {

        if ($conn === null) {
            $flashError = $dbError ?: 'Database is unavailable. Please try again later.';
        } else {
            try {
                $grievanceTypeId = (int) ($_POST['grievance_type_id'] ?? 0);
                $subject         = trim((string) ($_POST['subject']       ?? ''));
                $description     = trim((string) ($_POST['description']   ?? ''));

                if ($grievanceTypeId <= 0) throw new Exception('Please select a valid Grievance Type.');
                if ($subject === '') throw new Exception('Subject is required.');
                if (mb_strlen($subject, 'UTF-8') > 120) throw new Exception('Subject cannot exceed 120 characters.');
                if (mb_strlen($description, 'UTF-8') > 420) throw new Exception('Description cannot exceed 420 characters.');

                $chkType = $conn->prepare("SELECT id FROM grievance_types WHERE id = ? AND status = 'Active' LIMIT 1");
                $chkType->bind_param('i', $grievanceTypeId);
                $chkType->execute();
                if ($chkType->get_result()->num_rows === 0) { $chkType->close(); throw new Exception('Selected Grievance Type is invalid or inactive.'); }
                $chkType->close();

                $yearPrefix = 'GRV-' . date('Y') . '-';
                $grievanceNumber = '';

                for ($attempt = 0; $attempt < 5; $attempt++) {
                    $candidate = $yearPrefix . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);

                    $chkNum = $conn->prepare("SELECT id FROM grievances WHERE grievance_number = ? LIMIT 1");
                    $chkNum->bind_param('s', $candidate);
                    $chkNum->execute();
                    $exists = $chkNum->get_result()->num_rows > 0;
                    $chkNum->close();

                    if (!$exists) { $grievanceNumber = $candidate; break; }
                }

                if ($grievanceNumber === '') throw new Exception('Unable to generate a unique grievance number. Please try again.');

                if (!empty($_FILES['attachment']['name'])) {
                    $file = $_FILES['attachment'];

                    if ($file['error'] === UPLOAD_ERR_OK) {
                        $maxBytes = 5 * 1024 * 1024;

                        if ((int) $file['size'] > $maxBytes) throw new Exception('Attachment exceeds the maximum allowed size of 5 MB.');

                        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
                        $ext        = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));

                        if (!in_array($ext, $allowedExt, true)) throw new Exception('Invalid file type. Allowed: PDF, JPG, JPEG, PNG, DOC, DOCX.');

                        $uploadDir = __DIR__ . '/../uploads/grievances/';
                        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);

                        if (!is_dir($uploadDir) || !is_writable($uploadDir)) throw new Exception('Upload directory is not writable. Please contact support.');

                        $safeBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo((string) $file['name'], PATHINFO_FILENAME));
                        if ($safeBase === '' || $safeBase === null) $safeBase = 'file';
                        $safeBase = substr($safeBase, 0, 60);

                        $newFileName = 'grv_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '_' . $safeBase . '.' . $ext;
                        $targetPath  = $uploadDir . $newFileName;

                        if (!move_uploaded_file($file['tmp_name'], $targetPath)) throw new Exception('Failed to save the uploaded attachment. Please try again.');
                    } elseif ($file['error'] !== UPLOAD_ERR_NO_FILE) {
                        throw new Exception('File upload error (code ' . (int) $file['error'] . '). Please try again.');
                    }
                }

                $initialStatus = 'Pending';

                $sql = "INSERT INTO grievances
                            (grievance_number, grievance_type_id, complainant_user_id,
                             subject, description, status, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";

                $stmt = $conn->prepare($sql);
                if (!$stmt) throw new Exception('Query preparation failed: ' . $conn->error);

                $stmt->bind_param('siisss', $grievanceNumber, $grievanceTypeId, $userId, $subject, $description, $initialStatus);

                if (!$stmt->execute()) throw new Exception('Failed to submit grievance: ' . $stmt->error);

                $stmt->close();

                $flashSuccess = 'Grievance submitted successfully. Your reference number is ' . $grievanceNumber . '.';

            } catch (Throwable $ex) {
                error_log('[Parent Create Grievance] ' . $ex->getMessage());
                $flashError = $ex->getMessage() ?: 'A system error occurred while submitting your grievance.';
            }
        }

        $_SESSION['flash_success'] = $flashSuccess;
        $_SESSION['flash_error']   = $flashError;
        header('Location: dashboard.php');
        exit;
    }

    if ($action === 'update_grievance') {

        if ($conn === null) {
            $flashError = $dbError ?: 'Database is unavailable. Please try again later.';
        } else {
            try {
                $grievanceId     = (int) ($_POST['grievance_id']       ?? 0);
                $grievanceTypeId = (int) ($_POST['grievance_type_id']  ?? 0);
                $subject         = trim((string) ($_POST['subject']       ?? ''));
                $description     = trim((string) ($_POST['description']   ?? ''));

                if ($grievanceId <= 0) throw new Exception('Invalid grievance reference.');
                if ($grievanceTypeId <= 0) throw new Exception('Please select a valid Grievance Type.');
                if ($subject === '') throw new Exception('Subject is required.');
                if (mb_strlen($subject, 'UTF-8') > 120) throw new Exception('Subject cannot exceed 120 characters.');
                if (mb_strlen($description, 'UTF-8') > 420) throw new Exception('Description cannot exceed 420 characters.');

                $chk = $conn->prepare("SELECT id, status FROM grievances WHERE id = ? AND complainant_user_id = ? LIMIT 1");
                $chk->bind_param('ii', $grievanceId, $userId);
                $chk->execute();
                $chkRes = $chk->get_result();

                if ($chkRes->num_rows === 0) { $chk->close(); throw new Exception('Grievance not found or does not belong to your account.'); }

                $chkRow    = $chkRes->fetch_assoc();
                $curStatus = (string) ($chkRow['status'] ?? '');
                $chk->close();

                if (!in_array($curStatus, ['Pending', 'Reopened'], true)) throw new Exception('This grievance can no longer be edited (current status: ' . $curStatus . ').');

                $chkType = $conn->prepare("SELECT id FROM grievance_types WHERE id = ? AND status = 'Active' LIMIT 1");
                $chkType->bind_param('i', $grievanceTypeId);
                $chkType->execute();
                if ($chkType->get_result()->num_rows === 0) { $chkType->close(); throw new Exception('Selected Grievance Type is invalid or inactive.'); }
                $chkType->close();

                $sql = "UPDATE grievances
                        SET grievance_type_id = ?, subject = ?, description = ?, updated_at = NOW()
                        WHERE id = ? AND complainant_user_id = ?";

                $stmt = $conn->prepare($sql);
                if (!$stmt) throw new Exception('Query preparation failed: ' . $conn->error);

                $stmt->bind_param('issii', $grievanceTypeId, $subject, $description, $grievanceId, $userId);

                if (!$stmt->execute()) throw new Exception('Failed to update grievance: ' . $stmt->error);

                $stmt->close();

                $flashSuccess = 'Grievance updated successfully.';

            } catch (Throwable $ex) {
                error_log('[Parent Update Grievance] ' . $ex->getMessage());
                $flashError = $ex->getMessage() ?: 'A system error occurred while updating your grievance.';
            }
        }

        $_SESSION['flash_success'] = $flashSuccess;
        $_SESSION['flash_error']   = $flashError;
        header('Location: dashboard.php');
        exit;
    }

    if ($action === 'dispose_grievance') {

        if ($conn === null) {
            $flashError = $dbError ?: 'Database is unavailable. Please try again later.';
        } else {
            try {
                $grievanceId = (int) ($_POST['grievance_id'] ?? 0);

                if ($grievanceId <= 0) throw new Exception('Invalid grievance reference.');

                $chk = $conn->prepare("SELECT id, status FROM grievances WHERE id = ? AND complainant_user_id = ? LIMIT 1");
                $chk->bind_param('ii', $grievanceId, $userId);
                $chk->execute();
                $chkRes = $chk->get_result();

                if ($chkRes->num_rows === 0) { $chk->close(); throw new Exception('Grievance not found or does not belong to your account.'); }

                $chkRow    = $chkRes->fetch_assoc();
                $curStatus = (string) ($chkRow['status'] ?? '');
                $chk->close();

                if (!in_array($curStatus, ['Pending', 'In Progress', 'Reopened'], true)) throw new Exception('This grievance cannot be disposed (current status: ' . $curStatus . ').');

                $newStatus = 'Disposed';

                $sql = "UPDATE grievances SET status = ?, updated_at = NOW() WHERE id = ? AND complainant_user_id = ?";

                $stmt = $conn->prepare($sql);
                if (!$stmt) throw new Exception('Query preparation failed: ' . $conn->error);

                $stmt->bind_param('sii', $newStatus, $grievanceId, $userId);

                if (!$stmt->execute()) throw new Exception('Failed to dispose grievance: ' . $stmt->error);

                $stmt->close();

                $flashSuccess = 'Grievance has been marked as Disposed.';

            } catch (Throwable $ex) {
                error_log('[Parent Dispose Grievance] ' . $ex->getMessage());
                $flashError = $ex->getMessage() ?: 'A system error occurred while disposing your grievance.';
            }
        }

        $_SESSION['flash_success'] = $flashSuccess;
        $_SESSION['flash_error']   = $flashError;
        header('Location: dashboard.php');
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

$parentData = [
    'username'      => $_SESSION['username'] ?? 'Parent',
    'name'          => '',
    'email'         => '',
    'profile_image' => '',
];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  u.username, p.name, p.email, p.profile_image
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
                $parentData['username']      = $row['username']      ?? $parentData['username'];
                $parentData['name']          = $row['name']          ?? '';
                $parentData['email']         = $row['email']         ?? '';
                $parentData['profile_image'] = $row['profile_image'] ?? '';
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Parent Dashboard Profile] ' . $ex->getMessage());
    }
}

$displayName  = !empty($parentData['name']) ? $parentData['name'] : $parentData['username'];
$displayEmail = !empty($parentData['email']) ? $parentData['email'] : 'parent@rajagiri.edu';

$hasProfilePicture = false;
$profilePictureUrl = '';

if (!empty($parentData['profile_image'])) {
    $relative     = ltrim((string) $parentData['profile_image'], '/');
    $absolutePath = __DIR__ . '/../' . $relative;
    $browserPath  = '../' . $relative;

    if (file_exists($absolutePath) && is_file($absolutePath)) {
        $hasProfilePicture = true;
        $profilePictureUrl = $browserPath;
    }
}

$grievanceTypes = [];

if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, type_name FROM grievance_types WHERE status = 'Active' ORDER BY type_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) $grievanceTypes[] = $row;
        }
    } catch (Throwable $ex) {
        error_log('[Parent Dashboard Grievance Types] ' . $ex->getMessage());
    }
}

$grievances = [];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  g.id, g.grievance_number, g.subject, g.description, g.status,
                        g.reply_details, g.created_at, g.updated_at, g.grievance_type_id,
                        gt.type_name
                FROM grievances g
                LEFT JOIN grievance_types gt ON gt.id = g.grievance_type_id
                WHERE g.complainant_user_id = ?
                ORDER BY g.created_at DESC";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) $grievances[] = $row;
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Parent Dashboard Grievances] ' . $ex->getMessage());
    }
}

$totalGrievances = count($grievances);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Parent grievance dashboard — Rajagiri College Grievance Redressal Portal." />
  <meta name="theme-color" content="#DB0878" />
  <title>Grievance Details — Parent | Rajagiri College Grievance Portal</title>
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
           class="group relative w-full h-12 rounded-xl bg-hot ring-2 ring-sun/60 flex items-center text-white nav-icon-link px-3">
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

        <?php if ($dbError): ?>
          <div class="max-w-7xl mx-auto mb-6 rounded-xl border-2 border-amber-300 bg-amber-50 px-4 py-3 flex items-start gap-2">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-amber-700 font-medium"><?= e($dbError) ?></p>
          </div>
        <?php endif; ?>

        <div class="max-w-7xl mx-auto mb-6 rise">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
              <h1 class="font-display text-2xl md:text-3xl font-extrabold tracking-tight text-ink mb-2">Grievance Details</h1>
              <nav class="flex flex-wrap items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
                <a href="dashboard.php" class="flex items-center rounded transition-colors hover:text-hot"><i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i> Dashboard</a>
                <span class="text-slate-300">/</span>
                <a href="dashboard.php" class="rounded transition-colors hover:text-hot">Grievance</a>
                <span class="text-slate-300">/</span>
                <span class="text-hot font-semibold">Grievance Details</span>
              </nav>
            </div>

            <button type="button" onclick="openCreateGrievanceModal()" title="Add Grievance" aria-label="Add grievance"
                    class="btn-hard inline-flex items-center justify-center w-11 h-11 rounded-xl bg-hot text-white hover:bg-hotdark">
              <i data-lucide="plus" class="w-5 h-5"></i>
            </button>
          </div>
        </div>

        <?php if ($flashSuccess !== ''): ?>
          <div id="flashSuccessBox" class="max-w-7xl mx-auto mb-6 rounded-xl border-2 border-leaf bg-[#DCEFE4] px-4 py-3 flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="check-circle" class="w-5 h-5 text-leaf flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-leaf font-semibold"><?= e($flashSuccess) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
          <div id="flashErrorBox" class="max-w-7xl mx-auto mb-6 rounded-xl border-2 border-red-300 bg-red-50 px-4 py-3 flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-red-700 font-semibold"><?= e($flashError) ?></p>
          </div>
        <?php endif; ?>

        <div class="max-w-7xl mx-auto mb-5 rise rise-2">
          <div class="bg-white rounded-2xl border-2 border-ink px-5 py-4 shadow-[4px_4px_0_#FFC93C]">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div class="flex items-center gap-3">
                <span class="text-sm text-slate-600">Show</span>
                <select id="entriesPerPage"
                        class="px-3 py-1.5 border-2 border-ink rounded-lg text-sm font-semibold text-ink bg-white focus:outline-none focus:ring-4 focus:ring-hot/10 transition-colors">
                  <option value="10" selected>10</option>
                  <option value="25">25</option>
                  <option value="50">50</option>
                  <option value="100">100</option>
                </select>
                <span class="text-sm text-slate-600">entries</span>
              </div>

              <div class="relative w-full sm:w-80">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                <input type="text" id="searchInput" placeholder="Search.." autocomplete="off"
                       class="w-full pl-10 pr-4 py-2 border-2 border-ink rounded-lg text-sm bg-white focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
              </div>
            </div>
          </div>
        </div>

        <div class="max-w-7xl mx-auto rise rise-3">
          <div class="bg-white rounded-2xl border-2 border-ink overflow-hidden shadow-[6px_6px_0_#FFC93C]">

            <table class="w-full table-fixed" id="grievancesTable">
              <colgroup>
                <col style="width: 4%;">
                <col style="width: 13%;">
                <col style="width: 13%;">
                <col style="width: 9%;">
                <col style="width: 20%;">
                <col style="width: 9%;">
                <col style="width: 12%;">
                <col style="width: 20%;">
              </colgroup>
              <thead>
                <tr class="bg-ink text-white">
                  <th class="px-2 py-3 text-left text-[10px] font-bold uppercase tracking-wider">Sl.No.</th>
                  <th class="px-2 py-3 text-left text-[10px] font-bold uppercase tracking-wider">Grievance Number</th>
                  <th class="px-2 py-3 text-left text-[10px] font-bold uppercase tracking-wider">Grievance Type</th>
                  <th class="px-2 py-3 text-left text-[10px] font-bold uppercase tracking-wider">Date</th>
                  <th class="px-2 py-3 text-left text-[10px] font-bold uppercase tracking-wider">Subject</th>
                  <th class="px-2 py-3 text-left text-[10px] font-bold uppercase tracking-wider">Status</th>
                  <th class="px-2 py-3 text-center text-[10px] font-bold uppercase tracking-wider">Actions</th>
                  <th class="px-2 py-3 text-center text-[10px] font-bold uppercase tracking-wider">Remainder / Reopen</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100" id="grievancesTableBody">

                <?php if (empty($grievances)): ?>
                  <tr>
                    <td colspan="8" class="px-4 py-16 text-center text-slate-500">
                      <div class="flex flex-col items-center justify-center">
                        <span class="w-16 h-16 rounded-full bg-blush border-2 border-ink flex items-center justify-center mb-4">
                          <i data-lucide="inbox" class="w-8 h-8 text-hot"></i>
                        </span>
                        <p class="text-lg font-bold text-ink">No data available in table</p>
                        <p class="text-sm text-slate-500 mt-1">Click the "+" button above to submit your first grievance.</p>
                      </div>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($grievances as $index => $row): ?>
                    <?php
                      $gId         = (int) $row['id'];
                      $gTypeId     = (int) ($row['grievance_type_id'] ?? 0);
                      $gNumber     = (string) ($row['grievance_number'] ?? '—');
                      $gType       = (string) ($row['type_name']        ?? '—');
                      $gSubject    = (string) ($row['subject']          ?? '—');
                      $gDesc       = (string) ($row['description']      ?? '');
                      $gReply      = (string) ($row['reply_details']    ?? '');
                      $gStatus     = (string) ($row['status']           ?? 'Pending');
                      $gCreated    = !empty($row['created_at']) ? date('d M y', strtotime((string) $row['created_at'])) : '—';

                      $canEdit     = in_array($gStatus, ['Pending', 'Reopened'], true);
                      $canDispose  = in_array($gStatus, ['Pending', 'In Progress', 'Reopened'], true);
                      $canRemind   = in_array($gStatus, ['Pending', 'In Progress'], true);
                      $canReopen   = in_array($gStatus, ['Disposed', 'Closed'], true);
                    ?>
                    <tr class="grievance-row hover:bg-blush/60 transition-colors align-middle">
                      <td class="px-2 py-4 text-xs font-medium text-slate-900"><?= $index + 1 ?></td>
                      <td class="px-2 py-4 text-xs font-bold text-hot break-words"><?= e($gNumber) ?></td>
                      <td class="px-2 py-4 text-xs text-slate-700 break-words"><?= e($gType) ?></td>
                      <td class="px-2 py-4 text-xs text-slate-600 whitespace-nowrap"><?= e($gCreated) ?></td>
                      <td class="px-2 py-4 text-xs text-slate-700 break-words" title="<?= e($gSubject) ?>"><?= e($gSubject) ?></td>
                      <td class="px-2 py-4 whitespace-nowrap"><?= statusBadge($gStatus) ?></td>

                      <td class="px-2 py-4">
                        <div class="flex items-center justify-center gap-1">
                          <button type="button" title="View grievance" aria-label="View"
                                  onclick='openViewGrievanceModal(
                                      <?= json_encode($gNumber) ?>,
                                      <?= json_encode($gType) ?>,
                                      <?= json_encode($gSubject) ?>,
                                      <?= json_encode($gDesc) ?>,
                                      <?= json_encode($gStatus) ?>,
                                      <?= json_encode($gReply) ?>,
                                      <?= json_encode($gCreated) ?>
                                  )'
                                  class="w-7 h-7 rounded-full bg-blush border-2 border-ink inline-flex items-center justify-center text-ink hover:bg-hot hover:text-white transition-all duration-200 flex-shrink-0">
                            <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                          </button>

                          <?php if ($canEdit): ?>
                            <button type="button" title="Edit grievance" aria-label="Edit"
                                    onclick='openEditGrievanceModal(<?= $gId ?>, <?= $gTypeId ?>, <?= json_encode($gSubject) ?>, <?= json_encode($gDesc) ?>)'
                                    class="w-7 h-7 rounded-full bg-blush border-2 border-ink inline-flex items-center justify-center text-ink hover:bg-grape hover:text-white transition-all duration-200 flex-shrink-0">
                              <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                            </button>
                          <?php endif; ?>

                          <?php if ($canDispose): ?>
                            <button type="button" title="Dispose grievance" aria-label="Dispose"
                                    onclick='openDisposeModal(<?= $gId ?>, <?= json_encode($gNumber) ?>)'
                                    class="w-7 h-7 rounded-full bg-blush border-2 border-ink inline-flex items-center justify-center text-ink hover:bg-red-500 hover:text-white transition-all duration-200 flex-shrink-0">
                              <i data-lucide="x" class="w-3.5 h-3.5"></i>
                            </button>
                          <?php endif; ?>
                        </div>
                      </td>

                      <td class="px-2 py-4 text-center">
                        <?php if ($canRemind): ?>
                          <form method="POST" action="send_reminder.php" class="inline">
                            <input type="hidden" name="grievance_id" value="<?= $gId ?>" />
                            <button type="submit"
                                    class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-bold
                                           bg-sun border-2 border-ink text-ink
                                           hover:bg-[#FFD35C] transition-colors whitespace-nowrap">
                              <i data-lucide="bell" class="w-3 h-3"></i>
                              <span>Reminder</span>
                            </button>
                          </form>
                        <?php elseif ($canReopen): ?>
                          <button type="button"
                                  onclick="openReopenModal(<?= $gId ?>, <?= json_encode($gNumber) ?>)"
                                  class="inline-flex items-center gap-1 px-2 py-1 rounded-lg text-[10px] font-bold
                                         bg-[#FFE0F0] border-2 border-hot text-hot
                                         hover:bg-hot hover:text-white transition-colors whitespace-nowrap">
                            <i data-lucide="rotate-ccw" class="w-3 h-3"></i>
                            <span>Reopen</span>
                          </button>
                        <?php else: ?>
                          <span class="text-xs text-slate-400 italic">—</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>

              </tbody>
            </table>

            <div class="px-4 py-4 bg-blush border-t-2 border-ink flex flex-col sm:flex-row items-center justify-between gap-4">
              <p class="text-sm text-slate-600" id="tableInfo">
                Showing <span class="font-semibold text-ink" id="infoStart"><?= $totalGrievances > 0 ? 1 : 0 ?></span>
                to <span class="font-semibold text-ink" id="infoEnd"><?= $totalGrievances ?></span>
                of <span class="font-semibold text-ink" id="infoTotal"><?= $totalGrievances ?></span> entries
              </p>

              <div class="flex items-center gap-2">
                <button type="button" id="prevPageBtn"
                        class="px-4 py-2 rounded-lg text-sm font-semibold text-slate-400 bg-white border-2 border-slate-200 cursor-not-allowed"
                        disabled>Previous</button>

                <span id="currentPageBadge"
                      class="inline-flex items-center justify-center w-9 h-9 rounded-lg
                             bg-hot border-2 border-ink text-white text-sm font-bold shadow-[2px_2px_0_#FFC93C]">1</span>

                <button type="button" id="nextPageBtn"
                        class="px-4 py-2 rounded-lg text-sm font-semibold text-slate-400 bg-white border-2 border-slate-200 cursor-not-allowed"
                        disabled>Next</button>
              </div>
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

  <!-- CREATE GRIEVANCE MODAL -->
  <div id="createGrievanceModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeCreateGrievanceModal()"></div>
    <div class="relative w-full max-w-2xl bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden flex flex-col max-h-[92vh]">
      <div class="h-1.5 w-full bg-hot"></div>
      <div class="flex items-center justify-between px-6 py-4 border-b-2 border-ink bg-blush">
        <h3 class="font-display text-lg font-bold text-ink">Create Grievance</h3>
        <button type="button" onclick="closeCreateGrievanceModal()" aria-label="Close"
                class="w-8 h-8 rounded-lg border-2 border-ink bg-white hover:bg-blush flex items-center justify-center text-ink transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="createGrievanceForm" method="POST" action="dashboard.php" enctype="multipart/form-data" class="p-6 space-y-5 overflow-y-auto">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
        <input type="hidden" name="action" value="create_grievance" />

        <div class="space-y-2">
          <label for="grievance_type_id" class="block text-sm font-semibold text-ink">Grievance Type <span class="text-hot">*</span></label>
          <select id="grievance_type_id" name="grievance_type_id" required
                  class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all">
            <option value="" disabled selected>Select Grievance Type</option>
            <?php foreach ($grievanceTypes as $type): ?>
              <option value="<?= (int) $type['id'] ?>"><?= e((string) $type['type_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (empty($grievanceTypes)): ?>
            <p class="text-xs text-amber-600 font-semibold mt-1">No active grievance types are configured. Please contact the administrator.</p>
          <?php endif; ?>
        </div>

        <div class="space-y-2">
          <label for="subject" class="block text-sm font-semibold text-ink">Subject <span class="text-hot">*</span></label>
          <input type="text" id="subject" name="subject" required maxlength="120" placeholder="Enter a brief subject"
                 class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
          <p class="text-xs text-slate-500">(Maximum 120 character) · <span id="subjectCounter" class="font-semibold text-slate-600">0</span>/120</p>
        </div>

        <div class="space-y-2">
          <label for="description" class="block text-sm font-semibold text-ink">Description</label>
          <textarea id="description" name="description" rows="5" maxlength="420" placeholder="Describe your grievance in detail…"
                    class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 resize-none focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all"></textarea>
          <p class="text-xs text-slate-500">(Maximum 420 character) · <span id="descriptionCounter" class="font-semibold text-slate-600">0</span>/420</p>
        </div>

        <div class="space-y-2">
          <label for="attachment" class="block text-sm font-semibold text-ink">Attachment</label>
          <div class="flex items-center gap-3">
            <label for="attachment"
                   class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg border-2 border-ink
                          bg-white hover:bg-blush text-ink font-bold text-sm cursor-pointer transition-colors">
              <i data-lucide="upload" class="w-4 h-4"></i>
              <span>Choose files</span>
            </label>
            <span id="attachmentFileName" class="text-sm text-slate-500 truncate">No file chosen</span>
            <input type="file" id="attachment" name="attachment" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="hidden" />
          </div>
          <p class="text-xs text-slate-500">(Max 5 Mb)</p>
        </div>

        <div class="pt-2 flex justify-center">
          <button type="submit"
                  class="btn-hard inline-flex items-center justify-center gap-2 rounded-xl bg-hot px-10 py-3 text-sm font-bold text-white hover:bg-hotdark">
            <i data-lucide="send" class="w-4 h-4"></i>
            <span>Submit</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- EDIT GRIEVANCE MODAL -->
  <div id="editGrievanceModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeEditGrievanceModal()"></div>
    <div class="relative w-full max-w-2xl bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden flex flex-col max-h-[92vh]">
      <div class="h-1.5 w-full bg-hot"></div>
      <div class="flex items-center justify-between px-6 py-4 border-b-2 border-ink bg-blush">
        <h3 class="font-display text-lg font-bold text-ink">Edit Grievance</h3>
        <button type="button" onclick="closeEditGrievanceModal()" aria-label="Close"
                class="w-8 h-8 rounded-lg border-2 border-ink bg-white hover:bg-blush flex items-center justify-center text-ink transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="editGrievanceForm" method="POST" action="dashboard.php" class="p-6 space-y-5 overflow-y-auto">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
        <input type="hidden" name="action" value="update_grievance" />
        <input type="hidden" name="grievance_id" id="editGrievanceId" value="" />

        <div class="space-y-2">
          <label for="edit_grievance_type_id" class="block text-sm font-semibold text-ink">Grievance Type <span class="text-hot">*</span></label>
          <select id="edit_grievance_type_id" name="grievance_type_id" required
                  class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all">
            <option value="" disabled>Select Grievance Type</option>
            <?php foreach ($grievanceTypes as $type): ?>
              <option value="<?= (int) $type['id'] ?>"><?= e((string) $type['type_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="space-y-2">
          <label for="edit_subject" class="block text-sm font-semibold text-ink">Subject <span class="text-hot">*</span></label>
          <input type="text" id="edit_subject" name="subject" required maxlength="120" placeholder="Enter a brief subject"
                 class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
          <p class="text-xs text-slate-500">(Maximum 120 character) · <span id="editSubjectCounter" class="font-semibold text-slate-600">0</span>/120</p>
        </div>

        <div class="space-y-2">
          <label for="edit_description" class="block text-sm font-semibold text-ink">Description</label>
          <textarea id="edit_description" name="description" rows="5" maxlength="420" placeholder="Describe your grievance in detail…"
                    class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 resize-none focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all"></textarea>
          <p class="text-xs text-slate-500">(Maximum 420 character) · <span id="editDescriptionCounter" class="font-semibold text-slate-600">0</span>/420</p>
        </div>

        <div class="pt-2 flex justify-center gap-3">
          <button type="button" onclick="closeEditGrievanceModal()"
                  class="px-6 py-3 rounded-xl font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">Cancel</button>
          <button type="submit"
                  class="btn-hard inline-flex items-center justify-center gap-2 rounded-xl bg-hot px-8 py-3 text-sm font-bold text-white hover:bg-hotdark">
            <i data-lucide="save" class="w-4 h-4"></i>
            <span>Save Changes</span>
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- DISPOSE CONFIRM MODAL -->
  <div id="disposeConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDisposeModal()"></div>
    <div id="disposeConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-red-500"></div>
      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <span class="w-16 h-16 rounded-full bg-red-50 border-2 border-ink flex items-center justify-center mb-4">
          <i data-lucide="x" class="w-8 h-8 text-red-500"></i>
        </span>
        <h3 class="font-display text-xl font-bold text-ink mb-2">Dispose Grievance?</h3>
        <p class="text-sm text-slate-500 leading-relaxed">
          You are about to mark
          <span id="disposeGrievanceNumber" class="font-bold text-hot break-words">this grievance</span>
          as <strong class="text-red-600">Disposed</strong>.
        </p>
        <p class="text-xs text-amber-600 font-semibold mt-3 flex items-center gap-1.5">
          <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i> The grievance committee will be notified.
        </p>
      </div>
      <form id="disposeForm" method="POST" action="dashboard.php" class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <input type="hidden" name="grievance_id" id="disposeGrievanceId" value="" />
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
        <input type="hidden" name="action" value="dispose_grievance" />

        <button type="button" onclick="closeDisposeModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">Cancel</button>
        <button type="submit"
                class="btn-hard flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-red-500 px-5 py-3 text-sm font-bold text-white hover:bg-red-600">
          <i data-lucide="x" class="w-4 h-4"></i> Dispose
        </button>
      </form>
    </div>
  </div>

  <!-- VIEW GRIEVANCE MODAL -->
  <div id="viewGrievanceModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeViewGrievanceModal()"></div>
    <div class="relative w-full max-w-2xl bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden flex flex-col max-h-[90vh]">
      <div class="h-1.5 w-full bg-hot"></div>
      <div class="flex items-center justify-between px-6 py-4 border-b-2 border-ink bg-blush">
        <h3 class="font-display text-lg font-bold text-ink">Grievance Details</h3>
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
            <p id="vgType" class="text-sm font-bold text-ink break-words">—</p>
          </div>
          <div class="bg-blush rounded-xl p-3 border-2 border-ink">
            <p class="text-[10px] font-bold uppercase tracking-wider text-hot mb-1">Submitted On</p>
            <p id="vgDate2" class="text-sm font-bold text-ink">—</p>
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
      </div>

      <div class="px-6 py-4 bg-blush border-t-2 border-ink flex justify-end">
        <button type="button" onclick="closeViewGrievanceModal()"
                class="px-5 py-2.5 rounded-lg font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">Close</button>
      </div>
    </div>
  </div>

  <!-- REOPEN CONFIRM MODAL -->
  <div id="reopenConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeReopenModal()"></div>
    <div id="reopenConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-hot"></div>
      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <span class="w-16 h-16 rounded-full bg-[#FFE0F0] border-2 border-ink flex items-center justify-center mb-4">
          <i data-lucide="rotate-ccw" class="w-8 h-8 text-hot"></i>
        </span>
        <h3 class="font-display text-xl font-bold text-ink mb-2">Reopen Grievance?</h3>
        <p class="text-sm text-slate-500 leading-relaxed">
          You are about to reopen
          <span id="reopenGrievanceNumber" class="font-bold text-hot break-words">this grievance</span>.
          It will be sent back to the grievance committee for review.
        </p>
        <p class="text-xs text-amber-600 font-semibold mt-3 flex items-center gap-1.5">
          <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i> Only reopen if the issue is not resolved.
        </p>
      </div>
      <form id="reopenForm" method="POST" action="reopen_grievance.php" class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <input type="hidden" name="grievance_id" id="reopenGrievanceId" value="" />
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />

        <button type="button" onclick="closeReopenModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">Cancel</button>
        <button type="submit"
                class="btn-hard flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-hot px-5 py-3 text-sm font-bold text-white hover:bg-hotdark">
          <i data-lucide="rotate-ccw" class="w-4 h-4"></i> Reopen
        </button>
      </form>
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

    const createGrievanceModal = document.getElementById('createGrievanceModal');
    const createGrievanceForm  = document.getElementById('createGrievanceForm');
    const subjectInput         = document.getElementById('subject');
    const descriptionInput     = document.getElementById('description');
    const subjectCounter       = document.getElementById('subjectCounter');
    const descriptionCounter   = document.getElementById('descriptionCounter');
    const attachmentInput      = document.getElementById('attachment');
    const attachmentFileName   = document.getElementById('attachmentFileName');

    function openCreateGrievanceModal() {
      if (!createGrievanceModal) return;
      if (createGrievanceForm) createGrievanceForm.reset();
      if (subjectCounter)     subjectCounter.textContent = '0';
      if (descriptionCounter) descriptionCounter.textContent = '0';
      if (attachmentFileName) attachmentFileName.textContent = 'No file chosen';
      createGrievanceModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      setTimeout(function () { const el = document.getElementById('grievance_type_id'); if (el) el.focus(); }, 60);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeCreateGrievanceModal() {
      if (!createGrievanceModal) return;
      createGrievanceModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }
    if (subjectInput && subjectCounter) {
      subjectInput.addEventListener('input', function () {
        subjectCounter.textContent = String(this.value.length);
        subjectCounter.classList.toggle('text-red-600', this.value.length > 120);
      });
    }
    if (descriptionInput && descriptionCounter) {
      descriptionInput.addEventListener('input', function () {
        descriptionCounter.textContent = String(this.value.length);
        descriptionCounter.classList.toggle('text-red-600', this.value.length > 420);
      });
    }
    if (attachmentInput && attachmentFileName) {
      attachmentInput.addEventListener('change', function () {
        const file = this.files && this.files[0] ? this.files[0] : null;
        if (!file) { attachmentFileName.textContent = 'No file chosen'; attachmentFileName.classList.remove('text-red-600'); return; }
        const maxBytes = 5 * 1024 * 1024;
        if (file.size > maxBytes) { attachmentFileName.textContent = file.name + ' — exceeds 5 MB limit'; attachmentFileName.classList.add('text-red-600'); this.value = ''; return; }
        attachmentFileName.classList.remove('text-red-600');
        attachmentFileName.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
      });
    }

    const editGrievanceModal     = document.getElementById('editGrievanceModal');
    const editGrievanceForm      = document.getElementById('editGrievanceForm');
    const editGrievanceId        = document.getElementById('editGrievanceId');
    const editTypeSelect         = document.getElementById('edit_grievance_type_id');
    const editSubjectInput       = document.getElementById('edit_subject');
    const editDescriptionInput   = document.getElementById('edit_description');
    const editSubjectCounter     = document.getElementById('editSubjectCounter');
    const editDescriptionCounter = document.getElementById('editDescriptionCounter');

    function openEditGrievanceModal(id, typeId, subject, description) {
      if (!editGrievanceModal) return;
      editGrievanceId.value = String(id);
      editTypeSelect.value  = String(typeId);
      editSubjectInput.value = subject || '';
      editDescriptionInput.value = description || '';
      editSubjectCounter.textContent = String((subject || '').length);
      editDescriptionCounter.textContent = String((description || '').length);
      editSubjectCounter.classList.toggle('text-red-600', (subject || '').length > 120);
      editDescriptionCounter.classList.toggle('text-red-600', (description || '').length > 420);
      editGrievanceModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      setTimeout(function () { if (editSubjectInput) editSubjectInput.focus(); }, 60);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeEditGrievanceModal() {
      if (!editGrievanceModal) return;
      editGrievanceModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
      if (editGrievanceForm) editGrievanceForm.reset();
    }
    if (editSubjectInput && editSubjectCounter) {
      editSubjectInput.addEventListener('input', function () {
        editSubjectCounter.textContent = String(this.value.length);
        editSubjectCounter.classList.toggle('text-red-600', this.value.length > 120);
      });
    }
    if (editDescriptionInput && editDescriptionCounter) {
      editDescriptionInput.addEventListener('input', function () {
        editDescriptionCounter.textContent = String(this.value.length);
        editDescriptionCounter.classList.toggle('text-red-600', this.value.length > 420);
      });
    }

    const disposeConfirmModal = document.getElementById('disposeConfirmModal');
    const disposeConfirmPanel = document.getElementById('disposeConfirmPanel');
    const disposeGrievanceId  = document.getElementById('disposeGrievanceId');
    const disposeGrievanceNum = document.getElementById('disposeGrievanceNumber');

    function openDisposeModal(grievanceId, grievanceNumber) {
      if (!disposeConfirmModal) return;
      disposeGrievanceId.value = String(grievanceId);
      disposeGrievanceNum.textContent = '"' + (grievanceNumber || '') + '"';
      disposeConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      if (disposeConfirmPanel) {
        disposeConfirmPanel.classList.remove('animate-confirm-shake');
        void disposeConfirmPanel.offsetWidth;
        disposeConfirmPanel.classList.add('animate-confirm-shake');
      }
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeDisposeModal() {
      if (!disposeConfirmModal) return;
      disposeConfirmModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }

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

    function openViewGrievanceModal(number, type, subject, description, status, reply, date) {
      document.getElementById('vgNumber').textContent     = number || '—';
      document.getElementById('vgSubject').textContent    = subject || '—';
      document.getElementById('vgStatus').innerHTML       = statusBadgeHtml(status);
      document.getElementById('vgType').textContent       = type || '—';
      document.getElementById('vgDate').textContent       = date || '—';
      document.getElementById('vgDate2').textContent      = date || '—';
      document.getElementById('vgDescription').textContent = (description && description.trim() !== '') ? description : 'No description provided.';
      document.getElementById('vgReply').textContent      = (reply && reply.trim() !== '') ? reply : 'No response yet from the grievance committee.';
      viewGrievanceModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeViewGrievanceModal() {
      viewGrievanceModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }

    const reopenConfirmModal = document.getElementById('reopenConfirmModal');
    const reopenConfirmPanel = document.getElementById('reopenConfirmPanel');

    function openReopenModal(grievanceId, grievanceNumber) {
      document.getElementById('reopenGrievanceId').value = grievanceId;
      document.getElementById('reopenGrievanceNumber').textContent = '"' + (grievanceNumber || '') + '"';
      reopenConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');
      if (reopenConfirmPanel) {
        reopenConfirmPanel.classList.remove('animate-confirm-shake');
        void reopenConfirmPanel.offsetWidth;
        reopenConfirmPanel.classList.add('animate-confirm-shake');
      }
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeReopenModal() {
      reopenConfirmModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }

    const logoutConfirmModal = document.getElementById('logoutConfirmModal');
    const logoutConfirmPanel = document.getElementById('logoutConfirmPanel');
    const confirmLogoutBtn   = document.getElementById('confirmLogoutBtn');
    const LOGOUT_URL = '../logout.php?role=parent';

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

    (function () {
      const searchInput   = document.getElementById('searchInput');
      const entriesSelect = document.getElementById('entriesPerPage');
      const tableBody     = document.getElementById('grievancesTableBody');
      const infoStart     = document.getElementById('infoStart');
      const infoEnd       = document.getElementById('infoEnd');
      const infoTotal     = document.getElementById('infoTotal');
      const prevBtn       = document.getElementById('prevPageBtn');
      const nextBtn       = document.getElementById('nextPageBtn');
      const pageBadge     = document.getElementById('currentPageBadge');
      if (!tableBody) return;

      const allRows = Array.from(tableBody.querySelectorAll('tr')).filter(function (r) {
        return !r.querySelector('td[colspan]');
      });

      let pageSize    = 10;
      let currentPage = 1;
      let searchTerm  = '';

      function applyFilters() {
        const filtered = allRows.filter(function (row) {
          if (searchTerm === '') return true;
          return row.textContent.toLowerCase().indexOf(searchTerm) !== -1;
        });

        const totalPages = Math.max(1, Math.ceil(filtered.length / pageSize));
        if (currentPage > totalPages) currentPage = totalPages;

        allRows.forEach(function (r) { r.style.display = 'none'; });

        const startIdx = (currentPage - 1) * pageSize;
        const endIdx   = Math.min(startIdx + pageSize, filtered.length);

        filtered.slice(startIdx, endIdx).forEach(function (r) { r.style.display = ''; });

        if (infoStart) infoStart.textContent = filtered.length === 0 ? 0 : startIdx + 1;
        if (infoEnd)   infoEnd.textContent   = endIdx;
        if (infoTotal) infoTotal.textContent = filtered.length;

        if (prevBtn) prevBtn.disabled = (currentPage <= 1);
        if (nextBtn) nextBtn.disabled = (currentPage >= totalPages);

        if (prevBtn) {
          if (currentPage <= 1) prevBtn.className = 'px-4 py-2 rounded-lg text-sm font-semibold text-slate-400 bg-white border-2 border-slate-200 cursor-not-allowed';
          else prevBtn.className = 'px-4 py-2 rounded-lg text-sm font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors';
        }
        if (nextBtn) {
          if (currentPage >= totalPages) nextBtn.className = 'px-4 py-2 rounded-lg text-sm font-semibold text-slate-400 bg-white border-2 border-slate-200 cursor-not-allowed';
          else nextBtn.className = 'px-4 py-2 rounded-lg text-sm font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors';
        }

        if (pageBadge) pageBadge.textContent = currentPage;
      }

      if (searchInput) {
        let timer = null;
        searchInput.addEventListener('input', function () {
          clearTimeout(timer);
          const self = this;
          timer = setTimeout(function () {
            searchTerm = self.value.toLowerCase().trim();
            currentPage = 1;
            applyFilters();
          }, 200);
        });
      }

      if (entriesSelect) {
        entriesSelect.addEventListener('change', function () {
          pageSize = parseInt(this.value, 10) || 10;
          currentPage = 1;
          applyFilters();
        });
      }

      if (prevBtn) prevBtn.addEventListener('click', function () { if (currentPage > 1) { currentPage--; applyFilters(); } });
      if (nextBtn) nextBtn.addEventListener('click', function () { currentPage++; applyFilters(); });

      applyFilters();
    })();

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      if (createGrievanceModal && !createGrievanceModal.classList.contains('hidden')) closeCreateGrievanceModal();
      if (editGrievanceModal && !editGrievanceModal.classList.contains('hidden')) closeEditGrievanceModal();
      if (disposeConfirmModal && !disposeConfirmModal.classList.contains('hidden')) closeDisposeModal();
      if (viewGrievanceModal && !viewGrievanceModal.classList.contains('hidden')) closeViewGrievanceModal();
      if (reopenConfirmModal && !reopenConfirmModal.classList.contains('hidden')) closeReopenModal();
      if (logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) closeLogoutModal();
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>