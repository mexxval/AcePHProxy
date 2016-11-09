<?php

class AcePlugin_ttv extends AcePlugin_common {
	private $ace;

	protected function init() {
		if (!class_exists('AceConnect')) {
			throw new CoreException('AceConnect class not found, cant work', 0);
		}

		// создаем коннект к acestream, запускаем клиентский сокет
		$this->ace = AceConnect::getInstance($this->acestreamkey);
	}

	// метод должен вернуть инфу по запросу и объект ответа, если запрос не предполагает запуска потока
	public function process(ClientRequest $req) {
		// для пробивочного запроса выдаем заголовки и закрываем коннект
		if ($req->getReqType() == 'HEAD' or ($req->isRanged() and $req->isEmptyRanged())) {
			return $req->response(
				'HTTP/1.1 200 OK' . "\r\n" .
				'Content-Length: 14324133' . "\r\n" . // TODO хедеры от балды, поправить
				'Accept-Ranges: bytes' . "\r\n\r\n"
			);
		}


		$type = $req->getType();
		$pid = $req->getPid();
		$tmp = null;

		// передаем также request headers клиента
		switch ($type) {
			case 'pid':
				$conn = $this->ace->startpid($pid);
				break;
			case 'trid':
				try {
					$tmp = $this->parse4PID($pid);
				}
				catch (Exception $e) {
					// рефакторить на нормальные классы, коды ошибок и убрать копипаст parse4PID!!
					// error_log($e->getMessage());
					if (stripos($e->getMessage(), 'curl') === 0) {
						throw new Exception('Torrent tv timed out');
					}
					$this->torrentAuth();
					$tmp = $this->parse4PID($pid);
				}
				// tmp использовалась, чтобы pid не попортить раньше времени
				$pid = $tmp;
			case 'acelive':
				if ($type == 'acelive') {
					$pid = sprintf('http://content.asplaylist.net/cdn/%d_all.acelive', $pid);
				}
			default:
				$conn = $this->ace->starttorrent($pid);
		}
		$conn->setRequestHeaders($req->getHeaders());

		// определяем уникальный идентификатор контента
		$streamid = $req->getPid();
		return $req->response($conn, $streamid);
	}


	protected function torrentAuth() {
		error_log('Authorizing on torrent');
		$url = "http://torrent-tv.ru/auth.php";
		$opts = array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => 'email=' . urlencode($this->ttv_login) . '&password=' . 
				urlencode($this->ttv_psw) . '&enter=' . urlencode('Войти'),
		);
		$res = $this->makeRequest($url, $opts);

		if (!preg_match('~"Refresh".*URL="cabinet\.php"~', $res)) {
			throw new Exception('Login failed');
		}
		return true;
	}
	protected function parse4PID($trid) {
		$url = "http://torrent-tv.ru/torrent-online.php?translation=" . $trid;
		$res = $this->makeRequest($url);
		$isLoggedIn = preg_match('~/exit\.php~', $res);
		// PID на сайте больше нет. http://content.torrent-tv.ru/cdn/31_all.acelive
		// this.loadTorrent("http://content.torrent-tv.ru/cdn/31_all.acelive",{autoplay: true});
		$pattern = '~loadPlayer\("([a-f0-9]{40})"~smU';
		$pattern = '~loadTorrent\("([^"]+)",~sm';
		if (!preg_match($pattern, $res, $m)) {
			throw new Exception('Stream ID not matched. ' . ($isLoggedIn ? 'Is' : 'Not') . ' logged in');
		}

		return $m[1];
	}
	private function makeRequest($url, $addCurlOptions = array(), $tryAntiban = true) {
		$rnd = rand(1000, 10000);
		$httpHeaders = array(
			'Origin: http://torrent-tv.ru',
			'Referer: http://torrent-tv.ru',
			'User-Agent: Mozilla/5.0'
		);

		// base init
		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL => $url,
			CURLOPT_VERBOSE => false,
			CURLOPT_HEADER => false,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_0,
			CURLOPT_COOKIEFILE => __DIR__ . '/../cookie_ttv.txt',
			CURLOPT_COOKIEJAR =>  __DIR__ . '/../cookie_ttv.txt',
			#CURLOPT_STDERR => fopen('/tmp/ttvcurl_' . $rnd, 'w'),
			CURLOPT_HTTPHEADER => $httpHeaders,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_TIMEOUT => 10 // чем больше тут секунд, тем дольше висит UI при факапе на стороне ttv.ru. форкаться чтоль..
		));
		curl_setopt_array($curl, $addCurlOptions);
		$res = curl_exec($curl);

		$isTimeout = ($curlErrno = curl_errno($curl)) == 28;
		if ($isTimeout) {
			throw new Exception('Curl timeout');
		} else if ($curlErrno !== 0) {
			throw new Exception('Curl error ' . $curlErrno);
		}

		// torrent-tv внедрил защиту от ботов, но мы ее обойдем
		if ($tryAntiban and strpos($res, 'banhammer/pid') !== false) { // защита активировалась
			error_log('Enabling antiban...');
			// надо запросить у банхаммера заголовок X-BH-Token
			// для чего просто отправляем на /banhammer/pid get-запрос
			$addHeader = array(CURLOPT_HEADER => true);
			$res = $this->makeRequest('http://torrent-tv.ru/banhammer/pid', $addHeader, false);
			// ищем header X-BH-Token со значением вроде IUMjlVubjDlQJZVIctmuLmVuPIU=_22142957038
			if (preg_match('~X\-BH\-Token:\s?([^\s]+)[\s$]~smU', $res, $m)) {
				$token = $m[1];
				error_log('Found antiban token');
				// нашли токен, повторяем изначальный запрос, но с установкой куки
				$addCurlOptions[CURLOPT_COOKIE] = 'BHC=' . urlencode($token);
				$res = $this->makeRequest($url, $addCurlOptions, false);
			}
			else {
				throw new Exception('Banhammer crack failed');
			}
		}
		return $res;
	}
}

