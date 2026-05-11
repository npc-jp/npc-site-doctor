<?php
/**
 * 通知クラス
 * critical検出時にメール送信する責務を持つ
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPC_SD_Notifier {

    /**
     * 診断結果から「critical扱いの項目」を抽出
     * 通知すべき深刻な問題があるかを判定するための正規化ロジック
     *
     * @param array $results NPC_SD_Checker::run_all_checks() の返り値
     * @return array critical項目の配列 [{key, label, detail}, ...]
     */
    public static function detect_critical_issues( $results ) {
        $issues = array();

        // ファイル改ざん: 不審コードが出たら最優先
        $fi = $results['file_integrity'] ?? array();
        if ( ! empty( $fi['has_danger'] ) ) {
            $details = array();
            foreach ( array_merge( $fi['modified_files'] ?? array(), $fi['suspect_files'] ?? array() ) as $f ) {
                if ( ! empty( $f['has_danger'] ) ) {
                    $details[] = $f['file'] . ' (' . implode( ',', $f['danger_patterns'] ?? array() ) . ')';
                }
            }
            $issues[] = array(
                'key'    => 'file_integrity_danger',
                'label'  => __( 'Possible file tampering (suspicious code detected)', 'npc-site-doctor' ),
                'detail' => implode( "\n", $details ),
            );
        }

        // 不審ファイル: uploads内のPHP
        $sf = $results['suspicious_files'] ?? array();
        if ( ! empty( $sf['suspicious_count'] ) && $sf['suspicious_count'] > 0 ) {
            $issues[] = array(
                'key'    => 'suspicious_files',
                'label'  => __( 'Suspicious PHP files in uploads directory', 'npc-site-doctor' ),
                'detail' => implode( "\n", array_slice( $sf['suspicious_files'] ?? array(), 0, 10 ) ),
            );
        }

        // SSL: 期限切れ or 14日以下
        $ssl = $results['ssl_certificate'] ?? array();
        if ( ( $ssl['status'] ?? '' ) === 'critical' ) {
            $detail = isset( $ssl['days_left'] )
                ? sprintf(
                    /* translators: 1: days remaining until expiry, 2: expiry date in YYYY-MM-DD */
                    __( '%1$d days remaining (expires: %2$s)', 'npc-site-doctor' ),
                    (int) $ssl['days_left'],
                    $ssl['expires_at']
                )
                : ( $ssl['note'] ?? '' );
            $issues[] = array(
                'key'    => 'ssl_expiring',
                'label'  => __( 'SSL certificate is expiring soon', 'npc-site-doctor' ),
                'detail' => $detail,
            );
        }

        // サイトヘルスのcritical
        $sh = $results['site_health'] ?? array();
        if ( ! empty( $sh['critical_count'] ) && $sh['critical_count'] > 0 ) {
            $labels = array();
            foreach ( $sh['issues']['critical'] ?? array() as $issue ) {
                $labels[] = $issue['label'] ?? '';
            }
            $issues[] = array(
                'key'    => 'site_health_critical',
                'label'  => sprintf(
                    /* translators: %d: number of critical Site Health issues */
                    __( 'Site Health critical issues: %d', 'npc-site-doctor' ),
                    (int) $sh['critical_count']
                ),
                'detail' => implode( "\n", $labels ),
            );
        }

        return $issues;
    }

    /**
     * 通知を送信
     *
     * @param string $email      送信先メアド
     * @param array  $results    診断結果
     * @param array  $issues     detect_critical_issues() の結果
     * @param string $ai_report  生成済みAIレポート（任意、空でも可）
     * @return bool 送信成否
     */
    public static function send( $email, $results, $issues, $ai_report = '' ) {
        if ( empty( $email ) || empty( $issues ) ) {
            return false;
        }

        $site_name   = $results['site_info']['site_name'] ?? get_bloginfo( 'name' );
        $site_url    = $results['site_info']['site_url'] ?? get_site_url();
        $admin_url   = admin_url( 'admin.php?page=npc-site-doctor' );
        $count       = count( $issues );

        $subject = sprintf(
            /* translators: 1: site name, 2: number of critical issues */
            __( '[NPC Site Doctor] %1$s detected %2$d critical issue(s)', 'npc-site-doctor' ),
            $site_name,
            $count
        );

        $lines   = array();
        $lines[] = sprintf(
            /* translators: 1: site name, 2: site URL */
            __( 'An automated health-check on %1$s (%2$s) found issues that need attention.', 'npc-site-doctor' ),
            $site_name,
            $site_url
        );
        $lines[] = '';
        $lines[] = '── ' . __( 'Detected issues', 'npc-site-doctor' ) . ' ──';

        foreach ( $issues as $i => $issue ) {
            $num = $i + 1;
            $lines[] = "";
            $lines[] = "{$num}. {$issue['label']}";
            if ( ! empty( $issue['detail'] ) ) {
                foreach ( explode( "\n", $issue['detail'] ) as $d ) {
                    $lines[] = "   - {$d}";
                }
            }
        }

        $lines[] = '';
        $lines[] = '── ' . __( 'How to respond', 'npc-site-doctor' ) . ' ──';
        $lines[] = __( 'See the full report in the admin dashboard:', 'npc-site-doctor' );
        $lines[] = $admin_url;

        if ( ! empty( $ai_report ) ) {
            $lines[] = '';
            $lines[] = '── ' . __( 'AI diagnostic report', 'npc-site-doctor' ) . ' ──';
            // AIレポートからタグを除去してプレーンテキスト化
            $plain = self::ai_report_to_plain( $ai_report );
            $lines[] = $plain;
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = __( 'This email was sent by the NPC Site Doctor automated health-check.', 'npc-site-doctor' );
        $lines[] = __( 'To stop these notifications, go to NPC Site Doctor > Settings and disable automatic diagnostics.', 'npc-site-doctor' );

        $body = implode( "\n", $lines );

        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );

        return wp_mail( $email, $subject, $body, $headers );
    }

    /**
     * AIレポートの構造化タグを除去してプレーンテキストに変換
     * メール本文用（HTMLを使わないので最小限の整形）
     */
    private static function ai_report_to_plain( $report ) {
        $text = $report;
        // タグ除去
        $text = preg_replace( '/\[\/?SUMMARY\]/u', '', $text );
        $text = preg_replace( '/\[SECTION:[^\]]+\]/u', '', $text );
        $text = preg_replace( '/\[\/SECTION\]/u', '', $text );
        $text = preg_replace( '/\[STATUS:([^\]]+)\]/u', '[$1]', $text );
        // Markdownの見出しマーカーを維持（見やすいので）
        // 連続改行を整理
        $text = preg_replace( "/\n{3,}/u", "\n\n", $text );
        return trim( $text );
    }
}
