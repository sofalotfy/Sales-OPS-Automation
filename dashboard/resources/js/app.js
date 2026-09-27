/**
 * Auto-submitting filters for the classification log.
 *
 * Picking an option applies the filter immediately, so the count above the
 * table and the rows themselves move together with the selection instead of
 * sitting next to a stale result until Apply is pressed.
 *
 * A status reached before the scoring stage has no verdict yet, so the
 * classification select is disabled for it and its value cleared: submitting
 * `status=scoring&classification=high` would report an empty log and read as
 * "no high-scoring runs are scoring" rather than "nothing is scored yet". The
 * list of those statuses is published by the controller on the select, so the
 * rule stays in one place; the controller applies the same drop server-side.
 */
const classificationFilters = document.getElementById('classification-filters');

if (classificationFilters) {
    const status = document.getElementById('filter-status');
    const classification = document.getElementById('filter-classification');
    const hint = document.getElementById('filter-classification-hint');
    const preClassificationStatuses = (classification.dataset.preClassificationStatuses ?? '').split(',');

    const syncClassification = () => {
        const awaitsVerdict = preClassificationStatuses.includes(status.value);

        classification.disabled = awaitsVerdict;

        if (awaitsVerdict) {
            classification.value = '';
        }

        hint.classList.toggle('hidden', !awaitsVerdict);
    };

    status.addEventListener('change', () => {
        syncClassification();
        classificationFilters.submit();
    });

    classification.addEventListener('change', () => classificationFilters.submit());

    syncClassification();
}
