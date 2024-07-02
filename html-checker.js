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
});
