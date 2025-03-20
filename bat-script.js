jQuery(document).ready(function ($) {
    console.log("✅ bat-script.js loaded successfully!");

    $.ajax({
        url: batAjax.ajaxurl,
        type: "POST",
        data: { action: "bat_fetch_auctions" },
        beforeSend: function () {
            $("#bat-auction-table tbody").html(
                '<tr><td colspan="13">Loading auctions...</td></tr>'
            );
        },
        success: function (response) {
            console.log("✅ AJAX response received:", response);

            if (response.error) {
                $("#bat-auction-table tbody").html(
                    '<tr><td colspan="13">' + response.error + "</td></tr>"
                );
                return;
            }

            let html = "";
            response.forEach(function (auction) {
                html += "<tr>";
                html += `<td><img src="${auction.image}" width="100"></td>`;
                html += `<td>${auction.name}</td>`;
                html += `<td>${auction.bid}</td>`;
                html += `<td>${auction.time_left}</td>`;
                html += `<td>${auction.country}</td>`;
                html += `<td><a href="${auction.seller_link}" target="_blank">${auction.seller_name}</a></td>`;
                html += `<td>${auction.make}</td>`;
                html += `<td>${auction.model}</td>`;
                html += `<td>${auction.era}</td>`;
                html += `<td>${auction.origin}</td>`;
                html += `<td>${auction.category}</td>`;
                html += `<td>${auction.dealer_type}</td>`;
                html += `<td><a href="${auction.link}" target="_blank">View</a></td>`;
                html += "</tr>";
            });

            $("#bat-auction-table tbody").html(html);
        },
        error: function (error) {
            console.log("❌ AJAX error:", error);
            $("#bat-auction-table tbody").html(
                '<tr><td colspan="13">Failed to load auctions.</td></tr>'
            );
        },
    });
});
