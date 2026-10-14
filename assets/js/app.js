/* ========================================== */
/* Session Timeout Handler                     */
/* ========================================== */

(function() {
    'use strict';
    
    var SESSION_TIMEOUT = 30 * 60 * 1000; // 30 minutes
    var timer;
    
    function resetTimer() {
        clearTimeout(timer);
        timer = setTimeout(function() {
            if (confirm('Your session is about to expire. Stay logged in?')) {
                fetch('refresh_session.php')
                    .then(resetTimer)
                    .catch(resetTimer);
            } else {
                window.location.href = 'logout.php?timeout=1';
            }
        }, SESSION_TIMEOUT);
    }
    
    ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(function(event) {
        document.addEventListener(event, resetTimer, true);
    });
    
    document.addEventListener('DOMContentLoaded', resetTimer);
})();