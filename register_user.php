<?php
// register_user.php — reader sign-up
$pageTitle = 'User Registration';
require_once 'db.php';

if (isUserLoggedIn()) redirect('index.php');

// Genre choices = common genres + any genre used by a comic
$genreOptions = array_unique(array_merge(
    ['Action', 'Adventure', 'Comedy', 'Drama', 'Fantasy', 'Horror', 'Romance', 'Slice of Life'],
    $pdo->query("SELECT DISTINCT Genre FROM GENRE")->fetchAll(PDO::FETCH_COLUMN)
));
sort($genreOptions);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name            = trim($_POST['name'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $gender          = in_array($_POST['gender'] ?? '', ['Male', 'Female', 'Other'], true) ? $_POST['gender'] : null;
    $genrePref       = in_array($_POST['genre_pref'] ?? '', $genreOptions, true) ? $_POST['genre_pref'] : null;

    if ($name === '' || $email === '' || $password === '') {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (mb_strlen($name) > 100) {
        $error = 'Name is too long (max 100 characters).';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare("SELECT ID FROM USERS WHERE Email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'An account with this email already exists.';
        } else {
            $pdo->prepare("INSERT INTO USERS (Email, Name, Gender, PasswordHash, GenrePref) VALUES (?, ?, ?, ?, ?)")
                ->execute([$email, $name, $gender, password_hash($password, PASSWORD_DEFAULT), $genrePref]);
            redirect('login_user.php?registered=1');
        }
    }
}

require_once 'header.php';
?>

<div class="auth-container">
    <div class="auth-tabs">
        <a href="register_user.php" class="auth-tab active">User Sign Up</a>
        <a href="register_artist.php" class="auth-tab">Artist Sign Up</a>
    </div>

    <div class="auth-header">
        <h1>Create Account</h1>
        <p>Join Akatsuki and start reading manga today</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error alert-sticky"><?php echo h($error); ?></div>
    <?php endif; ?>

    <form method="POST" action="register_user.php">
        <div class="form-group">
            <label for="name">Full Name *</label>
            <input type="text" id="name" name="name" class="form-control" maxlength="100"
                   placeholder="Enter your full name" value="<?php echo h($_POST['name'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
            <label for="email">Email Address *</label>
            <input type="email" id="email" name="email" class="form-control" maxlength="250"
                   placeholder="Enter your email" value="<?php echo h($_POST['email'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
            <label for="gender">Gender</label>
            <select id="gender" name="gender" class="form-control">
                <option value="">Select gender (optional)</option>
                <?php foreach (['Male', 'Female', 'Other'] as $g): ?>
                    <option value="<?php echo $g; ?>" <?php echo ($_POST['gender'] ?? '') === $g ? 'selected' : ''; ?>><?php echo $g; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="genre_pref">Favorite Genre <small class="form-hint-inline">(used for your recommendations)</small></label>
            <select id="genre_pref" name="genre_pref" class="form-control">
                <option value="">Select preferred genre (optional)</option>
                <?php foreach ($genreOptions as $g): ?>
                    <option value="<?php echo h($g); ?>" <?php echo ($_POST['genre_pref'] ?? '') === $g ? 'selected' : ''; ?>><?php echo h($g); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="password">Password *</label>
            <input type="password" id="password" name="password" class="form-control"
                   placeholder="Create a password (min 6 characters)" minlength="6" required>
        </div>
        <div class="form-group">
            <label for="confirm_password">Confirm Password *</label>
            <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                   placeholder="Confirm your password" minlength="6" required>
        </div>
        <button type="submit" class="btn btn-primary btn-full">Create Account</button>
    </form>

    <div class="auth-footer">
        <p>Already have an account? <a href="login_user.php">Sign in</a></p>
    </div>
</div>

<?php require_once 'footer.php'; ?>
