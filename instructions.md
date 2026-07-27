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
  - `class-acf-fields.php` — all ACF field groups + shared helpers (`srt_get_task_tag*`, `srt_user_can_access_task`, `srt_editor_post_type_capabilities`, `srt_get_assigned_user_id`, `srt_format_people_line`, `srt_lines_to_array`, `srt_default_executive_summary`).
  - `class-cron.php` — daily check (`run_daily_check`) + per-company runner (`run_for_company`), task creation/snapshot, due-date advance, maker/completion emails, Run log (retention configurable).
  - `class-site-scanner.php` — per-task site scan: broken links, W3C HTML validation, WAVE accessibility, Google PageSpeed (Lighthouse). Results stored in post meta; rendered in admin meta box + front-end + MG PDF.
  - `class-mailer.php` — `SRT_Mailer::send()`, the only place notification email is built/sent. HTML body + plain-text `AltBody`, configurable From/Reply-To, envelope sender aligned with From.
  - `class-admin-settings.php` — Settings page (dashboard/task page IDs, restrict-to-maker, cron time, WAVE key, PageSpeed key, Run log retention, email From name/address + Reply-To) + email deliverability notes and test send + Run status + Run log.
  - `class-import.php` — CSV company importer.
  - `class-roles.php` — the optional `srt_marketing_guide` ("Marketing Guide") role, `read` only. Installed on activation and via a version-guarded `init` check (`srt_roles_version`).
  - `class-maker-dashboard.php` — `[srt_maker_dashboard]` shortcode; buckets each company's latest task into Needs Work / Upcoming / Completed. Lists companies the user is maker **or** marketing guide of. Only the latest task per company appears — there is no historical list.
  - `class-task-frontend.php` — `[srt_task_view]` shortcode; the maker's editable task form (checklist + executive summary + status).
  - `class-task-print.php` — the branded PDF/print view (standard + MG variants).
- `assets/` — `dashboard.css`, `rosewood-logo.png`.

## Data model

- A **company** has a website URL, maker, marketing guide, a checklist **template**, a review interval, next due date, a due-date rule, lead days, and PageSpeed options (enable toggle + mobile/desktop).
- **Due Date Rule** (`due_date_mode`): `fixed_date` keeps the calendar day when advancing by the interval; `weekday` re-resolves `due_week` + `due_weekday` in the target month, so "last Wednesday" stays the last Wednesday instead of drifting. Helpers live in `class-acf-fields.php` (`srt_company_advance_due_date`, `srt_company_apply_due_rule`); saving a company snaps Next Due Date to the rule via `SRT_CPT_Company::snap_due_date_to_rule`.
- On schedule (or manual run), a **task** is created for a company: it **snapshots** the template's checklist items and prefills the Executive Summary. The task's checklist is a repeater of rows (`section`, `field_type`, `label`, `choices`, `answer`).
- **Checklist sections:** a row's `section` groups it under a heading. Grouping is **sequential** — a non-empty `section` starts a group; blank-section rows continue the current group (so a section's items and its free-text summary stay together in document order). Both the PDF (`group_rows_by_section`) and the edit form use this rule.
- **Task tag** (`srt_get_task_tag_slug`): `completed`/`needs_work` come from the manual Status field; otherwise `overdue` if Period is in the past, else `ready` (an empty Period → `ready`).

## Access control

- **Admin screens are Editor-and-above.** All three CPTs register with `map_meta_cap => true` and `'capabilities' => srt_editor_post_type_capabilities()`, which pins every primitive capability to `edit_others_posts` — the lowest cap an Editor has and an Author/Contributor does not. Settings and Import CSV stay on `manage_options`.
- **Never put `edit_post` / `read_post` / `delete_post` in that capabilities array.** `register_post_type()` passes them to `_post_type_meta_capabilities()`, which registers `$post_type_meta_caps[ <your value> ] = <meta cap>` **globally**. Aliasing them to a real primitive (e.g. `'edit_post' => 'edit_others_posts'`) makes `map_meta_cap()` reroute every site-wide `current_user_can( 'edit_others_posts' )` — core's Posts and Pages screens included — into a post-specific check with no post ID, which returns `do_not_allow`. The symptom is the plugin's admin menus silently vanishing for administrators. Only primitive caps belong in the array.
- **Front end is assignment-based, not role-based**, so a maker or marketing guide of any role (they're typically Subscribers, or hold the `srt_marketing_guide` role) can use `[srt_maker_dashboard]` and `[srt_task_view]`. `srt_user_can_access_task()` allows: anyone covered by `srt_user_can_see_all_tasks()`, whoever is named on the task, and whoever is named on the **task's company**. That last fall-through is load-bearing — see below.
- **Task assignments are snapshots; company assignments are current.** `create_task()` copies maker and marketing guide off the company at creation, so tasks created before someone was assigned carry no marketing guide, and reassigning a company leaves its existing tasks naming the previous person. Access therefore falls through to the company, which also keeps the dashboard honest: that query is company-level, so a task-only check would list a company whose task the user is then denied.
- **The Marketing Guide role (`class-roles.php`) is optional and grants nothing.** It holds `read` and nothing else; it exists so a marketing guide can have a login with no authority over the rest of the site. Their access to review work comes entirely from the ACF assignment, so an existing Subscriber works just as well. It is not removed on deactivation — that would strand assigned users.
- Both maker and marketing guide get the **same** rights on a task, including edit. If marketing guides should be read-only, that's a separate change in `class-task-frontend.php`.
- **Authenticate before checking the post type** in `class-task-print.php` and `class-task-frontend.php`, otherwise the differing responses let anonymous visitors enumerate which post IDs are review tasks.
- Read ACF user fields with `srt_get_assigned_user_id()`, never `(int) get_field(...)` — `(int) array( 5 )` is `1`, which would lock out the real assignee and match user ID 1 instead.

## Outbound HTTP (`class-site-scanner.php`)

- Links harvested from a scanned page are **third-party input** (client sites carry comments and other UGC). The page fetch and the per-link `HEAD` use `wp_safe_remote_get` / `wp_safe_remote_request`, and each link passes `is_unsafe_target()` first — that runs `wp_http_validate_url()` (blocks loopback/RFC1918 with DNS resolution, limits ports to 80/443/8080) plus a `169.254.0.0/16` check core omits, where cloud instance metadata lives.
- Blocked links are counted into `skipped` and reported in the summary — they're never listed as "broken", which would be a false finding about the client's site.
- The W3C / WAVE / PageSpeed calls stay on plain `wp_remote_get`: the target is a fixed third-party host and the scanned URL is only a query parameter.

## Notification email (`class-mailer.php`)

- **Never call `wp_mail()` directly** for notifications — go through `SRT_Mailer::send( $to, $subject, $heading, $paragraphs, $cta_url, $cta_label )` so every message gets the same From/Reply-To, HTML + plain-text parts, and aligned envelope sender.
- Two notifications, both in `class-cron.php`: `notify_maker()` (task created) and `notify_completed()` (status saved as Completed → maker + marketing guide, sent individually).
- **Deliverability split:** the plugin controls the From address, the multipart body, and Return-Path alignment. It cannot control the transport or DNS — authenticated SMTP (Kinsta does not deliver PHP `mail()`) plus SPF/DKIM/DMARC on the sending domain are required, and the Settings page says so.
- `is_from_aligned()` warns on the settings screen when the From domain isn't the site domain or a subdomain of it (relaxed DMARC alignment).

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
