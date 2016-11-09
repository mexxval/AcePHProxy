<?php


class AceConn implements AppStreamResource {
	const STATE_HDRREAD = 0x03; // начало чтения заголовков потока
	const STATE_HDRSENT = 0x04; // Потоковая передача, все подготовлено, и пошла собственно выдача видео
		# ручной разбор chunked потоков пришлось вернуть, ибо самсунг тв не умеет их декодировать
		# из-за чего нормальный просмотр ТВ на нем невозможен
		const STATE_CHUNKWAIT = 0x05; // ожидание длины чанка, аналог _HDRSENT, но для Chunked потока
		const STATE_CHUNKREAD = 0x06; // чтение чанка на полученную длину
	const STATE_ERROR = 0x08;

	protected $state = null;
	protected $conn;
	protected $pid; // для связывания одного с другим
	protected $auth = false;
	protected $listener;
	protected $parent;

	protected $eof = false;
	protected $started = false;
	protected $isLive;
	protected $link;
	protected $resource;
	protected $name;
	protected $seek = 0;
		protected $isChunked = false;
		protected $currentChunkSize = 0;

	protected $headers = array();
	protected $reqheaders;

	private $cid; // contentID: base64, url, PID
	private $fileidx = 0; // для правильного определения имени потока из множества
	private $startMode; // raw, torrent, pid

	public function __construct($conn, $pid, $parent) {
		$this->conn = $conn;
		$this->pid = $pid;
		$this->parent = $parent;
		// error_log('construct aceconn ' . spl_object_hash($this));
	}
	public function __destruct() {
		$this->disconnect();
		// error_log(' destruct aceconn ' . spl_object_hash($this));
	}

	public function startraw($base64, $fileidx = 0) {
		$this->fileidx = $fileidx;
		$this->cid = $base64;
		$this->startMode = 'raw';
	}
	public function starttorrent($url, $fileidx = 0) {
		$this->fileidx = $fileidx;
		$this->cid = $url;
		$this->startMode = 'torrent';
	}
	public function startpid($pid) {
		$this->fileidx = null;
		$this->cid = $pid;
		$this->startMode = 'pid';
	}

	public function open() {
		switch ($this->startMode) {
			case 'raw':
				$idx = rand(10, 99);
				$this->send('LOADASYNC ' . $idx . ' RAW ' . $this->cid . ' 0 0 0', 0);
				$this->send('START RAW ' . $this->cid . ' ' . $this->fileidx . ' 0 0 0', 10);
				break;
			case 'torrent':
				$idx = rand(10, 99);
				$this->send('LOADASYNC ' . $idx . ' TORRENT ' . $this->cid . ' 0 0 0', 0);
				$this->send('START TORRENT ' . $this->cid . ' 0 0 0 0 0', 15);
				break;
			case 'pid':
				$this->send('START PID ' . $this->cid . ' 0', 15);
				break;
			default:
				throw new CoreException('Unknown start mode', 0);
		}
	}

	public function auth($prodkey) {
		// HELLOBG, get inkey
		$ans = $this->send('HELLOBG version=3'); // << HELLOTS ... key=...
		if (!preg_match('~key=([0-9a-f]{10})~', $ans, $m)) {
			throw new CoreException('No answer with HELLOBG. ' . $ans, CoreException::EXC_CONN_FAIL);
		}
		$inkey = $m[1];
		if (!$inkey) {
			throw new Exception('Key not get with HELLOBG');
		}

		$ready_key = $this->makeKey($prodkey, $inkey);

		// AUTH with ready_key
		$ans = $this->send(sprintf('READY key=%s', $ready_key)); // << AUTH 1

		return $this->auth = true; //$ans == 'AUTH 1';
	}

	public function isLive() { // START http://... >>> stream=1
		return $this->isLive;
	}
	public function isAuthorized() {
		return $this->auth;
	}

	protected function makeKey($prodkey, $inkey) {
		$shakey = sha1($inkey . $prodkey);
		$part = explode('-', $prodkey);
		$prod_part = reset($part);
		return $prod_part . '-' . $shakey;
	}

	public function send($string, $sec = 1, $usec = 0) {
		stream_socket_sendto($this->conn, $string . "\r\n");
		//	 error_log('Ace send: ' . $string);
		$line = $this->readsocket($sec, $usec);
		return $line;
	}

	public function registerEventListener($cb) {
		$this->listener = $cb;
	}
	protected function notifyListener($event) {
		is_callable($this->listener) and call_user_func_array($this->listener, array($event));
	}

	public function close() {
		// тут сохранялся объект StreamUnit, отчего даже при закрытии потока объект не уничтожался
		$this->listener = null;
		// вызываем через parent, Он управляет массивом коннектов
		// дисконнект будет вызван через destruct
		// TODO черезжопия какая то, можно бы и getPID выпилить заодно
		$this->parent->_closeConn($this->getPID());
	}
	private function getPID() {
		return $this->pid;
	}
	public function getName() {
		return $this->name;
	}


	// public только для aceCLI.php
	public function readsocket($sec = 0, $usec = 300000) {
		stream_set_timeout($this->conn, $sec, $usec);

		// при падении ace engine моментально выставляется eof в true
		$s = socket_get_status($this->conn);
		if ($s['eof']) {
			$this->eof = true;
			// тут ничего не делаем, задумка такая, что Listener получит флаг eof и сам все остановит
			// $this->disconnect(); // решение примем уровнями выше
			// throw new Exception('ace_connection_broken');
		}

		$dlstat = array();
		$line = trim(fgets($this->conn));
		if ($line) {
			$dlstat['line'] = $line;
			if ($line == 'EVENT getuserdata') {
				$this->send('USERDATA [{"gender": 1}, {"age": 4}]');
				// error_log('Send userdata');
			}

			// error_log('Ace line: ' . $line);
			$pattern = '~^STATUS\smain:(?<state>buf|prebuf|dl|check);(?<percent>\d+)(;(\d+;\d+;)?\d+;' .
				'(?<spdn>\d+);\d+;(?<spup>\d+);(?<peers>\d+);\d+;(?<dlb>\d+);\d+;(?<ulb>\d+))?$~s';
			if (preg_match($pattern, $line, $m)) {
				$dlstat = array(
					'acestate' => $m['state'],
					'bufpercent' => isset($m['percent']) ? $m['percent'] : null,
					'speed_dn' => @$m['spdn'],
					'speed_up' => @$m['spup'],
					'peers' => @$m['peers'],
					'dl_bytes' => @$m['dlb'],
					'ul_bytes' => @$m['ulb'],
				);
			} else if (substr($line, 0, 5) == 'EVENT') {
			} else if (substr($line, 0, 5) == 'STATE') {
				// error_log('Ace: ' . $line);
			} else {
				// error_log('Ace line not matched: ' . $line);
			}

			// несколько косвенно. можно смотреть на окончание данных по ссылке
			if (!$this->eof and $line == 'STATE 0') {
				$this->eof = true;
			}
			// при состоянии STARTING ожидаем ссылки на поток
			// ждем START http://127.0.0.1:6878/content/aa1ad7963f4dabed7899367c9b6b33c77447abad/0.784118134089
			if (strpos($line, 'START http') !== false) {
				$tmp = explode(' ', $line);
				$this->link = $tmp[1];
				$this->isLive = (isset($tmp[2]) and $tmp[2] == 'stream=1');
				// error_log('Got link ' . $this->link . '  ' . ($this->isLive ? 'is live' : 'is NOT live'));
			}

			// TODO можно и красивше сделать
			if (strpos($line, 'LOADRESP') !== false) {
				// поскольку idx двузначный, можем отрезать с известной позиции в строке
				// длина "LOADRESP NN " = 12
				$answer = explode(' ', $line, 3);
				if (isset($answer[2])) {
					$answer = json_decode($answer[2], true);
					$fileidx = $this->fileidx;
					// первый попавшийся filename берем как название ресурса
					if (isset($answer['files'], $answer['files'][$fileidx], $answer['files'][$fileidx][0])) {
						$this->name = urldecode($answer['files'][$fileidx][0]);
					}
				}
			}
		}

		$dlstat['eof'] = $this->eof;
		$dlstat['started'] = $this->started;

		$this->notifyListener($dlstat);
		return $line;
	}


	public function getStreamHeaders($implode = true) {
		if (!$this->headers) {
			return $implode ? '' : array();
		}
		return $implode ?
			implode("\r\n", $this->headers) . "\r\n\r\n" :
			$this->headers;
	}

	protected function disconnect() {
		$this->send('STOP');
		fclose($this->conn);
	}

	// с какими заголовками клиент запросил поток. их запишем при открытии ссылки от ace
	public function setRequestHeaders($headers) {
		$this->reqheaders = $headers;
	}

	// основной метод получения данных. дергается в цикле. тут работает конечный автомат
	// сначала ждем сыслки от ace, потом заголовков, потом только начинаем выдавать данные
	public function getStreamChunk($bufSize) {
		$this->readsocket(0, 20000); // читаем лог понемногу, сигналы сервера можно отслеживать

		// ссылки на поток нет - ловить нечего
		if (!$this->link) {
			return;
		}

		// далее открываем ссылку, пытаемся прочитать заголовки
		if (!$this->resource) {
			$chunk = $this->initiateStream($this->link, $this->reqheaders, $bufSize);
		} else {
			$chunk = $this->readStreamChunk($this->resource, $bufSize);
		}

		return $chunk;
	}

	// headers - http request headers to open link with
	private function initiateStream($link, $headers, $bufSize) {
		$this->resource = $this->openStream($link, $headers);
		// ну и сразу надо скопировать из потока первую часть данных
		// это для режима кина в основном, иначе проблема следующая
		// XBMC делает при старте много запросов подряд, при неблокирующем чтении можно просто
		// не успеть прочитать и отдать данные, получится пустой ответ (при следующем коннекте предыдущий кикается)
		// и перемотка видео работать не будет.
// TODO с этим что то надо сделать! может просто таймаут побольше? секунд 5 например. а то софт вешается бывает
#		stream_set_blocking($this->resource, 0);
#		stream_set_timeout($this->resource, 5, 0); // не особо действенный способ вышел
		$chunk = $this->readStreamChunk($this->resource, $bufSize);

		// теперь переводим поток в неблокирующий режим, он помогает от зависаний в желтом состоянии буфера
		stream_set_blocking($this->resource, 0); // чет картинка сыпется. но похоже не из-за этого
		stream_set_timeout($this->resource, 0, 20000); // неизвестно, работает или нет

		return $chunk;
	}

	protected function readStreamChunk($res, $bufferSize) {
		$tmp = '';

		if ($this->state == self::STATE_CHUNKWAIT) {
			// $tmp = fgets($res);
				$tmp = stream_get_line($res, 16, "\r\n");
			$len = trim($tmp);
			if (!$len) {
				return '';
			}
			if (!preg_match('~^[0-9a-f]{1,8}$~', $len)) {
				throw new Exception('Chunk read failed "' . json_encode($len) . '"');
			}
			else {
				// +2 на \r\n, длина которых в чанке не учитывается
				$this->currentChunkSize = hexdec($len) + 2;
			}
			$this->state = self::STATE_CHUNKREAD;
		}
		if ($this->state == self::STATE_CHUNKREAD or $this->state == self::STATE_CHUNKWAIT) {
			$bufferSize = $bufferSize > $this->currentChunkSize ? $this->currentChunkSize : $bufferSize;
		}

		// замена fread на stream_socket_recvfrom решила проблему тормозов при просмотре torrent-файлов!
		// потому как fread читает только по 8192 байт, хз как увеличить. второй параметр не работает
		// stream_socket_recvfrom не работает как надо. 253871 - первая длина буфера после 3ffa0, бывало и 264к вместо 262к
		// зато с fread функция чтения chunked работает. fgets вообще не але
		// новая напасть: при запросе конца файла, stream_get_line выдает пустую $data, 
		// если читать осталось меньше, чем размер буфера!
		#$data = stream_get_line($res, $bufferSize);
		// только stream_socket_recvfrom отработала как нужно!
		// Значит для режима live юзаем stream_get_line, а для кина - stream_socket_recvfrom
		// а лучше так, если get_line ничего не дало, попробуем recvfrom
		// upd: не работает!! попытка использовать stream_socket_recvfrom сразу после stream_get_line
		// не дает данных на выходе. т.е. вариант - только раздельное использование
		// но есть подозрение, что для кина это иногда становится причиной вывода только звука без видео
		if ($this->isLive()) {
			$data = stream_get_line($res, $bufferSize);
		}
		else {
			$data = stream_socket_recvfrom($res, $bufferSize);
		}
		$datalen = strlen($data);
		// error_log('got stream ' . $datalen . ' bytes');

		// контролируем, весь ли буфер прочитан
		if ($this->state == self::STATE_CHUNKREAD) {
			if ($datalen < $this->currentChunkSize) {
				$this->currentChunkSize -= $datalen;
			}
			else {
				$this->state = self::STATE_CHUNKWAIT;
				$data = substr($data, 0, -2); // откусываем последние \r\n
			}
		}

		return $data;
	}

	protected function readStreamHeaders($res) {
		$headers = array();
		$this->isChunked = false; // лишняя строка
		while ($line = trim(fgets($res))) {
			if (is_null($this->state)) { // только начали, первый шаг
				if (strpos($line, 'HTTP/1.') === false) {
					throw new Exception('HTTP header expected. Got ' . $line);
				}
				$this->state = self::STATE_HDRREAD;
			}
			if (strpos($line, ':') !== false) {
				list ($name, $value) = array_map('trim', explode(':', $line));
				if ($name == 'Transfer-Encoding' and $value == 'chunked') {
					$this->isChunked = true;
				}
				// вот этот хедер здорово мешал, по сути препятствовал запуску потока
				// я же chunked-поток разбираю (кстати можно вернуть этот хедер и не заниматься разбором)
				// а раз уж разбираю, то и хедер естессно надо убирать
				if (strpos($line, 'Trans') !== false) {
					continue;
				}
			}
			// обработка ошибок. бывает и такое
			// "HTTP/1.1 500 Internal Server Error", "Content-Type: text/plain", "Content-Length: 45"
			if (strpos($line, '500 Internal') !== false) {
				$this->state = self::STATE_ERROR;
			}
			if (strpos($line, 'Connection:') !== false) {
				//$line = 'Connection: close';
			}
			if ($line == 'Content-Type: None') {
				// error_log('Content-Type = None, rewrite to video/x-msvideo');
				// $line = 'Content-Type: video/x-msvideo';
			}
			$headers[] = $line;
		}
		
		// если ответ был ошибкой - прочитаем ее содержание и кинем исключение
		if ($this->state == self::STATE_ERROR) {
			$err = fgets($res);
			throw new Exception('Headers contains error: ' . $err);
		}

		// устанавливается состояние Потоковая передача. 
		$this->state = $this->isChunked ? self::STATE_CHUNKWAIT : self::STATE_HDRSENT;
		return $headers;
	}

	/**
	 * значит так. для режима Live открываем поток 1 раз, остальным клиентам выдаем
	 * заголовки $this->headers от потока
	 * для режима кина предполагаем, что клиент один (иначе перемотка работать не будет)
	 * соответственно, каждый раз закрываем поток и открываем снова,
	 * передавая последние заголовки клиента в поток, и выдавая ему ответные от потока

	 * надо бы для начала написать простейший скрипт, выступающий как веб-прокси,
	 * сервящий один видеофайл с поддержкой перемотки, а там и видно будет
	 * проблема, что ace-поток позволяет только 1 коннект за раз, а некоторые плееры
	 * пробивают разными значениями Range в несколько потоков. VLC вон вообще жестит
	 * скрипт написан: serve_video_test.php

	 * план работы с выдачей кина:
	 *  предыстория: XBMC делает около 4-6 запросов для старта видео.
	 *		сначала HEAD запрос, чтобы получить опции сервера и длину контента
	 *		коннект закрывается сразу после ответа
	 *		думаю на поддержку перемотки результат ответа на HEAD особо не влияет
	 *		Далее - запрос #1 GET с range 0- (т.е. файл целиком, но этот поток будет сброшен)
	 *		затем #2 немного с конца файла range [многобайт]- (около 1Мб до конца файла), #1 активен
	 *		затем сброс #1 и запрос #3 "почти сначала" range 4108-, #2 еще активен
	 *		затем сброс #2 и запрос #4 опять с конца [многобайт]-, почти с того же места, #3 активен
	 *		сброс #3, активен только #4 (чтение хвоста)
	 *		закончено чтение хвоста, открыт новый коннект #5 "почти сначала", bytes 4108-,
	 *		далее весь файл сливается по последнему коннекту #5
	 *	поскольку вся эта канитель происходит очень быстро, в первые секунды,
	 *	и данных на каждый такой запрос в итоге передается немного, то можно установить размер
	 *	буфера чтения ace-потока где-нить в 256-512кБ
	 * итак, видно, что кроме того, что делаются несколько запросов с разных концов потока,
	 *	они еще и параллельные, хорошо, что не все сразу, а только 2 за раз.
	 * вероятный план:
	 *	клиент подключился (запрос #1), открываем ресурс (ace-поток), пихаем туда заголовки, читаем ответные
	 *		после, читаем один буфер, пишем на клиента. а точнее надо usleep немного повышенный поставить
	 *		и читать поток пока он есть. логика будет ясна позже
	 *	клиент делает запрос #2. первый еще активен, его, наверное, попробуем закрыть принудительно.
	 *		если будет брыкаться, заморим голодом, т.е. писать данные на него не будем, сам отвалится.
	 *		данные пишутся только на последний коннект.
	 *		для каждого нового коннекта ресурс закрывается, если открыт, и открывается заново с заголовками
	 *		последнего коннекта, там будет нужный Range: bytes=NNN-.
	 *		Это должно работать, т.к. на каждый из серии начальных коннектов приходится совсем немного данных.
	 * клиент делает запрос #3, сбрасывая #1. #2 еще активен, туда записан минимум 1 буфер, но тут..
	 * ..клиент сбрасывает #2 и делает #4.. ну и т.д.
	 * TODO волшебный функционал: для поддержки нескольких клиентов на 1 фильм, да еще и с
	 *		индивидуальной перемоткой для каждого, можно быстро метаться между кусками ресурса,
	 *		считывая на клиент, пока тот не подавится
	 * ДЕЛАЕМ!
	 */
	protected function openStream($link, $headers) {
		// нужно поправить заголовки, воткнуть туда ссылку к ace, вместо клиентского запроса к проксе
		// GET /trid/407 HTTP/1.1 надо отрезать и заменить другой ссылкой
		$parsed = parse_url($link);
		$get = sprintf('GET %s HTTP/1.1', $parsed['path']);
		$headers = explode("\r\n", $headers);
		array_shift($headers); // снимаем GET с шапки массива
		array_shift($headers); // снимаем Host с шапки массива

		// поищем заголовок range, если задан оффсет
		if ($this->seek) {
			foreach ($headers as $idx => $line) {
				if (stripos($line, 'range') === 0) {
					unset($headers[$idx]);
					break;
				}
			}
			array_unshift($headers, 'Range: bytes=' . $this->seek . '-');
		}
		array_unshift($headers, 'Host: 127.0.0.1:6878'); // кладем сверху свой Host
		array_unshift($headers, $get); // кладем сверху свой GET
		
		// пытался сделать trim() до explode, а сюда добавить \r\n, зависает софт. че за ХХ
		$headers = implode("\r\n", $headers);

		// готовы открывать коннект к видеоданным
		$res = sprintf('tcp://%s:%d', $parsed['host'], $parsed['port']);
		$link_src = stream_socket_client($res, $errno, $errstr, $tmout = 1);
		if (!$link_src) {
			throw new Exception('Failed to open stream link');
		}
		// пишем заголовки запроса
		// error_log('open stream request ' . $headers);
		// почему использована именно эта функция? при записи нужен блокирующий режим
		// stream_socket_sendto($link_src, $headers);
		fwrite($link_src, $headers);

		// теперь ждем заголовков ответа, сохраним их отдельно
		// при этом режим дб блокирующий, иначе нам не успеют ответить
		// TODO замерить время ожидания
		$this->headers = $this->readStreamHeaders($link_src);
		 error_log('open stream response ' . json_encode($this->headers));

		// флаг started устанавливается из false в true один раз - при прочтении заголовков по ссылке
		if (!$this->started) { // хедеры прочитаны и данные пошли
			$this->started = true;
		}

		$this->notifyListener(array('headers' => $this->getStreamHeaders(true)));
		return $link_src;
	}

	// перемотка
	public function seek($offsetBytes) {
		$this->seek = $offsetBytes;
		// хитрый ход. закрываем ресурс, ставим метку оффсета, при запросе данных поток откроется с заданного места
		is_resource($this->resource) and fclose($this->resource);
		$this->resource = null;
		$this->headers = array();
		// из-за несброшенной ссылки с концами вешался весь софт
		// XBMC, как известно, делает несколько запросов для поддержки перемотки.
		// на каждый следующий запрос Ace выдавал немного другую ссылку. infohash был тот же, но rand() другой
		// http://127.0.0.1:6878/content/6aa8418581a92db20cf588aa0f651cdd7a7834a8/0.370222724338
		// менялась последняя часть 0.370...
		// и при следующем вызове initiateStream() открывалась старая ссылка и ожидались данные, а их нет
		// и быть не будет, а режим там блокирующий.. вот и виселица
		// ХЕР, все вообще не так было, я зря новый START отправлял на Ace Server
		# $this->link = null;
	}




	// НИЖЕ НЕРАЗОБРАННЫЙ ШЛАК
	protected $restarting = 0; // счетчик с обратным отсчетом, выставляется когда поток пытается перезапуститься

	public function isRestarting() {
		return $this->restarting > 0;
	}
/*
		// эта ветка срабатывает только при запуске потока, по идее на этот момент только 1 клиент в массиве
		if ($this->state == self::STATE_STARTED) {
			$this->resource = $this->openStream($this->cur_link);
		}

		if ($this->isRestarting()) {
			$this->restart(); // дальнейшие попытки 
		}
		try {
			// проверяем, жив ли сокет до ace server, он мог упасть. метод годный, быстрый
			if (!is_null($this->cur_conn)) {
				$this->cur_conn->ping();
				$this->cur_conn->readsocket(0, 20000); // читаем лог понемногу, сигналы сервера можно отслеживать
			}
		}
		catch (Exception $e) {
			$msg = $e->getMessage();
			if ($msg == 'ace_connection_broken' or strpos($msg, 'Cannot connect') !== false) {
				// ace бывает падает, надо попробовать перезапустить
				// если не получится, будет исключение и поток остановится
				$this->restart(); // первая попытка
				return;
			}
		}


		// такие 2 метода есть выше
		public function close() {
			return $this->isActive() and fclose($this->resource);
		}
		public function isActive() {
			return is_resource($this->resource);
		}
	// найти, что использует
	private function isActive() {
		return $this->state !== 0;
	}

	protected function restart() {
		usleep(250000);
		if (!$this->isRestarting()) { // первая попытка
			$this->notify('Ace connect broken. Restarting', 'warning');
			// закрываем поток видео и коннект к ace, но оставляем всех клиентов активными. потом запускаем заново
			$this->cur_conn->close();
			$this->ace->stoppid($this->cur_pid);
			$this->restarting = self::RESTART_COUNT;
			$this->resource = null;
		}

		// очень плохое решение. ace перезапускается не сразу, сек через 5. делаем N попыток с интервалом в 0.25секунды
		// а остальное приложение все это время ждет.. gui не обновляется
		$pid = $this->cur_pid;
		$name = $this->cur_name;
		try {
			$res = $this->start($pid, $name, false);
			$this->restarting = 0;
			return $res;
		}
		catch (Exception $e) {
			if (strpos($e->getMessage(), 'Cannot connect') === false) {
				$this->restarting = 0;
				throw $e; // не наш случай. мы ждем ошибки коннекта
			}
		}
		$this->restarting--;

		if ($this->restarting == 0) { // так и не дождались
			error_log('Ace Server not reachable');
			throw $e;
		}
	}

 */


}





