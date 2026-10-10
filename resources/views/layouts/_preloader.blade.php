{{--
    The page preloader: public/preloader.gif over everything until the page
    has loaded — kept up at least a moment, so its logo has faded in rather
    than flashing blank — and up again on the way to another page of the app
    (a link followed, a form sent), until that one has loaded. Not for what
    opens in a new tab or downloads, nor for live updates, which swap the
    page in place. Fewer moments for anyone who'd rather less motion; never
    without JavaScript.
--}}
<div class="app-preloader" id="appPreloader" role="status" aria-label="Loading">
    <img src="{{ asset('preloader.gif') }}" alt="" class="app-preloader-img" width="800" height="450">
</div>
<noscript><style>.app-preloader { display: none; }</style></noscript>
<script>
    (function () {
        var preloader = document.getElementById('appPreloader');
        if (!preloader) return;

        var lessMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var shortest = lessMotion ? 0 : 600;
        var shownAt = Date.now();
        var stuck = null;

        function hide() {
            clearTimeout(stuck);
            setTimeout(function () {
                preloader.classList.add('is-hidden');
            }, Math.max(0, shortest - (Date.now() - shownAt)));
        }

        function show() {
            if (lessMotion) return;
            shownAt = Date.now();
            preloader.classList.remove('is-hidden');
            // Something that didn't leave the page after all (a download, a
            // stopped load) doesn't leave it covered.
            clearTimeout(stuck);
            stuck = setTimeout(hide, 15000);
        }

        if (document.readyState === 'complete') {
            hide();
        } else {
            window.addEventListener('load', hide);
        }

        // Back or forward to a page the browser kept: as it was, uncovered.
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                clearTimeout(stuck);
                preloader.classList.add('is-hidden');
            }
        });

        document.addEventListener('click', function (event) {
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

            var link = event.target.closest('a[href]');
            if (!link || (link.target && link.target !== '_self') || link.hasAttribute('download') || link.dataset.bsToggle) return;

            var href = link.getAttribute('href');
            if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) return;

            var url = new URL(link.href, window.location.href);
            var samePage = url.pathname === window.location.pathname && url.search === window.location.search;
            if (url.origin !== window.location.origin || (samePage && url.hash)) return;

            show();
        });

        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (event.defaultPrevented || (form.target && form.target !== '_self')) return;

            show();
        });
    })();
</script>
