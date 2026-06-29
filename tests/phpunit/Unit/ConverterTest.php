<?php
/**
 * Unit tests for NExT_Blogspot2WP_Converter.
 *
 * @package NExT_Blogspot2WP
 */

namespace NExT\Blogspot2WP\Tests\Unit;

use Brain\Monkey\Functions;
use NExT_Blogspot2WP_Converter;
use NExT_Blogspot2WP_Image;
use Yoast\WPTestUtils\BrainMonkey\TestCase;

/**
 * @coversDefaultClass \NExT_Blogspot2WP_Converter
 */
final class ConverterTest extends TestCase {

	/**
	 * Set up WordPress function stubs used by the converter.
	 */
	protected function set_up(): void {
		parent::set_up();

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
	}

	/**
	 * Create a converter with a mocked image handler.
	 */
	private function make_converter(): NExT_Blogspot2WP_Converter {
		$image = $this->createMock( NExT_Blogspot2WP_Image::class );
		return new NExT_Blogspot2WP_Converter( $image );
	}

	/**
	 * @covers ::convert
	 */
	public function test_returns_empty_string_for_empty_html(): void {
		$this->assertSame( '', $this->make_converter()->convert( '' ) );
	}

	/**
	 * @covers ::convert
	 */
	public function test_converts_heading_to_core_heading_block(): void {
		$result = $this->make_converter()->convert( '<h2>Hello World</h2>' );

		$this->assertStringContainsString( '<!-- wp:core/heading', $result );
		$this->assertStringContainsString( 'wp-block-heading', $result );
		$this->assertStringContainsString( 'Hello World', $result );
	}

	/**
	 * @covers ::convert
	 */
	public function test_converts_paragraph_to_core_paragraph_block(): void {
		$result = $this->make_converter()->convert( '<p>Some text</p>' );

		$this->assertStringContainsString( '<!-- wp:core/paragraph', $result );
		$this->assertStringContainsString( 'Some text', $result );
	}

	/**
	 * @covers ::convert
	 */
	public function test_converts_unordered_list_to_core_list_block(): void {
		$result = $this->make_converter()->convert( '<ul><li>one</li><li>two</li></ul>' );

		$this->assertStringContainsString( '<!-- wp:core/list', $result );
		$this->assertStringContainsString( '<li>one</li>', $result );
		$this->assertStringContainsString( '<li>two</li>', $result );
	}
}
