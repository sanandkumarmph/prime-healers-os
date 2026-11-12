document.querySelectorAll('[data-rental-signature]').forEach((form) => {
    const canvas = form.querySelector('canvas');
    const context = canvas.getContext('2d');
    const field = form.elements.signature_data;
    const error = form.querySelector('[data-signature-error]');
    let pointer = null;
    let hasInk = false;

    context.lineWidth = 4;
    context.lineCap = 'round';
    context.lineJoin = 'round';
    context.strokeStyle = '#172b4d';

    function point(event) {
        const rectangle = canvas.getBoundingClientRect();
        return [
            (event.clientX - rectangle.left) * canvas.width / rectangle.width,
            (event.clientY - rectangle.top) * canvas.height / rectangle.height,
        ];
    }

    canvas.addEventListener('pointerdown', (event) => {
        if (pointer !== null || event.button !== 0) return;
        pointer = event.pointerId;
        canvas.setPointerCapture(pointer);
        context.beginPath();
        context.moveTo(...point(event));
        error.hidden = true;
    });

    canvas.addEventListener('pointermove', (event) => {
        if (event.pointerId !== pointer) return;
        const position = point(event);
        context.lineTo(...position);
        context.stroke();
        context.beginPath();
        context.moveTo(...position);
        hasInk = true;
    });

    canvas.addEventListener('lostpointercapture', () => { pointer = null; });

    form.querySelector('[data-clear-signature]').addEventListener('click', () => {
        context.clearRect(0, 0, canvas.width, canvas.height);
        hasInk = false;
        field.value = '';
        error.hidden = true;
    });

    form.addEventListener('submit', (event) => {
        if (!hasInk) {
            event.preventDefault();
            error.hidden = false;
            return;
        }

        field.value = canvas.toDataURL('image/png');
    });
});
