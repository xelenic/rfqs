// RFQMS Admin Panel — RFQ activity line chart. Plain JS + inline SVG, no
// charting library, no build step. Renders a Pending vs Completed trend line
// from the JSON payload in the container's [data-chart] attribute.
(function () {
    var SVG_NS = 'http://www.w3.org/2000/svg';
    var SERIES = [
        { key: 'pending', label: 'Pending', color: '#3b53d1' },
        { key: 'completed', label: 'Completed', color: '#157347' },
    ];

    function el(name, attrs, parent) {
        var node = document.createElementNS(SVG_NS, name);
        for (var key in attrs) {
            node.setAttribute(key, attrs[key]);
        }
        if (parent) parent.appendChild(node);
        return node;
    }

    // Round a max value up to a "clean" axis ceiling (1, 2, 5 × 10^n).
    function niceCeiling(value) {
        if (value <= 4) return 4;
        var magnitude = Math.pow(10, Math.floor(Math.log10(value)));
        var normalized = value / magnitude;
        var step = normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10;
        return step * magnitude;
    }

    // Thin a label array down to at most `max` evenly-spaced indices,
    // always keeping the first and last.
    function pickLabelIndices(count, max) {
        if (count <= max) {
            var all = [];
            for (var i = 0; i < count; i++) all.push(i);
            return all;
        }
        var picks = [];
        var step = (count - 1) / (max - 1);
        for (var j = 0; j < max; j++) picks.push(Math.round(j * step));
        return Array.from(new Set(picks));
    }

    function renderChart(container) {
        var data;
        try {
            data = JSON.parse(container.dataset.chart);
        } catch (e) {
            return;
        }

        var labels = data.labels || [];
        var total = (data.pending || []).reduce(function (a, b) { return a + b; }, 0) +
            (data.completed || []).reduce(function (a, b) { return a + b; }, 0);

        if (!labels.length || total === 0) {
            var empty = document.createElement('p');
            empty.className = 'text-muted-soft rfq-chart-empty mb-0';
            empty.textContent = 'No RFQs were created in this period.';
            container.appendChild(empty);
            return;
        }

        var width = 760;
        var height = 260;
        var padding = { top: 16, right: 16, bottom: 30, left: 34 };
        var plotWidth = width - padding.left - padding.right;
        var plotHeight = height - padding.top - padding.bottom;

        var maxValue = niceCeiling(Math.max.apply(null, data.pending.concat(data.completed)));
        var tickCount = 4;

        var x = function (i) {
            return labels.length === 1
                ? padding.left + plotWidth / 2
                : padding.left + (i / (labels.length - 1)) * plotWidth;
        };
        var y = function (v) {
            return padding.top + plotHeight - (v / maxValue) * plotHeight;
        };

        var wrap = document.createElement('div');
        wrap.className = 'rfq-chart-wrap';

        var svg = el('svg', {
            viewBox: '0 0 ' + width + ' ' + height,
            role: 'img',
            'aria-label': 'RFQs created over time, pending versus completed',
        });
        svg.classList.add('rfq-chart-svg');

        // Gridlines + y-axis ticks.
        for (var t = 0; t <= tickCount; t++) {
            var value = Math.round((maxValue / tickCount) * t);
            var gy = y(value);
            el('line', {
                x1: padding.left, x2: width - padding.right, y1: gy, y2: gy,
                class: 'rfq-chart-gridline',
            }, svg);
            var tick = el('text', {
                x: padding.left - 8, y: gy, class: 'rfq-chart-tick', 'text-anchor': 'end',
                'dominant-baseline': 'middle',
            }, svg);
            tick.textContent = value.toLocaleString();
        }

        // X-axis labels (thinned so they never collide).
        var labelIndices = pickLabelIndices(labels.length, 7);
        labelIndices.forEach(function (i) {
            var lbl = el('text', {
                x: x(i), y: height - 8, class: 'rfq-chart-tick', 'text-anchor': 'middle',
            }, svg);
            lbl.textContent = labels[i];
        });

        // Series lines + end markers.
        var seriesEls = {};
        var lastIndex = labels.length - 1;
        SERIES.forEach(function (series) {
            var values = data[series.key] || [];
            var points = values.map(function (v, i) { return x(i) + ',' + y(v); }).join(' ');

            var line = el('polyline', {
                points: points, class: 'rfq-chart-line',
                style: 'stroke:' + series.color,
                'data-series': series.key,
            }, svg);

            var dot = el('circle', {
                cx: x(lastIndex), cy: y(values[lastIndex]), r: 4,
                class: 'rfq-chart-enddot', style: 'fill:' + series.color,
            }, svg);

            seriesEls[series.key] = { line: line, dot: dot, values: values };
        });

        // Direct end-labels — placed after both lines are drawn so converging
        // endpoints (e.g. both series at 0) can be pushed apart instead of
        // left to collide (see dataviz skill: "when end-labels collide,
        // don't stack them").
        var endYs = SERIES.map(function (series) {
            return y(seriesEls[series.key].values[lastIndex]);
        });
        var minGap = 13;
        if (Math.abs(endYs[0] - endYs[1]) < minGap * 2) {
            var mid = (endYs[0] + endYs[1]) / 2;
            endYs = endYs[0] <= endYs[1] ? [mid - minGap, mid + minGap] : [mid + minGap, mid - minGap];
        }
        SERIES.forEach(function (series, sIndex) {
            var values = seriesEls[series.key].values;
            var endLabel = el('text', {
                x: x(lastIndex) - 6, y: endYs[sIndex], class: 'rfq-chart-endlabel', 'text-anchor': 'end',
            }, svg);
            endLabel.textContent = series.label + ': ' + values[lastIndex];
        });

        // Crosshair + shared tooltip.
        var crosshair = el('line', {
            x1: 0, x2: 0, y1: padding.top, y2: height - padding.bottom,
            class: 'rfq-chart-crosshair',
        }, svg);

        var overlay = el('rect', {
            x: padding.left, y: padding.top, width: plotWidth, height: plotHeight,
            class: 'rfq-chart-overlay',
        }, svg);

        var tooltip = document.createElement('div');
        tooltip.className = 'rfq-chart-tooltip';
        tooltip.hidden = true;

        function showAt(index) {
            var px = x(index);
            crosshair.setAttribute('x1', px);
            crosshair.setAttribute('x2', px);
            crosshair.style.opacity = 1;

            tooltip.innerHTML = '';
            var heading = document.createElement('div');
            heading.className = 'rfq-chart-tooltip-heading';
            heading.textContent = labels[index];
            tooltip.appendChild(heading);

            SERIES.forEach(function (series) {
                var row = document.createElement('div');
                row.className = 'rfq-chart-tooltip-row';

                var key = document.createElement('span');
                key.className = 'rfq-chart-tooltip-key';
                key.style.background = series.color;

                var name = document.createElement('span');
                name.className = 'rfq-chart-tooltip-name';
                name.textContent = series.label;

                var value = document.createElement('strong');
                value.className = 'rfq-chart-tooltip-value';
                value.textContent = seriesEls[series.key].values[index];

                row.append(key, name, value);
                tooltip.appendChild(row);
            });

            var leftPct = (px / width) * 100;
            tooltip.style.left = leftPct + '%';
            tooltip.style.transform = leftPct > 70 ? 'translateX(-100%)' : leftPct < 15 ? 'translateX(0)' : 'translateX(-50%)';
            tooltip.hidden = false;
        }

        function hide() {
            crosshair.style.opacity = 0;
            tooltip.hidden = true;
        }

        function nearestIndex(clientX) {
            var rect = svg.getBoundingClientRect();
            var svgX = ((clientX - rect.left) / rect.width) * width;
            var ratio = labels.length === 1 ? 0 : (svgX - padding.left) / plotWidth;
            var index = Math.round(ratio * (labels.length - 1));
            return Math.min(Math.max(index, 0), labels.length - 1);
        }

        overlay.addEventListener('pointermove', function (e) {
            showAt(nearestIndex(e.clientX));
        });
        overlay.addEventListener('pointerleave', hide);
        overlay.addEventListener('touchstart', function (e) {
            if (e.touches[0]) showAt(nearestIndex(e.touches[0].clientX));
        }, { passive: true });

        wrap.appendChild(svg);
        wrap.appendChild(tooltip);

        // Legend — toggle-to-isolate a series by clicking its swatch.
        var legend = document.createElement('div');
        legend.className = 'rfq-chart-legend';
        SERIES.forEach(function (series) {
            var item = document.createElement('button');
            item.type = 'button';
            item.className = 'rfq-chart-legend-item';

            var swatch = document.createElement('span');
            swatch.className = 'rfq-chart-legend-swatch';
            swatch.style.background = series.color;

            var label = document.createElement('span');
            label.textContent = series.label;

            item.append(swatch, label);
            item.addEventListener('click', function () {
                var isHidden = item.classList.toggle('is-hidden');
                seriesEls[series.key].line.style.opacity = isHidden ? 0.12 : 1;
                seriesEls[series.key].dot.style.opacity = isHidden ? 0.12 : 1;
            });
            legend.appendChild(item);
        });

        container.appendChild(legend);
        container.appendChild(wrap);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.rfq-chart[data-chart]').forEach(renderChart);

        // Table-view toggle — the accessibility twin of the chart above it.
        // Swaps between the two rather than showing both at once.
        document.querySelectorAll('.js-toggle-table').forEach(function (button) {
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
    });
})();
