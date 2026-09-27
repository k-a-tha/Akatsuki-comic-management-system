<?php
// reader.php — read a published chapter page by page
require_once 'db.php';

$chapterId = (int)($_GET['id'] ?? 0);
if (!$chapterId) redirect('index.php');

$stmt = $pdo->prepare("
    SELECT ch.CHAPTER_ID, ch.ChapterNumber, ch.Date_of_Publication, ch.ContentURL, ch.Comic_ID, ch.isPublished,
           c.Name AS ComicName, c.Title AS ComicTitle, c.AID
    FROM CHAPTER ch JOIN COMIC c ON ch.Comic_ID = c.ID
    WHERE ch.CHAPTER_ID = ?
");
$stmt->execute([$chapterId]);
$chapter = $stmt->fetch();
if (!$chapter) redirect('index.php');

// Drafts can only be previewed by the artist who owns the comic
$isPublished    = (bool)$chapter['isPublished'];
$isOwnerPreview = isArtistLoggedIn() && (int)$chapter['AID'] === currentUserId();
if (!$isPublished && !$isOwnerPreview) redirect('index.php');

$comicTitle = $chapter['ComicTitle'] ?: $chapter['ComicName'];
$pageTitle  = $comicTitle . ' - Chapter ' . $chapter['ChapterNumber'];

$stmt = $pdo->prepare("
    SELECT CHAPTER_ID, ChapterNumber FROM CHAPTER
    WHERE Comic_ID = ? AND isPublished = TRUE ORDER BY ChapterNumber ASC
");
$stmt->execute([$chapter['Comic_ID']]);
$allChapters = $stmt->fetchAll();

$prevChapter = $nextChapter = null;
foreach ($allChapters as $i => $ch) {
    if ((int)$ch['CHAPTER_ID'] === $chapterId) {
        $prevChapter = $allChapters[$i - 1] ?? null;
        $nextChapter = $allChapters[$i + 1] ?? null;
        break;
    }
}

// Page images inside the ContentURL folder, sorted naturally (page-2 before page-10)
$contentPath = trim((string)$chapter['ContentURL'], '/');
$images      = listImages($contentPath);

require_once 'header.php';

function readerNav(?array $prev, ?array $next, array $all, int $current, int $comicId, bool $withSelect): void { ?>
    <div class="reader-nav">
        <?php if ($prev): ?>
            <a href="reader.php?id=<?php echo (int)$prev['CHAPTER_ID']; ?>" class="btn btn-outline" data-nav="prev">← Previous</a>
        <?php else: ?>
            <span class="btn btn-outline is-disabled">← Previous</span>
        <?php endif; ?>

        <?php if ($withSelect && $all): ?>
            <select class="chapter-select" onchange="window.location.href='reader.php?id='+this.value" aria-label="Jump to chapter">
                <?php foreach ($all as $ch): ?>
                    <option value="<?php echo (int)$ch['CHAPTER_ID']; ?>" <?php echo (int)$ch['CHAPTER_ID'] === $current ? 'selected' : ''; ?>>
                        Chapter <?php echo (int)$ch['ChapterNumber']; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php else: ?>
            <a href="comic.php?id=<?php echo $comicId; ?>" class="btn btn-primary">Back to Comic</a>
        <?php endif; ?>

        <?php if ($next): ?>
            <a href="reader.php?id=<?php echo (int)$next['CHAPTER_ID']; ?>" class="btn btn-outline" data-nav="next">Next →</a>
        <?php else: ?>
            <span class="btn btn-outline is-disabled">Next →</span>
        <?php endif; ?>
    </div>
<?php }
?>

<div class="reader-container">
    <?php if (!$isPublished): ?>
        <div class="alert alert-info alert-sticky">Draft preview — only you can see this chapter until you publish it.</div>
    <?php endif; ?>

    <div class="reader-header">
        <div class="reader-info">
            <h1><a href="comic.php?id=<?php echo (int)$chapter['Comic_ID']; ?>"><?php echo h($comicTitle); ?></a></h1>
            <span>Chapter <?php echo (int)$chapter['ChapterNumber']; ?>
                <?php if ($chapter['Date_of_Publication']): ?> · <?php echo date('M j, Y', strtotime($chapter['Date_of_Publication'])); ?><?php endif; ?>
            </span>
        </div>
        <?php readerNav($prevChapter, $nextChapter, $allChapters, $chapterId, (int)$chapter['Comic_ID'], true); ?>
    </div>

    <div class="reader-images">
        <?php foreach ($images as $n => $image): ?>
            <img src="<?php echo h($image); ?>" alt="Page <?php echo $n + 1; ?>" loading="lazy" data-no-fallback>
        <?php endforeach; ?>
        <?php if (!$images): ?>
            <div class="reader-empty">
                <h2>Chapter content not available</h2>
                <p>The page images for this chapter have not been uploaded yet.<br>
                   Expected folder: <code><?php echo h($contentPath ?: 'assets/chapters/<comic>/chapter-' . $chapter['ChapterNumber']); ?></code></p>
            </div>
        <?php endif; ?>
    </div>

    <div class="reader-header reader-footer-nav">
        <span class="muted">Tip: use ← and → keys to switch chapters</span>
        <?php readerNav($prevChapter, $nextChapter, $allChapters, $chapterId, (int)$chapter['Comic_ID'], false); ?>
    </div>
</div>

<?php require_once 'footer.php'; ?>
