<?php
// notifications.php — a reader's notifications (opening the page marks them as read)
$pageTitle = 'Notifications';
require_once 'db.php';
requireUser();
$userId = currentUserId();

$stmt = $pdo->prepare("
    SELECT ID, TIME_STAMP, Message, Type, IsRead
    FROM NOTIFICATION WHERE UID = ?
    ORDER BY TIME_STAMP DESC, ID DESC
    LIMIT 50
");
$stmt->execute([$userId]);
$notifications = $stmt->fetchAll();

// Mark everything read AFTER fetching, so new ones are still highlighted this time
$pdo->prepare("UPDATE NOTIFICATION SET IsRead = TRUE WHERE UID = ? AND IsRead = FALSE")->execute([$userId]);

$balance = coinBalance($pdo, $userId);

$types = [
    'correct_prediction' => ['notif-correct', '✅'],
    'wrong_prediction'   => ['notif-wrong',   '❌'],
    'prediction_made'    => ['notif-general', '🔮'],
    'new_chapter'        => ['notif-chapter', '📖'],
];

require_once 'header.php';
?>

<div class="narrow-wrap">
    <div class="notifications-header">
        <h1 class="page-title">🔔 Notifications</h1>
        <a href="profile.php" class="coin-balance">🪙 <?php echo $balance; ?> coins</a>
    </div>

    <?php if (!$notifications): ?>
        <div class="empty-state">
            <div class="empty-icon">🔕</div>
            <p>No notifications yet. Start predicting to earn coins!</p>
            <a href="comics.php" class="btn btn-primary">Browse Comics</a>
        </div>
    <?php else: ?>
        <div class="notifications-list">
            <?php foreach ($notifications as $n):
                [$cls, $icon] = $types[$n['Type']] ?? ['notif-general', '🔔']; ?>
                <div class="notification-item <?php echo $cls; ?> <?php echo $n['IsRead'] ? '' : 'unread'; ?>">
                    <span class="notif-icon"><?php echo $icon; ?></span>
                    <div class="notif-body">
                        <p class="notif-message"><?php echo h($n['Message']); ?></p>
                        <span class="notif-time"><?php echo date('M j, Y \a\t g:i a', strtotime($n['TIME_STAMP'])); ?> · <?php echo timeAgo($n['TIME_STAMP']); ?></span>
                    </div>
                    <?php if (!$n['IsRead']): ?><span class="unread-dot" title="New"></span><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
