(function() {
    'use strict';
    
    // Get script attributes - find our script by looking for data-api-key
    var currentScript = document.currentScript;
    
    // Fallback if currentScript not supported
    if (!currentScript) {
        var scripts = document.querySelectorAll('script[data-api-key]');
        currentScript = scripts[scripts.length - 1];
    }
    
    // Final fallback
    if (!currentScript) {
        var allScripts = document.getElementsByTagName('script');
        currentScript = allScripts[allScripts.length - 1];
    }
    
    var apiKey = currentScript ? currentScript.getAttribute('data-api-key') : '';
    var siteId = currentScript ? currentScript.getAttribute('data-site-id') : '';
    
    // Clean up base URL - remove widget.js and fix any double slashes
    var scriptSrc = currentScript.src || '';
    console.log('Original script src:', scriptSrc);
    
    // Remove widget.js from the end
    var baseUrl = scriptSrc.replace(/\/widget\.js$/, '');
    
    // Fix double slashes (but preserve http:// and https://)
    baseUrl = baseUrl.replace(/([^:])\/\/+/g, '$1/');
    
    // Remove trailing slash
    baseUrl = baseUrl.replace(/\/$/, '');
    
    console.log('Computed baseUrl:', baseUrl);
    
    // Validate baseUrl
    if (!baseUrl || !baseUrl.match(/^https?:\/\//)) {
        console.error('Invalid baseUrl detected:', baseUrl);
        console.error('Script src was:', scriptSrc);
        
        // Try to construct from script src if it exists
        if (scriptSrc && scriptSrc.indexOf('widget.js') > -1) {
            var srcParts = scriptSrc.split('/');
            srcParts.pop(); // Remove widget.js
            baseUrl = srcParts.join('/');
            console.log('Reconstructed baseUrl:', baseUrl);
        } else {
            // Last resort: use current page location (might not work if widget is embedded elsewhere)
            baseUrl = window.location.protocol + '//' + window.location.host;
            console.warn('Using page location as baseUrl - this may not work correctly');
        }
    }
    
    // Create form HTML with inline arrow button
    var formHTML = `
        <style>
            #wharflist-widget {
                max-width: 400px;
                font-family: inherit;
                transition: opacity 0.3s ease;
            }
            #wharflist-widget.fade-out {
                opacity: 0;
            }
            #wharflist-widget h3 {
                margin-top: 0;
                color: #000;
                font-size: 18px;
            }
            #wharflist-email-wrapper {
                position: relative;
                display: flex;
                align-items: center;
            }
            #wharflist-email {
                width: 100%;
                padding: 14px 50px 14px 14px;
                border: 2px solid #000;
                border-radius: 5px;
                font-size: 14px;
                outline: none;
                background: #fff;
                color: #000;
                transition: border-color 0.3s ease;
            }
            .wharflist-hp {
                position: absolute;
                left: -9999px;
                width: 1px;
                height: 1px;
                opacity: 0;
                pointer-events: none;
            }
            #wharflist-email:focus {
                border-color: #000;
                box-shadow: 0 0 0 2px rgba(0, 0, 0, 0.1);
            }
            #wharflist-submit-btn {
                position: absolute;
                right: 4px;
                background: #000;
                color: #fff;
                border: none;
                border-radius: 5px;
                width: 40px;
                height: 40px;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: background 0.3s ease, transform 0.2s ease;
            }
            #wharflist-submit-btn:hover {
                background: #333;
                transform: scale(1.05);
            }
            #wharflist-submit-btn:active {
                transform: scale(0.95);
            }
            #wharflist-submit-btn:disabled {
                background: #666;
                cursor: not-allowed;
                transform: scale(1);
            }
            #wharflist-message {
                margin-top: 15px;
                padding: 12px;
                border-radius: 5px;
                text-align: center;
                font-size: 14px;
                opacity: 0;
                transform: translateY(-10px);
                transition: opacity 0.3s ease, transform 0.3s ease;
            }
            #wharflist-message.show {
                opacity: 1;
                transform: translateY(0);
            }
            #wharflist-message.success {
                background: #fff;
                color: #000;
                border: 2px solid #000;
            }
            #wharflist-message.error {
                background: #000;
                color: #fff;
                border: 2px solid #000;
            }
        </style>
        <div id="wharflist-widget">
            <form id="wharflist-subscribe-form">
                <input type="text" name="website" class="wharflist-hp" tabindex="-1" autocomplete="off">
                <input type="hidden" id="wharflist-timestamp" value="">
                <div id="wharflist-email-wrapper">
                    <input type="email" id="wharflist-email" placeholder="Enter your email" required>
                    <button type="submit" id="wharflist-submit-btn" aria-label="Subscribe">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                            <polyline points="12 5 19 12 12 19"></polyline>
                        </svg>
                    </button>
                </div>
                <div id="wharflist-message"></div>
            </form>
        </div>
    `;
    
    // Insert form into container
    var container = document.getElementById('wharflist-form');
    if (container) {
        container.innerHTML = formHTML;
    }
    
    // Handle form submission - wait for DOM to be ready or insert dynamically
    function initializeForm() {
        var form = document.getElementById('wharflist-subscribe-form');
        var messageDiv = document.getElementById('wharflist-message');
        
        // Set timestamp when form is ready (for timing check)
        var timestampField = document.getElementById('wharflist-timestamp');
        if (timestampField) {
            timestampField.value = Date.now();
        }
        
        if (form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                var email = document.getElementById('wharflist-email').value;
                var submitButton = document.getElementById('wharflist-submit-btn');
                var widget = document.getElementById('wharflist-widget');
                var emailWrapper = document.getElementById('wharflist-email-wrapper');
                
                // Anti-spam checks
                var honeypot = form.querySelector('.wharflist-hp');
                var timestamp = parseInt(timestampField.value);
                var timeDiff = Date.now() - timestamp;
                
                // Silent fail if honeypot is filled (bot detected)
                if (honeypot && honeypot.value !== '') {
                    console.log('Bot detected: honeypot filled');
                    return false;
                }
                
                // Silent fail if submitted too fast (< 2 seconds, likely bot)
                if (timeDiff < 2000) {
                    console.log('Bot detected: too fast');
                    return false;
                }
                
                // Disable button and show loading state
                submitButton.disabled = true;
                submitButton.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" opacity="0.25"/><path d="M12 2 A10 10 0 0 1 22 12" stroke-linecap="round"><animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="1s" repeatCount="indefinite"/></path></svg>';
                
                // Fade out the email input
                emailWrapper.style.opacity = '0.5';
                
                // Send request
                var apiUrl = baseUrl + '/api.php';
                console.log('Submitting to:', apiUrl);
                
                fetch(apiUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        api_key: apiKey,
                        email: email,
                        _t: Date.now(),
                        _hp: ''
                    })
                })
                .then(function(response) {
                    if (!response.ok) {
                        console.error('HTTP error:', response.status, response.statusText);
                        throw new Error('HTTP error ' + response.status);
                    }
                    return response.text();
                })
                .then(function(text) {
                    console.log('API Response:', text);
                    try {
                        var data = JSON.parse(text);
                        console.log('Parsed data:', data);
                        return data;
                    } catch (e) {
                        console.error('Invalid JSON response:', text);
                        console.error('Parse error:', e);
                        throw new Error('Invalid response from server');
                    }
                })
                .then(function(data) {
                    console.log('Success/Error:', data);
                    // Fade out the widget
                    widget.classList.add('fade-out');
                    
                    setTimeout(function() {
                        // Hide email wrapper and show message
                        emailWrapper.style.display = 'none';
                        messageDiv.className = data.success ? 'show success' : 'show error';
                        messageDiv.textContent = data.message;
                        
                        // Fade widget back in
                        widget.classList.remove('fade-out');
                        
                        // Reset form if successful
                        if (data.success) {
                            form.reset();
                            
                            // Show email input again after 5 seconds
                            setTimeout(function() {
                                widget.classList.add('fade-out');
                                setTimeout(function() {
                                    emailWrapper.style.display = 'flex';
                                    emailWrapper.style.opacity = '1';
                                    messageDiv.className = '';
                                    widget.classList.remove('fade-out');
                                    submitButton.disabled = false;
                                    submitButton.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>';
                                }, 300);
                            }, 5000);
                        } else {
                            // Show email input again after 3 seconds on error
                            setTimeout(function() {
                                widget.classList.add('fade-out');
                                setTimeout(function() {
                                    emailWrapper.style.display = 'flex';
                                    emailWrapper.style.opacity = '1';
                                    messageDiv.className = '';
                                    widget.classList.remove('fade-out');
                                    submitButton.disabled = false;
                                    submitButton.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>';
                                }, 300);
                            }, 3000);
                        }
                    }, 300);
                })
                .catch(function(error) {
                    console.error('Fetch error:', error);
                    console.error('Error message:', error.message);
                    
                    // Fade out the widget
                    widget.classList.add('fade-out');
                    
                    setTimeout(function() {
                        emailWrapper.style.display = 'none';
                        messageDiv.className = 'show error';
                        messageDiv.textContent = 'An error occurred. Please try again.';
                        widget.classList.remove('fade-out');
                        
                        // Show email input again after 3 seconds
                        setTimeout(function() {
                            widget.classList.add('fade-out');
                            setTimeout(function() {
                                emailWrapper.style.display = 'flex';
                                emailWrapper.style.opacity = '1';
                                messageDiv.className = '';
                                widget.classList.remove('fade-out');
                                submitButton.disabled = false;
                                submitButton.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>';
                            }, 300);
                        }, 3000);
                    }, 300);
                });
            });
        }
    }
    
    // Initialize immediately if DOM is ready, otherwise wait
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeForm);
    } else {
        initializeForm();
    }
})();
