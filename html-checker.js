jQuery(document).ready(function($) {
    $('#html-checker-form').on('submit', function(e) {
        e.preventDefault();
        var url = $('#url').val();
        $('#html-checker-result').html('Checking...');

        $.ajax({
            url: htmlCheckerAjax.ajax_url,
            type: 'post',
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

    $('#negative-margin-checker-form').on('submit', function(e) {
        e.preventDefault();
        console.log("Negative margin form submitted");
        var url = $('#negative-margin-url').val();
        console.log("URL to check:", url);
        $('#negative-margin-checker-result').html('Checking...');

        $.ajax({
            url: htmlCheckerAjax.ajax_url,
            type: 'post',
            data: {
                action: 'negative_margin_checker_check_css',
                url: url
            },
            success: function(response) {
                console.log("AJAX response received:", response);
                $('#negative-margin-checker-result').html(response);
            },
            error: function(xhr, status, error) {
                console.error("AJAX error:", status, error);
                $('#negative-margin-checker-result').html('An error occurred.');
            }
        });
    });
});