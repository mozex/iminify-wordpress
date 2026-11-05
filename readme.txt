=== Iminify Image Optimizer ===
Tags: image optimization, compress images, webp, avif, image compression
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Compress, convert and resize the images in your media library with Iminify, and serve them as WebP and AVIF.

== Description ==

Iminify Image Optimizer sends the images you upload to Iminify, which compresses them, makes WebP and AVIF copies, and sends the smaller files back to your site. An upload counts as one image, however many sizes WordPress keeps of it.

Images already in your library go through Media, Bulk Optimization. It shows how many are left, how many days your plan's daily images need for them, and the disk space their backups take. A run goes on in the background, or while the page is open on a host whose scheduled tasks don't run, and picks up by itself after a spent day. The same page restores every optimized image at once, on any plan and without a connection.

If you turn AVIF on after optimizing your library, or a theme adds an image size, that page also makes what the optimized images lack. Each one goes again from its original and counts as one more image, and it keeps its other files unless Iminify makes them smaller. The media library shows what each image lacks, with a button for that one image.

For large libraries and hosts whose scheduled tasks don't run, `wp iminify optimize --all` runs the same bulk run from the shell, with a progress bar. `wp help iminify` lists the rest: restore, missing, status, run and stop.

Administrators reach the settings and the bulk page. Anyone who can upload files can optimize or restore an image they can edit, so an author gets their own images and an editor everyone's.

The WebP and AVIF copies reach your visitors through picture tags in your pages, which work with page caches and CDNs, and a browser that reads neither format gets the original. With backups on (the default), the original of each image is kept under uploads/iminify-backup, so any image can be restored.

When you delete the plugin, it removes its settings and stops the site's key, and keeps the WebP and AVIF copies and the backups unless you ticked otherwise in its settings.

= For developers =

The plugin has six hooks:

* `iminify_capability` (filter): the capability each part asks for.
* `iminify_optimize_image` (filter): return false to keep an image from ever being sent.
* `iminify_disk_free_space` (filter): the free bytes the plugin reads, for a host whose real limit is a quota.
* `iminify_image_optimized` (action): an image's files were written.
* `iminify_image_restored` (action): an image's original is back.
* `iminify_bulk_finished` (action): a bulk run came to its end.

== External services ==

This plugin relies on Iminify (https://www.iminify.com), an image optimization service, to compress, convert and resize your images. Nothing is sent to Iminify until you press Connect on the plugin's settings page, or give it a site key there or in wp-config.php.

When you press Connect, your site's server sends Iminify your site's home address and name, and the plugin opens a page on www.iminify.com in a new tab, where you sign in or create an account and approve the site. Your site's server then collects the site's key from Iminify. A key you paste is checked with Iminify before it is kept.

Once the site is connected, the plugin sends Iminify, from your site's server:

* each image it optimizes (the original you uploaded, or WordPress's scaled copy when the original is larger than your plan takes), with the dimensions and file sizes of the sizes WordPress keeps of it, to be compressed and sent back. Iminify deletes it once the plugin has saved the files;
* your site's home address and the plugin's version, with every call;
* how many images on your site aren't optimized yet, and how many files they make, when you open the bulk page or start or stop a run there, so Iminify can say how long they take on your plan;
* the settings you choose on the plugin's settings page, which Iminify keeps for your site;
* a request that stops the site's key, when you press Disconnect or delete the plugin (not for a key set in wp-config.php).

Nothing about your site's visitors is sent: the images on your pages, and their WebP and AVIF copies, are served from your site. The plugin suggests text for your privacy policy under Settings, Privacy.

Iminify Terms of Service: https://www.iminify.com/terms-of-service
Iminify Privacy Policy: https://www.iminify.com/privacy-policy
