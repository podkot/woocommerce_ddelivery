<?php
/**
 * @author dmz9 <dmz9@yandex.ru>
 * @copyright 2017 http://ipolh.com
 * @licence MIT
 */
namespace WPWooCommerceDDelivery;

use DDelivery\Storage\SettingStorageInterface;

class WPSettingsStorage implements SettingStorageInterface {
	public function createStorage() {
		return true;
	}

	public function save( $settings ) {
		$group = DDeliveryShipping::getOptionsGroup();
		return update_option( $group, $settings );
	}

	public function getParam( $paramName ) {
		$group = DDeliveryShipping::getOptionsGroup();
		$opts  = get_option( $group );
		return ( isset( $opts[ $paramName ] ) )
			? $opts[ $paramName ]
			: null;
	}

	public function drop() {
		return true;
	}
}
