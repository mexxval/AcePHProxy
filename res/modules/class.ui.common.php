<?php

// TODO singleton

abstract class AppUI_common extends AcePlugin_common implements AppUserInterface {
	const CLR_SPEC1 = 4;
	const CLR_YELLOW = 3;
	const CLR_GREEN = 2;
	const CLR_ERROR = 1;
	const CLR_DEFAULT = 7;

	static protected $instance;

	static public function getInstance() {
		$cn = get_called_class();
		if (!isset(self::$instance[$cn])) {
			$args = func_get_args();
			$class = new ReflectionClass( $cn );
			self::$instance[$cn] = $class->newInstanceArgs($args);
		}
		return self::$instance[$cn];
	}

	// private сделать нельзя из-за public parent конструктора
	// но могу взбрыкнуть хотя бы
	final public function __construct() {
		$cn = get_called_class();
		if (isset(self::$instance[$cn])) {
			throw new CoreException('cannot instance again, use getInstance()', 0);
		}
		$args = func_get_args();
		call_user_func_array(array('parent', __FUNCTION__), $args);
	}

	public function init2(AcePHProxy $app) {
		// getApp есть в plugin
	}

	abstract public function draw();

	abstract public function log($msg, $color = self::CLR_DEFAULT);

	public function error($msg) {
		return $this->log($msg, self::CLR_ERROR);
	}
	public function success($msg) {
		return $this->log($msg, self::CLR_GREEN);
	}


	protected function makePlainStreamsArray($allStreams) {
		// задача - собрать массив трансляций для вывода в UI
		$channels = array();
		foreach ($allStreams as $pid => $one) {
			$stats = $one->getStatistics();
			$isRest = $one->isRestarting();
			$bufColor = self::CLR_GREEN;
			$titleColor = self::CLR_DEFAULT;
			if ($isRest) {
				$bufColor = self::CLR_SPEC1;
				$titleColor = self::CLR_ERROR;
			}
			else if (@$stats['emptydata']) {
				$bufColor = self::CLR_ERROR;
			}
			else if (@$stats['shortdata']) {
				$bufColor = self::CLR_YELLOW;
			}

			$bufLen = round($one->getBufferedLength() / 1024 / 1024) . ' Mb';
			// показываем поочередно размер буфера чтения и размер прочитанного внутреннего буфера
			$buf = time() % 2 ? $one->getBufferSize() : $bufLen;
			$s = iconv('cp866', 'utf8', chr(249)); // значок заполнитель
			$tmp = array(
				// если вместо строки массив: 0 - цвет, 1 - выводимая строка
				0 => array(0 => $titleColor, 1 => $one->getName()),
				1 => array(0 => $bufColor, 1 => $buf),
				2 => $one->getState(),
				3 => @$stats['peers'],
				4 => sprintf('%\'.-7d%\'.6d', @$stats['ul_bytes']/1024/1024, @$stats['dl_bytes']/1024/1024),
				6 => sprintf('%\'.-6d%\'.6d', @$stats['speed_dn'],  @$stats['speed_up'])
			);
			$peers = $one->getPeers();
			if (empty($peers)) {
				$tmp[2] = 'close';
				$channels[] = $tmp;
			}
			else {
				foreach ($peers as $peer => $client) {
					// выводим поочередно то клиента, то его статистику
					// это поле размером 24 символа
					$tmp[5] = round(time() / 0.6) % 2 ? 
						sprintf('%s %d%%', $client->getName(), $client->getPointerPosition()) :
						sprintf('%-13s %8s', $client->getUptime(), $client->getTraffic()) ;
					$channels[] = $tmp;
					$tmp = array(0 => '', '', '', '', '', '', '');
				}
			}
		}

		return $channels;
	}

}

