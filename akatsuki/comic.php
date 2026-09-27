<?php
// comic.php — comic details: rating & reviews, chapters, prediction poll,
// characters (favourite voting), live stream buttons, community preview.
require_once 'db.php';

$comicId = (int)($_GET['id'] ?? 0);
if (!$comicId) redirect('index.php');

$exists = $pdo->prepare("SELECT ID, AID FROM COMIC WHERE ID = ?");
$exists->execute([$comicId]);
$comicRow = $exists->fetch();
if (!$comicRow) redirect('comics.php');

$self            = "comic.php?id=$comicId";
$artistOwnsComic = isArtistLoggedIn() && (int)$comicRow['AID'] === currentUserId();

// ── POST: rating & review (readers) ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['score'])) {
    if (!isUserLoggedIn()) redirect('login_user.php');
    $score  = (float)$_POST['score'];
    $review = mb_substr(trim($_POST['review_text'] ?? ''), 0, 1000);
    if ($score < 1 || $score > 10) {
        setFlash('Please pick a score between 1 and 10.', 'error');
    } else {
        $pdo->prepare("
            INSERT INTO RATING (UID, Comic_ID, Score, ReviewText, TIME_STAMP)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE Score = VALUES(Score), ReviewText = VALUES(ReviewText), TIME_STAMP = NOW()
        ")->execute([currentUserId(), $comicId, $score, $review !== '' ? $review : null]);
        setFlash('Thanks! Your rating has been saved.');
    }
    redirect($self . '#reviews');
}

// ── POST: vote for a favourite character (readers, 1 vote per comic per day) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vote_character') {
    if (!isUserLoggedIn()) redirect('login_user.php');
    $charId = (int)($_POST['character_id'] ?? 0);
    $chk = $pdo->prepare("SELECT Name FROM CHARACTERS WHERE ID = ? AND Comic_ID = ?");
    $chk->execute([$charId, $comicId]);
    $charName = $chk->fetchColumn();

    $already = $pdo->prepare("SELECT 1 FROM VOTE_FOR WHERE UID = ? AND Comic_ID = ? AND Vote_Date = CURDATE()");
    $already->execute([currentUserId(), $comicId]);

    if (!$charName) {
        setFlash('That character does not belong to this comic.', 'error');
    } elseif ($already->fetchColumn()) {
        setFlash('You already voted for a favourite character of this comic today. Come back tomorrow!', 'error');
    } else {
        $pdo->prepare("INSERT INTO VOTE_FOR (Vote_Date, Character_ID, Comic_ID, UID) VALUES (CURDATE(), ?, ?, ?)")
            ->execute([$charId, $comicId, currentUserId()]);
        setFlash("❤ You voted for {$charName}!");
    }
    redirect($self . '#characters');
}

// ── POST: artist goes live from this comic page ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'comic_go_live') {
    if (!$artistOwnsComic) redirect($self);
    $url   = trim($_POST['video_url'] ?? '');
    $title = trim($_POST['stream_title'] ?? '');
    if (!isExternalUrl($url) || $title === '') {
        setFlash('Please enter a stream title and a valid YouTube link.', 'error');
    } else {
        $pdo->prepare("
            INSERT INTO LIVESTREAM (VideoURL, Title, StartTime, EndTime, AID, Comic_ID)
            VALUES (?, ?, NOW(), NULL, ?, ?)
            ON DUPLICATE KEY UPDATE Title = VALUES(Title), StartTime = NOW(), EndTime = NULL,
                                    AID = VALUES(AID), Comic_ID = VALUES(Comic_ID)
        ")->execute([mb_substr($url, 0, 500), mb_substr($title, 0, 200), currentUserId(), $comicId]);
        setFlash("🔴 You're live! Viewers can now see your stream on this comic's page.");
    }
    redirect($self);
}

// ── GET: artist ends the live stream from this page ───
if (isset($_GET['end_live']) && $artistOwnsComic) {
    $pdo->prepare("
        UPDATE LIVESTREAM SET EndTime = NOW()
        WHERE Comic_ID = ? AND AID = ? AND StartTime <= NOW() AND (EndTime IS NULL OR EndTime > NOW())
    ")->execute([$comicId, currentUserId()]);
    setFlash('⏹ Stream ended.');
    redirect($self);
}

// ── Count the page view (the owner's own visits are not counted) ──
if (!$artistOwnsComic) recordComicView($pdo, $comicId);

// ── Fetch comic ───────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT c.*, a.Name AS ArtistName,
           COALESCE((SELECT AVG(Score) FROM RATING WHERE Comic_ID = c.ID), 0) AS AvgRating,
           (SELECT COUNT(*) FROM RATING WHERE Comic_ID = c.ID)                AS RatingCount
    FROM COMIC c
    LEFT JOIN ARTIST a ON c.AID = a.ID
    WHERE c.ID = ?
");
$stmt->execute([$comicId]);
$comic      = $stmt->fetch();
$comicTitle = $comic['Title'] ?: $comic['Name'];
$pageTitle  = $comicTitle;

$stmt = $pdo->prepare("SELECT Genre FROM GENRE WHERE Comic_ID = ? ORDER BY Genre");
$stmt->execute([$comicId]);
$genres = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = $pdo->prepare("SELECT * FROM CHAPTER WHERE Comic_ID = ? AND isPublished = TRUE ORDER BY ChapterNumber DESC");
$stmt->execute([$comicId]);
$chapters = $stmt->fetchAll();

// Reviews (+ the current reader's own rating to pre-fill the modal)
$stmt = $pdo->prepare("
    SELECT r.*, u.Name AS UserName FROM RATING r JOIN USERS u ON r.UID = u.ID
    WHERE r.Comic_ID = ? ORDER BY r.TIME_STAMP DESC
");
$stmt->execute([$comicId]);
$reviews  = $stmt->fetchAll();
$myRating = null;
foreach ($reviews as $rv) {
    if (isUserLoggedIn() && (int)$rv['UID'] === currentUserId()) $myRating = $rv;
}

// Live stream happening now for this comic
$liveStmt = $pdo->prepare("
    SELECT VideoURL, Title, AID FROM LIVESTREAM
    WHERE Comic_ID = ? AND StartTime <= NOW() AND (EndTime IS NULL OR EndTime > NOW())
    ORDER BY StartTime DESC LIMIT 1
");
$liveStmt->execute([$comicId]);
$liveStream   = $liveStmt->fetch();
$artistIsLive = $artistOwnsComic && $liveStream && (int)$liveStream['AID'] === currentUserId();

// Reading guide
$guideExists = !empty($comic['PDF']) && is_file(__DIR__ . "/assets/guides/comic-{$comicId}.html");

// Characters with favourite votes
$stmt = $pdo->prepare("
    SELECT ch.*,
           (SELECT COUNT(*) FROM VOTE_FOR v WHERE v.Character_ID = ch.ID) AS TotalFavoriteCount
    FROM CHARACTERS ch WHERE ch.Comic_ID = ? ORDER BY ch.ID
");
$stmt->execute([$comicId]);
$characters = $stmt->fetchAll();

$votedTodayFor = null;
if (isUserLoggedIn()) {
    $s = $pdo->prepare("SELECT Character_ID FROM VOTE_FOR WHERE UID = ? AND Comic_ID = ? AND Vote_Date = CURDATE()");
    $s->execute([currentUserId(), $comicId]);
    $votedTodayFor = $s->fetchColumn() ?: null;
}

// ── Prediction poll for the latest published chapter ──
$latestChapter  = $chapters[0] ?? null;
$predOptions    = [];
$pollResolved   = false;
$userPrediction = null;
$totalVotes     = 0;
$voteCounts     = [];

if ($latestChapter) {
    $optStmt = $pdo->prepare("
        SELECT po.ID, po.Option_Number, po.OptionText, po.IsCorrect, po.IsResolved, COUNT(p.ID) AS VoteCount
        FROM PREDICTION_OPTION po
        LEFT JOIN PREDICTION p ON p.Option_ID = po.ID
        WHERE po.Chapter_ID = ?
        GROUP BY po.ID, po.Option_Number, po.OptionText, po.IsCorrect, po.IsResolved
        ORDER BY po.Option_Number
    ");
    $optStmt->execute([$latestChapter['CHAPTER_ID']]);
    $predOptions = $optStmt->fetchAll();

    foreach ($predOptions as $o) {
        $voteCounts[$o['ID']] = (int)$o['VoteCount'];
        $totalVotes += (int)$o['VoteCount'];
    }
    if ($predOptions) {
        $pollResolved = (bool)$predOptions[0]['IsResolved'];
        if (isUserLoggedIn()) {
            $s = $pdo->prepare("
                SELECT p.Option_ID FROM PREDICTION p JOIN PREDICTION_OPTION po ON p.Option_ID = po.ID
                WHERE po.Chapter_ID = ? AND p.UID = ?
            ");
            $s->execute([$latestChapter['CHAPTER_ID'], currentUserId()]);
            $userPrediction = $s->fetchColumn() ?: null;
        }
    }
}

// Community preview
$stmt = $pdo->prepare("
    SELECT f.*, u.Name AS UserName FROM FORUM_POST f JOIN USERS u ON f.UID = u.ID
    WHERE f.Comic_ID = ? ORDER BY f.TimeStamp DESC LIMIT 3
");
$stmt->execute([$comicId]);
$previewPosts = $stmt->fetchAll();
$emojiMap     = comicEmojiMap($pdo, $comicId);

require_once 'header.php';
?>

<?php if (isset($_GET['no_guide'])): ?>
    <div class="alert alert-info">This comic does not have a reading guide yet.</div>
<?php endif; ?>

<div class="comic-detail">
    <div class="comic-cover">
        <img src="<?php echo h(getThumbnail($comic['HardCopyLink'], $comic['Name'])); ?>" alt="<?php echo h($comicTitle); ?>">
        <?php if ($liveStream): ?>
            <a href="<?php echo h($liveStream['VideoURL']); ?>" target="_blank" rel="noopener" class="btn-live cover-live">
                <span class="live-dot-btn"></span> LIVE NOW
            </a>
        <?php endif; ?>
    </div>

    <div class="comic-meta">
        <h1><?php echo h($comicTitle); ?></h1>

        <div class="meta-row">
            <button type="button" class="asura-rating-trigger" onclick="toggleRatingPopup(true)">
                <span class="star-icon">★</span>
                <span class="score-text"><?php echo number_format((float)$comic['AvgRating'], 1); ?></span>
                <span class="rate-text"><?php echo $myRating ? 'Edit rating' : 'Rate'; ?></span>
            </button>
            <a href="#reviews" class="muted">(<?php echo (int)$comic['RatingCount']; ?> review<?php echo (int)$comic['RatingCount'] === 1 ? '' : 's'; ?>)</a>
        </div>

        <div class="meta-row">
            <span class="meta-item"><strong>Author:</strong> <?php echo h($comic['ArtistName'] ?? 'Unknown'); ?></span>
            <span class="meta-item"><strong>Chapters:</strong> <?php echo count($chapters); ?></span>
            <span class="meta-item"><strong>Views:</strong> <?php echo number_format((int)$comic['ViewCount']); ?></span>
        </div>

        <?php if ($genres): ?>
            <div class="genre-tags">
                <?php foreach ($genres as $g): ?>
                    <a href="comics.php?genre=<?php echo urlencode($g); ?>" class="genre-tag"><?php echo h($g); ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($comic['Synopsis'])): ?>
            <div class="synopsis"><strong>Synopsis:</strong><br><br><?php echo nl2br(h($comic['Synopsis'])); ?></div>
        <?php endif; ?>

        <div class="action-buttons">
            <?php if ($chapters): ?>
                <a href="reader.php?id=<?php echo (int)end($chapters)['CHAPTER_ID']; ?>" class="btn btn-primary">Read First Chapter</a>
                <a href="reader.php?id=<?php echo (int)$chapters[0]['CHAPTER_ID']; ?>" class="btn btn-secondary">Read Latest Chapter</a>
            <?php endif; ?>
            <a href="community.php?id=<?php echo $comicId; ?>" class="btn btn-outline">💬 Community</a>
            <a href="emoji_store.php?id=<?php echo $comicId; ?>" class="btn btn-outline">🛍️ Emoji Store</a>

            <?php if ($buy = hardCopyUrl($comic['HardCopyLink'])): ?>
                <a href="<?php echo h($buy); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-amber" title="Buy physical copy - opens in new tab">🛒 Buy Hard Copy</a>
            <?php else: ?>
                <span class="btn btn-outline is-disabled" title="No hard copy available yet">🛒 Hard Copy (Coming Soon)</span>
            <?php endif; ?>

            <?php if ($guideExists): ?>
                <a href="view_guide.php?comic_id=<?php echo $comicId; ?>" class="btn btn-outline">📖 Reading Guide</a>
            <?php endif; ?>
            <a href="livestream.php?comic_id=<?php echo $comicId; ?>" class="btn btn-outline">📺 Live Streams</a>

            <?php if ($liveStream && !$artistIsLive): ?>
                <a href="<?php echo h($liveStream['VideoURL']); ?>" target="_blank" rel="noopener" class="btn-live">
                    <span class="live-dot-btn"></span> Watch Live
                </a>
            <?php endif; ?>
        </div>

        <?php if ($artistOwnsComic): ?>
            <div class="owner-bar">
                <span class="owner-label">You own this comic</span>
                <?php if ($artistIsLive): ?>
                    <a href="<?php echo h($self); ?>&end_live=1" class="btn-end-live" onclick="return confirm('End your live stream?')">⏹ End Live</a>
                <?php else: ?>
                    <button type="button" class="btn-go-live-artist" onclick="toggleLiveModal(true)">🔴 Go Live</button>
                <?php endif; ?>
                <a href="create_guide.php?comic_id=<?php echo $comicId; ?>" class="btn btn-outline btn-sm"><?php echo $guideExists ? '✏️ Edit Guide' : '📖 Create Guide'; ?></a>
                <a href="admin_panel.php?tab=comics" class="btn btn-outline btn-sm">🛠 Manage in Dashboard</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Artist Go Live Modal ───────────────────────────── -->
<?php if ($artistOwnsComic && !$artistIsLive): ?>
<div id="goLiveModal" class="modal-overlay" onclick="if(event.target===this)toggleLiveModal(false)">
    <div class="modal-box">
        <button type="button" class="modal-close" onclick="toggleLiveModal(false)">✕</button>
        <h2 class="modal-title">🔴 Go Live on <?php echo h($comicTitle); ?></h2>
        <p class="modal-sub">Paste your YouTube Live URL and give your stream a title. Viewers will see a Live button on this comic page.</p>
        <form method="POST" action="<?php echo h($self); ?>">
            <input type="hidden" name="action" value="comic_go_live">
            <div class="form-group">
                <label>Stream Title</label>
                <input type="text" name="stream_title" class="form-control" maxlength="200"
                       placeholder="e.g. Drawing Chapter <?php echo count($chapters) + 1; ?> Live!" required>
            </div>
            <div class="form-group">
                <label>YouTube Live URL</label>
                <input type="url" name="video_url" class="form-control" maxlength="500" placeholder="https://youtube.com/live/..." required>
                <small class="form-hint">Copy this from YouTube Studio → Go Live after you start streaming.</small>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="toggleLiveModal(false)">Cancel</button>
                <button type="submit" class="btn btn-live-header" style="flex:2">🔴 GO LIVE NOW</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ── Rating Modal ───────────────────────────────────── -->
<div id="asuraRatingModal" class="modal-overlay" onclick="if(event.target===this)toggleRatingPopup(false)">
    <div class="modal-box rating-modal-box">
        <button type="button" class="modal-close" onclick="toggleRatingPopup(false)">✕</button>
        <?php if (isUserLoggedIn()): ?>
            <h2 class="modal-title"><?php echo $myRating ? 'Update Your Rating' : 'Rate This Comic'; ?></h2>
            <p class="modal-sub">Give it a score out of 10</p>
            <form method="POST" action="<?php echo h($self); ?>">
                <div class="star-rating-row">
                    <?php for ($i = 10; $i >= 1; $i--): ?>
                        <input type="radio" id="star<?php echo $i; ?>" name="score" value="<?php echo $i; ?>" required
                               <?php echo $myRating && (int)round($myRating['Score']) === $i ? 'checked' : ''; ?>>
                        <label for="star<?php echo $i; ?>" title="<?php echo $i; ?>/10">★</label>
                    <?php endfor; ?>
                </div>
                <div class="star-rating-value" id="starValue"><?php echo $myRating ? (int)round($myRating['Score']) . ' / 10' : 'Pick a score'; ?></div>
                <textarea name="review_text" class="form-control" rows="4" maxlength="1000"
                          placeholder="Write a short review (optional)..."><?php echo h($myRating['ReviewText'] ?? ''); ?></textarea>
                <div class="modal-actions">
                    <button type="button" class="btn btn-outline" onclick="toggleRatingPopup(false)">Cancel</button>
                    <button type="submit" class="btn btn-primary">Submit</button>
                </div>
            </form>
        <?php elseif (isArtistLoggedIn()): ?>
            <h2 class="modal-title">Readers only</h2>
            <p class="modal-sub">Ratings and reviews are for reader accounts. Log in as a reader to rate comics.</p>
        <?php else: ?>
            <h2 class="modal-title">Login Required</h2>
            <p class="modal-sub">You must be logged in to rate and review comics.</p>
            <a href="login_user.php" class="btn btn-primary btn-full">Log in</a>
        <?php endif; ?>
    </div>
</div>

<section class="panel-section">
    <h2>Chapters (<?php echo count($chapters); ?>)</h2>
    <div class="chapters-list">
        <?php foreach ($chapters as $chapter): ?>
            <a href="reader.php?id=<?php echo (int)$chapter['CHAPTER_ID']; ?>" class="chapter-item">
                <span class="chapter-title">Chapter <?php echo (int)$chapter['ChapterNumber']; ?></span>
                <span class="chapter-meta"><?php echo $chapter['Date_of_Publication'] ? date('M j, Y', strtotime($chapter['Date_of_Publication'])) : 'N/A'; ?></span>
            </a>
        <?php endforeach; ?>
        <?php if (!$chapters): ?>
            <p class="empty-note">No chapters available yet.</p>
        <?php endif; ?>
    </div>
</section>

<!-- ── Prediction poll ────────────────────────────────── -->
<?php if ($predOptions): ?>
<section class="prediction-section" id="prediction">
    <div class="prediction-header">
        <h2>
            🔮 Chapter <?php echo (int)$latestChapter['ChapterNumber']; ?> Prediction
            <span class="<?php echo $pollResolved ? 'poll-resolved-badge' : 'poll-open-badge'; ?>"><?php echo $pollResolved ? 'Resolved' : 'Open'; ?></span>
        </h2>
        <p class="prediction-subtitle">
            <?php echo $pollResolved
                ? 'Results are in! See how your prediction compared.'
                : 'What do you think happens in the next chapter? Pick one — you cannot change it!'; ?>
        </p>
    </div>

    <?php if (!$pollResolved && !isUserLoggedIn()): ?>
        <div class="prediction-login-prompt">
            <?php if (isArtistLoggedIn()): ?>
                Artists can watch the poll here; predictions are for reader accounts.
            <?php else: ?>
                You must be <a href="login_user.php">logged in</a> to make a prediction and earn coins.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php $canPick = !$pollResolved && !$userPrediction && isUserLoggedIn(); ?>
    <div class="prediction-options" id="predictionOptions">
        <?php foreach ($predOptions as $opt):
            $voteCount = $voteCounts[$opt['ID']] ?? 0;
            $percent   = $totalVotes > 0 ? round($voteCount / $totalVotes * 100) : 0;
            $isMyPick  = $userPrediction && (int)$userPrediction === (int)$opt['ID'];
            $isCorrect = (bool)$opt['IsCorrect'];
            $showBars  = $pollResolved || $userPrediction || isArtistLoggedIn();

            $optClass = 'prediction-option';
            if ($pollResolved && $isCorrect)      $optClass .= ' option-correct';
            elseif ($pollResolved && $isMyPick)   $optClass .= ' option-wrong-pick';
            elseif (!$pollResolved && $isMyPick)  $optClass .= ' option-selected';
            elseif ($pollResolved)                $optClass .= ' option-neutral';
            if ($canPick)                         $optClass .= ' option-clickable';
        ?>
            <div class="<?php echo $optClass; ?>" data-option-id="<?php echo (int)$opt['ID']; ?>"
                 <?php if ($canPick): ?>onclick="selectOption(<?php echo (int)$opt['ID']; ?>, this)" role="button" tabindex="0"<?php endif; ?>>
                <div class="option-top-row">
                    <div class="option-text-wrap">
                        <?php if ($pollResolved && $isCorrect): ?>
                            <span class="option-badge badge-correct">✓ Correct</span>
                        <?php elseif ($pollResolved && $isMyPick): ?>
                            <span class="option-badge badge-wrong">✗ Your Pick</span>
                        <?php elseif ($isMyPick): ?>
                            <span class="option-badge badge-mypick">Your Pick</span>
                        <?php endif; ?>
                        <span class="option-number"><?php echo (int)$opt['Option_Number']; ?>.</span>
                        <span class="option-text"><?php echo h($opt['OptionText']); ?></span>
                    </div>
                    <?php if ($showBars): ?><span class="option-percent"><?php echo $percent; ?>%</span><?php endif; ?>
                </div>
                <?php if ($showBars): ?>
                    <div class="option-bar-wrap">
                        <div class="option-bar <?php echo $isCorrect && $pollResolved ? 'bar-correct' : ($isMyPick ? 'bar-mine' : ''); ?>" style="width: <?php echo $percent; ?>%;"></div>
                    </div>
                    <div class="option-vote-count"><?php echo $voteCount; ?> vote<?php echo $voteCount === 1 ? '' : 's'; ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($canPick): ?>
        <div class="prediction-confirm-area" id="confirmArea" style="display:none;">
            <p class="confirm-warning">⚠️ Once you confirm, your prediction is locked and cannot be changed.</p>
            <button type="button" class="btn btn-primary" id="confirmPredBtn" onclick="submitPrediction()">Confirm My Prediction</button>
            <button type="button" class="btn btn-outline" onclick="clearSelection()">Cancel</button>
        </div>
        <div id="predMessage" class="prediction-message" style="display:none;"></div>
    <?php endif; ?>

    <?php if ($userPrediction && !$pollResolved): ?>
        <p class="prediction-locked-msg">🔒 Your prediction is locked. Check back when the next chapter drops to see if you were right!</p>
    <?php endif; ?>

    <?php if ($pollResolved && $userPrediction):
        $userWon = false;
        foreach ($predOptions as $o) if ((int)$o['ID'] === (int)$userPrediction && $o['IsCorrect']) $userWon = true; ?>
        <div class="prediction-result-banner <?php echo $userWon ? 'result-win' : 'result-lose'; ?>">
            <?php if ($userWon): ?>
                🎉 You predicted correctly and earned <strong>1 coin</strong>! <a href="notifications.php">View notifications</a>
            <?php else: ?>
                Better luck next time! Check the next chapter's poll.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="prediction-footer">
        <span><?php echo $totalVotes; ?> total prediction<?php echo $totalVotes === 1 ? '' : 's'; ?> made</span>
        <span class="coin-reward-note">🪙 Correct predictions earn 1 coin</span>
    </div>
</section>
<?php endif; ?>

<!-- ── Characters ─────────────────────────────────────── -->
<?php if ($characters): ?>
<section class="panel-section" id="characters">
    <div class="panel-head">
        <h2>Characters</h2>
        <span class="muted">
            <?php if (isUserLoggedIn()): ?>
                <?php echo $votedTodayFor ? 'Thanks for voting today! Vote again tomorrow.' : 'Vote for your favourite — 1 vote per comic per day.'; ?>
            <?php else: ?>
                <a href="top-comics.php#characters">See the favourite-character leaderboard →</a>
            <?php endif; ?>
        </span>
    </div>
    <div class="characters-grid">
        <?php foreach ($characters as $character): ?>
            <div class="character-card <?php echo (int)$votedTodayFor === (int)$character['ID'] ? 'voted' : ''; ?>">
                <div class="character-avatar"><?php echo h(mb_substr($character['Name'], 0, 1)); ?></div>
                <div class="character-name"><?php echo h($character['Name']); ?></div>
                <?php if (!empty($character['Biography'])): ?>
                    <div class="character-bio" title="<?php echo h($character['Biography']); ?>">
                        <?php echo h(mb_strimwidth($character['Biography'], 0, 70, '…')); ?>
                    </div>
                <?php endif; ?>
                <div class="character-votes">❤ <?php echo (int)$character['TotalFavoriteCount']; ?></div>
                <?php if (isUserLoggedIn() && !$votedTodayFor): ?>
                    <form method="POST" action="<?php echo h($self); ?>" data-no-spinner>
                        <input type="hidden" name="action" value="vote_character">
                        <input type="hidden" name="character_id" value="<?php echo (int)$character['ID']; ?>">
                        <button type="submit" class="btn btn-outline btn-xs">♥ Vote</button>
                    </form>
                <?php elseif ((int)$votedTodayFor === (int)$character['ID']): ?>
                    <span class="voted-label">Your vote today</span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- ── Reviews ────────────────────────────────────────── -->
<section class="panel-section" id="reviews">
    <div class="panel-head">
        <h2>Community Reviews</h2>
        <button type="button" class="btn btn-outline btn-sm" onclick="toggleRatingPopup(true)"><?php echo $myRating ? '✏️ Edit my review' : '★ Write a review'; ?></button>
    </div>
    <div class="reviews-grid">
        <?php foreach ($reviews as $row): ?>
            <div class="review-display-card">
                <div class="review-header">
                    <strong class="reviewer-name"><?php echo h($row['UserName']); ?></strong>
                    <span class="review-score"><?php echo number_format((float)$row['Score'], 1); ?> ★</span>
                </div>
                <?php if ($row['ReviewText'] !== null && $row['ReviewText'] !== ''): ?>
                    <p class="review-body"><?php echo nl2br(h($row['ReviewText'])); ?></p>
                <?php endif; ?>
                <small class="muted"><?php echo date('M j, Y', strtotime($row['TIME_STAMP'])); ?></small>
            </div>
        <?php endforeach; ?>
        <?php if (!$reviews): ?>
            <p class="muted">No reviews yet. Be the first to rate!</p>
        <?php endif; ?>
    </div>
</section>

<!-- ── Community preview ──────────────────────────────── -->
<section class="panel-section">
    <div class="panel-head">
        <h2>Community Discussions</h2>
        <a href="community.php?id=<?php echo $comicId; ?>" class="btn btn-outline btn-sm">View All Posts</a>
    </div>
    <div class="preview-posts">
        <?php foreach ($previewPosts as $post): ?>
            <div class="preview-post">
                <div class="preview-post-head">
                    <strong><?php echo h($post['UserName']); ?></strong>
                    <small class="muted"><?php echo date('M j, g:i a', strtotime($post['TimeStamp'])); ?></small>
                </div>
                <div class="post-content"><?php echo renderContent(mb_strimwidth($post['Content'], 0, 200, '…'), $emojiMap); ?></div>
            </div>
        <?php endforeach; ?>
        <?php if (!$previewPosts): ?>
            <p class="muted">No discussions yet. Start the conversation in the community tab!</p>
        <?php endif; ?>
    </div>
</section>

<script>
// ── Modals ──
function toggleRatingPopup(show) {
    document.getElementById('asuraRatingModal').style.display = show ? 'flex' : 'none';
    document.body.style.overflow = show ? 'hidden' : '';
}
function toggleLiveModal(show) {
    const modal = document.getElementById('goLiveModal');
    if (!modal) return;
    modal.style.display = show ? 'flex' : 'none';
    document.body.style.overflow = show ? 'hidden' : '';
}
document.querySelectorAll('.star-rating-row input').forEach(r => r.addEventListener('change', () => {
    document.getElementById('starValue').textContent = r.value + ' / 10';
}));

// ── Prediction poll ──
let selectedOptionId = null;
function selectOption(optionId, el) {
    document.querySelectorAll('.prediction-option').forEach(o => o.classList.remove('option-selected'));
    el.classList.add('option-selected');
    selectedOptionId = optionId;
    document.getElementById('confirmArea').style.display = 'block';
}
function clearSelection() {
    document.querySelectorAll('.prediction-option').forEach(o => o.classList.remove('option-selected'));
    selectedOptionId = null;
    document.getElementById('confirmArea').style.display = 'none';
}
function submitPrediction() {
    if (!selectedOptionId) return;
    const btn = document.getElementById('confirmPredBtn');
    const msgEl = document.getElementById('predMessage');
    btn.disabled = true;
    btn.textContent = 'Submitting...';

    fetch('prediction_submit.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'option_id=' + encodeURIComponent(selectedOptionId)
    })
    .then(r => r.json())
    .then(data => {
        msgEl.style.display = 'block';
        msgEl.textContent = data.message;
        msgEl.className = 'prediction-message ' + (data.success ? 'msg-success' : 'msg-error');
        if (data.success) {
            document.getElementById('confirmArea').style.display = 'none';
            document.querySelectorAll('.prediction-option').forEach(o => {
                o.classList.remove('option-clickable');
                o.removeAttribute('onclick');
            });
            setTimeout(() => location.reload(), 1500);   // show the vote bars
        } else {
            btn.disabled = false;
            btn.textContent = 'Confirm My Prediction';
        }
    })
    .catch(() => {
        msgEl.style.display = 'block';
        msgEl.textContent = 'Network error. Please try again.';
        msgEl.className = 'prediction-message msg-error';
        btn.disabled = false;
        btn.textContent = 'Confirm My Prediction';
    });
}
document.querySelectorAll('.option-clickable').forEach(el => el.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); el.click(); }
}));
</script>

<?php require_once 'footer.php'; ?>
