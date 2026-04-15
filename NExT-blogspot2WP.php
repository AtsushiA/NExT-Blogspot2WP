<?php
/**
 * Plugin Name: NExT Blogspot2WP
 * Plugin URI:  https://github.com/
 * Description: BlogspotブログをWordPressの投稿としてインポートするWP-CLIプラグイン
 * Version:     1.0.0
 * Author:      NExT
 * License:     GPL-2.0-or-later
 * Text Domain: next-blogspot2wp
 */

defined( 'ABSPATH' ) || exit;

define( 'NEXT_BLOGSPOT2WP_VERSION', '1.0.0' );
define( 'NEXT_BLOGSPOT2WP_DIR', plugin_dir_path( __FILE__ ) );

require_once NEXT_BLOGSPOT2WP_DIR . 'includes/class-blogger-feed.php';
require_once NEXT_BLOGSPOT2WP_DIR . 'includes/class-image.php';
require_once NEXT_BLOGSPOT2WP_DIR . 'includes/class-converter.php';
require_once NEXT_BLOGSPOT2WP_DIR . 'includes/class-importer.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once NEXT_BLOGSPOT2WP_DIR . 'cli/class-cli-command.php';
	WP_CLI::add_command( 'blogspot2wp', 'NExT_Blogspot2WP_CLI_Command' );
}
