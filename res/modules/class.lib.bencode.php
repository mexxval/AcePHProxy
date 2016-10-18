<?php
// BEncode Library
//
// Original Python implementation by Petru Paler
// PHP conversion by Gerard Krijgsman (for AnimeSuki.com)
// Last updated January 9, 2003
class BEncodeLib
{
	public function decode_int(&$x, $f)
	{
		$int_filter = '/(0|-?[1-9][0-9]*)e/';
		$pr = preg_match($int_filter,substr($x,$f),$m);
		$result['r'] = round($m[1]);
		$result['l'] = $f + strlen($m[0]);
		return $result;
	}

	public function decode_string(&$x, $f)
	{
		$string_filter = '/(0|[1-9][0-9]*):/';
		$pr = preg_match($string_filter,substr($x,$f),$m);
		$l = intval($m[1]);
		$s = $f + strlen($m[0]);
		$result['r'] = substr($x,$s,$l);
		$result['l'] = $s + $l;
		return $result;
	}

	public function decode_list(&$x, $f)
	{
		$r = array();
		while($x[$f] != 'e')
		{
			$v = $this->bdecode_rec($x, $f);
			array_push($r,$v['r']);
			$f = $v['l'];
		}
		$result['r'] = $r;
		$result['l'] = $f + 1;
		return $result;
	}

	public function decode_dict(&$x, $f)
	{
		$r = array();
		while($x[$f] != 'e')
		{
			$k = $this->decode_string($x, $f);
			$f = $k['l'];
			$v = $this->bdecode_rec($x, $f);
			$r[$k['r']] = $v['r'];
			$f = $v['l'];
		}
		$result['r'] = $r;
		$result['l'] = $f + 1;
		return $result;
	}

	public function bdecode_rec(&$x, $f)
	{
		$t = $x[$f];
		if ($t == 'i')
			return $this->decode_int($x, $f + 1);
		elseif ($t == 'l')
			return $this->decode_list($x, $f + 1);
		elseif ($t == 'd')
			return $this->decode_dict($x, $f + 1);
		else
			return $this->decode_string($x, $f);
	}

	public function bdecode($x)
	{
		$result = $this->bdecode_rec($x, 0);
		return $result['r'];
	}

	public function bencode_rec($x, &$b)
	{
		if (is_numeric($x))
			$b .= 'i'.round($x).'e';
		elseif (is_string($x))
			$b .= strlen($x).':'.$x;
		elseif (is_array($x))
		{
			// Unlike Python, PHP does not have a "tuple", "list" or "dict" type
			// This code assumes arrays with purely integer indexes are lists,
			// arrays which use string indexes assumed to be dictionaries.
			$keys = array_keys($x);
			$listtype = true;
			while(list($k,$v) = each($keys))
				if (!is_integer($v)) $listtype = false;
			if ($listtype)
			{
				// List
				$b .= 'l';
				while(list($k,$v) = each($x))
					$this->bencode_rec($v, $b);
				$b .= 'e';
			}
			else
			{
				// Dictionary
				$b .= 'd';
				ksort($x);
				while(list($k,$v) = each($x))
				{
					settype($k,"string");
					$this->bencode_rec($k, $b);
					$this->bencode_rec($v, $b);
				}
				$b .= 'e';
			}
		}
	}

	public function bencode($x)
	{
		$b = '';
		$this->bencode_rec($x, $b);
		return $b;
	}
}
?>