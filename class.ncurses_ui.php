<?php

class EventController {
	const CLR_SPEC1 = 4;
	const CLR_YELLOW = 3;
	const CLR_GREEN = 2;
	const CLR_ERROR = 1;
	const CLR_DEFAULT = 7;

	protected $windows = array();
	protected $www_ok = true;
	protected $cur_x;
	protected $cur_y;
	protected $map = array();
	protected $colwid = array(); // ширины столбцов

	public function __construct() {
		// конфиг раскладки по колонкам
		$this->colwid = array(
			0 => 24, // channel (variable!)
			7, // Buffer
			9,	// State
			17,	// up/down bytes
			6,	// peers
			24,	// Client list
			8,	// download/upload speed
			8
		);
	}

	public function __destruct() {
		$this->closeClean();
	}
	// закрывает сессию ncurses
	protected function closeClean() {
		ncurses_end(); // выходим из режима ncurses, чистим экран
	}

	public function init($title = 'AcePHProxy') {
		// начинаем с инициализации библиотеки
		$ncurse = ncurses_init();
		// используем весь экран
		$this->windows['main'] = ncurses_newwin ( 0, 0, 0, 0); 
		// рисуем рамку вокруг окна
		ncurses_border(0,0, 0,0, 0,0, 0,0);
		ncurses_getmaxyx ($this->windows['main'], $y, $x);
		// save current main window size
		$this->cur_x = $x;
		$this->cur_y = $y;

		// создаём второе окно для лога
		$rows = floor($y / 2); $cols = $x; $sy = $y - $rows; $sx = 0;
		$this->windows['log'] = ncurses_newwin($rows, $cols, $sy, $sx);

		// и окно для статистики (остальное пространство)
		$rows = $y - $rows - 1; $cols = $x; $sy = 1; $sx = 0; // еще -1 чтобы границы не перекрывались
		$this->windows['stat'] = ncurses_newwin($rows, $cols, $sy, $sx);

		if (ncurses_has_colors()) {
			ncurses_start_color();
			// colors http://php.net/manual/en/ncurses.colorconsts.php
			ncurses_init_pair(self::CLR_ERROR, NCURSES_COLOR_RED, NCURSES_COLOR_BLACK);
			ncurses_init_pair(self::CLR_GREEN, NCURSES_COLOR_GREEN, NCURSES_COLOR_BLACK);
			ncurses_init_pair(self::CLR_YELLOW, NCURSES_COLOR_YELLOW, NCURSES_COLOR_BLACK);
			ncurses_init_pair(self::CLR_SPEC1, NCURSES_COLOR_RED, NCURSES_COLOR_WHITE);
			ncurses_init_pair(5, NCURSES_COLOR_MAGENTA, NCURSES_COLOR_BLACK);
			ncurses_init_pair(6, NCURSES_COLOR_CYAN, NCURSES_COLOR_BLACK);
			ncurses_init_pair(self::CLR_DEFAULT, NCURSES_COLOR_WHITE, NCURSES_COLOR_BLACK);
			$this->log('Init colors', self::CLR_GREEN);
		}

		// рамка для него
		ncurses_wborder($this->windows['log'], 0,0, 0,0, 0,0, 0,0);
		ncurses_wborder($this->windows['stat'], 0,0, 0,0, 0,0, 0,0);

		$this->outputTitle($title);

		ncurses_nl ();
		ncurses_curs_set (0); // visibility

		ncurses_refresh(); // рисуем окна

		// обновляем маленькое окно для вывода строки
		ncurses_wrefresh($this->windows['log']);
	}

	protected function listen4resize() {
		ncurses_getmaxyx ($this->windows['main'], $y, $x);
		if ($x != $this->cur_x or $y != $this->cur_y) {
			// restart ncurses session, redraw all
			$this->closeClean();
			$this->init();
		}

		// save current main window size
		$this->cur_x = $x;
		$this->cur_y = $y;

		// ширина первого столбца определяется как разность ширины окна и всех столбцов, кроме первого
		$colsum = array_sum($this->colwid) - $this->colwid[0];
		$this->colwid[0] = $this->cur_x - $colsum;

		// renew map
		$col = 0;
		$this->map = array(
			0 => $col += 2, // channel
			$col += $this->colwid[0], // Buffer, but 25 is Channel width!
			$col += $this->colwid[1],	// State
			$col += $this->colwid[2],	// up/down bytes
			$col += $this->colwid[3],	// peers
			$col += $this->colwid[4],	// Client list
			$col += $this->colwid[5],	// download/upload speed
			$col += $this->colwid[6]
		);
	}

	protected function outputTitle($title) {
		ncurses_attron(NCURSES_A_REVERSE);
		ncurses_mvaddstr(0, 1, $title);
		ncurses_attroff(NCURSES_A_REVERSE);
		ncurses_refresh(); // рисуем окна
	}

	// вызывать каждый цикл. выводим массив трансляций
	public function tick($streams) {
		ncurses_werase ($this->windows['stat']);
		ncurses_wborder($this->windows['stat'], 0,0, 0,0, 0,0, 0,0);

		$this->listen4resize();

		$i = 1;
		$map = $this->map;

		// выводим все коннекты и трансляции
		$this->output('stat', $i, 0, "Channel");
		$this->output('stat', $i, 1, "Buffer");
		$this->output('stat', $i, 2, "State");
		$this->output('stat', $i, 3, "Up  (Mb) Down");
		$this->output('stat', $i, 4, "Peers");
		$this->output('stat', $i, 5, "Client");
		$this->output('stat', $i, 6, "DL (kbps) UL");
		$i++;

		foreach ($streams as $row) {
			$i++;
			foreach ($row as $colidx => $str) {
				$this->output('stat', $i, $colidx, $str);
			}
		}

		// состояние инета
		// ascii table http://www.linuxivr.com/c/week6/ascii_window.jpg
		#iconv('cp866', 'utf8', chr(0xb4)),
		#iconv('cp866', 'utf8', chr(0xc3))
		$str = array(
			0 => $this->www_ok ? EventController::CLR_GREEN : EventController::CLR_ERROR,
			1 => sprintf(' %s ', $this->www_ok ? 'online' : 'offline')
		);
		$this->output('stat', 0, 6, $str);

		ncurses_wrefresh($this->windows['stat']);

		// перерисуем окно лога, при ресайзе оно не обновляется
		// TODO один хрен косяк
		ncurses_wborder($this->windows['log'], 0,0, 0,0, 0,0, 0,0);
		ncurses_wrefresh($this->windows['log']);
	}

	protected function output($wcode, $y, $col, $str) {
		$x = $this->map[$col];
		$w = $this->windows[$wcode];
		$color = null;
		if (is_array($str)) {
			$color = $str[0];
			$str = $str[1];
		}
		$col === 0 and $str = mb_substr($str, 0, $this->colwid[$col] - 1); // -1 чтобы не сливался со след.столбцом

		$color and ncurses_wcolor_set($w, $color);
		ncurses_mvwaddstr($w, $y, $x, $str);
		$color and ncurses_wcolor_set($w, self::CLR_DEFAULT);
	}

	// а еще чтобы не долбить коннектами, можно его куда нить открыть и держать. 
	// опыт xbmc клиента правда говорит, что это мб весьма ненадежно.. зато реалтайм
	public function checkWWW(&$changed) {
		// делаем 2-3 попытки коннекта для проверки инета
		$cyc = 3;
		while (!($fp = @stream_socket_client('tcp://8.8.8.8:53', $e, $e, 0.15, STREAM_CLIENT_CONNECT)) and $cyc-- > 0);

		$tmp = $this->www_ok; // для определения смены состояния
		$this->www_ok = $fp ? true : false;
		$changed = $tmp != $this->www_ok;
		$fp and fclose($fp);
		return $this->www_ok;
	}

	public function log($msg, $color = self::CLR_DEFAULT) {
		ncurses_getmaxyx ($this->windows['log'], $y, $x);
		ncurses_getyx ($this->windows['log'], $cy, $cx); // cursor xy
		if ($cy > $y - 3) {
			ncurses_werase ($this->windows['log']);
			ncurses_wborder($this->windows['log'], 0,0, 0,0, 0,0, 0,0);
			$cy = 0;
		}
		$msg = mb_substr($msg, 0, $x - 2);

		$color and ncurses_wcolor_set($this->windows['log'], $color);
		ncurses_mvwaddstr ($this->windows['log'], $cy + 1, 1, $msg);
		ncurses_clrtoeol ();
		$color and ncurses_wcolor_set($this->windows['log'], self::CLR_DEFAULT);

		// никак скроллить не выходит
		#ncurses_insdelln (1);
		#ncurses_scrl (-2); // вообще 0 реакции
		#ncurses_insertln ();
		ncurses_wrefresh($this->windows['log']);
	}
	public function error($msg) {
		return $this->log($msg, self::CLR_ERROR);
	}
}

