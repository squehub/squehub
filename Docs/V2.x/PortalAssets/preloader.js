/* If JavaScript is unavailable, the preloader stays hidden. CSS is a second
   escape hatch if this script runs but later scripts or load events fail. */
(() => {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    document.documentElement.classList.add('squehub-preloader-active');
    const dismiss = () => document.documentElement.classList.remove('squehub-preloader-active');
    window.setTimeout(dismiss, 1900);
    window.addEventListener('pageshow', event => {
        if (event.persisted) dismiss();
    });
})();
