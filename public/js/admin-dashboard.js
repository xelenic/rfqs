// RFQMS Admin Panel — the charts on Admin's dashboard (dashboard/admin.blade.php),
// drawn with Apache ECharts like the RFQ activity chart (rfq-chart.js, which
// also binds their "View as table" toggles). Each reads its JSON from
// [data-dash-chart]:
//   "stacked" — tasks finished each day, stacked by the role that finished
//               them (Sourcing, Data Entry, GM Assistant);
//   "people"  — each person's workload: what's in hand now beside what they
//               finished lately, as paired horizontal bars.
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

    function render(container) {
        if (typeof echarts !== 'undefined' && echarts.getInstanceByDom(container)) return; // already drawn
        if (container.dataset.drawn) return; // already said there's nothing

        var data;
        try {
            data = JSON.parse(container.dataset.dashChart);
        } catch (e) {
            return;
        }

        var total = data.series.reduce(function (sum, item) {
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
        chart.setOption(data.type === 'people' ? peopleOption(data, animate) : stackedOption(data, animate));

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

    document.addEventListener('DOMContentLoaded', init);
    document.addEventListener('rfqms:live-updated', init);
})();
