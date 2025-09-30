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

$errors = []; // to catch errors in creating an account

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username     = trim($_POST['username'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $password     = trim($_POST['password'] ?? '');
    $confirm_pw   = trim($_POST['confirm-password'] ?? '');
    $display_name = trim($_POST['display_name'] ?? '');

    // basic acc validation
    // user must be 3-50 characters; a-z,#,_,. only
    if ($username === '' || !preg_match('/^[A-Za-z0-9_.]{3,50}$/', $username)) {
        $errors[] = 'Username must be 3–50 chars (letters, numbers, underscore, dot).';
    }
    if ($email === '' || strpos($email, '@') === false) {
        $errors[] = 'email must have @';
    }
    // atleast 12 char
    if ($password !== '' && strlen($password) < 12) {
        $errors[] = 'Password must be at least 12 characters.';
    }
    // pass must match
    if ($password !== $confirm_pw) {
        $errors[] = 'Passwords don\'t match';
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
            // binding and executing
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
            header('Location: ./index.php');
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') { // 2300 is a class for integrity constraint violations
                $msg = $e->getMessage();
                if (stripos($msg, 'username') !== false) {
                    $errors[] = 'That username is taken.';
                } elseif (stripos($msg, 'email') !== false) {
                    $errors[] = 'That email is already registered.';
                } else {
                    $errors[] = 'Account could not be created.';
                }
            } else {
                // db error
                $driverCode = $e->errorInfo[1] ?? 'n/a';
                $errors[] = 'Unexpected error creating account. ';
            }
        }
    }
}
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Create User</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <link rel="stylesheet" href="css/style.css" type="text/css">

  <script>
    function passConfirm() {
      // live pass confirmation
      const pw  = document.getElementById("password");
      const cpw = document.getElementById("confirm-password");
      const msg = document.getElementById("message");

      // clear message if either is empty
      if (!pw.value || !cpw.value) {
        msg.textContent = "";
        cpw.setCustomValidity(""); // validity reset
        return;
      }

      if (pw.value === cpw.value) {
        msg.style.color = "green";
        msg.textContent = "Passwords match!";
        cpw.setCustomValidity(""); // valid
      } else {
        msg.style.color = "red";
        msg.textContent = "Passwords do NOT match!";
        cpw.setCustomValidity("Passwords do not match"); // blocks form submit
      }
    }

    // runs passconfirm
    document.addEventListener("DOMContentLoaded", () => {
      document.getElementById("password").addEventListener("input", passConfirm);
      document.getElementById("confirm-password").addEventListener("input", passConfirm);
    });
  </script>
</head>
<body>
  <main class="container has-logo">
    <div class="logo">
      <img src="doodles/create_user_logo.jpg" alt="Logo Loading...">
    </div>

    <h1>Create User</h1>

    <?php if ($errors): ?>
      <ul class="error-list">
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <form method="post" action="create_user.php" autocomplete="off" class="form">
      <div class="form-group">
        <label for="username">Username</label>
        <input id="username" name="username" required placeholder="Your Username">
      </div>

      <div class="form-group">
        <label for="email">Email</label>
        <input id="email" type="email" name="email" required placeholder="your@email.com">
      </div>

      <div class="form-group">
        <label for="display_name">Display name</label>
        <input id="display_name" name="display_name" placeholder="Optional">
      </div>

      <div class="form-group">
        <label for="password">Password (Must be atleast 12 characters)</label>
        <input id="password" type="password" name="password" onkeyup="passConfirm();" minlength="12" required placeholder="••••••••">
      </div>

      <div class="form-group">
        <label for="confirm-password">Confirm Password</label>
        <input id="confirm-password" type="password" name="confirm-password" onkeyup="passConfirm();" required placeholder="••••••••">
        <p id="message" aria-live="polite"></p>
      </div>

      <button type="submit" class="btn">Create account</button>
    </form>

    <p><a href="./login.php" class="link">Already have an account? Log in</a></p>
  </main>
</body>
</html>



