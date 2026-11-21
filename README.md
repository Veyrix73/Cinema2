# Dancing Fruit Cinema — InfinityFree edition

This repository now includes a PHP + MySQL API intended for InfinityFree shared hosting. The existing Node.js/Express files are retained as the Render/Node reference; InfinityFree uses `api.php` instead.

## InfinityFree setup

1. Create a MySQL database in the InfinityFree Control Panel.
2. Open `config.example.php`, copy it to a new file named `config.php`, and fill in the database host, database name, username and password shown in the control panel. Set a unique admin password of at least 12 characters.
3. Upload the repository's website files to the domain's `htdocs` folder. Include `index.html`, `app.js`, `styles.css`, `.htaccess`, `api.php`, and `config.php`.
4. Open the website. The PHP API creates the database tables and default price settings on its first request.
5. Sign in to the admin panel using the username and password set in `config.php`.

## Important

- The original `Cinema` repository and Render deployment are separate and have not been changed.
- InfinityFree cannot run the Node.js server. Its PHP API is implemented in `api.php`; the old `server.js` is retained for Node hosting.
- PHP sessions store customer and administrator sign-ins. HTTPS should be enabled before using the site.
- The API supports customer accounts, profiles, bookings, seat availability, lookup and cancellation, admin login, ticket scanning/check-in, movie management, screenings and prices.
- The site does not process card payments or send confirmation emails.
- Existing PostgreSQL data is not automatically copied into the new MySQL database. Existing bookings and accounts need a separate data migration if you want them carried over.
- Never upload `config.example.php` as `config.php` without replacing the placeholder values. Never commit `config.php` or expose database credentials.
