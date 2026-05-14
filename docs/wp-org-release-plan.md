# NPC Maintenance Inspector — WP.org公開向け実装計画書

**作成日**: 2026-05-11
**バージョン**: v1.0.0 最小構成リリース版
**戦略**: 段階リリース（v1.0.0 = 既存機能のWP.org対応のみ / v1.1.0以降で新機能追加）

---

## 0. 段階リリース戦略（最重要）

### v1.0.0（今日リリース）— **最小構成・約9.5h**
- リネーム（`npc-wp-healthcheck` → `npc-maintenance-inspector`）
- i18n完全英語化
- readme.txt作成（English + External services セクション）
- 規約遵守（早期サニタイズ・admin_notices ガード等）
- **新機能は入れない**（既存機能のまま）

### v1.1.0（審査待ち期間中・約4h）
- 既知プラグイン辞書を `includes/dictionaries/known-plugins.php` に分離
- ローカル無視リスト機能（検出結果に「これは正常」ボタン）
- AJAX handler + 設定画面UI

### v1.2.0（v1.1後・約5.5h）
- フィードバック送信機能（オプトイン）
- npcサーバー側受信エンドポイント（`https://n-pc.jp/api/healthcheck-feedback/`）
- 共有秘密トークン認証ではなく Origin/UA + RateLimit + KillSwitch

### v1.3.0（v1.2後・約3h）
- 既存20サイトから `npc-wp-healthcheck` → `npc-maintenance-inspector` への自動マイグレータ
- option/CPT/cron/APIキー定数の互換移行

---

## 1. 確定済み仕様

| 項目 | 値 |
|------|-----|
| **slug** | `npc-maintenance-inspector` （WP.org重複なし確認済） |
| **表示名** | NPC Maintenance Inspector |
| **バージョン** | v1.0.0 |
| **Plugin URI** | `https://n-pc.jp/products/site-doctor/` |
| **Author URI** | `https://n-pc.jp/`（Plugin URIと別物にする・lessons遵守） |
| **Text Domain** | `npc-maintenance-inspector` |
| **Requires at least** | WP 6.0 |
| **Requires PHP** | 7.4 |
| **License** | GPL v2 or later |
| **AI機能** | 段階A維持（自前Anthropic APIキー方式・`is_ai_available()`ガード） |

---

## 2. v1.0.0 実装フェーズ

### Phase 0: 新リポ準備（1.5h）

| 作業 | 対象 | 時間 |
|------|------|------|
| GitHubに `npc-works/npc-maintenance-inspector` 新規作成 | `gh repo create` | 10分 |
| 旧リポを clone → 新リポへ push（履歴引継ぎ） | git | 20分 |
| `dist/` / `blog-draft.html` / `DEPLOY.md` 等を `_internal/` へ退避＋`.gitignore`追加 | git mv/rm | 15分 |
| 旧 README.md を `_internal/README-ja.md` に退避 | mv | 5分 |
| `.gitignore` 更新（vendor/ dist/ node_modules/ .DS_Store） | edit | 10分 |
| 検証: `find . -name "*.sh" -o -name "*.bat" -o -name "*.exe"` ゼロ確認 | bash | 10分 |
| Phase 1 着手前の git tag `v0.7.8-pre-rename` 打刻 | git | 5分 |

### Phase 1: WP.org審査対応（6h）

#### 1A. プラグインヘッダー・定数・クラス名リネーム（1.5h）

ファイルリネーム:
- `npc-wp-healthcheck.php` → `npc-maintenance-inspector.php`
- リポルートディレクトリ名も同様（zip展開時に `npc-maintenance-inspector/` として展開される必要）

プラグインヘッダー書き換え:
```php
/**
 * Plugin Name: NPC Maintenance Inspector
 * Plugin URI: https://n-pc.jp/products/site-doctor/
 * Description: WordPress maintenance health-check tool with 9-point diagnostics, history tracking, and optional AI-powered reports.
 * Version: 1.0.0
 * Author: npc
 * Author URI: https://n-pc.jp/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: npc-maintenance-inspector
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
```

定数リネーム（約10箇所）:
- `NPC_HEALTHCHECK_VERSION` → `NPCMI_VERSION`
- `NPC_HEALTHCHECK_PATH` → `NPCMI_PATH`
- `NPC_HEALTHCHECK_URL` → `NPCMI_URL`
- `NPC_HEALTHCHECK_CPT` → 値も `'npcmi_log'` に変更（v1.3.0マイグレータで吸収）
- `NPC_HEALTHCHECK_HISTORY_LIMIT` → `NPCMI_HISTORY_LIMIT`
- `NPC_HEALTHCHECK_CRON_HOOK` → `NPCMI_CRON_HOOK`

クラス名リネーム:
- `NPC_WP_Healthcheck` → `NPCMI_Plugin`
- `NPC_Checker` → `NPCMI_Checker`
- `NPC_AI_Reporter` → `NPCMI_AI_Reporter`
- `NPC_Notifier` → `NPCMI_Notifier`

option キー: 全 `npc_healthcheck_*` → `npcmi_*` （16個前後・v1.0.0では旧キーフォールバック不要）

#### 1B. 早期サニタイズ強化（30分）

参照: lessons[2026-05-05] WP.org審査保留対応チェックリスト

修正箇所:
- `npc-maintenance-inspector.php` の `$_POST` 受信箇所:
  - `$_POST['auto_enabled']` → `(bool)` キャスト
  - `$_POST['auto_schedule']`, `$_POST['notify_email']` → 既に `sanitize_text_field( wp_unslash(...) )` 済 → そのまま
- `templates/settings.php` の `$_GET['settings-updated']` → `sanitize_key( wp_unslash( $_GET['settings-updated'] ?? '' ) )`
- `includes/class-checker.php` の `$_SERVER['SERVER_SOFTWARE']` → `sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? '' ) )`

#### 1C. i18n完全英語化（PHP・2.5h）

全PHPファイルの日本語ハードコード文字列を `__( 'English source', 'npc-maintenance-inspector' )` または `esc_html__()` で wrap。

注意点（lessons[2026-05-05]）:
- class property の default 値で `__()` を使えない（PHP仕様）→ コンストラクタで初期化
- `wp_die()`, `wp_send_json_error()`, button label, heading, AJAX response message 全箇所

例:
```php
// Before
wp_die( '権限がありません。' );
// After
wp_die( esc_html__( 'You do not have permission.', 'npc-maintenance-inspector' ) );
```

#### 1D. JS i18n対応（1.5h）

`assets/js/admin.js` の日本語文字列（50+ 箇所推定）を `wp.i18n.__()` 経由に。

PHP側で `wp_set_script_translations('npc-maintenance-inspector-admin', 'npc-maintenance-inspector', NPCMI_PATH . 'languages')` を `enqueue_admin_assets()` に追加。

#### 1E. admin_notices スクリーンガード（20分）

`npc-maintenance-inspector.php` の `show_invalid_site_notice` を `get_current_screen()->id` が `toplevel_page_npc-maintenance-inspector` 系で始まる場合のみ表示するよう wrap。

```php
add_action( 'admin_notices', function() {
    $screen = get_current_screen();
    if ( ! $screen || strpos( $screen->id, 'npc-maintenance-inspector' ) === false ) return;
    // 既存の notice 表示ロジック
} );
```

#### 1F. languages/ 一式生成（1h）

WP-CLI:
```bash
wp i18n make-pot . languages/npc-maintenance-inspector.pot --slug=npc-maintenance-inspector
```

日本語 .po/.mo:
```bash
cp languages/npc-maintenance-inspector.pot languages/npc-maintenance-inspector-ja.po
# msgstr に日本語訳を流し込み（Python スクリプトで自動化）
msgfmt -o languages/npc-maintenance-inspector-ja.mo languages/npc-maintenance-inspector-ja.po
```

#### 1G. readme.txt 作成（1h）

WP.org規定フォーマット:
- Plugin Name: NPC Maintenance Inspector
- Contributors: npc01
- Tags: maintenance, healthcheck, diagnostics, security, monitoring
- Requires at least: 6.0
- Tested up to: 6.7
- Stable tag: 1.0.0
- License: GPLv2 or later
- License URI: https://www.gnu.org/licenses/gpl-2.0.html

必須セクション:
- Description
- Installation
- FAQ
- Screenshots
- Changelog（v1.0.0のみ）
- Upgrade Notice
- **== External services ==** （Anthropic Claude API・opt-in明示）

#### 1H. 旧 `load_plugin_textdomain()` 確認（5分）

grep 結果ゼロ確認済（現状未使用）→ 追加・削除作業不要

### Phase 5: テスト + 配布zip作成（1.5h）

| 作業 | 時間 |
|------|------|
| ローカルWP環境で E2E テスト（診断実行 / AIレポート / Cron / 通知メール） | 30分 |
| 日本語ロケール + 英語ロケール両方で UI確認 | 15分 |
| `WP_DEBUG=true` で warning/notice ゼロ確認 | 15分 |
| 機械的i18n検査: `grep -rP '[\\x{3040}-\\x{9fff}]' includes/ templates/ *.php` 残存ゼロ確認 | 10分 |
| 配布zip作成: `zip -r npc-maintenance-inspector-1.0.0.zip npc-maintenance-inspector/ -x "**/.git/*" -x "**/_internal/*" -x "**/node_modules/*"` | 5分 |
| zip 内容 unzip -l で目視チェック（.sh/.bat/.exe/.git/dist 混入なし） | 15分 |
| zip サイズ確認: 8MB以下（lessons[2026-05-06]） | 5分 |

### Phase 7: WP.org提出（30分・Azu本人）

| 作業 | 時間 |
|------|------|
| WP.org にログイン（アカウント `npc01`）→ Add Your Plugin | 5分 |
| zip アップロード（10MB制限内） | 5分 |
| Submit実行・キュー人数記録 | 10分 |
| レビュアーメール（`plugins@wordpress.org`）を許可リスト追加 | 5分 |
| 提出完了スクショ保存 | 5分 |

---

## 3. WP.org審査チェックリスト

参照: `~/npc-team/.claude/rules/lessons/wordpress.md` `[2026-05-05]`

| # | 項目 | 対応Phase |
|---|------|----------|
| 1 | `$_POST/$_GET/$_REQUEST/$_COOKIE/$_SERVER` 早期 sanitize | 1B |
| 2 | 出力時 esc_html/esc_attr/esc_url 二重防御 | 1C |
| 3 | ソース原文を英語に統一（Plugin header / `__()` / readme.txt） | 1A, 1C, 1G |
| 4 | `load_plugin_textdomain()` 削除 | 1H（未使用確認済） |
| 5 | `admin_notices` 自プラグインページのみ | 1E |
| 6 | 配布zipに `.sh`/`.bat`/`.exe`/`node_modules/`/`.git*` 混入なし | 5 |
| 7 | languages/ 同梱（.pot + ja.po + ja.mo） | 1F |
| 8 | Plugin URI と Author URI が別URL | 1A |
| 9 | Text Domain と slug 一致 | 1A |
| 10 | composer.json/vendor/ 整備（不使用） | 該当なし |
| 11 | `== External services ==` セクション明示 | 1G |
| 12 | nonce + capability + screen guard 3重防御 | 既存実装維持 |

---

## 4. リスクと緩和策

| # | リスク | 影響度 | 緩和策 |
|---|--------|--------|--------|
| 1 | i18n全英語化中の文字列見落とし → Pended | 高 | Phase 5で `grep -P '[\\x{3040}-\\x{9fff}]'` 機械的検出を必須化 |
| 2 | Anthropic API利用が「不要な外部送信」と指摘 → Pended | 中 | readme.txt の `== External services ==` で opt-in 明示・API キー未設定で完全停止を強調 |
| 3 | PHP 7.4 と PHP 8.3 の両環境動作不良 | 中 | Phase 5 で最低限 PHP 8.x 環境（ローカルWP）動作確認 |

---

## 5. 既存20サイトへの影響（v1.0.0段階）

- **影響なし**: 既存20サイトは `npc-wp-healthcheck` v0.7.8 のまま運用継続
- マイグレーションは v1.3.0 で対応
- v1.0.0 リリース後も2-4週間は2系統メンテ状態（許容）

---

## 6. タイムライン（今日 = 2026-05-11）

| 時刻 | 内容 | 担当 |
|------|------|------|
| 11:30-13:00 | Phase 0: リポ準備・リネーム雛形 | web-implementer |
| 13:00-14:00 | ランチ＋Azu途中確認 | Azu |
| 14:00-17:30 | Phase 1: i18n英語化・readme.txt | web-implementer |
| 17:30-18:30 | Phase 5: テスト・zip作成 | web-implementer |
| 18:30-19:00 | Azu最終確認 | Azu |
| 19:00-19:30 | Phase 7: WP.org提出 | Azu本人 |

---

## 7. v1.1.0以降のロードマップ（参考）

| バージョン | 内容 | 想定時期 | 工数 |
|-----------|------|---------|------|
| v1.1.0 | 既知プラグイン辞書分離 + ローカル無視リスト | 審査中（1-2週間後） | 4h |
| v1.2.0 | フィードバック送信 + npcサーバーAPI | v1.1後 | 5.5h |
| v1.3.0 | 既存20サイト用マイグレータ | v1.2後 | 3h |

---

## 8. 参照ファイル

- `~/npc-team/.claude/rules/lessons/wordpress.md` `[2026-05-05]` WP.org審査保留対応チェックリスト
- `~/npc-team/.claude/rules/lessons/wordpress.md` `[2026-05-06]` Pended再提出フロー
- `~/.claude/projects/-Users-azusayabune-npc-team/memory/reference_wporg-plugin-submission.md` WP.org提出ルール
- `~/.claude/projects/-Users-azusayabune-npc-team/memory/reference_mpdf-font-stripping.md` （vendor削減・参考のみ・本案件は vendor 不使用）
