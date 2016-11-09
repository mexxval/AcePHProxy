"use strict";

var STOP = false;

(function () {
	var ipport = document.body.getAttribute('ipport');
	if (!ipport) {
		$('#error').html('IP:PORT not found').show();
		return false;
	}

	var socket;
    var init = function () {
		socket = new WebSocket('ws://' + ipport + '/websocket/');
		socket.onopen = onOpen;
		socket.onmessage = onMessage;
		socket.onclose = function() {
			$('[data-connected]').attr('data-connected', 0);
			setTimeout(init, 1000);
		}
		socket.onerror = function() {
			$('[data-connected]').attr('data-connected', 0);
			$('#error').html('WebSocket error').show();
		};
    };

	function onOpen() {
		$('[data-connected]').attr('data-connected', 1);
		$('#error').html('').hide();
	}

	function onMessage(e) {
		var data = JSON.parse(e.data);
		// console.log("Got data: ", data);
		if (STOP) {
			return;
		}

		var stats = $('#stats');
		if (data && data.stats) {
			var tmp = data.stats.uptime.split(':');
			var d = Math.floor(tmp[0] / 24); // days
			var h = tmp[0] - d * 24; // hours (always < 24)
			stats.find('[data-content="uptime"]').html(data.stats.uptime);
			stats.find('[data-content="uptime_h"]').html((d ? (d + 'd ') : '') + h + 'h'); // human friendly
			stats.find('[data-content="memory"]').html(data.stats.ram);
			stats.find('[data-content="port"]').html(data.stats.port);
			$('[data-wwwok]').attr('data-wwwok', data.stats.wwwok ? '1' : '0');
			$('[data-content="maintitle"]').html(data.stats.title);
		}

		if (data && data.streams) {
			var peersOnPage = {}; // все, имеющиеся на странице
			var streamsOnPage = {}; // все, имеющиеся на странице
			$('#streams [data-peer]').each(function() {
				peersOnPage[$(this).attr('data-peer')] = false;
			});
			$('#streams [data-streamid]').each(function() {
				streamsOnPage[$(this).attr('data-streamid')] = false;
			});

			var example = $('#examples .app__stream');
			for (var streamid in data.streams) {
				var stream = data.streams[streamid];
				var existing = $('#streams [data-streamid="' + streamid + '"]');
				var newrow = existing.length == 1 ? existing : example.clone();
				// определим ширину прогрессбара, причем только активной его части!
				var pbWidth = newrow.find('[ui-element="progressbar"] .bar').width();
				streamsOnPage[streamid] = true;

				// fill with data
				for (var key in stream) {
					// если атрибут имеет не скалярное значение - пока пропускаем
					if (['number', 'string', 'boolean'].indexOf($.type(stream[key])) < 0) {
						continue;
					}
					// ставим атрибуты всем. кто их имеет
					// этот прием не работает! addBack()+filter().. не ищет атрибуты вложенных эл-в
					newrow.addBack().filter('[data-' + key + ']').attr('data-' + key, stream[key]);
						// так что добавляем отдельный проход по вложенным эл-м
						newrow.find('[data-' + key + ']').attr('data-' + key, stream[key]);
					// также заполняем текстовые ноды по атрибутам data-content="..."
					newrow.find('[data-content="' + key + '"]').html(stream[key]);
				}

				// клиенты
				for (var peer in stream.clients) {
					var clex = $('#examples .app__stream__client');
					var clexist = newrow.find('[data-peer="' + peer + '"]');
					var clrow = clexist.length == 1 ? clexist : clex.clone();
					var client = stream.clients[peer];
					client.peer = peer;
					// уберем клиента из массива для удаления
					peersOnPage[peer] = true;

					for (var key in client) {
						// ставим атрибуты всем. кто их имеет
						clrow.addBack().filter('[data-' + key + ']').attr('data-' + key, client[key]);
						clrow.find('[data-' + key + ']').attr('data-' + key, client[key]);
						// также заполняем текстовые ноды по атрибутам data-content="..."
						clrow.find('[data-content="' + key + '"]').html(client[key]);
					}
					// client position on progressbar
					var leftOffset = Math.round(pbWidth * client.ptrPosition / 100);
					clrow.find('div').stop().animate({left: (leftOffset + 'px')}, 150);
					clexist.length == 1 ||
						 clrow.appendTo(newrow.find('.app__clients'));
				}

				var state = stream.state.toLowerCase();
				if (['close', 'start'].indexOf(state) < 0) {
					state = stream.statistics.acestate;
				}
				// заполнение буфера
				var buf_fill_prc = Math.round( stream.bufferedLength / stream.bufferMaxLength * 100);
				newrow.find('[ui-element="progressbar"] .bar')
					.stop().animate({width: (buf_fill_prc + '%')});

				var buf_fill_prc = stream.statistics.bufpercent; // прогресс буферизации
				var bar2 = newrow.find('[ui-element="progressbar"] .bar2')
					.stop().animate({width: (buf_fill_prc + '%')}).show();
				state == 'dl' && bar2.hide();

				newrow.find('[ui-element="statistics-acestate"][data-acestate]').attr('data-acestate', '' + state);
				newrow.find('[ui-element="statistics-acestate"] [data-content="bufpercent"]').html(stream.statistics.bufpercent);
				newrow.find('[data-content="bufLenMb"]').html(Math.round(stream.bufferedLength / 1024 / 1024, 1) + 'Mb');
				newrow.attr('data-started', stream.statistics.started);

				// Добавляем в контейнер подходящего типа
				if (existing.length == 0) {
					newrow.appendTo($('[data-filter-type="' + stream.type + '"]'));
				}
			}

			// лишних клиентов (отвалившихся) и потоки (закрытые) удаляем
			for (var peer in peersOnPage) {
				if (peersOnPage[peer]) {
					continue;
				}
				$('[data-peer="' + peer + '"]').remove();
			}
			for (var streamid in streamsOnPage) {
				if (streamsOnPage[streamid]) {
					continue;
				}
				$('[data-streamid="' + streamid + '"]').remove();
			}
		}
		if (typeof(data) == 'string') { // log
			var example = $('#examples .logline');
			var newrow = example.clone();
			newrow.html(data);
			newrow.prependTo($('.logcontainer'));
		}
	}

	window.addEventListener('load', function () {
		init();
	}, false);
})();

