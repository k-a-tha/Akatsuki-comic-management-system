    </main>
    <footer class="main-footer">
        <div class="footer-container">
            <div class="footer-brand">
                <a href="index.php" class="logo"><span class="logo-text">AKATSUKI</span></a>
                <p class="footer-desc">Your premier destination for Manga and Webtoons. Read the latest chapters, predict what happens next, and meet the artists live.</p>
            </div>
            <div class="footer-links">
                <div class="footer-column">
                    <h4>Browse</h4>
                    <a href="index.php">Home</a>
                    <a href="comics.php">All Comics</a>
                    <a href="top-comics.php">Top Comics</a>
                    <a href="fan-art.php">Fan Art</a>
                    <a href="livestream.php">Live Streams</a>
                </div>
                <div class="footer-column">
                    <h4>Account</h4>
                    <?php if (isUserLoggedIn()): ?>
                        <a href="profile.php">My Profile</a>
                        <a href="notifications.php">Notifications</a>
                    <?php elseif (isArtistLoggedIn()): ?>
                        <a href="admin_panel.php">Artist Dashboard</a>
                        <a href="artist_go_live.php">My Streams</a>
                    <?php else: ?>
                        <a href="login_user.php">Reader Login</a>
                        <a href="login_artist.php">Artist Login</a>
                        <a href="register_artist.php">Become an Artist</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; <?php echo date('Y'); ?> Akatsuki · CSE370 Group 07. All rights reserved.</p>
        </div>
    </footer>
    <script src="script.js"></script>
</body>
</html>
