<?php


class AcePHProxy {
	static private $pool; // clients pool
	static private $mgr; // streams manager
	static private $ui; //
	static private $instance; // main app instance

	private $wwwstate = true; // internet availability state
	private $ctrlC = false;   // track for Ctrl+C event
	private $last_check = 0;  // for internet availability periodic check
	private $startts; // for uptime counter
	private $config;


	static public function getInstance() {
		if (!self::$instance) {
			self::$instance = new AcePHProxy;
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init();
	}

	private function init() {
		$this->startts = time();
		$this->initSettings();
		$this->initCtrlC();
	}
	private function initSettings() {
		$setup_file = __DIR__ . '/../.acePHProxy.settings';
		$savedcfg = json_decode(file_get_contents($setup_file), true);
		$defaultcfg = array(
			'buffers' => array(),
			'listen_ip' => '0.0.0.0',
			'listen_port' => '8001',
			'stream_keepalive_sec' => 5,
			'ui' => array('ncurses'),
		);

		// такое выражение действует так:
		// в результирующий массив попадают все ключи из savedcfg в неизменном виде,
		// плюс те ключи из defaultcfg, которых нет в savedcfg
		$this->config = $savedcfg + $defaultcfg;
	}

	private function initCtrlC() {
		if (!function_exists('pcntl_signal')) {
			$this->error('pcntl function not found. Ctrl+C will not work properly');
		}
		else {
			$this->success('Setting up Ctrl+C');
			declare(ticks=1000);
			pcntl_signal(SIGINT, array($this, '_ctrlC_Handler'));
		}
	}
	public function _ctrlC_Handler() {
		$this->ctrlC = true;
		$this->error('Ctrl+C caught. Exiting');
	}
	public function isCtrlC_Occured() {
		return $this->ctrlC;
	}

	public function __call($m, $a) {
		if (in_array($m, array('log', 'error', 'success'))) {
			if ($m == 'error') {
				error_log($a[0]);
			}
			foreach ($this->getUI() as $one) {
				call_user_func_array(array($one, $m), $a);
			}
		}
	}
	public function getPlugin($type, $fullClassName = false) {
		// название класса плагина, регистр не важен
		$plugin = $fullClassName ? $type : ('AcePlugin_' . $type);
		if (!class_exists($plugin)) {
			throw new CoreException('Plugin type not found', 0, $type);
		}
		$config = isset($this->config[$type]) ? $this->config[$type] : array();
		if (is_callable($cb = array($plugin, 'getInstance'))) {
			return call_user_func_array($cb, array($this, $config));
		}
		return new $plugin($this, $config);
	}

	public function tick() {
		$check_inet = (time() - $this->last_check) > 10; // every N sec
		if ($check_inet) {
			$this->checkInternet();
		}
	
		$pool = $this->getClientPool();
		$streams = $this->getStreamManager();

		try {
			// получаем статистику по новым клиентам, отвалившимся клиентам и запросам контента
			if ($new = $pool->track4new()) {
				foreach ($new['start'] as $peer => $req) {
					$streams->start2($req);
				}

				foreach ($new['new'] as $peer => $_) {
				}
				foreach ($new['done'] as $peer => $_) {
				}

				// быстренько валим на новый цикл (main)
				if ($new['recheck']) {
					return;
				}
			}


			// раскидываем контент по клиентам
			$streams->closeWaitingStreams();
			$streams->copyContents();

			// дергаем метод перерисовки всех UI
			foreach ($this->getUI() as $one) {
				$one->draw();
			}
		}
		catch (Exception $e) {
			$this->error($e->getMessage());
		}
	}

	// метод выдачи некоторой вспомогательной и статистической инфы для вывода в UI
	public function getUIAdditionalInfo() {
		// выведем аптайм и потребляемую память
		$allsec = time() - $this->startts;
		$secs = sprintf('%02d', $allsec % 60);
		$mins = sprintf('%02d', floor($allsec / 60 % 60));
		$hours = sprintf('%02d', floor($allsec / 3600));
		$mem = memory_get_usage(); // bytes
		$mem = round($mem / (1024 * 1024), 1); // MBytes

		$pool = $this->getClientPool();
		$addinfo = array(
			'ram' => $mem,
			'uptime' => "$hours:$mins:$secs",
			'title' => ' AcePHProxy v.' . ACEPHPROXY_VERSION . ' ',
			'port' => $pool->getPort(),
			'wwwok' => $this->wwwstate
		);
		return $addinfo;
	}


	// в соответствии с конфигом инстанцируем нужные модули UI
	private function getUI() {
		if (is_null(self::$ui)) {
			self::$ui = array();

			// ищем все загруженные модули интерфейсов и инстанцируем
			$list = get_declared_classes();
			$cfgui = $this->config['ui']; // интерфейсы из конфига, произвольный регистр
			$regexp = '~(' . implode('|', $cfgui) . ')~i';

			foreach ($list as $className) {
				if (preg_match($regexp, $className)) {
					try {
						// try-catch, ибо ncurses например может взбрыкнуть,
						// если расширение в пхп не загружено
						// все UI - синглтоны
						if (is_a($className, 'AppUserInterface', true)) {
							$tmp = $this->getPlugin($className, true);
						} else {
							throw new CoreException($className . ' is not a AppUserInterface class', 0);
						}
					} catch (Exception $e) {
						$this->error($e->getMessage());
						continue;
					}
					self::$ui[] = $tmp;
					$tmp->init2($this);
				}
			}
		}
		return self::$ui;
	}



	public function __destruct() {
		// тормозим все трансляции, закрываем сокеты Ace
		$this->getStreamManager()->closeAll();
	}

	// а еще чтобы не долбить коннектами, можно его куда нить открыть и держать. 
	// опыт xbmc клиента правда говорит, что это мб весьма ненадежно.. зато реалтайм
	private function checkInternet() {
		$tmp = $this->wwwstate; // для определения смены состояния

		// делаем 2-3 попытки коннекта для проверки инета
		$cyc = 3;
		while (!($fp = @stream_socket_client('tcp://8.8.8.8:53', $e, $e, 0.15, STREAM_CLIENT_CONNECT)) and $cyc-- > 0);
		$res = (bool) $fp;
		$fp and fclose($fp);

		$this->last_check = time();
		$wwwChanged = $tmp != $this->wwwstate;
		if ($wwwChanged) {
			$this->getClientPool()->notify($this->wwwstate ? 'Интернет восстановлен' : 'Интернет упал');
		}
		return $res;
	}

	private function getClientPool() {
		// создает сокет сервера трансляций и управляет коннектами клиентов к демону
		if (!self::$pool) {
			self::$pool = new ClientPool($this->config['listen_ip'], $this->config['listen_port']);
		}
		return self::$pool;
	}

	// пришлось сделать public для AcePlugin_common::getStreams()
	// для AppUserInterface::init() тоже пригодилось
	public function getStreamManager() {
		// управляет трансляциями. заказывает их у Ace и раздает клиентам из pool
		if (!self::$mgr) {
			self::$mgr = new StreamsManager($this);
			self::$mgr->setKeepaliveTime($this->config['stream_keepalive_sec']);
		}
		return self::$mgr;
	}
}


