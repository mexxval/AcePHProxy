<?php

class StreamUnit {
	const BUF_READ = 128000; // bytes
	const BUF_MIN = 8000;
	const BUF_MAX = 512000;
	const BUF_SECONDS = 30;
	const BUF_DELTA_PRC = 5;

	protected $ace;
	protected $resource;
	protected $bufferSize;
	protected $buf_adjusted = array();
	protected $statistics = array();
	protected $clients = array();
	protected $cur_pid; // текущий id запущенной трансляции
	protected $cur_name;// и название
	protected $finished = false; // выставляется в true когда отключается последний клиент
	protected $FIFO = array(); // небольшой буфер в приложении

	public function __construct(AceConnect $ace, $bufSize = null) {
		$this->ace = $ace;
		// можно передать сюда и буфер из кэша настроек
		$this->init($bufSize);
	}

	public function start($pid, $name) {
		try {
			$this->cur_pid = $pid;
			$this->cur_name = $name;

			$link = $this->ace->startpid($pid);
			if (!$link) {
				throw new Exception('Failed to get link');
			}
			if (!($link_src = fopen($link, 'r'))) {
				throw new Exception('Failed to open stream link');
			}
			stream_set_timeout($link_src, 0, 10000); // works?
			$this->resource = $link_src;
		}
		catch (Exception $e) {
			$this->close();
			$this->finished = true;
			throw $e;
		}
	}

	public function __destruct() {
#error_log('__destruct stream');
	}

	public function close() {
		// вообще по идее при уничтожении объекта будут вызваны __destruct и всех вложенных
		foreach ($this->clients as $idx => $one) {
			#$one->close();
			unset($this->clients[$idx]);
		}

		is_resource($this->resource) and fclose($this->resource);
		$this->ace->stoppid($this->cur_pid);
	}

	public function unfinish() {
		$this->finished = false;
	}

	public function isFinished() {
		return $this->finished;
	}
	
	public function isActive() {
		return is_resource($this->resource);
	}

	public function getStatistics() {
		return $this->statistics;
	}

	public function getBuffer() {
		return $this->bufferSize;
	}

	public function getState() {
		$state = @$this->statistics['acestate'];
		if ($state == 'buf') {
			$set = array(
				//iconv('cp866', 'utf8', chr(0xf8)),
				iconv('cp866', 'utf8', chr(0x27)), // ' (апостроф, точки вверху не нашел)
				iconv('cp866', 'utf8', chr(0xf9)),	// точка в центре
				'.'	// точка внизу
			);
			$sign = $set[time() % count($set)];
			$perc = $this->statistics['bufpercent'];
			$state = $sign . ' ' . $perc . '%';
		}
		else if ($state == 'dl') {
			$state = 'PLAY';
		}
		return $state;
	}

	public function getName() {
		return $this->cur_name;
	}

	public function getPeers() {
		return $this->clients;
	}

	public function getPID() {
		return $this->cur_pid;
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
	    // отправляем хттп заголовки ОК
		$client->accept();
		$peer = $client->getName();
		$this->clients[$peer] = $client;
		$client->associateStream($this);
	}

	public function unregisterClientByName($peer) {
		unset($this->clients[$peer]);
		if (empty($this->clients)) { // пора сворачивать кино
			// выставим флаг, а StreamsManager по нему поставит нас в очередь на остановку
			$this->finished = true;
		}
	}

	// читаем часть трансляции и раздаем зарегенным клиентам
	// вызывается около 33 раз в сек, зависит от usleep в главном цикле
	public function copyChunk() {
		if (!$this->resource) {
			return false;
		}

		$conn = $this->ace->getConnection($this->cur_pid);
		$conn->readsocket(0, 20000, $dlstat); // читаем лог понемногу, сигналы сервера можно отслеживать
		// копируем контент в сокет
		$data = fread($this->resource, $this->bufferSize); // TODO small timeout

		// набираем FIFO в 10 буферов. добавляем новое в начало массива (верх), а снимаем снизу
		if ($data) {
			array_unshift($this->FIFO, $data);
			if (count($this->FIFO) < 10) {
				if (strlen($data) == $this->bufferSize) {
					return;
				}
			}
		}

		// если данные пусты, надо выдавать по несколько байт из последнего элемента FIFO,
		// чтобы XBMC дал нормально остановить при желании поток. 
		// а то он пока байта не прочитает будет висеть (или до таймаута своего)
		// впрочем содержимое data будет поправлено внутри метода и выдано по ссылке

		// сюда передаем текущий кусок, пока он пустой - выдаем по несколько байт 
		// из последнего FIFO, как пойдет чтение - снова большими кусками будем кормить клиента
		// на выходе имеем данные из FIFO, вся логика в методе
		$buffer = $this->adjustBuffer($data);
#error_log('fifo ' . count($this->FIFO) . ', buf=' . strlen($buffer) . ', data=' . strlen($data));

		$this->statistics = array_merge($this->statistics, $this->buf_adjusted);
		if ($dlstat) {
			$this->statistics = array_merge($this->statistics, $dlstat);
		}

		// опытно подобранный размер буфера, не приводящий к проблеме рассыпания картинки
		$blkg = $this->bufferSize > 70000;
		foreach ($this->clients as $peer => $client) {
			$result = $client->put($buffer, $blkg);
		}
		unset($client);

		return ;
	}

	// data на входе только для контроля ситуации, идет ли считывание из ace
	// на основании этого метод выдаст кусок из FIFO, большой или малый
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

		// способ 2: идем от заведомо большого буфера. заполняем массив buf_adjusted данными
		// по каждому pid: время перехода "нет данных - пошли данные" и обратно
		// по этим цифрам вычисляем, за сколько времени поток вычитывается и сколько потом простаивает
		// вычисляем соотношение и пропорционально корректируем буфер

		// чето говно одно выходит

		// способ 3, гениальный )
		// считаем, сколько обычно секунд занимает пауза между буферизациями
		// если пауза затянулась на 20%-30%, начинаем отдавать пустые байты
		// 
		// СПОСОБ 4, тупой
		// время считывания контента должно быть секунд 30, подстраиваем буфер под это

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
			$this->bufferSize += 150;
		}
		if ($check) {
			$this->buf_adjusted['lastcheck'] = time();
		}

		// итого, имея период наличия данных и их отсутствия, 
		if ($this->buf_adjusted['state1time']) {
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

		$bigPart = !empty($data); // выдавать весь последний элемент FIFO (true) или отщипывать от него

		// итак, начинаем расходовать буфер
		if ($bigPart) {
			$buffer = array_pop($this->FIFO);
		}
		else {
			$buffer = $this->getPartOfFIFO(500); // 5 bytes
		}
		return $buffer;
	}
	// отщипываем от FIFO часть данных, чтобы XBMC не вис в ожидании байт из сокета, 
	// игнорируя команды юзера остановить поток
	// если FIFO пуст, это интересно.. такого быть вообще не должно
	protected function getPartOfFIFO($bytes = 10) {
		// тут важно не напутать с какого конца откусить и в каком порядке вообще данные уходят на клиент
		// типа наш буфер такой: [0 => 'abc', 1 => 'def', ..., 9 => '123456789']
		// и нам нужны 5 байт. ясень пень что элемент будет 9
		// мануал говорит, что запись идет с 0 символа, т.е. откусывать надо строку 12345, с начала
		// берем элемент с конца FIFO, откусываем N байт, если что осталось - кладем обратно в FIFO
		$tmp = array_pop($this->FIFO);
		$buffer = substr($tmp, 0, $bytes);
		if (strlen($tmp) > $bytes) {
			array_push($this->FIFO, substr($tmp, $bytes));
		}

		#error_log('FIFO ' . count($this->FIFO) . ' items, last len=' . strlen(end($this->FIFO)));
		return $buffer;
	}
}

