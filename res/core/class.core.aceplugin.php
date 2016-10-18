<?php

abstract class AcePlugin_common {
	private $app;
	private $config;

	// в плагин передается основная инфа: ссылка на главный объект приложения,
	// объект менеджера потоков и конфиг плагина
	// по-хорошему метод - final, но .. в общем см. класс AppUI_common
	public function __construct(AcePHProxy $app, $config) {
		$this->app = $app;
		$this->config = $config;
		$this->init();
	}

	abstract protected function init();

	public function __get($prop) {
		return isset($this->config[$prop]) ? $this->config[$prop] : null;
	}

	protected function getStreams() {
		return $this->getApp()->getStreamManager()->getStreams();
	}
	protected function getApp() {
		return $this->app;
	}

	// должен вернуть объект ClientResponse, содержащий данные для отправки на клиента
	// в виде plaintext (отправка разом) или в виде объекта-потока (регистрация в Stream Manager)
	// содержимое ресурса будет выдаваться клиенту до тех пор, пока не кончится (eof)
	abstract public function process(ClientRequest $req);

}

