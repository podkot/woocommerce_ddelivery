<?php

/**
 * @author dmz9 <dmz9@yandex.ru>
 * @copyright 2017 http://ipolh.com
 * @licence MIT
 */
namespace WPWooCommerceDDelivery;

class Controller
{
	public static function actionDDelivery()
	{

		$container = Helper::createContainer();
		$container->getUi()
			->render($_REQUEST);
		die();
	}

	public static function actionSDKToken()
	{

		$products = (isset($_POST) && isset($_POST['products']))
			? $_POST['products']
			: array();
		$discount = (isset($_POST) && isset($_POST['discount']))
			? $_POST['discount']
			: array();

		$container = Helper::createContainer(array(
			'form' => $products,
			'discount' => (float)$discount
		));
		$business = $container->getBusiness();
		$cartAndDiscount = $container->getAdapter()
			->getCartAndDiscount();
		$token = $business->renderModuleToken($cartAndDiscount);

		return array(
			'url' => $container->getAdapter()
				->getSdkServer() . 'delivery/' . $token . '/index.json'
		);
	}

	public static function actionUserCart()
	{
		return Helper::getCurrentCartProducts();
	}

	/**
	 * сохраняем sdk-id выданый дделивери. это ид черновика.
	 *
	 * @return array
	 */
	public static function actionSaveSDK()
	{
		$sdkId = $_POST['id'];
		if (empty($sdkId)) {
			return array('status' => 'fail');
		}

		$session = WC()->session;
		$field = Core::SESSION_FIELD_SDK_ID;
		$session->{$field} = $sdkId;
		$session->save_data();

		return array('status' => 'ok');
	}

	/**
	 * сохраняем выбраную йузером цену доставки в сессии
	 *
	 * @return array
	 */
	public static function actionSavePrice()
	{
		$data = $_POST['data'];
		if (empty($data)) {
			return array('status' => 'fail');
		}

		$price = $data['price'];

		$wc = WC();
		$session = $wc->session;
		$field = Core::SESSION_FIELD_PRICE;
		unset($session->{$field});
		$session->{$field} = $price;
		// hack to remove session key for ddelivery shipping package to force it recalculate instead of getting cached
		// @stolen in class-wc-shipping.php@calculate_shipping_for_package()
		// emulating case when recalculating is forced
		$total = $session->get('shipping_method_counts', array());
		foreach ($total as $i => $whatever) {
			$session->set(
				'shipping_for_package_' . $i,
				'fuck_you_woocommerce'
			);
		}

		$session->save_data();

		return array('status' => 'ok');
	}

	public static function actionDebug()
	{
		$order = Helper::getOrder($_POST['orderId']);

		self::_saveOrder( $order );

		return die(print_r(
			$order,
			1
		));
	}

	/**
	 * backend only. sending order to ddelivery is here
	 *
	 * @param $orderId
	 *
	 * @return int
	 */
	public static function actionOrderUpdate($orderId)
	{
		$logger = new WPLogStorage();
		$logger->saveLog(" ");

		$orderId = (int)$orderId;

		if (empty($orderId)) {
			$logger->saveLog("Order create stopped: empty orderId");

			return $orderId;
		}

		$logger->saveLog('Order update hook ' . $orderId);

		$container = Helper::createContainer();
		$business = $container->getBusiness();

		try {
			$order = Helper::getOrder($orderId);

			if ( !$order->has_shipping_method( DDeliveryShipping::DELIVERY_ID ) ) {
				$logger->saveLog('DDelivery shipping method not found in order');

				return $orderId;
			}

			$sdkId = $order->get_meta(Core::SESSION_FIELD_SDK_ID);

			if (empty($sdkId)) {
				$message = 'Empty sdk id, stopping';
				$logger->saveLog($message);
				self::_orderUpdateError( $order, $message );

				return $orderId;
			}

			$logger->saveLog("Order $orderId has sdk id $sdkId");
			$ddeliveryId = $order->get_meta(Core::ORDER_FIELD_DDELIVERY_ID, false);

			if ($ddeliveryId !== false && !empty($ddeliveryId)) {
				$logger->saveLog("Order already uploaded, ddelivery id: " . print_r($ddeliveryId, 1));
				$order->add_order_note('Заказ уже был отправлен в DDelivery, повторая отправка пропущена');

				return $orderId;
			}
		} catch (\Exception $exception) {
			$message = wp_strip_all_tags($exception->getMessage());
			$logger->saveLog("Exception in actionOrderUpdate: $message");
			self::_orderUpdateError( $order, $message );

			return $orderId;
		}

		$logger->saveLog("Sending order to DDelivery");
		$toSend = [
			$sdkId,
			$order->get_order_number(),
			Helper::stringToNumber($order->get_payment_method()),
			$order->get_status(),
			$order->get_formatted_billing_full_name(),
			$order->get_billing_phone(),
			$order->get_billing_email(),
			apply_filters('ddelivery_payment', $order->is_paid() ? 0 : $order->get_total(), $order),
			$order->post->post_excerpt
		];

		try {
			$result = $business->onCmsChangeStatus(...$toSend);
		} catch (\Exception $exception) {
			$logger->saveLog("Exception in onCmsChangeStatus: {$exception->getMessage()}");
			$logger->saveLog("Debug data: " . print_r($toSend, 1));

			$message = wp_strip_all_tags($exception->getMessage());

			if ( strpos( $message, 'ПВЗ' ) !== false ) {
				$creation_data = $order->get_meta(Core::ORDER_FIELD_CREATION_DATA);

				if ( $creation_data ) {
					$creation_data = \unserialize( $creation_data );
					$info = $creation_data['info'];

					if ( $info ) {
						$info = wp_strip_all_tags( $info );
						$message .= " Информация о доставке: $info";
					}
				}
			}

			self::_orderUpdateError( $order, $message );
			Helper::addUploadError($exception->getMessage(), $orderId);

			return $orderId;
		}

		if ($result === 0) {
			$logger->saveLog("Status '{$order->get_status()}' for send order doesnt match, stopping");
		} else if ($result > 0) {
			$logger->saveLog("DDelivery order id : " . print_r($result, 1));
			$order->add_meta_data(Core::ORDER_FIELD_DDELIVERY_ID, (int)$result, true);
			$order->delete_meta_data(Core::ORDER_FIELD_LAST_UPDATE_ERROR);
			$order->add_order_note(
				"Заказ отправлен в DDelivery: " .
					"<a href=\"https://ddelivery.ru/cabinet/orders/${result}/view\">${result}</a>"
			);
			self::_saveOrder( $order );

			Helper::dropUploadErrors();
		} else {
			$logger->saveLog("Unexpected result: " . print_r($result, 1));
		}

		return $orderId;
	}

	protected static function _orderUpdateError( $order, $message ) {
		$order->add_order_note("Ошибка отправки заказа в DDelivery: $message");
		$order->add_meta_data(Core::ORDER_FIELD_LAST_UPDATE_ERROR, $message, true);
		self::_saveOrder( $order );
	}

	/**
	 * backend only. binding order to ddelivery
	 *
	 * @param $orderId
	 *
	 * @return int
	 */
	public static function actionOrderCreate($orderId)
	{
		$logger = new WPLogStorage();
		$logger->saveLog(" ");

		$orderId = (int)$orderId;
		if (empty($orderId)) {
			$logger->saveLog("Order create stopped: empty orderId");

			return $orderId;
		}

		$logger->saveLog('Order create hook ' . $orderId);

		$session = WC()->session;

		$container = Helper::createContainer();
		$business = $container->getBusiness();

		try {
			$order = Helper::getOrder($orderId);

			if ( !$order->has_shipping_method( DDeliveryShipping::DELIVERY_ID ) ) {
				$logger->saveLog('DDelivery shipping method not found in order');

				return $orderId;
			}

			$field = Core::SESSION_FIELD_SDK_ID;
			$sdkId = $session->get($field);

			if (empty($sdkId)) {
				$message = 'SDK ID not found';
				$logger->saveLog($message);
				self::_orderCreateError( $order, $message );

				return $orderId;
			} else {
				unset($session->{$field});
				$session->save_data();
			}

			$order->add_meta_data(Core::SESSION_FIELD_SDK_ID, $sdkId, true);
			self::_saveOrder( $order );

			$logger->saveLog("Order $orderId has sdk id $sdkId");
		} catch (\Exception $exception) {
			$message = wp_strip_all_tags($exception->getMessage());
			$logger->saveLog("Exception in actionOrderCreate: $message");
			self::_orderCreateError( $order, $message );

			return $orderId;
		}

		$logger->saveLog("Sending order to DDelivery");
		$toSend = [
			$sdkId,
			$order->get_order_number(),
			Helper::stringToNumber($order->get_payment_method()),
			$order->get_status(),
			$order->get_formatted_billing_full_name(),
			$order->get_billing_phone(),
			$order->get_billing_email(),
			apply_filters('ddelivery_payment', $order->is_paid() ? 0 : $order->get_total(), $order),
			$order->post->post_excerpt
		];

		try {
			$result = $business->onCmsOrderFinish(...$toSend);
		} catch (\Exception $exception) {
			$logger->saveLog("Exception in onCmsOrderFinish: {$exception->getMessage()}");
			$logger->saveLog("Debug data: " . print_r($toSend, 1));

			$message = wp_strip_all_tags($exception->getMessage());
			self::_orderCreateError( $order, $message );

			return $orderId;
		}

		if ($result !== false) {
			$logger->saveLog("DDelivery data: " . print_r($result, 1));
			$order->add_meta_data(Core::ORDER_FIELD_CREATION_DATA, \serialize($result), true);
			$order->delete_meta_data(Core::ORDER_FIELD_LAST_CREATE_ERROR);
			$order->add_order_note('Заказ создан в DDelivery');

			self::_saveOrder( $order );
		} else {
			$logger->saveLog("DDelivery onCmsOrderFinish returned empty answer");
		}

		return $orderId;
	}

	protected static function _orderCreateError( $order, $message ) {
		$order->add_order_note("Ошибка создания заказа в DDelivery: $message");
		$order->add_meta_data(Core::ORDER_FIELD_LAST_CREATE_ERROR, $message, true);
		self::_saveOrder( $order );
	}

	protected static function _saveOrder( $order ) {
		// cmb2 deletes some metas. prevent it
		add_filter('cmb2_override_meta_save', '__return_false', 10, 4);
		add_filter('cmb2_override_meta_remove', '__return_false', 10, 4);
		$order->save();
	}
}
