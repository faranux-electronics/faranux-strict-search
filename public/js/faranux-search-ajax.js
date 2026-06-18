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
     * Aborts any in-flight request for this specific results panel.
     * Each search widget instance tracks its own XHR (via $.data on its own
     * results element) instead of a single module-level variable. Previously
     * one global "activeXhr" was shared by every search box on the page, so
     * typing into a second widget would abort the first widget's request —
     * and because the abort handler bailed out silently, that first widget's
     * panel got stuck showing "Searching…" forever.
     */
    function abortActive($results) {
        var existingXhr = $results.data('faranuxXhr');
        if (existingXhr) {
            existingXhr.abort();
            $results.removeData('faranuxXhr');
        }
    }

    /**
     * Performs the AJAX search request.
     *
     * @param {jQuery} $input   The search input element.
     * @param {jQuery} $results The results container element.
     * @param {string} query    The sanitized query string.
     */
    function doSearch($input, $results, query) {
        // Find the specific type field for this form (product vs post)
        var searchType = $input.closest('form').find('.faranux-search-type-field').val();

        abortActive($results);

        showLoading($results);

        var xhr = $.ajax({
            url: faranuxSearch.ajaxurl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'faranux_strict_search_ajax',
                nonce: faranuxSearch.nonce,
                query: query,
                search_type: searchType // Send the scope to the server
            },
            success: function (response) {
                if (response && response.success && response.data) {
                    $results.html(response.data).addClass('is-visible');
                } else {
                    hideResults($results);
                }
            },
            error: function (xhr) {
                if ('abort' === xhr.statusText) { return; }
                hideResults($results);
            },
            complete: function () {
                if ($results.data('faranuxXhr') === xhr) {
                    $results.removeData('faranuxXhr');
                }
            }
        });

        $results.data('faranuxXhr', xhr);
    }

    // -------------------------------------------------------------------------
    // Event bindings
    // -------------------------------------------------------------------------

    $(document).on('input', '.faranux-strict-search-input', function () {
        var $input = $(this);
        var $results = $input.closest('.faranux-strict-search-form')
            .find('.faranux-strict-search-results');
        var query = $.trim($input.val());

        // Each input keeps its own debounce timer (instead of one shared timer
        // for every widget on the page). With a shared timer, typing into one
        // search box while another box's debounce was still pending silently
        // cancelled the other box's search — its keystrokes never fired a
        // request at all.
        clearTimeout($input.data('faranuxTimer'));

        if (query.length < MIN_CHARS) {
            abortActive($results);
            hideResults($results);
            return;
        }

        var timer = setTimeout(function () {
            doSearch($input, $results, query);
        }, DEBOUNCE_MS);
        $input.data('faranuxTimer', timer);
    });

    // Close the dropdown when clicking outside.
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.faranux-strict-search-form').length) {
            $('.faranux-strict-search-results').each(function () {
                var $results = $(this);
                // Abort any in-flight request too — otherwise a response that
                // arrives after the user dismissed the dropdown would silently
                // reopen it with stale results.
                abortActive($results);
                hideResults($results);
            });
        }
    });

    // Close on Escape key.
    $(document).on('keydown', '.faranux-strict-search-input', function (e) {
        if (27 === e.which) { // Escape
            var $results = $(this).closest('.faranux-strict-search-form')
                .find('.faranux-strict-search-results');
            abortActive($results);
            hideResults($results);
        }
    });

})(jQuery);