<?php
/**
 * ТЕМА: читаем из ace максимально все что есть большими порциями и складываем в память.
 * клиент подключается, выдаем ему содержимое, пока не подавится, но буфер не очищаем!!!
 * когда клиент подавился, значит он записал только часть переданных ему данных, оставшееся сохранил себе
 * пропускаем передачу ему данных, пока не запишет оставшееся в сокет
 * у каждого клиента есть указатель на буфер в объекте потока, данные не копируются в каждом объекте клиента
 * новый клиент, подключаясь, имеет указатель на хвост буфера и мы выдаем ему сколько он попросит, пока не подавится
 * ^_^ KAWAIIIII
 * профит: моментальный старт последующих клиентов, оптимизация по памяти, упразднение метода adjustBuffer, ...?
 */

class StreamUnit {
	const BUF_READ = 256000; // bytes
	const BUF_MIN = 20000;
	const BUF_MAX = 512000;
	const BUF_SECONDS = 30;
	const BUF_DELTA_PRC = 5;
	const RESTART_COUNT = 30;
	const BUFFER_LENGTH = 15e6;

	const STATE_STARTING = 0x01;
	const STATE_STARTED = 0x02;
	const STATE_HDRREAD = 0x03;
	const STATE_HDRSENT = 0x04;
	const STATE_CHUNKWAIT = 0x05; // ожидание длины чанка
	const STATE_CHUNKREAD = 0x06; // чтение чанка на полученную длину
	const STATE_ERROR = 0x08;
	const STATE_IDLE = 0x09;

	protected $ace;
	protected $resource;
	protected $bufferSize;
	protected $buf_adjusted = array();
	protected $statistics = array();
	protected $clients = array();
	protected $cur_pid; // текущий id запущенной трансляции
	protected $cur_name;// и название
	protected $cur_conn;
	protected $cur_type;
	protected $cur_link;
	protected $startTime; // время запроса потока
	protected $waitSec; // сколько секунд ждать ссылки на поток
	protected $finished = false; // выставляется в true когда отключается последний клиент
	protected $restarting = 0; // счетчик с обратным отсчетом, выставляется когда поток пытается перезапуститься
	protected $stopReading = false;
	protected $buffer = ''; // сюда большой строкой будет записываться буфер
	protected $state; // состояние конечного автомата
	protected $isLive = false;
	protected $isChunked = false;
	protected $currentChunkSize = 0;
	protected $headers = array();
	protected $ttv_login;
	protected $ttv_psw;

	public function __construct(AceConnect $ace, $bufSize = null, $type = 'pid') {
		$this->state = self::STATE_IDLE;
		$this->ace = $ace;
		$this->cur_type = $type;
		// можно передать сюда и буфер из кэша настроек
		$this->init($bufSize);
		$this->statistics = array(
			'bufpercent' => null,
			'acestate' => null,
			'speed_dn' => null,
			'speed_up' => null,
			'peers' => null,
			'dl_bytes' => null,
			'ul_bytes' => null,
		) + $this->buf_adjusted;
	}
	public function getType() {
		return $this->cur_type;
	}

	// soe - stop on exception
	public function start($pid, $name, $soe = true) {
		try {
			$this->cur_pid = $pid;
			$this->cur_name = $name;
			$this->state = self::STATE_STARTING;
			$this->startTime = time();
			$type = $this->getType();
			$tmp = null;
			switch ($type) {
				case 'pid':
					$conn = $this->ace->startpid($this->cur_pid);
					$this->isLive = true;
					$this->waitSec = 30; // cycles ~ seconds
					break;
				case 'torrent':
					$conn = $this->ace->startraw($this->cur_pid, $tmp);
					// в tmp кладется название из LOADRESP
					if ($tmp) {
						$this->cur_name = $tmp;
					}
					$this->isLive = false;
					$this->waitSec = 60; // cycles ~ seconds
					// единственный пока isLive=false случай - непотоковое торрент-содержимое
					// кино, серия, мультик - в общем файл
					break;
				case 'trid':
					try {
						$tmp = $this->parse4PID($this->cur_pid);
					}
					catch (Exception $e) {
						// рефакторить на нормальные классы, коды ошибок и убрать копипаст parse4PID!!
						error_log($e->getMessage());
						if (stripos($e->getMessage(), 'curl') === 0) {
							throw new Exception('Torrent tv timed out');
						}
						$this->torrentAuth();
						$tmp = $this->parse4PID($this->cur_pid);
					}
					// tmp использовалась, чтобы pid не попортить раньше времени
					$pid = $tmp;
				case 'acelive':
					if ($type == 'acelive') {
						$pid = sprintf('http://content.asplaylist.net/cdn/%d_all.acelive', $pid);
					}
					error_log('Got link ' . $pid);
				default:
					$conn = $this->ace->starttorrent($pid);
					$this->isLive = true;
					$this->waitSec = 50; // cycles ~ seconds
			}
			$conn->registerEventListener(array($this, 'aceListen'));
			$this->cur_conn = $conn;
		}
		catch (Exception $e) {
			$this->state = self::STATE_ERROR;
			$soe and $this->close();
			throw $e;
		}
	}
	// любой ответ от движка в plaintext поступает сюда
	public function aceListen($aceline) {
		/*
		начало фильма Час пик
		STATUS main:dl;5;0;959;0;0;10;0;49709056;0;0
		STATUS main:dl;6;0;961;0;0;10;0;50741248;0;0
		кажется тут пошел аплоад
		STATUS main:dl;8;0;967;0;0;11;0;109805568;0;0
		STATUS main:dl;8;0;971;0;10;11;0;110903296;0;212992
		STATUS main:dl;8;0;965;0;15;11;0;111771648;0;344064
		по окончании
		STATUS main:dl;99;0;962;0;116;21;0;2782150656;0;658866176
		STATUS main:dl;100;0;0;0;115;9;0;2782445568;0;658964480
		EVENT cansave index=0 infohash=75e3411c18ebc5d905cb33dc60b01854a7935f19 format=plain
		STATUS main:dl;100;0;0;0;112;4;0;2782445568;0;659030016

		// разбираем STATUS по косточкам: STATUS main:buf;0;0;0;0;79;0;5;20;0;2359296;0;163840
		// total_progress;immediate_progress;speed_down;http_speed_down;speed_up;peers;http_peers;downloaded;http_downloaded;uploaded
		STATUS main:buf;93;0;0;0;193;0;3561;98;0;89571328;0;1644904448
		STATUS main:prebuf;1;0;0;0;175;0;0;1;0;884736;0;0
		Все числа передаются как integer.
		Все progress принимают значение от 0 до 100.
		*/
		error_log($aceline);
		$pattern = '~^STATUS\smain:(?<state>buf|prebuf|dl|check);(?<percent>\d+)(;(\d+;\d+;)?\d+;' .
			'(?<spdn>\d+);\d+;(?<spup>\d+);(?<peers>\d+);\d+;(?<dlb>\d+);\d+;(?<ulb>\d+))?$~s';
		if (preg_match($pattern, $aceline, $m)) {
			$dlstat = array(
				'acestate' => $m['state'],
				'bufpercent' => isset($m['percent']) ? $m['percent'] : null,
				'speed_dn' => @$m['spdn'],
				'speed_up' => @$m['spup'],
				'peers' => @$m['peers'],
				'dl_bytes' => @$m['dlb'],
				'ul_bytes' => @$m['ulb'],
			);
			$this->statistics = array_merge($this->statistics, $dlstat);
			return;
		}

		// движок говорит, что поток остановлен (бывает при ошибке Cannot load transport file)
		if ($aceline == 'STATE 0') {
			$this->close();
		}

		// при состоянии STARTING ожидаем ссылки на поток
		// вообще этот метод дергается только при наличии ответа от Ace, а если тот будет молчать, можем застрять
		if ($this->state == self::STATE_STARTING) {
			$secPassed = (time() - $this->startTime);
			if ($secPassed > $this->waitSec) {
				// может close+exception заменить одним методом, например error(msg)
				$this->close();
				throw new Exception('Failed to get link');
			}

		// ждем START http://127.0.0.1:6878/content/aa1ad7963f4dabed7899367c9b6b33c77447abad/0.784118134089
			if (strpos($aceline, 'START http') !== false) {
				$tmp = explode(' ', $aceline);
				$this->state = self::STATE_STARTED;
				$this->cur_link = $tmp[1];
			}
		}
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
	 * TODO надо только придумать что то с буферизацией на клиенте. пихать данные для начальных запросов лучше целиком
	 *		без указания bufSize, но когда все устаканится, пойдет много данных и на клиента в итоге не полезет
	 * TODO волшебный функционал: для поддержки нескольких клиентов на 1 фильм, да еще и с
	 *		индивидуальной перемоткой для каждого, можно быстро метаться между кусками ресурса,
	 *		считывая на клиент, пока тот не подавится
	 * ДЕЛАЕМ!
	 */
	protected function openStream($link) {
		$c = end($this->getClients());
		if (!$c) { // бывает и такое, если клиент после коннекта сразу отваливается
			return false;
		}

		$this->buffer = '';
		$parsed = parse_url($link);
		$headers = $c->getLastRequest()->getHeaders();
		// вот только GET /trid/407 HTTP/1.1 надо отрезать и заменить другой ссылкой
		$get = sprintf('GET %s HTTP/1.1', $parsed['path']);
		$headers = explode("\r\n", $headers);
		array_shift($headers); // снимаем GET с шапки массива
		array_unshift($headers, $get);
		$headers = implode("\r\n", $headers);

		$res = sprintf('tcp://%s:%d', $parsed['host'], $parsed['port']);
		$link_src = stream_socket_client($res, $errno, $errstr, $tmout = 1);
		if (!$link_src) {
			throw new Exception('Failed to open stream link');
		}
		// пишем заголовки запроса
		error_log('open stream request ' . $headers);
		// почему использована именно эта функция? при записи нужен блокирующий режим
		// stream_socket_sendto($link_src, $headers);
		fwrite($link_src, $headers);

		// теперь ждем заголовков ответа, сохраним их отдельно
		// при этом режим дб блокирующий, иначе нам не успеют ответить
		$this->headers = $this->readStreamHeaders($link_src);
		error_log('open stream response ' . json_encode($this->headers));

		// поток открыт, пора всех клиентов оповестить и раздать им заголовки
		foreach ($this->getClients() as $c) {
			if ($c->isAccepted()) { // клиент уже получил заголовки, пропускаем
				continue;
			}
			// отправляем хттп заголовки ОК
			$c->accept($this->getHeadersPlainText());
		}

		// ну и сразу надо скопировать из потока первую часть данных
		// это для режима кина в основном
		$this->copyChunk();

		// теперь переводим поток в неблокирующий режим, он помогает от зависаний в желтом состоянии буфера
		stream_set_blocking($link_src, 0); // чет картинка сыпется. но похоже не из-за этого
		stream_set_timeout($link_src, 0, 20000); // неизвестно, работает или нет
		return $link_src;
	}

	public function __destruct() {
	}

	public function close() {
		// вообще по идее при уничтожении объекта будут вызваны __destruct и всех вложенных
		foreach ($this->clients as $idx => $one) {
			// почему было закомментировано закрытие клиентов?
			$one->close();
			unset($this->clients[$idx]); // может из-за unset?
			// в __destruct у клиента нет кода самозакрытия, так что раскомментировал
			// не работал сброс клиента при Failed to get link, помогло
		}

		$this->closeStream();
		$this->cur_conn and $this->cur_conn->isActive() and $this->cur_conn->send('STOP');
		$this->finished = true;
		$this->state = self::STATE_IDLE;
	}

	public function unfinish() {
		$this->finished = false;
	}

	public function isFinished() {
		return $this->finished;
	}
	
	public function isRestarting() {
		return $this->restarting > 0;
	}

	protected function closeStream() {
		error_log('closing stream');
		return $this->isActive() and fclose($this->resource);
	}

	public function isActive() {
		return is_resource($this->resource);
	}

	public function getStatistics() {
		return $this->statistics;
	}

	public function getBufferedLength() {
		return strlen($this->buffer);
	}

	public function getBufferSize() {
		return $this->bufferSize;
	}

	public function getState() {
		$set = array(
			iconv('cp866', 'utf8', chr(0x27)), // ' (апостроф, точки вверху не нашел)
			iconv('cp866', 'utf8', chr(0xf9)),	// точка в центре
			'.'	// точка внизу
		);
		$sign = $set[time() % count($set)];
		$perc = $this->statistics['bufpercent'];
		$state = $this->statistics['acestate'];

		if ($state == 'buf') {
			$state = $sign . ' ' . $perc . '%';
		}
		else if ($state == 'check') {
			$state = 'chk ' . $perc . '%';
		}
		else if ($state == 'prebuf') {
			$state = 'pre ' . $perc . '%';
		}
		else if ($state == 'dl') {
			$state = 'PLAY';
		}
		else if ($this->state == self::STATE_STARTED) {
			#$state = 'run';
		}
		else if ($this->state == self::STATE_STARTING) {
			$state = 'START';
		}
		else {
			#$state = 'unk';
		}
		return $state;
	}

	public function getName() {
		return $this->cur_name;
	}

	public function getClients() { // alias
		return $this->getPeers();
	}
	public function getPeers() {
		return $this->clients;
	}

	public function getPID() {
		return $this->cur_pid;
	}

	protected function getHeadersPlainText() {
		return implode("\r\n", $this->headers) . "\r\n\r\n";
	}
	protected function init($bufSize = null) {
		$this->bufferSize = $bufSize ? $bufSize : self::BUF_READ;
		// инициализируем и сопутствующие массивы
		$this->buf_adjusted = array(
			'lastcheck' => null,
			'state' => null, // есть данные или нет
			'over' => false, // слишком долго читаем поток без буферизации
			'changed' => null, // unixts последнего перехода нет-есть/есть-нет данных
			'state1time' => null, // время наличия данных
			'state0time' => null, // время отсутствия данных
			'emptydata' => false, // считаны ли данные из Ace
			'shortdata' => false, // true, если данных меньше, чем размер буфера
		);
	}

	public function registerClient(StreamClient $client) {
		if (!$this->isLive) {
			// предыдущих клиентов надо скинуть, иначе новый диапазон байт будет при 
			// прочтении записан на них тоже. надо только на последнего подключившегося
			// может это логичнее при openStream делать?
			foreach ($this->getClients() as $one) {
				$one->close();
			}
			$this->unfinish();
		}

		$peer = $client->getName();
		$this->clients[$peer] = $client;
		$client->associateStream($this);
		if ($this->isLive) {
			return;
		}

		$client->setEcoMode(false);
		// итак, если у нас не поток (кино), то нужно закрыть источник 
		// и открыть его с новыми клиентскими заголовками
		// НО только если поток уже запущен и воспроизводится
		if ($this->state != self::STATE_HDRSENT) {
			return;
		}
		$this->restartStream();
	}

	protected function restartStream() {
		$this->closeStream();
		$this->resource = $this->openStream($this->cur_link);
	}

	public function unregisterClientByName($peer) {
		unset($this->clients[$peer]);
		if (empty($this->clients)) { // пора сворачивать кино
			// выставим флаг, а StreamsManager по нему поставит нас в очередь на остановку
			$this->finished = true;
		}
	}

	protected function notify() {
		$args = func_get_args();
		foreach ($this->clients as $one) {
			call_user_func_array(array($one, 'notify'), $args);
		}
	}
	protected function restart() {
		usleep(250000);
		if (!$this->isRestarting()) { // первая попытка
			$this->notify('Ace connect broken. Restarting', 'warning');
			// закрываем поток видео и коннект к ace, но оставляем всех клиентов активными. потом запускаем заново
			$this->isActive() and fclose($this->resource);
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

	protected function readStreamHeaders($res) {
		$headers = array();
		$this->isChunked = false;
		while ($line = trim(fgets($res))) {
			if ($this->state == self::STATE_STARTED) {
				if (strpos($line, 'HTTP/1.') === false) {
					throw new Exception('HTTP 200 OK header expected. Got ' . $line);
				}
				$this->state = self::STATE_HDRREAD;
			}
			if (strpos($line, ':') !== false) {
				list ($name, $value) = array_map('trim', explode(':', $line));
				if ($name == 'Transfer-Encoding' and $value == 'chunked') {
					$this->isChunked = true;
					error_log('Chunked mode');
				}
				if (0
					// вот этот хедер здорово мешал, по сути препятствовал запуску потока
					// я же chunked-поток разбираю (кстати можно вернуть этот хедер и не заниматься разбором)
					// а раз уж разбираю, то и хедер естессно надо убирать
					or strpos($line, 'Trans') !== false 
				) {
					continue;
				}
			}
			// обработка ошибок. бывает и такое
			// "HTTP/1.1 500 Internal Server Error", "Content-Type: text/plain", "Content-Length: 45"
			if (strpos($line, '500 Internal') !== false) {
				$this->state = self::STATE_ERROR;
			}
			$headers[] = $line;
		}
		
		// если ответ был ошибкой - прочитаем ее содержание и кинем исключение
		if ($this->state == self::STATE_ERROR) {
			$err = fgets($res);
			throw new Exception('Headers contains error: ' . $err);
		}

		$this->state = $this->isChunked ? self::STATE_CHUNKWAIT : self::STATE_HDRSENT;
		return $headers;
	}

	// читаем часть трансляции и раздаем зарегенным клиентам
	// вызывается около 33 раз в сек, зависит от usleep в главном цикле
	// наверное где то тут надо отслеживать коннект ace и рестартить поток в случае падения
	public function copyChunk() {
		$data = null;
		// на данном этапе надо открыть полученную ссылку, и сконнектить общение клиента и Ace, 
		// т.е. пробрасывать все запросы клиента в поток, ну и само собой из потока все данные тупо на клиента выдавать
		// клиент запросит несколько различных частей потока и тогда перемотка работает!!
		// класть ли ответные заголовки в буфер или отсеивать?
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


		// если режим остановки и буфер похудел - продолжаем чтение
		if ($this->stopReading) {
			if ($this->getBufferedLength() <= self::BUFFER_LENGTH) {
				$this->stopReading = false;
			}
		}
		// копируем контент в сокет
		else if (!$data and $this->isActive()) {
			$data = $this->readStreamChunk($this->resource);
		}

		// тут собирается некоторая статистика и флаги для вывода в UI
		$this->adjustBuffer($data);
		// добавляем считанные данные к буферу
		// если считанных данных нет. а до этого была частичная запись, то в буфере остается кусок, 
		// который пишется бесконечно, пока не будут прочитаны данные из потока - косяк
		$this->appendBuffer($data);

		// TODO
		// если данные пусты, надо выдавать по несколько байт из последнего элемента буфера,
		// чтобы XBMC дал нормально остановить при желании поток. 
		// а то он пока байта не прочитает будет висеть (или до таймаута своего)

		$this->statistics = array_merge($this->statistics, $this->buf_adjusted);

		// походу тут и проблема. эта строка писалась для старта потока
		// однако она же сработает и при окончании потока от Ace
		// и записывать на клиент по 1 байтику не даст
		// upd: емое,я с указателем перепутал, закэшированные данные в размере 
		// могут только вырасти, с 0 до 15-30Мб
		if ($this->isLive and $this->getBufferedLength() < 1000000) { // подкопим немного для начала
			return; // // убрал до ввода доп.флага различия старта и финиша
		}

		// на каждого клиента есть указатель на буфер
		// буфер потока один на всех клиентов
		// при старте потока пишем все в буфер, держим его размер постоянным
		$bufSize = $this->isLive ? $this->getBufferSize() : strlen($this->buffer);
		foreach ($this->clients as $peer => $client) {
			$result = $client->put($this->buffer, $bufSize);
		}

		$this->trimBuffer();

		return ;
	}

	protected function readStreamChunk($res) {
		$tmp = '';
		if ($this->state == self::STATE_CHUNKWAIT) {
			// +2 на \r\n, длина которых в чанке не учитывается
			$tmp = fgets($this->resource);
			$len = trim($tmp);
			if (!$len) {
				return '';
			}
			if (!preg_match('~^[0-9a-f]{1,8}$~', $len)) {
				throw new Exception('Chunk read failed');
			}
			else {
				$this->currentChunkSize = hexdec($len) + 2;
			}
			$this->state = self::STATE_CHUNKREAD;
		}
		else {
			$bufferSize = $this->bufferSize;
		}
		if ($this->state == self::STATE_CHUNKREAD or $this->state == self::STATE_CHUNKWAIT) {
			$bufferSize = $this->bufferSize > $this->currentChunkSize ? $this->currentChunkSize : $this->bufferSize;
		}

		// замена fread на stream_socket_recvfrom решила проблему тормозов при просмотре torrent-файлов!
		// потому как fread читает только по 8192 байт, хз как увеличить. второй параметр не работает
		// stream_socket_recvfrom не работает как надо. 253871 - первая длина буфера после 3ffa0, бывало и 264к вместо 262к
		// зато с fread функция чтения chunked работает. fgets вообще не але
		// новая напасть: при запросе конца файла, stream_get_line выдает пустую $data, 
		// если читать осталось меньше, чем размер буфера!
		#$data = stream_get_line($this->resource, $bufferSize);
		// только stream_socket_recvfrom отработала как нужно!
		// Значит для режима live юзаем stream_get_line, а для кина - stream_socket_recvfrom
		// а лучше так, если get_line ничего не дало, попробуем recvfrom
		// upd: не работает!! попытка использовать stream_socket_recvfrom сразу после stream_get_line
		// не дает данных на выходе. т.е. вариант - только раздельное использование
		// но есть подозрение, что для кина это иногда становится причиной вывода только звука без видео
		if ($this->isLive) {
			$data = stream_get_line($this->resource, $bufferSize);
		}
		else {
			$data = stream_socket_recvfrom($this->resource, $bufferSize);
		}
		$datalen = strlen($data);

#		error_log('Got ' . $datalen . ' from source');
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

	protected function appendBuffer($data) {
		if ($data) {
			$this->buffer .= $data;
		}
	}

	// задача метода - держать размер буфера 15-30Мб
	// уведомлять клиентов о необходимости скорректировать указатели
	// кикать зазевавшихся или мертвых клиентов (upd: клиент сам себя кикнет)
	protected function trimBuffer() {
		$len = $this->getBufferedLength();
		$delta = $len - self::BUFFER_LENGTH;
		if ($delta > 0) {
			$this->buffer = substr($this->buffer, $delta);
		}
		// а если delta 0 или вдруг < 0?
		$tmp = true;
		foreach ($this->clients as $peer => $client) {
			if ($client->getPointerPosition() > 80) {
				$tmp = false;
			}
			$client->correctBufferPointer($delta, $this->buffer);
		}
		// здесь определяется только остановка, не запуск
		if ($tmp != $this->stopReading and $this->getBufferedLength() > 1000000) {
			$this->stopReading = true;
		}
	}

	// data на входе только для контроля ситуации, идет ли считывание из ace
	protected function adjustBuffer($data, &$adjusted = null) {
		$adjusted = null; // не используется в общем то

		$this->buf_adjusted['emptydata'] = empty($data);
		$this->buf_adjusted['shortdata'] = strlen($data) < $this->bufferSize;

		// хочется добиться равномерного считывания потока и записи на клиент
		// причем с учетом, что у потоков мб разный битрейт
		// если данные не получены, значит вычитали весь буфер источника 
		// (при нормальной работе, факапы в расчет не берем сейчас)
		// значит прекращаем повышать размер буфера для потока
		// иначе повышаем его постепенно (на 100-1000 байт при каждом пустом $data)

		// время считывания контента должно быть секунд 30, подстраиваем буфер под это
		// upd: буфер выставлен фиксированно, не меняем его размер
		// только собираем доп.данные

		$statechange = false; // факт перехода есть данные - нет данных и обратно
		if ($data and !$this->buf_adjusted['state']) { // переход "нет данных - есть данные"
			$this->buf_adjusted['state'] = true;
			// если есть время, когда пропали данные, высчитаем период их отсутствия
			if ($this->buf_adjusted['changed']) {
				$this->buf_adjusted['state0time'] = time() - $this->buf_adjusted['changed'];
			}
			$this->buf_adjusted['changed'] = time();
			$statechange = true;
		}
		else if (!$data and $this->buf_adjusted['state']) { // переход "есть - нет"
			$this->buf_adjusted['state'] = false;
			// если есть время, когда появились данные, высчитаем период их наличия
			if ($this->buf_adjusted['changed']) {
				$this->buf_adjusted['state1time'] = time() - $this->buf_adjusted['changed'];
			}
			$this->buf_adjusted['changed'] = time();
			$statechange = true;
		}

		$check = (
			empty($this->buf_adjusted['lastcheck']) or 
			time() - $this->buf_adjusted['lastcheck'] >= 1
		);

		$changeTime = time() - $this->buf_adjusted['changed'];
		if ($data and $changeTime > (1.0 * self::BUF_SECONDS) and $check) { // too long reading
			$this->buf_adjusted['over'] = true;
		}
		if ($check) {
			$this->buf_adjusted['lastcheck'] = time();
		}

		if ($statechange) {
			$this->buf_adjusted['over'] = false;
		}
		// HACK нафиг всю эту подстройку буфера
		$this->bufferSize = self::BUF_READ;
	}

	public function setTTVCredentials($login, $pw) {
		$this->ttv_login = $login;
		$this->ttv_psw = $pw;
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
			CURLOPT_COOKIEFILE => './cookie_ttv.txt',
			CURLOPT_COOKIEJAR =>  './cookie_ttv.txt',
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

