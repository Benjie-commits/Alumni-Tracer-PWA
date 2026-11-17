# SUN-ATES manual testing checklist

Spec section 12 calls for automated tests (in `backend/tests`, `pwa/tests`) **and** manual checks that only a person
with real devices and the Registrar's staff can do. Record each run: date, tester, device/browser, result, notes.
Use anonymised historical records (spec 12), never live alumni data, on a staging copy.

## A. Automated suites (run before every release)

```powershell
cd backend ; php artisan test        # needs the sunates_testing MySQL database
cd pwa     ; npm test
cd pwa     ; npm run build
```

## B. Throttled 3G load test (spec section 4 "Performance", section 12)

1. Build the PWA (`npm run build`) and serve it as in production (or `npm run preview`).
2. Chrome DevTools → Network: *Slow 3G* (or a custom 400 kbps / 400 ms profile); CPU 4× slowdown; *Disable cache*.
3. Lighthouse (mobile) on `/login` and, signed in, on `/`. Record First Contentful Paint and Time to Interactive.
4. Pass: the sign-in screen is usable within a few seconds on the throttled profile; record the numbers here.

| Date | Page | FCP | TTI | Total transfer | Pass? |
|---|---|---|---|---|---|
| | | | | | |

## C. Offline survey queue (spec section 4 "Offline behaviour", section 12)

Do on **one Android phone (Chrome)** and **one iPhone (Safari, installed to home screen)**:

1. Open a survey link `/s/<token>`; answer the questions; switch on airplane mode; submit.
   - Expect: "saved on your phone and will be sent as soon as you're online".
2. Close the app completely. Turn airplane mode off. Re-open the app.
   - Expect: the answer is sent on open (iOS has no background sync); the console shows the response.
3. Repeat with the app left open: turning the connection back on sends it within ~30 s.
4. Submit, lose connection mid-request, retry: the console shows **one** response (no duplicate).
5. Sign in, queue an answer offline, then *Sign out*: you are asked whether to delete or keep the waiting answer.

| Date | Device / OS / browser | Step 1 | 2 | 3 | 4 | 5 | Notes |
|---|---|---|---|---|---|---|---|
| | | | | | | | |

## D. User-acceptance walkthrough with Registrar staff

| # | Workflow | Who | Expected |
|---|---|---|---|
| 1 | Import a CSV (anonymised), preview, commit | Registrar | report matches the sheet; re-import changes nothing |
| 2 | Register as a graduate whose record has a phone | Alumnus | code arrives (sandbox / log); account verified |
| 3 | Register with a misspelt surname | Alumnus | "waiting for the Registrar"; appears in Verification queue; staff link it |
| 4 | Forgot password | Alumnus | email link; new password works; other devices signed out |
| 5 | Edit a record's phone and email | Registrar | saved; sign-in email follows; Activity log shows field names only |
| 6 | Employer lookup by full name | Employer | verified + programme + year only; ambiguous never lists people |
| 7 | Outcome dashboard, export | QA viewer | small groups withheld; export matches screen; no contact details |
| 8 | Post a job aimed at one programme | Alumni desk | only that programme's alumni see it in *Jobs & events* |
| 9 | Post an event for graduates from 2023 on | Alumni desk | only those alumni see it |
| 10 | Alumnus offers to mentor; staff add a student and match | Both | mentor sees the student; contact only if the student agreed |
| 11 | Export the directory | Registrar | Activity log records the export and filters, not the search text |
| 12 | QA viewer tries Jobs / Mentorship / Activity log | QA | refused (403) |

## E. Security spot checks (staging)

- [ ] 6 wrong sign-ins for one email → "too many attempts"; 21 sign-ins for different emails from one address → refused.
- [ ] Changing the sign-in email in the app without the current password is refused.
- [ ] 6 wrong registration codes → the code stops working, even the right one.
- [ ] `/admin/activity` as Registrar → 403.
- [ ] Response headers include HSTS (over https), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`.
