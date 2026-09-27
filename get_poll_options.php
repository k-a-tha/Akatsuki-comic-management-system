<?php
// get_poll_options.php — AJAX helper for admin_panel.php: poll options of one of the artist's chapters
require_once 'db.php';
header('Content-Type: application/json');

$chapterId = (int)($_GET['chapter_id'] ?? 0);
if (!isArtistLoggedIn() || !$chapterId || !artistOwnsChapter($pdo, $chapterId, currentUserId())) {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT po.ID, po.Option_Number, po.OptionText, COUNT(p.ID) AS Votes
    FROM PREDICTION_OPTION po
    LEFT JOIN PREDICTION p ON p.Option_ID = po.ID
    WHERE po.Chapter_ID = ?
    GROUP BY po.ID, po.Option_Number, po.OptionText
    ORDER BY po.Option_Number
");
$stmt->execute([$chapterId]);
echo json_encode($stmt->fetchAll());
