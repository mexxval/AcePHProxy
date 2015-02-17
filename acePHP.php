<?php
/**
 * Демон трансляции торрент-тв.
 * Поддерживает подключение множества клиентов к одной трансляции.
 * Для запуска потока нужно обратиться к демону по http://<host>:8000/pid/<pid>/<Stream Name>
 * Например ссылка трансляции канала 2x2 будет выглядеть так
 * http://127.0.0.1:8000/pid/0d2137fc5d44fa9283b6820973f4c0e017898a09/2x2
 * <Stream Name> нужен для отображения в ncurses интерфейсе
 *
 * Для работы требует PHP с pecl-расширением ncurses и сервер AceStream
 * ну и само-собой аккаунт на торрент-тв ;)
 *
 * @author	mexxval
 * @link	http://blog.sci-smart.ru
 */

// TODO
// - stream_copy_to_stream [не фонтан]
// + сильно срать точками в сокет не стоит при буферизации. надо палить прогресс буферизации, и если он есть - выдавать точку
//   если его нет, то нет
// движок ace иногда просто падает (перезапускается), трансляция зависает при этом наглухо
//	AceConn надо научить следить за коннектом
// есть такая мысль. при запуске потока считывать в память небольшой буфер мегабайт 10-20 
//	(подумать о зависимости от битрейта), использовать его для раздачи в моменты затыков 
//	(также по 5-10 байт, вместо точек, ломающих картинку). также должно помочь избавиться от затыков,
//	которые случаются стабильно при запуске потока в первые минуты
//	вероятный алгоритм, читаем 10 буферов в очередь FIFO, писать на клиент начинаем с 11-го, 
//	  причем если данных не получено (буферизация ace), то пишем на клиент не очередную часть FIFO,
//	  а только несколько ее байт
// web-интерфейс, можно кстати через тот же порт 8000
// + перерисовка окна при ресайзе
// навигация по трансляциям и закрытие вручную
// + state buf + %
// короче логика новая: надо пихать в сокеты столько, сколько туда влазит, максимальными порциями
//	(но с использованием FIFO), а читать из Ace с учетом "полноты" сокетов.
//	Т.е. пишем в сокеты элемент FIFO, если записан полностью - читаем 1 раз из Ace.
// ace может можно как то пнуть, чтоб не буферизовал так долго. буфер настроить поменьше или START сказать
// брать инфо о трансляции через LOADASYNC


require_once dirname(__FILE__) . '/class.client_pool.php';
require_once dirname(__FILE__) . '/class.stream_client.php';
require_once dirname(__FILE__) . '/class.stream_unit.php';
require_once dirname(__FILE__) . '/class.ace_connect.php';
require_once dirname(__FILE__) . '/class.ncurses_ui.php';
require_once dirname(__FILE__) . '/class.streams_mgr.php';


// создаем коннект к acestream, запускаем клиентский сокет
$key = 'n51LvQoTlJzNGaFxseRK-uvnvX-sD4Vm5Axwmc4UcoD-jruxmKsuJaH0eVgE';

// создает сокет сервера трансляций и управляет коннектами клиентов к демону
$pool = new ClientPool('0.0.0.0', 8000);
// получает PID и выдает ссылку на трансляцию
$ace = new AceConnect($key);

// управляет трансляциями. заказывает их у Ace и раздает клиентам из pool
$streams = new StreamsManager($ace, $pool);

// при рефакторинге роль совершенно изменилась и не соответствует имени класса
// занимается отрисовкой ncurses интерфейса
$EVENTS = new EventController;
$EVENTS->init();

// мониторим новых клиентов, запускаем для них трансляцию или, если такая запущена, копируем данные из нее
// мониторим дисконнекты и убиваем трансляцию, если клиентов больше нет (пока можно сделать ее вечноживой)
// мониторим проблемы с трансляцией и делаем попытку ее перезапустить в случае чего

$last_check = 0;
$ctrlC = false;

if (!function_exists('pcntl_signal')) {
	$EVENTS->error('pcntl function not found. Ctrl+C will not work properly');
}
else {
	$EVENTS->log('Setting up Ctrl+C', EventController::CLR_GREEN);

	declare(ticks=1000);
	function signalHandler() {
		global $ctrlC, $EVENTS;
		$ctrlC = true;
		$EVENTS->error('Ctrl+C caught. Exiting');
	}
	pcntl_signal(SIGINT, 'signalHandler');
}

// сюда будем писать инфу, выводимую на экран
$rows = array();

while (!$ctrlC) {
	$check_inet = (time() - $last_check) > 10; // every 60 sec
	try {
		if ($check_inet) {
			$EVENTS->checkWWW();
			$last_check = time();
		}

		// получаем статистику по новым клиентам, отвалившимся клиентам и запросам на запуск трансляций
		if ($new = $pool->track4new()) {
			foreach ($new['start'] as $peer => $info) {
				// info - array('pid' => $pid, 'name' => $m[2], 'type' => 'trid|pid', 'client' => StreamClient);
				try {
					$channel = $streams->start($info['pid'], $info['name'], $info['type']);
					// регистрируем клиента в потоке
					$channel->registerClient($info['client']);
					unset($channel);
				}
				catch (Exception $e) {
					$info['client']->close();
					error_log('unset client on start error');
					$EVENTS->error($e->getMessage());
				}
			}
			unset($info);

			foreach ($new['done'] as $peer => $_) {
				// ассоциированные трансляции должны удалиться через __destruct клиента
				// тут можно разве что в лог написать
				error_log('disconnected ' . $peer);
			}
			foreach ($new['new'] as $peer => $_) {
				// также при желании пишем в лог о новом коннекте
				error_log('connected ' . $peer);
			}
		}


		// раскидываем контент по клиентам
		$streams->closeWaitingStreams();
		$buf_adj = $streams->copyContents();

		// задача - собрать массив трансляций
		$channels = array();
		$allStreams = $streams->getStreams();

		foreach ($allStreams as $pid => $one) {
			$stats = $one->getStatistics();
			$bufColor = EventController::CLR_GREEN;
			if (@$stats['emptydata']) {
				$bufColor = EventController::CLR_ERROR;
			}
			else if (@$stats['shortdata']) {
				$bufColor = EventController::CLR_YELLOW;
			}

			$tmp = array(
				0 => $one->getName(),
				// если вместо строки массив: 0 - цвет, 1 - выводимая строка
				1 => array(0 => $bufColor, 1 => $one->getBuffer()),
				2 => $one->getState(),
				3 => sprintf('%0.1f/%0.1f', @$stats['ul_bytes']/1024/1024, @$stats['dl_bytes']/1024/1024),
				4 => @$stats['peers'],
				6 => @$stats['speed_dn'],
				7 => @$stats['speed_up']
			);
			$peers = $one->getPeers();
			if (empty($peers)) {
				$tmp[2] = 'close';
				$channels[] = $tmp;
			}
			else {
				foreach ($peers as $peer => $client) {
					$tmp[5] = sprintf('%s(%d)', $client->getName(), $client->getBuffersCount());
					$channels[] = $tmp;
					$tmp = array(0 => '', '', '', '', '', '', '', '');
				}
			}
		}
		// это чтобы удалились все ссылки на объекты потока и клиента
		unset($client);
		unset($one);

		$EVENTS->tick($channels);
		// увеличение с 20 до 100мс улучшило ситуацию с переполнением клиентских сокетов
		usleep(30000);

	}
	catch (Exception $e) {
		$EVENTS->error($e->getMessage());
	}
}

// тормозим все трансляции, закрываем сокеты Ace
$streams->closeAll();


