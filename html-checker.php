<?php
/*
Plugin Name: HTML and CSS Checker
Description: A plugin to check for unclosed HTML tags and negative margins on a given URL.
Version: 0.3
Author: Lee Matthew Jackson
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Enqueue the script for AJAX
function html_css_checker_enqueue_script() {
    wp_enqueue_script('html-css-checker', plugin_dir_url(__FILE__) . 'html-css-checker.js', array('jquery'), null, true);
    wp_localize_script('html-css-checker', 'htmlCssCheckerAjax', array('ajax_url' => admin_url('admin-ajax.php')));
}
add_action('wp_enqueue_scripts', 'html_css_checker_enqueue_script');

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

// Function to fetch and parse CSS
function fetch_and_parse_css($url) {
    $css_rules = array();
    
    // Fetch the HTML
    $response = wp_remote_get($url);
    if (is_wp_error($response)) {
        return false;
    }
    $html = wp_remote_retrieve_body($response);
    
    // Create a new DOMDocument
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    
    // Find all <link> tags with rel="stylesheet"
    $links = $dom->getElementsByTagName('link');
    foreach ($links as $link) {
        if ($link->getAttribute('rel') == 'stylesheet') {
            $css_url = $link->getAttribute('href');
            if (strpos($css_url, 'http') !== 0) {
                $css_url = $url . $css_url;
            }
            $css_content = wp_remote_retrieve_body(wp_remote_get($css_url));
            $css_rules = array_merge($css_rules, parse_css($css_content));
        }
    }
    
    // Find all <style> tags
    $styles = $dom->getElementsByTagName('style');
    foreach ($styles as $style) {
        $css_rules = array_merge($css_rules, parse_css($style->nodeValue));
    }
    
    return $css_rules;
}

function parse_css($css_content) {
    $css_rules = array();
    preg_match_all('/([^{]+){([^}]+)}/s', $css_content, $matches);
    
    foreach ($matches[0] as $i => $match) {
        $selector = trim($matches[1][$i]);
        $rules = trim($matches[2][$i]);
        if (preg_match('/margin(-[a-z]+)?:\s*-[0-9]+[a-z%]*/i', $rules)) {
            $css_rules[] = array(
                'selector' => $selector,
                'rule' => $rules
            );
        }
    }
    
    return $css_rules;
}

// AJAX handler to check for negative margins
function negative_margin_checker_check_css() {
    if (isset($_POST['url'])) {
        $url = esc_url_raw($_POST['url']);
        $css_rules = fetch_and_parse_css($url);
        
        if (empty($css_rules)) {
            echo 'No negative margins found.';
        } else {
            $count = 1;
            foreach ($css_rules as $rule) {
                echo "<strong>Issue $count:</strong> Negative margin found in selector \"{$rule['selector']}\"<br>";
                echo "CSS Rule: <pre>{$rule['rule']}</pre><br>";
                $count++;
            }
        }
    }
    wp_die();
}
add_action('wp_ajax_negative_margin_checker_check_css', 'negative_margin_checker_check_css');
add_action('wp_ajax_nopriv_negative_margin_checker_check_css', 'negative_margin_checker_check_css');