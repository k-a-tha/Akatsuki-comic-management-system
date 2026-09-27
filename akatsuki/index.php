<?php
// index.php — home page
$pageTitle = 'Home';
require_once 'db.php';

// ── You May Like (genre preference of the logged-in reader, else top rated) ──
$prefGenre = null;
$userPref  = null;
if (isUserLoggedIn()) {
    $s = $pdo->prepare("SELECT GenrePref FROM USERS WHERE ID = ?");
    $s->execute([currentUserId()]);
    $prefGenre = $userPref = $s->fetchColumn() ?: null;
}
if ($prefGenre) {
    $s = $pdo->prepare(comicListSql() . "
        WHERE c.ID IN (SELECT Comic_ID FROM GENRE WHERE Genre = ?)
        ORDER BY AvgRating DESC, c.ViewCount DESC LIMIT 8");
    $s->execute([$prefGenre]);
    $youMayLike = $s->fetchAll();
    if (!$youMayLike) $prefGenre = null;   // nothing in that genre yet → show top rated
}
if (!$prefGenre) {
    $youMayLike = $pdo->query(comicListSql() . " ORDER BY AvgRating DESC, c.ViewCount DESC LIMIT 8")->fetchAll();
}

// ── Featured = newest comics ──
$featuredComics = $pdo->query(comicListSql() . " ORDER BY c.ID DESC LIMIT 8")->fetchAll();

// ── Weekly top 5 (side column) + "HOT" badge for the top 3 ──
$top5Comics = $pdo->query(comicListSql() . " ORDER BY WeeklyViews DESC, c.ViewCount DESC LIMIT 5")->fetchAll();
$hotIds = array_map(fn($c) => (int)$c['ID'], array_slice(array_filter($top5Comics, fn($c) => $c['WeeklyViews'] > 0), 0, 3));

// ── Latest updates = comics ordered by their newest published chapter ──
$latestComics = $pdo->query("
    SELECT x.*, lu.LastUpdate
    FROM (" . comicListSql() . ") x
    JOIN (SELECT Comic_ID, MAX(Date_of_Publication) AS LastUpdate
          FROM CHAPTER WHERE isPublished = TRUE GROUP BY Comic_ID) lu ON lu.Comic_ID = x.ID
    ORDER BY lu.LastUpdate DESC, x.ID DESC
    LIMIT 12
")->fetchAll();

// ── Streams live right now ──
$liveNow = $pdo->query("
    SELECT l.VideoURL, l.Title, a.Name AS ArtistName, COALESCE(c.Title, c.Name) AS ComicTitle
    FROM LIVESTREAM l JOIN ARTIST a ON l.AID = a.ID JOIN COMIC c ON l.Comic_ID = c.ID
    WHERE l.StartTime <= NOW() AND (l.EndTime IS NULL OR l.EndTime > NOW())
    ORDER BY l.StartTime DESC LIMIT 3
")->fetchAll();

require_once 'header.php';
?>

<div class="homepage-grid">
    <div class="main-column">

        <!-- You May Like -->
        <section class="you-may-like">
            <div class="section-header">
                <h2 class="section-title">You May Like</h2>
                <span class="section-sub">
                    <?php if ($prefGenre): ?>
                        Because you love <strong><?php echo h($prefGenre); ?></strong> ·
                        <a href="top-comics.php#recommended">more</a>
                    <?php elseif ($userPref): ?>
                        No <?php echo h($userPref); ?> comics yet — here are the top rated picks · <a href="profile.php">change genre</a>
                    <?php elseif (isUserLoggedIn()): ?>
                        Top rated picks · <a href="profile.php">set your favorite genre</a>
                    <?php else: ?>
                        Top rated picks · <a href="register_user.php">sign up</a> for personal picks
                    <?php endif; ?>
                </span>
            </div>
            <div class="like-strip">
                <?php foreach ($youMayLike as $c):
                    $label = $c['Title'] ?: $c['Name'];
                    $buy   = hardCopyUrl($c['HardCopyLink']); ?>
                    <div class="like-item">
                        <a href="comic.php?id=<?php echo (int)$c['ID']; ?>" class="like-thumb">
                            <img src="<?php echo h(getThumbnail($c['HardCopyLink'], $c['Name'])); ?>" alt="<?php echo h($label); ?>" loading="lazy">
                        </a>
                        <a href="comic.php?id=<?php echo (int)$c['ID']; ?>" class="like-title"><?php echo h($label); ?></a>
                        <div class="like-meta">★ <?php echo number_format((float)$c['AvgRating'], 1); ?>
                            <?php if ($buy): ?>
                                <a href="<?php echo h($buy); ?>" target="_blank" rel="noopener noreferrer" class="amber-badge" title="Buy hard copy">🛒 Buy</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($youMayLike)): ?>
                    <p class="muted">No comics yet.</p>
                <?php endif; ?>
            </div>
        </section>

        <!-- Featured Comics -->
        <section class="featured-section">
            <div class="section-header">
                <h2 class="section-title">Featured Comics</h2>
                <a href="comics.php" class="section-link">View all →</a>
            </div>
            <div class="featured-grid">
                <?php foreach ($featuredComics as $comic): ?>
                    <?php echo comicCard($pdo, $comic, ['hot' => in_array((int)$comic['ID'], $hotIds, true)]); ?>
                <?php endforeach; ?>
                <?php if (empty($featuredComics)): ?>
                    <p class="muted" style="grid-column:1/-1;text-align:center;">No comics available yet. Check back soon!</p>
                <?php endif; ?>
            </div>
        </section>

        <!-- Latest Updates -->
        <section class="updates-section">
            <div class="section-header">
                <span class="section-badge">LATEST MANGA UPDATES</span>
            </div>
            <div class="updates-grid">
                <?php foreach ($latestComics as $comic): ?>
                    <?php echo comicCard($pdo, $comic); ?>
                <?php endforeach; ?>
                <?php if (empty($latestComics)): ?>
                    <p class="muted">No chapters published yet.</p>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- Side column -->
    <aside class="side-column">
        <?php if ($liveNow): ?>
        <div class="side-card live-side">
            <div class="top-strip-label"><span class="live-nav-dot"></span> Live Now</div>
            <?php foreach ($liveNow as $ls): ?>
                <a href="<?php echo h($ls['VideoURL']); ?>" target="_blank" rel="noopener" class="live-side-item">
                    <strong><?php echo h($ls['Title']); ?></strong>
                    <span><?php echo h($ls['ArtistName']); ?> · <?php echo h($ls['ComicTitle']); ?></span>
                </a>
            <?php endforeach; ?>
            <a href="livestream.php" class="side-more">All streams →</a>
        </div>
        <?php endif; ?>

        <div class="side-card top-strip-wrap">
            <div class="top-strip-label">✨ Top Comics This Week</div>
            <?php foreach ($top5Comics as $i => $tc):
                $label     = $tc['Title'] ?: $tc['Name'];
                $rankClass = ['rank-gold', 'rank-silver', 'rank-bronze'][$i] ?? 'rank-other'; ?>
                <a href="comic.php?id=<?php echo (int)$tc['ID']; ?>" class="top-strip-item">
                    <span class="top-strip-rank <?php echo $rankClass; ?>"><?php echo $i + 1; ?></span>
                    <img class="top-strip-thumb" src="<?php echo h(getThumbnail($tc['HardCopyLink'], $tc['Name'])); ?>" alt="<?php echo h($label); ?>">
                    <div class="top-strip-info">
                        <div class="top-strip-title"><?php echo h($label); ?></div>
                        <div class="top-strip-sub">
                            👁 <?php echo (int)$tc['WeeklyViews']; ?> this week
                            <?php if ($tc['RatingCount'] > 0): ?> · ★ <?php echo number_format((float)$tc['AvgRating'], 1); ?><?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
            <?php if (empty($top5Comics)): ?>
                <p class="muted" style="text-align:center;padding:16px 0;">No comics yet.</p>
            <?php endif; ?>
            <a href="top-comics.php" class="side-more">Full rankings →</a>
        </div>
    </aside>
</div>

<?php require_once 'footer.php'; ?>
