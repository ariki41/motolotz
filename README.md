# MotoLotz

駐輪場の情報を登録・検索・参照するためのLaravel製Webアプリケーションです。
利用者は会員登録・ログイン後、駐輪場の基本情報、営業時間、料金帯、画像を登録・編集できます。

本書および利用者向けの表示では、二輪車を対象とする施設を「駐輪場」と表記します。DB・URL・クラス名の `ParkingSpot` は技術上の識別子として維持します。

## 主な機能

- 駐輪場の一覧表示・検索
- 駐輪場情報の登録・編集・詳細表示
- 駐輪場のお気に入り追加・解除・一覧表示
- 駐輪場の評価・レビュー投稿
- 郵便番号による住所補完
- 住所からの緯度・経度取得と地図表示
- 曜日・時間帯ごとの料金設定
- 料金帯の重複チェック
- 駐輪場画像のアップロード（最大4枚・WebP変換）
- 駐輪場・料金・画像を一体として保存するトランザクションと画像補償処理
- 確認画面の改ざん・期限切れ対策と放置された一時画像の定期削除
- ユーザーIDによる会員登録・ログイン、プロフィール管理

## 技術構成

| 項目 | 採用技術 |
| --- | --- |
| Backend | PHP 8.5 / Laravel 13 |
| Frontend | Blade / Livewire 3 / Tailwind CSS / Alpine.js |
| Database | MySQL 8.4 LTS |
| Development environment | Laravel Sail / Docker Compose |
| Asset build | Vite |

検索・詳細・登録確認画面のLeaflet地図は `x-leaflet-map` コンポーネントを使用します。中心座標、ズーム、固定・動的マーカー、Livewireへの表示範囲通知を画面ごとに設定でき、初期化できない場合は地図領域にエラーメッセージを表示します。

ローカル開発用のDocker Composeは、Laravelアプリケーションを実行する `laravel.test` とMySQL 8.4 LTSの `mysql` で構成します。セッション、キャッシュ、キューはデータベースドライバ、メールはログドライバを使用するため、Redis、Meilisearch、Mailpit、Seleniumは起動しません。

## 必要な環境

- Docker Desktop または Docker Engine
- Docker Compose
- PHP 8.5 / Composer
- Node.js / npm（ローカルでViteを実行する場合）

## セットアップ

### 1. リポジトリの取得

```bash
git clone <repository-url>
cd bike-parking
```

### 2. Laravel Sailの起動

```bash
cp .env.example .env
composer install
docker compose up -d
docker compose exec laravel.test php artisan key:generate
docker compose exec laravel.test php artisan migrate --seed
```

画像や静的ファイルを利用する場合は、必要に応じてストレージリンクも作成します。

```bash
docker compose exec laravel.test php artisan storage:link
```

### 3. フロントエンドの準備

```bash
npm install
npm run dev
```

本番用アセットを生成する場合は `npm run build` を実行してください。

### Vite開発サーバーのセキュリティ

Vite開発サーバーはソースファイルを配信する開発専用プロセスです。`vite.config.js` では
`127.0.0.1` にだけバインドし、許可Hostを `localhost`、CORS Originを `localhost`・loopback IPに
限定しています。開発サーバーをLANやインターネットへ公開しないでください。リモート開発が必要な
場合は、この設定を広げず、認証されたリバースプロキシまたはポートフォワーディングを利用します。

`npm audit` はビルド時に使うVite、PostCSS、Tailwind、Playwrightなどの開発依存と、ブラウザに
バンドルされるAxiosを含めて検査します。ビルド済み資産のみを配信する本番環境ではVite・PostCSS・
Playwrightの開発サーバー／ビルドツールは実行されませんが、脆弱な依存を開発・CIに残さない方針です。
Axiosはクライアント資産に含まれるため、開発依存として記録されていても更新対象に含めます。

### 4. アプリケーションへのアクセス

ブラウザで [http://localhost](http://localhost) を開きます。
認証にはメールアドレスではなくユーザーIDを使用します。メール確認とメール経由のパスワード再設定は提供していません。

## 環境変数

基本設定は `.env.example` を使用します。ローカル開発ではDocker ComposeのMySQLと、ブラウザから画像を参照できる `public` ディスクを推奨設定としています。

```dotenv
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=bike_parking
DB_USERNAME=sail
DB_PASSWORD=password
FILESYSTEM_DISK=public
```

`public` ディスクを利用するため、セットアップ手順どおり `php artisan storage:link` を実行してください。

### MySQL 8.0から8.4 LTSへの更新

既存の `sail-mysql` ボリュームをMySQL 8.4で起動すると、MySQLはデータディレクトリをアップグレードします。アップグレード後に8.0へインプレースで戻すことはできないため、必ずバックアップを取得してから実施してください。

```bash
# MySQLのシステムスキーマを含む論理バックアップをホストへ保存
docker compose exec -T mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --events --all-databases' > mysql-8.0-backup.sql

# コンテナを正常停止する。名前付きボリュームは削除しない
docker compose down

# 8.4イメージを取得して、既存ボリュームで起動
docker compose pull mysql
docker compose up -d
docker compose logs mysql
```

実行前に、MySQL Shellの `util.checkForServerUpgrade()` で8.4への適合性を確認してください。起動後は `docker compose exec laravel.test php artisan migrate` とテストスイートを実行し、アプリケーションを確認してください。アップグレードに失敗した場合や8.0へ戻す必要がある場合は、8.4で更新済みのボリュームを8.0で起動しないでください。8.0用の新しい空ボリュームを作成してバックアップを復元するか、アップグレード前のボリュームスナップショットへ戻してください。

YOLP APIのURLは `.env.example` に設定済みです。住所検索・ジオコード機能を利用する場合は、取得したClient IDだけを `.env` の `YOLP_CLIENT_ID` に設定してください。

```dotenv
YOLP_URL=https://map.yahooapis.jp/search/local/V1/localSearch
YOLP_GEOCODE_URL=https://map.yahooapis.jp/geocode/V1/geoCoder
YOLP_CLIENT_ID=<your-client-id>
```

YOLP設定は `config/services.php` を通して共通APIクライアントから参照されます。検索と駐輪場登録のAPI通信には、共通のタイムアウト・リトライ・レスポンス変換が適用されます。

### 広告（Google AdSense）

広告は初期状態で無効です。手動で配置するのは、ホームの新着一覧の後、駐輪場詳細の補足情報の後、検索結果一覧の直後だけです。検索画面では検索操作・検索結果のカード・地図を覆わず、一覧とサイトフッターの間に表示します。追従表示・全画面表示・自動挿入は使用せず、フォーム・ログイン画面には表示しません。

AdSense のサイト審査中は、広告枠を出さずに審査用スクリプトと `ads.txt` だけを公開できます。本番環境に次を設定してください。

```dotenv
ADVERTISING_ENABLED=false
ADSENSE_VERIFICATION_ENABLED=true
ADSENSE_CLIENT=ca-pub-<your-publisher-id>
```

公開後、ページのHTMLに AdSense スクリプトが含まれることと、`/ads.txt` が `google.com, pub-<your-publisher-id>, DIRECT, f08c47fec0942fa0` を返すことを確認してから、AdSense 管理画面で審査を申請してください。審査後は `ADSENSE_VERIFICATION_ENABLED=false` に戻します。

AdSense の審査完了後に、発行されたサイト運営者 ID と各広告ユニットのスロット ID を設定してください。

```dotenv
ADVERTISING_ENABLED=true
ADSENSE_CLIENT=ca-pub-1234567890123456
ADSENSE_SLOT_HOME_FOOTER=1234567890
ADSENSE_SLOT_PARKING_SPOT_FOOTER=0987654321
ADSENSE_SLOT_SEARCH_FOOTER=9876543210
```

開発環境で広告枠の余白・レスポンシブ表示だけを確認する場合は、実AdSenseへ通信しないプレースホルダーを使用できます。`ADSENSE_*` の設定は不要です。

```dotenv
ADVERTISING_ENABLED=true
ADVERTISING_TEST_MODE=true
```

この設定では「広告（開発用）」と `AD PREVIEW` を表示し、AdSense スクリプトや広告リクエストは出力しません。本番で実広告を配信する際は `ADVERTISING_TEST_MODE=false` にしてください。

### お問い合わせフォーム

フッターの「お問い合わせ」ページには、Googleフォームへのリンクと送信先を表示します。送信先メールアドレスは、環境変数で変更できます。

```dotenv
CONTACT_EMAIL=bikeparking819.app@gmail.com
```

設定後は本番サイトの `/ads.txt` で AdSense 用のレコードが返ることも確認してください。広告を有効化する前に、`/privacy` の内容が実際の広告配信事業者と利用者の地域に必要な同意要件に合っていることを確認してください。

## 開発コマンド

```bash
# Laravelコンテナの起動・停止
docker compose up -d
docker compose down

# マイグレーション
docker compose exec laravel.test php artisan migrate

# コード整形
docker compose exec laravel.test vendor/bin/pint

# コード整形の確認（ファイルは変更しない）
docker compose exec laravel.test vendor/bin/pint --test

# 静的解析
docker compose exec laravel.test composer analyse

# テスト全体
docker compose exec laravel.test php artisan test

# 本番用フロントエンドアセットのビルド
docker compose exec laravel.test npm run build

# 24時間以上経過した確認画面用の一時画像を削除
docker compose exec laravel.test php artisan parking-spots:prune-temporary-images --hours=24

# 日本郵便から最新の郵便番号データを取得して同期
docker compose exec laravel.test php artisan postal-codes:sync
```

Laravel Sailのショートカットを利用できる環境では、上記の `docker compose exec laravel.test` を `./vendor/bin/sail` に置き換えられます。
一時画像の削除コマンドはLaravelのスケジューラにも1時間ごとで登録されています。本番・開発サーバーではスケジューラプロセスを常時実行してください。

### CIと同等の確認

Pull Requestと`main`へのpushでは、GitHub Actionsの`CI/CD`ワークフローがPHP 8.5とNode.js 22を使用し、npm依存の脆弱性監査（Low以上を失敗扱い）、Pint、Larastan、Feature・Unitテスト、フロントエンドビルドを実行します。Composerとnpmのダウンロードキャッシュは、それぞれ`composer.lock`と`package-lock.json`に応じて更新されます。

ローカルでは次のコマンドで同等の確認を実行できます。

```bash
./vendor/bin/sail pint --test
./vendor/bin/sail composer analyse
./vendor/bin/sail test
./vendor/bin/sail npm ci
./vendor/bin/sail npm audit --audit-level=low
./vendor/bin/sail npm run build
```

CIが失敗した場合は、GitHubの`Actions`から対象の`CI/CD`実行を開き、失敗したジョブとステップのログを確認してください。上記の対応するコマンドをローカルで再実行し、依存関係のインストールで失敗した場合は`composer.lock`または`package-lock.json`の差分と、その直前に表示されたエラーを確認します。

郵便番号同期は、日本郵便が公開する1レコード1行のUTF-8版ZIPをダウンロードし、内容を検証してから都道府県・市区町村・郵便番号をトランザクション内で更新します。`storage/app/private/x-ken-all.csv` の手動配置は不要です。廃止された郵便番号は、既存の駐輪場との関連を保つため削除せず無効化します。同期コマンドは毎月2日3時にも自動実行されます。ダウンロード元を変更する場合だけ `JAPAN_POST_POSTAL_CODE_URL` を設定してください。

`DatabaseSeeder` は都道府県と100件のサンプルユーザーを常に生成します。`storage/app/private/postalcode.csv` が読み込める場合は、その郵便番号・住所・緯度経度データから全都道府県へ均等に割り当てた10,000件の駐輪場サンプルも生成します。CSVがない環境では駐輪場サンプルだけをスキップするため、`php artisan migrate:fresh --seed` は外部データなしでも完了します。パスを変更する場合は `PARKING_SPOT_SAMPLE_POSTALCODE_CSV` を設定します。SeederはCSVをストリーム処理するため、全国データをメモリへ全件読み込みません。

料金表示・入力・保存に関する変更では、まず次のfocused testを実行してください。

```bash
./vendor/bin/sail test tests/Feature/ParkingSpotRateDisplayTest.php
```

## ドキュメント

- [簡易設計書](doc/design.md)
- [状態遷移図](doc/state-transition.md)
- [開発サーバーへのデプロイ](doc/deployment.md)

設計書と状態遷移図はMarkdownとMermaidで管理しているため、GitHub上またはMermaid対応エディタで確認できます。

## ディレクトリ構成

```text
app/
├── Domain/               料金帯などのドメインルール
├── Http/Controllers/     HTTPリクエストと画面遷移
├── Http/Requests/        入力値検証
├── Livewire/             住所補完などのリアクティブ処理
├── Models/               Eloquentモデル
├── Services/             保存更新・ジオコード・画像などの業務処理
└── ValueObjects/         サービス間で受け渡す型付き処理結果
database/                 マイグレーション、ファクトリ、シーダー
resources/views/          Bladeテンプレート
resources/js/             Leaflet地図・料金フォームなどの共通フロントエンド処理
routes/                   Web・認証ルート
tests/                    Feature / Unitテスト
doc/                      設計書・状態遷移図
```

## ライセンス

このプロジェクトはMITライセンスで公開されています。
