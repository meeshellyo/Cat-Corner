<?php
// create_user.php
declare(strict_types=1);
session_start();

//remove later
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);


require_once("database.php");
$conn = Database::dbConnect();              
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$errors = []; //to catch errors in creating an account

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username     = trim($_POST['username'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $password     = trim($_POST['password'] ?? '');
    $confirm_pw   = trim($_POST['confirm-password'] ?? '');
    $display_name = trim($_POST['display_name'] ?? '');

    // basic acc validation
    if ($username === '' || !preg_match('/^[A-Za-z0-9_.]{3,50}$/', $username)) {
        $errors[] = 'Username must be 3–50 chars (letters, numbers, underscore, dot).';
    }
    if ($email === '' || strpos($email, '@') === false) {
        $errors[] = 'email must have @';
    }
    if (strlen($password) < 12) {
        $errors[] = 'Password must be at least 12 characters.';
    }
    if ($password !== $confirm_pw){
        $errors[] = 'pass dont match';
    }
    if ($display_name !== '' && mb_strlen($display_name) > 100) {
        $errors[] = 'Display name is too long.';
    }

    if (!$errors) {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
                $sql = "INSERT INTO users (username, email, hashed_pass, display_name)
                        VALUES (:u, :e, :h, :d)"; //prepare sql insert with plaeholders.  this protects against injections
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':u' => $username,
                    ':e' => $email,
                    ':h' => $hash,
                    ':d' => ($display_name !== '' ? $display_name : null),
                ]);
                session_regenerate_id(true); //if the inserts succeed it gets called before logging the user in
                $_SESSION['user'] = [ // store a single user object
                    'user_id'      => (int)$conn->lastInsertId(), 
                    'username'     => $username,
                    'email'        => $email,
                    'role'         => 'registered',
                    'display_name' => $display_name,
                ];
                header('Location: /index.php');
                exit;
            
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $msg = $e->getMessage();
                if (stripos($msg, 'username') !== false) {
                    $errors[] = 'That username is taken.';
                } elseif (stripos($msg, 'email') !== false) {
                    $errors[] = 'That email is already registered.';
                } else {
                    $errors[] = 'Account could not be created.';
                }
            } else {
         
                $driverCode = $e->errorInfo[1] ?? 'n/a';
                $errors[] = 'Unexpected error creating account. ';
            }
        }
    }
}
?>

<!-- just test basic set up -->
<!doctype html>
<html lang="en"><meta charset="utf-8"><title>Create User</title>
<body>
<h1>Create User</h1>
<?php if ($errors): ?>
  <ul style="color:red"><?php foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul>
<?php endif; ?>
<form method="post" action="create_user.php" autocomplete="off">
  <label>Username <input name="username" required></label><br>
  <label>Email <input type="email" name="email" required></label><br>
  <label>Display name <input name="display_name"></label><br>
  <label>Password <input type="password" name="password" required></label><br>
  <label>confirm pass <input type="password" name="confirm-password" required></label><br>
  <button type="submit">Create account</button>
</form>
<p><a href="login.php">Already have an account? Log in</a></p>
</body></html>
