(function ($) {
	'use strict';

	var config = window.dnBfsAdmin || {};
	var strings = config.strings || {};
	var state = {
		period: config.period || 'month_to_date',
		compare: config.compare || 'previous_year',
		start: config.start || '',
		end: config.end || '',
		filters: $.isPlainObject(config.filters) ? $.extend({}, config.filters) : {}
	};

	function requestData(extra) {
		return $.extend({
			nonce: config.nonce,
			period: state.period,
			compare: state.compare,
			start: state.start,
			end: state.end,
			filter: state.filters
		}, extra || {});
	}

	function post(action, extra) {
		return $.post(config.ajaxUrl, requestData($.extend({ action: action }, extra || {})));
	}

	function errorMessage(source) {
		var body = source && source.responseJSON ? source.responseJSON : source;
		return body && body.data && body.data.message ? body.data.message : (strings.error || 'Unable to load data. Please try again.');
	}

	function getCurrentTab() {
		var $active = $('[data-dn-tab].nav-tab-active');
		return $active.length ? $active.attr('data-dn-tab') : (config.tab || 'overview');
	}

	function setLoading($region) {
		$region.addClass('is-loading');
		if (!$region.find('.dn-burst-loading').length) {
			$region.prepend($('<div class="dn-burst-loading"></div>').text(strings.loading || 'Loading data…'));
		}
	}

	function clearLoading($region) {
		$region.removeClass('is-loading').find('.dn-burst-loading').remove();
	}

	function showError($region, message) {
		$region.html($('<div class="notice notice-error inline"><p></p></div>').find('p').text(message).end());
	}

	function updateUrl(tab) {
		if (!window.history || !window.history.pushState) {
			return;
		}

		var url = new URL(window.location.href);
		var stale = [];

		url.searchParams.forEach(function (value, key) {
			if (key.indexOf('dn_filter[') === 0) {
				stale.push(key);
			}
		});
		stale.forEach(function (key) {
			url.searchParams.delete(key);
		});

		url.searchParams.set('dn_tab', tab);
		url.searchParams.set('dn_period', state.period);
		url.searchParams.set('dn_compare', state.compare);

		if (state.period === 'custom') {
			url.searchParams.set('dn_start', state.start);
			url.searchParams.set('dn_end', state.end);
		} else {
			url.searchParams.delete('dn_start');
			url.searchParams.delete('dn_end');
		}

		$.each(state.filters, function (dimension, value) {
			url.searchParams.set('dn_filter[' + dimension + ']', value);
		});

		window.history.pushState({ dnTab: tab }, '', url.toString());
	}

	function updateDateButton(range) {
		if (!range) {
			return;
		}

		var compare = range.compare !== 'none' && range.previous_range_label ? range.compare_label + ' (' + range.previous_range_label + ')' : '';

		$('.dn-burst-date-title').text(range.current_label + ' (' + range.current_range_label + ')');
		$('.dn-burst-date-compare').text(compare).prop('hidden', !compare);
	}

	function loadTab(tab, pushState) {
		var $content = $('[data-dn-tab-content]');

		if (!$content.length) {
			return;
		}

		setLoading($content);

		post('dn_bfs_load_tab', { tab: tab }).done(function (response) {
			if (!response || !response.success) {
				showError($content, errorMessage(response));
				return;
			}

			$content.html(response.data.html);
			$('[data-dn-tab]').removeClass('nav-tab-active').filter(function () {
				return String($(this).attr('data-dn-tab')) === String(tab);
			}).addClass('nav-tab-active');
			$('.dn-burst-topbar-title').text(response.data.title);
			config.tab = tab;
			updateDateButton(response.data.range);
			initCharts();

			if (pushState) {
				updateUrl(tab);
			}
		}).fail(function (xhr) {
			showError($content, errorMessage(xhr));
		}).always(function () {
			clearLoading($content);
		});
	}

	function loadTable($table, overrides) {
		var $region = $table.closest('[data-dn-table-region]');

		setLoading($region);

		post('dn_bfs_table', $.extend({
			tab: $table.attr('data-tab'),
			dimension: $table.attr('data-dimension'),
			orderby: $table.attr('data-orderby'),
			order: $table.attr('data-order'),
			page: $table.attr('data-page') || 1
		}, overrides || {})).done(function (response) {
			if (!response || !response.success) {
				showError($region, errorMessage(response));
				return;
			}

			$region.html(response.data.html);
		}).fail(function (xhr) {
			showError($region, errorMessage(xhr));
		}).always(function () {
			clearLoading($region);
		});
	}

	function renderFilterChips() {
		var $chips = $('[data-dn-filter-chips]').empty();
		var labels = config.filterLabels || {};

		$.each(state.filters, function (dimension, value) {
			var $chip = $('<span class="dn-burst-chip"></span>').attr('data-dn-filter-dim', dimension);

			$chip.append($('<span></span>').text((labels[dimension] || dimension) + ': ' + value));
			$chip.append($('<button type="button" class="dn-burst-chip-remove" data-dn-filter-remove></button>').attr('aria-label', strings.removeFilter || 'Remove filter').html('&times;'));
			$chips.append($chip);
		});
	}

	function setFilter(dimension, value) {
		if (value === '' || value === null || typeof value === 'undefined') {
			delete state.filters[dimension];
		} else {
			state.filters[dimension] = String(value);
		}

		renderFilterChips();
		loadTab(getCurrentTab(), true);
	}

	function drawer() {
		var $drawer = $('[data-dn-drawer]');

		if (!$drawer.length) {
			$drawer = $('<div class="dn-burst-drawer" data-dn-drawer role="dialog" aria-modal="false" hidden><div class="dn-burst-drawer-body" data-dn-drawer-body></div></div>').appendTo('body');
		}

		return $drawer;
	}

	function openDrilldown(dimension, value) {
		var $drawer = drawer();
		var $body = $drawer.find('[data-dn-drawer-body]').empty();

		$drawer.prop('hidden', false);
		setLoading($body);

		post('dn_bfs_drilldown', { dimension: dimension, value: value }).done(function (response) {
			if (!response || !response.success) {
				showError($body, errorMessage(response));
				return;
			}

			$body.html(response.data.html);
			initCharts();
		}).fail(function (xhr) {
			showError($body, errorMessage(xhr));
		}).always(function () {
			clearLoading($body);
		});
	}

	var cardsSnapshot = null;

	function cardsGrid() {
		return $('[data-dn-cards]');
	}

	function toggleCardButtons(editing) {
		$('[data-dn-cards-edit]').prop('hidden', editing);
		$('[data-dn-cards-save], [data-dn-cards-cancel], [data-dn-cards-reset]').prop('hidden', !editing);
	}

	function exitCardEdit() {
		var $grid = cardsGrid();

		if ($.fn.sortable && $grid.hasClass('ui-sortable')) {
			$grid.sortable('destroy');
		}

		$grid.removeClass('is-editing');
		toggleCardButtons(false);
	}

	function refreshOnline() {
		var $badge = $('[data-dn-online]');

		if (!$badge.length) {
			return;
		}

		post('dn_bfs_realtime').done(function (response) {
			if (!response || !response.success) {
				return;
			}

			var $popover = $('[data-dn-online-popover]').empty();
			var $list = $('<ul></ul>');

			$badge.find('[data-dn-online-count]').text(response.data.online);

			$.each(response.data.pages || [], function (_, row) {
				$list.append($('<li></li>').append($('<code></code>').text(row.path)).append($('<span></span>').text(' ' + row.visitors)));
			});

			$popover.append($('<h3></h3>').text(strings.activePages || 'Pages being viewed'));
			$popover.append($list.children().length ? $list : $('<p></p>').text(strings.nobodyOnline || 'Nobody is online right now.'));
		});
	}

	function normalizeSeries(data) {
		if (data && $.isArray(data.series)) {
			return data.series;
		}

		if (data && $.isArray(data.values)) {
			return [{
				label: data.label || '',
				color: '#2271b1',
				format: data.format || 'integer',
				axis: 'left',
				values: data.values
			}];
		}

		return [];
	}

	function getAllValues(series) {
		return series.reduce(function (carry, row) {
			return carry.concat((row.values || []).map(function (value) {
				return Number(value) || 0;
			}));
		}, []);
	}

	function hasMeaningfulChartData(series) {
		return getAllValues(series).some(function (value) {
			return value > 0;
		});
	}

	function escapeHtml(value) {
		return $('<div>').text(value == null ? '' : String(value)).html();
	}

	function formatNumber(value, minPlaces, maxPlaces, thousand, decimal) {
		var parts = Number(value).toFixed(maxPlaces).split('.');
		var whole = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thousand);
		var fraction = parts.length > 1 ? parts[1] : '';

		while (fraction.length > minPlaces && fraction.charAt(fraction.length - 1) === '0') {
			fraction = fraction.slice(0, -1);
		}

		return fraction ? whole + decimal + fraction : whole;
	}

	function formatValue(value, format) {
		var number = Number(value) || 0;
		var abs = Math.abs(number);
		var decimals = abs > 0 && abs < 10 && format !== 'integer' ? 1 : 0;

		if (format === 'money') {
			var currency = config.currency || {};
			var places = currency.decimals == null ? 2 : Number(currency.decimals);
			var symbol = currency.symbol || '$';
			var position = currency.position || 'left';
			var text = formatNumber(
				abs,
				abs > 0 && abs < 10 ? places : 0,
				places,
				currency.thousand == null ? ',' : String(currency.thousand),
				currency.decimal == null ? '.' : String(currency.decimal)
			);

			if (position === 'right') {
				text = text + symbol;
			} else if (position === 'right_space') {
				text = text + '\u00a0' + symbol;
			} else if (position === 'left_space') {
				text = symbol + '\u00a0' + text;
			} else {
				text = symbol + text;
			}

			return (number < 0 ? '-' : '') + text;
		}

		if (format === 'percent') {
			return number.toLocaleString(undefined, {
				minimumFractionDigits: decimals,
				maximumFractionDigits: 1
			}) + '%';
		}

		return Math.round(number).toLocaleString();
	}

	function niceMax(max, format) {
		max = Number(max) || 0;

		if (format === 'percent') {
			if (max <= 0) {
				return 1;
			}

			if (max <= 2) {
				return 2;
			}

			if (max <= 5) {
				return 5;
			}

			if (max <= 10) {
				return 10;
			}

			return Math.ceil((max * 1.15) / 10) * 10;
		}

		if (max <= 0) {
			return 1;
		}

		var power = Math.pow(10, Math.max(0, Math.floor(Math.log(max) / Math.LN10) - 1));
		return Math.ceil((max * 1.15) / power) * power;
	}

	function truncateText(ctx, text, maxWidth) {
		text = String(text || '');

		if (ctx.measureText(text).width <= maxWidth) {
			return text;
		}

		while (text.length > 1 && ctx.measureText(text + '...').width > maxWidth) {
			text = text.slice(0, -1);
		}

		return text + '...';
	}

	function setupCanvas(canvas, $canvas) {
		var ctx = canvas.getContext('2d');
		var width = Math.max(320, Math.floor($canvas.parent().width() || 600));
		var height = Math.max(220, Number($canvas.attr('height')) || 250);
		var ratio = window.devicePixelRatio || 1;

		canvas.style.width = width + 'px';
		canvas.style.height = height + 'px';
		canvas.width = Math.floor(width * ratio);
		canvas.height = Math.floor(height * ratio);
		ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
		ctx.clearRect(0, 0, width, height);

		return {
			ctx: ctx,
			width: width,
			height: height
		};
	}

	function drawGrid(ctx, width, chart) {
		var rows = 4;
		ctx.strokeStyle = '#e5e5e5';
		ctx.lineWidth = 1;

		for (var i = 0; i <= rows; i++) {
			var y = chart.top + (chart.height / rows * i);
			ctx.beginPath();
			ctx.moveTo(chart.left, y);
			ctx.lineTo(width - chart.right, y);
			ctx.stroke();
		}
	}

	function drawLineAxisLabels(ctx, labels, leftMax, rightMax, width, height, chart, leftFormat, rightFormat) {
		var rows = 4;
		ctx.font = '600 11px sans-serif';
		ctx.fillStyle = '#50575e';
		ctx.textAlign = 'right';
		ctx.textBaseline = 'middle';

		for (var i = 0; i <= rows; i++) {
			var y = chart.top + (chart.height / rows * i);
			var leftValue = leftMax - (leftMax / rows * i);
			ctx.fillText(formatValue(leftValue, leftFormat), chart.left - 10, y);

			if (rightMax) {
				ctx.textAlign = 'left';
				ctx.fillText(formatValue(rightMax - (rightMax / rows * i), rightFormat || 'integer'), width - chart.right + 10, y);
				ctx.textAlign = 'right';
			}
		}

		if (!labels || !labels.length) {
			return;
		}

		ctx.textAlign = 'center';
		ctx.textBaseline = 'top';
		ctx.fillStyle = '#646970';
		ctx.font = '600 11px sans-serif';
		var visibleEvery = Math.max(1, Math.ceil(labels.length / 5));
		var step = labels.length > 1 ? chart.width / (labels.length - 1) : chart.width;

		labels.forEach(function (label, index) {
			if (index % visibleEvery !== 0 && index !== labels.length - 1) {
				return;
			}

			var x = chart.left + (step * index);
			ctx.fillText(String(label || ''), x, height - 20);
		});
	}

	function drawSmoothLine(ctx, points, color) {
		if (!points.length) {
			return;
		}

		ctx.strokeStyle = color;
		ctx.lineWidth = 3;
		ctx.lineJoin = 'round';
		ctx.lineCap = 'round';
		ctx.beginPath();
		ctx.moveTo(points[0].x, points[0].y);

		for (var i = 1; i < points.length; i++) {
			var prev = points[i - 1];
			var point = points[i];
			var cx = (prev.x + point.x) / 2;
			ctx.bezierCurveTo(cx, prev.y, cx, point.y, point.x, point.y);
		}

		ctx.stroke();

		points.forEach(function (point) {
			ctx.beginPath();
			ctx.fillStyle = '#fff';
			ctx.strokeStyle = color;
			ctx.lineWidth = 2.5;
			ctx.arc(point.x, point.y, 4, 0, Math.PI * 2);
			ctx.fill();
			ctx.stroke();
		});
	}

	function bindTooltip(canvas, items, formatter) {
		var $canvas = $(canvas);
		var $panel = $canvas.closest('.dn-burst-chart-panel');
		var $tooltip = $panel.find('.dn-burst-chart-tooltip');

		$canvas.off('.dnBurstChart');

		if (!items.length || !$tooltip.length) {
			return;
		}

		$canvas.on('mouseleave.dnBurstChart', function () {
			$tooltip.prop('hidden', true);
		});

		$canvas.on('mousemove.dnBurstChart', function (event) {
			var offset = $canvas.offset();
			var x = event.pageX - offset.left;
			var y = event.pageY - offset.top;
			var match = null;
			var distance = Infinity;

			items.forEach(function (item) {
				var isInside = item.bounds
					&& x >= item.bounds.left
					&& x <= item.bounds.right
					&& y >= item.bounds.top
					&& y <= item.bounds.bottom;
				var dx = item.x == null ? 0 : Math.abs(item.x - x);
				var dy = item.y == null ? 0 : Math.abs(item.y - y);
				var candidateDistance = isInside ? 0 : dx + (dy * 0.25);

				if (candidateDistance < distance) {
					distance = candidateDistance;
					match = item;
				}
			});

			if (!match || distance > 36) {
				$tooltip.prop('hidden', true);
				return;
			}

			$tooltip.html(formatter(match)).prop('hidden', false);
			$tooltip.css({
				left: Math.max(8, Math.min(x + 14, $canvas.parent().width() - $tooltip.outerWidth() - 12)),
				top: Math.max(8, y - $tooltip.outerHeight() - 12)
			});
		});
	}

	function tooltipRows(title, rows) {
		var html = '<strong>' + escapeHtml(title) + '</strong>';

		rows.forEach(function (row) {
			html += '<span class="dn-burst-tooltip-row"><span><span class="dn-burst-tooltip-swatch" style="background-color:' + escapeHtml(row.color || '#2271b1') + '"></span>' + escapeHtml(row.label) + '</span><b>' + escapeHtml(row.value) + '</b></span>';
		});

		return html;
	}

	function renderLineChart(canvas, data, type, ctx, width, height, series, hasData) {
		var labels = data.labels || [];
		var hasRightAxis = series.some(function (row) {
			return row.axis === 'right';
		});
		var chart = {
			left: 64,
			right: hasRightAxis ? 54 : 18,
			top: 24,
			bottom: 38
		};
		var leftSeries = series.filter(function (row) {
			return row.axis !== 'right';
		});
		var rightSeries = series.filter(function (row) {
			return row.axis === 'right';
		});
		var leftValues = getAllValues(leftSeries);
		var rightValues = getAllValues(rightSeries);
		var leftFormat = leftSeries[0] && leftSeries[0].format ? leftSeries[0].format : (data.format || 'integer');
		var rightFormat = rightSeries[0] && rightSeries[0].format ? rightSeries[0].format : 'integer';
		var leftMax = niceMax(Math.max.apply(null, leftValues.concat([0])), leftFormat);
		var rightMax = rightValues.length ? niceMax(Math.max.apply(null, rightValues.concat([0])), rightFormat) : 0;
		var tooltipItems = [];

		if (type === 'conversion') {
			leftFormat = 'percent';
			leftMax = niceMax(Math.max.apply(null, leftValues.concat([0])), 'percent');
		}

		chart.width = width - chart.left - chart.right;
		chart.height = height - chart.top - chart.bottom;

		drawGrid(ctx, width, chart);
		drawLineAxisLabels(ctx, labels, leftMax, rightMax, width, height, chart, leftFormat, rightFormat);

		if (!hasData) {
			bindTooltip(canvas, [], $.noop);
			return;
		}

		series.forEach(function (row) {
			var rowValues = row.values || [];
			var rowMax = row.axis === 'right' ? rightMax : leftMax;
			var step = rowValues.length > 1 ? chart.width / (rowValues.length - 1) : chart.width;
			var points = rowValues.map(function (value, index) {
				return {
					x: chart.left + (step * index),
					y: chart.top + chart.height - (((Number(value) || 0) / rowMax) * chart.height),
					index: index,
					value: Number(value) || 0
				};
			});

			drawSmoothLine(ctx, points, row.color || '#2271b1');

			points.forEach(function (point) {
				tooltipItems.push({
					x: point.x,
					y: point.y,
					index: point.index
				});
			});
		});

		bindTooltip(canvas, tooltipItems, function (item) {
			var rows = series.map(function (row) {
				var value = row.values && row.values[item.index] != null ? row.values[item.index] : 0;
				return {
					label: row.label || '',
					color: row.color || '#2271b1',
					value: formatValue(value, row.format || data.format || 'integer')
				};
			});

			return tooltipRows(labels[item.index] || '', rows);
		});
	}

	function renderHorizontalBarChart(canvas, data, ctx, width, height, series, hasData) {
		var labels = data.labels || [];
		var values = series[0] && series[0].values ? series[0].values : (data.values || []);
		var format = series[0] && series[0].format ? series[0].format : (data.format || 'integer');
		var color = series[0] && series[0].color ? series[0].color : '#2271b1';
		var chart = {
			left: Math.min(178, Math.max(116, Math.floor(width * 0.34))),
			right: 18,
			top: 18,
			bottom: 30
		};
		var max = niceMax(Math.max.apply(null, values.concat([0])), format);
		var count = Math.max(1, labels.length);
		var rowHeight = Math.max(22, Math.min(34, (height - chart.top - chart.bottom) / count));
		var barHeight = Math.max(10, Math.min(18, rowHeight * 0.52));
		var tooltipItems = [];

		chart.width = width - chart.left - chart.right;
		chart.height = height - chart.top - chart.bottom;

		ctx.strokeStyle = '#e5e5e5';
		ctx.fillStyle = '#646970';
		ctx.font = '600 11px sans-serif';
		ctx.textAlign = 'right';
		ctx.textBaseline = 'middle';

		for (var i = 0; i <= 4; i++) {
			var x = chart.left + (chart.width / 4 * i);
			ctx.beginPath();
			ctx.moveTo(x, chart.top);
			ctx.lineTo(x, chart.top + chart.height);
			ctx.stroke();
			ctx.fillText(formatValue(max / 4 * i, format), x, height - 18);
		}

		if (!hasData) {
			bindTooltip(canvas, [], $.noop);
			return;
		}

		labels.forEach(function (label, index) {
			var value = Number(values[index]) || 0;
			var y = chart.top + (rowHeight * index) + (rowHeight / 2);
			var barWidth = max > 0 ? (value / max) * chart.width : 0;

			ctx.textAlign = 'right';
			ctx.fillStyle = '#2c3338';
			ctx.font = '600 12px sans-serif';
			ctx.fillText(truncateText(ctx, label, chart.left - 14), chart.left - 12, y);

			ctx.fillStyle = '#edf6ff';
			ctx.fillRect(chart.left, y - (barHeight / 2), chart.width, barHeight);
			ctx.fillStyle = color;
			ctx.fillRect(chart.left, y - (barHeight / 2), Math.max(2, barWidth), barHeight);

			ctx.textAlign = 'left';
			ctx.fillStyle = '#50575e';
			ctx.font = '600 11px sans-serif';
			ctx.fillText(formatValue(value, format), Math.min(chart.left + barWidth + 8, width - 76), y);

			tooltipItems.push({
				bounds: {
					left: 0,
					right: width,
					top: y - (rowHeight / 2),
					bottom: y + (rowHeight / 2)
				},
				label: label,
				value: value,
				format: format,
				color: color
			});
		});

		bindTooltip(canvas, tooltipItems, function (item) {
			return tooltipRows(item.label, [{
				label: strings.sales || 'Sales',
				color: item.color,
				value: formatValue(item.value, item.format)
			}]);
		});
	}

	function renderFunnelChart(canvas, data, ctx, width, height, hasData) {
		var labels = data.labels || [];
		var values = data.values || [];
		var colors = ['#2271b1', '#00a32a', '#dba617', '#7f54b3', '#d63638'];
		var chart = {
			left: Math.min(142, Math.max(104, Math.floor(width * 0.28))),
			right: 24,
			top: 24,
			bottom: 22
		};
		var base = Math.max(1, Number(values[0]) || 0);
		var max = Math.max.apply(null, values.concat([base]));
		var count = Math.max(1, labels.length);
		var rowHeight = (height - chart.top - chart.bottom) / count;
		var barHeight = Math.max(16, Math.min(28, rowHeight * 0.48));
		var tooltipItems = [];

		chart.width = width - chart.left - chart.right;
		chart.height = height - chart.top - chart.bottom;

		ctx.strokeStyle = '#e5e5e5';
		ctx.fillStyle = '#646970';
		ctx.font = '600 11px sans-serif';
		ctx.textAlign = 'left';
		ctx.textBaseline = 'middle';

		for (var i = 0; i <= 4; i++) {
			var x = chart.left + (chart.width / 4 * i);
			ctx.beginPath();
			ctx.moveTo(x, chart.top - 4);
			ctx.lineTo(x, chart.top + chart.height + 4);
			ctx.stroke();
		}

		labels.forEach(function (label, index) {
			var value = Number(values[index]) || 0;
			var previous = index > 0 ? Number(values[index - 1]) || 0 : base;
			var stepRate = previous > 0 ? (value / previous) * 100 : 0;
			var y = chart.top + (rowHeight * index) + (rowHeight / 2);
			var barWidth = max > 0 ? (value / max) * chart.width : 0;
			var color = colors[index % colors.length];

			ctx.textAlign = 'right';
			ctx.fillStyle = '#2c3338';
			ctx.font = '700 12px sans-serif';
			ctx.fillText(truncateText(ctx, label, chart.left - 14), chart.left - 12, y - 7);
			ctx.fillStyle = '#646970';
			ctx.font = '600 11px sans-serif';
			ctx.fillText(formatValue(value, 'integer'), chart.left - 12, y + 9);

			ctx.fillStyle = '#f0f6fc';
			ctx.fillRect(chart.left, y - (barHeight / 2), chart.width, barHeight);
			ctx.fillStyle = color;
			ctx.fillRect(chart.left, y - (barHeight / 2), hasData ? Math.max(2, barWidth) : 0, barHeight);

			ctx.textAlign = 'left';
			ctx.fillStyle = '#50575e';
			ctx.font = '600 11px sans-serif';
			ctx.fillText(formatValue(stepRate, 'percent'), Math.min(chart.left + barWidth + 8, width - 66), y);

			tooltipItems.push({
				bounds: {
					left: 0,
					right: width,
					top: y - (rowHeight / 2),
					bottom: y + (rowHeight / 2)
				},
				label: label,
				value: value,
				stepRate: stepRate,
				visitRate: base > 0 ? (value / base) * 100 : 0,
				color: color
			});
		});

		bindTooltip(canvas, hasData ? tooltipItems : [], function (item) {
			return tooltipRows(item.label, [{
				label: strings.value || 'Value',
				color: item.color,
				value: formatValue(item.value, 'integer')
			}, {
				label: strings.stepConversion || 'Step conversion',
				color: item.color,
				value: formatValue(item.stepRate, 'percent')
			}, {
				label: strings.ofVisits || 'Of visits',
				color: item.color,
				value: formatValue(item.visitRate, 'percent')
			}]);
		});
	}

	function renderChart(canvas) {
		var $canvas = $(canvas);
		var $panel = $canvas.closest('.dn-burst-chart-panel');
		var $empty = $panel.find('.dn-burst-chart-empty');
		var payload = $canvas.attr('data-chart');
		var data;

		try {
			data = JSON.parse(payload || '{}');
		} catch (e) {
			data = {};
		}

		var type = $canvas.data('dn-chart') || data.type || 'line';
		var series = normalizeSeries(data);
		var hasData = hasMeaningfulChartData(series);
		var setup = setupCanvas(canvas, $canvas);
		var ctx = setup.ctx;
		var width = setup.width;
		var height = setup.height;

		$panel.toggleClass('is-empty', !hasData);
		$empty.prop('hidden', hasData);

		if (!series.length && type !== 'funnel') {
			bindTooltip(canvas, [], $.noop);
			return;
		}

		if (type === 'top-sales') {
			renderHorizontalBarChart(canvas, data, ctx, width, height, series, hasData);
			return;
		}

		if (type === 'funnel') {
			renderFunnelChart(canvas, data, ctx, width, height, hasData);
			return;
		}

		renderLineChart(canvas, data, type, ctx, width, height, series, hasData);
	}

	function initCharts() {
		$('.dn-burst-chart').each(function () {
			renderChart(this);
		});
	}

	$(document).on('click', '[data-dn-tab]', function (event) {
		event.preventDefault();
		loadTab($(this).attr('data-dn-tab'), true);
	});

	$(document).on('click', '[data-dn-date-toggle]', function () {
		$('[data-dn-date-popover]').prop('hidden', function (_, hidden) {
			return !hidden;
		});
	});

	$(document).on('click', '[data-dn-date-mode]', function () {
		var mode = $(this).data('dn-date-mode');

		$('[data-dn-date-mode]').removeClass('is-active');
		$(this).addClass('is-active');
		$('[data-dn-date-pane]').removeClass('is-active');
		$('[data-dn-date-pane="' + mode + '"]').addClass('is-active');

		if (mode === 'custom') {
			$('input[name="dn_period"][value="custom"]').prop('checked', true);
		}
	});

	$(document).on('submit', '[data-dn-date-popover]', function (event) {
		var $form = $(this);
		var custom = $form.find('[data-dn-date-pane="custom"]').hasClass('is-active');

		event.preventDefault();
		state.period = custom ? 'custom' : ($form.find('input[name="dn_period"]:checked').val() || 'month_to_date');
		state.compare = $form.find('input[name="dn_compare"]:checked').val() || 'previous_year';
		state.start = $form.find('input[name="dn_start"]').val() || '';
		state.end = $form.find('input[name="dn_end"]').val() || '';
		$form.prop('hidden', true);
		loadTab(getCurrentTab(), true);
	});

	$(document).on('change', '[data-dn-dimension] input[name="dn_dimension"]', function () {
		loadTable($(this).closest('.dn-burst-breakdown').find('[data-dn-table]'), { dimension: $(this).val(), orderby: '', order: 'desc', page: 1 });
	});

	$(document).on('click', '[data-dn-table] [data-dn-sort]', function (event) {
		event.preventDefault();
		loadTable($(this).closest('[data-dn-table]'), { orderby: $(this).attr('data-dn-sort'), order: $(this).attr('data-dn-order'), page: 1 });
	});

	$(document).on('click', '[data-dn-table] [data-dn-page]', function (event) {
		event.preventDefault();
		loadTable($(this).closest('[data-dn-table]'), { page: $(this).attr('data-dn-page') });
	});

	$(document).on('click keydown', 'tr[data-dn-drill-dimension]', function (event) {
		if (event.type === 'keydown' && event.key !== 'Enter') {
			return;
		}

		openDrilldown($(this).attr('data-dn-drill-dimension'), $(this).attr('data-dn-drill-value'));
	});

	$(document).on('click', '[data-dn-drawer-close]', function () {
		drawer().prop('hidden', true);
	});

	$(document).on('keydown', function (event) {
		if (event.key === 'Escape') {
			drawer().prop('hidden', true);
			$('[data-dn-filter-form], [data-dn-online-popover], [data-dn-date-popover]').prop('hidden', true);
		}
	});

	$(document).on('click', '[data-dn-apply-filter]', function () {
		drawer().prop('hidden', true);
		setFilter($(this).attr('data-dimension'), $(this).attr('data-value'));
	});

	$(document).on('click', '[data-dn-filter-remove]', function () {
		setFilter($(this).closest('[data-dn-filter-dim]').attr('data-dn-filter-dim'), '');
	});

	$(document).on('click', '[data-dn-filter-toggle]', function () {
		$('[data-dn-filter-form]').prop('hidden', function (_, hidden) {
			return !hidden;
		});
	});

	var suggestTimer;
	var suggestXhr;

	$(document).on('input change', '[data-dn-filter-value], [data-dn-filter-dimension]', function () {
		var $form = $(this).closest('[data-dn-filter-form]');

		window.clearTimeout(suggestTimer);
		suggestTimer = window.setTimeout(function () {
			if (suggestXhr && suggestXhr.abort) {
				suggestXhr.abort();
			}

			suggestXhr = post('dn_bfs_filter_values', {
				dimension: $form.find('[data-dn-filter-dimension]').val(),
				search: $form.find('[data-dn-filter-value]').val()
			}).done(function (response) {
				var $list = $('#dn-filter-values').empty();

				if (response && response.success) {
					$.each(response.data.values || [], function (_, value) {
						$list.append($('<option></option>').attr('value', value));
					});
				}
			});
		}, 250);
	});

	$(document).on('submit', '[data-dn-filter-form]', function (event) {
		var $form = $(this);
		var value = $.trim($form.find('[data-dn-filter-value]').val());

		event.preventDefault();

		if (value) {
			$form.prop('hidden', true);
			$form.find('[data-dn-filter-value]').val('');
			setFilter($form.find('[data-dn-filter-dimension]').val(), value);
		}
	});

	$(document).on('click', '[data-dn-cards-edit]', function () {
		var $grid = cardsGrid();

		$grid.find('[data-dn-card-visible]').each(function () {
			if (this.checked) {
				this.setAttribute('checked', 'checked');
			} else {
				this.removeAttribute('checked');
			}
		});

		cardsSnapshot = $grid.html();
		$grid.addClass('is-editing');
		toggleCardButtons(true);

		if ($.fn.sortable) {
			$grid.sortable({ items: '[data-dn-card]', tolerance: 'pointer' });
		}
	});

	$(document).on('change', '[data-dn-card-visible]', function () {
		$(this).closest('[data-dn-card]').toggleClass('is-hidden', !this.checked);
	});

	$(document).on('click', '[data-dn-cards-cancel]', function () {
		exitCardEdit();
		cardsGrid().html(cardsSnapshot);
	});

	$(document).on('click', '[data-dn-cards-save]', function () {
		var cards = [];

		cardsGrid().find('[data-dn-card]').each(function () {
			if ($(this).find('[data-dn-card-visible]').prop('checked')) {
				cards.push($(this).attr('data-dn-card'));
			}
		});

		post('dn_bfs_save_cards', { cards: cards, cards_sent: 1 }).done(function (response) {
			if (response && response.success) {
				exitCardEdit();
			} else {
				window.alert(errorMessage(response));
			}
		}).fail(function (xhr) {
			window.alert(errorMessage(xhr));
		});
	});

	$(document).on('click', '[data-dn-cards-reset]', function () {
		post('dn_bfs_save_cards', { reset: 1 }).done(function (response) {
			if (response && response.success) {
				exitCardEdit();
				loadTab(getCurrentTab(), false);
			} else {
				window.alert(errorMessage(response));
			}
		}).fail(function (xhr) {
			window.alert(errorMessage(xhr));
		});
	});

	$(document).on('click', '[data-dn-online]', function () {
		var $popover = $('[data-dn-online-popover]');

		$popover.prop('hidden', !$popover.prop('hidden'));
		$(this).attr('aria-expanded', String(!$popover.prop('hidden')));
	});

	$(document).on('click', '[data-dn-update-now]', function () {
		var $button = $(this);
		var $panel = $button.closest('[data-dn-status-panel]');
		var $message = $panel.find('[data-dn-status-message]');

		$button.prop('disabled', true);
		$message.text(strings.loading || 'Loading data…');

		post('dn_bfs_update_now').done(function (response) {
			if (!response || !response.success) {
				$message.text(errorMessage(response));
				return;
			}

			$panel.find('[data-dn-last-update]').text(response.data.lastUpdate || '');
			$panel.find('[data-dn-next-update]').text(response.data.nextUpdate || '');
			$message.text(response.data.message || strings.updated || '');
			loadTab(getCurrentTab(), false);
		}).fail(function (xhr) {
			$message.text(errorMessage(xhr));
		}).always(function () {
			$button.prop('disabled', false);
		});
	});

	$(document).on('input', '[data-dn-select-search]', function () {
		var query = $(this).val().toLowerCase();

		$(this).closest('[data-dn-searchable-select]').find('[data-dn-select-list] option').each(function () {
			$(this).prop('hidden', $(this).text().toLowerCase().indexOf(query) === -1);
		});
	});

	var resizeTimer;

	$(window).on('resize', function () {
		window.clearTimeout(resizeTimer);
		resizeTimer = window.setTimeout(initCharts, 120);
	});

	window.addEventListener('popstate', function () {
		var params = new URLSearchParams(window.location.search);

		state.period = params.get('dn_period') || config.period || 'month_to_date';
		state.compare = params.get('dn_compare') || config.compare || 'previous_year';
		state.start = params.get('dn_start') || '';
		state.end = params.get('dn_end') || '';
		state.filters = {};

		params.forEach(function (value, key) {
			var match = key.match(/^dn_filter\[(\w+)\]$/);

			if (match) {
				state.filters[match[1]] = value;
			}
		});

		renderFilterChips();
		var wantedTab = params.get('dn_tab') || 'overview';
		var knownTab = $('[data-dn-tab]').filter(function () {
			return String($(this).attr('data-dn-tab')) === wantedTab;
		}).length;

		loadTab(knownTab ? wantedTab : 'overview', false);
	});

	$(function () {
		initCharts();

		if ($('[data-dn-online]').length) {
			refreshOnline();
			window.setInterval(refreshOnline, 30000);
		}
	});
})(jQuery);
