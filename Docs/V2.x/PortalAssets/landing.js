(() => {
    const art = document.querySelector('.sq-hero-art');
    const stack = art?.querySelector('.sq-stack');
    if (!art || !stack) return;

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const finePointer = window.matchMedia('(pointer: fine)');
    let frame = 0;

    const reset = () => {
        window.cancelAnimationFrame(frame);
        stack.style.removeProperty('--sq-rotate-x');
        stack.style.removeProperty('--sq-rotate-z');
    };

    // Keep the perspective effect decorative and pointer-only. CSS supplies
    // the fixed scene when reduced motion is requested or touch is in use.
    art.addEventListener('pointermove', (event) => {
        if (reducedMotion.matches || !finePointer.matches) return;
        const bounds = art.getBoundingClientRect();
        const x = ((event.clientX - bounds.left) / bounds.width - .5) * 2;
        const y = ((event.clientY - bounds.top) / bounds.height - .5) * 2;
        window.cancelAnimationFrame(frame);
        frame = window.requestAnimationFrame(() => {
            stack.style.setProperty('--sq-rotate-x', `${58 - y * 2.5}deg`);
            stack.style.setProperty('--sq-rotate-z', `${-41 + x * 3}deg`);
        });
    }, { passive: true });
    art.addEventListener('pointerleave', reset);
    reducedMotion.addEventListener('change', reset);
    finePointer.addEventListener('change', reset);
})();
