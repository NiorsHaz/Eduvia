# Lycée Horizon — High School Management & Administration System

An API-first school administration system built from *High School Management & Administration System — Updated Scope and Implementation* (v1.1). Phases 1–3 of the blueprint are implemented: school structure and student records, configurable academic rules with results and report cards, and an append-only finance module with receipts and approvals.

The interface is in French (Madagascar), with French / Malagasy / English locale switching. Currency is Ariary, stored as integers.

## Stack

| Layer | Choice |
| --- | --- |
| API | Laravel 12 (PHP 8.2+), Sanctum bearer tokens |
| Database | PostgreSQL 15+ for production; SQLite for local development |
| Permissions | `spatie/laravel-permission` (roles + granular permissions) |
| Audit | `spatie/laravel-activitylog` |
| Admin SPA | Vue 3, Vue Router, PrimeVue 4 (MIT line), vue-i18n, Vite |
| Mobile | Flutter (Phase 4 — planned, not included) |

## Quick start

```bash
composer install
npm install

cp .env.example .env        # then set APP_KEY and database values
php artisan key:generate

php artisan migrate:fresh --seed
npm run build               # or: npm run dev

php artisan serve --host=127.0.0.1 --port=8080
```

Open <http://127.0.0.1:8080>. The API lives under `/api/v1`.

The seeder creates a complete demo school: campus, 2026‑2027 year with three terms, levels, tracks, subjects, rooms, staff and assignments, a weekly timetable, 36 students with verified guardians, a grading policy with promotion rules, assessments and grades, term results, attendance, a three-installment fee plan with generated charges, verified cash/MVola payments with receipts, scholarships and six report cards.

### Demo accounts

Password for every account: `Password123!`

| Account | Role |
| --- | --- |
| `admin@school.mg` | super_admin (all modules) |
| `director@school.mg` | director (approvals, policies) |
| `teacher@school.mg` | teacher (gradebook, attendance) |
| `finance@school.mg` | finance (payments, expenses) |
| `parent@school.mg` | guardian (portal scope) |

## What is implemented

### Phase 1 — School, people, access

- Campuses, buildings, rooms, academic years and terms, levels, tracks, subjects, classes, departments, staff.
- Student records with generated unique student numbers, admissions/enrolments, health records, guardians with verified links and separate academic/financial visibility.
- CSV student import with per-row validation report.
- Users, roles, permissions, temporary role grants (time-boxed), login history, account deactivation and token revocation, audit log.
- Activity log with filters by journal, text and date.

### Phase 2 — Configurable academics

- **Rules are data**: settings registry (`/settings`) with typed values, allowed scopes (school, campus, year, level) and permission gates.
- **Versioned grading policies**: per year and level, pass/eliminatory marks, rounding, absence and missing-grade handling, averaging methods, subject exceptions, lock with reason, clone into a new version. Existing results keep the policy they were computed with.
- **Promotion rule sets**: conditions are stored as JSON and evaluated by a whitelisted metric evaluator (`GradeCalculator` / `PromotionEvaluator`) — never executable code. Decisions (promoted, repeat, conditional, council review, graduated, left) require human confirmation.
- Gradebook: assessments per class-subject-teacher, entry sheet, submit → validate → reopen workflow with mandatory reason, policy locking on validation.
- Term results: computed snapshots (subject averages, class averages, ranks, metrics), annual decisions with review and confirmation.
- Report cards: one active version per student and term, immutable snapshots, subject lines with class averages and appreciation, publish step, print layout.

### Phase 3 — Finance

- Fee plans with installments, fee structures by level/category, idempotent charge generation, discounts and scholarships.
- Append-only ledger: payments are never rewritten; corrections are reversals, and balances are always recomputed.
- Gapless receipt and invoice numbering through `NumberSeriesService` (e.g. `REC-2026-000001`), allocated inside transactions.
- Payment intake (cash, bank transfer, mobile money), verification, manual or automatic allocation to charges (oldest due first, by category priority, or manual), unapplied credit handling.
- Four-eyes approvals: reversals, refunds and expenses above thresholds require a second authorised user.
- Expense recording, approval and payment; finance dashboard with collected/outstanding, payment-method breakdown, expected vs collected by level, overdue installments, aging and monthly collection.
- Receipt printing in the browser.

### Cross-cutting

- Permission checks on every route; server-side authorisation is the only authority.
- Sensitive changes (grades, attendance, payments, permissions, policy locks) are written to the activity log with actor and context.
- Attendance register by class and day with statuses, late minutes, justifications, edit history and idempotent submission support for offline clients.
- Configurable rules cover attendance mode, lateness policy, grading visibility windows, finance behaviour, student number format and document numbering.

## Architecture

```
app/
  Http/Controllers/Api/     Auth, Dashboard, Reference (generic CRUD), Student, Guardian,
                            Classroom, Timetable, Attendance, Gradebook, Results, Settings,
                            Fee, Payment, Charge, FinanceReport, Expense, User, Audit
  Models/                   ~70 Eloquent models mapped to the schema drafts
  Services/                 SettingService, NumberSeriesService, TimetableService,
                            AttendanceService, GradeCalculator, PromotionEvaluator,
                            PolicyService, FeeService, PaymentService, ReportCardService
config/
  references.php            Registry of structural resources served by ReferenceController
  school.php                School defaults (timezone, locale, currency, name)
database/
  migrations/               Phase 1 / 2 / 3 schemas
  seeders/                  Permissions, reference data, full demo school
resources/js/               Vue 3 SPA (views, components, stores, router, i18n)
routes/api.php              /api/v1 routes, grouped by permission
phase{,1,2,2b,3,4,5}_schema.sql   Original PostgreSQL design drafts (see below)
```

Reference data (levels, rooms, subjects, fee categories, payment methods, number series, …) is served by one registry-driven controller, so adding a lookup is a config entry rather than a new endpoint.

## Configuration highlights

- `config/school.php` and `.env`: school name, timezone (`Indian/Antananarivo`), locale, currency.
- Settings registry: values may be overridden per campus, academic year or level; the most specific scope wins. Enum values are validated server-side.
- Grading policy: exactly one active policy per year/level; the most specific level policy overrides the year default.
- Number series: pattern placeholders `{YEAR}`, `{SEQ:n}`; sequences are locked and incremented in the same transaction as the document.
- File storage is configured for the private disk; documents are never exposed as public URLs.

## Switching to PostgreSQL

The migrations are portable. To run on PostgreSQL 15+:

1. Create the database and set `DB_CONNECTION=pgsql` plus `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` in `.env`.
2. Run `php artisan migrate:fresh --seed`.

The `phase*_schema.sql` files are the original PostgreSQL design drafts kept for reference. They contain database-level guarantees that SQLite cannot express and that are therefore enforced in the service layer instead: exclusion constraints for timetable/teacher/room overlaps, partial unique indexes (one active policy per year/level, one active report card version), append-only ledger triggers, number-series locking functions and money/date check constraints. Those DDL objects are **not** applied by the Laravel migrations; add them as a PostgreSQL-only migration (or via `psql`) before production use.

## Verification status

- `php artisan migrate:fresh --seed` succeeds and seeds the full demo dataset.
- `npm run build` succeeds.
- The API was smoke-tested with a real token across dashboard, students, classes, guardians, reference endpoints, settings, grading policies and promotion rules, timetable, assessments, terms results, report cards, attendance roster, finance summary, payments, charges, guardians, users, roles and audit.
- The SPA was exercised in a browser: login, dashboard, students list and detail, gradebook sheet list, results ranking, report-card preview (rendered from its snapshot), finance overview, policies (detail with subject exceptions and promotion rules), timetable grid, attendance register, users and audit. No console errors remained after the fixes above.
- Not covered: automated test suite (the scaffold's example tests remain), production PostgreSQL run, mobile app, and the Phase 4–5 modules below. Receipt/report-card printing uses the browser print dialog and was not sent to a printer.

## Roadmap (from the blueprint, not implemented)

- **Phase 4** — Flutter mobile app for guardians/teachers, push notifications, offline attendance/grade entry.
- **Phase 5** — Library, transport, payroll, inventory, AI-assisted analytics. Design drafts exist (`phase4_schema.sql`, `phase5_schema.sql`).

## Notes

- PrimeVue is pinned to the 4.3 line (MIT). PrimeVue 5 requires a commercial PrimeUI licence and shows a licensing watermark, so do not upgrade without one.
- Authentication is bearer-token based (token stored in `localStorage`), so no SPA cookie/CSRF configuration is required. For a same-site cookie setup, enable Sanctum's stateful domains and switch the client accordingly.
- Demo payments are historical seed rows; real receipt numbers are issued by the number series for anything created through the UI.
