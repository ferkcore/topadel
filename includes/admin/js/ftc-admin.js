(function ($) {
    'use strict';

    $(function () {
        var escapeHtml = function (text) {
            return $('<div />').text(text || '').html();
        };

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

        var matcherButton = $('#ftc-matcher-run');
        if (matcherButton.length) {
            if (!window.fetch) {
                matcherButton.on('click', function (event) {
                    event.preventDefault();
                });
            } else {
                var matcherConfig = (typeof ftcAdmin !== 'undefined' && ftcAdmin.matcher) ? ftcAdmin.matcher : {};
                var matcherFileInput = $('#ftc-matcher-file');
                var matcherSpinner = $('#ftc-matcher-spinner');
                var matcherStatus = $('#ftc-matcher-status');
                var matcherSummary = $('#ftc-matcher-summary');
                var matcherTableBody = $('#ftc-matcher-results tbody');
                var matcherError = $('#ftc-matcher-error');
                var matcherErrorText = $('#ftc-matcher-error p');
                var messages = matcherConfig.messages || {};
                var summaryLabels = matcherConfig.summaryLabels || {};
                var statusLabels = matcherConfig.statusLabels || {};
                var tableMessages = matcherConfig.tableMessages || {};
                var previousLabel = matcherConfig.previousLabel || '';
                var variantLabel = messages.variantOf || (ftcAdmin.productsSourceLabels && ftcAdmin.productsSourceLabels.variantOf) || 'Var. de';
                var initialTableMessage = (matcherTableBody.find('td').first().text() || '').trim();

                var getNonce = function () {
                    var field = $('input[name="ftc_products_matcher_nonce"]');
                    return field.length ? field.val() : '';
                };

                var resetTable = function () {
                    var emptyMessage = tableMessages.empty || initialTableMessage || '';
                    matcherTableBody.empty().append('<tr><td colspan="5">' + escapeHtml(emptyMessage) + '</td></tr>');
                };

                var hideError = function () {
                    matcherError.hide();
                    matcherErrorText.text('');
                };

                var showError = function (message) {
                    matcherErrorText.text(message || messages.error || '');
                    matcherError.show();
                };

                var setStatus = function (message, type) {
                    matcherStatus.text(message || '');
                    matcherStatus.removeClass('ftc-error ftc-success');
                    if (type) {
                        matcherStatus.addClass(type);
                    }
                };

                var setBusy = function (busy) {
                    matcherButton.prop('disabled', busy);
                    matcherFileInput.prop('disabled', busy);
                    if (busy) {
                        matcherSpinner.addClass('is-active');
                    } else {
                        matcherSpinner.removeClass('is-active');
                    }
                };

                var renderSummary = function (summary) {
                    if (!summary || typeof summary !== 'object') {
                        matcherSummary.empty();
                        matcherSummary.removeClass('ftc-error ftc-success');
                        return;
                    }

                    var keys = ['totalRows', 'processed', 'updated', 'unchanged', 'skipped', 'notFound', 'errors'];
                    var html = '<ul>';
                    var hasValues = false;

                    keys.forEach(function (key) {
                        if (typeof summary[key] === 'undefined') {
                            return;
                        }

                        hasValues = true;
                        var label = summaryLabels[key] || key;
                        html += '<li><strong>' + escapeHtml(label) + ':</strong> ' + escapeHtml(String(summary[key])) + '</li>';
                    });

                    html += '</ul>';

                    if (!hasValues) {
                        matcherSummary.empty();
                        matcherSummary.removeClass('ftc-error ftc-success');
                        return;
                    }

                    matcherSummary.html(html);
                    matcherSummary.removeClass('ftc-error').addClass('ftc-success');
                };

                var renderResults = function (items) {
                    matcherTableBody.empty();

                    if (!Array.isArray(items) || !items.length) {
                        resetTable();
                        return;
                    }

                    items.forEach(function (item) {
                        var rowNumber = item && typeof item.row !== 'undefined' ? String(item.row) : '';
                        var sku = item && typeof item.sku !== 'undefined' ? String(item.sku) : '';
                        var toptenId = item && typeof item.topten_id !== 'undefined' && item.topten_id !== null ? String(item.topten_id) : '';
                        var productId = item && item.product_id ? parseInt(item.product_id, 10) : 0;
                        var productName = item && item.product_name ? String(item.product_name) : '';
                        var parentId = item && item.product_parent_id ? parseInt(item.product_parent_id, 10) : 0;
                        var previousId = item && typeof item.previous_id !== 'undefined' && item.previous_id ? String(item.previous_id) : '';
                        var statusKey = item && item.status ? String(item.status) : '';
                        var message = item && item.message ? String(item.message) : '';

                        var rowCell = rowNumber ? escapeHtml(rowNumber) : '&mdash;';
                        var skuCell = sku ? escapeHtml(sku) : '&mdash;';
                        var toptenCell = toptenId ? escapeHtml(toptenId) : '&mdash;';

                        var productCell = '&mdash;';
                        if (productId > 0) {
                            var editUrl = ftcAdmin.editPostUrl ? ftcAdmin.editPostUrl + '?post=' + productId + '&action=edit' : '';
                            productCell = editUrl ? '<a href="' + escapeHtml(editUrl) + '">#' + escapeHtml(String(productId)) + '</a>' : '#' + escapeHtml(String(productId));
                            if (productName) {
                                productCell += '<br /><small>' + escapeHtml(productName) + '</small>';
                            }
                            if (parentId > 0) {
                                productCell += '<br /><small>' + escapeHtml(variantLabel) + ' #' + escapeHtml(String(parentId)) + '</small>';
                            }
                        }

                        var statusLabel = statusLabels[statusKey] || statusKey;
                        var statusCell = statusLabel ? escapeHtml(statusLabel) : '&mdash;';
                        if (message) {
                            statusCell += '<br /><small>' + escapeHtml(message) + '</small>';
                        }
                        if (previousLabel && previousId && previousId !== toptenId) {
                            statusCell += '<br /><small>' + escapeHtml(previousLabel) + ': ' + escapeHtml(previousId) + '</small>';
                        }

                        var rowHtml = '<tr>' +
                            '<td>' + rowCell + '</td>' +
                            '<td>' + skuCell + '</td>' +
                            '<td>' + toptenCell + '</td>' +
                            '<td>' + productCell + '</td>' +
                            '<td>' + statusCell + '</td>' +
                            '</tr>';

                        matcherTableBody.append(rowHtml);
                    });
                };

                var runMatcher = function () {
                    hideError();
                    setStatus('', '');
                    matcherSummary.empty();
                    matcherSummary.removeClass('ftc-error ftc-success');

                    var nonce = getNonce();
                    var files = matcherFileInput.length ? matcherFileInput[0].files : null;

                    if (!files || !files.length) {
                        setStatus(messages.selectFile || '', 'ftc-error');
                        return;
                    }

                    if (!nonce) {
                        showError(messages.error || '');
                        return;
                    }

                    var formData = new FormData();
                    formData.append('nonce', nonce);
                    formData.append('file', files[0]);

                    setBusy(true);
                    setStatus(messages.uploading || '', '');

                    fetch(matcherConfig.url, {
                        method: 'POST',
                        headers: {
                            'X-WP-Nonce': ftcAdmin.restNonce
                        },
                        body: formData
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
                            setBusy(false);

                            var items = data && Array.isArray(data.items) ? data.items : [];
                            var summary = data && data.summary && typeof data.summary === 'object' ? data.summary : {};

                            renderResults(items);
                            renderSummary(summary);

                            if (items.length) {
                                setStatus(messages.success || '', 'ftc-success');
                            } else {
                                setStatus(messages.empty || '', 'ftc-error');
                            }
                        })
                        .catch(function (error) {
                            setBusy(false);
                            renderResults([]);
                            renderSummary({});

                            var message = messages.error || '';
                            if (error) {
                                if (error.message) {
                                    message = error.message;
                                } else if (error.data && error.data.message) {
                                    message = error.data.message;
                                }
                            }

                            setStatus('', '');
                            showError(message);
                        });
                };

                resetTable();
                matcherButton.on('click', function (event) {
                    event.preventDefault();
                    runMatcher();
                });
            }
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

        var searchButton = $('#ftc-search-run');
        if (searchButton.length) {
            var searchConfig = (typeof ftcAdmin !== 'undefined' && ftcAdmin.search) ? ftcAdmin.search : {};
            var searchSpinner = $('#ftc-search-spinner');
            var searchStatus = $('#ftc-search-status');
            var searchError = $('#ftc-search-error');
            var searchErrorText = $('#ftc-search-error p');
            var searchTableBody = $('#ftc-search-results tbody');
            var searchMeta = $('#ftc-search-meta');
            var searchTermInput = $('#ftc-search-term');
            var searchPageInput = $('#ftc-search-page');
            var searchNonceField = $('input[name="ftc_products_search_nonce"]');
            var initialSearchMessage = (searchTableBody.find('td').first().text() || '').trim();
            var searchMessages = searchConfig.messages || {};
            var searchEmptyMessage = searchMessages.empty || initialSearchMessage || (ftcAdmin.productsMessages ? ftcAdmin.productsMessages.empty : '');
            var searchErrorMessage = searchMessages.error || (ftcAdmin.productsMessages ? ftcAdmin.productsMessages.error : '');
            var searchRunningMessage = searchMessages.running || '';
            var searchTermsPrefix = searchMessages.termsPrefix || 'Términos';

            var searchFormatTemplate = function (template, replacements) {
                if (!template) {
                    return '';
                }

                return template.replace(/%(\d)\$d/g, function (match, index) {
                    var key = parseInt(index, 10);
                    if (!Object.prototype.hasOwnProperty.call(replacements, key)) {
                        return match;
                    }

                    return String(replacements[key]);
                });
            };

            var searchResetTable = function (message) {
                var text = message || searchEmptyMessage || '';
                if (text) {
                    searchTableBody.empty().append('<tr><td colspan="5">' + escapeHtml(text) + '</td></tr>');
                } else {
                    searchTableBody.empty();
                }
            };

            var searchHideError = function () {
                searchError.hide();
                searchErrorText.text('');
            };

            var searchShowError = function (message) {
                var text = message || searchErrorMessage || '';
                if (!text && searchMessages.error) {
                    text = searchMessages.error;
                }
                searchErrorText.text(text);
                searchError.show();
            };

            var searchSetStatus = function (text) {
                searchStatus.text(text || '');
            };

            var searchRenderRows = function (products) {
                searchTableBody.empty();

                if (!Array.isArray(products) || !products.length) {
                    searchResetTable(searchEmptyMessage);
                    return;
                }

                products.forEach(function (product) {
                    var info = product && product.InfoProducto ? product.InfoProducto : {};
                    var productData = info && typeof info === 'object' ? (info.Producto || {}) : {};
                    var prodId = productData.Prod_Id;
                    if (typeof prodId === 'string') {
                        var parsedId = parseInt(prodId, 10);
                        prodId = isNaN(parsedId) ? prodId : parsedId;
                    }
                    if (typeof prodId !== 'number' && typeof prodId !== 'string') {
                        prodId = '';
                    }

                    var sku = productData.Prod_Sku || productData.SKU || productData.Sku || '';
                    var name = productData.Prod_Nombre || productData.Prod_Descripcion || productData.Nombre || productData.Descripcion || '';
                    var brand = '';
                    if (productData.Marca && typeof productData.Marca === 'object') {
                        brand = productData.Marca.Marc_Descripcion || productData.Marca.Descripcion || productData.Marca.Nombre || '';
                    }
                    if (!brand && productData.Prod_Marca) {
                        brand = productData.Prod_Marca;
                    }
                    if (!brand && info && typeof info === 'object') {
                        brand = info.MarcaDescripcion || '';
                        if (!brand && info.Marca && typeof info.Marca === 'object') {
                            brand = info.Marca.Descripcion || info.Marca.Nombre || '';
                        }
                    }

                    var price = '';
                    var priceKeys = ['Prod_PrecioConIva', 'Prod_PrecioConIVA', 'Prod_Precio', 'PrecioConIva', 'PrecioConIVA', 'Precio'];
                    priceKeys.some(function (key) {
                        if (typeof productData[key] !== 'undefined' && productData[key] !== null && productData[key] !== '') {
                            price = productData[key];
                            return true;
                        }
                        return false;
                    });
                    if (!price && info && typeof info === 'object' && typeof info.Precio !== 'undefined') {
                        price = info.Precio;
                    }
                    if (typeof price === 'number') {
                        price = price.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    } else if (typeof price !== 'string') {
                        if (price !== null && typeof price !== 'undefined') {
                            price = String(price);
                        } else {
                            price = '';
                        }
                    }

                    var termsPreview = '';
                    if (info && typeof info === 'object' && Array.isArray(info.TerminosList)) {
                        var values = info.TerminosList.map(function (term) {
                            if (!term) {
                                return '';
                            }
                            if (typeof term === 'string') {
                                return term;
                            }
                            if (typeof term === 'object') {
                                if (term.SkuPropio) {
                                    return term.SkuPropio;
                                }
                                if (term.Sku) {
                                    return term.Sku;
                                }
                                if (term.CodigoInterno) {
                                    return term.CodigoInterno;
                                }
                            }
                            return '';
                        }).filter(function (value) {
                            return !!value;
                        });

                        if (values.length) {
                            var previewValues = values.slice(0, 3);
                            termsPreview = previewValues.join(', ');
                            if (values.length > previewValues.length) {
                                termsPreview += '…';
                            }
                        }
                    }

                    var idCell = prodId ? escapeHtml(String(prodId)) : '&mdash;';
                    var skuCell = sku ? escapeHtml(String(sku)) : '&mdash;';
                    var nameHtml = name ? escapeHtml(String(name)) : '&mdash;';
                    if (termsPreview) {
                        nameHtml += '<br><small>' + escapeHtml(searchTermsPrefix) + ': ' + escapeHtml(termsPreview) + '</small>';
                    }
                    var brandCell = brand ? escapeHtml(String(brand)) : '&mdash;';
                    var priceCell = price ? escapeHtml(String(price)) : '&mdash;';

                    var rowHtml = '<tr>' +
                        '<td>' + idCell + '</td>' +
                        '<td>' + skuCell + '</td>' +
                        '<td>' + nameHtml + '</td>' +
                        '<td>' + brandCell + '</td>' +
                        '<td>' + priceCell + '</td>' +
                        '</tr>';

                    searchTableBody.append(rowHtml);
                });
            };

            var searchFormatMeta = function (meta, count) {
                if (!meta || typeof meta !== 'object') {
                    return '';
                }

                var page = parseInt(meta.page, 10);
                var pages = parseInt(meta.pages, 10);
                var total = parseInt(meta.total, 10);

                if (!page || isNaN(page) || page < 1) {
                    page = 1;
                }

                if (!pages || isNaN(pages) || pages < 0) {
                    pages = 0;
                }

                if (isNaN(total) || total < 0) {
                    total = typeof count === 'number' ? count : 0;
                }

                if (pages > 0 && searchMessages.meta) {
                    return searchFormatTemplate(searchMessages.meta, { 1: page, 2: pages, 3: total });
                }

                if (searchMessages.metaSingle) {
                    return searchFormatTemplate(searchMessages.metaSingle, { 1: page, 2: total });
                }

                return '';
            };

            var searchPerform = function () {
                if (!window.fetch) {
                    searchSetStatus('');
                    searchShowError(searchErrorMessage || searchMessages.error || '');
                    return;
                }

                if (!searchConfig.url) {
                    searchSetStatus('');
                    searchShowError(searchErrorMessage || searchMessages.error || '');
                    return;
                }

                var nonce = searchNonceField.length ? searchNonceField.val() : '';
                if (!nonce) {
                    searchSetStatus('');
                    searchShowError(searchErrorMessage || searchMessages.error || '');
                    return;
                }

                var pageValue = parseInt(searchPageInput.val(), 10);
                if (!pageValue || pageValue < 1) {
                    pageValue = 1;
                    searchPageInput.val(pageValue);
                }

                var termsValue = searchTermInput.val() || '';
                var terms = termsValue.split(/[,;]/).map(function (value) {
                    return value.trim();
                }).filter(function (value) {
                    return value.length > 0;
                });

                searchHideError();
                searchSetStatus(searchRunningMessage);
                searchSpinner.addClass('is-active');
                searchButton.prop('disabled', true);
                searchMeta.text('');

                window.fetch(searchConfig.url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': ftcAdmin.restNonce || ''
                    },
                    body: JSON.stringify({
                        nonce: nonce,
                        page: pageValue,
                        terms: terms
                    })
                })
                    .then(function (response) {
                        return response.json()
                            .catch(function () {
                                return {};
                            })
                            .then(function (data) {
                                if (!response.ok) {
                                    var message = '';
                                    if (data && data.message) {
                                        message = data.message;
                                    } else if (data && data.data && data.data.message) {
                                        message = data.data.message;
                                    }
                                    if (!message) {
                                        message = searchErrorMessage || searchMessages.error || '';
                                    }
                                    throw new Error(message);
                                }

                                return data;
                            });
                    })
                    .then(function (data) {
                        searchButton.prop('disabled', false);
                        searchSpinner.removeClass('is-active');

                        var products = Array.isArray(data.products) ? data.products : [];
                        searchRenderRows(products);

                        var metaText = searchFormatMeta(data.meta || {}, products.length);
                        searchMeta.text(metaText);

                        if (!products.length) {
                            searchSetStatus(searchEmptyMessage);
                        } else {
                            searchSetStatus('');
                        }
                    })
                    .catch(function (error) {
                        searchButton.prop('disabled', false);
                        searchSpinner.removeClass('is-active');

                        var message = error && error.message ? error.message : (searchErrorMessage || searchMessages.error || '');
                        searchResetTable(searchEmptyMessage);
                        searchMeta.text('');
                        searchSetStatus('');
                        searchShowError(message);
                    });
            };

            searchButton.on('click', function (event) {
                event.preventDefault();
                searchPerform();
            });

            searchTermInput.on('keypress', function (event) {
                if (event.which === 13) {
                    event.preventDefault();
                    searchPerform();
                }
            });

            searchPageInput.on('keypress', function (event) {
                if (event.which === 13) {
                    event.preventDefault();
                    searchPerform();
                }
            });
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
