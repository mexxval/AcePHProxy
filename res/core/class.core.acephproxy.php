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
		$this->initCtrlC();
		$this->initSettings();
	}
	private function initSettings() {
		$this->config = new stdClass;
		$this->config->listen_ip = '0.0.0.0';
		$this->config->listen_port = 8001;
		$this->config->stream_keepalive_sec = 5;

		$setup_file = __DIR__ . '/../.acePHProxy.settings';
		$cfg = json_decode(file_get_contents($setup_file), true);
	}
		/*
		public function __destruct() {
			file_put_contents($this->setup_file, json_encode(array(
				'buffers' => $this->buffers,
				'ttv_login' => $this->ttv_login,
				'ttv_psw' => $this->ttv_psw,
			)));
		}*/

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
			$ui = $this->getUI();
			return $ui ? call_user_func_array(array($ui, $m), $a) : null;
		}
	}
	public function getPlugin($type) {
		$plugin = 'AcePlugin_' . $type; // название класса плагина, регистр не важен
		if (!class_exists($plugin)) {
			throw new CoreException('Plugin type not found', 0, $type);
		}
		// TODO уметь и синглтоны
		$config = array(); // TODO
		return new $plugin($config);
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
				unset($info, $req, $client); // обязательно. ибо лишние object-ссылки

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

			// выведем аптайм и потребляемую память
			$allsec = time() - $this->startts;
			$secs = sprintf('%02d', $allsec % 60);
			$mins = sprintf('%02d', floor($allsec / 60 % 60));
			$hours = sprintf('%02d', floor($allsec / 3600));
			$mem = memory_get_usage(); // bytes
			$mem = round($mem / (1024 * 1024), 1); // MBytes

			$addinfo = array(
				'ram' => $mem,
				'uptime' => "$hours:$mins:$secs",
				'title' => ' AcePHProxy v.' . ACEPHPROXY_VERSION . ' ',
				'port' => $pool->getPort(),
				'wwwok' => $this->wwwstate
			);
			$UI = $this->getUI();
			$UI and $UI->draw($streams->getStreams(), $addinfo);
		}
		catch (Exception $e) {
			$this->error($e->getMessage());
		}
	}


	// получаем какой нибудь интерфейс (текстовый, ncurses или вообще никакой, можно и без него работать)
	// public только для StreamManager
	private function getUI() {
		// return null;
		if (is_null(self::$ui)) {
			// default UI type
			$UICLASS = 'AppUI_Text';
			if (function_exists('ncurses_init') and 1) {
				$UICLASS = 'AppUI_NCurses';
			}
			self::$ui = new $UICLASS;
			self::$ui->init();
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
			self::$pool = new ClientPool($this->config->listen_ip, $this->config->listen_port);
		}
		return self::$pool;
	}

	private function getStreamManager() {
		// управляет трансляциями. заказывает их у Ace и раздает клиентам из pool
		if (!self::$mgr) {
			self::$mgr = new StreamsManager($this);
			self::$mgr->setKeepaliveTime($this->config->stream_keepalive_sec);
		}
		return self::$mgr;
	}
}


