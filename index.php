<?php
session_start();

/* =========================================================
   PESAHub - ONE FILE SYSTEM
   Database: pesahub_db
   ========================================================= */

/* DATABASE CONNECTION */
$conn = new mysqli("localhost", "root", "");

if ($conn->connect_error) {
    die("MySQL connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

/* CREATE DATABASE */
$conn->query("CREATE DATABASE IF NOT EXISTS pesahub_db");

$conn->select_db("pesahub_db");


/* =========================================================
   CREATE TABLES
   ========================================================= */

$conn->query("
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fullname VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(30) NOT NULL,
    country VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    referral_code VARCHAR(50) UNIQUE NOT NULL,
    referred_by VARCHAR(50) DEFAULT NULL,
    balance DECIMAL(12,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
");

$conn->query("
CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    reward DECIMAL(12,2) NOT NULL,
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
");

$conn->query("
CREATE TABLE IF NOT EXISTS submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    task_id INT NOT NULL,
    proof TEXT NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
");

$conn->query("
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(20) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    description VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
");


/* =========================================================
   ADD SAMPLE TASKS IF NONE EXIST
   ========================================================= */

$checkTasks = $conn->query("SELECT COUNT(*) AS total FROM tasks");
$taskCount = $checkTasks->fetch_assoc()['total'];

if ($taskCount == 0) {

    $conn->query("
        INSERT INTO tasks
        (title, description, reward)
        VALUES
        (
            'Social Media Task',
            'Visit our social media page, follow the account and submit proof.',
            50
        ),
        (
            'Website Visit',
            'Visit the assigned website and submit proof of completion.',
            30
        ),
        (
            'Registration Task',
            'Complete the required registration and submit proof.',
            100
        )
    ");
}


/* =========================================================
   HELPER FUNCTION
   ========================================================= */

function redirect($page = "dashboard")
{
    header("Location: ?page=" . $page);
    exit();
}


/* =========================================================
   LOGOUT
   ========================================================= */

if (isset($_GET['logout'])) {

    session_destroy();

    header("Location: index.php");

    exit();
}


/* =========================================================
   REGISTER
   ========================================================= */

if (
    isset($_POST['action']) &&
    $_POST['action'] === 'register'
) {

    $fullname = trim($_POST['fullname']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $country = trim($_POST['country']);
    $password = $_POST['password'];
    $referral = trim($_POST['referral']);

    if (
        $fullname === '' ||
        $email === '' ||
        $phone === '' ||
        $country === '' ||
        $password === ''
    ) {

        $error = "Please fill in all fields.";

    } else {

        $check = $conn->prepare(
            "SELECT id FROM users WHERE email = ?"
        );

        $check->bind_param("s", $email);

        $check->execute();

        $result = $check->get_result();

        if ($result->num_rows > 0) {

            $error = "Email already registered.";

        } else {

            /* CREATE REFERRAL CODE */

            $referral_code =
                strtoupper(
                    substr(
                        preg_replace(
                            "/[^A-Za-z]/",
                            "",
                            $fullname
                        ),
                        0,
                        5
                    )
                )
                . rand(1000,9999);

            $hashedPassword =
                password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

            $stmt = $conn->prepare("
                INSERT INTO users
                (
                    fullname,
                    email,
                    phone,
                    country,
                    password,
                    referral_code,
                    referred_by
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->bind_param(
                "sssssss",
                $fullname,
                $email,
                $phone,
                $country,
                $hashedPassword,
                $referral_code,
                $referral
            );

            if ($stmt->execute()) {

                $newUserId =
                    $stmt->insert_id;

                /* REFERRAL BONUS */

                if ($referral !== '') {

                    $refQuery = $conn->prepare("
                        SELECT id
                        FROM users
                        WHERE referral_code = ?
                    ");

                    $refQuery->bind_param(
                        "s",
                        $referral
                    );

                    $refQuery->execute();

                    $refResult =
                        $refQuery->get_result();

                    $refUser =
                        $refResult->fetch_assoc();

                    if ($refUser) {

                        $referrerId =
                            $refUser['id'];

                        $bonus = 0;

                        $conn->query("
                            INSERT INTO transactions
                            (
                                user_id,
                                type,
                                amount,
                                description
                            )
                            VALUES
                            (
                                $referrerId,
                                'credit',
                                $bonus,
                                'Referral registration bonus'
                            )
                        ");
                    }
                }

                $success =
                    "Registration successful. You can now login.";

            } else {

                $error =
                    "Registration failed: " .
                    $stmt->error;
            }

            $stmt->close();
        }

        $check->close();
    }
}


/* =========================================================
   LOGIN
   ========================================================= */

if (
    isset($_POST['action']) &&
    $_POST['action'] === 'login'
) {

    $email =
        trim($_POST['email']);

    $password =
        $_POST['password'];

    $stmt = $conn->prepare("
        SELECT *
        FROM users
        WHERE email = ?
    ");

    $stmt->bind_param(
        "s",
        $email
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    $user =
        $result->fetch_assoc();

    if (
        $user &&
        password_verify(
            $password,
            $user['password']
        )
    ) {

        $_SESSION['user_id'] =
            $user['id'];

        redirect("dashboard");

    } else {

        $error =
            "Invalid email or password.";
    }

    $stmt->close();
}


/* =========================================================
   GET CURRENT USER
   ========================================================= */

$currentUser = null;

if (isset($_SESSION['user_id'])) {

    $userId =
        (int) $_SESSION['user_id'];

    $stmt = $conn->prepare("
        SELECT *
        FROM users
        WHERE id = ?
    ");

    $stmt->bind_param(
        "i",
        $userId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    $currentUser =
        $result->fetch_assoc();

    $stmt->close();
}


/* =========================================================
   TASK SUBMISSION
   ========================================================= */

if (
    isset($_POST['action']) &&
    $_POST['action'] === 'submit_task'
) {

    if (!$currentUser) {

        redirect("login");
    }

    $taskId =
        (int) $_POST['task_id'];

    $proof =
        trim($_POST['proof']);

    if ($proof === '') {

        $error =
            "Please provide proof.";

    } else {

        $check = $conn->prepare("
            SELECT id
            FROM submissions
            WHERE user_id = ?
            AND task_id = ?
        ");

        $check->bind_param(
            "ii",
            $userId,
            $taskId
        );

        $check->execute();

        $existing =
            $check->get_result();

        if ($existing->num_rows > 0) {

            $submission =
                $existing->fetch_assoc();

            $update = $conn->prepare("
                UPDATE submissions
                SET proof = ?,
                    status = 'pending'
                WHERE id = ?
            ");

            $update->bind_param(
                "si",
                $proof,
                $submission['id']
            );

            $update->execute();

            $update->close();

        } else {

            $insert = $conn->prepare("
                INSERT INTO submissions
                (
                    user_id,
                    task_id,
                    proof
                )
                VALUES (?, ?, ?)
            ");

            $insert->bind_param(
                "iis",
                $userId,
                $taskId,
                $proof
            );

            $insert->execute();

            $insert->close();
        }

        $check->close();

        $success =
            "Task submitted successfully.";
    }
}


/* =========================================================
   PROFILE UPDATE
   ========================================================= */

if (
    isset($_POST['action']) &&
    $_POST['action'] === 'update_profile'
) {

    if (!$currentUser) {

        redirect("login");
    }

    $fullname =
        trim($_POST['fullname']);

    $phone =
        trim($_POST['phone']);

    $stmt = $conn->prepare("
        UPDATE users
        SET fullname = ?,
            phone = ?
        WHERE id = ?
    ");

    $stmt->bind_param(
        "ssi",
        $fullname,
        $phone,
        $userId
    );

    if ($stmt->execute()) {

        $success =
            "Profile updated successfully.";

        $currentUser['fullname'] =
            $fullname;

        $currentUser['phone'] =
            $phone;

    } else {

        $error =
            "Could not update profile.";
    }

    $stmt->close();
}


/* =========================================================
   PAGE
   ========================================================= */

$page =
    $_GET['page'] ?? (
        $currentUser
        ? 'dashboard'
        : 'login'
    );

?>
<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>PesaHub</title>

<style>

/* =========================================================
   GENERAL
   ========================================================= */

* {
    box-sizing: border-box;
}

body {

    margin: 0;

    font-family: Arial, sans-serif;

    background: #f4f6f9;

    color: #222;
}

a {

    text-decoration: none;
}


/* =========================================================
   LOGIN / REGISTER
   ========================================================= */

.auth-page {

    min-height: 100vh;

    display: flex;

    justify-content: center;

    align-items: center;

    background:
        linear-gradient(
            rgba(0,0,0,0.5),
            rgba(0,0,0,0.5)
        ),
        linear-gradient(
            135deg,
            #159447,
            #0066cc
        );
}

.auth-box {

    width: 380px;

    max-width: 95%;

    background: white;

    padding: 30px;

    border-radius: 15px;

    box-shadow:
        0 15px 40px
        rgba(0,0,0,0.3);
}

.logo {

    text-align: center;

    font-size: 32px;

    font-weight: bold;

    color: #159447;

    margin-bottom: 10px;
}

.auth-box h2 {

    text-align: center;

    margin-bottom: 25px;
}

input,
select,
textarea {

    width: 100%;

    padding: 12px;

    margin: 7px 0 15px;

    border: 1px solid #ccc;

    border-radius: 7px;

    font-size: 15px;
}

textarea {

    min-height: 160px;

    resize: vertical;
}

button,
.btn {

    border: none;

    padding: 12px 18px;

    border-radius: 7px;

    cursor: pointer;

    font-weight: bold;

    display: inline-block;
}

.btn-green {

    background: #159447;

    color: white;
}

.btn-blue {

    background: #007bff;

    color: white;
}

.btn-red {

    background: #dc3545;

    color: white;
}

.full-btn {

    width: 100%;
}

.auth-link {

    text-align: center;

    margin-top: 15px;
}


/* =========================================================
   MESSAGES
   ========================================================= */

.success {

    background: #d4edda;

    color: #155724;

    padding: 12px;

    border-radius: 7px;

    margin-bottom: 15px;
}

.error {

    background: #f8d7da;

    color: #721c24;

    padding: 12px;

    border-radius: 7px;

    margin-bottom: 15px;
}


/* =========================================================
   DASHBOARD
   ========================================================= */

.dashboard {

    display: flex;

    min-height: 100vh;
}


/* =========================================================
   SIDEBAR
   ========================================================= */

.sidebar {

    width: 240px;

    background: #111827;

    color: white;

    min-height: 100vh;

    padding: 20px;

    position: fixed;

    left: 0;

    top: 0;

    bottom: 0;
}

.sidebar-logo {

    font-size: 25px;

    font-weight: bold;

    text-align: center;

    color: #00d084;

    margin-bottom: 25px;
}

.sidebar-profile {

    text-align: center;

    padding-bottom: 20px;

    border-bottom:
        1px solid #444;

    margin-bottom: 20px;
}

.avatar {

    width: 65px;

    height: 65px;

    background: #ddd;

    border-radius: 50%;

    margin: auto;

    display: flex;

    justify-content: center;

    align-items: center;

    font-size: 30px;

    color: #555;
}

.sidebar a {

    display: block;

    color: white;

    padding: 13px;

    margin: 7px 0;

    border-radius: 7px;

    background:
        rgba(255,255,255,0.05);
}

.sidebar a:hover {

    background: #00d084;

    color: black;
}


/* =========================================================
   MAIN CONTENT
   ========================================================= */

.main {

    margin-left: 240px;

    width: calc(100% - 240px);

    padding: 30px;
}

.header {

    margin-bottom: 25px;
}

.header h1 {

    margin-bottom: 5px;
}


/* =========================================================
   CARDS
   ========================================================= */

.cards {

    display: grid;

    grid-template-columns:
        repeat(
            auto-fit,
            minmax(200px, 1fr)
        );

    gap: 20px;

    margin-bottom: 25px;
}

.card {

    background: white;

    padding: 25px;

    border-radius: 12px;

    box-shadow:
        0 4px 15px
        rgba(0,0,0,0.08);
}

.card h3 {

    color: #555;
}

.big-number {

    font-size: 28px;

    font-weight: bold;

    color: #159447;
}


/* =========================================================
   TASKS
   ========================================================= */

.task-grid {

    display: grid;

    grid-template-columns:
        repeat(
            auto-fit,
            minmax(280px, 1fr)
        );

    gap: 20px;
}

.task-card {

    background: white;

    padding: 22px;

    border-radius: 12px;

    box-shadow:
        0 4px 15px
        rgba(0,0,0,0.08);
}

.reward {

    color: #159447;

    font-size: 21px;

    font-weight: bold;

    margin: 15px 0;
}

.status {

    display: inline-block;

    padding: 8px 12px;

    border-radius: 6px;

    font-weight: bold;
}

.pending {

    background: #fff3cd;

    color: #856404;
}

.approved {

    background: #d4edda;

    color: #155724;
}

.rejected {

    background: #f8d7da;

    color: #721c24;
}


/* =========================================================
   TABLE
   ========================================================= */

.table-box {

    background: white;

    padding: 20px;

    border-radius: 12px;

    overflow-x: auto;
}

table {

    width: 100%;

    border-collapse: collapse;
}

th,
td {

    padding: 12px;

    border-bottom:
        1px solid #ddd;

    text-align: left;
}

th {

    background: #f3f3f3;
}


/* =========================================================
   REFERRAL
   ========================================================= */

.referral-box {

    background: white;

    padding: 25px;

    border-radius: 12px;

    margin-bottom: 20px;

    box-shadow:
        0 4px 15px
        rgba(0,0,0,0.08);
}

.referral-code {

    font-size: 30px;

    font-weight: bold;

    color: #159447;

    margin: 15px 0;
}


/* =========================================================
   MOBILE
   ========================================================= */

@media(max-width:700px) {

    .sidebar {

        width: 190px;
    }

    .main {

        margin-left: 190px;

        width:
            calc(100% - 190px);

        padding: 15px;
    }

    .sidebar a {

        font-size: 14px;

        padding: 10px;
    }

}

</style>

</head>

<body>


<?php

/* =========================================================
   LOGIN PAGE
   ========================================================= */

if ($page === 'login' && !$currentUser):

?>

<div class="auth-page">

<div class="auth-box">

<div class="logo">
PesaHub
</div>

<h2>Login</h2>

<?php if (isset($error)): ?>

<div class="error">
<?= htmlspecialchars($error) ?>
</div>

<?php endif; ?>

<?php if (isset($success)): ?>

<div class="success">
<?= htmlspecialchars($success) ?>
</div>

<?php endif; ?>

<form method="POST">

<input
    type="hidden"
    name="action"
    value="login"
>

<input
    type="email"
    name="email"
    placeholder="Email Address"
    required
>

<input
    type="password"
    name="password"
    placeholder="Password"
    required
>

<button
    type="submit"
    class="btn btn-blue full-btn"
>
Login
</button>

</form>

<div class="auth-link">

Don't have an account?

<a href="?page=register">
Register
</a>

</div>

</div>

</div>


<?php

/* =========================================================
   REGISTER PAGE
   ========================================================= */

elseif (
    $page === 'register'
    && !$currentUser
):

?>

<div class="auth-page">

<div class="auth-box">

<div class="logo">
PesaHub
</div>

<h2>Create Account</h2>

<?php if (isset($error)): ?>

<div class="error">
<?= htmlspecialchars($error) ?>
</div>

<?php endif; ?>

<?php if (isset($success)): ?>

<div class="success">
<?= htmlspecialchars($success) ?>
</div>

<?php endif; ?>

<form method="POST">

<input
    type="hidden"
    name="action"
    value="register"
>

<input
    type="text"
    name="fullname"
    placeholder="Full Name"
    required
>

<input
    type="email"
    name="email"
    placeholder="Email Address"
    required
>

<input
    type="tel"
    name="phone"
    placeholder="Phone Number"
    required
>

<select
    name="country"
    required
>

<option value="">
Select Country
</option>

<option>Kenya</option>
<option>Uganda</option>
<option>Tanzania</option>
<option>Rwanda</option>
<option>Nigeria</option>
<option>South Africa</option>
<option>United States</option>
<option>United Kingdom</option>
<option>India</option>
<option>China</option>

</select>

<input
    type="password"
    name="password"
    placeholder="Password"
    minlength="6"
    required
>

<input
    type="text"
    name="referral"
    placeholder="Referral Code (optional)"
>

<button
    type="submit"
    class="btn btn-green full-btn"
>
Create Account
</button>

</form>

<div class="auth-link">

Already have an account?

<a href="?page=login">
Login
</a>

</div>

</div>

</div>


<?php

/* =========================================================
   LOGGED-IN SYSTEM
   ========================================================= */

elseif ($currentUser):

?>

<div class="dashboard">

<!-- SIDEBAR -->

<aside class="sidebar">

<div class="sidebar-logo">
PesaHub
</div>

<div class="sidebar-profile">

<div class="avatar">
👤
</div>

<h3>
<?= htmlspecialchars(
    $currentUser['fullname']
) ?>
</h3>

<small>
Online
</small>

</div>

<a href="?page=dashboard">
🏠 Dashboard
</a>

<a href="?page=tasks">
📌 Tasks
</a>

<a href="?page=referrals">
👥 Referrals
</a>

<a href="?page=wallet">
💰 Wallet
</a>

<a href="?page=profile">
👤 Profile
</a>

<a href="?logout=1">
🚪 Logout
</a>

</aside>


<!-- MAIN -->

<main class="main">


<?php

/* =========================================================
   DASHBOARD
   ========================================================= */

if ($page === 'dashboard'):

    $taskResult =
        $conn->query("
            SELECT COUNT(*) AS total
            FROM tasks
            WHERE status='active'
        ");

    $totalTasks =
        $taskResult->fetch_assoc()['total'];

    $refStmt =
        $conn->prepare("
            SELECT COUNT(*) AS total
            FROM users
            WHERE referred_by = ?
        ");

    $refStmt->bind_param(
        "s",
        $currentUser['referral_code']
    );

    $refStmt->execute();

    $totalReferrals =
        $refStmt
        ->get_result()
        ->fetch_assoc()['total'];

    $refStmt->close();

    $completedStmt =
        $conn->prepare("
            SELECT COUNT(*) AS total
            FROM submissions
            WHERE user_id = ?
            AND status = 'approved'
        ");

    $completedStmt->bind_param(
        "i",
        $userId
    );

    $completedStmt->execute();

    $completed =
        $completedStmt
        ->get_result()
        ->fetch_assoc()['total'];

    $completedStmt->close();

?>

<div class="header">

<h1>
Welcome,
<?= htmlspecialchars(
    $currentUser['fullname']
) ?>
👋
</h1>

<p>
Welcome to your PesaHub dashboard.
</p>

</div>

<div class="cards">

<div class="card">

<h3>
💰 Balance
</h3>

<div class="big-number">

KSh
<?= number_format(
    $currentUser['balance'],
    2
) ?>

</div>

</div>

<div class="card">

<h3>
📌 Available Tasks
</h3>

<div class="big-number">
<?= $totalTasks ?>
</div>

</div>

<div class="card">

<h3>
👥 Referrals
</h3>

<div class="big-number">
<?= $totalReferrals ?>
</div>

</div>

<div class="card">

<h3>
✅ Completed Tasks
</h3>

<div class="big-number">
<?= $completed ?>
</div>

</div>

</div>

<div class="card">

<h2>
Get Started
</h2>

<p>
Complete tasks, invite friends and manage your wallet.
</p>

<a
    href="?page=tasks"
    class="btn btn-green"
>
View Tasks
</a>

<a
    href="?page=referrals"
    class="btn btn-blue"
>
Invite Friends
</a>

</div>


<?php

/* =========================================================
   TASKS PAGE
   ========================================================= */

elseif ($page === 'tasks'):

?>

<div class="header">

<h1>
📌 Available Tasks
</h1>

<p>
Complete tasks and earn rewards.
</p>

</div>

<div class="task-grid">

<?php

$stmt =
    $conn->prepare("
        SELECT
            t.*,
            s.status AS submission_status
        FROM tasks t

        LEFT JOIN submissions s
        ON t.id = s.task_id
        AND s.user_id = ?

        WHERE t.status = 'active'

        ORDER BY t.created_at DESC
    ");

$stmt->bind_param(
    "i",
    $userId
);

$stmt->execute();

$tasks =
    $stmt->get_result();

?>

<?php while (
    $task = $tasks->fetch_assoc()
): ?>

<div class="task-card">

<h2>
<?= htmlspecialchars(
    $task['title']
) ?>
</h2>

<p>
<?= nl2br(
    htmlspecialchars(
        $task['description']
    )
) ?>
</p>

<div class="reward">

KSh
<?= number_format(
    $task['reward'],
    2
) ?>

</div>


<?php if (
    !$task['submission_status']
): ?>

<a
    href="?page=submit&task=<?= $task['id'] ?>"
    class="btn btn-green"
>
Complete Task
</a>


<?php elseif (
    $task['submission_status']
    === 'pending'
): ?>

<span class="status pending">
⏳ Pending Review
</span>


<?php elseif (
    $task['submission_status']
    === 'approved'
): ?>

<span class="status approved">
✅ Completed
</span>


<?php else: ?>

<span class="status rejected">
❌ Rejected
</span>

<br><br>

<a
    href="?page=submit&task=<?= $task['id'] ?>"
    class="btn btn-green"
>
Resubmit
</a>

<?php endif; ?>

</div>

<?php endwhile; ?>

</div>


<?php

/* =========================================================
   SUBMIT TASK
   ========================================================= */

elseif ($page === 'submit'):

$taskId =
    (int) ($_GET['task'] ?? 0);

$stmt =
    $conn->prepare("
        SELECT *
        FROM tasks
        WHERE id = ?
    ");

$stmt->bind_param(
    "i",
    $taskId
);

$stmt->execute();

$task =
    $stmt
    ->get_result()
    ->fetch_assoc();

?>

<div class="card">

<h1>
Submit Task
</h1>

<?php if (!$task): ?>

<div class="error">
Task not found.
</div>

<?php else: ?>

<h2>
<?= htmlspecialchars(
    $task['title']
) ?>
</h2>

<p>
<?= nl2br(
    htmlspecialchars(
        $task['description']
    )
) ?>
</p>

<p>

<strong>
Reward:
</strong>

KSh
<?= number_format(
    $task['reward'],
    2
) ?>

</p>

<?php if (isset($error)): ?>

<div class="error">
<?= htmlspecialchars($error) ?>
</div>

<?php endif; ?>

<?php if (isset($success)): ?>

<div class="success">
<?= htmlspecialchars($success) ?>
</div>

<?php endif; ?>

<form method="POST">

<input
    type="hidden"
    name="action"
    value="submit_task"
>

<input
    type="hidden"
    name="task_id"
    value="<?= $taskId ?>"
>

<label>
<strong>
Proof of Completion
</strong>
</label>

<textarea
    name="proof"
    placeholder="Enter your proof or task link here..."
    required
></textarea>

<button
    type="submit"
    class="btn btn-green"
>
Submit Task
</button>

<a
    href="?page=tasks"
    class="btn btn-blue"
>
Back
</a>

</form>

<?php endif; ?>

</div>


<?php

/* =========================================================
   REFERRALS
   ========================================================= */

elseif ($page === 'referrals'):

$referralLink =
    "http://" .
    $_SERVER['HTTP_HOST'] .
    dirname($_SERVER['PHP_SELF']) .
    "/?page=register&ref=" .
    urlencode(
        $currentUser['referral_code']
    );

$refStmt =
    $conn->prepare("
        SELECT COUNT(*) AS total
        FROM users
        WHERE referred_by = ?
    ");

$refStmt->bind_param(
    "s",
    $currentUser['referral_code']
);

$refStmt->execute();

$totalReferrals =
    $refStmt
    ->get_result()
    ->fetch_assoc()['total'];

$refStmt->close();

?>

<div class="header">

<h1>
👥 Referrals
</h1>

<p>
Invite your friends to PesaHub.
</p>

</div>

<div class="referral-box">

<h2>
Your Referral Code
</h2>

<div class="referral-code">

<?= htmlspecialchars(
    $currentUser['referral_code']
) ?>

</div>

<p>
Share this link:
</p>

<input
    id="referralLink"
    value="<?= htmlspecialchars(
        $referralLink
    ) ?>"
    readonly
>

<button
    class="btn btn-green"
    onclick="copyReferral()"
>
📋 Copy Link
</button>

</div>

<div class="cards">

<div class="card">

<h3>
Total Referrals
</h3>

<div class="big-number">
<?= $totalReferrals ?>
</div>

</div>

</div>


<div class="table-box">

<h2>
People You Referred
</h2>

<table>

<tr>

<th>
Name
</th>

<th>
Email
</th>

<th>
Date
</th>

</tr>

<?php

$stmt =
    $conn->prepare("
        SELECT fullname,
               email,
               created_at
        FROM users
        WHERE referred_by = ?
        ORDER BY created_at DESC
    ");

$stmt->bind_param(
    "s",
    $currentUser['referral_code']
);

$stmt->execute();

$people =
    $stmt->get_result();

while (
    $person =
    $people->fetch_assoc()
):

?>

<tr>

<td>
<?= htmlspecialchars(
    $person['fullname']
) ?>
</td>

<td>
<?= htmlspecialchars(
    $person['email']
) ?>
</td>

<td>
<?= htmlspecialchars(
    $person['created_at']
) ?>
</td>

</tr>

<?php endwhile; ?>

</table>

</div>


<?php

/* =========================================================
   WALLET
   ========================================================= */

elseif ($page === 'wallet'):

?>

<div class="header">

<h1>
💰 My Wallet
</h1>

</div>

<div class="card">

<h3>
Available Balance
</h3>

<div class="big-number">

KSh
<?= number_format(
    $currentUser['balance'],
    2
) ?>

</div>

</div>

<br>

<div class="table-box">

<h2>
Transaction History
</h2>

<table>

<tr>

<th>
Type
</th>

<th>
Amount
</th>

<th>
Description
</th>

<th>
Date
</th>

</tr>

<?php

$stmt =
    $conn->prepare("
        SELECT *
        FROM transactions
        WHERE user_id = ?
        ORDER BY created_at DESC
    ");

$stmt->bind_param(
    "i",
    $userId
);

$stmt->execute();

$transactions =
    $stmt->get_result();

while (
    $transaction =
    $transactions->fetch_assoc()
):

?>

<tr>

<td>
<?= htmlspecialchars(
    $transaction['type']
) ?>
</td>

<td>

KSh
<?= number_format(
    $transaction['amount'],
    2
) ?>

</td>

<td>
<?= htmlspecialchars(
    $transaction['description']
) ?>
</td>

<td>
<?= htmlspecialchars(
    $transaction['created_at']
) ?>
</td>

</tr>

<?php endwhile; ?>

</table>

</div>


<?php

/* =========================================================
   PROFILE
   ========================================================= */

elseif ($page === 'profile'):

?>

<div class="header">

<h1>
👤 My Profile
</h1>

</div>

<div class="card">

<?php if (isset($error)): ?>

<div class="error">
<?= htmlspecialchars($error) ?>
</div>

<?php endif; ?>

<?php if (isset($success)): ?>

<div class="success">
<?= htmlspecialchars($success) ?>
</div>

<?php endif; ?>

<form method="POST">

<input
    type="hidden"
    name="action"
    value="update_profile"
>

<label>
Full Name
</label>

<input
    type="text"
    name="fullname"
    value="<?= htmlspecialchars(
        $currentUser['fullname']
    ) ?>"
    required
>

<label>
Email
</label>

<input
    type="email"
    value="<?= htmlspecialchars(
        $currentUser['email']
    ) ?>"
    readonly
>

<label>
Phone
</label>

<input
    type="text"
    name="phone"
    value="<?= htmlspecialchars(
        $currentUser['phone']
    ) ?>"
    required
>

<label>
Country
</label>

<input
    type="text"
    value="<?= htmlspecialchars(
        $currentUser['country']
    ) ?>"
    readonly
>

<label>
Referral Code
</label>

<input
    type="text"
    value="<?= htmlspecialchars(
        $currentUser['referral_code']
    ) ?>"
    readonly
>

<button
    type="submit"
    class="btn btn-green"
>
Save Changes
</button>

</form>

</div>

<?php endif; ?>


</main>

</div>


<?php

else:

?>

<div class="auth-page">

<div class="auth-box">

<div class="logo">
PesaHub
</div>

<h2>
Welcome
</h2>

<a
    href="?page=login"
    class="btn btn-blue full-btn"
>
Login
</a>

<br><br>

<a
    href="?page=register"
    class="btn btn-green full-btn"
>
Create Account
</a>

</div>

</div>

<?php endif; ?>


<script>

function copyReferral() {

    const input =
        document.getElementById(
            "referralLink"
        );

    input.select();

    input.setSelectionRange(
        0,
        99999
    );

    navigator.clipboard
        .writeText(input.value)
        .then(function() {

            alert(
                "Referral link copied!"
            );

        })
        .catch(function() {

            document.execCommand(
                "copy"
            );

            alert(
                "Referral link copied!"
            );

        });

}

</script>

</body>
</html>