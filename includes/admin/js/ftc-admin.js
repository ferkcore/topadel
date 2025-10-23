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

        var authButton = $('#ftc-test-auth');
        var authSpinner = $('#ftc-test-auth-spinner');
        var authMessage = $('#ftc-test-auth-result');

        if (authButton.length) {
            if (!window.fetch) {
                authButton.on('click', function (event) {
                    event.preventDefault();
                    authMessage.text(ftcAdmin.messages.authError).addClass('ftc-error');
                });
            } else {
                authButton.on('click', function (event) {
                    event.preventDefault();
                    authMessage.text(ftcAdmin.messages.authTesting).removeClass('ftc-error ftc-success');
                    authSpinner.addClass('is-active');

                    window.fetch(ftcAdmin.testAuthUrl, {
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
                            authSpinner.removeClass('is-active');
                            if (data && data.ok) {
                                var prefix = data.token_starts_with || '';
                                var expires = parseInt(data.expires_in, 10);
                                if (isNaN(expires)) {
                                    expires = 0;
                                }
                                var message = ftcAdmin.messages.authSuccess
                                    .replace('%1$s', prefix)
                                    .replace('%2$d', expires);
                                authMessage.text(message).addClass('ftc-success');
                            } else {
                                var errorMessage = (data && data.message) || ftcAdmin.messages.authError;
                                authMessage.text(errorMessage).addClass('ftc-error');
                            }
                        })
                        .catch(function (error) {
                            authSpinner.removeClass('is-active');
                            var errorMessage = ftcAdmin.messages.authError;
                            if (error) {
                                if (error.message) {
                                    errorMessage = error.message;
                                } else if (error.data && error.data.message) {
                                    errorMessage = error.data.message;
                                }
                            }
                            authMessage.text(errorMessage).addClass('ftc-error');
                        });
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
