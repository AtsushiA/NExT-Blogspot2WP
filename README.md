# NExT Blogspot2WP

Blogger（Blogspot）のブログを WordPress の投稿として WP-CLI 経由でインポートするプラグインです。

---

## 機能

- Blogger Atom Feed（JSON形式）から全記事を取得（ページネーション対応）
- APIキー不要・認証不要でそのまま使用可能
- 記事タイトル・公開日・スラッグを Blogger の値そのままで保存
- 本文 HTML を Gutenberg ブロック（`core/paragraph`、`core/heading`、`core/image` など）に変換
- 画像を WordPress メディアライブラリに登録（投稿日の年/月フォルダ）
- Blogger 画像 URL のサイズパラメータ（`/s400/` など）をオリジナル（`/s0/`）に変換してダウンロード
- ラベルを WordPress カテゴリーまたはタグに変換・紐付け（`--labels-as` で切り替え）
- 重複インポート防止（`_blogger_post_id` メタで照合）
- ドライラン・強制上書き・画像スキップなどのオプション

---

## 動作環境

| 項目 | 要件 |
|---|---|
| WordPress | 6.0 以上 |
| PHP | 7.4 以上（`DOMDocument` 拡張が必要） |
| WP-CLI | 2.0 以上 |

---

## インストール

1. プラグインフォルダを `wp-content/plugins/NExT-blogspot2WP/` に配置
2. WordPress 管理画面または WP-CLI でプラグインを有効化

```bash
wp plugin activate NExT-blogspot2WP
```

---

## 使い方

### import コマンド

```bash
wp blogspot2wp import --blog-url=<BloggerブログURL> [オプション]
```

#### オプション

| オプション | デフォルト | 説明 |
|---|---|---|
| `--blog-url=<url>` | （必須） | Blogger ブログの URL |
| `--limit=<number>` | `0`（全件） | 最大インポート件数 |
| `--dry-run` | false | 実行せず処理内容を表示のみ |
| `--force` | false | インポート済み記事を上書き更新 |
| `--skip-images` | false | 画像インポートをスキップ |
| `--post-status=<status>` | `publish` | 投稿ステータス（`publish` / `draft` / `private` / `pending`） |
| `--author=<user_id>` | `1` | 投稿者のユーザー ID |
| `--labels-as=<taxonomy>` | `category` | ラベルの取り込み先（`category` または `tag`） |

#### 実行例

```bash
# 全記事をインポート
wp blogspot2wp import --blog-url=https://example.blogspot.com/

# 最初の 5 件だけドライランで確認
wp blogspot2wp import --blog-url=https://example.blogspot.com/ --limit=5 --dry-run

# 画像をスキップして下書きでインポート
wp blogspot2wp import --blog-url=https://example.blogspot.com/ --skip-images --post-status=draft

# ラベルをタグとしてインポート
wp blogspot2wp import --blog-url=https://example.blogspot.com/ --labels-as=tag

# インポート済みの記事を強制上書き
wp blogspot2wp import --blog-url=https://example.blogspot.com/ --force

# 詳細ログを表示しながら実行
wp blogspot2wp import --blog-url=https://example.blogspot.com/ --debug
```

#### 実行例（出力）

```
Blogger Feed から記事を取得しています: https://example.blogspot.com/
150 件の記事が見つかりました。
インポート中: 100% (150/150) [============================] 0:02:30

===== インポート完了 =====
  インポート: 148
  更新:         0
  スキップ:     2
  エラー:       0
Success: すべての処理が完了しました。
```

---

## 保存されるデータ

### 投稿メタ

| メタキー | 内容 |
|---|---|
| `_blogger_post_id` | Blogger の記事 ID（重複チェック・再インポート用） |
| `_blogger_source_url` | Blogger の元記事 URL（確認用） |

### メディアメタ

| メタキー | 内容 |
|---|---|
| `_blogger_original_url` | Blogger の元画像 URL（重複インポート防止用） |

---

## ファイル構成

```
NExT-blogspot2WP/
├── NExT-blogspot2WP.php            # プラグインエントリーポイント
├── includes/
│   ├── class-blogger-feed.php      # Blogger Atom Feed クライアント
│   ├── class-image.php             # 画像ダウンロード・メディア登録
│   ├── class-converter.php         # HTML → Gutenberg ブロック変換
│   └── class-importer.php          # 投稿インポート処理（ラベル・日付含む）
├── cli/
│   └── class-cli-command.php       # WP-CLI コマンド定義
├── README.md
└── SPEC.md                         # 仕様書
```

---

## 技術仕様

### データ取得

| 項目 | 内容 |
|---|---|
| エンドポイント | `https://{domain}/feeds/posts/default?alt=json` |
| 認証 | 不要 |
| ページネーション | `start-index` パラメータで制御（1始まり） |
| 1回の取得件数 | 25件（最大150件まで変更可能） |

### HTML → Gutenberg ブロック変換

| HTML タグ | Gutenberg ブロック |
|---|---|
| `<p>` | `core/paragraph` |
| `<h1>`〜`<h6>` | `core/heading` |
| `<img>` / `<figure>` | `core/image` |
| `<a>` + `<img>`（画像リンク） | `core/image`（linkDestination=media） |
| Blogger 画像テーブル（`<table>` + `tr-caption`） | `core/image`（キャプション付き） |
| `<blockquote>` | `core/quote` |
| `<pre>` / `<code>` | `core/code` |
| `<ul>` | `core/list`（unordered） |
| `<ol>` | `core/list`（ordered） |
| `<hr>` | `core/separator` |
| `<table>` | `core/table` |
| `<iframe>` | `core/embed` |
| その他 | `core/html` |

---

## 注意事項

- **大量インポート**: 記事数が多い場合は `--limit=5` などで少量テストしてから全件実行することを推奨します。
- **レート制限**: 各ページリクエスト間に 200ms のインターバルを設けています。
- **再実行**: 一度インポートした記事は `_blogger_post_id` メタで管理されるため、再実行しても重複しません。上書きが必要な場合は `--force` を使用してください。
- **画像 URL**: Blogger の画像 URL に含まれるサイズパラメータ（`/s400/` など）は自動的に `/s0/`（オリジナルサイズ）に変換してダウンロードします。
- **ラベル**: Blogger のラベルはデフォルトでカテゴリーとして取り込まれます。タグとして取り込む場合は `--labels-as=tag` を指定してください。

---

## ライセンス

GPL-2.0-or-later
