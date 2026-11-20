/**
 * Success messages hide by themselves after a few seconds, the flash messages of the layout of the template bundle.
 * Errors, warnings and hints stay until closed. The time stops while the pointer is on a message or it has the focus.
 */
(function () {
    'use strict';

    const DELAY = 5000;

    function hide(alert) {
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reducedMotion || !alert.classList.contains('fade')) {
            alert.remove();
            return;
        }
        alert.addEventListener('transitionend', function () {
            alert.remove();
        }, {once: true});
        alert.classList.remove('show');
    }

    function autoHide(alert) {
        let timer = null;
        const start = function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                hide(alert);
            }, DELAY);
        };
        const stop = function () {
            window.clearTimeout(timer);
        };
        alert.addEventListener('mouseenter', stop);
        alert.addEventListener('focusin', stop);
        alert.addEventListener('mouseleave', function () {
            if (!alert.contains(document.activeElement)) {
                start();
            }
        });
        alert.addEventListener('focusout', function (event) {
            if (!alert.contains(event.relatedTarget) && !alert.matches(':hover')) {
                start();
            }
        });
        start();
    }

    window.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.alert[data-severity="success"]').forEach(autoHide);
    });
})();
