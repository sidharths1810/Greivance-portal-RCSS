<?php
/**
 * login.php
 * ---------------------------------------------------------------------------
 * Unified Grievance Redressal Portal Login
 * Rajagiri College of Social Sciences
 *
 * Roles supported (URL param): admin | student | parent | teacher |
 *                              non_teaching | management
 * Usage:  login.php?role=admin
 *
 * DB role ENUM values (UPPERCASE):
 *   ADMIN | STUDENT | PARENT | TEACHER | NON_TEACHING | MANAGEMENT
 *
 * DB status ENUM values:
 *   Pending | Approved | Rejected | Terminated
 *
 * Registration is allowed only for:
 *   STUDENT | PARENT | TEACHER | NON_TEACHING
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

$roleDashboardMap = [
    'ADMIN'        => 'admin/dashboard.php',
    'STUDENT'      => 'student/dashboard.php',
    'PARENT'       => 'parent/dashboard.php',
    'TEACHER'      => 'teacher/dashboard.php',
    'NON_TEACHING' => 'non_teaching/dashboard.php',
    'MANAGEMENT'   => 'management/dashboard.php',
];

if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    $currentRole = strtoupper((string) $_SESSION['role']);
    $targetPath  = $roleDashboardMap[$currentRole] ?? 'student/dashboard.php';

    header('Location: ' . $targetPath);
    exit;
}

$dbError = null;
$conn    = null;

$dbFile = __DIR__ . '/db_connect.php';

if (!file_exists($dbFile)) {
    $dbError = 'Database configuration file (db_connect.php) is missing.';
} else {
    require_once $dbFile;

    if (!isset($conn) || !($conn instanceof mysqli)) {
        $conn = @new mysqli('localhost', 'root', '', 'grievance_db');

        if ($conn->connect_error) {
            $dbError = 'Unable to connect to the database.';
            $conn    = null;
        } else {
            $conn->set_charset('utf8mb4');
        }
    }

    if ($conn && $conn->connect_errno) {
        $dbError = 'Database connection failed: ' . $conn->connect_error;
        $conn    = null;
    }
}

$roleConfig = [
    'admin' => [
        'db_role'       => 'ADMIN',
        'title'         => 'Administrator Portal',
        'subtitle'      => 'Core Control & System Governance',
        'icon'          => 'shield-check',
        'placeholder'   => 'Enter your Admin Username',
        'label'         => 'Admin Username',
        'notice'        => 'System Administrator Access Only',
        'supportMail'   => 'admin.support@rajagiri.edu',
        'supportTag'    => 'Tech Support',
        'register_page' => null,
    ],
    'student' => [
        'db_role'       => 'STUDENT',
        'title'         => 'Student Portal',
        'subtitle'      => 'Secure access to your grievance dashboard',
        'icon'          => 'graduation-cap',
        'placeholder'   => 'Enter your Student Username',
        'label'         => 'Student Username',
        'notice'        => 'Enrolled Students Only',
        'supportMail'   => 'student.grievance@rajigarircss.edu',
        'supportTag'    => 'Helpdesk Email',
        'register_page' => 'student_register.php',
    ],
    'parent' => [
        'db_role'       => 'PARENT',
        'title'         => 'Parent Portal',
        'subtitle'      => "Monitor Your Ward's Grievances",
        'icon'          => 'users',
        'placeholder'   => 'Enter your Email',
        'label'         => 'Parent Email',
        'notice'        => 'For Registered Parents & Guardians',
        'supportMail'   => 'parent.help@rajigarircss.edu',
        'supportTag'    => 'Support Email',
        'register_page' => 'parent_register.php',
    ],
    'teacher' => [
        'db_role'       => 'TEACHER',
        'title'         => 'Teacher Portal',
        'subtitle'      => 'Teaching Staff Access',
        'icon'          => 'briefcase',
        'placeholder'   => 'Enter your Teacher Username',
        'label'         => 'Teacher Username',
        'notice'        => 'Verified Teaching Staff Only',
        'supportMail'   => 'staff.help@rajagiri.edu',
        'supportTag'    => 'Support Email',
        'register_page' => 'teacher_register.php',
    ],
    'non_teaching' => [
        'db_role'       => 'NON_TEACHING',
        'title'         => 'Non-Teaching Staff Portal',
        'subtitle'      => 'Administrative & Support Staff Access',
        'icon'          => 'briefcase',
        'placeholder'   => 'Enter your Staff Username',
        'label'         => 'Staff Username',
        'notice'        => 'Verified Non-Teaching Staff Only',
        'supportMail'   => 'staff.help@rajagiri.edu',
        'supportTag'    => 'Support Email',
        'register_page' => 'non_teaching_register.php',
    ],
    'management' => [
        'db_role'       => 'MANAGEMENT',
        'title'         => 'Management & Grievance Portal',
        'subtitle'      => 'Committee Oversight & Resolution Management',
        'icon'          => 'layers',
        'placeholder'   => 'Enter your Management Username',
        'label'         => 'Management Username',
        'notice'        => 'Authorized Committee Members Only',
        'supportMail'   => 'grievance.committee@rajagiri.edu',
        'supportTag'    => 'Committee Helpdesk',
        'register_page' => null,
    ],
];

$registrationAllowedRoles = ['student', 'parent', 'teacher', 'non_teaching'];

$roleKey = isset($_GET['role']) ? strtolower(trim((string) $_GET['role'])) : 'student';
if (!array_key_exists($roleKey, $roleConfig)) {
    $roleKey = 'student';
}
$role = $roleConfig[$roleKey];

$canRegister = in_array($roleKey, $registrationAllowedRoles, true);

$errors     = [];
$loginInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';
    $sessionToken   = $_SESSION['csrf_token'] ?? '';

    if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    }

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (isset($_POST['role']) && array_key_exists($_POST['role'], $roleConfig)) {
        $roleKey = $_POST['role'];
    }
    $role = $roleConfig[$roleKey];
    $canRegister = in_array($roleKey, $registrationAllowedRoles, true);

    $dbRole = strtoupper((string) $role['db_role']);

    $loginInput = $username;

    if ($username === '') $errors[] = 'Please enter your username.';
    if ($password === '') $errors[] = 'Please enter your password.';

    if (empty($errors)) {

        if ($conn === null) {
            $errors[] = $dbError ?: 'Database is unavailable. Please try again later.';
        } else {
            try {
                $sql = "SELECT id, username, password, role, status
                        FROM users
                        WHERE username = ?
                        LIMIT 1";

                $stmt = $conn->prepare($sql);

                if (!$stmt) {
                    throw new Exception('Query preparation failed: ' . $conn->error);
                }

                $stmt->bind_param('s', $username);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result === false || $result->num_rows === 0) {
                    $errors[] = 'Invalid username or password.';
                } else {
                    $user = $result->fetch_assoc();

                    $dbUserRole     = strtoupper((string) ($user['role']   ?? ''));
                    $dbUserStatus   = ucfirst(strtolower((string) ($user['status'] ?? '')));

                    if ($dbUserRole !== $dbRole) {
                        $errors[] = 'Invalid username or password for this portal.';
                    }
                    elseif ($dbUserStatus !== 'Approved') {
                        $errors[] = 'Your account status is ' . $dbUserStatus . '. Access is restricted until approved.';
                    }
                    elseif (!password_verify($password, $user['password'])) {
                        $errors[] = 'Invalid username or password.';
                    }
                    else {
                        session_regenerate_id(true);

                        $_SESSION['user_id']    = (int) $user['id'];
                        $_SESSION['username']   = $user['username'];
                        $_SESSION['role']       = $dbUserRole;
                        $_SESSION['logged_in']  = true;
                        $_SESSION['login_time'] = time();

                        $stmt->close();
                        $conn->close();

                        $targetPath = $roleDashboardMap[$dbUserRole] ?? 'student/dashboard.php';

                        header('Location: ' . $targetPath);
                        exit;
                    }
                }

                $stmt->close();

            } catch (Exception $ex) {
                error_log('[Login Error] ' . $ex->getMessage());
                $errors[] = 'A system error occurred while signing you in. Please try again.';
            }
        }
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

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
  <meta name="description" content="<?= e($role['title']) ?> — Rajagiri College of Social Sciences Grievance Redressal Portal" />
  <meta name="theme-color" content="#DB0878" />
  <title><?= e($role['title']) ?> — Rajagiri College of Social Sciences</title>
  <link rel="icon" type="image/svg+xml" href="public/favicon.svg" />

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
    };
  </script>

  <link rel="stylesheet" href="assets/css/index.css" />

  <style>
    html { scroll-behavior: smooth; }
    body { font-family: Figtree, system-ui, sans-serif; }

    a:focus-visible, button:focus-visible, input:focus-visible {
      outline: 3px solid #1F0A24;
      outline-offset: 3px;
    }
    .on-hot a:focus-visible, .on-hot button:focus-visible { outline-color: #fff; }

    .balance { text-wrap: balance; }

    .btn-hard {
      border: 2px solid #1F0A24;
      box-shadow: 4px 4px 0 #1F0A24;
      transition: transform .12s ease, box-shadow .12s ease, background-color .15s ease;
    }
    .btn-hard:hover { transform: translate(2px, 2px); box-shadow: 2px 2px 0 #1F0A24; }
    .btn-hard:active { transform: translate(4px, 4px); box-shadow: 0 0 0 #1F0A24; }

    .login-card {
      border: 2px solid #1F0A24;
      box-shadow: 10px 10px 0 #FFC93C;
    }

    .info-card {
      border: 2px solid rgba(255,255,255,.30);
      transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
      background: rgba(255,255,255,.10);
    }
    .info-card:hover {
      border-color: #1F0A24;
      box-shadow: 4px 4px 0 #FFC93C;
      transform: translate(-2px, -2px);
      background: #fff;
      color: #1F0A24;
    }
    .info-card:hover h3 { color: #1F0A24; }
    .info-card:hover p { color: #475569; }

    .field {
      border: 2px solid #E7E0E9;
      background: #fff;
      transition: border-color .15s ease, box-shadow .15s ease;
    }
    .field:focus {
      border-color: #DB0878;
      box-shadow: 0 0 0 4px rgba(219, 8, 120, .10);
      outline: none;
    }

    .role-badge {
      background: #FFF0F7;
      color: #DB0878;
      border: 2px solid #1F0A24;
    }

    @keyframes rise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
    .rise { animation: rise .55s cubic-bezier(.2,.7,.2,1) both; }
    .rise-2 { animation-delay: .10s; }
    .rise-3 { animation-delay: .20s; }

    @media (max-width: 640px) {
      .login-card { box-shadow: 6px 6px 0 #FFC93C; }
    }

    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after {
        animation-duration: .01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: .01ms !important;
        scroll-behavior: auto !important;
      }
    }

    ::-webkit-scrollbar { width: 10px; }
    ::-webkit-scrollbar-track { background: #fff0f7; }
    ::-webkit-scrollbar-thumb {
      background: #db0878;
      border-radius: 6px;
      border: 2px solid #fff0f7;
    }
    ::-webkit-scrollbar-thumb:hover { background: #b70664; }
  </style>
</head>

<body class="min-h-screen bg-white text-slate-700 antialiased selection:bg-sun selection:text-ink">

  <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="orel-logo" viewBox="0 0 128 40" style="overflow:visible">
      <path fill="#DB0878" d="M10 0H26A10 10 0 0 1 36 10V20A10 10 0 0 1 26 30H16L8 38V29.8A10 10 0 0 1 0 20V10A10 10 0 0 1 10 0Z"/>
      <path d="M10 15.5l5.5 5.5L27 9.5" fill="none" stroke="#FFC93C" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round"/>
      <text x="46" y="21" font-family="Bricolage Grotesque, system-ui, sans-serif" font-size="23" font-weight="800" fill="#1F0A24">Oréll</text>
      <text x="46.5" y="35" font-family="Figtree, system-ui, sans-serif" font-size="11" font-weight="700" letter-spacing="0.6" fill="#DB0878">Grievance</text>
    </symbol>
  </svg>

  <!-- Brand stripe -->
  <div class="fixed top-0 left-0 right-0 z-[60] flex h-1.5" aria-hidden="true">
    <span class="flex-1 bg-hot"></span>
    <span class="flex-1 bg-sun"></span>
    <span class="flex-1 bg-leaf"></span>
    <span class="flex-1 bg-grape"></span>
  </div>

  <!-- Header -->
  <header class="sticky top-0 z-50 border-b border-slate-200 bg-white/95 backdrop-blur-md">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex min-h-16 md:min-h-20 items-center justify-between gap-4">

        <a href="index.php" class="flex items-center gap-3 sm:gap-4 rounded-lg" aria-label="Return to RCSS Grievance Portal home">
          <img src="public/rcss-logo.png" alt="RCSS Logo" class="h-9 md:h-11 w-auto" />
          <span class="hidden sm:block h-8 w-px bg-slate-200" aria-hidden="true"></span>
          <svg class="hidden sm:block h-9 md:h-10 w-auto" width="128" height="40" viewBox="0 0 128 40" role="img" aria-label="Oréll Grievance"><use href="#orel-logo"></use></svg>
        </a>

        <a href="index.php"
           class="inline-flex items-center gap-2 rounded-lg border-2 border-ink bg-white px-3.5 py-2 text-sm font-bold text-ink transition hover:bg-blush hover:text-hot">
          <i data-lucide="arrow-left" class="h-4 w-4"></i>
          <span>Back to Home</span>
        </a>
      </div>
    </div>
  </header>

  <main>
    <!-- Login hero -->
    <section class="on-hot relative overflow-hidden bg-hot">
      <div class="pointer-events-none absolute -bottom-36 -left-24 h-80 w-80 rounded-full bg-grape" aria-hidden="true"></div>
      <div class="pointer-events-none absolute -top-32 right-[12%] hidden h-72 w-72 rounded-full border-[36px] border-white/10 lg:block" aria-hidden="true"></div>

      <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 md:py-14 lg:py-16">
        <div class="grid items-center gap-10 lg:grid-cols-12 lg:gap-14">

          <!-- Login card -->
          <div class="lg:col-span-7 rise">
            <div class="mb-5 flex items-center gap-2 text-sm font-semibold text-white/90">
              <a href="index.php" class="hover:text-white hover:underline underline-offset-4">Grievance Portal</a>
              <i data-lucide="chevron-right" class="h-4 w-4"></i>
              <span>Login</span>
            </div>

            <div class="login-card rounded-2xl bg-white p-5 sm:p-7 lg:p-8">
              <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
                <div>
                  <span class="role-badge inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-bold">
                    <i data-lucide="<?= e($role['icon']) ?>" class="h-4 w-4"></i>
                    <?= e($role['title']) ?>
                  </span>
                  <h1 class="font-display balance mt-4 text-3xl sm:text-4xl font-extrabold leading-tight tracking-tight text-ink">
                    Welcome back.
                  </h1>
                  <p class="mt-2 max-w-xl text-sm sm:text-base leading-relaxed text-slate-600">
                    Sign in securely to access your grievance dashboard.
                  </p>
                </div>

                <div class="hidden sm:flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-hot text-white border-2 border-ink shadow-[4px_4px_0_#FFC93C]">
                  <i data-lucide="<?= e($role['icon']) ?>" class="h-7 w-7"></i>
                </div>
              </div>

              <?php if (!empty($errors)): ?>
                <div class="mt-6 rounded-xl border-2 border-red-300 bg-red-50 px-4 py-3" role="alert">
                  <div class="flex items-start gap-3">
                    <i data-lucide="alert-circle" class="mt-0.5 h-5 w-5 shrink-0 text-red-600"></i>
                    <ul class="space-y-1 text-sm font-semibold text-red-700">
                      <?php foreach ($errors as $err): ?>
                        <li><?= e($err) ?></li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                </div>
              <?php endif; ?>

              <?php if ($dbError && empty($errors)): ?>
                <div class="mt-6 rounded-xl border-2 border-amber-300 bg-amber-50 px-4 py-3" role="alert">
                  <div class="flex items-start gap-3">
                    <i data-lucide="alert-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600"></i>
                    <p class="text-sm font-semibold text-amber-700"><?= e($dbError) ?></p>
                  </div>
                </div>
              <?php endif; ?>

              <form action="login.php?role=<?= e($roleKey) ?>" method="POST" class="mt-7 space-y-5" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />
                <input type="hidden" name="role" value="<?= e($roleKey) ?>" />

                <div>
                  <label for="username" class="mb-2 block text-sm font-bold text-ink">
                    <?= e($role['label']) ?>
                  </label>
                  <div class="relative">
                    <i data-lucide="user" class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400"></i>
                    <input id="username" name="username" type="text"
                           value="<?= e($loginInput) ?>"
                           placeholder="<?= e($role['placeholder']) ?>"
                           required autocomplete="username"
                           class="field w-full rounded-xl border-2 py-3.5 pl-12 pr-4 text-sm font-medium text-slate-900 placeholder-slate-400 sm:text-base" />
                  </div>
                </div>

                <div>
                  <div class="mb-2 flex items-center justify-between gap-3">
                    <label for="password" class="block text-sm font-bold text-ink">Password</label>
                    <a href="forgot-password.php?role=<?= e($roleKey) ?>"
                       class="text-xs font-bold text-hot transition hover:text-hotdark hover:underline underline-offset-4 sm:text-sm">
                      Forgotten password?
                    </a>
                  </div>
                  <div class="relative">
                    <i data-lucide="lock" class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400"></i>
                    <input id="password" name="password" type="password"
                           placeholder="Enter your password"
                           required autocomplete="current-password"
                           class="field w-full rounded-xl border-2 py-3.5 pl-12 pr-12 text-sm font-medium text-slate-900 placeholder-slate-400 sm:text-base" />
                    <button type="button" id="togglePassword" aria-label="Show password"
                            class="absolute right-0 top-0 flex h-full w-12 items-center justify-center text-slate-400 transition hover:text-hot">
                      <i data-lucide="eye" id="eyeIcon" class="h-5 w-5"></i>
                    </button>
                  </div>
                </div>

                <button type="submit"
                        class="btn-hard inline-flex w-full cursor-pointer items-center justify-center gap-2.5 rounded-xl bg-sun px-6 py-3.5 text-base font-extrabold text-ink hover:bg-[#FFD35C]">
                  <span>Log in securely</span>
                  <i data-lucide="arrow-right" class="h-5 w-5"></i>
                </button>
              </form>

              <?php if ($canRegister && !empty($role['register_page'])): ?>
                <div class="mt-7 border-t-2 border-slate-100 pt-6">
                  <p class="text-center text-sm text-slate-600">Don't have an account yet?</p>
                  <a href="<?= e($role['register_page']) ?>?role=<?= e($roleKey) ?>"
                     class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-xl border-2 border-ink bg-white px-5 py-3 text-sm font-bold text-ink transition hover:bg-blush hover:text-hot">
                    <i data-lucide="user-plus" class="h-4 w-4"></i>
                    <span>Create an account</span>
                    <i data-lucide="arrow-right" class="h-4 w-4"></i>
                  </a>
                  <p class="mt-3 flex items-center justify-center gap-1.5 text-[11px] font-medium text-slate-400">
                    <i data-lucide="shield-check" class="h-3.5 w-3.5"></i>
                    Approval is required before portal access.
                  </p>
                </div>
              <?php endif; ?>

              <div class="mt-6 flex items-center justify-center gap-2 border-t-2 border-slate-100 pt-5 text-xs font-semibold text-slate-500 sm:text-sm">
                <i data-lucide="shield-check" class="h-4 w-4 text-leaf"></i>
                <span><?= e($role['notice']) ?></span>
              </div>
            </div>
          </div>

          <!-- Brand / information side -->
          <div class="lg:col-span-5 rise rise-2">
            <div class="text-white">
              <div class="flex items-center gap-3">
                <div class="rounded-xl bg-white p-2.5 border-2 border-ink shadow-[4px_4px_0_#FFC93C]">
                  <img src="public/rcss-logo.png" alt="Rajagiri College of Social Sciences" class="h-12 w-auto" />
                </div>
                <div>
                  <p class="font-display text-xl font-extrabold leading-tight">Rajagiri College<br class="hidden sm:block"> of Social Sciences</p>
                  <p class="mt-1 text-xs font-semibold text-white/80">Oréll Grievance Redressal Portal</p>
                </div>
              </div>

              <div class="mt-8">
                <p class="text-sm font-bold uppercase tracking-[0.16em] text-sun">Secure access</p>
                <h2 class="font-display balance mt-3 text-3xl sm:text-4xl font-extrabold leading-tight tracking-tight">
                  Your voice deserves a clear path to resolution.
                </h2>
                <p class="mt-4 max-w-xl text-base leading-relaxed text-white/90">
                  Use your dedicated portal to submit, monitor, and manage grievance-related information securely.
                </p>
              </div>

              <div class="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                <div class="info-card rounded-xl p-4 text-white">
                  <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-hot border-2 border-ink text-white">
                      <i data-lucide="lock-keyhole" class="h-5 w-5"></i>
                    </span>
                    <div>
                      <h3 class="font-display font-bold">Secure authentication</h3>
                      <p class="mt-1 text-sm leading-relaxed text-white/85">Your login credentials are handled through the existing secure authentication flow.</p>
                    </div>
                  </div>
                </div>

                <div class="info-card rounded-xl p-4 text-white">
                  <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-leaf border-2 border-ink text-white">
                      <i data-lucide="route" class="h-5 w-5"></i>
                    </span>
                    <div>
                      <h3 class="font-display font-bold">Role-based access</h3>
                      <p class="mt-1 text-sm leading-relaxed text-white/85">Continue to the dashboard assigned to your authenticated portal role.</p>
                    </div>
                  </div>
                </div>
              </div>

              <div class="mt-7 flex items-start gap-3 border-t border-white/20 pt-5">
                <i data-lucide="mail" class="mt-0.5 h-5 w-5 shrink-0 text-sun"></i>
                <div>
                  <p class="text-xs font-bold uppercase tracking-wider text-white/60"><?= e($role['supportTag']) ?></p>
                  <a href="mailto:<?= e($role['supportMail']) ?>" class="mt-1 block text-sm font-semibold hover:text-sun hover:underline underline-offset-4 break-all">
                    <?= e($role['supportMail']) ?>
                  </a>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>
    </section>

    <!-- Footer -->
    <footer class="on-ink bg-ink border-t-[6px] border-sun py-6 text-white">
      <div class="max-w-7xl mx-auto flex flex-col gap-3 px-4 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:px-8">
        <div class="flex items-center gap-3">
          <span class="inline-flex items-center gap-3 rounded-xl bg-white px-3 py-2">
            <img src="public/rcss-logo.png" alt="RCSS Logo" class="h-8 w-auto" />
            <svg class="h-7 w-auto" width="128" height="40" viewBox="0 0 128 40" role="img" aria-label="Oréll Grievance"><use href="#orel-logo"></use></svg>
          </span>
        </div>
        <p class="text-xs text-slate-400">&copy; <?= date('Y') ?> <span class="font-bold text-white">Rajagiri College of Social Sciences</span>. All rights reserved.</p>
      </div>
    </footer>
  </main>

  <script>
    if (typeof lucide !== 'undefined') {
      lucide.createIcons();
    }

    (function () {
      const toggleBtn = document.getElementById('togglePassword');
      const pwdInput = document.getElementById('password');
      const eyeIcon = document.getElementById('eyeIcon');

      if (!toggleBtn || !pwdInput || !eyeIcon) return;

      toggleBtn.addEventListener('click', function () {
        const isHidden = pwdInput.type === 'password';
        pwdInput.type = isHidden ? 'text' : 'password';
        toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        eyeIcon.setAttribute('data-lucide', isHidden ? 'eye-off' : 'eye');

        if (typeof lucide !== 'undefined') {
          lucide.createIcons({ targets: [eyeIcon] });
        }
      });
    })();

    window.scrollTo(0, 0);
  </script>

  <script src="assets/js/index.js"></script>
</body>
</html>