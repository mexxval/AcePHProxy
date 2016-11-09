<?php

class StreamClient {
	// 20 ошибок записи в сокет подряд - кикбан
	const BUF_WRITE_ERR_MAXCOUNT = 20;

	protected $peer;
	protected $last_request;
	protected $raw_read; // строка запроса, прочитанная от клиента
	protected $socket;
	protected $stream; // по сути только для сообщения потоку, что клиент отваливается
	protected $finished = false; // выставляется в true когда отключается клиент
	protected $err_counter;
	protected $ecoModeEnabled = true;
	protected $ecoModeRunning = false;
	protected $pointer = 0; // указатель на буфер
	protected $pointerPos = 0; // позиция указателя в буфере, %. Т.е. фактически сколько буфера уже ушло на клиент
	protected $tsconnected; // когда подключился
	protected $bytesgot = 0; // сколько данных принял
	protected $isAccepted = false; // отправлены ли на клиент HTTP заголовки
	protected $listener;

	public function __construct($peer, $socket) {
		$this->peer = $peer;
		$this->socket = $socket;
		stream_set_blocking($this->socket, 0);
		stream_set_timeout($this->socket, 0, 20000);
		$this->tsconnected = time();
		// error_log('construct client ' . spl_object_hash ($this) . "\t" . $peer);
	}

	public function registerEventListener($cb) {
		$this->listener = $cb;
	}
	protected function notifyListener($event) {
		is_callable($this->listener) and call_user_func_array($this->listener, array($this, $event));
	}
	public function getIp() {
		return implode('', array_slice(explode(':', $this->peer), 0, 1));
	}

	public function getName() {
		return $this->peer;
	}
	public function getPointerPosition() {
		return $this->pointerPos;
	}
	public function getUptimeSeconds() {
		return time() - $this->tsconnected;
	}
	public function getUptime() {
		$allsec = $this->getUptimeSeconds();
		// собираем строку времени
		$secs = sprintf('%02ds', $allsec % 60);
		$hours = $mins = '';
		if ($tmp = floor($allsec / 3600)) {
			$hours = sprintf('%dh ', $tmp);
		}
		if ($tmp = floor(($allsec - intval($hours) * 3600) / 60)) {
			$mins = sprintf('%02dm ', $tmp);
		}
		return $hours . $mins . $secs;
	}
	public function getTraffic() {
		$bytes = $this->bytesgot;
		if ($bytes < 1024) {
			$units = 'B';
		} else if (($bytes /= 1024) < 1024) {
			$units = 'kB';
		} else if (($bytes /= 1024) < 1024) {
			$units = 'MB';
		} else if (($bytes /= 1024) < 1024) {
			$units = 'GB';
		} else {
			$units = 'TB';
		}
		return sprintf('%d %s', $bytes, $units);
	}
	// возвращает известные типы клиентов. например название плеера, браузера
	public function getType() {
		$req = $this->getLastRequest();
		// коннект уже может быть, но оформленного реквеста может не быть
		if (!$req) {
			return false;
		}

		$ua = $req->getUserAgent();
		$map = array(
			'chrome' => 'chrome',
			'vlc' => 'vlc',
			'kodi' => 'kodi',
			'xbmc' => 'xbmc',
			'wmp' => array('wmfsdk', 'Windows-Media-Player'),
		);
		$pattern = array();
		foreach ($map as $name => $subptrn) {
			is_array($subptrn) or $subptrn = array($subptrn);
			$pattern[] = sprintf('(?<%s>(%s))', $name, implode('|', $subptrn));
		}
		$pattern = '~(' . implode('|', $pattern) . ')~sUi';

		if (!preg_match($pattern, $ua, $m)) {
			return false;
		}
		$res = array_filter(array_intersect_key($m, $map), 'strlen');
		if (!$res) {
			return false;
		}
		reset($res);
		return key($res);
	}

	public function isFinished() {
		return $this->finished;
	}
	public function isActiveStream() {
		return $this->stream and $this->stream->isActive();
	}
	public function setEcoMode($bool) {
		//error_log('Setting eco mode to ' . ($bool ? 'TRUE' : 'false'));
		$this->ecoModeEnabled = (bool) $bool;
	}
	// включена ли функция экорежима вообще
	public function isEcoMode() {
		return $this->ecoModeEnabled;
	}
	// задействована ли функция экорежима в данный момент (буфер опустел?)
	public function isEcoModeRunning() {
		return $this->ecoModeRunning;
	}

	// вызывается при регистрации клиента в потоке, они связываются перекрестными ссылками друг на друга
	public function associateStream(StreamUnit $stream) {
		$this->stream = $stream;
		// новая связка - возможно новая регистрация того же клиента
		// значит будет новый вызов accept()
		$this->isAccepted = false;
	}


	public function accept($headers, $pointer = 0, $pointerPos = 0) {
		if ($this->isAccepted()) { // клиент уже получил заголовки, пропускаем
			error_log(sprintf('already accepted %s!! why again???', $this->getName()));
			return;
		}
		# error_log(sprintf('ACCEPT %s with %s ptr %d', $this->getName(), json_encode($headers), $pointer));
		$this->pointer = 0;
		$this->pointerPos = 0;
		$this->isAccepted = true;
		// отправляем заголовки, тут pointer дб равен 0
		$res = $this->put($headers);
		// затем выставляем указатель в требуемое положение
		$this->pointer = (int) $pointer;
		$this->pointerPos = (int) $pointerPos;
		return $res;
	}

	public function isAccepted() {
		return $this->isAccepted;
	}

	// затея: сюда передается большой буфер из StreamUnit. мы храним указатель и самостоятельно берем нужную часть
	// bufSize - сколько клиент должен себе забрать
	public function put(&$data, $bufSize = null) {
		if (!$this->socket) {
			throw new Exception('inactive client socket', 10);
		}

		// пробуем использовать stream_select()
		// а проблема в том, что XBMC набрал себе буфера секунд 5-8, и больше не лезет, 
		// а Ace транслирует и читать это приходится, разве что излишки в памяти хранить
		// upd: это только для трансляций. для фильмов надо переставать читать по какому то условию
		$write = array($this->socket);
		$_ = null;
		$mod_fd = stream_select($_, $write, $_, 0, 20000);
		if ($mod_fd === FALSE) {
			return false;
		}
		// когда клиент тупо вырубается (по питанию, инет упал и т.д.) - он застревает тут
		// вообще то клиент и при нормальной работе достаточно часто тут оказывается
		// для VLC http://joxi.ru/48Ang31hopYNrO
		// для XBMC бывает и так http://joxi.ru/dp27Dgpcn4M5A7 от 16 до 92 была сплошь неготовность
		// вопрос по детекту отвалившегося сокета. правда без ответа
		// https://stackoverflow.com/questions/16715313/how-can-i-detect-when-a-stream-client-is-no-longer-available-in-php-eg-network
		if (!$write) {
			return null;
		}
		$sock = reset($write);
		
		// fwrite отличается тем, что не врет, что записал весь буфер в неактивный сокет
		// но с ней другая проблема, картинка периодически разваливается, затем снова восстанавливается
		// можно юзать .._sendto, а ошибки мониторить через error_get_last, 
		// к тому же реальное число записанных байт не пригодилось
		#$res = @fwrite($this->socket, $data); // @ чтоб ошибки в лог не сыпались
		#$res = @stream_socket_sendto($this->socket, $data);

		// если запись не удалась, надо бы как то попытаться еще раз.. может в буфер себе сохранить
		// вот еще по ошибке 11
		// http://stackoverflow.com/questions/14370489/what-can-cause-a-resource-temporarily-unavailable-on-sock-send-command

		// Типафича. если буфер Null, пишем всю data, полезно при записи заголовков на клиент
		$writeWhole = is_null($bufSize);
		// $data это большой общий разделяемый буфер. передается сюда по ссылке
		$dataLen = strlen($data);
		// решение "выдавать по одному" было проблемой для отправки хедеров из accept()

		// XBMC при попытке остановить поток во время Ace-буферизации вис до окончания буферизации
		// причина была в том, что весь буфер был уже записан, и флаг ecoMode по сути не работал
		// put был пуст и мы выходили из метода
		// поэтому ecoMode определяем сами как последние 10кБ буфера, думаю этого достаточно,
		// чтобы по 5 байт выдавать до таймаута самого XBMC
		// работает!
		// ecoMode - выдача данных по 1 байту, т.к. XBMC при отсутствии данных вешается нахер,
		// не реагирует ни на какие раздражители, пока не отвалится по таймауту
		// upd: чтобы это работало, нужно подкорректировать bufSize, чтобы тот не вылез за пределы dataLen
		// например: в этот проход разница данные-указатель > 50000, а bufSize = 256000, 
		// и в след.проход на клиента пишутся остатки буфера и ничего для ecoMode не остается
		$ecoModeTailLength = 1000;

		$correctPointer = true;
		if ($writeWhole) {
			$bufSize = $dataLen;
			$correctPointer = false;
			//	error_log('put on client ' . $data);
		} else {
			// иначе проверим, сколько осталось до конца буфера
			// надо оставить хвост для выдачи по 1 байту
			// например для ТВ потока при буфере 512к и остатке данных 510к
			// ecoMode включится очень рано, задолго до заданных 1кБ
			// нужно подрезать буфер
			// отличный эффект, клиенты держатся чуть не на последнем байте,
			// стабильность улучшена (xbmc не буферизует)
			if (
				($this->pointer + $bufSize) > $dataLen and
				($this->pointer + $ecoModeTailLength) < $dataLen // защита от ухода в минус
			) {
				$bufSize = $dataLen - $this->pointer - $ecoModeTailLength;
				// error_log('buffer trimmed to ' . $bufSize);
			}
		}

		// ecoMode представляет проблему для режима просмотра фильма,
		// но очень и очень полезен для режима ТВ:
		// дает стабильность и XBMC быстрее отрабатывает остановку потока
		$this->ecoModeRunning = ($this->isEcoMode() and !$writeWhole and
			($this->pointer + $bufSize) > $dataLen);
		if ($this->ecoModeRunning) {
			$bufSize = 1;
		}


		// сразу обновим указатель в %
		$this->pointerPos = $dataLen ? round($this->pointer / $dataLen * 100) : 0; // и его позиции в %

		$put = substr($data, $this->pointer, $bufSize);
		if (!$put) {
			# error_log('No data for client got from buffer');
			return;
		}
		// а вот так работает. хотя функция "Returns a result code, as an integer"
		// проверка же показала, что выдается число байт
		$b = stream_socket_sendto($this->socket, $put);
		if ($writeWhole and $b < strlen($put)) {
			error_log('Some data failed to write on client!');
		}
		// это явно ошибка и корректировать буфер на -1 совсем ни к чему
		if ($b == -1) {
			return $b;
		}
		# error_log($b . ' bytes was written on client');
		// а $b может быть false или другим не-числом?
		if ($correctPointer) {
			$this->pointer += $b; // корректировка указателя
			$this->pointerPos = $dataLen ? round($this->pointer / $dataLen * 100) : 0; // и его позиции в %
		}
		// обновим статистику записанных на клиента байт
		$this->bytesgot += $b;

		// если сокет полон и дальше не лезет - выдаем сколько байт НЕ записалось
		return $bufSize - $b;
	}

	// когда буфер триммируется, все клиенты получают команду на смещение указателей
	public function correctBufferPointer($bytes, &$buffer) {
		if ($bytes <= 0) {
			return;
		}

		$this->pointer -= $bytes;
		// видимо буфер уже очищается, а мы все еще не прочитали его. может мы мертвы?
		if ($this->pointer < 0) {
			$this->pointer = 0;
			// проблема подключения нового клиента, его сразу кикает в половине случаев
			// будем кикать только если клиент хотя бы пару секунд был на связи
			if ($this->getUptimeSeconds() > 20) {
				error_log('Close on negative pointer');
				$this->close();
			}
			return;
		}
		$dataLen = strlen($buffer);
		$this->pointerPos = $dataLen ? round($this->pointer / $dataLen * 100) : 0; // и его позиции в %
	}

	public function getLastRequest() {
		return $this->last_request;
	}

	public function track4new() {
		$read = array($this->socket);
		$_ = null;
		$mod_fd = stream_select($read, $_, $_, 0, 20000);
		if ($mod_fd === FALSE) {
			return false;
		}
		if (!$read) {
			return null;
		}
		$sock = reset($read);

		$sock_data = stream_socket_recvfrom($sock, 1024);
		if (strlen($sock_data) === 0) { // connection closed, works
			throw new Exception('Disconnect', 1);
		} else if ($sock_data === FALSE) {
			throw new Exception('Something bad happened', 2);
		}

		// TODO тут надо читать HTTP запрос целиком, до тех пор, пока не встретится пустая строка
		// таймаут? хз, может клиент сам отвалится если что.. ну или вводить тут конечный автомат
		// если клиент начал что-то похожее на GET / передавать, 
		// значит читаем заголовки и ждем пустой строки

		// хоть логика разбора клиентского запроса и лежит в ClientRequest, но все же
		// мы явно работаем по HTTP, что означает несколько вещей:
		//  - клиент отправляет только 1 запрос в самом начале за все время коннекта
		//  - прежде чем обрабатывать запрос, нужно дождаться пустой строки, как того требует HTTP
		// Следовательно, часть логики поместим тут, а именно, собираем запрос от клиента
		// до пустой строки, и только потом из полученного делаем объект запроса

		$this->raw_read = $sock_data;

		// TODO требуется такая логика: первые данные при коннекте нового клиента должны обрабатываться через
		// clientPool как и сейчас. а дальнейшие данные уже в определенном плагине
		// т.е. прочитали первую строку GET /websrv/... HTTP/1.0, выдали как объект ClientRequest
		// дальше в случае гет-запроса данных не будет. 
		// А вот в случае POST, как раз вместо того, что реализовано ниже (проверка длины пост-данных),
		// можно было бы это делать уже в недрах соответствующего модуля
		// В случае вебсокетов тоже - handshake читается тут, дальше работает модуль: все остальные
		// данные с клиента и на него обрабатываются в плагине
		// А тут логика упрощается донельзя. И нечего тут развесистые условия разводить.

		// для DLNA нужно уметь обрабатывать POST-запросы. если запрос POST, ждем появления двойного переноса строк и
		// контента длиной Content-Length байт. значение смотрим в заголовке
		if (!preg_match('~HTTP/1\..*\r?\n\r?\n(.*)~sm', $this->raw_read, $content)) {
			// одно из двух. либо клиент прислал реально какую-то чушь. 
			// либо это доп.данные, через вебсокеты например
			// error_log('wrong request from client: ' . $this->raw_read);
			if ($this->last_request) { // запрос был создан, значит доп.данные
				// добавляем данные в запрос, но сам его не возвращаем из метода,
				// иначе он будет повторно обработан, а это совсем ни к чему.
				// возврат объекта ClientRequest отсюда равносилен старту какого-то потока!
				// TODO решить с этим -> $this->last_request->addData($sock_data);
				$this->notifyListener(array('moredata' => $sock_data));
			}
			return false;
		}

		// РЕАЛИЗАЦИЯ: если мы тут, значит начало данных верное (HTTP протокол, GET-POST)
		// создаем объект запроса сразу
		$this->last_request = new ClientRequest($this->raw_read, $this);

		// TODO это уже надо переносить в модуль, т.к. raw_read уже не наращивается через .=
		$isPost = substr($this->raw_read, 0, 4) == 'POST';
		// проконтролируем длину полученного контента
		if ($isPost) {
			$contentLength = null;
			preg_match('~Content-Length: (\d+)~i', $this->raw_read, $m);
			$contentLength = $m[1];
			$content = $content[1];
			$allDataGot = (!$contentLength or strlen($content) == $contentLength);
			if (!$allDataGot) {
				error_log('PARTIAL POST DATA ' . strlen($content) . ' of ' . $contentLength);
				return false;
			}
		}

		// далее предстоит реализация перемотки кино
		// оно хоть и касается только кино, но по сути это есть правильная работа с 
		// HTTP/1.1 206 Partial Content и Range: bytes=..
		// Плеер (на примере VLC) отправляет отправляет исходный запрос с Range: bytes=0-
		// мы отвечаем ему HTTP/1.1 206 Partial Content, Content-Range: bytes 0-2200333187/2200333188
		// при перемотке плеер закрывает текущий коннект и открывает новый, 
		// с новым значением Range: bytes=
		// думаю при этом логика работы с потоком дб такая: закрываем поток от AceServer,
		// открываем заново, передаем туда заголовки от клиента, читаем заголовки из потока, 
		// выдаем их клиенту. по идее на этом все
		// показывать кино параллельно на несколько устройств было бы можно, 
		// если бы это поддерживал AceServer. Подключиться к потоку можно только в 1 поток :)
		// соответственно, размножить видео в принципе можно, но перематывать сможет только кто-то один
		// пока буду исходить из того, что одно и то же кино смотрит один клиент!

		return $this->last_request;
	}

	// новая фича, пробуем уведомить XBMC-клиента об ошибке (popup уведомление)
	// работает! :)
	// notify all уведомляет всех клиентов, хз чем фича мб полезна
	//	{"id":2,"jsonrpc":"2.0","method":"JSONRPC.NotifyAll","params":{"sender":"me","message":"he","data":"testdata"}}
	//  тут же получаю уведомление 
	//	{"jsonrpc":"2.0","method":"Other.he","params":{"data":"testdata","sender":"me"}}
	//  и отчет о выполнении команды {"id":2,"jsonrpc":"2.0","result":"OK"}
	public function notify($note, $type = 'info') {
		$ip = $this->getIp();
		error_log('NOTE on ' . $ip . ':' . $note);

		$conn = @stream_socket_client('tcp://' . $ip . ':9090', $e, $e, 0.01, STREAM_CLIENT_CONNECT);
		if ($conn) {
			switch ($type) {
				case 'info':
					$dtime = 1500;
					break;
				case 'warning':
					$dtime = 3000;
					break;
				default:
					$dtime = 4000;
			}

			$json = array(
					'jsonrpc' => '2.0',
					'id' => 1,
					'method' => 'GUI.ShowNotification',
					'params' => array(
						'title' => 'AcePHP ' . $type,
						'message' => $note,
						'image' => 'http://kodi.wiki/images/c/c9/Logo.png',
						'displaytime' => $dtime
					)
			);
			$json = json_encode($json);
			$res = @stream_socket_sendto($conn, $json);
			fclose($conn);
		}
	}

	public function close() {
		// без этого не уничтожались объекты клиентов после их дисконнекта
		unset($this->last_request);

		if (!empty($this->stream)) {
			$this->stream->unregisterClientByName($this->getName());
			unset($this->stream); // без этого лишняя ссылка оставалась в памяти и объект потока не уничтожался
		}
		is_resource($this->socket) and fclose($this->socket);
		$this->finished = true;
		$this->notifyListener(array('event' => 'close'));
		// error_log('  client closed');
	}

	public function __destruct() {
	//	error_log(' destruct client ' . spl_object_hash ($this) . "\t" . $this->getName());
	}
}




