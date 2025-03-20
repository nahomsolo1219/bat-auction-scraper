<?php
if (!defined('ABSPATH')) exit; // Exit if accessed directly

function fetchHTML($url) {
    $response = wp_remote_get($url, array('timeout' => 15));
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
    error_log("DEBUG: Fetching auction details from $url");
    $html = fetchHTML($url);

    if (!$html) {
        error_log("ERROR: Failed to fetch auction page.");
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

    // Extracting bid price
    preg_match('/<strong class="info-value">(.*?)<\/strong>/', $html, $bidMatch);
    $bid = $bidMatch[1] ?? 'N/A';

    // Extracting time left
    // Extract auction end timestamp
preg_match('/data-until=["\'](\d+)["\']/', $html, $timeUntilMatch);
$time_until = $timeUntilMatch[1] ?? null;

if ($time_until) {
    $current_time = time(); // Get current Unix timestamp
    $time_remaining = $time_until - $current_time; // Calculate remaining time in seconds

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


    // Extracting country
    preg_match('/<span class="show-country-name">(.*?)<\/span>/', $html, $countryMatch);
    $country = $countryMatch[1] ?? 'Unknown';

    // Extracting seller details
    preg_match('/<a href="(https:\/\/bringatrailer.com\/member\/.*?)"[^>]*>(.*?)<\/a>/', $html, $sellerMatch);
    $seller_name = $sellerMatch[2] ?? 'Unknown';
    $seller_link = $sellerMatch[1] ?? '#';

    // Extracting Make, Model, Era, Origin, Category
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

    // Extracting Dealer Type
    preg_match('/<div class="item additional"><strong>Private Party or Dealer<\/strong>: (.*?)<\/div>/', $html, $dealerMatch);
    $dealer_type = trim($dealerMatch[1] ?? 'N/A');

    return [
        'bid' => $bid,
        'time_left' => $time_left,
        'time_end' => time() + ($hours * 3600) + ($minutes * 60), // Pass auction end time,
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
    error_log("DEBUG: AJAX function triggered.");

    if (!defined('DOING_AJAX') || !DOING_AJAX) {
        error_log("ERROR: Not a valid AJAX request.");
        wp_send_json_error(["error" => "Invalid request."]);
        wp_die();
    }

    $rss_url = "https://bringatrailer.com/feed/";
    $rss_data = fetchHTML($rss_url);

    if (!$rss_data) {
        error_log("ERROR: Failed to fetch RSS data.");
        wp_send_json_error(["error" => "Failed to load auctions"]);
        wp_die();
    }

    error_log("DEBUG: Successfully fetched RSS feed.");

    libxml_use_internal_errors(true);
    $rss = simplexml_load_string($rss_data, 'SimpleXMLElement', LIBXML_NOCDATA);
    libxml_clear_errors();

    if (!$rss) {
        error_log("ERROR: Failed to parse RSS XML.");
        wp_send_json_error(["error" => "Failed to parse auction data"]);
        wp_die();
    }

    $auctions = [];
    foreach ($rss->channel->item as $item) {
        $title = (string) $item->title;
        $link = (string) $item->link;
        $content = (string) $item->children('content', true)->encoded;

        error_log("DEBUG: Processing auction - $title");

        $imageURL = extractFirstImageFromContent($content);
        error_log("DEBUG: Extracted Image - $imageURL");

        $details = getAuctionDetailsFromPage($link);
        error_log("DEBUG: Extracted auction details from page - $title");

        $auctions[] = array_merge([
            'image' => $imageURL,
            'name' => $title,
            'link' => $link
        ], $details);
    }

    error_log("DEBUG: Successfully processed all auctions.");
    wp_send_json_success($auctions);
    wp_die();
}

add_action('wp_ajax_bat_fetch_auctions', 'bat_fetch_auctions');
add_action('wp_ajax_nopriv_bat_fetch_auctions', 'bat_fetch_auctions');

// Optional: Manual testing endpoint
if (isset($_GET['test_bat_ajax'])) {
    bat_fetch_auctions();
}