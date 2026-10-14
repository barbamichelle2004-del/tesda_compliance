/* ========================================== */
/* Seamless Navigation                         */
/* ========================================== */

(function() {
    'use strict';
    
    document.addEventListener('DOMContentLoaded', function() {
        var menuItems = document.querySelectorAll('.menu-item');
        var isNavigating = false;
        
        menuItems.forEach(function(item) {
            item.addEventListener('click', function(e) {
                if (isNavigating) {
                    e.preventDefault();
                    return;
                }
                
                var href = this.getAttribute('href');
                if (!href || href === '#') return;
                
                isNavigating = true;
                setTimeout(function() {
                    isNavigating = false;
                }, 1000);
            });
        });
    });
    
})();