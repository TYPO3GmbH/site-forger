/**
 * The datepicker of a range, like the filter "Created" of the issues: one day clicked alone is that day.
 *
 * The template bundle sets up flatpickr for every field with "data-datepicker". In the mode "range" flatpickr
 * forgets a range with only its first day when the calendar closes, so a click on "Apply" would send nothing.
 * Before that, on closing, the day becomes the first and the last day. See templates/_partials/aggregations.html.twig.
 */
(function () {
    'use strict';

    function keepOneDay(field) {
        const picker = field._flatpickr;
        if (!picker) {
            return;
        }
        picker.config.onClose.push(function (dates, text, instance) {
            if (dates.length === 1) {
                instance.setDate([dates[0], dates[0]], true);
            }
        });
    }

    function init() {
        document.querySelectorAll('[data-date-range]').forEach(keepOneDay);
    }

    // After the template bundle, whose script comes first, has set up the datepickers
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
