<?php
if (!defined('ABSPATH')) exit; // Exit if accessed directly

function fetchHTML($url) {
    $response = wp_remote_get($url, array('timeout' => 10));
    if (is_wp_error($response)) {
        error_log("ERROR: Failed to fetch URL - " . $url . " - " . $response->get_error_message());
        return false;
    }
    return wp_remote_retrieve_body($response);
}

function extractFirstImageFromContent($content) {
    if (preg_match('/<img.*?src=["\'](.*?)["\']/', $content, $matches)) {
        return $matches[1];
    }
    return 'https://via.placeholder.com/150?text=Image+Not+Found';
}

function getAuctionDetailsFromPage($url) {
    $html = fetchHTML($url);

    if (!$html) {
        return [
            'bid' => 'N/A',
            'time_left' => 'N/A',
            'country' => 'Unknown',
            'seller_name' => 'Unknown',
            'seller_link' => '#',
            'make' => 'N/A',
            'model' => 'N/A',
            'era' => 'N/A',
            'origin' => 'N/A',
            'category' => 'N/A',
            'dealer_type' => 'N/A'
        ];
    }

    preg_match('/<strong class="info-value">(.*?)<\/strong>/', $html, $bidMatch);
    $bid = $bidMatch[1] ?? 'N/A';

    preg_match('/data-until=["\'](\d+)["\']/', $html, $timeUntilMatch);
    $time_until = $timeUntilMatch[1] ?? null;

    if ($time_until) {
        $current_time = time();
        $time_remaining = $time_until - $current_time;

        if ($time_remaining > 0) {
            $hours = floor($time_remaining / 3600);
            $minutes = floor(($time_remaining % 3600) / 60);
            $seconds = $time_remaining % 60;
            $time_left = sprintf("%02d:%02d:%02d", $hours, $minutes, $seconds);
        } else {
            $time_left = "Auction Ended";
        }
    } else {
        $time_left = "N/A";
    }

    preg_match('/<span class="show-country-name">(.*?)<\/span>/', $html, $countryMatch);
    $country = $countryMatch[1] ?? 'Unknown';

    preg_match('/<a href="(https:\/\/bringatrailer.com\/member\/.*?)"[^>]*>(.*?)<\/a>/', $html, $sellerMatch);
    $seller_name = $sellerMatch[2] ?? 'Unknown';
    $seller_link = $sellerMatch[1] ?? '#';

    $details = ['make' => 'N/A', 'model' => 'N/A', 'era' => 'N/A', 'origin' => 'N/A', 'category' => 'N/A'];
    preg_match_all('/<button class="group-title"><strong class="group-title-label">(.*?)<\/strong>(.*?)<\/button>/', $html, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $label = trim($match[1]);
        $value = trim(strip_tags($match[2]));
        if ($label === 'Make') $details['make'] = $value;
        if ($label === 'Model') $details['model'] = $value;
        if ($label === 'Era') $details['era'] = $value;
        if ($label === 'Origin') $details['origin'] = $value;
        if ($label === 'Category') $details['category'] = $value;
    }

    preg_match('/<div class="item additional"><strong>Private Party or Dealer<\/strong>: (.*?)<\/div>/', $html, $dealerMatch);
    $dealer_type = trim($dealerMatch[1] ?? 'N/A');

    return [
        'bid' => $bid,
        'time_left' => $time_left,
        'time_end' => $time_until ?? (time() + (isset($hours) ? $hours * 3600 : 0) + (isset($minutes) ? $minutes * 60 : 0)),
        'country' => $country,
        'seller_name' => $seller_name,
        'seller_link' => $seller_link,
        'make' => $details['make'],
        'model' => $details['model'],
        'era' => $details['era'],
        'origin' => $details['origin'],
        'category' => $details['category'],
        'dealer_type' => $dealer_type
    ];
}

function bat_fetch_auctions() {
    if (!defined('DOING_AJAX') || !DOING_AJAX) {
        wp_send_json_error(["error" => "Invalid request."]);
        wp_die();
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'bat_auctions';

    // Fetch new auctions from RSS
    $rss_url = "https://bringatrailer.com/feed/";
    $rss_data = fetchHTML($rss_url);

    if ($rss_data) {
        libxml_use_internal_errors(true);
        $rss = simplexml_load_string($rss_data, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();

        if ($rss) {
            foreach ($rss->channel->item as $item) {
                $title = (string) $item->title;
                $link = (string) $item->link;
                $content = (string) $item->children('content', true)->encoded;
                $imageURL = extractFirstImageFromContent($content);

                // Check if auction exists
                $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE link = %s", $link));
                if (!$exists) {
                    $result = $wpdb->insert(
                        $table_name,
                        [
                            'link' => $link,
                            'name' => $title,
                            'image' => $imageURL,
                            'bid' => 'Loading...',
                            'time_left' => 'Loading...',
                            'time_end' => 0,
                            'country' => 'Loading...',
                            'seller_name' => 'Loading...',
                            'seller_link' => '#',
                            'make' => 'Loading...',
                            'model' => 'Loading...',
                            'era' => 'Loading...',
                            'origin' => 'Loading...',
                            'category' => 'Loading...',
                            'dealer_type' => 'Loading...'
                        ],
                        ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
                    );
                    if ($result === false) {
                        error_log("DEBUG: Failed to insert auction $link - " . $wpdb->last_error);
                    }
                } else {
                    error_log("DEBUG: Skipping duplicate auction $link");
                }
            }
        } else {
            error_log("ERROR: Failed to parse RSS feed.");
        }
    } else {
        error_log("ERROR: Failed to fetch RSS feed.");
    }

    // Return all stored auctions
    $auctions = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);
    if (!$auctions) {
        wp_send_json_error(["error" => "No auctions found in database."]);
    } else {
        wp_send_json_success($auctions);
    }
    wp_die();
}

function bat_fetch_auction_details() {
    if (!defined('DOING_AJAX') || !DOING_AJAX || !isset($_POST['url'])) {
        wp_send_json_error(["error" => "Invalid request."]);
        wp_die();
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'bat_auctions';
    $url = sanitize_url($_POST['url']);
    $details = getAuctionDetailsFromPage($url);

    // Update the database with fetched details
    $wpdb->update(
        $table_name,
        $details,
        ['link' => $url],
        ['%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
        ['%s']
    );

    $details['bidValue'] = floatval(preg_replace('/[^0-9.]/', '', $details['bid']));
    wp_send_json_success($details);
    wp_die();
}

add_action('wp_ajax_bat_fetch_auctions', 'bat_fetch_auctions');
add_action('wp_ajax_nopriv_bat_fetch_auctions', 'bat_fetch_auctions');
add_action('wp_ajax_bat_fetch_auction_details', 'bat_fetch_auction_details');
add_action('wp_ajax_nopriv_bat_fetch_auction_details', 'bat_fetch_auction_details');            