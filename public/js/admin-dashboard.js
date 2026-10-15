// RFQMS Admin Panel — the charts on Admin's dashboard (dashboard/admin.blade.php),
// drawn with Apache ECharts like the RFQ activity chart (rfq-chart.js, which
// also binds their "View as table" toggles). Each reads its JSON from
// [data-dash-chart]:
//   "stacked" — tasks finished each day, stacked by the role that finished
//               them (Sourcing, Data Entry, GM Assistant) — or the General
//               Manager's decisions, approved and sent back;
//   "people"  — each person's workload: what's in hand now beside what they
//               finished lately, as paired horizontal bars;
//   "metric"  — one measure span by span as columns, the card's switch
//               (.js-chart-metric) picking which (Data Entry's parts sent or
//               entries made — never both on one axis);
//   "share"   — a percentage per row, 0–100, as horizontal bars with the
//               figure behind it at the tip (Data Entry within target, by
//               priority). One series each: Data Entry's own hue.
// Colors are admin.css's tokens for the theme in use (validated as a set for
// colorblind separation, light and dark). With no ECharts the table twin
// shows instead; with nothing to plot, a line saying so. Redrawn after a live
// update (live.js fires rfqms:live-updated once it swaps the page in).
(function () {
    var theme = getComputedStyle(document.documentElement);
    function token(name, fallback) {
        return theme.getPropertyValue(name).trim() || fallback;
    }

    var COLORS = {
        sourcing: token('--rfq-role-sourcing', '#3b53d1'),
        'data-entry': token('--rfq-role-data-entry', '#e2620f'),
        'gm-assistant': token('--rfq-role-gm-assistant', '#13a374'),
        'in-hand': token('--rfq-role-sourcing', '#3b53d1'),
        done: token('--rfq-role-gm-assistant', '#13a374'),
        parts: token('--rfq-role-data-entry', '#e2620f'),
        entries: token('--rfq-role-data-entry', '#e2620f'),
        'on-time': token('--rfq-role-data-entry', '#e2620f'),
        approved: token('--rfq-decision-approved', '#4f6df5'),
        returned: token('--rfq-decision-returned', '#d97706'),
    };
    var INK = token('--rfq-text', '#1f2430');
    var MUTED = token('--rfq-text-muted', '#6b7280');
    var HAIRLINE = token('--rfq-border', '#e6e9f2');
    var SURFACE = token('--rfq-surface', '#ffffff');
    var MIN_CEILING = 4; // a quiet stretch still gets a readable scale

    var drawn = [];

    function showTableOnly(container) {
        var button = document.querySelector('.js-toggle-table[data-chart-target="' + container.id + '"]');
        var table = button ? document.getElementById(button.dataset.target) : null;
        container.classList.add('d-none');
        if (table) table.classList.remove('d-none');
        if (button) button.classList.add('d-none');
    }

    function showEmpty(container, text) {
        var empty = document.createElement('p');
        empty.className = 'text-muted-soft small mb-0';
        empty.textContent = text;
        container.style.height = 'auto';
        container.appendChild(empty);
    }

    // The settings both charts share: the legend above, the dark tooltip,
    // recessive hairline axes.
    function common(series, animate) {
        return {
            animation: animate,
            animationDuration: 600,
            aria: { enabled: true },
            textStyle: { fontFamily: getComputedStyle(document.body).fontFamily },
            color: series.map(function (item) { return COLORS[item.key] || INK; }),
            legend: {
                top: 0,
                left: 0,
                icon: 'roundRect',
                itemWidth: 12,
                itemHeight: 12,
                itemGap: 18,
                textStyle: { color: INK, fontSize: 12, fontWeight: 600 },
                inactiveColor: token('--rfq-tree-line-soft', '#c3cbdc'),
            },
            tooltip: {
                trigger: 'axis',
                confine: true,
                axisPointer: { type: 'shadow', shadowStyle: { color: 'rgba(127, 127, 127, 0.08)' } },
                backgroundColor: INK,
                borderWidth: 0,
                padding: [8, 12],
                textStyle: { color: SURFACE, fontSize: 12 },
                extraCssText: 'border-radius: 8px; box-shadow: 0 6px 18px rgba(16, 24, 40, 0.18);',
            },
        };
    }

    function valueAxis(values) {
        var axis = {
            type: 'value',
            min: 0,
            minInterval: 1, // whole tasks only
            splitNumber: 4,
            axisLine: { show: false },
            axisTick: { show: false },
            axisLabel: { color: MUTED, fontSize: 11 },
            splitLine: { lineStyle: { color: HAIRLINE } },
        };
        if (Math.max.apply(null, values.concat([0])) < MIN_CEILING) axis.max = MIN_CEILING;
        return axis;
    }

    // Tasks finished per day, a column per day stacked by role. A 1px surface
    // border on each segment leaves the 2px gap between them.
    function stackedOption(data, animate) {
        var totals = data.labels.map(function (label, i) {
            return data.series.reduce(function (sum, item) { return sum + (item.values[i] || 0); }, 0);
        });

        var option = common(data.series, animate);
        option.grid = { top: 40, left: 4, right: 12, bottom: 4, containLabel: true };
        option.tooltip.formatter = function (points) {
            var i = points[0].dataIndex;
            var rows = points.map(function (point) {
                return point.marker + point.seriesName + ': <b>' + point.value + '</b>';
            });
            return '<b>' + (data.dates[i] || data.labels[i]) + '</b><br>' + rows.join('<br>') + '<br>Total: <b>' + totals[i] + '</b>';
        };
        option.xAxis = {
            type: 'category',
            data: data.labels,
            axisLine: { lineStyle: { color: HAIRLINE } },
            axisTick: { show: false },
            axisLabel: { color: MUTED, fontSize: 11, margin: 10, hideOverlap: true },
        };
        option.yAxis = valueAxis(totals);
        option.series = data.series.map(function (item) {
            return {
                name: item.label,
                type: 'bar',
                stack: 'finished',
                data: item.values,
                barMaxWidth: 24,
                itemStyle: { borderColor: SURFACE, borderWidth: 1, borderRadius: [3, 3, 0, 0] },
                emphasis: { focus: 'series' },
            };
        });
        return option;
    }

    // Each person's two bars side by side: in hand now, finished lately —
    // the count at each bar's tip.
    function peopleOption(data, animate) {
        var everyValue = data.series.reduce(function (all, item) { return all.concat(item.values); }, []);

        var option = common(data.series, animate);
        option.grid = { top: 40, left: 4, right: 28, bottom: 4, containLabel: true };
        option.yAxis = {
            type: 'category',
            data: data.labels,
            inverse: true, // first person on top, as the team table has them
            axisLine: { lineStyle: { color: HAIRLINE } },
            axisTick: { show: false },
            axisLabel: { color: INK, fontSize: 12, width: 160, overflow: 'truncate' },
        };
        option.xAxis = valueAxis(everyValue);
        option.series = data.series.map(function (item) {
            return {
                name: item.label,
                type: 'bar',
                data: item.values,
                barMaxWidth: 12,
                barGap: '30%',
                itemStyle: { borderRadius: [0, 4, 4, 0] },
                label: { show: true, position: 'right', color: MUTED, fontSize: 11, formatter: function (point) { return point.value > 0 ? point.value : ''; } },
                emphasis: { focus: 'series' },
            };
        });
        return option;
    }

    // One measure (series[index]) span by span, as columns — its name in
    // the axis title rather than a legend, since there's only the one.
    function metricOption(data, index, animate) {
        var item = data.series[index] || data.series[0];

        var option = common([item], animate);
        option.legend = { show: false };
        option.grid = { top: 28, left: 4, right: 12, bottom: 4, containLabel: true };
        option.tooltip.formatter = function (points) {
            var i = points[0].dataIndex;
            var rows = data.series.map(function (series) {
                return series.label + ': <b>' + series.values[i] + '</b>';
            });
            return '<b>' + (data.dates[i] || data.labels[i]) + '</b><br>' + rows.join('<br>');
        };
        option.xAxis = {
            type: 'category',
            data: data.labels,
            axisLine: { lineStyle: { color: HAIRLINE } },
            axisTick: { show: false },
            axisLabel: { color: MUTED, fontSize: 11, margin: 10, hideOverlap: true },
        };
        option.yAxis = valueAxis(item.values);
        option.yAxis.name = item.label;
        option.yAxis.nameTextStyle = { color: MUTED, fontSize: 11, align: 'left' };
        option.series = [{
            name: item.label,
            type: 'bar',
            data: item.values,
            barMaxWidth: 24,
            itemStyle: { borderRadius: [4, 4, 0, 0] },
        }];
        return option;
    }

    // A percentage per row, 0–100: the bar, and at its tip what it's of
    // (data.display); the tooltip adds data.details.
    function shareOption(data, animate) {
        var item = data.series[0];

        var option = common([item], animate);
        option.legend = { show: false };
        option.grid = { top: 8, left: 4, right: 96, bottom: 4, containLabel: true };
        option.tooltip.trigger = 'item';
        option.tooltip.formatter = function (point) {
            var i = point.dataIndex;
            return '<b>' + data.labels[i] + '</b><br>' + item.label + ': <b>' + data.display[i] + '</b>' + (data.details && data.details[i] ? '<br>' + data.details[i] : '');
        };
        option.yAxis = {
            type: 'category',
            data: data.labels,
            inverse: true,
            axisLine: { lineStyle: { color: HAIRLINE } },
            axisTick: { show: false },
            axisLabel: { color: INK, fontSize: 12 },
        };
        option.xAxis = {
            type: 'value',
            min: 0,
            max: 100,
            interval: 25,
            axisLine: { show: false },
            axisTick: { show: false },
            axisLabel: { color: MUTED, fontSize: 11, formatter: '{value}%' },
            splitLine: { lineStyle: { color: HAIRLINE } },
        };
        option.series = [{
            name: item.label,
            type: 'bar',
            data: item.values,
            barMaxWidth: 14,
            itemStyle: { borderRadius: [0, 4, 4, 0] },
            label: { show: true, position: 'right', color: MUTED, fontSize: 11, formatter: function (point) { return data.display[point.dataIndex] || ''; } },
        }];
        return option;
    }

    function optionFor(data, animate, container) {
        switch (data.type) {
            case 'people':
                return peopleOption(data, animate);
            case 'metric':
                return metricOption(data, Number(container.dataset.metric || 0), animate);
            case 'share':
                return shareOption(data, animate);
            default:
                return stackedOption(data, animate);
        }
    }

    function render(container) {
        if (typeof echarts !== 'undefined' && echarts.getInstanceByDom(container)) return; // already drawn
        if (container.dataset.drawn) return; // already said there's nothing

        var data;
        try {
            data = JSON.parse(container.dataset.dashChart);
        } catch (e) {
            return;
        }

        // A chart of shares has nothing to show when nothing was counted, even
        // if every share is 0%.
        var total = data.total !== undefined ? data.total : data.series.reduce(function (sum, item) {
            return sum + item.values.reduce(function (a, b) { return a + b; }, 0);
        }, 0);
        if (!data.labels.length || total === 0) {
            container.dataset.drawn = '1';
            showEmpty(container, data.empty || 'Nothing to show yet.');
            return;
        }
        if (typeof echarts === 'undefined') {
            showTableOnly(container);
            return;
        }

        // A live refresh puts the chart back without playing its entrance again.
        var main = document.getElementById('live-main');
        var animate = !(main && main.classList.contains('is-live'));

        var chart = echarts.init(container, null, { renderer: 'svg' });
        chart.setOption(optionFor(data, animate, container));

        // Follows the card as the window changes, and as the table twin is
        // swapped out (the container has no size while it's hidden).
        var observer = new ResizeObserver(function () { chart.resize(); });
        observer.observe(container);
        drawn.push({ chart: chart, observer: observer });
    }

    function init() {
        drawn = drawn.filter(function (entry) {
            if (entry.chart.getDom().isConnected) return true;
            entry.observer.disconnect();
            entry.chart.dispose();
            return false;
        });

        document.querySelectorAll('.dash-chart[data-dash-chart]').forEach(render);
    }

    // A "metric" chart's switch: the measure picked, redrawn in place.
    document.addEventListener('change', function (event) {
        var input = event.target;
        if (!input.classList || !input.classList.contains('js-chart-metric')) return;

        var container = document.getElementById(input.dataset.chart);
        if (!container) return;
        container.dataset.metric = input.value;

        var chart = typeof echarts !== 'undefined' ? echarts.getInstanceByDom(container) : null;
        if (chart) chart.setOption(metricOption(JSON.parse(container.dataset.dashChart), Number(input.value), true), true);
    });

    document.addEventListener('DOMContentLoaded', init);
    document.addEventListener('rfqms:live-updated', init);
})();
