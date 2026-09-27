<?php
// fan-art.php — fan art gallery (filter by character) + upload for readers
$pageTitle = 'Fan Art';
require_once 'db.php';

$artTypes = ['Digital', 'Traditional', 'Chibi', 'Sketch', 'Cosplay', 'Other'];
$artRoles = ['Main subject', 'Supporting', 'Group shot', 'Background'];

// ── Upload ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    if (!isUserLoggedIn()) redirect('login_user.php');

    $charId = (int)($_POST['character_id'] ?? 0);
    $type   = in_array($_POST['type'] ?? '', $artTypes, true) ? $_POST['type'] : null;
    $role   = in_array($_POST['role'] ?? '', $artRoles, true) ? $_POST['role'] : 'Main subject';

    $c = $pdo->prepare("SELECT 1 FROM CHARACTERS WHERE ID = ?");
    $c->execute([$charId]);

    if (!$c->fetchColumn()) {
        setFlash('Please choose a character.', 'error');
    } else {
        try {
            $path  = saveUploadedImage($_FILES['art'] ?? [], 'assets/fanarts', uniqid('fanart_', true), 5 * 1024 * 1024);
            $fname = basename($path);

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO FANART (Image_URL, UID, Upload_Date) VALUES (?, ?, NOW())")->execute([$fname, currentUserId()]);
            $fanId = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO DEPICTED_IN (Fan_ID, Character_ID, Type, Role) VALUES (?, ?, ?, ?)")
                ->execute([$fanId, $charId, $type, $role]);
            $pdo->commit();
            setFlash('Your fan art was uploaded successfully!');
        } catch (RuntimeException $e) {
            setFlash($e->getMessage(), 'error');
        }
    }
    redirect('fan-art.php' . ($charId ? '?char=' . $charId : ''));
}

// ── Delete own fan art ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isUserLoggedIn()) {
    $s = $pdo->prepare("SELECT Image_URL FROM FANART WHERE ID = ? AND UID = ?");
    $s->execute([(int)$_POST['fan_id'], currentUserId()]);
    if ($img = $s->fetchColumn()) {
        $pdo->prepare("DELETE FROM FANART WHERE ID = ? AND UID = ?")->execute([(int)$_POST['fan_id'], currentUserId()]);
        @unlink(__DIR__ . '/assets/fanarts/' . basename($img));
        setFlash('Fan art deleted.');
    }
    redirect('fan-art.php');
}

// ── Page data ─────────────────────────────────────────
$filterChar = (int)($_GET['char'] ?? 0);

$characters = $pdo->query("
    SELECT ch.ID, ch.Name, COALESCE(c.Title, c.Name) AS ComicTitle,
           (SELECT COUNT(*) FROM DEPICTED_IN d WHERE d.Character_ID = ch.ID) AS ArtCount
    FROM CHARACTERS ch JOIN COMIC c ON ch.Comic_ID = c.ID
    ORDER BY ch.Name
")->fetchAll();

$sql = "
    SELECT fa.ID AS Fan_ID, fa.Image_URL, fa.Upload_Date, fa.UID,
           ch.Name AS CharName, COALESCE(c.Title, c.Name) AS ComicTitle,
           di.Type, di.Role, u.Name AS UploaderName
    FROM FANART fa
    LEFT JOIN DEPICTED_IN di ON fa.ID = di.Fan_ID
    LEFT JOIN CHARACTERS ch  ON di.Character_ID = ch.ID
    LEFT JOIN COMIC c        ON ch.Comic_ID = c.ID
    LEFT JOIN USERS u        ON fa.UID = u.ID
    " . ($filterChar ? "WHERE di.Character_ID = ?" : "") . "
    ORDER BY fa.ID DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($filterChar ? [$filterChar] : []);
$gallery = $stmt->fetchAll();

$filterName = '';
foreach ($characters as $ch) if ((int)$ch['ID'] === $filterChar) $filterName = $ch['Name'];

require_once 'header.php';
?>

<h1 class="page-title">Fan <span class="accent-red">Art</span> Gallery</h1>
<p class="muted" style="margin-bottom:28px">Community-made artwork celebrating your favourite characters</p>

<div class="fanart-top">
    <div>
        <div class="eyebrow">Filter by character</div>
        <div class="filter-bar">
            <a href="fan-art.php" class="filter-chip <?php echo $filterChar === 0 ? 'active' : ''; ?>">All Characters</a>
            <?php foreach ($characters as $ch): ?>
                <a href="fan-art.php?char=<?php echo (int)$ch['ID']; ?>" class="filter-chip <?php echo $filterChar === (int)$ch['ID'] ? 'active' : ''; ?>">
                    <?php echo h($ch['Name']); ?><?php if ($ch['ArtCount']): ?> <span class="chip-count"><?php echo (int)$ch['ArtCount']; ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <p class="muted small" style="margin-top:12px">
            <?php echo count($gallery); ?> artwork<?php echo count($gallery) === 1 ? '' : 's'; ?>
            <?php if ($filterName): ?> for <strong><?php echo h($filterName); ?></strong><?php endif; ?>
        </p>
    </div>

    <?php if (isUserLoggedIn()): ?>
        <div class="upload-box">
            <h3 class="card-title">Upload Your Fan Art</h3>
            <form method="POST" action="fan-art.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload">
                <div class="form-group">
                    <label>Character</label>
                    <select name="character_id" class="form-control" required>
                        <option value="">— Select a character —</option>
                        <?php foreach ($characters as $ch): ?>
                            <option value="<?php echo (int)$ch['ID']; ?>" <?php echo $filterChar === (int)$ch['ID'] ? 'selected' : ''; ?>>
                                <?php echo h($ch['Name']); ?> (<?php echo h($ch['ComicTitle']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Style</label>
                        <select name="type" class="form-control">
                            <?php foreach ($artTypes as $t): ?><option><?php echo $t; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Character role</label>
                        <select name="role" class="form-control">
                            <?php foreach ($artRoles as $r): ?><option><?php echo $r; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Artwork image (max 5 MB)</label>
                    <div class="drop-zone" id="drop-zone" onclick="document.getElementById('art-file').click()">
                        <div class="drop-icon">🎨</div>
                        <p>Click to browse or drag &amp; drop</p>
                        <p class="small">JPG · PNG · WebP · GIF</p>
                        <input type="file" name="art" id="art-file" accept="image/jpeg,image/png,image/webp,image/gif" hidden onchange="previewFile(this)">
                    </div>
                    <img id="preview" src="" alt="" class="upload-preview" style="display:none">
                </div>
                <button type="submit" class="btn btn-primary btn-full"
                        onclick="if(!document.getElementById('art-file').files.length){alert('Please choose an image first.');return false;}">Upload Fan Art</button>
            </form>
        </div>
    <?php else: ?>
        <div class="upload-box upload-locked">
            <div class="drop-icon">🔒</div>
            <p class="muted">Log in as a reader to upload fan art!</p>
            <a href="login_user.php" class="btn btn-primary">Sign in to Upload</a>
        </div>
    <?php endif; ?>
</div>

<?php if (!$gallery): ?>
    <div class="empty-state">
        <div class="empty-icon">🖼️</div>
        <p>No fan art yet<?php echo $filterChar ? ' for this character' : ''; ?>. Be the first to upload!</p>
    </div>
<?php else: ?>
    <div class="fanart-grid">
        <?php foreach ($gallery as $art):
            $file = basename($art['Image_URL']);
            $src  = is_file(__DIR__ . '/assets/fanarts/' . $file) ? 'assets/fanarts/' . $file : 'assets/thumbnails/placeholder.webp'; ?>
            <div class="fanart-item">
                <a href="<?php echo h($src); ?>" target="_blank" rel="noopener" class="fanart-img-wrap">
                    <img src="<?php echo h($src); ?>" alt="Fan art of <?php echo h($art['CharName'] ?? 'a character'); ?>" loading="lazy">
                </a>
                <div class="fanart-item-info">
                    <div class="fanart-item-char">
                        <?php echo h($art['CharName'] ?? 'Unknown Character'); ?>
                        <?php if ($art['ComicTitle']): ?><span class="muted"> · <?php echo h($art['ComicTitle']); ?></span><?php endif; ?>
                    </div>
                    <?php if ($art['Type'] || $art['Role']): ?>
                        <div class="fanart-tags">
                            <?php if ($art['Type']): ?><span class="tag"><?php echo h($art['Type']); ?></span><?php endif; ?>
                            <?php if ($art['Role']): ?><span class="tag"><?php echo h($art['Role']); ?></span><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div class="fanart-item-by">
                        <span>by <?php echo h($art['UploaderName'] ?? 'Anonymous'); ?></span>
                        <span class="muted"><?php echo date('M j, Y', strtotime($art['Upload_Date'])); ?></span>
                    </div>
                    <?php if (isUserLoggedIn() && currentUserId() === (int)$art['UID']): ?>
                        <form method="POST" action="fan-art.php" onsubmit="return confirm('Delete this fan art?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="fan_id" value="<?php echo (int)$art['Fan_ID']; ?>">
                            <button type="submit" class="link-danger">🗑 Delete</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
function previewFile(input) {
    if (!input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        const prev = document.getElementById('preview');
        prev.src = e.target.result;
        prev.style.display = 'block';
    };
    reader.readAsDataURL(input.files[0]);
}
const zone = document.getElementById('drop-zone');
if (zone) {
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
    zone.addEventListener('drop', e => {
        e.preventDefault();
        zone.classList.remove('dragover');
        const input = document.getElementById('art-file');
        input.files = e.dataTransfer.files;
        previewFile(input);
    });
}
</script>

<?php require_once 'footer.php'; ?>
