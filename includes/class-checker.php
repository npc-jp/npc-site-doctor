<?php
/**
 * 診断チェッカークラス
 * 各項目のチェックを実行し、結果を配列で返す
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NPCMI_Checker {

    /**
     * 全チェックを実行
     * 各チェックメソッドを順に呼び、結果をまとめて返す
     *
     * @return array 診断結果の連想配列
     */
    public function run_all_checks() {
        return array(
            'site_info'         => $this->check_site_info(),
            'core_updates'      => $this->check_core_updates(),
            'plugin_updates'    => $this->check_plugin_updates(),
            'site_health'       => $this->check_site_health(),
            'php_version'       => $this->check_php_version(),
            'error_log'         => $this->check_error_log(),
            'file_integrity'    => $this->check_file_integrity(),
            'suspicious_files'  => $this->check_suspicious_files(),
            'ssl_certificate'   => $this->check_ssl_certificate(),
            'file_permissions'  => $this->check_file_permissions(),
            'checked_at'        => current_time( 'mysql' ),
        );
    }

    /**
     * サイト基本情報
     */
    private function check_site_info() {
        return array(
            'site_url'   => get_site_url(),
            'site_name'  => get_bloginfo( 'name' ),
            'wp_version' => get_bloginfo( 'version' ),
            'theme'      => wp_get_theme()->get( 'Name' ),
        );
    }

    /**
     * WordPress本体の更新状況
     * 最新版との比較で更新が必要かどうかを判定
     */
    private function check_core_updates() {
        // 更新情報を強制取得
        wp_version_check();
        $update_data = get_site_transient( 'update_core' );

        $result = array(
            'current_version' => get_bloginfo( 'version' ),
            'status'          => 'ok', // ok / update_available / unknown
            'latest_version'  => '',
        );

        if ( ! empty( $update_data->updates ) ) {
            foreach ( $update_data->updates as $update ) {
                if ( 'upgrade' === $update->response ) {
                    $result['status']         = 'update_available';
                    $result['latest_version'] = $update->current;
                    break;
                }
            }
        }

        if ( 'ok' === $result['status'] ) {
            $result['latest_version'] = $result['current_version'];
        }

        return $result;
    }

    /**
     * プラグインの更新状況
     * 更新が必要なプラグインの一覧を返す
     */
    private function check_plugin_updates() {
        wp_update_plugins();
        $update_data = get_site_transient( 'update_plugins' );

        $updates_needed = array();

        if ( ! empty( $update_data->response ) ) {
            $all_plugins = get_plugins();

            foreach ( $update_data->response as $plugin_file => $plugin_data ) {
                $name = isset( $all_plugins[ $plugin_file ] )
                    ? $all_plugins[ $plugin_file ]['Name']
                    : $plugin_file;

                $updates_needed[] = array(
                    'name'            => $name,
                    'current_version' => $all_plugins[ $plugin_file ]['Version'] ?? __( 'unknown', 'npc-maintenance-inspector' ),
                    'new_version'     => $plugin_data->new_version ?? __( 'unknown', 'npc-maintenance-inspector' ),
                );
            }
        }

        return array(
            'total_plugins'    => count( get_plugins() ),
            'active_plugins'   => count( get_option( 'active_plugins', array() ) ),
            'updates_needed'   => $updates_needed,
            'updates_count'    => count( $updates_needed ),
            'status'           => empty( $updates_needed ) ? 'ok' : 'update_available',
        );
    }

    /**
     * サイトヘルス情報の取得
     * WP標準のサイトヘルスAPIから情報を引っ張る
     */
    private function check_site_health() {
        // WPのサイトヘルスクラスを読み込む
        if ( ! class_exists( 'WP_Site_Health' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
        }

        $health = WP_Site_Health::get_instance();

        // 各テストを実行（直接テストのみ。非同期テストは時間がかかるため除外）
        $tests  = WP_Site_Health::get_tests();
        $issues = array(
            'critical'    => array(),
            'recommended' => array(),
            'good'        => array(),
        );

        if ( ! empty( $tests['direct'] ) ) {
            foreach ( $tests['direct'] as $test_key => $test ) {
                // テスト実行
                if ( is_callable( $test['test'] ) ) {
                    $result = call_user_func( $test['test'] );

                    if ( ! empty( $result ) && isset( $result['status'] ) ) {
                        $entry = array(
                            'label'  => $result['label'] ?? $test_key,
                            'status' => $result['status'],
                        );

                        if ( 'critical' === $result['status'] ) {
                            $issues['critical'][] = $entry;
                        } elseif ( 'recommended' === $result['status'] ) {
                            $issues['recommended'][] = $entry;
                        } else {
                            $issues['good'][] = $entry;
                        }
                    }
                }
            }
        }

        return array(
            'critical_count'    => count( $issues['critical'] ),
            'recommended_count' => count( $issues['recommended'] ),
            'good_count'        => count( $issues['good'] ),
            'issues'            => $issues,
        );
    }

    /**
     * PHPバージョンとサーバー環境
     */
    private function check_php_version() {
        return array(
            'php_version'     => phpversion(),
            'server_software' => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
            'max_memory'      => ini_get( 'memory_limit' ),
            'max_upload'      => ini_get( 'upload_max_filesize' ),
            'max_post'        => ini_get( 'post_max_size' ),
            'max_execution'   => ini_get( 'max_execution_time' ),
            // PHP 8.0以上を推奨
            'status'          => version_compare( phpversion(), '8.0', '>=' ) ? 'ok' : 'warning',
        );
    }

    /**
     * エラーログの確認
     * debug.logの直近エラーを取得する
     */
    private function check_error_log() {
        $log_file = $this->get_debug_log_path();

        if ( ! $log_file || ! file_exists( $log_file ) ) {
            return array(
                'exists' => false,
                'status' => 'ok',
                'note'   => __( 'debug.log does not exist (WP_DEBUG may be disabled).', 'npc-maintenance-inspector' ),
                'errors' => array(),
            );
        }

        $file_size = filesize( $log_file );

        // ファイルサイズが大きすぎる場合は末尾だけ読む
        $max_read = 50000; // 50KB
        $content  = '';

        if ( $file_size > $max_read ) {
            $fp = fopen( $log_file, 'r' );
            fseek( $fp, -$max_read, SEEK_END );
            $content = fread( $fp, $max_read );
            fclose( $fp );
        } else {
            $content = file_get_contents( $log_file );
        }

        // 直近のエラー行を抽出（Fatal, Warning, Notice）
        $lines  = explode( "\n", $content );
        $errors = array();
        $count  = 0;

        // 新しい方から50件まで
        $lines = array_reverse( $lines );
        foreach ( $lines as $line ) {
            if ( $count >= 50 ) break;

            if ( preg_match( '/(Fatal error|Warning|Notice|Deprecated)/i', $line ) ) {
                $errors[] = trim( $line );
                $count++;
            }
        }

        // 重複を除去してユニークなエラーだけ残す
        $unique_errors = array_unique( $errors );

        return array(
            'exists'       => true,
            'file_size'    => size_format( $file_size ),
            'status'       => empty( $unique_errors ) ? 'ok' : 'warning',
            'error_count'  => count( $unique_errors ),
            'errors'       => array_slice( $unique_errors, 0, 20 ), // 最大20件
            // ログファイルが巨大な場合は警告
            'size_warning' => $file_size > 10485760, // 10MB超
        );
    }

    /**
     * ファイル改ざん検知（簡易版）
     * WPコアファイルのチェックサムをWordPress.org APIと比較
     */
    private function check_file_integrity() {
        $wp_version = get_bloginfo( 'version' );
        $locale     = get_locale();

        // WordPress.org APIからチェックサムを取得
        // ロケール版で取れなければen_USにフォールバック（日本語版はチェックサムがずれることがある）
        $url      = "https://api.wordpress.org/core/checksums/1.0/?version={$wp_version}&locale={$locale}";
        $response = wp_remote_get( $url, array( 'timeout' => 15 ) );
        $body     = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['checksums'] ) && 'en_US' !== $locale ) {
            $url      = "https://api.wordpress.org/core/checksums/1.0/?version={$wp_version}&locale=en_US";
            $response = wp_remote_get( $url, array( 'timeout' => 15 ) );
        }

        if ( is_wp_error( $response ) ) {
            return array(
                'status' => 'unknown',
                'note'   => __( 'Could not connect to WordPress.org API.', 'npc-maintenance-inspector' ),
                'modified_files' => array(),
            );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['checksums'] ) ) {
            return array(
                'status' => 'unknown',
                'note'   => __( 'Could not retrieve checksum data.', 'npc-maintenance-inspector' ),
                'modified_files' => array(),
            );
        }

        $modified_files = array();

        // ロケールやマイナーアップデートのタイミングで誤検知しやすいファイル
        // これらは「要確認」として分離表示し、いきなり「改ざん」とは言わない
        $false_positive_prone = array(
            'wp-includes/version.php',
            'wp-includes/class-wp-locale.php',
            'wp-config-sample.php',
            'readme.html',
            'license.txt',
        );

        // 主要ファイルだけチェック（全ファイルだと重いので）
        $check_patterns = array(
            'wp-admin/',
            'wp-includes/',
            'wp-login.php',
            'wp-config-sample.php',
            'xmlrpc.php',
            'index.php',
        );

        $suspect_files = array(); // 誤検知の可能性があるファイル

        foreach ( $body['checksums'] as $file => $checksum ) {
            // チェック対象のパターンに合致するファイルだけ
            $should_check = false;
            foreach ( $check_patterns as $pattern ) {
                if ( strpos( $file, $pattern ) === 0 || $file === $pattern ) {
                    $should_check = true;
                    break;
                }
            }

            if ( ! $should_check ) continue;

            $file_path = ABSPATH . $file;
            if ( ! file_exists( $file_path ) ) continue;

            $local_hash = md5_file( $file_path );
            if ( $local_hash !== $checksum ) {
                // ファイルの中身をスキャンして不審なコードパターンがないかチェック
                $danger_scan = $this->scan_file_for_danger( $file_path );

                $file_info = array(
                    'file'             => $file,
                    'has_danger'       => $danger_scan['has_danger'],
                    'danger_patterns'  => $danger_scan['found_patterns'],
                );

                // 誤検知しやすいファイルは分離
                if ( in_array( $file, $false_positive_prone, true ) ) {
                    $suspect_files[] = $file_info;
                } else {
                    $modified_files[] = $file_info;
                }
            }
        }

        // 判定ロジック:
        // - 不審コードが見つかったファイルがある → critical
        // - チェックサムずれあるが不審コードなし（誤検知リスト外） → warning
        // - 誤検知リストのファイルだけ、かつ不審コードなし → warning（軽め）
        // - どちらもなし → ok
        $has_any_danger = false;
        foreach ( array_merge( $modified_files, $suspect_files ) as $f ) {
            if ( $f['has_danger'] ) {
                $has_any_danger = true;
                break;
            }
        }

        $status = 'ok';
        if ( $has_any_danger ) {
            $status = 'critical';
        } elseif ( ! empty( $modified_files ) ) {
            $status = 'warning';
        } elseif ( ! empty( $suspect_files ) ) {
            $status = 'warning';
        }

        // ノート生成
        $note = '';
        if ( $has_any_danger ) {
            $note = __( 'Suspicious code patterns (eval, base64_decode, etc.) were detected. There is a high possibility of tampering, and immediate verification is required.', 'npc-maintenance-inspector' );
        } elseif ( ! empty( $suspect_files ) && empty( $modified_files ) ) {
            $note = __( 'Checksum mismatches were found, but no suspicious code (eval, base64_decode, etc.) was detected. This is likely a normal difference caused by locale or automatic WP updates.', 'npc-maintenance-inspector' );
        } elseif ( ! empty( $modified_files ) ) {
            $note = __( 'Core files have checksum mismatches. No suspicious code was detected, but unintended changes may be present.', 'npc-maintenance-inspector' );
        }

        return array(
            'status'         => $status,
            'modified_files' => $modified_files,
            'modified_count' => count( $modified_files ),
            'suspect_files'  => $suspect_files,
            'suspect_count'  => count( $suspect_files ),
            'has_danger'     => $has_any_danger,
            'checked_files'  => count( $body['checksums'] ),
            'note'           => $note,
        );
    }

    /**
     * ファイル内の危険なコードパターンをスキャン
     * 改ざんされたファイルに典型的に含まれるパターンを検出する
     *
     * @param string $file_path チェック対象のファイルパス
     * @return array has_danger(bool) と found_patterns(array)
     */
    private function scan_file_for_danger( $file_path ) {
        // ファイルが読めない、または大きすぎる場合はスキップ
        if ( ! is_readable( $file_path ) || filesize( $file_path ) > 1048576 ) { // 1MB上限
            return array( 'has_danger' => false, 'found_patterns' => array() );
        }

        $content = file_get_contents( $file_path );
        $found   = array();

        // 改ざんでよく使われる危険パターン
        $danger_patterns = array(
            'eval('                  => __( 'eval() — executes a string as PHP code. Not used in legitimate WP core.', 'npc-maintenance-inspector' ),
            'base64_decode('         => __( 'base64_decode() — often abused to hide encoded malicious code.', 'npc-maintenance-inspector' ),
            'gzinflate('             => __( 'gzinflate() — used to decompress malicious payloads.', 'npc-maintenance-inspector' ),
            'str_rot13('             => __( 'str_rot13() — used for code obfuscation.', 'npc-maintenance-inspector' ),
            'preg_replace'           => false, // 単体では正規利用が多いのでスキップ
            'assert('                => __( 'assert() — eval-equivalent dangerous function.', 'npc-maintenance-inspector' ),
            'create_function('       => __( 'create_function() — deprecated function that builds code dynamically.', 'npc-maintenance-inspector' ),
            'call_user_func('        => false, // 正規利用が多い
            'shell_exec('            => __( 'shell_exec() — executes a shell command on the server.', 'npc-maintenance-inspector' ),
            'passthru('              => __( 'passthru() — runs external commands.', 'npc-maintenance-inspector' ),
            'system('                => false, // 誤検知が多いのでスキップ
            '$$'                     => false, // 可変変数は正規利用が多い
            'file_put_contents('     => false, // 正規利用あり
            'chmod('                 => __( 'chmod() — changes file permissions. Should not appear in core files.', 'npc-maintenance-inspector' ),
            'error_reporting(0)'     => __( 'error_reporting(0) — intentionally hides error output.', 'npc-maintenance-inspector' ),
            '@ini_set'               => false, // 正規利用あり
        );

        // 難読化パターン（正規表現で検出）
        $regex_patterns = array(
            '/\\\\x[0-9a-f]{2}.*\\\\x[0-9a-f]{2}.*\\\\x[0-9a-f]{2}/i'
                => __( 'Consecutive hex escapes — a common code obfuscation pattern.', 'npc-maintenance-inspector' ),
            '/\$[a-zA-Z_]+\s*=\s*["\'][A-Za-z0-9+\/=]{100,}["\']/'
                => __( 'Long Base64 string assignment — possible encoded malicious payload.', 'npc-maintenance-inspector' ),
            '/\$[a-zA-Z_]+\(\s*\$[a-zA-Z_]+\s*\(/'
                => __( 'Nested variable function call — dynamic code execution obfuscation pattern.', 'npc-maintenance-inspector' ),
        );

        // 文字列パターンの検出
        foreach ( $danger_patterns as $pattern => $description ) {
            if ( false === $description ) continue; // スキップ対象
            if ( stripos( $content, $pattern ) !== false ) {
                $found[] = $description;
            }
        }

        // 正規表現パターンの検出
        foreach ( $regex_patterns as $regex => $description ) {
            if ( preg_match( $regex, $content ) ) {
                $found[] = $description;
            }
        }

        return array(
            'has_danger'      => ! empty( $found ),
            'found_patterns'  => $found,
        );
    }

    /**
     * 不審なファイルの検出
     * wp-content以下にある「あるべきでない」PHPファイルを検出
     *
     * v0.7.0: 既知プラグインの正規ファイルと「Silence is golden」だけのindex.phpを除外
     */
    private function check_suspicious_files() {
        $suspicious = array();

        // アップロードディレクトリにPHPがあったら怪しい
        $upload_info = wp_upload_dir();
        $check_dirs  = array();
        if ( ! empty( $upload_info['basedir'] ) ) {
            $check_dirs[] = $upload_info['basedir'];
        }

        foreach ( $check_dirs as $dir ) {
            if ( ! is_dir( $dir ) ) continue;

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ( $iterator as $file ) {
                if ( ! $file->isFile() ) continue;
                if ( ! preg_match( '/\.php$/i', $file->getFilename() ) ) continue;

                $relative = str_replace( ABSPATH, '', $file->getPathname() );

                // 既知プラグインの正規ファイル + 中身検証で安全と確認できた場合のみスキップ
                if ( $this->is_known_plugin_file( $relative, $file->getPathname() ) ) continue;

                // 中身が「Silence is golden」だけのindex.php → 安全判定でスキップ
                if ( $this->is_silence_is_golden( $file->getPathname() ) ) continue;

                $suspicious[] = $relative;
            }
        }

        return array(
            'status'           => empty( $suspicious ) ? 'ok' : 'warning',
            'suspicious_files' => array_slice( $suspicious, 0, 50 ), // 最大50件
            'suspicious_count' => count( $suspicious ),
        );
    }

    /**
     * 既知プラグインの正規ファイルかを判定（v0.7.4: 二重判定）
     *
     * パスマッチだけでは「攻撃者がホワイトリストパスに偽ファイルを置いた」場合に素通りしてしまう。
     * そこで、パスマッチ後に**ファイル中身の先頭バイト or 危険関数の有無**を検証する二重判定を実装。
     *
     * 型:
     * - 'storage'    : 先頭が `__halt_compiler();` / `exit;` / `die;` で始まる（データストレージ用）
     * - 'silence'    : 先頭が `Silence is golden` / 実行停止パターン / 空のPHPタグのみ
     * - 'template'   : PHPテンプレートだが、eval/base64_decode 等の危険関数を含まない
     */
    private function is_known_plugin_file( $relative_path, $absolute_path ) {
        // パターン: { 正規表現, 検証タイプ }
        $patterns = array(
            // テンプレート型（PHP実行可能だが、WP標準関数の組み合わせのみのはず）
            array( '#wp-content/uploads/alm_templates/.*\.php$#',        'template' ),  // Ajax Load More
            array( '#wp-content/uploads/wpforms/.*\.php$#',              'template' ),  // WPForms

            // データストレージ型（__halt_compiler / exit / die で実行停止する設計）
            array( '#wp-content/uploads/aios/.*\.php$#',                 'storage' ),   // AIOS firewall-rules
            array( '#wp-content/uploads/wp-personal-data-exports/.*\.php$#', 'storage' ), // WP Privacy Tool

            // インデックス防御型（Silence is golden 等）+ ストレージ兼用
            array( '#wp-content/uploads/wp-staging/.*\.php$#',           'silence' ),   // WP STAGING
            array( '#wp-content/uploads/wp-rollback/.*\.php$#',          'silence' ),   // WP Rollback
            array( '#wp-content/uploads/updraft/.*\.php$#',              'silence' ),   // UpdraftPlus
            array( '#wp-content/uploads/backwpup-.*/.*\.php$#',          'silence' ),   // BackWPup
            array( '#wp-content/uploads/duplicator.*/.*\.php$#',         'silence' ),   // Duplicator
            array( '#wp-content/uploads/wpcf7_uploads/.*\.php$#',        'silence' ),   // CF7
            array( '#wp-content/uploads/wordfence/.*\.php$#',            'silence' ),   // Wordfence
            array( '#wp-content/uploads/ithemes-security/.*\.php$#',     'silence' ),   // iThemes Security
        );

        foreach ( $patterns as $entry ) {
            list( $pattern, $type ) = $entry;
            if ( ! preg_match( $pattern, $relative_path ) ) continue;
            // パスがマッチしたら中身を検証
            return $this->verify_plugin_file_safety( $absolute_path, $type );
        }
        return false;
    }

    /**
     * ホワイトリストにマッチしたファイルの中身を検証して、本当に安全かを判定
     */
    private function verify_plugin_file_safety( $absolute_path, $type ) {
        // 大きすぎるファイルは「ホワイトリストでも信用しない」（不審扱い）
        if ( filesize( $absolute_path ) > 1024 * 1024 ) return false; // 1MB超

        $content = @file_get_contents( $absolute_path );
        if ( $content === false ) return false;

        // BOM除去 + 先頭空白除去（v0.7.7: BOM付きUTF-8対応）
        $clean = preg_replace( '/^[\xEF\xBB\xBF\s]+/', '', $content );

        // 先頭の300文字を取得（先頭バイト判定用）
        $head = substr( $clean, 0, 300 );

        // 即時実行停止パターン（先頭で確実に止まる系）
        $halt_patterns = array(
            '#^<\?php\s+__halt_compiler\s*\(\s*\)\s*;#i',
            '#^<\?php\s+(exit|die)\s*\(?\s*\)?\s*;#i',
            '#^<\?php\s+return\s*;#i',
            '#^<\?php\s+if\s*\(\s*!\s*defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)\s*\)\s*(\{\s*)?(exit|die)#i',
        );
        foreach ( $halt_patterns as $p ) {
            if ( preg_match( $p, $head ) ) return true;
        }

        // v0.7.8: 汎用判定 — ファイル全体が「PHPタグ + コメント + 空白」のみで実行コードを含まない場合は安全
        // これでWP STAGING / Silence is golden / プラグイン独自docblock 等が一律対応できる
        if ( $this->is_comments_only_php( $clean ) ) return true;

        // 型別判定
        if ( $type === 'storage' || $type === 'silence' ) {
            // データストレージ型・サイレンス型は「実行停止パターン」が必須
            // 上記のhalt_patterns・空PHPタグでマッチしなかったら不審扱い
            return false;
        }

        if ( $type === 'template' ) {
            // テンプレート型: 危険関数を含まないか確認
            $dangerous_signatures = array(
                'eval(',
                'base64_decode(',
                'base64_encode(',  // 攻撃の前段で使われがち
                'gzinflate(',
                'gzuncompress(',
                'str_rot13(',
                'assert(',
                'create_function(',
                'system(',
                'shell_exec(',
                'passthru(',
                'proc_open(',
                'popen(',
                'eval ',  // スペース区切り版
            );
            foreach ( $dangerous_signatures as $sig ) {
                if ( stripos( $content, $sig ) !== false ) return false;
            }
            // preg_replace の /e モディファイア（PHP 7+で廃止だが念のため）
            if ( preg_match( '#preg_replace\s*\([^)]*[\'"][^\'"]*[\'"]\s*[\.\,]\s*[\'"]/[a-z]*e[a-z]*[\'"]#i', $content ) ) {
                return false;
            }
            return true;
        }

        return false;
    }

    /**
     * v0.7.8: ファイルが「PHPタグ + コメント + 空白」のみで構成されているか判定
     * 実行可能なPHPコード（変数・関数呼び出し・制御文）が一切含まれない場合 true
     *
     * 安全例（true を返す）: <?php に Silence is golden 系コメントだけ / WPSTAGING の docblock だけ / 空のPHPタグ
     * 危険例（false を返す）: echo / 変数代入 / 関数呼び出し / 制御文
     */
    private function is_comments_only_php( $content ) {
        // 先頭の <?php タグを除去
        $stripped = preg_replace( '#^<\?php\b#i', '', $content, 1 );
        if ( $stripped === null ) return false;

        // 終端のクローズタグを除去（行コメント内に閉じタグを書かない）
        $stripped = preg_replace( '#\?>\s*$#', '', $stripped );

        // ブロックコメントを全て削除（docblockも含む）
        $stripped = preg_replace( '#/\*[\s\S]*?\*/#', '', $stripped );

        // 行コメント // ... と # ... を全て削除
        $stripped = preg_replace( '#//[^\n]*#', '', $stripped );
        $stripped = preg_replace( '/^[ \t]*#[^\n]*/m', '', $stripped );

        // 空白・改行を全て削除
        $stripped = preg_replace( '/\s+/', '', $stripped );

        // 残ったものが空、または ; だけ → 実行コードなし＝安全
        return ( $stripped === '' || $stripped === ';' );
    }

    /**
     * 中身が `<?php // Silence is golden` だけの index.php かを判定
     * ディレクトリリスティング防止用の安全ファイル（WP標準パターン）
     */
    private function is_silence_is_golden( $absolute_path ) {
        if ( basename( $absolute_path ) !== 'index.php' ) return false;
        // 大きいファイルは読まない（safety guard）
        if ( filesize( $absolute_path ) > 200 ) return false;

        $content = @file_get_contents( $absolute_path );
        if ( $content === false ) return false;

        // 改行・空白を除去して正規化
        $normalized = preg_replace( '/\s+/', ' ', trim( $content ) );

        // 既知パターン（WP標準のSilence is golden bait）
        $silence_patterns = array(
            '<?php // Silence is golden',
            '<?php // Silence is golden.',
            '<?php //Silence is golden',
            '<?php /* Silence is golden. */',
            '<?php /* Silence is golden */',
            '<?php',  // 空のindex.php（中身ほぼなし）
        );

        foreach ( $silence_patterns as $pattern ) {
            if ( $normalized === $pattern ) return true;
        }
        return false;
    }

    /**
     * SSL証明書の期限チェック
     */
    private function check_ssl_certificate() {
        $site_url = get_site_url();

        // HTTPSでなければスキップ
        if ( strpos( $site_url, 'https://' ) !== 0 ) {
            return array(
                'status' => 'warning',
                'note'   => __( 'Site is not using HTTPS.', 'npc-maintenance-inspector' ),
            );
        }

        $host = wp_parse_url( $site_url, PHP_URL_HOST );

        // SSL証明書情報を取得
        $context = stream_context_create( array(
            'ssl' => array(
                'capture_peer_cert' => true,
                'verify_peer'       => false,
            ),
        ) );

        $stream = @stream_socket_client(
            "ssl://{$host}:443",
            $errno,
            $errstr,
            10,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ( ! $stream ) {
            return array(
                'status' => 'unknown',
                'note'   => sprintf(
                    /* translators: %s: SSL error message from stream_socket_client */
                    __( 'SSL connection failed: %s', 'npc-maintenance-inspector' ),
                    $errstr
                ),
            );
        }

        $params   = stream_context_get_params( $stream );
        $cert     = openssl_x509_parse( $params['options']['ssl']['peer_certificate'] );
        fclose( $stream );

        if ( ! $cert ) {
            return array(
                'status' => 'unknown',
                'note'   => __( 'Failed to parse certificate.', 'npc-maintenance-inspector' ),
            );
        }

        $expires_at   = $cert['validTo_time_t'];
        $days_left    = floor( ( $expires_at - time() ) / 86400 );

        // 30日以内に期限切れなら警告
        $status = 'ok';
        if ( $days_left <= 0 ) {
            $status = 'critical';
        } elseif ( $days_left <= 30 ) {
            $status = 'warning';
        }

        return array(
            'status'     => $status,
            'issuer'     => $cert['issuer']['O'] ?? __( 'unknown', 'npc-maintenance-inspector' ),
            'expires_at' => date( 'Y-m-d', $expires_at ),
            'days_left'  => $days_left,
        );
    }

    /**
     * ファイルパーミッションのチェック
     * 重要ファイル/ディレクトリのパーミッションが適切か確認
     */
    private function check_file_permissions() {
        $upload_info = wp_upload_dir();
        $uploads_dir = ! empty( $upload_info['basedir'] ) ? $upload_info['basedir'] : null;
        $themes_root = get_theme_root();

        $checks = array(
            // ファイルパス => 推奨パーミッション
            array( 'path' => ABSPATH . 'wp-config.php', 'recommended' => '0440', 'type' => 'file' ),
            array( 'path' => ABSPATH . '.htaccess',     'recommended' => '0644', 'type' => 'file' ),
            array( 'path' => WP_CONTENT_DIR,            'recommended' => '0755', 'type' => 'dir' ),
            array( 'path' => WP_PLUGIN_DIR,             'recommended' => '0755', 'type' => 'dir' ),
        );

        if ( $uploads_dir ) {
            $checks[] = array( 'path' => $uploads_dir, 'recommended' => '0755', 'type' => 'dir' );
        }
        if ( $themes_root ) {
            $checks[] = array( 'path' => $themes_root, 'recommended' => '0755', 'type' => 'dir' );
        }

        $results  = array();
        $warnings = 0;

        foreach ( $checks as $check ) {
            if ( ! file_exists( $check['path'] ) ) continue;

            $perms    = substr( sprintf( '%o', fileperms( $check['path'] ) ), -4 );
            $is_ok    = ( $perms === $check['recommended'] );
            $rel_path = str_replace( ABSPATH, '', $check['path'] );

            if ( ! $is_ok ) $warnings++;

            $results[] = array(
                'path'        => $rel_path ?: basename( $check['path'] ),
                'current'     => $perms,
                'recommended' => $check['recommended'],
                'status'      => $is_ok ? 'ok' : 'warning',
            );
        }

        return array(
            'status'   => $warnings === 0 ? 'ok' : 'warning',
            'warnings' => $warnings,
            'checks'   => $results,
        );
    }

    /**
     * debug.log のパスを取得（WP_DEBUG_LOG 定数を尊重）
     * - WP_DEBUG_LOG が文字列ならカスタムパス
     * - true なら標準位置（WP_CONTENT_DIR/debug.log）
     * - それ以外なら null
     */
    public function get_debug_log_path() {
        if ( defined( 'WP_DEBUG_LOG' ) ) {
            if ( is_string( WP_DEBUG_LOG ) && WP_DEBUG_LOG !== '' ) {
                return WP_DEBUG_LOG;
            }
            if ( WP_DEBUG_LOG === true ) {
                return WP_CONTENT_DIR . '/debug.log';
            }
        }
        return null;
    }
}
