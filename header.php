<?php
// header.php — site header, navigation and the artist "Go Live" modal.
// Pages must finish all redirects BEFORE including this file (it prints HTML).
require_once __DIR__ . '/db.php';

$__page = basename($_SERVER['PHP_SELF']);
function __navActive(array $pages): string {
    return in_array(basename($_SERVER['PHP_SELF']), $pages, true) ? 'active' : '';
}

// Genres that actually exist, for the dropdown
$__genres = $pdo->query("SELECT DISTINCT Genre FROM GENRE ORDER BY Genre")->fetchAll(PDO::FETCH_COLUMN);

// Red dot on LIVE when any stream is live right now
$__live = (int)$pdo->query("
    SELECT COUNT(*) FROM LIVESTREAM
    WHERE StartTime <= NOW() AND (EndTime IS NULL OR EndTime > NOW())
")->fetchColumn();

$__bellCount = 0;
$__coins     = 0;
if (isUserLoggedIn()) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM NOTIFICATION WHERE UID = ? AND IsRead = FALSE");
    $s->execute([currentUserId()]);
    $__bellCount = (int)$s->fetchColumn();
    $__coins = coinBalance($pdo, currentUserId());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? h($pageTitle) . ' - Akatsuki' : 'Akatsuki - Manga & Webtoon Platform'; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header class="main-header">
        <div class="header-container">
            <a href="index.php" class="logo">
                <span class="logo-text">AKATSUKI</span>
            </a>

            <nav class="main-nav">
                <a href="index.php" class="nav-link <?php echo __navActive(['index.php']); ?>">HOME</a>

                <div class="nav-dropdown">
                    <a href="comics.php" class="nav-link <?php echo __navActive(['comics.php']); ?>">GENRES <span class="dropdown-arrow">▼</span></a>
                    <div class="dropdown-content">
                        <a href="comics.php">All Comics</a>
                        <?php foreach ($__genres as $__g): ?>
                            <a href="comics.php?genre=<?php echo urlencode($__g); ?>"><?php echo h($__g); ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <a href="top-comics.php" class="nav-link <?php echo __navActive(['top-comics.php']); ?>">TOP</a>
                <a href="fan-art.php" class="nav-link <?php echo __navActive(['fan-art.php']); ?>">FAN ART</a>
                <a href="livestream.php" class="nav-link <?php echo __navActive(['livestream.php']); ?>" id="nav-live">
                    <?php if ($__live > 0): ?><span class="live-nav-dot"></span><?php endif; ?>
                    LIVE
                </a>

                <form action="comics.php" method="GET" class="header-search-form">
                    <div class="search-input-group">
                        <?php if (!empty($_GET['genre']) && $__page === 'comics.php'): ?>
                            <input type="hidden" name="genre" value="<?php echo h($_GET['genre']); ?>">
                        <?php endif; ?>
                        <input type="text" name="search" placeholder="Search comics..."
                               value="<?php echo h($_GET['search'] ?? ''); ?>">
                        <button type="submit" class="search-submit-btn" aria-label="Search">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <circle cx="11" cy="11" r="8"></circle>
                                <path d="M21 21l-4.35-4.35"></path>
                            </svg>
                        </button>
                    </div>
                </form>
            </nav>

            <div class="auth-buttons">
                <?php if (isUserLoggedIn()): ?>
                    <a href="notifications.php" class="notif-bell-link" title="Notifications">
                        🔔
                        <?php if ($__bellCount > 0): ?>
                            <span class="notif-count-badge"><?php echo $__bellCount > 9 ? '9+' : $__bellCount; ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="profile.php" class="coin-chip" title="My coins">🪙 <?php echo $__coins; ?></a>
                    <a href="profile.php" class="welcome-text" title="My profile">Welcome, <?php echo h($_SESSION['user_name'] ?? ''); ?></a>
                    <a href="logout.php" class="btn btn-outline btn-sm">Log out</a>
                <?php elseif (isArtistLoggedIn()): ?>
                    <a href="admin_panel.php" class="btn btn-outline btn-sm">🛠 Dashboard</a>
                    <button type="button" class="btn btn-live-header btn-sm"
                            onclick="document.getElementById('golive-modal').style.display='flex'">
                        🔴 Go Live
                    </button>
                    <span class="welcome-text">Welcome, <?php echo h($_SESSION['user_name'] ?? ''); ?></span>
                    <a href="logout.php" class="btn btn-outline btn-sm">Log out</a>
                <?php else: ?>
                    <a href="login_user.php" class="btn btn-outline btn-sm">Sign in</a>
                    <a href="register_user.php" class="btn btn-outline btn-sm">Sign up</a>
                <?php endif; ?>
            </div>

            <button class="mobile-menu-btn" aria-label="Toggle menu">
                <span></span><span></span><span></span>
            </button>
        </div>
    </header>

    <?php if (isArtistLoggedIn()): ?>
    <?php
        $__myComics = $pdo->prepare("SELECT ID, COALESCE(Title, Name) AS Label FROM COMIC WHERE AID = ? ORDER BY Label");
        $__myComics->execute([currentUserId()]);
        $__myComics = $__myComics->fetchAll();

        $__active = $pdo->prepare("
            SELECT VideoURL, Title FROM LIVESTREAM
            WHERE AID = ? AND StartTime <= NOW() AND (EndTime IS NULL OR EndTime > NOW())
        ");
        $__active->execute([currentUserId()]);
        $__active = $__active->fetchAll();
    ?>
    <!-- ── Go Live Modal ─────────────────────────────── -->
    <div id="golive-modal" class="modal-overlay" onclick="if(event.target===this)this.style.display='none'">
        <div class="modal-box">
            <button type="button" class="modal-close"
                    onclick="document.getElementById('golive-modal').style.display='none'">✕</button>

            <?php if (!empty($__active)): ?>
                <div class="currently-live-box">
                    <h3>You are currently live</h3>
                    <?php foreach ($__active as $__as): ?>
                        <div class="currently-live-row">
                            <span><?php echo h($__as['Title']); ?></span>
                            <a href="artist_go_live.php?end=<?php echo urlencode($__as['VideoURL']); ?>"
                               class="btn btn-danger-solid btn-xs"
                               onclick="return confirm('End this stream?')">⏹ End Live</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h2 class="modal-title">🔴 Go Live Now</h2>
            <p class="modal-sub">Start your YouTube stream first, paste the link here, then hit Go Live.</p>

            <?php if (empty($__myComics)): ?>
                <div class="alert alert-info">You need at least one comic before you can go live.
                    <a href="admin_panel.php?tab=comics">Create a comic</a> first.</div>
            <?php else: ?>
            <form method="POST" action="artist_go_live.php">
                <input type="hidden" name="action" value="go_live">
                <div class="form-group">
                    <label>Stream Title</label>
                    <input type="text" name="title" class="form-control" maxlength="200"
                           placeholder="e.g. Drawing Chapter 12 Live!" required>
                </div>
                <div class="form-group">
                    <label>YouTube Live URL</label>
                    <input type="url" name="video_url" class="form-control" maxlength="500"
                           placeholder="https://youtube.com/live/..." required>
                    <small class="form-hint">Copy from YouTube Studio → Go Live</small>
                </div>
                <div class="form-group">
                    <label>Related Comic</label>
                    <select name="comic_id" class="form-control" required>
                        <option value="">— Select one of your comics —</option>
                        <?php foreach ($__myComics as $__c): ?>
                            <option value="<?php echo (int)$__c['ID']; ?>"><?php echo h($__c['Label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline"
                            onclick="document.getElementById('golive-modal').style.display='none'">Cancel</button>
                    <button type="submit" class="btn btn-live-header" style="flex:2">🔴 GO LIVE NOW</button>
                </div>
                <p class="modal-foot"><a href="artist_go_live.php">Schedule a stream or manage your streams →</a></p>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <main class="main-content">
    <?php if ($__flash = getFlash()): ?>
        <div class="alert alert-<?php echo $__flash['type'] === 'error' ? 'error' : 'success'; ?>"><?php echo h($__flash['message']); ?></div>
    <?php endif; ?>
