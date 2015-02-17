<?php

class EventController {
	const CLR_YELLOW = 3;
	const CLR_GREEN = 2;
	const CLR_ERROR = 1;
	const CLR_DEFAULT = 7;

	protected $windows = array();
	protected $www_ok;
	protected $cur_x;
	protected $cur_y;

	public function __construct() {
	}

	public function __destruct() {
		$this->closeClean();
	}
	// закрывает сессию ncurses
	protected function closeClean() {
		ncurses_end(); // выходим из режима ncurses, чистим экран
	}

	public function init() {
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
		$rows = 10; $cols = $x; $sy = $y - $rows; $sx = 0;
		$this->windows['log'] = ncurses_newwin($rows, $cols, $sy, $sx);

		// и окно для статистики (остальное пространство)
		$rows = $y - 10 - 1; $cols = $x; $sy = 1; $sx = 0; // еще -1 чтобы границы не перекрывались
		$this->windows['stat'] = ncurses_newwin($rows, $cols, $sy, $sx);

		if (ncurses_has_colors()) {
			ncurses_start_color();
			ncurses_init_pair(self::CLR_ERROR, NCURSES_COLOR_RED, NCURSES_COLOR_BLACK);
			ncurses_init_pair(self::CLR_GREEN, NCURSES_COLOR_GREEN, NCURSES_COLOR_BLACK);
			ncurses_init_pair(self::CLR_YELLOW, NCURSES_COLOR_YELLOW, NCURSES_COLOR_BLACK);
			ncurses_init_pair(4, NCURSES_COLOR_BLUE, NCURSES_COLOR_BLACK);
			ncurses_init_pair(5, NCURSES_COLOR_MAGENTA, NCURSES_COLOR_BLACK);
			ncurses_init_pair(6, NCURSES_COLOR_CYAN, NCURSES_COLOR_BLACK);
			ncurses_init_pair(self::CLR_DEFAULT, NCURSES_COLOR_WHITE, NCURSES_COLOR_BLACK);
			$this->log('Init colors', self::CLR_GREEN);
		}

		// рамка для него
		ncurses_wborder($this->windows['log'], 0,0, 0,0, 0,0, 0,0);
		ncurses_wborder($this->windows['stat'], 0,0, 0,0, 0,0, 0,0);

		ncurses_attron(NCURSES_A_REVERSE);
		ncurses_mvaddstr(0, 1, "AcePHProxy");
		ncurses_attroff(NCURSES_A_REVERSE);
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
	}

	// вызывать каждый цикл. выводим массив трансляций
	public function tick($streams) {
		ncurses_werase ($this->windows['stat']);
		ncurses_wborder($this->windows['stat'], 0,0, 0,0, 0,0, 0,0);

		$this->listen4resize();

		$i = 1; $col = 0;
		// конфиг раскладки по колонкам
		$map = array(
			0 => $col += 2, // channel
			$col += 24, // Buffer, but 25 is Channel width!
			$col += 8,	// State
			$col += 9,	// up/down bytes
			$col += 17,	// peers
			$col += 6,	// Client list
			$col += 24,	// download/upload speed
			$col += 8
		);

		// выводим все коннекты и трансляции
		$this->output('stat', $i, $map[0], "Channel");
		$this->output('stat', $i, $map[1], "Buffer");
		$this->output('stat', $i, $map[2], "State");
		$this->output('stat', $i, $map[3], "Up  (Mb) Down");
		$this->output('stat', $i, $map[4], "Peers");
		$this->output('stat', $i, $map[5], "Client");
		$this->output('stat', $i, $map[6], "DL (kbps) UL");
		$i++;

		foreach ($streams as $row) {
			$i++;
			foreach ($row as $colidx => $str) {
				$this->output('stat', $i, $map[$colidx], $str);
			}
		}

		// состояние инета
		// ascii table http://www.linuxivr.com/c/week6/ascii_window.jpg
		$str = sprintf('%swww %s%s',
			iconv('cp866', 'utf8', chr(0xb4)),
			$this->www_ok ? 'ON' : 'OFF', 
			iconv('cp866', 'utf8', chr(0xc3))
		);
		$this->output('stat', 0, 88, $str);

		ncurses_wrefresh($this->windows['stat']);

		// перерисуем окно лога, при ресайзе оно не обновляется
		// TODO один хрен косяк
		ncurses_wborder($this->windows['log'], 0,0, 0,0, 0,0, 0,0);
		ncurses_wrefresh($this->windows['log']);
	}

	protected function output($wcode, $y, $x, $str) {
		$w = $this->windows[$wcode];
		$color = null;
		if (is_array($str)) {
			$color = $str[0];
			$str = $str[1];
		}

		$color and ncurses_wcolor_set($w, $color);
		ncurses_mvwaddstr($w, $y, $x, $str);
		$color and ncurses_wcolor_set($w, self::CLR_DEFAULT);
	}

	// а еще чтобы не долбить коннектами, можно его куда нить открыть и держать. 
	// опыт xbmc клиента правда говорит, что это мб весьма ненадежно.. зато реалтайм
	public function checkWWW() {
		$fp = fsockopen('8.8.8.8', 53, $errstr, $errno, 1);
		$this->www_ok = $fp ? true : false;
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

