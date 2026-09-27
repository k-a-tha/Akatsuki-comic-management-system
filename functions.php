<?php
// functions.php — every shared helper lives here (loaded by db.php).
// Pages must NOT re-declare these functions.

// ── Output escaping ─────────────────────────────────────
function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// ── Auth ────────────────────────────────────────────────
function isLoggedIn(): bool {
    return isset($_SESSION['user_id'], $_SESSION['user_type']);
}
function isUserLoggedIn(): bool {
    return isLoggedIn() && $_SESSION['user_type'] === 'user';
}
function isArtistLoggedIn(): bool {
    return isLoggedIn() && $_SESSION['user_type'] === 'artist';
}
function currentUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}
function requireUser(): void {
    if (!isUserLoggedIn()) redirect('login_user.php');
}
function requireArtist(): void {
    if (!isArtistLoggedIn()) redirect('login_artist.php');
}

// ── One-time messages that survive a redirect ───────────
function setFlash(string $message, string $type = 'success'): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}
function getFlash(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ── Strings / URLs ──────────────────────────────────────
function slugify(string $text): string {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
    return $slug !== '' ? $slug : 'item';
}
function isExternalUrl(?string $url): bool {
    return (bool)preg_match('#^https?://#i', (string)$url);
}
/** HardCopyLink is only a "buy" link when it is a real web address. */
function hardCopyUrl(?string $link): ?string {
    return isExternalUrl($link) ? $link : null;
}

// ── Comic helpers ───────────────────────────────────────
/**
 * Cover image path for a comic.
 * Looks for assets/thumbnails/<slug>.(webp|jpg|jpeg|png|gif); falls back to a placeholder.
 * (Older data that stored a local image path in HardCopyLink still works.)
 */
function getThumbnail(?string $hardCopyLink, ?string $comicName): string {
    if ($hardCopyLink && !isExternalUrl($hardCopyLink) && is_file(__DIR__ . '/' . $hardCopyLink)) {
        return $hardCopyLink;
    }
    $slugs = array_unique([slugify((string)$comicName), strtolower(str_replace(' ', '-', (string)$comicName))]);
    foreach ($slugs as $slug) {
        foreach (['webp', 'jpg', 'jpeg', 'png', 'gif'] as $ext) {
            $path = "assets/thumbnails/{$slug}.{$ext}";
            if (is_file(__DIR__ . '/' . $path)) return $path;
        }
    }
    return 'assets/thumbnails/placeholder.webp';
}

/** Scores are 0–10; stars are out of 5. */
function renderStars($rating): string {
    $rating     = (float)$rating;
    $fullStars  = (int)floor($rating / 2);
    $halfStar   = ($rating / 2) - $fullStars >= 0.5;
    $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);
    return str_repeat('★', $fullStars)
         . ($halfStar ? '<span class="half-star">★</span>' : '')
         . str_repeat('☆', max(0, $emptyStars));
}

function getRecentChapters(PDO $pdo, int $comicId, int $limit = 2): array {
    $stmt = $pdo->prepare("
        SELECT CHAPTER_ID, ChapterNumber, Date_of_Publication
        FROM CHAPTER
        WHERE Comic_ID = :comicId AND isPublished = TRUE
        ORDER BY ChapterNumber DESC
        LIMIT :lim
    ");
    $stmt->bindValue(':comicId', $comicId, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * SQL for comic lists: every COMIC column plus AvgRating, RatingCount and WeeklyViews
 * (views of the current ISO week; 0 if the comic has not been viewed this week).
 */
function comicListSql(): string {
    return "
        SELECT c.ID, c.Name, c.Title, c.HardCopyLink, c.Synopsis, c.AID, c.ViewCount,
               COALESCE(r.AvgRating, 0)   AS AvgRating,
               COALESCE(r.RatingCount, 0) AS RatingCount,
               IF(c.LastViewWeek = YEARWEEK(CURDATE(), 1), c.WeeklyViewCount, 0) AS WeeklyViews
        FROM COMIC c
        LEFT JOIN (SELECT Comic_ID, AVG(Score) AS AvgRating, COUNT(*) AS RatingCount
                   FROM RATING GROUP BY Comic_ID) r ON r.Comic_ID = c.ID
    ";
}

/** Comic card used on the home page and the comics grid. */
function comicCard(PDO $pdo, array $comic, array $opts = []): string {
    $title    = $comic['Title'] ?: $comic['Name'];
    $chapters = getRecentChapters($pdo, (int)$comic['ID']);
    $buy      = hardCopyUrl($comic['HardCopyLink'] ?? null);
    ob_start(); ?>
    <article class="comic-card">
        <a href="comic.php?id=<?php echo (int)$comic['ID']; ?>" class="comic-thumbnail">
            <img src="<?php echo h(getThumbnail($comic['HardCopyLink'] ?? null, $comic['Name'])); ?>"
                 alt="<?php echo h($title); ?>" loading="lazy">
            <?php if (!empty($opts['hot'])): ?><span class="hot-badge">HOT</span><?php endif; ?>
        </a>
        <div class="comic-info">
            <h3 class="comic-title"><a href="comic.php?id=<?php echo (int)$comic['ID']; ?>"><?php echo h($title); ?></a></h3>
            <div class="comic-rating">
                <span class="stars"><?php echo renderStars($comic['AvgRating'] ?? 0); ?></span>
                <span class="rating-value"><?php echo number_format((float)($comic['AvgRating'] ?? 0), 1); ?></span>
            </div>
            <div class="comic-chapters">
                <?php foreach ($chapters as $ch):
                    $isNew = $ch['Date_of_Publication'] && strtotime($ch['Date_of_Publication']) >= strtotime('-7 days'); ?>
                    <a href="reader.php?id=<?php echo (int)$ch['CHAPTER_ID']; ?>" class="chapter-link">
                        <span>Chapter <?php echo (int)$ch['ChapterNumber']; ?></span>
                        <?php if ($isNew): ?><span class="chapter-new">NEW</span>
                        <?php elseif ($ch['Date_of_Publication']): ?><span class="chapter-date"><?php echo date('M j', strtotime($ch['Date_of_Publication'])); ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
                <?php if (empty($chapters)): ?><span class="chapter-date">Coming soon</span><?php endif; ?>
            </div>
            <?php if (!empty($opts['buy'])): ?>
                <?php if ($buy): ?>
                    <a href="<?php echo h($buy); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-amber btn-sm btn-full card-buy">🛒 Buy Hard Copy</a>
                <?php else: ?>
                    <span class="btn btn-outline btn-sm btn-full card-buy is-disabled">🛒 Hard Copy Soon</span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </article>
    <?php
    return ob_get_clean();
}

function artistOwnsComic(PDO $pdo, int $comicId, int $artistId): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM COMIC WHERE ID = ? AND AID = ?");
    $stmt->execute([$comicId, $artistId]);
    return (bool)$stmt->fetchColumn();
}

function artistOwnsChapter(PDO $pdo, int $chapterId, int $artistId): bool {
    $stmt = $pdo->prepare("
        SELECT 1 FROM CHAPTER ch
        JOIN COMIC c ON ch.Comic_ID = c.ID
        WHERE ch.CHAPTER_ID = ? AND c.AID = ?
    ");
    $stmt->execute([$chapterId, $artistId]);
    return (bool)$stmt->fetchColumn();
}

/** ISO year+week number, e.g. 202639 — same value as MySQL YEARWEEK(date, 1). */
function currentYearWeek(): int {
    return (int)date('oW');
}

/**
 * Counts a comic page view (once per visitor session per day) and keeps the
 * weekly counter in step: when a new ISO week starts, WeeklyViewCount restarts at 1.
 */
function recordComicView(PDO $pdo, int $comicId): void {
    $key = $comicId . '-' . date('Y-m-d');
    if (!empty($_SESSION['viewed'][$key])) return;
    $_SESSION['viewed'][$key] = true;
    $pdo->prepare("
        UPDATE COMIC
        SET ViewCount       = ViewCount + 1,
            WeeklyViewCount = IF(LastViewWeek = YEARWEEK(CURDATE(), 1), WeeklyViewCount + 1, 1),
            LastViewWeek    = YEARWEEK(CURDATE(), 1)
        WHERE ID = ?
    ")->execute([$comicId]);
}

// ── Coins & notifications ───────────────────────────────
function coinBalance(PDO $pdo, int $uid): int {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(Amount), 0) FROM COIN_TRANSACTION WHERE UID = ?");
    $stmt->execute([$uid]);
    return (int)$stmt->fetchColumn();
}

function notify(PDO $pdo, int $uid, string $message, string $type = 'general'): void {
    $pdo->prepare("INSERT INTO NOTIFICATION (Message, Type, UID, TIME_STAMP) VALUES (?, ?, ?, NOW())")
        ->execute([mb_substr($message, 0, 500), $type, $uid]);
}

// ── Live streams ────────────────────────────────────────
function ytId(?string $url): ?string {
    $pattern = '~(?:youtube\.com/(?:watch\?(?:.*&)?v=|live/|embed/|shorts/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})~i';
    return preg_match($pattern, (string)$url, $m) ? $m[1] : null;
}
function ytThumb(string $id): string {
    return "https://img.youtube.com/vi/{$id}/hqdefault.jpg";
}
/** SQL snippet that turns a LIVESTREAM row into upcoming / live / past (uses MySQL's clock). */
function streamStatusSql(string $alias = 'l'): string {
    return "CASE
                WHEN {$alias}.StartTime > NOW() THEN 'upcoming'
                WHEN {$alias}.EndTime IS NULL OR {$alias}.EndTime > NOW() THEN 'live'
                ELSE 'past'
            END";
}

// ── Dates ───────────────────────────────────────────────
function timeAgo(?string $datetime): string {
    if (!$datetime) return '';
    $diff = (new DateTime())->diff(new DateTime($datetime));
    if ($diff->y > 0) return $diff->y . 'y ago';
    if ($diff->m > 0) return $diff->m . 'mo ago';
    if ($diff->d > 0) return $diff->d . 'd ago';
    if ($diff->h > 0) return $diff->h . 'h ago';
    if ($diff->i > 0) return $diff->i . 'm ago';
    return 'just now';
}

// ── Community emoji tags ────────────────────────────────
/**
 * Escapes post text, then turns [emoji:Name] into <img> using $emojiMap (Name => image path).
 * Unknown tags stay as plain text.
 */
function renderContent(string $raw, array $emojiMap): string {
    $safe = nl2br(htmlspecialchars($raw, ENT_QUOTES, 'UTF-8'));
    return preg_replace_callback('/\[emoji:([^\]]+)\]/', function (array $m) use ($emojiMap): string {
        $name = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        if (!isset($emojiMap[$name])) return $m[0];
        $src = h($emojiMap[$name]);
        $alt = h($name);
        return "<img src=\"{$src}\" alt=\"{$alt}\" title=\"{$alt}\" class=\"post-emoji\">";
    }, $safe);
}

function comicEmojiMap(PDO $pdo, int $comicId): array {
    $stmt = $pdo->prepare("SELECT Name, E_Value FROM SPECIAL_EMOJI WHERE Comic_ID = ?");
    $stmt->execute([$comicId]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// ── Image uploads ───────────────────────────────────────
/**
 * Validates an uploaded image by its real content (not the browser-supplied type)
 * and saves it as <destDir>/<baseName>.<ext>. Returns the site-relative path.
 * Throws RuntimeException with a user-friendly message on failure.
 */
function saveUploadedImage(array $file, string $destDir, string $baseName, int $maxBytes = 5242880): string {
    $errors = [
        UPLOAD_ERR_INI_SIZE   => 'The file is larger than the server allows (upload_max_filesize in php.ini).',
        UPLOAD_ERR_FORM_SIZE  => 'The file is too large.',
        UPLOAD_ERR_PARTIAL    => 'The file was only partly uploaded. Please try again.',
        UPLOAD_ERR_NO_FILE    => 'Please choose a file to upload.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Server could not write the file to disk.',
    ];
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException($errors[$err] ?? 'Upload failed. Please try again.');
    }
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('File is too large. Maximum size is ' . round($maxBytes / 1048576) . ' MB.');
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    // getimagesize() reads the real file content, so a renamed .php file is rejected
    $info = @getimagesize($file['tmp_name']);
    $mime = $info['mime'] ?? '';
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG, WebP or GIF images are allowed.');
    }
    $absDir = __DIR__ . '/' . trim($destDir, '/');
    if (!is_dir($absDir) && !mkdir($absDir, 0775, true)) {
        throw new RuntimeException("Could not create folder {$destDir}. Check folder permissions.");
    }
    $relPath = trim($destDir, '/') . '/' . $baseName . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/' . $relPath)) {
        throw new RuntimeException("Could not save the file. Check that {$destDir} is writable.");
    }
    return $relPath;
}

/** Image files (sorted naturally) inside a folder given relative to the site root. */
function listImages(string $relDir): array {
    $abs = __DIR__ . '/' . trim($relDir, '/');
    if ($relDir === '' || !is_dir($abs)) return [];
    $files = [];
    foreach (scandir($abs) as $f) {
        if (preg_match('/\.(webp|jpe?g|png|gif)$/i', $f)) $files[] = trim($relDir, '/') . '/' . $f;
    }
    natcasesort($files);
    return array_values($files);
}

/** Deletes assets/thumbnails/<slug>.<any image ext> (used when a cover is replaced or a comic deleted). */
function deleteCoverFiles(string $comicName): void {
    foreach (['webp', 'jpg', 'jpeg', 'png', 'gif'] as $ext) {
        $f = __DIR__ . '/assets/thumbnails/' . slugify($comicName) . '.' . $ext;
        if (is_file($f)) @unlink($f);
    }
}

/** Turns $_FILES['x'] with multiple files into a list of single-file arrays. */
function normalizeFiles(array $files): array {
    if (!is_array($files['name'] ?? null)) return [$files];
    $list = [];
    foreach ($files['name'] as $i => $name) {
        $list[] = [
            'name'     => $name,
            'type'     => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error'    => $files['error'][$i],
            'size'     => $files['size'][$i],
        ];
    }
    return $list;
}
