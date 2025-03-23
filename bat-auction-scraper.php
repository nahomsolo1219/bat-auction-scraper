<?php
/*
Plugin Name: BAT Auction Scraper
Description: Scrapes auction data from Bring a Trailer and displays it in a table.
Version: 3.2
Author: Nahom
Author URI: https://nahoms.com
*/

require_once plugin_dir_path(__FILE__) . 'bat-scraper-functions.php';

// Create table on plugin activation
register_activation_hook(__FILE__, 'bat_create_auction_table');

function bat_create_auction_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'bat_auctions';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        link VARCHAR(255) NOT NULL,
        name TEXT NOT NULL,
        image TEXT NOT NULL,
        bid VARCHAR(50) DEFAULT 'N/A',
        time_left VARCHAR(50) DEFAULT 'N/A',
        time_end BIGINT(20) DEFAULT 0,
        country VARCHAR(100) DEFAULT 'Unknown',
        seller_name VARCHAR(100) DEFAULT 'Unknown',
        seller_link VARCHAR(255) DEFAULT '#',
        make VARCHAR(100) DEFAULT 'N/A',
        model VARCHAR(100) DEFAULT 'N/A',
        era VARCHAR(100) DEFAULT 'N/A',
        origin VARCHAR(100) DEFAULT 'N/A',
        category VARCHAR(100) DEFAULT 'N/A',
        dealer_type VARCHAR(100) DEFAULT 'N/A',
        pub_date DATETIME DEFAULT NULL, -- New column for publication date
        PRIMARY KEY (id),
        UNIQUE KEY link (link)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

function bat_enqueue_scripts() {
    wp_enqueue_style('bat-styles', plugins_url('bat-style.css', __FILE__));
    wp_enqueue_script('bat-script', plugins_url('bat-script.js', __FILE__), array('jquery'), null, true);
    wp_localize_script('bat-script', 'batAjax', array('ajaxurl' => admin_url('admin-ajax.php')));
}
add_action('wp_enqueue_scripts', 'bat_enqueue_scripts');

function bat_auction_table() {
    ob_start();
    ?>
    <div id="bat-auction-wrapper">
        <div id="bat-filters" style="margin-bottom: 20px;">
            <input type="text" id="bat-search" placeholder="Search auctions...">
            <select id="bat-country-filter">
                <option value="">All Countries</option>
            </select>
            <select id="bat-make-filter">
                <option value="">All Makes</option>
            </select>
            <select id="bat-dealer-filter">
                <option value="">All Dealer Types</option>
                <option value="Private Party">Private Party</option>
                <option value="Dealer">Dealer</option>
            </select>
            <div style="display: inline-block; margin-left: 10px;">
                Bid Range: 
                <input type="number" id="bat-bid-min" placeholder="Min" style="width: 80px;"> - 
                <input type="number" id="bat-bid-max" placeholder="Max" style="width: 80px;">
            </div>
        </div>
        <table id="bat-auction-table">
            <thead>
                <tr>
                    <th data-sort="image">Car Image</th>
                    <th data-sort="string">Name</th>
                    <th data-sort="number">Bid</th>
                    <th data-sort="time">Time Left</th>
                    <th data-sort="string">Location</th>
                    <th data-sort="string">Seller</th>
                    <th data-sort="string">Make</th>
                    <th data-sort="string">Model</th>
                    <th data-sort="string">Era</th>
                    <th data-sort="string">Origin</th>
                    <th data-sort="string">Category</th>
                    <th data-sort="string">Dealer Type</th>
                    <th data-sort="string">View</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
        <div id="bat-pagination" style="margin-top: 20px; text-align: center;"></div>
    </div>
    <script>
        jQuery(document).ready(function($) {
            let auctionsData = [];
            let filteredData = [];
            let currentPage = 1;
            const rowsPerPage = 10;
            let countries = new Set();
            let makes = new Set();

            $.ajax({
                url: batAjax.ajaxurl,
                type: 'POST',
                data: { action: 'bat_fetch_auctions' },
                beforeSend: function() {
                    $('#bat-auction-table tbody').html('<tr><td colspan="13">Loading auctions...</td></tr>');
                },
                success: function(response) {
                    if (!response.success || response.data.error) {
                        $('#bat-auction-table tbody').html('<tr><td colspan="13">' + (response.data.error || 'Unknown error occurred') + '</td></tr>');
                        return;
                    }
                    auctionsData = response.data.map(auction => ({
                        ...auction,
                        bidValue: auction.bid === 'Loading...' ? 0 : parseFloat(auction.bid.replace(/[^0-9.]/g, '')) || 0
                    }));
                    filteredData = [...auctionsData];
                    renderTable();
                    loadAuctionDetails();
                },
                error: function(xhr, status, error) {
                    $('#bat-auction-table tbody').html('<tr><td colspan="13">Failed to load auctions: ' + error + '</td></tr>');
                }
            });

            function loadAuctionDetails() {
                auctionsData.forEach((auction, index) => {
                    if (auction.bid === 'Loading...') {
                        $.ajax({
                            url: batAjax.ajaxurl,
                            type: 'POST',
                            data: { action: 'bat_fetch_auction_details', url: auction.link },
                            success: function(response) {
                                if (response.success) {
                                    auctionsData[index] = { ...auction, ...response.data };
                                    if (filteredData.some(item => item.link === auction.link)) {
                                        filteredData[filteredData.findIndex(item => item.link === auction.link)] = auctionsData[index];
                                    }
                                    countries.add(response.data.country);
                                    makes.add(response.data.make);
                                    populateFilters();
                                    renderTable();
                                }
                            },
                            error: function() {
                                console.log('Failed to load details for ' + auction.link);
                            }
                        });
                    } else {
                        countries.add(auction.country);
                        makes.add(auction.make);
                    }
                });
                populateFilters();
            }

            function populateFilters() {
                $('#bat-country-filter').html('<option value="">All Countries</option>');
                $('#bat-make-filter').html('<option value="">All Makes</option>');
                countries.forEach(country => {
                    if (country && country !== 'Unknown') {
                        $('#bat-country-filter').append(`<option value="${country}">${country}</option>`);
                    }
                });
                makes.forEach(make => {
                    if (make && make !== 'N/A') {
                        $('#bat-make-filter').append(`<option value="${make}">${make}</option>`);
                    }
                });
            }

            function renderTable() {
                const start = (currentPage - 1) * rowsPerPage;
                const end = start + rowsPerPage;
                const paginatedData = filteredData.slice(start, end);

                var html = '';
                paginatedData.forEach(function(auction) {
                    html += '<tr>';
                    html += '<td><img src="' + auction.image + '" width="80"></td>';
                    html += '<td>' + auction.name + '</td>';
                    html += '<td' + (auction.bid === 'Loading...' ? ' class="loading"' : '') + '>' + auction.bid + '</td>';
                    html += '<td class="countdown" data-endtime="' + auction.time_end + '"' + (auction.time_left === 'Loading...' ? ' class="loading"' : '') + '>' + auction.time_left + '</td>';
                    html += '<td' + (auction.country === 'Loading...' ? ' class="loading"' : '') + '>' + auction.country + '</td>';
                    html += '<td><a href="' + auction.seller_link + '" target="_blank"' + (auction.seller_name === 'Loading...' ? ' class="loading"' : '') + '>' + auction.seller_name + '</a></td>';
                    html += '<td' + (auction.make === 'Loading...' ? ' class="loading"' : '') + '>' + auction.make + '</td>';
                    html += '<td' + (auction.model === 'Loading...' ? ' class="loading"' : '') + '>' + auction.model + '</td>';
                    html += '<td' + (auction.era === 'Loading...' ? ' class="loading"' : '') + '>' + auction.era + '</td>';
                    html += '<td' + (auction.origin === 'Loading...' ? ' class="loading"' : '') + '>' + auction.origin + '</td>';
                    html += '<td' + (auction.category === 'Loading...' ? ' class="loading"' : '') + '>' + auction.category + '</td>';
                    html += '<td' + (auction.dealer_type === 'Loading...' ? ' class="loading"' : '') + '>' + auction.dealer_type + '</td>';
                    html += '<td><a href="' + auction.link + '" target="_blank">View</a></td>';
                    html += '</tr>';
                });
                $('#bat-auction-table tbody').html(html);
                updateCountdowns();
                updatePagination();
            }

            function updatePagination() {
                const totalPages = Math.ceil(filteredData.length / rowsPerPage);
                const paginationDiv = $('#bat-pagination');
                paginationDiv.empty();

                // Previous button (left arrow)
                const prevButton = $('<button>&lt;</button>')
                    .prop('disabled', currentPage === 1)
                    .css({
                        margin: '0 5px',
                        padding: '5px 10px',
                        border: '1px solid #ccc',
                        background: currentPage === 1 ? '#f0f0f0' : '#fff',
                        cursor: currentPage === 1 ? 'not-allowed' : 'pointer'
                    })
                    .on('click', function() {
                        if (currentPage > 1) {
                            currentPage--;
                            renderTable();
                        }
                    });
                paginationDiv.append(prevButton);

                // Page numbers
                const maxVisiblePages = 5; // Maximum number of page numbers to show at once
                let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
                let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);

                // Adjust startPage if we're near the end
                if (endPage - startPage + 1 < maxVisiblePages) {
                    startPage = Math.max(1, endPage - maxVisiblePages + 1);
                }

                // Add page numbers
                for (let i = startPage; i <= endPage; i++) {
                    const pageButton = $('<button>' + i + '</button>')
                        .css({
                            margin: '0 5px',
                            padding: '5px 10px',
                            border: '1px solid #ccc',
                            background: currentPage === i ? '#007bff' : '#fff',
                            color: currentPage === i ? '#fff' : '#000',
                            cursor: 'pointer'
                        })
                        .on('click', function() {
                            currentPage = i;
                            renderTable();
                        });
                    paginationDiv.append(pageButton);
                }

                // Next button (right arrow)
                const nextButton = $('<button>&gt;</button>')
                    .prop('disabled', currentPage === totalPages || totalPages === 0)
                    .css({
                        margin: '0 5px',
                        padding: '5px 10px',
                        border: '1px solid #ccc',
                        background: (currentPage === totalPages || totalPages === 0) ? '#f0f0f0' : '#fff',
                        cursor: (currentPage === totalPages || totalPages === 0) ? 'not-allowed' : 'pointer'
                    })
                    .on('click', function() {
                        if (currentPage < totalPages) {
                            currentPage++;
                            renderTable();
                        }
                    });
                paginationDiv.append(nextButton);
            }

            function applyFilters() {
                var searchTerm = $('#bat-search').val().toLowerCase();
                var countryFilter = $('#bat-country-filter').val();
                var makeFilter = $('#bat-make-filter').val();
                var dealerFilter = $('#bat-dealer-filter').val();
                var bidMin = parseFloat($('#bat-bid-min').val()) || 0;
                var bidMax = parseFloat($('#bat-bid-max').val()) || Infinity;

                filteredData = auctionsData.filter(function(auction) {
                    return (
                        (searchTerm === '' || 
                            auction.name.toLowerCase().includes(searchTerm) ||
                            (auction.bid !== 'Loading...' && auction.bid.toLowerCase().includes(searchTerm)) ||
                            (auction.time_left !== 'Loading...' && auction.time_left.toLowerCase().includes(searchTerm)) ||
                            (auction.country !== 'Loading...' && auction.country.toLowerCase().includes(searchTerm)) ||
                            (auction.seller_name !== 'Loading...' && auction.seller_name.toLowerCase().includes(searchTerm)) ||
                            (auction.make !== 'Loading...' && auction.make.toLowerCase().includes(searchTerm)) ||
                            (auction.model !== 'Loading...' && auction.model.toLowerCase().includes(searchTerm)) ||
                            (auction.era !== 'Loading...' && auction.era.toLowerCase().includes(searchTerm)) ||
                            (auction.origin !== 'Loading...' && auction.origin.toLowerCase().includes(searchTerm)) ||
                            (auction.category !== 'Loading...' && auction.category.toLowerCase().includes(searchTerm)) ||
                            (auction.dealer_type !== 'Loading...' && auction.dealer_type.toLowerCase().includes(searchTerm))) &&
                        (!countryFilter || auction.country === countryFilter) &&
                        (!makeFilter || auction.make === makeFilter) &&
                        (!dealerFilter || auction.dealer_type === dealerFilter) &&
                        (auction.bidValue >= bidMin && auction.bidValue <= bidMax)
                    );
                });
                currentPage = 1;
                renderTable();
            }

            $('#bat-search, #bat-country-filter, #bat-make-filter, #bat-dealer-filter, #bat-bid-min, #bat-bid-max').on('input change', applyFilters);

            $('#bat-auction-table th[data-sort]').on('click', function() {
                var column = $(this).index();
                var sortType = $(this).data('sort');
                var isAsc = $(this).hasClass('asc');
                
                $('#bat-auction-table th').removeClass('asc desc');
                $(this).addClass(isAsc ? 'desc' : 'asc');

                filteredData.sort(function(a, b) {
                    var aValue, bValue;
                    switch(column) {
                        case 0: return 0; // Image
                        case 1: aValue = a.name; bValue = b.name; break;
                        case 2: aValue = a.bidValue; bValue = b.bidValue; break;
                        case 3: aValue = a.time_end || 0; bValue = b.time_end || 0; break;
                        case 4: aValue = a.country; bValue = b.country; break;
                        case 5: aValue = a.seller_name; bValue = b.seller_name; break;
                        case 6: aValue = a.make; bValue = b.make; break;
                        case 7: aValue = a.model; bValue = b.model; break;
                        case 8: aValue = a.era; bValue = b.era; break;
                        case 9: aValue = a.origin; bValue = b.origin; break;
                        case 10: aValue = a.category; bValue = b.category; break;
                        case 11: aValue = a.dealer_type; bValue = b.dealer_type; break;
                        case 12: return 0; // View
                    }

                    if (sortType === 'time' || sortType === 'number') {
                        return isAsc ? bValue - aValue : aValue - bValue;
                    }
                    return isAsc ? 
                        (bValue || '').localeCompare(aValue || '') : 
                        (aValue || '').localeCompare(bValue || '');
                });

                currentPage = 1;
                renderTable();
            });

            function updateCountdowns() {
                $('.countdown').each(function() {
                    var endTime = parseInt($(this).attr('data-endtime'));
                    if (!endTime) return;
                    var currentTime = Math.floor(Date.now() / 1000);
                    var timeLeft = endTime - currentTime;

                    if (timeLeft > 0) {
                        var hours = Math.floor(timeLeft / 3600);
                        var minutes = Math.floor((timeLeft % 3600) / 60);
                        $(this).text(hours.toString().padStart(2, '0') + ":" + 
                                   minutes.toString().padStart(2, '0'));
                    } else {
                        $(this).text("Auction Ended");
                    }
                });
            }

            setInterval(updateCountdowns, 30000);
            updateCountdowns();
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('bat_auctions', 'bat_auction_table');