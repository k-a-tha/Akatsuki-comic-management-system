<?php
// top-comics.php — weekly Top 5 comics, favourite-character leaderboard,
// genre-based recommendations and the weekly "Hall of Fame" history.
$pageTitle = 'Top Comics';
require_once 'db.php';

$yw   = currentYearWeek();            // e.g. 202639
$year = intdiv($yw, 100);
$week = $yw % 100;

// ── Top 5 comics this week (by weekly views) ──────────
$topComics = $pdo->query("
    SELECT x.*, a.Name AS ArtistName,
           (SELECT GROUP_CONCAT(g.Genre ORDER BY g.Genre SEPARATOR ', ') FROM GENRE g WHERE g.Comic_ID = x.ID) AS Genres
    FROM (" . comicListSql() . ") x
    JOIN ARTIST a ON a.ID = x.AID
    ORDER BY x.WeeklyViews DESC, x.ViewCount DESC
    LIMIT 5
")->fetchAll();

// ── Favourite characters (votes this week, then all-time) ──
$topCharacters = $pdo->query("
    SELECT ch.ID, ch.Name, ch.Comic_ID, COALESCE(c.Title, c.Name) AS ComicTitle,
           COUNT(v.UID) AS TotalFavoriteCount,
           SUM(CASE WHEN YEARWEEK(v.Vote_Date, 1) = YEARWEEK(CURDATE(), 1) THEN 1 ELSE 0 END) AS WeeklyVotes
    FROM CHARACTERS ch
    JOIN COMIC c ON ch.Comic_ID = c.ID
    LEFT JOIN VOTE_FOR v ON v.Character_ID = ch.ID
    GROUP BY ch.ID, ch.Name, ch.Comic_ID, c.Title, c.Name
    ORDER BY WeeklyVotes DESC, TotalFavoriteCount DESC, ch.Name
    LIMIT 10
")->fetchAll();

// ── Save this week's snapshot into the TOP_FAVOURITE_* tables ──
$pdo->beginTransaction();
$pdo->prepare("DELETE FROM TOP_FAVOURITE_COMIC WHERE Year = ? AND Week_Number = ?")->execute([$year, $week]);
$pdo->prepare("DELETE FROM TOP_FAVOURITE_CHARACTER WHERE Year = ? AND Week_Number = ?")->execute([$year, $week]);
$insC = $pdo->prepare("INSERT INTO TOP_FAVOURITE_COMIC (Year, Week_Number, Rank_No, Comic_ID, Weekly_Views) VALUES (?, ?, ?, ?, ?)");
$rank = 0;
foreach ($topComics as $c) {
    if ($c['WeeklyViews'] > 0) $insC->execute([$year, $week, ++$rank, $c['ID'], $c['WeeklyViews']]);
}
$insH = $pdo->prepare("INSERT INTO TOP_FAVOURITE_CHARACTER (Year, Week_Number, Rank_No, Character_ID, Votes) VALUES (?, ?, ?, ?, ?)");
$rank = 0;
foreach (array_slice($topCharacters, 0, 5) as $ch) {
    if ($ch['WeeklyVotes'] > 0) $insH->execute([$year, $week, ++$rank, $ch['ID'], $ch['WeeklyVotes']]);
}
$pdo->commit();

// ── Recommendations by genre (GET ?genre=, else the reader's GenrePref) ──
$allGenres = $pdo->query("SELECT DISTINCT Genre FROM GENRE ORDER BY Genre")->fetchAll(PDO::FETCH_COLUMN);
$recGenre  = trim($_GET['genre'] ?? '');
$fromPref  = false;
if ($recGenre === '' && isUserLoggedIn()) {
    $s = $pdo->prepare("SELECT GenrePref FROM USERS WHERE ID = ?");
    $s->execute([currentUserId()]);
    $recGenre = (string)$s->fetchColumn();
    $fromPref = $recGenre !== '';
}
$recommended = [];
if ($recGenre !== '') {
    $s = $pdo->prepare(comicListSql() . "
        WHERE c.ID IN (SELECT Comic_ID FROM GENRE WHERE Genre = ?)
        ORDER BY AvgRating DESC, c.ViewCount DESC");
    $s->execute([$recGenre]);
    $recommended = $s->fetchAll();
}

// ── Hall of fame: previous weeks' snapshots ──
$history = $pdo->prepare("
    SELECT t.Year, t.Week_Number, t.Rank_No, t.Weekly_Views, c.ID, COALESCE(c.Title, c.Name) AS ComicTitle
    FROM TOP_FAVOURITE_COMIC t JOIN COMIC c ON t.Comic_ID = c.ID
    WHERE (t.Year * 100 + t.Week_Number) < ?
    ORDER BY t.Year DESC, t.Week_Number DESC, t.Rank_No ASC
");
$history->execute([$yw]);
$historyWeeks = [];
foreach ($history->fetchAll() as $row) {
    $key = $row['Year'] . '-W' . str_pad($row['Week_Number'], 2, '0', STR_PAD_LEFT);
    if (count($historyWeeks) >= 4 && !isset($historyWeeks[$key])) break;
    $historyWeeks[$key]['comics'][] = $row;
}
$charHistory = $pdo->prepare("
    SELECT t.Year, t.Week_Number, t.Rank_No, t.Votes, ch.Name
    FROM TOP_FAVOURITE_CHARACTER t JOIN CHARACTERS ch ON t.Character_ID = ch.ID
    WHERE (t.Year * 100 + t.Week_Number) < ?
    ORDER BY t.Year DESC, t.Week_Number DESC, t.Rank_No ASC
");
$charHistory->execute([$yw]);
foreach ($charHistory->fetchAll() as $row) {
    $key = $row['Year'] . '-W' . str_pad($row['Week_Number'], 2, '0', STR_PAD_LEFT);
    if (isset($historyWeeks[$key])) $historyWeeks[$key]['characters'][] = $row;
}

$weekStart = date('M j', strtotime('monday this week'));
$weekEnd   = date('M j', strtotime('sunday this week'));

require_once 'header.php';
?>

<div class="page-header">
    <div>
        <h1 class="section-title">🏆 Top Comics This Week</h1>
        <p class="muted">Week <?php echo $week; ?>, <?php echo $year; ?> · <?php echo $weekStart; ?> – <?php echo $weekEnd; ?> · ranked by page views this week</p>
    </div>
</div>

<div class="rank-list">
    <?php foreach ($topComics as $i => $c):
        $label = $c['Title'] ?: $c['Name'];
        $buy   = hardCopyUrl($c['HardCopyLink']);
        $rc    = ['rank-gold', 'rank-silver', 'rank-bronze'][$i] ?? 'rank-other'; ?>
        <div class="rank-item">
            <span class="rank-num <?php echo $rc; ?>"><?php echo $i + 1; ?></span>
            <a href="comic.php?id=<?php echo (int)$c['ID']; ?>"><img class="rank-thumb" src="<?php echo h(getThumbnail($c['HardCopyLink'], $c['Name'])); ?>" alt="<?php echo h($label); ?>"></a>
            <div class="rank-body">
                <a href="comic.php?id=<?php echo (int)$c['ID']; ?>" class="rank-title"><?php echo h($label); ?></a>
                <div class="muted small">by <?php echo h($c['ArtistName']); ?><?php if ($c['Genres']): ?> · <?php echo h($c['Genres']); ?><?php endif; ?></div>
                <div class="rank-meta">
                    <span class="stars"><?php echo renderStars($c['AvgRating']); ?></span>
                    <span><?php echo number_format((float)$c['AvgRating'], 1); ?></span>
                    <?php if ($buy): ?>
                        <a href="<?php echo h($buy); ?>" target="_blank" rel="noopener noreferrer" class="amber-badge">🛒 Buy Hard Copy</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="rank-views">
                <strong><?php echo (int)$c['WeeklyViews']; ?></strong>
                <span>views this week</span>
                <small class="muted"><?php echo number_format((int)$c['ViewCount']); ?> total</small>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$topComics): ?><p class="empty-note">No comics yet.</p><?php endif; ?>
</div>

<section class="panel-section" id="characters">
    <div class="panel-head">
        <h2>❤ Top Favourite Characters</h2>
        <span class="muted small">Vote on any comic page · 1 vote per comic per day</span>
    </div>
    <div class="char-board">
        <?php foreach ($topCharacters as $i => $ch):
            $avatar = 'https://ui-avatars.com/api/?name=' . urlencode($ch['Name']) . '&background=764ba2&color=fff&size=128&rounded=true&bold=true'; ?>
            <a href="comic.php?id=<?php echo (int)$ch['Comic_ID']; ?>#characters" class="char-card">
                <span class="char-rank <?php echo ['rank-gold', 'rank-silver', 'rank-bronze'][$i] ?? 'rank-other'; ?>">#<?php echo $i + 1; ?></span>
                <span class="char-avatar-wrap">
                    <span class="char-avatar-fallback"><?php echo h(mb_substr($ch['Name'], 0, 1)); ?></span>
                    <img src="<?php echo h($avatar); ?>" alt="<?php echo h($ch['Name']); ?>" class="char-avatar" onerror="this.style.display='none'">
                </span>
                <strong><?php echo h($ch['Name']); ?></strong>
                <span class="muted small"><?php echo h($ch['ComicTitle']); ?></span>
                <span class="char-votes">❤ <?php echo (int)$ch['WeeklyVotes']; ?> this week · <?php echo (int)$ch['TotalFavoriteCount']; ?> total</span>
            </a>
        <?php endforeach; ?>
        <?php if (!$topCharacters): ?><p class="empty-note">No characters yet.</p><?php endif; ?>
    </div>
</section>

<section class="panel-section" id="recommended">
    <div class="panel-head">
        <h2>🎯 Recommended For You</h2>
        <?php if ($fromPref): ?>
            <span class="muted small">Based on your favourite genre · <a href="profile.php">change</a></span>
        <?php elseif (!isUserLoggedIn()): ?>
            <span class="muted small"><a href="login_user.php">Log in</a> to get picks from your favourite genre automatically</span>
        <?php endif; ?>
    </div>
    <div class="filter-bar">
        <?php foreach ($allGenres as $g): ?>
            <a href="top-comics.php?genre=<?php echo urlencode($g); ?>#recommended" class="filter-chip <?php echo strcasecmp($g, $recGenre) === 0 ? 'active' : ''; ?>"><?php echo h($g); ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($recGenre === ''): ?>
        <p class="muted" style="margin-top:16px">Pick a genre above to see recommendations.</p>
    <?php elseif (!$recommended): ?>
        <p class="muted" style="margin-top:16px">No comics in <?php echo h($recGenre); ?> yet.</p>
    <?php else: ?>
        <div class="rec-list">
            <?php foreach ($recommended as $c):
                $label = $c['Title'] ?: $c['Name'];
                $buy   = hardCopyUrl($c['HardCopyLink']); ?>
                <div class="rec-item">
                    <a href="comic.php?id=<?php echo (int)$c['ID']; ?>"><img src="<?php echo h(getThumbnail($c['HardCopyLink'], $c['Name'])); ?>" alt="<?php echo h($label); ?>"></a>
                    <div>
                        <a href="comic.php?id=<?php echo (int)$c['ID']; ?>" class="rank-title"><?php echo h($label); ?></a>
                        <div class="rank-meta">
                            <span class="stars"><?php echo renderStars($c['AvgRating']); ?></span>
                            <span><?php echo number_format((float)$c['AvgRating'], 1); ?> (<?php echo (int)$c['RatingCount']; ?>)</span>
                        </div>
                        <p class="muted small"><?php echo h(mb_strimwidth((string)$c['Synopsis'], 0, 140, '…')); ?></p>
                        <?php if ($buy): ?><a href="<?php echo h($buy); ?>" target="_blank" rel="noopener noreferrer" class="amber-badge">🛒 Buy Hard Copy</a><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($historyWeeks): ?>
<section class="panel-section">
    <div class="panel-head"><h2>📜 Hall of Fame — previous weeks</h2></div>
    <div class="history-grid">
        <?php foreach ($historyWeeks as $key => $wk): ?>
            <div class="history-card">
                <h4><?php echo h(str_replace('-W', ' · Week ', $key)); ?></h4>
                <?php foreach ($wk['comics'] ?? [] as $row): ?>
                    <div class="history-row"><span class="history-rank">#<?php echo (int)$row['Rank_No']; ?></span>
                        <a href="comic.php?id=<?php echo (int)$row['ID']; ?>"><?php echo h($row['ComicTitle']); ?></a>
                        <span class="muted small"><?php echo (int)$row['Weekly_Views']; ?> views</span></div>
                <?php endforeach; ?>
                <?php if (!empty($wk['characters'])): ?>
                    <div class="history-sub">Top characters</div>
                    <?php foreach ($wk['characters'] as $row): ?>
                        <div class="history-row"><span class="history-rank">#<?php echo (int)$row['Rank_No']; ?></span>
                            <?php echo h($row['Name']); ?> <span class="muted small">❤ <?php echo (int)$row['Votes']; ?></span></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
