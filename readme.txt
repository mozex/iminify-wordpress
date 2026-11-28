=== Iminify Image Optimizer: Compress Images, Convert to WebP and AVIF ===
Contributors: iminify
Tags: image optimization, compress images, optimize images, webp, avif
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Compress images, convert them to WebP and AVIF, and keep every original. Free, with quality checked per image and all compressing off your server.

== Description ==

[Iminify](https://www.iminify.com/) makes the images on your WordPress site smaller without making them look worse. It compresses every upload and each size WordPress makes of it, adds WebP copies for the browsers that read them (AVIF too, with one switch), and keeps your originals so you can undo any of it.

On the six sample photos on [iminify.com](https://www.iminify.com/#showcase), Smart, the default level, made WebP copies 38% to 82% smaller than the originals, and AVIF copies 41% to 87% smaller.

### ✨ What you get

* 🎯 **Quality checked on every image.** Iminify tries each image at several qualities and keeps the smallest file that still passes a quality check measured on that image, so a detailed photo and a flat logo each get what they need.
* 🖼️ **WebP and AVIF, served for you.** WebP copies from the start, AVIF ones once you switch them on. Picture tags hand them to browsers that read them and the original to the rest, and page caches and CDNs keep them as they are.
* ⚡ **Your server does none of the compressing.** Images go to Iminify in the background, so an upload finishes as fast as before and the heavy work never touches your hosting.
* 📐 **Every size made from your original.** Thumbnails are made from the file you uploaded, never from a copy WordPress already shrank, so no size goes through a second round of compression.
* ↩️ **Originals kept.** Restore one image or the whole library whenever you like, even with the site disconnected.
* 🔎 **Page Audit.** Iminify opens one of your pages like a visitor and lists each image that slows it down, next to its media library item and the fix.
* ✍️ **AI alt text.** Turn it on, and images with an empty alt get a description in the language you pick. Alt text you wrote is never replaced.
* 📏 **A file size limit in KB.** Set one and every image is fitted under it, lowering the quality first and the dimensions only when that isn't enough.
* 🔄 **Switching keeps your old optimizer's work.** Its compressed images, originals, copies and settings carry over.

### 🚀 Up and running in a minute

1. Install and activate the plugin.
2. Open **Settings → Iminify** and press **Connect**. Sign in, or create a free Iminify account, and approve the site.
3. Every new upload is optimized from then on. For the images you already have, open **Media → Bulk Optimization** and press **Optimize the library**.

### 🔁 Switching from Imagify, ShortPixel, EWWW, Smush or TinyPNG

Iminify reads what ten image optimizers left on your site, whether they're still active or not: Imagify, ShortPixel, EWWW Image Optimizer, Smush, TinyPNG, Converter for Media, Modern Image Formats, Optimole, Image Optimizer by Elementor and LiteSpeed Cache's image optimization.

* Images they compressed show as optimized and cost you nothing.
* Restore puts back the originals they kept.
* Their WebP and AVIF copies keep being served until Iminify makes its own.
* "Use Imagify's settings" (or that plugin's) shows what your settings would become, and changes nothing until you confirm.
* Rules they left in your .htaccess are listed, and removed only when you press Remove.

Iminify never compresses their output a second time: asked to optimize such an image again, it starts from the original that plugin kept. Read [how switching works](https://www.iminify.com/docs/wordpress#switching-from-another-optimizer).

### 📦 The library you already have

**Media → Bulk Optimization** shows how many images are left and how much disk space their backups will take, then works through them in the background. A run picks up where it stopped on its own, and the same page puts every original back in one press.

Prefer the shell? `wp iminify optimize --all` runs the same job with a progress bar.

Images your theme or plugins ship are covered too: **Media → Other Folders** optimizes them where they are, keeping their width and height.

### 🌐 WebP and AVIF, your way

By default the copies reach visitors through picture tags, which work with every page cache and CDN. If your theme or page builder prints its own image markup, turn on whole-page serving, which also covers background images in the page.

Or leave your pages untouched and let the server send the copy in place of each image: the plugin writes the rules into .htaccess on Apache and LiteSpeed, and shows the lines to add on Nginx.

### 🔎 Page Audit

Pick a page under **Media → Page Audit** and Iminify opens it as a visitor would. Each image it finds is listed beside its media library item, with what's wrong and the fix:

* An image twice as wide as the space it fills gets the smaller size to pick.
* A missing width, height or alt text is filled in from your media library by one setting, as the page is sent.
* An image that isn't optimized yet gets an Optimize button.

### 🧩 Works with

* **WooCommerce:** product galleries keep their zoom, lightbox and variation images.
* **WP Offload Media:** the copies go up to your bucket with each image, "Remove Local Media" included.
* **WPML and Polylang:** a translated image's files are optimized once.
* **LiteSpeed Cache, WP Rocket, W3 Total Cache and Cloudflare's plugin:** emptied when a bulk run ends or an image is restored.
* **Regenerate Thumbnails:** with backups on, sizes made again come from the original, never from a compressed file.
* **Multisite:** connect the network once and each site joins by itself, with its own settings and backups.

### 🌍 In your language

The plugin comes in 28 languages besides English, 31 locales in all, right to left where the language reads that way. Each user sees it in the language of their own profile.

### 🛠️ For developers

Sixteen actions and filters, WP-CLI commands for every bulk job, and a site key you can set as `IMINIFY_API_KEY` in wp-config.php. The [WordPress guide](https://www.iminify.com/docs/wordpress) documents each [hook](https://www.iminify.com/docs/wordpress#hooks), each [command](https://www.iminify.com/docs/wordpress#wp-cli) and every call the plugin makes.

The same Iminify account also works outside WordPress: in the [web app](https://www.iminify.com/), through the [API and its packages](https://www.iminify.com/docs), and from [AI assistants](https://www.iminify.com/ai-agents).

== External services ==

This plugin relies on [Iminify](https://www.iminify.com/), an image optimization service, to compress, convert and resize your images. Nothing is sent to Iminify until you press Connect on the plugin's settings page, or give it a site key there or in wp-config.php.

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

[Iminify Terms of Service](https://www.iminify.com/terms-of-service)
[Iminify Privacy Policy](https://www.iminify.com/privacy-policy)

== Installation ==

1. Install the plugin from **Plugins → Add Plugin**, and activate it.
2. Open **Settings → Iminify** and press **Connect**. iminify.com opens in a new tab, where you sign in or create a free account and approve the site.
3. New uploads are optimized from then on. For the images already in your library, open **Media → Bulk Optimization** and press **Optimize the library**.

An agency can paste a site key on the settings page instead, or set it as `IMINIFY_API_KEY` in wp-config.php.

== Frequently Asked Questions ==

= Is it free? =

Yes. The plugin is free, and so is the Iminify account it connects to, with WebP, AVIF, backups, Page Audit and AI alt text included. A free account has a daily allowance of uploads, page audits and AI alt texts. When the day's uploads are used, uploads keep working as they always have and wait their turn, and the plugin carries on by itself when the next day's batch opens. Sites that upload more can move to a [paid plan](https://www.iminify.com/pricing) at any time.

= Do I need an Iminify account? =

Yes, a free one. Iminify does the compressing, so the plugin connects to an account, which you can create while connecting. Nothing is sent to Iminify before you press Connect or give the plugin a site key.

= Will my images look worse? =

Not at the default level. Smart keeps the smallest file that still passes a quality check measured on that image, Lossless keeps every pixel, and Ultra trades a little detail for smaller files. Each image's panel in the media library compares the original with every file Iminify made, on one picture with a line you drag, and Restore puts the original back.

= Will it slow down my site or my uploads? =

No. The compressing happens on Iminify's servers. An upload only queues the image, so it finishes as fast as before, and your pages get plain picture tags that page caches keep.

= How are my images counted? =

One upload counts as one image, with every size WordPress keeps of it and every WebP and AVIF copy, since Iminify makes them all from the original you uploaded. Optimizers that count per file charge for each thumbnail and each copy, so one photo can cost a dozen or more.

= Can I get my original images back? =

Yes, while backups are on, which they are by default. The original of each image is kept under uploads/iminify-backup. Restore one image from the media library or the whole library from the bulk page, on any plan and without a connection.

= Does it rename my files or change their addresses? =

No. Each file is replaced under its own name, so every post and page that shows it keeps working. The copies sit beside it as photo.jpg.webp and photo.jpg.avif.

= I use another image optimizer. Can I switch? =

Yes. The plugin reads what Imagify, ShortPixel, EWWW Image Optimizer, Smush, TinyPNG, Converter for Media, Modern Image Formats, Optimole, Image Optimizer by Elementor and LiteSpeed Cache's image optimization left on your site. Their compressed images stay as they are and cost nothing, the originals they kept can be restored, the WebP and AVIF copies their records name keep being served, and their settings can become yours. While the other plugin still optimizes your uploads, this one sends nothing, so turn that off first.

= Does it work with my theme or page builder? =

Yes. Images WordPress prints get picture tags on their own. For images your theme or builder prints itself, turn on whole-page serving in the settings, or the server rules, which also cover background images set in a stylesheet.

= Does it work behind Cloudflare or another CDN? =

Yes. The default picture tags are plain HTML, so caches and CDNs keep them. Only the server rules need a CDN that keeps a version of each image per format, and the settings warn when Cloudflare answers.

= Does it work on a multisite network? =

Yes. Activate it for the network and connect the network once under **Network Admin → Settings → Iminify**, or connect each site to an account of its own. Each site keeps its own settings, bulk page and backups.

= Who can use it? =

Administrators reach the settings, the bulk page, the page audit and the other folders. Anyone who can upload files can optimize or restore an image they can edit, so an author gets their own images and an editor everyone's.

= What happens when I delete the plugin? =

It removes its settings and stops the site's key on Iminify (a key set in wp-config.php stays). The WebP and AVIF copies and the backups stay unless you ticked otherwise in its settings, since a cached page may still point at a copy.

= What does the plugin send, and where? =

The External services section above lists everything the plugin sends to Iminify and when. Nothing about your site's visitors is sent, and the plugin suggests text for your privacy policy under Settings, Privacy.

= Where can I get help? =

Ask in the [support forum](https://wordpress.org/support/plugin/iminify-image-optimizer/), or read the [WordPress guide](https://www.iminify.com/docs/wordpress), which covers every setting, command and hook.

== Screenshots ==

1. Every image's state in the media library: what its files saved, and its WebP and AVIF copies.
2. One image's panel: each size before and after, with the levels to optimize it again at.
3. Drag the line to compare the original with each optimized file, here an AVIF copy 77% smaller.
4. Bulk Optimization works through the library in the background and shows what it has saved so far.
5. Page Audit: the images that slow a page, each beside its media library item, with the fix.
6. Settings: the compression level, WebP and AVIF copies, and an offer to use another optimizer's settings.
7. More settings: a size limit in KB, AI alt text in the language you pick, backups, and how the copies reach your pages.

== Changelog ==

= 1.0.0 =
* First release: optimizing on upload and in bulk, WebP and AVIF copies served through picture tags, backups and restore, Page Audit, AI alt text, other folders, WP-CLI, multisite networks, switching from ten other image optimizers, and translations into 28 languages.
