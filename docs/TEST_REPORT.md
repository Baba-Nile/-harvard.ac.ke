# Test report

Tested in headless Chromium (Playwright) against the built `public_html`, with the server API simulated
by a mock that follows the same request/response contract as the PHP endpoints. External resources
(Google Fonts, Unsplash) were not reachable in the test environment: fonts fell back to system fonts and
photographs were replaced by correctly proportioned placeholders. **Please re-check on a real device
with internet access** (see "Not verified").

## 1. Responsive audit

12 pages (13 counting the department page for several departments) x 9 widths = 108 page loads:
320, 360, 375, 414, 768, 1024, 1280, 1440, 1920 px. The student dashboard (with sample data) was also
checked at 320, 360, 375, 414, 768, 1024, 1180, 1280, 1440 and 1920 px.

| Check | Result |
|-------|--------|
| Horizontal scrolling (document and any element past the viewport edge) | none at any width |
| Browser console errors / uncaught script errors | none |
| Desktop navigation on one line at 1180, 1280, 1440, 1920 px (no wrapped items) | confirmed |
| Mobile menu below 1180 px: opens, closes with the button and Escape, includes Online Classes, Student login and Apply now, navigates | confirmed at 360 px |
| Sticky header: content is never hidden behind it (header is in normal flow, `scroll-padding-top` for anchors) | by construction; anchors #programmes, #application checked visually |
| WhatsApp button: fixed bottom-right inside the viewport, 48 px on phones, 54 px from 640 px, respects iPhone safe area | confirmed |
| Images: width/height attributes, `aspect-ratio` + `object-fit: cover` (no stretching), lazy loading below the fold, hero image `fetchpriority=high`, `srcset` 480/800/1200 | confirmed in markup |

## 2. Functional tests (61 automated checks, all passing)

* Online Classes lists all 50 programmes; search by name, department filter, "available online only" filter and the empty
  state work; enabled programmes link to sign-in with the programme preselected; every other programme shows
  "Online learning availability: Contact admissions."
* Login: validation messages, wrong-credentials message, show/hide password, redirect to the forced password change on
  first login, dashboard blocked until the password is changed, weak/mismatched/incorrect-current password messages,
  dashboard shows the registered name and admission number, only the enrolled programmes, lesson description rendered
  as plain text, downloads routed through the protected endpoint, unsafe `javascript:` links refused, assignments and
  announcements, programme tabs, logout, redirect to login when not signed in.
* Contact, application and forgot-password forms: field errors, summary message, success message; course list follows
  the chosen department.
* Department page for every key, unknown department handled; departments page totals exactly 50 courses in 7 departments.
* Every page has the developer credit, the WhatsApp link with the prefilled message, the supplied Facebook and TikTok
  links, and the Online Classes navigation item. No placeholder text, no `href="#"`, no Instagram/X/LinkedIn/YouTube
  links, no broken internal links, every `target="_blank"` link has `rel="noopener noreferrer"`.
* Markup: one `<h1>` per page, every image has alt text and dimensions, every form control has a label, title and
  description on every page.

One real bug was found and fixed by these tests: re-rendering the programme list when the search box lost focus could
swallow the first click on a programme button.

## 3. Performance

Shared CSS 22 KB (6 KB gzipped), JavaScript 37 KB in total across four files (10 KB gzipped; only the files a page
needs are loaded). Bootstrap, Font Awesome and the old CDN requests were removed; icons are a 7.7 KB local SVG sprite.
The only external requests are Google Fonts and Unsplash photographs. The 834 KB original logo is kept (and used
as the social-share image); pages use a 220 px copy of the same artwork (83 KB).

## 4. Not verified: please check before launch

1. **The PHP backend has not been executed.** PHP is not available in the test environment. The 13 PHP files passed
   lexical and bracket checks only. Run `php -l` on each file and `php backend/bin/check-setup.php` on the host,
   then complete the end-to-end test in step 11 of the go-live checklist. Expect to fix small issues on first run.
2. The mock API proves the front end follows the contract; it does not prove the server enforces it. Test wrong
   passwords, direct access to `api/dashboard.php` and `api/download.php?id=1` while signed out, and a student
   requesting another programme's file.
3. Photographs are hot-linked stock images from Unsplash and were not visually confirmed (no internet access here).
   They are labelled "Illustrative photography" on Campus Life. Replace them with college photographs.
4. Google Fonts and the Unsplash photographs were not loaded in testing, so typography and photo cropping must be
   reviewed in a real browser. Email delivery (`mail()`) depends on the host.
5. Not tested on physical phones, Safari/iOS or Firefox. Real-device checks are still needed.

## 5. What changed from the previous version

Rebuilt shared header/footer into every page (works without JavaScript). Replaced the pattern of seven equally styled
rounded cards, circular icon badges, spinning seal, counters, reveal animations and heavy shadows with a flat
institutional layout. Removed because they contained no real content or no working destination: partners and
student-voices placeholders, "5 core support pillars" statistic, newsletter form (it posted to an unset Formspree
address), campus video modal (no video URL), map embed (generic Nakuru pin), "popular courses" (no data behind
"popular"), back-to-top button, and all `[ADD: ...]` text. The enquiry and application forms now post to the new
PHP endpoint, store each message and email the college. All 7 departments and exactly 50 courses are preserved.
