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

Iminify Image Optimizer sends the images you upload to Iminify, which compresses them, makes WebP and AVIF copies, and sends the smaller files back to your site. Every size WordPress keeps of an upload counts as one image.

== External services ==

This plugin relies on Iminify (https://www.iminify.com), an image optimization service, to compress, convert and resize your images. Nothing is sent to Iminify until you connect the plugin to an Iminify account.

Once the site is connected, the plugin sends Iminify, from your site's server:

* each image it optimizes, with the sizes WordPress keeps of it, to be compressed and sent back;
* your site's home address and name, and the plugin's version, with every call;
* the settings you choose on the plugin's settings page, which Iminify keeps for your site.

Iminify Terms of Service: https://www.iminify.com/terms-of-service
Iminify Privacy Policy: https://www.iminify.com/privacy-policy
