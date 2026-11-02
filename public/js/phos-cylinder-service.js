(() => {
    'use strict';
    document.querySelectorAll('[data-cylinder-step]').forEach(form => {
        const canvas = form.querySelector('[data-cylinder-signature]');
        if (!canvas) return;
        const context = canvas.getContext('2d');
        const field = form.querySelector('[name=signature_data]');
        let drawing = false, ink = false;
        const resize = () => {
            if (ink || !canvas.clientWidth) return;
            canvas.width = Math.round(canvas.clientWidth * devicePixelRatio);
            canvas.height = Math.round(160 * devicePixelRatio);
            context.scale(devicePixelRatio, devicePixelRatio);
            context.lineWidth = 2; context.lineCap = 'round'; context.strokeStyle = '#183247';
        };
        new ResizeObserver(resize).observe(canvas);
        const point = event => { const r = canvas.getBoundingClientRect(); return [event.clientX-r.left, event.clientY-r.top]; };
        canvas.addEventListener('pointerdown', event => {
            if (!event.isPrimary) return;
            drawing = true; canvas.setPointerCapture(event.pointerId);
            context.beginPath(); context.moveTo(...point(event));
        });
        canvas.addEventListener('pointermove', event => {
            if (!drawing) return;
            context.lineTo(...point(event)); context.stroke(); ink = true;
        });
        canvas.addEventListener('pointerup', () => { drawing = false; field.value = ink ? canvas.toDataURL('image/png') : ''; });
        canvas.addEventListener('pointercancel', () => { drawing = false; field.value = ink ? canvas.toDataURL('image/png') : ''; });
        form.querySelector('[data-signature-clear]').addEventListener('click', () => {
            context.clearRect(0,0,canvas.width,canvas.height); ink = false; field.value = '';
        });
        form.addEventListener('submit', () => { field.value = ink ? canvas.toDataURL('image/png') : ''; });
    });
})();
