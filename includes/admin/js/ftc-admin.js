(function ($) {
    'use strict';

    $(function () {
        var button = $('#ftc-test-connection');
        var spinner = $('#ftc-test-spinner');
        var message = $('#ftc-test-result');

        if (button.length) {
            button.on('click', function (event) {
                event.preventDefault();
                message.text(ftcAdmin.messages.testing).removeClass('ftc-error ftc-success');
                spinner.addClass('is-active');

                $.post(
                    ftcAdmin.ajaxUrl,
                    {
                        action: 'ftc_test_connection',
                        nonce: ftcAdmin.nonce
                    }
                )
                    .done(function (response) {
                        spinner.removeClass('is-active');
                        if (response && response.success) {
                            message.text(ftcAdmin.messages.success).addClass('ftc-success');
                        } else {
                            message.text((response && response.data && response.data.message) || ftcAdmin.messages.error).addClass('ftc-error');
                        }
                    })
                    .fail(function () {
                        spinner.removeClass('is-active');
                        message.text(ftcAdmin.messages.error).addClass('ftc-error');
                    });
            });
        }

        var userButton = $('#ftc-test-user');
        var userSpinner = $('#ftc-test-user-spinner');
        var userMessage = $('#ftc-test-user-result');

        if (userButton.length) {
            if (!window.fetch) {
                userButton.on('click', function (event) {
                    event.preventDefault();
                    userMessage.text(ftcAdmin.messages.userError).addClass('ftc-error');
                });
                return;
            }

            userButton.on('click', function (event) {
                event.preventDefault();
                userMessage.text(ftcAdmin.messages.userTesting).removeClass('ftc-error ftc-success');
                userSpinner.addClass('is-active');

                window.fetch(ftcAdmin.testUserUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': ftcAdmin.restNonce
                    },
                    body: JSON.stringify({})
                })
                    .then(function (response) {
                        return response.json()
                            .catch(function () {
                                return {};
                            })
                            .then(function (data) {
                                if (!response.ok) {
                                    throw data;
                                }
                                return data;
                            });
                    })
                    .then(function (data) {
                        userSpinner.removeClass('is-active');
                        if (data && data.id) {
                            var successMessage = ftcAdmin.messages.userSuccess.replace('%s', data.id);
                            userMessage.text(successMessage).addClass('ftc-success');
                        } else {
                            userMessage.text(ftcAdmin.messages.userError).addClass('ftc-error');
                        }
                    })
                    .catch(function (error) {
                        userSpinner.removeClass('is-active');
                        var errorMessage = ftcAdmin.messages.userError;
                        if (error) {
                            if (error.message) {
                                errorMessage = error.message;
                            } else if (error.data && error.data.message) {
                                errorMessage = error.data.message;
                            }
                        }
                        userMessage.text(errorMessage).addClass('ftc-error');
                    });
            });
        }

        var productsRunButton = $('#ftc-products-map-run');
        if (productsRunButton.length) {
            var exportButton = $('#ftc-products-export');
            var spinner = $('#ftc-products-spinner');
            var summaryContainer = $('#ftc-products-summary');
            var tableBody = $('#ftc-products-results tbody');
            var truncatedNotice = $('#ftc-products-truncated');
            var state = {
                rows: [],
                summary: {},
                truncated: false
            };

            var escapeHtml = function (text) {
                return $('<div />').text(text || '').html();
            };

            var getNonce = function () {
                var field = $('input[name="ftc_products_map_nonce"]');
                return field.length ? field.val() : '';
            };

            var resetTable = function () {
                tableBody.empty().append('<tr><td colspan="5">' + escapeHtml(ftcAdmin.productsMessages.empty) + '</td></tr>');
            };

            var mapNotes = function (notes) {
                if (!notes) {
                    return '';
                }

                var labels = ftcAdmin.productsNotesLabels || {};

                return notes.split(',')
                    .map(function (note) {
                        var trimmed = note.trim();
                        return labels[trimmed] || trimmed;
                    })
                    .filter(function (value) {
                        return !!value;
                    })
                    .join(', ');
            };

            var renderSummary = function (summary, applied) {
                if (!summary || typeof summary !== 'object') {
                    summaryContainer.empty();
                    summaryContainer.removeClass('ftc-error ftc-success');
                    return;
                }

                var labels = ftcAdmin.productsSummaryLabels || {};
                var html = '<p><strong>' + escapeHtml(applied ? ftcAdmin.productsMessages.applyDone : ftcAdmin.productsMessages.previewDone) + '</strong></p>';
                html += '<ul>';

                ['totalTopTen', 'totalWooMatched', 'saved', 'skipped', 'already_set', 'conflicts', 'pagesProcessed'].forEach(function (key) {
                    if (typeof summary[key] === 'undefined') {
                        return;
                    }

                    var label = labels[key] || key;
                    html += '<li>' + escapeHtml(label) + ': ' + escapeHtml(String(summary[key])) + '</li>';
                });

                html += '</ul>';

                summaryContainer.html(html);
                summaryContainer.removeClass('ftc-error').addClass('ftc-success');
            };

            var renderTable = function (rows) {
                tableBody.empty();

                if (!rows || !rows.length) {
                    resetTable();
                    return;
                }

                var actionLabels = ftcAdmin.productsActionLabels || {};
                var sourceLabels = ftcAdmin.productsSourceLabels || {};
                var variantLabel = sourceLabels.variantOf || 'Var. de';
                var topTenSkuLabel = ftcAdmin.productsMessages.toptenSku || 'TopTen SKU';

                rows.forEach(function (row) {
                    var productId = parseInt(row.wc_product_id, 10) || 0;
                    var parentId = parseInt(row.wc_parent_id, 10) || 0;
                    var sku = row.sku_woo || '';
                    var topTenSku = row.topten_sku || '';
                    var topTenId = row.topten_id ? String(row.topten_id) : '';
                    var source = row.source || '';
                    var action = row.action || '';
                    var notes = mapNotes(row.notes || '');

                    var editUrl = '';
                    if (productId > 0 && ftcAdmin.editPostUrl) {
                        editUrl = ftcAdmin.editPostUrl + '?post=' + productId + '&action=edit';
                    }

                    var idCell = productId > 0 ? ('#' + productId) : '&mdash;';
                    if (editUrl) {
                        idCell = '<a href="' + escapeHtml(editUrl) + '">#' + escapeHtml(String(productId)) + '</a>';
                    }

                    if (row.wc_product_name) {
                        idCell += '<br /><small>' + escapeHtml(row.wc_product_name) + '</small>';
                    }

                    if (parentId > 0) {
                        idCell += '<br /><small>' + escapeHtml(variantLabel) + ' #' + escapeHtml(String(parentId)) + '</small>';
                    }

                    var skuCell = escapeHtml(sku);
                    if (topTenSku && topTenSku !== sku) {
                        skuCell += '<br /><small>' + escapeHtml(topTenSkuLabel) + ': ' + escapeHtml(topTenSku) + '</small>';
                    }

                    var actionText = actionLabels[action] || action;
                    var actionCell = escapeHtml(actionText);
                    if (notes) {
                        actionCell += '<br /><small>' + escapeHtml(notes) + '</small>';
                    }

                    var sourceText = sourceLabels[source] || source;
                    var topTenCell = topTenId ? escapeHtml(topTenId) : '&mdash;';

                    var rowHtml = '<tr>' +
                        '<td>' + idCell + '</td>' +
                        '<td>' + skuCell + '</td>' +
                        '<td>' + topTenCell + '</td>' +
                        '<td>' + escapeHtml(sourceText) + '</td>' +
                        '<td>' + actionCell + '</td>' +
                        '</tr>';

                    tableBody.append(rowHtml);
                });
            };

            var setBusy = function (busy) {
                productsRunButton.prop('disabled', busy);
                exportButton.prop('disabled', busy || !state.rows.length);
                if (busy) {
                    spinner.addClass('is-active');
                } else {
                    spinner.removeClass('is-active');
                }
            };

            var buildPayload = function () {
                return {
                    nonce: getNonce()
                };
            };

            var runMap = function () {
                if (!window.fetch) {
                    summaryContainer.text(ftcAdmin.productsMessages.error).removeClass('ftc-success').addClass('ftc-error');
                    return;
                }

                var payload = buildPayload();
                if (!payload.nonce) {
                    summaryContainer.text(ftcAdmin.productsMessages.error).removeClass('ftc-success').addClass('ftc-error');
                    return;
                }

                summaryContainer.text(ftcAdmin.productsMessages.applying).removeClass('ftc-error ftc-success');
                truncatedNotice.hide();
                setBusy(true);

                fetch(ftcAdmin.productsMapUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': ftcAdmin.restNonce
                    },
                    body: JSON.stringify(payload)
                })
                    .then(function (response) {
                        return response.json()
                            .catch(function () {
                                return {};
                            })
                            .then(function (data) {
                                if (!response.ok) {
                                    throw data;
                                }

                                return data;
                            });
                    })
                    .then(function (data) {
                        state.rows = Array.isArray(data.rows) ? data.rows : [];
                        state.summary = data && data.summary && typeof data.summary === 'object' ? data.summary : {};
                        state.truncated = !!data.truncated;

                        renderTable(state.rows);
                        if (state.rows.length) {
                            renderSummary(state.summary, true);
                        } else {
                            summaryContainer.text(ftcAdmin.productsMessages.empty).removeClass('ftc-success').addClass('ftc-error');
                        }

                        if (state.truncated) {
                            truncatedNotice.show();
                        } else {
                            truncatedNotice.hide();
                        }

                        exportButton.prop('disabled', !state.rows.length);
                        setBusy(false);
                    })
                    .catch(function (error) {
                        state.rows = [];
                        state.summary = {};
                        state.truncated = false;

                        var message = ftcAdmin.productsMessages.error;
                        if (error) {
                            if (error.message) {
                                message = error.message;
                            } else if (error.data && error.data.message) {
                                message = error.data.message;
                            }
                        }

                        summaryContainer.text(message).removeClass('ftc-success').addClass('ftc-error');
                        resetTable();
                        truncatedNotice.hide();
                        exportButton.prop('disabled', true);
                        setBusy(false);
                    });
            };

            var exportCsv = function () {
                if (!state.rows.length) {
                    summaryContainer.text(ftcAdmin.productsMessages.exportEmpty).removeClass('ftc-success').addClass('ftc-error');
                    return;
                }

                var headers = ['wc_product_id', 'wc_product_name', 'wc_parent_id', 'sku_woo', 'topten_sku', 'topten_id', 'source', 'action', 'notes'];
                var rows = [headers];

                state.rows.forEach(function (row) {
                    rows.push(headers.map(function (header) {
                        var value = typeof row[header] === 'undefined' || row[header] === null ? '' : row[header];
                        return String(value);
                    }));
                });

                var csvContent = rows.map(function (row) {
                    return row.map(function (value) {
                        var needsQuote = value.indexOf(',') !== -1 || value.indexOf('"') !== -1 || value.indexOf('\n') !== -1;
                        var escaped = value.replace(/"/g, '""');
                        return needsQuote ? '"' + escaped + '"' : escaped;
                    }).join(',');
                }).join('\r\n');

                var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                var url = URL.createObjectURL(blob);
                var link = document.createElement('a');
                link.href = url;
                link.download = ftcAdmin.productsMessages.exportFile || 'mapeo-topten.csv';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
            };

            if (!window.fetch) {
                productsRunButton.on('click', function (event) {
                    event.preventDefault();
                    summaryContainer.text(ftcAdmin.productsMessages.error).removeClass('ftc-success').addClass('ftc-error');
                });
            } else {
                resetTable();
                productsRunButton.on('click', function (event) {
                    event.preventDefault();
                    runMap();
                });

                exportButton.on('click', function (event) {
                    event.preventDefault();
                    exportCsv();
                });
            }
        }

        $('.ftc-copy-button').on('click', function (event) {
            event.preventDefault();

            var button = $(this);
            var targetSelector = button.data('copy-target');
            var target = targetSelector ? document.querySelector(targetSelector) : null;
            var value = '';

            if (target && typeof target.value !== 'undefined') {
                value = target.value;
            }

            if (!value && button.data('copy-value')) {
                value = button.data('copy-value');
            }

            if (!button.data('copy-original')) {
                button.data('copy-original', button.text());
            }

            var showCopied = function () {
                button.text(ftcAdmin.messages.copied);
                setTimeout(function () {
                    button.text(button.data('copy-original'));
                }, 2000);
            };

            var showError = function () {
                button.text(ftcAdmin.messages.copyFallback);
                setTimeout(function () {
                    button.text(button.data('copy-original'));
                }, 2000);
            };

            if (!value) {
                showError();
                return;
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value)
                    .then(showCopied)
                    .catch(showError);
                return;
            }

            if (target && target.select) {
                target.select();
                try {
                    var successful = document.execCommand('copy');
                    window.getSelection().removeAllRanges();
                    if (successful) {
                        showCopied();
                    } else {
                        showError();
                    }
                } catch (err) {
                    showError();
                }

                return;
            }

            showError();
        });

        $('.ftc-toggle-password').on('click', function (event) {
            event.preventDefault();

            var button = $(this);
            var targetSelector = button.data('target');
            var input = targetSelector ? document.querySelector(targetSelector) : null;

            if (!input) {
                return;
            }

            var isPassword = input.getAttribute('type') === 'password';
            input.setAttribute('type', isPassword ? 'text' : 'password');

            button.attr('aria-pressed', isPassword ? 'true' : 'false');

            var labelShow = button.data('label-show');
            var labelHide = button.data('label-hide');
            if (labelShow && labelHide) {
                button.attr('aria-label', isPassword ? labelHide : labelShow);
            }
        });
    });
})(jQuery);
