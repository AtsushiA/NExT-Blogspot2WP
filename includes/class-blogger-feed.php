<?php
defined( 'ABSPATH' ) || exit;

/**
 * Blogger Atom Feed クライアント
 *
 * Blogger（Google）の Atom Feed（JSON形式）から記事データを取得する。
 * APIキー不要。ページネーションに対応。
 *
 * フィードエンドポイント:
 *   GET https://{domain}/feeds/posts/default?alt=json&start-index=1&max-results=25
 */
class NExT_Blogspot2WP_Blogger_Feed {

	/** @var string ブログの URL（例: https://example.blogspot.com/ ） */
	private $blog_url;

	/** @var int 1回のリクエストで取得する記事数（最大150） */
	private $batch_size;

	/**
	 * @param string $blog_url   ブログの URL
	 * @param int    $batch_size 1回の取得件数
	 */
	public function __construct( $blog_url, $batch_size = 25 ) {
		$this->blog_url   = rtrim( $blog_url, '/' );
		$this->batch_size = min( (int) $batch_size, 150 );
	}

	/**
	 * 全記事を取得する。
	 *
	 * @param int $limit 最大取得件数（0 = 全件）
	 * @return array|WP_Error 正規化された記事データの配列、またはエラー
	 */
	public function get_all_posts( $limit = 0 ) {
		$all_posts   = array();
		$start_index = 1;
		$total       = null;

		do {
			$result = $this->fetch_page( $start_index );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$posts = $result['posts'];
			if ( null === $total ) {
				$total = $result['total'];
			}

			foreach ( $posts as $post ) {
				$all_posts[] = $post;

				if ( $limit > 0 && count( $all_posts ) >= $limit ) {
					return $all_posts;
				}
			}

			$start_index += count( $posts );

			// レート制限対策
			usleep( 200000 ); // 200ms

		} while ( count( $posts ) > 0 && $start_index <= $total );

		return $all_posts;
	}

	/**
	 * 1ページ分の記事を取得する。
	 *
	 * @param int $start_index 取得開始インデックス（1始まり）
	 * @return array|WP_Error { 'posts' => array, 'total' => int }
	 */
	private function fetch_page( $start_index ) {
		$feed_url = $this->build_feed_url( $start_index );

		$response = wp_remote_get(
			$feed_url,
			array(
				'timeout' => 30,
				'headers' => array(
					'User-Agent' => 'Mozilla/5.0 (compatible; NExT-Blogspot2WP/1.0)',
					'Accept'     => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'feed_fetch_failed',
				'Blogger Feed の取得に失敗しました: ' . $response->get_error_message()
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code !== 200 ) {
			return new WP_Error(
				'feed_http_error',
				sprintf( 'Blogger Feed が HTTP %d を返しました。URL: %s', $status_code, $feed_url )
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! $data || ! isset( $data['feed'] ) ) {
			return new WP_Error(
				'feed_parse_error',
				'Blogger Feed のレスポンスを解析できませんでした。'
			);
		}

		$feed  = $data['feed'];
		$total = isset( $feed['openSearch$totalResults']['$t'] )
			? (int) $feed['openSearch$totalResults']['$t']
			: 0;

		$entries = isset( $feed['entry'] ) ? $feed['entry'] : array();
		$posts   = array();

		foreach ( $entries as $entry ) {
			$normalized = $this->normalize_entry( $entry );
			if ( $normalized ) {
				$posts[] = $normalized;
			}
		}

		return array(
			'posts' => $posts,
			'total' => $total,
		);
	}

	/**
	 * Blogger Atom Feed の entry を内部形式に正規化する。
	 *
	 * @param array $entry  Atom Feed の entry オブジェクト
	 * @return array|null
	 */
	private function normalize_entry( $entry ) {
		// ID: "tag:blogger.com,1999:blog-12345.post-67890" → "blog-12345.post-67890"
		$raw_id = isset( $entry['id']['$t'] ) ? $entry['id']['$t'] : '';
		$id     = $this->extract_post_id( $raw_id );

		if ( ! $id ) {
			return null;
		}

		$title   = isset( $entry['title']['$t'] ) ? $entry['title']['$t'] : '(無題)';
		$content = isset( $entry['content']['$t'] ) ? $entry['content']['$t'] : '';

		// 公開日・更新日
		$published = isset( $entry['published']['$t'] ) ? $entry['published']['$t'] : '';
		$updated   = isset( $entry['updated']['$t'] ) ? $entry['updated']['$t'] : $published;

		// 記事 URL とスラッグ
		$link = $this->extract_link( $entry, 'alternate' );
		$slug = $this->extract_slug( $link );

		// ラベル（カテゴリー）
		$labels = $this->extract_labels( $entry );

		// カバー画像: media$thumbnail または本文内最初の img
		$cover_image = '';
		if ( ! empty( $entry['media$thumbnail']['url'] ) ) {
			$cover_image = $this->normalize_image_url( $entry['media$thumbnail']['url'] );
		}

		// 著者
		$author_name = '';
		if ( ! empty( $entry['author'][0]['name']['$t'] ) ) {
			$author_name = $entry['author'][0]['name']['$t'];
		}

		return array(
			'id'           => $id,
			'title'        => $title,
			'content'      => $content,
			'published'    => $published,
			'updated'      => $updated,
			'link'         => $link,
			'slug'         => $slug,
			'labels'       => $labels,
			'cover_image'  => $cover_image,
			'author_name'  => $author_name,
		);
	}

	// -------------------------------------------------------------------------
	// ヘルパー
	// -------------------------------------------------------------------------

	/**
	 * Atom Feed URL を組み立てる。
	 *
	 * @param int $start_index
	 * @return string
	 */
	private function build_feed_url( $start_index ) {
		return add_query_arg(
			array(
				'alt'         => 'json',
				'start-index' => $start_index,
				'max-results' => $this->batch_size,
				'orderby'     => 'published',
			),
			$this->blog_url . '/feeds/posts/default'
		);
	}

	/**
	 * Blogger の entry ID（tag URI）から識別子を抽出する。
	 *
	 * 例: "tag:blogger.com,1999:blog-12345.post-67890" → "blog-12345.post-67890"
	 *
	 * @param string $raw_id
	 * @return string
	 */
	private function extract_post_id( $raw_id ) {
		if ( preg_match( '/(\d+\.post-\d+)$/', $raw_id, $m ) ) {
			return $m[1];
		}
		return $raw_id;
	}

	/**
	 * entry の links から rel に一致するものを返す。
	 *
	 * @param array  $entry
	 * @param string $rel
	 * @return string
	 */
	private function extract_link( $entry, $rel ) {
		if ( empty( $entry['link'] ) || ! is_array( $entry['link'] ) ) {
			return '';
		}
		foreach ( $entry['link'] as $link ) {
			if ( isset( $link['rel'] ) && $link['rel'] === $rel && ! empty( $link['href'] ) ) {
				return $link['href'];
			}
		}
		return '';
	}

	/**
	 * Blogger の記事 URL からスラッグを抽出する。
	 *
	 * 例: https://example.blogspot.com/2023/08/my-post.html → my-post
	 *
	 * @param string $url
	 * @return string
	 */
	private function extract_slug( $url ) {
		if ( ! $url ) {
			return '';
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		$slug = basename( $path, '.html' );
		return sanitize_title( $slug );
	}

	/**
	 * entry からラベル（カテゴリー）を抽出する。
	 *
	 * @param array $entry
	 * @return array ラベル名の配列
	 */
	private function extract_labels( $entry ) {
		if ( empty( $entry['category'] ) || ! is_array( $entry['category'] ) ) {
			return array();
		}
		$labels = array();
		foreach ( $entry['category'] as $cat ) {
			if ( ! empty( $cat['term'] ) ) {
				$labels[] = $cat['term'];
			}
		}
		return $labels;
	}

	/**
	 * Blogger 画像 URL のサイズパラメータを /s0/（オリジナル）に変換する。
	 *
	 * 例: https://1.bp.blogspot.com/-xxx/s400/image.jpg
	 *   → https://1.bp.blogspot.com/-xxx/s0/image.jpg
	 *
	 * @param string $url
	 * @return string
	 */
	public function normalize_image_url( $url ) {
		// /s\d+/ または /s\d+-\w+/ 形式のサイズパラメータを /s0/ に変換
		$url = preg_replace( '#/s\d+(-[a-z]+)?/#', '/s0/', $url );
		// クエリパラメータを除去
		$url = strtok( $url, '?' );
		return $url;
	}
}
