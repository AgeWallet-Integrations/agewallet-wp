/**
 * AgeWallet Client-Side Gating Script
 *
 * Checks for the verification cookie. Handles:
 * 1. Displaying a full-screen overlay if automatic gating is active and user is unverified.
 * 2. Showing/hiding content wrapped in the [agewallet_protected] shortcode based on verification status.
 * 3. Strict Mode: Fetches secure content via API (verified) or reveals Gate UI (unverified).
 *
 * @since 0.1.0
 */
(function() {
    'use strict';

    // Data passed from PHP via wp_localize_script('agewallet_gate_data')
    // Expected properties:
    // - cookieName: Name of the verification cookie (string)
    // - gateHtml: HTML for the overlay gate (string, may be empty)
    // - isOverlayActive: Flag indicating if automatic overlay gating is triggered (boolean)
    // - bodyClassPending: CSS class added to body initially (string)
    // - isStrictMode: (Bool) If true, use Skeleton/API logic.
    // - apiEndpoint: (String) URL to fetch content from.
    // - launchUrl: (String) URL endpoint to start the verification flow (/agewallet/launch/).
    // - redirectUrl: (String) Fallback return URL (from PHP).

    /**
     * Function to check if the verification cookie exists.
     * Checks for Signed Cookie Format (Base64.Signature)
     * @param {string} cookieName The name of the cookie to check.
     * @returns {boolean} True if the cookie exists and matches the signed format.
     */
    function isVerified(cookieName) {
        if (!cookieName) {
            console.error('[AgeWallet Gate] Cookie name is missing.');
            return false;
        }

        // Retrieve raw cookie value
        var match = document.cookie.match(new RegExp('(^| )' + cookieName + '=([^;]+)'));
        if (!match) return false;

        var cookieValue = decodeURIComponent(match[2]);

        // Validate Format: Base64Payload.HexSignature
        // Base64 (approx): [a-zA-Z0-9+/=]+
        // Hex (SHA256): [a-f0-9]{64}
        var signatureRegex = /^[a-zA-Z0-9+/=]+\.[a-f0-9]{64}$/;

        if (signatureRegex.test(cookieValue)) {
            return true;
        }

        console.warn('[AgeWallet Gate] Cookie present but invalid signature format.');
        return false;
    }

    /**
     * Creates and injects the overlay into the DOM (Standard Mode).
     * @param {string} gateHtml The HTML markup for the gate prompt.
     * @param {string} bodyClassActive CSS class to add to body when overlay is active.
     */
    function showOverlay(gateHtml, bodyClassActive) {
        if (!gateHtml) {
            console.error('[AgeWallet Gate] Gate HTML is missing. Cannot display overlay.');
            return;
        }

        // Create the overlay container div
        var overlay = document.createElement('div');
        overlay.id = 'agewallet-gate-overlay';
        overlay.className = 'aw-gate__overlay';
        overlay.style.zIndex = '999999';

        try {
             overlay.innerHTML = gateHtml;
        } catch(e) {
             console.error("Error setting overlay innerHTML", e);
             overlay.innerHTML = '<p style="color:red;">Error loading gate content.</p>';
        }

        document.body.appendChild(overlay);

        if (bodyClassActive && bodyClassActive.trim() !== '') {
            document.body.classList.add(bodyClassActive);
        }
    }

    /**
     * Handles showing/hiding protected content blocks based on verification status.
     */
    function handleProtectedBlocks(userIsVerified) {
        var wrappers = document.querySelectorAll('.agewallet-protected-wrapper');
        if (wrappers.length === 0) return;

        wrappers.forEach(function(wrapper) {
            var content = wrapper.querySelector('.agewallet-protected-content');
            var placeholder = wrapper.querySelector('.agewallet-protected-placeholder');

            if (!content || !placeholder) return;

            if (userIsVerified) {
                content.style.display = 'block';
                placeholder.style.display = 'none';
            } else {
                content.style.display = 'none';
                placeholder.style.display = 'block';
            }
        });
    }

    /**
     * STRICT MODE: Fetches secure content via API and injects it.
     */
    function fetchAndInjectContent(apiEndpoint) {
        if (!apiEndpoint) {
            console.error('[AgeWallet Strict] API Endpoint missing.');
            return;
        }

        var xhr = new XMLHttpRequest();
        // Uses POST to prevent server-side caching
        xhr.open('POST', apiEndpoint, true);
        xhr.withCredentials = true; // Send cookies with request

        xhr.onload = function() {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success && response.html) {
                        // The "Hard" Load: Replace the entire document with the fetched HTML.
                        document.open();
                        document.write(response.html);
                        document.close();
                    } else {
                        console.error('[AgeWallet Strict] API returned error or empty HTML.', response);
                        // Force Reload to clear invalid state if session expired
                        if (response.error === 'unverified') {
                             window.location.reload();
                        } else {
                             document.body.innerHTML = '<p>Error loading content: ' + (response.message || 'Unknown error') + '</p>';
                        }
                    }
                } catch (e) {
                    console.error('[AgeWallet Strict] JSON Parse Error', e);
                }
            } else {
                console.error('[AgeWallet Strict] HTTP Error', xhr.statusText);
            }
        };

        xhr.onerror = function() {
            console.error('[AgeWallet Strict] Network Error');
        };

        xhr.send();
    }


    /**
     * Main execution function. Runs after the DOM is ready.
     */
    function initGate() {

        // Check if the localized data object exists
        if (typeof agewallet_gate_data === 'undefined' || !agewallet_gate_data) {
            var pendingClass = 'agewallet-gated-pending';
            document.body.classList.remove(pendingClass);
            document.body.style.visibility = 'visible';
            // Continue to enable click handlers if possible
        }

        // Extract data passed from PHP
        var data = (typeof agewallet_gate_data !== 'undefined') ? agewallet_gate_data : {};
        var cookieName = data.cookieName || 'agewallet_verified';
        var gateHtml = data.gateHtml || '';
        var isOverlayActive = data.isOverlayActive || false;
        var bodyClassPending = data.bodyClassPending || 'agewallet-gated-pending';

        // Strict Mode Data
        var isStrictMode   = data.isStrictMode || false;
        var apiEndpoint    = data.apiEndpoint || '';
        var launchUrl      = data.launchUrl || '';
        var signedMetadata = data.signedMetadata || '';

        // Check verification status
        var userIsVerified = isVerified(cookieName);

        // --- 1. REGISTER CLICKS FIRST (Safety) ---
        // Delegated Click Handler for "I Agree" buttons (works for both Overlay and Skeleton)
        document.body.addEventListener('click', function(event) {
            var agreeButton = event.target.closest('.aw-gate__btn--yes');
            if (agreeButton) {
                event.preventDefault();
                var rUrl = agreeButton.getAttribute('data-redirect-url');
                if (rUrl) {
                    // Use a Form POST to navigate, preventing caching of the launch request
                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.action = rUrl;
                    form.style.display = 'none';
                    document.body.appendChild(form);
                    form.submit();
                } else {
                    console.error('[AgeWallet Gate] Agree button is missing redirect URL.');
                }
                return;
            }

            // "I Disagree" — reveal the inline error message. (Was an inline onclick handler;
            // moved here so the gate markup carries no inline JS.)
            var disagreeButton = event.target.closest('.aw-gate__btn--no');
            if (disagreeButton) {
                event.preventDefault();
                var card = disagreeButton.closest('.aw-gate');
                var errEl = card ? card.querySelector('.aw-gate__error') : null;
                if (errEl) {
                    errEl.style.display = 'block';
                }
            }
        });

        // --- 2. STRICT MODE LOGIC ---
        if (isStrictMode) {
            if (userIsVerified) {
                // Verified: Fetch content via API and Inject
                fetchAndInjectContent(apiEndpoint);
            } else {
                // Not Verified: Toggle from Spinner to Gate UI
                var spinner = document.getElementById('aw-gate-spinner');
                var gateUI = document.getElementById('aw-gate-ui');

                // Hide Spinner
                if (spinner) {
                    spinner.style.display = 'none';
                }

                // Show Gate
                if (gateUI) {
                    gateUI.style.display = 'block';

                    // Dynamic Redirect: Update the button to return to *this* page
                    var agreeBtn = gateUI.querySelector('.aw-gate__btn--yes');
                    if (agreeBtn && launchUrl) {
                        var currentUrl = window.location.href;
                        var separator = launchUrl.indexOf('?') !== -1 ? '&' : '?';
                        var finalUrl = launchUrl + separator + 'redirect_to=' + encodeURIComponent(currentUrl);
                        if (signedMetadata) {
                            finalUrl += '&agewallet_md=' + encodeURIComponent(signedMetadata);
                        }
                        agreeBtn.setAttribute('data-redirect-url', finalUrl);
                    }
                } else {
                    // Fallback if template is missing UI parts
                    console.error('[AgeWallet Strict] Critical: #aw-gate-ui container missing from template.');
                }
            }
            return; // Stop execution of Standard Mode logic
        }

        // --- 3. STANDARD MODE LOGIC (Overlay) ---
        if (isOverlayActive) {
            if (userIsVerified) {
                document.body.classList.remove(bodyClassPending);
                document.body.style.visibility = 'visible';
            } else {
                showOverlay(gateHtml);
                document.body.classList.remove(bodyClassPending);
                document.body.style.visibility = 'visible';
            }
        } else {
             document.body.classList.remove(bodyClassPending);
             document.body.style.visibility = 'visible';
        }

        // Handle Shortcode Content
        handleProtectedBlocks(userIsVerified);

        // Wrappers parsed after gate.js ran are handled once the page has finished parsing.
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                handleProtectedBlocks(userIsVerified);
            });
        }

        // Hide button wrappers if verified
        if (userIsVerified) {
            var buttonWrappers = document.querySelectorAll('.agewallet-shortcode-wrapper');
            if (buttonWrappers.length > 0) {
                 buttonWrappers.forEach(function(wrapper){
                     wrapper.style.display = 'none';
                 });
            }
        }
    }

    // --- Execution ---
    // Standard mode runs at once when the body exists (gate.js loads in the footer, after the page
    // content and every [agewallet_protected] wrapper). Strict mode, or no body yet, waits for
    // DOMContentLoaded.
    var gateData = (typeof agewallet_gate_data !== 'undefined' && agewallet_gate_data) ? agewallet_gate_data : {};
    if (document.readyState !== 'loading' || (document.body && !gateData.isStrictMode)) {
        initGate();
    } else {
        document.addEventListener('DOMContentLoaded', initGate);
    }

})(); // End IIFE