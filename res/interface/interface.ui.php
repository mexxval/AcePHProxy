<?php
interface AppUserInterface {
	static public function getInstance();

	public function init2(AcePHProxy $app);
	public function draw();

	public function log($msg, $color);
	public function error($msg);
	public function success($msg);
}

