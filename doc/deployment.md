# KAGOYA Cloud 本番デプロイ

本番はGitHub Container Registry（GHCR）上のDockerイメージを、GitHub Actionsから `/opt/motolotz` に配置したDocker Compose構成として稼働させます。`main` へのマージでイメージのビルドとデプロイが自動実行されます。

## サーバー準備

Ubuntu x86_64に Docker Engine、Docker Compose plugin、Nginx、Certbotを導入します。デプロイユーザーがDockerを実行できるようにします。`/opt/motolotz/.env` は、デプロイ時にGitHub Actionsが生成して600権限で配置するため、Git管理もサーバーでの手作業作成も不要です。

```bash
sudo install -d -o <deploy-user> -g <deploy-user> /opt/motolotz
```

GitHubの `production` Environment に次を登録します。アプリケーションの秘匿値は **Secrets**、公開してもよい設定値は **Variables** に分けます。配置先はワークフローで `/opt/motolotz` に固定されています。

| 種別 | 名前 | 用途 |
| --- | --- | --- |
| Secret | `DEPLOY_SSH_PRIVATE_KEY` | デプロイ用SSH秘密鍵 |
| Secret | `DEPLOY_KNOWN_HOSTS` | 接続先SSHホスト鍵 |
| Secret | `PRODUCTION_APP_KEY` | Laravelの `APP_KEY` |
| Secret | `PRODUCTION_DB_PASSWORD` | アプリケーションMySQLユーザーのパスワード |
| Secret | `PRODUCTION_MYSQL_ROOT_PASSWORD` | MySQL rootパスワード |
| Secret | `PRODUCTION_YOLP_CLIENT_ID` | Yahoo!ローカルサーチAPI Client ID。未利用なら空でも可 |
| Variable | `DEPLOY_HOST` | サーバーIPまたはホスト名 |
| Variable | `DEPLOY_USER` | SSHユーザー |
| Variable | `DEPLOY_PORT` | SSHポート |
| Variable | `PRODUCTION_APP_URL` | `https://motolotz.com`（未登録時もこの値） |
| Variable | `PRODUCTION_DB_DATABASE` | `motolotz`（未登録時もこの値） |
| Variable | `PRODUCTION_DB_USERNAME` | `motolotz`（未登録時もこの値） |
| Variable | `PRODUCTION_YOLP_URL` | ローカル検索API URL（必須） |
| Variable | `PRODUCTION_YOLP_GEOCODE_URL` | ジオコーダーAPI URL（必須） |
| Variable | `PRODUCTION_JAPAN_POST_POSTAL_CODE_URL` | 日本郵便の郵便番号ZIP URL（必須） |
| Variable | `PRODUCTION_QUERY_LOG_SLOW_MS` | 遅延クエリの記録しきい値ms（未登録時は500） |
| Variable | `PRODUCTION_QUERY_LOG_SAMPLE_RATE` | 遅延クエリの記録率、0〜1（未登録時は0.1） |
| Variable | `PRODUCTION_QUERY_LOG_MAX_PER_PROCESS` | PHPプロセスあたりの最大記録件数（未登録時は10） |

Actionsはこれらから `.env` を一時生成して `/opt/motolotz/.env` に転送します。値をログ出力せず、GitHub Actionsランナーの一時ファイルはジョブ終了時に削除されます。

## 公開とTLS

UFWは80/443と管理元限定SSHだけを許可します。Nginx設定 [motolotz.com.conf](../deploy/nginx/motolotz.com.conf) を `/etc/nginx/sites-available/` へ配置して有効化します。Nginxはホストの `127.0.0.1:8000` で待ち受けるアプリコンテナへリバースプロキシします。

DNSのA/AAAAレコードをKAGOYAサーバーへ向けた後、HTTP設定を有効にしてCertbotを実行します。

```bash
sudo ln -s /etc/nginx/sites-available/motolotz.com.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d motolotz.com -d www.motolotz.com
sudo certbot renew --dry-run
sudo systemctl enable --now certbot.timer
```

キューとスケジューラはDocker Composeの `worker`・`scheduler` サービスとして常駐します。SupervisorやPHP-FPMの追加設定は不要です。

## ログ監視

Compose の `alloy` サービスは、共有ストレージの Laravel ログとホストの Nginx access/error ログを Raspberry Pi の Loki へ送信します。送信先は GitHub の production 環境変数 `PRODUCTION_LOKI_URL` で管理し、Tailscale URL（例: `http://100.79.190.75:3100/loki/api/v1/push`）を設定します。デプロイスクリプトはホストの `adm` グループGIDを検出し、Alloy の補助グループへ追加するため、Alloyはroot化せずにNginxログを読み取れます。Alloy の管理ポートはコンテナ内の loopback にだけバインドされます。

Laravel の `daily` ログは JSON Lines 形式で出力され、14日でローテーションされます。コンテキスト内のパスワード、Cookie、トークン、認可ヘッダー、API キー、secret を保存前に `[REDACTED]` へ置換します。Alloy も Nginx ログを含む全送信行へ同等のマスキングを適用します。

`query` ログは遅延クエリだけを対象にし、500 ms以上、10%サンプリング、各PHPプロセスあたり最大10件で記録します。SQLのバインド値は記録せず、リテラル値も `?` に置換します。必要な場合は GitHub の production Environment で `QUERY_LOG_SLOW_MS`、`QUERY_LOG_SAMPLE_RATE`、`QUERY_LOG_MAX_PER_PROCESS` を調整して再デプロイします。

デプロイ後は、Pi の Grafana Explore で Loki を選び、次の LogQL で送信を確認します。

```logql
{application="motolotz", environment="production"}
```

`job="laravel"`、`job="laravel-query"`、`job="nginx-access"`、`job="nginx-error"` でログ種別を、`host` で送信元ホストを絞り込めます。たとえば遅延クエリは `{application="motolotz", environment="production", job="laravel-query"}` で検索できます。Alloy の稼働状態は本番サーバーで `docker ps --filter name=motolotz-alloy-1` と `docker logs --tail=100 motolotz-alloy-1` を確認し、停止時は `docker restart motolotz-alloy-1` で復旧します。Pi の Tailscale IP を変更した場合は、GitHub の `PRODUCTION_LOKI_URL` を更新して再デプロイします。

## リリースと運用

PRでは **CI** が品質チェック・テスト・アセットビルドを実行します。`main`へのマージ後、**Deploy production** がGHCRイメージを取得してComposeサービスを更新し、マイグレーションと `/up` のヘルスチェックを実行します。必要時は同ワークフローを `main` から手動実行して再デプロイできます。

毎日、MySQL Docker volumeの論理バックアップと`app-storage` volumeを暗号化してサーバー外へ保存し、初回リリース前に復元を検証します。マイグレーションは前方互換にします。Issue #170はHTTPS、主要機能、バックアップ復元、監視を確認してからクローズします。
