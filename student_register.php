<?php
/**
 * student_register.php
 * ---------------------------------------------------------------------------
 * Student Self-Registration
 * Rajagiri College Grievance Redressal Portal
 *
 * Flow:
 *   1. Fetch active classes for the Class/Semester dropdown
 *   2. Validate required fields + duplicate email/admission check
 *   3. Insert into users (role = STUDENT, status = Pending)
 *   4. Insert into students (linked via user_id)
 *   5. Redirect to login.php?role=student&registered=success
 * ---------------------------------------------------------------------------
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// 1. SESSION
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// ---------------------------------------------------------------------------
// 2. DATABASE CONNECTION
// ---------------------------------------------------------------------------
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

// ---------------------------------------------------------------------------
// 3. HELPERS
// ---------------------------------------------------------------------------
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// 4. CSRF TOKEN
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
// 5. FETCH ACTIVE CLASSES (for dropdown)
// ---------------------------------------------------------------------------
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

// ---------------------------------------------------------------------------
// 6. HANDLE FORM SUBMISSION
// ---------------------------------------------------------------------------
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

    // ---- CSRF check ----
    $submittedToken = $_POST['csrf_token'] ?? '';
    if ($submittedToken === '' || !hash_equals($csrfToken, (string) $submittedToken)) {
        $errors[] = 'Security token expired. Please refresh the page and try again.';
    }

    // ---- Collect input ----
    $formData['name']             = trim((string) ($_POST['name']             ?? ''));
    $formData['gender']           = trim((string) ($_POST['gender']           ?? ''));
    $formData['admission_number'] = trim((string) ($_POST['admission_number'] ?? ''));
    $formData['class_id']         = trim((string) ($_POST['class_id']         ?? ''));
    $formData['email']            = trim((string) ($_POST['email']            ?? ''));
    $formData['contact_number']   = trim((string) ($_POST['contact_number']   ?? ''));
    $formData['guardian_name']    = trim((string) ($_POST['guardian_name']    ?? ''));
    $formData['password']         = (string)       ($_POST['password']         ?? '');
    $formData['address']          = trim((string) ($_POST['address']          ?? ''));

    // ---- Validation ----
    if ($formData['name'] === '') {
        $errors[] = 'Student Name is required.';
    }
    if (!in_array($formData['gender'], ['Male', 'Female', 'Other'], true)) {
        $errors[] = 'Please select a valid Gender.';
    }
    if ($formData['admission_number'] === '') {
        $errors[] = 'Admission Number is required.';
    }
    if ($formData['class_id'] === '' || !ctype_digit($formData['class_id'])) {
        $errors[] = 'Please select a valid Class/Semester.';
    }
    if ($formData['email'] === '') {
        $errors[] = 'Email is required.';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($formData['contact_number'] === '') {
        $errors[] = 'Contact Number is required.';
    } elseif (!preg_match('/^[0-9]{10}$/', $formData['contact_number'])) {
        $errors[] = 'Contact Number must be exactly 10 digits.';
    }
    if ($formData['guardian_name'] === '') {
        $errors[] = 'Guardian Name is required.';
    }
    if ($formData['password'] === '') {
        $errors[] = 'Password is required.';
    } elseif (strlen($formData['password']) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
    }

    // ---- Duplicate checks ----
    if (empty($errors) && $conn instanceof mysqli) {
        try {
            // Email as username — check users table
            $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            if ($chk) {
                $chk->bind_param('s', $formData['email']);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $errors[] = 'This email is already registered. Please log in or use a different email.';
                }
                $chk->close();
            }

            // Email uniqueness in students table
            $chk2 = $conn->prepare("SELECT id FROM students WHERE email = ? LIMIT 1");
            if ($chk2) {
                $chk2->bind_param('s', $formData['email']);
                $chk2->execute();
                if ($chk2->get_result()->num_rows > 0) {
                    $errors[] = 'This email is already in use by another student record.';
                }
                $chk2->close();
            }

            // Admission number uniqueness
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

    // ---- Insert ----
    if (empty($errors) && $conn instanceof mysqli) {
        try {
            $conn->begin_transaction();

            $classIdInt = (int) $formData['class_id'];

            // Verify the selected class is active
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

            // 1. Insert into users
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

            // 2. Insert into students
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
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Registration — Rajagiri College of Social Sciences</title>
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
    <a href="login.php?role=student" class="btn btn-secondary focus-ring text-sm">
      <i data-lucide="arrow-left" class="w-4 h-4 mr-2"></i> Back to Login
    </a>
  </div>
</header>

<section class="hero text-white">
  <div class="max-w-7xl mx-auto px-5 sm:px-8 py-10 md:py-14 relative z-10">
    <div class="max-w-3xl rise">
      <div class="inline-flex items-center gap-2 rounded-full bg-white/10 border border-white/20 px-3 py-1.5 text-xs font-bold tracking-wide uppercase">
        <i data-lucide="graduation-cap" class="w-4 h-4"></i> Student Registration
      </div>
      <h1 class="brand-heading text-3xl sm:text-4xl md:text-5xl font-extrabold mt-4">Create your portal account.</h1>
      <p class="mt-3 text-white/80 text-base sm:text-lg max-w-2xl">Create your student account with your academic, contact and guardian information.</p>
    </div>
  </div>
</section>

<main class="max-w-7xl mx-auto px-5 sm:px-8 py-10 md:py-14">
  <div class="grid lg:grid-cols-[1fr_320px] gap-8 items-start">
    <section class="card rounded-2xl p-6 sm:p-8 md:p-10 rise delay-1">
      
<div class="mb-8">
  <p class="text-xs font-extrabold uppercase tracking-[.18em] text-[#DB0878]">Student account</p>
  <h2 class="brand-heading text-2xl sm:text-3xl font-extrabold text-[#4A154B] mt-1">Register as a student</h2>
  <p class="text-sm text-slate-500 mt-2">Complete your academic and contact details to submit an account request.</p>
</div>
<?php if (!empty($errors)): ?><div class="error-box rounded-xl px-4 py-3 mb-6 flex gap-3"><i data-lucide="alert-circle" class="w-5 h-5 shrink-0 mt-0.5"></i><ul class="text-sm font-semibold space-y-1"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($dbError && empty($errors)): ?><div class="warn-box rounded-xl px-4 py-3 mb-6 flex gap-3"><i data-lucide="alert-triangle" class="w-5 h-5 shrink-0 mt-0.5"></i><p class="text-sm font-semibold"><?= e($dbError) ?></p></div><?php endif; ?>
<form method="POST" action="student_register.php" class="space-y-6" novalidate>
<input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
<div class="grid md:grid-cols-2 gap-5">
<div><label class="label" for="name">Student Name <span class="text-[#DB0878]">*</span></label><input class="field" type="text" name="name" id="name" required value="<?= e($formData['name']) ?>" placeholder="Full name"></div>
<div><label class="label" for="gender">Gender <span class="text-[#DB0878]">*</span></label><select class="field" name="gender" id="gender" required><option value="">Select gender</option><option value="Male" <?= $formData['gender']==='Male'?'selected':'' ?>>Male</option><option value="Female" <?= $formData['gender']==='Female'?'selected':'' ?>>Female</option><option value="Other" <?= $formData['gender']==='Other'?'selected':'' ?>>Other</option></select></div>
</div>
<div class="grid md:grid-cols-2 gap-5">
<div><label class="label" for="admission_number">Admission Number <span class="text-[#DB0878]">*</span></label><input class="field" type="text" name="admission_number" id="admission_number" required value="<?= e($formData['admission_number']) ?>" placeholder="Registration / admission number"></div>
<div><label class="label" for="class_id">Class / Semester <span class="text-[#DB0878]">*</span></label><select class="field" name="class_id" id="class_id" required><option value="">Select class / semester</option><?php foreach($classOptions as $opt): ?><option value="<?= (int)$opt['id'] ?>" <?= (string)$formData['class_id']===(string)$opt['id']?'selected':'' ?>><?= e($opt['class_name']) ?></option><?php endforeach; ?></select></div>
</div>
<div class="grid md:grid-cols-2 gap-5">
<div><label class="label" for="email">Email <span class="text-[#DB0878]">*</span></label><input class="field" type="email" name="email" id="email" required value="<?= e($formData['email']) ?>" placeholder="Email address"><p class="text-xs text-slate-500 mt-1.5">This email will be used as your username.</p></div>
<div><label class="label" for="contact_number">Contact Number <span class="text-[#DB0878]">*</span></label><input class="field" type="tel" name="contact_number" id="contact_number" required inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10" value="<?= e($formData['contact_number']) ?>" placeholder="10-digit mobile number"></div>
</div>
<div class="grid md:grid-cols-2 gap-5">
<div><label class="label" for="guardian_name">Guardian Name <span class="text-[#DB0878]">*</span></label><input class="field" type="text" name="guardian_name" id="guardian_name" required value="<?= e($formData['guardian_name']) ?>" placeholder="Parent / guardian name"></div>
<div><label class="label" for="password">Password <span class="text-[#DB0878]">*</span></label><div class="relative"><input class="field pr-12" type="password" name="password" id="password" required minlength="6" placeholder="Create a password"><button type="button" id="togglePassword" aria-label="Toggle password visibility" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-[#DB0878] focus-ring rounded"><i data-lucide="eye" id="eyeIcon" class="w-5 h-5"></i></button></div><p class="text-xs text-slate-500 mt-1.5">Minimum 6 characters.</p></div>
</div>
<div><label class="label" for="address">Address</label><textarea class="field resize-none" name="address" id="address" rows="5" placeholder="Residential address"><?= e($formData['address']) ?></textarea></div>
<div class="pt-5 border-t-2 border-slate-100 flex flex-col-reverse sm:flex-row sm:justify-end gap-3"><a href="login.php?role=student" class="btn btn-secondary focus-ring">Cancel</a><button type="submit" class="btn btn-hard focus-ring"><i data-lucide="user-plus" class="w-4 h-4 mr-2"></i> Create Student Account</button></div>
</form>
<p class="text-center text-sm text-slate-500 mt-6">Already registered? <a href="login.php?role=student" class="font-extrabold text-[#DB0878] hover:text-[#4A154B]">Log in here</a></p>

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

(function(){const b=document.getElementById('togglePassword'),p=document.getElementById('password'),i=document.getElementById('eyeIcon');if(!b)return;b.addEventListener('click',()=>{const h=p.type==='password';p.type=h?'text':'password';i.setAttribute('data-lucide',h?'eye-off':'eye');lucide.createIcons({targets:[i]})})})();
(function(){const m=document.getElementById('contact_number');if(m)m.addEventListener('input',function(){this.value=this.value.replace(/\D/g,'').slice(0,10)})})();

</script>
<script src="assets/js/index.js"></script>
</body>
</html>