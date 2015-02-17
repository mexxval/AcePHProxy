<?php

class StreamsManager {
	const KEEPALIVE_TIME = 3; // sec

	protected $streams = array(); // pid => clients
	protected $ace;
	protected $pool;
	protected $setup_file;
	protected $closeStreams = array(); // закрывать будем не сразу, а через время (10sec). pid => puttime

	public function __construct(AceConnect $ace, ClientPool $pool) {
		$this->ace = $ace;
		$this->pool = $pool;
		$this->setup_file = dirname(__FILE__) . '/.acePHProxy.settings';
		$this->buffers = json_decode(file_get_contents($this->setup_file), true);
	}
	public function __destruct() {
		file_put_contents($this->setup_file, json_encode($this->buffers));
	}

	public function getStreams() {
		return $this->streams;
	}

	// отрефакторить это
	protected function log() {
		global $EVENTS;

		$args = func_get_args();
		call_user_func_array(array($EVENTS, 'log'), $args);
	}

	// xbmc странно делает, при зависании закрывает коннект и открывает новый с offset-ом, 
	// при этом я успеваю отдать хедеры, но в итоге xbmc останавливает поток
	// может ему что то в хедерах не нравится
	protected function markStream4Close($pid) {
		$this->closeStreams[$pid] = time();
	}

	protected function closeStream($pid) {
		if (!isset($this->streams[$pid])) {
			return false; // o_O
		}
		$name = $this->streams[$pid]->getName();
		// обновим значение буфера
		$this->buffers[$pid] = $this->streams[$pid]->getBuffer();
		$this->streams[$pid]->close();
		unset($this->streams[$pid]);
		$this->log('Closed stream ' . $name);
	}

	public function isExists($pid) {
		return isset($this->streams[$pid]);
	}

	public function start($pid, $name, $type = 'pid') {
		if ($type == 'trid') { // pid в этом случае это trid, надо найти pid на сайте
			$this->log('Request translation ' . $name . ' (' . $pid . ')');
			try {
				$tmp = $this->parse4PID($pid);
			}
			catch (Exception $e) {
				$this->torrentAuth();
				$tmp = $this->parse4PID($pid);
			}
			// tmp использовалась, чтобы pid не попортить раньше времени
			$pid = $tmp;
		}
		else {
			$this->log('Request channel ' . $name);
		}

		// если трансляции нет, создаем экземпляр, оно запустит коннект ace и сам pid
		if (!isset($this->streams[$pid])) {
			$bufSize = isset($this->buffers[$pid]) ? $this->buffers[$pid] : null;
			$this->streams[$pid] = new StreamUnit($this->ace, $bufSize);
			try {
				$this->log('Start new PID ' . $pid);
				$this->streams[$pid]->start($pid, $name);
			}
			catch (Exception $e) {
				$this->streams[$pid]->close();
				unset($this->streams[$pid]);
				// closeStream ?
				$this->log($e->getMessage(), EventController::CLR_ERROR);
				throw $e;
			}
		}
		else { // уже есть и запущено
			$this->log('Existing PID ' . $pid);
			$this->streams[$pid]->unfinish();
		}
		// если мы тут, значит поток либо успешно создан, либо уже был создан ранее

		// удалим из очереди на закрытие
		if (isset($this->closeStreams[$pid])) {
			unset($this->closeStreams[$pid]);
		}
		// $this->streams[$pid]->registerClient($client);
		return $this->streams[$pid];
	}

	// вызывается около 33 раз в сек, зависит от usleep в главном цикле
	public function copyContents() {
		// по каждой активной трансляции читаем контент и раскидываем его по ассоциированным клиентам
		// по каждому ace-коннекту читаем его лог, чтобы буфер не заполнял, да и полезно бывает
		foreach ($this->streams as $one) {
			$one->copyChunk();
		}
		unset($one);
	}

	protected function torrentAuth() {
		$this->log('Authorizing on torrent');
		// base init
		$curl = curl_init();
		$url = "http://torrent-tv.ru/auth.php";
		curl_setopt_array($curl, array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_POST           => 1,
			CURLOPT_POSTFIELDS     => 'email=<emaaail))>&password=<paroooole>&enter=Войти',
			CURLOPT_COOKIEFILE => './cookie_ttv.txt',
			CURLOPT_COOKIEJAR =>  './cookie_ttv.txt',
		));
		$res = curl_exec($curl);
		if (strpos($res, 'cabinet.php') === false) {
			throw new Exception('Login failed');
		}
		return true;
	}
	protected function parse4PID($trid) {
		$this->log('Searching PID');
		// base init
		$curl = curl_init();
		$url = "http://torrent-tv.ru/torrent-online.php?translation=" . $trid;
		curl_setopt_array($curl, array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_COOKIEFILE => './cookie_ttv.txt',
			CURLOPT_COOKIEJAR =>  './cookie_ttv.txt',
		));

		$res = curl_exec($curl);
		$isLoggedIn = preg_match('~Мой кабинет~', $res);
		if (!preg_match('~loadPlayer\("([a-f0-9]{40})"~smU', $res, $m)) {
			throw new Exception('loadPlayer+PID not matched. ' . ($isLoggedIn ? 'Is' : 'Not') . ' logged in');
		}

		$this->log('Got PID ' . $m[1]);
		return $m[1];
	}

	public function closeAll() {
		foreach ($this->closeStreams as $pid => $time) {
			$this->closeStream($pid);
		}
		foreach ($this->streams as $pid => $peers) {
			$this->closeStream($pid);
		}
	}

	public function closeWaitingStreams() {
		// mark finished streams
		foreach ($this->streams as $pid => $one) {
			if ($one->isFinished() and !isset($this->closeStreams[$pid])) {
				$this->log('Prepare to stop ' . $one->getName());
				$this->markStream4Close($pid);
			}
		}
		unset($one);

		// потоки, помеченные для закрытия, закрываем по достижении таймаута
		foreach ($this->closeStreams as $pid => $time) {
			if (time() - $time > self::KEEPALIVE_TIME) { // 15 sec to close
				$this->closeStream($pid);
				unset($this->closeStreams[$pid]);
			}
		}
	}
}

