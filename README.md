# Comic Platform

A complete web application for managing, sharing, and discovering comics with user and artist accounts.

## Tech Stack
- **Frontend:** HTML5, CSS3, JavaScript
- **Backend:** PHP
- **Database:** MySQL (XAMPP)

## Features

### User Features
- User registration and login
- Browse comics
- View comic details
- Rate and review comics
- Forum discussions
- Fan art uploads
- Character voting

### Artist Features
- Artist registration and login
- Create and manage comics
- Upload chapters
- Host livestreams
- View statistics

## Database Tables
1. **USERS** - User account information
2. **ARTIST** - Artist account information
3. **COMIC** - Comic metadata
4. **CHAPTER** - Comic chapters
5. **CHARACTERS** - Comic characters
6. **GENRE** - Comic genres
7. **RATING** - User ratings and reviews
8. **FANART** - User fan art submissions
9. **FORUM_POST** - Forum discussions
10. **LIVESTREAM** - Artist livestreams
11. **PREDICTION** - User predictions
12. **NOTIFICATION** - User notifications
13. **COIN_TRANSACTION** - Virtual currency transactions
14. **SPECIAL_EMOJI** - Custom emoji reactions
15. **VOTE_FOR** - Character voting
16. **TOP_FAVOURITE_CHARACTER** - Top rated characters
17. **TOP_FAVOURITE_COMIC** - Top rated comics
18. **DEPICTED_IN** - Fan art character mappings
19. **USES_ON** - Emoji usage tracking


## File Structure
```
CSE370-group-7/
├── index.html                 # Homepage
├── config/
│   └── db.php                # Database configuration
├── css/
│   └── style.css             # Styling
├── js/
│   └── script.js             # Client-side functionality
├── pages/
│   ├── login.html            # User login
│   ├── register.html         # User registration
│   ├── artist_login.html     # Artist login
│   ├── artist_register.html  # Artist registration
│   └── comic.html            # Comics listing
├── php/
│   ├── register_user.php     # User registration backend
│   ├── login_user.php        # User login backend
│   ├── register_artist.php   # Artist registration backend
│   ├── login_artist.php      # Artist login backend
│   └── get_comics.php        # Comics API endpoint
└── README.md                 # This file
```

## Security Features
- Password hashing with BCRYPT
- Input sanitization (SQL injection prevention)
- Session-based authentication
- Email uniqueness validation
- Password confirmation matching



## Final version (Akatsuki)
The complete, working project is in the [`akatsuki/`](akatsuki/) folder — code, `setup_database.sql`, demo images and screenshots.
See [`akatsuki/README.md`](akatsuki/README.md) for setup steps, demo accounts, screenshots and the final database schema.
