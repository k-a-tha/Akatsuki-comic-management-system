# Akatsuki — Comicbook Management System
CSE370 · Group 07 · Section 03 · Spring 2026

A PHP + MySQL web platform for comic readers and artists: reading, ratings & reviews, prediction polls that pay coins, an emoji store, community forums, live streams, reading guides, fan art, hard-copy links, weekly top charts and genre recommendations.

## Screenshots

| Home | Browse comics |
|---|---|
| ![Home](screenshots/01-home.png) | ![Browse](screenshots/02-browse-comics.png) |
| **Comic page** (rating, chapters, poll, characters, reviews) | **Prediction poll** |
| ![Comic page](screenshots/07-comic-page.png) | ![Prediction](screenshots/08-prediction-poll.png) |
| **Rate & review** | **Emoji store** |
| ![Rating](screenshots/09-rating-modal.png) | ![Emoji store](screenshots/10-emoji-store.png) |
| **Community forum with special emojis** | **Notifications** |
| ![Community](screenshots/11-community-forum.png) | ![Notifications](screenshots/12-notifications.png) |
| **Reader profile** | **Top comics & favourite characters** |
| ![Profile](screenshots/13-profile.png) | ![Top](screenshots/03-top-comics.png) |
| **Fan art gallery** | **Live streams** |
| ![Fan art](screenshots/04-fan-art.png) | ![Live](screenshots/05-live-streams.png) |
| **Chapter reader** | **Artist dashboard** |
| ![Reader](screenshots/06-reader.png) | ![Dashboard](screenshots/14-artist-dashboard.png) |
| **Publish chapter & resolve poll** | **Upload a chapter** |
| ![Publish](screenshots/15-publish-and-resolve-poll.png) | ![Upload](screenshots/16-upload-chapter.png) |
| **Go Live** | **Reading guide editor** |
| ![Go live](screenshots/17-go-live-modal.png) | ![Guide](screenshots/18-reading-guide-editor.png) |
| **Mobile** | |
| ![Mobile](screenshots/19-mobile.png) | |

---

## 1. How to run it (XAMPP, step by step)

**You need:** XAMPP with **PHP 8.0 or newer** (any XAMPP from 2021 onward). Download: https://www.apachefriends.org

1. **Start the servers.** Open the *XAMPP Control Panel* and click **Start** next to **Apache** and **MySQL**. Both should turn green.
2. **Copy the project.** Unzip this folder and put the whole `akatsuki` folder inside XAMPP's `htdocs` folder:
   - Windows: `C:\xampp\htdocs\akatsuki`
   - macOS: `/Applications/XAMPP/htdocs/akatsuki`
3. **Create the database.** Open http://localhost/phpmyadmin → click the **Import** tab at the top → **Choose File** → pick `akatsuki/setup_database.sql` → scroll down → **Import**. You should see *"Database setup completed successfully!"* and a new database **Anya_Forger** with 21 tables.
   > ⚠️ This script deletes any old `Anya_Forger` database and rebuilds it. If you want to keep old test data, use phpMyAdmin → *Export* first.
4. **Open the site:** http://localhost/akatsuki/
5. **Log in with a demo account** (password for all of them is `password`):

   | Role | Email | Owns / notes |
   |---|---|---|
   | Reader | `user@example.com` | has 2 coins, owns 1 emoji, favourite genre Action |
   | Reader | `user2@example.com` | has 1 coin, favourite genre Comedy |
   | Artist | `artist@example.com` | owns *Solo Leveling* and *SPY×FAMILY* |
   | Artist | `artist2@example.com` | owns *Return of the Crazy Demon* |

That's it. If your MySQL root user has a password, put it in `db.php` (`$password = '...'`).

---

## 2. Five-minute demo (shows every feature)

**As a reader** (`user@example.com`)
1. Home → "You May Like" strip picks Action comics (your genre). Amber 🛒 badges = buy hard copy.
2. Open **Solo Leveling** → scroll to **🔮 Chapter 3 Prediction** → click an option → *Confirm*. The bars appear and a 🔔 notification is created.
3. Click **★ Rate** → pick stars, write a review → Submit. Rate again to edit it.
4. In **Characters**, press **♥ Vote** (1 vote per comic per day). See the leaderboard under **TOP**.
5. **🛍️ Emoji Store** (on SPY×FAMILY) → buy "Spy Smirk" for 1 coin. Balance updates live.
6. **💬 Community** → *Insert Emoji* → post. Only emojis you own can be used.
7. **FAN ART** → pick a character, style and image → Upload.
8. Click your coin chip → **Profile**: coin history, predictions, emojis, change favourite genre.

**As an artist** (`artist@example.com`)
1. **🛠 Dashboard → Predictions** → *Publish Chapter & Resolve Poll*: pick "SPY×FAMILY — Chapter 3", the poll "Chapter 2" is selected automatically → choose option 4 (the one `user@example.com` picked) → Publish. The reader gets +1 coin and notifications.
2. Create a new poll on the chapter you just published.
3. **Comics** tab → create a comic with a cover and genres. **Chapters** tab → upload page images. **Characters** / **Emojis** tabs → add some.
4. **🔴 Go Live** (header) → paste any YouTube link → the LIVE dot appears for everyone. **🎥 Streams** page → schedule a future stream, end or cancel streams.
5. Open your comic → **📖 Create Guide** → save → readers see a *Reading Guide* button.

---

## 3. Files

| File | What it does |
|---|---|
| `db.php` | Database connection, session, timezone. Loads `functions.php`. **Edit DB login here.** |
| `functions.php` | Every shared helper (escaping, auth checks, covers, stars, uploads, notifications, emoji rendering). |
| `header.php` / `footer.php` | Site header (nav, bell, coins, artist Go-Live modal) and footer. |
| `style.css` / `script.js` | All styling / site-wide JavaScript. |
| `index.php` | Home: You May Like, Featured, Latest Updates, Top this week, Live now. |
| `comics.php` | Browse all comics: genre filter, search, 4 sort orders, buy buttons. |
| `comic.php` | Comic page: rating & reviews, chapters, prediction poll, character voting, live buttons, community preview. |
| `reader.php` | Chapter reader (← → keys switch chapters; artists can preview drafts). |
| `community.php` | Per-comic forum with special emojis (records `USES_ON`). |
| `emoji_store.php` + `emoji_purchase.php` | Emoji shop page + AJAX purchase (locked transaction). |
| `prediction_submit.php` | AJAX: lock in a prediction (one per chapter per reader). |
| `notifications.php` | Reader notifications. |
| `profile.php` | Reader profile: coins, predictions, emojis, favourite genre. |
| `top-comics.php` | Weekly Top 5, favourite-character leaderboard, genre recommendations, Hall of Fame. |
| `fan-art.php` | Fan-art gallery + upload. |
| `livestream.php` | Live / Upcoming / Past streams (optionally for one comic). |
| `artist_go_live.php` | Artist: go live, schedule, end, cancel, delete streams. |
| `admin_panel.php` | **Artist Dashboard**: comics, chapters (page upload), characters, prediction polls, emojis. |
| `get_poll_options.php` | AJAX helper for the dashboard. |
| `create_guide.php` / `view_guide.php` | Write / read a comic's reading guide. |
| `login_*.php`, `register_*.php`, `logout.php` | Accounts for readers and artists. |
| `setup_database.sql` | Creates all 21 tables + demo data. |
| `assets/` | `thumbnails/` covers · `chapters/` page images · `emojis/` · `fanarts/` · `guides/` |

### Using your own images
The `assets` folder contains simple placeholder covers, pages and emojis so everything works out of the box. To use your real artwork, copy your old `assets` folder over this one (say *Replace* when asked). Rules the site follows:
- **Cover:** `assets/thumbnails/<comic-name-in-lowercase-with-dashes>.webp` (or .jpg/.png) — e.g. `spy-x-family.webp`. Artists can also upload covers in the Dashboard.
- **Chapter pages:** any images inside the folder stored in `CHAPTER.ContentURL`, shown in file-name order.
- **Emojis:** the path stored in `SPECIAL_EMOJI.E_Value`.

---

## 4. Troubleshooting

| Problem | Fix |
|---|---|
| "Database connection failed" | MySQL isn't started in XAMPP, or `setup_database.sql` wasn't imported, or your MySQL password differs from `db.php`. |
| MySQL won't start (port 3306 busy) | Another MySQL is running. Stop it, or change XAMPP's MySQL port and add `;port=3307` after `host=$host` in `db.php`. |
| Page shows `Not Found` | The folder must be `htdocs/akatsuki` and the URL `http://localhost/akatsuki/`. |
| Chapter upload says it's too large / only some pages saved | PHP limits: `upload_max_filesize`, `post_max_size`, `max_file_uploads` in `php.ini` (XAMPP → Apache → Config → PHP (php.ini)). Or upload in smaller batches with **+ Pages**. |
| Upload says "Could not save the file" (macOS/Linux) | Make `assets` writable: `chmod -R 777 /Applications/XAMPP/htdocs/akatsuki/assets` |
| Times look wrong | Timezone is set to Asia/Dhaka in `db.php` (`date_default_timezone_set`). |

---

## 5. What was fixed / added

**Database (`setup_database.sql` did not match the PHP code)**
- Added the missing tables the code uses: `PREDICTION_OPTION`, `USER_EMOJI`; rebuilt `PREDICTION`, `NOTIFICATION`, `SPECIAL_EMOJI` with the columns the pages actually read and write.
- `RATING` key changed to (UID, Comic_ID) — before, "edit your rating" created duplicate rows because TIME_STAMP was part of the key.
- `SPECIAL_EMOJI.E_Value` was `VARCHAR(10)` (too short for an image path) and linked to USERS instead of COMIC.
- `RANK` is a reserved word in MySQL 8 → the top-chart tables failed to create; renamed to `Rank_No`, and their keys now allow one ranking per week.
- Added `ViewCount / WeeklyViewCount / LastViewWeek` (COMIC) and `Reason` (COIN_TRANSACTION); `UNIQUE (Comic_ID, ChapterNumber)`; `SET NAMES utf8mb4` so titles like SPY×FAMILY import correctly.
- New demo data covering every feature.

**Crashes & broken features**
- Artist Dashboard crashed (`c.IsCompleted` column doesn't exist).
- `functions.php` re-declared `h()` etc. → fatal error if included; now it is the single helper file and copies of the same helper functions were removed from the pages that each had their own.
- `index.php`: broken regex (`'/^https?:///'`) and a hard-coded `/akatsuki/` path.
- Login/register/notifications/fan-art printed HTML before redirecting ("headers already sent").
- `fan-art.php` used lowercase table names → fails on Linux/Mac MySQL.
- Go Live page: tab switch pointed at a tab that didn't exist (JS error), thumbnail typo `defaulSt.jpg`, half-built Schedule tab — now fully working (upcoming → live automatically).
- Scheduled streams showed as LIVE; ending an upcoming stream left it "upcoming". Status is now computed by MySQL (upcoming / live / past).
- `HardCopyLink` was used as both the cover image and the "Buy" link, so Buy opened a thumbnail file. Covers now come from `assets/thumbnails/`, the link is only a shop URL.
- Genre menu link `slice-of-life` never matched "Slice of Life".
- Emoji store: "1 coins", and the affordability refresh never worked (`parseInt` on "🪙 1").
- Community preview on the comic page showed raw `[emoji:…]` text.
- comic.php had no Go Live / End Live / Watch Live / Emoji Store buttons although the code for them existed.
- The double-submit script disabled buttons even when a confirm() was cancelled.

**Security**
- Fan-art upload trusted the browser's file type and kept the user's extension → a `.php` file could be uploaded and run. Images are now checked by content and renamed.
- Readers could post any emoji (even unowned); only owned emojis are kept now.
- Artists could go live / create polls / read poll options for other artists' comics; resolving a poll could use an option from another chapter. All checked now.
- Coin purchase and prediction now run inside a locked transaction (no double spending by double-clicking).
- Reading-guide files wrote the title unescaped into HTML (stored XSS).

**New pieces the report describes but were missing**
- Artists can create/edit/delete comics (cover, genres, hard-copy link), upload chapters (drafts or publish now), add pages, and manage characters — before, there was no way to add content from the site.
- `top-comics.php`: Top 5 weekly comics (by weekly views), favourite-character leaderboard (VOTE_FOR), genre recommendations (GenrePref), weekly Hall of Fame saved into `TOP_FAVOURITE_COMIC` / `TOP_FAVOURITE_CHARACTER`.
- Character voting on comic pages; "You May Like" strip on the home page; `profile.php`.
- Notifications for "prediction locked" and "new chapter" (sent to readers who follow the comic).
- `USES_ON` is now filled when a post contains emojis; `DEPICTED_IN.Type/Role` are set on fan-art upload.
- A complete `style.css` (it was not among the files you sent) with a responsive mobile menu.

---

## 6. Final relational schema (for the report's ER / schema diagrams)

Primary keys **bold**, foreign keys → target.

- USERS(**ID**, Email UNIQUE, Name, Gender, PasswordHash, GenrePref)
- ARTIST(**ID**, Email UNIQUE, Name, Gender, PasswordHash)
- COMIC(**ID**, HardCopyLink, Synopsis, Name, AID → ARTIST, Title, PDF, ViewCount, WeeklyViewCount, LastViewWeek)
- GENRE(**Comic_ID** → COMIC, **Genre**)
- CHARACTERS(**ID**, Name, Biography, Comic_ID → COMIC)
- CHAPTER(**CHAPTER_ID**, Date_of_Publication, ChapterNumber, isPublished, ContentURL, Comic_ID → COMIC) · UNIQUE(Comic_ID, ChapterNumber)
- LIVESTREAM(**VideoURL**, Title, StartTime, EndTime, AID → ARTIST, Comic_ID → COMIC)
- FANART(**ID**, Upload_Date, Image_URL, UID → USERS)
- DEPICTED_IN(**Fan_ID** → FANART, **Character_ID** → CHARACTERS, Type, Role)
- RATING(**UID** → USERS, **Comic_ID** → COMIC, Score, ReviewText, TIME_STAMP)
- COIN_TRANSACTION(**ID**, Amount, Time_stamp, Reason, UID → USERS)
- PREDICTION_OPTION(**ID**, Option_Number, OptionText, IsCorrect, IsResolved, Chapter_ID → CHAPTER) · UNIQUE(Chapter_ID, Option_Number)
- PREDICTION(**ID**, Option_ID → PREDICTION_OPTION, UID → USERS, TimeStamp, Coin_T_ID → COIN_TRANSACTION) · UNIQUE(Option_ID, UID)
- NOTIFICATION(**ID**, Message, Type, TIME_STAMP, IsRead, UID → USERS)
- FORUM_POST(**ID**, Content, TimeStamp, UID → USERS, Comic_ID → COMIC)
- SPECIAL_EMOJI(**Name**, E_Value, Coin_Amount, Comic_ID → COMIC)
- USER_EMOJI(**UID** → USERS, **Emoji_Name** → SPECIAL_EMOJI, Purchase_Date, Coin_T_ID → COIN_TRANSACTION)
- USES_ON(**E_Name** → SPECIAL_EMOJI, **F_post_ID** → FORUM_POST)
- VOTE_FOR(**Vote_Date**, **Character_ID** → CHARACTERS, **Comic_ID** → COMIC, **UID** → USERS)
- TOP_FAVOURITE_CHARACTER(**Year**, **Week_Number**, **Rank_No**, Character_ID → CHARACTERS, Votes)
- TOP_FAVOURITE_COMIC(**Year**, **Week_Number**, **Rank_No**, Comic_ID → COMIC, Weekly_Views)

Derived values (not stored): coin balance = SUM(COIN_TRANSACTION.Amount); average rating = AVG(RATING.Score); TotalFavoriteCount = COUNT(VOTE_FOR); weekly views shown as 0 when LastViewWeek is not the current week.
