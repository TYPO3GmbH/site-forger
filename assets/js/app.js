// Load the CSS stuff
require('@fortawesome/fontawesome-pro/css/fontawesome.min.css');
require('@fortawesome/fontawesome-pro/css/brands.min.css');
require('@fortawesome/fontawesome-pro/css/light.min.css');
require('../css/app.scss');

// Load the JS stuff
let $ = require('jquery');
require('bootstrap');
require('./libs/navbar.js');

// DateTime Transformations
var {DateTime} = require('luxon');
document.onreadystatechange = function () {
    if (document.readyState == "interactive") {

        // Format to Local Date
        Array.from(document.querySelectorAll('[data-processor="localdate"]')).forEach(function (element) {
            const value = element.dataset.value;
            element.textContent = DateTime.fromISO(value).toLocaleString({
                month: '2-digit',
                day: '2-digit',
                year: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hour12: false
            });
        });

        // Format to Relative Time
        Array.from(document.querySelectorAll('[data-processor="relativetime"]')).forEach(function (element) {
            const value = element.dataset.value;
            element.textContent = DateTime.fromISO(value).toRelative();
        });

    }
}
