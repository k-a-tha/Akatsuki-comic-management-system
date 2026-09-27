<?php
// profile.php — a reader's coins, predictions, emojis and genre preference
$pageTitle = 'My Profile';
require_once 'db.php';
requireUser();
$uid = currentUserId();

$genreOptions = array_unique(array_merge(
    ['Action', 'Adventure', 'Comedy', 'Drama', 'Fantasy', 'Horror', 'Romance', 'Slice of Life'],
    $pdo->query("SELECT DISTINCT Genre FROM GENRE")->fetchAll(PDO::FETCH_COLUMN)
));
sort($genreOptions);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_pref') {
    $pref = in_array($_POST['genre_pref'] ?? '', $genreOptions, true) ? $_POST['genre_pref'] : null;
    $pdo->prepare("UPDATE USERS SET GenrePref = ? WHERE ID = ?")->execute([$pref, $uid]);
    setFlash($pref ? "Favourite genre set to {$pref}. Your recommendations are updated." : 'Favourite genre cleared.');
    redirect('profile.php');
}

$me = $pdo->prepare("SELECT Name, Email, Gender, GenrePref FROM USERS WHERE ID = ?");
$me->execute([$uid]);
$me = $me->fetch();

$balance = coinBalance($pdo, $uid);

$tx = $pdo->prepare("SELECT Amount, Time_stamp, Reason FROM COIN_TRANSACTION WHERE UID = ? ORDER BY Time_stamp DESC, ID DESC LIMIT 20");
$tx->execute([$uid]);
$transactions = $tx->fetchAll();

$pred = $pdo->prepare("
    SELECT p.TimeStamp, po.OptionText, po.IsCorrect, po.IsResolved, ch.ChapterNumber,
           c.ID AS ComicID, COALESCE(c.Title, c.Name) AS ComicTitle
    FROM PREDICTION p
    JOIN PREDICTION_OPTION po ON p.Option_ID = po.ID
    JOIN CHAPTER ch ON po.Chapter_ID = ch.CHAPTER_ID
    JOIN COMIC c ON ch.Comic_ID = c.ID
    WHERE p.UID = ?
    ORDER BY p.TimeStamp DESC
");
$pred->execute([$uid]);
$predictions = $pred->fetchAll();
$resolved    = array_filter($predictions, fn($p) => $p['IsResolved']);
$wins        = array_filter($resolved, fn($p) => $p['IsCorrect']);

$em = $pdo->prepare("
    SELECT se.Name, se.E_Value, ue.Purchase_Date, c.ID AS ComicID, COALESCE(c.Title, c.Name) AS ComicTitle
    FROM USER_EMOJI ue JOIN SPECIAL_EMOJI se ON ue.Emoji_Name = se.Name JOIN COMIC c ON se.Comic_ID = c.ID
    WHERE ue.UID = ? ORDER BY ue.Purchase_Date DESC
");
$em->execute([$uid]);
$myEmojis = $em->fetchAll();

$counts = $pdo->prepare("
    SELECT (SELECT COUNT(*) FROM RATING     WHERE UID = ?) AS Ratings,
           (SELECT COUNT(*) FROM FORUM_POST WHERE UID = ?) AS Posts,
           (SELECT COUNT(*) FROM FANART     WHERE UID = ?) AS Fanarts,
           (SELECT COUNT(*) FROM VOTE_FOR   WHERE UID = ?) AS Votes
");
$counts->execute([$uid, $uid, $uid, $uid]);
$counts = $counts->fetch();

require_once 'header.php';
?>

<div class="profile-head card">
    <div class="avatar avatar-lg"><?php echo h(mb_substr($me['Name'], 0, 1)); ?></div>
    <div class="profile-info">
        <h1><?php echo h($me['Name']); ?></h1>
        <p class="muted"><?php echo h($me['Email']); ?></p>
        <div class="profile-stats">
            <span><strong><?php echo (int)$counts['Ratings']; ?></strong> ratings</span>
            <span><strong><?php echo (int)$counts['Posts']; ?></strong> posts</span>
            <span><strong><?php echo (int)$counts['Fanarts']; ?></strong> fan arts</span>
            <span><strong><?php echo (int)$counts['Votes']; ?></strong> character votes</span>
            <span><strong><?php echo count($wins); ?>/<?php echo count($resolved); ?></strong> correct predictions</span>
        </div>
    </div>
    <div class="store-balance-box">
        <span class="coin-big">🪙</span>
        <div><div class="label">Balance</div><div class="amount"><?php echo $balance; ?> coins</div></div>
    </div>
</div>

<div class="profile-grid">
    <div>
        <div class="card">
            <h3 class="card-title">🔮 My Predictions</h3>
            <?php if (!$predictions): ?>
                <p class="muted">No predictions yet. Open a comic and vote in its poll!</p>
            <?php endif; ?>
            <?php foreach ($predictions as $p):
                $state = !$p['IsResolved'] ? ['pill p-upcoming', 'Pending'] : ($p['IsCorrect'] ? ['pill p-win', '+1 coin'] : ['pill p-past', 'Missed']); ?>
                <div class="list-row">
                    <div>
                        <a href="comic.php?id=<?php echo (int)$p['ComicID']; ?>"><strong><?php echo h($p['ComicTitle']); ?></strong></a>
                        · Chapter <?php echo (int)$p['ChapterNumber']; ?>
                        <div class="muted small">“<?php echo h($p['OptionText']); ?>”</div>
                    </div>
                    <span class="<?php echo $state[0]; ?>"><?php echo $state[1]; ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <h3 class="card-title">🪙 Coin History</h3>
            <?php if (!$transactions): ?>
                <p class="muted">No coin activity yet.</p>
            <?php endif; ?>
            <?php foreach ($transactions as $t): ?>
                <div class="list-row">
                    <div>
                        <?php echo h($t['Reason'] ?: ($t['Amount'] > 0 ? 'Coins earned' : 'Coins spent')); ?>
                        <div class="muted small"><?php echo date('M j, Y · g:i a', strtotime($t['Time_stamp'])); ?></div>
                    </div>
                    <strong class="<?php echo $t['Amount'] > 0 ? 'text-green' : 'text-red'; ?>"><?php echo $t['Amount'] > 0 ? '+' : ''; ?><?php echo (int)$t['Amount']; ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div>
        <div class="card">
            <h3 class="card-title">❤ Favourite Genre</h3>
            <p class="muted small">Used for "You May Like" on the home page and the recommendations on the Top page.</p>
            <form method="POST" action="profile.php">
                <input type="hidden" name="action" value="update_pref">
                <select name="genre_pref" class="form-control">
                    <option value="">— No preference —</option>
                    <?php foreach ($genreOptions as $g): ?>
                        <option value="<?php echo h($g); ?>" <?php echo $me['GenrePref'] === $g ? 'selected' : ''; ?>><?php echo h($g); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-full" style="margin-top:12px">Save</button>
            </form>
        </div>

        <div class="card">
            <h3 class="card-title">🎭 My Emojis</h3>
            <?php if (!$myEmojis): ?>
                <p class="muted small">You haven't unlocked any emojis yet. Every comic has its own Emoji Store.</p>
            <?php endif; ?>
            <div class="mini-emoji-grid">
                <?php foreach ($myEmojis as $e): ?>
                    <a href="community.php?id=<?php echo (int)$e['ComicID']; ?>" class="mini-emoji" title="<?php echo h($e['Name'] . ' · ' . $e['ComicTitle']); ?>">
                        <img src="<?php echo h($e['E_Value']); ?>" alt="<?php echo h($e['Name']); ?>">
                        <span><?php echo h($e['Name']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
