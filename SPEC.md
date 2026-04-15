# NExT-blogspot2WP プラグイン仕様書

## 概要

BlogspotブログをWordPressの投稿としてインポートするWP-CLIプラグイン。
Blogger（Google）で構築されたサイト（例: https://example.blogspot.com/）を対象とする。

---

## 対象サイト

- サイトURL: `https://example.blogspot.com/`
- プラットフォーム: Blogger（Google）
- コンテンツ形式: HTML（サーバーサイドレンダリング）

---

## WP-CLI コマンド仕様

### 基本コマンド

```bash
# 全記事インポート
wp blogspot2wp import --blog-url=https://example.blogspot.com/

# 記事数を指定してインポート（テスト用）
wp blogspot2wp import --blog-url=https://example.blogspot.com/ --limit=10

# ドライラン（実際にはインポートしない）
wp blogspot2wp import --blog-url=https://example.blogspot.com/ --dry-run

# インポート済みの記事を再インポート（上書き）
wp blogspot2wp import --blog-url=https://example.blogspot.com/ --force

# 画像のインポートをスキップ
wp blogspot2wp import --blog-url=https://example.blogspot.com/ --skip-images
```

### オプション一覧

| オプション | 型 | デフォルト | 説明 |
|---|---|---|---|
| `--blog-url` | string | 必須 | BlogspotブログのURL |
| `--limit` | int | 0（全件） | インポートする記事数の上限 |
| `--dry-run` | bool | false | 実行せず処理内容を表示のみ |
| `--force` | bool | false | 既存投稿を上書き |
| `--skip-images` | bool | false | 画像インポートをスキップ |
| `--post-status` | string | `publish` | インポート後の投稿ステータス |
| `--author` | int | 1 | 投稿者のユーザーID |
| `--labels-as` | string | `category` | Bloggerラベルの取り込み先（`category` または `tag`） |

---

## 記事取得方式

### Blogger Atom Feed 利用

BloggerはAtom/RSS形式のフィードを標準提供しているため、**Atom Feed（JSON形式）** を使用して記事データを取得する。APIキー不要でアクセスできる。

#### フィードエンドポイント

```
GET https://{domain}/feeds/posts/default
    ?alt=json
    &start-index=1
    &max-results=25
    &orderby=published
```

| パラメータ | 説明 |
|---|---|
| `alt=json` | レスポンスをJSON形式で取得 |
| `start-index` | 取得開始位置（1始まり） |
| `max-results` | 1回の取得件数（最大150） |
| `orderby` | 並び順（`published` または `updated`） |

#### ページネーション処理

1. `start-index=1` から取得開始
2. レスポンスの `feed.openSearch$totalResults.$t` で総件数を確認
3. 全件取得するまで `start-index` を加算してループ
4. `--limit` 指定時は上限に達したら停止

```
start-index=1  → 25件取得
start-index=26 → 25件取得
start-index=51 → 残り件数分取得
...
```

#### レスポンス例

```json
{
  "feed": {
    "openSearch$totalResults": { "$t": "150" },
    "entry": [
      {
        "id": { "$t": "tag:blogger.com,1999:blog-12345.post-67890" },
        "title": { "$t": "ブログタイトル" },
        "content": { "$t": "<p>本文HTML...</p>" },
        "published": { "$t": "2023-08-15T10:00:00+09:00" },
        "updated":   { "$t": "2023-08-15T11:00:00+09:00" },
        "link": [
          { "rel": "alternate", "href": "https://example.blogspot.com/2023/08/slug.html" }
        ],
        "category": [
          { "term": "お知らせ" },
          { "term": "WordPress" }
        ],
        "author": [
          { "name": { "$t": "著者名" } }
        ],
        "media$thumbnail": { "url": "https://1.bp.blogspot.com/-xxx/image.jpg" }
      }
    ]
  }
}
```

---

## インポート処理フロー

```
1. WP-CLI コマンド実行
        ↓
2. Blogger Atom FeedでIDと記事一覧取得（ページネーション）
        ↓
3. 各記事をループ処理
   ├── 3a. 重複チェック（_blogger_post_id メタで確認）
   │       既存 & --force なし → スキップ
   │       既存 & --force あり → 更新
   │       未インポート → 新規作成
   ├── 3b. 画像処理（--skip-images なし）
   │       カバー画像・本文内画像を取得
   │       WordPressメディアライブラリに登録
   │       年/月フォルダに分類（投稿日基準）
   │       本文内URLをWordPress URLに置換
   ├── 3c. 本文変換（Blogger HTML → Gutenberg ブロック）
   ├── 3d. ラベル処理
   │       Bloggerラベルを--labels-asオプションに従い
   │       WPカテゴリーまたはタグに変換・紐付け
   └── 3e. wp_insert_post() で投稿作成・更新
        ↓
4. 処理結果サマリー表示
```

---

## 投稿データのマッピング

| WordPress フィールド | Blogger データソース | 備考 |
|---|---|---|
| `post_title` | `entry.title.$t` | そのまま使用 |
| `post_date` | `entry.published.$t` | タイムゾーンを考慮して変換 |
| `post_date_gmt` | `entry.published.$t` | UTC に変換して保存 |
| `post_modified` | `entry.updated.$t` | |
| `post_status` | `--post-status` オプション | デフォルト `publish` |
| `post_author` | `--author` オプション | |
| `post_name` | `entry.link[rel=alternate].href` | URLからスラッグを抽出 |
| `post_content` | `entry.content.$t` | Gutenbergブロックに変換 |
| `_thumbnail_id` | `media$thumbnail.url` | メディアID |
| `_blogger_post_id` | `entry.id.$t` | 重複チェック用カスタムフィールド |
| `_blogger_source_url` | `entry.link[rel=alternate].href` | 元のBlogger記事URL |

---

## ラベルインポート仕様

### 取得方法

Bloggerの「ラベル（Labels）」はAtom Feedの `entry.category[].term` で取得する。

```json
"category": [
  { "term": "お知らせ" },
  { "term": "WordPress" }
]
```

### WordPressへのマッピング

| Blogger フィールド | WordPress | 処理 |
|---|---|---|
| `category[].term` | カテゴリー名 または タグ名 | `--labels-as=category`（デフォルト）でカテゴリー、`--labels-as=tag` でタグに登録 |

### 処理フロー（カテゴリーの場合）

```php
foreach ($entry['category'] as $label) {
    $term_name = $label['term'];

    // 同名カテゴリーが存在するか確認
    $existing = get_term_by('name', $term_name, 'category');

    if (!$existing) {
        $result = wp_insert_term($term_name, 'category');
        $term_id = $result['term_id'];
    } else {
        $term_id = $existing->term_id;
    }

    $category_ids[] = $term_id;
}

wp_set_post_categories($post_id, $category_ids);
```

---

## Blogger HTML → Gutenberg ブロック 変換仕様

### 変換方針

Bloggerのコンテンツは通常のHTMLで提供される。DOMパーサーでHTMLを解析し、対応するGutenbergブロックに変換する。

### 対応タグとブロックのマッピング

| HTML タグ | Gutenberg ブロック | 備考 |
|---|---|---|
| `<p>` | `core/paragraph` | |
| `<h1>`〜`<h6>` | `core/heading` | level を保持 |
| `<img>` | `core/image` | メディアライブラリ登録後のIDを使用 |
| `<blockquote>` | `core/quote` | |
| `<pre>` / `<code>` | `core/code` | |
| `<ul>` | `core/list` | ordered=false |
| `<ol>` | `core/list` | ordered=true |
| `<hr>` | `core/separator` | |
| `<table>` | `core/table` | |
| `<iframe>` | `core/embed` | YouTube等の埋め込み |
| 上記以外のHTML | `core/html` | フリーフォームブロックとして格納 |

### テキスト装飾（インライン）

| HTML タグ | 扱い |
|---|---|
| `<strong>` / `<b>` | そのまま保持 |
| `<em>` / `<i>` | そのまま保持 |
| `<a>` | そのまま保持（href を保持） |
| `<span style="...">` | そのまま保持 |

---

## 画像インポート仕様

### 処理手順

1. Bloggerの画像URL（`bp.blogspot.com` / `googleusercontent.com` 等）を取得
2. カバー画像: `media$thumbnail.url` または本文内最初の `<img>` を使用
3. `media_handle_sideload()` でダウンロード
4. 投稿日（`published`）の年/月ディレクトリに保存
   - 例: `wp-content/uploads/2023/08/image.jpg`
5. `wp_insert_attachment()` でメディアライブラリに登録
6. `wp_generate_attachment_metadata()` でサムネイル生成
7. 本文内の元URLをWordPressメディアURLに置換

### 注意事項

- Bloggerの画像URLにはサイズパラメータ（`/s1600/` 等）が含まれる場合がある。オリジナル解像度のURLに変換して取得する
  - 例: `https://1.bp.blogspot.com/-xxx/s400/image.jpg` → `https://1.bp.blogspot.com/-xxx/s0/image.jpg`
- 同一画像の重複インポート防止: `_blogger_original_url` メタで照合
- Googleの外部ホスト画像（`googleusercontent.com`）はリダイレクトが発生する場合があるため、リダイレクト追跡を有効にして取得する

---

## 重複チェック

```php
// インポート済みチェック
$existing = get_posts([
    'post_type'  => 'post',
    'meta_key'   => '_blogger_post_id',
    'meta_value' => $blogger_post_id,
    'fields'     => 'ids',
]);
```

- `_blogger_post_id` カスタムフィールドにBloggerの記事IDを保存（`entry.id.$t` の値）
- インポート前に上記で既存投稿を検索
- `--force` オプション時は既存投稿を更新

---

## プラグイン構成

```
NExT-blogspot2WP/
├── NExT-blogspot2WP.php          # プラグインエントリーポイント
├── includes/
│   ├── class-blogger-feed.php    # Blogger Atom Feed クライアント
│   ├── class-converter.php       # HTML → Gutenbergブロック変換
│   ├── class-importer.php        # 投稿インポート処理
│   └── class-image.php           # 画像ダウンロード・メディア登録
├── cli/
│   └── class-cli-command.php     # WP-CLI コマンド定義
└── SPEC.md
```

---

## エラーハンドリング

| エラーケース | 対応 |
|---|---|
| Blogger Feed 接続失敗 | WP_Errorを返し処理中断 |
| 画像ダウンロード失敗 | ログに記録してスキップ（記事インポートは継続） |
| 本文変換失敗 | 元のHTMLを `core/html` ブロックとして格納 |
| DB書き込み失敗 | WP_Errorを返し次の記事へ |
| Feedレスポンス不正 | WP_Errorを返し処理中断 |

---

## 進捗表示（WP-CLI出力）

```
$ wp blogspot2wp import --blog-url=https://example.blogspot.com/

Fetching posts from Blogger Feed...
Found 150 posts.

Importing post 1/150: "ブログタイトル1" (2023-08-15)... done
Importing post 2/150: "ブログタイトル2" (2023-07-20)... done
...
Importing post 150/150: "最初の記事" (2019-01-10)... done

Import complete!
  Imported: 148
  Skipped:  2
  Errors:   0
```

---

## 開発上の注意事項

1. **Atom Feed の仕様**: `alt=json` で取得できるJSONはAtomフィードをGoogle独自形式でラップしたもの。`$t` プロパティで実際の値にアクセスする。
2. **レート制限**: Blogger Feedへのリクエストを連続して行わないよう、適切なインターバル（100〜500ms）を設ける。
3. **タイムゾーン**: BloggerのFeedはサイト設定のタイムゾーンでオフセット付きで返す（例: `+09:00`）。`post_date_gmt` にはUTCに変換した値を保存する。
4. **大量インポート**: `--limit` で小量テストしてから全件実行を推奨。`set_time_limit(0)` でタイムアウトを回避する。
5. **スラッグ抽出**: BloggerのURLは `https://example.blogspot.com/YYYY/MM/slug.html` 形式のため、末尾の `.html` を除いたパス末尾をスラッグとして使用する。
6. **画像サイズパラメータ**: Blogger画像URLのサイズ指定（`/s1600/`、`/s400/` 等）を `/s0/`（オリジナルサイズ）に変換してからダウンロードする。
