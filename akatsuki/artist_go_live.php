<?php
// artist_go_live.php — artists start a stream now, schedule one, and manage their streams
$pageTitle = 'Go Live';
require_once 'db.php';
requireArtist();
$artistId = currentUserId();

/** Returns an error message, or null when the URL can be used by this artist. */
function checkStreamUrl(PDO $pdo, string $url, int $artistId): ?string {
    if (!isExternalUrl($url)) return 'Please paste a valid link that starts with https://';
    $s = $pdo->prepare("SELECT AID FROM LIVESTREAM WHERE VideoURL = ?");
    $s->execute([$url]);
    $owner = $s->fetchColumn();
    if ($owner !== false && (int)$owner !== $artistId) return 'That stream link is already used by another artist.';
    return null;
}

// ── GO LIVE NOW ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'go_live') {
    $url     = mb_substr(trim($_POST['video_url'] ?? ''), 0, 500);
    $title   = mb_substr(trim($_POST['title'] ?? ''), 0, 200);
    $comicId = (int)($_POST['comic_id'] ?? 0);

    if ($title === '' || !artistOwnsComic($pdo, $comicId, $artistId)) {
        setFlash('Please give the stream a title and pick one of your comics.', 'error');
        redirect('artist_go_live.php');
    }
    if ($err = checkStreamUrl($pdo, $url, $artistId)) {
        setFlash($err, 'error');
        redirect('artist_go_live.php');
    }
    $pdo->prepare("
        INSERT INTO LIVESTREAM (VideoURL, Title, StartTime, EndTime, AID, Comic_ID)
        VALUES (?, ?, NOW(), NULL, ?, ?)
        ON DUPLICATE KEY UPDATE Title = VALUES(Title), StartTime = NOW(), EndTime = NULL, Comic_ID = VALUES(Comic_ID)
    ")->execute([$url, $title, $artistId, $comicId]);
    setFlash("🔴 You're live! Your stream is now visible to viewers.");
    redirect('artist_go_live.php?tab=streams');
}

// ── SCHEDULE A FUTURE STREAM ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'schedule') {
    $url     = mb_substr(trim($_POST['video_url'] ?? ''), 0, 500);
    $title   = mb_substr(trim($_POST['title'] ?? ''), 0, 200);
    $comicId = (int)($_POST['comic_id'] ?? 0);
    $start   = DateTime::createFromFormat('Y-m-d\TH:i', $_POST['start_time'] ?? '');

    if ($title === '' || !artistOwnsComic($pdo, $comicId, $artistId)) {
        setFlash('Please give the stream a title and pick one of your comics.', 'error');
    } elseif (!$start || $start <= new DateTime()) {
        setFlash('Please choose a start date and time in the future.', 'error');
    } elseif ($err = checkStreamUrl($pdo, $url, $artistId)) {
        setFlash($err, 'error');
    } else {
        $pdo->prepare("
            INSERT INTO LIVESTREAM (VideoURL, Title, StartTime, EndTime, AID, Comic_ID)
            VALUES (?, ?, ?, NULL, ?, ?)
            ON DUPLICATE KEY UPDATE Title = VALUES(Title), StartTime = VALUES(StartTime), EndTime = NULL, Comic_ID = VALUES(Comic_ID)
        ")->execute([$url, $title, $start->format('Y-m-d H:i:00'), $artistId, $comicId]);
        setFlash('📅 Stream scheduled for ' . $start->format('D, M j · g:i A') . '. It will show as Live automatically at that time.');
        redirect('artist_go_live.php?tab=streams');
    }
    redirect('artist_go_live.php?tab=schedule');
}

// ── START A SCHEDULED STREAM EARLY ─────────────────────
if (isset($_GET['start'])) {
    $pdo->prepare("UPDATE LIVESTREAM SET StartTime = NOW(), EndTime = NULL WHERE VideoURL = ? AND AID = ? AND StartTime > NOW()")
        ->execute([$_GET['start'], $artistId]);
    setFlash("🔴 You're live!");
    redirect('artist_go_live.php?tab=streams');
}

// ── END (live) / CANCEL (upcoming) ────────────────────
if (isset($_GET['end'])) {
    $s = $pdo->prepare("SELECT " . streamStatusSql('l') . " FROM LIVESTREAM l WHERE VideoURL = ? AND AID = ?");
    $s->execute([$_GET['end'], $artistId]);
    $status = $s->fetchColumn();
    if ($status === 'live') {
        $pdo->prepare("UPDATE LIVESTREAM SET EndTime = NOW() WHERE VideoURL = ? AND AID = ?")->execute([$_GET['end'], $artistId]);
        setFlash('⏹ Stream ended. It now appears under Past streams.');
    } elseif ($status === 'upcoming') {
        $pdo->prepare("DELETE FROM LIVESTREAM WHERE VideoURL = ? AND AID = ?")->execute([$_GET['end'], $artistId]);
        setFlash('Scheduled stream cancelled.');
    }
    redirect('artist_go_live.php?tab=streams');
}

// ── DELETE ────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $pdo->prepare("DELETE FROM LIVESTREAM WHERE VideoURL = ? AND AID = ?")->execute([$_GET['delete'], $artistId]);
    setFlash('Stream removed.');
    redirect('artist_go_live.php?tab=streams');
}

// ── Page data ─────────────────────────────────────────
$myComics = $pdo->prepare("SELECT ID, COALESCE(Title, Name) AS Label FROM COMIC WHERE AID = ? ORDER BY Label");
$myComics->execute([$artistId]);
$myComics = $myComics->fetchAll();

$stmt = $pdo->prepare("
    SELECT l.VideoURL, l.Title, l.StartTime, l.EndTime, COALESCE(c.Title, c.Name) AS ComicTitle,
           " . streamStatusSql('l') . " AS StreamStatus
    FROM LIVESTREAM l JOIN COMIC c ON l.Comic_ID = c.ID
    WHERE l.AID = ?
    ORDER BY l.StartTime DESC
");
$stmt->execute([$artistId]);
$myStreams = $stmt->fetchAll();
// Live first, then upcoming, then past (newest first inside each group)
$order = ['live' => 0, 'upcoming' => 1, 'past' => 2];
usort($myStreams, fn($a, $b) => $order[$a['StreamStatus']] <=> $order[$b['StreamStatus']] ?: strcmp($b['StartTime'], $a['StartTime']));

$tab = in_array($_GET['tab'] ?? '', ['now', 'schedule', 'streams'], true) ? $_GET['tab'] : 'now';
$minStart = date('Y-m-d\TH:i', strtotime('+5 minutes'));

require_once 'header.php';

function comicSelect(array $comics): void { ?>
    <select name="comic_id" class="form-control" required>
        <option value="">— Select one of your comics —</option>
        <?php foreach ($comics as $c): ?>
            <option value="<?php echo (int)$c['ID']; ?>"><?php echo h($c['Label']); ?></option>
        <?php endforeach; ?>
    </select>
<?php }
?>

<div class="narrow-wrap">
    <h1 class="page-title">🎥 Go Live</h1>

    <div class="tabs">
        <button type="button" class="tab <?php echo $tab === 'now' ? 'active' : ''; ?>" data-tab="now">🔴 Go Live Now</button>
        <button type="button" class="tab <?php echo $tab === 'schedule' ? 'active' : ''; ?>" data-tab="schedule">📅 Schedule</button>
        <button type="button" class="tab <?php echo $tab === 'streams' ? 'active' : ''; ?>" data-tab="streams">
            My Streams <?php if ($myStreams): ?><span class="tab-count"><?php echo count($myStreams); ?></span><?php endif; ?>
        </button>
    </div>

    <?php if (!$myComics): ?>
        <div class="alert alert-info alert-sticky">You don't have any comics yet. <a href="admin_panel.php?tab=comics">Create a comic</a> first — every stream is linked to one of your comics.</div>
    <?php endif; ?>

    <!-- GO LIVE NOW -->
    <div class="tab-panel <?php echo $tab === 'now' ? 'active' : ''; ?>" id="tab-now">
        <div class="card">
            <h3 class="card-title">Start a stream right now</h3>
            <p class="muted">Start your YouTube stream first, then paste the link below. The stream goes live on the site immediately.</p>
            <form method="POST" action="artist_go_live.php">
                <input type="hidden" name="action" value="go_live">
                <div class="form-group">
                    <label>Stream Title</label>
                    <input type="text" name="title" class="form-control" maxlength="200" placeholder="e.g. Drawing Chapter 12 Live!" required>
                </div>
                <div class="form-group">
                    <label>YouTube Live URL</label>
                    <input type="url" name="video_url" class="form-control" maxlength="500" placeholder="https://youtube.com/live/..." required>
                    <small class="form-hint">Copy from YouTube Studio → Go Live.</small>
                </div>
                <div class="form-group">
                    <label>Related Comic</label>
                    <?php comicSelect($myComics); ?>
                </div>
                <button type="submit" class="btn btn-live-header btn-full">🔴 GO LIVE NOW</button>
            </form>
        </div>
    </div>

    <!-- SCHEDULE -->
    <div class="tab-panel <?php echo $tab === 'schedule' ? 'active' : ''; ?>" id="tab-schedule">
        <div class="card">
            <h3 class="card-title">Schedule a future stream</h3>
            <p class="muted">Pick a future date and time. Viewers see it under "Upcoming", and it switches to Live automatically at that time.</p>
            <form method="POST" action="artist_go_live.php">
                <input type="hidden" name="action" value="schedule">
                <div class="form-group">
                    <label>Stream Title</label>
                    <input type="text" name="title" class="form-control" maxlength="200" placeholder="e.g. Chapter 13 Speed-draw" required>
                </div>
                <div class="form-group">
                    <label>YouTube URL</label>
                    <input type="url" name="video_url" class="form-control" maxlength="500" placeholder="https://youtube.com/live/..." required>
                    <small class="form-hint">In YouTube Studio use "Schedule stream" to get the link in advance.</small>
                </div>
                <div class="form-group">
                    <label>Related Comic</label>
                    <?php comicSelect($myComics); ?>
                </div>
                <div class="form-group">
                    <label>Start Date &amp; Time</label>
                    <input type="datetime-local" name="start_time" class="form-control" min="<?php echo $minStart; ?>" value="<?php echo $minStart; ?>" required>
                </div>
                <button type="submit" class="btn btn-primary btn-full">📅 Schedule Stream</button>
            </form>
        </div>
    </div>

    <!-- MY STREAMS -->
    <div class="tab-panel <?php echo $tab === 'streams' ? 'active' : ''; ?>" id="tab-streams">
        <div class="card">
            <h3 class="card-title">Your streams</h3>
            <?php if (!$myStreams): ?>
                <p class="empty-note">No streams yet — go live!</p>
            <?php endif; ?>
            <?php foreach ($myStreams as $s):
                $st  = $s['StreamStatus'];
                $id  = ytId($s['VideoURL']);
                $th  = $id ? "https://img.youtube.com/vi/{$id}/mqdefault.jpg" : 'assets/thumbnails/placeholder.webp';
                $pill = ['live' => '🔴 LIVE', 'upcoming' => '⏰ Upcoming', 'past' => 'Past'][$st]; ?>
                <div class="stream-row">
                    <img src="<?php echo h($th); ?>" class="sr-thumb" alt="">
                    <div class="sr-body">
                        <div class="sr-title"><?php echo h($s['Title']); ?></div>
                        <div class="sr-meta">
                            <span class="pill p-<?php echo $st; ?>"><?php echo $pill; ?></span>
                            <?php echo h($s['ComicTitle']); ?> ·
                            <?php echo $st === 'upcoming' ? 'starts ' : ''; ?><?php echo date('M j, Y · g:i A', strtotime($s['StartTime'])); ?>
                        </div>
                    </div>
                    <div class="sr-actions">
                        <?php if ($st === 'live'): ?>
                            <a href="artist_go_live.php?end=<?php echo urlencode($s['VideoURL']); ?>" class="btn btn-danger btn-xs" onclick="return confirm('End this stream?')">⏹ End</a>
                        <?php elseif ($st === 'upcoming'): ?>
                            <a href="artist_go_live.php?start=<?php echo urlencode($s['VideoURL']); ?>" class="btn btn-live-header btn-xs" onclick="return confirm('Go live now?')">Start now</a>
                            <a href="artist_go_live.php?end=<?php echo urlencode($s['VideoURL']); ?>" class="btn btn-outline btn-xs" onclick="return confirm('Cancel this scheduled stream?')">Cancel</a>
                        <?php endif; ?>
                        <a href="<?php echo h($s['VideoURL']); ?>" target="_blank" rel="noopener" class="btn btn-outline btn-xs" title="Open on YouTube">▶</a>
                        <?php if ($st === 'past'): ?>
                            <a href="artist_go_live.php?delete=<?php echo urlencode($s['VideoURL']); ?>" class="btn btn-outline btn-xs" title="Delete" onclick="return confirm('Delete this stream from the site?')">🗑</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.tabs .tab').forEach(btn => btn.addEventListener('click', () => {
    document.querySelectorAll('.tabs .tab').forEach(b => b.classList.toggle('active', b === btn));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === 'tab-' + btn.dataset.tab));
    history.replaceState(null, '', 'artist_go_live.php?tab=' + btn.dataset.tab);
}));
</script>

<?php require_once 'footer.php'; ?>
