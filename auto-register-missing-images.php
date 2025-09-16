<?php
/**
 * Plugin Name: Auto Register Missing Images
 * Plugin URI:  https://wordpress.org/plugins/auto-register-missing-images/
 * Description: Auto-register manually uploaded images (in wp-content/uploads) into the Media Library. Scans when you open the Media Library, plus a one-click “Scan Missing Images” button (and an optional Deep Scan for all uploads).
 * Version:     1.1.0
 * Author:      Lennart Øster
 * Author URI:  https://lennartoester.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: auto-register-missing-images
 * Domain Path: /languages
 * Requires at least: 5.2
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

if (!defined('ARM_MI_VERSION')) {
    define('ARM_MI_VERSION', '1.1.0');
}
if (!defined('ARM_MI_TEXTDOMAIN')) {
    define('ARM_MI_TEXTDOMAIN', 'auto-register-missing-images');
}

class ARM_Auto_Register_Missing_Images {
    /** @var array */
    private $exts = ['jpg','jpeg','png','gif','webp','bmp','tif','tiff'];

    public function __construct() {
        // i18n
        add_action('plugins_loaded', [$this, 'load_textdomain']);

        // Auto-run when entering Media Library (upload.php)
        add_action('load-upload.php', [$this, 'auto_scan_on_media_screen']);

        // Manual buttons (admin bar + URL param)
        add_action('admin_bar_menu', [$this, 'add_admin_bar_buttons'], 100);
        add_action('admin_init',      [$this, 'maybe_handle_manual_scan']);

        // Result notice after a scan
        add_action('admin_notices',  [$this, 'maybe_show_notice']);

        // Quick link on the Plugins screen
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'action_links']);
    }

    public function load_textdomain() {
        load_plugin_textdomain(ARM_MI_TEXTDOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    /** Add link to Media Library in the plugin row */
    public function action_links($links) {
        $url = admin_url('upload.php');
        $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Open Media Library', ARM_MI_TEXTDOMAIN) . '</a>';
        return $links;
    }

    /** Auto-scan current month whenever the Media Library screen loads */
    public function auto_scan_on_media_screen() {
        if (!current_user_can('upload_files')) { return; }

        // Skip if we're already handling a manual scan via URL param
        if (isset($_GET['arm_scan'])) { return; }

        // Allow devs to disable auto-scan via filter
        $enabled = apply_filters('arm_mi_enable_auto_scan', true);
        if (!$enabled) { return; }

        $stats = $this->scan_current_month();
        $this->store_notice($stats, __('Auto-scan (current month)', ARM_MI_TEXTDOMAIN));
    }

    /** Add buttons to the Admin Bar for quick scans */
    public function add_admin_bar_buttons($wp_admin_bar) {
        if (!is_admin() || !current_user_can('upload_files')) { return; }
        if (!function_exists('get_current_screen')) { return; }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'upload') { return; } // Only on Media Library

        $nonce  = wp_create_nonce('arm_scan_now');
        $upload_url = admin_url('upload.php');

        $wp_admin_bar->add_node([
            'id'    => 'arm-scan',
            'title' => __('Scan Missing Images', ARM_MI_TEXTDOMAIN),
            'href'  => add_query_arg(['arm_scan' => 1, '_wpnonce' => $nonce], $upload_url),
            'meta'  => ['title' => __('Scan current month for unregistered images', ARM_MI_TEXTDOMAIN)]
        ]);

        $wp_admin_bar->add_node([
            'id'     => 'arm-scan-deep',
            'parent' => 'arm-scan',
            'title'  => __('Deep Scan (all uploads)', ARM_MI_TEXTDOMAIN),
            'href'   => add_query_arg(['arm_scan' => 'deep', '_wpnonce' => $nonce], $upload_url),
            'meta'   => ['title' => __('Recursively scan all subfolders in uploads', ARM_MI_TEXTDOMAIN)]
        ]);
    }

    /** Handle manual scan via button/link */
    public function maybe_handle_manual_scan() {
        if (!is_admin() || !current_user_can('upload_files')) { return; }
        if (!isset($_GET['arm_scan'])) { return; }
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'arm_scan_now')) { return; }

        $mode = sanitize_text_field($_GET['arm_scan']);
        if ($mode === 'deep') {
            $stats = $this->scan_all_uploads();
            $this->store_notice($stats, __('Manual Deep Scan (all uploads)', ARM_MI_TEXTDOMAIN));
        } else {
            $stats = $this->scan_current_month();
            $this->store_notice($stats, __('Manual Scan (current month)', ARM_MI_TEXTDOMAIN));
        }

        // Redirect to clean the URL
        wp_safe_redirect(remove_query_arg(['arm_scan','_wpnonce']));
        exit;
    }

    /** Show a one-time admin notice with scan results */
    public function maybe_show_notice() {
        $key = $this->notice_key();
        $notice = get_transient($key);
        if (!$notice) { return; }

        delete_transient($key);

        $msg = sprintf(
            /* translators: 1: label 2: scanned 3: imported 4: skipped existing 5: skipped intermediate 6: errors */
            __('%1$s: scanned %2$d file(s), imported %3$d, skipped-existing %4$d, skipped-intermediate %5$d, errors %6$d.', ARM_MI_TEXTDOMAIN),
            $notice['label'],
            $notice['stats']['scanned'],
            $notice['stats']['imported'],
            $notice['stats']['skipped_existing'],
            $notice['stats']['skipped_intermediate'],
            $notice['stats']['errors']
        );
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
    }

    private function store_notice(array $stats, string $label) {
        set_transient($this->notice_key(), [
            'label' => $label,
            'stats' => $stats
        ], 60);
    }

    private function notice_key(): string {
        return 'arm_scan_notice_' . get_current_user_id();
    }

    /* ===================== Core scanning ===================== */

    /** Scan current Y/m folder only */
    public function scan_current_month(): array {
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['error'])) { return $this->empty_stats(); }

        $dir_path = trailingslashit($uploads['path']);       // /.../uploads/Y/m/
        $dir_sub  = ltrim(trailingslashit($uploads['subdir']), '/'); // Y/m/
        return $this->scan_directory($dir_path, $dir_sub, $uploads);
    }

    /** Recursively scan ALL subfolders under uploads/ (use with care) */
    public function scan_all_uploads(): array {
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['error'])) { return $this->empty_stats(); }

        $base_dir = trailingslashit($uploads['basedir']); // /.../uploads/
        $stats = $this->empty_stats();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($base_dir, FilesystemIterator::SKIP_DOTS),
                function ($current) {
                    if ($current->isDir()) {
                        $basename = $current->getBasename();
                        $skip = ['cache','elementor','smush','simple-uploads'];
                        if (in_array($basename, $skip, true)) { return false; }
                    }
                    return true;
                }
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $fileinfo) {
            if (!$fileinfo->isFile()) { continue; }

            $abs_path = $fileinfo->getPathname();
            $rel = ltrim(str_replace($base_dir, '', $abs_path), '/'); // e.g. Y/m/image.jpg

            $ext = strtolower(pathinfo($abs_path, PATHINFO_EXTENSION));
            if (!in_array($ext, $this->exts, true)) { continue; }

            $filename = basename($abs_path);
            if ($filename[0] === '.') { continue; }

            if ($this->looks_like_intermediate_size($filename)) {
                $stats['skipped_intermediate']++;
                $stats['scanned']++;
                continue;
            }

            $stats['scanned']++;
            $stats = $this->maybe_register_file($abs_path, $rel, $uploads, $stats);
        }

        return $stats;
    }

    /** Scan a specific directory (non-recursive) given uploads context */
    private function scan_directory(string $dir_path, string $dir_sub, array $uploads): array {
        $stats = $this->empty_stats();

        if (!is_dir($dir_path) || !is_readable($dir_path)) { return $stats; }

        $pattern = $dir_path . '*.{'. implode(',', $this->exts) .'}';
        $files = glob($pattern, GLOB_BRACE);
        if (!$files) { return $stats; }

        foreach ($files as $abs_path) {
            if (!is_file($abs_path)) { continue; }

            $filename = basename($abs_path);
            if ($filename[0] === '.') { continue; }

            if ($this->looks_like_intermediate_size($filename)) {
                $stats['skipped_intermediate']++;
                $stats['scanned']++;
                continue;
            }

            $stats['scanned']++;
            $relative = $dir_sub . $filename; // e.g. Y/m/image.jpg
            $stats = $this->maybe_register_file($abs_path, $relative, $uploads, $stats);
        }

        return $stats;
    }

    /** Register a file as attachment if not yet in Media Library */
    private function maybe_register_file(string $abs_path, string $relative, array $uploads, array $stats): array {
        if ($this->attachment_exists_for_relative_path($relative)) {
            $stats['skipped_existing']++;
            return $stats;
        }

        $filetype = wp_check_filetype($abs_path, null);
        if (empty($filetype['type']) || strpos($filetype['type'], 'image/') !== 0) {
            return $stats;
        }

        $base_url = trailingslashit($uploads['baseurl']);
        $url      = $base_url . ltrim($relative, '/');

        $filetime = @filemtime($abs_path) ?: time();
        $postdate = gmdate('Y-m-d H:i:s', $filetime);

        $attachment = [
            'post_mime_type' => $filetype['type'],
            'post_title'     => sanitize_text_field(pathinfo($abs_path, PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'guid'           => esc_url_raw($url),
            'post_date'      => get_date_from_gmt($postdate),
            'post_date_gmt'  => $postdate,
        ];

        $attach_id = wp_insert_attachment($attachment, $abs_path, 0);
        if (is_wp_error($attach_id) || !$attach_id) {
            $stats['errors']++;
            return $stats;
        }

        update_post_meta($attach_id, '_wp_attached_file', $relative);

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $meta = wp_generate_attachment_metadata($attach_id, $abs_path);
        if (!empty($meta)) {
            wp_update_attachment_metadata($attach_id, $meta);
        }

        $stats['imported']++;
        return $stats;
    }

    /** Detect filenames like image-150x150.jpg */
    private function looks_like_intermediate_size(string $filename): bool {
        return (bool) preg_match('/-\d+x\d+\.(jpe?g|png|gif|webp|bmp|tiff?)$/i', $filename);
    }

    /** Check if already registered (by _wp_attached_file) */
    private function attachment_exists_for_relative_path(string $relative): bool {
        global $wpdb;
        $sql = $wpdb->prepare(
            "SELECT post_id
               FROM {$wpdb->postmeta} pm
               JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key = '_wp_attached_file'
                AND pm.meta_value = %s
                AND p.post_type = 'attachment'
              LIMIT 1",
            $relative
        );
        return (bool) $wpdb->get_var($sql);
    }

    private function empty_stats(): array {
        return [
            'scanned' => 0,
            'imported' => 0,
            'skipped_existing' => 0,
            'skipped_intermediate' => 0,
            'errors' => 0,
        ];
    }
}

new ARM_Auto_Register_Missing_Images();
