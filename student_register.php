<?php
/**
 * student_register.php
 * ---------------------------------------------------------------------------
 * Student Self-Registration
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

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Throwable $ex) {
        $_SESSION['csrf_token'] = md5(uniqid((string) mt_rand(), true));
    }
}
$csrfToken = (string) $_SESSION['csrf_token'];

$classOptions = [];

if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, class_name FROM classes WHERE status = 'Active' ORDER BY class_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $classOptions[] = $row;
            }
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Classes] ' . $ex->getMessage());
    }
}

$errors  = [];
$success = false;

$formData = [
    'name'             => '',
    'gender'           => '',
    'admission_number' => '',
    'class_id'         => '',
    'email'            => '',
    'contact_number'   => '',
    'guardian_name'    => '',
    'password'         => '',
    'address'          => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';
    if ($submittedToken === '' || !hash_equals($csrfToken, (string) $submittedToken)) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    }

    $formData['name']             = trim((string) ($_POST['name']             ?? ''));
    $formData['gender']           = trim((string) ($_POST['gender']           ?? ''));
    $formData['admission_number'] = trim((string) ($_POST['admission_number'] ?? ''));
    $formData['class_id']         = trim((string) ($_POST['class_id']         ?? ''));
    $formData['email']            = trim((string) ($_POST['email']            ?? ''));
    $formData['contact_number']   = trim((string) ($_POST['contact_number']   ?? ''));
    $formData['guardian_name']    = trim((string) ($_POST['guardian_name']    ?? ''));
    $formData['password']         = (string)       ($_POST['password']         ?? '');
    $formData['address']          = trim((string) ($_POST['address']          ?? ''));

    if ($formData['name'] === '') $errors[] = 'Student Name is required.';
    if (!in_array($formData['gender'], ['Male', 'Female', 'Other'], true)) $errors[] = 'Please select a valid Gender.';
    if ($formData['admission_number'] === '') $errors[] = 'Admission Number is required.';
    if ($formData['class_id'] === '' || !ctype_digit($formData['class_id'])) $errors[] = 'Please select a valid Class/Semester.';
    if ($formData['email'] === '') $errors[] = 'Email is required.';
    elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if ($formData['contact_number'] === '') $errors[] = 'Contact Number is required.';
    elseif (!preg_match('/^[0-9]{10}$/', $formData['contact_number'])) $errors[] = 'Contact Number must be exactly 10 digits.';
    if ($formData['guardian_name'] === '') $errors[] = 'Guardian Name is required.';
    if ($formData['password'] === '') $errors[] = 'Password is required.';
    elseif (strlen($formData['password']) < 6) $errors[] = 'Password must be at least 6 characters long.';

    if (empty($errors) && $conn instanceof mysqli) {
        try {
            $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            if ($chk) {
                $chk->bind_param('s', $formData['email']);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $errors[] = 'This email is already registered. Please log in or use a different email.';
                }
                $chk->close();
            }

            $chk2 = $conn->prepare("SELECT id FROM students WHERE email = ? LIMIT 1");
            if ($chk2) {
                $chk2->bind_param('s', $formData['email']);
                $chk2->execute();
                if ($chk2->get_result()->num_rows > 0) {
                    $errors[] = 'This email is already in use by another student record.';
                }
                $chk2->close();
            }

            $chk3 = $conn->prepare("SELECT id FROM students WHERE admission_number = ? LIMIT 1");
            if ($chk3) {
                $chk3->bind_param('s', $formData['admission_number']);
                $chk3->execute();
                if ($chk3->get_result()->num_rows > 0) {
                    $errors[] = 'This Admission Number is already registered.';
                }
                $chk3->close();
            }
        } catch (Throwable $ex) {
            error_log('[Duplicate Check] ' . $ex->getMessage());
            $errors[] = 'A system error occurred while validating your details.';
        }
    }

    if (empty($errors) && $conn instanceof mysqli) {
        try {
            $conn->begin_transaction();

            $classIdInt = (int) $formData['class_id'];

            $chkClass = $conn->prepare("SELECT id FROM classes WHERE id = ? AND status = 'Active' LIMIT 1");
            if ($chkClass) {
                $chkClass->bind_param('i', $classIdInt);
                $chkClass->execute();
                if ($chkClass->get_result()->num_rows === 0) {
                    $chkClass->close();
                    throw new Exception('The selected Class/Semester is not available.');
                }
                $chkClass->close();
            }

            $hash = password_hash($formData['password'], PASSWORD_BCRYPT);
            $role = 'STUDENT';

            $stmtU = $conn->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, ?, 'Pending')");
            if (!$stmtU) {
                throw new Exception('Failed to prepare user insert.');
            }
            $stmtU->bind_param('sss', $formData['email'], $hash, $role);
            $stmtU->execute();
            $newUserId = (int) $conn->insert_id;
            $stmtU->close();

            $stmtS = $conn->prepare("INSERT INTO students
                                        (user_id, class_id, name, admission_number, email, contact_number, guardian_name, address)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmtS) {
                throw new Exception('Failed to prepare student insert.');
            }
            $stmtS->bind_param(
                'iissssss',
                $newUserId,
                $classIdInt,
                $formData['name'],
                $formData['admission_number'],
                $formData['email'],
                $formData['contact_number'],
                $formData['guardian_name'],
                $formData['address']
            );
            $stmtS->execute();
            $stmtS->close();

            $conn->commit();

            $_SESSION['flash_success'] = 'Your registration request has been submitted! Please wait for admin approval before logging in.';

            header('Location: login.php?role=student&registered=success');
            exit;

        } catch (Throwable $ex) {
            if ($conn instanceof mysqli) $conn->rollback();
            error_log('[Student Register] ' . $ex->getMessage());
            $errors[] = $ex->getMessage() ?: 'A system error occurred while creating your account. Please try again.';
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Student registration — Rajagiri College Grievance Redressal Portal." />
  <meta name="theme-color" content="#DB0878" />
  <title>Student Registration — Rajagiri College of Social Sciences</title>
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

  <link rel="stylesheet" href="assets/css/index.css" />

  <style>
    html { scroll-behavior: smooth; }
    a:focus-visible, button:focus-visible, input:focus-visible, textarea:focus-visible, select:focus-visible {
      outline: 3px solid #1F0A24; outline-offset: 3px;
    }
    .on-ink a:focus-visible, .on-ink button:focus-visible { outline-color: #fff; }
    .balance { text-wrap: balance; }

    .btn-hard {
      border: 2px solid #1F0A24;
      box-shadow: 4px 4px 0 #1F0A24;
      transition: transform .12s ease, box-shadow .12s ease, background-color .15s ease;
    }
    .btn-hard:hover { transform: translate(2px, 2px); box-shadow: 2px 2px 0 #1F0A24; }
    .btn-hard:active { transform: translate(4px, 4px); box-shadow: 0 0 0 #1F0A24; }

    .card-hard { border: 2px solid #1F0A24; box-shadow: 6px 6px 0 #FFC93C; }

    .info-card {
      border: 2px solid #1F0A24;
      box-shadow: 4px 4px 0 #FFC93C;
      transition: transform .15s ease, box-shadow .15s ease;
      background: #fff;
    }
    .info-card:hover { transform: translate(-2px, -2px); box-shadow: 6px 6px 0 #FFC93C; }

    .field {
      width: 100%;
      border: 2px solid #1F0A24;
      border-radius: 12px;
      padding: .82rem 1rem;
      background: #fff;
      color: #1F0A24;
      font-weight: 600;
      outline: none;
      transition: box-shadow .15s ease;
    }
    .field::placeholder { color: #94a3b8; }
    .field:focus { box-shadow: 0 0 0 4px rgba(219, 8, 120, .10); }

    .label { display: block; font-size: .82rem; font-weight: 700; margin-bottom: .45rem; color: #1F0A24; }

    @keyframes rise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
    .rise { animation: rise .55s cubic-bezier(.2,.7,.2,1) both; }
    .rise-2 { animation-delay: .12s; }

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
    ::-webkit-scrollbar-thumb { background: #db0878; border-radius: 6px; border: 2px solid #fff0f7; }
    ::-webkit-scrollbar-thumb:hover { background: #b70664; }
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

  <div class="fixed top-0 left-0 right-0 z-[60] flex h-1.5" aria-hidden="true">
    <span class="flex-1 bg-hot"></span>
    <span class="flex-1 bg-sun"></span>
    <span class="flex-1 bg-leaf"></span>
    <span class="flex-1 bg-grape"></span>
  </div>

  <header class="sticky top-0 z-50 border-b border-slate-200 bg-white/95 backdrop-blur-md">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex min-h-16 md:min-h-20 items-center justify-between gap-4">
        <a href="index.php" class="flex items-center gap-3 sm:gap-4 rounded-lg">
          <img src="public/rcss-logo.png" alt="RCSS Logo" class="h-9 md:h-11 w-auto" />
          <span class="hidden sm:block h-8 w-px bg-slate-200" aria-hidden="true"></span>
          <svg class="hidden sm:block h-9 md:h-10 w-auto" width="128" height="40" viewBox="0 0 128 40" role="img" aria-label="Oréll Grievance"><use href="#orel-logo"></use></svg>
        </a>

        <a href="login.php?role=student"
           class="inline-flex items-center gap-2 rounded-lg border-2 border-ink bg-white px-3.5 py-2 text-sm font-bold text-ink transition hover:bg-blush hover:text-hot">
          <i data-lucide="arrow-left" class="h-4 w-4"></i>
          <span>Back to Login</span>
        </a>
      </div>
    </div>
  </header>

  <section class="on-hot relative overflow-hidden bg-hot">
    <div class="pointer-events-none absolute -bottom-36 -left-24 h-80 w-80 rounded-full bg-grape" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -top-32 right-[12%] hidden h-72 w-72 rounded-full border-[36px] border-white/10 lg:block" aria-hidden="true"></div>

    <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 md:py-14">
      <div class="max-w-3xl rise text-white">
        <span class="inline-flex items-center gap-2 rounded-full bg-sun text-ink border-2 border-ink px-3 py-1.5 text-xs font-bold uppercase tracking-wider">
          <i data-lucide="graduation-cap" class="h-4 w-4"></i>
          Student Registration
        </span>
        <h1 class="font-display balance mt-4 text-3xl sm:text-4xl md:text-5xl font-extrabold leading-tight">
          Create your portal account.
        </h1>
        <p class="mt-3 text-white/85 text-base sm:text-lg max-w-2xl">
          Create your student account with your academic, contact and guardian information.
        </p>
      </div>
    </div>
  </section>

  <main id="main-content" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 md:py-14">
    <div class="grid lg:grid-cols-[1fr_320px] gap-8 items-start">
      <section class="card-hard rounded-2xl bg-white p-6 sm:p-8 md:p-10 rise">

        <div class="mb-8">
          <p class="text-xs font-extrabold uppercase tracking-[.18em] text-hot">Student account</p>
          <h2 class="font-display text-2xl sm:text-3xl font-extrabold text-ink mt-1">Register as a student</h2>
          <p class="text-sm text-slate-500 mt-2">Complete your academic and contact details to submit an account request.</p>
        </div>

        <?php if (!empty($errors)): ?>
          <div class="rounded-xl border-2 border-red-300 bg-red-50 px-4 py-3 mb-6 flex gap-3">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-600 shrink-0 mt-0.5"></i>
            <ul class="text-sm font-semibold text-red-700 space-y-1"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
          </div>
        <?php endif; ?>

        <?php if ($dbError && empty($errors)): ?>
          <div class="rounded-xl border-2 border-amber-300 bg-amber-50 px-4 py-3 mb-6 flex gap-3">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 shrink-0 mt-0.5"></i>
            <p class="text-sm font-semibold text-amber-700"><?= e($dbError) ?></p>
          </div>
        <?php endif; ?>

        <form method="POST" action="student_register.php" class="space-y-6" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

          <div class="grid md:grid-cols-2 gap-5">
            <div>
              <label class="label" for="name">Student Name <span class="text-hot">*</span></label>
              <input class="field" type="text" name="name" id="name" required value="<?= e($formData['name']) ?>" placeholder="Full name">
            </div>
            <div>
              <label class="label" for="gender">Gender <span class="text-hot">*</span></label>
              <select class="field" name="gender" id="gender" required>
                <option value="">Select gender</option>
                <option value="Male" <?= $formData['gender']==='Male'?'selected':'' ?>>Male</option>
                <option value="Female" <?= $formData['gender']==='Female'?'selected':'' ?>>Female</option>
                <option value="Other" <?= $formData['gender']==='Other'?'selected':'' ?>>Other</option>
              </select>
            </div>
          </div>

          <div class="grid md:grid-cols-2 gap-5">
            <div>
              <label class="label" for="admission_number">Admission Number <span class="text-hot">*</span></label>
              <input class="field" type="text" name="admission_number" id="admission_number" required value="<?= e($formData['admission_number']) ?>" placeholder="Registration / admission number">
            </div>
            <div>
              <label class="label" for="class_id">Class / Semester <span class="text-hot">*</span></label>
              <select class="field" name="class_id" id="class_id" required>
                <option value="">Select class / semester</option>
                <?php foreach ($classOptions as $opt): ?>
                  <option value="<?= (int) $opt['id'] ?>" <?= (string) $formData['class_id'] === (string) $opt['id'] ? 'selected' : '' ?>><?= e($opt['class_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="grid md:grid-cols-2 gap-5">
            <div>
              <label class="label" for="email">Email <span class="text-hot">*</span></label>
              <input class="field" type="email" name="email" id="email" required value="<?= e($formData['email']) ?>" placeholder="Email address">
              <p class="text-xs text-slate-500 mt-1.5">This email will be used as your username.</p>
            </div>
            <div>
              <label class="label" for="contact_number">Contact Number <span class="text-hot">*</span></label>
              <input class="field" type="tel" name="contact_number" id="contact_number" required inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10" value="<?= e($formData['contact_number']) ?>" placeholder="10-digit mobile number">
            </div>
          </div>

          <div class="grid md:grid-cols-2 gap-5">
            <div>
              <label class="label" for="guardian_name">Guardian Name <span class="text-hot">*</span></label>
              <input class="field" type="text" name="guardian_name" id="guardian_name" required value="<?= e($formData['guardian_name']) ?>" placeholder="Parent / guardian name">
            </div>
            <div>
              <label class="label" for="password">Password <span class="text-hot">*</span></label>
              <div class="relative">
                <input class="field pr-12" type="password" name="password" id="password" required minlength="6" placeholder="Create a password">
                <button type="button" id="togglePassword" aria-label="Toggle password visibility"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-hot rounded">
                  <i data-lucide="eye" id="eyeIcon" class="w-5 h-5"></i>
                </button>
              </div>
              <p class="text-xs text-slate-500 mt-1.5">Minimum 6 characters.</p>
            </div>
          </div>

          <div>
            <label class="label" for="address">Address</label>
            <textarea class="field resize-none" name="address" id="address" rows="5" placeholder="Residential address"><?= e($formData['address']) ?></textarea>
          </div>

          <div class="pt-5 border-t-2 border-slate-100 flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
            <a href="login.php?role=student"
               class="inline-flex items-center justify-center gap-2 rounded-xl bg-white border-2 border-ink px-6 py-3 text-sm font-bold text-ink hover:bg-blush transition-colors">
              Cancel
            </a>
            <button type="submit"
                    class="btn-hard inline-flex items-center justify-center gap-2 rounded-xl bg-hot px-8 py-3 text-sm font-bold text-white hover:bg-hotdark">
              <i data-lucide="user-plus" class="w-4 h-4"></i>
              <span>Create Student Account</span>
            </button>
          </div>
        </form>

        <p class="text-center text-sm text-slate-500 mt-6">
          Already registered?
          <a href="login.php?role=student" class="font-extrabold text-hot hover:text-grape underline-offset-4 hover:underline">Log in here</a>
        </p>
      </section>

      <aside class="space-y-4 rise rise-2">
        <div class="card-hard rounded-2xl bg-white p-5">
          <span class="w-11 h-11 rounded-xl bg-hot border-2 border-ink text-white flex items-center justify-center mb-4">
            <i data-lucide="shield-check" class="w-5 h-5"></i>
          </span>
          <h2 class="font-display text-xl font-extrabold text-ink">Secure registration</h2>
          <p class="text-sm text-slate-600 mt-2 leading-6">Your details are submitted securely and your account remains pending until the required approval is completed.</p>
        </div>

        <div class="info-card rounded-2xl p-4">
          <div class="flex gap-3">
            <i data-lucide="circle-help" class="w-5 h-5 text-leaf mt-0.5 shrink-0"></i>
            <div>
              <h3 class="font-display font-bold text-ink">Need help?</h3>
              <p class="text-sm text-slate-600 mt-1">Use your registered email to access the portal after approval.</p>
            </div>
          </div>
        </div>

        <div class="on-ink rounded-2xl border-2 border-ink bg-ink text-white p-5">
          <div class="inline-flex items-center gap-3 rounded-xl bg-white px-3 py-2 mb-3">
            <img src="public/rcss-logo.png" alt="RCSS Logo" class="h-8 w-auto" />
            <svg class="h-7 w-auto" width="128" height="40" viewBox="0 0 128 40" role="img" aria-label="Oréll Grievance"><use href="#orel-logo"></use></svg>
          </div>
          <p class="text-sm text-white/80 leading-6">Rajagiri College of Social Sciences<br>Grievance Redressal Portal</p>
        </div>
      </aside>
    </div>
  </main>

  <footer class="on-ink bg-ink border-t-[6px] border-sun mt-4">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-7 flex flex-col sm:flex-row items-center justify-between gap-4">
      <div class="flex items-center gap-3">
        <span class="inline-flex items-center gap-3 rounded-xl bg-white px-3 py-2">
          <img src="public/rcss-logo.png" alt="RCSS Logo" class="h-8 w-auto" />
          <svg class="h-7 w-auto" width="128" height="40" viewBox="0 0 128 40" role="img" aria-label="Oréll Grievance"><use href="#orel-logo"></use></svg>
        </span>
      </div>
      <p class="text-xs text-slate-400">Grievance Redressal Portal · <span class="font-bold text-sun">Oréll Grievance</span></p>
    </div>
  </footer>

  <script>
    if (typeof lucide !== 'undefined') lucide.createIcons();

    (function(){
      const b=document.getElementById('togglePassword'),p=document.getElementById('password'),i=document.getElementById('eyeIcon');
      if(!b)return;
      b.addEventListener('click',()=>{const h=p.type==='password';p.type=h?'text':'password';i.setAttribute('data-lucide',h?'eye-off':'eye');lucide.createIcons({targets:[i]})})
    })();
    (function(){const m=document.getElementById('contact_number');if(m)m.addEventListener('input',function(){this.value=this.value.replace(/\D/g,'').slice(0,10)})})();
  </script>

  <script src="assets/js/index.js"></script>
</body>
</html>
