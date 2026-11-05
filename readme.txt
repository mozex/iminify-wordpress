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

The WebP and AVIF copies reach your visitors through picture tags in your pages, which work with page caches and CDNs, and a browser that reads neither format gets the original. The original of each image is kept in a backup under uploads/iminify-backup, so any image can be restored.

When you delete the plugin, it removes its settings and stops the site's key, and keeps the WebP and AVIF copies and the backups unless you ticked otherwise in its settings.

== External services ==

This plugin relies on Iminify (https://www.iminify.com), an image optimization service, to compress, convert and resize your images. Nothing is sent to Iminify until you press Connect on the plugin's settings page, or give it a site key there or in wp-config.php.

When you press Connect, your site's server sends Iminify your site's home address and name, and the plugin opens a page on www.iminify.com in a new tab, where you sign in or create an account and approve the site. Your site's server then collects the site's key from Iminify. A key you paste is checked with Iminify before it is kept.

Once the site is connected, the plugin sends Iminify, from your site's server:

* each image it optimizes (the original you uploaded, or WordPress's scaled copy when the original is larger than your plan takes), with the dimensions and file sizes of the sizes WordPress keeps of it, to be compressed and sent back. Iminify deletes it once the plugin has saved the files;
* your site's home address and the plugin's version, with every call;
* the settings you choose on the plugin's settings page, which Iminify keeps for your site;
* a request that stops the site's key, when you press Disconnect or delete the plugin (not for a key set in wp-config.php).

Nothing about your site's visitors is sent: the images on your pages, and their WebP and AVIF copies, are served from your site. The plugin suggests text for your privacy policy under Settings, Privacy.

Iminify Terms of Service: https://www.iminify.com/terms-of-service
Iminify Privacy Policy: https://www.iminify.com/privacy-policy
