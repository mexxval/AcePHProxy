<?php

define('ACEPHPROXY_VERSION', '0.8.0');


$subdirs = array('interface', 'core', 'modules');
foreach ($subdirs as $one) {
	foreach (glob(__DIR__ . '/' . $one . '/*.php') as $file) {
		include $file;
	}
}

mb_internal_encoding('UTF-8');

