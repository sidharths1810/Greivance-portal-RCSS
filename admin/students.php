<?php
/**
 * admin/students.php
 * ---------------------------------------------------------------------------
 * Admin — Student Management (per class)
 * Rajagiri College Grievance Redressal Portal
 *
 * Features:
 *   • Lists students of a specific class (via ?class_id=X)
 *   • Add / Edit / View / Delete students (with admission_number)
 *   • Reset student password
 *   • Bulk delete selected students
 *   • Bulk Excel/CSV import with sample template
 *   • Live search + entries-per-page dropdown
 *   • Themed delete & reset confirmation modals
 *   • Flash messages auto-dismiss after 3 seconds
 *   • Show/hide password toggle in Add Student form
 *   • Mobile number must be exactly 10 digits
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

/**
 * Validate that a mobile number is exactly 10 digits.
 */
function isValidMobile(string $mobile): bool
{
    return (bool) preg_match('/^[0-9]{10}$/', $mobile);
}

// ---------------------------------------------------------------------------
// 5. VALIDATE CLASS_ID
// ---------------------------------------------------------------------------
$classId = isset($_GET['class_id']) ? (int) $_GET['class_id'] : 0;

if ($classId <= 0) {
    header('Location: classes.php');
    exit;
}

// ---------------------------------------------------------------------------
// 6. SAMPLE TEMPLATE DOWNLOAD  (?download_template=1)
// ---------------------------------------------------------------------------
if (isset($_GET['download_template']) && (string) $_GET['download_template'] === '1') {
    $filename = 'students_import_template.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');

    // UTF-8 BOM so Excel opens it correctly
    fwrite($out, "\xEF\xBB\xBF");

    // Header row — matches expected column order
    fputcsv($out, [
        'admission_number',
        'name',
        'email',
        'contact_number',
        'address',
        'username',
        'password',
    ]);

    // Example rows
    fputcsv($out, [
        'RCSS2025001',
        'John Doe',
        'john.doe@example.com',
        '9876543210',
        'Kochi, Kerala',
        'johndoe',
        'Student@123',
    ]);

    fputcsv($out, [
        'RCSS2025002',
        'Jane Smith',
        'jane.smith@example.com',
        '9876543211',
        'Thrissur, Kerala',
        'janesmith',
        'Student@456',
    ]);

    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// 7. FETCH ADMIN PROFILE (for header)
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
        error_log('[Students Admin Profile] ' . $ex->getMessage());
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
// 8. FETCH CLASS + COURSE INFO
// ---------------------------------------------------------------------------
$className   = '';
$courseName  = '';
$classExists = false;

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  cl.id,
                        cl.class_name,
                        co.course_name
                FROM classes cl
                JOIN courses co ON cl.course_id = co.id
                WHERE cl.id = ?
                LIMIT 1";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $classId);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res && $res->num_rows > 0) {
                $row         = $res->fetch_assoc();
                $className   = (string) ($row['class_name']  ?? '');
                $courseName  = (string) ($row['course_name'] ?? '');
                $classExists = true;
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Class Info] ' . $ex->getMessage());
    }
}

if (!$classExists) {
    header('Location: classes.php');
    exit;
}

// ---------------------------------------------------------------------------
// 9. HANDLE FORM SUBMISSIONS
// ---------------------------------------------------------------------------
$flashSuccess = '';
$flashError   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $conn instanceof mysqli) {

    $action = $_POST['action'] ?? '';

    // -------- ADD STUDENT --------
    if ($action === 'add_student') {
        $admissionNumber = trim((string) ($_POST['admission_number'] ?? ''));
        $name            = trim((string) ($_POST['name']             ?? ''));
        $email           = trim((string) ($_POST['email']            ?? ''));
        $mobileNumber    = trim((string) ($_POST['contact_number']   ?? ''));
        $address         = trim((string) ($_POST['address']          ?? ''));
        $username        = trim((string) ($_POST['username']         ?? ''));
        $password        = (string)       ($_POST['password']        ?? '');

        if ($admissionNumber === '' || $name === '' || $email === '' || $mobileNumber === '' || $username === '' || $password === '') {
            $flashError = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flashError = 'Please enter a valid email address.';
        } elseif (!isValidMobile($mobileNumber)) {
            $flashError = 'Mobile number must be exactly 10 digits.';
        }

        if ($flashError === '') {
            try {
                $conn->begin_transaction();

                // Duplicate checks
                $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $chk->bind_param('s', $username);
                $chk->execute();
                $dupUser = $chk->get_result()->num_rows > 0;
                $chk->close();

                $chk2 = $conn->prepare("SELECT id FROM students WHERE email = ? LIMIT 1");
                $chk2->bind_param('s', $email);
                $chk2->execute();
                $dupEmail = $chk2->get_result()->num_rows > 0;
                $chk2->close();

                $chk3 = $conn->prepare("SELECT id FROM students WHERE admission_number = ? LIMIT 1");
                $chk3->bind_param('s', $admissionNumber);
                $chk3->execute();
                $dupAdmission = $chk3->get_result()->num_rows > 0;
                $chk3->close();

                if ($dupUser)      throw new Exception('Username already exists.');
                if ($dupEmail)     throw new Exception('Email is already registered for another student.');
                if ($dupAdmission) throw new Exception('Admission number already exists.');

                // Create user account
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $role = 'STUDENT';

                $stmtU = $conn->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, ?, 'Approved')");
                $stmtU->bind_param('sss', $username, $hash, $role);
                $stmtU->execute();
                $newUserId = (int) $conn->insert_id;
                $stmtU->close();

                // Insert student record
                $stmtS = $conn->prepare("INSERT INTO students (user_id, class_id, admission_number, name, email, contact_number, address) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmtS->bind_param('iisssss', $newUserId, $classId, $admissionNumber, $name, $email, $mobileNumber, $address);
                $stmtS->execute();
                $stmtS->close();

                $conn->commit();
                $flashSuccess = 'Student added successfully.';
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Add Student] ' . $ex->getMessage());
                $flashError = $ex->getMessage() ?: 'A system error occurred while adding the student.';
            }
        }
    }

    // -------- EDIT STUDENT --------
    if ($action === 'edit_student') {
        $studentId       = (int) ($_POST['student_id']      ?? 0);
        $admissionNumber = trim((string) ($_POST['admission_number'] ?? ''));
        $name            = trim((string) ($_POST['name']             ?? ''));
        $email           = trim((string) ($_POST['email']            ?? ''));
        $mobileNumber    = trim((string) ($_POST['contact_number']   ?? ''));
        $address         = trim((string) ($_POST['address']          ?? ''));
        $username        = trim((string) ($_POST['username']         ?? ''));

        if ($studentId <= 0) {
            $flashError = 'Invalid student.';
        } elseif ($admissionNumber === '' || $name === '' || $email === '' || $mobileNumber === '' || $username === '') {
            $flashError = 'Please fill in all required fields.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flashError = 'Please enter a valid email address.';
        } elseif (!isValidMobile($mobileNumber)) {
            $flashError = 'Mobile number must be exactly 10 digits.';
        }

        if ($flashError === '') {
            try {
                $conn->begin_transaction();

                $stmtG = $conn->prepare("SELECT user_id FROM students WHERE id = ? LIMIT 1");
                $stmtG->bind_param('i', $studentId);
                $stmtG->execute();
                $resG = $stmtG->get_result();
                $linkedUserId = $resG && $resG->num_rows > 0 ? (int) ($resG->fetch_assoc()['user_id'] ?? 0) : 0;
                $stmtG->close();

                if ($linkedUserId <= 0) {
                    throw new Exception('Student record not found.');
                }

                // Duplicate checks (excluding self)
                $chk = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1");
                $chk->bind_param('si', $username, $linkedUserId);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) {
                    $chk->close();
                    throw new Exception('Username already exists.');
                }
                $chk->close();

                $chk2 = $conn->prepare("SELECT id FROM students WHERE email = ? AND id != ? LIMIT 1");
                $chk2->bind_param('si', $email, $studentId);
                $chk2->execute();
                if ($chk2->get_result()->num_rows > 0) {
                    $chk2->close();
                    throw new Exception('Email is already registered for another student.');
                }
                $chk2->close();

                $chk3 = $conn->prepare("SELECT id FROM students WHERE admission_number = ? AND id != ? LIMIT 1");
                $chk3->bind_param('si', $admissionNumber, $studentId);
                $chk3->execute();
                if ($chk3->get_result()->num_rows > 0) {
                    $chk3->close();
                    throw new Exception('Admission number already exists.');
                }
                $chk3->close();

                // Update users.username
                $stmtU = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
                $stmtU->bind_param('si', $username, $linkedUserId);
                $stmtU->execute();
                $stmtU->close();

                // Update students record
                $stmtS = $conn->prepare("UPDATE students SET admission_number = ?, name = ?, email = ?, contact_number = ?, address = ? WHERE id = ?");
                $stmtS->bind_param('sssssi', $admissionNumber, $name, $email, $mobileNumber, $address, $studentId);
                $stmtS->execute();
                $stmtS->close();

                $conn->commit();
                $flashSuccess = 'Student updated successfully.';
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Edit Student] ' . $ex->getMessage());
                $flashError = $ex->getMessage() ?: 'A system error occurred while updating the student.';
            }
        }
    }

    // -------- DELETE STUDENT --------
    if ($action === 'delete_student') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        if ($studentId > 0) {
            try {
                $conn->begin_transaction();

                $stmtG = $conn->prepare("SELECT user_id FROM students WHERE id = ? LIMIT 1");
                $stmtG->bind_param('i', $studentId);
                $stmtG->execute();
                $resG = $stmtG->get_result();
                $linkedUserId = $resG && $resG->num_rows > 0 ? (int) ($resG->fetch_assoc()['user_id'] ?? 0) : 0;
                $stmtG->close();

                $stmtD = $conn->prepare("DELETE FROM students WHERE id = ?");
                $stmtD->bind_param('i', $studentId);
                $stmtD->execute();
                $stmtD->close();

                if ($linkedUserId > 0) {
                    $stmtU = $conn->prepare("DELETE FROM users WHERE id = ?");
                    $stmtU->bind_param('i', $linkedUserId);
                    $stmtU->execute();
                    $stmtU->close();
                }

                $conn->commit();
                $flashSuccess = 'Student deleted successfully.';
            } catch (Throwable $ex) {
                if ($conn instanceof mysqli) $conn->rollback();
                error_log('[Delete Student] ' . $ex->getMessage());
                $flashError = 'A system error occurred while deleting the student.';
            }
        }
    }

    // -------- BULK DELETE --------
    if ($action === 'bulk_delete_students') {
        $ids = $_POST['student_ids'] ?? [];
        if (is_array($ids) && !empty($ids)) {
            $cleanIds = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));

            if (!empty($cleanIds)) {
                try {
                    $conn->begin_transaction();

                    $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
                    $types        = str_repeat('i', count($cleanIds));

                    $stmtG = $conn->prepare("SELECT user_id FROM students WHERE id IN ($placeholders)");
                    $stmtG->bind_param($types, ...$cleanIds);
                    $stmtG->execute();
                    $resG = $stmtG->get_result();
                    $userIds = [];
                    while ($row = $resG->fetch_assoc()) {
                        $uid = (int) ($row['user_id'] ?? 0);
                        if ($uid > 0) $userIds[] = $uid;
                    }
                    $stmtG->close();

                    $stmtD = $conn->prepare("DELETE FROM students WHERE id IN ($placeholders)");
                    $stmtD->bind_param($types, ...$cleanIds);
                    $stmtD->execute();
                    $stmtD->close();

                    if (!empty($userIds)) {
                        $uPlaceholders = implode(',', array_fill(0, count($userIds), '?'));
                        $uTypes        = str_repeat('i', count($userIds));
                        $stmtU = $conn->prepare("DELETE FROM users WHERE id IN ($uPlaceholders)");
                        $stmtU->bind_param($uTypes, ...$userIds);
                        $stmtU->execute();
                        $stmtU->close();
                    }

                    $conn->commit();
                    $flashSuccess = count($cleanIds) . ' student(s) deleted successfully.';
                } catch (Throwable $ex) {
                    if ($conn instanceof mysqli) $conn->rollback();
                    error_log('[Bulk Delete Students] ' . $ex->getMessage());
                    $flashError = 'A system error occurred while deleting the students.';
                }
            } else {
                $flashError = 'No valid students selected.';
            }
        } else {
            $flashError = 'Please select at least one student to delete.';
        }
    }

    // -------- BULK IMPORT (CSV) --------
    if ($action === 'import_students') {

        if (!isset($_FILES['import_file']) || !is_array($_FILES['import_file'])) {
            $flashError = 'Please select a file to upload.';
        } elseif ((int) ($_FILES['import_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $flashError = 'File upload failed. Please try again.';
        } else {
            $tmpPath  = (string) ($_FILES['import_file']['tmp_name'] ?? '');
            $origName = (string) ($_FILES['import_file']['name']     ?? '');
            $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            if (!in_array($ext, ['csv', 'txt'], true)) {
                $flashError = 'Unsupported file type. Please upload a CSV file (XLSX/XLS should be saved as CSV first).';
            } elseif (!is_uploaded_file($tmpPath)) {
                $flashError = 'Invalid upload. Please try again.';
            } else {
                $handle = fopen($tmpPath, 'r');
                if ($handle === false) {
                    $flashError = 'Unable to read the uploaded file.';
                } else {
                    @set_time_limit(0);

                    $rowNumber     = 0;
                    $insertedCount = 0;
                    $skippedRows   = [];
                    $seenUsernames = [];
                    $seenEmails    = [];
                    $seenAdmissions= [];

                    try {
                        $conn->begin_transaction();

                        // Prepare insert statements
                        $stmtU = $conn->prepare("INSERT INTO users (username, password, role, status) VALUES (?, ?, 'STUDENT', 'Approved')");
                        $stmtS = $conn->prepare("INSERT INTO students (user_id, class_id, admission_number, name, email, contact_number, address) VALUES (?, ?, ?, ?, ?, ?, ?)");

                        if (!$stmtU || !$stmtS) {
                            throw new Exception('Failed to prepare insert statements.');
                        }

                        // Prepare duplicate check statements
                        $chkUser = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                        $chkMail = $conn->prepare("SELECT id FROM students WHERE email = ? LIMIT 1");
                        $chkAdm  = $conn->prepare("SELECT id FROM students WHERE admission_number = ? LIMIT 1");

                        while (($row = fgetcsv($handle, 0, ',')) !== false) {

                            $rowNumber++;

                            // Skip empty lines
                            if (count($row) === 1 && trim((string) $row[0]) === '') {
                                continue;
                            }

                            // Skip header row on first line
                            if ($rowNumber === 1) {
                                $firstCell = strtolower(trim((string) ($row[0] ?? '')));
                                if ($firstCell === 'admission_number' || $firstCell === 'admission no' || $firstCell === 'admissionno') {
                                    continue;
                                }
                            }

                            // Expect 7 columns
                            if (count($row) < 7) {
                                $skippedRows[] = "Row {$rowNumber}: Not enough columns (expected 7).";
                                continue;
                            }

                            // Extract and trim
                            $rAdmission = trim((string) ($row[0] ?? ''));
                            $rName      = trim((string) ($row[1] ?? ''));
                            $rEmail     = trim((string) ($row[2] ?? ''));
                            $rMobile    = trim((string) ($row[3] ?? ''));
                            $rAddress   = trim((string) ($row[4] ?? ''));
                            $rUsername  = trim((string) ($row[5] ?? ''));
                            $rPassword  = (string)       ($row[6] ?? '');

                            // Remove UTF-8 BOM from first cell
                            $rAdmission = preg_replace('/^\xEF\xBB\xBF/', '', $rAdmission) ?? $rAdmission;

                            // Required fields
                            if ($rAdmission === '' || $rName === '' || $rEmail === '' || $rMobile === '' || $rUsername === '' || $rPassword === '') {
                                $skippedRows[] = "Row {$rowNumber}: Missing required fields.";
                                continue;
                            }
                            if (!filter_var($rEmail, FILTER_VALIDATE_EMAIL)) {
                                $skippedRows[] = "Row {$rowNumber}: Invalid email.";
                                continue;
                            }
                            if (!isValidMobile($rMobile)) {
                                $skippedRows[] = "Row {$rowNumber}: Mobile number must be exactly 10 digits.";
                                continue;
                            }

                            $lowerUser  = strtolower($rUsername);
                            $lowerEmail = strtolower($rEmail);
                            $lowerAdm   = strtolower($rAdmission);

                            // Within-file duplicate checks
                            if (isset($seenUsernames[$lowerUser])) {
                                $skippedRows[] = "Row {$rowNumber}: Duplicate username within file ({$rUsername}).";
                                continue;
                            }
                            if (isset($seenEmails[$lowerEmail])) {
                                $skippedRows[] = "Row {$rowNumber}: Duplicate email within file ({$rEmail}).";
                                continue;
                            }
                            if (isset($seenAdmissions[$lowerAdm])) {
                                $skippedRows[] = "Row {$rowNumber}: Duplicate admission number within file ({$rAdmission}).";
                                continue;
                            }

                            // DB duplicate checks
                            $chkUser->bind_param('s', $rUsername);
                            $chkUser->execute();
                            $chkUser->store_result();
                            if ($chkUser->num_rows > 0) {
                                $chkUser->free_result();
                                $skippedRows[] = "Row {$rowNumber}: Username already exists ({$rUsername}).";
                                continue;
                            }
                            $chkUser->free_result();

                            $chkMail->bind_param('s', $rEmail);
                            $chkMail->execute();
                            $chkMail->store_result();
                            if ($chkMail->num_rows > 0) {
                                $chkMail->free_result();
                                $skippedRows[] = "Row {$rowNumber}: Email already registered ({$rEmail}).";
                                continue;
                            }
                            $chkMail->free_result();

                            $chkAdm->bind_param('s', $rAdmission);
                            $chkAdm->execute();
                            $chkAdm->store_result();
                            if ($chkAdm->num_rows > 0) {
                                $chkAdm->free_result();
                                $skippedRows[] = "Row {$rowNumber}: Admission number already exists ({$rAdmission}).";
                                continue;
                            }
                            $chkAdm->free_result();

                            // Insert user
                            $hash = password_hash($rPassword, PASSWORD_BCRYPT);
                            $stmtU->bind_param('ss', $rUsername, $hash);
                            if (!$stmtU->execute()) {
                                throw new Exception("Row {$rowNumber}: Failed to create user account.");
                            }
                            $newUserId = (int) $conn->insert_id;

                            // Insert student
                            $stmtS->bind_param('iisssss', $newUserId, $classId, $rAdmission, $rName, $rEmail, $rMobile, $rAddress);
                            if (!$stmtS->execute()) {
                                throw new Exception("Row {$rowNumber}: Failed to create student record.");
                            }

                            // Track as successfully inserted
                            $seenUsernames[$lowerUser]   = true;
                            $seenEmails[$lowerEmail]     = true;
                            $seenAdmissions[$lowerAdm]   = true;
                            $insertedCount++;
                        }

                        // Cleanup prepared statements
                        $stmtU->close();
                        $stmtS->close();
                        $chkUser->close();
                        $chkMail->close();
                        $chkAdm->close();

                        // If nothing was inserted, treat as a failed import
                        if ($insertedCount === 0) {
                            throw new Exception('No valid rows were found in the uploaded file.');
                        }

                        $conn->commit();

                        $msg = "Successfully imported {$insertedCount} student(s) into {$className}.";
                        if (!empty($skippedRows)) {
                            $msg .= ' Skipped ' . count($skippedRows) . ' row(s).';
                        }
                        $flashSuccess = $msg;

                        if (!empty($skippedRows)) {
                            $_SESSION['import_skipped_rows'] = $skippedRows;
                        }

                    } catch (Throwable $ex) {
                        if ($conn instanceof mysqli) $conn->rollback();
                        error_log('[Bulk Import] ' . $ex->getMessage());
                        $flashError = $ex->getMessage() ?: 'A system error occurred during the import.';
                    }

                    fclose($handle);
                }
            }
        }
    }

    // -------- RESET PASSWORD --------
    if ($action === 'reset_password') {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        if ($studentId > 0) {
            try {
                $stmtG = $conn->prepare("SELECT user_id FROM students WHERE id = ? LIMIT 1");
                $stmtG->bind_param('i', $studentId);
                $stmtG->execute();
                $resG = $stmtG->get_result();
                $linkedUserId = $resG && $resG->num_rows > 0 ? (int) ($resG->fetch_assoc()['user_id'] ?? 0) : 0;
                $stmtG->close();

                if ($linkedUserId > 0) {
                    $newPassword = 'Student@' . random_int(1000, 9999);
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
        header('Location: students.php?class_id=' . $classId);
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

// Optional detailed skip report from bulk import
$importSkippedRows = [];
if (!empty($_SESSION['import_skipped_rows']) && is_array($_SESSION['import_skipped_rows'])) {
    $importSkippedRows = $_SESSION['import_skipped_rows'];
    unset($_SESSION['import_skipped_rows']);
}

// ---------------------------------------------------------------------------
// 10. FETCH STUDENTS FOR THIS CLASS
// ---------------------------------------------------------------------------
$students = [];

if ($conn instanceof mysqli) {
    try {
        $sql = "SELECT  st.id,
                        st.user_id,
                        st.class_id,
                        st.admission_number,
                        st.name,
                        st.email,
                        st.contact_number,
                        st.address,
                        u.username,
                        u.status
                FROM students st
                LEFT JOIN users u ON st.user_id = u.id
                WHERE st.class_id = ?
                ORDER BY st.admission_number ASC, st.id ASC";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $classId);
            $stmt->execute();
            $res = $stmt->get_result();

            while ($row = $res->fetch_assoc()) {
                $students[] = $row;
            }
            $stmt->close();
        }
    } catch (Throwable $ex) {
        error_log('[Fetch Students] ' . $ex->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="description" content="Manage students — Rajagiri College Grievance Redressal Portal." />
  <meta name="theme-color" content="#DB0878" />
  <title>Student — Admin | Rajagiri College Grievance Portal</title>
  <link rel="icon" type="image/svg+xml" href="../public/favicon.svg" />

  <!-- Fonts -->
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
            brandPurple: '#4A154B',
            brandPink: '#E5097F',
            brandGreen: '#006837',
            brandGold: '#C5A059',
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

  <link rel="stylesheet" href="../assets/css/index.css" />

  <style>
    html { scroll-behavior: smooth; }
    a:focus-visible, button:focus-visible, input:focus-visible,
    textarea:focus-visible, select:focus-visible {
      outline: 3px solid #1F0A24;
      outline-offset: 3px;
    }
    .on-ink a:focus-visible, .on-ink button:focus-visible { outline-color: #fff; }
    .balance { text-wrap: balance; }

    .btn-hard {
      border: 2px solid #1F0A24;
      box-shadow: 4px 4px 0 #1F0A24;
      transition: transform .12s ease, box-shadow .12s ease, background-color .15s ease;
    }
    .btn-hard:hover  { transform: translate(2px, 2px); box-shadow: 2px 2px 0 #1F0A24; }
    .btn-hard:active { transform: translate(4px, 4px); box-shadow: 0 0 0 #1F0A24; }

    .card-hard {
      border: 2px solid #1F0A24;
      box-shadow: 6px 6px 0 #FFC93C;
      transition: transform .15s ease, box-shadow .15s ease;
    }
    .card-hard:hover { transform: translate(-2px, -2px); box-shadow: 10px 10px 0 #FFC93C; }

    @keyframes rise {
      from { opacity: 0; transform: translateY(16px); }
      to   { opacity: 1; transform: none; }
    }
    .rise   { animation: rise .6s cubic-bezier(.2, .7, .2, 1) both; }
    .rise-2 { animation-delay: .12s; }
    .rise-3 { animation-delay: .24s; }

    @keyframes flashIn {
      from { opacity: 0; transform: translateY(-10px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    @keyframes flashOut {
      from { opacity: 1; transform: translateY(0); max-height: 200px; }
      to   { opacity: 0; transform: translateY(-10px); max-height: 0; }
    }
    .animate-flash-in  { animation: flashIn .35s cubic-bezier(.16, 1, .3, 1) forwards; }
    .animate-flash-out { animation: flashOut .45s cubic-bezier(.4, 0, 1, 1) forwards; }

    @keyframes modalIn {
      from { opacity: 0; transform: scale(.96); }
      to   { opacity: 1; transform: scale(1); }
    }
    .animate-modal-in { animation: modalIn .25s cubic-bezier(.16, 1, .3, 1) forwards; }

    @keyframes confirmShake {
      0%, 100% { transform: translateX(0); }
      20%      { transform: translateX(-6px); }
      40%      { transform: translateX(6px); }
      60%      { transform: translateX(-4px); }
      80%      { transform: translateX(4px); }
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

  <!-- Oréll Grievance logo symbol -->
  <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="orel-logo" viewBox="0 0 128 40" style="overflow:visible">
      <path fill="#DB0878" d="M10 0H26A10 10 0 0 1 36 10V20A10 10 0 0 1 26 30H16L8 38V29.8A10 10 0 0 1 0 20V10A10 10 0 0 1 10 0Z"/>
      <path d="M10 15.5l5.5 5.5L27 9.5" fill="none" stroke="#FFC93C" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round"/>
      <text x="46" y="21" font-family="Bricolage Grotesque, system-ui, sans-serif" font-size="23" font-weight="800" fill="#1F0A24">Oréll</text>
      <text x="46.5" y="35" font-family="Figtree, system-ui, sans-serif" font-size="11" font-weight="700" letter-spacing="0.6" fill="#DB0878">Grievance</text>
    </symbol>
  </svg>

  <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[60] focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-ink focus:shadow-lg">
    Skip to main content
  </a>

  <div class="flex min-h-screen">

    <!-- ============================================================
         SIDEBAR
         ============================================================ -->
    <aside class="w-20 on-ink bg-ink flex flex-col items-center py-4 fixed inset-y-0 left-0 z-40">

      <div class="absolute top-0 left-0 right-0 flex h-1.5" aria-hidden="true">
        <span class="flex-1 bg-hot"></span>
        <span class="flex-1 bg-sun"></span>
        <span class="flex-1 bg-leaf"></span>
        <span class="flex-1 bg-grape"></span>
      </div>

      <a href="dashboard.php"
         class="nav-icon-link group relative mt-6 w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white"
         title="Dashboard" aria-label="Dashboard">
        <i data-lucide="home" class="w-6 h-6"></i>
        <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-ink text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50 border border-white/10">
          Dashboard
        </span>
      </a>

      <nav class="flex flex-col items-center space-y-4 flex-1 mt-8" aria-label="Admin navigation">

        <a href="profile.php"
           class="nav-icon-link group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white"
           title="Profile" aria-label="Profile">
          <i data-lucide="user" class="w-6 h-6"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-ink text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50 border border-white/10">
            Profile
          </span>
        </a>

        <a href="settings.php"
           class="nav-icon-link group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center text-white"
           title="Settings" aria-label="Settings">
          <i data-lucide="settings" class="w-6 h-6 group-hover:rotate-90 transition-transform duration-500"></i>
          <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-ink text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50 border border-white/10">
            Settings
          </span>
        </a>

      </nav>

      <a href="../logout.php?role=admin"
         id="sidebarLogoutBtn"
         class="nav-icon-link group relative w-12 h-12 rounded-xl bg-white/10 hover:bg-hot flex items-center justify-center text-white"
         title="Logout" aria-label="Logout">
        <i data-lucide="log-out" class="w-6 h-6 group-hover:translate-x-0.5 transition-transform"></i>
        <span class="absolute left-full ml-3 hidden group-hover:block whitespace-nowrap bg-hot text-white text-xs font-semibold px-3 py-1.5 rounded-lg shadow-lg z-50">
          Logout
        </span>
      </a>
    </aside>

    <!-- ============================================================
         MAIN CONTENT
         ============================================================ -->
    <div class="flex-1 ml-20 flex flex-col min-h-screen">

      <!-- ============ TOP HEADER ============ -->
      <header class="sticky top-0 z-30 bg-white border-b border-slate-200">
        <div class="flex h-1.5" aria-hidden="true">
          <span class="flex-1 bg-hot"></span>
          <span class="flex-1 bg-sun"></span>
          <span class="flex-1 bg-leaf"></span>
          <span class="flex-1 bg-grape"></span>
        </div>

        <div class="flex items-center justify-between px-4 sm:px-6 py-3 md:py-4">

          <div class="flex items-center gap-4">
            <a href="dashboard.php" class="flex items-center gap-4 rounded-lg">
              <img src="../public/rcss-logo.png" alt="RCSS Logo" class="h-10 md:h-12 w-auto" />
              <span class="hidden sm:block h-8 w-px bg-slate-200" aria-hidden="true"></span>
              <svg class="hidden sm:block h-9 md:h-10 w-auto" width="128" height="40" viewBox="0 0 128 40" role="img" aria-label="Oréll Grievance">
                <use href="#orel-logo"></use>
              </svg>
            </a>
          </div>

          <div class="relative" id="admin-dropdown-container">
            <button id="admin-dropdown-btn"
                    type="button"
                    aria-haspopup="true"
                    aria-expanded="false"
                    aria-controls="admin-dropdown-menu"
                    class="flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2 transition-colors hover:bg-blush">

              <?php if ($hasProfilePicture): ?>
                <img src="<?= e($profilePictureUrl) ?>"
                     alt="<?= e($displayName) ?>"
                     class="w-10 h-10 rounded-full object-cover border-2 border-ink shadow-[2px_2px_0_#FFC93C]" />
              <?php else: ?>
                <span class="w-10 h-10 rounded-full bg-hot flex items-center justify-center text-white border-2 border-ink shadow-[2px_2px_0_#FFC93C]">
                  <i data-lucide="user" class="w-5 h-5 text-white"></i>
                </span>
              <?php endif; ?>

              <span class="hidden sm:block text-sm font-bold text-ink"><?= e($displayName) ?></span>
              <i data-lucide="chevron-down" id="admin-chevron" class="w-4 h-4 text-slate-500 transition-transform duration-300"></i>
            </button>

            <div id="admin-dropdown-menu"
                 class="hidden absolute right-0 z-50 mt-3 w-72 rounded-xl border-2 border-ink bg-white p-2 shadow-[6px_6px_0_#FFC93C]"
                 role="menu">

              <div class="rounded-lg bg-blush px-3 py-3">
                <div class="flex items-center gap-3">
                  <?php if ($hasProfilePicture): ?>
                    <img src="<?= e($profilePictureUrl) ?>" alt="<?= e($displayName) ?>"
                         class="w-12 h-12 rounded-full object-cover border-2 border-ink" />
                  <?php else: ?>
                    <span class="w-12 h-12 rounded-full bg-hot flex items-center justify-center text-white border-2 border-ink">
                      <i data-lucide="user" class="w-6 h-6 text-white"></i>
                    </span>
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

              <a href="settings.php" role="menuitem" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 transition-colors hover:bg-blush hover:text-ink group">
                <i data-lucide="settings" class="w-4 h-4 text-hot group-hover:scale-110 transition-transform"></i>
                <span class="font-semibold">Settings</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-ink transition-opacity"></i>
              </a>

              <a href="change_password.php" role="menuitem" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 transition-colors hover:bg-blush hover:text-ink group">
                <i data-lucide="key" class="w-4 h-4 text-[#B37A00] group-hover:scale-110 transition-transform"></i>
                <span class="font-semibold">Change Password</span>
                <i data-lucide="arrow-right" class="w-4 h-4 ml-auto opacity-0 group-hover:opacity-100 text-ink transition-opacity"></i>
              </a>

              <div class="my-2 border-t border-slate-200" role="separator"></div>

              <a href="../logout.php?role=admin" id="dropdownLogoutBtn" role="menuitem" class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm text-slate-700 transition-colors hover:bg-blush hover:text-hot group">
                <i data-lucide="log-out" class="w-4 h-4 text-hot group-hover:scale-110 transition-transform"></i>
                <span class="font-semibold">Logout</span>
              </a>
            </div>
          </div>

        </div>
      </header>

      <!-- ============ PAGE CONTENT ============ -->
      <main id="main-content" class="flex-1 px-4 sm:px-6 py-8">

        <!-- Breadcrumb + Action Buttons -->
        <div class="max-w-6xl mx-auto mb-6 rise">
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">

            <div>
              <h1 class="font-display text-2xl md:text-3xl font-extrabold tracking-tight text-ink mb-2">
                Student
              </h1>
              <nav class="flex flex-wrap items-center gap-2 text-sm text-slate-500" aria-label="Breadcrumb">
                <a href="dashboard.php" class="flex items-center rounded transition-colors hover:text-hot">
                  <i data-lucide="layout-dashboard" class="w-4 h-4 mr-1"></i>
                  Dashboard
                </a>
                <span class="text-slate-300">/</span>
                <a href="settings.php" class="rounded transition-colors hover:text-hot">Settings</a>
                <span class="text-slate-300">/</span>
                <a href="classes.php" class="rounded transition-colors hover:text-hot">Course/semester</a>
                <span class="text-slate-300">/</span>
                <span class="text-hot font-semibold">Student</span>
              </nav>
            </div>

            <div class="flex items-center gap-2">

              <button type="button"
                      onclick="openImportModal()"
                      title="Import Students via Excel/CSV"
                      aria-label="Import students"
                      class="btn-hard inline-flex items-center justify-center w-11 h-11 rounded-xl bg-sun text-ink hover:bg-[#FFD35C]">
                <i data-lucide="file-up" class="w-5 h-5"></i>
              </button>

              <button type="button"
                      id="bulkDeleteBtn"
                      title="Delete selected"
                      aria-label="Delete selected students"
                      class="btn-hard inline-flex items-center justify-center w-11 h-11 rounded-xl bg-white text-ink hover:bg-blush">
                <i data-lucide="trash-2" class="w-5 h-5"></i>
              </button>

              <button type="button"
                      onclick="openStudentModal('add')"
                      title="Add Student"
                      aria-label="Add student"
                      class="btn-hard inline-flex items-center justify-center w-11 h-11 rounded-xl bg-hot text-white hover:bg-hotdark">
                <i data-lucide="plus" class="w-5 h-5"></i>
              </button>

            </div>
          </div>
        </div>

        <!-- Class Name Sub-header -->
        <div class="max-w-6xl mx-auto mb-6 text-center rise rise-2">
          <h2 class="font-display text-lg md:text-xl font-bold text-ink">
            Class Name : <span class="text-hot"><?= e($className) ?></span>
          </h2>
        </div>

        <!-- Flash Messages -->
        <?php if ($flashSuccess !== ''): ?>
          <div id="flashSuccessBox"
               class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-leaf bg-[#DCEFE4] px-4 py-3 flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="check-circle" class="w-5 h-5 text-leaf flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-leaf font-semibold"><?= e($flashSuccess) ?></p>
          </div>
        <?php endif; ?>

        <?php if ($flashError !== ''): ?>
          <div id="flashErrorBox"
               class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-red-300 bg-red-50 px-4 py-3 flex items-start gap-2 animate-flash-in overflow-hidden">
            <i data-lucide="alert-circle" class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5"></i>
            <p class="text-sm text-red-700 font-semibold"><?= e($flashError) ?></p>
          </div>
        <?php endif; ?>

        <!-- Import skip report -->
        <?php if (!empty($importSkippedRows)): ?>
          <div class="max-w-6xl mx-auto mb-6 rounded-xl border-2 border-amber-300 bg-amber-50 px-4 py-3 rise">
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

        <!-- ============ TABLE CONTROLS ============ -->
        <div class="max-w-6xl mx-auto mb-5 rise rise-3">
          <div class="bg-white rounded-2xl border-2 border-ink px-5 py-4 shadow-[4px_4px_0_#FFC93C]">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">

              <div class="flex items-center gap-3">
                <span class="text-sm text-slate-600">Show</span>
                <select id="entriesPerPage"
                        class="px-3 py-1.5 border-2 border-ink rounded-lg text-sm font-semibold text-ink bg-white
                               focus:outline-none focus:ring-4 focus:ring-hot/10 transition-colors">
                  <option value="10" selected>10</option>
                  <option value="25">25</option>
                  <option value="50">50</option>
                  <option value="100">100</option>
                </select>
                <span class="text-sm text-slate-600">entries</span>
              </div>

              <div class="relative w-full sm:w-80">
                <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
                <input type="text"
                       id="searchInput"
                       placeholder="Search.."
                       class="w-full pl-10 pr-4 py-2 border-2 border-ink rounded-lg text-sm bg-white
                              focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
              </div>

            </div>
          </div>
        </div>

        <!-- ============ DATA TABLE ============ -->
        <div class="max-w-6xl mx-auto rise rise-3">
          <div class="bg-white rounded-2xl border-2 border-ink overflow-hidden shadow-[6px_6px_0_#FFC93C]">

            <form id="bulkForm" method="POST" action="students.php?class_id=<?= (int) $classId ?>">
              <input type="hidden" name="action" value="bulk_delete_students" />

              <div class="overflow-x-auto">
                <table class="w-full" id="studentsTable">
                  <thead>
                    <tr class="bg-ink text-white">
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Sl.No.</th>
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Admission No.</th>
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Name</th>
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Address</th>
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Email</th>
                      <th class="px-6 py-4 text-left text-xs font-bold uppercase tracking-wider">Mobile Number</th>
                      <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                          <input type="checkbox" id="selectAllCheckbox"
                                 class="w-4 h-4 rounded border-slate-300 text-hot focus:ring-hot/30 cursor-pointer" />
                          <span>Select All</span>
                        </label>
                      </th>
                      <th class="px-6 py-4 text-center text-xs font-bold uppercase tracking-wider">Actions</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-slate-100" id="studentsTableBody">

                    <?php if (empty($students)): ?>

                      <tr>
                        <td colspan="8" class="px-6 py-16 text-center text-slate-500">
                          <div class="flex flex-col items-center justify-center">
                            <span class="w-16 h-16 rounded-full bg-blush border-2 border-ink flex items-center justify-center mb-4">
                              <i data-lucide="users" class="w-8 h-8 text-hot"></i>
                            </span>
                            <p class="text-lg font-bold text-ink">No students yet</p>
                            <p class="text-sm text-slate-500 mt-1 mb-4">
                              Click "Add Student" to enroll the first student, or use the import button above.
                            </p>
                          </div>
                        </td>
                      </tr>

                    <?php else: ?>

                      <?php foreach ($students as $index => $student): ?>
                        <?php
                          $studentId        = (int) $student['id'];
                          $studentAdmission = (string) ($student['admission_number'] ?? '');
                          $studentName      = (string) ($student['name']             ?? '');
                          $studentEmail     = (string) ($student['email']            ?? '');
                          $studentMob       = (string) ($student['contact_number']   ?? '');
                          $studentAddr      = (string) ($student['address']          ?? '');
                          $studentUser      = (string) ($student['username']         ?? '');
                        ?>
                        <tr class="hover:bg-blush/60 transition-colors group">
                          <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900">
                            <?= $index + 1 ?>
                          </td>
                          <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-hot">
                            <?= e($studentAdmission !== '' ? $studentAdmission : '—') ?>
                          </td>
                          <td class="px-6 py-4 text-sm font-semibold text-ink">
                            <?= e($studentName) ?>
                          </td>
                          <td class="px-6 py-4 text-sm text-slate-600 max-w-[200px]">
                            <?= e($studentAddr !== '' ? $studentAddr : '—') ?>
                          </td>
                          <td class="px-6 py-4 text-sm text-slate-600 break-all">
                            <?= e($studentEmail) ?>
                          </td>
                          <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600">
                            <?= e($studentMob !== '' ? $studentMob : '—') ?>
                          </td>
                          <td class="px-6 py-4 whitespace-nowrap text-center">
                            <input type="checkbox"
                                   name="student_ids[]"
                                   value="<?= $studentId ?>"
                                   class="student-checkbox w-4 h-4 rounded border-slate-300 text-hot focus:ring-hot/30 cursor-pointer" />
                          </td>
                          <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center justify-center gap-1.5">

                              <button type="button"
                                      title="Edit student"
                                      aria-label="Edit student"
                                      onclick='openStudentModal("edit", <?= $studentId ?>, <?= json_encode($studentAdmission) ?>, <?= json_encode($studentName) ?>, <?= json_encode($studentEmail) ?>, <?= json_encode($studentMob) ?>, <?= json_encode($studentAddr) ?>, <?= json_encode($studentUser) ?>)'
                                      class="w-8 h-8 rounded-full bg-blush border-2 border-ink flex items-center justify-center text-ink hover:bg-hot hover:text-white transition-all duration-200">
                                <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                              </button>

                              <button type="button"
                                      title="View details"
                                      aria-label="View student"
                                      onclick='openViewModal(<?= json_encode($studentAdmission) ?>, <?= json_encode($studentName) ?>, <?= json_encode($studentEmail) ?>, <?= json_encode($studentMob) ?>, <?= json_encode($studentAddr) ?>, <?= json_encode($studentUser) ?>)'
                                      class="w-8 h-8 rounded-full bg-blush border-2 border-ink flex items-center justify-center text-ink hover:bg-grape hover:text-white transition-all duration-200">
                                <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                              </button>

                              <button type="button"
                                      title="Reset password"
                                      aria-label="Reset password"
                                      onclick='confirmResetPassword(<?= $studentId ?>, <?= json_encode($studentName) ?>)'
                                      class="w-8 h-8 rounded-full bg-blush border-2 border-ink flex items-center justify-center text-ink hover:bg-sun hover:text-ink transition-all duration-200">
                                <i data-lucide="lock" class="w-3.5 h-3.5"></i>
                              </button>

                              <button type="button"
                                      title="Delete student"
                                      aria-label="Delete student"
                                      onclick='confirmDeleteStudent(<?= $studentId ?>, <?= json_encode($studentName) ?>)'
                                      class="w-8 h-8 rounded-full bg-blush border-2 border-ink flex items-center justify-center text-ink hover:bg-red-500 hover:text-white transition-all duration-200">
                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                              </button>

                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>

                    <?php endif; ?>

                  </tbody>
                </table>
              </div>
            </form>

            <!-- Footer Info & Pagination -->
            <?php if (!empty($students)): ?>
              <div class="px-6 py-4 bg-blush border-t-2 border-ink flex flex-col sm:flex-row items-center justify-between gap-4">

                <p class="text-sm text-slate-600" id="tableInfo">
                  Showing <span class="font-semibold text-ink">1</span> to
                  <span class="font-semibold text-ink"><?= count($students) ?></span> of
                  <span class="font-semibold text-ink"><?= count($students) ?></span> entries
                </p>

                <div class="flex items-center gap-2">
                  <button type="button"
                          class="px-4 py-2 rounded-lg text-sm font-semibold text-slate-400 cursor-not-allowed"
                          disabled>
                    Previous
                  </button>

                  <span class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-hot border-2 border-ink text-white text-sm font-bold shadow-[2px_2px_0_#FFC93C]">
                    1
                  </span>

                  <button type="button"
                          class="px-4 py-2 rounded-lg text-sm font-semibold text-slate-400 cursor-not-allowed"
                          disabled>
                    Next
                  </button>
                </div>

              </div>
            <?php endif; ?>

          </div>
        </div>

      </main>

      <!-- ============ FOOTER ============ -->
      <footer class="on-ink bg-ink border-t-[6px] border-sun mt-auto">
        <div class="px-4 sm:px-6 py-10">
          <div class="max-w-7xl mx-auto">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-8">

              <div class="flex items-start gap-4">
                <span class="inline-flex items-center gap-3 rounded-xl bg-white px-3 py-2">
                  <img src="../public/rcss-logo.png" alt="RCSS Logo" class="h-10 w-auto" />
                  <svg class="h-8 w-auto" width="128" height="40" viewBox="0 0 128 40" role="img" aria-label="Oréll Grievance">
                    <use href="#orel-logo"></use>
                  </svg>
                </span>
                <div>
                  <p class="font-display font-bold text-white text-sm">
                    Rajagiri College of Social Sciences
                  </p>
                  <p class="text-xs text-slate-300 mt-1">
                    Grievance Redressal Portal
                  </p>
                </div>
              </div>

              <div>
                <h4 class="font-display font-bold text-sm text-sun mb-3">Quick Links</h4>
                <ul class="space-y-2 text-xs text-slate-200">
                  <li>
                    <a href="dashboard.php" class="inline-flex items-center gap-2 rounded transition-colors hover:text-white hover:underline underline-offset-4 group">
                      <i data-lucide="arrow-right" class="w-3 h-3 text-sun group-hover:translate-x-0.5 transition-transform"></i>
                      <span>Dashboard</span>
                    </a>
                  </li>
                  <li>
                    <a href="profile.php" class="inline-flex items-center gap-2 rounded transition-colors hover:text-white hover:underline underline-offset-4 group">
                      <i data-lucide="arrow-right" class="w-3 h-3 text-sun group-hover:translate-x-0.5 transition-transform"></i>
                      <span>My Profile</span>
                    </a>
                  </li>
                  <li>
                    <a href="settings.php" class="inline-flex items-center gap-2 rounded transition-colors hover:text-white hover:underline underline-offset-4 group">
                      <i data-lucide="arrow-right" class="w-3 h-3 text-sun group-hover:translate-x-0.5 transition-transform"></i>
                      <span>Settings</span>
                    </a>
                  </li>
                  <li>
                    <a href="change_password.php" class="inline-flex items-center gap-2 rounded transition-colors hover:text-white hover:underline underline-offset-4 group">
                      <i data-lucide="arrow-right" class="w-3 h-3 text-sun group-hover:translate-x-0.5 transition-transform"></i>
                      <span>Change Password</span>
                    </a>
                  </li>
                </ul>
              </div>

              <div>
                <h4 class="font-display font-bold text-sm text-sun mb-3">Contact Support</h4>
                <ul class="space-y-2 text-xs text-slate-200">
                  <li class="flex items-center gap-2">
                    <i data-lucide="mail" class="w-3.5 h-3.5 text-sun"></i>
                    <a href="mailto:admin.support@rajagiri.edu" class="rounded transition-colors hover:text-white hover:underline underline-offset-4">admin.support@rajagiri.edu</a>
                  </li>
                  <li class="flex items-center gap-2">
                    <i data-lucide="phone" class="w-3.5 h-3.5 text-sun"></i>
                    <span>+91 484 XXX XXXX</span>
                  </li>
                  <li class="flex items-center gap-2">
                    <i data-lucide="map-pin" class="w-3.5 h-3.5 text-sun"></i>
                    <span>Kalamassery, Kochi, Kerala</span>
                  </li>
                </ul>
              </div>

            </div>

            <div class="border-t border-white/15 pt-6">
              <div class="flex flex-col sm:flex-row items-center justify-between gap-2">
                <p class="text-xs text-slate-400 text-center sm:text-left">
                  &copy; <?= date('Y') ?>
                  <span class="font-bold text-white">Rajagiri College of Social Sciences</span>.
                  All rights reserved.
                </p>
                <p class="text-xs text-slate-400">
                  Powered by
                  <span class="font-bold text-sun ml-1">Oréll Grievance</span>
                </p>
              </div>
            </div>

          </div>
        </div>
      </footer>

    </div>
  </div>

  <!-- ============================================================
       BULK IMPORT MODAL
       ============================================================ -->
  <div id="importModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeImportModal()"></div>

    <div class="relative w-full max-w-lg bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden">

      <div class="h-1.5 w-full bg-hot"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b-2 border-ink bg-blush">
        <div class="flex items-center gap-3">
          <span class="w-9 h-9 rounded-lg bg-sun border-2 border-ink flex items-center justify-center">
            <i data-lucide="file-up" class="w-5 h-5 text-ink"></i>
          </span>
          <h3 class="font-display text-lg font-bold text-ink">Import Students via Excel/CSV</h3>
        </div>
        <button type="button" onclick="closeImportModal()" aria-label="Close"
                class="w-8 h-8 rounded-lg border-2 border-ink bg-white hover:bg-blush flex items-center justify-center text-ink transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="importForm" method="POST" action="students.php?class_id=<?= (int) $classId ?>" enctype="multipart/form-data" class="p-6 space-y-5">
        <input type="hidden" name="action" value="import_students" />

        <div class="rounded-xl border-2 border-ink bg-blush px-4 py-3">
          <p class="text-xs text-slate-700 leading-relaxed">
            <span class="font-bold text-hot">Column order required:</span>
            <code class="text-[11px] bg-white px-1.5 py-0.5 rounded border border-slate-200">admission_number, name, email, contact_number, address, username, password</code>
          </p>
          <p class="text-xs text-slate-600 mt-1.5">
            All students will be enrolled into
            <span class="font-semibold text-hot"><?= e($className) ?></span>.
          </p>
        </div>

        <div class="space-y-2">
          <label for="import_file" class="block text-sm font-semibold text-ink">
            Select File <span class="text-hot">*</span>
          </label>
          <input type="file" name="import_file" id="import_file" required
                 accept=".csv, .xlsx, .xls"
                 class="w-full text-sm text-slate-700
                        file:mr-3 file:py-2.5 file:px-4
                        file:rounded-lg file:border-2 file:border-ink
                        file:text-sm file:font-bold
                        file:bg-hot file:text-white
                        hover:file:bg-hotdark
                        border-2 border-ink rounded-xl
                        focus:outline-none focus:ring-4 focus:ring-hot/10
                        transition-all cursor-pointer bg-white" />
          <p class="text-xs text-slate-500 mt-1">
            Accepted formats: <span class="font-medium">.csv</span> (recommended).
            Excel files must be saved as CSV first.
          </p>
        </div>

        <div class="flex items-center justify-between rounded-xl bg-white border-2 border-ink px-4 py-3">
          <div class="flex items-start gap-3">
            <i data-lucide="file-spreadsheet" class="w-5 h-5 text-hot mt-0.5 flex-shrink-0"></i>
            <div>
              <p class="text-sm font-bold text-ink">Need the correct format?</p>
              <p class="text-xs text-slate-500">Download the sample CSV template.</p>
            </div>
          </div>
          <a href="students.php?class_id=<?= (int) $classId ?>&download_template=1"
             class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border-2 border-ink bg-sun hover:bg-[#FFD35C] text-ink font-bold text-xs transition-colors whitespace-nowrap">
            <i data-lucide="download" class="w-4 h-4"></i>
            <span>Sample Template</span>
          </a>
        </div>

        <div class="flex justify-center pt-2 gap-3">
          <button type="button" onclick="closeImportModal()"
                  class="px-6 py-3 rounded-xl font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">
            Cancel
          </button>
          <button type="submit"
                  class="btn-hard inline-flex items-center gap-2 rounded-xl bg-hot px-8 py-3 text-sm font-bold text-white hover:bg-hotdark">
            <i data-lucide="upload" class="w-4 h-4"></i>
            <span>Import</span>
          </button>
        </div>
      </form>

    </div>
  </div>

  <!-- ============================================================
       ADD / EDIT STUDENT MODAL
       ============================================================ -->
  <div id="studentModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeStudentModal()"></div>

    <div class="relative w-full max-w-2xl bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden">

      <div class="h-1.5 w-full bg-hot"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b-2 border-ink bg-blush">
        <h3 id="studentModalTitle" class="font-display text-lg font-bold text-ink">Add Student</h3>
        <button type="button" onclick="closeStudentModal()" aria-label="Close"
                class="w-8 h-8 rounded-lg border-2 border-ink bg-white hover:bg-blush flex items-center justify-center text-ink transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <form id="studentForm" method="POST" action="students.php?class_id=<?= (int) $classId ?>" class="p-6 space-y-4">
        <input type="hidden" name="action" id="formAction" value="add_student" />
        <input type="hidden" name="student_id" id="formStudentId" value="" />

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-2">
            <label for="admission_number" class="block text-sm font-semibold text-ink">
              Admission Number <span class="text-hot">*</span>
            </label>
            <input type="text" name="admission_number" id="admission_number" required
                   placeholder="e.g. RCSS2025001"
                   class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium
                          placeholder-slate-400
                          focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
          </div>

          <div class="space-y-2">
            <label for="name" class="block text-sm font-semibold text-ink">
              Full Name <span class="text-hot">*</span>
            </label>
            <input type="text" name="name" id="name" required
                   placeholder="e.g. John Doe"
                   class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium
                          placeholder-slate-400
                          focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-2">
            <label for="email" class="block text-sm font-semibold text-ink">
              Email <span class="text-hot">*</span>
            </label>
            <input type="email" name="email" id="email" required
                   placeholder="e.g. student@rajagiri.edu"
                   class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium
                          placeholder-slate-400
                          focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
          </div>

          <div class="space-y-2">
            <label for="contact_number" class="block text-sm font-semibold text-ink">
              Mobile Number <span class="text-hot">*</span>
            </label>
            <input type="tel" name="contact_number" id="contact_number" required
                   inputmode="numeric" pattern="[0-9]{10}" minlength="10" maxlength="10"
                   title="Please enter exactly 10 digits"
                   placeholder="e.g. 9876543210"
                   class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium
                          placeholder-slate-400
                          focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
            <p class="text-[11px] text-slate-500 mt-1">Enter exactly 10 digits (numbers only).</p>
          </div>
        </div>

        <div class="space-y-2">
          <label for="address" class="block text-sm font-semibold text-ink">Address</label>
          <textarea name="address" id="address" rows="2"
                    placeholder="e.g. Kochi, Kerala"
                    class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium
                           placeholder-slate-400 resize-none
                           focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all"></textarea>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="space-y-2">
            <label for="username" class="block text-sm font-semibold text-ink">
              Username <span class="text-hot">*</span>
            </label>
            <input type="text" name="username" id="username" required
                   placeholder="e.g. johndoe"
                   class="w-full px-4 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium
                          placeholder-slate-400
                          focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />
          </div>

          <div class="space-y-2" id="passwordFieldWrapper">
            <label for="password" class="block text-sm font-semibold text-ink">
              Password <span class="text-hot">*</span>
            </label>
            <div class="relative">
              <input type="password" name="password" id="password"
                     placeholder="e.g. Student@123"
                     class="w-full pl-4 pr-12 py-3 border-2 border-ink rounded-xl bg-white text-ink font-medium
                            placeholder-slate-400
                            focus:outline-none focus:ring-4 focus:ring-hot/10 transition-all" />

              <button type="button" id="togglePasswordBtn"
                      title="Show password" aria-label="Show password" tabindex="-1"
                      class="absolute right-2 top-1/2 -translate-y-1/2 w-9 h-9 rounded-lg
                             flex items-center justify-center
                             text-slate-500 hover:text-hot hover:bg-blush
                             transition-colors">
                <i data-lucide="eye" id="togglePasswordIcon" class="w-5 h-5"></i>
              </button>
            </div>
          </div>
        </div>

        <div class="flex justify-center pt-3 gap-3">
          <button type="button" onclick="closeStudentModal()"
                  class="px-6 py-3 rounded-xl font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">
            Cancel
          </button>
          <button type="submit"
                  class="btn-hard inline-flex items-center rounded-xl bg-hot px-8 py-3 text-sm font-bold text-white hover:bg-hotdark">
            Save
          </button>
        </div>
      </form>

    </div>
  </div>

  <!-- ============================================================
       VIEW STUDENT MODAL
       ============================================================ -->
  <div id="viewModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeViewModal()"></div>

    <div class="relative w-full max-w-md bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden">

      <div class="h-1.5 w-full bg-hot"></div>

      <div class="flex items-center justify-between px-6 py-4 border-b-2 border-ink bg-blush">
        <h3 class="font-display text-lg font-bold text-ink">Student Details</h3>
        <button type="button" onclick="closeViewModal()" aria-label="Close"
                class="w-8 h-8 rounded-lg border-2 border-ink bg-white hover:bg-blush flex items-center justify-center text-ink transition-colors">
          <i data-lucide="x" class="w-5 h-5"></i>
        </button>
      </div>

      <div class="p-6 space-y-4">

        <div class="flex items-center gap-4 pb-4 border-b-2 border-slate-100">
          <span class="w-14 h-14 rounded-full bg-hot border-2 border-ink flex items-center justify-center text-white shadow-[2px_2px_0_#FFC93C]">
            <i data-lucide="user" class="w-7 h-7"></i>
          </span>
          <div class="min-w-0">
            <p id="viewName" class="text-base font-bold text-ink truncate">—</p>
            <p id="viewUsername" class="text-xs text-slate-500 truncate">@—</p>
          </div>
        </div>

        <div class="space-y-3">
          <div class="flex items-start gap-3">
            <i data-lucide="hash" class="w-4 h-4 text-hot mt-1 flex-shrink-0"></i>
            <div class="min-w-0">
              <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Admission Number</p>
              <p id="viewAdmission" class="text-sm text-slate-700 break-all">—</p>
            </div>
          </div>

          <div class="flex items-start gap-3">
            <i data-lucide="mail" class="w-4 h-4 text-hot mt-1 flex-shrink-0"></i>
            <div class="min-w-0">
              <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Email</p>
              <p id="viewEmail" class="text-sm text-slate-700 break-all">—</p>
            </div>
          </div>

          <div class="flex items-start gap-3">
            <i data-lucide="phone" class="w-4 h-4 text-hot mt-1 flex-shrink-0"></i>
            <div class="min-w-0">
              <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Mobile Number</p>
              <p id="viewMobile" class="text-sm text-slate-700">—</p>
            </div>
          </div>

          <div class="flex items-start gap-3">
            <i data-lucide="map-pin" class="w-4 h-4 text-hot mt-1 flex-shrink-0"></i>
            <div class="min-w-0">
              <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Address</p>
              <p id="viewAddress" class="text-sm text-slate-700 break-words">—</p>
            </div>
          </div>
        </div>

      </div>

      <div class="px-6 py-4 bg-blush border-t-2 border-ink flex justify-end">
        <button type="button" onclick="closeViewModal()"
                class="px-5 py-2.5 rounded-lg font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">
          Close
        </button>
      </div>

    </div>
  </div>

  <!-- ============================================================= -->
  <!-- CUSTOM DELETE CONFIRMATION MODAL                              -->
  <!-- ============================================================= -->
  <div id="deleteConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeDeleteModal()"></div>

    <div id="deleteConfirmPanel"
         class="relative w-full max-w-md bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden">

      <div class="h-1.5 w-full bg-red-500"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <span class="w-16 h-16 rounded-full bg-red-50 border-2 border-ink flex items-center justify-center mb-4">
          <i data-lucide="trash-2" class="w-8 h-8 text-red-500"></i>
        </span>

        <h3 id="deleteModalTitle" class="font-display text-xl font-bold text-ink mb-2">Delete Student?</h3>

        <p id="deleteModalDescription" class="text-sm text-slate-500 leading-relaxed">
          You are about to permanently delete
          <span class="font-bold text-hot break-words">this student</span>.
        </p>

        <p class="text-xs text-red-500 font-semibold mt-3 flex items-center gap-1.5">
          <i data-lucide="alert-triangle" class="w-3.5 h-3.5"></i>
          This action cannot be undone.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeDeleteModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">
          Cancel
        </button>

        <button type="button" id="confirmDeleteBtn"
                class="btn-hard flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-red-500 px-5 py-3 text-sm font-bold text-white hover:bg-red-600">
          <i data-lucide="trash-2" class="w-4 h-4"></i>
          <span>Delete</span>
        </button>
      </div>

    </div>
  </div>

  <!-- ============================================================= -->
  <!-- CUSTOM RESET PASSWORD CONFIRMATION MODAL                      -->
  <!-- ============================================================= -->
  <div id="resetConfirmModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeResetModal()"></div>

    <div id="resetConfirmPanel"
         class="relative w-full max-w-md bg-white rounded-2xl border-2 border-ink shadow-[8px_8px_0_#FFC93C] animate-modal-in overflow-hidden">

      <div class="h-1.5 w-full bg-hot"></div>

      <div class="px-6 pt-6 pb-2 flex flex-col items-center text-center">
        <span class="w-16 h-16 rounded-full bg-sun border-2 border-ink flex items-center justify-center mb-4">
          <i data-lucide="lock" class="w-8 h-8 text-ink"></i>
        </span>

        <h3 class="font-display text-xl font-bold text-ink mb-2">Reset Password?</h3>

        <p class="text-sm text-slate-500 leading-relaxed">
          A new random password will be generated for
          <span id="resetStudentNameDisplay" class="font-bold text-hot break-words">this student</span>.
        </p>

        <p class="text-xs text-hot font-semibold mt-3 flex items-center gap-1.5">
          <i data-lucide="info" class="w-3.5 h-3.5"></i>
          The new password will be shown after reset.
        </p>
      </div>

      <div class="px-6 py-5 mt-2 flex flex-col-reverse sm:flex-row gap-3">
        <button type="button" onclick="closeResetModal()"
                class="flex-1 px-5 py-3 rounded-xl font-semibold text-ink bg-white border-2 border-ink hover:bg-blush transition-colors">
          Cancel
        </button>

        <button type="button" id="confirmResetBtn"
                class="btn-hard flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-hot px-5 py-3 text-sm font-bold text-white hover:bg-hotdark">
          <i data-lucide="refresh-cw" class="w-4 h-4"></i>
          <span>Reset Password</span>
        </button>
      </div>

    </div>
  </div>

  <!-- HIDDEN FORMS -->
  <form id="deleteForm" method="POST" action="students.php?class_id=<?= (int) $classId ?>" class="hidden">
    <input type="hidden" name="action" value="delete_student" />
    <input type="hidden" name="student_id" id="deleteStudentId" value="" />
  </form>

  <form id="resetForm" method="POST" action="students.php?class_id=<?= (int) $classId ?>" class="hidden">
    <input type="hidden" name="action" value="reset_password" />
    <input type="hidden" name="student_id" id="resetStudentId" value="" />
  </form>

  <script>
    if (typeof lucide !== 'undefined') {
      lucide.createIcons();
    }

    // ---- Auto-dismiss flash messages after 3 seconds ----
    (function () {
      const flashBoxes = [
        document.getElementById('flashSuccessBox'),
        document.getElementById('flashErrorBox'),
      ];

      flashBoxes.forEach(function (box) {
        if (!box) return;

        setTimeout(function () {
          box.classList.remove('animate-flash-in');
          box.classList.add('animate-flash-out');

          setTimeout(function () {
            if (box && box.parentNode) {
              box.parentNode.removeChild(box);
            }
          }, 500);
        }, 3000);
      });
    })();

    // ---- Admin profile dropdown ----
    (function () {
      const btn       = document.getElementById('admin-dropdown-btn');
      const menu      = document.getElementById('admin-dropdown-menu');
      const chevron   = document.getElementById('admin-chevron');
      const container = document.getElementById('admin-dropdown-container');

      if (!btn || !menu || !container) return;

      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        const isOpen = !menu.classList.contains('hidden');
        if (isOpen) {
          menu.classList.add('hidden');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded', 'false');
        } else {
          menu.classList.remove('hidden');
          if (chevron) chevron.classList.add('rotate-180');
          btn.setAttribute('aria-expanded', 'true');
        }
      });

      document.addEventListener('click', function (e) {
        if (!container.contains(e.target)) {
          menu.classList.add('hidden');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded', 'false');
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          menu.classList.add('hidden');
          if (chevron) chevron.classList.remove('rotate-180');
          btn.setAttribute('aria-expanded', 'false');
        }
      });
    })();

    // ---- Logout confirmation ----
    (function () {
      const logoutButtons = [
        document.getElementById('sidebarLogoutBtn'),
        document.getElementById('dropdownLogoutBtn'),
      ];
      logoutButtons.forEach(function (btn) {
        if (!btn) return;
        btn.addEventListener('click', function (e) {
          const confirmed = window.confirm('Are you sure you want to log out?');
          if (!confirmed) {
            e.preventDefault();
            e.stopPropagation();
            return false;
          }
          btn.classList.add('opacity-50', 'pointer-events-none');
        });
      });
    })();

    // ---- Import Modal ----
    const importModal = document.getElementById('importModal');
    const importForm  = document.getElementById('importForm');

    function openImportModal() {
      importModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');

      setTimeout(function () {
        const fileInput = document.getElementById('import_file');
        if (fileInput) fileInput.focus();
      }, 80);

      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeImportModal() {
      importModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
      if (importForm) importForm.reset();
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && importModal && !importModal.classList.contains('hidden')) {
        closeImportModal();
      }
    });

    // ---- Student Add/Edit Modal ----
    const studentModal       = document.getElementById('studentModal');
    const studentModalTitle  = document.getElementById('studentModalTitle');
    const studentForm        = document.getElementById('studentForm');
    const formAction         = document.getElementById('formAction');
    const formStudentId      = document.getElementById('formStudentId');
    const admissionInput     = document.getElementById('admission_number');
    const nameInput          = document.getElementById('name');
    const emailInput         = document.getElementById('email');
    const mobileInput        = document.getElementById('contact_number');
    const addressInput       = document.getElementById('address');
    const usernameInput      = document.getElementById('username');
    const passwordInput      = document.getElementById('password');
    const passwordWrapper    = document.getElementById('passwordFieldWrapper');
    const togglePasswordBtn  = document.getElementById('togglePasswordBtn');
    const togglePasswordIcon = document.getElementById('togglePasswordIcon');

    if (mobileInput) {
      mobileInput.addEventListener('input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 10);
      });

      mobileInput.addEventListener('keypress', function (e) {
        const charCode = e.which ? e.which : e.keyCode;
        if (charCode < 48 || charCode > 57) {
          if (![8, 9, 13, 27, 37, 38, 39, 40, 46].includes(charCode)) {
            e.preventDefault();
          }
        }
      });
    }

    function resetPasswordToggleState() {
      if (!passwordInput || !togglePasswordBtn || !togglePasswordIcon) return;

      passwordInput.type = 'password';
      togglePasswordBtn.setAttribute('title', 'Show password');
      togglePasswordBtn.setAttribute('aria-label', 'Show password');

      togglePasswordIcon.setAttribute('data-lucide', 'eye');

      if (typeof lucide !== 'undefined') {
        lucide.createIcons();
      }
    }

    if (togglePasswordBtn && passwordInput) {
      togglePasswordBtn.addEventListener('click', function () {
        const currentlyHidden = (passwordInput.type === 'password');

        if (currentlyHidden) {
          passwordInput.type = 'text';
          togglePasswordBtn.setAttribute('title', 'Hide password');
          togglePasswordBtn.setAttribute('aria-label', 'Hide password');
          if (togglePasswordIcon) togglePasswordIcon.setAttribute('data-lucide', 'eye-off');
        } else {
          passwordInput.type = 'password';
          togglePasswordBtn.setAttribute('title', 'Show password');
          togglePasswordBtn.setAttribute('aria-label', 'Show password');
          if (togglePasswordIcon) togglePasswordIcon.setAttribute('data-lucide', 'eye');
        }

        if (typeof lucide !== 'undefined') {
          lucide.createIcons();
        }

        passwordInput.focus();
      });
    }

    function openStudentModal(mode, studentId, admission, name, email, mobile, address, username) {
      studentModal.classList.remove('hidden');

      if (mode === 'edit') {
        studentModalTitle.textContent = 'Edit Student';
        formAction.value     = 'edit_student';
        formStudentId.value  = studentId || '';
        admissionInput.value = admission || '';
        nameInput.value      = name      || '';
        emailInput.value     = email     || '';
        mobileInput.value    = mobile    || '';
        addressInput.value   = address   || '';
        usernameInput.value  = username  || '';

        if (passwordWrapper) {
          passwordWrapper.style.display = 'none';
        }
        if (passwordInput) {
          passwordInput.removeAttribute('required');
          passwordInput.value = '';
        }
      } else {
        studentModalTitle.textContent = 'Add Student';
        formAction.value    = 'add_student';
        formStudentId.value = '';
        studentForm.reset();

        if (passwordWrapper) {
          passwordWrapper.style.display = '';
        }
        if (passwordInput) {
          passwordInput.setAttribute('required', 'required');
        }
      }

      resetPasswordToggleState();

      setTimeout(() => admissionInput && admissionInput.focus(), 50);
      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeStudentModal() {
      studentModal.classList.add('hidden');
      studentForm.reset();
      formAction.value    = 'add_student';
      formStudentId.value = '';
      resetPasswordToggleState();
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && studentModal && !studentModal.classList.contains('hidden')) {
        closeStudentModal();
      }
    });

    // ---- View Modal ----
    const viewModal      = document.getElementById('viewModal');
    const viewAdmission  = document.getElementById('viewAdmission');
    const viewName       = document.getElementById('viewName');
    const viewUsername   = document.getElementById('viewUsername');
    const viewEmail      = document.getElementById('viewEmail');
    const viewMobile     = document.getElementById('viewMobile');
    const viewAddress    = document.getElementById('viewAddress');

    function openViewModal(admission, name, email, mobile, address, username) {
      viewAdmission.textContent = admission || '—';
      viewName.textContent      = name      || '—';
      viewUsername.textContent  = '@' + (username || '—');
      viewEmail.textContent     = email     || '—';
      viewMobile.textContent    = mobile    || '—';
      viewAddress.textContent   = address   || '—';

      viewModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');

      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeViewModal() {
      viewModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && viewModal && !viewModal.classList.contains('hidden')) {
        closeViewModal();
      }
    });

    // ---- Delete Confirmation Modal ----
    const deleteConfirmModal     = document.getElementById('deleteConfirmModal');
    const deleteConfirmPanel     = document.getElementById('deleteConfirmPanel');
    const deleteModalTitle       = document.getElementById('deleteModalTitle');
    const deleteModalDescription = document.getElementById('deleteModalDescription');
    const confirmDeleteBtn       = document.getElementById('confirmDeleteBtn');

    let pendingDeleteId   = null;
    let bulkDeleteMode    = false;

    function confirmDeleteStudent(studentId, studentName) {
      bulkDeleteMode = false;
      pendingDeleteId = studentId;

      deleteModalTitle.textContent = 'Delete Student?';
      deleteModalDescription.innerHTML = 'You are about to permanently delete <span class="font-bold text-hot break-words">"' + studentName + '"</span>.';
      confirmDeleteBtn.querySelector('span').textContent = 'Delete';

      deleteConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');

      if (deleteConfirmPanel) {
        deleteConfirmPanel.classList.remove('animate-confirm-shake');
        void deleteConfirmPanel.offsetWidth;
        deleteConfirmPanel.classList.add('animate-confirm-shake');
      }

      setTimeout(function () {
        if (confirmDeleteBtn) confirmDeleteBtn.focus();
      }, 80);

      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeDeleteModal() {
      deleteConfirmModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
      pendingDeleteId = null;
      bulkDeleteMode  = false;
    }

    if (confirmDeleteBtn) {
      confirmDeleteBtn.addEventListener('click', function () {
        if (bulkDeleteMode) {
          const bulkForm = document.getElementById('bulkForm');
          if (bulkForm) bulkForm.submit();
          return;
        }

        if (pendingDeleteId === null || pendingDeleteId === undefined) {
          closeDeleteModal();
          return;
        }

        const delIdInput = document.getElementById('deleteStudentId');
        const delForm    = document.getElementById('deleteForm');

        if (delIdInput && delForm) {
          delIdInput.value = String(pendingDeleteId);
          delForm.submit();
        } else {
          closeDeleteModal();
        }
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && deleteConfirmModal && !deleteConfirmModal.classList.contains('hidden')) {
        closeDeleteModal();
      }
    });

    // ---- Bulk Delete ----
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    if (bulkDeleteBtn) {
      bulkDeleteBtn.addEventListener('click', function () {
        const checked = document.querySelectorAll('.student-checkbox:checked');
        if (checked.length === 0) {
          alert('Please select at least one student to delete.');
          return;
        }

        bulkDeleteMode = true;
        pendingDeleteId = null;

        deleteModalTitle.textContent = 'Delete Selected Students?';
        deleteModalDescription.innerHTML = 'You are about to permanently delete <span class="font-bold text-hot">' + checked.length + ' student(s)</span>.';
        confirmDeleteBtn.querySelector('span').textContent = 'Delete All';

        deleteConfirmModal.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');

        if (deleteConfirmPanel) {
          deleteConfirmPanel.classList.remove('animate-confirm-shake');
          void deleteConfirmPanel.offsetWidth;
          deleteConfirmPanel.classList.add('animate-confirm-shake');
        }

        setTimeout(function () {
          if (confirmDeleteBtn) confirmDeleteBtn.focus();
        }, 80);

        if (typeof lucide !== 'undefined') lucide.createIcons();
      });
    }

    // ---- Select All Checkbox ----
    (function () {
      const selectAll  = document.getElementById('selectAllCheckbox');
      const checkboxes = document.querySelectorAll('.student-checkbox');

      if (!selectAll) return;

      selectAll.addEventListener('change', function () {
        checkboxes.forEach(function (cb) {
          cb.checked = selectAll.checked;
        });
      });

      checkboxes.forEach(function (cb) {
        cb.addEventListener('change', function () {
          const allChecked = Array.from(checkboxes).every(c => c.checked);
          const anyChecked = Array.from(checkboxes).some(c => c.checked);
          selectAll.checked = allChecked;
          selectAll.indeterminate = anyChecked && !allChecked;
        });
      });
    })();

    // ---- Reset Password Modal ----
    const resetConfirmModal  = document.getElementById('resetConfirmModal');
    const resetConfirmPanel  = document.getElementById('resetConfirmPanel');
    const resetStudentNameEl = document.getElementById('resetStudentNameDisplay');
    const confirmResetBtn    = document.getElementById('confirmResetBtn');

    let pendingResetId = null;

    function confirmResetPassword(studentId, studentName) {
      pendingResetId = studentId;

      if (resetStudentNameEl) {
        resetStudentNameEl.textContent = '"' + studentName + '"';
      }

      resetConfirmModal.classList.remove('hidden');
      document.body.classList.add('overflow-hidden');

      if (resetConfirmPanel) {
        resetConfirmPanel.classList.remove('animate-confirm-shake');
        void resetConfirmPanel.offsetWidth;
        resetConfirmPanel.classList.add('animate-confirm-shake');
      }

      setTimeout(function () {
        if (confirmResetBtn) confirmResetBtn.focus();
      }, 80);

      if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeResetModal() {
      resetConfirmModal.classList.add('hidden');
      document.body.classList.remove('overflow-hidden');
      pendingResetId = null;
    }

    if (confirmResetBtn) {
      confirmResetBtn.addEventListener('click', function () {
        if (pendingResetId === null || pendingResetId === undefined) {
          closeResetModal();
          return;
        }

        const resetIdInput = document.getElementById('resetStudentId');
        const resetForm    = document.getElementById('resetForm');

        if (resetIdInput && resetForm) {
          resetIdInput.value = String(pendingResetId);
          resetForm.submit();
        } else {
          closeResetModal();
        }
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && resetConfirmModal && !resetConfirmModal.classList.contains('hidden')) {
        closeResetModal();
      }
    });

    // ---- Live Search ----
    (function () {
      const searchInput = document.getElementById('searchInput');
      const tableBody   = document.getElementById('studentsTableBody');

      if (!searchInput || !tableBody) return;

      searchInput.addEventListener('input', function () {
        const term = this.value.toLowerCase().trim();
        const rows = tableBody.querySelectorAll('tr');

        rows.forEach(function (row) {
          const text = row.textContent.toLowerCase();
          row.style.display = (term === '' || text.indexOf(term) !== -1) ? '' : 'none';
        });
      });
    })();
  </script>

  <script src="../assets/js/index.js"></script>
</body>
</html>