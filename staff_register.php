<?php
/**
 * staff_register.php
 * ---------------------------------------------------------------------------
 * Staff Self-Registration
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

$departments  = [];
$designations = [];

if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, department_name FROM departments WHERE status = 'Active' ORDER BY department_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $departments[] = $row;
            }
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Departments] ' . $ex->getMessage());
    }

    try {
        $res = $conn->query("SELECT id, designation_name FROM designations WHERE status = 'Active' ORDER BY designation_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $designations[] = $row;
            }
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Designations] ' . $ex->getMessage());
    }
}

$errors = [];

$formData = [
    'name'           => '',
    'gender'         => '',
    'department_id'  => '',
    'designation_id' => '',
    'email'          => '',
    'contact_number' => '',
    'employee_id'    => '',
    'password'       => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($submittedToken === '' || !hash_equals($csrfToken, $submittedToken)) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    }

    $formData['name']           = trim((string) ($_POST['name']           ?? ''));
    $formData['gender']         = trim((string) ($_POST['gender']         ?? ''));
    $formData['department_id']  = trim((string) ($_POST['department_id']  ?? ''));
    $formData['designation_id'] = trim((string) ($_POST['designation_id'] ?? ''));
    $formData['email']          = trim((string) ($_POST['email']          ?? ''));
    $formData['contact_number'] = trim((string) ($_POST['contact_number'] ?? ''));
    $formData['employee_id']    = trim((string) ($_POST['employee_id']    ?? ''));
    $formData['password']       = (string)       ($_POST['password']       ?? '');

    if ($formData['name'] === '') $errors[] = 'Staff Name is required.';
    if (!in_array($formData['gender'], ['Male', 'Female', 'Other'], true)) $errors[] = 'Please select a valid Gender.';
    if ($formData['department_id'] === '' || !ctype_digit($formData['department_id'])) $errors[] = 'Please select a Department.';
    if ($formData['designation_id'] === '' || !ctype_digit($formData['designation_id'])) $errors[] = 'Please select a Designation.';
    if ($formData['email'] === '') $errors[] = 'Email is required.';
    elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if ($formData['contact_number'] === '') $errors[] = 'Contact Number is required.';
    elseif (!preg_match('/^[0-9]{10}$/', $formData['contact_number'])) $errors[] = 'Contact Number must be exactly 10 digits.';
    if ($formData['password'] === '') $errors[] = 'Password is required.';
    elseif (strlen($formData['password']) < 6) $errors[] = 'Password must be at least 6 characters long.';

    if (empty($errors) && $conn instanceof mysqli) {
        try {
            $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            if ($chk) {
                $chk->bind_param('s', $formData['name']);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $errors[] = 'This Staff Name is already registered as a username. Please use a different name or contact the administrator.';
                }
                $chk->close();
            }

            $chk2 = $conn->prepare("SELECT id FROM staff WHERE email = ? LIMIT 1");
            if ($chk2) {
                $chk2->bind_param('s', $formData['email']);
                $chk2->execute();
                if ($chk2->get_result()->num_rows > 0) {
                    $errors[] = 'This email is already in use by another staff member.';
                }
                $chk2->close();
            }

            if ($formData['employee_id'] !== '') {
                $chk3 = $conn->prepare("SELECT id FROM staff WHERE employee_id = ? LIMIT 1");
                if ($chk3) {
                    $chk3->bind_param('s', $formData['employee_id']);
                    $chk3->execute();
                    if ($chk3->get_result()->num_rows > 0) {
                        $errors[] = 'This Employee ID is already registered.';
                    }
                    $chk3->close();
                }
            }
        } catch (Throwable $ex) {
            error_log('[Staff Duplicate Check] ' . $ex->getMessage());
            $errors[] = 'A system error occurred while validating your details.';
        }
    }

    if (empty($errors) && $conn instanceof mysqli) {
        try {
            $conn->begin_transaction();

            $departmentId  = (int) $formData['department_id'];
            $designationId = (int) $formData['designation_id'];

            $chkDept = $conn->prepare("SELECT id FROM departments WHERE id = ? AND status = 'Active' LIMIT 1");
            if ($chkDept) {
                $chkDept->bind_param('i', $departmentId);
                $chkDept->execute();
                if ($chkDept->get_result()->num_rows === 0) {
                    $chkDept->close();
                    throw new Exception('The selected Department is not available.');
                }
                $chkDept->close();
            }

            $chkDesig = $conn->prepare("SELECT id, post_occupied FROM designations WHERE id = ? AND status = 'Active' LIMIT 1");
            if (!$chkDesig) {
                throw new Exception('Unable to verify designation.');
            }
            $chkDesig->bind_param('i', $designationId);
            $chkDesig->execute();
            $desigRes = $chkDesig->get_result();

            if ($desigRes->num_rows === 0) {
                $chkDesig->close();
                throw new Exception('The selected Designation is not available.');
            }

            $desigRow     = $desigRes->fetch_assoc();
            $postOccupied = (string) ($desigRow['post_occupied'] ?? 'Teaching');
            $chkDesig->close();

            if ($postOccupied === 'Teaching') {
                $userRole   = 'TEACHER';
                $staffType  = 'TEACHING';
            } else {
                $userRole   = 'NON_TEACHING';
                $staffType  = 'NON_TEACHING';
            }

            $hash = password_hash($formData['password'], PASSWORD_BCRYPT);

            $stmtU = $conn->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, ?, 'Pending')");
            if (!$stmtU) {
                throw new Exception('Failed to prepare user insert.');
            }
            $stmtU->bind_param('sss', $formData['name'], $hash, $userRole);
            $stmtU->execute();
            $newUserId = (int) $conn->insert_id;
            $stmtU->close();

            $employeeId = ($formData['employee_id'] !== '') ? $formData['employee_id'] : null;

            $stmtS = $conn->prepare("INSERT INTO staff
                                        (user_id, name, gender, email, contact_number,
                                         employee_id, department_id, designation_id, staff_type)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmtS) {
                throw new Exception('Failed to prepare staff insert.');
            }

            $stmtS->bind_param(
                'isssssiis',
                $newUserId,
                $formData['name'],
                $formData['gender'],
                $formData['email'],
                $formData['contact_number'],
                $employeeId,
                $departmentId,
                $designationId,
                $staffType
            );
            $stmtS->execute();
            $stmtS->close();

            $conn->commit();

            $_SESSION['flash_success'] = 'Your registration request has been submitted! Please wait for admin approval before logging in.';
            header('Location: login.php?role=staff&registered=success');
            exit;

        } catch (Throwable $ex) {
            if ($conn instanceof mysqli) $conn->rollback();
            error_log('[Staff Register] ' . $ex->getMessage());
            $errors[] = $ex->getMessage() ?: 'A system error occurred while creating your account. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Staff registration — Rajagiri College Grievance Redressal Portal." />
  <meta name="theme-color" content="#DB0878" />
  <title>Staff Registration — Rajagiri College of Social Sciences</title>
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

  <link rel="stylesheet" href="assets/css/index.css" />

  <style>
    html { scroll-behavior: smooth; }
    a:focus-visible, button:focus-visible, input:focus-visible, textarea:focus-visible, select:focus-visible {
      outline: 3px solid #1F0A24; outline-offset: 3px;
    }
    .on-hot a:focus-visible, .on-hot button:focus-visible { outline-color: #fff; }
    .on-ink a:focus-visible, .on-ink button:focus-visible { outline-color: #fff; }
    .balance { text-wrap: balance; }

    .btn-hard {
      border: 2px solid #1F0A24;
      box-shadow: 4px 4px 0 #1F0A24;
      transition: transform .12s ease, box-shadow .12s ease, background-color .15s ease;
    }
    .btn-hard:hover  { transform: translate(2px, 2px); box-shadow: 2px 2px 0 #1F0A24; }
    .btn-hard:active { transform: translate(4px, 4px); box-shadow: 0 0 0 #1F0A24; }

    .card-hard { border: 2px solid #1F0A24; box-shadow: 6px 6px 0 #FFC93C; }

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

        <a href="login.php?role=staff"
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
          <i data-lucide="briefcase" class="h-4 w-4"></i>
          Staff Registration
        </span>
        <h1 class="font-display balance mt-4 text-3xl sm:text-4xl md:text-5xl font-extrabold leading-tight">
          Create your portal account.
        </h1>
        <p class="mt-3 text-white/85 text-base sm:text-lg max-w-2xl">
          Register as teaching or non-teaching staff and get access to the grievance portal after admin approval.
        </p>
      </div>
    </div>
  </section>

  <main id="main-content" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 md:py-14">
    <div class="grid lg:grid-cols-[1fr_320px] gap-8 items-start">
      <section class="card-hard rounded-2xl bg-white p-6 sm:p-8 md:p-10 rise">

        <div class="mb-8">
          <p class="text-xs font-extrabold uppercase tracking-[.18em] text-hot">Staff account</p>
          <h2 class="font-display text-2xl sm:text-3xl font-extrabold text-ink mt-1">Register as staff</h2>
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

        <form method="POST" action="staff_register.php" class="space-y-6" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>" />

          <!-- Row 1: Staff Name + Gender -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
              <label for="name" class="label">Staff Name <span class="text-hot">*</span></label>
              <input class="field" type="text" name="name" id="name" required
                     value="<?= e($formData['name']) ?>" placeholder="Staff Name" />
              <p class="text-xs text-hot font-bold mt-1.5">Staff Name will be used as username</p>
            </div>

            <div>
              <label for="gender" class="label">Gender <span class="text-hot">*</span></label>
              <select class="field" name="gender" id="gender" required>
                <option value="">Gender</option>
                <option value="Male"   <?= $formData['gender'] === 'Male'   ? 'selected' : '' ?>>Male</option>
                <option value="Female" <?= $formData['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                <option value="Other"  <?= $formData['gender'] === 'Other'  ? 'selected' : '' ?>>Other</option>
              </select>
            </div>
          </div>

          <!-- Row 2: Department + Designation -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
              <label for="department_id" class="label">Department <span class="text-hot">*</span></label>
              <select class="field" name="department_id" id="department_id" required>
                <option value="">SELECT</option>
                <?php foreach ($departments as $dept): ?>
                  <option value="<?= (int) $dept['id'] ?>" <?= (string) $formData['department_id'] === (string) $dept['id'] ? 'selected' : '' ?>>
                    <?= e($dept['department_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label for="designation_id" class="label">Designation <span class="text-hot">*</span></label>
              <select class="field" name="designation_id" id="designation_id" required>
                <option value="">SELECT</option>
                <?php foreach ($designations as $desig): ?>
                  <option value="<?= (int) $desig['id'] ?>" <?= (string) $formData['designation_id'] === (string) $desig['id'] ? 'selected' : '' ?>>
                    <?= e($desig['designation_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Row 3: Email + Contact Number -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
              <label for="email" class="label">Email <span class="text-hot">*</span></label>
              <input class="field" type="email" name="email" id="email" required
                     value="<?= e($formData['email']) ?>" placeholder="Email" />
            </div>

            <div>
              <label for="contact_number" class="label">Contact Number <span class="text-hot">*</span></label>
              <input class="field" type="tel" name="contact_number" id="contact_number" required
                     inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10"
                     value="<?= e($formData['contact_number']) ?>" placeholder="Contact Number" />
            </div>
          </div>

          <!-- Row 4: Employee Id + Password -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
              <label for="employee_id" class="label">Employee Id</label>
              <input class="field" type="text" name="employee_id" id="employee_id"
                     value="<?= e($formData['employee_id']) ?>" placeholder="Employee Id" />
            </div>

            <div>
              <label for="password" class="label">Password <span class="text-hot">*</span></label>
              <div class="relative">
                <input class="field pr-12" type="password" name="password" id="password" required minlength="6" placeholder="Password" />
                <button type="button" id="togglePassword" aria-label="Toggle password visibility"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-hot rounded">
                  <i data-lucide="eye" id="eyeIcon" class="w-5 h-5"></i>
                </button>
              </div>
              <p class="text-xs text-slate-500 mt-1.5">Minimum 6 characters.</p>
            </div>
          </div>

          <!-- Actions -->
          <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-3 pt-5 border-t-2 border-slate-100">
            <a href="login.php?role=staff"
               class="inline-flex items-center justify-center gap-2 rounded-xl bg-white border-2 border-ink px-6 py-3 text-sm font-bold text-ink hover:bg-blush transition-colors">
              Cancel
            </a>
            <button type="submit"
                    class="btn-hard inline-flex items-center justify-center gap-2 rounded-xl bg-hot px-8 py-3 text-sm font-bold text-white hover:bg-hotdark">
              <i data-lucide="send" class="w-4 h-4"></i>
              <span>Submit</span>
            </button>
          </div>

        </form>
      </section>

      <aside class="space-y-4 rise rise-2">
        <div class="card-hard rounded-2xl bg-white p-5">
          <span class="w-11 h-11 rounded-xl bg-hot border-2 border-ink text-white flex items-center justify-center mb-4">
            <i data-lucide="shield-check" class="w-5 h-5"></i>
          </span>
          <h2 class="font-display text-xl font-extrabold text-ink">Secure registration</h2>
          <p class="text-sm text-slate-600 mt-2 leading-6">Your details are submitted securely and your account remains pending until the required approval is completed.</p>
        </div>

        <div class="rounded-2xl border-2 border-ink bg-white p-4 shadow-[4px_4px_0_#FFC93C]">
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
    if (typeof lucide !== 'undefined') {
      lucide.createIcons();
    }

    // ---- Toggle password visibility ----
    (function () {
      const btn     = document.getElementById('togglePassword');
      const pwd     = document.getElementById('password');
      const eyeIcon = document.getElementById('eyeIcon');
      if (!btn || !pwd || !eyeIcon) return;

      btn.addEventListener('click', function () {
        const isHidden = pwd.type === 'password';
        pwd.type = isHidden ? 'text' : 'password';
        eyeIcon.setAttribute('data-lucide', isHidden ? 'eye-off' : 'eye');
        if (typeof lucide !== 'undefined') {
          lucide.createIcons({ targets: [eyeIcon] });
        }
      });
    })();

    // ---- Mobile number: only 10 digits ----
    (function () {
      const mobile = document.getElementById('contact_number');
      if (!mobile) return;
      mobile.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 10);
      });
    })();
  </script>

  <script src="assets/js/index.js"></script>
</body>
</html>