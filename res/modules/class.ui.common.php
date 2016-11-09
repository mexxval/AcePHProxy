<?php

// TODO singleton

abstract class AppUI_common extends AcePlugin_common implements AppUserInterface {
	const CLR_SPEC1 = 4;
	const CLR_YELLOW = 3;
	const CLR_GREEN = 2;
	const CLR_ERROR = 1;
	const CLR_DEFAULT = 7;

	static protected $instance;

	static public function getInstance() {
		$cn = get_called_class();
		if (!isset(self::$instance[$cn])) {
			$args = func_get_args();
			$class = new ReflectionClass( $cn );
			self::$instance[$cn] = $class->newInstanceArgs($args);
		}
		return self::$instance[$cn];
	}

	// private сделать нельзя из-за public parent конструктора
	// но могу взбрыкнуть хотя бы
	final public function __construct() {
		$cn = get_called_class();
		if (isset(self::$instance[$cn])) {
			throw new CoreException('cannot instance again, use getInstance()', 0);
		}
		$args = func_get_args();
		call_user_func_array(array('parent', __FUNCTION__), $args);
	}

	public function init2(AcePHProxy $app) {
		// getApp есть в plugin
	}

	abstract public function draw();

	abstract public function log($msg, $color = self::CLR_DEFAULT);

	public function error($msg) {
		return $this->log($msg, self::CLR_ERROR);
	}
	public function success($msg) {
		return $this->log($msg, self::CLR_GREEN);
	}

}

