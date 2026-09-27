<?php
// create_guide.php — only the artist who owns the comic can create / edit / delete its guide.
// The guide is saved as assets/guides/comic-<id>.html (readable HTML + an embedded JSON copy
// of the form data for re-editing) and COMIC.PDF stores that path.
require_once 'db.php';
requireArtist();

$artistId = currentUserId();
$comicId  = (int)($_GET['comic_id'] ?? 0);
if (!$comicId) redirect('admin_panel.php');

$stmt = $pdo->prepare("SELECT ID, Name, Title, PDF FROM COMIC WHERE ID = ? AND AID = ?");
$stmt->execute([$comicId, $artistId]);
$comic = $stmt->fetch();
if (!$comic) {
    setFlash('You can only write guides for your own comics.', 'error');
    redirect('admin_panel.php');
}

$comicLabel  = $comic['Title'] ?: $comic['Name'];
$guideRel    = "assets/guides/comic-{$comicId}.html";
$guideFile   = __DIR__ . '/' . $guideRel;
$guideExists = !empty($comic['PDF']) && is_file($guideFile);
$self        = "create_guide.php?comic_id={$comicId}";

// ── Existing guide data (for editing) ─────────────────
$defaults = ['guide_title' => '', 'overview' => '', 'sections' => [['heading' => '', 'body' => '']], 'tips' => '', 'final_notes' => ''];
$existing = $defaults;
if ($guideExists && preg_match('/<script type="application\/json" id="guide-data">(.*?)<\/script>/s', file_get_contents($guideFile), $m)) {
    $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
    if (is_array($decoded)) $existing = array_merge($defaults, $decoded);
    if (empty($existing['sections'])) $existing['sections'] = $defaults['sections'];
}

// ── Save ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'save_guide') {
    $data = [
        'guide_title' => mb_substr(trim($_POST['guide_title'] ?? ''), 0, 200),
        'overview'    => trim($_POST['overview'] ?? ''),
        'tips'        => trim($_POST['tips'] ?? ''),
        'final_notes' => trim($_POST['final_notes'] ?? ''),
        'sections'    => [],
    ];
    $bodies = $_POST['sec_body'] ?? [];
    foreach ($_POST['sec_heading'] ?? [] as $i => $heading) {
        $heading = trim($heading);
        $body    = trim($bodies[$i] ?? '');
        if ($heading !== '' || $body !== '') $data['sections'][] = ['heading' => $heading, 'body' => $body];
    }

    if ($data['guide_title'] === '') {
        setFlash('Please give the guide a title.', 'error');
        redirect($self);
    }

    // Readable HTML version (everything escaped) + the JSON copy used for editing
    $e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $html  = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">\n<title>{$e($data['guide_title'])} — Guide</title>\n</head>\n<body>\n";
    $html .= "<!-- Structured data for editing — do not remove -->\n";
    $html .= '<script type="application/json" id="guide-data">' . $e(json_encode($data, JSON_UNESCAPED_UNICODE)) . "</script>\n";
    $html .= "<div data-guide=\"true\">\n<h1>{$e($data['guide_title'])}</h1>\n";
    if ($data['overview'] !== '') $html .= '<div class="gv-overview">' . nl2br($e($data['overview'])) . "</div>\n";
    foreach ($data['sections'] as $s) {
        $html .= '<div class="gv-section">'
               . ($s['heading'] !== '' ? '<h2>' . $e($s['heading']) . '</h2>' : '')
               . ($s['body'] !== '' ? '<div>' . nl2br($e($s['body'])) . '</div>' : '')
               . "</div>\n";
    }
    if ($data['tips'] !== '') {
        $html .= "<div class=\"gv-tips\"><h2>Tips &amp; Tricks</h2><ul>";
        foreach (array_filter(array_map('trim', explode("\n", $data['tips']))) as $tip) $html .= '<li>' . $e($tip) . '</li>';
        $html .= "</ul></div>\n";
    }
    if ($data['final_notes'] !== '') $html .= '<div class="gv-final"><h2>Final Notes</h2><div>' . nl2br($e($data['final_notes'])) . "</div></div>\n";
    $html .= "</div>\n</body>\n</html>\n";

    if (!is_dir(dirname($guideFile))) mkdir(dirname($guideFile), 0775, true);
    if (file_put_contents($guideFile, $html) === false) {
        setFlash('Could not write the guide file. Check that assets/guides/ is writable.', 'error');
        redirect($self);
    }
    $pdo->prepare("UPDATE COMIC SET PDF = ? WHERE ID = ? AND AID = ?")->execute([$guideRel, $comicId, $artistId]);

    setFlash('✓ Guide saved! Readers can now open it from the comic page.');
    redirect($self);
}

// ── Delete ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete_guide') {
    if (is_file($guideFile)) @unlink($guideFile);
    $pdo->prepare("UPDATE COMIC SET PDF = NULL WHERE ID = ? AND AID = ?")->execute([$comicId, $artistId]);
    setFlash('Guide deleted.');
    redirect($self);
}

$pageTitle = ($guideExists ? 'Edit' : 'Create') . ' Guide — ' . $comicLabel;
require_once 'header.php';
?>

<div class="narrow-wrap">
    <a href="comic.php?id=<?php echo $comicId; ?>" class="back-link">← Back to Comic</a>

    <h1 class="page-title">📖 <?php echo $guideExists ? 'Edit' : 'Create'; ?> Guide</h1>
    <p class="muted" style="margin-bottom:24px">
        Writing guide for <strong><?php echo h($comicLabel); ?></strong> — only you (the artist) can edit this. Readers will see it as a formatted page.
    </p>

    <?php if ($guideExists): ?>
        <div class="info-bar">
            <span>📄 A guide is live for this comic.</span>
            <div class="btn-row">
                <a href="view_guide.php?comic_id=<?php echo $comicId; ?>" target="_blank" class="btn btn-outline btn-xs">👁 Preview</a>
                <form method="POST" action="<?php echo h($self); ?>" onsubmit="return confirm('Delete this guide permanently?')">
                    <input type="hidden" name="_action" value="delete_guide">
                    <button type="submit" class="btn btn-danger btn-xs">🗑 Delete Guide</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" action="<?php echo h($self); ?>" id="guideForm">
        <input type="hidden" name="_action" value="save_guide">

        <div class="card">
            <h3 class="card-title">Guide Title &amp; Overview</h3>
            <div class="form-group">
                <label>Guide Title</label>
                <input type="text" name="guide_title" class="form-control" maxlength="200" required
                       placeholder="e.g. Reading Guide for <?php echo h($comicLabel); ?>" value="<?php echo h($existing['guide_title']); ?>">
                <small class="form-hint">This is the big heading readers see at the top.</small>
            </div>
            <div class="form-group">
                <label>Overview / Introduction</label>
                <textarea name="overview" rows="4" class="form-control"
                          placeholder="Briefly introduce what this comic is about and what readers can expect…"><?php echo h($existing['overview']); ?></textarea>
            </div>
        </div>

        <div class="card">
            <h3 class="card-title">📚 Content Sections</h3>
            <p class="muted small" style="margin-bottom:16px">Reading order tips, world-building notes, character introductions, chapter breakdowns — add as many as you like.</p>
            <div id="sections-container">
                <?php foreach ($existing['sections'] as $i => $sec): ?>
                    <div class="section-block">
                        <div class="section-num">Section <?php echo $i + 1; ?></div>
                        <button type="button" class="btn-rm-section" onclick="removeSection(this)" title="Remove section">✕</button>
                        <div class="form-group">
                            <label>Section Heading</label>
                            <input type="text" name="sec_heading[]" class="form-control" placeholder="e.g. Where to Start, Power System Explained…" value="<?php echo h($sec['heading'] ?? ''); ?>">
                        </div>
                        <div class="form-group" style="margin-bottom:0">
                            <label>Content</label>
                            <textarea name="sec_body[]" rows="5" class="form-control" placeholder="Write the section content here…"><?php echo h($sec['body'] ?? ''); ?></textarea>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-add-section" onclick="addSection()">+ Add Another Section</button>
        </div>

        <div class="card">
            <h3 class="card-title">💡 Tips &amp; Tricks</h3>
            <div class="form-group">
                <label>Tips (one per line)</label>
                <textarea name="tips" rows="5" class="form-control"
                          placeholder="Pay attention to the background details&#10;Read the author's notes at the end of each chapter"><?php echo h($existing['tips']); ?></textarea>
                <small class="form-hint">Each line becomes a bullet point.</small>
            </div>
        </div>

        <div class="card">
            <h3 class="card-title">📝 Final Notes</h3>
            <div class="form-group">
                <label>Closing message to readers</label>
                <textarea name="final_notes" rows="4" class="form-control"
                          placeholder="Thank readers, share your socials, mention upcoming chapters…"><?php echo h($existing['final_notes']); ?></textarea>
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-full btn-lg">💾 Save Guide</button>
    </form>
</div>

<template id="section-template">
    <div class="section-block">
        <div class="section-num"></div>
        <button type="button" class="btn-rm-section" onclick="removeSection(this)" title="Remove section">✕</button>
        <div class="form-group">
            <label>Section Heading</label>
            <input type="text" name="sec_heading[]" class="form-control" placeholder="e.g. Where to Start, Power System Explained…">
        </div>
        <div class="form-group" style="margin-bottom:0">
            <label>Content</label>
            <textarea name="sec_body[]" rows="5" class="form-control" placeholder="Write the section content here…"></textarea>
        </div>
    </div>
</template>

<script>
function renumberSections() {
    const blocks = document.querySelectorAll('#sections-container .section-block');
    blocks.forEach((block, i) => {
        block.querySelector('.section-num').textContent = 'Section ' + (i + 1);
        block.querySelector('.btn-rm-section').style.display = blocks.length > 1 ? '' : 'none';
    });
}
function addSection() {
    const node = document.getElementById('section-template').content.firstElementChild.cloneNode(true);
    document.getElementById('sections-container').appendChild(node);
    renumberSections();
    node.querySelector('input').focus();
}
function removeSection(btn) {
    btn.closest('.section-block').remove();
    renumberSections();
}
renumberSections();
</script>

<?php require_once 'footer.php'; ?>
