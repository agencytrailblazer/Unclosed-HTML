<?php
/*
Plugin Name: Unclosed HTML Checker
Description: A plugin to check for unclosed HTML tags on a given URL.
Version: 0.1
Author: Lee Matthew Jackson
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Enqueue the script for AJAX
function html_checker_enqueue_script() {
    wp_enqueue_script('html-checker', plugin_dir_url(__FILE__) . 'html-checker.js', array('jquery'), null, true);
    wp_localize_script('html-checker', 'htmlCheckerAjax', array('ajax_url' => admin_url('admin-ajax.php')));
}
add_action('wp_enqueue_scripts', 'html_checker_enqueue_script');

// Shortcode to display the form
function html_checker_form_shortcode() {
    ob_start();
    ?>
    <form id="html-checker-form">
        <input type="url" name="url" id="url" placeholder="Enter URL" required>
        <button type="submit">Check HTML</button>
    </form>
    <div id="html-checker-result"></div>
    <?php
    return ob_get_clean();
}
add_shortcode('html_checker_form', 'html_checker_form_shortcode');

// Function to find unclosed HTML tags
function find_unclosed_tags($html) {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    
    $unclosed_tags = [];

    foreach ($errors as $error) {
        if (strpos($error->message, 'Opening and ending tag mismatch') !== false) {
            $unclosed_tags[] = [
                'message' => trim($error->message),
                'line' => $error->line
            ];
        }
    }

    return $unclosed_tags;
}

// Function to get the snippet of HTML content around the unclosed tag
function get_snippet_from_html($html, $line_number, $unclosed_tag) {
    $lines = explode("\n", $html);
    $line_number = max(0, min(count($lines) - 1, $line_number - 1));
    if (!isset($lines[$line_number])) {
        return '';
    }
    $line_content = trim($lines[$line_number]);
    $snippet_length = 100;
    $tag_position = strpos($line_content, "<$unclosed_tag");
    if ($tag_position !== false) {
        return substr($line_content, $tag_position, $snippet_length) . '...';
    }
    return substr($line_content, 0, $snippet_length) . '...';
}

// AJAX handler to check HTML
function html_checker_check_html() {
    if (isset($_POST['url'])) {
        $url = esc_url_raw($_POST['url']);
        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            echo 'Failed to fetch URL';
            wp_die();
        }

        $body = wp_remote_retrieve_body($response);

        // Check for unclosed tags
        $unclosed_tags = find_unclosed_tags($body);
        if (empty($unclosed_tags)) {
            echo 'No unclosed tags found.';
        } else {
            $count = 1;
            $reported_tags = [];
            foreach ($unclosed_tags as $unclosed_tag) {
                // Extract the tag names from the error message
                preg_match('/Opening and ending tag mismatch: (\w+) and (\w+)/', $unclosed_tag['message'], $matches);
                if (isset($matches[2]) && !in_array($matches[2], $reported_tags)) {
                    $snippet = get_snippet_from_html($body, $unclosed_tag['line'], $matches[2]);
                    echo "Issue $count: Unclosed \"&lt;{$matches[2]}&gt;\". See - $snippet<br>";
                    $reported_tags[] = $matches[2];
                    $count++;
                }
            }
        }
    }
    wp_die();
}
add_action('wp_ajax_html_checker_check_html', 'html_checker_check_html');
add_action('wp_ajax_nopriv_html_checker_check_html', 'html_checker_check_html');
