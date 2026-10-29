# CHAT29 — Hostinger PHP + MySQL testing build

This is a **testing build**, not a security-audited production release. Payments are intentionally excluded. CHAT29 is built using plain PHP 8.2+, MySQL 8+/MariaDB 10.6+, HTML, CSS, and vanilla JavaScript. No Node.js server, npm, Composer, SSH, or build process is needed for deployment.

## Folder layout

```
CHAT29_Hostinger_PHP/
  public/                 <-- contents go into Hostinger public_html/
    index.php
    api.php
    download.php
    .htaccess
    assets/
      logo.png            <-- original transparent CHAT29 logo
      favicon.png         <-- original transparent CHAT29 favicon
      app.css
      app.js
    uploads/
      .htaccess
  private/                <-- place ONE LEVEL ABOVE public_html
    bootstrap.php
    config.example.php
    schema.sql
    .htaccess
```

**Important:** `private` must not be web-accessible. The PHP files expect `../private/` relative to `public_html`. This is the normal layout for `domains/example.com/public_html/` and `domains/example.com/private/` in Hostinger File Manager. If your hosting folder layout is different, adjust the require paths in `index.php`, `api.php`, and `download.php` and the upload path accordingly. Never put config.php inside public_html.

## Deployment using Hostinger hPanel File Manager and phpMyAdmin

1. In hPanel, go to **Websites → Manage → Databases → MySQL Databases**. Create a database and database user. Note the exact database name, username, password, and hostname.
2. Open **phpMyAdmin** for the database. Select the database, then use **Import** to import `private/schema.sql`. This creates the tables. There is no automatic installer that would expose setup operations to the internet.
3. Open Hostinger **File Manager**. Find your domain folder and `public_html`. Upload the **contents** of `public/` into `public_html/`. Make sure hidden `.htaccess` files were uploaded, including `public_html/uploads/.htaccess`.
4. Create a `private` directory **alongside** `public_html`, not inside it. Upload `private/bootstrap.php`, `private/.htaccess`, and optionally `private/schema.sql` to that directory. Do not leave `schema.sql` web-accessible.
5. Copy `private/config.example.php` to `private/config.php` and edit it with your MySQL credentials, HTTPS site URL, and a cryptographically random 64-character hexadecimal `app_key`. Generate it locally with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"` or `node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"`. Do not share the generated key.
6. In Hostinger, enable SSL for the domain and ensure `https://your-domain.com` works. CHAT29 refuses non-HTTPS public requests.
7. Ensure PHP version 8.2+ and extensions `pdo_mysql`, `mbstring`, `fileinfo`, `openssl` are enabled. Set `upload_max_filesize` and `post_max_size` to at least 6M for 5 MB uploads.
8. Open your HTTPS site. Register the first company and owner. The app creates the company and `general` channel automatically.
9. Go to **People → Invite people**, generate a 7-day invitation URL, and open it in a second private browser window. New employees can create an account from that link, or existing users can sign in with the invited email.

## Test scenarios

- Create Company A and Company B with different email accounts.
- Confirm Company B cannot access Company A conversations or attachments (test both UI and manually modified request IDs).
- Invite employees, accept invitation, and exchange messages in two browser windows. Messages update every 3 seconds via AJAX polling.
- Create a public channel, a private channel, and a direct message.
- Edit and delete only your own messages.
- Upload an allowed file (PNG, JPEG, WebP, PDF, TXT) smaller than 5 MB and download it while authenticated. Verify a logged-out request to `download.php` fails.
- Verify accessing `/uploads/<filename>` directly returns 403 on your Hostinger setup.
- Test desktop and mobile sizes and confirm all fields, links and buttons work.

## Known limitations — do not treat as production ready

- Messaging uses **3-second polling**, not WebSockets. It is suitable for a small functional pilot, not yet benchmarked for high concurrency.
- No email service is connected: invite links must be shared manually. No email verification or password reset yet.
- No malware scanning for attachments; allowed types and size are restricted. Do not invite untrusted external users until scanning is added.
- Session-based rate limits are basic and can be bypassed by clearing cookies; use host/WAF-level rate limiting before public launch.
- Private channels currently include their creator only. A UI for adding private-channel members is not implemented.
- DMs are labelled with a conversation ID in the sidebar, rather than participant names.
- No full audit of privacy, accessibility, penetration testing, load testing, backups, restore testing, or disaster recovery has been completed.
- No billing, subscriptions, super-admin console, push notifications, or multi-file uploads.
- UI is an original branded interpretation of the CHAT29 aesthetic, **not verified pixel-perfect against the Figma frames**.
- No test database/server is available in this build environment, so live end-to-end behavior remains to be validated on Hostinger.

## Security notes

- Use a dedicated database user with only necessary privileges.
- Back up the database and uploads folder regularly, and test restores.
- Never expose `private/config.php`, the database dump, or PHP error logs.
- Keep Hostinger PHP and dependencies updated. Review `uploads/.htaccess` effectiveness on the actual web server.
- Change production passwords after any accidental exposure.

## Developer checks performed for this package

- PHP syntax lint on PHP source files.
- JavaScript syntax check using `node --check`.
- File structure and asset presence inspection.

These checks do **not** constitute an end-to-end or production security certification.
