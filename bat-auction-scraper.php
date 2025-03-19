<?php
/**
 * Plugin Name: Bring A Trailer Auction Scraper
 * Description: Fetch and display live car auctions from Bring A Trailer.
 * Version: 2.9 (Optimized + Fix Country, Time + More Columns)
 * Author: Your Name
 */

if (!defined('ABSPATH')) exit;

include_once plugin_dir_path(__FILE__) . 'bat-scraper-functions.php';

function bat_display_auctions() {
    ob_start(); ?>
    <div id="bat-auctions">
        <p>Loading auctions...</p>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        fetch("<?php echo admin_url('admin-ajax.php?action=bat_fetch_auctions'); ?>")
            .then(response => response.json())
            .then(data => {
                let output = "<table border='1'><tr><th>Car Image</th><th>Name</th><th>Year</th><th>Transmission</th><th>Bid</th><th>Time Left</th><th>Location</th><th>View</th></tr>";
                data.forEach((car, index) => {
                    output += `<tr data-auction-url="${car.link}" data-index="${index}">
                        <td><img src="${car.image}" alt="${car.name}" style="width: 100px; height: auto;" onerror="this.onerror=null; this.src='https://via.placeholder.com/150?text=Image+Not+Found';"></td>
                        <td>${car.name}</td>
                        <td>${car.year}</td>
                        <td>${car.transmission}</td>
                        <td id="bid-${index}">Loading...</td>
                        <td id="countdown-${index}" data-time="" data-static-time="Loading...">Loading...</td>
                        <td>
                            <img src="${car.flag}" alt="${car.country}" style="width:20px; height:15px;"> 
                            ${car.country}
                        </td>
                        <td><a href="${car.link}" target="_blank">View</a></td>
                    </tr>`;
                });
                output += "</table>";
                document.getElementById("bat-auctions").innerHTML = output;
                setTimeout(fetchAuctionDetails, 3000);
            })
            .catch(error => {
                document.getElementById("bat-auctions").innerHTML = "<p>Failed to load auctions.</p>";
            });
    });

    function fetchAuctionDetails() {
        document.querySelectorAll("tr[data-auction-url]").forEach((row) => {
            let auctionUrl = row.getAttribute("data-auction-url");
            let index = row.getAttribute("data-index");

            fetch("<?php echo admin_url('admin-ajax.php?action=bat_fetch_auction_details'); ?>&url=" + encodeURIComponent(auctionUrl))
                .then(response => response.json())
                .then(data => {
                    document.getElementById(`bid-${index}`).innerText = data.bid;
                    document.getElementById(`countdown-${index}`).setAttribute("data-time", data.time_until);
                    document.getElementById(`countdown-${index}`).innerText = data.time_left;
                })
                .catch(error => {
                    document.getElementById(`bid-${index}`).innerText = "N/A";
                    document.getElementById(`countdown-${index}`).innerText = "Error";
                });
        });
    }
    </script>
    <?php
    return ob_get_clean();
}

add_shortcode('bat_auctions', 'bat_display_auctions');
