<?php
/**
 * WP-CLI コマンドクラス
 *
 * @package NExT_Blogspot2WP
 */

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI コマンド定義
 *
 * 使い方:
 *   wp blogspot2wp import --blog-url=https://example.blogspot.com/
 */
class NExT_Blogspot2WP_CLI_Command {

	/**
	 * Blogger ブログを WordPress にインポートする。
	 *
	 * ## OPTIONS
	 *
	 * --blog-url=<url>
	 * : Blogger ブログの URL（例: https://example.blogspot.com/）
	 *
	 * [--limit=<number>]
	 * : インポートする最大記事数（0 = 全件）
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--dry-run]
	 * : 実際にはインポートせず、処理内容を表示のみ
	 *
	 * [--force]
	 * : インポート済みの記事を上書き更新する
	 *
	 * [--skip-images]
	 * : 画像のダウンロード・インポートをスキップする
	 *
	 * [--post-status=<status>]
	 * : インポート後の投稿ステータス
	 * ---
	 * default: publish
	 * options:
	 *   - publish
	 *   - draft
	 *   - private
	 * ---
	 *
	 * [--author=<user_id>]
	 * : 投稿者のユーザー ID
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--labels-as=<taxonomy>]
	 * : Blogger ラベルの取り込み先（category または tag）
	 * ---
	 * default: category
	 * options:
	 *   - category
	 *   - tag
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *   # 全記事をインポート
	 *   wp blogspot2wp import --blog-url=https://example.blogspot.com/
	 *
	 *   # 最初の 5 件だけドライラン
	 *   wp blogspot2wp import --blog-url=https://example.blogspot.com/ --limit=5 --dry-run
	 *
	 *   # 画像スキップして下書きとしてインポート
	 *   wp blogspot2wp import --blog-url=https://example.blogspot.com/ --skip-images --post-status=draft
	 *
	 *   # ラベルをタグとしてインポート
	 *   wp blogspot2wp import --blog-url=https://example.blogspot.com/ --labels-as=tag
	 *
	 *   # 既存記事を強制上書き
	 *   wp blogspot2wp import --blog-url=https://example.blogspot.com/ --force
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       位置引数
	 * @param array $assoc_args キー付き引数
	 */
	public function import( $args, $assoc_args ) {
		$blog_url    = WP_CLI\Utils\get_flag_value( $assoc_args, 'blog-url', '' );
		$limit       = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 0 );
		$dry_run     = WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$force       = WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$skip_images = WP_CLI\Utils\get_flag_value( $assoc_args, 'skip-images', false );
		$post_status = WP_CLI\Utils\get_flag_value( $assoc_args, 'post-status', 'publish' );
		$author      = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'author', 1 );
		$labels_as   = WP_CLI\Utils\get_flag_value( $assoc_args, 'labels-as', 'category' );

		if ( ! $blog_url ) {
			WP_CLI::error( '--blog-url は必須です。例: --blog-url=https://example.blogspot.com/' );
		}

		if ( ! wp_http_validate_url( $blog_url ) ) {
			WP_CLI::error( '--blog-url に有効な URL を指定してください。' );
		}

		$allowed_statuses = array( 'publish', 'draft', 'private', 'pending' );
		if ( ! in_array( $post_status, $allowed_statuses, true ) ) {
			WP_CLI::error( '--post-status には publish / draft / private / pending のいずれかを指定してください。' );
		}

		if ( ! in_array( $labels_as, array( 'category', 'tag' ), true ) ) {
			WP_CLI::error( '--labels-as には "category" または "tag" を指定してください。' );
		}

		if ( $dry_run ) {
			WP_CLI::line( '[DRY RUN モード] 実際のインポートは行いません。' );
		}

		// タイムアウト無効化（大量インポート対策）
		set_time_limit( 0 );

		// ---- 1. 記事一覧取得 ------------------------------------------------
		WP_CLI::line( 'Blogger Feed から記事を取得しています: ' . $blog_url );

		$feed  = new NExT_Blogspot2WP_Blogger_Feed( $blog_url, 25 );
		$posts = $feed->get_all_posts( $limit );

		if ( is_wp_error( $posts ) ) {
			WP_CLI::error( '記事の取得に失敗しました: ' . $posts->get_error_message() );
		}

		$total = count( $posts );
		WP_CLI::line( sprintf( '%d 件の記事が見つかりました。', $total ) );

		if ( 0 === $total ) {
			WP_CLI::success( 'インポートする記事がありません。' );
			return;
		}

		// ---- 2. インポート処理 -----------------------------------------------
		$image_handler = new NExT_Blogspot2WP_Image();
		$converter     = new NExT_Blogspot2WP_Converter( $image_handler );
		$importer      = new NExT_Blogspot2WP_Importer( $image_handler, $converter );

		$options = array(
			'force'       => (bool) $force,
			'skip_images' => (bool) $skip_images,
			'post_status' => $post_status,
			'author'      => $author,
			'dry_run'     => (bool) $dry_run,
			'labels_as'   => $labels_as,
		);

		$progress = WP_CLI\Utils\make_progress_bar( 'インポート中', $total );

		foreach ( $posts as $i => $blogger_post ) {
			$title = isset( $blogger_post['title'] ) ? $blogger_post['title'] : '(無題)';
			$date  = isset( $blogger_post['published'] )
				? substr( $blogger_post['published'], 0, 10 )
				: '';

			$result = $importer->import_post( $blogger_post, $options );

			switch ( $result['result'] ) {
				case 'imported':
					WP_CLI::debug( sprintf(
						'[%d/%d] インポート完了: "%s" (%s) → post_id=%d',
						$i + 1, $total, $title, $date, $result['post_id']
					) );
					break;
				case 'updated':
					WP_CLI::debug( sprintf(
						'[%d/%d] 更新完了: "%s" (%s) → post_id=%d',
						$i + 1, $total, $title, $date, $result['post_id']
					) );
					break;
				case 'skipped':
					WP_CLI::debug( sprintf( '[%d/%d] スキップ: "%s"', $i + 1, $total, $title ) );
					break;
				case 'error':
					WP_CLI::warning( sprintf(
						'[%d/%d] エラー: "%s" — %s',
						$i + 1, $total, $title, $result['message']
					) );
					break;
				case 'dry_run':
					WP_CLI::line( $result['message'] );
					break;
			}

			$progress->tick();
		}

		$progress->finish();

		// ---- 3. サマリー表示 ------------------------------------------------
		$stats = $importer->get_stats();

		WP_CLI::line( '' );
		WP_CLI::line( '===== インポート完了 =====' );
		WP_CLI::line( sprintf( '  インポート: %d', $stats['imported'] ) );
		WP_CLI::line( sprintf( '  更新:       %d', $stats['updated'] ) );
		WP_CLI::line( sprintf( '  スキップ:   %d', $stats['skipped'] ) );
		WP_CLI::line( sprintf( '  エラー:     %d', $stats['errors'] ) );

		if ( $stats['errors'] > 0 ) {
			WP_CLI::warning( 'エラーが発生した記事があります。--debug フラグで詳細を確認してください。' );
		} else {
			WP_CLI::success( 'すべての処理が完了しました。' );
		}
	}
}
