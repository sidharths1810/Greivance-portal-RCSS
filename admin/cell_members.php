<?php
/**
 * admin/cell_members.php
 * ---------------------------------------------------------------------------
 * Admin — Grievance Cell Member List
 * Rajagiri College Grievance Redressal Portal
 *
 * Database schema (from grievance_db.sql):
 *   cell_members: id, user_id(NOT NULL FK), designation_id(NOT NULL FK),
 *                 department_id(NULLABLE FK), member_type(ENUM NOT NULL),
 *                 grievance_type_id(NULLABLE FK), name, email, mobile_number
 *
 * Assigned Category (grievance_type_id) is intentionally not editable here —
 * it will be set to NULL for new members.
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
// 2. AUTH GUARD
// ---------------------------------------------------------------------------
$sessionRole = isset($_SESSION['role']) ? strtoupper((string) $_SESSION['role']) : '';

if (empty($_SESSION['user_id']) || $sessionRole !== 'ADMIN') {
    header('Location: ../login.php?role=admin');
    exit;
}

$userId = (int) $_SESSION['user_id'];

// ---------------------------------------------------------------------------
// 3. DATABASE CONNECTION
// ---------------------------------------------------------------------------
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

// ---------------------------------------------------------------------------
// 4. HELPERS
// ---------------------------------------------------------------------------
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function isValidMobile(string $mobile): bool
{
    return (bool) preg_match('/^[0-9]{10}$/', $mobile);
}

function memberTypeLabel(string $type): string
{
    $map = [
        'MANAGEMENT'       => 'MANAGEMENT',
        'GRIEVANCE_MEMBER' => 'GRIEVANCE MEMBER',
        'TEACHING'         => 'TEACHING',
        'NON_TEACHING'     => 'NON TEACHING',
        'PARENT'           => 'PARENT',
        'STUDENT'          => 'STUDENT',
    ];
    return $map[$type] ?? $type;
}

// ---------------------------------------------------------------------------
// 5. FETCH ADMIN PROFILE
// ---------------------------------------------------------------------------
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
        error_log('[Cell Members Admin Profile] ' . $ex->getMessage());
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

// ---------------------------------------------------------------------------
// 6. FETCH ACTIVE DROPDOWN OPTIONS (Designations + Departments only)
// ---------------------------------------------------------------------------
$designationOptions = [];
$departmentOptions  = [];

if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, designation_name FROM designations WHERE status = 'Active' ORDER BY designation_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) $designationOptions[] = $row;
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Designations] ' . $ex->getMessage());
    }

    try {
        $res = $conn->query("SELECT id, department_name FROM departments WHERE status = 'Active' ORDER BY department_name ASC");
        if ($res) {
            while ($row = $res->fetch_assoc()) $departmentOptions[] = $row;
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Departments] ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// 7. HANDLE FORM SUBMISSIONS
// ---------------------------------------------------------------------------
$flashSuccess = '';
$flashError   = '';

// Map cell_members.member_type → users.role enum
// users.role ENUM('ADMIN','STUDENT','PARENT','TEACHER','NON_TEACHING','MANAGEMENT')
$roleMap = [
    'MANAGEMENT'       => 'MANAGEMENT',
    'GRIEVANCE_MEMBER' => 'MANAGEMENT',
    'TEACHING'         => 'TEACHER',
    'NON_TEACHING'     => 'NON_TEACHING',
    'PARENT'           => 'PARENT',
    'STUDENT'          => 'STUDENT',
];

$validMemberTypes = ['MANAGEMENT', 'GRIEVANCE_MEMBER', 'TEACHING', 'NON_TEACHING', 'PARENT', 'STUDENT'];
$validStatuses    = ['Approved', 'Pending', 'Rejected', 'Terminated'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn instanceof mysqli) {

    $action = $_POST['action'] ?? '';

    // -------- ADD MEMBER --------
    if ($action === 'add_member') {
        $name            = trim((string) ($_POST['name']             ?? ''));
        $email           = trim((string) ($_POST['email']            ?? ''));
        $mobileNumber    = trim((string) ($_POST['mobile_number']    ?? ''));
        $username        = trim((string) ($_POST['username']         ?? ''));
        $password        = (string)       ($_POST['password']        ?? '');
        $memberType      = trim((string) ($_POST['member_type']      ?? 'STUDENT'));
        $designationId   = (int) ($_POST['designation_id']           ?? 0);
        $departmentId    = (int) ($_POST['department_id']            ?? 0);

        // Normalise nullable FK: 0 becomes NULL
        $departmentIdDb = ($departmentId > 0) ? $departmentId : null;

        if (!in_array($memberType, $validMemberTypes, true)) {
            $memberType = 'STUDENT';
        }

        if ($name === '' || $email === '' || $mobileNumber === '' || $username === '' || $password === '') {
            $flashError = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flashError = 'Please enter a valid email address.';
        } elseif (!isValidMobile($mobileNumber)) {
            $flashError = 'Mobile number must be exactly 10 digits.';
        } elseif ($designationId <= 0) {
            $flashError = 'Please select a designation.';
        }

        // Verify designation exists (required FK)
        if ($flashError === '') {
            try {
                $chk = $conn->prepare("SELECT id FROM designations WHERE id = ? LIMIT 1");
                $chk->bind_param('i', $designationId);
                $chk->execute();
                if ($chk->get_result()->num_rows === 0) {
                    $chk->close();
                    throw new Exception('Selected designation is not valid.');
                }
                $chk->close();

                if ($departmentIdDb !== null) {
                    $chk = $conn->prepare("SELECT id FROM departments WHERE id = ? LIMIT 1");
                    $chk->bind_param('i', $departmentIdDb);
                    $chk->execute();
                    if ($chk->get_result()->num_rows === 0) {
                        $chk->close();
                        throw new Exception('Selected department is not valid.');
                    }
                    $chk->close();
                }
            } catch (Throwable $ex) {
                $flashError = $ex->getMessage();
            }
        }

        if ($flashError === '') {
            try {
                $conn->begin_transaction();

                // Duplicate checks
                $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $chk->bind_param('s', $username);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $chk->close();
                    throw new Exception('Username already exists.');
                }
                $chk->close();

                $chk2 = $conn->prepare("SELECT id FROM cell_members WHERE email = ? LIMIT 1");
                $chk2->bind_param('s', $email);
                $chk2->execute();
                if ($chk2->get_result()->num_rows > 0) {
                    $chk2->close();
                    throw new Exception('Email is already registered for another member.');
                }
                $chk2->close();

                // Create user (mapped role)
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $userRole = $roleMap[$memberType] ?? 'STUDENT';

                $stmtU = $conn->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, ?, 'Approved')");
                $stmtU->bind_param('sss', $username, $hash, $userRole);
                $stmtU->execute();
                $newUserId = (int) $conn->insert_id;
                $stmtU->close();

                // Insert cell member (grievance_type_id always NULL)
                $stmtS = $conn->prepare("INSERT INTO cell_members (user_id, designation_id, department_id, member_type, grievance_type_id, name, email, mobile_number) VALUES (?, ?, ?, ?, NULL, ?, ?, ?)");
                $stmtS->bind_param(
                    'iiissss',
                    $newUserId,
                    $designationId,
                    $departmentIdDb,
                    $memberType,
                    $name,
                    $email,
                    $mobileNumber
                );

                if (!$stmtS->execute()) {
                    throw new Exception('Failed to add member details: ' . $stmtS->error);
                }
                $stmtS->close();

                $conn->commit();
                $flashSuccess = 'Member added successfully.';
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Add Member] ' . $ex->getMessage());
                $flashError = $ex->getMessage() ?: 'A system error occurred while adding the member.';
            }
        }
    }

    // -------- EDIT MEMBER --------
    if ($action === 'edit_member') {
        $memberId        = (int) ($_POST['member_id']      ?? 0);
        $name            = trim((string) ($_POST['name']             ?? ''));
        $email           = trim((string) ($_POST['email']            ?? ''));
        $mobileNumber    = trim((string) ($_POST['mobile_number']    ?? ''));
        $memberType      = trim((string) ($_POST['member_type']      ?? 'STUDENT'));
        $designationId   = (int) ($_POST['designation_id']           ?? 0);
        $departmentId    = (int) ($_POST['department_id']            ?? 0);

        $departmentIdDb = ($departmentId > 0) ? $departmentId : null;

        if (!in_array($memberType, $validMemberTypes, true)) {
            $memberType = 'STUDENT';
        }

        if ($memberId <= 0) {
            $flashError = 'Invalid member.';
        } elseif ($name === '' || $email === '' || $mobileNumber === '') {
            $flashError = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flashError = 'Please enter a valid email address.';
        } elseif (!isValidMobile($mobileNumber)) {
            $flashError = 'Mobile number must be exactly 10 digits.';
        } elseif ($designationId <= 0) {
            $flashError = 'Please select a designation.';
        }

        if ($flashError === '') {
            try {
                $conn->begin_transaction();

                $chk = $conn->prepare("SELECT id FROM cell_members WHERE email = ? AND id != ? LIMIT 1");
                $chk->bind_param('si', $email, $memberId);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $chk->close();
                    throw new Exception('Email is already registered for another member.');
                }
                $chk->close();

                // Update member (leave grievance_type_id untouched)
                $stmt = $conn->prepare("UPDATE cell_members SET designation_id = ?, department_id = ?, member_type = ?, name = ?, email = ?, mobile_number = ? WHERE id = ?");
                $stmt->bind_param(
                    'iissssi',
                    $designationId,
                    $departmentIdDb,
                    $memberType,
                    $name,
                    $email,
                    $mobileNumber,
                    $memberId
                );
                $stmt->execute();
                $stmt->close();

                // Sync role in users table
                $stmtG = $conn->prepare("SELECT user_id FROM cell_members WHERE id = ? LIMIT 1");
                $stmtG->bind_param('i', $memberId);
                $stmtG->execute();
                $resG = $stmtG->get_result();
                $linkedUserId = $resG && $resG->num_rows > 0 ? (int) ($resG->fetch_assoc()['user_id'] ?? 0) : 0;
                $stmtG->close();

                if ($linkedUserId > 0) {
                    $userRole = $roleMap[$memberType] ?? 'STUDENT';
                    $stmtU = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
                    $stmtU->bind_param('si', $userRole, $linkedUserId);
                    $stmtU->execute();
                    $stmtU->close();
                }

                $conn->commit();
                $flashSuccess = 'Member updated successfully.';
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Edit Member] ' . $ex->getMessage());
                $flashError = $ex->getMessage() ?: 'A system error occurred while updating the member.';
            }
        }
    }

    // -------- DELETE MEMBER --------
    if ($action === 'delete_member') {
        $memberId = (int) ($_POST['member_id'] ?? 0);
        if ($memberId > 0) {
            try {
                $conn->begin_transaction();

                $stmtG = $conn->prepare("SELECT user_id FROM cell_members WHERE id = ? LIMIT 1");
                $stmtG->bind_param('i', $memberId);
                $stmtG->execute();
                $resG = $stmtG->get_result();
                $linkedUserId = $resG && $resG->num_rows > 0 ? (int) ($resG->fetch_assoc()['user_id'] ?? 0) : 0;
                $stmtG->close();

                $stmtD = $conn->prepare("DELETE FROM cell_members WHERE id = ?");
                $stmtD->bind_param('i', $memberId);
                $stmtD->execute();
                $stmtD->close();

                if ($linkedUserId > 0) {
                    $stmtU = $conn->prepare("DELETE FROM users WHERE id = ?");
                    $stmtU->bind_param('i', $linkedUserId);
                    $stmtU->execute();
                    $stmtU->close();
                }

                $conn->commit();
                $flashSuccess = 'Member deleted successfully.';
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Delete Member] ' . $ex->getMessage());
                $flashError = 'A system error occurred while deleting the member.';
            }
        }
    }

    // -------- DEACTIVATE --------
    if ($action === 'deactivate_member') {
        $memberId = (int) ($_POST['member_id'] ?? 0);
        if ($memberId > 0) {
            try {
                $stmtG = $conn->prepare("SELECT user_id FROM cell_members WHERE id = ? LIMIT 1");
                $stmtG->bind_param('i', $memberId);
                $stmtG->execute();
                $resG = $stmtG->get_result();
                $linkedUserId = $resG && $resG->num_rows > 0 ? (int) ($resG->fetch_assoc()['user_id'] ?? 0) : 0;
                $stmtG->close();

                if ($linkedUserId > 0) {
                    $stmtU = $conn->prepare("UPDATE users SET status = 'Terminated' WHERE id = ?");
                    $stmtU->bind_param('i', $linkedUserId);
                    $stmtU->execute();
                    $stmtU->close();

                    $flashSuccess = 'Member deactivated successfully.';
                } else {
                    $flashError = 'Linked user account not found.';
                }
            } catch (Throwable $ex) {
                error_log('[Deactivate Member] ' . $ex->getMessage());
                $flashError = 'A system error occurred while deactivating the member.';
            }
        }
    }

    // -------- RESET PASSWORD --------
    if ($action === 'reset_password') {
        $memberId = (int) ($_POST['member_id'] ?? 0);
        if ($memberId > 0) {
            try {
                $stmtG = $conn->prepare("SELECT user_id FROM cell_members WHERE id = ? LIMIT 1");
                $stmtG->bind_param('i', $memberId);
                $stmtG->execute();
                $resG = $stmtG->get_result();
                $linkedUserId = $resG && $resG->num_rows > 0 ? (int) ($resG->fetch_assoc()['user_id'] ?? 0) : 0;
                $stmtG->close();

                if ($linkedUserId > 0) {
                    $newPassword = 'Member@' . random_int(1000, 9999);
                    $hash        = password_hash($newPassword, PASSWORD_BCRYPT);

                    $stmtU = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmtU->bind_param('si', $hash, $linkedUserId);
                    $stmtU->execute();
                    $stmtU->close();

                    $flashSuccess = 'Password reset successfully. New password: ' . $newPassword;
                } else {
                    $flashError = 'Linked user account not found.';
                }
            } catch (Throwable $ex) {
                error_log('[Reset Password] ' . $ex->getMessage());
                $flashError = 'A system error occurred while resetting the password.';
            }
        }
    }

    if ($flashSuccess !== '' || $flashError !== '') {
        $_SESSION['flash_success'] = $flashSuccess;
        $_SESSION['flash_error']   = $flashError;

        $qFilterMemberType = urlencode((string) ($_POST['filter_member_type'] ?? 'ALL'));
        $qFilterStatus     = urlencode((string) ($_POST['filter_status']      ?? 'All'));

        header('Location: cell_members.php?member_type=' . $qFilterMemberType . '&status=' . $qFilterStatus);
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

// ---------------------------------------------------------------------------
// 8. FILTERS
// ---------------------------------------------------------------------------
$filterMemberType = isset($_GET['member_type']) ? (string) $_GET['member_type'] : 'ALL';
$filterStatus     = isset($_GET['status'])      ? (string) $_GET['status']      : 'All';

if (!in_array($filterMemberType, array_merge(['ALL'], $validMemberTypes), true)) {
    $filterMemberType = 'ALL';
}
if (!in_array($filterStatus, array_merge(['All'], $validStatuses), true)) {
    $filterStatus = 'All';
}

// ---------------------------------------------------------------------------
// 9. FETCH CELL MEMBERS (with joins)
// ---------------------------------------------------------------------------
$members = [];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  cm.id,
                        cm.user_id,
                        cm.designation_id,
                        cm.department_id,
                        cm.grievance_type_id,
                        cm.member_type,
                        cm.name,
                        cm.email,
                        cm.mobile_number,
                        u.username,
                        u.status,
                        d.designation_name,
                        dep.department_name,
                        gt.type_name
                FROM cell_members cm
                LEFT JOIN users u            ON cm.user_id            = u.id
                LEFT JOIN designations d     ON cm.designation_id     = d.id
                LEFT JOIN departments dep    ON cm.department_id      = dep.id
                LEFT JOIN grievance_types gt ON cm.grievance_type_id  = gt.id
                WHERE 1=1";

        $params = [];
        $types  = '';

        if ($filterMemberType !== 'ALL') {
            $sql .= " AND cm.member_type = ?";
            $params[] = $filterMemberType;
            $types   .= 's';
        }

        if ($filterStatus !== 'All') {
            $sql .= " AND u.status = ?";
            $params[] = $filterStatus;
            $types   .= 's';
        }

        $sql .= " ORDER BY cm.id ASC";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $members[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Cell Members] ' . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Grievance Cell Members — Admin | Rajagiri College Grievance Portal</title>
<link rel="icon" type="image/svg+xml" href="../public/favicon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,500;12..96,600;12..96,700;12..96,800&family=Figtree:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://unpkg.com/lucide@latest"></script>
<style>
:root{--pink:#DB0878;--pink2:#B70664;--purple:#4A154B;--green:#006837;--gold:#FFC93C;--ink:#1F0A24;--cream:#FFF9FC;--line:#eadfe8}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:#f8f7fa;color:var(--ink);font-family:Figtree,system-ui,sans-serif}h1,h2,h3,h4{font-family:"Bricolage Grotesque",sans-serif}
a{text-decoration:none;color:inherit}.app{min-height:100vh;display:flex}.sidebar{position:fixed;z-index:40;inset:0 auto 0 0;width:82px;background:linear-gradient(180deg,var(--purple),#65195f 55%,var(--green));display:flex;flex-direction:column;align-items:center;padding:18px 10px;box-shadow:12px 0 30px #32102b18}.brand-mini{width:48px;height:48px;border-radius:15px;background:#fff;display:grid;place-items:center;box-shadow:5px 5px 0 var(--gold);margin-bottom:30px}.brand-mini img{width:34px}.side-nav{display:flex;flex-direction:column;gap:12px;flex:1}.side-link{position:relative;width:52px;height:52px;border-radius:16px;display:grid;place-items:center;color:#fff8;transition:.2s}.side-link:hover,.side-link.active{background:#ffffff18;color:#fff;transform:translateY(-2px)}.side-link span{position:absolute;left:62px;white-space:nowrap;background:var(--ink);color:#fff;padding:8px 11px;border-radius:9px;font-size:12px;opacity:0;pointer-events:none;transform:translateX(-5px);transition:.18s;box-shadow:4px 4px 0 #0003}.side-link:hover span{opacity:1;transform:none}.main{margin-left:82px;min-height:100vh;width:calc(100% - 82px)}.topbar{height:76px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 clamp(18px,4vw,44px);position:sticky;top:0;z-index:30}.logos{display:flex;align-items:center;gap:16px}.logos img:first-child{height:43px;width:auto}.logos img:last-child{height:34px;width:auto;border-left:1px solid #ddd;padding-left:16px}.profile{position:relative}.profile-btn{border:0;background:#fff;display:flex;align-items:center;gap:10px;padding:7px 10px;border-radius:14px;cursor:pointer}.profile-btn:hover{background:#faf3f8}.avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--purple),var(--pink));display:grid;place-items:center;color:#fff;font-weight:800;overflow:hidden}.avatar img{width:100%;height:100%;object-fit:cover}.profile-menu{position:absolute;right:0;top:54px;width:255px;background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 20px 45px #32102b20;padding:8px;display:none}.profile-menu.open{display:block;animation:drop .18s ease}.profile-info{padding:12px;border-radius:12px;background:#fff7fb;margin-bottom:5px}.menu-item{display:flex;align-items:center;gap:10px;padding:11px 12px;border-radius:10px;font-size:14px;font-weight:600}.menu-item:hover{background:#fff0f7;color:var(--pink2)}.content{padding:34px clamp(18px,4vw,44px) 48px}.container{max-width:1180px;margin:auto}.eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:12px;text-transform:uppercase;letter-spacing:.16em;font-weight:800;color:var(--pink2);margin-bottom:8px}.hero{display:flex;justify-content:space-between;align-items:end;gap:20px;margin-bottom:26px}.hero h1{font-size:clamp(28px,4vw,42px);line-height:1;margin:0 0 10px}.hero p{margin:0;color:#776b78;max-width:680px}.crumbs{display:flex;gap:8px;align-items:center;font-size:13px;color:#8b7f8c;margin-top:13px}.crumbs a:hover{color:var(--pink2)}.btn{border:0;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:12px 18px;border-radius:13px;font-weight:800;font-family:inherit;transition:.2s}.btn-primary{background:var(--pink);color:#fff;box-shadow:5px 5px 0 var(--purple)}.btn-primary:hover{background:var(--pink2);transform:translate(-1px,-1px);box-shadow:7px 7px 0 var(--purple)}.btn-dark{background:var(--purple);color:#fff;box-shadow:4px 4px 0 var(--gold)}.btn-light{background:#fff;border:1px solid var(--line);color:#514450}.btn-danger{background:#c92b45;color:#fff}.btn:hover{transform:translateY(-1px)}.flash{padding:14px 16px;border-radius:14px;margin-bottom:18px;display:flex;align-items:flex-start;gap:11px;font-weight:600}.flash.success{background:#eaf8f0;border:1px solid #b8e5ca;color:#145c34}.flash.error{background:#fff0f1;border:1px solid #f2c0c5;color:#9c2635}.panel{background:#fff;border:1px solid var(--line);border-radius:22px;box-shadow:0 12px 35px #32102b0b;overflow:hidden}.panel-head{padding:20px 22px;border-bottom:1px solid #eee5eb;display:flex;align-items:center;justify-content:space-between;gap:15px}.panel-title{font:700 20px "Bricolage Grotesque";margin:0}.filter{background:linear-gradient(135deg,#fff4fa,#fbf9ff);border:1px solid #eadbe8;border-radius:18px;padding:17px;margin-bottom:20px}.filter-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:12px}.field{display:flex;flex-direction:column;gap:7px}.field label{font-size:12px;font-weight:800;color:#5e5360}.input,.select{width:100%;border:1.5px solid #ddd1dc;border-radius:12px;background:#fff;padding:12px 13px;font:500 14px Figtree;color:var(--ink);outline:0;transition:.18s}.input:focus,.select:focus{border-color:var(--pink);box-shadow:0 0 0 4px #db087814}.table-wrap{overflow:auto}.table{width:100%;border-collapse:collapse;min-width:820px}.table th{padding:13px 16px;text-align:left;background:#fbf6fa;color:#756976;font-size:11px;letter-spacing:.09em;text-transform:uppercase}.table td{padding:15px 16px;border-top:1px solid #f0e9ef;font-size:14px;vertical-align:middle}.table tr:hover td{background:#fffafd}.person{display:flex;align-items:center;gap:11px}.person-avatar{width:38px;height:38px;border-radius:12px;background:#ffe3f1;color:var(--pink2);display:grid;place-items:center;font-weight:800}.badge{display:inline-flex;align-items:center;padding:6px 9px;border-radius:999px;border:1px solid;font-size:11px;font-weight:800}.approved{background:#eaf8f0;color:#126136;border-color:#c6ead3}.pending{background:#fff7df;color:#89620a;border-color:#f1dda1}.rejected{background:#fff0f1;color:#9c2635;border-color:#f0c4ca}.terminated{background:#f0eef1;color:#5e5661;border-color:#ddd7df}.actions{display:flex;justify-content:flex-end;gap:6px}.icon-btn{width:34px;height:34px;border:1px solid #e7dce6;border-radius:10px;background:#fff;display:grid;place-items:center;color:#655968;cursor:pointer;transition:.18s}.icon-btn:hover{background:#fff0f7;color:var(--pink2);border-color:#f1bdd8;transform:translateY(-1px)}.icon-btn.danger:hover{background:#fff0f1;color:#c92b45;border-color:#efc2c8}.empty{padding:60px 20px;text-align:center;color:#7b707d}.empty-icon{width:66px;height:66px;margin:0 auto 14px;border-radius:20px;background:#fff0f7;color:var(--pink2);display:grid;place-items:center}.modal{position:fixed;inset:0;z-index:80;display:none;align-items:center;justify-content:center;padding:18px}.modal.show{display:flex}.backdrop{position:absolute;inset:0;background:#1f0a2490;backdrop-filter:blur(6px)}.modal-card{position:relative;width:min(700px,100%);max-height:92vh;overflow:auto;background:#fff;border-radius:24px;box-shadow:0 30px 80px #0005;animation:pop .22s ease}.modal-accent{height:6px;background:linear-gradient(90deg,var(--purple),var(--pink),var(--gold))}.modal-head{padding:19px 22px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #eee5eb}.modal-head h3{margin:0;font-size:21px}.modal-body{padding:22px}.modal-actions{display:flex;justify-content:flex-end;gap:10px;padding-top:18px}.danger-card{width:min(430px,100%);text-align:center}.danger-icon{width:68px;height:68px;border-radius:20px;background:#fff0f1;color:#c92b45;display:grid;place-items:center;margin:24px auto 14px}.danger-card h3{margin:0 20px 8px;font-size:23px}.danger-card p{color:#766b77;margin:0 24px 20px;line-height:1.55}.footer{padding:24px 0;color:#8b7f8c;font-size:12px;text-align:center}.cards{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}.course-block{margin-bottom:30px}.course-head{display:flex;align-items:center;gap:10px;margin:0 0 13px;font-size:18px}.course-dot{width:10px;height:10px;border-radius:50%;background:var(--pink);box-shadow:0 0 0 6px #db087812}.class-card{position:relative;min-height:115px;background:#fff;border:1px solid var(--line);border-radius:20px;padding:17px;box-shadow:0 10px 25px #32102b0a;transition:.2s;display:flex;flex-direction:column;justify-content:space-between}.class-card:hover{transform:translateY(-4px);border-color:#e6a3c5;box-shadow:0 18px 35px #32102b16}.class-top{display:flex;justify-content:space-between;align-items:center;gap:8px}.count{display:inline-flex;align-items:center;gap:6px;background:#fff0f7;color:var(--pink2);border-radius:999px;padding:6px 9px;font-size:12px;font-weight:800}.class-name{font:700 18px "Bricolage Grotesque";margin:15px 0 2px}.class-course{font-size:11px;color:#8a7d89;text-transform:uppercase;letter-spacing:.08em;font-weight:800}.class-actions{display:flex;justify-content:flex-end;gap:5px}.notice{padding:13px 16px;background:#fff8e6;border:1px solid #f0dd9e;border-radius:14px;color:#745b12;font-size:13px;font-weight:600;margin-bottom:20px}.password-layout{display:grid;grid-template-columns:1fr 280px;gap:20px}.security-card{background:linear-gradient(145deg,var(--purple),#76266e);color:#fff;border-radius:22px;padding:25px;box-shadow:7px 7px 0 var(--gold)}.security-card h3{font-size:24px;margin:14px 0 8px}.security-card p{color:#f2ddec;line-height:1.6;font-size:14px}.security-list{list-style:none;padding:0;margin:20px 0 0}.security-list li{display:flex;gap:9px;margin:11px 0;font-size:13px;color:#fff}.strength{height:6px;border-radius:99px;background:#eee2eb;overflow:hidden;margin-top:7px}.strength i{display:block;height:100%;width:0;background:var(--green);transition:.2s}.match{font-size:12px;margin-top:6px}.srch{position:relative}.srch .input{padding-left:40px}.srch svg{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#978a97;width:17px}.pagination{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-top:1px solid #eee5eb;color:#786d79;font-size:12px}.pagination select{border:1px solid #ddd1dc;border-radius:8px;padding:5px;background:#fff}.hidden{display:none!important}
@keyframes drop{from{opacity:0;transform:translateY(-7px) scale(.98)}to{opacity:1;transform:none}}@keyframes pop{from{opacity:0;transform:scale(.97) translateY(7px)}to{opacity:1;transform:none}}
@media(max-width:900px){.cards{grid-template-columns:repeat(2,1fr)}.password-layout{grid-template-columns:1fr}.security-card{order:-1}.hero{align-items:flex-start;flex-direction:column}.filter-grid{grid-template-columns:1fr 1fr}.filter-grid>*{grid-column:span 1!important}}
@media(max-width:640px){.sidebar{width:64px;padding:12px 7px}.brand-mini{width:42px;height:42px;margin-bottom:18px}.brand-mini img{width:29px}.side-link{width:46px;height:46px}.main{margin-left:64px;width:calc(100% - 64px)}.topbar{height:68px;padding:0 14px}.logos img:first-child{height:34px}.logos img:last-child{height:27px;padding-left:9px}.profile-btn{padding:5px}.profile-btn>span,.profile-btn>svg{display:none}.content{padding:24px 13px 35px}.hero h1{font-size:30px}.cards{grid-template-columns:1fr}.filter-grid{grid-template-columns:1fr}.filter-grid>*{grid-column:span 1!important}.modal{padding:10px}.modal-body{padding:17px}.modal-actions{flex-direction:column-reverse}.modal-actions .btn{width:100%}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation:none!important;transition:none!important;scroll-behavior:auto!important}}
</style>
</head>
<body>
<div class="app">
<aside class="sidebar">
<a class="brand-mini" href="dashboard.php" title="RCSS Admin"><img src="../public/rcss-logo.png" alt="RCSS"></a>
<nav class="side-nav">
<a class="side-link" href="dashboard.php"><i data-lucide="layout-dashboard"></i><span>Dashboard</span></a>
<a class="side-link" href="profile.php"><i data-lucide="user-round"></i><span>Profile</span></a>
<a class="side-link" href="settings.php"><i data-lucide="settings"></i><span>Settings</span></a>
</nav>
<a class="side-link" href="../logout.php?role=admin" id="sideLogout" title="Logout"><i data-lucide="log-out"></i><span>Logout</span></a>
</aside>
<main class="main">
<header class="topbar">
<div class="logos"><a href="dashboard.php"><img src="../public/rcss-logo.png" alt="RCSS Logo"></a><img src="../public/orel-grievance.png" alt="Oréll Grievance"></div>
<div class="profile">
<button class="profile-btn" id="profileBtn" aria-expanded="false">
<div class="avatar"><?php if (!empty($hasProfilePicture)): ?><img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>"><?php else: ?><i data-lucide="user"></i><?php endif; ?></div>
<span><?= e($displayName ?? ($_SESSION['username'] ?? 'Admin')) ?></span><i data-lucide="chevron-down"></i>
</button>
<div class="profile-menu" id="profileMenu">
<div class="profile-info"><strong><?= e($displayName ?? ($_SESSION['username'] ?? 'Admin')) ?></strong><div style="font-size:12px;color:#887c88;margin-top:3px"><?= e($displayEmail ?? 'admin@rajagiri.edu') ?></div></div>
<a class="menu-item" href="dashboard.php"><i data-lucide="layout-dashboard"></i>Dashboard</a>
<a class="menu-item" href="profile.php"><i data-lucide="user"></i>Profile</a>
<a class="menu-item" href="change_password.php"><i data-lucide="key-round"></i>Change Password</a>
<a class="menu-item" href="settings.php"><i data-lucide="settings"></i>Settings</a>
<a class="menu-item" href="../logout.php?role=admin" id="dropLogout" style="color:#b42338"><i data-lucide="log-out"></i>Logout</a>
</div></div>
</header>

<section class="content"><div class="container">
<div class="hero"><div><div class="eyebrow"><i data-lucide="users"></i> Grievance Administration</div><h1>Cell Members</h1><p>Manage grievance cell members, roles, account access and contact details from one place.</p><div class="crumbs"><a href="dashboard.php">Dashboard</a><span>/</span><a href="members.php">Members</a><span>/</span><span>Cell Members</span></div></div><button class="btn btn-primary" type="button" onclick="openMemberModal('add')"><i data-lucide="plus"></i>Add Member</button></div>
<?php if($flashSuccess!==''): ?><div class="flash success"><i data-lucide="circle-check"></i><?= e($flashSuccess) ?></div><?php endif; ?>
<?php if($flashError!==''): ?><div class="flash error"><i data-lucide="circle-alert"></i><?= e($flashError) ?></div><?php endif; ?>
<div class="filter"><form method="GET" action="cell_members.php"><div class="filter-grid">
<div class="field" style="grid-column:span 4"><label for="filter_member_type">Member Type</label><select class="select" name="member_type" id="filter_member_type"><option value="ALL" <?= $filterMemberType==='ALL'?'selected':'' ?>>All member types</option><option value="MANAGEMENT" <?= $filterMemberType==='MANAGEMENT'?'selected':'' ?>>Management</option><option value="GRIEVANCE_MEMBER" <?= $filterMemberType==='GRIEVANCE_MEMBER'?'selected':'' ?>>Grievance Member</option><option value="TEACHING" <?= $filterMemberType==='TEACHING'?'selected':'' ?>>Teaching</option><option value="NON_TEACHING" <?= $filterMemberType==='NON_TEACHING'?'selected':'' ?>>Non Teaching</option><option value="PARENT" <?= $filterMemberType==='PARENT'?'selected':'' ?>>Parent</option><option value="STUDENT" <?= $filterMemberType==='STUDENT'?'selected':'' ?>>Student</option></select></div>
<div class="field" style="grid-column:span 4"><label for="status">Account Status</label><select class="select" name="status" id="status"><option value="All" <?= $filterStatus==='All'?'selected':'' ?>>All statuses</option><option value="Approved" <?= $filterStatus==='Approved'?'selected':'' ?>>Approved</option><option value="Pending" <?= $filterStatus==='Pending'?'selected':'' ?>>Pending</option><option value="Rejected" <?= $filterStatus==='Rejected'?'selected':'' ?>>Rejected</option><option value="Terminated" <?= $filterStatus==='Terminated'?'selected':'' ?>>Terminated</option></select></div>
<div style="grid-column:span 4;display:flex;align-items:end;gap:9px"><button class="btn btn-dark" type="submit" style="flex:1"><i data-lucide="filter"></i>Apply Filters</button><a class="btn btn-light" href="cell_members.php" title="Reset filters"><i data-lucide="rotate-ccw"></i></a></div>
</div></form></div>
<div class="panel">
<div class="panel-head"><div><h2 class="panel-title">Member Directory</h2><div style="font-size:12px;color:#897d89;margin-top:4px">Review and manage registered grievance cell accounts.</div></div><div class="srch" style="width:min(280px,45vw)"><i data-lucide="search"></i><input class="input" id="searchInput" placeholder="Search members..." aria-label="Search members"></div></div>
<div class="table-wrap"><table class="table" id="membersTable"><thead><tr><th>#</th><th>Member</th><th>Type</th><th>Contact</th><th>Mobile</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead><tbody id="membersTableBody">
<?php if(empty($members)): ?><tr><td colspan="7"><div class="empty"><div class="empty-icon"><i data-lucide="users-round"></i></div><h3 style="margin:0 0 6px;color:var(--ink)">No members found</h3><p style="margin:0">Try another filter or add a new member.</p></div></td></tr>
<?php else: foreach($members as $index=>$member): $memberId=(int)$member['id'];$memberName=(string)($member['name']??'');$memberType=(string)($member['member_type']??'');$memberEmail=(string)($member['email']??'');$memberMobile=(string)($member['mobile_number']??'');$memberStatus=(string)($member['status']??'Approved');$memberUser=(string)($member['username']??'');$memberDesigId=(int)($member['designation_id']??0);$memberDeptId=(int)($member['department_id']??0);$statusKey=strtolower($memberStatus);$statusCls=in_array($statusKey,['approved','pending','rejected','terminated'],true)?$statusKey:'pending'; ?>
<tr class="member-row" data-search="<?= e(strtolower($memberName.' '.$memberEmail.' '.$memberMobile.' '.memberTypeLabel($memberType).' '.$memberStatus)) ?>">
<td style="color:#8a7e89;font-weight:700"><?= $index+1 ?></td><td><div class="person"><div class="person-avatar"><?= e(strtoupper(mb_substr($memberName,0,1))) ?></div><div><strong><?= e($memberName) ?></strong><div style="font-size:11px;color:#938792">@<?= e($memberUser) ?></div></div></div></td><td><span style="font-weight:700"><?= e(memberTypeLabel($memberType)) ?></span></td><td><div><?= e($memberEmail) ?></div></td><td><?= e($memberMobile!==''?$memberMobile:'—') ?></td><td><span class="badge <?= $statusCls ?>"><?= e($memberStatus) ?></span></td><td><div class="actions">
<button class="icon-btn" title="Reset password" onclick='confirmResetPassword(<?= $memberId ?>,<?= json_encode($memberName) ?>)'><i data-lucide="key-round"></i></button>
<button class="icon-btn" title="Edit member" onclick='openMemberModal("edit",<?= $memberId ?>,<?= json_encode($memberName) ?>,<?= json_encode($memberType) ?>,<?= $memberDesigId ?>,<?= $memberDeptId ?>,<?= json_encode($memberEmail) ?>,<?= json_encode($memberMobile) ?>)'><i data-lucide="pencil"></i></button>
<button class="icon-btn" title="Deactivate member" onclick='confirmDeactivate(<?= $memberId ?>,<?= json_encode($memberName) ?>)'><i data-lucide="user-round-minus"></i></button>
<button class="icon-btn danger" title="Delete member" onclick='confirmDeleteMember(<?= $memberId ?>,<?= json_encode($memberName) ?>)'><i data-lucide="trash-2"></i></button>
</div></td></tr>
<?php endforeach; endif; ?></tbody></table></div>
<div class="pagination"><span id="tableInfo"></span><label>Rows <select id="entriesPerPage"><option>10</option><option>25</option><option>50</option></select></label></div>
</div></div></section>

<div class="modal" id="memberModal"><div class="backdrop" onclick="closeMemberModal()"></div><div class="modal-card"><div class="modal-accent"></div><div class="modal-head"><div><div class="eyebrow" style="margin:0">Member account</div><h3 id="memberModalTitle">Add Member</h3></div><button class="icon-btn" onclick="closeMemberModal()" type="button"><i data-lucide="x"></i></button></div>
<form id="memberForm" method="POST" action="cell_members.php" class="modal-body"><input type="hidden" name="action" id="formAction" value="add_member"><input type="hidden" name="member_id" id="formMemberId"><input type="hidden" name="filter_member_type" value="<?= e($filterMemberType) ?>"><input type="hidden" name="filter_status" value="<?= e($filterStatus) ?>">
<?php if(empty($designationOptions)): ?><div class="notice" style="margin-bottom:17px"><i data-lucide="triangle-alert"></i> No active designations are available. Add a designation before creating members.</div><?php endif; ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:15px"><div class="field"><label for="name">Full Name *</label><input class="input" id="name" name="name" required placeholder="Full name"></div><div class="field"><label for="member_type">Member Type *</label><select class="select" id="member_type" name="member_type" required><option value="STUDENT">Student</option><option value="PARENT">Parent</option><option value="TEACHING">Teaching</option><option value="NON_TEACHING">Non Teaching</option><option value="GRIEVANCE_MEMBER">Grievance Member</option><option value="MANAGEMENT">Management</option></select></div></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-top:15px"><div class="field"><label for="designation_id">Designation *</label><select class="select" id="designation_id" name="designation_id" required><option value="">-- Select Designation --</option><?php foreach($designationOptions as $opt): ?><option value="<?= (int)$opt['id'] ?>"><?= e($opt['designation_name']) ?></option><?php endforeach; ?></select></div><div class="field"><label for="department_id">Department <small>(optional)</small></label><select class="select" id="department_id" name="department_id"><option value="">-- None --</option><?php foreach($departmentOptions as $opt): ?><option value="<?= (int)$opt['id'] ?>"><?= e($opt['department_name']) ?></option><?php endforeach; ?></select></div></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-top:15px"><div class="field"><label for="email">Email *</label><input class="input" id="email" name="email" type="email" required placeholder="member@rajagiri.edu"></div><div class="field"><label for="mobile_number">Mobile Number *</label><input class="input" id="mobile_number" name="mobile_number" type="tel" inputmode="numeric" pattern="[0-9]{10}" maxlength="10" required placeholder="10 digit mobile"></div></div>
<div id="credentialsBlock" style="display:grid;grid-template-columns:1fr 1fr;gap:15px;margin-top:15px"><div class="field"><label for="username">Username *</label><input class="input" id="username" name="username" required placeholder="Login username"></div><div class="field" id="passwordFieldWrapper"><label for="password">Password *</label><div style="position:relative"><input class="input" style="padding-right:45px" id="password" name="password" type="password" placeholder="Initial password"><button type="button" class="icon-btn" id="togglePasswordBtn" style="position:absolute;right:7px;top:6px"><i data-lucide="eye" id="togglePasswordIcon"></i></button></div></div></div>
<div class="modal-actions"><button type="button" class="btn btn-light" onclick="closeMemberModal()">Cancel</button><button type="submit" class="btn btn-primary"><i data-lucide="save"></i>Save Member</button></div></form></div></div>

<div class="modal" id="confirmModal"><div class="backdrop" onclick="closeConfirmModal()"></div><div class="modal-card danger-card"><div class="modal-accent"></div><div class="danger-icon" id="confirmIcon"><i data-lucide="triangle-alert"></i></div><h3 id="confirmTitle">Confirm action</h3><p id="confirmText"></p><div class="modal-actions" style="padding:0 22px 22px"><button class="btn btn-light" onclick="closeConfirmModal()">Cancel</button><button class="btn btn-danger" id="confirmActionBtn">Confirm</button></div></div></div>
<form id="actionForm" method="POST" action="cell_members.php" class="hidden"><input type="hidden" name="action" id="actionType"><input type="hidden" name="member_id" id="actionMemberId"></form>

<script>
const memberModal=document.getElementById('memberModal'),memberForm=document.getElementById('memberForm'),formAction=document.getElementById('formAction'),formMemberId=document.getElementById('formMemberId'),nameInput=document.getElementById('name'),memberTypeInput=document.getElementById('member_type'),designationInput=document.getElementById('designation_id'),departmentInput=document.getElementById('department_id'),emailInput=document.getElementById('email'),mobileInput=document.getElementById('mobile_number'),usernameInput=document.getElementById('username'),passwordInput=document.getElementById('password'),credentialsBlock=document.getElementById('credentialsBlock');
function openMemberModal(mode,id,name,type,desig,dept,email,mobile){memberModal.classList.add('show');document.body.style.overflow='hidden';if(mode==='edit'){document.getElementById('memberModalTitle').textContent='Edit Member';formAction.value='edit_member';formMemberId.value=id||'';nameInput.value=name||'';memberTypeInput.value=type||'STUDENT';designationInput.value=String(desig||'');departmentInput.value=String(dept||'');emailInput.value=email||'';mobileInput.value=mobile||'';credentialsBlock.style.display='none';usernameInput.required=false;passwordInput.required=false;}else{document.getElementById('memberModalTitle').textContent='Add Member';formAction.value='add_member';formMemberId.value='';memberForm.reset();memberTypeInput.value='STUDENT';credentialsBlock.style.display='grid';usernameInput.required=true;passwordInput.required=true;}setTimeout(()=>nameInput.focus(),70);lucide.createIcons();}
function closeMemberModal(){memberModal.classList.remove('show');document.body.style.overflow='';memberForm.reset();formAction.value='add_member';formMemberId.value='';credentialsBlock.style.display='grid';usernameInput.required=true;passwordInput.required=true;}
document.getElementById('togglePasswordBtn').addEventListener('click',()=>{passwordInput.type=passwordInput.type==='password'?'text':'password';lucide.createIcons();});
mobileInput.addEventListener('input',()=>mobileInput.value=mobileInput.value.replace(/\D/g,'').slice(0,10));
const confirmModal=document.getElementById('confirmModal'),actionForm=document.getElementById('actionForm'),actionType=document.getElementById('actionType'),actionMemberId=document.getElementById('actionMemberId'),confirmActionBtn=document.getElementById('confirmActionBtn');
let pendingAction=null;
function askAction(action,id,name){pendingAction={action,id};const title={delete_member:'Delete member?',deactivate_member:'Deactivate member?',reset_password:'Reset password?'}[action];const text={delete_member:'You are about to permanently delete ',deactivate_member:'The account for ',reset_password:'Generate a new password for '}[action];document.getElementById('confirmTitle').textContent=title;document.getElementById('confirmText').textContent=text+'"'+name+'".';confirmModal.classList.add('show');document.body.style.overflow='hidden';setTimeout(()=>confirmActionBtn.focus(),50);}
function closeConfirmModal(){confirmModal.classList.remove('show');document.body.style.overflow='';pendingAction=null;}
function confirmDeleteMember(id,name){askAction('delete_member',id,name)}function confirmDeactivate(id,name){askAction('deactivate_member',id,name)}function confirmResetPassword(id,name){askAction('reset_password',id,name)}
confirmActionBtn.addEventListener('click',()=>{if(!pendingAction)return;actionType.value=pendingAction.action;actionMemberId.value=pendingAction.id;actionForm.submit();});
const rows=[...document.querySelectorAll('.member-row')],search=document.getElementById('searchInput'),per=document.getElementById('entriesPerPage'),info=document.getElementById('tableInfo');let page=1;
function renderRows(){const q=(search.value||'').toLowerCase();const filtered=rows.filter(r=>r.dataset.search.includes(q));const n=parseInt(per.value,10);const pages=Math.max(1,Math.ceil(filtered.length/n));page=Math.min(page,pages);rows.forEach(r=>r.style.display='none');filtered.slice((page-1)*n,page*n).forEach(r=>r.style.display='table-row');info.textContent=filtered.length?`Showing ${(page-1)*n+1}-${Math.min(page*n,filtered.length)} of ${filtered.length} members`:'No matching members';}
search?.addEventListener('input',()=>{page=1;renderRows()});per?.addEventListener('change',()=>{page=1;renderRows()});renderRows();
setTimeout(()=>document.querySelectorAll('.flash').forEach(x=>x.remove()),3500);
</script>
<style>@media(max-width:640px){#memberForm>div[style*="grid-template-columns"]{grid-template-columns:1fr!important}#credentialsBlock{grid-template-columns:1fr!important}}</style>
<footer class="footer">Rajagiri College Grievance Redressal Portal · Powered by <strong style="color:var(--pink2)">Oréll</strong></footer>
</main></div>
<script>
lucide.createIcons();
const profileBtn=document.getElementById('profileBtn'), profileMenu=document.getElementById('profileMenu');
profileBtn?.addEventListener('click',e=>{e.stopPropagation();profileMenu.classList.toggle('open');profileBtn.setAttribute('aria-expanded',profileMenu.classList.contains('open'));});
document.addEventListener('click',e=>{if(!e.target.closest('.profile')){profileMenu?.classList.remove('open');profileBtn?.setAttribute('aria-expanded','false');}});
document.addEventListener('keydown',e=>{if(e.key==='Escape'){profileMenu?.classList.remove('open');document.querySelectorAll('.modal.show').forEach(m=>m.classList.remove('show'));document.body.style.overflow='';}});
document.querySelectorAll('#sideLogout,#dropLogout').forEach(b=>b?.addEventListener('click',e=>{if(!confirm('Are you sure you want to log out?'))e.preventDefault();}));
</script>
</body></html>
