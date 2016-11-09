<?php
/**
 * требуется генерить уведомление eof, чтобы например отдача статичного файла не растянулась до таймаута
 * рефакторить это надо
 */

class StreamResource_file implements AppStreamResource {
	protected $listener;
	private $file;
	private $fp; // file pointer

	public function __construct($file) {
		$this->file = $file;
		# error_log('construct file ' . spl_object_hash($this));
	}
	public function __destruct() {
		# error_log(' destruct file ' . spl_object_hash($this));
	}

// TODO это рефакторить! циклические ссылки на StreamUnit
	public function registerEventListener($cb) {
		$this->listener = $cb;
	}
	protected function notifyListener($event) {
		is_callable($this->listener) and call_user_func_array($this->listener, array($event));
	}
	public function isRestarting() {
		return false;
	}
	public function open() {
		// открываем ссылку, пытаемся прочитать заголовки
		$this->fp = fopen($this->file, 'r');
		$this->notifyListener(array(
			'headers' => $this->getStreamHeaders(),
			'started' => true
		));
	}
	public function close() {
		return is_resource($this->fp) and fclose($this->fp);
	}
	public function getName() {
		$name = str_replace(realpath(__DIR__ . '/../../') . '/', '', $this->file);
		return $name;
	}
	public function isLive() {
		return false;
	}
	public function getStreamHeaders($implode = true) {
		$mimetype = mime_content_type($this->file);

		strpos($this->file, '.mp4') and $mimetype = 'video/x-msvideo';
		strpos($this->file, '.ts') and $mimetype = 'video/MP2T';

		$headers = array(
 			'HTTP/1.0 200 OK',
			'Content-Type: ' . $mimetype,
			'Content-Length: ' . filesize($this->file),
		);
		return $implode ? 
			implode("\r\n", $headers) . "\r\n\r\n" :
			$headers;
	}

	public function seek($offsetBytes) {}

	public function getStreamChunk($bufSize) {
		if (!is_resource($this->fp)) {
			return false;
		}
		$data = fread($this->fp, $bufSize); // с этим работало

		if (!$data) {
			$this->notifyListener(array('eof' => true));
			return ;
		}

		return $data;
	}
}

