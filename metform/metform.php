<?php
/**
 * Plugin Name: MetForm
 * Plugin URI: http://wpmet.com/plugin/metform/
 * Description: Most flexible and design friendly form builder for Elementor
 * Version: 4.0.3
 * Author: Wpmet
 * Author URI:  https://wpmet.com
 * Text Domain: metform
 * Domain Path: /languages
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.txt
 */

defined( 'ABSPATH' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'utils/notice/notice.php';
require_once plugin_dir_path( __FILE__ ) . 'utils/banner/banner.php';
require_once plugin_dir_path( __FILE__ ) . 'utils/apps/apps.php';
require_once plugin_dir_path( __FILE__ ) . 'utils/emailkit/emailkit.php';
require_once plugin_dir_path( __FILE__ ) . 'utils/stories/stories.php';
require_once plugin_dir_path( __FILE__ ) . 'utils/pro-awareness/pro-awareness.php';
require_once plugin_dir_path( __FILE__ ) . 'utils/rating/rating.php';

require plugin_dir_path( __FILE__ ) .'autoloader.php';
require plugin_dir_path( __FILE__ ) .'plugin.php';

// init notice class
\Oxaim\Libs\Notice::init();
// \Wpmet\Rating\Rating::init();
\Wpmet\Libs\Pro_Awareness::init();


register_activation_hook( __FILE__, [ MetForm\Plugin::instance(), 'flush_rewrites'] );

add_action( 'plugins_loaded', function(){
    do_action('metform/before_load');
    MetForm\Plugin::instance()->init();
    do_action('metform/after_load');
}, 111);


// Added Date: 20/07/2022
add_action('plugins_loaded', function(){
    if(class_exists('MetForm_Pro\Core\Integrations\Crm\Hubspot\Integration')){
        return;
    }
    require trailingslashit(plugin_dir_path(__FILE__)) . "core/integrations/crm/hubspot/loader.php";
}, 222);