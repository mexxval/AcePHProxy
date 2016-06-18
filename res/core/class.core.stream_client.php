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
	protected $pointer = 0; // указатель на буфер
	protected $pointerPos = 0; // позиция указателя в буфере, %. Т.е. фактически сколько буфера уже ушло на клиент
	protected $tsconnected; // когда подключился
	protected $bytesgot = 0; // сколько данных принял
	protected $isAccepted = false; // отправлены ли на клиент HTTP заголовки

	public function __construct($peer, $socket) {
		$this->peer = $peer;
		$this->socket = $socket;
		stream_set_blocking($this->socket, 0);
		stream_set_timeout($this->socket, 0, 20000);
		$this->tsconnected = time();
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

	public function isFinished() {
		return $this->finished;
	}
	public function isActiveStream() {
		return $this->stream and $this->stream->isActive();
	}
	public function setEcoMode($bool) {
		$this->ecoModeEnabled = (bool) $bool;
	}

	// вызывается при регистрации клиента в потоке, они связываются перекрестными ссылками друг на друга
	public function associateStream(StreamUnit $stream) {
		$this->stream = $stream;
	}


	public function accept($headers, $pointer = 0, $pointerPos = 0) {
		if ($this->isAccepted()) { // клиент уже получил заголовки, пропускаем
			error_log('already accepted!! why again???');
			return;
		}
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
		// Типафича. если буфер Null, пишем всю data, полезно при записи заголовков на клиент
		$writeWhole = is_null($bufSize);
		$dataLen = strlen($data);
		// решение "выдавать по одному" было проблемой для отправки хедеров из accept()

		$correctPointer = true;
		if ($writeWhole) {
			$bufSize = $dataLen;
			$correctPointer = false;
			//	error_log('put on client ' . $data);
		}
		// сразу обновим указатель в %
		$this->pointerPos = $dataLen ? round($this->pointer / $dataLen * 100) : 0; // и его позиции в %

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

		// XBMC при попытке остановить поток во время Ace-буферизации вис до окончания буферизации
		// причина была в том, что весь буфер был уже записан, и флаг ecoMode по сути не работал
		// put был пуст и мы выходили из метода
		// поэтому ecoMode определяем сами как последние 10кБ буфера, думаю этого достаточно, 
		// чтобы по 5 байт выдавать до таймаута самого XBMC
		// работает!
		$ecoMode = ($this->ecoModeEnabled and (!$writeWhole and ($dataLen - $this->pointer) < 100000));
		if ($ecoMode) {
			$bufSize = 3;
		}

		$put = substr($data, $this->pointer, $bufSize);
		if (!$put) {
			return;
		}
		// а вот так работает. хотя функция "Returns a result code, as an integer"
		// проверка же показала, что выдается число байт
		$b = stream_socket_sendto($this->socket, $put);
		// это явно ошибка и корректировать буфер на -1 совсем ни к чему
		if ($b == -1) {
			return $b;
		}
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
		// значит чиатем заголовки и ждем пустой строки

		// хоть логика разбора клиентского запроса и лежит в ClientRequest, но все же
		// мы явно работаем по HTTP, что означает несколько вещей:
		//  - клиент отправляет только 1 запрос в самом начале за все время коннекта
		//  - прежде чем обрабатывать запрос, нужно дождаться пустой строки, как того требует HTTP
		// Следовательно, часть логики поместим тут, а именно, собираем запрос от клиента
		// до пустой строки, и только потом из полученного делаем объект запроса

		$this->raw_read .= $sock_data;

		// для DLNA нужно уметь обрабатывать POST-запросы. если запрос POST, ждем появления двойного переноса строк и
		// контента длиной Content-Length байт. значение смотрим в заголовке
		if (!preg_match('~HTTP/1\..*\r?\n\r?\n(.*)~sm', $this->raw_read, $content)) {
			return false;
		}

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
		// пока буду исходитьиз того, что одно и то же кино смотрит один клиент!

		return $this->last_request = new ClientRequest($this->raw_read, $this);
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
		if (!empty($this->stream)) {
			$this->stream->unregisterClientByName($this->getName());
			unset($this->stream); // без этого лишняя ссылка оставалась в памяти и объект потока не уничтожался
		}
		is_resource($this->socket) and fclose($this->socket);
		$this->finished = true;
	}

	public function __destruct() {
		# error_log(' destruct client ' . spl_object_hash ($this));
	}
}




