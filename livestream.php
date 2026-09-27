<?php
// livestream.php — live, upcoming (scheduled) and past streams; optional ?comic_id= filter
$pageTitle = 'Live Streams';
require_once 'db.php';

$filterComicId = (int)($_GET['comic_id'] ?? 0);
$filterComic   = null;
if ($filterComicId) {
    $cs = $pdo->prepare("SELECT ID, Name, HardCopyLink, COALESCE(Title, Name) AS Label FROM COMIC WHERE ID = ?");
    $cs->execute([$filterComicId]);
    $filterComic = $cs->fetch() ?: null;
    if (!$filterComic) $filterComicId = 0;
}

// Status is computed by MySQL (same clock as NOW() used when streams start/end)
$sql = "
    SELECT l.AID, l.VideoURL, l.StartTime, l.EndTime, l.Title,
           a.Name AS ArtistName, c.ID AS ComicID, COALESCE(c.Title, c.Name) AS ComicTitle,
           " . streamStatusSql('l') . " AS StreamStatus
    FROM LIVESTREAM l
    JOIN ARTIST a ON l.AID = a.ID
    JOIN COMIC  c ON l.Comic_ID = c.ID
    " . ($filterComicId ? "WHERE l.Comic_ID = ?" : "") . "
    ORDER BY l.StartTime DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($filterComicId ? [$filterComicId] : []);
$rows = $stmt->fetchAll();

$groups = ['live' => [], 'upcoming' => [], 'past' => []];
foreach ($rows as $r) $groups[$r['StreamStatus']][] = $r;
$groups['upcoming'] = array_reverse($groups['upcoming']);   // soonest first

function streamCard(array $s): void {
    $status  = $s['StreamStatus'];
    $id      = ytId($s['VideoURL']);
    $thumb   = $id ? ytThumb($id) : 'assets/thumbnails/placeholder.webp';
    $isOwner = isArtistLoggedIn() && currentUserId() === (int)$s['AID'];
    $badge   = ['live' => '🔴 LIVE', 'upcoming' => '⏰ UPCOMING', 'past' => '▶ PAST'][$status]; ?>
    <div class="ls-card ls-<?php echo $status; ?>">
        <a href="<?php echo h($s['VideoURL']); ?>" target="_blank" rel="noopener" class="ls-thumb">
            <img src="<?php echo h($thumb); ?>" alt="<?php echo h($s['Title']); ?>" loading="lazy">
            <span class="s-badge b-<?php echo $status; ?>"><?php echo $badge; ?></span>
            <span class="play-ov"><span class="play-btn"></span></span>
        </a>
        <div class="ls-info">
            <a href="<?php echo h($s['VideoURL']); ?>" target="_blank" rel="noopener" class="ls-title"><?php echo h($s['Title']); ?></a>
            <div class="ls-meta">
                <span><?php echo h($s['ArtistName']); ?></span> ·
                <a href="comic.php?id=<?php echo (int)$s['ComicID']; ?>" class="ls-comic"><?php echo h($s['ComicTitle']); ?></a>
                <?php if ($isOwner): ?><span class="owner-pill">OWNER</span><?php endif; ?>
            </div>
            <div class="ls-time">
                <?php if ($status === 'live'): ?>
                    Started <?php echo date('M j · g:i A', strtotime($s['StartTime'])); ?>
                <?php elseif ($status === 'upcoming'): ?>
                    Starts <?php echo date('D, M j · g:i A', strtotime($s['StartTime'])); ?>
                <?php else: ?>
                    Ended <?php echo date('M j, Y · g:i A', strtotime($s['EndTime'])); ?>
                <?php endif; ?>
            </div>
            <?php if ($isOwner && $status !== 'past'): ?>
                <a href="artist_go_live.php?end=<?php echo urlencode($s['VideoURL']); ?>" class="btn btn-danger btn-xs ls-end"
                   onclick="return confirm('<?php echo $status === 'live' ? 'End this stream?' : 'Cancel this scheduled stream?'; ?>')">
                    <?php echo $status === 'live' ? '⏹ End stream' : '✕ Cancel'; ?></a>
            <?php endif; ?>
        </div>
    </div>
<?php }

require_once 'header.php';
?>

<?php if ($filterComic): ?>
    <a href="comic.php?id=<?php echo $filterComicId; ?>" class="back-link">← Back to comic</a>
    <div class="ls-filter-banner">
        <img src="<?php echo h(getThumbnail($filterComic['HardCopyLink'], $filterComic['Name'])); ?>" alt="" class="ls-filter-thumb">
        <div class="ls-filter-info">
            <div class="ls-filter-title"><?php echo h($filterComic['Label']); ?> — Live Streams</div>
            <div class="ls-filter-sub">Showing <?php echo count($rows); ?> stream<?php echo count($rows) === 1 ? '' : 's'; ?> for this comic</div>
        </div>
        <a href="livestream.php" class="btn btn-outline btn-sm">View all streams</a>
    </div>
<?php endif; ?>

<div class="ls-hero">
    <?php if ($groups['live']): ?>
        <span class="pulse-ring"></span>
        <span class="live-badge"><?php echo count($groups['live']); ?> LIVE</span>
    <?php endif; ?>
    <h1><?php echo $filterComic ? h($filterComic['Label']) . ' Streams' : 'Live Streams'; ?></h1>
    <?php if (isArtistLoggedIn()): ?>
        <a href="artist_go_live.php" class="btn btn-outline btn-sm" style="margin-left:auto">🎥 My streams</a>
    <?php endif; ?>
</div>

<div class="ls-label">Live now</div>
<?php if (!$groups['live']): ?>
    <div class="ls-empty">No streams live right now<?php echo $filterComic ? ' for ' . h($filterComic['Label']) : ''; ?> — check back soon!</div>
<?php else: ?>
    <div class="ls-grid"><?php foreach ($groups['live'] as $s) streamCard($s); ?></div>
<?php endif; ?>

<?php if ($groups['upcoming']): ?>
    <div class="ls-label">Upcoming</div>
    <div class="ls-grid"><?php foreach ($groups['upcoming'] as $s) streamCard($s); ?></div>
<?php endif; ?>

<?php if ($groups['past']): ?>
    <div class="ls-label">Past streams</div>
    <div class="ls-grid"><?php foreach ($groups['past'] as $s) streamCard($s); ?></div>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
