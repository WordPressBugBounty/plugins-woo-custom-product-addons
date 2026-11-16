/**
 * Acowebs feedback platform in WooCommerce Custom Product Addons Free (FEAT-021): the deactivation popup on
 * plugins.php and the newsletter notice on Free's own screen. Markup and PHP: includes/backend/feedback.php.
 * No jQuery. Configuration from wp_localize_script: wcpaFeedback {ajaxUrl, nonce, plugin, wait, i18n}.
 *
 * Popup rules: nothing is sent unless the user clicks "Send & deactivate"; deactivation then follows when
 * admin-ajax answers or after `wait` ms, whichever comes first, so feedback can never hold it up. Skip
 * deactivates at once and sends nothing; Cancel, Esc and a click on the backdrop close the popup.
 */
(function () {
    'use strict';
    var cfg = window.wcpaFeedback;
    if (!cfg) {
        return;
    }

    /** POST to admin-ajax with the nonce; resolves with the parsed JSON (or rejects). */
    function post(action, fields) {
        var body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce', cfg.nonce);
        Object.keys(fields || {}).forEach(function (key) {
            body.append(key, fields[key]);
        });
        return fetch(cfg.ajaxUrl, {method: 'POST', credentials: 'same-origin', body: body}).then(function (response) {
            return response.json();
        });
    }

    function visible(el) {
        return !!(el && el.getClientRects().length);
    }

    // ---- Deactivation popup -------------------------------------------------------------------------
    // Free's row is found by its plugin file (data-plugin), which WordPress prints whatever the folder or
    // the WordPress.org slug is.
    var link = document.querySelector('tr[data-plugin="' + cfg.plugin + '"] .deactivate a');
    var overlay = document.getElementById('acofb-wcpa-deactivation');
    if (link && overlay) {
        var form = overlay.querySelector('form');
        var sendButton = form.querySelector('[data-acofb-action="send"]');
        var leaving = false;

        var deactivate = function () {
            if (leaving) {
                return;
            }
            leaving = true;
            window.location.href = link.href;
        };
        var open = function () {
            overlay.hidden = false;
            form.querySelector('input[name="reason"]').focus();
        };
        var close = function () {
            overlay.hidden = true;
            form.reset();
            link.focus();
        };
        /** The fields to send: the chosen reason, its visible details box, and name/email only while ticked. */
        var collect = function () {
            var reason = form.querySelector('input[name="reason"]:checked');
            var consent = form.querySelector('input[name="consent"]');
            var data = {reason: reason ? reason.value : '', consent: consent.checked ? '1' : '0'};
            var details = reason ? reason.parentNode.querySelector('textarea[name="reason_details"]') : null;
            if (visible(details) && details.value.trim() !== '') {
                data.reason_details = details.value.trim();
            }
            if (consent.checked) {
                ['name', 'email'].forEach(function (key) {
                    var input = form.querySelector('input[name="' + key + '"]');
                    if (input && input.value.trim() !== '') {
                        data[key] = input.value.trim();
                    }
                });
            }
            return data;
        };

        link.addEventListener('click', function (event) {
            event.preventDefault();
            open();
        });
        overlay.addEventListener('click', function (event) {
            var action = event.target.closest('[data-acofb-action]');
            if (event.target === overlay || (action && action.getAttribute('data-acofb-action') === 'cancel')) {
                close();
            } else if (action && action.getAttribute('data-acofb-action') === 'skip') {
                deactivate();
            }
        });
        overlay.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                close();
                return;
            }
            if (event.key !== 'Tab') {
                return;
            }
            // Keep Tab inside the dialog. A radio group is one Tab stop: its checked radio, or the first one.
            var anyChecked = form.querySelector('input[name="reason"]:checked');
            var stops = Array.prototype.filter.call(overlay.querySelectorAll('input, textarea, button, a[href]'), function (el) {
                if (!visible(el) || el.disabled) {
                    return false;
                }
                return el.type !== 'radio' || (anyChecked ? el.checked : el === form.querySelector('input[name="reason"]'));
            });
            var first = stops[0];
            var last = stops[stops.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                last.focus();
                event.preventDefault();
            } else if (!event.shiftKey && document.activeElement === last) {
                first.focus();
                event.preventDefault();
            }
        });
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!form.querySelector('input[name="reason"]:checked')) {
                // The Send button only looks disabled until a reason is chosen: point at the reasons.
                form.querySelector('input[name="reason"]').focus();
                return;
            }
            if (sendButton.disabled) {
                return;
            }
            sendButton.disabled = true;
            window.setTimeout(deactivate, cfg.wait || 3000);
            post('wcpa_deactivation_feedback', collect()).then(deactivate, deactivate);
        });
    }

    // ---- Newsletter notice --------------------------------------------------------------------------
    var notice = document.getElementById('acofb-wcpa-newsletter');
    if (notice) {
        var panel = notice.querySelector('form.acofb-panel');
        var msg = notice.querySelector('.acofb-msg');
        var subscribe = panel.querySelector('button[type="submit"]');
        var say = function (text, isError) {
            msg.textContent = text || '';
            msg.classList.toggle('acofb-msg--error', !!isError);
        };

        panel.addEventListener('submit', function (event) {
            event.preventDefault();
            var email = panel.querySelector('input[name="email"]');
            if (!email.value || !email.checkValidity()) {
                email.reportValidity();
                return;
            }
            subscribe.disabled = true;
            say(cfg.i18n.subscribing);
            // The first-name input is read by selector: `panel.name` would be the form's own name property.
            var field = function (key) {
                return panel.querySelector('input[name="' + key + '"]').value.trim();
            };
            post('wcpa_optin_subscribe', {email: field('email'), name: field('name'), phone: field('phone')}).then(function (result) {
                if (result && result.success) {
                    notice.querySelector('.acofb-notice-body').hidden = true;
                    var thanks = notice.querySelector('.acofb-thanks');
                    thanks.hidden = false;
                    thanks.focus();
                    return;
                }
                subscribe.disabled = false;
                say((result && result.data && result.data.message) || cfg.i18n.error, true);
            }).catch(function () {
                subscribe.disabled = false;
                say(cfg.i18n.error, true);
            });
        });
        // "Not now" and the X both dismiss for good (also after a successful subscribe: the X closes the thanks).
        notice.addEventListener('click', function (event) {
            if (!event.target.closest('[data-acofb-action="dismiss"]')) {
                return;
            }
            post('wcpa_optin_dismiss').catch(function () {
            });
            var wrap = notice.parentNode;
            wrap.parentNode.removeChild(wrap);
        });
    }
})();
