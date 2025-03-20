<?php
/*
Plugin Name: BAT Auction Scraper
Description: Scrapes auction data from Bring a Trailer and displays it in a table.
Version: 3.0
Author: Nahom
Author URI: https://nahoms.com
*/

// Include the functions file
require_once plugin_dir_path(__FILE__) . 'bat-scraper-functions.php';

function bat_enqueue_scripts() {
    wp_enqueue_style('bat-styles', plugins_url('bat-style.css', __FILE__));
    wp_enqueue_script('bat-script', plugins_url('bat-script.js', __FILE__), array('jquery'), null, true);
    wp_localize_script('bat-script', 'batAjax', array('ajaxurl' => admin_url('admin-ajax.php')));
}
add_action('wp_enqueue_scripts', 'bat_enqueue_scripts');

function bat_auction_table() {
    ob_start();
    ?>
    <table id="bat-auction-table">
        <thead>
            <tr>
                <th>Car Image</th>
                <th>Name</th>
                <th>Bid</th>
                <th>Time Left</th>
                <th>Location</th>
                <th>Seller</th>
                <th>Make</th>
                <th>Model</th>
                <th>Era</th>
                <th>Origin</th>
                <th>Category</th>
                <th>Dealer Type</th>
                <th>View</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
    <script>
        jQuery(document).ready(function($) {
            console.log('batAjax:', batAjax); // Debug AJAX URL
            $.ajax({
                url: batAjax.ajaxurl,
                type: 'POST',
                data: { action: 'bat_fetch_auctions' },
                beforeSend: function() {
                    $('#bat-auction-table tbody').html('<tr><td colspan="13">Loading auctions...</td></tr>');
                },
                success: function(response) {
                    console.log('Response:', response); // Debug response
                    if (!response.success || response.data.error) {
                        $('#bat-auction-table tbody').html('<tr><td colspan="13">' + (response.data.error || 'Unknown error occurred') + '</td></tr>');
                        return;
                    }
                    var html = '';
                    response.data.forEach(function(auction) {
                        html += '<tr>';
                        html += '<td><img src="' + auction.image + '" width="100"></td>';
                        html += '<td>' + auction.name + '</td>';
                        html += '<td>' + auction.bid + '</td>';
                        html += '<td class="countdown" data-endtime="' + auction.time_end + '">' + auction.time_left + '</td>';
                        html += '<td>' + auction.country + '</td>';
                        html += '<td><a href="' + auction.seller_link + '" target="_blank">' + auction.seller_name + '</a></td>';
                        html += '<td>' + auction.make + '</td>';
                        html += '<td>' + auction.model + '</td>';
                        html += '<td>' + auction.era + '</td>';
                        html += '<td>' + auction.origin + '</td>';
                        html += '<td>' + auction.category + '</td>';
                        html += '<td>' + auction.dealer_type + '</td>';
                        html += '<td><a href="' + auction.link + '" target="_blank">View</a></td>';
                        html += '</tr>';
                    });
                    $('#bat-auction-table tbody').html(html);
                },
                error: function(xhr, status, error) {
                    console.log('AJAX Error:', status, error); // Debug AJAX failure
                    $('#bat-auction-table tbody').html('<tr><td colspan="13">Failed to load auctions: ' + error + '</td></tr>');
                }
            });
        });

        // Function to update the countdown dynamically
function updateCountdowns() {
    jQuery('.countdown').each(function() {
        var endTime = parseInt(jQuery(this).attr('data-endtime'));
        var currentTime = Math.floor(Date.now() / 1000);
        var timeLeft = endTime - currentTime;

        if (timeLeft > 0) {
            var hours = Math.floor(timeLeft / 3600);
            var minutes = Math.floor((timeLeft % 3600) / 60);
            jQuery(this).text(hours.toString().padStart(2, '0') + ":" + 
                              minutes.toString().padStart(2, '0'));
        } else {
            jQuery(this).text("Auction Ended");
        }
    });
}

// Update countdown every 30 seconds
setInterval(updateCountdowns, 30000);

// Run the function immediately when the page loads
updateCountdowns();

    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('bat_auctions', 'bat_auction_table');