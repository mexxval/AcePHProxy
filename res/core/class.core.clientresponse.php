<?php

class ClientResponse {
	private $req;
	private $contents;
	private $streamid;

	public function __construct(ClientRequest $req, $contents = null, $streamid = null) {
		if (is_object($contents) and !is_a($contents, 'AppStreamResource')) {
			throw new CoreException('Only AppStreamResource available as an object', 0);
		}
		$this->req = $req;
		$this->contents = $contents;
		$this->streamid = $streamid;
		# error_log(' construct response ' . spl_object_hash ($this));
	}

	public function getPluginCode() {
		return $this->req->getPluginCode();
	}
	public function getName() {
		// return $this->req->getName(); // это не катит никак
		return $this->getStreamId();
	}
	public function isStream() {
		return (bool) $this->getStreamId();
	}
	public function getStreamId() {
		return $this->streamid;
	}

	// метод выдает ресурс, полученный на вход,
	// но только если это инстанс AppStreamResource
	public function getStreamResource() {
		return is_a($this->contents, 'AppStreamResource') ? $this->contents : null;
	}
	// то же, но выдает только plaintext
	public function getContents() {
		return is_scalar($this->contents) ? $this->contents : null;
	}

	public function __destruct() {
		# error_log(' destruct response ' . spl_object_hash ($this));
	}
}

