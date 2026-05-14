<?php
/**
 * Settings page template
 * v0.2.0: API key is managed via a wp-config.php constant instead of the database.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$api_key        = NPCMI_Plugin::get_api_key();
$has_api_key    = ! empty( $api_key );
$ai_available   = NPCMI_Plugin::is_ai_available();
$masked_key     = $has_api_key ? substr( $api_key, 0, 10 ) . '...' . substr( $api_key, -4 ) : '';
$not_set_label  = __( '(not set)', 'npc-maintenance-inspector' );
$allowed_email  = get_option( 'npcmi_allowed_user_email', '' );
$bound_url      = get_option( 'npcmi_bound_site_url', '' );
$current_user   = wp_get_current_user();

// Auto diagnosis settings
$auto_enabled   = (bool) get_option( 'npcmi_auto_enabled', false );
$auto_schedule  = get_option( 'npcmi_auto_schedule', 'weekly' );
$notify_email   = get_option( 'npcmi_notify_email', '' );
$last_notified  = get_option( 'npcmi_last_notified', '' );
$next_run_ts    = wp_next_scheduled( NPCMI_CRON_HOOK );
$next_run_text  = $next_run_ts
    ? wp_date( 'Y-m-d H:i', $next_run_ts )
    : __( 'Not scheduled', 'npc-maintenance-inspector' );

$schedule_labels = array(
    'daily'   => __( 'Daily', 'npc-maintenance-inspector' ),
    'weekly'  => __( 'Weekly (every 7 days)', 'npc-maintenance-inspector' ),
    'monthly' => __( 'Monthly (every 30 days)', 'npc-maintenance-inspector' ),
);
?>

<?php
// 早期サニタイズ: sanitize_key で wrap してから判定
$settings_updated = isset( $_GET['settings-updated'] ) ? sanitize_key( wp_unslash( $_GET['settings-updated'] ) ) : '';
?>
<?php if ( $settings_updated === 'true' ) : ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'npc-maintenance-inspector' ); ?></p></div>
<?php endif; ?>

<div class="wrap">
    <h1><?php esc_html_e( 'NPC Maintenance Inspector — Settings', 'npc-maintenance-inspector' ); ?></h1>

    <!-- Security info -->
    <div class="card" style="max-width: 700px; margin-bottom: 20px;">
        <h2><?php esc_html_e( 'Security Settings', 'npc-maintenance-inspector' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Authorized user', 'npc-maintenance-inspector' ); ?></th>
                <td>
                    <code><?php echo esc_html( $allowed_email ?: $not_set_label ); ?></code>
                    <p class="description">
                        <?php esc_html_e( 'Only the user who activated this plugin can operate it. Other users do not see the menu.', 'npc-maintenance-inspector' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Bound site URL', 'npc-maintenance-inspector' ); ?></th>
                <td>
                    <code><?php echo esc_html( $bound_url ?: $not_set_label ); ?></code>
                    <p class="description">
                        <?php esc_html_e( 'This plugin only operates on this domain. If the site is restored as a different domain (from backup), the plugin will automatically stop.', 'npc-maintenance-inspector' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Current user', 'npc-maintenance-inspector' ); ?></th>
                <td>
                    <code><?php echo esc_html( $current_user->user_email ); ?></code>
                    <?php
                    printf(
                        /* translators: %d: user ID */
                        esc_html__( '(ID: %d)', 'npc-maintenance-inspector' ),
                        (int) $current_user->ID
                    );
                    ?>
                </td>
            </tr>
        </table>
    </div>

    <!-- API key guide / AI report feature -->
    <div class="card" style="max-width: 700px; margin-bottom: 20px;">
        <h2><?php esc_html_e( 'AI Report Feature (optional)', 'npc-maintenance-inspector' ); ?></h2>

        <?php if ( $ai_available ) : ?>
            <!-- Enabled -->
            <div class="notice notice-success inline" style="margin: 0 0 16px;">
                <p>
                    <?php
                    printf(
                        /* translators: %s: bold "Enabled" word wrapped in <strong> */
                        esc_html__( 'The AI report feature is %s.', 'npc-maintenance-inspector' ),
                        '<strong>' . esc_html__( 'Enabled', 'npc-maintenance-inspector' ) . '</strong>'
                    );
                    ?>
                    <br>
                    <?php esc_html_e( 'API key:', 'npc-maintenance-inspector' ); ?>
                    <code><?php echo esc_html( $masked_key ); ?></code>
                </p>
            </div>

            <p class="description">
                <?php esc_html_e( 'When the diagnosis result contains critical/warning items, the Claude API automatically generates a maintenance report in Japanese. The report is also attached to notification emails.', 'npc-maintenance-inspector' ); ?>
            </p>

            <h3><?php esc_html_e( 'How to change the API key', 'npc-maintenance-inspector' ); ?></h3>
            <p><?php esc_html_e( 'For security reasons, the API key is stored in wp-config.php instead of the database.', 'npc-maintenance-inspector' ); ?></p>
            <ol>
                <li><?php esc_html_e( 'Open wp-config.php via FTP or a file manager.', 'npc-maintenance-inspector' ); ?></li>
                <li>
                    <?php
                    printf(
                        /* translators: %s: NPCMI_API_KEY constant name in <code> */
                        esc_html__( 'Replace the value of %s with the new key.', 'npc-maintenance-inspector' ),
                        '<code>NPCMI_API_KEY</code>'
                    );
                    ?>
                </li>
            </ol>

        <?php else : ?>
            <!-- Disabled / before setup -->
            <div class="notice notice-info inline" style="margin: 0 0 16px;">
                <p>
                    <?php
                    printf(
                        /* translators: %s: bold "Disabled" word wrapped in <strong> */
                        esc_html__( 'The AI report feature is %s. Only diagnostics are active.', 'npc-maintenance-inspector' ),
                        '<strong>' . esc_html__( 'Disabled', 'npc-maintenance-inspector' ) . '</strong>'
                    );
                    ?>
                </p>
            </div>

            <p class="description">
                <?php esc_html_e( 'Without an API key, this plugin still runs all 9 diagnostics, scheduled checks, email notification, and history. Configure the API key only if you want AI-generated maintenance reports in Japanese.', 'npc-maintenance-inspector' ); ?>
            </p>

            <h3><?php esc_html_e( 'How to enable it', 'npc-maintenance-inspector' ); ?></h3>

            <h4 style="margin-top: 16px;"><?php esc_html_e( 'A. Bring your own API key', 'npc-maintenance-inspector' ); ?></h4>
            <ol>
                <li>
                    <?php
                    printf(
                        /* translators: %s: Anthropic Console link */
                        esc_html__( 'Issue an API key at %s (pay-as-you-go, typically a few dozen JPY per month).', 'npc-maintenance-inspector' ),
                        '<a href="https://console.anthropic.com/" target="_blank" rel="noopener">' . esc_html__( 'Anthropic Console', 'npc-maintenance-inspector' ) . '</a>'
                    );
                    ?>
                </li>
                <li><?php esc_html_e( 'Open wp-config.php via FTP or a file manager.', 'npc-maintenance-inspector' ); ?></li>
                <li>
                    <?php
                    printf(
                        /* translators: %s: code marker shown in wp-config.php */
                        esc_html__( 'Add the following line above %s:', 'npc-maintenance-inspector' ),
                        '<code>/* That\'s all, stop editing! */</code>'
                    );
                    ?>
                </li>
            </ol>

            <pre style="background: #23282d; color: #eee; padding: 12px 16px; border-radius: 4px; overflow-x: auto;">define( 'NPCMI_API_KEY', 'paste-your-api-key-here' );</pre>

            <h4 style="margin-top: 16px;"><?php esc_html_e( 'B. Use the npc maintenance plan', 'npc-maintenance-inspector' ); ?></h4>
            <p>
                <?php
                printf(
                    /* translators: %s: link to npc WP maintenance page */
                    esc_html__( 'We set up the API key for you as part of a monthly maintenance subscription. See %s.', 'npc-maintenance-inspector' ),
                    '<a href="https://n-pc.jp/services/wp-maintenance/" target="_blank" rel="noopener">' . esc_html__( 'npc WP maintenance plan', 'npc-maintenance-inspector' ) . '</a>'
                );
                ?>
            </p>
        <?php endif; ?>
    </div>

    <!-- Auto diagnosis & notifications -->
    <div class="card" style="max-width: 700px; margin-bottom: 20px;">
        <h2><?php esc_html_e( 'Automatic Diagnosis & Notifications', 'npc-maintenance-inspector' ); ?></h2>
        <p class="description">
            <?php esc_html_e( 'Run periodic diagnostics via WP Cron and send email when critical issues (file tampering, suspicious files, SSL expiration, etc.) are detected.', 'npc-maintenance-inspector' ); ?>
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'npcmi_auto_settings' ); ?>
            <input type="hidden" name="action" value="npcmi_save_auto_settings">

            <table class="form-table">
                <tr>
                    <th><label for="auto_enabled"><?php esc_html_e( 'Automatic diagnosis', 'npc-maintenance-inspector' ); ?></label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_enabled" id="auto_enabled" value="1" <?php checked( $auto_enabled ); ?>>
                            <?php esc_html_e( 'Enable', 'npc-maintenance-inspector' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'When disabled, both the scheduled check and email notifications stop.', 'npc-maintenance-inspector' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="auto_schedule"><?php esc_html_e( 'Frequency', 'npc-maintenance-inspector' ); ?></label></th>
                    <td>
                        <select name="auto_schedule" id="auto_schedule">
                            <?php foreach ( $schedule_labels as $val => $label ) : ?>
                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $auto_schedule, $val ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'WP Cron is triggered by page visits, so the actual execution time is approximate.', 'npc-maintenance-inspector' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="notify_email"><?php esc_html_e( 'Notification email', 'npc-maintenance-inspector' ); ?></label></th>
                    <td>
                        <input type="email" name="notify_email" id="notify_email"
                               value="<?php echo esc_attr( $notify_email ); ?>"
                               class="regular-text"
                               placeholder="<?php echo esc_attr( $allowed_email ); ?>">
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: authorized user's email */
                                esc_html__( 'If left blank, notifications are sent to the authorized user (%s).', 'npc-maintenance-inspector' ),
                                '<code>' . esc_html( $allowed_email ?: $not_set_label ) . '</code>'
                            );
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Notification triggers', 'npc-maintenance-inspector' ); ?></th>
                    <td>
                        <p><?php esc_html_e( 'Email is sent when any of the following is detected:', 'npc-maintenance-inspector' ); ?></p>
                        <ul style="margin-left: 20px; list-style: disc;">
                            <li><?php esc_html_e( 'File tampering (suspicious code detected)', 'npc-maintenance-inspector' ); ?></li>
                            <li><?php esc_html_e( 'Suspicious PHP files in the uploads directory', 'npc-maintenance-inspector' ); ?></li>
                            <li><?php esc_html_e( 'SSL certificate expiring soon (14 days or less)', 'npc-maintenance-inspector' ); ?></li>
                            <li><?php esc_html_e( 'Critical Site Health issues', 'npc-maintenance-inspector' ); ?></li>
                        </ul>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Next scheduled run', 'npc-maintenance-inspector' ); ?></th>
                    <td>
                        <code><?php echo esc_html( $next_run_text ); ?></code>
                        <?php if ( $last_notified ) : ?>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: timestamp of last notification sent */
                                    esc_html__( 'Last notification sent: %s', 'npc-maintenance-inspector' ),
                                    esc_html( $last_notified )
                                );
                                ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Save Settings', 'npc-maintenance-inspector' ) ); ?>
        </form>

        <hr style="margin: 24px 0;">

        <h3><?php esc_html_e( 'Notification Test', 'npc-maintenance-inspector' ); ?></h3>
        <p class="description">
            <?php esc_html_e( 'Send a dummy critical notification to verify email delivery. The real diagnosis results are not affected.', 'npc-maintenance-inspector' ); ?>
        </p>
        <p>
            <button type="button" id="npc-test-notification" class="button">
                <?php esc_html_e( 'Send Test Email', 'npc-maintenance-inspector' ); ?>
            </button>
            <span id="npc-test-notification-result" style="margin-left: 10px;"></span>
        </p>
    </div>

    <!-- Cost info (only when AI is enabled) -->
    <?php if ( $ai_available ) : ?>
    <div class="card" style="max-width: 700px;">
        <h2><?php esc_html_e( 'API Cost Estimate', 'npc-maintenance-inspector' ); ?></h2>
        <table class="widefat fixed" style="max-width: 400px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Item', 'npc-maintenance-inspector' ); ?></th>
                    <th><?php esc_html_e( 'Estimate', 'npc-maintenance-inspector' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php esc_html_e( 'Per-site diagnosis + report', 'npc-maintenance-inspector' ); ?></td>
                    <td><?php esc_html_e( 'About JPY 2', 'npc-maintenance-inspector' ); ?></td>
                </tr>
                <tr>
                    <td><?php esc_html_e( '20 sites once a month', 'npc-maintenance-inspector' ); ?></td>
                    <td><?php esc_html_e( 'About JPY 40 / month', 'npc-maintenance-inspector' ); ?></td>
                </tr>
                <tr>
                    <td><?php esc_html_e( 'Automatic diagnosis (normal)', 'npc-maintenance-inspector' ); ?></td>
                    <td>
                        <?php esc_html_e( 'JPY 0', 'npc-maintenance-inspector' ); ?><br>
                        <span class="description"><?php esc_html_e( 'No AI report generated when no critical issues are detected.', 'npc-maintenance-inspector' ); ?></span>
                    </td>
                </tr>
                <tr>
                    <td><?php esc_html_e( 'Automatic diagnosis (only on critical)', 'npc-maintenance-inspector' ); ?></td>
                    <td>
                        <?php esc_html_e( 'About JPY 2 / run', 'npc-maintenance-inspector' ); ?><br>
                        <span class="description"><?php esc_html_e( 'AI report is generated and attached to the email when critical issues are detected.', 'npc-maintenance-inspector' ); ?></span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
