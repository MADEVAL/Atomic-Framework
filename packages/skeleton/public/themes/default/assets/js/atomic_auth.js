/* Atomic Auth JS */
'use strict';

document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('auth-form');
    if (!form) return;

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var alertEl = document.getElementById('alert');
        if (alertEl) alertEl.style.display = 'none';

        var data = new FormData(form);

        fetch(form.action, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams(data).toString()
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (json.success && json.data && json.data.redirect) {
                window.location.href = json.data.redirect;
            } else {
                if (alertEl) {
                    alertEl.textContent = json.message || 'Something went wrong.';
                    alertEl.style.display = 'block';
                }
            }
        })
        .catch(function() {
            if (alertEl) {
                alertEl.textContent = 'Network error. Please try again.';
                alertEl.style.display = 'block';
            }
        });
    });
});
