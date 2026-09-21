// RFQMS Admin Panel — RFQ activity chart, drawn with Apache ECharts (the page
// loads it from the CDN before this file). Renders a Pending vs Completed
// trend line from the JSON payload in the container's [data-chart] attribute
// (whose optional seriesLabels renames a series), and the table-view toggle:
// the table is the chart's accessible twin, swapped in for it on request — or
// left showing on its own if ECharts couldn't be loaded.
(function () {
    // Colors are validated for CVD-safe adjacent separation ("#3b53d1,#157347"
    // passes all checks against a white surface). Pending = blue, Completed =
    // green, matching the badge-soft-* colors used for RFQ status elsewhere.
    var SERIES = [
        { key: 'pending', label: 'Pending', color: '#3b53d1' },
        { key: 'completed', label: 'Completed', color: '#157347' },
    ];
    var INK = '#1f2430';
    var MUTED = '#6b7280';
    var HAIRLINE = '#e6e9f2';
    var MIN_CEILING = 4; // a quiet period still gets a readable scale

    // The charts on the page, and — so a live refresh (window.renderRfqCharts)
    // can put things back as they were — which series had been switched off.
    var drawn = [];
    var switchedOff = {};

    // '#3b53d1' at 18% → 'rgba(59, 83, 209, 0.18)', for the area under a line.
    function withAlpha(hex, alpha) {
        var n = parseInt(hex.slice(1), 16);
        return 'rgba(' + (n >> 16) + ', ' + ((n >> 8) & 255) + ', ' + (n & 255) + ', ' + alpha + ')';
    }

    // The page has a table twin for every chart; with no chart to show (no
    // data, or ECharts not loaded) the table is the fallback where there is
    // something to put in it.
    function twinOf(container) {
        var button = document.querySelector('.js-toggle-table[data-chart-target="' + container.id + '"]');
        return { button: button, table: button ? document.getElementById(button.dataset.target) : null };
    }

    function showEmpty(container) {
        var empty = document.createElement('p');
        empty.className = 'text-muted-soft rfq-chart-empty mb-0';
        empty.textContent = 'No RFQs were created in this period.';
        container.classList.add('is-plain');
        container.appendChild(empty);
    }

    function showTableOnly(container) {
        var twin = twinOf(container);
        container.classList.add('d-none');
        if (twin.table) twin.table.classList.remove('d-none');
        if (twin.button) twin.button.classList.add('d-none');
    }

    function renderChart(container) {
        if (typeof echarts !== 'undefined' && echarts.getInstanceByDom(container)) return; // already drawn

        var data;
        try {
            data = JSON.parse(container.dataset.chart);
        } catch (e) {
            return;
        }

        // A page can rename a series through data.seriesLabels — e.g. the
        // finished one is "Closed" rather than "Completed" for Business Development.
        var series = SERIES.map(function (definition) {
            var label = data.seriesLabels && data.seriesLabels[definition.key];
            return { key: definition.key, label: label || definition.label, color: definition.color, values: data[definition.key] || [] };
        });

        var labels = data.labels || [];
        var everyValue = series.reduce(function (all, item) { return all.concat(item.values); }, []);
        var total = everyValue.reduce(function (sum, value) { return sum + value; }, 0);

        if (!labels.length || total === 0) {
            showEmpty(container);
            return;
        }
        if (typeof echarts === 'undefined') {
            showTableOnly(container);
            return;
        }

        var yAxis = {
            type: 'value',
            min: 0,
            minInterval: 1, // whole RFQs only
            splitNumber: 4,
            axisLine: { show: false },
            axisTick: { show: false },
            axisLabel: { color: MUTED, fontSize: 11 },
            splitLine: { lineStyle: { color: HAIRLINE } },
        };
        if (Math.max.apply(null, everyValue) < MIN_CEILING) yAxis.max = MIN_CEILING;

        // A live refresh puts the chart back without playing its entrance again.
        var main = document.getElementById('live-main');
        var animate = !(main && main.classList.contains('is-live'));

        var chart = echarts.init(container, null, { renderer: 'svg' });
        chart.setOption({
            animation: animate,
            animationDuration: 700,
            aria: { enabled: true },
            textStyle: { fontFamily: getComputedStyle(document.body).fontFamily },
            color: series.map(function (item) { return item.color; }),
            grid: { top: 44, left: 4, right: 20, bottom: 4, containLabel: true },
            // Click a name to switch its line off and on.
            legend: {
                top: 0,
                left: 0,
                icon: 'roundRect',
                itemWidth: 14,
                itemHeight: 4,
                itemGap: 20,
                textStyle: { color: INK, fontSize: 12, fontWeight: 600 },
                inactiveColor: '#c3cbdc',
                selected: switchedOff[container.id] || {},
            },
            tooltip: {
                trigger: 'axis',
                confine: true,
                axisPointer: { type: 'line', lineStyle: { color: MUTED, width: 1 } },
                backgroundColor: INK,
                borderWidth: 0,
                padding: [8, 12],
                textStyle: { color: '#fff', fontSize: 12 },
                extraCssText: 'border-radius: 8px; box-shadow: 0 6px 18px rgba(16, 24, 40, 0.18);',
            },
            xAxis: {
                type: 'category',
                data: labels,
                boundaryGap: labels.length === 1, // a lone point sits in the middle
                axisLine: { lineStyle: { color: HAIRLINE } },
                axisTick: { show: false },
                axisLabel: { color: MUTED, fontSize: 11, margin: 12, hideOverlap: true },
            },
            yAxis: yAxis,
            series: series.map(function (item) {
                return {
                    name: item.label,
                    type: 'line', // straight between days: a count has no in-between value
                    data: item.values,
                    symbol: 'circle',
                    symbolSize: 7,
                    showSymbol: labels.length <= 15, // over a long range only the point you're on
                    lineStyle: { width: 2.5 },
                    itemStyle: { color: item.color, borderColor: '#fff', borderWidth: 2 },
                    areaStyle: {
                        color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                            { offset: 0, color: withAlpha(item.color, 0.18) },
                            { offset: 1, color: withAlpha(item.color, 0) },
                        ]),
                    },
                    emphasis: { focus: 'series' },
                };
            }),
        });

        chart.on('legendselectchanged', function (event) {
            switchedOff[container.id] = event.selected;
        });

        // Follows the card as the window changes, and as the table twin is
        // swapped out (the container has no size while it's hidden).
        var observer = new ResizeObserver(function () {
            chart.resize();
        });
        observer.observe(container);

        drawn.push({ chart: chart, observer: observer });
    }

    // Also run again (window.renderRfqCharts) when live.js swaps a fresh page
    // body in — the charts in it are new, empty elements, and the ones they
    // replace are put away.
    function init() {
        drawn = drawn.filter(function (entry) {
            if (entry.chart.getDom().isConnected) return true;

            entry.observer.disconnect();
            entry.chart.dispose();
            return false;
        });

        document.querySelectorAll('.rfq-chart[data-chart]').forEach(renderChart);

        // Table-view toggle — the accessibility twin of the chart above it.
        // Swaps between the two rather than showing both at once.
        document.querySelectorAll('.js-toggle-table').forEach(function (button) {
            if (button.dataset.bound) return;
            button.dataset.bound = '1';

            button.addEventListener('click', function () {
                var table = document.getElementById(button.dataset.target);
                var chart = document.querySelector(button.dataset.chartTarget ? '#' + button.dataset.chartTarget : null);
                if (!table || !chart) return;

                var showingTable = table.classList.contains('d-none');
                table.classList.toggle('d-none', !showingTable);
                chart.classList.toggle('d-none', showingTable);
                button.innerHTML = showingTable
                    ? '<i class="bi bi-graph-up"></i> View as chart'
                    : '<i class="bi bi-table"></i> View as table';
            });
        });
    }

    window.renderRfqCharts = init;
    document.addEventListener('DOMContentLoaded', init);
})();
