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
 * Рекомендованные опции запуска AceStream
 * --client-console --live-cache-size 200000000 --upload-limit 1000 --max-upload-slots 10 --live-buffer 45
 *
 * @author	mexxval
 * @link	http://blog.sci-smart.ru
 */

// TODO
// - stream_copy_to_stream [не фонтан]
// + сильно срать точками в сокет не стоит при буферизации. надо палить прогресс буферизации, 
//		и если он есть - выдавать точку, если его нет, то нет
// + [удалено] есть такая мысль. при запуске потока считывать в память небольшой буфер мегабайт 10-20 
//	 (подумать о зависимости от битрейта), использовать его для раздачи в моменты затыков 
//	 (также по 5-10 байт, вместо точек, ломающих картинку). также должно помочь избавиться от затыков,
//	 которые случаются стабильно при запуске потока в первые минуты
//	 вероятный алгоритм, читаем 10 буферов в очередь FIFO, писать на клиент начинаем с 11-го, 
//	   причем если данных не получено (буферизация ace), то пишем на клиент не очередную часть FIFO,
//	   а только несколько ее байт
// web-интерфейс, можно кстати через тот же порт 8000
// + перерисовка окна при ресайзе
// админская навигация по трансляциям в ncurses-UI и закрытие вручную
// + state buf + %
// - короче логика новая: надо пихать в сокеты столько, сколько туда влазит, максимальными порциями
//		(но с использованием FIFO), а читать из Ace с учетом "полноты" сокетов.
//		Т.е. пишем в сокеты элемент FIFO, если записан полностью - читаем 1 раз из Ace.
// ace может можно как то пнуть, чтоб не буферизовал так долго. буфер настроить поменьше или START сказать
// брать инфо о трансляции через LOADASYNC [там ничего особо интересного нет]
// + движок ace иногда просто падает (перезапускается), трансляция зависает при этом наглухо
//		AceConn надо научить следить за коннектом
// memory + cpu usage, uptime и другая статистика
// нормальное логирование со скроллом
// DLNA? multicast? see pecl extension Gupnp http://php.net/manual/ru/gupnp.installation.php
//		и вообще сетевые SAP потоки замутить, из XBMC-меню чтоб видно было
//		можно поковырять http://www.netlab.linkpc.net/forum/index.php?topic=898.0
// после DLNA/SAP/Multicast внедрить управление торрентами. чтобы из XBMC было видно, сколько осталось качаться
// вывести для каждого клиента время подключения (uptime)
// + обрезать выводимую строку по ширине столбца
// настроить хедеры: 
//	1. хром вероятно можно заставить показывать видео прямо на странице, если дать правильный хедер
//	2. перещелкивание PgUp/PgDn с пульта ТВ приводит к ошибке "Не удается найти след.файл", возможно тоже получится поправить
//  3. не знаю получится ли, но перематывать фильмы было бы тоже неплохо. 
//		с HTTP едва ли выйдет, может отдавать контент на отдельном порту и по ссылке tcp://0.0.0.0:xxxx ?
//		с наскоку не вышло. может контейнер добавить явный, mp4 например, или протокол сменить rtmp/rtsp
//		недокачанный mkv даже VLC не перематывает, даже назад, на просмотренный кусок. 
//			есть подозрение, что нужен конец файла для этого
//			http://sci-smart.ru/data/Hanna.2011.D.DVDRip.x264.mkv проигрывает и перематывает VLC и XBMC
//			VLC при запуске делает 4 GET запроса, получает 4 ответа 206 Partial Content
//		ААА НИШТЯК! главное сконнектить клиента напрямую с http ссылкой от Ace, перемотка работает!!
//			XBMC не перематывает
//			одну и ту же ссылку, расшаренную через socat, можно открыть только одним плеером
//			можно после получения START http расшаривать ее socat-ом и перенаправлять на нее клиента
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
// + динамически определять ширину окна и отдавать первой колонке все оставшееся место
// + поддержка Transfer-encoding: chunked
// + при запуске торрента все виснет в ожидании ссылки START http
//   рефакторинг: 
//		State-Machine обработчик запросов: пробивка сервера (HEAD,OPTIONS), NowPlaying/Recently Played, запуск видео
//		различные классы трасляций (расширение StreamUnit: запуск файлов, торрентов, live, rtsp),
//		расширение класса клиента (XBMC/обычный, на XBMC уведомления слать например)


// BUGS
// при ошибке коннекта к ace (нет демона), трансляция не удаляется из списка
// при детаче-аттаче screen и вообще при перерисовке окна (ресайз) stream_select() глючит, клиент может отвалиться
// VLC/Kodi/XBMC чтоб показывали. щас только XBMC кажет, остальные выебываются

// решенные проблемы по сокетам:
// - определяется нормальный коннект и дисконнект клиента (realtime)
// - определяется отвалившийся AceServer (realtime)
// - нет необходимости висеть в ожидании ссылки на поток (START http), 
//		готовность ссылки проверяется периодически, не вешая программу и клиентов
// - TODO определять отвалившийся клиент. тут только по таймауту
// - TODO классы исключений и коды ошибок
// - TODO для торрентов из нескольких видеофайлов (сезон сериала например) выдавать меню (плейлист)


define('ACEPHPROXY_VERSION', '0.6.2');

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
$pool = new ClientPool('0.0.0.0', $port = 8001);
// получает PID и выдает ссылку на трансляцию
$ace = new AceConnect($key);

// управляет трансляциями. заказывает их у Ace и раздает клиентам из pool
$streams = new StreamsManager($ace, $pool);

// при рефакторинге роль совершенно изменилась и не соответствует имени класса
// занимается отрисовкой ncurses интерфейса
$EVENTS = new EventController;
$EVENTS->init(' AcePHProxy v.' . ACEPHPROXY_VERSION . ' running on port ' . $port);

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
				// info - array('pid' => $pid, 'name' => $m[2], 'type' => 'trid|pid', 'client' => StreamClient);
				try {
					$client = $req->getClient();
					switch ($req->getType()) {
						case 'file':
						case 'pid':
						case 'trid':
						case 'acelive':
						case 'torrent':
							// для пробивочного запроса выдаем заголовки и закрываем коннект
							if ($req->getReqType() == 'HEAD' or ($req->isRanged() and $req->isEmptyRanged())) {
								$response = 'HTTP/1.1 200 OK' . "\r\n" .
									'Accept-Ranges: bytes' . "\r\n\r\n";
								$client->put($response);
								$client->close();
							} else {
								// да в stream надо тупо req передавать! и пусть там решает,как отвечать на HEAD, как на GET и т.д.
								$channel = $streams->start($req->getPid(), $req->getName(), $req->getType());
								// регистрируем клиента в потоке
								$channel->registerClient($client);
								unset($channel);
							}
							break;
						default:
							throw new Exception('Unknown request type');
					}
				}
				catch (Exception $e) {
					$client->close();
					$client->notify('Start error: ' . $e->getMessage(), 'error');
					error_log('unset client on start error');
					$EVENTS->error($e->getMessage());
				}
			}
			unset($info);

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
			$tmp = array(
				// если вместо строки массив: 0 - цвет, 1 - выводимая строка
				0 => array(0 => $titleColor, 1 => $one->getName()),
				1 => array(0 => $bufColor, 1 => $buf),
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
					$tmp[5] = sprintf('%s %d%%', $client->getName(), $client->getPointerPosition());
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



// AceProxy на кривой урл не выдает понятного для XBMC ответа, тот повторяет попытки открыть урл
// m3u открывается долго, потому как XBMC делает по 2 инфо-запроса: HEAD и Range:0-0, на что тоже не получает внятного ответа
// из-за 2 причин выше остановка потока не отрабатывает нормально (висит, пока не пройдут запросы по всем эл-там плейлиста)
// ссылка критична к /stream.mp4 на хвосте ссылки (/pid/<pid>/stream.mp4)
// для трансляции одного потока на несколько клиентов требует VLC
// при нажатии на эл-т плейлиста XBMC замирает секунды на 3-4, затем идет Подождите, потом только пойдет видео
// иногда, нажав на стоп в момент затыка, приходится долго ждать, пока пойдут данные, чтобы XBMC отвис


// несколько замеров времени старта. рестарт производился методом остановки работающего потока и немедленного запуска снова
// каждый замер длился около 1.5-2 мин
//				  клик .. Подождите .. открытие видео .. пошел звук .. 1-я буф-я старт .. финиш .. обрыв .. время рестарта
// AceProxy	VLC	: 0			5				12				12						50		-		78			22
// videotimeout : 0			4				12				12						16		-		да			28
// 20sec		: 0			5				-				-						-		-		30			23
//				: 0			5				13				13						49		-		78			20
//				: 0			5				13				13						55		-		80			23

// AceProxy		: 0			5				12				12						41		45		-			11
//				: 0			5				13				13						-		-		-			21
//				: 0			5				11				11						43		48		-			11
//				: 0			5				11				11						42		46		-			27 не стартануло
//				: 0			5				12				12						42		45		-			23

// AcePHProxy	: 0			0				4				5						31		46		-			3
//				: 0			0				4				4						53		67		-			20
//				: 0			0				4				4						49		62		-			2
//				: 0			0				5				5						26		42		-			21
//				: 0			0				5				5						54		68		-			19

// после настройки параметров запуска AceStream Engine, запуск по PID, вместо trid. 3min на тест
// AcePHProxy	: 0			0				3				3						-		- не было -			<1; 5
//				: 0			0				3				3						-		- не было -			2
//				: 0			0				2.5				2.5						-		- не было -			1; <1
//			HD	: 0			0				3				3						-		- не было -			<1; <1
//			HD	: 0			0				3.5				3.5						31		36		-			1
// в последнем тесте было 6 пиров всего, может потому проскочила буферизация


