<?php
// admin_panel.php — Artist Dashboard.
// Artists manage ONLY their own comics: create comics, upload chapters, add characters,
// run prediction polls (publish chapter + resolve poll + pay coins), and sell special emojis.
require_once 'db.php';
requireArtist();

$artistId = currentUserId();
$tabs     = ['overview' => '📊 Overview', 'comics' => '📚 Comics', 'chapters' => '📄 Chapters',
             'characters' => '🧑 Characters', 'polls' => '🔮 Predictions', 'emojis' => '🎭 Emojis'];
$tab      = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'overview';

$commonGenres = ['Action', 'Adventure', 'Comedy', 'Drama', 'Fantasy', 'Horror', 'Martial Arts',
                 'Mystery', 'Romance', 'Sci-Fi', 'Slice of Life', 'Sports', 'Thriller'];

/** Finish a POST: store the message and go back to the same tab (Post/Redirect/Get). */
function done(string $tab, string $message, string $type = 'success'): void {
    setFlash($message, $type);
    redirect('admin_panel.php?tab=' . $tab);
}

/** Genres from the checkboxes + the "other genres" text box, matched to existing spellings. */
function postedGenres(PDO $pdo, array $commonGenres): array {
    $known = [];
    foreach (array_merge($commonGenres, $pdo->query("SELECT DISTINCT Genre FROM GENRE")->fetchAll(PDO::FETCH_COLUMN)) as $g) {
        $known[mb_strtolower($g)] = $g;
    }
    $list  = array_merge((array)($_POST['genres'] ?? []), explode(',', $_POST['genres_other'] ?? ''));
    $clean = [];
    foreach ($list as $g) {
        $g = mb_substr(trim(preg_replace('/\s+/', ' ', $g)), 0, 50);
        if ($g === '') continue;
        $key = mb_strtolower($g);
        $clean[$key] = $known[$key] ?? mb_strtoupper(mb_substr($g, 0, 1)) . mb_substr($g, 1);
    }
    return array_values($clean);
}

function saveGenres(PDO $pdo, int $comicId, array $genres): void {
    $pdo->prepare("DELETE FROM GENRE WHERE Comic_ID = ?")->execute([$comicId]);
    $ins = $pdo->prepare("INSERT INTO GENRE (Comic_ID, Genre) VALUES (?, ?)");
    foreach ($genres as $g) $ins->execute([$comicId, $g]);
}

/** Saves a cover as assets/thumbnails/<slug>.<ext>, replacing any old cover of that comic. */
function saveCover(array $file, string $comicName): void {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return;
    deleteCoverFiles($comicName);
    saveUploadedImage($file, 'assets/thumbnails', slugify($comicName), 5 * 1024 * 1024);
}

/** Saves chapter page images (in file-name order) into the chapter folder, continuing the numbering. */
function saveChapterPages(array $files, string $folder): int {
    $files = array_filter(normalizeFiles($files), fn($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
    usort($files, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    $existing = count(listImages($folder));
    $saved = 0;
    foreach ($files as $f) {
        saveUploadedImage($f, $folder, sprintf('page-%03d', $existing + $saved + 1), 10 * 1024 * 1024);
        $saved++;
    }
    return $saved;
}

/**
 * Publishes a chapter. If a poll is given, it is resolved first: winners get 1 coin
 * (COIN_TRANSACTION linked from PREDICTION.Coin_T_ID) and everyone who voted is notified.
 * Readers who follow the comic (rated / predicted / posted / voted) get a "new chapter" notification.
 * Must be called inside a transaction.
 */
function publishChapter(PDO $pdo, int $chapterId, int $pollChapterId = 0, int $correctOptionId = 0): array {
    $info = $pdo->prepare("
        SELECT ch.ChapterNumber, ch.Comic_ID, COALESCE(c.Title, c.Name) AS ComicTitle
        FROM CHAPTER ch JOIN COMIC c ON ch.Comic_ID = c.ID WHERE ch.CHAPTER_ID = ?
    ");
    $info->execute([$chapterId]);
    $chapter = $info->fetch();
    $winners = $losers = [];

    if ($pollChapterId && $correctOptionId) {
        $info->execute([$pollChapterId]);
        $pollChapter = $info->fetch();
        $opt = $pdo->prepare("SELECT OptionText FROM PREDICTION_OPTION WHERE ID = ?");
        $opt->execute([$correctOptionId]);
        $correctText = $opt->fetchColumn();
        $label = "{$pollChapter['ComicTitle']} Chapter {$pollChapter['ChapterNumber']}";

        $pdo->prepare("UPDATE PREDICTION_OPTION SET IsResolved = TRUE, IsCorrect = (ID = ?) WHERE Chapter_ID = ?")
            ->execute([$correctOptionId, $pollChapterId]);

        $w = $pdo->prepare("SELECT ID, UID FROM PREDICTION WHERE Option_ID = ?");
        $w->execute([$correctOptionId]);
        $winners = $w->fetchAll();

        $l = $pdo->prepare("
            SELECT p.ID, p.UID FROM PREDICTION p JOIN PREDICTION_OPTION po ON p.Option_ID = po.ID
            WHERE po.Chapter_ID = ? AND p.Option_ID <> ?
        ");
        $l->execute([$pollChapterId, $correctOptionId]);
        $losers = $l->fetchAll();

        $coin = $pdo->prepare("INSERT INTO COIN_TRANSACTION (Amount, Time_stamp, Reason, UID) VALUES (1, NOW(), ?, ?)");
        $link = $pdo->prepare("UPDATE PREDICTION SET Coin_T_ID = ? WHERE ID = ?");
        foreach ($winners as $win) {
            $coin->execute(["Correct prediction: {$label}", $win['UID']]);
            $link->execute([$pdo->lastInsertId(), $win['ID']]);
            notify($pdo, (int)$win['UID'], "You predicted correctly for {$label}! The answer was: \"{$correctText}\". You earned 1 coin!", 'correct_prediction');
        }
        foreach ($losers as $lose) {
            notify($pdo, (int)$lose['UID'], "Your prediction for {$label} was incorrect. The correct answer was: \"{$correctText}\". Better luck next time!", 'wrong_prediction');
        }
    }

    $pdo->prepare("UPDATE CHAPTER SET isPublished = TRUE, Date_of_Publication = CURDATE() WHERE CHAPTER_ID = ?")
        ->execute([$chapterId]);

    $f = $pdo->prepare("
        SELECT UID FROM RATING WHERE Comic_ID = ?
        UNION SELECT UID FROM FORUM_POST WHERE Comic_ID = ?
        UNION SELECT UID FROM VOTE_FOR WHERE Comic_ID = ?
        UNION SELECT p.UID FROM PREDICTION p
              JOIN PREDICTION_OPTION po ON p.Option_ID = po.ID
              JOIN CHAPTER ch ON po.Chapter_ID = ch.CHAPTER_ID
              WHERE ch.Comic_ID = ?
    ");
    $f->execute(array_fill(0, 4, $chapter['Comic_ID']));
    $followers = $f->fetchAll(PDO::FETCH_COLUMN);
    foreach ($followers as $uid) {
        notify($pdo, (int)$uid, "📖 New chapter! {$chapter['ComicTitle']} Chapter {$chapter['ChapterNumber']} is out now.", 'new_chapter');
    }
    return ['winners' => count($winners), 'losers' => count($losers), 'followers' => count($followers)];
}

// A POST bigger than post_max_size arrives with empty $_POST/$_FILES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    done($tab, 'The upload was too large for the server (post_max_size = ' . ini_get('post_max_size') . '). Upload fewer or smaller images at a time.', 'error');
}

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : '';

try {
    // ════════════════ COMICS ════════════════
    if ($action === 'create_comic') {
        $name     = mb_substr(trim($_POST['name'] ?? ''), 0, 200);
        $title    = mb_substr(trim($_POST['title'] ?? ''), 0, 200);
        $synopsis = mb_substr(trim($_POST['synopsis'] ?? ''), 0, 1000);
        $link     = trim($_POST['hard_copy_link'] ?? '');

        if ($name === '') done('comics', 'Comic name is required.', 'error');
        if ($link !== '' && !isExternalUrl($link)) done('comics', 'The hard copy link must start with http:// or https://', 'error');

        $slug = slugify($name);
        foreach ($pdo->query("SELECT Name FROM COMIC")->fetchAll(PDO::FETCH_COLUMN) as $existingName) {
            if (slugify($existingName) === $slug) done('comics', 'A comic with this name already exists. Please choose another name.', 'error');
        }

        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO COMIC (Name, Title, Synopsis, HardCopyLink, AID) VALUES (?, ?, ?, ?, ?)")
            ->execute([$name, $title !== '' ? $title : null, $synopsis !== '' ? $synopsis : null, $link !== '' ? $link : null, $artistId]);
        $newId = (int)$pdo->lastInsertId();
        saveGenres($pdo, $newId, postedGenres($pdo, $commonGenres));
        $pdo->commit();

        try {
            saveCover($_FILES['cover'] ?? [], $name);
        } catch (RuntimeException $e) {
            done('comics', "Comic created, but the cover was not saved: " . $e->getMessage(), 'error');
        }
        done('chapters', "Comic \"{$name}\" created! Now upload its first chapter below.");
    }

    if ($action === 'update_comic') {
        $comicId = (int)($_POST['comic_id'] ?? 0);
        if (!artistOwnsComic($pdo, $comicId, $artistId)) done('comics', 'That is not your comic.', 'error');
        $title    = mb_substr(trim($_POST['title'] ?? ''), 0, 200);
        $synopsis = mb_substr(trim($_POST['synopsis'] ?? ''), 0, 1000);
        $link     = trim($_POST['hard_copy_link'] ?? '');
        if ($link !== '' && !isExternalUrl($link)) done('comics', 'The hard copy link must start with http:// or https://', 'error');

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE COMIC SET Title = ?, Synopsis = ?, HardCopyLink = ? WHERE ID = ? AND AID = ?")
            ->execute([$title !== '' ? $title : null, $synopsis !== '' ? $synopsis : null, $link !== '' ? $link : null, $comicId, $artistId]);
        saveGenres($pdo, $comicId, postedGenres($pdo, $commonGenres));
        $pdo->commit();

        $n = $pdo->prepare("SELECT Name FROM COMIC WHERE ID = ?");
        $n->execute([$comicId]);
        saveCover($_FILES['cover'] ?? [], $n->fetchColumn());
        done('comics', 'Comic updated.');
    }

    if ($action === 'delete_comic') {
        $comicId = (int)($_POST['comic_id'] ?? 0);
        if (!artistOwnsComic($pdo, $comicId, $artistId)) done('comics', 'That is not your comic.', 'error');
        $n = $pdo->prepare("SELECT Name FROM COMIC WHERE ID = ?");
        $n->execute([$comicId]);
        $name = $n->fetchColumn();
        // ON DELETE CASCADE removes its chapters, polls, characters, ratings, posts, emojis, streams…
        $pdo->prepare("DELETE FROM COMIC WHERE ID = ? AND AID = ?")->execute([$comicId, $artistId]);
        deleteCoverFiles($name);
        @unlink(__DIR__ . "/assets/guides/comic-{$comicId}.html");
        done('comics', "Comic \"{$name}\" and everything linked to it was deleted.");
    }

    // ════════════════ CHAPTERS ════════════════
    if ($action === 'add_chapter') {
        $comicId = (int)($_POST['comic_id'] ?? 0);
        $number  = (int)($_POST['chapter_number'] ?? 0);
        if (!artistOwnsComic($pdo, $comicId, $artistId)) done('chapters', 'Please choose one of your comics.', 'error');
        if ($number < 1) done('chapters', 'Chapter number must be 1 or more.', 'error');

        $dup = $pdo->prepare("SELECT 1 FROM CHAPTER WHERE Comic_ID = ? AND ChapterNumber = ?");
        $dup->execute([$comicId, $number]);
        if ($dup->fetchColumn()) done('chapters', "Chapter {$number} already exists for this comic.", 'error');

        $n = $pdo->prepare("SELECT Name FROM COMIC WHERE ID = ?");
        $n->execute([$comicId]);
        $folder = 'assets/chapters/' . slugify($n->fetchColumn()) . '/chapter-' . $number;

        $saved = saveChapterPages($_FILES['pages'] ?? [], $folder);
        if ($saved === 0) done('chapters', 'Please choose at least one page image.', 'error');

        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO CHAPTER (ChapterNumber, isPublished, ContentURL, Comic_ID) VALUES (?, FALSE, ?, ?)")
            ->execute([$number, $folder, $comicId]);
        $newChapter = (int)$pdo->lastInsertId();
        $published  = !empty($_POST['publish_now']);
        $result     = $published ? publishChapter($pdo, $newChapter) : null;
        $pdo->commit();

        done('chapters', "Chapter {$number} uploaded ({$saved} page" . ($saved === 1 ? '' : 's') . ")"
            . ($published ? " and published. {$result['followers']} follower(s) notified." : ' as a draft. Publish it below when ready.'));
    }

    if ($action === 'add_pages') {
        $chapterId = (int)($_POST['chapter_id'] ?? 0);
        if (!artistOwnsChapter($pdo, $chapterId, $artistId)) done('chapters', 'That is not your chapter.', 'error');
        $c = $pdo->prepare("SELECT ch.ContentURL, ch.ChapterNumber, c.Name FROM CHAPTER ch JOIN COMIC c ON ch.Comic_ID = c.ID WHERE ch.CHAPTER_ID = ?");
        $c->execute([$chapterId]);
        $row    = $c->fetch();
        $folder = $row['ContentURL'] ?: 'assets/chapters/' . slugify($row['Name']) . '/chapter-' . $row['ChapterNumber'];
        $saved  = saveChapterPages($_FILES['pages'] ?? [], $folder);
        if ($saved === 0) done('chapters', 'Please choose at least one page image.', 'error');
        $pdo->prepare("UPDATE CHAPTER SET ContentURL = ? WHERE CHAPTER_ID = ?")->execute([$folder, $chapterId]);
        done('chapters', "{$saved} page(s) added to Chapter {$row['ChapterNumber']}.");
    }

    if ($action === 'delete_chapter') {
        $chapterId = (int)($_POST['chapter_id'] ?? 0);
        $c = $pdo->prepare("
            SELECT ch.ContentURL, ch.ChapterNumber, ch.isPublished FROM CHAPTER ch JOIN COMIC c ON ch.Comic_ID = c.ID
            WHERE ch.CHAPTER_ID = ? AND c.AID = ?
        ");
        $c->execute([$chapterId, $artistId]);
        $row = $c->fetch();
        if (!$row) done('chapters', 'That is not your chapter.', 'error');
        if ($row['isPublished']) done('chapters', 'Published chapters cannot be deleted (readers may have predictions on them).', 'error');
        $pdo->prepare("DELETE FROM CHAPTER WHERE CHAPTER_ID = ?")->execute([$chapterId]);
        $dir = __DIR__ . '/' . trim((string)$row['ContentURL'], '/');
        if ($row['ContentURL'] && str_starts_with(trim($row['ContentURL'], '/'), 'assets/chapters/') && is_dir($dir)) {
            foreach (glob($dir . '/*') ?: [] as $f) if (is_file($f)) @unlink($f);
            @rmdir($dir);
        }
        done('chapters', "Draft chapter {$row['ChapterNumber']} deleted.");
    }

    if ($action === 'publish_and_resolve') {
        $chapterId = (int)($_POST['chapter_to_publish'] ?? 0);
        $pollId    = (int)($_POST['poll_chapter_id'] ?? 0);
        $correctId = (int)($_POST['correct_option_id'] ?? 0);

        $c = $pdo->prepare("
            SELECT ch.CHAPTER_ID, ch.Comic_ID, ch.ChapterNumber, ch.isPublished
            FROM CHAPTER ch JOIN COMIC c ON ch.Comic_ID = c.ID WHERE ch.CHAPTER_ID = ? AND c.AID = ?
        ");
        $c->execute([$chapterId, $artistId]);
        $pub = $c->fetch();
        if (!$pub) done('polls', 'Please select one of your draft chapters to publish.', 'error');
        if ($pub['isPublished']) done('polls', 'That chapter is already published.', 'error');

        if ($pollId) {
            $c->execute([$pollId, $artistId]);
            $poll = $c->fetch();
            if (!$poll) done('polls', 'You can only resolve polls on your own chapters.', 'error');
            if ((int)$poll['Comic_ID'] !== (int)$pub['Comic_ID']) done('polls', 'The poll must belong to the same comic as the chapter you publish.', 'error');
            if ((int)$poll['ChapterNumber'] >= (int)$pub['ChapterNumber']) done('polls', 'Resolve the poll of an EARLIER chapter (the chapter you publish reveals the answer).', 'error');
            $o = $pdo->prepare("SELECT IsResolved FROM PREDICTION_OPTION WHERE ID = ? AND Chapter_ID = ?");
            $o->execute([$correctId, $pollId]);
            $state = $o->fetchColumn();
            if ($state === false) done('polls', 'Please select which option was correct.', 'error');
            if ($state) done('polls', 'That poll is already resolved.', 'error');
        } else {
            $correctId = 0;
        }

        $pdo->beginTransaction();
        $r = publishChapter($pdo, $chapterId, $pollId, $correctId);
        $pdo->commit();
        done('polls', "Chapter {$pub['ChapterNumber']} published! "
            . ($pollId ? "{$r['winners']} reader(s) earned a coin, {$r['losers']} got a better-luck notification. " : '')
            . "{$r['followers']} follower(s) notified.");
    }

    // ════════════════ POLLS ════════════════
    if ($action === 'create_poll') {
        $chapterId = (int)($_POST['chapter_id'] ?? 0);
        $options   = array_values(array_filter(array_map(fn($o) => mb_substr(trim($o), 0, 500), (array)($_POST['options'] ?? [])), fn($o) => $o !== ''));

        if (!artistOwnsChapter($pdo, $chapterId, $artistId)) done('polls', 'Please select one of your chapters.', 'error');
        if (count($options) !== 4) done('polls', 'You must provide exactly 4 options.', 'error');
        if (count(array_unique(array_map('mb_strtolower', $options))) !== 4) done('polls', 'The 4 options must all be different.', 'error');

        $latest = $pdo->prepare("
            SELECT ch.CHAPTER_ID FROM CHAPTER ch
            WHERE ch.Comic_ID = (SELECT Comic_ID FROM CHAPTER WHERE CHAPTER_ID = ?) AND ch.isPublished = TRUE
            ORDER BY ch.ChapterNumber DESC LIMIT 1
        ");
        $latest->execute([$chapterId]);
        if ((int)$latest->fetchColumn() !== $chapterId) done('polls', 'Polls can only be added to the latest published chapter (that is the one readers can vote on).', 'error');

        $exists = $pdo->prepare("SELECT COUNT(*) FROM PREDICTION_OPTION WHERE Chapter_ID = ?");
        $exists->execute([$chapterId]);
        if ($exists->fetchColumn() > 0) done('polls', 'A poll already exists for this chapter. Delete it first.', 'error');

        $ins = $pdo->prepare("INSERT INTO PREDICTION_OPTION (Option_Number, OptionText, Chapter_ID) VALUES (?, ?, ?)");
        foreach ($options as $i => $text) $ins->execute([$i + 1, $text, $chapterId]);
        done('polls', 'Prediction poll created! Readers can vote on the comic page now.');
    }

    if ($action === 'delete_poll') {
        $chapterId = (int)($_POST['chapter_id'] ?? 0);
        if (!artistOwnsChapter($pdo, $chapterId, $artistId)) done('polls', 'That is not your chapter.', 'error');
        $r = $pdo->prepare("SELECT MAX(IsResolved) FROM PREDICTION_OPTION WHERE Chapter_ID = ?");
        $r->execute([$chapterId]);
        if ($r->fetchColumn()) done('polls', 'Resolved polls are kept because coins were paid out from them.', 'error');
        $pdo->prepare("DELETE FROM PREDICTION_OPTION WHERE Chapter_ID = ?")->execute([$chapterId]);
        done('polls', 'Poll deleted (with its predictions).');
    }

    // ════════════════ CHARACTERS ════════════════
    if ($action === 'add_character') {
        $comicId = (int)($_POST['comic_id'] ?? 0);
        $name    = mb_substr(trim($_POST['name'] ?? ''), 0, 100);
        $bio     = mb_substr(trim($_POST['biography'] ?? ''), 0, 1000);
        if (!artistOwnsComic($pdo, $comicId, $artistId)) done('characters', 'Please choose one of your comics.', 'error');
        if ($name === '') done('characters', 'Character name is required.', 'error');
        $pdo->prepare("INSERT INTO CHARACTERS (Name, Biography, Comic_ID) VALUES (?, ?, ?)")
            ->execute([$name, $bio !== '' ? $bio : null, $comicId]);
        done('characters', "Character \"{$name}\" added.");
    }

    if ($action === 'update_character') {
        $charId = (int)($_POST['character_id'] ?? 0);
        $name   = mb_substr(trim($_POST['name'] ?? ''), 0, 100);
        $bio    = mb_substr(trim($_POST['biography'] ?? ''), 0, 1000);
        if ($name === '') done('characters', 'Character name is required.', 'error');
        $pdo->prepare("
            UPDATE CHARACTERS ch JOIN COMIC c ON ch.Comic_ID = c.ID
            SET ch.Name = ?, ch.Biography = ? WHERE ch.ID = ? AND c.AID = ?
        ")->execute([$name, $bio !== '' ? $bio : null, $charId, $artistId]);
        done('characters', 'Character updated.');
    }

    if ($action === 'delete_character') {
        $charId = (int)($_POST['character_id'] ?? 0);
        $pdo->prepare("DELETE ch FROM CHARACTERS ch JOIN COMIC c ON ch.Comic_ID = c.ID WHERE ch.ID = ? AND c.AID = ?")
            ->execute([$charId, $artistId]);
        done('characters', 'Character deleted (with their votes and fan-art links).');
    }

    // ════════════════ EMOJIS ════════════════
    if ($action === 'add_emoji') {
        $name    = trim($_POST['emoji_name'] ?? '');
        $cost    = (int)($_POST['emoji_cost'] ?? 1);
        $comicId = (int)($_POST['emoji_comic'] ?? 0);
        $path    = trim($_POST['emoji_path'] ?? '');
        $hasFile = ($_FILES['emoji_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($name === '' || !$comicId)                        done('emojis', 'Emoji name and comic are required.', 'error');
        if (!preg_match('/^[a-zA-Z0-9_ \-]{1,50}$/', $name))  done('emojis', 'Emoji name may only use letters, numbers, spaces, - and _ (max 50).', 'error');
        if ($cost < 1 || $cost > 100)                         done('emojis', 'Emoji cost must be between 1 and 100 coins.', 'error');
        if (!artistOwnsComic($pdo, $comicId, $artistId))      done('emojis', 'You can only add emojis to your own comics.', 'error');

        $dup = $pdo->prepare("SELECT 1 FROM SPECIAL_EMOJI WHERE Name = ?");
        $dup->execute([$name]);
        if ($dup->fetchColumn()) done('emojis', 'An emoji with that name already exists (names are unique across the site).', 'error');

        if ($hasFile) {
            $path = saveUploadedImage($_FILES['emoji_file'], 'assets/emojis', slugify($name) . '-' . substr(uniqid(), -5), 2 * 1024 * 1024);
        } elseif ($path === '' || !is_file(__DIR__ . '/' . ltrim($path, '/'))) {
            done('emojis', 'Upload an image, or give the path of an image that already exists on the server.', 'error');
        }
        $pdo->prepare("INSERT INTO SPECIAL_EMOJI (Name, E_Value, Coin_Amount, Comic_ID) VALUES (?, ?, ?, ?)")
            ->execute([$name, ltrim($path, '/'), $cost, $comicId]);
        done('emojis', "Emoji \"{$name}\" added to the store.");
    }

    if ($action === 'delete_emoji') {
        $name = trim($_POST['emoji_name'] ?? '');
        $own = $pdo->prepare("
            SELECT se.E_Value, (SELECT COUNT(*) FROM USER_EMOJI ue WHERE ue.Emoji_Name = se.Name) AS Owners
            FROM SPECIAL_EMOJI se JOIN COMIC c ON se.Comic_ID = c.ID WHERE se.Name = ? AND c.AID = ?
        ");
        $own->execute([$name, $artistId]);
        $row = $own->fetch();
        if (!$row) done('emojis', 'You do not have permission to delete this emoji.', 'error');
        if ($row['Owners'] > 0) done('emojis', 'This emoji has already been bought, so it cannot be deleted.', 'error');
        $pdo->prepare("DELETE FROM SPECIAL_EMOJI WHERE Name = ?")->execute([$name]);
        // Remove the image only if no other emoji still uses the same file
        $shared = $pdo->prepare("SELECT COUNT(*) FROM SPECIAL_EMOJI WHERE E_Value = ?");
        $shared->execute([$row['E_Value']]);
        if (!$shared->fetchColumn() && str_starts_with($row['E_Value'], 'assets/emojis/')) @unlink(__DIR__ . '/' . $row['E_Value']);
        done('emojis', "Emoji \"{$name}\" deleted.");
    }
} catch (RuntimeException $e) {          // upload problems
    if ($pdo->inTransaction()) $pdo->rollBack();
    done($tab === 'overview' ? 'chapters' : $tab, $e->getMessage(), 'error');
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    done($tab, 'Database error: ' . $e->getMessage(), 'error');
}

// ═══════════════════ PAGE DATA (this artist only) ═══════════════════
$comics = $pdo->prepare("
    SELECT x.*,
           (SELECT GROUP_CONCAT(Genre ORDER BY Genre SEPARATOR ', ') FROM GENRE WHERE Comic_ID = x.ID) AS Genres,
           (SELECT COUNT(*) FROM CHAPTER WHERE Comic_ID = x.ID AND isPublished = TRUE)  AS Published,
           (SELECT COUNT(*) FROM CHAPTER WHERE Comic_ID = x.ID AND isPublished = FALSE) AS Drafts,
           (SELECT MAX(ChapterNumber) FROM CHAPTER WHERE Comic_ID = x.ID)                 AS LastNumber
    FROM (" . comicListSql() . " WHERE c.AID = ?) x
    ORDER BY x.ID DESC
");
$comics->execute([$artistId]);
$comics = $comics->fetchAll();
$comicPdf = [];
$p = $pdo->prepare("SELECT ID, PDF FROM COMIC WHERE AID = ?");
$p->execute([$artistId]);
foreach ($p->fetchAll() as $r) $comicPdf[$r['ID']] = !empty($r['PDF']);

$chapters = $pdo->prepare("
    SELECT ch.CHAPTER_ID, ch.ChapterNumber, ch.isPublished, ch.Date_of_Publication, ch.ContentURL, ch.Comic_ID,
           COALESCE(c.Title, c.Name) AS ComicTitle,
           (SELECT COUNT(*) FROM PREDICTION_OPTION po WHERE po.Chapter_ID = ch.CHAPTER_ID)       AS OptionCount,
           (SELECT MAX(po.IsResolved) FROM PREDICTION_OPTION po WHERE po.Chapter_ID = ch.CHAPTER_ID) AS Resolved,
           (SELECT COUNT(*) FROM PREDICTION p JOIN PREDICTION_OPTION po ON p.Option_ID = po.ID
             WHERE po.Chapter_ID = ch.CHAPTER_ID) AS Votes,
           (ch.ChapterNumber = (SELECT MAX(c2.ChapterNumber) FROM CHAPTER c2
                                WHERE c2.Comic_ID = ch.Comic_ID AND c2.isPublished = TRUE)) AS IsLatest
    FROM CHAPTER ch JOIN COMIC c ON ch.Comic_ID = c.ID
    WHERE c.AID = ?
    ORDER BY c.ID DESC, ch.ChapterNumber DESC
");
$chapters->execute([$artistId]);
$chapters = $chapters->fetchAll();
foreach ($chapters as &$ch) {
    $ch['Pages'] = count(listImages((string)$ch['ContentURL']));
}
unset($ch);

$drafts        = array_filter($chapters, fn($c) => !$c['isPublished']);
$pollable      = array_filter($chapters, fn($c) => $c['isPublished'] && $c['IsLatest'] && !$c['OptionCount']);
$openPolls     = array_filter($chapters, fn($c) => $c['OptionCount'] && !$c['Resolved']);
$chaptersWithPolls = array_filter($chapters, fn($c) => $c['OptionCount']);

$characters = $pdo->prepare("
    SELECT ch.*, COALESCE(c.Title, c.Name) AS ComicTitle,
           (SELECT COUNT(*) FROM VOTE_FOR v WHERE v.Character_ID = ch.ID) AS Votes
    FROM CHARACTERS ch JOIN COMIC c ON ch.Comic_ID = c.ID
    WHERE c.AID = ? ORDER BY c.ID DESC, ch.Name
");
$characters->execute([$artistId]);
$characters = $characters->fetchAll();

$emojis = $pdo->prepare("
    SELECT se.*, COALESCE(c.Title, c.Name) AS ComicTitle,
           (SELECT COUNT(*) FROM USER_EMOJI ue WHERE ue.Emoji_Name = se.Name) AS OwnersCount
    FROM SPECIAL_EMOJI se JOIN COMIC c ON se.Comic_ID = c.ID
    WHERE c.AID = ? ORDER BY c.ID DESC, se.Coin_Amount, se.Name
");
$emojis->execute([$artistId]);
$emojis = $emojis->fetchAll();

$stats = [
    'Comics'         => count($comics),
    'Published'      => count($chapters) - count($drafts),
    'Drafts'         => count($drafts),
    'Open polls'     => count($openPolls),
    'Views'          => array_sum(array_column($comics, 'ViewCount')),
    'Emojis sold'    => array_sum(array_column($emojis, 'OwnersCount')),
];

$pageTitle = 'Artist Dashboard';
require_once 'header.php';

function myComicSelect(array $comics, string $name = 'comic_id', string $id = ''): void { ?>
    <select name="<?php echo $name; ?>" class="form-control" <?php echo $id ? 'id="' . $id . '"' : ''; ?> required>
        <option value="">— Select comic —</option>
        <?php foreach ($comics as $c): ?>
            <option value="<?php echo (int)$c['ID']; ?>" data-next="<?php echo (int)$c['LastNumber'] + 1; ?>"><?php echo h($c['Title'] ?: $c['Name']); ?></option>
        <?php endforeach; ?>
    </select>
<?php }

function genreCheckboxes(array $common, array $selected): void {
    $all = array_unique(array_merge($common, $selected));
    sort($all); ?>
    <div class="check-grid">
        <?php foreach ($all as $g): ?>
            <label class="check-pill"><input type="checkbox" name="genres[]" value="<?php echo h($g); ?>" <?php echo in_array($g, $selected, true) ? 'checked' : ''; ?>> <?php echo h($g); ?></label>
        <?php endforeach; ?>
    </div>
    <input type="text" name="genres_other" class="form-control" placeholder="Other genres, comma separated (optional)" style="margin-top:8px">
<?php }
?>

<div class="dash-head">
    <div>
        <h1 class="page-title">🛠 Artist Dashboard</h1>
        <p class="muted">Signed in as <strong><?php echo h($_SESSION['user_name']); ?></strong> · you can only manage your own comics.</p>
    </div>
    <div class="btn-row">
        <a href="artist_go_live.php" class="btn btn-live-header btn-sm">🎥 Streams</a>
        <a href="index.php" class="btn btn-outline btn-sm">← Back to Site</a>
    </div>
</div>

<div class="tabs dash-tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <a href="admin_panel.php?tab=<?php echo $key; ?>" class="tab <?php echo $tab === $key ? 'active' : ''; ?>"><?php echo $label; ?>
            <?php if ($key === 'polls' && $openPolls): ?><span class="tab-count"><?php echo count($openPolls); ?></span><?php endif; ?>
            <?php if ($key === 'chapters' && $drafts): ?><span class="tab-count"><?php echo count($drafts); ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if (!$comics && $tab !== 'comics'): ?>
    <div class="alert alert-info alert-sticky">👋 Welcome! You don't have any comics yet. <a href="admin_panel.php?tab=comics">Create your first comic</a> to get started.</div>
<?php endif; ?>

<?php /* ═════════════ OVERVIEW ═════════════ */ if ($tab === 'overview'): ?>
    <div class="stat-grid">
        <?php foreach ($stats as $label => $value): ?>
            <div class="stat-tile"><strong><?php echo number_format($value); ?></strong><span><?php echo $label; ?></span></div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h3 class="card-title">How the prediction game works</h3>
        <ol class="howto">
            <li><strong>Upload</strong> a chapter (Chapters tab) — publish it right away or keep it as a draft.</li>
            <li><strong>Create a poll</strong> with 4 options on your latest published chapter (Predictions tab). Readers vote on what happens next.</li>
            <li>When the next chapter is ready, <strong>publish it and pick the correct option</strong>. Winners get 1 coin, everyone gets a notification.</li>
            <li>Readers spend coins on your comic's <strong>special emojis</strong> (Emojis tab) and use them in the community forum.</li>
        </ol>
    </div>

    <div class="card">
        <h3 class="card-title">Your comics</h3>
        <?php foreach ($comics as $c): ?>
            <div class="list-row">
                <div class="list-main">
                    <img src="<?php echo h(getThumbnail($c['HardCopyLink'], $c['Name'])); ?>" alt="" class="mini-cover">
                    <div>
                        <a href="comic.php?id=<?php echo (int)$c['ID']; ?>"><strong><?php echo h($c['Title'] ?: $c['Name']); ?></strong></a>
                        <div class="muted small"><?php echo (int)$c['Published']; ?> published · <?php echo (int)$c['Drafts']; ?> drafts · 👁 <?php echo number_format((int)$c['ViewCount']); ?> views (<?php echo (int)$c['WeeklyViews']; ?> this week) · ★ <?php echo number_format((float)$c['AvgRating'], 1); ?></div>
                    </div>
                </div>
                <div class="btn-row">
                    <a href="create_guide.php?comic_id=<?php echo (int)$c['ID']; ?>" class="btn btn-outline btn-xs"><?php echo !empty($comicPdf[$c['ID']]) ? '✏️ Guide' : '📖 Add guide'; ?></a>
                    <a href="community.php?id=<?php echo (int)$c['ID']; ?>" class="btn btn-outline btn-xs">💬 Forum</a>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$comics): ?><p class="muted">No comics yet.</p><?php endif; ?>
    </div>
<?php endif; ?>

<?php /* ═════════════ COMICS ═════════════ */ if ($tab === 'comics'): ?>
    <div class="card">
        <h3 class="card-title">➕ Create a new comic</h3>
        <form method="POST" action="admin_panel.php?tab=comics" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create_comic">
            <div class="form-row">
                <div class="form-group">
                    <label>Name * <small class="form-hint-inline">(used for file names; can't be changed later)</small></label>
                    <input type="text" name="name" class="form-control" maxlength="200" required placeholder="e.g. Solo Leveling">
                </div>
                <div class="form-group">
                    <label>Display title <small class="form-hint-inline">(optional, e.g. SPY×FAMILY)</small></label>
                    <input type="text" name="title" class="form-control" maxlength="200">
                </div>
            </div>
            <div class="form-group">
                <label>Synopsis</label>
                <textarea name="synopsis" class="form-control" rows="3" maxlength="1000"></textarea>
            </div>
            <div class="form-group">
                <label>Genres</label>
                <?php genreCheckboxes($commonGenres, []); ?>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Hard copy shop link <small class="form-hint-inline">(optional)</small></label>
                    <input type="url" name="hard_copy_link" class="form-control" maxlength="500" placeholder="https://www.amazon.com/...">
                </div>
                <div class="form-group">
                    <label>Cover image <small class="form-hint-inline">(JPG/PNG/WebP, 3:4, max 5 MB)</small></label>
                    <input type="file" name="cover" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Create Comic</button>
        </form>
    </div>

    <div class="card">
        <h3 class="card-title">📚 Your comics</h3>
        <?php foreach ($comics as $c):
            $cg = $c['Genres'] ? explode(', ', $c['Genres']) : []; ?>
            <details class="edit-block">
                <summary>
                    <img src="<?php echo h(getThumbnail($c['HardCopyLink'], $c['Name'])); ?>" alt="" class="mini-cover">
                    <span><strong><?php echo h($c['Title'] ?: $c['Name']); ?></strong>
                        <span class="muted small"> · <?php echo h($c['Genres'] ?: 'no genres'); ?></span></span>
                    <span class="summary-hint">Edit ▾</span>
                </summary>
                <form method="POST" action="admin_panel.php?tab=comics" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_comic">
                    <input type="hidden" name="comic_id" value="<?php echo (int)$c['ID']; ?>">
                    <div class="form-row">
                        <div class="form-group"><label>Name</label><input type="text" class="form-control" value="<?php echo h($c['Name']); ?>" disabled></div>
                        <div class="form-group"><label>Display title</label><input type="text" name="title" class="form-control" maxlength="200" value="<?php echo h($c['Title']); ?>"></div>
                    </div>
                    <div class="form-group"><label>Synopsis</label><textarea name="synopsis" class="form-control" rows="3" maxlength="1000"><?php echo h($c['Synopsis']); ?></textarea></div>
                    <div class="form-group"><label>Genres</label><?php genreCheckboxes($commonGenres, $cg); ?></div>
                    <div class="form-row">
                        <div class="form-group"><label>Hard copy shop link</label><input type="url" name="hard_copy_link" class="form-control" maxlength="500" value="<?php echo h(hardCopyUrl($c['HardCopyLink'])); ?>" placeholder="https://..."></div>
                        <div class="form-group"><label>Replace cover</label><input type="file" name="cover" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif"></div>
                    </div>
                    <div class="btn-row">
                        <button type="submit" class="btn btn-primary btn-sm">Save changes</button>
                        <a href="comic.php?id=<?php echo (int)$c['ID']; ?>" class="btn btn-outline btn-sm">View page</a>
                    </div>
                </form>
                <form method="POST" action="admin_panel.php?tab=comics" class="danger-zone"
                      onsubmit="return confirm('Delete this comic and ALL its chapters, polls, ratings, posts, emojis and streams? This cannot be undone.')">
                    <input type="hidden" name="action" value="delete_comic">
                    <input type="hidden" name="comic_id" value="<?php echo (int)$c['ID']; ?>">
                    <button type="submit" class="btn btn-danger btn-xs">🗑 Delete comic</button>
                </form>
            </details>
        <?php endforeach; ?>
        <?php if (!$comics): ?><p class="muted">You haven't created any comics yet.</p><?php endif; ?>
    </div>
<?php endif; ?>

<?php /* ═════════════ CHAPTERS ═════════════ */ if ($tab === 'chapters' && $comics): ?>
    <div class="card">
        <h3 class="card-title">⬆️ Upload a new chapter</h3>
        <form method="POST" action="admin_panel.php?tab=chapters" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_chapter">
            <div class="form-row">
                <div class="form-group"><label>Comic</label><?php myComicSelect($comics, 'comic_id', 'chapterComic'); ?></div>
                <div class="form-group"><label>Chapter number</label><input type="number" name="chapter_number" id="chapterNumber" class="form-control" min="1" value="1" required></div>
            </div>
            <div class="form-group">
                <label>Page images <small class="form-hint-inline">(select all pages at once; they are ordered by file name, e.g. 01.jpg, 02.jpg…)</small></label>
                <input type="file" name="pages[]" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif" multiple required>
                <small class="form-hint">Max 10 MB per image. PHP accepts up to <?php echo (int)ini_get('max_file_uploads'); ?> files and <?php echo h(ini_get('post_max_size')); ?> per upload — add more pages to the chapter afterwards if needed.</small>
            </div>
            <label class="check-inline"><input type="checkbox" name="publish_now" value="1"> Publish immediately <span class="muted small">(leave unticked to keep it as a draft — use the Predictions tab to publish and resolve a poll together)</span></label>
            <button type="submit" class="btn btn-primary" style="margin-top:14px">Upload Chapter</button>
        </form>
    </div>

    <div class="card">
        <h3 class="card-title">📄 Your chapters</h3>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Comic</th><th>Chapter</th><th>Status</th><th>Pages</th><th>Poll</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($chapters as $ch): ?>
                <tr>
                    <td><?php echo h($ch['ComicTitle']); ?></td>
                    <td>Chapter <?php echo (int)$ch['ChapterNumber']; ?></td>
                    <td><?php echo $ch['isPublished']
                        ? '<span class="badge badge-green">Published</span> <span class="muted small">' . ($ch['Date_of_Publication'] ? date('M j, Y', strtotime($ch['Date_of_Publication'])) : '') . '</span>'
                        : '<span class="badge badge-gray">Draft</span>'; ?></td>
                    <td><?php echo (int)$ch['Pages']; ?></td>
                    <td><?php echo !$ch['OptionCount'] ? '<span class="muted">—</span>'
                        : ($ch['Resolved'] ? '<span class="badge badge-green">Resolved</span>' : '<span class="badge badge-orange">Open · ' . (int)$ch['Votes'] . ((int)$ch['Votes'] === 1 ? ' vote' : ' votes') . '</span>'); ?></td>
                    <td class="actions-cell">
                        <a href="reader.php?id=<?php echo (int)$ch['CHAPTER_ID']; ?>" class="btn btn-outline btn-xs" target="_blank"><?php echo $ch['isPublished'] ? 'Read' : 'Preview'; ?></a>
                        <details class="inline-details">
                            <summary class="btn btn-outline btn-xs">+ Pages</summary>
                            <form method="POST" action="admin_panel.php?tab=chapters" enctype="multipart/form-data" class="inline-upload">
                                <input type="hidden" name="action" value="add_pages">
                                <input type="hidden" name="chapter_id" value="<?php echo (int)$ch['CHAPTER_ID']; ?>">
                                <input type="file" name="pages[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple required>
                                <button type="submit" class="btn btn-primary btn-xs">Upload</button>
                            </form>
                        </details>
                        <?php if (!$ch['isPublished']): ?>
                            <form method="POST" action="admin_panel.php?tab=chapters" class="inline-form" onsubmit="return confirm('Delete this draft chapter and its page images?')">
                                <input type="hidden" name="action" value="delete_chapter">
                                <input type="hidden" name="chapter_id" value="<?php echo (int)$ch['CHAPTER_ID']; ?>">
                                <button type="submit" class="btn btn-danger btn-xs">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$chapters): ?><tr><td colspan="6" class="muted">No chapters yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>

<?php /* ═════════════ CHARACTERS ═════════════ */ if ($tab === 'characters' && $comics): ?>
    <div class="card">
        <h3 class="card-title">➕ Add a character</h3>
        <form method="POST" action="admin_panel.php?tab=characters">
            <input type="hidden" name="action" value="add_character">
            <div class="form-row">
                <div class="form-group"><label>Comic</label><?php myComicSelect($comics); ?></div>
                <div class="form-group"><label>Name</label><input type="text" name="name" class="form-control" maxlength="100" required></div>
            </div>
            <div class="form-group"><label>Biography</label><textarea name="biography" class="form-control" rows="3" maxlength="1000"></textarea></div>
            <button type="submit" class="btn btn-primary">Add Character</button>
        </form>
    </div>

    <div class="card">
        <h3 class="card-title">🧑 Your characters</h3>
        <?php foreach ($characters as $ch): ?>
            <details class="edit-block">
                <summary>
                    <span class="avatar"><?php echo h(mb_substr($ch['Name'], 0, 1)); ?></span>
                    <span><strong><?php echo h($ch['Name']); ?></strong> <span class="muted small">· <?php echo h($ch['ComicTitle']); ?> · ❤ <?php echo (int)$ch['Votes']; ?> votes</span></span>
                    <span class="summary-hint">Edit ▾</span>
                </summary>
                <form method="POST" action="admin_panel.php?tab=characters">
                    <input type="hidden" name="action" value="update_character">
                    <input type="hidden" name="character_id" value="<?php echo (int)$ch['ID']; ?>">
                    <div class="form-group"><label>Name</label><input type="text" name="name" class="form-control" maxlength="100" value="<?php echo h($ch['Name']); ?>" required></div>
                    <div class="form-group"><label>Biography</label><textarea name="biography" class="form-control" rows="3" maxlength="1000"><?php echo h($ch['Biography']); ?></textarea></div>
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                </form>
                <form method="POST" action="admin_panel.php?tab=characters" class="danger-zone" onsubmit="return confirm('Delete this character? Their votes and fan-art links are removed too.')">
                    <input type="hidden" name="action" value="delete_character">
                    <input type="hidden" name="character_id" value="<?php echo (int)$ch['ID']; ?>">
                    <button type="submit" class="btn btn-danger btn-xs">🗑 Delete character</button>
                </form>
            </details>
        <?php endforeach; ?>
        <?php if (!$characters): ?><p class="muted">No characters yet.</p><?php endif; ?>
    </div>
<?php endif; ?>

<?php /* ═════════════ POLLS ═════════════ */ if ($tab === 'polls' && $comics): ?>
    <div class="card">
        <h3 class="card-title">📊 Create Prediction Poll</h3>
        <p class="muted small" style="margin-bottom:16px">Polls go on the <strong>latest published chapter</strong> of a comic. Readers predict what happens in the NEXT chapter. Exactly 4 options.</p>
        <?php if (!$pollable): ?>
            <div class="alert alert-info alert-sticky">None of your comics can take a new poll right now — the latest published chapter of each comic already has one. Publish a new chapter first.</div>
        <?php else: ?>
        <form method="POST" action="admin_panel.php?tab=polls">
            <input type="hidden" name="action" value="create_poll">
            <div class="form-group">
                <label>Chapter</label>
                <select name="chapter_id" class="form-control" required>
                    <option value="">— Select a chapter —</option>
                    <?php foreach ($pollable as $ch): ?>
                        <option value="<?php echo (int)$ch['CHAPTER_ID']; ?>"><?php echo h($ch['ComicTitle']); ?> — Chapter <?php echo (int)$ch['ChapterNumber']; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <?php for ($i = 1; $i <= 4; $i++): ?>
                    <div class="form-group"><label>Option <?php echo $i; ?></label><input type="text" name="options[]" class="form-control" maxlength="500" required placeholder="e.g. Anya reads the villain's mind"></div>
                <?php endfor; ?>
            </div>
            <button type="submit" class="btn btn-primary">Create Poll</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3 class="card-title">🚀 Publish Chapter &amp; Resolve Poll</h3>
        <p class="muted small" style="margin-bottom:16px">Publishing the next chapter reveals the answer to the previous chapter's poll. Pick the correct option — coins are paid and notifications sent automatically.</p>
        <?php if (!$drafts): ?>
            <div class="alert alert-info alert-sticky">You have no draft chapters. <a href="admin_panel.php?tab=chapters">Upload one</a> first.</div>
        <?php else: ?>
        <form method="POST" action="admin_panel.php?tab=polls" id="publishForm"
              onsubmit="return confirm('Publish this chapter and pay out coins now? This cannot be undone.')">
            <input type="hidden" name="action" value="publish_and_resolve">
            <div class="form-row">
                <div class="form-group">
                    <label>Chapter to publish</label>
                    <select name="chapter_to_publish" id="publishSelect" class="form-control" required>
                        <option value="">— Select a draft —</option>
                        <?php foreach (array_reverse($drafts) as $ch): ?>
                            <option value="<?php echo (int)$ch['CHAPTER_ID']; ?>" data-comic="<?php echo (int)$ch['Comic_ID']; ?>"><?php echo h($ch['ComicTitle']); ?> — Chapter <?php echo (int)$ch['ChapterNumber']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Poll to resolve</label>
                    <select name="poll_chapter_id" id="pollChapterSelect" class="form-control">
                        <option value="">— No poll to resolve —</option>
                        <?php foreach ($openPolls as $ch): ?>
                            <option value="<?php echo (int)$ch['CHAPTER_ID']; ?>" data-comic="<?php echo (int)$ch['Comic_ID']; ?>"><?php echo h($ch['ComicTitle']); ?> — Chapter <?php echo (int)$ch['ChapterNumber']; ?> (<?php echo (int)$ch['Votes']; ?> vote<?php echo (int)$ch['Votes'] === 1 ? '' : 's'; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div id="optionsContainer" class="form-group" style="display:none">
                <label>Which option was correct?</label>
                <select name="correct_option_id" id="correctOptionSelect" class="form-control"></select>
            </div>
            <button type="submit" class="btn btn-secondary">Publish Chapter &amp; Distribute Coins</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="card">
        <h3 class="card-title">📋 Poll overview</h3>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Comic</th><th>Chapter</th><th>Status</th><th>Votes</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($chaptersWithPolls as $ch): ?>
                <tr>
                    <td><?php echo h($ch['ComicTitle']); ?></td>
                    <td>Chapter <?php echo (int)$ch['ChapterNumber']; ?></td>
                    <td><?php echo $ch['Resolved'] ? '<span class="badge badge-green">Resolved ✓</span>' : '<span class="badge badge-orange">Open</span>'; ?></td>
                    <td><?php echo (int)$ch['Votes']; ?></td>
                    <td>
                        <?php if (!$ch['Resolved']): ?>
                            <form method="POST" action="admin_panel.php?tab=polls" class="inline-form" onsubmit="return confirm('Delete this poll and all its predictions?')">
                                <input type="hidden" name="action" value="delete_poll">
                                <input type="hidden" name="chapter_id" value="<?php echo (int)$ch['CHAPTER_ID']; ?>">
                                <button type="submit" class="btn btn-danger btn-xs">Delete poll</button>
                            </form>
                        <?php else: ?><span class="muted">—</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$chaptersWithPolls): ?><tr><td colspan="5" class="muted">No polls yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>

<?php /* ═════════════ EMOJIS ═════════════ */ if ($tab === 'emojis' && $comics): ?>
    <div class="card">
        <h3 class="card-title">➕ Add a special emoji</h3>
        <form method="POST" action="admin_panel.php?tab=emojis" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_emoji">
            <div class="form-row form-row-3">
                <div class="form-group"><label>Comic</label><?php myComicSelect($comics, 'emoji_comic'); ?></div>
                <div class="form-group">
                    <label>Emoji name</label>
                    <input type="text" name="emoji_name" class="form-control" maxlength="50" pattern="[a-zA-Z0-9_ \-]+" required
                           title="Letters, numbers, spaces, hyphens, underscores only" placeholder="e.g. Anya Heh">
                </div>
                <div class="form-group"><label>Coin cost</label><input type="number" name="emoji_cost" class="form-control" min="1" max="100" value="1" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Emoji image <small class="form-hint-inline">(square PNG/WebP, max 2 MB)</small></label><input type="file" name="emoji_file" class="form-control" accept="image/png,image/webp,image/jpeg,image/gif"></div>
                <div class="form-group"><label>…or path of an image already on the server</label><input type="text" name="emoji_path" class="form-control" placeholder="assets/emojis/anya-heh.webp"></div>
            </div>
            <button type="submit" class="btn btn-success">Add Emoji</button>
        </form>
    </div>

    <div class="card">
        <h3 class="card-title">🎭 Your emojis</h3>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Preview</th><th>Name</th><th>Comic</th><th>Cost</th><th>Owners</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($emojis as $em): ?>
                <tr>
                    <td><img src="<?php echo h($em['E_Value']); ?>" alt="<?php echo h($em['Name']); ?>" class="emoji-preview-sm" onerror="this.style.opacity='0.2'"></td>
                    <td><strong><?php echo h($em['Name']); ?></strong></td>
                    <td><?php echo h($em['ComicTitle']); ?></td>
                    <td class="text-amber">🪙 <?php echo (int)$em['Coin_Amount']; ?></td>
                    <td><?php echo (int)$em['OwnersCount']; ?> reader(s)</td>
                    <td>
                        <?php if ((int)$em['OwnersCount'] === 0): ?>
                            <form method="POST" action="admin_panel.php?tab=emojis" class="inline-form" onsubmit="return confirm('Delete this emoji?')">
                                <input type="hidden" name="action" value="delete_emoji">
                                <input type="hidden" name="emoji_name" value="<?php echo h($em['Name']); ?>">
                                <button type="submit" class="btn btn-danger btn-xs">Delete</button>
                            </form>
                        <?php else: ?>
                            <span class="badge badge-gray" title="Cannot delete — readers own this emoji">Owned</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$emojis): ?><tr><td colspan="6" class="muted">No emojis yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>

<script>
// Suggest the next chapter number when a comic is picked
const chapterComic = document.getElementById('chapterComic');
if (chapterComic) chapterComic.addEventListener('change', () => {
    const opt = chapterComic.selectedOptions[0];
    if (opt && opt.dataset.next) document.getElementById('chapterNumber').value = opt.dataset.next;
});

// Publish & resolve: only show polls of the same comic, and load their options
const publishSelect = document.getElementById('publishSelect');
const pollSelect    = document.getElementById('pollChapterSelect');
function filterPolls() {
    if (!publishSelect || !pollSelect) return;
    const comic = publishSelect.selectedOptions[0]?.dataset.comic;
    let firstMatch = '';
    [...pollSelect.options].forEach(o => {
        if (!o.value) return;
        const match = !comic || o.dataset.comic === comic;
        o.hidden = !match;
        if (match && !firstMatch && comic) firstMatch = o.value;
    });
    if (pollSelect.selectedOptions[0]?.hidden || (comic && !pollSelect.value)) pollSelect.value = firstMatch;
    loadOptions(pollSelect.value);
}
function loadOptions(chapterId) {
    const container = document.getElementById('optionsContainer');
    const select    = document.getElementById('correctOptionSelect');
    if (!chapterId) { container.style.display = 'none'; select.required = false; select.innerHTML = ''; return; }
    fetch('get_poll_options.php?chapter_id=' + encodeURIComponent(chapterId))
        .then(r => r.json())
        .then(options => {
            select.innerHTML = '<option value="">— Select the correct option —</option>';
            options.forEach(opt => {
                const o = document.createElement('option');
                o.value = opt.ID;
                o.textContent = `Option ${opt.Option_Number}: ${opt.OptionText} (${opt.Votes} vote${opt.Votes == 1 ? '' : 's'})`;
                select.appendChild(o);
            });
            select.required = true;
            container.style.display = 'block';
        })
        .catch(() => { container.style.display = 'none'; });
}
if (publishSelect) publishSelect.addEventListener('change', filterPolls);
if (pollSelect) pollSelect.addEventListener('change', () => loadOptions(pollSelect.value));
</script>

<?php require_once 'footer.php'; ?>
