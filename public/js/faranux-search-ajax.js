(function ($) {
    'use strict';

    $(document).on('input', '.faranux-strict-search-input', function () {
        var $input = $(this);
        var $results = $input.siblings('.faranux-strict-search-results');
        var query = $.trim($input.val());

        if (query.length < 2) {
            $results.hide().html('');
            return;
        }

        $.ajax({
            url: window.ajaxurl || '/wp-admin/admin-ajax.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'faranux_strict_search_ajax',
                query: query
            },
            success: function (response) {
                if (response && response.success && response.data) {
                    $results.html(response.data).show();
                } else {
                    $results.hide().html('');
                }
            }
        });
    });
})(jQuery);
