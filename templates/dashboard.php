<?php
/**
 * ダッシュボードテンプレート
 * 診断実行ボタン + 結果表示 + AIレポート生成 + 履歴アコーディオン
 *
 * AI機能（レポート生成）は NPC_SD_Plugin::is_ai_available() が false の時、
 * UI上から完全に消える。GitHub公開版（APIキー未設定）でも違和感なく使えるようにする。
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$ai_available = NPC_SD_Plugin::is_ai_available();
?>

<div class="wrap npc-site-doctor">
    <h1>WP Healthcheck</h1>

    <?php if ( ! $ai_available ) : ?>
        <!--
            AI機能が無効化されている時の案内バナー
            GitHub公開版で違和感が出ないよう「機能はオプション」というニュアンスで案内する。
            最後に保守契約への導線を控えめに添える。
        -->
        <div class="notice notice-info">
            <p>
                <strong>AI レポート機能は標準では無効です。</strong>
                有効化するには <code>wp-config.php</code> に以下を追加してください:
            </p>
            <pre style="background: #23282d; color: #eee; padding: 8px 12px; border-radius: 4px; margin: 4px 0; font-size: 12px;">define( 'NPC_SD_API_KEY', 'sk-ant-xxxx' );</pre>
            <p>
                AIレポートは
                <a href="https://console.anthropic.com/" target="_blank" rel="noopener">Anthropic API キー</a>
                があれば自分で有効化できます。設定が手間な場合は
                <a href="https://n-pc.jp/services/wp-maintenance" target="_blank" rel="noopener">npc の WP 保守契約</a>
                にお申し込みいただくと、APIキー設定込みでセットアップを代行します。
            </p>
        </div>
    <?php endif; ?>

    <!-- 操作ボタン -->
    <div class="npc-actions">
        <button id="npc-run-check" class="button button-primary button-hero">
            診断を実行
        </button>
        <?php if ( $ai_available ) : ?>
            <button id="npc-generate-report" class="button button-secondary button-hero">
                AIレポートを生成
            </button>
        <?php endif; ?>
        <button id="npc-download-report" class="button button-secondary button-hero" style="display:none;">
            レポートをダウンロード
        </button>
    </div>

    <!-- ローディング表示 -->
    <div id="npc-loading" style="display:none;">
        <span class="spinner is-active" style="float:none;"></span>
        <span id="npc-loading-text">診断を実行中...</span>
    </div>

    <!-- 今回の診断サマリヘッダー（スコア＋件数＋診断日時） -->
    <div id="npc-summary-header" style="display:none;"></div>

    <!-- 今回の診断結果エリア -->
    <div id="npc-results" style="display:none;">
        <h2>診断結果</h2>
        <div id="npc-results-grid" class="npc-grid"></div>
    </div>

    <!-- 今回のAIレポートエリア（AI機能無効時は表示しない） -->
    <?php if ( $ai_available ) : ?>
        <div id="npc-report" style="display:none;">
            <h2 id="npc-report-title">AIレポート</h2>
            <div id="npc-report-content" class="npc-report-content"></div>
        </div>
    <?php endif; ?>

    <!-- 診断履歴アコーディオン（直近10件） -->
    <div id="npc-history" style="display:none;">
        <h2>診断履歴</h2>
        <p class="npc-history-note">直近<?php echo (int) NPC_SD_HISTORY_LIMIT; ?>件を保持します。古いものから自動削除されます。</p>
        <div id="npc-history-list"></div>
    </div>
</div>
