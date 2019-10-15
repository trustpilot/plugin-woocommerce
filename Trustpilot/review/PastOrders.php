<?php
/**
 * Trustpilot-reviews
 *
 *
 * @package   Trustpilot-reviews
 * @author    Trustpilot
 * @license   AFL-3.0
 * @link      https://trustpilot.com
 * @copyright 2018 Trustpilot
 */

namespace Trustpilot\Review;

/**
 * @subpackage PastOrders
 */
class PastOrders
{
    /**
     * Instance of this class.
     */
    protected static $instance = null;

    /**
     * Return an instance of this class.
     */
	public static function get_instance() {
		if ( null == self::$instance ) {
			self::$instance = new self;
        }
		return self::$instance;
    }

    public function sync($period_in_days) {
        update_option('sync_in_progress', 'true');
        update_option('show_past_orders_initial', 'false');
        try {
            $trustpilot_api = new TrustpilotHttpClient(TRUSTPILOT_API_URL);
            $key = trustpilot_get_settings(TRUSTPILOT_GENERAL_CONFIGURATION)->key;
            $collect_product_data = WITHOUT_PRODUCT_DATA;
            if (!is_null($key)) {
                // reset counter when syncing for the first time as orders count will be doubled
                trustpilot_set_field(TRUSTPILOT_PAST_ORDERS_FIELD, 0);

                $pageId = 1;
                $post_batch = $this->trustpilot_get_orders_for_period($period_in_days, $collect_product_data, $pageId);
                while ($post_batch) {
                    set_time_limit(120);
                    $batch = null;
                    if (!is_null($post_batch)) {
                        $batch['invitations'] = $post_batch;
                        $batch['type'] = $collect_product_data;
                        $response = $trustpilot_api->postBatchInvitations($key, $batch);
                        $code = $this->handle_trustpilot_response($response, $batch);

                        if ($code == 202) {
                            $collect_product_data = WITH_PRODUCT_DATA;
                            $batch['invitations'] = $this->trustpilot_get_orders_for_period($period_in_days, $collect_product_data, $pageId);
                            $batch['type'] = $collect_product_data;
                            $response = $trustpilot_api->postBatchInvitations($key, $batch);
                            $code = $this->handle_trustpilot_response($response, $batch);
                        }
                        if ($code < 200 || $code > 202) {
                            update_option('show_past_orders_initial', 'true');
                            update_option('sync_in_progress', 'false');
                            update_option('past_orders', 0);
                            update_option('failed_orders', '{}');
                            return;
                        }
                    }
                    $pageId = $pageId + 1;
                    $post_batch = $this->trustpilot_get_orders_for_period($period_in_days, $collect_product_data, $pageId);
                }
            }
        } catch (\Throwable $e) {
            $message = 'Failed to sync past orders.';
            Logger::error($e, $message, array(
                'periodInDays' => $period_in_days,
            ));
        } catch (\Exception $e) {
            $message = 'Failed to sync past orders.';
            Logger::error($e, $message, array(
                'periodInDays' => $period_in_days,
            ));
        }
        update_option('sync_in_progress', 'false');
    }

    public function resync() {
        update_option('sync_in_progress', 'true');
        try {
            $trustpilot_api = new TrustpilotHttpClient(TRUSTPILOT_API_URL);
            $failed_orders_object = trustpilot_get_field(TRUSTPILOT_FAILED_ORDERS_FIELD);
            $key = trustpilot_get_settings(TRUSTPILOT_GENERAL_CONFIGURATION)->key;
            $collect_product_data = WITHOUT_PRODUCT_DATA;
            if (!is_null($key)) {
                $failed_orders_array = array();
                foreach ($failed_orders_object as $id => $value) {
                    array_push($failed_orders_array, $id);
                }

                $chunked_failed_orders = array_chunk($failed_orders_array, 10, true);
                foreach ($chunked_failed_orders as $failed_orders_chunk) {
                    set_time_limit(120);
                    $post_batch = $this->trustpilot_get_orders_by_ids($collect_product_data, $failed_orders_chunk);

                    $batch = null;
                    $batch['invitations'] = $post_batch;
                    $batch['type'] = $collect_product_data;
                    $response = $trustpilot_api->postBatchInvitations($key, $batch);
                    $code = $this->handle_trustpilot_response($response, $batch);

                    if ($code == 202) {
                        $collect_product_data = WITH_PRODUCT_DATA;
                        $batch['invitations'] = $this->trustpilot_get_orders_by_ids($collect_product_data, $failed_orders_chunk);
                        $batch['type'] = $collect_product_data;
                        $response = $trustpilot_api->postBatchInvitations($key, $batch);
                        $code = $this->handle_trustpilot_response($response, $batch);
                    }
                    if ($code < 200 || $code > 202) {
                        update_option('sync_in_progress', 'false');
                        return;
                    }
                }
            }
        } catch (\Throwable $e) {
            $message = 'Failed to resync failed orders.';
            Logger::error($e, $message);
        } catch (\Exception $e) {
            $message = 'Failed to resync failed orders.';
            Logger::error($e, $message);
        }
        update_option('sync_in_progress', 'false');
    }

    public function get_past_orders_info() {
        $syncInProgress = get_option('sync_in_progress', 'false');
        $showInitial = get_option('show_past_orders_initial', 'true');

        if ($syncInProgress === 'false') {
            $synced_orders = trustpilot_get_field(TRUSTPILOT_PAST_ORDERS_FIELD);
            $failed_orders = trustpilot_get_field(TRUSTPILOT_FAILED_ORDERS_FIELD);

            $failed_orders_result = array();
            foreach ($failed_orders as $key => $value) {
                $item = array(
                    'referenceId' => $key,
                    'error' => $value
                );
                array_push($failed_orders_result, $item);
            }

            return array(
                'pastOrders' => array(
                    'synced' => $synced_orders,
                    'unsynced' => count($failed_orders_result),
                    'failed' => $failed_orders_result,
                    'syncInProgress' => $syncInProgress === 'true',
                    'showInitial' => $showInitial === 'true',
                )
            );
        } else {
            return array(
                'pastOrders' => array(
                    'syncInProgress' => $syncInProgress === 'true',
                    'showInitial' => $showInitial === 'true',
                )
            );
        }
    }

    private function trustpilot_get_orders_for_period($period_in_days, $collect_product_data, $pageId) {
        $args = array(
            'type' => 'shop_order',
            'date_created' => '>' . ( time() - (DAY_IN_SECONDS * $period_in_days) ),
            'limit' => 10,
            'paged' => $pageId,
            'status' => 'completed'
        );
        $paged_orders = Orders::get_instance()->get_all_wc_orders($args);
        $invitations = array();

        foreach ($paged_orders as $order) {
            try {
                $invitation = Orders::get_instance()->trustpilot_get_invitation($order, 'past-orders', $collect_product_data);
                if (!is_null($invitation)) {
                    array_push($invitations, $invitation);
                }
            } catch (\Throwable $e) {
                $message = 'Unable to get invitation data for past orders period: ' . $period_in_days;
                Logger::error($e, $message, array(
                    'periodInDays' => $period_in_days,
                    'collectProductData' => $collect_product_data,
                    'pageId' => $pageId,
                ));
            } catch (\Exception $e) {
                $message = 'Unable to get invitation data for past orders period: ' . $period_in_days;
                Logger::error($e, $message, array(
                    'periodInDays' => $period_in_days,
                    'collectProductData' => $collect_product_data,
                    'pageId' => $pageId,
                ));
            }
        }
        wp_cache_flush();

        return $invitations;
    }

    private function trustpilot_get_orders_by_ids($collect_product_data, $order_ids) {
        $invitations = array();

        foreach ($order_ids as $id) {
            $invitation = Orders::get_instance()->trustpilot_get_invitation_by_order_id($id, 'past-orders', $collect_product_data);
            if (!is_null($invitation)) {
                array_push($invitations, $invitation);
            }
        }
        wp_cache_flush();

        return $invitations;
    }

    private function handle_trustpilot_response($response, $post_batch) {
        $synced_orders = trustpilot_get_field(TRUSTPILOT_PAST_ORDERS_FIELD);
        $failed_orders = trustpilot_get_field(TRUSTPILOT_FAILED_ORDERS_FIELD);

        // all succeeded
        if ($response['code'] == 201 && count($response['data']) == 0) {
            $this->trustpilot_save_synced_orders($synced_orders, $post_batch['invitations']);
            $this->trustpilot_save_failed_orders($failed_orders, $post_batch['invitations']);
        }

        // all/some failed
        if ($response['code'] == 201 && count($response['data']) > 0) {
            $failed_order_ids = array_column($response['data'], 'referenceId');
            $succeeded_orders = array_filter($post_batch['invitations'], function ($invitation) use ($failed_order_ids)  {
                return !(in_array($invitation['referenceId'], $failed_order_ids));
            });

            $this->trustpilot_save_synced_orders($synced_orders, $succeeded_orders);
            $this->trustpilot_save_failed_orders($failed_orders, $succeeded_orders, $response['data']);
        }

        return $response['code'];
    }

    private function trustpilot_save_synced_orders($synced_orders, $new_orders) {
        if (count($new_orders) > 0) {
            trustpilot_set_field(TRUSTPILOT_PAST_ORDERS_FIELD, $synced_orders + count($new_orders));
        }
    }

    private function trustpilot_save_failed_orders($failed_orders, $succeeded_orders, $new_failed_orders = array()) {
        $update_needed = false;
        if (count($succeeded_orders) > 0) {
            $update_needed = true;
            foreach ($succeeded_orders as $order) {
                if (isset($failed_orders->{$order['referenceId']})) {
                    unset($failed_orders->{$order['referenceId']});
                }
            }
        }

        if (count($new_failed_orders) > 0) {
            $update_needed = true;
            foreach ($new_failed_orders as $failed_order) {
                $failed_orders->{$failed_order->referenceId} = base64_encode($failed_order->error);
            }
        }

        if ($update_needed) {
            trustpilot_set_field(TRUSTPILOT_FAILED_ORDERS_FIELD, $failed_orders);
        }
    }
}
