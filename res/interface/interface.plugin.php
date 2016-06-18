<?php

interface AcePluginInterface {
	public function __construct($config);

	// метод возвращает объект ClientResponse, а по ссылке массив инфы о потоке/запросе
	public function process(ClientRequest $req, &$info);

	// должен вернуть указатель чтения данных (file pointer)
	public function getStreamResource(ClientRequest $req);
}

