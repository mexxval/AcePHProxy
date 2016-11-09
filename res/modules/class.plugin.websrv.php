<?php

/**
 * вспомогательный плагин для отдачи статических файлов по http
 * годится для построения веб-интерфейса или отдачи rss
TODO
- требуется поддержка выдачи файлов по HTTP/1.1, поддержка Range: bytes=

 */

class AcePlugin_websrv extends AcePlugin_common {
	private $servicedir;

	protected function init() {
		$this->servicedir = realpath($path = __DIR__ . '/../websrv/');
		if (!is_dir($this->servicedir)) {
			$this->getApp()->error('Path not exists: ' . $path);
		}
	}


	// метод возвращает текст ответа клиенту (с хедерами), а по ссылке массив инфы о потоке/запросе
	public function process(ClientRequest $req) {
		try {
			$filepath = $this->getFilePath($req);
		} catch (Exception $e) {
			$this->getApp()->error($e->getCode() . ': ' . $req->getUri());
			return $req->response($this->returnCode($e->getCode()));
		}

		// если GET - выдаем статичный файл
		if ($req->getReqType() == 'GET') {
			$tpl = $this->getFilePath($req);
			// если файл html или php - подключаем его как шаблон, в нем допустимы php-тэги
			$ext = substr($tpl, strrpos($tpl, '.') + 1);
			if (in_array($ext, array('html', 'php'))) {
				$TPLDATA = $this->getApp()->getUIAdditionalInfo();
				$TPLDATA['ipport'] = sprintf('%s:%s', $req->getServerHost(), $TPLDATA['port']);
				$STREAMS = $this->getStreams();
				ob_start();
				include $tpl; // это может быть и 1Мб-ный minified-JS
				$tmpfname = tempnam(sys_get_temp_dir(), "AcePHProxyTemp_");
				file_put_contents($tmpfname, ob_get_clean());
				$tpl = $tmpfname;
			}

			$streamid = md5($filepath . uniqid());
			return $req->response(new StreamResource_file($tpl), $streamid);
		}

		// === TODO ==== implement
		$return = '';
		if ($req->getReqType() == 'POST' and strpos($file, '.php') !== false) { // POST запрос к файлу делаем
			$rawpost = $req->getContent();
		}


		error_log($req->getReqType());
		$response = 'HTTP/1.1 200 OK' . "\r\n" .
			$return;
		error_log('RESPONSE ' . $response);
		return $req->response($response);
	}



	private function getFilePath(ClientRequest $req) {
		$file = $req->getUri(); // полный путь, начиная с /websrv, раз уж обрабатывается этим плагином
		// /websrv надо откусить
		$file = substr($file, 7);
		// query string тоже откусить
		$pos = strpos($file, '?') and $file = substr($file, 0, $pos);
		// а вот дальше надо путь обработать методом-антихакером
		$filepath = realpath($this->servicedir . $file);
		// если в получившемся пути нет root-папки - возможно нас пытались хакнуть через ../../
		if (strpos($filepath, $this->servicedir) !== 0) {
			throw new Exception('Intrusion attempt', 403);
		}
		if (!is_file($filepath)) {
			throw new Exception('File not found', 404);
		} 
		return $filepath;
	}

	private function returnCode($code) {
		switch ($code) {
			case 403:
				$response = 'HTTP/1.1 403 Forbidden' . "\r\n" .
					'Connection: close' . "\r\n" .
					"\r\n";
				break;
			case 404:
				$response = 'HTTP/1.1 404 Not Found' . "\r\n" .
					'Connection: close' . "\r\n" .
					"\r\n";
				break;
			default:
				$response = 'HTTP/1.1 204 No Content' . "\r\n" .
					'Connection: close' . "\r\n" .
					"\r\n";
				break;
		}
		return $response;
	}
}

