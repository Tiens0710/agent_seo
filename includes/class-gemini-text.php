<?php
/**
 * Client kết nối Gemini API phục vụ viết bài chuẩn SEO
 */

defined('ABSPATH') || exit;

class Agent_SEO_Gemini_Text {

    /**
     * Kiểm tra kết nối tới Gemini API
     */
    public static function test_connection($api_key) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=' . $api_key;
        
        $body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array('text' => 'Hãy trả lời chính xác từ "OK" bằng tiếng Anh, không kèm theo dấu câu hay từ nào khác.')
                    )
                )
            )
        );

        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($body),
            'timeout' => 15
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
        $text = isset($data['candidates'][0]['content']['parts'][0]['text']) ? trim($data['candidates'][0]['content']['parts'][0]['text']) : '';

        if (strtoupper($text) === 'OK') {
            return array('success' => true);
        }

        return array('success' => false, 'message' => 'Phản hồi từ AI không đúng định dạng mong đợi: ' . $text);
    }

    public static function parse_article_brief($api_key, $brief) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=' . rawurlencode($api_key);
        $instruction = "Phân tích yêu cầu viết bài SEO dưới đây và trả về DUY NHẤT JSON hợp lệ, không markdown. "
            . "Các khóa bắt buộc: topic, location, intent, backlink, secondary_keywords, instructions. "
            . "topic là chủ đề chính; location là khu vực; intent chọn một trong: Tìm hiểu thông tin, So sánh và lựa chọn, Tìm nơi mua / nhận báo giá, Hướng dẫn sử dụng. "
            . "secondary_keywords là chuỗi tối đa 5 từ khóa, ngăn cách bằng dấu phẩy. Nếu không có dữ liệu thì để chuỗi rỗng. "
            . "Không được bịa backlink; chỉ dùng URL xuất hiện trong yêu cầu. Yêu cầu: " . $brief;
        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode(array('contents' => array(array('parts' => array(array('text' => $instruction))))), JSON_UNESCAPED_UNICODE),
            'timeout' => 30
        ));
        if (is_wp_error($response)) return array('success' => false, 'message' => $response->get_error_message());
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            return array('success' => false, 'message' => isset($data['error']['message']) ? $data['error']['message'] : 'Lỗi API HTTP ' . $code);
        }
        $text = isset($data['candidates'][0]['content']['parts'][0]['text']) ? trim($data['candidates'][0]['content']['parts'][0]['text']) : '';
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
        $parsed = json_decode(trim($text), true);
        if (!is_array($parsed)) return array('success' => false, 'message' => 'AI trả về dữ liệu không đúng định dạng.');
        return array('success' => true, 'brief' => array(
            'topic' => sanitize_text_field(isset($parsed['topic']) ? $parsed['topic'] : ''),
            'location' => sanitize_text_field(isset($parsed['location']) ? $parsed['location'] : ''),
            'intent' => sanitize_text_field(isset($parsed['intent']) ? $parsed['intent'] : 'Tìm hiểu thông tin'),
            'backlink' => esc_url_raw(isset($parsed['backlink']) ? $parsed['backlink'] : ''),
            'secondary_keywords' => sanitize_text_field(isset($parsed['secondary_keywords']) ? $parsed['secondary_keywords'] : ''),
            'instructions' => sanitize_textarea_field(isset($parsed['instructions']) ? $parsed['instructions'] : '')
        ));
    }

    /**
     * Viết bài viết chi tiết dựa trên từ khóa bằng Gemini 3.1 Flash Lite
     * Trả về mảng chứa title, excerpt, content
     */
    public static function generate_content($api_key, $keyword, $niche, $brand_voice, $brand_data = array(), $batch_started_at = 0) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=' . $api_key;

        $brand_name = isset($brand_data['brand_name']) ? trim($brand_data['brand_name']) : '';
        $brand_address = self::normalize_contact_value(isset($brand_data['brand_address']) ? $brand_data['brand_address'] : '');
        $brand_phone = self::normalize_contact_value(isset($brand_data['brand_phone']) ? $brand_data['brand_phone'] : '');
        $brand_contact = isset($brand_data['brand_contact']) ? trim($brand_data['brand_contact']) : '';
        $brand_price = isset($brand_data['brand_price']) ? trim($brand_data['brand_price']) : '';
        $brand_cta = isset($brand_data['brand_cta']) ? trim($brand_data['brand_cta']) : '';
        $article_topic = isset($brand_data['article_topic']) ? trim($brand_data['article_topic']) : '';
        $global_secondary_keywords = isset($brand_data['global_secondary_keywords']) ? trim($brand_data['global_secondary_keywords']) : '';
        $source_website = isset($brand_data['source_website']) ? trim($brand_data['source_website']) : '';
        $user_brief = isset($brand_data['user_brief']) ? trim($brand_data['user_brief']) : '';
        $backlink_url = '';
        if (preg_match('#https?://[^\s<>"\']+#i', $user_brief, $brief_url_match)) {
            $backlink_url = esc_url_raw(rtrim($brief_url_match[0], '.,);'));
        }
        if (empty($backlink_url) && !empty($source_website)) {
            $backlink_url = esc_url_raw($source_website);
        }

        $brand_section = "";
        if (!empty($brand_name)) {
            $brand_section .= "2. LỒNG GHÉP THÔNG TIN THƯƠNG HIỆU THỰC TẾ:
   - Tên thương hiệu/Cửa hàng: " . $brand_name . "\n";
            if (!empty($brand_address)) {
                $brand_section .= "   - Địa chỉ cửa hàng: " . $brand_address . "\n";
            }
            if (!empty($brand_phone)) {
                $brand_section .= "   - Hotline tư vấn/Zalo: " . $brand_phone . "\n";
            }
            if (!empty($brand_contact)) {
                $brand_section .= "   - Người phụ trách/CSKH hỗ trợ: " . $brand_contact . "\n";
            }
            if (!empty($brand_cta)) {
                $brand_section .= "   - Yêu cầu hành động (CTA): " . $brand_cta . "\n";
            }
            $brand_section .= "   Hãy đưa các thông tin doanh nghiệp thực tế này vào bài viết một cách khéo léo, tự nhiên ở phần liên hệ hoặc phần hướng dẫn nơi mua hàng uy tín.\n";
        } else {
            $brand_section .= "2. THÔNG TIN THƯƠNG HIỆU: Hãy viết bài viết khách quan, chia sẻ kinh nghiệm chung, không bịa đặt hoặc tự bịa ra thông tin thương hiệu, địa chỉ hay số điện thoại nào khác.\n";
        }

        $price_section = "";
        if (!empty($brand_price)) {
            $price_section .= "3. THÔNG TIN SẢN PHẨM & GIÁ THAM KHẢO:
   - " . $brand_price . "\n   Hãy lồng ghép các thông tin giá cả và chất lượng này vào nội dung bài viết để tăng tính thuyết phục.\n";
        } else {
            $price_section .= "3. THÔNG TIN SẢN PHẨM/DỊCH VỤ: Trình bày thông tin chuyên môn phù hợp với lĩnh vực, không tự bịa thông số, chứng nhận, giá hoặc công dụng.\n";
        }

        $clean_phone = preg_replace('/[^0-9]/', '', $brand_phone);

        $brand_section = "";
        if (!empty($brand_name)) {
            $brand_section .= "2. LỒNG GHÉP THÔNG TIN THƯƠNG HIỆU THỰC TẾ:
   - Tên thương hiệu/Cửa hàng: " . $brand_name . "\n";
            if (!empty($brand_address)) {
                $brand_section .= "   - Địa chỉ cửa hàng: " . $brand_address . "\n";
            }
            if (!empty($brand_phone)) {
                $brand_section .= "   - Hotline tư vấn/Zalo: " . $brand_phone . " (Link Zalo: https://zalo.me/" . $clean_phone . ")\n";
            }
            if (!empty($brand_contact)) {
                $brand_section .= "   - Người phụ trách/CSKH hỗ trợ: " . $brand_contact . "\n";
            }
            if (!empty($brand_cta)) {
                $brand_section .= "   - Yêu cầu hành động (CTA): " . $brand_cta . "\n";
            }
            $brand_section .= "   Chú ý quan trọng: Tên người phụ trách CSKH (" . $brand_contact . ") chỉ được xuất hiện ở câu văn liên hệ nhỏ bên dưới. TUYỆT ĐỐI không được dùng tên cá nhân này đặt lên các thẻ tiêu đề H2/H3 lớn (ví dụ: cấm viết tiêu đề như 'Cam kết từ Vy Hoàng', thay vào đó phải viết là 'Cam kết chất lượng từ " . $brand_name . "').\n";
        } else {
            $brand_section .= "2. THÔNG TIN THƯƠNG HIỆU: Hãy viết bài viết khách quan, chia sẻ kinh nghiệm chung, không bịa đặt hoặc tự bịa ra thông tin thương hiệu, địa chỉ hay số điện thoại nào khác.\n";
        }

        $price_section = "";
        if (!empty($brand_price)) {
            $price_section .= "3. THÔNG TIN SẢN PHẨM & GIÁ THAM KHẢO:
   - " . $brand_price . "\n";
        } else {
            $price_section .= "3. THÔNG TIN SẢN PHẨM/DỊCH VỤ: Trình bày thông tin chuyên môn phù hợp với lĩnh vực, không tự bịa thông số, chứng nhận, giá hoặc công dụng.\n";
        }

        $product_section = "";
        $product_info = isset($brand_data['product_info']) ? $brand_data['product_info'] : '';
        if (!empty($product_info)) {
            $product_section = "4. SẢN PHẨM MỤC TIÊU CẦN SEO (WOOCOMMERCE):\n" . $product_info . "\n" .
                "   Hãy phân tích sâu sắc đặc điểm của sản phẩm/dịch vụ này, lồng ghép thông tin và liên kết mua hàng hoặc liên hệ bằng anchor text tự nhiên, liên quan trực tiếp; tuyệt đối không chèn gượng ép.\n";
        }

        $prod_name = !empty($brand_data['product_name']) ? $brand_data['product_name'] : (!empty($brand_name) ? $brand_name : 'Sản phẩm hoặc dịch vụ nổi bật');
        $prod_desc = !empty($brand_data['product_desc']) ? $brand_data['product_desc'] : (!empty($brand_price) ? $brand_price : 'Thông tin sản phẩm hoặc dịch vụ cần được mô tả chính xác theo dữ liệu thực tế của website.');
        $prod_image = !empty($brand_data['product_image']) ? $brand_data['product_image'] : '';
        $prod_url = !empty($brand_data['product_url']) ? $brand_data['product_url'] : home_url('/');

        $prompt = "Bạn là một chuyên gia SEO Content Writer có kinh nghiệm trong lĩnh vực \"{$niche}\". Hãy dựa hoàn toàn vào dữ liệu thương hiệu, sản phẩm/dịch vụ và chủ đề được cung cấp; không mặc định website thuộc ngành gạo, thực phẩm hay bất kỳ địa phương nào.
Hãy viết một bài viết chuyên sâu về từ khóa: \"{$keyword}\" thuộc lĩnh vực \"{$niche}\" với tông giọng thương hiệu là \"{$brand_voice}\".

Yêu cầu bài viết để chuẩn E-E-A-T của Google:
1. VĂN PHONG TỰ NHIÊN, THỰC TẾ & ĐỘ DÀI CHUYÊN SÂU:
   - Tổng độ dài bài viết bắt buộc phải đạt từ 1200 đến 1500 từ (không được trả về bài 700-900 từ). Trước khi trả JSON, hãy tự đếm từ; nếu chưa đủ 1200 từ thì mở rộng bằng ví dụ, quy trình, tiêu chí lựa chọn, FAQ và tình huống thực tế. Hãy viết cực kỳ chi tiết, phân tích chuyên sâu từng mục, tránh việc viết hời hợt hoặc chỉ viết 1-2 câu ngắn cho mỗi phần.
   - Dưới mỗi tiêu đề chính H2 hoặc H3, viết ít nhất 2 đến 3 đoạn văn chi tiết (mỗi đoạn vẫn giữ độ dài vừa phải từ 2-3 câu để đảm bảo trải nghiệm đọc trên di động, nhưng có nhiều đoạn kế tiếp nhau để tăng dung lượng bài).
   - Nghiêm cấm sử dụng các từ ngữ rập khuôn sáo rỗng của AI như: \"Nhắc đến... không thể không\", \"linh hồn của bữa cơm\", \"hạt ngọc trời\", \"đất nước hình chữ S\", \"sợi dây kết nối tình thân\", \"chân chất\", \"thượng hạng\".
   - Đóng vai người đại diện thương hiệu hoặc chuyên gia đúng lĩnh vực \"{$niche}\", dùng ngôn ngữ gần gũi, tin cậy và phù hợp với khách hàng mục tiêu.
   - Tuyệt đối KHÔNG sử dụng các tiêu đề phụ dạng \"Lời kết\", \"Tóm lại\", \"Kết luận\" ở cuối bài. Hãy kết thúc bài viết bằng lời kêu gọi hành động (CTA) mua hàng tự nhiên hoặc đoạn thông tin liên hệ.

6. HƯỚNG DẪN CẤU TRÚC LAYOUT HTML LINH HOẠT & TỰ NHIÊN:
   Để tránh việc mọi bài viết đều rập khuôn một bố cục giống hệt nhau gây nhàm chán và thiếu chuyên nghiệp cho người đọc, hãy phân bổ các phần tử HTML sau đây một cách thông minh, phù hợp với ngữ cảnh của chủ đề bài viết:
   - **Mở bài (Sapo):** Viết khoảng 1-2 đoạn văn ngắn dẫn dắt tự nhiên, chứa từ khóa chính \"{$keyword}\".
   - **Khung trưng bày sản phẩm:** Nếu bài viết có liên quan trực tiếp đến việc bán hàng, so sánh sản phẩm hoặc giới thiệu sản phẩm mục tiêu, hãy chèn hộp HTML giới thiệu sản phẩm dưới đây ở vị trí thích hợp (dưới sapo hoặc trong thân bài khi bắt đầu đề xuất sản phẩm):
     <div style=\"border: 2px solid #0B5226; padding: 20px; border-radius: 8px; margin: 30px 0; background-color: #f9fbf9; display: flex; align-items: center; flex-wrap: wrap;\">
       <div style=\"flex: 1; min-width: 120px; text-align: center; margin-right: 20px;\"><img style=\"width: 120px; border-radius: 4px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); margin: 0 auto; display: block;\" src=\"{$prod_image}\" alt=\"{$keyword}\" /></div>
       <div style=\"flex: 3; min-width: 250px;\">
         <p style=\"margin: 0 0 8px 0; color: #0b5226; font-size: 18px; font-weight: bold; font-family: sans-serif;\">Sản phẩm: {$prod_name}</p>
         <p style=\"margin: 0 0 15px 0; font-size: 14px; color: #555; line-height: 1.5; font-family: sans-serif;\">{$prod_desc}</p>
         <div style=\"display: flex; gap: 10px; flex-wrap: wrap;\">
           <a style=\"background-color: #0b5226; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 14px; font-family: sans-serif;\" href=\"{$zalo_link}\">Liên hệ tư vấn</a>
           <a style=\"border: 1px solid #0B5226; color: #0b5226; padding: 10px 20px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 14px; background-color: white; font-family: sans-serif;\" href=\"{$prod_url}\">Xem Chi Tiết Sản Phẩm</a>
         </div>
       </div>
     </div>
     Nếu chủ đề bài viết hoàn toàn chung hoặc chia sẻ kiến thức khách quan không liên quan đến việc bán sản phẩm này, bạn có thể lược bỏ khung trưng bày này hoặc chỉ đặt một box nhỏ ở cuối bài.
   - **Tiêu đề phụ H2/H3:** Đặt tiêu đề một cách sáng tạo và đa dạng theo chủ đề bài viết, tự động thêm ID cho thẻ h2 dạng `<h2 id=\"slug-tieu-de\">1. Tiêu đề...</h2>`. Tránh các tiêu đề phụ rập khuôn máy móc như \"5 tiêu chí...\" hay \"5 lợi ích...\" trên mọi bài viết.
   - **Bảng dữ liệu/so sánh (HTML <table>):** Chèn bảng HTML ở vị trí hợp lý trong bài viết NẾU ngữ cảnh bài viết cần so sánh hoặc liệt kê thông số (ví dụ: so sánh các loại gạo, bảng giá trị dinh dưỡng, bảng giá, checklist kiểm tra chất lượng). Nếu chủ đề bài viết không thích hợp để dùng bảng, hãy lược bỏ hoặc thay thế bằng danh sách liệt kê chi tiết hoặc hộp thông tin (blockquotes) để bài viết tự nhiên nhất. Định dạng bảng (nếu dùng):
     <table style=\"width: 100%; border-collapse: collapse; margin: 20px 0; font-family: sans-serif; text-align: left;\">
       <thead>
         <tr style=\"background-color: #0b5226; color: #ffffff;\">
           <th style=\"padding: 12px; border: 1px solid #dddddd;\">Tiêu chí / Đặc điểm</th>
           <th style=\"padding: 12px; border: 1px solid #dddddd;\">" . (!empty($brand_name) ? $brand_name : 'Sản phẩm của chúng tôi') . "</th>
           <th style=\"padding: 12px; border: 1px solid #dddddd;\">Thông tin so sánh/tham chiếu khác</th>
         </tr>
       </thead>
       <tbody>
         <tr>
           <td style=\"padding: 12px; border: 1px solid #dddddd; font-weight: bold;\">Đặc tính 1</td>
           <td style=\"padding: 12px; border: 1px solid #dddddd; color: green; font-weight: bold;\">Thông tin sản phẩm/dịch vụ thực tế</td>
           <td style=\"padding: 12px; border: 1px solid #dddddd;\">Thông tin so sánh hoặc đối chiếu thực tế</td>
         </tr>
         <tr style=\"background-color: #f3f3f3;\">
           <td style=\"padding: 12px; border: 1px solid #dddddd; font-weight: bold;\">Đặc tính 2</td>
           <td style=\"padding: 12px; border: 1px solid #dddddd; color: green; font-weight: bold;\">Ưu điểm hoặc cam kết chất lượng</td>
           <td style=\"padding: 12px; border: 1px solid #dddddd;\">Điểm khác biệt</td>
         </tr>
       </tbody>
     </table>
   - **Hộp đăng ký liên hệ cuối bài (HTML Box):** Bắt buộc chèn một khung màu nền nhạt ở cuối bài viết để giữ chân khách hàng:
     <div style=\"background-color: #eaf2eb; padding: 20px; border-left: 5px solid #0B5226; margin: 30px 0; border-radius: 4px;\">
       <h3 style=\"margin-top: 0; color: #0b5226;\">Nhận tư vấn tại " . (!empty($brand_name) ? $brand_name : 'đơn vị cung cấp') . "</h3>
       Khách hàng cần tư vấn, báo giá hoặc thông tin chi tiết về chủ đề này, vui lòng liên hệ:
       <ul>
         <li><strong>" . (!empty($brand_name) ? $brand_name : 'Đơn vị cung cấp') . "</strong></li>
         " . (!empty($brand_address) ? '<li><strong>Địa chỉ:</strong> ' . $brand_address . '</li>' : '') . "
         " . (!empty($brand_phone) ? '<li><strong>Hotline/Zalo:</strong> ' . $brand_phone . '</li>' : '') . "
       </ul>
     </div>
    - **Liên kết ngoài (Outbound Link):** Bắt buộc chèn ít nhất một liên kết tự nhiên trỏ ra ngoài trang web uy tín khác (ví dụ: liên kết tới Wikipedia tiếng Việt về chủ đề/loại sản phẩm tương ứng, hoặc các trang báo chí lớn, nguồn tài liệu khoa học chính thống) bằng một anchor text tự nhiên và có ý nghĩa (không được dùng anchor text chung chung như \"tại đây\" hay \"xem thêm\"). Liên kết này phải là link dofollow để tối ưu SEO.

7. ĐỊNH DẠNG ĐẦU RA JSON BẮT BUỘC:
   - Chỉ trả về duy nhất một chuỗi JSON chứa các khóa:
     * \"seo_title\": Tiêu đề SEO tự nhiên, thu hút người đọc, lồng ghép từ khóa chính \"{$keyword}\" một cách sáng tạo và trôi chảy (từ khóa có thể đứng ở đầu, giữa hoặc cuối tùy vào ngữ cảnh để tiêu đề tự nhiên nhất, tránh việc bài nào cũng lặp lại cấu trúc rập khuôn \"Từ khóa chính: ...\"). Nên có chứa số tự nhiên (như 3, 5, 7, hoặc năm hiện tại) và từ ngữ kích thích click để tăng tỷ lệ CTR, độ dài từ 50 đến 65 ký tự.
     * \"slug\": Đường dẫn tĩnh viết thường, không dấu, ngăn cách bằng dấu gạch ngang. Slug bắt buộc phản ánh góc nội dung/chủ đề phụ riêng của bài, không được chỉ lặp lại từ khóa chính dùng chung và không được thêm hậu tố số như -2, -3.
     * \"meta_description\": Đoạn mô tả tóm tắt ngắn gọn bài viết (khoảng 2-3 câu, tối đa 160 ký tự) làm thẻ meta description để hiển thị trên Google.
     * \"primary_keyword\": Từ khóa chính tối ưu riêng cho bài viết này (tự động kết hợp tinh tế giữa từ khóa chính chung \"{$keyword}\" và góc nội dung cụ thể \"{$article_topic}\", ví dụ nếu từ khóa chung là \"mua gạo Cần Thơ\" và chủ đề là \"chọn gạo ST25\", bạn có thể tạo từ khóa chính riêng là \"mua gạo ST25 Cần Thơ\"). Từ khóa này sẽ làm từ khóa tập trung (focus keyword) chính của bài viết để tránh bị trùng lặp với các bài viết khác.
     * \"secondary_keyword\": Danh sách 3-5 từ khóa phụ/biến thể ngữ nghĩa sát nhất đã dùng trong bài, ngăn cách bằng dấu phẩy.
     * \"content\": Nội dung chi tiết bài viết chứa các thẻ định dạng HTML đã nêu ở trên (độ dài tổng đạt 1200 - 1500 từ).
      * \"image_prompt\": Một prompt tiếng Anh mô tả ảnh đại diện bám đúng ngành, sản phẩm/dịch vụ, địa điểm và mục đích tìm kiếm của bài. Chọn một cảnh đời thực phù hợp như sử dụng sản phẩm, tư vấn, cửa hàng, văn phòng, sản xuất, dịch vụ, giao nhận, kiểm tra chất lượng hoặc trải nghiệm khách hàng; không mặc định ngành gạo/thực phẩm. Ảnh tham chiếu chỉ được xuất hiện khi phù hợp, ở vai trò phụ và giữ nguyên nhận diện. Dùng editorial documentary photography, neutral natural daylight, accurate white balance, true-to-life colors. Tránh ánh vàng/cam, CGI, quảng cáo sản phẩm phóng đại, chữ hoặc logo tự bịa.
   - Tuyệt đối KHÔNG được chứa thẻ <h1>, KHÔNG bao gồm các thẻ <html>, <body> hay <!DOCTYPE> bên trong khóa \"content\".
";

        $master_prompt = trim(get_option('aseo_master_prompt', ''));
        if (empty($master_prompt)) {
            $master_prompt = trim(get_option('aseo_master_text_prompt', ''));
        }
        if (!empty($master_prompt)) {
            $prompt .= "\n\nMASTER PROMPT DÙNG CHUNG CỦA CHỦ WEBSITE (áp dụng cho nội dung và định hướng hình ảnh nếu không mâu thuẫn với dữ liệu thật, SEO và định dạng JSON):\n" . $master_prompt . "\n";
        }

        $prompt .= "\n\nCRITICAL GENERALIZATION RULE: This plugin serves websites in any industry. Ignore and replace any rice, agriculture, food, Cần Thơ, moisture, grain, harvest, or wholesale examples in the template when they do not match the configured niche and actual product/service. Build headings, comparison criteria, use cases, CTA and image scene dynamically from the supplied niche and product data. Never invent industry-specific facts. If product image, price, address or phone is missing, omit that element instead of using sample data.\n";

        $prompt .= "\n\nFACTUAL CONTACT AND BACKLINK RULES — HIGHEST PRIORITY:\n"
            . "- Dữ liệu thương hiệu, địa chỉ, hotline và URL dưới đây là nguồn sự thật. Nếu trường nào có giá trị, phải sao chép đúng giá trị đó; không thay bằng cách nói chung chung.\n"
            . "- TUYỆT ĐỐI KHÔNG được viết các placeholder như \"Xem thông tin liên hệ trên website\", \"liên hệ trên website\", \"xem website để biết địa chỉ\", \"hotline cập nhật trên website\" hoặc câu tương đương. Nếu thiếu một trường, bỏ hẳn dòng đó.\n"
            . (!empty($source_website) ? "- Website chính thức cần được nhắc tới bằng một liên kết HTML tự nhiên: " . $source_website . "\n" : '')
            . (!empty($backlink_url) ? "- BACKLINK BẮT BUỘC: phải có ít nhất một thẻ <a href=\"" . esc_attr($backlink_url) . "\"> với anchor text mô tả thương hiệu/sản phẩm, đặt tự nhiên trong phần giới thiệu hoặc CTA. Không được bỏ qua URL này.\n" : '')
            . (!empty($user_brief) ? "- YÊU CẦU TRỰC TIẾP CỦA CHỦ WEBSITE (ưu tiên cao): " . $user_brief . "\n" : '')
            . "\n";

        if (!empty($article_topic) && $article_topic !== $keyword) {
            $prompt .= "\n\nARTICLE SUBTOPIC FOR THIS SPECIFIC ARTICLE: \"{$article_topic}\". Use this as the unique angle and supporting topic while keeping \"{$keyword}\" as the only primary keyword for the whole content cluster. Include several natural secondary/LSI phrases related to this subtopic, but do not replace the primary keyword.\n";
            $prompt .= "SLUG UNIQUENESS: Build the slug from this specific article subtopic and its unique search intent. Never return a slug based only on the shared primary keyword.\n";
        }
        $prompt .= "\nSECONDARY KEYWORDS: In the JSON field secondary_keyword, return only 3-5 comma-separated secondary/LSI phrases most relevant to this article's subtopic. Use them naturally in the content; the primary keyword remains only \"{$keyword}\".\n";
        if (!empty($global_secondary_keywords)) {
            $normalized_secondary_keywords = preg_replace('/[\r\n]+/', ', ', $global_secondary_keywords);
            $prompt .= "\nMANDATORY SHARED SECONDARY KEYWORDS FOR EVERY ARTICLE: {$normalized_secondary_keywords}. Include these phrases naturally where relevant, without keyword stuffing. They are supporting keywords only; never replace the primary keyword \"{$keyword}\".\n";
        }

        // Chống trùng lặp nội dung: truyền danh sách bài đã viết trước đó cho AI.
        $existing_titles = isset($brand_data['existing_article_titles']) && is_array($brand_data['existing_article_titles']) ? $brand_data['existing_article_titles'] : array();
        if (!empty($existing_titles)) {
            $titles_list = '';
            foreach (array_slice($existing_titles, 0, 20) as $idx => $et) {
                $titles_list .= ($idx + 1) . '. ' . $et . "\n";
            }
            $prompt .= "\nCONTENT UNIQUENESS — CRITICAL ANTI-DUPLICATION RULE:\nThe following articles have ALREADY been published on this website. Your new article MUST be substantially different from ALL of them:\n" . $titles_list . "\nSpecific requirements to ensure uniqueness:\n- Choose a DIFFERENT angle, narrative structure and set of H2/H3 headings from every article listed above.\n- Do NOT reuse the same opening paragraph pattern, the same list of tips/criteria, or the same comparison table structure.\n- Vary your storytelling approach: if previous articles used listicles, use a case-study or how-to guide format instead; if they used expert advice format, use a buyer's journey or problem-solution format.\n- The seo_title MUST be clearly distinct from all titles above — different wording, different hook, different number if using numbers.\n- Cover aspects of the topic that the existing articles have NOT covered yet.\n";
        }

        $prompt .= "\nFINAL SEO SAFETY RULE: Keep the exact primary keyword \"{$keyword}\" to a maximum of 8-10 natural occurrences in the full HTML content. Use semantic variants afterward, avoid repeating it in every paragraph, table cell, CTA and product box, and never force a sentence just to increase keyword density.\n";

        $body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array('text' => $prompt)
                    )
                )
            ),
            'generationConfig' => array(
                'temperature'      => 0.45,
                'responseMimeType' => 'application/json',
                'responseSchema'   => array(
                    'type'       => 'OBJECT',
                    'properties' => array(
                        'seo_title'         => array('type' => 'STRING'),
                        'slug'              => array('type' => 'STRING'),
                        'meta_description'  => array('type' => 'STRING'),
                        'primary_keyword'   => array('type' => 'STRING'),
                        'secondary_keyword' => array('type' => 'STRING'),
                        'content'           => array('type' => 'STRING'),
                        'image_prompt'      => array('type' => 'STRING')
                    ),
                    'required' => array('seo_title', 'slug', 'meta_description', 'primary_keyword', 'secondary_keyword', 'content', 'image_prompt')
                )
            )
        );

        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($body),
            'timeout' => 120 // Bài viết dài cần thời gian timeout lớn
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $res_body = wp_remote_retrieve_body($response);
        if (self::is_batch_stopped($batch_started_at)) {
            return array('success' => false, 'message' => 'Tiến trình đã được người dùng dừng.');
        }

        if ($code !== 200) {
            $data = json_decode($res_body, true);
            $err_msg = isset($data['error']['message']) ? $data['error']['message'] : 'Lỗi HTTP ' . $code;
            return array('success' => false, 'message' => $err_msg);
        }

        $data = json_decode($res_body, true);
        $json_text = isset($data['candidates'][0]['content']['parts'][0]['text']) ? trim($data['candidates'][0]['content']['parts'][0]['text']) : '';

        if (empty($json_text)) {
            return array('success' => false, 'message' => 'API trả về phản hồi rỗng.');
        }

        // Làm sạch chuỗi và trích xuất phần JSON nằm giữa cặp ngoặc nhọn { và } đầu-cuối
        $first_brace = strpos($json_text, '{');
        $last_brace = strrpos($json_text, '}');
        if ($first_brace !== false && $last_brace !== false && $last_brace > $first_brace) {
            $json_text = substr($json_text, $first_brace, $last_brace - $first_brace + 1);
        }
        $json_text = trim($json_text);

        $article = json_decode($json_text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            if (self::is_batch_stopped($batch_started_at)) {
                return array('success' => false, 'message' => 'Tiến trình đã được người dùng dừng.');
            }
            error_log('Agent SEO Raw Gemini Response: ' . $json_text);
            // Retry once with a stricter instruction and lower temperature. This is useful
            // when long HTML attributes contain quotes that the first response did not escape.
            $retry_body = $body;
            $retry_body['generationConfig']['temperature'] = 0.2;
            $retry_body['contents'][0]['parts'][0]['text'] = $prompt . "\n\nCRITICAL RETRY: Return one valid JSON object only. Every double quote inside the HTML content string must be JSON-escaped. Do not use Markdown fences or add commentary.";

            $retry_response = wp_remote_post($url, array(
                'headers' => array('Content-Type' => 'application/json'),
                'body'    => wp_json_encode($retry_body),
                'timeout' => 120
            ));

            if (!is_wp_error($retry_response) && wp_remote_retrieve_response_code($retry_response) === 200) {
                $retry_data = json_decode(wp_remote_retrieve_body($retry_response), true);
                $retry_text = isset($retry_data['candidates'][0]['content']['parts'][0]['text']) ? trim($retry_data['candidates'][0]['content']['parts'][0]['text']) : '';
                $retry_first = strpos($retry_text, '{');
                $retry_last = strrpos($retry_text, '}');
                if ($retry_first !== false && $retry_last !== false && $retry_last > $retry_first) {
                    $retry_text = substr($retry_text, $retry_first, $retry_last - $retry_first + 1);
                }
                $article = json_decode(trim($retry_text), true);
            }

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($article)) {
                return array(
                    'success' => false,
                    'message' => 'Không thể phân giải JSON trả về từ AI sau 2 lần thử: ' . json_last_error_msg() . '.'
                );
            }
        }

        if (empty($article['seo_title']) || empty($article['content'])) {
            return array('success' => false, 'message' => 'Dữ liệu bài viết trả về bị thiếu trường seo_title hoặc content.');
        }

        // Một số phản hồi vẫn ngắn hơn yêu cầu dù prompt đã nêu rõ độ dài.
        // Chỉ gọi thêm một lần khi bài thực sự thiếu dung lượng, tránh làm chậm các bài đạt chuẩn.
        $word_count = 0;
        preg_match_all('/\S+/u', wp_strip_all_tags($article['content']), $word_matches);
        $word_count = isset($word_matches[0]) ? count($word_matches[0]) : 0;
        if ($word_count < 1050) {
            if (self::is_batch_stopped($batch_started_at)) {
                return array('success' => false, 'message' => 'Tiến trình đã được người dùng dừng.');
            }
            $expand_prompt = "Mở rộng HTML bài viết dưới đây thành khoảng 1200-1500 từ tiếng Việt. Giữ nguyên chủ đề, dữ liệu thực tế, từ khóa chính và các thông tin sản phẩm; không bịa thêm giá, chứng nhận hay địa chỉ. Bổ sung phân tích, ví dụ, quy trình, tiêu chí lựa chọn và FAQ liên quan. Giữ cấu trúc H2/H3, bảng, CTA hiện có; không thêm h1, markdown hay lời giải thích. Chỉ trả về HTML nội dung hoàn chỉnh.\n\nHTML hiện tại:\n" . $article['content'];
            $expand_body = array(
                'contents' => array(array('parts' => array(array('text' => $expand_prompt)))),
                'generationConfig' => array('temperature' => 0.35, 'responseMimeType' => 'text/plain')
            );
            $expand_response = wp_remote_post($url, array(
                'headers' => array('Content-Type' => 'application/json'),
                'body'    => wp_json_encode($expand_body),
                'timeout' => 120
            ));
            if (!is_wp_error($expand_response) && wp_remote_retrieve_response_code($expand_response) === 200) {
                $expand_data = json_decode(wp_remote_retrieve_body($expand_response), true);
                $expanded_content = isset($expand_data['candidates'][0]['content']['parts'][0]['text'])
                    ? trim($expand_data['candidates'][0]['content']['parts'][0]['text']) : '';
                $expanded_content = preg_replace('/^```(?:html)?\s*|\s*```$/i', '', $expanded_content);
                preg_match_all('/\S+/u', wp_strip_all_tags($expanded_content), $expanded_matches);
                if (!empty($expanded_content) && isset($expanded_matches[0]) && count($expanded_matches[0]) > $word_count) {
                    $article['content'] = $expanded_content;
                }
            }
        }

        // Lớp bảo vệ cuối cùng: AI đôi khi vẫn lặp lại placeholder hoặc bỏ qua
        // backlink dù prompt đã yêu cầu. Chuẩn hóa HTML trước khi trả về worker.
        $article['content'] = self::enforce_contact_and_backlink($article['content'], $brand_data, $backlink_url);

        return array(
            'success'          => true,
            'seo_title'        => sanitize_text_field($article['seo_title']),
            'slug'             => sanitize_title($article['slug']),
            'meta_description' => sanitize_text_field($article['meta_description']),
            'primary_keyword'  => sanitize_text_field($article['primary_keyword']),
            'secondary_keyword'=> sanitize_text_field($article['secondary_keyword']),
            'content'          => $article['content'], // Giữ nguyên HTML
            'image_prompt'     => sanitize_text_field($article['image_prompt'])
        );
    }

    private static function enforce_contact_and_backlink($content, $brand_data, $backlink_url = '') {
        $content = (string) $content;
        $address = isset($brand_data['brand_address']) ? trim($brand_data['brand_address']) : '';
        $phone = isset($brand_data['brand_phone']) ? trim($brand_data['brand_phone']) : '';
        $brand_name = isset($brand_data['brand_name']) ? trim($brand_data['brand_name']) : 'website chính thức';
        $placeholder = '(?:Xem thông tin liên hệ trên website|xem thông tin liên hệ trên website|liên hệ trên website|xem website để biết địa chỉ|hotline cập nhật trên website)';

        if ($address !== '' && !preg_match('/' . $placeholder . '/iu', $address)) {
            $content = preg_replace('/(<strong>\s*Địa chỉ\s*:\s*<\/strong>\s*)' . $placeholder . '/iu', '$1' . esc_html($address), $content);
        } else {
            $content = preg_replace('/\s*<li[^>]*>\s*<strong>\s*Địa chỉ\s*:\s*<\/strong>\s*' . $placeholder . '\s*<\/li>\s*/iu', '', $content);
        }

        if ($phone !== '' && !preg_match('/' . $placeholder . '/iu', $phone)) {
            $content = preg_replace('/(<strong>\s*Hotline(?:\/Zalo)?\s*:\s*<\/strong>\s*)' . $placeholder . '/iu', '$1' . esc_html($phone), $content);
        } else {
            $content = preg_replace('/\s*<li[^>]*>\s*<strong>\s*Hotline(?:\/Zalo)?\s*:\s*<\/strong>\s*' . $placeholder . '\s*<\/li>\s*/iu', '', $content);
        }

        // Không để placeholder lọt ra ngoài list HTML do AI tự đổi layout.
        $content = preg_replace('/\b' . $placeholder . '\b/iu', '', $content);

        $backlink_url = esc_url_raw($backlink_url);
        if ($backlink_url !== '' && !preg_match('/<a\b[^>]*href=["\']' . preg_quote($backlink_url, '/') . '["\']/iu', $content)) {
            $content .= '<p>Tham khảo thêm thông tin từ <a href="' . esc_url($backlink_url) . '">' . esc_html($brand_name) . '</a>.</p>';
        }
        return $content;
    }

    private static function normalize_contact_value($value) {
        $value = trim((string) $value);
        if ($value === '' || preg_match('/(?:xem thông tin liên hệ trên website|liên hệ trên website|xem website để biết địa chỉ|hotline cập nhật trên website)/iu', $value)) {
            return '';
        }
        return $value;
    }

    private static function is_batch_stopped($batch_started_at) {
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

    /**
     * Tự động suy nghĩ/bốc tách từ khóa mới chuẩn SEO dựa trên thông tin sản phẩm và lĩnh vực
     */
    public static function generate_keyword_from_product($api_key, $product_info, $existing_keywords = array()) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=' . $api_key;
        
        $prompt = "Bạn là chuyên gia nghiên cứu từ khóa SEO, có khả năng phân tích nhiều ngành nghề và hành vi tìm kiếm của khách hàng dựa trên dữ liệu sản phẩm/dịch vụ thực tế được cung cấp.
Dựa trên thông tin sản phẩm mục tiêu sau đây:
" . $product_info . "

Và danh sách các từ khóa đã chạy viết bài từ trước đến nay (Tránh tuyệt đối trùng lặp chủ đề):
" . implode("\n", $existing_keywords) . "

Hãy suy nghĩ và đề xuất duy nhất một từ khóa tìm kiếm Google (search query) có lượng truy cập tốt liên quan đến sản phẩm trên.
Yêu cầu bắt buộc:
- Từ khóa nên có độ dài từ 3 đến 8 từ, thể hiện một nhu cầu tìm kiếm cụ thể phù hợp với ngành và khách hàng mục tiêu.
- Từ khóa phải liên quan trực tiếp đến nhu cầu thực tế của khách hàng (tìm giá, tìm địa chỉ, tìm cách phân biệt, kinh nghiệm mua hàng).
- Tuyệt đối nghiêm cấm viết bậy bạ, tục tĩu hoặc lạc đề.
- Chỉ trả về duy nhất từ khóa đó dưới dạng văn bản thô, KHÔNG thêm dấu câu, KHÔNG giải thích, KHÔNG có tiền tố, KHÔNG viết hoa toàn bộ.";

        $body = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array('text' => $prompt)
                    )
                )
            )
        );

        $response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($body),
            'timeout' => 15
        ));

        if (is_wp_error($response)) {
            return '';
        }

        $res_body = wp_remote_retrieve_body($response);
        $data = json_decode($res_body, true);
        $text = isset($data['candidates'][0]['content']['parts'][0]['text']) ? trim($data['candidates'][0]['content']['parts'][0]['text']) : '';
        
        // Làm sạch từ khóa trả về
        $text = str_replace(array('"', "'", '.', ',', ':', '-', '_', '?', '!'), '', $text);
        return trim($text);
    }

    public static function generate_master_prompt_suggestions($api_key, $niche, $brand_name = '', $product_info = '', $user_brief = '') {
        if (empty($api_key)) {
            return array('success' => false, 'message' => 'Thiếu Gemini API Key.');
        }
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=' . $api_key;
        $brief = $user_brief !== '' ? $user_brief : 'Nội dung chuyên nghiệp, hữu ích, tự nhiên và hình ảnh chân thực.';
        $prompt = "Bạn là chuyên gia Prompt Engineer, Content Strategist và Visual Director. Hãy biến yêu cầu của người dùng thành một master prompt có thể dùng ngay.\n\n"
            . "=== YÊU CẦU BẮT BUỘC CỦA NGƯỜI DÙNG (ƯU TIÊN CAO NHẤT) ===\n"
            . $brief . "\n"
            . "=== SẢN PHẨM ĐANG ĐƯỢC CHỌN ===\n" . $product_info . "\n"
            . "=== NGỮ CẢNH WEBSITE (CHỈ DÙNG ĐỂ BỔ SUNG) ===\nNgành/lĩnh vực: " . $niche . "\nThương hiệu: " . $brand_name . "\n\n"
            . "QUY TẮC ƯU TIÊN: Câu yêu cầu của người dùng và tên sản phẩm đang chọn là nguồn sự thật cao nhất. Phải giữ đúng ý định, đối tượng và loại sản phẩm được nêu; không đổi sang sản phẩm gần giống, không tự suy diễn thành gạo, cám, nông sản hoặc ngành khác. Nếu dữ liệu website mâu thuẫn với yêu cầu người dùng, giữ yêu cầu người dùng và chỉ dùng dữ liệu không mâu thuẫn. Số lượng bài như '1 bài' là thao tác của lượt chạy, không biến thành quy tắc nội dung.\n\n"
            . "Hãy tạo một master prompt bằng tiếng Việt dùng chung cho AI viết bài SEO và AI tạo ảnh editorial. Tuyệt đối không để placeholder trong ngoặc vuông. Xuất đúng 6 mục, mỗi mục bắt đầu ở một dòng riêng và có 2-5 gạch đầu dòng bắt đầu bằng '-': 1. VAI TRÒ, 2. DỮ LIỆU VÀ TÍNH CHÍNH XÁC, 3. NỘI DUNG SEO, 4. GIỌNG VĂN, 5. HÌNH ẢNH, 6. ĐIỀU CẤM. Không viết mục thành đoạn văn dài.\n"
            . "Không tự thêm giá, địa chỉ, chứng nhận, cam kết hoặc thuộc tính sản phẩm chưa được cung cấp. Ảnh phải đúng sản phẩm/chủ đề, đời thực, ánh sáng tự nhiên hơi lạnh 6000K-6500K, không ánh sáng studio, CGI, ám vàng hay vàng ấm. Chỉ trả JSON với một khóa master_prompt.";
        $body = array('contents' => array(array('parts' => array(array('text' => $prompt)))), 'generationConfig' => array('temperature' => 0.5, 'responseMimeType' => 'application/json'));
        $response = wp_remote_post($url, array('headers' => array('Content-Type' => 'application/json'), 'body' => wp_json_encode($body), 'timeout' => 30));
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $text = isset($data['candidates'][0]['content']['parts'][0]['text']) ? trim($data['candidates'][0]['content']['parts'][0]['text']) : '';
        $suggestions = json_decode($text, true);
        if (!is_array($suggestions) || empty($suggestions['master_prompt'])) {
            return array('success' => false, 'message' => 'AI không trả về đúng định dạng gợi ý prompt.');
        }
        return array('success' => true, 'master_prompt' => sanitize_textarea_field($suggestions['master_prompt']));
    }

    public static function extract_website_profile($api_key, $website_url) {
        if (empty($api_key) || empty($website_url)) {
            return array('success' => false, 'message' => 'Thiếu Gemini API Key hoặc địa chỉ website.');
        }
        $response = wp_safe_remote_get($website_url, array(
            'timeout' => 30,
            'redirection' => 5,
            'user-agent' => 'Mozilla/5.0 (compatible; AgentSEO/1.0; +' . home_url('/') . ')'
        ));
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return array('success' => false, 'message' => 'Website phản hồi HTTP ' . $code . '.');
        }
        $html = wp_remote_retrieve_body($response);
        $html = preg_replace('#<(script|style|noscript|svg)[^>]*>.*?</\1>#is', ' ', $html);
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', trim($text));
        $text = mb_substr($text, 0, 24000);
        if (mb_strlen($text) < 100) {
            return array('success' => false, 'message' => 'Không đọc được đủ nội dung công khai từ website.');
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key=' . $api_key;
        $prompt = "Trích xuất hồ sơ doanh nghiệp từ nội dung website bên dưới. Chỉ dùng dữ liệu xuất hiện rõ ràng; không suy đoán, không dùng Lorem Ipsum, testimonial mẫu hoặc dữ liệu nhà thiết kế website.\n"
            . "Trả đúng một JSON với các khóa: brand_name, brand_address, brand_phone, brand_contact, brand_price, brand_cta, niche, brand_voice, product_summary.\n"
            . "- brand_contact chỉ là người phụ trách thật sự nếu được ghi rõ; nếu không thì để trống.\n"
            . "- brand_price chỉ lấy giá công khai; nếu không có giá thì để trống.\n"
            . "- product_summary tóm tắt sản phẩm/dịch vụ thật trong tối đa 500 ký tự.\n"
            . "- brand_cta là lời kêu gọi hành động ngắn dựa trên thông tin liên hệ có thật.\n\n"
            . "URL: " . $website_url . "\nNỘI DUNG WEBSITE:\n" . $text;
        $body = array(
            'contents' => array(array('parts' => array(array('text' => $prompt)))),
            'generationConfig' => array('temperature' => 0.1, 'responseMimeType' => 'application/json')
        );
        $ai_response = wp_remote_post($url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode($body),
            'timeout' => 45
        ));
        if (is_wp_error($ai_response)) {
            return array('success' => false, 'message' => $ai_response->get_error_message());
        }
        $data = json_decode(wp_remote_retrieve_body($ai_response), true);
        $raw = isset($data['candidates'][0]['content']['parts'][0]['text']) ? trim($data['candidates'][0]['content']['parts'][0]['text']) : '';
        $profile = json_decode($raw, true);
        if (!is_array($profile) || empty($profile['brand_name'])) {
            return array('success' => false, 'message' => 'AI không nhận diện được hồ sơ doanh nghiệp hợp lệ.');
        }
        $clean = array();
        foreach (array('brand_name', 'brand_address', 'brand_phone', 'brand_contact', 'brand_price', 'brand_cta', 'niche', 'brand_voice', 'product_summary') as $field) {
            $clean[$field] = isset($profile[$field]) ? sanitize_textarea_field($profile[$field]) : '';
        }
        $clean['success'] = true;
        return $clean;
    }
}
