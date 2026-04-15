<?php
defined( 'ABSPATH' ) || exit;

/**
 * 投稿インポート処理
 *
 * Blogger 記事データを受け取り、WordPress 投稿として保存する。
 * ラベル（カテゴリー/タグ）、アイキャッチ画像、本文ブロック変換を含む。
 */
class NExT_Blogspot2WP_Importer {

	/** @var NExT_Blogspot2WP_Image */
	private $image_handler;

	/** @var NExT_Blogspot2WP_Converter */
	private $converter;

	/** @var array インポート結果集計 */
	private $stats = array(
		'imported' => 0,
		'updated'  => 0,
		'skipped'  => 0,
		'errors'   => 0,
	);

	/**
	 * @param NExT_Blogspot2WP_Image     $image_handler
	 * @param NExT_Blogspot2WP_Converter $converter
	 */
	public function __construct(
		NExT_Blogspot2WP_Image $image_handler,
		NExT_Blogspot2WP_Converter $converter
	) {
		$this->image_handler = $image_handler;
		$this->converter     = $converter;
	}

	/**
	 * 1件の Blogger 記事を WordPress にインポートする。
	 *
	 * @param array $post    正規化された Blogger 記事データ
	 * @param array $options インポートオプション
	 *   - force       bool   既存投稿を上書きするか
	 *   - skip_images bool   画像インポートをスキップするか
	 *   - post_status string 投稿ステータス
	 *   - author      int    投稿者 ID
	 *   - dry_run     bool   ドライランかどうか
	 *   - labels_as   string ラベルの取り込み先（'category' または 'tag'）
	 * @return array { 'result' => 'imported'|'updated'|'skipped'|'error'|'dry_run', 'post_id' => int, 'message' => string }
	 */
	public function import_post( $post, $options = array() ) {
		$options = wp_parse_args(
			$options,
			array(
				'force'       => false,
				'skip_images' => false,
				'post_status' => 'publish',
				'author'      => 1,
				'dry_run'     => false,
				'labels_as'   => 'category',
			)
		);

		$blogger_id  = isset( $post['id'] )          ? $post['id']          : '';
		$title       = isset( $post['title'] )        ? $post['title']       : '(無題)';
		$slug        = isset( $post['slug'] )         ? $post['slug']        : '';
		$content     = isset( $post['content'] )      ? $post['content']     : '';
		$published   = isset( $post['published'] )    ? $post['published']   : '';
		$updated     = isset( $post['updated'] )      ? $post['updated']     : $published;
		$cover_image = isset( $post['cover_image'] )  ? $post['cover_image'] : '';
		$labels      = isset( $post['labels'] )       ? $post['labels']      : array();
		$source_url  = isset( $post['link'] )         ? $post['link']        : '';

		// 日付を WP 形式（Y-m-d H:i:s）に変換
		$post_date     = $this->convert_date( $published );
		$post_date_gmt = $this->to_gmt( $published );
		$post_modified = $this->convert_date( $updated );
		$post_mod_gmt  = $this->to_gmt( $updated );

		// 重複チェック
		$existing_id = $this->find_existing( $blogger_id, $slug );
		if ( $existing_id && ! $options['force'] ) {
			$this->stats['skipped']++;
			return array(
				'result'  => 'skipped',
				'post_id' => $existing_id,
				'message' => 'スキップ（既存）',
			);
		}

		// ドライランは処理内容を返すだけ
		if ( $options['dry_run'] ) {
			$action = $existing_id ? '更新予定' : 'インポート予定';
			return array(
				'result'  => 'dry_run',
				'post_id' => $existing_id ?: 0,
				'message' => "[DRY RUN] {$action}: {$title} ({$post_date})",
			);
		}

		// 本文変換（HTML → Gutenberg ブロック）
		// 画像インポートがある場合は post_id 確定後に変換するため、ここではスキップ時のみ変換する
		$post_content = $options['skip_images']
			? $this->converter->convert( $content, (int) $existing_id, $post_date )
			: '';

		// 投稿データ組み立て
		$post_data = array(
			'post_title'        => $title,
			'post_name'         => $slug,
			'post_content'      => $post_content,
			'post_status'       => $options['post_status'],
			'post_author'       => (int) $options['author'],
			'post_date'         => $post_date,
			'post_date_gmt'     => $post_date_gmt,
			'post_modified'     => $post_modified,
			'post_modified_gmt' => $post_mod_gmt,
			'post_type'         => 'post',
		);

		if ( $existing_id ) {
			$post_data['ID'] = $existing_id;
			$post_id = wp_update_post( $post_data, true );
		} else {
			$post_id = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $post_id ) ) {
			$this->stats['errors']++;
			return array(
				'result'  => 'error',
				'post_id' => 0,
				'message' => $post_id->get_error_message(),
			);
		}

		// カスタムフィールド保存
		update_post_meta( $post_id, '_blogger_post_id', $blogger_id );
		update_post_meta( $post_id, '_blogger_source_url', $source_url );

		// ラベル紐付け
		if ( ! empty( $labels ) ) {
			$this->assign_labels( $post_id, $labels, $options['labels_as'] );
		}

		// 画像インポート（post_id 確定後に変換・保存）
		if ( ! $options['skip_images'] ) {
			// アイキャッチ画像
			if ( $cover_image ) {
				$thumbnail_id = $this->image_handler->import( $cover_image, $post_id, $post_date );
				if ( ! is_wp_error( $thumbnail_id ) ) {
					set_post_thumbnail( $post_id, $thumbnail_id );
				}
			}

			// 本文変換（post_id 確定済みなので画像も正しく紐付けられる）。
			$post_content = $this->converter->convert( $content, $post_id, $post_date );
			wp_update_post( array( 'ID' => $post_id, 'post_content' => $post_content ) );
		}

		if ( $existing_id ) {
			$this->stats['updated']++;
			$result = 'updated';
		} else {
			$this->stats['imported']++;
			$result = 'imported';
		}

		return array(
			'result'  => $result,
			'post_id' => $post_id,
			'message' => '',
		);
	}

	/**
	 * 集計結果を返す。
	 *
	 * @return array
	 */
	public function get_stats() {
		return $this->stats;
	}

	// -------------------------------------------------------------------------
	// ラベル処理
	// -------------------------------------------------------------------------

	/**
	 * Blogger ラベルを WordPress カテゴリーまたはタグに変換して投稿に紐付ける。
	 *
	 * @param int    $post_id
	 * @param array  $labels   ラベル名の配列
	 * @param string $taxonomy 'category' または 'tag'（post_tag）
	 */
	private function assign_labels( $post_id, $labels, $taxonomy = 'category' ) {
		$wp_taxonomy = ( $taxonomy === 'tag' ) ? 'post_tag' : 'category';
		$term_ids    = array();

		foreach ( $labels as $label_name ) {
			if ( ! $label_name ) {
				continue;
			}

			$term_id = $this->get_or_create_term( $label_name, $wp_taxonomy );
			if ( $term_id ) {
				$term_ids[] = $term_id;
			}
		}

		if ( empty( $term_ids ) ) {
			return;
		}

		if ( $wp_taxonomy === 'category' ) {
			wp_set_post_categories( $post_id, $term_ids );
		} else {
			wp_set_post_tags( $post_id, $term_ids, false );
		}
	}

	/**
	 * ターム名でタクソノミーを検索し、なければ作成して term_id を返す。
	 *
	 * @param string $name
	 * @param string $taxonomy
	 * @return int|false
	 */
	private function get_or_create_term( $name, $taxonomy ) {
		$existing = get_term_by( 'name', $name, $taxonomy );

		if ( $existing ) {
			return (int) $existing->term_id;
		}

		$result = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $result ) ) {
			return false;
		}

		return (int) $result['term_id'];
	}

	// -------------------------------------------------------------------------
	// 日付変換
	// -------------------------------------------------------------------------

	/**
	 * ISO 8601 をサイトのローカル時間（Y-m-d H:i:s）に変換する。
	 *
	 * Blogger Feed は "+09:00" などのオフセット付きで返す。
	 *
	 * @param string $iso_date
	 * @return string
	 */
	private function convert_date( $iso_date ) {
		if ( ! $iso_date ) {
			return current_time( 'mysql' );
		}
		$timestamp = strtotime( $iso_date );
		if ( false === $timestamp ) {
			return current_time( 'mysql' );
		}
		return get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) );
	}

	/**
	 * ISO 8601 を UTC の Y-m-d H:i:s に変換する。
	 *
	 * @param string $iso_date
	 * @return string
	 */
	private function to_gmt( $iso_date ) {
		if ( ! $iso_date ) {
			return current_time( 'mysql', true );
		}
		$timestamp = strtotime( $iso_date );
		if ( false === $timestamp ) {
			return current_time( 'mysql', true );
		}
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	// -------------------------------------------------------------------------
	// 重複チェック
	// -------------------------------------------------------------------------

	/**
	 * 既存投稿を検索する。
	 * _blogger_post_id メタで検索し、見つからなければスラッグで検索する。
	 *
	 * @param string $blogger_id
	 * @param string $slug
	 * @return int|false
	 */
	private function find_existing( $blogger_id, $slug = '' ) {
		if ( $blogger_id ) {
			$posts = get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'any',
					'meta_key'       => '_blogger_post_id',
					'meta_value'     => $blogger_id,
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);

			if ( ! empty( $posts ) ) {
				return (int) $posts[0];
			}
		}

		if ( $slug ) {
			$posts = get_posts(
				array(
					'name'           => $slug,
					'post_type'      => 'post',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);

			if ( ! empty( $posts ) ) {
				return (int) $posts[0];
			}
		}

		return false;
	}
}
