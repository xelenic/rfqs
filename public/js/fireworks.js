/*
 * Fireworks for something closed — RFQMS.
 *
 * layouts/_celebration.blade.php puts the overlay on the page for the one load
 * after Business Development closes a part or an RFQ (RfqController::close()
 * and closePart() flash `celebrate`); this paints the show over it. Plain
 * canvas, no library: rockets climb with a trail and burst into a few shapes
 * (peony, ring, willow, crackle, star — and a heart, for the grand one),
 * sparks twinkle and fall, and confetti drifts down. The grand show, for a
 * whole RFQ closing, is longer and bigger. Reduced motion gets the card and
 * nothing else. Everything goes away again after a few seconds, on a click
 * outside the card, or on Escape.
 */
(function () {
    'use strict';

    var overlay = document.getElementById('celebration');

    if (!overlay) {
        return;
    }

    var canvas = overlay.querySelector('.celebration-canvas');
    var confettiLayer = overlay.querySelector('.celebration-confetti-layer');
    var card = overlay.querySelector('.celebration-card');
    var grand = overlay.getAttribute('data-grand') === '1';
    var reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    // How long the rockets keep going and how far apart they are, how much
    // confetti there is, and how long the card stays before it leaves on its own.
    var SHOW = grand
        ? { length: 5600, gapMin: 230, gapMax: 470, confetti: 130, stay: 9000 }
        : { length: 3800, gapMin: 360, gapMax: 680, confetti: 45, stay: 6800 };

    // Indigo, green, gold, pink, cyan, orange, violet — the app's blue with company.
    var HUES = [226, 152, 44, 332, 188, 22, 270];

    var TAU = Math.PI * 2;
    var timers = [];
    var frameId = null;
    var leaving = false;

    function rand(min, max) {
        return min + Math.random() * (max - min);
    }

    function pick(list) {
        return list[Math.floor(Math.random() * list.length)];
    }

    function later(fn, ms) {
        var id = setTimeout(fn, ms);
        timers.push(id);
        return id;
    }

    // ---- Leaving -----------------------------------------------------------

    var stayTimer = null;
    var stayLeft = SHOW.stay;
    var stayFrom = Date.now();

    overlay.style.setProperty('--stay', SHOW.stay + 'ms');

    function armStay() {
        clearTimeout(stayTimer);
        stayFrom = Date.now();
        stayTimer = setTimeout(dismiss, stayLeft);
    }

    function dismiss() {
        if (leaving) {
            return;
        }

        leaving = true;
        clearTimeout(stayTimer);
        overlay.classList.add('is-leaving');
        // Not one of the show's timers: the show may finish, and clear those, first.
        setTimeout(remove, 480);
    }

    function remove() {
        stopFireworks();
        document.removeEventListener('keydown', onKey);
        window.removeEventListener('resize', resize);
        overlay.remove();
    }

    function onKey(event) {
        if (event.key === 'Escape') {
            dismiss();
        }
    }

    document.addEventListener('keydown', onKey);

    // A click anywhere but on the card (or its link) sends it away; the button
    // does too. Resting the pointer on the card keeps it — the bar pauses with it.
    overlay.addEventListener('click', function (event) {
        if (event.target.closest('.js-celebration-dismiss') || !event.target.closest('.celebration-card')) {
            dismiss();
        }
    });

    card.addEventListener('mouseenter', function () {
        clearTimeout(stayTimer);
        stayLeft = Math.max(1500, stayLeft - (Date.now() - stayFrom));
        overlay.classList.add('is-held');
    });

    card.addEventListener('mouseleave', function () {
        overlay.classList.remove('is-held');
        armStay();
    });

    armStay();

    if (reduceMotion) {
        return;
    }

    // ---- Confetti: little elements the stylesheet lets fall -----------------

    function confetti() {
        for (var i = 0; i < SHOW.confetti; i++) {
            var piece = document.createElement('i');
            var hue = pick(HUES) + rand(-10, 10);

            piece.className = 'celebration-confetti';
            piece.style.cssText = [
                '--x:' + rand(0, 100).toFixed(1) + 'vw',
                '--w:' + rand(6, 11).toFixed(1) + 'px',
                '--h:' + rand(10, 20).toFixed(1) + 'px',
                '--c:hsl(' + hue.toFixed(0) + ',90%,' + rand(55, 68).toFixed(0) + '%)',
                '--r:' + rand(360, 1080).toFixed(0) + 'deg',
                '--s:' + rand(-70, 70).toFixed(0) + 'px',
                '--t:' + rand(3.4, 5.6).toFixed(2) + 's',
                '--d:' + rand(0, SHOW.length / 1000 * 0.8).toFixed(2) + 's',
                'border-radius:' + (Math.random() < 0.3 ? '50%' : '2px'),
            ].join(';');
            confettiLayer.appendChild(piece);
        }
    }

    // ---- Fireworks -----------------------------------------------------------

    var ctx = canvas.getContext('2d');
    var W = 0;
    var H = 0;
    var rockets = [];
    var sparks = [];
    var startedAt = 0;
    var lastFrame = 0;
    var launching = true;

    function resize() {
        var dpr = Math.min(window.devicePixelRatio || 1, 2);

        W = window.innerWidth;
        H = window.innerHeight;
        canvas.width = Math.round(W * dpr);
        canvas.height = Math.round(H * dpr);
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }

    window.addEventListener('resize', resize);
    resize();

    function spark(x, y, vx, vy, hue, options) {
        options = options || {};

        sparks.push({
            x: x,
            y: y,
            vx: vx,
            vy: vy,
            hue: hue,
            sat: options.sat || 100,
            light: options.light || rand(58, 72),
            size: options.size || rand(1.5, 2.5),
            drag: options.drag || 0.965,
            gravity: options.gravity === undefined ? 0.045 : options.gravity,
            decay: options.decay || rand(0.011, 0.02),
            twinkle: options.twinkle !== false,
            crackle: !!options.crackle,
            phase: rand(0, TAU),
            life: 1,
            trail: [],
        });
    }

    function launch(x, hue) {
        var top = rand(0.12, 0.4) * H;
        var g = 0.28;

        rockets.push({
            x: x,
            y: H + 8,
            vx: rand(-0.5, 0.5),
            vy: -Math.sqrt(2 * g * (H - top)), // just reaches its height, and bursts there
            g: g,
            hue: hue,
            trail: [],
        });
    }

    // One burst, in one of a handful of shapes. $power scales it to the screen.
    function burst(x, y, hue) {
        var kinds = ['peony', 'peony', 'ring', 'willow', 'crackle', 'star'];
        var kind = pick(grand ? kinds.concat(['heart', 'star', 'ring']) : kinds);
        var power = Math.min(1.4, Math.max(0.75, Math.min(W, H) / 720)) * rand(0.92, 1.12) * (grand ? 1.15 : 1);
        var second = (hue + pick([35, 150, 180, 210])) % 360;
        var i;
        var angle;
        var speed;

        if (kind === 'ring') {
            var squash = rand(0.45, 1);
            var tilt = rand(-0.5, 0.5);

            for (i = 0; i < 60; i++) {
                angle = (i / 60) * TAU;
                var rx = Math.cos(angle) * 6.6 * power;
                var ry = Math.sin(angle) * 6.6 * power * squash;

                spark(x, y, rx * Math.cos(tilt) - ry * Math.sin(tilt), rx * Math.sin(tilt) + ry * Math.cos(tilt), i % 2 ? hue : second, { drag: 0.958, gravity: 0.035, size: 2.6 });
            }
        } else if (kind === 'willow') {
            for (i = 0; i < 80; i++) {
                angle = rand(0, TAU);
                speed = rand(1.2, 5.4) * power;
                spark(x, y, Math.cos(angle) * speed, Math.sin(angle) * speed, 44, { drag: 0.978, gravity: 0.064, decay: rand(0.006, 0.011), light: rand(62, 78), size: 2 });
            }
        } else if (kind === 'star' || kind === 'heart') {
            var points = kind === 'star' ? starPoints() : heartPoints();

            for (i = 0; i < points.length; i++) {
                spark(x, y, points[i][0] * 7.4 * power, points[i][1] * 7.4 * power, i % 3 ? hue : second, { drag: 0.948, gravity: 0.04, decay: rand(0.011, 0.015), size: 2.8 });
            }
        } else {
            var crackle = kind === 'crackle';

            for (i = 0; i < 110; i++) {
                angle = rand(0, TAU);
                speed = Math.sqrt(Math.random()) * 7.2 * power;
                spark(x, y, Math.cos(angle) * speed, Math.sin(angle) * speed, Math.random() < 0.28 ? second : hue, { drag: 0.968, size: rand(1.8, 3), crackle: crackle && Math.random() < 0.4 });
            }
        }

        // A soft flash where it goes off.
        sparks.push({ flash: true, x: x, y: y, hue: hue, life: 1, size: 70 * power });
    }

    // Outline of a five-pointed star, as unit velocities.
    function starPoints() {
        var vertices = [];
        var out = [];
        var i;
        var j;

        for (i = 0; i < 10; i++) {
            var a = -Math.PI / 2 + (i * Math.PI) / 5;
            var r = i % 2 ? 0.42 : 1;

            vertices.push([Math.cos(a) * r, Math.sin(a) * r]);
        }

        for (i = 0; i < 10; i++) {
            var from = vertices[i];
            var to = vertices[(i + 1) % 10];

            for (j = 0; j < 7; j++) {
                out.push([from[0] + ((to[0] - from[0]) * j) / 7, from[1] + ((to[1] - from[1]) * j) / 7]);
            }
        }

        return out;
    }

    // Outline of a heart, as unit velocities.
    function heartPoints() {
        var out = [];

        for (var i = 0; i < 64; i++) {
            var t = (i / 64) * TAU;

            out.push([(16 * Math.pow(Math.sin(t), 3)) / 17, -(13 * Math.cos(t) - 5 * Math.cos(2 * t) - 2 * Math.cos(3 * t) - Math.cos(4 * t)) / 17]);
        }

        return out;
    }

    function rocket() {
        // Mostly to the sides, so the card isn't sitting on the show; now and then straight up the middle.
        var x = Math.random() < 0.2 ? rand(0.4, 0.6) * W : (Math.random() < 0.5 ? rand(0.08, 0.36) : rand(0.64, 0.92)) * W;

        launch(x, pick(HUES) + rand(-8, 8));
    }

    // The grand show ends on a volley across the whole sky.
    var finale = false;

    function volley() {
        for (var i = 0; i < 8; i++) {
            (function (i) {
                later(function () {
                    launch(((i + 0.5) / 8) * W, HUES[i % HUES.length] + rand(-8, 8));
                }, i * 75);
            })(i);
        }
    }

    function schedule() {
        var elapsed = Date.now() - startedAt;

        if (leaving || elapsed > SHOW.length) {
            launching = false;

            return;
        }

        if (grand && !finale && elapsed > SHOW.length * 0.72) {
            finale = true;
            volley();
        }

        rocket();

        if (grand && Math.random() < 0.55) {
            later(rocket, 130);
        }

        later(schedule, rand(SHOW.gapMin, SHOW.gapMax));
    }

    function step(now) {
        frameId = requestAnimationFrame(step);

        var dt = Math.min(2, Math.max(0.4, (now - (lastFrame || now)) / 16.667));

        lastFrame = now;
        ctx.clearRect(0, 0, W, H);
        ctx.globalCompositeOperation = 'lighter';

        var i;

        // Rockets climbing, leaving a trail and a few sparks.
        for (i = rockets.length - 1; i >= 0; i--) {
            var r = rockets[i];

            r.x += r.vx * dt;
            r.y += r.vy * dt;
            r.vy += r.g * dt;
            r.trail.push([r.x, r.y]);

            if (r.trail.length > 9) {
                r.trail.shift();
            }

            if (Math.random() < 0.7) {
                spark(r.x, r.y, rand(-0.4, 0.4), rand(0.2, 1), r.hue, { size: 1.1, decay: 0.05, gravity: 0.03, sat: 90, light: 75 });
            }

            drawTrail(r.trail, r.hue, 1, 2.2);

            if (r.vy >= -0.5) {
                burst(r.x, r.y, r.hue);
                rockets.splice(i, 1);
            }
        }

        // Sparks: drift, fall, twinkle, fade. A crackling one goes off in a few pops as it dies.
        for (i = sparks.length - 1; i >= 0; i--) {
            var p = sparks[i];

            if (p.flash) {
                p.life -= 0.07 * dt;

                if (p.life <= 0) {
                    sparks.splice(i, 1);

                    continue;
                }

                var glow = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, p.size * (1.6 - p.life * 0.6));

                glow.addColorStop(0, 'hsla(' + p.hue + ',100%,88%,' + (p.life * 0.5).toFixed(3) + ')');
                glow.addColorStop(0.3, 'hsla(' + p.hue + ',100%,70%,' + (p.life * 0.16).toFixed(3) + ')');
                glow.addColorStop(1, 'hsla(' + p.hue + ',100%,60%,0)');
                ctx.fillStyle = glow;
                ctx.fillRect(p.x - p.size * 2, p.y - p.size * 2, p.size * 4, p.size * 4);

                continue;
            }

            p.vx *= Math.pow(p.drag, dt);
            p.vy *= Math.pow(p.drag, dt);
            p.vy += p.gravity * dt;
            p.x += p.vx * dt;
            p.y += p.vy * dt;
            p.life -= p.decay * dt;
            p.trail.push([p.x, p.y]);

            if (p.trail.length > 6) {
                p.trail.shift();
            }

            if (p.life <= 0 || p.y > H + 20) {
                if (p.crackle) {
                    for (var c = 0; c < 3; c++) {
                        spark(p.x, p.y, rand(-1.6, 1.6), rand(-1.6, 1.6), p.hue, { sat: 30, light: 88, size: 1.4, decay: rand(0.05, 0.09), drag: 0.9, gravity: 0.02 });
                    }
                }

                sparks.splice(i, 1);

                continue;
            }

            var alpha = p.life;

            if (p.twinkle && p.life < 0.6) {
                alpha *= 0.5 + 0.5 * Math.sin(now * 0.035 + p.phase);
            }

            drawTrail(p.trail, p.hue, Math.max(0, alpha), p.size * (0.55 + 0.45 * p.life), p.sat, p.light);
        }

        ctx.globalCompositeOperation = 'source-over';

        if (!launching && !rockets.length && !sparks.length) {
            stopFireworks();
        }
    }

    // A trail: a soft line up to a bright head with a little glow round it.
    function drawTrail(trail, hue, alpha, width, sat, light) {
        var n = trail.length;

        if (n < 2 || alpha <= 0) {
            return;
        }

        sat = sat || 100;
        light = light || 66;

        ctx.lineCap = 'round';
        ctx.lineWidth = width;
        ctx.strokeStyle = 'hsla(' + hue + ',' + sat + '%,' + light + '%,' + (alpha * 0.55).toFixed(3) + ')';
        ctx.beginPath();
        ctx.moveTo(trail[0][0], trail[0][1]);

        for (var i = 1; i < n; i++) {
            ctx.lineTo(trail[i][0], trail[i][1]);
        }

        ctx.stroke();

        var head = trail[n - 1];

        ctx.fillStyle = 'hsla(' + hue + ',' + sat + '%,' + Math.min(92, light + 18) + '%,' + alpha.toFixed(3) + ')';
        ctx.beginPath();
        ctx.arc(head[0], head[1], width * 0.75, 0, TAU);
        ctx.fill();

        ctx.fillStyle = 'hsla(' + hue + ',' + sat + '%,' + light + '%,' + (alpha * 0.14).toFixed(3) + ')';
        ctx.beginPath();
        ctx.arc(head[0], head[1], width * 3.2, 0, TAU);
        ctx.fill();
    }

    function stopFireworks() {
        launching = false;

        if (frameId !== null) {
            cancelAnimationFrame(frameId);
            frameId = null;
        }

        timers.forEach(clearTimeout);
        timers = [];
        ctx.clearRect(0, 0, W, H);
    }

    // ---- Go ---------------------------------------------------------------

    confetti();
    startedAt = Date.now();

    // An opening salvo across the sky, then a rocket every so often.
    [0.14, 0.3, 0.7, 0.86].forEach(function (place, index) {
        later(function () {
            launch(place * W, HUES[index % HUES.length]);
        }, 60 + index * 110);
    });

    later(schedule, 700);
    frameId = requestAnimationFrame(step);
})();
