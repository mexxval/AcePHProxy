<?php

// текстовый консольный интерфейс на случай, если нет ncurses
class AppUI_Text extends AppUI_common {

	// только ради требований базового класса, обращений к модулю извне не предполагается
	public function process(ClientRequest $req) {
	}

	public function init() {
	}

	public function draw() {
		$clients = 0;
		foreach ($this->getStreams() as $one) {
			$clients += count($one->getPeers());
		}
		echo 'Active streams: ' . count($streams) . "\t" . 'Active clients: ' . $clients . "\r";
	}

	public function log($msg, $color = null) {
		echo $msg . "\n";
	}
}

