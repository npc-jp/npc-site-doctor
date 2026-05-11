<?php
/**
 * 設定ページテンプレート
 * v0.2.0: APIキーはwp-config.phpの定数で管理する方式に変更
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$api_key        = NPC_SD_Plugin::get_api_key();
$has_api_key    = ! empty( $api_key );
$ai_available   = NPC_SD_Plugin::is_ai_available();
$masked_key     = $has_api_key ? substr( $api_key, 0, 10 ) . '...' . substr( $api_key, -4 ) : '';
$allowed_email  = get_option( 'npc_sd_allowed_user_email', '未設定' );
$bound_url      = get_option( 'npc_sd_bound_site_url', '未設定' );
$current_user   = wp_get_current_user();

// 自動診断の設定値
$auto_enabled   = (bool) get_option( 'npc_sd_auto_enabled', false );
$auto_schedule  = get_option( 'npc_sd_auto_schedule', 'weekly' );
$notify_email   = get_option( 'npc_sd_notify_email', '' );
$last_notified  = get_option( 'npc_sd_last_notified', '' );
$next_run_ts    = wp_next_scheduled( NPC_SD_CRON_HOOK );
$next_run_text  = $next_run_ts
    ? wp_date( 'Y-m-d H:i', $next_run_ts )
    : 'スケジュールなし';

$schedule_labels = array(
    'daily'   => '毎日',
    'weekly'  => '毎週（7日ごと）',
    'monthly' => '毎月（30日ごと）',
);
?>

<?php if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] === 'true' ) : ?>
    <div class="notice notice-success is-dismissible"><p>設定を保存しました。</p></div>
<?php endif; ?>

<div class="wrap">
    <h1>WP Healthcheck — 設定</h1>

    <!-- セキュリティ情報 -->
    <div class="card" style="max-width: 700px; margin-bottom: 20px;">
        <h2>セキュリティ設定</h2>
        <table class="form-table">
            <tr>
                <th>許可ユーザー</th>
                <td>
                    <code><?php echo esc_html( $allowed_email ); ?></code>
                    <p class="description">
                        プラグイン初回有効化時に登録されたユーザーのみ操作可能です。
                        他のユーザーにはメニューが表示されません。
                    </p>
                </td>
            </tr>
            <tr>
                <th>紐付けサイト</th>
                <td>
                    <code><?php echo esc_html( $bound_url ); ?></code>
                    <p class="description">
                        このドメインでのみ動作します。バックアップ復元で別ドメインに移された場合、
                        プラグインは自動的に停止します。
                    </p>
                </td>
            </tr>
            <tr>
                <th>現在のユーザー</th>
                <td>
                    <code><?php echo esc_html( $current_user->user_email ); ?></code>
                    （ID: <?php echo esc_html( $current_user->ID ); ?>）
                </td>
            </tr>
        </table>
    </div>

    <!-- APIキー設定ガイド / AIレポート機能 -->
    <div class="card" style="max-width: 700px; margin-bottom: 20px;">
        <h2>AIレポート機能（オプション）</h2>

        <?php if ( $ai_available ) : ?>
            <!-- 有効: 通常運用 -->
            <div class="notice notice-success inline" style="margin: 0 0 16px;">
                <p>
                    AIレポート機能は<strong>有効</strong>です。<br>
                    APIキー: <code><?php echo esc_html( $masked_key ); ?></code>
                </p>
            </div>

            <p class="description">
                診断結果に critical/warning が含まれた場合、Claude API が日本語で修正提案レポートを自動生成します。
                通知メールにもレポートが添付されます。
            </p>

            <h3>APIキーの変更方法</h3>
            <p>セキュリティのため、APIキーはデータベースではなく <code>wp-config.php</code> に直接記述しています。</p>
            <ol>
                <li>FTPまたはファイルマネージャーで <code>wp-config.php</code> を開く</li>
                <li><code>NPC_SD_API_KEY</code> の値を新しいキーに書き換える</li>
            </ol>

        <?php else : ?>
            <!-- 無効: GitHub公開版相当 / セットアップ前 -->
            <div class="notice notice-info inline" style="margin: 0 0 16px;">
                <p>AIレポート機能は<strong>無効</strong>です。診断機能のみが動作しています。</p>
            </div>

            <p class="description">
                このプラグインは、APIキーなしでも 9 項目の診断・自動通知・履歴管理が利用できます。<br>
                AIレポート（修正提案を日本語で自動生成）を有効化したい場合のみ、以下の設定を行ってください。
            </p>

            <h3>有効化する方法</h3>

            <h4 style="margin-top: 16px;">A. 自分でAPIキーを設定する</h4>
            <ol>
                <li>
                    <a href="https://console.anthropic.com/" target="_blank" rel="noopener">Anthropic Console</a>
                    でAPIキーを発行（クレジット最小課金で月数十円〜）
                </li>
                <li>FTPまたはファイルマネージャーで <code>wp-config.php</code> を開く</li>
                <li><code>/* That's all, stop editing! */</code> の<strong>上</strong>に以下を追記：</li>
            </ol>

            <pre style="background: #23282d; color: #eee; padding: 12px 16px; border-radius: 4px; overflow-x: auto;">define( 'NPC_SD_API_KEY', 'ここにAPIキーを貼り付け' );</pre>

            <h4 style="margin-top: 16px;">B. npc 保守契約を利用する</h4>
            <p>
                APIキーの取得・設定込みで丸ごとセットアップを代行します。<br>
                月額の保守契約に AIレポート機能を含めて運用できます（詳細:
                <a href="https://n-pc.jp/services/wp-maintenance" target="_blank" rel="noopener">npc WP 保守メニュー</a>）。
            </p>
        <?php endif; ?>
    </div>

    <!-- 自動診断・通知設定 -->
    <div class="card" style="max-width: 700px; margin-bottom: 20px;">
        <h2>自動診断と通知</h2>
        <p class="description">
            WP Cron を使って定期的に自動診断を実行し、重大な問題（ファイル改ざん・不審ファイル・SSL期限など）を検出したらメールで通知します。
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'npc_sd_auto_settings' ); ?>
            <input type="hidden" name="action" value="npc_sd_save_auto_settings">

            <table class="form-table">
                <tr>
                    <th><label for="auto_enabled">自動診断</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_enabled" id="auto_enabled" value="1" <?php checked( $auto_enabled ); ?>>
                            有効にする
                        </label>
                        <p class="description">OFFにすると、自動診断とメール通知は停止します。</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="auto_schedule">診断頻度</label></th>
                    <td>
                        <select name="auto_schedule" id="auto_schedule">
                            <?php foreach ( $schedule_labels as $val => $label ) : ?>
                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $auto_schedule, $val ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            WP Cronはアクセス時にトリガーされる仕組みなので、実際の実行は頻度の目安です。
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="notify_email">通知先メール</label></th>
                    <td>
                        <input type="email" name="notify_email" id="notify_email"
                               value="<?php echo esc_attr( $notify_email ); ?>"
                               class="regular-text"
                               placeholder="<?php echo esc_attr( $allowed_email ); ?>">
                        <p class="description">
                            空欄の場合、許可ユーザーのメアド（<code><?php echo esc_html( $allowed_email ); ?></code>）に送信されます。
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>通知条件</th>
                    <td>
                        <p>以下のいずれかを検出したときに通知:</p>
                        <ul style="margin-left: 20px; list-style: disc;">
                            <li>ファイル改ざん（不審コード検出）</li>
                            <li>uploads内の不審なPHPファイル</li>
                            <li>SSL証明書の期限切れ間近（残り14日以下）</li>
                            <li>サイトヘルスの致命的問題</li>
                        </ul>
                    </td>
                </tr>
                <tr>
                    <th>次回実行予定</th>
                    <td>
                        <code><?php echo esc_html( $next_run_text ); ?></code>
                        <?php if ( $last_notified ) : ?>
                            <p class="description">最終通知送信: <?php echo esc_html( $last_notified ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <?php submit_button( '設定を保存' ); ?>
        </form>

        <hr style="margin: 24px 0;">

        <h3>通知テスト</h3>
        <p class="description">
            実際のメール到達を確認するため、ダミーのcritical通知を送信します。
            本番の診断結果には影響しません。
        </p>
        <p>
            <button type="button" id="npc-test-notification" class="button">
                テストメールを送信
            </button>
            <span id="npc-test-notification-result" style="margin-left: 10px;"></span>
        </p>
    </div>

    <!-- コスト情報（AI機能が有効な場合のみ表示） -->
    <?php if ( $ai_available ) : ?>
    <div class="card" style="max-width: 700px;">
        <h2>API利用コストの目安</h2>
        <table class="widefat fixed" style="max-width: 400px;">
            <thead>
                <tr>
                    <th>項目</th>
                    <th>目安</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>1サイトあたりの診断+レポート</td>
                    <td>約2円</td>
                </tr>
                <tr>
                    <td>20サイト × 月1回</td>
                    <td>約40円/月</td>
                </tr>
                <tr>
                    <td>自動診断（通常時）</td>
                    <td>0円<br><span class="description">critical未検出時はAIレポートを生成しません</span></td>
                </tr>
                <tr>
                    <td>自動診断（critical検出時のみ）</td>
                    <td>約2円/回<br><span class="description">検出時にAIレポートを生成してメールに添付</span></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
