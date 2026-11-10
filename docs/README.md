# Harvard Extention Training College: website and online learning

## What is in this package

```
harvard-college-website/
  public_html/    Upload the CONTENTS of this folder to the domain's public_html (cPanel File Manager).
                  The 13 website pages, shared CSS/JS, the PHP API (api/) and the admin panel (admin/).
  backend/        Upload this folder NEXT TO public_html (in the account's home folder), NOT inside it.
                  Private PHP code, database schema, configuration template, private file storage.
  docs/           This file, BACKEND_AND_DEPLOYMENT.md, TEST_REPORT.md
```

The public website is plain HTML, CSS and JavaScript and works on cPanel hosting on its own.
Online learning (student login, dashboard, protected downloads, admin panel, and delivery of the
contact/application forms) additionally needs PHP 8.0+ and a MySQL/MariaDB database, which cPanel
hosting normally includes. Until the backend is installed, the public site still works: the Online
Classes page shows "Online learning availability: Contact admissions" for every programme, and the
student login page tells students the portal is not reachable.

Read next: `BACKEND_AND_DEPLOYMENT.md` (how to go live) and `TEST_REPORT.md` (what was tested, what was not).

## Pages

index, about, departments, department (one page, `?dept=` shows each of the 7 departments), admissions,
campus-life, news, contact, **online-classes**, **student-login**, **student-password** (first-login
and later password change), **student-forgot**, **student-dashboard**.
