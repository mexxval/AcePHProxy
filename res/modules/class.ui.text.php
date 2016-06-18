<?php

// текстовый консольный интерфейс на случай, если нет ncurses
class AppUI_Text implements AppUserInterface {
	public function init() {
	}
	public function draw($streams, $addinfo) {
		$clients = 0;
		foreach ($streams as $one) {
			$clients += count($one->getPeers());
		}
		echo 'Active streams: ' . count($streams) . "\t" . 'Active clients: ' . $clients . "\r";
	}

	public function log($msg, $color = null) {
		echo $msg . "\n";
	}
	public function error($msg) {
		return $this->log($msg);
	}
	public function success($msg) {
		return $this->log($msg);
	}
}

