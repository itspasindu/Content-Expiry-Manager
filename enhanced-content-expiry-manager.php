<?php
/**
 * Plugin Name: Enhanced Content Expiry Manager
 * Description: Advanced content expiration management with custom actions, notifications, and analytics
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include the widget class
require_once(plugin_dir_path(__FILE__) . 'class-content-expiry-countdown-widget.php');

class EnhancedContentExpiryManager {
    private static $instance = null;
    
    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Initialize plugin
        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Schedule cron jobs
        add_action('wp', array($this, 'schedule_expiry_check'));
        add_action('content_expiry_check', array($this, 'check_expired_content'));
        
        // Add meta boxes
        add_action('add_meta_boxes', array($this, 'add_expiry_meta_box'));
        add_action('save_post', array($this, 'save_expiry_meta'));
        
        // Frontend hooks
        add_filter('the_content', array($this, 'handle_expired_content'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Register widget
        add_action('widgets_init', array($this, 'register_widgets'));
    }
    
    public function register_widgets() {
        register_widget('Content_Expiry_Countdown_Widget');
    }
    
    public function init() {
        // Register custom post statuses
        register_post_status('expired', array(
            'label' => _x('Expired', 'post'),
            'public' => false,
            'exclude_from_search' => true,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Expired <span class="count">(%s)</span>',
                                   'Expired <span class="count">(%s)</span>')
        ));
    }
    
    public function admin_init() {
        // Register settings
        register_setting('content_expiry_options', 'content_expiry_settings');
        
        // Add settings sections
        add_settings_section(
            'content_expiry_general',
            'General Settings',
            array($this, 'render_settings_section'),
            'content_expiry_settings'
        );
        
        // Add settings fields
        add_settings_field(
            'default_expiry_period',
            'Default Expiry Period (days)',
            array($this, 'render_default_expiry_field'),
            'content_expiry_settings',
            'content_expiry_general'
        );
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'Content Expiry Manager',
            'Content Expiry',
            'manage_options',
            'content-expiry-manager',
            array($this, 'render_admin_page'),
            'dashicons-calendar-alt'
        );
        
        add_submenu_page(
            'content-expiry-manager',
            'Analytics',
            'Analytics',
            'manage_options',
            'content-expiry-analytics',
            array($this, 'render_analytics_page')
        );
    }
    
    public function render_settings_section() {
        echo '<p>Configure general settings for content expiration.</p>';
    }
    
    public function render_default_expiry_field() {
        $options = get_option('content_expiry_settings');
        $value = isset($options['default_expiry_period']) ? $options['default_expiry_period'] : 30;
        echo '<input type="number" name="content_expiry_settings[default_expiry_period]" value="' . esc_attr($value) . '" />';
    }
    
    public function add_expiry_meta_box() {
        $post_types = get_post_types(array('public' => true));
        foreach ($post_types as $post_type) {
            add_meta_box(
                'content_expiry_meta_box',
                'Content Expiry Settings',
                array($this, 'render_expiry_meta_box'),
                $post_type,
                'side',
                'high'
            );
        }
    }
    
    public function render_expiry_meta_box($post) {
        wp_nonce_field('content_expiry_meta_box', 'content_expiry_meta_box_nonce');
        
        $expiry_date = get_post_meta($post->ID, '_content_expiry_date', true);
        $expiry_action = get_post_meta($post->ID, '_content_expiry_action', true);
        ?>
        <p>
            <label for="content_expiry_date">Expiry Date:</label>
            <input type="datetime-local" id="content_expiry_date" name="content_expiry_date"
                   value="<?php echo esc_attr($expiry_date); ?>" />
        </p>
        <p>
            <label for="content_expiry_action">Action on Expiry:</label>
            <select id="content_expiry_action" name="content_expiry_action">
                <option value="archive" <?php selected($expiry_action, 'archive'); ?>>Archive</option>
                <option value="delete" <?php selected($expiry_action, 'delete'); ?>>Delete</option>
                <option value="redirect" <?php selected($expiry_action, 'redirect'); ?>>Redirect</option>
            </select>
        </p>
        <?php
    }
    
    public function save_expiry_meta($post_id) {
        if (!isset($_POST['content_expiry_meta_box_nonce'])) {
            return;
        }
        
        if (!wp_verify_nonce($_POST['content_expiry_meta_box_nonce'], 'content_expiry_meta_box')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        if (isset($_POST['content_expiry_date'])) {
            update_post_meta($post_id, '_content_expiry_date', sanitize_text_field($_POST['content_expiry_date']));
        }
        
        if (isset($_POST['content_expiry_action'])) {
            update_post_meta($post_id, '_content_expiry_action', sanitize_text_field($_POST['content_expiry_action']));
        }
    }
    
    public function check_expired_content() {
        $args = array(
            'post_type' => 'any',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_content_expiry_date',
                    'value' => current_time('mysql'),
                    'compare' => '<=',
                    'type' => 'DATETIME'
                )
            )
        );
        
        $expired_posts = new WP_Query($args);
        
        if ($expired_posts->have_posts()) {
            while ($expired_posts->have_posts()) {
                $expired_posts->the_post();
                $this->handle_post_expiry(get_the_ID());
            }
        }
        
        wp_reset_postdata();
    }
    
    private function handle_post_expiry($post_id) {
        $action = get_post_meta($post_id, '_content_expiry_action', true);
        
        switch ($action) {
            case 'archive':
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_status' => 'expired'
                ));
                break;
                
            case 'delete':
                wp_delete_post($post_id, true);
                break;
                
            case 'redirect':
                // Handle redirect logic
                update_post_meta($post_id, '_content_expired_redirect', true);
                break;
        }
        
        // Send notifications
        $this->send_expiry_notifications($post_id);
        
        // Log the expiry
        $this->log_content_expiry($post_id);
    }
    
    private function send_expiry_notifications($post_id) {
        $post = get_post($post_id);
        $author_email = get_the_author_meta('user_email', $post->post_author);
        
        // Email notification
        $subject = sprintf('Content Expired: %s', get_the_title($post_id));
        $message = sprintf(
            'The following content has expired:\n\nTitle: %s\nURL: %s\n\nPlease review and take necessary action.',
            get_the_title($post_id),
            get_permalink($post_id)
        );
        
        wp_mail($author_email, $subject, $message);
        
        // Slack notification (if configured)
        $this->send_slack_notification($post_id);
    }
    
    private function send_slack_notification($post_id) {
        $webhook_url = get_option('content_expiry_slack_webhook');
        if (!$webhook_url) {
            return;
        }
        
        $post = get_post($post_id);
        $payload = array(
            'text' => sprintf(
                'Content Expired: %s\nURL: %s',
                get_the_title($post_id),
                get_permalink($post_id)
            )
        );
        
        wp_remote_post($webhook_url, array(
            'body' => json_encode($payload),
            'headers' => array('Content-Type' => 'application/json')
        ));
    }
    
    private function log_content_expiry($post_id) {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'content_expiry_log',
            array(
                'post_id' => $post_id,
                'expiry_date' => current_time('mysql'),
                'action_taken' => get_post_meta($post_id, '_content_expiry_action', true)
            ),
            array('%d', '%s', '%s')
        );
    }
    
    public function handle_expired_content($content) {
        global $post;
        
        if (!is_singular() || !isset($post->ID)) {
            return $content;
        }
        
        if (get_post_meta($post->ID, '_content_expired_redirect', true)) {
            $redirect_url = $this->get_redirect_url($post->ID);
            wp_redirect($redirect_url);
            exit;
        }
        
        if (get_post_status() === 'expired') {
            return $this->get_expired_content_message($post->ID);
        }
        
        return $content;
    }
    
    private function get_redirect_url($post_id) {
        $redirect_rule = get_post_meta($post_id, '_content_expiry_redirect_rule', true);
        
        if (empty($redirect_rule)) {
            return home_url();
        }
        
        $redirect_url = str_replace(
            array('{category}', '{tag}'),
            array(
                get_the_category($post_id)[0]->slug,
                get_the_tags($post_id)[0]->slug
            ),
            $redirect_rule
        );
        
        return $redirect_url;
    }
    
    private function get_expired_content_message($post_id) {
        $message = get_option('content_expiry_message', 'This content is no longer available.');
        
        if (get_option('content_expiry_show_related', true)) {
            $message .= $this->get_related_posts($post_id);
        }
        
        return '<div class="expired-content-message">' . $message . '</div>';
    }
    
    private function get_related_posts($post_id) {
        $args = array(
            'post_type' => get_post_type($post_id),
            'posts_per_page' => 3,
            'post__not_in' => array($post_id),
            'orderby' => 'rand'
        );
        
        $related_posts = new WP_Query($args);
        
        if (!$related_posts->have_posts()) {
            return '';
        }
        
        $output = '<div class="related-posts"><h3>Related Content</h3><ul>';
        
        while ($related_posts->have_posts()) {
            $related_posts->the_post();
            $output .= sprintf(
                '<li><a href="%s">%s</a></li>',
                get_permalink(),
                get_the_title()
            );
        }
        
        wp_reset_postdata();
        
        $output .= '</ul></div>';
        
        return $output;
    }
    
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>Content Expiry Manager</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('content_expiry_options');
                do_settings_sections('content_expiry_settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
    
    public function render_analytics_page() {
        $stats = $this->get_expiry_stats();
        ?>
        <div class="wrap">
            <h1>Content Expiry Analytics</h1>
            <div class="expiry-stats">
                <div class="stat-box">
                    <h3>Total Expired Posts</h3>
                    <p><?php echo esc_html($stats['total_expired']); ?></p>
                </div>
                <div class="stat-box">
                    <h3>Upcoming Expirations (30 days)</h3>
                    <p><?php echo esc_html($stats['upcoming_expired']); ?></p>
                </div>
            </div>
        </div>
        <?php
    }
    
    private function get_expiry_stats() {
        global $wpdb;
        
        return array(
            'total_expired' => $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'expired'"
            ),
            'upcoming_expired' => $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} 
                    WHERE meta_key = '_content_expiry_date' 
                    AND meta_value BETWEEN %s AND %s",
                    current_time('mysql'),
                    date('Y-m-d H:i:s', strtotime('+30 days'))
                )
            )
        );
    }
    
    public function activate() {
        // Create necessary database tables
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}content_expiry_log (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            expiry_date datetime NOT NULL,
            action_taken varchar(50) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY expiry_date (expiry_date)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Create default options
        $default_options = array(
            'default_expiry_period' => 30,
            'notification_email' => get_option('admin_email'),
            'enable_slack' => false,
            'slack_webhook' => '',
            'enable_countdown' => true,
            'countdown_threshold' => 7,
            'expired_content_message' => 'This content is no longer available.',
            'show_related_posts' => true,
            'backup_expired_content' => true
        );
        
        foreach ($default_options as $key => $value) {
            add_option('content_expiry_' . $key, $value);
        }
        
        // Schedule cron job
        if (!wp_next_scheduled('content_expiry_check')) {
            wp_schedule_event(time(), 'hourly', 'content_expiry_check');
        }
    }
    
    public function deactivate() {
        // Clear scheduled hooks
        wp_clear_scheduled_hook('content_expiry_check');
    }
    
    public function enqueue_frontend_scripts() {
        if (is_singular() && has_expiry_date()) {
            wp_enqueue_style(
                'content-expiry-frontend',
                plugins_url('assets/css/frontend.css', __FILE__),
                array(),
                '1.0.0'
            );
            
            wp_enqueue_script(
                'content-expiry-countdown',
                plugins_url('assets/js/countdown.js', __FILE__),
                array('jquery'),
                '1.0.0',
                true
            );
            
            wp_localize_script('content-expiry-countdown', 'contentExpiryData', array(
                'expiryDate' => get_post_meta(get_the_ID(), '_content_expiry_date', true),
                'countdownText' => __('Content expires in: ', 'content-expiry-manager')
            ));
        }
    }
    
    public function enqueue_admin_scripts($hook) {
        if ('post.php' != $hook && 'post-new.php' != $hook) {
            return;
        }
        
        wp_enqueue_style(
            'content-expiry-admin',
            plugins_url('assets/css/admin.css', __FILE__),
            array(),
            '1.0.0'
        );
        
        wp_enqueue_script(
            'content-expiry-admin',
            plugins_url('assets/js/admin.js', __FILE__),
            array('jquery', 'jquery-ui-datepicker'),
            '1.0.0',
            true
        );
    }
    
    // Bulk Management Methods
    public function bulk_edit_custom_box($column_name, $post_type) {
        if ($column_name !== 'expiry_date') {
            return;
        }
        ?>
        <fieldset class="inline-edit-col-right">
            <div class="inline-edit-col">
                <label>
                    <span class="title">Expiry Date</span>
                    <input type="datetime-local" name="content_expiry_date" />
                </label>
            </div>
        </fieldset>
        <?php
    }
    
    public function save_bulk_edit($post_id) {
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        if (isset($_REQUEST['content_expiry_date'])) {
            update_post_meta($post_id, '_content_expiry_date', 
                sanitize_text_field($_REQUEST['content_expiry_date']));
        }
    }
}
    
if (!class_exists('Content_Expiry_Countdown_Widget')) {
    class Content_Expiry_Countdown_Widget extends WP_Widget {
        public function __construct() {
            parent::__construct(
                'content_expiry_countdown',
                'Content Expiry Countdown',
                array('description' => 'Displays countdown for content expiration')
            );
        }

        public function widget($args, $instance) {
            if (!is_singular()) {
                return;
            }

            $expiry_date = get_post_meta(get_the_ID(), '_content_expiry_date', true);
            if (!$expiry_date) {
                return;
            }

            echo $args['before_widget'];
            if (!empty($instance['title'])) {
                echo $args['before_title'] . apply_filters('widget_title', $instance['title']) . $args['after_title'];
            }
            ?>
            <div class="expiry-countdown" data-expiry="<?php echo esc_attr($expiry_date); ?>">
                <!-- Countdown will be inserted here via JavaScript -->
            </div>
            <?php
            echo $args['after_widget'];
        }

        public function form($instance) {
            $title = !empty($instance['title']) ? $instance['title'] : '';
            ?>
            <p>
                <label for="<?php echo $this->get_field_id('title'); ?>">Title:</label>
                <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>"
                       name="<?php echo $this->get_field_name('title'); ?>" type="text"
                       value="<?php echo esc_attr($title); ?>">
            </p>
            <?php
        }

        public function update($new_instance, $old_instance) {
            $instance = array();
            $instance['title'] = (!empty($new_instance['title'])) 
                ? strip_tags($new_instance['title']) 
                : '';
            return $instance;
        }
    }
}

// Function to register the widget
function register_countdown_widget() {
    register_widget('Content_Expiry_Countdown_Widget');
}
add_action('widgets_init', 'register_countdown_widget');


// Initialize the plugin
function initialize_content_expiry_manager() {
    return EnhancedContentExpiryManager::getInstance();
}
add_action('plugins_loaded', 'initialize_content_expiry_manager');
add_action('widgets_init', 'register_countdown_widget');

// Ensure this is within the EnhancedContentExpiryManager class
if (!class_exists('EnhancedContentExpiryManager')) {
class EnhancedContentExpiryManager {
    private static $instance = null;

    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Initialization code...
    }

    // Backup functionality
    private function create_content_backup($post_id) {
        $post = get_post($post_id, ARRAY_A);
        $meta = get_post_meta($post_id);
        
        $backup = array(
            'post' => $post,
            'meta' => $meta,
            'backup_date' => current_time('mysql')
        );
        
        update_post_meta($post_id, '_content_expiry_backup', $backup);
    }
    
    public function restore_expired_content($post_id) {
        $backup = get_post_meta($post_id, '_content_expiry_backup', true);
        
        if (!$backup) {
            return false;
        }
        
        // Restore post
        wp_update_post($backup['post']);
        
        // Restore meta
        foreach ($backup['meta'] as $meta_key => $meta_values) {
            if ($meta_key !== '_content_expiry_backup') {
                update_post_meta($post_id, $meta_key, $meta_values[0]);
            }
        }
        
        // Clear expired status
        delete_post_meta($post_id, '_content_expired_redirect');
        
        return true;
    }

    // Other methods...

    
    // Helper Methods
    public function has_expiry_date($post_id = null) {
        if (!$post_id) {
            $post_id = get_the_ID();
        }
        
        $expiry_date = get_post_meta($post_id, '_content_expiry_date', true);
        return !empty($expiry_date);
    }
    
    public function get_expiry_date($post_id = null, $format = 'Y-m-d H:i:s') {
        if (!$post_id) {
            $post_id = get_the_ID();
        }
        
        $expiry_date = get_post_meta($post_id, '_content_expiry_date', true);
        return $expiry_date ? date($format, strtotime($expiry_date)) : false;
    }
}

// Initialize the plugin
if (!function_exists('initialize_content_expiry_manager')) {
    function initialize_content_expiry_manager() {
        return EnhancedContentExpiryManager::getInstance();
    }
}

add_action('plugins_loaded', 'initialize_content_expiry_manager');

// Additional frontend scripts for countdown functionality
function content_expiry_countdown_script() {
    ?>
    <script>
    jQuery(document).ready(function($) {
        $('.expiry-countdown').each(function() {
            var $countdown = $(this);
            var expiryDate = new Date($countdown.data('expiry'));
            
            function updateCountdown() {
                var now = new Date();
                var diff = expiryDate - now;
                
                if (diff <= 0) {
                    $countdown.html('Content has expired');
                    return;
                }
                
                var days = Math.floor(diff / (1000 * 60 * 60 * 24));
                var hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                var minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                var seconds = Math.floor((diff % (1000 * 60)) / 1000);
                
                $countdown.html(
                    days + ' days ' + hours + ' hours ' + 
                    minutes + ' minutes ' + seconds + ' seconds'
                );
            }
            
            updateCountdown();
            setInterval(updateCountdown, 1000);
        });
    });
    </script>
    <?php
}
add_action('wp_footer', 'content_expiry_countdown_script');

}