<?php
// community.php — discussion forum for one comic (+ special emoji picker)
require_once 'db.php';

$comicId = (int)($_GET['id'] ?? 0);
if (!$comicId) redirect('index.php');

$stmt = $pdo->prepare(comicListSql() . " WHERE c.ID = ?");
$stmt->execute([$comicId]);
$comic = $stmt->fetch();
if (!$comic) redirect('comics.php');

$self = "community.php?id=$comicId";

// Emojis of this comic that the logged-in reader owns (Name => image path)
$ownedEmojiMap = [];
if (isUserLoggedIn()) {
    $s = $pdo->prepare("
        SELECT se.Name, se.E_Value FROM USER_EMOJI ue
        JOIN SPECIAL_EMOJI se ON ue.Emoji_Name = se.Name
        WHERE ue.UID = ? AND se.Comic_ID = ?
        ORDER BY se.Name
    ");
    $s->execute([currentUserId(), $comicId]);
    $ownedEmojiMap = $s->fetchAll(PDO::FETCH_KEY_PAIR);
}

// ── New post ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['post_content'])) {
    if (!isUserLoggedIn()) redirect('login_user.php');

    $content = trim(strip_tags($_POST['post_content']));

    // Keep only [emoji:Name] tags for emojis this reader actually owns
    $usedEmojis = [];
    $content = preg_replace_callback('/\[emoji:([^\]]+)\]/', function ($m) use ($ownedEmojiMap, &$usedEmojis) {
        if (isset($ownedEmojiMap[$m[1]])) {
            $usedEmojis[$m[1]] = true;
            return $m[0];
        }
        return '';
    }, $content);
    $content = trim($content);

    if ($content === '') {
        setFlash('Your post is empty.', 'error');
    } elseif (mb_strlen($content) > 500) {
        setFlash('Posts can be at most 500 characters.', 'error');
    } else {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO FORUM_POST (Content, TimeStamp, UID, Comic_ID) VALUES (?, NOW(), ?, ?)")
            ->execute([$content, currentUserId(), $comicId]);
        $postId = (int)$pdo->lastInsertId();
        $usesOn = $pdo->prepare("INSERT INTO USES_ON (E_Name, F_post_ID) VALUES (?, ?)");
        foreach (array_keys($usedEmojis) as $emojiName) $usesOn->execute([$emojiName, $postId]);
        $pdo->commit();
        setFlash('✓ Your post has been published!');
    }
    redirect($self);
}

// ── Delete own post ───────────────────────────────────
if (isset($_GET['delete']) && isUserLoggedIn()) {
    $pdo->prepare("DELETE FROM FORUM_POST WHERE ID = ? AND UID = ? AND Comic_ID = ?")
        ->execute([(int)$_GET['delete'], currentUserId(), $comicId]);
    setFlash('Post deleted.');
    redirect($self);
}

// ── Page data ─────────────────────────────────────────
$stmt = $pdo->prepare("SELECT Genre FROM GENRE WHERE Comic_ID = ? ORDER BY Genre");
$stmt->execute([$comicId]);
$genres = $stmt->fetchAll(PDO::FETCH_COLUMN);

$artistName = $pdo->prepare("SELECT Name FROM ARTIST WHERE ID = ?");
$artistName->execute([$comic['AID']]);
$artistName = $artistName->fetchColumn();

$sort    = ($_GET['sort'] ?? '') === 'oldest' ? 'oldest' : 'newest';
$orderBy = $sort === 'oldest' ? 'fp.TimeStamp ASC, fp.ID ASC' : 'fp.TimeStamp DESC, fp.ID DESC';

$stmt = $pdo->prepare("
    SELECT fp.ID, fp.Content, fp.TimeStamp, u.ID AS UserID, u.Name AS UserName
    FROM FORUM_POST fp JOIN USERS u ON fp.UID = u.ID
    WHERE fp.Comic_ID = ?
    ORDER BY $orderBy
");
$stmt->execute([$comicId]);
$posts = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COUNT(DISTINCT UID) FROM FORUM_POST WHERE Comic_ID = ?");
$stmt->execute([$comicId]);
$memberCount = (int)$stmt->fetchColumn();

$emojiMap    = comicEmojiMap($pdo, $comicId);
$totalEmojis = count($emojiMap);
$comicTitle  = $comic['Title'] ?: $comic['Name'];
$pageTitle   = $comicTitle . ' — Community';

require_once 'header.php';
?>

<div class="community-banner">
    <img src="<?php echo h(getThumbnail($comic['HardCopyLink'], $comic['Name'])); ?>" alt="<?php echo h($comicTitle); ?>" class="banner-cover">
    <div class="banner-info">
        <div class="banner-title"><?php echo h($comicTitle); ?> Community</div>
        <div class="banner-meta">
            <span>💬 <?php echo count($posts); ?> posts</span>
            <span>👥 <?php echo $memberCount; ?> members</span>
            <span>⭐ <?php echo number_format((float)$comic['AvgRating'], 1); ?> avg rating</span>
            <?php if ($artistName): ?><span>✏️ <?php echo h($artistName); ?></span><?php endif; ?>
        </div>
        <?php if ($genres): ?>
            <div class="genre-tags">
                <?php foreach ($genres as $g): ?><span class="genre-tag genre-tag-sm"><?php echo h($g); ?></span><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="banner-actions">
            <a href="comic.php?id=<?php echo $comicId; ?>" class="btn btn-outline btn-sm">← Back to Comic</a>
            <a href="emoji_store.php?id=<?php echo $comicId; ?>" class="btn btn-outline btn-sm">🛍️ Emoji Store</a>
        </div>
    </div>
</div>

<div class="community-layout">
    <div class="community-main">
        <?php if (isUserLoggedIn()): ?>
            <div class="card compose-card">
                <h3 class="card-title">Share your thoughts about <?php echo h($comicTitle); ?></h3>
                <form method="POST" action="<?php echo h($self); ?>" id="postForm">
                    <textarea name="post_content" class="form-control compose-textarea" maxlength="500" id="postTextarea" required
                              placeholder="What are your thoughts? Theories, reactions, fan discussions — all welcome!"></textarea>

                    <div class="compose-toolbar">
                        <?php if ($ownedEmojiMap): ?>
                            <button type="button" class="btn-emoji-picker" onclick="toggleEmojiPicker()">🎭 Insert Emoji</button>
                        <?php else: ?>
                            <a href="emoji_store.php?id=<?php echo $comicId; ?>" class="btn-emoji-picker">🛍️ Get Emojis</a>
                        <?php endif; ?>
                        <span class="char-counter" id="charCounter">0 / 500</span>
                    </div>

                    <?php if ($ownedEmojiMap): ?>
                        <div class="emoji-picker-panel" id="emojiPickerPanel">
                            <?php foreach ($ownedEmojiMap as $name => $path): ?>
                                <button type="button" class="ep-item" data-emoji="<?php echo h($name); ?>" title="<?php echo h($name); ?>">
                                    <img src="<?php echo h($path); ?>" alt="<?php echo h($name); ?>" onerror="this.style.opacity='0.3'">
                                    <span class="ep-name"><?php echo h($name); ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="compose-footer">
                        <button type="submit" class="btn btn-primary">Post</button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="card login-prompt">
                💬 <?php if (isArtistLoggedIn()): ?>Posting is for reader accounts — artists can read along here.
                <?php else: ?><a href="login_user.php">Sign in</a> to join the community and post your thoughts!<?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="posts-controls">
            <span class="posts-count"><?php echo count($posts); ?> Post<?php echo count($posts) === 1 ? '' : 's'; ?></span>
            <div class="sort-tabs">
                <a href="<?php echo h($self); ?>&sort=newest" class="sort-tab <?php echo $sort === 'newest' ? 'active' : ''; ?>">Newest</a>
                <a href="<?php echo h($self); ?>&sort=oldest" class="sort-tab <?php echo $sort === 'oldest' ? 'active' : ''; ?>">Oldest</a>
            </div>
        </div>

        <?php if (!$posts): ?>
            <div class="empty-state">
                <div class="empty-icon">💬</div>
                <h3>No posts yet</h3>
                <p>Be the first to start a discussion about <?php echo h($comicTitle); ?>!</p>
            </div>
        <?php endif; ?>

        <?php foreach ($posts as $post): ?>
            <div class="post-card">
                <div class="post-header">
                    <div class="avatar"><?php echo h(mb_substr($post['UserName'], 0, 1)); ?></div>
                    <div class="post-author-info">
                        <div class="post-author"><?php echo h($post['UserName']); ?></div>
                        <div class="post-time"><?php echo timeAgo($post['TimeStamp']); ?> · <?php echo date('M j, Y', strtotime($post['TimeStamp'])); ?></div>
                    </div>
                    <?php if (isUserLoggedIn() && currentUserId() === (int)$post['UserID']): ?>
                        <a href="<?php echo h($self); ?>&delete=<?php echo (int)$post['ID']; ?>" class="post-delete"
                           onclick="return confirm('Delete this post?')">🗑 Delete</a>
                    <?php endif; ?>
                </div>
                <div class="post-content"><?php echo renderContent($post['Content'], $emojiMap); ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <aside class="community-sidebar">
        <div class="side-card">
            <h4 class="side-title">Community Stats</h4>
            <div class="stat-row"><span class="stat-label">Total Posts</span><span class="stat-value"><?php echo count($posts); ?></span></div>
            <div class="stat-row"><span class="stat-label">Members</span><span class="stat-value"><?php echo $memberCount; ?></span></div>
            <div class="stat-row"><span class="stat-label">Avg Rating</span><span class="stat-value"><?php echo number_format((float)$comic['AvgRating'], 1); ?> / 10</span></div>
            <div class="stat-row"><span class="stat-label">Total Reviews</span><span class="stat-value"><?php echo (int)$comic['RatingCount']; ?></span></div>
        </div>

        <?php if ($totalEmojis > 0): ?>
        <div class="side-card">
            <h4 class="side-title">🎭 Special Emojis</h4>
            <p class="muted small"><?php echo count($ownedEmojiMap); ?> / <?php echo $totalEmojis; ?> emojis owned. Earn coins by predicting correctly!</p>
            <a href="emoji_store.php?id=<?php echo $comicId; ?>" class="btn btn-primary btn-full">🛍️ Emoji Store</a>
        </div>
        <?php endif; ?>

        <div class="side-card">
            <h4 class="side-title">Community Rules</h4>
            <ol class="rules-list">
                <li>Be respectful to other members</li>
                <li>No spoilers without warning</li>
                <li>Stay on topic for this comic</li>
                <li>No spam or self-promotion</li>
                <li>Have fun and enjoy the discussion!</li>
            </ol>
        </div>

        <div class="side-card">
            <h4 class="side-title">Quick Links</h4>
            <a href="comic.php?id=<?php echo $comicId; ?>" class="btn btn-outline btn-full">Comic Details</a>
            <a href="comics.php" class="btn btn-outline btn-full" style="margin-top:8px">All Comics</a>
        </div>
    </aside>
</div>

<script>
const textarea = document.getElementById('postTextarea');
const counter  = document.getElementById('charCounter');
function updateCounter() {
    if (!textarea) return;
    const len = textarea.value.length;
    counter.textContent = len + ' / 500';
    counter.className = 'char-counter' + (len >= 500 ? ' over' : len > 450 ? ' warn' : '');
}
if (textarea) textarea.addEventListener('input', updateCounter);

function toggleEmojiPicker() {
    const panel = document.getElementById('emojiPickerPanel');
    if (panel) panel.classList.toggle('open');
}
document.querySelectorAll('.ep-item').forEach(item => item.addEventListener('click', () => {
    const tag = '[emoji:' + item.dataset.emoji + ']';
    if (textarea.value.length + tag.length > 500) return;
    const start = textarea.selectionStart, end = textarea.selectionEnd;
    textarea.value = textarea.value.slice(0, start) + tag + textarea.value.slice(end);
    textarea.selectionStart = textarea.selectionEnd = start + tag.length;
    textarea.focus();
    updateCounter();
}));
document.addEventListener('click', e => {
    const panel = document.getElementById('emojiPickerPanel');
    const btn   = document.querySelector('.btn-emoji-picker');
    if (panel && !panel.contains(e.target) && btn && !btn.contains(e.target)) panel.classList.remove('open');
});
</script>

<?php require_once 'footer.php'; ?>
