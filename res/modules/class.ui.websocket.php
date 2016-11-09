<?php

/**
 * реалтайм вебсокетный UI для вебморды, возможно и CSS3D/WebGL
 * TODO Этот UI, вообще как и любой другой, должен быть синглтоном
 */
class AcePlugin_WebSocket extends AppUI_common {

	static private $sockets = array();
	private $lastupdated;

	public function process(ClientRequest $req) {
		$headers = array_map('trim', explode("\n", trim($req->getHeaders())));
		$key = null;
		foreach ($headers as $line) {
			if (strpos($line, 'Sec-WebSocket-Key') === false) {
				continue;
			}
			$key = trim(substr($line, 18));
			break;
		}
		if (empty($key)) {
			return false;
		}

		// создаем сокет/поток, куда мы можем писать данные,
		// которые в итоге пойдут на вебстраницу клиента и будут разобраны в JS
		$client = $req->getClient();
		$client->registerEventListener(array($this, 'clientEventListener'));
		$socket = new StreamResource_ws($client, $key);
		self::$sockets[$client->getName()] = $socket;
		return $req->response($socket, $key);
	}

	public function clientEventListener($client, $event) {
		if (isset($event['event'])) {
			switch ($event['event']) {
				case 'close': // client disconnected
					$this->closeClient($client);
					break;
			}
		}
		if (isset($event['moredata'])) {
			$socket = self::$sockets[$client->getName()];
			if ($socket) {
				$gotpacket = $socket->decode($event['moredata']);
				// error_log('WS got: ' . json_encode($gotpacket));
				if (isset($gotpacket['type']) and $gotpacket['type'] == 'close') {
					$this->closeClient($client);
				}
			}
		}
	}

	private function closeClient(StreamClient $client) {
		if (isset(self::$sockets[$client->getName()])) {
			// error_log('closing WS for client ' . $client->getName());
			self::$sockets[$client->getName()]->close();
			unset(self::$sockets[$client->getName()]);
		}
	}

	public function init() {
	}

	// по всем клиентам вебсокета надо раздать данные (потоки, статистика)
	public function draw() {
		//error_log('draw ' . microtime(1));
		if (rand(0, 10) < 10) { // do not redraw so often
			# return;
		}
		// сделаем так.. будем замерять время и обновлять интерфейс раз в 100мс
		if ((microtime(1) - $this->lastupdated) < 0.3) {
			return;
		}
		$this->lastupdated = microtime(1);

		$uidata = array(
			'stats' => $this->getApp()->getUIAdditionalInfo(),
			'streams' => $this->makePlainStreamsArray($this->getStreams())
		);
		$this->put($uidata);
	}

	private function put($uidata) {
		// уведомляем все сокеты об обновлениях UI
		// заодно проверим и выкинем отвалившихся клиентов
		// здесь проверяем каждого на isFinished,
		// но вообще callback под это надо завести
		// см. метод put
		foreach (self::$sockets as $socket) {
			$socket->put($uidata);
		}
	}

	public function log($msg, $color = null) {
		$this->put($msg);
	}



	private function makePlainStreamsArray($allStreams) {
		// задача - собрать массив трансляций для вывода в UI
		$channels = array();
		foreach ($allStreams as $pid => $one) {
			$channels[$pid] = array(
				'streamid' => $pid,
				'isLive' => $one->isLive(),
				'type' => $one->getType(),
				'statistics' => $one->getStatistics(),
				'isRestarting' => $one->isRestarting(),
				'title' => $one->getName(),
				'buffer' => $one->getBufferSize(),
				'state' => $one->getState(),
				'bufferedLength' => $one->getBufferedLength(),
				'bufferMaxLength' => $one->getBufferLength(),
				'clients' => array(),
			);

			$peers = $one->getPeers();
			if (empty($peers)) {
				$channels[$pid]['state'] = 'close';
			}

			foreach ($peers as $peer => $client) {
				$channels[$pid]['clients'][$peer] = array(
					'isEcoMode' => $client->isEcoMode(),
					'isEcoModeRunning' => $client->isEcoModeRunning(),
					'ptrPosition' => $client->getPointerPosition(),
					'uptime' => $client->getUptime(),
					'traffic' => $client->getTraffic(),
					'clienttype' => strtolower($client->getType()),
				);
			}
		}

		return $channels;
	}


}


