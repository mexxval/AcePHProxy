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

 * переработка класса, делаем поддержку всех клиентов даже для одного фильма:
 *	для режима isLive=0 пусть буфер будет неразделяемым. на стороне клиента
 *		все равно никогда не будет двух клиентов с одинаковым offset
 *		хотя это придется еще клиента дорабатывать. хочется все же ограничиться правкой StreamUnit
 *	точнее буфер может вообще отсутствовать, все что считаем из источника - сразу пишем на соотв.клиента
 *		если в него что то не поместилось (несколько сот кБ) - сохраняем в его мини буфер
 */

class StreamUnit {
	/* на примере ТВ потока: если буфер сильно большой - AceServer очень часто уходит в
	 * буферизацию, т.е. бОльшую часть времени находится в этом состоянии,
	 * т.к. прогруженные данные при большом буфере мы забираем очень быстро, а битрейт
	 * ТВ канала небольшой.
	 */
	const BUF_READ = 25000; // bytes
	const BUF_MIN = 25;
	const BUF_MAX = 512000;
	const BUF_SECONDS = 30;
	const BUF_DELTA_PRC = 5;
	const RESTART_COUNT = 30;
	const BUFFER_LENGTH = 15e6;
	// устарело. у разных каналов разный битрейт. HD каналы еще есть
	// так что даем фору по времени. 5 сек, см. INIT_SECONDS
	// если держать 2Мб отставания от ace - он не будет переходить в режим буферизации
	// если пытаться держаться ближе (читать все до отказа) - часто буферизует
	const INIT_LENGTH = 2.0e6;
	const INIT_SECONDS = 4;

	const STATE_STARTING = 0x01; // самое начало, когда только-только вызван метод start
	const STATE_STARTED = 0x02; // поток пошел
	const STATE_IDLE = 0x09;

	protected $buffer = ''; // сюда большой строкой будет записываться буфер
	protected $isLive = true;
	protected $streamType; // тип потока (plugin code)
	
	protected $bufferSize;
	protected $buf_adjusted = array();
	protected $statistics = array();
	protected $state; // состояние конечного автомата
	protected $cur_conn;

	protected $clients = array();
	protected $startTime; // время запроса потока
	protected $startedTime; // время, когда получена ссылка на поток (пробуферизованный)
	protected $waitSec; // сколько секунд ждать ссылки на поток
	protected $finished = false; // выставляется в true когда отключается последний клиент
	protected $stopReading = false;

	public function __construct(AppStreamResource $conn) {
		$this->cur_conn = $conn; // КЛАСС источника потока, у него там тоже конечный автомат есть
		$this->cur_conn->registerEventListener(array($this, 'connectionListener'));
		$this->state = self::STATE_IDLE;

		$this->init();

		// ace-related code! TODO
		// в принципе все эти ace-параметры можно и к другим источникам применить
		$this->statistics = array(
			'dl_total' => 0, // сколько байт вообще было прочитано из источника за все время
			'bufpercent' => null,
			'acestate' => null,
			'speed_dn' => null,
			'speed_up' => null,
			'peers' => null,
			'dl_bytes' => null,
			'ul_bytes' => null,
		) + $this->buf_adjusted;
		# error_log('construct stream ' . spl_object_hash ($this));

		$this->start2();
	}

	protected function init() {
		$this->bufferSize = self::BUF_READ;
		// инициализируем и сопутствующие массивы
		$this->buf_adjusted = array(
			'lastcheck' => null,
			'state' => null, // есть данные или нет
			'over' => false, // adaptiveBuffer: слишком долго читаем поток без буферизации
			'changed' => null, // unixts последнего перехода нет-есть/есть-нет данных
			'state1time' => null, // adaptiveBuffer: время наличия данных
			'state0time' => null, // adaptiveBuffer: время отсутствия данных
			'emptydata' => false, // считаны ли данные из источника
			'shortdata' => false, // true, если данных меньше, чем размер буфера
		);
	}

	private function start2() {
		$this->state = self::STATE_STARTING;
		$this->startTime = time();
		$this->waitSec = 30; // cycles ~ seconds
		$this->cur_conn->open();
	}

	public function __destruct() {
		# error_log(' destruct stream ' . spl_object_hash ($this));
	}

	// первый компонент request_uri, т.е. плагин (torrent, ttv, websocket, etc)
	// достать его здесь - не совсем просто, т.к. это содержится в ClientRequest
	// делаем так, при регистрации в потоке первого клиента, берем его lastRequest,
	// и спрашиваем у него тип
	public function getType() {
		return $this->streamType;
	}

	public function close() {
		// вообще по идее при уничтожении объекта будут вызваны __destruct и всех вложенных
		foreach ($this->clients as $peer => $one) {
			$this->dropClientByPeer($peer);
		}
		$this->closeStream();
		$this->finished = true;
		$this->state = self::STATE_IDLE;
	}
	private function dropClientByPeer($peer) {
		// почему было закомментировано закрытие клиентов?
		$this->clients[$peer]->close();
		unset($this->clients[$peer]); // может из-за unset?
		// в __destruct у клиента нет кода самозакрытия, так что раскомментировал
		// не работал сброс клиента при Failed to get link, помогло
	}

	public function unfinish() {
		$this->finished = false;
	}

	public function isFinished() {
		return $this->finished;
	}
	
	public function isRestarting() {
		return $this->cur_conn->isRestarting();
	}

	protected function closeStream() {
		isset($this->cur_conn) and $this->cur_conn->close();
		// например для плагина вебсервера объект файла закрывался, но не удалялся.
		// а в нем ссылка на этот объект StreamUnit (через registerEventListener)
		unset($this->cur_conn);
	}

	public function getStatistics() {
		return $this->statistics;
	}

	// текущий объем данных в разделяемом буфере
	public function getBufferedLength() {
		return strlen($this->buffer);
	}

	// размер порции данных, читаемых из источника за раз
	public function getBufferSize() {
		return $this->bufferSize;
	}

	// максимальный объем разделяемого буфера
	public function getBufferLength() {
		return self::BUFFER_LENGTH;
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
			// для кина рисуем другую картинку
			if (!$this->isLive) {
				$s = ')'; // различные варианты значков
				#$s = iconv('cp866', 'utf8', chr(186));
				#$s = iconv('cp866', 'utf8', chr(249));
				$list = array("$s   ", "$s$s  ", "$s$s$s ", " $s$s ", "  $s ", "    ");
				// * 4 регулирует скорость. больше множитель - выше скорость
				$symbolidx = round(microtime(1) * 3) % count($list);
				$symbol = $list[$symbolidx];
				$state = ($perc == 100 ? $perc : ($symbol . $perc)) . '%';
			}
		}
		else if ($this->state == self::STATE_STARTED) {
			$state = 'READ';
		}
		else if ($this->state == self::STATE_STARTING) {
			$state = 'START';
		}
		else {
			#$state = 'unk';
		}
		return $state;
	}
	public function isLive() {
		return $this->isLive;
	}

	public function getName() {
		// в случае failed to start stream объекта потока может не быть
		// и тогда тут будет фатал
		return isset($this->cur_conn) ? $this->cur_conn->getName() : null;
	}

	public function getClients() { // alias
		return $this->getPeers();
	}
	public function getPeers() {
		return $this->clients;
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
	// метод отвечает, запущена ли уже выдача видео, т.е. в основном ли рабочем состоянии находится объект
	protected function isRunning() {
		return $this->state == self::STATE_STARTED;
	}



	// любой ответ от движка в plaintext поступает сюда
	public function connectionListener($stats) {
		$this->statistics = array_merge($this->statistics, $stats);

		// движок говорит, что поток остановлен (бывает при ошибке Cannot load transport file)
		if (!empty($stats['eof'])) {
			# $this->close();
			$this->finished = true; // через флаг лучше. parent-объект нас потом сам закроет
			# error_log('Event: stream resource eof');
		}
		// вообще этот метод дергается только при наличии ответа от Ace, а если тот будет молчать, можем застрять
		else if ($this->state == self::STATE_STARTING and !empty($stats['started'])) {
			$this->state = self::STATE_STARTED;
			$this->isLive = $this->cur_conn->isLive();
			$this->startedTime = time();
			foreach ($this->getClients() as $c) {
				// перевыставляем режим ecoMode, см.коммент к методу registerClient
				$c->setEcoMode($this->isLive);
			}
			#error_log('Event: stream resource ready, isLive=' . ($this->isLive ? 'true' : 'false'));
		}

		if (!empty($stats['headers'])) { // готовы хедеры в ответ на запрос пользователя, отдаем
			// поток открыт, пора всех клиентов оповестить и раздать им заголовки
			#error_log('Event: Accepting all clients on stream start');
			foreach ($this->getClients() as $c) {
				// отправляем хттп заголовки ОК
				$c->accept($stats['headers']);
			}
		}
	}

	// TODO рефаккттоориииить
	// TODO еще косяк.. касается долгооткрывающегося ace контента.
	//	флаг isLive в момент регистрации клиента мб определен неверно,
	//	т.к. регистрация происходит раньше, чем поток открывается и
	//	отчитывается событием 'headers'
	public function registerClient(StreamClient $client) {
		// это тут немного не к месту. просто нужен тип открываемого потока
		// файл, торрент, вебсокет, тв и т.д.
		if (!$this->streamType) {
			$req = $client->getLastRequest();
			$this->streamType = $req->getPluginCode();
		}

		if (!$this->isLive) { // типа кино
			// предыдущих клиентов надо скинуть, иначе новый диапазон байт будет при
			// прочтении записан на них тоже. надо только на последнего подключившегося
			// может это логичнее при openStream делать?
			// error_log('Drop all clients except new one');
			// если клиенту не отправлялись заголовки Connection: close, он вполне
			// может возыметь наглось отправить еще запрос по тому же каналу
			// и этот запрос будет обработан как и предыдущий. и приведет нас сюда
			// и в случае, если это кино (isLive=false), то все клиенты будут сброшены
			// включая последнего, и это косяк. см.ниже про getLastRequest()
			foreach ($this->getClients() as $peer => $one) {
				// поэтому дополнительно проверяем peer
				if ($peer == $client->getName()) {
					// сделаем unregister, чтобы далее register нормально отработал
					$this->unregisterClientByName($peer);
					error_log(' unregister ' . $peer . ' instead of kick');
				} else {
					$this->dropClientByPeer($peer);
				}
			}
			// на случай если поток уже был запущен и последний клиент отключился,
			// идет обратный отсчет до полной остановки. и тут подключается новый
			// клиент - надо отменить остановку
			$this->unfinish();
			$this->buffer = '';
			$this->statistics['dl_total'] = 0;
		}

		// для режима кино обязательно вырубаем ecoMode, иначе просто не будет работать
		// т.к. для работы перемотки плееры делают несколько мелких запросов, а ecoMode
		// из-за этого отдает данные по 1 байту
		// см. коммент к методу про косяк с isLive
		$client->setEcoMode($this->isLive);

		$peer = $client->getName();
		$this->clients[$peer] = $client;
		$client->associateStream($this);




		// что идет ниже - мне не нравится
		// заголовки должны быть с правильным range и content-length
		if ($this->isLive) {
			// если поток уже открыт и воспроизводится, то похоже это дополнительные клиенты
			// надо им отослать заголовки! а то внезапно оказалось, что более 1 клиента на одну
			// трансляцию перестало обслуживаться
			// для Live-режима заголовки те же самые, одинаковые для всех
			// для просмотра торрентов отдельная песня. там разные Range: bytes должны быть
			if ($this->isRunning()) {
				$headers = $this->cur_conn->getStreamHeaders(true);
				// попробуем решить проблему отвала VLC по negative counter таким способом:
				// нового клиента цепляем на середину буфера
				list($pointerPos, $pointer) = $this->getMiddlePointerPosition();
				# error_log('Accept client on ' . $pointerPos . '%');
				$client->accept($headers, $pointer, $pointerPos);
			}
			return;
		}

		// далее идет логика для режима Кино (не лайв поток)
		// сбросим метку начала старта, чтобы не кикнуло раньше времени
		$this->startTime = time();
		// итак, если у нас не поток (кино), то нужно закрыть источник 
		// и открыть его с новыми клиентскими заголовками
		// НО только если поток уже запущен и воспроизводится
		if (!$this->isRunning()) {
			return;
		}

		// вебсокеты открываются быстро, и сразу отправляют уведомление с headers,
		// но клиент еще не ассоциирован и не получает его. правильнее каждому новому клиенту
		// при регистрации сразу давать заголовки, если они готовы
		// upd: снова косяк. теперь кино глючит. первый коннект получает хедеры,
		// затем идет следующий коннект с новым range, но хедеры еще не обновились (поток не переоткрылся)
		// и клиент тут принимается со старыми заголовками..
		// upd: другой косяк. WMPlayer имеет наглость иногда отправлять через один сокет 2 GET запроса.
		//	и если это кино, то при обработке второго запроса кикаются все клиенты (см.выше),
		//	в т.ч. и сам клиент от второго запроса, т.к. он тот же, что и для первого.
		//	last_request в клиенте очищается и получаем тут при обращении req->isRanged() фатал!
		$req = $client->getLastRequest();
		// DONE хотелось бы все же, чтобы у каждого клиента был определен минимум 1 запрос, 
		// с которым он пришел. иначе нефига ему в этом методе делать
		if ($req->isRanged()) {
			$range = $req->getReqRange();
			$this->cur_conn->seek($range['from']);
			// error_log('Seek to ' . $range['from']);
		}

		$headers = $this->cur_conn->getStreamHeaders(true);
		if ($headers) {
			$client->accept($headers);
		}
	}

	// если транслируем неразобранный chunked-поток, надо позицию искать так,
	// чтобы данные для клиента начинались с длины чанка, как положено
	// иначе пофиг, просто берем 50%
	private function getMiddlePointerPosition() {
		$isChunkedStream = $this->isChunkedStream();
		if ($isChunkedStream) {
			$offset = strlen($this->buffer) / 2;
			$found = preg_match('~(?:\r?\n|^)([0-9a-f]{3,8})\r?\n~smU', $this->buffer, $m, PREG_OFFSET_CAPTURE, $offset);
			if (!$found or !isset($m[1][1])) {
				error_log('Failed to get middle position in chunked stream');
				$pointerPos = 0;
				$pointer = 0;
			} else {
				$pointer = $m[1][1];
				$pointerPos = round(100 * $pointer / strlen($this->buffer));
			}
		} else {
			$pointerPos = 50;
			$pointer = round(strlen($this->buffer) * $pointerPos / 100);
		}
		return array($pointerPos, $pointer);
	}
	
	private function isChunkedStream() {
		return stripos($this->cur_conn->getStreamHeaders(true), 'chunked') !== false;
	}


	// читаем часть трансляции и раздаем зарегенным клиентам
	// вызывается около 33 раз в сек, зависит от usleep в главном цикле
	// наверное где то тут надо отслеживать коннект ace и рестартить поток в случае падения
	public function copyChunk() {
		// таймаут ожидания открытия потока. если источник ничего не выдал - забиваем
		if ($this->state == self::STATE_STARTING) {
			$secPassed = (time() - $this->startTime);
			if ($secPassed > $this->waitSec) {
				// может close+exception заменить одним методом, например error(msg)
				$this->close();
				throw new CoreException('Failed to start stream', 0);
			}
			// return;
		}

		// на данном этапе надо открыть полученную ссылку, и сконнектить общение клиента и Ace,
		// т.е. пробрасывать все запросы клиента в поток, ну и само собой из потока все данные тупо на клиента выдавать
		// клиент запросит несколько различных частей потока и тогда перемотка работает!!
		// класть ли ответные заголовки в буфер или отсеивать?

		$data = null;
		// если режим остановки и буфер похудел - продолжаем чтение
		// TODO эту фигню надо рефакторить и тестировать! глючит
		// и для лайв-режима неактуальна совершенно
		// КОСЯК: проблема была в том, что ТВ поток стопорился внезапно,
		//	и ace-статистика по нему не обновлялась, т.к. не дергался aceconn::readsocket(),
		//	т.к. не вызывался getStreamChunk(), т.к. был режим остановки чтения!
		if (!$this->isLive and $this->stopReading) {
			if ($this->getBufferedLength() <= $this->getBufferLength()) {
				$this->stopReading = false;
			}
		}
		// считываем часть контента из источника
		else {
			$data = $this->cur_conn->getStreamChunk($this->getBufferSize());
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
		$gotLinkTime = (time() - $this->startedTime);
		if (
			$this->isLive and
			($gotLinkTime < self::INIT_SECONDS or
			$this->getBufferedLength() < self::INIT_LENGTH)
		) { // подкопим немного для начала
			return; // // убрал до ввода доп.флага различия старта и финиша
		}

		// на каждого клиента есть указатель на буфер
		// буфер потока один на всех клиентов
		// при старте потока пишем все в буфер, держим его размер постоянным
		foreach ($this->clients as $peer => $client) {
			// на клиента всегда пытаемся писать все, что есть, т.к. максимальными кусками
			$result = $client->put($this->buffer, self::BUF_MAX);
			// TODO это что, такой метод определения eof?
			if ($this->isFinished() and $client->getPointerPosition() == 100) {
				$this->dropClientByPeer($peer);
			}
		}

		$this->trimBuffer();

		return ;
	}

	protected function appendBuffer($data) {
		if ($data) {
			$this->buffer .= $data;
			$this->statistics['dl_total'] += strlen($data);
		}
	}

	// задача метода - держать размер буфера 15-30Мб
	// уведомлять клиентов о необходимости скорректировать указатели
	// кикать зазевавшихся или мертвых клиентов (upd: клиент сам себя кикнет)
	protected function trimBuffer() {
		$len = $this->getBufferedLength();
		$delta = $len - $this->getBufferLength();
		if ($delta > 0) {
			$this->buffer = substr($this->buffer, $delta);
		}
		// а если delta 0 или вдруг < 0?
		$tmp = true;
		// если хоть один клиент уже приближается к концу буфера, надо бы его пополнить
		// т.е. прочитать еще кусок из источника. а если не переставать читать, то
		// клиентов кикнет по достижении начала буфера. типа они отстали от остальных
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
		// логика адаптивной подстройки буфера выключена, фигня
		$adaptiveBuffer = false;

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
			// адаптивная подстройка буфера
			$adaptiveBuffer and $this->bufferSize += 150;
		}
		if ($check) {
			$this->buf_adjusted['lastcheck'] = time();
		}

		// итого, имея период наличия данных и их отсутствия, 
		if ($adaptiveBuffer and $this->buf_adjusted['state1time']) {
			$coeff = $this->buf_adjusted['state1time'] / self::BUF_SECONDS;
			if (!$data) {
				// здесь принимается решение подпихнуть символ вместо данных
				// развитие алгоритма: вместо случайного символа - расходуем буфер FIFO
				// если конечно он есть
				if ($check and !$this->buf_adjusted['state'] and $changeTime > 20) {
					// тут была подстановка в data фейкового байта
				}
			}
			// буфер правим только при переходе "есть - нет"
			if ($statechange and !$this->buf_adjusted['state'] and
				!$this->buf_adjusted['over']) {
				// внимание со степенью: должна быть нечетной, чтобы не потерять знак
				$delta = round(-($this->bufferSize * self::BUF_DELTA_PRC / 100) 
					* pow(2 * (1 - $coeff), 3));
				$this->bufferSize += $delta;
				if ($this->bufferSize > self::BUF_MAX) {
					$this->bufferSize = self::BUF_MAX;
				}
				else if ($this->bufferSize < self::BUF_MIN) {
					$this->bufferSize = self::BUF_MIN;
				}
				$adjusted = array('delta' => $delta, 'buf' => $this->bufferSize);
			}
		}
		if ($statechange) {
			$this->buf_adjusted['over'] = false;
		}
		// HACK нафиг всю эту подстройку буфера
		$adaptiveBuffer or $this->bufferSize = self::BUF_READ;

		if (!isset($this->statistics['speed_dn'])) {
			return $this->bufferSize;
		}
		// такая мысля. смотрим скорость загрузки и выставляем буфер относительно нее
		// чтобы не обогнать наполнение буфера ace своим жадным чтением из него, ибо
		// это провоцирует его уходить в глухой режим буферизации
		// но вот засада - скорость указана в секунду,
		// а мы читаем данные из источника неизвестно сколько раз за секунду, так что
		// даже установление нужного размера буфера не поможет просто так.
		// нужно ограничить чтение данных этим объемом за секунду
		// а еще у нас есть данные по объему скачанных ace данных
		// если отслеживать кол-во прочитанного, то с оглядкой на ту цифру можно
		// и буфер выставить

		// пока данных мало - используем простое определение буфера по скорости
		// имеет смысл только для isLive
		$dl_bytes = $this->statistics['dl_bytes'];
		$dl_total = $this->statistics['dl_total'];
		// когда кино скачалось, скорость падает до 0 и размер буфера вместе с ней
		$dlspeed = $this->statistics['speed_dn'] ? $this->statistics['speed_dn'] : 1;
		// скорость - кбайт/с, буфер - байт, 0.9 - коэфф-т
		$dl_bytes2 = $dl_bytes - self::INIT_LENGTH; // пытаемся отставать ровно на N Мб
		$coeff2 = ($dl_bytes2 - $dl_total) / self::INIT_LENGTH; // пытаемся отставать ровно на N Мб
		// коэф-т надо немного ослабить, а то слишком быстро нагоняет разницу
		// делим на 2
		$this->bufferSize = round($coeff2 * 1024 * $dlspeed / 3);

		if ($this->bufferSize > self::BUF_MAX) {
			$this->bufferSize = self::BUF_MAX;
		}
		else if ($this->bufferSize < self::BUF_MIN) {
			$this->bufferSize = self::BUF_MIN;
		}

		#error_log(sprintf("%.1f\tAce dlb: %d\twe got: %d\tBufSize: %d",
		#	$coeff2, $dl_bytes, $dl_total, $this->bufferSize));
	}

}

