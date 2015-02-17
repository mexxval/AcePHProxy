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

		// теперь надо по всем клиентам пройти и спросить. нет ли у них на сокетах событий
		foreach ($this->clients as $peer => $one) {
			if ($one->isFinished()) {
				$one->close();
				unset($this->clients[$peer]);
				continue;
			}

			try {
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
			}
		}

		return array(
			'new' => $newclients,
			'done' => $doneclients,
			'start' => $startreq
		);
	}

}






