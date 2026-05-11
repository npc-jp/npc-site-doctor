/**
 * NPC WP Healthcheck — 管理画面JS
 */
(function($) {
    'use strict';

    // 現在画面に表示している診断（今回分 or 前回分の復元）
    var currentLog = {
        results: null,
        report:  '',
        date:    ''
    };

    // =========================================
    // イベントハンドラ
    // =========================================

    /**
     * 診断実行ボタン
     */
    $('#npc-run-check').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true);

        showLoading('診断を実行中...（サイトヘルスチェックに少し時間がかかります）');
        $('#npc-results').hide();
        $('#npc-report').hide();
        $('#npc-download-report').hide();

        $.ajax({
            url: npcHealthcheck.ajaxUrl,
            type: 'POST',
            data: {
                action: 'npc_run_healthcheck',
                nonce: npcHealthcheck.nonce
            },
            success: function(response) {
                hideLoading();
                $btn.prop('disabled', false);

                if (response.success) {
                    currentLog.results = response.data.results;
                    currentLog.report  = '';
                    currentLog.date    = response.data.date;
                    $('#npc-report-title').text('AIレポート');
                    renderResults(currentLog.results, currentLog.date);
                } else {
                    alert('診断エラー: ' + response.data);
                }
            },
            error: function() {
                hideLoading();
                $btn.prop('disabled', false);
                alert('通信エラーが発生しました。');
            }
        });
    });

    /**
     * AIレポート生成ボタン
     */
    $('#npc-generate-report').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true);

        showLoading('AIレポートを生成中...（30秒ほどかかります）');
        $('#npc-report').hide();

        $.ajax({
            url: npcHealthcheck.ajaxUrl,
            type: 'POST',
            data: {
                action: 'npc_generate_report',
                nonce: npcHealthcheck.nonce
            },
            timeout: 90000,
            success: function(response) {
                hideLoading();
                $btn.prop('disabled', false);

                if (response.success) {
                    currentLog.report = response.data.report;
                    renderReport(currentLog.report);
                } else {
                    alert('レポート生成エラー: ' + response.data);
                }
            },
            error: function() {
                hideLoading();
                $btn.prop('disabled', false);
                alert('通信エラーが発生しました。タイムアウトの可能性があります。');
            }
        });
    });

    /**
     * 現在表示中レポートのダウンロードボタン
     */
    $('#npc-download-report').on('click', function() {
        downloadReport(currentLog.results, currentLog.report, currentLog.date, $('#npc-report-content').html());
    });

    /**
     * 通知テストメール送信ボタン（設定画面）
     */
    $('#npc-test-notification').on('click', function() {
        var $btn = $(this);
        var $result = $('#npc-test-notification-result');

        $btn.prop('disabled', true).text('送信中...');
        $result.text('').css('color', '');

        $.ajax({
            url: npcHealthcheck.ajaxUrl,
            type: 'POST',
            data: {
                action: 'npc_test_notification',
                nonce:  npcHealthcheck.nonce
            },
            success: function(response) {
                $btn.prop('disabled', false).text('テストメールを送信');
                if (response.success) {
                    $result.text('✓ ' + response.data.message).css('color', '#166534');
                } else {
                    $result.text('✗ ' + response.data).css('color', '#b91c1c');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text('テストメールを送信');
                $result.text('✗ 通信エラーが発生しました').css('color', '#b91c1c');
            }
        });
    });

    /**
     * カード内アクションボタン（debug.logクリア等）
     * カードは動的生成なのでイベント委譲で拾う
     */
    $('#npc-results-grid').on('click', '.npc-card__action[data-action="clear-log"]', function() {
        var $btn = $(this);
        var $card = $btn.closest('.npc-card');

        if (!confirm('debug.log の内容をすべて削除します。よろしいですか？\n（ファイル自体は残り、パーミッションは維持されます）')) {
            return;
        }

        $btn.prop('disabled', true).text('クリア中...');

        $.ajax({
            url: npcHealthcheck.ajaxUrl,
            type: 'POST',
            data: {
                action: 'npc_clear_error_log',
                nonce:  npcHealthcheck.nonce
            },
            success: function(response) {
                if (response.success) {
                    // カードの表示を更新: サイズ0・エラー数0にして成功メッセージを表示
                    $card.find('ul').html(
                        '<li>サイズ: 0 B</li>'
                        + '<li>エラー数: 0 件</li>'
                        + '<li class="npc-card__notice">' + escapeHtml(response.data.message) + '</li>'
                    );
                    // バッジを「正常」に更新
                    $card.removeClass('npc-card--warning npc-card--critical').addClass('npc-card--ok');
                    $card.find('.npc-badge')
                        .removeClass('npc-badge--warning npc-badge--critical')
                        .addClass('npc-badge--ok')
                        .text('正常');
                    // アクションボタンを非表示
                    $card.find('.npc-card__actions').hide();
                } else {
                    alert('エラー: ' + response.data);
                    $btn.prop('disabled', false).text('ログをクリア');
                }
            },
            error: function() {
                alert('通信エラーが発生しました。');
                $btn.prop('disabled', false).text('ログをクリア');
            }
        });
    });

    // =========================================
    // 画面描画
    // =========================================

    /**
     * 診断結果をカード形式で表示
     */
    function renderResults(data, dateLabel) {
        renderSummaryHeader(data, null, dateLabel);

        var $grid = $('#npc-results-grid');
        $grid.empty();

        // WP本体
        $grid.append(createCard('WordPress本体', data.core_updates.status, [
            '現在: ' + data.core_updates.current_version,
            data.core_updates.status === 'ok'
                ? '最新バージョンです'
                : '更新あり: ' + data.core_updates.latest_version
        ]));

        // プラグイン
        var pluginItems = [
            '全 ' + data.plugin_updates.total_plugins + ' 件（有効: ' + data.plugin_updates.active_plugins + '）',
            '更新が必要: ' + data.plugin_updates.updates_count + ' 件'
        ];
        data.plugin_updates.updates_needed.forEach(function(p) {
            pluginItems.push(p.name + ': ' + p.current_version + ' → ' + p.new_version);
        });
        $grid.append(createCard('プラグイン', data.plugin_updates.status, pluginItems));

        // サイトヘルス
        $grid.append(createCard('サイトヘルス',
            data.site_health.critical_count > 0 ? 'critical' : (data.site_health.recommended_count > 0 ? 'warning' : 'ok'),
            [
                '致命的: ' + data.site_health.critical_count + ' 件',
                '推奨: ' + data.site_health.recommended_count + ' 件',
                '良好: ' + data.site_health.good_count + ' 件'
            ]
        ));

        // PHP環境
        $grid.append(createCard('サーバー環境', data.php_version.status, [
            'PHP: ' + data.php_version.php_version,
            'メモリ: ' + data.php_version.max_memory,
            'アップロード上限: ' + data.php_version.max_upload
        ]));

        // エラーログ（存在+サイズがあればクリアボタンを表示）
        var logItems = [];
        var logActions = [];
        if (!data.error_log.exists) {
            logItems.push('debug.log なし');
        } else {
            logItems.push('サイズ: ' + data.error_log.file_size);
            logItems.push('エラー数: ' + data.error_log.error_count + ' 件');
            if (parseFloat(data.error_log.file_size) > 0 || data.error_log.error_count > 0) {
                logActions.push({
                    label:   'ログをクリア',
                    action:  'clear-log',
                    variant: 'danger'
                });
            }
        }
        $grid.append(createCard('エラーログ', data.error_log.status, logItems, 'error-log', logActions));

        // ファイル改ざん検知
        var integrityItems = [];
        var integrityStatus = data.file_integrity.status;

        if (data.file_integrity.has_danger) {
            integrityItems.push('不審なコードが検出されました');
            integrityStatus = 'critical';
        } else if (data.file_integrity.modified_count > 0) {
            integrityItems.push('変更されたファイル: ' + data.file_integrity.modified_count + ' 件');
            integrityItems.push('不審コード: 検出なし');
        } else if (data.file_integrity.suspect_count > 0) {
            integrityItems.push('コアファイルに問題なし');
            integrityItems.push('チェックサムずれ: ' + data.file_integrity.suspect_count + ' 件（不審コードなし・安全）');
            integrityStatus = 'ok';
        } else {
            integrityItems.push('コアファイルに変更なし');
        }

        $grid.append(createCard('ファイル改ざん検知', integrityStatus, integrityItems));

        // 不審ファイル
        $grid.append(createCard('不審ファイル', data.suspicious_files.status, [
            data.suspicious_files.suspicious_count === 0
                ? '検出なし'
                : data.suspicious_files.suspicious_count + ' 件の不審なファイルを検出'
        ]));

        // SSL
        var sslItems = [];
        if (data.ssl_certificate.expires_at) {
            sslItems.push('有効期限: ' + data.ssl_certificate.expires_at);
            sslItems.push('残り: ' + data.ssl_certificate.days_left + ' 日');
        }
        if (data.ssl_certificate.note) {
            sslItems.push(data.ssl_certificate.note);
        }
        $grid.append(createCard('SSL証明書', data.ssl_certificate.status, sslItems));

        // ファイルパーミッション
        var permItems = data.file_permissions.checks.map(function(c) {
            var mark = c.status === 'ok' ? '✓' : '✗';
            return mark + ' ' + c.path + ': ' + c.current;
        });
        $grid.append(createCard('ファイルパーミッション', data.file_permissions.status, permItems));

        $('#npc-results').show();
    }

    /**
     * サマリヘッダーを描画
     * @param {object} data      診断結果
     * @param {string} aiGrade   AIが判定した総合評価 A〜D。nullなら自前集計
     * @param {string} dateLabel 診断日時（"2026-04-19 12:30"形式）
     */
    function renderSummaryHeader(data, aiGrade, dateLabel) {
        var statuses = collectStatuses(data);
        var counts = {
            critical: statuses.filter(function(s) { return s === 'critical'; }).length,
            warning:  statuses.filter(function(s) { return s === 'warning'; }).length,
            ok:       statuses.filter(function(s) { return s === 'ok'; }).length
        };
        var total = statuses.length;

        var grade, gradeClass;
        if (aiGrade) {
            grade = aiGrade;
            if (grade === 'D') gradeClass = 'critical';
            else if (grade === 'C' || grade === 'B') gradeClass = 'warning';
            else gradeClass = 'ok';
        } else {
            if (counts.critical > 0)      { grade = 'D'; gradeClass = 'critical'; }
            else if (counts.warning >= 3) { grade = 'C'; gradeClass = 'warning'; }
            else if (counts.warning >= 1) { grade = 'B'; gradeClass = 'warning'; }
            else                          { grade = 'A'; gradeClass = 'ok'; }
        }

        var subtitleMap = {
            ok: 'サイトの健全性は良好です',
            warning: '軽微な注意事項があります',
            critical: '早急な対応が必要な項目があります'
        };

        var segments = '';
        ['critical', 'warning', 'ok'].forEach(function(key) {
            if (counts[key] > 0) {
                var pct = (counts[key] / total * 100).toFixed(1);
                segments += '<div class="npc-progress-bar__seg npc-progress-bar__seg--' + key
                    + '" style="width:' + pct + '%"></div>';
            }
        });

        var dateHtml = dateLabel
            ? '<div class="npc-summary-date">診断日時: <strong>' + escapeHtml(dateLabel) + '</strong></div>'
            : '';

        var html = '<div class="npc-summary-card npc-summary-card--' + gradeClass + '">'
            + '<div class="npc-score-circle npc-score-circle--' + gradeClass + '">'
            +   '<div class="npc-score-grade">' + grade + '</div>'
            +   '<div class="npc-score-label">TOTAL SCORE</div>'
            + '</div>'
            + '<div class="npc-summary-body">'
            +   '<div class="npc-summary-head">'
            +     '<h2 class="npc-summary-title">総合診断結果</h2>'
            +     dateHtml
            +   '</div>'
            +   '<p class="npc-summary-subtitle">' + subtitleMap[gradeClass] + '（' + total + '項目をチェック）</p>'
            +   '<div class="npc-count-pills">'
            +     countPill('critical', '要対応', counts.critical)
            +     countPill('warning', '注意', counts.warning)
            +     countPill('ok', '正常', counts.ok)
            +   '</div>'
            +   '<div class="npc-progress-bar">' + segments + '</div>'
            + '</div>'
            + '</div>';

        $('#npc-summary-header').html(html).show();
    }

    /**
     * AIレポートを表示
     */
    function renderReport(reportText) {
        var html = buildReportHtml(reportText, currentLog.results);
        $('#npc-report-content').html(html);
        $('#npc-report').show();
        $('#npc-download-report').show();
    }

    /**
     * AIレポートテキストから表示用HTMLを組み立てる
     * 履歴カードの描画でも使うため純粋関数にしている
     *
     * @param {string} reportText AIレポート生テキスト
     * @param {object} resultsForGrade サマリヘッダー更新用の診断結果（nullなら更新しない）
     */
    function buildReportHtml(reportText, resultsForGrade) {
        var html = '';

        var summaryMatch = reportText.match(/\[SUMMARY\]([\s\S]*?)\[\/SUMMARY\]/);
        var aiGrade = null;
        var summaryComment = '';

        if (summaryMatch) {
            var summaryRaw = summaryMatch[1].trim();
            var gradeMatch = summaryRaw.match(/総合評価[：:]?\s*([A-D])/);
            if (gradeMatch) aiGrade = gradeMatch[1];
            summaryComment = summaryRaw.replace(/総合評価[：:]?\s*[A-D][^\n]*\n?/, '').trim();
        }

        // AIグレードでサマリヘッダーを上書き（今回レポートのみ。履歴はスキップ）
        if (aiGrade && resultsForGrade) {
            renderSummaryHeader(resultsForGrade, aiGrade, currentLog.date);
        }

        if (summaryComment) {
            var commentClass = 'ok';
            if (aiGrade === 'D') commentClass = 'critical';
            else if (aiGrade === 'C' || aiGrade === 'B') commentClass = 'warning';
            html += '<div class="npc-report-summary npc-report-summary--' + commentClass + '">'
                + '<p>' + escapeHtml(summaryComment) + '</p></div>';
        }

        var sectionRegex = /\[SECTION:(critical|warning|ok|action)\]([\s\S]*?)\[\/SECTION\]/g;
        var match;
        while ((match = sectionRegex.exec(reportText)) !== null) {
            var sectionType = match[1];
            var sectionContent = match[2].trim();

            if (sectionType === 'action') {
                html += renderActionSection(sectionContent);
                continue;
            }

            var isEmpty = /^##.*\n+該当(なし|ありません)/.test(sectionContent)
                || sectionContent.indexOf('該当なし') !== -1 && sectionContent.split('\n').length <= 3;

            sectionContent = sectionContent
                .replace(/\[STATUS:critical\]/g, '<span class="npc-badge npc-badge--critical">要対応</span> ')
                .replace(/\[STATUS:warning\]/g, '<span class="npc-badge npc-badge--warning">注意</span> ')
                .replace(/\[STATUS:ok\]/g, '<span class="npc-badge npc-badge--ok">正常</span> ');

            var sectionHtml = markdownToHtml(sectionContent);

            html += '<div class="npc-report-section npc-report-section--' + sectionType
                + (isEmpty ? ' npc-report-section--empty' : '') + '">'
                + sectionHtml + '</div>';
        }

        if (!summaryMatch && !reportText.match(/\[SECTION:/)) {
            html = '<div class="npc-report-section">' + markdownToHtml(reportText) + '</div>';
        }

        return html;
    }

    /**
     * [SECTION:action] の中身をアクションカードに変換
     */
    function renderActionSection(content) {
        var heading = '対応手順';
        var headingMatch = content.match(/^##\s+(.+)$/m);
        if (headingMatch) {
            heading = headingMatch[1].trim();
            content = content.replace(/^##\s+.+$/m, '').trim();
        }

        var items = [];
        var parts = content.split(/\[STATUS:(critical|warning|ok)\]/);
        for (var i = 1; i < parts.length; i += 2) {
            items.push({ status: parts[i], body: (parts[i + 1] || '').trim() });
        }

        if (items.length === 0) {
            return '<div class="npc-report-section npc-report-section--action">'
                + markdownToHtml('## ' + heading + '\n' + content) + '</div>';
        }

        var html = '<div class="npc-report-section npc-report-section--action">'
            + '<h2>' + escapeHtml(heading) + '</h2>'
            + '<div class="npc-action-list">';

        items.forEach(function(item) {
            var lines = item.body.split('\n');
            var title = lines[0].replace(/^\*\*(.+?)\*\*$/, '$1').trim();
            var rest = lines.slice(1).join('\n').trim();
            var badgeLabel = { critical: '要対応', warning: '注意', ok: '正常' }[item.status];

            html += '<div class="npc-action-card npc-action-card--' + item.status + '">'
                + '<div class="npc-action-card__header">'
                +   '<span class="npc-badge npc-badge--' + item.status + '">' + badgeLabel + '</span>'
                +   '<strong>' + escapeHtml(title) + '</strong>'
                + '</div>'
                + (rest ? '<div class="npc-action-card__body">' + markdownToHtml(rest) + '</div>' : '')
                + '</div>';
        });

        html += '</div></div>';
        return html;
    }

    // =========================================
    // 履歴アコーディオン
    // =========================================

    /**
     * 履歴リスト全体を描画
     * @param {Array} logs サーバーから渡された診断履歴（新しい順）
     */
    function renderHistoryList(logs) {
        var $list = $('#npc-history-list');
        $list.empty();

        if (!logs || logs.length === 0) {
            $('#npc-history').hide();
            return;
        }

        logs.forEach(function(log) {
            $list.append(renderHistoryItem(log));
        });

        $('#npc-history').show();

        // ダウンロードボタンのクリック
        $list.on('click', '.npc-history-download', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var logId = String($(this).data('logId'));
            var log = logs.find(function(l) { return String(l.id) === logId; });
            if (!log) return;
            var reportHtml = log.report
                ? buildReportHtml(log.report, null)
                : '<div class="npc-report-section npc-report-section--empty"><p>AIレポートは未生成です</p></div>';
            downloadReport(log.results, log.report, log.date, reportHtml);
        });
    }

    /**
     * 履歴1件のアコーディオンを生成
     */
    function renderHistoryItem(log) {
        var summary = log.summary || { grade: '-', counts: { critical: 0, warning: 0, ok: 0 } };
        var counts  = summary.counts || { critical: 0, warning: 0, ok: 0 };
        var grade   = summary.grade || '-';

        var gradeClass = 'ok';
        if (grade === 'D') gradeClass = 'critical';
        else if (grade === 'C' || grade === 'B') gradeClass = 'warning';

        var $details = $('<details class="npc-history-item npc-history-item--' + gradeClass + '"></details>');

        var summaryBar = '<summary class="npc-history-summary">'
            + '<span class="npc-history-grade npc-history-grade--' + gradeClass + '">' + escapeHtml(grade) + '</span>'
            + '<span class="npc-history-date">' + escapeHtml(log.date) + ' の診断結果</span>'
            + '<span class="npc-history-counts">'
            +   '<span class="npc-history-count npc-history-count--critical">要対応 ' + counts.critical + '</span>'
            +   '<span class="npc-history-count npc-history-count--warning">注意 ' + counts.warning + '</span>'
            +   '<span class="npc-history-count npc-history-count--ok">正常 ' + counts.ok + '</span>'
            + '</span>'
            + '<button type="button" class="button button-small npc-history-download" data-log-id="' + log.id + '">ダウンロード</button>'
            + '</summary>';

        var bodyHtml = '<div class="npc-history-body">';
        if (log.report) {
            bodyHtml += buildReportHtml(log.report, null);
        } else {
            bodyHtml += '<div class="npc-report-section npc-report-section--empty"><p>この診断にはAIレポートがありません（診断結果のみ保存）。</p></div>';
        }
        bodyHtml += '</div>';

        $details.html(summaryBar + bodyHtml);
        return $details;
    }

    // =========================================
    // ダウンロード
    // =========================================

    /**
     * レポートをHTMLファイルとしてダウンロード
     * @param {object} results      診断結果（サイト名取得用）
     * @param {string} reportText   AIレポート生テキスト（未使用、将来用）
     * @param {string} dateLabel    診断日時
     * @param {string} reportHtml   レンダリング済みレポートHTML
     */
    function downloadReport(results, reportText, dateLabel, reportHtml) {
        // v0.7.0: サイト名は wp_localize_script 経由（npcHealthcheck.siteName）から取る。
        // results.site_info.site_name は経由が長く、過去にタイトル文字化け（u5546u7528...）が発生した
        var siteName = (window.npcHealthcheck && window.npcHealthcheck.siteName)
            || (results && results.site_info && results.site_info.site_name)
            || 'サイト';
        var date = (dateLabel || new Date().toISOString().slice(0, 10)).replace(/[: ]/g, '-');

        var html = '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8">'
            + '<title>' + escapeHtml(siteName) + ' 保守診断レポート ' + escapeHtml(date) + '</title>'
            + '<style>' + getDownloadStyle() + '</style></head><body>'
            + '<h1>' + escapeHtml(siteName) + ' — 保守診断レポート</h1>'
            + '<p class="meta">診断日時: ' + escapeHtml(dateLabel || '') + '</p>'
            + reportHtml
            + '<div class="footer">Generated by NPC WP Healthcheck</div>'
            + '</body></html>';

        var blob = new Blob([html], { type: 'text/html' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'healthcheck-report-' + date + '.html';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    /**
     * ダウンロードHTML用の内蔵CSS
     * 管理画面と同じ見た目を再現するため、最小限のスタイルを埋め込む
     */
    function getDownloadStyle() {
        return 'body { font-family: "Hiragino Sans", "Yu Gothic", sans-serif; max-width: 880px; margin: 40px auto; padding: 0 24px; line-height: 1.8; color: #1e293b; }'
            + 'h1 { font-size: 22px; border-bottom: 2px solid #333; padding-bottom: 8px; }'
            + 'h2 { font-size: 16px; margin-top: 24px; }'
            + 'h3 { font-size: 14px; }'
            + 'p.meta { color: #64748b; font-size: 13px; }'
            + 'ul { margin: 8px 0 8px 20px; }'
            + '.npc-report-summary { background: #f7fdf7; border-left: 5px solid #46b450; padding: 16px 20px; margin-bottom: 16px; border-radius: 4px; }'
            + '.npc-report-summary--warning { background: #fffbeb; border-left-color: #ffb900; }'
            + '.npc-report-summary--critical { background: #fef2f2; border-left-color: #dc3232; }'
            + '.npc-report-section { background: #fff; border: 1px solid #e2e8f0; border-left: 5px solid #ccd0d4; padding: 16px 20px; margin-bottom: 12px; border-radius: 4px; }'
            + '.npc-report-section--critical { border-left-color: #dc3232; background: #fef7f7; }'
            + '.npc-report-section--warning { border-left-color: #ffb900; background: #fffbeb; }'
            + '.npc-report-section--ok { border-left-color: #46b450; background: #f7fdf7; }'
            + '.npc-report-section--action { border-left-color: #0073aa; background: #f0f6fc; }'
            + '.npc-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; color: #fff; font-weight: 600; }'
            + '.npc-badge--ok { background: #46b450; } .npc-badge--warning { background: #ffb900; color: #333; } .npc-badge--critical { background: #dc3232; }'
            + '.npc-action-card { background: #fff; border: 1px solid #e2e8f0; border-left: 4px solid #cbd5e1; padding: 12px 16px; margin: 8px 0; border-radius: 4px; }'
            + '.npc-action-card--critical { border-left-color: #dc3232; background: #fef7f7; } .npc-action-card--warning { border-left-color: #ffb900; background: #fffbeb; } .npc-action-card--ok { border-left-color: #46b450; background: #f7fdf7; }'
            + '.npc-action-card__header { display: flex; gap: 8px; align-items: center; margin-bottom: 6px; }'
            + '.footer { margin-top: 40px; padding-top: 16px; border-top: 1px solid #ccc; font-size: 12px; color: #888; }';
    }

    // =========================================
    // ヘルパー
    // =========================================

    function collectStatuses(data) {
        var list = [];
        list.push(normalizeStatus(data.core_updates.status));
        list.push(normalizeStatus(data.plugin_updates.status));
        list.push(data.site_health.critical_count > 0 ? 'critical'
            : (data.site_health.recommended_count > 0 ? 'warning' : 'ok'));
        list.push(normalizeStatus(data.php_version.status));
        list.push(normalizeStatus(data.error_log.status));

        var integrityStatus = data.file_integrity.status;
        if (data.file_integrity.has_danger) integrityStatus = 'critical';
        else if (data.file_integrity.suspect_count > 0 && data.file_integrity.modified_count === 0) integrityStatus = 'ok';
        list.push(normalizeStatus(integrityStatus));

        list.push(normalizeStatus(data.suspicious_files.status));
        list.push(normalizeStatus(data.ssl_certificate.status));
        list.push(normalizeStatus(data.file_permissions.status));
        return list;
    }

    function normalizeStatus(status) {
        if (status === 'update_available') return 'warning';
        if (status === 'unknown') return 'ok';
        return status;
    }

    function countPill(type, label, count) {
        var cls = 'npc-count-pill npc-count-pill--' + type;
        if (count === 0) cls += ' npc-count-pill--zero';
        return '<span class="' + cls + '">'
            + '<span class="npc-count-pill__num">' + count + '</span>'
            + '<span>' + label + '</span></span>';
    }

    /**
     * 診断カードを生成
     * @param {string} title    カードタイトル
     * @param {string} status   ok/warning/critical/update_available/unknown
     * @param {Array}  items    表示項目文字列の配列
     * @param {string} [cardId] カード識別子（data属性に付与）
     * @param {Array}  [actions] アクションボタン配列 [{label, action, variant}]
     */
    function createCard(title, status, items, cardId, actions) {
        var badgeLabel = {
            ok: '正常', warning: '注意', critical: '要対応', unknown: '不明',
            update_available: '更新あり'
        };
        var cardStatus = (status === 'update_available') ? 'warning' : status;
        var dataAttr = cardId ? ' data-card-id="' + escapeHtml(cardId) + '"' : '';

        var html = '<div class="npc-card npc-card--' + cardStatus + '"' + dataAttr + '>'
            + '<h3>' + title + ' <span class="npc-badge npc-badge--' + cardStatus + '">'
            + (badgeLabel[status] || status) + '</span></h3><ul>';

        items.forEach(function(item) {
            html += '<li>' + escapeHtml(item) + '</li>';
        });

        html += '</ul>';

        if (actions && actions.length > 0) {
            html += '<div class="npc-card__actions">';
            actions.forEach(function(a) {
                var btnClass = 'button button-small npc-card__action';
                if (a.variant === 'danger') btnClass += ' npc-card__action--danger';
                html += '<button type="button" class="' + btnClass
                    + '" data-action="' + escapeHtml(a.action) + '">'
                    + escapeHtml(a.label) + '</button>';
            });
            html += '</div>';
        }

        html += '</div>';
        return html;
    }

    function markdownToHtml(text) {
        var badges = [];
        text = text.replace(/<span class="npc-badge npc-badge--(critical|warning|ok)">(.*?)<\/span>/g, function(match) {
            badges.push(match);
            return '{{BADGE_' + (badges.length - 1) + '}}';
        });

        var html = escapeHtml(text)
            .replace(/^### (.+)$/gm, '<h3>$1</h3>')
            .replace(/^## (.+)$/gm, '<h2>$1</h2>')
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/^- (.+)$/gm, '<li>$1</li>')
            .replace(/^\d+\.\s+(.+)$/gm, '<li>$1</li>')
            .replace(/\n\n/g, '</p><p>')
            .replace(/\n/g, '<br>');

        html = html.replace(/((?:<li>.*?<\/li>\s*(?:<br>)?)+)/g, '<ul>$1</ul>');
        html = html.replace(/<ul>([\s\S]*?)<\/ul>/g, function(match, inner) {
            return '<ul>' + inner.replace(/<br>/g, '') + '</ul>';
        });

        badges.forEach(function(badge, i) {
            html = html.replace('{{BADGE_' + i + '}}', badge);
        });

        return '<p>' + html + '</p>';
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text == null ? '' : text));
        return div.innerHTML;
    }

    function showLoading(text) {
        $('#npc-loading-text').text(text);
        $('#npc-loading').show();
    }

    function hideLoading() {
        $('#npc-loading').hide();
    }

    // =========================================
    // ページ読込時の初期化
    // =========================================

    $(function() {
        var history = npcHealthcheck.history || [];

        if (history.length > 0) {
            // 最新分は「前回の診断結果」として通常の領域に展開表示
            var latest = history[0];
            currentLog.results = latest.results;
            currentLog.report  = latest.report || '';
            currentLog.date    = latest.date;

            if (latest.results) {
                $('#npc-report-title').text('前回のAIレポート');
                renderResults(latest.results, latest.date);
            }
            if (latest.report) {
                renderReport(latest.report);
            }

            // 2件目以降を履歴アコーディオンに表示
            renderHistoryList(history.slice(1));
        }
    });

})(jQuery);
