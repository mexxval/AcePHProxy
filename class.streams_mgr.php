<?php

class StreamsManager {
	const KEEPALIVE_TIME = 3; // sec

	protected $streams = array(); // pid => StreamUnit
	protected $ace;
	protected $pool;
	protected $setup_file;
	protected $ttv_login;
	protected $ttv_psw;
	protected $buffers = array();
	protected $closeStreams = array(); // закрывать будем не сразу, а через время (10sec). pid => puttime

	public function __construct(AceConnect $ace, ClientPool $pool) {
		$this->ace = $ace;
		$this->pool = $pool;
		$this->setup_file = dirname(__FILE__) . '/.acePHProxy.settings';
		$tmp = json_decode(file_get_contents($this->setup_file), true);
		$this->buffers = $tmp['buffers'];
		$this->ttv_login = $tmp['ttv_login'];
		$this->ttv_psw = $tmp['ttv_psw'];
	}
	public function __destruct() {
		file_put_contents($this->setup_file, json_encode(array(
			'buffers' => $this->buffers,
			'ttv_login' => $this->ttv_login,
			'ttv_psw' => $this->ttv_psw,
		)));
	}

	public function getStreams() {
		return $this->streams;
	}

	// отрефакторить это
	protected function log() {
		global $EVENTS;

		$args = func_get_args();
		$EVENTS and call_user_func_array(array($EVENTS, 'log'), $args);
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
		$this->buffers[$pid] = $this->streams[$pid]->getBufferSize();
		$this->streams[$pid]->close();
		unset($this->streams[$pid]);
		$this->log('Closed stream ' . $name);
	}

	public function isExists($pid) {
		return isset($this->streams[$pid]);
	}

	// с какого то хера старт канала идет двумя последовательными запросами
	// Request translation Карусель (410)
	// Got PID  ...
	// Prepare to stop Карусель
	// Request translation Карусель (410)
	// Got PID ...
	// и только потом играет
	public function start(ClientRequest $req) {
		$response = ClientResponse::createResponse($req);

		// короткий реквест типа "запрос-ответ"
		if (!$response->isStream()) {
			return $response->response();
		}

		// многофайловые торренты должны играть с учетом индекса файла
		$pid = $req->getPid();
		$name = $req->getName();
		$type = $req->getType();

		$streamid = $pid;
		if (is_numeric($name)) {
			$streamid .= $name;
		}

		$client = $req->getClient();
		// если трансляции нет, создаем экземпляр, оно запустит коннект ace и сам pid
		if (!isset($this->streams[$streamid])) {
			$bufSize = isset($this->buffers[$streamid]) ? $this->buffers[$streamid] : null;
			$this->streams[$streamid] = new StreamUnit($this->ace, $bufSize, $type);
			$this->streams[$streamid]->setTTVCredentials($this->ttv_login, $this->ttv_psw);
			try {
				#$this->log('Start new PID ' . $pid);
				$this->streams[$streamid]->start($pid, $name);
			}
			catch (Exception $e) {
				// через closeStream может?
				$this->streams[$streamid]->close();
				unset($this->streams[$streamid]);
				// closeStream ?
				$this->log($e->getMessage(), EventController::CLR_ERROR);
				throw $e;
			}
		}
		else { // уже есть и запущено
			$this->streams[$streamid]->unfinish();
		}
		// если мы тут, значит поток либо успешно создан, либо уже был создан ранее

		// удалим из очереди на закрытие
		if (isset($this->closeStreams[$streamid])) {
			$this->log('Cancel stop ' . $this->streams[$streamid]->getName());
			unset($this->closeStreams[$streamid]);
		}

		// регистрируем клиента в потоке
		$this->streams[$streamid]->registerClient($client);
		$response->response();
		return $this->streams[$streamid];
	}

	// вызывается около 33 раз в сек, зависит от usleep в главном цикле
	public function copyContents() {
		// по каждой активной трансляции читаем контент и раскидываем его по ассоциированным клиентам
		// по каждому ace-коннекту читаем его лог, чтобы буфер не заполнял, да и полезно бывает
		foreach ($this->streams as $pid => $one) {
			try {
				$one->copyChunk();
			}
			catch (Exception $e) {
				error_log('StrMgr [E] ' . $e->getMessage());
				#if ($e->getMessage() == 'ace_connection_broken') {
					$this->closeStream($pid);
				#}
			}
		}
		#unset($one);
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


