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
    public static function generate_and_save_image($api_key, $prompt, $post_id = 0, $post_title = '', $keyword = '') {
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
        return self::sideload_base64_image($base64_data, $post_title, $post_id, $keyword);
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
     * Validate DukyAI credentials by creating a lightweight asynchronous task.
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
            return array('success' => false, 'message' => $response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code === 200 && !empty($data['ok']) && !empty($data['mediaId'])) {
            return array('success' => true);
        }
        $message = isset($data['error']) ? $data['error'] : 'HTTP ' . $code;
        return array('success' => false, 'message' => is_string($message) ? $message : wp_json_encode($message));
    }

    /**
     * Create a DukyAI ImageFX task, poll it, download the result and attach it
     * to the correct WordPress post.
     */
    public static function generate_and_save_image_duky($api_key, $prompt, $post_id = 0, $post_title = '', $keyword = '', $image_url = '', $max_poll_override = 0) {
        if (empty($api_key)) {
            return false;
        }

        $model_key = get_option('aseo_duky_model', 'GEM_PIX_2');
        if (!in_array($model_key, array('GEM_PIX_2', 'NARWHAL', 'R2I'), true)) {
            $model_key = 'GEM_PIX_2';
        }
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

        if (!empty($image_url)) {
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

        $headers = array('Content-Type' => 'application/json', 'X-Api-Key' => $api_key);
        $media_id = $post_id > 0 ? get_post_meta($post_id, '_agent_seo_duky_media_id', true) : '';
        if (empty($media_id)) {
            $create = wp_remote_post('https://api-v1.dukyai.com/api/image-task/create', array(
                'headers' => $headers,
                'body' => wp_json_encode($body),
                'timeout' => 45
            ));
            if (is_wp_error($create)) {
                error_log('Agent SEO DukyAI create error: ' . $create->get_error_message());
                return false;
            }
            $create_code = wp_remote_retrieve_response_code($create);
            $create_data = json_decode(wp_remote_retrieve_body($create), true);
            $media_id = isset($create_data['mediaId']) ? sanitize_text_field($create_data['mediaId']) : '';
            if ($create_code !== 200 || empty($media_id)) {
                error_log('Agent SEO DukyAI create error (HTTP ' . $create_code . '): ' . wp_remote_retrieve_body($create));
                return false;
            }
            if ($post_id > 0) {
                update_post_meta($post_id, '_agent_seo_duky_media_id', $media_id);
            }
        }

        // Cho phép chờ tối đa 36 lần (khoảng 3 phút) để chắc chắn AI vẽ xong và lấy được ảnh ngay trong lần chạy đầu tiên.
        // Khi chạy batch (quick mode), chỉ poll 6 lần (~30s) rồi để retry task xử lý tiếp.
        $max_attempts = ($max_poll_override > 0) ? intval($max_poll_override) : 36;
        for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
            if ($attempt > 0) {
                sleep(5);
            }
            $check = wp_remote_post('https://api-v1.dukyai.com/api/image-task/check', array(
                'headers' => $headers,
                'body' => wp_json_encode(array('mediaId' => $media_id)),
                'timeout' => 30
            ));
            if (is_wp_error($check)) {
                continue;
            }
            $check_data = json_decode(wp_remote_retrieve_body($check), true);
            $status = isset($check_data['status']) ? strtolower($check_data['status']) : '';
            $image_urls = isset($check_data['imageUrls']) && is_array($check_data['imageUrls']) ? $check_data['imageUrls'] : array();
            if (!empty($image_urls[0])) {
                $result_url = $image_urls[0];
                if (strpos($result_url, '/') === 0) {
                    $result_url = 'https://api-v1.dukyai.com' . $result_url;
                }
                // CDN có thể trả 404/502 trong vài giây đầu; giữ task lại để poll và tải lại thay vì bỏ ngay.
                for ($download_attempt = 0; $download_attempt < 3; $download_attempt++) {
                    $download = wp_remote_get(esc_url_raw($result_url), array('timeout' => 60, 'redirection' => 3));
                    if (!is_wp_error($download) && wp_remote_retrieve_response_code($download) === 200) {
                        $download_body = wp_remote_retrieve_body($download);
                        $attach_id = self::sideload_base64_image(base64_encode($download_body), $post_title, $post_id, $keyword);
                        if ($attach_id && $post_id > 0) {
                            delete_post_meta($post_id, '_agent_seo_duky_media_id');
                        }
                        if ($attach_id) {
                            return $attach_id;
                        }
                    }
                    if ($download_attempt < 2) {
                        sleep(2);
                    }
                }
                // Thử lại lần poll kế tiếp; không xóa mediaId khi CDN chưa sẵn sàng.
                continue;
            }
            if (in_array($status, array('failed', 'error', 'cancelled'), true)) {
                $error = isset($check_data['error']) ? $check_data['error'] : 'Unknown DukyAI error';
                error_log('Agent SEO DukyAI task failed: ' . (is_string($error) ? $error : wp_json_encode($error)));
                if ($post_id > 0) {
                    delete_post_meta($post_id, '_agent_seo_duky_media_id');
                }
                return false;
            }
        }
        error_log('Agent SEO DukyAI task timed out: ' . $media_id);
        return false;
    }

    /**
     * Sinh ảnh từ mô tả bằng NVIDIA NIM FLUX API và lưu vào hệ thống
     */
    public static function generate_and_save_image_nvidia($nvidia_key, $prompt, $post_id = 0, $post_title = '', $keyword = '') {
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
        return self::sideload_base64_image($base64_data, $post_title, $post_id, $keyword);
    }

    /**
     * Gửi yêu cầu sinh ảnh đến máy chủ tự động hóa Google Flow
     */
    public static function generate_and_save_image_kaggle($kaggle_url, $prompt, $post_id = 0, $post_title = '', $keyword = '', $image_url = '') {
        if (empty($image_url)) {
            error_log('Agent SEO Image Google Flow Error: Missing product reference image URL. Select a WooCommerce product with a featured image.');
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
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $res_body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            error_log('Agent SEO Image Google Flow API Error (HTTP ' . $code . '): ' . $res_body);
            return false;
        }

        $data = json_decode($res_body, true);
        $base64_data = isset($data['image']) ? $data['image'] : '';

        if (empty($base64_data)) {
            error_log('Agent SEO Image Google Flow Error: Base64 data is empty.');
            return false;
        }

        // Thực hiện lưu trữ file ảnh vào WordPress
        return self::sideload_base64_image($base64_data, $post_title, $post_id, $keyword);
    }

    /**
     * Chuyển đổi Base64 và đưa vào WordPress Media Library
     */
    private static function sideload_base64_image($base64_data, $title, $post_id = 0, $keyword = '') {
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

            // Gán làm ảnh tiêu biểu nếu có post_id
            if ($post_id > 0) {
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
