# Iminify Image Optimizer

The WordPress plugin for Iminify (https://www.iminify.com), an image optimization service. Its wordpress.org slug, main file and text domain are `iminify-image-optimizer`.

## The plugin is thin on purpose

The service decides everything it can: a site's settings, which outputs are worth keeping, every limit and count, the bulk estimate, the status and upgrade messages. The plugin does only what has to run inside WordPress: catch uploads, edits and deletions, send an attachment's image, write back what the service returns, keep and restore backups, print `<picture>` markup for copies that exist, keep its own per-attachment state, and show what the service says. A rule that could run on the service goes there, because code in a public plugin can be read and changed by anyone who installs it. Every number the plugin shows comes from the service's answers, never typed into its copy.

## Rules, each with its reason

- **PHP 7.4 syntax.** WordPress runs on PHP 7.4 and later, and about one site in seven is still on 7.4. So no union types, `match`, enums, `readonly`, constructor promotion, named arguments, the nullsafe operator, `mixed` or a `static` return type. PHPCompatibilityWP (in `composer lint`) holds the code to it. WordPress 5.9 and later polyfill `str_contains()`, `str_starts_with()` and `str_ends_with()`.
- **WordPress 6.9 and later**, the floor of the bundled Action Scheduler, which follows WordPress's latest release minus two.
- **WordPress's coding standards** (WPCS 3, `WordPress-Extra`): the directory's reviewers and its automated release scan read code against them. Run `composer lint`; `vendor/bin/phpcbf` fixes what it can.
- **Prefixes everywhere:** `iminify_` for functions, options, hooks, transients and post meta, `IMINIFY_` for constants, the namespace `Iminify\WordPress`, and the literal text domain `iminify-image-optimizer` in every translation call. A WordPress site shares one global scope with every other plugin.
- **Files:** classes live in `includes/` under WordPress's file names (`Key_Store` in `includes/class-key-store.php`, `Api\Client` in `includes/api/class-client.php`), which `includes/class-autoloader.php` maps. Every PHP file starts with the `ABSPATH` guard.
- **No runtime libraries but Action Scheduler.** Two plugins that bundle different versions of one library break each other on the same site. Action Scheduler is the exception by design: it is loaded from the main file before `plugins_loaded`, never prefixed, and the newest copy on a site runs.
- **Every call to the service goes through `Api\Client`** on WordPress's HTTP functions, with TLS verified and no redirect followed. The site key is stored sealed (`Key_Store`), and is never printed, never sent to a browser, never put in a REST answer or a script.
- **Nothing reaches the service before the site is connected** (directory guideline 7): no call on activation, no telemetry, no remote assets.
- **Nothing that runs locally is locked by plan** (directory guideline 5): restore, serving WebP and AVIF copies that exist, the bulk screens, WP-CLI and backups keep working on any plan, with the day's images spent, or disconnected. Only the service refuses work.
- **Notices only on the plugin's own screens**, plus at most one dismissible notice elsewhere; no admin bar item and nothing on the front end by default (guidelines 10 and 11).
- **Files the plugin writes go under the uploads folder**, never into its own folder, which every update replaces.
- **Optimizing runs in the background and never waits.** An upload, an edit or regenerated sizes only queues the attachment (`Media\Hooks`), since an upload request is already busy making sizes. `Media\Optimizer`'s Action Scheduler actions open a group, send the original and check back later: a `Retry-After`, a group still being made or an outage becomes the next run's time, never a `sleep()`, and a spent day holds no run at all (below).
- **An attachment's record (`Media\Records`, the `_iminify` post meta) is the only proof a file is the plugin's own.** It names every file the plugin wrote over, with the bytes it wrote, and every WebP or AVIF copy with its origin. The original comes only from `Media\Originals::find()`, which never answers a file the plugin wrote, only its backup, so no image is compressed twice. Delete only copies whose recorded origin is the plugin's.
- **A file is replaced, never rewritten in place:** `Media\Files::replace()` writes a temporary file beside it, checks it, and renames it over the old one, because WP_Filesystem's `move()` deletes the target first. A name inside a zip is never used as a path: `Media\Zip` reads only the entries the service's answer named.
- **The attachment metadata is read fresh right before it is saved, and only `filesize` and the main file's width and height change in it**, so keys other plugins keep there survive. The save runs with `Media\Writer::$writing` set, and the plugin's own hooks ignore it. A restore makes the sizes again with core (`Media\Restorer`), under the same guard, and merges what core made over the old metadata for the same reason.
- **A wait holds no runs.** An attachment waiting for the next batch reads `paused` and one waiting for the connection `parked`, with nothing scheduled, and `Media\Queue` is the one routine that sends them on, from the timer, "Start now" or the first `GET /site` that works. Queueing them one run each would make a long queue fire and refuse itself. Only a spent day stops every group (`Media\Pause`, on the `daily_limit_reached` code, never on a 429 alone: `rate_limited` is the same status and stays one attachment's wait), and a group already open always goes on, since its image is counted. A parked attachment keeps its group for the same reason.
- **`_iminify` is serialized, so nothing can query it.** `Media\Records::update()` also writes `_iminify_status` and `_iminify_formats`, which the queue and the media library's filters read. Change a record only through `Records`, or those two drift.
- **Never write over the only copy of an image with backups on.** No room for the backup, or a backup that can't be saved, leaves the image as it is with nothing counted (`disk_full`, `backup_failed`); it never goes on without one. Below `Media\Disk::RESERVE` no group opens, and each write is checked first, so a copy with no room is skipped and never recorded.
- **Optimizing an image again restores it first** (`Media\Optimizer::ask()`), because the service only replaces a file with a smaller one, so the plugin's last output would stand against a gentler level. A request that can't be taken now changes nothing.
- **Admin screens draw state the server builds.** The settings page's script (`src/settings`) only draws what `Admin\State` answers through the plugin's REST routes (`iminify/v1`, `manage_options` only), and every call to the service happens behind them: the logic stays testable in PHP, and no answer ever carries the site key or a pairing's secret. The media library's script (`src/media`) only puts back the markup `Admin\Media_View` builds, through `Admin\Media_Controller`'s routes, which ask for `upload_files` and `edit_post` on that attachment.
- **Inside the attachment panel, use buttons, not selects or inputs.** The media modal's attachment fields save on every `change` event inside them and draw themselves again, which would throw a choice away.
- **Admin scripts are built with `@wordpress/scripts`** from `src/` into the gitignored `assets/build/` (`npm run build`). `src/` and `package.json` ship with the plugin so anyone can rebuild what it runs (directory guideline 4). WordPress's own packages (`@wordpress/components`, `element`, `i18n`, `api-fetch`) load as core's copies and are never installed here, which `eslint.config.cjs` tells the linter, so use only what the oldest supported WordPress has. The build minifies only when `NODE_ENV` is unset or `production`.

## Tests and checks

- `composer lint`: PHPCS with WPCS and PHPCompatibilityWP.
- `composer types`: PHPStan at level 8 with the WordPress extension. The integration tests stay outside it, since nothing describes WordPress's test library to it.
- `composer test`: the unit tests in `tests/Unit`, PHPUnit 9 with Brain Monkey and no WordPress loaded.
- `npm run lint:js`: wp-scripts' ESLint and Prettier on `src/`; `npx wp-scripts lint-js src --fix` fixes the formatting.
- `npm run test:integration`: the integration tests in `tests/Integration`, on WordPress's own test library inside the test environment (start it first with `npm run env:test start`). WordPress's test library runs on PHPUnit 9 with Yoast's polyfills, so the suite does too.
- Tests never reach the real service or use a real key: `tests/Support/Api_Stub.php` answers the API's calls through `pre_http_request`, from the answers in `tests/fixtures`, and a call nothing set up answers 404.
- `php bin/build.php` copies what ships into `build/iminify-image-optimizer`: the tracked files `.distignore` doesn't exclude, the built admin scripts (it stops when `npm run build` hasn't run), and Action Scheduler. Plugin Check runs on that copy.
- CI (`.github/workflows/ci.yml`) runs all of these, and Plugin Check on the build.

## The development WordPress

wp-env runs WordPress in Docker: `.wp-env.json` is the development site on port 8888, `.wp-env.test.json` the test site on 8889. Stop both when you are done (`npm run env stop`, `npm run env:test stop`).

Run `npm run build` once before opening the plugin's settings page there, or `npm run start` to rebuild as you edit.

To reach a development copy of the service, a gitignored `.wp-env.override.json` maps `dev/mu-plugins` into `wp-content/mu-plugins` and sets the constants that plugin reads (`IMINIFY_API_BASE`, `IMINIFY_DEV_RESOLVE`, `IMINIFY_DEV_CA`; its header says what each is), with a certificate file kept in the gitignored `dev/local/`. `IMINIFY_API_KEY` there connects the development site with a key. Anything about one machine stays in those two ignored places, never in a tracked file.

## Commits

A commit message says what changed and why, in plain words.
