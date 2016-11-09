<?php

class CoreException extends Exception {
    const EXC_404_PLUGIN = 0x01;
    const EXC_CONN_FAIL = 0x02;

	private $addinfo = null;

    // $addinfo - любые строки, массивы, для сохранения в дампе ошибки, чтобы скорее понять, куда копать
    public function __construct($msg, $code, $addinfo = array()) {
        $msg = htmlspecialchars($msg, ENT_NOQUOTES);
        parent::__construct($msg, $code);
        $this->addinfo = $addinfo;
    }
    public function getAdditionalInfo() {
        return $this->addinfo;
    }
}


