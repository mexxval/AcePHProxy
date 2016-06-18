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
	const INIT_LENGTH = 2e6; // 2mb

	const STATE_STARTING = 0x01; // самое начало, когда только-только вызван метод start
	const STATE_STARTED = 0x02; // поток пошел
	const STATE_IDLE = 0x09;

	protected $buffer = ''; // сюда большой строкой будет записываться буфер
	protected $isLive = false;
	
	protected $bufferSize;
	protected $buf_adjusted = array();
	protected $statistics = array();
	protected $state; // состояние конечного автомата
	protected $cur_conn;

	protected $clients = array();
	protected $startTime; // время запроса потока
	protected $waitSec; // сколько секунд ждать ссылки на поток
	protected $finished = false; // выставляется в true когда отключается последний клиент
	protected $stopReading = false;

	public function __construct($conn) {
		$this->cur_conn = $conn; // КЛАСС источника потока, у него там тоже конечный автомат есть
		$this->cur_conn->registerEventListener(array($this, 'connectionListener'));
		$this->state = self::STATE_IDLE;

		$this->init();

		// ace-related code! TODO
		// в принципе все эти ace-параметры можно и к другим источникам применить
		$this->statistics = array(
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
	}

	public function __destruct() {
		# error_log(' destruct stream ' . spl_object_hash ($this));
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
		return $this->cur_conn->isRestarting();
	}

	protected function closeStream() {
		return $this->cur_conn and $this->cur_conn->close();
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

	public function getName() {
		return $this->cur_conn->getName();
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
			$this->close();
		}
		// вообще этот метод дергается только при наличии ответа от Ace, а если тот будет молчать, можем застрять
		else if ($this->state == self::STATE_STARTING and !empty($stats['started'])) {
			$this->state = self::STATE_STARTED;
			$this->isLive = $this->cur_conn->isLive();
		}

		if (!empty($stats['headers'])) { // готовы хедеры в ответ на запрос пользователя, отдаем
			// поток открыт, пора всех клиентов оповестить и раздать им заголовки
			foreach ($this->getClients() as $c) {
				// отправляем хттп заголовки ОК
				$c->accept($stats['headers']);
			}
		}
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
			$this->buffer = '';
		}

		$peer = $client->getName();
		$this->clients[$peer] = $client;
		$client->associateStream($this);

		if ($this->isLive) {
			// если поток уже открыт и воспроизводится, то похоже это дополнительные клиенты
			// надо им отослать заголовки! а то внезапно оказалось, что более 1 клиента на одну 
			// трансляцию перестало обслуживаться
			// для Live-режима заголовки те же самые, одинаковые для всех
			// для просмотра торрентов отдельная песня. там разные Range: bytes должны быть
			if ($this->isRunning())	{
				// попробуем решить проблему отвала VLC по negative counter таким способом:
				// нового клиента цепляем на середину буфера
				$pointerPos = 50;
				$pointer = round(strlen($this->buffer) * $pointerPos / 100);
				$headers = $this->cur_conn->getStreamHeaders(true);
				$client->accept($headers, $pointer, $pointerPos);
			}
			return;
		}

		$client->setEcoMode(false);
		// сбросим метку начала старта, чтобы не кикнуло раньше времени
		$this->startTime = time();
		// итак, если у нас не поток (кино), то нужно закрыть источник 
		// и открыть его с новыми клиентскими заголовками
		// НО только если поток уже запущен и воспроизводится
		if (!$this->isRunning()) {
			return;
		}
		$req = $client->getLastRequest();
		if ($req->isRanged()) {
			$range = $req->getReqRange();
			$this->cur_conn->seek($range['from']);
			error_log('Seek to ' . $range['from']);
		} else {
			error_log('Cannot seek, expected ranged request');
		}
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
				throw new Exception('Failed to start stream');
			}
			// return;
		}

		// на данном этапе надо открыть полученную ссылку, и сконнектить общение клиента и Ace,
		// т.е. пробрасывать все запросы клиента в поток, ну и само собой из потока все данные тупо на клиента выдавать
		// клиент запросит несколько различных частей потока и тогда перемотка работает!!
		// класть ли ответные заголовки в буфер или отсеивать?

		$data = null;
		// если режим остановки и буфер похудел - продолжаем чтение
		if ($this->stopReading) {
			if ($this->getBufferedLength() <= self::BUFFER_LENGTH) {
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
		if ($this->isLive and $this->getBufferedLength() < self::INIT_LENGTH) { // подкопим немного для начала
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
	}

}

