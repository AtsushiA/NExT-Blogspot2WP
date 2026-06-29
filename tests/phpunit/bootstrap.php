<?php
/**
 * PHPUnit bootstrap file for Integration tests.
 *
 * WordPress テスト環境をロードする bootstrap。wp-env / CI 上で `--bootstrap` 指定で使う。
 *
 * @package NExT_Blogspot2WP
 */

// Composer autoloader.
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

// Yoast PHPUnit Polyfills のパスを WordPress テストスイートに伝える。
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// WordPress テストライブラリの読み込み.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * テスト対象プラグインを手動で読み込む.
 */
function _manually_load_plugin() {
	require dirname( __DIR__, 2 ) . '/NExT-blogspot2WP.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// WordPress テスト環境を起動.
require "{$_tests_dir}/includes/bootstrap.php";
