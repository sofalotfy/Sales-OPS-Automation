/**
 * Filters that apply as soon as they are chosen.
 *
 * Every form marked `data-auto-submit` reloads on `change` of any control, so
 * the count above a table and the rows under it move together instead of
 * describing different sets until a second click. The apply button is left in
 * the markup as the no-JavaScript path.
 *
 * Two details are handled per form:
 *
 * - A status reached before the scoring stage has no verdict yet, so the
 *   classification select is disabled for it and its value cleared. Submitting
 *   `status=scoring&classification=high` would report an empty log and read as
 *   "no high-scoring runs are scoring" rather than "nothing is scored yet". The
 *   controller publishes that list on the select so the rule is not restated
 *   here, and it applies the same drop server-side.
 *
 * - The two date bounds cross-limit each other, so the picker cannot choose a
 *   start after the end. The server rejects that too; this only avoids
 *   offering it.
 */
document.querySelectorAll('[data-auto-submit]').forEach((form) => {
    const status = form.querySelector('[data-filter-status]');
    const classification = form.querySelector('[data-filter-classification]');
    const hint = form.querySelector('[data-filter-classification-hint]');
    const from = form.querySelector('input[name="from"]');
    const to = form.querySelector('input[name="to"]');

    const preClassificationStatuses = classification
        ? (classification.dataset.preClassificationStatuses ?? '').split(',')
        : [];

    const syncClassification = () => {
        if (!status || !classification) {
            return;
        }

        const awaitsVerdict = preClassificationStatuses.includes(status.value);

        classification.disabled = awaitsVerdict;

        if (awaitsVerdict) {
            classification.value = '';
        }

        hint?.classList.toggle('hidden', !awaitsVerdict);
    };

    const syncDateBounds = () => {
        if (!from || !to) {
            return;
        }

        from.removeAttribute('max');
        to.removeAttribute('min');

        if (to.value) {
            from.max = to.value;
        }

        if (from.value) {
            to.min = from.value;
        }
    };

    // `change` bubbles from every control in the form, so one listener covers
    // the selects and the date inputs alike.
    form.addEventListener('change', () => {
        // Synced before submitting so the disabled select is already out of the
        // query string the form sends.
        syncClassification();
        form.submit();
    });

    syncClassification();
    syncDateBounds();
});
