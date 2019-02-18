<?php

function trustpilot_legacy_get_all_wc_orders($args) {
    global $wpdb;
    $orders = array();
    $limit = $args['limit'];
    $offset = ($args['limit'] * $args['paged']) - $args['limit'];
    $date_created = $args['date_created'];
    $query = "SELECT * FROM $wpdb->posts 
        WHERE post_type = 'shop_order'
        AND post_status in ('wc-completed', 'completed')
        AND post_date {$date_created}
        ORDER BY id
        LIMIT {$offset}, {$limit}
    ";
    $customer_orders = $wpdb->get_results($query);
    foreach ( $customer_orders as $customer_order ) {
        array_push($orders, wc_get_order( $customer_order ));
    }
    return $orders;
}