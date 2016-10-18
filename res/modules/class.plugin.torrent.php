<?php

// обрабатывает ссылки вида
//	/torrent/single/blabla.torrent
//	/torrent/multi/blabla.torrent
//	/torrent/multi/blabla.torrent/2
//	/torrent/playlist.m3u


class AcePlugin_torrent extends AcePlugin_common {
	private $ace;

	protected function init() {
		if (!class_exists('AceConnect')) {
			throw new CoreException('AceConnect class not found, cant work', 0);
		}
		$this->ace = AceConnect::getInstance($this->acestreamkey);
	}

	// косяк был такой! при старте видео XBMC, как известно, делает несколько разных коннектов
	// и тут на каждый запрос вызывается startraw! что хреново! т.к. поток уже запущен и
	// надо просто переоткрыть ссылку от ace с новым Range: bytes=
	// а я думаю, что-то в логе не то, START http:// лишние лезут откуда-то
	public function process(ClientRequest $req) {
		// для пробивочного запроса выдаем заголовки и закрываем коннект
		if ($req->getReqType() == 'HEAD' or ($req->isRanged() and $req->isEmptyRanged())) {
			return $req->response(
				'HTTP/1.1 200 OK' . "\r\n" .
				'Content-Length: 14324133' . "\r\n" . // TODO хедеры от балды, поправить
				'Accept-Ranges: bytes' . "\r\n\r\n"
			);
		}

		// определяем уникальный идентификатор контента
		$pid = $req->getPid();
		$name = $req->getName(); // для мультиторрента это индекс видеофайла

		if (strpos($req->getType(), 'playlist') === 0) {
			$playlist = $this->playlist($req);
			$response = 'HTTP/1.1 200 OK' . "\r\n" .
				'Connection: close' . "\r\n" .
				'Content-Type: text/plain' . "\r\n" .
				'Content-Length: ' . strlen($playlist) . "\r\n" .
				'Accept-Ranges: bytes' . "\r\n\r\n" .
				$playlist;
			return $req->response($response);
		}

		$streamid = $pid;
		if (is_numeric($name)) { // для многосерийных торрентов видимо. name содержит номер видеофайла
			$streamid .= $name;
		}

		$conn = $this->ace->startraw($pid, $name); // TODO refactor, it is NOT name for series
		$conn->setRequestHeaders($req->getHeaders());
		return $req->response($conn, $streamid);
	}


	private function playlist($req) {
		$playlist = array();

		$curFile = $req->getPid();
		if (substr($curFile, -12) !== '.torrent.m3u') { // интересуют только торренты
			$curFile = null;
		} else {
			// xbmc не воспринимает содержимое как плейлист без расширения m3u
			// может еще удастся поиграть и настроить через хедеры или mime
			$curFile = substr($curFile, 0, -4);
		}

		$basedir = $this->basedir;
		$hostport = $req->getHttpHost(true); // true - с портом через двоеточие, если тот есть
		$lib_loaded = class_exists('BDecode');
		// это запрос на чтение содержимого торрент-файла
		if ($lib_loaded and is_file($path = ($basedir . $curFile))) {
			$torrent = new BDecode($path);
			$files = $torrent->result['info']['files'];
			foreach ($files as $idx => $one) {
				$name = implode('/', $one['path']);
				// TODO hostname брать из запроса
				$playlist[$name] = '#EXTINF:-1,' . $name . "\r\n" .
					'http://' . $hostport . '/torrent/multi/' . $curFile . '/' . $idx . "\r\n";
			}
		} else {
			$torList = glob($basedir . '*.torrent');
			foreach ($torList as $one) {
				$basename = basename($one);
				$name = str_replace('.torrent', '', $basename);

				$isMultifiled = false;
				// попробуем декодировать торрент и получить некоторое инфо
				if ($lib_loaded) {
					$torrent = new BDecode($one);
					if (isset($torrent->result['info']['name'])) {
						$name = $torrent->result['info']['name'];
					}
					$files = isset($torrent->result['info']['files']) ? 
						$torrent->result['info']['files'] : array();
					$count = count($files);
					foreach ($files as $f) {
						// отсеем всякие сопутствующие фильмам файлы
						$tmp = implode('/', $f['path']);
						if (in_array(substr($tmp, -4), array('.srt', '.ac3'))) {
							$count--;
						}
					}
					if ($count > 1) {
						$isMultifiled = true;
					}
				}

				// принимаем решение, запускать файл или выдавать как плейлист
				if ($isMultifiled) {
					$playlist[$name] = '#EXTINF:-1,' . $name . "\r\n" .
						'http://' . $hostport . '/torrent/playlist/' . $basename . '.m3u' . "\r\n";
				} else {
					$playlist[$name] = '#EXTINF:-1,' . $name . "\r\n" .
						'http://' . $hostport . '/torrent/single/' . $basename . "\r\n";
				}
			}
		}

		ksort($playlist);
		$playlist =  '#EXTM3U' . "\r\n" . implode("\r\n", $playlist);

		return $playlist;
	}
}

