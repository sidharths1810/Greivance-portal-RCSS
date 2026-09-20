<?php
/**
 * parent_register.php
 * ---------------------------------------------------------------------------
 * Parent Registration — Rajagiri College Grievance Redressal Portal
 *
 * Flow:
 *   1. Parent enters the student's Admission Number and clicks "Check Student"
 *   2. AJAX fetches student + course + class details (embedded endpoint)
 *   3. Parent fills their own details, then submits
 *   4. User row → parents row (with relation) → link to student → commit
 *
 * Database (grievance_db):
 *   users    : id, username, password (BCRYPT), role, status
 *   parents  : id, user_id, name, email, contact_number, relation
 *   students : id, admission_number, parent_id, class_id, name, email, contact_number
 *   classes  : id, course_id, class_name
 *   courses  : id, course_name
 *
 * REQUIRED MIGRATION (run once):
 *   ALTER TABLE `parents`
 *     ADD COLUMN `relation` VARCHAR(50) DEFAULT NULL AFTER `contact_number`;
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. SESSION START
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// 2. If already logged in as PARENT, redirect to dashboard
// ---------------------------------------------------------------------------
if (!empty($_SESSION['user_id']) && isset($_SESSION['role']) && strtoupper((string) $_SESSION['role']) === 'PARENT') {
    header('Location: parent/dashboard.php');
    exit;
}

// ---------------------------------------------------------------------------
// 3. DATABASE CONNECTION
// ---------------------------------------------------------------------------
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

// ---------------------------------------------------------------------------
// 4. HELPERS
// ---------------------------------------------------------------------------
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

// ---------------------------------------------------------------------------
// 5. CSRF TOKEN
// ---------------------------------------------------------------------------
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Throwable $ex) {
        $_SESSION['csrf_token'] = md5(uniqid((string) mt_rand(), true));
    }
}
$csrfToken = (string) $_SESSION['csrf_token'];

// ---------------------------------------------------------------------------
// 6. ALLOWED RELATIONS (whitelist)
// ---------------------------------------------------------------------------
$allowedRelations = ['Father', 'Mother', 'Guardian'];

// ---------------------------------------------------------------------------
// 7. EMBEDDED AJAX ENDPOINT — ?action=check_student
// ---------------------------------------------------------------------------
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

// ---------------------------------------------------------------------------
// 8. HANDLE FORM SUBMISSION (POST)
// ---------------------------------------------------------------------------
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

    // ---- CSRF check ----
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    if ($submittedToken === '' || !hash_equals($csrfToken, $submittedToken)) {
        $formErrors[] = 'Security token expired. Please refresh the page and try again.';
    }

    // ----- Collect -----
    $admissionNumber = trim((string) ($_POST['admission_number'] ?? ''));
    $studentId       = (int) ($_POST['student_id']       ?? 0);
    $name            = trim((string) ($_POST['name']             ?? ''));
    $email           = trim((string) ($_POST['email']            ?? ''));
    $contactNumber   = trim((string) ($_POST['contact_number']   ?? ''));
    $relation        = trim((string) ($_POST['relation']         ?? ''));
    $password        = (string) ($_POST['password']              ?? '');

    // ----- Preserve for redisplay -----
    $formData['admission_number'] = $admissionNumber;
    $formData['student_id']       = $studentId;
    $formData['name']             = $name;
    $formData['email']            = $email;
    $formData['contact_number']   = $contactNumber;
    $formData['relation']         = $relation;

    // ----- Validation -----
    if ($admissionNumber === '') {
        $formErrors[] = 'Admission Number is required.';
    }
    if ($studentId <= 0) {
        $formErrors[] = 'Please verify the student by clicking "Check Student" before submitting.';
    }
    if ($name === '') {
        $formErrors[] = 'Parent name is required.';
    }
    if ($email === '') {
        $formErrors[] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $formErrors[] = 'Please enter a valid email address.';
    }
    if ($contactNumber === '') {
        $formErrors[] = 'Contact Number is required.';
    } elseif (!preg_match('/^[0-9]{10}$/', $contactNumber)) {
        $formErrors[] = 'Contact Number must be exactly 10 digits.';
    }
    if ($relation === '' || !in_array($relation, $allowedRelations, true)) {
        $formErrors[] = 'Please select a valid Relation.';
    }
    if ($password === '') {
        $formErrors[] = 'Password is required.';
    } elseif (strlen($password) < 6) {
        $formErrors[] = 'Password must be at least 6 characters long.';
    }

    // ----- Sanity check: student exists, unassigned, matches admission -----
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

    // ----- Duplicate checks on email/username -----
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

    // ----- Insert (Transaction) -----
    if (empty($formErrors) && $conn !== null) {
        $conn->begin_transaction();

        try {
            // ---- 1) users ----
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

            // ---- 2) parents (WITH relation) ----
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

            // ---- 3) Link parent to student ----
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

            // ---- Success: redirect to login page with flag ----
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
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Parent Registration — Rajagiri College of Social Sciences</title>
<link rel="icon" type="image/svg+xml" href="public/favicon.svg">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/lucide@latest"></script>

<style>
@import url('https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700;12..96,800&family=Figtree:wght@400;500;600;700&display=swap');
:root{--hot:#DB0878;--hotdark:#B70664;--sun:#FFC93C;--grape:#4A154B;--leaf:#006837;--ink:#1F0A24;--blush:#FFF0F7}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{font-family:'Figtree',sans-serif;background:#fff;color:var(--ink)}
h1,h2,h3,.brand-heading{font-family:'Bricolage Grotesque',sans-serif}
.brand-stripe{height:6px;background:linear-gradient(90deg,var(--hot) 0 38%,var(--sun) 38% 62%,var(--leaf) 62% 82%,#6A2C8A 82% 100%)}
.nav-shadow{box-shadow:0 2px 0 rgba(31,10,36,.05)}
.hero{background:linear-gradient(135deg,var(--grape) 0%,#6A2C8A 48%,#9B176F 100%);position:relative;overflow:hidden}
.hero:after{content:"";position:absolute;width:340px;height:340px;border-radius:50%;right:-120px;top:-150px;background:rgba(255,201,60,.15)}
.hero:before{content:"";position:absolute;width:240px;height:240px;border-radius:50%;left:-130px;bottom:-150px;background:rgba(219,8,120,.25)}
.logo-box{background:#fff;border:1px solid rgba(255,255,255,.65);box-shadow:7px 7px 0 rgba(255,201,60,.9)}
.card{background:#fff;border:2px solid #eee5ee;box-shadow:10px 10px 0 rgba(74,21,75,.12)}
.field{width:100%;border:2px solid #e8e1e8;border-radius:12px;padding:.82rem 1rem;background:#fff;color:var(--ink);font-weight:600;outline:none;transition:.2s}
.field:hover{border-color:rgba(219,8,120,.35)}.field:focus{border-color:var(--hot);box-shadow:0 0 0 4px rgba(219,8,120,.10)}
.field.readonly{background:#f8f5f8;color:#5b4d5c;cursor:not-allowed}
.label{display:block;font-size:.82rem;font-weight:700;margin-bottom:.45rem;color:#38283b}
.btn{display:inline-flex;align-items:center;justify-content:center;border-radius:12px;font-weight:800;padding:.82rem 1.25rem;transition:.2s}
.btn-hard{background:var(--hot);color:#fff;box-shadow:5px 5px 0 var(--grape)}.btn-hard:hover{background:var(--hotdark);transform:translate(-1px,-1px);box-shadow:6px 6px 0 var(--grape)}
.btn-secondary{background:#fff;color:var(--grape);border:2px solid #ddd2df}
.btn-secondary:hover{border-color:var(--grape);background:#faf6fa}
.btn-check{background:var(--grape);color:#fff}.btn-check:hover{background:#5c1d5d}
.info{border:2px solid #eee5ee;border-radius:16px;padding:1rem 1.1rem;background:#fff}
.info:hover{box-shadow:5px 5px 0 rgba(219,8,120,.10)}
.error-box{border:2px solid #fecaca;background:#fff1f2;color:#991b1b}.warn-box{border:2px solid #fde68a;background:#fffbeb;color:#92400e}
.success-box{border:2px solid #bbf7d0;background:#f0fdf4;color:#166534}
.focus-ring:focus-visible{outline:3px solid var(--sun);outline-offset:3px}
@keyframes rise{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
.rise{animation:rise .55s ease both}.delay-1{animation-delay:.08s}.delay-2{animation-delay:.16s}
@media(prefers-reduced-motion:reduce){*,*:before,*:after{animation-duration:.01ms!important;transition:none!important;scroll-behavior:auto!important}}
::-webkit-scrollbar{width:10px}::-webkit-scrollbar-thumb{background:#c9b8c9;border-radius:20px}::-webkit-scrollbar-thumb:hover{background:var(--hot)}
</style>

</head>
<body class="min-h-screen">
<div class="brand-stripe"></div>
<header class="bg-white nav-shadow">
  <div class="max-w-7xl mx-auto px-5 sm:px-8 py-4 flex items-center justify-between gap-4">
    <a href="index.php" class="flex items-center gap-3 focus-ring rounded-lg">
      <img src="public/rcss-logo.png" alt="Rajagiri College of Social Sciences" class="h-11 sm:h-12 w-auto">
      <div class="hidden sm:block border-l border-slate-200 pl-3">
        <p class="brand-heading text-sm font-extrabold text-[#4A154B]">Grievance Redressal</p>
        <p class="text-xs text-slate-500">Student • Parent Portal</p>
      </div>
    </a>
    <a href="login.php?role=parent" class="btn btn-secondary focus-ring text-sm">
      <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i> Back to Login
    </a>
  </div>
</header>

<section class="hero text-white">
  <div class="max-w-7xl mx-auto px-5 sm:px-8 py-10 md:py-14 relative z-10">
    <div class="max-w-3xl rise">
      <div class="inline-flex items-center gap-2 rounded-full bg-white/10 border border-white/20 px-3 py-1.5 text-xs font-bold tracking-wide uppercase">
        <i data-lucide="users" class="w-4 h-4"></i> Parent Registration
      </div>
      <h1 class="brand-heading text-3xl sm:text-4xl md:text-5xl font-extrabold mt-4">Create your portal account.</h1>
      <p class="mt-3 text-white/80 text-base sm:text-lg max-w-2xl">Link your parent account to the correct student and manage grievance portal access.</p>
    </div>
  </div>
</section>

<main class="max-w-7xl mx-auto px-5 sm:px-8 py-10 md:py-14">
  <div class="grid lg:grid-cols-[1fr_320px] gap-8 items-start">
    <section class="card rounded-2xl p-6 sm:p-8 md:p-10 rise delay-1">
      
<div class="mb-8">
  <p class="text-xs font-extrabold uppercase tracking-[.18em] text-[#DB0878]">Parent account</p>
  <h2 class="brand-heading text-2xl sm:text-3xl font-extrabold text-[#4A154B] mt-1">Register as a parent</h2>
  <p class="text-sm text-slate-500 mt-2">Verify the student first, then enter your contact and account details.</p>
</div>
<?php if (!empty($formErrors)): ?>
<div class="error-box rounded-xl px-4 py-3 mb-6 flex gap-3"><i data-lucide="alert-circle" class="w-5 h-5 shrink-0 mt-0.5"></i><ul class="text-sm font-semibold space-y-1"><?php foreach ($formErrors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($dbError && empty($formErrors)): ?>
<div class="warn-box rounded-xl px-4 py-3 mb-6 flex gap-3"><i data-lucide="alert-triangle" class="w-5 h-5 shrink-0 mt-0.5"></i><p class="text-sm font-semibold"><?= e($dbError) ?></p></div>
<?php endif; ?>
<div id="ajaxMessage" class="hidden rounded-xl px-4 py-3 mb-6 flex gap-3"><i id="ajaxMessageIcon" data-lucide="alert-circle" class="w-5 h-5 shrink-0 mt-0.5"></i><p id="ajaxMessageText" class="text-sm font-semibold"></p></div>
<form id="parentRegistrationForm" method="POST" action="parent_register.php" class="space-y-6" novalidate>
<input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="action" value="register_parent"><input type="hidden" name="student_id" id="student_id" value="<?= (int)$formData['student_id'] ?>">
<div class="rounded-2xl border-2 border-[#eadfeb] bg-[#fcf9fc] p-5">
  <div class="flex items-start gap-3 mb-4"><div class="w-9 h-9 rounded-lg bg-[#4A154B] text-white flex items-center justify-center"><i data-lucide="search" class="w-4 h-4"></i></div><div><h3 class="font-extrabold text-[#4A154B]">Verify student</h3><p class="text-xs text-slate-500 mt-1">Enter the student's admission number to load their details.</p></div></div>
  <div class="grid md:grid-cols-[1.1fr_.9fr] gap-5">
    <div><label class="label" for="admission_number">Admission Number <span class="text-[#DB0878]">*</span></label><div class="flex gap-2"><input class="field" type="text" name="admission_number" id="admission_number" required value="<?= e($formData['admission_number']) ?>" placeholder="Enter admission number" autocomplete="off"><button type="button" id="checkStudentBtn" class="btn btn-check whitespace-nowrap focus-ring"><i data-lucide="badge-check" class="w-4 h-4 mr-2"></i> Check Student</button></div></div>
    <div><label class="label" for="student_name">Student Name</label><input class="field readonly" type="text" id="student_name" readonly value="<?= e($formData['student_name']) ?>" placeholder="Verified student name"></div>
  </div>
  <div class="grid md:grid-cols-2 gap-5 mt-5">
    <div><label class="label" for="course">Course</label><input class="field readonly" type="text" id="course" readonly value="<?= e($formData['course'] ?? '') ?>" placeholder="Course"></div>
    <div><label class="label" for="class">Class</label><input class="field readonly" type="text" id="class" readonly value="<?= e($formData['class'] ?? '') ?>" placeholder="Class"></div>
  </div>
</div>
<div class="grid md:grid-cols-2 gap-5">
  <div><label class="label" for="name">Parent Name <span class="text-[#DB0878]">*</span></label><input class="field" type="text" name="name" id="name" required value="<?= e($formData['name']) ?>" placeholder="Full name"></div>
  <div><label class="label" for="relation">Relation <span class="text-[#DB0878]">*</span></label><select class="field" name="relation" id="relation" required><option value="" disabled <?= $formData['relation']===''?'selected':'' ?>>Select relation</option><option value="Father" <?= $formData['relation']==='Father'?'selected':'' ?>>Father</option><option value="Mother" <?= $formData['relation']==='Mother'?'selected':'' ?>>Mother</option><option value="Guardian" <?= $formData['relation']==='Guardian'?'selected':'' ?>>Guardian</option></select></div>
</div>
<div class="grid md:grid-cols-2 gap-5">
  <div><label class="label" for="email">Email <span class="text-[#DB0878]">*</span></label><input class="field" type="email" name="email" id="email" required value="<?= e($formData['email']) ?>" placeholder="Email address"><p class="text-xs text-slate-500 mt-1.5">This email will be used as your username.</p></div>
  <div><label class="label" for="contact_number">Contact Number <span class="text-[#DB0878]">*</span></label><input class="field" type="tel" name="contact_number" id="contact_number" required inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10" value="<?= e($formData['contact_number']) ?>" placeholder="10-digit mobile number"></div>
</div>
<div class="max-w-xl"><label class="label" for="password">Password <span class="text-[#DB0878]">*</span></label><div class="relative"><input class="field pr-12" type="password" name="password" id="password" required minlength="6" placeholder="Create a password"><button type="button" id="togglePassword" aria-label="Toggle password visibility" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-[#DB0878] focus-ring rounded"><i data-lucide="eye" id="eyeIcon" class="w-5 h-5"></i></button></div><p class="text-xs text-slate-500 mt-1.5">Minimum 6 characters.</p></div>
<div class="pt-5 border-t-2 border-slate-100 flex flex-col-reverse sm:flex-row sm:justify-end gap-3"><a href="login.php?role=parent" class="btn btn-secondary focus-ring">Cancel</a><button type="submit" id="submitBtn" <?= ((int)$formData['student_id']<=0)?'disabled':'' ?> class="btn btn-hard focus-ring disabled:opacity-45 disabled:cursor-not-allowed"><i data-lucide="user-plus" class="w-4 h-4 mr-2"></i> Create Parent Account</button></div>
</form>
<p class="text-center text-sm text-slate-500 mt-6">Already registered? <a href="login.php?role=parent" class="font-extrabold text-[#DB0878] hover:text-[#4A154B]">Log in here</a></p>

    </section>
    <aside class="space-y-4 rise delay-2">
      <div class="rounded-2xl bg-[#FFF0F7] border-2 border-[#F7C7E0] p-5">
        <div class="w-11 h-11 rounded-xl bg-[#DB0878] text-white flex items-center justify-center mb-4">
          <i data-lucide="shield-check" class="w-5 h-5"></i>
        </div>
        <h2 class="brand-heading text-xl font-extrabold text-[#4A154B]">Secure registration</h2>
        <p class="text-sm text-slate-600 mt-2 leading-6">Your details are submitted securely and your account remains pending until the required approval is completed.</p>
      </div>
      <div class="info">
        <div class="flex gap-3">
          <i data-lucide="circle-help" class="w-5 h-5 text-[#006837] mt-0.5 shrink-0"></i>
          <div><h3 class="font-extrabold text-[#4A154B]">Need help?</h3><p class="text-sm text-slate-600 mt-1">Use your registered email to access the portal after approval.</p></div>
        </div>
      </div>
      <div class="rounded-2xl bg-[#4A154B] text-white p-5">
        <div class="flex items-center gap-3 mb-3">
          <img src="public/orel-grievance.png" alt="Oréll" class="h-9 w-auto bg-white rounded-md px-2 py-1">
          <span class="text-xs text-white/70">Powered by Oréll</span>
        </div>
        <p class="text-sm text-white/75 leading-6">Rajagiri College of Social Sciences<br>Grievance Redressal Portal</p>
      </div>
    </aside>
  </div>
</main>

<footer class="bg-[#1F0A24] text-white mt-4">
  <div class="max-w-7xl mx-auto px-5 sm:px-8 py-7 flex flex-col sm:flex-row items-center justify-between gap-4">
    <div class="flex items-center gap-3"><img src="public/rcss-logo.png" alt="RCSS" class="h-9 w-auto bg-white rounded-md px-1.5 py-1"><span class="text-xs text-white/60">Rajagiri College of Social Sciences</span></div>
    <p class="text-xs text-white/50">Grievance Redressal Portal</p>
  </div>
</footer>
<script>
if(typeof lucide!=='undefined') lucide.createIcons();

const admissionInput=document.getElementById('admission_number'),checkBtn=document.getElementById('checkStudentBtn'),studentIdInput=document.getElementById('student_id'),studentNameInput=document.getElementById('student_name'),courseInput=document.getElementById('course'),classInput=document.getElementById('class'),submitBtn=document.getElementById('submitBtn'),ajaxMsgBox=document.getElementById('ajaxMessage'),ajaxMsgIcon=document.getElementById('ajaxMessageIcon'),ajaxMsgText=document.getElementById('ajaxMessageText');
function showMessage(type,msg){ajaxMsgBox.classList.remove('hidden','error-box','success-box');ajaxMsgBox.classList.add(type==='success'?'success-box':'error-box');ajaxMsgIcon.setAttribute('data-lucide',type==='success'?'check-circle':'alert-circle');ajaxMsgText.textContent=msg;if(window.lucide)lucide.createIcons({targets:[ajaxMsgIcon]});}
function hideMessage(){ajaxMsgBox.classList.add('hidden');ajaxMsgText.textContent='';}
async function checkStudent(){const admissionNumber=(admissionInput.value||'').trim();if(!admissionNumber){showMessage('error','Please enter an Admission Number first.');admissionInput.focus();return}studentIdInput.value='0';studentNameInput.value='';courseInput.value='';classInput.value='';submitBtn.disabled=true;const old=checkBtn.innerHTML;checkBtn.disabled=true;checkBtn.innerHTML='<span class="animate-spin mr-2">⏳</span> Checking…';try{const body=new URLSearchParams();body.append('admission_number',admissionNumber);const response=await fetch('parent_register.php?action=check_student',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()});const data=await response.json();if(data&&data.success){studentIdInput.value=String(data.student_id||0);studentNameInput.value=data.name||'';courseInput.value=data.course||'';classInput.value=data.class||'';submitBtn.disabled=false;showMessage('success','Student verified! You can now complete the form.')}else{showMessage('error',data&&data.message?data.message:'Student not found.')}}catch(e){console.error(e);showMessage('error','Unable to reach the server. Please try again.')}finally{checkBtn.disabled=false;checkBtn.innerHTML=old;if(window.lucide)lucide.createIcons()}}
checkBtn.addEventListener('click',checkStudent);admissionInput.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();checkStudent()}});admissionInput.addEventListener('input',()=>{if(studentIdInput.value!=='0'){studentIdInput.value='0';studentNameInput.value='';courseInput.value='';classInput.value='';submitBtn.disabled=true;hideMessage()}});
(function(){const b=document.getElementById('togglePassword'),p=document.getElementById('password'),i=document.getElementById('eyeIcon');if(!b)return;b.addEventListener('click',()=>{const h=p.type==='password';p.type=h?'text':'password';i.setAttribute('data-lucide',h?'eye-off':'eye');lucide.createIcons({targets:[i]})})})();
(function(){const m=document.getElementById('contact_number');if(m)m.addEventListener('input',function(){this.value=this.value.replace(/\D/g,'').slice(0,10)})})();

</script>
<script src="assets/js/index.js"></script>
</body>
</html>