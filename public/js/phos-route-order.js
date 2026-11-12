(() => {
    const list = document.querySelector('[data-route-stop-list]');
    const form = document.getElementById('route-order');
    if (!list || !form || !list.setPointerCapture) return;

    const order = form.elements.namedItem('order');
    const status = document.querySelector('[data-route-drag-status]');
    const handleSelector = '[data-route-drag-handle]';
    const rows = () => Array.from(list.rows);
    let pending = null;
    let picked = null;
    let frame = 0;
    let submitting = false;

    function renumber() {
        rows().forEach((row, index) => {
            row.querySelector('[data-route-position]').textContent = index + 1;
        });
    }

    function pickUp(handle, pointerId = null) {
        const row = handle.closest('tr');
        picked = { row, handle, next: row.nextElementSibling, pointerId };
        row.setAttribute('data-route-picked-up', '');
        status.textContent = 'Stop picked up. Choose a new position.';
    }

    function finish(place) {
        const { row, next, handle, pointerId } = picked;
        picked = null;
        cancelAnimationFrame(frame);
        if (pointerId !== null && list.hasPointerCapture(pointerId)) list.releasePointerCapture(pointerId);
        row.removeAttribute('data-route-picked-up');
        if (!place) {
            list.insertBefore(row, next);
            renumber();
            status.textContent = 'Reordering cancelled.';
        } else {
            const sequence = rows().map(stop => stop.dataset.routeStop).join(',');
            if (sequence !== order.value) {
                const url = new URL(form.action);
                url.search = new URLSearchParams(new FormData(form));
                url.searchParams.set('order', sequence);
                url.hash = row.id;
                submitting = true;
                status.textContent = 'Updating route order.';
                window.location.assign(url);
                return;
            }
            status.textContent = 'Stop kept in its original position.';
        }
        handle.focus({ preventScroll: true });
    }

    function moveToPointer(y) {
        const { row } = picked;
        const midpoint = sibling => {
            const bounds = sibling.getBoundingClientRect();
            return bounds.top + bounds.height / 2;
        };
        const originalNext = row.nextElementSibling;
        while (row.previousElementSibling && y < midpoint(row.previousElementSibling)) {
            row.previousElementSibling.before(row);
        }
        while (row.nextElementSibling && y > midpoint(row.nextElementSibling)) {
            row.nextElementSibling.after(row);
        }
        if (row.nextElementSibling !== originalNext) renumber();
    }

    function followPointer() {
        const { row, y } = picked;
        const scrollEdge = 48;
        if (y < scrollEdge && row.previousElementSibling) window.scrollBy(0, -14);
        if (y > window.innerHeight - scrollEdge && row.nextElementSibling) window.scrollBy(0, 14);
        moveToPointer(y);
        frame = requestAnimationFrame(followPointer);
    }

    list.addEventListener('pointerdown', event => {
        const handle = event.target.closest(handleSelector);
        if (!handle || picked || submitting || !event.isPrimary || event.button !== 0) return;
        pending = { handle, id: event.pointerId, y: event.clientY };
    });

    window.addEventListener('pointermove', event => {
        if (pending?.id === event.pointerId && Math.abs(event.clientY - pending.y) >= 5) {
            pickUp(pending.handle, event.pointerId);
            pending = null;
            list.setPointerCapture(event.pointerId);
            picked.y = event.clientY;
            followPointer();
        } else if (picked?.pointerId === event.pointerId) {
            picked.y = event.clientY;
        }
    });

    window.addEventListener('pointerup', event => {
        if (pending?.id === event.pointerId) pending = null;
        if (picked?.pointerId === event.pointerId) {
            moveToPointer(event.clientY);
            finish(true);
        }
    });

    window.addEventListener('pointercancel', event => {
        if (pending?.id === event.pointerId) pending = null;
        if (picked?.pointerId === event.pointerId) finish(false);
    });

    list.addEventListener('lostpointercapture', event => {
        if (event.target === list && picked?.pointerId === event.pointerId) finish(false);
    });

    list.addEventListener('click', event => {
        const handle = event.target.closest(handleSelector);
        if (!handle || submitting || (picked && picked.pointerId !== null)) return;
        if (!picked) {
            pickUp(handle);
            return;
        }
        const target = handle.closest('tr');
        if (target.rowIndex < picked.row.rowIndex) target.before(picked.row);
        if (target.rowIndex > picked.row.rowIndex) target.after(picked.row);
        finish(true);
    });

    document.addEventListener('keydown', event => {
        if (!picked) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            finish(false);
        } else if (event.target === picked.handle && picked.pointerId === null) {
            const { row, handle } = picked;
            if (!['ArrowUp', 'ArrowDown'].includes(event.key)) return;
            event.preventDefault();
            if (event.key === 'ArrowUp') row.previousElementSibling?.before(row);
            if (event.key === 'ArrowDown') row.nextElementSibling?.after(row);
            renumber();
            handle.focus({ preventScroll: true });
            handle.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            status.textContent = 'Stop moved to position ' + (row.sectionRowIndex + 1) + '.';
        }
    });

    window.addEventListener('pageshow', event => {
        if (event.persisted && submitting) window.location.reload();
    });
    list.querySelectorAll(handleSelector).forEach(handle => { handle.hidden = false; });
    list.querySelectorAll('[data-route-reorder-fallback]').forEach(fallback => { fallback.hidden = true; });
    document.querySelector('[data-route-drag-help]').hidden = false;
    document.getElementById(window.location.hash.slice(1))?.querySelector(handleSelector)?.focus({ preventScroll: true });
})();
