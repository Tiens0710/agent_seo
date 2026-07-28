<?php
/**
 * Trình điều phối chính quy trình SEO (AI Orchestrator)
 */

defined('ABSPATH') || exit;

class Agent_SEO_Orchestrator {

    public function __construct() {
        // Lắng nghe sự kiện chạy ngầm từ WP-Cron
        add_action('agent_seo_cron_hook', array($this, 'run_scheduled_task'));
        add_action('agent_seo_background_task', array($this, 'run_background_task'), 10, 2);
        add_action('agent_seo_image_retry_task', array($this, 'run_image_retry_task'), 10, 1);
        add_action('agent_seo_inline_image_task', array($this, 'run_inline_image_task'), 10, 1);
        add_action('agent_seo_index_notify_task', array($this, 'notify_indexnow'), 10, 1);
        add_action('agent_seo_gsc_sitemap_task', array('Agent_SEO_GSC', 'submit_sitemap'));
        
        // Thêm lịch kiểm tra thay đổi chu kỳ Cron khi lưu cấu hình
        add_action('update_option_aseo_cron_interval', array($this, 'reschedule_cron_on_update'), 10, 2);
        add_action('transition_post_status', array($this, 'queue_index_notification'), 10, 3);
        add_action('init', array($this, 'serve_indexnow_key'));
    }

    public function serve_indexnow_key() {
        if (!isset($_GET['agent_seo_indexnow_key'])) {
            return;
        }
        $key = trim(get_option('aseo_indexnow_key', ''));
        if (empty($key)) {
            status_header(404);
            exit;
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo esc_html($key);
        exit;
    }

    public function queue_index_notification($new_status, $old_status, $post) {
        if ($new_status !== 'publish' || $old_status === 'publish' || empty($post->ID) || $post->post_type !== 'post') {
            return;
        }
        if (!get_post_meta($post->ID, '_agent_seo_generated', true)) {
            return;
        }
        if (!wp_next_scheduled('agent_seo_index_notify_task', array($post->ID))) {
            wp_schedule_single_event(time() + 10, 'agent_seo_index_notify_task', array($post->ID));
        }
        if (!wp_next_scheduled('agent_seo_gsc_sitemap_task')) {
            wp_schedule_single_event(time() + 15, 'agent_seo_gsc_sitemap_task');
        }
    }

    public function notify_indexnow($post_id) {
        $key = trim(get_option('aseo_indexnow_key', ''));
        $url = get_permalink($post_id);
        if (empty($key) || empty($url)) {
            return;
        }
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $response = wp_remote_post('https://api.indexnow.org/indexnow', array(
            'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
            'body' => wp_json_encode(array(
                'host' => $host,
                'key' => $key,
                'keyLocation' => add_query_arg('agent_seo_indexnow_key', '1', home_url('/')),
                'urlList' => array($url)
            )),
            'timeout' => 20
        ));
        if (!is_wp_error($response) && in_array(wp_remote_retrieve_response_code($response), array(200, 202), true)) {
            update_post_meta($post_id, '_agent_seo_indexnow_sent_at', time());
        } else {
            error_log('Agent SEO IndexNow notification failed for post ID ' . intval($post_id));
        }
    }

    /**
     * Worker chạy một bài trong request WP-Cron riêng, tránh làm timeout trang quản trị.
     */
    public function run_scheduled_task() {
        $batch = get_option('aseo_batch_status', array());
        $active_states = array('queued', 'running', 'waiting', 'images_pending');
        if (
            (is_array($batch) && isset($batch['status']) && in_array($batch['status'], $active_states, true))
            || $this->is_generation_locked()
        ) {
            return;
        }
        return $this->run_single_task();
    }

    public function run_background_task($requested_status = 'draft', $remaining = 1) {
        @set_time_limit(720);
        @ini_set('max_execution_time', '720');
        $batch_before_lock = get_option('aseo_batch_status', array());
        if (is_array($batch_before_lock) && isset($batch_before_lock['status']) && $batch_before_lock['status'] === 'stopped') {
            return;
        }
        // add_option() là thao tác nguyên tử ở database, nên hai request WP-Cron
        // đến cùng lúc cũng chỉ có đúng một worker được quyền tạo bài.
        $generation_lock_token = $this->acquire_generation_lock();
        if ($generation_lock_token === false) {
            // Không tự schedule lại event với remaining cũ vì đây là nguồn gây
            // tạo dư bài. AJAX status sẽ kick lại đúng remaining trong option.
            return;
        }
        $batch = get_option('aseo_batch_status', array());
        $active_states = array('queued', 'running', 'waiting', 'images_pending');
        $batch_status = is_array($batch) && isset($batch['status']) ? $batch['status'] : '';
        $batch_total = is_array($batch) && isset($batch['total']) ? max(0, intval($batch['total'])) : 0;
        $batch_completed = is_array($batch) && isset($batch['completed']) ? max(0, intval($batch['completed'])) : 0;
        $remaining = is_array($batch) && isset($batch['remaining'])
            ? max(0, intval($batch['remaining']))
            : max(0, $batch_total - $batch_completed);
        if (!in_array($batch_status, $active_states, true) || $batch_total <= 0 || $remaining <= 0 || $batch_completed >= $batch_total) {
            $this->release_generation_lock($generation_lock_token);
            return;
        }
        $requested_status = isset($batch['requested_status']) && $batch['requested_status'] === 'draft' ? 'draft' : 'publish';
        if (is_array($batch) && isset($batch['total'])) {
            $current = min($batch_total, $batch_completed + 1);
            $batch['status'] = 'running';
            $batch['current'] = $current;
            $batch['remaining'] = $remaining;
            $batch['requested_status'] = $requested_status;
            $batch['message'] = 'Đang viết nội dung bài ' . $current . '/' . intval($batch['total']) . '...';
            $batch['updated_at'] = time();
            update_option('aseo_batch_status', $batch, false);
        }
        $product_id_override = is_array($batch) && isset($batch['product_id']) ? absint($batch['product_id']) : 0;
        $batch_started_at = is_array($batch) && isset($batch['started_at']) ? intval($batch['started_at']) : 0;
        $result = $this->run_single_task($requested_status, $product_id_override, $batch_started_at);
        $batch_after_task = get_option('aseo_batch_status', array());
        if (
            is_array($batch_after_task)
            && isset($batch_after_task['status'], $batch_after_task['started_at'])
            && $batch_after_task['status'] === 'stopped'
            && intval($batch_after_task['started_at']) === $batch_started_at
        ) {
            $this->release_generation_lock($generation_lock_token);
            return;
        }
        if (!$result['success']) {
            if (is_array($batch)) {
                $batch['status'] = 'failed';
                $batch['message'] = 'Tác vụ bị lỗi: ' . $result['message'];
                $batch['updated_at'] = time();
                $batch['finished_at'] = time();
                update_option('aseo_batch_status', $batch, false);
            }
            $this->release_generation_lock($generation_lock_token);
            error_log('Agent SEO Background Task Error: ' . $result['message']);
            return;
        }

        $remaining = max(0, intval($remaining) - 1);
        // Image worker có thể cập nhật pending list trong lúc Gemini đang viết.
        // Nạp lại trạng thái mới nhất để tránh ghi đè kết quả ảnh vừa hoàn tất.
        $latest_batch = get_option('aseo_batch_status', array());
        if (
            is_array($latest_batch)
            && isset($latest_batch['status'], $latest_batch['started_at'], $batch['started_at'])
            && $latest_batch['status'] === 'stopped'
            && intval($latest_batch['started_at']) === intval($batch['started_at'])
        ) {
            $this->release_generation_lock($generation_lock_token);
            return;
        }
        if (
            is_array($latest_batch)
            && isset($latest_batch['started_at'], $batch['started_at'])
            && intval($latest_batch['started_at']) !== intval($batch['started_at'])
        ) {
            // Người dùng đã bắt đầu một batch mới khi worker cũ còn chạy.
            // Giữ bài vừa tạo nhưng không cho worker cũ ghi đè/schedule vào batch mới.
            $this->release_generation_lock($generation_lock_token);
            return;
        }
        if (
            is_array($latest_batch)
            && isset($latest_batch['started_at'], $batch['started_at'])
            && intval($latest_batch['started_at']) === intval($batch['started_at'])
        ) {
            $batch = array_merge($batch, $latest_batch);
        }
        if (is_array($batch) && isset($batch['total'])) {
            $batch['remaining'] = $remaining;
            $batch['completed'] = max(intval(isset($batch['completed']) ? $batch['completed'] : 0), intval($batch['current']));
            $batch['last_post_id'] = intval($result['post_id']);
            $batch['last_title'] = sanitize_text_field($result['title']);
            $post_ids = isset($batch['post_ids']) && is_array($batch['post_ids']) ? $batch['post_ids'] : array();
            $post_ids[] = intval($result['post_id']);
            $batch['post_ids'] = array_values(array_unique(array_filter(array_map('absint', $post_ids))));
            // Nội dung được lưu nháp thành danh sách. Không yêu cầu duyệt nội
            // dung; API ảnh chỉ chạy khi người dùng bấm “Tạo ảnh” cho một bài.
            $batch['review_required_posts'] = array();
            $batch['updated_at'] = time();
            if (!empty($result['image_warning'])) {
                $result_post_id = intval($result['post_id']);
                $featured_id = get_post_thumbnail_id($result_post_id);
                $featured_job = get_post_meta($result_post_id, '_agent_seo_image_job', true);
                $inline_ids = get_post_meta($result_post_id, '_agent_seo_inline_image_ids', true);
                $inline_ids = is_array($inline_ids) ? array_values(array_filter(array_map('absint', $inline_ids))) : array();
                $inline_job = get_post_meta($result_post_id, '_agent_seo_inline_image_job', true);
                if (empty($featured_id) && is_array($featured_job) && !empty($featured_job['prompt'])) {
                    $pending_images = isset($batch['pending_images']) && is_array($batch['pending_images']) ? $batch['pending_images'] : array();
                    $pending_images[] = $result_post_id;
                    $batch['pending_images'] = array_values(array_unique($pending_images));
                    if (isset($batch['pending_inline_images']) && is_array($batch['pending_inline_images'])) {
                        $batch['pending_inline_images'] = array_values(array_filter($batch['pending_inline_images'], function($id) use ($result_post_id) {
                            return intval($id) !== $result_post_id;
                        }));
                    }
                } elseif (count($inline_ids) < 1 && is_array($inline_job) && !empty($inline_job['prompt'])) {
                    $pending_inline = isset($batch['pending_inline_images']) && is_array($batch['pending_inline_images']) ? $batch['pending_inline_images'] : array();
                    $pending_inline[] = $result_post_id;
                    $batch['pending_inline_images'] = array_values(array_unique($pending_inline));
                    if (isset($batch['pending_images']) && is_array($batch['pending_images'])) {
                        $batch['pending_images'] = array_values(array_filter($batch['pending_images'], function($id) use ($result_post_id) {
                            return intval($id) !== $result_post_id;
                        }));
                    }
                }
            }
            $batch['remaining'] = $remaining;
            $batch['requested_status'] = $requested_status;
            if ($remaining > 0) {
                $batch['status'] = 'waiting';
                $batch['message'] = 'Đã lưu bản nháp ' . intval($batch['completed']) . '/' . intval($batch['total']) . '. Đang chuẩn bị nội dung bài tiếp theo...';
            } else {
                $batch['completed'] = intval($batch['total']);
                $total_pending = intval(!empty($batch['pending_images']) ? count($batch['pending_images']) : 0) + intval(!empty($batch['pending_inline_images']) ? count($batch['pending_inline_images']) : 0);
                if ($total_pending > 0) {
                    $batch['status'] = 'images_pending';
                    $batch['message'] = 'Đã viết xong ' . intval($batch['total']) . ' bài. Đang hoàn thiện ' . $total_pending . ' bài chưa đủ ảnh...';
                } else {
                    $batch['status'] = 'complete';
                    $failed_count = !empty($batch['image_errors']) && is_array($batch['image_errors'])
                        ? count($batch['image_errors']) : 0;
                    $batch['message'] = $failed_count > 0
                        ? 'Đã tạo xong ' . intval($batch['total']) . ' bài; có ' . $failed_count . ' bài chưa đủ ảnh sau các lần thử.'
                        : 'Đã tạo xong ' . intval($batch['total']) . ' bài viết. Chọn bài trong danh sách rồi bấm “Tạo ảnh”.';
                    $batch['finished_at'] = time();
                }
            }
            if ($this->is_batch_stopped($batch_started_at)) {
                $this->release_generation_lock($generation_lock_token);
                return;
            }
            update_option('aseo_batch_status', $batch, false);
        }
        // Viết bài tiếp theo độc lập với tiến trình tạo ảnh. Image worker có lock
        // riêng theo từng post nên không còn kéo dài hoặc chặn content worker.
        if ($remaining > 0 && !$this->is_batch_stopped($batch_started_at)) {
            wp_schedule_single_event(time() + 5, 'agent_seo_background_task', array($requested_status, $remaining));
        }
        $this->release_generation_lock($generation_lock_token);
    }

    /**
     * Khóa nguyên tử cho content worker. Giá trị token ngăn worker cũ vô tình
     * xóa khóa của worker mới. Khóa quá 15 phút được xem là request đã chết.
     */
    private function acquire_generation_lock() {
        $option_name = 'aseo_generation_lock';
        $now = time();
        $token = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('aseo_', true);
        $payload = array('token' => $token, 'created_at' => $now);

        // Tôn trọng worker vẫn đang chạy từ phiên bản dùng transient trước đây.
        if (get_transient('agent_seo_generation_lock')) {
            return false;
        }

        if (add_option($option_name, $payload, '', false)) {
            return $token;
        }

        $existing = get_option($option_name, array());
        $created_at = is_array($existing) && isset($existing['created_at'])
            ? intval($existing['created_at'])
            : 0;
        if ($created_at > 0 && ($now - $created_at) > 900) {
            delete_option($option_name);
            if (add_option($option_name, $payload, '', false)) {
                return $token;
            }
        }

        return false;
    }

    private function release_generation_lock($token) {
        $existing = get_option('aseo_generation_lock', array());
        if (is_array($existing) && isset($existing['token']) && hash_equals((string) $existing['token'], (string) $token)) {
            delete_option('aseo_generation_lock');
        }
        // Dọn khóa transient của phiên bản cũ sau khi worker hiện tại kết thúc.
        delete_transient('agent_seo_generation_lock');
    }

    private function is_generation_locked() {
        $existing = get_option('aseo_generation_lock', array());
        if (is_array($existing) && !empty($existing['created_at'])) {
            if ((time() - intval($existing['created_at'])) <= 900) {
                return true;
            }
            delete_option('aseo_generation_lock');
        }
        return (bool) get_transient('agent_seo_generation_lock');
    }

    public function run_image_retry_task($post_id) {
        @set_time_limit(600);
        @ini_set('max_execution_time', '600');
        $post_id = intval($post_id);
        if ($this->is_post_batch_stopped($post_id)) {
            return;
        }
        // Dùng lock riêng per-post thay vì generation_lock chung để retry ảnh
        // không bị chặn khi batch đang viết bài tiếp theo.
        if (get_transient('agent_seo_image_lock_' . $post_id)) {
            wp_schedule_single_event(time() + 45, 'agent_seo_image_retry_task', array($post_id));
            return;
        }
        $job = get_post_meta($post_id, '_agent_seo_image_job', true);
        if (!is_array($job) || empty($job['prompt'])) {
            return;
        }
        $force_image_retry = get_post_meta($post_id, '_agent_seo_force_image_retry', true) === '1';
        if ($post_id <= 0 || !get_post($post_id)) {
            return;
        }
        $existing_thumbnail_id = absint(get_post_thumbnail_id($post_id));
        $thumbnail_is_ai = $existing_thumbnail_id > 0
            && get_post_meta($existing_thumbnail_id, '_agent_seo_generated_image', true) === '1';

        // Chỉ attachment có dấu nguồn Agent SEO mới được phép làm ảnh đại diện.
        // Ảnh sản phẩm, ảnh Media tham chiếu hoặc thumbnail do hook ngoài gán
        // vào đều bị loại trước khi worker tạo ảnh mới.
        if ($existing_thumbnail_id > 0 && ($force_image_retry || !$thumbnail_is_ai)) {
            delete_post_thumbnail($post_id);
        } elseif ($existing_thumbnail_id > 0) {
            $this->clear_image_job($post_id);
            $this->check_and_progress_image_flow($post_id);
            return;
        }
        set_transient('agent_seo_image_lock_' . $post_id, 1, 300);
        $attempts = intval(get_post_meta($post_id, '_agent_seo_image_attempts', true));
        $attach_id = $this->generate_featured_image($post_id, $job, 'Đang xử lý ảnh đại diện' . $this->stage_progress_suffix());
        if ($this->is_post_batch_stopped($post_id)) {
            delete_transient('agent_seo_image_lock_' . $post_id);
            return;
        }
        if (is_wp_error($attach_id) && $attach_id->get_error_code() === 'agent_seo_image_pending') {
            $polls = intval(get_post_meta($post_id, '_agent_seo_image_polls', true)) + 1;
            update_post_meta($post_id, '_agent_seo_image_polls', $polls);
            delete_transient('agent_seo_image_lock_' . $post_id);
            if ($polls < 20) {
                wp_schedule_single_event(time() + 15, 'agent_seo_image_retry_task', array($post_id));
            } else {
                delete_post_meta($post_id, '_agent_seo_duky_media_id');
                delete_post_meta($post_id, '_agent_seo_image_polls');
                $attempts++;
                update_post_meta($post_id, '_agent_seo_image_attempts', $attempts);
                if ($attempts < 3) {
                    wp_schedule_single_event(time() + 30, 'agent_seo_image_retry_task', array($post_id));
                } else {
                    $this->clear_image_job($post_id);
                    $this->check_and_progress_image_flow($post_id);
                }
            }
            return;
        }
        delete_post_meta($post_id, '_agent_seo_image_polls');
        $attempts++;
        update_post_meta($post_id, '_agent_seo_image_attempts', $attempts);
        $ai_image_success = $this->ensure_featured_image($post_id, $attach_id);
        
        delete_transient('agent_seo_image_lock_' . $post_id);
        if ($ai_image_success) {
            $this->clear_image_job($post_id);
            delete_post_meta($post_id, '_agent_seo_force_image_retry');
            $this->check_and_progress_image_flow($post_id);
        } elseif ($attempts < 3) {
            // Tăng delay dần theo số lần thử: lần 1 → 45s, lần 2 → 60s
            $retry_delay = 30 + ($attempts * 15);
            wp_schedule_single_event(time() + $retry_delay, 'agent_seo_image_retry_task', array($post_id));
        } else {
            $this->clear_image_job($post_id); // Dọn dẹp job sau khi hết số lần thử mà vẫn lỗi AI
            $this->check_and_progress_image_flow($post_id);
        }
    }

    /** Tạo từng ảnh minh họa và chèn vào bài sau khi ảnh đại diện đã xong. */
    public function run_inline_image_task($post_id) {
        @set_time_limit(360);
        $post_id = absint($post_id);
        if ($post_id <= 0 || !get_post($post_id) || get_transient('agent_seo_inline_image_lock_' . $post_id)) {
            return;
        }
        if (!$this->inline_images_enabled()) {
            delete_post_meta($post_id, '_agent_seo_inline_image_job');
            delete_post_meta($post_id, '_agent_seo_inline_image_attempts');
            delete_post_meta($post_id, '_agent_seo_inline_image_polls');
            $this->check_and_progress_image_flow($post_id);
            return;
        }
        if ($this->is_post_batch_stopped($post_id)) {
            return;
        }
        // Featured và inline dùng chung DukyAI mediaId. Không cho ảnh phụ chạy
        // trước ảnh đại diện, nếu không hai worker có thể nhận nhầm kết quả.
        $featured_id = get_post_thumbnail_id($post_id);
        $featured_job = get_post_meta($post_id, '_agent_seo_image_job', true);
        if (empty($featured_id) && is_array($featured_job) && !empty($featured_job['prompt'])) {
            if (!wp_next_scheduled('agent_seo_inline_image_task', array($post_id))) {
                wp_schedule_single_event(time() + 30, 'agent_seo_inline_image_task', array($post_id));
            }
            return;
        }
        $job = get_post_meta($post_id, '_agent_seo_inline_image_job', true);
        if (!is_array($job) || empty($job['prompt'])) {
            $job = get_post_meta($post_id, '_agent_seo_image_job', true);
        }
        $inline_ids = get_post_meta($post_id, '_agent_seo_inline_image_ids', true);
        $inline_ids = is_array($inline_ids) ? array_values(array_filter(array_map('absint', $inline_ids))) : array();
        if (count($inline_ids) >= 1) {
            delete_post_meta($post_id, '_agent_seo_inline_image_job');
            delete_post_meta($post_id, '_agent_seo_inline_image_attempts');
            delete_post_meta($post_id, '_agent_seo_inline_image_polls');
            $this->check_and_progress_image_flow($post_id);
            return;
        }
        if (!is_array($job) || empty($job['prompt'])) {
            $this->check_and_progress_image_flow($post_id);
            return;
        }
        // Ảnh phụ ưu tiên tốc độ: tối đa 2 lần, mỗi lần poll khoảng 2 phút.
        $inline_attempts = intval(get_post_meta($post_id, '_agent_seo_inline_image_attempts', true));
        $max_inline_attempts = 2;
        if ($inline_attempts >= $max_inline_attempts) {
            delete_post_meta($post_id, '_agent_seo_inline_image_job');
            delete_post_meta($post_id, '_agent_seo_inline_image_attempts');
            $this->check_and_progress_image_flow($post_id);
            return;
        }
        set_transient('agent_seo_inline_image_lock_' . $post_id, 1, 600);
        $variant_index = count($inline_ids) + 1;
        $variant_prompts = array(
            1 => "Create a distinct in-content editorial illustration for this article. Show a natural medium or close documentary scene explaining practical use, product handling, consultation or customer interaction. Use the supplied featured image only as a visual identity reference, but change the composition, camera angle and activity.",
            2 => "Create a distinct wide or overhead documentary illustration showing delivery, preparation, quality checking or a real workspace related to this article. Keep visual identity consistent with the supplied featured image but do not duplicate its composition."
        );
        // Prompt ảnh đại diện rất dài và nhiều điều cấm. Ảnh phụ chỉ cần brief
        // ngắn, bám tiêu đề và dùng featured image làm visual reference.
        $prompt = "Article title: " . $job['title'] . ".\n"
            . "Article keyword: " . (isset($job['keyword']) ? $job['keyword'] : '') . ".\n"
            . $variant_prompts[$variant_index]
            . "\nRealistic Vietnamese editorial photography, natural neutral daylight, accurate colors, no text, no invented logo, no CGI.";
        $inline_job = $job;
        $inline_job['prompt'] = $prompt;
        $inline_job['title'] = $job['title'] . ' - Minh hoa ' . $variant_index;
        $featured_url = $featured_id ? wp_get_attachment_url($featured_id) : '';
        if (!empty($featured_url)) {
            $inline_job['product_image'] = $featured_url;
            $inline_job['product_image_id'] = $featured_id;
        }
        // NARWHAL nhanh hơn GEM_PIX_2 và phù hợp cho ảnh phụ 1K.
        $inline_job['model_key'] = 'NARWHAL';
        // Sinh ảnh phụ KHÔNG gán thumbnail để tránh cướp ảnh đại diện.
        $attach_id = $this->generate_inline_image($post_id, $inline_job, 'Đang tạo ảnh minh họa (lần thử ' . ($inline_attempts + 1) . '/' . $max_inline_attempts . ')' . $this->stage_progress_suffix());
        if ($this->is_post_batch_stopped($post_id)) {
            delete_transient('agent_seo_inline_image_lock_' . $post_id);
            return;
        }
        if (is_wp_error($attach_id) && $attach_id->get_error_code() === 'agent_seo_image_pending') {
            $polls = intval(get_post_meta($post_id, '_agent_seo_inline_image_polls', true)) + 1;
            update_post_meta($post_id, '_agent_seo_inline_image_polls', $polls);
            delete_transient('agent_seo_inline_image_lock_' . $post_id);
            if ($polls < 8) {
                wp_schedule_single_event(time() + 15, 'agent_seo_inline_image_task', array($post_id));
            } else {
                delete_post_meta($post_id, '_agent_seo_duky_media_id');
                delete_post_meta($post_id, '_agent_seo_inline_image_polls');
                $inline_attempts++;
                update_post_meta($post_id, '_agent_seo_inline_image_attempts', $inline_attempts);
                if ($inline_attempts < $max_inline_attempts) {
                    wp_schedule_single_event(time() + 30, 'agent_seo_inline_image_task', array($post_id));
                } else {
                    delete_post_meta($post_id, '_agent_seo_inline_image_job');
                    delete_post_meta($post_id, '_agent_seo_inline_image_attempts');
                    $this->check_and_progress_image_flow($post_id);
                }
            }
            return;
        }
        delete_post_meta($post_id, '_agent_seo_inline_image_polls');
        update_post_meta($post_id, '_agent_seo_inline_image_attempts', $inline_attempts + 1);
        if ($attach_id > 0 && $this->insert_inline_image($post_id, $attach_id, $variant_index)) {
            $inline_ids[] = $attach_id;
            update_post_meta($post_id, '_agent_seo_inline_image_ids', array_values(array_unique($inline_ids)));
            // Reset attempt counter khi ảnh phụ thành công.
            update_post_meta($post_id, '_agent_seo_inline_image_attempts', 0);
        }
        delete_transient('agent_seo_inline_image_lock_' . $post_id);
        if (count($inline_ids) < 1) {
            wp_schedule_single_event(time() + 20, 'agent_seo_inline_image_task', array($post_id));
        } else {
            delete_post_meta($post_id, '_agent_seo_inline_image_job');
            delete_post_meta($post_id, '_agent_seo_inline_image_attempts');
            delete_post_meta($post_id, '_agent_seo_inline_image_polls');
            $this->check_and_progress_image_flow($post_id);
        }
    }

    private function insert_inline_image($post_id, $attach_id, $position) {
        $post = get_post($post_id);
        if (!$post || !$attach_id) {
            return false;
        }
        $image_html = wp_get_attachment_image($attach_id, 'large', false, array(
            'class' => 'agent-seo-inline-image',
            'loading' => 'lazy',
            'alt' => get_the_title($post_id) . ' - hình minh họa ' . intval($position)
        ));
        if (empty($image_html)) {
            return false;
        }
        $content = $post->post_content;
        $paragraphs = preg_split('/(<\/p>)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $insert_after = $position === 1 ? 3 : 7;
        $offset = 0;
        $paragraph_count = 0;
        for ($i = 0; $i < count($paragraphs); $i++) {
            $offset += strlen($paragraphs[$i]);
            if (stripos($paragraphs[$i], '</p>') !== false) {
                $paragraph_count++;
                if ($paragraph_count >= $insert_after) {
                    break;
                }
            }
        }
        $content = substr($content, 0, $offset)
            . "\n<figure class=\"agent-seo-inline-figure\" style=\"margin:32px auto; text-align:center; max-width:900px;\">"
            . $image_html
            . "</figure>\n"
            . substr($content, $offset);
        $updated = wp_update_post(array('ID' => $post_id, 'post_content' => $content), true);
        return !is_wp_error($updated);
    }

    private function mark_batch_image_finished($post_id, $success) {
        $batch = get_option('aseo_batch_status', array());
        if (!is_array($batch)) {
            return;
        }
        $post_id = intval($post_id);

        if (isset($batch['pending_images']) && is_array($batch['pending_images'])) {
            $batch['pending_images'] = array_values(array_filter($batch['pending_images'], function($id) use ($post_id) {
                return intval($id) !== $post_id;
            }));
        }
        if (isset($batch['pending_inline_images']) && is_array($batch['pending_inline_images'])) {
            $batch['pending_inline_images'] = array_values(array_filter($batch['pending_inline_images'], function($id) use ($post_id) {
                return intval($id) !== $post_id;
            }));
        }

        if (!$success) {
            $errors = isset($batch['image_errors']) && is_array($batch['image_errors']) ? $batch['image_errors'] : array();
            $errors[] = $post_id;
            $batch['image_errors'] = array_values(array_unique($errors));
        }

        $remaining = isset($batch['remaining']) ? intval($batch['remaining']) : 0;
        $completed = intval(isset($batch['completed']) ? $batch['completed'] : 0);
        $total = intval(isset($batch['total']) ? $batch['total'] : 0);

        $pending_featured = !empty($batch['pending_images']) ? $batch['pending_images'] : array();
        $pending_inline = !empty($batch['pending_inline_images']) ? $batch['pending_inline_images'] : array();

        if (empty($pending_featured) && empty($pending_inline)) {
            if ($remaining > 0) {
                $batch['updated_at'] = time();
                update_option('aseo_batch_status', $batch, false);
                return;
            }
            if ($completed >= $total) {
                $batch['status'] = 'complete';
                $failed_count = !empty($batch['image_errors']) ? count($batch['image_errors']) : 0;
                $batch['message'] = $failed_count > 0
                    ? 'Đã tạo xong bài viết; có ' . $failed_count . ' ảnh chưa tạo được sau nhiều lần thử.'
                    : 'Ảnh đã tạo xong. Hãy kiểm tra và chấp nhận ảnh đại diện.';
                $batch['finished_at'] = time();
            }
        }
        $batch['updated_at'] = time();
        update_option('aseo_batch_status', $batch, false);
    }

    private function check_and_progress_image_flow($post_id) {
        $post_id = absint($post_id);
        if ($post_id <= 0 || !get_post($post_id)) {
            return;
        }

        // 1. Kiểm tra ảnh đại diện
        $featured_id = get_post_thumbnail_id($post_id);
        $featured_job = get_post_meta($post_id, '_agent_seo_image_job', true);

        if (empty($featured_id) && is_array($featured_job) && !empty($featured_job['prompt'])) {
            // Ảnh đại diện chưa xong và vẫn còn job (đang trong quá trình retry)
            return;
        }

        // 2. Kiểm tra ảnh phụ
        $inline_ids = get_post_meta($post_id, '_agent_seo_inline_image_ids', true);
        $inline_ids = is_array($inline_ids) ? array_values(array_filter(array_map('absint', $inline_ids))) : array();
        $inline_job = get_post_meta($post_id, '_agent_seo_inline_image_job', true);

        if (!$this->inline_images_enabled()) {
            delete_post_meta($post_id, '_agent_seo_inline_image_job');
            delete_post_meta($post_id, '_agent_seo_inline_image_attempts');
            delete_post_meta($post_id, '_agent_seo_inline_image_polls');
            $success = !empty($featured_id);
            $this->maybe_publish_after_approved_images($post_id, $success);
            $this->mark_batch_image_finished($post_id, $success);
            return;
        }

        if (count($inline_ids) < 1 && is_array($inline_job) && !empty($inline_job['prompt'])) {
            // Ảnh phụ chưa xong và vẫn còn job → Chạy hoặc schedule tiếp ảnh phụ.
            if (!wp_next_scheduled('agent_seo_inline_image_task', array($post_id))) {
                wp_schedule_single_event(time() + 5, 'agent_seo_inline_image_task', array($post_id));
            }
            return;
        }

        // 3. Cả hai job đã kết thúc. Chỉ báo success khi thật sự có đủ hai ảnh.
        $success = !empty($featured_id) && (!$this->inline_images_enabled() || count($inline_ids) >= 1);
        $this->maybe_publish_after_approved_images($post_id, $success);
        $this->mark_batch_image_finished($post_id, $success);
    }

    /**
     * Chỉ xuất bản sau khi người dùng chấp nhận ảnh và toàn bộ ảnh được yêu cầu
     * đã hoàn tất. Nếu chọn lưu nháp, bài vẫn giữ nguyên.
     */
    private function maybe_publish_after_approved_images($post_id, $success) {
        $post_id = absint($post_id);
        if (
            !$success
            || get_post_meta($post_id, '_agent_seo_image_approved', true) !== '1'
        ) {
            return;
        }
        update_post_meta($post_id, '_agent_seo_image_stage_complete', '1');
        if (get_post_meta($post_id, '_agent_seo_publish_after_images', true) !== '1') {
            return;
        }
        $post = get_post($post_id);
        if ($post && $post->post_type === 'post' && $post->post_status !== 'publish') {
            wp_update_post(array('ID' => $post_id, 'post_status' => 'publish'));
        }
    }

    /**
     * Cập nhật message hiển thị trên thanh tiến độ mà không đổi phần trăm/đếm bài.
     */
    private function set_stage_message($message) {
        $batch = get_option('aseo_batch_status', array());
        if (!is_array($batch) || empty($batch['total'])) {
            return;
        }
        // Không để message của image worker liên tục làm mới content worker.
        // Nếu content cron bị lỡ, ajax_batch_status vẫn có thể tự cứu sau 15 giây.
        if (intval(isset($batch['remaining']) ? $batch['remaining'] : 0) > 0 && mb_stripos($message, 'ảnh') !== false) {
            return;
        }
        $batch['message'] = $message;
        $batch['updated_at'] = time();
        update_option('aseo_batch_status', $batch, false);
    }

    private function is_batch_stopped($batch_started_at) {
        $batch_started_at = intval($batch_started_at);
        if ($batch_started_at <= 0) {
            return false;
        }
        $batch = get_option('aseo_batch_status', array());
        return is_array($batch)
            && isset($batch['status'], $batch['started_at'])
            && $batch['status'] === 'stopped'
            && intval($batch['started_at']) === $batch_started_at;
    }

    private function is_post_batch_stopped($post_id) {
        $batch_started_at = intval(get_post_meta($post_id, '_agent_seo_batch_started_at', true));
        return $this->is_batch_stopped($batch_started_at);
    }

    private function inline_images_enabled() {
        return get_option('aseo_enable_inline_images', '0') === '1';
    }

    /**
     * Chuỗi " bài X/Y" để ghép vào các thông báo giai đoạn.
     */
    private function stage_progress_suffix() {
        $batch = get_option('aseo_batch_status', array());
        if (is_array($batch) && !empty($batch['total'])) {
            $current = isset($batch['current']) ? intval($batch['current']) : 1;
            return ' bài ' . $current . '/' . intval($batch['total']);
        }
        return '';
    }

    private function generate_featured_image($post_id, $job, $stage_label = '') {
        return $this->generate_image_internal($post_id, $job, true, $stage_label);
    }

    /**
     * Sinh ảnh phụ (inline) — KHÔNG gán thumbnail, tránh cướp ảnh đại diện.
     */
    private function generate_inline_image($post_id, $job, $stage_label = '') {
        return $this->generate_image_internal($post_id, $job, false, $stage_label);
    }

    /**
     * Hàm nội bộ dùng chung cho cả ảnh đại diện và ảnh phụ.
     * @param bool $set_thumbnail Nếu true, ảnh sẽ được gán làm thumbnail (chỉ dùng cho ảnh đại diện).
     * @param string $stage_label Nhãn hiển thị trên progress bar (VD: "Đang tạo ảnh đại diện bài 1/3").
     */
    private function generate_image_internal($post_id, $job, $set_thumbnail = true, $stage_label = '') {
        if ($stage_label !== '') {
            update_option('aseo_image_wait_stage', array('label' => $stage_label, 'started_at' => time()), false);
            $this->set_stage_message($stage_label . '...');
        }
        $api_key = get_option('aseo_gemini_api_key', '');
        $duky_key = get_option('aseo_duky_api_key', '');
        $nvidia_key = get_option('aseo_nvidia_api_key', '');
        $engine = get_option('aseo_image_engine', 'duky');
        $kaggle_url = get_option('aseo_kaggle_api_url', '');
        $prompt = isset($job['prompt']) ? $job['prompt'] : '';
        $title = isset($job['title']) ? $job['title'] : get_the_title($post_id);
        $keyword = isset($job['keyword']) ? $job['keyword'] : '';
        $product_image = isset($job['product_image']) ? $job['product_image'] : '';
        $attach_id = false;
        if ($engine === 'kaggle') {
            if (empty($kaggle_url)) {
                error_log('Agent SEO Google Flow: engine selected but aseo_kaggle_api_url is empty.');
                return false;
            }
            error_log('Agent SEO Google Flow: generating featured=' . ($set_thumbnail ? 'yes' : 'no') . ' post=' . intval($post_id) . ' url=' . $kaggle_url);
            $attach_id = Agent_SEO_Gemini_Image::generate_and_save_image_kaggle($kaggle_url, $prompt, $post_id, $title, $keyword, $product_image, $set_thumbnail);
            // Google Flow là engine được chọn rõ ràng: không âm thầm chuyển sang
            // Duky/Imagen nếu Flow lỗi, để trạng thái và retry phản ánh đúng nguyên nhân.
            return $attach_id;
        }
        if ($engine === 'imagen' && !empty($api_key)) {
            $attach_id = Agent_SEO_Gemini_Image::generate_and_save_image($api_key, $prompt, $post_id, $title, $keyword, $set_thumbnail);
            if ($attach_id) return $attach_id;
        }
        if ($engine === 'nvidia' && !empty($nvidia_key)) {
            $attach_id = Agent_SEO_Gemini_Image::generate_and_save_image_nvidia($nvidia_key, $prompt, $post_id, $title, $keyword, $set_thumbnail);
            if ($attach_id) return $attach_id;
        }
        if (!empty($duky_key)) {
            $duky_model_override = isset($job['model_key']) ? sanitize_text_field($job['model_key']) : '';
            $attach_id = Agent_SEO_Gemini_Image::generate_and_save_image_duky($duky_key, $prompt, $post_id, $title, $keyword, $product_image, $set_thumbnail, $duky_model_override);
            if (is_wp_error($attach_id) && $attach_id->get_error_code() === 'agent_seo_image_pending') {
                return $attach_id;
            }

            /*
             * Ảnh phụ hiện được gọi với model_key = NARWHAL, trong khi ảnh
             * đại diện không có model_key nên dùng model cấu hình (thường là
             * GEM_PIX_2). Khi GEM_PIX_2 hết quota, ảnh phụ vẫn tạo được nhưng
             * ảnh chính thất bại. Với ảnh đại diện, tự thử NARWHAL một lần
             * nữa để dùng cùng model đang hoạt động cho ảnh phụ.
             *
             * Chỉ fallback khi không có model override (tức ảnh đại diện);
             * không thay đổi luồng ảnh phụ và không fallback khi tác vụ đang
             * chờ tải ảnh về.
             */
            if (
                $set_thumbnail
                && empty($duky_model_override)
                && (!$attach_id || is_wp_error($attach_id))
            ) {
                delete_post_meta($post_id, '_agent_seo_duky_media_id');
                error_log('Agent SEO Duky: featured image model failed; retrying with NARWHAL.');
                $attach_id = Agent_SEO_Gemini_Image::generate_and_save_image_duky($duky_key, $prompt, $post_id, $title, $keyword, $product_image, true, 'NARWHAL');
                if (is_wp_error($attach_id) && $attach_id->get_error_code() === 'agent_seo_image_pending') {
                    return $attach_id;
                }
            }
            // Các lỗi Duky khác là lỗi cứng của lượt này; chuẩn hóa về false
            // để worker có thể retry đúng cách hoặc thử engine dự phòng.
            if (is_wp_error($attach_id)) {
                $attach_id = false;
            }
        }
        if (!$attach_id && !empty($nvidia_key)) {
            $attach_id = Agent_SEO_Gemini_Image::generate_and_save_image_nvidia($nvidia_key, $prompt, $post_id, $title, $keyword, $set_thumbnail);
        }
        if (!$attach_id && !empty($api_key)) {
            $attach_id = Agent_SEO_Gemini_Image::generate_and_save_image($api_key, $prompt, $post_id, $title, $keyword, $set_thumbnail);
        }
        return $attach_id;
    }

    private function ensure_featured_image($post_id, $attach_id) {
        $post_id = intval($post_id);
        $attach_id = intval($attach_id);
        if (
            $post_id <= 0
            || $attach_id <= 0
            || !wp_attachment_is_image($attach_id)
            || get_post_meta($attach_id, '_agent_seo_generated_image', true) !== '1'
        ) {
            return false;
        }
        set_post_thumbnail($post_id, $attach_id);
        if (intval(get_post_thumbnail_id($post_id)) !== $attach_id) {
            update_post_meta($post_id, '_thumbnail_id', $attach_id);
        }
        $success = intval(get_post_thumbnail_id($post_id)) === $attach_id;
        if ($success) {
            update_post_meta($post_id, '_agent_seo_ai_featured_image_id', $attach_id);
        }
        return $success;
    }

    private function clear_image_job($post_id) {
        delete_post_meta($post_id, '_agent_seo_image_job');
        delete_post_meta($post_id, '_agent_seo_image_attempts');
        delete_post_meta($post_id, '_agent_seo_image_polls');
        delete_post_meta($post_id, '_agent_seo_duky_media_id');
        $timestamp = wp_next_scheduled('agent_seo_image_retry_task', array($post_id));
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'agent_seo_image_retry_task', array($post_id));
        }
    }

    /**
     * Thực thi một chu trình viết bài hoàn chỉnh cho một từ khóa tiếp theo
     */
    public function run_single_task($requested_status = '', $product_id_override = 0, $batch_started_at = 0) {
        $api_key       = get_option('aseo_gemini_api_key', '');
        $niche         = get_option('aseo_niche', 'Sản phẩm, dịch vụ và lĩnh vực kinh doanh của website');
        $brand_voice   = get_option('aseo_brand_voice', 'Chuyên nghiệp, tin cậy, rõ ràng và phù hợp với khách hàng mục tiêu');
        $keywords_text = get_option('aseo_keywords', '');

        // Lấy thông tin cấu hình thương hiệu tự chọn
        $brand_data = array(
            'brand_name'    => get_option('aseo_brand_name', ''),
            'brand_address' => get_option('aseo_brand_address', ''),
            'brand_phone'   => get_option('aseo_brand_phone', ''),
            'brand_contact' => get_option('aseo_brand_contact', ''),
            'brand_price'   => get_option('aseo_brand_price', ''),
            'brand_cta'     => get_option('aseo_brand_cta', ''),
            'source_website' => get_option('aseo_source_website', ''),
            'user_brief'    => get_option('aseo_master_prompt_brief', '')
        );

        // Lượt tạo thủ công có thể gửi một brief riêng. Chỉ dùng topic này cho
        // bài đầu tiên của batch; các bài tiếp theo vẫn lấy lần lượt từ hàng đợi.
        $active_batch = get_option('aseo_batch_status', array());
        if (is_array($active_batch) && empty($active_batch['run_brief_used'])) {
            $run_topic = isset($active_batch['run_topic']) ? sanitize_text_field($active_batch['run_topic']) : '';
            $run_brief = isset($active_batch['run_brief']) ? sanitize_textarea_field($active_batch['run_brief']) : '';
            if ($run_topic !== '') {
                $brand_data['article_topic_override'] = $run_topic;
                if ($run_brief !== '') {
                    $brand_data['user_brief'] = trim($brand_data['user_brief'] . "\n" . $run_brief);
                }
                $active_batch['run_brief_used'] = 1;
                update_option('aseo_batch_status', $active_batch, false);
            }
        }

        $product_id = $product_id_override > 0 ? $product_id_override : get_option('aseo_target_product', '');
        if (class_exists('WooCommerce') && !empty($product_id)) {
            $product = wc_get_product($product_id);
            if ($product) {
                $raw_desc = wp_strip_all_tags($product->get_short_description() ? $product->get_short_description() : $product->get_description());
                $clean_desc = str_replace(array('"', "'", "\r", "\n", "\\"), ' ', $raw_desc);
                $clean_desc = mb_substr(trim($clean_desc), 0, 150);
                if (mb_strlen($raw_desc) > 150) {
                    $clean_desc .= '...';
                }

                $brand_data['product_name'] = str_replace(array('"', "'", "\\"), '', $product->get_name());
                $brand_data['product_desc'] = $clean_desc;
                $brand_data['product_price'] = str_replace(array('"', "'", "\\"), '', strip_tags(wc_price($product->get_price())));
                $brand_data['product_url'] = esc_url(get_permalink($product_id));
                
                // Trích xuất ảnh nổi bật của sản phẩm nếu có
                $image_id = $product->get_image_id();
                $brand_data['product_image'] = $image_id ? esc_url(wp_get_attachment_url($image_id)) : '';
                $brand_data['product_image_id'] = $image_id ? absint($image_id) : 0;

                // Định nghĩa chuỗi thông tin để hướng dẫn AI viết bài tập trung
                $brand_data['product_info'] = "SẢN PHẨM MỤC TIÊU CẦN SEO:\n" .
                    "- Tên sản phẩm: " . $brand_data['product_name'] . "\n" .
                    "- Mô tả: " . $brand_data['product_desc'] . "\n" .
                    "- Giá bán: " . $brand_data['product_price'] . "\n" .
                    "- Link mua hàng trực tiếp: " . $brand_data['product_url'] . "\n";
            }
        }

        // Ảnh tham chiếu chọn từ Media Library được ưu tiên hơn ảnh sản phẩm mặc định khi tạo ảnh AI.
        $reference_image_id = absint(get_option('aseo_reference_image_id', 0));
        if ($reference_image_id > 0 && wp_attachment_is_image($reference_image_id)) {
            $reference_image_url = wp_get_attachment_url($reference_image_id);
            if (!empty($reference_image_url)) {
                $brand_data['product_image_id'] = $reference_image_id;
                $brand_data['product_image'] = esc_url($reference_image_url);
            }
        }

        if (empty($api_key)) {
            return array('success' => false, 'message' => 'Thiếu cấu hình Gemini API Key.');
        }

        // 1. Phân tích từ khóa tiếp theo chưa được viết
        $lines = explode("\n", $keywords_text);
        $target_keyword = '';
        $target_index   = -1;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) {
                continue;
            }
            
            // Bỏ qua dòng đã có tiền tố đánh dấu viết xong
            if (preg_match('/^\[(x|đã viết)\]/iu', $trimmed)) {
                continue;
            }

            $target_keyword = $trimmed;
            $target_index   = $index;
            break;
        }

        $is_auto_generated = false;
        if (empty($target_keyword)) {
            // Hàng đợi trống hoặc đã viết xong, tự động suy nghĩ từ khóa mới liên quan đến sản phẩm
            $prod_info_for_keyword = isset($brand_data['product_info']) ? $brand_data['product_info'] : ("Lĩnh vực/Chủ đề: " . $niche);
            
            // Tập hợp các từ khóa đã viết để tránh trùng lặp
            $existing_keywords = array();
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (!empty($trimmed)) {
                    // Loại bỏ tiền tố [Đã viết] khi truyền cho AI đối chiếu
                    $existing_keywords[] = preg_replace('/^\[(x|đã viết)\]\s+/iu', '', $trimmed);
                }
            }
            
            $brainstormed_keyword = Agent_SEO_Gemini_Text::generate_keyword_from_product($api_key, $prod_info_for_keyword, $existing_keywords);
            if (empty($brainstormed_keyword)) {
                return array('success' => false, 'message' => 'Danh sách từ khóa trống và AI không thể tự động suy nghĩ từ khóa mới.');
            }
            
            $target_keyword = $brainstormed_keyword;
            $is_auto_generated = true;
        }

        // Một primary keyword cố định cho toàn bộ cụm bài; keyword trong hàng đợi là chủ đề phụ từng bài.
        $topic_keyword = !empty($brand_data['article_topic_override'])
            ? $brand_data['article_topic_override'] : $target_keyword;
        $primary_keyword = trim(get_option('aseo_primary_keyword', ''));
        if (empty($primary_keyword)) {
            return array(
                'success' => false,
                'message' => 'Bạn chưa nhập từ khóa chính cố định. Vui lòng nhập từ khóa chính trong tab SEO & Từ khóa rồi lưu cấu hình trước khi tạo bài.'
            );
        }
        $brand_data['article_topic'] = $topic_keyword;
        $global_secondary_raw = trim(get_option('aseo_secondary_keywords', ''));
        $global_secondary_items = preg_split('/[\r\n,;]+/', $global_secondary_raw);
        $global_secondary_clean = array();
        foreach ($global_secondary_items as $global_secondary_item) {
            $global_secondary_item = sanitize_text_field(trim($global_secondary_item));
            if ($global_secondary_item === '' || mb_strtolower($global_secondary_item) === mb_strtolower($primary_keyword)) {
                continue;
            }
            $global_secondary_clean[mb_strtolower($global_secondary_item)] = $global_secondary_item;
            if (count($global_secondary_clean) >= 3) {
                break;
            }
        }
        $brand_data['global_secondary_keywords'] = implode("\n", array_values($global_secondary_clean));

        // Truy vấn tiêu đề các bài đã tạo trước đó để AI viết nội dung không trùng lặp.
        $existing_posts = get_posts(array(
            'post_type'      => 'post',
            'post_status'    => array('publish', 'draft'),
            'meta_key'       => '_agent_seo_generated',
            'meta_value'     => '1',
            'posts_per_page' => 20,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids'
        ));
        $existing_titles = array();
        foreach ($existing_posts as $ep_id) {
            $existing_titles[] = get_the_title($ep_id);
        }
        $brand_data['existing_article_titles'] = $existing_titles;

        // 2. Gọi Gemini để viết nội dung bài viết
        if ($this->is_batch_stopped($batch_started_at)) {
            return array('success' => false, 'message' => 'Tiến trình đã được người dùng dừng.');
        }
        $this->set_stage_message('Đang viết nội dung' . $this->stage_progress_suffix() . '...');
        $article = Agent_SEO_Gemini_Text::generate_content($api_key, $primary_keyword, $niche, $brand_voice, $brand_data, $batch_started_at);
        if (!$article['success']) {
            return array('success' => false, 'message' => 'Lỗi tạo văn bản: ' . $article['message']);
        }
        if ($this->is_batch_stopped($batch_started_at)) {
            return array('success' => false, 'message' => 'Tiến trình đã được người dùng dừng.');
        }

        // Tiêu đề do AI tạo đã được tối ưu hóa tự nhiên và lồng ghép từ khóa sáng tạo.
        $article['content'] = $this->ensure_rank_math_toc($article['content']);

        // Rank Math: meta description phải chứa nguyên văn primary keyword.
        // AI đôi khi viết mô tả hay nhưng bỏ quên cụm từ này, nên chuẩn hóa lại trước khi lưu WordPress.
        $article['meta_description'] = $this->ensure_primary_in_meta_description(
            isset($article['meta_description']) ? $article['meta_description'] : '',
            $primary_keyword
        );

        // Rank Math: Giữ từ khóa chính riêng biệt của bài viết làm từ khóa tập trung chính (để tránh trùng lặp)
        // và thêm từ khóa chính chung của cụm bài làm từ khóa phụ.
        $post_primary_keyword = !empty($article['primary_keyword']) ? sanitize_text_field($article['primary_keyword']) : $primary_keyword;
        $rank_math_keywords_list = array($post_primary_keyword);
        
        $secondary_sources = array();
        if (mb_strtolower($post_primary_keyword) !== mb_strtolower($primary_keyword)) {
            $secondary_sources[] = $primary_keyword;
        }
        $secondary_sources[] = $brand_data['global_secondary_keywords'];
        $secondary_sources[] = isset($article['secondary_keyword']) ? $article['secondary_keyword'] : '';
        $max_secondary_keywords = 3;
        foreach ($secondary_sources as $secondary_source) {
            $secondary_items = preg_split('/[\r\n,;]+/', $secondary_source);
            foreach ($secondary_items as $secondary_item) {
                $secondary_item = sanitize_text_field(trim($secondary_item));
                if (empty($secondary_item)) {
                    continue;
                }
                $is_duplicate = false;
                foreach ($rank_math_keywords_list as $existing_keyword) {
                    if (mb_strtolower($existing_keyword) === mb_strtolower($secondary_item)) {
                        $is_duplicate = true;
                        break;
                    }
                }
                if (!$is_duplicate) {
                    $rank_math_keywords_list[] = $secondary_item;
                    if (count($rank_math_keywords_list) >= ($max_secondary_keywords + 1)) {
                        break 2;
                    }
                }
            }
        }
        $rank_math_keywords = implode(', ', $rank_math_keywords_list);

        // Giai đoạn 1 luôn lưu nháp để người dùng kiểm tra thông tin. Nếu người
        // dùng chọn tự xuất bản, chỉ xuất bản sau khi họ duyệt và ảnh tạo đủ.
        $requested_post_status = in_array($requested_status, array('draft', 'publish'), true)
            ? $requested_status
            : get_option('aseo_post_status', 'publish');
        if (!in_array($requested_post_status, array('draft', 'publish'), true)) {
            $requested_post_status = 'draft';
        }
        $post_status = 'draft';

        // Slug phải chứa primary keyword exact-match để Rank Math không báo thiếu từ khóa trong URL.
        // Ghép thêm topic để mỗi bài trong cụm vẫn có slug riêng.
        $primary_slug = sanitize_title($primary_keyword);
        $topic_slug = sanitize_title($topic_keyword);
        $slug_seed = $primary_slug;
        if ($topic_slug !== '' && $topic_slug !== $primary_slug) {
            $slug_seed .= '-' . $topic_slug;
        }
        $article_slug = $this->limit_article_slug($slug_seed);
        if ($article_slug === '') {
            $article_slug = $this->limit_article_slug($topic_keyword);
        }
        if ($article_slug === '') {
            $article_slug = $this->limit_article_slug($article['slug']);
        }
        if (get_page_by_path($article_slug, OBJECT, 'post')) {
            $article_slug = $this->limit_article_slug($primary_slug . '-' . $topic_slug . '-' . sanitize_title($article['seo_title']));
        }
        if (get_page_by_path($article_slug, OBJECT, 'post')) {
            // Trường hợp hiếm khi AI trả cả tiêu đề và chủ đề trùng hoàn toàn.
            // Dùng mã nội dung ngắn thay cho hậu tố số vô nghĩa của WordPress.
            $article_slug = $this->limit_article_slug($primary_slug . '-' . $topic_slug, 34)
                . '-goc-' . substr(md5($topic_keyword . '|' . $article['seo_title'] . '|' . $article['content']), 0, 6);
        }

        $post_data = array(
            'post_title'   => $article['seo_title'],
            'post_name'    => $article_slug,
            'post_content' => $article['content'],
            'post_excerpt' => $article['meta_description'],
            'post_status'  => $post_status,
            'post_type'    => 'post',
            'post_author'  => 1, // Gán cho Admin
            'meta_input'   => array(
                '_agent_seo_generated' => '1',
                '_agent_seo_awaiting_image_approval' => '1',
                '_agent_seo_image_approved' => '0',
                '_agent_seo_publish_after_images' => $requested_post_status === 'publish' ? '1' : '0',
                '_agent_seo_batch_started_at' => intval($batch_started_at),
                '_agent_seo_keyword'   => $target_keyword,
                '_agent_seo_primary_keyword' => $primary_keyword,
                '_agent_seo_global_secondary_keywords' => $brand_data['global_secondary_keywords'],
                '_agent_seo_secondary_keywords' => $article['secondary_keyword'],
                
                // Rank Math SEO Integration (Lưu cả 2 loại có và không có gạch dưới để tương thích 100%)
                'rank_math_title'          => $article['seo_title'],
                'rank_math_description'    => $article['meta_description'],
                'rank_math_focus_keyword'  => $rank_math_keywords,
                '_rank_math_title'         => $article['seo_title'],
                '_rank_math_description'   => $article['meta_description'],
                '_rank_math_focus_keyword' => $rank_math_keywords,
                
                // Yoast SEO Integration
                '_yoast_wpseo_title'    => $article['seo_title'],
                '_yoast_wpseo_metadesc' => $article['meta_description'],
                '_yoast_wpseo_focuskw'  => $primary_keyword
            )
        );

        $post_id = wp_insert_post($post_data);
        if (is_wp_error($post_id)) {
            return array('success' => false, 'message' => 'Lỗi đăng bài viết vào WordPress: ' . $post_id->get_error_message());
        }
        $inserted_thumbnail_id = absint(get_post_thumbnail_id($post_id));
        if (
            $inserted_thumbnail_id > 0
            && get_post_meta($inserted_thumbnail_id, '_agent_seo_generated_image', true) !== '1'
        ) {
            delete_post_thumbnail($post_id);
        }
        if ($this->is_batch_stopped($batch_started_at)) {
            // Bài đã được lưu nên đánh dấu topic đã dùng, tránh batch sau tạo trùng.
            if ($is_auto_generated) {
                $lines = array_filter($lines, 'trim');
                $lines[] = '[Đã viết] ' . $target_keyword;
            } else {
                $lines[$target_index] = '[Đã viết] ' . $target_keyword;
            }
            update_option('aseo_keywords', implode("\n", $lines));
            return array(
                'success' => true,
                'post_id' => $post_id,
                'title' => $article['seo_title'],
                'image_warning' => ''
            );
        }

        // 4. Tạo ảnh thực tế và gán làm ảnh đại diện
        // Sử dụng image_prompt do AI tạo ra tương ứng với chủ đề bài viết để đảm bảo tính liên quan cao nhất
        $article_context = wp_strip_all_tags($article['content']);
        $article_context = preg_replace('/\s+/', ' ', $article_context);
        $article_context = mb_substr(trim($article_context), 0, 500);
        $base_image_prompt = !empty($article['image_prompt'])
            ? $article['image_prompt']
            : 'A realistic editorial photograph that directly illustrates the article topic, with a natural Vietnamese setting and no generic product hero shot';
        $master_prompt = trim(get_option('aseo_master_prompt', ''));
        if (empty($master_prompt)) {
            $master_prompt = trim(get_option('aseo_master_image_prompt', ''));
        }
        if (!empty($master_prompt)) {
            $base_image_prompt = $master_prompt . "\n\nARTICLE-SPECIFIC IMAGE BRIEF:\n" . $base_image_prompt;
        }
        $scene_variants = array(
            'CLOSE-UP DETAIL: tight close-up at eye level, 50mm macro-style framing of the product texture, label color and a human hand using it; shallow depth of field, background softly blurred',
            'MEDIUM HUMAN ACTION: medium shot at eye level showing a real person inspecting, handling or advising about the product in its natural workplace',
            'WIDE ESTABLISHING: wide 24mm documentary shot showing the complete store, workshop, warehouse or service environment with the product integrated naturally',
            'LOW ANGLE PROCESS: subtle low-angle three-quarter view of a worker carrying, loading, installing or preparing the product; realistic depth and motion',
            'HIGH ANGLE OVERHEAD: high-angle or overhead documentary view of an organized work surface, packing area, ingredients, tools or order preparation',
            'DELIVERY AND LOGISTICS: side or rear three-quarter shot of authentic loading, delivery, handover or transport activity; vehicle and people support the story',
            'QUALITY CONTROL: medium close-up of an expert checking, weighing, testing or inspecting the product with appropriate tools and natural concentration',
            'CUSTOMER EXPERIENCE: over-the-shoulder shot from behind a customer receiving advice, comparing options or using the product in a real setting',
            'PRODUCTION BEHIND THE SCENES: diagonal side view of preparation, manufacturing, workshop or team operation with layered foreground and background',
            'ENVIRONMENTAL PORTRAIT: 35mm environmental portrait of a relevant worker or customer with the product visible as a natural part of the scene'
        );
        $scene_index = abs(crc32($topic_keyword . '|' . $article['seo_title'] . '|' . $post_id)) % count($scene_variants);
        $selected_scene = $scene_variants[$scene_index];
        $reference_instruction = !empty($brand_data['product_image'])
            ? "PRODUCT REFERENCE PRIORITY: The supplied product reference image is the primary visual subject. Preserve its exact package shape, printed markings and dominant colors; show one clearly recognizable package in the foreground occupying about 35-50% of the frame. Any background packages must be blurred, secondary and visually consistent with the reference. Do not replace it with generic sacks or packaging of another color."
            : "If no product reference is supplied, do not invent packaging; focus on people, process, place, tools or outcome appropriate to the article.";
        $image_prompt = $base_image_prompt . "\n\nARTICLE CONTEXT (must guide the scene):\n"
            . "Title: " . $article['seo_title'] . "\n"
            . "Primary keyword: " . $primary_keyword . "\n"
            . "Article subtopic: " . $topic_keyword . "\n"
            . "Summary: " . $article_context . "\n\n"
            . "SELECTED SHOT AND SCENE FOR VISUAL DIVERSITY: " . $selected_scene . ". Follow this camera distance and angle; do not revert to a generic warehouse medium shot. Adapt the scene naturally to the article topic and vary the composition across articles. "
            . "Use an environmental documentary shot with authentic human activity. " . $reference_instruction . " "
            . "Use cool-toned 6000K-6500K daylight, crisp cool color temperature, accurate white balance, clean whites and cool grays, realistic true-to-life colors, and soft non-yellow shadows. "
            . "No warm golden light, yellow/orange cast, sunset, cinematic grading, dramatic advertising light, glossy 3D render, plastic CGI look, generic AI stock-photo composition, or random props. No readable text or newly invented logos.";
        $image_prompt .= "\n\nFINAL LIGHTING OVERRIDE (must follow exactly): Photographed in real soft natural daylight, preferably open shade, cool overcast sky, or bright overcast window light. Cool white balance 6000K-6500K, crisp cool color temperature, clean neutral whites and cool grays, realistic skin tones, physically accurate shadows. ABSOLUTELY NO warm white balance, yellow tint, yellow cast, orange cast, golden-hour light, warm golden glow, tungsten light, amber filter, sepia, warm beige tones, cinematic teal-orange grade, glossy studio glow, CGI, or artificial AI lighting. The scene must look like an unedited documentary photograph taken with a real camera.";
        
        $product_image = isset($brand_data['product_image']) ? $brand_data['product_image'] : '';
        $image_job = array(
            'prompt' => $image_prompt,
            'title' => $article['seo_title'],
            'keyword' => $target_keyword,
            'product_image' => $product_image,
            'product_image_id' => isset($brand_data['product_image_id']) ? absint($brand_data['product_image_id']) : 0
        );
        update_post_meta($post_id, '_agent_seo_image_job', $image_job);
        // Giữ một bản prompt/job gốc để người dùng có thể sửa ảnh sau khi
        // worker đã dọn job tạm thời lúc tạo ảnh thành công.
        update_post_meta($post_id, '_agent_seo_base_image_job', $image_job);
        // Ảnh trong thân bài là tùy chọn; mặc định tắt để batch không bị kéo dài.
        if ($this->inline_images_enabled()) {
            update_post_meta($post_id, '_agent_seo_inline_image_job', $image_job);
        } else {
            delete_post_meta($post_id, '_agent_seo_inline_image_job');
        }
        update_post_meta($post_id, '_agent_seo_inline_image_ids', array());
        update_post_meta($post_id, '_agent_seo_image_attempts', 0);
        // Chỉ chuẩn bị prompt/job. API ảnh chỉ được gọi sau khi người dùng chọn
        // bài trong danh sách và bấm “Tạo ảnh”.
        $image_warning = '';

        // 5. Tự động đi liên kết nội bộ (Internal Link)
        $this->set_stage_message('Đang tự động đi liên kết nội bộ' . $this->stage_progress_suffix() . '...');
        Agent_SEO_Linker::add_internal_links($post_id);

        // 6. Cập nhật lại danh sách từ khóa trong cấu hình (Đánh dấu từ khóa đã viết xong)
        if ($is_auto_generated) {
            // Loại bỏ các dòng trống cuối cùng nếu có trước khi thêm
            $lines = array_filter($lines, 'trim');
            $lines[] = '[Đã viết] ' . $target_keyword;
        } else {
            $lines[$target_index] = '[Đã viết] ' . $target_keyword;
        }
        $updated_keywords_text = implode("\n", $lines);
        update_option('aseo_keywords', $updated_keywords_text);

        return array(
            'success'       => true,
            'post_id'       => $post_id,
            'title'         => $article['seo_title'],
            'image_warning' => $image_warning,
            'review_required' => false
        );
    }

    private function ensure_primary_in_meta_description($description, $primary_keyword) {
        $description = sanitize_text_field((string) $description);
        $primary_keyword = sanitize_text_field((string) $primary_keyword);
        if ($primary_keyword === '') {
            return mb_substr($description, 0, 160);
        }
        if ($description === '') {
            $description = 'Khám phá thông tin chi tiết về ' . $primary_keyword . ' và cách lựa chọn phù hợp.';
        } elseif (mb_stripos($description, $primary_keyword) === false) {
            $description = $primary_keyword . ': ' . $description;
        }
        if (mb_strlen($description) > 160) {
            $description = rtrim(mb_substr($description, 0, 157), " \t\n\r\0\x0B,.;:-") . '...';
        }
        return $description;
    }

    private function limit_article_slug($text, $max_length = 45) {
        $slug = sanitize_title($text);
        if (strlen($slug) <= $max_length) {
            return $slug;
        }
        $short = rtrim(substr($slug, 0, $max_length), '-');
        $word_boundary = preg_replace('/-[^-]*$/', '', $short);
        return strlen($word_boundary) >= 30 ? $word_boundary : $short;
    }

    private function ensure_rank_math_toc($content) {
        if (stripos($content, 'wp:rank-math/toc-block') !== false) {
            return $content;
        }
        $toc_items = array();
        $used_ids = array();
        $content = preg_replace_callback('/<h2\b([^>]*)>(.*?)<\/h2>/isu', function($matches) use (&$toc_items, &$used_ids) {
            $attributes = $matches[1];
            $heading_html = $matches[2];
            $heading_text = trim(wp_strip_all_tags(html_entity_decode($heading_html, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($heading_text === '') {
                return $matches[0];
            }
            $heading_id = '';
            if (preg_match('/\bid=["\']([^"\']+)["\']/iu', $attributes, $id_match)) {
                $heading_id = sanitize_title($id_match[1]);
            }
            if ($heading_id === '') {
                $heading_id = sanitize_title($heading_text);
            }
            $base_id = $heading_id;
            $suffix = 2;
            while (isset($used_ids[$heading_id])) {
                $heading_id = $base_id . '-' . $suffix;
                $suffix++;
            }
            $used_ids[$heading_id] = true;
            $toc_items[] = array('id' => $heading_id, 'title' => $heading_text);
            $attributes = preg_replace('/\s*\bid=["\'][^"\']*["\']/iu', '', $attributes);
            return '<h2' . $attributes . ' id="' . esc_attr($heading_id) . '">' . $heading_html . '</h2>';
        }, $content);
        if (count($toc_items) < 2) {
            return $content;
        }
        $toc_list = '';
        foreach ($toc_items as $toc_item) {
            $toc_list .= '<li><a href="#' . esc_attr($toc_item['id']) . '">' . esc_html($toc_item['title']) . '</a></li>';
        }
        $toc = '<!-- wp:rank-math/toc-block {"title":"Mục lục bài viết"} -->'
            . '<div class="wp-block-rank-math-toc-block agent-seo-toc"><p><strong>Mục lục bài viết</strong></p><nav><ul>'
            . $toc_list
            . '</ul></nav></div><!-- /wp:rank-math/toc-block -->';
        $first_paragraph_end = stripos($content, '</p>');
        if ($first_paragraph_end !== false) {
            $insert_at = $first_paragraph_end + 4;
            return substr($content, 0, $insert_at) . $toc . substr($content, $insert_at);
        }
        return $toc . $content;
    }

    /**
     * Tự động điều chỉnh lịch chạy WP-Cron khi người dùng đổi cài đặt tần suất
     */
    public function reschedule_cron_on_update($old_value, $new_value) {
        if ($old_value === $new_value) {
            return;
        }

        $timestamp = wp_next_scheduled('agent_seo_cron_hook');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'agent_seo_cron_hook');
        }

        // Đăng ký lịch mới dựa trên cài đặt của người dùng
        $allowed_intervals = array('hourly', 'twicedaily', 'daily', 'weekly');
        $interval = in_array($new_value, $allowed_intervals) ? $new_value : 'daily';

        wp_schedule_event(time(), $interval, 'agent_seo_cron_hook');
    }
}
