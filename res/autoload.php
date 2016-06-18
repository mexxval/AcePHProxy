<?php

function acephproxy_autoload($class) {
	$lclass = strtolower($class);
	
}

spl_autoload_register('acephproxy_autoload');
