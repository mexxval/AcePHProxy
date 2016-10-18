<?php

class ClientPool {
	const MASTERSOCKET = '_master';

	protected $port;
	protected $socket;
	protected $clients = array(); // объекты клиентов

	public function __construct($ip = '0.0.0.0', $port = 8000) {
		$this->port = $port;
		$this->socket = stream_socket_server(sprintf("tcp://%s:%d", $ip, $this->port), $errno, $errstr);
		if (!$this->socket) {
			throw new Exception("Failed to create socket server. $errstr", $errno);
		}
	}

	public function getPort() {
		return $this->port;
	}

	public function getClients() {
		return $this->clients;
	}

	public function notify() {
		$args = func_get_args();
		foreach ($this->getClients() as $one) {
			call_user_func_array(array($one, 'notify'), $args);
		}
	}

	public function track4new() {
		$read = array($this->socket); // опрашиваем только мастер-сокет (сервер)
		$_ = array();
		$mod_fd = stream_select($read, $_, $_, 0, 20000);
		if ($mod_fd === FALSE) {
			return false;
		}

		// сюда собираем статистику для дальнейшего реагирования
		$newclients = array(); // newly connected
		$doneclients = array(); // disconnected
		$startreq = array(); // client->pid for start

		// флаг необходимости срочно опросить сокеты еще раз
		$recheck = false;

		// теперь надо по всем клиентам пройти и спросить. нет ли у них на сокетах событий
		foreach ($this->clients as $peer => $one) {
			try {
				if ($one->isFinished()) {
					throw new Exception('Close finished client');
				}

				// тут такая логика: xbmc при открытии m3u по каждому элементу коннектится и
				// спрашивает инфу, отдельно. а в главном цикле демона задержка около 30мс
				// из-за нее плейлист из 10 строк открывается секунду.
				// потому делаем так: если поймали исключение HEAD или empty-range - опрашиваем сокет еще
				// но блин проблема в том, что на каждый эл-т плейлиста - отдельный коннект, так что
				// компактненько тут не обойдешься... TODO
				$result = $one->track4new();
				if ($result) {
					$startreq[$peer] = $result;
				}
			}
			catch (Exception $e) {
				// если в возвращаемых методом массивах будут объекты (главный цикл, new),
				// то __destruct не срабатывает, хотя new там перезаписывается каждый цикл, непонятно
				// флаг finished выставляется в методе close(), так что вызывать его еще раз ни к чему
				// upd: нет, надо! клиент может не только по флагу finished сам себя закрыть.
				// но еще и отменить запрос (прервать закачку файла) и отвалиться
				$one->close();
				unset($this->clients[$peer]);
				$doneclients[$peer] = null;
				// ассоциированные трансляции должны удалиться через __destruct клиента
				// тут можно разве что в лог написать
				// error_log('disconnected ' . $peer);

				// чо это такое? и нафиг надо
				// кажется чтобы в логе connected и disconnected были по порядку
				if (in_array($e->getCode(), array(3, 4))) {
					$recheck = true;
				}
			}
		}

		if ($read) { // есть событие на сокете сервера - Новый клиент
			$conn = stream_socket_accept($this->socket, 1, $peer); // peer заполняется по ссылке
			// также при желании пишем в лог о новом коннекте
			// и желательно сразу, а то пока до главного цикла дойдет, окажется что клиент уже и данные прислал
			// а мы после этого только пишем, что он приконнектился
			// еще одна мелкая эстетическая проблемка. когда клиент рвет соединение и тут же создает новое 
			// в логе пишется сначала connected для нового. затем disconnected для старого коннекта
			// error_log('connected ' . $peer);
			$this->clients[$peer] = new StreamClient($peer, $conn);
			$newclients[$peer] = null;
		}

		return array(
			'recheck' => $recheck,
			'new' => $newclients,
			'done' => $doneclients,
			'start' => $startreq
		);
	}
}


