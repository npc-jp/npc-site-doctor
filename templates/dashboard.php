<?php
/**
 * Dashboard template
 * Run diagnosis button + result display + AI report generation + history accordion
 *
 * The AI feature (report generation) is hidden completely from the UI when
 * NPC_SD_Plugin::is_ai_available() returns false. This makes the public version
 * (no API key) feel natural without dead buttons.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$ai_available = NPC_SD_Plugin::is_ai_available();
?>

<div class="wrap npc-site-doctor">
    <h1><?php esc_html_e( 'NPC Site Doctor', 'npc-site-doctor' ); ?></h1>

    <?php if ( ! $ai_available ) : ?>
        <!--
            Banner shown when the AI feature is disabled.
            Phrasing keeps it neutral ("the feature is optional") so the
            public/no-key build does not feel broken. A subtle link to the
            paid maintenance plan is added at the end.
        -->
        <div class="notice notice-info">
            <p>
                <strong><?php esc_html_e( 'The AI report feature is disabled by default.', 'npc-site-doctor' ); ?></strong>
                <?php esc_html_e( 'To enable it, add the following to wp-config.php:', 'npc-site-doctor' ); ?>
            </p>
            <pre style="background: #23282d; color: #eee; padding: 8px 12px; border-radius: 4px; margin: 4px 0; font-size: 12px;">define( 'NPC_SD_API_KEY', 'sk-ant-xxxx' );</pre>
            <p>
                <?php
                printf(
                    /* translators: 1: Anthropic API key link, 2: npc WP maintenance link */
                    esc_html__( 'You can enable the AI report yourself with an %1$s. If setup is troublesome, you can also subscribe to the %2$s and we will configure the API key for you.', 'npc-site-doctor' ),
                    '<a href="https://console.anthropic.com/" target="_blank" rel="noopener">' . esc_html__( 'Anthropic API key', 'npc-site-doctor' ) . '</a>',
                    '<a href="https://n-pc.jp/services/wp-maintenance" target="_blank" rel="noopener">' . esc_html__( 'npc WP maintenance plan', 'npc-site-doctor' ) . '</a>'
                );
                ?>
            </p>
        </div>
    <?php endif; ?>

    <!-- Action buttons -->
    <div class="npc-actions">
        <button id="npc-run-check" class="button button-primary button-hero">
            <?php esc_html_e( 'Run Diagnosis', 'npc-site-doctor' ); ?>
        </button>
        <?php if ( $ai_available ) : ?>
            <button id="npc-generate-report" class="button button-secondary button-hero">
                <?php esc_html_e( 'Generate AI Report', 'npc-site-doctor' ); ?>
            </button>
        <?php endif; ?>
        <button id="npc-download-report" class="button button-secondary button-hero" style="display:none;">
            <?php esc_html_e( 'Download Report', 'npc-site-doctor' ); ?>
        </button>
    </div>

    <!-- Loading indicator -->
    <div id="npc-loading" style="display:none;">
        <span class="spinner is-active" style="float:none;"></span>
        <span id="npc-loading-text"><?php esc_html_e( 'Running diagnosis...', 'npc-site-doctor' ); ?></span>
    </div>

    <!-- Latest diagnosis summary header (grade + counts + timestamp) -->
    <div id="npc-summary-header" style="display:none;"></div>

    <!-- Latest diagnosis result area -->
    <div id="npc-results" style="display:none;">
        <h2><?php esc_html_e( 'Diagnosis Result', 'npc-site-doctor' ); ?></h2>
        <div id="npc-results-grid" class="npc-grid"></div>
    </div>

    <!-- Latest AI report area (hidden when AI is disabled) -->
    <?php if ( $ai_available ) : ?>
        <div id="npc-report" style="display:none;">
            <h2 id="npc-report-title"><?php esc_html_e( 'AI Report', 'npc-site-doctor' ); ?></h2>
            <div id="npc-report-content" class="npc-report-content"></div>
        </div>
    <?php endif; ?>

    <!-- Diagnosis history accordion (latest 10) -->
    <div id="npc-history" style="display:none;">
        <h2><?php esc_html_e( 'Diagnosis History', 'npc-site-doctor' ); ?></h2>
        <p class="npc-history-note">
            <?php
            printf(
                /* translators: %d: number of history entries kept */
                esc_html__( 'The most recent %d entries are kept. Older entries are deleted automatically.', 'npc-site-doctor' ),
                (int) NPC_SD_HISTORY_LIMIT
            );
            ?>
        </p>
        <div id="npc-history-list"></div>
    </div>
</div>
