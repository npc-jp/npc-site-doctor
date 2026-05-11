<?php
/**
 * Plugin Name: NPC WP Healthcheck
 * Plugin URI: https://n-pc.jp
 * Description: WordPressサイトの保守診断を自動化し、AIが修正提案レポートを生成するツール
 * Version: 0.7.8
 * Author: npc (Azu)
 * Author URI: https://n-pc.jp
 * License: GPL v2 or later
 * Text Domain: npc-wp-healthcheck
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

// 直接アクセス禁止
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// プラグイン定数
define( 'NPC_HEALTHCHECK_VERSION', '0.7.8' );
define( 'NPC_HEALTHCHECK_PATH', plugin_dir_path( __FILE__ ) );
define( 'NPC_HEALTHCHECK_URL', plugin_dir_url( __FILE__ ) );

// 診断履歴CPTと保持件数
define( 'NPC_HEALTHCHECK_CPT', 'npc_hc_log' );
define( 'NPC_HEALTHCHECK_HISTORY_LIMIT', 10 );

// 自動診断のcronフック名
define( 'NPC_HEALTHCHECK_CRON_HOOK', 'npc_healthcheck_auto_check' );

/**
 * メインクラス
 * プラグイン全体の初期化と管理画面の登録を担当
 */
class NPC_WP_Healthcheck {

    /** @var self|null シングルトンインスタンス */
    private static $instance = null;

    /**
     * シングルトン取得
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * コンストラクタ — フックの登録だけ行う
     */
    private function __construct() {
        $this->load_dependencies();

        // プラグイン有効化時のセットアップ
        register_activation_hook( __FILE__, array( $this, 'on_activation' ) );

        // プラグイン無効化時にcronを必ずクリア
        register_deactivation_hook( __FILE__, array( $this, 'on_deactivation' ) );

        // カスタムcronスケジュール（weekly/monthly）を追加
        add_filter( 'cron_schedules', array( $this, 'add_custom_cron_schedules' ) );

        // 自動診断のcronフック
        add_action( NPC_HEALTHCHECK_CRON_HOOK, array( $this, 'run_auto_check' ) );

        // DB上の旧APIキーをクリーンアップ（再有効化不要で即時実行）
        $this->maybe_cleanup_db_api_key();

        // サイトURL検証（別ドメインで動かさせない）
        if ( ! $this->is_valid_site() ) {
            add_action( 'admin_notices', array( $this, 'show_invalid_site_notice' ) );
            return; // 以降のフックを一切登録しない
        }

        // 診断履歴CPTの登録
        add_action( 'init', array( $this, 'register_log_cpt' ) );

        // 管理画面メニューの登録（許可ユーザーのみ）
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

        // 管理画面用のCSS/JSを読み込む
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // AJAX: 診断実行（許可ユーザーチェック付き）
        add_action( 'wp_ajax_npc_run_healthcheck', array( $this, 'ajax_run_healthcheck' ) );

        // AJAX: AIレポート生成（許可ユーザーチェック付き）
        add_action( 'wp_ajax_npc_generate_report', array( $this, 'ajax_generate_report' ) );

        // AJAX: debug.logクリア
        add_action( 'wp_ajax_npc_clear_error_log', array( $this, 'ajax_clear_error_log' ) );

        // 自動診断の設定保存（admin-post経由）
        add_action( 'admin_post_npc_save_auto_settings', array( $this, 'handle_save_auto_settings' ) );

        // AJAX: 通知テストメール送信
        add_action( 'wp_ajax_npc_test_notification', array( $this, 'ajax_test_notification' ) );
    }

    /**
     * 依存ファイルの読み込み
     */
    private function load_dependencies() {
        require_once NPC_HEALTHCHECK_PATH . 'includes/class-checker.php';
        require_once NPC_HEALTHCHECK_PATH . 'includes/class-ai-reporter.php';
        require_once NPC_HEALTHCHECK_PATH . 'includes/class-notifier.php';
    }

    /**
     * DB上の旧APIキーを削除（wp-config.phpに移行済みなら不要）
     * 毎回チェックするが、delete_optionは存在しなければ何もしないので軽い
     */
    private function maybe_cleanup_db_api_key() {
        if ( defined( 'NPC_HEALTHCHECK_API_KEY' ) && NPC_HEALTHCHECK_API_KEY ) {
            delete_option( 'npc_healthcheck_api_key' );
        }
    }

    // =========================================
    // セキュリティ: ユーザー制限 + サイト紐付け
    // =========================================

    /**
     * プラグイン有効化時の処理
     * - 初回: 有効化したユーザーのIDとサイトURLを記録してロック
     * - 2回目以降: 既存設定を保持（上書きしない）
     */
    public function on_activation() {
        // 許可ユーザーが未設定の場合だけ記録（初回のみ）
        if ( ! get_option( 'npc_healthcheck_allowed_user_id' ) ) {
            $current_user = wp_get_current_user();
            update_option( 'npc_healthcheck_allowed_user_id', $current_user->ID );
            update_option( 'npc_healthcheck_allowed_user_email', $current_user->user_email );
        }

        // サイトURLが未設定の場合だけ記録（初回のみ）
        if ( ! get_option( 'npc_healthcheck_bound_site_url' ) ) {
            update_option( 'npc_healthcheck_bound_site_url', get_site_url() );
        }

        // セットアップ完了フラグ
        update_option( 'npc_healthcheck_setup_done', true );

        // wp-config.phpにAPIキーが定義されていれば、DB上の旧キーを削除（セキュリティ強化）
        if ( defined( 'NPC_HEALTHCHECK_API_KEY' ) && NPC_HEALTHCHECK_API_KEY ) {
            delete_option( 'npc_healthcheck_api_key' );
        }
    }

    /**
     * プラグイン無効化時: cronを必ずクリア
     * 自動診断が無効化後も動き続けるのを防ぐ
     */
    public function on_deactivation() {
        wp_clear_scheduled_hook( NPC_HEALTHCHECK_CRON_HOOK );
    }

    // =========================================
    // 自動診断（WP Cron）
    // =========================================

    /**
     * カスタムcronスケジュールを追加
     * WP標準は hourly/twicedaily/daily のみなので weekly と monthly を足す
     */
    public function add_custom_cron_schedules( $schedules ) {
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = array(
                'interval' => 7 * DAY_IN_SECONDS,
                'display'  => '週1回',
            );
        }
        if ( ! isset( $schedules['monthly'] ) ) {
            $schedules['monthly'] = array(
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => '月1回',
            );
        }
        return $schedules;
    }

    /**
     * 自動診断のスケジュール設定
     * 既存のスケジュールをクリアしてから、新しい頻度で登録
     *
     * @param string $schedule 'daily' | 'weekly' | 'monthly'
     */
    public function schedule_auto_check( $schedule ) {
        wp_clear_scheduled_hook( NPC_HEALTHCHECK_CRON_HOOK );

        $valid = array( 'daily', 'weekly', 'monthly' );
        if ( ! in_array( $schedule, $valid, true ) ) {
            $schedule = 'weekly';
        }

        // 初回は1時間後から開始（即時実行を避けてユーザーの想定外を防ぐ）
        wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, NPC_HEALTHCHECK_CRON_HOOK );
    }

    /**
     * 自動診断の実行
     * cron経由で呼ばれる。実行中はユーザーコンテキストがないので権限チェックは不要
     */
    public function run_auto_check() {
        // 自動診断がOFFなら何もしない（設定変更のタイミング次第で残留cron対策）
        if ( ! get_option( 'npc_healthcheck_auto_enabled', false ) ) {
            return;
        }

        $checker = new NPC_Checker();
        $results = $checker->run_all_checks();

        // 履歴CPTに保存
        $log_id = $this->save_healthcheck_log( $results );
        if ( is_wp_error( $log_id ) ) {
            return;
        }

        update_option( 'npc_healthcheck_last_results', $results );
        update_option( 'npc_healthcheck_last_run', current_time( 'mysql' ) );

        // critical検出判定
        $issues = NPC_Notifier::detect_critical_issues( $results );
        if ( empty( $issues ) ) {
            return; // critical なしなら通知不要
        }

        // critical検出時のみAIレポートを生成（コスト最適化）
        // APIキー未設定（GitHub公開版）の場合はAI機能をスキップし、メール通知のみ送る
        $report = '';
        if ( self::is_ai_available() ) {
            $reporter = new NPC_AI_Reporter();
            $maybe_report = $reporter->generate( $results );
            if ( ! is_wp_error( $maybe_report ) ) {
                $report = $maybe_report;
                update_option( 'npc_healthcheck_last_report', $report );
                $this->attach_report_to_log( $log_id, $report );
            }
        }

        // 通知メール送信
        $email = $this->get_notify_email();
        if ( $email ) {
            NPC_Notifier::send( $email, $results, $issues, $report );
            update_option( 'npc_healthcheck_last_notified', current_time( 'mysql' ) );
        }
    }

    /**
     * 通知先メアドを取得
     * 未設定なら許可ユーザーのメアドにフォールバック
     */
    private function get_notify_email() {
        $configured = get_option( 'npc_healthcheck_notify_email', '' );
        if ( is_email( $configured ) ) {
            return $configured;
        }
        $allowed = get_option( 'npc_healthcheck_allowed_user_email', '' );
        return is_email( $allowed ) ? $allowed : '';
    }

    /**
     * 自動診断の設定保存ハンドラ（admin-post）
     */
    public function handle_save_auto_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '権限がありません。' );
        }
        check_admin_referer( 'npc_healthcheck_auto_settings' );

        if ( ! $this->is_allowed_user() ) {
            wp_die( '許可されたユーザーではありません。' );
        }

        $enabled  = ! empty( $_POST['auto_enabled'] );
        $schedule = isset( $_POST['auto_schedule'] ) ? sanitize_text_field( wp_unslash( $_POST['auto_schedule'] ) ) : 'weekly';
        $email    = isset( $_POST['notify_email'] ) ? sanitize_email( wp_unslash( $_POST['notify_email'] ) ) : '';

        $valid_schedules = array( 'daily', 'weekly', 'monthly' );
        if ( ! in_array( $schedule, $valid_schedules, true ) ) {
            $schedule = 'weekly';
        }

        update_option( 'npc_healthcheck_auto_enabled', $enabled );
        update_option( 'npc_healthcheck_auto_schedule', $schedule );
        update_option( 'npc_healthcheck_notify_email', $email );

        // cron再登録
        if ( $enabled ) {
            $this->schedule_auto_check( $schedule );
        } else {
            wp_clear_scheduled_hook( NPC_HEALTHCHECK_CRON_HOOK );
        }

        wp_safe_redirect( add_query_arg( 'settings-updated', 'true', admin_url( 'admin.php?page=npc-healthcheck-settings' ) ) );
        exit;
    }

    /**
     * 現在のユーザーが許可ユーザーかチェック
     * メールアドレスで照合（サイトごとにユーザーIDが違う場合に対応）
     */
    private function is_allowed_user() {
        $current_user = wp_get_current_user();

        // まだセットアップ前ならfalse
        if ( ! get_option( 'npc_healthcheck_setup_done' ) ) {
            return false;
        }

        // メールアドレスで照合（IDが違うサイトでも一致する）
        $allowed_email = get_option( 'npc_healthcheck_allowed_user_email', '' );
        if ( $current_user->user_email === $allowed_email ) {
            return true;
        }

        // フォールバック: ユーザーIDでも照合
        $allowed_id = get_option( 'npc_healthcheck_allowed_user_id', 0 );
        if ( $current_user->ID === (int) $allowed_id ) {
            return true;
        }

        return false;
    }

    /**
     * サイトURLの検証
     * 有効化時に記録したURLと現在のURLが一致するか確認
     * バックアップ復元で別ドメインに移された場合をブロック
     */
    private function is_valid_site() {
        $bound_url   = get_option( 'npc_healthcheck_bound_site_url', '' );
        $current_url = get_site_url();

        // まだ紐付けされていない（初回有効化前）場合はOK
        if ( empty( $bound_url ) ) {
            return true;
        }

        // ドメイン部分だけで比較（http/httpsの違いは無視）
        $bound_host   = wp_parse_url( $bound_url, PHP_URL_HOST );
        $current_host = wp_parse_url( $current_url, PHP_URL_HOST );

        return $bound_host === $current_host;
    }

    /**
     * サイト不一致時の警告表示
     */
    public function show_invalid_site_notice() {
        // 管理者にだけ表示
        if ( ! current_user_can( 'manage_options' ) ) return;

        $bound_url = get_option( 'npc_healthcheck_bound_site_url', '' );
        echo '<div class="notice notice-error"><p>';
        echo '<strong>WP Healthcheck:</strong> ';
        echo 'このプラグインは <code>' . esc_html( $bound_url ) . '</code> 用にセットアップされています。';
        echo '別のサイトでは使用できません。再セットアップが必要な場合は、';
        echo 'データベースの <code>npc_healthcheck_bound_site_url</code> オプションを削除してからプラグインを再有効化してください。';
        echo '</p></div>';
    }

    /**
     * APIキーの取得
     * wp-config.phpの定数を優先し、DBにはAPIキーを保存しない
     */
    public static function get_api_key() {
        // wp-config.php の定数を最優先
        if ( defined( 'NPC_HEALTHCHECK_API_KEY' ) && NPC_HEALTHCHECK_API_KEY ) {
            return NPC_HEALTHCHECK_API_KEY;
        }

        // 旧バージョン互換: DBに保存されたキーがあればそれを使う（移行用）
        $db_key = get_option( 'npc_healthcheck_api_key', '' );
        if ( $db_key ) {
            return $db_key;
        }

        return '';
    }

    /**
     * AI機能が利用可能か判定
     *
     * F案（GitHub公開版 + 保守クライアント版を1コードベースで両用）の中核判定。
     * - APIキーが未設定 / 空文字なら false
     * - 設定済みなら true（n-pc.jp 既存サイトはこの分岐で従来通りAI機能が動く）
     *
     * 全てのAI機能（管理画面ボタン・AJAX・Cron・メール通知）はこの判定を経由する。
     * 判定結果を変えたい場合は `npc_healthcheck_ai_available` フィルタで上書き可能。
     *
     * @return bool
     */
    public static function is_ai_available() {
        $key       = self::get_api_key();
        $available = ! empty( $key ) && is_string( $key ) && trim( $key ) !== '';

        /**
         * AI機能の有効/無効をフィルタで上書き可能にする
         * 例: 一時的に AI 機能を停止したい場合に false を返す
         */
        return (bool) apply_filters( 'npc_healthcheck_ai_available', $available );
    }

    // =========================================
    // 診断履歴CPT
    // =========================================

    /**
     * 診断履歴を保存するカスタム投稿タイプを登録
     * 非公開・管理画面非表示（自前UIで管理するため）
     */
    public function register_log_cpt() {
        register_post_type( NPC_HEALTHCHECK_CPT, array(
            'label'           => 'Healthcheck ログ',
            'public'          => false,
            'show_ui'         => false,
            'show_in_menu'    => false,
            'show_in_rest'    => false,
            'has_archive'     => false,
            'rewrite'         => false,
            'query_var'       => false,
            'capability_type' => 'post',
            'supports'        => array( 'title', 'custom-fields' ),
        ) );
    }

    /**
     * 診断結果を新しいログ投稿として保存
     * 保存後、保持件数超過分を自動削除する
     *
     * @param array $results 診断結果
     * @return int|WP_Error 作成した投稿ID or エラー
     */
    public function save_healthcheck_log( $results ) {
        $title = sprintf( '診断ログ %s', current_time( 'Y-m-d H:i' ) );

        $post_id = wp_insert_post( array(
            'post_type'   => NPC_HEALTHCHECK_CPT,
            'post_status' => 'publish',
            'post_title'  => $title,
        ), true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // 診断結果と集計サマリをmetaに保存
        update_post_meta( $post_id, '_npc_results', wp_json_encode( $results ) );
        update_post_meta( $post_id, '_npc_summary', $this->calculate_summary( $results ) );

        // 保持件数を超えた古いログを削除
        $this->cleanup_old_logs();

        return $post_id;
    }

    /**
     * ログ投稿にAIレポートを紐付け
     *
     * @param int    $post_id ログ投稿ID
     * @param string $report  AIレポート本文
     */
    public function attach_report_to_log( $post_id, $report ) {
        update_post_meta( $post_id, '_npc_report', $report );

        // AIレポートから総合評価A〜Dを抽出できたらサマリも更新
        if ( preg_match( '/総合評価[：:]?\s*([A-D])/u', $report, $m ) ) {
            $summary = get_post_meta( $post_id, '_npc_summary', true );
            if ( is_array( $summary ) ) {
                $summary['grade'] = $m[1];
                update_post_meta( $post_id, '_npc_summary', $summary );
            }
        }
    }

    /**
     * 診断結果からグレードと件数を集計
     * AIレポートが未生成の段階でも表示できるようにする
     */
    private function calculate_summary( $results ) {
        $statuses = array();

        $statuses[] = $this->normalize_status( $results['core_updates']['status'] ?? 'unknown' );
        $statuses[] = $this->normalize_status( $results['plugin_updates']['status'] ?? 'unknown' );

        // サイトヘルスは件数から判定
        $sh = $results['site_health'] ?? array();
        if ( ( $sh['critical_count'] ?? 0 ) > 0 ) {
            $statuses[] = 'critical';
        } elseif ( ( $sh['recommended_count'] ?? 0 ) > 0 ) {
            $statuses[] = 'warning';
        } else {
            $statuses[] = 'ok';
        }

        $statuses[] = $this->normalize_status( $results['php_version']['status'] ?? 'unknown' );
        $statuses[] = $this->normalize_status( $results['error_log']['status'] ?? 'unknown' );

        // ファイル改ざんはJS側のrenderと同じ格上げロジック
        $fi = $results['file_integrity'] ?? array();
        $integrity_status = $fi['status'] ?? 'unknown';
        if ( ! empty( $fi['has_danger'] ) ) {
            $integrity_status = 'critical';
        } elseif ( ( $fi['suspect_count'] ?? 0 ) > 0 && ( $fi['modified_count'] ?? 0 ) === 0 ) {
            $integrity_status = 'ok';
        }
        $statuses[] = $this->normalize_status( $integrity_status );

        $statuses[] = $this->normalize_status( $results['suspicious_files']['status'] ?? 'unknown' );
        $statuses[] = $this->normalize_status( $results['ssl_certificate']['status'] ?? 'unknown' );
        $statuses[] = $this->normalize_status( $results['file_permissions']['status'] ?? 'unknown' );

        $counts = array(
            'critical' => count( array_filter( $statuses, function( $s ) { return $s === 'critical'; } ) ),
            'warning'  => count( array_filter( $statuses, function( $s ) { return $s === 'warning'; } ) ),
            'ok'       => count( array_filter( $statuses, function( $s ) { return $s === 'ok'; } ) ),
        );

        // グレード判定（AIが後で上書きする）
        if ( $counts['critical'] > 0 ) {
            $grade = 'D';
        } elseif ( $counts['warning'] >= 3 ) {
            $grade = 'C';
        } elseif ( $counts['warning'] >= 1 ) {
            $grade = 'B';
        } else {
            $grade = 'A';
        }

        return array(
            'grade'  => $grade,
            'counts' => $counts,
        );
    }

    /**
     * ステータス値の正規化（update_available→warning, unknown→okなど）
     */
    private function normalize_status( $status ) {
        if ( $status === 'update_available' ) return 'warning';
        if ( $status === 'unknown' ) return 'ok';
        return $status;
    }

    /**
     * 保持件数を超えた古いログを削除
     */
    private function cleanup_old_logs() {
        $all = get_posts( array(
            'post_type'      => NPC_HEALTHCHECK_CPT,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ) );

        if ( count( $all ) <= NPC_HEALTHCHECK_HISTORY_LIMIT ) {
            return;
        }

        $to_delete = array_slice( $all, NPC_HEALTHCHECK_HISTORY_LIMIT );
        foreach ( $to_delete as $id ) {
            wp_delete_post( $id, true ); // 即時削除（ゴミ箱を経由しない）
        }
    }

    /**
     * 診断履歴を取得（新しい順）
     * JSに渡すため軽量化した配列で返す
     *
     * @return array
     */
    public function get_healthcheck_logs() {
        $posts = get_posts( array(
            'post_type'      => NPC_HEALTHCHECK_CPT,
            'post_status'    => 'publish',
            'posts_per_page' => NPC_HEALTHCHECK_HISTORY_LIMIT,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        $logs = array();
        foreach ( $posts as $p ) {
            $results_json = get_post_meta( $p->ID, '_npc_results', true );
            $report       = get_post_meta( $p->ID, '_npc_report', true );
            $summary      = get_post_meta( $p->ID, '_npc_summary', true );

            $logs[] = array(
                'id'        => $p->ID,
                'date'      => get_the_date( 'Y-m-d H:i', $p ),
                'date_iso'  => get_the_date( 'c', $p ),
                'results'   => $results_json ? json_decode( $results_json, true ) : null,
                'report'    => $report ?: '',
                'summary'   => is_array( $summary ) ? $summary : array( 'grade' => '-', 'counts' => array( 'critical' => 0, 'warning' => 0, 'ok' => 0 ) ),
                'site_name' => get_bloginfo( 'name' ),
            );
        }

        return $logs;
    }

    /**
     * 既存の option データを CPT に移行（初回のみ実行）
     * プラグインv0.3以前の診断結果を履歴1件目として取り込む
     */
    private function maybe_migrate_legacy_option() {
        // 既にCPT投稿がある、または既に移行済みフラグがあればスキップ
        if ( get_option( 'npc_healthcheck_migrated_v04', false ) ) {
            return;
        }

        $existing = get_posts( array(
            'post_type'      => NPC_HEALTHCHECK_CPT,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ) );
        if ( ! empty( $existing ) ) {
            update_option( 'npc_healthcheck_migrated_v04', true );
            return;
        }

        $legacy_results = get_option( 'npc_healthcheck_last_results', null );
        if ( empty( $legacy_results ) ) {
            update_option( 'npc_healthcheck_migrated_v04', true );
            return;
        }

        $post_id = $this->save_healthcheck_log( $legacy_results );
        if ( ! is_wp_error( $post_id ) ) {
            $legacy_report = get_option( 'npc_healthcheck_last_report', '' );
            if ( $legacy_report ) {
                $this->attach_report_to_log( $post_id, $legacy_report );
            }
            // 投稿日時を last_run の時刻に寄せる（保持できる場合）
            $last_run = get_option( 'npc_healthcheck_last_run', '' );
            if ( $last_run ) {
                wp_update_post( array(
                    'ID'            => $post_id,
                    'post_date'     => $last_run,
                    'post_date_gmt' => get_gmt_from_date( $last_run ),
                ) );
            }
        }

        update_option( 'npc_healthcheck_migrated_v04', true );
    }

    // =========================================
    // 管理画面
    // =========================================

    /**
     * 管理画面メニューを追加
     * 許可ユーザーにだけ表示する
     */
    public function add_admin_menu() {
        // 許可ユーザーでなければメニューを追加しない
        if ( ! $this->is_allowed_user() ) {
            return;
        }

        add_menu_page(
            'WP Healthcheck',
            'Healthcheck',
            'manage_options',
            'npc-healthcheck',
            array( $this, 'render_dashboard' ),
            'dashicons-heart',
            80
        );

        add_submenu_page(
            'npc-healthcheck',
            '設定 — WP Healthcheck',
            '設定',
            'manage_options',
            'npc-healthcheck-settings',
            array( $this, 'render_settings' )
        );
    }

    /**
     * 管理画面用のCSS/JSを読み込む
     */
    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'npc-healthcheck' ) === false ) {
            return;
        }

        // 許可ユーザーでなければ読み込まない
        if ( ! $this->is_allowed_user() ) {
            return;
        }

        wp_enqueue_style(
            'npc-healthcheck-admin',
            NPC_HEALTHCHECK_URL . 'assets/css/admin.css',
            array(),
            NPC_HEALTHCHECK_VERSION
        );

        wp_enqueue_script(
            'npc-healthcheck-admin',
            NPC_HEALTHCHECK_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            NPC_HEALTHCHECK_VERSION,
            true
        );

        // 診断履歴をJSに渡す（ダッシュボードHealthcheck画面でのみ）
        $history = array();
        if ( strpos( $hook, 'npc-healthcheck' ) !== false && strpos( $hook, 'settings' ) === false ) {
            $this->maybe_migrate_legacy_option();
            $history = $this->get_healthcheck_logs();
        }

        wp_localize_script( 'npc-healthcheck-admin', 'npcHealthcheck', array(
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'npc_healthcheck_nonce' ),
            'history'  => $history,
            'siteName' => get_bloginfo( 'name' ),
        ) );
    }

    /**
     * ダッシュボードページの表示
     */
    public function render_dashboard() {
        if ( ! $this->is_allowed_user() ) {
            wp_die( 'このページへのアクセス権限がありません。' );
        }

        // 初回アクセス時に旧optionデータをCPTに移行
        $this->maybe_migrate_legacy_option();

        $api_key = self::get_api_key();
        include NPC_HEALTHCHECK_PATH . 'templates/dashboard.php';
    }

    /**
     * 設定ページの表示
     * APIキーの設定はwp-config.php方式に変更したので、設定画面はガイド表示のみ
     */
    public function render_settings() {
        if ( ! $this->is_allowed_user() ) {
            wp_die( 'このページへのアクセス権限がありません。' );
        }

        include NPC_HEALTHCHECK_PATH . 'templates/settings.php';
    }

    // =========================================
    // AJAX
    // =========================================

    /**
     * AJAX: 診断を実行して結果をJSONで返す
     * 新しいログ投稿を作成し、その投稿IDを「現在のログ」としてoptionに記録する
     */
    public function ajax_run_healthcheck() {
        check_ajax_referer( 'npc_healthcheck_nonce', 'nonce' );

        if ( ! $this->is_allowed_user() ) {
            wp_send_json_error( 'この操作を実行する権限がありません。' );
        }

        $checker = new NPC_Checker();
        $results = $checker->run_all_checks();

        // 後方互換: optionにも最新分を保持
        update_option( 'npc_healthcheck_last_results', $results );
        update_option( 'npc_healthcheck_last_run', current_time( 'mysql' ) );

        // 履歴CPTに新しいログを作成
        $log_id = $this->save_healthcheck_log( $results );
        if ( ! is_wp_error( $log_id ) ) {
            update_option( 'npc_healthcheck_current_log_id', $log_id );
        }

        wp_send_json_success( array(
            'results' => $results,
            'log_id'  => is_wp_error( $log_id ) ? 0 : $log_id,
            'date'    => current_time( 'Y-m-d H:i' ),
        ) );
    }

    /**
     * AJAX: AIレポートを生成
     * 現在のログ投稿にレポートを紐付けて保存する
     */
    public function ajax_generate_report() {
        check_ajax_referer( 'npc_healthcheck_nonce', 'nonce' );

        if ( ! $this->is_allowed_user() ) {
            wp_send_json_error( 'この操作を実行する権限がありません。' );
        }

        // AI機能が無効化されている（APIキー未設定）場合は早期エラー
        // フロント側で button が非表示でも、念のためサーバー側でも防御する
        if ( ! self::is_ai_available() ) {
            wp_send_json_error( 'AIレポート機能は無効です。wp-config.php に NPC_HEALTHCHECK_API_KEY を設定するか、npc保守契約をご利用ください。' );
        }

        $results = get_option( 'npc_healthcheck_last_results', array() );
        if ( empty( $results ) ) {
            wp_send_json_error( '先に診断を実行してください。' );
        }

        $reporter = new NPC_AI_Reporter();
        $report   = $reporter->generate( $results );

        if ( is_wp_error( $report ) ) {
            wp_send_json_error( $report->get_error_message() );
        }

        // 後方互換
        update_option( 'npc_healthcheck_last_report', $report );

        // 現在のログ投稿にレポートを紐付け
        $log_id = (int) get_option( 'npc_healthcheck_current_log_id', 0 );
        if ( $log_id && get_post_status( $log_id ) === 'publish' ) {
            $this->attach_report_to_log( $log_id, $report );
        }

        wp_send_json_success( array(
            'report' => $report,
            'log_id' => $log_id,
        ) );
    }

    /**
     * AJAX: debug.logをクリア（truncate）
     * - nonce検証 + 許可ユーザー + manage_options で三重チェック
     * - file_put_contents('') でファイル自体は残す（権限・所有者を維持）
     */
    public function ajax_clear_error_log() {
        check_ajax_referer( 'npc_healthcheck_nonce', 'nonce' );

        if ( ! $this->is_allowed_user() ) {
            wp_send_json_error( 'この操作を実行する権限がありません。' );
        }

        // 念のため capability も確認（二重防御）
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '管理者権限が必要です。' );
        }

        $log_file = WP_CONTENT_DIR . '/debug.log';

        if ( ! file_exists( $log_file ) ) {
            wp_send_json_error( 'debug.logが存在しません。' );
        }

        if ( ! is_writable( $log_file ) ) {
            wp_send_json_error( 'debug.logへの書き込み権限がありません。FTPでパーミッションを確認してください。' );
        }

        $before_size = filesize( $log_file );

        // truncate: 削除ではなく中身だけ空にする（所有者・パーミッションを維持）
        $result = file_put_contents( $log_file, '' );

        if ( $result === false ) {
            wp_send_json_error( 'debug.logのクリアに失敗しました。' );
        }

        wp_send_json_success( array(
            'bytes_cleared'    => (int) $before_size,
            'formatted_size'   => size_format( $before_size, 2 ),
            'message'          => sprintf( '%s を削除しました', size_format( $before_size, 2 ) ),
        ) );
    }

    /**
     * AJAX: 通知メールのテスト送信
     * ダミーのcritical issueでNPC_Notifierを呼び、メール到達確認に使う
     */
    public function ajax_test_notification() {
        check_ajax_referer( 'npc_healthcheck_nonce', 'nonce' );

        if ( ! $this->is_allowed_user() ) {
            wp_send_json_error( 'この操作を実行する権限がありません。' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( '管理者権限が必要です。' );
        }

        $email = $this->get_notify_email();
        if ( empty( $email ) ) {
            wp_send_json_error( '通知先メールアドレスが設定されていません。' );
        }

        // ダミーの診断結果とissueを作成
        $dummy_results = array(
            'site_info' => array(
                'site_name' => get_bloginfo( 'name' ),
                'site_url'  => get_site_url(),
            ),
        );

        $dummy_issues = array(
            array(
                'key'    => 'test_notification',
                'label'  => 'これはテスト通知です（実際の問題ではありません）',
                'detail' => "自動診断の通知機能が正しく動作するかを確認するためのテストメールです。\n本番では、以下のような問題を検出したときにこのメールが届きます：\n  - ファイル改ざん/不審コード検出\n  - uploads内の不審PHPファイル\n  - SSL期限切れ間近\n  - サイトヘルスの致命的問題",
            ),
        );

        $sent = NPC_Notifier::send( $email, $dummy_results, $dummy_issues, '' );

        if ( ! $sent ) {
            wp_send_json_error( 'メール送信に失敗しました。WordPressのメール設定を確認してください。' );
        }

        wp_send_json_success( array(
            'email'   => $email,
            'message' => sprintf( '%s にテストメールを送信しました。受信できるか確認してください。', $email ),
        ) );
    }
}

// プラグイン起動
NPC_WP_Healthcheck::get_instance();
