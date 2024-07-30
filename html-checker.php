<?php
/*
Plugin Name: HTML and CSS Checker
Description: A plugin to check for unclosed HTML tags and negative CSS margins on a given URL.
Version: 0.3
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

// Shortcode to display the HTML checker form
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

// Shortcode to display the negative margin checker form
function negative_margin_checker_form_shortcode() {
    ob_start();
    ?>
    <form id="negative-margin-checker-form">
        <input type="url" name="url" id="negative-margin-url" placeholder="Enter URL" required>
        <button type="submit">Check Negative Margins</button>
    </form>
    <div id="negative-margin-checker-result"></div>
    <?php
    return ob_get_clean();
}
add_shortcode('negative_margin_checker_form', 'negative_margin_checker_form_shortcode');

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
    
    $context_lines = 2; // Number of lines to show before and after
    $start_line = max(0, $line_number - $context_lines);
    $end_line = min(count($lines) - 1, $line_number + $context_lines);
    
    $snippet = array();
    for ($i = $start_line; $i <= $end_line; $i++) {
        $line_content = trim($lines[$i]);
        if ($i == $line_number) {
            $tag_position = strpos($line_content, "<$unclosed_tag");
            if ($tag_position !== false) {
                $line_content = substr_replace($line_content, '<strong>', $tag_position, 0);
                $line_content .= '</strong>';
            }
        }
        $snippet[] = htmlspecialchars($line_content);
    }
    
    return $snippet;
}

// Function to find negative margins in CSS
function find_negative_margins($html, $url) {
    $negative_margins = [];

    // Check inline styles
    preg_match_all('/<[^>]+style="[^"]*margin[^"]*-[0-9]+[^"]*"/i', $html, $inline_matches);
    foreach ($inline_matches[0] as $match) {
        $negative_margins[] = ['type' => 'inline', 'content' => $match];
    }

    // Check internal stylesheets
    preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $html, $style_matches);
    foreach ($style_matches[1] as $style_content) {
        preg_match_all('/[^}]+{[^}]*margin[^}]*-[0-9]+[^}]*}/i', $style_content, $css_matches);
        foreach ($css_matches[0] as $match) {
            $negative_margins[] = ['type' => 'internal', 'content' => $match];
        }
    }

    // Check external stylesheets
    preg_match_all('/<link[^>]+rel="stylesheet"[^>]*href="([^"]+)"[^>]*>/i', $html, $link_matches);
    foreach ($link_matches[1] as $stylesheet_url) {
        $full_url = url_to_absolute($url, $stylesheet_url);
        $css_content = wp_remote_get($full_url);
        if (!is_wp_error($css_content)) {
            $css_content = wp_remote_retrieve_body($css_content);
            preg_match_all('/[^}]+{[^}]*margin[^}]*-[0-9]+[^}]*}/i', $css_content, $css_matches);
            foreach ($css_matches[0] as $match) {
                $negative_margins[] = ['type' => 'external', 'content' => $match, 'file' => $stylesheet_url];
            }
        }
    }

    return $negative_margins;
}

// Helper function to convert relative URLs to absolute
function url_to_absolute($base, $rel) {
    if (parse_url($rel, PHP_URL_SCHEME) != '') return $rel;
    if ($rel[0] == '#' || $rel[0] == '?') return $base.$rel;
    extract(parse_url($base));
    $path = preg_replace('#/[^/]*$#', '', $path);
    if ($rel[0] == '/') $path = '';
    $abs = "$host$path/$rel";
    $re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');
    for ($n = 1; $n > 0; $abs = preg_replace($re, '/', $abs, -1, $n)) {}
    return $scheme.'://'.$abs;
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
                    echo "<strong>Issue $count:</strong> Unclosed \"&lt;{$matches[2]}&gt;\". See:<br><pre>";
                    foreach ($snippet as $line) {
                        echo $line . "<br>";
                    }
                    echo "</pre><br>"; // Add an extra line break for separation between issues
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

// AJAX handler to check for negative margins
function negative_margin_checker_check_css() {
    error_log("negative_margin_checker_check_css function called");
    if (isset($_POST['url'])) {
        $url = esc_url_raw($_POST['url']);
        error_log("URL to check: " . $url);
        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            echo 'Failed to fetch URL';
            error_log("Failed to fetch URL: " . $response->get_error_message());
            wp_die();
        }
        $body = wp_remote_retrieve_body($response);
        // Check for negative margins
        $negative_margins = find_negative_margins($body, $url);
        if (empty($negative_margins)) {
            echo 'No negative margins found.';
        } else {
            $count = 1;
            foreach ($negative_margins as $margin) {
                echo "<strong>Issue $count:</strong> Negative margin found in " . $margin['type'] . " CSS:<br><pre>";
                echo htmlspecialchars($margin['content']);
                if (isset($margin['file'])) {
                    echo "<br>File: " . htmlspecialchars($margin['file']);
                }
                echo "</pre><br>";
                $count++;
            }
        }
    }
    error_log("negative_margin_checker_check_css function completed");
    wp_die();
}
add_action('wp_ajax_negative_margin_checker_check_css', 'negative_margin_checker_check_css');
add_action('wp_ajax_nopriv_negative_margin_checker_check_css', 'negative_margin_checker_check_css');