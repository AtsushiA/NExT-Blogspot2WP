<?php
/**
 * Integration tests for the plugin bootstrapping.
 *
 * @package NExT_Blogspot2WP
 */

namespace NExT\Blogspot2WP\Tests\Integration;

use WP_UnitTestCase;

/**
 * Verify the plugin loads correctly inside a real WordPress environment.
 */
class PluginTest extends WP_UnitTestCase {

	/**
	 * The plugin version constant should be defined once loaded.
	 */
	public function test_version_constant_is_defined(): void {
		$this->assertTrue( defined( 'NEXT_BLOGSPOT2WP_VERSION' ) );
	}

	/**
	 * Core classes should be available after bootstrap.
	 */
	public function test_core_classes_exist(): void {
		$this->assertTrue( class_exists( 'NExT_Blogspot2WP_Converter' ) );
		$this->assertTrue( class_exists( 'NExT_Blogspot2WP_Image' ) );
		$this->assertTrue( class_exists( 'NExT_Blogspot2WP_Importer' ) );
		$this->assertTrue( class_exists( 'NExT_Blogspot2WP_Blogger_Feed' ) );
	}

	/**
	 * The converter should turn a heading into a core/heading block using the real WP runtime.
	 */
	public function test_converter_outputs_heading_block(): void {
		$image     = $this->getMockBuilder( \NExT_Blogspot2WP_Image::class )
			->disableOriginalConstructor()
			->getMock();
		$converter = new \NExT_Blogspot2WP_Converter( $image );

		$result = $converter->convert( '<h2>Hello</h2>' );

		$this->assertStringContainsString( 'wp:core/heading', $result );
		$this->assertStringContainsString( 'Hello', $result );
	}
}
