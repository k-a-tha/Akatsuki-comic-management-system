<?php
// prediction_submit.php — AJAX endpoint (JSON only), called from the poll on comic.php
require_once 'db.php';
header('Content-Type: application/json');

function reply(bool $ok, string $message, array $extra = []): void {
    echo json_encode(['success' => $ok, 'message' => $message] + $extra);
    exit;
}

if (!isUserLoggedIn())                        reply(false, 'You must be logged in as a reader to make predictions.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    reply(false, 'Invalid request method.');

$optionId = (int)($_POST['option_id'] ?? 0);
$userId   = currentUserId();
if (!$optionId) reply(false, 'Please select an option before confirming.');

$stmt = $pdo->prepare("
    SELECT po.ID, po.Chapter_ID, po.IsResolved, po.OptionText,
           ch.Comic_ID, ch.ChapterNumber, c.Name AS ComicName, c.Title AS ComicTitle
    FROM PREDICTION_OPTION po
    JOIN CHAPTER ch ON po.Chapter_ID = ch.CHAPTER_ID
    JOIN COMIC   c  ON ch.Comic_ID   = c.ID
    WHERE po.ID = ?
");
$stmt->execute([$optionId]);
$option = $stmt->fetch();

if (!$option)              reply(false, 'The selected option does not exist.');
if ($option['IsResolved']) reply(false, 'This prediction poll has already closed.');

// Only the latest published chapter's poll is open
$latest = $pdo->prepare("
    SELECT CHAPTER_ID FROM CHAPTER
    WHERE Comic_ID = ? AND isPublished = TRUE
    ORDER BY ChapterNumber DESC LIMIT 1
");
$latest->execute([$option['Comic_ID']]);
if ((int)$latest->fetchColumn() !== (int)$option['Chapter_ID']) {
    reply(false, 'Predictions are only available for the latest chapter.');
}

try {
    $pdo->beginTransaction();

    // Lock this user's row so two quick clicks cannot both get through
    $pdo->prepare("SELECT ID FROM USERS WHERE ID = ? FOR UPDATE")->execute([$userId]);

    // One prediction per user per CHAPTER (across all 4 options)
    $check = $pdo->prepare("
        SELECT 1 FROM PREDICTION p JOIN PREDICTION_OPTION po ON p.Option_ID = po.ID
        WHERE po.Chapter_ID = ? AND p.UID = ?
    ");
    $check->execute([$option['Chapter_ID'], $userId]);
    if ($check->fetchColumn()) {
        $pdo->rollBack();
        reply(false, 'You have already made a prediction for this chapter. It cannot be changed.');
    }

    $pdo->prepare("INSERT INTO PREDICTION (Option_ID, UID, TimeStamp) VALUES (?, ?, NOW())")
        ->execute([$optionId, $userId]);

    $comicTitle = $option['ComicTitle'] ?: $option['ComicName'];
    notify($pdo, $userId,
        "Prediction locked for {$comicTitle} Chapter {$option['ChapterNumber']}: \"{$option['OptionText']}\". Results arrive when the next chapter drops.",
        'prediction_made');

    $pdo->commit();
    reply(true, "Prediction locked! You chose: \"{$option['OptionText']}\". You cannot change this.", [
        'option_id'  => $optionId,
        'comic'      => $comicTitle,
        'chapter_no' => (int)$option['ChapterNumber'],
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    reply(false, 'Failed to save your prediction. Please try again.');
}
