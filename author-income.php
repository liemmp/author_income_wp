<?php
/**
 * Plugin Name: Author Income
 * Description: Thống kê lượt xem bài viết và hoa hồng theo tác giả, tính thu nhập và gửi email báo cáo hàng tháng.
 * Version: 2.37
 * Author: ChatGPT, Deepseek và Rhino.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Author_Post_Views {

    public function __construct() {
        add_action('wp', array($this, 'track_post_views'));
        add_action('admin_menu', array($this, 'add_menus'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('author_views_monthly_email_event', array($this, 'send_monthly_email_reports'));
        register_activation_hook(__FILE__, array($this, 'schedule_monthly_emails'));
        register_deactivation_hook(__FILE__, array($this, 'clear_monthly_email_schedule'));
    }

    // ======= FUNCTIONALITIES CŨ =======

    public function track_post_views() {
        if (!is_single() || get_post_type() !== 'post') return;
        $post_id = get_the_ID();
        $user_ip = $_SERVER['REMOTE_ADDR'];
        $current_month = date('Y-m');
        $views_key = "_post_views_{$current_month}";
        $views = get_post_meta($post_id, $views_key, true);
        $views = is_numeric($views) ? $views : 0;
        if (!get_transient("viewed_{$post_id}_{$user_ip}")) {
            update_post_meta($post_id, $views_key, $views + 1);
            set_transient("viewed_{$post_id}_{$user_ip}", true, MONTH_IN_SECONDS);
        }
    }

    public function add_menus() {
        // --- MENU CHO TÁC GIẢ ---
        add_menu_page(
            'Thu nhập',                // Page title
            'Thu nhập',                // Menu title
            'edit_posts',              // Capability
            'author_earnings_parent',  // Menu slug (container)
            array($this, 'render_author_yearly_income_summary'),
            'dashicons-chart-line',    // Icon
            25
        );
        add_submenu_page(
            'author_earnings_parent',
            'Lượt xem',
            'Lượt xem',
            'edit_posts',
            'author_earnings',
            array($this, 'render_author_earnings')
        );
        add_submenu_page(
            'author_earnings_parent',
            'Hoa hồng',
            'Hoa hồng',
            'edit_posts',
            'author_commission',
            array($this, 'render_author_commission')
        );
        
        // --- MENU CHO ADMIN ---
        if (current_user_can('manage_options')) {
            // Menu gốc "Đối tác"
            add_menu_page(
                'Đối tác',
                'Đối tác',
                'manage_options',
                'partner_dashboard',
                array($this, 'render_partner_dashboard'),
                'dashicons-analytics',
                26
            );
            add_submenu_page(
                'partner_dashboard',
                'Lượt xem',
                'Lượt xem',
                'manage_options',
                'author_views',
                array($this, 'render_admin_views')
            );
            add_submenu_page(
                'partner_dashboard',
                'Hoa hồng',
                'Hoa hồng',
                'manage_options',
                'partner_commission',
                array($this, 'render_commission_page')
            );
            add_submenu_page(
                'partner_dashboard',
                'Cài đặt',
                'Cài đặt',
                'manage_options',
                'author_views_settings',
                array($this, 'render_admin_settings')
            );
        }
    }

    // ======= REGISTER SETTINGS (ADMIN Cài đặt) =======

    public function register_settings() {
        // Đăng ký cài đặt "Giá mỗi lượt xem" (VND)
        register_setting('author_views_settings', 'author_views_price_per_view');
        // Đăng ký cài đặt "Tỉ lệ hoa hồng (%)"
        register_setting('author_views_settings', 'partner_commission_rate');
        // Đăng ký cài đặt Ngưỡng thanh toán (VND)
        register_setting('author_views_settings', 'author_payment_threshold');

        add_settings_section(
            'main_section',
            'Thiết lập giá trị',
            null,
            'author_views_settings'
        );
        add_settings_field(
            'price_per_view',
            'Giá mỗi lượt xem (VND)',
            array($this, 'price_per_view_callback'),
            'author_views_settings',
            'main_section'
        );
        add_settings_field(
            'partner_commission_rate_field',
            'Tỉ lệ hoa hồng (%)',
            array($this, 'commission_rate_callback'),
            'author_views_settings',
            'main_section'
        );
        add_settings_field(
            'payment_threshold_field',
            'Ngưỡng thanh toán (VND)',
            array($this, 'payment_threshold_callback'),
            'author_views_settings',
            'main_section'
        );
    }

    public function price_per_view_callback() {
        $value = get_option('author_views_price_per_view', 100);
        echo "<input type='number' name='author_views_price_per_view' value='" . esc_attr($value) . "' /> đ";
    }

    public function commission_rate_callback() {
        $commission = get_option('partner_commission_rate', 10);
        echo "<input type='number' step='0.1' min='0' name='partner_commission_rate' value='" . esc_attr($commission) . "' /> %";
    }

    public function payment_threshold_callback() {
        $threshold = get_option('author_payment_threshold', 1000000);
        echo "<input type='number' step='1' min='0' name='author_payment_threshold' value='" . esc_attr($threshold) . "' /> đ";
    }

    public function render_admin_settings() {
        echo '<div class="wrap"><h2>Cài đặt</h2><form method="post" action="options.php">';
        settings_fields('author_views_settings');
        do_settings_sections('author_views_settings');
        submit_button();
        echo '</form></div>';
    }

    // ======= HIỆN THỊ CHỨC NĂNG CHO TÁC GIẢ (MENU "Thu nhập") =======

    // Báo cáo lượt xem (chức năng cũ)
    public function render_author_earnings() {
        $author_id = get_current_user_id();
        $price_per_view = get_option('author_views_price_per_view', 100);
        $selected_month = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : date('Y-m');
        echo '<div class="wrap"><h2>Lượt xem - Tháng ' . esc_html(date('m-Y', strtotime($selected_month . '-01'))) . '</h2>';
        echo '<form method="get"><input type="hidden" name="page" value="author_earnings">';
        echo '<select name="month">';
        for ($m = 1; $m <= 12; $m++) {
            $month = sprintf('%02d', $m);
            $ym = date('Y') . '-' . $month;
            $sel = ($ym === $selected_month) ? 'selected' : '';
            $disp = $month . '-' . date('Y');
            echo "<option value='{$ym}' {$sel}>{$disp}</option>";
        }
        echo '</select> <input type="submit" value="Xem"></form><br>';
        echo "<h3>Chi tiết lượt xem tháng " . date('m-Y', strtotime($selected_month . '-01')) . "</h3>";
        echo '<table class="widefat fixed"><thead><tr><th>Bài viết</th><th>Lượt xem</th><th>Thu nhập</th></tr></thead><tbody>';
        $total_views = 0;
        $args = array('post_type' => 'post', 'author' => $author_id, 'posts_per_page' => -1);
        $posts = get_posts($args);
        foreach ($posts as $post) {
            $views = get_post_meta($post->ID, "_post_views_{$selected_month}", true);
            $views = is_numeric($views) ? $views : 0;
            if ($views <= 0) continue;
            $income = $views * $price_per_view;
            $total_views += $views;
            echo "<tr><td>{$post->post_title}</td><td>{$views}</td><td>" . number_format($income) . " đ</td></tr>";
        }
        $total_income = $total_views * $price_per_view;
        echo "<tr><th>Tổng</th><th>{$total_views}</th><th>" . number_format($total_income) . " đ</th></tr>";
        echo '</tbody></table></div>';
    }

    // Báo cáo hoa hồng của tác giả (chức năng cũ)
    public function render_author_commission() {
        $author_id = get_current_user_id();
        $commission_rate_pct = get_option('partner_commission_rate', 10);
        $commission_rate = $commission_rate_pct / 100;
        $selected_month = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : date('Y-m');

        // Lấy tất cả các đơn hàng có trạng thái "completed" (có thể gây tải nếu có nhiều đơn hàng)
        $all_orders = wc_get_orders(array(
            'limit'  => -1,
            'status' => 'completed'
        ));

        // Lọc các đơn hàng theo ngày hoàn thành dựa trên $selected_month
        $orders = array();
        if (!empty($all_orders)) {
            foreach ($all_orders as $order) {
                $date_completed = $order->get_date_completed();
                // Kiểm tra nếu có ngày hoàn thành và định dạng của nó bằng 'Y-m'
                if ($date_completed && $date_completed->date('Y-m') === $selected_month) {
                    $orders[] = $order;
                }
            }
        }

        // Tính tổng doanh thu từ các order item thuộc về tác giả của bạn
        $revenue_by_product = array();
        if (!empty($orders)) {
            foreach ($orders as $order) {
                foreach ($order->get_items() as $item) {
                    // Lấy meta "post_id" từ order item
                    $post_id = $item->get_meta('post_id');
                    if (!$post_id) {
                        $data = $item->get_data();
                        $post_id = isset($data['post_id']) ? $data['post_id'] : 0;
                    }
                    if (!$post_id) continue;
                    // Kiểm tra tác giả của bài viết
                    $post_author = get_post_field('post_author', $post_id);
                    if ((int)$post_author !== (int)$author_id) continue;
                    $product_id = $item->get_product_id();
                    $product_name = $item->get_name();
                    $item_total = (float)$item->get_total();
                    if (!isset($revenue_by_product[$product_id])) {
                        $revenue_by_product[$product_id] = array('name' => $product_name, 'total' => 0);
                    }
                    $revenue_by_product[$product_id]['total'] += $item_total;
                }
            }
        }

        echo '<div class="wrap">';
        echo '<h2>Hoa hồng - Tháng ' . esc_html(date('m-Y', strtotime($selected_month . '-01'))) . '</h2>';
        
        // Form chọn tháng
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="author_commission">';
        echo '<select name="month">';
        $current_year = date('Y');
        for ($m = 1; $m <= 12; $m++) {
            $month_val = sprintf('%02d', $m);
            $option_value = $current_year . '-' . $month_val;
            $option_label = $month_val . '-' . $current_year;
            $sel = ($option_value === $selected_month) ? 'selected' : '';
            echo '<option value="' . esc_attr($option_value) . '" ' . $sel . '>' . esc_html($option_label) . '</option>';
        }
        echo '</select> ';
        echo '<input type="submit" class="button button-primary" value="Xem">';
        echo '</form><br>';

        // Hiển thị bảng hoa hồng với 3 cột: Tác giả, Doanh thu, Hoa hồng
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>Tác giả</th>';
        echo '<th>Doanh thu</th>';
        echo '<th>Hoa hồng</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        $total_commission = 0;
        if (!empty($revenue_by_product)) {
            foreach ($revenue_by_product as $author_id_key => $info) {
                $product_name = $info['name'];
                $revenue = $info['total'];
                $commission = $revenue * $commission_rate;
                $total_commission += $commission;
                echo '<tr>';
                echo '<td>' . esc_html($product_name) . '</td>';
                echo '<td>' . wc_price($revenue) . '</td>';
                echo '<td>' . wc_price($commission) . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="3">Không có dữ liệu trong tháng này.</td></tr>';
        }

        echo '</tbody>';
        echo '<tfoot>';
        echo '<tr>';
        echo '<th colspan="2" style="text-align:right;">Tổng hoa hồng:</th>';
        echo '<th>' . wc_price($total_commission) . '</th>';
        echo '</tr>';
        echo '</tfoot>';
        echo '</table>';
        echo '</div>';
    }


    public function render_commission_page() {
        // Lấy tỷ lệ hoa hồng từ options (ví dụ: 10% mặc định)
        $commission_rate = get_option('partner_commission_rate', 10) / 100;

        // Lấy tháng được chọn theo định dạng 'Y-m'; nếu không có thì sử dụng tháng hiện tại
        $selected_month = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : date('Y-m');

        // Lấy tất cả các đơn hàng có trạng thái "completed"
        $all_orders = wc_get_orders(array(
            'limit'  => -1,
            'status' => 'completed'
        ));

        // Lọc đơn hàng dựa trên ngày hoàn thành (order completed date)
        $orders = array();
        if (!empty($all_orders)) {
            foreach ($all_orders as $order) {
                $date_completed = $order->get_date_completed();
                if ($date_completed && $date_completed->date('Y-m') === $selected_month) {
                    $orders[] = $order;
                }
            }
        }

        // Mảng lưu tổng doanh thu cho mỗi tác giả từ các đơn hàng đã lọc
        $author_revenues = array();

        if (!empty($orders)) {
            foreach ($orders as $order) {
                foreach ($order->get_items() as $item) {
                    // Lấy meta 'post_id' của order item (nếu không có, lấy từ data)
                    $post_id = $item->get_meta('post_id');
                    if (!$post_id) {
                        $data = $item->get_data();
                        $post_id = isset($data['post_id']) ? $data['post_id'] : 0;
                    }
                    if (!$post_id) continue;
                    // Xác định tác giả của bài viết từ post_id
                    $author_id = get_post_field('post_author', $post_id);
                    // Lấy doanh thu của dòng order item
                    $item_total = (float)$item->get_total();
                    if (isset($author_revenues[$author_id])) {
                        $author_revenues[$author_id] += $item_total;
                    } else {
                        $author_revenues[$author_id] = $item_total;
                    }
                }
            }
        }

        // Hiển thị giao diện cho trang "Hoa hồng"
        echo '<div class="wrap"><h1>Hoa hồng theo tác giả - Tháng ' . esc_html(date('m-Y', strtotime($selected_month . '-01'))) . '</h1>';

        // Form chọn tháng
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="partner_commission">';
        echo '<select name="month">';
        $current_year = date('Y');
        for ($m = 1; $m <= 12; $m++) {
            $month_val    = sprintf('%02d', $m);
            $option_value = $current_year . '-' . $month_val;
            $option_label = $month_val . '-' . $current_year;
            $sel = ($option_value === $selected_month) ? 'selected' : '';
            echo '<option value="' . esc_attr($option_value) . '" ' . $sel . '>' . esc_html($option_label) . '</option>';
        }
        echo '</select> ';
        echo '<input type="submit" class="button button-primary" value="Xem">';
        echo '</form><br>';

        // Bảng hiển thị: 3 cột: Tác giả, Doanh thu, Thu nhập (hoa hồng)
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>Tác giả</th>';
        echo '<th>Doanh thu</th>';
        echo '<th>Thu nhập</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        $total_commission = 0;
        if (!empty($author_revenues)) {
            foreach ($author_revenues as $author_id => $revenue) {
                $commission = $revenue * $commission_rate;
                $total_commission += $commission;
                $author_name = get_the_author_meta('display_name', $author_id);
                echo '<tr>';
                echo '<td>' . esc_html($author_name) . '</td>';
                echo '<td>' . wc_price($revenue) . '</td>';
                echo '<td>' . wc_price($commission) . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="3">Không có dữ liệu trong tháng này.</td></tr>';
        }

        echo '</tbody>';
        echo '<tfoot>';
        echo '<tr>';
        echo '<th colspan="2" style="text-align:right;">Tổng hoa hồng:</th>';
        echo '<th>' . wc_price($total_commission) . '</th>';
        echo '</tr>';
        echo '</tfoot>';
        echo '</table>';
        echo '</div>';
    }

    public function render_admin_views() {
        $price_per_view = get_option('author_views_price_per_view', 100);
        $selected_month = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : date('Y-m');
        $authors = get_users(array('capability' => ['edit_posts'], 'has_published_posts' => ['post']));
        echo '<div class="wrap"><h2>Lượt xem theo tác giả</h2>';
        echo '<form method="get"><input type="hidden" name="page" value="author_views">';
        echo '<select name="month">';
        for ($m = 1; $m <= 12; $m++) {
            $month = sprintf('%02d', $m);
            $ym = date('Y') . '-' . $month;
            $sel = ($ym === $selected_month) ? 'selected' : '';
            $disp = $month . '-' . date('Y');
            echo "<option value='{$ym}' {$sel}>{$disp}</option>";
        }
        echo '</select> <input type="submit" value="Xem"></form><br>';
        echo "<table class='widefat fixed'><thead><tr><th>Tác giả</th><th>Lượt xem</th><th>Thu nhập</th></tr></thead><tbody>";
        foreach ($authors as $author) {
            $views = $this->get_author_monthly_views($author->ID, $selected_month);
            $income = $views * $price_per_view;
            echo "<tr><td>{$author->display_name}</td><td>{$views}</td><td>" . number_format($income) . " đ</td></tr>";
        }
        echo '</tbody></table></div>';
    }

    public function get_author_monthly_views($author_id, $year_month) {
        $args = array(
            'post_type' => 'post',
            'author' => $author_id,
            'posts_per_page' => -1,
            'fields' => 'ids'
        );
        $posts = get_posts($args);
        $total = 0;
        foreach ($posts as $post_id) {
            $views = get_post_meta($post_id, "_post_views_{$year_month}", true);
            $total += is_numeric($views) ? $views : 0;
        }
        return $total;
    }

    public function get_author_monthly_commission($author_id, $selected_month) {
        $commission_rate = get_option('partner_commission_rate', 10) / 100;
        // Lấy tất cả các đơn hàng có trạng thái "completed"
        $all_orders = wc_get_orders(array(
            'limit'  => -1,
            'status' => 'completed'
        ));
        $total = 0;
        if (!empty($all_orders)) {
            foreach ($all_orders as $order) {
                $date_completed = $order->get_date_completed();
                // Kiểm tra nếu có ngày hoàn thành và nếu nó thuộc tháng được chọn
                if ($date_completed && $date_completed->date('Y-m') === $selected_month) {
                    foreach ($order->get_items() as $item) {
                        $post_id = $item->get_meta('post_id');
                        if (!$post_id) {
                            $data = $item->get_data();
                            $post_id = isset($data['post_id']) ? $data['post_id'] : 0;
                        }
                        if (!$post_id) continue;
                        $post_author = get_post_field('post_author', $post_id);
                        if ((int)$post_author === (int)$author_id) {
                            $total += (float)$item->get_total();
                        }
                    }
                }
            }
        }
        return $total * $commission_rate;
    }


    // ======= CHỨC NĂNG MỚI: QUẢN LÝ THANH TOÁN VÀ BÁO CÁO CHO ADMIN =======

    /**
     * Xử lý cập nhật trạng thái thanh toán từ form ở partner_dashboard.
     * Dữ liệu được lưu qua user meta:
     *  - "author_payment_status_{$selected_month}" với giá trị "chua" hoặc "da"
     *  - "author_carry_{$selected_month}" để lưu số tiền chưa được thanh toán (carry)
     */
    private function process_partner_dashboard_payments($selected_month) {
        if (isset($_POST['payment_status']) && is_array($_POST['payment_status'])) {
            foreach ($_POST['payment_status'] as $author_id => $status) {
                $author_id = intval($author_id);
                update_user_meta($author_id, "author_payment_status_{$selected_month}", sanitize_text_field($status));
                $price_per_view = get_option('author_views_price_per_view', 100);
                $commission_rate = get_option('partner_commission_rate', 10) / 100;
                $views = $this->get_author_monthly_views($author_id, $selected_month);
                $view_income = $views * $price_per_view;
                
                $start_date_obj = new WC_DateTime($selected_month . '-01 00:00:00');
                $end_date_obj   = new WC_DateTime(date('Y-m-t', strtotime($selected_month)) . ' 23:59:59');
                $start_date = $start_date_obj->date('Y-m-d H:i:s');
                $end_date = $end_date_obj->date('Y-m-d H:i:s');
                $args = array(
                    'limit' => -1,
                    'status' => 'completed',
                    'date_created_after' => $start_date,
                    'date_created_before' => $end_date,
                );
                $orders = wc_get_orders($args);
                $order_total = 0;
                if (!empty($orders)) {
                    foreach ($orders as $order) {
                        foreach ($order->get_items() as $item) {
                            $post_id = $item->get_meta('post_id');
                            if (!$post_id) {
                                $data = $item->get_data();
                                $post_id = isset($data['post_id']) ? $data['post_id'] : 0;
                            }
                            if (!$post_id) continue;
                            $post_author = get_post_field('post_author', $post_id);
                            if ((int)$post_author === $author_id) {
                                $order_total += (float)$item->get_total();
                            }
                        }
                    }
                }
                $commission_income = $order_total * $commission_rate;
                $total_income = $view_income + $commission_income;
                if (sanitize_text_field($status) === 'da') {
                    update_user_meta($author_id, "author_carry_{$selected_month}", 0);
                } else {
                    update_user_meta($author_id, "author_carry_{$selected_month}", $total_income);
                }
            }
        }
    }

    /**
     * Trang "Đối tác" (partner_dashboard) của Admin.
     * Phân chia tác giả thành 2 bảng:
     *  - Bảng 1: Tác giả có tổng thu nhập (lượt xem + hoa hồng) >= ngưỡng thanh toán,
     *             kèm cột "Trạng thái" có thể toggle.
     *  - Bảng 2: Tác giả có tổng thu nhập < ngưỡng thanh toán, hiển thị cả cột "Tồn".
     */
    public function render_partner_dashboard() {
        // Lấy các thiết lập
        $price_per_view  = get_option('author_views_price_per_view', 100);
        $threshold       = get_option('author_payment_threshold', 1000000);
        $selected_month  = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : date('Y-m');

        // Xử lý cập nhật trạng thái thanh toán nếu form POST được submit
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment_status'])) {
            $this->process_partner_dashboard_payments($selected_month);
            echo '<div class="updated"><p>Cập nhật trạng thái thanh toán thành công.</p></div>';
        }

        // Lấy thông tin đơn hàng không cần thiết trong phần tính hoa hồng vì giờ dùng get_author_monthly_commission()

        // Tính lượt xem
        $authors = get_users(array(
            'capability' => array('edit_posts'),
            'has_published_posts' => array('post')
        ));

        // Phân chia tác giả thành 2 nhóm: đủ điều kiện và chưa đủ điều kiện thanh toán
        $eligible = array();
        $noneligible = array();
        
        foreach ($authors as $author) {
            $aid  = $author->ID;
            $name = $author->display_name;

            // Lấy cột "Tồn": số tiền tồn của tháng hiện tại (được lưu qua user meta)
            $carry = get_user_meta($aid, "author_carry_{$selected_month}", true);
            $carry = is_numeric($carry) ? $carry : 0;

            // Lấy view_income: lượt xem * giá mỗi lượt xem
            $views = $this->get_author_monthly_views($aid, $selected_month);
            $view_income = $views * $price_per_view;

            // Lấy hoa hồng của tác giả thông qua hàm get_author_monthly_commission()
            $commission_income = $this->get_author_monthly_commission($aid, $selected_month);

            // Tổng thực nhận tháng = carry + view_income + commission_income
            $overall = $carry + $view_income + $commission_income;

            // Lấy trạng thái thanh toán của tháng hiện tại, mặc định là 'chua'
            $status = get_user_meta($aid, "author_payment_status_{$selected_month}", true);
            if (!$status) { $status = 'chua'; }

            $data = array(
                'name'              => $name,
                'carry'             => $carry,
                'view_income'       => $view_income,
                'commission_income' => $commission_income,
                'overall'           => $overall,
                'status'            => $status,
            );

            if ($overall >= $threshold) {
                $eligible[$aid] = $data;
            } else {
                $noneligible[$aid] = $data;
            }
        }

        echo '<div class="wrap">';
        echo '<h1>Tổng thu nhập tác giả - Tháng ' . esc_html(date('m-Y', strtotime($selected_month . '-01'))) . '</h1>';

        // Form chọn tháng
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="partner_dashboard">';
        echo '<select name="month">';
        $current_year = date('Y');
        for ($m = 1; $m <= 12; $m++) {
            $month_val = sprintf('%02d', $m);
            $option_value = $current_year . '-' . $month_val;
            $option_label = $month_val . '-' . $current_year;
            $sel = ($option_value == $selected_month) ? 'selected' : '';
            echo "<option value='" . esc_attr($option_value) . "' $sel>" . esc_html($option_label) . "</option>";
        }
        echo '</select> ';
        echo '<input type="submit" class="button button-primary" value="Xem">';
        echo '</form><br>';

        // Bảng 1: Tác giả đủ điều kiện thanh toán (≥ threshold) với cột toggle trạng thái
        echo '<form method="post">';
        echo '<input type="hidden" name="page" value="partner_dashboard">';
        echo '<input type="hidden" name="month" value="' . esc_attr($selected_month) . '">';
        echo '<h2>Danh sách tác giả đủ điều kiện thanh toán (≥ ' . wc_price($threshold) . ')</h2>';
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>Tác giả</th>';
        echo '<th>Tồn (tháng trước)</th>';
        echo '<th>Thu nhập lượt xem</th>';
        echo '<th>Thu nhập hoa hồng</th>';
        echo '<th>Tổng thực nhận</th>';
        echo '<th>Trạng thái</th>';
        echo '</tr></thead><tbody>';
        foreach ($eligible as $aid => $data) {
            echo '<tr>';
            echo '<td>' . esc_html($data['name']) . '</td>';
            echo '<td>' . wc_price($data['carry']) . '</td>';
            echo '<td>' . wc_price($data['view_income']) . '</td>';
            echo '<td>' . wc_price($data['commission_income']) . '</td>';
            echo '<td>' . wc_price($data['overall']) . '</td>';
            echo '<td>';
            echo '<select name="payment_status[' . intval($aid) . ']">';
            echo '<option value="chua"' . ($data['status'] === 'chua' ? ' selected' : '') . '>Chưa thanh toán</option>';
            echo '<option value="da"' . ($data['status'] === 'da' ? ' selected' : '') . '>Đã thanh toán</option>';
            echo '</select>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table><br>';

        // Bảng 2: Tác giả chưa đủ điều kiện (< threshold) (không có toggle)
        echo '<h2>Danh sách tác giả chưa đủ điều kiện (< ' . wc_price($threshold) . ')</h2>';
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>Tác giả</th>';
        echo '<th>Tồn (tháng trước)</th>';
        echo '<th>Thu nhập lượt xem</th>';
        echo '<th>Thu nhập hoa hồng</th>';
        echo '<th>Tổng thực nhận</th>';
        echo '</tr></thead><tbody>';
        foreach ($noneligible as $aid => $data) {
            echo '<tr>';
            echo '<td>' . esc_html($data['name']) . '</td>';
            echo '<td>' . wc_price($data['carry']) . '</td>';
            echo '<td>' . wc_price($data['view_income']) . '</td>';
            echo '<td>' . wc_price($data['commission_income']) . '</td>';
            echo '<td>' . wc_price($data['overall']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p><input type="submit" name="update_payment_status" class="button button-primary" value="Cập nhật trạng thái thanh toán"></p>';
        echo '</form>';
        echo '</div>';
    }

        public function render_author_yearly_income_summary() {
            $author_id       = get_current_user_id();
            $price_per_view  = get_option('author_views_price_per_view', 100);
            $current_year    = date('Y');
            $current_month   = date('n'); // Số tháng hiện tại (1–12)

            echo '<div class="wrap">';
            echo '<h1>Tổng hợp chi tiết thu nhập của năm ' . esc_html($current_year) . '</h1>';
            echo '<p>Dữ liệu tính từ tháng 1 đến tháng ' . $current_month . ' năm ' . $current_year . '.</p>';

            // Header của bảng
            echo '<table class="widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>Tháng</th>';
            echo '<th>Tồn tháng trước</th>';
            echo '<th>Lượt xem</th>';
            echo '<th>Hoa hồng</th>';
            echo '<th>Thực nhận</th>';
            echo '<th>Trạng thái</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            $carry_prev = 0;
            $sum_view_income = 0;
            $sum_commission_income = 0;

            for ($m = 1; $m <= $current_month; $m++) {
                $month_str = sprintf('%04d-%02d', $current_year, $m);

                // Lượt xem tháng và tính thu nhập
                $views = $this->get_author_monthly_views($author_id, $month_str);
                $view_income = $views * $price_per_view;
                $sum_view_income += $view_income;

                // Hoa hồng: sử dụng helper để chỉ lấy hoa hồng của tháng đó
                $commission_income = $this->get_author_monthly_commission($author_id, $month_str);
                $sum_commission_income += $commission_income;

                // Tổng thực nhận của tháng
                $month_total = $carry_prev + $view_income + $commission_income;

                // Trạng thái thanh toán
                $status = get_user_meta($author_id, "author_payment_status_{$month_str}", true) ?: 'chua';
                $status_text = ($status === 'da') ? 'Đã thanh toán' : 'Chưa thanh toán';

                echo '<tr>';
                echo '<td>' . esc_html("Tháng " . sprintf('%02d', $m) . "-{$current_year}") . '</td>';
                echo '<td>' . wc_price($carry_prev) . '</td>';
                echo '<td>' . wc_price($view_income) . '</td>';
                echo '<td>' . wc_price($commission_income) . '</td>';
                echo '<td>' . wc_price($month_total) . '</td>';
                echo '<td>' . esc_html($status_text) . '</td>';
                echo '</tr>';

                // Cập nhật carry cho tháng sau
                $carry_prev = ($status === 'da') ? 0 : $month_total;
            }

            echo '</tbody>';
            echo '<tfoot><tr>';
            echo '<th colspan="4" style="text-align:right;">Tổng thu nhập cả năm tính tới hiện tại:</th>';
            echo '<th>' . wc_price($sum_view_income + $sum_commission_income) . '</th>';
            echo '<th></th>';
            echo '</tr></tfoot>';
            echo '</table>';
            echo '</div>';
        }

                // ======= EMAIL REPORT FUNCTIONALITY =======

                public function schedule_monthly_emails() {
                    if (!wp_next_scheduled('author_views_monthly_email_event')) {
                        wp_schedule_event(strtotime('first day of next month midnight'), 'monthly', 'author_views_monthly_email_event');
                    }
                }

                public function clear_monthly_email_schedule() {
                    wp_clear_scheduled_hook('author_views_monthly_email_event');
                }

                public function send_monthly_email_reports() {
                    $month               = date('Y-m', strtotime('last month'));
                    $price_per_view      = get_option('author_views_price_per_view', 100);
                    $commission_rate     = get_option('partner_commission_rate', 10) / 100;
                    // Lấy chỉ contributors
                    $authors = get_users([
                        'role' => 'contributor',
                    ]);

                    foreach ($authors as $author) {
                        // —————— 1) TÍNH TOÁN LƯỢT XEM ——————
                        $posts = get_posts([
                            'post_type'      => 'post',
                            'author'         => $author->ID,
                            'posts_per_page' => -1,
                        ]);
                        $total_views       = 0;
                        $total_view_income = 0;
                        foreach ($posts as $post) {
                            $views = (int) get_post_meta($post->ID, "_post_views_{$month}", true);
                            $total_views    += $views;
                        }
                        $total_view_income = $total_views * $price_per_view;

                        // —————— 2) TÍNH TOÁN HOA HỒNG ——————
                        $start = "{$month}-01 00:00:00";
                        $end   = date('Y-m-t 23:59:59', strtotime($start));
                        $orders = wc_get_orders([
                            'limit'               => -1,
                            'status'              => 'completed',
                            'date_created_after'  => $start,
                            'date_created_before' => $end,
                        ]);
                        $total_revenue           = 0;
                        $total_commission_income = 0;
                        foreach ($orders as $order) {
                            foreach ($order->get_items() as $item) {
                                $post_id = $item->get_meta('post_id') ?: ($item->get_data()['post_id'] ?? 0);
                                if (!$post_id) continue;
                                if ((int)get_post_field('post_author', $post_id) !== $author->ID) continue;
                                $rev = (float)$item->get_total();
                                $total_revenue           += $rev;
                            }
                        }
                        $total_commission_income = $total_revenue * $commission_rate;

                        // —————— 3) BỎ QUA NẾU KHÔNG CÓ THU NHẬP ——————
                        if ($total_view_income + $total_commission_income <= 0) {
                            continue;
                        }

                        // —————— 4) TỔNG THU NHẬP ——————
                        $total_overall = $total_view_income + $total_commission_income;

                        // —————— 5) TẠO CSV LƯỢT XEM ——————
                        $csv1 = "Bài viết,Lượt xem,Thu nhập VND\n";
                        foreach ($posts as $post) {
                            $views  = (int) get_post_meta($post->ID, "_post_views_{$month}", true);
                            $income = $views * $price_per_view;
                            $csv1  .= '"' . implode('","', [$post->post_title, $views, $income]) . "\"\n";
                        }
                        $upload = wp_upload_dir();
                        $path1  = "{$upload['basedir']}/report_views_{$author->ID}_{$month}.csv";
                        file_put_contents($path1, $csv1);

                        // —————— 6) TẠO CSV HOA HỒNG ——————
                        $csv2 = "Sản phẩm,Doanh thu VND,Hoa hồng VND\n";
                        foreach ($orders as $order) {
                            foreach ($order->get_items() as $item) {
                                $post_id = $item->get_meta('post_id') ?: ($item->get_data()['post_id'] ?? 0);
                                if (!$post_id || (int)get_post_field('post_author', $post_id) !== $author->ID) continue;
                                $name = $item->get_name();
                                $rev  = (float)$item->get_total();
                                $comm = $rev * $commission_rate;
                                $csv2 .= '"' . implode('","', [$name, $rev, $comm]) . "\"\n";
                            }
                        }
                        $path2 = "{$upload['basedir']}/report_commission_{$author->ID}_{$month}.csv";
                        file_put_contents($path2, $csv2);

                        // —————— 7) GỬI MAIL ——————
                        $email   = $author->user_email;
                        $subject = "Báo cáo thu nhập tháng " . date('m-Y', strtotime($month . '-01'));
                        $body    = "<p>Xin chào <strong>{$author->display_name}</strong>,</p>";
                        $body   .= "<p>Đây là báo cáo thu nhập tháng <strong>" . date('m-Y', strtotime($month . '-01')) . "</strong>:</p>";
                        $body   .= "<ul>"
                                . "<li>Lượt xem: <strong>{$total_views}</strong></li>"
                                . "<li>Thu nhập lượt xem: <strong>" . number_format($total_view_income) . "</strong> đ</li>"
                                . "<li>Doanh thu (đơn hàng): <strong>" . number_format($total_revenue) . "</strong> đ</li>"
                                . "<li>Hoa hồng: <strong>" . number_format($total_commission_income) . "</strong> đ</li>"
                                . "</ul>";
                        $body   .= "<strong>Tổng thu nhập: " . number_format($total_overall) . " đ</strong>";
                        $body   .= "<p>Chi tiết lượt xem đính kèm file CSV 1, chi tiết hoa hồng đính kèm file CSV 2.</p>";
                        $body   .= "<p>Trân trọng,<br>Nhà in online</p>";

                        wp_mail(
                            $email,
                            $subject,
                            $body,
                            ['Content-Type: text/html; charset=UTF-8'],
                            [$path1, $path2]
                        );

                        @unlink($path1);
                        @unlink($path2);
                    }
                }


            }

new Author_Post_Views();

// Custom monthly interval
add_filter('cron_schedules', function($schedules) {
    $schedules['monthly'] = array(
        'interval' => 30 * DAY_IN_SECONDS,
        'display'  => __('Once Monthly')
    );
    return $schedules;
});
