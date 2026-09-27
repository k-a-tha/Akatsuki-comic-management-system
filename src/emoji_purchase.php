<?php
// emoji_purchase.php — AJAX endpoint (JSON only), called from emoji_store.php
require_once 'db.php';
header('Content-Type: application/json');

function reply(bool $ok, string $message, array $extra = []): void {
    echo json_encode(['success' => $ok, 'message' => $message] + $extra);
    exit;
}

if (!isUserLoggedIn())                     reply(false, 'You must be logged in as a reader to purchase emojis.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(false, 'Invalid request method.');

$emojiName = trim($_POST['emoji_name'] ?? '');
$comicId   = (int)($_POST['comic_id'] ?? 0);
$userId    = currentUserId();
if ($emojiName === '' || !$comicId) reply(false, 'Invalid request parameters.');

try {
    $pdo->beginTransaction();

    // Lock the buyer's row: the balance check and the deduction happen as one unit,
    // so double-clicking "Buy" can never spend the same coins twice.
    $pdo->prepare("SELECT ID FROM USERS WHERE ID = ? FOR UPDATE")->execute([$userId]);

    $s = $pdo->prepare("SELECT Name, Coin_Amount FROM SPECIAL_EMOJI WHERE Name = ? AND Comic_ID = ?");
    $s->execute([$emojiName, $comicId]);
    $emoji = $s->fetch();
    if (!$emoji) {
        $pdo->rollBack();
        reply(false, 'Emoji not found for this comic.');
    }
    $cost = (int)$emoji['Coin_Amount'];

    $s = $pdo->prepare("SELECT 1 FROM USER_EMOJI WHERE UID = ? AND Emoji_Name = ?");
    $s->execute([$userId, $emojiName]);
    if ($s->fetchColumn()) {
        $pdo->rollBack();
        reply(false, 'You already own this emoji!');
    }

    $balance = coinBalance($pdo, $userId);
    if ($balance < $cost) {
        $pdo->rollBack();
        reply(false, "Not enough coins! You have {$balance} but need {$cost}.");
    }

    // Deduct (negative transaction) and record ownership
    $pdo->prepare("INSERT INTO COIN_TRANSACTION (Amount, Time_stamp, Reason, UID) VALUES (?, NOW(), ?, ?)")
        ->execute([-$cost, "Bought emoji: {$emojiName}", $userId]);
    $txId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO USER_EMOJI (UID, Emoji_Name, Purchase_Date, Coin_T_ID) VALUES (?, ?, NOW(), ?)")
        ->execute([$userId, $emojiName, $txId]);

    $pdo->commit();
    reply(true, "🎉 You unlocked \"{$emojiName}\"! Use it in community posts.", [
        'new_balance' => $balance - $cost,
        'emoji_name'  => $emojiName,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    reply(false, 'Transaction failed. Please try again.');
}
