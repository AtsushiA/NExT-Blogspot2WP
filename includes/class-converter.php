<?php
defined( 'ABSPATH' ) || exit;

/**
 * Blogger HTML → Gutenberg ブロック変換
 *
 * Blogger の post_content（HTML 文字列）を PHP の DOMDocument で解析し、
 * WordPress のブロックシリアライズ形式に変換する。
 */
class NExT_Blogspot2WP_Converter {

	/** @var NExT_Blogspot2WP_Image */
	private $image_handler;

	/** @var int 紐付ける投稿 ID */
	private $post_id;

	/** @var string 投稿日（Y-m-d H:i:s） */
	private $post_date;

	/**
	 * @param NExT_Blogspot2WP_Image $image_handler
	 */
	public function __construct( NExT_Blogspot2WP_Image $image_handler ) {
		$this->image_handler = $image_handler;
	}

	/**
	 * Blogger の HTML コンテンツを Gutenberg ブロック文字列に変換する。
	 *
	 * @param string $html       Blogger の post_content（HTML）
	 * @param int    $post_id    紐付ける投稿 ID
	 * @param string $post_date  投稿日（Y-m-d H:i:s）
	 * @return string Gutenberg シリアライズ済みブロック文字列
	 */
	public function convert( $html, $post_id = 0, $post_date = '' ) {
		$this->post_id   = $post_id;
		$this->post_date = $post_date;

		if ( ! $html ) {
			return '';
		}

		// DOMDocument でパース
		$dom = new DOMDocument( '1.0', 'UTF-8' );
		// エラー抑制 + HTML5 対応のため UTF-8 BOM 付き charset メタタグを付与
		$prev_libxml_errors = libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<?xml encoding="UTF-8">' . '<div id="blogger-content">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $prev_libxml_errors );

		$wrapper = $dom->getElementById( 'blogger-content' );
		if ( ! $wrapper ) {
			// フォールバック: 元のHTMLをそのまま core/html に格納
			return $this->wrap_block( 'core/html', array(), $html );
		}

		$blocks = array();
		foreach ( $wrapper->childNodes as $node ) {
			$block = $this->convert_node( $node );
			if ( $block !== '' ) {
				$blocks[] = $block;
			}
		}

		return implode( "\n\n", $blocks );
	}

	// -------------------------------------------------------------------------
	// ノード変換
	// -------------------------------------------------------------------------

	/**
	 * 単一 DOM ノードを Gutenberg ブロック文字列に変換する。
	 *
	 * @param DOMNode $node
	 * @return string
	 */
	private function convert_node( $node ) {
		if ( $node->nodeType === XML_TEXT_NODE ) {
			$text = trim( $node->nodeValue );
			if ( $text === '' ) {
				return '';
			}
			return $this->wrap_block( 'core/paragraph', array(), '<p>' . esc_html( $text ) . '</p>' );
		}

		if ( $node->nodeType !== XML_ELEMENT_NODE ) {
			return '';
		}

		/** @var DOMElement $node */
		$tag = strtolower( $node->nodeName );

		switch ( $tag ) {
			case 'p':
				return $this->convert_paragraph( $node );

			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				return $this->convert_heading( $node, (int) substr( $tag, 1 ) );

			case 'img':
				return $this->convert_image_node( $node );

			case 'figure':
				return $this->convert_figure( $node );

			case 'blockquote':
				return $this->convert_blockquote( $node );

			case 'pre':
				return $this->convert_pre( $node );

			case 'ul':
				return $this->convert_list( $node, false );

			case 'ol':
				return $this->convert_list( $node, true );

			case 'hr':
				return $this->wrap_block( 'core/separator', array(), '<hr class="wp-block-separator has-alpha-channel-opacity"/>' );

			case 'table':
				return $this->convert_table( $node );

			case 'iframe':
				return $this->convert_iframe( $node );

			case 'div':
			case 'section':
			case 'article':
				return $this->convert_div( $node );

			case 'br':
				return '';

			default:
				// その他の要素は innerHTML を取得して core/html に格納
				$inner = $this->get_inner_html( $node );
				if ( trim( strip_tags( $inner ) ) === '' && ! preg_match( '/<img\b/i', $inner ) ) {
					return '';
				}
				return $this->wrap_block( 'core/html', array(), '<' . $tag . '>' . $inner . '</' . $tag . '>' );
		}
	}

	// -------------------------------------------------------------------------
	// 各タグの変換
	// -------------------------------------------------------------------------

	private function convert_paragraph( DOMElement $node ) {
		$inner = $this->get_inner_html( $node );
		if ( trim( strip_tags( $inner ) ) === '' && ! preg_match( '/<img\b/i', $inner ) ) {
			return '';
		}
		// img だけ含む <p> は image ブロックに変換
		if ( preg_match( '/^\s*<img\b[^>]*>\s*$/i', $inner ) ) {
			$img_tags = array();
			preg_match_all( '/<img\b[^>]*>/i', $inner, $img_tags );
			if ( ! empty( $img_tags[0] ) ) {
				$src = '';
				if ( preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $img_tags[0][0], $m ) ) {
					$src = $m[1];
				}
				if ( $src ) {
					return $this->convert_image_from_src( $src );
				}
			}
		}
		return $this->wrap_block( 'core/paragraph', array(), '<p>' . $inner . '</p>' );
	}

	private function convert_heading( DOMElement $node, $level ) {
		$level = max( 1, min( 6, $level ) );
		$inner = $this->get_inner_html( $node );
		return $this->wrap_block(
			'core/heading',
			array( 'level' => $level ),
			'<h' . $level . ' class="wp-block-heading">' . $inner . '</h' . $level . '>'
		);
	}

	private function convert_image_node( DOMElement $node ) {
		$src = $node->getAttribute( 'src' );
		if ( ! $src ) {
			return '';
		}
		return $this->convert_image_from_src( $src, $node->getAttribute( 'alt' ) );
	}

	private function convert_figure( DOMElement $node ) {
		// figure 内の img を探す
		$imgs = $node->getElementsByTagName( 'img' );
		if ( $imgs->length === 0 ) {
			$inner = $this->get_inner_html( $node );
			return $this->wrap_block( 'core/html', array(), '<figure>' . $inner . '</figure>' );
		}

		$img     = $imgs->item( 0 );
		$src     = $img->getAttribute( 'src' );
		$alt     = $img->getAttribute( 'alt' );
		$caption = '';

		// figcaption
		$captions = $node->getElementsByTagName( 'figcaption' );
		if ( $captions->length > 0 ) {
			$caption = $captions->item( 0 )->textContent;
		}

		return $this->convert_image_from_src( $src, $alt, $caption );
	}

	/**
	 * 画像 URL から core/image ブロックを生成する。
	 *
	 * @param string $src
	 * @param string $alt
	 * @param string $caption
	 * @return string
	 */
	private function convert_image_from_src( $src, $alt = '', $caption = '' ) {
		if ( ! $src ) {
			return '';
		}

		$normalized_src = $this->image_handler->normalize_url( $src );
		$attachment_id  = $this->image_handler->import( $normalized_src, $this->post_id, $this->post_date );

		if ( is_wp_error( $attachment_id ) ) {
			$img = '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '"/>';
			$attrs = array();
		} else {
			$img_url = wp_get_attachment_url( $attachment_id );
			$img     = '<img src="' . esc_url( $img_url ) . '" alt="' . esc_attr( $alt ) . '"'
				. ' class="wp-image-' . (int) $attachment_id . '"/>';
			$attrs = array( 'id' => $attachment_id );
		}

		$figure = '<figure class="wp-block-image">' . $img;
		if ( $caption ) {
			$figure .= '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>';
		}
		$figure .= '</figure>';

		return $this->wrap_block( 'core/image', $attrs, $figure );
	}

	private function convert_blockquote( DOMElement $node ) {
		$inner = $this->get_inner_html( $node );
		return $this->wrap_block(
			'core/quote',
			array(),
			'<blockquote class="wp-block-quote"><p>' . $inner . '</p></blockquote>'
		);
	}

	private function convert_pre( DOMElement $node ) {
		// <pre><code> の場合はコードブロック
		$codes = $node->getElementsByTagName( 'code' );
		if ( $codes->length > 0 ) {
			$text = $codes->item( 0 )->textContent;
		} else {
			$text = $node->textContent;
		}
		return $this->wrap_block(
			'core/code',
			array(),
			'<pre class="wp-block-code"><code>' . esc_html( $text ) . '</code></pre>'
		);
	}

	private function convert_list( DOMElement $node, $ordered ) {
		$tag   = $ordered ? 'ol' : 'ul';
		$items = $node->getElementsByTagName( 'li' );
		$html  = '<' . $tag . '>';
		foreach ( $items as $li ) {
			$html .= '<li>' . $this->get_inner_html( $li ) . '</li>';
		}
		$html .= '</' . $tag . '>';

		return $this->wrap_block(
			'core/list',
			array( 'ordered' => $ordered ),
			$html
		);
	}

	private function convert_table( DOMElement $node ) {
		$inner = $this->get_inner_html( $node );
		return $this->wrap_block(
			'core/table',
			array(),
			'<figure class="wp-block-table"><table>' . $inner . '</table></figure>'
		);
	}

	private function convert_iframe( DOMElement $node ) {
		$src = $node->getAttribute( 'src' );
		if ( ! $src ) {
			return '';
		}
		$esc_url = esc_url( $src );
		return $this->wrap_block(
			'core/embed',
			array( 'url' => $src ),
			'<figure class="wp-block-embed"><div class="wp-block-embed__wrapper">'
			. $esc_url
			. '</div></figure>'
		);
	}

	private function convert_div( DOMElement $node ) {
		$blocks = array();
		foreach ( $node->childNodes as $child ) {
			$block = $this->convert_node( $child );
			if ( $block !== '' ) {
				$blocks[] = $block;
			}
		}
		if ( empty( $blocks ) ) {
			return '';
		}
		return implode( "\n\n", $blocks );
	}

	// -------------------------------------------------------------------------
	// ヘルパー
	// -------------------------------------------------------------------------

	/**
	 * DOM ノードの innerHTML（内側の HTML 文字列）を取得する。
	 *
	 * @param DOMNode $node
	 * @return string
	 */
	private function get_inner_html( DOMNode $node ) {
		$html = '';
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		return $html;
	}

	/**
	 * ブロックコメントで囲む。
	 *
	 * @param string $block_name  例: 'core/paragraph'
	 * @param array  $attrs       ブロック属性
	 * @param string $inner_html  ブロック内 HTML
	 * @return string
	 */
	private function wrap_block( $block_name, $attrs, $inner_html ) {
		$attrs_str = empty( $attrs ) ? '' : ' ' . wp_json_encode( $attrs );
		return "<!-- wp:{$block_name}{$attrs_str} -->\n"
			. $inner_html
			. "\n<!-- /wp:{$block_name} -->";
	}
}
