<?php
/**
 * admin/summary_details.php
 * ---------------------------------------------------------------------------
 * Admin — Summary Details (Grievance Listing + Create + Bulk Upload + View + Edit + Delete)
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
$allowedRoles = ['ADMIN', 'MANAGEMENT', 'TEACHER'];

if (empty($_SESSION['user_id']) || !in_array($sessionRole, $allowedRoles, true)) {
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

function statusBadgeClass(string $status): string
{
    return match (strtolower($status)) {
        'pending'     => 'bg-[#FFF5D8] text-[#9A6500] border-[#F1DDA1]',
        'in progress' => 'bg-sky-50 text-sky-800 border-sky-200',
        'disposed'    => 'bg-[#DCEFE4] text-leaf border-leaf',
        'closed'      => 'bg-slate-100 text-slate-700 border-slate-300',
        'reopened'    => 'bg-[#FFE0F0] text-hot border-hot',
        default       => 'bg-slate-100 text-slate-700 border-slate-300',
    };
}

function roleLabel(string $role): string
{
    $map = [
        'STUDENT'      => 'STUDENT',
        'PARENT'       => 'PARENT',
        'TEACHER'      => 'TEACHER',
        'NON_TEACHING' => 'NON TEACHING',
        'MANAGEMENT'   => 'MANAGEMENT',
        'ADMIN'        => 'ADMIN',
    ];
    return $map[$role] ?? $role;
}

function generateGrievanceNumber(mysqli $conn): string
{
    $prefix = 'GRV-' . date('Y') . '-';
    do {
        $suffix = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $candidate = $prefix . $suffix;

        $chk = $conn->prepare("SELECT id FROM grievances WHERE grievance_number = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param('s', $candidate);
            $chk->execute();
            $exists = $chk->get_result()->num_rows > 0;
            $chk->close();
        } else {
            $exists = false;
        }
    } while ($exists);

    return $candidate;
}

function resolveComplainantUser(mysqli $conn, string $role, string $name, string $cellNo, string $classOrDept): int
{
    $role = strtoupper($role);

    $validRoles = ['STUDENT', 'PARENT', 'TEACHER', 'NON_TEACHING', 'MANAGEMENT'];
    if (!in_array($role, $validRoles, true)) {
        $role = 'STUDENT';
    }

    $userRoleEnum = $role;

    $profileTable = '';
    switch ($role) {
        case 'STUDENT':      $profileTable = 'students';     break;
        case 'PARENT':       $profileTable = 'parents';      break;
        case 'TEACHER':      $profileTable = 'cell_members'; break;
        case 'NON_TEACHING': $profileTable = 'cell_members'; break;
        case 'MANAGEMENT':   $profileTable = 'cell_members'; break;
    }

    if ($profileTable !== '') {
        $sql = "SELECT u.id
                FROM users u
                INNER JOIN {$profileTable} p ON p.user_id = u.id
                WHERE u.role = ? AND p.name = ?
                LIMIT 1";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ss', $userRoleEnum, $name);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $existingId = (int) $res->fetch_assoc()['id'];
                $stmt->close();
                return $existingId;
            }
            $stmt->close();
        }
    }

    $baseUsername = strtolower(preg_replace('/[^a-z0-9]+/i', '.', $name));
    $baseUsername = trim($baseUsername, '.');
    if ($baseUsername === '') $baseUsername = 'user';

    $username = $baseUsername;
    $i = 1;
    while (true) {
        $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $chk->bind_param('s', $username);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            $chk->close();
            break;
        }
        $chk->close();
        $username = $baseUsername . $i;
        $i++;
        if ($i > 1000) {
            $username = $baseUsername . '_' . bin2hex(random_bytes(3));
            break;
        }
    }

    $defaultPassword = password_hash('User@' . random_int(1000, 9999), PASSWORD_BCRYPT);

    $stmtU = $conn->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, ?, 'Approved')");
    if (!$stmtU) {
        throw new Exception('Failed to prepare user insert.');
    }
    $stmtU->bind_param('sss', $username, $defaultPassword, $userRoleEnum);
    $stmtU->execute();
    $newUserId = (int) $conn->insert_id;
    $stmtU->close();

    switch ($role) {
        case 'STUDENT':
            $classId = null;
            if ($classOrDept !== '') {
                $chkC = $conn->prepare("SELECT id FROM classes WHERE class_name = ? LIMIT 1");
                $chkC->bind_param('s', $classOrDept);
                $chkC->execute();
                $resC = $chkC->get_result();
                if ($resC && $resC->num_rows > 0) {
                    $classId = (int) $resC->fetch_assoc()['id'];
                }
                $chkC->close();
            }

            if ($classId === null) {
                $resFirst = $conn->query("SELECT id FROM classes ORDER BY id ASC LIMIT 1");
                if ($resFirst && $resFirst->num_rows > 0) {
                    $classId = (int) $resFirst->fetch_assoc()['id'];
                }
            }

            if ($classId === null) {
                throw new Exception('Cannot create student: no classes exist. Please add a class first.');
            }

            $stmtS = $conn->prepare("INSERT INTO students (user_id, class_id, name, email, contact_number) VALUES (?, ?, ?, ?, ?)");
            if (!$stmtS) {
                throw new Exception('Failed to prepare student insert.');
            }
            $placeholderEmail = 'student_' . $newUserId . '@rajagiri.edu';
            $stmtS->bind_param('iisss', $newUserId, $classId, $name, $placeholderEmail, $cellNo);
            $stmtS->execute();
            $stmtS->close();
            break;

        case 'PARENT':
            $stmtP = $conn->prepare("INSERT INTO parents (user_id, name, email, contact_number) VALUES (?, ?, ?, ?)");
            if (!$stmtP) {
                throw new Exception('Failed to prepare parent insert.');
            }
            $placeholderEmail = 'parent_' . $newUserId . '@rajagiri.edu';
            $stmtP->bind_param('isss', $newUserId, $name, $placeholderEmail, $cellNo);
            $stmtP->execute();
            $stmtP->close();
            break;

        case 'TEACHER':
        case 'NON_TEACHING':
        case 'MANAGEMENT':
            $resD = $conn->query("SELECT id FROM designations WHERE status = 'Active' ORDER BY id ASC LIMIT 1");
            $designationId = 0;
            if ($resD && $resD->num_rows > 0) {
                $designationId = (int) $resD->fetch_assoc()['id'];
            }
            if ($designationId <= 0) {
                $defaultName = 'General Staff';
                $postOcc = 'Non Teaching';
                $stmtD = $conn->prepare("INSERT INTO designations (designation_name, post_occupied, status) VALUES (?, ?, 'Active')");
                if ($stmtD) {
                    $stmtD->bind_param('ss', $defaultName, $postOcc);
                    $stmtD->execute();
                    $designationId = (int) $conn->insert_id;
                    $stmtD->close();
                }
            }
            if ($designationId <= 0) {
                throw new Exception('Cannot create staff member: no designation available.');
            }

            $memberType = match ($role) {
                'TEACHER'      => 'TEACHING',
                'NON_TEACHING' => 'NON_TEACHING',
                'MANAGEMENT'   => 'MANAGEMENT',
                default        => 'TEACHING',
            };

            $stmtC = $conn->prepare("INSERT INTO cell_members (user_id, designation_id, member_type, name, email, mobile_number) VALUES (?, ?, ?, ?, ?, ?)");
            if (!$stmtC) {
                throw new Exception('Failed to prepare cell member insert.');
            }
            $placeholderEmail = 'staff_' . $newUserId . '@rajagiri.edu';
            $stmtC->bind_param('iissss', $newUserId, $designationId, $memberType, $name, $placeholderEmail, $cellNo);
            $stmtC->execute();
            $stmtC->close();
            break;
    }

    return $newUserId;
}

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
        error_log('[Summary Details Admin Profile] ' . $ex->getMessage());
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

$grievanceTypeOptions = [];
if ($conn instanceof mysqli) {
    try {
        $res = $conn->query("SELECT id, type_name FROM grievance_types WHERE status = 'Active' ORDER BY type_name ASC");
        if ($res) while ($row = $res->fetch_assoc()) $grievanceTypeOptions[] = $row;
    } catch (Throwable $ex) {
        error_log('[Fetch Grievance Types] ' . $ex->getMessage());
    }
}

if (isset($_GET['download_template']) && (string) $_GET['download_template'] === '1') {
    $filename = 'summary_template.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'role', 'name', 'academic_year', 'class_name', 'complaint',
        'grievance_type', 'action_taken', 'cell_no',
        'posted_date', 'reply_date', 'replied_by',
    ]);

    fputcsv($out, [
        'STUDENT', 'John Doe', '2025-2026', 'SEMESTER I',
        'Grievance regarding library timing',
        'Grievance related to Admission',
        'Investigated and resolved', '9876543210',
        '2026-01-15', '2026-01-20', 'Dr. Bindya Varghese',
    ]);

    fclose($out);
    exit;
}

$flashSuccess = '';
$flashError   = '';

if (!empty($_SESSION['flash_success'])) {
    $flashSuccess = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $flashError = (string) $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn instanceof mysqli) {

    $action = $_POST['action'] ?? '';

    if ($action === 'create_summary') {
        $role          = trim((string) ($_POST['role']            ?? 'STUDENT'));
        $name          = trim((string) ($_POST['name']            ?? ''));
        $academicYear  = trim((string) ($_POST['academic_year']   ?? ''));
        $className     = trim((string) ($_POST['class_name']      ?? ''));
        $complaint     = trim((string) ($_POST['complaint']       ?? ''));
        $grievanceType = (int) ($_POST['grievance_type_id']       ?? 0);
        $actionTaken   = trim((string) ($_POST['action_taken']    ?? ''));
        $cellNo        = trim((string) ($_POST['cell_no']         ?? ''));
        $postedDate    = trim((string) ($_POST['posted_date']     ?? date('Y-m-d')));
        $replyDate     = trim((string) ($_POST['reply_date']      ?? ''));
        $repliedBy     = trim((string) ($_POST['replied_by']      ?? ''));

        if ($name === '' || $complaint === '' || $grievanceType <= 0 || $actionTaken === '' || $cellNo === '' || $repliedBy === '') {
            $flashError = 'Please fill in all required fields.';
        } elseif (!in_array($role, ['STUDENT', 'PARENT', 'TEACHER', 'NON_TEACHING', 'MANAGEMENT'], true)) {
            $flashError = 'Invalid role selected.';
        } else {
            try {
                $conn->begin_transaction();

                $complainantUserId = resolveComplainantUser($conn, $role, $name, $cellNo, $className);
                $grievanceNumber = generateGrievanceNumber($conn);

                $status = 'Disposed';
                $description = $complaint . ($academicYear !== '' ? "\n\nAcademic Year: " . $academicYear : '');

                $sql = "INSERT INTO grievances
                            (grievance_number, grievance_type_id, complainant_user_id, subject,
                             description, status, reply_details, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                if (!$stmt) throw new Exception('Failed to prepare grievance insert.');

                $subject = mb_substr($complaint, 0, 250, 'UTF-8');
                $createdAt = $postedDate !== '' ? $postedDate . ' 00:00:00' : date('Y-m-d H:i:s');
                $updatedAt = $replyDate !== '' ? $replyDate . ' 00:00:00' : $createdAt;

                $stmt->bind_param(
                    'siissssss',
                    $grievanceNumber, $grievanceType, $complainantUserId,
                    $subject, $description, $status, $actionTaken,
                    $createdAt, $updatedAt
                );
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                $flashSuccess = 'Record created successfully! Grievance No: ' . $grievanceNumber;
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Create Summary] ' . $ex->getMessage());
                $flashError = $ex->getMessage() ?: 'A system error occurred while creating the record.';
            }
        }

        if ($flashSuccess !== '' || $flashError !== '') {
            $_SESSION['flash_success'] = $flashSuccess;
            $_SESSION['flash_error']   = $flashError;
            header('Location: summary_details.php');
            exit;
        }
    }

    if ($action === 'edit_summary') {
        $grievanceId   = (int) ($_POST['grievance_id']    ?? 0);
        $subject       = trim((string) ($_POST['subject']       ?? ''));
        $description   = trim((string) ($_POST['description']   ?? ''));
        $status        = trim((string) ($_POST['status']        ?? 'Pending'));
        $replyDetails  = trim((string) ($_POST['reply_details'] ?? ''));

        $validStatuses = ['Pending', 'In Progress', 'Disposed', 'Closed', 'Reopened'];
        if (!in_array($status, $validStatuses, true)) {
            $status = 'Pending';
        }

        if ($grievanceId <= 0) {
            $flashError = 'Invalid grievance.';
        } elseif ($subject === '' || $description === '') {
            $flashError = 'Subject and Description are required.';
        } else {
            try {
                $stmt = $conn->prepare("UPDATE grievances
                                        SET subject = ?, description = ?, status = ?, reply_details = ?
                                        WHERE id = ?");
                if (!$stmt) throw new Exception('Failed to prepare update.');

                $stmt->bind_param('ssssi', $subject, $description, $status, $replyDetails, $grievanceId);
                $stmt->execute();
                $stmt->close();

                $flashSuccess = 'Grievance updated successfully.';
            } catch (Throwable $ex) {
                error_log('[Edit Summary] ' . $ex->getMessage());
                $flashError = 'A system error occurred while updating the record.';
            }
        }

        if ($flashSuccess !== '' || $flashError !== '') {
            $_SESSION['flash_success'] = $flashSuccess;
            $_SESSION['flash_error']   = $flashError;
            header('Location: summary_details.php');
            exit;
        }
    }

    if ($action === 'delete_summary') {
        $grievanceId = (int) ($_POST['grievance_id'] ?? 0);
        if ($grievanceId > 0) {
            try {
                $stmt = $conn->prepare("DELETE FROM grievances WHERE id = ?");
                if (!$stmt) throw new Exception('Failed to prepare delete.');

                $stmt->bind_param('i', $grievanceId);
                $stmt->execute();
                $stmt->close();

                $flashSuccess = 'Grievance deleted successfully.';
            } catch (Throwable $ex) {
                error_log('[Delete Summary] ' . $ex->getMessage());
                $flashError = 'A system error occurred while deleting the record.';
            }
        }

        if ($flashSuccess !== '' || $flashError !== '') {
            $_SESSION['flash_success'] = $flashSuccess;
            $_SESSION['flash_error']   = $flashError;
            header('Location: summary_details.php');
            exit;
        }
    }

    if ($action === 'bulk_upload') {
        if (!isset($_FILES['csv_file']) || !is_array($_FILES['csv_file'])) {
            $flashError = 'Please select a CSV file to upload.';
        } elseif ((int) ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $flashError = 'File upload failed. Please try again.';
        } else {
            $tmpPath  = (string) ($_FILES['csv_file']['tmp_name'] ?? '');
            $origName = (string) ($_FILES['csv_file']['name']     ?? '');
            $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, ['csv', 'txt'], true)) {
                $flashError = 'Unsupported file type. Please upload a CSV file.';
            } elseif (!is_uploaded_file($tmpPath)) {
                $flashError = 'Invalid upload. Please try again.';
            } else {
                $handle = fopen($tmpPath, 'r');
                if ($handle === false) {
                    $flashError = 'Unable to read the uploaded file.';
                } else {
                    @set_time_limit(0);

                    $insertedCount = 0;
                    $skippedRows   = [];
                    $rowNumber     = 0;

                    try {
                        $conn->begin_transaction();

                        $typeLookup = [];
                        $resT = $conn->query("SELECT id, type_name FROM grievance_types");
                        if ($resT) {
                            while ($rowT = $resT->fetch_assoc()) {
                                $typeLookup[strtolower(trim((string) $rowT['type_name']))] = (int) $rowT['id'];
                            }
                        }

                        while (($row = fgetcsv($handle, 0, ',')) !== false) {
                            $rowNumber++;

                            if (count($row) === 1 && trim((string) $row[0]) === '') continue;

                            if ($rowNumber === 1) {
                                $firstCell = strtolower(trim((string) ($row[0] ?? '')));
                                if ($firstCell === 'role') continue;
                            }

                            if (count($row) < 11) {
                                $skippedRows[] = "Row {$rowNumber}: Not enough columns (expected 11).";
                                continue;
                            }

                            $rRole         = trim((string) ($row[0]  ?? ''));
                            $rName         = trim((string) ($row[1]  ?? ''));
                            $rAcademicYear = trim((string) ($row[2]  ?? ''));
                            $rClassName    = trim((string) ($row[3]  ?? ''));
                            $rComplaint    = trim((string) ($row[4]  ?? ''));
                            $rGrievanceType= trim((string) ($row[5]  ?? ''));
                            $rActionTaken  = trim((string) ($row[6]  ?? ''));
                            $rCellNo       = trim((string) ($row[7]  ?? ''));
                            $rPostedDate   = trim((string) ($row[8]  ?? ''));
                            $rReplyDate    = trim((string) ($row[9]  ?? ''));
                            $rRepliedBy    = trim((string) ($row[10] ?? ''));

                            $rRole = preg_replace('/^\xEF\xBB\xBF/', '', $rRole) ?? $rRole;
                            $rRole = strtoupper($rRole);

                            if (!in_array($rRole, ['STUDENT', 'PARENT', 'TEACHER', 'NON_TEACHING', 'MANAGEMENT'], true)) {
                                $skippedRows[] = "Row {$rowNumber}: Invalid role '{$rRole}'.";
                                continue;
                            }

                            if ($rName === '' || $rComplaint === '' || $rGrievanceType === '' || $rActionTaken === '' || $rCellNo === '' || $rRepliedBy === '') {
                                $skippedRows[] = "Row {$rowNumber}: Missing required fields.";
                                continue;
                            }

                            $typeKey = strtolower($rGrievanceType);
                            if (!isset($typeLookup[$typeKey])) {
                                $skippedRows[] = "Row {$rowNumber}: Unknown grievance type '{$rGrievanceType}'.";
                                continue;
                            }
                            $typeId = $typeLookup[$typeKey];

                            $complainantUserId = resolveComplainantUser($conn, $rRole, $rName, $rCellNo, $rClassName);
                            $grievanceNumber = generateGrievanceNumber($conn);

                            $createdAt = ($rPostedDate !== '' && strtotime($rPostedDate) !== false)
                                ? date('Y-m-d H:i:s', strtotime($rPostedDate))
                                : date('Y-m-d H:i:s');
                            $updatedAt = ($rReplyDate !== '' && strtotime($rReplyDate) !== false)
                                ? date('Y-m-d H:i:s', strtotime($rReplyDate))
                                : $createdAt;

                            $status = 'Disposed';
                            $subject = mb_substr($rComplaint, 0, 250, 'UTF-8');
                            $description = $rComplaint . ($rAcademicYear !== '' ? "\n\nAcademic Year: " . $rAcademicYear : '');

                            $stmtI = $conn->prepare("INSERT INTO grievances
                                                        (grievance_number, grievance_type_id, complainant_user_id, subject,
                                                         description, status, reply_details, created_at, updated_at)
                                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            if (!$stmtI) throw new Exception("Row {$rowNumber}: Failed to prepare insert.");

                            $stmtI->bind_param(
                                'siissssss',
                                $grievanceNumber, $typeId, $complainantUserId,
                                $subject, $description, $status, $rActionTaken,
                                $createdAt, $updatedAt
                            );
                            $stmtI->execute();
                            $stmtI->close();

                            $insertedCount++;
                        }

                        if ($insertedCount === 0) {
                            throw new Exception('No valid rows were found in the uploaded file.');
                        }

                        $conn->commit();

                        $msg = 'CSV Imported: ' . $insertedCount . ' row(s) added successfully!';
                        if (!empty($skippedRows)) {
                            $msg .= ' Skipped ' . count($skippedRows) . ' row(s).';
                            $_SESSION['import_skipped_rows'] = $skippedRows;
                        }
                        $flashSuccess = $msg;
                    } catch (Throwable $ex) {
                        if ($conn instanceof mysqli) $conn->rollback();
                        error_log('[Bulk Upload] ' . $ex->getMessage());
                        $flashError = $ex->getMessage() ?: 'A system error occurred during the import.';
                    }

                    fclose($handle);
                }
            }
        }

        if ($flashSuccess !== '' || $flashError !== '') {
            $_SESSION['flash_success'] = $flashSuccess;
            $_SESSION['flash_error']   = $flashError;
            header('Location: summary_details.php');
            exit;
        }
    }
}

$importSkippedRows = [];
if (!empty($_SESSION['import_skipped_rows']) && is_array($_SESSION['import_skipped_rows'])) {
    $importSkippedRows = $_SESSION['import_skipped_rows'];
    unset($_SESSION['import_skipped_rows']);
}

$search  = trim((string) ($_GET['q']       ?? ''));
$entries = (int) ($_GET['entries']          ?? 10);
$page    = (int) ($_GET['page']             ?? 1);

if (!in_array($entries, [10, 25, 50, 100], true)) {
    $entries = 10;
}
if ($page < 1) $page = 1;

$offset = ($page - 1) * $entries;

$rows       = [];
$totalRows  = 0;
$totalPages = 1;

if ($conn instanceof mysqli) {
    try {
        $whereSql = " WHERE 1=1";
        $params   = [];
        $types    = '';

        if ($search !== '') {
            $whereSql .= " AND (
                g.subject LIKE ?
                OR g.status LIKE ?
                OR g.grievance_number LIKE ?
                OR COALESCE(s.name, p.name, cm.name, ap.name, u.username) LIKE ?
            )";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types   .= 'ssss';
        }

        $countSql = "SELECT COUNT(*) AS c
                     FROM grievances g
                     LEFT JOIN users u            ON g.complainant_user_id = u.id
                     LEFT JOIN students s         ON u.id = s.user_id
                     LEFT JOIN parents p          ON u.id = p.user_id
                     LEFT JOIN cell_members cm    ON u.id = cm.user_id
                     LEFT JOIN admin_profiles ap  ON u.id = ap.user_id
                     $whereSql";

        $stmt = $conn->prepare($countSql);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $res       = $stmt->get_result();
            $totalRows = (int) ($res ? ($res->fetch_assoc()['c'] ?? 0) : 0);
            $stmt->close();
        }

        $totalPages = max(1, (int) ceil($totalRows / $entries));

        if ($page > $totalPages) {
            $page   = $totalPages;
            $offset = ($page - 1) * $entries;
        }

        $dataSql = "SELECT  g.id,
                            g.grievance_number,
                            g.subject,
                            g.description,
                            g.status,
                            g.reply_details,
                            g.feedback_details,
                            g.created_at,
                            g.updated_at,
                            gt.type_name,
                            u.role AS complainant_role,
                            COALESCE(s.name, p.name, cm.name, ap.name, u.username) AS complainant_name,
                            COALESCE(c.class_name, d.department_name, '—') AS class_or_department
                    FROM grievances g
                    LEFT JOIN grievance_types gt ON g.grievance_type_id = gt.id
                    LEFT JOIN users u            ON g.complainant_user_id = u.id
                    LEFT JOIN students s         ON u.id = s.user_id
                    LEFT JOIN classes c          ON s.class_id = c.id
                    LEFT JOIN parents p          ON u.id = p.user_id
                    LEFT JOIN cell_members cm    ON u.id = cm.user_id
                    LEFT JOIN departments d      ON cm.department_id = d.id
                    LEFT JOIN admin_profiles ap  ON u.id = ap.user_id
                    $whereSql
                    ORDER BY g.id DESC
                    LIMIT ? OFFSET ?";

        $dataParams = $params;
        $dataTypes  = $types . 'ii';
        $dataParams[] = $entries;
        $dataParams[] = $offset;

        $stmt = $conn->prepare($dataSql);
        if ($stmt) {
            $stmt->bind_param($dataTypes, ...$dataParams);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Summary Details] ' . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Summary details — Rajagiri College Grievance Redressal Portal." />
  <meta name="theme-color" content="#DB0878" />
  <title>Summary Details — Admin | Rajagiri College Grievance Portal</title>
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

    @media print {
      body * { visibility: hidden; }
      #printArea, #printArea * { visibility: visible; }
      #printArea {
        position: absolute; left: 0; top: 0; width: 100%;
        padding: 0 !important; margin: 0 !important;
      }
      .no-print { display: none !important; }
      .print-table { width: 100%; border-collapse: collapse; font-size: 11px; }
      .print-table th, .print-table td { border: 1px solid #333; padding: 5px 7px; text-align: left; }
      .print-table th { background-color: #f3f3f3 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      @page { margin: 12mm; }
    }

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

    <aside class="w-20 on-ink bg-ink flex flex-col items-center py-4 fixed inset-y-0 left-0 z-40 no-print">
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
      </nav>

      <a href="#" data-logout-trigger="1" id="sidebarLogoutBtn" class="nav-icon-link group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-hot flex items-center justify-center text-white" title="Logout" aria-label="Logout">
        <i data-lucide="log-out" class="w-6 h-6 group-hover:translate-x-0.5 transition-transform"></i>
        <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-hot text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50">Logout</span>
      </a>
    </aside>

    <div class="flex-1 ml-20 flex flex-col min-h-screen">

      <header class="sticky top-0 z-30 bg-white border-b border-slate-200 no-print">
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

              <a href="#" data-logout-trigger="1" id="dropdownLogoutBtn" role="menuitem" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 transition-colors hover:bg-blush hover:text-hot group">
                <i data-lucide="log-out" class="w-4 h-4 text-hot group-hover:scale-110 transition-transform"></i>
                <span class="font-semibold">Logout</span>
              </a>
            </div>
          </div>
        </div>
      </header>

      <main id="main-content" class="flex-1 px-4 sm:px-6 py-8">

        <div class="max-w-6xl mx-auto mb-6 rise no-print">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
              <h1 class="font-display text-2xl md:text-3xl font-extrabold tracking-tight text-ink mb-2">Summary Details</h1>
              <nav class="flex flex-wrap items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
                <a href="dashboard.php" class="flex items-center rounded transition-colors hover:text-hot"><i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i> Dashboard</a>
                <span class="text-slate-300">/</span>
                <span class="text-hot font-semibold">Summary Details</span>
              </nav>
            </div>

            <div class="flex items-center gap-2">
              <button type="button" onclick="window.print();" title="Print Summary" aria-label="Print"
                      class="btn-hard inline-flex items-center justify-center w-11 h-11 rounded-xl bg-sun text-ink hover:bg-[#FFD35C]">
                <i data-lucide="printer" class="w-5 h-5"></i>
              </button>

              <button type="button" onclick="openBulkUploadModal()" title="Bulk Upload Summary Details" aria-label="Bulk upload"
                      class="btn-hard inline-flex items-center justify-center w-11 h-11 rounded-xl bg-white text-ink hover:bg-blush">
                <i data-lucide="upload" class="w-5 h-5"></i>
              </button>

              <button type="button" onclick="openCreateModal()" title="Create Summary Details" aria-label="Add record"
                      class="btn-hard inline-flex items-center justify-center w-11 h-11 rounded-xl bg-hot text-white hover:bg-hotdark">
                <i data-lucide="plus" class="w-5 h-5"></i>
              </button>
            </div>
          </div>
        </div>

        <?php if ($flashSuccess !== ''): ?>
          <div id="flashSuccessBox" class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-leaf bg-[#DCEFE4] px-4 py-3 flex items-start gap-2 animate-flash-in overflow-hidden no-print">
            <i data-lucide="check-circle" class="w-5 h-5 text-leaf flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-leaf font-semibold"><?= e($flashSuccess) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
          <div id="flashErrorBox" class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-red-300 bg-red-50 px-4 py-3 flex items-start gap-2 animate-flash-in overflow-hidden no-print">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-red-700 font-semibold"><?= e($flashError) ?></p>
          </div>
        <?php endif; ?>

        <?php if (!empty($importSkippedRows)): ?>
          <div class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-amber-300 bg-amber-50 px-4 py-3 rise no-print">
            <div class="flex items-start gap-2 mb-2">
              <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5"></i>
              <p class="text-sm font-semibold text-amber-800">
                Some rows were skipped during import (<?= count($importSkippedRows) ?>):
              </p>
            </div>
            <ul class="text-xs text-amber-700 space-y-1 ml-7 list-disc">
              <?php foreach (array_slice($importSkippedRows, 0, 15) as $sk): ?>
                <li><?= e((string) $sk) ?></li>
              <?php endforeach; ?>
              <?php if (count($importSkippedRows) > 15): ?>
                <li class="italic">... and <?= count($importSkippedRows) - 15 ?> more</li>
              <?php endif; ?>
            </ul>
          </div>
        <?php endif; ?>

        <div class="max-w-6xl mx-auto mb-5 rise rise-2 no-print">
          <form method="GET" action="summary_details.php" id="filterForm" class="bg-white rounded-2xl border-2 border-ink px-5 py-4 shadow-[4px_4px_0_#FFC93C]">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div class="flex items-center gap-3">
                <span class="text-sm text-slate-600">Show</span>
                <select name="entries" id="entriesPerPage"
                        class="px-3 py-1.5 border-2 border-ink rounded-lg text-sm font-semibold text-ink bg-white focus:outline-none focus:ring-4 focus:ring-hot/10 transition-colors">
                  <option value="10"  <?= $entries === 10  ? 'selected' : '' ?>>10</option>
                  <option value="25"  <?= $entries === 25  ? 'selected' : '' ?>>25</option>
                  <option value="50"  <?= $entries === 50  ? 'selected' : '' ?>>50</option>
                  <option value="100" <?= $entries === 100 ? 'selected' : '' ?>>100</option>
                </select>
                <span class="text-sm text-slate-600">entries</span>
              </div>

              <div class="relative w-full sm:w-80">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                <input type="text" name="q" id="searchInput" value="<?= e($search) ?>" placeholder="Search.." autocomplete="off"
                       class="w-full pl-10 pr-4 py-2 border-2 border-ink rounded-lg text-sm bg-white focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
              </div>
            </div>
          </form>
        </div>

        <div class="max-w-6xl mx-auto rise rise-3" id="printArea">
          <div class="bg-white rounded-2xl border-2 border-ink overflow-hidden shadow-[6px_6px_0_#FFC93C]">

            <div class="overflow-x-auto">
              <table class="w-full print-table" id="summaryTable">
                <thead>
                  <tr class="bg-ink text-white">
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Sl.No.</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Name</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Class/Department</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Complaint</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Posted Date</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Role</th>
                    <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">Actions</th>
                    <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Status</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-slate-100" id="summaryTableBody">

                  <?php if (empty($rows)): ?>
                    <tr>
                      <td colspan="8" class="px-6 py-16 text-center text-slate-500">
                        <div class="flex flex-col items-center justify-center">
                          <span class="w-16 h-16 rounded-full bg-blush border-2 border-ink flex items-center justify-center mb-4">
                            <i data-lucide="inbox" class="w-8 h-8 text-hot"></i>
                          </span>
                          <p class="text-lg font-bold text-ink">No data available in table</p>
                        </div>
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($rows as $index => $r): ?>
                      <?php
                        $gId        = (int) $r['id'];
                        $gNumber    = (string) ($r['grievance_number']      ?? '');
                        $gName      = (string) ($r['complainant_name']      ?? 'N/A');
                        $gClass     = (string) ($r['class_or_department']   ?? '—');
                        $gSubject   = (string) ($r['subject']               ?? '—');
                        $gDesc      = (string) ($r['description']           ?? '');
                        $gReply     = (string) ($r['reply_details']         ?? '');
                        $gFeedback  = (string) ($r['feedback_details']      ?? '');
                        $gType      = (string) ($r['type_name']             ?? '—');
                        $gDate      = (string) ($r['created_at']            ?? '');
                        $gUpd       = (string) ($r['updated_at']            ?? '');
                        $gRole      = (string) ($r['complainant_role']      ?? '');
                        $gStatus    = (string) ($r['status']                ?? 'Pending');

                        $formattedDate    = '—';
                        $formattedUpdated = '—';
                        if ($gDate !== '' && strtotime($gDate) !== false) $formattedDate = date('Y-m-d', strtotime($gDate));
                        if ($gUpd !== ''  && strtotime($gUpd)  !== false) $formattedUpdated = date('Y-m-d', strtotime($gUpd));

                        $statusCls   = statusBadgeClass($gStatus);
                        $globalIndex = $offset + $index + 1;
                      ?>
                      <tr class="hover:bg-blush/60 transition-colors group">
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-900"><?= $globalIndex ?></td>
                        <td class="px-6 py-4 text-sm font-bold text-ink"><?= e($gName) ?></td>
                        <td class="px-6 py-4 text-sm text-slate-700 max-w-[200px]"><?= e($gClass) ?></td>
                        <td class="px-6 py-4 text-sm text-slate-700 max-w-[260px]"><?= e($gSubject) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600"><?= e($formattedDate) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-hot"><?= e(roleLabel($gRole)) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-center no-print">
                          <div class="inline-flex items-center gap-1.5">

                            <button type="button" title="View grievance" aria-label="View"
                                    onclick='openViewModal(<?= json_encode([
                                        "grievance_number" => $gNumber,
                                        "name"             => $gName,
                                        "role"             => roleLabel($gRole),
                                        "class_department" => $gClass,
                                        "grievance_type"   => $gType,
                                        "subject"          => $gSubject,
                                        "description"      => $gDesc,
                                        "status"           => $gStatus,
                                        "posted_date"      => $formattedDate,
                                        "reply_date"       => $formattedUpdated,
                                        "reply_details"    => $gReply,
                                        "feedback_details" => $gFeedback,
                                    ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                    class="w-9 h-9 rounded-full bg-blush border-2 border-ink flex items-center justify-center text-ink hover:bg-hot hover:text-white transition-all duration-200">
                              <i data-lucide="eye" class="w-4 h-4"></i>
                            </button>

                            <button type="button" title="Edit grievance" aria-label="Edit"
                                    onclick='openEditModal(<?= json_encode([
                                        "id"           => $gId,
                                        "number"       => $gNumber,
                                        "subject"      => $gSubject,
                                        "description"  => $gDesc,
                                        "status"       => $gStatus,
                                        "reply_details"=> $gReply,
                                    ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                    class="w-9 h-9 rounded-full bg-blush border-2 border-ink flex items-center justify-center text-ink hover:bg-grape hover:text-white transition-all duration-200">
                              <i data-lucide="pencil" class="w-4 h-4"></i>
                            </button>

                            <button type="button" title="Delete grievance" aria-label="Delete"
                                    onclick='confirmDelete(<?= $gId ?>, <?= json_encode($gNumber !== '' ? $gNumber : $gSubject, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                    class="w-9 h-9 rounded-full bg-blush border-2 border-ink flex items-center justify-center text-ink hover:bg-red-500 hover:text-white transition-all duration-200">
                              <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>

                          </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                          <span class="inline-flex items-center px-3 py-1 text-xs font-bold rounded-full border-2 <?= $statusCls ?>"><?= e($gStatus) ?></span>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>

                </tbody>
              </table>
            </div>

            <div class="px-6 py-4 bg-blush border-t-2 border-ink flex flex-col sm:flex-row items-center justify-between gap-4 no-print">

              <p class="text-sm text-slate-600">
                Showing <span class="font-semibold text-ink"><?= $totalRows > 0 ? ($offset + 1) : 0 ?></span>
                to <span class="font-semibold text-ink"><?= $totalRows > 0 ? min($offset + count($rows), $totalRows) : 0 ?></span>
                of <span class="font-semibold text-ink"><?= $totalRows ?></span> entries
              </p>

              <div class="flex items-center gap-2">
                <?php
                  $qsBase = 'summary_details.php?entries=' . $entries;
                  if ($search !== '') $qsBase .= '&q=' . urlencode($search);
                ?>

                <?php if ($page > 1): ?>
                  <a href="<?= e($qsBase . '&page=' . ($page - 1)) ?>"
                     class="px-4 py-2 rounded-lg text-sm font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">Previous</a>
                <?php else: ?>
                  <button type="button" class="px-4 py-2 rounded-lg text-sm font-semibold text-slate-400 bg-white border-2 border-slate-200 cursor-not-allowed" disabled>Previous</button>
                <?php endif; ?>

                <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-hot border-2 border-ink text-white text-sm font-bold shadow-[2px_2px_0_#FFC93C]"><?= $page ?></span>

                <?php if ($page < $totalPages): ?>
                  <a href="<?= e($qsBase . '&page=' . ($page + 1)) ?>"
                     class="px-4 py-2 rounded-lg text-sm font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">Next</a>
                <?php else: ?>
                  <button type="button" class="px-4 py-2 rounded-lg text-sm font-semibold text-slate-400 bg-white border-2 border-slate-200 cursor-not-allowed" disabled>Next</button>
                <?php endif; ?>
              </div>

            </div>

          </div>
        </div>

      </main>

      <footer class="on-ink bg-ink border-t-[6px] border-sun mt-auto no-print">
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

  <!-- VIEW MODAL -->
  <div id="viewModal" class="hidden fixed inset-0 z-[65] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeViewModal()"></div>
    <div class="relative w-full max-w-3xl bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden flex flex-col max-h-[90vh]">
      <div class="h-1.5 w-full bg-hot"></div>
      <div class="flex items-center justify-between px-6 py-4 border-b-2 border-ink bg-blush">
        <div class="flex items-center gap-3">
          <span class="w-9 h-9 rounded-lg bg-hot border-2 border-ink flex items-center justify-center">
            <i data-lucide="eye" class="w-5 h-5 text-white"></i>
          </span>
          <h3 class="font-display text-lg font-bold text-ink">Grievance Details</h3>
        </div>
        <button type="button" onclick="closeViewModal()" aria-label="Close"
                class="w-8 h-8 rounded-lg border-2 border-ink bg-white hover:bg-blush flex items-center justify-center text-ink transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="p-6 space-y-5 overflow-y-auto">
        <div class="flex items-center justify-between flex-wrap gap-4 pb-4 border-b-2 border-slate-100">
          <div class="flex items-center gap-4">
            <span class="w-14 h-14 rounded-full bg-hot border-2 border-ink flex items-center justify-center text-white shadow-[2px_2px_0_#FFC93C]">
              <i data-lucide="user" class="w-7 h-7"></i>
            </span>
            <div class="min-w-0">
              <p id="viewName" class="text-base font-bold text-ink break-words">—</p>
              <p class="text-xs text-slate-500"><span id="viewRole">—</span> · <span id="viewClassDept">—</span></p>
            </div>
          </div>
          <span id="viewStatus" class="inline-flex items-center px-3 py-1 text-xs font-bold rounded-full border-2">—</span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="bg-blush rounded-xl p-3 border-2 border-ink">
            <p class="text-[10px] font-bold uppercase tracking-wider text-hot mb-1">Grievance Number</p>
            <p id="viewNumber" class="text-sm font-bold text-ink break-all">—</p>
          </div>
          <div class="bg-blush rounded-xl p-3 border-2 border-ink">
            <p class="text-[10px] font-bold uppercase tracking-wider text-hot mb-1">Grievance Type</p>
            <p id="viewType" class="text-sm font-bold text-ink">—</p>
          </div>
          <div class="bg-blush rounded-xl p-3 border-2 border-ink">
            <p class="text-[10px] font-bold uppercase tracking-wider text-hot mb-1">Posted Date</p>
            <p id="viewPostedDate" class="text-sm font-bold text-ink">—</p>
          </div>
          <div class="bg-blush rounded-xl p-3 border-2 border-ink">
            <p class="text-[10px] font-bold uppercase tracking-wider text-hot mb-1">Reply Date</p>
            <p id="viewReplyDate" class="text-sm font-bold text-ink">—</p>
          </div>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-hot mb-1.5">Subject</p>
          <p id="viewSubject" class="text-sm text-ink font-semibold break-words bg-blush rounded-xl p-3 border-2 border-ink">—</p>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-hot mb-1.5">Description</p>
          <p id="viewDescription" class="text-sm text-slate-700 leading-relaxed whitespace-pre-wrap break-words bg-blush rounded-xl p-3 border-2 border-ink">—</p>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-leaf mb-1.5">Reply / Action Taken</p>
          <p id="viewReplyDetails" class="text-sm text-leaf leading-relaxed whitespace-pre-wrap break-words bg-[#DCEFE4] rounded-xl p-3 border-2 border-leaf">—</p>
        </div>

        <div>
          <p class="text-[10px] font-bold uppercase tracking-wider text-[#B37A00] mb-1.5">Feedback Details</p>
          <p id="viewFeedbackDetails" class="text-sm text-[#9A6500] leading-relaxed whitespace-pre-wrap break-words bg-[#FFF5D8] rounded-xl p-3 border-2 border-[#F1DDA1]">—</p>
        </div>
      </div>

      <div class="px-6 py-4 bg-blush border-t-2 border-ink flex justify-end">
        <button type="button" onclick="closeViewModal()"
                class="px-5 py-2.5 rounded-lg font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">Close</button>
      </div>
    </div>
  </div>

  <!-- EDIT MODAL -->
  <div id="editModal" class="hidden fixed inset-0 z-[66] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeEditModal()"></div>
    <div class="relative w-full max-w-2xl bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden max-h-[90vh] flex flex-col">
      <div class="h-1.5 w-full bg-hot"></div>
      <div class="flex items-center justify-between px-6 py-4 border-b-2 border-ink bg-blush">
        <div class="flex items-center gap-3">
          <span class="w-9 h-9 rounded-lg bg-hot border-2 border-ink flex items-center justify-center">
            <i data-lucide="pencil" class="w-5 h-5 text-white"></i>
          </span>
          <h3 class="font-display text-lg font-bold text-ink">Edit Grievance</h3>
        </div>
        <button type="button" onclick="closeEditModal()" aria-label="Close"
                class="w-8 h-8 rounded-lg border-2 border-ink bg-white hover:bg-blush flex items-center justify-center text-ink transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="editForm" method="POST" action="summary_details.php" class="p-6 space-y-5 overflow-y-auto">
        <input type="hidden" name="action" value="edit_summary" />
        <input type="hidden" name="grievance_id" id="editGrievanceId" value="" />

        <div class="space-y-2">
          <label class="block text-sm font-semibold text-ink">Grievance Number</label>
          <input type="text" id="editNumber" readonly
                 class="w-full px-4 py-3 border-2 border-slate-200 rounded-xl bg-slate-50 text-slate-500 font-medium cursor-not-allowed" />
        </div>

        <div class="space-y-2">
          <label for="edit_subject" class="block text-sm font-semibold text-ink">Subject <span class="text-hot">*</span></label>
          <input type="text" name="subject" id="edit_subject" required placeholder="Enter subject"
                 class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
        </div>

        <div class="space-y-2">
          <label for="edit_description" class="block text-sm font-semibold text-ink">Description <span class="text-hot">*</span></label>
          <textarea name="description" id="edit_description" required rows="4" placeholder="Enter description"
                    class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 resize-none focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all"></textarea>
        </div>

        <div class="space-y-2">
          <label for="edit_status" class="block text-sm font-semibold text-ink">Status <span class="text-hot">*</span></label>
          <select name="status" id="edit_status" required
                  class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all">
            <option value="Pending">Pending</option>
            <option value="In Progress">In Progress</option>
            <option value="Disposed">Disposed</option>
            <option value="Closed">Closed</option>
            <option value="Reopened">Reopened</option>
          </select>
        </div>

        <div class="space-y-2">
          <label for="edit_reply_details" class="block text-sm font-semibold text-ink">Reply / Action Taken</label>
          <textarea name="reply_details" id="edit_reply_details" rows="3" placeholder="Enter reply or action taken"
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

  <!-- DELETE CONFIRM -->
  <div id="deleteConfirmModal" class="hidden fixed inset-0 z-[67] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeleteModal()"></div>
    <div id="deleteConfirmPanel" class="relative w-full max-w-md bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-red-500"></div>
      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <span class="w-16 h-16 rounded-full bg-red-50 border-2 border-ink flex items-center justify-center mb-4">
          <i data-lucide="trash-2" class="w-8 h-8 text-red-500"></i>
        </span>
        <h3 class="font-display text-xl font-bold text-ink mb-2">Delete Grievance?</h3>
        <p class="text-sm text-slate-500 leading-relaxed">
          You are about to permanently delete
          <span id="deleteNameDisplay" class="font-bold text-hot break-words">this grievance</span>.
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

  <!-- CREATE MODAL -->
  <div id="createSummaryModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeCreateModal()"></div>
    <div class="relative w-full max-w-4xl bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden max-h-[92vh] flex flex-col">
      <div class="h-1.5 w-full bg-hot"></div>
      <div class="flex items-center justify-between px-6 py-4 border-b-2 border-ink bg-blush">
        <h3 class="font-display text-lg font-bold text-ink">Create Summary Details</h3>
        <button type="button" onclick="closeCreateModal()" aria-label="Close"
                class="w-8 h-8 rounded-lg border-2 border-ink bg-white hover:bg-blush flex items-center justify-center text-ink transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="createSummaryForm" method="POST" action="summary_details.php" class="p-6 space-y-5 overflow-y-auto">
        <input type="hidden" name="action" value="create_summary" />

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
          <div class="space-y-2">
            <label for="create_role" class="block text-sm font-semibold text-ink">Role <span class="text-hot">*</span></label>
            <select name="role" id="create_role" required
                    class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all">
              <option value="">Select</option>
              <option value="STUDENT">STUDENT</option>
              <option value="PARENT">PARENT</option>
              <option value="TEACHER">TEACHER</option>
              <option value="NON_TEACHING">NON TEACHING</option>
              <option value="MANAGEMENT">MANAGEMENT</option>
            </select>
          </div>

          <div class="space-y-2">
            <label for="create_name" class="block text-sm font-semibold text-ink">Name <span class="text-hot">*</span></label>
            <input type="text" name="name" id="create_name" required placeholder="StudentName"
                   class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
          </div>

          <div class="space-y-2">
            <label for="create_academic_year" class="block text-sm font-semibold text-ink">Academic Year <span class="text-hot">*</span></label>
            <input type="text" name="academic_year" id="create_academic_year" required placeholder="AcademicYear"
                   class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
          <div class="space-y-2">
            <label for="create_complaint" class="block text-sm font-semibold text-ink">Complaint <span class="text-hot">*</span></label>
            <textarea name="complaint" id="create_complaint" required rows="3" placeholder="Complaint"
                      class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 resize-none focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all"></textarea>
          </div>

          <div class="space-y-2">
            <label for="create_class_name" class="block text-sm font-semibold text-ink">Class Name</label>
            <input type="text" name="class_name" id="create_class_name" placeholder="ClassName"
                   class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
          </div>

          <div class="space-y-2">
            <label for="create_grievance_type" class="block text-sm font-semibold text-ink">Grievance Type <span class="text-hot">*</span></label>
            <select name="grievance_type_id" id="create_grievance_type" required
                    class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all">
              <option value="">GrievanceType</option>
              <?php foreach ($grievanceTypeOptions as $opt): ?>
                <option value="<?= (int) $opt['id'] ?>"><?= e($opt['type_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
          <div class="space-y-2">
            <label for="create_action_taken" class="block text-sm font-semibold text-ink">Action Taken <span class="text-hot">*</span></label>
            <input type="text" name="action_taken" id="create_action_taken" required placeholder="ActionTaken"
                   class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
          </div>

          <div class="space-y-2">
            <label for="create_cell_no" class="block text-sm font-semibold text-ink">Cell No <span class="text-hot">*</span></label>
            <input type="tel" name="cell_no" id="create_cell_no" required
                   inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10"
                   title="Please enter exactly 10 digits" placeholder="CellNo"
                   class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
          </div>

          <div class="space-y-2">
            <label for="create_posted_date" class="block text-sm font-semibold text-ink">Posted Date</label>
            <input type="date" name="posted_date" id="create_posted_date" value="<?= date('Y-m-d') ?>"
                   class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
          <div class="space-y-2">
            <label for="create_reply_date" class="block text-sm font-semibold text-ink">Reply Date</label>
            <input type="date" name="reply_date" id="create_reply_date" value="<?= date('Y-m-d') ?>"
                   class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
          </div>

          <div class="space-y-2 md:col-span-2">
            <label for="create_replied_by" class="block text-sm font-semibold text-ink">Replied By <span class="text-hot">*</span></label>
            <input type="text" name="replied_by" id="create_replied_by" required placeholder="Replied"
                   class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium placeholder-slate-400 focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
          </div>
        </div>

        <div class="flex justify-center pt-3">
          <button type="submit" class="btn-hard inline-flex items-center gap-2 rounded-xl bg-hot px-12 py-3 text-sm font-bold text-white hover:bg-hotdark">
            <i data-lucide="save" class="w-4 h-4"></i> Submit
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- BULK UPLOAD MODAL -->
  <div id="bulkUploadModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeBulkUploadModal()"></div>
    <div class="relative w-full max-w-lg bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden">
      <div class="h-1.5 w-full bg-hot"></div>
      <div class="flex items-center justify-between px-6 py-4 border-b-2 border-ink bg-blush">
        <div class="flex items-center gap-3">
          <span class="w-9 h-9 rounded-lg bg-sun border-2 border-ink flex items-center justify-center">
            <i data-lucide="upload" class="w-5 h-5 text-ink"></i>
          </span>
          <h3 class="font-display text-lg font-bold text-ink">Bulk Upload Summary Details</h3>
        </div>
        <button type="button" onclick="closeBulkUploadModal()" aria-label="Close"
                class="w-8 h-8 rounded-lg border-2 border-ink bg-white hover:bg-blush flex items-center justify-center text-ink transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="bulkUploadForm" method="POST" action="summary_details.php" enctype="multipart/form-data" class="p-6 space-y-5">
        <input type="hidden" name="action" value="bulk_upload" />

        <div class="rounded-xl border-2 border-ink bg-blush px-4 py-3">
          <p class="text-xs text-slate-700 leading-relaxed">
            <span class="font-bold text-hot">Column order required:</span>
            <code class="text-[11px] bg-white px-1.5 py-0.5 rounded border border-slate-200">role, name, academic_year, class_name, complaint, grievance_type, action_taken, cell_no, posted_date, reply_date, replied_by</code>
          </p>
        </div>

        <div class="space-y-2">
          <label for="csv_file" class="block text-sm font-semibold text-ink">Select CSV File <span class="text-hot">*</span></label>
          <input type="file" name="csv_file" id="csv_file" required accept=".csv, .txt"
                 class="w-full text-sm text-slate-700
                        file:mr-3 file:py-2.5 file:px-4
                        file:rounded-lg file:border-2 file:border-ink
                        file:text-sm file:font-bold
                        file:bg-hot file:text-white
                        hover:file:bg-hotdark
                        border-2 border-ink rounded-xl
                        focus:outline-none focus:ring-4 focus:ring-hot/10
                        transition-all cursor-pointer bg-white" />
          <p class="text-xs text-slate-500">Accepted format: .csv</p>
        </div>

        <div class="flex items-center justify-between rounded-xl bg-white border-2 border-ink px-4 py-3">
          <div class="flex items-start gap-3">
            <i data-lucide="file-spreadsheet" class="w-5 h-5 text-hot mt-0.5 flex-shrink-0"></i>
            <div>
              <p class="text-sm font-bold text-ink">Need the correct format?</p>
              <p class="text-xs text-slate-500">Download the sample CSV template.</p>
            </div>
          </div>
          <a href="summary_details.php?download_template=1"
             class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border-2 border-ink bg-sun hover:bg-[#FFD35C] text-ink font-bold text-xs transition-colors whitespace-nowrap">
            <i data-lucide="download" class="w-4 h-4"></i>
            <span>Sample Template</span>
          </a>
        </div>

        <div class="flex justify-center pt-2 gap-3">
          <button type="button" onclick="closeBulkUploadModal()"
                  class="px-6 py-3 rounded-xl font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">Cancel</button>
          <button type="submit" class="btn-hard inline-flex items-center gap-2 rounded-xl bg-hot px-8 py-3 text-sm font-bold text-white hover:bg-hotdark">
            <i data-lucide="upload" class="w-4 h-4"></i> Import
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- LOGOUT CONFIRM -->
  <div id="logoutConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4 no-print">
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

  <form id="deleteForm" method="POST" action="summary_details.php" class="hidden">
    <input type="hidden" name="action" value="delete_summary" />
    <input type="hidden" name="grievance_id" id="deleteGrievanceId" value="" />
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
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { menu.classList.add('hidden'); if (chevron) chevron.classList.remove('rotate-180'); btn.setAttribute('aria-expanded', 'false'); }
      });
    })();

    const viewModal = document.getElementById('viewModal');

    function openViewModal(data) {
      const setText = function (id, value) {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = (value !== undefined && value !== null && String(value).trim() !== '')
          ? String(value) : '—';
      };

      setText('viewName',         data.name);
      setText('viewRole',         data.role);
      setText('viewClassDept',    data.class_department);
      setText('viewNumber',       data.grievance_number);
      setText('viewType',         data.grievance_type);
      setText('viewPostedDate',   data.posted_date);
      setText('viewReplyDate',    data.reply_date);
      setText('viewSubject',      data.subject);
      setText('viewDescription',  data.description);
      setText('viewReplyDetails', data.reply_details);
      setText('viewFeedbackDetails', data.feedback_details);

      const statusEl = document.getElementById('viewStatus');
      if (statusEl) {
        const status = String(data.status || 'Pending');
        statusEl.textContent = status;
        statusEl.className = 'inline-flex items-center px-3 py-1 text-xs font-bold rounded-full border-2';

        const key = status.toLowerCase();
        if (key === 'pending')          statusEl.classList.add('bg-[#FFF5D8]', 'text-[#9A6500]', 'border-[#F1DDA1]');
        else if (key === 'in progress') statusEl.classList.add('bg-sky-50', 'text-sky-800', 'border-sky-200');
        else if (key === 'disposed')    statusEl.classList.add('bg-[#DCEFE4]', 'text-leaf', 'border-leaf');
        else if (key === 'closed')      statusEl.classList.add('bg-slate-100', 'text-slate-700', 'border-slate-300');
        else if (key === 'reopened')    statusEl.classList.add('bg-[#FFE0F0]', 'text-hot', 'border-hot');
        else                            statusEl.classList.add('bg-slate-100', 'text-slate-700', 'border-slate-300');
      }

      viewModal.classList.remove('hidden');
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeViewModal() { viewModal.classList.add('hidden'); }

    const editModal = document.getElementById('editModal');
    function openEditModal(data) {
      document.getElementById('editGrievanceId').value   = data.id || '';
      document.getElementById('editNumber').value        = data.number || '—';
      document.getElementById('edit_subject').value      = data.subject || '';
      document.getElementById('edit_description').value  = data.description || '';
      document.getElementById('edit_status').value       = data.status || 'Pending';
      document.getElementById('edit_reply_details').value= data.reply_details || '';
      editModal.classList.remove('hidden');
      setTimeout(function () { const el = document.getElementById('edit_subject'); if (el) el.focus(); }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeEditModal() { editModal.classList.add('hidden'); }

    const deleteConfirmModal = document.getElementById('deleteConfirmModal');
    const deleteConfirmPanel = document.getElementById('deleteConfirmPanel');
    const deleteNameDisplay  = document.getElementById('deleteNameDisplay');
    const confirmDeleteBtn   = document.getElementById('confirmDeleteBtn');
    let pendingDeleteId = null;

    function confirmDelete(grievanceId, label) {
      pendingDeleteId = grievanceId;
      if (deleteNameDisplay) deleteNameDisplay.textContent = '"' + label + '"';
      deleteConfirmModal.classList.remove('hidden');
      if (deleteConfirmPanel) {
        deleteConfirmPanel.classList.remove('animate-confirm-shake');
        void deleteConfirmPanel.offsetWidth;
        deleteConfirmPanel.classList.add('animate-confirm-shake');
      }
      setTimeout(function () { if (confirmDeleteBtn) confirmDeleteBtn.focus(); }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeDeleteModal() { deleteConfirmModal.classList.add('hidden'); pendingDeleteId = null; }
    if (confirmDeleteBtn) confirmDeleteBtn.addEventListener('click', function () {
      if (pendingDeleteId === null) return closeDeleteModal();
      const input = document.getElementById('deleteGrievanceId');
      const form  = document.getElementById('deleteForm');
      if (input && form) { input.value = String(pendingDeleteId); form.submit(); }
    });

    const createModal = document.getElementById('createSummaryModal');
    const createForm  = document.getElementById('createSummaryForm');
    function openCreateModal() {
      createModal.classList.remove('hidden');
      setTimeout(function () { const el = document.getElementById('create_role'); if (el) el.focus(); }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeCreateModal() { createModal.classList.add('hidden'); if (createForm) createForm.reset(); }

    const bulkModal = document.getElementById('bulkUploadModal');
    const bulkForm  = document.getElementById('bulkUploadForm');
    function openBulkUploadModal() {
      bulkModal.classList.remove('hidden');
      setTimeout(function () { const el = document.getElementById('csv_file'); if (el) el.focus(); }, 80);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeBulkUploadModal() { bulkModal.classList.add('hidden'); if (bulkForm) bulkForm.reset(); }

    const logoutConfirmModal = document.getElementById('logoutConfirmModal');
    const logoutConfirmPanel = document.getElementById('logoutConfirmPanel');
    const confirmLogoutBtn   = document.getElementById('confirmLogoutBtn');
    const LOGOUT_URL = '../logout.php?role=admin';
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

    (function () {
      const entriesSelect = document.getElementById('entriesPerPage');
      const filterForm    = document.getElementById('filterForm');
      if (!entriesSelect || !filterForm) return;
      entriesSelect.addEventListener('change', function () { filterForm.submit(); });
    })();

    (function () {
      const searchInput = document.getElementById('searchInput');
      const tableBody   = document.getElementById('summaryTableBody');
      if (!searchInput || !tableBody) return;
      let debounceTimer = null;
      searchInput.addEventListener('input', function () {
        const term = this.value.toLowerCase().trim();
        tableBody.querySelectorAll('tr').forEach(function (row) {
          row.style.display = (term === '' || row.textContent.toLowerCase().indexOf(term) !== -1) ? '' : 'none';
        });
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
          const form = document.getElementById('filterForm');
          if (form) form.submit();
        }, 700);
      });
    })();

    (function () {
      const cellInput = document.getElementById('create_cell_no');
      if (!cellInput) return;
      cellInput.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 10);
      });
    })();

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      if (viewModal && !viewModal.classList.contains('hidden')) closeViewModal();
      if (editModal && !editModal.classList.contains('hidden')) closeEditModal();
      if (deleteConfirmModal && !deleteConfirmModal.classList.contains('hidden')) closeDeleteModal();
      if (createModal && !createModal.classList.contains('hidden')) closeCreateModal();
      if (bulkModal && !bulkModal.classList.contains('hidden')) closeBulkUploadModal();
      if (logoutConfirmModal && !logoutConfirmModal.classList.contains('hidden')) closeLogoutModal();
    });
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>