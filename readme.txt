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

Images outside the media library, such as your theme's, go through Media, Other Folders. Choose the active theme, its parent, or any folder in wp-content by its path, and the plugin lists the images in it. Each is optimized where it is, keeping its width and height whatever your maximum width says, with its original kept under uploads/iminify-backup-folders. The bulk page optimizes or restores them all in a run of their own. The plugin looks through the folders again after a theme or plugin update, so a changed file reads as not optimized and is never restored over. On a network, the main site chooses the folders for every site.

For large libraries and hosts whose scheduled tasks don't run, `wp iminify optimize --all` runs the same bulk run from the shell, with a progress bar, and `wp iminify optimize --folders` does it for the folders you chose with `wp iminify folders add`. `wp help iminify` lists the rest: restore, missing, folders, status, run and stop.

Administrators reach the settings, the bulk page and the other folders (on a network, super admins choose the folders). Anyone who can upload files can optimize or restore an image they can edit, so an author gets their own images and an editor everyone's.

On a multisite network, activate the plugin for the network and a super admin connects the whole network once, under Network Admin, Settings, Iminify. Each site then joins by itself the first time it needs Iminify, with a key of its own, and keeps its own settings, bulk page and backups. A site can connect to an account of its own instead, and a site disconnected on its own page stays out until someone presses Join the network there. Turning the plugin off for the network, or deleting it, does it on every site.

The WebP and AVIF copies reach your visitors through picture tags in your pages, which work with page caches and CDNs, and a browser that reads neither format gets the original. With backups on (the default), the original of each image is kept under uploads/iminify-backup, so any image can be restored.

WooCommerce's product galleries keep their zoom, lightbox and variation images with the picture tags. With WP Offload Media serving your library from a bucket and keeping the files on the server too (its default), the copies go up with each image and are served from the bucket as well. Once a bulk run ends, or an image is restored, the plugin empties the page caches of LiteSpeed Cache, WP Rocket, W3 Total Cache and Cloudflare's plugin, so no cached page keeps pointing at a copy that has gone.

When you delete the plugin, it removes its settings and stops the site's key, and keeps the WebP and AVIF copies and the backups unless you ticked otherwise in its settings.

Translations WPML or Polylang make of an image share its files, so the plugin optimizes those files once, through the image they translate, and never sends a translation on its own. Regenerate Thumbnails and `wp media regenerate` send the image again from its backup, so no size is made from an already compressed file, and a file Enable Media Replace puts in place goes as a new original. A theme or plugin with more image sizes than Iminify makes for one image gets the first ones it lists, and the rest stay as WordPress made them.

Where WordPress sends over cURL, as nearly every host does, originals go to Iminify straight from the disk, so a file as large as your plan takes goes through on a 128 MB memory limit. Elsewhere, an original too large for the server's memory goes as WordPress's scaled copy. On a site behind a password (HTTP basic auth), the background runs reach the site with the password the page was opened with, or with `IMINIFY_AUTH_USER` and `IMINIFY_AUTH_PASSWORD` set in wp-config.php.

= For developers =

The plugin has fourteen hooks:

* `iminify_capability` (filter): the capability each part asks for.
* `iminify_optimize_image` (filter): return false to keep an image from ever being sent.
* `iminify_optimize_file` (filter): return false to keep a file in another folder from ever being sent; it gets the file's path in wp-content.
* `iminify_disk_free_space` (filter): the free bytes the plugin reads, for a host whose real limit is a quota.
* `iminify_stream_source` (filter): return false to read an original into memory rather than stream it, for a host whose proxy breaks a streamed upload.
* `iminify_purge_caches` (filter): return false to keep the page caches as they are after a bulk run or a restore; it gets `bulk`, `restore` or `uninstall`.
* `iminify_image_optimized` (action): an image's files were written.
* `iminify_image_restored` (action): an image's original is back.
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
* each image in the folders you choose under Media, Other Folders, with its file name, dimensions and file size, the same way. The folders' paths and the other files in them never leave your site;
* your site's home address and the plugin's version, with every call;
* on a multisite network connected as one, each site's home address and name, with the network's key, the first time that site needs Iminify, so it gets a key of its own;
* how many images on your site aren't optimized yet, and how many files they make, when you open the bulk page or start or stop a run there, so Iminify can say how long they take on your plan;
* the settings you choose on the plugin's settings page, which Iminify keeps for your site;
* a request that stops the site's key, when you press Disconnect or delete the plugin (not for a key set in wp-config.php).

Nothing about your site's visitors is sent: the images on your pages, and their WebP and AVIF copies, are served from your site. The plugin suggests text for your privacy policy under Settings, Privacy.

Iminify Terms of Service: https://www.iminify.com/terms-of-service
Iminify Privacy Policy: https://www.iminify.com/privacy-policy
