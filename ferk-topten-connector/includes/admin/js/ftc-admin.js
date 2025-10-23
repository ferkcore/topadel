(function ($) {
    'use strict';

    $(function () {
        var button = $('#ftc-test-connection');
        var spinner = $('#ftc-test-spinner');
        var message = $('#ftc-test-result');

        if (!button.length) {
            return;
        }

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
    });
})(jQuery);
