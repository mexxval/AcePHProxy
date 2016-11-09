<?php

class StreamsManager {
	protected $keepalive_time = 3; // default, sec
	protected $streams = array(); // pid => StreamUnit
	protected $closeStreams = array(); // закрывать будем не сразу, а через время (10sec). pid => puttime
	protected $app;

	// app для логирования в основном
	public function __construct(AcePHProxy $parent) {
		$this->app = $parent;
	}
	public function getStreams() {
		return $this->streams;
	}
	public function isExists($pid) {
		return isset($this->streams[$pid]);
	}

	public function setKeepaliveTime($sec) {
		$this->keepalive_time = $sec;
	}


	// метод обработки запроса пользователя. основная точка входа!
	public function start2(ClientRequest $req) {
		// получаем первую часть request uri (/pid, /torrent, /trid, /acelive...)
		// она будет указывать на плагин, необходимый для обработки запроса
		$pcode = $req->getPluginCode();
		// скармливаем плагину запрос юзера
		// на выходе ожидаем ответ ClientResponse, из которого будет ясно, быстрый это запрос или медленный
		// плагин при процессинге определяет, надо ли запускать поток
		// инфо получаем по ссылке
		try {
			$client = $req->getClient();
			$plugin = $this->app->getPlugin($pcode);
			$response = $plugin->process($req);

			// плагины мб разной степени кривизны, в т.ч. могут вернуть неверный объект или null/false
			if (!is_a($response, 'ClientResponse')) {
				throw new CoreException('Wrong response from plugin ' . $pcode, 0);
			}

			// если требуется запуск, смотрим по id, не запущено ли уже
			if ($response->isStream()) {
				$streamid = $response->getStreamId();
				// если трансляции нет, создаем экземпляр
				if (!$this->isExists($streamid)) {
					$this->app->log(sprintf('Start new %s-stream ', $response->getPluginCode()));
					// просим плагин запустить поток. вся логика ace, file, torrent, web, soap - на стороне плагинов
					// но создавать самостоятельно новые потоки плагин не может. надо тут проверить, не запущен ли уже такой streamid
					$this->streams[$streamid] = new StreamUnit($response->getStreamResource());
				}
				else { // уже есть и запущено
					#error_log('Stream exists');
					$this->streams[$streamid]->unfinish();
				}
				// если мы тут, значит поток либо успешно создан, либо уже был создан ранее

				// удалим из очереди на закрытие
				if (isset($this->closeStreams[$streamid])) {
					$this->app->log('Cancel stop ' . $this->streams[$streamid]->getName());
					unset($this->closeStreams[$streamid]);
				}

				// регистрируем клиента в потоке
				#error_log('Register client in stream');
				$this->streams[$streamid]->registerClient($client);
				// на клиента ничего не пишем, ответные заголовки будут потом
			} else {
				$respContents = $response->getContents();
				$client->put($respContents);
				$client->close();
			}
		}
		catch (Exception $e) {
			$client->close();
			if (!empty($streamid)) {
				$this->closeStream($streamid);
				$client->notify('Start error: ' . $e->getMessage(), 'error');
			}
			$this->app->error($e->getMessage());
			return false;
		}

		return ;
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
				// TODO какого рода тут ошибка и почему раньше стрим закрывался только по ошибке ace_connection_broken - ХЗ
				error_log($e->getMessage());
				$this->app->error('StrMgr [E] ' . $e->getMessage());
				#if ($e->getMessage() == 'ace_connection_broken') {
					$this->closeStream($pid);
				#}
			}
		}
	}



	public function closeAll() {
		foreach ($this->closeStreams as $pid => $time) {
			$this->closeStream($pid);
		}
		foreach ($this->streams as $pid => $peers) {
			$this->closeStream($pid);
		}
	}

	// xbmc странно делает, при зависании закрывает коннект и открывает новый с offset-ом, 
	// при этом я успеваю отдать хедеры, но в итоге xbmc останавливает поток
	// может ему что то в хедерах не нравится
	protected function markStream4Close($pid) {
		$this->closeStreams[$pid] = time();
	}

	public function closeWaitingStreams() {
		// mark finished streams
		foreach ($this->streams as $pid => $one) {
			if ($one->isFinished() and !isset($this->closeStreams[$pid])) {
				$this->app->log('Prepare to stop ' . $one->getName());
				$this->markStream4Close($pid);
			}
		}

		// потоки, помеченные для закрытия, закрываем по достижении таймаута
		foreach ($this->closeStreams as $pid => $time) {
			if (time() - $time > $this->keepalive_time) { // 15 sec to close
				$this->closeStream($pid);
				unset($this->closeStreams[$pid]);
			}
		}
	}

	protected function closeStream($pid) {
		if (!$this->isExists($pid)) {
			return false; // o_O
		}
		$name = $this->streams[$pid]->getName();
		$this->streams[$pid]->close();
		unset($this->streams[$pid]);
		$this->app->log('Closed stream ' . $name);
	}
}


