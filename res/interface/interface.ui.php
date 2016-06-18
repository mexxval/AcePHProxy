<?php
interface AppUserInterface {
	public function init();
	public function draw($streams, $addinfo);

	public function log($msg, $color);
	public function error($msg);
	public function success($msg);
}

