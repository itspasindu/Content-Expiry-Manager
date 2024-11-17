<?php
/**
 * Widget Class for Content Expiry Countdown
 */
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