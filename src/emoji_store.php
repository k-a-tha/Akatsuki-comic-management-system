<?php
// emoji_store.php — readers spend coins on special emojis for one comic
require_once 'db.php';

$comicId = (int)($_GET['id'] ?? 0);
if (!$comicId) redirect('index.php');

$comicStmt = $pdo->prepare("SELECT ID, Name, Title, HardCopyLink FROM COMIC WHERE ID = ?");
$comicStmt->execute([$comicId]);
$comic = $comicStmt->fetch();
if (!$comic) redirect('comics.php');

$comicTitle = $comic['Title'] ?: $comic['Name'];
$pageTitle  = $comicTitle . ' — Emoji Store';

$balance    = 0;
$ownedNames = [];
if (isUserLoggedIn()) {
    $balance = coinBalance($pdo, currentUserId());
    $s = $pdo->prepare("SELECT Emoji_Name FROM USER_EMOJI WHERE UID = ?");
    $s->execute([currentUserId()]);
    $ownedNames = $s->fetchAll(PDO::FETCH_COLUMN);
}

$emojiStmt = $pdo->prepare("SELECT Name, E_Value, Coin_Amount FROM SPECIAL_EMOJI WHERE Comic_ID = ? ORDER BY Coin_Amount, Name");
$emojiStmt->execute([$comicId]);
$emojis = $emojiStmt->fetchAll();

require_once 'header.php';
?>

<div class="narrow-wrap wide">
    <div class="store-header">
        <div>
            <h1 class="page-title">🛍️ <?php echo h($comicTitle); ?> Emoji Store</h1>
            <p class="muted">Purchase exclusive emojis to use in community posts. Earn coins by predicting correctly!</p>
            <div class="btn-row">
                <a href="comic.php?id=<?php echo $comicId; ?>" class="btn btn-outline btn-sm">← Back to Comic</a>
                <a href="community.php?id=<?php echo $comicId; ?>" class="btn btn-outline btn-sm">💬 Community</a>
            </div>
        </div>
        <?php if (isUserLoggedIn()): ?>
            <div class="store-balance-box">
                <span class="coin-big">🪙</span>
                <div>
                    <div class="label">Your Balance</div>
                    <div class="amount"><span id="balanceDisplay"><?php echo $balance; ?></span> coins</div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!isUserLoggedIn()): ?>
        <div class="alert alert-info alert-sticky">🔒 <a href="login_user.php">Log in</a> as a reader to purchase emojis and use them in community posts.</div>
    <?php endif; ?>

    <?php if (!$emojis): ?>
        <div class="empty-state">
            <div class="empty-icon">🎭</div>
            <p>No special emojis available for this comic yet. Check back later!</p>
        </div>
    <?php else: ?>
        <div class="emoji-grid">
            <?php foreach ($emojis as $emoji):
                $cost      = (int)$emoji['Coin_Amount'];
                $isOwned   = in_array($emoji['Name'], $ownedNames, true);
                $canAfford = $balance >= $cost; ?>
                <div class="emoji-card <?php echo $isOwned ? 'owned' : ''; ?>" data-cost="<?php echo $cost; ?>">
                    <img class="emoji-preview" src="<?php echo h($emoji['E_Value']); ?>" alt="<?php echo h($emoji['Name']); ?>"
                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="emoji-preview-fallback" style="display:none;">🖼️</div>
                    <div class="emoji-name"><?php echo h($emoji['Name']); ?></div>
                    <div class="emoji-cost">🪙 <?php echo $cost; ?> coin<?php echo $cost === 1 ? '' : 's'; ?></div>

                    <?php if ($isOwned): ?>
                        <span class="owned-badge">✓ Owned</span>
                    <?php elseif (!isUserLoggedIn()): ?>
                        <button type="button" class="btn-buy" disabled>Log in to buy</button>
                    <?php else: ?>
                        <button type="button" class="btn-buy" data-name="<?php echo h($emoji['Name']); ?>"
                                <?php echo $canAfford ? '' : 'disabled title="Not enough coins"'; ?>>
                            <?php echo $canAfford ? 'Buy' : 'Need more coins'; ?>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div id="storeToast"></div>

<script>
const comicId = <?php echo $comicId; ?>;

document.querySelectorAll('.btn-buy[data-name]').forEach(btn => btn.addEventListener('click', () => {
    const card = btn.closest('.emoji-card');
    const name = btn.dataset.name;
    const cost = parseInt(card.dataset.cost, 10);
    if (!confirm(`Buy "${name}" for ${cost} coin${cost !== 1 ? 's' : ''}?`)) return;

    btn.disabled = true;
    btn.textContent = 'Buying…';

    fetch('emoji_purchase.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'emoji_name=' + encodeURIComponent(name) + '&comic_id=' + comicId
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            card.classList.add('owned');
            const badge = document.createElement('span');
            badge.className = 'owned-badge';
            badge.textContent = '✓ Owned';
            btn.replaceWith(badge);
            document.getElementById('balanceDisplay').textContent = data.new_balance;
            document.querySelectorAll('.coin-chip').forEach(c => c.textContent = '🪙 ' + data.new_balance);
            refreshAffordability(data.new_balance);
            showToast(data.message, 'ok');
        } else {
            btn.disabled = false;
            btn.textContent = 'Buy';
            showToast(data.message, 'err');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.textContent = 'Buy';
        showToast('Network error. Please try again.', 'err');
    });
}));

function refreshAffordability(balance) {
    document.querySelectorAll('.btn-buy[data-name]:not([disabled])').forEach(b => {
        if (balance < parseInt(b.closest('.emoji-card').dataset.cost, 10)) {
            b.disabled = true;
            b.textContent = 'Need more coins';
            b.title = 'Not enough coins';
        }
    });
}

let toastTimer;
function showToast(msg, type) {
    const t = document.getElementById('storeToast');
    t.textContent = msg;
    t.className = 'show toast-' + type;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.className = ''; }, 3500);
}
</script>

<?php require_once 'footer.php'; ?>
