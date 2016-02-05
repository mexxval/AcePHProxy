<?php
/**
 * Демон трансляции торрент-тв.
 * Для запуска потока нужно обратиться к демону по http://<host>:8000/pid/<pid>/<Stream Name>
 * Например ссылка трансляции канала 2x2 будет выглядеть так
 * http://127.0.0.1:8000/pid/0d2137fc5d44fa9283b6820973f4c0e017898a09/2x2
 * <Stream Name> нужен для отображения в ncurses интерфейсе
 *
 * Для работы требует PHP с pecl-расширением ncurses и сервер AceStream
 * Поддерживает подключение множества клиентов к одной трансляции.
 * Поддерживает воспроизведение .torrent файлов с возможностью перемотки и
 *	просмотра с заданного места
 * Рекомендованные опции запуска AceStream
 * --client-console --live-cache-size 200000000 --upload-limit 1000 --max-upload-slots 10 --live-buffer 45
 *
 * @author	mexxval
 * @link	http://blog.sci-smart.ru
 */

// TODO
// web-интерфейс, можно кстати через тот же порт 8000
// админская навигация по трансляциям в ncurses-UI и закрытие вручную
// ace может можно как то пнуть, чтоб не буферизовал так долго. буфер настроить поменьше или START сказать
//	[не похоже, что такое возможно. однако при скорости интернет-канала 48Мбит фильм стартует за 7-15 сек]
// + memory + cpu usage, uptime и другая статистика
// + вывести для каждого клиента время подключения (uptime)
// нормальное логирование со скроллом
// DLNA? multicast? see pecl extension Gupnp http://php.net/manual/ru/gupnp.installation.php
//		и вообще сетевые SAP потоки замутить, из XBMC-меню чтоб видно было
//		можно поковырять http://www.netlab.linkpc.net/forum/index.php?topic=898.0
// после DLNA/SAP/Multicast внедрить управление торрентами. чтобы из XBMC было видно, сколько осталось качаться
// настроить хедеры: 
//	1. хром вероятно можно заставить показывать видео прямо на странице, если дать правильный хедер
//	2. перещелкивание PgUp/PgDn с пульта ТВ приводит к ошибке "Не удается найти след.файл", возможно тоже получится поправить
// таймаут операций при недоступном torrent-tv.ru: 
//   висит на searching pid, authorizing, при этом других клиентов даже не обрабатывает
// на XBMC узлы можно уведомлениями слать фидбек от демона (tcp 9090 jsonrpc)
//		+реконнект, упала скорость, нет сидов, +не удалось запустить PID, +упал инет, трансляция мертва (Down не растет)
//		необходимость и информативность уведомлений можно задавать в параметре урла запроса трансляции
// + фильмы стартуют не так охотно. бывает клиент уже отвалился, а тут из ace приходит наконец команда start. 
//		Keep-Alive: header, HTTP/1.1, посмотреть какой HTTP умеет XBMC. в 1.1 коннекты вообще персистентны изначально
//			http://habrahabr.ru/post/184302/
//      надо клиента снова цеплять, используя jsonrpc
//		[помогает на клиенте в advansedsettings.xml проставить advancedsettings/network/curllowspeedtime в 60 сек]
//		может через header Location клиента закольцевать, пока торрент грузится
//		вообще есть еще вариант, запускать фильм не через сам XBMC, а через этот софт. 
//		Пусть прогрузит фильм и потом подцепит XBMC
//		а может вообще держать постоянный коннект и полностью интегрировать управление XBMC<->AcePHProxy
//		к тому же поиграться с EVENT от клиента к движку
// рефакторинг: 
//		+ State-Machine обработчик запросов: пробивка сервера (HEAD,OPTIONS), NowPlaying/Recently Played, запуск видео
//		различные классы трасляций (расширение StreamUnit: запуск файлов, торрентов, live, rtsp),
//		расширение класса клиента (XBMC/обычный, на XBMC уведомления слать например)


// BUGS
// при ошибке коннекта к ace (нет демона), трансляция не удаляется из списка
// при детаче-аттаче screen и вообще при перерисовке окна (ресайз) stream_select() глючит, клиент может отвалиться
// VLC/Kodi/XBMC чтоб показывали. щас только XBMC кажет, остальные выебываются
// при просмотре фильма или серии в конце, когда плеер уже остановлен, коннект какое то время еще висит, секунд 10
// этот же косяк является причиной еще одного: если пока предыдущий коннект висит открыть следующую серию,
//	то пребуферизация прерывается в момент закрытия висящего коннекта


// решенные проблемы по сокетам:
// - определяется нормальный коннект и дисконнект клиента (realtime)
// - определяется отвалившийся AceServer (realtime)
// - нет необходимости висеть в ожидании ссылки на поток (START http), 
//		готовность ссылки проверяется периодически, не вешая программу и клиентов
// - для торрентов из нескольких видеофайлов (сезон сериала например) выдавать меню (плейлист)
// - TODO определять отвалившийся клиент. тут только по таймауту
// - TODO классы исключений и коды ошибок
// - TODO скачанные фильмы по событию cansave надо переносить в фильмотеку
// - TODO не обрабатывается ситуация с умершим ace, когда он совсем не запущен. бесконечные попытки подключиться
// - TODO если isLive и поток кончился (косяк трансляции, гоняется кэш) - закрывать клиента быстрее. 
//		а то XBMC заипет висеть до таймаута, даже стоп не помогает
// - TODO периодически снимать скриншоты потоков, а еще есть программа передач, 
//		как бы это к XBMC прикрутить, чтобы не приходилось запускать каналы ради "глянуть, что идет"
// - TODO помечать ecoMode в UI
// - TODO реализовать интеграцию с rTorrent. в частности требуется запуск фильма по infohash,
//		rtorrent поможет сделать из магнет-ссылки torrent-файл
//		[пробовал подсунуть такой torrent, ace его не ест. говорит "announce, nodes and announce-list missing"]
// - TODO все хранить на стороне софта, список каналов, папка с торрентами. оформить как подключаемые модули
//		можно подключить разные сайты, torrent-tv.ru, yify-torrent.org, eztv, etc

define('ACEPHPROXY_VERSION', '0.6.5');

require_once dirname(__FILE__) . '/class.bdecode.php';
require_once dirname(__FILE__) . '/class.client_pool.php';
require_once dirname(__FILE__) . '/class.stream_client.php';
require_once dirname(__FILE__) . '/class.stream_unit.php';
require_once dirname(__FILE__) . '/class.ace_connect.php';
require_once dirname(__FILE__) . '/class.ncurses_ui.php';
require_once dirname(__FILE__) . '/class.streams_mgr.php';

mb_internal_encoding('UTF-8');

// создаем коннект к acestream, запускаем клиентский сокет
// изначально был этот ключ
$key = 'n51LvQoTlJzNGaFxseRK-uvnvX-sD4Vm5Axwmc4UcoD-jruxmKsuJaH0eVgE';



// создает сокет сервера трансляций и управляет коннектами клиентов к демону
$pool = new ClientPool('0.0.0.0', $PORT = 8000);
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
$STARTEDTS = time();

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


while (!$ctrlC) {
	$check_inet = (time() - $last_check) > 10; // every N sec
	try {
		if ($check_inet) {
			$wwwOK = $EVENTS->checkWWW($wwwChanged);
			$last_check = time();
			if ($wwwChanged) {
				$pool->notify(($wwwOK ? 'Интернет восстановлен' : 'Интернет упал'));
			}
		}

		// получаем статистику по новым клиентам, отвалившимся клиентам и запросам на запуск трансляций
		if ($new = $pool->track4new()) {
			foreach ($new['start'] as $peer => $req) {
				try {
					$streams->start($req);
				}
				catch (Exception $e) {
					$client = $req->getClient();
					$client->close();
					$client->notify('Start error: ' . $e->getMessage(), 'error');
					error_log('unset client on start error');
					$EVENTS->error($e->getMessage());
				}
			}
			unset($info, $req, $client); // обязательно. ибо лишние object-ссылки

			foreach ($new['new'] as $peer => $_) {
			}
			foreach ($new['done'] as $peer => $_) {
			}

			// быстренько валим на новый цикл
			if ($new['recheck']) {
				continue;
			}
		}


		// раскидываем контент по клиентам
		$streams->closeWaitingStreams();
		$streams->copyContents();

		// задача - собрать массив трансляций
		$channels = array();
		$allStreams = $streams->getStreams();

		// собираем инфу для вывода в UI
		foreach ($allStreams as $pid => $one) {
			$stats = $one->getStatistics();
			$isRest = $one->isRestarting();
			$bufColor = EventController::CLR_GREEN;
			$titleColor = EventController::CLR_DEFAULT;
			if ($isRest) {
				$bufColor = EventController::CLR_SPEC1;
				$titleColor = EventController::CLR_ERROR;
			}
			else if (@$stats['emptydata']) {
				$bufColor = EventController::CLR_ERROR;
			}
			else if (@$stats['shortdata']) {
				$bufColor = EventController::CLR_YELLOW;
			}

			$bufLen = round($one->getBufferedLength() / 1024 / 1024) . ' Mb';
			// показываем поочередно размер буфера чтения и размер прочитанного внутреннего буфера
			$buf = time() % 2 ? $one->getBufferSize() : $bufLen;
			$s = iconv('cp866', 'utf8', chr(249)); // значок заполнитель
			$tmp = array(
				// если вместо строки массив: 0 - цвет, 1 - выводимая строка
				0 => array(0 => $titleColor, 1 => $one->getName()),
				1 => array(0 => $bufColor, 1 => $buf),
				2 => $one->getState(),
				3 => @$stats['peers'],
				4 => sprintf('%\'.-7d%\'.6d', @$stats['ul_bytes']/1024/1024, @$stats['dl_bytes']/1024/1024),
				6 => sprintf('%\'.-6d%\'.6d', @$stats['speed_dn'],  @$stats['speed_up'])
			);
			$peers = $one->getPeers();
			if (empty($peers)) {
				$tmp[2] = 'close';
				$channels[] = $tmp;
			}
			else {
				foreach ($peers as $peer => $client) {
					// выводим поочередно то клиента, то его статистику
					// это поле размером 24 символа
					$tmp[5] = round(time() / 0.6) % 2 ? 
						sprintf('%s %d%%', $client->getName(), $client->getPointerPosition()) :
						sprintf('%-13s %8s', $client->getUptime(), $client->getTraffic()) ;
					$channels[] = $tmp;
					$tmp = array(0 => '', '', '', '', '', '', '');
				}
			}
		}
		// это чтобы удалились все ссылки на объекты потока и клиента
		unset($client);
		unset($one);

		// выведем аптайм и потребляемую память
		$allsec = time() - $STARTEDTS;
		$secs = sprintf('%02d', $allsec % 60);
		$mins = sprintf('%02d', floor($allsec / 60 % 60));
		$hours = sprintf('%02d', floor($allsec / 3600));
		$mem = memory_get_usage(); // bytes
		$mem = round($mem / (1024 * 1024), 1); // MBytes

		$addinfo = array(
			'ram' => $mem,
			'uptime' => "$hours:$mins:$secs",
			'title' => ' AcePHProxy v.' . ACEPHPROXY_VERSION . ' ',
			'port' => $PORT
		);
		$EVENTS->tick($channels, $addinfo);
		// увеличение с 20 до 100мс улучшило ситуацию с переполнением клиентских сокетов
		usleep(30000);
	}
	catch (Exception $e) {
		$EVENTS->error($e->getMessage());
	}
}

// тормозим все трансляции, закрываем сокеты Ace
$streams->closeAll();



