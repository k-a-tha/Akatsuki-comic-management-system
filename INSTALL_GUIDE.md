# Akatsuki — Full Install Guide (Windows)
From a PC with nothing installed to the site running, including how to fix "MySQL won't start".

---

## Part A — Install XAMPP

1. Go to https://www.apachefriends.org and download **XAMPP for Windows** (the PHP 8.x version).
2. If Windows asks about **User Account Control (UAC)**, click OK and continue.
3. In the installer, keep these ticked: **Apache, MySQL, PHP, phpMyAdmin**. The rest can stay as they are.
4. Install folder: keep **`C:\xampp`**. Do **not** install into `C:\Program Files` — that causes permission problems that stop MySQL from starting.
5. Finish, then open the **XAMPP Control Panel**. Always open it by right-clicking → **Run as administrator**.
6. If Windows Firewall asks about Apache or mysqld, click **Allow access**.
7. Click **Start** next to **Apache**, then **Start** next to **MySQL**. Both names should turn green.

If MySQL does not turn green, go to Part B. If Apache does not turn green, see B6.

---

## Part B — Fix "MySQL is not starting"

First, find out *why*: in the Control Panel, click **Logs** on the MySQL row → **mysql_error.log**, and read the last lines. Then match it below.

### B1. "Port 3306 in use" / "blocked port" (most common)
Another MySQL on your PC is already using port 3306 (e.g. MySQL Workbench / MySQL Server 8.0, WAMP, another XAMPP).

**Fix — stop the other MySQL:**
1. Press **Win + R**, type `services.msc`, press Enter.
2. Find **MySQL80** (or MySQL57 / MySQL / wampmysqld).
3. Right-click → **Stop**. Then right-click → **Properties** → Startup type **Manual** → OK (so it doesn't come back after restart).
4. Go back to XAMPP and click **Start** on MySQL.

Check who is using the port: click **Netstat** in the Control Panel, or open Command Prompt and run
`netstat -ano | findstr :3306`

**Alternative — move XAMPP's MySQL to port 3307** (if you need the other MySQL):
1. MySQL row → **Config** → **my.ini**. Change **both** lines `port=3306` to `port=3307`. Save.
2. Open `C:\xampp\phpMyAdmin\config.inc.php` and add this line near the other `$cfg['Servers'][$i]` lines:
   `$cfg['Servers'][$i]['port'] = '3307';`
3. In the project's `db.php` change the connection line to:
   `$pdo = new PDO("mysql:host=$host;port=3307;dbname=$dbname;charset=utf8mb4", ...`
4. Start MySQL again.

### B2. "MySQL shutdown unexpectedly" (log mentions aria_log, InnoDB, crash, or corrupted)
Usually happens after the PC was turned off without stopping XAMPP. The MySQL data folder is damaged.

1. Make sure MySQL is stopped in XAMPP.
2. Open `C:\xampp\mysql\`.
3. Rename the folder **`data`** to **`data_old`**.
4. Copy the folder **`backup`** and paste it; rename the copy to **`data`**.
5. **Only if you have other projects' databases you want to keep:** copy those database folders (NOT `mysql`, `performance_schema`, `phpmyadmin`, `test`) and the file `ibdata1` from `data_old` into the new `data` folder, replacing files.
   For Akatsuki you don't need this — you will import `setup_database.sql` fresh.
6. Start MySQL. It should turn green.

### B3. Nothing in the log / "Attempting to start MySQL" then stops
- Close the Control Panel and reopen it with **Run as administrator**.
- Make sure XAMPP is in `C:\xampp` (not Program Files, not OneDrive / Desktop).
- Temporarily disable antivirus and try again; if it works, add `C:\xampp` as an exception.

### B4. Error about MSVCR / VCRUNTIME .dll missing
Install **Microsoft Visual C++ Redistributable 2015–2022 (x64)** from Microsoft's website, restart the PC, start MySQL again.

### B5. Still failing — clean reinstall (5 minutes)
1. Stop everything, close the Control Panel.
2. Uninstall XAMPP, then delete the folder `C:\xampp` completely.
3. Stop any other MySQL service (B1).
4. Restart the PC and do Part A again.

### B6. Apache won't start (port 80/443 busy)
Skype, IIS, VMware or another web server is using port 80.
1. Apache row → **Config** → **httpd.conf** → change `Listen 80` to `Listen 8080` → save.
2. Apache → Config → **httpd-ssl.conf** → change `Listen 443` to `Listen 4433` → save.
3. Start Apache. Now use **http://localhost:8080/** instead of http://localhost/ everywhere below
   (e.g. http://localhost:8080/phpmyadmin and http://localhost:8080/akatsuki/).

---

## Part C — Put the project in and run it

1. Unzip `akatsuki.zip`. You get a folder named **`akatsuki`**.
2. Copy that folder into **`C:\xampp\htdocs\`** → you should now have `C:\xampp\htdocs\akatsuki\index.php`.
   (Be careful not to end up with `htdocs\akatsuki\akatsuki\index.php`.)
3. With Apache and MySQL both green, open **http://localhost/phpmyadmin**.
4. Click the **Import** tab at the top → **Choose File** → select `C:\xampp\htdocs\akatsuki\setup_database.sql` → scroll down → click **Import**.
   You should see "Database setup completed successfully!" and **Anya_Forger** in the left list with 21 tables.
5. Open **http://localhost/akatsuki/**
6. Log in (password for all: `password`):
   - Reader: `user@example.com` or `user2@example.com`
   - Artist: `artist@example.com` or `artist2@example.com`

---

## Part D — Every time you work on it later
1. Open XAMPP Control Panel (Run as administrator) → Start **Apache** and **MySQL**.
2. Open http://localhost/akatsuki/
3. When done, click **Stop** on MySQL and Apache **before** shutting down the PC (this prevents problem B2).

---

## Quick error → fix table
| What you see | Fix |
|---|---|
| MySQL: "Port 3306 in use by ..." | B1 |
| MySQL: "shutdown unexpectedly" | B2 |
| Browser: "Database connection failed" | MySQL isn't green, or you skipped Part C step 4 |
| Browser: "Not Found" | Folder is not `C:\xampp\htdocs\akatsuki` |
| Browser: "This site can't be reached" | Apache isn't green (B6) |
| phpMyAdmin: "#2002 No connection" / "mysqli::real_connect" | MySQL isn't running — fix with Part B |
| Unknown database 'anya_forger' | Import `setup_database.sql` (Part C step 4) |
