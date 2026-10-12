// RFQMS Admin Panel — the Critical monitor (layouts/_critical_modal.blade.php):
// fetches what's critical into the window when it opens, again every half
// minute while it's open (the server says how often: data-refresh), and on
// Refresh; counts the headline numbers up as they first appear; draws the
// charts with Apache ECharts — loaded here if the page hasn't already —
// animating in on opening; and keeps the top bar button's count in step.
// Motion is left out for those who've asked for less.
(function () {
    var modal = document.getElementById('criticalMonitorModal');
    var button = document.getElementById('criticalMonitorBtn');
    if (!modal || !button) return;

    var body = document.getElementById('criticalMonitorBody');
    var updated = document.getElementById('criticalUpdated');
    var refreshButton = document.getElementById('criticalRefresh');
    var lessMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var ECHARTS = 'https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js';

    var timer = null;
    var charts = [];
    var loadingCharts = null;
    var busy = false;

    var theme = getComputedStyle(document.documentElement);
    function token(name, fallback) {
        return theme.getPropertyValue(name).trim() || fallback;
    }
    var TONES = {
        danger: token('--rfq-danger', '#e5484d'),
        warning: '#d97706',
        success: token('--rfq-success', '#1a9c6a'),
    };
    var INK = token('--rfq-text', '#1f2430');
    var MUTED = token('--rfq-text-muted', '#6b7280');
    var HAIRLINE = token('--rfq-border', '#e6e9f2');
    var SURFACE = token('--rfq-surface', '#ffffff');
    var TRACK = token('--rfq-bg', '#f4f6fb');

    // ECharts, once — the dashboards already have it; elsewhere it's fetched
    // the first time the monitor opens. Resolves false if it can't be had.
    function withECharts() {
        if (window.echarts) return Promise.resolve(true);
        if (loadingCharts) return loadingCharts;

        loadingCharts = new Promise(function (resolve) {
            var script = document.createElement('script');
            script.src = ECHARTS;
            script.onload = function () { resolve(true); };
            script.onerror = function () { loadingCharts = null; resolve(false); };
            document.head.appendChild(script);
        });
        return loadingCharts;
    }

    function disposeCharts() {
        charts.forEach(function (entry) {
            entry.observer.disconnect();
            entry.chart.dispose();
        });
        charts = [];
    }

    function tooltip() {
        return {
            confine: true,
            backgroundColor: INK,
            borderWidth: 0,
            padding: [8, 12],
            textStyle: { color: SURFACE, fontSize: 12 },
            extraCssText: 'border-radius: 8px; box-shadow: 0 6px 18px rgba(16, 24, 40, 0.18);',
        };
    }

    // The share of timed tasks on time, as an arc that fills to it — green
    // from 80%, amber from 50%, red below.
    function gaugeOption(data, animate) {
        var value = data.value;
        var color = value >= 80 ? TONES.success : (value >= 50 ? TONES.warning : TONES.danger);

        return {
            animation: animate,
            animationDuration: 1200,
            animationEasing: 'cubicOut',
            series: [{
                type: 'gauge',
                startAngle: 210,
                endAngle: -30,
                min: 0,
                max: 100,
                radius: '95%',
                center: ['50%', '58%'],
                progress: { show: true, width: 16, roundCap: true, itemStyle: { color: color } },
                axisLine: { roundCap: true, lineStyle: { width: 16, color: [[1, TRACK]] } },
                pointer: { show: false },
                axisTick: { show: false },
                splitLine: { show: false },
                axisLabel: { show: false },
                anchor: { show: false },
                title: { show: true, offsetCenter: [0, '38%'], color: MUTED, fontSize: 12 },
                detail: {
                    valueAnimation: true,
                    offsetCenter: [0, '0%'],
                    fontSize: 34,
                    fontWeight: 700,
                    color: INK,
                    formatter: '{value}%',
                },
                data: [{ value: value, name: data.caption || 'on time' }],
            }],
        };
    }

    // One bar per row — the most overdue, or each person's idle time —
    // growing from the left, its figure at the tip, and for idle time the
    // alert's line across them.
    function barsOption(data, animate) {
        var color = TONES[data.tone] || TONES.danger;
        var series = {
            type: 'bar',
            data: data.values.map(function (value) {
                return { value: value, itemStyle: { color: data.threshold && value < data.threshold ? token('--rfq-tree-line-soft', '#c9d0e0') : color } };
            }),
            barMaxWidth: 14,
            itemStyle: { borderRadius: [0, 4, 4, 0] },
            label: {
                show: true,
                position: 'right',
                color: MUTED,
                fontSize: 11,
                formatter: function (point) { return data.display[point.dataIndex] || ''; },
            },
            animationDelay: function (index) { return index * 90; },
        };

        if (data.threshold) {
            series.markLine = {
                symbol: 'none',
                silent: true,
                lineStyle: { color: TONES.danger, type: 'solid', width: 1.5 },
                // At the line's top: the rows run downwards (inverse), so that's its start.
                label: { formatter: data.thresholdLabel, color: TONES.danger, fontSize: 11, position: 'start', distance: 4 },
                data: [{ xAxis: data.threshold }],
            };
        }

        var max = Math.max.apply(null, data.values.concat([data.threshold || 0, 1]));

        return {
            animation: animate,
            animationDuration: 900,
            animationEasing: 'cubicOut',
            aria: { enabled: true },
            textStyle: { fontFamily: getComputedStyle(document.body).fontFamily },
            grid: { top: data.threshold ? 26 : 12, left: 4, right: 70, bottom: 26, containLabel: true },
            tooltip: Object.assign(tooltip(), {
                trigger: 'item',
                formatter: function (point) { return data.labels[point.dataIndex] + '<br><b>' + (data.display[point.dataIndex] || point.value) + '</b>'; },
            }),
            xAxis: {
                type: 'value',
                min: 0,
                max: Math.ceil(max * 1.15),
                name: data.unit,
                nameLocation: 'middle',
                nameGap: 22,
                nameTextStyle: { color: MUTED, fontSize: 11 },
                axisLine: { show: false },
                axisTick: { show: false },
                axisLabel: { color: MUTED, fontSize: 11 },
                splitLine: { lineStyle: { color: HAIRLINE } },
            },
            yAxis: {
                type: 'category',
                data: data.labels,
                inverse: true,
                axisLine: { lineStyle: { color: HAIRLINE } },
                axisTick: { show: false },
                axisLabel: { color: INK, fontSize: 11, width: 140, overflow: 'truncate' },
            },
            series: [series],
        };
    }

    function showNote(container, text) {
        var note = document.createElement('p');
        note.className = 'critical-panel-note critical-chart-empty';
        note.textContent = text;
        container.style.height = 'auto';
        container.style.minHeight = '0';
        container.appendChild(note);
    }

    function drawCharts(animate) {
        body.querySelectorAll('.critical-chart[data-critical-chart]').forEach(function (container) {
            var data;
            try {
                data = JSON.parse(container.dataset.criticalChart);
            } catch (e) {
                return;
            }

            // Nothing to draw: no gauge value, nobody to list, or — for bars —
            // every one of them at nothing (all clear).
            if (data.type === 'gauge' ? data.value === null || data.value === undefined : !data.values || !data.values.length) {
                showNote(container, data.empty || 'Nothing to show.');
                return;
            }
            if (data.type === 'bars' && data.values.every(function (value) { return value === 0; })) {
                showNote(container, data.allClear || data.empty || 'Nothing to show.');
                return;
            }
            if (!window.echarts) {
                showNote(container, 'The chart couldn’t load — the numbers are in the lists below.');
                return;
            }

            var chart = echarts.init(container, null, { renderer: 'svg' });
            chart.setOption(data.type === 'gauge' ? gaugeOption(data, animate) : barsOption(data, animate));
            var observer = new ResizeObserver(function () { chart.resize(); });
            observer.observe(container);
            charts.push({ chart: chart, observer: observer });
        });
    }

    // The headline numbers count up from nothing as the window first fills.
    function countUp() {
        if (lessMotion) return;

        body.querySelectorAll('[data-count-up]').forEach(function (element) {
            var target = Number(element.dataset.countUp) || 0;
            if (target === 0) return;

            var started = null;
            var duration = 900;
            function step(now) {
                if (started === null) started = now;
                var progress = Math.min(1, (now - started) / duration);
                var eased = 1 - Math.pow(1 - progress, 3);
                element.textContent = Math.round(target * eased);
                if (progress < 1) window.requestAnimationFrame(step);
            }
            element.textContent = '0';
            window.requestAnimationFrame(step);
        });
    }

    // The top bar button shows the count the monitor just worked out.
    function syncButton(count) {
        var badge = button.querySelector('.critical-btn-count');
        button.classList.toggle('is-alert', count > 0);

        if (count > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'critical-btn-count';
                button.appendChild(badge);
            }
            badge.textContent = count;
        } else if (badge) {
            badge.remove();
        }
    }

    function load(first) {
        if (busy) return;
        busy = true;
        refreshButton.disabled = true;
        refreshButton.classList.add('is-spinning');

        fetch(button.dataset.url, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) throw new Error('critical monitor: ' + response.status);
                return response.text();
            })
            .then(function (html) {
                return withECharts().then(function () { return html; });
            })
            .then(function (html) {
                disposeCharts();
                body.innerHTML = html;

                var monitor = body.querySelector('.critical-monitor');
                if (monitor) {
                    updated.textContent = 'Updated ' + monitor.dataset.updated + ' · refreshes every ' + monitor.dataset.refresh + 's';
                    syncButton(Number(monitor.dataset.criticalCount) || 0);
                    restartTimer(Number(monitor.dataset.refresh) || 30);
                }

                if (first) countUp();
                drawCharts(first && !lessMotion);
            })
            .catch(function () {
                updated.textContent = 'Couldn’t refresh — it’ll try again shortly.';
                if (first) {
                    body.innerHTML = '<p class="critical-empty"><i class="bi bi-wifi-off"></i> The monitor couldn’t load. Check the connection and press Refresh.</p>';
                }
            })
            .then(function () {
                busy = false;
                refreshButton.disabled = false;
                refreshButton.classList.remove('is-spinning');
            });
    }

    function restartTimer(seconds) {
        window.clearInterval(timer);
        timer = window.setInterval(function () {
            if (!document.hidden) load(false);
        }, seconds * 1000);
    }

    modal.addEventListener('shown.bs.modal', function () { load(true); });
    modal.addEventListener('hidden.bs.modal', function () {
        window.clearInterval(timer);
        timer = null;
        disposeCharts();
    });
    refreshButton.addEventListener('click', function () { load(false); });
})();
