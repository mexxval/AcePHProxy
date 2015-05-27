<?php
// http://wiki.acestream.org/wiki/index.php/Engine_API#STATUS
/*
	STATUS main:buf;93;0;0;0;193;0;3561;98;0;89571328;0;1644904448
	Все числа передаются как integer.
	Все progress принимают значение от 0 до 100.
*/

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
		$conn = @stream_socket_client(sprintf('tcp://%s:%d', $host, $port), &$errno, &$errstr, $tmout);
		if (!$conn) {
			throw new Exception('Cannot connect to AceServer. ' . $errstr, $errno);
		}
		# stream_set_blocking($conn, 0); // с этим херня полная
		stream_set_timeout($conn, 1, 0);
		$conn = new AceConn($conn, $pid);
		$conn->auth($key);
		return $conn;
	}

	public function startpid($pid) {
		$conn = $this->getConnection($pid);
		$this->send($pid, 'START PID ' . $pid . ' 0', 5);

		// ждем START http://127.0.0.1:6878/content/aa1ad7963f4dabed7899367c9b6b33c77447abad/0.784118134089
		if ($ans = $conn->waitFor('START http', 10)) {
			$tmp = explode(' ', $ans);
			$link = $tmp[1];
			return $link; // SUCCESS START
		}
		return null;
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
	protected $dlstat;
	protected $conn;
	protected $pid; // для связывания одного с другим

	public function __construct($conn, $pid) {
		$this->conn = $conn;
		$this->pid = $pid;
	}
	public function getPID() {
		return $this->pid;
	}
	public function getDlstat() {
		$tmp = $this->dlstat;
		$this->dlstat = null;
		return $tmp;
	}

	public function auth($prodkey) {
		// HELLOBG, get inkey
		$ans = $this->send('HELLOBG version=2'); // << HELLOTS ... key=...
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

		return $ans == 'AUTH 1';
	}

	protected function makeKey($prodkey, $inkey) {
		$shakey = sha1($inkey . $prodkey);
		$prod_part = reset(explode('-', $prodkey));
		return $prod_part . '-' . $shakey;
	}

	public function send($string, $sec = 1, $usec = 0) {
		@fwrite($this->conn, $string . "\r\n");
		$line = $this->readsocket($sec, $usec, $this->dlstat);
		return $line;
	}

	public function readsocket($sec = 0, $usec = 300000, &$dlstat = null) {
		stream_set_timeout($this->conn, $sec, $usec);
		$line = trim(fgets($this->conn));
		if ($line) {
			// разбираем STATUS по косточкам: STATUS main:buf;0;0;0;0;79;0;5;20;0;2359296;0;163840
			// total_progress;immediate_progress;speed_down;http_speed_down;speed_up;peers;http_peers;downloaded;http_downloaded;uploaded
			if (preg_match('~^STATUS\smain:((buf);\d+;\d+|(dl))?;\d+;\d+;(\d+);\d+;(\d+);(\d+);\d+;(\d+);\d+;(\d+)$~s', $line, $m)) {
				$tmp = explode(';', $m[1]);
				$dlstat = array(
					'acestate' => $m[2] ? 'buf' : 'dl',
					'bufpercent' => $m[2] ? $tmp[1] : null,
					'speed_dn' => $m[4],
					'speed_up' => $m[5],
					'peers' => $m[6],
					'dl_bytes' => $m[7],
					'ul_bytes' => $m[8],
				);
			}
		}
		return $line;
	}

	public function ping() {
		// принцип - ставим небольшой таймаут, пробуем записать пустую строку (\r\n, 2 байта), 
		// если запись прошла - стало быть сокет жив
		stream_set_timeout($this->conn, 0, 50000);
		$bytes = @fwrite($this->conn, "\r\n");
		if (!$bytes) {
			error_log('ping failed');
			# $this->disconnect(); // решение примем уровнями выше
			throw new Exception('ace_connection_broken');
		}
		else {
			#error_log('ping success ' . var_export($ans, 1));
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





