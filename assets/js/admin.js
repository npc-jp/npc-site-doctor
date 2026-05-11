/**
 * NPC Site Doctor — admin JS
 */
(function($) {
    'use strict';

    // wp.i18n is registered when wp_set_script_translations is called server-side.
    // Fall back to identity function if i18n is unavailable for some reason.
    var i18n = (window.wp && window.wp.i18n) ? window.wp.i18n : null;
    var __ = i18n ? i18n.__ : function(s) { return s; };
    var sprintf = (window.wp && window.wp.i18n && window.wp.i18n.sprintf)
        ? window.wp.i18n.sprintf
        : function() {
            var args = Array.prototype.slice.call(arguments);
            var format = args.shift();
            var i = 0;
            return format.replace(/%[sd]/g, function() { return args[i++]; });
        };

    // Currently displayed diagnosis (latest run or restored from history).
    var currentLog = {
        results: null,
        report:  '',
        date:    ''
    };

    // =========================================
    // Event handlers
    // =========================================

    /**
     * Run-diagnosis button
     */
    $('#npc-run-check').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true);

        showLoading(__( 'Running diagnosis... (Site Health takes a moment)', 'npc-site-doctor' ));
        $('#npc-results').hide();
        $('#npc-report').hide();
        $('#npc-download-report').hide();

        $.ajax({
            url: npcSiteDoctor.ajaxUrl,
            type: 'POST',
            data: {
                action: 'npc_sd_run_healthcheck',
                nonce: npcSiteDoctor.nonce
            },
            success: function(response) {
                hideLoading();
                $btn.prop('disabled', false);

                if (response.success) {
                    currentLog.results = response.data.results;
                    currentLog.report  = '';
                    currentLog.date    = response.data.date;
                    $('#npc-report-title').text(__( 'AI Report', 'npc-site-doctor' ));
                    renderResults(currentLog.results, currentLog.date);
                } else {
                    alert(__( 'Diagnosis error: ', 'npc-site-doctor' ) + response.data);
                }
            },
            error: function() {
                hideLoading();
                $btn.prop('disabled', false);
                alert(__( 'A communication error occurred.', 'npc-site-doctor' ));
            }
        });
    });

    /**
     * Generate AI Report button
     */
    $('#npc-generate-report').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true);

        showLoading(__( 'Generating AI report... (takes about 30 seconds)', 'npc-site-doctor' ));
        $('#npc-report').hide();

        $.ajax({
            url: npcSiteDoctor.ajaxUrl,
            type: 'POST',
            data: {
                action: 'npc_sd_generate_report',
                nonce: npcSiteDoctor.nonce
            },
            timeout: 90000,
            success: function(response) {
                hideLoading();
                $btn.prop('disabled', false);

                if (response.success) {
                    currentLog.report = response.data.report;
                    renderReport(currentLog.report);
                } else {
                    alert(__( 'Report generation error: ', 'npc-site-doctor' ) + response.data);
                }
            },
            error: function() {
                hideLoading();
                $btn.prop('disabled', false);
                alert(__( 'A communication error occurred (possibly a timeout).', 'npc-site-doctor' ));
            }
        });
    });

    /**
     * Download currently shown report
     */
    $('#npc-download-report').on('click', function() {
        downloadReport(currentLog.results, currentLog.report, currentLog.date, $('#npc-report-content').html());
    });

    /**
     * Send test notification email (settings screen)
     */
    $('#npc-test-notification').on('click', function() {
        var $btn = $(this);
        var $result = $('#npc-test-notification-result');
        var btnLabel = __( 'Send Test Email', 'npc-site-doctor' );

        $btn.prop('disabled', true).text(__( 'Sending...', 'npc-site-doctor' ));
        $result.text('').css('color', '');

        $.ajax({
            url: npcSiteDoctor.ajaxUrl,
            type: 'POST',
            data: {
                action: 'npc_sd_test_notification',
                nonce:  npcSiteDoctor.nonce
            },
            success: function(response) {
                $btn.prop('disabled', false).text(btnLabel);
                if (response.success) {
                    $result.text('✓ ' + response.data.message).css('color', '#166534');
                } else {
                    $result.text('✗ ' + response.data).css('color', '#b91c1c');
                }
            },
            error: function() {
                $btn.prop('disabled', false).text(btnLabel);
                $result.text('✗ ' + __( 'A communication error occurred.', 'npc-site-doctor' )).css('color', '#b91c1c');
            }
        });
    });

    /**
     * In-card action buttons (e.g. clear debug.log).
     * Cards are rendered dynamically, so we delegate.
     */
    $('#npc-results-grid').on('click', '.npc-card__action[data-action="clear-log"]', function() {
        var $btn = $(this);
        var $card = $btn.closest('.npc-card');
        var clearLabel = __( 'Clear Log', 'npc-site-doctor' );

        if (!confirm(__( 'Are you sure you want to clear the contents of debug.log?\n(The file itself stays in place and permissions are preserved.)', 'npc-site-doctor' ))) {
            return;
        }

        $btn.prop('disabled', true).text(__( 'Clearing...', 'npc-site-doctor' ));

        $.ajax({
            url: npcSiteDoctor.ajaxUrl,
            type: 'POST',
            data: {
                action: 'npc_sd_clear_error_log',
                nonce:  npcSiteDoctor.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Update the card: size 0 / 0 errors / success message.
                    $card.find('ul').html(
                        '<li>' + escapeHtml(__( 'Size: 0 B', 'npc-site-doctor' )) + '</li>'
                        + '<li>' + escapeHtml(__( 'Errors: 0', 'npc-site-doctor' )) + '</li>'
                        + '<li class="npc-card__notice">' + escapeHtml(response.data.message) + '</li>'
                    );
                    // Switch the badge to "OK".
                    $card.removeClass('npc-card--warning npc-card--critical').addClass('npc-card--ok');
                    $card.find('.npc-badge')
                        .removeClass('npc-badge--warning npc-badge--critical')
                        .addClass('npc-badge--ok')
                        .text(__( 'OK', 'npc-site-doctor' ));
                    // Hide action buttons.
                    $card.find('.npc-card__actions').hide();
                } else {
                    alert(__( 'Error: ', 'npc-site-doctor' ) + response.data);
                    $btn.prop('disabled', false).text(clearLabel);
                }
            },
            error: function() {
                alert(__( 'A communication error occurred.', 'npc-site-doctor' ));
                $btn.prop('disabled', false).text(clearLabel);
            }
        });
    });

    // =========================================
    // Rendering
    // =========================================

    /**
     * Render the diagnosis result as cards.
     */
    function renderResults(data, dateLabel) {
        renderSummaryHeader(data, null, dateLabel);

        var $grid = $('#npc-results-grid');
        $grid.empty();

        // WP Core
        $grid.append(createCard(__( 'WordPress Core', 'npc-site-doctor' ), data.core_updates.status, [
            sprintf(__( 'Current: %s', 'npc-site-doctor' ), data.core_updates.current_version),
            data.core_updates.status === 'ok'
                ? __( 'Up to date.', 'npc-site-doctor' )
                : sprintf(__( 'Update available: %s', 'npc-site-doctor' ), data.core_updates.latest_version)
        ]));

        // Plugins
        var pluginItems = [
            sprintf(__( 'Total: %1$d (active: %2$d)', 'npc-site-doctor' ),
                data.plugin_updates.total_plugins, data.plugin_updates.active_plugins),
            sprintf(__( 'Updates needed: %d', 'npc-site-doctor' ), data.plugin_updates.updates_count)
        ];
        data.plugin_updates.updates_needed.forEach(function(p) {
            pluginItems.push(p.name + ': ' + p.current_version + ' → ' + p.new_version);
        });
        $grid.append(createCard(__( 'Plugins', 'npc-site-doctor' ), data.plugin_updates.status, pluginItems));

        // Site Health
        $grid.append(createCard(__( 'Site Health', 'npc-site-doctor' ),
            data.site_health.critical_count > 0 ? 'critical' : (data.site_health.recommended_count > 0 ? 'warning' : 'ok'),
            [
                sprintf(__( 'Critical: %d', 'npc-site-doctor' ), data.site_health.critical_count),
                sprintf(__( 'Recommended: %d', 'npc-site-doctor' ), data.site_health.recommended_count),
                sprintf(__( 'Good: %d', 'npc-site-doctor' ), data.site_health.good_count)
            ]
        ));

        // PHP / server environment
        $grid.append(createCard(__( 'Server Environment', 'npc-site-doctor' ), data.php_version.status, [
            sprintf(__( 'PHP: %s', 'npc-site-doctor' ), data.php_version.php_version),
            sprintf(__( 'Memory: %s', 'npc-site-doctor' ), data.php_version.max_memory),
            sprintf(__( 'Max upload: %s', 'npc-site-doctor' ), data.php_version.max_upload)
        ]));

        // Error log
        var logItems = [];
        var logActions = [];
        if (!data.error_log.exists) {
            logItems.push(__( 'debug.log does not exist.', 'npc-site-doctor' ));
        } else {
            logItems.push(sprintf(__( 'Size: %s', 'npc-site-doctor' ), data.error_log.file_size));
            logItems.push(sprintf(__( 'Errors: %d', 'npc-site-doctor' ), data.error_log.error_count));
            if (parseFloat(data.error_log.file_size) > 0 || data.error_log.error_count > 0) {
                logActions.push({
                    label:   __( 'Clear Log', 'npc-site-doctor' ),
                    action:  'clear-log',
                    variant: 'danger'
                });
            }
        }
        $grid.append(createCard(__( 'Error Log', 'npc-site-doctor' ), data.error_log.status, logItems, 'error-log', logActions));

        // File integrity
        var integrityItems = [];
        var integrityStatus = data.file_integrity.status;

        if (data.file_integrity.has_danger) {
            integrityItems.push(__( 'Suspicious code detected.', 'npc-site-doctor' ));
            integrityStatus = 'critical';
        } else if (data.file_integrity.modified_count > 0) {
            integrityItems.push(sprintf(__( 'Modified files: %d', 'npc-site-doctor' ), data.file_integrity.modified_count));
            integrityItems.push(__( 'Suspicious code: none.', 'npc-site-doctor' ));
        } else if (data.file_integrity.suspect_count > 0) {
            integrityItems.push(__( 'No issues with core files.', 'npc-site-doctor' ));
            integrityItems.push(sprintf(__( 'Checksum mismatches: %d (no suspicious code, safe).', 'npc-site-doctor' ), data.file_integrity.suspect_count));
            integrityStatus = 'ok';
        } else {
            integrityItems.push(__( 'No changes to core files.', 'npc-site-doctor' ));
        }

        $grid.append(createCard(__( 'File Integrity', 'npc-site-doctor' ), integrityStatus, integrityItems));

        // Suspicious files
        $grid.append(createCard(__( 'Suspicious Files', 'npc-site-doctor' ), data.suspicious_files.status, [
            data.suspicious_files.suspicious_count === 0
                ? __( 'None detected.', 'npc-site-doctor' )
                : sprintf(__( '%d suspicious file(s) detected.', 'npc-site-doctor' ), data.suspicious_files.suspicious_count)
        ]));

        // SSL
        var sslItems = [];
        if (data.ssl_certificate.expires_at) {
            sslItems.push(sprintf(__( 'Expires: %s', 'npc-site-doctor' ), data.ssl_certificate.expires_at));
            sslItems.push(sprintf(__( '%d days remaining', 'npc-site-doctor' ), data.ssl_certificate.days_left));
        }
        if (data.ssl_certificate.note) {
            sslItems.push(data.ssl_certificate.note);
        }
        $grid.append(createCard(__( 'SSL Certificate', 'npc-site-doctor' ), data.ssl_certificate.status, sslItems));

        // File permissions
        var permItems = data.file_permissions.checks.map(function(c) {
            var mark = c.status === 'ok' ? '✓' : '✗';
            return mark + ' ' + c.path + ': ' + c.current;
        });
        $grid.append(createCard(__( 'File Permissions', 'npc-site-doctor' ), data.file_permissions.status, permItems));

        $('#npc-results').show();
    }

    /**
     * Render the summary header.
     * @param {object} data      Diagnosis result.
     * @param {string} aiGrade   AI-judged grade A-D. null means use local count.
     * @param {string} dateLabel Diagnosis timestamp ("2026-04-19 12:30" format).
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
            ok: __( 'The site is in good health.', 'npc-site-doctor' ),
            warning: __( 'Some minor items need attention.', 'npc-site-doctor' ),
            critical: __( 'There are items that require immediate attention.', 'npc-site-doctor' )
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
            ? '<div class="npc-summary-date">' + escapeHtml(__( 'Diagnosed at:', 'npc-site-doctor' ))
                + ' <strong>' + escapeHtml(dateLabel) + '</strong></div>'
            : '';

        var subtitleText = sprintf(__( '%1$s (checked %2$d items)', 'npc-site-doctor' ),
            subtitleMap[gradeClass], total);

        var html = '<div class="npc-summary-card npc-summary-card--' + gradeClass + '">'
            + '<div class="npc-score-circle npc-score-circle--' + gradeClass + '">'
            +   '<div class="npc-score-grade">' + grade + '</div>'
            +   '<div class="npc-score-label">TOTAL SCORE</div>'
            + '</div>'
            + '<div class="npc-summary-body">'
            +   '<div class="npc-summary-head">'
            +     '<h2 class="npc-summary-title">' + escapeHtml(__( 'Overall Diagnosis', 'npc-site-doctor' )) + '</h2>'
            +     dateHtml
            +   '</div>'
            +   '<p class="npc-summary-subtitle">' + escapeHtml(subtitleText) + '</p>'
            +   '<div class="npc-count-pills">'
            +     countPill('critical', __( 'Critical', 'npc-site-doctor' ), counts.critical)
            +     countPill('warning', __( 'Warning', 'npc-site-doctor' ), counts.warning)
            +     countPill('ok', __( 'OK', 'npc-site-doctor' ), counts.ok)
            +   '</div>'
            +   '<div class="npc-progress-bar">' + segments + '</div>'
            + '</div>'
            + '</div>';

        $('#npc-summary-header').html(html).show();
    }

    /**
     * Show the AI report.
     */
    function renderReport(reportText) {
        var html = buildReportHtml(reportText, currentLog.results);
        $('#npc-report-content').html(html);
        $('#npc-report').show();
        $('#npc-download-report').show();
    }

    /**
     * Convert AI report text into display HTML.
     * Pure function (reused for history rendering).
     *
     * @param {string} reportText      Raw AI report text.
     * @param {object} resultsForGrade Diagnosis result for header rebuild. Pass null to skip.
     */
    function buildReportHtml(reportText, resultsForGrade) {
        var html = '';

        var summaryMatch = reportText.match(/\[SUMMARY\]([\s\S]*?)\[\/SUMMARY\]/);
        var aiGrade = null;
        var summaryComment = '';

        if (summaryMatch) {
            var summaryRaw = summaryMatch[1].trim();
            // AI follows the prompt and produces "総合評価: X" in Japanese.
            var gradeMatch = summaryRaw.match(/総合評価[：:]?\s*([A-D])/);
            if (gradeMatch) aiGrade = gradeMatch[1];
            summaryComment = summaryRaw.replace(/総合評価[：:]?\s*[A-D][^\n]*\n?/, '').trim();
        }

        // Override summary header with AI grade (only for the current report; history skips).
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

            // AI prompt instructs Japanese "該当なし" for empty sections.
            var emptyHeading = /^##.*\n+該当(なし|ありません)/;
            var noneMarker = '該当なし';
            var isEmpty = emptyHeading.test(sectionContent)
                || (sectionContent.indexOf(noneMarker) !== -1 && sectionContent.split('\n').length <= 3);

            sectionContent = sectionContent
                .replace(/\[STATUS:critical\]/g, '<span class="npc-badge npc-badge--critical">'
                    + escapeHtml(__( 'Critical', 'npc-site-doctor' )) + '</span> ')
                .replace(/\[STATUS:warning\]/g, '<span class="npc-badge npc-badge--warning">'
                    + escapeHtml(__( 'Warning', 'npc-site-doctor' )) + '</span> ')
                .replace(/\[STATUS:ok\]/g, '<span class="npc-badge npc-badge--ok">'
                    + escapeHtml(__( 'OK', 'npc-site-doctor' )) + '</span> ');

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
     * Convert the [SECTION:action] body into action cards.
     */
    function renderActionSection(content) {
        var heading = __( 'Action Steps', 'npc-site-doctor' );
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
            var badgeLabel = {
                critical: __( 'Critical', 'npc-site-doctor' ),
                warning: __( 'Warning', 'npc-site-doctor' ),
                ok: __( 'OK', 'npc-site-doctor' )
            }[item.status];

            html += '<div class="npc-action-card npc-action-card--' + item.status + '">'
                + '<div class="npc-action-card__header">'
                +   '<span class="npc-badge npc-badge--' + item.status + '">' + escapeHtml(badgeLabel) + '</span>'
                +   '<strong>' + escapeHtml(title) + '</strong>'
                + '</div>'
                + (rest ? '<div class="npc-action-card__body">' + markdownToHtml(rest) + '</div>' : '')
                + '</div>';
        });

        html += '</div></div>';
        return html;
    }

    // =========================================
    // History accordion
    // =========================================

    /**
     * Render the entire history list.
     * @param {Array} logs Diagnosis history from server (newest first).
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

        // Download button click handler.
        $list.on('click', '.npc-history-download', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var logId = String($(this).data('logId'));
            var log = logs.find(function(l) { return String(l.id) === logId; });
            if (!log) return;
            var reportHtml = log.report
                ? buildReportHtml(log.report, null)
                : '<div class="npc-report-section npc-report-section--empty"><p>'
                    + escapeHtml(__( 'AI report not yet generated.', 'npc-site-doctor' )) + '</p></div>';
            downloadReport(log.results, log.report, log.date, reportHtml);
        });
    }

    /**
     * Render a single history accordion entry.
     */
    function renderHistoryItem(log) {
        var summary = log.summary || { grade: '-', counts: { critical: 0, warning: 0, ok: 0 } };
        var counts  = summary.counts || { critical: 0, warning: 0, ok: 0 };
        var grade   = summary.grade || '-';

        var gradeClass = 'ok';
        if (grade === 'D') gradeClass = 'critical';
        else if (grade === 'C' || grade === 'B') gradeClass = 'warning';

        var $details = $('<details class="npc-history-item npc-history-item--' + gradeClass + '"></details>');

        var dateLabel = sprintf(__( 'Diagnosis from %s', 'npc-site-doctor' ), log.date);

        var summaryBar = '<summary class="npc-history-summary">'
            + '<span class="npc-history-grade npc-history-grade--' + gradeClass + '">' + escapeHtml(grade) + '</span>'
            + '<span class="npc-history-date">' + escapeHtml(dateLabel) + '</span>'
            + '<span class="npc-history-counts">'
            +   '<span class="npc-history-count npc-history-count--critical">'
            +     escapeHtml(__( 'Critical', 'npc-site-doctor' )) + ' ' + counts.critical + '</span>'
            +   '<span class="npc-history-count npc-history-count--warning">'
            +     escapeHtml(__( 'Warning', 'npc-site-doctor' )) + ' ' + counts.warning + '</span>'
            +   '<span class="npc-history-count npc-history-count--ok">'
            +     escapeHtml(__( 'OK', 'npc-site-doctor' )) + ' ' + counts.ok + '</span>'
            + '</span>'
            + '<button type="button" class="button button-small npc-history-download" data-log-id="' + log.id + '">'
            +   escapeHtml(__( 'Download', 'npc-site-doctor' )) + '</button>'
            + '</summary>';

        var bodyHtml = '<div class="npc-history-body">';
        if (log.report) {
            bodyHtml += buildReportHtml(log.report, null);
        } else {
            bodyHtml += '<div class="npc-report-section npc-report-section--empty"><p>'
                + escapeHtml(__( 'This diagnosis has no AI report (results only).', 'npc-site-doctor' ))
                + '</p></div>';
        }
        bodyHtml += '</div>';

        $details.html(summaryBar + bodyHtml);
        return $details;
    }

    // =========================================
    // Download
    // =========================================

    /**
     * Download the report as an HTML file.
     * @param {object} results      Diagnosis result (used to obtain site name).
     * @param {string} reportText   Raw AI report text (unused; reserved for future).
     * @param {string} dateLabel    Diagnosis timestamp.
     * @param {string} reportHtml   Rendered report HTML.
     */
    function downloadReport(results, reportText, dateLabel, reportHtml) {
        // Site name comes from wp_localize_script (npcSiteDoctor.siteName).
        // Going through results.site_info.site_name has caused title encoding issues before.
        var siteName = (window.npcSiteDoctor && window.npcSiteDoctor.siteName)
            || (results && results.site_info && results.site_info.site_name)
            || __( 'Site', 'npc-site-doctor' );
        var date = (dateLabel || new Date().toISOString().slice(0, 10)).replace(/[: ]/g, '-');

        var titleStr = sprintf(__( '%1$s Maintenance Report %2$s', 'npc-site-doctor' ),
            siteName, date);
        var heading  = sprintf(__( '%s — Maintenance Report', 'npc-site-doctor' ), siteName);
        var meta     = sprintf(__( 'Diagnosed at: %s', 'npc-site-doctor' ), dateLabel || '');

        var html = '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8">'
            + '<title>' + escapeHtml(titleStr) + '</title>'
            + '<style>' + getDownloadStyle() + '</style></head><body>'
            + '<h1>' + escapeHtml(heading) + '</h1>'
            + '<p class="meta">' + escapeHtml(meta) + '</p>'
            + reportHtml
            + '<div class="footer">Generated by NPC Site Doctor</div>'
            + '</body></html>';

        var blob = new Blob([html], { type: 'text/html' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'site-doctor-report-' + date + '.html';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    /**
     * Built-in CSS for the download HTML.
     * Mirrors the admin UI with a minimal style set.
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
    // Helpers
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
            + '<span>' + escapeHtml(label) + '</span></span>';
    }

    /**
     * Build a diagnostic card.
     * @param {string} title    Card title.
     * @param {string} status   ok / warning / critical / update_available / unknown.
     * @param {Array}  items    Array of item strings.
     * @param {string} [cardId] Card identifier (stored on data attribute).
     * @param {Array}  [actions] Action buttons [{label, action, variant}].
     */
    function createCard(title, status, items, cardId, actions) {
        var badgeLabel = {
            ok: __( 'OK', 'npc-site-doctor' ),
            warning: __( 'Warning', 'npc-site-doctor' ),
            critical: __( 'Critical', 'npc-site-doctor' ),
            unknown: __( 'Unknown', 'npc-site-doctor' ),
            update_available: __( 'Update available', 'npc-site-doctor' )
        };
        var cardStatus = (status === 'update_available') ? 'warning' : status;
        var dataAttr = cardId ? ' data-card-id="' + escapeHtml(cardId) + '"' : '';

        var html = '<div class="npc-card npc-card--' + cardStatus + '"' + dataAttr + '>'
            + '<h3>' + escapeHtml(title) + ' <span class="npc-badge npc-badge--' + cardStatus + '">'
            + escapeHtml(badgeLabel[status] || status) + '</span></h3><ul>';

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
    // Page-load init
    // =========================================

    $(function() {
        var history = npcSiteDoctor.history || [];

        if (history.length > 0) {
            // Latest entry is rendered as "previous diagnosis" in the main area.
            var latest = history[0];
            currentLog.results = latest.results;
            currentLog.report  = latest.report || '';
            currentLog.date    = latest.date;

            if (latest.results) {
                $('#npc-report-title').text(__( 'Previous AI Report', 'npc-site-doctor' ));
                renderResults(latest.results, latest.date);
            }
            if (latest.report) {
                renderReport(latest.report);
            }

            // Older entries are pushed into the history accordion.
            renderHistoryList(history.slice(1));
        }
    });

})(jQuery);
