// assets/js/countdown.js

(function($) {
    'use strict';

    class ContentExpiryCountdown {
        constructor(element) {
            this.element = element;
            this.expiryDate = new Date($(element).data('expiry'));
            this.countdownInterval = null;
            this.init();
        }

        init() {
            if (this.isValidDate(this.expiryDate)) {
                this.startCountdown();
            } else {
                console.error('Invalid expiry date provided');
            }
        }

        isValidDate(date) {
            return date instanceof Date && !isNaN(date);
        }

        startCountdown() {
            this.updateCountdown();
            this.countdownInterval = setInterval(() => this.updateCountdown(), 1000);
        }

        updateCountdown() {
            const now = new Date();
            const timeLeft = this.expiryDate - now;

            if (timeLeft <= 0) {
                this.handleExpired();
                return;
            }

            const days = Math.floor(timeLeft / (1000 * 60 * 60 * 24));
            const hours = Math.floor((timeLeft % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);

            this.updateDisplay(days, hours, minutes, seconds);
            this.updateUrgencyClass(days);
        }

        updateDisplay(days, hours, minutes, seconds) {
            const timeString = [
                days > 0 ? `${days} day${days !== 1 ? 's' : ''}` : '',
                hours > 0 ? `${hours} hour${hours !== 1 ? 's' : ''}` : '',
                minutes > 0 ? `${minutes} minute${minutes !== 1 ? 's' : ''}` : '',
                `${seconds} second${seconds !== 1 ? 's' : ''}`
            ].filter(Boolean).join(' ');

            $(this.element).html(`Content expires in: ${timeString}`);
        }

        updateUrgencyClass(days) {
            if (days <= 7) {
                $(this.element).addClass('urgent');
            }
        }

        handleExpired() {
            clearInterval(this.countdownInterval);
            $(this.element).html('Content has expired').addClass('expired');
            
            // Trigger custom event for other scripts to handle
            $(this.element).trigger('content:expired');
        }
    }

    // Initialize countdown for all countdown elements
    $('.expiry-countdown').each(function() {
        new ContentExpiryCountdown(this);
    });

    // Handle expired content
    $(document).on('content:expired', function(e) {
        const $container = $(e.target).closest('.post-content');
        
        // Optional: Show related posts or alternative content
        if ($container.length && typeof contentExpiryData !== 'undefined') {
            if (contentExpiryData.showRelatedPosts) {
                $.ajax({
                    url: contentExpiryData.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'get_related_posts',
                        post_id: contentExpiryData.postId,
                        nonce: contentExpiryData.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $container.append(response.data.html);
                        }
                    }
                });
            }
        }
    });
})(jQuery);