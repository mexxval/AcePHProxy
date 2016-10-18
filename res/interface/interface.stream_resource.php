<?php
interface AppStreamResource {
	public function open();
	public function registerEventListener($cb);
	public function isRestarting();
	public function close();
	public function getName();
	public function isLive();
	public function getStreamHeaders();
	public function seek($offsetBytes);
	public function getStreamChunk($bufSize);
}

