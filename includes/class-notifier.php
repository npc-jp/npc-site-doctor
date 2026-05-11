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
                    $details[] = $f['file'] . '（' . implode( ',', $f['danger_patterns'] ?? array() ) . '）';
                }
            }
            $issues[] = array(
                'key'    => 'file_integrity_danger',
                'label'  => 'ファイル改ざんの疑い（不審コード検出）',
                'detail' => implode( "\n", $details ),
            );
        }

        // 不審ファイル: uploads内のPHP
        $sf = $results['suspicious_files'] ?? array();
        if ( ! empty( $sf['suspicious_count'] ) && $sf['suspicious_count'] > 0 ) {
            $issues[] = array(
                'key'    => 'suspicious_files',
                'label'  => 'uploads内に不審なPHPファイル',
                'detail' => implode( "\n", array_slice( $sf['suspicious_files'] ?? array(), 0, 10 ) ),
            );
        }

        // SSL: 期限切れ or 14日以下
        $ssl = $results['ssl_certificate'] ?? array();
        if ( ( $ssl['status'] ?? '' ) === 'critical' ) {
            $detail = isset( $ssl['days_left'] )
                ? "残り{$ssl['days_left']}日（期限: {$ssl['expires_at']}）"
                : ( $ssl['note'] ?? '' );
            $issues[] = array(
                'key'    => 'ssl_expiring',
                'label'  => 'SSL証明書の期限が近い',
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
                'label'  => "サイトヘルスの致命的問題 {$sh['critical_count']}件",
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

        $subject = sprintf( '[WP Healthcheck] %s で%d件の重大な問題を検出', $site_name, $count );

        $lines   = array();
        $lines[] = sprintf( '%s（%s）で自動診断を実行したところ、対応が必要な問題が見つかりました。', $site_name, $site_url );
        $lines[] = '';
        $lines[] = '── 検出された問題 ──';

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
        $lines[] = '── 対応方法 ──';
        $lines[] = '詳細レポートは管理画面からご確認ください:';
        $lines[] = $admin_url;

        if ( ! empty( $ai_report ) ) {
            $lines[] = '';
            $lines[] = '── AIによる診断レポート ──';
            // AIレポートからタグを除去してプレーンテキスト化
            $plain = self::ai_report_to_plain( $ai_report );
            $lines[] = $plain;
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = 'このメールは NPC WP Healthcheck の自動診断から送信されました。';
        $lines[] = '通知を止めるには、管理画面 > Healthcheck > 設定 から自動診断をOFFにしてください。';

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
