<?php
defined( 'ABSPATH' ) || exit;

/**
 * 画像ダウンロード・メディアライブラリ登録
 *
 * - Blogger の画像を WordPress メディアとしてインポートする
 * - 投稿日の年/月ディレクトリに保存する
 * - 同一画像の重複インポートを防ぐ（_blogger_original_url メタで照合）
 */
class NExT_Blogspot2WP_Image {

	/**
	 * Blogger 画像 URL を WordPress メディアライブラリに登録し、アタッチメント ID を返す。
	 *
	 * @param string $blogger_url  Blogger 画像 URL
	 * @param int    $post_id      紐付ける投稿 ID（0 の場合は紐付けなし）
	 * @param string $post_date    投稿日（Y-m-d H:i:s）— uploads/年/月 の決定に使う
	 * @return int|WP_Error アタッチメント ID またはエラー
	 */
	public function import( $blogger_url, $post_id = 0, $post_date = '' ) {
		$original_url = $this->normalize_url( $blogger_url );

		// 重複チェック
		$existing_id = $this->find_by_original_url( $original_url );
		if ( $existing_id ) {
			return $existing_id;
		}

		// uploads の年/月を投稿日で上書きするために filter を使う
		if ( $post_date ) {
			$this->set_upload_date_filter( $post_date );
		}

		// ダウンロード & 登録
		$attachment_id = $this->sideload( $original_url, $post_id );

		// filter を解除
		remove_filter( 'upload_dir', array( $this, 'upload_dir_filter' ) );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// 元 URL をメタに保存（重複防止用）
		update_post_meta( $attachment_id, '_blogger_original_url', $original_url );

		return $attachment_id;
	}

	/**
	 * Blogger 画像 URL のサイズパラメータを除去してオリジナル画像 URL に正規化する。
	 *
	 * Blogger URL 例:
	 *   https://1.bp.blogspot.com/-xxx/AAAA/BBBB/s400/image.jpg
	 *   → https://1.bp.blogspot.com/-xxx/AAAA/BBBB/s0/image.jpg
	 *
	 * @param string $url
	 * @return string
	 */
	public function normalize_url( $url ) {
		// /s\d+/ または /s\d+-\w+/ 形式のサイズパラメータを /s0/ に変換
		$url = preg_replace( '#/s\d+(-[a-z]+)?/#', '/s0/', $url );

		// クエリパラメータを除去
		$url = strtok( $url, '?' );

		return $url;
	}

	/**
	 * _blogger_original_url メタで既存アタッチメントを検索する。
	 *
	 * @param string $url
	 * @return int|false
	 */
	private function find_by_original_url( $url ) {
		$posts = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'meta_key'       => '_blogger_original_url',
				'meta_value'     => $url,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return ! empty( $posts ) ? (int) $posts[0] : false;
	}

	/**
	 * 投稿日に基づいて wp_upload_dir の年/月を上書きするフィルターをセットする。
	 *
	 * @param string $post_date  Y-m-d H:i:s
	 */
	private function set_upload_date_filter( $post_date ) {
		$this->upload_year  = gmdate( 'Y', strtotime( $post_date ) );
		$this->upload_month = gmdate( 'm', strtotime( $post_date ) );
		add_filter( 'upload_dir', array( $this, 'upload_dir_filter' ) );
	}

	/** @var string */
	private $upload_year;

	/** @var string */
	private $upload_month;

	/**
	 * upload_dir フィルターコールバック。
	 *
	 * @param array $dirs
	 * @return array
	 */
	public function upload_dir_filter( $dirs ) {
		$dirs['subdir'] = '/' . $this->upload_year . '/' . $this->upload_month;
		$dirs['path']   = $dirs['basedir'] . $dirs['subdir'];
		$dirs['url']    = $dirs['baseurl'] . $dirs['subdir'];
		return $dirs;
	}

	/**
	 * URL からファイルをダウンロードしてメディアライブラリに登録する。
	 *
	 * @param string $url
	 * @param int    $post_id
	 * @return int|WP_Error
	 */
	private function sideload( $url, $post_id ) {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$filename = sanitize_file_name( basename( wp_parse_url( $url, PHP_URL_PATH ) ) );
		if ( empty( $filename ) || ! preg_match( '/\.[a-z]{2,5}$/i', $filename ) ) {
			$filename = 'blogger-image-' . md5( $url ) . '.jpg';
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id );

		// 一時ファイルを削除（失敗してもファイルが残らないように）
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
		}

		return $attachment_id;
	}
}
