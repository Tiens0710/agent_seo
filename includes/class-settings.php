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
        add_action('wp_ajax_agent_seo_batch_status', array($this, 'ajax_batch_status'));
        add_action('wp_ajax_agent_seo_save_product', array($this, 'ajax_save_product'));
        add_action('wp_ajax_agent_seo_autosave_setting', array($this, 'ajax_autosave_setting'));
        add_action('wp_ajax_agent_seo_retry_image', array($this, 'ajax_retry_image'));
        add_action('wp_ajax_agent_seo_retry_inline_image', array($this, 'ajax_retry_inline_image'));
        add_action('wp_ajax_agent_seo_accept_image', array($this, 'ajax_accept_image'));
        add_action('wp_ajax_agent_seo_preview_post', array($this, 'ajax_preview_post'));
        add_action('wp_ajax_agent_seo_image_status', array($this, 'ajax_image_status'));
        add_action('wp_ajax_agent_seo_accept_all_images', array($this, 'ajax_accept_all_images'));
        add_action('wp_ajax_agent_seo_stop_batch', array($this, 'ajax_stop_batch'));
        add_action('wp_ajax_agent_seo_parse_brief', array($this, 'ajax_parse_brief'));
        add_action('wp_ajax_agent_seo_prepare_assistant', array($this, 'ajax_prepare_assistant'));
        add_action('wp_ajax_agent_seo_auto_company_profile', array($this, 'ajax_auto_company_profile'));
    }

    /**
     * Một nút lấy hồ sơ doanh nghiệp từ chính website WordPress và lưu ngay.
     */
    public function ajax_auto_company_profile() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Không có quyền truy cập.'), 403);
        }
        check_ajax_referer('agent_seo_auto_company_profile', 'nonce');

        $api_key = get_option('aseo_gemini_api_key', '');
        if ($api_key === '') {
            wp_send_json_error(array('message' => 'Chưa cấu hình Gemini API Key.'));
        }

        $website_url = trailingslashit(home_url('/'));
        $profile = Agent_SEO_Gemini_Text::extract_website_profile($api_key, $website_url);
        if (empty($profile['success'])) {
            wp_send_json_error(array(
                'message' => isset($profile['message']) ? $profile['message'] : 'Không lấy được thông tin doanh nghiệp.'
            ));
        }

        $brand_price = trim(
            (isset($profile['product_summary']) ? $profile['product_summary'] : '')
            . (!empty($profile['brand_price']) ? ' Giá công khai: ' . $profile['brand_price'] : '')
        );
        $option_map = array(
            'aseo_source_website' => $website_url,
            'aseo_brand_name'     => isset($profile['brand_name']) ? $profile['brand_name'] : '',
            'aseo_brand_address'  => isset($profile['brand_address']) ? $profile['brand_address'] : '',
            'aseo_brand_phone'    => isset($profile['brand_phone']) ? $profile['brand_phone'] : '',
            'aseo_brand_contact'  => isset($profile['brand_contact']) ? $profile['brand_contact'] : '',
            'aseo_brand_price'    => $brand_price,
            'aseo_brand_cta'      => isset($profile['brand_cta']) ? $profile['brand_cta'] : '',
            'aseo_niche'          => isset($profile['niche']) ? $profile['niche'] : '',
            'aseo_brand_voice'    => isset($profile['brand_voice']) ? $profile['brand_voice'] : ''
        );
        // Không xóa dữ liệu người dùng đã nhập nếu website không công khai
        // một trường cụ thể như hotline, địa chỉ hoặc giá.
        foreach ($option_map as $option_name => $option_value) {
            if ($option_name !== 'aseo_source_website' && trim((string) $option_value) === '') {
                $option_map[$option_name] = get_option($option_name, '');
            }
        }
        foreach ($option_map as $option_name => $option_value) {
            update_option($option_name, $option_value);
        }
        update_option('aseo_company_profile_auto_done', time(), false);
        delete_transient('aseo_website_profile_preview_' . get_current_user_id());

        wp_send_json_success(array(
            'message'       => 'Đã tự lấy và lưu thông tin doanh nghiệp từ ' . wp_parse_url($website_url, PHP_URL_HOST) . '.',
            'source_url'    => $website_url,
            'brand_name'    => $option_map['aseo_brand_name'],
            'brand_address' => $option_map['aseo_brand_address'],
            'brand_phone'   => $option_map['aseo_brand_phone'],
            'brand_contact' => $option_map['aseo_brand_contact'],
            'brand_price'   => $option_map['aseo_brand_price'],
            'brand_cta'     => $option_map['aseo_brand_cta'],
            'niche'         => $option_map['aseo_niche'],
            'brand_voice'   => $option_map['aseo_brand_voice']
        ));
    }

    public function ajax_prepare_assistant() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Không có quyền truy cập.'), 403);
        }
        check_ajax_referer('agent_seo_prepare_assistant', 'nonce');
        $brief = isset($_POST['brief']) ? sanitize_textarea_field(wp_unslash($_POST['brief'])) : '';
        $api_key = get_option('aseo_gemini_api_key', '');
        if ($brief === '' || $api_key === '') {
            wp_send_json_error(array('message' => $brief === '' ? 'Hãy nhập yêu cầu trước.' : 'Chưa cấu hình Gemini API Key.'));
        }

        // Mỗi lần người dùng bấm trợ lý, làm mới hồ sơ doanh nghiệp từ website
        // trước khi tạo brief/prompt để nội dung luôn dùng thông tin mới nhất.
        $website_url = trailingslashit(home_url('/'));
        $profile = Agent_SEO_Gemini_Text::extract_website_profile($api_key, $website_url);
        if (!empty($profile['success'])) {
            $brand_price = trim(
                (isset($profile['product_summary']) ? $profile['product_summary'] : '')
                . (!empty($profile['brand_price']) ? ' Giá công khai: ' . $profile['brand_price'] : '')
            );
            $profile_map = array(
                'aseo_source_website' => $website_url,
                'aseo_brand_name' => isset($profile['brand_name']) ? $profile['brand_name'] : '',
                'aseo_brand_address' => isset($profile['brand_address']) ? $profile['brand_address'] : '',
                'aseo_brand_phone' => isset($profile['brand_phone']) ? $profile['brand_phone'] : '',
                'aseo_brand_contact' => isset($profile['brand_contact']) ? $profile['brand_contact'] : '',
                'aseo_brand_price' => $brand_price,
                'aseo_brand_cta' => isset($profile['brand_cta']) ? $profile['brand_cta'] : '',
                'aseo_niche' => isset($profile['niche']) ? $profile['niche'] : '',
                'aseo_brand_voice' => isset($profile['brand_voice']) ? $profile['brand_voice'] : ''
            );
            foreach ($profile_map as $profile_option => $profile_value) {
                if ($profile_option !== 'aseo_source_website' && trim((string) $profile_value) === '') {
                    $profile_map[$profile_option] = get_option($profile_option, '');
                }
                update_option($profile_option, $profile_map[$profile_option]);
            }
            update_option('aseo_company_profile_auto_done', time(), false);
        }
        $parsed = Agent_SEO_Gemini_Text::parse_article_brief($api_key, $brief);
        if (empty($parsed['success'])) {
            wp_send_json_error(array('message' => $parsed['message']));
        }
        $niche = get_option('aseo_niche', 'Lĩnh vực kinh doanh của website');
        $brand_name = get_option('aseo_brand_name', '');
        $product_info = get_option('aseo_brand_price', '');
        $product_id = absint(get_option('aseo_target_product', 0));
        if ($product_id && function_exists('wc_get_product')) {
            $product = wc_get_product($product_id);
            if ($product) $product_info = trim($product->get_name() . ' | ' . $product_info);
        }
        $prompt_result = Agent_SEO_Gemini_Text::generate_master_prompt_suggestions($api_key, $niche, $brand_name, $product_info, $brief);
        if (empty($prompt_result['success'])) {
            wp_send_json_error(array('message' => $prompt_result['message']));
        }
        update_option('aseo_master_prompt_brief', $brief);
        update_option('aseo_master_prompt', $prompt_result['master_prompt']);
        update_option('aseo_master_text_prompt', $prompt_result['master_prompt']);
        update_option('aseo_master_image_prompt', $prompt_result['master_prompt']);
        wp_send_json_success(array(
            'brief' => $parsed['brief'],
            'master_prompt' => $prompt_result['master_prompt'],
            'company_profile_refreshed' => !empty($profile['success'])
        ));
    }

    public function ajax_parse_brief() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Không có quyền truy cập.'), 403);
        }
        check_ajax_referer('agent_seo_parse_brief', 'nonce');
        $brief = isset($_POST['brief']) ? sanitize_textarea_field(wp_unslash($_POST['brief'])) : '';
        if ($brief === '') {
            wp_send_json_error(array('message' => 'Hãy mô tả yêu cầu trước.'));
        }
        $api_key = get_option('aseo_gemini_api_key', '');
        if ($api_key === '') {
            wp_send_json_error(array('message' => 'Chưa cấu hình Gemini API Key.'));
        }
        $result = Agent_SEO_Gemini_Text::parse_article_brief($api_key, $brief);
        if (empty($result['success'])) {
            wp_send_json_error(array('message' => isset($result['message']) ? $result['message'] : 'AI không thể phân tích brief.'));
        }
        wp_send_json_success($result['brief']);
    }

    /**
     * Dừng batch hiện tại và hủy các cron còn đang chờ.
     * Request HTTP đang chạy không thể bị kill an toàn, nhưng worker sẽ đọc trạng
     * thái stopped và thoát ngay khi request hiện tại trả về.
     */
    public function ajax_stop_batch() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Không có quyền truy cập.'), 403);
        }
        check_ajax_referer('agent_seo_stop_batch', 'nonce');

        $batch = get_option('aseo_batch_status', array());
        $active_states = array('queued', 'running', 'waiting', 'images_pending');
        $status = is_array($batch) && isset($batch['status']) ? $batch['status'] : 'idle';
        if (!in_array($status, $active_states, true)) {
            wp_send_json_error(array('message' => 'Không có tiến trình nào đang chạy.'), 400);
        }

        $batch['status'] = 'stopped';
        $batch['stop_requested'] = 1;
        $batch['message'] = 'Đã dừng theo yêu cầu. Bài và ảnh hoàn tất trước thời điểm dừng vẫn được giữ lại.';
        $batch['updated_at'] = time();
        $batch['finished_at'] = time();
        update_option('aseo_batch_status', $batch, false);

        // Không tạo thêm bài mới trong batch này.
        wp_clear_scheduled_hook('agent_seo_background_task');

        $post_ids = isset($batch['post_ids']) && is_array($batch['post_ids']) ? $batch['post_ids'] : array();
        if (!empty($batch['pending_images']) && is_array($batch['pending_images'])) {
            $post_ids = array_merge($post_ids, $batch['pending_images']);
        }
        if (!empty($batch['pending_inline_images']) && is_array($batch['pending_inline_images'])) {
            $post_ids = array_merge($post_ids, $batch['pending_inline_images']);
        }
        if (!empty($batch['last_post_id'])) {
            $post_ids[] = $batch['last_post_id'];
        }
        $post_ids = array_values(array_unique(array_filter(array_map('absint', $post_ids))));
        foreach ($post_ids as $post_id) {
            wp_clear_scheduled_hook('agent_seo_image_retry_task', array($post_id));
            wp_clear_scheduled_hook('agent_seo_inline_image_task', array($post_id));
        }

        $total = max(0, intval(isset($batch['total']) ? $batch['total'] : 0));
        $completed = max(0, min($total, intval(isset($batch['completed']) ? $batch['completed'] : 0)));
        $batch['total'] = $total;
        $batch['completed'] = $completed;
        $batch['percent'] = $total > 0 ? intval(round(($completed / $total) * 100)) : 0;
        wp_send_json_success($batch);
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
            'aseo_duky_model' => 'text', 'aseo_kaggle_api_url' => 'url', 'aseo_image_engine' => 'text',
            'aseo_enable_inline_images' => 'text', 'aseo_image_aspect_ratio' => 'text',
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
        if ($field === 'aseo_image_aspect_ratio' && !in_array($value, array('16:9', '1:1'), true)) {
            $value = '16:9';
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
        $was_awaiting_approval = get_post_meta($post_id, '_agent_seo_awaiting_image_approval', true) === '1';
        $edit_instruction = isset($_POST['edit_prompt'])
            ? sanitize_textarea_field(wp_unslash($_POST['edit_prompt']))
            : '';
        $is_edit_request = $edit_instruction !== '';
        $current_thumbnail_id = absint(get_post_thumbnail_id($post_id));
        $current_thumbnail_url = $current_thumbnail_id ? wp_get_attachment_url($current_thumbnail_id) : '';

        @set_time_limit(180);

        $job = get_post_meta($post_id, '_agent_seo_image_job', true);
        if (!is_array($job) || empty($job['prompt'])) {
            $job = get_post_meta($post_id, '_agent_seo_base_image_job', true);
        }
        if (!is_array($job) || empty($job['prompt'])) {
            $job = get_post_meta($post_id, '_agent_seo_inline_image_job', true);
        }
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
            update_post_meta($post_id, '_agent_seo_base_image_job', $job);
        }

        if ($was_awaiting_approval) {
            $approved_context = wp_strip_all_tags($post->post_content);
            $approved_context = preg_replace('/\s+/', ' ', trim($approved_context));
            $approved_context = mb_substr($approved_context, 0, 700);
            $job['title'] = $post->post_title;
            $job['prompt'] = trim($job['prompt'])
                . "\n\nAPPROVED ARTICLE CONTENT — USE THE CURRENT REVIEWED VERSION:\n"
                . "Title: " . $post->post_title . "\n"
                . "Summary: " . $approved_context;
            update_post_meta($post_id, '_agent_seo_base_image_job', $job);
        }

        if ($is_edit_request) {
            $base_job = get_post_meta($post_id, '_agent_seo_base_image_job', true);
            if (is_array($base_job) && !empty($base_job['prompt'])) {
                $job = $base_job;
            }
            $job['prompt'] = trim($job['prompt'])
                . "\n\nUSER IMAGE EDIT REQUEST — HIGHEST PRIORITY:\n"
                . $edit_instruction
                . "\nUse the supplied current featured image as the visual reference. Preserve every element the user did not ask to change, especially product identity, package shape, label layout, colors and logos. Do not add invented text.";
            if (!empty($current_thumbnail_url)) {
                $job['product_image'] = $current_thumbnail_url;
                $job['product_image_id'] = $current_thumbnail_id;
            }
            update_post_meta($post_id, '_agent_seo_last_image_edit_instruction', $edit_instruction);
        }

        // Xóa các vết lịch sử cũ để thử lại sạch sẽ
        update_post_meta($post_id, '_agent_seo_image_job', $job);
        // Các bài cũ có thể chưa có job ảnh minh họa; dùng cùng ngữ cảnh để
        // worker bổ sung tối đa 2 ảnh vào thân bài sau khi ảnh đại diện xong.
        $inline_job = get_post_meta($post_id, '_agent_seo_inline_image_job', true);
        if (get_option('aseo_enable_inline_images', '0') === '1' && (!is_array($inline_job) || empty($inline_job['prompt']))) {
            update_post_meta($post_id, '_agent_seo_inline_image_job', $job);
        } elseif (get_option('aseo_enable_inline_images', '0') !== '1') {
            delete_post_meta($post_id, '_agent_seo_inline_image_job');
        }
        $inline_ids = get_post_meta($post_id, '_agent_seo_inline_image_ids', true);
        if (!is_array($inline_ids)) {
            update_post_meta($post_id, '_agent_seo_inline_image_ids', array());
        }
        delete_post_meta($post_id, '_agent_seo_awaiting_image_approval');
        // Mỗi ảnh mới hoặc ảnh sửa lại đều phải được người dùng chấp nhận.
        update_post_meta($post_id, '_agent_seo_image_approved', '0');
        update_post_meta($post_id, '_agent_seo_force_image_retry', '1');
        if ($current_thumbnail_id) {
            // Người dùng đã yêu cầu tạo lại: xóa thumbnail hiện tại để worker
            // không bỏ qua và không hiển thị ảnh tham chiếu như kết quả AI.
            delete_post_thumbnail($post_id);
        }
        delete_post_meta($post_id, '_agent_seo_duky_media_id');
        delete_post_meta($post_id, '_agent_seo_image_attempts');

        // Ảnh có thể mất vài phút; không giữ AJAX request mở chờ API.
        if (!wp_next_scheduled('agent_seo_image_retry_task', array($post_id))) {
            wp_schedule_single_event(time(), 'agent_seo_image_retry_task', array($post_id));
        }
        if (function_exists('spawn_cron')) {
            spawn_cron(time());
        }
        // Một số VPS tắt DISABLE_WP_CRON nhưng vẫn cho phép gọi loopback;
        // kích hoạt wp-cron không chặn request để worker thực sự được chạy.
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            wp_remote_post(site_url('/wp-cron.php'), array(
                'timeout'   => 0.01,
                'blocking'  => false,
                'sslverify' => false
            ));
        }
        if (get_option('aseo_enable_inline_images', '0') === '1' && !wp_next_scheduled('agent_seo_inline_image_task', array($post_id))) {
            // Chờ ảnh đại diện được tạo trước rồi mới chèn ảnh minh họa.
            wp_schedule_single_event(time() + 90, 'agent_seo_inline_image_task', array($post_id));
        }
        if ($was_awaiting_approval) {
            $batch = get_option('aseo_batch_status', array());
            if (is_array($batch)) {
                $review_posts = isset($batch['review_required_posts']) && is_array($batch['review_required_posts'])
                    ? $batch['review_required_posts']
                    : array();
                $batch['review_required_posts'] = array_values(array_filter($review_posts, function($id) use ($post_id) {
                    return intval($id) !== $post_id;
                }));
                $pending_images = isset($batch['pending_images']) && is_array($batch['pending_images'])
                    ? $batch['pending_images']
                    : array();
                $pending_images[] = $post_id;
                $batch['pending_images'] = array_values(array_unique(array_filter(array_map('absint', $pending_images))));
                $batch['status'] = 'images_pending';
                $batch['message'] = 'Đang tạo ảnh cho bài “' . sanitize_text_field($post->post_title) . '”...';
                $batch['updated_at'] = time();
                unset($batch['finished_at']);
                update_option('aseo_batch_status', $batch, false);
            }
        }
        wp_send_json_success(array(
            'message' => $was_awaiting_approval
                ? 'Đã xếp hàng tạo ảnh cho bài đã chọn.'
                : ($is_edit_request
                    ? 'Đã nhận prompt sửa ảnh và bắt đầu tạo phiên bản mới.'
                    : 'Đã xếp hàng tạo lại ảnh. Hệ thống sẽ cập nhật sau khi hoàn tất.'),
            'queued' => true
        ));

    }

    public function ajax_accept_image() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Không có quyền truy cập.'), 403);
        }
        check_ajax_referer('agent_seo_accept_image', 'nonce');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (!$post || $post->post_type !== 'post') {
            wp_send_json_error(array('message' => 'Bài viết không hợp lệ.'), 400);
        }
        if (!get_post_thumbnail_id($post_id)) {
            wp_send_json_error(array('message' => 'Ảnh đại diện chưa tạo xong.'), 409);
        }

        update_post_meta($post_id, '_agent_seo_image_approved', '1');
        delete_post_meta($post_id, '_agent_seo_awaiting_image_approval');

        $inline_ready = true;
        if (get_option('aseo_enable_inline_images', '0') === '1') {
            $inline_ids = get_post_meta($post_id, '_agent_seo_inline_image_ids', true);
            $inline_ids = is_array($inline_ids) ? array_filter(array_map('absint', $inline_ids)) : array();
            $inline_ready = count($inline_ids) >= 1;
        }
        $published = false;
        if ($inline_ready) {
            update_post_meta($post_id, '_agent_seo_image_stage_complete', '1');
            if (get_post_meta($post_id, '_agent_seo_publish_after_images', true) === '1' && $post->post_status !== 'publish') {
                $updated = wp_update_post(array('ID' => $post_id, 'post_status' => 'publish'), true);
                if (is_wp_error($updated)) {
                    wp_send_json_error(array('message' => 'Đã chấp nhận ảnh nhưng chưa thể xuất bản bài viết: ' . $updated->get_error_message()), 500);
                }
                $published = true;
            }
        }

        wp_send_json_success(array(
            'message' => $published
                ? 'Đã chấp nhận ảnh và xuất bản bài viết.'
                : ($inline_ready ? 'Đã chấp nhận ảnh.' : 'Đã chấp nhận ảnh đại diện; hệ thống đang hoàn thiện ảnh phụ.'),
            'published' => $published
        ));
    }

    /** Xếp hàng tạo lại một ảnh minh họa trong thân bài theo prompt người dùng. */
    public function ajax_retry_inline_image() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Không có quyền truy cập.'), 403);
        }
        check_ajax_referer('agent_seo_retry_inline_image', 'nonce');
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $inline_id = isset($_POST['inline_id']) ? absint($_POST['inline_id']) : 0;
        $edit_prompt = isset($_POST['edit_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['edit_prompt'])) : '';
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (!$post || $post->post_type !== 'post' || !$inline_id || $edit_prompt === '') {
            wp_send_json_error(array('message' => 'Bài viết, ảnh phụ hoặc prompt không hợp lệ.'), 400);
        }
        $inline_ids = get_post_meta($post_id, '_agent_seo_inline_image_ids', true);
        $inline_ids = is_array($inline_ids) ? array_values(array_filter(array_map('absint', $inline_ids))) : array();
        if (!in_array($inline_id, $inline_ids, true)) {
            wp_send_json_error(array('message' => 'Không tìm thấy ảnh phụ cần sửa.'), 404);
        }
        // Luồng hiện tại tạo tối đa một ảnh phụ; không làm mất các ảnh khác nếu
        // website đã có dữ liệu cũ nhiều ảnh.
        if (count($inline_ids) > 1) {
            wp_send_json_error(array('message' => 'Bản cài đặt hiện tại chỉ hỗ trợ sửa ảnh phụ đầu tiên.'), 409);
        }
        $base_job = get_post_meta($post_id, '_agent_seo_base_image_job', true);
        if (!is_array($base_job) || empty($base_job['prompt'])) {
            $base_job = get_post_meta($post_id, '_agent_seo_image_job', true);
        }
        if (!is_array($base_job) || empty($base_job['prompt'])) {
            wp_send_json_error(array('message' => 'Không còn prompt gốc để tạo ảnh phụ.'), 409);
        }
        // Xóa figure cũ khỏi nội dung để ảnh mới không bị chèn thêm một bản nữa.
        $content = $post->post_content;
        $pattern = '/<figure[^>]*agent-seo-inline-figure[^>]*>.*?wp-image-' . preg_quote((string) $inline_id, '/') . '.*?<\/figure>/is';
        $content = preg_replace($pattern, '', $content);
        if ($content !== $post->post_content) {
            wp_update_post(array('ID' => $post_id, 'post_content' => $content));
        }
        $inline_job = $base_job;
        $inline_job['prompt'] = trim($base_job['prompt'])
            . "\n\nUSER INLINE IMAGE EDIT REQUEST — HIGHEST PRIORITY:\n"
            . $edit_prompt
            . "\nPreserve the product identity and visual style, but apply the requested change. Do not add text or invented logos.";
        $inline_job['title'] = $post->post_title . ' - Minh hoa 1';
        $inline_job['model_key'] = 'NARWHAL';
        $featured_id = absint(get_post_thumbnail_id($post_id));
        if ($featured_id) {
            $inline_job['product_image'] = wp_get_attachment_url($featured_id);
            $inline_job['product_image_id'] = $featured_id;
        }
        update_post_meta($post_id, '_agent_seo_inline_image_job', $inline_job);
        update_post_meta($post_id, '_agent_seo_inline_image_edit_instruction', $edit_prompt);
        update_post_meta($post_id, '_agent_seo_inline_image_ids', array());
        delete_post_meta($post_id, '_agent_seo_inline_image_attempts');
        delete_post_meta($post_id, '_agent_seo_inline_image_polls');
        if (!wp_next_scheduled('agent_seo_inline_image_task', array($post_id))) {
            wp_schedule_single_event(time(), 'agent_seo_inline_image_task', array($post_id));
        }
        if (function_exists('spawn_cron')) {
            spawn_cron(time());
        }
        wp_send_json_success(array('queued' => true, 'message' => 'Đã xếp hàng sửa ảnh phụ theo prompt.'));
    }

    public function ajax_preview_post() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Không có quyền truy cập.'), 403);
        }
        check_ajax_referer('agent_seo_preview_post', 'nonce');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (!$post || $post->post_type !== 'post') {
            wp_send_json_error(array('message' => 'Không tìm thấy bài viết.'), 404);
        }

        $inline_preview = array();
        $inline_preview_ids = get_post_meta($post_id, '_agent_seo_inline_image_ids', true);
        $inline_preview_ids = is_array($inline_preview_ids) ? array_values(array_filter(array_map('absint', $inline_preview_ids))) : array();
        foreach ($inline_preview_ids as $inline_preview_id) {
            $inline_preview_url = wp_get_attachment_image_url($inline_preview_id, 'large');
            if ($inline_preview_url) {
                $inline_preview[] = array('id' => $inline_preview_id, 'url' => $inline_preview_url, 'instruction' => get_post_meta($post_id, '_agent_seo_inline_image_edit_instruction', true));
            }
        }
        wp_send_json_success(array(
            'post_id' => $post_id,
            'title' => $post->post_title,
            'content' => wp_kses_post(apply_filters('the_content', $post->post_content)),
            'inline_images' => $inline_preview,
            'edit_url' => get_edit_post_link($post_id, 'raw'),
            'view_url' => $post->post_status === 'publish'
                ? get_permalink($post_id)
                : get_preview_post_link($post_id)
        ));
    }

    public function ajax_image_status() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Không có quyền truy cập.'), 403);
        }
        check_ajax_referer('agent_seo_image_status', 'nonce');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (!$post || $post->post_type !== 'post') {
            wp_send_json_error(array('message' => 'Bài viết không hợp lệ.'), 404);
        }

        $featured_id = absint(get_post_thumbnail_id($post_id));
        $approved = get_post_meta($post_id, '_agent_seo_image_approved', true) === '1';
        $waiting = get_post_meta($post_id, '_agent_seo_awaiting_image_approval', true) === '1';
        $job = get_post_meta($post_id, '_agent_seo_image_job', true);
        $has_job = is_array($job) && !empty($job['prompt']);
        $inline_ids = get_post_meta($post_id, '_agent_seo_inline_image_ids', true);
        $inline_ids = is_array($inline_ids) ? array_values(array_filter(array_map('absint', $inline_ids))) : array();
        $inline_id = !empty($inline_ids) ? absint($inline_ids[0]) : 0;
        $inline_job = get_post_meta($post_id, '_agent_seo_inline_image_job', true);
        $inline_running = is_array($inline_job) && !empty($inline_job['prompt']);

        if ($featured_id > 0) {
            $status = $approved ? 'done' : 'review';
        } elseif ($waiting) {
            $status = 'waiting';
        } elseif ($has_job || wp_next_scheduled('agent_seo_image_retry_task', array($post_id))) {
            $status = 'running';
        } else {
            $status = 'failed';
        }

        wp_send_json_success(array(
            'status' => $status,
            'thumb_url' => $featured_id ? wp_get_attachment_image_url($featured_id, 'medium') : '',
            'inline_url' => $inline_id ? wp_get_attachment_image_url($inline_id, 'large') : '',
            'inline_running' => $inline_running,
            'message' => $status === 'running'
                ? 'Ảnh vẫn đang được tạo…'
                : ($status === 'review'
                    ? 'Ảnh mới đã hoàn tất và đang chờ chấp nhận.'
                    : ($status === 'failed' ? 'Không tạo được ảnh. Bạn có thể bấm tạo lại.' : ''))
        ));
    }

    public function ajax_accept_all_images() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Không có quyền truy cập.'), 403);
        }
        check_ajax_referer('agent_seo_accept_all_images', 'nonce');

        $batch = get_option('aseo_batch_status', array());
        $post_ids = is_array($batch) && isset($batch['post_ids']) && is_array($batch['post_ids']) ? $batch['post_ids'] : array();
        $accepted = 0;
        foreach (array_unique(array_filter(array_map('absint', $post_ids))) as $post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'post' || !get_post_thumbnail_id($post_id)) {
                continue;
            }
            if (get_post_meta($post_id, '_agent_seo_image_approved', true) === '1') {
                continue;
            }
            update_post_meta($post_id, '_agent_seo_image_approved', '1');
            delete_post_meta($post_id, '_agent_seo_awaiting_image_approval');
            $inline_ready = true;
            if (get_option('aseo_enable_inline_images', '0') === '1') {
                $inline_ids = get_post_meta($post_id, '_agent_seo_inline_image_ids', true);
                $inline_ready = is_array($inline_ids) && count(array_filter(array_map('absint', $inline_ids))) >= 1;
            }
            if ($inline_ready) {
                update_post_meta($post_id, '_agent_seo_image_stage_complete', '1');
                if (get_post_meta($post_id, '_agent_seo_publish_after_images', true) === '1' && $post->post_status !== 'publish') {
                    wp_update_post(array('ID' => $post_id, 'post_status' => 'publish'));
                }
            }
            $accepted++;
        }
        wp_send_json_success(array('accepted' => $accepted, 'message' => 'Đã chấp nhận ' . $accepted . ' ảnh.'));
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
        if (isset($batch['status']) && in_array($batch['status'], array('running', 'waiting', 'images_pending'), true)) {
            $last_inline_kick = intval(isset($batch['last_inline_kick_at']) ? $batch['last_inline_kick_at'] : 0);
            if (time() - $last_inline_kick >= 15) {
                // Tự khôi phục danh sách từ post_ids. Trước đây nếu event ảnh đã
                // tồn tại nhưng quá hạn thì $kicked=false và Cron không được đánh
                // thức, khiến job nằm chờ vô thời hạn.
                $image_post_ids = isset($batch['post_ids']) && is_array($batch['post_ids'])
                    ? $batch['post_ids']
                    : array();
                if (!empty($batch['pending_images']) && is_array($batch['pending_images'])) {
                    $image_post_ids = array_merge($image_post_ids, $batch['pending_images']);
                }
                if (!empty($batch['pending_inline_images']) && is_array($batch['pending_inline_images'])) {
                    $image_post_ids = array_merge($image_post_ids, $batch['pending_inline_images']);
                }
                if (!empty($batch['last_post_id'])) {
                    $image_post_ids[] = $batch['last_post_id'];
                }
                $image_post_ids = array_values(array_unique(array_filter(array_map('absint', $image_post_ids))));
                $has_outstanding_image_job = false;
                $missing_images_without_job = array();
                // Dựng lại từ trạng thái thật của từng bài, không giữ ID cũ đã
                // hoàn tất vì chúng có thể làm batch mắc kẹt ở images_pending.
                $pending_featured = array();
                $pending_inline = array();

                foreach ($image_post_ids as $pending_post_id) {
                    if (!get_post($pending_post_id)) {
                        continue;
                    }
                    // Chỉ chạy ảnh sau khi người dùng bấm “Tạo ảnh”.
                    if (get_post_meta($pending_post_id, '_agent_seo_awaiting_image_approval', true) === '1') {
                        continue;
                    }
                    $featured_job = get_post_meta($pending_post_id, '_agent_seo_image_job', true);
                    if (
                        !get_post_thumbnail_id($pending_post_id)
                        && is_array($featured_job)
                        && !empty($featured_job['prompt'])
                    ) {
                        $pending_featured[] = $pending_post_id;
                        $has_outstanding_image_job = true;
                        if (!wp_next_scheduled('agent_seo_image_retry_task', array($pending_post_id))) {
                            wp_schedule_single_event(time(), 'agent_seo_image_retry_task', array($pending_post_id));
                        }
                    } elseif (!get_post_thumbnail_id($pending_post_id)) {
                        $missing_images_without_job[] = $pending_post_id;
                    }

                    $inline_job = get_post_meta($pending_post_id, '_agent_seo_inline_image_job', true);
                    $inline_ids = get_post_meta($pending_post_id, '_agent_seo_inline_image_ids', true);
                    $inline_ids = is_array($inline_ids)
                        ? array_values(array_filter(array_map('absint', $inline_ids)))
                        : array();
                    if (
                        get_option('aseo_enable_inline_images', '0') === '1'
                        && count($inline_ids) < 1
                        && is_array($inline_job)
                        && !empty($inline_job['prompt'])
                    ) {
                        $pending_inline[] = $pending_post_id;
                        $has_outstanding_image_job = true;
                        if (!wp_next_scheduled('agent_seo_inline_image_task', array($pending_post_id))) {
                            wp_schedule_single_event(time(), 'agent_seo_inline_image_task', array($pending_post_id));
                        }
                    }
                }

                $batch['pending_images'] = array_values(array_unique(array_filter(array_map('absint', $pending_featured))));
                $batch['pending_inline_images'] = array_values(array_unique(array_filter(array_map('absint', $pending_inline))));
                $batch['last_inline_kick_at'] = time();
                if ($has_outstanding_image_job) {
                    update_option('aseo_batch_status', $batch, false);
                    // Luôn đánh thức Cron, kể cả event đã tồn tại. Đây là khác
                    // biệt quan trọng giúp xử lý các event đang bị quá hạn.
                    if (function_exists('spawn_cron')) {
                        spawn_cron(time());
                    }
                    if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
                        wp_remote_post(site_url('/wp-cron.php'), array(
                            'timeout' => 0.01,
                            'blocking' => false,
                            'sslverify' => false
                        ));
                    }
                } else {
                    // Không còn job nào để chạy: kết thúc trạng thái chờ thay vì
                    // hiển thị quay vô hạn, đồng thời báo rõ bài nào thiếu ảnh.
                    if ($batch['status'] === 'images_pending') {
                        $errors = isset($batch['image_errors']) && is_array($batch['image_errors'])
                            ? $batch['image_errors']
                            : array();
                        $errors = array_merge($errors, $missing_images_without_job);
                        $batch['image_errors'] = array_values(array_unique(array_filter(array_map('absint', $errors))));
                        $batch['status'] = 'complete';
                        $batch['finished_at'] = time();
                        $batch['message'] = !empty($batch['image_errors'])
                            ? 'Đã tạo xong bài viết; có ' . count($batch['image_errors']) . ' bài chưa tạo được ảnh. Có thể bấm “Tạo lại ảnh AI” để thử lại.'
                            : 'Đã tạo xong toàn bộ bài viết và hình ảnh.';
                    }
                    update_option('aseo_batch_status', $batch, false);
                }
            }
        }
        $total = max(0, intval(isset($batch['total']) ? $batch['total'] : 0));
        $completed = max(0, min($total, intval(isset($batch['completed']) ? $batch['completed'] : 0)));
        $started_at = intval(isset($batch['started_at']) ? $batch['started_at'] : 0);
        $finished_at = intval(isset($batch['finished_at']) ? $batch['finished_at'] : 0);
        $updated_at = intval(isset($batch['updated_at']) ? $batch['updated_at'] : time());
        $elapsed = 0;
        if ($finished_at > 0 && $started_at > 0) {
            $elapsed = max(0, $finished_at - $started_at);
        } elseif ($started_at > 0) {
            $elapsed = max(0, time() - $started_at);
        }
        $batch['total'] = $total;
        $batch['completed'] = $completed;
        $batch['started_at'] = $started_at;
        $batch['finished_at'] = $finished_at;
        $batch['updated_at'] = $updated_at;
        $batch['elapsed'] = $elapsed;
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
        register_setting('agent_seo_settings_group', 'aseo_enable_inline_images');
        register_setting('agent_seo_settings_group', 'aseo_image_aspect_ratio');
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

            // Gọi thử kết nối Text API
            $test_text = empty($api_key)
                ? array('success' => false, 'message' => 'Chưa nhập Gemini API Key.')
                : Agent_SEO_Gemini_Text::test_connection($api_key);
            
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
                    'Kết nối thành công! API Văn bản (Gemini 3.1 Flash Lite) và API Hình ảnh (' . $image_engine . ') đều hoạt động. '
                        . (!empty($test_image['message']) ? esc_html($test_image['message']) : ''),
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
            $existing_batch = get_option('aseo_batch_status', array());
            $active_states = array('queued', 'running', 'waiting', 'images_pending');
            $existing_status = is_array($existing_batch) && isset($existing_batch['status'])
                ? $existing_batch['status']
                : '';
            $atomic_lock = get_option('aseo_generation_lock', array());
            $atomic_lock_time = is_array($atomic_lock) && isset($atomic_lock['created_at'])
                ? intval($atomic_lock['created_at'])
                : 0;
            $worker_locked = ($atomic_lock_time > 0 && (time() - $atomic_lock_time) <= 900)
                || (bool) get_transient('agent_seo_generation_lock');
            if (in_array($existing_status, $active_states, true) || $worker_locked) {
                add_settings_error(
                    'agent_seo_messages',
                    'batch_already_running',
                    'Một tiến trình tạo bài vẫn đang chạy. Vui lòng bấm “Dừng tạo bài”, chờ worker dừng hẳn rồi mới bắt đầu lượt mới.',
                    'error'
                );
                return;
            }
            if (isset($_POST['aseo_primary_keyword_run'])) {
                $submitted_primary_keyword = sanitize_text_field(wp_unslash($_POST['aseo_primary_keyword_run']));
                if ($submitted_primary_keyword !== '') {
                    update_option('aseo_primary_keyword', $submitted_primary_keyword);
                }
            }
            // Chatbot brief có thể cung cấp keyword cho lượt chạy đầu tiên,
            // không bắt người dùng phải mở tab cấu hình nâng cao.
            if (trim(get_option('aseo_primary_keyword', '')) === '' && !empty($_POST['aseo_article_topic_run'])) {
                update_option('aseo_primary_keyword', sanitize_text_field(wp_unslash($_POST['aseo_article_topic_run'])));
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
            // Brief theo từng lượt tạo: giữ cấu hình website toàn cục, nhưng cho phép
            // người dùng thay đổi chủ đề/khu vực/ý định/backlink mà không phải sửa tab SEO.
            $run_topic = isset($_POST['aseo_article_topic_run'])
                ? sanitize_text_field(wp_unslash($_POST['aseo_article_topic_run'])) : '';
            $run_location = isset($_POST['aseo_article_location_run'])
                ? sanitize_text_field(wp_unslash($_POST['aseo_article_location_run'])) : '';
            $run_intent = isset($_POST['aseo_article_intent_run'])
                ? sanitize_text_field(wp_unslash($_POST['aseo_article_intent_run'])) : '';
            $run_reference = isset($_POST['aseo_article_reference_run'])
                ? esc_url_raw(wp_unslash($_POST['aseo_article_reference_run'])) : '';
            $run_instructions = isset($_POST['aseo_article_instructions_run'])
                ? sanitize_textarea_field(wp_unslash($_POST['aseo_article_instructions_run'])) : '';
            $run_title = isset($_POST['aseo_article_title_run'])
                ? sanitize_text_field(wp_unslash($_POST['aseo_article_title_run'])) : '';
            $run_outline = isset($_POST['aseo_article_outline_run'])
                ? sanitize_textarea_field(wp_unslash($_POST['aseo_article_outline_run'])) : '';
            $run_secondary = isset($_POST['aseo_article_secondary_run'])
                ? sanitize_textarea_field(wp_unslash($_POST['aseo_article_secondary_run'])) : '';
            $run_brief = array_filter(array(
                $run_title !== '' ? 'Tiêu đề ưu tiên (nếu phù hợp): ' . $run_title : '',
                $run_location !== '' ? 'Khu vực mục tiêu: ' . $run_location : '',
                $run_intent !== '' ? 'Mục đích tìm kiếm: ' . $run_intent : '',
                $run_reference !== '' ? 'Liên kết bắt buộc cần chèn tự nhiên: ' . $run_reference : '',
                $run_secondary !== '' ? 'Từ khóa liên quan cần phủ: ' . $run_secondary : '',
                $run_outline !== '' ? "Dàn ý đã được người dùng duyệt:\n" . $run_outline : '',
                $run_instructions !== '' ? 'Yêu cầu riêng của người dùng: ' . $run_instructions : ''
            ));
            // Mặc định lưu nháp để người dùng duyệt brief/nội dung trước khi xuất bản.
            $post_status = isset($_POST['aseo_post_status_run']) && sanitize_key($_POST['aseo_post_status_run']) === 'publish'
                ? 'publish' : 'draft';
            update_option('aseo_post_status', $post_status);

            // Chỉ xếp bài đầu tiên. Worker sẽ tự xếp bài kế tiếp sau khi bài hiện tại hoàn tất,
            // tránh nhiều request Gemini/Google Flow chạy chồng lên nhau.
            // Remove stale/duplicate workers from an earlier click before
            // starting this exact batch, otherwise counts can be added together.
            wp_clear_scheduled_hook('agent_seo_background_task');
            update_option('aseo_batch_status', array(
                'status' => 'queued',
                'total' => $num_posts,
                'completed' => 0,
                'current' => 0,
                'remaining' => $num_posts,
                'requested_status' => $post_status,
                'product_id' => $selected_product_id,
                'run_topic' => $run_topic,
                'run_brief' => implode("\n", $run_brief),
                'run_brief_used' => 0,
                'post_ids' => array(),
                'stop_requested' => 0,
                'message' => 'Đã xếp hàng ' . $num_posts . ' bài. Đang chờ worker bắt đầu...',
                'started_at' => time(),
                'updated_at' => time(),
                'last_kick_at' => time()
            ), false);
            wp_schedule_single_event(time(), 'agent_seo_background_task', array($post_status, $num_posts));
            if (function_exists('spawn_cron')) {
                spawn_cron(time());
            }

            $status_label = $post_status === 'publish'
                ? 'lưu nháp trước và tự xuất bản sau khi bạn duyệt, tạo đủ ảnh'
                : 'lưu bản nháp để bạn duyệt';
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
        // Xóa toàn bộ thông báo ngoài lề từ plugin khác (Elementor, RankMath...) hoặc WP Core trên trang Agent SEO
        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
        remove_all_actions('user_admin_notices');

        // Lấy các giá trị đã cấu hình
        $api_key = get_option('aseo_gemini_api_key', '');
        $nvidia_api_key = get_option('aseo_nvidia_api_key', '');
        $kaggle_api_url = get_option('aseo_kaggle_api_url', '');
        $last_google_flow_error = get_option('aseo_last_google_flow_error', array());
        $duky_api_key = get_option('aseo_duky_api_key', '');
        $last_duky_error = get_option('aseo_last_duky_error', array());
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
        $enable_inline_images = get_option('aseo_enable_inline_images', '0') === '1';
        $image_aspect_ratio = get_option('aseo_image_aspect_ratio', '16:9');
        if (!in_array($image_aspect_ratio, array('16:9', '1:1'), true)) {
            $image_aspect_ratio = '16:9';
        }
        if (!in_array($image_engine, array('duky', 'kaggle', 'nvidia', 'imagen'), true)) {
            $image_engine = 'duky';
        }
        // Phiên bản cũ từng ép Google Flow chỉ vì còn lưu URL tunnel.
        // Chuyển một lần về DukyAI nếu key Duky vẫn có; sau đó tôn trọng
        // chính xác lựa chọn của người dùng trong ô Bộ máy sinh ảnh.
        if (empty(get_option('aseo_image_engine_unforced_migration', 0)) && !empty($duky_api_key)) {
            $image_engine = 'duky';
            update_option('aseo_image_engine', 'duky');
            update_option('aseo_image_engine_unforced_migration', 1, false);
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
        $company_profile_auto_allowed = empty(get_option('aseo_company_profile_auto_done', 0))
            && (empty($brand_name) || (empty($brand_address) && empty($brand_phone)));
        $company_profile_needs_auto = $company_profile_auto_allowed && !empty($api_key);

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
            .aseo-header h2, .aseo-header .aseo-header-title { margin: 0; color: #fff; font-size: 1.55rem; font-weight: 700; letter-spacing: -0.3px; }
            .aseo-header h2 small, .aseo-header .aseo-header-title small { display: block; font-size: 0.78rem; font-weight: 400; color: rgba(255,255,255,0.6); margin-top: 2px; }
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
            .aseo-wizard-intro { display:flex; align-items:center; gap:10px; margin:0 0 18px; color:#365347; font-size:.82rem; }
            .aseo-wizard-intro .aseo-wizard-badge { display:inline-flex; align-items:center; justify-content:center; min-width:26px; height:26px; border-radius:50%; background:#0a1f14; color:#e7c969; font-weight:800; }
            .aseo-brief-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; margin-bottom:14px; }
            .aseo-create-form > .aseo-brief-grid { grid-column:1 / -1; }
            .aseo-brief-grid .aseo-create-control { display:flex; flex-direction:column; gap:6px; }
            .aseo-brief-grid .aseo-create-control-wide { grid-column:1 / -1; }
            .aseo-brief-grid input, .aseo-brief-grid select, .aseo-brief-grid textarea { width:100%; min-height:46px; border:1px solid #b9cfc1; border-radius:9px; padding:10px 12px; background:#fff; color:#17251d; font-size:.88rem; }
            .aseo-brief-grid textarea { min-height:70px; resize:vertical; }
            .aseo-brief-grid label span { font-size:.78rem; font-weight:800; color:#365347; }
            .aseo-brief-compact-bar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin:0 0 12px; padding:10px 12px; border:1px solid #dbe7df; border-radius:9px; background:#f8faf9; color:#64748b; font-size:.78rem; }
            .aseo-brief-compact-bar strong { color:#365347; }
            .aseo-brief-compact-keyword-input { flex:1; min-width:160px; border:0; border-bottom:1px solid #b9cfc1; background:transparent; color:#17251d; font-size:.82rem; font-weight:700; padding:4px 5px; outline:none; }
            .aseo-brief-compact-keyword-input:focus { border-bottom-color:#16834b; box-shadow:0 1px 0 #16834b; }
            .aseo-brief-compact-toggle { flex:0 0 auto; border:0; background:transparent; color:#166534; font-size:.76rem; font-weight:800; cursor:pointer; text-decoration:underline; }
            .aseo-create-form .aseo-brief-grid.aseo-brief-fields-collapsed { display:none; }
            .aseo-outline-preview { display:none; margin:14px 0; padding:14px 16px; border:1px solid #cfe3d6; border-radius:10px; background:#fbfefc; }
            .aseo-outline-preview.is-visible { display:block; }
            .aseo-outline-preview strong { color:#0a1f14; }
            .aseo-outline-preview ul { margin:8px 0 0 18px; color:#52665a; }
            .aseo-outline-preview .aseo-create-control { display:flex; flex-direction:column; gap:6px; margin-top:12px; }
            .aseo-outline-preview .aseo-create-control span { font-size:.78rem; font-weight:800; color:#365347; }
            .aseo-outline-preview input, .aseo-outline-preview textarea { width:100%; border:1px solid #b9cfc1; border-radius:9px; padding:10px 12px; background:#fff; color:#17251d; font-size:.88rem; }
            .aseo-brief-chat { order:1; padding:22px 24px; border-bottom:1px solid #e7eee9; background:linear-gradient(135deg,#f5fbf7,#fffdf5); }
            .aseo-brief-chat-head { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
            .aseo-brief-chat-head h3 { margin:0; color:#0a1f14; font-size:1.08rem; }
            .aseo-brief-chat-head span { color:#64748b; font-size:.78rem; }
            .aseo-brief-chat textarea { width:100%; min-height:82px; resize:vertical; border:1.5px solid #b9cfc1; border-radius:12px; padding:12px 14px; font-size:.9rem; line-height:1.5; }
            .aseo-brief-chat-actions { display:flex; align-items:center; gap:10px; margin-top:10px; flex-wrap:wrap; }
            .aseo-brief-chat-status { color:#64748b; font-size:.78rem; }
            .aseo-two-stage-flow { padding:20px 24px; border-bottom:1px solid #e7eee9; background:#fff; }
            .aseo-two-stage-title { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px; }
            .aseo-two-stage-title strong { color:#0a1f14; font-size:1rem; }
            .aseo-two-stage-title span { color:#64748b; font-size:.78rem; }
            .aseo-two-stage-grid { display:grid; grid-template-columns:minmax(0,1fr) 42px minmax(0,1fr); align-items:stretch; gap:10px; }
            .aseo-stage-card { display:flex; align-items:flex-start; gap:12px; padding:15px 16px; border:1.5px solid #dbe7df; border-radius:12px; background:#f8faf9; color:#17251d; text-decoration:none; }
            .aseo-stage-card:hover { border-color:#8eb9a1; background:#f2faf5; color:#17251d; }
            .aseo-stage-card.is-active { border-color:#16834b; background:#effaf3; box-shadow:0 0 0 2px rgba(22,131,75,.08); }
            .aseo-stage-card.is-ready { border-color:#c5a85c; background:#fffaf0; }
            .aseo-stage-number { display:inline-flex; align-items:center; justify-content:center; flex:0 0 32px; width:32px; height:32px; border-radius:50%; background:#0f5132; color:#fff; font-weight:800; }
            .aseo-stage-card.is-ready .aseo-stage-number { background:#a77b12; }
            .aseo-stage-copy strong { display:block; margin-bottom:4px; font-size:.9rem; }
            .aseo-stage-copy span { display:block; color:#64748b; font-size:.78rem; line-height:1.45; }
            .aseo-stage-arrow { display:flex; align-items:center; justify-content:center; color:#16834b; font-size:24px; font-weight:800; }
            .aseo-image-stage-panel { padding:22px 24px; border-top:1px solid #e7eee9; border-bottom:1px solid #e7eee9; background:#fbfefc; }
            .aseo-image-stage-head { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; margin-bottom:14px; }
            .aseo-image-stage-head h3 { margin:0 0 4px; color:#0a1f14; font-size:1.08rem; }
            .aseo-image-stage-head p { margin:0; color:#64748b; font-size:.8rem; }
            .aseo-image-stage-tools { display:flex; align-items:center; justify-content:flex-end; gap:10px; flex-wrap:wrap; }
            .aseo-image-ratio-control { display:flex; align-items:center; gap:8px; color:#334155; font-size:.76rem; font-weight:700; }
            .aseo-image-ratio-control select { min-width:150px; min-height:36px; padding:5px 28px 5px 9px; border:1px solid #b9cfc1; border-radius:8px; background:#fff; }
            .aseo-stage-two-badge { flex:0 0 auto; padding:5px 10px; border-radius:999px; background:#0f5132; color:#fff; font-size:.72rem; font-weight:800; }
            .aseo-image-stage-list { display:grid; gap:12px; }
            .aseo-stage-queue-summary { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin:0 0 12px; }
            .aseo-stage-queue-summary span { display:inline-flex; align-items:center; min-height:30px; padding:5px 11px; border:1px solid #dbe7df; border-radius:999px; background:#fff; color:#475569; font-size:.74rem; font-weight:800; }
            .aseo-stage-queue-summary .is-review { border-color:#efd58b; background:#fff7d6; color:#92400e; }
            .aseo-stage-queue-summary .is-running { border-color:#bfdbfe; background:#eff6ff; color:#1d4ed8; }
            .aseo-stage-queue-summary .is-done { border-color:#bbf7d0; background:#f0fdf4; color:#166534; }
            .aseo-bulk-image-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:0 0 12px; }
            /* Hai thao tác hàng loạt cần gọn và nằm cùng một hàng trên desktop. */
            .aseo-image-stage-panel > .aseo-bulk-image-actions { display:inline-flex; width:auto; margin:0 8px 12px 0; vertical-align:top; }
            .aseo-image-stage-panel > .aseo-bulk-image-actions > span { display:none; }
            .aseo-image-stage-panel > .aseo-bulk-image-actions .button#aseo-generate-all-images,
            .aseo-image-stage-panel > .aseo-bulk-image-actions .button#aseo-accept-all-images { min-height:38px; padding:7px 13px; border-radius:9px; font-size:.78rem; }
            .aseo-bulk-image-actions .button#aseo-generate-all-images { min-height:42px; height:auto; padding:9px 17px; border:0; border-radius:10px; background:linear-gradient(135deg,#0f7a43,#16834b 55%,#2b9b63); box-shadow:0 6px 14px rgba(15,122,67,.2); color:#fff; font-size:.82rem; font-weight:800; letter-spacing:.01em; transition:transform .18s ease,box-shadow .18s ease,filter .18s ease; }
            .aseo-bulk-image-actions .button#aseo-generate-all-images:hover { background:linear-gradient(135deg,#0b6638,#116f40 55%,#238653); box-shadow:0 8px 18px rgba(15,122,67,.28); color:#fff; transform:translateY(-1px); filter:saturate(1.08); }
            .aseo-bulk-image-actions .button#aseo-generate-all-images:disabled { background:linear-gradient(135deg,#94a3b8,#64748b); box-shadow:none; cursor:wait; transform:none; }
            .aseo-bulk-image-actions .button#aseo-accept-all-images { min-height:42px; height:auto; padding:9px 17px; border:0; border-radius:10px; background:linear-gradient(135deg,#0f7a43,#16834b 55%,#2b9b63); box-shadow:0 6px 14px rgba(15,122,67,.18); color:#fff; font-size:.82rem; font-weight:800; }
            .aseo-bulk-image-actions .button#aseo-accept-all-images:hover { filter:saturate(1.08) brightness(.96); color:#fff; }
            .aseo-bulk-image-actions span { color:#64748b; font-size:.74rem; }
            .aseo-stage-article-nav { display:flex; align-items:center; gap:10px; margin:0 0 12px; padding:10px 12px; border:1px solid #e2e8f0; border-radius:10px; background:#fff; overflow:hidden; }
            .aseo-stage-article-nav > strong { flex:0 0 auto; color:#334155; font-size:.76rem; }
            .aseo-stage-article-links { display:flex; gap:7px; min-width:0; overflow-x:auto; padding-bottom:2px; }
            .aseo-stage-article-links a { display:block; flex:0 0 auto; max-width:260px; padding:6px 10px; border-radius:7px; background:#f1f5f9; color:#334155; font-size:.73rem; font-weight:700; text-decoration:none; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
            .aseo-stage-article-links a:hover { background:#e2e8f0; color:#0f5132; }
            .aseo-stage-article-list { display:grid; gap:8px; margin:0 0 14px; }
            .aseo-stage-article-row { display:grid; grid-template-columns:42px minmax(0,1fr) auto; align-items:center; gap:11px; padding:10px 11px; border:1px solid #dbe7df; border-radius:10px; background:#fff; }
            .aseo-stage-article-row.is-generating { border-color:#bfdbfe; background:linear-gradient(90deg,#fff,#f8fbff,#fff); background-size:220% 100%; animation:aseoGeneratingRow 2.4s ease-in-out infinite; }
            .aseo-stage-article-row-thumb { display:flex; align-items:center; justify-content:center; width:42px; height:36px; overflow:hidden; border-radius:7px; background:#f1f5f9; color:#64748b; font-size:.7rem; font-weight:800; }
            .aseo-stage-article-row.is-generating .aseo-stage-article-row-thumb { position:relative; color:transparent; background:#eaf2ff; }
            .aseo-stage-article-row.is-generating .aseo-stage-article-row-thumb::after { content:''; position:absolute; inset:0; transform:translateX(-120%); background:linear-gradient(100deg,transparent,rgba(255,255,255,.9),transparent); animation:aseoImageShimmer 1.35s linear infinite; }
            .aseo-stage-article-row-thumb img { width:100%; height:100%; object-fit:cover; }
            .aseo-stage-article-row-copy { min-width:0; }
            .aseo-stage-article-row-copy strong { display:block; color:#17251d; font-size:.8rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
            .aseo-stage-article-row-copy span { display:block; margin-top:3px; color:#64748b; font-size:.7rem; }
            .aseo-stage-article-row-actions { display:flex; align-items:center; gap:7px; }
            .aseo-stage-article-row-actions .button { display:inline-flex; align-items:center; justify-content:center; min-height:32px; height:auto; padding:5px 10px; line-height:1.2; white-space:nowrap; }
            .aseo-stage-article-row.is-generating .aseo-image-work-status.is-running::before { content:''; display:inline-block; width:11px; height:11px; margin-right:6px; border:2px solid #bfdbfe; border-top-color:#2563eb; border-radius:50%; animation:aseoSpin .75s linear infinite; }
            .aseo-stage-article-row.is-generating .aseo-stage-article-row-actions button[disabled]::before { content:''; display:inline-block; width:11px; height:11px; margin-right:6px; border:2px solid #cbd5e1; border-top-color:#2563eb; border-radius:50%; animation:aseoSpin .75s linear infinite; }
            @keyframes aseoGeneratingRow { 0%,100% { background-position:0 0; box-shadow:0 0 0 rgba(37,99,235,0); } 50% { background-position:100% 0; box-shadow:0 0 0 3px rgba(37,99,235,.06); } }
            @keyframes aseoImageShimmer { 100% { transform:translateX(120%); } }
            @keyframes aseoReviewImagePulse { 0%,100% { opacity:.68; } 50% { opacity:.42; } }
            @media (prefers-reduced-motion:reduce) {
                .aseo-stage-article-row.is-generating,
                .aseo-stage-article-row.is-generating .aseo-stage-article-row-thumb::after,
                .aseo-stage-article-row.is-generating .aseo-image-work-status.is-running::before,
                .aseo-stage-article-row.is-generating .aseo-stage-article-row-actions button[disabled]::before { animation:none; }
                .aseo-image-review-card.is-generating .aseo-image-review-visual { animation:none; }
            }
            .aseo-article-modal { position:fixed; inset:0; z-index:100000; display:none; align-items:center; justify-content:center; padding:24px; }
            .aseo-article-modal.is-open { display:flex; }
            .aseo-article-modal-backdrop { position:absolute; inset:0; background:rgba(15,23,42,.62); backdrop-filter:blur(2px); }
            .aseo-article-modal-dialog { position:relative; display:flex; flex-direction:column; width:min(960px,96vw); max-height:90vh; overflow:hidden; border:1px solid #dbe7df; border-radius:16px; background:#fff; box-shadow:0 28px 80px rgba(15,23,42,.28); }
            .aseo-article-modal-dialog.has-inline-controls { display:grid; grid-template-columns:minmax(0,1fr) 320px; grid-template-rows:auto minmax(0,1fr) auto; }
            .aseo-article-modal-dialog.has-inline-controls .aseo-article-modal-head,
            .aseo-article-modal-dialog.has-inline-controls .aseo-article-modal-foot { grid-column:1 / -1; }
            .aseo-article-modal-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; padding:17px 20px; border-bottom:1px solid #e2e8f0; background:#f8faf9; }
            .aseo-article-modal-head h3 { margin:0; color:#17251d; font-size:1.08rem; line-height:1.4; }
            .aseo-article-modal-close { flex:0 0 34px; width:34px; height:34px; padding:0; border:0; border-radius:50%; background:#e2e8f0; color:#334155; font-size:22px; line-height:1; cursor:pointer; }
            .aseo-article-modal-close:hover { background:#cbd5e1; }
            .aseo-article-modal-body { min-height:220px; overflow:auto; padding:22px 28px; color:#334155; font-size:.9rem; line-height:1.7; }
            .aseo-article-modal-body h2 { margin:22px 0 9px; color:#17251d; font-size:1.25rem; }
            .aseo-article-modal-body h3 { margin:18px 0 8px; color:#284638; font-size:1.08rem; }
            .aseo-article-modal-body img { max-width:100%; height:auto; }
            .aseo-article-modal-inline-controls { display:none; padding:12px 20px; border-top:1px solid #dbe7df; background:#fbfefc; }
            .aseo-article-modal-inline-controls.is-visible { display:block; }
            .aseo-article-modal-dialog.has-inline-controls .aseo-article-modal-inline-controls { border-top:0; border-left:1px solid #dbe7df; overflow:auto; }
            .aseo-article-modal-dialog.has-inline-controls .aseo-article-modal-body { min-width:0; max-height:62vh; }
            .aseo-modal-inline-edit { margin:18px 0 0; padding:14px; border:1px solid #dbe7df; border-radius:10px; background:#f8faf9; }
            .aseo-modal-inline-edit strong { display:block; margin-bottom:8px; color:#17251d; font-size:.86rem; }
            .aseo-modal-inline-edit textarea { display:block; width:100%; min-height:70px; margin-bottom:8px; padding:8px 10px; border:1px solid #b9cfc1; border-radius:8px; resize:vertical; box-sizing:border-box; }
            .aseo-modal-inline-edit button { width:100%; }
            .aseo-modal-inline-edit span { display:block; margin-top:6px; color:#64748b; font-size:.74rem; }
            .aseo-article-modal-loading { display:flex; align-items:center; justify-content:center; min-height:180px; color:#64748b; }
            .aseo-article-modal-foot { display:flex; justify-content:flex-end; gap:9px; padding:13px 20px; border-top:1px solid #e2e8f0; background:#f8faf9; }
            body.aseo-modal-open { overflow:hidden; }
            .aseo-image-review-card { display:grid; grid-template-columns:minmax(280px,440px) minmax(280px,1fr); gap:20px; padding:18px; border:1.5px solid #b9cfc1; border-radius:13px; background:#fff; box-shadow:0 8px 24px rgba(15,81,50,.06); }
            .aseo-image-review-card.is-generating { border-color:#bfdbfe; box-shadow:0 0 0 3px rgba(37,99,235,.06); }
            .aseo-image-review-card.is-generating .aseo-image-review-visual { opacity:.68; animation:aseoReviewImagePulse 1.6s ease-in-out infinite; }
            .aseo-image-review-visual img { display:block; width:100%; max-height:330px; object-fit:contain; border:1px solid #e2e8f0; border-radius:11px; background:#f8faf9; }
            .aseo-image-review-info { display:flex; flex-direction:column; justify-content:center; min-width:0; }
            .aseo-image-review-info h4 { margin:0 0 8px; color:#17251d; font-size:1rem; line-height:1.4; }
            .aseo-image-review-info p { margin:8px 0 14px; color:#64748b; font-size:.78rem; }
            .aseo-image-review-actions { display:grid; gap:9px; }
            .aseo-image-review-actions textarea { width:100%; min-height:74px; padding:8px 9px; border:1px solid #b9cfc1; border-radius:8px; resize:vertical; font-size:.76rem; line-height:1.4; }
            .aseo-image-review-actions .button { display:flex; align-items:center; justify-content:center; width:100%; height:auto; min-height:40px; padding:8px 10px; text-align:center; white-space:normal; }
            .aseo-image-action-feedback { display:block; min-height:18px; color:#64748b; font-size:.73rem; text-align:center; }
            .aseo-accept-image { background:#0f7a43 !important; border-color:#0f7a43 !important; color:#fff !important; }
            .aseo-image-work-card { display:grid; grid-template-columns:110px minmax(0,1fr) minmax(240px,320px); align-items:center; gap:16px; padding:14px; border:1px solid #dbe7df; border-radius:13px; background:#fff; }
            .aseo-image-work-card-focus { border-color:#b9cfc1; box-shadow:0 8px 24px rgba(15,81,50,.06); }
            .aseo-image-work-thumb { width:110px; height:90px; object-fit:contain; border:1px solid #e2e8f0; border-radius:10px; background:#f8faf9; }
            .aseo-image-work-empty { display:flex; align-items:center; justify-content:center; width:110px; height:90px; border:1px dashed #cbd5e1; border-radius:10px; background:#f8faf9; color:#94a3b8; font-size:.74rem; text-align:center; }
            .aseo-image-work-info strong { display:block; margin-bottom:6px; color:#17251d; font-size:.88rem; }
            .aseo-image-work-status { display:inline-flex; padding:4px 8px; border-radius:999px; background:#fef3c7; color:#92400e; font-size:.72rem; font-weight:800; }
            .aseo-image-work-status.is-running { background:#dbeafe; color:#1d4ed8; }
            .aseo-image-work-status.is-done { background:#dcfce7; color:#166534; }
            .aseo-image-work-note { display:block; margin-top:7px; color:#64748b; font-size:.75rem; line-height:1.4; }
            .aseo-image-work-actions { display:grid; gap:8px; }
            .aseo-image-work-actions .button, .aseo-image-work-actions .aseo-btn { width:100%; justify-content:center; text-align:center; white-space:normal; height:auto; min-height:36px; line-height:1.3; padding:8px 10px; }
            .aseo-image-work-actions textarea { width:100%; min-height:74px; padding:8px 9px; border:1px solid #b9cfc1; border-radius:8px; resize:vertical; font-size:.76rem; line-height:1.4; }
            .aseo-image-stage-empty { padding:20px; border:1px dashed #cbd5e1; border-radius:12px; color:#64748b; text-align:center; background:#fff; }
            .aseo-draft-review-card { padding:18px; border:1.5px solid #d8c27a; border-radius:13px; background:#fffdf7; }
            .aseo-draft-review-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:12px; }
            .aseo-draft-review-head strong { color:#17251d; font-size:1rem; }
            .aseo-draft-preview { max-height:320px; overflow:auto; padding:16px 18px; border:1px solid #e2e8f0; border-radius:10px; background:#fff; color:#334155; font-size:.84rem; line-height:1.65; }
            .aseo-draft-preview h2 { margin:18px 0 8px; font-size:1.05rem; color:#17251d; }
            .aseo-draft-preview h3 { margin:14px 0 7px; font-size:.95rem; color:#284638; }
            .aseo-draft-preview p { margin:0 0 10px; }
            .aseo-draft-review-actions { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1.4fr); gap:10px; margin-top:12px; }
            .aseo-draft-review-actions .button { display:flex; align-items:center; justify-content:center; width:100%; height:auto; min-height:42px; padding:9px 12px; text-align:center; white-space:normal; }
            .aseo-next-review-note { margin:10px 0 0; color:#64748b; font-size:.76rem; text-align:right; }
            @media (max-width:782px) {
                .aseo-image-stage-panel > .aseo-bulk-image-actions { display:flex; width:100%; margin-right:0; }
                .aseo-image-stage-panel > .aseo-bulk-image-actions .button { width:100%; justify-content:center; }
                .aseo-two-stage-grid { grid-template-columns:1fr; }
                .aseo-stage-arrow { transform:rotate(90deg); min-height:24px; }
                .aseo-image-work-card { grid-template-columns:80px minmax(0,1fr); }
                .aseo-image-work-thumb, .aseo-image-work-empty { width:80px; height:72px; }
                .aseo-image-work-actions { grid-column:1 / -1; }
                .aseo-draft-review-actions { grid-template-columns:1fr; }
                .aseo-image-stage-head { flex-direction:column; }
                .aseo-image-stage-tools { width:100%; justify-content:flex-start; }
                .aseo-image-review-card { grid-template-columns:1fr; }
                .aseo-image-review-visual img { max-height:260px; }
                .aseo-article-modal-dialog.has-inline-controls { display:flex; }
                .aseo-article-modal-dialog.has-inline-controls .aseo-article-modal-inline-controls { border-left:0; border-top:1px solid #dbe7df; }
                .aseo-stage-article-nav { align-items:flex-start; flex-direction:column; }
                .aseo-stage-article-links { width:100%; }
                .aseo-stage-article-row { grid-template-columns:42px minmax(0,1fr); }
                .aseo-stage-article-row-actions { grid-column:1 / -1; }
                .aseo-stage-article-row-actions .button { flex:1; white-space:normal; }
                .aseo-article-modal { padding:10px; }
                .aseo-article-modal-dialog { width:100%; max-height:94vh; }
                .aseo-article-modal-body { padding:17px; }
            }
            .aseo-article-prompt-preview { margin-top:12px; padding:12px 14px; border:1px solid #cfe3d6; border-radius:10px; background:#fbfefc; color:#365347; font-size:.8rem; line-height:1.5; }
            .aseo-article-prompt-preview strong { color:#0a1f14; display:block; margin-bottom:5px; }
            .aseo-article-prompt-preview code { display:block; max-height:150px; overflow:auto; white-space:pre-wrap; font-family:inherit; color:#52665a; }
            /* Sản phẩm/ảnh là đầu vào thủ công, đặt trước chatbot và chỉ hiển thị một lần. */
            #aseo-panel-seo { display:block !important; }
            #aseo-panel-seo > .aseo-section:not(#aseo-product-section) { display:none !important; }
            .aseo-create-form .aseo-create-control:has(.aseo-product-run-select) { display:none !important; }
            .aseo-create-form { grid-template-columns:1fr !important; align-items:stretch; gap:14px; }
            .aseo-create-form > .aseo-create-control { max-width:260px; }
            .aseo-create-form > .aseo-brief-grid { width:100%; }
            .aseo-create-form .aseo-wizard-actions { width:100%; justify-content:flex-end; }
            .aseo-create-form .aseo-wizard-actions .aseo-create-control { max-width:220px; }
            .aseo-create-form .aseo-wizard-actions .aseo-btn { min-height:50px; }
            /* Chế độ gọn: ưu tiên nhãn và thao tác, không chiếm màn hình bằng mô tả dài. */
            .aseo-workflow-card .desc,
            .aseo-workflow-card .aseo-guide,
            .aseo-workflow-card .aseo-wizard-intro,
            .aseo-workflow-card .aseo-master-presets,
            .aseo-workflow-card .aseo-advanced-hint { display:none !important; }
            .aseo-workflow-card .aseo-section-header p { display:none; }
            .aseo-workflow-card .aseo-section-header { padding-top:13px; padding-bottom:13px; }
            .aseo-workflow-card .aseo-section-body { padding-top:15px; padding-bottom:15px; }
            .aseo-workflow-card .aseo-chat-bubble { padding:8px 11px; }
            .aseo-workflow-card .aseo-chat-log { gap:12px; margin-bottom:14px; min-height:360px; max-height:520px; padding:14px 12px; }
            .aseo-workflow-card .aseo-chat-message { max-width:92%; }
            .aseo-workflow-card .aseo-chat-builder .aseo-master-brief { min-height:140px; }
            .aseo-workflow-card .aseo-chat-builder { padding:24px; }
            /* Chế độ tối giản: giữ thao tác chính, ẩn các câu mô tả lặp lại. */
            .aseo-workflow-card .aseo-section-header > p,
            .aseo-workflow-card .aseo-image-stage-head > div > p,
            .aseo-workflow-card .aseo-two-stage-title > span,
            .aseo-workflow-card .aseo-wizard-intro,
            .aseo-workflow-card .aseo-guide,
            .aseo-workflow-card .aseo-stage-copy > span { display:none !important; }
            .aseo-workflow-card .aseo-two-stage-title { margin-bottom:8px; }
            .aseo-workflow-card .aseo-image-stage-head { margin-bottom:9px; }
            .aseo-wizard-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
            .aseo-wizard-next { background:#eaf8ef; color:#166534; border:1px solid #9bc7aa; }
            .aseo-wizard-next:hover { background:#dcfce7; color:#166534; }
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
            .aseo-settings-actions { margin-top:20px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
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
            /* Ẩn tất cả thông báo ngoài lề từ plugin khác hoặc WP Core trên trang Agent SEO */
            .toplevel_page_agent-seo-settings .notice:not(.aseo-own-notice),
            .toplevel_page_agent-seo-settings .updated:not(.aseo-own-notice),
            .toplevel_page_agent-seo-settings .error:not(.aseo-own-notice),
            .toplevel_page_agent-seo-settings .update-nag,
            .toplevel_page_agent-seo-settings .e-notice,
            .toplevel_page_agent-seo-settings div[class*="notice"]:not(.aseo-own-notice),
            .toplevel_page_agent-seo-settings div[class*="error"]:not(.aseo-own-notice),
            .aseo-wrap .notice:not(.aseo-own-notice),
            .aseo-wrap .updated:not(.aseo-own-notice),
            .aseo-wrap .error:not(.aseo-own-notice),
            .aseo-header .notice,
            .aseo-header .updated,
            .aseo-header .error,
            .aseo-header .e-notice,
            .aseo-header div[class*="notice"],
            #wpbody-content > .notice,
            #wpbody-content > .updated,
            #wpbody-content > .error,
            #wpbody-content > .update-nag,
            #wpbody-content > .notice-warning,
            #wpbody-content > .notice-info,
            #wpbody-content > .notice-success,
            #wpbody-content > .notice-error,
            #wpbody-content > div.error,
            #wpbody-content > div.updated,
            #wpbody-content > div.update-nag,
            #wpbody-content > div[class*="notice"] {
                display: none !important;
            }
            .aseo-progress-card { display:none; margin:0 0 22px; padding:18px 20px; border:1px solid #cfe3d6; border-radius:14px; background:#f7fcf8; }
            .aseo-progress-card.visible { display:block; }
            .aseo-progress-head { display:flex; align-items:center; justify-content:space-between; gap:14px; margin-bottom:10px; }
            .aseo-progress-title { display:flex; align-items:center; gap:10px; color:#0a1f14; font-weight:800; }
            .aseo-spinner { width:18px; height:18px; border:3px solid #cfe3d6; border-top-color:#16834b; border-radius:50%; animation:aseoSpin .8s linear infinite; }
            .aseo-progress-card.complete .aseo-spinner { animation:none; border:0; width:20px; height:20px; }
            .aseo-progress-card.complete .aseo-spinner:after { content:'✓'; display:block; color:#16a34a; font-size:20px; line-height:20px; }
            .aseo-progress-card.failed { background:#fff7f7; border-color:#fecaca; }
            .aseo-progress-card.failed .aseo-spinner { animation:none; border-color:#dc2626; }
            .aseo-progress-card.stopped { background:#fffaf0; border-color:#f6c76f; }
            .aseo-progress-card.stopped .aseo-spinner { animation:none; border-color:#d97706; border-top-color:#d97706; }
            .aseo-progress-meta { display:flex; align-items:center; gap:10px; }
            .aseo-progress-timer { display:inline-flex; align-items:center; gap:5px; font-weight:700; font-size:0.8rem; color:#334155; background:#e2eef0; border:1px solid #cbd5e1; padding:3px 10px; border-radius:8px; white-space:nowrap; }
            .aseo-progress-card.complete .aseo-progress-timer { background:#dcfce7; border-color:#86efac; color:#166534; }
            .aseo-progress-card.failed .aseo-progress-timer { background:#fee2e2; border-color:#fca5a5; color:#991b1b; }
            .aseo-progress-card.stopped .aseo-progress-timer { background:#fef3c7; border-color:#f6c76f; color:#92400e; }
            .aseo-progress-count { font-weight:800; color:#1a5c3a; white-space:nowrap; }
            .aseo-stop-batch { border:1px solid #dc2626; border-radius:8px; padding:5px 10px; background:#fff; color:#b91c1c; font-weight:700; cursor:pointer; }
            .aseo-stop-batch:hover { background:#fef2f2; }
            .aseo-stop-batch:disabled { opacity:.55; cursor:wait; }
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
                .aseo-brief-grid { grid-template-columns:1fr; }
                .aseo-brief-grid .aseo-create-control-wide { grid-column:auto; }
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
            .aseo-retry-image.is-queued { color:#166534 !important; border-color:#9bc7aa !important; background:#f0f9f2 !important; cursor:wait; }
            .aseo-retry-image.is-queued::before { content:''; display:inline-block; width:12px; height:12px; margin-right:6px; vertical-align:-2px; border:2px solid #b9d8c1; border-top-color:#16834b; border-radius:50%; animation:aseoSpin .8s linear infinite; }
            .aseo-image-edit-box { margin-top:8px; padding-top:7px; border-top:1px dashed #dbe7df; }
            .aseo-image-edit-box summary { color:#166534; font-size:.76rem; font-weight:700; cursor:pointer; }
            .aseo-image-edit-box textarea { width:100%; min-height:72px; margin:7px 0; padding:8px 9px; border:1px solid #b9cfc1; border-radius:8px; resize:vertical; font-size:.76rem; line-height:1.4; }
            .aseo-image-edit-box .aseo-edit-image { width:100%; min-height:30px; height:auto; white-space:normal; }
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
            /* Luồng tạo bài chính luôn nằm ở đầu trang, trước phần cấu hình. */
            .aseo-quick-create { display:block; }
            .aseo-guide-step[href="#aseo-create-section"] { display:flex; }
            .aseo-guide { grid-template-columns:repeat(3,1fr); }
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
            /* Bản tạo bài ở cuối trang là bản cũ; chỉ giữ một CTA chính ở đầu. */
            .aseo-action-bar .aseo-run-form { display:none; }
            #aseo-settings-form > div[style*="margin-top"] .aseo-btn-save { display:inline-flex; }
            #aseo-panel-api .aseo-master-section { display:block; border:2px solid #cfe3d6; box-shadow:0 8px 24px rgba(10,31,20,.08); }
            #aseo-panel-api .aseo-master-section .aseo-section-header { background:linear-gradient(135deg,#eef9f1,#fffdf5); }
            /* Chatbot brief đầu trang là luồng duy nhất; khối prompt cũ chỉ giữ dữ liệu nền. */
            #aseo-panel-api .aseo-master-section { display:none !important; }
            /* Sắp xếp luồng chính như một wizard: brief → tiến trình → cấu hình chi tiết. */
            .aseo-main { display:flex; flex-direction:column; padding:0; background:transparent; border:0; border-radius:0; box-shadow:none; }
            .aseo-header { border-radius:20px 20px 0 0; }
            .aseo-workflow-card { display:flex; flex-direction:column; overflow:hidden; background:#fff; border:1px solid #dbe7df; border-top:0; border-radius:0 0 20px 20px; box-shadow:0 12px 34px rgba(10,31,20,.08); }
            #aseo-settings-form { display:contents; }
            .aseo-progress-card { order:1; }
            #aseo-panel-seo { order:2; }
            .aseo-two-stage-flow { order:3; }
            .aseo-brief-chat { order:4; }
            #aseo-create-section { order:5; }
            .aseo-image-stage-panel { order:6; }
            .aseo-guide { order:7; }
            .aseo-stats { order:8; }
            .aseo-own-notices-box { order:9; }
            #aseo-panel-api { order:10; }
            .aseo-tabs { order:11; }
            #aseo-panel-brand { order:12; }
            #aseo-settings-form > div[style*="margin-top"] { order:13; }
            .aseo-action-bar { order:14; }
            .aseo-posts-list { order:15; }
            .aseo-workflow-card > .aseo-stats { margin:0; padding:22px 24px; border-bottom:1px solid #e7eee9; }
            .aseo-workflow-card > .aseo-own-notices-box { padding:0 24px; }
            .aseo-workflow-card .aseo-progress-card,
            .aseo-workflow-card .aseo-quick-create,
            .aseo-workflow-card .aseo-section,
            .aseo-workflow-card .aseo-action-bar,
            .aseo-workflow-card .aseo-posts-list { border-radius:0; box-shadow:none; border-left:0; border-right:0; }
            .aseo-workflow-card .aseo-progress-card { margin:0; padding:22px 24px; border-top:0; border-bottom:1px solid #e7eee9; background:#fbfefc; }
            .aseo-workflow-card .aseo-quick-create { margin:0; padding:24px; border-bottom:1px solid #e7eee9; background:linear-gradient(135deg,#f5fbf7,#fffdf5); }
            .aseo-workflow-card .aseo-guide { margin:0; padding:16px 24px; border-bottom:1px solid #e7eee9; background:#f8faf9; }
            .aseo-workflow-card .aseo-tabs { margin:0; border-radius:0; border-bottom:1px solid #dbe7df; background:#f4f7f5; }
            .aseo-workflow-card .aseo-section { border-bottom:1px solid #e7eee9; }
            .aseo-workflow-card .aseo-action-bar { margin:0; border-top:0; border-bottom:1px solid #e7eee9; }
            .aseo-workflow-card .aseo-posts-list { margin:0; }
            #aseo-panel-api .aseo-master-section { margin:0 0 22px; }
            #aseo-panel-api .aseo-master-section .aseo-section-header { padding:20px 24px; }
            #aseo-panel-api .aseo-master-section .aseo-section-header h3 { font-size:1.05rem; }
            #aseo-panel-api .aseo-chat-builder { max-width:none; padding:24px; background:#fff; }
            #aseo-panel-api .aseo-chat-log { min-height:360px; max-height:520px; overflow-y:auto; padding:14px 12px; margin-bottom:18px; scrollbar-width:thin; }
            #aseo-panel-api .aseo-chat-bubble { box-shadow:0 2px 7px rgba(10,31,20,.05); }
            #aseo-panel-api .aseo-master-brief { min-height:140px; background:#fbfefc; }
            #aseo-panel-api .aseo-master-presets { padding-top:2px; }
            #aseo-panel-api .aseo-master-actions { display:flex; align-items:center; flex-wrap:wrap; gap:10px; margin-top:16px; padding-top:14px; border-top:1px solid #eef2ef; }
            #aseo-panel-api .aseo-master-actions .aseo-btn { min-height:44px; }
            @media (max-width: 900px) { .aseo-guide { grid-template-columns:1fr; } }
            @media (prefers-reduced-motion: reduce) { .aseo-wrap *, .aseo-wrap *::before, .aseo-wrap *::after { animation-duration:.001ms !important; transition-duration:.001ms !important; } }
        </style>

        <div class="wrap aseo-wrap">
            <!-- ===== HEADER ===== -->
            <div class="aseo-header">
                <div class="aseo-header-left">
                    <div class="aseo-header-logo">🚀</div>
                    <div class="aseo-header-title">Agent SEO<small>Hệ thống viết bài & sinh ảnh AI tự động</small></div>
                </div>
                <div class="aseo-header-right">
                    <span class="aseo-status-dot">Đang hoạt động</span>
                    <span class="aseo-badge">v<?php echo esc_html(ASEO_VERSION); ?></span>
                </div>
            </div>

            <div class="aseo-main">
                <div class="aseo-workflow-card">
                <div class="aseo-brief-chat" id="aseo-brief-chat">
                    <div class="aseo-brief-chat-head"><span aria-hidden="true">🤖</span><h3>Trợ lý tạo bài</h3><span>Viết yêu cầu tự nhiên, AI tự điền brief</span></div>
                    <div class="aseo-brief-compact-bar aseo-chat-keyword-bar"><label style="display:flex;align-items:center;gap:6px;flex:1;"><strong>Từ khóa chính:</strong><input type="text" class="aseo-brief-compact-keyword-input" id="aseo-brief-compact-keyword" placeholder="Nhập từ khóa chính"></label></div>
                    <textarea id="aseo-brief-chat-input" placeholder="Ví dụ: Viết bài giới thiệu gạo ST25 25kg của Gạo Cần Thơ Kavico, bán tại Cần Thơ, chèn link https://gaocantho.com/..."></textarea>
                    <div class="aseo-brief-chat-actions"><button type="button" class="aseo-btn aseo-btn-save" id="aseo-brief-chat-submit">✨ AI chuẩn bị brief & prompt</button><span class="aseo-brief-chat-status" id="aseo-brief-chat-status" aria-live="polite"></span></div>
                    <?php if (!empty($master_prompt)) : ?><div class="aseo-article-prompt-preview" id="aseo-article-prompt-preview"><strong>Prompt đang áp dụng</strong><code><?php echo esc_html($master_prompt); ?></code></div><?php endif; ?>
                </div>
                <?php
                $stage_batch_state = isset($batch_status['status']) ? $batch_status['status'] : 'idle';
                $stage_one_active = in_array($stage_batch_state, array('queued', 'running', 'waiting'), true);
                $stage_two_active = in_array($stage_batch_state, array('images_pending', 'complete'), true);
                ?>
                <div class="aseo-two-stage-flow" id="aseo-two-stage-flow">
                    <div class="aseo-two-stage-title">
                        <strong>Quy trình tạo nội dung rồi chọn tạo ảnh</strong>
                        <span>API ảnh chỉ chạy khi bạn bấm nút</span>
                    </div>
                    <div class="aseo-two-stage-grid">
                        <a class="aseo-stage-card <?php echo $stage_one_active ? 'is-active' : ''; ?>" href="#aseo-create-section">
                            <span class="aseo-stage-number">1</span>
                            <span class="aseo-stage-copy">
                                <strong>Tạo bài viết</strong>
                                <span>AI viết nội dung và lưu thành danh sách bản nháp.</span>
                            </span>
                        </a>
                        <span class="aseo-stage-arrow" aria-hidden="true">→</span>
                        <a class="aseo-stage-card <?php echo $stage_two_active ? 'is-active' : ''; ?>" href="#aseo-image-stage">
                            <span class="aseo-stage-number">2</span>
                            <span class="aseo-stage-copy">
                                <strong>Chọn bài và tạo ảnh</strong>
                                <span>Bấm tạo ảnh cho bài muốn xử lý, sau đó chấp nhận hoặc sửa.</span>
                            </span>
                        </a>
                    </div>
                </div>
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

                <div class="aseo-own-notices-box">
                    <?php
                    ob_start();
                    settings_errors('agent_seo_messages');
                    $se_output = ob_get_clean();
                    if (!empty($se_output)) {
                        echo str_replace('class="notice', 'class="notice aseo-own-notice', $se_output);
                    }
                    ?>
                    <?php if (!empty($_GET['aseo_gsc_success'])) : ?>
                        <div class="notice notice-success aseo-own-notice"><p><?php echo esc_html(rawurldecode(wp_unslash($_GET['aseo_gsc_success']))); ?></p></div>
                    <?php elseif (!empty($_GET['aseo_gsc_error'])) : ?>
                        <div class="notice notice-error aseo-own-notice"><p><?php echo esc_html(rawurldecode(wp_unslash($_GET['aseo_gsc_error']))); ?></p></div>
                    <?php endif; ?>
                </div>

                <?php
                $batch_total = intval(isset($batch_status['total']) ? $batch_status['total'] : 0);
                $batch_completed = intval(isset($batch_status['completed']) ? $batch_status['completed'] : 0);
                $batch_state = isset($batch_status['status']) ? $batch_status['status'] : 'idle';
                $batch_percent = $batch_total > 0 ? intval(round(($batch_completed / $batch_total) * 100)) : 0;
                if ($batch_state === 'images_pending') $batch_percent = 95;
                $batch_active = in_array($batch_state, array('queued', 'running', 'waiting', 'images_pending'), true);
                $batch_visible = in_array($batch_state, array('queued', 'running', 'waiting', 'images_pending', 'complete', 'failed', 'stopped'), true);
                $batch_started_at = intval(isset($batch_status['started_at']) ? $batch_status['started_at'] : 0);
                $batch_finished_at = intval(isset($batch_status['finished_at']) ? $batch_status['finished_at'] : 0);
                $initial_elapsed = 0;
                if ($batch_started_at > 0) {
                    if (in_array($batch_state, array('complete', 'failed', 'stopped'), true) && $batch_finished_at > 0) {
                        $initial_elapsed = max(0, $batch_finished_at - $batch_started_at);
                    } else {
                        $initial_elapsed = max(0, time() - $batch_started_at);
                    }
                }
                $initial_timer_str = $batch_started_at > 0 ? sprintf('%02d:%02d', floor($initial_elapsed / 60), $initial_elapsed % 60) : '--:--';
                ?>
                <div id="aseo-progress-card" class="aseo-progress-card <?php echo $batch_visible ? 'visible ' . esc_attr($batch_state) : ''; ?>" data-started="<?php echo esc_attr($batch_started_at); ?>" data-finished="<?php echo esc_attr($batch_finished_at); ?>">
                    <div class="aseo-progress-head">
                        <div class="aseo-progress-title"><span class="aseo-spinner"></span><span id="aseo-progress-label"><?php echo $batch_state === 'complete' ? 'Danh sách bài đã sẵn sàng' : ($batch_state === 'stopped' ? 'Đã dừng tiến trình' : 'AI đang xử lý bài viết'); ?></span></div>
                        <div class="aseo-progress-meta">
                            <span id="aseo-progress-timer" class="aseo-progress-timer" title="Thời gian thực hiện">⏱️ <?php echo esc_html($initial_timer_str); ?></span>
                            <div id="aseo-progress-count" class="aseo-progress-count"><?php echo esc_html($batch_completed . '/' . $batch_total . ' bài'); ?></div>
                            <button type="button" id="aseo-stop-batch" class="aseo-stop-batch" style="<?php echo $batch_active ? '' : 'display:none;'; ?>">■ Dừng tiến trình</button>
                        </div>
                    </div>
                    <div class="aseo-progress-track"><div id="aseo-progress-fill" class="aseo-progress-fill" style="width:<?php echo esc_attr($batch_percent); ?>%;"></div></div>
                    <p id="aseo-progress-message" class="aseo-progress-message"><?php echo esc_html(isset($batch_status['message']) ? $batch_status['message'] : ''); ?></p>
                </div>

                <div class="aseo-quick-create" id="aseo-create-section">
                    <div class="aseo-quick-head">
                        <div>
                            <h3>⚡ Tạo bài viết theo brief</h3>
                            <p>Nhập brief → duyệt dàn ý → tạo bài.</p>
                        </div>
                        <span class="aseo-publish-inline">Bước 1/3 · Brief</span>
                    </div>
                        <div class="aseo-wizard-intro"><span class="aseo-wizard-badge">1</span><span>Brief lượt này</span></div>
                    <form method="post" action="" class="aseo-create-form" onsubmit="return confirm('Bắt đầu tạo bài viết bằng AI?');">
                        <?php wp_nonce_field('aseo_action_nonce', 'aseo_nonce'); ?>
                        <input type="hidden" name="aseo_action" value="force_run">
                        <input type="hidden" class="aseo-primary-keyword-run" name="aseo_primary_keyword_run" value="">
                        <input type="hidden" name="aseo_article_topic_run" id="aseo_article_topic_run" value="">
                        <input type="hidden" name="aseo_article_location_run" id="aseo_article_location_run" value="">
                        <input type="hidden" name="aseo_article_intent_run" id="aseo_article_intent_run" value="">
                        <input type="hidden" name="aseo_article_reference_run" id="aseo_article_reference_run" value="">
                        <input type="hidden" name="aseo_article_instructions_run" id="aseo_article_instructions_run" value="">
                        <input type="hidden" name="aseo_article_title_run" id="aseo_article_title_run" value="">
                        <input type="hidden" name="aseo_article_outline_run" id="aseo_article_outline_run" value="">
                        <input type="hidden" name="aseo_article_secondary_run" id="aseo_article_secondary_run" value="">
                        <div class="aseo-brief-compact-bar"><span>Brief đã được Trợ lý AI tự điền</span><button type="button" class="aseo-brief-compact-toggle" id="aseo-toggle-brief-fields" aria-expanded="false">Chỉnh sửa brief</button></div>
                        <div class="aseo-brief-grid aseo-create-control-wide aseo-brief-fields-collapsed" id="aseo-brief-fields">
                            <label class="aseo-create-control"><span>Chủ đề / keyword *</span><input type="text" id="aseo_article_topic" placeholder="gạo ST25 25kg Cần Thơ" required></label>
                            <label class="aseo-create-control"><span>Khu vực</span><input type="text" id="aseo_article_location" placeholder="Cần Thơ, miền Tây"></label>
                            <label class="aseo-create-control"><span>Ý định</span><select id="aseo_article_intent"><option value="Tìm hiểu thông tin">Tìm hiểu</option><option value="So sánh và lựa chọn">So sánh</option><option value="Tìm nơi mua / nhận báo giá">Mua / báo giá</option><option value="Hướng dẫn sử dụng">Hướng dẫn</option></select></label>
                            <label class="aseo-create-control"><span>Backlink</span><input type="url" id="aseo_article_reference" placeholder="https://gaocantho.com/"></label>
                            <label class="aseo-create-control aseo-create-control-wide"><span>Từ khóa phụ</span><input type="text" id="aseo_article_secondary" placeholder="giá gạo ST25, đại lý gạo Cần Thơ"></label>
                            <label class="aseo-create-control aseo-create-control-wide"><span>Yêu cầu thêm</span><textarea id="aseo_article_instructions" placeholder="Thông tin bắt buộc hoặc điều cần tránh"></textarea></label>
                        </div>
                        <div id="aseo-outline-preview" class="aseo-outline-preview" aria-live="polite"></div>
                        <?php if (!empty($wc_products)) : ?>
                            <label class="aseo-create-control"><span>Sản phẩm</span>
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
                            <select name="aseo_num_posts"><option value="1">1 bài</option><option value="2">2 bài</option><option value="3">3 bài</option><option value="5">5 bài</option><option value="10">10 bài</option></select>
                        </label>
                        <div class="aseo-wizard-actions"><button type="button" id="aseo-preview-brief" class="aseo-btn aseo-wizard-next">2. Xem dàn ý</button><label class="aseo-create-control"><span>Sau khi chấp nhận ảnh</span><select name="aseo_post_status_run"><option value="publish" selected>Tự động xuất bản</option><option value="draft">Giữ bản nháp</option></select></label><button type="submit" class="aseo-btn aseo-btn-run">3. Tạo danh sách bài <span aria-hidden="true">→</span></button></div>
                    </form>
                </div>

                <section class="aseo-image-stage-panel" id="aseo-image-stage">
                    <div class="aseo-image-stage-head">
                        <div>
                            <h3>Giai đoạn 2 — Chọn bài viết để tạo ảnh</h3>
                            <p>API ảnh chưa chạy ở bước tạo nội dung. Chọn một bài bên dưới rồi bấm “Tạo ảnh”.</p>
                        </div>
                        <div class="aseo-image-stage-tools">
                            <label class="aseo-image-ratio-control">
                                <span>Tỷ lệ ảnh</span>
                                <select id="aseo_image_aspect_ratio" name="aseo_image_aspect_ratio">
                                    <option value="16:9" <?php selected($image_aspect_ratio, '16:9'); ?>>16:9 — Ảnh ngang</option>
                                    <option value="1:1" <?php selected($image_aspect_ratio, '1:1'); ?>>1:1 — Hình vuông</option>
                                </select>
                            </label>
                            <span class="aseo-stage-two-badge">CHỌN BÀI → TẠO ẢNH</span>
                        </div>
                    </div>
                    <?php
                    $stage_post_ids = isset($batch_status['post_ids']) && is_array($batch_status['post_ids'])
                        ? array_values(array_unique(array_filter(array_map('absint', $batch_status['post_ids']))))
                        : array();
                    $stage_posts = array();
                    foreach ($stage_post_ids as $stage_post_id) {
                        $stage_post = get_post($stage_post_id);
                        if ($stage_post && $stage_post->post_type === 'post') {
                            $stage_posts[] = $stage_post;
                        }
                    }
                    ?>
                    <?php if (!empty($stage_posts)) :
                        $stage_waiting_items = array();
                        $stage_review_items = array();
                        $stage_running_items = array();
                        $stage_done_items = array();
                        $stage_other_items = array();
                        foreach ($stage_posts as $stage_item_post) {
                            $stage_item_id = absint($stage_item_post->ID);
                            $stage_item_featured = absint(get_post_thumbnail_id($stage_item_id));
                            $stage_item_job = get_post_meta($stage_item_id, '_agent_seo_image_job', true);
                            $stage_item_waiting = get_post_meta($stage_item_id, '_agent_seo_awaiting_image_approval', true) === '1';
                            $stage_item_approved = get_post_meta($stage_item_id, '_agent_seo_image_approved', true) === '1';
                            $stage_item_running = !$stage_item_waiting
                                && !$stage_item_featured
                                && is_array($stage_item_job)
                                && !empty($stage_item_job['prompt']);
                            $stage_item = array(
                                'post' => $stage_item_post,
                                'id' => $stage_item_id,
                                'featured_id' => $stage_item_featured,
                                'edit_url' => get_edit_post_link($stage_item_id),
                                'view_url' => $stage_item_post->post_status === 'publish'
                                    ? get_permalink($stage_item_id)
                                    : get_preview_post_link($stage_item_id)
                            );
                            if ($stage_item_waiting && !$stage_item_featured) {
                                $stage_waiting_items[] = $stage_item;
                            } elseif ($stage_item_featured && !$stage_item_approved) {
                                $stage_review_items[] = $stage_item;
                            } elseif ($stage_item_running) {
                                $stage_running_items[] = $stage_item;
                            } elseif ($stage_item_featured && $stage_item_approved) {
                                $stage_done_items[] = $stage_item;
                            } else {
                                $stage_other_items[] = $stage_item;
                            }
                        }
                        $focus_is_review = !empty($stage_review_items);
                        if ($focus_is_review) {
                            $focus_item = $stage_review_items[0];
                            $focus_review_position = 1;
                            $requested_review_id = isset($_GET['aseo_review_post']) ? absint($_GET['aseo_review_post']) : 0;
                            if ($requested_review_id > 0) {
                                foreach ($stage_review_items as $candidate_review_index => $candidate_review_item) {
                                    if ($candidate_review_item['id'] === $requested_review_id) {
                                        $focus_item = $candidate_review_item;
                                        $focus_review_position = $candidate_review_index + 1;
                                        break;
                                    }
                                }
                            }
                            $focus_post = $focus_item['post'];
                            $focus_post_id = $focus_item['id'];
                        }
                    ?>
                    <div class="aseo-stage-queue-summary">
                        <?php if (count($stage_waiting_items) > 0) : ?><span><?php echo esc_html(count($stage_waiting_items)); ?> bài chưa tạo ảnh</span><?php endif; ?>
                        <?php if (count($stage_review_items) > 0) : ?><span class="is-review"><?php echo esc_html(count($stage_review_items)); ?> ảnh chờ chấp nhận</span><?php endif; ?>
                        <?php if (count($stage_running_items) > 0) : ?><span class="is-running"><?php echo esc_html(count($stage_running_items)); ?> ảnh đang tạo</span><?php endif; ?>
                        <?php if (count($stage_done_items) > 0) : ?><span class="is-done"><?php echo esc_html(count($stage_done_items)); ?> ảnh đã chấp nhận</span><?php endif; ?>
                    </div>
                    <?php if (count($stage_waiting_items) > 0) : ?>
                        <div class="aseo-bulk-image-actions"><button type="button" class="button button-primary" id="aseo-generate-all-images" data-count="<?php echo esc_attr(count($stage_waiting_items)); ?>">⚡ Tạo tất cả ảnh (<?php echo esc_html(count($stage_waiting_items)); ?>)</button><span id="aseo-bulk-image-feedback" aria-live="polite">Tạo lần lượt từng ảnh trong nền.</span></div>
                    <?php endif; ?>
                    <?php if (count($stage_review_items) > 0) : ?>
                        <div class="aseo-bulk-image-actions"><button type="button" class="button" id="aseo-accept-all-images" data-count="<?php echo esc_attr(count($stage_review_items)); ?>">✓ Duyệt tất cả ảnh (<?php echo esc_html(count($stage_review_items)); ?>)</button><span id="aseo-accept-all-feedback" aria-live="polite">Chỉ áp dụng cho ảnh đã tạo xong.</span></div>
                    <?php endif; ?>
                    <div class="aseo-stage-article-list">
                        <?php foreach ($stage_posts as $stage_index => $stage_list_post) :
                            $stage_list_id = absint($stage_list_post->ID);
                            $stage_list_featured = absint(get_post_thumbnail_id($stage_list_id));
                            $stage_list_waiting = get_post_meta($stage_list_id, '_agent_seo_awaiting_image_approval', true) === '1';
                            $stage_list_approved = get_post_meta($stage_list_id, '_agent_seo_image_approved', true) === '1';
                            $stage_list_job = get_post_meta($stage_list_id, '_agent_seo_image_job', true);
                            $stage_list_inline_ids = get_post_meta($stage_list_id, '_agent_seo_inline_image_ids', true);
                            $stage_list_inline_ids = is_array($stage_list_inline_ids) ? array_values(array_filter(array_map('absint', $stage_list_inline_ids))) : array();
                            $stage_list_running = !$stage_list_waiting
                                && !$stage_list_featured
                                && is_array($stage_list_job)
                                && !empty($stage_list_job['prompt']);
                            if ($stage_list_waiting) {
                                $stage_list_status = 'Chưa tạo ảnh';
                            } elseif ($stage_list_running) {
                                $stage_list_status = 'Đang tạo ảnh';
                            } elseif ($stage_list_featured && !$stage_list_approved) {
                                $stage_list_status = 'Ảnh chờ chấp nhận';
                            } elseif ($stage_list_featured && $stage_list_approved) {
                                $stage_list_status = 'Ảnh đã chấp nhận';
                            } else {
                                $stage_list_status = 'Chưa có ảnh';
                            }
                        ?>
                        <div class="aseo-stage-article-row<?php echo $stage_list_running ? ' is-generating' : ''; ?>" data-post-id="<?php echo esc_attr($stage_list_id); ?>">
                            <div class="aseo-stage-article-row-thumb">
                                <?php if ($stage_list_featured) : ?>
                                    <img src="<?php echo esc_url(wp_get_attachment_image_url($stage_list_featured, 'thumbnail')); ?>" alt="">
                                <?php else : ?>
                                    <?php echo esc_html($stage_index + 1); ?>
                                <?php endif; ?>
                            </div>
                            <div class="aseo-stage-article-row-copy">
                                <strong><?php echo esc_html($stage_list_post->post_title); ?></strong>
                                <span class="<?php echo $stage_list_running ? 'aseo-image-work-status is-running' : ''; ?>"><?php echo esc_html($stage_list_status); ?></span>
                            </div>
                            <div class="aseo-stage-article-row-actions">
                                <button type="button" class="button aseo-preview-article" data-post-id="<?php echo esc_attr($stage_list_id); ?>">Xem bài</button>
                                <?php if ($stage_list_waiting) : ?>
                                    <button type="button" class="button button-primary aseo-retry-image" data-create-image="1" data-post-id="<?php echo esc_attr($stage_list_id); ?>">Tạo ảnh</button>
                                <?php elseif ($stage_list_running) : ?>
                                    <button type="button" class="button" disabled>Đang tạo…</button>
                                <?php elseif ($stage_list_featured && !$stage_list_approved) : ?>
                                    <a href="#aseo-image-review-current-<?php echo esc_attr($stage_list_id); ?>" class="button">Duyệt ảnh</a>
                                <?php elseif (!$stage_list_featured) : ?>
                                    <button type="button" class="button button-primary aseo-retry-image" data-post-id="<?php echo esc_attr($stage_list_id); ?>">Tạo lại ảnh</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($stage_list_featured && !$stage_list_approved) : ?>
                        <article class="aseo-image-review-card" id="aseo-image-review-current-<?php echo esc_attr($stage_list_id); ?>">
                            <div class="aseo-image-review-visual">
                                <img src="<?php echo esc_url(wp_get_attachment_image_url($stage_list_featured, 'large')); ?>" alt="">
                            </div>
                            <div class="aseo-image-review-info">
                                <span class="aseo-image-work-status">Ảnh chờ xác nhận</span>
                                <h4><?php echo esc_html($stage_list_post->post_title); ?></h4>
                                <p>Ảnh ổn thì chấp nhận. Nếu chưa ổn, nhập yêu cầu sửa rồi tạo phiên bản mới.</p>
                                <div class="aseo-image-review-actions">
                                    <button type="button" class="button button-primary aseo-accept-image" data-post-id="<?php echo esc_attr($stage_list_id); ?>">✓ Chấp nhận ảnh này</button>
                                    <textarea class="aseo-image-edit-prompt" placeholder="Ví dụ: giữ nguyên sản phẩm, đổi nền sáng hơn, bỏ người phía sau..."><?php echo esc_textarea(get_post_meta($stage_list_id, '_agent_seo_last_image_edit_instruction', true)); ?></textarea>
                                    <button type="button" class="button aseo-retry-image aseo-edit-image" data-edit-image="1" data-post-id="<?php echo esc_attr($stage_list_id); ?>">Sửa ảnh theo prompt</button>
                                    <span class="aseo-image-action-feedback" aria-live="polite"></span>
                                    <button type="button" class="button aseo-preview-article" data-post-id="<?php echo esc_attr($stage_list_id); ?>">Xem bài viết</button>
                                </div>
                            </div>
                        </article>
                        <?php endif; ?>
                        <?php foreach ($stage_list_inline_ids as $stage_inline_position => $stage_inline_id) :
                            $stage_inline_url = wp_get_attachment_image_url($stage_inline_id, 'large');
                            if (!$stage_inline_url) continue;
                        ?>
                        <article class="aseo-image-review-card aseo-inline-image-review-card" id="aseo-inline-image-review-<?php echo esc_attr($stage_list_id . '-' . $stage_inline_id); ?>">
                            <div class="aseo-image-review-visual">
                                <img src="<?php echo esc_url($stage_inline_url); ?>" alt="">
                            </div>
                            <div class="aseo-image-review-info">
                                <span class="aseo-image-work-status">Ảnh phụ <?php echo esc_html($stage_inline_position + 1); ?></span>
                                <h4><?php echo esc_html($stage_list_post->post_title); ?></h4>
                                <p>Nhập yêu cầu bên dưới để chỉnh riêng ảnh minh họa này; ảnh đại diện và nội dung bài vẫn được giữ nguyên.</p>
                                <div class="aseo-image-review-actions aseo-inline-image-edit-box">
                                    <textarea class="aseo-image-edit-prompt" placeholder="Ví dụ: đổi góc chụp, nền sáng hơn, giữ nguyên bao gạo..."><?php echo esc_textarea(get_post_meta($stage_list_id, '_agent_seo_inline_image_edit_instruction', true)); ?></textarea>
                                    <button type="button" class="button aseo-retry-inline-image" data-post-id="<?php echo esc_attr($stage_list_id); ?>" data-inline-id="<?php echo esc_attr($stage_inline_id); ?>">Sửa ảnh phụ theo prompt</button>
                                    <span class="aseo-image-action-feedback" aria-live="polite"></span>
                                </div>
                            </div>
                        </article>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!$focus_is_review) : ?>
                        <div class="aseo-image-stage-empty"><?php echo !empty($stage_running_items)
                            ? 'Ảnh đang được tạo. Bạn có thể tiếp tục chọn bài khác trong danh sách hoặc chờ ảnh hoàn tất.'
                            : (!empty($stage_waiting_items)
                                ? 'Chọn bài trong danh sách và bấm “Tạo ảnh”. API ảnh chỉ chạy cho bài bạn chọn.'
                                : 'Toàn bộ ảnh trong lượt này đã được xử lý.'); ?></div>
                    <?php endif; ?>
                    <?php else : ?>
                        <div class="aseo-image-stage-empty">Chưa có bài trong lượt hiện tại. Hãy tạo nội dung ở Giai đoạn 1; danh sách bài sẽ xuất hiện tại đây.</div>
                    <?php endif; ?>
                </section>
                <div class="aseo-article-modal" id="aseo-article-modal" aria-hidden="true">
                    <div class="aseo-article-modal-backdrop" data-close-article-modal></div>
                    <div class="aseo-article-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="aseo-article-modal-title">
                        <div class="aseo-article-modal-head">
                            <h3 id="aseo-article-modal-title">Xem trước bài viết</h3>
                            <button type="button" class="aseo-article-modal-close" data-close-article-modal aria-label="Đóng popup">×</button>
                        </div>
                        <div class="aseo-article-modal-body" id="aseo-article-modal-body"></div>
                        <div class="aseo-article-modal-inline-controls" id="aseo-article-modal-inline-controls"></div>
                        <div class="aseo-article-modal-foot">
                            <a href="#" target="_blank" class="button" id="aseo-article-modal-view">Mở trang xem trước</a>
                            <a href="#" target="_blank" class="button button-primary" id="aseo-article-modal-edit">Mở trình chỉnh sửa</a>
                        </div>
                    </div>
                </div>

                <div class="aseo-guide" aria-label="Hướng dẫn tạo bài">
                    <a class="aseo-guide-step" href="#aseo-create-section">
                        <b class="aseo-guide-number">1</b><span><strong>Nhập brief</strong><span>Chủ đề, khu vực, ý định và backlink.</span></span>
                    </a>
                    <a class="aseo-guide-step" href="#aseo-outline-preview">
                        <b class="aseo-guide-number">2</b><span><strong>Duyệt dàn ý</strong><span>Xem hướng triển khai trước khi AI viết.</span></span>
                    </a>
                    <a class="aseo-guide-step" href="#aseo-progress-card">
                        <b class="aseo-guide-number">3</b><span><strong>Chọn tạo ảnh</strong><span>Bài được lưu nháp; ảnh chỉ chạy khi bạn bấm nút.</span></span>
                    </a>
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
                                            <option value="duky" <?php selected($image_engine, 'duky'); ?>>DukyAI ImageFX</option>
                                            <option value="kaggle" <?php selected($image_engine, 'kaggle'); ?>>Google Flow (Playwright API)</option>
                                        </select>
                                    </div>
                                    <div id="aseo-google-flow-settings" class="aseo-field aseo-fg-full aseo-google-flow-only" style="<?php echo $image_engine === 'kaggle' ? '' : 'display:none;'; ?>">
                                        <label><span class="field-icon">🌐</span> Google Flow API URL</label>
                                        <p class="desc">Dán URL public tới Flask server, ví dụ https://ten-mien.trycloudflare.com/generate</p>
                                        <input type="url" id="aseo_kaggle_api_url" name="aseo_kaggle_api_url" value="<?php echo esc_attr($kaggle_api_url); ?>" placeholder="https://...trycloudflare.com/generate">
                                    </div>
                                    <?php if ($image_engine === 'kaggle' && is_array($last_google_flow_error) && !empty($last_google_flow_error['message'])) : ?>
                                    <div class="aseo-field aseo-fg-full aseo-google-flow-only" style="border:1px solid #fecaca;background:#fff7f7;padding:12px 14px;border-radius:10px;">
                                        <strong style="color:#b91c1c;">Lỗi Google Flow gần nhất:</strong>
                                        <span><?php echo esc_html(strtoupper(isset($last_google_flow_error['stage']) ? $last_google_flow_error['stage'] : 'API') . ' — ' . $last_google_flow_error['message']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="aseo-field aseo-duky-settings-field" style="<?php echo $image_engine === 'duky' ? '' : 'display:none;'; ?>">
                                        <label><span class="field-icon">🔑</span> DukyAI API Key</label>
                                        <p class="desc">API tạo ảnh trực tiếp, không cần chạy Google Flow bot hoặc Cloudflare Tunnel.</p>
                                        <input type="password" id="aseo_duky_api_key" name="aseo_duky_api_key" value="<?php echo esc_attr($duky_api_key); ?>" placeholder="Nhập DukyAI API Key">
                                    </div>
                                    <div class="aseo-field aseo-duky-settings-field" style="<?php echo $image_engine === 'duky' ? '' : 'display:none;'; ?>">
                                        <label><span class="field-icon">🧠</span> Model DukyAI</label>
                                        <p class="desc">GEM_PIX_2 cho chất lượng cao, NARWHAL nhanh hơn, R2I phù hợp Imagen 4.</p>
                                        <select id="aseo_duky_model" name="aseo_duky_model">
                                            <option value="GEM_PIX_2" <?php selected($duky_model, 'GEM_PIX_2'); ?>>GEM_PIX_2 — Nano Banana Pro (mặc định)</option>
                                            <option value="NARWHAL" <?php selected($duky_model, 'NARWHAL'); ?>>NARWHAL — Nano Banana 2</option>
                                            <option value="R2I" <?php selected($duky_model, 'R2I'); ?>>R2I — Imagen 4</option>
                                        </select>
                                    </div>
                                    <?php if ($image_engine === 'duky' && is_array($last_duky_error) && !empty($last_duky_error['message'])) : ?>
                                    <div class="aseo-field aseo-fg-full aseo-duky-settings-field" style="border:1px solid #fecaca;background:#fff7f7;padding:12px 14px;border-radius:10px;">
                                        <strong style="color:#b91c1c;">Lỗi DukyAI gần nhất:</strong>
                                        <span><?php echo esc_html(
                                            strtoupper(isset($last_duky_error['stage']) ? $last_duky_error['stage'] : 'API')
                                            . (!empty($last_duky_error['http_code']) ? ' / HTTP ' . intval($last_duky_error['http_code']) : '')
                                            . ' — ' . $last_duky_error['message']
                                        ); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <div class="aseo-field aseo-fg-full">
                                        <label><span class="field-icon">📝</span> Ảnh minh họa trong nội dung</label>
                                        <p class="desc">Tắt để bài hoàn tất nhanh hơn; ảnh đại diện vẫn được tạo bình thường.</p>
                                        <label style="display:flex; align-items:center; gap:8px; font-weight:600;">
                                            <input type="checkbox" id="aseo_enable_inline_images" name="aseo_enable_inline_images" value="1" <?php checked($enable_inline_images, true); ?>>
                                            Tạo thêm 1 ảnh minh họa trong thân bài
                                        </label>
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
                                <h3>🤖 Trợ lý AI — thiết lập bài viết & hình ảnh</h3>
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

                    <!-- ===== TAB NAVIGATION ===== -->
                    <div class="aseo-advanced-hint">Sau khi thiết lập chatbot, bạn có thể tinh chỉnh nội dung, thông tin doanh nghiệp và cài đặt kỹ thuật ở các tab bên dưới.</div>
                    <div class="aseo-tabs">
                        <button type="button" class="aseo-tab-btn" data-tab="aseo-panel-seo"><span class="tab-icon">⚙️</span> Cấu hình nâng cao</button>
                        <button type="button" class="aseo-tab-btn" data-tab="aseo-panel-brand"><span class="tab-icon">🏢</span> Thông tin doanh nghiệp</button>
                        <button type="button" id="aseo-api-toggle" class="aseo-api-toggle" aria-expanded="false">⚙ Cài đặt kỹ thuật</button>
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
                    <div id="aseo-panel-seo" class="aseo-tab-panel">
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

                    <!-- Trạng thái tự lưu: không cần nút lưu thủ công -->
                    <div class="aseo-settings-actions">
                        <span id="aseo-company-profile-status" class="desc" aria-live="polite">
                            Thông tin công ty được tự động lấy từ <?php echo esc_html(wp_parse_url(home_url('/'), PHP_URL_HOST)); ?>.
                        </span>
                        <span id="aseo-autosave-status" class="aseo-autosave-status" aria-live="polite">Các trường SEO sẽ tự lưu khi bạn thay đổi.</span>
                    </div>
                </form>

                <!-- ===== ACTION BAR (outside form) ===== -->
                <div class="aseo-action-bar">
                    <h3>⚡ Hành động nhanh</h3>

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
                        <input type="hidden" id="aseo_kaggle_api_url_temp" name="aseo_kaggle_api_url_temp" value="">
                        <input type="hidden" id="aseo_image_engine_temp" name="aseo_image_engine_temp" value="">
                        <button type="submit" class="aseo-btn aseo-btn-test" onclick="document.getElementById('aseo_gemini_api_key_temp').value=document.getElementById('aseo_gemini_api_key').value;document.getElementById('aseo_nvidia_api_key_temp').value=document.getElementById('aseo_nvidia_api_key').value;document.getElementById('aseo_duky_api_key_temp').value=document.getElementById('aseo_duky_api_key').value;document.getElementById('aseo_duky_model_temp').value=document.getElementById('aseo_duky_model').value;document.getElementById('aseo_kaggle_api_url_temp').value=document.getElementById('aseo_kaggle_api_url').value;document.getElementById('aseo_image_engine_temp').value=document.getElementById('aseo_image_engine').value;">
                            🔌 Kiểm tra kết nối
                        </button>
                    </form>

                    <?php if ($count_generated > 0) : ?>
                    <a href="<?php echo admin_url('edit.php?post_status=publish&post_type=post'); ?>" class="aseo-btn aseo-btn-test" style="text-decoration:none;">
                        📄 Xem danh sách bài (<?php echo $count_generated; ?>)
                    </a>
                    <?php endif; ?>
                </div>

                <!-- ===== BÀI VIẾT ĐÃ ĐĂNG ===== -->
                <div class="aseo-posts-list" id="aseo-posts-list">
                    <div class="aseo-section">
                        <div class="aseo-section-header">
                            <span class="sec-icon"><span class="dashicons dashicons-list-view"></span></span>
                            <h3>Bài viết AI gần đây</h3>
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
                                            $awaiting_image_approval = get_post_meta($ai_post->ID, '_agent_seo_awaiting_image_approval', true) === '1';
                                            if ($awaiting_image_approval) {
                                                echo '<span style="color:#1d4ed8;font-weight:700;font-size:.78rem;display:block;margin-bottom:5px;">● Ảnh đang chờ khởi chạy</span>';
                                            } elseif (has_post_thumbnail($ai_post->ID) && get_post_meta($ai_post->ID, '_agent_seo_image_approved', true) !== '1') {
                                                echo '<span style="color:#92400e;font-weight:700;font-size:.78rem;display:block;margin-bottom:5px;">● Ảnh chờ chấp nhận</span>';
                                            }
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
                                            <a href="<?php echo esc_url($ai_post->post_status === 'publish' ? get_permalink($ai_post->ID) : get_preview_post_link($ai_post->ID)); ?>" target="_blank" class="post-link">Xem <span class="dashicons dashicons-external"></span></a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php else : ?>
                            <div class="aseo-posts-empty">
                                <span class="dashicons dashicons-edit-page"></span>
                                Chưa có bài viết nào. Hãy nhập brief và tạo nội dung nháp để bắt đầu.
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
                </div>
        </div>

        <script>
        (function(){
            var companyProfileStatus = document.getElementById('aseo-company-profile-status');
            var companyProfileLoading = false;
            var companyProfileLoaded = false;
            var companyProfileAutoAllowed = <?php echo wp_json_encode((bool) $company_profile_auto_allowed); ?>;
            function loadCompanyProfile() {
                if (companyProfileLoading) return;
                companyProfileLoading = true;
                if (companyProfileStatus) companyProfileStatus.textContent = 'Đang tự tìm trang chủ, Liên hệ và Giới thiệu...';

                var companyBody = new URLSearchParams();
                companyBody.append('action', 'agent_seo_auto_company_profile');
                companyBody.append('nonce', '<?php echo esc_js(wp_create_nonce('agent_seo_auto_company_profile')); ?>');
                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: companyBody.toString()
                })
                .then(function(response) { return response.json(); })
                .then(function(payload) {
                    if (!payload.success) {
                        throw new Error(payload.data && payload.data.message ? payload.data.message : 'Không lấy được thông tin doanh nghiệp.');
                    }
                    var data = payload.data || {};
                    var fieldMap = {
                        'aseo_brand_name': 'brand_name',
                        'aseo_brand_address': 'brand_address',
                        'aseo_brand_phone': 'brand_phone',
                        'aseo_brand_contact': 'brand_contact',
                        'aseo_brand_price': 'brand_price',
                        'aseo_brand_cta': 'brand_cta',
                        'aseo_niche': 'niche',
                        'aseo_brand_voice': 'brand_voice'
                    };
                    Object.keys(fieldMap).forEach(function(fieldId) {
                        var field = document.getElementById(fieldId);
                        if (field) field.value = data[fieldMap[fieldId]] || '';
                    });
                    companyProfileLoaded = true;
                    companyProfileAutoAllowed = false;
                    if (companyProfileStatus) companyProfileStatus.textContent = data.message || 'Đã lưu thông tin doanh nghiệp.';
                })
                .catch(function(error) {
                    if (companyProfileStatus) companyProfileStatus.textContent = error.message;
                })
                .finally(function() {
                    companyProfileLoading = false;
                });
            }
            if (<?php echo wp_json_encode((bool) $company_profile_needs_auto); ?>) {
                window.setTimeout(loadCompanyProfile, 700);
            }

            var imageEngineSelect = document.getElementById('aseo_image_engine');
            function syncImageEngineSettings() {
                if (!imageEngineSelect) return;
                var useFlow = imageEngineSelect.value === 'kaggle';
                document.querySelectorAll('.aseo-google-flow-only').forEach(function(field) {
                    field.style.display = useFlow ? '' : 'none';
                });
                document.querySelectorAll('.aseo-duky-settings-field').forEach(function(field) {
                    field.style.display = useFlow ? 'none' : '';
                });
            }
            if (imageEngineSelect) {
                imageEngineSelect.addEventListener('change', syncImageEngineSettings);
                syncImageEngineSettings();
            }

            var imageStatusPollers = {};
            var bulkImageQueue = [];
            var bulkImageRunning = false;
            var bulkImageTotal = 0;
            var bulkImageCompleted = 0;
            function advanceBulkImage(data) {
                var status = data && data.status ? data.status : 'failed';
                var currentButton = data && data.button ? data.button : null;
                bulkImageCompleted++;
                if (currentButton) currentButton.removeAttribute('data-bulk-image');
                if (bulkImageQueue.length) {
                    if (generateAllImagesFeedback) generateAllImagesFeedback.textContent = 'Đã xử lý ' + bulkImageCompleted + '/' + bulkImageTotal + '. Đang chuyển sang bài tiếp theo…';
                    window.setTimeout(startNextBulkImage, 600);
                } else {
                    bulkImageRunning = false;
                    if (generateAllImagesFeedback) generateAllImagesFeedback.textContent = status === 'review' || status === 'done' ? '✓ Đã tạo xong toàn bộ ảnh.' : 'Đã xử lý xong hàng đợi ảnh.';
                    window.setTimeout(function() { window.location.reload(); }, 700);
                }
                return true;
            }
            function startNextBulkImage() {
                if (!bulkImageQueue.length) return;
                var nextButton = bulkImageQueue.shift();
                if (!nextButton || nextButton.disabled) {
                    startNextBulkImage();
                    return;
                }
                nextButton.setAttribute('data-bulk-image', '1');
                nextButton.click();
            }
            function pollImageStatus(postId, feedback, triggerButton, onFinished) {
                if (!postId || imageStatusPollers[postId]) return;
                var pollCount = 0;
                function checkStatus() {
                    pollCount++;
                    var statusBody = new URLSearchParams();
                    statusBody.append('action', 'agent_seo_image_status');
                    statusBody.append('nonce', '<?php echo esc_js(wp_create_nonce('agent_seo_image_status')); ?>');
                    statusBody.append('post_id', postId);
                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: statusBody.toString()
                    }).then(function(response) {
                        return response.json();
                    }).then(function(payload) {
                        if (!payload.success) throw new Error('Không đọc được trạng thái ảnh.');
                        var data = payload.data || {};
                        if (feedback && data.message) feedback.textContent = data.message;
                        if (data.status === 'review' || data.status === 'done') {
                            delete imageStatusPollers[postId];
                            if (feedback) feedback.textContent = '✓ Ảnh mới đã hoàn tất. Đang cập nhật giao diện…';
                            if (onFinished && onFinished({status:data.status, button:triggerButton})) return;
                            window.setTimeout(function() { window.location.reload(); }, 500);
                            return;
                        }
                        if (data.status === 'failed' || data.status === 'waiting') {
                            delete imageStatusPollers[postId];
                            if (feedback) feedback.textContent = data.message || 'Chưa thể tạo ảnh.';
                            if (triggerButton) {
                                triggerButton.disabled = false;
                                triggerButton.classList.remove('is-queued');
                                triggerButton.textContent = 'Tạo lại ảnh';
                            }
                            if (onFinished && onFinished({status:data.status, button:triggerButton})) return;
                            return;
                        }
                        if (pollCount < 180) {
                            imageStatusPollers[postId] = window.setTimeout(checkStatus, 5000);
                        } else {
                            delete imageStatusPollers[postId];
                            if (feedback) feedback.textContent = 'Ảnh vẫn đang xử lý nền. Bạn có thể rời trang và quay lại sau.';
                        }
                    }).catch(function() {
                        if (pollCount < 180) {
                            imageStatusPollers[postId] = window.setTimeout(checkStatus, 7000);
                        } else {
                            delete imageStatusPollers[postId];
                        }
                    });
                }
                imageStatusPollers[postId] = true;
                window.setTimeout(checkStatus, 1200);
            }
            document.querySelectorAll('#aseo-image-stage .aseo-stage-article-row.is-generating[data-post-id]').forEach(function(row) {
                pollImageStatus(row.getAttribute('data-post-id'), null, null);
            });
            var generateAllImagesButton = document.getElementById('aseo-generate-all-images');
            var generateAllImagesFeedback = document.getElementById('aseo-bulk-image-feedback');
            if (generateAllImagesButton) {
                generateAllImagesButton.addEventListener('click', function() {
                    var waitingButtons = Array.prototype.slice.call(document.querySelectorAll('#aseo-image-stage .aseo-retry-image[data-create-image="1"]:not(:disabled)'));
                    if (!waitingButtons.length) {
                        generateAllImagesButton.disabled = true;
                        generateAllImagesButton.textContent = '✓ Đã xếp hàng toàn bộ ảnh';
                        if (generateAllImagesFeedback) generateAllImagesFeedback.textContent = 'Không còn bài chờ tạo ảnh.';
                        return;
                    }
                    generateAllImagesButton.disabled = true;
                    bulkImageQueue = waitingButtons;
                    bulkImageRunning = true;
                    bulkImageTotal = waitingButtons.length;
                    bulkImageCompleted = 0;
                    generateAllImagesButton.textContent = '⚡ Đang tạo ảnh lần lượt…';
                    if (generateAllImagesFeedback) generateAllImagesFeedback.textContent = 'Đang xử lý bài 1/' + waitingButtons.length + '. Bài tiếp theo sẽ tự chạy khi ảnh xong.';
                    startNextBulkImage();
                });
            }
            var acceptAllImagesButton = document.getElementById('aseo-accept-all-images');
            var acceptAllImagesFeedback = document.getElementById('aseo-accept-all-feedback');
            if (acceptAllImagesButton) {
                acceptAllImagesButton.addEventListener('click', function() {
                    if (!window.confirm('Chấp nhận toàn bộ ảnh đã tạo xong trong lượt này?')) return;
                    acceptAllImagesButton.disabled = true;
                    acceptAllImagesButton.textContent = 'Đang lưu…';
                    var acceptAllBody = new URLSearchParams();
                    acceptAllBody.append('action', 'agent_seo_accept_all_images');
                    acceptAllBody.append('nonce', '<?php echo esc_js(wp_create_nonce('agent_seo_accept_all_images')); ?>');
                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST', credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: acceptAllBody.toString()
                    }).then(function(response) { return response.json(); }).then(function(payload) {
                        if (!payload.success) throw new Error(payload.data && payload.data.message ? payload.data.message : 'Không thể duyệt ảnh.');
                        if (acceptAllImagesFeedback) acceptAllImagesFeedback.textContent = payload.data.message || 'Đã duyệt tất cả ảnh.';
                        acceptAllImagesButton.textContent = '✓ Đã duyệt tất cả';
                        window.setTimeout(function() { window.location.reload(); }, 700);
                    }).catch(function(error) {
                        if (acceptAllImagesFeedback) acceptAllImagesFeedback.textContent = error.message || 'Không thể duyệt ảnh.';
                        acceptAllImagesButton.disabled = false;
                        acceptAllImagesButton.textContent = '✓ Duyệt tất cả ảnh';
                    });
                });
            }

            var articleModal = document.getElementById('aseo-article-modal');
            var articleModalTitle = document.getElementById('aseo-article-modal-title');
            var articleModalBody = document.getElementById('aseo-article-modal-body');
            var articleModalInlineControls = document.getElementById('aseo-article-modal-inline-controls');
            var articleModalDialog = articleModal ? articleModal.querySelector('.aseo-article-modal-dialog') : null;
            var articleModalEdit = document.getElementById('aseo-article-modal-edit');
            var articleModalView = document.getElementById('aseo-article-modal-view');
            var articlePreviewCache = {};
            function closeArticleModal() {
                if (!articleModal) return;
                articleModal.classList.remove('is-open');
                articleModal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('aseo-modal-open');
            }
            function renderArticlePreview(data) {
                if (!articleModal || !articleModalTitle || !articleModalBody) return;
                articleModalTitle.textContent = data.title || 'Xem trước bài viết';
                articleModalBody.innerHTML = data.content || '<p>Bài viết chưa có nội dung.</p>';
                if (articleModalDialog) articleModalDialog.classList.remove('has-inline-controls');
                if (articleModalInlineControls) {
                    articleModalInlineControls.innerHTML = '';
                    articleModalInlineControls.classList.remove('is-visible');
                }
                if (Array.isArray(data.inline_images) && data.inline_images.length) {
                    data.inline_images.forEach(function(inlineImage, index) {
                        var box = document.createElement('div');
                        box.className = 'aseo-modal-inline-edit';
                        box.innerHTML = '<strong>Ảnh phụ ' + (index + 1) + '</strong><textarea placeholder="Nhập yêu cầu sửa ảnh phụ..."></textarea><button type="button" class="button button-primary">Sửa ảnh phụ theo prompt</button><span aria-live="polite"></span>';
                        box.setAttribute('data-post-id', data.post_id || '');
                        box.setAttribute('data-inline-id', inlineImage.id || '');
                        if (articleModalInlineControls) {
                            articleModalInlineControls.appendChild(box);
                            articleModalInlineControls.classList.add('is-visible');
                            if (articleModalDialog) articleModalDialog.classList.add('has-inline-controls');
                        }
                        var editButton = box.querySelector('button');
                        var editField = box.querySelector('textarea');
                        var feedback = box.querySelector('span');
                        editField.value = inlineImage.instruction || '';
                        editButton.addEventListener('click', function() {
                            var prompt = editField.value.trim();
                            if (!prompt) { editField.focus(); feedback.textContent = 'Hãy nhập yêu cầu sửa ảnh phụ.'; return; }
                            editButton.disabled = true;
                            editButton.textContent = 'Đang xếp hàng…';
                            feedback.textContent = 'Đang gửi prompt sửa ảnh phụ…';
                            var body = new URLSearchParams();
                            body.append('action', 'agent_seo_retry_inline_image');
                            body.append('nonce', '<?php echo esc_js(wp_create_nonce('agent_seo_retry_inline_image')); ?>');
                            body.append('post_id', data.post_id || '');
                            body.append('inline_id', inlineImage.id || '');
                            body.append('edit_prompt', prompt);
                            fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:body.toString()})
                                .then(function(response) { return response.json(); })
                                .then(function(payload) {
                                    if (!payload.success) throw new Error(payload.data && payload.data.message ? payload.data.message : 'Không thể sửa ảnh phụ.');
                                    editButton.textContent = 'Đã xếp hàng sửa ảnh';
                                    feedback.textContent = 'Ảnh mới sẽ cập nhật sau khi worker hoàn tất.';
                                })
                                .catch(function(error) { editButton.disabled = false; editButton.textContent = 'Sửa ảnh phụ theo prompt'; feedback.textContent = error.message || 'Không thể sửa ảnh phụ.'; });
                        });
                    });
                }
                if (articleModalEdit) {
                    articleModalEdit.href = data.edit_url || '#';
                    articleModalEdit.style.display = data.edit_url ? '' : 'none';
                }
                if (articleModalView) {
                    articleModalView.href = data.view_url || '#';
                    articleModalView.style.display = data.view_url ? '' : 'none';
                }
            }
            document.querySelectorAll('.aseo-preview-article').forEach(function(button) {
                button.addEventListener('click', function() {
                    if (!articleModal || !articleModalBody || !articleModalTitle) return;
                    var postId = button.getAttribute('data-post-id');
                    if (!postId) return;
                    articleModal.classList.add('is-open');
                    articleModal.setAttribute('aria-hidden', 'false');
                    document.body.classList.add('aseo-modal-open');
                    articleModalTitle.textContent = 'Đang tải bài viết…';
                    articleModalBody.innerHTML = '<div class="aseo-article-modal-loading">Đang tải nội dung…</div>';
                    if (articlePreviewCache[postId]) {
                        renderArticlePreview(articlePreviewCache[postId]);
                        return;
                    }
                    var previewBody = new URLSearchParams();
                    previewBody.append('action', 'agent_seo_preview_post');
                    previewBody.append('nonce', '<?php echo esc_js(wp_create_nonce('agent_seo_preview_post')); ?>');
                    previewBody.append('post_id', postId);
                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: previewBody.toString()
                    }).then(function(response) {
                        return response.json();
                    }).then(function(payload) {
                        if (!payload.success) {
                            throw new Error(payload.data && payload.data.message ? payload.data.message : 'Không tải được bài viết.');
                        }
                    articlePreviewCache[postId] = payload.data;
                    payload.data.post_id = postId;
                    renderArticlePreview(payload.data);
                    }).catch(function(error) {
                        articleModalTitle.textContent = 'Không thể mở bài viết';
                        articleModalBody.innerHTML = '<div class="aseo-article-modal-loading"></div>';
                        articleModalBody.querySelector('.aseo-article-modal-loading').textContent = error.message || 'Không tải được bài viết.';
                    });
                });
            });
            document.querySelectorAll('[data-close-article-modal]').forEach(function(control) {
                control.addEventListener('click', closeArticleModal);
            });
            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && articleModal && articleModal.classList.contains('is-open')) {
                    closeArticleModal();
                }
            });

            document.querySelectorAll('.aseo-accept-image').forEach(function(button) {
                button.addEventListener('click', function() {
                    var postId = button.getAttribute('data-post-id');
                    if (!postId) return;
                    button.disabled = true;
                    button.textContent = 'Đang lưu xác nhận…';
                    var body = new URLSearchParams();
                    body.append('action', 'agent_seo_accept_image');
                    body.append('nonce', '<?php echo esc_js(wp_create_nonce('agent_seo_accept_image')); ?>');
                    body.append('post_id', postId);
                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: body.toString()
                    }).then(function(response) {
                        return response.json();
                    }).then(function(payload) {
                        if (!payload.success) {
                            throw new Error(payload.data && payload.data.message ? payload.data.message : 'Không thể chấp nhận ảnh.');
                        }
                        button.textContent = '✓ Đã chấp nhận — chuyển ảnh tiếp…';
                        window.setTimeout(function() { window.location.reload(); }, 700);
                    }).catch(function(error) {
                        window.alert(error.message || 'Lỗi kết nối khi chấp nhận ảnh.');
                        button.disabled = false;
                        button.textContent = '✓ Chấp nhận ảnh này';
                    });
                });
            });

            document.querySelectorAll('.aseo-retry-inline-image').forEach(function(button) {
                button.addEventListener('click', function() {
                    var card = button.closest('.aseo-inline-image-edit-box');
                    var field = card ? card.querySelector('.aseo-image-edit-prompt') : null;
                    var feedback = card ? card.querySelector('.aseo-image-action-feedback') : null;
                    var prompt = field ? field.value.trim() : '';
                    if (!prompt) {
                        window.alert('Hãy nhập yêu cầu cần sửa trên ảnh phụ.');
                        if (field) field.focus();
                        return;
                    }
                    button.disabled = true;
                    button.classList.add('is-queued');
                    button.textContent = '🎨 Đang xếp hàng sửa ảnh...';
                    if (feedback) feedback.textContent = 'Đang gửi prompt sửa ảnh phụ…';
                    var body = new URLSearchParams();
                    body.append('action', 'agent_seo_retry_inline_image');
                    body.append('nonce', '<?php echo esc_js(wp_create_nonce('agent_seo_retry_inline_image')); ?>');
                    body.append('post_id', button.getAttribute('data-post-id') || '');
                    body.append('inline_id', button.getAttribute('data-inline-id') || '');
                    body.append('edit_prompt', prompt);
                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:body.toString()})
                        .then(function(response) { return response.json(); })
                        .then(function(payload) {
                            if (!payload.success) throw new Error(payload.data && payload.data.message ? payload.data.message : 'Không thể xếp hàng sửa ảnh phụ.');
                            button.textContent = 'Đang tạo ảnh phụ…';
                            if (feedback) feedback.textContent = 'Đã nhận yêu cầu. Ảnh mới sẽ cập nhật tại thẻ này sau khi hoàn tất.';
                            var reviewCard = button.closest('.aseo-image-review-card');
                            if (reviewCard) reviewCard.classList.add('is-generating');
                            var postId = button.getAttribute('data-post-id') || '';
                            var checks = 0;
                            var checkInline = function() {
                                checks++;
                                var statusBody = new URLSearchParams();
                                statusBody.append('action', 'agent_seo_image_status');
                                statusBody.append('nonce', '<?php echo esc_js(wp_create_nonce('agent_seo_image_status')); ?>');
                                statusBody.append('post_id', postId);
                                fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:statusBody.toString()})
                                    .then(function(response) { return response.json(); })
                                    .then(function(state) {
                                        var data = state && state.success ? state.data : null;
                                        if (data && data.inline_url) {
                                            var image = reviewCard ? reviewCard.querySelector('.aseo-image-review-visual img') : null;
                                            if (image) image.src = data.inline_url + (data.inline_url.indexOf('?') >= 0 ? '&' : '?') + 'aseo_refresh=' + Date.now();
                                            if (reviewCard) reviewCard.classList.remove('is-generating');
                                            button.disabled = false;
                                            button.classList.remove('is-queued');
                                            button.textContent = 'Sửa ảnh phụ theo prompt';
                                            if (feedback) feedback.textContent = 'Ảnh phụ mới đã sẵn sàng.';
                                            return;
                                        }
                                        if (checks < 36) window.setTimeout(checkInline, 5000);
                                        else if (feedback) feedback.textContent = 'Ảnh vẫn đang xử lý nền. Tải lại trang sau để xem kết quả.';
                                    })
                                    .catch(function() { if (checks < 36) window.setTimeout(checkInline, 5000); });
                            };
                            window.setTimeout(checkInline, 5000);
                        })
                        .catch(function(error) {
                            window.alert(error.message || 'Lỗi kết nối khi sửa ảnh phụ.');
                            button.disabled = false;
                            button.classList.remove('is-queued');
                            button.textContent = 'Sửa ảnh phụ theo prompt';
                            if (feedback) feedback.textContent = error.message || 'Không thể sửa ảnh phụ.';
                        });
                });
            });

            document.querySelectorAll('.aseo-retry-image').forEach(function(button) {
                button.addEventListener('click', function() {
                    var postId = button.getAttribute('data-post-id');
                    if (!postId) return;
                    var originalButtonText = button.textContent;
                    var isEditImage = button.getAttribute('data-edit-image') === '1';
                    var isBulkImage = button.getAttribute('data-bulk-image') === '1';
                    var feedback = button.parentElement ? button.parentElement.querySelector('.aseo-image-action-feedback') : null;
                    var editPrompt = '';
                    if (isEditImage) {
                        var editBox = button.closest('.aseo-image-review-actions, .aseo-image-edit-box, .aseo-image-work-actions');
                        var editPromptField = editBox ? editBox.querySelector('.aseo-image-edit-prompt') : null;
                        editPrompt = editPromptField ? editPromptField.value.trim() : '';
                        if (!editPrompt) {
                            window.alert('Hãy nhập yêu cầu cần sửa trên ảnh.');
                            if (feedback) feedback.textContent = 'Hãy nhập yêu cầu cần sửa trên ảnh.';
                            if (editPromptField) editPromptField.focus();
                            return;
                        }
                    }
                    button.disabled = true;
                    button.classList.add('is-queued');
                    button.textContent = isEditImage ? '🎨 Đang sửa ảnh...' : '🎨 Đang vẽ ảnh AI...';
                    if (feedback) feedback.textContent = isEditImage ? 'Đang gửi prompt sửa ảnh…' : 'Đang gửi yêu cầu tạo ảnh…';
                    var body = new URLSearchParams();
                    body.append('action', 'agent_seo_retry_image');
                    body.append('nonce', '<?php echo esc_js(wp_create_nonce('agent_seo_retry_image')); ?>');
                    body.append('post_id', postId);
                    if (editPrompt) body.append('edit_prompt', editPrompt);
                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body: body.toString()})
                        .then(function(response) { return response.json(); })
                        .then(function(payload) {
                            if (payload.success) {
                                if (payload.data && payload.data.queued) {
                                    button.textContent = 'Đang tạo ảnh…';
                                    button.classList.add('is-queued');
                                    if (feedback) feedback.textContent = 'Đã nhận yêu cầu. Worker ảnh đang chạy nền…';
                                    var reviewCard = button.closest('.aseo-image-review-card');
                                    if (reviewCard) reviewCard.classList.add('is-generating');
                                    var acceptButton = reviewCard ? reviewCard.querySelector('.aseo-accept-image') : null;
                                    if (acceptButton) acceptButton.disabled = true;
                                    var articleRow = document.querySelector('#aseo-image-stage .aseo-stage-article-row[data-post-id="' + postId + '"]');
                                    if (articleRow) {
                                        articleRow.classList.add('is-generating');
                                        var rowStatus = articleRow.querySelector('.aseo-stage-article-row-copy span');
                                        if (rowStatus) {
                                            rowStatus.className = 'aseo-image-work-status is-running';
                                            rowStatus.textContent = 'Đang tạo ảnh';
                                        }
                                    }
                                    pollImageStatus(postId, feedback, button, isBulkImage ? advanceBulkImage : null);
                                    return;
                                }
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
                                var errorMessage = payload.data && payload.data.message ? payload.data.message : 'Tạo ảnh thất bại';
                                alert(errorMessage);
                                if (feedback) feedback.textContent = errorMessage;
                                button.textContent = originalButtonText;
                                button.disabled = false;
                                if (isBulkImage) advanceBulkImage({status:'failed', button:button});
                            }
                        })
                        .catch(function() {
                            alert('Lỗi kết nối khi gửi yêu cầu vẽ ảnh.');
                            if (feedback) feedback.textContent = 'Lỗi kết nối khi gửi yêu cầu vẽ ảnh.';
                            button.textContent = originalButtonText;
                            button.disabled = false;
                            if (isBulkImage) advanceBulkImage({status:'failed', button:button});
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
            var progressTimerEl = document.getElementById('aseo-progress-timer');
            var stopBatchButton = document.getElementById('aseo-stop-batch');
            var progressTimer = null;
            var liveTimerInterval = null;
            var currentBatchStartedAt = 0;
            var currentBatchFinishedAt = 0;
            var currentBatchStatus = '';
            var completionReloadQueued = false;

            function formatTimer(totalSeconds) {
                if (isNaN(totalSeconds) || totalSeconds < 0) totalSeconds = 0;
                var mins = Math.floor(totalSeconds / 60);
                var secs = totalSeconds % 60;
                var hours = Math.floor(mins / 60);
                mins = mins % 60;
                var pad = function(n) { return (n < 10 ? '0' : '') + n; };
                if (hours > 0) {
                    return pad(hours) + ':' + pad(mins) + ':' + pad(secs);
                }
                return pad(mins) + ':' + pad(secs);
            }

            function updateTimerDisplay() {
                if (!progressTimerEl) return;
                if (!currentBatchStartedAt) {
                    progressTimerEl.textContent = '⏱️ --:--';
                    return;
                }
                var nowSecs = Math.floor(Date.now() / 1000);
                var elapsed = 0;
                if (currentBatchStatus === 'complete' || currentBatchStatus === 'failed' || currentBatchStatus === 'stopped') {
                    var endSecs = currentBatchFinishedAt > 0 ? currentBatchFinishedAt : nowSecs;
                    elapsed = Math.max(0, endSecs - currentBatchStartedAt);
                } else {
                    elapsed = Math.max(0, nowSecs - currentBatchStartedAt);
                }
                progressTimerEl.textContent = '⏱️ ' + formatTimer(elapsed);
            }

            function renderBatchProgress(batch) {
                if (!progressCard || !batch || batch.status === 'idle') return;
                var previousStatus = currentBatchStatus;
                progressCard.className = 'aseo-progress-card visible ' + batch.status;
                progressFill.style.width = (batch.percent || 0) + '%';
                progressCount.textContent = (batch.completed || 0) + '/' + (batch.total || 0) + ' bài';
                progressMessage.textContent = (batch.status === 'complete' && (!batch.message || /^Đang viết/i.test(batch.message)))
                    ? 'Đã hoàn tất nội dung và các tác vụ ảnh.' : (batch.message || '');
                
                currentBatchStartedAt = parseInt(batch.started_at, 10) || 0;
                currentBatchFinishedAt = parseInt(batch.finished_at, 10) || 0;
                currentBatchStatus = batch.status || '';
                var batchIsActive = ['queued', 'running', 'waiting', 'images_pending'].indexOf(currentBatchStatus) !== -1;
                if (stopBatchButton) {
                    stopBatchButton.style.display = batchIsActive ? '' : 'none';
                    stopBatchButton.disabled = false;
                    stopBatchButton.textContent = '■ Dừng tiến trình';
                }
                updateTimerDisplay();

                if (batch.status === 'complete' || batch.status === 'failed' || batch.status === 'stopped') {
                    if (liveTimerInterval) { window.clearInterval(liveTimerInterval); liveTimerInterval = null; }
                } else if (!liveTimerInterval && currentBatchStartedAt > 0) {
                    liveTimerInterval = window.setInterval(updateTimerDisplay, 1000);
                }

                if (batch.status === 'complete') {
                    progressLabel.textContent = 'Danh sách bài đã sẵn sàng';
                    if (
                        !completionReloadQueued
                        && ['queued', 'running', 'waiting', 'images_pending'].indexOf(previousStatus) !== -1
                    ) {
                        completionReloadQueued = true;
                        window.setTimeout(function() { window.location.reload(); }, 1200);
                    }
                } else if (batch.status === 'failed') {
                    progressLabel.textContent = 'Tạo bài gặp lỗi';
                } else if (batch.status === 'stopped') {
                    progressLabel.textContent = 'Đã dừng tiến trình';
                } else if (batch.status === 'images_pending') {
                    progressLabel.textContent = 'Đang hoàn thiện ảnh và bố cục bài';
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
                    if (payload.data.status === 'complete' || payload.data.status === 'failed' || payload.data.status === 'stopped' || payload.data.status === 'idle') {
                        if (progressTimer) window.clearInterval(progressTimer);
                    }
                }).catch(function() {});
            }

            if (progressCard) {
                currentBatchStartedAt = parseInt(progressCard.getAttribute('data-started'), 10) || 0;
                currentBatchFinishedAt = parseInt(progressCard.getAttribute('data-finished'), 10) || 0;
                if (progressCard.classList.contains('complete') || progressCard.classList.contains('failed') || progressCard.classList.contains('stopped')) {
                    currentBatchStatus = progressCard.classList.contains('complete')
                        ? 'complete'
                        : (progressCard.classList.contains('stopped') ? 'stopped' : 'failed');
                } else if (progressCard.classList.contains('visible')) {
                    currentBatchStatus = 'running';
                }
                updateTimerDisplay();
                if (progressCard.classList.contains('queued') || progressCard.classList.contains('running') || progressCard.classList.contains('waiting') || progressCard.classList.contains('images_pending')) {
                    if (!liveTimerInterval && currentBatchStartedAt > 0) {
                        liveTimerInterval = window.setInterval(updateTimerDisplay, 1000);
                    }
                    pollBatchProgress();
                    progressTimer = window.setInterval(pollBatchProgress, 5000);
                }
            }

            if (stopBatchButton) {
                stopBatchButton.addEventListener('click', function() {
                    if (!window.confirm('Dừng tiến trình hiện tại? Bài và ảnh đã hoàn tất sẽ được giữ lại.')) return;
                    stopBatchButton.disabled = true;
                    stopBatchButton.textContent = 'Đang dừng...';
                    var body = new URLSearchParams();
                    body.append('action', 'agent_seo_stop_batch');
                    body.append('nonce', '<?php echo esc_js(wp_create_nonce('agent_seo_stop_batch')); ?>');
                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: body.toString()
                    }).then(function(response) {
                        return response.json();
                    }).then(function(payload) {
                        if (!payload.success) {
                            throw new Error(payload.data && payload.data.message ? payload.data.message : 'Không thể dừng tiến trình.');
                        }
                        renderBatchProgress(payload.data);
                        if (progressTimer) { window.clearInterval(progressTimer); progressTimer = null; }
                    }).catch(function(error) {
                        stopBatchButton.disabled = false;
                        stopBatchButton.textContent = '■ Dừng tiến trình';
                        window.alert(error.message || 'Không thể dừng tiến trình.');
                    });
                });
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
            var briefChatSubmit = document.getElementById('aseo-brief-chat-submit');
            var briefChatInput = document.getElementById('aseo-brief-chat-input');
            var briefChatStatus = document.getElementById('aseo-brief-chat-status');
            var briefPromptSubmit = document.getElementById('aseo-brief-prompt-submit');
            if (briefPromptSubmit) {
                briefPromptSubmit.addEventListener('click', function() {
                    var request = briefChatInput ? briefChatInput.value.trim() : '';
                    var masterBrief = document.getElementById('aseo_master_prompt_brief');
                    if (!request) { if (briefChatInput) briefChatInput.focus(); return; }
                    if (!masterBrief || !generateMasterButton) {
                        if (briefChatStatus) briefChatStatus.textContent = 'Không tìm thấy cấu hình prompt.';
                        return;
                    }
                    masterBrief.value = request;
                    masterBrief.dispatchEvent(new Event('input', {bubbles:true}));
                    if (briefChatStatus) briefChatStatus.textContent = 'AI đang tạo prompt viết và ảnh...';
                    briefPromptSubmit.disabled = true;
                    generateMasterButton.click();
                });
            }
            if (briefChatSubmit && briefChatInput) {
                briefChatSubmit.addEventListener('click', function() {
                    var request = briefChatInput.value.trim();
                    if (!request) { briefChatInput.focus(); return; }
                    var assistantKeyword = document.getElementById('aseo-brief-compact-keyword');
                    var primaryKeyword = assistantKeyword ? assistantKeyword.value.trim() : '';
                    if (primaryKeyword) {
                        request += '\n\nTỪ KHÓA CHÍNH BẮT BUỘC CHO TOÀN BỘ BÀI VIẾT: ' + primaryKeyword;
                    }
                    briefChatSubmit.disabled = true;
                    briefChatSubmit.textContent = 'AI đang phân tích...';
                    if (briefChatStatus) briefChatStatus.textContent = 'Đang lấy thông tin website và chuẩn bị brief...';
                    var body = new URLSearchParams();
                    body.append('action', 'agent_seo_prepare_assistant');
                    body.append('nonce', '<?php echo esc_js(wp_create_nonce('agent_seo_prepare_assistant')); ?>');
                    body.append('brief', request);
                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:body.toString()})
                        .then(function(response){ return response.json(); })
                        .then(function(payload){
                            if (!payload.success) throw new Error(payload.data && payload.data.message ? payload.data.message : 'AI không thể phân tích yêu cầu.');
                            var data = payload.data || {};
                            var values = data.brief || {};
                            values = {topic:values.topic || '', location:values.location || '', intent:values.intent || '', reference:values.backlink || '', secondary:values.secondary_keywords || '', instructions:values.instructions || ''};
                            Object.keys(values).forEach(function(key){
                                var field = briefFields && briefFields[key]
                                    ? briefFields[key]
                                    : document.getElementById('aseo_article_' + key);
                                if (!field) return;
                                field.value = values[key];
                                field.dispatchEvent(new Event('input', {bubbles:true}));
                            });
                            if (typeof syncArticleBrief === 'function') syncArticleBrief();
                            var promptPreview = document.getElementById('aseo-article-prompt-preview');
                            if (!promptPreview) {
                                promptPreview = document.createElement('div');
                                promptPreview.id = 'aseo-article-prompt-preview';
                                promptPreview.className = 'aseo-article-prompt-preview';
                                document.getElementById('aseo-brief-chat').appendChild(promptPreview);
                            }
                            promptPreview.innerHTML = '<strong>Prompt viết & ảnh đã sẵn sàng</strong><code>' + String(data.master_prompt || '').replace(/[&<>]/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;'}[c];}) + '</code>';
                            if (briefChatStatus) briefChatStatus.textContent = data.company_profile_refreshed
                                ? '✓ Đã cập nhật thông tin website và chuẩn bị xong brief/prompt.'
                                : '✓ Đã chuẩn bị xong brief/prompt (dùng thông tin doanh nghiệp đã lưu).';
                            var createSection = document.getElementById('aseo-create-section');
                            if (createSection) createSection.scrollIntoView({behavior:'smooth', block:'start'});
                        })
                        .catch(function(error){ if (briefChatStatus) briefChatStatus.textContent = error.message; })
                        .finally(function(){ briefChatSubmit.disabled = false; briefChatSubmit.textContent = '✨ AI chuẩn bị brief & prompt'; });
                });
            }
            var briefPreviewButton = document.getElementById('aseo-preview-brief');
            var briefPreview = document.getElementById('aseo-outline-preview');
            var briefFieldsPanel = document.getElementById('aseo-brief-fields');
            var briefFieldsToggle = document.getElementById('aseo-toggle-brief-fields');
            if (briefFieldsPanel && briefFieldsToggle) {
                briefFieldsToggle.addEventListener('click', function() {
                    var isCollapsed = briefFieldsPanel.classList.toggle('aseo-brief-fields-collapsed');
                    briefFieldsToggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
                    briefFieldsToggle.textContent = isCollapsed ? 'Chỉnh sửa brief' : 'Thu gọn brief';
                });
            }
            var briefFields = {
                topic: document.getElementById('aseo_article_topic'),
                location: document.getElementById('aseo_article_location'),
                intent: document.getElementById('aseo_article_intent'),
                reference: document.getElementById('aseo_article_reference'),
                instructions: document.getElementById('aseo_article_instructions'),
                secondary: document.getElementById('aseo_article_secondary')
            };
            var compactKeyword = document.getElementById('aseo-brief-compact-keyword');
            function updateCompactKeyword() {
                if (compactKeyword && briefFields.topic && document.activeElement !== compactKeyword) compactKeyword.value = briefFields.topic.value || '';
            }
            if (briefFields.topic) {
                briefFields.topic.addEventListener('input', updateCompactKeyword);
                updateCompactKeyword();
            }
            if (compactKeyword) {
                compactKeyword.addEventListener('input', function() {
                    if (!briefFields.topic) return;
                    briefFields.topic.value = compactKeyword.value;
                    briefFields.topic.dispatchEvent(new Event('input', {bubbles:true}));
                });
            }
            function syncArticleBrief() {
                Object.keys(briefFields).forEach(function(key) {
                    var hidden = document.getElementById('aseo_article_' + key + '_run');
                    if (hidden && briefFields[key]) hidden.value = briefFields[key].value.trim();
                });
                ['title', 'outline'].forEach(function(key) {
                    var previewField = document.getElementById('aseo_article_' + key + '_preview');
                    var hidden = document.getElementById('aseo_article_' + key + '_run');
                    if (previewField && hidden) hidden.value = previewField.value.trim();
                });
            }
            if (briefPreviewButton) {
                briefPreviewButton.addEventListener('click', function() {
                    var topic = briefFields.topic ? briefFields.topic.value.trim() : '';
                    if (!topic) {
                        if (briefFields.topic) briefFields.topic.focus();
                        window.alert('Vui lòng nhập chủ đề hoặc từ khóa cho bài viết.');
                        return;
                    }
                    var location = briefFields.location ? briefFields.location.value.trim() : '';
                    var intent = briefFields.intent ? briefFields.intent.value : '';
                    var reference = briefFields.reference ? briefFields.reference.value.trim() : '';
                    var safeTopic = topic.replace(/[&<>\"']/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#039;'}[c];});
                    var title = topic.charAt(0).toUpperCase() + topic.slice(1) + (location ? ' tại ' + location : '') + ': Hướng dẫn chi tiết';
                    var html = '<strong>Bước 2 — Duyệt tiêu đề & dàn ý</strong><label class="aseo-create-control"><span>Tiêu đề bài viết</span><input type="text" id="aseo_article_title_preview" value="' + title.replace(/\"/g,'&quot;') + '"></label>';
                    html += '<label class="aseo-create-control"><span>Dàn ý có thể chỉnh sửa (mỗi dòng một H2/H3)</span><textarea id="aseo_article_outline_preview" rows="5">H2. ' + safeTopic + '\nH2. Tiêu chí lựa chọn và thông tin cần biết\nH2. Hướng dẫn thực tế cho người mua\nH2. Câu hỏi thường gặp (FAQ)\nH2. Kết luận và CTA</textarea></label>';
                    html += '<small>Ý định: ' + intent + (reference ? ' · Backlink: ' + reference : '') + '</small>';
                    briefPreview.innerHTML = html;
                    briefPreview.classList.add('is-visible');
                    syncArticleBrief();
                    var titlePreview = document.getElementById('aseo_article_title_preview');
                    var outlinePreview = document.getElementById('aseo_article_outline_preview');
                    if (titlePreview) titlePreview.addEventListener('input', syncArticleBrief);
                    if (outlinePreview) outlinePreview.addEventListener('input', syncArticleBrief);
                });
            }
            document.querySelectorAll('form input[name="aseo_action"][value="force_run"]').forEach(function(actionInput) {
                var runForm = actionInput.closest('form');
                if (!runForm) return;
                runForm.addEventListener('submit', function() {
                    syncArticleBrief();
                    var keywordBridge = runForm.querySelector('.aseo-primary-keyword-run');
                    if (keywordBridge && primaryKeywordInput) keywordBridge.value = primaryKeywordInput.value.trim();
                });
            });

            var autosaveStatus = document.getElementById('aseo-autosave-status');
            if (autosaveStatus) autosaveStatus.textContent = 'Tự lưu khi thay đổi · bấm Lưu để chắc chắn';
            var settingsForm = document.getElementById('aseo-settings-form');
            var autosaveTimers = {};
            var autosaveFields = document.querySelectorAll('#aseo-settings-form input[name], #aseo-settings-form textarea[name], #aseo-settings-form select[name], #aseo_image_aspect_ratio');
            var autosaveNames = {
                'aseo_primary_keyword': true, 'aseo_secondary_keywords': true, 'aseo_keywords': true,
                'aseo_niche': true, 'aseo_brand_voice': true, 'aseo_master_prompt_brief': true,
                'aseo_master_prompt': true, 'aseo_cron_interval': true, 'aseo_brand_name': true,
                'aseo_brand_address': true, 'aseo_brand_phone': true, 'aseo_brand_contact': true,
                'aseo_brand_price': true, 'aseo_brand_cta': true, 'aseo_gsc_property': true,
                'aseo_gsc_sitemap_url': true, 'aseo_reference_image_id': true,
                'aseo_gemini_api_key': true, 'aseo_nvidia_api_key': true,
                'aseo_duky_api_key': true, 'aseo_duky_model': true, 'aseo_kaggle_api_url': true,
                'aseo_image_engine': true, 'aseo_indexnow_key': true,
                'aseo_enable_inline_images': true, 'aseo_image_aspect_ratio': true,
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
                    saveBody.append('value', field.type === 'checkbox' ? (field.checked ? '1' : '0') : field.value);
                    fetch('<?php echo esc_url(admin_url('admin-ajax.php')); ?>', {
                        method: 'POST', credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: saveBody.toString()
                    }).then(function(response) { return response.json(); }).then(function(payload) {
                        if (autosaveStatus) { autosaveStatus.textContent = payload.success ? '✓ Đã tự lưu' : 'Không thể tự lưu'; autosaveStatus.className = 'aseo-autosave-status ' + (payload.success ? 'saved' : ''); }
                        if (
                            payload.success
                            && field.name === 'aseo_gemini_api_key'
                            && String(field.value || '').trim() !== ''
                            && companyProfileAutoAllowed
                            && !companyProfileLoaded
                        ) {
                            window.setTimeout(loadCompanyProfile, 150);
                        }
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
