<?php
/**
 * Client kết nối Gemini Imagen 3 API phục vụ sinh ảnh thực tế
 */

defined('ABSPATH') || exit;

class Agent_SEO_Gemini_Image {

    /**
     * Kiểm tra kết nối tới Imagen 4 API bằng cách tạo thử ảnh siêu nhỏ
     */
    public static function test_connection($api_key) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/imagen-4.0-generate-001:predict?key=' . $api_key;

        $body = array(
            'instances' => array(
                array('prompt' => 'Một chiếc lá xanh nhỏ, nền mờ, phong cách nhiếp ảnh chân thực.')
            ),
            'parameters' => array(
                'sampleCount'    => 1,
                'outputMimeType' => 'image/jpeg',
                'aspectRatio'    => '1:1'
            )
        );

        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($body),
            'timeout' => 30 // Sinh ảnh mất thời gian hơn
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $res_body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $data = json_decode($res_body, true);
            $err_msg = isset($data['error']['message']) ? $data['error']['message'] : 'Mã lỗi HTTP: ' . $code;
            return array('success' => false, 'message' => $err_msg);
        }

        $data = json_decode($res_body, true);
        $base64 = isset($data['predictions'][0]['bytesBase64Encoded']) ? $data['predictions'][0]['bytesBase64Encoded'] : '';

        if (!empty($base64)) {
            return array('success' => true);
        }

        return array('success' => false, 'message' => 'API không trả về dữ liệu ảnh Base64 hợp lệ.');
    }

    /**
     * Sinh ảnh từ mô tả của Gemini Imagen 4 và lưu vào hệ thống
     * Trả về Attachment ID trong WordPress hoặc false nếu thất bại
     */
    public static function generate_and_save_image($api_key, $prompt, $post_id = 0, $post_title = '', $keyword = '', $set_thumbnail = true) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/imagen-4.0-generate-001:predict?key=' . $api_key;

        // Bổ sung các từ khóa kỹ thuật nhiếp ảnh thương mại cao cấp sạch sẽ, ánh sáng ban ngày lạnh sạch
        $enhanced_prompt = $prompt . ', authentic editorial documentary photography in a real-world environment appropriate to the brand, industry and location, scene and camera angle should vary by article topic, natural human activity, if a product reference is supplied preserve its exact package shape and dominant colors as the primary subject, cool-toned 6000K-6500K daylight, accurate white balance, clean whites and cool grays, true-to-life colors, soft non-yellow shadows, physically plausible materials, no generic packaging of another color, no warm golden-hour light, no yellow or orange cast, no yellow tint, no warm white balance, no amber cast, no golden hour, no warm beige/brown tones, no cinematic color grading, no dramatic advertising lighting, no glossy 3D render, no CGI, no generic AI stock photo, no invented text or logos.';

        $body = array(
            'instances' => array(
                array('prompt' => $enhanced_prompt)
            ),
            'parameters' => array(
                'sampleCount'    => 1,
                'outputMimeType' => 'image/jpeg',
                'aspectRatio'    => '16:9' // Thường dùng cho ảnh banner/featured bài viết
            )
        );

        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($body),
            'timeout' => 60
        ));

        if (is_wp_error($response)) {
            error_log('Agent SEO Image Error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $res_body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            error_log('Agent SEO Image API Error (HTTP ' . $code . '): ' . $res_body);
            return false;
        }

        $data = json_decode($res_body, true);
        $base64_data = isset($data['predictions'][0]['bytesBase64Encoded']) ? $data['predictions'][0]['bytesBase64Encoded'] : '';

        if (empty($base64_data)) {
            error_log('Agent SEO Image Error: Base64 data is empty.');
            return false;
        }

        // Thực hiện lưu trữ file ảnh vào WordPress
        return self::sideload_base64_image($base64_data, $post_title, $post_id, $keyword, $set_thumbnail);
    }

    /**
     * Kiểm tra kết nối tới NVIDIA NIM FLUX API
     */
    public static function test_connection_nvidia($nvidia_key) {
        $url = 'https://ai.api.nvidia.com/v1/genai/black-forest-labs/flux.2-klein-4b';

        $body = array(
            'prompt' => 'Một chiếc lá xanh nhỏ, nền mờ, phong cách nhiếp ảnh chân thực.',
            'width'  => 1024,
            'height' => 1024,
            'seed'   => 0,
            'steps'  => 4
        );

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $nvidia_key
            ),
            'body'    => wp_json_encode($body),
            'timeout' => 30
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $res_body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $data = json_decode($res_body, true);
            $err_msg = isset($data['error']['message']) ? $data['error']['message'] : 'Mã lỗi HTTP: ' . $code;
            if (empty($err_msg) && isset($data['detail'])) {
                $err_msg = is_array($data['detail']) ? wp_json_encode($data['detail']) : $data['detail'];
            }
            return array('success' => false, 'message' => !empty($err_msg) ? $err_msg : $res_body);
        }

        $data = json_decode($res_body, true);
        $base64 = isset($data['artifacts'][0]['base64']) ? $data['artifacts'][0]['base64'] : '';

        if (!empty($base64)) {
            return array('success' => true);
        }

        return array('success' => false, 'message' => 'NVIDIA API không trả về dữ liệu ảnh Base64 hợp lệ.');
    }

    /**
     * Kiểm tra kết nối tới máy chủ sinh ảnh Google Flow / Kaggle tự host
     */
    public static function test_connection_kaggle($kaggle_url) {
        $base_url = preg_replace('/\/generate$/', '', $kaggle_url);
        
        $response = wp_remote_get($base_url, array('timeout' => 15));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => 'Lỗi kết nối tới máy chủ sinh ảnh Google Flow: ' . $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($code === 200 && (strpos($body, 'Kaggle') !== false || strpos($body, 'Google Flow') !== false)) {
            return array('success' => true);
        }
        
        return array('success' => false, 'message' => 'Địa chỉ API phản hồi không đúng (HTTP ' . $code . '). Hãy chắc chắn server python tự động hóa Google Flow đang chạy.');
    }

    /**
     * Validate DukyAI credentials with both CREATE and CHECK. A successful
     * create alone is not enough because an invalid model/account can still
     * fail as soon as the task worker starts processing it.
     */
    public static function test_connection_duky($api_key, $model_key = 'GEM_PIX_2') {
        if (empty($api_key)) {
            return array('success' => false, 'message' => 'Chưa nhập DukyAI API Key.');
        }
        $response = wp_remote_post('https://api-v1.dukyai.com/api/image-task/create', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Api-Key' => $api_key
            ),
            'body' => wp_json_encode(array(
                'prompt' => 'A single green leaf on a clean neutral background, realistic daylight photography',
                'aspectRatio' => 'IMAGE_ASPECT_RATIO_LANDSCAPE',
                'modelKey' => in_array($model_key, array('GEM_PIX_2', 'NARWHAL', 'R2I'), true) ? $model_key : 'GEM_PIX_2',
                'numOutputs' => 1,
                'provider' => 'google'
            )),
            'timeout' => 30
        ));
        if (is_wp_error($response)) {
            self::record_duky_diagnostic('create', $response->get_error_message());
            return array('success' => false, 'message' => 'CREATE không kết nối được: ' . $response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $data = json_decode($raw_body, true);
        $media_id = isset($data['mediaId']) ? sanitize_text_field($data['mediaId']) : '';
        if ($code < 200 || $code >= 300 || empty($media_id)) {
            $message = self::extract_duky_error($data, $raw_body, 'HTTP ' . $code);
            self::record_duky_diagnostic('create', $message, $code);
            return array('success' => false, 'message' => 'CREATE lỗi (HTTP ' . $code . '): ' . $message);
        }

        $check = wp_remote_post('https://api-v1.dukyai.com/api/image-task/check', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Api-Key' => $api_key
            ),
            'body' => wp_json_encode(array('mediaId' => $media_id)),
            'timeout' => 30
        ));
        if (is_wp_error($check)) {
            self::record_duky_diagnostic('check', $check->get_error_message());
            return array('success' => false, 'message' => 'CREATE thành công nhưng CHECK không kết nối được: ' . $check->get_error_message());
        }

        $check_code = wp_remote_retrieve_response_code($check);
        $check_raw = wp_remote_retrieve_body($check);
        $check_data = json_decode($check_raw, true);
        if ($check_code < 200 || $check_code >= 300 || !is_array($check_data)) {
            $message = self::extract_duky_error($check_data, $check_raw, 'HTTP ' . $check_code);
            self::record_duky_diagnostic('check', $message, $check_code);
            return array('success' => false, 'message' => 'CREATE thành công nhưng CHECK lỗi (HTTP ' . $check_code . '): ' . $message);
        }

        $status = isset($check_data['status']) ? sanitize_text_field($check_data['status']) : 'đang xử lý';
        if (in_array(strtolower($status), array('failed', 'error', 'cancelled'), true)) {
            $message = self::extract_duky_error($check_data, $check_raw, 'Task bị từ chối');
            self::record_duky_diagnostic('task', $message, $check_code);
            return array('success' => false, 'message' => 'Task DukyAI thất bại: ' . $message);
        }

        self::clear_duky_diagnostic();
        return array(
            'success' => true,
            'message' => 'CREATE và CHECK đều hoạt động; trạng thái task thử nghiệm: ' . $status . '.'
        );
    }

    private static function extract_duky_error($data, $raw_body, $fallback) {
        if (is_array($data)) {
            foreach (array('error', 'message', 'detail') as $field) {
                if (!empty($data[$field])) {
                    return is_string($data[$field])
                        ? sanitize_text_field($data[$field])
                        : sanitize_text_field(wp_json_encode($data[$field]));
                }
            }
        }
        $raw_body = sanitize_text_field(wp_strip_all_tags((string) $raw_body));
        return $raw_body !== '' ? mb_substr($raw_body, 0, 500) : $fallback;
    }

    private static function record_duky_diagnostic($stage, $message, $http_code = 0) {
        $diagnostic = array(
            'stage' => sanitize_key($stage),
            'message' => sanitize_text_field((string) $message),
            'http_code' => intval($http_code),
            'time' => time()
        );
        update_option('aseo_last_duky_error', $diagnostic, false);
        error_log(
            'Agent SEO DukyAI ' . $diagnostic['stage']
            . ($diagnostic['http_code'] ? ' HTTP ' . $diagnostic['http_code'] : '')
            . ': ' . $diagnostic['message']
        );
    }

    private static function clear_duky_diagnostic() {
        delete_option('aseo_last_duky_error');
    }

    /**
     * Cập nhật progress bar với số giây đã chờ DukyAI vẽ xong (gọi trong lúc polling).
     */
    private static function report_wait_progress($elapsed_seconds, $max_seconds) {
        $stage = get_option('aseo_image_wait_stage', array());
        if (!is_array($stage) || empty($stage['label'])) {
            return;
        }
        $batch = get_option('aseo_batch_status', array());
        if (!is_array($batch) || empty($batch['total'])) {
            return;
        }
        $batch['message'] = $stage['label'] . '... (đã chờ ' . $elapsed_seconds . '/' . $max_seconds . ' giây)';
        $batch['updated_at'] = time();
        update_option('aseo_batch_status', $batch, false);
    }

    /**
     * Create a DukyAI ImageFX task, poll it, download the result and attach it
     * to the correct WordPress post.
     */
    public static function generate_and_save_image_duky($api_key, $prompt, $post_id = 0, $post_title = '', $keyword = '', $image_url = '', $set_thumbnail = true, $model_key_override = '') {
        if (empty($api_key)) {
            self::record_duky_diagnostic('config', 'Chưa cấu hình DukyAI API Key.');
            return false;
        }

        $model_key = !empty($model_key_override) ? $model_key_override : get_option('aseo_duky_model', 'GEM_PIX_2');
        if (!in_array($model_key, array('GEM_PIX_2', 'NARWHAL', 'R2I'), true)) {
            $model_key = 'GEM_PIX_2';
        }
        $headers = array('Content-Type' => 'application/json', 'X-Api-Key' => $api_key);
        $media_id = $post_id > 0 ? get_post_meta($post_id, '_agent_seo_duky_media_id', true) : '';
        $reference_override = !empty($image_url)
            ? ' PRODUCT REFERENCE IS THE PRIMARY SUBJECT: preserve the exact package shape, label layout and dominant colors from the supplied reference image; show one clearly recognizable package in the foreground occupying about 35-50% of the frame. Background packages must be secondary and consistent. Never replace the reference with generic packaging or bags of another color.'
            : ' If no product reference is supplied, do not invent packaging that is not present in the article context.';
        $lighting_override = $reference_override . ' FINAL HARD RULE: real soft natural daylight, open shade, cool overcast sky, or bright overcast light, cool white balance 6000K-6500K, clean whites and cool gray tones, realistic skin tones and soft physically accurate shadows. ABSOLUTELY NO yellow tint, yellow cast, orange cast, warm white balance, amber cast, golden-hour light, tungsten, amber filter, sepia, warm beige tones, cinematic color grading, glossy studio glow, CGI, 3D render or artificial AI lighting. Unedited documentary photograph from a real camera.';
        $body = array(
            'prompt' => preg_replace('/\s+/', ' ', trim($prompt . $lighting_override)),
            'aspectRatio' => 'IMAGE_ASPECT_RATIO_LANDSCAPE',
            'modelKey' => $model_key,
            'numOutputs' => 1,
            'provider' => 'google',
            'seed' => rand(1, 2147483647)
        );

        // Chỉ đọc/tải ảnh tham chiếu lúc tạo task mới. Những lượt poll sau dùng
        // mediaId đã lưu nên không cần tải lại cùng một file.
        if (empty($media_id) && !empty($image_url)) {
            $attachment_id = attachment_url_to_postid($image_url);
            $reference_body = '';
            if ($attachment_id > 0) {
                $file_path = get_attached_file($attachment_id);
                if ($file_path && file_exists($file_path)) {
                    $reference_body = @file_get_contents($file_path);
                }
            }
            if (empty($reference_body)) {
                // Nếu không tìm thấy file cục bộ (hoặc chạy local lỗi loopback), thử tải qua HTTP làm phương án dự phòng
                $reference = wp_remote_get($image_url, array('timeout' => 30, 'sslverify' => false));
                if (!is_wp_error($reference) && wp_remote_retrieve_response_code($reference) === 200) {
                    $reference_body = wp_remote_retrieve_body($reference);
                }
            }
            if (!empty($reference_body)) {
                $body['imageBase64'] = base64_encode($reference_body);
            } else {
                error_log('Agent SEO DukyAI: Could not download or read product reference image: ' . $image_url);
            }
        }

        if (empty($media_id)) {
            $create = wp_remote_post('https://api-v1.dukyai.com/api/image-task/create', array(
                'headers' => $headers,
                'body' => wp_json_encode($body),
                'timeout' => 45
            ));
            if (is_wp_error($create)) {
                self::record_duky_diagnostic('create', $create->get_error_message());
                return false;
            }
            $create_code = wp_remote_retrieve_response_code($create);
            $create_body = wp_remote_retrieve_body($create);
            $create_data = json_decode($create_body, true);
            $media_id = isset($create_data['mediaId']) ? sanitize_text_field($create_data['mediaId']) : '';
            if ($create_code < 200 || $create_code >= 300 || empty($media_id)) {
                self::record_duky_diagnostic(
                    'create',
                    self::extract_duky_error($create_data, $create_body, 'Không nhận được mediaId.'),
                    $create_code
                );
                return false;
            }
            if ($post_id > 0) {
                update_post_meta($post_id, '_agent_seo_duky_media_id', $media_id);
            }
        }

        // Mỗi cron request chỉ kiểm tra trạng thái một lần. Không sleep/poll liên tục
        // trong cùng PHP process vì điều đó từng giữ worker đến khoảng 3 phút.
        $check = wp_remote_post('https://api-v1.dukyai.com/api/image-task/check', array(
            'headers' => $headers,
            'body' => wp_json_encode(array('mediaId' => $media_id)),
            'timeout' => 20
        ));
        if (is_wp_error($check)) {
            self::record_duky_diagnostic('check', $check->get_error_message());
            return new WP_Error('agent_seo_image_pending', 'DukyAI chưa phản hồi; sẽ kiểm tra lại ở worker kế tiếp.');
        }

        $check_code = wp_remote_retrieve_response_code($check);
        $check_body = wp_remote_retrieve_body($check);
        if ($check_code < 200 || $check_code >= 300) {
            // Lỗi xác thực/request sẽ không tự hết khi poll lại cùng mediaId.
            // 408, 429 và 5xx là lỗi tạm thời nên vẫn giữ task để thử sau.
            if ($check_code >= 400 && $check_code < 500 && !in_array($check_code, array(408, 429), true)) {
                self::record_duky_diagnostic(
                    'check',
                    self::extract_duky_error(json_decode($check_body, true), $check_body, 'Request kiểm tra bị từ chối.'),
                    $check_code
                );
                if ($post_id > 0) {
                    delete_post_meta($post_id, '_agent_seo_duky_media_id');
                }
                return false;
            }
            self::record_duky_diagnostic('check', 'Lỗi tạm thời khi kiểm tra task.', $check_code);
            return new WP_Error('agent_seo_image_pending', 'DukyAI tạm thời chưa phản hồi hợp lệ; sẽ kiểm tra lại.');
        }

        $check_data = json_decode($check_body, true);
        if (!is_array($check_data)) {
            self::record_duky_diagnostic('check', 'Phản hồi không phải JSON hợp lệ.', $check_code);
            return new WP_Error('agent_seo_image_pending', 'DukyAI trả dữ liệu chưa hoàn chỉnh; sẽ kiểm tra lại.');
        }
        $status = isset($check_data['status']) ? strtolower($check_data['status']) : '';
        $image_urls = isset($check_data['imageUrls']) && is_array($check_data['imageUrls']) ? $check_data['imageUrls'] : array();

        if (empty($image_urls[0])) {
            if (in_array($status, array('failed', 'error', 'cancelled'), true)) {
                $error = self::extract_duky_error($check_data, $check_body, 'DukyAI báo task thất bại.');
                self::record_duky_diagnostic('task', $error, $check_code);
                if ($post_id > 0) {
                    delete_post_meta($post_id, '_agent_seo_duky_media_id');
                }
                return false;
            }
            return new WP_Error('agent_seo_image_pending', 'DukyAI đang tạo ảnh; sẽ kiểm tra lại sau.');
        }

        $result_url = $image_urls[0];
        if (strpos($result_url, '/') === 0) {
            $result_url = 'https://api-v1.dukyai.com' . $result_url;
        }

        // Thử proxy một lần. Nếu proxy chưa sẵn sàng thì thử URL kết quả trực tiếp;
        // lần cron sau vẫn dùng lại mediaId, không tạo task ảnh trùng.
        $download = wp_remote_post('https://api-v1.dukyai.com/api/download-image', array(
            'headers' => $headers,
            'body'    => wp_json_encode(array('imageUrl' => $result_url)),
            'timeout' => 45
        ));
        if (!is_wp_error($download) && wp_remote_retrieve_response_code($download) === 200) {
            $content_type = wp_remote_retrieve_header($download, 'content-type');
            $download_body = wp_remote_retrieve_body($download);
            if (!empty($content_type) && strpos($content_type, 'application/json') !== false) {
                $proxy_data = json_decode($download_body, true);
                if (!empty($proxy_data['encodedImage'])) {
                    $download_body = base64_decode($proxy_data['encodedImage']);
                }
            }
            if (!empty($download_body) && strlen($download_body) > 1000) {
                $attach_id = self::sideload_base64_image(base64_encode($download_body), $post_title, $post_id, $keyword, $set_thumbnail);
                if ($attach_id) {
                    if ($post_id > 0) {
                        delete_post_meta($post_id, '_agent_seo_duky_media_id');
                    }
                    self::clear_duky_diagnostic();
                    return $attach_id;
                }
            }
        }

        $direct_download = wp_remote_get(esc_url_raw($result_url), array('timeout' => 45, 'redirection' => 3));
        if (!is_wp_error($direct_download) && wp_remote_retrieve_response_code($direct_download) === 200) {
            $download_body = wp_remote_retrieve_body($direct_download);
            if (!empty($download_body) && strlen($download_body) > 1000) {
                $attach_id = self::sideload_base64_image(base64_encode($download_body), $post_title, $post_id, $keyword, $set_thumbnail);
                if ($attach_id) {
                    if ($post_id > 0) {
                        delete_post_meta($post_id, '_agent_seo_duky_media_id');
                    }
                    self::clear_duky_diagnostic();
                    return $attach_id;
                }
            }
        }

        self::record_duky_diagnostic('download', 'Task đã có ảnh nhưng WordPress chưa tải hoặc lưu được tệp ảnh.');
        return new WP_Error('agent_seo_image_pending', 'Ảnh đã tạo nhưng tệp chưa tải được; sẽ thử lại.');
    }

    /**
     * Sinh ảnh từ mô tả bằng NVIDIA NIM FLUX API và lưu vào hệ thống
     */
    public static function generate_and_save_image_nvidia($nvidia_key, $prompt, $post_id = 0, $post_title = '', $keyword = '', $set_thumbnail = true) {
        $url = 'https://ai.api.nvidia.com/v1/genai/black-forest-labs/flux.2-klein-4b';

        // Bổ sung các từ khóa kỹ thuật nhiếp ảnh thương mại cao cấp sạch sẽ, ánh sáng ban ngày lạnh sạch
        $enhanced_prompt = $prompt . ', authentic editorial documentary photography in a real-world environment appropriate to the brand, industry and location, scene and camera angle should vary by article topic, natural human activity, if a product reference is supplied preserve its exact package shape and dominant colors as the primary subject, cool-toned 6000K-6500K daylight, accurate white balance, clean whites and cool grays, true-to-life colors, soft non-yellow shadows, no generic packaging of another color, no warm golden-hour light, no yellow or orange cast, no yellow tint, no warm white balance, no amber cast, no golden hour, no warm beige/brown tones, no cinematic color grading, no dramatic advertising lighting, no glossy 3D render, no CGI, no generic AI stock photo, no invented text or logos.';

        $body = array(
            'prompt' => $enhanced_prompt,
            'width'  => 1024,
            'height' => 1024,
            'seed'   => rand(1, 1000000),
            'steps'  => 4
        );

        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $nvidia_key
            ),
            'body'    => wp_json_encode($body),
            'timeout' => 60
        ));

        if (is_wp_error($response)) {
            error_log('Agent SEO Image NVIDIA Error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $res_body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            error_log('Agent SEO Image NVIDIA API Error (HTTP ' . $code . '): ' . $res_body);
            return false;
        }

        $data = json_decode($res_body, true);
        $base64_data = isset($data['artifacts'][0]['base64']) ? $data['artifacts'][0]['base64'] : '';

        if (empty($base64_data)) {
            error_log('Agent SEO Image NVIDIA Error: Base64 data is empty.');
            return false;
        }

        // Thực hiện lưu trữ file ảnh vào WordPress
        return self::sideload_base64_image($base64_data, $post_title, $post_id, $keyword, $set_thumbnail);
    }

    /**
     * Gửi yêu cầu sinh ảnh đến máy chủ tự động hóa Google Flow
     */
    public static function generate_and_save_image_kaggle($kaggle_url, $prompt, $post_id = 0, $post_title = '', $keyword = '', $image_url = '', $set_thumbnail = true) {
        if (empty($image_url)) {
            error_log('Agent SEO Image Google Flow Error: Missing product reference image URL. Select a WooCommerce product with a featured image.');
            update_option('aseo_last_google_flow_error', array('stage' => 'REFERENCE', 'message' => 'Thiếu URL ảnh tham chiếu.', 'time' => time()), false);
            return false;
        }

        $response = wp_remote_post($kaggle_url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'prompt'    => $prompt,
                'image_url' => $image_url
            )),
            'timeout' => 210 // Google Flow gồm upload ảnh tham chiếu + sinh ảnh + tải ảnh về
        ));

        if (is_wp_error($response)) {
            error_log('Agent SEO Image Google Flow Error: ' . $response->get_error_message());
            update_option('aseo_last_google_flow_error', array('stage' => 'REQUEST', 'message' => $response->get_error_message(), 'time' => time()), false);
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $res_body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            error_log('Agent SEO Image Google Flow API Error (HTTP ' . $code . '): ' . $res_body);
            $error_data = json_decode($res_body, true);
            $error_message = isset($error_data['error']) ? sanitize_text_field($error_data['error']) : 'HTTP ' . $code;
            update_option('aseo_last_google_flow_error', array('stage' => 'API', 'message' => $error_message, 'time' => time()), false);
            return false;
        }

        $data = json_decode($res_body, true);
        $base64_data = isset($data['image']) ? $data['image'] : '';

        if (empty($base64_data)) {
            error_log('Agent SEO Image Google Flow Error: Base64 data is empty.');
            update_option('aseo_last_google_flow_error', array('stage' => 'RESPONSE', 'message' => 'API không trả về trường image Base64.', 'time' => time()), false);
            return false;
        }

        // Thực hiện lưu trữ file ảnh vào WordPress
        $attach_id = self::sideload_base64_image($base64_data, $post_title, $post_id, $keyword, $set_thumbnail);
        if (!$attach_id) {
            update_option('aseo_last_google_flow_error', array('stage' => 'MEDIA', 'message' => 'Không thể lưu ảnh Base64 vào Media Library.', 'time' => time()), false);
            return false;
        }
        delete_option('aseo_last_google_flow_error');
        return $attach_id;
    }

    /**
     * Chuyển đổi Base64 và đưa vào WordPress Media Library
     */
    private static function sideload_base64_image($base64_data, $title, $post_id = 0, $keyword = '', $set_thumbnail = true) {
        // Cần nạp các file quản lý upload của WordPress core
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');

        $image_data = base64_decode($base64_data);
        if (!$image_data) {
            error_log('Agent SEO Sideload Error: Base64 decode failed.');
            return false;
        }

        // Xác thực bytes là ảnh trước khi ghi Media Library. Duky có thể trả PNG/WebP/JPEG,
        // vì vậy không gán cứng phần mở rộng JPEG cho mọi phản hồi.
        $image_info = function_exists('getimagesizefromstring') ? @getimagesizefromstring($image_data) : false;
        if (!$image_info || empty($image_info['mime'])) {
            error_log('Agent SEO Sideload Error: Response is not a valid image.');
            return false;
        }
        $mime_to_ext = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif');
        $mime_type = isset($mime_to_ext[$image_info['mime']]) ? $image_info['mime'] : 'image/jpeg';
        $extension = isset($mime_to_ext[$mime_type]) ? $mime_to_ext[$mime_type] : 'jpg';

        // Đặt tên file chuẩn SEO dựa trên tiêu đề bài viết
        $filename = sanitize_title($title) . '-' . rand(1000, 9999) . '.' . $extension;

        // Lưu file tạm thời vào thư mục upload
        $upload = wp_upload_bits($filename, null, $image_data);

        if (isset($upload['error']) && $upload['error'] !== false) {
            error_log('Agent SEO Sideload Error: ' . $upload['error']);
            return false;
        }

        $caption = 'Hình ảnh minh họa cho: ' . (!empty($keyword) ? $keyword : $title);
        $description = 'Ảnh đại diện chất lượng cao được sinh bởi AI phục vụ bài viết: ' . $title . (!empty($keyword) ? ' (Tối ưu từ khóa: ' . $keyword . ')' : '');

        // Tạo thông tin đính kèm (Attachment) trong CSDL
        $attachment = array(
            'post_mime_type' => $mime_type,
            'post_title'     => sanitize_text_field($title),
            'post_excerpt'   => sanitize_text_field($caption),
            'post_content'   => sanitize_textarea_field($description),
            'post_status'    => 'inherit'
        );

        $attach_id = wp_insert_attachment($attachment, $upload['file'], $post_id);

        if (!is_wp_error($attach_id)) {
            // Tạo metadata kích thước ảnh nhỏ/trung bình/lớn
            $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
            wp_update_attachment_metadata($attach_id, $attach_data);

            // Đặt alt text cho ảnh trùng khớp với từ khóa chính phục vụ SEO Rank Math
            $alt_text = !empty($keyword) ? $keyword : $title;
            update_post_meta($attach_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
            update_post_meta($attach_id, '_agent_seo_generated_image', '1');
            update_post_meta($attach_id, '_agent_seo_generated_for_post', absint($post_id));

            // Chỉ gán thumbnail khi được yêu cầu (ảnh đại diện), bỏ qua cho ảnh phụ.
            if ($set_thumbnail && $post_id > 0) {
                set_post_thumbnail($post_id, $attach_id);
                if (intval(get_post_thumbnail_id($post_id)) !== intval($attach_id)) {
                    update_post_meta($post_id, '_thumbnail_id', intval($attach_id));
                }
                if (intval(get_post_thumbnail_id($post_id)) !== intval($attach_id)) {
                    error_log('Agent SEO Sideload Error: featured image assignment failed for post ID ' . $post_id);
                    return false;
                }
            }

            return $attach_id;
        } else {
            error_log('Agent SEO Sideload Error: ' . $attach_id->get_error_message());
            return false;
        }
    }
}
