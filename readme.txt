=== Auto Register Missing Images ===
Contributors: sitepointsystems
Tags: media library, uploads, images, sync, register, import
Requires at least: 5.2
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Auto-register manually uploaded images (in `wp-content/uploads`) into the Media Library. Scans when you open the Media Library, plus a one-click **Scan Missing Images** button (and an optional **Deep Scan** for all uploads).

== Description ==

Dropped a bunch of image files into `wp-content/uploads/YYYY/MM/` via SFTP/SSH and they don't show up in the Media Library? This plugin fixes that.

**How it works**

- **Auto-scan** the *current month* folder every time you open **Media → Library**.
- **Admin Bar button**: “**Scan Missing Images**” to run on demand (current month).
- **Deep Scan** button: recursively scan **all** subfolders under `wp-content/uploads/` (use when you backfilled older months).
- Skips WordPress-generated intermediate sizes like `-150x150.jpg`.
- Avoids duplicates by checking `_wp_attached_file` before registering.
- Generates attachment metadata (thumbnails/sizes) just like native uploads.

**Why this plugin?**  
Sometimes you need to upload files outside of WordPress (bulk imports, migrations, CDN syncs, etc.). This keeps your Media Library in sync with what's actually on disk — **fast**, **simple**, and **safe**.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via the Plugins screen.
2. Activate the plugin.
3. Go to **Media → Library**; the plugin auto-scans the current month.  
   Use the Admin Bar menu **Scan Missing Images → Deep Scan** for a full scan.

== Frequently Asked Questions ==

= Does it import thumbnails like `-150x150.jpg`? =
No. It only registers **originals** (e.g. `photo.jpg`). WordPress regenerates sizes if needed.

= Can it scan older months automatically? =
Use the **Deep Scan (all uploads)** action. It will walk every subfolder under `wp-content/uploads/`.

= Will it slow down the Media Library? =
The **auto-scan** only looks in the **current** month, which is typically fast. Deep Scan is manual so *you* control when it runs.

= Does it touch non-image files? =
No. Only files with these extensions: `jpg, jpeg, png, gif, webp, bmp, tif, tiff` and with an `image/*` MIME type.

= Can I disable auto-scan? =
Yes. Add this to a mu-plugin or your theme’s `functions.php`:
`add_filter( 'arm_mi_enable_auto_scan', '__return_false' );`

== Screenshots ==

1. Admin Bar actions on the Media Library screen (Scan current month / Deep Scan).
2. Success notice with scan stats after running.

== Changelog ==

= 1.1.0 =
* New: Admin Bar buttons for **Scan Missing Images** and **Deep Scan**.
* New: Auto-scan whenever you open **Media → Library**.
* Improvement: i18n and capability/nonce checks.
* Initial public release.

== Upgrade Notice ==

= 1.1.0 =
Adds Admin Bar buttons and auto-scan on Media Library open.
