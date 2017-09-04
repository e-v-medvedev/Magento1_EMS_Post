<?php

class Smartceo_Emspost_Model_Carrier_Emspost extends Mage_Shipping_Model_Carrier_Abstract
/* implements Mage_Shipping_Model_Carrier_Interface */ {

	protected $_code = 'emspost';
	var $EMS_cities = false, $EMS_regions = false;

	public function __construct() {
		/*
		 * поиск по городам отключен из-за возможных конфликтов результатов с поиском по регионам
		 */
		$this->_getCities();

		/*
		 * Список регионов в БД должен соответствовать списку регионов в API сервиса
		 */
		$this->_getRegions();
	}

	/**
	 * Возвращает Quote в зависимости от того, из какого интерфейса вызывается модуль.
	 * 
	 * @return type
	 */
	protected function _getQuote() {
		/*
		 * в админке и фронтофисе разные сессии и квоты
		 */
		if (!Mage::app()->getStore()->isAdmin()) {
			return Mage::getSingleton('checkout/session')->getQuote();
		} else {
			return Mage::getSingleton('adminhtml/session_quote')->getQuote();
		}
	}

	public function collectRates(Mage_Shipping_Model_Rate_Request $request) {

		if (!$this->getConfigFlag('active')) {
			return false;
		}

		//калькуляция количества едениц и веса доставки
		$qty = 0;
		$weight = 0.;
		if ($request->getAllItems()) {
			foreach ($request->getAllItems() as $item) {
				if ($item->getProduct()->getTypeId() =='configurable') {
					continue;
				}

				$product = Mage::getModel("catalog/product")->load($item->getProduct()->getId());

				$weight += (float) $product->getWeight() * (int) $item->getQty();
				$qty += $item->getQty();
			}
		}

		//Схема расчета веса упаковки: первый товар 0.5 кг. Каждый следующий товар добавляет 0.3 кг
		if ($qty == 1) {
			$packWeight = 0.5;
		} elseif ($qty > 1) {
			$packWeight = 0.5 + ($qty - 1) * 0.3;
		}

		$regionModel = Mage::getModel('directory/region')->load(Mage::getStoreConfig('shipping/origin/region_id'));
		$store_region = (string) $regionModel->getName();
		$store_city = (string) Mage::getStoreConfig('shipping/origin/city');

		//получаем из настроек отправной город
		if (!$start_point = $this->_searchkey($store_city, true))
			if (!$start_point = $this->_searchkey($store_region)) {
				return false;
			}

		$shippingAddress = $this->_getQuote()->getShippingAddress();

		/*
		 * Этот метод доставки не используется для Москвы
		 */
		if ((string) $shippingAddress->getCountryId() == 'RU' && (string) $shippingAddress->getRegion() == "Москва") {
			return;
		}

		if ((string) $shippingAddress->getCountryId() != 'RU') {
			//международная доставка
			$request = "http://emspost.ru/api/rest?method=ems.calculate&to=" .
				$shippingAddress->getCountryId() .
				"&from=" . 'RU' .
				"&weight=" . ($weight + $packWeight) . "&type=att&plain=true";
			$calculate = file_get_contents($request);
			$calculate = json_decode($calculate);
			if (isset($calculate->rsp->price) && (float) $calculate->rsp->price > 0)
				$shippingPrice = $this->convert((float) $calculate->rsp->price);
			else {
				return false;
			}
		} else {
			//доставка по России
			if ($shippingAddress->getCity() != NULL &&
				$shippingAddress->getCity() != "Москва" &&
				$end_point = $this->_searchkey($shippingAddress->getCity())) {
				$EMS_destination_code = (string) $end_point->value;
			} else
			if ($end_point = $this->_searchkey($shippingAddress->getRegion())) {
				$EMS_destination_code = (string) $end_point->value;
			} else {
				return false;
			}

			$calcURL = 'http://emspost.ru/api/rest?&method=ems.calculate&from=' .
				$start_point->value .
				'&to=' . $EMS_destination_code .
				'&weight=' . ($weight + $packWeight) .
				'&type=att&plain=true';

			$calculate = file_get_contents($calcURL, false, stream_context_create(array('http' => array('method' => 'GET', 'timeout' => 5))));
			$calculate = json_decode($calculate);
			if (isset($calculate->rsp->price) && (float) $calculate->rsp->price > 0)
				$shippingPrice = $this->convert((float) $calculate->rsp->price);
			else {
				return false;
			}
		}

		$result = Mage::getModel('shipping/rate_result');

		if ($shippingPrice !== false) {
			$method = Mage::getModel('shipping/rate_result_method');

			$method->setCarrier('emspost');
			$method->setCarrierTitle($this->getConfigData('title'));

			$method->setMethod('emspost');
			$method->setMethodTitle($this->getConfigData('name'));

			$method->setPrice($shippingPrice);
			$method->setCost($shippingPrice);
			$result->append($method);
		}

		return $result;
	}

	public function getAllowedMethods() {
		return array('emspost' => $this->getConfigData('name'));
	}

	/**
	 * поиск города или региона в массивах точек присутствия EMS почты России
	 * 
	 * @param type $str
	 * @param boolean $check_cities (если true то сначала расчет ведется по городу, а потом по региону, если false - только по региону)
	 * @return boolean
	 */
	private function _searchkey($str, $check_cities = true) {
		$str_array = explode(" ", mb_strtolower($str, 'UTF-8'));

		if ($check_cities) {
			/*
			 * поиск по городу может противоречить поиску по региону, так как
			 * регион вводится из списка, а город произвольно. Могут быть проблемы и с одноименными городами
			 */
			foreach ($this->EMS_cities as $city) {
				$city_array = explode(' ', mb_strtolower($city->name, 'UTF-8'));
				$flag = true;
				foreach ($str_array as $word) {
					if (in_array($word, $city_array)) {
						continue;
					} else {
						$flag = false;
						break;
					}
				}
				/*
				 * если в результате проверки флаг остался true
				 * то значит не нашлось ни одного слова, не входящего в название города
				 * следовательно найден правильный город
				 */
				if ($flag) {
					return $city;
				}
			}
		}

		foreach ($this->EMS_regions as $region) {
			$region_array = explode(' ', mb_strtolower($region->name, 'UTF-8'));

			$flag = true;
			foreach ($str_array as $word) {
				if (in_array($word, $region_array)) {
					continue;
				} else {
					$flag = false;
					break;
				}
			}
			/*
			 * если в результате проверки флаг остался true
			 * то значит не нашлось ни одного слова, не входящего в название региона
			 */
			if ($flag) {
				return $region;
			}
		}

		return false;
	}

	/**
	 * получение массива идентификаторов городов из API сервиса
	 */
	protected function _getCities() {
		$this->EMS_cities = file_get_contents('http://emspost.ru/api/rest/?method=ems.get.locations&type=cities&plain=true', false, stream_context_create(array('http' => array('method' => 'GET', 'timeout' => 5))));
		$this->EMS_cities = json_decode($this->EMS_cities);
		if ($this->EMS_cities->rsp->stat == 'ok') {
			$this->EMS_cities = $this->EMS_cities->rsp->locations;
		} else {
			$this->EMS_cities = json_decode('[{"value":"city--abakan","name":"Абакан","type":"cities"},{"value":"city--anadyr","name":"Анадырь","type":"cities"},{"value":"city--anapa","name":"Анапа","type":"cities"},{"value":"city--arhangelsk","name":"Архангельск","type":"cities"},{"value":"city--astrahan","name":"Астрахань","type":"cities"},{"value":"city--barnaul","name":"Барнаул","type":"cities"},{"value":"city--belgorod","name":"Белгород","type":"cities"},{"value":"city--birobidzhan","name":"Биробиджан","type":"cities"},{"value":"city--blagoveshhensk","name":"Благовещенск","type":"cities"},{"value":"city--brjansk","name":"Брянск","type":"cities"},{"value":"city--velikij-novgorod","name":"Великий Новгород","type":"cities"},{"value":"city--vladivostok","name":"Владивосток","type":"cities"},{"value":"city--vladikavkaz","name":"Владикавказ","type":"cities"},{"value":"city--vladimir","name":"Владимир","type":"cities"},{"value":"city--volgograd","name":"Волгоград","type":"cities"},{"value":"city--vologda","name":"Вологда","type":"cities"},{"value":"city--vorkuta","name":"Воркута","type":"cities"},{"value":"city--voronezh","name":"Воронеж","type":"cities"},{"value":"city--gorno-altajsk","name":"Горно-Алтайск","type":"cities"},{"value":"city--groznyj","name":"Грозный","type":"cities"},{"value":"city--dudinka","name":"Дудинка","type":"cities"},{"value":"city--ekaterinburg","name":"Екатеринбург","type":"cities"},{"value":"city--elizovo","name":"Елизово","type":"cities"},{"value":"city--ivanovo","name":"Иваново","type":"cities"},{"value":"city--izhevsk","name":"Ижевск","type":"cities"},{"value":"city--irkutsk","name":"Иркутск","type":"cities"},{"value":"city--ioshkar-ola","name":"Йошкар-Ола","type":"cities"},{"value":"city--kazan","name":"Казань","type":"cities"},{"value":"city--kaliningrad","name":"Калининград","type":"cities"},{"value":"city--kaluga","name":"Калуга","type":"cities"},{"value":"city--kemerovo","name":"Кемерово","type":"cities"},{"value":"city--kirov","name":"Киров","type":"cities"},{"value":"city--kostomuksha","name":"Костомукша","type":"cities"},{"value":"city--kostroma","name":"Кострома","type":"cities"},{"value":"city--krasnodar","name":"Краснодар","type":"cities"},{"value":"city--krasnojarsk","name":"Красноярск","type":"cities"},{"value":"city--kurgan","name":"Курган","type":"cities"},{"value":"city--kursk","name":"Курск","type":"cities"},{"value":"city--kyzyl","name":"Кызыл","type":"cities"},{"value":"city--lipeck","name":"Липецк","type":"cities"},{"value":"city--magadan","name":"Магадан","type":"cities"},{"value":"city--magnitogorsk","name":"Магнитогорск","type":"cities"},{"value":"city--majkop","name":"Майкоп","type":"cities"},{"value":"city--mahachkala","name":"Махачкала","type":"cities"},{"value":"city--mineralnye-vody","name":"Минеральные Воды","type":"cities"},{"value":"city--mirnyj","name":"Мирный","type":"cities"},{"value":"city--moskva","name":"Москва","type":"cities"},{"value":"city--murmansk","name":"Мурманск","type":"cities"},{"value":"city--mytishhi","name":"Мытищи","type":"cities"},{"value":"city--naberezhnye-chelny","name":"Набережные Челны","type":"cities"},{"value":"city--nadym","name":"Надым","type":"cities"},{"value":"city--nazran","name":"Назрань","type":"cities"},{"value":"city--nalchik","name":"Нальчик","type":"cities"},{"value":"city--narjan-mar","name":"Нарьян-Мар","type":"cities"},{"value":"city--nerjungri","name":"Нерюнгри","type":"cities"},{"value":"city--neftejugansk","name":"Нефтеюганск","type":"cities"},{"value":"city--nizhnevartovsk","name":"Нижневартовск","type":"cities"},{"value":"city--nizhnij-novgorod","name":"Нижний Новгород","type":"cities"},{"value":"city--novokuzneck","name":"Новокузнецк","type":"cities"},{"value":"city--novorossijsk","name":"Новороссийск","type":"cities"},{"value":"city--novosibirsk","name":"Новосибирск","type":"cities"},{"value":"city--novyj-urengoj","name":"Новый Уренгой","type":"cities"},{"value":"city--norilsk","name":"Норильск","type":"cities"},{"value":"city--nojabrsk","name":"Ноябрьск","type":"cities"},{"value":"city--omsk","name":"Омск","
type":"cities"},{"value":"city--orel","name":"Орел","type":"cities"},{"value":"city--orenburg","name":"Оренбург","type":"cities"},{"value":"city--penza","name":"Пенза","type":"cities"},{"value":"city--perm","name":"Пермь","type":"cities"},{"value":"city--petrozavodsk","name":"Петрозаводск","type":"cities"},{"value":"city--petropavlovsk-kamchatskij","name":"Петропавловск-Камчатский","type":"cities"},{"value":"city--pskov","name":"Псков","type":"cities"},{"value":"city--rostov-na-donu","name":"Ростов-на-Дону","type":"cities"},{"value":"city--rjazan","name":"Рязань","type":"cities"},{"value":"city--salehard","name":"Салехард","type":"cities"},{"value":"city--samara","name":"Самара","type":"cities"},{"value":"city--sankt-peterburg","name":"Санкт-Петербург","type":"cities"},{"value":"city--saransk","name":"Саранск","type":"cities"},{"value":"city--saratov","name":"Саратов","type":"cities"},{"value":"city--smolensk","name":"Смоленск","type":"cities"},{"value":"city--sochi","name":"Сочи","type":"cities"},{"value":"city--stavropol","name":"Ставрополь","type":"cities"},{"value":"city--strezhevoj","name":"Стрежевой","type":"cities"},{"value":"city--surgut","name":"Сургут","type":"cities"},{"value":"city--syktyvkar","name":"Сыктывкар","type":"cities"},{"value":"city--tambov","name":"Тамбов","type":"cities"},{"value":"city--tver","name":"Тверь","type":"cities"},{"value":"city--toljatti","name":"Тольятти","type":"cities"},{"value":"city--tomsk","name":"Томск","type":"cities"},{"value":"city--tula","name":"Тула","type":"cities"},{"value":"city--tynda","name":"Тында","type":"cities"},{"value":"city--tjumen","name":"Тюмень","type":"cities"},{"value":"city--ulan-udje","name":"Улан-Удэ","type":"cities"},{"value":"city--uljanovsk","name":"Ульяновск","type":"cities"},{"value":"city--usinsk","name":"Усинск","type":"cities"},{"value":"city--ufa","name":"Уфа","type":"cities"},{"value":"city--uhta","name":"Ухта","type":"cities"},{"value":"city--khabarovsk","name":"Хабаровск","type":"cities"},{"value":"city--khanty-mansijsk","name":"Ханты-Мансийск","type":"cities"},{"value":"city--kholmsk","name":"Холмск","type":"cities"},{"value":"city--cheboksary","name":"Чебоксары","type":"cities"},{"value":"city--cheljabinsk","name":"Челябинск","type":"cities"},{"value":"city--cherepovec","name":"Череповец","type":"cities"},{"value":"city--cherkessk","name":"Черкесск","type":"cities"},{"value":"city--chita","name":"Чита","type":"cities"},{"value":"city--elista","name":"Элиста","type":"cities"},{"value":"city--yuzhno-sahalinsk","name":"Южно-Сахалинск","type":"cities"},{"value":"city--yakutsk","name":"Якутск","type":"cities"},{"value":"city--yaroslavl","name":"Ярославль","type":"cities"}]');
		}
	}

	/**
	 * получение массива идентификаторов регионов из API сервиса
	 */
	protected function _getRegions() {
		/*
		 * @TODO если необходимо постоянно синхронизировать данные о регионах доставки
		 * с сервисом, раскомментировать строки, формирующие и отаравляющие соответствующий запрос
		 */
//		$this->EMS_regions = file_get_contents('http://emspost.ru/api/rest/?method=ems.get.locations&type=regions&plain=true', false, stream_context_create(array('http' => array('method' => 'GET', 'timeout' => 5))));
//		$this->EMS_regions = json_decode($this->EMS_regions);
//		if ($this->EMS_regions->rsp->stat == 'ok') {
//			$this->EMS_regions = $this->EMS_regions->rsp->locations;
//		} else {
		$regions = '[{"value":"region--respublika-adygeja","name":"АДЫГЕЯ РЕСПУБЛИКА","type":"regions"},{"value":"region--respublika-altaj","name":"АЛТАЙ РЕСПУБЛИКА","type":"regions"},{"value":"region--altajskij-kraj","name":"АЛТАЙСКИЙ КРАЙ","type":"regions"},{"value":"region--amurskaja-oblast","name":"АМУРСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--arhangelskaja-oblast","name":"АРХАНГЕЛЬСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--astrahanskaja-oblast","name":"АСТРАХАНСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--respublika-bashkortostan","name":"БАШКОРТОСТАН РЕСПУБЛИКА","type":"regions"},{"value":"region--belgorodskaja-oblast","name":"БЕЛГОРОДСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--brjanskaja-oblast","name":"БРЯНСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--respublika-burjatija","name":"БУРЯТИЯ РЕСПУБЛИКА","type":"regions"},{"value":"region--vladimirskaja-oblast","name":"ВЛАДИМИРСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--volgogradskaja-oblast","name":"ВОЛГОГРАДСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--vologodskaja-oblast","name":"ВОЛОГОДСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--voronezhskaja-oblast","name":"ВОРОНЕЖСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--respublika-dagestan","name":"ДАГЕСТАН РЕСПУБЛИКА","type":"regions"},{"value":"region--evrejskaja-ao","name":"ЕВРЕЙСКАЯ АВТОНОМНАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--zabajkalskij-kraj","name":"ЗАБАЙКАЛЬСКИЙ КРАЙ","type":"regions"},{"value":"region--ivanovskaja-oblast","name":"ИВАНОВСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--respublika-ingushetija","name":"ИНГУШЕТИЯ РЕСПУБЛИКА","type":"regions"},{"value":"region--irkutskaja-oblast","name":"ИРКУТСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--kabardino-balkarskaja-respublika","name":"КАБАРДИНО-БАЛКАРСКАЯ РЕСПУБЛИКА","type":"regions"},{"value":"region--kaliningradskaja-oblast","name":"КАЛИНИНГРАДСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--respublika-kalmykija","name":"КАЛМЫКИЯ РЕСПУБЛИКА","type":"regions"},{"value":"region--kaluzhskaja-oblast","name":"КАЛУЖСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--kamchatskij-kraj","name":"КАМЧАТСКИЙ КРАЙ","type":"regions"},{"value":"region--karachaevo-cherkesskaja-respublika","name":"КАРАЧАЕВО-ЧЕРКЕССКАЯ РЕСПУБЛИКА","type":"regions"},{"value":"region--respublika-karelija","name":"КАРЕЛИЯ РЕСПУБЛИКА","type":"regions"},{"value":"region--kemerovskaja-oblast","name":"КЕМЕРОВСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--kirovskaja-oblast","name":"КИРОВСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--respublika-komi","name":"КОМИ РЕСПУБЛИКА","type":"regions"},{"value":"region--kostromskaja-oblast","name":"КОСТРОМСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--krasnodarskij-kraj","name":"КРАСНОДАРСКИЙ КРАЙ","type":"regions"},{"value":"region--krasnojarskij-kraj","name":"КРАСНОЯРСКИЙ КРАЙ","type":"regions"},{"value":"region--kurganskaja-oblast","name":"КУРГАНСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--kurskaja-oblast","name":"КУРСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--leningradskaja-oblast","name":"ЛЕНИНГРАДСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--lipeckaja-oblast","name":"ЛИПЕЦКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--magadanskaja-oblast","name":"МАГАДАНСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--respublika-marij-el","name":"МАРИЙ ЭЛ РЕСПУБЛИКА","type":"regions"},{"value":"region--respublika-mordovija","name":"МОРДОВИЯ РЕСПУБЛИКА","type":"regions"},{"value":"region--moskovskaja-oblast","name":"МОСКОВСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--murmanskaja-oblast","name":"МУРМАНСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--neneckij-ao","name":"НЕНЕЦКИЙ АВТОНОМНЫЙ ОКРУГ","type":"regions"},{"value":"region--nizhegorodskaja-oblast","name":"НИЖЕГОРОДСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--novgorodskaja-oblast","name":"НОВГОРОДСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--novosibirskaja-oblast","name":"НОВОСИБИРСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"663300","name":"НОРИЛЬСКИЙ ПРОМЫШЛЕНННЫЙ РАЙОН","type":"regions"},{"value":"region--omskaja-oblast","name":"ОМСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--orenburgskaja-oblast","name":"ОРЕНБУРГСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--orlovskaja-oblast","name":"ОРЛОВСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--penzenskaja-oblast","name":"ПЕНЗЕНСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--permskij-kraj","name":"ПЕРМСКИЙ КРАЙ","type":"regions"},{"value":"region--primorskij-kraj","name":"ПРИМОРСКИЙ КРАЙ","type":"regions"},{"value":"region--pskovskaja-oblast","name":"ПСКОВСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--rostovskaja-oblast","name":"РОСТОВСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--rjazanskaja-oblast","name":"РЯЗАНСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--samarskaja-oblast","name":"САМАРСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--saratovskaja-oblast","name":"САРАТОВСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--respublika-saha-yakutija","name":"САХА (ЯКУТИЯ) РЕСПУБЛИКА","type":"regions"},{"value":"region--sahalinskaja-oblast","name":"САХАЛИНСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--sverdlovskaja-oblast","name":"СВЕРДЛОВСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--respublika-sev.osetija-alanija","name":"СЕВЕРНАЯ ОСЕТИЯ-АЛАНИЯ РЕСПУБЛИКА","type":"regions"},{"value":"region--smolenskaja-oblast","name":"СМОЛЕНСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--stavropolskij-kraj","name":"СТАВРОПОЛЬСКИЙ КРАЙ","type":"regions"},{"value":"region--tajmyrskij-ao","name":"ТАЙМЫРСКИЙ ДОЛГАНО-НЕНЕЦКИЙ РАЙОН","type":"regions"},{"value":"region--tambovskaja-oblast","name":"ТАМБОВСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--respublika-tatarstan","name":"ТАТАРСТАН РЕСПУБЛИКА","type":"regions"},{"value":"region--tverskaja-oblast","name":"ТВЕРСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--tomskaja-oblast","name":"ТОМСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--tulskaja-oblast","name":"ТУЛЬСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--respublika-tyva","name":"ТЫВА РЕСПУБЛИКА","type":"regions"},{"value":"region--tjumenskaja-oblast","name":"ТЮМЕНСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--udmurtskaja-respublika","name":"УДМУРТСКАЯ РЕСПУБЛИКА","type":"regions"},{"value":"region--uljanovskaja-oblast","name":"УЛЬЯНОВСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--khabarovskij-kraj","name":"ХАБАРОВСКИЙ КРАЙ","type":"regions"},{"value":"region--respublika-khakasija","name":"ХАКАСИЯ РЕСПУБЛИКА","type":"regions"},{"value":"region--khanty-mansijskij-ao","name":"ХАНТЫ-МАНСИЙСКИЙ-ЮГРА АВТОНОМНЫЙ ОКРУГ","type":"regions"},{"value":"region--cheljabinskaja-oblast","name":"ЧЕЛЯБИНСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--chechenskaya-respublika","name":"ЧЕЧЕНСКАЯ РЕСПУБЛИКА","type":"regions"},{"value":"region--chuvashskaja-respublika","name":"ЧУВАШСКАЯ РЕСПУБЛИКА","type":"regions"},{"value":"region--chukotskij-ao","name":"ЧУКОТСКИЙ АВТОНОМНЫЙ ОКРУГ","type":"regions"},{"value":"region--yamalo-neneckij-ao","name":"ЯМАЛО-НЕНЕЦКИЙ АВТОНОМНЫЙ ОКРУГ","type":"regions"},{"value":"region--yaroslavskaja-oblast","name":"ЯРОСЛАВСКАЯ ОБЛАСТЬ","type":"regions"},{"value":"region--kazahstan","name":"КАЗАХСТАН","type":"regions"},{"value":"region--crimea","name":"КРЫМ РЕСПУБЛИКА","type":"regions"}]';
		$this->EMS_regions = json_decode($regions);
//		}
	}

	/**
	 * Пересчет стоимости доставки из рублей в валюту админки магазина.
	 * Сервис выдает расчет в рублях, а в заказ расчет стоимости записывается в валюте админки.
	 * В результате имеем неправильный расчет.
	 * 
	 * @param type $price
	 * @return type
	 */
	protected function convert($price) {
		/*
		 * основная валюта магазина
		 */
		$baseCurrencyCode = Mage::app()->getStore()->getBaseCurrencyCode();

		/*
		 * валюта витрины магазина
		 */
		$currentCurrencyCode = Mage::app()->getStore()->getCurrentCurrencyCode();

		/*
		 * коды доступных в магазине валют
		 */
		$allowedCurrencies = Mage::getModel('directory/currency')
			->getConfigAllowCurrencies();

		/**
		 * курсы доступных в магазине валют
		 */
		$currencyRates = Mage::getModel('directory/currency')
			->getCurrencyRates('USD', array_values($allowedCurrencies));

		/*
		 * если валюта админки не равна рублю, то есть валюте расчета сервиса EMS почта России,
		 * то пересчитываем из рублей в валюту админки
		 */
		if ($baseCurrencyCode != "RUB") {
			$price = $price / $currencyRates[$currentCurrencyCode];
		}

		return $price;
	}

}
