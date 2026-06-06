(function ($) {
	'use strict';

	var config = window.dnBurstFunnelStats || {};

	function getDateState() {
		return {
			period: config.period || 'month_to_date',
			compare: config.compare || 'previous_year',
			start: config.start || '',
			end: config.end || ''
		};
	}

	function setDateState(state) {
		config.period = state.period || config.period || 'month_to_date';
		config.compare = state.compare || config.compare || 'previous_year';
		config.start = state.start || config.start || '';
		config.end = state.end || config.end || '';
	}

	function getCurrentTab() {
		var $active = $('[data-dn-tab].nav-tab-active');
		return $active.length ? $active.data('dn-tab') : (config.currentTab || 'overview');
	}

	function setLoading($region) {
		$region.addClass('is-loading');
		if (!$region.find('.dn-burst-loading').length) {
			$region.prepend('<div class="dn-burst-loading">' + (config.strings && config.strings.loading ? config.strings.loading : 'Loading data...') + '</div>');
		}
	}

	function clearLoading($region) {
		$region.removeClass('is-loading').find('.dn-burst-loading').remove();
	}

	function showError($region, message) {
		$region.html('<div class="notice notice-error"><p>' + $('<div>').text(message || config.strings.error).html() + '</p></div>');
	}

	function datePayload(extra) {
		return $.extend({}, getDateState(), extra || {});
	}

	function updateUrl(tab) {
		if (!window.history || !window.history.pushState) {
			return;
		}

		var state = getDateState();
		var url = new URL(window.location.href);
		url.searchParams.set('dn_period', state.period);
		url.searchParams.set('dn_compare', state.compare);
		url.searchParams.set('dn_tab', tab || getCurrentTab());

		if (state.period === 'custom') {
			url.searchParams.set('dn_start', state.start);
			url.searchParams.set('dn_end', state.end);
		} else {
			url.searchParams.delete('dn_start');
			url.searchParams.delete('dn_end');
		}

		window.history.pushState({ dnTab: tab || getCurrentTab() }, '', url.toString());
	}

	function updateDateButton(range) {
		if (!range) {
			return;
		}

		var title = range.current_label + ' (' + range.current_range_label + ')';
		var compare = range.compare !== 'none' && range.previous_range_label ? range.compare_label + ' (' + range.previous_range_label + ')' : '';

		$('.dn-burst-date-title').text(title);
		$('.dn-burst-date-compare').text(compare).toggle(!!compare);
	}

	function loadTab(tab, pushState) {
		var $content = $('[data-dn-tab-content]');

		if (!$content.length) {
			return;
		}

		setLoading($content);

		$.post(config.ajaxUrl, datePayload({
			action: 'dn_burst_funnel_stats_load_tab',
			nonce: config.nonce,
			tab: tab
		})).done(function (response) {
			if (!response || !response.success) {
				showError($content, response && response.data && response.data.message ? response.data.message : config.strings.error);
				return;
			}

			$content.html(response.data.html);
			$('[data-dn-tab]').removeClass('nav-tab-active');
			$('[data-dn-tab="' + tab + '"]').addClass('nav-tab-active');
			config.currentTab = tab;
			updateDateButton(response.data.range);
			initCharts();

			if (pushState) {
				updateUrl(tab);
			}
		}).fail(function () {
			showError($content, config.strings.error);
		}).always(function () {
			clearLoading($content);
		});
	}

	function loadUrlTable(overrides) {
		var $region = $('[data-dn-url-table-region]');
		var $checked = $('[data-dn-url-group] input[name="dn_group"]:checked');
		var data = $.extend({
			action: 'dn_burst_funnel_stats_url_tracking',
			nonce: config.nonce,
			group: $checked.length ? $checked.val() : 'campaign',
			orderby: 'visits',
			order: 'desc',
			paged: 1
		}, getDateState(), overrides || {});

		if (!$region.length) {
			return;
		}

		setLoading($region);

		$.post(config.ajaxUrl, data).done(function (response) {
			if (!response || !response.success) {
				showError($region, response && response.data && response.data.message ? response.data.message : config.strings.error);
				return;
			}

			$region.html(response.data.html);
		}).fail(function () {
			showError($region, config.strings.error);
		}).always(function () {
			clearLoading($region);
		});
	}

	function drawAxes(ctx, width, height) {
		ctx.strokeStyle = '#dcdcde';
		ctx.lineWidth = 1;
		ctx.beginPath();
		ctx.moveTo(42, 12);
		ctx.lineTo(42, height - 30);
		ctx.lineTo(width - 12, height - 30);
		ctx.stroke();
	}

	function drawLine(ctx, values, color, width, height, max) {
		var usableWidth = width - 58;
		var usableHeight = height - 48;
		var step = values.length > 1 ? usableWidth / (values.length - 1) : usableWidth;

		ctx.strokeStyle = color;
		ctx.lineWidth = 2;
		ctx.beginPath();

		values.forEach(function (value, index) {
			var x = 42 + (step * index);
			var y = (height - 30) - ((Number(value) || 0) / max * usableHeight);
			if (index === 0) {
				ctx.moveTo(x, y);
			} else {
				ctx.lineTo(x, y);
			}
		});

		ctx.stroke();
	}

	function drawBars(ctx, labels, values, width, height) {
		var max = Math.max.apply(null, values.concat([1]));
		var usableWidth = width - 58;
		var usableHeight = height - 48;
		var gap = 8;
		var barWidth = Math.max(8, (usableWidth / values.length) - gap);

		values.forEach(function (value, index) {
			var barHeight = (Number(value) || 0) / max * usableHeight;
			var x = 46 + (index * (barWidth + gap));
			var y = (height - 30) - barHeight;

			ctx.fillStyle = '#2271b1';
			ctx.fillRect(x, y, barWidth, barHeight);
			ctx.fillStyle = '#646970';
			ctx.font = '11px sans-serif';
			ctx.fillText(String(labels[index] || '').slice(0, 12), x, height - 12);
		});
	}

	function renderChart(canvas) {
		var $canvas = $(canvas);
		var payload = $canvas.attr('data-chart');
		var type = $canvas.data('dn-chart');
		var data;

		try {
			data = JSON.parse(payload || '{}');
		} catch (e) {
			data = {};
		}

		var ctx = canvas.getContext('2d');
		var width = $canvas.parent().width() || 600;
		var height = Number($canvas.attr('height')) || 220;
		canvas.width = width;
		canvas.height = height;
		ctx.clearRect(0, 0, width, height);
		drawAxes(ctx, width, height);

		if (type === 'sales') {
			var series = [data.netSales || [], data.profits || [], data.orders || []];
			var max = Math.max.apply(null, series.reduce(function (carry, row) { return carry.concat(row); }, [1]));
			drawLine(ctx, series[0], '#2271b1', width, height, max);
			drawLine(ctx, series[1], '#008a20', width, height, max);
			drawLine(ctx, series[2], '#b26200', width, height, max);
			return;
		}

		if (type === 'line') {
			drawLine(ctx, data.values || [], '#2271b1', width, height, Math.max.apply(null, (data.values || []).concat([1])));
			return;
		}

		drawBars(ctx, data.labels || [], data.values || [], width, height);
	}

	function initCharts() {
		$('.dn-burst-chart').each(function () {
			renderChart(this);
		});
	}

	$(document).on('click', '[data-dn-tab]', function (event) {
		event.preventDefault();
		loadTab($(this).data('dn-tab'), true);
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
		var customActive = $form.find('[data-dn-date-pane="custom"]').hasClass('is-active');
		var state = {
			period: customActive ? 'custom' : ($form.find('input[name="dn_period"]:checked').val() || 'month_to_date'),
			compare: $form.find('input[name="dn_compare"]:checked').val() || 'previous_year',
			start: $form.find('input[name="dn_start"]').val() || '',
			end: $form.find('input[name="dn_end"]').val() || ''
		};

		event.preventDefault();
		setDateState(state);
		$form.prop('hidden', true);
		loadTab(getCurrentTab(), true);
	});

	$(document).on('change', '[data-dn-url-group] input[name="dn_group"]', function () {
		loadUrlTable({
			group: $(this).val(),
			paged: 1
		});
	});

	$(document).on('click', '[data-dn-sort]', function (event) {
		var url = new URL(this.href, window.location.href);

		event.preventDefault();

		loadUrlTable({
			orderby: $(this).data('dn-sort'),
			order: url.searchParams.get('order') || 'desc',
			paged: 1
		});
	});

	$(document).on('click', '[data-dn-page]', function (event) {
		event.preventDefault();

		loadUrlTable({
			paged: $(this).data('dn-page')
		});
	});

	$(document).on('click', '[data-dn-update-now]', function () {
		var $button = $(this);
		var $panel = $button.closest('[data-dn-status-panel]');
		var $message = $panel.find('[data-dn-status-message]');

		$button.prop('disabled', true);
		$message.text(config.strings.loading);

		$.post(config.ajaxUrl, datePayload({
			action: 'dn_burst_funnel_stats_update_now',
			nonce: config.nonce
		})).done(function (response) {
			if (!response || !response.success) {
				$message.text(response && response.data && response.data.message ? response.data.message : config.strings.error);
				return;
			}

			$panel.find('[data-dn-last-update]').text(response.data.lastUpdate || '');
			$panel.find('[data-dn-next-update]').text(response.data.nextUpdate || '');
			$message.text(response.data.message || config.strings.updated);
			loadTab(getCurrentTab(), false);
		}).fail(function () {
			$message.text(config.strings.error);
		}).always(function () {
			$button.prop('disabled', false);
		});
	});

	$(document).on('input', '[data-dn-select-search]', function () {
		var query = $(this).val().toLowerCase();
		var $select = $(this).closest('[data-dn-searchable-select]').find('[data-dn-select-list]');

		$select.find('option').each(function () {
			var matches = $(this).text().toLowerCase().indexOf(query) !== -1;
			$(this).prop('hidden', !matches);
		});
	});

	$(window).on('resize', initCharts);

	window.addEventListener('popstate', function () {
		var params = new URLSearchParams(window.location.search);
		setDateState({
			period: params.get('dn_period') || config.period || 'month_to_date',
			compare: params.get('dn_compare') || config.compare || 'previous_year',
			start: params.get('dn_start') || config.start || '',
			end: params.get('dn_end') || config.end || ''
		});
		loadTab(params.get('dn_tab') || config.currentTab || 'overview', false);
	});

	$(initCharts);
})(jQuery);
