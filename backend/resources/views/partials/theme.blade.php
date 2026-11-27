{{-- Light / dark switch for the staff console. Include in <head>: applies the saved choice before the
     first paint. With nothing saved, the console follows the computer's own setting. --}}
<script>
    (function () {
        var KEY = 'sunates.theme', PKEY = 'sunates.palette';
        var root = document.documentElement;
        try {
            var saved = localStorage.getItem(KEY);
            if (saved === 'light' || saved === 'dark') root.dataset.theme = saved;
            if (localStorage.getItem(PKEY) === 'coffee') root.dataset.palette = 'coffee';
        } catch (e) {}

        function current() {
            var t = root.dataset.theme;
            if (t === 'light' || t === 'dark') return t;
            return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }

        function label() {
            document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
                b.textContent = current() === 'dark' ? '☀ Light mode' : '☾ Dark mode';
            });
            document.querySelectorAll('[data-palette-toggle]').forEach(function (b) {
                b.textContent = root.dataset.palette === 'coffee' ? 'Palette: Coffee' : 'Palette: Forest';
            });
        }

        window.sunatesTogglePalette = function () {
            var next = root.dataset.palette === 'coffee' ? 'green' : 'coffee';
            if (next === 'green') delete root.dataset.palette; else root.dataset.palette = 'coffee';
            try { localStorage.setItem(PKEY, next); } catch (e) {}
            label();
        };

        window.sunatesToggleTheme = function () {
            var next = current() === 'dark' ? 'light' : 'dark';
            root.dataset.theme = next;
            try { localStorage.setItem(KEY, next); } catch (e) {}
            label();
        };

        document.addEventListener('DOMContentLoaded', label);
        // Livewire page navigation swaps the body; keep the button's label right.
        document.addEventListener('livewire:navigated', label);
    })();
</script>
