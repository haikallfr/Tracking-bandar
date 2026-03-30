(() => {
    const DURATION = 520;

    function getBody(details) {
        return details.querySelector('.item-body');
    }

    function cleanup(body) {
        body.style.overflow = '';
        body.style.maxHeight = '';
        body.style.opacity = '';
        body.style.transform = '';
    }

    function openDetails(details) {
        const body = getBody(details);
        if (!body) return;

        details.open = true;
        body.style.overflow = 'hidden';
        body.style.maxHeight = '0px';
        body.style.opacity = '0';
        body.style.transform = 'translateY(-10px)';

        requestAnimationFrame(() => {
            body.style.maxHeight = `${body.scrollHeight}px`;
            body.style.opacity = '1';
            body.style.transform = 'translateY(0)';
        });

        window.setTimeout(() => {
            if (details.open) {
                cleanup(body);
            }
        }, DURATION + 40);
    }

    function closeDetails(details) {
        const body = getBody(details);
        if (!body) return;

        body.style.overflow = 'hidden';
        body.style.maxHeight = `${body.scrollHeight}px`;
        body.style.opacity = '1';
        body.style.transform = 'translateY(0)';

        requestAnimationFrame(() => {
            body.style.maxHeight = '0px';
            body.style.opacity = '0';
            body.style.transform = 'translateY(-10px)';
        });

        window.setTimeout(() => {
            details.open = false;
            cleanup(body);
        }, DURATION);
    }

    function bind(details) {
        const summary = details.querySelector('.item-summary');
        const body = getBody(details);
        if (!summary || !body || details.dataset.animated === '1') return;

        details.dataset.animated = '1';
        if (!details.open) {
            body.style.maxHeight = '0px';
            body.style.opacity = '0';
            body.style.transform = 'translateY(-10px)';
        }

        summary.addEventListener('click', (event) => {
            event.preventDefault();

            if (details.dataset.animating === '1') {
                return;
            }

            details.dataset.animating = '1';

            if (details.open) {
                closeDetails(details);
            } else {
                openDetails(details);
            }

            window.setTimeout(() => {
                delete details.dataset.animating;
            }, DURATION + 60);
        });
    }

    function init(root = document) {
        root.querySelectorAll('details.item').forEach(bind);
    }

    document.addEventListener('DOMContentLoaded', () => init());
    window.bindAnimatedDetails = init;
})();
