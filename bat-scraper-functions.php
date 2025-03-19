<?php

// Fetch initial auction data (Fast)
function bat_fetch_auctions() {
    $rss_url = "https://bringatrailer.com/auctions/feed/";
    $rss = simplexml_load_file($rss_url, 'SimpleXMLElement', LIBXML_NOCDATA);

    $auctions = [];

    foreach ($rss->channel->item as $item) {
        $title = (string) $item->title;
        $link = (string) $item->link;
        $content = (string) $item->children('content', true)->encoded;

        // Extract first image
        $imageURL = extractFirstImageFromContent($content);
        
        // Extract country details
        $countryData = extractCountryFromContent($content);
        
        // Extract additional data like Year & Transmission
        $carDetails = extractCarDetailsFromTitle($title);

        $auctions[] = [
            'image' => $imageURL,
            'name' => $title,
            'year' => $carDetails['year'],
            'transmission' => $carDetails['transmission'],
            'link' => $link,
            'flag' => $countryData['flag'],
            'country' => $countryData['country']
        ];
    }

    echo json_encode($auctions);
    wp_die();
}

// Fetch auction details (Slow, done separately via AJAX)
function bat_fetch_auction_details() {
    $url = isset($_GET['url']) ? esc_url($_GET['url']) : '';
    if (!$url) {
        echo json_encode(['bid' => 'N/A', 'time_left' => 'N/A', 'time_until' => 0]);
        wp_die();
    }

    $details = getAuctionDetails($url);
    echo json_encode($details);
    wp_die();
}

// Extract first image from RSS
function extractFirstImageFromContent($content) {
    preg_match('/<img[^>]+src=["\'](.*?)["\']/', $content, $matches);
    return isset($matches[1]) ? $matches[1] : 'https://via.placeholder.com/150?text=No+Image';
}

// Extract country details
function extractCountryFromContent($content) {
    preg_match('/<img[^>]*class=["\']countries-flags["\'][^>]*src=["\'](.*?)["\']/', $content, $flagMatch);
    preg_match('/<span[^>]*class=["\']show-country-name["\'][^>]*>(.*?)<\/span>/', $content, $countryMatch);

    return [
        'flag' => $flagMatch[1] ?? 'https://via.placeholder.com/20x15?text=?',
        'country' => $countryMatch[1] ?? 'Unknown'
    ];
}

// Extract car year & transmission from title
function extractCarDetailsFromTitle($title) {
    preg_match('/\b(19|20)\d{2}\b/', $title, $yearMatch);
    $year = isset($yearMatch[0]) ? $yearMatch[0] : 'Unknown';

    $transmission = (strpos(strtolower($title), 'manual') !== false) ? 'Manual' : 'Automatic';

    return [
        'year' => $year,
        'transmission' => $transmission
    ];
}

// Fetch auction time & bid price
function getAuctionDetails($url) {
    $html = fetchHTML($url);
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $timeNode = $xpath->query("//span[contains(@class, 'listing-available-countdown')]");
    $bidNode = $xpath->query("//span[contains(@data-listing-currently, '')]//strong[contains(@class, 'info-value')]");

    $timeText = 'Loading...';
    $timeUntil = 0;
    $bidPrice = 'N/A';

    if ($timeNode->length > 0) {
        $timeText = trim($timeNode->item(0)->nodeValue);
    }

    if ($bidNode->length > 0) {
        $bidPrice = trim($bidNode->item(0)->nodeValue);
    }

    return ['bid' => $bidPrice, 'time_left' => $timeText, 'time_until' => $timeUntil];
}

add_action('wp_ajax_bat_fetch_auctions', 'bat_fetch_auctions');
add_action('wp_ajax_nopriv_bat_fetch_auctions', 'bat_fetch_auctions');
add_action('wp_ajax_bat_fetch_auction_details', 'bat_fetch_auction_details');
add_action('wp_ajax_nopriv_bat_fetch_auction_details', 'bat_fetch_auction_details');
