<?php
/**
 * parent_register.php
 * ---------------------------------------------------------------------------
 * Parent Registration — Rajagiri College Grievance Redressal Portal
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

if (!empty($_SESSION['user_id']) && isset($_SESSION['role']) && strtoupper((string) $_SESSION['role']) === 'PARENT') {
    header('Location: parent/dashboard.php');
    exit;
}

$dbFile = __DIR__ . '/db_connect.php';

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

function jsonResponse(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Throwable $ex) {
        $_SESSION['csrf_token'] = md5(uniqid((string) mt_rand(), true));
    }
}
$csrfToken = (string) $_SESSION['csrf_token'];

$allowedRelations = ['Father', 'Mother', 'Guardian'];

if (($_GET['action'] ?? '') === 'check_student') {

    if ($conn === null) {
        jsonResponse([
            'success' => false,
            'message' => $dbError ?: 'Database is unavailable.',
        ]);
    }

    $admissionNumber = trim((string) ($_POST['admission_number'] ?? $_GET['admission_number'] ?? ''));

    if ($admissionNumber === '') {
        jsonResponse([
            'success' => false,
            'message' => 'Please enter an Admission Number.',
        ]);
    }

    try {
        $sql = "SELECT  s.id            AS student_id,
                        s.name          AS student_name,
                        s.admission_number,
                        s.parent_id     AS existing_parent_id,
                        c.class_name    AS class_name,
                        co.course_name  AS course_name
                FROM students s
                LEFT JOIN classes c  ON c.id = s.class_id
                LEFT JOIN courses co ON co.id = c.course_id
                WHERE s.admission_number = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception('Query preparation failed: ' . $conn->error);
        }

        $stmt->bind_param('s', $admissionNumber);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 0) {
            $stmt->close();
            jsonResponse([
                'success' => false,
                'message' => 'Student not found with this Admission Number.',
            ]);
        }

        $student = $res->fetch_assoc();
        $stmt->close();

        if (!empty($student['existing_parent_id'])) {
            jsonResponse([
                'success' => false,
                'message' => 'A parent account is already linked to this student.',
            ]);
        }

        jsonResponse([
            'success'    => true,
            'student_id' => (int) $student['student_id'],
            'name'       => (string) ($student['student_name'] ?? ''),
            'course'     => (string) ($student['course_name']  ?? ''),
            'class'      => (string) ($student['class_name']   ?? ''),
        ]);

    } catch (Throwable $ex) {
        error_log('[Parent Reg - Check Student] ' . $ex->getMessage());
        jsonResponse([
            'success' => false,
            'message' => 'A system error occurred while looking up the student.',
        ]);
    }
}

$formErrors  = [];
$formSuccess = '';
$formData    = [
    'admission_number' => '',
    'student_id'       => 0,
    'student_name'     => '',
    'course'           => '',
    'class'            => '',
    'name'             => '',
    'email'            => '',
    'contact_number'   => '',
    'relation'         => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register_parent') {

    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($submittedToken === '' || !hash_equals($csrfToken, $submittedToken)) {
        $formErrors[] = 'Security token expired. Please refresh the page and try again.';
    }

    $admissionNumber = trim((string) ($_POST['admission_number'] ?? ''));
    $studentId       = (int) ($_POST['student_id']       ?? 0);
    $name            = trim((string) ($_POST['name']             ?? ''));
    $email           = trim((string) ($_POST['email']            ?? ''));
    $contactNumber   = trim((string) ($_POST['contact_number']   ?? ''));
    $relation        = trim((string) ($_POST['relation']         ?? ''));
    $password        = (string) ($_POST['password']              ?? '');

    $formData['admission_number'] = $admissionNumber;
    $formData['student_id']       = $studentId;
    $formData['name']             = $name;
    $formData['email']            = $email;
    $formData['contact_number']   = $contactNumber;
    $formData['relation']         = $relation;

    if ($admissionNumber === '') $formErrors[] = 'Admission Number is required.';
    if ($studentId <= 0) $formErrors[] = 'Please verify the student by clicking "Check Student" before submitting.';
    if ($name === '') $formErrors[] = 'Parent name is required.';
    if ($email === '') $formErrors[] = 'Email is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $formErrors[] = 'Please enter a valid email address.';
    if ($contactNumber === '') $formErrors[] = 'Contact Number is required.';
    elseif (!preg_match('/^[0-9]{10}$/', $contactNumber)) $formErrors[] = 'Contact Number must be exactly 10 digits.';
    if ($relation === '' || !in_array($relation, $allowedRelations, true)) $formErrors[] = 'Please select a valid Relation.';
    if ($password === '') $formErrors[] = 'Password is required.';
    elseif (strlen($password) < 6) $formErrors[] = 'Password must be at least 6 characters long.';

    if (empty($formErrors) && $conn !== null) {
        try {
            $chk = $conn->prepare(
                "SELECT id, name, parent_id
                 FROM students
                 WHERE id = ? AND admission_number = ?
                 LIMIT 1"
            );
            $chk->bind_param('is', $studentId, $admissionNumber);
            $chk->execute();
            $chkRes = $chk->get_result();

            if ($chkRes->num_rows === 0) {
                $formErrors[] = 'The verified student record was not found. Please re-check the Admission Number.';
            } else {
                $studentRow = $chkRes->fetch_assoc();
                $formData['student_name'] = (string) ($studentRow['name'] ?? '');

                if (!empty($studentRow['parent_id'])) {
                    $formErrors[] = 'A parent account is already linked to this student.';
                }
            }
            $chk->close();
        } catch (Throwable $ex) {
            error_log('[Parent Reg - Verify Student] ' . $ex->getMessage());
            $formErrors[] = 'Unable to verify the student record. Please try again.';
        }
    }

    if (empty($formErrors) && $conn !== null) {
        try {
            $chkDup = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $chkDup->bind_param('s', $email);
            $chkDup->execute();
            if ($chkDup->get_result()->num_rows > 0) {
                $formErrors[] = 'An account with this email already exists. Please use another email.';
            }
            $chkDup->close();

            if (empty($formErrors)) {
                $chkDup2 = $conn->prepare("SELECT id FROM parents WHERE email = ? LIMIT 1");
                $chkDup2->bind_param('s', $email);
                $chkDup2->execute();
                if ($chkDup2->get_result()->num_rows > 0) {
                    $formErrors[] = 'This email is already registered as a parent.';
                }
                $chkDup2->close();
            }
        } catch (Throwable $ex) {
            error_log('[Parent Reg - Duplicate Check] ' . $ex->getMessage());
        }
    }

    if (empty($formErrors) && $conn !== null) {
        $conn->begin_transaction();

        try {
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $role           = 'PARENT';
            $status         = 'Pending';

            $sqlUser = "INSERT INTO users (username, password, role, status, created_at)
                        VALUES (?, ?, ?, ?, NOW())";
            $stmtUser = $conn->prepare($sqlUser);
            if (!$stmtUser) {
                throw new Exception('User insert prepare failed: ' . $conn->error);
            }

            $stmtUser->bind_param('ssss', $email, $hashedPassword, $role, $status);
            if (!$stmtUser->execute()) {
                throw new Exception('User insert failed: ' . $stmtUser->error);
            }
            $newUserId = (int) $conn->insert_id;
            $stmtUser->close();

            $sqlParent = "INSERT INTO parents (user_id, name, email, contact_number, relation)
                          VALUES (?, ?, ?, ?, ?)";
            $stmtParent = $conn->prepare($sqlParent);
            if (!$stmtParent) {
                throw new Exception('Parent insert prepare failed: ' . $conn->error);
            }

            $stmtParent->bind_param('issss', $newUserId, $name, $email, $contactNumber, $relation);
            if (!$stmtParent->execute()) {
                throw new Exception('Parent insert failed: ' . $stmtParent->error);
            }
            $newParentId = (int) $conn->insert_id;
            $stmtParent->close();

            $sqlLink = "UPDATE students SET parent_id = ? WHERE id = ?";
            $stmtLink = $conn->prepare($sqlLink);
            if (!$stmtLink) {
                throw new Exception('Student update prepare failed: ' . $conn->error);
            }

            $stmtLink->bind_param('ii', $newParentId, $studentId);
            if (!$stmtLink->execute()) {
                throw new Exception('Student update failed: ' . $stmtLink->error);
            }
            $stmtLink->close();

            $conn->commit();

            $_SESSION['flash_success'] = 'Your registration request has been submitted! Please wait for admin approval before logging in.';
            header('Location: login.php?role=parent&registered=success');
            exit;

        } catch (Throwable $ex) {
            $conn->rollback();
            error_log('[Parent Reg - Save] ' . $ex->getMessage());
            $formErrors[] = $ex->getMessage() ?: 'Registration failed. Please try again.';
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Parent registration — Rajagiri College Grievance Redressal Portal." />
  <meta name="theme-color" content="#DB0878" />
  <title>Parent Registration — Rajagiri College of Social Sciences</title>
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

    .field.readonly { background: #F8F5F8; color: #5b4d5c; cursor: not-allowed; }

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

        <a href="login.php?role=parent"
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
          <i data-lucide="users" class="h-4 w-4"></i>
          Parent Registration
        </span>
        <h1 class="font-display balance mt-4 text-3xl sm:text-4xl md:text-5xl font-extrabold leading-tight">
          Create your portal account.
        </h1>
        <p class="mt-3 text-white/85 text-base sm:text-lg max-w-2xl">
          Link your parent account to the correct student and manage grievance portal access.
        </p>
      </div>
    </div>
  </section>

  <main id="main-content" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 md:py-14">
    <div class="grid lg:grid-cols-[1fr_320px] gap-8 items-start">
      <section class="card-hard rounded-2xl bg-white p-6 sm:p-8 md:p-10 rise">

        <div class="mb-8">
          <p class="text-xs font-extrabold uppercase tracking-[.18em] text-hot">Parent account</p>
          <h2 class="font-display text-2xl sm:text-3xl font-extrabold text-ink mt-1">Register as a parent</h2>
          <p class="text-sm text-slate-500 mt-2">Verify the student first, then enter your contact and account details.</p>
        </div>

        <?php if (!empty($formErrors)): ?>
          <div class="rounded-xl border-2 border-red-300 bg-red-50 px-4 py-3 mb-6 flex gap-3">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-600 shrink-0 mt-0.5"></i>
            <ul class="text-sm font-semibold text-red-700 space-y-1"><?php foreach ($formErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
          </div>
        <?php endif; ?>

        <?php if ($dbError && empty($formErrors)): ?>
          <div class="rounded-xl border-2 border-amber-300 bg-amber-50 px-4 py-3 mb-6 flex gap-3">
            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 shrink-0 mt-0.5"></i>
            <p class="text-sm font-semibold text-amber-700"><?= e($dbError) ?></p>
          </div>
        <?php endif; ?>

        <div id="ajaxMessage" class="hidden rounded-xl px-4 py-3 mb-6 flex gap-3">
          <i id="ajaxMessageIcon" data-lucide="alert-circle" class="w-5 h-5 shrink-0 mt-0.5"></i>
          <p id="ajaxMessageText" class="text-sm font-semibold"></p>
        </div>

        <form id="parentRegistrationForm" method="POST" action="parent_register.php" class="space-y-6" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
          <input type="hidden" name="action" value="register_parent">
          <input type="hidden" name="student_id" id="student_id" value="<?= (int)$formData['student_id'] ?>">

          <div class="rounded-2xl border-2 border-ink bg-blush p-5">
            <div class="flex items-start gap-3 mb-4">
              <span class="w-9 h-9 rounded-lg bg-hot border-2 border-ink text-white flex items-center justify-center">
                <i data-lucide="search" class="w-4 h-4"></i>
              </span>
              <div>
                <h3 class="font-display font-bold text-ink">Verify student</h3>
                <p class="text-xs text-slate-500 mt-1">Enter the student's admission number to load their details.</p>
              </div>
            </div>

            <div class="grid md:grid-cols-[1.1fr_.9fr] gap-5">
              <div>
                <label class="label" for="admission_number">Admission Number <span class="text-hot">*</span></label>
                <div class="flex gap-2">
                  <input class="field" type="text" name="admission_number" id="admission_number" required value="<?= e($formData['admission_number']) ?>" placeholder="Enter admission number" autocomplete="off">
                  <button type="button" id="checkStudentBtn" class="btn-hard whitespace-nowrap inline-flex items-center gap-2 rounded-xl bg-grape text-white px-4 py-2.5 text-sm font-bold hover:bg-ink">
                    <i data-lucide="badge-check" class="w-4 h-4"></i>
                    <span>Check Student</span>
                  </button>
                </div>
              </div>
              <div>
                <label class="label" for="student_name">Student Name</label>
                <input class="field readonly" type="text" id="student_name" readonly value="<?= e($formData['student_name']) ?>" placeholder="Verified student name">
              </div>
            </div>

            <div class="grid md:grid-cols-2 gap-5 mt-5">
              <div>
                <label class="label" for="course">Course</label>
                <input class="field readonly" type="text" id="course" readonly value="<?= e($formData['course'] ?? '') ?>" placeholder="Course">
              </div>
              <div>
                <label class="label" for="class">Class</label>
                <input class="field readonly" type="text" id="class" readonly value="<?= e($formData['class'] ?? '') ?>" placeholder="Class">
              </div>
            </div>
          </div>

          <div class="grid md:grid-cols-2 gap-5">
            <div>
              <label class="label" for="name">Parent Name <span class="text-hot">*</span></label>
              <input class="field" type="text" name="name" id="name" required value="<?= e($formData['name']) ?>" placeholder="Full name">
            </div>
            <div>
              <label class="label" for="relation">Relation <span class="text-hot">*</span></label>
              <select class="field" name="relation" id="relation" required>
                <option value="" disabled <?= $formData['relation']===''?'selected':'' ?>>Select relation</option>
                <option value="Father" <?= $formData['relation']==='Father'?'selected':'' ?>>Father</option>
                <option value="Mother" <?= $formData['relation']==='Mother'?'selected':'' ?>>Mother</option>
                <option value="Guardian" <?= $formData['relation']==='Guardian'?'selected':'' ?>>Guardian</option>
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

          <div class="max-w-xl">
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

          <div class="pt-5 border-t-2 border-slate-100 flex flex-col-reverse sm:flex-row sm:justify-end gap-3">
            <a href="login.php?role=parent"
               class="inline-flex items-center justify-center gap-2 rounded-xl bg-white border-2 border-ink px-6 py-3 text-sm font-bold text-ink hover:bg-blush transition-colors">
              Cancel
            </a>
            <button type="submit" id="submitBtn" <?= ((int)$formData['student_id']<=0)?'disabled':'' ?>
                    class="btn-hard inline-flex items-center justify-center gap-2 rounded-xl bg-hot px-8 py-3 text-sm font-bold text-white hover:bg-hotdark disabled:opacity-45 disabled:cursor-not-allowed">
              <i data-lucide="user-plus" class="w-4 h-4"></i>
              <span>Create Parent Account</span>
            </button>
          </div>
        </form>

        <p class="text-center text-sm text-slate-500 mt-6">
          Already registered?
          <a href="login.php?role=parent" class="font-extrabold text-hot hover:text-grape underline-offset-4 hover:underline">Log in here</a>
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

    const admissionInput=document.getElementById('admission_number'),
          checkBtn=document.getElementById('checkStudentBtn'),
          studentIdInput=document.getElementById('student_id'),
          studentNameInput=document.getElementById('student_name'),
          courseInput=document.getElementById('course'),
          classInput=document.getElementById('class'),
          submitBtn=document.getElementById('submitBtn'),
          ajaxMsgBox=document.getElementById('ajaxMessage'),
          ajaxMsgIcon=document.getElementById('ajaxMessageIcon'),
          ajaxMsgText=document.getElementById('ajaxMessageText');

    function showMessage(type,msg){
      ajaxMsgBox.classList.remove('hidden');
      ajaxMsgBox.classList.remove('border-leaf','bg-[#DCEFE4]','text-leaf','border-red-300','bg-red-50','text-red-700');
      if(type==='success'){
        ajaxMsgBox.classList.add('border-leaf','bg-[#DCEFE4]','text-leaf');
        ajaxMsgIcon.setAttribute('data-lucide','check-circle');
      }else{
        ajaxMsgBox.classList.add('border-red-300','bg-red-50','text-red-700');
        ajaxMsgIcon.setAttribute('data-lucide','alert-circle');
      }
      ajaxMsgText.textContent=msg;
      if(window.lucide) lucide.createIcons({targets:[ajaxMsgIcon]});
    }
    function hideMessage(){ajaxMsgBox.classList.add('hidden');ajaxMsgText.textContent='';}

    async function checkStudent(){
      const admissionNumber=(admissionInput.value||'').trim();
      if(!admissionNumber){showMessage('error','Please enter an Admission Number first.');admissionInput.focus();return}
      studentIdInput.value='0';studentNameInput.value='';courseInput.value='';classInput.value='';submitBtn.disabled=true;
      const old=checkBtn.innerHTML;
      checkBtn.disabled=true;
      checkBtn.innerHTML='<span class="animate-spin mr-2">⏳</span> Checking…';
      try{
        const body=new URLSearchParams();body.append('admission_number',admissionNumber);
        const response=await fetch('parent_register.php?action=check_student',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()});
        const data=await response.json();
        if(data&&data.success){
          studentIdInput.value=String(data.student_id||0);
          studentNameInput.value=data.name||'';
          courseInput.value=data.course||'';
          classInput.value=data.class||'';
          submitBtn.disabled=false;
          showMessage('success','Student verified! You can now complete the form.');
        }else{
          showMessage('error',data&&data.message?data.message:'Student not found.');
        }
      }catch(e){console.error(e);showMessage('error','Unable to reach the server. Please try again.')}
      finally{
        checkBtn.disabled=false;
        checkBtn.innerHTML=old;
        if(window.lucide) lucide.createIcons();
      }
    }

    checkBtn.addEventListener('click',checkStudent);
    admissionInput.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();checkStudent()}});
    admissionInput.addEventListener('input',()=>{
      if(studentIdInput.value!=='0'){
        studentIdInput.value='0';studentNameInput.value='';courseInput.value='';classInput.value='';submitBtn.disabled=true;hideMessage();
      }
    });

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
