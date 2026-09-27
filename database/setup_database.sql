-- =====================================================================
--  Akatsuki — Comicbook Management System
--  CSE370 Group 07 · Database setup script
--  Database: Anya_Forger
--
--  HOW TO USE (XAMPP):
--    phpMyAdmin → "Import" tab → choose this file → "Import" (bottom).
--    It creates the database, all 21 tables and demo data.
--
--  WARNING: running this script DELETES any existing Anya_Forger database
--  and rebuilds it from scratch. Export your old data first if you need it.
--
--  Demo logins (password for all four is:  password)
--    Reader  : user@example.com      /  user2@example.com
--    Artist  : artist@example.com    /  artist2@example.com
-- =====================================================================

SET NAMES utf8mb4;

DROP DATABASE IF EXISTS Anya_Forger;
CREATE DATABASE Anya_Forger CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE Anya_Forger;

-- =====================================================================
-- 1. USERS  (readers)
-- =====================================================================
CREATE TABLE USERS (
    ID            INT PRIMARY KEY AUTO_INCREMENT,
    Email         VARCHAR(250) NOT NULL UNIQUE,
    Name          VARCHAR(100) NOT NULL,
    Gender        VARCHAR(20),
    PasswordHash  VARCHAR(250) NOT NULL,
    GenrePref     VARCHAR(100)
);

-- =====================================================================
-- 2. ARTIST  (comic creators)
-- =====================================================================
CREATE TABLE ARTIST (
    ID            INT PRIMARY KEY AUTO_INCREMENT,
    Email         VARCHAR(250) NOT NULL UNIQUE,
    Name          VARCHAR(100) NOT NULL,
    Gender        VARCHAR(20),
    PasswordHash  VARCHAR(250) NOT NULL
);

-- =====================================================================
-- 3. COMIC
--    HardCopyLink    : external shop URL for the printed copy (NULL = none)
--    PDF             : path of the artist's reading guide (NULL = no guide)
--    ViewCount       : total page views of the comic
--    WeeklyViewCount : views during the ISO week stored in LastViewWeek
--    Covers are image files: assets/thumbnails/<comic-name-slug>.webp|jpg|png
-- =====================================================================
CREATE TABLE COMIC (
    ID               INT PRIMARY KEY AUTO_INCREMENT,
    HardCopyLink     VARCHAR(500),
    Synopsis         VARCHAR(1000),
    Name             VARCHAR(200) NOT NULL,
    AID              INT NOT NULL,
    Title            VARCHAR(200),
    PDF              VARCHAR(500),
    ViewCount        INT NOT NULL DEFAULT 0,
    WeeklyViewCount  INT NOT NULL DEFAULT 0,
    LastViewWeek     INT NULL,
    FOREIGN KEY (AID) REFERENCES ARTIST(ID) ON DELETE CASCADE ON UPDATE CASCADE
);

-- =====================================================================
-- 4. GENRE  (multivalued attribute of COMIC)
-- =====================================================================
CREATE TABLE GENRE (
    Comic_ID  INT NOT NULL,
    Genre     VARCHAR(50) NOT NULL,
    PRIMARY KEY (Comic_ID, Genre),
    FOREIGN KEY (Comic_ID) REFERENCES COMIC(ID) ON DELETE CASCADE ON UPDATE CASCADE
);

-- =====================================================================
-- 5. CHARACTERS
-- =====================================================================
CREATE TABLE CHARACTERS (
    ID         INT PRIMARY KEY AUTO_INCREMENT,
    Name       VARCHAR(100) NOT NULL,
    Biography  VARCHAR(1000),
    Comic_ID   INT NOT NULL,
    FOREIGN KEY (Comic_ID) REFERENCES COMIC(ID) ON DELETE CASCADE ON UPDATE CASCADE
);

-- =====================================================================
-- 6. CHAPTER
--    ContentURL : folder that holds the page images of the chapter
-- =====================================================================
CREATE TABLE CHAPTER (
    CHAPTER_ID           INT PRIMARY KEY AUTO_INCREMENT,
    Date_of_Publication  DATE,
    ChapterNumber        INT NOT NULL,
    isPublished          BOOLEAN NOT NULL DEFAULT FALSE,
    ContentURL           VARCHAR(500),
    Comic_ID             INT NOT NULL,
    UNIQUE KEY uq_comic_chapter (Comic_ID, ChapterNumber),
    FOREIGN KEY (Comic_ID) REFERENCES COMIC(ID) ON DELETE CASCADE ON UPDATE CASCADE
);

-- =====================================================================
-- 7. LIVESTREAM
--    Status is derived:  StartTime > NOW()            -> upcoming (scheduled)
--                        EndTime IS NULL / > NOW()    -> live
--                        otherwise                    -> past
-- =====================================================================
CREATE TABLE LIVESTREAM (
    VideoURL   VARCHAR(500) PRIMARY KEY,
    Title      VARCHAR(200),
    StartTime  DATETIME NOT NULL,
    EndTime    DATETIME NULL,
    AID        INT NOT NULL,
    Comic_ID   INT NOT NULL,
    FOREIGN KEY (AID)      REFERENCES ARTIST(ID) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (Comic_ID) REFERENCES COMIC(ID)  ON DELETE CASCADE ON UPDATE CASCADE
);

-- =====================================================================
-- 8. FANART
--    Image_URL : file name inside assets/fanarts/
-- =====================================================================
CREATE TABLE FANART (
    ID           INT PRIMARY KEY AUTO_INCREMENT,
    Upload_Date  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Image_URL    VARCHAR(500) NOT NULL,
    UID          INT NOT NULL,
    FOREIGN KEY (UID) REFERENCES USERS(ID) ON DELETE CASCADE ON UPDATE CASCADE
);

-- =====================================================================
-- 9. DEPICTED_IN  (FANART m:n CHARACTERS)
-- =====================================================================
CREATE TABLE DEPICTED_IN (
    Fan_ID        INT NOT NULL,
    Character_ID  INT NOT NULL,
    Type          VARCHAR(50),
    Role          VARCHAR(50),
    PRIMARY KEY (Fan_ID, Character_ID),
    FOREIGN KEY (Fan_ID)       REFERENCES FANART(ID)     ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (Character_ID) REFERENCES CHARACTERS(ID) ON DELETE CASCADE ON UPDATE CASCADE
);

-- =====================================================================
-- 10. RATING  (one rating + review per user per comic, editable)
-- =====================================================================
CREATE TABLE RATING (
    UID         INT NOT NULL,
    Comic_ID    INT NOT NULL,
    Score       DECIMAL(3,1) NOT NULL CHECK (Score >= 1.0 AND Score <= 10.0),
    ReviewText  VARCHAR(1000),
    TIME_STAMP  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (UID, Comic_ID),
    FOREIGN KEY (UID)      REFERENCES USERS(ID) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (Comic_ID) REFERENCES COMIC(ID) ON DELETE CASCADE ON UPDATE CASCADE
);

-- =====================================================================
-- 11. COIN_TRANSACTION  (+ earned / - spent; balance = SUM(Amount))
-- =====================================================================
CREATE TABLE COIN_TRANSACTION (
    ID          INT PRIMARY KEY AUTO_INCREMENT,
    Amount      INT NOT NULL,
    Time_stamp  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Reason      VARCHAR(200),
    UID         INT NOT NULL,
    FOREIGN KEY (UID) REFERENCES USERS(ID) ON DELETE CASCADE ON UPDATE CASCADE
);

-- =====================================================================
-- 12. PREDICTION_OPTION  (the 4 poll options an artist sets on a chapter)
-- =====================================================================
CREATE TABLE PREDICTION_OPTION (
    ID             INT PRIMARY KEY AUTO_INCREMENT,
    Option_Number  INT NOT NULL,
    OptionText     VARCHAR(500) NOT NULL,
    IsCorrect      BOOLEAN NOT NULL DEFAULT FALSE,
    IsResolved     BOOLEAN NOT NULL DEFAULT FALSE,
    Chapter_ID     INT NOT NULL,
    UNIQUE KEY uq_chapter_option (Chapter_ID, Option_Number),
    FOREIGN KEY (Chapter_ID) REFERENCES CHAPTER(CHAPTER_ID) ON DELETE CASCADE ON UPDATE CASCADE
);

-- =====================================================================
-- 13. PREDICTION  (a user's locked-in pick; one per user per chapter —
--     enforced in prediction_submit.php inside a transaction)
-- =====================================================================
CREATE TABLE PREDICTION (
    ID          INT PRIMARY KEY AUTO_INCREMENT,
    Option_ID   INT NOT NULL,
    UID         INT NOT NULL,
    TimeStamp   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Coin_T_ID   INT NULL,
    UNIQUE KEY uq_option_user (Option_ID, UID),
    FOREIGN KEY (Option_ID) REFERENCES PREDICTION_OPTION(ID) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (UID)       REFERENCES USERS(ID)             ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (Coin_T_ID) REFERENCES COIN_TRANSACTION(ID)  ON DELETE SET NULL
);

-- =====================================================================
-- 14. NOTIFICATION
--     Type: prediction_made | correct_prediction | wrong_prediction | new_chapter
-- =====================================================================
CREATE TABLE NOTIFICATION (
    ID          INT PRIMARY KEY AUTO_INCREMENT,
    Message     VARCHAR(500) NOT NULL,
    Type        VARCHAR(50)  NOT NULL DEFAULT 'general',
    TIME_STAMP  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    IsRead      BOOLEAN      NOT NULL DEFAULT FALSE,
    UID         INT NOT NULL,
    FOREIGN KEY (UID) REFERENCES USERS(ID) ON DELETE CASCADE ON UPDATE CASCADE
);

-- =====================================================================
-- 15. FORUM_POST  (one community forum per comic)
--     Emojis are stored inside Content as  [emoji:Name]
-- =====================================================================
CREATE TABLE FORUM_POST (
    ID         INT PRIMARY KEY AUTO_INCREMENT,
    Content    VARCHAR(500) NOT NULL,
    TimeStamp  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UID        INT NOT NULL,
    Comic_ID   INT NOT NULL,
    FOREIGN KEY (UID)      REFERENCES USERS(ID) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (Comic_ID) REFERENCES COMIC(ID) ON DELETE CASCADE ON UPDATE CASCADE
);

-- =====================================================================
-- 16. SPECIAL_EMOJI  (comic-specific emojis sold in the emoji store)
--     E_Value : image path, e.g. assets/emojis/spy-smirk.webp
-- =====================================================================
CREATE TABLE SPECIAL_EMOJI (
    Name         VARCHAR(100) PRIMARY KEY,
    E_Value      VARCHAR(500) NOT NULL,
    Coin_Amount  INT NOT NULL DEFAULT 1 CHECK (Coin_Amount >= 1),
    Comic_ID     INT NOT NULL,
    FOREIGN KEY (Comic_ID) REFERENCES COMIC(ID) ON DELETE CASCADE ON UPDATE CASCADE
);

-- =====================================================================
-- 17. USER_EMOJI  (deduct_coin_balance: which user bought which emoji)
-- =====================================================================
CREATE TABLE USER_EMOJI (
    UID            INT NOT NULL,
    Emoji_Name     VARCHAR(100) NOT NULL,
    Purchase_Date  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    Coin_T_ID      INT NULL,
    PRIMARY KEY (UID, Emoji_Name),
    FOREIGN KEY (UID)        REFERENCES USERS(ID)            ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (Emoji_Name) REFERENCES SPECIAL_EMOJI(Name)  ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (Coin_T_ID)  REFERENCES COIN_TRANSACTION(ID) ON DELETE SET NULL
);

-- =====================================================================
-- 18. USES_ON  (which special emojis appear in which forum post)
-- =====================================================================
CREATE TABLE USES_ON (
    E_Name     VARCHAR(100) NOT NULL,
    F_post_ID  INT NOT NULL,
    PRIMARY KEY (E_Name, F_post_ID),
    FOREIGN KEY (E_Name)    REFERENCES SPECIAL_EMOJI(Name) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (F_post_ID) REFERENCES FORUM_POST(ID)      ON DELETE CASCADE ON UPDATE CASCADE
);

-- =====================================================================
-- 19. VOTE_FOR  (user votes a favourite character of a comic; 1 per comic per day)
-- =====================================================================
CREATE TABLE VOTE_FOR (
    Vote_Date     DATE NOT NULL,
    Character_ID  INT NOT NULL,
    Comic_ID      INT NOT NULL,
    UID           INT NOT NULL,
    PRIMARY KEY (Vote_Date, Character_ID, Comic_ID, UID),
    FOREIGN KEY (Character_ID) REFERENCES CHARACTERS(ID) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (Comic_ID)     REFERENCES COMIC(ID)      ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (UID)          REFERENCES USERS(ID)      ON DELETE CASCADE ON UPDATE CASCADE
);

-- =====================================================================
-- 20. TOP_FAVOURITE_CHARACTER  (weekly leaderboard snapshot)
--     "RANK" is a reserved word in MySQL 8, so the column is Rank_No.
-- =====================================================================
CREATE TABLE TOP_FAVOURITE_CHARACTER (
    Year          INT NOT NULL,
    Week_Number   INT NOT NULL,
    Rank_No       INT NOT NULL,
    Character_ID  INT NOT NULL,
    Votes         INT NOT NULL DEFAULT 0,
    PRIMARY KEY (Year, Week_Number, Rank_No),
    FOREIGN KEY (Character_ID) REFERENCES CHARACTERS(ID) ON DELETE CASCADE ON UPDATE CASCADE
);

-- =====================================================================
-- 21. TOP_FAVOURITE_COMIC  (weekly top-5 snapshot)
-- =====================================================================
CREATE TABLE TOP_FAVOURITE_COMIC (
    Year          INT NOT NULL,
    Week_Number   INT NOT NULL,
    Rank_No       INT NOT NULL,
    Comic_ID      INT NOT NULL,
    Weekly_Views  INT NOT NULL DEFAULT 0,
    PRIMARY KEY (Year, Week_Number, Rank_No),
    FOREIGN KEY (Comic_ID) REFERENCES COMIC(ID) ON DELETE CASCADE ON UPDATE CASCADE
);


-- =====================================================================
--  DEMO DATA
-- =====================================================================

-- Artists (password: password)
INSERT INTO ARTIST (Email, Name, Gender, PasswordHash) VALUES
('artist@example.com',  'Sample Artist', 'Other',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
('artist2@example.com', 'Demon Studio',  'Male',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

-- Readers (password: password)
INSERT INTO USERS (Email, Name, Gender, PasswordHash, GenrePref) VALUES
('user@example.com',  'Sample User', 'Other',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Action'),
('user2@example.com', 'Manga Fan',   'Female', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Comedy');

-- Comics  (covers live in assets/thumbnails/<slug>.webp)
INSERT INTO COMIC (HardCopyLink, Synopsis, Name, AID, Title, ViewCount, WeeklyViewCount, LastViewWeek) VALUES
('https://www.amazon.com/s?k=solo+leveling+manga',
 'In a world where hunters must battle deadly monsters to protect humanity, Sung Jin-Woo is the weakest of them all. But when a mysterious dungeon gives him a unique power, he begins his journey to become the strongest hunter.',
 'Solo Leveling', 1, 'Solo Leveling', 214, 41, YEARWEEK(CURDATE(), 1)),
('https://www.amazon.com/s?k=spy+x+family+manga',
 'A spy, an assassin, and a telepath form an unlikely family. Master spy "Twilight" must create a fake family to infiltrate an elite school, but his new "wife" is actually an assassin and his "daughter" is a mind reader!',
 'Spy x Family', 1, 'SPY×FAMILY', 305, 57, YEARWEEK(CURDATE(), 1)),
(NULL,
 'After being pushed off a cliff by his enemies, the leader of a small sect is reborn 30 years in the past. Armed with memories of his previous life, he sets out on a path of revenge and domination.',
 'Return of the Crazy Demon', 2, 'Return of the Crazy Demon', 96, 18, YEARWEEK(CURDATE(), 1));

INSERT INTO GENRE (Comic_ID, Genre) VALUES
(1, 'Action'), (1, 'Fantasy'), (1, 'Adventure'),
(2, 'Comedy'), (2, 'Action'), (2, 'Slice of Life'),
(3, 'Action'), (3, 'Martial Arts'), (3, 'Fantasy');

-- Chapters (CHAPTER_ID 1..9; the last two are unpublished drafts)
INSERT INTO CHAPTER (Date_of_Publication, ChapterNumber, isPublished, ContentURL, Comic_ID) VALUES
('2026-04-20', 1, TRUE,  'assets/chapters/solo-leveling/chapter-1', 1),              -- 1
('2026-04-22', 2, TRUE,  'assets/chapters/solo-leveling/chapter-2', 1),              -- 2
('2026-04-24', 3, TRUE,  'assets/chapters/solo-leveling/chapter-3', 1),              -- 3
('2026-04-18', 1, TRUE,  'assets/chapters/spy-x-family/chapter-1', 2),               -- 4
('2026-04-21', 2, TRUE,  'assets/chapters/spy-x-family/chapter-2', 2),               -- 5
('2026-04-19', 1, TRUE,  'assets/chapters/return-of-the-crazy-demon/chapter-1', 3),  -- 6
('2026-04-23', 2, TRUE,  'assets/chapters/return-of-the-crazy-demon/chapter-2', 3),  -- 7
(NULL,         3, FALSE, 'assets/chapters/spy-x-family/chapter-3', 2),               -- 8 draft
(NULL,         4, FALSE, 'assets/chapters/solo-leveling/chapter-4', 1);              -- 9 draft

INSERT INTO CHARACTERS (Name, Biography, Comic_ID) VALUES
('Sung Jin-Woo', 'The main protagonist. Known as the World''s Weakest Hunter before gaining the System.', 1),
('Cha Hae-In',   'An S-Rank Hunter and Vice Guild Master of the Hunters Guild.', 1),
('Loid Forger',  'A spy known by the codename "Twilight", considered the best spy in Westalis.', 2),
('Yor Forger',   'An assassin known as "Thorn Princess" who becomes Loid''s fake wife.', 2),
('Anya Forger',  'A telepath who becomes Loid and Yor''s adopted daughter.', 2),
('Zaha',         'Former leader of a small sect, reborn 30 years in the past to take revenge.', 3);

-- Ratings (1 per user per comic)
INSERT INTO RATING (UID, Comic_ID, Score, ReviewText, TIME_STAMP) VALUES
(1, 1, 9.5, 'Amazing story with great action sequences!', NOW() - INTERVAL 5 DAY),
(1, 2, 9.0, 'Hilarious and heartwarming family comedy.',  NOW() - INTERVAL 4 DAY),
(1, 3, 8.5, 'Great martial arts action and revenge plot.', NOW() - INTERVAL 3 DAY),
(2, 2, 10.0, 'Anya is the best. Every chapter makes me smile.', NOW() - INTERVAL 2 DAY),
(2, 1, 8.0, 'The art is incredible, pacing is fast.', NOW() - INTERVAL 1 DAY);

-- Prediction polls (option IDs 1..20)
INSERT INTO PREDICTION_OPTION (Option_Number, OptionText, IsCorrect, IsResolved, Chapter_ID) VALUES
-- Solo Leveling Ch.1 (resolved, correct = option 2)
(1, 'Jin-Woo escapes the double dungeon alone', FALSE, TRUE, 1),
(2, 'Jin-Woo receives a mysterious System',      TRUE,  TRUE, 1),
(3, 'The whole raid party survives',             FALSE, TRUE, 1),
(4, 'Jin-Woo quits being a hunter',              FALSE, TRUE, 1),
-- Solo Leveling Ch.2 (resolved, correct = option 1)
(1, 'Jin-Woo starts the daily training quest',   TRUE,  TRUE, 2),
(2, 'Jin-Woo joins a big guild',                 FALSE, TRUE, 2),
(3, 'Cha Hae-In appears',                        FALSE, TRUE, 2),
(4, 'A new gate opens in Seoul',                 FALSE, TRUE, 2),
-- Return of the Crazy Demon Ch.1 (resolved, correct = option 4)
(1, 'Zaha returns to his old sect immediately',  FALSE, TRUE, 6),
(2, 'Zaha meets his future enemy',               FALSE, TRUE, 6),
(3, 'Zaha loses his memories',                   FALSE, TRUE, 6),
(4, 'Zaha will create the Great Zaha Inn',       TRUE,  TRUE, 6),
-- Solo Leveling Ch.3 (OPEN — latest chapter)
(1, 'Jin-Woo levels up to rank C',               FALSE, FALSE, 3),
(2, 'Jin-Woo enters an instant dungeon',         FALSE, FALSE, 3),
(3, 'Jin-Woo meets the Hunters Association chief', FALSE, FALSE, 3),
(4, 'Jin-Woo gets his first shadow soldier',     FALSE, FALSE, 3),
-- Spy x Family Ch.2 (OPEN — latest chapter)
(1, 'Anya will use her telepathy to help Loid with his mission', FALSE, FALSE, 5),
(2, 'Loid will be discovered by enemy agents',                   FALSE, FALSE, 5),
(3, 'The family will have a peaceful dinner together',           FALSE, FALSE, 5),
(4, 'Yor will reveal a clue about her assassin past',            FALSE, FALSE, 5);

-- Coins earned from correct predictions (IDs 1..4) and one emoji purchase (ID 5)
INSERT INTO COIN_TRANSACTION (Amount, Time_stamp, Reason, UID) VALUES
( 1, NOW() - INTERVAL 20 DAY, 'Correct prediction: Solo Leveling Chapter 1', 1),
( 1, NOW() - INTERVAL 18 DAY, 'Correct prediction: Solo Leveling Chapter 2', 1),
( 1, NOW() - INTERVAL 17 DAY, 'Correct prediction: Return of the Crazy Demon Chapter 1', 1),
( 1, NOW() - INTERVAL 17 DAY, 'Correct prediction: Return of the Crazy Demon Chapter 1', 2),
(-1, NOW() - INTERVAL 10 DAY, 'Bought emoji: Peanut Joy', 1);

INSERT INTO PREDICTION (Option_ID, UID, TimeStamp, Coin_T_ID) VALUES
( 2, 1, NOW() - INTERVAL 21 DAY, 1),
( 5, 1, NOW() - INTERVAL 19 DAY, 2),
(12, 1, NOW() - INTERVAL 18 DAY, 3),
( 1, 2, NOW() - INTERVAL 21 DAY, NULL),
( 6, 2, NOW() - INTERVAL 19 DAY, NULL),
(12, 2, NOW() - INTERVAL 18 DAY, 4),
(20, 1, NOW() - INTERVAL 1 DAY,  NULL);   -- open poll pick (Spy x Family Ch.2)

INSERT INTO NOTIFICATION (Message, Type, TIME_STAMP, IsRead, UID) VALUES
('You predicted correctly for Solo Leveling Chapter 1! The answer was: "Jin-Woo receives a mysterious System". You earned 1 coin!', 'correct_prediction', NOW() - INTERVAL 20 DAY, TRUE, 1),
('You predicted correctly for Solo Leveling Chapter 2! The answer was: "Jin-Woo starts the daily training quest". You earned 1 coin!', 'correct_prediction', NOW() - INTERVAL 18 DAY, TRUE, 1),
('You predicted correctly for Return of the Crazy Demon Chapter 1! The answer was: "Zaha will create the Great Zaha Inn". You earned 1 coin!', 'correct_prediction', NOW() - INTERVAL 17 DAY, FALSE, 1),
('Prediction locked for SPY×FAMILY Chapter 2: "Yor will reveal a clue about her assassin past". Results arrive when the next chapter drops.', 'prediction_made', NOW() - INTERVAL 1 DAY, FALSE, 1),
('Your prediction for Solo Leveling Chapter 1 was incorrect. The correct answer was: "Jin-Woo receives a mysterious System". Better luck next time!', 'wrong_prediction', NOW() - INTERVAL 20 DAY, TRUE, 2),
('Your prediction for Solo Leveling Chapter 2 was incorrect. The correct answer was: "Jin-Woo starts the daily training quest". Better luck next time!', 'wrong_prediction', NOW() - INTERVAL 18 DAY, TRUE, 2),
('You predicted correctly for Return of the Crazy Demon Chapter 1! The answer was: "Zaha will create the Great Zaha Inn". You earned 1 coin!', 'correct_prediction', NOW() - INTERVAL 17 DAY, FALSE, 2);

-- Emoji store
INSERT INTO SPECIAL_EMOJI (Name, E_Value, Coin_Amount, Comic_ID) VALUES
('Spy Smirk',   'assets/emojis/spy-smirk.webp',   1, 2),
('Peanut Joy',  'assets/emojis/peanut-joy.webp',  1, 2),
('Shock Face',  'assets/emojis/shock-face.webp',  2, 2),
('Tiny Tears',  'assets/emojis/tiny-tears.webp',  2, 2),
('Shadow Grin', 'assets/emojis/shadow-grin.webp', 1, 1),
('Level Up',    'assets/emojis/level-up.webp',    2, 1),
('Demon Rage',  'assets/emojis/demon-rage.webp',  1, 3);

INSERT INTO USER_EMOJI (UID, Emoji_Name, Purchase_Date, Coin_T_ID) VALUES
(1, 'Peanut Joy', NOW() - INTERVAL 10 DAY, 5);

-- Community posts
INSERT INTO FORUM_POST (Content, TimeStamp, UID, Comic_ID) VALUES
('Chapter 2 was so funny [emoji:Peanut Joy] can''t wait for the next one!', NOW() - INTERVAL 9 DAY, 1, 2),
('What do you think Yor is hiding? My theory: her boss knows about Loid.',   NOW() - INTERVAL 3 DAY, 2, 2),
('The art in chapter 3 is on another level.',                               NOW() - INTERVAL 2 DAY, 1, 1),
('Zaha building an inn instead of taking revenge first is such a twist.',   NOW() - INTERVAL 5 DAY, 2, 3);

INSERT INTO USES_ON (E_Name, F_post_ID) VALUES ('Peanut Joy', 1);

-- Favourite-character votes (this week)
INSERT INTO VOTE_FOR (Vote_Date, Character_ID, Comic_ID, UID) VALUES
(CURDATE() - INTERVAL 1 DAY, 5, 2, 1),
(CURDATE() - INTERVAL 2 DAY, 5, 2, 2),
(CURDATE() - INTERVAL 3 DAY, 4, 2, 1),
(CURDATE() - INTERVAL 1 DAY, 1, 1, 2),
(CURDATE() - INTERVAL 2 DAY, 1, 1, 1),
(CURDATE() - INTERVAL 3 DAY, 6, 3, 2);

-- Last week's leaderboard snapshot (history shown on top-comics.php)
INSERT INTO TOP_FAVOURITE_COMIC (Year, Week_Number, Rank_No, Comic_ID, Weekly_Views) VALUES
(FLOOR(YEARWEEK(CURDATE() - INTERVAL 7 DAY, 1) / 100), MOD(YEARWEEK(CURDATE() - INTERVAL 7 DAY, 1), 100), 1, 1, 52),
(FLOOR(YEARWEEK(CURDATE() - INTERVAL 7 DAY, 1) / 100), MOD(YEARWEEK(CURDATE() - INTERVAL 7 DAY, 1), 100), 2, 2, 49),
(FLOOR(YEARWEEK(CURDATE() - INTERVAL 7 DAY, 1) / 100), MOD(YEARWEEK(CURDATE() - INTERVAL 7 DAY, 1), 100), 3, 3, 20);

INSERT INTO TOP_FAVOURITE_CHARACTER (Year, Week_Number, Rank_No, Character_ID, Votes) VALUES
(FLOOR(YEARWEEK(CURDATE() - INTERVAL 7 DAY, 1) / 100), MOD(YEARWEEK(CURDATE() - INTERVAL 7 DAY, 1), 100), 1, 1, 4),
(FLOOR(YEARWEEK(CURDATE() - INTERVAL 7 DAY, 1) / 100), MOD(YEARWEEK(CURDATE() - INTERVAL 7 DAY, 1), 100), 2, 5, 3),
(FLOOR(YEARWEEK(CURDATE() - INTERVAL 7 DAY, 1) / 100), MOD(YEARWEEK(CURDATE() - INTERVAL 7 DAY, 1), 100), 3, 4, 1);

-- Fan art (files in assets/fanarts/)
INSERT INTO FANART (Upload_Date, Image_URL, UID) VALUES
(NOW() - INTERVAL 6 DAY, 'fanart_sample_1.webp', 1),
(NOW() - INTERVAL 4 DAY, 'fanart_sample_2.webp', 2),
(NOW() - INTERVAL 2 DAY, 'fanart_sample_3.webp', 2);

INSERT INTO DEPICTED_IN (Fan_ID, Character_ID, Type, Role) VALUES
(1, 5, 'Digital',     'Main subject'),
(2, 1, 'Traditional', 'Main subject'),
(3, 4, 'Chibi',       'Main subject');

-- A finished stream so the Past Streams section has something to show
INSERT INTO LIVESTREAM (VideoURL, Title, StartTime, EndTime, AID, Comic_ID) VALUES
('https://www.youtube.com/watch?v=SAMPLEVIDEO', 'Drawing Anya Forger', NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 3 DAY + INTERVAL 2 HOUR, 1, 2);

SELECT 'Database setup completed successfully!' AS Status;
