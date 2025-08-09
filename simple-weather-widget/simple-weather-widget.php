<?php
/**
 * Plugin Name: Simple Weather Widget
 * Description: A lightweight WordPress widget that fetches current weather from OpenWeatherMap and displays it. Caches responses via transients.
 * Version: 1.0.0
 * Author: Cryptoball cryptoball7@gmail.com
 * License: GPLv3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class Simple_Weather_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'simple_weather_widget',
            __( 'Simple Weather Widget', 'simple-weather' ),
            array( 'description' => __( 'Shows current weather from OpenWeatherMap', 'simple-weather' ) )
        );

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function enqueue_assets() {
        wp_register_style( 'simple-weather-widget-style', plugins_url( 'assets/simple-weather.css', __FILE__ ) );
        wp_enqueue_style( 'simple-weather-widget-style' );
    }

    public function widget( $args, $instance ) {
        echo $args['before_widget'];

        $title = ! empty( $instance['title'] ) ? $instance['title'] : '';
        $city = ! empty( $instance['city'] ) ? $instance['city'] : '';
        $api_key = ! empty( $instance['api_key'] ) ? $instance['api_key'] : '';
        $units = ! empty( $instance['units'] ) ? $instance['units'] : 'metric';
        $cache_minutes = isset( $instance['cache_minutes'] ) ? (int) $instance['cache_minutes'] : 10;

        if ( ! empty( $title ) ) {
            echo $args['before_title'] . apply_filters( 'widget_title', $title ) . $args['after_title'];
        }

        if ( empty( $city ) || empty( $api_key ) ) {
            echo '<p>' . esc_html__( 'Please configure both City and API Key in the widget settings.', 'simple-weather' ) . '</p>';
            echo $args['after_widget'];
            return;
        }

        $cache_key = 'simple_weather_' . md5( strtolower( $city ) . '|' . $units );
        $cached = get_transient( $cache_key );

        if ( $cached && is_array( $cached ) ) {
            $weather = $cached;
        } else {
            $weather = $this->fetch_weather( $city, $api_key, $units );
            if ( is_wp_error( $weather ) ) {
                echo '<p>' . esc_html__( 'Weather service is currently unavailable.', 'simple-weather' ) . '</p>';
                echo $args['after_widget'];
                return;
            }

            // store in transient
            set_transient( $cache_key, $weather, $cache_minutes * MINUTE_IN_SECONDS );
        }

        // Render weather
        if ( isset( $weather['main'] ) ) {
            $temp = isset( $weather['main']['temp'] ) ? $weather['main']['temp'] : '';
            $desc = isset( $weather['weather'][0]['description'] ) ? $weather['weather'][0]['description'] : '';
            $icon = isset( $weather['weather'][0]['icon'] ) ? $weather['weather'][0]['icon'] : '';
            $humidity = isset( $weather['main']['humidity'] ) ? $weather['main']['humidity'] : '';
            $wind_speed = isset( $weather['wind']['speed'] ) ? $weather['wind']['speed'] : '';

            // sanitize output
            $temp_out = $temp !== '' ? esc_html( round( $temp ) ) : '-';
            $desc_out = $desc !== '' ? esc_html( ucwords( $desc ) ) : '';

            $unit_symbol = ( $units === 'imperial' ) ? '&deg;F' : '&deg;C';

            echo '<div class="simple-weather-widget">';

            if ( $icon ) {
                // using OpenWeatherMap icons
                $icon_url = esc_url( "https://openweathermap.org/img/wn/{$icon}@2x.png" );
                echo "<div class=\"sw-icon\"><img src=\"{$icon_url}\" alt=\"{$desc_out}\" width=64 height=64></div>";
            }

            echo '<div class="sw-data">';
            echo "<div class=\"sw-temp\">{$temp_out}{$unit_symbol}</div>";
            if ( $desc_out ) {
                echo "<div class=\"sw-desc\">{$desc_out}</div>";
            }

            echo '<ul class="sw-meta">';
            if ( $humidity !== '' ) {
                echo '<li>' . sprintf( esc_html__( 'Humidity: %s%%', 'simple-weather' ), esc_html( $humidity ) ) . '</li>';
            }
            if ( $wind_speed !== '' ) {
                $wind_label = ( $units === 'imperial' ) ? esc_html__( '%s mph', 'simple-weather' ) : esc_html__( '%s m/s', 'simple-weather' );
                echo '<li>' . sprintf( $wind_label, esc_html( $wind_speed ) ) . '</li>';
            }
            echo '</ul>';

            echo '</div>'; // .sw-data
            echo '<div style="clear:both"></div>';
            echo '</div>'; // .simple-weather-widget

        } else {
            echo '<p>' . esc_html__( 'No weather data available.', 'simple-weather' ) . '</p>';
        }

        echo $args['after_widget'];
    }

    /**
     * Fetch weather from OpenWeatherMap
     */
    protected function fetch_weather( $city, $api_key, $units = 'metric' ) {
        $api_url = add_query_arg(
            array(
                'q' => rawurlencode( $city ),
                'appid' => $api_key,
                'units' => $units,
            ),
            'https://api.openweathermap.org/data/2.5/weather'
        );

        $response = wp_remote_get( $api_url, array( 'timeout' => 10 ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            return new WP_Error( 'weather_error', 'API returned status ' . intval( $code ) );
        }

        $data = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'json_error', 'Invalid JSON from API' );
        }

        return $data;
    }

    public function form( $instance ) {
        $title = isset( $instance['title'] ) ? $instance['title'] : __( 'Weather', 'simple-weather' );
        $city = isset( $instance['city'] ) ? $instance['city'] : '';
        $api_key = isset( $instance['api_key'] ) ? $instance['api_key'] : '';
        $units = isset( $instance['units'] ) ? $instance['units'] : 'metric';
        $cache_minutes = isset( $instance['cache_minutes'] ) ? (int) $instance['cache_minutes'] : 10;
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'simple-weather' ); ?></label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'city' ) ); ?>"><?php esc_html_e( 'City (name or "City,CountryCode"):', 'simple-weather' ); ?></label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'city' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'city' ) ); ?>" type="text" value="<?php echo esc_attr( $city ); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'api_key' ) ); ?>"><?php esc_html_e( 'OpenWeatherMap API Key:', 'simple-weather' ); ?></label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'api_key' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'api_key' ) ); ?>" type="text" value="<?php echo esc_attr( $api_key ); ?>">
            <small><?php esc_html_e( 'Get an API key from https://openweathermap.org', 'simple-weather' ); ?></small>
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'units' ) ); ?>"><?php esc_html_e( 'Units:', 'simple-weather' ); ?></label>
            <select id="<?php echo esc_attr( $this->get_field_id( 'units' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'units' ) ); ?>" class="widefat">
                <option value="metric" <?php selected( $units, 'metric' ); ?>><?php esc_html_e( 'Metric (C, m/s)', 'simple-weather' ); ?></option>
                <option value="imperial" <?php selected( $units, 'imperial' ); ?>><?php esc_html_e( 'Imperial (F, mph)', 'simple-weather' ); ?></option>
            </select>
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'cache_minutes' ) ); ?>"><?php esc_html_e( 'Cache duration (minutes):', 'simple-weather' ); ?></label>
            <input id="<?php echo esc_attr( $this->get_field_id( 'cache_minutes' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'cache_minutes' ) ); ?>" type="number" min="1" value="<?php echo esc_attr( $cache_minutes ); ?>" class="small-text">
            <small><?php esc_html_e( 'How long to cache API responses (to avoid rate limits).', 'simple-weather' ); ?></small>
        </p>
        <?php
    }

    public function update( $new_instance, $old_instance ) {
        $instance = array();
        $instance['title'] = sanitize_text_field( $new_instance['title'] );
        $instance['city'] = sanitize_text_field( $new_instance['city'] );
        $instance['api_key'] = sanitize_text_field( $new_instance['api_key'] );
        $instance['units'] = in_array( $new_instance['units'], array( 'metric', 'imperial' ), true ) ? $new_instance['units'] : 'metric';
        $instance['cache_minutes'] = max( 1, intval( $new_instance['cache_minutes'] ) );

        // Clear transient when settings change for this city/units
        if ( isset( $old_instance['city'] ) && $old_instance['city'] !== $instance['city'] ) {
            delete_transient( 'simple_weather_' . md5( strtolower( $old_instance['city'] ) . '|' . $old_instance['units'] ) );
        }

        return $instance;
    }
}

function simple_weather_register_widget() {
    register_widget( 'Simple_Weather_Widget' );
}
add_action( 'widgets_init', 'simple_weather_register_widget' );

// Provide a minimal stylesheet so widget looks decent out of the box
function simple_weather_create_assets() {
    $css_dir = plugin_dir_path( __FILE__ ) . 'assets';
    if ( ! file_exists( $css_dir ) ) {
        wp_mkdir_p( $css_dir );
    }
    $css_file = $css_dir . '/simple-weather.css';
    if ( ! file_exists( $css_file ) ) {
        $css = 
".simple-weather-widget{border:1px solid #e1e1e1;padding:10px;border-radius:6px;font-family:Segoe UI,Roboto,Arial,sans-serif;}
.simple-weather-widget .sw-icon{float:left;margin-right:10px}
.simple-weather-widget .sw-data{overflow:hidden}
.simple-weather-widget .sw-temp{font-size:24px;font-weight:700}
.simple-weather-widget .sw-desc{font-size:14px;color:#555}
.simple-weather-widget .sw-meta{list-style:none;padding:0;margin:8px 0 0 0;font-size:13px}
.simple-weather-widget .sw-meta li{margin-bottom:4px}
";
        file_put_contents( $css_file, $css );
    }
}
register_activation_hook( __FILE__, 'simple_weather_create_assets' );

?>
