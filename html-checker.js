jQuery(document).ready(function($) {
    $('#html-checker-form').submit(function(e) {
        e.preventDefault();
        var url = $('#url').val();
        $('#html-checker-result').html('Checking...');
        
        $.ajax({
            url: htmlCssCheckerAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'html_checker_check_html',
                url: url
            },
            success: function(response) {
                $('#html-checker-result').html(response);
            },
            error: function() {
                $('#html-checker-result').html('An error occurred.');
            }
        });
    });

    $('#negative-margin-checker-form').submit(function(e) {
        e.preventDefault();
        var url = $('#negative-margin-url').val();
        $('#negative-margin-checker-result').html('Checking...');
        
        $.ajax({
            url: htmlCssCheckerAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'negative_margin_checker_check_css',
                url: url
            },
            success: function(response) {
                $('#negative-margin-checker-result').html(response);
            },
            error: function() {
                $('#negative-margin-checker-result').html('An error occurred.');
            }
        });
    });
});