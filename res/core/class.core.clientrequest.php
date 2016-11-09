<?php

// пример ссылки, по которой может прийти клиент
// http://sci-smart.ru:8000/pid/43b12325cd848b7513c42bd265a62e89f635ab08/Russia24
class ClientRequest {
	protected $start; // сюда запишем, что клиент запросил
	protected $req;
	protected $client;


	public function __construct($data, $client) {
		// error_log('client send: ' . "\n\t" . str_replace("\n", "\n\t", $data));
		$this->req = $data;
		$this->client = $client;
		$this->start = $this->parse($this->req);
		// error_log('construct request ' . spl_object_hash ($this));
	}
	// переопределенное имя потока или индекс видеофайла (нехорошо такое смешивать)
	public function getName() {
		return $this->start['uriName'];
	}
	public function getPluginCode() {
		return $this->start['plugin'];
	}
	// <pid> или <aceliveid> или имя торрент-файла
	public function getPid() {
		return $this->start['uriAddr'];
	}
	// возвращает тип запрошенного контента, acelive, trid, pid, torrent, file
	public function getType() {
		return $this->start['uriType'];
	}
	// raw post data
	public function getContent() {
		return $this->start['reqContent'];
	}
	public function getClient() {
		return $this->client;
	}
	// в каком виде? явно строка, но включен ли двойной перенос в конце?
	public function getHeaders() {
		return $this->req;
	}
	// все после GET и до HTTP/1.x, request uri в общем
	public function getUri() {
		return $this->start['reqUri'];
	}
	public function getUserAgent() {
		return $this->start['UA'];
	}
	// GET POST HEAD OPTIONS SUBSCRIBE etc
	public function getReqType() {
		return $this->start['reqType'];
	}
	// есть ли заголовок Range: bytes=x-x
	public function isRanged() {
		return !is_null($this->start['range']);
	}
	public function isEmptyRanged() {
		return $this->start['range'] === array('from' => 0, 'to' => 0);
	}
	public function getReqRange() {
		return $this->start['range'];
	}
	public function getHttpHost($withPort = true) {
		$host = $this->start['reqHost'];
		if (!$withPort) {
			$host = explode(':', $host);
			$host = reset($host);
		}
		return $host;
	}
	// на какой интерфейс пришло обращение
	// (заголовки можно и подделать, но как определить на сервере не нашел)
	// кстати там может быть как dns-имя, так и ip
	public function getServerHost() {
		return $this->getHttpHost(false);
	}

	public function addData() {
		error_log('TODO ' . __METHOD__);
	}

	// немного упрощает написание выдачи ответов в плагинах
	public function response($contents = null, $streamid = null) {
		return new ClientResponse($this, $contents, $streamid);
	}


	// TODO тут не дб логики обработки и кидания исключений
	// только разбор заголовков и выдача их в удобном виде
	// а кто чего попросил запустить это в acePHP.php стоит решать
	protected function parse($sock_data) {
		$firstLine = trim(substr($sock_data, 0, strpos($sock_data, "\n")));
		preg_match('~HTTP/1\..*\r?\n\r?\n(.*)~sm', $sock_data, $content);
		$result = array(
			'reqType' => substr($firstLine, 0, $space = strpos($firstLine, ' ')), // от начала до первого пробела (GET/HEAD/etc)
			'reqUri' => substr($firstLine, $space + 1, ($rspace = strrpos($firstLine, ' ')) - $space - 1), // /ttv/trid/123/title
			'reqProto' => substr($firstLine, $rspace + 1),	// HTTP/1.x
			'range' => preg_match('~Range: bytes=(\d+)-(\d+)?~sm', $sock_data, $m) ?
				array('from' => $m[1], 'to' => @$m[2]) : null,
			'reqHost' => preg_match('~host: ([^\s]*)~smi', $sock_data, $m) ? $m[1] : null,
			'UA' => preg_match('~user-agent: ([^\n]+)~smi', $sock_data, $m) ? $m[1] : null,
			'reqContent' => empty($content[1]) ? null : $content[1] // тело запроса (для POST)
		);

		// немного дополним инфо о запросе, разобрав reqUri
		// обычно запрос состоит из 3 частей: тип, адрес и название. /pid/blablabla/name
		// UPD: теперь запрос состоит из указателя на плагин, типа контента, id и названия
		//	/ttv/trid/390/2x2
		//  /torrent/seriesname_s01.torrent/2/Episode name
		$uriInfo = array();
		$uri = $result['reqUri'];
		$tmp = explode('/', $uri);
		// @ расставлены, чтобы кривые урлы в лог ошибки не генерили
		$uriInfo['plugin'] = @$tmp[1];		// getPluginCode() между первым и вторым слешами
		$uriInfo['uriType'] = urldecode(@$tmp[2]); // getType() между вторым и третьим слешами
		$uriInfo['uriAddr'] = urldecode(@$tmp[3]); // getPid() decode, торрент файл может содержать спецсимволы ([ ] пробелы и т.д.)
		// getName() название торрента - необязательный параметр. скоро через LOADASYNC получать будем
		$uriInfo['uriName'] = isset($tmp[4]) ? urldecode($tmp[4]) : '';

		// types:
		// pid - start by PID
		// acelive, trid - start by translation ID (http://torrent-tv.ru/torrent-online.php?translation=?)
		// torrent - start torrent file

		// такая штука. если клиент медленный, он может выдать GET запрос за первый проход,
		// а остальные хедеры за последующие. в связи с чем по первому принятому запросу
		// запускаем поток, а по следующему кидаем этот эксепшен и клиента кикает
		// upd: жду от клиента полных заголовков, и только потом обрабатываю. вернул исключенеи
		#throw new Exception('Unknown request', 15);
		return $result + $uriInfo;
	}
	public function __destruct() {
		 // error_log(' destruct request ' . spl_object_hash ($this));
	}
}

