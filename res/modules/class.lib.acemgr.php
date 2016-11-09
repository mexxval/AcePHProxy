<?php
// http://wiki.acestream.org/wiki/index.php/Engine_API#STATUS

/*
echo "GET /torrent/single/ARQ.torrent HTTP/1.1

" | nc localhost 8002
почему то вешает все, и дело не в том, что это не работает, 
а в том, что извне можно проэксплойтить проксю, вызвав DoS
*/

class AceConnect {
	static private $instance;

	protected $key = 'kjYX790gTytRaXV04IvC-xZH3A18sj5b1Tf3I-J5XVS1xsj-j0797KwxxLpBl26HPvWMm'; // public one
	protected $conn = array(); // pid => connect
	protected $host = '127.0.0.1';
	protected $port = 62062;

	static public function getInstance($key) {
		if (!self::$instance) {
			self::$instance = new AceConnect($key);
		}
		return self::$instance;
	}

	private function __construct($key = null) {
		$this->key = $key ? $key : $this->key;
	}

	// для каждой трансляции новый коннект к Ace
	public function getConnection($pid) {
		if (!isset($this->conn[$pid])) {
			$this->conn[$pid] = $this->connect($this->key, $this->host, $this->port, $pid);
		}
		return $this->conn[$pid];
	}

	protected function connect($key, $host, $port, $pid) {
		$tmout = 1; // seconds
		$conn = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), $errno, $errstr, $tmout);
		if (!$conn) {
			throw new Exception('Cannot connect to AceServer. ' . $errstr, $errno);
		}
		# stream_set_blocking($conn, 0); // с этим херня полная
		stream_set_timeout($conn, 1, 0); // нужно ли
		$conn = new AceConn($conn, $pid, $this);
		try {
			$res = $conn->auth($key);
		} catch (CoreException $e) {
			if ($e->getCode() == CoreException::EXC_CONN_FAIL) {
				// acestream hellobg not answered
				$this->restartAceServer();
			}
			throw $e;
		}
		return $conn;
	}

	public function _closeConn($pid) {
		if (!isset($this->conn[$pid])) { // O_o
			return false;
		}
		// $this->conn[$pid]->close(); // закроется через destruct
		unset($this->conn[$pid]);
		return;
	}

	public function restartAceServer() {
		$cmd = 'killall acestreamengine';
		return `$cmd`;
	}



	// TODO сильно рефакторить
	public function startraw($pid, $fileidx = 0) {
		$conn = $this->getConnection($pid . $fileidx);
		if (!$conn->isAuthorized()) {
			throw new Exception('Ace connection not authorized');
		}

		// вероятно $pid это имя торрент файла, поищем в папке /STORAGE/FILES
		// оно может быть и урлом
		$url = parse_url($pid);
		if (!isset($url['scheme'])) { // видимо файл
			if (!is_file($file = ('/STORAGE/FILES/' . $pid))) {
				throw new Exception('Torrent file not found ' . $file);
			}
		} else {
			$file = $pid;
		}

		$base64 = file_get_contents($file);
		$base64 = base64_encode($base64);

		$conn->startraw($base64, $fileidx);
		return $conn;
	}

	public function starttorrent($url, $fileidx = 0) {
		$conn = $this->getConnection($url . $fileidx);
		if (!$conn->isAuthorized()) {
			throw new Exception('Ace connection not authorized');
		}
		$conn->starttorrent($url, $fileidx);
		return $conn;
	}

	public function startpid($pid) {
		$conn = $this->getConnection($pid);
		if (!$conn->isAuthorized()) {
			throw new Exception('Ace connection not authorized');
		}
		$conn->startpid($pid);
		return $conn;
	}
}




