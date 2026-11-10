# Translating the plugin

The plugin ships its own translations in this folder. `iminify-image-optimizer.pot` is the template of every string; each locale has a `.po` file written from it, and `wp eval-file bin/i18n.php make` makes the files WordPress loads from each `.po` (`.mo`, `.l10n.php` and one `.json` per built script). `tests/Integration/TranslationsTest.php` fails while any locale lacks a string or changes what a string must keep. A language pack from translate.wordpress.org, once one exists for a locale, takes over from the files here.

## What every translation keeps

- **Names stay as they are:** Iminify, WebP, AVIF, JPEG, PNG, GIF, HEIC, the plan names Pro and Max, WP-CLI, WooCommerce, Cloudflare, Nginx, Apache, and every other product or plugin name. A name in a sentence may take the grammar the language needs around it, but its spelling never changes.
- **Code stays as it is:** everything inside `<code>` and `</code>`, file names (`wp-config.php`, `.htaccess`), constants (`IMINIFY_API_KEY`), commands (`wp iminify optimize`), addresses (`iminify.com`) and HTTP status codes. So do the words a command takes as typed, even outside `<code>`: "formats or sizes" after `wp iminify missing` keeps both words in English, with the language's own word beside each if it helps.
- **Placeholders stay exactly as written:** `%s`, `%d`, `%1$s`, `%2$s`, `%%`. A translation holds the same placeholders as its source, no more and no fewer; with several, the numbered ones may move to wherever the language puts them. Each plural form holds the placeholders of the plural source.
- **Tags stay exactly as written:** `<a>` and `</a>`, `<code>`, `<strong>`, `<file />` and the like are filled in by the code, so each pair keeps its name and wraps the translated words it wrapped in the source.
- **No em dash (—).** Use the language's ordinary punctuation: a comma, a colon, or a hyphen with spaces.
- **Follow WordPress's own translation for the locale:** how it addresses the person (formal or informal), and its words for the Media Library, an attachment, Settings, Plugins, uploads, the dashboard and other WordPress screens. The plugin's menu items sit beside WordPress's own, so they should read as one admin.
- **Short where the source is short:** a button or a menu item stays a few words; a status chip is one or two.
- **A menu path keeps its commas** as the source writes them ("Media, Bulk Optimization"), never an arrow. iminify.com's own labels ("Settings, Sites", "This is a multisite network") and other plugins' setting names stay in English, since those screens are English; WordPress's own menus take WordPress's words for the locale.
- **The account tile's note is two pieces** ("3 used" and "37 left", each with its own plural), which the code joins with the language's list separator, the "used" piece first, so the "left" piece starts as the language goes on after a comma.

## The plugin's words

| Word | What it means here |
|---|---|
| optimize, optimized | Make an image smaller through Iminify: compress it, and resize or convert it as the settings say. |
| original | The image as it was uploaded, before Iminify optimized it. |
| backup | The copy of the original the plugin keeps, so the image can be restored. |
| restore | Put the original back in place of the optimized image. |
| copy, copies | The WebP and AVIF files the plugin makes beside an image, served to browsers that can read them. Never a duplicate of the image itself. |
| size, sizes | The image sizes WordPress makes from each upload (Thumbnail, Medium and the rest). |
| level | How hard Iminify compresses: Smart, Ultra, Lossless or None. Translate the four names the way the language would name such a choice. |
| size limit | The most a file may weigh, in KB; Iminify makes the image smaller until it fits. |
| alt text | The text describing an image for screen readers and search engines; "AI alt text" is alt text Iminify writes. |
| connect, disconnect | Link this site to an Iminify account, or end that link. |
| key, site key | The secret a connected site uses to reach Iminify. |
| the day's images, the next batch | Each plan optimizes so many images in a rolling 24 hours; the next batch is when the count opens again. |
| bulk, Bulk Optimization | Optimizing the whole library at once, from the Media menu. |
| Other Folders, Page Audit | Two more Media menu screens: images outside the library (a theme's), and a scan of a page for the images that slow it down. |
| picture tags | The `<picture>` markup that serves the WebP and AVIF copies. |
| server rules | Lines in the web server's settings that serve the copies instead of picture tags. |
| network | A WordPress multisite network. |
| another optimizer, another plugin | One of the other image optimization plugins the site used before, whose work the plugin reads. |
