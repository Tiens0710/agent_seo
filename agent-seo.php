<?php
/**
 * Plugin Name: Agent SEO
 * Plugin URI: https://gaocantho.com
 * Description: AI Agent tự động hóa quy trình viết bài chuyên sâu và sinh ảnh thực tế, tự động tối ưu hóa SEO On-page và đi link nội bộ định kỳ.
 * Version: 1.1.2
 * Author: Tiens0710
 * Author URI: https://gaocantho.com
 * License: GPL2
 * Text Domain: agent-seo
 */

// Ngăn chặn truy cập trực tiếp
defined('ABSPATH') || exit;

// Định nghĩa các hằng số
define('ASEO_PATH', plugin_dir_path(__FILE__));
define('ASEO_URL', plugin_dir_url(__FILE__));
define('ASEO_VERSION', '1.1.2');

// Nạp các tệp thành phần xử lý logic
require_once ASEO_PATH . 'includes/class-settings.php';
require_once ASEO_PATH . 'includes/class-gemini-text.php';
require_once ASEO_PATH . 'includes/class-gemini-image.php';
require_once ASEO_PATH . 'includes/class-linker.php';
require_once ASEO_PATH . 'includes/class-orchestrator.php';
require_once ASEO_PATH . 'includes/class-google-search-console.php';

// Đăng ký hook kích hoạt và hủy kích hoạt plugin
register_activation_hook(__FILE__, 'agent_seo_activate');
register_deactivation_hook(__FILE__, 'agent_seo_deactivate');

/**
 * Hook kích hoạt plugin: Đăng ký lịch chạy ngầm định kỳ bằng WP-Cron
 */
function agent_seo_activate() {
    if (!wp_next_scheduled('agent_seo_cron_hook')) {
        // Mặc định thiết lập chạy ngầm kiểm tra hàng ngày (daily)
        wp_schedule_event(time(), 'daily', 'agent_seo_cron_hook');
    }
}

/**
 * Hook hủy kích hoạt plugin: Xóa lịch biểu ngầm để tránh rác hệ thống
 */
function agent_seo_deactivate() {
    $timestamp = wp_next_scheduled('agent_seo_cron_hook');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'agent_seo_cron_hook');
    }
}

// Khởi chạy các module chính của plugin
add_action('plugins_loaded', 'agent_seo_init');

function agent_seo_init() {
    agent_seo_ensure_indexing_defaults();
    Agent_SEO_GSC::register_hooks();
    // Làm sạch metadata ảnh ngay khi người dùng upload vào Media Library.
    Agent_SEO_Gemini_Image::register_hooks();
    // Khởi tạo trang cài đặt quản trị
    if (is_admin()) {
        new Agent_SEO_Settings();
    }
    
    // Khởi tạo điều phối viên và kết nối WP-Cron Hook
    new Agent_SEO_Orchestrator();
}

/**
 * Thiết lập các giá trị index cơ bản mà không bắt người dùng cấu hình từng
 * website bằng OAuth. Google Search Console vẫn là tùy chọn nâng cao.
 */
function agent_seo_ensure_indexing_defaults() {
    $indexnow_key = trim(get_option('aseo_indexnow_key', ''));
    if ($indexnow_key === '') {
        update_option('aseo_indexnow_key', strtolower(wp_generate_password(32, false, false)), false);
    }
}

/**
 * Tự động mở đường cho các con Bot AI (ChatGPT, Perplexity, Google) cào dữ liệu qua robots.txt
 */
add_filter('robots_txt', 'agent_seo_custom_robots_rules', 99, 2);
function agent_seo_custom_robots_rules($output, $public) {
    if (stripos($output, 'Sitemap:') === false) {
        $sitemap_url = trim(get_option('aseo_gsc_sitemap_url', ''));
        if ($sitemap_url === '') {
            if (defined('RANK_MATH_VERSION') || defined('WPSEO_VERSION')) {
                $sitemap_url = home_url('/sitemap_index.xml');
            } elseif (function_exists('wp_get_sitemap_index_url')) {
                $sitemap_url = wp_get_sitemap_index_url();
            } else {
                $sitemap_url = home_url('/sitemap_index.xml');
            }
        }
        $output .= "\nSitemap: " . esc_url_raw($sitemap_url) . "\n";
    }

    $ai_rules = "\n# Agent SEO - AI Bot Allow Rules\n" .
                "User-agent: GPTBot\nAllow: /\n\n" .
                "User-agent: OAI-SearchBot\nAllow: /\n\n" .
                "User-agent: ChatGPT-User\nAllow: /\n\n" .
                "User-agent: PerplexityBot\nAllow: /\n\n" .
                "User-agent: Google-Extended\nAllow: /\n\n" .
                "User-agent: ClaudeBot\nAllow: /\n";
    return $output . $ai_rules;
}
