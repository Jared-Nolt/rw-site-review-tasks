# RW Site Review Tasks

WordPress plugin that auto-creates a recurring website-review task per company, assigned to a
maker, with a checklist, an automated site scan, a front-end maker dashboard, and a branded PDF
report. Depends on **Advanced Custom Fields PRO** (field groups are registered in code).

## Conventions

- **Function/option/hook prefix:** `srt_` — **post-type prefix:** `rwsrt_` — **text domain:** `rw-site-review-tasks`.
- **Constants:** `SRT_PLUGIN_FILE`, `SRT_PLUGIN_DIR`, `SRT_PLUGIN_URL` (defined in the main file).
- Match WordPress coding style (tabs for indentation, Yoda-free is fine, escape on output).
- **No build step.** PHP only. Lint with `php -l <file>` after edits.

## Layout

- `rw-site-review-tasks.php` — bootstrap: defines constants, `require_once`s every class, ACF-missing notice, activation/deactivation (cron schedule).
- `includes/`
  - `class-cpt-company.php` — `rwsrt_company` CPT + admin columns + **"Create review task now"** button (per-company manual run via `admin-post.php`, nonced link — never a nested `<form>`, since the meta box lives inside the post edit form).
  - `class-cpt-task.php` — `rwsrt_task` CPT (+ admin columns). `show_in_menu` is false; tasks are reached from the company's Review Tasks meta box.
  - `class-cpt-checklist.php` — `rwsrt_checklist` CPT (reusable checklist templates).
  - `class-acf-fields.php` — all ACF field groups + shared helpers (`srt_get_task_tag*`, `srt_user_can_access_task`, `srt_format_people_line`, `srt_lines_to_array`, `srt_default_executive_summary`).
  - `class-cron.php` — daily check (`run_daily_check`) + per-company runner (`run_for_company`), task creation/snapshot, due-date advance, maker/completion emails, Run log (retention configurable).
  - `class-site-scanner.php` — per-task site scan: broken links, W3C HTML validation, WAVE accessibility, Google PageSpeed (Lighthouse). Results stored in post meta; rendered in admin meta box + front-end + MG PDF.
  - `class-admin-settings.php` — Settings page (dashboard/task page IDs, restrict-to-maker, cron time, WAVE key, PageSpeed key, Run log retention) + Run status + Run log.
  - `class-import.php` — CSV company importer.
  - `class-maker-dashboard.php` — `[srt_maker_dashboard]` shortcode; buckets each company's latest task into Needs Work / Upcoming / Completed.
  - `class-task-frontend.php` — `[srt_task_view]` shortcode; the maker's editable task form (checklist + executive summary + status).
  - `class-task-print.php` — the branded PDF/print view (standard + MG variants).
- `assets/` — `dashboard.css`, `rosewood-logo.png`.

## Data model

- A **company** has a website URL, maker, marketing guide, a checklist **template**, a review interval, next due date, lead days, and PageSpeed options (enable toggle + mobile/desktop).
- On schedule (or manual run), a **task** is created for a company: it **snapshots** the template's checklist items and prefills the Executive Summary. The task's checklist is a repeater of rows (`section`, `field_type`, `label`, `choices`, `answer`).
- **Checklist sections:** a row's `section` groups it under a heading. Grouping is **sequential** — a non-empty `section` starts a group; blank-section rows continue the current group (so a section's items and its free-text summary stay together in document order). Both the PDF (`group_rows_by_section`) and the edit form use this rule.
- **Task tag** (`srt_get_task_tag_slug`): `completed`/`needs_work` come from the manual Status field; otherwise `overdue` if Period is in the past, else `ready` (an empty Period → `ready`).

## PDF report (`class-task-print.php`)

- Styled after Rosewood's "Website Health & Security Report" (maroon title, gold section headings, serif body, cream footer with logo). CSS is inline in the print `<head>` (it does **not** load `dashboard.css`).
- Sections in order: Executive Summary → checklist groups → (MG only) Site Scan Results → (MG only) Extra Notes.
- **Two variants:** standard (`srt_print_task`) and Marketing-Guide (`srt_print_task_mg`). Extra Notes and the Site Scan Results section are MG-only.
- Site Scan Results on the MG report render **collapsed** (`render_results_html($scan, true)`) — folded `<details>`; a browser-saved PDF shows only the summary bars.
- Download filename comes from `<title>`: `{Period|today}-{Company-Name}-Website-Health-Security-Report`.

## External integrations (all optional, keys in Settings)

- **W3C Nu HTML validator** — free, no key.
- **WAVE** accessibility — `srt_wave_api_key`.
- **Google PageSpeed Insights** — `srt_psi_api_key` (optional; keyless works but is rate-limited). One key for all sites; per-company toggle `psi_enabled` + `psi_strategy`. Runs on the homepage only.

## Gotchas

- The Review Tasks meta box renders **inside** the WordPress post `<form>`. Never emit a nested `<form>` there — use nonced links to `admin-post.php` (see the per-company run button and the re-scan link).
- The maker dashboard is **company-centric**: it shows one tile per company (the latest task), only for companies the user makes (when "Restrict to assigned maker" is on) and that are published. Tasks with no Period still show (treated as Ready).
- Checklist section fields must be carried through the snapshot in `SRT_Cron::create_task` — the task stores its own copy, so template edits don't retro-apply to existing tasks.
