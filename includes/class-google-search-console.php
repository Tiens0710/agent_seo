<?php
/** Google Search Console OAuth and sitemap integration. */
defined('ABSPATH') || exit;

class Agent_SEO_GSC {
    const SCOPE = 'https://www.googleapis.com/auth/webmasters';

    public static function redirect_uri() {
        return admin_url('admin-post.php?action=agent_seo_google_oauth_callback');
    }

    public static function register_hooks() {
        add_action('admin_post_agent_seo_google_oauth_start', array(__CLASS__, 'start_oauth'));
        add_action('admin_post_agent_seo_google_oauth_callback', array(__CLASS__, 'oauth_callback'));
        add_action('admin_post_agent_seo_google_gsc_test', array(__CLASS__, 'test_gsc_submit'));
    }

    public static function start_oauth() {
        if (!current_user_can('manage_options')) wp_die('Không có quyền truy cập.');
        check_admin_referer('agent_seo_google_oauth_start');
        $client_id = trim(get_option('aseo_gsc_client_id', ''));
        if ($client_id === '') self::back('error', 'Bạn chưa nhập Google OAuth Client ID.');
        $state = wp_generate_password(32, false, false);
        set_transient('aseo_gsc_oauth_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS);
        $query = array(
            'client_id' => $client_id,
            'redirect_uri' => self::redirect_uri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state
        );
        // Google là host OAuth bên ngoài nên wp_safe_redirect sẽ chặn; URL đã được
        // tạo từ các tham số đã mã hóa ở trên.
        wp_redirect('https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        exit;
    }

    public static function oauth_callback() {
        if (!current_user_can('manage_options')) wp_die('Không có quyền truy cập.');
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
        $expected = get_transient('aseo_gsc_oauth_state_' . get_current_user_id());
        delete_transient('aseo_gsc_oauth_state_' . get_current_user_id());
        if (!$state || !$expected || !hash_equals($expected, $state)) self::back('error', 'Xác thực Google không hợp lệ hoặc đã hết hạn.');
        if (!empty($_GET['error'])) self::back('error', 'Bạn đã hủy kết nối Google.');
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'timeout' => 20,
            'body' => array(
                'code' => isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '',
                'client_id' => get_option('aseo_gsc_client_id', ''),
                'client_secret' => get_option('aseo_gsc_client_secret', ''),
                'redirect_uri' => self::redirect_uri(),
                'grant_type' => 'authorization_code'
            )
        ));
        $data = is_wp_error($response) ? array() : json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['access_token'])) self::back('error', 'Google không trả về token. Kiểm tra Client ID, Secret và Redirect URI.');
        $data['saved_at'] = time();
        update_option('aseo_gsc_token', $data, false);
        self::back('success', 'Đã kết nối Google Search Console thành công.');
    }

    public static function access_token() {
        $token = get_option('aseo_gsc_token', array());
        if (!is_array($token) || empty($token['access_token'])) return '';
        if (!empty($token['expires_in']) && !empty($token['saved_at']) && time() < ($token['saved_at'] + intval($token['expires_in']) - 60)) return $token['access_token'];
        if (empty($token['refresh_token'])) return '';
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array('timeout' => 20, 'body' => array(
            'client_id' => get_option('aseo_gsc_client_id', ''), 'client_secret' => get_option('aseo_gsc_client_secret', ''),
            'refresh_token' => $token['refresh_token'], 'grant_type' => 'refresh_token'
        )));
        $fresh = is_wp_error($response) ? array() : json_decode(wp_remote_retrieve_body($response), true);
        if (empty($fresh['access_token'])) return '';
        $token = array_merge($token, $fresh, array('saved_at' => time()));
        update_option('aseo_gsc_token', $token, false);
        return $token['access_token'];
    }

    public static function submit_sitemap() {
        $site = trim(get_option('aseo_gsc_property', ''));
        $sitemap = trim(get_option('aseo_gsc_sitemap_url', ''));
        $access = self::access_token();
        if (!$site || !$sitemap || !$access) return false;
        $url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($site) . '/sitemaps/' . rawurlencode($sitemap);
        $response = wp_remote_request($url, array('method' => 'PUT', 'timeout' => 20, 'headers' => array('Authorization' => 'Bearer ' . $access)));
        $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) update_option('aseo_gsc_last_sitemap_submit', time(), false);
        return $code >= 200 && $code < 300;
    }

    public static function test_gsc_submit() {
        if (!current_user_can('manage_options')) wp_die('Không có quyền truy cập.');
        check_admin_referer('agent_seo_google_gsc_test');
        
        $site = trim(get_option('aseo_gsc_property', ''));
        $sitemap = trim(get_option('aseo_gsc_sitemap_url', ''));
        $access = self::access_token();
        
        if (empty($access)) {
            self::back('error', 'Chưa kết nối Google OAuth hoặc Token đã hết hạn. Vui lòng bấm Kết nối với Google.');
        }
        if (empty($site)) {
            self::back('error', 'Bạn chưa nhập Search Console Property.');
        }
        if (empty($sitemap)) {
            self::back('error', 'Bạn chưa nhập Sitemap URL.');
        }
        
        $url = 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($site) . '/sitemaps/' . rawurlencode($sitemap);
        $response = wp_remote_request($url, array(
            'method' => 'PUT',
            'timeout' => 25,
            'headers' => array('Authorization' => 'Bearer ' . $access)
        ));
        
        if (is_wp_error($response)) {
            self::back('error', 'Lỗi kết nối mạng: ' . $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($code >= 200 && $code < 300) {
            update_option('aseo_gsc_last_sitemap_submit', time(), false);
            self::back('success', 'Gửi sitemap thành công! Google API phản hồi mã HTTP ' . $code . ' (Thành công).');
        } else {
            $err_data = json_decode($body, true);
            $err_msg = isset($err_data['error']['message']) ? $err_data['error']['message'] : 'HTTP ' . $code;
            self::back('error', 'Google API báo lỗi: ' . $err_msg . ' (Chi tiết phản hồi: ' . esc_attr(mb_substr($body, 0, 150)) . ')');
        }
    }

    private static function back($type, $message) {
        $url = add_query_arg(array('page' => 'agent-seo-settings', 'aseo_gsc_' . $type => rawurlencode($message)), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }
}
