(function ($) {
    'use strict';

    /**
     * Minimum characters before firing a live search.
     */
    var MIN_CHARS = 2;

    /**
     * Debounce delay in milliseconds.
     */
    var DEBOUNCE_MS = 280;

    /**
     * Active XHR reference so we can abort stale requests.
     */
    var activeXhr = null;

    /**
     * Debounce helper — returns a function that fires fn only after `wait` ms
     * of silence.
     */
    function debounce(fn, wait) {
        var timer;
        return function () {
            var ctx  = this;
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () {
                fn.apply(ctx, args);
            }, wait);
        };
    }

    /**
     * Shows a loading indicator inside the results panel.
     */
    function showLoading($results) {
        $results
            .html('<p class="faranux-searching">' + faranuxSearch.i18n.searching + '</p>')
            .addClass('is-visible');
    }

    /**
     * Hides and clears the results panel.
     */
    function hideResults($results) {
        $results.removeClass('is-visible').html('');
    }

    /**
     * Performs the AJAX search request.
     *
     * @param {jQuery} $input   The search input element.
     * @param {jQuery} $results The results container element.
     * @param {string} query    The sanitized query string.
     */
    function doSearch($input, $results, query) {
        // Abort any in-flight request.
        if (activeXhr) {
            activeXhr.abort();
            activeXhr = null;
        }

        showLoading($results);

        activeXhr = $.ajax({
            url:      faranuxSearch.ajaxurl,
            type:     'POST',
            dataType: 'json',
            data: {
                action: 'faranux_strict_search_ajax',
                nonce:  faranuxSearch.nonce,
                query:  query
            },
            success: function (response) {
                if (response && response.success && response.data) {
                    $results.html(response.data).addClass('is-visible');
                } else {
                    hideResults($results);
                }
            },
            error: function (xhr) {
                // Ignore intentional aborts.
                if ('abort' === xhr.statusText) {
                    return;
                }
                hideResults($results);
            },
            complete: function () {
                activeXhr = null;
            }
        });
    }

    var debouncedSearch = debounce(function ($input, $results, query) {
        doSearch($input, $results, query);
    }, DEBOUNCE_MS);

    // -------------------------------------------------------------------------
    // Event bindings
    // -------------------------------------------------------------------------

    $(document).on('input', '.faranux-strict-search-input', function () {
        var $input   = $(this);
        var $results = $input.closest('.faranux-strict-search-form')
                             .find('.faranux-strict-search-results');
        var query    = $.trim($input.val());

        if (query.length < MIN_CHARS) {
            if (activeXhr) {
                activeXhr.abort();
                activeXhr = null;
            }
            hideResults($results);
            return;
        }

        debouncedSearch($input, $results, query);
    });

    // Close the dropdown when clicking outside.
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.faranux-strict-search-form').length) {
            $('.faranux-strict-search-results').each(function () {
                hideResults($(this));
            });
        }
    });

    // Close on Escape key.
    $(document).on('keydown', '.faranux-strict-search-input', function (e) {
        if (27 === e.which) { // Escape
            var $results = $(this).closest('.faranux-strict-search-form')
                                  .find('.faranux-strict-search-results');
            hideResults($results);
        }
    });

})(jQuery);
