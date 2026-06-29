<?php
/**
 * PHPUnit bootstrap file for Unit tests.
 *
 * WordPress をロードしない軽量 bootstrap。Brain\Monkey で WP 関数をモックする。
 *
 * @package NExT_Blogspot2WP
 */

require_once dirname( __DIR__, 2 ) . '/../vendor/autoload.php';

/*
 * プラグインのクラスファイルは先頭で `defined( 'ABSPATH' ) || exit;` を実行する。
 * Composer classmap オートロードでロードした際に exit しないよう、ダミーの ABSPATH を定義する。
 */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );
}
