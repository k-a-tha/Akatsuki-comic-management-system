<?php
// comics.php — browse, filter by genre, search and sort
require_once 'db.php';

$genre  = trim($_GET['genre']  ?? '');
$search = trim($_GET['search'] ?? '');
$sort   = $_GET['sort'] ?? 'latest';
if (!in_array($sort, ['latest', 'popular', 'views', 'name'], true)) $sort = 'latest';

function buildUrl(array $changes): string {
    global $genre, $search, $sort;
    $params = array_filter(array_merge(['genre' => $genre, 'search' => $search, 'sort' => $sort], $changes),
                           fn($v) => $v !== '' && $v !== null);
    return 'comics.php' . ($params ? '?' . http_build_query($params) : '');
}

$sql = "
    SELECT x.*, lu.LastUpdate
    FROM (" . comicListSql() . ") x
    LEFT JOIN (SELECT Comic_ID, MAX(Date_of_Publication) AS LastUpdate
               FROM CHAPTER WHERE isPublished = TRUE GROUP BY Comic_ID) lu ON lu.Comic_ID = x.ID
";
$where  = [];
$params = [];

if ($genre !== '') {
    // "slice-of-life" and "Slice of Life" both match
    $where[]  = "x.ID IN (SELECT Comic_ID FROM GENRE WHERE LOWER(REPLACE(Genre, ' ', '-')) = LOWER(REPLACE(?, ' ', '-')))";
    $params[] = $genre;
}
if ($search !== '') {
    $where[]  = "(x.Name LIKE ? OR x.Title LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);

$sql .= match ($sort) {
    'popular' => ' ORDER BY x.AvgRating DESC, x.RatingCount DESC',
    'views'   => ' ORDER BY x.ViewCount DESC',
    'name'    => ' ORDER BY COALESCE(x.Title, x.Name) ASC',
    default   => ' ORDER BY lu.LastUpdate IS NULL, lu.LastUpdate DESC, x.ID DESC',
};

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$comics = $stmt->fetchAll();

// Pretty genre name for the heading
$genreLabel = $genre;
if ($genre !== '') {
    $g = $pdo->prepare("SELECT Genre FROM GENRE WHERE LOWER(REPLACE(Genre, ' ', '-')) = LOWER(REPLACE(?, ' ', '-')) LIMIT 1");
    $g->execute([$genre]);
    $genreLabel = $g->fetchColumn() ?: ucwords(str_replace('-', ' ', $genre));
}

$pageTitle = $genre ? "$genreLabel Comics" : ($search ? "Search: $search" : 'All Comics');
require_once 'header.php';
?>

<div class="page-header">
    <h1 class="section-title">
        <?php if ($genre): ?>
            <?php echo h($genreLabel); ?> Comics
        <?php elseif ($search): ?>
            Search Results for "<?php echo h($search); ?>"
        <?php else: ?>
            All Comics
        <?php endif; ?>
    </h1>

    <div class="filters">
        <select class="filter-select" onchange="window.location.href=this.value" aria-label="Sort comics">
            <option value="<?php echo h(buildUrl(['sort' => 'latest'])); ?>"  <?php echo $sort === 'latest'  ? 'selected' : ''; ?>>Latest Updates</option>
            <option value="<?php echo h(buildUrl(['sort' => 'popular'])); ?>" <?php echo $sort === 'popular' ? 'selected' : ''; ?>>Most Popular (rating)</option>
            <option value="<?php echo h(buildUrl(['sort' => 'views'])); ?>"   <?php echo $sort === 'views'   ? 'selected' : ''; ?>>Most Viewed</option>
            <option value="<?php echo h(buildUrl(['sort' => 'name'])); ?>"    <?php echo $sort === 'name'    ? 'selected' : ''; ?>>Alphabetical</option>
        </select>
    </div>
</div>

<?php if ($genre || $search): ?>
    <div class="active-filters">
        <?php if ($genre): ?>
            <span class="filter-tag">Genre: <?php echo h($genreLabel); ?>
                <a href="<?php echo h(buildUrl(['genre' => ''])); ?>" aria-label="Remove genre filter">×</a></span>
        <?php endif; ?>
        <?php if ($search): ?>
            <span class="filter-tag">Search: <?php echo h($search); ?>
                <a href="<?php echo h(buildUrl(['search' => ''])); ?>" aria-label="Remove search">×</a></span>
        <?php endif; ?>
        <a href="comics.php" class="clear-filters">Clear all</a>
    </div>
<?php endif; ?>

<div class="comics-grid">
    <?php foreach ($comics as $comic): ?>
        <?php echo comicCard($pdo, $comic, ['buy' => true]); ?>
    <?php endforeach; ?>

    <?php if (empty($comics)): ?>
        <p class="empty-note" style="grid-column:1/-1;">
            <?php if ($search): ?>
                No comics found matching "<?php echo h($search); ?>".
            <?php elseif ($genre): ?>
                No comics found in the <?php echo h($genreLabel); ?> genre.
            <?php else: ?>
                No comics available yet.
            <?php endif; ?>
        </p>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
