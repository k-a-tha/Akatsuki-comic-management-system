<?php
// login_artist.php — artist login
$pageTitle = 'Artist Login';
require_once 'db.php';

if (isArtistLoggedIn()) redirect('admin_panel.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $pdo->prepare("SELECT ID, Name, Email, PasswordHash FROM ARTIST WHERE Email = ?");
        $stmt->execute([$email]);
        $artist = $stmt->fetch();

        if ($artist && password_verify($password, $artist['PasswordHash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']    = (int)$artist['ID'];
            $_SESSION['user_name']  = $artist['Name'];
            $_SESSION['user_email'] = $artist['Email'];
            $_SESSION['user_type']  = 'artist';
            redirect('index.php');
        }
        $error = 'Invalid email or password.';
    }
}

require_once 'header.php';
?>

<div class="auth-container">
    <div class="auth-tabs">
        <a href="login_user.php" class="auth-tab">User Login</a>
        <a href="login_artist.php" class="auth-tab active">Artist Login</a>
    </div>

    <div class="auth-header">
        <h1>Artist Portal</h1>
        <p>Sign in to manage your comics and chapters</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error alert-sticky"><?php echo h($error); ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['registered'])): ?>
        <div class="alert alert-success alert-sticky">Artist account created! You can sign in now.</div>
    <?php endif; ?>

    <form method="POST" action="login_artist.php">
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
        <button type="submit" class="btn btn-secondary btn-full">Sign In as Artist</button>
    </form>

    <div class="auth-footer">
        <p>Want to publish your work? <a href="register_artist.php">Register as Artist</a></p>
        <p class="demo-hint">Demo artist: <code>artist@example.com</code> / <code>password</code></p>
    </div>
</div>

<?php require_once 'footer.php'; ?>
