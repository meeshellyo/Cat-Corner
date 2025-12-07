<?php
// login.php
declare(strict_types=1);
session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once "database.php";

$conn = Database::dbConnect();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$errors = []; // error collector

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // pulling inputs
    $identifier = trim($_POST['identifier'] ?? ''); // can be username OR email
    $password   = $_POST['password'] ?? '';

    // checking inpits
    if ($identifier === '' || $password === '') {
        $errors[] = 'Please enter your username/email and password.';
    } else {
        try {
            // look up user from either username or email
            $sql = "SELECT user_id, username, email, hashed_pass, display_name, role, status
                      FROM users
                     WHERE email = :id OR username = :id
                     LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => $identifier]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $failMsg = 'Invalid credentials.';

            // validating user
            if (!$row) {
                $errors[] = $failMsg; // no such user
            } elseif ($row['status'] !== 'active') {
                $errors[] = 'Your account is not active.';
            } elseif (!password_verify($password, $row['hashed_pass'])) {
                $errors[] = $failMsg; // wrong password
            } else {
                session_regenerate_id(true); // prevent session fixation

                // store user info in the session for authentification checking
                $_SESSION['user'] = [
                    'user_id'      => (int)$row['user_id'],
                    'username'     => $row['username'],
                    'email'        => $row['email'],
                    'role'         => $row['role'],
                    'display_name' => $row['display_name'],
                ];

                // redirect to homepage 
                header('Location: ./index.php');
                exit;
            }
        } catch (PDOException $e) {
            // if db error occurs
            $errors[] = 'Unexpected error during login.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Login</title>
  <link rel="stylesheet" href="css/style.css" type="text/css">
  <meta name="viewport" content="width=device-width, initial-scale=1" />
</head>
<body>
    <main class="container has-logo">
        <div class="logo">
            <img src="doodles/login_logo.jpg" alt="Logo Loading...">
        </div> 

        <h1>Login</h1>

        <!-- show error if any exists -->
        <?php if ($errors): ?>
          <ul class="error-list">
            <?php foreach ($errors as $e): ?>
              <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <!-- login form -->
        <form method="post" action="login.php" autocomplete="off" class="form" novalidate>
          <!-- username or email -->
          <div class="form-group">
            <label for="identifier">Username or Email</label>
            <input id="identifier" name="identifier" required placeholder="you@example.com" autofocus>
          </div>

          <!-- password -->
          <div class="form-group">
            <label for="password">Password</label>
            <input id="password" type="password" name="password" required placeholder="••••••••">
          </div>

          <!-- submit button + link to create account -->
          <button class="btn" type="submit">Log in</button>
          <a class="link" href="create_user.php">Create an account</a>
        </form>
    </main>
</body>
</html>
