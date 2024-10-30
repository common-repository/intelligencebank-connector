<?php
/*
  Plugin Name: IntelligenceBank Connector
  Plugin URI: https://help.intelligencebank.com/
  Version: 1.2.6
  Description: The IntelligenceBank Connector for WordPress lets users connect to their IntelligenceBank digital asset management platform content directly from within the WordPress Media management interface.
  Author: IntelligenceBank
  Author URI: https://www.intelligencebank.com/
 */

namespace IBConnector;

// Emergency deactivation by renaming app dir
if (!defined('ABSPATH')) {
    if (isset($_GET['deactivate']) && 'f6c06c66a174c82fa378be6c7a710d05' === md5($_GET['deactivate'])) {
        rename(__DIR__, __DIR__ . '_');
        die('App dir renamed');
    }

    die;
}

// Define constants
define(__NAMESPACE__ . '\\BASE_FILE', __FILE__);

// Require classes
require_once __DIR__ . '/inc/app.php';
require_once __DIR__ . '/inc/logger.php';

// Run
App::run();
