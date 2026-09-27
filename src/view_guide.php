<?php
// view_guide.php — anyone can read an artist's reading guide
require_once 'db.php';

$comicId = (int)($_GET['comic_id'] ?? 0);
if (!$comicId) redirect('index.php');

$stmt = $pdo->prepare("
    SELECT c.ID, c.Name, c.Title, c.PDF, c.HardCopyLink, c.AID, a.Name AS ArtistName
    FROM COMIC c JOIN ARTIST a ON c.AID = a.ID
    WHERE c.ID = ?
");
$stmt->execute([$comicId]);
$comic = $stmt->fetch();
if (!$comic) redirect('comics.php');

$comicLabel = $comic['Title'] ?: $comic['Name'];
$guideFile  = __DIR__ . "/assets/guides/comic-{$comicId}.html";

if (empty($comic['PDF']) || !is_file($guideFile)) redirect("comic.php?id={$comicId}&no_guide=1");

// The guide file stores its content as JSON inside <script id="guide-data">
$data = null;
if (preg_match('/<script type="application\/json" id="guide-data">(.*?)<\/script>/s', file_get_contents($guideFile), $m)) {
    $data = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
}
if (!is_array($data)) redirect("comic.php?id={$comicId}&no_guide=1");

$data = array_merge(['guide_title' => '', 'overview' => '', 'sections' => [], 'tips' => '', 'final_notes' => ''], $data);
$sections = array_values(array_filter((array)$data['sections'], fn($s) => ($s['heading'] ?? '') !== '' || ($s['body'] ?? '') !== ''));

$isOwner   = isArtistLoggedIn() && currentUserId() === (int)$comic['AID'];
$pageTitle = ($data['guide_title'] ?: $comicLabel) . ' — Guide';

// Table of contents: only parts that have content get an entry + anchor
$toc = [];
if ($data['overview'] !== '')    $toc['overview'] = 'Overview';
foreach ($sections as $i => $s)  if (($s['heading'] ?? '') !== '') $toc["section-$i"] = $s['heading'];
if ($data['tips'] !== '')        $toc['tips'] = 'Tips & Tricks';
if ($data['final_notes'] !== '') $toc['final-notes'] = 'Final Notes';

require_once 'header.php';
?>

<div class="narrow-wrap">
    <a href="comic.php?id=<?php echo $comicId; ?>" class="back-link">← Back to Comic</a>

    <div class="gv-hero card">
        <img src="<?php echo h(getThumbnail($comic['HardCopyLink'], $comic['Name'])); ?>" alt="" class="gv-cover">
        <div class="gv-hero-info">
            <div class="eyebrow accent">📖 Reading Guide</div>
            <h1 class="gv-main-title"><?php echo h($data['guide_title'] ?: $comicLabel); ?></h1>
            <div class="muted">For <strong><?php echo h($comicLabel); ?></strong> · by <strong><?php echo h($comic['ArtistName']); ?></strong></div>
            <?php if ($isOwner): ?>
                <a href="create_guide.php?comic_id=<?php echo $comicId; ?>" class="btn btn-outline btn-sm" style="margin-top:14px">✏️ Edit Guide</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (count($toc) > 1): ?>
        <div class="card gv-toc">
            <div class="eyebrow">Contents</div>
            <ol>
                <?php foreach ($toc as $anchor => $label): ?>
                    <li><a href="#<?php echo $anchor; ?>"><?php echo h($label); ?></a></li>
                <?php endforeach; ?>
            </ol>
        </div>
    <?php endif; ?>

    <?php if ($data['overview'] !== ''): ?>
        <div class="card" id="overview">
            <h2 class="gv-sec-heading">Overview</h2>
            <div class="gv-sec-body"><?php echo nl2br(h($data['overview'])); ?></div>
        </div>
    <?php endif; ?>

    <?php if ($sections): ?>
        <div class="card">
            <?php foreach ($sections as $i => $s): ?>
                <div class="gv-section" id="section-<?php echo $i; ?>">
                    <?php if (($s['heading'] ?? '') !== ''): ?><h2 class="gv-sec-heading"><?php echo h($s['heading']); ?></h2><?php endif; ?>
                    <?php if (($s['body'] ?? '') !== ''): ?><div class="gv-sec-body"><?php echo nl2br(h($s['body'])); ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($data['tips'] !== ''): ?>
        <div class="card gv-tips-block" id="tips">
            <h2 class="gv-sec-heading">💡 Tips &amp; Tricks</h2>
            <ul class="gv-tips-list">
                <?php foreach (array_filter(array_map('trim', explode("\n", $data['tips']))) as $tip): ?>
                    <li><?php echo h($tip); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($data['final_notes'] !== ''): ?>
        <div class="card gv-final" id="final-notes">
            <h2 class="gv-sec-heading">📝 Final Notes</h2>
            <div class="gv-sec-body"><?php echo nl2br(h($data['final_notes'])); ?></div>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
