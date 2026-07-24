<?php
/**
 * Module tự động chèn liên kết nội bộ (Internal Link)
 */

defined('ABSPATH') || exit;

class Agent_SEO_Linker {

    /**
     * Tự động thiết lập liên kết chéo giữa bài viết mới và bài viết cũ
     */
    public static function add_internal_links($post_id) {
        $current_post = get_post($post_id);
        if (!$current_post || $current_post->post_type !== 'post') {
            return;
        }

        $current_title   = $current_post->post_title;
        $current_content = $current_post->post_content;
        $current_url     = get_permalink($post_id);

        // Lấy toàn bộ danh sách các bài viết khác
        $other_posts = get_posts(array(
            'post_type'    => 'post',
            'post_status'  => 'publish',
            'numberposts'  => -1,
            'exclude'      => array($post_id)
        ));

        if (empty($other_posts)) {
            return;
        }

        // --- Hướng 1: Chèn liên kết từ bài cũ về bài mới ---
        // Chúng ta duyệt từng bài viết cũ, nếu tìm thấy Tiêu đề của bài mới (hoặc cụm từ liên quan) thì chèn link trỏ về bài mới
        foreach ($other_posts as $old_post) {
            $old_content = $old_post->post_content;
            
            // Thử chèn link chứa từ khóa là tiêu đề bài mới vào nội dung bài cũ
            $updated_old_content = self::insert_link_safely($old_content, $current_title, $current_url);
            
            // Nếu có thay đổi, cập nhật bài viết cũ
            if ($updated_old_content !== $old_content) {
                wp_update_post(array(
                    'ID'           => $old_post->ID,
                    'post_content' => $updated_old_content
                ));
            }
        }

        // --- Hướng 2: Chèn liên kết từ bài mới về các bài cũ ---
        // Duyệt từng bài viết cũ, tìm kiếm tiêu đề của chúng trong bài mới và chèn link trỏ về bài cũ
        $new_content_updated = $current_content;
        $link_count = 0;
        
        foreach ($other_posts as $old_post) {
            if ($link_count >= 4) {
                break; // Giới hạn tối đa 4 internal link mỗi bài để tránh spam
            }

            $old_title = $old_post->post_title;
            $old_url   = get_permalink($old_post->ID);

            $temp_content = self::insert_link_safely($new_content_updated, $old_title, $old_url);
            if ($temp_content !== $new_content_updated) {
                $new_content_updated = $temp_content;
                $link_count++;
            }
        }

        // Cập nhật lại nội dung bài viết mới
        if ($new_content_updated !== $current_content) {
            // Hủy kích hoạt WP-Cron hook tạm thời để tránh vòng lặp lưu bài viết (nếu có)
            remove_action('save_post', array('Agent_SEO_Orchestrator', 'handle_save_post'));
            
            wp_update_post(array(
                'ID'           => $post_id,
                'post_content' => $new_content_updated
            ));
            
            // Kích hoạt lại hook save_post
            add_action('save_post', array('Agent_SEO_Orchestrator', 'handle_save_post'), 10, 3);
        }
    }

    /**
     * Chèn thẻ liên kết <a> vào nội dung văn bản một cách an toàn mà không phá vỡ HTML
     */
    private static function insert_link_safely($content, $phrase, $link_url) {
        if (empty($phrase) || strlen($phrase) < 4) {
            return $content;
        }

        // Cắt toàn bộ nội dung HTML thành các phần tử Tags và Text
        $parts = preg_split('/(<[^>]*>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $in_anchor = false;
        $replaced = false;

        foreach ($parts as &$part) {
            if (empty($part)) {
                continue;
            }

            // Nếu đây là tag HTML
            if ($part[0] === '<') {
                // Kiểm tra xem có bắt đầu bằng thẻ <a> hay kết thúc bằng </a> không
                if (preg_match('/^<a\s/i', $part)) {
                    $in_anchor = true;
                } elseif (preg_match('/^<\/a>/i', $part)) {
                    $in_anchor = false;
                }
            } else {
                // Đây là văn bản thô bên ngoài tag. Chúng ta thực hiện chèn liên kết.
                if (!$in_anchor && !$replaced) {
                    $quoted_phrase = preg_quote($phrase, '/');
                    
                    // Regex tìm kiếm không phân biệt chữ hoa/thường
                    $pattern = '/' . $quoted_phrase . '/iu';
                    
                    if (preg_match($pattern, $part)) {
                        // Chỉ thay thế từ khóa đầu tiên xuất hiện
                        $part = preg_replace($pattern, '<a href="' . esc_url($link_url) . '">$0</a>', $part, 1);
                        $replaced = true;
                    }
                }
            }
        }

        return implode('', $parts);
    }
}
