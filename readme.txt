=== Iminify Image Optimizer ===
Contributors: iminify
Tags: image optimization, compress images, webp, avif, image compression
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Compress your images and serve WebP and AVIF copies. One upload counts as one image, every size included. Backups, bulk and page audits.

== Description ==

Iminify Image Optimizer sends the images you upload to Iminify, which compresses them and the sizes WordPress keeps of them, makes WebP copies (AVIF ones too, once you turn them on), and sends the smaller files back under the same names. Your visitors get the copies through picture tags, and a browser that reads neither format gets the original.

Iminify counts what you upload, not what WordPress makes of it. A photo WordPress keeps in six sizes comes back as 18 files with WebP and AVIF on, and counts as one image. Connect the site to a free Iminify account in one click, or create one on the way.

= What sets it apart =

* **One upload, one image.** Iminify makes the sizes from your original, rather than compressing the copies WordPress already shrank, and counts the set once. Optimizers that charge per file count every thumbnail and every copy, so a free allowance runs out after a few uploads.
* **Quality picked per image.** Iminify searches each image for the lowest quality that still looks as good as your level promises, measured on that image, rather than one quality number for every file. A detailed photo and a flat graphic each get what they need.
* **A size limit in KB.** Set one, and the main image is fitted under it: the quality goes down first, and the dimensions only when that isn't enough.
* **Page Audit, matched to your library.** Iminify opens one of your pages as a visitor would and lists each image that slows it down beside its media library item, with the fix next to it.
* **AI alt text where there is none.** Turn it on, and each image with an empty alt gets a description in the language you pick. An alt you wrote is never replaced.
* **Originals you can get back.** Backups are on from the first upload, so you can restore one image or the whole library on any plan, after the day's images are spent, even with the site disconnected.
* **Switching keeps your old optimizer's work.** Its compressed images, originals, copies and settings carry over (below).

= Your library, in bulk =

Media, Bulk Optimization shows how many images are left, how many days your plan's daily images need for them, and the disk space their backups take. A run goes on in the background, or while the page is open on a host whose scheduled tasks don't run, and picks up by itself after a spent day. The same page puts back, at once, every optimized image whose original was kept.

If you turn AVIF on after optimizing your library, or a theme adds an image size, the page also makes what the optimized images lack. Each image goes again from its original, counts as one more image, and keeps its other files unless Iminify makes them smaller.

From the shell, `wp iminify optimize --all` runs the same bulk run with a progress bar. `wp help iminify` lists the rest: restore, missing, alt-text, folders, status, run and stop.

= Every image's state in the media library =

A column says whether each image is optimized, how much its files lost and which copies it has. An image's panel lists every size before and after, offers to optimize it again at another level, and compares the original with the file Iminify wrote and each WebP and AVIF copy, on one picture with a line you drag. It also says when an image carried Content Credentials, the signed record of where it came from, which Iminify's files leave out and the backup keeps.

= Page Audit =

Media, Page Audit has Iminify open one of your pages and list every image it shows, each beside the image and size in your media library it is. It names what slows the page down, such as an image twice as wide as the space it fills, one with no width and height or no alt text, or one loaded lazily in the first screen.

Beside each finding is what fixes it: Optimize for an image the plugin hasn't optimized yet, AI alt text for one that has none, and the size to pick in the editor for an image shown far smaller than its file. A setting adds, as each page is sent, the width, height and alt text a page's markup leaves out of an image from your media library. When a firewall or a bot check turns the scanner away, the page says how to let it through. Each audit counts as one of your account's page scans for the day.

= Serving WebP and AVIF =

By default the copies reach visitors through picture tags in your pages, which work with page caches and CDNs. For images WordPress doesn't print itself, such as a page builder's or a theme's own markup, the settings offer to serve the copies across the whole page, background images in the page's own markup included.

A fourth choice leaves your pages as they are and has your server send the copy in place of each image. On Apache and LiteSpeed the plugin writes the rules into your .htaccess, and on Nginx the settings show the lines to add. Behind a CDN this needs one that keeps a version of each image per format, which Cloudflare doesn't, so the settings warn when Cloudflare answers.

= Switching from another optimizer =

The plugin reads what Imagify, ShortPixel, EWWW Image Optimizer, Smush, TinyPNG, Converter for Media, Modern Image Formats, Optimole, Image Optimizer by Elementor and LiteSpeed Cache's image optimization leave on a site, active or not.

* Images that plugin compressed show as optimized by it, with what it saved, and cost nothing. Iminify compresses them again from their originals only when you ask, in the media library or on the bulk page.
* Restore puts back the original that plugin kept, once Iminify has checked it is one.
* The WebP and AVIF copies its records name keep being served until Iminify makes its own.
* A button on the settings page named after that plugin ("Use Imagify's settings") shows what your site's settings would become, and changes nothing until you confirm.
* Rules it left in your .htaccess are listed, and removed only when you press Remove.

While another optimizer still optimizes your uploads, this plugin sends nothing, so no image is compressed twice, and its pages say what to switch off.

= Images outside the media library =

Media, Other Folders optimizes the images your theme and plugins ship, where they are, keeping their width and height. Choose the active theme, its parent, or any folder in wp-content. Each original is kept under uploads/iminify-backup-folders, and the plugin looks through the folders again after a theme or plugin update, so a changed file reads as not optimized and is never restored over.

= Works with =

* **WooCommerce:** product galleries keep their zoom, lightbox and variation images with the picture tags.
* **WP Offload Media:** the copies go up to your bucket with each image, "Remove Local Media" included.
* **WPML and Polylang:** a translated image's files are optimized once, through the image they translate.
* **Page caches:** LiteSpeed Cache, WP Rocket, W3 Total Cache and Cloudflare's plugin are emptied when a bulk run ends or an image is restored.
* **Regenerate Thumbnails and `wp media regenerate`:** with backups on, sizes made again are made from the original, never from a compressed file.
* **Enable Media Replace:** a replaced file goes as a new original.
* **Multisite:** activate the plugin for the network and connect the network once under Network Admin, Settings, Iminify. Each site joins by itself the first time it needs Iminify and keeps its own settings, bulk page and backups.
* **Sites behind a password:** the background runs reach the site with the password the page was opened with, or with `IMINIFY_AUTH_USER` and `IMINIFY_AUTH_PASSWORD` in wp-config.php.

= Plans =

The plugin is free, and so is an Iminify account. Every plan gets WebP, AVIF, backups, page audits and AI alt text, and a new account starts with a free trial of Pro. Connect as many sites as you like: they share your account's daily images. When the day's images are spent, uploads keep working as WordPress always does and wait their turn, and the plugin picks up by itself when the next batch opens. The plans and their limits: https://www.iminify.com/pricing

= In your language =

The plugin carries its own translations into 28 languages besides English, 31 locales in all with Spanish for Spain and Mexico, Portuguese for Brazil and Portugal, and Chinese in Simplified and Traditional script. Its screens follow the language of the site or of each user's profile, right to left where the language reads that way. When translate.wordpress.org has a translation of the plugin for your language, WordPress loads that one instead.

= Who can do what =

Administrators reach the settings, the bulk page, the page audit and the other folders (on a network, super admins choose the folders). Anyone who can upload files can optimize or restore an image they can edit, so an author gets their own images and an editor everyone's.

= For developers =

The plugin has sixteen hooks:

* `iminify_capability` (filter): the capability each part asks for, given `settings`, `bulk`, `media`, `folders`, `audit` or `network`.
* `iminify_optimize_image` (filter): return false to keep an image from ever being sent.
* `iminify_optimize_file` (filter): return false to keep a file in another folder from ever being sent; it gets the file's path in wp-content.
* `iminify_disk_free_space` (filter): the free bytes the plugin reads, for a host whose real limit is a quota.
* `iminify_stream_source` (filter): return false to read an original into memory rather than stream it, for a host whose proxy breaks a streamed upload.
* `iminify_purge_caches` (filter): return false to keep the page caches as they are after a bulk run, a restore, a change of how the copies are served or of whether the images' missing width, height and alt text are added, or the deletion of another image optimizer whose copies the pages served; it gets `bulk`, `restore`, `uninstall`, `delivery` or `others`.
* `iminify_page_buffer` (filter): return false to leave a page as WordPress printed it when "Serve them on the whole page", or adding the width, height and alt text images lack, is on in the settings.
* `iminify_image_optimized` (action): an image's files were written.
* `iminify_image_restored` (action): an image's original is back.
* `iminify_alt_text_written` (action): an image's AI alt text was written, in a field that had none.
* `iminify_file_optimized` (action): a file in another folder was written, with its path in wp-content.
* `iminify_file_restored` (action): a file in another folder has its original back.
* `iminify_copies_deleted` (action): an image's WebP and AVIF copies were deleted from its panel, or by a restore that couldn't finish.
* `iminify_bulk_finished` (action): a bulk run came to its end.
* `iminify_bulk_paused` (action): a bulk run was paused.
* `iminify_bulk_stopped` (action): a bulk run was stopped before its end.

== External services ==

This plugin relies on Iminify (https://www.iminify.com), an image optimization service, to compress, convert and resize your images. Nothing is sent to Iminify until you press Connect on the plugin's settings page, or give it a site key there or in wp-config.php.

When you press Connect, your site's server sends Iminify your site's home address and name, and the plugin opens a page on www.iminify.com in a new tab, where you sign in or create an account and approve the site. Your site's server then collects the site's key from Iminify. A key you paste is checked with Iminify before it is kept. On a multisite network connected from the network admin, it sends the main site's home address and the network's name.

Once the site is connected, the plugin sends Iminify, from your site's server:

* each image it optimizes (the original you uploaded, or WordPress's scaled copy when the original is larger than your plan takes or the server's memory can send), with the dimensions and file sizes of the sizes WordPress keeps of it, to be compressed and sent back. Iminify deletes it once the plugin has saved the files;
* when you turn on "Write alt text with AI" in the settings, a request for an alt text with each image that has none, and the language you chose. Iminify makes a copy of the image at most 768 pixels on its longest side, and sends it to Anthropic, whose model writes the text;
* when you ask for alt text for images already in the media library (in an image's panel, on the bulk page or with WP-CLI), a small copy of each image that has no alt text, which Iminify sends on to Anthropic the same way;
* each image in the folders you choose under Media, Other Folders, with its file name, dimensions and file size, the same way. The folders' paths and the other files in them never leave your site;
* your site's home address and the plugin's version, with every call;
* on a multisite network connected as one, each site's home address and name, with the network's key, the first time that site needs Iminify, so it gets a key of its own;
* the address of each page you scan under Media, Page Audit. Iminify opens that page as any visitor could, measures the images it shows and keeps what it found with your site's connection;
* how many images on your site aren't optimized yet, and how many files they make, when you open the bulk page or start or stop a run there, so Iminify can say how long they take on your plan;
* the settings you choose on the plugin's settings page, which Iminify keeps for your site;
* when you press "Use <plugin>'s settings" on the settings page, the settings another image optimizer kept on your site (only those Iminify has a match for, never that optimizer's own keys or tokens), that optimizer's name and the names of the image sizes your site makes. Iminify answers with the settings they match and keeps none of it, and nothing changes until you confirm them;
* a request that stops the site's key, when you press Disconnect or delete the plugin (not for a key set in wp-config.php).

Nothing about your site's visitors is sent: the images on your pages, and their WebP and AVIF copies, are served from your site. The plugin suggests text for your privacy policy under Settings, Privacy.

Iminify Terms of Service: https://www.iminify.com/terms-of-service
Iminify Privacy Policy: https://www.iminify.com/privacy-policy

== Installation ==

1. Install the plugin from Plugins, Add New, and activate it.
2. Open Settings, Iminify and press Connect. iminify.com opens in a new tab, where you sign in or create a free account and approve the site.
3. New uploads are optimized from then on. For the images already in your library, open Media, Bulk Optimization and press Optimize the library.

An agency can paste a site key on the settings page instead, or set it as `IMINIFY_API_KEY` in wp-config.php.

== Frequently Asked Questions ==

= Do I need an Iminify account? =

Yes. Iminify does the compressing, so the plugin works with a free Iminify account, which you can create while connecting. Nothing is sent to Iminify before you press Connect or give the plugin a site key.

= How are my images counted? =

One upload counts as one image, with every size WordPress keeps of it and every WebP and AVIF copy. Iminify makes them all from the original you uploaded. The files it makes have a daily allowance of their own, which the plans list. A theme or plugin with more image sizes than Iminify makes for one image gets the first ones it lists, and the rest stay as WordPress made them.

= What happens when my day's images are spent? =

Nothing breaks. Uploads keep working as they always do and wait their turn, and the plugin's pages say when the next batch opens, in your site's time zone. When it opens, the plugin picks up by itself, new uploads first and then the library. Start now on the settings page asks again at once, say right after you change plans.

= Can I get my original images back? =

Yes, while backups are on, which they are by default. The original of each image is kept under uploads/iminify-backup. Restore one image from the media library or the whole library from the bulk page, on any plan and without a connection.

= Does it rename my files or change their addresses? =

No. Each file is replaced under its own name, so every post and page that shows it keeps working. The copies sit beside it as photo.jpg.webp and photo.jpg.avif.

= I use another image optimizer. Can I switch? =

Yes. The plugin reads what Imagify, ShortPixel, EWWW Image Optimizer, Smush, TinyPNG, Converter for Media, Modern Image Formats, Optimole, Image Optimizer by Elementor and LiteSpeed Cache's image optimization left on your site. Their compressed images stay as they are and cost nothing, the originals they kept can be restored, the WebP and AVIF copies their records name keep being served, and their settings can become yours. While the other plugin still optimizes your uploads, this one sends nothing, so turn that off first.

= Does it work behind Cloudflare or another CDN? =

Yes. The default picture tags are plain HTML, so caches and CDNs keep them. Only the server rules need a CDN that keeps a version of each image per format, and the settings warn when Cloudflare answers.

= Does it work on a multisite network? =

Yes. Activate it for the network and connect the network once, or connect each site to an account of its own. Each site keeps its own settings, bulk page and backups.

= What happens when I delete the plugin? =

It removes its settings and stops the site's key on Iminify (a key set in wp-config.php stays). The WebP and AVIF copies and the backups stay unless you ticked otherwise in its settings, since a cached page may still point at a copy.

= What does the plugin send, and where? =

The External services section above lists everything the plugin sends to Iminify and when. Nothing about your site's visitors is sent, and the plugin suggests text for your privacy policy under Settings, Privacy.

== Screenshots ==

1. The media library's Iminify column: each image's state, what it saved across its files, and its WebP and AVIF copies.
2. One image's panel: every size before and after, with its WebP and AVIF copies and the levels to optimize it again at.
3. The original beside the optimized file in each format, here the AVIF copy at 77% smaller.
4. Bulk Optimization running in the background, with the library's progress and what it has saved so far.
5. Page Audit: the images a page shows, each beside its media library item, with what slows the page down and how to fix it.
6. Settings: the compression level, the WebP and AVIF copies, the images and files the account has used today, and an offer to use another optimizer's settings.
7. More settings: a size limit in KB, AI alt text in the language you pick, backups, and how the copies reach your pages.

== Changelog ==

= 1.0.0 =
* First release: optimizing on upload and in bulk, WebP and AVIF copies served through picture tags, backups and restore, Page Audit, AI alt text, other folders, WP-CLI, multisite networks, switching from ten other image optimizers, and translations into 28 languages.
