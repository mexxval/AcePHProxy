<?php

class ClientPool {
	const MASTERSOCKET = '_master';

	protected $socket;
	protected $clients = array(); // объекты клиентов

	public function __construct($ip = '0.0.0.0', $port = 8000) {
		$this->socket = stream_socket_server(sprintf("tcp://%s:%d", $ip, $port), $errno, $errstr);
		if (!$this->socket) {
			throw new Exception("Failed to create socket server. $errstr", $errno);
		}
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
		$mod_fd = stream_select($read, $_w = NULL, $_e = NULL, 0, 20000);
		if ($mod_fd === FALSE) {
			return false;
		}

		// сюда собираем статистику для дальнейшего реагирования
		$newclients = array(); // newly connected
		$doneclients = array(); // disconnected
		$startreq = array(); // client->pid for start

		if ($read) { // есть событие на сокете сервера - Новый клиент
			$conn = stream_socket_accept($this->socket, 1, $peer); // peer заполняется по ссылке
			$this->clients[$peer] = new StreamClient($peer, $conn);
			$newclients[$peer] = null;
		}

		// флаг необходимости срочно опросить сокеты еще раз
		$recheck = false;

		// теперь надо по всем клиентам пройти и спросить. нет ли у них на сокетах событий
		foreach ($this->clients as $peer => $one) {
			if ($one->isFinished()) {
				$one->close();
				unset($this->clients[$peer]);
				continue;
			}

			try {
				// тут такая логика: xbmc при открытии m3u по каждому элементу коннектится и
				// спрашивает инфу, отдельно. а в главном цикле демона задержка около 30мс
				// из-за нее плейлист из 10 строк открывается секунду.
				// потому делаем так: если поймали исключение HEAD или empty-range - опрашиваем сокет еще
				// но блин проблема в том, что на каждый эл-т плейлиста - отдельный коннект, так что
				// компактненько тут не обойдешься... TODO
				$result = $one->track4new();
				if ($result) {
					$result['client'] = $one;
					$startreq[$peer] = $result;
				}
			}
			catch (Exception $e) {
				// если в возвращаемых методом массивах будут объекты (главный цикл, new), 
				// то __destruct не срабатывает, хотя new там перезаписывается каждый цикл, непонятно
				$one->close();
				unset($one);
				unset($this->clients[$peer]);
				$doneclients[$peer] = null;

				if (in_array($e->getCode(), array(3, 4))) {
					$recheck = true;
				}
			}
		}

		return array(
			'recheck' => $recheck,
			'new' => $newclients,
			'done' => $doneclients,
			'start' => $startreq
		);
	}

}






