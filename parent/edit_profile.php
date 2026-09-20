<?php
/**
 * parent/edit_profile.php
 * ---------------------------------------------------------------------------
 * Parent — Edit Profile (Personal + Login Details)
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

$dbFile = __DIR__ . '/../db_connect.php';

if (!file_exists($dbFile)) {
    die('Database configuration file (db_connect.php) not found.');
}

require_once $dbFile;

$dbError = null;

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

$userId = (int) $_SESSION['user_id'];

function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$formData = [
    'name'            => '',
    'address'         => '',
    'email'           => '',
    'contact_number'  => '',
    'whatsapp_number' => '',
    'username'        => '',
    'profile_image'   => '',
];

$formErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name            = trim((string) ($_POST['name']            ?? ''));
    $address         = trim((string) ($_POST['address']         ?? ''));
    $email           = trim((string) ($_POST['email']           ?? ''));
    $contactNumber   = trim((string) ($_POST['contact_number']  ?? ''));
    $whatsappNumber  = trim((string) ($_POST['whatsapp_number'] ?? ''));
    $username        = trim((string) ($_POST['username']        ?? ''));
    $password        = (string) ($_POST['password']             ?? '');
    $confirmPassword = (string) ($_POST['confirm_password']     ?? '');

    if ($name === '') $formErrors[] = 'Name is required.';

    if ($email === '') $formErrors[] = 'Email is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $formErrors[] = 'Please enter a valid email address.';

    if ($contactNumber === '') $formErrors[] = 'Contact number is required.';
    elseif (!preg_match('/^[0-9]{6,15}$/', preg_replace('/\D/', '', $contactNumber))) $formErrors[] = 'Please enter a valid contact number (6–15 digits).';

    if ($whatsappNumber !== '' && !preg_match('/^[0-9]{6,15}$/', preg_replace('/\D/', '', $whatsappNumber))) $formErrors[] = 'Please enter a valid WhatsApp number (6–15 digits).';

    if ($username === '') $formErrors[] = 'Username is required.';

    $updatePassword = false;
    if ($password !== '' || $confirmPassword !== '') {
        if ($password === '') $formErrors[] = 'Please enter a password.';
        elseif (strlen($password) < 6) $formErrors[] = 'Password must be at least 6 characters.';
        elseif ($password !== $confirmPassword) $formErrors[] = 'Passwords do not match.';
        else $updatePassword = true;
    }

    if (empty($formErrors) && $conn !== null) {
        try {
            $chk = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
            $chk->bind_param('si', $username, $userId);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) $formErrors[] = 'This username is already taken. Please choose another.';
            $chk->close();
        } catch (Throwable $ex) { error_log('[Parent Edit Profile Username Check] ' . $ex->getMessage()); }
    }

    if (empty($formErrors) && $conn !== null) {
        try {
            $chk = $conn->prepare("SELECT id FROM parents WHERE email = ? AND user_id != ? LIMIT 1");
            $chk->bind_param('si', $email, $userId);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) $formErrors[] = 'This email is already registered to another parent.';
            $chk->close();
        } catch (Throwable $ex) { error_log('[Parent Edit Profile Email Check] ' . $ex->getMessage()); }
    }

    $newProfilePictureRelative = null;

    if (!empty($_FILES['profile_picture']['name']) && (int) $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {

        $fileTmp  = $_FILES['profile_picture']['tmp_name'];
        $fileName = $_FILES['profile_picture']['name'];
        $fileSize = (int) $_FILES['profile_picture']['size'];
        $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
        $maxSize     = 2 * 1024 * 1024;

        if (!in_array($fileExt, $allowedExts, true)) {
            $formErrors[] = 'Only JPG, JPEG, PNG, and WEBP images are allowed.';
        } elseif ($fileSize > $maxSize) {
            $formErrors[] = 'Image size must be under 2 MB.';
        } else {
            $uploadDirAbs = __DIR__ . '/../uploads/profiles/';

            if (!is_dir($uploadDirAbs)) @mkdir($uploadDirAbs, 0755, true);

            if (!is_dir($uploadDirAbs) || !is_writable($uploadDirAbs)) {
                $formErrors[] = 'Upload directory is not writable. Please contact support.';
            } else {
                $newFileName  = 'parent_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
                $targetAbs    = $uploadDirAbs . $newFileName;
                $relativePath = 'uploads/profiles/' . $newFileName;

                if (move_uploaded_file($fileTmp, $targetAbs)) {
                    $newProfilePictureRelative = $relativePath;
                } else {
                    $formErrors[] = 'Failed to upload the profile picture. Please try again.';
                }
            }
        }
    }

    if (empty($formErrors) && $conn !== null) {

        try {
            $oldProfileImage = '';
            $stmtOld = $conn->prepare("SELECT profile_image FROM parents WHERE user_id = ? LIMIT 1");
            if ($stmtOld) {
                $stmtOld->bind_param('i', $userId);
                $stmtOld->execute();
                $resOld = $stmtOld->get_result();
                if ($resOld && $resOld->num_rows > 0) {
                    $rowOld = $resOld->fetch_assoc();
                    $oldProfileImage = (string) ($rowOld['profile_image'] ?? '');
                }
                $stmtOld->close();
            }

            if ($newProfilePictureRelative !== null) {
                $sqlParent = "UPDATE parents
                              SET name            = ?,
                                  address         = ?,
                                  email           = ?,
                                  contact_number  = ?,
                                  whatsapp_number = ?,
                                  profile_image   = ?
                              WHERE user_id = ?
                              LIMIT 1";

                $stmt = $conn->prepare($sqlParent);
                if (!$stmt) throw new Exception('Prepare failed (parents update with image): ' . $conn->error);

                $stmt->bind_param('ssssssi', $name, $address, $email, $contactNumber, $whatsappNumber, $newProfilePictureRelative, $userId);
            } else {
                $sqlParent = "UPDATE parents
                              SET name            = ?,
                                  address         = ?,
                                  email           = ?,
                                  contact_number  = ?,
                                  whatsapp_number = ?
                              WHERE user_id = ?
                              LIMIT 1";

                $stmt = $conn->prepare($sqlParent);
                if (!$stmt) throw new Exception('Prepare failed (parents update): ' . $conn->error);

                $stmt->bind_param('sssssi', $name, $address, $email, $contactNumber, $whatsappNumber, $userId);
            }

            if (!$stmt->execute()) throw new Exception('Failed to update parent profile: ' . $stmt->error);
            $stmt->close();

            if ($newProfilePictureRelative !== null && !empty($oldProfileImage) && $oldProfileImage !== $newProfilePictureRelative) {
                $oldAbs = __DIR__ . '/../' . ltrim($oldProfileImage, '/');
                if (file_exists($oldAbs) && is_file($oldAbs)) @unlink($oldAbs);
            }

            if ($updatePassword) {
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

                $stmt = $conn->prepare("UPDATE users SET username = ?, password = ? WHERE id = ?");
                if (!$stmt) throw new Exception('Prepare failed (users update with password): ' . $conn->error);
                $stmt->bind_param('ssi', $username, $hashedPassword, $userId);
            } else {
                $stmt = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
                if (!$stmt) throw new Exception('Prepare failed (users update): ' . $conn->error);
                $stmt->bind_param('si', $username, $userId);
            }

            if (!$stmt->execute()) throw new Exception('Failed to update login details: ' . $stmt->error);
            $stmt->close();

            $_SESSION['username'] = $username;

            $_SESSION['flash_success'] = 'Profile updated successfully.';
            header('Location: profile.php');
            exit;

        } catch (Throwable $ex) {
            error_log('[Parent Edit Profile Save] ' . $ex->getMessage());
            $formErrors[] = $ex->getMessage() ?: 'A system error occurred while saving your profile.';
        }
    }

    $formData['name']            = $name;
    $formData['address']         = $address;
    $formData['email']           = $email;
    $formData['contact_number']  = $contactNumber;
    $formData['whatsapp_number'] = $whatsappNumber;
    $formData['username']        = $username;
}

$currentProfilePictureRelative = '';
$currentName                   = '';

if ($conn !== null) {
    try {
        $sql = "SELECT  u.username,
                        p.name            AS name,
                        p.address         AS address,
                        p.email           AS email,
                        p.contact_number  AS contact_number,
                        p.whatsapp_number AS whatsapp_number,
                        p.profile_image   AS profile_image
                FROM users u
                LEFT JOIN parents p ON p.user_id = u.id
                WHERE u.id = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();

            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($formData['username'])) $formData['username'] = $row['username'] ?? '';
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $formData['name'] === '') $formData['name'] = $row['name'] ?? '';
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $formData['address'] === '') $formData['address'] = $row['address'] ?? '';
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $formData['email'] === '') $formData['email'] = $row['email'] ?? '';
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $formData['contact_number'] === '') $formData['contact_number'] = $row['contact_number'] ?? '';
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $formData['whatsapp_number'] === '') $formData['whatsapp_number'] = $row['whatsapp_number'] ?? '';

            $currentProfilePictureRelative = $row['profile_image'] ?? '';
            $currentName                   = $row['name']          ?? '';
        }

        $stmt->close();
    } catch (Throwable $ex) {
        error_log('[Parent Edit Profile Pre-load] ' . $ex->getMessage());
        if ($dbError === null) $dbError = 'Unable to load your profile data.';
    }
}

$headerDisplayName  = !empty($formData['name']) ? $formData['name'] : $formData['username'];
$headerDisplayEmail = !empty($formData['email']) ? $formData['email'] : 'parent@rajagiri.edu';

$existingPreviewUrl = '';
if (!empty($currentProfilePictureRelative)) {
    if (file_exists(__DIR__ . '/../' . ltrim((string) $currentProfilePictureRelative, '/'))) {
        $existingPreviewUrl = '../' . ltrim((string) $currentProfilePictureRelative, '/');
    }
}

$countryCodes = [
    '+91'  => 'India (+91)', '+1' => 'United States (+1)', '+44' => 'United Kingdom (+44)', '+61' => 'Australia (+61)',
    '+971' => 'United Arab Emirates (+971)', '+966' => 'Saudi Arabia (+966)', '+974' => 'Qatar (+974)', '+965' => 'Kuwait (+965)', '+968' => 'Oman (+968)', '+973' => 'Bahrain (+973)',
    '+60' => 'Malaysia (+60)', '+65' => 'Singapore (+65)', '+81' => 'Japan (+81)', '+82' => 'South Korea (+82)', '+86' => 'China (+86)',
    '+49' => 'Germany (+49)', '+33' => 'France (+33)', '+39' => 'Italy (+39)', '+34' => 'Spain (+34)', '+31' => 'Netherlands (+31)',
    '+41' => 'Switzerland (+41)', '+46' => 'Sweden (+46)', '+47' => 'Norway (+47)', '+45' => 'Denmark (+45)', '+64' => 'New Zealand (+64)',
    '+27' => 'South Africa (+27)', '+20' => 'Egypt (+20)', '+234' => 'Nigeria (+234)', '+254' => 'Kenya (+254)', '+880' => 'Bangladesh (+880)',
    '+92' => 'Pakistan (+92)', '+94' => 'Sri Lanka (+94)', '+977' => 'Nepal (+977)', '+7' => 'Russia (+7)', '+30' => 'Greece (+30)',
    '+32' => 'Belgium (+32)', '+36' => 'Hungary (+36)', '+40' => 'Romania (+40)', '+43' => 'Austria (+43)', '+48' => 'Poland (+48)',
    '+51' => 'Peru (+51)', '+52' => 'Mexico (+52)', '+54' => 'Argentina (+54)', '+55' => 'Brazil (+55)', '+56' => 'Chile (+56)',
    '+57' => 'Colombia (+57)', '+58' => 'Venezuela (+58)', '+62' => 'Indonesia (+62)', '+63' => 'Philippines (+63)', '+66' => 'Thailand (+66)',
    '+84' => 'Vietnam (+84)', '+90' => 'Turkey (+90)', '+98' => 'Iran (+98)', '+212' => 'Morocco (+212)', '+213' => 'Algeria (+213)',
    '+216' => 'Tunisia (+216)', '+218' => 'Libya (+218)', '+220' => 'Gambia (+220)', '+221' => 'Senegal (+221)', '+233' => 'Ghana (+233)',
    '+237' => 'Cameroon (+237)', '+250' => 'Rwanda (+250)', '+251' => 'Ethiopia (+251)', '+255' => 'Tanzania (+255)', '+256' => 'Uganda (+256)',
    '+263' => 'Zimbabwe (+263)', '+351' => 'Portugal (+351)', '+352' => 'Luxembourg (+352)', '+353' => 'Ireland (+353)', '+354' => 'Iceland (+354)',
    '+355' => 'Albania (+355)', '+356' => 'Malta (+356)', '+357' => 'Cyprus (+357)', '+358' => 'Finland (+358)', '+359' => 'Bulgaria (+359)',
    '+370' => 'Lithuania (+370)', '+371' => 'Latvia (+371)', '+372' => 'Estonia (+372)', '+373' => 'Moldova (+373)', '+374' => 'Armenia (+374)',
    '+375' => 'Belarus (+375)', '+380' => 'Ukraine (+380)', '+381' => 'Serbia (+381)', '+385' => 'Croatia (+385)', '+386' => 'Slovenia (+386)',
    '+420' => 'Czech Republic (+420)', '+421' => 'Slovakia (+421)', '+852' => 'Hong Kong (+852)', '+853' => 'Macau (+853)', '+855' => 'Cambodia (+855)',
    '+856' => 'Laos (+856)', '+886' => 'Taiwan (+886)', '+960' => 'Maldives (+960)', '+961' => 'Lebanon (+961)', '+962' => 'Jordan (+962)',
    '+963' => 'Syria (+963)', '+964' => 'Iraq (+964)', '+967' => 'Yemen (+967)', '+970' => 'Palestine (+970)', '+972' => 'Israel (+972)',
    '+975' => 'Bhutan (+975)', '+976' => 'Mongolia (+976)', '+992' => 'Tajikistan (+992)', '+993' => 'Turkmenistan (+993)', '+994' => 'Azerbaijan (+994)',
    '+995' => 'Georgia (+995)', '+996' => 'Kyrgyzstan (+996)', '+998' => 'Uzbekistan (+998)',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Edit parent profile — Rajagiri College Grievance Redressal Portal." />
  <meta name="theme-color" content="#DB0878" />
  <title>Edit Profile — Parent | Rajagiri College Grievance Portal</title>
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
           class="group relative w-full h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center text-white nav-icon-link px-3">
          <i data-lucide="home" class="w-6 h-6 flex-shrink-0"></i>
          <span class="sidebar-label ml-4 text-sm font-semibold whitespace-nowrap opacity-0 w-0 overflow-hidden transition-all duration-200">Dashboard</span>
          <span class="sidebar-tooltip absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-ink text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50 border border-white/10">Dashboard</span>
        </a>

        <a href="profile.php"
           class="group relative w-full h-12 rounded-xl bg-hot ring-2 ring-sun/60 flex items-center text-white nav-icon-link px-3">
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
              <?php if ($existingPreviewUrl): ?>
                <img src="<?= e($existingPreviewUrl) ?>" alt="<?= e($headerDisplayName) ?>" class="w-10 h-10 rounded-full object-cover border-2 border-ink shadow-[2px_2px_0_#FFC93C]" />
              <?php else: ?>
                <span class="w-10 h-10 rounded-full bg-hot flex items-center justify-center text-white border-2 border-ink shadow-[2px_2px_0_#FFC93C]">
                  <i data-lucide="user" class="w-5 h-5 text-white"></i>
                </span>
              <?php endif; ?>
              <span class="hidden sm:block text-sm font-bold text-ink"><?= e($headerDisplayName) ?></span>
              <i data-lucide="chevron-down" id="parent-chevron" class="w-4 h-4 text-slate-500 transition-transform duration-300"></i>
            </button>

            <div id="parent-dropdown-menu" class="hidden absolute right-0 z-50 mt-3 w-72 rounded-xl border-2 border-ink bg-white p-2 shadow-[6px_6px_0_#FFC93C]" role="menu">
              <div class="rounded-lg bg-blush px-3 py-3">
                <div class="flex items-center gap-3">
                  <?php if ($existingPreviewUrl): ?>
                    <img src="<?= e($existingPreviewUrl) ?>" alt="<?= e($headerDisplayName) ?>" class="w-12 h-12 rounded-full object-cover border-2 border-ink" />
                  <?php else: ?>
                    <span class="w-12 h-12 rounded-full bg-hot flex items-center justify-center text-white border-2 border-ink"><i data-lucide="user" class="w-6 h-6 text-white"></i></span>
                  <?php endif; ?>
                  <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-bold text-ink"><?= e($headerDisplayName) ?></p>
                    <p class="truncate text-xs text-slate-600"><?= e($headerDisplayEmail) ?></p>
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

        <div class="max-w-5xl mx-auto mb-6 rise">
          <h1 class="font-display text-2xl md:text-3xl font-extrabold tracking-tight text-ink mb-2 flex items-center gap-3">
            <span class="w-10 h-10 rounded-xl bg-hot border-2 border-ink flex items-center justify-center shadow-[2px_2px_0_#FFC93C]">
              <i data-lucide="user" class="w-5 h-5 text-white"></i>
            </span>
            User Details
          </h1>
          <nav class="flex flex-wrap items-center gap-2 text-sm text-slate-500 ml-1" aria-label="Breadcrumb">
            <a href="dashboard.php" class="flex items-center rounded transition-colors hover:text-hot"><i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i> Dashboard</a>
            <span class="text-slate-300">/</span>
            <a href="profile.php" class="rounded transition-colors hover:text-hot">My Profile</a>
            <span class="text-slate-300">/</span>
            <span class="text-hot font-semibold">Edit Profile</span>
          </nav>
        </div>

        <?php if ($dbError || !empty($formErrors)): ?>
          <div class="max-w-5xl mx-auto mb-6 rounded-xl border-2 border-red-300 bg-red-50 px-4 py-3 flex items-start gap-2">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <div class="text-sm text-red-700 space-y-1">
              <?php if ($dbError): ?><p><?= e($dbError) ?></p><?php endif; ?>
              <?php foreach ($formErrors as $err): ?><p><?= e($err) ?></p><?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="max-w-5xl mx-auto rise rise-2">
          <div class="bg-white rounded-2xl border-2 border-ink shadow-[6px_6px_0_#FFC93C] overflow-hidden">

            <div class="bg-blush px-6 py-4 border-b-2 border-ink">
              <h2 class="font-display text-lg font-bold text-ink flex items-center gap-2">
                <i data-lucide="pencil-line" class="w-5 h-5 text-hot"></i>
                Update
              </h2>
              <p class="text-sm text-slate-600 mt-1">Manage your personal information and login credentials</p>
            </div>

            <form action="edit_profile.php" method="POST" enctype="multipart/form-data" class="p-6 md:p-8 space-y-8">

              <div class="space-y-6">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                  <div class="space-y-2">
                    <label for="name" class="block text-sm font-semibold text-ink">Name <span class="text-hot">*</span></label>
                    <div class="relative">
                      <i data-lucide="user" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
                      <input type="text" id="name" name="name" value="<?= e($formData['name']) ?>" required placeholder="Enter your full name"
                             class="w-full pl-11 pr-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
                    </div>
                  </div>

                  <div class="space-y-2">
                    <label for="address" class="block text-sm font-semibold text-ink">Address</label>
                    <div class="relative">
                      <i data-lucide="map-pin" class="absolute left-3 top-3 w-5 h-5 text-slate-400"></i>
                      <textarea id="address" name="address" rows="3" placeholder="Enter your residential address"
                                class="w-full pl-11 pr-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 resize-none focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all"><?= e($formData['address']) ?></textarea>
                    </div>
                  </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                  <div class="space-y-2">
                    <label for="email" class="block text-sm font-semibold text-ink">Email <span class="text-hot">*</span></label>
                    <div class="relative">
                      <i data-lucide="mail" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
                      <input type="email" id="email" name="email" value="<?= e($formData['email']) ?>" required placeholder="Enter your email address"
                             class="w-full pl-11 pr-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
                    </div>
                  </div>

                  <div class="space-y-2">
                    <label for="profile_picture" class="block text-sm font-semibold text-ink">Image</label>
                    <div class="flex items-center gap-3">
                      <input type="file" id="profile_picture" name="profile_picture" accept=".jpg,.jpeg,.png,.webp" class="hidden" onchange="previewProfileImage(event)" />
                      <button type="button" onclick="document.getElementById('profile_picture').click()"
                              class="px-5 py-2.5 bg-white border-2 border-ink rounded-xl text-sm font-bold text-ink hover:bg-blush transition-colors">
                        Choose file
                      </button>
                      <span id="file-chosen-text" class="text-sm text-slate-500 truncate">
                        <?= $existingPreviewUrl ? 'Current image loaded' : 'No file chosen' ?>
                      </span>
                      <?php if ($existingPreviewUrl): ?>
                        <img id="profile-preview" src="<?= e($existingPreviewUrl) ?>" alt="Preview" class="w-10 h-10 rounded-lg object-cover border-2 border-ink" />
                      <?php else: ?>
                        <img id="profile-preview" src="" alt="Preview" class="hidden w-10 h-10 rounded-lg object-cover border-2 border-ink" />
                      <?php endif; ?>
                    </div>
                    <p class="text-xs text-slate-500 mt-1">Allowed: JPG, JPEG, PNG, WEBP (max 2 MB)</p>
                  </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

                  <div class="space-y-2">
                    <label for="contact_number" class="block text-sm font-semibold text-ink">Mobile Number <span class="text-hot">*</span></label>
                    <div class="flex gap-2">
                      <div class="relative w-40 flex-shrink-0" data-country-select="mobile">
                        <button type="button" class="country-select-trigger w-full flex items-center justify-between px-3 py-3 border-2 border-ink rounded-xl bg-white text-ink text-sm font-semibold focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all">
                          <span class="country-select-label truncate">+91</span>
                          <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 pointer-events-none flex-shrink-0"></i>
                        </button>
                        <div class="country-select-panel hidden absolute z-50 mt-1 w-72 bg-white rounded-xl border-2 border-ink shadow-[6px_6px_0_#FFC93C] overflow-hidden">
                          <div class="p-3 border-b-2 border-ink bg-blush">
                            <div class="relative">
                              <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                              <input type="text" class="country-select-search w-full pl-10 pr-4 py-2 border-2 border-ink rounded-lg text-sm focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" placeholder="Search country..." />
                            </div>
                          </div>
                          <div class="country-select-list max-h-56 overflow-y-auto">
                            <?php foreach ($countryCodes as $code => $label): ?>
                              <button type="button"
                                      class="country-select-option w-full flex items-center justify-between px-4 py-2.5 text-sm transition-colors <?= $code === '+91' ? 'bg-blush text-hot font-semibold' : 'text-slate-700 hover:bg-blush' ?>"
                                      data-value="<?= e($code) ?>" data-label="<?= e($label) ?>" data-search="<?= e(strtolower($code . ' ' . $label)) ?>">
                                <span class="font-semibold flex-shrink-0"><?= e($code) ?></span>
                                <span class="text-xs text-slate-500 flex-1 ml-3 text-left truncate"><?= e($label) ?></span>
                              </button>
                            <?php endforeach; ?>
                          </div>
                          <div class="country-select-empty hidden px-4 py-3 text-sm text-slate-500 text-center">No countries found</div>
                        </div>
                      </div>

                      <div class="relative flex-1">
                        <i data-lucide="smartphone" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
                        <input type="tel" id="contact_number" name="contact_number" value="<?= e($formData['contact_number']) ?>" required placeholder="Enter mobile number"
                               class="w-full pl-11 pr-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
                      </div>
                    </div>
                    <p class="text-xs text-slate-500 mt-1">Country code is displayed for reference only.</p>
                  </div>

                  <div class="space-y-2">
                    <label for="whatsapp_number" class="block text-sm font-semibold text-ink">WhatsApp Number</label>
                    <div class="flex gap-2">
                      <div class="relative w-40 flex-shrink-0" data-country-select="whatsapp">
                        <button type="button" class="country-select-trigger w-full flex items-center justify-between px-3 py-3 border-2 border-ink rounded-xl bg-white text-ink text-sm font-semibold focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all">
                          <span class="country-select-label truncate">--SELECT--</span>
                          <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 pointer-events-none flex-shrink-0"></i>
                        </button>
                        <div class="country-select-panel hidden absolute z-50 mt-1 w-72 bg-white rounded-xl border-2 border-ink shadow-[6px_6px_0_#FFC93C] overflow-hidden">
                          <div class="p-3 border-b-2 border-ink bg-blush">
                            <div class="relative">
                              <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                              <input type="text" class="country-select-search w-full pl-10 pr-4 py-2 border-2 border-ink rounded-lg text-sm focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" placeholder="Search country..." />
                            </div>
                          </div>
                          <div class="country-select-list max-h-56 overflow-y-auto">
                            <button type="button" class="country-select-option w-full flex items-center justify-between px-4 py-2.5 text-sm transition-colors bg-blush text-hot font-semibold" data-value="" data-label="--SELECT--" data-search="select">
                              <span class="font-semibold">--SELECT--</span>
                              <span class="text-xs text-slate-500 flex-1 ml-3 text-left truncate">Choose a country code</span>
                            </button>
                            <?php foreach ($countryCodes as $code => $label): ?>
                              <button type="button"
                                      class="country-select-option w-full flex items-center justify-between px-4 py-2.5 text-sm text-slate-700 hover:bg-blush transition-colors"
                                      data-value="<?= e($code) ?>" data-label="<?= e($label) ?>" data-search="<?= e(strtolower($code . ' ' . $label)) ?>">
                                <span class="font-semibold flex-shrink-0"><?= e($code) ?></span>
                                <span class="text-xs text-slate-500 flex-1 ml-3 text-left truncate"><?= e($label) ?></span>
                              </button>
                            <?php endforeach; ?>
                          </div>
                          <div class="country-select-empty hidden px-4 py-3 text-sm text-slate-500 text-center">No countries found</div>
                        </div>
                      </div>

                      <div class="relative flex-1">
                        <i data-lucide="message-circle" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
                        <input type="tel" id="whatsapp_number" name="whatsapp_number" value="<?= e($formData['whatsapp_number']) ?>" placeholder="Enter Your Whatsapp No"
                               class="w-full pl-11 pr-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
                      </div>
                    </div>
                    <p class="text-xs text-slate-500 mt-1">Optional — leave blank if not applicable.</p>
                  </div>

                </div>

              </div>

              <div class="space-y-6">
                <div class="bg-hot rounded-xl px-4 py-3 border-2 border-ink relative overflow-hidden">
                  <div class="absolute top-0 right-0 w-24 h-24 bg-white/10 rounded-full blur-xl"></div>
                  <h3 class="relative text-white font-bold text-sm flex items-center gap-2">
                    <i data-lucide="lock" class="w-4 h-4"></i>
                    Login Details
                  </h3>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                  <div class="space-y-2">
                    <label for="username" class="block text-sm font-semibold text-ink">Username <span class="text-hot">*</span></label>
                    <div class="relative">
                      <i data-lucide="user-circle" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
                      <input type="text" id="username" name="username" value="<?= e($formData['username']) ?>" required placeholder="Enter username"
                             class="w-full pl-11 pr-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
                    </div>
                  </div>

                  <div class="space-y-2">
                    <label for="password" class="block text-sm font-semibold text-ink">Password</label>
                    <div class="relative">
                      <i data-lucide="lock" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
                      <input type="password" id="password" name="password" placeholder="••••••••" autocomplete="new-password"
                             class="w-full pl-11 pr-12 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
                      <button type="button" onclick="togglePasswordVisibility('password', this)" aria-label="Toggle password"
                              class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-hot transition-colors">
                        <i data-lucide="eye" class="w-5 h-5"></i>
                      </button>
                    </div>
                    <p class="text-xs text-slate-500">Leave blank to keep current password</p>
                  </div>

                  <div class="space-y-2">
                    <label for="confirm_password" class="block text-sm font-semibold text-ink">Confirm Password</label>
                    <div class="relative">
                      <i data-lucide="lock" class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400"></i>
                      <input type="password" id="confirm_password" name="confirm_password" placeholder="••••••••" autocomplete="new-password"
                             class="w-full pl-11 pr-12 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
                      <button type="button" onclick="togglePasswordVisibility('confirm_password', this)" aria-label="Toggle password"
                              class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-hot transition-colors">
                        <i data-lucide="eye" class="w-5 h-5"></i>
                      </button>
                    </div>
                    <p class="text-xs text-slate-500">Must match the password above</p>
                  </div>
                </div>
              </div>

              <div class="flex flex-col sm:flex-row justify-end items-center gap-3 pt-6 border-t-2 border-slate-100">
                <a href="profile.php"
                   class="inline-flex items-center justify-center gap-2 rounded-xl bg-white border-2 border-ink px-6 py-3 text-sm font-bold text-ink hover:bg-blush transition-colors w-full sm:w-auto">
                  <i data-lucide="x" class="w-4 h-4"></i>
                  <span>Close</span>
                </a>
                <button type="submit"
                        class="btn-hard inline-flex items-center justify-center gap-2 rounded-xl bg-hot px-8 py-3 text-sm font-bold text-white hover:bg-hotdark w-full sm:w-auto">
                  <i data-lucide="save" class="w-4 h-4"></i>
                  <span>Update</span>
                </button>
              </div>

            </form>
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

  <script>
    if (typeof lucide !== 'undefined') lucide.createIcons();

    function previewProfileImage(event) {
      const file = event.target.files && event.target.files[0];
      const previewEl = document.getElementById('profile-preview');
      const textEl    = document.getElementById('file-chosen-text');

      if (!file) return;

      const reader = new FileReader();
      reader.onload = function (e) {
        previewEl.src = e.target.result;
        previewEl.classList.remove('hidden');
      };
      reader.readAsDataURL(file);

      if (textEl) textEl.textContent = file.name;
    }

    function togglePasswordVisibility(inputId, btn) {
      const input = document.getElementById(inputId);
      if (!input) return;

      const isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';

      const icon = btn.querySelector('i');
      if (icon && typeof lucide !== 'undefined') {
        icon.setAttribute('data-lucide', isHidden ? 'eye-off' : 'eye');
        lucide.createIcons({ targets: [icon] });
      }
    }

    (function initCountrySelects() {
      const wrappers = document.querySelectorAll('[data-country-select]');

      wrappers.forEach(function (wrapper) {
        const trigger  = wrapper.querySelector('.country-select-trigger');
        const label    = wrapper.querySelector('.country-select-label');
        const panel    = wrapper.querySelector('.country-select-panel');
        const search   = wrapper.querySelector('.country-select-search');
        const options  = wrapper.querySelectorAll('.country-select-option');
        const emptyMsg = wrapper.querySelector('.country-select-empty');

        if (!trigger || !panel) return;

        trigger.addEventListener('click', function (e) {
          e.stopPropagation();
          document.querySelectorAll('.country-select-panel').forEach(function (p) {
            if (p !== panel) p.classList.add('hidden');
          });
          const isOpen = !panel.classList.contains('hidden');
          panel.classList.toggle('hidden', isOpen);
          if (!isOpen) setTimeout(function () { search && search.focus(); }, 30);
          else if (search) { search.value = ''; filterOptions(''); }
        });

        function filterOptions(term) {
          const t = term.trim().toLowerCase();
          let visibleCount = 0;
          options.forEach(function (opt) {
            const haystack = opt.getAttribute('data-search') || '';
            const match = t === '' || haystack.indexOf(t) !== -1;
            opt.style.display = match ? '' : 'none';
            if (match) visibleCount++;
          });
          if (emptyMsg) emptyMsg.classList.toggle('hidden', visibleCount !== 0);
        }

        if (search) {
          search.addEventListener('input', function () { filterOptions(search.value); });
          search.addEventListener('keydown', function (e) { if (e.key === 'Escape') panel.classList.add('hidden'); });
        }

        options.forEach(function (opt) {
          opt.addEventListener('click', function (e) {
            e.stopPropagation();
            const value = opt.getAttribute('data-value') || '';
            const lbl   = opt.getAttribute('data-label') || value;
            if (label) label.textContent = lbl || '--SELECT--';
            options.forEach(function (o) { o.classList.remove('bg-blush', 'text-hot', 'font-semibold'); });
            opt.classList.add('bg-blush', 'text-hot', 'font-semibold');
            panel.classList.add('hidden');
            if (search) search.value = '';
            filterOptions('');
          });
        });

        document.addEventListener('click', function (e) {
          if (!wrapper.contains(e.target)) panel.classList.add('hidden');
        });
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

    const logoutConfirmModal = document.getElementById('logoutConfirmModal');
    const logoutConfirmPanel = document.getElementById('logoutConfirmPanel');
    const confirmLogoutBtn   = document.getElementById('confirmLogoutBtn');
    const LOGOUT_URL = '../logout.php?role=parent';

    function openLogoutModal() {
      logoutConfirmModal.classList.remove('hidden');
      if (logoutConfirmPanel) { logoutConfirmPanel.classList.remove('animate-confirm-shake'); void logoutConfirmPanel.offsetWidth; logoutConfirmPanel.classList.add('animate-confirm-shake'); }
      setTimeout(function () { if (confirmLogoutBtn) confirmLogoutBtn.focus(); }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeLogoutModal() { logoutConfirmModal.classList.add('hidden'); }

    [document.getElementById('sidebarLogoutBtn'), document.getElementById('dropdownLogoutBtn')].forEach(function (btn) {
      if (!btn) return;
      btn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); openLogoutModal(); });
    });
    if (confirmLogoutBtn) confirmLogoutBtn.addEventListener('click', function () {
      confirmLogoutBtn.classList.add('opacity-50', 'pointer-events-none');
      window.location.href = LOGOUT_URL;
    });
    document.getElementById('logoutConfirmModal').addEventListener('click', function (e) { if (e.target.id === 'logoutConfirmModal') closeLogoutModal(); });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) closeLogoutModal();
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>