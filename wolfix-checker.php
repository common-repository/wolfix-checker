<?php
/*
Plugin Name: Wolfix Checker
Description: Analizează problemele site-ului tău și le trimite către panoul de client Wolfix.
Version: 1.0
Author: Wolfix
Author URI: https://wolfix.ro/
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/

add_action('plugins_loaded', 'wolfix_checker_init');

function wolfix_checker_init() {
    // Ensure the Site Health class is available.
    if (!class_exists('WP_Site_Health')) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
    }

    // Register REST API route.
    add_action('rest_api_init', 'wolfix_register_routes');
}

function wolfix_register_routes() {
    register_rest_route('wolfix-checker/v1', '/site-health', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'wolfix_get_site_health',
        'permission_callback' => '__return_true', // Adjust the permission callback as needed
    ));
}

function wolfix_get_site_health(WP_REST_Request $request) {
    // Ensure necessary WordPress classes and functions are available
    require_once(ABSPATH . 'wp-admin/includes/update.php');
    require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    require_once(ABSPATH . 'wp-admin/includes/theme.php');

    $site_info = [];

    // WordPress version
    $site_info['wordpress_version'] = sanitize_text_field(get_bloginfo('version'));
    $site_info['php_version'] = sanitize_text_field(phpversion());
    global $wpdb;
    $site_info['mysql_version'] = sanitize_text_field($wpdb->db_version());


    // Active theme
    $active_theme = wp_get_theme();
    $site_info['active_theme'] = [
        'name' => sanitize_text_field($active_theme->get('Name')),
        'version' => sanitize_text_field($active_theme->get('Version')),
        'author' => sanitize_text_field($active_theme->get('Author')),
        'description' => sanitize_text_field($active_theme->get('Description')),
        'theme_uri' => esc_url_raw($active_theme->get('ThemeURI'))  // Use esc_url_raw for URLs intended for storage or redirects
    ];

    // Active plugins
    $active_plugins = get_option('active_plugins');
    $plugins_info = [];
    foreach ($active_plugins as $plugin_path) {
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_path);
        $plugins_info[] = [
            'name' => sanitize_text_field($plugin_data['Name']),
            'version' => sanitize_text_field($plugin_data['Version']),
            'author' => sanitize_text_field($plugin_data['Author']),
            'description' => sanitize_text_field($plugin_data['Description']),
            'plugin_uri' => esc_url_raw($plugin_data['PluginURI'])
        ];
    }
    $site_info['active_plugins'] = $plugins_info;

    // Plugin updates
    $updates = get_site_transient('update_plugins');
    if (!empty($updates->response)) {
        $plugins_needing_updates = [];
        foreach ($updates->response as $plugin_path => $plugin_data) {
            $plugin_info = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_path);
            $plugins_needing_updates[] = [
                'name' => sanitize_text_field($plugin_info['Name']),
                'current_version' => sanitize_text_field($plugin_info['Version']),
                'update_version' => sanitize_text_field($plugin_data->new_version)
            ];
        }
        $site_info['plugins_needing_updates'] = $plugins_needing_updates;
    } else {
        $site_info['plugins_needing_updates'] = false;
    }

    // Inactive themes
    $all_themes = wp_get_themes();
    $inactive_themes = array_diff_key($all_themes, [$active_theme->stylesheet => $active_theme]);
    $inactive_theme_names = array_map(function ($theme) {
        return sanitize_text_field($theme->get('Name'));
    }, $inactive_themes);
    $site_info['inactive_themes'] = array_values($inactive_theme_names);


    // Missing recommended PHP modules
    $recommended_modules = ['curl', 'dom', 'gd', 'json', 'mbstring', 'openssl', 'xml', 'zip', 'imagick'];
    $missing_modules = array_filter($recommended_modules, function ($module) {
        return !extension_loaded($module);
    });
    $site_info['missing_php_modules'] = array_values(array_map('sanitize_text_field', $missing_modules));


    // Persistent object cache
    $site_info['persistent_object_cache'] = sanitize_text_field(wp_using_ext_object_cache() ? 'Enabled' : 'Not enabled. Consider setting up a persistent object cache for better performance.');
    $site_info['page_cache'] = 'Not detected';
    if (function_exists('wp_cache_is_enabled') && wp_cache_is_enabled()) {
        $site_info['page_cache'] = 'Enabled';
    }
    if (is_plugin_active('litespeed-cache/litespeed-cache.php')) {
        $site_info['page_cache'] = 'Enabled (LiteSpeed)';
    }


    // Check for WordPress core updates
    require_once(ABSPATH . 'wp-admin/includes/update.php');
    $core_updates = get_core_updates();
    if (!empty($core_updates) && !is_wp_error($core_updates) && $core_updates[0]->response == 'upgrade') {
        $site_info['wordpress_up_to_date'] = sanitize_text_field('No (Current version: ' . get_bloginfo('version') . ', Latest version: ' . $core_updates[0]->current . ')');
    } else {
        $site_info['wordpress_up_to_date'] = 'Yes';
    }

    // Check scheduled events
    if (false === wp_next_scheduled('wp_version_check')) {
        $site_info['scheduled_events'] = 'Scheduled events not running properly';
    } else {
        $site_info['scheduled_events'] = 'Scheduled events are running';
    }

    // Check file uploads
    $upload_dir = wp_upload_dir();
    $site_info['file_uploads'] = wp_is_writable($upload_dir['basedir']) ? 'Yes' : 'No';

    // Check REST API availability
    $response = wp_remote_get(get_rest_url());
    $site_info['rest_api'] = is_wp_error($response) ? 'REST API encountered an error: ' . sanitize_text_field($response->get_error_message()) : 'Available';

    // Additional Information:

    // Database Charset and Collation
    if (!function_exists('get_charset_collate')) {
        /**
         * Retrieves the database character set and collation.
         *
         * @return string The charset and collation.
         */
        function get_charset_collate()
        {
            global $wpdb;

            $charset_collation = '';

            if (!empty($wpdb->charset)) {
                $charset_collation = 'DEFAULT CHARACTER SET ' . $wpdb->charset;
            }

            if (!empty($wpdb->collate)) {
                $charset_collation .= ' COLLATE ' . $wpdb->collate;
            }

            return $charset_collation;
        }
    }
    $charset_collation = get_charset_collate();
    $site_info['database_charset'] = $charset_collation;

    // WordPress Multisite Information
    $site_info['is_multisite'] = is_multisite() ? 'Yes' : 'No';
    if (is_multisite()) {
        $sites = get_sites();
        $site_info['sites'] = array_map(function($site) {
            return esc_url_raw($site->siteurl); // Assuming the siteurl property exists
        }, $sites);
    }

    // Server Environment Details
    $site_info['server_software'] = sanitize_text_field($_SERVER['SERVER_SOFTWARE']);
    $site_info['server_ip'] = sanitize_text_field($_SERVER['SERVER_ADDR']);
    $site_info['server_name'] = sanitize_text_field($_SERVER['SERVER_NAME']);

    // Server Configuration
    $site_info['php_memory_limit'] = sanitize_text_field(ini_get('memory_limit'));
    $site_info['max_upload_size'] = sanitize_text_field(ini_get('upload_max_filesize'));
    $site_info['max_post_size'] = sanitize_text_field(ini_get('post_max_size'));
    $site_info['max_input_time'] = sanitize_text_field(ini_get('max_input_time'));
    $site_info['php_max_execution_time'] = sanitize_text_field(ini_get('max_execution_time'));

    // Security Headers
    $site_info['content_security_policy'] = sanitize_text_field($_SERVER['HTTP_CONTENT_SECURITY_POLICY'] ?? 'Not Set');
    $site_info['strict_transport_security'] = sanitize_text_field($_SERVER['HTTP_STRICT_TRANSPORT_SECURITY'] ?? 'Not Set');
    $site_info['x_content_type_options'] = sanitize_text_field($_SERVER['HTTP_X_CONTENT_TYPE_OPTIONS'] ?? 'Not Set');
    $site_info['x_frame_options'] = sanitize_text_field($_SERVER['HTTP_X_FRAME_OPTIONS'] ?? 'Not Set');
    $site_info['x_xss_protection'] = sanitize_text_field($_SERVER['HTTP_X_XSS_PROTECTION'] ?? 'Not Set');

    // WordPress Environment Configuration
    $site_info['debug_mode'] = defined('WP_DEBUG') && WP_DEBUG ? 'Enabled' : 'Disabled';
    $site_info['debug_log'] = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG ? 'Enabled' : 'Disabled';

    // Check HTTPS connection and SSL certificate
    $site_info['has_https'] = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'Yes' : 'No';
    $site_info['ssl_certificate'] = is_ssl() ? 'Yes' : 'No';

    // Check if the permalink structure includes the post name
    $permalink_structure = get_option('permalink_structure');
    $site_info['permalink_structure'] = strpos($permalink_structure, '%postname%') !== false ? 'Yes' : 'No';

    $default_tagline = 'Just another WordPress site';
    $current_tagline = get_bloginfo('description');
    $site_info['changed_tagline'] = sanitize_text_field($current_tagline !== $default_tagline ? 'Yes' : 'No');


    // Check if the site can communicate with WordPress.org
    $response = wp_remote_get('https://api.wordpress.org/');
    $site_info['can_communicate_with_wp_org'] = !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200 ? 'Yes' : 'No';

    // SEO Information
    // Meta Tags
    $html_content_test = wp_remote_get(get_site_url());
    $html_content = wp_remote_retrieve_body($html_content_test);

// Get title from <title> tag
    preg_match("/<title>(.*?)<\/title>/", $html_content, $matches_title);
    $meta_title = isset($matches_title[1]) ? sanitize_text_field($matches_title[1]) : '';

// Get description from <meta> tag
    preg_match('/<meta\s+name="description"\s+content="([^"]*)"/i', $html_content, $matches_description);
    $meta_description = isset($matches_description[1]) ? sanitize_text_field($matches_description[1]) : '';

    $site_info['meta_tags'] = [
        'title' => $meta_title,
        'description' => $meta_description
    ];

    // Get Open Graph tags
    $open_graph_tags_present = preg_match('/<meta\s+property="og:/i', $html_content);
    $site_info['open_graph_tags'] = $open_graph_tags_present ? 'Present' : 'Not present';

    // Get structured data markup
    $structured_data_present = preg_match('/<script type="application\/ld\+json">/i', $html_content);
    $site_info['structured_data'] = $structured_data_present ? 'Implemented' : 'Not implemented';

    // Get canonical URL
    preg_match('/<link\s+rel="canonical"\s+href="([^"]*)"/i', $html_content, $matches_canonical);
    $canonical_url = isset($matches_canonical[1]) ? esc_url($matches_canonical[1]) : '';
    $site_info['canonical_url'] = $canonical_url ?: 'Not set';


    function get_cpu_usage()
    {
        // Execute top command and capture its output
        $top_output = shell_exec('top -bn 1');

        // Use regular expressions to extract CPU usage from the output
        preg_match('/%Cpu\(s\):\s+(\d+\.\d+)\s+us/', $top_output, $matches);

        // Extract CPU usage percentage
        $cpu_usage = isset($matches[1]) ? floatval($matches[1]) : null;

        return $cpu_usage;
    }

    $site_info['cpu_usage'] = get_cpu_usage();

    // Check for critical errors or alerts
    $health_check_results = get_option('health_check_debug_mode');
    $site_info['critical_errors_or_alerts'] = !empty($health_check_results['fatal_errors']) ? 'Yes' : 'No';


    // Check for Google Analytics integration
    $google_analytics_integration = strpos($html_content, 'google-analytics.com/analytics.js') !== false
        || strpos($html_content, 'https://www.google-analytics.com/analytics.js') !== false
        || strpos($html_content, 'https://www.googletagmanager.com/gtag/js') !== false;
    $site_info['google_analytics_integration'] = $google_analytics_integration ? 'Enabled' : 'Not enabled';


    // Check if robots.txt exists and properly configured
    $robots_txt_url = get_site_url() . '/robots.txt';
    $robots_txt_response = wp_remote_get($robots_txt_url);
    $site_info['robots_txt_exists'] = !is_wp_error($robots_txt_response) && wp_remote_retrieve_response_code($robots_txt_response) === 200 ? 'Yes' : 'No';

    // Check if XML sitemap is available and accessible
    $xml_sitemap_url = get_site_url() . '/sitemap.xml';
    $xml_sitemap_response = wp_remote_get($xml_sitemap_url);
    $site_info['xml_sitemap_exists'] = !is_wp_error($xml_sitemap_response) && wp_remote_retrieve_response_code($xml_sitemap_response) === 200 ? 'Yes' : 'No';

    return new WP_REST_Response($site_info, 200);
}

