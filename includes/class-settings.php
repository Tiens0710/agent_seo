<?php
/**
 * Quản lý trang cài đặt của Plugin Agent SEO
 */

defined('ABSPATH') || exit;

class Agent_SEO_Settings {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_settings_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'handle_actions'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_agent_seo_batch_status', array($this, 'ajax_batch_status'));
        add_action('wp_ajax_agent_seo_save_product', array($this, 'ajax_save_product'));
        add_action('wp_ajax_agent_seo_autosave_setting', array($this, 'ajax_autosave_setting'));
        add_action('wp_ajax_agent_seo_retry_image', array($this, 'ajax_retry_image'));
    }

    public function ajax_save_product() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Không có quyền truy cập.'), 403);
        }
        check_ajax_referer('agent_seo_save_product', 'nonce');
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        update_option('aseo_target_product', $product_id);
        wp_send_json_success(array('product_id' => $product_id, 'message' => $product_id ? 'Đã lưu sản phẩm.' : 'Đã chuyển sang viết bài chung.'));
    }

    public function ajax_autosave_setting() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Không có quyền truy cập.'), 403);
        }
        check_ajax_referer('agent_seo_autosave_setting', 'nonce');
        $allowed = array(
            'aseo_primary_keyword' => 'text', 'aseo_secondary_keywords' => 'textarea',
            'aseo_keywords' => 'textarea', 'aseo_niche' => 'text', 'aseo_brand_voice' => 'text',
            'aseo_master_prompt_brief' => 'textarea', 'aseo_master_prompt' => 'textarea',
            'aseo_cron_interval' => 'text', 'aseo_brand_name' => 'text', 'aseo_brand_address' => 'text',
            'aseo_brand_phone' => 'text', 'aseo_brand_contact' => 'text', 'aseo_brand_price' => 'text',
            'aseo_brand_cta' => 'text', 'aseo_gsc_property' => 'text', 'aseo_gsc_sitemap_url' => 'url',
            'aseo_reference_image_id' => 'int', 'aseo_gemini_api_key' => 'text',
            'aseo_nvidia_api_key' => 'text', 'aseo_duky_api_key' => 'text',
            'aseo_duky_model' => 'text', 'aseo_image_engine' => 'text',
            'aseo_indexnow_key' => 'text', 'aseo_gsc_client_id' => 'text',
            'aseo_gsc_client_secret' => 'text', 'aseo_source_website' => 'url',
            'aseo_master_text_prompt' => 'textarea', 'aseo_master_image_prompt' => 'textarea',
            'aseo_post_status' => 'text'
        );
        $field = isset($_POST['field']) ? sanitize_key(wp_unslash($_POST['field'])) : '';
        if (!isset($allowed[$field])) {
            wp_send_json_error(array('message' => 'Trường cấu hình không được phép tự lưu.'), 400);
        }
        $value = isset($_POST['value']) ? wp_unslash($_POST['value']) : '';
        if ($allowed[$field] === 'textarea') {
            $value = sanitize_textarea_field($value);
        } elseif ($allowed[$field] === 'url') {
            $value = esc_url_raw($value);
        } elseif ($allowed[$field] === 'int') {
            $value = absint($value);
        } else {
            $value = sanitize_text_field($value);
        }
        update_option($field, $value);
        wp_send_json_success(array('field' => $field));
    }

    public function ajax_retry_image() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Không có quyền truy cập.'), 403);
        }
        check_ajax_referer('agent_seo_retry_image', 'nonce');
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $post = get_post($post_id);
        if ($post_id <= 0 || !$post || $post->post_type !== 'post') {
            wp_send_json_error(array('message' => 'Bài viết không hợp lệ.'), 400);
        }

        @set_time_limit(180);

        $job = get_post_meta($post_id, '_agent_seo_image_job', true);
        if (!is_array($job) || empty($job['prompt'])) {
            // Tự động dựng lại job ảnh từ tiêu đề bài viết và sản phẩm mục tiêu
            $product_id = get_option('aseo_target_product', '');
            $product_image_url = '';
            $product_image_id = 0;
            if (!empty($product_id) && class_exists('WooCommerce')) {
                $product = wc_get_product($product_id);
                if ($product) {
                    $product_image_id = $product->get_image_id();
                    $product_image_url = $product_image_id ? wp_get_attachment_url($product_image_id) : '';
                }
            }

            $prompt = 'Authentic editorial documentary photography in a real-world environment illustrating: ' . $post->post_title . ', cool-toned 6000K-6500K daylight, accurate white balance, true-to-life colors, soft non-yellow shadows, no CGI, no generic AI stock photo';

            $job = array(
                'prompt' => $prompt,
                'title' => $post->post_title,
                'keyword' => get_post_meta($post_id, 'rank_math_focus_keyword', true),
                'product_image' => $product_image_url,
                'product_image_id' => $product_image_id
            );
            update_post_meta($post_id, '_agent_seo_image_job', $job);
        }

        // Xóa các vết lịch sử cũ để thử lại sạch sẽ
        delete_post_meta($post_id, '_agent_seo_duky_media_id');
        delete_post_meta($post_id, '_agent_seo_image_attempts');

        if (!class_exists('Agent_SEO_Orchestrator')) {
            require_once dirname(__FILE__) . '/class-orchestrator.php';
        }

        $orchestrator = new Agent_SEO_Orchestrator();
        $attach_id = $orchestrator->generate_featured_image($post_id, $job);
        $image_is_set = $orchestrator->ensure_featured_image($post_id, $attach_id);

        if ($image_is_set) {
            $orchestrator->clear_image_job($post_id);
            $thumb_url = get_the_post_thumbnail_url($post_id, 'thumbnail');
            wp_send_json_success(array(
                'message' => 'Đã tạo lại ảnh AI thành công!',
                'thumb_url' => $thumb_url
            ));
        } else {
            wp_send_json_error(array('message' => 'Tạo ảnh AI thất bại. Vui lòng kiểm tra API Key hoặc thử lại.'), 500);
        }
    }

    public function ajax_batch_status() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Không có quyền truy cập.'), 403);
        }
        check_ajax_referer('agent_seo_batch_status', 'nonce');
        $batch = get_option('aseo_batch_status', array());
        if (!is_array($batch) || empty($batch)) {
            $batch = array('status' => 'idle', 'total' => 0, 'completed' => 0, 'current' => 0, 'message' => 'Chưa có tác vụ đang chạy.');
        }
        // Tự cứu một batch đã xếp hàng nhưng WP-Cron chưa được website kích hoạt.
        if (isset($batch['status']) && in_array($batch['status'], array('queued', 'waiting'), true)) {
            if ($batch['status'] === 'queued' && get_transient('agent_seo_generation_lock')) {
                // Một worker thật sẽ đổi trạng thái sang running ngay sau khi khóa.
                // Nếu vẫn queued thì đây là khóa sót lại do request cũ bị timeout.
                delete_transient('agent_seo_generation_lock');
            }
            $last_kick = intval(isset($batch['last_kick_at']) ? $batch['last_kick_at'] : 0);
            $updated_at = intval(isset($batch['updated_at']) ? $batch['updated_at'] : 0);
            if (time() - max($last_kick, $updated_at) >= 15) {
                $remaining = max(1, intval(isset($batch['remaining']) ? $batch['remaining'] : (intval($batch['total']) - intval($batch['completed']))));
                $requested_status = isset($batch['requested_status']) && $batch['requested_status'] === 'draft' ? 'draft' : 'publish';
                $args = array($requested_status, $remaining);
                if (!wp_next_scheduled('agent_seo_background_task', $args)) {
                    wp_schedule_single_event(time(), 'agent_seo_background_task', $args);
                }
                $batch['last_kick_at'] = time();
                $batch['message'] = 'Đang tự khởi động lại worker xử lý bài viết...';
                update_option('aseo_batch_status', $batch, false);
                if (function_exists('spawn_cron')) {
                    spawn_cron(time());
                }
            }
        }
        $total = max(0, intval(isset($batch['total']) ? $batch['total'] : 0));
        $completed = max(0, min($total, intval(isset($batch['completed']) ? $batch['completed'] : 0)));
        $batch['total'] = $total;
        $batch['completed'] = $completed;
        $batch['percent'] = $total > 0 ? intval(round(($completed / $total) * 100)) : 0;
        if (isset($batch['status']) && $batch['status'] === 'images_pending') {
            $batch['percent'] = 95;
        }
        wp_send_json_success($batch);
    }

    /**
     * Thêm trang cài đặt vào Admin Menu
     */
    public function add_settings_menu() {
        add_menu_page(
            'Agent SEO Settings',
            'Agent SEO',
            'manage_options',
            'agent-seo-settings',
            array($this, 'render_settings_page'),
            'dashicons-text-page',
            80
        );
    }

    /**
     * Nạp thư viện media của WordPress cho trang cài đặt
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'agent-seo-settings') !== false) {
            wp_enqueue_media();
        }
    }

    /**
     * Đăng ký cấu hình với WordPress Options API
     */
    public function register_settings() {
        register_setting('agent_seo_settings_group', 'aseo_gemini_api_key');
        register_setting('agent_seo_settings_group', 'aseo_nvidia_api_key');
        register_setting('agent_seo_settings_group', 'aseo_kaggle_api_url');
        register_setting('agent_seo_settings_group', 'aseo_duky_api_key');
        register_setting('agent_seo_settings_group', 'aseo_duky_model');
        register_setting('agent_seo_settings_group', 'aseo_indexnow_key');
        register_setting('agent_seo_settings_group', 'aseo_gsc_client_id');
        register_setting('agent_seo_settings_group', 'aseo_gsc_client_secret');
        register_setting('agent_seo_settings_group', 'aseo_gsc_property');
        register_setting('agent_seo_settings_group', 'aseo_gsc_sitemap_url');
        register_setting('agent_seo_settings_group', 'aseo_source_website');
        register_setting('agent_seo_settings_group', 'aseo_image_engine');
        register_setting('agent_seo_settings_group', 'aseo_niche');
        register_setting('agent_seo_settings_group', 'aseo_brand_voice');
        register_setting('agent_seo_settings_group', 'aseo_keywords');
        register_setting('agent_seo_settings_group', 'aseo_primary_keyword');
        register_setting('agent_seo_settings_group', 'aseo_secondary_keywords');
        register_setting('agent_seo_settings_group', 'aseo_master_text_prompt');
        register_setting('agent_seo_settings_group', 'aseo_master_image_prompt');
        register_setting('agent_seo_settings_group', 'aseo_master_prompt');
        register_setting('agent_seo_settings_group', 'aseo_master_prompt_brief');
        register_setting('agent_seo_settings_group', 'aseo_cron_interval');
        register_setting('agent_seo_settings_group', 'aseo_post_status');
        register_setting('agent_seo_settings_group', 'aseo_target_product');
        register_setting('agent_seo_settings_group', 'aseo_reference_image_id');
        // Đăng ký các trường cấu hình thông tin doanh nghiệp tự chọn
        register_setting('agent_seo_settings_group', 'aseo_brand_name');
        register_setting('agent_seo_settings_group', 'aseo_brand_address');
        register_setting('agent_seo_settings_group', 'aseo_brand_phone');
        register_setting('agent_seo_settings_group', 'aseo_brand_contact');
        register_setting('agent_seo_settings_group', 'aseo_brand_price');
        register_setting('agent_seo_settings_group', 'aseo_brand_cta');
    }

    /**
     * Xử lý các hành động nhấn nút (Kiểm tra kết nối, Viết bài ngay)
     */
    public function handle_actions() {
        if (!isset($_POST['aseo_action']) || !check_admin_referer('aseo_action_nonce', 'aseo_nonce')) {
            return;
        }

        $action = sanitize_text_field($_POST['aseo_action']);

        if ($action === 'test_connection') {
            $api_key = sanitize_text_field($_POST['aseo_gemini_api_key_temp']);
            if (empty($api_key)) {
                $api_key = get_option('aseo_gemini_api_key');
            }

            $nvidia_key = sanitize_text_field($_POST['aseo_nvidia_api_key_temp']);
            if (empty($nvidia_key)) {
                $nvidia_key = get_option('aseo_nvidia_api_key');
            }

            $duky_key = isset($_POST['aseo_duky_api_key_temp']) ? sanitize_text_field($_POST['aseo_duky_api_key_temp']) : '';
            if (empty($duky_key)) {
                $duky_key = get_option('aseo_duky_api_key');
            }
            $duky_model = isset($_POST['aseo_duky_model_temp']) ? sanitize_text_field($_POST['aseo_duky_model_temp']) : '';
            if (empty($duky_model)) {
                $duky_model = get_option('aseo_duky_model', 'GEM_PIX_2');
            }

            if (empty($api_key)) {
                add_settings_error('agent_seo_messages', 'empty_key', 'Vui lòng điền Gemini API Key để kiểm tra.', 'error');
                return;
            }

            // Gọi thử kết nối Text API
            $test_text = Agent_SEO_Gemini_Text::test_connection($api_key);
            
            $kaggle_url = isset($_POST['aseo_kaggle_api_url_temp']) ? sanitize_text_field($_POST['aseo_kaggle_api_url_temp']) : '';
            if (empty($kaggle_url)) {
                $kaggle_url = get_option('aseo_kaggle_api_url');
            }

            $image_engine_sel = isset($_POST['aseo_image_engine_temp']) ? sanitize_text_field($_POST['aseo_image_engine_temp']) : '';
            if (empty($image_engine_sel)) {
                $image_engine_sel = get_option('aseo_image_engine', 'duky');
            }

            // Gọi thử kết nối Image API dựa trên Lựa chọn Bộ máy sinh ảnh
            if ($image_engine_sel === 'duky') {
                $test_image = Agent_SEO_Gemini_Image::test_connection_duky($duky_key, $duky_model);
                $image_engine = 'DukyAI ImageFX';
            } elseif ($image_engine_sel === 'kaggle') {
                if (empty($kaggle_url)) {
                    $test_image = array('success' => false, 'message' => 'Bạn chọn Google Flow nhưng chưa cấu hình Google Flow API URL.');
                } else {
                    $test_image = Agent_SEO_Gemini_Image::test_connection_kaggle($kaggle_url);
                }
                $image_engine = 'Google Flow';
            } elseif ($image_engine_sel === 'nvidia') {
                if (empty($nvidia_key)) {
                    $test_image = array('success' => false, 'message' => 'Bạn chọn NVIDIA FLUX nhưng chưa điền NVIDIA NIM API Key.');
                } else {
                    $test_image = Agent_SEO_Gemini_Image::test_connection_nvidia($nvidia_key);
                }
                $image_engine = 'NVIDIA FLUX';
            } else {
                $test_image = Agent_SEO_Gemini_Image::test_connection($api_key);
                $image_engine = 'Gemini Imagen 4';
            }

            if ($test_text['success'] && $test_image['success']) {
                add_settings_error(
                    'agent_seo_messages',
                    'test_success',
                    'Kết nối thành công! Cả API Văn bản (Gemini 3.1 Flash Lite) và API Hình ảnh (' . $image_engine . ') đều hoạt động tốt.',
                    'updated'
                );
            } else {
                $err_msg = 'Lỗi kết nối API: <br>';
                if (!$test_text['success']) {
                    $err_msg .= '- Lỗi Text: ' . esc_html($test_text['message']) . '<br>';
                }
                if (!$test_image['success']) {
                    $err_msg .= '- Lỗi Image: ' . esc_html($test_image['message']) . '<br>';
                }
                add_settings_error('agent_seo_messages', 'test_failed', $err_msg, 'error');
            }
        }

        if ($action === 'force_run') {
            $num_posts = isset($_POST['aseo_num_posts']) ? intval($_POST['aseo_num_posts']) : 1;
            $num_posts = max(1, min(10, $num_posts)); // Giới hạn tối đa 10 bài mỗi lượt để tránh quá tải
            if (isset($_POST['aseo_primary_keyword_run'])) {
                $submitted_primary_keyword = sanitize_text_field(wp_unslash($_POST['aseo_primary_keyword_run']));
                if ($submitted_primary_keyword !== '') {
                    update_option('aseo_primary_keyword', $submitted_primary_keyword);
                }
            }
            if (trim(get_option('aseo_primary_keyword', '')) === '') {
                add_settings_error('agent_seo_messages', 'missing_primary_keyword', 'Vui lòng nhập và lưu từ khóa chính cố định trước khi tạo bài.', 'error');
                return;
            }
            if (isset($_POST['aseo_target_product_run'])) {
                $selected_product_id = absint($_POST['aseo_target_product_run']);
                update_option('aseo_target_product', $selected_product_id);
            } else {
                $selected_product_id = absint(get_option('aseo_target_product', ''));
            }
            // Các lượt bấm tạo bài đều tự động xuất bản sau khi hoàn tất.
            $post_status = 'publish';
            update_option('aseo_post_status', 'publish');

            // Chỉ xếp bài đầu tiên. Worker sẽ tự xếp bài kế tiếp sau khi bài hiện tại hoàn tất,
            // tránh nhiều request Gemini/Google Flow chạy chồng lên nhau.
            // Remove stale/duplicate workers from an earlier click before
            // starting this exact batch, otherwise counts can be added together.
            wp_clear_scheduled_hook('agent_seo_background_task');
            delete_transient('agent_seo_generation_lock');
            update_option('aseo_batch_status', array(
                'status' => 'queued',
                'total' => $num_posts,
                'completed' => 0,
                'current' => 0,
                'remaining' => $num_posts,
                'requested_status' => $post_status,
                'product_id' => $selected_product_id,
                'message' => 'Đã xếp hàng ' . $num_posts . ' bài. Đang chờ worker bắt đầu...',
                'started_at' => time(),
                'updated_at' => time(),
                'last_kick_at' => time()
            ), false);
            wp_schedule_single_event(time(), 'agent_seo_background_task', array($post_status, $num_posts));
            if (function_exists('spawn_cron')) {
                spawn_cron(time());
            }

            $status_label = $post_status === 'publish' ? 'xuất bản' : 'lưu bản nháp';
            add_settings_error(
                'agent_seo_messages',
                'run_queued',
                'Đã xếp hàng <strong>' . $num_posts . ' bài viết</strong> để AI ' . $status_label . ' ở chế độ nền. Bạn có thể rời trang này; bài sẽ xuất hiện khi hoàn tất.',
                'updated'
            );
        }

        if ($action === 'suggest_master_prompts') {
            $api_key = get_option('aseo_gemini_api_key', '');
            $user_brief = isset($_POST['aseo_master_prompt_brief_temp']) ? sanitize_textarea_field(wp_unslash($_POST['aseo_master_prompt_brief_temp'])) : '';
            if (empty($user_brief)) {
                $user_brief = get_option('aseo_master_prompt_brief', '');
            }
            update_option('aseo_master_prompt_brief', $user_brief);

            $niche = isset($_POST['aseo_niche_temp']) ? sanitize_text_field(wp_unslash($_POST['aseo_niche_temp'])) : '';
            $brand_name = isset($_POST['aseo_brand_name_temp']) ? sanitize_text_field(wp_unslash($_POST['aseo_brand_name_temp'])) : '';
            $product_info = isset($_POST['aseo_product_info_temp']) ? sanitize_textarea_field(wp_unslash($_POST['aseo_product_info_temp'])) : '';
            if (empty($niche)) $niche = get_option('aseo_niche', 'Lĩnh vực kinh doanh của website');
            if (empty($brand_name)) $brand_name = get_option('aseo_brand_name', '');
            if (empty($product_info)) $product_info = get_option('aseo_brand_price', '');
            $target_product_id = isset($_POST['aseo_target_product_temp']) ? absint($_POST['aseo_target_product_temp']) : absint(get_option('aseo_target_product', ''));
            if ($target_product_id) {
                update_option('aseo_target_product', $target_product_id);
            }
            if ($target_product_id && function_exists('wc_get_product')) {
                $target_product = wc_get_product($target_product_id);
                if ($target_product) {
                    $product_info = trim($target_product->get_name() . ' | ' . $product_info);
                }
            }
            $suggestions = Agent_SEO_Gemini_Text::generate_master_prompt_suggestions(
                $api_key,
                $niche,
                $brand_name,
                $product_info,
                $user_brief
            );
            if (!empty($suggestions['success'])) {
                update_option('aseo_master_prompt', $suggestions['master_prompt']);
                update_option('aseo_master_text_prompt', $suggestions['master_prompt']);
                update_option('aseo_master_image_prompt', $suggestions['master_prompt']);
                add_settings_error('agent_seo_messages', 'master_prompt_suggested', 'AI đã tạo xong cấu hình nội dung và hình ảnh. Các bài viết tiếp theo sẽ tự động sử dụng cấu hình này.', 'updated');
            } else {
                add_settings_error('agent_seo_messages', 'master_prompt_failed', 'Không thể tạo gợi ý Master Prompt: ' . esc_html($suggestions['message']), 'error');
            }
        }

        if ($action === 'import_website_profile') {
            $website_url = isset($_POST['aseo_source_website_temp']) ? esc_url_raw($_POST['aseo_source_website_temp']) : '';
            if (empty($website_url)) {
                add_settings_error('agent_seo_messages', 'website_import_failed', 'Vui lòng nhập địa chỉ website cần lấy thông tin.', 'error');
            } else {
                $profile = Agent_SEO_Gemini_Text::extract_website_profile(get_option('aseo_gemini_api_key', ''), $website_url);
                if (!empty($profile['success'])) {
                    set_transient('aseo_website_profile_preview_' . get_current_user_id(), $profile, 30 * MINUTE_IN_SECONDS);
                    update_option('aseo_source_website', $website_url);
                    add_settings_error('agent_seo_messages', 'website_import_success', 'Đã lấy thông tin từ website. Hãy kiểm tra các ô bên dưới rồi bấm Lưu cấu hình.', 'updated');
                } else {
                    add_settings_error('agent_seo_messages', 'website_import_failed', 'Không thể lấy thông tin website: ' . esc_html($profile['message']), 'error');
                }
            }
        }
    }

    /**
     * Giao diện trang cài đặt quản trị
     */
    public function render_settings_page() {
        // Lấy các giá trị đã cấu hình
        $api_key = get_option('aseo_gemini_api_key', '');
        $nvidia_api_key = get_option('aseo_nvidia_api_key', '');
        $kaggle_api_url = get_option('aseo_kaggle_api_url', '');
        $duky_api_key = get_option('aseo_duky_api_key', '');
        $indexnow_key = get_option('aseo_indexnow_key', '');
        $gsc_client_id = get_option('aseo_gsc_client_id', '');
        $gsc_client_secret = get_option('aseo_gsc_client_secret', '');
        $gsc_property = get_option('aseo_gsc_property', '');
        $gsc_sitemap_url = get_option('aseo_gsc_sitemap_url', home_url('/sitemap_index.xml'));
        $gsc_token = get_option('aseo_gsc_token', array());
        $source_website = get_option('aseo_source_website', '');
        $duky_model = get_option('aseo_duky_model', 'GEM_PIX_2');
        if (!in_array($duky_model, array('GEM_PIX_2', 'NARWHAL', 'R2I'), true)) {
            $duky_model = 'GEM_PIX_2';
        }
        $image_engine = get_option('aseo_image_engine', 'duky');
        if (in_array($image_engine, array('nvidia', 'kaggle'), true)) {
            $image_engine = 'duky';
        }
        $niche = get_option('aseo_niche', 'Sản phẩm, dịch vụ và lĩnh vực kinh doanh của website');
        $brand_voice = get_option('aseo_brand_voice', 'Chuyên nghiệp, tin cậy, rõ ràng và phù hợp với khách hàng mục tiêu');
        $keywords = get_option('aseo_keywords', '');
        $primary_keyword = get_option('aseo_primary_keyword', '');
        $secondary_keywords = get_option('aseo_secondary_keywords', '');
        $master_text_prompt = get_option('aseo_master_text_prompt', '');
        $master_image_prompt = get_option('aseo_master_image_prompt', '');
        $master_prompt = get_option('aseo_master_prompt', '');
        $master_prompt_brief = get_option('aseo_master_prompt_brief', '');
        if (empty($master_prompt)) {
            $master_prompt = $master_text_prompt ? $master_text_prompt : $master_image_prompt;
        }
        $batch_status = get_option('aseo_batch_status', array());
        $cron_interval = get_option('aseo_cron_interval', 'daily');
        $default_post_status = get_option('aseo_post_status', 'publish');
        $target_product = get_option('aseo_target_product', '');
        $reference_image_id = absint(get_option('aseo_reference_image_id', 0));
        $reference_image_url = $reference_image_id ? wp_get_attachment_image_url($reference_image_id, 'medium') : '';
        
        // Giá trị cấu hình thông tin doanh nghiệp tự chọn
        $brand_name = get_option('aseo_brand_name', '');
        $brand_address = get_option('aseo_brand_address', '');
        $brand_phone = get_option('aseo_brand_phone', '');
        $brand_contact = get_option('aseo_brand_contact', '');
        $brand_price = get_option('aseo_brand_price', '');
        $brand_cta = get_option('aseo_brand_cta', '');
        $website_preview = get_transient('aseo_website_profile_preview_' . get_current_user_id());
        if (is_array($website_preview) && !empty($website_preview['success'])) {
            $brand_name = $website_preview['brand_name'];
            $brand_address = $website_preview['brand_address'];
            $brand_phone = $website_preview['brand_phone'];
            $brand_contact = $website_preview['brand_contact'];
            $brand_price = trim($website_preview['product_summary'] . (!empty($website_preview['brand_price']) ? ' Giá công khai: ' . $website_preview['brand_price'] : ''));
            $brand_cta = $website_preview['brand_cta'];
            if (!empty($website_preview['niche'])) $niche = $website_preview['niche'];
            if (!empty($website_preview['brand_voice'])) $brand_voice = $website_preview['brand_voice'];
        }

        // Lấy danh sách sản phẩm WooCommerce thực tế
        $wc_products = array();
        $wc_product_images = array();
        if (post_type_exists('product')) {
            $products_posts = get_posts(array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
            ));
            foreach ($products_posts as $p) {
                $wc_products[$p->ID] = $p->post_title;
                $product_image_id = get_post_thumbnail_id($p->ID);
                $wc_product_images[$p->ID] = $product_image_id ? wp_get_attachment_image_url($product_image_id, 'thumbnail') : '';
            }
        }
        $target_product_image = !empty($target_product) && !empty($wc_product_images[$target_product]) ? $wc_product_images[$target_product] : '';

        // Thống kê bài viết tự động
        $generated_posts = get_posts(array(
            'post_type'   => 'post',
            'post_status' => array('publish', 'draft', 'pending', 'future'),
            'meta_key'    => '_agent_seo_generated',
            'numberposts' => -1
        ));
        $count_generated = count($generated_posts);
        $count_published = 0;
        $count_drafts = 0;
        foreach ($generated_posts as $generated_post) {
            if ($generated_post->post_status === 'publish') $count_published++;
            if ($generated_post->post_status === 'draft') $count_drafts++;
        }
        // Đếm từ khóa còn chờ viết
        $kw_lines = array_filter(explode("\n", $keywords), 'trim');
        $kw_remaining = 0;
        foreach ($kw_lines as $kl) {
            if (strpos($kl, '[Đã viết]') === false && trim($kl) !== '') $kw_remaining++;
        }
        $engine_labels = array('duky' => 'DukyAI ImageFX', 'kaggle' => 'Google Flow', 'nvidia' => 'NVIDIA FLUX', 'imagen' => 'Imagen 4');
        $current_engine_label = isset($engine_labels[$image_engine]) ? $engine_labels[$image_engine] : 'N/A';

        // Lấy 10 bài viết gần nhất do AI tạo
        $recent_ai_posts = get_posts(array(
            'post_type'   => 'post',
            'post_status' => array('publish', 'draft', 'pending', 'future'),
            'meta_key'    => '_agent_seo_generated',
            'numberposts' => 10,
            'orderby'     => 'date',
            'order'       => 'DESC'
        ));
        ?>
        <style>
            /* ========== RESET & BASE ========== */
            .aseo-wrap * { box-sizing: border-box; }
            .aseo-wrap { max-width: calc(100% - 20px); margin: 20px 20px 40px 0; font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }

            /* ========== HEADER ========== */
            .aseo-header {
                background: linear-gradient(135deg, #0a1f14 0%, #143d28 50%, #1a5c3a 100%);
                color: #fff; padding: 28px 32px; border-radius: 16px 16px 0 0;
                display: flex; align-items: center; justify-content: space-between;
                position: relative; overflow: hidden;
            }
            .aseo-header::before {
                content: ''; position: absolute; top: -50%; right: -10%; width: 300px; height: 300px;
                background: radial-gradient(circle, rgba(197,168,92,0.12) 0%, transparent 70%);
                border-radius: 50%;
            }
            .aseo-header-left { display: flex; align-items: center; gap: 14px; z-index: 1; }
            .aseo-header-logo {
                width: 44px; height: 44px; background: rgba(197,168,92,0.15);
                border: 2px solid rgba(197,168,92,0.4); border-radius: 12px;
                display: flex; align-items: center; justify-content: center; font-size: 22px;
            }
            .aseo-header h2 { margin: 0; color: #fff; font-size: 1.55rem; font-weight: 700; letter-spacing: -0.3px; }
            .aseo-header h2 small { display: block; font-size: 0.78rem; font-weight: 400; color: rgba(255,255,255,0.6); margin-top: 2px; }
            .aseo-header-right { display: flex; align-items: center; gap: 12px; z-index: 1; }
            .aseo-badge { background: linear-gradient(135deg, #C5A85C, #e0c97a); color: #0a1f14; font-size: 0.7rem; font-weight: 800; padding: 5px 14px; border-radius: 30px; text-transform: uppercase; letter-spacing: 0.5px; }
            .aseo-status-dot { display: flex; align-items: center; gap: 6px; font-size: 0.78rem; color: rgba(255,255,255,0.7); }
            .aseo-status-dot::before { content: ''; width: 8px; height: 8px; background: #4ade80; border-radius: 50%; animation: aseo-pulse 2s infinite; }
            @keyframes aseo-pulse { 0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(74,222,128,0.5); } 50% { opacity: 0.7; box-shadow: 0 0 0 6px rgba(74,222,128,0); } }

            /* ========== MAIN CONTAINER ========== */
            .aseo-main {
                background: #fff; border: 1px solid #e2e5e9; border-top: none;
                padding: 0 32px 32px; border-radius: 0 0 16px 16px;
                box-shadow: 0 4px 24px rgba(0,0,0,0.04);
            }

            /* ========== STATS CARDS ========== */
            .aseo-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; padding: 24px 0; border-bottom: 1px solid #eef0f2; margin-bottom: 24px; }
            .aseo-stats .aseo-stat-card:nth-child(2) { display: none; }
            .aseo-stat-card {
                background: #f8faf9; border: 1px solid #e8ece9; border-radius: 12px; padding: 18px 20px;
                display: flex; align-items: center; gap: 14px; transition: all 0.25s ease;
            }
            .aseo-stat-card:hover { border-color: #c5d4cb; transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.04); }
            .aseo-stat-icon {
                width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center;
                font-size: 20px; flex-shrink: 0;
            }
            .aseo-stat-icon.green { background: #dcfce7; color: #16a34a; }
            .aseo-stat-icon.amber { background: #fef3c7; color: #d97706; }
            .aseo-stat-icon.blue { background: #dbeafe; color: #2563eb; }
            .aseo-stat-info { }
            .aseo-stat-value { font-size: 1.5rem; font-weight: 800; color: #1a1a1a; line-height: 1.2; }
            .aseo-stat-label { font-size: 0.78rem; color: #6b7280; font-weight: 500; margin-top: 2px; }

            /* ========== QUICK CREATE ========== */
            .aseo-quick-create { padding: 20px 22px; margin-bottom: 24px; border: 1px solid #cfe3d6; border-radius: 14px; background: #f8fcf9; }
            .aseo-quick-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:16px; }
            .aseo-quick-create h3 { margin: 0 0 3px; color: #0a1f14; font-size: 1.05rem; }
            .aseo-quick-create p { margin: 0; color: #64748b; font-size: .82rem; }
            .aseo-create-form { display: grid; grid-template-columns: minmax(260px, 1fr) 105px auto; align-items: end; gap: 12px; }
            .aseo-create-control { display:flex; flex-direction:column; gap:6px; }
            .aseo-create-control > span { color:#475569; font-size:.75rem; font-weight:800; }
            .aseo-create-form select { width:100%; min-height:46px; border:1px solid #cbd5e1; border-radius:9px; padding:0 12px; background:#fff; color:#17251d; font-size:.88rem; }
            .aseo-product-run-select { max-width: none; }
            .aseo-create-form .aseo-btn-run { min-height:46px; padding:0 24px; white-space:nowrap; }
            .aseo-publish-inline { color:#16834b; font-size:.76rem; font-weight:700; white-space:nowrap; padding-top:2px; }
            .aseo-product-main { border-color: #cfe3d6; }
            .aseo-product-main-row { display:flex; align-items:flex-start; gap:24px; min-height:230px; }
            .aseo-product-main-row .aseo-field { flex:1; min-width:0; }
            .aseo-product-main-thumb { display:block; width:220px; height:220px; flex:0 0 220px; object-fit:contain; margin:0; border-radius:14px; border:1px solid #dbe7df; background:#fff; box-shadow:0 8px 20px rgba(10,31,20,.08); }
            .aseo-product-image-skeleton { width:220px; height:220px; flex:0 0 220px; border-radius:14px; background:linear-gradient(100deg,#edf2ef 30%,#f8faf9 45%,#edf2ef 60%); background-size:200% 100%; animation:aseoSkeleton 1.4s ease-in-out infinite; }
            .aseo-product-image-skeleton.is-hidden { display:none; }
            @keyframes aseoSkeleton { 0% { background-position:200% 0; } 100% { background-position:-200% 0; } }
            .aseo-reference-image-box { margin-top: 14px; padding: 12px 14px; border: 1px dashed #cbd5e1; border-radius: 10px; background: #f8fafc; }
            .aseo-reference-image-box label { display: block; font-weight: 600; margin-bottom: 8px; }
            .aseo-reference-image-box .desc-inline { font-weight: 400; color: #64748b; font-size: 12px; }
            .aseo-reference-image-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
            .aseo-reference-image-row img { display:none !important; }
            .aseo-suggestion-chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:9px; }
            .aseo-suggestion-chips button { border:1px solid #cfe3d6; background:#f5fbf7; color:#166534; border-radius:999px; padding:5px 10px; font-size:.74rem; cursor:pointer; transition:background-color .2s, border-color .2s, transform .2s; }
            .aseo-suggestion-chips button:hover { background:#e1f3e7; border-color:#83b995; transform:translateY(-1px); }
            .aseo-suggestion-chips button:focus-visible { outline:3px solid rgba(37,99,235,.3); outline-offset:2px; }
            #aseo_niche_select, #aseo_brand_voice_select { margin-top:8px; }
            #aseo_niche, #aseo_brand_voice { display:none; margin-top:8px; }
            #aseo_niche.aseo-custom-visible, #aseo_brand_voice.aseo-custom-visible { display:block; }
            .aseo-suggestion-chips { display:none; }
            .aseo-product-save-status { min-height: 18px; color: #16834b; font-size: .74rem; font-weight: 700; }
            .aseo-guide { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin: 8px 0 18px; }
            .aseo-guide-step { display:flex; align-items:flex-start; gap:11px; padding:14px; border:1px solid #e2e8f0; border-radius:12px; background:#fff; text-decoration:none; color:#17251d; transition:border-color .2s, box-shadow .2s, transform .2s; }
            .aseo-guide-step:hover { border-color:#9bc7aa; box-shadow:0 5px 14px rgba(10,31,20,.08); transform:translateY(-1px); }
            .aseo-guide-number { width:28px; height:28px; flex:0 0 28px; border-radius:50%; display:grid; place-items:center; background:#0a1f14; color:#fff; font-weight:800; }
            .aseo-guide-step strong { display:block; font-size:.86rem; margin-bottom:3px; }
            .aseo-guide-step span { display:block; color:#64748b; font-size:.76rem; line-height:1.35; }
            .aseo-advanced-hint { margin:0 0 14px; padding:10px 13px; border-radius:9px; background:#f1f5f9; color:#475569; font-size:.8rem; }
            .aseo-guide-step:focus-visible, .aseo-btn:focus-visible, .aseo-tab-btn:focus-visible, .aseo-api-toggle:focus-visible { outline:3px solid rgba(37,99,235,.35); outline-offset:2px; }
            @media (max-width: 900px) { .aseo-guide { grid-template-columns:1fr; } .aseo-create-form { grid-template-columns:1fr; } }
            @media (max-width: 700px) { .aseo-product-main-row { flex-direction:column; } .aseo-product-main-thumb, .aseo-product-image-skeleton { width:180px; height:180px; flex-basis:180px; margin:0 auto; } }
            .aseo-autosave-status { display:inline-block; margin-left:10px; color:#64748b; font-size:.76rem; }
            .aseo-autosave-status.saving { color:#b7791f; }
            .aseo-autosave-status.saved { color:#16834b; }
            .aseo-gsc-box { padding: 16px; border: 1px solid #dbe7df; border-radius: 10px; background: #f8fcf9; }
            .aseo-gsc-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-top:12px; }
            .aseo-gsc-actions code { font-size:.7rem; word-break:break-all; }

            /* ========== TABS ========== */
            .aseo-tabs { display: flex; gap: 6px; padding: 6px; background: #f3f4f6; border-radius: 12px; margin-bottom: 24px; }
            .aseo-tab-btn {
                flex: 1; padding: 11px 16px; border: none; background: transparent; border-radius: 8px;
                font-size: 0.88rem; font-weight: 600; color: #6b7280; cursor: pointer;
                transition: all 0.25s ease; display: flex; align-items: center; justify-content: center; gap: 7px;
                white-space: nowrap;
            }
            .aseo-tab-btn:hover { color: #374151; background: rgba(255,255,255,0.6); }
            .aseo-tab-btn.active {
                background: #fff; color: #0a1f14; box-shadow: 0 1px 6px rgba(0,0,0,0.08);
            }
            .aseo-tab-btn .tab-icon { font-size: 1.05rem; }
            .aseo-api-toggle {
                flex: 0 0 auto; padding: 11px 14px; border: 1px solid #d8dee4;
                background: #fff; color: #64748b; border-radius: 8px; cursor: pointer;
                font-size: .8rem; font-weight: 700; white-space: nowrap;
            }
            .aseo-api-toggle:hover, .aseo-api-toggle[aria-expanded="true"] { color: #0f5132; border-color: #8eb9a1; background: #f2faf5; }

            /* ========== TAB CONTENT ========== */
            .aseo-tab-panel { display: none; animation: aseo-fadeIn 0.3s ease; }
            .aseo-tab-panel.active { display: block; }
            #aseo-settings-form { display: flex; flex-direction: column; }
            #aseo-panel-seo { order: 1; }
            #aseo-panel-brand { order: 2; }
            #aseo-panel-api { order: 3; }
            #aseo-settings-form > div[style*="margin-top"] { order: 4; }
            #aseo-panel-api.active { display: flex; flex-direction: column; }
            #aseo-panel-api .aseo-master-section { order: -1; }
            @keyframes aseo-fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

            /* ========== FORM SECTIONS ========== */
            .aseo-section {
                border: 1px solid #e8ece9; border-radius: 12px; margin-bottom: 20px; overflow: hidden;
            }
            .aseo-section-header {
                background: #f8faf9; padding: 14px 20px; border-bottom: 1px solid #e8ece9;
                display: flex; align-items: center; gap: 10px;
            }
            .aseo-section-header .sec-icon { font-size: 1.1rem; }
            .aseo-section-header h3 { margin: 0; font-size: 0.92rem; font-weight: 700; color: #1a1a1a; }
            .aseo-section-header p { margin: 0; font-size: 0.78rem; color: #6b7280; margin-left: auto; }
            .aseo-section-body { padding: 20px; }

            /* ========== FORM GRID ========== */
            .aseo-form-grid { display: grid; grid-template-columns: 1fr; gap: 18px; }
            @media (min-width: 960px) { .aseo-form-grid { grid-template-columns: repeat(2, 1fr); } .aseo-fg-full { grid-column: span 2; } }

            /* ========== FORM FIELD ========== */
            .aseo-field { display: flex; flex-direction: column; gap: 6px; }
            .aseo-field label { font-weight: 700; color: #1e293b; font-size: 0.88rem; display: flex; align-items: center; gap: 6px; }
            .aseo-field label .field-icon { font-size: 0.95rem; opacity: 0.6; }
            .aseo-field .desc { margin: 0; font-size: 0.8rem; color: #94a3b8; line-height: 1.4; }
            .aseo-field input[type="text"],
            .aseo-field textarea,
            .aseo-field select {
                padding: 10px 14px; border-radius: 8px; border: 1.5px solid #d1d5db;
                font-size: 0.88rem; width: 100%; transition: all 0.2s ease;
                background: #fff; color: #1e293b;
            }
            .aseo-field input[type="text"]:focus,
            .aseo-field textarea:focus,
            .aseo-field select:focus {
                border-color: #1a5c3a; box-shadow: 0 0 0 3px rgba(26,92,58,0.1); outline: none;
            }
            .aseo-field textarea { resize: vertical; font-family: inherit; }
            .aseo-master-builder { max-width: 920px; }
            .aseo-chat-builder { max-width: 920px; padding:16px; border:1px solid #dbe7df; border-radius:16px; background:linear-gradient(180deg,#f7fbf8,#fff); }
            .aseo-chat-window { display:flex; align-items:flex-start; gap:10px; margin-bottom:16px; }
            .aseo-chat-avatar { width:34px; height:34px; flex:0 0 34px; display:grid; place-items:center; border-radius:50%; background:#0a1f14; color:#e7c969; font-size:.72rem; font-weight:800; }
            .aseo-chat-bubble { max-width:78%; padding:11px 14px; border-radius:4px 14px 14px 14px; background:#e8f4eb; color:#234333; line-height:1.5; font-size:.86rem; }
            .aseo-chat-builder .aseo-field { margin:0; }
            .aseo-chat-builder .aseo-field > label { display:block; margin-bottom:4px; color:#0a1f14; font-size:.82rem; font-weight:800; }
            .aseo-chat-builder .aseo-master-brief { border:1.5px solid #b9cfc1 !important; border-radius:12px !important; min-height:92px; padding:13px 15px !important; box-shadow:0 2px 7px rgba(10,31,20,.04); }
            .aseo-chat-builder .aseo-master-brief:focus { border-color:#16834b !important; box-shadow:0 0 0 3px rgba(22,131,75,.12) !important; }
            .aseo-master-brief {
                margin-top: 8px; min-height: 105px; padding: 15px !important;
                font-size: .92rem !important; line-height: 1.6; background: #fff !important;
            }
            .aseo-master-editor {
                min-height: 260px; padding: 16px !important;
                font-size: .86rem !important; line-height: 1.65; background: #fbfdfb !important;
            }
            .aseo-master-actions {
                display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 12px;
            }
            .aseo-master-actions .aseo-btn { min-height: 44px; padding: 10px 18px; }
            .aseo-master-actions .aseo-btn-save { box-shadow: none; }
            .aseo-chat-builder .aseo-master-actions .aseo-btn-save { border-radius:999px; padding:10px 18px; box-shadow:0 5px 12px rgba(10,31,20,.14); }
            .aseo-master-presets { display:flex; align-items:center; flex-wrap:wrap; gap:7px; margin-top:10px; }
            .aseo-master-presets-label { color:#64748b; font-size:.76rem; font-weight:700; }
            .aseo-master-preset { border:1px solid #cfe3d6; border-radius:999px; padding:6px 10px; background:#f5fbf7; color:#166534; cursor:pointer; font-size:.76rem; transition:background-color .2s, border-color .2s, transform .2s; }
            .aseo-master-preset:hover { background:#e1f3e7; border-color:#83b995; transform:translateY(-1px); }
            .aseo-master-preset:focus-visible { outline:3px solid rgba(37,99,235,.3); outline-offset:2px; }
            .aseo-master-count { color: #64748b; font-size: .76rem; margin-left: auto; }
            .aseo-master-result {
                margin-top: 16px; border: 1px solid #dfe7e2; border-radius: 9px; background: #f8faf9;
            }
            .aseo-master-result summary {
                padding: 12px 14px; cursor: pointer; color: #365347; font-size: .82rem; font-weight: 700;
            }
            /* Chat window as a real chatbot */
            .aseo-chat-log {
                display: flex;
                flex-direction: column;
                gap: 16px;
                margin-bottom: 20px;
                padding: 18px;
                border: 1.5px solid #cfe3d6;
                border-radius: 16px;
                background: #fcfdfe;
                max-height: 480px;
                overflow-y: auto;
                box-shadow: inset 0 2px 8px rgba(10,31,20,.03);
            }
            .aseo-chat-message {
                display: flex;
                gap: 12px;
                align-items: flex-start;
                max-width: 82%;
                animation: aseo-fadeIn 0.3s ease;
            }
            .aseo-chat-message-ai {
                align-self: flex-start;
            }
            .aseo-chat-message-user {
                align-self: flex-end;
                flex-direction: row-reverse;
            }
            .aseo-chat-message-user .aseo-chat-avatar {
                background: #16a34a;
                color: #fff;
            }
            .aseo-chat-message-ai .aseo-chat-bubble {
                border-radius: 4px 16px 16px 16px;
                background: #e8f4eb;
                color: #173b27;
                box-shadow: 0 2px 6px rgba(10,31,20,.04);
            }
            .aseo-chat-message-user .aseo-chat-bubble {
                border-radius: 16px 4px 16px 16px;
                background: #f0fdf4;
                color: #166534;
                border: 1px solid #d1ebd8;
                box-shadow: 0 2px 6px rgba(10,31,20,.02);
            }
            .aseo-prompt-chat-message {
                max-height: 300px;
                overflow-y: auto;
                padding: 12px 14px;
                border-radius: 8px;
                background: #ffffff;
                border: 1px solid #dbe7df;
                color: #1e293b;
                font-size: 0.82rem;
                line-height: 1.6;
                white-space: pre-wrap;
                font-family: inherit;
                margin-top: 8px;
            }
            .aseo-prompt-chat-message.is-empty { display:none; }
            .aseo-master-editor { display: block; width: 100%; border: 1.5px solid #cfe3d6 !important; border-radius: 8px !important; }
            .aseo-prompt-ready {
                display: inline-flex; align-items: center; gap: 6px; padding: 4px 9px;
                border-radius: 999px; background: #dcfce7; color: #166534; font-size: .72rem; font-weight: 700;
            }
            #aseo-panel-api .aseo-field:has(#aseo_nvidia_api_key) { display: none; }
            #aseo_image_engine option[value="nvidia"] { display: none; }
            #aseo-panel-api .aseo-field:has(#aseo_primary_keyword) { display: none; }
            #aseo-panel-seo .aseo-section:has(#aseo_keywords) { display: none; }
            #aseo-panel-api.aseo-global-panel { display: block !important; }
            #aseo-panel-api.aseo-global-panel > .aseo-section:not(.aseo-master-section) { display: none; }
            #aseo-panel-api.aseo-global-panel.show-technical > .aseo-section:not(.aseo-master-section) { display: block; }

            /* ========== ACTION BAR ========== */
            .aseo-action-bar {
                background: linear-gradient(135deg, #f8faf9 0%, #f0f4f1 100%);
                border: 1px solid #e2e5e9; border-radius: 14px; padding: 24px;
                margin-top: 28px; display: flex; flex-wrap: wrap; align-items: center; gap: 14px;
            }
            .aseo-action-bar h3 { width: 100%; margin: 0 0 4px; font-size: 1rem; color: #1e293b; font-weight: 800; display: flex; align-items: center; gap: 8px; }
            .aseo-action-bar form { margin: 0; }
            .aseo-action-bar .aseo-import-form { display:flex; flex:1 1 100%; align-items:center; gap:10px; }
            .aseo-import-form input[type="url"] { flex:1; min-height:50px; border:1.5px solid #d1d5db; border-radius:10px; padding:0 14px; font-size:.9rem; }
            .aseo-action-bar .aseo-run-form { display: flex; flex: 1 1 390px; align-items: stretch; gap: 10px; }
            .aseo-action-bar .aseo-btn { min-height: 52px; padding: 13px 20px; }
            .aseo-action-bar .aseo-btn-run { flex: 1; min-width: 220px; font-size: .94rem; }

            .aseo-btn {
                display: inline-flex; align-items: center; justify-content: center; gap: 8px;
                padding: 12px 22px; font-size: 0.88rem; font-weight: 700; border-radius: 10px;
                cursor: pointer; transition: all 0.25s ease; border: none; text-decoration: none;
            }
            .aseo-btn-save {
                background: linear-gradient(135deg, #0a1f14, #1a5c3a); color: #fff;
                box-shadow: 0 2px 8px rgba(10,31,20,0.2);
            }
            .aseo-btn-save:hover { transform: translateY(-1px); box-shadow: 0 4px 16px rgba(10,31,20,0.3); color: #fff; }
            .aseo-btn-test {
                background: #fff; color: #374151; border: 1.5px solid #d1d5db;
            }
            .aseo-btn-test:hover { border-color: #9ca3af; background: #f9fafb; color: #1e293b; }
            .aseo-btn-run {
                background: linear-gradient(135deg, #C5A85C, #d4b96e); color: #0a1f14;
                box-shadow: 0 2px 8px rgba(197,168,92,0.3);
            }
            .aseo-btn-run:hover { transform: translateY(-1px); box-shadow: 0 4px 16px rgba(197,168,92,0.4); color: #0a1f14; }

            .aseo-post-count {
                display: flex; align-items: center; background: #fff; border: 1.5px solid #C5A85C; border-radius: 10px;
                padding: 4px 10px 4px 14px; gap: 10px; min-height: 50px;
            }
            .aseo-post-count label { display:flex; align-items:center; gap:8px; font-weight: 700; color: #0a1f14; font-size: 0.84rem; white-space: nowrap; margin: 0; }
            .aseo-post-count select { min-width:64px; min-height:38px; border: 1px solid #e2d39f; border-radius:8px; padding: 0 8px; font-weight: 800; background: #fffdf6; cursor: pointer; font-size: 1rem; outline: none; box-shadow: none; }
            .aseo-publish-badge { display:inline-flex; align-items:center; min-height:44px; padding:0 12px; border-radius:9px; background:#eaf8ef; color:#16834b; font-size:.82rem; font-weight:800; white-space:nowrap; }

            /* ========== NOTICES ========== */
            .aseo-wrap .notice, .aseo-wrap .updated, .aseo-wrap .error { border-radius: 10px; margin: 0 0 16px; }
            .aseo-progress-card { display:none; margin:0 0 22px; padding:18px 20px; border:1px solid #cfe3d6; border-radius:14px; background:#f7fcf8; }
            .aseo-progress-card.visible { display:block; }
            .aseo-progress-head { display:flex; align-items:center; justify-content:space-between; gap:14px; margin-bottom:10px; }
            .aseo-progress-title { display:flex; align-items:center; gap:10px; color:#0a1f14; font-weight:800; }
            .aseo-spinner { width:18px; height:18px; border:3px solid #cfe3d6; border-top-color:#16834b; border-radius:50%; animation:aseoSpin .8s linear infinite; }
            .aseo-progress-card.complete .aseo-spinner { animation:none; border:0; width:20px; height:20px; }
            .aseo-progress-card.complete .aseo-spinner:after { content:'✓'; display:block; color:#16a34a; font-size:20px; line-height:20px; }
            .aseo-progress-card.failed { background:#fff7f7; border-color:#fecaca; }
            .aseo-progress-card.failed .aseo-spinner { animation:none; border-color:#dc2626; }
            .aseo-progress-count { font-weight:800; color:#1a5c3a; white-space:nowrap; }
            .aseo-progress-track { height:10px; overflow:hidden; border-radius:999px; background:#e5eee8; }
            .aseo-progress-fill { width:0; height:100%; border-radius:inherit; background:linear-gradient(90deg,#16834b,#49b675); transition:width .45s ease; }
            .aseo-progress-message { margin:10px 0 0; color:#64748b; font-size:.84rem; }
            @keyframes aseoSpin { to { transform:rotate(360deg); } }

            /* ========== RESPONSIVE ========== */
            @media (max-width: 782px) {
                .aseo-header { flex-direction: column; align-items: flex-start; gap: 12px; padding: 20px; }
                .aseo-stats { grid-template-columns: 1fr; }
                .aseo-tabs { flex-direction: column; }
                .aseo-main { padding: 0 16px 20px; }
                .aseo-action-bar { flex-direction: column; align-items: stretch; }
                .aseo-action-bar form, .aseo-action-bar .aseo-run-form, .aseo-action-bar .aseo-btn, .aseo-action-bar > a { width:100%; }
                .aseo-action-bar .aseo-run-form { flex: 0 0 auto; flex-direction:column; }
                .aseo-action-bar .aseo-import-form { flex-direction:column; align-items:stretch; }
                .aseo-import-form input[type="url"] { width:100%; }
                .aseo-post-count { width:auto; }
                .aseo-quick-create { padding: 18px; }
                .aseo-quick-head { flex-direction:column; gap:8px; }
                .aseo-create-form { grid-template-columns: 1fr; }
                .aseo-create-form .aseo-btn { width: 100%; }
            }

            /* ========== DASHICON STYLING ========== */
            .aseo-header-logo .dashicons { font-size: 24px; width: 24px; height: 24px; color: #C5A85C; }
            .aseo-stat-icon .dashicons { font-size: 22px; width: 22px; height: 22px; }
            .aseo-tab-btn .dashicons { font-size: 17px; width: 17px; height: 17px; vertical-align: text-bottom; }
            .sec-icon { display: inline-flex; align-items: center; justify-content: center; font-size: 0; }
            .sec-icon .dashicons { font-size: 18px; width: 18px; height: 18px; color: #1a5c3a; }
            .aseo-field label .field-icon { font-size: 0; opacity: 1; display: inline-flex; align-items: center; }
            .field-icon .dashicons { font-size: 15px; width: 15px; height: 15px; color: #94a3b8; }
            .aseo-btn .dashicons { font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom; }
            .aseo-action-bar h3 .dashicons { font-size: 18px; width: 18px; height: 18px; vertical-align: text-bottom; color: #C5A85C; }

            /* ========== POSTS TABLE ========== */
            .aseo-posts-list { margin-top: 28px; }
            .aseo-posts-table { width: 100%; border-collapse: separate; border-spacing: 0; }
            .aseo-posts-table th { text-align: left; padding: 10px 16px; font-size: 0.75rem; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e8ece9; background: #f8faf9; }
            .aseo-posts-table td { padding: 12px 16px; border-bottom: 1px solid #f0f2f4; vertical-align: middle; font-size: 0.88rem; }
            .aseo-posts-table tbody tr { transition: background 0.15s ease; }
            .aseo-posts-table tbody tr:hover { background: #f8faf9; }
            .aseo-post-thumb { width: 48px; height: 48px; border-radius: 8px; object-fit: cover; border: 1px solid #e8ece9; }
            .aseo-post-thumb-product { border-color: #c5a85c; box-shadow: 0 0 0 2px rgba(197,168,92,0.14); }
            .aseo-post-thumb-empty { width: 48px; height: 48px; border-radius: 8px; background: #f3f4f6; display: inline-flex; align-items: center; justify-content: center; color: #d1d5db; }
            .aseo-post-thumb-empty .dashicons { font-size: 20px; width: 20px; height: 20px; }
            .aseo-posts-table .post-title { font-weight: 600; color: #1e293b; text-decoration: none; }
            .aseo-posts-table .post-title:hover { color: #1a5c3a; }
            .aseo-posts-table .post-date { color: #94a3b8; font-size: 0.82rem; }
            .aseo-posts-table .post-link { color: #1a5c3a; font-weight: 600; font-size: 0.82rem; text-decoration: none; }
            .aseo-posts-table .post-link:hover { text-decoration: underline; }
            .aseo-posts-table .post-link .dashicons { font-size: 13px; width: 13px; height: 13px; vertical-align: text-bottom; }
            .aseo-posts-empty { text-align: center; padding: 40px 20px; color: #94a3b8; font-size: 0.88rem; }
            .aseo-posts-empty .dashicons { font-size: 36px; width: 36px; height: 36px; display: block; margin: 0 auto 12px; color: #d1d5db; }

            /* ========== VISUAL POLISH ========== */
            .aseo-wrap { color:#17251d; }
            .aseo-header { min-height:112px; padding:26px 30px; box-shadow:0 8px 24px rgba(10,31,20,.12); }
            .aseo-header h2 { letter-spacing:-.5px; }
            .aseo-main { padding-left:28px; padding-right:28px; }
            .aseo-stats { gap:12px; padding:20px 0; }
            .aseo-stat-card { min-height:86px; border-radius:14px; background:linear-gradient(180deg,#fff,#f8faf9); }
            .aseo-stat-card:hover { transform:none; box-shadow:0 8px 20px rgba(10,31,20,.07); }
            .aseo-quick-create { border:0; box-shadow:0 10px 26px rgba(10,31,20,.09); background:linear-gradient(135deg,#f5fbf7,#fffdf5); padding:24px; }
            /* Luồng tạo bài chính nằm ở khu vực Hành động nhanh bên dưới. */
            .aseo-quick-create { display:none; }
            .aseo-guide-step[href="#aseo-create-section"] { display:none; }
            .aseo-guide { grid-template-columns:repeat(2,1fr); }
            .aseo-quick-create h3 { font-size:1.18rem; }
            .aseo-quick-create p { font-size:.86rem; }
            .aseo-create-form select, .aseo-create-form .aseo-btn-run { min-height:50px; }
            .aseo-create-form select { border-color:#b9cfc1; box-shadow:0 1px 2px rgba(10,31,20,.04); }
            .aseo-btn-run { border-radius:10px; box-shadow:0 5px 12px rgba(10,31,20,.15); font-weight:800; }
            .aseo-tabs { margin-top:24px; border-radius:12px; background:#f4f7f5; padding:5px; gap:4px; }
            .aseo-tab-btn, .aseo-api-toggle { border-radius:9px; min-height:44px; }
            .aseo-tab-btn.active { box-shadow:0 2px 8px rgba(10,31,20,.09); }
            .aseo-section { border-radius:14px; box-shadow:0 3px 12px rgba(10,31,20,.04); overflow:hidden; }
            .aseo-section-header { padding:16px 20px; }
            .aseo-section-body { padding:20px; }
            .aseo-field input, .aseo-field textarea, .aseo-field select { border-radius:9px; border-color:#cbd8cf; }
            .aseo-field input:focus, .aseo-field textarea:focus, .aseo-field select:focus { border-color:#16834b; box-shadow:0 0 0 2px rgba(22,131,75,.13); }
            .aseo-progress-card { box-shadow:0 5px 16px rgba(10,31,20,.06); }
            #aseo-settings-form > div[style*="margin-top"] .aseo-btn-save { display:none; }
            @media (prefers-reduced-motion: reduce) { .aseo-wrap *, .aseo-wrap *::before, .aseo-wrap *::after { animation-duration:.001ms !important; transition-duration:.001ms !important; } }
        </style>

        <div class="wrap aseo-wrap">
            <!-- ===== HEADER ===== -->
            <div class="aseo-header">
                <div class="aseo-header-left">
                    <div class="aseo-header-logo">🚀</div>
                    <h2>Agent SEO<small>Hệ thống viết bài & sinh ảnh AI tự động</small></h2>
                </div>
                <div class="aseo-header-right">
                    <span class="aseo-status-dot">Đang hoạt động</span>
                    <span class="aseo-badge">v1.0</span>
                </div>
            </div>

            <div class="aseo-main">
                <!-- ===== STATS CARDS ===== -->
                <div class="aseo-stats">
                    <div class="aseo-stat-card">
                        <div class="aseo-stat-icon green">📝</div>
                        <div class="aseo-stat-info">
                            <div class="aseo-stat-value"><?php echo $count_generated; ?></div>
                            <div class="aseo-stat-label">Bài viết đã tạo</div>
                        </div>
                    </div>
                    <div class="aseo-stat-card">
                        <div class="aseo-stat-icon amber">🔑</div>
                        <div class="aseo-stat-info">
                            <div class="aseo-stat-value"><?php echo $kw_remaining; ?></div>
                            <div class="aseo-stat-label">Từ khóa đang chờ</div>
                        </div>
                    </div>
                    <div class="aseo-stat-card">
                        <div class="aseo-stat-icon blue">🖼️</div>
                        <div class="aseo-stat-info">
                            <div class="aseo-stat-value" style="font-size:1rem;"><?php echo esc_html($current_engine_label); ?></div>
                            <div class="aseo-stat-label">Bộ máy sinh ảnh</div>
                        </div>
                    </div>
                    <div class="aseo-stat-card">
                        <div class="aseo-stat-icon blue"><span class="dashicons dashicons-edit-page"></span></div>
                        <div class="aseo-stat-info">
                            <div class="aseo-stat-value"><?php echo esc_html($count_drafts); ?></div>
                            <div class="aseo-stat-label">Bản nháp cần duyệt</div>
                        </div>
                    </div>
                    <div class="aseo-stat-card">
                        <div class="aseo-stat-icon green"><span class="dashicons dashicons-yes-alt"></span></div>
                        <div class="aseo-stat-info">
                            <div class="aseo-stat-value"><?php echo esc_html($count_published); ?></div>
                            <div class="aseo-stat-label">Bài đã xuất bản</div>
                        </div>
                    </div>
                </div>

                <?php settings_errors('agent_seo_messages'); ?>
                <?php if (!empty($_GET['aseo_gsc_success'])) : ?>
                    <div class="notice notice-success"><p><?php echo esc_html(rawurldecode(wp_unslash($_GET['aseo_gsc_success']))); ?></p></div>
                <?php elseif (!empty($_GET['aseo_gsc_error'])) : ?>
                    <div class="notice notice-error"><p><?php echo esc_html(rawurldecode(wp_unslash($_GET['aseo_gsc_error']))); ?></p></div>
                <?php endif; ?>

                <?php
                $batch_total = intval(isset($batch_status['total']) ? $batch_status['total'] : 0);
                $batch_completed = intval(isset($batch_status['completed']) ? $batch_status['completed'] : 0);
                $batch_state = isset($batch_status['status']) ? $batch_status['status'] : 'idle';
                $batch_percent = $batch_total > 0 ? intval(round(($batch_completed / $batch_total) * 100)) : 0;
                if ($batch_state === 'images_pending') $batch_percent = 95;
                $batch_visible = in_array($batch_state, array('queued', 'running', 'waiting', 'images_pending', 'complete', 'failed'), true);
                ?>
                <div id="aseo-progress-card" class="aseo-progress-card <?php echo $batch_visible ? 'visible ' . esc_attr($batch_state) : ''; ?>">
                    <div class="aseo-progress-head">
                        <div class="aseo-progress-title"><span class="aseo-spinner"></span><span id="aseo-progress-label"><?php echo $batch_state === 'complete' ? 'Hoàn tất tạo bài' : 'AI đang xử lý bài viết'; ?></span></div>
                        <div id="aseo-progress-count" class="aseo-progress-count"><?php echo esc_html($batch_completed . '/' . $batch_total . ' bài'); ?></div>
                    </div>
                    <div class="aseo-progress-track"><div id="aseo-progress-fill" class="aseo-progress-fill" style="width:<?php echo esc_attr($batch_percent); ?>%;"></div></div>
                    <p id="aseo-progress-message" class="aseo-progress-message"><?php echo esc_html(isset($batch_status['message']) ? $batch_status['message'] : ''); ?></p>
                </div>

                <div class="aseo-guide" aria-label="Hướng dẫn tạo bài">
                    <a class="aseo-guide-step" href="#aseo-product-section">
                        <b class="aseo-guide-number">1</b><span><strong>Chọn sản phẩm & ảnh</strong><span>Chọn sản phẩm ở trên; ảnh Media là tùy chọn.</span></span>
                    </a>
                    <a class="aseo-guide-step" href="#aseo-seo-section">
                        <b class="aseo-guide-number">2</b><span><strong>Nhập từ khóa</strong><span>Nhập 1 từ khóa chính và tối đa 3 từ khóa phụ.</span></span>
                    </a>
                    <a class="aseo-guide-step" href="#aseo-create-section">
                        <b class="aseo-guide-number">3</b><span><strong>Bấm tạo bài</strong><span>AI tự viết, tạo ảnh và xuất bản theo cấu hình.</span></span>
                    </a>
                </div>

                <div class="aseo-quick-create" id="aseo-create-section">
                    <div class="aseo-quick-head">
                        <div>
                            <h3>⚡ Tạo bài viết mới</h3>
                            <p>Chọn sản phẩm và số lượng, AI sẽ tự viết, tạo ảnh và xuất bản.</p>
                        </div>
                        <span class="aseo-publish-inline">✓ Tự động xuất bản</span>
                    </div>
                    <form method="post" action="" class="aseo-create-form" onsubmit="return confirm('Bắt đầu tạo bài viết bằng AI?');">
                        <?php wp_nonce_field('aseo_action_nonce', 'aseo_nonce'); ?>
                        <input type="hidden" name="aseo_action" value="force_run">
                        <input type="hidden" class="aseo-primary-keyword-run" name="aseo_primary_keyword_run" value="">
                        <?php if (!empty($wc_products)) : ?>
                            <label class="aseo-create-control"><span>Sản phẩm tham chiếu</span>
                                <select id="aseo_target_product_run" name="aseo_target_product_run" class="aseo-product-run-select">
                                    <option value="">Viết bài chung</option>
                                    <?php foreach ($wc_products as $id => $title) : ?>
                                        <option value="<?php echo esc_attr($id); ?>" data-image="<?php echo esc_url(isset($wc_product_images[$id]) ? $wc_product_images[$id] : ''); ?>" <?php selected($target_product, $id); ?>><?php echo esc_html($title); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php else : ?>
                            <label class="aseo-create-control"><span>Sản phẩm tham chiếu</span><select disabled><option>Viết bài chung</option></select></label>
                        <?php endif; ?>
                        <label class="aseo-create-control"><span>Số bài</span>
                            <select name="aseo_num_posts"><option value="1">1 bài</option><option value="2">2 bài</option><option value="3">3 bài</option></select>
                        </label>
                        <button type="submit" class="aseo-btn aseo-btn-run">Tạo bài ngay <span aria-hidden="true">→</span></button>
                        <input type="hidden" name="aseo_post_status" value="publish">
                    </form>
                </div>

                <!-- ===== TAB NAVIGATION ===== -->
                <div class="aseo-advanced-hint">Bạn chỉ cần dùng 3 bước trên. Các mục bên dưới là thiết lập nâng cao, có thể để mặc định nếu chưa quen.</div>
                <div class="aseo-tabs">
                    <button type="button" class="aseo-tab-btn active" data-tab="aseo-panel-seo"><span class="tab-icon">✍️</span> Nội dung & từ khóa</button>
                    <button type="button" class="aseo-tab-btn" data-tab="aseo-panel-brand"><span class="tab-icon">🏢</span> Thông tin doanh nghiệp</button>
                    <button type="button" id="aseo-api-toggle" class="aseo-api-toggle" aria-expanded="false">⚙ Cài đặt kỹ thuật</button>
                </div>

                <!-- ===== FORM ===== -->
                <form method="post" action="options.php" id="aseo-settings-form">
                    <?php settings_fields('agent_seo_settings_group'); ?>

                    <!-- PANEL 1: API -->
                    <div id="aseo-panel-api" class="aseo-tab-panel aseo-global-panel">

                        <div class="aseo-section">
                            <div class="aseo-section-header">
                                <span class="sec-icon">🤖</span>
                                <h3>API Viết bài (Text Generation)</h3>
                                <p>Gemini 3.1 Flash Lite — Miễn phí</p>
                            </div>
                            <div class="aseo-section-body">
                                <div class="aseo-field">
                                    <label><span class="field-icon">🔑</span> Google Gemini API Key</label>
                                    <p class="desc">Lấy API Key miễn phí tại <a href="https://aistudio.google.com/apikey" target="_blank">Google AI Studio</a>.</p>
                                    <input type="text" id="aseo_gemini_api_key" name="aseo_gemini_api_key" value="<?php echo esc_attr($api_key); ?>" placeholder="AIzaSy...">
                                </div>
                            </div>
                        </div>

                        <div class="aseo-section">
                            <div class="aseo-section-header">
                                <span class="sec-icon">🖼️</span>
                                <h3>API Sinh ảnh (Image Generation)</h3>
                            </div>
                            <div class="aseo-section-body">
                                <div class="aseo-form-grid">
                                    <div class="aseo-field">
                                        <label><span class="field-icon">⚙️</span> Bộ máy sinh ảnh</label>
                                        <p class="desc">Chọn công nghệ để tự động sinh ảnh đại diện cho mỗi bài viết.</p>
                                        <select id="aseo_image_engine" name="aseo_image_engine">
                                            <option value="duky" <?php selected($image_engine, 'duky'); ?>>DukyAI ImageFX (Khuyên dùng)</option>
                                            <option value="nvidia" <?php selected($image_engine, 'nvidia'); ?>>NVIDIA FLUX.2 (Chất lượng cao)</option>
                                            <option value="imagen" <?php selected($image_engine, 'imagen'); ?>>Gemini Imagen 4 (Trả phí)</option>
                                        </select>
                                    </div>
                                    <div class="aseo-field">
                                        <label><span class="field-icon">🔑</span> DukyAI API Key</label>
                                        <p class="desc">API tạo ảnh trực tiếp, không cần chạy Google Flow bot hoặc Cloudflare Tunnel.</p>
                                        <input type="password" id="aseo_duky_api_key" name="aseo_duky_api_key" value="<?php echo esc_attr($duky_api_key); ?>" placeholder="Nhập DukyAI API Key">
                                    </div>
                                    <div class="aseo-field">
                                        <label><span class="field-icon">🧠</span> Model DukyAI</label>
                                        <p class="desc">GEM_PIX_2 cho chất lượng cao, NARWHAL nhanh hơn, R2I phù hợp Imagen 4.</p>
                                        <select id="aseo_duky_model" name="aseo_duky_model">
                                            <option value="GEM_PIX_2" <?php selected($duky_model, 'GEM_PIX_2'); ?>>GEM_PIX_2 — Nano Banana Pro (mặc định)</option>
                                            <option value="NARWHAL" <?php selected($duky_model, 'NARWHAL'); ?>>NARWHAL — Nano Banana 2</option>
                                            <option value="R2I" <?php selected($duky_model, 'R2I'); ?>>R2I — Imagen 4</option>
                                        </select>
                                    </div>
                                    <div class="aseo-field aseo-fg-full">
                                        <label><span class="field-icon">🔐</span> NVIDIA NIM API Key</label>
                                        <p class="desc">Chỉ cần nếu chọn bộ máy NVIDIA FLUX.2. Đăng ký tại <a href="https://build.nvidia.com" target="_blank">NVIDIA NIM</a>.</p>
                                        <input type="text" id="aseo_nvidia_api_key" name="aseo_nvidia_api_key" value="<?php echo esc_attr($nvidia_api_key); ?>" placeholder="nvapi-...">
                                    </div>
                                    <div class="aseo-field aseo-fg-full">
                                        <label><span class="field-icon">📡</span> IndexNow Key (tùy chọn)</label>
                                        <p class="desc">Sau khi xuất bản, plugin sẽ tự báo URL mới cho các công cụ hỗ trợ IndexNow. Không phải Google GSC.</p>
                                        <div style="display:flex; gap:10px; align-items:center;">
                                            <input type="text" id="aseo_indexnow_key" name="aseo_indexnow_key" value="<?php echo esc_attr($indexnow_key); ?>" placeholder="Nhấn Tạo khóa tự động" style="flex:1;">
                                            <button type="button" id="aseo-generate-indexnow-key" class="aseo-btn aseo-btn-test" style="white-space:nowrap;">Tạo khóa tự động</button>
                                        </div>
                                    </div>
                                    <div class="aseo-field aseo-fg-full aseo-gsc-box">
                                        <label><span class="field-icon">🔎</span> Kết nối Google Search Console</label>
                                        <p class="desc">Dùng OAuth để gửi sitemap sau khi xuất bản. Đây không phải Google Indexing API ép index bài blog. Hãy lưu Client ID/Secret trước khi bấm kết nối.</p>
                                        <div class="aseo-form-grid">
                                            <div class="aseo-field"><label>OAuth Client ID</label><input type="text" name="aseo_gsc_client_id" value="<?php echo esc_attr($gsc_client_id); ?>" placeholder="...apps.googleusercontent.com"></div>
                                            <div class="aseo-field"><label>OAuth Client Secret</label><input type="password" name="aseo_gsc_client_secret" value="<?php echo esc_attr($gsc_client_secret); ?>" placeholder="GOCSPX-..."></div>
                                            <div class="aseo-field"><label>Search Console property</label><input type="text" name="aseo_gsc_property" value="<?php echo esc_attr($gsc_property); ?>" placeholder="sc-domain:example.com hoặc https://example.com/"></div>
                                            <div class="aseo-field"><label>Sitemap URL</label><input type="url" name="aseo_gsc_sitemap_url" value="<?php echo esc_attr($gsc_sitemap_url); ?>" placeholder="https://example.com/sitemap_index.xml"></div>
                                        </div>
                                        <div class="aseo-gsc-actions">
                                            <span class="aseo-prompt-ready"><?php echo !empty($gsc_token['access_token']) ? '✓ Đã kết nối Google' : 'Chưa kết nối'; ?></span>
                                            <a class="aseo-btn aseo-btn-test" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=agent_seo_google_oauth_start'), 'agent_seo_google_oauth_start')); ?>">Kết nối với Google</a>
                                            <?php if (!empty($gsc_token['access_token'])) : ?>
                                                <a class="aseo-btn aseo-btn-test" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=agent_seo_google_gsc_test'), 'agent_seo_google_gsc_test')); ?>" style="margin-left: 10px; border-color: #0369a1; color: #0369a1;">Thử gửi sitemap</a>
                                            <?php endif; ?>
                                            <span class="desc">Redirect URI: <code><?php echo esc_html(Agent_SEO_GSC::redirect_uri()); ?></code></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="aseo-section aseo-master-section">
                            <div class="aseo-section-header">
                                <span class="sec-icon">🧩</span>
                                <h3>AI thiết lập phong cách</h3>
                                <?php if (!empty($master_prompt)) : ?><span class="aseo-prompt-ready">✓ Đã thiết lập</span><?php endif; ?>
                            </div>
                            <div class="aseo-section-body">
                                <div class="aseo-master-builder aseo-chat-builder">
                                    <!-- Khung hội thoại Chatbot -->
                                    <div class="aseo-chat-log">
                                        <!-- Tin nhắn chào mừng (AI) -->
                                        <div class="aseo-chat-message aseo-chat-message-ai">
                                            <div class="aseo-chat-avatar">AI</div>
                                            <div class="aseo-chat-bubble">
                                                <strong>Xin chào!</strong><br>
                                                Hãy nói cho tôi biết bạn muốn bài viết và hình ảnh có phong cách như thế nào. Bạn có thể viết tự nhiên, tôi sẽ chuyển thành cấu hình chuẩn.
                                            </div>
                                        </div>

                                        <!-- Tin nhắn yêu cầu từ người dùng (User) -->
                                        <?php if (!empty($master_prompt_brief)) : ?>
                                        <div class="aseo-chat-message aseo-chat-message-user">
                                            <div class="aseo-chat-avatar">TÔI</div>
                                            <div class="aseo-chat-bubble">
                                                <?php echo nl2br(esc_html($master_prompt_brief)); ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Tin nhắn phản hồi cấu hình đã tạo (AI) -->
                                        <?php if (!empty($master_prompt)) : ?>
                                        <div class="aseo-chat-message aseo-chat-message-ai">
                                            <div class="aseo-chat-avatar">AI</div>
                                            <div class="aseo-chat-bubble">
                                                <strong>Đây là cấu hình tôi đã tạo cho bạn:</strong>
                                                <div class="aseo-prompt-chat-message"><?php echo esc_html($master_prompt); ?></div>
                                                <div style="margin-top: 10px; font-size: 0.78rem; opacity: 0.9; color: #166534;">
                                                    ✓ Cấu hình đã được tự động lưu và áp dụng cho các bài viết tiếp theo.
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Khung nhập liệu ở dưới hội thoại -->
                                    <div class="aseo-field">
                                        <label for="aseo_master_prompt_brief">Bạn muốn AI viết và tạo ảnh như thế nào?</label>
                                        <p class="desc">Chỉ cần mô tả bằng lời bình thường. AI sẽ kết hợp với thông tin website, thương hiệu và sản phẩm để tạo cấu hình chuẩn.</p>
                                        <textarea id="aseo_master_prompt_brief" class="aseo-master-brief" name="aseo_master_prompt_brief" rows="4" placeholder="Ví dụ: Viết gần gũi, đáng tin, tập trung giải đáp nhu cầu khách hàng. Không nói quá. Ảnh đời thực đa dạng, ánh sáng ban ngày tự nhiên, không ám vàng và không phô sản phẩm quá mức."><?php echo esc_textarea($master_prompt_brief); ?></textarea>
                                    </div>
                                    <div class="aseo-master-presets" aria-label="Gợi ý nhanh">
                                        <span class="aseo-master-presets-label">Gợi ý nhanh:</span>
                                        <button type="button" class="aseo-master-preset" data-preset="Viết chuyên nghiệp, rõ ràng, đáng tin; tập trung giải đáp nhu cầu khách hàng và đưa ra thông tin thực tế. Ảnh đời thực đa dạng, ánh sáng tự nhiên trung tính, không ám vàng, không phóng đại sản phẩm.">Chuyên nghiệp & thực tế</button>
                                        <button type="button" class="aseo-master-preset" data-preset="Viết thân thiện, dễ hiểu, như đang tư vấn trực tiếp cho khách hàng. Ưu tiên ví dụ cụ thể, câu ngắn dễ đọc. Hình ảnh đời thực, nhiều góc máy, không tạo cảm giác ảnh AI.">Thân thiện & dễ hiểu</button>
                                        <button type="button" class="aseo-master-preset" data-preset="Tập trung chuyển đổi nhưng không nói quá: nêu lợi ích, cách lựa chọn, quy trình và lời kêu gọi hành động tự nhiên. Hình ảnh kể câu chuyện sử dụng, đóng gói, giao hàng hoặc dịch vụ với ánh sáng ban ngày.">Tập trung bán hàng</button>
                                    </div>
                                    <div class="aseo-master-actions">
                                        <button type="submit" form="aseo-master-suggest-form" id="aseo-generate-master-prompt" class="aseo-btn aseo-btn-save">✨ AI tạo cấu hình cho tôi</button>
                                        <span class="desc">AI sẽ tự lưu sau khi tạo xong.</span>
                                    </div>

                                    <input type="hidden" id="aseo_master_prompt" name="aseo_master_prompt" value="<?php echo esc_attr($master_prompt); ?>">
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- PANEL 2: BRAND -->
                    <div id="aseo-panel-brand" class="aseo-tab-panel">

                        <div class="aseo-section">
                            <div class="aseo-section-header">
                                <span class="sec-icon">🏢</span>
                                <h3>Thông tin doanh nghiệp</h3>
                                <p>Giúp AI lồng ghép tự nhiên vào bài viết</p>
                            </div>
                            <div class="aseo-section-body">
                                <div class="aseo-form-grid">
                                    <div class="aseo-field">
                                        <label><span class="field-icon">🏷️</span> Tên thương hiệu / Cửa hàng</label>
                                        <p class="desc">Bỏ trống nếu muốn AI viết bài khách quan chung.</p>
                                        <input type="text" id="aseo_brand_name" name="aseo_brand_name" value="<?php echo esc_attr($brand_name); ?>" placeholder="Đại lý Gạo Hoàng Gia Cần Thơ">
                                    </div>
                                    <div class="aseo-field">
                                        <label><span class="field-icon">📍</span> Địa chỉ doanh nghiệp</label>
                                        <input type="text" id="aseo_brand_address" name="aseo_brand_address" value="<?php echo esc_attr($brand_address); ?>" placeholder="Đường 30/4, Q. Ninh Kiều, TP. Cần Thơ">
                                    </div>
                                    <div class="aseo-field">
                                        <label><span class="field-icon">📞</span> Hotline tư vấn</label>
                                        <input type="text" id="aseo_brand_phone" name="aseo_brand_phone" value="<?php echo esc_attr($brand_phone); ?>" placeholder="0939.863.388">
                                    </div>
                                    <div class="aseo-field">
                                        <label><span class="field-icon">👤</span> Người phụ trách (CSKH)</label>
                                        <input type="text" id="aseo_brand_contact" name="aseo_brand_contact" value="<?php echo esc_attr($brand_contact); ?>" placeholder="Vy Hoàng">
                                    </div>
                                    <div class="aseo-field aseo-fg-full">
                                        <label><span class="field-icon">💰</span> Thông tin sản phẩm & Giá tham khảo</label>
                                        <input type="text" id="aseo_brand_price" name="aseo_brand_price" value="<?php echo esc_attr($brand_price); ?>" placeholder="Gạo ST25 5kg giá 32.000đ-36.000đ/kg; chính sách sỉ...">
                                    </div>
                                    <div class="aseo-field aseo-fg-full">
                                        <label><span class="field-icon">📣</span> Lời kêu gọi hành động (CTA)</label>
                                        <input type="text" id="aseo_brand_cta" name="aseo_brand_cta" value="<?php echo esc_attr($brand_cta); ?>" placeholder="Liên hệ Zalo 0939.863.388 để nhận báo giá sỉ tốt nhất">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- PANEL 3: SEO -->
                    <div id="aseo-panel-seo" class="aseo-tab-panel active">
                        <div class="aseo-section aseo-product-main" id="aseo-product-section">
                            <div class="aseo-section-header">
                                <span class="sec-icon">🛒</span>
                                <h3>1. Chọn sản phẩm cần viết</h3>
                            </div>
                            <div class="aseo-section-body">
                                <div class="aseo-product-main-row">
                                    <div id="aseo-product-image-skeleton" class="aseo-product-image-skeleton" aria-hidden="true"></div>
                                    <img id="aseo-product-main-thumb" src="<?php echo esc_url($target_product_image); ?>" alt="Ảnh sản phẩm đang chọn" class="aseo-product-main-thumb" <?php echo empty($target_product_image) ? 'style="display:none;"' : ''; ?>>
                                    <div class="aseo-field">
                                        <label for="aseo_target_product">Sản phẩm tham chiếu</label>
                                        <p class="desc">AI sẽ lấy đúng thông tin và ảnh của sản phẩm này để viết bài. Chọn “Viết bài chung” nếu bài không nhắm một sản phẩm cụ thể.</p>
                                        <select id="aseo_target_product" name="aseo_target_product">
                                            <option value="">— Viết bài chung, không chọn sản phẩm —</option>
                                            <?php foreach ($wc_products as $id => $title) : ?>
                                                <option value="<?php echo esc_attr($id); ?>" data-image="<?php echo esc_url(isset($wc_product_images[$id]) ? $wc_product_images[$id] : ''); ?>" <?php selected($target_product, $id); ?>><?php echo esc_html($title); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span id="aseo-product-save-status" class="aseo-product-save-status" aria-live="polite"></span>
                                        <?php if (empty($wc_products)) : ?><p class="desc">Chưa tìm thấy sản phẩm WooCommerce đang xuất bản.</p><?php endif; ?>
                                        <div class="aseo-reference-image-box">
                                            <label>Ảnh tham chiếu từ Thư viện Media <span class="desc-inline">(tùy chọn, được ưu tiên khi tạo ảnh AI)</span></label>
                                            <input type="hidden" id="aseo_reference_image_id" name="aseo_reference_image_id" value="<?php echo esc_attr($reference_image_id); ?>">
                                            <div class="aseo-reference-image-row">
                                                <img id="aseo-reference-image-preview" src="<?php echo esc_url($reference_image_url); ?>" alt="Ảnh tham chiếu" <?php echo empty($reference_image_url) ? 'style="display:none;"' : ''; ?>>
                                                <button type="button" class="button" id="aseo-select-reference-image">Chọn ảnh từ thư viện</button>
                                                <button type="button" class="button" id="aseo-clear-reference-image" <?php echo empty($reference_image_id) ? 'style="display:none;"' : ''; ?>>Bỏ ảnh</button>
                                            </div>
                                            <p class="desc">Dùng ảnh này làm mẫu nhận diện sản phẩm. Bỏ trống để dùng ảnh đại diện của sản phẩm đã chọn.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="aseo-section" id="aseo-seo-section">
                            <div class="aseo-section-header">
                                <span class="sec-icon">🎯</span>
                                <h3>2. SEO & Từ khóa</h3>
                            </div>
                            <div class="aseo-section-body">
                                <div class="aseo-form-grid">
                                    <div class="aseo-field aseo-fg-full">
                                        <label><span class="field-icon">🎯</span> Từ khóa chính cố định cho toàn bộ cụm bài</label>
                                        <p class="desc">Mọi bài trong cụm sẽ cùng nhắm từ khóa này. AI và sản phẩm được chọn không thể tự thay đổi giá trị này.</p>
                                        <input type="text" id="aseo_primary_keyword_seo" name="aseo_primary_keyword" value="<?php echo esc_attr($primary_keyword); ?>" placeholder="Ví dụ: mua gạo Cần Thơ">
                                    </div>
                                    <div class="aseo-field aseo-fg-full">
                                        <label><span class="field-icon">🔗</span> Từ khóa phụ dùng chung cho tất cả bài</label>
                                        <p class="desc">Nhập tối đa 3 từ khóa, mỗi từ khóa một dòng. Hệ thống ưu tiên 3 dòng đầu và vẫn giữ một từ khóa chính duy nhất.</p>
                                        <textarea id="aseo_secondary_keywords" name="aseo_secondary_keywords" rows="6" placeholder="giá gạo ST25 Cần Thơ&#10;đại lý gạo Cần Thơ&#10;gạo ST25 chính hãng&#10;mua gạo online"><?php echo esc_textarea($secondary_keywords); ?></textarea>
                                    </div>
                                    <div class="aseo-field">
                                        <label><span class="field-icon">🌾</span> Chủ đề trang web (Niche)</label>
                                        <p class="desc">Giúp AI hiểu sâu chuyên môn cần viết.</p>
                                        <select id="aseo_niche_select" aria-label="Chọn chủ đề website">
                                            <option value="">Chọn chủ đề website…</option>
                                            <option value="Thương mại điện tử và bán lẻ">Bán lẻ / E-commerce</option>
                                            <option value="Dịch vụ doanh nghiệp B2B">Dịch vụ B2B</option>
                                            <option value="Sản xuất và phân phối">Sản xuất & phân phối</option>
                                            <option value="Du lịch, lưu trú và trải nghiệm">Du lịch & lưu trú</option>
                                            <option value="Giáo dục và đào tạo">Giáo dục & đào tạo</option>
                                            <option value="custom">Tùy chỉnh…</option>
                                        </select>
                                        <input type="text" id="aseo_niche" name="aseo_niche" value="<?php echo esc_attr($niche); ?>" placeholder="Nhập chủ đề website của bạn…">
                                        <div class="aseo-suggestion-chips" data-target="aseo_niche">
                                            <button type="button" data-value="Thương mại điện tử và bán lẻ">Bán lẻ / E-commerce</button>
                                            <button type="button" data-value="Dịch vụ doanh nghiệp B2B">Dịch vụ B2B</button>
                                            <button type="button" data-value="Sản xuất và phân phối">Sản xuất</button>
                                            <button type="button" data-value="Du lịch, lưu trú và trải nghiệm">Du lịch</button>
                                            <button type="button" data-value="Giáo dục và đào tạo">Giáo dục</button>
                                        </div>
                                    </div>
                                    <div class="aseo-field">
                                        <label><span class="field-icon">🎙️</span> Tông giọng bài viết (Brand Voice)</label>
                                        <p class="desc">Ví dụ: Chuyên nghiệp, ấm áp, dân dã miền Tây...</p>
                                        <select id="aseo_brand_voice_select" aria-label="Chọn tông giọng bài viết">
                                            <option value="">Chọn tông giọng…</option>
                                            <option value="Chuyên nghiệp, rõ ràng, đáng tin cậy">Chuyên nghiệp</option>
                                            <option value="Thân thiện, gần gũi, dễ hiểu">Thân thiện</option>
                                            <option value="Ngắn gọn, trực tiếp, tập trung chuyển đổi">Tập trung bán hàng</option>
                                            <option value="Chuyên gia, phân tích sâu, có dẫn chứng">Chuyên gia</option>
                                            <option value="Trẻ trung, năng động, hiện đại">Trẻ trung</option>
                                            <option value="custom">Tùy chỉnh…</option>
                                        </select>
                                        <input type="text" id="aseo_brand_voice" name="aseo_brand_voice" value="<?php echo esc_attr($brand_voice); ?>" placeholder="Nhập tông giọng bạn muốn…">
                                        <div class="aseo-suggestion-chips" data-target="aseo_brand_voice">
                                            <button type="button" data-value="Chuyên nghiệp, rõ ràng, đáng tin cậy">Chuyên nghiệp</button>
                                            <button type="button" data-value="Thân thiện, gần gũi, dễ hiểu">Thân thiện</button>
                                            <button type="button" data-value="Ngắn gọn, trực tiếp, tập trung chuyển đổi">Tập trung bán hàng</button>
                                            <button type="button" data-value="Chuyên gia, phân tích sâu, có dẫn chứng">Chuyên gia</button>
                                            <button type="button" data-value="Trẻ trung, năng động, hiện đại">Trẻ trung</button>
                                        </div>
                                    </div>
                                    <div class="aseo-field aseo-fg-full">
                                        <label><span class="field-icon">⏰</span> Lịch chạy ngầm tự động</label>
                                        <p class="desc">Tần suất hệ thống kiểm tra và tự viết bài mới.</p>
                                        <select id="aseo_cron_interval" name="aseo_cron_interval">
                                            <option value="hourly" <?php selected($cron_interval, 'hourly'); ?>>Mỗi giờ (Testing)</option>
                                            <option value="twicedaily" <?php selected($cron_interval, 'twicedaily'); ?>>Hai lần mỗi ngày</option>
                                            <option value="daily" <?php selected($cron_interval, 'daily'); ?>>Mỗi ngày một lần (Khuyên dùng)</option>
                                            <option value="weekly" <?php selected($cron_interval, 'weekly'); ?>>Mỗi tuần một lần</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="aseo-section">
                            <div class="aseo-section-header">
                                <span class="sec-icon">🔑</span>
                                <h3>Danh sách từ khóa mục tiêu</h3>
                                <p><?php echo $kw_remaining; ?> từ khóa đang chờ viết</p>
                            </div>
                            <div class="aseo-section-body">
                                <div class="aseo-field">
                                    <p class="desc">Mỗi dòng = 1 từ khóa. Hệ thống viết lần lượt từ trên xuống, sau đó tự đánh dấu [Đã viết].</p>
                                    <textarea id="aseo_keywords" name="aseo_keywords" rows="10" placeholder="mua gao st25 can tho&#10;dai ly gao can tho uy tin&#10;cach nau gao st25 ngon nhat"><?php echo esc_textarea($keywords); ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ===== SAVE BUTTON (inside form) ===== -->
                    <div style="margin-top: 20px;">
                        <button type="submit" class="aseo-btn aseo-btn-save">💾 Lưu cấu hình</button>
                        <span id="aseo-autosave-status" class="aseo-autosave-status" aria-live="polite">Các trường SEO sẽ tự lưu khi bạn thay đổi.</span>
                    </div>
                </form>

                <!-- ===== ACTION BAR (outside form) ===== -->
                <div class="aseo-action-bar">
                    <h3>⚡ Hành động nhanh</h3>

                    <form method="post" action="" class="aseo-import-form">
                        <?php wp_nonce_field('aseo_action_nonce', 'aseo_nonce'); ?>
                        <input type="hidden" name="aseo_action" value="import_website_profile">
                        <input type="url" name="aseo_source_website_temp" value="<?php echo esc_attr($source_website); ?>" placeholder="https://tenmien.com/" required>
                        <button type="submit" class="aseo-btn aseo-btn-test">🌐 Lấy thông tin website</button>
                    </form>

                    <form id="aseo-master-suggest-form" method="post" action="" style="display:none;">
                        <?php wp_nonce_field('aseo_action_nonce', 'aseo_nonce'); ?>
                        <input type="hidden" name="aseo_action" value="suggest_master_prompts">
                        <input type="hidden" id="aseo_master_prompt_brief_temp" name="aseo_master_prompt_brief_temp" value="">
                        <input type="hidden" id="aseo_niche_temp" name="aseo_niche_temp" value="">
                        <input type="hidden" id="aseo_brand_name_temp" name="aseo_brand_name_temp" value="">
                        <input type="hidden" id="aseo_product_info_temp" name="aseo_product_info_temp" value="">
                        <input type="hidden" id="aseo_target_product_temp" name="aseo_target_product_temp" value="">
                    </form>

                    <!-- Test Connection -->
                    <form method="post" action="" style="margin:0; display:inline-flex;">
                        <?php wp_nonce_field('aseo_action_nonce', 'aseo_nonce'); ?>
                        <input type="hidden" name="aseo_action" value="test_connection">
                        <input type="hidden" id="aseo_gemini_api_key_temp" name="aseo_gemini_api_key_temp" value="">
                        <input type="hidden" id="aseo_nvidia_api_key_temp" name="aseo_nvidia_api_key_temp" value="">
                        <input type="hidden" id="aseo_duky_api_key_temp" name="aseo_duky_api_key_temp" value="">
                        <input type="hidden" id="aseo_duky_model_temp" name="aseo_duky_model_temp" value="">
                        <input type="hidden" id="aseo_image_engine_temp" name="aseo_image_engine_temp" value="">
                        <button type="submit" class="aseo-btn aseo-btn-test" onclick="document.getElementById('aseo_gemini_api_key_temp').value=document.getElementById('aseo_gemini_api_key').value;document.getElementById('aseo_nvidia_api_key_temp').value=document.getElementById('aseo_nvidia_api_key').value;document.getElementById('aseo_duky_api_key_temp').value=document.getElementById('aseo_duky_api_key').value;document.getElementById('aseo_duky_model_temp').value=document.getElementById('aseo_duky_model').value;document.getElementById('aseo_image_engine_temp').value=document.getElementById('aseo_image_engine').value;">
                            🔌 Kiểm tra kết nối
                        </button>
                    </form>

                    <!-- Force Run -->
                    <form method="post" action="" class="aseo-run-form" onsubmit="return confirm('Hệ thống sẽ tạo đúng số bài đã chọn. Bạn có muốn bắt đầu?');">
                        <?php wp_nonce_field('aseo_action_nonce', 'aseo_nonce'); ?>
                        <input type="hidden" name="aseo_action" value="force_run">
                        <input type="hidden" class="aseo-primary-keyword-run" name="aseo_primary_keyword_run" value="">
                        <div class="aseo-post-count">
                            <label for="aseo_num_posts">Số bài cần tạo</label>
                            <select id="aseo_num_posts" name="aseo_num_posts">
                                <option value="1">1</option>
                                <option value="2">2</option>
                                <option value="3">3</option>
                                <option value="5">5</option>
                                <option value="10">10</option>
                            </select>
                        </div>
                        <button type="submit" class="aseo-btn aseo-btn-run">✍️ Bắt đầu tạo bài</button>
                    </form>

                    <?php if ($count_generated > 0) : ?>
                    <a href="<?php echo admin_url('edit.php?post_status=publish&post_type=post'); ?>" class="aseo-btn aseo-btn-test" style="text-decoration:none;">
                        📄 Xem danh sách bài (<?php echo $count_generated; ?>)
                    </a>
                    <?php endif; ?>
                </div>

                <!-- ===== BÀI VIẾT ĐÃ ĐĂNG ===== -->
                <div class="aseo-posts-list">
                    <div class="aseo-section">
                        <div class="aseo-section-header">
                            <span class="sec-icon"><span class="dashicons dashicons-list-view"></span></span>
                            <h3>Bài viết đã đăng gần đây</h3>
                            <p><?php echo $count_generated; ?> bài tổng cộng</p>
                        </div>
                        <div class="aseo-section-body" style="padding: 0;">
                            <?php if (!empty($recent_ai_posts)) : ?>
                            <table class="aseo-posts-table">
                                <thead>
                                    <tr>
                                        <th style="width:64px;">Ảnh</th>
                                        <th>Tiêu đề</th>
                                        <th style="width:130px;">Ngày đăng</th>
                                        <th style="width:160px;">Trạng thái Index</th>
                                        <th style="width:80px;"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_ai_posts as $ai_post) : ?>
                                    <tr>
                                        <td>
                                            <?php if (has_post_thumbnail($ai_post->ID)) : ?>
                                                <img src="<?php echo esc_url(get_the_post_thumbnail_url($ai_post->ID, 'thumbnail')); ?>" class="aseo-post-thumb" alt="">
                                            <?php else : ?>
                                                <div class="aseo-post-thumb-empty"><span class="dashicons dashicons-format-image"></span></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><a href="<?php echo esc_url(get_edit_post_link($ai_post->ID)); ?>" class="post-title"><?php echo esc_html($ai_post->post_title); ?></a></td>
                                        <td class="post-date"><?php echo get_the_date('d/m/Y H:i', $ai_post); ?></td>
                                        <td>
                                            <?php
                                            $indexnow_sent = get_post_meta($ai_post->ID, '_agent_seo_indexnow_sent_at', true);
                                            $gsc_connected = class_exists('Agent_SEO_GSC') && !empty(Agent_SEO_GSC::access_token());
                                            $gsc_property = get_option('aseo_gsc_property', '');

                                            if ($gsc_connected && !empty($gsc_property)) {
                                                echo '<span class="aseo-badge-gsc-sent" style="color:#0369a1; font-weight:600; font-size:0.78rem; display:block; margin-bottom:4px;">✓ GSC: Đã gửi sitemap</span>';
                                            } else {
                                                echo '<span class="aseo-badge-gsc-unsent" style="color:#94a3b8; font-size:0.78rem; display:block; margin-bottom:4px;">GSC: Chưa kết nối</span>';
                                            }

                                            if ($indexnow_sent) {
                                                echo '<span class="aseo-badge-in-sent" style="color:#166534; font-weight:600; font-size:0.78rem; display:block;">✓ IndexNow: Đã gửi</span>';
                                            } else {
                                                echo '<span class="aseo-badge-in-unsent" style="color:#ef4444; font-size:0.78rem; display:block;">IndexNow: Chưa gửi</span>';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <button type="button" class="button aseo-retry-image" data-post-id="<?php echo esc_attr($ai_post->ID); ?>" style="font-size:0.75rem; height:26px; line-height:24px; padding:0 8px; margin-right:4px; border-color:#0369a1; color:#0369a1;">Tạo lại ảnh AI</button>
                                            <a href="<?php echo esc_url(get_permalink($ai_post->ID)); ?>" target="_blank" class="post-link">Xem <span class="dashicons dashicons-external"></span></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php else : ?>
                            <div class="aseo-posts-empty">
                                <span class="dashicons dashicons-edit-page"></span>
                                Chưa có bài viết nào. Nhấn "Viết & Đăng bài ngay" để bắt đầu!
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function(){
            document.querySelectorAll('.aseo-retry-image').forEach(function(button) {
                button.addEventListener('click', function() {
                    var postId = button.getAttribute('data-post-id');
                    if (!postId) return;
                    button.disabled = true;
                    button.textContent = '🎨 Đang vẽ ảnh AI...';
                    var body = new URLSearchParams();
                    body.append('action', 'agent_seo_retry_image');
                    body.append('nonce', '<?php echo esc_js(wp_create_nonce('agent_seo_retry_image')); ?>');
                    body.append('post_id', postId);
                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body: body.toString()})
                        .then(function(response) { return response.json(); })
                        .then(function(payload) {
                            if (payload.success) {
                                button.textContent = '✓ Đã tạo xong';
                                button.style.color = '#166534';
                                button.style.borderColor = '#166534';
                                var row = button.closest('tr');
                                if (row && payload.data && payload.data.thumb_url) {
                                    var imgTd = row.querySelector('td:first-child');
                                    if (imgTd) {
                                        imgTd.innerHTML = '<img src="' + payload.data.thumb_url + '" class="aseo-post-thumb" alt="">';
                                    }
                                }
                            } else {
                                alert(payload.data && payload.data.message ? payload.data.message : 'Tạo ảnh thất bại');
                                button.textContent = 'Tạo lại ảnh AI';
                                button.disabled = false;
                            }
                        })
                        .catch(function() {
                            alert('Lỗi kết nối khi gửi yêu cầu vẽ ảnh.');
                            button.textContent = 'Tạo lại ảnh AI';
                            button.disabled = false;
                        });
                });
            });
            var masterPrompt = document.getElementById('aseo_master_prompt');
            var masterCount = document.getElementById('aseo-master-count');
            function updateMasterCount() {
                if (!masterPrompt || !masterCount) return;
                var length = masterPrompt.value.trim().length;
                masterCount.textContent = length.toLocaleString('vi-VN') + ' ký tự';
            }
            if (masterPrompt) {
                masterPrompt.addEventListener('input', updateMasterCount);
                updateMasterCount();
            }
            var masterResult = document.querySelector('.aseo-chat-builder .aseo-master-result');
            var promptChatMessage = masterResult ? masterResult.querySelector('.aseo-prompt-chat-message:not(.is-empty)') : null;
            var masterActions = document.querySelector('.aseo-chat-builder .aseo-master-actions');
            if (masterResult && promptChatMessage && masterActions) {
                var aiTurn = document.createElement('div');
                aiTurn.className = 'aseo-chat-ai-turn';
                aiTurn.innerHTML = '<div class="aseo-chat-avatar">AI</div><div class="aseo-chat-ai-answer"><strong>Đây là cấu hình tôi đã tạo cho bạn:</strong><div class="aseo-chat-ai-answer-content">' + promptChatMessage.innerHTML + '</div></div>';
                masterActions.insertAdjacentElement('afterend', aiTurn);
                masterResult.style.display = 'none';
            }
            document.querySelectorAll('.aseo-master-preset').forEach(function(preset) {
                preset.addEventListener('click', function() {
                    var brief = document.getElementById('aseo_master_prompt_brief');
                    if (!brief) return;
                    brief.value = preset.getAttribute('data-preset') || '';
                    brief.dispatchEvent(new Event('input', {bubbles:true}));
                    brief.focus();
                });
            });

            var generateMasterButton = document.getElementById('aseo-generate-master-prompt');
            var generateMasterForm = document.getElementById('aseo-master-suggest-form');
            if (generateMasterButton && !generateMasterButton.disabled) generateMasterButton.innerHTML = 'Gửi cho AI <span aria-hidden="true">➤</span>';
            if (generateMasterButton) {
                generateMasterButton.addEventListener('click', function() {
                    var mappings = {
                        'aseo_master_prompt_brief_temp': 'aseo_master_prompt_brief',
                        'aseo_niche_temp': 'aseo_niche',
                        'aseo_brand_name_temp': 'aseo_brand_name',
                        'aseo_product_info_temp': 'aseo_brand_price',
                        'aseo_target_product_temp': 'aseo_target_product'
                    };
                    Object.keys(mappings).forEach(function(targetId) {
                        var target = document.getElementById(targetId);
                        var source = document.getElementById(mappings[targetId]);
                        if (target && source) target.value = source.value;
                    });
                });
            }
            if (generateMasterForm && generateMasterButton) {
                generateMasterForm.addEventListener('submit', function() {
                    generateMasterButton.disabled = true;
                    generateMasterButton.textContent = 'AI đang tạo cấu hình...';
                });
            }

            var indexNowButton = document.getElementById('aseo-generate-indexnow-key');
            if (indexNowButton) {
                indexNowButton.addEventListener('click', function() {
                    var bytes = new Uint8Array(16);
                    if (window.crypto && window.crypto.getRandomValues) {
                        window.crypto.getRandomValues(bytes);
                    } else {
                        for (var i = 0; i < bytes.length; i++) bytes[i] = Math.floor(Math.random() * 256);
                    }
                    var key = Array.prototype.map.call(bytes, function(b) {
                        return ('0' + b.toString(16)).slice(-2);
                    }).join('');
                    document.getElementById('aseo_indexnow_key').value = key;
                });
            }

            var progressCard = document.getElementById('aseo-progress-card');
            var progressFill = document.getElementById('aseo-progress-fill');
            var progressCount = document.getElementById('aseo-progress-count');
            var progressMessage = document.getElementById('aseo-progress-message');
            var progressLabel = document.getElementById('aseo-progress-label');
            var progressTimer = null;

            function renderBatchProgress(batch) {
                if (!progressCard || !batch || batch.status === 'idle') return;
                progressCard.className = 'aseo-progress-card visible ' + batch.status;
                progressFill.style.width = (batch.percent || 0) + '%';
                progressCount.textContent = (batch.completed || 0) + '/' + (batch.total || 0) + ' bài';
                progressMessage.textContent = batch.message || '';
                if (batch.status === 'complete') {
                    progressLabel.textContent = 'Hoàn tất tạo bài';
                } else if (batch.status === 'failed') {
                    progressLabel.textContent = 'Tạo bài gặp lỗi';
                } else if (batch.status === 'images_pending') {
                    progressLabel.textContent = 'Đang hoàn thiện ảnh đại diện';
                } else {
                    progressLabel.textContent = 'AI đang xử lý bài viết';
                }
            }

            function pollBatchProgress() {
                var body = new URLSearchParams();
                body.append('action', 'agent_seo_batch_status');
                body.append('nonce', '<?php echo esc_js(wp_create_nonce('agent_seo_batch_status')); ?>');
                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: body.toString()
                }).then(function(response) { return response.json(); }).then(function(payload) {
                    if (!payload.success) return;
                    renderBatchProgress(payload.data);
                    if (payload.data.status === 'complete' || payload.data.status === 'failed' || payload.data.status === 'idle') {
                        if (progressTimer) window.clearInterval(progressTimer);
                    }
                }).catch(function() {});
            }

            if (progressCard && (progressCard.classList.contains('queued') || progressCard.classList.contains('running') || progressCard.classList.contains('waiting') || progressCard.classList.contains('images_pending'))) {
                pollBatchProgress();
                progressTimer = window.setInterval(pollBatchProgress, 5000);
            }

            // Tab switching
            var btns = document.querySelectorAll('.aseo-tab-btn');
            var panels = document.querySelectorAll('.aseo-tab-panel:not(.aseo-global-panel)');
            btns.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    btns.forEach(function(b) { b.classList.remove('active'); });
                    panels.forEach(function(p) { p.classList.remove('active'); });
                    btn.classList.add('active');
                    var target = document.getElementById(btn.getAttribute('data-tab'));
                    if (target) target.classList.add('active');
                });
            });

            var apiToggle = document.getElementById('aseo-api-toggle');
            var apiPanel = document.getElementById('aseo-panel-api');
            if (apiToggle && apiPanel) {
                apiToggle.addEventListener('click', function() {
                    var isOpen = apiPanel.classList.toggle('show-technical');
                    apiToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    apiToggle.textContent = isOpen ? '✕ Đóng cài đặt API' : '⚙ Cài đặt API';
                    if (isOpen) {
                        window.setTimeout(function() {
                            apiPanel.scrollIntoView({behavior: 'smooth', block: 'start'});
                        }, 50);
                    }
                });
            }

            var productSelect = document.getElementById('aseo_target_product');
            var quickProductSelect = document.getElementById('aseo_target_product_run');
            var productThumb = document.getElementById('aseo-product-main-thumb');
            var productImageSkeleton = document.getElementById('aseo-product-image-skeleton');
            var productSaveStatus = document.getElementById('aseo-product-save-status');
            function setProductImageState(hasImage) {
                if (productImageSkeleton) productImageSkeleton.classList.toggle('is-hidden', !!hasImage);
            }
            if (productThumb && productThumb.getAttribute('src')) setProductImageState(true);
            function saveProductSelection(select) {
                if (!select) return;
                if (productSaveStatus) productSaveStatus.textContent = 'Đang lưu lựa chọn...';
                var saveBody = new URLSearchParams();
                saveBody.append('action', 'agent_seo_save_product');
                saveBody.append('nonce', '<?php echo esc_js(wp_create_nonce('agent_seo_save_product')); ?>');
                saveBody.append('product_id', select.value || '0');
                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: saveBody.toString()
                }).then(function(response) { return response.json(); }).then(function(payload) {
                    if (productSaveStatus) productSaveStatus.textContent = payload.success ? '✓ Đã tự lưu sản phẩm' : 'Không thể tự lưu';
                }).catch(function() { if (productSaveStatus) productSaveStatus.textContent = 'Không thể tự lưu'; });
            }
            function updateProductPreview(select) {
                if (!select || !productThumb) return;
                if (referenceImageId && referenceImageId.value && referenceImageId.value !== '0') return;
                var option = select.options[select.selectedIndex];
                var image = option ? option.getAttribute('data-image') : '';
                if (image) {
                    productThumb.src = image;
                    productThumb.style.display = 'block';
                    setProductImageState(true);
                } else {
                    productThumb.removeAttribute('src');
                    productThumb.style.display = 'none';
                    setProductImageState(false);
                }
            }
            if (productSelect) {
                productSelect.addEventListener('change', function() {
                    updateProductPreview(productSelect);
                    if (quickProductSelect) quickProductSelect.value = productSelect.value;
                    saveProductSelection(productSelect);
                });
            }
            if (quickProductSelect) {
                quickProductSelect.addEventListener('change', function() {
                    updateProductPreview(quickProductSelect);
                    if (productSelect) productSelect.value = quickProductSelect.value;
                    saveProductSelection(quickProductSelect);
                });
            }

            var referenceImageButton = document.getElementById('aseo-select-reference-image');
            var clearReferenceImageButton = document.getElementById('aseo-clear-reference-image');
            var referenceImageId = document.getElementById('aseo_reference_image_id');
            var referenceImagePreview = document.getElementById('aseo-reference-image-preview');
            var referenceMediaFrame;
            if (referenceImageId && referenceImageId.value && referenceImageId.value !== '0' && referenceImagePreview && referenceImagePreview.src && productThumb) {
                productThumb.src = referenceImagePreview.src;
                productThumb.style.display = 'block';
                setProductImageState(true);
            }
            function saveReferenceImage(id) {
                if (!referenceImageId) return;
                var saveBody = new URLSearchParams();
                saveBody.append('action', 'agent_seo_autosave_setting');
                saveBody.append('nonce', '<?php echo esc_js(wp_create_nonce('agent_seo_autosave_setting')); ?>');
                saveBody.append('field', 'aseo_reference_image_id');
                saveBody.append('value', id || '0');
                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body: saveBody.toString()});
            }
            if (referenceImageButton) {
                referenceImageButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (!window.wp || typeof window.wp.media !== 'function') {
                        window.alert('Thư viện Media của WordPress chưa tải xong. Hãy tải lại trang rồi thử lại.');
                        return;
                    }
                    if (!referenceMediaFrame) {
                        referenceMediaFrame = window.wp.media({title: 'Chọn ảnh tham chiếu', button: {text: 'Dùng ảnh này'}, library: {type: 'image'}, multiple: false});
                        referenceMediaFrame.on('select', function() {
                            var attachment = referenceMediaFrame.state().get('selection').first().toJSON();
                            var imageUrl = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
                            referenceImageId.value = attachment.id || 0;
                            if (referenceImagePreview) { referenceImagePreview.src = imageUrl; referenceImagePreview.style.display = 'none'; }
                            if (productThumb) { productThumb.src = imageUrl; productThumb.style.display = 'block'; setProductImageState(true); }
                            if (clearReferenceImageButton) clearReferenceImageButton.style.display = 'inline-block';
                            saveReferenceImage(attachment.id || 0);
                        });
                    }
                    referenceMediaFrame.open();
                });
            }
            if (clearReferenceImageButton) {
                clearReferenceImageButton.addEventListener('click', function() {
                    referenceImageId.value = '0';
                    if (referenceImagePreview) { referenceImagePreview.removeAttribute('src'); referenceImagePreview.style.display = 'none'; }
                    clearReferenceImageButton.style.display = 'none';
                    updateProductPreview(productSelect);
                    saveReferenceImage(0);
                });
            }

            var primaryKeywordInput = document.getElementById('aseo_primary_keyword_seo');
            document.querySelectorAll('form input[name="aseo_action"][value="force_run"]').forEach(function(actionInput) {
                var runForm = actionInput.closest('form');
                if (!runForm) return;
                runForm.addEventListener('submit', function() {
                    var keywordBridge = runForm.querySelector('.aseo-primary-keyword-run');
                    if (keywordBridge && primaryKeywordInput) keywordBridge.value = primaryKeywordInput.value.trim();
                });
            });

            var autosaveStatus = document.getElementById('aseo-autosave-status');
            if (autosaveStatus) autosaveStatus.textContent = '✓ Thay đổi sẽ tự động được lưu';
            var settingsForm = document.getElementById('aseo-settings-form');
            if (settingsForm) settingsForm.addEventListener('submit', function(event) { event.preventDefault(); });
            var autosaveTimers = {};
            var autosaveFields = document.querySelectorAll('#aseo-settings-form input[name], #aseo-settings-form textarea[name], #aseo-settings-form select[name]');
            var autosaveNames = {
                'aseo_primary_keyword': true, 'aseo_secondary_keywords': true, 'aseo_keywords': true,
                'aseo_niche': true, 'aseo_brand_voice': true, 'aseo_master_prompt_brief': true,
                'aseo_master_prompt': true, 'aseo_cron_interval': true, 'aseo_brand_name': true,
                'aseo_brand_address': true, 'aseo_brand_phone': true, 'aseo_brand_contact': true,
                'aseo_brand_price': true, 'aseo_brand_cta': true, 'aseo_gsc_property': true,
                'aseo_gsc_sitemap_url': true, 'aseo_reference_image_id': true,
                'aseo_gemini_api_key': true, 'aseo_nvidia_api_key': true,
                'aseo_duky_api_key': true, 'aseo_duky_model': true,
                'aseo_image_engine': true, 'aseo_indexnow_key': true,
                'aseo_gsc_client_id': true, 'aseo_gsc_client_secret': true,
                'aseo_source_website': true, 'aseo_master_text_prompt': true,
                'aseo_master_image_prompt': true, 'aseo_post_status': true
            };
            function autosaveField(field) {
                if (!field || !autosaveNames[field.name]) return;
                if (autosaveTimers[field.name]) window.clearTimeout(autosaveTimers[field.name]);
                autosaveTimers[field.name] = window.setTimeout(function() {
                    if (autosaveStatus) { autosaveStatus.textContent = 'Đang tự lưu...'; autosaveStatus.className = 'aseo-autosave-status saving'; }
                    var saveBody = new URLSearchParams();
                    saveBody.append('action', 'agent_seo_autosave_setting');
                    saveBody.append('nonce', '<?php echo esc_js(wp_create_nonce('agent_seo_autosave_setting')); ?>');
                    saveBody.append('field', field.name);
                    saveBody.append('value', field.value);
                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST', credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: saveBody.toString()
                    }).then(function(response) { return response.json(); }).then(function(payload) {
                        if (autosaveStatus) { autosaveStatus.textContent = payload.success ? '✓ Đã tự lưu' : 'Không thể tự lưu'; autosaveStatus.className = 'aseo-autosave-status ' + (payload.success ? 'saved' : ''); }
                    }).catch(function() { if (autosaveStatus) { autosaveStatus.textContent = 'Không thể tự lưu'; autosaveStatus.className = 'aseo-autosave-status'; } });
                }, field.tagName === 'TEXTAREA' ? 900 : 500);
            }
            autosaveFields.forEach(function(field) {
                if (!autosaveNames[field.name]) return;
                field.addEventListener('change', function() { autosaveField(field); });
                if (field.tagName === 'TEXTAREA' || field.type === 'text' || field.type === 'password' || field.type === 'url') field.addEventListener('input', function() { autosaveField(field); });
            });
            document.querySelectorAll('.aseo-suggestion-chips button').forEach(function(chip) {
                chip.addEventListener('click', function() {
                    var target = document.getElementById(chip.closest('.aseo-suggestion-chips').getAttribute('data-target'));
                    if (!target) return;
                    target.value = chip.getAttribute('data-value') || '';
                    target.dispatchEvent(new Event('input', {bubbles:true}));
                    target.focus();
                });
            });
            function setupPresetSelect(selectId, inputId) {
                var select = document.getElementById(selectId);
                var input = document.getElementById(inputId);
                if (!select || !input) return;
                var current = input.value.trim();
                var matched = false;
                Array.prototype.forEach.call(select.options, function(option) {
                    if (option.value && option.value !== 'custom' && option.value === current) { select.value = option.value; matched = true; }
                });
                if (!matched && current) { select.value = 'custom'; input.classList.add('aseo-custom-visible'); }
                select.addEventListener('change', function() {
                    if (select.value === 'custom') {
                        input.classList.add('aseo-custom-visible');
                        input.focus();
                        return;
                    }
                    input.classList.remove('aseo-custom-visible');
                    input.value = select.value;
                    input.dispatchEvent(new Event('input', {bubbles:true}));
                });
            }
            setupPresetSelect('aseo_niche_select', 'aseo_niche');
            setupPresetSelect('aseo_brand_voice_select', 'aseo_brand_voice');

            // Replace emojis with WordPress dashicons
            var m = {
                '\uD83D\uDE80':'admin-site-alt3', '\uD83D\uDCDD':'edit-page', '\uD83D\uDD11':'admin-network',
                '\uD83D\uDDBC\uFE0F':'format-gallery', '\uD83D\uDD0C':'admin-plugins', '\uD83C\uDFE2':'store',
                '\u270D\uFE0F':'edit-large', '\uD83E\uDD16':'cloud', '\u2699\uFE0F':'admin-generic',
                '\uD83C\uDF10':'admin-site', '\uD83D\uDD10':'lock', '\uD83D\uDED2':'cart',
                '\uD83D\uDCE6':'archive', '\uD83C\uDFF7\uFE0F':'nametag', '\uD83D\uDCCD':'location-alt',
                '\uD83D\uDCDE':'phone', '\uD83D\uDC64':'admin-users', '\uD83D\uDCB0':'money-alt',
                '\uD83D\uDCE3':'megaphone', '\uD83C\uDFAF':'admin-settings', '\uD83C\uDF3E':'admin-page',
                '\uD83C\uDF99\uFE0F':'microphone', '\u23F0':'clock', '\uD83D\uDCBE':'yes-alt',
                '\u26A1':'performance', '\uD83D\uDCC4':'media-text', '\uD83D\uDDBC':'format-gallery'
            };
            document.querySelectorAll('.aseo-header-logo,.aseo-stat-icon,.tab-icon,.sec-icon,.field-icon').forEach(function(el) {
                var t = el.textContent.trim();
                for (var e in m) {
                    if (t.indexOf(e) !== -1) {
                        el.innerHTML = '<span class="dashicons dashicons-' + m[e] + '"></span>';
                        break;
                    }
                }
            });
            document.querySelectorAll('.aseo-btn,.aseo-action-bar h3').forEach(function(el) {
                var h = el.innerHTML, changed = false;
                for (var e in m) {
                    if (h.indexOf(e) !== -1) {
                        h = h.replace(e, '<span class="dashicons dashicons-' + m[e] + '"></span> ');
                        changed = true;
                    }
                }
                if (changed) el.innerHTML = h;
            });
        })();
        </script>
        <?php
    }
}
