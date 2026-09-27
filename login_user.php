<?php
// login_user.php — reader login
$pageTitle = 'User Login';
require_once 'db.php';

if (isUserLoggedIn()) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $pdo->prepare("SELECT ID, Name, Email, PasswordHash FROM USERS WHERE Email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['PasswordHash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']    = (int)$user['ID'];
            $_SESSION['user_name']  = $user['Name'];
            $_SESSION['user_email'] = $user['Email'];
            $_SESSION['user_type']  = 'user';
            redirect('index.php');
        }
        $error = 'Invalid email or password.';
    }
}

require_once 'header.php';
?>

<div class="auth-container">
    <div class="auth-tabs">
        <a href="login_user.php" class="auth-tab active">User Login</a>
        <a href="login_artist.php" class="auth-tab">Artist Login</a>
    </div>

    <div class="auth-header">
        <h1>Welcome Back</h1>
        <p>Sign in to continue reading your favorite manga</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error alert-sticky"><?php echo h($error); ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['registered'])): ?>
        <div class="alert alert-success alert-sticky">Account created! You can sign in now.</div>
    <?php endif; ?>

    <form method="POST" action="login_user.php">
        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" class="form-control"
                   placeholder="Enter your email" value="<?php echo h($_POST['email'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" class="form-control"
                   placeholder="Enter your password" required>
        </div>
        <button type="submit" class="btn btn-primary btn-full">Sign In</button>
    </form>

    <div class="auth-footer">
        <p>Don't have an account? <a href="register_user.php">Sign up</a></p>
        <p class="demo-hint">Demo reader: <code>user@example.com</code> / <code>password</code></p>
    </div>
</div>

<?php require_once 'footer.php'; ?>
