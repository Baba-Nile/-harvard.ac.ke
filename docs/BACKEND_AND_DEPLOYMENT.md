# Online learning: architecture, authentication and go-live checklist

## 1. Why this architecture

The existing site was static HTML/CSS/JS. A login screen written only in JavaScript can be bypassed by
anyone, and any "protected" file in a public folder can be downloaded by anyone who guesses its address.
Real protection must happen on a server, so the project now has two cleanly separated parts:

| Part | Where | Job |
|------|-------|-----|
| Front end | `public_html/*.html`, `assets/` | Display only. Holds no passwords, no student data, no learning materials. |
| API | `public_html/api/*.php` | Thin endpoints. Every one loads the private core and checks the session first. |
| Core | `backend/src/bootstrap.php` | Database, sessions, CSRF, rate limiting, mail. Outside the web root. |
| Admin | `public_html/admin/` + `backend/src/admin_app.php` | Staff-only panel with its own accounts and session. |
| Data | MySQL database, `backend/storage/materials/` | Student records, hashes, content; uploaded files. Never web-accessible. |

**PHP 8 + MySQL** was chosen because it is what cPanel hosting provides everywhere with no extra services,
no always-on process and no extra cost. A Node/Python service or a hosted learning platform (Moodle, Google
Classroom, an LMS) are valid alternatives if the college later wants a full LMS (quizzes, grading,
discussion); this front end talks to the API only through `assets/js/portal.js`, so the backend can be
swapped without redesigning the site.

## 2. How student authentication works

1. **Records.** An administrator registers each student from the official register (admission number and
   full registered name), one by one or by CSV import. Nothing is invented or pre-filled.
2. **Initial password.** The server stores a *hash* of the admission number (PHP `password_hash`, bcrypt, salted). The admission number itself is never stored as a password and no password is ever stored in
   plain text, in localStorage or in a cookie.
3. **Sign in.** The student enters their full name and admission number. The browser sends them over HTTPS to
   `api/login.php`. The server normalises the name (case and spacing), finds matching *active* student records and
   verifies the password hash. Name and admission number must both match the same record.
4. **First login.** While `must_change_password` is set, the session can only reach the change-password endpoint.
   The dashboard and downloads return an error until a new private password is saved.
5. **Session.** A server-side PHP session in a private folder. Cookie flags: HttpOnly, Secure, SameSite=Lax.
   A new session ID is issued at login, logout and password change. Idle timeout 30 minutes, absolute limit
   8 hours (both configurable). The student record is re-read on every request, so suspending a student takes
   effect immediately.
6. **Protection of materials.** Uploaded files are stored outside `public_html` under random names and are only
   served by `api/download.php`, which checks: signed in, password changed, student enrolled in that programme,
   programme enabled online, lesson and file published. Video and other links are shown only to enrolled students.
7. **Other protections.** CSRF token on every state-changing request; same-origin check; all SQL parameterised;
   all output rendered as text; failed-login throttling per IP (20 / 15 min) and per student name (6 / 15 min);
   identical error for unknown name and wrong password; audit log of sign-ins, resets, downloads and admin actions.
8. **Forgot password.** Student submits name + admission number. The server answers identically whether or not a
   record matches, and (if it matches) queues a request and emails admissions. Staff verify the student's identity
   (for example by calling the number on record) and press *Reset password*, which sets the password back to the
   admission number and forces a new one at next sign in. No reset links are emailed, so a student's email account
   being compromised cannot take over their portal account.
9. **Logout.** `api/logout.php` destroys the session on the server.

### Known limits you should decide on (please read)

* **Admission number as the first password is weak by nature** if admission numbers are sequential or known to
  classmates. Someone who knows a student's name and guesses the number before the student first signs in could
  claim the account (and set their own password). Mitigations already in place: throttling, forced change, audit
  log. Recommended before launch: have students sign in and change their password soon after registration, and
  consider adding a second check at first sign in (for example the phone number on record), which needs data the
  college holds but we do not have.
* **Video links are links.** If an unlisted YouTube/Vimeo link is shared, the host cannot tell who opened it. For
  strict control use a host with domain-restricted embedding or upload video as files.
* **Admin accounts have no two-factor sign-in.** Use strong, unique passwords, one account per person.
* **Shared networks.** Throttling by IP means many students behind one router share the 20-attempt limit.

## 3. Go-live checklist (cPanel)

1. **Create the database** (cPanel > MySQL Databases): a database, a user, a strong password; add the user to the
   database with all privileges.
2. **Import `backend/database/schema.sql`** in phpMyAdmin into that empty database. It creates the tables and the
   college's 7 departments and 50 courses. No students, lessons or files exist; every programme starts as
   *not available online*.
3. **Upload** `public_html/*` into the domain's `public_html`, and the whole `backend` folder into the account home
   folder beside `public_html` (so the path is `/home/USER/backend`). Do not put `backend` inside `public_html`.
4. **Choose PHP 8.1+** (cPanel > MultiPHP Manager) and enable `pdo_mysql` and `mbstring` (cPanel > Select PHP Version).
5. **Create `backend/config/config.php`** by copying `config.sample.php` and filling in: database name/user/password,
   `app.secret` (a random 64-character string), and `mail.from` (a mailbox on the college's own domain).
   Never put this file in `public_html`.
6. **Make storage writable**: `backend/storage/materials`, `sessions` and `logs` (permissions 750 or 755 as the host requires).
7. **Switch on SSL** (cPanel > SSL/TLS Status, AutoSSL), then un-comment the HTTPS redirect in `public_html/.htaccess`.
   The API refuses to run over plain HTTP.
8. **Raise upload limits** if needed (MultiPHP INI Editor): `upload_max_filesize` and `post_max_size` of at least 20M.
9. **Create the first administrator** in cPanel Terminal: `php backend/bin/create-admin.php username "Full Name"`.
   If Terminal is not available on your plan, ask the hosting provider or the developer to run it once.
10. **Run `php backend/bin/check-setup.php`** and fix anything marked FAIL.
11. **Sign in at `/admin/`**, register a test student, enable one programme, enrol the student, add a lesson, upload a
    file, then test the whole student journey on a phone before announcing anything.
12. Confirm in the browser that `https://yourdomain/backend/config/config.php` and `https://yourdomain/api/_init.php`
    return an error (404/403), never a blank page or code.

## 4. Administrator functions (admin panel at /admin/)

Register students (single or CSV); the admission number becomes the initial password. Suspend, withdraw or
reactivate access. Reset a password. Enrol and un-enrol students. Enable or disable each of the 50 programmes for
online learning. Add lessons with descriptions, upload downloadable files (PDF, Word, PowerPoint, Excel, text, CSV,
JPG, PNG), add video and other https links, add assignments with due dates, post announcements to everyone or to
one programme. Handle password-reset requests. Read website enquiries and applications. Create staff accounts
(administrator or editor).

## 5. Configuration and items still required from the college or the host

| Item | Needed for |
|------|-----------|
| Hosting plan with PHP 8.0+ and MySQL (and Terminal access, or someone to run one command) | online learning |
| Domain name, SSL certificate active | HTTPS (required by the API), absolute `og:image` and sitemap |
| Database name, user and password | `config.php` |
| A mailbox on the college domain for `mail.from` | emailed enquiries, reset alerts |
| Official student register (admission numbers + registered names) | registration or CSV import |
| Decision on who the administrators are | staff accounts |
| Which programmes to open for online learning, and the learning material for them | nothing is shown until supplied |
| College photographs | replace the illustrative stock photographs |
| Physical address and map location | contact page, FAQ (currently says Nakuru only) |
| Fees, intake dates, application requirements, durations, accreditation details | not shown anywhere: nothing was invented |
| Campus video link, news items, partners, student testimonials | those sections were removed until real content exists |
| A Facebook *page* URL (the supplied Facebook link is to a single post) | footer and contact links |
| Optional: a data protection / privacy notice | the forms and the portal store personal data |
