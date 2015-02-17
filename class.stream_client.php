<?php

class StreamClient {
	// 20 ошибок записи в сокет подряд - кикбан
	const BUF_WRITE_ERR_MAXCOUNT = 20;

	protected $peer;
	protected $socket;
	protected $stream;
	protected $finished = false; // выставляется в true когда отключается клиент
	protected $err_counter;
	protected $buffer = array();

	public function __construct($peer, $socket) {
		$this->peer = $peer;
		$this->socket = $socket;
		stream_set_blocking($this->socket, 0);
		stream_set_timeout($this->socket, 0, 20000);
		#stream_set_write_buffer($this->socket, 128000); // хер помогает
	}
	public function getBuffersCount() {
		return count($this->buffer);
	}

	public function getName() {
		return $this->peer;
	}
	public function isFinished() {
		return $this->finished;
	}
	public function isActiveStream() {
		return $this->stream and $this->stream->isActive();
	}

	// вызывается при регистрации клиента в потоке, они связываются перекрестными ссылками друг на друга
	public function associateStream(StreamUnit $stream) {
		$this->stream = $stream;
	}

	public function accept() {
		$headers = 'HTTP/1.0 200 OK' . "\r\n" . 'Connection: keep-alive' . "\r\n\r\n";
		return $this->put($headers);
	}

	public function copy($src_res, $buf) {
		return stream_copy_to_stream ($src_res, $this->socket, $buf);
	}
	public function put($data) {
		if (!$this->socket) {
			throw new Exception('inactive client socket', 10);
		}

		// сразу в буфер, на случай если выйдем по неготовности сокета
		if ($data) {
			$this->buffer[] = $data;
		}
		// будем кикать тех, у кого буфер слишком вырос. не знаю как еще опрделить, что клиент мертв
		if (count($this->buffer) > 300) {
			// 300 буферов HD дискавери это около 45Mb
			error_log('buffer kickban ' . $this->getName());
			return $this->close();
		}

		// пробуем использовать stream_select()
		// а проблема в том, что XBMC набрал себе буфера секунд 5-8, и больше не лезет, 
		// а ace транслирует и читать это приходится, разве что излишки в памяти хранить
		$write = array($this->socket);
		$mod_fd = stream_select($_r = NULL, $write, $_e = NULL, 0, 20000);
		if ($mod_fd === FALSE) {
			return false;
		}
		// когда клиент тупо вырубается (по питанию, инет упал и т.д.) - он застревает тут
		if (!$write) {
			$cnt = count($this->buffer);
			if ($cnt % 10 == 0) {
				#error_log('write socket not ready2. ' . $cnt . ' buffered');
			}
			return null;
		}
		$sock = reset($write);
		

		// передаем буфер. тут он заполняется снизу, а расходуется сверху
		$b = 0;
		while ($tmp = array_shift($this->buffer)) {
			$b = @fwrite($this->socket, $tmp); // @ чтоб ошибки в лог не сыпались
			$this->checkForWriteError();
			// если сокет полон и дальше не лезет - выходим
			if ($b != strlen($tmp)) {
				#error_log(count($this->buffer) . ' buffers left');
				// видимо в сокет уже не лезет, вернем в буфер что осталось и выходим
				array_unshift($this->buffer, substr($tmp, $b));
				break;
			}
		}

		// fwrite отличается тем, что не врет, что записал весь буфер в неактивный сокет
		// но с ней другая проблема, картинка периодически разваливается, затем снова восстанавливается
		// можно юзать .._sendto, а ошибки мониторить через error_get_last, 
		// к тому же реальное число записанных байт не пригодилось
		#$res = @fwrite($this->socket, $data); // @ чтоб ошибки в лог не сыпались
		#$res = @stream_socket_sendto($this->socket, $data);

		// если запись не удалась, надо бы как то попытаться еще раз.. может в буфер себе сохранить
		// вот еще по ошибке 11
		// http://stackoverflow.com/questions/14370489/what-can-cause-a-resource-temporarily-unavailable-on-sock-send-command

		// поскольку грубо выключенный комп не успевает правильно закрыть сокет, трансляция пишет "вникуда"
		// будем считать число ошибок записи в сокет подряд (при успешной записи счетчик в 0)
		// по достижении порога ошибок - кикаем сами себя

		return $b;
	}
	protected function checkForWriteError() {
		$err = error_get_last();
		@trigger_error(""); // "очистить" ошибку, по другому хз как
		// можно и просто по fwrite смотреть
		$match = ($err['message'] and strpos($err['message'], 'Resource temporarily unavailable'));
		if (!$match) {
			$this->err_counter = 0;
		}
		else {
			$this->err_counter++;
		}

		if ($this->err_counter > self::BUF_WRITE_ERR_MAXCOUNT) {
			error_log('error kickban ' . $this->getName());
			$this->close();
		}
	}

	public function track4new() {
		$read = array($this->socket);
		$mod_fd = stream_select($read, $_w = NULL, $_e = NULL, 0, 20000);
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

		// в этой ветке можем читать запрос клиента на запуск канала
		// http://localhost:8000/pid/43b12325cd848b7513c42bd265a62e89f635ab08/Russia24
		// закрывать коннект не надо
		// error_log(date('H:i:s') . " Client sent: " . $sock_data);
		if (preg_match('~^HEAD.*HTTP~smU', $sock_data, $m)) {
			throw new Exception('HEAD request not supported', 3);
		}
		else if (preg_match('~Range: bytes=0\-0~smU', $sock_data, $m)) {
			throw new Exception('Skip empty range request');
		}

		// start by PID
		if (preg_match('~GET\s/pid/([^/]*)/(.*)\sHTTP~smU', $sock_data, $m)) {
			$pid = $m[1];
			return array('pid' => $pid, 'name' => $m[2], 'type' => 'pid');
		}
		// start by translation ID (http://torrent-tv.ru/torrent-online.php?translation=?)
		else if (preg_match('~GET\s/trid/(\d+)/(.*)\sHTTP~smU', $sock_data, $m)) {
			$id = $m[1];
			return array('pid' => $id, 'name' => $m[2], 'type' => 'trid');
		}
		// start by channel name (how?)
	}

	public function close() {
		if (!empty($this->stream)) {
			$this->stream->unregisterClientByName($this->getName());
			#error_log('unregister');
			unset($this->stream); // без этого лишняя ссылка оставалась в памяти и объект потока не уничтожался
		}
		is_resource($this->socket) and fclose($this->socket);
		//unset($this->socket);
		$this->finished = true;
	}

	public function __destruct() {
#error_log('__destruct client ');
	}
}

