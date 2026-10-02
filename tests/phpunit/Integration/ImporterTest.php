<?php
/**
 * NExT_Blogspot2WP_Importer の統合テスト
 *
 * @package NExT_Blogspot2WP
 */

namespace NExT\Blogspot2WP\Tests\Integration;

use WP_UnitTestCase;

/**
 * 実際の WordPress 環境で投稿インポート処理を検証する。
 */
class ImporterTest extends WP_UnitTestCase {

	/**
	 * テスト対象のインポーターを生成する。
	 *
	 * 画像処理はモック化し、外部通信が発生しないようにする。
	 *
	 * @return \NExT_Blogspot2WP_Importer
	 */
	private function create_importer() {
		// 画像ハンドラーはコンストラクタを呼ばずにモック化する。
		$image = $this->getMockBuilder( \NExT_Blogspot2WP_Image::class )
			->disableOriginalConstructor()
			->getMock();

		return new \NExT_Blogspot2WP_Importer( $image, new \NExT_Blogspot2WP_Converter( $image ) );
	}

	/**
	 * テスト用の Blogger 記事データを組み立てる。
	 *
	 * @param string $blogger_id Blogger 記事 ID
	 * @param string $url        Blogger 記事 URL
	 * @param string $title      記事タイトル
	 * @return array 正規化済み Blogger 記事データ
	 */
	private function build_blogger_post( $blogger_id, $url, $title ) {
		return array(
			'id'          => $blogger_id,
			'title'       => $title,
			'content'     => '<p>' . $title . '</p>',
			'published'   => '2016-03-09T10:00:00.000+09:00',
			'updated'     => '2016-03-09T10:00:00.000+09:00',
			'link'        => $url,
			'slug'        => 'blog-post',
			'labels'      => array(),
			'cover_image' => '',
			'author_name' => '',
		);
	}

	/**
	 * import_post() の重複判定（Blogger ID / スラッグ）を検証する。
	 */
	public function test_import_post() {
		// 2026/08/blog-post.html として取り込み済みの Blogger 記事。
		$existing_blogger = $this->build_blogger_post(
			'1111.post-1',
			'https://example.blogspot.com/2026/08/blog-post.html',
			'新しい記事'
		);

		// 2016/03/blog-post.html（スラッグは同じだが別の Blogger 記事）。
		$other_blogger = $this->build_blogger_post(
			'1111.post-2',
			'https://example.blogspot.com/2016/03/blog-post.html',
			'古い記事'
		);

		$test_cases = array(
			array(
				'test_condition_name' => '既存投稿なしで新規記事を取り込む場合 => imported',
				'conditions'          => array(
					'existing_blogger_posts' => array(),
					'existing_manual_slug'   => '',
					'post'                   => $existing_blogger,
					'options'                => array( 'skip_images' => true ),
				),
				'expected'            => array(
					'result'           => 'imported',
					'is_existing_post' => false,
					'post_count'       => 1,
				),
			),
			array(
				'test_condition_name' => '同じ Blogger ID の記事を再取り込みする場合 => skipped（既存投稿）',
				'conditions'          => array(
					'existing_blogger_posts' => array( $existing_blogger ),
					'existing_manual_slug'   => '',
					'post'                   => $existing_blogger,
					'options'                => array( 'skip_images' => true ),
				),
				'expected'            => array(
					'result'           => 'skipped',
					'is_existing_post' => true,
					'post_count'       => 1,
				),
			),
			array(
				'test_condition_name' => '別の Blogger 記事とスラッグだけが同じ場合 => imported（別投稿として追加）',
				'conditions'          => array(
					'existing_blogger_posts' => array( $existing_blogger ),
					'existing_manual_slug'   => '',
					'post'                   => $other_blogger,
					'options'                => array( 'skip_images' => true ),
				),
				'expected'            => array(
					'result'           => 'imported',
					'is_existing_post' => false,
					'post_count'       => 2,
				),
			),
			array(
				'test_condition_name' => '別の Blogger 記事とスラッグだけが同じで --force 指定の場合 => imported（既存投稿を上書きしない）',
				'conditions'          => array(
					'existing_blogger_posts' => array( $existing_blogger ),
					'existing_manual_slug'   => '',
					'post'                   => $other_blogger,
					'options'                => array(
						'skip_images' => true,
						'force'       => true,
					),
				),
				'expected'            => array(
					'result'           => 'imported',
					'is_existing_post' => false,
					'post_count'       => 2,
				),
			),
			array(
				'test_condition_name' => 'Blogger 以外で作成された同スラッグの投稿がある場合 => skipped（既存投稿）',
				'conditions'          => array(
					'existing_blogger_posts' => array(),
					'existing_manual_slug'   => 'blog-post',
					'post'                   => $other_blogger,
					'options'                => array( 'skip_images' => true ),
				),
				'expected'            => array(
					'result'           => 'skipped',
					'is_existing_post' => true,
					'post_count'       => 1,
				),
			),
		);

		foreach ( $test_cases as $case ) {
			// 既存の Blogger 由来投稿を事前に取り込む。
			$existing_ids = array();
			$setup        = $this->create_importer();
			foreach ( $case['conditions']['existing_blogger_posts'] as $blogger_post ) {
				$setup_result   = $setup->import_post( $blogger_post, array( 'skip_images' => true ) );
				$existing_ids[] = $setup_result['post_id'];
			}

			// Blogger 以外で作成された投稿（_blogger_post_id なし）を作成する。
			if ( $case['conditions']['existing_manual_slug'] ) {
				$existing_ids[] = self::factory()->post->create(
					array( 'post_name' => $case['conditions']['existing_manual_slug'] )
				);
			}

			// 既存投稿の内容を控えておき、上書きされていないか確認する。
			$existing_titles = array();
			foreach ( $existing_ids as $existing_id ) {
				$existing_titles[ $existing_id ] = get_the_title( $existing_id );
			}

			// テスト対象メソッドを実行する。
			$actual = $this->create_importer()->import_post( $case['conditions']['post'], $case['conditions']['options'] );

			// 結果種別を検証する。
			$this->assertSame( $case['expected']['result'], $actual['result'], $case['test_condition_name'] );

			// 返された投稿 ID が既存投稿かどうかを検証する。
			$this->assertSame(
				$case['expected']['is_existing_post'],
				in_array( $actual['post_id'], $existing_ids, true ),
				$case['test_condition_name']
			);

			// 投稿数を検証する（スキップ時は増えず、取り込み時は1件増える）。
			$all_ids = get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);
			$this->assertCount( $case['expected']['post_count'], $all_ids, $case['test_condition_name'] );

			// 既存投稿のタイトルが変わっていない（上書きされていない）ことを検証する。
			foreach ( $existing_titles as $existing_id => $existing_title ) {
				$this->assertSame( $existing_title, get_the_title( $existing_id ), $case['test_condition_name'] );
			}

			// 次のケースに影響しないよう投稿を削除する。
			foreach ( $all_ids as $post_id ) {
				wp_delete_post( $post_id, true );
			}
		}
	}
}
