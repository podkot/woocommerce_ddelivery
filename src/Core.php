<?php
/**
 * @author dmz9 <dmz9@yandex.ru>
 * @copyright 2017 http://ipolh.com
 * @licence MIT
 */
namespace WPWooCommerceDDelivery;
class Core {
	const PLUGIN_ID = 'woocommerce-ddelivery';
	const SCRIPT_HANDLE = 'ddelivery-adapter';
	const SESSION_FIELD_PRICE = 'ddelivery_price';
	const SESSION_FIELD_SDK_ID = 'ddelivery_order_sdk_id';
	const SESSION_FIELD_UPLOAD_ERRORS = 'ddelivery_sdk_errors';
	const ORDER_FIELD_DDELIVERY_ID = 'ddelivery_order_id';
	const ORDER_FIELD_LAST_CREATE_ERROR = '_ddelivery_last_create_error';
	const ORDER_FIELD_LAST_UPDATE_ERROR = '_ddelivery_last_update_error';
	const ORDER_FIELD_CREATION_DATA = '_ddelivery_creation_data';

	public static function init() {
		$instance = new self();
		$instance->_init();
	}

	private function _init() {

		if ( ! Helper::woocommerceActive() ) {
			return false;
		}

		// инициализация метода доставки
		$this->registerShipping();

		if ( ! is_admin() ) {
			// регистрация роутинга
			add_action( 'rest_api_init',
			            array( Router::class, 'registerRoutes' ) );
		}
		add_action( 'woocommerce_checkout_update_order_review',
			function () {
				$wc = WC();
				$wc->shipping()
				   ->calculate_shipping_for_package( DDeliveryShipping::DELIVERY_ID );
			} );

		add_action( 'woocommerce_before_order_itemmeta',
			function ( $orderId ) {
				Helper::showUploadErrorsIfAny();
				Helper::dropUploadErrors();

				return $orderId;
			} );

		$updateCallback = array( Controller::class, 'actionOrderUpdate' );
		$createCallback = array( Controller::class, 'actionOrderCreate' );

		add_action( 'woocommerce_checkout_order_processed', $createCallback, 10, 1 );

		add_action( 'init', function() use( $updateCallback ) {
			$statuses = Helper::createContainer()
							->getAdapter()
							->getCmsOrderStatusList();
			$statuses = array_keys( $statuses );
			foreach ( $statuses as $status ) {
				$action = "woocommerce_order_status_$status";
				add_action( $action,
							$updateCallback,
							10,
							1 );
			}
		} );

		return true;
	}

	private function registerShipping() {
		add_filter( 'woocommerce_shipping_methods',
			function ( $deliveryMethods ) {
				if ( ! is_admin() ) {
					// основной файл скрипта
					wp_enqueue_script( self::SCRIPT_HANDLE,
					                   Helper::getAssetUrl( 'ddelivery-adapter.js' ),
					                   array( 'jquery' ) );
					$customer_id = get_current_user_id();

					// прокидывание настроек на фронт.
					$settings   = array(
						'token'           => Router::buildRestUrl( 'generateSDKToken' ),
						'cart'            => Router::buildRestUrl( 'getUserCart' ),
						'endpoint'        => Router::buildRestUrl( 'ddeliveryEndpoint' ),
						'save'            => Router::buildRestUrl( 'savePrice' ),
						'saveSDK'         => Router::buildRestUrl( 'saveSDK' ),
						'debug'           => Router::buildRestUrl( 'debug' ),
						'containerId'     => 'ddelivery-container-id',
						'bindElement'     => '.woocommerce-billing-fields__field-wrapper',
						'ddeliveryParams' => array(
							'height' => 500
						),
						'debugMode'       => self::isDebug(),
						'toStreet'        => get_user_meta( $customer_id, 'billing_address_1', true ),
						'toHouse'         => get_user_meta( $customer_id, 'billing_house',     true ),
						'toFlat'          => get_user_meta( $customer_id, 'billing_flat',      true ),
					);
					$jsSettings = json_encode( $settings );
					// инлайн данные о заказе и т.п.
					wp_add_inline_script( self::SCRIPT_HANDLE,
					                      "var woocommerce_ddelivery_settings = $jsSettings" );
				}

				return DDeliveryShipping::addShippingToFrontend( $deliveryMethods );
			} );
	}

	private static function isDebug() {

		$group                       = DDeliveryShipping::getOptionsGroup();
		$woocommerceShippingSettings = get_option( $group );

		return ( DDeliveryShipping::IS_DEBUG_DEFAULT_NO != (string) $woocommerceShippingSettings[ DDeliveryShipping::IS_DEBUG_FIELD ] );
	}
}
