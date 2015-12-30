<?php
// http://wiki.acestream.org/wiki/index.php/Engine_API#STATUS

class AceConnect {
	protected $key = 'kjYX790gTytRaXV04IvC-xZH3A18sj5b1Tf3I-J5XVS1xsj-j0797KwxxLpBl26HPvWMm'; // public one
	protected $conn = array(); // pid => connect
	protected $host = '127.0.0.1';
	protected $port = 62062;

	public function __construct($key = null) {
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
		$conn = new AceConn($conn, $pid);
		$res = $conn->auth($key);
		return $conn;
	}

	public function startraw($pid, &$resourceName = '') {
		$resourceName = '';
		$conn = $this->getConnection($pid);
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

		$idx = rand(10, 99);
		$conn->send('LOADASYNC ' . $idx . ' RAW ' . $base64 . ' 0 0 0', 0);
		// LOADRESP 96 {"status": 1, "files": [["Z.Nation.S01E06.1080p.rus.LostFilm.TV.mkv", 0]], 
		//	"infohash": "fe12d1d6be1c155e52d4d53ec1168845b773786b", 
		//	"checksum": "ede8087d1e26150df68e320c0d1a95ac62083449"}
		$answer = $conn->waitFor('LOADRESP ', 5);
		// поскольку idx двузначный, можем отрезать с известной позиции в строке 
		// длина "LOADRESP NN " = 12
		if ($answer) {
			$answer = json_decode(substr($answer, 12), true);
			// первый попавшийся filename берем как название ресурса
			if (isset($answer['files'], $answer['files'][0], $answer['files'][0][0])) {
				$resourceName = $answer['files'][0][0];
				error_log('SET RESOURCE NAME = ' . $resourceName);
			}
		}

		// load response, ничего особо интересного
		// request_id=1 response={"status": 1, "files": 
		//	[["%D0%92%D0%B5%D0%BA.%D0%90%D0%B4%D0%B0%D0%BB%D0%B8%D0%BD.2015.WEB-DL.%5B1080p%5D.NNM-CLUB.mkv", 0]], 
		//	"infohash": "6af888601fcf25f8eecce113d5e3d11cc57e1cb7", "checksum": "d8cc03f0f147ac87210ba7eb3bcba3eecdd52c3c"}

		$this->send($pid, 'START RAW ' . $base64 . ' 0 0 0 0', 10);
		return $conn;
	}

	public function starttorrent($url) {
		$conn = $this->getConnection($url);
		if (!$conn->isAuthorized()) {
			throw new Exception('Ace connection not authorized');
		}
		$this->send($url, 'START TORRENT ' . $url . ' 0 0 0 0 0', 15);
		return $conn;
	}

	public function startpid($pid) {
		$conn = $this->getConnection($pid);
		if (!$conn->isAuthorized()) {
			throw new Exception('Ace connection not authorized');
		}
		$this->send($pid, 'START PID ' . $pid . ' 0', 15);
		return $conn;
	}

	public function stoppid($pid) {
		try {
			$this->send($pid, 'STOP');
		}
		catch (Exception $e) {
			error_log('Stop PID error: ' . $e->getMessage());
		}
		unset($this->conn[$pid]);
	}

	protected function send($pid, $cmd, $sec = 1) {
		$conn = $this->getConnection($pid);
		$line = $conn->send($cmd, $sec);
		return $line;
	}
}


class AceConn {
	protected $state = 0;
	protected $conn;
	protected $pid; // для связывания одного с другим
	protected $auth = false;
	protected $listener;

	public function __construct($conn, $pid) {
		$this->conn = $conn;
		$this->pid = $pid;
	}
	public function getPID() {
		return $this->pid;
	}

	public function auth($prodkey) {
		// HELLOBG, get inkey
		$ans = $this->send('HELLOBG version=3'); // << HELLOTS ... key=...
		if (!preg_match('~key=([0-9a-f]{10})~', $ans, $m)) {
			throw new Exception('No answer with HELLOBG. ' . $ans);
		}
		$inkey = $m[1];
		if (!$inkey) {
			throw new Exception('Key not get with HELLOBG');
		}

		$ready_key = $this->makeKey($prodkey, $inkey);

		// AUTH with ready_key
		$ans = $this->send(sprintf('READY key=%s', $ready_key)); // << AUTH 1

		return $this->auth = true; //$ans == 'AUTH 1';
	}

	public function isAuthorized() {
		return $this->auth;
	}

	protected function makeKey($prodkey, $inkey) {
		$shakey = sha1($inkey . $prodkey);
		$part = explode('-', $prodkey);
		$prod_part = reset($part);
		return $prod_part . '-' . $shakey;
	}

	public function send($string, $sec = 1, $usec = 0) {
		stream_socket_sendto($this->conn, $string . "\r\n");
		$line = $this->readsocket($sec, $usec);
		return $line;
	}

	public function registerEventListener($cb) {
		$this->listener = $cb;
	}
	protected function notifyListener($event) {
		is_callable($this->listener) and call_user_func_array($this->listener, array($event));
	}

	public function isActive() {
		return $this->state !== 0;
	}

	public function readsocket($sec = 0, $usec = 300000) {
		stream_set_timeout($this->conn, $sec, $usec);
		$line = trim(fgets($this->conn));
		if ($line) {
			if (strpos($line, 'STATE ') === 0) {
				$this->state = (int) substr($line, 6);
				#error_log('Engine State ' . $this->state);
			}
			if ($line == 'EVENT getuserdata') {
				$this->send('USERDATA [{"gender": 1}, {"age": 4}]');
				error_log('Send userdata');
			}
			$this->notifyListener($line);
		}
		return $line;
	}

	public function ping() {
		// при падении ace engine моментально выставляется eof в true
		$s = socket_get_status($this->conn);
		if ($s['eof']) {
			# $this->disconnect(); // решение примем уровнями выше
			throw new Exception('ace_connection_broken');
		}
	}

	public function __destruct() {
		$this->disconnect();
	}
	protected function disconnect() {
		fclose($this->conn);
	}

	public function waitFor($str, $cycles = 10, $sec = 1, $usec = 0) {
		while ($cycles-- > 0) {
			$ans = $this->readsocket($sec, $usec);
			if (strpos($ans, $str) !== false) {
				return $ans;
			}
		}
		return false;
	}
}





