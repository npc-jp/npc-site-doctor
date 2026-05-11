<?php
/**
 * AIレポート生成クラス
 * 診断結果をClaude APIに送り、日本語の修正提案レポートを生成する
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPC_AI_Reporter {

    /** @var string Claude APIのエンドポイント */
    private $api_url = 'https://api.anthropic.com/v1/messages';

    /** @var string 使用モデル（コスト重視でSonnet） */
    private $model = 'claude-sonnet-4-20250514';

    /**
     * 診断結果からAIレポートを生成
     *
     * @param array $results NPC_Checker::run_all_checks() の返り値
     * @return string|WP_Error レポートHTML or エラー
     */
    public function generate( $results ) {
        // AI機能の利用可否を判定（wp-config.php定数 or DB旧キー、フィルタ対応）
        if ( ! NPC_WP_Healthcheck::is_ai_available() ) {
            return new WP_Error( 'ai_disabled', 'AIレポート機能は無効です。wp-config.php に NPC_HEALTHCHECK_API_KEY を設定してください。' );
        }

        // 実際のキー値を取得（is_ai_available() で空でないことは保証済み）
        $api_key = NPC_WP_Healthcheck::get_api_key();

        // 診断結果をプロンプト用のテキストに整形
        $diagnosis_text = $this->format_results_for_prompt( $results );

        // Claude APIに送信
        $response = wp_remote_post( $this->api_url, array(
            'timeout' => 60, // AIの応答は時間がかかる場合がある
            'headers' => array(
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ),
            'body' => wp_json_encode( array(
                'model'      => $this->model,
                'max_tokens' => 2000,
                'messages'   => array(
                    array(
                        'role'    => 'user',
                        'content' => $this->build_prompt( $diagnosis_text ),
                    ),
                ),
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'api_error', 'Claude APIへの接続に失敗: ' . $response->get_error_message() );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status_code !== 200 ) {
            $error_msg = $body['error']['message'] ?? "APIエラー (HTTP {$status_code})";
            return new WP_Error( 'api_error', $error_msg );
        }

        // レスポンスからテキストを抽出
        $report_text = $body['content'][0]['text'] ?? '';

        if ( empty( $report_text ) ) {
            return new WP_Error( 'empty_response', 'AIからの応答が空でした。' );
        }

        return $report_text;
    }

    /**
     * 診断結果をプロンプト用テキストに整形
     * AIが読みやすい形式にまとめる
     */
    private function format_results_for_prompt( $results ) {
        $lines = array();

        // サイト基本情報
        $info = $results['site_info'];
        $lines[] = "【サイト情報】";
        $lines[] = "- URL: {$info['site_url']}";
        $lines[] = "- サイト名: {$info['site_name']}";
        $lines[] = "- WPバージョン: {$info['wp_version']}";
        $lines[] = "- テーマ: {$info['theme']}";

        // WP本体更新
        $core = $results['core_updates'];
        $lines[] = "\n【WordPress本体】 判定: {$core['status']}";
        $lines[] = "- 現在: {$core['current_version']}";
        $lines[] = "- 状態: " . ( $core['status'] === 'ok' ? '最新' : "更新あり ({$core['latest_version']})" );

        // プラグイン更新
        $plugins = $results['plugin_updates'];
        $lines[] = "\n【プラグイン】 判定: {$plugins['status']}";
        $lines[] = "- 全{$plugins['total_plugins']}件（有効: {$plugins['active_plugins']}件）";
        $lines[] = "- 更新が必要: {$plugins['updates_count']}件";
        foreach ( $plugins['updates_needed'] as $p ) {
            $lines[] = "  - {$p['name']}: {$p['current_version']} → {$p['new_version']}";
        }

        // サイトヘルス
        $health = $results['site_health'];
        $health_status = $health['critical_count'] > 0 ? 'critical' : ( $health['recommended_count'] > 0 ? 'warning' : 'ok' );
        $lines[] = "\n【サイトヘルス】 判定: {$health_status}";
        $lines[] = "- 致命的: {$health['critical_count']}件";
        $lines[] = "- 推奨: {$health['recommended_count']}件";
        $lines[] = "- 良好: {$health['good_count']}件";
        foreach ( $health['issues']['critical'] as $issue ) {
            $lines[] = "  - [致命的] {$issue['label']}";
        }
        foreach ( $health['issues']['recommended'] as $issue ) {
            $lines[] = "  - [推奨] {$issue['label']}";
        }

        // PHP環境
        $php = $results['php_version'];
        $lines[] = "\n【サーバー環境】 判定: {$php['status']}";
        $lines[] = "- PHP: {$php['php_version']}";
        $lines[] = "- サーバー: {$php['server_software']}";
        $lines[] = "- メモリ上限: {$php['max_memory']}";
        $lines[] = "- 最大アップロード: {$php['max_upload']}";

        // エラーログ
        $log = $results['error_log'];
        $lines[] = "\n【エラーログ】 判定: {$log['status']}";
        if ( ! $log['exists'] ) {
            $lines[] = "- debug.logなし";
        } else {
            $lines[] = "- ファイルサイズ: {$log['file_size']}";
            $lines[] = "- ユニークエラー数: {$log['error_count']}件";
            foreach ( array_slice( $log['errors'], 0, 10 ) as $err ) {
                $lines[] = "  - {$err}";
            }
        }

        // ファイル改ざん検知
        $integrity = $results['file_integrity'];
        $lines[] = "\n【ファイル改ざん検知】 判定: {$integrity['status']}";

        if ( ! empty( $integrity['modified_files'] ) ) {
            $lines[] = "- チェックサムが一致しないコアファイル:";
            foreach ( $integrity['modified_files'] as $f ) {
                $lines[] = "  - {$f['file']}";
                if ( $f['has_danger'] ) {
                    $lines[] = "    ⚠ 不審なコードパターン検出:";
                    foreach ( $f['danger_patterns'] as $pattern ) {
                        $lines[] = "      - {$pattern}";
                    }
                } else {
                    $lines[] = "    → eval(), base64_decode() 等の不審コードは検出されず";
                }
            }
        }

        if ( ! empty( $integrity['suspect_files'] ) ) {
            $lines[] = "- チェックサムずれ（誤検知の可能性があるファイル）:";
            foreach ( $integrity['suspect_files'] as $f ) {
                $lines[] = "  - {$f['file']}";
                if ( $f['has_danger'] ) {
                    $lines[] = "    ⚠ 不審なコードパターン検出:";
                    foreach ( $f['danger_patterns'] as $pattern ) {
                        $lines[] = "      - {$pattern}";
                    }
                } else {
                    $lines[] = "    → eval(), base64_decode() 等の不審コードは検出されず（安全）";
                }
            }
        }

        if ( ! empty( $integrity['note'] ) ) {
            $lines[] = "- 補足: {$integrity['note']}";
        }

        // 不審ファイル
        $suspicious = $results['suspicious_files'];
        $lines[] = "\n【不審ファイル検出】 判定: {$suspicious['status']}";
        $lines[] = "- 検出数: {$suspicious['suspicious_count']}件";
        foreach ( array_slice( $suspicious['suspicious_files'], 0, 10 ) as $f ) {
            $lines[] = "  - {$f}";
        }

        // SSL
        $ssl = $results['ssl_certificate'];
        $lines[] = "\n【SSL証明書】 判定: {$ssl['status']}";
        if ( isset( $ssl['expires_at'] ) ) {
            $lines[] = "- 有効期限: {$ssl['expires_at']}（残り{$ssl['days_left']}日）";
        }
        if ( isset( $ssl['note'] ) ) {
            $lines[] = "- 備考: {$ssl['note']}";
        }

        // ファイルパーミッション
        $perms = $results['file_permissions'];
        $lines[] = "\n【ファイルパーミッション】 判定: {$perms['status']}";
        foreach ( $perms['checks'] as $check ) {
            $mark = $check['status'] === 'ok' ? '✓' : '✗';
            $lines[] = "- {$mark} {$check['path']}: {$check['current']}（推奨: {$check['recommended']}）";
        }

        return implode( "\n", $lines );
    }

    /**
     * AIに送るプロンプトを組み立てる
     */
    private function build_prompt( $diagnosis_text ) {
        return <<<PROMPT
あなたはWordPressの保守担当エンジニアです。
以下のサイト診断結果を分析し、保守レポートを日本語で作成してください。

## 重要: 重大度の判定ルール
各セクションに「判定: ok / warning / critical」が記載されています。
レポートの重大度分類は**必ずこの判定値に従ってください**。独自に格上げ・格下げしないこと。
- ok → 緑（正常）
- warning → 黄（注意）
- critical → 赤（要対応）

## 出力フォーマット（厳守）
以下のフォーマットで出力してください。`[STATUS:xx]` タグは必ず付けてください。

```
[SUMMARY]
総合評価: X（A〜D）
（一言コメント）
[/SUMMARY]

[SECTION:critical]
## 今すぐ対応が必要な項目
（criticalの項目をここに。なければ「該当なし」）
[/SECTION]

[SECTION:warning]
## 早めの対応を推奨する項目
（warningの項目をここに。なければ「該当なし」）
[/SECTION]

[SECTION:ok]
## 問題なしの項目
（okの項目をここに）
[/SECTION]

[SECTION:action]
## 具体的な対応手順
（対応が必要な項目ごとに手順を記載。各手順の冒頭に [STATUS:critical] または [STATUS:warning] を付ける）
[/SECTION]
```

## 注意事項
- クライアント（非エンジニア）にも理解できる平易な日本語で書く
- 技術用語を使う場合は簡単な説明を添える
- 対応手順は具体的に（「〇〇画面から△△をクリック」レベルで）
- 問題がない場合も「正常です」と明記する（安心材料になる）
- 各項目名の前に [STATUS:ok] [STATUS:warning] [STATUS:critical] タグを必ず付ける
- 対応手順でWordPressの管理画面操作を案内する場合、実際にその操作が可能かどうかを正確に書くこと。例: テーマエディターでは既存ファイルの編集のみ可能で、新規ファイルの作成はできない。ファイルの新規追加にはFTPやファイルマネージャーが必要
- 「〇〇すればよい」と書く場合、その操作がWordPressの標準機能で本当にできるか確認してから書く

## 既に運用中のサイトでは推奨しない事項（重要）
以下は「ベストプラクティス」として一見良さそうに見えるが、**運用中サイトでは安易に推奨してはいけない**。
警告として表示はするが、**警告本文の中に「運用中サイトでは現状維持を推奨します」という補足を必ず一文添える**こと。
「投稿名に変更してください」「カスタム構造を選択してください」のような具体的な操作手順は書かない。

### パーマリンク構造の変更
出力例（warning セクションでの書き方）:
```
[STATUS:warning] パーマリンク設定について
現在のURL構造に投稿名が含まれていません。
ただし、運用中サイトでのパーマリンク変更は既存記事のURL変更によりSEO評価リセット・外部リンク切れ・ブックマーク失効のリスクがあるため、現状維持を推奨します。SEO面の改善は他の項目（コンテンツ・内部リンク・サイト速度）で対応するのが安全です。
```
具体的な対応手順セクション（[SECTION:action]）には、パーマリンクは**含めない**（現状維持なので対応不要）。

### テーマの更新
警告本文の最後に「カスタム改修されている可能性があるため、現状維持を推奨します」を必ず添える。対応手順は書かない。

### 不審ファイル検知の安易な削除指示
警告本文の最後に「ファイル内容を確認してから判断してください。プラグイン正規ファイル（Ajax Load More の alm_templates / WP STAGING の index.php / AIOS の firewall-rules 等）の場合は削除しないこと」と添える。

### データベース最適化プラグインの導入提案
診断項目になければ一切言及しない（自発的に提案しない）。

## サーバー側の制約で対処不可な項目（重要）
以下はクライアント側ではほぼ対処できない（共用レンタルサーバーではサーバー会社の対応待ち）。診断に出てきても**「優先度: 低」で簡潔に触れる程度に留め、対応手順は書かない**:
- **AVIF画像フォーマット未対応**: サーバーのPHP-GD / Imagick ライブラリのバージョン依存。共用サーバーではコントロール不可。WebPで十分実用的なため現状維持で問題なし
- **HTTP/2 / HTTP/3 未対応**: サーバー会社の設定依存
- **OPcache 設定**: サーバー会社の設定依存
- **memory_limit / max_execution_time の上限**: 共用サーバーではプラン依存

これらは「サーバー会社の今後のアップデートで対応される可能性があります」程度に留め、クライアントに不要な不安を与えないこと。

## 診断結果
{$diagnosis_text}
PROMPT;
    }
}
