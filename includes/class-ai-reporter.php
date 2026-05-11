<?php
/**
 * AI Report generator
 * Sends diagnosis results to the Claude API and gets back a maintenance report
 * in Japanese (the audience for this plugin is Japanese-speaking site owners).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPC_SD_AI_Reporter {

    /** @var string Claude API endpoint */
    private $api_url = 'https://api.anthropic.com/v1/messages';

    /** @var string Model name (Sonnet — chosen for cost/quality balance) */
    private $model = 'claude-sonnet-4-20250514';

    /**
     * Generate an AI maintenance report from diagnosis results.
     *
     * @param array $results NPC_SD_Checker::run_all_checks() return value.
     * @return string|WP_Error Report text or error.
     */
    public function generate( $results ) {
        // AI機能の利用可否を判定（wp-config.php定数 or DB旧キー、フィルタ対応）
        if ( ! NPC_SD_Plugin::is_ai_available() ) {
            return new WP_Error(
                'ai_disabled',
                __( 'AI report feature is disabled. Define NPC_SD_API_KEY in wp-config.php to enable it.', 'npc-site-doctor' )
            );
        }

        // 実際のキー値を取得（is_ai_available() で空でないことは保証済み）
        $api_key = NPC_SD_Plugin::get_api_key();

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
            return new WP_Error(
                'api_error',
                sprintf(
                    /* translators: %s: error message from wp_remote_post */
                    __( 'Failed to connect to the Claude API: %s', 'npc-site-doctor' ),
                    $response->get_error_message()
                )
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status_code !== 200 ) {
            $default_msg = sprintf(
                /* translators: %d: HTTP status code */
                __( 'API error (HTTP %d)', 'npc-site-doctor' ),
                (int) $status_code
            );
            $error_msg = $body['error']['message'] ?? $default_msg;
            return new WP_Error( 'api_error', $error_msg );
        }

        // レスポンスからテキストを抽出
        $report_text = $body['content'][0]['text'] ?? '';

        if ( empty( $report_text ) ) {
            return new WP_Error(
                'empty_response',
                __( 'The AI returned an empty response.', 'npc-site-doctor' )
            );
        }

        return $report_text;
    }

    /**
     * Format diagnosis results as text for the AI prompt.
     *
     * Output is English-labeled but values are passed through as-is.
     * The prompt tells the AI to respond in Japanese (target audience).
     */
    private function format_results_for_prompt( $results ) {
        $lines = array();

        // Site info
        $info    = $results['site_info'];
        $lines[] = '[Site Info]';
        $lines[] = "- URL: {$info['site_url']}";
        $lines[] = "- Name: {$info['site_name']}";
        $lines[] = "- WP Version: {$info['wp_version']}";
        $lines[] = "- Theme: {$info['theme']}";

        // Core updates
        $core    = $results['core_updates'];
        $lines[] = "\n[WordPress Core] status: {$core['status']}";
        $lines[] = "- Current: {$core['current_version']}";
        $lines[] = '- State: ' . ( $core['status'] === 'ok' ? 'Up to date' : "Update available ({$core['latest_version']})" );

        // Plugin updates
        $plugins = $results['plugin_updates'];
        $lines[] = "\n[Plugins] status: {$plugins['status']}";
        $lines[] = "- Total: {$plugins['total_plugins']} (active: {$plugins['active_plugins']})";
        $lines[] = "- Updates needed: {$plugins['updates_count']}";
        foreach ( $plugins['updates_needed'] as $p ) {
            $lines[] = "  - {$p['name']}: {$p['current_version']} -> {$p['new_version']}";
        }

        // Site Health
        $health        = $results['site_health'];
        $health_status = $health['critical_count'] > 0 ? 'critical' : ( $health['recommended_count'] > 0 ? 'warning' : 'ok' );
        $lines[]       = "\n[Site Health] status: {$health_status}";
        $lines[]       = "- Critical: {$health['critical_count']}";
        $lines[]       = "- Recommended: {$health['recommended_count']}";
        $lines[]       = "- Good: {$health['good_count']}";
        foreach ( $health['issues']['critical'] as $issue ) {
            $lines[] = "  - [critical] {$issue['label']}";
        }
        foreach ( $health['issues']['recommended'] as $issue ) {
            $lines[] = "  - [recommended] {$issue['label']}";
        }

        // PHP / server environment
        $php     = $results['php_version'];
        $lines[] = "\n[Server Environment] status: {$php['status']}";
        $lines[] = "- PHP: {$php['php_version']}";
        $lines[] = "- Server: {$php['server_software']}";
        $lines[] = "- Memory limit: {$php['max_memory']}";
        $lines[] = "- Max upload: {$php['max_upload']}";

        // Error log
        $log     = $results['error_log'];
        $lines[] = "\n[Error Log] status: {$log['status']}";
        if ( ! $log['exists'] ) {
            $lines[] = '- debug.log does not exist';
        } else {
            $lines[] = "- File size: {$log['file_size']}";
            $lines[] = "- Unique errors: {$log['error_count']}";
            foreach ( array_slice( $log['errors'], 0, 10 ) as $err ) {
                $lines[] = "  - {$err}";
            }
        }

        // File integrity
        $integrity = $results['file_integrity'];
        $lines[]   = "\n[File Integrity] status: {$integrity['status']}";

        if ( ! empty( $integrity['modified_files'] ) ) {
            $lines[] = '- Core files with checksum mismatch:';
            foreach ( $integrity['modified_files'] as $f ) {
                $lines[] = "  - {$f['file']}";
                if ( $f['has_danger'] ) {
                    $lines[] = '    [!] Suspicious code patterns detected:';
                    foreach ( $f['danger_patterns'] as $pattern ) {
                        $lines[] = "      - {$pattern}";
                    }
                } else {
                    $lines[] = '    -> No suspicious code (eval, base64_decode, etc.) detected';
                }
            }
        }

        if ( ! empty( $integrity['suspect_files'] ) ) {
            $lines[] = '- Checksum mismatches (possibly false-positive files):';
            foreach ( $integrity['suspect_files'] as $f ) {
                $lines[] = "  - {$f['file']}";
                if ( $f['has_danger'] ) {
                    $lines[] = '    [!] Suspicious code patterns detected:';
                    foreach ( $f['danger_patterns'] as $pattern ) {
                        $lines[] = "      - {$pattern}";
                    }
                } else {
                    $lines[] = '    -> No suspicious code (eval, base64_decode, etc.) detected (likely safe)';
                }
            }
        }

        if ( ! empty( $integrity['note'] ) ) {
            $lines[] = "- Note: {$integrity['note']}";
        }

        // Suspicious files
        $suspicious = $results['suspicious_files'];
        $lines[]    = "\n[Suspicious Files] status: {$suspicious['status']}";
        $lines[]    = "- Detected: {$suspicious['suspicious_count']}";
        foreach ( array_slice( $suspicious['suspicious_files'], 0, 10 ) as $f ) {
            $lines[] = "  - {$f}";
        }

        // SSL
        $ssl     = $results['ssl_certificate'];
        $lines[] = "\n[SSL Certificate] status: {$ssl['status']}";
        if ( isset( $ssl['expires_at'] ) ) {
            $lines[] = "- Expires: {$ssl['expires_at']} ({$ssl['days_left']} days remaining)";
        }
        if ( isset( $ssl['note'] ) ) {
            $lines[] = "- Note: {$ssl['note']}";
        }

        // File permissions
        $perms   = $results['file_permissions'];
        $lines[] = "\n[File Permissions] status: {$perms['status']}";
        foreach ( $perms['checks'] as $check ) {
            $mark    = $check['status'] === 'ok' ? '[OK]' : '[NG]';
            $lines[] = "- {$mark} {$check['path']}: {$check['current']} (recommended: {$check['recommended']})";
        }

        return implode( "\n", $lines );
    }

    /**
     * Build the prompt sent to the AI.
     *
     * Note: instruction is English but the AI is told to reply in Japanese
     * (this plugin's primary user base reads Japanese reports).
     */
    private function build_prompt( $diagnosis_text ) {
        $lines = array(
            'You are a WordPress maintenance engineer.',
            'Analyze the site diagnosis result below and write a maintenance report **in Japanese** (日本語で出力).',
            '',
            '## Important: severity rules',
            'Each section in the diagnosis has "status: ok / warning / critical".',
            'Your report must follow these status values exactly. Do not upgrade or downgrade severity on your own.',
            '- ok      => green (normal)',
            '- warning => yellow (caution)',
            '- critical => red (action needed)',
            '',
            '## Output format (strict)',
            'Use the following format. The `[STATUS:xx]` tags are required.',
            '',
            '```',
            '[SUMMARY]',
            '総合評価: X (A〜D)',
            '(brief one-line comment in Japanese)',
            '[/SUMMARY]',
            '',
            '[SECTION:critical]',
            '## 今すぐ対応が必要な項目',
            '(critical items. If none, write 「該当なし」.)',
            '[/SECTION]',
            '',
            '[SECTION:warning]',
            '## 早めの対応を推奨する項目',
            '(warning items. If none, write 「該当なし」.)',
            '[/SECTION]',
            '',
            '[SECTION:ok]',
            '## 問題なしの項目',
            '(ok items.)',
            '[/SECTION]',
            '',
            '[SECTION:action]',
            '## 具体的な対応手順',
            '(Per-item action steps. Prefix each item heading with [STATUS:critical] or [STATUS:warning].)',
            '[/SECTION]',
            '```',
            '',
            '## Writing guidelines',
            '- Write in plain Japanese understandable to non-engineers.',
            '- When using technical terms, add a short explanation.',
            '- Make action steps concrete (e.g. "Go to <screen> and click <button>").',
            '- Even when there is no issue, explicitly say "正常です" so the reader is reassured.',
            '- Prefix each item label with [STATUS:ok] / [STATUS:warning] / [STATUS:critical].',
            '- When suggesting WordPress admin operations, verify the operation is actually possible. For example, the theme editor only edits existing files; creating new files needs FTP or a file manager.',
            '- Only suggest "do X" if you have confirmed WordPress\'s standard UI actually supports X.',
            '',
            '## Items NOT recommended on running sites (important)',
            'The following look like "best practices" but should not be casually recommended on running sites.',
            'Show a warning, but in the warning body **always add a sentence saying "運用中サイトでは現状維持を推奨します"**.',
            'Do not write concrete operation steps like "投稿名に変更してください" or "カスタム構造を選択してください".',
            '',
            '### Permalink structure changes',
            'Example wording (in warning section):',
            '```',
            '[STATUS:warning] パーマリンク設定について',
            '現在のURL構造に投稿名が含まれていません。',
            'ただし、運用中サイトでのパーマリンク変更は既存記事のURL変更によりSEO評価リセット・外部リンク切れ・ブックマーク失効のリスクがあるため、現状維持を推奨します。SEO面の改善は他の項目（コンテンツ・内部リンク・サイト速度）で対応するのが安全です。',
            '```',
            'Do **not** include permalinks in [SECTION:action] (no action needed since we recommend status-quo).',
            '',
            '### Theme updates',
            'At the end of the warning body, always add: "カスタム改修されている可能性があるため、現状維持を推奨します". Do not write action steps.',
            '',
            '### Casual deletion of "suspicious files"',
            'At the end of the warning body, add: "ファイル内容を確認してから判断してください。プラグイン正規ファイル（Ajax Load More の alm_templates / WP STAGING の index.php / AIOS の firewall-rules 等）の場合は削除しないこと".',
            '',
            '### Database optimization plugins',
            'Do not mention them at all unless they appear in the diagnosis.',
            '',
            '## Items the client typically cannot fix (server-side constraints)',
            'The following are usually outside the client\'s control on shared hosting. Even if reported, treat them as "low priority" and **do not write action steps**:',
            '- **AVIF image format not supported**: depends on the server\'s PHP-GD / Imagick. Not controllable on shared hosting. WebP is sufficient in practice, so status-quo is fine.',
            '- **HTTP/2 / HTTP/3 not supported**: depends on the hosting provider.',
            '- **OPcache settings**: depends on the hosting provider.',
            '- **memory_limit / max_execution_time caps**: depends on shared hosting plan.',
            '',
            'For these, say something like "サーバー会社の今後のアップデートで対応される可能性があります" so you do not alarm the client unnecessarily.',
            '',
            '## Diagnosis result',
            $diagnosis_text,
        );
        return implode( "\n", $lines );
    }
}
