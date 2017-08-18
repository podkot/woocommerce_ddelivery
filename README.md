Установка плагина (только через composer, для [bedrock](https://roots.io/bedrock/)-подобных конфигураций):

0. Добавить в composer.json/repositories:
```
        {
            "type": "vcs",
            "url": "https://github.com/podkot/woocommerce_ddelivery"
        }
```
и в composer.json/require:
```
"ipolh/woocommerce_ddelivery": "dev-master#45dde08"
```
(хэш лучше подставить актуальный)
1. Активировать плагин из админки Wordpress
2. Если WP REST API отключён - его нужно включить и затем сбросить permalink cache

Для работы на чекауте необходима привязка по ключу апи. Ключ можно найти в кабинете DDelivery на вкладке "Магазины".

Настройки доставки находятся в WooCommerce - Настройки - Доставка - DDelivery.


Разработчиком модуля является компания <a href="http://ipolh.com">Ипол</a>.
По вопросу консультаций или помощи в настройке и доработке (платные услуги) можете обратиться на адрес support@ipolh.com.
